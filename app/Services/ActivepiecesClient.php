<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\ActivepiecesException;
use App\Exceptions\FlowNotConfiguredException;
use App\Models\Book;
use App\Models\Mandala;
use App\Models\MandalaFlow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only place that knows about Activepieces. One request = one mandala:
 * the flow linked to that page position (Configuración → Flujos) generates the
 * image and calls back with the result, which ActivepiecesCallbackController
 * saves. Nothing here waits for the image.
 */
class ActivepiecesClient
{
    public const MIN_SECRET_LENGTH = 16;

    public function isEnabled(): bool
    {
        return (bool) config('kawaii.activepieces.enabled');
    }

    public function hasValidSecret(): bool
    {
        return strlen((string) config('kawaii.activepieces.shared_secret')) >= self::MIN_SECRET_LENGTH;
    }

    /** Integration switched on and able to authenticate callbacks. */
    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->hasValidSecret();
    }

    /** Base URL the flows use to call back; null-safe helper for the UI. */
    public function publicUrl(): string
    {
        return rtrim((string) (config('kawaii.activepieces.public_url') ?: config('app.url')), '/');
    }

    /** Warning when the callback base URL looks unreachable from a remote Activepieces. */
    public function publicUrlWarning(): ?string
    {
        $configured = filled(config('kawaii.activepieces.public_url'));
        $host = strtolower((string) parse_url($this->publicUrl(), PHP_URL_HOST));
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || Str::endsWith($host, ['.test', '.local', '.localhost']);

        if (! $configured) {
            return 'APP_PUBLIC_URL está vacío: se usará '.$this->publicUrl().'. Activepieces debe poder alcanzar esa URL para devolver las imágenes.';
        }

        return $local
            ? "APP_PUBLIC_URL ({$this->publicUrl()}) parece una dirección local: un Activepieces remoto no podrá devolver las imágenes. Usa un túnel (cloudflared/ngrok) o una URL pública."
            : null;
    }

    /**
     * Ask the flow of this page position to generate one mandala. Marks the slot
     * as requested before calling out so a very fast callback finds a valid token.
     *
     * @throws FlowNotConfiguredException when the position has no usable flow link
     * @throws ActivepiecesException
     */
    public function requestMandala(Mandala $mandala): Mandala
    {
        $this->assertConfigured();

        $url = MandalaFlow::urlFor($mandala->position);

        if ($url === null) {
            throw new FlowNotConfiguredException(
                "No hay un enlace de flujo para el mandala {$mandala->label()}. Configúralo en Configuración → Flujos.",
            );
        }

        if ($mandala->isInFlight()) {
            throw new ActivepiecesException("El mandala {$mandala->label()} ya tiene una solicitud en curso.");
        }

        $mandala->forceFill([
            'generation_status' => GenerationStatus::Requested,
            'generation_attempts' => $mandala->generation_attempts + 1,
            'request_token' => Str::random(40),
            'requested_at' => now(),
            'generation_error' => null,
        ])->save();
        $mandala->book->refreshStatus();

        try {
            $response = Http::timeout(config('kawaii.activepieces.request_timeout_seconds'))
                ->acceptJson()
                ->asJson()
                ->post($url, $this->payload($mandala));
        } catch (ConnectionException $e) {
            $this->fail($mandala, 'No se pudo contactar con Activepieces: '.Str::limit($e->getMessage(), 200));
        }

        if (! $response->successful()) {
            $this->fail($mandala, 'Activepieces respondió con HTTP '.$response->status().'.');
        }

        return $mandala->refresh();
    }

    /**
     * Turn-based queue: request the first mandala without an image, one at a
     * time. Returns null (and switches the queue off) when nothing is left.
     *
     * @throws ActivepiecesException
     */
    public function requestNext(Book $book): ?Mandala
    {
        $this->expireStale($book);

        $mandalas = $book->mandalas()->get();

        if ($mandalas->contains(fn (Mandala $m) => $m->isInFlight())) {
            return null; // one request in flight at a time
        }

        $next = $mandalas->first(fn (Mandala $m) => ! $m->hasImage());

        if ($next === null) {
            $this->stopQueue($book);

            return null;
        }

        try {
            return $this->requestMandala($next);
        } catch (ActivepiecesException $e) {
            $this->stopQueue($book);
            throw $e;
        }
    }

    /**
     * @throws ActivepiecesException when not configured or any pending page has no flow link
     */
    public function startQueue(Book $book): ?Mandala
    {
        $this->assertConfigured();

        $missing = $this->missingFlowPositions($book);

        if ($missing) {
            throw new FlowNotConfiguredException(
                'Faltan enlaces de flujo para los mandalas: '.implode(', ', $missing).'. Configúralos en Configuración → Flujos.',
            );
        }

        $book->forceFill(['ai_queue_active' => true])->save();

        return $this->requestNext($book);
    }

    public function stopQueue(Book $book): void
    {
        if ($book->ai_queue_active) {
            $book->forceFill(['ai_queue_active' => false])->save();
        }
    }

    /**
     * Positions still without an image whose page has no usable flow link.
     *
     * @return list<int>
     */
    public function missingFlowPositions(Book $book): array
    {
        $pending = $book->mandalas()->whereNull('image_path')->pluck('position');

        return MandalaFlow::missingFor($pending);
    }

    /**
     * Requests that never got a callback within the timeout become failed, so the
     * UI offers a retry and a running queue does not hang forever.
     *
     * @return int number of slots expired
     */
    public function expireStale(Book $book): int
    {
        $limit = (int) config('kawaii.activepieces.stale_after_minutes');

        $stale = $book->mandalas()
            ->where('generation_status', GenerationStatus::Requested->value)
            ->where('requested_at', '<', now()->subMinutes($limit))
            ->get();

        foreach ($stale as $mandala) {
            $this->markFailed($mandala, "Sin respuesta de Activepieces tras {$limit} min.", 'timeout');
        }

        if ($stale->isNotEmpty()) {
            $this->stopQueue($book);
        }

        return $stale->count();
    }

    public function markFailed(Mandala $mandala, string $message, ?string $code = null): void
    {
        $text = Str::limit(($code ? "[{$code}] " : '').$message, 500);

        $mandala->forceFill([
            'generation_status' => GenerationStatus::Failed,
            'request_token' => null,
            'generation_error' => $text,
        ])->save();
        $mandala->book->refreshStatus();

        Log::warning('Activepieces mandala generation failed', [
            'book' => $mandala->book->uuid,
            'position' => $mandala->position,
            'attempt' => $mandala->generation_attempts,
            'reason' => $text,
        ]);
    }

    /** @return array<string, mixed> */
    public function payload(Mandala $mandala): array
    {
        $book = $mandala->book;

        return [
            'book_uuid' => $book->uuid,
            'position' => $mandala->position,
            'flow_position' => $mandala->position,
            'count' => $book->mandala_count,
            'title' => $book->title,
            'subtitle' => $book->subtitle,
            'animal_theme' => $book->animal_theme,
            'prompt' => $mandala->prompt,
            'style_profile' => config('kawaii.activepieces.style_profile'),
            'output' => [
                'format' => 'png',
                'width_px' => config('kawaii.target_image_px'),
                'height_px' => config('kawaii.target_image_px'),
                // Ask the image model for this size; this app upscales to width/height_px.
                'provider_size' => '1024x1024',
                'background' => 'white',
                'color_mode' => 'black_and_white',
            ],
            'request_token' => $mandala->request_token,
            'callback_url' => $this->callbackUrl($mandala),
            'callback_secret' => config('kawaii.activepieces.shared_secret'),
        ];
    }

    public function callbackUrl(Mandala $mandala): string
    {
        return $this->publicUrl().route('api.activepieces.mandala', [$mandala->book->uuid, $mandala->position], false);
    }

    /** @throws ActivepiecesException */
    private function fail(Mandala $mandala, string $message): never
    {
        $this->markFailed($mandala, $message);

        throw new ActivepiecesException($message);
    }

    private function assertConfigured(): void
    {
        if (! $this->isEnabled()) {
            throw new ActivepiecesException('Activepieces está desactivado (ACTIVEPIECES_ENABLED=0 en .env).');
        }

        if (! $this->hasValidSecret()) {
            throw new ActivepiecesException('Falta ACTIVEPIECES_SHARED_SECRET (mínimo '.self::MIN_SECRET_LENGTH.' caracteres) en .env.');
        }
    }
}
