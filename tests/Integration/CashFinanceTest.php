<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Application\Services\ManagePaymentMethods;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Cash\Infrastructure\Models\CashMovement;
use Modules\Cash\Infrastructure\Models\PaymentMethod;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\FinanceSqlServerTestCase;

class CashFinanceTest extends FinanceSqlServerTestCase
{
    public function test_maximum_overflow_and_exact_zero_balance_are_protected(): void
    {
        $ops = app(CashMovements::class);
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_in', '999999999999999.9999', 'Desbordamiento', (string) Str::uuid()), OperationRejected::class);
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
        $ops->record($this->actor, $this->sessionId, 'cash_out', '100', 'Retiro total autorizado', (string) Str::uuid());
        $this->assertSame('0.0000', $this->funds()['expected_cash']);
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_out', '0.0001', 'Sin saldo', (string) Str::uuid()), OperationRejected::class);
        $this->assertSame(1, $this->database->table('cash_movements')->count());
    }

    private function rejection(callable $operation, string $class): void
    {
        try {
            $operation();
            $this->fail('Debió rechazar la operación.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }

    public function test_methods_have_stable_identity_and_provision_does_not_reactivate(): void
    {
        $service = app(ManagePaymentMethods::class);
        $initial = $service->provision();
        $this->assertSame(['cash', 'card', 'transfer', 'other'], array_column($initial, 'code'));
        $this->assertEquals($initial, $service->provision());
        $methods = app(PaymentMethods::class);
        $methods->setActive($this->actor, $initial[1]->id, false);
        $this->assertCount(3, $methods->available($this->actor));
        $this->assertSame($initial[1]->id, $service->provision()[1]->id);
        $this->assertFalse($service->provision()[1]->isActive);
        $model = PaymentMethod::query()->findOrFail($initial[1]->id);
        $this->rejection(fn () => $model->delete(), \LogicException::class);
        $model->setAttribute('code', 'other');
        $this->rejection(fn () => $model->save(), \LogicException::class);
        $this->assertSame(4, $this->database->table('cash_payment_methods')->count());
    }

    public function test_movements_balance_replay_and_closed_history_are_exact(): void
    {
        $ops = app(CashMovements::class);
        $key = (string) Str::uuid();
        $a = $ops->record($this->actor, $this->sessionId, 'cash_in', '25.5', '  Reposición   autorizada ', $key);
        $b = $ops->record($this->actor, strtoupper($this->sessionId), 'cash_in', '25.5000', 'Reposición autorizada', $key);
        $this->assertEquals($a, $b);
        $this->assertSame('125.5000', $this->funds()['expected_cash']);
        $out = $ops->record($this->actor, $this->sessionId, 'cash_out', '0.0001', 'Retiro autorizado', (string) Str::uuid());
        $this->assertSame('125.4999', $this->funds()['expected_cash']);
        $this->assertSame('100.0000', $this->funds()['opening_fund']);
        $this->assertSame('0.0000', $this->funds()['confirmed_sales_cash']);
        $this->assertSame(2, $this->database->table('cash_movements')->count());
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'cash.movement.recorded')->count());
        $audit = json_decode($this->database->table('audit_security_events')->where('record_id', $out->id)->value('change_data'), true);
        $this->assertSame(['cash_session_id' => $this->sessionId, 'movement_type' => 'cash_out', 'amount' => '0.0001'], $audit);
        $this->closeFixture();
        $this->assertEquals($a, $ops->record($this->actor, $this->sessionId, 'cash_in', '25.5', 'Reposición autorizada', $key));
        $this->assertSame('125.4999', $ops->balance($this->actor, $this->sessionId)['expected_cash']);
        $this->assertNull($this->funds());
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_in', '1', 'Caja cerrada', (string) Str::uuid()), OperationRejected::class);
        $model = CashMovement::query()->findOrFail($out->id);
        $this->rejection(fn () => $model->delete(), \LogicException::class);
        $model->setAttribute('amount', '1.0000');
        $this->rejection(fn () => $model->save(), \LogicException::class);
    }

    public function test_insufficient_balance_key_conflict_and_invalid_inputs_have_no_effect(): void
    {
        $ops = app(CashMovements::class);
        $key = (string) Str::uuid();
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_out', '100.0001', 'Saldo insuficiente', $key), OperationRejected::class);
        $this->assertSame('failed', $this->database->table('idempotency_records')->value('state'));
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_out', '1', 'Datos distintos', $key), OperationRejected::class);
        foreach ([['cash_in', '0', 'Concepto'], ['cash_in', '-1', 'Concepto'], ['cash_in', 1, 'Concepto'], ['cash_in', '1.00001', 'Concepto'], ['cash_in', '1', ' '], ['cash_in', '1', '<script>'], ['sale_income', '1', 'Concepto']] as [$type,$amount,$concept]) {
            $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, $type, $amount, $concept, (string) Str::uuid()), ValidationException::class);
        }
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
    }

    public function test_permissions_are_fresh_and_waiter_never_moves_money_or_replays(): void
    {
        $ops = app(CashMovements::class);
        $key = (string) Str::uuid();
        $ops->record($this->actor, $this->sessionId, 'cash_in', '5', 'Autorizado', $key);
        $this->database->table('role_has_permissions')->where('permission_id', $this->database->table('permissions')->where('name', 'cash.movements.create')->value('id'))->delete();
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_in', '5', 'Autorizado', $key), AuthorizationException::class);
        $this->assertSame(1, $this->database->table('cash_movements')->count());
        $waiter = app(UserAdministration::class)->create($this->actor, ['name' => 'Waiter', 'username' => 'finance.waiter', 'password' => 'WaiterInitial!123']);
        $this->rejection(fn () => $ops->record($waiter->id, $this->sessionId, 'cash_out', '1', 'No autorizado', (string) Str::uuid()), AuthorizationException::class);
    }

    public function test_audit_failure_rolls_back_both_movement_and_durable_receipt(): void
    {
        $throw = true;
        app('events')->listen(SecurityEvent::class, function (SecurityEvent $event) use (&$throw): void {
            if ($throw && $event->type === 'cash.movement.recorded') {
                throw new \RuntimeException('Fixture audit failure');
            }
        });
        $key = (string) Str::uuid();
        $ops = app(CashMovements::class);
        $this->rejection(fn () => $ops->record($this->actor, $this->sessionId, 'cash_in', '10', 'Atómico', $key), \RuntimeException::class);
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(0, $this->database->table('idempotency_records')->count());
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
        $throw = false;
        $ops->record($this->actor, $this->sessionId, 'cash_in', '10', 'Atómico', $key);
        $this->assertSame('110.0000', $this->funds()['expected_cash']);
    }
}
