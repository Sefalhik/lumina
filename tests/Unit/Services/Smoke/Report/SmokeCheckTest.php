<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Report;

use App\Enums\SmokeSeverity;
use App\Enums\SmokeStatus;
use App\Services\Smoke\Report\SmokeCheck;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A check has three shapes and no fourth. Passed-with-a-remedy, or failed-without-one, were
 * possible until 2026-09-20 — nobody had written them, which is not the same as impossible.
 */
class SmokeCheckTest extends TestCase
{
    /**
     * The structural guard for the rest of this file: with a public constructor every invariant
     * below is a convention, and conventions are what a copy-paste breaks at two in the morning.
     */
    public function test_a_check_can_only_be_built_through_a_named_constructor(): void
    {
        $constructor = (new ReflectionClass(SmokeCheck::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue(
            $constructor->isPrivate(),
            'SmokeCheck::__construct must stay private: pass(), fail() and skip() are the only coherent shapes.',
        );
    }

    public function test_a_passing_check_carries_no_remedy(): void
    {
        $check = SmokeCheck::pass('id', 'label', 'detail');

        $this->assertTrue($check->passed());
        $this->assertFalse($check->blocks());
        $this->assertFalse($check->warns());
        $this->assertSame('', $check->remedy);
    }

    /**
     * @param  string  $remedy  a remedy that says nothing
     */
    #[DataProvider('emptyRemedies')]
    public function test_a_failure_without_a_remedy_is_refused(string $remedy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the-probe');

        SmokeCheck::fail('the-probe', 'label', 'something is wrong', $remedy);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyRemedies(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'a newline' => ["\n"];
    }

    public function test_a_blocking_failure_blocks_and_a_warning_does_not(): void
    {
        $blocking = SmokeCheck::fail('id', 'label', 'detail', 'remedy');
        $warning = SmokeCheck::fail('id', 'label', 'detail', 'remedy', SmokeSeverity::Warning);

        $this->assertTrue($blocking->blocks());
        $this->assertFalse($blocking->warns());
        $this->assertTrue($warning->warns());
        $this->assertFalse($warning->blocks());
        $this->assertFalse($warning->passed());
    }

    public function test_a_skipped_check_is_neither_a_pass_nor_a_failure(): void
    {
        // Skipped means the probe could not apply — the report says why, and nothing is promoted on
        // the strength of a question that was never asked.
        $check = SmokeCheck::skip('id', 'label', 'no expected release was given');

        $this->assertSame(SmokeStatus::Skipped, $check->status);
        $this->assertFalse($check->passed());
        $this->assertFalse($check->blocks());
        $this->assertFalse($check->warns());
        $this->assertSame('no expected release was given', $check->detail);
    }
}
