<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use Tests\TestCase;

/**
 * The smoke probe for ACME renewal means something only because of one line in public/.htaccess.
 *
 * That file is never executed by this test suite — development runs on FrankenPHP, which routes
 * every request to index.php itself and never reads it. So the coupling between an Apache rewrite
 * and the meaning of a probe is invisible to everything: no test exercises it, no linter reads it,
 * and the probe keeps passing on the fake site either way.
 *
 * Remove the exclusion and AcmeChallengeProbe stops measuring authorisation and starts measuring
 * routing. It still goes red, which is the part that makes it dangerous: a red probe with a remedy
 * pointing at the hosting panel, for a defect that lives in this repository. That happened on
 * 2026-09-20 and cost a wrong diagnosis (LUMN-61).
 *
 * @see docs/deployment.md
 */
class AcmeChallengeRewriteTest extends TestCase
{
    public function test_the_front_controller_does_not_swallow_the_well_known_namespace(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));

        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{REQUEST_URI\}\s+!\^\/\\\\\.well-known\//',
            $htaccess,
            "public/.htaccess must keep /.well-known/ out of the front-controller rewrite.\n".
            "  → Without it, a missing challenge file becomes an internal redirect to /index.php,\n".
            "    which Apache re-evaluates against <Location \"/\"> — so the Basic auth exemption\n".
            "    stops applying and Let's Encrypt sees a 401 where it expects a 404.\n".
            '  → It also turns the ACME smoke probe into a test of routing. See its docblock.',
        );
    }

    public function test_the_exclusion_precedes_the_rewrite_it_guards(): void
    {
        // A RewriteCond only applies to the RewriteRule that follows it. Placed after the rule, or
        // after a blank line that starts another block, it silently guards nothing.
        $htaccess = (string) file_get_contents(public_path('.htaccess'));
        $exclusion = strpos($htaccess, '!^/\.well-known/');
        $frontController = strpos($htaccess, 'RewriteRule ^ index.php [L]');

        $this->assertIsInt($exclusion);
        $this->assertIsInt($frontController);
        $this->assertLessThan($frontController, $exclusion, 'The condition must come before the rule it guards.');
        $this->assertStringNotContainsString(
            "\n\n",
            substr($htaccess, $exclusion, $frontController - $exclusion),
            'A blank line between the condition and its rule breaks the association.',
        );
    }
}
