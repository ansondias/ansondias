@extends('layouts.app')
@section('title', $site->business_name.' - AI Builds My Website')

@section('content')
    <div class="row spread" style="margin-bottom:1rem">
        <div>
            <h1>{{ $site->business_name }}</h1>
            <div class="row muted small">
                <span>{{ $site->city ?: 'No city given' }}</span>
                @include('sites.partials.stage', ['site' => $site])
                @if ($site->identity && ($site->identity['reason'] ?? null))
                    <span>Identified online: {{ $site->identity['name'] }} ({{ $site->identity['reason'] }})</span>
                @endif
            </div>
        </div>
        <div class="row">
            @if ($site->sections->isNotEmpty())
                <a class="btn btn-ghost" href="{{ route('sites.preview', $site) }}" target="_blank">Preview ↗</a>
                @if ($site->isPublished())
                    <form class="inline" method="post" action="{{ route('sites.unpublish', $site) }}">@csrf<button class="btn btn-danger">Unpublish</button></form>
                @else
                    <form class="inline" method="post" action="{{ route('sites.publish', $site) }}">@csrf<button class="btn" @disabled($site->hasBlockingQuestions())>Publish</button></form>
                @endif
            @endif
        </div>
    </div>

    @if ($site->isPublished())
        <div class="pubbar">
            <strong>Live:</strong>
            <code>{{ route('public.site', $site->slug) }}</code>
            <a href="{{ route('public.site', $site->slug) }}" target="_blank">Open ↗</a>
            <span class="muted small">Edits you make below go live immediately.</span>
        </div>
    @endif

    @if ($site->isBusy())
        <div class="progress" id="progress" data-status-url="{{ route('sites.status', $site) }}" data-stage="{{ $site->stage }}">
            <div class="spinner"></div>
            <div>
                <strong id="progress-detail">{{ $site->stage_detail ?: 'Starting…' }}</strong>
                <div class="muted small">This page updates by itself. Reading files and searching the web takes up to a minute.</div>
            </div>
        </div>
    @endif

    @if ($site->stage === 'failed')
        <div class="failbox">
            <strong>{{ $site->stage_detail ?: 'The build stopped.' }}</strong>
            <div class="small" style="margin:.35rem 0 .75rem">{{ $site->last_error }}</div>
            <div class="small" style="margin-bottom:.75rem">Nothing is lost: your links, files and every fact found so far are saved. You can:</div>
            <div class="row">
                <form class="inline" method="post" action="{{ route('sites.retry', $site) }}">@csrf<button class="btn">Try again</button></form>
                <form class="inline" method="post" action="{{ route('sites.retry', $site) }}">@csrf<input type="hidden" name="without_ai" value="1"><button class="btn btn-ghost">Build without AI from what we have</button></form>
            </div>
            <div class="muted small" style="margin-top:.5rem">Without AI, we lay out contact, hours, prices and photos straight from your facts and skip the written copy, which you can type yourself.</div>
        </div>
    @endif

    @if ($questions->isNotEmpty())
        <div class="panel" id="questions">
            <h2>We need your help ({{ $questions->count() }})</h2>
            <p class="muted small" style="margin-top:-.5rem">We only publish what we are sure of. Red ones must be answered before we can build or publish.</p>
            @foreach ($questions as $question)
                @include('sites.partials.question', ['question' => $question])
            @endforeach
        </div>
    @endif

    <nav class="tabs">
        <a href="#sections">Website</a>
        <a href="#facts">Facts ({{ $facts->count() }})</a>
        <a href="#sources">Sources ({{ $site->sources->count() }})</a>
        <a href="#settings">Settings</a>
    </nav>

    <div class="panel" id="sections">
        <div class="row spread">
            <h2>Website sections</h2>
            @if ($site->sections->isNotEmpty())
                <form class="inline" method="post" action="{{ route('sites.rebuild', $site) }}" onsubmit="return confirm('Rewrite every section from your current facts? Your manual edits will be replaced.')">@csrf<button class="btn btn-ghost btn-small">↻ Regenerate whole site</button></form>
            @endif
        </div>
        @forelse ($site->sections as $section)
            @include('sites.partials.section', ['section' => $section, 'first' => $loop->first, 'last' => $loop->last])
        @empty
            <p class="muted">Sections will appear here once we've read your sources{{ $questions->where('blocking', true)->isNotEmpty() ? ' and you have answered the questions above' : '' }}.</p>
        @endforelse
    </div>

    <div class="panel" id="facts">
        @include('sites.partials.facts')
    </div>

    <div class="panel" id="sources">
        @include('sites.partials.sources')
    </div>

    <div class="panel" id="settings">
        <h2>Settings</h2>
        <form method="post" action="{{ route('sites.update', $site) }}" class="row">
            @csrf @method('PATCH')
            <label style="margin:0">Accent colour <input type="color" name="accent_color" value="{{ $site->accent_color }}"></label>
            <label style="margin:0">Style
                <select name="theme" style="width:auto">
                    <option value="classic" @selected($site->theme === 'classic')>Classic</option>
                    <option value="bold" @selected($site->theme === 'bold')>Bold</option>
                </select>
            </label>
            <button class="btn btn-small">Save</button>
        </form>
        @if ($aiErrors->isNotEmpty())
            <h3 style="margin-top:1rem">Recent AI errors</h3>
            <ul class="small muted">
                @foreach ($aiErrors as $run)<li>{{ $run->created_at->diffForHumans() }} · {{ $run->purpose }} · {{ $run->error }}</li>@endforeach
            </ul>
        @endif
        <form method="post" action="{{ route('sites.destroy', $site) }}" onsubmit="return confirm('Delete this website and everything we stored for it?')" style="margin-top:1rem">
            @csrf @method('DELETE')
            <button class="btn btn-danger btn-small">Delete website and its data</button>
        </form>
    </div>

    <script>
        (function () {
            const box = document.getElementById('progress');
            if (!box) return;
            const detail = document.getElementById('progress-detail');
            async function poll() {
                try {
                    const res = await fetch(box.dataset.statusUrl, {headers: {'Accept': 'application/json'}});
                    const s = await res.json();
                    if (!['queued', 'processing'].includes(s.stage)) { location.reload(); return; }
                    if (s.detail) detail.textContent = s.detail;
                } catch (e) { /* keep polling */ }
                setTimeout(poll, 1500);
            }
            setTimeout(poll, 1500);
        })();
    </script>
@endsection
