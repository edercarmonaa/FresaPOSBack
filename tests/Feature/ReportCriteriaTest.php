<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Reports\Domain\ReportCriteria;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportCriteriaTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function ranges(): array
    {
        return [['today', '2026-11-17', '2026-11-17'], ['yesterday', '2026-11-16', '2026-11-16'], ['last_seven_days', '2026-11-11', '2026-11-17'], ['current_month', '2026-11-01', '2026-11-17'], ['previous_month', '2026-10-01', '2026-10-31']];
    }

    #[DataProvider('ranges')]
    public function test_presets_resolve_to_frozen_business_dates_and_exclusive_utc_bounds(string $range, string $from, string $until): void
    {
        config(['pos.business_timezone' => 'America/Mexico_City']);
        CarbonImmutable::setTestNow('2026-11-18T05:59:59Z');
        $criteria = ReportCriteria::from('sales', compact('range'));
        $this->assertSame($from, $criteria->values['from']);
        $this->assertSame($until, $criteria->values['until']);
        $this->assertSame('custom', $criteria->values['range']);
        $this->assertSame($from.' 06:00:00.000000', $criteria->query->fromUtc);
        $this->assertSame(CarbonImmutable::parse($until, 'America/Mexico_City')->addDay()->utc()->format('Y-m-d H:i:s.u'), $criteria->query->untilUtc);
        CarbonImmutable::setTestNow('2026-11-18T06:01:00Z');
        $this->assertSame($criteria->query->fromUtc, ReportCriteria::from('sales', $criteria->values)->query->fromUtc);
    }

    public static function invalid(): array
    {
        return [[['from' => '2026-02-30', 'until' => '2026-03-01']], [['from' => '2026-11-18', 'until' => '2026-11-17']], [['from' => '2025-01-01', 'until' => '2026-01-02']], [['from' => '1700-01-01', 'until' => '1700-01-02']], [['from' => '2026-11-17']], [['page' => 0]], [['per_page' => 1001]], [['sort' => 'total; DROP TABLE sales_sales']], [['direction' => 'desc;select 1']], [['employee_id' => 'invalid']], [['unknown' => 'value']], [['range' => 'today', 'from' => '2026-11-17', 'until' => '2026-11-17']]];
    }

    #[DataProvider('invalid')]
    public function test_invalid_filters_are_rejected_before_sql(array $input): void
    {
        config(['pos.business_timezone' => 'America/Mexico_City']);
        $this->expectException(ValidationException::class);
        ReportCriteria::from('sales', $input);
    }

    public function test_historical_dst_and_leap_day_are_converted_without_fixed_offset_assumptions(): void
    {
        config(['pos.business_timezone' => 'America/Mexico_City']);
        $summer = ReportCriteria::from('summary', ['from' => '2020-07-01', 'until' => '2020-07-01']);
        $this->assertSame('2020-07-01 05:00:00.000000', $summer->query->fromUtc);
        $leap = ReportCriteria::from('summary', ['from' => '2024-02-29', 'until' => '2024-02-29']);
        $this->assertSame('2024-03-01 06:00:00.000000', $leap->query->untilUtc);
    }

    public function test_non_applicable_filters_cannot_cross_report_boundaries(): void
    {
        $this->expectException(ValidationException::class);
        ReportCriteria::from('cash-reconciliations', ['product_id' => '01j00000000000000000000000']);
    }
}
