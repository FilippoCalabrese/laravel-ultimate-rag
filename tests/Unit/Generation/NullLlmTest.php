<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Generation\NullLlm;

it('returns empty output and an empty stream (FR-GE-05)', function () {
    $llm = new NullLlm;

    expect($llm->generate('prompt'))->toBe('')
        ->and(iterator_to_array((function () use ($llm) {
            yield from $llm->stream('prompt');
        })()))->toBe([])
        ->and($llm->model())->toBe('null');
});
