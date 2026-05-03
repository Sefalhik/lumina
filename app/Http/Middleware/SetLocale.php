<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->route('lang');
        $supported = config('i18n.supported_locales', ['fr', 'en']);

        if (! in_array($lang, $supported, true)) {
            return redirect()->to('/'.config('i18n.default_locale', 'fr'));
        }

        app()->setLocale($lang);
        URL::defaults(['lang' => $lang]);

        return $next($request);
    }
}
