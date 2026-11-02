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
}
