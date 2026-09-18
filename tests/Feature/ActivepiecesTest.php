<?php

namespace Tests\Feature;

use App\Jobs\SendMandalaRequest;
use App\Models\Book;
use App\Models\MandalaFlow;
use App\Models\User;
use App\Services\ActivepiecesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActivepiecesHelpers;
use Tests\TestCase;

class ActivepiecesTest extends TestCase
{
    use ActivepiecesHelpers;
    use RefreshDatabase;

    private const HOOK = 'https://hooks.test/webhooks/m';

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        config([
            'kawaii.activepieces.enabled' => true,
            'kawaii.activepieces.public_url' => 'https://app.test',
        ]);

        foreach ([1, 2, 3] as $position) {
            MandalaFlow::create(['position' => $position, 'flow_url' => self::HOOK.$position]);
        }

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

    private function slot(int $position)
    {
        return $this->book->mandalas()->where('position', $position)->first();
    }

    private function generate(int $position, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user())->post("/books/{$this->book->uuid}/mandalas/{$position}/generate");
    }

    // ------------------------------------------------------- request side

    public function test_generate_button_posts_the_expected_payload_and_marks_slot_requested(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);

        $this->generate(2)->assertSessionHas('status');

        $mandala = $this->slot(2);
        $this->assertSame('requested', $mandala->generation_status->value);
        $this->assertNotNull($mandala->request_token);
        $this->assertSame('waiting_mandalas', $this->book->fresh()->status->value);

        Http::assertSent(function (HttpRequest $r) use ($mandala) {
            $d = $r->data();

            return $r->url() === self::HOOK.'2'
                && $r->hasHeader('Content-Type', 'application/json')
                && $d['book_uuid'] === $this->book->uuid
                && $d['position'] === 2
                && $d['count'] === 3
                && $d['title'] === 'T'
                && $d['animal_theme'] === 'Capybara'
                && $d['prompt'] === null
                && $d['style_profile'] === 'kawaii_mandala_v1'
                && $d['output'] === ['format' => 'png', 'width_px' => 2550, 'height_px' => 2550, 'provider_size' => '1024x1024', 'background' => 'white', 'color_mode' => 'black_and_white']
                && $d['request_token'] === $mandala->request_token
                && $d['callback_url'] === "https://app.test/api/activepieces/books/{$this->book->uuid}/mandalas/2"
                && strlen($d['callback_secret']) === 40;
        });
    }

    public function test_the_webhook_call_runs_in_a_queued_job(): void
    {
        Queue::fake();
        Http::fake();

        $this->generate(1)->assertSessionHas('status');

        Queue::assertPushed(SendMandalaRequest::class, fn (SendMandalaRequest $job) => $job->mandalaId === $this->slot(1)->id
            && $job->token === $this->slot(1)->request_token);
        Http::assertNothingSent();
        $this->assertSame('requested', $this->slot(1)->generation_status->value);
    }

    public function test_only_the_hash_of_the_secret_is_stored_and_each_request_gets_a_new_one(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();

        $this->generate(1, $user);
        $first = $this->issued(1);
        $stored = $this->slot(1)->callback_secret_hash;

        $this->assertSame(hash('sha256', $first['secret']), $stored);
        $this->assertNotSame($first['secret'], $stored);

        $this->slot(1)->forceFill(['generation_status' => 'failed'])->save();
        $this->generate(1, $user);
        $second = $this->issued(1);

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($first['secret'], $second['secret']);
        $this->assertSame(hash('sha256', $second['secret']), $this->slot(1)->callback_secret_hash);
    }

    public function test_retry_does_not_resend_the_final_prompt_as_notes(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->generate(1, $user);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes()), 'prompt' => 'FINAL PROMPT USED']))->assertOk();

        $this->assertSame('FINAL PROMPT USED', $this->slot(1)->final_prompt);
        $this->assertNull($this->slot(1)->prompt);

        $this->generate(1, $user);

        Http::assertSent(fn (HttpRequest $r) => $r->data()['prompt'] === null && $r->data()['position'] === 1);
    }

    public function test_user_notes_are_sent_as_prompt_and_capped(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->slot(1)->forceFill(['prompt' => str_repeat('n', 40000)])->save();

        $this->generate(1);

        Http::assertSent(fn (HttpRequest $r) => strlen($r->data()['prompt']) === 30000);
    }

    public function test_second_request_is_refused_while_one_is_in_flight(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();

        $this->generate(1, $user)->assertSessionHas('status');
        $this->generate(1, $user)->assertSessionHas('error');

        Http::assertSentCount(1);
    }

    public function test_webhook_error_marks_slot_failed_and_app_keeps_working(): void
    {
        Http::fake(['hooks.test/*' => Http::response('boom', 500)]);

        $this->generate(1);

        $mandala = $this->slot(1);
        $this->assertSame('failed', $mandala->generation_status->value);
        $this->assertStringContainsString('HTTP 500', $mandala->generation_error);
        $this->assertSame('dispatch_error', $mandala->error_code);
        $this->assertNull($mandala->request_token);
        $this->assertNull($mandala->callback_secret_hash);
        $this->assertSame('draft', $this->book->fresh()->status->value);

        $this->actingAs($this->user())->get("/books/{$this->book->uuid}")->assertOk()->assertSee('Reintentar');
    }

    public function test_unreachable_webhook_marks_slot_failed(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: refused'));

        $this->generate(1);

        $this->assertSame('failed', $this->slot(1)->generation_status->value);
        $this->assertStringContainsString('No se pudo contactar', $this->slot(1)->generation_error);
    }

    public function test_job_does_nothing_when_the_request_was_superseded(): void
    {
        Queue::fake();
        Http::fake();
        $this->generate(1);
        $old = $this->slot(1)->request_token;

        // A newer request replaced the token before the queued job ran.
        $this->slot(1)->forceFill(['request_token' => 'newer-token'])->save();
        (new SendMandalaRequest($this->slot(1)->id, $old, 'secret'))->handle(app(ActivepiecesClient::class));

        Http::assertNothingSent();
    }

    public function test_controls_only_show_when_enabled(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get("/books/{$this->book->uuid}")->assertSee('Generar con AI')->assertSee('por turno');

        config(['kawaii.activepieces.enabled' => false]);
        $this->actingAs($user)->get("/books/{$this->book->uuid}")->assertDontSee('Generar con AI')->assertSee('Activepieces está desactivado');
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1/generate")->assertSessionHas('error');
    }

    // ------------------------------------------------------ callback side

    public function test_callback_stores_the_upscaled_image_and_the_original(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes(1024)), 'prompt' => 'p']))
            ->assertOk()->assertJson(['ok' => true, 'position' => 1]);

        $m = $this->slot(1);
        $this->assertSame([2550, 2550], [$m->width_px, $m->height_px]);
        $this->assertSame("books/{$this->book->uuid}/mandalas/001.png", $m->image_path);
        $this->assertSame("books/{$this->book->uuid}/mandalas/originals/001.png", $m->original_image_path);
        $this->assertSame([1024, 1024], array_slice(getimagesizefromstring(Storage::disk('local')->get($m->original_image_path)), 0, 2));
        $this->assertSame('done', $m->generation_status->value);
        $this->assertNull($m->request_token);
        $this->assertNotNull($m->responded_at);
    }

    public function test_removing_the_image_removes_the_original_too(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes(1024))]))->assertOk();
        $original = $this->slot(1)->original_image_path;
        Storage::disk('local')->assertExists($original);

        $this->actingAs($this->user())->delete("/books/{$this->book->uuid}/mandalas/1");

        Storage::disk('local')->assertMissing($original);
        $this->assertNull($this->slot(1)->original_image_path);
    }

    public function test_callback_rejects_missing_or_wrong_secret(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $body = $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes())]);

        $this->postCallback(1, $body, null)->assertStatus(401);
        $this->postCallback(1, $body, 'nope')->assertStatus(401);
        // The secret issued for another page is not valid here.
        $this->generate(2);
        $this->postCallback(1, $body, $this->issued(2)['secret'])->assertStatus(401);
        $this->assertNull($this->slot(1)->image_path);
    }

    public function test_callback_without_any_request_is_unauthorized(): void
    {
        $this->postCallback(1, ['request_token' => 'x', 'image_base64' => base64_encode($this->pngBytes())], 'whatever')->assertStatus(401);
    }

    public function test_callback_is_unavailable_when_integration_is_off(): void
    {
        config(['kawaii.activepieces.enabled' => false]);
        $this->postCallback(1, [], 'x')->assertStatus(503);
    }

    public function test_callback_accepts_secret_from_body_or_bearer(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $this->generate(2);

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes()), 'callback_secret' => $this->issued(1)['secret']]), null)->assertOk();

        $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas/2", $this->withIssuedToken(2, ['image_base64' => base64_encode($this->pngBytes())]), ['Authorization' => 'Bearer '.$this->issued(2)['secret']])->assertOk();
    }

    public function test_callback_for_unknown_book_is_404(): void
    {
        $this->postJson('/api/activepieces/books/TEST/mandalas/1', ['request_token' => 't1', 'image_base64' => base64_encode($this->pngBytes()), 'prompt' => 'test'], ['X-Callback-Secret' => 'xxx'])
            ->assertStatus(404)->assertJson(['error' => 'Book not found.']);
    }

    public function test_callback_rejects_invalid_position(): void
    {
        $b64 = base64_encode($this->pngBytes());

        $this->postCallback(99, ['image_base64' => $b64], 'x')->assertStatus(422);
        $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas", ['position' => 0, 'image_base64' => $b64], ['X-Callback-Secret' => 'x'])->assertStatus(422);
        $this->postJson("/api/activepieces/books/{$this->book->uuid}/mandalas", ['image_base64' => $b64], ['X-Callback-Secret' => 'x'])->assertStatus(422);
    }

    public function test_callback_rejects_invalid_base64_and_non_png_as_422_and_fails_the_slot(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();

        $this->generate(1, $user);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => '!!! not base64 !!!']))->assertStatus(422);
        $this->assertSame('failed', $this->slot(1)->generation_status->value);
        $this->assertNull($this->slot(1)->image_path);

        $this->generate(1, $user);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode('<?php echo 1;')]))->assertStatus(422);
        $this->assertNull($this->slot(1)->image_path);

        // A JPEG is a valid image but not what the flow promises.
        $img = imagecreatetruecolor(50, 50);
        ob_start();
        imagejpeg($img);
        $jpeg = ob_get_clean();
        $this->generate(1, $user);
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($jpeg)]))->assertStatus(422);
        $this->assertNull($this->slot(1)->image_path);

        $this->generate(2, $user);
        $this->postCallback(2, $this->withIssuedToken(2, ['image_url' => 'ftp://evil/x.png']))->assertStatus(422);
    }

    public function test_callback_accepts_a_data_uri_prefix(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => 'data:image/png;base64,'.base64_encode($this->pngBytes())]))->assertOk();
    }

    public function test_callback_with_valid_image_url_saves_into_the_right_slot(): void
    {
        Http::fake([
            'hooks.test/*' => Http::response(['ok' => true]),
            'https://cdn.test/*' => Http::response($this->pngBytes(320), 200, ['Content-Type' => 'image/png']),
        ]);
        $this->generate(2);

        $this->postCallback(2, $this->withIssuedToken(2, [
            'image_url' => 'https://cdn.test/mandala-02.png',
            'prompt' => 'a cute capybara mandala',
        ]))->assertOk()->assertJson(['ok' => true, 'position' => 2]);

        $m2 = $this->slot(2);
        $this->assertSame("books/{$this->book->uuid}/mandalas/002.png", $m2->image_path);
        $this->assertSame('activepieces', $m2->generation_source);
        $this->assertSame('done', $m2->generation_status->value);
        $this->assertSame('a cute capybara mandala', $m2->final_prompt);
        $this->assertNull($m2->prompt);
        $this->assertNull($m2->request_token);
        $this->assertSame([2550, 2550], [$m2->width_px, $m2->height_px], 'AI images are upscaled to the print size');
        $this->assertNull($this->slot(1)->image_path);
        Storage::disk('local')->assertExists($m2->image_path);
    }

    public function test_callback_with_wrong_or_missing_token_is_409(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $b64 = base64_encode($this->pngBytes());

        $this->postCallback(1, ['image_base64' => $b64, 'request_token' => 'wrong'])->assertStatus(409);
        $this->postCallback(1, ['image_base64' => $b64])->assertStatus(409);
        $this->assertNull($this->slot(1)->image_path);
        $this->assertSame('requested', $this->slot(1)->generation_status->value);
    }

    public function test_a_repeated_callback_is_acknowledged_without_reprocessing(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $body = $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes(1024)), 'prompt' => 'first']);

        $this->postCallback(1, $body)->assertOk()->assertJsonMissing(['duplicate' => true]);
        $stored = Storage::disk('local')->get($this->slot(1)->image_path);

        // Same token again, even with a different image: nothing changes.
        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes(600)), 'prompt' => 'second']))
            ->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);

        $this->assertSame($stored, Storage::disk('local')->get($this->slot(1)->image_path));
        $this->assertSame('first', $this->slot(1)->final_prompt);
    }

    public function test_a_repeated_error_callback_is_acknowledged(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $body = $this->withIssuedToken(1, ['error' => 'boom', 'error_code' => 'openai_error']);

        $this->postCallback(1, $body)->assertOk()->assertJson(['ok' => false]);
        $this->postCallback(1, $body)->assertOk()->assertJson(['ok' => true, 'duplicate' => true]);
    }

    public function test_the_token_of_a_replaced_request_is_rejected(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->generate(1, $user);
        $old = $this->issued(1);
        $this->postCallback(1, ['request_token' => $old['token'], 'error' => 'x', 'error_code' => 'openai_error'])->assertOk();

        $this->generate(1, $user); // retry: new token and secret

        $this->postCallback(1, ['request_token' => $old['token'], 'image_base64' => base64_encode($this->pngBytes())], $old['secret'])->assertStatus(401);
        $this->postCallback(1, ['request_token' => $old['token'], 'image_base64' => base64_encode($this->pngBytes())])->assertStatus(409);
    }

    public function test_error_callback_marks_slot_failed_with_its_code_and_stops_queue(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->actingAs($this->user())->post("/books/{$this->book->uuid}/ai/queue");
        $this->assertTrue($this->book->fresh()->ai_queue_active);

        $this->postCallback(1, $this->withIssuedToken(1, ['error' => 'model refused', 'error_code' => 'content_policy']))->assertOk()->assertJson(['ok' => false]);

        $m1 = $this->slot(1);
        $this->assertSame('failed', $m1->generation_status->value);
        $this->assertSame('[content_policy] model refused', $m1->generation_error);
        $this->assertSame('content_policy', $m1->error_code);
        $this->assertNotNull($m1->responded_at);
        $this->assertFalse($this->book->fresh()->ai_queue_active);
    }

    public function test_a_callback_of_about_2_mb_is_accepted(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);

        $img = imagecreatetruecolor(850, 850);
        for ($x = 0; $x < 850; $x++) {
            for ($y = 0; $y < 850; $y++) {
                imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($img, null, 6);
        $b64 = base64_encode(ob_get_clean());
        $this->assertGreaterThan(2_000_000, strlen($b64));

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => $b64, 'prompt' => 'noise']))->assertOk();

        $this->assertSame([2550, 2550], [$this->slot(1)->width_px, $this->slot(1)->height_px]);
    }

    public function test_late_callback_of_a_timed_out_request_is_still_accepted(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $this->generate(1);
        $this->slot(1)->forceFill(['requested_at' => now()->subMinutes(11)])->save();
        $this->artisan('activepieces:expire-stale')->assertSuccessful();
        $this->assertSame('timeout', $this->slot(1)->generation_status->value);

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes())]))->assertOk();

        $this->assertSame('done', $this->slot(1)->generation_status->value);
        $this->assertNull($this->slot(1)->error_code);
    }

    // ----------------------------------------------------------- the queue

    public function test_queue_requests_one_mandala_at_a_time_and_advances_on_each_callback(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue")->assertSessionHas('status');
        Http::assertSentCount(1);
        $this->assertSame('requested', $this->slot(1)->generation_status->value);
        $this->assertSame('pending', $this->slot(2)->generation_status->value);

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes())]))
            ->assertOk()->assertJson(['next_requested' => 2]);
        Http::assertSentCount(2);

        $this->postCallback(2, $this->withIssuedToken(2, ['image_base64' => base64_encode($this->pngBytes())]))
            ->assertOk()->assertJson(['next_requested' => 3]);

        $this->assertSame('waiting_mandalas', $this->book->fresh()->status->value);

        $this->postCallback(3, $this->withIssuedToken(3, ['image_base64' => base64_encode($this->pngBytes())]))
            ->assertOk()->assertJson(['next_requested' => null, 'book_status' => 'ready']);

        $book = $this->book->fresh();
        $this->assertSame('ready', $book->status->value);
        $this->assertFalse($book->ai_queue_active);
        $this->assertSame(3, $book->completedCount());
        Http::assertSentCount(3);
    }

    public function test_queue_keeps_up_to_max_concurrent_pages_in_flight(): void
    {
        config(['kawaii.activepieces.max_concurrent' => 2]);
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);

        $this->actingAs($this->user())->post("/books/{$this->book->uuid}/ai/queue")->assertSessionHas('status');

        Http::assertSentCount(2);
        $this->assertSame('requested', $this->slot(1)->generation_status->value);
        $this->assertSame('requested', $this->slot(2)->generation_status->value);
        $this->assertSame('pending', $this->slot(3)->generation_status->value);

        // One finishes → exactly one replacement is requested.
        $this->postCallback(2, $this->withIssuedToken(2, ['image_base64' => base64_encode($this->pngBytes())]))
            ->assertOk()->assertJson(['next_requested' => 3]);
        Http::assertSentCount(3);

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes())]))
            ->assertOk()->assertJson(['next_requested' => null]);
        $this->assertTrue($this->book->fresh()->ai_queue_active, 'page 3 is still in flight');

        $this->postCallback(3, $this->withIssuedToken(3, ['image_base64' => base64_encode($this->pngBytes())]))->assertOk();
        $this->assertFalse($this->book->fresh()->ai_queue_active);
    }

    public function test_queue_skips_slots_that_already_have_an_image(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1", ['image' => MandalaUploadTest::png('1.png', 300, 300)]);

        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue");

        Http::assertSent(fn (HttpRequest $r) => $r->data()['position'] === 2);
    }

    public function test_stopping_the_queue_prevents_further_requests(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue");
        $this->actingAs($user)->post("/books/{$this->book->uuid}/ai/queue/stop");

        $this->postCallback(1, $this->withIssuedToken(1, ['image_base64' => base64_encode($this->pngBytes())]))
            ->assertOk()->assertJson(['next_requested' => null]);

        Http::assertSentCount(1);
    }

    public function test_status_endpoint_reports_in_flight_slots(): void
    {
        Http::fake(['hooks.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();
        $this->generate(3, $user);

        $this->actingAs($user)->getJson("/books/{$this->book->uuid}/status")
            ->assertOk()
            ->assertJsonPath('in_flight', [3])
            ->assertJsonPath('book_status', 'waiting_mandalas')
            ->assertJsonPath('mandalas.2.status', 'requested');
    }

    public function test_manual_upload_and_pdf_flow_do_not_depend_on_activepieces(): void
    {
        config(['kawaii.activepieces.enabled' => false]);
        Http::fake(fn () => throw new ConnectionException('down'));
        $user = $this->user();

        $this->actingAs($user)->post("/books/{$this->book->uuid}/mandalas/1", ['image' => MandalaUploadTest::png('1.png', 300, 300)])
            ->assertSessionMissing('error');

        $this->assertSame(1, $this->book->completedCount());
    }
}
