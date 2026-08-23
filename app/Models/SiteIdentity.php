<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Site-wide identity: who runs this site, and where to find them.
 *
 * Single row. Distinct from HomepageContent because these values are used on
 * every page (footer, and structured data later), not just the home page.
 */
class SiteIdentity extends Model
{
    use HasTranslations;

    /**
     * Only job_title is translated. Names, emails and profile URLs are the same
     * in every language — listing them here would store pointless JSON and hand
     * them to cms:translate for no reason.
     *
     * @var list<string>
     */
    public array $translatable = ['job_title'];

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
