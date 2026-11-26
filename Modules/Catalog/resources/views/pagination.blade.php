<x-ui.pagination label="Paginación">
    <x-slot:summary>{{ $pagination['from'] ?? 0 }}–{{ $pagination['to'] ?? 0 }} de {{ $pagination['total'] }} · Página {{ $pagination['current_page'] }} de {{ $pagination['last_page'] }}</x-slot:summary>
    <x-ui.button variant="secondary" type="button" wire:click="gotoPage({{ max(1, $page - 1) }})" wire:loading.attr="disabled" :disabled="$page <= 1">Anterior</x-ui.button>
    <x-ui.button variant="secondary" type="button" wire:click="gotoPage({{ $page + 1 }})" wire:loading.attr="disabled" :disabled="$page >= $pagination['last_page']">Siguiente</x-ui.button>
</x-ui.pagination>
