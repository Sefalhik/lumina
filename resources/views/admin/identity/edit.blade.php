@extends('layouts.admin')
@section('title', __('admin.identity_edit_title'))

@section('content')

<div class="mb-10">
    <p class="text-secondary text-xs tracking-[0.3em] uppercase mb-3 font-mono">
        <span class="text-primary opacity-60">//</span> {{ __('admin.identity_edit_subtitle') }}
    </p>
    <h1 class="font-display text-3xl font-bold tracking-wider neon-pulse">
        {{ __('admin.identity_edit_heading') }}
    </h1>
</div>

@if ($errors->any())
<div class="mb-6 border border-error/40 bg-error/10 px-5 py-3 font-mono text-xs tracking-widest text-error uppercase">
    @foreach ($errors->all() as $error)
    <p>✗ {{ $error }}</p>
    @endforeach
</div>
@endif

<form method="POST" action="{{ route('admin.identity.update', ['lang' => app()->getLocale()]) }}">
    @csrf
    @method('PUT')

    {{-- Full name --}}
    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="full_name">
            › {{ __('admin.identity_field_full_name') }}
        </label>
        <input id="full_name" type="text" name="full_name"
               value="{{ old('full_name', $identity->full_name) }}"
               maxlength="120"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('full_name') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>

    {{-- Job title — one value for every locale, see docs/site-identity.md --}}
    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="job_title">
            › {{ __('admin.identity_field_job_title') }}
        </label>
        <input id="job_title" type="text" name="job_title"
               value="{{ old('job_title', $identity->job_title) }}"
               maxlength="120"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('job_title') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
        <p class="mt-2 text-[10px] tracking-widest uppercase text-base-content/60 font-mono">
            {{ __('admin.identity_hint_job_title') }}
        </p>
    </div>

    {{-- Contact email --}}
    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="contact_email">
            › {{ __('admin.identity_field_contact_email') }}
        </label>
        <input id="contact_email" type="email" name="contact_email"
               value="{{ old('contact_email', $identity->contact_email) }}"
               maxlength="180"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('contact_email') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>

    <div class="my-10 border-t border-primary/10"></div>

    {{-- Profile URLs --}}
    @foreach ([
        'github_url' => __('admin.identity_field_github'),
        'linkedin_url' => __('admin.identity_field_linkedin'),
        'mastodon_url' => __('admin.identity_field_mastodon'),
    ] as $field => $label)
    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="{{ $field }}">
            › {{ $label }}
        </label>
        <input id="{{ $field }}" type="url" name="{{ $field }}"
               value="{{ old($field, $identity->{$field}) }}"
               maxlength="255"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has($field) ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>
    @endforeach

    <p class="mb-8 text-[10px] tracking-widest uppercase text-base-content/60 font-mono">
        {{ __('admin.identity_hint_optional') }}
    </p>

    <div class="flex items-center gap-4">
        <button type="submit"
                class="btn btn-primary font-display tracking-widest uppercase text-sm glow-box">
            {{ __('admin.identity_save') }}
        </button>
        <a href="{{ route('home', ['lang' => app()->getLocale()]) }}"
           target="_blank" rel="noopener noreferrer"
           class="btn btn-ghost btn-sm font-display tracking-widest uppercase text-xs border border-primary/20 hover:border-primary/60">
            {{ __('admin.homepage_preview') }}
        </a>
    </div>
</form>

@endsection
