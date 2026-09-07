<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiences', function (Blueprint $table) {
            $table->id();

            // Never translated: proper nouns and dates. job_title is the value a
            // sameAs statement cross-references with the LinkedIn profile, which
            // carries one hand-typed title — the same reasoning that made
            // SiteIdentity.job_title a plain column. See docs/site-identity.md.
            $table->string('employer');
            $table->string('job_title');
            $table->string('location')->nullable();

            $table->date('started_at');

            // NULL means "still in this position" — a business state, not missing
            // data. Experience::isCurrent() and scopeCurrent() name it so nobody
            // has to be told twice; ExperienceTest asserts it.
            $table->date('ended_at')->nullable();

            // Prose, translated by cms:translate once the model is registered in
            // config('i18n.cms_models').
            $table->json('description')->nullable();
            $table->json('achievements')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiences');
    }
};
