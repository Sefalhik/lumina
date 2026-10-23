<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_accessible(): void
    {
        $this->get('/fr/login')->assertStatus(200);
    }

    public function test_authenticated_user_is_redirected_from_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/fr/login')
            ->assertRedirect();
    }

    public function test_valid_credentials_authenticate_user_and_redirect_to_their_destination(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        // The response is asserted, not only the session: a login that authenticates and then
        // fails to answer is a 500 with the user logged in (LUMN-36 mutation pass).
        $this->post('/fr/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('home', ['lang' => config('app.locale')]));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_login_returns_to_the_page_that_asked_for_it(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->withSession(['url.intended' => url('/fr/cv')])
            ->post('/fr/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(url('/fr/cv'));
    }

    public function test_a_successful_login_renews_the_session_id_and_the_csrf_token(): void
    {
        // Session fixation: an id or a token known before login must not survive it. Asserted as
        // a behaviour, whoever provides it — today SessionGuard::updateSession(), through
        // regenerate(true). LoginController's own regenerate() call was removed on LUMN-36 as a
        // guard that could not fire; this test is what notices if the framework ever stops.
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $this->startSession();
        $id = session()->getId();
        $token = session()->token();

        $this->post('/fr/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
    }

    public function test_a_successful_login_is_logged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $log = Log::spy();

        $this->post('/fr/login', ['email' => $user->email, 'password' => 'correct-password']);

        $log->shouldHaveReceived('info')->once()->with(
            'User authenticated successfully',
            Mockery::on(fn (array $context): bool => $context === [
                'service' => LoginController::class,
                'method' => 'store',
                'step' => 'login_success',
            ]),
        );
    }

    public function test_invalid_password_returns_validation_error(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->post('/fr/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_unknown_email_returns_validation_error(): void
    {
        $this->post('/fr/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_unauthenticates_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/fr/logout')
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->post('/fr/logout')->assertRedirect(route('login', ['lang' => 'fr']));
    }
}
