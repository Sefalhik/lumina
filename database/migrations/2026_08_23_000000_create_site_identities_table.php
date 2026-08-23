<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_identities', function (Blueprint $table) {
            $table->id();

            // Every column is nullable: the table starts empty and is filled
            // from the admin UI, so "nothing set yet" is the initial state, not
            // an edge case. The footer must degrade cleanly on all of them.
            $table->string('full_name')->nullable();
            $table->json('job_title')->nullable();      // translated
            $table->string('contact_email')->nullable();
            $table->string('github_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('mastodon_url')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_identities');
    }
};
