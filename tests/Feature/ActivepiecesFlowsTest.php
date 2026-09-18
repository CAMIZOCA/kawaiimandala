<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Book;
use App\Models\MandalaFlow;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActivepiecesHelpers;
use Tests\TestCase;

/** One flow per page position, missing-link messages, timeouts, error codes, upscale. */
class ActivepiecesFlowsTest extends TestCase
{
    use ActivepiecesHelpers;
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'kawaii.activepieces.enabled' => true,
            'kawaii.activepieces.public_url' => 'https://app.test',
        ]);

        $this->book = Book::create(['title' => 'T', 'animal_theme' => 'Lion', 'mandala_count' => 3]);
        $this->book->syncSlots();
    }

    private function flow(int $position, bool $enabled = true): void
    {
        MandalaFlow::create(['position' => $position, 'flow_url' => "https://hooks.test/flow/{$position}", 'enabled' => $enabled]);
    }

    private function slot(int $position)
    {
        return $this->book->mandalas()->where('position', $position)->first();
    }

    private function png(int $w, int $h): string
    {
        return file_get_contents(MandalaUploadTest::png('x.png', $w, $h)->getRealPath());
    }

    /** Send the webhook request for a page (secret and token are read back from the recorded call). */
    private function request(int $position): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->flow($position);
        $this->actingAs(User::factory()->create())->post("/books/{$this->book->uuid}/mandalas/{$position}/generate");
    }

    // ------------------------------------------------- one flow per position

    public function test_each_position_calls_its_own_flow_url(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->flow(1);
        $this->flow(3);
        $user = User::factory()->create();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/3/generate")->assertSessionHas('status');
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate")->assertSessionHas('status');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.test/flow/3' && $r->data()['position'] === 3 && $r->data()['animal_theme'] === 'Lion');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.test/flow/1' && $r->data()['position'] === 1);
        Http::assertSentCount(2);
    }

    public function test_missing_link_shows_a_message_sends_nothing_and_does_not_fail_the_slot(): void
    {
        Http::fake();
        $this->flow(1);

        $this->actingAs(User::factory()->create())
            ->post("/books/{$this->book->uuid}/mandalas/2/generate")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No hay un enlace de flujo para el mandala 02')
                && str_contains($m, 'Configuración'));

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Pending, $this->slot(2)->generation_status);
        $this->assertSame(0, $this->slot(2)->generation_attempts);
    }

    public function test_disabled_flow_counts_as_missing(): void
    {
        Http::fake();
        $this->flow(1, enabled: false);

        $this->actingAs(User::factory()->create())->post("/books/{$this->book->uuid}/mandalas/1/generate")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No hay un enlace'));
        Http::assertNothingSent();
    }

    public function test_queue_refuses_to_start_listing_pages_without_flow(): void
    {
        Http::fake();
        $this->flow(2);

        $this->actingAs(User::factory()->create())->post("/books/{$this->book->uuid}/ai/queue")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Faltan enlaces de flujo para los mandalas: 1, 3'));

        Http::assertNothingSent();
        $this->assertFalse($this->book->fresh()->ai_queue_active);
    }

    public function test_queue_only_needs_links_for_pages_still_pending(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->flow(2);
        $this->flow(3);
        $user = User::factory()->create();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1", ['image' => MandalaUploadTest::png('1.png', 300, 300)]);

        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue")->assertSessionHas('status');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.test/flow/2');
    }

    public function test_book_page_shows_missing_flow_banner_and_per_slot_notice(): void
    {
        $this->flow(1);

        $this->actingAs(User::factory()->create())->get("/books/{$this->book->uuid}")
            ->assertOk()
            ->assertSee('Faltan enlaces de flujo para los mandalas pendientes')
            ->assertSee('2, 3')
            ->assertSee('Sin enlace de flujo')
            ->assertSee(route('settings.flows.edit'), false);
    }

    public function test_book_page_explains_when_integration_is_disabled(): void
    {
        config(['kawaii.activepieces.enabled' => false]);

        $this->actingAs(User::factory()->create())->get("/books/{$this->book->uuid}")->assertSee('Activepieces está desactivado');
    }

    public function test_attempt_counter_increases_on_each_request(): void
    {
        Http::fake(['hooks.test/*' => Http::response('boom', 500)]);
        $this->flow(1);
        $user = User::factory()->create();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate");
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate");

        $this->assertSame(2, $this->slot(1)->generation_attempts);
    }

    // ---------------------------------------------------------------- timeout

    public function test_stale_request_becomes_timeout_and_stops_the_queue(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        foreach ([1, 2, 3] as $p) {
            $this->flow($p);
        }
        $user = User::factory()->create();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue");
        $this->assertTrue($this->book->fresh()->ai_queue_active);

        $this->slot(1)->forceFill(['requested_at' => now()->subMinutes(11)])->save();

        $this->actingAs($user)->get("/books/{$this->book->uuid}")
            ->assertOk()
            ->assertSee('Sin respuesta de Activepieces tras 10 min')
            ->assertSee('Reintentar');

        $this->assertSame(GenerationStatus::Timeout, $this->slot(1)->generation_status);
        $this->assertSame('timeout', $this->slot(1)->error_code);
        $this->assertFalse($this->book->fresh()->ai_queue_active);
    }

    public function test_recent_request_is_not_expired(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->flow(1);
        $user = User::factory()->create();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate");
        $this->slot(1)->forceFill(['requested_at' => now()->subMinutes(5)])->save();

        $this->actingAs($user)->getJson("/books/{$this->book->uuid}/status")->assertJsonPath('in_flight', [1]);
        $this->assertSame(GenerationStatus::Requested, $this->slot(1)->generation_status);
    }

    public function test_expire_command_times_out_requests_of_every_book_and_is_scheduled(): void
    {
        $other = Book::create(['title' => 'O', 'animal_theme' => 'Fox', 'mandala_count' => 2]);
        $other->syncSlots();
        $this->book->mandalas()->where('position', 1)->update(['generation_status' => 'requested', 'requested_at' => now()->subMinutes(11)]);
        $other->mandalas()->where('position', 1)->update(['generation_status' => 'requested', 'requested_at' => now()->subMinutes(11)]);
        $this->book->mandalas()->where('position', 2)->update(['generation_status' => 'requested', 'requested_at' => now()->subMinutes(3)]);

        $this->artisan('activepieces:expire-stale')->expectsOutputToContain('2 solicitud')->assertSuccessful();

        $this->assertSame(GenerationStatus::Timeout, $this->slot(1)->generation_status);
        $this->assertSame(GenerationStatus::Timeout, $other->mandalas()->where('position', 1)->first()->generation_status);
        $this->assertSame(GenerationStatus::Requested, $this->slot(2)->generation_status, 'a recent request is left alone');

        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains($event->command, 'activepieces:expire-stale') && $event->expression === '* * * * *');
        $this->assertTrue($scheduled);
    }

    // ------------------------------------------------------------ error codes

    public function test_error_callback_stores_the_error_code(): void
    {
        $this->request(1);

        $this->postCallback(1, $this->withIssuedToken(1, ['error' => 'blocked by policy', 'error_code' => 'content_policy']))
            ->assertOk()->assertJson(['ok' => false]);

        $this->assertSame('[content_policy] blocked by policy', $this->slot(1)->generation_error);
    }

    // ----------------------------------------------------------------- upscale

    public function test_small_ai_image_is_upscaled_to_print_size(): void
    {
        $this->request(2);
        $this->postCallback(2, $this->withIssuedToken(2, ['image_base64' => base64_encode($this->png(1024, 1024))]))->assertOk();

        $m = $this->slot(2);
        $this->assertSame([2550, 2550], [$m->width_px, $m->height_px]);
        $this->assertFalse($m->isLowRes());

        $info = getimagesizefromstring(Storage::disk('local')->get($m->image_path));
        $this->assertSame([2550, 2550, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function test_non_square_ai_image_is_padded_to_a_square(): void
    {
        $this->request(1);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->png(1024, 600))]))->assertOk();

        $this->assertSame([2550, 2550], [$this->slot(1)->width_px, $this->slot(1)->height_px]);
    }

    public function test_upscale_can_be_disabled(): void
    {
        config(['kawaii.auto_upscale' => false]);

        $this->request(1);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->png(400, 400))]))->assertOk();

        $this->assertSame([400, 400], [$this->slot(1)->width_px, $this->slot(1)->height_px]);
    }

    public function test_full_size_ai_image_is_stored_untouched(): void
    {
        $bytes = $this->png(2550, 2550);
        $this->request(1);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($bytes)]))->assertOk();

        $this->assertSame($bytes, Storage::disk('local')->get($this->slot(1)->image_path));
        $this->assertNull($this->slot(1)->original_image_path, 'nothing to keep when the image was not touched');
    }

    public function test_manual_uploads_are_never_upscaled(): void
    {
        $this->actingAs(User::factory()->create())
            ->post("/books/{$this->book->uuid}/mandalas/1", ['image' => MandalaUploadTest::png('1.png', 500, 500)]);

        $this->assertSame([500, 500], [$this->slot(1)->width_px, $this->slot(1)->height_px]);
    }
}
