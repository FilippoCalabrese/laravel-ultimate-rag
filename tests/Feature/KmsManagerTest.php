<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

it('builds a file-backed local kms when configured with store=file', function () {
    $dir = sys_get_temp_dir().'/rag-kms-mgr-'.bin2hex(random_bytes(6));
    config()->set('rag-engine.kms.local-file', [
        'driver' => 'local',
        'store' => 'file',
        'keystore' => $dir,
    ]);

    $kms = app(KmsManager::class)->driver('local-file');
    $dataKey = $kms->generateDataKey('t1');

    expect($kms)->toBeInstanceOf(LocalKms::class)
        ->and($kms->unwrapDataKey('t1', $dataKey->wrapped))->toBe($dataKey->plaintext)
        ->and(is_dir($dir))->toBeTrue();

    array_map('unlink', glob($dir.'/*') ?: []);
    @rmdir($dir);
});
