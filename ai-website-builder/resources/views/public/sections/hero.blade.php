<header class="hero">
    <div class="wrap">
        <h1>{{ $c['headline'] ?? $site->business_name }}</h1>
        @if (!empty($c['subheadline']))<p class="lead">{{ $c['subheadline'] }}</p>@endif
        @if (!empty($c['cta_label']))
            <a class="cta" href="{{ $c['cta_href'] ?? '#contact' }}">{{ $c['cta_label'] }}</a>
        @endif
    </div>
</header>
