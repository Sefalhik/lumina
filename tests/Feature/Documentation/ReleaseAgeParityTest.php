<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

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
 * @see docs/dependency-updates.md
 */
class ReleaseAgeParityTest extends TestCase
{
    public function test_renovate_and_npm_wait_the_same_number_of_days(): void
    {
        $this->assertSame(
            $this->daysInNpmrc(),
            $this->daysInRenovate(),
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
        $matched = preg_match("/^\s*npmrc:\s*'([^']*)'/m", $this->read('renovate.json5'), $override);

        $this->assertSame(
            1,
            $matched,
            "renovate.json5 no longer overrides the repository .npmrc.\n".
            '  → Without an `npmrc` option, Renovate applies min-release-age to security fixes.',
        );
        $this->assertStringNotContainsString('min-release-age', $override[1]);
    }

    private function daysInNpmrc(): int
    {
        $matched = preg_match('/^min-release-age=(\d+)$/m', $this->read('.npmrc'), $days);

        $this->assertSame(1, $matched, '.npmrc declares no min-release-age.');

        return (int) $days[1];
    }

    private function daysInRenovate(): int
    {
        // Every declaration, not the first: a second rule with another delay — 14 days the day
        // automerge is enabled — makes "the" release age ambiguous, and this test has to be
        // rewritten on purpose rather than keep passing on the rule it happens to find.
        preg_match_all("/^\s*minimumReleaseAge:\s*'(\d+) days'/m", $this->read('renovate.json5'), $declarations);

        $this->assertCount(
            1,
            $declarations[1],
            'renovate.json5 is expected to declare minimumReleaseAge exactly once, in days.',
        );

        return (int) $declarations[1][0];
    }

    private function read(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }
}
