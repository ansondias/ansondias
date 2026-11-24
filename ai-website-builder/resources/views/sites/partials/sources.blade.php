<h2>Sources</h2>
<table>
    <thead><tr><th>Source</th><th>Status</th><th>Details</th></tr></thead>
    <tbody>
    @foreach ($site->sources as $source)
        <tr>
            <td>
                <strong>{{ $source->label }}</strong>
                @if ($source->url)<div class="small"><a href="{{ $source->url }}" target="_blank" rel="noopener nofollow">{{ \Illuminate\Support\Str::limit($source->url, 60) }}</a></div>@endif
                <div class="muted small">{{ str_replace('_', ' ', $source->kind) }}{{ ($source->meta['discovered'] ?? false) ? ' · found by web search' : '' }}</div>
            </td>
            <td><span class="badge {{ $source->status }}">{{ $source->status }}</span></td>
            <td class="small">
                @if ($source->error)<div style="color:var(--warn)">{{ $source->error }}</div>@endif
                @if ($source->meta['different_business'] ?? false)<div style="color:var(--warn)">This looked like a different business, so we ignored it.</div>@endif
                @if (!empty($source->injection_findings))
                    <div class="note">⚠️ This source contained text that tried to give instructions to the AI. We removed it and did not follow it:
                        <ul style="margin:.25rem 0">@foreach ($source->injection_findings as $finding)<li>“{{ $finding }}”</li>@endforeach</ul>
                    </div>
                @endif
                @if ($source->kind === 'web_search' && !empty($source->meta['candidates']))
                    <div>{{ count($source->meta['candidates']) }} matching business(es) found via {{ $source->meta['engine'] ?? 'search' }}.</div>
                @endif
                @if ($source->kind === 'web_search' && !empty($source->meta['links']))
                    <div class="muted">Search sources: @foreach ($source->meta['links'] as $link)<a href="{{ $link['url'] }}" target="_blank" rel="noopener nofollow">{{ $link['title'] }}</a>@if (!$loop->last), @endif @endforeach</div>
                @endif
                @if ($source->isImage())
                    <img src="{{ route('media', $source) }}" alt="" style="width:80px;height:60px;object-fit:cover;border-radius:4px">
                    @if ($source->meta['alt_text'] ?? null)<div class="muted">{{ $source->meta['alt_text'] }}</div>@endif
                @endif
                @if ($source->content && $source->kind !== 'owner')
                    <details><summary class="small">What we read ({{ number_format(mb_strlen($source->content)) }} characters, {{ $source->facts()->count() }} facts)</summary><pre class="snippet">{{ \Illuminate\Support\Str::limit($source->content, 3000) }}</pre></details>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<details style="margin-top:1rem">
    <summary>+ Add another source</summary>
    <form method="post" action="{{ route('sites.sources.store', $site) }}" enctype="multipart/form-data">
        @csrf
        <label>Link</label><input type="url" name="url" placeholder="https://…">
        <label>Files</label><input type="file" name="files[]" multiple accept=".pdf,.docx,.txt,.csv,.md,.html,.jpg,.jpeg,.png,.webp,.gif">
        <label>Notes</label><textarea name="notes"></textarea>
        <button class="btn" style="margin-top:.75rem">Add and read</button>
    </form>
</details>
