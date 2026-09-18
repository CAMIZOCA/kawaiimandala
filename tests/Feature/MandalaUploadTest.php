<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MandalaUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->book = Book::create([
            'title' => 'T', 'animal_theme' => 'Capybara', 'mandala_count' => 3,
        ]);
        $this->book->syncSlots();
    }

    public static function png(string $name, int $w = 2550, int $h = 2550): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img, null, 9);
        $bytes = ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_single_upload_saves_with_internal_name(): void
    {
        $this->actingAs($this->user)
            ->post("/books/{$this->book->uuid}/mandalas/2", ['image' => self::png('../../evil name.png')])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $mandala = $this->book->mandalas()->where('position', 2)->first();
        $this->assertSame("books/{$this->book->uuid}/mandalas/002.png", $mandala->image_path);
        $this->assertSame([2550, 2550], [$mandala->width_px, $mandala->height_px]);
        $this->assertSame('manual', $mandala->generation_source);
        $this->assertFalse($mandala->isLowRes());
        Storage::disk('local')->assertExists($mandala->image_path);
    }

    public function test_low_resolution_shows_warning(): void
    {
        $this->actingAs($this->user)
            ->post("/books/{$this->book->uuid}/mandalas/1", ['image' => self::png('a.png', 800, 800)])
            ->assertSessionHas('warning');

        $this->assertTrue($this->book->mandalas()->where('position', 1)->first()->isLowRes());
    }

    public function test_fake_image_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;');

        $this->actingAs($this->user)
            ->post("/books/{$this->book->uuid}/mandalas/1", ['image' => $file])
            ->assertSessionHas('error');

        $this->assertNull($this->book->mandalas()->where('position', 1)->first()->image_path);
    }

    public function test_multi_upload_uses_natural_order(): void
    {
        $this->actingAs($this->user)->post("/books/{$this->book->uuid}/mandalas", [
            'images' => [self::png('10.png', 300, 300), self::png('2.png', 400, 400), self::png('1.png', 500, 500)],
        ])->assertSessionHas('status');

        $widths = $this->book->mandalas()->pluck('width_px', 'position')->all();
        $this->assertSame([1 => 500, 2 => 400, 3 => 300], $widths);
    }

    public function test_multi_upload_rejects_more_than_slots(): void
    {
        $this->actingAs($this->user)->post("/books/{$this->book->uuid}/mandalas", [
            'images' => [self::png('1.png', 300, 300), self::png('2.png', 300, 300), self::png('3.png', 300, 300), self::png('4.png', 300, 300)],
        ])->assertSessionHas('error');

        $this->assertSame(0, $this->book->completedCount());
    }

    public function test_book_becomes_ready_when_all_slots_are_filled(): void
    {
        $this->actingAs($this->user)->post("/books/{$this->book->uuid}/mandalas", [
            'images' => [self::png('1.png', 300, 300), self::png('2.png', 300, 300)],
        ]);
        $this->assertSame('draft', $this->book->fresh()->status->value);

        $this->actingAs($this->user)->post("/books/{$this->book->uuid}/mandalas/3", ['image' => self::png('3.png', 300, 300)]);
        $this->assertSame('ready', $this->book->fresh()->status->value);
    }

    public function test_image_route_requires_auth_and_serves_file(): void
    {
        $this->actingAs($this->user)->post("/books/{$this->book->uuid}/mandalas/1", ['image' => self::png('1.png', 300, 300)]);

        auth()->logout();
        $this->get("/books/{$this->book->uuid}/mandalas/1/image")->assertRedirect('/login');
        $this->actingAs($this->user)->get("/books/{$this->book->uuid}/mandalas/1/image")->assertOk();
        $this->actingAs($this->user)->get("/books/{$this->book->uuid}/mandalas/2/image")->assertNotFound();
    }

    public function test_removing_an_image_reverts_status(): void
    {
        $this->actingAs($this->user)->post("/books/{$this->book->uuid}/mandalas", [
            'images' => [self::png('1.png', 300, 300), self::png('2.png', 300, 300), self::png('3.png', 300, 300)],
        ]);
        $this->assertSame('ready', $this->book->fresh()->status->value);

        $this->actingAs($this->user)->delete("/books/{$this->book->uuid}/mandalas/2");

        $this->assertSame('draft', $this->book->fresh()->status->value);
        $this->assertNull($this->book->mandalas()->where('position', 2)->first()->image_path);
    }
}
