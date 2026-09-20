<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /up used to prove only that Laravel boots: it answered 200 with the database down, which is the
 * most likely failure of a deployment. The smoke test reads it first (LUMN-49).
 */
class HealthDatabaseTest extends TestCase
{
    public function test_up_answers_200_when_the_database_answers(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_failure_page_does_not_leak_the_connection_details(): void
    {
        // /up is public and unauthenticated. With APP_DEBUG off — the only setting a server runs
        // with — its failure must say nothing about the host, the port or the database name.
        config([
            'app.debug' => false,
            'database.connections.unreachable' => [...(array) config('database.connections.pgsql'), 'port' => 1],
            'database.default' => 'unreachable',
        ]);
        DB::purge('unreachable');

        $body = (string) $this->get('/up')->assertStatus(500)->getContent();

        foreach (['SQLSTATE', 'pgsql', 'password', (string) config('database.connections.pgsql.database')] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "The failure page leaks {$secret}.");
        }
    }

    public function test_up_answers_500_when_the_database_is_unreachable(): void
    {
        config([
            'database.connections.unreachable' => [...(array) config('database.connections.pgsql'), 'port' => 1],
            'database.default' => 'unreachable',
        ]);
        DB::purge('unreachable');

        $this->get('/up')->assertStatus(500);
    }
}
