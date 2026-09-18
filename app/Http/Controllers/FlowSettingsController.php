<?php

namespace App\Http\Controllers;

use App\Http\Requests\FlowSettingsRequest;
use App\Models\Book;
use App\Models\MandalaFlow;
use App\Services\ActivepiecesClient;
use Illuminate\Http\RedirectResponse;

/** Configuración → Flujos: the Activepieces flow link of every page position. */
class FlowSettingsController extends Controller
{
    public function edit(ActivepiecesClient $activepieces)
    {
        $rows = max((int) config('kawaii.default_mandala_count'), (int) Book::max('mandala_count'));
        $flows = MandalaFlow::query()->get()->keyBy('position');
        $configured = collect(range(1, $rows))->filter(fn ($p) => $flows->get($p)?->isUsable())->count();

        return view('settings.flows', [
            'rows' => $rows,
            'flows' => $flows,
            'configured' => $configured,
            'client' => $activepieces,
        ]);
    }

    public function update(FlowSettingsRequest $request): RedirectResponse
    {
        foreach ($request->validated('flows') as $position => $data) {
            $url = trim((string) ($data['flow_url'] ?? ''));
            $flow = MandalaFlow::where('position', (int) $position)->first();

            if ($flow === null && $url === '') {
                continue;
            }

            $attributes = ['flow_url' => $url !== '' ? $url : null, 'enabled' => (bool) ($data['enabled'] ?? true)];

            // Keep the flow id in step with the pasted webhook URL; name new rows.
            if ($url !== '') {
                $attributes['flow_id'] = MandalaFlow::flowIdFromUrl($url);
            }
            if ($flow?->name === null) {
                $attributes['name'] = 'Mandala '.str_pad((string) $position, 2, '0', STR_PAD_LEFT);
            }

            MandalaFlow::updateOrCreate(['position' => (int) $position], $attributes);
        }

        return redirect()->route('settings.flows.edit')->with('status', 'Enlaces de flujos guardados.');
    }
}
