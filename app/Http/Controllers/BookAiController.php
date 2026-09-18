<?php

namespace App\Http\Controllers;

use App\Exceptions\ActivepiecesException;
use App\Models\Book;
use App\Services\ActivepiecesClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/** Buttons that ask Activepieces to generate mandalas (one flow run per mandala). */
class BookAiController extends Controller
{
    public function __construct(private readonly ActivepiecesClient $client) {}

    public function generateOne(Book $book, int $position): RedirectResponse
    {
        $mandala = $book->mandalas()->where('position', $position)->firstOrFail();

        try {
            $this->client->requestMandala($mandala);
        } catch (ActivepiecesException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Generación solicitada. La imagen aparecerá cuando Activepieces la devuelva.');
    }

    public function startQueue(Book $book): RedirectResponse
    {
        try {
            $started = $this->client->startQueue($book);
        } catch (ActivepiecesException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($started === null) {
            return back()->with('warning', 'No hay mandalas pendientes o ya hay uno en curso.');
        }

        return back()->with('status', "Cola iniciada: mandala {$started->label()} solicitado. Los siguientes se solicitarán uno a uno al recibir cada imagen.");
    }

    public function stopQueue(Book $book): RedirectResponse
    {
        $this->client->stopQueue($book);

        return back()->with('status', 'Cola detenida. Las solicitudes en curso seguirán llegando.');
    }

    /** Light JSON state used by the polling script on the book page. */
    public function status(Book $book): JsonResponse
    {
        if ($this->client->expireStale($book) > 0) {
            $book->refresh();
        }

        $mandalas = $book->mandalas()->get();

        return response()->json([
            'book_status' => $book->status->value,
            'completed' => $mandalas->filter->hasImage()->count(),
            'ai_queue_active' => $book->ai_queue_active,
            'in_flight' => $mandalas->filter->isInFlight()->pluck('position')->values(),
            'mandalas' => $mandalas->map(fn ($m) => [
                'position' => $m->position,
                'has_image' => $m->hasImage(),
                'status' => $m->generation_status->value,
            ])->values(),
        ]);
    }
}
