<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Libros') — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header class="top">
    <a class="brand" href="{{ route('books.index') }}">🌸 {{ config('app.name') }}</a>
    @auth
        <nav style="display:flex;gap:.75rem;align-items:center">
            <a href="{{ route('books.index') }}">Libros</a>
            <a href="{{ route('settings.flows.edit') }}">Configuración</a>
            <form method="POST" action="{{ route('logout') }}" style="margin:0">@csrf
                <button class="btn secondary small" type="submit">Salir</button>
            </form>
        </nav>
    @endauth
</header>
<main>
    @if (session('status'))<div class="flash ok">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="flash bad">{{ session('error') }}</div>@endif
    @if (session('warning'))<div class="flash warn">{{ session('warning') }}</div>@endif
    @yield('content')
</main>
@stack('scripts')
</body>
</html>
