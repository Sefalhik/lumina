<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('homepage_contents', function (Blueprint $table) {
            $table->json('meta_description')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('homepage_contents', function (Blueprint $table) {
            $table->dropColumn('meta_description');
        });
    }
};
