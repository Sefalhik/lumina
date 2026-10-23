<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LUMN-91 — the time step of the last two-factor code accepted, so that a code cannot be used twice
 * within its own window. Null means no code has been accepted yet.
 *
 * Nullable and unread by the release before it, so a rollback can still run against this schema
 * (docs/deployment.md, "Migrations run before the switch"). An integer holds the step comfortably:
 * Unix time divided by 30 stays under 2^31 for another two thousand years.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('two_factor_last_used_timestep')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_last_used_timestep');
        });
    }
};
