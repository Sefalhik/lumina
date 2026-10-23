<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What the User model promises by declaration alone — hidden columns, mass-assignable columns,
 * casts. The mechanical pass of LUMN-91 deleted each of these lines without a test noticing: the
 * factory hashes passwords itself, and PostgreSQL already returns integers, which hid two of them.
 */
class UserTest extends TestCase
{
    public function test_secrets_never_leave_the_model_when_it_is_serialised(): void
    {
        $user = (new User)->forceFill([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'irrelevant',
            'remember_token' => 'token',
            'two_factor_secret' => 'SECRET',
            'two_factor_last_used_timestep' => 1000,
        ]);

        $serialised = $user->toArray();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_last_used_timestep'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialised);
        }
        $this->assertSame('admin@example.com', $serialised['email']);
    }

    public function test_only_the_identity_columns_can_be_mass_assigned(): void
    {
        $user = new User([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'irrelevant',
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => '2026-10-23 10:00:00',
            'two_factor_last_used_timestep' => 1000,
        ]);

        $this->assertSame('admin@example.com', $user->email);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_used_timestep);
    }

    public function test_a_password_assigned_in_clear_is_stored_hashed(): void
    {
        $user = new User(['password' => 'correct-password']);

        $this->assertNotSame('correct-password', $user->password);
        $this->assertTrue(Hash::check('correct-password', $user->password));
    }

    public function test_columns_read_from_the_database_get_their_types(): void
    {
        // Raw values as a driver may hand them over, strings included: each cast is proven by its
        // own effect, not by what PostgreSQL happens to return.
        $user = (new User)->setRawAttributes([
            'email_verified_at' => '2026-10-23 10:00:00',
            'two_factor_confirmed_at' => '2026-10-23 10:00:00',
            'two_factor_last_used_timestep' => '1000',
        ]);

        $this->assertInstanceOf(CarbonInterface::class, $user->email_verified_at);
        $this->assertInstanceOf(CarbonInterface::class, $user->two_factor_confirmed_at);
        $this->assertSame(1000, $user->two_factor_last_used_timestep);
    }
}
