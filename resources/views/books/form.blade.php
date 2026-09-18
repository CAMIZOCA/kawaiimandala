@extends('layouts.app')
@php($editing = $book->exists)
@section('title', $editing ? 'Editar libro' : 'Nuevo libro')
@section('content')
<h1>{{ $editing ? 'Editar libro' : 'Nuevo libro' }}</h1>
<form class="card" method="POST" action="{{ $editing ? route('books.update', $book) : route('books.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="grid2">
        <div>
            <label for="title">Título *</label>
            <input id="title" type="text" name="title" value="{{ old('title', $book->title) }}">
            @error('title')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="subtitle">Subtítulo</label>
            <input id="subtitle" type="text" name="subtitle" value="{{ old('subtitle', $book->subtitle) }}">
            @error('subtitle')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="animal_theme">Animal / tema *</label>
            <input id="animal_theme" type="text" name="animal_theme" value="{{ old('animal_theme', $book->animal_theme) }}">
            @error('animal_theme')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="mandala_count">Cantidad de mandalas (1–{{ config('kawaii.max_mandalas') }})</label>
            <input id="mandala_count" type="number" name="mandala_count" value="{{ old('mandala_count', $book->mandala_count) }}" min="1" max="{{ config('kawaii.max_mandalas') }}">
            @error('mandala_count')<div class="err">{{ $message }}</div>@enderror
        </div>
    </div>

    <label for="introduction">Introducción (requerida para exportar)</label>
    <textarea id="introduction" name="introduction">{{ old('introduction', $book->introduction) }}</textarea>
    @error('introduction')<div class="err">{{ $message }}</div>@enderror

    <div class="grid2">
        <div>
            <label for="author_name">Nombre del creador</label>
            <input id="author_name" type="text" name="author_name" value="{{ old('author_name', $book->author_name) }}">
            @error('author_name')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="website_url">Website (opcional)</label>
            <input id="website_url" type="url" name="website_url" value="{{ old('website_url', $book->website_url) }}">
            @error('website_url')<div class="err">{{ $message }}</div>@enderror
        </div>
    </div>

    <label for="creator_description">Descripción del creador (requerida para exportar)</label>
    <textarea id="creator_description" name="creator_description">{{ old('creator_description', $book->creator_description) }}</textarea>
    @error('creator_description')<div class="err">{{ $message }}</div>@enderror

    <div class="grid2">
        <div>
            <label for="copyright_text">Texto de copyright (requerido para exportar)</label>
            <input id="copyright_text" type="text" name="copyright_text" value="{{ old('copyright_text', $book->copyright_text) }}">
            @error('copyright_text')<div class="err">{{ $message }}</div>@enderror
        </div>
        <div>
            <label for="copyright_year">Año de copyright</label>
            <input id="copyright_year" type="number" name="copyright_year" value="{{ old('copyright_year', $book->copyright_year) }}">
            @error('copyright_year')<div class="err">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="actions">
        <button class="btn" type="submit">Guardar</button>
        <a class="btn secondary" href="{{ $editing ? route('books.show', $book) : route('books.index') }}">Cancelar</a>
    </div>
</form>

@if ($editing)
<form method="POST" action="{{ route('books.destroy', $book) }}" onsubmit="return confirm('¿Eliminar este libro y todas sus imágenes?')">
    @csrf @method('DELETE')
    <button class="btn danger small" type="submit">Eliminar libro</button>
</form>
@endif
@endsection
