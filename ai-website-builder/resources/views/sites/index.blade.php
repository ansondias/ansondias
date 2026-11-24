@extends('layouts.app')

@section('content')
    <div class="row spread" style="margin-bottom:1rem">
        <div>
            <h1>Your websites</h1>
            <p class="muted" style="margin:0">Share what you have about a business. We read it, check it, ask when unsure, and build a site.</p>
        </div>
        <a href="{{ route('sites.create') }}" class="btn btn-big">+ Build a website</a>
    </div>

    @if ($sites->isEmpty())
        <div class="panel empty">
            <h2>No websites yet</h2>
            <p class="muted">Start with a business name and city. Add links, a price list or photos if you have them.</p>
            <a href="{{ route('sites.create') }}" class="btn">Build the first one</a>
        </div>
    @else
        <div class="cards">
            @foreach ($sites as $site)
                <div class="card">
                    <h3><a href="{{ route('sites.show', $site) }}">{{ $site->business_name }}</a></h3>
                    <div class="muted small">{{ $site->city ?: 'No city' }} · created {{ $site->created_at->diffForHumans() }}</div>
                    <div class="row" style="margin-top:.6rem">
                        @include('sites.partials.stage', ['site' => $site])
                        @if ($site->open_questions_count)
                            <span class="badge warn">{{ $site->open_questions_count }} question{{ $site->open_questions_count > 1 ? 's' : '' }}</span>
                        @endif
                        @if ($site->isPublished())
                            <a class="badge ok" href="{{ route('public.site', $site->slug) }}" target="_blank">Live ↗</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
