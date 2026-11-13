<?php

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Application\Services\ManageProductImages;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Catalog\Http\CatalogAccess;
use Modules\Catalog\Http\CatalogFilters;
use Modules\Catalog\Http\CatalogPresentation;
use Modules\Catalog\Http\Requests\CatalogListRequest;
use Modules\Catalog\Http\Requests\ProductImageRequest;
use Modules\Catalog\Http\Requests\ProductRequest;
use Modules\Catalog\Http\Requests\StatusRequest;
use Modules\Core\Contracts\ApiResponder;

final class ProductController
{
    public function __construct(private readonly CatalogAdministration $catalog, private readonly ApiResponder $responses, private readonly CatalogAccess $access) {}

    private function actor(Request $request): string
    {
        return $this->access->requireAny(['catalog.products.view'], $request);
    }

    private function respond(Request $request, string $actor, ProductSnapshot $product, int $status = 200): JsonResponse
    {
        return $this->responses->success($request, (new CatalogPresentation($this->catalog, $actor))->product($product, $request), status: $status);
    }

    public function index(CatalogListRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $page = $this->catalog->products($actor, CatalogFilters::query($request->validated(), true));
        $presentation = new CatalogPresentation($this->catalog, $actor);
        $items = array_map(fn ($product) => $presentation->product($product, $request), $page->items);

        return $this->responses->success($request, ['products' => $items], CatalogPresentation::meta($page));
    }

    public function show(CatalogListRequest $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $record = $this->catalog->product($actor, $id, $request->input('deleted', 'without') !== 'without');
        abort_if($request->input('deleted') === 'only' && $record->deletedAt === null, 404);

        return $this->respond($request, $actor, $record);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->respond($request, $actor, $this->catalog->createProduct($actor, $request->catalogData()), 201);
    }

    public function update(ProductRequest $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->respond($request, $actor, $this->catalog->updateProduct($actor, $id, $request->catalogData()));
    }

    public function status(StatusRequest $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->respond($request, $actor, $this->catalog->setProductActive($actor, $id, (bool) $request->validated('is_active')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->actor($request);
        $actor = $this->access->requireAny(['catalog.products.deactivate'], $request);
        $this->catalog->deleteProduct($actor, $id);

        return $this->responses->success($request, null);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->actor($request);
        $actor = $this->access->requireAny(['catalog.products.restore'], $request);

        return $this->respond($request, $actor, $this->catalog->restoreProduct($actor, $id));
    }

    public function uploadImage(ProductImageRequest $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $product = app(ManageProductImages::class)->replace($actor, $id, $request->file('image'));

        return $this->respond($request, $actor, $product);
    }

    public function removeImage(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->requireAny(['catalog.products.update'], $request);
        $product = app(ManageProductImages::class)->remove($actor, $id);

        return $this->respond($request, $actor, $product);
    }
}
