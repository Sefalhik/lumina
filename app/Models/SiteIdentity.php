<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Site-wide identity: who runs this site, and where to find them.
 *
 * Single row. Distinct from HomepageContent because these values are used on
 * every page (footer, and structured data later), not just the home page.
 *
 * Nothing here is translated, job_title included. The title is the value that
 * cross-references this site with the GitHub, LinkedIn and Mastodon profiles a
 * sameAs statement points at; those carry one hand-typed title each, so a
 * localised one would only ever have matched in `fr`. docs/site-identity.md
 * records the decision — SiteIdentityTest guards it.
 */
class SiteIdentity extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'full_name',
        'job_title',
        'contact_email',
        'github_url',
        'linkedin_url',
        'mastodon_url',
    ];
}
