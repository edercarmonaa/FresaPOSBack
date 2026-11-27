<?php

namespace Tests\Fixtures\UI08A;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Catalog\Contracts\CatalogPage;
use Modules\Catalog\Contracts\CategorySnapshot;
use Modules\Catalog\Contracts\ProductSnapshot;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SalesPage;
use Modules\Sales\Contracts\SaleSummary;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Contracts\UserSnapshot;

final class FixtureData
{
    public const STATES = ['empty', 'open', 'tables', 'table-selected', 'paused', 'closed', 'cancelled', 'pending', 'stale', 'no-cash', 'no-critical', 'read-only', 'add-editor', 'item-editor', 'error', 'long'];

    public static function make(string $state = 'open', bool $permissions = true): array
    {
        $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $date = '2026-11-27 12:00:00';
        $name = $state === 'long' ? str_repeat('Producto con nombre extenso ', 6) : 'Café de prueba <script>alert("escaped")</script>';
        $actor = new UserSnapshot($id, 'Empleado ficticio UI-08A', 'ui_fixture', true, false, [], $permissions ? ['sales.create', 'sales.open.update', 'sales.close', 'cash.view', 'sales.cancel', 'sales.special.authorize', 'sales.discounts.apply', 'tickets.view'] : []);
        $products = new CatalogPage($state === 'empty' ? [] : [new ProductSnapshot($id, $id, $name, null, '45.0000', true, null, $date, $date, null), new ProductSnapshot('product-2', $id, 'Producto sin imagen', null, '30.0000', true, null, $date, $date, null)], $state === 'empty' ? 0 : 26, 1, 24);
        $categories = new CatalogPage($state === 'empty' ? [] : [new CategorySnapshot($id, 'Bebidas de prueba', null, true, 1, $date, $date, null)], $state === 'empty' ? 0 : 11, 1, 10);
        $tables = new SalesPage([new DiningTableSnapshot('table-1', 'Mesa libre', true, 1, false), new DiningTableSnapshot('table-2', 'Mesa ocupada', true, 2, true, $id), new DiningTableSnapshot('table-3', 'Mesa inactiva', false, 3, false)], 12, 1, 10);
        $hasSale = ! in_array($state, ['empty', 'tables', 'table-selected', 'pending'], true);
        $saleState = in_array($state, ['paused', 'closed', 'cancelled'], true) ? $state : 'open';
        $sales = new SalesPage($state === 'empty' ? [] : [new SaleSummary($id, SaleType::Counter, SaleState::from($saleState), null, $id, $id, null, '90.0000', '0.0000', '90.0000', $state === 'closed' ? '202611270001' : null, $state === 'closed' ? $date : null, null, null, null, $date, $date)], $state === 'empty' ? 0 : 12, 1, 10);
        $currentSale = $hasSale ? ['type' => 'counter', 'state' => $saleState, 'table_id' => null, 'table_name' => null, 'items' => [['id' => 'item-1', 'name' => $name, 'quantity' => 2, 'unit_price' => '45.0000', 'line_total' => '90.0000', 'note' => 'Nota ficticia conservada', 'removed_at' => null], ['id' => 'item-2', 'name' => 'Línea retirada', 'quantity' => 1, 'unit_price' => '30.0000', 'line_total' => '30.0000', 'note' => null, 'removed_at' => $date]], 'subtotal' => '90.0000', 'discount' => '0.0000', 'total' => '90.0000'] : null;
        $errors = new ViewErrorBag;
        if ($state === 'error') {
            $errors->put('default', new MessageBag(['quantity' => 'Revisa la cantidad.', 'saleNote' => 'Nota <script> inválida.']));
        }

        return compact('actor', 'products', 'categories', 'tables', 'sales', 'currentSale', 'errors') + [
            'cash' => $state !== 'no-cash', 'critical' => ! in_array($state, ['no-cash', 'no-critical', 'pending'], true), 'editable' => $permissions && ! in_array($state, ['read-only', 'stale', 'paused', 'closed', 'cancelled', 'no-cash', 'pending'], true),
            'stale' => $state === 'stale', 'images' => [$id => '/qa-image.svg'], 'cashSessionId' => $state === 'no-cash' ? null : $id, 'intentPending' => $state === 'pending', 'status' => '',
            'mode' => in_array($state, ['tables', 'table-selected'], true) ? 'table' : 'counter', 'counterState' => in_array($state, ['paused', 'closed'], true) ? $state : 'open', 'saleId' => $hasSale ? $id : null, 'tableId' => $state === 'table-selected' ? 'table-1' : null,
            'search' => '', 'categoryFilter' => '', 'categoryName' => '', 'salesPage' => 1, 'tablePage' => 1, 'categoryPage' => 1, 'productPage' => 1, 'paidFolio' => '', 'saleNote' => 'Nota general ficticia',
            'productId' => $state === 'add-editor' ? $id : null, 'itemId' => $state === 'item-editor' ? 'item-1' : null, 'productName' => $name, 'quantity' => 2, 'itemNote' => 'Nota de producto ficticia',
        ];
    }

    public static function source(bool $before): string
    {
        $source = file_get_contents(base_path($before ? 'tests/Fixtures/UI08A/point-of-sale.blade.php' : 'Modules/Sales/resources/views/livewire/point-of-sale.blade.php'));
        $source = preg_replace('/@script.*?@endscript/s', '', $source);

        return preg_replace('/<livewire:sales\.([a-z-]+)\s[^>]*\/>/', '<div data-ui08a-child="$1">Panel financiero fuera del alcance visual UI-08A</div>', $source);
    }
}
