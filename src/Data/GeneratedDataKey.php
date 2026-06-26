<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use SensitiveParameter;

/**
 * A freshly generated data-encryption key (DEK) in both forms.
 *
 * The plaintext lives only in memory for the duration of an operation
 * (model-data §8): it is never persisted. Only $wrapped — the DEK encrypted by
 * the tenant KEK inside the KMS — is stored. Implements FR-SEC-01.
 */
final class GeneratedDataKey
{
    public function __construct(
        #[SensitiveParameter] public readonly string $plaintext,
        public readonly string $wrapped,
        public readonly string $keyId,
    ) {}
}
