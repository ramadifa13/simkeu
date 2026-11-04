<?php

namespace Tests\Unit;

use App\Support\ImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageStorageTest extends TestCase
{
    public function test_stored_image_is_webp_and_no_larger_than_one_megabyte(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(1800, 1400);
        imagefill($image, 0, 0, imagecolorallocate($image, 28, 120, 104));
        $sourcePath = tempnam(sys_get_temp_dir(), 'simkeu-image-');
        imagepng($image, $sourcePath);
        imagedestroy($image);

        try {
            $upload = new UploadedFile($sourcePath, 'document.png', 'image/png', null, true);
            $path = app(ImageStorage::class)->store($upload, 'image-test');

            $this->assertStringEndsWith('.webp', $path);
            $this->assertLessThanOrEqual(1024 * 1024, Storage::disk('local')->size($path));
            $this->assertSame('image/webp', getimagesizefromstring(Storage::disk('local')->get($path))['mime']);
        } finally {
            unlink($sourcePath);
        }
    }

    public function test_cooperative_logo_keeps_original_transparent_png(): void
    {
        Storage::fake('local');
        $image = imagecreatetruecolor(240, 240);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, 239, 239, $transparent);
        imagealphablending($image, true);
        imagefilledellipse($image, 120, 120, 120, 120, imagecolorallocatealpha($image, 13, 148, 136, 0));
        $sourcePath = tempnam(sys_get_temp_dir(), 'simkeu-logo-');
        imagepng($image, $sourcePath);
        imagedestroy($image);

        try {
            $upload = new UploadedFile($sourcePath, 'logo.png', 'image/png', null, true);
            $path = app(ImageStorage::class)->store($upload, 'koperasi');
            $stored = imagecreatefromstring(Storage::disk('local')->get($path));

            $this->assertStringEndsWith('.png', $path);
            $this->assertSame('image/png', getimagesizefromstring(Storage::disk('local')->get($path))['mime']);
            $this->assertSame(127, imagecolorsforindex($stored, imagecolorat($stored, 8, 8))['alpha']);
            imagedestroy($stored);
        } finally {
            unlink($sourcePath);
        }
    }
}
