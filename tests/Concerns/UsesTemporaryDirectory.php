<?php

declare(strict_types=1);

namespace Tests\Concerns;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\After;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A directory of the test's own, under the system's temporary directory, removed with everything
 * in it when the test ends — whatever the test left there, symbolic links included.
 *
 * Works in a Laravel test case and in a bare PHPUnit one alike: the removal is a PHPUnit `#[After]`
 * hook, not a Laravel callback.
 */
trait UsesTemporaryDirectory
{
    private ?string $temporaryDirectory = null;

    protected function temporaryDirectory(): string
    {
        if ($this->temporaryDirectory !== null) {
            return $this->temporaryDirectory;
        }

        $directory = sys_get_temp_dir().'/lumina_test_'.bin2hex(random_bytes(8));
        mkdir($directory, 0755, true);

        return $this->temporaryDirectory = $directory;
    }

    #[After]
    protected function removeTemporaryDirectory(): void
    {
        if ($this->temporaryDirectory === null || ! is_dir($this->temporaryDirectory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->temporaryDirectory);
        $this->temporaryDirectory = null;
    }
}
