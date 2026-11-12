<nav aria-label="Paginación" class="mt-5 flex flex-wrap items-center gap-3">
    <p class="mr-auto text-sm text-slate-600">{{ $pagination['from'] ?? 0 }}–{{ $pagination['to'] ?? 0 }} de {{ $pagination['total'] }} · Página {{ $pagination['current_page'] }} de {{ $pagination['last_page'] }}</p>
    <button type="button" wire:click="gotoPage({{ max(1, $page - 1) }})" wire:loading.attr="disabled" @disabled($page <= 1) class="rounded border px-4 py-2 disabled:opacity-40">Anterior</button>
    <button type="button" wire:click="gotoPage({{ $page + 1 }})" wire:loading.attr="disabled" @disabled($page >= $pagination['last_page']) class="rounded border px-4 py-2 disabled:opacity-40">Siguiente</button>
</nav>
