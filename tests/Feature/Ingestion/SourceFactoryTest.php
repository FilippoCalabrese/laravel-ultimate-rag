<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Ingestion\SourceFactory;
use Sellinnate\RagEngine\Models\Document;

beforeEach(fn () => $this->factory = app(SourceFactory::class));

it('builds a text source (FR-IN-02)', function () {
    $source = $this->factory->text('hello world', ['tag' => 'x']);

    expect($source->sourceType)->toBe(IngestionSource::TYPE_TEXT)
        ->and($source->content)->toBe('hello world')
        ->and($source->mimeType)->toBe('text/plain')
        ->and($source->metadata['tag'])->toBe('x');
});

it('builds a file source and guesses mime by extension (FR-IN-01)', function () {
    $path = sys_get_temp_dir().'/rag-src-'.bin2hex(random_bytes(4)).'.md';
    file_put_contents($path, '# Title');

    $source = $this->factory->file($path);
    @unlink($path);

    expect($source->sourceType)->toBe(IngestionSource::TYPE_UPLOAD)
        ->and($source->mimeType)->toBe('text/markdown')
        ->and($source->metadata['filename'])->toEndWith('.md');
});

it('throws when a file is missing', function () {
    $this->factory->file('/no/such/file.txt');
})->throws(RagException::class, 'File not found');

it('builds a storage source from a fake disk (FR-IN-05)', function () {
    Storage::fake('docs');
    Storage::disk('docs')->put('reports/q1.csv', "a,b\n1,2");

    $source = $this->factory->storage('docs', 'reports/q1.csv');

    expect($source->sourceType)->toBe(IngestionSource::TYPE_STORAGE)
        ->and($source->mimeType)->toBe('text/csv')
        ->and($source->content)->toContain('1,2')
        ->and($source->metadata['key'])->toBe('reports/q1.csv');
});

it('throws when a storage object is missing', function () {
    Storage::fake('docs');

    $this->factory->storage('docs', 'missing.txt');
})->throws(RagException::class, 'not found');

it('fetches a URL source from a public host (FR-IN-03)', function () {
    Http::fake(['https://93.184.216.34/page' => Http::response('<html><body>Hi</body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

    $source = $this->factory->url('https://93.184.216.34/page');

    expect($source->sourceType)->toBe(IngestionSource::TYPE_URL)
        ->and($source->mimeType)->toBe('text/html')
        ->and($source->content)->toContain('Hi')
        ->and($source->metadata['url'])->toBe('https://93.184.216.34/page');
});

it('throws on a failed URL fetch', function () {
    Http::fake(['*' => Http::response('nope', 404)]);

    $this->factory->url('https://93.184.216.34/missing');
})->throws(RagException::class, 'Failed to fetch');

it('blocks SSRF to internal hosts before fetching (H3)', function () {
    Http::fake(['*' => Http::response('should never be reached', 200)]);

    expect(fn () => $this->factory->url('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(RagException::class, 'SSRF');

    Http::assertNothingSent();
});

it('builds an eloquent source from fields (FR-IN-04)', function () {
    $model = new Document(['source_type' => 'article', 'status' => 'published']);

    $source = $this->factory->eloquent($model, ['source_type', 'status']);

    expect($source->sourceType)->toBe(IngestionSource::TYPE_ELOQUENT)
        ->and($source->content)->toContain('article')->toContain('published')
        ->and($source->metadata['model'])->toBe(Document::class);
});

it('json-encodes non-scalar eloquent fields', function () {
    $model = new Document(['metadata' => ['a' => 1, 'b' => 2]]);

    $source = $this->factory->eloquent($model, ['metadata']);

    expect($source->content)->toContain('"a":1');
});

it('sniffs mime when the extension is unknown', function () {
    $path = sys_get_temp_dir().'/rag-src-'.bin2hex(random_bytes(4)).'.unknownext';
    file_put_contents($path, 'just some plain text content here');

    $source = $this->factory->file($path);
    @unlink($path);

    expect($source->mimeType)->toBe('text/plain');
});

it('builds an eloquent source from a toRagContent() method', function () {
    $model = new class extends Model
    {
        public function toRagContent(): string
        {
            return 'custom rag content';
        }

        public function getKey(): string
        {
            return 'k1';
        }
    };

    $source = $this->factory->eloquent($model);

    expect($source->content)->toBe('custom rag content');
});
