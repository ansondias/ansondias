@php
    $labels = ['queued' => 'Queued', 'processing' => 'Working…', 'awaiting_answers' => 'Needs your answer', 'ready' => 'Draft ready', 'failed' => 'Stopped'];
@endphp
<span class="badge {{ $site->stage }}">{{ $labels[$site->stage] ?? $site->stage }}</span>
