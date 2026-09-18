@extends('layouts.app')
@section('title', $book->title)
@section('content')
@php
    $completed = $book->mandalas->whereNotNull('image_path')->count();
@endphp
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

@php
    $ap = app(\App\Services\ActivepiecesClient::class);
    $apOn = $ap->isEnabled();
    $apReady = $ap->isConfigured();
    $inFlight = $book->mandalas->filter->isInFlight();
    $pendingCount = $book->mandala_count - $completed;
    $missingFlows = $apReady ? $ap->missingFlowPositions($book) : [];
    $flowless = $apReady ? \App\Models\MandalaFlow::missingFor($book->mandalas->pluck('position')) : [];
@endphp
<div class="card" id="ai-card">
    <h2 style="margin-top:0">Generar con Activepieces</h2>
    @if (! $apOn)
        <div class="flash warn" style="margin-bottom:0">Activepieces está desactivado. Pon <code>ACTIVEPIECES_ENABLED=1</code> en <code>.env</code> para poder generar mandalas con AI (la carga manual sigue funcionando).</div>
    @elseif (! $ap->hasValidSecret())
        <div class="flash warn" style="margin-bottom:0">Falta <code>ACTIVEPIECES_SHARED_SECRET</code> (mínimo {{ \App\Services\ActivepiecesClient::MIN_SECRET_LENGTH }} caracteres) en <code>.env</code>.</div>
    @else
        <p class="muted">Cada página tiene su propio flujo en Activepieces; el flujo genera el mandala del animal «{{ $book->animal_theme }}» y devuelve la imagen para guardarla en su slot. Puedes pedir un mandala suelto o «por turno», uno a la vez.</p>
        @if ($warning = $ap->publicUrlWarning())
            <div class="flash warn">{{ $warning }}</div>
        @endif
        @if ($missingFlows)
            <div class="flash bad">Faltan enlaces de flujo para los mandalas pendientes: <strong>{{ implode(', ', $missingFlows) }}</strong>. <a href="{{ route('settings.flows.edit') }}">Configurar flujos →</a></div>
        @endif
        <div class="actions">
            @if ($book->ai_queue_active)
                <span class="badge requested"><span class="spinner"></span> Cola activa</span>
                <form method="POST" action="{{ route('books.ai.stop', $book) }}">@csrf
                    <button class="btn danger" type="submit">Detener cola</button>
                </form>
            @else
                <form method="POST" action="{{ route('books.ai.start', $book) }}">@csrf
                    <button class="btn" type="submit" @disabled($pendingCount === 0 || $missingFlows)>Generar los {{ $pendingCount }} pendientes por turno</button>
                </form>
                @if ($pendingCount === 0) <span class="muted">No hay mandalas pendientes.</span>
                @elseif ($missingFlows) <span class="muted">Configura primero los enlaces que faltan.</span> @endif
            @endif
            <a class="btn secondary" href="{{ route('settings.flows.edit') }}">Configurar flujos</a>
        </div>
    @endif
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
        @if ($apOn && ! $mandala->hasImage())
            <div class="meta">
                @if ($mandala->isInFlight())
                    <span class="badge requested"><span class="spinner"></span> solicitado {{ $mandala->requested_at->format('H:i') }}@if ($mandala->generation_attempts > 1) · intento {{ $mandala->generation_attempts }}@endif</span>
                @elseif ($mandala->generation_status === \App\Enums\GenerationStatus::Failed)
                    <span class="badge failed">error</span> {{ $mandala->generation_error }}
                @endif
            </div>
        @endif
        @if ($apReady)
            @if (in_array($mandala->position, $flowless, true))
                <div class="meta"><a href="{{ route('settings.flows.edit') }}">⚠ Sin enlace de flujo</a></div>
            @endif
            <form method="POST" action="{{ route('books.mandalas.generate', [$book, $mandala->position]) }}">
                @csrf
                <button class="btn small" type="submit" @disabled($mandala->isInFlight() || in_array($mandala->position, $flowless, true))>
                    @if ($mandala->isInFlight()) Esperando… @elseif ($mandala->hasImage()) Regenerar con AI @elseif ($mandala->generation_status === \App\Enums\GenerationStatus::Failed) Reintentar @else Generar con AI @endif
                </button>
            </form>
        @endif
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
@if ($apReady && ($inFlight->isNotEmpty() || $book->ai_queue_active))
@push('scripts')
<script>
    // Poll only while a generation is pending; reload when a slot resolves.
    (function () {
        const url = @json(route('books.status', $book));
        let known = null;
        async function tick() {
            try {
                const r = await fetch(url, {headers: {'Accept': 'application/json'}});
                if (!r.ok) return;
                const d = await r.json();
                const sig = d.mandalas.map(m => m.position + ':' + m.status + ':' + (m.has_image ? 1 : 0)).join('|') + '#' + d.ai_queue_active;
                if (known !== null && sig !== known) { location.reload(); return; }
                known = sig;
            } catch (e) { /* network hiccup: try again next tick */ }
        }
        tick();
        setInterval(tick, 5000);
    })();
</script>
@endpush
@endif
@endsection
