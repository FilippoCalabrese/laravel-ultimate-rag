<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Preprocessing;

use Sellinnate\RagEngine\Contracts\PreprocessingStage;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Lightweight stopword-frequency language detector (FR-PA-11) prioritising
 * IT/DE/EN (NFR-IN-02). Zero dependencies; good enough to tag documents and
 * chunks. As a preprocessing stage it sets the document language when unknown.
 */
final class LanguageDetector implements PreprocessingStage
{
    /** @var array<string, list<string>> */
    private const STOPWORDS = [
        'it' => ['il', 'lo', 'la', 'che', 'di', 'e', 'un', 'una', 'per', 'con', 'non', 'sono', 'gli', 'nel', 'alla', 'come', 'più', 'anche'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'ein', 'eine', 'mit', 'auf', 'für', 'den', 'von', 'zu', 'auch', 'werden', 'sind'],
        'en' => ['the', 'and', 'is', 'of', 'to', 'in', 'that', 'it', 'for', 'with', 'as', 'are', 'this', 'be', 'on', 'not', 'or'],
    ];

    public function detect(string $text): ?string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return null;
        }

        $counts = array_count_values($words);
        $scores = [];

        foreach (self::STOPWORDS as $lang => $stopwords) {
            $scores[$lang] = array_sum(array_map(static fn (string $w): int => $counts[$w] ?? 0, $stopwords));
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? (string) $best : null;
    }

    public function process(ParsedDocument $document): ParsedDocument
    {
        if ($document->language !== null) {
            return $document;
        }

        return $document->withLanguage($this->detect($document->text));
    }

    public function name(): string
    {
        return 'language-detector';
    }
}
