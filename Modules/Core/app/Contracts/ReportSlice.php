<?php

namespace Modules\Core\Contracts;

/** Datos escalares de lectura, sin modelos ni documentos internos. */
final readonly class ReportSlice
{
    public function __construct(public array $items, public int $total, public array $totals = [], public array $notes = [], public array $series = []) {}
}
