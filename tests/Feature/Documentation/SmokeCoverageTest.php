<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use App\Services\Smoke\SmokeCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The smoke suite probes what the deployment does today. This fails the day it does more.
 *
 * Nothing sends mail, queues a job on a broker or writes a file yet, so none of those is probed —
 * deliberately, because probing a transport nothing uses proves nothing. The risk is not the gap,
 * it is the day the gap closes and `docs/smoke-tests.md` is never reopened: the capability ships,
 * the deployment gains a way to fail, and the report stays green.
 *
 * So each capability is paired with the probe it will need, and the pairing is checked from the
 * codebase rather than from memory. Adding `app/Mail/` fails this test, naming the probe to write.
 *
 * **Its limit, stated rather than discovered.** It only sees capabilities that leave a mechanical
 * trace — a directory, a committed environment value, a call. "We now depend on someone else's API"
 * leaves none: that one stays a question to ask, and the three questions to ask it with are in
 * `docs/smoke-tests.md` (§ When a new capability needs a probe).
 *
 * @see docs/smoke-tests.md
 */
class SmokeCoverageTest extends TestCase
{
    /**
     * Each capability: how the codebase betrays it, the probe id it then requires, and what that
     * probe must read. The third column is the part worth writing down — it is the reasoning that
     * will otherwise be redone from scratch, badly, under time pressure.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function capabilities(): iterable
    {
        yield 'outgoing mail' => [
            'mail',
            'Probe that the transport is reachable and authenticated — never by sending to a real address.',
        ];

        yield 'a queue on a broker' => [
            'queue',
            'Probe that the broker answers AND that a worker is consuming: a filling queue with no consumer is the silent failure.',
        ];

        yield 'file storage' => [
            'storage',
            'Probe that public/storage resolves — storage:link is the classic forgotten deployment step.',
        ];
    }

    /**
     * @param  string  $probeId  the probe the catalogue must carry once the capability exists
     * @param  string  $what  what that probe has to read, decided before it was needed
     */
    #[DataProvider('capabilities')]
    public function test_a_capability_that_exists_is_probed(string $probeId, string $what): void
    {
        $capability = $this->dataName();

        if (! $this->isPresent($probeId)) {
            $this->assertNotContains($probeId, $this->probeIds(), "There is a '{$probeId}' probe but nothing in the codebase uses that capability. Remove the probe, or fix the detection in ".self::class.'.');
            $this->markTestSkipped("The deployment does not use {$capability} yet.");
        }

        $this->assertContains(
            $probeId,
            $this->probeIds(),
            "The deployment now uses {$capability}, and no smoke probe watches it.\n".
            "  → Add a '{$probeId}' probe to app/Services/Smoke/Probes/ and list it in SmokeCatalogue.\n".
            "  → {$what}\n".
            '  → The reasoning is in docs/smoke-tests.md, section "When a new capability needs a probe".',
        );
    }

    /**
     * Read the committed description of a deployment, never the running config: phpunit.xml
     * overrides drivers on purpose, and this test asks what the *servers* are told to use.
     */
    private function isPresent(string $capability): bool
    {
        return match ($capability) {
            'mail' => is_dir(base_path('app/Mail'))
                || $this->appMatches('/Mail::(to|send|queue|mailer)\(/'),
            'queue' => ! in_array($this->envExample('QUEUE_CONNECTION'), ['sync', '', null], true)
                || is_dir(base_path('app/Jobs')),
            'storage' => $this->appMatches('/Storage::|->storeAs?\(/')
                || ! in_array($this->envExample('FILESYSTEM_DISK'), ['local', '', null], true),
            default => false,
        };
    }

    private function envExample(string $key): ?string
    {
        $contents = (string) file_get_contents(base_path('.env.example'));

        return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $match) === 1
            ? trim($match[1], " \t\"'")
            : null;
    }

    private function appMatches(string $pattern): bool
    {
        // Recursive on purpose: glob('app/**/*.php') stops at one nested level, and app/Services/
        // is already three deep — a detection that silently sees a third of the code is worse than
        // none, because it reports a capability as absent.
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php'
                && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function probeIds(): array
    {
        return array_map(
            fn (string $probe): string => $this->app->make($probe)->id(),
            SmokeCatalogue::PROBES,
        );
    }
}
