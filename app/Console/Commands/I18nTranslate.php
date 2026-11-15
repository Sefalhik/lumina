<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AnthropicTranslator;
use App\Services\Translation\LangFileTranslation;
use App\Services\Translation\TranslationPlan;
use App\Services\Translation\TranslationTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Translates `lang/fr/*.php` and `resources/js/i18n/fr.json` into the other locales — the keys
 * that need it and no others (LUMN-110). The work is {@see LangFileTranslation}'s; this command
 * shows the plan, what it will cost, and asks before spending anything.
 */
class I18nTranslate extends Command
{
    protected $signature = 'i18n:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Translate every key again, not only those that need it}
                            {--yes     : Accept the plan without asking — required outside a terminal}
                            {--dry-run : Show the plan and write nothing}';

    protected $description = 'Translate i18n files from French (source of truth) to EU locales using the Anthropic API';

    public function __construct(
        private readonly LangFileTranslation $translation,
        private readonly AnthropicTranslator $translator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $locales = $this->targetLocales();
        if ($locales === null || $locales === []) {
            return $locales === null ? self::FAILURE : self::SUCCESS;
        }

        $plan = $this->translation->plan($locales, (bool) $this->option('force'));
        $this->showPlan($plan);

        if ($this->option('dry-run')) {
            return $plan->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        if ($plan->needsTranslation() && ! $this->consents($plan)) {
            return $this->input->isInteractive() ? self::SUCCESS : self::FAILURE;
        }

        Log::info('i18n:translate started', $this->logContext(__FUNCTION__, 'start', ['target_locales' => $locales, 'key_count' => $plan->keyCount()]));

        $succeeded = $this->translation->apply($plan, $this->allTargetLocales(), $this->showTask(...));

        $this->newLine();
        $succeeded ? $this->info('All translations completed.') : $this->error('Completed with errors: what failed is left to translate.');
        Log::info('i18n:translate completed', $this->logContext(__FUNCTION__, 'complete', ['success' => $succeeded]));

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>|null null when the run must not go further
     */
    private function targetLocales(): ?array
    {
        if ((string) config('services.anthropic.api_key', '') === '' && ! $this->option('dry-run')) {
            $this->error('ANTHROPIC_API_KEY is not set. Add it to your .env file.');

            return null;
        }

        $requested = $this->option('locale');
        if ($requested === null) {
            return $this->allTargetLocales();
        }

        if ($requested === 'fr') {
            $this->warn('French is the source of truth — nothing to translate.');

            return [];
        }

        if (! in_array($requested, $this->allTargetLocales(), true)) {
            $this->error("Locale '{$requested}' is not in the supported locales list.");

            return null;
        }

        return [$requested];
    }

    /**
     * @return list<string>
     */
    private function allTargetLocales(): array
    {
        /** @var list<string> $supported */
        $supported = config('i18n.supported_locales', []);

        return array_values(array_diff($supported, ['fr']));
    }

    private function showPlan(TranslationPlan $plan): void
    {
        foreach ($plan->errors as $error) {
            $this->error("Unreadable: {$error}");
        }

        if ($plan->tasks === []) {
            $this->info('Nothing to translate: every target is up to date.');

            return;
        }

        foreach ($plan->tasks as $task) {
            $this->line(sprintf('  %s — %d to translate, %d to remove', $task->source->label($task->locale), count($task->keys), count($task->obsolete)));
        }

        $estimate = $this->estimate($plan);

        $this->newLine();
        $this->info(sprintf(
            '%d keys in %d files: %d API requests to %s, about %d input and %d output tokens.',
            $plan->keyCount(),
            count($plan->tasks),
            $estimate['requests'],
            $this->translator->model(),
            $estimate['input_tokens'],
            $estimate['output_tokens'],
        ));
    }

    /**
     * @return array{requests: int, input_tokens: int, output_tokens: int}
     */
    private function estimate(TranslationPlan $plan): array
    {
        $total = ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0];

        foreach ($plan->tasks as $task) {
            foreach ($this->translator->estimate($task->strings()) as $metric => $value) {
                $total[$metric] += $value;
            }
        }

        return $total;
    }

    /**
     * Asks once for the whole plan. Outside a terminal there is no one to ask: without `--yes`,
     * the run stops before writing — never the silent "no" that left 138 lines to undo on 2026-11-12.
     */
    private function consents(TranslationPlan $plan): bool
    {
        if ($this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Not run: outside a terminal, accept the plan with --yes.');
            Log::warning('i18n:translate refused to run without consent', $this->logContext(__FUNCTION__, 'refused_without_consent', ['key_count' => $plan->keyCount()]));

            return false;
        }

        if ($this->confirm('Translate these keys?', false)) {
            return true;
        }

        $this->info('Nothing translated, nothing written.');

        return false;
    }

    private function showTask(TranslationTask $task, bool $done): void
    {
        $label = $task->source->label($task->locale);
        $done ? $this->line("  <fg=green>✓</> {$label}") : $this->error("  ✗ {$label} — translation failed, left to translate");
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(string $method, string $step, array $extra): array
    {
        return ['service' => self::class, 'method' => $method, 'step' => $step] + $extra;
    }
}
