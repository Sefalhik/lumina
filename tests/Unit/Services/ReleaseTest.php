<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Release;
use PHPUnit\Framework\TestCase;

class ReleaseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/lumina-release-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function test_no_file_means_no_release(): void
    {
        $this->assertNull(Release::fromFile($this->file));
    }

    public function test_the_sha_is_read_and_trimmed(): void
    {
        // The deployment script writes it with a trailing newline, as `git rev-parse HEAD > RELEASE` does.
        file_put_contents($this->file, "4f2a9c1e\n");

        $this->assertSame('4f2a9c1e', Release::fromFile($this->file));
    }

    public function test_an_empty_file_means_no_release_never_an_empty_string(): void
    {
        file_put_contents($this->file, "  \n");

        $this->assertNull(Release::fromFile($this->file));
    }
}
