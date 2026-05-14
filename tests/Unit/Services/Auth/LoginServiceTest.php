<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginService $service;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->service = new LoginService;
    }

    public function test_resolves_admin_dashboard_for_admin_role(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            route('admin.dashboard', ['lang' => config('app.locale')]),
            $this->service->resolvePostLoginRedirect($admin)
        );
    }

    public function test_resolves_home_for_user_without_role(): void
    {
        $user = User::factory()->create();

        $this->assertSame(
            route('home', ['lang' => config('app.locale')]),
            $this->service->resolvePostLoginRedirect($user)
        );
    }
}
