@extends('layouts.admin')
@section('title', __('admin.dashboard_page_title'))

@section('content')

<div class="mb-10">
    <p class="text-secondary text-xs tracking-[0.3em] uppercase mb-3 font-mono">
        <span class="text-primary opacity-60">//</span> {{ __('admin.dashboard_subtitle') }}
    </p>
    <h1 class="font-display text-3xl font-bold tracking-wider neon-pulse">
        {{ __('admin.dashboard_heading') }}
    </h1>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-10">
    <div class="border border-primary/20 bg-base-200 p-6 hover:border-primary/60 transition-all duration-300">
        <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2">{{ __('admin.card_status_label') }}</p>
        <p class="font-display text-sm text-primary">{{ __('admin.card_status_value') }}</p>
    </div>
    <div class="border border-primary/20 bg-base-200 p-6 hover:border-primary/60 transition-all duration-300">
        <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2">{{ __('admin.card_operator_label') }}</p>
        <p class="font-display text-sm text-primary">⬡ {{ auth()->user()->name }}</p>
    </div>
    <div class="border border-primary/20 bg-base-200 p-6 hover:border-primary/60 transition-all duration-300">
        <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2">{{ __('admin.card_security_label') }}</p>
        <p class="font-display text-sm text-primary">{{ __('admin.card_security_value') }}</p>
    </div>
</div>

{{-- Quick access --}}
<div>
    <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-4 font-mono">
        › {{ __('admin.quick_access') }}
    </p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="{{ route('admin.homepage.edit', ['lang' => app()->getLocale()]) }}"
           class="border border-primary/20 bg-base-200 p-6 hover:border-primary/60 hover:glow-box transition-all duration-300 group block">
            <p class="font-display text-sm text-primary group-hover:glow-primary transition-all">
                ◈ {{ __('admin.homepage_nav') }}
            </p>
        </a>
    </div>
</div>

@endsection
