<?php

namespace Modules\Catalog\Http;

use Illuminate\Http\Request;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Http\Resources\ProductResource;

final class CatalogPresentation
{
    /** @var array<string,string> */
    private array $categoryNames = [];

    public function __construct(private readonly CatalogAdministration $catalog, private readonly string $actorId) {}

    public function product(ProductSnapshot $product, Request $request): array
    {
        $name = $this->categoryNames[$product->categoryId] ??= $this->catalog->category($this->actorId, $product->categoryId, true)->name;

        return (new ProductResource($product, $name))->resolve($request);
    }

    public static function meta(CatalogPage $page): array
    {
        return ['pagination' => ['current_page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total,
            'last_page' => max(1, intdiv(max(0, $page->total - 1), $page->perPage) + 1),
            'from' => $page->items === [] ? null : ($page->page - 1) * $page->perPage + 1,
            'to' => $page->items === [] ? null : ($page->page - 1) * $page->perPage + count($page->items)]];
    }

    public static function money(string $price): string
    {
        // Agrupar únicamente caracteres; nunca usar number_format con un float.
        [$integer, $fraction] = explode('.', $price);
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer) ?? $integer;

        return '$'.$integer.'.'.$fraction;
    }
}
