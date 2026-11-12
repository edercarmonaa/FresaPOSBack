<?php

namespace Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Modules\Catalog\Contracts\ImageReference;
use Modules\Catalog\Contracts\ProductSnapshot;

class ProductResource extends JsonResource
{
    public function __construct(private readonly ProductSnapshot $product, private readonly string $categoryName)
    {
        parent::__construct($product);
    }

    public static function image(?ImageReference $image): ?array
    {
        if ($image === null) {
            return null;
        }
        if (config('filesystems.disks.'.$image->disk.'.visibility') !== 'public') {
            throw new LogicException('La imagen requiere almacenamiento público configurado.');
        }
        $url = Storage::disk($image->disk)->url($image->key);
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://') && (! str_starts_with($url, '/') || str_starts_with($url, '//'))) {
            throw new LogicException('La referencia pública de imagen no es válida.');
        }

        return ['url' => $url, 'version' => pathinfo($image->key, PATHINFO_FILENAME)];
    }

    public function toArray(Request $request): array
    {
        $product = $this->product;

        return ['id' => $product->id, 'name' => $product->name, 'description' => $product->description,
            'category_id' => $product->categoryId, 'category' => ['id' => $product->categoryId, 'name' => $this->categoryName],
            'price' => $product->price, 'is_active' => $product->isActive, 'image' => self::image($product->image),
            'created_at' => $product->createdAt, 'updated_at' => $product->updatedAt, 'deleted_at' => $product->deletedAt];
    }
}
