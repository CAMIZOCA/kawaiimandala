<?php

namespace App\Services;

/**
 * Single source of truth for the interior page structure.
 *
 *   page 1                 = title (right)
 *   page 2                 = introduction (left)
 *   mandala i              = 3 + 2(i-1)   (always odd → right/recto)
 *   blank after mandala i  = mandala(i) + 1 (always even → left/verso)
 *   creator page           = 2N + 3
 *   technical blank page   = 2N + 4 = total pages
 */
class BookPaginationService
{
    public const TYPE_TITLE = 'title';

    public const TYPE_INTRODUCTION = 'introduction';

    public const TYPE_MANDALA = 'mandala';

    public const TYPE_BLANK = 'blank';

    public const TYPE_CREATOR = 'creator';

    /**
     * @return list<array{page:int,side:string,type:string,position?:int}>
     */
    public function buildPlan(int $mandalaCount): array
    {
        if ($mandalaCount < 1) {
            throw new \InvalidArgumentException('mandalaCount must be at least 1.');
        }

        $plan = [
            $this->entry(1, self::TYPE_TITLE),
            $this->entry(2, self::TYPE_INTRODUCTION),
        ];

        for ($i = 1; $i <= $mandalaCount; $i++) {
            $page = $this->mandalaPage($i);
            $plan[] = $this->entry($page, self::TYPE_MANDALA, ['position' => $i]);
            $plan[] = $this->entry($page + 1, self::TYPE_BLANK);
        }

        $plan[] = $this->entry($this->creatorPage($mandalaCount), self::TYPE_CREATOR);
        $plan[] = $this->entry($this->totalPages($mandalaCount), self::TYPE_BLANK);

        return $plan;
    }

    public function mandalaPage(int $position): int
    {
        return 3 + (($position - 1) * 2);
    }

    public function creatorPage(int $mandalaCount): int
    {
        return (2 * $mandalaCount) + 3;
    }

    public function totalPages(int $mandalaCount): int
    {
        return (2 * $mandalaCount) + 4;
    }

    /** odd = right / recto, even = left / verso. */
    public function sideFor(int $page): string
    {
        return $page % 2 === 1 ? 'right' : 'left';
    }

    private function entry(int $page, string $type, array $extra = []): array
    {
        return ['page' => $page, 'side' => $this->sideFor($page), 'type' => $type] + $extra;
    }
}
