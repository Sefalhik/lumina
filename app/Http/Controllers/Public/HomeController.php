<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\HomepageContent;
use App\Services\HomepageContentService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(private readonly HomepageContentService $homepage) {}

    public function index(): View
    {
        $content = HomepageContent::first();

        // Choosing the input stays here on purpose. Resolving it needs three
        // framework things — the current locale, spatie's fallback, and the
        // translator — and HomepageContentService is worth having precisely
        // because it has none of them: prose() is unit-tested against plain
        // strings, with no database and no request. Moving these two lines in
        // would buy nothing and cost that.
        //
        // No is_string() guard: PHPStan 8 does not ask for one, and __() only
        // returns an array for an array translation key. Should this key ever
        // become one, prose(?string) raises a TypeError — which is the loud
        // failure a silent null would have hidden.
        $bio = $content?->getTranslation('bio', app()->getLocale(), true)
            ?? __('home.fallback_bio');

        return view('home', [
            'content' => $content,
            'prose' => $this->homepage->prose($bio),
        ]);
    }
}
