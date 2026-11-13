<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

trait ImageFixtures
{
    private function imageBytes(string $format = 'png', int $width = 80, int $height = 40, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 30, 100, 170, $transparent ? 127 : 0));
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image), 'webp' => imagewebp($image), default => imagepng($image)
        };

        return ob_get_clean();
    }

    private function imageFile(string $format = 'png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('client-name.'.$format, $this->imageBytes($format));
    }
}
