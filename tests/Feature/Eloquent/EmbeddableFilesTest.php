<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Exceptions\UnsupportedFileException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Parsing\ParserManager;

beforeEach(function () {
    Schema::dropIfExists('file_docs');
    Schema::create('file_docs', function ($t) {
        $t->increments('id');
        $t->string('title');
        $t->string('path')->nullable();
        $t->string('disk')->nullable();
        $t->string('mime')->nullable();
    });

    config()->set('rag-engine.eloquent.auto_sync', false);
    config()->set('rag-engine.eloquent.queue', false);

    $this->pdf = __DIR__.'/../../fixtures/sample.pdf';
});

it('embeds a PDF file field and associates it with the model (FR-DX-05)', function () {
    $pdfText = app(ParserManager::class)->parse(file_get_contents($this->pdf), 'application/pdf')->text;

    $doc = FileDoc::create(['title' => 'Quarterly Report', 'path' => $this->pdf]);
    $document = $doc->syncEmbedding();

    expect($document)->toBeInstanceOf(Document::class)
        ->and($document->status)->toBe('indexed')
        ->and($document->metadata['embeddable_type'])->toBe(FileDoc::class)
        ->and($document->metadata['embeddable_id'])->toBe((string) $doc->id);

    // The model's stored content contains BOTH the title and the parsed PDF text.
    $content = Rag::ingestor()->content($document);
    expect($content)->toContain('Quarterly Report')
        ->and($content)->toContain(trim($pdfText));

    // It is searchable, and a hit resolves back to the originating model.
    $hits = Rag::search('quarterly report')->topK(5)->get();
    expect($hits)->not->toBeEmpty();
    expect(app(ModelEmbedder::class)->resolve($hits[0]))->toBeInstanceOf(FileDoc::class);
});

it('embeds a PDF stored on a Laravel filesystem disk', function () {
    Storage::fake('docs');
    Storage::disk('docs')->put('report.pdf', file_get_contents($this->pdf));

    $pdfText = app(ParserManager::class)->parse(file_get_contents($this->pdf), 'application/pdf')->text;

    $doc = FileDoc::create(['title' => 'On Disk', 'path' => 'report.pdf', 'disk' => 'docs']);
    $content = Rag::ingestor()->content($doc->syncEmbedding());

    expect($content)->toContain('On Disk')->and($content)->toContain(trim($pdfText));
});

it('skips a non-embeddable binary (zip) by default and still indexes the rest', function () {
    config()->set('rag-engine.eloquent.on_unparsable_file', 'skip');

    $zip = tempnam(sys_get_temp_dir(), 'rag').'.zip';
    file_put_contents($zip, "PK\x03\x04 not really a zip but unsupported");

    $doc = FileDoc::create(['title' => 'Release Archive', 'path' => $zip, 'mime' => 'application/zip']);
    $document = $doc->syncEmbedding();

    $content = Rag::ingestor()->content($document);
    expect($document->status)->toBe('indexed')
        ->and($content)->toContain('Release Archive')          // text field kept
        ->and($content)->not->toContain('not really a zip')    // binary not embedded
        ->and(Document::query()->where('metadata->document_key', $doc->embeddableKey())->count())->toBe(1);

    unlink($zip);
});

it('throws UnsupportedFileException for a binary when policy is fail', function () {
    config()->set('rag-engine.eloquent.on_unparsable_file', 'fail');

    $exe = tempnam(sys_get_temp_dir(), 'rag').'.bin';
    file_put_contents($exe, "\x7fELF\x02\x01\x01 binary executable");

    $doc = FileDoc::create(['title' => 'Installer', 'path' => $exe, 'mime' => 'application/octet-stream']);

    try {
        $doc->syncEmbedding();
        unlink($exe);
        expect()->fail('expected UnsupportedFileException');
    } catch (UnsupportedFileException $e) {
        unlink($exe);
        expect($e->getMessage())->toContain('not embeddable');
    }
});

it('skips a missing file and indexes the text fields', function () {
    config()->set('rag-engine.eloquent.on_unparsable_file', 'skip');

    $doc = FileDoc::create(['title' => 'Has No File', 'path' => '/nope/does-not-exist.pdf']);
    $document = $doc->syncEmbedding();

    expect($document->status)->toBe('indexed')
        ->and(Rag::ingestor()->content($document))->toContain('Has No File');
});

it('skips a file over the size limit', function () {
    config()->set('rag-engine.eloquent.on_unparsable_file', 'skip');
    config()->set('rag-engine.eloquent.max_file_bytes', 10); // PDF is larger than 10 bytes

    $doc = FileDoc::create(['title' => 'Too Big', 'path' => $this->pdf]);
    $content = Rag::ingestor()->content($doc->syncEmbedding());

    $pdfText = app(ParserManager::class)->parse(file_get_contents($this->pdf), 'application/pdf')->text;
    expect($content)->toContain('Too Big')
        ->and($content)->not->toContain(trim($pdfText));
});

class FileDoc extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'file_docs';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            ->addFile('Document', $this->path, $this->disk, $this->mime);
    }
}
