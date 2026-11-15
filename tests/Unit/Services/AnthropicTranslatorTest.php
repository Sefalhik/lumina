<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AnthropicTranslator;
use App\Services\Translation\TextFormat;
use App\Services\Translation\TranslationContext;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationModel;
use App\Services\Translation\TranslationPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\AssertsLogs;
use Tests\Concerns\FakesTranslationApi;
use Tests\TestCase;

class AnthropicTranslatorTest extends TestCase
{
    use AssertsLogs;
    use FakesTranslationApi;

    private AnthropicTranslator $translator;

    private TranslationPrompt $prompt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompt = new TranslationPrompt(
            resource_path('prompts/translation'),
            config('i18n.translation_languages'),
            ['Neuromatrix', 'biocortex'],
        );
        $this->translator = new AnthropicTranslator('test-key', $this->prompt, $this->models());
    }

    /**
     * Two contexts that differ in everything, so that a request built with the wrong one shows.
     *
     * @return array<string, TranslationModel>
     */
    private function models(int $interfaceMaxTokens = 16000): array
    {
        return [
            'interface' => new TranslationModel('interface-model', 'low', $interfaceMaxTokens, 1.0, 2.0, 1.5),
            'prose' => new TranslationModel('prose-model', 'medium', 20000, 3.0, 4.0, 4.0),
        ];
    }

    /**
     * The calls whose subject is not the context nor the format: interface labels, plain text.
     *
     * @param  array<array-key, string>  $source
     * @return array<array-key, string>|null
     */
    private function translate(array $source, string $locale): ?array
    {
        return $this->translator->translate($source, $locale, TranslationContext::Interface, TextFormat::Plain);
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

        $result = $this->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de');

        $this->assertSame(['home' => 'Startseite', 'cv' => 'Lebenslauf'], $result);
    }

    public function test_translate_strips_markdown_code_fences(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => "```json\n{\"home\": \"Startseite\"}\n```"]],
            ], 200),
        ]);

        $result = $this->translate(['home' => 'Accueil'], 'de');

        $this->assertSame(['home' => 'Startseite'], $result);
    }

    public function test_translate_returns_null_on_http_failure(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'error' => ['message' => 'invalid api key'],
            ], 401),
        ]);

        $result = $this->translate(['home' => 'Accueil'], 'de');

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

        $result = $this->translate(['home' => 'Accueil'], 'de');

        $this->assertNull($result);
    }

    public function test_translate_returns_null_on_invalid_json_response(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'voici la traduction : {{{invalid']],
            ], 200),
        ]);

        $result = $this->translate(['home' => 'Accueil'], 'de');

        $this->assertNull($result);
    }

    // ── The request ──────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{TranslationContext, TextFormat, string, string, int, string}>
     */
    public static function contexts(): iterable
    {
        yield 'interface labels, from a Laravel file' => [TranslationContext::Interface, TextFormat::Laravel, 'interface-model', 'low', 16000, 'sv'];
        yield 'interface labels, from a vue-i18n file' => [TranslationContext::Interface, TextFormat::VueI18n, 'interface-model', 'low', 16000, 'de'];
        yield 'prose' => [TranslationContext::Prose, TextFormat::Plain, 'prose-model', 'medium', 20000, 'en'];
    }

    #[DataProvider('contexts')]
    public function test_the_request_is_built_from_its_context_its_format_and_its_locale(
        TranslationContext $context,
        TextFormat $format,
        string $model,
        string $effort,
        int $maxTokens,
        string $locale,
    ): void {
        $this->fakeTranslations();

        $this->translator->translate(['home' => 'Accueil'], $locale, $context, $format);

        Http::assertSent(fn (Request $request) => $request->url() === self::MESSAGES_URL
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === $model
            && $request['max_tokens'] === $maxTokens
            && $request['output_config'] === ['effort' => $effort]
            && $request['system'] === $this->prompt->system($context, $format, $locale)
            && ! array_key_exists('thinking', $request->data()));
    }

    public function test_the_glossary_goes_with_every_request(): void
    {
        $this->fakeTranslations();

        $this->translator->translate(['home' => 'Accueil'], 'de', TranslationContext::Interface, TextFormat::Laravel);
        $this->translator->translate(['bio' => 'Bio'], 'de', TranslationContext::Prose, TextFormat::Plain);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains((string) $request['system'], 'Neuromatrix, biocortex'));
        Http::assertNotSent(fn (Request $request) => ! str_contains((string) $request['system'], 'Neuromatrix, biocortex'));
    }

    public function test_the_user_turn_is_the_french_as_written(): void
    {
        $this->fakeTranslations();

        $this->translate(['subtitle' => 'Tech Lead // Interfaces bio-machines'], 'de');

        // Neither the slashes nor the accents escaped: the model reads the text as the author wrote it.
        Http::assertSent(fn (Request $request) => $request['messages'] === [['role' => 'user', 'content' => <<<'JSON'
{
    "subtitle": "Tech Lead // Interfaces bio-machines"
}
JSON]]);
    }

    public function test_the_model_of_each_context_is_named(): void
    {
        $this->assertSame('interface-model', $this->translator->model(TranslationContext::Interface));
        $this->assertSame('prose-model', $this->translator->model(TranslationContext::Prose));
    }

    // ── A model sized for its context ────────────────────────────────────────

    public function test_a_context_without_a_model_is_refused_at_construction(): void
    {
        try {
            new AnthropicTranslator('test-key', $this->prompt, ['interface' => $this->models()['interface']]);
            $this->fail('A translator without a prose model was built.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('No translation model for the prose context in config/i18n.php (translation).', $e->getMessage());
        }
    }

    public function test_a_model_without_room_to_think_is_refused_at_construction(): void
    {
        new AnthropicTranslator('test-key', $this->prompt, $this->models(AnthropicTranslator::MIN_MAX_TOKENS));

        try {
            new AnthropicTranslator('test-key', $this->prompt, $this->models(AnthropicTranslator::MIN_MAX_TOKENS - 1));
            $this->fail('A model with no room to think was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The interface translation model needs max_tokens of at least 12000.', $e->getMessage());
        }
    }

    public function test_the_room_left_holds_the_longest_answer_and_thinking_on_top(): void
    {
        // A chunk of French is ~CHUNK_CHARACTERS / 3 tokens, twice that in Greek: the floor must hold
        // that answer, and at least as much again for the thinking that precedes it.
        $longestAnswer = (int) ceil(AnthropicTranslator::CHUNK_CHARACTERS / 3) * 2;

        $this->assertGreaterThanOrEqual(2 * $longestAnswer, AnthropicTranslator::MIN_MAX_TOKENS);
    }

    public function test_an_answer_cut_short_while_the_model_was_still_thinking_is_refused(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => '']],
            'stop_reason' => 'max_tokens',
        ])]);

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
        $this->assertLogged('error', 'AnthropicTranslator API answer was cut short', ['step' => 'truncated', 'context' => 'interface']);
    }

    // ── Requests that never become an answer ────────────────────────────────

    public function test_a_request_that_never_reached_the_api_fails_cleanly_and_is_logged(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::failedConnection('cURL error 28: Operation timed out')]);

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
        $this->assertLogged('error', 'AnthropicTranslator API could not be reached', ['step' => 'connection', 'locale' => 'de', 'context' => 'interface']);
        // The whole exception, as the logging conventions want it — not its message alone.
        $this->logSpy()->shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'AnthropicTranslator API could not be reached'
            && ($context['exception'] ?? null) instanceof ConnectionException
            && str_contains($context['exception']->getMessage(), 'Operation timed out'))->once();
    }

    public function test_a_refusal_is_no_answer_even_when_its_text_decodes(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response(['stop_reason' => 'refusal'] + $this->apiResponse(['home' => 'Startseite']))]);

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
        $this->assertLogged('error', 'AnthropicTranslator API refused to answer', ['step' => 'refusal', 'key_count' => 1]);
        $this->assertNotLogged('error', 'AnthropicTranslator API answer was cut short');
    }

    public function test_a_prompt_that_cannot_be_built_stops_the_call_before_anything_is_sent(): void
    {
        $this->fakeTranslations();
        $translator = new AnthropicTranslator('test-key', new TranslationPrompt('/nowhere', ['de' => 'German'], []), $this->models());

        try {
            $translator->translate(['home' => 'Accueil'], 'de', TranslationContext::Interface, TextFormat::Laravel);
            $this->fail('A request went out without its prompt.');
        } catch (RuntimeException $e) {
            $this->assertSame('The translation prompt interface.md cannot be read from /nowhere.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // ── An answer that breaks its format (criterion 11) ──────────────────────

    /**
     * @return iterable<string, array{TextFormat, string, string}>
     */
    public static function brokenFormats(): iterable
    {
        yield 'a Laravel placeholder lost' => [TextFormat::Laravel, 'Le champ :attribute est requis.', 'Das Feld ist erforderlich.'];
        yield 'a Laravel placeholder translated' => [TextFormat::Laravel, 'Le champ :attribute est requis.', 'Das Feld :attribut ist erforderlich.'];
        yield 'a Laravel placeholder added' => [TextFormat::Laravel, 'Profil :network', 'Profil :network :value'];
        yield 'a plural separator written in a Laravel string' => [TextFormat::Laravel, 'Pluie et néons', 'Regen | Neon'];
        yield 'a vue-i18n placeholder lost' => [TextFormat::VueI18n, 'Thème actuel : {theme}', 'Aktuelles Thema'];
        yield 'a vue-i18n placeholder translated' => [TextFormat::VueI18n, 'Thème actuel : {theme}', 'Aktuelles Thema: {Thema}'];
        yield 'a vue-i18n linked-message sign' => [TextFormat::VueI18n, 'Envoyer un e-mail', 'E-Mail @ senden'];
    }

    #[DataProvider('brokenFormats')]
    public function test_an_answer_that_breaks_its_format_is_refused_and_says_which_key(TextFormat $format, string $french, string $translation): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response($this->apiResponse(['ok' => 'Startseite', 'broken' => $translation]))]);

        $this->assertNull($this->translator->translate(['ok' => 'Accueil', 'broken' => $french], 'de', TranslationContext::Interface, $format));
        $this->assertLogged('error', 'AnthropicTranslator API answer broke the format', [
            'step' => 'format',
            'format' => $format->value,
            'broken_keys' => ['broken'],
        ]);
    }

    public function test_placeholders_may_move_where_the_grammar_needs_them(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->apiResponse(['msg' => ':max Zeichen höchstens für :attribute']))]);

        $this->assertSame(
            ['msg' => ':max Zeichen höchstens für :attribute'],
            $this->translator->translate(['msg' => 'Le champ :attribute ne doit pas dépasser :max caractères.'], 'de', TranslationContext::Interface, TextFormat::Laravel),
        );
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

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_an_answer_missing_a_key_is_refused(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response($this->apiResponse(['home' => 'Startseite']), 200)]);

        $this->assertNull($this->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de'));
    }

    public function test_an_empty_or_non_string_value_counts_as_missing(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push($this->apiResponse(['home' => '']), 200)
            ->push($this->apiResponse(['home' => ['nested' => 'x']]), 200)]);

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_keys_that_were_not_asked_for_are_dropped(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response($this->apiResponse(['home' => 'Startseite', 'invented' => 'x']), 200)]);

        $this->assertSame(['home' => 'Startseite'], $this->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_the_text_block_is_read_even_when_another_block_comes_first(): void
    {
        // A model that thinks by default answers with a thinking block before its text.
        Http::fake([self::MESSAGES_URL => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '{"home": "Startseite"}']],
            'stop_reason' => 'end_turn',
        ])]);

        $this->assertSame(['home' => 'Startseite'], $this->translate(['home' => 'Accueil'], 'de'));
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

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
        $this->assertLogged('error', 'AnthropicTranslator API returned empty content', ['step' => 'parse_response']);
    }

    public function test_an_answer_that_is_a_json_list_is_refused(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->apiResponse(['Startseite']))]);

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_a_closing_fence_glued_to_the_json_is_stripped(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response([
            'content' => [['type' => 'text', 'text' => '{"home": "Startseite"}```']],
            'stop_reason' => 'end_turn',
        ])]);

        $this->assertSame(['home' => 'Startseite'], $this->translate(['home' => 'Accueil'], 'de'));
    }

    public function test_numeric_keys_are_answered_like_any_other(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->apiResponse(['404' => 'Nicht gefunden']))]);

        $this->assertSame([404 => 'Nicht gefunden'], $this->translate(['404' => 'Introuvable'], 'de'));
    }

    public function test_an_empty_french_value_is_answered_empty_without_being_sent(): void
    {
        $this->fakeTranslations();

        $this->assertSame(['home' => 'DE Accueil', 'blank' => ''], $this->translate(['home' => 'Accueil', 'blank' => ''], 'de'));
        $this->assertSame(['de' => ['home']], $this->sentKeysByLocale());

        $this->fakeTranslations();
        $this->assertSame(['blank' => ''], $this->translate(['blank' => ''], 'de'));
        Http::assertNothingSent();
    }

    // ── What is logged ───────────────────────────────────────────────────────

    public function test_a_failed_request_is_logged_once_with_its_status_and_nothing_else(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response(['error' => ['message' => 'invalid api key']], 401)]);

        $this->translate(['home' => 'Accueil'], 'de');

        $this->assertLogged('error', 'AnthropicTranslator API request failed', ['step' => 'http_request', 'locale' => 'de', 'http_status' => 401, 'api_message' => 'invalid api key']);
        $this->assertNotLogged('error', 'AnthropicTranslator API returned empty content');
    }

    public function test_an_empty_answer_is_logged_as_such_and_not_as_invalid_json(): void
    {
        $this->spyOnLogs();
        Http::fake([self::MESSAGES_URL => Http::response(['content' => [['type' => 'text', 'text' => '']], 'stop_reason' => 'end_turn'])]);

        $this->assertNull($this->translate(['home' => 'Accueil'], 'de'));

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

        $this->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de');
        $this->translate(['home' => 'Accueil', 'cv' => 'CV'], 'de');
        $this->translate(['home' => 'Accueil'], 'de');

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

        $result = $this->translate($source, 'de');

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

        $this->assertNull($this->translate($source, 'de'));
    }

    // ── Estimate (criterion 9) ───────────────────────────────────────────────

    public function test_the_estimate_counts_one_request_per_chunk_and_prices_the_context_model(): void
    {
        $source = $this->largeSource();
        $sourceTokens = (int) ceil(strlen((string) json_encode($source, JSON_UNESCAPED_UNICODE)) / 3);
        $systemTokens = (int) ceil(strlen($this->prompt->system(TranslationContext::Prose, TextFormat::Plain, 'de')) / 3);
        $requests = count($this->translator->chunks($source));

        $estimate = $this->translator->estimate($source, 'de', TranslationContext::Prose, TextFormat::Plain);

        // The prose model: output four times the French, at $3 and $4 per million tokens.
        $input = $requests * $systemTokens + $sourceTokens;
        $output = $sourceTokens * 4;
        $this->assertSame([$requests, $input, $output], [$estimate->requests, $estimate->inputTokens, $estimate->outputTokens]);
        $this->assertEqualsWithDelta(($input * 3.0 + $output * 4.0) / 1_000_000, $estimate->cost, 1e-12);
        $this->assertGreaterThan(1, $requests);
    }

    public function test_the_estimate_of_each_context_uses_its_own_model(): void
    {
        $source = ['home' => 'Accueil', 'cv' => 'CV'];

        $interface = $this->translator->estimate($source, 'de', TranslationContext::Interface, TextFormat::Plain);
        $prose = $this->translator->estimate($source, 'de', TranslationContext::Prose, TextFormat::Plain);

        $sourceTokens = (int) ceil(strlen((string) json_encode($source, JSON_UNESCAPED_UNICODE)) / 3);
        $this->assertSame((int) ceil($sourceTokens * 1.5), $interface->outputTokens);
        $this->assertSame($sourceTokens * 4, $prose->outputTokens);
    }

    public function test_the_estimate_counts_the_system_prompt_of_the_format_and_the_locale(): void
    {
        $source = ['home' => 'Accueil'];

        $laravel = $this->translator->estimate($source, 'de', TranslationContext::Interface, TextFormat::Laravel);
        $plain = $this->translator->estimate($source, 'de', TranslationContext::Interface, TextFormat::Plain);

        $this->assertSame(
            // {"home":"Accueil"} is 18 characters: 6 tokens of French.
            (int) ceil(strlen($this->prompt->system(TranslationContext::Interface, TextFormat::Laravel, 'de')) / 3) + 6,
            $laravel->inputTokens,
        );
        $this->assertGreaterThan($plain->inputTokens, $laravel->inputTokens);
    }

    public function test_an_empty_french_value_is_not_counted_as_sent(): void
    {
        $this->assertEquals(
            new TranslationEstimate,
            $this->translator->estimate(['blank' => ''], 'de', TranslationContext::Interface, TextFormat::Plain),
        );
        $this->assertEquals(
            $this->translator->estimate(['home' => 'Accueil'], 'de', TranslationContext::Interface, TextFormat::Plain),
            $this->translator->estimate(['home' => 'Accueil', 'blank' => ''], 'de', TranslationContext::Interface, TextFormat::Plain),
        );
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
