@extends('layouts.app')
@section('title', 'Configuración de flujos')
@section('content')
<h1>Configuración → Flujos de Activepieces</h1>
<p class="muted">Cada página (mandala 1…N) tiene su propio flujo en Activepieces. Pega aquí el enlace del webhook (URL de producción) de cada flujo. Son los mismos flujos para todos los libros; lo que cambia es el animal que envía cada libro.</p>

<div class="card">
    <h2 style="margin-top:0">Estado de la integración</h2>
    <ul style="margin:.3rem 0 0;padding-left:1.2rem">
        <li>
            @if ($client->isEnabled()) <span class="badge ready">activada</span>
            @else <span class="badge failed">desactivada</span> Pon <code>ACTIVEPIECES_ENABLED=1</code> en <code>.env</code>. @endif
        </li>
        <li>
            @if ($client->hasValidSecret()) <span class="badge ready">secreto OK</span>
            @else <span class="badge failed">falta secreto</span> Define <code>ACTIVEPIECES_SHARED_SECRET</code> (mínimo {{ \App\Services\ActivepiecesClient::MIN_SECRET_LENGTH }} caracteres) en <code>.env</code>. @endif
        </li>
        <li>
            @if ($warning = $client->publicUrlWarning()) <span class="badge requested">revisar</span> {{ $warning }}
            @else <span class="badge ready">URL pública OK</span> @endif
            <div class="muted">Callback: <code>{{ $client->publicUrl() }}/api/activepieces/books/{book_uuid}/mandalas/{posición}</code></div>
        </li>
        <li>
            <strong>{{ $configured }} de {{ $rows }}</strong> flujos configurados
            @if ($configured < $rows) <span class="badge requested">faltan {{ $rows - $configured }}</span> @else <span class="badge ready">completo</span> @endif
        </li>
    </ul>
</div>

<form method="POST" action="{{ route('settings.flows.update') }}" class="card">
    @csrf @method('PUT')
    @error('flows')<div class="err">{{ $message }}</div>@enderror
    <table>
        <thead><tr><th style="width:6rem">Mandala</th><th>Enlace del flujo (webhook)</th><th style="width:6rem">Activo</th><th style="width:8rem">Estado</th></tr></thead>
        <tbody>
        @foreach (range(1, $rows) as $position)
            @php
                $flow = $flows->get($position);
                $url = old("flows.$position.flow_url", $flow?->flow_url);
                $enabled = old("flows.$position.enabled", $flow?->enabled ?? true);
            @endphp
            <tr>
                <td><strong>{{ str_pad($position, 2, '0', STR_PAD_LEFT) }}</strong></td>
                <td>
                    <input type="url" name="flows[{{ $position }}][flow_url]" value="{{ $url }}" placeholder="https://activepieces.medio-digital.net/api/v1/webhooks/…">
                    @error("flows.$position.flow_url")<div class="err">{{ $message }}</div>@enderror
                </td>
                <td>
                    <input type="hidden" name="flows[{{ $position }}][enabled]" value="0">
                    <input type="checkbox" name="flows[{{ $position }}][enabled]" value="1" @checked($enabled)>
                </td>
                <td>
                    @if ($flow?->isUsable()) <span class="badge ready">configurado</span>
                    @elseif ($flow && filled($flow->flow_url)) <span class="badge">inactivo</span>
                    @else <span class="badge failed">sin enlace</span> @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="actions"><button class="btn" type="submit">Guardar enlaces</button></div>
</form>
@endsection
