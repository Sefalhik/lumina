<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TranslatesFromFrench;
use App\Services\Translation\LangFileTranslation;
use App\Services\Translation\TranslationPlan;
use App\Services\Translation\TranslationTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Translates `lang/fr/*.php` and `resources/js/i18n/fr.json` into the other locales — the keys
 * that need it and no others (LUMN-110). The work is {@see LangFileTranslation}'s; this command
 * shows the plan, what it will cost on the interface model, and asks before spending anything.
 */
class I18nTranslate extends Command
{
    use TranslatesFromFrench;

    protected $signature = 'i18n:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Translate every key again, not only those that need it}
                            {--yes     : Accept the plan without asking — required outside a terminal}
                            {--dry-run : Show the plan and write nothing}';

    protected $description = 'Translate i18n files from French (source of truth) to EU locales using the Anthropic API';

    public function __construct(
        private readonly LangFileTranslation $translation,
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

        if ($plan->needsTranslation() && ! $this->consents('Translate these keys?', $plan->keyCount())) {
            return $this->input->isInteractive() ? self::SUCCESS : self::FAILURE;
        }

        Log::info('i18n:translate started', $this->logContext(__FUNCTION__, 'start', ['target_locales' => $locales, 'key_count' => $plan->keyCount()]));

        $succeeded = $this->translation->apply($plan, $this->allTargetLocales(), $this->showTask(...));

        $this->newLine();
        $succeeded ? $this->info('All translations completed.') : $this->error('Completed with errors: what failed is left to translate.');
        Log::info('i18n:translate completed', $this->logContext(__FUNCTION__, 'complete', ['success' => $succeeded]));

        return $succeeded ? self::SUCCESS : self::FAILURE;
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

        $this->newLine();
        $this->info(sprintf('%d keys in %d files: ', $plan->keyCount(), count($plan->tasks)).$this->translation->estimate($plan)->describe($this->translation->model()));
    }

    private function showTask(TranslationTask $task, bool $done): void
    {
        $label = $task->source->label($task->locale);
        $done ? $this->line("  <fg=green>✓</> {$label}") : $this->error("  ✗ {$label} — translation failed, left to translate");
    }
}
