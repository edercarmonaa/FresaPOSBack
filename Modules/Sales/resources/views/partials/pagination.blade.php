@if($total > $size)
    <nav class="pos-pager" aria-label="Páginas de {{ $label }}">
        <button type="button" wire:click="gotoPage('{{ $target }}', {{ max(1, $page - 1) }})" @disabled($page <= 1) wire:loading.attr="disabled">Anterior</button>
        <span>Página {{ $page }} de {{ max(1, (int) ceil($total / $size)) }}</span>
        <button type="button" wire:click="gotoPage('{{ $target }}', {{ $page + 1 }})" @disabled($page * $size >= $total) wire:loading.attr="disabled">Siguiente</button>
    </nav>
@endif
