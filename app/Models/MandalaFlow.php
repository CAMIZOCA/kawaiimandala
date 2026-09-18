<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Link to the Activepieces flow that generates the mandala of one page position. */
class MandalaFlow extends Model
{
    protected $fillable = ['position', 'name', 'flow_id', 'flow_url', 'enabled'];

    /** Flow id embedded in an Activepieces webhook URL (…/webhooks/{flow_id}), if any. */
    public static function flowIdFromUrl(?string $url): ?string
    {
        return $url !== null && preg_match('#/webhooks/([A-Za-z0-9_-]+)#', $url, $m) ? $m[1] : null;
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function isUsable(): bool
    {
        return $this->enabled && filled($this->flow_url);
    }

    /** Webhook URL for the position, or null when missing/disabled. */
    public static function urlFor(int $position): ?string
    {
        $flow = static::where('position', $position)->first();

        return $flow?->isUsable() ? $flow->flow_url : null;
    }

    /**
     * @param  iterable<int>  $positions
     * @return list<int> positions without a usable link, ascending
     */
    public static function missingFor(iterable $positions): array
    {
        $usable = static::query()
            ->where('enabled', true)
            ->whereNotNull('flow_url')
            ->where('flow_url', '!=', '')
            ->pluck('position')
            ->all();

        $missing = [];
        foreach ($positions as $position) {
            if (! in_array((int) $position, $usable, true)) {
                $missing[] = (int) $position;
            }
        }
        sort($missing);

        return $missing;
    }
}
