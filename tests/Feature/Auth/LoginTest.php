<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_valid_credentials_authenticate_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->post('/fr/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $this->assertAuthenticated();
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
