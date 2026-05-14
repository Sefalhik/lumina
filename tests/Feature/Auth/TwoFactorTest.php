<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertRedirect(route('admin.dashboard', ['lang' => config('app.locale')]));
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

        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
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

        $response->assertSessionHas('auth.two_factor_verified', true);
    }

    public function test_invalid_challenge_code_returns_error(): void
    {
        [$admin] = $this->adminUserWith2fa();

        $this->actingAs($admin)
            ->post('/fr/two-factor/challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('auth.two_factor_verified');
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
