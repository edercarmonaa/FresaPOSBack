<?php

namespace Modules\Sales\Application\Ports;

use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SaleItemSnapshot;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesPage;
use Modules\Sales\Contracts\TableQuery;

interface SalesStore
{
    public function findSale(string $id, bool $lock = false): SaleSnapshot;

    public function createSale(array $values): SaleSnapshot;

    public function updateSale(string $id, array $values): SaleSnapshot;

    public function createItem(string $saleId, array $values): SaleItemSnapshot;

    public function updateItem(string $saleId, string $itemId, array $values): void;

    public function sales(SaleQuery $query): SalesPage;

    public function findTable(string $id, bool $lock = false): DiningTableSnapshot;

    public function createTable(array $values): DiningTableSnapshot;

    public function updateTable(string $id, array $values): DiningTableSnapshot;

    public function tables(TableQuery $query): SalesPage;
}
