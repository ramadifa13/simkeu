<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageStorage
{
    public function store(UploadedFile $file, string $directory): string
    {
        $source = @imagecreatefromstring(file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 2200 / max($width, $height));

        while ($scale >= 0.08) {
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            foreach ([86, 74, 62, 50, 38] as $quality) {
                ob_start();
                imagewebp($target, null, $quality);
                $contents = ob_get_clean();

                if ($contents !== false && strlen($contents) <= 1024 * 1024) {
                    imagedestroy($target);
                    imagedestroy($source);
                    $path = $directory.'/'.bin2hex(random_bytes(20)).'.webp';
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
}
