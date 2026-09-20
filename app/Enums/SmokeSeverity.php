<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much a failed smoke check matters.
 *
 * A blocking failure means the deployment is broken and must not be promoted; a warning means it
 * works, but something needs attention soon — a certificate close to expiry, a slow page.
 */
enum SmokeSeverity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';
}
