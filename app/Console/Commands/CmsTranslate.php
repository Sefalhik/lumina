<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TranslatesFromFrench;
use App\Services\Translation\CmsPlan;
use App\Services\Translation\CmsTask;
use App\Services\Translation\CmsTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Translates the French of every CMS record into the other locales — the fields that need it and
 * no others. The work is {@see CmsTranslation}'s; this command shows the plan, what it will cost on
 * the prose model, and asks before spending anything (LUMN-33).
 */
class CmsTranslate extends Command
{
    use TranslatesFromFrench;

    protected $signature = 'cms:translate
                            {--locale= : Target locale (default: all non-French EU locales)}
                            {--force   : Translate every field again, not only those that need it}
                            {--yes     : Accept the plan without asking — required outside a terminal}
                            {--dry-run : Show what would be translated without writing to DB}';

    protected $description = 'Translate CMS content from French (source of truth) to EU locales using the Anthropic API';

    public function __construct(
        private readonly CmsTranslation $translation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $locales = $this->targetLocales();
        if ($locales === null || $locales === []) {
            return $locales === null ? self::FAILURE : self::SUCCESS;
        }

        /** @var list<string> $cmsModels */
        $cmsModels = config('i18n.cms_models', []);
        if ($cmsModels === []) {
            $this->warn('No CMS models registered in config/i18n.php (cms_models).');

            return self::SUCCESS;
        }

        $this->info('Target locales : <fg=cyan>'.implode(', ', $locales).'</>');
        $plan = $this->translation->plan($cmsModels, $locales, (bool) $this->option('force'));
        $this->showPlan($plan);

        if ($this->option('dry-run')) {
            return $plan->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        if ($plan->needsTranslation() && ! $this->consents('Translate these fields?', $plan->fieldCount())) {
            return $this->input->isInteractive() ? self::SUCCESS : self::FAILURE;
        }

        Log::info('cms:translate started', $this->logContext(__FUNCTION__, 'start', ['target_locales' => $locales, 'model_count' => count($cmsModels)]));

        $succeeded = $this->translation->apply($plan, $this->allTargetLocales(), $this->showTask(...));

        $this->newLine();
        $succeeded ? $this->info('All CMS translations completed.') : $this->error('Completed with errors: what failed is left to translate.');
        Log::info('cms:translate completed', $this->logContext(__FUNCTION__, 'complete', ['success' => $succeeded]));

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function showPlan(CmsPlan $plan): void
    {
        foreach ($plan->errors as $error) {
            $this->error($error);
        }

        foreach ($plan->emptyModels as $model) {
            $this->line("  <fg=cyan>{$model}</> — no records found, skipping");
        }

        foreach ($plan->sources as $source) {
            $this->line('  <fg=yellow>'.$source->label().'</>');
            foreach ($plan->locales as $locale) {
                $this->line("    → <fg=green>{$locale}</> ".$plan->fieldsFor($source, $locale).' to translate');
            }
        }

        if ($plan->needsTranslation()) {
            $this->newLine();
            $this->info(sprintf('%d fields in %d records: ', $plan->fieldCount(), count($plan->sources)).$this->translation->estimate($plan)->describe($this->translation->model()));
        }
    }

    private function showTask(CmsTask $task, bool $done): void
    {
        if (! $done) {
            $this->error("      Translation failed for {$task->source->label()}, locale {$task->locale}");
        }
    }
}
