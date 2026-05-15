@extends('layouts.admin')
@section('title', __('admin.homepage_edit_title'))

@section('content')

<div class="mb-10">
    <p class="text-secondary text-xs tracking-[0.3em] uppercase mb-3 font-mono">
        <span class="text-primary opacity-60">//</span> {{ __('admin.homepage_edit_subtitle') }}
    </p>
    <h1 class="font-display text-3xl font-bold tracking-wider neon-pulse">
        {{ __('admin.homepage_edit_heading') }}
    </h1>
</div>

@if (session('success'))
<div class="mb-6 border border-primary/40 bg-primary/10 px-5 py-3 font-mono text-xs tracking-widest text-primary uppercase">
    ◈ {{ session('success') }}
</div>
@endif

@if ($errors->any())
<div class="mb-6 border border-error/40 bg-error/10 px-5 py-3 font-mono text-xs tracking-widest text-error uppercase">
    @foreach ($errors->all() as $error)
    <p>✗ {{ $error }}</p>
    @endforeach
</div>
@endif

<form method="POST" action="{{ route('admin.homepage.update', ['lang' => app()->getLocale()]) }}">
    @csrf
    @method('PUT')

    {{-- Tagline --}}
    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="tagline_fr">
            › {{ __('admin.homepage_field_tagline') }}
        </label>
        <input id="tagline_fr" type="text" name="tagline[fr]"
               value="{{ old('tagline.fr', $content->getTranslation('tagline', 'fr', false)) }}"
               maxlength="200"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('tagline.fr') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>

    {{-- Subtitle --}}
    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="subtitle_fr">
            › {{ __('admin.homepage_field_subtitle') }}
        </label>
        <input id="subtitle_fr" type="text" name="subtitle[fr]"
               value="{{ old('subtitle.fr', $content->getTranslation('subtitle', 'fr', false)) }}"
               maxlength="200"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('subtitle.fr') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>

    {{-- Bio --}}
    <div class="mb-10">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/60 mb-2 font-mono"
               for="bio_fr">
            › {{ __('admin.homepage_field_bio') }}
        </label>
        <textarea id="bio_fr" name="bio[fr]" rows="4" maxlength="1000"
                  class="textarea w-full bg-base-200 font-mono text-sm {{ $errors->has('bio.fr') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">{{ old('bio.fr', $content->getTranslation('bio', 'fr', false)) }}</textarea>
    </div>

    <div class="flex items-center gap-4">
        <button type="submit"
                class="btn btn-primary font-display tracking-widest uppercase text-sm glow-box">
            {{ __('admin.homepage_save') }}
        </button>
        <a href="{{ route('home', ['lang' => app()->getLocale()]) }}"
           target="_blank" rel="noopener noreferrer"
           class="btn btn-ghost btn-sm font-display tracking-widest uppercase text-xs border border-primary/20 hover:border-primary/60">
            {{ __('admin.homepage_preview') }}
        </a>
    </div>
</form>

@endsection
