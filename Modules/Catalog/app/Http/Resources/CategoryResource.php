<?php

namespace Modules\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Catalog\Contracts\CategorySnapshot;

class CategoryResource extends JsonResource
{
    public function __construct(private readonly CategorySnapshot $category)
    {
        parent::__construct($category);
    }

    public function toArray(Request $request): array
    {
        $category = $this->category;

        return ['id' => $category->id, 'name' => $category->name, 'description' => $category->description,
            'is_active' => $category->isActive, 'display_order' => $category->displayOrder,
            'created_at' => $category->createdAt, 'updated_at' => $category->updatedAt, 'deleted_at' => $category->deletedAt];
    }
}
