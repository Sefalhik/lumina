<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@cardascia-it.org')],
            [
                'name' => env('ADMIN_NAME', 'Laurent Bernard-Cardascia'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'changeme')),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }
}
