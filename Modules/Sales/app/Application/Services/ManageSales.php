<?php

namespace Modules\Sales\Application\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Catalog\Contracts\CatalogReader;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\Events\SaleChanged;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\QuantityLimits;
use Modules\Sales\Contracts\SaleItemSnapshot;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\SalesPage;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Domain\Amount;
use Modules\Sales\Domain\SaleLifecycle;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;
use Throwable;

final class ManageSales implements SalesOperations
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly IdentityAccess $identities,
        private readonly SalesPolicy $policy,
        private readonly CatalogReader $catalog,
        private readonly SalesStore $store,
        private readonly ActiveCashSession $cash,
        private readonly QuantityLimits $quantities,
        private readonly LineRemovalApproval $removals,
    ) {}

    public function open(string $actorId, OpenSale $input): SaleSnapshot
    {
        return $this->write($actorId, 'sales.create', function () use ($actorId, $input): SaleSnapshot {
            $session = SalesText::id($this->cash->requireActive($actorId));
            $tableId = $input->tableId === null ? null : SalesText::id($input->tableId);
            if (($input->type === SaleType::Table) !== ($tableId !== null)) {
                throw new SalesConflict('Una venta de mesa requiere mesa; una de mostrador no admite mesa.');
            }
            if ($tableId !== null) {
                $table = $this->store->findTable($tableId, lock: true);
                if (! $table->isActive || $table->isOccupied) {
                    throw new SalesConflict('La mesa debe estar activa y libre.');
                }
            }
            $sale = $this->store->createSale(['type' => $input->type->value, 'status' => SaleState::Open->value,
                'table_id' => $tableId, 'created_by' => $actorId, 'cash_session_id' => $session,
                'note' => SalesText::normalize($input->note, 150)]);
            $this->audit($actorId, $sale->sale->id, 'opened');

            return $sale;
        });
    }

    public function sale(string $actorId, string $saleId): SaleSnapshot
    {
        $this->authorize($actorId, 'sales.view');

        return $this->store->findSale(SalesText::id($saleId));
    }

    public function sales(string $actorId, ?SaleQuery $query = null): SalesPage
    {
        $this->authorize($actorId, 'sales.view');

        return $this->store->sales($query ?? new SaleQuery);
    }

    public function addProduct(string $actorId, string $saleId, AddProduct $input): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, 'item_added', function (SaleSnapshot $sale) use ($actorId, $input): bool {
            $product = $this->catalog->forSale($actorId, SalesText::id($input->productId));
            $maximum = $this->quantities->maximumFor($product->productId);
            $quantity = SalesText::quantity($input->quantity, $maximum);
            $note = SalesText::normalize($input->note, 100);
            foreach ($sale->items as $item) {
                if ($item->removedAt === null && [$item->productId, $item->name, $item->categoryId, $item->categoryName, $item->unitPrice, $item->note]
                    === [$product->productId, $product->name, $product->categoryId, $product->categoryName, $product->price, $note]) {
                    $quantity = SalesText::quantity($item->quantity + $quantity, $maximum);
                    $this->store->updateItem($sale->sale->id, $item->id, ['quantity' => $quantity, 'line_total' => Amount::from($item->unitPrice)->multiply($quantity)->value]);

                    return true;
                }
            }
            $position = $sale->items === [] ? 1 : max(array_column($sale->items, 'position')) + 1;
            if ($position > 2147483647) {
                throw new SalesConflict('La venta superó el límite de posiciones.');
            }
            $this->store->createItem($sale->sale->id, ['product_id' => $product->productId, 'product_name' => $product->name,
                'category_id' => $product->categoryId, 'category_name' => $product->categoryName, 'unit_price' => $product->price,
                'quantity' => $quantity, 'note' => $note, 'line_total' => Amount::from($product->price)->multiply($quantity)->value, 'position' => $position]);

            return true;
        });
    }

    public function changeQuantity(string $actorId, string $saleId, string $itemId, mixed $quantity, ?string $removalReasonCode = null): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, $quantity === 0 ? 'item_removed' : 'quantity_changed', function (SaleSnapshot $sale) use ($actorId, $itemId, $quantity, $removalReasonCode): bool {
            $item = $this->item($sale, $itemId);
            if ($quantity === 0) {
                $this->removeLine($actorId, $sale, $item, $removalReasonCode ?? '');

                return true;
            }
            $quantity = SalesText::quantity($quantity, $this->quantities->maximumFor($item->productId));
            if ($quantity === $item->quantity) {
                return false;
            }
            if ($quantity > $item->quantity) {
                $product = $this->catalog->forSale($actorId, $item->productId);
                if ([$item->name, $item->categoryId, $item->categoryName, $item->unitPrice]
                    !== [$product->name, $product->categoryId, $product->categoryName, $product->price]) {
                    throw new SalesConflict('El producto cambió; incorpora las unidades nuevas con la instantánea vigente.');
                }
            }
            // Las disminuciones conservan la instantánea aunque el producto ya no esté disponible.
            $this->store->updateItem($sale->sale->id, $item->id, ['quantity' => $quantity, 'line_total' => Amount::from($item->unitPrice)->multiply($quantity)->value]);

            return true;
        });
    }

    public function editItemNote(string $actorId, string $saleId, string $itemId, ?string $note): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, 'item_note_updated', function (SaleSnapshot $sale) use ($itemId, $note): bool {
            $item = $this->item($sale, $itemId);
            $note = SalesText::normalize($note, 100);
            if ($note === $item->note) {
                return false;
            }
            $this->store->updateItem($sale->sale->id, $item->id, ['note' => $note]);

            return true;
        });
    }

    public function editNote(string $actorId, string $saleId, ?string $note): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, 'note_updated', function (SaleSnapshot $sale) use ($note): bool {
            $note = SalesText::normalize($note, 150);
            if ($note === $sale->sale->note) {
                return false;
            }
            $this->store->updateSale($sale->sale->id, ['note' => $note]);

            return true;
        });
    }

    public function removeItem(string $actorId, string $saleId, string $itemId, string $reasonCode): SaleSnapshot
    {
        return $this->changeQuantity($actorId, $saleId, $itemId, 0, $reasonCode);
    }

    public function pause(string $actorId, string $saleId): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, 'paused', function (SaleSnapshot $sale): bool {
            SaleLifecycle::pause($sale->sale->type, $sale->sale->state);
            $this->store->updateSale($sale->sale->id, ['status' => SaleState::Paused->value]);

            return true;
        }, editable: false);
    }

    public function resume(string $actorId, string $saleId): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, 'resumed', function (SaleSnapshot $sale): bool {
            SaleLifecycle::resume($sale->sale->type, $sale->sale->state);
            $this->store->updateSale($sale->sale->id, ['status' => SaleState::Open->value]);

            return true;
        }, editable: false);
    }

    public function cancel(string $actorId, string $saleId, string $reason): SaleSnapshot
    {
        return $this->mutate($actorId, $saleId, 'cancelled', function (SaleSnapshot $sale) use ($actorId, $reason): bool {
            SaleLifecycle::cancel($sale->sale->state);
            $this->store->updateSale($sale->sale->id, ['status' => SaleState::Cancelled->value, 'cancelled_at' => now()->utc(),
                'cancelled_by' => $actorId, 'cancellation_reason' => SalesText::normalize($reason, 150, true)]);

            return true;
        }, permission: 'sales.cancel', editable: false);
    }

    public function createTable(string $actorId, string $name, int $displayOrder = 0): DiningTableSnapshot
    {
        return $this->write($actorId, 'settings.update', function () use ($actorId, $name, $displayOrder): DiningTableSnapshot {
            $table = $this->store->createTable($this->tableValues($name, true, $displayOrder));
            event(new SecurityEvent('sales.table.created', actorId: $actorId, recordId: $table->id));

            return $table;
        });
    }

    public function updateTable(string $actorId, string $id, string $name, bool $isActive, int $displayOrder = 0): DiningTableSnapshot
    {
        return $this->write($actorId, 'settings.update', function () use ($actorId, $id, $name, $isActive, $displayOrder): DiningTableSnapshot {
            $before = $this->store->findTable(SalesText::id($id), lock: true);
            if ($before->isOccupied && ! $isActive) {
                throw new SalesConflict('La mesa ocupada debe conservarse activa hasta liberar su venta.');
            }
            $values = $this->tableValues($name, $isActive, $displayOrder);
            $table = $this->store->updateTable($before->id, $values);
            if ([$before->name, $before->isActive, $before->displayOrder] !== [$table->name, $table->isActive, $table->displayOrder]) {
                event(new SecurityEvent('sales.table.updated', actorId: $actorId, recordId: $table->id));
            }

            return $table;
        });
    }

    public function table(string $actorId, string $id): DiningTableSnapshot
    {
        $this->authorize($actorId, 'sales.view');

        return $this->store->findTable(SalesText::id($id));
    }

    public function tables(string $actorId, ?TableQuery $query = null): SalesPage
    {
        $this->authorize($actorId, 'sales.view');

        return $this->store->tables($query ?? new TableQuery);
    }

    private function tableValues(string $name, bool $active, int $order): array
    {
        if ($order < 0) {
            throw new InvalidArgumentException('La prioridad no puede ser negativa.');
        }

        return ['name' => SalesText::normalize($name, 150, true), 'is_active' => $active, 'display_order' => $order];
    }

    private function removeLine(string $actorId, SaleSnapshot $sale, SaleItemSnapshot $item, string $reasonCode): void
    {
        $this->authorize($actorId, 'sales.special.authorize');
        if (preg_match('/\A[A-Z0-9][A-Z0-9_:-]{0,63}\z/', $reasonCode) !== 1) {
            throw new SalesConflict('Selecciona un código de motivo autorizado para retirar la línea.');
        }
        $this->removals->requireApproved($actorId, $sale->sale->id, $item->id, $reasonCode);
        $this->store->updateItem($sale->sale->id, $item->id, ['removed_at' => now()->utc(), 'removed_by' => $actorId, 'removal_reason_code' => $reasonCode]);
    }

    private function item(SaleSnapshot $sale, string $itemId): SaleItemSnapshot
    {
        $itemId = SalesText::id($itemId);
        foreach ($sale->items as $item) {
            if ($item->id === $itemId && $item->removedAt === null) {
                return $item;
            }
        }
        throw new ModelNotFoundException('Línea no disponible en esta venta.');
    }

    /** @param Closure(SaleSnapshot): bool $change */
    private function mutate(string $actorId, string $saleId, string $operation, Closure $change, string $permission = 'sales.open.update', bool $editable = true): SaleSnapshot
    {
        return $this->write($actorId, $permission, function () use ($actorId, $saleId, $operation, $change, $editable): SaleSnapshot {
            $sale = $this->store->findSale(SalesText::id($saleId), lock: true);
            $session = SalesText::id($this->cash->requireActive($actorId, $sale->sale->cashSessionId));
            if ($session !== $sale->sale->cashSessionId) {
                throw new SalesConflict('La sesión de caja original ya no está disponible.');
            }
            if ($editable) {
                SaleLifecycle::editable($sale->sale->state);
            }
            if (! $change($sale)) {
                return $sale;
            }
            $current = $this->store->findSale($sale->sale->id);
            $subtotal = Amount::from('0.0000');
            foreach ($current->items as $item) {
                if ($item->removedAt === null) {
                    $subtotal = $subtotal->add(Amount::from($item->unitPrice)->multiply($item->quantity));
                }
            }
            $total = $subtotal->subtract(Amount::from($current->sale->discount));
            $result = $this->store->updateSale($sale->sale->id, ['subtotal' => $subtotal->value, 'total' => $total->value]);
            $this->audit($actorId, $sale->sale->id, $operation);

            return $result;
        });
    }

    private function authorize(string $actorId, string $permission): void
    {
        $actor = $this->identities->inspect(SalesText::id($actorId));
        if (! $actor || ! $this->policy->allows($actor, $permission)) {
            throw new AuthorizationException('No tienes permiso para esta operación de ventas.');
        }
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function write(string $actorId, string $permission, Closure $operation): mixed
    {
        try {
            return $this->transactions->run(function () use ($actorId, $permission, $operation): mixed {
                $this->identities->lock();
                $this->authorize($actorId, $permission);

                return $operation();
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['sale' => $exception->getMessage()]);
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [2601, 2627], true)
                && (str_contains($exception->getSql(), 'sales_tables') || str_contains($exception->getSql(), 'sales_sales'))) {
                throw new SalesConflict('La mesa ya existe o tiene una venta activa; consulta nuevamente.');
            }
            throw $exception;
        }
    }

    private function audit(string $actorId, string $saleId, string $operation): void
    {
        event(new SecurityEvent('sales.'.$operation, actorId: $actorId, recordId: $saleId));
        $this->transactions->afterCommit(function () use ($actorId, $saleId, $operation): void {
            try {
                event(new SaleChanged($saleId, $actorId, $operation));
            } catch (Throwable $exception) {
                // Fallo secundario observable; nunca presenta como revertida una venta confirmada.
                try {
                    Log::error('Falló un consumidor secundario de Sales.', ['sale_id' => $saleId, 'operation' => $operation, 'exception_type' => $exception::class]);
                } catch (Throwable) {
                    // Tampoco una caída del logger convierte un commit confirmado en un fallo financiero.
                }
            }
        });
    }
}
