<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ActivepiecesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-very-long-shared-secret-1234567890';

    private const HOOK = 'https://hooks.test/webhooks/abc';

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        config([
            'kawaii.activepieces.enabled' => true,
            'kawaii.activepieces.webhook_url' => self::HOOK,
            'kawaii.activepieces.shared_secret' => self::SECRET,
            'kawaii.activepieces.public_url' => 'https://app.test',
        ]);

        $this->book = Book::create(['title' => 'T', 'animal_theme' => 'Capybara', 'mandala_count' => 3]);
        $this->book->syncSlots();
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function pngBytes(int $size = 300): string
    {
        return file_get_contents(MandalaUploadTest::png('x.png', $size, $size)->getRealPath());
    }

    private function postCallback(int $position, array $body, ?string $secret = self::SECRET)
    {
        return $this->postJson(
            "/api/activepieces/books/{$this->book->uuid}/mandalas/{$position}",
            $body,
            $secret === null ? [] : ['X-Callback-Secret' => $secret],
        );
    }

    private function token(int $position): string
    {
        return $this->book->mandalas()->where('position', $position)->first()->request_token;
    }

    // ------------------------------------------------------- request side

    public function test_generate_button_posts_the_expected_payload_and_marks_slot_requested(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);

        $this->actingAs($this->user())
            ->post("/books/{$this->book->uuid}/mandalas/2/generate")
            ->assertSessionHas('status');

        $mandala = $this->book->mandalas()->where('position', 2)->first();
        $this->assertSame('requested', $mandala->generation_status->value);
        $this->assertNotNull($mandala->request_token);
        $this->assertSame('waiting_mandalas', $this->book->fresh()->status->value);

        Http::assertSent(function (HttpRequest $r) use ($mandala) {
            $d = $r->data();

            return $r->url() === self::HOOK
                && $d['book_uuid'] === $this->book->uuid
                && $d['position'] === 2
                && $d['count'] === 3
                && $d['animal_theme'] === 'Capybara'
                && $d['style_profile'] === 'kawaii_mandala_v1'
                && $d['output'] === ['format' => 'png', 'width_px' => 2550, 'height_px' => 2550, 'background' => 'white', 'color_mode' => 'black_and_white']
                && $d['request_token'] === $mandala->request_token
                && $d['callback_url'] === "https://app.test/api/activepieces/books/{$this->book->uuid}/mandalas/2"
                && $d['callback_secret'] === self::SECRET;
        });
    }

    public function test_second_request_is_refused_while_one_is_in_flight(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $user = $this->user();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate")->assertSessionHas('status');
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate")->assertSessionHas('error');

        Http::assertSentCount(1);
    }

    public function test_webhook_error_marks_slot_failed_and_app_keeps_working(): void
    {
        Http::fake([self::HOOK => Http::response('boom', 500)]);

        $this->actingAs($this->user())
            ->post("/books/{$this->book->uuid}/mandalas/1/generate")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'HTTP 500'));

        $mandala = $this->book->mandalas()->where('position', 1)->first();
        $this->assertSame('failed', $mandala->generation_status->value);
        $this->assertNotEmpty($mandala->generation_error);
        $this->assertSame('draft', $this->book->fresh()->status->value);

        $this->actingAs($this->user())->get("/books/{$this->book->uuid}")->assertOk()->assertSee('Reintentar');
    }

    public function test_unreachable_webhook_marks_slot_failed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: refused'));

        $this->actingAs($this->user())
            ->post("/books/{$this->book->uuid}/mandalas/1/generate")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No se pudo contactar'));

        $this->assertSame('failed', $this->book->mandalas()->where('position', 1)->first()->generation_status->value);
    }

    public function test_controls_only_show_when_enabled_and_configured(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get("/books/{$this->book->uuid}")->assertSee('Generar con AI')->assertSee('por turno');

        config(['kawaii.activepieces.enabled' => false]);
        $this->actingAs($user)->get("/books/{$this->book->uuid}")->assertDontSee('Generar con AI')->assertDontSee('Activepieces');
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate")->assertSessionHas('error');
    }

    public function test_short_secret_counts_as_not_configured(): void
    {
        config(['kawaii.activepieces.shared_secret' => 'short']);
        Http::fake();

        $this->actingAs($this->user())->post("/books/{$this->book->uuid}/mandalas/1/generate")->assertSessionHas('error');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------ callback side

    public function test_callback_rejects_missing_or_wrong_secret(): void
    {
        $this->postCallback(1, ['image_base64' => base64_encode($this->pngBytes())], null)->assertStatus(401);
        $this->postCallback(1, ['image_base64' => base64_encode($this->pngBytes())], 'nope')->assertStatus(401);
        $this->assertNull($this->book->mandalas()->where('position', 1)->first()->image_path);
    }

    public function test_callback_is_unavailable_when_integration_is_off(): void
    {
        config(['kawaii.activepieces.enabled' => false]);
        $this->postCallback(1, [])->assertStatus(503);
    }

    public function test_callback_accepts_secret_from_body_or_bearer(): void
    {
        $body = ['image_base64' => base64_encode($this->pngBytes()), 'callback_secret' => self::SECRET];
        $this->postCallback(1, $body, null)->assertOk();

        $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas/2", ['image_base64' => base64_encode($this->pngBytes())], ['Authorization' => 'Bearer '.self::SECRET])->assertOk();
    }

    public function test_callback_rejects_invalid_position(): void
    {
        $b64 = base64_encode($this->pngBytes());

        $this->postCallback(99, ['image_base64' => $b64])->assertStatus(422);
        $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas", ['position' => 0, 'image_base64' => $b64], ['X-Callback-Secret' => self::SECRET])->assertStatus(422);
        $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas", ['image_base64' => $b64], ['X-Callback-Secret' => self::SECRET])->assertStatus(422);
    }

    public function test_callback_rejects_invalid_image_and_marks_slot_failed(): void
    {
        $this->postCallback(1, ['image_base64' => base64_encode('<?php echo 1;')])->assertStatus(422);

        $mandala = $this->book->mandalas()->where('position', 1)->first();
        $this->assertNull($mandala->image_path);
        $this->assertSame('failed', $mandala->generation_status->value);

        $this->postCallback(2, ['image_url' => 'ftp://evil/x.png'])->assertStatus(422);
        $this->postCallback(2, [])->assertStatus(422);
    }

    public function test_callback_with_valid_image_url_saves_into_the_right_slot(): void
    {
        Http::fake([
            self::HOOK => Http::response(['ok' => true]),
            'https://cdn.test/*' => Http::response($this->pngBytes(320), 200, ['Content-Type' => 'image/png']),
        ]);
        $this->actingAs($this->user())->post("/books/{$this->book->uuid}/mandalas/2/generate");

        $this->postCallback(2, [
            'image_url' => 'https://cdn.test/mandala-02.png',
            'prompt' => 'a cute capybara mandala',
            'request_token' => $this->token(2),
        ])->assertOk()->assertJson(['ok' => true, 'position' => 2]);

        $m2 = $this->book->mandalas()->where('position', 2)->first();
        $this->assertSame("books/{$this->book->uuid}/mandalas/002.png", $m2->image_path);
        $this->assertSame('activepieces', $m2->generation_source);
        $this->assertSame('done', $m2->generation_status->value);
        $this->assertSame('a cute capybara mandala', $m2->prompt);
        $this->assertNull($m2->request_token);
        $this->assertSame([320, 320], [$m2->width_px, $m2->height_px]);
        $this->assertNull($this->book->mandalas()->where('position', 1)->first()->image_path);
        Storage::disk('local')->assertExists($m2->image_path);
    }

    public function test_callback_with_wrong_token_is_rejected_while_a_request_is_outstanding(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $this->actingAs($this->user())->post("/books/{$this->book->uuid}/mandalas/1/generate");

        $this->postCallback(1, ['image_base64' => base64_encode($this->pngBytes()), 'request_token' => 'wrong'])->assertStatus(409);
        $this->postCallback(1, ['image_base64' => base64_encode($this->pngBytes())])->assertStatus(409);
        $this->assertNull($this->book->mandalas()->where('position', 1)->first()->image_path);
    }

    public function test_error_callback_marks_slot_failed_and_stops_queue(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $this->actingAs($this->user())->post("/books/{$this->book->uuid}/ai/queue");
        $this->assertTrue($this->book->fresh()->ai_queue_active);

        $this->postCallback(1, ['error' => 'model refused', 'request_token' => $this->token(1)])->assertOk()->assertJson(['ok' => false]);

        $m1 = $this->book->mandalas()->where('position', 1)->first();
        $this->assertSame('failed', $m1->generation_status->value);
        $this->assertSame('model refused', $m1->generation_error);
        $this->assertFalse($this->book->fresh()->ai_queue_active);
    }

    // ----------------------------------------------------------- the queue

    public function test_queue_requests_one_mandala_at_a_time_and_advances_on_each_callback(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $user = $this->user();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue")->assertSessionHas('status');
        Http::assertSentCount(1);
        $this->assertSame('requested', $this->book->mandalas()->where('position', 1)->first()->generation_status->value);
        $this->assertSame('pending', $this->book->mandalas()->where('position', 2)->first()->generation_status->value);

        $this->postCallback(1, ['image_base64' => base64_encode($this->pngBytes()), 'request_token' => $this->token(1)])
            ->assertOk()->assertJson(['next_requested' => 2]);
        Http::assertSentCount(2);

        $this->postCallback(2, ['image_base64' => base64_encode($this->pngBytes()), 'request_token' => $this->token(2)])
            ->assertOk()->assertJson(['next_requested' => 3]);

        $this->assertSame('waiting_mandalas', $this->book->fresh()->status->value);

        $this->postCallback(3, ['image_base64' => base64_encode($this->pngBytes()), 'request_token' => $this->token(3)])
            ->assertOk()->assertJson(['next_requested' => null, 'book_status' => 'ready']);

        $book = $this->book->fresh();
        $this->assertSame('ready', $book->status->value);
        $this->assertFalse($book->ai_queue_active);
        $this->assertSame(3, $book->completedCount());
        Http::assertSentCount(3);
    }

    public function test_queue_skips_slots_that_already_have_an_image(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1", ['image' => MandalaUploadTest::png('1.png', 300, 300)]);

        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue");

        Http::assertSent(fn (HttpRequest $r) => $r->data()['position'] === 2);
    }

    public function test_stopping_the_queue_prevents_further_requests(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue");
        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue/stop");

        $this->postCallback(1, ['image_base64' => base64_encode($this->pngBytes()), 'request_token' => $this->token(1)])
            ->assertOk()->assertJson(['next_requested' => null]);

        Http::assertSentCount(1);
    }

    public function test_status_endpoint_reports_in_flight_slots(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/3/generate");

        $this->actingAs($user)->getJson("/books/{$this->book->uuid}/status")
            ->assertOk()
            ->assertJsonPath('in_flight', [3])
            ->assertJsonPath('book_status', 'waiting_mandalas')
            ->assertJsonPath('mandalas.2.status', 'requested');
    }

    public function test_manual_upload_and_pdf_flow_do_not_depend_on_activepieces(): void
    {
        config(['kawaii.activepieces.enabled' => false, 'kawaii.activepieces.webhook_url' => null]);
        Http::fake(fn () => throw new ConnectionException('down'));
        $user = $this->user();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1", ['image' => MandalaUploadTest::png('1.png', 300, 300)])
            ->assertSessionMissing('error');

        $this->assertSame(1, $this->book->completedCount());
    }
}
