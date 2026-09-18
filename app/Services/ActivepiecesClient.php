<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\ActivepiecesException;
use App\Exceptions\FlowNotConfiguredException;
use App\Jobs\SendMandalaRequest;
use App\Models\Book;
use App\Models\Mandala;
use App\Models\MandalaFlow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only place that knows about Activepieces. One request = one mandala:
 * the flow linked to that page position (Configuración → Flujos) generates the
 * image and calls back with the result, which ActivepiecesCallbackController
 * saves. The webhook call itself runs in a queued job (SendMandalaRequest) and
 * nothing here waits for the image.
 *
 * Every request gets its own token and callback secret; only the sha256 of the
 * secret is stored on the page.
 */
class ActivepiecesClient
{
    public function isEnabled(): bool
    {
        return (bool) config('kawaii.activepieces.enabled');
    }

    /** Integration switched on (callbacks are authenticated with a per-request secret). */
    public function isConfigured(): bool
    {
        return $this->isEnabled();
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
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
     * as requested (new token + secret) before queueing the webhook call, so even a
     * very fast callback finds a valid token.
     *
     * @throws FlowNotConfiguredException when the position has no usable flow link
     * @throws ActivepiecesException
     */
    public function requestMandala(Mandala $mandala): Mandala
    {
        $this->assertConfigured();

        if (MandalaFlow::urlFor($mandala->position) === null) {
            throw new FlowNotConfiguredException(
                "No hay un enlace de flujo para el mandala {$mandala->label()}. Configúralo en Configuración → Flujos.",
            );
        }

        if ($mandala->isInFlight()) {
            throw new ActivepiecesException("El mandala {$mandala->label()} ya tiene una solicitud en curso.");
        }

        $token = Str::random(40);
        $secret = Str::random(40);

        $mandala->forceFill([
            'generation_status' => GenerationStatus::Requested,
            'generation_attempts' => $mandala->generation_attempts + 1,
            'request_token' => $token,
            'callback_secret_hash' => self::hashSecret($secret),
            'completed_token' => null,
            'requested_at' => now(),
            'responded_at' => null,
            'generation_error' => null,
            'error_code' => null,
        ])->save();
        $mandala->book->refreshStatus();

        SendMandalaRequest::dispatch($mandala->id, $token, $secret);

        return $mandala->refresh();
    }

    /**
     * Queue: keep up to `max_concurrent` pages in flight, taking the pages without an
     * image in order. Returns the first page requested (null when none was) and
     * switches the queue off once nothing is left.
     *
     * @throws ActivepiecesException
     */
    public function requestNext(Book $book): ?Mandala
    {
        $this->expireStale($book);

        $mandalas = $book->mandalas()->get();
        $inFlight = $mandalas->filter(fn (Mandala $m) => $m->isInFlight())->count();
        $free = (int) config('kawaii.activepieces.max_concurrent') - $inFlight;
        $candidates = $mandalas->filter(fn (Mandala $m) => ! $m->hasImage() && ! $m->isInFlight());

        if ($candidates->isEmpty()) {
            if ($inFlight === 0) {
                $this->stopQueue($book);
            }

            return null;
        }

        $first = null;

        foreach ($candidates->take(max(0, $free)) as $next) {
            try {
                $requested = $this->requestMandala($next);
            } catch (ActivepiecesException $e) {
                $this->stopQueue($book);
                throw $e;
            }

            $first ??= $requested;
        }

        return $first;
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
     * Requests that never got a callback within the timeout become `timeout`, so the
     * UI offers a retry and a running queue does not hang forever.
     *
     * @return int number of slots expired
     */
    public function expireStale(Book $book): int
    {
        return $this->expire($this->staleQuery()->where('book_id', $book->id)->get(), $book);
    }

    /** Same for every book (used by the scheduled `activepieces:expire-stale`). */
    public function expireStaleAll(): int
    {
        return $this->expire($this->staleQuery()->get());
    }

    /**
     * A failed request. Without $answered nothing reached (or will come back from) the
     * flow, so the token and secret are voided. With $answered the flow itself reported the
     * failure: its token stays as `completed_token`, so a repeated callback is a no-op.
     */
    public function markFailed(Mandala $mandala, string $message, ?string $code = null, bool $answered = false): void
    {
        $text = Str::limit(($code ? "[{$code}] " : '').$message, 500);

        $mandala->forceFill([
            'generation_status' => GenerationStatus::Failed,
            'completed_token' => $answered ? $mandala->request_token : null,
            'callback_secret_hash' => $answered ? $mandala->callback_secret_hash : null,
            'request_token' => null,
            'generation_error' => $text,
            'error_code' => $code,
            'responded_at' => now(),
        ])->save();
        $mandala->book->refreshStatus();

        Log::warning('Activepieces mandala generation failed', [
            'book' => $mandala->book->uuid,
            'position' => $mandala->position,
            'attempt' => $mandala->generation_attempts,
            'code' => $code,
            'reason' => $text,
        ]);
    }

    /**
     * No callback in time. The token and secret are kept, so a late (but still valid)
     * image for this very request is accepted unless the page was requested again.
     */
    public function markTimeout(Mandala $mandala): void
    {
        $limit = (int) config('kawaii.activepieces.stale_after_minutes');

        $mandala->forceFill([
            'generation_status' => GenerationStatus::Timeout,
            'generation_error' => "[timeout] Sin respuesta de Activepieces tras {$limit} min.",
            'error_code' => 'timeout',
        ])->save();
        $mandala->book->refreshStatus();

        Log::warning('Activepieces mandala generation timed out', [
            'book' => $mandala->book->uuid,
            'position' => $mandala->position,
            'attempt' => $mandala->generation_attempts,
        ]);
    }

    /** @return array<string, mixed> */
    public function payload(Mandala $mandala, string $secret): array
    {
        $book = $mandala->book;
        $notes = $mandala->prompt !== null && $mandala->prompt !== ''
            ? mb_substr($mandala->prompt, 0, (int) config('kawaii.activepieces.max_prompt_chars'))
            : null;

        return [
            'book_uuid' => $book->uuid,
            'position' => $mandala->position,
            'flow_position' => $mandala->position,
            'count' => $book->mandala_count,
            'title' => $book->title,
            'subtitle' => $book->subtitle,
            'animal_theme' => $book->animal_theme,
            'prompt' => $notes,
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
            'callback_secret' => $secret,
        ];
    }

    public function callbackUrl(Mandala $mandala): string
    {
        return $this->publicUrl().route('api.activepieces.mandala', [$mandala->book->uuid, $mandala->position], false);
    }

    /** @return Builder<Mandala> */
    private function staleQuery()
    {
        $limit = (int) config('kawaii.activepieces.stale_after_minutes');

        return Mandala::query()
            ->with('book')
            ->where('generation_status', GenerationStatus::Requested->value)
            ->where('requested_at', '<', now()->subMinutes($limit));
    }

    /** @param  Collection<int, Mandala>  $stale */
    private function expire($stale, ?Book $book = null): int
    {
        foreach ($stale as $mandala) {
            $this->markTimeout($mandala);
        }

        if ($stale->isEmpty()) {
            return 0;
        }

        // Use the caller's instance when given so it sees the queue switched off.
        foreach ($book ? [$book] : $stale->pluck('book')->unique('id') as $affected) {
            $this->stopQueue($affected);
        }

        return $stale->count();
    }

    /** @throws ActivepiecesException */
    private function assertConfigured(): void
    {
        if (! $this->isEnabled()) {
            throw new ActivepiecesException('Activepieces está desactivado (ACTIVEPIECES_ENABLED=0 en .env).');
        }
    }
}
