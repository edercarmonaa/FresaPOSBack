<?php

namespace Tests\Fixtures\UI07B;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

final class FixtureData
{
    public static function make(string $state = 'list'): array
    {
        $items = [
            ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'Motivo ficticio de retiro', 'code' => 'QA_REMOVAL', 'operation' => 'sale_item_removal', 'description' => 'Datos exclusivos de revisión visual.', 'is_active' => true, 'display_order' => 10],
            ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'name' => 'Motivo ficticio de cancelación', 'code' => 'QA_CANCELLATION', 'operation' => 'sale_cancellation', 'description' => str_repeat('Descripción extensa para verificar ajuste de texto. ', 6), 'is_active' => false, 'display_order' => 20],
        ];

        return [
            'status' => $state === 'submitted' ? 'No hay un resultado confirmado. Reintenta conservando la misma intención.' : '',
            'errors' => (new ViewErrorBag)->put('default', new MessageBag($state === 'error' ? ['name' => ['El nombre es obligatorio.']] : [])),
            'submitted' => $state === 'submitted',
            'formOpen' => in_array($state, ['create', 'edit', 'submitted', 'activate', 'deactivate', 'error'], true),
            'action' => in_array($state, ['activate', 'deactivate'], true) ? 'status' : ($state === 'edit' ? 'update' : 'create'),
            'frozen' => ['is_active' => $state === 'activate'],
            'name' => 'Motivo de QA <script>alert("escaped")</script>',
            'code' => 'QA_REASON', 'operation' => 'sale_item_removal', 'description' => 'Solo fixture', 'displayOrder' => 10,
            'filterOperation' => '', 'filterActive' => '',
            'reasons' => ['items' => $state === 'empty' ? [] : $items, 'total' => $state === 'empty' ? 0 : 12],
            'page' => $state === 'last-page' ? 2 : 1,
        ];
    }
}
