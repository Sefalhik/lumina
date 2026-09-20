<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * X-Release tells the smoke test which release answered (LUMN-49). Without it, a failed switch to a
 * new release would leave the old one serving — and every other probe would test the old one, and
 * pass.
 */
class ReleaseHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_names_the_release(): void
    {
        config(['app.release' => '4f2a9c1e']);

        $this->get('/fr')->assertOk()->assertHeader('X-Release', '4f2a9c1e');
        $this->get('/up')->assertOk()->assertHeader('X-Release', '4f2a9c1e');
        $this->get('/fr/lumina-smoke-missing-page')->assertNotFound()->assertHeader('X-Release', '4f2a9c1e');
    }

    public function test_no_release_means_no_header_never_an_empty_one(): void
    {
        config(['app.release' => null]);

        $this->get('/fr')->assertOk()->assertHeaderMissing('X-Release');
    }

    public function test_an_empty_release_sets_no_header(): void
    {
        // An empty RELEASE file — a deployment script interrupted between creating and writing it —
        // must read as "unknown", never as a release named "". The smoke test would otherwise
        // compare the expected SHA against an empty string and report a mismatch instead of the
        // missing header, sending the reader after the wrong defect.
        config(['app.release' => '']);

        $this->get('/fr')->assertOk()->assertHeaderMissing('X-Release');
    }

    public function test_the_repository_carries_no_release_file(): void
    {
        // RELEASE is written by the deployment script; one committed by mistake would make every
        // environment claim the same release, and the smoke test's first check would lie.
        $this->assertFileDoesNotExist(base_path('RELEASE'));
        $this->assertNull(config('app.release'));
    }
}
