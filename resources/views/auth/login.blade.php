@extends('layouts.auth')
@section('title', __('auth.login_page_title'))

@section('content')
<div class="border border-primary/30 bg-base-200 p-8">

    <div class="mb-8">
        <p class="text-secondary text-xs tracking-[0.3em] uppercase mb-3 font-mono">
            <span class="text-primary opacity-60">//</span> {{ __('auth.login_subtitle') }}
        </p>
        <h1 class="font-display text-2xl font-bold tracking-wider">
            {{ __('auth.login_heading') }}
        </h1>
    </div>

    <form method="POST" action="{{ route('login.store', ['lang' => app()->getLocale()]) }}" novalidate>
        @csrf

        <div class="form-control mb-4">
            <label class="label" for="email">
                <span class="label-text font-mono text-xs tracking-widest uppercase text-base-content/60">
                    {{ __('auth.login_label_email') }}
                </span>
            </label>
            <input type="email" id="email" name="email"
                   value="{{ old('email') }}"
                   class="input input-bordered input-sm font-mono bg-base-300 w-full {{ $errors->has('email') ? 'border-error' : 'border-primary/30 focus:border-primary' }}"
                   autocomplete="email"
                   required
                   autofocus>
            @error('email')
                <p class="text-error text-xs mt-1 font-mono">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-control mb-6">
            <label class="label" for="password">
                <span class="label-text font-mono text-xs tracking-widest uppercase text-base-content/60">
                    {{ __('auth.login_label_password') }}
                </span>
            </label>
            <input type="password" id="password" name="password"
                   class="input input-bordered input-sm font-mono bg-base-300 w-full {{ $errors->has('password') ? 'border-error' : 'border-primary/30 focus:border-primary' }}"
                   autocomplete="current-password"
                   required>
            @error('password')
                <p class="text-error text-xs mt-1 font-mono">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-control mb-6">
            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" name="remember" class="checkbox checkbox-primary checkbox-xs">
                <span class="label-text font-mono text-xs tracking-wider text-base-content/70">
                    {{ __('auth.login_remember') }}
                </span>
            </label>
        </div>

        <button type="submit"
                class="btn btn-primary w-full font-display tracking-widest uppercase text-sm glow-box">
            {{ __('auth.login_submit') }}
        </button>
    </form>

</div>
@endsection
