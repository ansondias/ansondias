@php
    $hero = $sections->firstWhere('type', 'hero')?->content ?? ['headline' => $site->business_name];
    $about = $sections->firstWhere('type', 'about')?->content;
    $description = \Illuminate\Support\Str::limit($hero['subheadline'] ?? ($about['paragraphs'][0] ?? $site->business_name), 155);
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $site->accent_color) ? $site->accent_color : '#1f6f5c';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $site->business_name }}{{ $site->city ? ' - '.$site->city : '' }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $site->business_name }}">
    <meta property="og:description" content="{{ $description }}">
    @if ($preview)<meta name="robots" content="noindex">@endif
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
    <style>:root { --accent: {{ $accent }}; }</style>
</head>
<body class="theme-{{ $site->theme === 'bold' ? 'bold' : 'classic' }}">
@if ($preview)
    <div class="preview-bar">Preview{{ $site->isPublished() ? '' : ' - not published yet' }} · <a href="{{ route('sites.show', $site) }}">Back to editor</a></div>
@endif

<nav class="nav">
    <div class="wrap nav-inner">
        <a href="#top" class="nav-brand">{{ $site->business_name }}</a>
        <div class="nav-links">
            @foreach ($sections as $s)
                @if (in_array($s->type, ['about', 'services', 'pricing', 'gallery', 'contact'], true))
                    <a href="#{{ $s->type }}">{{ $s->content['heading'] ?? $s->label() }}</a>
                @endif
            @endforeach
        </div>
    </div>
</nav>

<main id="top">
    @foreach ($sections as $section)
        @includeIf('public.sections.'.$section->type, ['c' => $section->content, 'site' => $site])
    @endforeach
</main>

<footer class="footer">
    <div class="wrap">
        <strong>{{ $site->business_name }}</strong>{{ $site->city ? ' · '.$site->city : '' }}
        <div class="small">© {{ now()->year }} {{ $site->business_name }}</div>
    </div>
</footer>
</body>
</html>
