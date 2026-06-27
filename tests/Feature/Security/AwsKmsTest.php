<?php

declare(strict_types=1);

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Aws\MockHandler;
use Aws\Result;
use Sellinnate\RagEngine\Data\GeneratedDataKey;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Security\Kms\AwsKms;

function awsKms(MockHandler $mock): AwsKms
{
    $client = new KmsClient([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $mock,
    ]);

    return new AwsKms($client, aliasPrefix: 'alias/rag-');
}

it('generates a data key and unwraps it (round trip)', function () {
    $mock = new MockHandler;
    $mock->append(new Result(['Plaintext' => 'PLAINTEXT-DEK-32', 'CiphertextBlob' => 'WRAPPED-BLOB']));
    $mock->append(new Result(['Plaintext' => 'PLAINTEXT-DEK-32']));

    $kms = awsKms($mock);

    $dek = $kms->generateDataKey('tenant-1');
    expect($dek)->toBeInstanceOf(GeneratedDataKey::class)
        ->and($dek->plaintext)->toBe('PLAINTEXT-DEK-32')
        ->and($dek->wrapped)->toBe(base64_encode('WRAPPED-BLOB'))
        ->and($dek->keyId)->toBe('tenant-1');

    expect($kms->unwrapDataKey('tenant-1', $dek->wrapped))->toBe('PLAINTEXT-DEK-32');

    // The decrypt call received the raw (base64-decoded) blob under the alias.
    $last = $mock->getLastCommand();
    expect($last->getName())->toBe('Decrypt')
        ->and($last['CiphertextBlob'])->toBe('WRAPPED-BLOB')
        ->and($last['KeyId'])->toBe('alias/rag-tenant-1');
});

it('wraps a plaintext DEK', function () {
    $mock = new MockHandler;
    $mock->append(new Result(['CiphertextBlob' => 'NEW-BLOB']));

    expect(awsKms($mock)->wrapDataKey('tenant-1', 'dek-bytes'))->toBe(base64_encode('NEW-BLOB'));
    expect($mock->getLastCommand()->getName())->toBe('Encrypt');
});

it('creates a per-tenant CMK with an alias and rotation when absent', function () {
    $mock = new MockHandler;
    $mock->append(new AwsException('not found', new Command('DescribeKey'))); // keyExists -> false
    $mock->append(new Result(['KeyMetadata' => ['KeyId' => 'cmk-123']]));     // createKey
    $mock->append(new Result([]));                                            // createAlias
    $mock->append(new Result([]));                                            // enableKeyRotation

    awsKms($mock)->createKey('tenant-1');

    expect($mock->getLastCommand()->getName())->toBe('EnableKeyRotation')
        ->and($mock->getLastCommand()['KeyId'])->toBe('cmk-123');
});

it('reports keyExists true/false from DescribeKey', function () {
    $present = new MockHandler;
    $present->append(new Result(['KeyMetadata' => ['KeyId' => 'cmk-1']]));
    expect(awsKms($present)->keyExists('tenant-1'))->toBeTrue();

    $absent = new MockHandler;
    $absent->append(new AwsException('not found', new Command('DescribeKey')));
    expect(awsKms($absent)->keyExists('tenant-1'))->toBeFalse();
});

it('crypto-shreds by disabling then scheduling key deletion', function () {
    $mock = new MockHandler;
    $mock->append(new Result([])); // disableKey
    $mock->append(new Result([])); // scheduleKeyDeletion

    awsKms($mock)->destroyKey('tenant-1');

    $last = $mock->getLastCommand();
    expect($last->getName())->toBe('ScheduleKeyDeletion')
        ->and($last['PendingWindowInDays'])->toBe(7)
        ->and($last['KeyId'])->toBe('alias/rag-tenant-1');
});

it('KmsManager resolves the aws driver from config', function () {
    config()->set('rag-engine.kms.aws', [
        'driver' => 'aws',
        'region' => 'eu-west-1',
        'key' => 'k',
        'secret' => 's',
    ]);

    expect(app(KmsManager::class)->driver('aws'))->toBeInstanceOf(AwsKms::class)
        ->and(app(KmsManager::class)->driver('aws')->name())->toBe('aws');
});
