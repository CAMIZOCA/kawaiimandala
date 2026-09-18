<?php

namespace Tests\Unit;

use App\Services\BookPaginationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookPaginationServiceTest extends TestCase
{
    private function byPage(array $plan): array
    {
        $indexed = [];
        foreach ($plan as $entry) {
            $indexed[$entry['page']] = $entry;
        }

        return $indexed;
    }

    public function test_default_22_mandalas_give_48_pages_with_expected_layout(): void
    {
        $plan = (new BookPaginationService)->buildPlan(22);
        $pages = $this->byPage($plan);

        $this->assertCount(48, $plan);
        $this->assertSame('title', $pages[1]['type']);
        $this->assertSame('introduction', $pages[2]['type']);
        $this->assertSame(['mandala', 1], [$pages[3]['type'], $pages[3]['position']]);
        $this->assertSame('blank', $pages[4]['type']);
        $this->assertSame(['mandala', 22], [$pages[45]['type'], $pages[45]['position']]);
        $this->assertSame('blank', $pages[46]['type']);
        $this->assertSame('creator', $pages[47]['type']);
        $this->assertSame('blank', $pages[48]['type']);
    }

    public function test_44_mandalas_give_92_pages_with_expected_layout(): void
    {
        $plan = (new BookPaginationService)->buildPlan(44);
        $pages = $this->byPage($plan);

        $this->assertCount(92, $plan);
        $this->assertSame(['mandala', 44], [$pages[89]['type'], $pages[89]['position']]);
        $this->assertSame('blank', $pages[90]['type']);
        $this->assertSame('creator', $pages[91]['type']);
        $this->assertSame('blank', $pages[92]['type']);
    }

    #[DataProvider('counts')]
    public function test_every_mandala_is_on_an_odd_page_and_followed_by_an_even_blank(int $n): void
    {
        $service = new BookPaginationService;
        $plan = $service->buildPlan($n);
        $pages = $this->byPage($plan);

        $this->assertCount(2 * $n + 4, $plan);
        $this->assertSame($service->totalPages($n), count($plan));
        $this->assertSame(range(1, 2 * $n + 4), array_keys($pages), 'pages are sequential with no gaps');

        $mandalas = array_filter($plan, fn ($e) => $e['type'] === 'mandala');
        $this->assertCount($n, $mandalas);

        foreach ($mandalas as $entry) {
            $this->assertSame(1, $entry['page'] % 2, "mandala {$entry['position']} must be on an odd page");
            $this->assertSame('right', $entry['side']);
            $this->assertSame(3 + ($entry['position'] - 1) * 2, $entry['page']);

            $after = $pages[$entry['page'] + 1];
            $this->assertSame('blank', $after['type']);
            $this->assertSame(0, $after['page'] % 2);
            $this->assertSame('left', $after['side']);
        }

        $this->assertSame('creator', $pages[2 * $n + 3]['type']);
        $this->assertSame('blank', $pages[2 * $n + 4]['type']);
        $this->assertSame(0, count($plan) % 2, 'total page count is even');
    }

    public static function counts(): array
    {
        return [[1], [2], [22], [44], [60]];
    }

    public function test_zero_mandalas_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BookPaginationService)->buildPlan(0);
    }
}
