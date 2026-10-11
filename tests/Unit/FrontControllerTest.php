<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * public/index.php itself, run for real in a site of two releases.
 *
 * App\Services\ReleaseInService decides whose front controller answers; this file proves that the
 * front controller asks, and does what it is told. It is the part a framework upgrade would
 * overwrite without a word, and the part no request of the test suite ever goes through: feature
 * tests enter by the kernel.
 *
 * Each release holds a copy of the real public/index.php and of the real class, and just enough
 * around them to answer: an autoloader and an application that say which release they belong to.
 */
class FrontControllerTest extends TestCase
{
    private const string A = '20261011-080000-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string B = '20261011-090000-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = sys_get_temp_dir().'/lumina-front-'.uniqid();
        $this->release(self::A);
        $this->release(self::B);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->site, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->site);
        parent::tearDown();
    }

    public function test_the_release_in_service_answers_its_own_requests(): void
    {
        symlink('releases/'.self::A, $this->site.'/current');

        $this->assertSame(['answered by '.self::A], $this->request(self::A));
    }

    public function test_a_release_that_was_replaced_hands_the_request_to_the_one_in_service(): void
    {
        // What OPcache does after a switch: it goes on running the front controller of A, by the
        // path of the link. The page has to come from B all the same — code, autoloader and all —
        // once PHP has been made to forget A.
        symlink('releases/'.self::B, $this->site.'/current');

        $this->assertSame(
            ['paths forgotten: true', 'code cache reset', 'answered by '.self::B],
            $this->request(self::A),
        );
    }

    public function test_a_request_is_handed_over_once_and_not_back(): void
    {
        // The front controller that receives the request asks the same question, with the class
        // the first one already loaded, and finds that it is the one in service.
        symlink('releases/'.self::B, $this->site.'/current');

        $this->assertSame('answered by '.self::B, $this->request(self::A)[2]);
        $this->assertSame(['answered by '.self::B], $this->request(self::B));
    }

    public function test_the_request_is_handed_over_even_when_the_code_cache_refuses_to_be_reset(): void
    {
        // A host may forbid the reset, and PHP answers false. The page comes from the release in
        // service all the same: forgetting spares the next requests the detour, it does not make
        // this one right.
        symlink('releases/'.self::B, $this->site.'/current');

        $this->assertSame(
            ['paths forgotten: true', 'code cache reset refused', 'answered by '.self::B],
            $this->request(self::A, resetAnswers: false),
        );
    }

    public function test_a_request_can_be_handed_to_a_release_from_before_this_mechanism(): void
    {
        // A rollback towards a release whose front controller asks nothing: it has to answer.
        file_put_contents(
            $this->site.'/releases/'.self::B.'/public/index.php',
            "<?php\n\necho \"answered by the plain front controller of ".self::B."\\n\";\n",
        );
        symlink('releases/'.self::B, $this->site.'/current');

        $this->assertSame(
            'answered by the plain front controller of '.self::B,
            $this->request(self::A)[2],
        );
    }

    public function test_a_checkout_with_no_site_around_it_answers_as_it_always_did(): void
    {
        // A developer machine: no releases/, no link.
        $checkout = $this->site.'/checkout';
        $this->files($checkout, 'a developer machine');

        $this->assertSame(['answered by a developer machine'], $this->execute($checkout));
    }

    public function test_it_asks_before_anything_of_the_release_is_loaded(): void
    {
        // Handed over after the autoloader, a request would be answered by the classes of one
        // release and the configuration of another.
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/public/index.php');

        $handsOver = strpos($source, 'ReleaseInService::frontController(');
        $this->assertNotFalse($handsOver, 'public/index.php no longer asks which release is in service.');
        $this->assertLessThan(strpos($source, "define('LARAVEL_START'"), $handsOver);
        $this->assertLessThan(strpos($source, 'vendor/autoload.php'), $handsOver);
        $this->assertLessThan(strpos($source, 'maintenance.php'), $handsOver);
    }

    /** @return list<string> what the request printed, line by line */
    private function request(string $release, bool $resetAnswers = true): array
    {
        return $this->execute($this->site.'/releases/'.$release, $resetAnswers);
    }

    /**
     * Runs the front controller of a release as PHP would for a request.
     *
     * The command line has no code cache to reset, so the two functions that make PHP forget are
     * replaced by ones that say they were called: declared in the namespace of the class, they
     * are found before the real ones.
     *
     * @return list<string>
     */
    private function execute(string $directory, bool $resetAnswers = true): array
    {
        $witnesses = $this->site.'/witnesses.php';
        file_put_contents($witnesses, <<<'PHP'
            <?php

            namespace App\Services;

            function clearstatcache(bool $clearRealpathCache = false): void
            {
                echo 'paths forgotten: ', var_export($clearRealpathCache, true), "\n";
            }

            function opcache_reset(): bool
            {
                $answer = getenv('WITNESS_RESET_ANSWERS') !== 'false';
                echo $answer ? "code cache reset\n" : "code cache reset refused\n";

                return $answer;
            }
            PHP);

        exec(
            'WITNESS_RESET_ANSWERS='.($resetAnswers ? 'true' : 'false').' '
                .escapeshellarg(PHP_BINARY).' -d auto_prepend_file='.escapeshellarg($witnesses).' '
                .escapeshellarg($directory.'/public/index.php').' 2>&1',
            $output,
            $status,
        );

        $this->assertSame(0, $status, implode("\n", $output));

        return $output;
    }

    private function release(string $name): void
    {
        $this->files($this->site.'/releases/'.$name, $name);
    }

    private function files(string $directory, string $name): void
    {
        $root = dirname(__DIR__, 2);
        mkdir($directory.'/public', recursive: true);
        mkdir($directory.'/app/Services', recursive: true);
        mkdir($directory.'/vendor');
        mkdir($directory.'/bootstrap');
        copy($root.'/public/index.php', $directory.'/public/index.php');
        copy($root.'/app/Services/ReleaseInService.php', $directory.'/app/Services/ReleaseInService.php');

        // The two things the front controller needs from the framework: a request to capture, and
        // an application to hand it to.
        file_put_contents($directory.'/vendor/autoload.php', <<<'PHP'
            <?php

            namespace Illuminate\Http;

            final class Request
            {
                public static function capture(): self
                {
                    return new self();
                }
            }
            PHP);
        file_put_contents($directory.'/bootstrap/app.php', sprintf(<<<'PHP'
            <?php

            return new class
            {
                public function handleRequest(object $request): void
                {
                    echo 'answered by %s', "\n";
                }
            };
            PHP, $name));
    }
}
