<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function book(int $count = 3, array $overrides = []): Book
    {
        $book = Book::create($overrides + [
            'title' => 'Cute Capybara Mandalas',
            'subtitle' => 'Relaxing Designs',
            'animal_theme' => 'Capybara',
            'introduction' => "Hello.\n\nEnjoy coloring.",
            'author_name' => 'Marvin Baptista',
            'creator_description' => 'Marvin creates coloring books.',
            'copyright_text' => 'All rights reserved.',
            'copyright_year' => 2026,
            'mandala_count' => $count,
        ]);
        $book->syncSlots();

        return $book;
    }

    private function fill(Book $book, int $upTo, int $size = 2250): void
    {
        for ($i = 1; $i <= $upTo; $i++) {
            $this->actingAs($this->user)->post("/books/{$book->uuid}/mandalas/{$i}", [
                'image' => MandalaUploadTest::png("{$i}.png", $size, $size),
            ]);
        }
    }

    public function test_export_is_blocked_when_a_mandala_is_missing(): void
    {
        $book = $this->book(3);
        $this->fill($book, 2);

        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export")
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Faltan mandalas (2 de 3 completos): 3'));

        $this->assertSame('draft', $book->fresh()->status->value);
        Storage::disk('local')->assertMissing($book->storageDir('exports').'/cute-capybara-mandalas-interior-8.5x8.5.pdf');
    }

    public function test_export_is_blocked_without_required_texts(): void
    {
        $book = $this->book(2, ['introduction' => null, 'creator_description' => '', 'copyright_text' => null]);
        $this->fill($book, 2);

        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'introducción')
                && str_contains($m, 'descripción del creador')
                && str_contains($m, 'copyright'));
    }

    public function test_export_is_blocked_for_low_resolution_unless_dev_mode(): void
    {
        $book = $this->book(1);
        $this->fill($book, 1, 800);

        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export")
            ->assertSessionHas('error', fn ($m) => str_contains($m, '2250'));

        config(['kawaii.allow_low_res_export' => true]);
        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export")->assertSessionMissing('error');
    }

    public function test_full_book_exports_a_correct_pdf(): void
    {
        $book = $this->book(3);
        $this->fill($book, 3);

        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export")
            ->assertRedirect(route('books.show', $book))
            ->assertSessionMissing('error');

        $path = $book->storageDir('exports').'/cute-capybara-mandalas-interior-8.5x8.5.pdf';
        Storage::disk('local')->assertExists($path);
        $this->assertSame('exported', $book->fresh()->status->value);

        $pdf = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF', $pdf);
        // 3 mandalas -> 2N + 4 = 10 pages, every page 8.5 x 8.5 in (612 x 612 pt).
        $this->assertStringContainsString('/Count 10', $pdf);
        // 10 page objects + the root page-tree default.
        $this->assertSame(11, substr_count($pdf, '/MediaBox [0 0 612.000 612.000]'));
        $this->assertSame(0, preg_match('/\/MediaBox \[0 0 (?!612\.000 612\.000)/', $pdf));
    }

    public function test_changing_content_after_export_marks_the_book_ready_again(): void
    {
        $book = $this->book(1);
        $this->fill($book, 1);
        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export");
        $this->assertSame('exported', $book->fresh()->status->value);

        $this->actingAs($this->user)->put("/books/{$book->uuid}", [
            'title' => 'New title', 'animal_theme' => 'Capybara', 'mandala_count' => 1,
        ]);

        $this->assertSame('ready', $book->fresh()->status->value);
    }

    public function test_too_long_introduction_is_reported_instead_of_adding_pages(): void
    {
        $book = $this->book(1, ['introduction' => str_repeat("Lorem ipsum dolor sit amet consectetur.\n", 400)]);
        $this->fill($book, 1);

        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'demasiado largo'));
    }

    public function test_download_requires_auth_and_an_existing_export(): void
    {
        $book = $this->book(1);
        $this->fill($book, 1);

        auth()->logout();
        $this->get("/books/{$book->uuid}/pdf/download")->assertRedirect('/login');
        $this->actingAs($this->user)->get("/books/{$book->uuid}/pdf/download")->assertNotFound();

        $this->actingAs($this->user)->post("/books/{$book->uuid}/pdf/export");
        $this->actingAs($this->user)->get("/books/{$book->uuid}/pdf/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_preview_streams_inline_pdf(): void
    {
        $book = $this->book(1);
        $this->fill($book, 1);

        $response = $this->actingAs($this->user)->get("/books/{$book->uuid}/pdf/preview");
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame('ready', $book->fresh()->status->value, 'preview must not change the status');
    }
}
