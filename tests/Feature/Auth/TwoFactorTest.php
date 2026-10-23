<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->post('/fr/two-factor/setup', ['code' => $google2fa->getCurrentOtp($secret)]);

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
        $google2fa = new Google2FA;

        $response = $this->actingAs($admin)
            ->post('/fr/two-factor/challenge', ['code' => $google2fa->getCurrentOtp($secret)]);

        // The response too, not only the flag: the flag is set before the redirect is built.
        $response->assertSessionHas('auth.two_factor_verified', true)
            ->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]));
    }

    public function test_a_valid_challenge_returns_to_the_page_that_asked_for_it(): void
    {
        [$admin, $secret] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->withSession(['url.intended' => url('/fr/admin/cv')])
            ->post('/fr/two-factor/challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)])
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
            ->post('/fr/two-factor/setup', ['code' => $google2fa->getCurrentOtp($secret)]);

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

        $this->actingAs($admin)->post('/fr/two-factor/challenge', ['code' => (new Google2FA)->getCurrentOtp($secret)]);

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
