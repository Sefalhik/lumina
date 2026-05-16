<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AnthropicTranslator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicTranslatorTest extends TestCase
{
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

    // ── flattenJson() ────────────────────────────────────────────────────────

    public function test_flatten_flat_array_returns_same_array(): void
    {
        $result = $this->translator->flattenJson(['home' => 'Accueil', 'cv' => 'CV']);

        $this->assertSame(['home' => 'Accueil', 'cv' => 'CV'], $result);
    }

    public function test_flatten_nested_array_uses_dot_notation(): void
    {
        $result = $this->translator->flattenJson(['nav' => ['home' => 'Accueil', 'cv' => 'CV']]);

        $this->assertSame(['nav.home' => 'Accueil', 'nav.cv' => 'CV'], $result);
    }

    public function test_flatten_deeply_nested_array(): void
    {
        $result = $this->translator->flattenJson(['a' => ['b' => ['c' => 'valeur']]]);

        $this->assertSame(['a.b.c' => 'valeur'], $result);
    }

    public function test_flatten_casts_non_string_scalar_to_string(): void
    {
        $result = $this->translator->flattenJson(['count' => 42]); // @phpstan-ignore-line

        $this->assertSame(['count' => '42'], $result);
    }

    // ── unflattenJson() ──────────────────────────────────────────────────────

    public function test_unflatten_restores_nested_structure(): void
    {
        $result = $this->translator->unflattenJson(['nav.home' => 'Accueil', 'nav.cv' => 'CV']);

        $this->assertSame(['nav' => ['home' => 'Accueil', 'cv' => 'CV']], $result);
    }

    public function test_unflatten_is_inverse_of_flatten(): void
    {
        $original = ['a' => ['b' => 'x'], 'c' => 'y'];
        $result = $this->translator->unflattenJson(
            $this->translator->flattenJson($original),
        );

        $this->assertSame($original, $result);
    }

    public function test_unflatten_flat_keys_returns_same_structure(): void
    {
        $flat = ['home' => 'Accueil', 'cv' => 'CV'];
        $result = $this->translator->unflattenJson($flat);

        $this->assertSame($flat, $result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function apiResponse(array $data): array
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
}
