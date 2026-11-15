<?php

declare(strict_types=1);

namespace App\Services\Translation;

use InvalidArgumentException;

/**
 * The model one {@see TranslationContext} is translated with, as `config/i18n.php` sets it
 * (`translation.{context}`): which model, how hard it thinks, how much room it has, and what it
 * costs — the last two only for the estimate shown before a run.
 */
final readonly class TranslationModel
{
    public function __construct(
        public string $model,
        public string $effort,
        public int $maxTokens,
        public float $inputPrice,
        public float $outputPrice,
        public float $outputRatio,
    ) {}

    /** The levels of `output_config.effort` the API knows. */
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * Refuses a configuration that would only fail at the first request — an unknown effort is a
     * 400 from the API — or that would price a run wrong: no model, a negative price, an output
     * ratio of nothing.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $model = $config['model'] ?? null;
        $effort = $config['effort'] ?? null;
        $maxTokens = $config['max_tokens'] ?? null;
        $ratio = $config['output_ratio'] ?? null;
        $price = $config['price'] ?? null;
        $input = is_array($price) && count($price) === 2 ? ($price[0] ?? null) : null;
        $output = is_array($price) && count($price) === 2 ? ($price[1] ?? null) : null;

        if (! is_string($model) || $model === '' || ! in_array($effort, self::EFFORTS, true)
            || ! is_int($maxTokens) || $maxTokens < 1 || ! is_numeric($ratio) || $ratio <= 0
            || ! is_numeric($input) || $input < 0 || ! is_numeric($output) || $output < 0) {
            throw new InvalidArgumentException(
                'A translation model needs a model, an effort among '.implode(', ', self::EFFORTS)
                .', a positive max_tokens, a price [input, output] of no less than zero and an output_ratio above zero.'
            );
        }

        return new self($model, $effort, $maxTokens, (float) $input, (float) $output, (float) $ratio);
    }

    /**
     * In US dollars, from the prices per million tokens.
     */
    public function cost(int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens * $this->inputPrice + $outputTokens * $this->outputPrice) / 1_000_000;
    }
}
