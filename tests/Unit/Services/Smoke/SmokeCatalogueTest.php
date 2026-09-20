<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke;

use App\Services\Smoke\SmokeCatalogue;
use App\Services\Smoke\SmokeProbe;
use PHPUnit\Framework\TestCase;

/**
 * A probe class that is not in the catalogue never runs — and it would still have its own passing
 * tests beside it, so nothing else would say so. That silence is exactly what this suite exists to
 * end, and one class per probe is what makes this guard possible at all.
 */
class SmokeCatalogueTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function probeClasses(): array
    {
        $files = glob(dirname(__DIR__, 4).'/app/Services/Smoke/Probes/*.php') ?: [];

        return array_map(
            fn (string $path): string => 'App\\Services\\Smoke\\Probes\\'.basename($path, '.php'),
            $files,
        );
    }

    public function test_every_probe_class_is_listed_in_the_catalogue(): void
    {
        $missing = array_diff($this->probeClasses(), SmokeCatalogue::PROBES);

        $this->assertSame(
            [],
            array_values($missing),
            'These probes exist and would never run — add them to SmokeCatalogue::PROBES.',
        );
    }

    public function test_the_catalogue_lists_no_missing_class(): void
    {
        $this->assertSame([], array_values(array_diff(SmokeCatalogue::PROBES, $this->probeClasses())));
    }

    public function test_every_listed_probe_implements_the_interface_and_has_a_unique_id(): void
    {
        $ids = [];
        foreach (SmokeCatalogue::PROBES as $class) {
            $probe = new $class;
            $this->assertInstanceOf(SmokeProbe::class, $probe);
            $this->assertNotSame('', $probe->label(), "{$class} has no label.");
            $ids[] = $probe->id();
        }

        $this->assertSame(array_unique($ids), $ids, 'Two probes share an id: one would overwrite the other in reports.');
    }
}
