<div class="row spread">
    <h2>Facts</h2>
    <span class="muted small">Your website is built only from <span class="badge confirmed">confirmed</span> facts.{{ $rejectedCount ? " $rejectedCount rejected fact(s) are kept for the record but never shown." : '' }}</span>
</div>
@if ($facts->isEmpty())
    <p class="muted">No facts yet.</p>
@else
    <table>
        <thead><tr><th>What</th><th>Value</th><th>Where it came from</th><th></th></tr></thead>
        <tbody>
        @foreach ($facts as $fact)
            <tr>
                <td>{{ $fact->label() }}<br><span class="badge {{ $fact->status }}">{{ $fact->status === 'conflict' ? 'needs answer' : $fact->status }}</span></td>
                <td style="max-width:340px;word-break:break-word">{{ $fact->display() }}</td>
                <td><strong class="small">{{ $fact->sourceLabel() }}</strong>
                    @if ($fact->evidence)<div class="evidence">“{{ \Illuminate\Support\Str::limit($fact->evidence, 140) }}”</div>@endif
                </td>
                <td style="white-space:nowrap">
                    <details>
                        <summary class="small">Edit</summary>
                        <form method="post" action="{{ route('facts.update', $fact) }}">
                            @csrf @method('PATCH')
                            <input type="text" name="value" value="{{ $fact->value }}">
                            @if ($fact->key === 'price_item')<input type="text" name="price" value="{{ $fact->meta['price'] ?? '' }}" placeholder="Price" style="margin-top:.3rem">@endif
                            <button class="btn btn-small" style="margin-top:.3rem">Save</button>
                        </form>
                    </details>
                    <form class="inline" method="post" action="{{ route('facts.destroy', $fact) }}">@csrf @method('DELETE')<button class="btn btn-danger btn-small">Remove</button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<details style="margin-top:1rem">
    <summary>+ Add a fact yourself</summary>
    <form method="post" action="{{ route('facts.store', $site) }}" class="grid2">
        @csrf
        <div>
            <label>What</label>
            <select name="key">@foreach (\App\Models\Fact::KEYS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
        </div>
        <div>
            <label>Value</label>
            <input type="text" name="value" required>
        </div>
        <div>
            <label>Price <span class="hint">(only for “Price”)</span></label>
            <input type="text" name="price">
        </div>
        <div style="align-self:end"><button class="btn">Add</button></div>
    </form>
</details>
