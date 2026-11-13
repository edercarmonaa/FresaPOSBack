<?php

namespace Modules\Sales\Contracts;

interface SalesOperations
{
    public function open(string $actorId, OpenSale $input): SaleSnapshot;

    public function sale(string $actorId, string $saleId): SaleSnapshot;

    public function sales(string $actorId, ?SaleQuery $query = null): SalesPage;

    public function addProduct(string $actorId, string $saleId, AddProduct $input): SaleSnapshot;

    public function changeQuantity(string $actorId, string $saleId, string $itemId, mixed $quantity, ?string $removalReasonCode = null): SaleSnapshot;

    public function editItemNote(string $actorId, string $saleId, string $itemId, ?string $note): SaleSnapshot;

    public function editNote(string $actorId, string $saleId, ?string $note): SaleSnapshot;

    public function removeItem(string $actorId, string $saleId, string $itemId, string $reasonCode): SaleSnapshot;

    public function pause(string $actorId, string $saleId): SaleSnapshot;

    public function resume(string $actorId, string $saleId): SaleSnapshot;

    public function cancel(string $actorId, string $saleId, string $reason): SaleSnapshot;

    public function createTable(string $actorId, string $name, int $displayOrder = 0): DiningTableSnapshot;

    public function updateTable(string $actorId, string $id, string $name, bool $isActive, int $displayOrder = 0): DiningTableSnapshot;

    public function table(string $actorId, string $id): DiningTableSnapshot;

    public function tables(string $actorId, ?TableQuery $query = null): SalesPage;
}
