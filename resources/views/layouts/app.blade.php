<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="sprawl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('meta_description', 'Laurent Bernard-Cardascia — Lead Developer')">
    <title>@yield('title', 'cardascia-it') | Laurent Bernard-Cardascia</title>
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

    {{-- Google Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Orbitron:wght@400;600;700;900&family=Share+Tech+Mono&family=VT323&display=swap" rel="stylesheet">

    @routes
    @vite(['resources/css/app.css', 'resources/css/scss/main.scss', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen bg-base-100 text-base-content font-mono">

    {{-- Boot sequence island (once per session) --}}
    <div id="boot-sequence"></div>

    {{-- Atmospheric overlays --}}
    <div class="scanlines" aria-hidden="true"></div>
    <div class="rain-overlay" aria-hidden="true"></div>

    <header class="navbar sticky top-0 z-50 bg-base-200/80 backdrop-blur-sm border-b border-primary/20">
        <div class="navbar-start">
            <a href="{{ route('home') }}" class="font-display text-primary text-xl tracking-widest hover:glow-primary transition-all">
                CARDASCIA<span class="text-secondary">::</span>IT
            </a>
        </div>

        <nav class="navbar-center hidden md:flex" aria-label="Navigation principale">
            <ul class="menu menu-horizontal gap-1 text-sm tracking-widest uppercase">
                <li><a href="{{ route('home') }}" class="hover:text-primary transition-colors">{{ __('nav.home') }}</a></li>
                <li><a href="{{ route('cv') }}" class="hover:text-primary transition-colors">{{ __('nav.cv') }}</a></li>
                <li><a href="{{ route('projects') }}" class="hover:text-primary transition-colors">{{ __('nav.projects') }}</a></li>
                <li><a href="{{ route('blog') }}" class="hover:text-primary transition-colors">{{ __('nav.blog') }}</a></li>
            </ul>
        </nav>

        <div class="navbar-end gap-2">
            {{-- Language Switcher Vue island --}}
            <div id="language-switcher"></div>

            {{-- Theme Switcher Vue island --}}
            <div id="theme-switcher"></div>

            {{-- Mobile menu --}}
            <div class="dropdown dropdown-end md:hidden">
                <button tabindex="0" class="btn btn-ghost btn-sm" aria-label="Menu">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <ul tabindex="0" class="dropdown-content menu bg-base-200 border border-primary/30 w-52 mt-2 text-sm tracking-widest uppercase">
                    <li><a href="{{ route('home') }}">{{ __('nav.home') }}</a></li>
                    <li><a href="{{ route('cv') }}">{{ __('nav.cv') }}</a></li>
                    <li><a href="{{ route('projects') }}">{{ __('nav.projects') }}</a></li>
                    <li><a href="{{ route('blog') }}">{{ __('nav.blog') }}</a></li>
                </ul>
            </div>
        </div>
    </header>

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="border-t border-primary/20 mt-24 py-8">
        <div class="container mx-auto px-4 text-center text-sm tracking-widest">
            <p class="font-mono text-base-content/70">&copy; {{ date('Y') }} Laurent Bernard-Cardascia — <span class="text-primary">cardascia-it.org</span></p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
