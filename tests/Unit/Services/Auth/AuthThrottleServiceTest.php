<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\AuthThrottleService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Written from the thresholds decided on 2026-10-22 (LUMN-36), not from the class: every number
 * below is typed out, never read from a constant of AuthThrottleService. A test that loops on the
 * class's own constant agrees with any value the constant takes.
 *
 *   login, email + IP   5 failures  → 60 s
 *   login, email alone  20 failures → 3600 s
 *   two-factor, user    5 failures  → 900 s, shared by setup and challenge
 */
class AuthThrottleServiceTest extends TestCase
{
    private const EMAIL = 'admin@example.com';

    private const IP = '203.0.113.10';

    private AuthThrottleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->service = app(AuthThrottleService::class);
    }

    private function failLogin(int $times, string $email = self::EMAIL, string $ip = self::IP): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->service->recordLoginFailure($email, $ip);
        }
    }

    /** One failure from each of $addresses distinct IPs, none of them self::IP. */
    private function failLoginFromDistinctAddresses(int $addresses): void
    {
        for ($n = 1; $n <= $addresses; $n++) {
            $this->service->recordLoginFailure(self::EMAIL, "192.0.2.{$n}");
        }
    }

    private function failTwoFactor(User $user, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->service->recordTwoFactorFailure($user);
        }
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    // --- Login, email + IP: 5 failures, 60 s ---

    public function test_login_is_allowed_with_no_failure(): void
    {
        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_four_login_failures_still_allow_an_attempt(): void
    {
        $this->failLogin(4);

        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_five_login_failures_lock_out_for_sixty_seconds(): void
    {
        $this->failLogin(5);

        $this->assertSame(60, $this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_the_login_lockout_counts_down_and_lifts_at_sixty_seconds(): void
    {
        $this->failLogin(5);

        $this->travel(59)->seconds();
        $this->assertSame(1, $this->service->loginLockout(self::EMAIL, self::IP));

        $this->travel(1)->seconds();
        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_failures_older_than_sixty_seconds_no_longer_count(): void
    {
        $this->failLogin(4);
        $this->travel(60)->seconds();
        $this->failLogin(1);

        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_the_login_lockout_does_not_reach_another_ip(): void
    {
        $this->failLogin(5);

        $this->assertNull($this->service->loginLockout(self::EMAIL, '198.51.100.20'));
    }

    public function test_the_login_lockout_does_not_reach_another_email(): void
    {
        $this->failLogin(5);

        $this->assertNull($this->service->loginLockout('someone@example.com', self::IP));
    }

    public function test_email_case_and_surrounding_spaces_do_not_buy_extra_attempts(): void
    {
        $this->failLogin(2, 'Admin@Example.com');
        $this->failLogin(2, '  ADMIN@EXAMPLE.COM ');
        $this->failLogin(1, 'admin@example.com');

        $this->assertSame(60, $this->service->loginLockout('aDmIn@eXaMpLe.CoM', self::IP));
    }

    public function test_a_request_without_an_address_is_still_limited(): void
    {
        // REMOTE_ADDR is always set behind Apache; should it ever be missing, the callers share
        // one counter — stricter, never a way around the limit.
        $this->failLogin(5, self::EMAIL, '');

        $this->assertSame(60, $this->service->loginLockout(self::EMAIL, ''));
    }

    // --- Login, email alone: 20 failures, 3600 s ---

    public function test_nineteen_failures_spread_over_addresses_still_allow_an_attempt(): void
    {
        $this->failLoginFromDistinctAddresses(19);

        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_twenty_failures_spread_over_addresses_lock_the_email_out_for_an_hour(): void
    {
        // No address fails more than once: only the email counter can be what refuses.
        $this->failLoginFromDistinctAddresses(20);

        $this->assertSame(3600, $this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_the_email_lockout_counts_down_and_lifts_at_an_hour(): void
    {
        $this->failLoginFromDistinctAddresses(20);

        $this->travel(3599)->seconds();
        $this->assertSame(1, $this->service->loginLockout(self::EMAIL, self::IP));

        $this->travel(1)->seconds();
        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_the_email_lockout_does_not_reach_another_email(): void
    {
        $this->failLoginFromDistinctAddresses(20);

        $this->assertNull($this->service->loginLockout('someone@example.com', self::IP));
    }

    public function test_the_longest_wait_is_announced_when_both_counters_are_locked(): void
    {
        $this->failLoginFromDistinctAddresses(15);
        $this->travel(10)->seconds();
        $this->failLogin(5);

        // email + IP would say 60; the email counter, started 10 s earlier, 3590.
        $this->assertSame(3590, $this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_clearing_the_login_resets_the_email_and_ip_counter(): void
    {
        $this->failLogin(4);

        $this->service->clearLogin(self::EMAIL, self::IP);
        $this->failLogin(4);

        // Without the reset: 8 failures on this address.
        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_clearing_the_login_resets_the_email_counter(): void
    {
        $this->failLoginFromDistinctAddresses(19);

        $this->service->clearLogin(self::EMAIL, self::IP);
        $this->failLoginFromDistinctAddresses(19);

        // Without the reset: 38 failures on this email.
        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    public function test_the_email_never_appears_in_a_counter_key(): void
    {
        // A real limiter that remembers the keys it is given — no mock, so its type is checked.
        $limiter = new class(app('cache')->store()) extends RateLimiter
        {
            /** @var list<string> */
            public array $keys = [];

            public function hit($key, $decaySeconds = 60)
            {
                $this->keys[] = $key;

                return parent::hit($key, $decaySeconds);
            }
        };

        (new AuthThrottleService($limiter))->recordLoginFailure(self::EMAIL, self::IP);

        $this->assertCount(2, $limiter->keys);
        foreach ($limiter->keys as $key) {
            $this->assertStringNotContainsStringIgnoringCase('admin', $key);
            $this->assertStringNotContainsString('example.com', $key);
        }
    }

    // --- Two-factor, per user: 5 failures, 900 s ---

    public function test_four_two_factor_failures_still_allow_a_code(): void
    {
        $this->failTwoFactor($this->user(7), 4);

        $this->assertNull($this->service->twoFactorLockout($this->user(7)));
    }

    public function test_five_two_factor_failures_lock_out_for_fifteen_minutes(): void
    {
        $this->failTwoFactor($this->user(7), 5);

        $this->assertSame(900, $this->service->twoFactorLockout($this->user(7)));
    }

    public function test_the_two_factor_lockout_counts_down_and_lifts_at_fifteen_minutes(): void
    {
        $this->failTwoFactor($this->user(7), 5);

        $this->travel(899)->seconds();
        $this->assertSame(1, $this->service->twoFactorLockout($this->user(7)));

        $this->travel(1)->seconds();
        $this->assertNull($this->service->twoFactorLockout($this->user(7)));
    }

    public function test_the_two_factor_lockout_does_not_reach_another_user(): void
    {
        $this->failTwoFactor($this->user(7), 5);

        $this->assertNull($this->service->twoFactorLockout($this->user(8)));
    }

    public function test_clearing_two_factor_resets_its_counter(): void
    {
        $this->failTwoFactor($this->user(7), 4);

        $this->service->clearTwoFactor($this->user(7));
        $this->failTwoFactor($this->user(7), 4);

        $this->assertNull($this->service->twoFactorLockout($this->user(7)));
    }

    public function test_login_failures_do_not_count_against_two_factor(): void
    {
        $this->failLogin(5);

        $this->assertNull($this->service->twoFactorLockout($this->user(7)));
    }

    public function test_two_factor_failures_do_not_count_against_login(): void
    {
        $this->failTwoFactor($this->user(7), 5);

        $this->assertNull($this->service->loginLockout(self::EMAIL, self::IP));
    }

    // --- Logging ---

    public function test_a_refused_login_logs_a_warning_without_the_email(): void
    {
        $this->failLogin(5);
        $log = Log::spy();

        $this->service->loginLockout(self::EMAIL, self::IP);

        $log->shouldHaveReceived('warning')->once()->with(
            'Login attempt refused — too many failures',
            Mockery::on(fn (array $context): bool => $context === [
                'service' => AuthThrottleService::class,
                'method' => 'loginLockout',
                'step' => 'login_throttled',
                'ip' => self::IP,
                'retry_after' => 60,
            ]),
        );
    }

    public function test_an_allowed_login_logs_nothing(): void
    {
        $this->failLogin(4);
        $log = Log::spy();

        $this->service->loginLockout(self::EMAIL, self::IP);

        $log->shouldNotHaveReceived('warning');
    }

    public function test_the_fifth_login_failure_raises_one_alert(): void
    {
        $this->failLogin(4);
        $log = Log::spy();

        $this->failLogin(1);

        $log->shouldHaveReceived('alert')->once()->with(
            'Login locked out after repeated failures',
            Mockery::on(fn (array $context): bool => $context === [
                'service' => AuthThrottleService::class,
                'method' => 'recordLoginFailure',
                'step' => 'login_lockout',
                'ip' => self::IP,
            ]),
        );
    }

    public function test_the_fourth_login_failure_raises_no_alert(): void
    {
        $this->failLogin(3);
        $log = Log::spy();

        $this->failLogin(1);

        $log->shouldNotHaveReceived('alert');
    }

    public function test_the_twentieth_failure_on_an_email_raises_the_alert_on_its_own(): void
    {
        $this->failLoginFromDistinctAddresses(19);
        $log = Log::spy();

        $this->service->recordLoginFailure(self::EMAIL, '198.51.100.99');

        $log->shouldHaveReceived('alert')->once();
    }

    public function test_the_nineteenth_failure_on_an_email_raises_no_alert(): void
    {
        $this->failLoginFromDistinctAddresses(18);
        $log = Log::spy();

        $this->service->recordLoginFailure(self::EMAIL, '198.51.100.99');

        $log->shouldNotHaveReceived('alert');
    }

    public function test_a_refused_two_factor_code_logs_a_warning_with_the_user_id(): void
    {
        $this->failTwoFactor($this->user(7), 5);
        $log = Log::spy();

        $this->service->twoFactorLockout($this->user(7));

        $log->shouldHaveReceived('warning')->once()->with(
            'Two-factor code refused — too many failures',
            Mockery::on(fn (array $context): bool => $context === [
                'service' => AuthThrottleService::class,
                'method' => 'twoFactorLockout',
                'step' => 'two_factor_throttled',
                'user_id' => 7,
                'retry_after' => 900,
            ]),
        );
    }

    public function test_an_allowed_two_factor_code_logs_nothing(): void
    {
        $this->failTwoFactor($this->user(7), 4);
        $log = Log::spy();

        $this->service->twoFactorLockout($this->user(7));

        $log->shouldNotHaveReceived('warning');
    }

    public function test_the_fifth_two_factor_failure_raises_one_alert(): void
    {
        $this->failTwoFactor($this->user(7), 4);
        $log = Log::spy();

        $this->failTwoFactor($this->user(7), 1);

        $log->shouldHaveReceived('alert')->once()->with(
            'Two-factor locked out after repeated failures',
            Mockery::on(fn (array $context): bool => $context === [
                'service' => AuthThrottleService::class,
                'method' => 'recordTwoFactorFailure',
                'step' => 'two_factor_lockout',
                'user_id' => 7,
            ]),
        );
    }

    public function test_the_fourth_two_factor_failure_raises_no_alert(): void
    {
        $this->failTwoFactor($this->user(7), 3);
        $log = Log::spy();

        $this->failTwoFactor($this->user(7), 1);

        $log->shouldNotHaveReceived('alert');
    }
}
