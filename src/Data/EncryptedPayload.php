<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Envelope-encrypted content: the ciphertext plus the wrapped DEK needed to
 * decrypt it and the KEK reference (FR-SEC-01).
 *
 * Deleting the wrapped DEK (the only copy) crypto-shreds this payload at
 * document granularity (FR-SEC-04).
 *
 * @implements Arrayable<string, string>
 */
final class EncryptedPayload implements Arrayable
{
    public function __construct(
        public readonly string $ciphertext,
        public readonly string $wrappedDek,
        public readonly string $keyId,
    ) {}

    /**
     * @param  array<string, string>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['ciphertext'], $data['wrapped_dek'], $data['key_id']);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'ciphertext' => $this->ciphertext,
            'wrapped_dek' => $this->wrappedDek,
            'key_id' => $this->keyId,
        ];
    }
}
