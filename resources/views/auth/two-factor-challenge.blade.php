@extends('layouts.auth')
@section('title', __('auth.challenge_page_title'))

@section('content')
<div class="border border-primary/30 bg-base-200 p-8">

    <div class="mb-8">
        <p class="text-secondary text-xs tracking-[0.3em] uppercase mb-3 font-mono">
            <span class="text-primary opacity-60">//</span> {{ __('auth.challenge_subtitle') }}
        </p>
        <h1 class="font-display text-2xl font-bold tracking-wider">
            {{ __('auth.challenge_heading') }}
        </h1>
        <p class="text-base-content/50 text-sm font-mono mt-2 leading-relaxed">
            {{ __('auth.challenge_hint') }}
        </p>
    </div>

    <form method="POST" action="{{ route('two-factor.challenge.store', ['lang' => app()->getLocale()]) }}" novalidate>
        @csrf

        <div class="form-control mb-6">
            <label class="label" for="code">
                <span class="label-text font-mono text-xs tracking-widest uppercase text-base-content/60">
                    {{ __('auth.challenge_label_code') }}
                </span>
            </label>
            <input type="text" id="code" name="code"
                   class="input input-bordered font-mono bg-base-300 border-primary/30 focus:border-primary w-full text-center text-2xl tracking-[0.5em] {{ $errors->has('code') ? 'input-error' : '' }}"
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
            {{ __('auth.challenge_submit') }}
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
