<?php

namespace Tests\Feature;

use App\Models\MandalaFlow;
use Database\Seeders\MandalaFlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MandalaFlowSeederTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://activepieces.medio-digital.net/api/v1/webhooks/';

    public function test_it_creates_the_22_flows_with_their_webhook_urls(): void
    {
        $this->seed(MandalaFlowSeeder::class);

        $this->assertSame(22, MandalaFlow::count());
        $this->assertSame(range(1, 22), MandalaFlow::orderBy('position')->pluck('position')->all());

        $first = MandalaFlow::where('position', 1)->first();
        $this->assertSame('Mandala 01', $first->name);
        $this->assertSame('sjB7fKgho9fyuI32qvWVZ', $first->flow_id);
        $this->assertSame(self::BASE.'sjB7fKgho9fyuI32qvWVZ', $first->flow_url);
        $this->assertTrue($first->enabled);

        $last = MandalaFlow::where('position', 22)->first();
        $this->assertSame('Mandala 22', $last->name);
        $this->assertSame(self::BASE.'gzZaxBnVMHFHX4HMuqLTU', MandalaFlow::urlFor(22));

        foreach (MandalaFlow::all() as $flow) {
            $this->assertSame(self::BASE.$flow->flow_id, $flow->flow_url);
            $this->assertSame(MandalaFlowSeeder::FLOWS[$flow->position], $flow->flow_id);
        }
        $this->assertCount(22, array_unique(MandalaFlowSeeder::FLOWS), 'every position has its own flow');
    }

    public function test_running_it_twice_does_not_duplicate_and_keeps_disabled_flows_disabled(): void
    {
        $this->seed(MandalaFlowSeeder::class);
        MandalaFlow::where('position', 5)->update(['enabled' => false]);
        MandalaFlow::where('position', 6)->update(['flow_url' => 'https://elsewhere.test/hook']);

        $this->seed(MandalaFlowSeeder::class);

        $this->assertSame(22, MandalaFlow::count());
        $this->assertFalse(MandalaFlow::where('position', 5)->first()->enabled);
        $this->assertSame(self::BASE.'wCHrwv9kmzAAhyhmzIsnv', MandalaFlow::where('position', 6)->first()->flow_url);
    }

    public function test_the_base_url_comes_from_config(): void
    {
        config(['kawaii.activepieces.base_url' => 'https://ap.example.com/']);

        $this->seed(MandalaFlowSeeder::class);

        $this->assertSame('https://ap.example.com/api/v1/webhooks/sjB7fKgho9fyuI32qvWVZ', MandalaFlow::urlFor(1));
    }

    public function test_the_database_seeder_loads_the_flows(): void
    {
        $this->seed();

        $this->assertSame(22, MandalaFlow::count());
    }
}
