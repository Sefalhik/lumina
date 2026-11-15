<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Translation\TextFormat;
use App\Services\Translation\TranslationContext;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationModel;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AnthropicTranslator
{
    /**
     * Characters of source JSON sent in one request: 6 000 characters of French are ~2 000 tokens,
     * and a translation into Greek or Bulgarian takes about twice the tokens of the French.
     */
    public const CHUNK_CHARACTERS = 6000;

    /**
     * The least `max_tokens` a model is given: the longest answer a chunk can take (~4 000 tokens,
     * see above), and room for the model to think before it — thinking counts toward `max_tokens`,
     * and a cap sized for the answer alone cuts it off (LUMN-33).
     */
    public const MIN_MAX_TOKENS = 12000;

    /**
     * @param  array<string, TranslationModel>  $models  keyed by {@see TranslationContext} value
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly TranslationPrompt $prompt,
        private readonly array $models,
    ) {
        foreach (TranslationContext::cases() as $context) {
            $model = $models[$context->value] ?? throw new InvalidArgumentException(
                "No translation model for the {$context->value} context in config/i18n.php (translation)."
            );
            if ($model->maxTokens < self::MIN_MAX_TOKENS) {
                throw new InvalidArgumentException(
                    "The {$context->value} translation model needs max_tokens of at least ".self::MIN_MAX_TOKENS.'.'
                );
            }
        }
    }

    public function model(TranslationContext $context): string
    {
        return $this->models[$context->value]->model;
    }

    /**
     * Translate flat key-value pairs from French to the given locale, in as many requests as
     * {@see chunks()} makes of them.
     *
     * Returns every requested key, or null — never part of them: a request that fails, an answer
     * cut short, an answer missing a key, or a translation that broke its format fails the whole
     * call (details are logged), and the caller leaves the keys to translate. An empty French value
     * is answered empty, and never sent: a model asked to translate nothing answers nothing, which
     * would count as a missing key, run after run.
     *
     * @param  array<array-key, string>  $flatSource
     * @return array<array-key, string>|null
     */
    public function translate(array $flatSource, string $locale, TranslationContext $context, TextFormat $format): ?array
    {
        $translated = array_filter($flatSource, fn (string $value) => $value === '');

        foreach ($this->chunks(array_diff_key($flatSource, $translated)) as $chunk) {
            $answer = $this->request($chunk, $locale, $context, $format);
            if ($answer === null) {
                return null;
            }
            $translated += $answer;
        }

        // In the order of the source, which every caller writes back in.
        return array_replace(array_intersect_key($flatSource, $translated), $translated);
    }

    /**
     * Splits a source into requests whose answer fits in `max_tokens`, keeping key order. A key
     * larger than the budget on its own still gets a request of its own.
     *
     * @param  array<array-key, string>  $flatSource
     * @return list<array<array-key, string>>
     */
    public function chunks(array $flatSource): array
    {
        $chunks = [];
        $current = [];

        foreach ($flatSource as $key => $value) {
            if ($current !== [] && $this->size($current + [$key => $value]) > self::CHUNK_CHARACTERS) {
                $chunks[] = $current;
                $current = [];
            }
            $current[$key] = $value;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * An order of magnitude, shown before a run is confirmed — not a bill. A token is counted as
     * three characters; the output, thinking included, as the model's measured multiple of the
     * French sent (`output_ratio`); the cost at the model's price.
     *
     * @param  array<array-key, string>  $flatSource
     */
    public function estimate(array $flatSource, string $locale, TranslationContext $context, TextFormat $format): TranslationEstimate
    {
        $model = $this->models[$context->value];
        $sent = array_filter($flatSource, fn (string $value) => $value !== '');
        $requests = count($this->chunks($sent));
        $sourceTokens = $sent === [] ? 0 : $this->tokens($this->size($sent));
        $systemTokens = $this->tokens(strlen($this->prompt->system($context, $format, $locale)));

        $input = $requests * $systemTokens + $sourceTokens;
        $output = (int) ceil($sourceTokens * $model->outputRatio);

        return new TranslationEstimate($requests, $input, $output, $model->cost($input, $output));
    }

    /**
     * @param  array<array-key, string>  $chunk
     * @return array<array-key, string>|null
     */
    private function request(array $chunk, string $locale, TranslationContext $context, TextFormat $format): ?array
    {
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'locale' => $locale, 'context' => $context->value];
        $model = $this->models[$context->value];

        $body = [
            'model' => $model->model,
            'max_tokens' => $model->maxTokens,
            'system' => $this->prompt->system($context, $format, $locale),
            'messages' => [['role' => 'user', 'content' => json_encode($chunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'output_config' => ['effort' => $model->effort],
        ];

        try {
            // 180 s: a model that thinks before it answers takes longer than the 60 s that sufficed.
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(180)->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            // A timeout or a lost network is a failed request like any other: the keys stay owed,
            // and the run goes on with the next file instead of stopping half-recorded.
            Log::error('AnthropicTranslator API could not be reached', $ctx + [
                'step' => 'connection',
                'exception' => $e,
            ]);

            return null;
        }

        if ($response->failed()) {
            Log::error('AnthropicTranslator API request failed', $ctx + [
                'step' => 'http_request',
                'http_status' => $response->status(),
                'api_message' => $response->json('error.message') ?? $response->body(),
            ]);

            return null;
        }

        $stopReason = $response->json('stop_reason');
        if ($stopReason === 'refusal') {
            // A safety classifier declined the request: whatever text came with it is not a translation.
            Log::error('AnthropicTranslator API refused to answer', $ctx + [
                'step' => 'refusal',
                'key_count' => count($chunk),
            ]);

            return null;
        }

        if ($stopReason === 'max_tokens') {
            // The JSON is cut mid-way — or never started, the model having thought its budget away.
            Log::error('AnthropicTranslator API answer was cut short', $ctx + [
                'step' => 'truncated',
                'key_count' => count($chunk),
            ]);

            return null;
        }

        // The first text block: a model that thinks puts a thinking block before it.
        $content = $this->firstText($response->json('content'));
        if (! is_string($content) || $content === '') {
            Log::error('AnthropicTranslator API returned empty content', $ctx + [
                'step' => 'parse_response',
                'stop_reason' => $stopReason,
            ]);

            return null;
        }

        return $this->decode($content, $chunk, $format, $ctx);
    }

    /**
     * @param  array<array-key, string>  $chunk
     * @param  array<string, string>  $ctx
     * @return array<array-key, string>|null
     */
    private function decode(string $content, array $chunk, TextFormat $format, array $ctx): ?array
    {
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/m', '', $content) ?? $content;

        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded)) {
            Log::error('AnthropicTranslator API response is not valid JSON', $ctx + [
                'step' => 'json_decode',
                'raw_excerpt' => mb_substr(trim($content), 0, 200),
            ]);

            return null;
        }

        /** @var array<array-key, string> $answered */
        $answered = array_intersect_key(array_filter($decoded, fn (mixed $value) => is_string($value) && $value !== ''), $chunk);
        $missing = array_diff_key($chunk, $answered);

        if ($missing !== []) {
            Log::error('AnthropicTranslator API answer is incomplete', $ctx + [
                'step' => 'incomplete',
                'missing_keys' => array_keys($missing),
            ]);

            return null;
        }

        $broken = array_keys(array_filter($answered, fn (string $value, int|string $key) => ! $format->keeps($chunk[$key], $value), ARRAY_FILTER_USE_BOTH));
        if ($broken !== []) {
            Log::error('AnthropicTranslator API answer broke the format', $ctx + [
                'step' => 'format',
                'format' => $format->value,
                'broken_keys' => $broken,
            ]);

            return null;
        }

        return $answered;
    }

    private function firstText(mixed $blocks): ?string
    {
        foreach ((array) $blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                return is_string($block['text'] ?? null) ? $block['text'] : null;
            }
        }

        return null;
    }

    private function tokens(int $characters): int
    {
        return (int) ceil($characters / 3);
    }

    /**
     * @param  array<array-key, string>  $flat
     */
    private function size(array $flat): int
    {
        return strlen((string) json_encode($flat, JSON_UNESCAPED_UNICODE));
    }
}
