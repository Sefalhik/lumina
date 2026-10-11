<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ReleaseInService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A site as scripts/release.sh lays one out, in a temporary directory: releases/, and a `current`
 * link. What is tested is one decision — whose front controller answers this request — and above
 * all the cases where the answer has to be "mine": nothing here may send a request elsewhere than
 * to a release of the same site.
 */
class ReleaseInServiceTest extends TestCase
{
    private const string A = '20261011-080000-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string B = '20261011-090000-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = sys_get_temp_dir().'/lumina-site-'.uniqid();
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

    public function test_a_release_in_service_serves_its_own_requests(): void
    {
        $this->serve('releases/'.self::A);

        $this->assertNull(ReleaseInService::frontController($this->path(self::A)));
    }

    public function test_a_release_that_was_replaced_hands_over_to_the_one_in_service(): void
    {
        $this->serve('releases/'.self::B);

        $this->assertSame(
            $this->path(self::B).'/public/index.php',
            ReleaseInService::frontController($this->path(self::A)),
        );
    }

    /** However the link names the release in service, the one that was replaced has to hand over. */
    #[DataProvider('waysOfNamingARelease')]
    public function test_the_link_may_be_written_by_the_scripts_or_by_hand(string $target): void
    {
        $this->serve(str_replace('{site}', $this->site, $target));

        $this->assertSame(
            $this->path(self::B).'/public/index.php',
            ReleaseInService::frontController($this->path(self::A)),
        );
    }

    /** @return array<string, array{string}> */
    public static function waysOfNamingARelease(): array
    {
        return [
            'as the scripts write it' => ['releases/'.self::B],
            'with the slash a shell completes it with' => ['releases/'.self::B.'/'],
            'by its full path' => ['{site}/releases/'.self::B],
            'by its full path and a slash' => ['{site}/releases/'.self::B.'/'],
        ];
    }

    public function test_making_php_forget_asks_nothing_php_refuses(): void
    {
        // The real functions, in this process: no code cache to reset on a command line, and no
        // warning for having asked.
        $this->quietly(static fn () => ReleaseInService::forgetTheOneItReplaced());

        $this->expectNotToPerformAssertions();
    }

    public function test_it_asks_the_link_every_time(): void
    {
        // A rollback is the same link moving the other way, and has to take effect the same way.
        $this->serve('releases/'.self::B);
        $this->assertNotNull(ReleaseInService::frontController($this->path(self::A)));

        $this->serve('releases/'.self::A);
        $this->assertNull(ReleaseInService::frontController($this->path(self::A)));
        $this->assertSame(
            $this->path(self::A).'/public/index.php',
            ReleaseInService::frontController($this->path(self::B)),
        );
    }

    public function test_a_site_with_no_link_serves_from_where_it_is_and_says_nothing(): void
    {
        // Asked on every request: a warning here would be a line in the log for each of them.
        $this->assertNull($this->quietly(fn () => ReleaseInService::frontController($this->path(self::A))));
    }

    public function test_a_directory_named_current_is_not_a_link(): void
    {
        mkdir($this->site.'/current/public', recursive: true);
        touch($this->site.'/current/public/index.php');

        $this->assertNull($this->quietly(fn () => ReleaseInService::frontController($this->path(self::A))));
    }

    public function test_a_link_that_leads_nowhere_changes_nothing(): void
    {
        // The release it names was removed: this one still exists, and goes on answering.
        $this->serve('releases/20261011-100000-cccccccccccccccccccccccccccccccccccccccc');

        $this->assertNull(ReleaseInService::frontController($this->path(self::A)));
    }

    public function test_a_release_with_no_front_controller_is_not_handed_anything(): void
    {
        unlink($this->path(self::B).'/public/index.php');
        $this->serve('releases/'.self::B);

        $this->assertNull(ReleaseInService::frontController($this->path(self::A)));
    }

    public function test_a_checkout_that_is_not_a_release_never_looks_for_a_link(): void
    {
        // A developer machine, or the single checkout a server used to be. Two levels up there is
        // a link, and beside the checkout a directory named like the release the link names: it
        // would all fit, if the checkout sat in a directory called releases/.
        mkdir($this->site.'/elsewhere/checkout/public', recursive: true);
        mkdir($this->site.'/elsewhere/'.self::B.'/public', recursive: true);
        touch($this->site.'/elsewhere/'.self::B.'/public/index.php');
        $this->serve('releases/'.self::B);

        $this->assertNull(ReleaseInService::frontController($this->site.'/elsewhere/checkout'));
    }

    /**
     * Every target below leads to a directory that holds a front controller. None is a release of
     * this site named the way the scripts write it, and none may be handed a request.
     */
    #[DataProvider('targetsThatAreNotAReleaseOfTheSite')]
    public function test_a_link_towards_anything_but_a_release_of_the_site_is_ignored(string $target): void
    {
        // Whatever the link names is there, with a front controller in it: only what it is called,
        // and how the link is written, can refuse it.
        $target = str_replace('{site}', $this->site, $target);
        $directory = str_starts_with($target, '/') ? $target : $this->site.'/'.$target;
        if (! is_dir($directory.'/public')) {
            mkdir($directory.'/public', recursive: true);
            touch($directory.'/public/index.php');
        }
        $this->assertFileExists($directory.'/public/index.php');
        $this->serve($target);

        $this->assertNull(ReleaseInService::frontController($this->path(self::A)));
    }

    /** @return array<string, array{string}> */
    public static function targetsThatAreNotAReleaseOfTheSite(): array
    {
        return [
            'a directory beside the releases' => ['outside'],
            'the same release, by a path with a detour' => ['releases/../releases/'.self::B],
            'a release of another site, by its full path' => ['{site}/elsewhere/releases/'.self::B],
            'a release of another site, by a relative path' => ['elsewhere/releases/'.self::B],
            'a directory whose name only starts like the releases' => ['{site}/releases-old/'.self::B],
            'a directory of releases/ not named like a release' => ['releases/by-hand'],
            'a directory inside a release' => ['releases/'.self::B.'/nested'],
            'a release name with a line break after it' => ['releases/'.self::B."\n"],
            'a release name in capitals' => ['releases/'.strtoupper(self::B)],
            'a release name with a shorter commit' => ['releases/20261011-090000-bbbbbbb'],
            'a release name with no moment' => ['releases/'.str_repeat('b', 40)],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $ask
     * @return T
     */
    private function quietly(callable $ask): mixed
    {
        set_error_handler(static function (int $level, string $message): never {
            throw new \ErrorException($message, 0, $level);
        });

        try {
            return $ask();
        } finally {
            restore_error_handler();
        }
    }

    private function release(string $name): void
    {
        mkdir($this->path($name).'/public', recursive: true);
        touch($this->path($name).'/public/index.php');
    }

    private function path(string $name): string
    {
        return $this->site.'/releases/'.$name;
    }

    private function serve(string $target): void
    {
        if (is_link($this->site.'/current')) {
            unlink($this->site.'/current');
        }
        symlink($target, $this->site.'/current');
    }
}
