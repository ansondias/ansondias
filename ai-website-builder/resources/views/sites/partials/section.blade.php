@php
    $c = $section->content;
    $originLabel = ['ai' => 'Written by AI from your facts', 'template' => 'Laid out from your facts', 'owner' => 'Edited by you'][$section->origin] ?? $section->origin;
    $isAi = in_array($section->type, \App\Models\Section::AI_WRITTEN, true);
    $joinPairs = fn ($items, $a, $b) => collect($items ?? [])->map(fn ($i) => trim(($i[$a] ?? '').(($i[$b] ?? '') !== '' ? ' | '.$i[$b] : '')))->implode("\n");
@endphp
<div class="section-card {{ $section->visible ? '' : 'hidden-section' }}" id="section-{{ $section->id }}">
    <div class="head">
        <div class="row">
            <h3>{{ $section->label() }}</h3>
            <span class="badge {{ $section->origin }}">{{ $originLabel }}</span>
            @unless ($section->visible)<span class="badge">Hidden</span>@endunless
        </div>
        <div class="row">
            <form class="inline" method="post" action="{{ route('sections.move', $section) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-ghost btn-small" title="Move up" @disabled($first)>↑</button></form>
            <form class="inline" method="post" action="{{ route('sections.move', $section) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-ghost btn-small" title="Move down" @disabled($last)>↓</button></form>
            <form class="inline" method="post" action="{{ route('sections.toggle', $section) }}">@csrf<button class="btn btn-ghost btn-small">{{ $section->visible ? 'Hide' : 'Show' }}</button></form>
        </div>
    </div>
    <div class="body">
        @if ($section->notes)
            <div class="note">🛡 {{ $section->notes }}</div>
        @endif

        <div class="section-preview">
            @switch($section->type)
                @case('hero')
                    <strong style="font-size:1.1rem">{{ $c['headline'] ?? '' }}</strong>
                    <div>{{ $c['subheadline'] ?? '' }}</div>
                    @if (!empty($c['cta_label']))<div class="small muted">Button: “{{ $c['cta_label'] }}” → {{ $c['cta_href'] ?? '' }}</div>@endif
                    @break
                @case('about')
                    @forelse ($c['paragraphs'] ?? [] as $p)<p style="margin:.25rem 0">{{ $p }}</p>@empty<span class="muted">Nothing to say yet - add “About” facts or write it yourself.</span>@endforelse
                    @break
                @case('services')
                @case('pricing')
                    @if (!empty($c['intro']))<p style="margin:.25rem 0">{{ $c['intro'] }}</p>@endif
                    <ul>@forelse ($c['items'] ?? [] as $item)<li><strong>{{ $item['name'] }}</strong>{{ ($item['description'] ?? $item['price'] ?? '') !== '' ? ' - '.($item['description'] ?? $item['price']) : '' }}</li>@empty<li class="muted">No items found yet.</li>@endforelse</ul>
                    @break
                @case('highlights')
                    <ul>@forelse ($c['items'] ?? [] as $item)<li>{{ $item }}</li>@empty<li class="muted">Nothing yet.</li>@endforelse</ul>
                    @break
                @case('hours')
                    @forelse ($c['lines'] ?? [] as $line)<div>{{ $line }}</div>@empty<span class="muted">No opening hours yet.</span>@endforelse
                    @break
                @case('gallery')
                    <div class="thumbs">@forelse ($c['images'] ?? [] as $img)<img src="{{ route('media', $img['source_id']) }}" alt="{{ $img['alt'] }}">@empty<span class="muted">No photos uploaded.</span>@endforelse</div>
                    @break
                @case('contact')
                    @foreach ($c['phones'] ?? [] as $p)<div>📞 {{ $p }}</div>@endforeach
                    @foreach ($c['whatsapp'] ?? [] as $p)<div>💬 {{ $p }}</div>@endforeach
                    @foreach ($c['emails'] ?? [] as $e)<div>✉️ {{ $e }}</div>@endforeach
                    @if (!empty($c['address']))<div>📍 {{ $c['address'] }}</div>@endif
                    @if (!empty($c['website']))<div>🌐 {{ $c['website'] }}</div>@endif
                    @foreach ($c['socials'] ?? [] as $s)<div>🔗 {{ $s['label'] }}</div>@endforeach
                    @break
            @endswitch
        </div>

        <details>
            <summary>Edit</summary>
            <form method="post" action="{{ route('sections.update', $section) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="visible" value="{{ $section->visible ? 1 : 0 }}">
                @switch($section->type)
                    @case('hero')
                        <label>Headline</label><input type="text" name="headline" value="{{ $c['headline'] ?? '' }}">
                        <label>Sub-headline</label><input type="text" name="subheadline" value="{{ $c['subheadline'] ?? '' }}">
                        <label>Button text</label><input type="text" name="cta_label" value="{{ $c['cta_label'] ?? '' }}">
                        @break
                    @case('about')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        <label>Text <span class="hint">(blank line between paragraphs)</span></label>
                        <textarea name="paragraphs" rows="6">{{ implode("\n\n", $c['paragraphs'] ?? []) }}</textarea>
                        @break
                    @case('services')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        <label>Intro</label><input type="text" name="intro" value="{{ $c['intro'] ?? '' }}">
                        <label>Items <span class="hint">(one per line: Name | description)</span></label>
                        <textarea name="items" rows="6">{{ $joinPairs($c['items'] ?? [], 'name', 'description') }}</textarea>
                        @break
                    @case('pricing')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        <label>Items <span class="hint">(one per line: Name | price)</span></label>
                        <textarea name="items" rows="6">{{ $joinPairs($c['items'] ?? [], 'name', 'price') }}</textarea>
                        <label>Note under the prices</label><input type="text" name="note" value="{{ $c['note'] ?? '' }}">
                        @break
                    @case('highlights')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        <label>Points <span class="hint">(one per line)</span></label>
                        <textarea name="items" rows="5">{{ implode("\n", $c['items'] ?? []) }}</textarea>
                        @break
                    @case('hours')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        <label>Lines <span class="hint">(one per line)</span></label>
                        <textarea name="lines" rows="5">{{ implode("\n", $c['lines'] ?? []) }}</textarea>
                        @break
                    @case('gallery')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        @foreach ($c['images'] ?? [] as $img)
                            <div class="row" style="margin:.4rem 0">
                                <img src="{{ route('media', $img['source_id']) }}" alt="" style="width:60px;height:45px;object-fit:cover;border-radius:4px">
                                <input type="text" name="alt[{{ $img['source_id'] }}]" value="{{ $img['alt'] }}" style="flex:1" aria-label="Description">
                                <label style="margin:0;font-weight:400"><input type="checkbox" name="remove[]" value="{{ $img['source_id'] }}"> remove</label>
                            </div>
                        @endforeach
                        @break
                    @case('contact')
                        <label>Heading</label><input type="text" name="heading" value="{{ $c['heading'] ?? '' }}">
                        <div class="grid2">
                            <div><label>Phones <span class="hint">(one per line)</span></label><textarea name="phones" rows="2">{{ implode("\n", $c['phones'] ?? []) }}</textarea></div>
                            <div><label>WhatsApp</label><textarea name="whatsapp" rows="2">{{ implode("\n", $c['whatsapp'] ?? []) }}</textarea></div>
                            <div><label>Emails</label><textarea name="emails" rows="2">{{ implode("\n", $c['emails'] ?? []) }}</textarea></div>
                            <div><label>Address</label><textarea name="address" rows="2">{{ $c['address'] ?? '' }}</textarea></div>
                        </div>
                        <label>Website</label><input type="text" name="website" value="{{ $c['website'] ?? '' }}">
                        <label>Social links <span class="hint">(Label | https://…)</span></label>
                        <textarea name="socials" rows="3">{{ $joinPairs($c['socials'] ?? [], 'label', 'url') }}</textarea>
                        <p class="small muted">Tip: changing a fact in the Facts tab updates this section automatically, unless you edit it here.</p>
                        @break
                @endswitch
                <div style="margin-top:.75rem"><button class="btn btn-small">Save section</button></div>
            </form>
        </details>

        <details>
            <summary>{{ $isAi ? 'Regenerate with AI' : 'Rebuild from facts' }}</summary>
            <form method="post" action="{{ route('sections.regenerate', $section) }}" class="row" style="align-items:flex-end">
                @csrf
                @if ($isAi)
                    <div style="flex:1;min-width:220px">
                        <label>Anything to change? <span class="hint">(optional - e.g. “shorter”, “more formal”, “mention home delivery first”)</span></label>
                        <input type="text" name="instruction" maxlength="300">
                    </div>
                @else
                    <p class="small muted" style="margin:0;flex:1">This section is laid out directly from your confirmed facts (no AI), so it can never contain an invented detail.</p>
                @endif
                <button class="btn btn-small">{{ $isAi ? 'Regenerate' : 'Rebuild' }}</button>
            </form>
        </details>

        @if ($section->revisions->isNotEmpty())
            <details>
                <summary>History ({{ $section->revisions->count() }})</summary>
                <table class="small">
                    @foreach ($section->revisions->take(8) as $rev)
                        <tr>
                            <td>{{ $rev->created_at->diffForHumans() }}</td>
                            <td>Before: {{ $rev->reason ?? 'change' }}</td>
                            <td class="muted">{{ \Illuminate\Support\Str::limit(collect($rev->content)->flatten()->filter(fn ($v) => is_string($v))->implode(' · '), 90) }}</td>
                            <td><form class="inline" method="post" action="{{ route('revisions.restore', $rev) }}">@csrf<button class="btn btn-ghost btn-small">Restore</button></form></td>
                        </tr>
                    @endforeach
                </table>
            </details>
        @endif
    </div>
</div>
