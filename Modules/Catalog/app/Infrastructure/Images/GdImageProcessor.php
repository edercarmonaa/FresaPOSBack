<?php

namespace Modules\Catalog\Infrastructure\Images;

use Closure;
use ErrorException;
use GdImage;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Application\Ports\ImageProcessor;
use RuntimeException;
use SplFileInfo;

final class GdImageProcessor implements ImageProcessor
{
    private function invalid(): never
    {
        throw ValidationException::withMessages(['image' => 'La imagen debe ser JPEG, PNG o WebP válido, de hasta 5 MB y dimensiones seguras.']);
    }

    private function checked(Closure $operation): mixed
    {
        // Las advertencias del decodificador son datos no confiables, no se envían al cliente.
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $level, $file, $line);
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    public function process(SplFileInfo $file): string
    {
        // TemporaryUploadedFile resuelve su ubicación real en el disco privado.
        if ($file->isLink()) {
            $this->invalid();
        }
        $path = $file->getRealPath();
        if ($path === false) {
            $this->invalid();
        }
        $localFile = new SplFileInfo($path);
        if (! extension_loaded('gd') || ! function_exists('imagewebp') || ! extension_loaded('exif')) {
            throw new RuntimeException('El procesador de imágenes no está disponible.');
        }
        if (! $localFile->isFile() || $localFile->isLink() || $localFile->getSize() < 1 || $localFile->getSize() > config('catalog.images.max_bytes')) {
            $this->invalid();
        }
        $data = file_get_contents($path);
        if ($data === false || strlen($data) > config('catalog.images.max_bytes')) {
            $this->invalid();
        }
        try {
            $info = $this->checked(fn () => getimagesizefromstring($data));
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
            $allowed = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
            if ($info === false || ! isset($allowed[$info[2]]) || $mime !== $allowed[$info[2]]) {
                $this->invalid();
            }
            [$width, $height, $type] = $info;
            if (($type === IMAGETYPE_JPEG && ! str_contains($data, "\xff\xd9"))
                || ($type === IMAGETYPE_PNG && ! str_contains($data, "\0\0\0\0IEND\xae\x42\x60\x82"))
                || ($type === IMAGETYPE_WEBP && unpack('Vsize', substr($data, 4, 4))['size'] + 8 !== strlen($data))) {
                $this->invalid();
            }
            $pixels = $width * $height;
            $required = $pixels * 16 + strlen($data) * 3 + 32 * 1024 * 1024;
            $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
            $available = $memoryLimit < 0 ? PHP_INT_MAX : $memoryLimit - memory_get_usage(true) - 16 * 1024 * 1024;
            if ($width < 1 || $height < 1 || max($width, $height) > config('catalog.images.max_input_dimension')
                || $pixels > config('catalog.images.max_pixels') || $required > min($available, config('catalog.images.max_processing_bytes'))) {
                $this->invalid();
            }
            // No se admite animación: WebP RIFF y PNG con chunks de animación se rechazan antes del decode.
            if (($type === IMAGETYPE_WEBP && $this->hasChunk($data, 'ANIM', true))
                || ($type === IMAGETYPE_PNG && $this->hasChunk($data, 'acTL', false))) {
                $this->invalid();
            }
            $orientation = 1;
            if ($type === IMAGETYPE_JPEG && str_contains($data, "Exif\0\0")) {
                $exif = $this->checked(fn () => exif_read_data($path, 'IFD0', true));
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
            }
            $source = $this->checked(fn () => imagecreatefromstring($data));
            if (! $source instanceof GdImage) {
                $this->invalid();
            }
        } catch (ErrorException) {
            $this->invalid();
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($source, in_array($orientation, [4, 5], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 6, 7 => -90, 8 => 90, default => 0
        };
        if ($angle !== 0) {
            $rotated = imagerotate($source, $angle, 0);
            if (! $rotated instanceof GdImage) {
                throw new RuntimeException('No se pudo orientar la imagen.');
            }
            $source = $rotated;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, (int) config('catalog.images.max_dimension') / max($width, $height));
        $targetWidth = max(1, (int) floor($width * $ratio));
        $targetHeight = max(1, (int) floor($height * $ratio));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $canvas instanceof GdImage) {
            throw new RuntimeException('No se pudo procesar la imagen.');
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        unset($source, $rotated, $data);
        ob_start();
        try {
            $written = $this->checked(fn () => imagewebp($canvas, null, (int) config('catalog.images.quality')));
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        // imagewebp puede indicar éxito sin producir contenido: verificar también el resultado.
        if (! $written || ! is_string($output) || strlen($output) < 12 || strlen($output) > config('catalog.images.max_bytes')
            || getimagesizefromstring($output)[2] !== IMAGETYPE_WEBP) {
            throw new RuntimeException('No se pudo generar WebP válido.');
        }

        return $output;
    }

    private function hasChunk(string $data, string $wanted, bool $webp): bool
    {
        $offset = $webp ? 12 : 8;
        while ($offset + 8 <= strlen($data)) {
            $length = unpack($webp ? 'Vsize' : 'Nsize', substr($data, $offset + ($webp ? 4 : 0), 4))['size'];
            $type = substr($data, $offset + ($webp ? 0 : 4), 4);
            if ($type === $wanted) {
                return true;
            }
            $offset += 8 + $length + ($webp ? $length % 2 : 4);
        }

        return false;
    }
}
