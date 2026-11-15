<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicTranslator
{
    /**
     * Characters of source JSON sent in one request. The answer comes back in `max_tokens` at most,
     * and a translation into Greek or Bulgarian takes about twice the tokens of the French: 6 000
     * characters of French (~2 000 tokens) leave room below 8 192 in every target language.
     */
    public const CHUNK_CHARACTERS = 6000;

    private const MAX_TOKENS = 8192;

    /** Rough cost of the instructions that wrap every request, in tokens. */
    private const PROMPT_TOKENS = 200;

    /**
     * @param  array<string, string>  $nativeNames
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly array $nativeNames,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Translate flat key-value pairs from French to the given locale, in as many requests as
     * {@see chunks()} makes of them.
     *
     * Returns every requested key, or null — never part of them: a request that fails, an answer
     * cut short, or an answer missing a key fails the whole call (details are logged). An empty
     * French value is answered empty, and never sent: a model asked to translate nothing answers
     * nothing, which would count as a missing key, run after run.
     *
     * @param  array<array-key, string>  $flatSource
     * @return array<array-key, string>|null
     */
    public function translate(array $flatSource, string $locale): ?array
    {
        $translated = array_filter($flatSource, fn (string $value) => $value === '');

        foreach ($this->chunks(array_diff_key($flatSource, $translated)) as $chunk) {
            $answer = $this->request($chunk, $locale);
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
     * An order of magnitude, shown before a run is confirmed — not a bill. Output is counted at
     * one and a half times the input, for the scripts that take more tokens than French.
     *
     * @param  array<array-key, string>  $flatSource
     * @return array{requests: int, input_tokens: int, output_tokens: int}
     */
    public function estimate(array $flatSource): array
    {
        $requests = count($this->chunks($flatSource));
        $sourceTokens = (int) ceil($this->size($flatSource) / 3);

        return [
            'requests' => $requests,
            'input_tokens' => $requests * self::PROMPT_TOKENS + $sourceTokens,
            'output_tokens' => (int) ceil($sourceTokens * 1.5),
        ];
    }

    /**
     * @param  array<array-key, string>  $chunk
     * @return array<array-key, string>|null
     */
    private function request(array $chunk, string $locale): ?array
    {
        $ctx = ['service' => self::class, 'method' => __FUNCTION__, 'locale' => $locale];

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $this->prompt($chunk, $locale)]],
        ]);

        if ($response->failed()) {
            Log::error('AnthropicTranslator API request failed', $ctx + [
                'step' => 'http_request',
                'http_status' => $response->status(),
                'api_message' => $response->json('error.message') ?? $response->body(),
            ]);

            return null;
        }

        $stopReason = $response->json('stop_reason');
        if ($stopReason === 'max_tokens') {
            // The JSON is cut mid-way: whatever decodes from it is not the whole answer.
            Log::error('AnthropicTranslator API answer was cut short', $ctx + [
                'step' => 'truncated',
                'key_count' => count($chunk),
            ]);

            return null;
        }

        // The first text block: a model that thinks by default puts a thinking block before it.
        $content = $this->firstText($response->json('content'));
        if (! is_string($content) || $content === '') {
            Log::error('AnthropicTranslator API returned empty content', $ctx + [
                'step' => 'parse_response',
                'stop_reason' => $stopReason,
            ]);

            return null;
        }

        return $this->decode($content, $chunk, $ctx);
    }

    /**
     * @param  array<array-key, string>  $chunk
     * @param  array<string, string>  $ctx
     * @return array<array-key, string>|null
     */
    private function decode(string $content, array $chunk, array $ctx): ?array
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

        $answered = array_filter($decoded, fn (mixed $value) => is_string($value) && $value !== '');
        $missing = array_diff_key($chunk, $answered);

        if ($missing !== []) {
            Log::error('AnthropicTranslator API answer is incomplete', $ctx + [
                'step' => 'incomplete',
                'missing_keys' => array_keys($missing),
            ]);

            return null;
        }

        /** @var array<array-key, string> */
        return array_intersect_key($answered, $chunk);
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

    /**
     * @param  array<array-key, string>  $chunk
     */
    private function prompt(array $chunk, string $locale): string
    {
        $nativeName = $this->nativeNames[$locale] ?? $locale;
        $sourceJson = json_encode($chunk, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a professional translator specializing in UI localization.

Translate the following JSON key-value pairs from French to {$nativeName} ({$locale}).

Rules:
- Translate only the VALUES, never the keys
- Keep all placeholders like {theme}, {name}, {count} exactly as-is
- Keep proper names, brand names, and technical terms untranslated
- Output ONLY the translated JSON object, nothing else — no explanation, no markdown code blocks

Source JSON (French):
{$sourceJson}
PROMPT;
    }

    /**
     * @param  array<array-key, string>  $flat
     */
    private function size(array $flat): int
    {
        return strlen((string) json_encode($flat, JSON_UNESCAPED_UNICODE));
    }
}
