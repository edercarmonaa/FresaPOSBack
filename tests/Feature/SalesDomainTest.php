<?php

namespace Tests\Feature;

use InvalidArgumentException;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Domain\Amount;
use Modules\Sales\Domain\SaleLifecycle;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Modules\Sales\Infrastructure\UnavailableLineRemoval;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesDomainTest extends TestCase
{
    public function test_exact_arithmetic_supports_scaled_values_larger_than_php_int_max(): void
    {
        $this->assertSame('999999999999999.9999', Amount::from('999999999999999.9999')->multiply(1)->value);
        $this->assertSame('999999999999999.9999', Amount::from('999999999999999.9998')->add(Amount::from('0.0001'))->value);
        $this->assertSame('999999999999999.9998', Amount::from('999999999999999.9999')->subtract(Amount::from('0.0001'))->value);
        $this->assertSame('0.0000', Amount::from('999999999999999.9999')->subtract(Amount::from('999999999999999.9999'))->value);
        $this->assertSame('123456789012345.6700', Amount::from('1234567890123.4567')->multiply(100)->value);
        $this->assertSame('0.0003', Amount::from('0.0001')->multiply(3)->value);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(mixed $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        Amount::from($amount);
    }

    public static function invalidAmounts(): array
    {
        return [['-1.0000'], ['1'], ['0.1'], ['1.00001'], ['1000000000000000.0000'], ['01.0000'], ['1e2'], ['NaN'], ['1,000.0000'], [0.0001], [1], [true], [null]];
    }

    #[DataProvider('invalidArithmetic')]
    public function test_overflows_and_negative_totals_are_rejected(string $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($operation) {
            'sum' => Amount::from('999999999999999.9999')->add(Amount::from('0.0001')),
            'multiply' => Amount::from('999999999999999.9999')->multiply(2),
            'negative' => Amount::from('0.0000')->subtract(Amount::from('0.0001')),
            'quantity' => Amount::from('1.0000')->multiply(0),
            'fraction' => Amount::from('1.0000')->multiply(1.5),
        };
    }

    public static function invalidArithmetic(): array
    {
        return [['sum'], ['multiply'], ['negative'], ['quantity'], ['fraction']];
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantities_never_coerce_fractions_strings_or_booleans(mixed $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);
        SalesText::quantity($quantity, 100);
    }

    public static function invalidQuantities(): array
    {
        return [[0], [-1], [101], [1.0], [1.5], ['1'], ['1.5'], [true], [null], [PHP_INT_MAX]];
    }

    public function test_note_normalization_preserves_case_and_storage_limits(): void
    {
        $this->assertSame('Sin Cebolla', SalesText::normalize('  Sin   Cebolla  ', 100));
        $this->assertNull(SalesText::normalize('   ', 100));
        $this->assertSame(str_repeat('á', 100), SalesText::normalize(str_repeat('á', 100), 100));
        $this->assertSame(str_repeat('N', 150), SalesText::normalize(str_repeat('N', 150), 150));
        $this->assertSame(100, config('sales.max_quantity'));
    }

    #[DataProvider('unsafeNotes')]
    public function test_html_controls_and_long_notes_are_rejected(string $note, int $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);
        SalesText::normalize($note, $maximum);
    }

    public static function unsafeNotes(): array
    {
        return [[str_repeat('x', 101), 100], [str_repeat('x', 151), 150], ['<script>alert(1)</script>', 100], ["Sin\ncebolla", 100], ["texto\x00", 100], ["a\u{202E}b", 100]];
    }

    public function test_states_types_and_reserved_transitions_are_explicit(): void
    {
        $this->assertNull(SaleState::tryFrom('paid'));
        $this->assertNull(SaleType::tryFrom('delivery'));
        SaleLifecycle::pause(SaleType::Counter, SaleState::Open);
        SaleLifecycle::resume(SaleType::Counter, SaleState::Paused);
        SaleLifecycle::cancel(SaleState::Paused);
        foreach ([SaleState::Paused, SaleState::Closed, SaleState::Cancelled, SaleState::Reopened] as $state) {
            try {
                SaleLifecycle::editable($state);
                $this->fail('No debe editar estados reservados o terminales.');
            } catch (SalesConflict) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFalse(method_exists(app(SalesOperations::class), 'close'));
        $this->assertFalse(method_exists(app(SalesOperations::class), 'reopen'));
    }

    public function test_table_sale_cannot_pause(): void
    {
        $this->expectException(SalesConflict::class);
        SaleLifecycle::pause(SaleType::Table, SaleState::Open);
    }

    public function test_production_bindings_deny_missing_financial_and_reason_dependencies(): void
    {
        $this->assertInstanceOf(UnavailableCashSession::class, app(ActiveCashSession::class));
        $this->assertInstanceOf(UnavailableLineRemoval::class, app(LineRemovalApproval::class));
        $this->expectException(SalesConflict::class);
        app(ActiveCashSession::class)->requireActive('01arz3ndektsv4rrffq69g5fav');
    }

    public function test_discount_proposal_does_not_apply_or_authorize_discounts(): void
    {
        $this->assertSame('10.0000', (new DiscountProposal('fixed', '10.0000'))->value);
        $this->assertSame(10, (new DiscountProposal('percent', 10))->value);
        $this->expectException(InvalidArgumentException::class);
        new DiscountProposal('percent', 10.5);
    }

    public function test_queries_reject_unbounded_pages_and_untrusted_identifiers(): void
    {
        foreach ([fn () => new SaleQuery(page: 0), fn () => new SaleQuery(perPage: PHP_INT_MAX),
            fn () => new SaleQuery(tableId: 'x;DROP'), fn () => new TableQuery(page: 1000001)] as $query) {
            try {
                $query();
                $this->fail('Consulta inválida.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_public_commands_cannot_supply_totals_prices_state_folio_or_authorizer(): void
    {
        $open = new \ReflectionMethod(OpenSale::class, '__construct');
        $add = new \ReflectionMethod(AddProduct::class, '__construct');
        $this->assertSame(['type', 'tableId', 'note'], array_map(fn ($p) => $p->getName(), $open->getParameters()));
        $this->assertSame(['productId', 'quantity', 'note'], array_map(fn ($p) => $p->getName(), $add->getParameters()));
        $this->assertFalse(method_exists(app(SalesOperations::class), 'update'));
    }
}
