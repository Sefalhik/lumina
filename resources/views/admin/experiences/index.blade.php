@extends('layouts.admin')
@section('title', __('admin.experience_index_title'))

@section('content')

<div class="mb-10">
    <p class="text-base-content/70 text-xs tracking-[0.3em] uppercase mb-3 font-mono">
        <span class="text-primary opacity-60">//</span> {{ __('admin.experience_index_subtitle') }}
    </p>
    <h1 class="font-display text-3xl font-bold tracking-wider neon-pulse">
        {{ __('admin.experience_index_heading') }}
    </h1>
</div>

<div class="mb-8">
    <a href="{{ route('admin.experiences.create') }}"
       class="btn btn-primary font-display tracking-widest uppercase text-sm glow-box">
        + {{ __('admin.experience_create') }}
    </a>
</div>

@if (count($rows) === 0)
<p class="font-mono text-sm text-base-content/70">{{ __('admin.experience_empty') }}</p>
@else
<ul class="space-y-4">
    @foreach ($rows as $row)
    <li class="border border-primary/20 bg-base-200 p-6 hover:border-primary/60 transition-all duration-300">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="font-mono text-[10px] tracking-[0.3em] uppercase text-base-content/70 mb-2">
                    {{ $row['period'] }}
                </p>
                <p class="font-display text-lg text-primary tracking-wide">{{ $row['job_title'] }}</p>
                <p class="font-mono text-sm text-base-content/70">{{ $row['employer'] }}</p>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('admin.experiences.edit', ['experience' => $row['id']]) }}"
                   class="btn btn-ghost btn-sm font-display tracking-widest uppercase text-xs border border-primary/20 hover:border-primary/60">
                    {{ __('admin.experience_edit_heading') }}
                </a>

                {{-- A plain form rather than a JS confirm: one less thing that
                     cannot be exercised by the E2E suite. --}}
                <form method="POST"
                      action="{{ route('admin.experiences.destroy', ['experience' => $row['id']]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="btn btn-ghost btn-sm font-display tracking-widest uppercase text-xs border border-error/40 text-error hover:border-error">
                        {{ __('admin.experience_delete') }}
                    </button>
                </form>
            </div>
        </div>
    </li>
    @endforeach
</ul>
@endif

@endsection
