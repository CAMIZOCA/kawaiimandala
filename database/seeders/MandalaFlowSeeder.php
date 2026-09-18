<?php

namespace Database\Seeders;

use App\Models\MandalaFlow;
use Illuminate\Database\Seeder;

/**
 * The 22 published Activepieces flows of the "Kawaii Mandala" folder, one per page position.
 * Idempotent (updateOrCreate by position). `enabled` is left as is so a manually
 * disabled flow stays disabled; name, flow_id and webhook URL are reset to these values.
 */
class MandalaFlowSeeder extends Seeder
{
    /** @var array<int, string> position => flow id */
    public const FLOWS = [
        1 => 'sjB7fKgho9fyuI32qvWVZ',
        2 => 'Esbk1dsUsQdQVqV9hDl5d',
        3 => 'tzIq1GCtuptnmeNRSOTja',
        4 => 'U6gz9WaARjPKKtjLZ6vs3',
        5 => 'kSYvduOfBt6D1IDAQSng7',
        6 => 'wCHrwv9kmzAAhyhmzIsnv',
        7 => 'ox6XBj1KwI8ifjc14l83T',
        8 => 'FLo87C6EKuOEqJXxk7T5A',
        9 => 'wgSgg50dkbBXuMI2CCmSn',
        10 => '8Q7WgO4dk4wFGVzEmOt9m',
        11 => 'vWSaPLtpljCWguVUF0qov',
        12 => 'zL4gsR7oSC53QHHYbdih9',
        13 => 'NqDorgPrsdDeqQnKKNytB',
        14 => 'QI803FArgvukGdf7uf1We',
        15 => '7lVRnyWAIzCaPYyviuV7s',
        16 => 'gFUyaOyj4TGDMSc0xoA1C',
        17 => 'QMCBgVerYTqXcY1f8HTg1',
        18 => 's4otUbN3WlsyUHYI1jXzR',
        19 => 'gfVlNvUISowoCV3ofq4W7',
        20 => '25c4icpkLbxrUVZFr8csm',
        21 => '2yjvnjPGcDr0wCkdruBqm',
        22 => 'gzZaxBnVMHFHX4HMuqLTU',
    ];

    public function run(): void
    {
        $base = rtrim((string) config('kawaii.activepieces.base_url'), '/');

        foreach (self::FLOWS as $position => $flowId) {
            MandalaFlow::updateOrCreate(
                ['position' => $position],
                [
                    'name' => 'Mandala '.str_pad((string) $position, 2, '0', STR_PAD_LEFT),
                    'flow_id' => $flowId,
                    'flow_url' => "{$base}/api/v1/webhooks/{$flowId}",
                ],
            );
        }
    }
}
