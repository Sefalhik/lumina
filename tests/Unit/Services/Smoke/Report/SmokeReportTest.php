<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Report;

use App\Enums\SmokeSeverity;
use App\Services\Smoke\Report\SmokeCheck;
use App\Services\Smoke\Report\SmokeReport;
use App\Services\Smoke\Site\SmokeTarget;
use PHPUnit\Framework\TestCase;

class SmokeReportTest extends TestCase
{
    private function report(bool $withBlocking = true): SmokeReport
    {
        $checks = [
            SmokeCheck::pass('health', 'The application answers'),
            SmokeCheck::fail('certificate', 'Certificate', 'Expires in 10 days.', 'Renew it.', SmokeSeverity::Warning),
            SmokeCheck::skip('release', 'Release', 'No --expect-release given.'),
        ];
        if ($withBlocking) {
            $checks[] = SmokeCheck::fail('e2e', 'Backdoor', '/e2e/admin-auth answered 302.', 'Set APP_ENV=production.');
        }

        return new SmokeReport(new SmokeTarget('https://preprod.example', null, 'laurent', 's3cret-pw'), $checks);
    }

    public function test_a_warning_alone_does_not_block(): void
    {
        $this->assertFalse($this->report(withBlocking: false)->blocked());
        $this->assertTrue($this->report()->blocked());
    }

    public function test_it_counts_each_outcome(): void
    {
        $this->assertSame(['passed' => 1, 'blocking' => 1, 'warnings' => 1, 'skipped' => 1], $this->report()->counts());
    }

    public function test_the_text_report_shows_each_failure_with_its_remedy(): void
    {
        $text = $this->report()->toText();

        $this->assertStringContainsString('✗ Backdoor — /e2e/admin-auth answered 302.', $text);
        $this->assertStringContainsString('→ Set APP_ENV=production.', $text);
        $this->assertStringContainsString('⚠ Certificate', $text);
        $this->assertStringContainsString('– Release', $text);
        $this->assertStringContainsString('✔ The application answers', $text);
        $this->assertStringContainsString('FAILED — 1 passed, 1 blocking, 1 warnings, 1 skipped', $text);
    }

    public function test_the_json_report_is_valid_and_complete(): void
    {
        $json = json_decode($this->report()->toJson(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($json);
        $this->assertFalse($json['passed']);
        $this->assertSame('https://preprod.example', $json['target']);
        $this->assertCount(4, $json['checks']);
        $this->assertSame(['id' => 'e2e', 'label' => 'Backdoor', 'severity' => 'blocking', 'status' => 'failed',
            'detail' => '/e2e/admin-auth answered 302.', 'remedy' => 'Set APP_ENV=production.'], $json['checks'][3]);
    }

    public function test_the_junit_report_is_valid_and_a_warning_is_not_a_failure(): void
    {
        $xml = simplexml_load_string($this->report()->toJunit());

        $this->assertNotFalse($xml);
        $this->assertSame('4', (string) $xml['tests']);
        $this->assertSame('1', (string) $xml['failures']);
        $this->assertSame('1', (string) $xml['skipped']);
        $this->assertCount(1, $xml->xpath('//testcase/failure') ?: []);
        // CI would otherwise block on a certificate that still has ten good days.
        $warning = $xml->xpath('//testcase[contains(@name, "certificate")]')[0] ?? null;
        $this->assertNotNull($warning);
        $this->assertCount(0, $warning->xpath('failure') ?: []);
        $this->assertStringContainsString('WARNING: Expires in 10 days.', (string) $warning->{'system-out'});
    }

    public function test_no_format_ever_renders_the_credentials(): void
    {
        $report = $this->report();

        foreach ([$report->toText(), $report->toJson(), $report->toJunit()] as $output) {
            $this->assertStringNotContainsString('s3cret-pw', $output);
            $this->assertStringNotContainsString('laurent', $output);
        }
    }
}
