<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Exceptions;

/**
 * Raised when a source cannot be parsed, or when parsing input is rejected for
 * security reasons (XXE, zip-bomb, path traversal — FR-SEC-08).
 */
class ParsingException extends RagException {}
