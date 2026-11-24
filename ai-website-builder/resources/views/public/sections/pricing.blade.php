<section id="pricing" class="block">
    <div class="wrap narrow">
        <h2>{{ $c['heading'] ?? 'Prices' }}</h2>
        <ul class="prices">
            @foreach ($c['items'] ?? [] as $item)
                <li><span>{{ $item['name'] }}</span><span class="dots"></span><strong>{{ $item['price'] }}</strong></li>
            @endforeach
        </ul>
        @if (!empty($c['note']))<p class="small muted">{{ $c['note'] }}</p>@endif
    </div>
</section>
