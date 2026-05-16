<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicTranslator
{
    /**
     * @param  array<string, string>  $nativeNames
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly array $nativeNames,
    ) {}

    /**
     * Translate flat key-value pairs from French to the given locale.
     *
     * Returns null on any API or parsing error (details are logged).
     *
     * @param  array<string, string>  $flatSource
     * @return array<string, string>|null
     */
    public function translate(array $flatSource, string $locale): ?array
    {
        $nativeName = $this->nativeNames[$locale] ?? $locale;
        $sourceJson = json_encode($flatSource, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
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

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 8192,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? $response->body();
            Log::error('AnthropicTranslator API request failed', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'http_request',
                'locale' => $locale,
                'http_status' => $response->status(),
                'api_message' => $message,
            ]);

            return null;
        }

        $body = $response->json();
        $content = $body['content'][0]['text'] ?? null;

        if (! is_string($content) || empty($content)) {
            Log::error('AnthropicTranslator API returned empty content', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'parse_response',
                'locale' => $locale,
                'stop_reason' => $body['stop_reason'] ?? null,
            ]);

            return null;
        }

        $content = preg_replace('/^```(?:json)?\s*/m', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/m', '', $content) ?? $content;

        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded)) {
            Log::error('AnthropicTranslator API response is not valid JSON', [
                'service' => self::class,
                'method' => __FUNCTION__,
                'step' => 'json_decode',
                'locale' => $locale,
                'stop_reason' => $body['stop_reason'] ?? null,
                'raw_excerpt' => mb_substr(trim($content), 0, 200),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, string>
     */
    public function flattenJson(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix.'.'.$key : (string) $key;
            if (is_array($value)) {
                $result += $this->flattenJson($value, $fullKey);
            } else {
                $result[$fullKey] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $flat
     * @return array<string, mixed>
     */
    public function unflattenJson(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            $result = $this->mergeDeep($result, $this->expandKey(explode('.', $key), $value));
        }

        return $result;
    }

    /**
     * @param  non-empty-list<string>  $keys
     * @return array<string, mixed>
     */
    private function expandKey(array $keys, string $value): array
    {
        $head = array_shift($keys);

        return $keys === [] ? [$head => $value] : [$head => $this->expandKey($keys, $value)];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = $this->mergeDeep($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
