<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ config('themes.default') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('admin.title_default')) | cardascia-it</title>
    <link rel="icon" id="favicon" type="image/svg+xml" href="{{ config('themes.default_favicon') }}">

    @include('layouts.partials.restore-preferences')

    @routes(nonce: Vite::cspNonce())
    {{-- Vite reads the CSP nonce from here for what it injects itself: styles in development,
         preloaded chunks in a build (LUMN-37). --}}
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    @vite(['resources/css/app.css', 'resources/css/scss/main.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-base-100 text-base-content font-mono">

    {{-- Sprawl's sky, as on the public pages: the whole interface is one console (LUMN-103,
         Laurent's decision of 2026-11-12). --}}
    <div id="dead-channel"></div>

    <div class="scanlines" aria-hidden="true"></div>

    <header class="navbar sticky top-0 z-50 bg-base-200/80 backdrop-blur-sm border-b border-primary/20">
        <div class="navbar-start">
            <a href="{{ route('admin.dashboard', ['lang' => app()->getLocale()]) }}"
               class="font-display text-primary tracking-widest hover:glow-primary transition-all">
                CARDASCIA<span class="text-secondary">::</span>IT
                <span class="text-xs text-secondary ml-2 tracking-wider uppercase">{{ __('admin.brand_suffix') }}</span>
            </a>
        </div>

        <div class="navbar-end gap-4">
            {{-- Motion switch (LUMN-11): before the languages, as on the public pages. --}}
            <div id="motion-toggle"></div>
            <div id="language-switcher"></div>
            <div id="theme-switcher"></div>
            <span class="text-xs text-base-content/70 hidden md:block font-mono tracking-wider">
                ⬡ {{ auth()->user()->name }}
            </span>
            <a href="{{ route('home', ['lang' => app()->getLocale()]) }}"
               target="_blank" rel="noopener noreferrer"
               class="btn btn-ghost btn-xs font-display tracking-widest uppercase text-xs border border-primary/20 hover:border-primary/60">
                {{ __('admin.view_site') }}
            </a>
            <form method="POST" action="{{ route('logout', ['lang' => app()->getLocale()]) }}">
                @csrf
                <button type="submit"
                        class="btn btn-ghost btn-xs font-display tracking-widest uppercase text-xs border border-primary/20 hover:border-primary/60">
                    {{ __('admin.logout') }}
                </button>
            </form>
        </div>
    </header>

    <main class="container mx-auto px-4 py-10">
        @if (session('success'))
        <div class="mb-6 border border-primary/40 bg-primary/10 px-5 py-3 font-mono text-xs tracking-widest text-primary uppercase">
            ◈ {{ session('success') }}
        </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
