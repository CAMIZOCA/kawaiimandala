<?php

namespace App\Services;

use App\Exceptions\InvalidMandalaImageException;

/**
 * Turns an AI-generated image (typically ~1024 px) into a print-ready square:
 * padded to a square on white, scaled up to the target size and cleaned to
 * crisp dark lines on pure white. Only used for Activepieces images.
 */
class MandalaImageProcessor
{
    private const CONTRAST_PASSES = 2;

    private const CONTRAST_LEVEL = -45;

    /**
     * @return string PNG bytes, target x target px
     *
     * @throws InvalidMandalaImageException
     */
    public function normalize(string $bytes, int $target): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new InvalidMandalaImageException('No se pudo procesar la imagen recibida.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = max($width, $height);

        // Square canvas on opaque white (also flattens any transparency).
        $square = imagecreatetruecolor($side, $side);
        imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));
        imagealphablending($square, true);
        imagecopy($square, $source, intdiv($side - $width, 2), intdiv($side - $height, 2), 0, 0, $width, $height);
        unset($source);

        $scaled = imagescale($square, $target, $target, IMG_BICUBIC);
        unset($square);

        if ($scaled === false) {
            throw new InvalidMandalaImageException('No se pudo escalar la imagen recibida.');
        }

        imagefilter($scaled, IMG_FILTER_GRAYSCALE);
        for ($i = 0; $i < self::CONTRAST_PASSES; $i++) {
            imagefilter($scaled, IMG_FILTER_CONTRAST, self::CONTRAST_LEVEL);
        }

        imagetruecolortopalette($scaled, false, 256);

        ob_start();
        imagepng($scaled, null, 9);
        $png = ob_get_clean();
        unset($scaled);

        return $png;
    }
}
