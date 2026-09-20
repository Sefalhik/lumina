<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Smoke\Site;

use App\Services\Smoke\Site\SmokeTarget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmokeTargetTest extends TestCase
{
    public function test_credentials_in_the_url_are_refused_rather_than_stripped(): void
    {
        // Accepting them, even to strip them, would teach the habit that puts a password in shell
        // history and CI logs.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMOKE_BASIC_USER');

        new SmokeTarget('https://laurent:s3cret@preprod.example');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        return [
            'relative' => ['/fr'],
            'no scheme' => ['preprod.example'],
            'other scheme' => ['ftp://preprod.example'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_only_absolute_http_urls_are_accepted(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SmokeTarget($url);
    }

    public function test_it_normalises_the_base_url_and_builds_urls_from_it(): void
    {
        $target = new SmokeTarget('https://preprod.example/');

        $this->assertSame('https://preprod.example', $target->baseUrl);
        $this->assertSame('https://preprod.example/fr/cv', $target->url('/fr/cv'));
        $this->assertSame('https://preprod.example/fr', $target->url('fr'));
        $this->assertSame('preprod.example', $target->host());
    }

    public function test_it_derives_the_port_from_the_scheme_unless_given(): void
    {
        $this->assertSame(443, (new SmokeTarget('https://a.example'))->port());
        $this->assertSame(80, (new SmokeTarget('http://a.example'))->port());
        $this->assertSame(8001, (new SmokeTarget('http://localhost:8001'))->port());
        $this->assertTrue((new SmokeTarget('https://a.example'))->isHttps());
        $this->assertFalse((new SmokeTarget('http://a.example'))->isHttps());
    }

    public function test_credentials_count_only_when_a_user_is_given(): void
    {
        $this->assertTrue((new SmokeTarget('https://a.example', null, 'laurent', 's3cret'))->hasCredentials());
        $this->assertFalse((new SmokeTarget('https://a.example', null, '', 's3cret'))->hasCredentials());
        $this->assertFalse((new SmokeTarget('https://a.example', null, 'laurent', null))->hasCredentials());
        $this->assertFalse((new SmokeTarget('https://a.example'))->hasCredentials());
    }
}
