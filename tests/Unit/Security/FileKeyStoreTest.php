<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\Kms\FileKeyStore;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/rag-kms-'.bin2hex(random_bytes(6));
    $this->store = new FileKeyStore($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('persists, reads and forgets KEK material on disk', function () {
    expect($this->store->has('k'))->toBeFalse()
        ->and($this->store->get('k'))->toBeNull();

    $material = random_bytes(48);
    $this->store->put('k', $material);

    expect($this->store->has('k'))->toBeTrue()
        ->and($this->store->get('k'))->toBe($material);

    $this->store->forget('k');
    expect($this->store->has('k'))->toBeFalse();
});

it('backs a LocalKms that crypto-shreds by deleting the file', function () {
    $kms = new LocalKms($this->store, new AeadCipher);
    $dataKey = $kms->generateDataKey('tenant-file');

    expect($kms->unwrapDataKey('tenant-file', $dataKey->wrapped))->toBe($dataKey->plaintext);

    $kms->destroyKey('tenant-file');

    expect($this->store->has('tenant-file'))->toBeFalse();
});
