<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashMovements;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DiscountArithmeticTest;
use Tests\Support\DiscountsSqlServerTestCase;

class SaleDiscountsTest extends DiscountsSqlServerTestCase
{
    public function test_sql_constraints_prevent_unattributed_discount_and_invalid_author_foreign_key(): void
    {
        $sale = $this->sale();
        foreach ([['discount_amount' => '1.0000', 'total' => '179.0000'],
            ['discount_type' => 'percent', 'discount_value' => '20.0000', 'discount_amount' => '36.0000', 'total' => '144.0000', 'discount_authorized_by' => strtolower((string) Str::ulid()), 'discount_authorized_at' => now()]] as $update) {
            try {
                $this->database->transaction(fn () => $this->database->table('sales_sales')->where('id', $sale->sale->id)->update($update));
                $this->fail('Invalid discount persisted');
            } catch (QueryException $e) {
                $this->assertSame(547, (int) $e->errorInfo[1]);
            }
        }
        $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
    }

    public function test_percentage_rounding_matches_sql_server_at_boundary_values(): void
    {
        foreach (DiscountArithmeticTest::amounts() as [$subtotal,$percentage,$expected]) {
            $sql = $this->database->selectOne('SELECT CONVERT(varchar(32),CAST(ROUND(CAST(? AS decimal(21,4))*CAST(? AS decimal(7,4))/100,4) AS decimal(19,4))) AS amount', [$subtotal, $percentage])->amount;
            $this->assertSame($expected, $sql);
            $this->assertSame($sql, DecimalAmount::from($subtotal)->percentage($percentage)->value);
        }
    }

    public function test_apply_replace_remove_preserves_one_discount_author_and_history_with_exact_replay(): void
    {
        $sale = $this->sale();
        $key = (string) Str::uuid();
        $proposal = new DiscountProposal('percent', 20);
        $first = $this->discount($sale, $proposal, $key);
        $this->assertSame('36.0000', $first->sale->discount);
        $this->assertSame('144.0000', $first->sale->total);
        $this->assertSame($this->actor, $first->sale->discountAuthorization['authorized_by']);
        $this->assertStringEndsWith('Z', $first->sale->discountAuthorization['authorized_at']);
        $this->assertEquals($first, $this->discount($sale, $proposal, $key));
        $fixed = $this->discount($first, new DiscountProposal('fixed', '75'));
        $this->assertSame('fixed', $fixed->sale->discountAuthorization['type']);
        $this->assertSame('75.0000', $fixed->sale->discountAuthorization['value']);
        $this->assertSame('105.0000', $fixed->sale->total);
        $removed = $this->discount($fixed, null);
        $this->assertNull($removed->sale->discountAuthorization);
        $this->assertSame('180.0000', $removed->sale->total);
        foreach (['applied', 'replaced', 'removed'] as $event) {
            $rows = $this->database->table('audit_security_events')->where('event_type', 'sales.discount.'.$event)->get();
            $this->assertCount(1, $rows);
            $data = json_decode($rows[0]->change_data, true);
            $this->assertArrayHasKey('discount_change', $data);
        }
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('cash_movements')->count());
    }

    public function test_percentage_recalculates_after_add_quantity_and_authorized_removal_without_changing_the_rate(): void
    {
        $sales = app(SalesOperations::class);
        $sale = $this->discount($this->sale(), new DiscountProposal('percent', 20));
        $two = $sales->changeQuantity($this->actor, $sale->sale->id, $sale->items[0]->id, 2);
        $this->assertSame('72.0000', $two->sale->discount);
        $this->assertSame('288.0000', $two->sale->total);
        $this->assertSame($sale->sale->discountAuthorization, $two->sale->discountAuthorization);
        $zero = $this->control($two, 'remove', 'QA_REMOVE');
        $this->assertSame('0.0000', $zero->sale->total);
        $this->assertSame(20, $zero->sale->discountAuthorization['value']);
        try {
            app(SaleCheckout::class)->confirm($this->actor, $zero->sale->id, [], (string) Str::uuid(), $this->revision($zero));
            $this->fail('Empty sale closed');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $added = $sales->addProduct($this->actor, $sale->sale->id, new AddProduct($this->productId, 1));
        $this->assertSame('36.0000', $added->sale->discount);
        $this->assertSame('144.0000', $added->sale->total);
    }

    public function test_fixed_discount_stays_fixed_and_invalidating_consumption_changes_rollback_atomically(): void
    {
        $sales = app(SalesOperations::class);
        $sale = $this->sale();
        $two = $sales->changeQuantity($this->actor, $sale->sale->id, $sale->items[0]->id, 2);
        $fixed = $this->discount($two, new DiscountProposal('fixed', '200'));
        try {
            $sales->changeQuantity($this->actor, $sale->sale->id, $sale->items[0]->id, 1);
            $this->fail('Invalid fixed discount retained');
        } catch (SalesConflict) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->control($fixed, 'remove', 'QA_REMOVE');
            $this->fail('Invalid removal committed');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $this->assertEquals($fixed, $sales->sale($this->actor, $sale->sale->id));
        $valid = $this->discount($fixed, new DiscountProposal('fixed', '75'));
        $changed = $sales->changeQuantity($this->actor, $sale->sale->id, $sale->items[0]->id, 1);
        $this->assertSame('75.0000', $changed->sale->discount);
        $this->assertSame('105.0000', $changed->sale->total);
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.item_removed')->count());
    }

    public static function zeroDiscounts(): array
    {
        return [['percent', 100], ['fixed', '180.0000']];
    }

    #[DataProvider('zeroDiscounts')]
    public function test_zero_total_checkout_issues_folio_without_payments_income_or_cancel_reason(string $type, mixed $value): void
    {
        $sale = $this->discount($this->sale(true), new DiscountProposal($type, $value));
        $key = (string) Str::uuid();
        $closed = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [], $key, $this->revision($sale));
        $this->assertSame('closed', $closed->sale->state->value);
        $this->assertSame('180.0000', $closed->sale->subtotal);
        $this->assertSame('180.0000', $closed->sale->discount);
        $this->assertSame('0.0000', $closed->sale->total);
        $this->assertMatchesRegularExpression('/\A[0-9]{12}\z/', $closed->sale->folio);
        $this->assertSame([], $closed->payments);
        $this->assertNull($closed->sale->reasonSnapshot);
        $this->assertEquals($sale->sale->discountAuthorization, $closed->sale->discountAuthorization);
        $this->assertSame('100.0000', app(CashMovements::class)->balance($this->actor)['expected_cash']);
        $this->assertEquals($closed, app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [], $key, $this->revision($sale)));
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
        $this->assertFalse(app(SalesOperations::class)->table($this->actor, $closed->sale->tableId)->isOccupied);
        $reconciliation = $this->closeCount();
        $this->assertSame('100.0000', $reconciliation['expected_cash'] ?? $this->database->table('cash_reconciliations')->value('expected_cash'));
        $immutable = $this->database->table('sales_sales')->where('id', $sale->sale->id)->first();
        try {
            $this->database->table('sales_sales')->where('id', $sale->sale->id)->update(['discount_authorized_at' => now()->addMinute()]);
            $this->fail('Closed authorization changed');
        } catch (QueryException $e) {
            // Ambas barreras rechazan la escritura; su orden no está fijado.
            $this->assertContains((int) $e->errorInfo[1], [51001, 51035]);
        }
        $this->assertEquals($immutable, $this->database->table('sales_sales')->where('id', $sale->sale->id)->first());
    }

    public function test_cashier_collects_supervisor_discount_and_replays_without_reevaluating_closed_authorization(): void
    {
        $users = app(UserAdministration::class);
        $actors = [];
        foreach (['supervisor', 'cashier'] as $role) {
            $user = $users->create($this->actor, ['name' => 'QA '.$role, 'username' => 'discount.collect.'.$role, 'password' => 'EmployeeInitial!123']);
            $users->assignRole($this->actor, $user->id, $role);
            app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
            $actors[$role] = $user->id;
        }
        $sale = $this->discount($this->sale(), new DiscountProposal('percent', 20), actor: $actors['supervisor']);
        $payments = [$this->payment('cash', '144', '150')];
        $key = (string) Str::uuid();
        $closed = app(SaleCheckout::class)->confirm($actors['cashier'], $sale->sale->id, $payments, $key, $this->revision($sale));
        $this->assertSame('closed', $closed->sale->state->value);
        $this->assertSame('144.0000', $closed->sale->total);
        $this->assertSame($actors['supervisor'], $closed->sale->discountAuthorization['authorized_by']);
        $users->removeRole($this->actor, $actors['supervisor'], 'supervisor');
        $this->assertEquals($closed, app(SaleCheckout::class)->confirm($actors['cashier'], $sale->sale->id, $payments, $key, $this->revision($sale)));
        $this->assertSame(1, $this->database->table('sales_payments')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
    }

    public function test_checkout_collects_only_discounted_total_with_mixed_payments_and_real_change(): void
    {
        $sale = $this->discount($this->sale(), new DiscountProposal('percent', 20));
        $payments = [$this->payment('cash', '44', '50'), $this->payment('card', '100', null)];
        $closed = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, $payments, (string) Str::uuid(), $this->revision($sale));
        $this->assertSame('144.0000', $closed->sale->total);
        $this->assertCount(2, $closed->payments);
        $cash = collect($closed->payments)->firstWhere('method_code', 'cash');
        $this->assertSame('6.0000', $cash['change_cash']);
        $this->assertSame('144.0000', app(CashMovements::class)->balance($this->actor)['expected_cash']);
    }

    public function test_no_confirmed_zero_payment_or_overpayment_is_accepted_for_free_sale(): void
    {
        $sale = $this->discount($this->sale(), new DiscountProposal('percent', 100));
        foreach ([$this->payment('cash', '0', '0'), $this->payment('cash', '1', '1')] as $payment) {
            try {
                app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$payment], (string) Str::uuid(), $this->revision($sale));
                $this->fail('Artificial payment accepted');
            } catch (OperationRejected $e) {
                $this->assertSame('CONFLICT', $e->apiCode->value);
            }
        }
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('sales_daily_sequences')->count());
    }

    public function test_revoked_authorization_requires_new_authorized_discount_before_checkout(): void
    {
        $users = app(UserAdministration::class);
        $author = $users->create($this->actor, ['name' => 'Discount author', 'username' => 'discount.author', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $author->id, 'supervisor');
        app(IdentityAccess::class)->changePassword($author->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $sale = $this->discount($this->sale(), new DiscountProposal('percent', 20), actor: $author->id);
        $users->removeRole($this->actor, $author->id, 'supervisor');
        try {
            app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment('cash', '144', '150')], (string) Str::uuid(), $this->revision($sale));
            $this->fail('Revoked author approved');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $fresh = $this->discount($sale, new DiscountProposal('percent', 20));
        $this->assertSame($this->actor, $fresh->sale->discountAuthorization['authorized_by']);
        $this->assertSame('closed', app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment('cash', '144', '150')], (string) Str::uuid(), $this->revision($fresh))->sale->state->value);
    }

    public function test_audit_failure_rolls_back_financial_fields_authorization_and_core_receipt(): void
    {
        $sale = $this->sale();
        $fail = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $event) use (&$fail): void {
            if ($fail && str_starts_with($event->type, 'sales.discount.')) {
                throw new \RuntimeException('Discount audit fixture failure');
            }
        });
        $key = (string) Str::uuid();
        try {
            $this->discount($sale, new DiscountProposal('percent', 20), $key);
            $this->fail('Audit failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Discount audit fixture failure', $e->getMessage());
        }
        $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
        $this->assertSame(0, $this->database->table('idempotency_records')->where('idempotency_key', $key)->count());
        $fail = false;
        $this->assertSame('144.0000', $this->discount($sale, new DiscountProposal('percent', 20), $key)->sale->total);
    }

    public function test_paused_and_cancelled_sales_cannot_change_discount_without_existing_editable_state(): void
    {
        $sale = $this->sale();
        $paused = app(SalesOperations::class)->pause($this->actor, $sale->sale->id);
        try {
            $this->discount($paused, new DiscountProposal('percent', 20));
            $this->fail('Paused discount changed');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $cancelled = $this->control($paused, 'cancel', 'QA_CANCEL');
        try {
            $this->discount($cancelled, new DiscountProposal('percent', 20));
            $this->fail('Cancelled discount changed');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'like', 'sales.discount.%')->count());
    }
}
