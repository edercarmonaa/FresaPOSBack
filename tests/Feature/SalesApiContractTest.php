<?php

namespace Tests\Feature;

use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Infrastructure\UnavailableCancellationApproval;
use Modules\Sales\Infrastructure\UnavailableCriticalSalesWrites;
use Tests\TestCase;

class SalesApiContractTest extends TestCase
{
    public function test_revision_is_deterministic_opaque_and_changes_for_table_occupancy(): void
    {
        $free = new DiningTableSnapshot('01arz3ndektsv4rrffq69g5fav', 'Mesa', true, 0, false);
        $same = new DiningTableSnapshot('01arz3ndektsv4rrffq69g5fav', 'Mesa', true, 0, false);
        $occupied = new DiningTableSnapshot('01arz3ndektsv4rrffq69g5fav', 'Mesa', true, 0, true, '01arz3ndektsv4rrffq69g5faw');
        $revision = SalesRevision::of($free);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $revision);
        $this->assertSame($revision, SalesRevision::of($same));
        $this->assertNotSame($revision, SalesRevision::of($occupied));
        SalesRevision::requireCurrent($same, '"'.$revision.'"');
        $this->expectException(SalesConflict::class);
        SalesRevision::requireCurrent($occupied, '"'.$revision.'"');
    }

    public function test_unquoted_or_wildcard_revision_is_not_a_bypass(): void
    {
        $table = new DiningTableSnapshot('01arz3ndektsv4rrffq69g5fav', 'Mesa', true, 0, false);
        foreach ([null, '*', SalesRevision::of($table), 'W/"'.SalesRevision::of($table).'"'] as $revision) {
            try {
                SalesRevision::requireCurrent($table, $revision);
                $this->fail('Debe exigir revisión exacta.');
            } catch (SalesConflict) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_production_critical_adapter_never_executes_the_operation(): void
    {
        $this->assertInstanceOf(UnavailableCriticalSalesWrites::class, app(CriticalSalesWrites::class));
        $this->assertInstanceOf(UnavailableCancellationApproval::class, app(CancellationApproval::class));
        $executed = false;
        try {
            app(CriticalSalesWrites::class)->run('actor', 'sales.open', 'key', [], function () use (&$executed): never {
                $executed = true;
                throw new \LogicException('No ejecutar.');
            });
            $this->fail('Debe fallar cerrado.');
        } catch (SalesDependencyUnavailable) {
            $this->assertFalse($executed);
        }
    }

    public function test_sale_query_rejects_invalid_utc_day_and_sql_ordering(): void
    {
        $this->assertSame('2028-02-29', (new SaleQuery(date: '2028-02-29', order: 'asc'))->date);
        foreach ([['2026-02-29', 'asc'], ['2026-01-01', 'DROP'], ['2026-01-01T00:00:00', 'desc']] as [$date,$order]) {
            try {
                new SaleQuery(date: $date, order: $order);
                $this->fail('Consulta no permitida.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
