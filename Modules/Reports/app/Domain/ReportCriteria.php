<?php

namespace Modules\Reports\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\ReportQuery;

final readonly class ReportCriteria
{
    public function __construct(public string $report, public array $values, public ReportQuery $query) {}

    public static function rules(string $report): array
    {
        ReportDefinition::require($report);
        $rules = ['range' => 'sometimes|in:today,yesterday,last_seven_days,current_month,previous_month,custom', 'from' => 'sometimes|required|date_format:Y-m-d', 'until' => 'sometimes|required|date_format:Y-m-d',
            'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|in:10,25,50,100', 'sort' => 'sometimes|in:'.implode(',', ReportDefinition::sorts($report)), 'direction' => 'sometimes|in:asc,desc'];
        foreach (ReportDefinition::filters($report) as $field) {
            $rules[$field] = match ($field) {
                'sale_type' => 'sometimes|in:counter,table', 'payment_method' => 'sometimes|in:cash,card,transfer,other', 'discount_type' => 'sometimes|in:fixed,percent',
                'movement_type' => 'sometimes|in:cash_in,cash_out', 'state' => match ($report) {
                    'sales' => 'sometimes|in:open,paused,cancelled,closed','corrections' => 'sometimes|in:active,confirmed,unchanged,discarded',default => 'sometimes|in:open,closed'
                },
                default => 'sometimes|ulid',
            };
        }

        return $rules;
    }

    public static function from(string $report, array $input, bool $export = false): self
    {
        $rules = self::rules($report);
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['filters' => 'Hay filtros no aplicables o campos desconocidos.']);
        }
        $values = validator($input, $rules)->validate();
        $timezone = (string) config('pos.business_timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $range = $values['range'] ?? (isset($values['from']) || isset($values['until']) ? 'custom' : 'today');
        if ($range !== 'custom' && (isset($values['from']) || isset($values['until']))) {
            throw ValidationException::withMessages(['range' => 'Las fechas explícitas requieren rango personalizado.']);
        }
        [$from,$until] = match ($range) {
            'today' => [$today, $today], 'yesterday' => [$today->subDay(), $today->subDay()], 'last_seven_days' => [$today->subDays(6), $today],
            'current_month' => [$today->startOfMonth(), $today], 'previous_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()->subDay()],
            default => self::custom($values, $timezone),
        };
        if ($from->year < 1753 || $until->year > 9998 || $until->lt($from) || $from->diff($until)->days >= 366) {
            throw ValidationException::withMessages(['until' => 'Usa un rango ordenado de hasta 366 días inclusivos.']);
        }
        $values = array_merge($values, ['range' => 'custom', 'from' => $from->toDateString(), 'until' => $until->toDateString(), 'page' => (int) ($values['page'] ?? 1), 'per_page' => (int) ($values['per_page'] ?? 25), 'sort' => $values['sort'] ?? ReportDefinition::sorts($report)[0], 'direction' => $values['direction'] ?? 'desc']);
        $filters = array_intersect_key($values, array_flip(ReportDefinition::filters($report)));
        foreach ($filters as $key => $value) {
            if (str_ends_with($key, '_id')) {
                $filters[$key] = strtolower($value);
                $values[$key] = $filters[$key];
            }
        }
        $query = new ReportQuery($from->utc()->format('Y-m-d H:i:s.u'), $until->addDay()->utc()->format('Y-m-d H:i:s.u'), $timezone, $filters,
            $export ? 1 : $values['page'], $export ? 1001 : $values['per_page'], $values['sort'], $values['direction']);

        return new self($report, $values, $query);
    }

    private static function custom(array $values, string $timezone): array
    {
        if (! isset($values['from'],$values['until'])) {
            throw ValidationException::withMessages(['range' => 'Indica ambas fechas del rango personalizado.']);
        }

        return [CarbonImmutable::createFromFormat('!Y-m-d', $values['from'], $timezone), CarbonImmutable::createFromFormat('!Y-m-d', $values['until'], $timezone)];
    }
}
