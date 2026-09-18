<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidMandalaImageException;
use App\Models\Book;
use App\Models\Mandala;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Validates and stores mandala images under
 * storage/app/private/books/{uuid}/mandalas/001.png (internal safe names only).
 */
class MandalaStorageService
{
    private const TYPES = [
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
    ];

    private const MAX_PIXELS = 100_000_000;

    public function __construct(private readonly MandalaImageProcessor $processor) {}

    public function storeUploaded(Mandala $mandala, UploadedFile $file): Mandala
    {
        if (! $file->isValid()) {
            throw new InvalidMandalaImageException('La subida del archivo falló.');
        }

        $bytes = @file_get_contents($file->getRealPath());

        if ($bytes === false) {
            throw new InvalidMandalaImageException('No se pudo leer el archivo subido.');
        }

        return $this->storeBinary($mandala, $bytes, $file->getClientOriginalName());
    }

    /**
     * @throws InvalidMandalaImageException
     */
    public function storeBinary(
        Mandala $mandala,
        string $bytes,
        ?string $originalName = null,
        string $source = 'manual',
        ?string $finalPrompt = null,
        ?string $completedToken = null,
    ): Mandala {
        [$width, $height, $extension] = $this->inspect($bytes);

        $fromFlow = $source === 'activepieces';

        if ($fromFlow && $extension !== 'png') {
            throw new InvalidMandalaImageException('La imagen de Activepieces debe ser un PNG.');
        }

        $original = null;

        if ($fromFlow && $this->needsUpscale($width, $height)) {
            $original = $bytes;
            $target = (int) config('kawaii.target_image_px');
            $bytes = $this->processor->normalize($bytes, $target);
            [$width, $height, $extension] = [$target, $target, 'png'];
        }

        $disk = Storage::disk('local');
        $this->deleteImage($mandala);

        $name = str_pad((string) $mandala->position, 3, '0', STR_PAD_LEFT);
        $path = $mandala->book->storageDir('mandalas').'/'.$name.'.'.$extension;
        $disk->put($path, $bytes);

        // Keep the image as the flow delivered it (~1024 px) next to the upscaled copy.
        $originalPath = null;
        if ($original !== null) {
            $originalPath = $mandala->book->storageDir('mandalas/originals').'/'.$name.'.png';
            $disk->put($originalPath, $original);
        }

        $mandala->forceFill([
            'image_path' => $path,
            'original_image_path' => $originalPath,
            'original_filename' => $originalName !== null ? mb_substr(basename($originalName), 0, 255) : null,
            'width_px' => $width,
            'height_px' => $height,
            'generation_source' => $source,
            'generation_status' => GenerationStatus::Done,
            'request_token' => null,
            // The token/secret of the answered request stay so a repeated callback is a no-op.
            'completed_token' => $completedToken,
            'callback_secret_hash' => $completedToken !== null ? $mandala->callback_secret_hash : null,
            'generation_error' => null,
            'error_code' => null,
            'responded_at' => $completedToken !== null ? now() : null,
        ]);

        if ($finalPrompt !== null) {
            $mandala->final_prompt = $finalPrompt;
        }

        $mandala->save();
        $mandala->book->refreshStatus();

        return $mandala;
    }

    public function deleteImage(Mandala $mandala): void
    {
        $paths = array_filter([$mandala->image_path, $mandala->original_image_path]);

        if ($paths !== []) {
            Storage::disk('local')->delete($paths);
        }
    }

    public function deleteBookFiles(Book $book): void
    {
        Storage::disk('local')->deleteDirectory($book->storageDir());
    }

    public function absolutePath(Mandala $mandala): ?string
    {
        if (! $mandala->hasImage() || ! Storage::disk('local')->exists($mandala->image_path)) {
            return null;
        }

        return Storage::disk('local')->path($mandala->image_path);
    }

    private function needsUpscale(int $width, int $height): bool
    {
        return config('kawaii.auto_upscale')
            && min($width, $height) < (int) config('kawaii.target_image_px');
    }

    /**
     * @return array{0:int,1:int,2:string} width, height, extension
     */
    private function inspect(string $bytes): array
    {
        $maxBytes = config('kawaii.max_upload_kb') * 1024;

        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new InvalidMandalaImageException('El archivo está vacío o supera el tamaño máximo de '.config('kawaii.max_upload_kb').' KB.');
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::TYPES[$info[2]])) {
            throw new InvalidMandalaImageException('El archivo no es una imagen PNG o JPG válida.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($mime !== self::TYPES[$info[2]][1]) {
            throw new InvalidMandalaImageException('El tipo real del archivo no coincide con una imagen PNG/JPG.');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidMandalaImageException('Las dimensiones de la imagen no son válidas.');
        }

        return [$width, $height, self::TYPES[$info[2]][0]];
    }
}
