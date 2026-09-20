<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use Tests\TestCase;

/**
 * Every variable `.env.example` declares has to be documented somewhere a reader will look.
 *
 * `.env.example` says **which** variables exist; `docs/environment-variables.md` says what each one
 * is for and — the part that actually matters on a server — where its value comes from, or that it
 * deliberately has no value there.
 *
 * LUMN-49 shipped three `SMOKE_*` variables documented in neither, and the gap surfaced only when
 * somebody asked out loud whether they belonged in the server's `.env`. They do not: the command
 * that reads them runs from outside, and putting Basic credentials on the host they protect is the
 * opposite of the intent. That question deserved an answer in a file, not in a conversation.
 *
 * @see docs/environment-variables.md
 */
class EnvironmentVariablesTest extends TestCase
{
    public function test_every_variable_of_env_example_is_documented(): void
    {
        $documentation = (string) file_get_contents(base_path('docs/environment-variables.md'));
        $undocumented = [];

        foreach ($this->declaredVariables() as $variable) {
            // Bounded on both sides: a plain substring search calls APP_NAME documented because
            // VITE_APP_NAME is mentioned. Underscores are word characters, so \b will not do it.
            if (preg_match('/(?<![A-Z0-9_])'.preg_quote($variable, '/').'(?![A-Z0-9_])/', $documentation) !== 1) {
                $undocumented[] = $variable;
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            "These variables exist in .env.example and are explained nowhere.\n".
            "  → Add them to docs/environment-variables.md, in the group they come from — or to its\n".
            "    \"Deliberately absent from preprod and production\" table when they must never have\n".
            "    a value on a server, which is a decision worth writing down rather than assuming.",
        );
    }

    public function test_env_example_declares_no_value_for_a_credential(): void
    {
        // A committed example file is read by everyone who clones. A placeholder password is how a
        // placeholder password ends up on a server.
        $secrets = [];

        foreach ($this->declaredVariables(withValues: true) as $variable => $value) {
            // `null` is how Laravel's own example file writes "no value", and env() casts it.
            if (preg_match('/PASSWORD|SECRET|_KEY$|TOKEN/', $variable) === 1 && ! in_array($value, ['', 'null'], true)) {
                $secrets[] = $variable;
            }
        }

        $this->assertSame([], $secrets, 'A credential in .env.example carries a value.');
    }

    /**
     * @return array<string, string>|list<string>
     */
    private function declaredVariables(bool $withValues = false): array
    {
        $contents = (string) file_get_contents(base_path('.env.example'));
        preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', $contents, $matches, PREG_SET_ORDER);

        $variables = [];
        foreach ($matches as $match) {
            $variables[$match[1]] = trim($match[2], " \t\"'");
        }

        return $withValues ? $variables : array_keys($variables);
    }
}
