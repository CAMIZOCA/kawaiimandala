<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidMandalaImageException;
use App\Models\Book;
use App\Models\Mandala;
use App\Services\MandalaStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookMandalaController extends Controller
{
    public function __construct(private readonly MandalaStorageService $storage) {}

    /** Single upload into one slot. */
    public function upload(Request $request, Book $book, int $position): RedirectResponse
    {
        $mandala = $this->slot($book, $position);

        $request->validate(['image' => ['required', 'file']], [], ['image' => 'imagen']);

        try {
            $this->storage->storeUploaded($mandala, $request->file('image'));
        } catch (InvalidMandalaImageException $e) {
            return back()->with('error', "Mandala {$mandala->label()}: {$e->getMessage()}");
        }

        return back()->with($this->lowResNotice($mandala->fresh(), "Mandala {$mandala->label()} guardado."));
    }

    /** Multiple upload: natural filename order → Mandala 1..N. */
    public function uploadMany(Request $request, Book $book): RedirectResponse
    {
        $request->validate(['images' => ['required', 'array', 'min:1'], 'images.*' => ['file']], [], ['images' => 'imágenes']);

        $files = $request->file('images');

        if (count($files) > $book->mandala_count) {
            return back()->with('error', 'Seleccionaste '.count($files)." imágenes pero el libro solo tiene {$book->mandala_count} mandalas.");
        }

        usort($files, fn ($a, $b) => strnatcasecmp($a->getClientOriginalName(), $b->getClientOriginalName()));

        $done = [];
        $errors = [];

        foreach ($files as $index => $file) {
            $mandala = $this->slot($book, $index + 1);

            try {
                $this->storage->storeUploaded($mandala, $file);
                $done[] = $file->getClientOriginalName().' → Mandala '.$mandala->label();
            } catch (InvalidMandalaImageException $e) {
                $errors[] = $file->getClientOriginalName().' (Mandala '.$mandala->label().'): '.$e->getMessage();
            }
        }

        $redirect = back();

        if ($done) {
            $redirect->with('status', count($done).' imagen(es) asignada(s): '.implode('; ', $done));
        }

        if ($errors) {
            $redirect->with('error', implode(' | ', $errors));
        }

        return $redirect;
    }

    public function image(Book $book, int $position): BinaryFileResponse
    {
        $mandala = $this->slot($book, $position);
        $path = $this->storage->absolutePath($mandala);

        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=0, must-revalidate']);
    }

    public function destroy(Book $book, int $position): RedirectResponse
    {
        $mandala = $this->slot($book, $position);

        $this->storage->deleteImage($mandala);
        $mandala->forceFill([
            'image_path' => null, 'original_filename' => null, 'width_px' => null, 'height_px' => null,
            'generation_status' => GenerationStatus::Pending, 'request_token' => null,
            'requested_at' => null, 'generation_error' => null,
        ])->save();
        $book->refresh()->refreshStatus();

        return back()->with('status', "Imagen del Mandala {$mandala->label()} eliminada.");
    }

    private function slot(Book $book, int $position): Mandala
    {
        return $book->mandalas()->where('position', $position)->firstOrFail();
    }

    private function lowResNotice(Mandala $mandala, string $message): array
    {
        if ($mandala->isLowRes()) {
            $min = config('kawaii.min_image_px');

            return ['warning' => "{$message} Advertencia: {$mandala->width_px}×{$mandala->height_px} px, por debajo del mínimo {$min}×{$min} px para 300 DPI."];
        }

        return ['status' => $message];
    }
}
