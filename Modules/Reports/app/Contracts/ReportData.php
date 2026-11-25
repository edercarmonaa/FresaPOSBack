<?php

namespace Modules\Reports\Contracts;

final readonly class ReportData implements \JsonSerializable
{
    public function __construct(public string $report, public string $title, public string $generatedAt, public string $timezone,
        public array $filters, public array $columns, public array $items, public array $totals, public int $total,
        public int $page, public int $perPage, public array $notes, public string $isolation, public array $series = []) {}

    public function jsonSerialize(): array
    {
        return ['report' => $this->report, 'title' => $this->title, 'generated_at' => $this->generatedAt, 'timezone' => $this->timezone,
            'filters' => $this->filters, 'columns' => $this->columns, 'items' => $this->items, 'totals' => $this->totals,
            'pagination' => ['total' => $this->total, 'page' => $this->page, 'per_page' => $this->perPage, 'last_page' => max(1, intdiv($this->total + $this->perPage - 1, $this->perPage))], 'series' => $this->series,
            'notes' => $this->notes, 'consistency' => ['isolation' => $this->isolation, 'scope' => 'Una transacción de lectura; exportación fuera de la transacción.']];
    }
}
