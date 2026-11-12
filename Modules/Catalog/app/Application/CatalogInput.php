<?php

namespace Modules\Catalog\Application;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Domain\CatalogText;
use Modules\Catalog\Domain\Price;

final class CatalogInput
{
    public static function category(array $data, bool $creating): array
    {
        self::keys($data, ['name', 'description', 'is_active', 'display_order']);
        $result = self::common($data, $creating, 1000);
        if (array_key_exists('display_order', $data)) {
            if (! is_int($data['display_order']) || $data['display_order'] < 0 || $data['display_order'] > 2147483647) {
                throw ValidationException::withMessages(['display_order' => 'El orden debe ser un entero no negativo válido.']);
            }
            $result['display_order'] = $data['display_order'];
        }

        return $result;
    }

    public static function product(array $data, bool $creating): array
    {
        self::keys($data, ['name', 'description', 'category_id', 'price', 'is_active', 'image']);
        $result = self::common($data, $creating, 2000);
        foreach (['category_id', 'price'] as $field) {
            if ($creating || array_key_exists($field, $data)) {
                try {
                    $result[$field] = $field === 'price' ? Price::from($data[$field] ?? null)->value : CatalogText::id($data[$field] ?? null);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$field => $exception->getMessage()]);
                }
            }
        }
        if (array_key_exists('image', $data)) {
            if ($data['image'] !== null && ! $data['image'] instanceof ImageReference) {
                throw ValidationException::withMessages(['image' => 'Utiliza una referencia de almacenamiento validada o null.']);
            }
            if ($data['image'] !== null && config('filesystems.disks.'.$data['image']->disk.'.visibility') !== 'public') {
                throw ValidationException::withMessages(['image' => 'El disco de imágenes debe estar configurado como público.']);
            }
            $result['image_disk'] = $data['image']?->disk;
            $result['image_key'] = $data['image']?->key;
        }

        return $result;
    }

    private static function common(array $data, bool $creating, int $descriptionLength): array
    {
        $result = [];
        foreach (['name', 'description'] as $field) {
            if (($field === 'name' && $creating) || array_key_exists($field, $data)) {
                try {
                    $result[$field] = $field === 'description' && ($data[$field] === null || $data[$field] === '')
                        ? null : CatalogText::normalize($data[$field] ?? null, $field === 'name' ? 150 : $descriptionLength, $field === 'name');
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$field => $exception->getMessage()]);
                }
            }
        }
        if (array_key_exists('is_active', $data)) {
            if (! is_bool($data['is_active'])) {
                throw ValidationException::withMessages(['is_active' => 'El estado debe ser booleano.']);
            }
            $result['is_active'] = $data['is_active'];
        }

        return $result;
    }

    private static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['data' => 'La solicitud contiene campos no permitidos.']);
        }
    }
}
