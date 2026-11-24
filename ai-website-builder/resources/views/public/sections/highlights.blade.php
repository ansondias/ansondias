<section id="highlights" class="block alt">
    <div class="wrap narrow">
        <h2>{{ $c['heading'] ?? 'Good to know' }}</h2>
        <ul class="checks">
            @foreach ($c['items'] ?? [] as $item)<li>{{ $item }}</li>@endforeach
        </ul>
    </div>
</section>
