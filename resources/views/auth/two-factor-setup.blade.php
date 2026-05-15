@extends('layouts.auth')
@section('title', __('auth.setup_page_title'))

@section('content')
<div class="border border-primary/30 bg-base-200 p-8">

    <div class="mb-8">
        <p class="text-secondary text-xs tracking-[0.3em] uppercase mb-3 font-mono">
            <span class="text-primary opacity-60">//</span> {{ __('auth.setup_subtitle') }}
        </p>
        <h1 class="font-display text-2xl font-bold tracking-wider">
            {{ __('auth.setup_heading') }}
        </h1>
        <p class="text-base-content/70 text-sm font-mono mt-2 leading-relaxed">
            {{ __('auth.setup_hint') }}
        </p>
    </div>

    {{-- QR Code --}}
    <div class="flex justify-center mb-6 p-4 bg-white rounded">
        {!! $qrSvg !!}
    </div>

    {{-- Manual key fallback --}}
    <div class="mb-6 p-3 bg-base-300 border border-primary/20">
        <p class="text-[10px] tracking-[0.3em] uppercase text-base-content/60 font-mono mb-1">
            {{ __('auth.setup_manual_key') }}
        </p>
        <p class="font-mono text-sm text-primary break-all tracking-widest select-all">
            {{ $secret }}
        </p>
    </div>

    <form method="POST" action="{{ route('two-factor.setup.store', ['lang' => app()->getLocale()]) }}" novalidate>
        @csrf

        <div class="form-control mb-6">
            <label class="label" for="code">
                <span class="label-text font-mono text-xs tracking-widest uppercase text-base-content/60">
                    {{ __('auth.setup_label_code') }}
                </span>
            </label>
            <input type="text" id="code" name="code"
                   class="input input-bordered font-mono bg-base-300 w-full text-center text-2xl tracking-[0.5em] {{ $errors->has('code') ? 'border-error' : 'border-primary/30 focus:border-primary' }}"
                   autocomplete="one-time-code"
                   inputmode="numeric"
                   pattern="[0-9]{6}"
                   maxlength="6"
                   required
                   autofocus>
            @error('code')
                <p class="text-error text-xs mt-1 font-mono text-center">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit"
                class="btn btn-primary w-full font-display tracking-widest uppercase text-sm glow-box">
            {{ __('auth.setup_submit') }}
        </button>
    </form>

    <div class="mt-6 border-t border-primary/10 pt-4">
        <form method="POST" action="{{ route('logout', ['lang' => app()->getLocale()]) }}">
            @csrf
            <button type="submit"
                    class="btn btn-ghost btn-xs w-full font-mono tracking-wider text-base-content/30 hover:text-base-content/60">
                {{ __('auth.abort_logout') }}
            </button>
        </form>
    </div>

</div>
@endsection
