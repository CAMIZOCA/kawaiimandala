@extends('layouts.app')
@section('title', $book->title)
@section('content')
@php($completed = $book->mandalas->whereNotNull('image_path')->count())
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
    <h1>{{ $book->title }}</h1>
    <a class="btn secondary small" href="{{ route('books.index') }}">← Libros</a>
</div>

<div class="card">
    <dl class="summary">
        <div><dt>Título</dt><dd>{{ $book->title }}</dd></div>
        <div><dt>Subtítulo</dt><dd>{{ $book->subtitle ?: '—' }}</dd></div>
        <div><dt>Animal</dt><dd>{{ $book->animal_theme }}</dd></div>
        <div><dt>Mandalas</dt><dd>{{ $completed }} / {{ $book->mandala_count }}</dd></div>
        <div><dt>Estado</dt><dd><span class="badge {{ $book->status->value }}">{{ $book->status->label() }}</span></dd></div>
    </dl>
    <div class="actions">
        <a class="btn secondary" href="{{ route('books.edit', $book) }}">Editar libro</a>
        <a class="btn secondary" href="{{ route('books.pages', $book) }}">Ver estructura de páginas</a>
        <a class="btn secondary" href="{{ route('books.pdf.preview', $book) }}" target="_blank">Previsualizar PDF</a>
        <form method="POST" action="{{ route('books.pdf.export', $book) }}">@csrf
            <button class="btn" type="submit">Exportar PDF</button>
        </form>
        @if (\Illuminate\Support\Facades\Storage::disk('local')->exists($book->storageDir('exports').'/'.(\Illuminate\Support\Str::slug($book->title) ?: 'book').'-interior-8.5x8.5.pdf'))
            <a class="btn secondary" href="{{ route('books.pdf.download', $book) }}">Descargar último PDF</a>
        @endif
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0">Cargar mandalas</h2>
    <p class="muted">PNG o JPG (preferible PNG, mínimo {{ config('kawaii.min_image_px') }}×{{ config('kawaii.min_image_px') }} px, objetivo {{ config('kawaii.target_image_px') }}×{{ config('kawaii.target_image_px') }}). Los archivos se ordenan por nombre natural y se asignan a Mandala 1..{{ $book->mandala_count }}.</p>
    <form method="POST" action="{{ route('books.mandalas.upload-many', $book) }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="images[]" accept="image/png,image/jpeg" multiple required>
        @error('images')<div class="err">{{ $message }}</div>@enderror
        <div class="actions"><button class="btn" type="submit">Subir y asignar en orden</button></div>
    </form>
</div>

<h2>Mandalas</h2>
<div class="slots">
@foreach ($book->mandalas as $mandala)
    <div class="slot">
        <div class="num">{{ $mandala->label() }}</div>
        <div class="thumb">
            @if ($mandala->hasImage())
                <img loading="lazy" src="{{ route('books.mandalas.image', [$book, $mandala->position]) }}?v={{ $mandala->updated_at->timestamp }}" alt="Mandala {{ $mandala->label() }}">
            @else
                pendiente
            @endif
        </div>
        @if ($mandala->hasImage())
            <div class="meta">{{ $mandala->width_px }}×{{ $mandala->height_px }} px · {{ $mandala->generation_source }}
                @if ($mandala->isLowRes()) <br><strong style="color:var(--warn)">⚠ baja resolución</strong> @endif
            </div>
        @endif
        <form method="POST" action="{{ route('books.mandalas.upload', [$book, $mandala->position]) }}" enctype="multipart/form-data">
            @csrf
            <input type="file" name="image" accept="image/png,image/jpeg" required>
            <button class="btn secondary small" type="submit" style="margin-top:.25rem">{{ $mandala->hasImage() ? 'Reemplazar' : 'Subir' }}</button>
        </form>
        @if ($mandala->hasImage())
            <form method="POST" action="{{ route('books.mandalas.destroy', [$book, $mandala->position]) }}" onsubmit="return confirm('¿Quitar la imagen del Mandala {{ $mandala->label() }}?')">
                @csrf @method('DELETE')
                <button class="btn danger small" type="submit">Quitar</button>
            </form>
        @endif
    </div>
@endforeach
</div>
@endsection
