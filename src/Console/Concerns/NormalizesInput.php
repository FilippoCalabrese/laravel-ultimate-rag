<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Safe accessors for console input. Laravel's `argument()`/`option()` return
 * `mixed` (a value can be a string, an array for array options, or null), so
 * casting them straight to `string` is unsound. These helpers coerce explicitly.
 *
 * @phpstan-require-extends Command
 */
trait NormalizesInput
{
    protected function stringArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    /**
     * A string option, or null when absent/blank/non-string.
     */
    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
