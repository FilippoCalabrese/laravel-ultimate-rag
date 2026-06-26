<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Preprocessing\PiiRedactor;
use Sellinnate\RagEngine\Preprocessing\PreprocessingPipeline;
use Sellinnate\RagEngine\Preprocessing\TextCleaner;

it('runs stages in order and reports their names', function () {
    $pipeline = (new PreprocessingPipeline)
        ->pipe(new TextCleaner)
        ->pipe(new PiiRedactor);

    $doc = new ParsedDocument("contact    me@x.com   \n\n\n\nnow", 'text/plain');
    $result = $pipeline->process($doc);

    expect($result->text)->toBe("contact [EMAIL]\n\nnow")
        ->and($result->metadata['pii_redactions']['email'])->toBe(1)
        ->and($pipeline->stageNames())->toBe(['text-cleaner', 'pii-redactor']);
});

it('is a no-op with no stages', function () {
    $doc = new ParsedDocument('unchanged', 'text/plain');

    expect((new PreprocessingPipeline)->process($doc)->text)->toBe('unchanged');
});

it('accepts stages via the constructor', function () {
    $pipeline = new PreprocessingPipeline([new TextCleaner]);

    expect($pipeline->stageNames())->toBe(['text-cleaner']);
});
