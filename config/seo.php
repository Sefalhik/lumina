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

];
