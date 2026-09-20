<?php

declare(strict_types=1);

namespace App\Services\Smoke\Site;

/**
 * The little HTML reading the probes need. Pure functions, no state, no dependency: regular
 * expressions are enough for a handful of tags in a page we control, and a DOM parser would
 * refuse the markup a broken deployment produces — which is exactly when these are read.
 */
final class Html
{
    public static function canonical(string $html): ?string
    {
        if (preg_match('/<link\b[^>]*\brel="canonical"[^>]*>/i', $html, $tag) !== 1) {
            return null;
        }

        return self::attribute($tag[0], 'href');
    }

    /**
     * @return list<string> URLs of the compiled stylesheets and module scripts the page loads
     */
    public static function compiledAssets(string $html): array
    {
        preg_match_all('/<link\b[^>]*\brel="stylesheet"[^>]*>|<script\b[^>]*\bsrc="[^"]*"[^>]*>/i', $html, $tags);

        $assets = [];
        foreach ($tags[0] as $tag) {
            $url = self::attribute($tag, str_starts_with(strtolower($tag), '<link') ? 'href' : 'src');
            if ($url !== null && str_contains($url, '/build/')) {
                $assets[] = $url;
            }
        }

        return array_values(array_unique($assets));
    }

    /**
     * The text of the element carrying data-smoke="…", or null when the page has none.
     */
    public static function markerText(string $html, string $marker): ?string
    {
        $pattern = '/<([a-z][a-z0-9]*)\b[^>]*\bdata-smoke="'.preg_quote($marker, '/').'"[^>]*>(.*?)<\/\1>/is';

        return preg_match($pattern, $html, $element) === 1 ? trim(strip_tags($element[2])) : null;
    }

    public static function attribute(string $tag, string $name): ?string
    {
        return preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/i', $tag, $value) === 1
            ? html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5)
            : null;
    }
}
