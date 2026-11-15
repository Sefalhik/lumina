<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * What a translation run is expected to send and to cost — an order of magnitude shown before the
 * run is confirmed, not a bill. Estimates of several requests add up with {@see plus()}.
 */
final readonly class TranslationEstimate
{
    public function __construct(
        public int $requests = 0,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $cost = 0.0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->requests + $other->requests,
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cost + $other->cost,
        );
    }

    /**
     * The line both translation commands show before asking.
     */
    public function describe(string $model): string
    {
        return sprintf(
            '%d API requests to %s, about %d input and %d output tokens, about $%.2f.',
            $this->requests,
            $model,
            $this->inputTokens,
            $this->outputTokens,
            $this->cost,
        );
    }
}
