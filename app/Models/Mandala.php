<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mandala extends Model
{
    protected $fillable = ['book_id', 'position'];

    protected function casts(): array
    {
        return [
            'generation_status' => GenerationStatus::class,
            'requested_at' => 'datetime',
            'position' => 'integer',
            'width_px' => 'integer',
            'height_px' => 'integer',
            'generation_attempts' => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null;
    }

    public function isLowRes(): bool
    {
        if (! $this->hasImage()) {
            return false;
        }

        return min($this->width_px, $this->height_px) < config('kawaii.min_image_px');
    }

    public function isInFlight(): bool
    {
        if ($this->generation_status !== GenerationStatus::Requested) {
            return false;
        }

        $stale = now()->subMinutes(config('kawaii.activepieces.stale_after_minutes'));

        return $this->requested_at !== null && $this->requested_at->greaterThan($stale);
    }

    /** Requested long ago without any callback (the slot is normally expired first). */
    public function isStale(): bool
    {
        return $this->generation_status === GenerationStatus::Requested && ! $this->isInFlight();
    }

    public function label(): string
    {
        return str_pad((string) $this->position, 2, '0', STR_PAD_LEFT);
    }
}
