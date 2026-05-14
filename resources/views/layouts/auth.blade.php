<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="sprawl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '// Secure Access') | cardascia-it</title>

    {{-- Anti-FOUC: restore theme before first paint --}}
    <script>
        (function () {
            const saved = localStorage.getItem('theme');
            const valid = ['sprawl', 'steampunk', 'neon-noir'];
            if (saved && valid.includes(saved)) {
                document.documentElement.setAttribute('data-theme', saved);
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Orbitron:wght@400;600;700;900&family=Share+Tech+Mono&family=VT323&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/css/scss/main.scss'])
</head>
<body class="min-h-screen bg-base-100 text-base-content font-mono flex items-center justify-center p-4">

    <div class="scanlines" aria-hidden="true"></div>
    <div class="rain-overlay" aria-hidden="true"></div>

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
