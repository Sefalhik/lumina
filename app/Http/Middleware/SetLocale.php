<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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

        // Drop {lang} from the route parameters once it has done its job.
        //
        // Laravel hands a controller its route parameters positionally, so
        // keeping it here means every action on a route that also carries a
        // model — /{lang}/admin/experiences/{experience} — would receive the
        // locale string as its first argument instead of the bound model. The
        // alternative is a `string $lang` first parameter on every such method,
        // which spreads a routing detail through the whole controller layer.
        //
        // Safe because nothing reads the parameter afterwards: URL generation
        // goes through the URL default set above, and LocalizedUrlService always
        // passes an explicit 'lang' when building alternates.
        //
        // The route cannot be null at this point: $lang was read from its own
        // parameters, and a null route would have yielded a null $lang and left
        // through the redirect above. assert() states that invariant rather than
        // hiding it behind a null-safe call whose null branch is unreachable.
        $route = $request->route();
        assert($route instanceof Route);

        $route->forgetParameter('lang');

        return $next($request);
    }
}
