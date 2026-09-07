<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turns site_identities.job_title from a translations JSON object into a plain
 * string column.
 *
 * The job title is the value a search engine cross-references between this
 * site, GitHub, LinkedIn and Mastodon. Those three carry one hand-typed title;
 * translating ours would have made the cross-reference hold for `fr` only. See
 * docs/site-identity.md for the full reasoning.
 *
 * Raw SQL rather than ->change(): PostgreSQL refuses a json → varchar cast
 * without an explicit USING clause, which the schema builder does not emit.
 * Nulls survive both ways — every identity column is nullable by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE site_identities
             ALTER COLUMN job_title TYPE varchar(255)
             USING (job_title::jsonb ->> 'fr')"
        );
    }

    /**
     * Rebuilds the translations object around the French value, which is the
     * only locale the column ever held.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE site_identities
             ALTER COLUMN job_title TYPE json
             USING (CASE WHEN job_title IS NULL THEN NULL ELSE json_build_object('fr', job_title) END)"
        );
    }
};
