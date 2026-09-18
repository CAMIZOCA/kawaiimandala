<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookCrudTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Cute Capybara Mandalas',
            'subtitle' => '22 Relaxing Animal Coloring Designs',
            'animal_theme' => 'Capybara',
            'mandala_count' => 22,
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/books')->assertRedirect('/login');
        $this->get('/books/new')->assertRedirect('/login');
        $this->post('/books', $this->payload())->assertRedirect('/login');
    }

    public function test_new_book_form_defaults_to_22_mandalas(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/books/new')
            ->assertOk()
            ->assertSee('value="22"', false);
    }

    public function test_creating_a_book_creates_all_slots(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/books', $this->payload());

        $book = Book::firstOrFail();
        $response->assertRedirect(route('books.show', $book));

        $this->assertSame(22, $book->mandalas()->count());
        $this->assertSame(range(1, 22), $book->mandalas()->pluck('position')->all());
        $this->assertSame('draft', $book->status->value);
        $this->assertNotEmpty($book->uuid);
    }

    public function test_validation_rules(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/books', $this->payload(['title' => '']))->assertSessionHasErrors('title');
        $this->actingAs($user)->post('/books', $this->payload(['animal_theme' => '']))->assertSessionHasErrors('animal_theme');
        $this->actingAs($user)->post('/books', $this->payload(['mandala_count' => 0]))->assertSessionHasErrors('mandala_count');
        $this->actingAs($user)->post('/books', $this->payload(['mandala_count' => 23]))->assertSessionHasErrors('mandala_count');
        $this->actingAs($user)->post('/books', $this->payload(['title' => str_repeat('a', 256)]))->assertSessionHasErrors('title');
        $this->assertSame(0, Book::count());
    }

    public function test_changing_count_adds_and_removes_empty_slots(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/books', $this->payload(['mandala_count' => 12]));
        $book = Book::firstOrFail();

        $this->actingAs($user)->put("/books/{$book->uuid}", $this->payload(['mandala_count' => 22]))->assertSessionHasNoErrors();
        $this->assertSame(22, $book->mandalas()->count());

        $this->actingAs($user)->put("/books/{$book->uuid}", $this->payload(['mandala_count' => 10]))->assertSessionHasNoErrors();
        $this->assertSame(10, $book->mandalas()->count());
    }

    public function test_index_and_show_render(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/books', $this->payload());
        $book = Book::firstOrFail();

        $this->actingAs($user)->get('/books')->assertOk()->assertSee('Cute Capybara Mandalas')->assertSee('0 / 22');
        $this->actingAs($user)->get("/books/{$book->uuid}")->assertOk()->assertSee('pendiente');
        $this->actingAs($user)->get("/books/{$book->uuid}/pages")->assertOk()->assertSee('Page 47')->assertSee('RIGHT / RECTO');
    }

    public function test_login_flow(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-1']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-1'])->assertRedirect('/books');
        $this->assertAuthenticatedAs($user);
    }
}
