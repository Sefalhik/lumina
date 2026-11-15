<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * What `i18n:translate` and `cms:translate` share as commands: which locales a run targets, and
 * the one question asked before anything is spent (LUMN-110, LUMN-33). The work itself is in their
 * services; this is the conversation with whoever runs them.
 *
 * The using command declares the `--locale`, `--dry-run` and `--yes` options.
 */
trait TranslatesFromFrench
{
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

    /**
     * Asks once for the whole plan. Outside a terminal there is no one to ask: without `--yes`,
     * the run stops before writing — never the silent "no" that left 138 lines to undo on 2026-11-12.
     */
    private function consents(string $question, int $count): bool
    {
        if ($this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Not run: outside a terminal, accept the plan with --yes.');
            Log::warning('Translation command refused to run without consent', $this->logContext(__FUNCTION__, 'refused_without_consent', [
                'command' => $this->getName(),
                'count' => $count,
            ]));

            return false;
        }

        if ($this->confirm($question, false)) {
            return true;
        }

        $this->info('Nothing translated, nothing written.');

        return false;
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
