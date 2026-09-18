<?php

namespace App\Models;

use App\Enums\BookStatus;
use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Book extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'animal_theme', 'introduction', 'author_name',
        'creator_description', 'copyright_text', 'copyright_year', 'website_url',
        'mandala_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookStatus::class,
            'ai_queue_active' => 'boolean',
            'mandala_count' => 'integer',
            'copyright_year' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Book $book) {
            $book->uuid ??= (string) Str::uuid();
            $book->status ??= BookStatus::Draft;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function mandalas(): HasMany
    {
        return $this->hasMany(Mandala::class)->orderBy('position');
    }

    public function completedCount(): int
    {
        return $this->mandalas()->whereNotNull('image_path')->count();
    }

    public function storageDir(string $sub = ''): string
    {
        return 'books/'.$this->uuid.($sub !== '' ? '/'.$sub : '');
    }

    /** Create missing slots 1..N and drop empty slots above N. */
    public function syncSlots(): void
    {
        $existing = $this->mandalas()->pluck('position')->all();

        for ($position = 1; $position <= $this->mandala_count; $position++) {
            if (! in_array($position, $existing, true)) {
                $this->mandalas()->create(['position' => $position]);
            }
        }

        $this->mandalas()->where('position', '>', $this->mandala_count)->whereNull('image_path')->delete();
    }

    /** Recompute the status from the slots (never touches an errored book). */
    public function refreshStatus(): void
    {
        if ($this->status === BookStatus::Error) {
            return;
        }

        $complete = $this->completedCount() === $this->mandala_count;

        if ($complete) {
            $status = $this->status === BookStatus::Exported ? BookStatus::Exported : BookStatus::Ready;
        } elseif ($this->mandalas()->where('generation_status', GenerationStatus::Requested->value)->exists()) {
            $status = BookStatus::WaitingMandalas;
        } else {
            $status = BookStatus::Draft;
        }

        if ($status !== $this->status) {
            $this->status = $status;
            $this->save();
        }
    }
}
