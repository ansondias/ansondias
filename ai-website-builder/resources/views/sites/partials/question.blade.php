<div class="question {{ $question->blocking ? 'blocking' : '' }}">
    <div class="row spread">
        <strong>{{ $question->prompt }}</strong>
        @if ($question->blocking)<span class="badge bad">Needed</span>@else<span class="badge">Optional</span>@endif
    </div>
    <form method="post" action="{{ route('questions.answer', $question) }}">
        @csrf
        @foreach ($question->options ?? [] as $option)
            <label class="opt">
                <input type="radio" name="choice" value="{{ $option['value'] }}">
                <span>{{ $option['label'] }}</span>
            </label>
        @endforeach
        @if ($question->allow_custom)
            @if ($question->type === 'blocked_source' || $question->field === 'service')
                <textarea name="custom" placeholder="{{ $question->type === 'blocked_source' ? 'Paste the text here…' : 'Type your answer…' }}" style="margin-top:.5rem"></textarea>
            @else
                <input type="text" name="custom" placeholder="{{ $question->options ? 'Or type the correct value…' : 'Type your answer…' }}" style="margin-top:.5rem">
            @endif
        @endif
        <div class="row" style="margin-top:.6rem">
            <button class="btn btn-small">Save answer</button>
            <button class="btn btn-ghost btn-small" formaction="{{ route('questions.skip', $question) }}">{{ $question->type === 'identity' ? 'Skip - use only what I gave you' : ($question->type === 'conflict' || $question->type === 'confirm' ? 'Not sure - leave it off the site' : 'Skip') }}</button>
        </div>
    </form>
</div>
