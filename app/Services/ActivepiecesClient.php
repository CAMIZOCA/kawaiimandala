<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\ActivepiecesException;
use App\Models\Book;
use App\Models\Mandala;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The only place that knows about Activepieces. One request = one mandala:
 * a dedicated flow generates the image and calls back with the result, which
 * ActivepiecesCallbackController saves. Nothing here waits for the image.
 */
class ActivepiecesClient
{
    public const MIN_SECRET_LENGTH = 16;

    public function isEnabled(): bool
    {
        return (bool) config('kawaii.activepieces.enabled');
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && filled(config('kawaii.activepieces.webhook_url'))
            && strlen((string) config('kawaii.activepieces.shared_secret')) >= self::MIN_SECRET_LENGTH;
    }

    /**
     * Ask the flow to generate one mandala. Marks the slot as requested before
     * calling out so a very fast callback finds a valid token.
     *
     * @throws ActivepiecesException
     */
    public function requestMandala(Mandala $mandala): Mandala
    {
        $this->assertConfigured();

        if ($mandala->isInFlight()) {
            throw new ActivepiecesException("El mandala {$mandala->label()} ya tiene una solicitud en curso.");
        }

        $mandala->forceFill([
            'generation_status' => GenerationStatus::Requested,
            'request_token' => Str::random(40),
            'requested_at' => now(),
            'generation_error' => null,
        ])->save();
        $mandala->book->refreshStatus();

        try {
            $response = Http::timeout(config('kawaii.activepieces.request_timeout_seconds'))
                ->acceptJson()
                ->asJson()
                ->post(config('kawaii.activepieces.webhook_url'), $this->payload($mandala));
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

    public function startQueue(Book $book): ?Mandala
    {
        $this->assertConfigured();

        $book->forceFill(['ai_queue_active' => true])->save();

        return $this->requestNext($book);
    }

    public function stopQueue(Book $book): void
    {
        if ($book->ai_queue_active) {
            $book->forceFill(['ai_queue_active' => false])->save();
        }
    }

    public function markFailed(Mandala $mandala, string $message): void
    {
        $mandala->forceFill([
            'generation_status' => GenerationStatus::Failed,
            'request_token' => null,
            'generation_error' => Str::limit($message, 500),
        ])->save();
        $mandala->book->refreshStatus();
    }

    /** @return array<string, mixed> */
    public function payload(Mandala $mandala): array
    {
        $book = $mandala->book;

        return [
            'book_uuid' => $book->uuid,
            'position' => $mandala->position,
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
        $base = rtrim((string) (config('kawaii.activepieces.public_url') ?: config('app.url')), '/');
        $path = route('api.activepieces.mandala', [$mandala->book->uuid, $mandala->position], false);

        return $base.$path;
    }

    /** @throws ActivepiecesException */
    private function fail(Mandala $mandala, string $message): never
    {
        $this->markFailed($mandala, $message);

        throw new ActivepiecesException($message);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ActivepiecesException('Activepieces no está configurado (ACTIVEPIECES_ENABLED, ACTIVEPIECES_WEBHOOK_URL y un ACTIVEPIECES_SHARED_SECRET de al menos '.self::MIN_SECRET_LENGTH.' caracteres).');
        }
    }
}
