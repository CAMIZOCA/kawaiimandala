<?php

namespace App\Http\Controllers;

use App\Enums\BookStatus;
use App\Exceptions\PdfExportException;
use App\Models\Book;
use App\Services\BookExportValidator;
use App\Services\BookPdfGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookPdfController extends Controller
{
    public function __construct(
        private readonly BookPdfGenerator $generator,
        private readonly BookExportValidator $validator,
    ) {}

    /** Renders the PDF inline without saving it or changing the book status. */
    public function preview(Book $book): Response|RedirectResponse
    {
        if ($errors = $this->validator->validate($book)) {
            return $this->blocked($book, $errors);
        }

        try {
            $binary = $this->generator->render($book);
        } catch (PdfExportException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->filename($book).'"',
        ]);
    }

    public function export(Book $book): RedirectResponse
    {
        if ($errors = $this->validator->validate($book)) {
            return $this->blocked($book, $errors);
        }

        try {
            $binary = $this->generator->render($book);
        } catch (PdfExportException $e) {
            return back()->with('error', $e->getMessage());
        }

        Storage::disk('local')->put($this->exportPath($book), $binary);

        $book->status = BookStatus::Exported;
        $book->save();

        return redirect()->route('books.show', $book)->with('status', 'PDF exportado: '.$this->filename($book));
    }

    public function download(Book $book): StreamedResponse
    {
        $path = $this->exportPath($book);

        abort_unless(Storage::disk('local')->exists($path), 404, 'Aún no hay un PDF exportado.');

        return Storage::disk('local')->download($path, $this->filename($book), ['Content-Type' => 'application/pdf']);
    }

    private function blocked(Book $book, array $errors): RedirectResponse
    {
        return redirect()->route('books.show', $book)
            ->with('error', 'No se puede exportar: '.implode(' ', $errors));
    }

    private function filename(Book $book): string
    {
        return (Str::slug($book->title) ?: 'book').'-interior-8.5x8.5.pdf';
    }

    private function exportPath(Book $book): string
    {
        return $book->storageDir('exports').'/'.$this->filename($book);
    }
}
