#!/usr/bin/env php
<?php

/**
 * Runs the Unit test suite with PCOV coverage and enforces a minimum line
 * coverage threshold. Exits with a non-zero code if the threshold is not met,
 * which blocks the pre-commit hook.
 *
 * Usage: php -d pcov.enabled=1 scripts/check-coverage.php [--min=80]
 */
$min = 80.0;
foreach ($argv as $arg) {
    if (preg_match('/^--min=(\d+(?:\.\d+)?)$/', $arg, $m)) {
        $min = (float) $m[1];
    }
}

$cloverFile = sys_get_temp_dir().'/phpunit-coverage-'.getmypid().'.xml';

passthru(
    'php -d pcov.enabled=1 vendor/bin/phpunit --testsuite=Unit,Feature --coverage-text --coverage-clover '.escapeshellarg($cloverFile),
    $exitCode,
);

if ($exitCode !== 0) {
    exit($exitCode);
}

if (! file_exists($cloverFile)) {
    fwrite(STDERR, "Coverage report not generated — is PCOV enabled?\n");
    exit(1);
}

$xml = simplexml_load_file($cloverFile);
unlink($cloverFile);

if ($xml === false) {
    fwrite(STDERR, "Failed to parse coverage report.\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "No metrics found in coverage report.\n");
    exit(1);
}

$coveredLines = (int) $metrics['coveredstatements'];
$totalLines = (int) $metrics['statements'];

if ($totalLines === 0) {
    fwrite(STDERR, "No coverable lines found — check your phpunit.xml <source> configuration.\n");
    exit(1);
}

$percent = round(($coveredLines / $totalLines) * 100, 2);

if ($percent < $min) {
    fwrite(STDERR, sprintf(
        "\n✗  Line coverage: %.2f%% — below the %.0f%% minimum threshold.\n",
        $percent,
        $min,
    ));
    exit(1);
}

echo sprintf("\n✓  Line coverage: %.2f%% (≥ %.0f%%)\n", $percent, $min);
exit(0);
