<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Testing\Concerns;

use Illuminate\Testing\PendingCommand;

/**
 * PHPStan stub — artisan() always returns PendingCommand in tests.
 * The framework annotation says PendingCommand|int but the implementation
 * always wraps in PendingCommand before returning.
 */
trait InteractsWithConsole
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function artisan(string $command, array $parameters = []): PendingCommand {}
}
