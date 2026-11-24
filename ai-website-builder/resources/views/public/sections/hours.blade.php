<section id="hours" class="block alt">
    <div class="wrap narrow">
        <h2>{{ $c['heading'] ?? 'Opening hours' }}</h2>
        <ul class="hours">
            @foreach ($c['lines'] ?? [] as $line)<li>{{ $line }}</li>@endforeach
        </ul>
    </div>
</section>
