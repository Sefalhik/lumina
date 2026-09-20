<?php

declare(strict_types=1);

namespace App\Services\Smoke\Report;

use App\Enums\SmokeStatus;
use App\Services\Smoke\Site\SmokeTarget;
use DOMDocument;

/**
 * Renders smoke results for a human (text), a program (JSON) or a CI system (JUnit XML).
 *
 * Only the target's URL is ever rendered — never its credentials. In JUnit, a warning is not a
 * failure: CI would otherwise block on a certificate that still has ten good days, which is exactly
 * the noise that teaches people to ignore a red build.
 */
final class SmokeReport
{
    /**
     * @param  list<SmokeCheck>  $checks
     */
    public function __construct(private readonly SmokeTarget $target, private readonly array $checks) {}

    public function blocked(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->blocks()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{passed: int, blocking: int, warnings: int, skipped: int}
     */
    public function counts(): array
    {
        $counts = ['passed' => 0, 'blocking' => 0, 'warnings' => 0, 'skipped' => 0];
        foreach ($this->checks as $check) {
            match (true) {
                $check->blocks() => $counts['blocking']++,
                $check->warns() => $counts['warnings']++,
                $check->status === SmokeStatus::Skipped => $counts['skipped']++,
                default => $counts['passed']++,
            };
        }

        return $counts;
    }

    public function toText(): string
    {
        $lines = ["Smoke test — {$this->target->baseUrl}", ''];

        foreach ($this->checks as $check) {
            $symbol = match (true) {
                $check->blocks() => '✗',
                $check->warns() => '⚠',
                $check->status === SmokeStatus::Skipped => '–',
                default => '✔',
            };
            $lines[] = rtrim("  {$symbol} {$check->label}".($check->detail !== '' ? " — {$check->detail}" : ''));
            if ($check->remedy !== '') {
                $lines[] = "      → {$check->remedy}";
            }
        }

        $counts = $this->counts();
        $lines[] = '';
        $lines[] = sprintf(
            '%s — %d passed, %d blocking, %d warnings, %d skipped',
            $this->blocked() ? 'FAILED' : 'PASSED',
            $counts['passed'],
            $counts['blocking'],
            $counts['warnings'],
            $counts['skipped'],
        );

        return implode("\n", $lines);
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'target' => $this->target->baseUrl,
            'passed' => ! $this->blocked(),
            'counts' => $this->counts(),
            'checks' => array_map(fn (SmokeCheck $check): array => [
                'id' => $check->id,
                'label' => $check->label,
                'severity' => $check->severity->value,
                'status' => $check->status->value,
                'detail' => $check->detail,
                'remedy' => $check->remedy,
            ], $this->checks),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function toJunit(): string
    {
        $counts = $this->counts();
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $suite = $document->createElement('testsuite');
        $suite->setAttribute('name', 'smoke '.$this->target->baseUrl);
        $suite->setAttribute('tests', (string) count($this->checks));
        $suite->setAttribute('failures', (string) $counts['blocking']);
        $suite->setAttribute('skipped', (string) $counts['skipped']);
        $document->appendChild($suite);

        foreach ($this->checks as $check) {
            $case = $document->createElement('testcase');
            $case->setAttribute('classname', 'smoke');
            $case->setAttribute('name', "{$check->id}: {$check->label}");

            if ($check->blocks()) {
                $failure = $document->createElement('failure');
                $failure->setAttribute('message', $check->detail);
                $failure->appendChild($document->createTextNode($check->remedy));
                $case->appendChild($failure);
            } elseif ($check->status === SmokeStatus::Skipped) {
                $skipped = $document->createElement('skipped');
                $skipped->setAttribute('message', $check->detail);
                $case->appendChild($skipped);
            } elseif ($check->warns()) {
                $case->appendChild($document->createElement('system-out'))
                    ->appendChild($document->createTextNode("WARNING: {$check->detail} → {$check->remedy}"));
            }

            $suite->appendChild($case);
        }

        return (string) $document->saveXML();
    }
}
