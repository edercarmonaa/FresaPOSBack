<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\CashClosings;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\Events\SaleChanged;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CorrectionsSqlServerTestCase;
use Tests\Support\FinancialIntegrity;

class SaleCorrectionsTest extends CorrectionsSqlServerTestCase
{
    public function test_reopening_preserves_complete_financial_history_and_blocks_generic_writes_and_cash_closing(): void
    {
        $paid = $this->settledSale();
        $before = $this->database->table('sales_sales')->first();
        $payments = $this->database->table('sales_payments')->get()->all();
        $sequence = $this->database->table('sales_daily_sequences')->get()->all();
        $row = $this->reopen($paid, '  Revisión   autorizada  ');
        $this->assertSame('Revisión autorizada', $row['observation']);
        $this->assertSame('active', $row['status']);
        $this->assertSame($paid->sale->folio, $row['folio']);
        $this->assertSame($paid->sale->closedAt, $row['original']['closed_at']);
        $this->assertSame($this->actor, $row['reopened_by']);
        $this->assertStringEndsWith('Z', $row['reopened_at']);
        $this->assertEquals($before, $this->database->table('sales_sales')->first());
        $pending = app(CashClosings::class)->pending($this->actor, $this->sessionId);
        $this->assertSame(1, $pending['total']);
        $this->assertSame('correcting', $pending['items'][0]['status']);
        $this->assertSame($paid->sale->id, $pending['items'][0]['id']);
        $this->assertArrayNotHasKey('total', $pending['items'][0]);
        try {
            $this->closeCount();
            $this->fail('Cash closed with pending correction');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        foreach ([fn () => $this->database->table('sales_sales')->where('id', $paid->sale->id)->update(['note' => 'bypass']),
            fn () => $this->database->table('sales_items')->where('sale_id', $paid->sale->id)->update(['note' => 'bypass'])] as $write) {
            try {
                $this->database->transaction($write);
                $this->fail('Paid data modified outside correction commit');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [51001, 51002]);
            }
        }
        $this->assertEquals($payments, $this->database->table('sales_payments')->get()->all());
        $this->assertEquals($sequence, $this->database->table('sales_daily_sequences')->get()->all());
        $this->assertSame('280.0000', $this->funds()['expected_cash']);
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
    }

    public function test_notes_commit_once_with_one_summary_event_and_immutable_completed_history(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $count = $this->database->table('audit_security_events')->count();
        $row = $this->correction($row, 'edit', ['action' => 'note', 'note' => 'Nota corregida']);
        $row = $this->correction($row, 'edit', ['action' => 'item-note', 'item_id' => $paid->items[0]->id, 'note' => 'Sin azúcar']);
        $this->assertSame($count, $this->database->table('audit_security_events')->count());
        $this->assertNull(app(SalesOperations::class)->sale($this->actor, $paid->sale->id)->sale->note);
        $key = (string) Str::uuid();
        $confirmed = $this->correction($row, 'confirm', ['payments' => []], $key);
        $this->assertSame($confirmed, $this->correction($row, 'confirm', ['payments' => []], $key));
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame('Nota corregida', $confirmed['sale']['note']);
        $this->assertSame($row['original']['payments'], $confirmed['sale']['payments']);
        foreach (['id', 'folio', 'created_at', 'closed_at', 'closed_by', 'table_id', 'created_by'] as $field) {
            $this->assertSame($row['original'][$field], $confirmed['sale'][$field]);
        }
        $this->assertSame($count + 1, $this->database->table('audit_security_events')->count());
        $event = $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->first();
        $data = json_decode($event->change_data, true);
        $this->assertSame($row['id'], $data['correction']['correctionId']);
        $this->assertSame(['items', 'note'], $data['correction']['changes']);
        $this->assertSame($this->actor, $event->actor_id);
        $this->assertSame('0.0000', $data['correction']['additionalPaid']);
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(0, app(CashClosings::class)->pending($this->actor, $this->sessionId)['total']);
        try {
            $this->database->table('sales_corrections')->where('id', $row['id'])->update(['observation' => 'Changed']);
            $this->fail('History changed');
        } catch (QueryException $e) {
            $this->assertSame(51050, (int) $e->errorInfo[1]);
        }
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
    }

    public function test_no_effective_changes_finish_without_summary_or_payment_or_consumed_sequence(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'note', 'note' => '   ']);
        $row = $this->correction($row, 'edit', ['action' => 'item-note', 'item_id' => $paid->items[0]->id, 'note' => ' Historical  note ']);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 1]);
        $result = $this->correction($row, 'confirm', ['payments' => []]);
        $this->assertSame('unchanged', $result['status']);
        $this->assertSame([], $result['changes']);
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
        $this->assertSame(1, $this->database->table('sales_payments')->count());
        $this->assertSame(1, (int) $this->database->table('sales_daily_sequences')->value('last_value'));
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
    }

    public function test_increment_uses_existing_allocation_with_mixed_additional_payments_and_preserves_original_rows(): void
    {
        $paid = $this->settledSale();
        $original = $this->database->table('sales_payments')->get()->all();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
        $this->assertSame('280.0000', $this->funds()['expected_cash']);
        $this->assertSame('180.0000', $row['financial']['difference']);
        $confirmed = $this->correction($row, 'confirm', ['payments' => [$this->payment('cash', '80', '100'), $this->payment('card', '100', null)]]);
        $this->assertSame('360.0000', $confirmed['sale']['total']);
        $this->assertSame('180.0000', $confirmed['financial']['additional_paid']);
        $this->assertCount(3, $confirmed['sale']['payments']);
        $this->assertSame('360.0000', $this->funds()['expected_cash']);
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertEquals($original, $this->database->table('sales_payments')->where('id', $original[0]->id)->get()->all());
        $this->assertSame('20.0000', collect($confirmed['sale']['payments'])->firstWhere('applied_amount', '80.0000')['change_cash']);
        $this->assertSame(1, (int) $this->database->table('sales_daily_sequences')->value('last_value'));
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
        $closing = $this->closeCount('360');
        $this->assertSame('0.0000', $closing['difference']);
        $this->assertSame(1, $closing['closed_sales']);
        $this->assertSame('360.0000', $closing['total_sold']);
    }

    public function test_refund_is_blocked_and_discard_preserves_paid_sale_and_releases_cash_pending(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'discount', 'type' => 'percent', 'value' => 20]);
        $this->assertTrue($row['financial']['requires_refund']);
        $this->assertSame('-36.0000', $row['financial']['difference']);
        try {
            $this->correction($row, 'confirm', ['payments' => []]);
            $this->fail('Refund allowed');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
            $this->assertStringContainsString('devolución', $e->errors['correction'][0]);
        }
        $discarded = $this->correction($row, 'discard');
        $this->assertSame('discarded', $discarded['status']);
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        $this->assertSame('280.0000', $this->funds()['expected_cash']);
        $this->assertSame(0, app(CashClosings::class)->pending($this->actor, $this->sessionId)['total']);
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
    }

    public function test_discount_recalculation_and_authorized_removal_keep_old_payments_and_validate_reason(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1, 'note' => 'Otra línea']);
        $row = $this->correction($row, 'edit', ['action' => 'discount', 'type' => 'percent', 'value' => 50]);
        $this->assertSame('180.0000', $row['proposed']['total']);
        $result = $this->correction($row, 'confirm', ['payments' => []]);
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame($paid->payments, $result['sale']['payments']);
        $next = $this->reopen(app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        $next = $this->correction($next, 'edit', ['action' => 'discount', 'type' => 'fixed', 'value' => '180']);
        $this->assertSame('180.0000', $next['proposed']['total']);
        $next = $this->correction($next, 'edit', ['action' => 'discount-remove']);
        $this->assertSame('360.0000', $next['proposed']['total']);
        $item = $next['proposed']['items'][1];
        try {
            $this->correction($next, 'edit', ['action' => 'remove', 'item_id' => $item['id'], 'reason_code' => 'UNKNOWN']);
            $this->fail('Invalid reason accepted');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $next = $this->correction($next, 'edit', ['action' => 'remove', 'item_id' => $item['id'], 'reason_code' => 'QA_REMOVE']);
        $result = $this->correction($next, 'confirm', ['payments' => []]);
        $this->assertSame('180.0000', $result['sale']['total']);
        $this->assertNotNull($result['sale']['items'][1]['removed_at']);
        $this->assertCount(1, $result['sale']['payments']);
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'like', 'sales.discount.%')->count());
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
    }

    public function test_zero_paid_sale_can_be_corrected_without_fictitious_payments(): void
    {
        $sale = $this->discount($this->sale(), new DiscountProposal('percent', 100));
        $paid = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [], (string) Str::uuid(), $this->revision($sale));
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
        $result = $this->correction($row, 'confirm', ['payments' => []]);
        $this->assertSame('0.0000', $result['sale']['total']);
        $this->assertSame([], $result['sale']['payments']);
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
        $this->assertSame($paid->sale->folio, $result['folio']);
    }

    public function test_fixed_discount_invalidated_by_product_change_rolls_back_only_draft_intent(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
        $row = $this->correction($row, 'edit', ['action' => 'discount', 'type' => 'fixed', 'value' => '200']);
        try {
            $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 1]);
            $this->fail('Invalid fixed discount accepted');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $this->assertSame($row, app(SaleCorrections::class)->current($this->actor, $paid->sale->id));
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
    }

    public function test_original_table_is_not_reoccupied_and_other_live_sale_remains_untouched(): void
    {
        $paid = $this->settledSale(true);
        $sales = app(SalesOperations::class);
        $other = $sales->open($this->actor, new OpenSale(SaleType::Table, $paid->sale->tableId));
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'note', 'note' => 'Mesa histórica']);
        $result = $this->correction($row, 'confirm', ['payments' => []]);
        $this->assertSame($paid->sale->tableId, $result['sale']['table_id']);
        $this->assertEquals($other, $sales->sale($this->actor, $other->sale->id));
        $this->assertSame($other->sale->id, $sales->table($this->actor, $paid->sale->tableId)->activeSaleId);
    }

    public function test_audit_failure_rolls_back_sale_payments_and_receipt_and_same_key_can_retry(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
        $fail = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $e) use (&$fail): void {
            if ($fail && $e->type === 'sales.correction.confirmed') {
                throw new \RuntimeException('Correction audit fixture');
            }
        });
        $key = (string) Str::uuid();
        $input = ['payments' => [$this->payment('cash', '180', '200')]];
        try {
            $this->correction($row, 'confirm', $input, $key);
            $this->fail('Audit failed but commit succeeded');
        } catch (\RuntimeException $e) {
            $this->assertSame('Correction audit fixture', $e->getMessage());
        }
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        $this->assertSame(1, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('idempotency_records')->where('idempotency_key', $key)->count());
        $this->assertNull($this->database->table('sales_corrections')->value('applying_transaction_id'));
        $fail = false;
        $result = $this->correction($row, 'confirm', $input, $key);
        $this->assertSame('360.0000', $result['sale']['total']);
        $this->assertSame(2, $this->database->table('sales_payments')->count());
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
    }

    public function test_reopen_replay_conflict_stale_revision_single_active_and_closed_cash_rejection(): void
    {
        $paid = $this->settledSale();
        $key = (string) Str::uuid();
        $row = $this->reopen($paid, null, $key);
        $this->assertSame($row, $this->reopen($paid, null, $key));
        foreach ([fn () => $this->reopen($paid, 'changed', $key), fn () => $this->reopen($paid), fn () => $this->correction($row, 'confirm', ['payments' => [$this->payment('cash', '1', '1')]])] as $attempt) {
            try {
                $attempt();
                $this->fail('Invalid financial intention accepted');
            } catch (OperationRejected $e) {
                $this->assertContains($e->apiCode->value, ['CONFLICT', 'IDEMPOTENCY_KEY_CONFLICT', 'VALIDATION_ERROR']);
            }
        }
        $this->assertSame(1, $this->database->table('sales_corrections')->count());
        $new = $this->correction($row, 'edit', ['action' => 'note', 'note' => 'New']);
        try {
            $this->correction($row, 'edit', ['action' => 'note', 'note' => 'Stale']);
            $this->fail('Stale update accepted');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $this->correction($new, 'discard');
        $this->closeCount();
        try {
            $this->reopen($paid);
            $this->fail('Closed Cash reopened');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $this->assertSame($row, $this->reopen($paid, null, $key));
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [101], [1.5], ['2'], [true], [null]];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_never_change_draft_or_confirmed_data(mixed $quantity): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $this->expectException(ValidationException::class);
        try {
            $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => $quantity]);
        } finally {
            $this->assertSame($row, app(SaleCorrections::class)->current($this->actor, $paid->sale->id));
            $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        }
    }

    public function test_immutable_prices_inactive_catalog_and_foreign_lines_are_rejected_but_notes_are_allowed(): void
    {
        $paid = $this->settledSale();
        $other = $this->sale();
        $row = $this->reopen($paid);
        $catalog = app(CatalogAdministration::class);
        $product = $catalog->product($this->actor, $this->productId);
        $catalog->updateProduct($this->actor, $product->id, ['name' => $product->name, 'category_id' => $product->categoryId, 'price' => '200.0000']);
        try {
            $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
            $this->fail('Historical price replaced');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        $row = $this->correction($row, 'edit', ['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1]);
        $this->assertSame('180.0000', $row['proposed']['items'][0]['unit_price']);
        $this->assertSame('200.0000', $row['proposed']['items'][1]['unit_price']);
        try {
            $this->correction($row, 'edit', ['action' => 'item-note', 'item_id' => $other->items[0]->id, 'note' => 'Foreign']);
            $this->fail('Foreign line changed');
        } catch (OperationRejected $e) {
            $this->assertSame('NOT_FOUND', $e->apiCode->value);
        }
        $this->assertEquals($other, app(SalesOperations::class)->sale($this->actor, $other->sale->id));
        $row = $this->correction($row, 'edit', ['action' => 'item-note', 'item_id' => $paid->items[0]->id, 'note' => 'Preserved']);
        $this->assertSame('Preserved', $row['proposed']['items'][0]['note']);
    }

    public static function invalidObservations(): array
    {
        return [[str_repeat('x', 151)], ['<img src=x onerror=bad>'], ["Control\0no permitido"], [123]];
    }

    #[DataProvider('invalidObservations')]
    public function test_reopen_observation_rejects_oversize_html_controls_and_non_text(mixed $observation): void
    {
        $paid = $this->settledSale();
        $this->expectException(ValidationException::class);
        try {
            app(SaleCorrections::class)->write($this->actor, $paid->sale->id, 'reopen', compact('observation'), (string) Str::uuid(), $this->revision($paid));
        } finally {
            $this->assertSame(0, $this->database->table('sales_corrections')->count());
            $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        }
    }

    public function test_inactive_product_is_not_added_or_increased_but_historical_note_can_be_corrected(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        app(CatalogAdministration::class)->setProductActive($this->actor, $this->productId, false);
        foreach ([['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1], ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]] as $input) {
            try {
                $this->correction($row, 'edit', $input);
                $this->fail('Inactive catalog consumed');
            } catch (OperationRejected $e) {
                $this->assertSame('CONFLICT', $e->apiCode->value);
            }
        }
        $row = $this->correction($row, 'edit', ['action' => 'item-note', 'item_id' => $paid->items[0]->id, 'note' => str_repeat('n', 100)]);
        $result = $this->correction($row, 'confirm', ['payments' => []]);
        $this->assertSame(str_repeat('n', 100), $result['sale']['items'][0]['note']);
        $this->assertSame('180.0000', $result['sale']['items'][0]['unit_price']);
    }

    public function test_quantity_limit_100_and_invalid_line_note_preserve_draft_on_rejection(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 100]);
        $this->assertSame('18000.0000', $row['proposed']['total']);
        foreach ([str_repeat('n', 101), '<script>bad</script>'] as $note) {
            try {
                $this->correction($row, 'edit', ['action' => 'item-note', 'item_id' => $paid->items[0]->id, 'note' => $note]);
                $this->fail('Invalid note');
            } catch (ValidationException) {
                $this->assertSame($row, app(SaleCorrections::class)->current($this->actor, $paid->sale->id));
            }
        }
        $this->correction($row, 'discard');
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
    }

    public function test_open_paused_and_cancelled_sales_never_enter_paid_correction_workflow(): void
    {
        $sales = app(SalesOperations::class);
        $open = $this->sale();
        $paused = $sales->pause($this->actor, $open->sale->id);
        $cancelled = $sales->cancel($this->actor, $open->sale->id, 'QA_CANCEL');
        foreach ([$open, $paused, $cancelled] as $sale) {
            try {
                $this->reopen($sale);
                $this->fail('Nonpaid sale reopened');
            } catch (OperationRejected $e) {
                $this->assertSame('CONFLICT', $e->apiCode->value);
            }
        }
        $this->assertSame(0, $this->database->table('sales_corrections')->count());
    }

    public function test_sql_application_window_still_forbids_repricing_changing_folio_and_deleting_financial_history(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        foreach ([
            fn () => $this->database->table('sales_items')->where('id', $paid->items[0]->id)->update(['unit_price' => '1', 'line_total' => '1']),
            fn () => $this->database->table('sales_sales')->where('id', $paid->sale->id)->update(['folio' => '202611170099']),
            fn () => $this->database->table('sales_items')->where('id', $paid->items[0]->id)->delete(),
            fn () => $this->database->table('sales_payments')->where('sale_id', $paid->sale->id)->delete(),
        ] as $attempt) {
            try {
                $this->database->transaction(function () use ($row, $attempt): void {
                    $tx = $this->database->selectOne('SELECT CURRENT_TRANSACTION_ID() AS id')->id;
                    $this->database->table('sales_corrections')->where('id', $row['id'])->update(['applying_transaction_id' => $tx]);
                    $attempt();
                });
                $this->fail('Immutable history changed inside application window');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [51001, 51002, 51003]);
            }
        }
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        $this->assertNull($this->database->table('sales_corrections')->value('applying_transaction_id'));
        try {
            $this->database->table('sales_corrections')->where('id', $row['id'])->update(['original_snapshot' => '{}']);
            $this->fail('Original snapshot changed');
        } catch (QueryException $e) {
            $this->assertSame(51050, (int) $e->errorInfo[1]);
        }
    }

    public function test_history_is_paginated_and_final_rows_cannot_be_deleted(): void
    {
        $paid = $this->settledSale();
        for ($i = 0; $i < 11; $i++) {
            $row = $this->reopen($paid, 'Revisión '.$i);
            $this->correction($row, 'discard');
        }
        $history = app(SaleCorrections::class);
        $first = $history->history($this->actor, $paid->sale->id);
        $second = $history->history($this->actor, $paid->sale->id, 2);
        $this->assertSame(11, $first['total']);
        $this->assertCount(10, $first['items']);
        $this->assertCount(1, $second['items']);
        $this->assertSame('discarded', $second['items'][0]['status']);
        try {
            $this->database->table('sales_corrections')->where('id', $row['id'])->delete();
            $this->fail('History deleted');
        } catch (QueryException $e) {
            $this->assertSame(51050, (int) $e->errorInfo[1]);
        }
        $this->assertSame(1, $this->database->table('sales_sales')->count());
        $this->assertSame(1, $this->database->table('sales_payments')->count());
    }

    public function test_secondary_event_failure_does_not_undo_confirmed_correction_or_duplicate_it_on_replay(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'note', 'note' => 'Commit conservado']);
        Event::listen(SaleChanged::class, fn () => throw new \RuntimeException('Secondary correction fixture'));
        $key = (string) Str::uuid();
        $result = $this->correction($row, 'confirm', ['payments' => []], $key);
        $this->assertSame('confirmed', $result['status']);
        $this->assertSame($result, $this->correction($row, 'confirm', ['payments' => []], $key));
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
    }

    public function test_discount_permission_is_revalidated_before_replay_and_confirmation_rechecks_original_author(): void
    {
        $users = app(UserAdministration::class);
        $permissions = ['catalog.products.view', 'sales.view', 'sales.open.update', 'sales.reopen', 'sales.special.authorize', 'sales.close', 'cash.view', 'sales.discounts.apply'];
        $users->createRole($this->actor, 'correction_discount_author', 'Autorizador de descuentos QA');
        $users->setRolePermissions($this->actor, 'correction_discount_author', $permissions);
        $user = $users->create($this->actor, ['name' => 'Discount correction QA', 'username' => 'correction.discount.author', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, 'correction_discount_author');
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
        $input = ['action' => 'discount', 'type' => 'percent', 'value' => 50];
        $key = (string) Str::uuid();
        $revision = '"'.$row['revision'].'"';
        $discounted = app(SaleCorrections::class)->write($user->id, $paid->sale->id, 'edit', $input, $key, $revision);
        $users->setRolePermissions($this->actor, 'correction_discount_author', array_values(array_diff($permissions, ['sales.discounts.apply'])));
        try {
            app(SaleCorrections::class)->write($user->id, $paid->sale->id, 'edit', $input, $key, $revision);
            $this->fail('Discount replay ignored revoked permission');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->correction($discounted,'confirm',['payments' => []]);
            $this->fail('Revoked discount author confirmed');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT',$e->apiCode->value);
        }
        $this->assertEquals($paid,app(SalesOperations::class)->sale($this->actor,$paid->sale->id));
        $this->assertSame(1,$this->database->table('sales_payments')->count());
    }
}
