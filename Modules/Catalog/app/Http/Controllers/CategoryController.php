<?php

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Http\CatalogAccess;
use Modules\Catalog\Http\CatalogFilters;
use Modules\Catalog\Http\CatalogPresentation;
use Modules\Catalog\Http\Requests\CatalogListRequest;
use Modules\Catalog\Http\Requests\CategoryRequest;
use Modules\Catalog\Http\Requests\StatusRequest;
use Modules\Catalog\Http\Resources\CategoryResource;
use Modules\Core\Contracts\ApiResponder;

final class CategoryController
{
    public function __construct(private readonly CatalogAdministration $catalog, private readonly ApiResponder $responses, private readonly CatalogAccess $access) {}

    private function actor(Request $request): string
    {
        return $this->access->requireAny(['catalog.products.view', 'catalog.categories.manage'], $request);
    }

    public function index(CatalogListRequest $request): JsonResponse
    {
        $page = $this->catalog->categories($this->actor($request), CatalogFilters::query($request->validated(), false));
        $items = array_map(fn ($category) => (new CategoryResource($category))->resolve($request), $page->items);

        return $this->responses->success($request, ['categories' => $items], CatalogPresentation::meta($page));
    }

    public function show(CatalogListRequest $request, string $id): JsonResponse
    {
        $record = $this->catalog->category($this->actor($request), $id, $request->input('deleted', 'without') !== 'without');
        abort_if($request->input('deleted') === 'only' && $record->deletedAt === null, 404);

        return $this->responses->success($request, (new CategoryResource($record))->resolve($request));
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $record = $this->catalog->createCategory($this->actor($request), $request->catalogData());

        return $this->responses->success($request, (new CategoryResource($record))->resolve($request), status: 201);
    }

    public function update(CategoryRequest $request, string $id): JsonResponse
    {
        $record = $this->catalog->updateCategory($this->actor($request), $id, $request->catalogData());

        return $this->responses->success($request, (new CategoryResource($record))->resolve($request));
    }

    public function status(StatusRequest $request, string $id): JsonResponse
    {
        $record = $this->catalog->setCategoryActive($this->actor($request), $id, (bool) $request->validated('is_active'));

        return $this->responses->success($request, (new CategoryResource($record))->resolve($request));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->requireAny(['catalog.categories.manage'], $request);
        $this->catalog->deleteCategory($actor, $id);

        return $this->responses->success($request, null);
    }
}
