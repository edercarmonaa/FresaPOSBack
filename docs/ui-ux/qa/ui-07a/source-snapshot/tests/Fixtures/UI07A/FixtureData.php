<?php

namespace Tests\Fixtures\UI07A;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\CategorySnapshot;

final class FixtureData
{
    public static function make(string $state = 'list'): array
    {
        $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $active = new CategorySnapshot($id, 'Categoría ficticia', 'Descripción de ejemplo', true, 1, '2026-11-27', '2026-11-27', null);
        $inactive = new CategorySnapshot('01ARZ3NDEKTSV4RRFFQ69G5FAW', 'Categoría inactiva ficticia', null, false, 2, '2026-11-27', '2026-11-27', null);
        $errors = new ViewErrorBag;
        if ($state === 'edit') {
            $errors->put('default', new MessageBag(['name' => 'Revisa el nombre de muestra.', 'price' => 'Revisa el precio de muestra.', 'category_id' => 'Revisa la categoría de muestra.']));
        }
        $empty = $state === 'empty';
        $items = $empty ? [] : [
            ['id' => $id, 'name' => 'Producto ficticio con nombre largo para comprobar su lectura completa', 'description' => 'Descripción de muestra, sin datos operativos.', 'price' => '1234.5678', 'is_active' => true, 'deleted_at' => null, 'category' => ['name' => $active->name], 'image' => ['url' => '/qa-image.svg']],
            ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'name' => 'Segundo producto ficticio', 'description' => null, 'price' => '0.0000', 'is_active' => false, 'deleted_at' => $state === 'deleted' ? '2026-11-27' : null, 'category' => ['name' => $inactive->name], 'image' => null],
        ];

        return ['status' => $state === 'edit' ? 'Mensaje de muestra; sin operación real.' : '', 'errors' => $errors,
            'formOpen' => in_array($state, ['create', 'edit'], true), 'editingId' => $state === 'edit' ? $id : null,
            'name' => 'Nombre de muestra', 'description' => '', 'isActive' => true, 'displayOrder' => 1,
            'imagePreview' => $state === 'create' ? '/qa-image.svg' : null, 'currentImageUrl' => $state === 'edit' ? '/qa-image.svg' : null,
            'categories' => [$active, $inactive], 'activeCategories' => [$active], 'originalCategory' => $state === 'edit' ? $inactive : null,
            'items' => $items, 'result' => new CatalogPage($empty ? [] : [$active, $inactive], $empty ? 0 : 50, 2, 25),
            'page' => $empty ? 1 : 2, 'pagination' => ['from' => $empty ? null : 26, 'to' => $empty ? null : 27, 'total' => $empty ? 0 : 50, 'current_page' => $empty ? 1 : 2, 'last_page' => $empty ? 1 : 2],
        ];
    }
}
