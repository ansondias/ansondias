<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'AI Builds My Website')</title>
    <link rel="stylesheet" href="{{ asset('css/builder.css') }}">
</head>
<body>
<header class="topbar">
    <a href="{{ route('sites.index') }}" class="brand"><span class="logo">◆</span> AI Builds My Website</a>
    <a href="{{ route('sites.create') }}" class="btn btn-small">+ New website</a>
</header>
<main class="container">
    @if (session('ok'))
        <div class="flash ok">{{ session('ok') }}</div>
    @endif
    @if (session('error'))
        <div class="flash error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash error">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
