@php $safe = fn ($u) => preg_match('#^(https?://|mailto:|tel:)#i', (string) $u) ? $u : '#'; @endphp
<section id="contact" class="block contact">
    <div class="wrap">
        <h2>{{ $c['heading'] ?? 'Get in touch' }}</h2>
        <div class="contact-grid">
            @foreach ($c['phones'] ?? [] as $phone)
                <a class="contact-item" href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}"><span class="ico">📞</span>{{ $phone }}</a>
            @endforeach
            @foreach ($c['whatsapp'] ?? [] as $wa)
                <a class="contact-item" href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}" rel="noopener"><span class="ico">💬</span>WhatsApp {{ $wa }}</a>
            @endforeach
            @foreach ($c['emails'] ?? [] as $email)
                <a class="contact-item" href="mailto:{{ $email }}"><span class="ico">✉️</span>{{ $email }}</a>
            @endforeach
            @if (!empty($c['address']))
                <a class="contact-item" href="{{ $safe($c['maps_url'] ?? '#') }}" target="_blank" rel="noopener"><span class="ico">📍</span>{{ $c['address'] }}</a>
            @endif
            @if (!empty($c['website']))
                <a class="contact-item" href="{{ $safe($c['website']) }}" target="_blank" rel="noopener"><span class="ico">🌐</span>{{ preg_replace('#^https?://(www\.)?#', '', rtrim($c['website'], '/')) }}</a>
            @endif
        </div>
        @if (!empty($c['socials']))
            <div class="socials">
                @foreach ($c['socials'] as $s)<a href="{{ $safe($s['url']) }}" target="_blank" rel="noopener">{{ $s['label'] }}</a>@endforeach
            </div>
        @endif
    </div>
</section>
