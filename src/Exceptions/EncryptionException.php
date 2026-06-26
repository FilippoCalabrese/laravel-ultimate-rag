<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Exceptions;

/**
 * Raised on encryption/decryption failures, including attempts to unwrap a DEK
 * whose KEK has been crypto-shredded (FR-SEC-04).
 */
class EncryptionException extends RagException {}
