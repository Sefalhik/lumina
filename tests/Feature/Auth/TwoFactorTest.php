<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        // The service reads the step from Carbon: an absolute moment makes every code below
        // decided by this date, never by the moment the suite runs.
        $this->travelTo(Carbon::parse('2026-10-23 12:00:10', 'UTC'));
    }

    /** The code of the step $offset steps from now, on the frozen clock. */
    private function codeAt(string $secret, int $offset): string
    {
        return (new Google2FA)->oathTotp($secret, intdiv(Carbon::now()->getTimestamp(), 30) + $offset);
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** @return array{0: User, 1: string} */
    private function adminUserWith2fa(): array
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $admin = $this->adminUser();
        $admin->two_factor_secret = $secret;
        $admin->two_factor_confirmed_at = now();
        $admin->save();

        return [$admin, $secret];
    }

    // --- Setup page ---

    public function test_setup_page_requires_authentication(): void
    {
        $this->get('/fr/two-factor/setup')
            ->assertRedirect(route('login', ['lang' => 'fr']));
    }

    public function test_admin_without_2fa_is_redirected_to_setup_when_accessing_admin(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get('/fr/admin/')
            ->assertRedirect(route('two-factor.setup', ['lang' => 'fr']));
    }

    public function test_setup_page_returns_200_with_qr_code(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get('/fr/two-factor/setup');

        $response->assertStatus(200);
        $response->assertSee('<svg', false);
    }

    public function test_setup_page_keeps_the_same_secret_across_refreshes(): void
    {
        // A secret regenerated on each display would invalidate the QR code already scanned.
        $admin = $this->adminUser();

        $first = $this->actingAs($admin)->get('/fr/two-factor/setup')->viewData('secret');
        $second = $this->actingAs($admin)->get('/fr/two-factor/setup')->viewData('secret');

        $this->assertIsString($first);
        $this->assertSame($first, $second);
        $this->assertSame($first, session('auth.two_factor_setup_secret'));
    }

    public function test_setup_page_redirects_to_challenge_when_already_confirmed(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->get('/fr/two-factor/setup')
            ->assertRedirect(route('two-factor.challenge', ['lang' => 'fr']));
    }

    public function test_valid_setup_code_confirms_2fa_and_redirects_to_admin(): void
    {
        $admin = $this->adminUser();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $response = $this->actingAs($admin)
            ->withSession(['auth.two_factor_setup_secret' => $secret])
            ->post('/fr/two-factor/setup', ['code' => $this->codeAt($secret, 0)]);

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $response->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]))
            // The temporary secret is gone once persisted, and the code just typed counts as this
            // session's verification: no second challenge on the way to the dashboard.
            ->assertSessionMissing('auth.two_factor_setup_secret')
            ->assertSessionHas('auth.two_factor_verified', true);
    }

    public function test_invalid_setup_code_returns_error_and_does_not_confirm(): void
    {
        $admin = $this->adminUser();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $this->actingAs($admin)
            ->withSession(['auth.two_factor_setup_secret' => $secret])
            ->post('/fr/two-factor/setup', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull($admin->fresh()?->two_factor_confirmed_at);
    }

    public function test_missing_session_secret_returns_error_on_setup(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post('/fr/two-factor/setup', ['code' => '123456'])
            ->assertSessionHasErrors('code');
    }

    // --- Challenge page ---

    public function test_challenge_page_requires_authentication(): void
    {
        $this->get('/fr/two-factor/challenge')
            ->assertRedirect(route('login', ['lang' => 'fr']));
    }

    public function test_admin_with_confirmed_2fa_is_redirected_to_challenge(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->get('/fr/admin/')
            ->assertRedirect(route('two-factor.challenge', ['lang' => 'fr']));
    }

    public function test_challenge_page_redirects_to_admin_when_already_verified(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->withSession(['auth.two_factor_verified' => true])
            ->get('/fr/two-factor/challenge')
            ->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]));
    }

    public function test_valid_challenge_code_sets_session_flag_and_redirects(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $response = $this->actingAs($admin)
            ->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)]);

        // The response too, not only the flag: the flag is set before the redirect is built.
        $response->assertSessionHas('auth.two_factor_verified', true)
            ->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]));
    }

    public function test_a_valid_challenge_returns_to_the_page_that_asked_for_it(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->withSession(['url.intended' => url('/fr/admin/cv')])
            ->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)])
            ->assertRedirect(url('/fr/admin/cv'));
    }

    public function test_challenge_page_is_shown_to_a_confirmed_admin_not_yet_verified(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->get('/fr/two-factor/challenge')
            ->assertOk()
            ->assertViewIs('auth.two-factor-challenge');
    }

    public function test_challenge_page_sends_a_user_without_2fa_to_setup(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/fr/two-factor/challenge')
            ->assertRedirect(route('two-factor.setup', ['lang' => 'fr']));
    }

    public function test_a_challenge_submitted_without_2fa_is_sent_to_setup(): void
    {
        $this->actingAs($this->adminUser())
            ->post('/fr/two-factor/challenge', ['code' => '123456'])
            ->assertRedirect(route('two-factor.setup', ['lang' => 'fr']))
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_invalid_challenge_code_returns_error(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->post('/fr/two-factor/challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('auth.two_factor_verified');
    }

    // --- Replay (LUMN-91): a code accepted once is refused the second time ---
    // Codes are built for explicit time steps: Google2FA reads the real clock, and the ±1 window
    // accepts the step before and after the current one.

    public function test_the_code_that_confirmed_setup_is_refused_at_the_next_challenge(): void
    {
        $admin = $this->adminUser();
        $secret = (new Google2FA)->generateSecretKey();
        $code = $this->codeAt($secret, 0);
        $this->actingAs($admin)->withSession(['auth.two_factor_setup_secret' => $secret])
            ->post('/fr/two-factor/setup', ['code' => $code])
            ->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]));
        $this->flushSession();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $code])
            ->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')])
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_a_challenge_code_is_refused_the_second_time_like_a_wrong_one(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $code = $this->codeAt($secret, 0);
        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $code])
            ->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]));
        $this->flushSession();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $code])
            ->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')])
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_the_next_code_is_still_accepted_after_a_replay(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)]);
        $this->flushSession();
        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)]);

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 1)])
            ->assertSessionHas('auth.two_factor_verified', true);
    }

    public function test_replayed_codes_count_as_failed_attempts(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $used = $this->codeAt($secret, 0);
        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $used]);
        $this->flushSession();
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $used]);
        }

        // Five replays have used up the five attempts of LUMN-36: even a fresh code is refused.
        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 1)])
            ->assertSessionHasErrors(['code' => __('auth.throttle', ['seconds' => 900], 'fr')]);
    }

    public function test_a_replayed_challenge_code_is_logged(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $code = $this->codeAt($secret, 0);
        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $code]);
        $this->flushSession();
        $log = Log::spy();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $code]);

        $log->shouldHaveReceived('warning')->with(
            'Two-factor code refused — already used',
            Mockery::on(fn (array $context): bool => $context['step'] === 'code_replayed'
                && $context['user_id'] === $admin->getKey()),
        )->once();
    }

    // --- The improbable, seen from the challenge: refused like a wrong code, never a 500 ---

    public function test_a_stored_secret_that_cannot_be_decrypted_refuses_the_challenge_without_a_500(): void
    {
        // As after APP_KEY was rotated without re-encrypting the column.
        [$admin] = $this->adminUserWith2fa();
        DB::table('users')->where('id', $admin->id)->update(['two_factor_secret' => 'not a ciphertext']);

        $this->actingAs($admin->fresh())->post('/fr/two-factor/challenge', ['code' => '123456'])
            ->assertRedirect()
            ->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')])
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_a_confirmed_2fa_without_a_secret_is_refused_not_sent_to_setup(): void
    {
        // Sending it to setup would let a new secret be enrolled behind the password alone.
        [$admin] = $this->adminUserWith2fa();
        DB::table('users')->where('id', $admin->id)->update(['two_factor_secret' => null]);

        $this->actingAs($admin->fresh())->post('/fr/two-factor/challenge', ['code' => '123456'])
            ->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')])
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_a_last_step_in_the_future_refuses_even_the_current_code(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $admin->forceFill(['two_factor_last_used_timestep' => intdiv(Carbon::now()->getTimestamp(), 30) + 2])->save();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)])
            ->assertSessionHasErrors(['code' => __('auth.two_factor_invalid_code', [], 'fr')])
            ->assertSessionMissing('auth.two_factor_verified');
    }

    public function test_a_successful_challenge_costs_one_query_on_users(): void
    {
        // The user is already loaded by the auth middleware: recording the code is one UPDATE by
        // primary key, and nothing reads the table again.
        [$admin, $secret] = $this->adminUserWith2fa();
        DB::enableQueryLog();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)])
            ->assertSessionHas('auth.two_factor_verified', true);

        $onUsers = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains($query['query'], '"users"'),
        ));
        $this->assertCount(1, $onUsers);
        $this->assertStringStartsWith('update "users"', $onUsers[0]['query']);
    }

    // --- Logging: each step names itself, with the mandatory context ---

    private function assertLogged(MockInterface $log, string $level, string $message, string $method, string $step): void
    {
        // with() before once(): the count is then of matching calls, not of every call.
        $log->shouldHaveReceived($level)->with(
            $message,
            Mockery::on(fn (array $context): bool => $context === [
                'service' => TwoFactorController::class,
                'method' => $method,
                'step' => $step,
            ]),
        )->once();
    }

    public function test_generating_a_setup_secret_is_logged(): void
    {
        $log = Log::spy();

        $this->actingAs($this->adminUser())->get('/fr/two-factor/setup');

        $this->assertLogged($log, 'info', 'Two-factor setup initiated — secret generated', 'showSetup', 'secret_generated');
    }

    public function test_a_setup_without_a_pending_secret_is_logged(): void
    {
        $log = Log::spy();

        $this->actingAs($this->adminUser())->post('/fr/two-factor/setup', ['code' => '123456']);

        $this->assertLogged($log, 'warning', 'Two-factor setup submission with no pending secret in session', 'storeSetup', 'setup_secret_missing');
    }

    public function test_a_wrong_setup_code_is_logged(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $log = Log::spy();

        $this->actingAs($this->adminUser())
            ->withSession(['auth.two_factor_setup_secret' => $secret])
            ->post('/fr/two-factor/setup', ['code' => '000000']);

        $this->assertLogged($log, 'warning', 'Two-factor setup failed — invalid TOTP code submitted', 'storeSetup', 'setup_code_invalid');
    }

    public function test_a_completed_setup_is_logged(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $log = Log::spy();

        $this->actingAs($this->adminUser())
            ->withSession(['auth.two_factor_setup_secret' => $secret])
            ->post('/fr/two-factor/setup', ['code' => $this->codeAt($secret, 0)]);

        $this->assertLogged($log, 'info', 'Two-factor setup completed successfully', 'storeSetup', 'setup_confirmed');
    }

    public function test_a_wrong_challenge_code_is_logged(): void
    {
        [$admin] = $this->adminUserWith2fa();
        $log = Log::spy();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => '000000']);

        $this->assertLogged($log, 'warning', 'Two-factor challenge failed — invalid TOTP code submitted', 'verifyChallenge', 'challenge_code_invalid');
    }

    public function test_a_passed_challenge_is_logged(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();
        $log = Log::spy();

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => $this->codeAt($secret, 0)]);

        $this->assertLogged($log, 'info', 'Two-factor challenge passed', 'verifyChallenge', 'challenge_verified');
    }

    // --- Admin access ---

    public function test_verified_admin_can_access_admin_dashboard(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->withSession(['auth.two_factor_verified' => true])
            ->get('/fr/admin/')
            ->assertStatus(200);
    }

    public function test_non_admin_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->get('/fr/admin/')
            ->assertForbidden();
    }
}
