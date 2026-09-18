<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\MandalaStorageService;
use Illuminate\Console\Command;

class CreateDemoBook extends Command
{
    protected $signature = 'kawaii:demo-book {--count=22 : Number of mandalas} {--size=2550 : Placeholder size in px} {--empty : Create the book without placeholder images}';

    protected $description = 'DEVELOPMENT ONLY: create the "Cute Capybara Mandalas" fixture with placeholder images';

    public function handle(MandalaStorageService $storage): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando es solo para desarrollo.');

            return self::FAILURE;
        }

        $count = max(1, min((int) $this->option('count'), config('kawaii.max_mandalas')));
        $size = max(200, (int) $this->option('size'));

        $book = Book::create([
            'title' => 'Cute Capybara Mandalas',
            'subtitle' => "{$count} Relaxing Animal Coloring Designs",
            'animal_theme' => 'Capybara',
            'introduction' => "Welcome to this collection of capybara mandalas!\n\nEach design sits alone on its own page and the reverse side is left blank, so your markers will never bleed through onto the next drawing. Take your time, pick your favourite colours and enjoy.",
            'author_name' => config('kawaii.default_author'),
            'creator_description' => 'Marvin Baptista creates relaxing coloring books full of cute animals and detailed mandala patterns.',
            'copyright_text' => 'All rights reserved. No part of this book may be reproduced without written permission.',
            'copyright_year' => (int) date('Y'),
            'mandala_count' => $count,
        ]);
        $book->syncSlots();

        if (! $this->option('empty')) {
            foreach ($book->mandalas as $mandala) {
                $storage->storeBinary($mandala, $this->placeholder($mandala->position, $size), "placeholder-{$mandala->label()}.png");
            }
        }

        $this->info("Libro demo creado: {$book->uuid} ({$count} mandalas, ".($this->option('empty') ? 'sin imágenes' : 'con placeholders').').');
        $this->warn('Las imágenes placeholder NO son archivos de producción.');

        return self::SUCCESS;
    }

    /** Simple black & white mandala-like placeholder, marked as not for production. */
    private function placeholder(int $index, int $size): string
    {
        $img = imagecreate($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);
        imagesetthickness($img, max(2, (int) ($size / 400)));

        $c = $size / 2;
        $petals = 6 + ($index % 10);

        for ($ring = 1; $ring <= 5; $ring++) {
            $r = $size * 0.09 * $ring;
            imageellipse($img, (int) $c, (int) $c, (int) ($r * 2), (int) ($r * 2), $black);
        }

        for ($k = 0; $k < $petals; $k++) {
            $angle = 2 * M_PI * $k / $petals;
            $pts = [];
            for ($t = 0; $t < 60; $t++) {
                $u = 2 * M_PI * $t / 60;
                $px = $size * 0.16 * cos($u) + $size * 0.28;
                $py = $size * 0.06 * sin($u);
                $pts[] = (int) ($c + $px * cos($angle) - $py * sin($angle));
                $pts[] = (int) ($c + $px * sin($angle) + $py * cos($angle));
            }
            imagepolygon($img, $pts, $black);
        }

        $font = resource_path('fonts/Roboto-Bold.ttf');
        $label = "PLACEHOLDER {$index} - NOT FOR PRODUCTION";
        imagettftext($img, max(10, (int) ($size / 60)), 0, (int) ($size * 0.05), (int) ($size * 0.96), $black, $font, $label);

        ob_start();
        imagepng($img, null, 9);

        return ob_get_clean();
    }
}
