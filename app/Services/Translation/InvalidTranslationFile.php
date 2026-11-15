<?php

declare(strict_types=1);

namespace App\Services\Translation;

use RuntimeException;

/**
 * A translation file exists but cannot be read as one: a PHP file that returns no array, a JSON
 * file that does not decode to an object, a registry that is not JSON.
 *
 * Thrown rather than read as empty: an unreadable target taken for an empty one would be
 * "translated" from scratch and overwritten, losing every value it held.
 */
final class InvalidTranslationFile extends RuntimeException
{
    public static function at(string $path, string $reason): self
    {
        return new self("{$path}: {$reason}");
    }
}
