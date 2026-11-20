<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashClosings;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\SaleSummary;
use Modules\Sales\Domain\SalesConflict;
use Modules\Tickets\Contracts\SaleReceipts;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinancialIntegrity;
use Tests\Support\ReceiptsSqlServerTestCase;

class SaleReceiptsTest extends ReceiptsSqlServerTestCase
{
    public function test_closed_sale_uses_historical_products_prices_notes_folio_first_close_and_employee(): void
    {
        $sale = $this->settledSale();
        app(CatalogAdministration::class)->updateProduct($this->actor, $this->productId, ['name' => 'Nombre actual diferente', 'category_id' => $sale->items[0]->categoryId, 'price' => '999.0000']);
        $before = $this->financialState();
        $events = $this->database->table('audit_security_events')->count();
        $receipt = $this->receipt($sale->sale->id);
        $this->assertSame($sale->sale->folio, $receipt->folio);
        $this->assertSame($sale->sale->closedAt, $receipt->closedAt);
        $this->assertSame('2026-11-17 12:00:00', $receipt->localClosedAt);
        $this->assertSame('America/Mexico_City', $receipt->timezone);
        $this->assertSame('Finance fixture', $receipt->employee);
        $this->assertSame('Checkout product', $receipt->items[0]['name']);
        $this->assertSame('180.0000', $receipt->items[0]['unit_price']);
        $this->assertSame('Historical note', $receipt->items[0]['note']);
        $this->assertSame(1, $receipt->items[0]['quantity']);
        $this->assertSame([], $receipt->establishment);
        $this->assertArrayNotHasKey('customer', $receipt->jsonSerialize());
        $this->assertSame($before, $this->financialState());
        $this->assertSame($events, $this->database->table('audit_security_events')->count());
        $this->assertEquals($receipt, app(SaleReceipts::class)->view($this->actor, folio: $receipt->folio));
    }

    public static function methods(): array
    {
        return [['cash', '180'], ['cash', '200'], ['card', null], ['transfer', null], ['other', null]];
    }

    #[DataProvider('methods')]
    public function test_confirmed_payment_and_cash_change_are_historical_and_sensitive_references_are_omitted(string $method, ?string $received): void
    {
        $sale = $this->sale();
        $payment = $this->payment($method, '180', $received);
        if ($method !== 'cash') {
            $payment['reference'] = 'PRIVATE-BANK-REFERENCE';
        }
        $paid = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$payment], (string) Str::uuid(), $this->revision($sale));
        $before = $this->financialState();
        $receipt = $this->receipt($paid->sale->id);
        $this->assertCount(1, $receipt->payments);
        $this->assertSame($method, $receipt->payments[0]['method_code']);
        $this->assertSame('180.0000', $receipt->payments[0]['applied_amount']);
        $this->assertSame($received === null ? null : $received.'.0000', $receipt->payments[0]['received_cash']);
        $this->assertSame($method === 'cash' ? ($received === '200' ? '20.0000' : '0.0000') : null, $receipt->payments[0]['change_cash']);
        $this->assertStringNotContainsString('PRIVATE-BANK-REFERENCE', json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $this->financialState());
    }

    public function test_mixed_payment_keeps_each_application_without_counting_received_cash_as_income(): void
    {
        $sale = $this->sale();
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment('cash', '80', '100'), $this->payment('card', '100', null)], (string) Str::uuid(), $this->revision($sale));
        $receipt = $this->receipt($sale->sale->id);
        $this->assertSame('180.0000', $receipt->total);
        $this->assertSame(['80.0000', '100.0000'], array_column($receipt->payments, 'applied_amount'));
        $this->assertSame('20.0000', $receipt->payments[0]['change_cash']);
        $this->assertSame('180.0000', $this->funds()['expected_cash']);
    }

    public static function discounts(): array
    {
        return [['percent', 25, '45.0000', '135.0000'], ['fixed', '30.0000', '30.0000', '150.0000'],
            ['percent', 100, '180.0000', '0.0000'], ['fixed', '180.0000', '180.0000', '0.0000']];
    }

    #[DataProvider('discounts')]
    public function test_confirmed_discount_and_zero_total_do_not_invent_payments(string $type, int|string $value, string $discount, string $total): void
    {
        $sale = $this->discount($this->sale(), new DiscountProposal($type, $value));
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, $total === '0.0000' ? [] : [$this->payment('cash', $total, $total)], (string) Str::uuid(), $this->revision($sale));
        $receipt = $this->receipt($sale->sale->id);
        $this->assertSame('180.0000', $receipt->subtotal);
        $this->assertSame($discount, $receipt->discountAmount);
        $this->assertSame($total, $receipt->total);
        $this->assertSame($type, $receipt->discount['type']);
        $this->assertSame($value, $receipt->discount['value']);
        $this->assertCount($total === '0.0000' ? 0 : 1, $receipt->payments);
    }

    public static function nonFinalStates(): array
    {
        return [['open'], ['paused'], ['cancelled']];
    }

    #[DataProvider('nonFinalStates')]
    public function test_non_financial_states_do_not_generate_definitive_receipts(string $state): void
    {
        $sale = $this->sale();
        if ($state === 'paused') {
            app(SalesOperations::class)->pause($this->actor, $sale->sale->id);
        } elseif ($state === 'cancelled') {
            $this->control($sale, 'cancel', 'QA_CANCEL');
        }
        $before = $this->financialState();
        try {
            $this->receipt($sale->sale->id);
            $this->fail('No debe producir un ticket definitivo.');
        } catch (SalesConflict) {
            $this->assertSame($before, $this->financialState());
        }
    }

    public function test_active_correction_blocks_receipt_and_discard_restores_the_original_confirmed_version(): void
    {
        $sale = $this->settledSale();
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        try {
            $this->receipt($sale->sale->id);
            $this->fail('No se publica el borrador activo.');
        } catch (SalesConflict) {
            $this->assertSame('180.0000', app(SalesOperations::class)->sale($this->actor, $sale->sale->id)->sale->total);
        }
        $this->correction($row, 'discard');
        $this->assertSame('180.0000', $this->receipt($sale->sale->id)->total);
    }

    public static function inconsistentHistories(): array
    {
        return [['missing_folio'], ['missing_payment'], ['incorrect_change'], ['incorrect_total']];
    }

    #[DataProvider('inconsistentHistories')]
    public function test_inconsistent_legacy_snapshot_is_rejected_without_weakening_sql_constraints(string $case): void
    {
        $sale = $this->settledSale();
        $summary = get_object_vars($sale->sale);
        $payments = $sale->payments;
        if ($case === 'missing_folio') {
            $summary['folio'] = null;
        } elseif ($case === 'incorrect_total') {
            $summary['total'] = '1.0000';
        } elseif ($case === 'missing_payment') {
            $payments = [];
        } else {
            $payments[0]['change_cash'] = '0.0000';
        }
        // Contrato interno controlado: no se deshabilitan triggers ni se corrompe SQL para crear el caso.
        $store = $this->createStub(SalesStore::class);
        $store->method('findSale')->willReturn(new SaleSnapshot(new SaleSummary(...$summary), $sale->items, $payments));
        $this->app->instance(SalesStore::class, $store);
        $this->expectException(SalesConflict::class);
        $this->receipt($sale->sale->id);
    }

    public function test_corrected_receipt_preserves_first_close_and_folio_and_includes_all_definitive_payments(): void
    {
        $sale = $this->settledSale();
        $original = $this->receipt($sale->sale->id);
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        $this->correction($row, 'confirm', ['payments' => [$this->payment()]]);
        $receipt = $this->receipt($sale->sale->id);
        $this->assertSame($original->folio, $receipt->folio);
        $this->assertSame($original->closedAt, $receipt->closedAt);
        $this->assertSame('360.0000', $receipt->total);
        $this->assertSame(2, $receipt->items[0]['quantity']);
        $this->assertCount(2, $receipt->payments);
        $this->assertSame($row['id'], $receipt->correction['id']);
        $this->assertNotSame($original->revision, $receipt->revision);
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
    }

    public function test_reprint_request_and_replay_audit_once_without_financial_mutations(): void
    {
        $sale = $this->settledSale();
        $receipt = $this->receipt($sale->sale->id);
        $before = $this->financialState();
        $key = (string) Str::uuid();
        $service = app(SaleReceipts::class);
        $first = $service->reprint($this->actor, $sale->sale->id, $receipt->revision, $key);
        $this->assertEquals($first, $service->reprint($this->actor, $sale->sale->id, $receipt->revision, $key));
        $this->assertSame($before, $this->financialState());
        $events = $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->get();
        $this->assertCount(1, $events);
        $this->assertSame($this->actor, $events[0]->actor_id);
        $data = json_decode($events[0]->change_data, true)['receipt'];
        $this->assertSame($receipt->folio, $data['folio']);
        $this->assertFalse($data['physicalPrintConfirmed']);
        $this->assertSame('reprint_requested_document_prepared', $data['action']);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
    }

    public function test_audit_failure_rolls_back_only_the_auxiliary_request_and_keeps_the_paid_sale(): void
    {
        $sale = $this->settledSale();
        $receipt = $this->receipt($sale->sale->id);
        $before = $this->financialState();
        Event::listen(SecurityEvent::class, function ($event): void {
            if ($event->type === 'tickets.reprint.requested') {
                throw new \RuntimeException('Impresión auxiliar no disponible.');
            }
        });
        try {
            app(SaleReceipts::class)->reprint($this->actor, $sale->sale->id, $receipt->revision, (string) Str::uuid());
            $this->fail('La solicitud no debe declararse preparada sin auditoría.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Impresión auxiliar no disponible.', $exception->getMessage());
        }
        $this->assertSame($before, $this->financialState());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
        $this->assertSame($receipt->folio, $this->receipt($sale->sale->id)->folio);
    }

    public function test_closed_cash_session_does_not_prevent_historical_receipt(): void
    {
        $sale = $this->settledSale();
        app(CashClosings::class)->close($this->actor, $this->sessionId, '280', 'Conteo QA para comprobante histórico', (string) Str::uuid());
        $before = $this->financialState();
        $this->assertSame($sale->sale->folio, $this->receipt($sale->sale->id)->folio);
        $this->assertSame($before, $this->financialState());
    }

    public function test_configuration_is_optional_and_fiscal_identifier_requires_explicit_enablement(): void
    {
        $sale = $this->settledSale();
        config(['tickets.establishment' => ['name' => 'Establecimiento QA', 'address' => 'Dirección ficticia QA', 'phone' => 'Contacto QA', 'fiscal_id' => 'FISCAL-QA', 'footer' => 'Mensaje QA']]);
        $receipt = $this->receipt($sale->sale->id);
        $this->assertSame('Establecimiento QA', $receipt->establishment['name']);
        $this->assertArrayNotHasKey('fiscal_id', $receipt->establishment);
        config(['tickets.print_fiscal_id' => true]);
        $this->assertSame('FISCAL-QA', $this->receipt($sale->sale->id)->establishment['fiscal_id']);
    }

    public function test_permissions_are_rechecked_before_replaying_a_print_request(): void
    {
        $sale = $this->settledSale();
        $receipt = $this->receipt($sale->sale->id);
        $key = (string) Str::uuid();
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Ticket supervisor QA', 'username' => 'receipt.supervisor', 'password' => 'ReceiptInitial!123']);
        $users->assignRole($this->actor, $user->id, 'supervisor');
        app(IdentityAccess::class)->changePassword($user->id, 'ReceiptInitial!123', 'ReceiptChanged!123');
        app(SaleReceipts::class)->reprint($user->id, $sale->sale->id, $receipt->revision, $key);
        // No se elimina la protección del administrador; solo el permiso de impresión del rol ficticio.
        $permissions = array_values(array_diff(app(IdentityAccess::class)->inspect($user->id)->permissions, ['tickets.print']));
        $users->setRolePermissions($this->actor, 'supervisor', $permissions);
        $this->expectException(AuthorizationException::class);
        app(SaleReceipts::class)->reprint($user->id, $sale->sale->id, $receipt->revision, $key);
    }

    public function test_stale_preview_or_replay_after_correction_cannot_publish_the_previous_version_as_current(): void
    {
        $sale = $this->settledSale();
        $receipt = $this->receipt($sale->sale->id);
        $key = (string) Str::uuid();
        app(SaleReceipts::class)->reprint($this->actor, $sale->sale->id, $receipt->revision, $key);
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'note', 'note' => 'Versión nueva']);
        $this->correction($row, 'confirm', ['payments' => []]);
        $this->expectException(OperationRejected::class);
        app(SaleReceipts::class)->reprint($this->actor, $sale->sale->id, $receipt->revision, $key);
    }
}
