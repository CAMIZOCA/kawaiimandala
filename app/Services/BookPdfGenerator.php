<?php

namespace App\Services;

use App\Exceptions\PdfExportException;
use App\Models\Book;
use App\Models\Mandala;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * Composes the KDP interior PDF (8.5 x 8.5 in, no bleed) following the plan
 * produced by BookPaginationService. Mandala pages hold only the centred image;
 * their reverse (and the technical last page) are truly empty pages.
 */
class BookPdfGenerator
{
    private const PAGE_MM = 215.9;

    private const MARGIN_MM = 12.7;

    private const USABLE_MM = 190.5;

    public function __construct(
        private readonly BookPaginationService $pagination,
        private readonly MandalaStorageService $storage,
    ) {}

    /**
     * @return string binary PDF
     */
    public function render(Book $book): string
    {
        $this->prepareRuntime();

        $book->loadMissing('mandalas');
        $mandalas = $book->mandalas->keyBy('position');
        $plan = $this->pagination->buildPlan($book->mandala_count);

        $pdf = $this->newMpdf();
        $pdf->SetTitle($book->title);
        $pdf->SetAuthor((string) $book->author_name);
        $pdf->SetCreator(config('app.name'));

        $tempFiles = [];

        try {
            foreach ($plan as $index => $entry) {
                $pdf->AddPage();

                switch ($entry['type']) {
                    case BookPaginationService::TYPE_TITLE:
                        $pdf->WriteHTML($this->fit($this->titleCandidates($book), 'la página de título'));
                        break;
                    case BookPaginationService::TYPE_INTRODUCTION:
                        $pdf->WriteHTML($this->fit($this->introductionCandidates($book), 'la introducción'));
                        break;
                    case BookPaginationService::TYPE_MANDALA:
                        $this->placeMandala($pdf, $mandalas[$entry['position']] ?? null, $tempFiles);
                        break;
                    case BookPaginationService::TYPE_CREATOR:
                        $pdf->WriteHTML($this->fit($this->creatorCandidates($book), 'la página del creador'));
                        break;
                    default:
                        // Blank page: intentionally nothing is written.
                        break;
                }
            }

            $binary = $pdf->OutputBinaryData();
        } finally {
            foreach ($tempFiles as $file) {
                @unlink($file);
            }
        }

        return $binary;
    }

    /**
     * Returns human-readable problems when any text page cannot fit on one page.
     *
     * @return list<string>
     */
    public function textFitErrors(Book $book): array
    {
        $this->prepareRuntime();

        $errors = [];
        $checks = [
            'la página de título' => $this->titleCandidates($book),
            'la introducción' => $this->introductionCandidates($book),
            'la página del creador' => $this->creatorCandidates($book),
        ];

        foreach ($checks as $label => $candidates) {
            try {
                $this->fit($candidates, $label);
            } catch (PdfExportException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    // ---------------------------------------------------------------- pages

    /** @param  list<string>  $tempFiles */
    private function placeMandala(Mpdf $pdf, ?Mandala $mandala, array &$tempFiles): void
    {
        $path = $mandala ? $this->storage->absolutePath($mandala) : null;

        if ($mandala === null || $path === null) {
            throw new PdfExportException('Falta la imagen del mandala '.($mandala?->label() ?? '?').'.');
        }

        $info = @getimagesize($path);

        if ($info === false) {
            throw new PdfExportException("La imagen del mandala {$mandala->label()} no se puede leer.");
        }

        [$width, $height] = $info;
        $file = $this->flattenIfNeeded($path, $info, $tempFiles);

        // Preserve aspect ratio inside the 190.5 x 190.5 mm usable box, centred.
        $scale = min(self::USABLE_MM / $width, self::USABLE_MM / $height);
        $w = $width * $scale;
        $h = $height * $scale;
        $x = (self::PAGE_MM - $w) / 2;
        $y = (self::PAGE_MM - $h) / 2;

        $pdf->Image(str_replace('\\', '/', $file), $x, $y, $w, $h, '', '', true, false);
    }

    /**
     * Removes any transparency (PNG alpha / palette transparency) over white so
     * the final PDF contains only opaque images.
     *
     * @param  list<string>  $tempFiles
     */
    private function flattenIfNeeded(string $path, array $info, array &$tempFiles): string
    {
        if ($info[2] !== IMAGETYPE_PNG) {
            return $path;
        }

        $header = file_get_contents($path, false, null, 0, 30);
        $colorType = ord($header[25] ?? "\0");
        $hasTransparencyChunk = $colorType === 3 && str_contains(file_get_contents($path), 'tRNS');

        if (! in_array($colorType, [4, 6], true) && ! $hasTransparencyChunk) {
            return $path;
        }

        $src = imagecreatefrompng($path);
        $canvas = imagecreatetruecolor($info[0], $info[1]);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $src, 0, 0, 0, 0, $info[0], $info[1]);

        $tmp = tempnam(sys_get_temp_dir(), 'mnd').'.png';
        imagepng($canvas, $tmp, 6);
        unset($src, $canvas);
        $tempFiles[] = $tmp;

        return $tmp;
    }

    // ----------------------------------------------------------- text pages

    /** @return list<string> */
    private function titleCandidates(Book $book): array
    {
        $out = [];

        foreach ([1.0, 0.85, 0.7, 0.55, 0.45] as $k) {
            $title = round(40 * $k, 1);
            $subtitle = round(18 * $k, 1);

            $html = '<div style="text-align:center;padding-top:48mm;font-family:roboto">'
                .'<div style="font-size:'.$title.'pt;font-weight:bold;line-height:1.15">'.$this->text($book->title).'</div>';

            if (filled($book->subtitle)) {
                $html .= '<div style="font-size:'.$subtitle.'pt;margin-top:8mm;line-height:1.3">'.$this->text($book->subtitle).'</div>';
            }

            if (filled($book->animal_theme)) {
                $html .= '<div style="font-size:12pt;margin-top:14mm;letter-spacing:2pt;text-transform:uppercase">'.$this->text($book->animal_theme).'</div>';
            }

            $html .= '</div>';

            if (filled($book->author_name)) {
                $html .= '<div style="position:absolute;left:'.self::MARGIN_MM.'mm;top:186mm;width:'.self::USABLE_MM.'mm;'
                    .'text-align:center;font-family:roboto;font-size:11pt">'.$this->text($book->author_name).'</div>';
            }

            $out[] = $html;
        }

        return $out;
    }

    /** @return list<string> */
    private function introductionCandidates(Book $book): array
    {
        $out = [];

        foreach ([12, 11.5, 11, 10.5, 10, 9.5, 9] as $size) {
            $out[] = '<div style="font-family:roboto">'
                .'<div style="font-size:18pt;font-weight:bold;margin-bottom:6mm">Introduction</div>'
                .'<div style="font-size:'.$size.'pt;line-height:1.55;text-align:left">'.$this->text($book->introduction, true).'</div>'
                .'</div>';
        }

        return $out;
    }

    /** @return list<string> */
    private function creatorCandidates(Book $book): array
    {
        $copyright = trim('© '.($book->copyright_year ?? '').' '.($book->author_name ?? ''));
        $out = [];

        foreach ([11, 10.5, 10, 9.5, 9] as $size) {
            $html = '<div style="font-family:roboto;font-size:'.$size.'pt;line-height:1.5">'
                .'<div style="font-size:18pt;font-weight:bold;margin-bottom:6mm">ABOUT THE CREATOR</div>'
                .'<div>'.$this->text($book->creator_description, true).'</div>'
                .'<div style="margin-top:12mm">'
                .'<div>'.$this->text($copyright).'</div>';

            if (filled($book->copyright_text)) {
                $html .= '<div>'.$this->text($book->copyright_text, true).'</div>';
            }

            if (filled($book->website_url)) {
                $html .= '<div>'.$this->text($book->website_url).'</div>';
            }

            $out[] = $html.'</div></div>';
        }

        return $out;
    }

    /**
     * Picks the first candidate HTML that fits on a single page. The order is
     * from preferred (largest) to smallest type size.
     *
     * @param  list<string>  $candidates
     */
    private function fit(array $candidates, string $label): string
    {
        foreach ($candidates as $html) {
            $probe = $this->newMpdf();
            $probe->AddPage();
            $probe->WriteHTML($html);

            if ($probe->page === 1) {
                return $html;
            }
        }

        throw new PdfExportException("El texto de {$label} es demasiado largo para caber en una página. Acórtalo.");
    }

    private function text(?string $value, bool $paragraphs = false): string
    {
        $escaped = e((string) $value);

        return $paragraphs ? nl2br($escaped, false) : $escaped;
    }

    // ------------------------------------------------------------- mPDF

    private function newMpdf(): Mpdf
    {
        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFonts = (new FontVariables)->getDefaults();

        $tempDir = storage_path('app/private/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => [self::PAGE_MM, self::PAGE_MM],
            'orientation' => 'P',
            'margin_left' => self::MARGIN_MM,
            'margin_right' => self::MARGIN_MM,
            'margin_top' => self::MARGIN_MM,
            'margin_bottom' => self::MARGIN_MM,
            'margin_header' => 0,
            'margin_footer' => 0,
            'autoPageBreak' => true,
            'tempDir' => $tempDir,
            'fontDir' => array_merge($defaultConfig['fontDir'], [resource_path('fonts')]),
            'fontdata' => $defaultFonts['fontdata'] + [
                'roboto' => ['R' => 'Roboto-Regular.ttf', 'B' => 'Roboto-Bold.ttf'],
            ],
            'default_font' => 'roboto',
            'default_font_size' => 11,
            'useSubstitutions' => false,
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
        ]);
    }

    private function prepareRuntime(): void
    {
        if ((int) ini_get('memory_limit') !== -1 && (int) ini_get('memory_limit') < 768) {
            @ini_set('memory_limit', '768M');
        }

        @set_time_limit(300);
    }
}
