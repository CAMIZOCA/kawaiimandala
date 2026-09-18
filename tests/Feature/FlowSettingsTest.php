<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\MandalaFlow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function url(int $position): string
    {
        return "https://activepieces.example/api/v1/webhooks/flow{$position}";
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/flows')->assertRedirect('/login');
        $this->put('/settings/flows', ['flows' => []])->assertRedirect('/login');
    }

    public function test_page_lists_22_positions_by_default_with_status_summary(): void
    {
        MandalaFlow::create(['position' => 1, 'flow_url' => $this->url(1)]);

        $response = $this->actingAs(User::factory()->create())->get('/settings/flows')->assertOk();

        $response->assertSee('1 de 22');
        $response->assertSee('name="flows[22][flow_url]"', false);
        $response->assertDontSee('name="flows[23][flow_url]"', false);
        $response->assertSee('configurado');
        $response->assertSee('sin enlace');
    }

    public function test_page_extends_to_the_largest_book(): void
    {
        Book::create(['title' => 'T', 'animal_theme' => 'Cat', 'mandala_count' => 30]);

        $this->actingAs(User::factory()->create())->get('/settings/flows')
            ->assertSee('name="flows[30][flow_url]"', false)
            ->assertDontSee('name="flows[31][flow_url]"', false);
    }

    public function test_urls_are_saved_updated_and_cleared(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/flows', ['flows' => [
            1 => ['flow_url' => $this->url(1), 'enabled' => '1'],
            2 => ['flow_url' => $this->url(2), 'enabled' => '0'],
            3 => ['flow_url' => '', 'enabled' => '1'],
        ]])->assertRedirect(route('settings.flows.edit'))->assertSessionHas('status');

        $this->assertSame($this->url(1), MandalaFlow::urlFor(1));
        $this->assertNull(MandalaFlow::urlFor(2), 'disabled flows are not usable');
        $this->assertSame(0, MandalaFlow::where('position', 3)->count(), 'blank rows without a stored link are not created');

        $this->actingAs($user)->put('/settings/flows', ['flows' => [
            1 => ['flow_url' => '', 'enabled' => '1'],
            2 => ['flow_url' => $this->url(2), 'enabled' => '1'],
        ]]);

        $this->assertNull(MandalaFlow::urlFor(1));
        $this->assertSame($this->url(2), MandalaFlow::urlFor(2));
    }

    public function test_invalid_urls_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/flows', ['flows' => [1 => ['flow_url' => 'not a url']]])
            ->assertSessionHasErrors('flows.1.flow_url');
        $this->actingAs($user)->put('/settings/flows', ['flows' => [1 => ['flow_url' => 'javascript:alert(1)']]])
            ->assertSessionHasErrors('flows.1.flow_url');
        $this->actingAs($user)->put('/settings/flows', ['flows' => [99 => ['flow_url' => $this->url(99)]]])
            ->assertSessionHasErrors('flows');

        $this->assertSame(0, MandalaFlow::count());
    }

    public function test_missing_for_reports_positions_without_usable_link(): void
    {
        MandalaFlow::create(['position' => 1, 'flow_url' => $this->url(1)]);
        MandalaFlow::create(['position' => 2, 'flow_url' => $this->url(2), 'enabled' => false]);
        MandalaFlow::create(['position' => 3, 'flow_url' => null]);

        $this->assertSame([2, 3, 4], MandalaFlow::missingFor([4, 3, 2, 1]));
    }

    public function test_page_warns_when_public_url_is_local(): void
    {
        config(['kawaii.activepieces.public_url' => 'http://localhost:8000']);

        $this->actingAs(User::factory()->create())->get('/settings/flows')->assertSee('dirección local');

        config(['kawaii.activepieces.public_url' => 'https://app.example.com']);
        $this->actingAs(User::factory()->create())->get('/settings/flows')->assertDontSee('dirección local');
    }
}
