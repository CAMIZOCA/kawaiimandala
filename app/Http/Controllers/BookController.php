<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookRequest;
use App\Models\Book;
use App\Services\BookPaginationService;
use App\Services\MandalaStorageService;
use Illuminate\Http\RedirectResponse;

class BookController extends Controller
{
    public function index()
    {
        $books = Book::query()
            ->withCount(['mandalas as completed_count' => fn ($q) => $q->whereNotNull('image_path')])
            ->latest()
            ->get();

        return view('books.index', compact('books'));
    }

    public function create()
    {
        return view('books.form', [
            'book' => new Book([
                'mandala_count' => config('kawaii.default_mandala_count'),
                'author_name' => config('kawaii.default_author'),
                'copyright_year' => (int) date('Y'),
            ]),
        ]);
    }

    public function store(BookRequest $request): RedirectResponse
    {
        $book = Book::create($request->validated());
        $book->syncSlots();

        return redirect()->route('books.show', $book)
            ->with('status', "Libro creado con {$book->mandala_count} slots de mandala.");
    }

    public function show(Book $book)
    {
        $book->load('mandalas');

        return view('books.show', compact('book'));
    }

    public function pages(Book $book, BookPaginationService $pagination)
    {
        return view('books.pages', ['book' => $book, 'plan' => $pagination->buildPlan($book->mandala_count)]);
    }

    public function edit(Book $book)
    {
        return view('books.form', compact('book'));
    }

    public function update(BookRequest $request, Book $book): RedirectResponse
    {
        $book->update($request->validated());
        $book->syncSlots();
        $book->refreshStatus();

        return redirect()->route('books.show', $book)->with('status', 'Libro actualizado.');
    }

    public function destroy(Book $book, MandalaStorageService $storage): RedirectResponse
    {
        $storage->deleteBookFiles($book);
        $book->delete();

        return redirect()->route('books.index')->with('status', 'Libro eliminado.');
    }
}
