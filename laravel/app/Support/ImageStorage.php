<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageStorage
{
    public function store(UploadedFile $file, string $directory): string
    {
        // Logo koperasi: jangan ubah PNG/WebP kecil agar alpha tetap utuh.
        if ($this->shouldKeepOriginal($file, $directory)) {
            $extension = $file->getMimeType() === 'image/png' ? 'png' : 'webp';
            $path = $directory.'/'.bin2hex(random_bytes(20)).'.'.$extension;
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()) ?: '');

            return $path;
        }

        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()) ?: '');

        if ($source === false) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }

        imagesavealpha($source, true);

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 2200 / max($width, $height));
        $asPng = str_starts_with($directory, 'koperasi');

        while ($scale >= 0.08) {
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = $this->canvasWithAlpha($targetWidth, $targetHeight);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagealphablending($target, false);
            imagesavealpha($target, true);

            foreach ($asPng ? [6, 5, 4, 3] : [86, 74, 62, 50, 38] as $quality) {
                ob_start();
                if ($asPng) {
                    imagepng($target, null, $quality);
                } else {
                    imagewebp($target, null, $quality);
                }
                $contents = ob_get_clean();

                if ($contents !== false && strlen($contents) <= 1024 * 1024) {
                    imagedestroy($target);
                    imagedestroy($source);
                    $path = $directory.'/'.bin2hex(random_bytes(20)).($asPng ? '.png' : '.webp');
                    Storage::disk('local')->put($path, $contents);

                    return $path;
                }
            }

            imagedestroy($target);
            $scale *= 0.75;
        }

        imagedestroy($source);

        throw new RuntimeException('Gambar tidak dapat dipadatkan hingga 1 MB.');
    }

    private function shouldKeepOriginal(UploadedFile $file, string $directory): bool
    {
        if (! str_starts_with($directory, 'koperasi')) {
            return false;
        }

        return in_array($file->getMimeType(), ['image/png', 'image/webp'], true)
            && (int) $file->getSize() > 0
            && (int) $file->getSize() <= 1024 * 1024;
    }

    /**
     * @return \GdImage
     */
    private function canvasWithAlpha(int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        imagealphablending($canvas, true);

        return $canvas;
    }
}
