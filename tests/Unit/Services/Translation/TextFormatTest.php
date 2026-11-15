<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\TextFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextFormatTest extends TestCase
{
    public function test_each_format_with_a_syntax_has_its_prompt_and_prose_has_none(): void
    {
        $this->assertSame('formats/laravel.md', TextFormat::Laravel->promptFile());
        $this->assertSame('formats/vue-i18n.md', TextFormat::VueI18n->promptFile());
        $this->assertNull(TextFormat::Plain->promptFile());
    }

    /**
     * @return iterable<string, array{TextFormat, string, string}>
     */
    public static function kept(): iterable
    {
        yield 'Laravel, placeholder kept' => [TextFormat::Laravel, 'Le champ :attribute est requis.', 'The :attribute field is required.'];
        yield 'Laravel, placeholders moved' => [TextFormat::Laravel, ':attribute doit faire :max caractères', 'max :max characters for :attribute'];
        yield 'Laravel, a pipe the French had' => [TextFormat::Laravel, 'pomme|pommes', 'apple|apples'];
        yield 'Laravel, a time and an address are no placeholders' => [TextFormat::Laravel, 'Accueil', 'Home 10:30 https://example.org'];
        yield 'vue-i18n, placeholder kept' => [TextFormat::VueI18n, 'Thème actuel : {theme}', 'Current theme: {theme}'];
        yield 'vue-i18n, braces of a placeholder are no syntax added' => [TextFormat::VueI18n, 'Catégorie {n}, techno {t}', '{t} in category {n}'];
        yield 'plain, anything goes' => [TextFormat::Plain, 'Bio', 'Bio :attribute {x} @ $ | }'];
        yield 'Laravel, a placeholder glued to punctuation' => [TextFormat::Laravel, '(:attribute).', '«:attribute».'];
        yield 'Laravel, a mailto kept as written' => [TextFormat::Laravel, 'Écrire à mailto:contact', 'Write to mailto:contact'];
        yield 'Laravel, a mailto is no placeholder' => [TextFormat::Laravel, 'Écrire à mailto:contact', 'Write to us'];
        yield 'Laravel, a Finnish case ending glued to placeholders' => [TextFormat::Laravel, 'La valeur de :attribute doit être un multiple de :value.', ':attributen arvon on oltava :valuen monikerta.'];
        yield 'Laravel, the Finnish abbreviation n:o' => [TextFormat::Laravel, 'La catégorie n°:n doit avoir un nom.', 'Luokalla n:o :n on oltava nimi.'];
        yield 'Laravel, a placeholder capitalised at the start of a sentence' => [TextFormat::Laravel, 'Le champ :attribute est requis.', ':Attribute ist erforderlich.'];
        yield 'Laravel, a placeholder in capitals' => [TextFormat::Laravel, 'Attention : :attribute', 'Warning: :ATTRIBUTE'];
        yield 'Laravel, :values and :value told apart' => [TextFormat::Laravel, ':values ou :value', ':value oder :values'];
        yield 'Laravel, a capitalised placeholder in the French' => [TextFormat::Laravel, ':Attribute est requis.', ':Attribute ist erforderlich.'];
        yield 'Laravel, a capitalised French placeholder in lower case' => [TextFormat::Laravel, ':Attribute est requis.', 'Vaaditaan :attribute.'];
        yield 'Laravel, a Swedish ordinal is no placeholder' => [TextFormat::Laravel, 'Le 1er du mois', 'Den 1:a i månaden'];
        yield 'Laravel, a placeholder with an underscore' => [TextFormat::Laravel, 'Valeur de :other_field', ':other_field value'];
        yield 'empty on both sides' => [TextFormat::VueI18n, '', ''];
    }

    #[DataProvider('kept')]
    public function test_a_translation_that_keeps_the_format_is_accepted(TextFormat $format, string $source, string $translation): void
    {
        $this->assertTrue($format->keeps($source, $translation));
    }

    /**
     * @return iterable<string, array{TextFormat, string, string}>
     */
    public static function broken(): iterable
    {
        yield 'Laravel, placeholder lost' => [TextFormat::Laravel, 'Profil :network', 'Profile'];
        yield 'Laravel, placeholder added' => [TextFormat::Laravel, 'Profil :network', 'Profile :network :email'];
        yield 'Laravel, placeholder translated' => [TextFormat::Laravel, 'Le champ :attribute', 'Das Feld :attribut'];
        yield 'Laravel, a capitalised placeholder lost' => [TextFormat::Laravel, ':Attribute est requis.', 'Erforderlich.'];
        yield 'Laravel, placeholder doubled' => [TextFormat::Laravel, 'Envoyer à :email', ':email, :email'];
        yield 'Laravel, a pipe written' => [TextFormat::Laravel, 'Pluie et néons', 'Rain | neon'];
        yield 'Laravel, one pipe more than the French' => [TextFormat::Laravel, 'pomme|pommes', 'apple|apples|applen'];
        yield 'vue-i18n, placeholder lost' => [TextFormat::VueI18n, 'Thème actuel : {theme}', 'Current theme'];
        yield 'vue-i18n, placeholder translated' => [TextFormat::VueI18n, 'Thème actuel : {theme}', 'Aktuelles Thema: {Thema}'];
        yield 'vue-i18n, an opening brace' => [TextFormat::VueI18n, 'Filtrer…', 'Filter {…'];
        yield 'vue-i18n, a closing brace' => [TextFormat::VueI18n, 'Filtrer…', 'Filter …}'];
        yield 'vue-i18n, a linked-message sign' => [TextFormat::VueI18n, 'Envoyer', 'Send @ once'];
        yield 'vue-i18n, a dollar sign' => [TextFormat::VueI18n, 'Prix', 'Price in $'];
        yield 'vue-i18n, a plural separator' => [TextFormat::VueI18n, 'Pluie & néons', 'Rain | neon'];
        yield 'vue-i18n, spaces inside a placeholder' => [TextFormat::VueI18n, 'Thème : {theme}', 'Theme: { theme }'];
        yield 'vue-i18n, a placeholder swapped for another' => [TextFormat::VueI18n, 'Catégorie {n}', 'Category {t}'];
        yield 'Laravel, a placeholder cut short' => [TextFormat::Laravel, 'Valeur de :other_field', 'Wert von :other'];
        yield 'Laravel, a placeholder doubled by a garbled sentence' => [TextFormat::Laravel, 'Le champ :attribute doit être une date postérieure ou égale au :date.', 'Das Feld :attribute muss ein Datum nach dem :date oder dem :date entsprechen.'];
        yield 'Laravel, a casing Laravel does not replace' => [TextFormat::Laravel, 'Le champ :attribute', 'Das Feld :aTTribute'];
        yield 'Laravel, :value where the French had :values' => [TextFormat::Laravel, 'Parmi :values', 'Among :value'];
        yield 'Laravel, a placeholder the French does not have' => [TextFormat::Laravel, 'Cette URL', 'Diese URL zeigt auf :host'];
    }

    #[DataProvider('broken')]
    public function test_a_translation_that_breaks_the_format_is_refused(TextFormat $format, string $source, string $translation): void
    {
        $this->assertFalse($format->keeps($source, $translation));
    }
}
