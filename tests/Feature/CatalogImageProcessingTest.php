<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Application\Ports\ImageProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ImageFixtures;
use Tests\TestCase;

class CatalogImageProcessingTest extends TestCase
{
    use ImageFixtures;

    public static function formats(): array
    {
        return [['jpeg'], ['png'], ['webp']];
    }

    #[DataProvider('formats')]
    public function test_supported_content_becomes_valid_webp_without_upscaling(string $format): void
    {
        $bytes = app(ImageProcessor::class)->process($this->imageFile($format));
        $info = getimagesizefromstring($bytes);
        $this->assertSame([80, 40, IMAGETYPE_WEBP], array_slice($info, 0, 3));
        $this->assertLessThan(10000, strlen($bytes));
    }

    public function test_resize_preserves_proportions_and_alpha(): void
    {
        config(['catalog.images.max_dimension' => 120]);
        $upload = UploadedFile::fake()->createWithContent('photo.png', $this->imageBytes('png', 240, 120, true));
        $bytes = app(ImageProcessor::class)->process($upload);
        $image = imagecreatefromstring($bytes);
        $this->assertSame([120, 60], [imagesx($image), imagesy($image)]);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 50, 25))['alpha']);
    }

    public static function orientations(): array
    {
        return array_map(fn ($orientation) => [$orientation], range(1, 8));
    }

    #[DataProvider('orientations')]
    public function test_exif_orientation_is_applied_and_private_metadata_is_removed(int $orientation): void
    {
        $source = imagecreatetruecolor(80, 40);
        foreach ([[0, 0, 39, 19, 240, 10, 10], [40, 0, 79, 19, 10, 240, 10], [0, 20, 39, 39, 10, 10, 240], [40, 20, 79, 39, 240, 240, 10]] as [$x1, $y1, $x2, $y2, $r, $g, $b]) {
            imagefilledrectangle($source, $x1, $y1, $x2, $y2, imagecolorallocate($source, $r, $g, $b));
        }
        ob_start();
        imagejpeg($source, null, 100);
        $jpeg = ob_get_clean();
        $exif = "Exif\0\0II".pack('vVv', 42, 8, 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\0\0".pack('V', 0);
        $comment = 'GPS_PRIVATE_LOCATION';
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif."\xff\xfe".pack('n', strlen($comment) + 2).$comment.substr($jpeg, 2);
        $bytes = app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('exif.jpg', $jpeg));
        $dimensions = $orientation >= 5 ? [40, 80] : [80, 40];
        $this->assertSame($dimensions, array_slice(getimagesizefromstring($bytes), 0, 2));
        $expected = match ($orientation) {
            1, 5 => [240, 10, 10], 2, 8 => [10, 240, 10], 3, 7 => [240, 240, 10], 4, 6 => [10, 10, 240]
        };
        $decoded = imagecreatefromstring($bytes);
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 5, 5));
        foreach (['red', 'green', 'blue'] as $index => $channel) {
            $this->assertEqualsWithDelta($expected[$index], $corner[$channel], 20);
        }
        $this->assertStringNotContainsString('GPS_PRIVATE_LOCATION', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertStringNotContainsString('EXIF', $bytes);
    }

    public static function invalidContents(): array
    {
        return [[''], ['<?php echo "EXECUTABLE";'], ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'], [base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==')], ['PK'.str_repeat('compressed', 20)], ['RIFF0000WEBPinvalid']];
    }

    #[DataProvider('invalidContents')]
    public function test_invalid_content_is_rejected_despite_jpeg_filename_and_browser_mime(string $content): void
    {
        $upload = UploadedFile::fake()->createWithContent('disguised.jpg', $content)->mimeType('image/jpeg');
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process($upload);
    }

    public function test_client_filename_and_mime_never_determine_the_stored_format(): void
    {
        $upload = UploadedFile::fake()->createWithContent('misleading.exe', $this->imageBytes('png'))->mimeType('application/octet-stream');
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(app(ImageProcessor::class)->process($upload))[2]);
    }

    public function test_original_at_five_mib_is_allowed_but_one_extra_byte_is_rejected(): void
    {
        $jpeg = str_pad($this->imageBytes('jpeg'), 5 * 1024 * 1024, "\0");
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('limit.jpg', $jpeg)))[2]);
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('too-large.jpg', $jpeg.'x'));
    }

    public function test_dangerous_dimensions_are_rejected_before_decoding(): void
    {
        $png = $this->imageBytes();
        $png = substr_replace($png, pack('NN', 10000, 10000), 16, 8);
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('bomb.png', $png));
    }

    public function test_corrupt_image_with_a_valid_header_is_rejected(): void
    {
        $jpeg = $this->imageBytes('jpeg');
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('broken.jpg', substr($jpeg, 0, -40)));
    }

    public function test_processing_memory_budget_is_enforced_before_decoding(): void
    {
        config(['catalog.images.max_processing_bytes' => 1]);
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process($this->imageFile());
    }

    public function test_animated_png_is_rejected_even_with_valid_static_pixels(): void
    {
        $png = $this->imageBytes('png');
        $payload = pack('NN', 2, 0);
        $chunk = pack('N', 8).'acTL'.$payload.pack('N', crc32('acTL'.$payload));
        $animated = substr($png, 0, 33).$chunk.substr($png, 33);
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('animated.png', $animated));
    }

    public function test_animated_webp_is_rejected(): void
    {
        $webp = $this->imageBytes('webp');
        $vp8x = 'VP8X'.pack('V', 10)."\x02\0\0\0".substr(pack('V', 79), 0, 3).substr(pack('V', 39), 0, 3);
        $animation = 'ANIM'.pack('V', 6).str_repeat("\0", 6);
        $chunks = $vp8x.$animation.substr($webp, 12);
        $animated = 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
        $this->expectException(ValidationException::class);
        app(ImageProcessor::class)->process(UploadedFile::fake()->createWithContent('animated.webp', $animated));
    }
}
