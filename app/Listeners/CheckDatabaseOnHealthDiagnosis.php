<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * Makes /up prove more than "Laravel boots": it now proves the database answers.
 *
 * Laravel dispatches DiagnosingHealth while serving /up, and turns any exception thrown by a
 * listener into a 500. Without this, /up answered 200 with the database down — a health check that
 * could not fail on the most likely failure of a deployment.
 */
class CheckDatabaseOnHealthDiagnosis
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->select('select 1');
    }
}
