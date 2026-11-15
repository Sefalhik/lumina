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
            'model' => 'claude-haiku-5-5',
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
        $this->fakeApi(function (Request $request) use ($failing) {
            $locale = $this->requestedLocale($request);
            if (in_array($locale, $failing, true)) {
                return Http::response(['error' => ['message' => 'overloaded']], 529);
            }

            return Http::response($this->apiResponse(array_map(
                fn (string $value) => strtoupper($locale).' '.$value,
                $this->requestedStrings($request),
            )));
        });
    }

    /**
     * Answers every request with `$answer`, replacing whatever was faked before — a second
     * `Http::fake()` would only be consulted after the first. Starts a fresh record of requests.
     *
     * @param  callable(Request): mixed  $answer
     */
    protected function fakeApi(callable $answer): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::MESSAGES_URL => $answer]);
    }

    /**
     * The French strings sent in one request: the user turn is the JSON object itself.
     *
     * @return array<string, string>
     */
    protected function requestedStrings(Request $request): array
    {
        /** @var array<string, string> */
        return json_decode((string) $request->data()['messages'][0]['content'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The locale a request asks for, read back from the language its system prompt names on its
     * first line ("… into British English.") through `config/i18n.php`, `translation_languages`.
     */
    protected function requestedLocale(Request $request): string
    {
        preg_match('/ into (.+?)\.\n/', (string) $request->data()['system'], $match);
        $locale = array_search($match[1] ?? '', (array) config('i18n.translation_languages'), true);

        return is_string($locale) ? $locale : '';
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
