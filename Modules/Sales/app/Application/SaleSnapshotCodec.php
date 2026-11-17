<?php

namespace Modules\Sales\Application;

use Modules\Sales\Contracts\SaleItemSnapshot;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SaleSummary;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;

/** Solo DTOs públicos readonly; nunca serializa modelos, excepciones ni contexto HTTP. */
final class SaleSnapshotCodec
{
    public static function encode(SaleSnapshot $snapshot): array
    {
        $sale = get_object_vars($snapshot->sale);
        $sale['type'] = $snapshot->sale->type->value;
        $sale['state'] = $snapshot->sale->state->value;

        return ['sale' => $sale, 'items' => array_map(get_object_vars(...), $snapshot->items)];
    }

    public static function decode(array $data): SaleSnapshot
    {
        $sale = $data['sale'];
        $sale['type'] = SaleType::from($sale['type']);
        $sale['state'] = SaleState::from($sale['state']);

        return new SaleSnapshot(new SaleSummary(...$sale), array_map(fn ($item) => new SaleItemSnapshot(...$item), $data['items']));
    }
}
