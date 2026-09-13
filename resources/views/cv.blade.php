@extends('layouts.app')

@section('title', __('cv.title'))
@section('meta_description', __('cv.meta_description'))

@section('content')

<section class="container mx-auto px-4 pt-24 pb-12">
    <p class="text-base-content/70 text-sm tracking-[0.3em] uppercase mb-4 font-mono">
        <span class="text-primary opacity-60">//</span> {{ __('cv.subtitle') }}
    </p>
    <h1 class="font-display text-4xl md:text-6xl font-bold tracking-tight text-base-content">
        {{ __('cv.heading') }}
    </h1>
</section>

<div class="border-t border-primary/10 mx-4"></div>

<section class="container mx-auto px-4 py-16" aria-labelledby="experience-heading">
    <h2 id="experience-heading"
        class="font-display text-xs tracking-[0.4em] uppercase text-primary mb-12">
        <span class="text-primary glow-primary">›</span> {{ __('cv.experience_heading') }}
    </h2>

    @if (count($timeline) > 0)
    <ol class="max-w-3xl space-y-12">
        @foreach ($timeline as $item)
        <li class="border-l border-primary/20 pl-6 md:pl-8 relative">
            <span class="absolute -left-[5px] top-1.5 block h-2 w-2 rotate-45
                         {{ $item['is_current'] ? 'bg-primary' : 'bg-primary/40' }}"
                  aria-hidden="true"></span>

            <p class="font-mono text-[11px] tracking-[0.25em] uppercase text-base-content/70 mb-2">
                {{ $item['period'] }}
                @if ($item['is_current'])
                <span class="ml-2 border border-primary/40 px-2 py-0.5 text-primary">
                    {{ __('cv.current_badge') }}
                </span>
                @endif
            </p>

            <h3 class="font-display text-xl md:text-2xl text-primary tracking-wide mb-1">
                {{ $item['job_title'] }}
            </h3>

            <p class="font-mono text-sm text-base-content/70 mb-4">
                {{ $item['employer'] }}@if ($item['location']) <span aria-hidden="true">·</span> {{ $item['location'] }}@endif
            </p>

            @if ($item['description'])
            <p class="text-base-content/70 leading-relaxed font-body whitespace-pre-line">
                {{ $item['description'] }}
            </p>
            @endif

            @if ($item['achievements'])
            <p class="mt-4 text-[10px] tracking-[0.3em] uppercase text-base-content/70 font-mono">
                › {{ __('cv.achievements_label') }}
            </p>
            <p class="mt-2 text-base-content/70 leading-relaxed font-body whitespace-pre-line">
                {{ $item['achievements'] }}
            </p>
            @endif
        </li>
        @endforeach
    </ol>
    @else
    <p class="font-mono text-sm text-base-content/70">{{ __('cv.empty') }}</p>
    @endif
</section>

@endsection
