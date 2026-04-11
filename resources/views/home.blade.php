@extends('layouts.app')

@section('title', 'Accueil')
@section('meta_description', 'Laurent Bernard-Cardascia — Lead Developer & Software Engineer. Développement web, architecture logicielle, et passion pour le code propre.')

@section('content')

{{-- Hero --}}
<section class="container mx-auto px-4 pt-24 pb-16 min-h-[80vh] flex flex-col justify-center">
    <div class="max-w-3xl">

        <p class="text-secondary text-sm tracking-[0.3em] uppercase mb-6 font-mono">
            <span class="text-primary opacity-60">//</span>
            <span class="cursor-blink"> Initializing connection</span>
        </p>

        <h1 class="font-display text-5xl md:text-7xl font-bold tracking-tight mb-6 leading-tight">
            <span class="text-base-content block glitch-jitter">Laurent</span>
            <span class="text-primary neon-pulse block glitch" data-text="Bernard-Cardascia">Bernard-Cardascia</span>
        </h1>

        <h2 class="font-display text-base md:text-xl text-primary neon-pulse-slow tracking-widest uppercase mb-10">
            Lead Developer <span class="text-primary opacity-40 mx-2">//</span> Software Engineer
        </h2>

        <p class="text-base-content/70 text-base md:text-lg leading-relaxed max-w-xl mb-12 font-body">
            Architecte de systèmes, artisan du code propre. Je construis des applications
            robustes et des équipes qui durent — quelque part entre la console et les étoiles.
        </p>

        <div class="flex flex-wrap gap-4">
            <a href="{{ route('projects') }}"
               class="btn btn-primary font-display tracking-widest uppercase text-sm glow-box">
                Voir mes projets
            </a>
            <a href="{{ route('cv') }}"
               class="btn btn-outline btn-secondary font-display tracking-widest uppercase text-sm">
                Mon CV
            </a>
        </div>
    </div>
</section>

{{-- Divider --}}
<div class="border-t border-primary/10 mx-4"></div>

{{-- Skills snapshot --}}
<section class="container mx-auto px-4 py-20" aria-labelledby="skills-heading">
    <h2 id="skills-heading"
        class="font-display text-xs tracking-[0.4em] uppercase text-primary mb-10">
        <span class="text-primary glow-primary">›</span> Stack &amp; expertise
    </h2>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ([
            ['PHP / Laravel',      'Backend',    '⬡'],
            ['Vue 3 / TypeScript', 'Frontend',   '◈'],
            ['PostgreSQL',         'Data',        '⬡'],
            ['Architecture',       'Design',      '◈'],
        ] as [$tech, $category, $icon])
        <div class="border border-primary/20 bg-base-200 p-5
                    hover:border-primary/60 hover:glow-box
                    transition-all duration-300 group cursor-default">
            <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2">
                {{ $category }}
            </p>
            <p class="font-display text-sm text-primary group-hover:glow-primary transition-all">
                {{ $icon }} {{ $tech }}
            </p>
        </div>
        @endforeach
    </div>
</section>

@endsection
