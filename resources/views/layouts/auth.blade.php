<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ config('themes.default') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '// Secure Access') | cardascia-it</title>

    @include('layouts.partials.restore-preferences')

    {{-- Vite reads the CSP nonce from here for what it injects itself: styles in development,
         preloaded chunks in a build (LUMN-37). --}}
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    {{-- The script carries one island here, the motion switch (LUMN-11): the login page has its
         rain and its flicker too, and every visitor must be able to stop them. --}}
    @vite(['resources/css/app.css', 'resources/css/scss/main.scss', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-100 text-base-content font-mono flex items-center justify-center p-4">

    <div class="scanlines" aria-hidden="true"></div>
    <div class="rain-overlay" aria-hidden="true"></div>

    <div class="fixed top-2 right-2 z-50">
        <div id="motion-toggle"></div>
    </div>

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="/" class="font-display text-primary text-2xl tracking-widest hover:glow-primary transition-all">
                CARDASCIA<span class="text-secondary">::</span>IT
            </a>
        </div>

        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>
