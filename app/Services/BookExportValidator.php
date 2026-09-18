<?php

namespace App\Services;

use App\Models\Book;

/** Decides whether a book may be exported and explains why not. */
class BookExportValidator
{
    public function __construct(
        private readonly MandalaStorageService $storage,
        private readonly BookPdfGenerator $generator,
    ) {}

    /**
     * @return list<string> empty when the book can be exported
     */
    public function validate(Book $book): array
    {
        $errors = [];
        $n = $book->mandala_count;
        $mandalas = $book->mandalas()->get();

        $positions = $mandalas->pluck('position');
        if ($positions->count() !== $positions->unique()->count()) {
            $errors[] = 'Existen posiciones de mandala duplicadas.';
        }
        if ($positions->contains(fn ($p) => $p < 1 || $p > $n)) {
            $errors[] = "Existen mandalas con posición fuera del rango 1–{$n}.";
        }

        $byPosition = $mandalas->keyBy('position');
        $missing = [];
        $unreadable = [];
        $lowRes = [];

        for ($i = 1; $i <= $n; $i++) {
            $mandala = $byPosition->get($i);

            if ($mandala === null || ! $mandala->hasImage()) {
                $missing[] = $i;

                continue;
            }

            $path = $this->storage->absolutePath($mandala);

            if ($path === null || @getimagesize($path) === false) {
                $unreadable[] = $i;

                continue;
            }

            if ($mandala->isLowRes()) {
                $lowRes[] = "{$i} ({$mandala->width_px}×{$mandala->height_px})";
            }
        }

        if ($missing) {
            $done = $n - count($missing);
            $errors[] = "Faltan mandalas ({$done} de {$n} completos): ".implode(', ', $missing).'.';
        }
        if ($unreadable) {
            $errors[] = 'No se pueden leer las imágenes de los mandalas: '.implode(', ', $unreadable).'.';
        }

        $min = config('kawaii.min_image_px');
        if ($lowRes && ! config('kawaii.allow_low_res_export')) {
            $errors[] = "Imágenes por debajo de {$min}×{$min} px (300 DPI): ".implode(', ', $lowRes).'.';
        }

        if (blank($book->introduction)) {
            $errors[] = 'Falta la introducción.';
        }
        if (blank($book->creator_description)) {
            $errors[] = 'Falta la descripción del creador.';
        }
        if (blank($book->copyright_text)) {
            $errors[] = 'Falta el texto de copyright.';
        }

        if (! $errors) {
            $errors = $this->generator->textFitErrors($book);
        }

        return $errors;
    }
}
