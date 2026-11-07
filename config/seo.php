<?php

return [

    /*
    | Route names that may carry canonical and hreflang tags.
    |
    | Deliberately an allowlist, not a denylist. Auth and admin routes live
    | under the same /{lang}/ prefix as public pages, so a denylist that
    | someone forgets to extend would publish the admin URL structure in the
    | <head> of every page. With an allowlist, forgetting a new public route
    | only costs that page its SEO tags — the failure stays silent and
    | harmless instead of leaking structure.
    |
    | Add a route name here when a new PUBLIC page ships.
    | See docs/seo-conventions.md.
    */
    'public_routes' => [
        'home',
        'cv',
        'projects',
        'blog',
        'blog.show',
    ],

    /*
    | Whether search engines may index the site (LUMN-37). False sends
    | X-Robots-Tag: noindex, nofollow on every response.
    |
    | Never deduced from APP_ENV: preprod runs APP_ENV=production on purpose.
    |
    | Only a recognised boolean makes the site indexable. Absent, empty or
    | misspelt ("flase", "oui") reads as false: a site left out of the index
    | by mistake shows and is fixed in one line, a preprod indexed by mistake
    | is not taken back. .env.example says true — the nominal case — while
    | this fallback guards the forgotten one.
    |
    | robots.txt stays permissive on purpose: a crawler forbidden to fetch a
    | page never sees its noindex. See docs/seo-conventions.md.
    */
    'indexable' => filter_var(env('SITE_INDEXABLE'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true,

];
