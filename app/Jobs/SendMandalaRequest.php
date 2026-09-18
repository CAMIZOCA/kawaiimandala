<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Mandala;
use App\Models\MandalaFlow;
use App\Services\ActivepiecesClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Calls the webhook of the flow linked to one page position. The flow answers 200 at
 * once and delivers the image later through the callback, so this only needs a short
 * HTTP timeout. Runs once (no retries): a failed page is retried by the user.
 */
class SendMandalaRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $mandalaId,
        public readonly string $token,
        public readonly string $secret,
    ) {}

    public function handle(ActivepiecesClient $client): void
    {
        $mandala = Mandala::with('book')->find($this->mandalaId);

        // Superseded (retry, manual upload, deleted): nothing to send.
        if ($mandala === null
            || $mandala->generation_status !== GenerationStatus::Requested
            || $mandala->request_token !== $this->token) {
            return;
        }

        $url = MandalaFlow::urlFor($mandala->position);

        if ($url === null) {
            $client->markFailed($mandala, "No hay un enlace de flujo para el mandala {$mandala->label()}.", 'dispatch_error');

            return;
        }

        Log::info('Activepieces request sent', [
            'book' => $mandala->book->uuid,
            'position' => $mandala->position,
            'attempt' => $mandala->generation_attempts,
            'flow' => MandalaFlow::flowIdFromUrl($url),
        ]);

        try {
            $response = Http::timeout((int) config('kawaii.activepieces.request_timeout_seconds'))
                ->acceptJson()
                ->asJson()
                ->post($url, $client->payload($mandala, $this->secret));
        } catch (ConnectionException $e) {
            $client->markFailed($mandala, 'No se pudo contactar con Activepieces: '.Str::limit($e->getMessage(), 200), 'dispatch_error');

            return;
        }

        if (! $response->successful()) {
            $client->markFailed($mandala, 'Activepieces respondió con HTTP '.$response->status().'.', 'dispatch_error');
        }
    }

    public function failed(Throwable $e): void
    {
        $mandala = Mandala::with('book')->find($this->mandalaId);

        if ($mandala?->request_token === $this->token && $mandala->generation_status === GenerationStatus::Requested) {
            app(ActivepiecesClient::class)->markFailed($mandala, 'Error interno al enviar la solicitud: '.Str::limit($e->getMessage(), 200), 'dispatch_error');
        }
    }
}
