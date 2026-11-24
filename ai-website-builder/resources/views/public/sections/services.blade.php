<section id="services" class="block alt">
    <div class="wrap">
        <h2>{{ $c['heading'] ?? 'What we offer' }}</h2>
        @if (!empty($c['intro']))<p class="intro">{{ $c['intro'] }}</p>@endif
        <div class="tiles">
            @foreach ($c['items'] ?? [] as $item)
                <div class="tile">
                    <h3>{{ $item['name'] }}</h3>
                    @if (!empty($item['description']))<p>{{ $item['description'] }}</p>@endif
                </div>
            @endforeach
        </div>
    </div>
</section>
