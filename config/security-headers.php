<?php

/*
| The security headers every response carries (LUMN-37), built by
| App\Services\Http\SecurityHeaders and set by the SecurityHeaders middleware.
| Whether the site may be indexed lives in config/seo.php, next to the other
| decisions about search engines.
*/

return [

    /*
    | Strict-Transport-Security, sent over HTTPS only.
    |
    | A browser that has seen a max-age refuses plain HTTP for that long, and
    | with includeSubDomains it refuses it on every subdomain — ftp.cardascia-it.org
    | already exists in the zone. Nothing takes it back before it expires. So
    | the duration climbs by steps: one day at launch, one year once every
    | subdomain is known to serve HTTPS. See docs/deployment.md.
    */
    'hsts' => [
        'max_age' => 86400,
        'include_subdomains' => true,
    ],

    /*
    | Content-Security-Policy, sent as Report-Only: it blocks nothing yet.
    |
    | Written in the shape it will have once enforced. A per-request nonce is
    | added to script-src and style-src by the service — the layouts stamp it
    | on their inline scripts, and hand it to Vite through
    | <meta property="csp-nonce"> for the styles it injects in development.
    | Vite's development server is added only on a developer's machine while
    | it runs. Never 'unsafe-inline', never 'unsafe-eval'.
    |
    | One violation is known and tolerated by the end-to-end check: the
    | eval probe vuedraggable bundles, harmless under an enforced policy and
    | removed with vuedraggable itself (LUMN-90).
    |
    | Google Fonts is allowed until LUMN-102 serves the fonts from the site.
    */
    'csp' => [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'", 'https://fonts.googleapis.com'],
        'font-src' => ["'self'", 'https://fonts.gstatic.com'],
        'img-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ],

];
