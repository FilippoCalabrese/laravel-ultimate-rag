<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Preprocessing\LanguageDetector;

beforeEach(fn () => $this->detector = new LanguageDetector);

it('detects English', function () {
    expect($this->detector->detect('the quick brown fox is in the house and it is not a dog'))->toBe('en');
});

it('detects Italian', function () {
    expect($this->detector->detect('il cane e la gatta sono nel giardino con una palla per il gioco'))->toBe('it');
});

it('detects German', function () {
    expect($this->detector->detect('der Hund und die Katze sind nicht in dem Haus mit ein Auto für den Garten'))->toBe('de');
});

it('returns null when no stopwords match', function () {
    expect($this->detector->detect('xyz qwerty zzz'))->toBeNull()
        ->and($this->detector->detect(''))->toBeNull();
});

it('sets language on a document only when unknown', function () {
    $unknown = new ParsedDocument('the cat is on the mat and it is here', 'text/plain');
    $known = new ParsedDocument('the cat', 'text/plain', language: 'fr');

    expect($this->detector->process($unknown)->language)->toBe('en')
        ->and($this->detector->process($known)->language)->toBe('fr')
        ->and($this->detector->name())->toBe('language-detector');
});
