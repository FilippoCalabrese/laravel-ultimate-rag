<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Exceptions;

/**
 * Raised when an embeddable model declares a file field whose content cannot be
 * turned into text — a non-embeddable binary (zip, executable, image…), an
 * unreadable/missing file, or one over the size limit. Handled per the
 * `rag-engine.eloquent.on_unparsable_file` policy (skip | fail).
 */
class UnsupportedFileException extends RagException {}
