<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Modules\Sales\Application\Ports\StagedSalesStore;
use Modules\Sales\Application\SaleSnapshotCodec;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\SaleItemSnapshot;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesPage;
use Modules\Sales\Contracts\TableQuery;

/** Puerto privado en memoria: ManageSales conserva las reglas sin escribir historia confirmada. */
final class CorrectionDraftStore implements LineRemovalApproval, StagedSalesStore
{
    private array $data;

    private const SALE_FIELDS = ['note' => 'note', 'subtotal' => 'subtotal', 'discount_amount' => 'discount', 'total' => 'total'];

    private const ITEM_FIELDS = ['quantity' => 'quantity', 'note' => 'note', 'line_total' => 'lineTotal', 'removed_at' => 'removedAt', 'removed_by' => 'removedBy', 'removal_reason_code' => 'removalReasonCode'];

    public function __construct(SaleSnapshot $snapshot, private readonly SaleOperationReasons $reasons)
    {
        $this->data = SaleSnapshotCodec::encode($snapshot);
    }

    public function snapshot(): SaleSnapshot
    {
        return SaleSnapshotCodec::decode($this->data);
    }

    public function findSale(string $id, bool $lock = false): SaleSnapshot
    {
        if ($id !== $this->data['sale']['id']) {
            throw new ModelNotFoundException;
        }
        $data = $this->data;
        // Solamente la vista de trabajo interna es editable; la venta real permanece closed.
        $data['sale']['state'] = 'open';
        $data['sale']['folio'] = $data['sale']['closedAt'] = $data['sale']['closedBy'] = null;
        $data['payments'] = [];

        return SaleSnapshotCodec::decode($data);
    }

    public function updateSale(string $id, array $values): SaleSnapshot
    {
        $this->findSale($id);
        foreach ($values as $field => $value) {
            if (isset(self::SALE_FIELDS[$field])) {
                $this->data['sale'][self::SALE_FIELDS[$field]] = $value;
            } elseif ($field === 'discount_type') {
                $this->data['sale']['discountAuthorization'] = $value === null ? null : [
                    'type' => $value, 'value' => $value === 'percent' ? (int) $values['discount_value'] : $values['discount_value'],
                    'authorized_by' => $values['discount_authorized_by'], 'authorized_at' => $values['discount_authorized_at']->toISOString(),
                ];
            } elseif (! in_array($field, ['discount_value', 'discount_authorized_by', 'discount_authorized_at'], true)) {
                throw new \LogicException('La corrección no permite cambiar identidad o estado financiero.');
            }
        }

        return $this->findSale($id);
    }

    public function hasConfirmedPayments(string $saleId): bool
    {
        $this->findSale($saleId);

        return false;
    }

    public function createItem(string $saleId, array $values): SaleItemSnapshot
    {
        $this->findSale($saleId);
        $item = new SaleItemSnapshot(strtolower((string) Str::ulid()), $saleId, $values['product_id'], $values['product_name'],
            $values['category_id'], $values['category_name'], $values['unit_price'], $values['quantity'], $values['note'],
            $values['line_total'], $values['position'], null, null, null);
        $this->data['items'][] = get_object_vars($item);

        return $item;
    }

    public function updateItem(string $saleId, string $itemId, array $values): void
    {
        $this->findSale($saleId);
        foreach ($this->data['items'] as &$item) {
            if ($item['id'] !== $itemId || $item['removedAt'] !== null) {
                continue;
            }
            foreach ($values as $field => $value) {
                if (! isset(self::ITEM_FIELDS[$field])) {
                    throw new \LogicException('No se permite alterar la instantánea histórica.');
                }
                $item[self::ITEM_FIELDS[$field]] = $field === 'removed_at' ? $value->toISOString() : $value;
            }

            return;
        }
        throw new ModelNotFoundException;
    }

    public function requireApproved(string $actorId, string $saleId, string $itemId, string $reasonCode): void
    {
        $this->findSale($saleId);
        $reason = $this->reasons->approved($actorId, 'sale_item_removal', $reasonCode);
        unset($reason['revision']);
        foreach ($this->data['items'] as &$item) {
            if ($item['id'] === $itemId && $item['removedAt'] === null) {
                $item['reasonSnapshot'] = $reason;

                return;
            }
        }
        throw new ModelNotFoundException;
    }

    public function createSale(array $values): SaleSnapshot
    {
        throw new \LogicException('Unsupported draft operation.');
    }

    public function sales(SaleQuery $query): SalesPage
    {
        throw new \LogicException('Unsupported draft operation.');
    }

    public function findTable(string $id, bool $lock = false): DiningTableSnapshot
    {
        throw new \LogicException('Unsupported draft operation.');
    }

    public function createTable(array $values): DiningTableSnapshot
    {
        throw new \LogicException('Unsupported draft operation.');
    }

    public function updateTable(string $id, array $values): DiningTableSnapshot
    {
        throw new \LogicException('Unsupported draft operation.');
    }

    public function tables(TableQuery $query): SalesPage
    {
        throw new \LogicException('Unsupported draft operation.');
    }
}
