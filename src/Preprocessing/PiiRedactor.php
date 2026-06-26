<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Preprocessing;

use Sellinnate\RagEngine\Contracts\PreprocessingStage;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * PII detection + redaction (FR-PP-03, NFR-CO-04). ON by default.
 *
 * Detects e-mail, credit cards (Luhn-validated), IBAN, Italian codice fiscale
 * and P.IVA, and phone numbers. Two strategies:
 *  - "mask": replace with a type label, e.g. [EMAIL]. Destructive.
 *  - "tokenize": replace with a stable token, e.g. [EMAIL:ab12cd], and record a
 *    token→original map in metadata so the consumer can reverse it.
 *
 * Detectors run most-specific first so broad phone matching never eats the
 * digits of an IBAN/card/CF.
 */
final class PiiRedactor implements PreprocessingStage
{
    public const STRATEGY_MASK = 'mask';

    public const STRATEGY_TOKENIZE = 'tokenize';

    public function __construct(
        private readonly string $strategy = self::STRATEGY_MASK,
    ) {}

    public function process(ParsedDocument $document): ParsedDocument
    {
        $tokens = [];
        $counts = [];

        // Redact the flat text, every section (content + nested metadata) and
        // the document metadata tree — PII hides in CSV rows, JSON values, PDF
        // page sections, not just the text (C1).
        $text = $this->redact($document->text, $tokens, $counts);

        $sections = array_map(
            fn (DocumentSection $section): DocumentSection => new DocumentSection(
                type: $section->type,
                content: $this->redact($section->content, $tokens, $counts),
                level: $section->level,
                page: $section->page,
                metadata: $this->redactTree($section->metadata, $tokens, $counts),
            ),
            $document->sections,
        );

        /** @var array<string, mixed> $metadata */
        $metadata = $this->redactTree($document->metadata, $tokens, $counts);
        $metadata['pii_redactions'] = $counts;

        if ($this->strategy === self::STRATEGY_TOKENIZE && $tokens !== []) {
            $metadata['pii_tokens'] = $tokens;
        }

        return $document
            ->withText($text)
            ->withSections($sections)
            ->replaceMetadata($metadata);
    }

    /**
     * Recursively redact every string scalar in a nested array.
     *
     * @param  array<array-key, mixed>  $tree
     * @param  array<string, string>  $tokens
     * @param  array<string, int>  $counts
     * @return array<array-key, mixed>
     */
    private function redactTree(array $tree, array &$tokens, array &$counts): array
    {
        foreach ($tree as $key => $value) {
            if (is_string($value)) {
                $tree[$key] = $this->redact($value, $tokens, $counts);
            } elseif (is_array($value)) {
                $tree[$key] = $this->redactTree($value, $tokens, $counts);
            }
        }

        return $tree;
    }

    /**
     * @param  array<string, string>  $tokens  Filled with token→original (tokenize mode).
     * @param  array<string, int>  $counts  Filled with type→occurrences.
     */
    public function redact(string $text, array &$tokens = [], array &$counts = []): string
    {
        foreach ($this->detectors() as $type => $detector) {
            $text = preg_replace_callback(
                $detector['pattern'],
                function (array $m) use ($type, $detector, &$tokens, &$counts): string {
                    $value = $m[0];
                    $validator = $detector['validator'] ?? null;

                    if ($validator !== null && ! $validator($value)) {
                        // A greedy match may have swallowed a trailing token (e.g.
                        // a spaced IBAN eating the next word). Trim trailing
                        // separated tokens and re-validate the longest valid head.
                        [$head, $tail] = $this->longestValidPrefix($value, $validator);

                        if ($head === null) {
                            return $value; // genuinely not PII (e.g. fails Luhn)
                        }

                        $counts[$type] = ($counts[$type] ?? 0) + 1;

                        return $this->replacement($type, $head, $tokens).$tail;
                    }

                    $counts[$type] = ($counts[$type] ?? 0) + 1;

                    return $this->replacement($type, $value, $tokens);
                },
                $text,
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Trim trailing separator-delimited tokens until the head validates.
     *
     * @param  callable(string): bool  $validator
     * @return array{0: string|null, 1: string} [validHead|null, trimmedTail]
     */
    private function longestValidPrefix(string $value, callable $validator): array
    {
        $candidate = $value;

        while (preg_match('/[\s.\-]\S*$/u', $candidate) === 1) {
            $candidate = (string) preg_replace('/[\s.\-]+\S*$/u', '', $candidate);

            if ($candidate === '') {
                break;
            }

            if ($validator($candidate)) {
                return [$candidate, substr($value, strlen($candidate))];
            }
        }

        return [null, ''];
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function replacement(string $type, string $value, array &$tokens): string
    {
        $label = strtoupper($type);

        if ($this->strategy === self::STRATEGY_TOKENIZE) {
            $token = '['.$label.':'.substr(hash('sha256', $type.':'.$value), 0, 6).']';
            $tokens[$token] = $value;

            return $token;
        }

        return '['.$label.']';
    }

    /**
     * @return array<string, array{pattern: string, validator?: callable(string): bool}>
     */
    private function detectors(): array
    {
        return [
            'email' => [
                // All quantifiers bounded (RFC-ish limits) so no start position can
                // trigger an O(n) scan → linear time, no ReDoS; \p{L} supports IDN.
                'pattern' => '/[\p{L}\p{N}._%+\-]{1,64}@(?:[\p{L}\p{N}\-]{1,63}\.){1,12}[\p{L}]{2,24}/u',
            ],
            'iban' => [
                // Allow the grouped human format ("DE89 3704 ...") and lowercase.
                'pattern' => '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]){11,30}\b/i',
                'validator' => $this->ibanValidator(),
            ],
            'credit_card' => [
                // Separators: space, hyphen, dot, NBSP and thin/narrow spaces.
                'pattern' => '/\b\d(?:[ \-.\x{00A0}\x{2009}\x{202F}]?\d){12,18}\b/u',
                'validator' => $this->luhnValidator(),
            ],
            'codice_fiscale' => [
                'pattern' => '/\b[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]\b/i',
            ],
            'vat' => [
                'pattern' => '/\b(?:IT)?\d{11}\b/',
            ],
            'phone' => [
                // Lookarounds exclude '-' too, so the matcher won't grab a phone-
                // shaped *substring* from inside a hyphenated sequence (ISBN etc.).
                'pattern' => '/(?<![\w.\-])\+?\d{1,3}[ .\-]?\(?\d{2,4}\)?(?:[ .\-]?\d{2,4}){2,4}(?![\w.\-])/',
                'validator' => function (string $v): bool {
                    $digits = strlen(preg_replace('/\D/', '', $v) ?? '');

                    // E.164 numbers are 8–15 digits.
                    if ($digits < 8 || $digits > 15) {
                        return false;
                    }

                    // Don't destroy dates (YYYY-MM-DD) or ISBN-13 numbers.
                    if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', trim($v)) === 1) {
                        return false;
                    }
                    if (preg_match('/^97[89][\d\- ]+$/', trim($v)) === 1) {
                        return false;
                    }

                    // Require phone-like structure (a leading + or separators) for
                    // long runs, so bare 12+-digit IDs/amounts aren't over-redacted.
                    $hasStructure = str_contains($v, '+') || preg_match('/[ .\-()]/', $v) === 1;

                    return $hasStructure || $digits <= 11;
                },
            ],
        ];
    }

    /**
     * @return callable(string): bool
     */
    private function luhnValidator(): callable
    {
        return function (string $value): bool {
            $digits = preg_replace('/\D/', '', $value) ?? '';

            if (strlen($digits) < 13 || strlen($digits) > 19) {
                return false;
            }

            $sum = 0;
            $alt = false;

            for ($i = strlen($digits) - 1; $i >= 0; $i--) {
                $n = (int) $digits[$i];

                if ($alt) {
                    $n *= 2;
                    if ($n > 9) {
                        $n -= 9;
                    }
                }

                $sum += $n;
                $alt = ! $alt;
            }

            return $sum % 10 === 0;
        };
    }

    /**
     * @return callable(string): bool
     */
    private function ibanValidator(): callable
    {
        return function (string $value): bool {
            $iban = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

            if (strlen($iban) < 15 || strlen($iban) > 34) {
                return false;
            }

            // Move the first four chars to the end, convert letters to numbers,
            // then check mod 97 == 1.
            $rearranged = substr($iban, 4).substr($iban, 0, 4);
            $numeric = '';

            foreach (str_split($rearranged) as $char) {
                $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
            }

            return $this->bcmod97($numeric) === 1;
        };
    }

    private function bcmod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }

    public function name(): string
    {
        return 'pii-redactor';
    }
}
