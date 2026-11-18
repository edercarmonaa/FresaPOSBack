<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\CashClosings;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Infrastructure\Models\CashReconciliation;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Infrastructure\Persistence\SqlOperationStore;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReconciliationSqlServerTestCase;

class CashReconciliationTest extends ReconciliationSqlServerTestCase
{
    public static function outcomes(): array
    {
        return ['balanced' => ['100', '0.0000', 'balanced'], 'shortage' => ['50', '-50.0000', 'shortage'],
            'surplus' => ['123.4567', '23.4567', 'surplus'], 'zero' => ['0', '-100.0000', 'shortage']];
    }

    #[DataProvider('outcomes')]
    public function test_atomic_closing_preserves_signed_exact_count_and_audits(string $counted, string $difference, string $outcome): void
    {
        $result = $this->closeCount($counted);
        $this->assertSame($difference, $result['difference']);
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame('100.0000', $result['expected_cash']);
        $this->assertSame($this->actor, $result['closed_by']);
        $this->assertTrue(Str::isUlid($result['id']));
        $this->assertSame('closed', $this->database->table('cash_sessions')->value('status'));
        $this->assertNull(app(CashOperations::class)->current($this->actor));
        $this->assertSame(1, $this->database->table('cash_reconciliations')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.session.closed')->count());
        $this->assertSame($difference === '0.0000' ? 0 : 1, $this->database->table('audit_security_events')->where('event_type', 'cash.difference.detected')->count());
        $this->assertSame($result, app(CashClosings::class)->reconciliation($this->actor, $this->sessionId));
        $this->assertSame(0, $this->database->table('cash_movements')->count());
    }

    public static function invalidCounts(): array
    {
        return array_combine(['negative', 'float', 'integer', 'exponent', 'comma', 'precision', 'overflow', 'null', 'bool', 'leading_zero'],
            array_map(fn ($value) => [$value], ['-1', 1.25, 100, '1e2', '1,00', '1.00001', '1000000000000000', null, true, '01']));
    }

    #[DataProvider('invalidCounts')]
    public function test_invalid_count_never_confirms_or_reveals_a_result(mixed $value): void
    {
        try {
            app(CashClosings::class)->close($this->actor, $this->sessionId, $value, 'Conteo realizado', (string) Str::uuid());
            $this->fail('Invalid count accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('counted_cash', $e->errors());
        }
        $this->assertSame('open', $this->database->table('cash_sessions')->value('status'));
        $this->assertSame(0, $this->database->table('cash_reconciliations')->count());
    }

    public function test_blind_queries_and_observation_validation_do_not_offer_a_balance_oracle(): void
    {
        $closings = app(CashClosings::class);
        $pending = $closings->pending($this->actor, $this->sessionId);
        $this->assertSame(0, $pending['total']);
        $this->assertStringNotContainsString('expected', json_encode($pending));
        foreach (['0', '100', '101'] as $counted) {
            try {
                $closings->close($this->actor, $this->sessionId, $counted, null, (string) Str::uuid());
                $this->fail('Observation required independently of expected cash');
            } catch (ValidationException $e) {
                $this->assertSame(['observation' => ['Describe brevemente el conteo antes de confirmarlo.']], $e->errors());
            }
        }
        try {
            $closings->reconciliation($this->actor, $this->sessionId);
            $this->fail('Active result exposed');
        } catch (OperationRejected $e) {
            $this->assertSame(ApiCode::CONFLICT, $e->apiCode);
            $this->assertStringNotContainsString('100', json_encode($e->errors));
        }
    }

    public function test_mixed_payments_movements_change_and_historical_summary_are_not_double_counted(): void
    {
        $this->paid([$this->payment('cash', '80', '100'), $this->payment('card', '100')], true);
        $this->paid([$this->payment('transfer', '180')]);
        $movements = app(CashMovements::class);
        $movements->record($this->actor, $this->sessionId, 'cash_in', '30.1234', 'Ingreso autorizado', (string) Str::uuid());
        $movements->record($this->actor, $this->sessionId, 'cash_out', '10.0001', 'Salida autorizada', (string) Str::uuid());
        $result = $this->closeCount('200.1233');
        $this->assertSame('200.1233', $result['expected_cash']);
        $this->assertSame('80.0000', $result['confirmed_sales_cash']);
        $this->assertSame('360.0000', $result['total_sold']);
        $this->assertSame(2, $result['closed_sales']);
        $this->assertSame(['card' => '100.0000', 'cash' => '80.0000', 'transfer' => '180.0000'], array_column($result['payment_methods'], 'applied_amount', 'method_code'));
        $this->assertSame('0.0000', $result['difference']);
        $this->assertSame(2, $this->database->table('cash_movements')->count());
        $this->assertSame(0, $this->database->table('sales_tables')->join('sales_sales', 'sales_sales.table_id', '=', 'sales_tables.id')->whereIn('sales_sales.status', ['open', 'paused'])->count());
        $this->assertSame($result, app(CashClosings::class)->history($this->actor)['items'][0]);
    }

    public function test_pending_counter_paused_and_table_sales_block_closing_and_remain_untouched(): void
    {
        $open = $this->sale();
        $paused = $this->sale();
        app(SalesOperations::class)->pause($this->actor, $paused->sale->id);
        $table = $this->sale(true);
        $pending = app(CashClosings::class)->pending($this->actor, $this->sessionId);
        $this->assertSame(3, $pending['total']);
        $this->assertEqualsCanonicalizing([$open->sale->id, $paused->sale->id, $table->sale->id], array_column($pending['items'], 'id'));
        try {
            $this->closeCount();
            $this->fail('Pending sales accepted');
        } catch (OperationRejected $e) {
            $this->assertArrayHasKey('pending_sales', $e->errors);
        }
        $this->assertSame('open', $this->database->table('cash_sessions')->value('status'));
        $this->assertSame(0, $this->database->table('cash_reconciliations')->count());
        $this->assertSame(3, $this->database->table('sales_sales')->whereIn('status', ['open', 'paused'])->count());
    }

    public function test_replay_lost_response_conflict_and_new_opening_keep_original_history(): void
    {
        $key = (string) Str::uuid();
        $original = $this->closeCount('100', $key);
        $cash = app(CashOperations::class);
        $register = $cash->registers($this->actor)[0];
        $new = $cash->open($this->actor, $register->id, '0', 'new-independent-turn-1234');
        $this->assertNotSame($this->sessionId, $new->id);
        $this->assertSame($original, $this->closeCount('100.0000', $key));
        $this->assertSame('0.0000', $new->openingFund);
        $this->assertSame($new->id, $cash->current($this->actor)->id);
        try {
            $this->closeCount('101', $key);
            $this->fail('Different payload accepted');
        } catch (OperationRejected $e) {
            $this->assertSame(ApiCode::IDEMPOTENCY_KEY_CONFLICT, $e->apiCode);
        }
        try {
            $this->closeCount();
            $this->fail('Duplicate closing accepted');
        } catch (OperationRejected $e) {
            $this->assertSame(ApiCode::CONFLICT, $e->apiCode);
        }
        $result = app(CashClosings::class)->close($this->actor, $new->id, '0', 'Nuevo turno sin traslado', (string) Str::uuid());
        $this->assertSame('0.0000', $result['difference']);
        $this->assertSame('0.0000', app(CashMovements::class)->balance($this->actor, $new->id)['opening_fund']);
        $this->assertSame(2, $this->database->table('cash_reconciliations')->count());
        $this->assertSame(2, app(CashClosings::class)->history($this->actor, ['per_page' => 10])['total']);
        $this->assertSame(0, app(CashClosings::class)->history($this->actor, ['status' => 'open'])['total']);
        $this->assertSame(0, app(CashClosings::class)->history($this->actor, ['date' => '2020-01-01'])['total']);
    }

    public function test_audit_failure_rolls_back_reconciliation_session_and_durable_receipt_together(): void
    {
        $armed = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $event) use (&$armed): void {
            if ($armed && $event->type === 'cash.session.closed') {
                throw new \RuntimeException('Injected audit failure');
            }
        });
        $key = (string) Str::uuid();
        try {
            $this->closeCount('100', $key);
            $this->fail('Audit failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected audit failure', $e->getMessage());
        }
        $this->assertSame(0, $this->database->table('cash_reconciliations')->count());
        $this->assertSame(0, $this->database->table('idempotency_records')->where('operation', 'cash.session.close')->count());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'cash.reconciliation.confirmed')->count());
        $this->assertSame('open', $this->database->table('cash_sessions')->value('status'));
        $armed = false;
        $this->closeCount('100', $key);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.session.closed')->count());
    }

    public function test_permissions_and_active_identity_are_rechecked_before_replay_or_history(): void
    {
        $users = app(UserAdministration::class);
        $waiter = $users->create($this->actor, ['name' => 'Restricted fixture', 'username' => 'closing.waiter', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $waiter->id, 'waiter');
        app(IdentityAccess::class)->changePassword($waiter->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        foreach (['close', 'history'] as $action) {
            try {
                $action === 'close' ? app(CashClosings::class)->close($waiter->id, $this->sessionId, '100', 'Conteo', (string) Str::uuid()) : app(CashClosings::class)->history($waiter->id);
                $this->fail('Unauthorized finance exposed');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
        $key = (string) Str::uuid();
        $this->closeCount('100', $key);
        // Desactivación aislada de fixture: no se toca el administrador operativo.
        $this->database->table('users')->where('id', $this->actor)->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $this->closeCount('100', $key);
    }

    public function test_sql_guards_prevent_reopen_reconciliation_changes_and_post_close_financial_writes(): void
    {
        $sale = $this->sale();
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
        $this->closeCount('280');
        $attempts = [
            fn () => $this->database->table('cash_sessions')->where('id', $this->sessionId)->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]),
            fn () => $this->database->table('cash_sessions')->where('id', $this->sessionId)->delete(),
            fn () => $this->database->table('cash_reconciliations')->update(['observation' => 'Changed']),
            fn () => $this->database->table('cash_reconciliations')->delete(),
            fn () => $this->database->table('cash_movements')->insert(['id' => strtolower((string) Str::ulid()), 'cash_session_id' => $this->sessionId, 'type' => 'cash_in', 'amount' => '1', 'concept' => 'Unauthorized', 'created_by' => $this->actor, 'occurred_at' => now(), 'created_at' => now()]),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Immutable history changed');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [547, 51030, 51031, 51034]);
            }
        }
        try {
            CashReconciliation::query()->firstOrFail()->delete();
            $this->fail('Model delete allowed');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, $this->database->table('cash_reconciliations')->count());
        $this->assertSame('closed', $this->database->table('cash_sessions')->value('status'));
    }

    public function test_sql_requires_reconciliation_and_rejects_new_sales_and_prepared_payments_after_closing(): void
    {
        try {
            $this->database->table('cash_sessions')->where('id', $this->sessionId)->update(['status' => 'closed', 'closed_by' => $this->actor, 'closed_at' => now()]);
            $this->fail('Closing without reconciliation accepted');
        } catch (QueryException $e) {
            $this->assertSame(51032, (int) $e->errorInfo[1]);
        }
        $this->paid();
        $payment = (array) $this->database->table('sales_payments')->first();
        $payment['id'] = strtolower((string) Str::ulid());
        $payment['status'] = 'prepared';
        $payment['confirmed_at'] = null;
        $this->database->table('sales_payments')->insert($payment);
        $result = $this->closeCount('280');
        $this->assertSame('180.0000', $result['confirmed_sales_cash']);
        $this->assertSame(1, $result['payment_methods'][0]['payment_count']);
        $payment['id'] = strtolower((string) Str::ulid());
        try {
            $this->database->table('sales_payments')->insert($payment);
            $this->fail('Payment after close accepted');
        } catch (QueryException $e) {
            $this->assertSame(51037, (int) $e->errorInfo[1]);
        }
        $sale = (array) $this->database->table('sales_sales')->first();
        $sale['id'] = strtolower((string) Str::ulid());
        $sale['status'] = 'open';
        $sale['folio'] = $sale['closed_by'] = $sale['closed_at'] = null;
        try {
            $this->database->table('sales_sales')->insert($sale);
            $this->fail('Sale after close accepted');
        } catch (QueryException $e) {
            $this->assertSame(51035, (int) $e->errorInfo[1]);
        }
        $this->assertSame(0, (int) $this->database->selectOne("SELECT COUNT(*) AS n FROM sys.foreign_keys WHERE parent_object_id=OBJECT_ID('cash_reconciliations') AND (is_disabled=1 OR is_not_trusted=1 OR delete_referential_action<>0)")->n);
        $column = $this->database->selectOne("SELECT TYPE_NAME(user_type_id) AS type,max_length FROM sys.columns WHERE object_id=OBJECT_ID('cash_reconciliations') AND name='id'");
        $this->assertSame('nchar', $column->type);
        $this->assertSame(52, (int) $column->max_length);
    }

    public function test_history_paginates_in_sql_and_filters_without_recalculating_previous_turns(): void
    {
        $cash = app(CashOperations::class);
        $register = $cash->registers($this->actor)[0];
        $original = $this->closeCount();
        for ($i = 1; $i < 12; $i++) {
            $session = $cash->open($this->actor, $register->id, '0', 'pagination-turn-fixture-'.$i);
            app(CashClosings::class)->close($this->actor, $session->id, '0', 'Conteo de turno aislado', (string) Str::uuid());
        }
        $closings = app(CashClosings::class);
        $page = $closings->history($this->actor, ['page' => 1, 'per_page' => 10, 'register_id' => $register->id, 'opened_by' => $this->actor]);
        $this->assertSame(12, $page['total']);
        $this->assertCount(10, $page['items']);
        $this->assertCount(2, $closings->history($this->actor, ['page' => 2, 'per_page' => 10])['items']);
        $this->assertSame($original, $closings->reconciliation($this->actor, $original['cash_session_id']));
        $this->assertSame(0, $closings->history($this->actor, ['closed_by' => strtolower((string) Str::ulid())])['total']);
    }

    public function test_persisted_pending_intention_is_not_stolen_or_converted_into_a_second_closing(): void
    {
        $key = (string) Str::uuid();
        $intent = new OperationIntent(OperationIntent::context($this->actor), $key,
            'cash.session.close', 'POST', $this->sessionId, ['counted' => '100.0000', 'observation' => 'Conteo pendiente'], $this->actor);
        $this->database->transaction(fn () => app(SqlOperationStore::class)->pending($intent));
        try {
            $this->closeCount('100', $key, 'Conteo pendiente');
            $this->fail('Persisted pending intention stolen');
        } catch (OperationRejected $e) {
            $this->assertSame(ApiCode::OPERATION_IN_PROGRESS, $e->apiCode);
        }
        $this->assertSame(0, $this->database->table('cash_reconciliations')->count());
        $this->assertSame('open', $this->database->table('cash_sessions')->value('status'));
        $this->assertSame('pending', $this->database->table('idempotency_records')->value('state'));
    }
}
