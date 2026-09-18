@extends('layouts.app')
@section('title', 'Libros')
@section('content')
<div style="display:flex;justify-content:space-between;align-items:center">
    <h1>Libros</h1>
    <a class="btn" href="{{ route('books.create') }}">+ Nuevo libro</a>
</div>
<div class="card">
@if ($books->isEmpty())
    <p class="muted">Aún no hay libros. Crea el primero con «Nuevo libro».</p>
@else
<table>
    <thead><tr><th>Título</th><th>Animal</th><th>Mandalas</th><th>Completados</th><th>Estado</th><th>Fecha</th><th></th></tr></thead>
    <tbody>
    @foreach ($books as $book)
        <tr>
            <td><a href="{{ route('books.show', $book) }}">{{ $book->title }}</a></td>
            <td>{{ $book->animal_theme }}</td>
            <td>{{ $book->mandala_count }}</td>
            <td>{{ $book->completed_count }} / {{ $book->mandala_count }}</td>
            <td><span class="badge {{ $book->status->value }}">{{ $book->status->label() }}</span></td>
            <td class="muted">{{ $book->created_at->format('Y-m-d') }}</td>
            <td>
                <a class="btn secondary small" href="{{ route('books.show', $book) }}">Abrir</a>
                <a class="btn secondary small" href="{{ route('books.edit', $book) }}">Editar</a>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
</div>
@endsection
