<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="sprawl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('admin.title_default')) | cardascia-it</title>
    <link rel="icon" id="favicon" type="image/svg+xml" href="/favicons/favicon-sprawl.svg">

    {{-- Anti-FOUC: restore theme and matching favicon from localStorage before first paint --}}
    <script>
        (function () {
            const saved = localStorage.getItem('theme');
            const valid = ['sprawl', 'steampunk', 'neon-noir'];
            if (saved && valid.includes(saved)) {
                document.documentElement.setAttribute('data-theme', saved);
                document.getElementById('favicon').href = '/favicons/favicon-' + saved + '.svg';
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Orbitron:wght@400;600;700;900&family=Share+Tech+Mono&family=VT323&display=swap" rel="stylesheet">

    @routes
    @vite(['resources/css/app.css', 'resources/css/scss/main.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-base-100 text-base-content font-mono">

    <div class="scanlines" aria-hidden="true"></div>

    <header class="navbar sticky top-0 z-50 bg-base-200/80 backdrop-blur-sm border-b border-primary/20">
        <div class="navbar-start">
            <a href="{{ route('admin.dashboard', ['lang' => app()->getLocale()]) }}"
               class="font-display text-primary tracking-widest hover:glow-primary transition-all">
                CARDASCIA<span class="text-secondary">::</span>IT
                <span class="text-xs text-secondary/50 ml-2 tracking-wider uppercase">{{ __('admin.brand_suffix') }}</span>
            </a>
        </div>

        <div class="navbar-end gap-4">
            <div id="language-switcher"></div>
            <div id="theme-switcher"></div>
            <span class="text-xs text-base-content/50 hidden md:block font-mono tracking-wider">
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
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
