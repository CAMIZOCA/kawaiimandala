<?php

namespace App\Console\Commands;

use App\Services\ActivepiecesClient;
use Illuminate\Console\Command;

class ExpireStaleMandalas extends Command
{
    protected $signature = 'activepieces:expire-stale';

    protected $description = 'Mark Activepieces requests without a callback after the timeout as "timeout"';

    public function handle(ActivepiecesClient $client): int
    {
        $count = $client->expireStaleAll();

        $this->info("{$count} solicitud(es) marcada(s) como timeout.");

        return self::SUCCESS;
    }
}
