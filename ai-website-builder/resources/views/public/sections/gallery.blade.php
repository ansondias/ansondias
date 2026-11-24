<section id="gallery" class="block">
    <div class="wrap">
        <h2>{{ $c['heading'] ?? 'Photos' }}</h2>
        <div class="gallery">
            @foreach ($c['images'] ?? [] as $img)
                <img src="{{ route('media', $img['source_id']) }}" alt="{{ $img['alt'] }}" loading="lazy">
            @endforeach
        </div>
    </div>
</section>
