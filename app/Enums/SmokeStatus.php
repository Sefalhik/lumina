<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of one smoke check.
 *
 * Skipped is not a pass: it says the check could not apply to this target — no expected release
 * given, a plain-HTTP target with no certificate — and the report shows why.
 */
enum SmokeStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
