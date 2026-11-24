<section id="about" class="block">
    <div class="wrap narrow">
        <h2>{{ $c['heading'] ?? 'About us' }}</h2>
        @foreach ($c['paragraphs'] ?? [] as $p)<p>{{ $p }}</p>@endforeach
    </div>
</section>
