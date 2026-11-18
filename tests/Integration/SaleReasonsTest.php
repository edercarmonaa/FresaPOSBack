<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\CashMovements;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SalesOperations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReasonsSqlServerTestCase;

class SaleReasonsTest extends ReasonsSqlServerTestCase
{
    public function test_native_ulids_trusted_foreign_keys_and_cancelled_history_are_protected_by_sql(): void
    {
        $columns = $this->database->select("SELECT t.name AS type,c.max_length FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID('sales_operation_reasons') AND c.name='id'");
        $this->assertSame('nchar', $columns[0]->type);
        $this->assertSame(52, (int) $columns[0]->max_length);
        $foreignKeys = $this->database->select("SELECT is_disabled,is_not_trusted,delete_referential_action FROM sys.foreign_keys WHERE referenced_object_id=OBJECT_ID('sales_operation_reasons')");
        $this->assertCount(2, $foreignKeys);
        foreach ($foreignKeys as $key) {
            $this->assertSame(0, (int) $key->is_disabled);
            $this->assertSame(0, (int) $key->is_not_trusted);
            $this->assertSame(0, (int) $key->delete_referential_action);
        }
        $sale = $this->sale();
        try {
            $this->database->table('sales_items')->where('id', $sale->items[0]->id)->update(['removal_reason_id' => strtolower((string) Str::ulid()), 'removal_reason_snapshot' => '{}']);
            $this->fail('Orphan reason accepted');
        } catch (QueryException $e) {
            $this->assertSame(547, (int) $e->errorInfo[1]);
        }
        $cancelled = $this->control($sale, 'cancel', 'QA_CANCEL');
        try {
            $this->database->table('sales_items')->where('id', $sale->items[0]->id)->update(['note' => 'Tampered after cancellation']);
            $this->fail('Cancelled item history mutated');
        } catch (QueryException $e) {
            $this->assertSame(51041, (int) $e->errorInfo[1]);
        }
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = $this->database->table('sales_sales')->where('id', $sale->sale->id);
                $operation === 'update' ? $query->update(['note' => 'Tamper']) : $query->delete();
                $this->fail('Cancelled history mutated');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], $operation === 'delete' ? [51042, 547] : [51042]);
            }
        }
        $this->assertEquals($cancelled, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
    }

    public function test_cancelled_sale_cannot_remove_lines_or_be_cancelled_with_a_new_intention(): void
    {
        $sale = $this->control($this->sale(), 'cancel', 'QA_CANCEL');
        foreach (['cancel', 'remove'] as $action) {
            try {
                $this->control($sale, $action, $action === 'cancel' ? 'QA_CANCEL' : 'QA_REMOVE');
                $this->fail('Terminal sale mutated');
            } catch (OperationRejected $e) {
                $this->assertSame('CONFLICT', $e->apiCode->value);
            }
        }
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.cancelled')->count());
        $this->assertSame(0, $this->database->table('sales_items')->whereNotNull('removed_at')->count());
    }

    public function test_catalog_order_and_pagination_use_active_compatible_reasons_only(): void
    {
        $reasons = app(SaleOperationReasons::class);
        for ($i = 0; $i < 11; $i++) {
            $reasons->write($this->actor, 'create', ['code' => 'QA_PAGE_'.$i, 'name' => 'Page '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'operation' => 'sale_item_removal', 'display_order' => $i + 1], (string) Str::uuid());
        }
        $first = $reasons->search($this->actor, ['operation' => 'sale_item_removal', 'per_page' => 10]);
        $second = $reasons->search($this->actor, ['operation' => 'sale_item_removal', 'per_page' => 10, 'page' => 2]);
        $this->assertSame(12, $first['total']);
        $this->assertCount(10, $first['items']);
        $this->assertCount(2, $second['items']);
        $this->assertSame('QA_REMOVE', $first['items'][0]['code']);
        $this->assertSame('QA_PAGE_10', $second['items'][1]['code']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
    }

    public function test_catalog_normalization_uniqueness_immutable_identity_and_durable_administration(): void
    {
        $reasons = app(SaleOperationReasons::class);
        $key = (string) Str::uuid();
        $input = ['code' => 'QA_EXTRA', 'name' => '  Razón   especial ', 'description' => ' Texto de prueba ', 'operation' => 'sale_item_removal', 'display_order' => 3];
        $reason = $reasons->write($this->actor, 'create', $input, $key);
        $this->assertSame('Razón especial', $reason['name']);
        $this->assertSame('Texto de prueba', $reason['description']);
        $this->assertTrue(Str::isUlid($reason['id']));
        $this->assertSame($reason, $reasons->write($this->actor, 'create', $input, $key));
        foreach ([['code' => 'QA_EXTRA', 'name' => 'Different'], ['code' => 'QA_OTHER', 'name' => 'razon especial']] as $changes) {
            try {
                $reasons->write($this->actor, 'create', array_replace($input, $changes), (string) Str::uuid());
                $this->fail('Equivalent duplicate accepted');
            } catch (OperationRejected $e) {
                $this->assertSame('CONFLICT', $e->apiCode->value);
            }
        }
        foreach (['code', 'operation', 'id'] as $field) {
            try {
                $reasons->write($this->actor, 'update', ['name' => 'New', 'description' => null, 'display_order' => 0, $field => 'CHANGED'], (string) Str::uuid(), $reason['id'], '"'.$reason['revision'].'"');
                $this->fail('Identity change accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([['code' => 'CHANGED'], ['operation' => 'sale_cancellation']] as $change) {
            try {
                $this->database->table('sales_operation_reasons')->where('id', $reason['id'])->update($change);
                $this->fail('SQL reason identity mutated');
            } catch (QueryException $e) {
                $this->assertSame(51040, (int) $e->errorInfo[1]);
            }
        }
        try {
            $this->database->table('sales_operation_reasons')->where('id', $reason['id'])->delete();
            $this->fail('Physical deletion accepted');
        } catch (QueryException $e) {
            $this->assertSame(51040, (int) $e->errorInfo[1]);
        }
        $this->assertSame(3, $this->database->table('sales_operation_reasons')->count());
        $this->assertSame(3, $this->database->table('audit_security_events')->where('event_type', 'sales.reason.created')->count());
    }

    public static function invalidReasons(): array
    {
        return [['code', 'lowercase'], ['code', 'A B'], ['code', str_repeat('A', 65)], ['operation', 'refund'], ['name', '  '], ['name', '<script>'], ['description', str_repeat('x', 501)], ['display_order', -1], ['display_order', '1.5']];
    }

    #[DataProvider('invalidReasons')]
    public function test_invalid_catalog_data_never_creates_an_approved_reason(string $field, mixed $value): void
    {
        $input = ['code' => 'QA_INVALID', 'name' => 'Fixture reason', 'operation' => 'sale_item_removal'];
        try {
            app(SaleOperationReasons::class)->write($this->actor, 'create', array_replace($input, [$field => $value]), (string) Str::uuid());
            $this->fail('Invalid catalog value accepted');
        } catch (ValidationException) {
            $this->assertSame(2, $this->database->table('sales_operation_reasons')->count());
        }
    }

    public function test_removal_preserves_original_line_reason_snapshot_and_closed_audit_without_cash_effects(): void
    {
        $sales = app(SalesOperations::class);
        $sale = $this->sale();
        $sale = $sales->changeQuantity($this->actor, $sale->sale->id, $sale->items[0]->id, 2);
        $balance = app(CashMovements::class)->balance($this->actor);
        $key = (string) Str::uuid();
        $removed = $this->control($sale, 'remove', 'QA_REMOVE', $key);
        $this->assertSame('0.0000', $removed->sale->subtotal);
        $this->assertSame('0.0000', $removed->sale->total);
        $this->assertSame(2, $removed->items[0]->quantity);
        $this->assertSame('360.0000', $removed->items[0]->lineTotal);
        $this->assertSame('Checkout product', $removed->items[0]->name);
        $this->assertSame($this->actor, $removed->items[0]->removedBy);
        $this->assertStringEndsWith('Z', $removed->items[0]->removedAt);
        $reason = app(SaleOperationReasons::class)->write($this->actor, 'update', ['name' => 'Updated fixture name', 'description' => null, 'display_order' => 20], (string) Str::uuid(), $this->removal['id'], '"'.$this->removal['revision'].'"');
        app(SaleOperationReasons::class)->write($this->actor, 'status', ['is_active' => false], (string) Str::uuid(), $reason['id'], '"'.$reason['revision'].'"');
        $this->assertEquals($removed, $this->control($sale, 'remove', 'QA_REMOVE', $key));
        $this->assertSame('Retiro exclusivo de fixture', $removed->items[0]->reasonSnapshot['name']);
        $this->assertEquals($removed, $sales->sale($this->actor, $sale->sale->id));
        $this->assertSame($balance, app(CashMovements::class)->balance($this->actor));
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $audit = $this->database->table('audit_security_events')->where('event_type', 'sales.item_removed')->sole();
        $data = json_decode($audit->change_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('QA_REMOVE', $data['reason']['code']);
        $this->assertSame(2, $data['item']['quantity']);
        $this->assertSame('360.0000', $data['item']['line_total']);
        $this->assertArrayNotHasKey('revision', $data['reason']);
        try {
            $this->database->table('sales_items')->where('id', $sale->items[0]->id)->update(['note' => 'Tampered']);
            $this->fail('Removed history changed');
        } catch (QueryException $e) {
            $this->assertSame(51041, (int) $e->errorInfo[1]);
        }
    }

    public static function cancellable(): array
    {
        return [['counter'], ['paused'], ['table']];
    }

    #[DataProvider('cancellable')]
    public function test_unpaid_cancellation_is_atomic_historical_idempotent_and_releases_table(string $type): void
    {
        $sales = app(SalesOperations::class);
        $sale = $this->sale($type === 'table');
        if ($type === 'paused') {
            $sale = $sales->pause($this->actor, $sale->sale->id);
        }
        $key = (string) Str::uuid();
        $cancelled = $this->control($sale, 'cancel', 'QA_CANCEL', $key);
        $this->assertSame('cancelled', $cancelled->sale->state->value);
        $this->assertEquals($sale->items, $cancelled->items);
        $this->assertSame('180.0000', $cancelled->sale->total);
        $this->assertNull($cancelled->sale->folio);
        $this->assertSame($this->cancellation['id'], $cancelled->sale->reasonSnapshot['id']);
        $this->assertSame('Cancelación exclusiva de fixture', $cancelled->sale->cancellationReason);
        $this->assertEquals($cancelled, $this->control($sale, 'cancel', 'QA_CANCEL', $key));
        if ($type === 'table') {
            $this->assertFalse($sales->table($this->actor, $sale->sale->tableId)->isOccupied);
        }
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.cancelled')->count());
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(0, $this->database->table('sales_daily_sequences')->count());
        $this->assertSame('100.0000', app(CashMovements::class)->balance($this->actor)['expected_cash']);
        $this->closeCount();
        $this->assertEquals($cancelled, $this->control($sale, 'cancel', 'QA_CANCEL', $key));
    }

    public static function rejectedControls(): array
    {
        return [['remove', 'QA_CANCEL'], ['cancel', 'QA_REMOVE'], ['remove', 'UNKNOWN'], ['cancel', 'UNKNOWN'], ['remove', 'inactive'], ['cancel', 'inactive']];
    }

    #[DataProvider('rejectedControls')]
    public function test_inactive_wrong_type_or_missing_reason_cannot_mutate_a_sale(string $action, string $code): void
    {
        $sale = $this->sale();
        if ($code === 'inactive') {
            $reason = $action === 'remove' ? $this->removal : $this->cancellation;
            app(SaleOperationReasons::class)->write($this->actor, 'status', ['is_active' => false], (string) Str::uuid(), $reason['id'], '"'.$reason['revision'].'"');
            $code = $reason['code'];
        }
        try {
            $this->control($sale, $action, $code);
            $this->fail('Unauthorized reason used');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
    }

    public function test_paid_sales_and_confirmed_payments_never_become_cancellation_or_refund(): void
    {
        $sale = $this->sale();
        $closed = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
        foreach (['cancel', 'remove'] as $action) {
            try {
                $this->control($closed, $action, $action === 'cancel' ? 'QA_CANCEL' : 'QA_REMOVE');
                $this->fail('Paid sale mutated');
            } catch (OperationRejected $e) {
                $this->assertSame('CONFLICT', $e->apiCode->value);
            }
        }
        $this->assertEquals($closed, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
        $this->assertSame('280.0000', app(CashMovements::class)->balance($this->actor)['expected_cash']);
        $this->assertSame(1, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.cancelled')->count());
    }

    public function test_audit_failure_rolls_back_reason_evidence_sale_and_core_receipt(): void
    {
        $sale = $this->sale();
        $fail = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $e) use (&$fail): void {
            if ($fail && in_array($e->type, ['sales.item_removed', 'sales.cancelled'], true)) {
                throw new \RuntimeException('Reason audit fixture failure');
            }
        });
        $key = (string) Str::uuid();
        foreach (['remove', 'cancel'] as $action) {
            try {
                $this->control($sale, $action, $action === 'remove' ? 'QA_REMOVE' : 'QA_CANCEL', $key);
                $this->fail('Audit failure ignored');
            } catch (\RuntimeException $e) {
                $this->assertSame('Reason audit fixture failure', $e->getMessage());
            }
            $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
            $this->assertSame(0, $this->database->table('idempotency_records')->where('idempotency_key', $key)->count());
        }
        $fail = false;
        $this->assertSame('cancelled', $this->control($sale, 'cancel', 'QA_CANCEL', $key)->sale->state->value);
    }
}
