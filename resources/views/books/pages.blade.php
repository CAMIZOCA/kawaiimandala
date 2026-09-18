@extends('layouts.app')
@section('title', 'Estructura de páginas')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
    <h1>Estructura de páginas — {{ $book->title }}</h1>
    <a class="btn secondary small" href="{{ route('books.show', $book) }}">← Volver al libro</a>
</div>
<p class="muted">{{ $book->mandala_count }} mandalas → {{ count($plan) }} páginas. Regla: impar = RIGHT / RECTO, par = LEFT / VERSO. Todo mandala va en página impar; su reverso queda en blanco.</p>

<div class="pages">
@foreach ($plan as $p)
    <div class="pg {{ $p['type'] }}">
        <span><strong>Page {{ $p['page'] }}</strong> &nbsp;
            @switch($p['type'])
                @case('title') Title @break
                @case('introduction') Introduction @break
                @case('mandala') Mandala {{ str_pad($p['position'], 2, '0', STR_PAD_LEFT) }} @break
                @case('creator') Creator / Copyright @break
                @default Blank
            @endswitch
        </span>
        <span class="side {{ $p['side'] }}">{{ $p['side'] === 'right' ? 'RIGHT / RECTO' : 'LEFT / VERSO' }}</span>
    </div>
@endforeach
</div>
@endsection
