@extends('layouts.admin')
@section('title', $experience->exists ? __('admin.experience_edit_heading') : __('admin.experience_create_heading'))

@section('content')

<div class="mb-10">
    <p class="text-base-content/70 text-xs tracking-[0.3em] uppercase mb-3 font-mono">
        <span class="text-primary opacity-60">//</span> {{ __('admin.experience_index_subtitle') }}
    </p>
    <h1 class="font-display text-3xl font-bold tracking-wider neon-pulse">
        {{ $experience->exists ? __('admin.experience_edit_heading') : __('admin.experience_create_heading') }}
    </h1>
</div>

@if ($errors->any())
<div class="mb-6 border border-error/40 bg-error/10 px-5 py-3 font-mono text-xs tracking-widest text-error uppercase">
    @foreach ($errors->all() as $error)
    <p>✗ {{ $error }}</p>
    @endforeach
</div>
@endif

<form method="POST"
      action="{{ $experience->exists
          ? route('admin.experiences.update', ['lang' => app()->getLocale(), 'experience' => $experience])
          : route('admin.experiences.store', ['lang' => app()->getLocale()]) }}">
    @csrf
    @if ($experience->exists)
    @method('PUT')
    @endif

    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
               for="employer">
            › {{ __('admin.experience_field_employer') }}
        </label>
        <input id="employer" type="text" name="employer" required
               value="{{ old('employer', $experience->employer) }}"
               maxlength="120"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('employer') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>

    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
               for="job_title">
            › {{ __('admin.experience_field_job_title') }}
        </label>
        <input id="job_title" type="text" name="job_title" required
               value="{{ old('job_title', $experience->job_title) }}"
               maxlength="120"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('job_title') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
        <p class="mt-2 text-[10px] tracking-widest uppercase text-base-content/70 font-mono">
            {{ __('admin.experience_hint_job_title') }}
        </p>
    </div>

    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
               for="location">
            › {{ __('admin.experience_field_location') }}
        </label>
        <input id="location" type="text" name="location"
               value="{{ old('location', $experience->location) }}"
               maxlength="120"
               class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('location') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div>
            <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
                   for="started_at">
                › {{ __('admin.experience_field_started_at') }}
            </label>
            <input id="started_at" type="date" name="started_at" required
                   value="{{ old('started_at', $experience->started_at?->format('Y-m-d')) }}"
                   class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('started_at') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
        </div>

        <div>
            <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
                   for="ended_at">
                › {{ __('admin.experience_field_ended_at') }}
            </label>
            <input id="ended_at" type="date" name="ended_at"
                   value="{{ old('ended_at', $experience->ended_at?->format('Y-m-d')) }}"
                   class="input w-full bg-base-200 font-mono text-sm {{ $errors->has('ended_at') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">
            <p class="mt-2 text-[10px] tracking-widest uppercase text-base-content/70 font-mono">
                {{ __('admin.experience_hint_ended_at') }}
            </p>
        </div>
    </div>

    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
               for="description">
            › {{ __('admin.experience_field_description') }}
        </label>
        <textarea id="description" name="description" rows="5" maxlength="2000"
                  class="textarea w-full bg-base-200 font-mono text-sm {{ $errors->has('description') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">{{ old('description', $experience->getTranslation('description', 'fr', false)) }}</textarea>
    </div>

    <div class="mb-8">
        <label class="block text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2 font-mono"
               for="achievements">
            › {{ __('admin.experience_field_achievements') }}
        </label>
        <textarea id="achievements" name="achievements" rows="5" maxlength="2000"
                  class="textarea w-full bg-base-200 font-mono text-sm {{ $errors->has('achievements') ? 'border-error' : 'border-primary/20 focus:border-primary/60' }}">{{ old('achievements', $experience->getTranslation('achievements', 'fr', false)) }}</textarea>
    </div>

    <div class="flex items-center gap-4">
        <button type="submit"
                class="btn btn-primary font-display tracking-widest uppercase text-sm glow-box">
            {{ __('admin.experience_save') }}
        </button>
        <a href="{{ route('admin.experiences.index', ['lang' => app()->getLocale()]) }}"
           class="btn btn-ghost btn-sm font-display tracking-widest uppercase text-xs border border-primary/20 hover:border-primary/60">
            {{ __('admin.experience_back') }}
        </a>
    </div>
</form>

@endsection
