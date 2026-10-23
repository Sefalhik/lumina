<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * LUMN-36 — the limiters are really wired: each form asks before checking anything, counts its
 * failures, clears them on success. One case per wired rule. Thresholds, expiry and keys, in every
 * case they can meet, are proven in Tests\Unit\Services\Auth\AuthThrottleServiceTest.
 *
 * Numbers are typed out from the decision of 2026-10-22, never read from the service.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-password';

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->freezeTime();

        // A failed Auth::attempt() lasts at least 200 ms on purpose, so that its duration tells
        // an attacker nothing (Illuminate\Support\Timebox). That protection is the framework's and
        // these tests do not measure it; at 200 ms per failure they took 13 s instead of 1.
        config(['auth.timebox_duration' => 0]);
    }

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
    }

    /** @return array{0: User, 1: string} */
    private function adminWith2fa(): array
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $secret = (new Google2FA)->generateSecretKey();

        $admin = $this->user();
        $admin->assignRole('admin');
        $admin->two_factor_secret = $secret;
        $admin->two_factor_confirmed_at = now();
        $admin->save();

        return [$admin, $secret];
    }

    /** @param  array<string, string>  $headers */
    private function login(User $user, string $password, string $ip = '127.0.0.1', array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders($headers)
            ->post('/fr/login', ['email' => $user->email, 'password' => $password]);
    }

    private function failLogin(User $user, int $times, string $ip = '127.0.0.1'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->login($user, 'wrong-password', $ip)->assertSessionHasErrors(['email' => __('auth.failed', [], 'fr')]);
        }
    }

    private function challenge(User $admin, string $code): TestResponse
    {
        return $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $code]);
    }

    private function setupCode(User $user, string $secret, string $code): TestResponse
    {
        return $this->actingAs($user)->withSession(['auth.two_factor_setup_secret' => $secret])
            ->post('/fr/two-factor/setup', ['code' => $code]);
    }

    private function failChallenge(User $admin, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->challenge($admin, '000000')->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')]);
        }
    }

    private function throttled(int $seconds): string
    {
        return __('auth.throttle', ['seconds' => $seconds], 'fr');
    }

    private function home(): string
    {
        return route('home', ['lang' => config('app.locale')]);
    }

    private function dashboard(): string
    {
        return route('admin.dashboard', ['lang' => config('app.locale')]);
    }

    /**
     * A code from a later time step: accepted inside the ±1 window, and never the code an earlier
     * call used — so these tests still pass once LUMN-91 refuses a replayed code.
     */
    private function validCode(string $secret, int $step = 1): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $step);
    }

    // --- Login ---

    public function test_after_five_failures_login_is_refused_with_the_wait_even_for_the_right_password(): void
    {
        $user = $this->user();
        $this->failLogin($user, 5);

        $this->login($user, self::PASSWORD)->assertSessionHasErrors(['email' => $this->throttled(60)]);

        $this->assertGuest();
    }

    public function test_a_refused_login_is_not_counted_as_a_failure(): void
    {
        // The limiter windows are fixed, so a counted refusal would not lengthen the minute: it
        // would show on the email counter instead. 5 failures + 15 refusals stay under 20.
        $user = $this->user();
        $this->failLogin($user, 5);
        for ($i = 0; $i < 15; $i++) {
            $this->login($user, 'wrong-password')->assertSessionHasErrors(['email' => $this->throttled(60)]);
        }

        $this->travel(60)->seconds();

        $this->login($user, self::PASSWORD)->assertRedirect($this->home());
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_forged_forwarded_for_does_not_reset_the_login_limiter(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->login($user, 'wrong-password', '127.0.0.1', ['X-Forwarded-For' => "198.51.100.{$i}"]);
        }

        // Fails the day trustProxies comes back: the forged address would then be a fresh key.
        $this->login($user, self::PASSWORD, '127.0.0.1', ['X-Forwarded-For' => '198.51.100.250'])
            ->assertSessionHasErrors(['email' => $this->throttled(60)]);

        $this->assertGuest();
    }

    public function test_the_login_limiter_follows_the_real_client_address(): void
    {
        // Negative control for the test above: a different REMOTE_ADDR is a different key.
        $user = $this->user();
        $this->failLogin($user, 5, '203.0.113.1');

        $this->login($user, self::PASSWORD, '203.0.113.2')->assertRedirect($this->home());

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_clears_the_counter(): void
    {
        $user = $this->user();
        $this->failLogin($user, 4);
        $this->login($user, self::PASSWORD)->assertRedirect($this->home());
        $this->post('/fr/logout');

        $this->failLogin($user, 4);

        // Without the reset this would follow 8 failures in a row, and be refused.
        $this->login($user, self::PASSWORD)->assertRedirect($this->home());
        $this->assertAuthenticatedAs($user);
    }

    public function test_twenty_failures_from_as_many_addresses_lock_the_email_out_from_any_address(): void
    {
        $user = $this->user();
        for ($n = 1; $n <= 20; $n++) {
            $this->failLogin($user, 1, "192.0.2.{$n}");
        }

        $this->login($user, self::PASSWORD, '192.0.2.99')->assertSessionHasErrors(['email' => $this->throttled(3600)]);

        $this->assertGuest();
    }

    public function test_a_refused_login_is_logged(): void
    {
        $user = $this->user();
        $this->failLogin($user, 5);
        $log = Log::spy();

        $this->login($user, self::PASSWORD);

        $log->shouldHaveReceived('warning')->once()->with(
            'Login attempt refused — too many failures',
            Mockery::on(fn (array $context): bool => $context['step'] === 'login_throttled'
                && $context['ip'] === '127.0.0.1'
                && $context['retry_after'] === 60),
        );
    }

    // --- What the user sees ---
    // The forms are server-rendered Blade with no island: the page HTML is all the user gets, so
    // the rendering is proven here rather than in Playwright, which would add only the browser.

    public function test_the_login_form_shows_the_wait(): void
    {
        $user = $this->user();
        $this->failLogin($user, 5);

        $this->from('/fr/login')->followingRedirects()
            ->post('/fr/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertSee($this->throttled(60));
    }

    public function test_the_challenge_form_shows_the_wait(): void
    {
        [$admin, $secret] = $this->adminWith2fa();
        $this->failChallenge($admin, 5);

        $this->actingAs($admin)->from('/fr/two-factor/challenge')->followingRedirects()
            ->post('/fr/two-factor/challenge', ['code' => $this->validCode($secret)])
            ->assertOk()
            ->assertSee($this->throttled(900));
    }

    public function test_the_setup_form_shows_the_wait(): void
    {
        $user = $this->user();
        $secret = (new Google2FA)->generateSecretKey();
        for ($i = 0; $i < 5; $i++) {
            $this->setupCode($user, $secret, '000000');
        }

        $this->actingAs($user)->withSession(['auth.two_factor_setup_secret' => $secret])
            ->from('/fr/two-factor/setup')->followingRedirects()
            ->post('/fr/two-factor/setup', ['code' => $this->validCode($secret)])
            ->assertOk()
            ->assertSee($this->throttled(900));
    }

    // --- Two-factor ---

    public function test_after_five_wrong_codes_the_challenge_refuses_even_a_valid_code(): void
    {
        [$admin, $secret] = $this->adminWith2fa();
        $this->failChallenge($admin, 5);

        $this->challenge($admin, $this->validCode($secret))
            ->assertSessionHasErrors(['code' => $this->throttled(900)])
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_after_five_wrong_codes_the_setup_refuses_even_a_valid_code(): void
    {
        $user = $this->user();
        $secret = (new Google2FA)->generateSecretKey();
        for ($i = 0; $i < 5; $i++) {
            $this->setupCode($user, $secret, '000000')->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')]);
        }

        $this->setupCode($user, $secret, $this->validCode($secret))->assertSessionHasErrors(['code' => $this->throttled(900)]);

        $this->assertNull($user->fresh()?->two_factor_confirmed_at);
    }

    public function test_setup_and_challenge_share_one_counter(): void
    {
        [$admin, $secret] = $this->adminWith2fa();
        for ($i = 0; $i < 3; $i++) {
            $this->setupCode($admin, $secret, '000000');
        }
        $this->failChallenge($admin, 2);

        $this->challenge($admin, $this->validCode($secret))->assertSessionHasErrors(['code' => $this->throttled(900)]);
    }

    public function test_a_forged_forwarded_for_does_not_reset_the_challenge_limiter(): void
    {
        [$admin, $secret] = $this->adminWith2fa();
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])->challenge($admin, '000000');
        }

        $this->withHeaders(['X-Forwarded-For' => '198.51.100.250'])->challenge($admin, $this->validCode($secret))
            ->assertSessionHasErrors(['code' => $this->throttled(900)]);
    }

    public function test_a_successful_challenge_clears_the_counter(): void
    {
        [$admin, $secret] = $this->adminWith2fa();
        $this->failChallenge($admin, 4);
        $this->challenge($admin, $this->validCode($secret, 0))->assertRedirect($this->dashboard());
        $this->flushSession();

        $this->failChallenge($admin, 4);

        $this->challenge($admin, $this->validCode($secret))->assertRedirect($this->dashboard());
    }

    public function test_a_successful_setup_clears_the_counter(): void
    {
        [$admin, $secret] = $this->adminWith2fa();
        for ($i = 0; $i < 4; $i++) {
            $this->setupCode($admin, $secret, '000000');
        }
        $this->setupCode($admin, $secret, $this->validCode($secret, 0))->assertRedirect($this->dashboard());
        $this->flushSession();

        $this->failChallenge($admin, 4);

        $this->challenge($admin, $this->validCode($secret))->assertRedirect($this->dashboard());
    }

    public function test_a_refused_two_factor_code_is_logged(): void
    {
        [$admin] = $this->adminWith2fa();
        $this->failChallenge($admin, 5);
        $log = Log::spy();

        $this->challenge($admin, '000000');

        $log->shouldHaveReceived('warning')->once()->with(
            'Two-factor code refused — too many failures',
            Mockery::on(fn (array $context): bool => $context['step'] === 'two_factor_throttled'
                && $context['user_id'] === $admin->getKey()
                && $context['retry_after'] === 900),
        );
    }
}
