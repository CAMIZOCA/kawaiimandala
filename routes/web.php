<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BookAiController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookMandalaController;
use App\Http\Controllers\BookPdfController;
use App\Http\Controllers\FlowSettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/books');

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/settings/flows', [FlowSettingsController::class, 'edit'])->name('settings.flows.edit');
    Route::put('/settings/flows', [FlowSettingsController::class, 'update'])->name('settings.flows.update');

    Route::get('/books', [BookController::class, 'index'])->name('books.index');
    Route::get('/books/new', [BookController::class, 'create'])->name('books.create');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    Route::get('/books/{book}', [BookController::class, 'show'])->name('books.show');
    Route::get('/books/{book}/edit', [BookController::class, 'edit'])->name('books.edit');
    Route::put('/books/{book}', [BookController::class, 'update'])->name('books.update');
    Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');
    Route::get('/books/{book}/status', [BookAiController::class, 'status'])->name('books.status');
    Route::post('/books/{book}/mandalas/{position}/generate', [BookAiController::class, 'generateOne'])->whereNumber('position')->name('books.mandalas.generate');
    Route::post('/books/{book}/ai/queue', [BookAiController::class, 'startQueue'])->name('books.ai.start');
    Route::post('/books/{book}/ai/queue/stop', [BookAiController::class, 'stopQueue'])->name('books.ai.stop');
    Route::get('/books/{book}/pdf/preview', [BookPdfController::class, 'preview'])->name('books.pdf.preview');
    Route::post('/books/{book}/pdf/export', [BookPdfController::class, 'export'])->name('books.pdf.export');
    Route::get('/books/{book}/pdf/download', [BookPdfController::class, 'download'])->name('books.pdf.download');
    Route::get('/books/{book}/pages', [BookController::class, 'pages'])->name('books.pages');

    Route::post('/books/{book}/mandalas', [BookMandalaController::class, 'uploadMany'])->name('books.mandalas.upload-many');
    Route::post('/books/{book}/mandalas/{position}', [BookMandalaController::class, 'upload'])->whereNumber('position')->name('books.mandalas.upload');
    Route::get('/books/{book}/mandalas/{position}/image', [BookMandalaController::class, 'image'])->whereNumber('position')->name('books.mandalas.image');
    Route::delete('/books/{book}/mandalas/{position}', [BookMandalaController::class, 'destroy'])->whereNumber('position')->name('books.mandalas.destroy');
});
