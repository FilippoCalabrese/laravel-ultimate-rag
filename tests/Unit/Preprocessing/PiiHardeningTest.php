<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Preprocessing\PiiRedactor;

beforeEach(fn () => $this->redactor = new PiiRedactor);

it('email redaction is linear-time on adversarial dotted input (C-M1 ReDoS)', function () {
    $evil = 'a@'.str_repeat('a.', 30000).'1'; // no valid TLD → would backtrack on the old pattern

    $start = hrtime(true);
    $this->redactor->redact($evil);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect($elapsedMs)->toBeLessThan(200); // was multi-second with the ambiguous host class
});

it('redacts internationalized (IDN) email addresses (C-M5)', function () {
    expect($this->redactor->redact('write to user@exämple.com please'))->toBe('write to [EMAIL] please')
        ->and($this->redactor->redact('mail user@пример.рф today'))->toContain('[EMAIL]');
});

it('does not over-redact dates, ISBNs or large amounts as phone numbers (C-M4)', function () {
    expect($this->redactor->redact('from 2021-01-01 to 2021-12-31'))->toBe('from 2021-01-01 to 2021-12-31')
        ->and($this->redactor->redact('ISBN 978-0-13-468599-1 book'))->toContain('978-0-13-468599-1')
        ->and($this->redactor->redact('value 100000000000 euros'))->toContain('100000000000');
});

it('still redacts genuine phone numbers (C-M4 no regression)', function () {
    expect($this->redactor->redact('call +39 02 1234 5678 now'))->toContain('[PHONE]');
});
