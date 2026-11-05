<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * /up has no rate limit, by decision (LUMN-92): it is the cheapest route of the site in database
 * queries, so a ceiling on it alone would protect nothing a loop on a page does not reach — see
 * docs/deployment.md, "What protects the site from a flood of requests".
 *
 * That decision holds only while /up stays this cheap. Put it in the `web` group and it reads and
 * writes a session; give it a limiter counting in the default cache and it reads and writes that
 * too. Either would fail here, and the decision would have to be taken again.
 */
class HealthRouteCostTest extends TestCase
{
    public function test_up_costs_one_query_with_the_drivers_production_runs(): void
    {
        // phpunit.xml keeps array drivers on purpose; production stores both in the database.
        config(['session.driver' => 'database', 'cache.default' => 'database']);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get('/up')->assertOk();

        $this->assertSame(['select 1'], $queries);
    }

    public function test_up_never_refuses_a_caller_who_polls_it(): void
    {
        // The test above sees a limiter only when it counts in the database. One counting in a
        // file or in memory costs no query, and would still answer 429 to the supervision that
        // polls /up continuously (LUMN-60). A hundred calls is well beyond the 60 a minute LUMN-92
        // had planned and far above any poller; a ceiling higher than that passes, and refuses
        // nobody who matters.
        for ($call = 1; $call <= 100; $call++) {
            $this->assertSame(200, $this->get('/up')->getStatusCode(), "Call {$call} to /up was refused.");
        }
    }
}
