<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The npm release age is written in three files, and nothing but this test makes them agree.
 *
 * `renovate.json5` decides what Renovate proposes, `.npmrc` decides what an install typed by hand
 * resolves, and `docs/dependency-updates.md` tells a reader which number is in force. Renovate
 * cannot read the delay from the package manager's configuration — its own documentation says so —
 * so the number cannot live in one place.
 *
 * A number repeated by hand drifts without a sound: change one file and the diff looks right.
 * It already happened once. The document announced 14 days from LUMN-21 until LUMN-64 while the
 * preset applied 3, and the gap was found by reading a job log, three weeks later.
 *
 * The real `renovate.json5` only ever shows the readers one form of each setting. What they must
 * accept and refuse beyond it is pinned on fixed strings below (LUMN-149): a reformatting by
 * Renovate's own config migration once read as a removed override.
 *
 * @see docs/dependency-updates.md
 */
class ReleaseAgeParityTest extends TestCase
{
    public function test_renovate_and_npm_wait_the_same_number_of_days(): void
    {
        $this->assertSame(
            $this->daysInNpmrc(),
            $this->daysInRenovate($this->read('renovate.json5')),
            "The npm release age differs between .npmrc and renovate.json5.\n".
            "  → One of them was changed alone. Decide which number is meant, write it in both,\n".
            '    and in docs/dependency-updates.md.',
        );
    }

    public function test_the_document_quotes_both_settings_with_the_number_in_force(): void
    {
        $days = $this->daysInNpmrc();
        $documentation = $this->read('docs/dependency-updates.md');

        foreach (["`min-release-age={$days}`", "`minimumReleaseAge: '{$days} days'`"] as $setting) {
            $this->assertStringContainsString(
                $setting,
                $documentation,
                "docs/dependency-updates.md does not quote {$setting}.\n".
                '  → Its "Release age" section has to show both settings as they are configured.',
            );
        }
    }

    public function test_renovate_does_not_inherit_the_delay_from_npmrc(): void
    {
        // Renovate exempts security fixes from the release age; npm, reading .npmrc, would not.
        // Dropping this override brings back a fix that fails to lock without an error anywhere.
        $this->assertOverridesNpmrc($this->read('renovate.json5'));
    }

    #[DataProvider('releaseAgesInEitherQuote')]
    public function test_a_release_age_is_read_in_either_quote(string $config): void
    {
        $this->assertSame(3, $this->daysInRenovate($config));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function releaseAgesInEitherQuote(): array
    {
        return [
            'apostrophes' => ["        minimumReleaseAge: '3 days',\n"],
            'double quotes, as Renovate rewrites it' => ["        minimumReleaseAge: \"3 days\",\n"],
        ];
    }

    #[DataProvider('releaseAgesThatAreNotOneDeclarationInDays')]
    public function test_a_release_age_that_is_not_one_declaration_in_days_is_refused(string $config): void
    {
        // No message to match: daysInRenovate() asserts one thing, so there is no wrong reason to
        // fail for — the mechanical pass showed such a check could never turn red (LUMN-149).
        $this->expectException(AssertionFailedError::class);

        $this->daysInRenovate($config);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function releaseAgesThatAreNotOneDeclarationInDays(): array
    {
        return [
            'absent' => ["        groupName: 'npm',\n"],
            'commented out, so not in force' => ["        // minimumReleaseAge: '3 days',\n"],
            'quotes that do not match' => ["        minimumReleaseAge: '3 days\",\n"],
            'another unit' => ["        minimumReleaseAge: '72 hours',\n"],
            'declared twice, the same number' => [
                "        minimumReleaseAge: '3 days',\n        minimumReleaseAge: '3 days',\n",
            ],
            'declared twice, in either quote' => [
                "        minimumReleaseAge: '3 days',\n        minimumReleaseAge: \"14 days\",\n",
            ],
        ];
    }

    #[DataProvider('overridesInEitherQuote')]
    public function test_an_override_is_recognised_in_either_quote(string $config): void
    {
        $this->assertOverridesNpmrc($config);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overridesInEitherQuote(): array
    {
        return [
            'apostrophes' => ["    npmrc: '# ignored on purpose',\n"],
            'double quotes, as Renovate rewrites it' => ["    npmrc: \"# ignored on purpose\",\n"],
        ];
    }

    #[DataProvider('overridesThatAreMissingDoubledOrInheriting')]
    public function test_an_override_that_is_missing_doubled_or_inheriting_is_refused(string $config, string $reason): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains($reason);

        $this->assertOverridesNpmrc($config);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overridesThatAreMissingDoubledOrInheriting(): array
    {
        $once = 'override the repository .npmrc exactly once';
        $inherits = 'carries min-release-age';

        return [
            'absent' => ["    timezone: 'Europe/Paris',\n", $once],
            'commented out, so not in force' => ["    // npmrc: '# ignored on purpose',\n", $once],
            'quotes that do not match' => ["    npmrc: '# ignored on purpose\",\n", $once],
            // JSON5 keeps the last of two equal keys: a second override below the first one is the
            // one in force, and reading only the first would check the wrong value.
            'declared twice' => ["    npmrc: '# ignored',\n    npmrc: 'min-release-age=3',\n", $once],
            'the delay itself' => ["    npmrc: 'min-release-age=3',\n", $inherits],
            'the delay among other settings' => ["    npmrc: \"registry=https://example.test\\nmin-release-age=3\",\n", $inherits],
            // A reader that stops at the first apostrophe sees `it\` and lets the delay through.
            'the delay after an escaped quote' => ["    npmrc: 'it\\'s min-release-age=3',\n", $inherits],
        ];
    }

    private function assertOverridesNpmrc(string $config): void
    {
        // Either quote — JSON5 accepts both — and an escaped quote does not end the value.
        preg_match_all('/^\s*npmrc:\s*([\'"])((?:\\\\.|(?!\1).)*)\1/m', $config, $overrides);

        $this->assertCount(
            1,
            $overrides[2],
            "renovate.json5 is expected to override the repository .npmrc exactly once.\n".
            '  → Without an `npmrc` option, Renovate applies min-release-age to security fixes.',
        );
        $this->assertStringNotContainsString(
            'min-release-age',
            $overrides[2][0],
            'The npmrc override in renovate.json5 carries min-release-age, which it exists to drop.',
        );
    }

    private function daysInNpmrc(): int
    {
        $matched = preg_match('/^min-release-age=(\d+)$/m', $this->read('.npmrc'), $days);

        $this->assertSame(1, $matched, '.npmrc declares no min-release-age.');

        return (int) $days[1];
    }

    private function daysInRenovate(string $config): int
    {
        // Every declaration, not the first: a second rule with another delay — 14 days the day
        // automerge is enabled — makes "the" release age ambiguous, and this test has to be
        // rewritten on purpose rather than keep passing on the rule it happens to find.
        // Either quote, for the same reason as the npmrc override.
        preg_match_all('/^\s*minimumReleaseAge:\s*([\'"])(\d+) days\1/m', $config, $declarations);

        $this->assertCount(
            1,
            $declarations[2],
            'renovate.json5 is expected to declare minimumReleaseAge exactly once, in days.',
        );

        return (int) $declarations[2][0];
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
