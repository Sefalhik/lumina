<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Http;

use App\Services\Http\SecurityHeaders;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The headers every response carries (LUMN-37). Pure logic: no request, no container — the cases a
 * Feature test would only reach one at a time are all here.
 */
class SecurityHeadersTest extends TestCase
{
    private const NONCE = 'r4nd0mN0nce';

    /** @var array<string, list<string>> */
    private const POLICY = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'object-src' => ["'none'"],
    ];

    /**
     * @param  array<string, list<string>>  $policy
     */
    private function headers(
        bool $indexable = true,
        int $hstsMaxAge = 86400,
        bool $includeSubdomains = true,
        array $policy = self::POLICY,
        ?string $devServerUrl = null,
    ): SecurityHeaders {
        return new SecurityHeaders($indexable, $hstsMaxAge, $includeSubdomains, $policy, $devServerUrl);
    }

    public function test_the_fixed_headers_are_always_present(): void
    {
        $headers = $this->headers()->for(secure: false, nonce: self::NONCE);

        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        $this->assertSame('camera=(), geolocation=(), microphone=()', $headers['Permissions-Policy']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    public function test_hsts_is_sent_over_https_only(): void
    {
        $this->assertArrayNotHasKey('Strict-Transport-Security', $this->headers()->for(false, self::NONCE));
        $this->assertSame(
            'max-age=86400; includeSubDomains',
            $this->headers()->for(true, self::NONCE)['Strict-Transport-Security'],
        );
    }

    public function test_hsts_carries_the_configured_duration(): void
    {
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $this->headers(hstsMaxAge: 31536000)->for(true, self::NONCE)['Strict-Transport-Security'],
        );
    }

    public function test_hsts_can_leave_subdomains_out(): void
    {
        $this->assertSame(
            'max-age=86400',
            $this->headers(includeSubdomains: false)->for(true, self::NONCE)['Strict-Transport-Security'],
        );
    }

    public function test_a_zero_hsts_duration_is_kept_since_it_is_how_a_browser_is_told_to_forget(): void
    {
        $this->assertSame(
            'max-age=0; includeSubDomains',
            $this->headers(hstsMaxAge: 0)->for(true, self::NONCE)['Strict-Transport-Security'],
        );
    }

    public function test_a_negative_hsts_duration_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->headers(hstsMaxAge: -1);
    }

    public function test_a_site_that_is_not_indexable_says_so(): void
    {
        $headers = $this->headers(indexable: false)->for(false, self::NONCE);

        $this->assertSame('noindex, nofollow', $headers['X-Robots-Tag']);
    }

    public function test_an_indexable_site_sends_no_robots_header(): void
    {
        $this->assertArrayNotHasKey('X-Robots-Tag', $this->headers(indexable: true)->for(false, self::NONCE));
    }

    public function test_the_policy_is_only_reported_never_enforced(): void
    {
        $headers = $this->headers()->for(false, self::NONCE);

        $this->assertArrayHasKey('Content-Security-Policy-Report-Only', $headers);
        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
    }

    public function test_the_policy_serialises_every_directive_in_order(): void
    {
        $this->assertSame(
            "default-src 'self'; script-src 'self' 'nonce-r4nd0mN0nce'; style-src 'self' 'nonce-r4nd0mN0nce'; connect-src 'self'; object-src 'none'",
            $this->headers()->for(false, self::NONCE)['Content-Security-Policy-Report-Only'],
        );
    }

    public function test_the_nonce_goes_to_scripts_and_styles_only(): void
    {
        // Styles too: Vite injects <style> elements in development, stamped with the nonce it reads
        // from the page — a nonce the policy does not declare for styles is no nonce for them.
        $policy = $this->headers()->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertSame(2, substr_count($policy, "'nonce-"));
        $this->assertStringContainsString("script-src 'self' 'nonce-r4nd0mN0nce'", $policy);
        $this->assertStringContainsString("style-src 'self' 'nonce-r4nd0mN0nce'", $policy);
    }

    public function test_a_policy_without_script_or_style_src_still_gets_the_nonce(): void
    {
        // Without their own directive, scripts and styles fall back to default-src: a nonce added
        // nowhere would leave every inline one reported. The directives are created from
        // default-src rather than the nonce lost.
        $policy = $this->headers(policy: ['default-src' => ["'self'"]])
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertSame(
            "default-src 'self'; script-src 'self' 'nonce-r4nd0mN0nce'; style-src 'self' 'nonce-r4nd0mN0nce'",
            $policy,
        );
    }

    public function test_the_development_server_is_allowed_where_vite_needs_it(): void
    {
        $policy = $this->headers(devServerUrl: 'https://dev.cardascia-it.org:5173')
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringContainsString(
            "script-src 'self' https://dev.cardascia-it.org:5173 'nonce-r4nd0mN0nce';",
            $policy,
        );
        $this->assertStringContainsString("style-src 'self' https://dev.cardascia-it.org:5173 'nonce-r4nd0mN0nce';", $policy);
        // In development, Vite serves the font files too — the Fontsource packages (LUMN-102).
        $this->assertStringContainsString("font-src 'self' https://dev.cardascia-it.org:5173", $policy);
        $this->assertStringContainsString(
            "connect-src 'self' https://dev.cardascia-it.org:5173 wss://dev.cardascia-it.org:5173;",
            $policy,
        );
    }

    public function test_the_development_server_never_replaces_the_sources_a_directive_inherits(): void
    {
        $policy = $this->headers(
            policy: ['default-src' => ["'self'"]],
            devServerUrl: 'https://dev.cardascia-it.org:5173',
        )->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertSame(
            "default-src 'self'; "
            ."script-src 'self' https://dev.cardascia-it.org:5173 'nonce-r4nd0mN0nce'; "
            ."style-src 'self' https://dev.cardascia-it.org:5173 'nonce-r4nd0mN0nce'; "
            ."font-src 'self' https://dev.cardascia-it.org:5173; "
            ."connect-src 'self' https://dev.cardascia-it.org:5173 wss://dev.cardascia-it.org:5173",
            $policy,
        );
    }

    public function test_a_plain_http_development_server_gets_a_plain_websocket(): void
    {
        $policy = $this->headers(devServerUrl: 'http://127.0.0.1:5173')
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringContainsString('ws://127.0.0.1:5173;', $policy);
        $this->assertStringNotContainsString('wss://', $policy);
    }

    public function test_without_a_development_server_the_policy_names_none(): void
    {
        $policy = $this->headers()->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringNotContainsString(':5173', $policy);
        $this->assertStringNotContainsString('ws', $policy);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableDevServerUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['dev.cardascia-it.org:5173'];
        yield 'another scheme' => ['ftp://dev.cardascia-it.org:5173'];
        yield 'a path' => ['https://dev.cardascia-it.org:5173/assets'];
        yield 'a directive smuggled in' => ["https://evil.example; script-src 'unsafe-inline'"];
        yield 'a trailing newline' => ["https://dev.cardascia-it.org:5173\n"];
    }

    #[DataProvider('unusableDevServerUrls')]
    public function test_an_unusable_development_server_url_is_refused(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->headers(devServerUrl: $url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableNonces(): iterable
    {
        yield 'empty' => [''];
        yield 'a quote' => ["abc'def"];
        yield 'a directive smuggled in' => ["abc; script-src 'unsafe-inline'"];
        yield 'a space' => ['abc def'];
        yield 'a newline' => ["abc\ndef"];
    }

    #[DataProvider('unusableNonces')]
    public function test_a_nonce_that_would_break_the_header_is_refused(string $nonce): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->headers()->for(false, $nonce);
    }

    public function test_a_base64_nonce_is_accepted(): void
    {
        $policy = $this->headers()->for(false, 'aB3+/_-x==')['Content-Security-Policy-Report-Only'];

        $this->assertStringContainsString("'nonce-aB3+/_-x=='", $policy);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedPolicies(): iterable
    {
        yield 'a directive name with a space' => [['script src' => ["'self'"]]];
        yield 'an uppercase directive' => [['Script-Src' => ["'self'"]]];
        yield 'a numeric key' => [[0 => ["'self'"]]];
        yield 'sources that are not a list' => [['script-src' => "'self'"]];
        yield 'a source smuggling a directive' => [['script-src' => ["'self'; img-src *"]]];
        yield 'a source with a comma' => [['script-src' => ["'self', *"]]];
        yield 'an empty source' => [['script-src' => ['']]];
        yield 'a source that is not a string' => [['script-src' => [42]]];
        yield 'no directive at all' => [[]];
    }

    /**
     * @param  array<mixed>  $policy
     */
    #[DataProvider('malformedPolicies')]
    public function test_a_malformed_policy_is_refused_when_the_service_is_built(array $policy): void
    {
        // A configuration error has to surface on the first request, loudly — not as a header that
        // browsers silently ignore, or worse, one that allows what the configuration meant to deny.
        $this->expectException(InvalidArgumentException::class);

        $this->headers(policy: $policy);
    }

    /**
     * @return array<string, mixed>
     */
    private static function configuration(): array
    {
        return [
            'hsts' => ['max_age' => 86400, 'include_subdomains' => true],
            'csp' => self::POLICY,
        ];
    }

    public function test_built_from_a_valid_configuration_it_sends_what_the_configuration_says(): void
    {
        $headers = SecurityHeaders::fromConfiguration(false, self::configuration(), false, null)->for(true, self::NONCE);

        $this->assertSame('max-age=86400; includeSubDomains', $headers['Strict-Transport-Security']);
        $this->assertSame('noindex, nofollow', $headers['X-Robots-Tag']);
        $this->assertStringStartsWith("default-src 'self'; script-src 'self' 'nonce-", $headers['Content-Security-Policy-Report-Only']);
    }

    public function test_built_from_configuration_an_indexable_site_sends_no_robots_header(): void
    {
        $headers = SecurityHeaders::fromConfiguration(true, self::configuration(), false, null)->for(false, self::NONCE);

        $this->assertArrayNotHasKey('X-Robots-Tag', $headers);
    }

    public function test_built_from_configuration_subdomains_can_be_left_out(): void
    {
        $configuration = self::configuration();
        $configuration['hsts']['include_subdomains'] = false;

        $headers = SecurityHeaders::fromConfiguration(true, $configuration, false, null)->for(true, self::NONCE);

        $this->assertSame('max-age=86400', $headers['Strict-Transport-Security']);
    }

    /**
     * Every wrong type fails loudly, and none is cast: cast, a missing duration would read 0 —
     * max-age=0, "forget HSTS" — and "true" or 1 would pass for a boolean nobody wrote.
     *
     * @return iterable<string, array{mixed, mixed, string}>
     */
    public static function unusableConfigurations(): iterable
    {
        $valid = self::configuration();

        yield 'indexable as a string' => ['true', $valid, 'seo.indexable must be a boolean.'];
        yield 'indexable as an integer' => [1, $valid, 'seo.indexable must be a boolean.'];
        yield 'indexable missing' => [null, $valid, 'seo.indexable must be a boolean.'];
        yield 'no configuration file' => [true, null, 'security-headers must be an array.'];
        yield 'configuration as a string' => [true, 'csp', 'security-headers must be an array.'];
        yield 'hsts missing' => [true, ['csp' => self::POLICY], 'security-headers.hsts must be an array.'];
        yield 'hsts as a number' => [true, ['hsts' => 86400, 'csp' => self::POLICY], 'security-headers.hsts must be an array.'];
        yield 'max_age missing' => [true, ['hsts' => ['include_subdomains' => true], 'csp' => self::POLICY], 'security-headers.hsts.max_age must be an integer.'];
        yield 'max_age as a string' => [true, ['hsts' => ['max_age' => '86400', 'include_subdomains' => true], 'csp' => self::POLICY], 'security-headers.hsts.max_age must be an integer.'];
        yield 'max_age as a float' => [true, ['hsts' => ['max_age' => 86400.0, 'include_subdomains' => true], 'csp' => self::POLICY], 'security-headers.hsts.max_age must be an integer.'];
        yield 'max_age null' => [true, ['hsts' => ['max_age' => null, 'include_subdomains' => true], 'csp' => self::POLICY], 'security-headers.hsts.max_age must be an integer.'];
        yield 'include_subdomains missing' => [true, ['hsts' => ['max_age' => 86400], 'csp' => self::POLICY], 'security-headers.hsts.include_subdomains must be a boolean.'];
        yield 'include_subdomains as a string' => [true, ['hsts' => ['max_age' => 86400, 'include_subdomains' => 'true'], 'csp' => self::POLICY], 'security-headers.hsts.include_subdomains must be a boolean.'];
        yield 'include_subdomains as an integer' => [true, ['hsts' => ['max_age' => 86400, 'include_subdomains' => 1], 'csp' => self::POLICY], 'security-headers.hsts.include_subdomains must be a boolean.'];
        yield 'csp missing' => [true, ['hsts' => $valid['hsts']], 'security-headers.csp must be an array.'];
        yield 'csp as a string' => [true, ['hsts' => $valid['hsts'], 'csp' => "default-src 'self'"], 'security-headers.csp must be an array.'];
    }

    #[DataProvider('unusableConfigurations')]
    public function test_an_unusable_configuration_is_refused_by_name(mixed $indexable, mixed $configuration, string $message): void
    {
        try {
            SecurityHeaders::fromConfiguration($indexable, $configuration, false, null);
            $this->fail('An unusable configuration was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_a_negative_duration_from_the_configuration_is_refused(): void
    {
        $configuration = self::configuration();
        $configuration['hsts']['max_age'] = -86400;

        $this->expectException(InvalidArgumentException::class);

        SecurityHeaders::fromConfiguration(true, $configuration, false, null);
    }

    public function test_the_development_server_is_allowed_on_a_developer_machine(): void
    {
        // The hot file ends with whatever its writer left: a trailing newline is not a reason to fail.
        $policy = SecurityHeaders::fromConfiguration(true, self::configuration(), true, "https://dev.cardascia-it.org:5173\n")
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringContainsString('wss://dev.cardascia-it.org:5173', $policy);
    }

    public function test_a_hot_file_found_anywhere_else_is_ignored(): void
    {
        // Left in a release by mistake, or written by a test: outside a developer's machine the file
        // decides nothing — not even to fail.
        foreach (['https://dev.cardascia-it.org:5173', '', 'not a url'] as $hotFile) {
            $policy = SecurityHeaders::fromConfiguration(true, self::configuration(), false, $hotFile)
                ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

            $this->assertStringNotContainsString('5173', $policy);
            $this->assertStringNotContainsString('ws', $policy);
        }
    }

    public function test_a_developer_machine_without_a_running_server_allows_none(): void
    {
        $policy = SecurityHeaders::fromConfiguration(true, self::configuration(), true, null)
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringNotContainsString('ws', $policy);
    }

    public function test_an_empty_or_unreadable_hot_file_on_a_developer_machine_fails_loudly(): void
    {
        // The provider reads an unreadable file as "". Vite itself could not serve from it either.
        $this->expectException(InvalidArgumentException::class);

        SecurityHeaders::fromConfiguration(true, self::configuration(), true, '');
    }

    public function test_an_ipv6_development_server_is_allowed(): void
    {
        // laravel-vite-plugin writes [::1] when Vite listens on the IPv6 loopback.
        $policy = SecurityHeaders::fromConfiguration(true, self::configuration(), true, 'http://[::1]:5173')
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringContainsString("connect-src 'self' http://[::1]:5173 ws://[::1]:5173", $policy);
    }

    public function test_a_directive_with_no_source_is_kept_as_a_bare_keyword(): void
    {
        // upgrade-insecure-requests and block-all-mixed-content take no value.
        $policy = $this->headers(policy: ['default-src' => ["'self'"], 'upgrade-insecure-requests' => []])
            ->for(false, self::NONCE)['Content-Security-Policy-Report-Only'];

        $this->assertStringContainsString('; upgrade-insecure-requests;', $policy);
    }
}
