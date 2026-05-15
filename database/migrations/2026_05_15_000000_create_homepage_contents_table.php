<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_contents', function (Blueprint $table) {
            $table->id();
            $table->json('tagline');
            $table->json('subtitle');
            $table->json('bio');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_contents');
    }
};
