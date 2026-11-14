<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The complexity thresholds of phpstan.neon (LUMN-62), held where PHPStan reads them: the resolved
 * configuration, as PHPStan itself dumps it — no analysis run. The parameter exists only when the
 * extension is loaded, so a lost include fails here too.
 *
 * Not the rule itself: it is the extension's, tested by its maintainers. What is tested is that it
 * is plugged in, at the measured thresholds, with its one dated exception and no other.
 */
class ComplexityThresholdsTest extends TestCase
{
    /** @var array<string, mixed>|null PHPStan dumped once for the class: it takes a second to start. */
    private static ?array $parameters = null;

    /** @return array<string, mixed> */
    private function parameters(): array
    {
        if (self::$parameters !== null) {
            return self::$parameters;
        }

        // The local .env points PHP_INI_SCAN_DIR at FrankenPHP's configuration, without phar: PHPStan,
        // a phar, would not start. Unset for this one process, PHP falls back on its own default —
        // on a developer's machine and in CI alike (docs/quality-tooling.md, PHP_INI_SCAN_DIR).
        $result = Process::path(base_path())
            ->env(['PHP_INI_SCAN_DIR' => false])
            ->run(['vendor/bin/phpstan', 'dump-parameters', '-c', 'phpstan.neon', '--json']);
        $this->assertTrue($result->successful(), $result->errorOutput());

        /** @var array<string, mixed> $parameters */
        $parameters = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        return self::$parameters = $parameters;
    }

    public function test_the_thresholds_are_the_measured_ones(): void
    {
        $this->assertSame(['class' => 40, 'function' => 15], $this->parameters()['cognitive_complexity'] ?? null);
    }

    public function test_the_only_exception_is_i18n_translate_once_per_rule_until_lumn_110(): void
    {
        /** @var list<array{identifier?: string, path?: string, count?: int}> $ignored */
        $ignored = $this->parameters()['ignoreErrors'];

        $this->assertCount(2, $ignored);
        $this->assertEqualsCanonicalizing(
            ['complexity.functionLike', 'complexity.classLike'],
            array_column($ignored, 'identifier'),
        );
        foreach ($ignored as $entry) {
            $this->assertSame(base_path('app/Console/Commands/I18nTranslate.php'), $entry['path'] ?? null);
            $this->assertSame(1, $entry['count'] ?? null);
        }
    }
}
