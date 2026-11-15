<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Anthropic Messages API, faked for the translation commands and services. No test reaches
 * the network: every answer comes from here, and `Http::preventStrayRequests()` makes any other
 * request throw.
 */
trait FakesTranslationApi
{
    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * A body as the API returns it, carrying the given JSON as its text.
     *
     * @param  array<array-key, mixed>  $data  a JSON object, or a list for a test that sends the wrong shape
     * @return array<string, mixed>
     */
    protected function apiResponse(array $data): array
    {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => json_encode($data)]],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ];
    }

    /**
     * Answers every request by "translating" each value it was sent into `<LOCALE> <value>` —
     * `DE Accueil` — so a test can tell what was translated, into which locale, and when. A locale
     * listed in `$failing` gets an HTTP error instead.
     *
     * Each call starts a fresh record of requests: what a test inspects afterwards is what was sent
     * since its last call.
     *
     * @param  list<string>  $failing
     */
    protected function fakeTranslations(array $failing = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::MESSAGES_URL => function (Request $request) use ($failing) {
            $locale = $this->requestedLocale($request);
            if (in_array($locale, $failing, true)) {
                return Http::response(['error' => ['message' => 'overloaded']], 529);
            }

            return Http::response($this->apiResponse(array_map(
                fn (string $value) => strtoupper($locale).' '.$value,
                $this->requestedStrings($request),
            )));
        }]);
    }

    /**
     * The French strings sent in one request, read back from its prompt.
     *
     * @return array<string, string>
     */
    protected function requestedStrings(Request $request): array
    {
        $prompt = (string) $request->data()['messages'][0]['content'];
        $marker = "Source JSON (French):\n";

        /** @var array<string, string> */
        return json_decode(substr($prompt, (int) strpos($prompt, $marker) + strlen($marker)), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function requestedLocale(Request $request): string
    {
        preg_match('/ \(([a-z]{2})\)\.\n/', (string) $request->data()['messages'][0]['content'], $match);

        return $match[1] ?? '';
    }

    /**
     * Every key sent to the API, by locale, over the whole test.
     *
     * @return array<string, list<string>>
     */
    protected function sentKeysByLocale(): array
    {
        $sent = [];
        foreach (Http::recorded() as [$request]) {
            $sent[$this->requestedLocale($request)] = [
                ...($sent[$this->requestedLocale($request)] ?? []),
                ...array_map(strval(...), array_keys($this->requestedStrings($request))),
            ];
        }
        ksort($sent);

        return $sent;
    }
}
