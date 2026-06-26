<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Parsing\JsonParser;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Parsing\PlainTextParser;

it('resolves a parser by mime type', function () {
    $manager = new ParserManager([new PlainTextParser, new JsonParser]);

    expect($manager->parserFor('application/json'))->toBeInstanceOf(JsonParser::class)
        ->and($manager->parserFor('text/plain'))->toBeInstanceOf(PlainTextParser::class)
        ->and($manager->supports('application/json'))->toBeTrue()
        ->and($manager->supports('application/pdf'))->toBeFalse();
});

it('parses through the resolved parser', function () {
    $manager = new ParserManager([new PlainTextParser]);

    expect($manager->parse('hi', 'text/plain')->text)->toBe('hi');
});

it('throws when no parser supports the mime type', function () {
    (new ParserManager)->parse('x', 'application/octet-stream');
})->throws(ParsingException::class, 'No parser registered');

it('lets a later registration override an earlier one (FR-PA-13)', function () {
    $custom = new class implements Parser
    {
        public function supports(string $mimeType): bool
        {
            return $mimeType === 'text/plain';
        }

        public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
        {
            return new ParsedDocument('CUSTOM', 'text/plain');
        }

        public function mimeTypes(): array
        {
            return ['text/plain'];
        }
    };

    $manager = new ParserManager([new PlainTextParser]);
    $manager->register($custom);

    expect($manager->parse('original', 'text/plain')->text)->toBe('CUSTOM')
        ->and($manager->all())->toHaveCount(2);
});
