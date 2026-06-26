<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;

/**
 * Registry of format parsers (FR-PA-13). New formats are isolated additions —
 * register a {@see Parser} and it participates without touching the pipeline.
 * Resolution is by MIME type / extension; the first registered parser that
 * supports the type wins.
 */
final class ParserManager
{
    /** @var list<Parser> */
    private array $parsers = [];

    /**
     * @param  list<Parser>  $parsers
     */
    public function __construct(array $parsers = [])
    {
        foreach ($parsers as $parser) {
            $this->register($parser);
        }
    }

    public function register(Parser $parser): self
    {
        // Most-recently registered takes precedence so consumers can override.
        array_unshift($this->parsers, $parser);

        return $this;
    }

    public function parserFor(string $mimeType): ?Parser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($mimeType)) {
                return $parser;
            }
        }

        return null;
    }

    public function supports(string $mimeType): bool
    {
        return $this->parserFor($mimeType) instanceof Parser;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $parser = $this->parserFor($mimeType);

        if (! $parser instanceof Parser) {
            throw new ParsingException("No parser registered for MIME type [{$mimeType}].");
        }

        return $parser->parse($contents, $mimeType, $context);
    }

    /**
     * @return list<Parser>
     */
    public function all(): array
    {
        return $this->parsers;
    }
}
