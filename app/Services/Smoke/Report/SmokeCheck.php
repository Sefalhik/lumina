<?php

declare(strict_types=1);

namespace App\Services\Smoke\Report;

use App\Enums\SmokeSeverity;
use App\Enums\SmokeStatus;
use InvalidArgumentException;

/**
 * The result of one smoke check: what was probed, how much it matters, what happened, and — when it
 * failed — what to do about it. A failure without a remedy only tells the reader something is
 * wrong; the remedy is what makes the report actionable at two in the morning.
 *
 * **The constructor is private so that the three shapes below are the only ones that exist.** It was
 * public until 2026-09-20, which made a passing check carrying a remedy, or a failure carrying none,
 * merely a convention nobody had broken yet — and this class states that rule in its own docblock.
 * A named constructor each makes it true by construction instead.
 */
final readonly class SmokeCheck
{
    private function __construct(
        public string $id,
        public string $label,
        public SmokeSeverity $severity,
        public SmokeStatus $status,
        public string $detail = '',
        public string $remedy = '',
    ) {}

    public static function pass(string $id, string $label, string $detail = ''): self
    {
        return new self($id, $label, SmokeSeverity::Blocking, SmokeStatus::Passed, $detail);
    }

    /**
     * @param  string  $remedy  what to do about it — required, and refused empty
     *
     * @throws InvalidArgumentException when the failure says nothing about what to do
     */
    public static function fail(
        string $id,
        string $label,
        string $detail,
        string $remedy,
        SmokeSeverity $severity = SmokeSeverity::Blocking,
    ): self {
        if (trim($remedy) === '') {
            throw new InvalidArgumentException("The {$id} check failed without saying what to do about it.");
        }

        return new self($id, $label, $severity, SmokeStatus::Failed, $detail, $remedy);
    }

    public static function skip(string $id, string $label, string $reason): self
    {
        return new self($id, $label, SmokeSeverity::Blocking, SmokeStatus::Skipped, $reason);
    }

    public function passed(): bool
    {
        return $this->status === SmokeStatus::Passed;
    }

    /**
     * Whether this result must stop the deployment from being promoted.
     */
    public function blocks(): bool
    {
        return $this->status === SmokeStatus::Failed && $this->severity === SmokeSeverity::Blocking;
    }

    public function warns(): bool
    {
        return $this->status === SmokeStatus::Failed && $this->severity === SmokeSeverity::Warning;
    }
}
