<?php

namespace Tests\Unit\Services;

use App\Services\GeoService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GeoServiceTest extends TestCase
{
    private GeoService $service;

    private const PUBLIC_IP = '82.64.12.34';

    private const PRIVATE_IP = '127.0.0.1';

    private const FALLBACK_IP = '1.2.3.4';

    private const API_SUCCESS = [
        'status' => 'success',
        'query' => self::PUBLIC_IP,
        'city' => 'Paris',
        'countryCode' => 'FR',
        'region' => 'IDF',
        'as' => 'AS3215 Orange SA',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeoService;
        Cache::flush();
    }

    // ─── resolveIp — tested indirectly via locate() ──────────────────────────

    public function test_locate_returns_null_for_null_ip(): void
    {
        Http::fake();

        $result = $this->service->locate(null);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_locate_returns_null_for_private_ip_without_fallback(): void
    {
        Http::fake();
        config(['geo.dev_fallback_ip' => '']);

        $result = $this->service->locate(self::PRIVATE_IP);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_locate_uses_fallback_ip_for_private_address(): void
    {
        config(['geo.dev_fallback_ip' => self::FALLBACK_IP]);
        Http::fake(['*' => Http::response(self::API_SUCCESS)]);

        $this->service->locate(self::PRIVATE_IP);

        Http::assertSent(fn ($request) => str_contains($request->url(), self::FALLBACK_IP));
    }

    // ─── Cache — hit ─────────────────────────────────────────────────────────

    public function test_locate_returns_cached_data_without_hitting_api(): void
    {
        Http::fake();
        Cache::put('geo_data:'.self::PUBLIC_IP, self::API_SUCCESS, 60);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertSame(self::API_SUCCESS, $result);
        Http::assertNothingSent();
    }

    public function test_locate_returns_null_when_negative_cache_is_active(): void
    {
        Http::fake();
        Cache::put('geo_data_failed:'.self::PUBLIC_IP, true, 60);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    // ─── API fetch — success ─────────────────────────────────────────────────

    public function test_locate_returns_data_and_caches_it_on_success(): void
    {
        Http::fake(['*' => Http::response(self::API_SUCCESS)]);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertSame(self::API_SUCCESS, $result);
        $this->assertSame(self::API_SUCCESS, Cache::get('geo_data:'.self::PUBLIC_IP));
        $this->assertFalse(Cache::has('geo_data_failed:'.self::PUBLIC_IP));
    }

    // ─── API fetch — failures ────────────────────────────────────────────────

    public function test_locate_returns_null_and_sets_negative_cache_on_non_2xx(): void
    {
        Http::fake(['*' => Http::response(null, 429)]);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertNull($result);
        $this->assertFalse(Cache::has('geo_data:'.self::PUBLIC_IP));
        $this->assertTrue(Cache::has('geo_data_failed:'.self::PUBLIC_IP));
    }

    public function test_locate_returns_null_and_sets_negative_cache_when_api_returns_fail_status(): void
    {
        Http::fake(['*' => Http::response(['status' => 'fail', 'message' => 'private range'])]);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertNull($result);
        $this->assertFalse(Cache::has('geo_data:'.self::PUBLIC_IP));
        $this->assertTrue(Cache::has('geo_data_failed:'.self::PUBLIC_IP));
    }

    public function test_locate_returns_null_and_sets_negative_cache_on_exception(): void
    {
        Http::fake(['*' => fn () => throw new \RuntimeException('connection refused')]);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertNull($result);
        $this->assertFalse(Cache::has('geo_data:'.self::PUBLIC_IP));
        $this->assertTrue(Cache::has('geo_data_failed:'.self::PUBLIC_IP));
    }

    public function test_locate_returns_null_and_sets_negative_cache_on_invalid_json(): void
    {
        Http::fake(['*' => Http::response('not-json', 200, ['Content-Type' => 'text/plain'])]);

        $result = $this->service->locate(self::PUBLIC_IP);

        $this->assertNull($result);
        $this->assertTrue(Cache::has('geo_data_failed:'.self::PUBLIC_IP));
    }

    // ─── Logs ────────────────────────────────────────────────────────────────

    public function test_locate_logs_warning_on_api_failure(): void
    {
        /** @var list<MessageLogged> $captured */
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });
        Http::fake(['*' => Http::response(null, 503)]);

        $this->service->locate(self::PUBLIC_IP);

        $match = array_filter($captured, fn ($e) => $e->level === 'warning'
            && $e->message === 'Geo API returned a non-2xx response'
            && ($e->context['ip'] ?? null) === self::PUBLIC_IP
            && ($e->context['status'] ?? null) === 503
        );
        $this->assertNotEmpty($match, 'Expected warning log was not emitted');
    }

    public function test_locate_logs_error_with_exception_on_network_failure(): void
    {
        /** @var list<MessageLogged> $captured */
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });
        $exception = new \RuntimeException('timeout');
        Http::fake(['*' => fn () => throw $exception]);

        $this->service->locate(self::PUBLIC_IP);

        $match = array_filter($captured, fn ($e) => $e->level === 'error'
            && $e->message === 'Geo API fetch threw an exception'
            && ($e->context['exception'] ?? null) === $exception
        );
        $this->assertNotEmpty($match, 'Expected error log was not emitted');
    }
}
