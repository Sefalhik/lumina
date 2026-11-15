<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\TextFormat;
use App\Services\Translation\TranslationContext;
use App\Services\Translation\TranslationPrompt;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\UsesTemporaryDirectory;
use Tests\TestCase;

class TranslationPromptTest extends TestCase
{
    use UsesTemporaryDirectory;

    private string $prompts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prompts = $this->temporaryDirectory();
        mkdir($this->prompts.'/formats');
        file_put_contents($this->prompts.'/interface.md', "\nLabels into {{language}}.\n\n");
        file_put_contents($this->prompts.'/prose.md', "Prose into {{language}}.\n");
        file_put_contents($this->prompts.'/formats/laravel.md', "Laravel syntax for {{language}}.\n");
        file_put_contents($this->prompts.'/formats/vue-i18n.md', "vue-i18n syntax.\n");
        file_put_contents($this->prompts.'/rules.md', "Keep {{glossary}}.\n");
    }

    private function prompt(): TranslationPrompt
    {
        return new TranslationPrompt($this->prompts, ['de' => 'German', 'en' => 'British English'], ['Neuromatrix', 'Tech Lead']);
    }

    // ── Assembly ─────────────────────────────────────────────────────────────

    public function test_the_trade_then_the_format_then_the_rules_with_the_language_and_the_glossary(): void
    {
        $this->assertSame(
            "Labels into British English.\n\nLaravel syntax for British English.\n\nKeep Neuromatrix, Tech Lead.",
            $this->prompt()->system(TranslationContext::Interface, TextFormat::Laravel, 'en'),
        );
        $this->assertSame(
            "Labels into German.\n\nvue-i18n syntax.\n\nKeep Neuromatrix, Tech Lead.",
            $this->prompt()->system(TranslationContext::Interface, TextFormat::VueI18n, 'de'),
        );
    }

    public function test_prose_has_no_format_to_follow(): void
    {
        $this->assertSame(
            "Prose into German.\n\nKeep Neuromatrix, Tech Lead.",
            $this->prompt()->system(TranslationContext::Prose, TextFormat::Plain, 'de'),
        );
    }

    public function test_a_locale_without_a_language_name_is_refused_rather_than_guessed(): void
    {
        try {
            $this->prompt()->system(TranslationContext::Prose, TextFormat::Plain, 'sv');
            $this->fail('A locale without a language name was translated.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame("No language is named for locale 'sv' in config/i18n.php (translation_languages).", $e->getMessage());
        }
    }

    public function test_a_missing_prompt_file_is_refused_rather_than_sent_empty(): void
    {
        unlink($this->prompts.'/rules.md');

        try {
            $this->prompt()->system(TranslationContext::Prose, TextFormat::Plain, 'de');
            $this->fail('A prompt was built without its rules.');
        } catch (RuntimeException $e) {
            $this->assertSame('The translation prompt rules.md cannot be read from '.$this->prompts.'.', $e->getMessage());
        }
    }

    // ── The prompts that ship (seam with resources/ and config/) ─────────────

    public function test_every_shipped_prompt_is_complete_for_every_target_locale(): void
    {
        $prompt = new TranslationPrompt(resource_path('prompts/translation'), config('i18n.translation_languages'), config('i18n.glossary'));
        $targets = array_values(array_diff(config('i18n.supported_locales'), ['fr']));

        $this->assertSame($targets, array_keys(config('i18n.translation_languages')), 'Every target locale, and only those, needs a language name.');

        foreach ($targets as $locale) {
            $language = config('i18n.translation_languages')[$locale];
            foreach (TranslationContext::cases() as $context) {
                foreach (TextFormat::cases() as $format) {
                    $system = $prompt->system($context, $format, $locale);

                    $this->assertStringNotContainsString('{{', $system, "A placeholder of the prompt is left in {$context->value}/{$format->value}/{$locale}.");
                    $this->assertStringContainsString(implode(', ', config('i18n.glossary')), $system);
                    // The first line names the language: tests read the locale back from it (FakesTranslationApi).
                    $this->assertStringContainsString(" into {$language}.\n", $system);
                }
            }
        }
    }
}
