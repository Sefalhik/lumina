@extends('layouts.app')

@section('title', __('home.title'))
@section('meta_description', $content?->getTranslation('meta_description', app()->getLocale(), true) ?? '')

@section('content')

{{-- Hero --}}
<section class="container mx-auto px-4 pt-24 pb-16 min-h-[80vh] flex flex-col justify-center">
    <div class="max-w-3xl">

        <p class="text-secondary text-sm tracking-[0.3em] uppercase mb-6 font-mono">
            <span class="text-primary opacity-60">//</span>
            <span class="cursor-blink"> {{ $content?->getTranslation('tagline', app()->getLocale(), true) ?? __('home.fallback_tagline') }}</span>
        </p>

        <h1 class="font-display text-5xl md:text-7xl font-bold tracking-tight mb-6 leading-tight">
            <span class="text-base-content block glitch-jitter">Laurent</span>
            <span class="text-primary neon-pulse block glitch" data-text="Bernard-Cardascia">Bernard-Cardascia</span>
        </h1>

        <h2 class="font-display text-base md:text-xl text-primary neon-pulse-slow tracking-widest uppercase mb-10">
            {{ $content?->getTranslation('subtitle', app()->getLocale(), true) ?? __('home.fallback_subtitle') }}
        </h2>

        <p class="text-base-content/70 text-base md:text-lg leading-relaxed max-w-xl mb-12 font-body">
            {{ $content?->getTranslation('bio', app()->getLocale(), true) ?? __('home.fallback_bio') }}
        </p>

        <div class="flex flex-wrap gap-4">
            <a href="{{ route('projects') }}"
               class="btn btn-primary font-display tracking-widest uppercase text-sm glow-box">
                {{ __('home.cta_projects') }}
            </a>
            <a href="{{ route('cv') }}"
               class="btn btn-outline btn-secondary font-display tracking-widest uppercase text-sm">
                {{ __('home.cta_cv') }}
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
        <span class="text-primary glow-primary">›</span> {{ __('home.skills_heading') }}
    </h2>

    @php
        $skillsJson = $content?->getTranslation('skills', app()->getLocale(), true) ?? '[]';
        $skills = is_string($skillsJson) ? json_decode($skillsJson, true) : [];
        $skills = is_array($skills) ? $skills : [];
    @endphp

    @if (count($skills) > 0)
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach ($skills as $category)
        <div class="border border-primary/20 bg-base-200 p-6
                    hover:border-primary/60 hover:glow-box
                    transition-all duration-300 group">
            <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-3 font-mono">
                {{ $category['icon'] ?? '⬡' }} {{ $category['name'] ?? '' }}
            </p>
            <div class="flex flex-wrap gap-2">
                @foreach ($category['techs'] ?? [] as $tech)
                <span class="text-xs font-mono text-primary/80 border border-primary/20 px-2 py-0.5
                             group-hover:border-primary/40 transition-colors">
                    {{ $tech }}
                </span>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ((array) __('home.fallback_skills') as $item)
        <div class="border border-primary/20 bg-base-200 p-5
                    hover:border-primary/60 hover:glow-box
                    transition-all duration-300 group cursor-default">
            <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2">
                {{ $item['category'] }}
            </p>
            <p class="font-display text-sm text-primary group-hover:glow-primary transition-all">
                {{ $item['icon'] }} {{ $item['tech'] }}
            </p>
        </div>
        @endforeach
    </div>
    @endif
</section>

@endsection
