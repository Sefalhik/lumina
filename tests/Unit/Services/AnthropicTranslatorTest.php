<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AnthropicTranslator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsLogs;
use Tests\Concerns\FakesTranslationApi;
use Tests\TestCase;

class AnthropicTranslatorTest extends TestCase
{
    use AssertsLogs;
    use FakesTranslationApi;

    private AnthropicTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->translator = new AnthropicTranslator(
            apiKey: 'test-key',
            model: 'claude-haiku-4-5-20251001',
            nativeNames: ['de' => 'Deutsch', 'en' => 'English'],
        );
    }

    // ── translate() ──────────────────────────────────────────────────────────

    public function test_translate_returns_decoded_array_on_success(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['home' => 'Startseite', 'cv' => 'Lebenslauf']),
                200,
            ),
        ]);

        $result = $this->translator->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de');

        $this->assertSame(['home' => 'Startseite', 'cv' => 'Lebenslauf'], $result);
    }

    public function test_translate_strips_markdown_code_fences(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => "```json\n{\"home\": \"Startseite\"}\n```"]],
            ], 200),
        ]);

        $result = $this->translator->translate(['home' => 'Accueil'], 'de');

        $this->assertSame(['home' => 'Startseite'], $result);
    }

    public function test_translate_returns_null_on_http_failure(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'invalid api key'],
            ], 401),
        ]);

        $result = $this->translator->translate(['home' => 'Accueil'], 'de');

        $this->assertNull($result);
    }

    public function test_translate_returns_null_on_empty_content(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '']],
                'stop_reason' => 'max_tokens',
            ], 200),
        ]);

        $result = $this->translator->translate(['home' => 'Accueil'], 'de');

        $this->assertNull($result);
    }

    public function test_translate_returns_null_on_invalid_json_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'voici la traduction : {{{invalid']],
            ], 200),
        ]);

        $result = $this->translator->translate(['home' => 'Accueil'], 'de');

        $this->assertNull($result);
    }

    public function test_translate_uses_native_name_in_prompt(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['home' => 'Startseite']),
                200,
            ),
        ]);

        $this->translator->translate(['home' => 'Accueil'], 'de');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['messages'][0]['content'], 'Deutsch');
        });
    }

    public function test_translate_falls_back_to_locale_code_when_no_native_name(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                $this->apiResponse(['home' => 'Hem']),
                200,
            ),
        ]);

        $this->translator->translate(['home' => 'Accueil'], 'sv');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['messages'][0]['content'], '(sv)');
        });
    }

    // ── What counts as an answer ─────────────────────────────────────────────

    public function test_an_answer_cut_short_is_refused_even_when_what_came_back_decodes(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                ['stop_reason' => 'max_tokens'] + $this->apiResponse(['home' => 'Startseite']),
                200,
            ),
        ]);

        $this->assertNull($this->translator->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_an_answer_missing_a_key_is_refused(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response($this->apiResponse(['home' => 'Startseite']), 200)]);

        $this->assertNull($this->translator->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de'));
    }

    public function test_an_empty_or_non_string_value_counts_as_missing(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push($this->apiResponse(['home' => '']), 200)
            ->push($this->apiResponse(['home' => ['nested' => 'x']]), 200)]);

        $this->assertNull($this->translator->translate(['home' => 'Accueil'], 'de'));
        $this->assertNull($this->translator->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_keys_that_were_not_asked_for_are_dropped(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response($this->apiResponse(['home' => 'Startseite', 'invented' => 'x']), 200)]);

        $this->assertSame(['home' => 'Startseite'], $this->translator->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_the_text_block_is_read_even_when_another_block_comes_first(): void
    {
        // A model that thinks by default answers with a thinking block before its text.
        Http::fake([self::MESSAGES_URL => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '{"home": "Startseite"}']],
            'stop_reason' => 'end_turn',
        ])]);

        $this->assertSame(['home' => 'Startseite'], $this->translator->translate(['home' => 'Accueil'], 'de'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedContents(): iterable
    {
        yield 'content that is not a list' => ['oops'];
        yield 'a block that is not an object, then a text' => [['oops', ['type' => 'text', 'text' => 5]]];
        yield 'no text block at all' => [[['type' => 'thinking', 'thinking' => '{"home": "x"}']]];
        yield 'no content' => [null];
    }

    #[DataProvider('malformedContents')]
    public function test_a_body_without_a_readable_text_block_is_refused(mixed $content): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response(['content' => $content, 'stop_reason' => 'end_turn'])]);

        $this->assertNull($this->translator->translate(['home' => 'Accueil'], 'de'));
        $this->assertLogged('error', 'AnthropicTranslator API returned empty content', ['step' => 'parse_response']);
    }

    public function test_an_answer_that_is_a_json_list_is_refused(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->apiResponse(['Startseite']))]);

        $this->assertNull($this->translator->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_a_closing_fence_glued_to_the_json_is_stripped(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response([
            'content' => [['type' => 'text', 'text' => '{"home": "Startseite"}```']],
            'stop_reason' => 'end_turn',
        ])]);

        $this->assertSame(['home' => 'Startseite'], $this->translator->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_numeric_keys_are_answered_like_any_other(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->apiResponse(['404' => 'Nicht gefunden']))]);

        $this->assertSame([404 => 'Nicht gefunden'], $this->translator->translate(['404' => 'Introuvable'], 'de'));
    }

    public function test_an_empty_french_value_is_answered_empty_without_being_sent(): void
    {
        $this->fakeTranslations();

        $this->assertSame(['home' => 'DE Accueil', 'blank' => ''], $this->translator->translate(['home' => 'Accueil', 'blank' => ''], 'de'));
        $this->assertSame(['de' => ['home']], $this->sentKeysByLocale());

        $this->fakeTranslations();
        $this->assertSame(['blank' => ''], $this->translator->translate(['blank' => ''], 'de'));
        Http::assertNothingSent();
    }

    // ── The request ──────────────────────────────────────────────────────────

    public function test_the_request_carries_the_key_the_version_the_model_and_the_budget(): void
    {
        $this->fakeTranslations();

        $this->translator->translate(['home' => 'Accueil'], 'de');

        Http::assertSent(fn (Request $request) => $request->url() === self::MESSAGES_URL
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-haiku-4-5-20251001'
            && $request['max_tokens'] === 8192);
    }

    /**
     * The prompt, word for word. LUMN-33 rewrites it on purpose — this test is where it says so.
     */
    public function test_the_prompt_is_the_reviewed_one(): void
    {
        $this->fakeTranslations();

        $this->translator->translate(['home' => 'Accueil'], 'de');

        Http::assertSent(fn (Request $request) => $request['messages'] === [['role' => 'user', 'content' => <<<'PROMPT'
You are a professional translator specializing in UI localization.

Translate the following JSON key-value pairs from French to Deutsch (de).

Rules:
- Translate only the VALUES, never the keys
- Keep all placeholders like {theme}, {name}, {count} exactly as-is
- Keep proper names, brand names, and technical terms untranslated
- Output ONLY the translated JSON object, nothing else — no explanation, no markdown code blocks

Source JSON (French):
{
    "home": "Accueil"
}
PROMPT]]);
    }

    // ── What is logged ───────────────────────────────────────────────────────

    public function test_a_failed_request_is_logged_once_with_its_status_and_nothing_else(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        $this->translator->translate(['home' => 'Accueil'], 'de');

        $this->assertLogged('error', 'AnthropicTranslator API request failed', ['step' => 'http_request', 'locale' => 'de', 'http_status' => 401, 'api_message' => 'invalid api key']);
        $this->assertNotLogged('error', 'AnthropicTranslator API returned empty content');
    }

    public function test_an_empty_answer_is_logged_as_such_and_not_as_invalid_json(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response(['content' => [['type' => 'text', 'text' => '']], 'stop_reason' => 'end_turn'])]);

        $this->assertNull($this->translator->translate(['home' => 'Accueil'], 'de'));

        $this->assertLogged('error', 'AnthropicTranslator API returned empty content', ['step' => 'parse_response', 'stop_reason' => 'end_turn']);
        $this->assertNotLogged('error', 'AnthropicTranslator API response is not valid JSON');
    }

    public function test_a_truncated_an_incomplete_and_an_unreadable_answer_are_logged_with_their_context(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::sequence()
            ->push(['stop_reason' => 'max_tokens'] + $this->apiResponse(['home' => 'x']))
            ->push($this->apiResponse(['home' => 'Startseite']))
            ->push(['content' => [['type' => 'text', 'text' => 'nope']], 'stop_reason' => 'end_turn'])]);

        $this->translator->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de');
        $this->translator->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de');
        $this->translator->translate(['home' => 'Accueil'], 'de');

        $this->assertLogged('error', 'AnthropicTranslator API answer was cut short', ['step' => 'truncated', 'locale' => 'de', 'key_count' => 2]);
        $this->assertLogged('error', 'AnthropicTranslator API answer is incomplete', ['step' => 'incomplete', 'missing_keys' => ['cv']]);
        $this->assertLogged('error', 'AnthropicTranslator API response is not valid JSON', ['step' => 'json_decode', 'raw_excerpt' => 'nope']);
    }

    // ── Requests sized to fit the answer ─────────────────────────────────────

    public function test_an_empty_source_is_no_request(): void
    {
        $this->assertSame([], $this->translator->chunks([]));
    }

    public function test_a_small_source_is_one_request(): void
    {
        $this->assertCount(1, $this->translator->chunks(['home' => 'Accueil', 'cv' => 'CV']));
    }

    public function test_a_large_source_is_split_without_losing_a_key_or_its_order(): void
    {
        $source = $this->largeSource();

        $chunks = $this->translator->chunks($source);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($source, array_merge(...$chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(AnthropicTranslator::CHUNK_CHARACTERS, strlen((string) json_encode($chunk, JSON_UNESCAPED_UNICODE)));
        }
    }

    public function test_a_key_larger_than_the_budget_gets_a_request_of_its_own(): void
    {
        $huge = str_repeat('é', AnthropicTranslator::CHUNK_CHARACTERS);

        $this->assertSame([['a' => 'x'], ['huge' => $huge], ['b' => 'y']], $this->translator->chunks(['a' => 'x', 'huge' => $huge, 'b' => 'y']));
    }

    public function test_a_first_key_larger_than_the_budget_is_not_preceded_by_an_empty_request(): void
    {
        $huge = str_repeat('é', AnthropicTranslator::CHUNK_CHARACTERS);

        $this->assertSame([['huge' => $huge], ['b' => 'y']], $this->translator->chunks(['huge' => $huge, 'b' => 'y']));
    }

    public function test_a_request_of_exactly_the_budget_is_not_split(): void
    {
        // {"a":"x","b":"…"} is 16 characters around the value.
        $source = ['a' => 'x', 'b' => str_repeat('v', AnthropicTranslator::CHUNK_CHARACTERS - 16)];

        $this->assertSame([$source], $this->translator->chunks($source));
        $this->assertCount(2, $this->translator->chunks($source + ['c' => '']));
    }

    public function test_every_request_of_a_split_source_is_sent_and_merged(): void
    {
        $source = $this->largeSource();
        $chunks = $this->translator->chunks($source);
        $sequence = Http::sequence();
        foreach ($chunks as $chunk) {
            $sequence->push($this->apiResponse(array_map(fn (string $value) => 'DE '.$value, $chunk)), 200);
        }
        Http::fake(['https://api.anthropic.com/v1/messages' => $sequence]);

        $result = $this->translator->translate($source, 'de');

        $this->assertSame(array_map(fn (string $value) => 'DE '.$value, $source), $result);
        Http::assertSentCount(count($chunks));
    }

    public function test_one_failed_request_fails_the_whole_source(): void
    {
        $source = $this->largeSource();
        [$first] = $this->translator->chunks($source);
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push($this->apiResponse($first), 200)
            ->push(['error' => ['message' => 'overloaded']], 529)]);

        $this->assertNull($this->translator->translate($source, 'de'));
    }

    // ── Estimate ─────────────────────────────────────────────────────────────

    public function test_the_estimate_counts_one_request_per_chunk_and_more_output_than_input_source(): void
    {
        $source = $this->largeSource();

        $estimate = $this->translator->estimate($source);

        $this->assertSame(count($this->translator->chunks($source)), $estimate['requests']);
        $this->assertGreaterThan($estimate['input_tokens'] - 200 * $estimate['requests'], $estimate['output_tokens']);
    }

    public function test_the_model_is_the_configured_one(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', $this->translator->model());
    }

    /**
     * @return array<string, string> about three times the budget of one request
     */
    private function largeSource(): array
    {
        $source = [];
        for ($i = 0; $i < 150; $i++) {
            $source["key_{$i}"] = "Une phrase française d'une longueur ordinaire, numéro {$i}, pour remplir.";
        }

        return $source;
    }
}
