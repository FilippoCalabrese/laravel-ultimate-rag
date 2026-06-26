<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Preprocessing\PiiRedactor;

it('masks e-mail addresses', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('Write to john.doe@example.com please'))
        ->toBe('Write to [EMAIL] please');
});

it('masks Luhn-valid credit cards but leaves invalid digit runs', function () {
    $redactor = new PiiRedactor;

    // 4111 1111 1111 1111 is a valid Visa test number (passes Luhn).
    expect($redactor->redact('card 4111 1111 1111 1111 ok'))->toBe('card [CREDIT_CARD] ok');
    // A 16-digit run that fails Luhn is never labelled a credit card (it may
    // still be caught as a phone-shaped number — safe-side over-redaction).
    expect($redactor->redact('ref 1234 5678 9012 3456 end'))->not->toContain('[CREDIT_CARD]');
});

it('masks a valid IBAN and skips an invalid one', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('IBAN DE89370400440532013000 here'))->toBe('IBAN [IBAN] here');
    expect($redactor->redact('IBAN DE00000000000000000000 here'))->toContain('DE00000000000000000000');
});

it('masks Italian codice fiscale', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('CF RSSMRA85T10A562S done'))->toBe('CF [CODICE_FISCALE] done');
});

it('masks phone numbers', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('call +39 02 1234 5678 now'))->toContain('[PHONE]');
});

it('counts redactions per type', function () {
    $redactor = new PiiRedactor;
    $tokens = [];
    $counts = [];

    $redactor->redact('a@b.com and c@d.com', $tokens, $counts);

    expect($counts['email'])->toBe(2);
});

it('tokenizes reversibly with a stable token and a map', function () {
    $redactor = new PiiRedactor(PiiRedactor::STRATEGY_TOKENIZE);
    $tokens = [];
    $counts = [];

    $out = $redactor->redact('mail john@example.com twice john@example.com', $tokens, $counts);

    // Same value → same token (stable), recorded in the reversible map.
    preg_match_all('/\[EMAIL:[a-f0-9]{6}\]/', $out, $matches);
    expect($matches[0])->toHaveCount(2)
        ->and($matches[0][0])->toBe($matches[0][1])
        ->and($tokens[$matches[0][0]])->toBe('john@example.com');
});

it('records redactions in document metadata via the stage interface', function () {
    $doc = new ParsedDocument('contact me@x.com', 'text/plain');

    $result = (new PiiRedactor)->process($doc);

    expect($result->text)->toBe('contact [EMAIL]')
        ->and($result->metadata['pii_redactions']['email'])->toBe(1);
});

it('leaves clean text untouched', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('nothing sensitive here'))->toBe('nothing sensitive here');
});

it('masks a spaced, lowercase IBAN (H1)', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('IBAN DE89 3704 0044 0532 0130 00 here'))->toBe('IBAN [IBAN] here')
        ->and($redactor->redact('iban de89 3704 0044 0532 0130 00'))->toContain('[IBAN]');
});

it('masks credit cards with dot and NBSP separators (H2)', function () {
    $redactor = new PiiRedactor;

    expect($redactor->redact('card 4111.1111.1111.1111 ok'))->toBe('card [CREDIT_CARD] ok')
        ->and($redactor->redact("card 4111\u{00A0}1111\u{00A0}1111\u{00A0}1111 ok"))->toContain('[CREDIT_CARD]');
});

it('redacts PII inside section content and section metadata (C1)', function () {
    $doc = new ParsedDocument(
        text: 'clean text',
        mimeType: 'text/csv',
        sections: [new DocumentSection(
            type: 'table',
            content: 'email: mario@example.com',
            metadata: ['rows' => [['email' => 'mario@example.com', 'iban' => 'DE89370400440532013000']]],
        )],
    );

    $result = (new PiiRedactor)->process($doc);

    expect($result->sections[0]->content)->toBe('email: [EMAIL]')
        ->and($result->sections[0]->metadata['rows'][0]['email'])->toBe('[EMAIL]')
        ->and($result->sections[0]->metadata['rows'][0]['iban'])->toBe('[IBAN]');
});

it('redacts PII inside document metadata tree (C1)', function () {
    $doc = new ParsedDocument(
        text: 'clean',
        mimeType: 'application/json',
        metadata: ['json' => ['user' => ['email' => 'leak@example.com']]],
    );

    $result = (new PiiRedactor)->process($doc);

    expect($result->metadata['json']['user']['email'])->toBe('[EMAIL]')
        ->and($result->metadata['pii_redactions']['email'])->toBe(1);
});
