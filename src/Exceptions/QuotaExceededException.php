<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Exceptions;

/**
 * Raised when a tenant exceeds a configured quota (FR-MT-04, NFR-CT-04).
 */
class QuotaExceededException extends RagException {}
