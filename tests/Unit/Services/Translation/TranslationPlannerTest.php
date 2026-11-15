<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Translation;

use App\Services\Translation\TranslationPlanner;
use App\Services\Translation\TranslationSources;
use PHPUnit\Framework\TestCase;

/**
 * Which keys a run translates (LUMN-110): those that need it, and never one that does not.
 *
 * No case here is about time: nothing the planner reads carries a date, so the defect of the
 * 180-day expiry — a validated label retranslated because it was old — has nowhere to come back.
 */
class TranslationPlannerTest extends TestCase
{
    private TranslationPlanner $planner;

    /** @var array<string, string> */
    private array $french = ['home' => 'Accueil', 'cv' => 'CV', 'blog' => 'Blog'];

    protected function setUp(): void
    {
        $this->planner = new TranslationPlanner;
    }

    /**
     * @return array<string, string>
     */
    private function recorded(): array
    {
        return array_map(TranslationSources::fingerprint(...), $this->french);
    }

    // ── What is never translated ─────────────────────────────────────────────

    public function test_a_complete_target_on_an_unchanged_source_needs_nothing(): void
    {
        $target = ['home' => 'Startseite', 'cv' => 'Lebenslauf', 'blog' => 'Blog'];

        $this->assertSame([], $this->planner->keysToTranslate($this->french, $this->recorded(), $target, [], false));
    }

    public function test_a_translation_with_no_recorded_fingerprint_is_adopted(): void
    {
        $target = ['home' => 'Startseite', 'cv' => 'Lebenslauf', 'blog' => 'Blog'];

        $this->assertSame([], $this->planner->keysToTranslate($this->french, [], $target, [], false));
    }

    public function test_a_hand_corrected_translation_on_an_unchanged_source_is_kept(): void
    {
        $target = ['home' => 'Zur Startseite (von Hand)', 'cv' => 'Lebenslauf', 'blog' => 'Blog'];

        $this->assertSame([], $this->planner->keysToTranslate($this->french, $this->recorded(), $target, [], false));
    }

    // ── What is translated ───────────────────────────────────────────────────

    public function test_a_key_missing_from_the_target_is_translated(): void
    {
        $target = ['home' => 'Startseite', 'blog' => 'Blog'];

        $this->assertSame(['cv'], $this->planner->keysToTranslate($this->french, $this->recorded(), $target, [], false));
    }

    public function test_an_empty_translation_counts_as_missing(): void
    {
        $target = ['home' => 'Startseite', 'cv' => '', 'blog' => 'Blog'];

        $this->assertSame(['cv'], $this->planner->keysToTranslate($this->french, $this->recorded(), $target, [], false));
    }

    public function test_a_key_whose_french_changed_is_translated(): void
    {
        $recorded = $this->recorded();
        $recorded['cv'] = TranslationSources::fingerprint('Curriculum');
        $target = ['home' => 'Startseite', 'cv' => 'Curriculum', 'blog' => 'Blog'];

        $this->assertSame(['cv'], $this->planner->keysToTranslate($this->french, $recorded, $target, [], false));
    }

    public function test_a_key_the_locale_still_owes_is_translated(): void
    {
        $target = ['home' => 'Startseite', 'cv' => 'Lebenslauf', 'blog' => 'Blog'];

        $this->assertSame(['blog'], $this->planner->keysToTranslate($this->french, $this->recorded(), $target, ['blog'], false));
    }

    public function test_force_translates_every_key(): void
    {
        $target = ['home' => 'Startseite', 'cv' => 'Lebenslauf', 'blog' => 'Blog'];

        $this->assertSame(['home', 'cv', 'blog'], $this->planner->keysToTranslate($this->french, $this->recorded(), $target, [], true));
    }

    public function test_keys_come_in_source_order_whatever_the_reason(): void
    {
        $recorded = $this->recorded();
        $recorded['blog'] = TranslationSources::fingerprint('ancien');
        $target = ['cv' => 'Lebenslauf', 'blog' => 'Blog'];

        $this->assertSame(['home', 'blog'], $this->planner->keysToTranslate($this->french, $recorded, $target, [], false));
    }

    public function test_an_empty_target_needs_every_key(): void
    {
        $this->assertSame(['home', 'cv', 'blog'], $this->planner->keysToTranslate($this->french, [], [], [], false));
    }

    // ── Keys nobody expects ──────────────────────────────────────────────────

    public function test_numeric_keys_come_back_as_strings(): void
    {
        // PHP turns '404' into an integer key: the planner must neither choke on it nor leak it.
        $this->assertSame(['404'], $this->planner->keysToTranslate(['404' => 'Introuvable'], [], [], [], false));
        $this->assertSame(['500'], $this->planner->obsoleteKeys([], ['500' => 'Fehler']));
    }

    public function test_an_empty_french_value_missing_from_the_target_is_planned(): void
    {
        $this->assertSame(['blank'], $this->planner->keysToTranslate(['blank' => ''], [], [], [], false));
    }

    public function test_an_empty_french_value_held_empty_by_the_target_is_done(): void
    {
        $this->assertSame([], $this->planner->keysToTranslate(['blank' => ''], [], ['blank' => ''], [], false));
    }

    public function test_an_empty_translation_of_a_non_empty_french_value_is_not_done(): void
    {
        $this->assertSame(['cv'], $this->planner->keysToTranslate(['cv' => 'CV'], [], ['cv' => ''], [], false));
    }

    // ── Obsolete keys ────────────────────────────────────────────────────────

    public function test_a_key_the_french_no_longer_has_is_obsolete(): void
    {
        $target = ['home' => 'Startseite', 'cv' => 'Lebenslauf', 'blog' => 'Blog', 'projects' => 'Projekte'];

        $this->assertSame(['projects'], $this->planner->obsoleteKeys($this->french, $target));
    }

    public function test_a_target_matching_the_french_has_nothing_obsolete(): void
    {
        $this->assertSame([], $this->planner->obsoleteKeys($this->french, ['home' => 'Startseite']));
    }
}
