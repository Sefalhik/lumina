<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\TranslationEstimate;
use PHPUnit\Framework\TestCase;

class TranslationEstimateTest extends TestCase
{
    public function test_an_estimate_of_nothing_is_zero_everywhere(): void
    {
        $none = new TranslationEstimate;

        $this->assertSame([0, 0, 0, 0.0], [$none->requests, $none->inputTokens, $none->outputTokens, $none->cost]);
    }

    public function test_estimates_add_up_term_by_term(): void
    {
        $sum = (new TranslationEstimate(1, 100, 50, 0.25))->plus(new TranslationEstimate(2, 300, 70, 0.5));

        $this->assertSame([3, 400, 120, 0.75], [$sum->requests, $sum->inputTokens, $sum->outputTokens, $sum->cost]);
    }

    public function test_the_line_shown_names_the_model_and_rounds_the_cost_to_the_cent(): void
    {
        $this->assertSame(
            '4 API requests to claude-opus-5-5, about 1450 input and 32 output tokens, about $0.15.',
            (new TranslationEstimate(4, 1450, 32, 0.146))->describe('claude-opus-5-5'),
        );
        $this->assertSame(
            '0 API requests to m, about 0 input and 0 output tokens, about $0.00.',
            (new TranslationEstimate)->describe('m'),
        );
    }
}
