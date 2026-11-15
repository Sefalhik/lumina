<?php

declare(strict_types=1);

namespace Tests\Feature\Translation;

use App\Services\AnthropicTranslator;
use App\Services\Translation\TextFormat;
use App\Services\Translation\TranslationContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesTranslationApi;
use Tests\TestCase;

/**
 * The models are a decision of the project (LUMN-33): read from config/i18n.php, one per context,
 * and from nowhere else — no environment variable picks them.
 */
class TranslationModelsTest extends TestCase
{
    use FakesTranslationApi;

    public function test_each_context_is_translated_with_the_model_and_effort_its_configuration_names(): void
    {
        config([
            'i18n.translation.interface.model' => 'configured-interface-model',
            'i18n.translation.interface.effort' => 'low',
            'i18n.translation.prose.model' => 'configured-prose-model',
            'i18n.translation.prose.effort' => 'high',
            'i18n.translation.prose.max_tokens' => 24000,
        ]);
        $this->fakeTranslations();
        $translator = $this->app->make(AnthropicTranslator::class);

        $translator->translate(['home' => 'Accueil'], 'de', TranslationContext::Interface, TextFormat::Laravel);
        $translator->translate(['bio' => 'Bio'], 'de', TranslationContext::Prose, TextFormat::Plain);

        $sent = array_map(fn (array $pair) => $pair[0], Http::recorded()->all());
        $this->assertCount(2, $sent);
        [$interface, $prose] = $sent;
        $this->assertSame(['configured-interface-model', 'low', 16000], $this->settings($interface));
        $this->assertSame(['configured-prose-model', 'high', 24000], $this->settings($prose));
    }

    public function test_the_shipped_configuration_builds_a_translator_with_the_benched_models(): void
    {
        $translator = $this->app->make(AnthropicTranslator::class);

        $this->assertSame('claude-haiku-5-5', $translator->model(TranslationContext::Interface));
        $this->assertSame('claude-opus-5-5', $translator->model(TranslationContext::Prose));
    }

    public function test_no_environment_variable_chooses_a_model(): void
    {
        $this->assertSame(['api_key'], array_keys(config('services.anthropic')));
        $this->assertStringNotContainsString('ANTHROPIC_MODEL', (string) file_get_contents(base_path('.env.example')));
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private function settings(Request $request): array
    {
        return [$request['model'], $request['output_config']['effort'] ?? null, $request['max_tokens']];
    }
}
