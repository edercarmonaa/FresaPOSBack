<?php

namespace Modules\Catalog\Infrastructure\Images;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Modules\Catalog\Application\ImageChange;
use Modules\Catalog\Application\Ports\ProductImageFiles;
use Modules\Catalog\Contracts\ImageReference;
use RuntimeException;

final class FilesystemProductImages implements ProductImageFiles
{
    public function __construct(private readonly Factory $files) {}

    private function journal(): Filesystem
    {
        $name = config('catalog.images.journal_disk');
        if (config('filesystems.disks.'.$name.'.visibility') === 'public' || $name === config('catalog.images.disk')) {
            throw new RuntimeException('El registro de recuperación requiere un disco privado independiente.');
        }

        return $this->files->disk($name);
    }

    private function imageDisk(ImageReference $reference): Filesystem
    {
        if (config('filesystems.disks.'.$reference->disk.'.visibility') !== 'public') {
            throw new RuntimeException('El disco de imágenes debe ser público.');
        }

        return $this->files->disk($reference->disk);
    }

    private function key(ImageChange $change): string
    {
        return 'catalog-image-journal/'.$change->id.'.json';
    }

    public function open(?string $webp, ?ImageReference $previous): ImageChange
    {
        $id = strtolower((string) Str::ulid());
        $next = $webp === null ? null : new ImageReference(config('catalog.images.disk'), 'catalog/products/'.$id.'.webp');
        if ($next !== null && $this->imageDisk($next)->exists($next->key)) {
            throw new RuntimeException('La versión de imagen ya existe.');
        }
        $change = new ImageChange($id, $next, $previous, now()->timestamp);
        $serialize = static fn (?ImageReference $reference) => $reference === null ? null : ['disk' => $reference->disk, 'key' => $reference->key];
        $json = json_encode(['id' => $id, 'next' => $serialize($next), 'previous' => $serialize($previous), 'created_at' => $change->createdAt], JSON_THROW_ON_ERROR);
        if (! $this->journal()->put($this->key($change), $json, ['visibility' => 'private'])) {
            throw new RuntimeException('No se pudo registrar la operación de imagen.');
        }
        if ($next !== null && ! $this->imageDisk($next)->put($next->key, $webp, ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'])) {
            // Se conserva el journal: un adaptador puede haber escrito parcialmente antes de fallar.
            throw new RuntimeException('No se pudo guardar la imagen.');
        }

        return $change;
    }

    public function delete(ImageReference $reference): void
    {
        $disk = $this->imageDisk($reference);
        if ($disk->exists($reference->key) && ! $disk->delete($reference->key)) {
            throw new RuntimeException('No se pudo retirar un archivo sin referencias.');
        }
    }

    public function complete(ImageChange $change): void
    {
        if (! $this->journal()->delete($this->key($change))) {
            throw new RuntimeException('No se pudo cerrar el registro de recuperación.');
        }
    }

    public function pending(int $before): iterable
    {
        foreach ($this->journal()->files('catalog-image-journal') as $file) {
            if (preg_match('~\Acatalog-image-journal/[0-7][0-9a-hjkmnp-tv-z]{25}\.json\z~', $file) !== 1) {
                continue;
            }
            $json = $this->journal()->get($file);
            if (! is_string($json) || strlen($json) > 2048) {
                throw new RuntimeException('Registro de recuperación inválido.');
            }
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (($data['id'] ?? null) !== pathinfo($file, PATHINFO_FILENAME) || ! is_int($data['created_at'] ?? null)) {
                throw new RuntimeException('Registro de recuperación inválido.');
            }
            if ($data['created_at'] > $before) {
                continue;
            }
            $reference = static fn (?array $value) => $value === null ? null : new ImageReference($value['disk'], $value['key']);
            yield new ImageChange($data['id'], $reference($data['next']), $reference($data['previous']), $data['created_at']);
        }
    }
}
