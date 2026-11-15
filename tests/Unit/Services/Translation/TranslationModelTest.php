<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\TranslationModel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TranslationModelTest extends TestCase
{
    private const REFUSAL = 'A translation model needs a model, an effort among low, medium, high, xhigh, max, a positive max_tokens, a price [input, output] of no less than zero and an output_ratio above zero.';

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        return ['model' => 'claude-opus-5-5', 'effort' => 'medium', 'max_tokens' => 16000, 'price' => [4, 20.5], 'output_ratio' => 4];
    }

    public function test_a_complete_configuration_is_read_whole(): void
    {
        $model = TranslationModel::fromConfig(self::config());

        $this->assertSame(
            ['claude-opus-5-5', 'medium', 16000, 4.0, 20.5, 4.0],
            [$model->model, $model->effort, $model->maxTokens, $model->inputPrice, $model->outputPrice, $model->outputRatio],
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function atTheLimits(): iterable
    {
        foreach (TranslationModel::EFFORTS as $effort) {
            yield "effort {$effort}" => [['effort' => $effort] + self::config()];
        }
        yield 'a free model' => [['price' => [0, 0]] + self::config()];
        yield 'one token' => [['max_tokens' => 1] + self::config()];
        yield 'a tiny output ratio' => [['output_ratio' => 0.01] + self::config()];
        yield 'prices and ratio written as text' => [['price' => ['0.10', '0.50'], 'output_ratio' => '1.6'] + self::config()];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('atTheLimits')]
    public function test_a_configuration_at_the_limits_is_accepted(array $config): void
    {
        $model = TranslationModel::fromConfig($config);

        $this->assertSame($config['effort'], $model->effort);
        $this->assertSame($config['max_tokens'], $model->maxTokens);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refused(): iterable
    {
        foreach (['model', 'effort', 'max_tokens', 'price', 'output_ratio'] as $key) {
            $config = self::config();
            unset($config[$key]);
            yield "no {$key}" => [$config];
        }
        yield 'a model that is no string' => [['model' => 5] + self::config()];
        yield 'an empty model' => [['model' => ''] + self::config()];
        yield 'an effort the API does not know' => [['effort' => 'extreme'] + self::config()];
        yield 'an effort in capitals' => [['effort' => 'Medium'] + self::config()];
        yield 'an effort that is no string' => [['effort' => ['low']] + self::config()];
        yield 'an effort that is true, which a loose comparison would take for any level' => [['effort' => true] + self::config()];
        yield 'max_tokens that is no integer' => [['max_tokens' => '16000'] + self::config()];
        yield 'no token at all' => [['max_tokens' => 0] + self::config()];
        yield 'an output ratio that is no number' => [['output_ratio' => 'four'] + self::config()];
        yield 'an output ratio of nothing' => [['output_ratio' => 0] + self::config()];
        yield 'a negative output ratio' => [['output_ratio' => -1] + self::config()];
        yield 'a price that is no list' => [['price' => 4] + self::config()];
        yield 'a price without an output price' => [['price' => [4]] + self::config()];
        yield 'a price with three figures' => [['price' => [4, 20, 1]] + self::config()];
        yield 'an input price that is no number' => [['price' => ['four', 20]] + self::config()];
        yield 'an output price that is no number' => [['price' => [4, 'twenty']] + self::config()];
        yield 'a negative input price' => [['price' => [-0.01, 20]] + self::config()];
        yield 'a negative output price' => [['price' => [4, -0.01]] + self::config()];
        yield 'a price keyed by name' => [['price' => ['input' => 4, 'output' => 20]] + self::config()];
        yield 'a price whose output alone is keyed by name' => [['price' => [4, 'output' => 20]] + self::config()];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('refused')]
    public function test_a_configuration_that_would_fail_or_misprice_is_refused(array $config): void
    {
        try {
            TranslationModel::fromConfig($config);
            $this->fail('A configuration that would fail or misprice was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(self::REFUSAL, $e->getMessage());
        }
    }

    public function test_the_cost_is_priced_per_million_tokens_input_and_output_apart(): void
    {
        $model = new TranslationModel('m', 'low', 16000, 4.0, 20.0, 1.0);

        $this->assertEqualsWithDelta(0.004 + 0.04, $model->cost(1000, 2000), 1e-12);
        $this->assertSame(0.0, $model->cost(0, 0));
    }
}
