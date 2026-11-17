<section class="pos" x-data="{ ticket: false, mobile: innerWidth < 1024 }" @resize.window="mobile = innerWidth < 1024" @keydown.escape.window="ticket = false" @pos-error.window="if (ticket && mobile) $nextTick(() => $refs.ticketPanel.scrollTop = 0)" aria-labelledby="pos-title">
    @include('sales::partials.styles')
    <header class="pos-heading">
        <div><p class="pos-eyebrow">FresaPOS · Punto de venta</p><h1 id="pos-title">Tu espacio de venta</h1><p class="pos-muted">Catálogo, mesas y ventas en un solo lugar.</p></div>
        <div class="pos-employee"><span class="pos-dot"></span><div><span class="pos-muted">Empleado activo</span><strong>{{ $actor->name }}</strong><a href="{{ route('auth.account') }}">Cambiar empleado</a></div></div>
    </header>
    @if(!$cash)
        <div class="pos-notice" role="status"><strong>La integración de caja está pendiente.</strong><p>Puedes consultar productos, mesas y ventas. La apertura y edición estarán disponibles al integrar una sesión de caja real.</p></div>
    @elseif(!$critical)
        <div class="pos-notice" role="status"><strong>Apertura y agregado temporalmente no disponibles.</strong><p>Estas operaciones requieren la integración de idempotencia durable.</p></div>
    @endif
    <div class="pos-feedback" aria-live="polite" aria-atomic="true">
        <div class="pos-error" data-pos-network hidden role="alert"><p data-pos-network-message></p><a href="{{ route('login') }}">Volver a iniciar sesión</a></div>
        @if($stale)<p class="pos-notice" role="status">Esta venta cambió desde tu última consulta. Usa «Actualizar vista» antes de editar. Tus notas pendientes se conservan.</p>@endif
        @if($status !== '')<p class="pos-success" role="status">{{ $status }}</p>@endif
        @if($errors->any())<div class="pos-error" role="alert"><strong>No se completó la operación.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul><p>Tus notas pendientes se conservan. Actualiza la venta antes de reintentar.</p></div>@endif
        <p wire:loading.delay role="status" class="pos-muted">Actualizando… Espera un momento.</p>
    </div>
    <div class="pos-workspace">
        <div class="pos-main">
            <section class="pos-card" aria-label="Selección de venta">
                <div class="pos-row"><div class="pos-tabs" aria-label="Tipo de venta"><button type="button" wire:click="selectMode('counter')" aria-pressed="{{ $mode === 'counter' ? 'true' : 'false' }}" wire:loading.attr="disabled">Mostrador</button><button type="button" wire:click="selectMode('table')" aria-pressed="{{ $mode === 'table' ? 'true' : 'false' }}" wire:loading.attr="disabled">Mesas</button></div><button type="button" wire:click="refreshSale" wire:loading.attr="disabled">Actualizar vista</button></div>
                @if($mode === 'counter')
                    <div class="pos-row pos-spaced"><h2>Ventas de mostrador</h2><button type="button" class="pos-primary" wire:click="startSale" @disabled(!$critical || !in_array('sales.create', $actor->permissions, true) || $saleId !== null) wire:loading.attr="disabled" aria-describedby="pos-opening-help">Iniciar venta</button></div>
                    <div class="pos-tabs pos-small" aria-label="Estado de mostrador"><button type="button" wire:click="selectCounterState('open')" aria-pressed="{{ $counterState === 'open' ? 'true' : 'false' }}">Abiertas</button><button type="button" wire:click="selectCounterState('paused')" aria-pressed="{{ $counterState === 'paused' ? 'true' : 'false' }}">Pausadas</button></div>
                    <div class="pos-sale-list">
                        @forelse($sales?->items ?? [] as $sale)
                            <button type="button" class="pos-sale-choice" wire:key="sale-{{ $sale->id }}" wire:click="selectSale('{{ $sale->id }}')" @click="if(innerWidth < 1024) ticket = true" wire:loading.attr="disabled"><span><strong>{{ $sale->state->value === 'paused' ? 'Pausada' : 'Abierta' }}</strong><small>{{ $sale->id }}</small><small>{{ substr($sale->createdAt, 0, 16) }} UTC</small></span><strong class="pos-money">${{ $sale->total }}</strong></button>
                        @empty<p class="pos-empty">No hay ventas {{ $counterState === 'paused' ? 'pausadas' : 'abiertas' }} de mostrador.</p>@endforelse
                    </div>
                    @if($sales) @include('sales::partials.pagination', ['total'=>$sales->total, 'size'=>10, 'page'=>$salesPage, 'target'=>'salesPage', 'label'=>'ventas']) @endif
                @else
                    <h2 class="pos-spaced">Mesas</h2><p class="pos-muted">Selecciona una mesa ocupada para consultar su venta.</p>
                    <div class="pos-table-grid">
                        @forelse($tables?->items ?? [] as $table)
                            <button type="button" class="pos-table {{ !$table->isActive ? 'pos-inactive' : ($table->isOccupied ? 'pos-occupied' : 'pos-free') }}" wire:key="table-{{ $table->id }}" wire:click="selectTable('{{ $table->id }}')" @disabled(!$table->isActive && !$table->isOccupied) wire:loading.attr="disabled"><strong>{{ $table->name }}</strong><span>{{ !$table->isActive ? 'Inactiva' : ($table->isOccupied ? 'Ocupada · Ver venta' : 'Libre · Seleccionar') }}</span></button>
                        @empty<p class="pos-empty">Todavía no hay mesas registradas.</p>@endforelse
                    </div>
                    @if($tables) @include('sales::partials.pagination', ['total'=>$tables->total, 'size'=>10, 'page'=>$tablePage, 'target'=>'tablePage', 'label'=>'mesas']) @endif
                    @if($tableId && !$saleId)<button type="button" class="pos-primary pos-spaced" wire:click="startSale" @disabled(!$critical || !in_array('sales.create', $actor->permissions, true)) wire:loading.attr="disabled" aria-describedby="pos-opening-help">Iniciar venta de mesa</button>@endif
                @endif
                <p id="pos-opening-help" class="pos-muted pos-small pos-spaced">{{ !$cash ? 'Apertura deshabilitada: falta integrar caja.' : (!$critical ? 'Apertura deshabilitada: falta idempotencia durable.' : 'La apertura verifica caja y permisos en el servidor.') }}</p>
            </section>
            <section class="pos-card" aria-labelledby="pos-catalog-title">
                <div class="pos-row"><div><p class="pos-eyebrow">Elige lo que necesitas</p><h2 id="pos-catalog-title">Productos</h2></div><span class="pos-muted">{{ $products?->total ?? 0 }} disponibles</span></div>
                <label for="pos-search" class="pos-label pos-spaced">Buscar producto</label><div class="pos-search"><input id="pos-search" type="search" wire:model.live.debounce.350ms="search" maxlength="100" placeholder="Nombre del producto…" aria-describedby="pos-search-help"><button type="button" wire:click="clearSearch" wire:loading.attr="disabled">Limpiar</button></div><p id="pos-search-help" class="pos-muted pos-small">Busca por nombre; no necesitas escribir acentos ni mayúsculas.</p>
                <div class="pos-categories" aria-label="Filtrar por categoría"><button type="button" wire:click="selectCategory" aria-pressed="{{ $categoryFilter === '' ? 'true' : 'false' }}">Todas</button>@foreach($categories?->items ?? [] as $category)<button type="button" wire:key="category-{{ $category->id }}" wire:click="selectCategory('{{ $category->id }}')" aria-pressed="{{ $categoryFilter === $category->id ? 'true' : 'false' }}">{{ $category->name }}</button>@endforeach</div>
                @if($categoryFilter !== '')<p class="pos-muted">Categoría seleccionada: <strong>{{ $categoryName }}</strong></p>@endif
                @if($categories && $categories->total === 0)<p class="pos-muted">No hay categorías disponibles.</p>@endif
                @if($categories) @include('sales::partials.pagination', ['total'=>$categories->total, 'size'=>10, 'page'=>$categoryPage, 'target'=>'categoryPage', 'label'=>'categorías']) @endif
                <div class="pos-products">
                    @forelse($products?->items ?? [] as $product)
                        <article class="pos-product" wire:key="product-{{ $product->id }}">
                            @if(isset($images[$product->id]))<img src="{{ $images[$product->id] }}" alt="{{ $product->name }}" loading="lazy" width="240" height="160">@else<div class="pos-product-placeholder" role="img" aria-label="{{ $product->name }} · sin imagen"><span>{{ $product->name }}</span></div>@endif
                            <div class="pos-product-body"><h3>{{ $product->name }}</h3><strong class="pos-money">${{ $product->price }}</strong><button type="button" wire:click="chooseProduct('{{ $product->id }}')" @click="ticket = true" @disabled(!$critical || !$editable) wire:loading.attr="disabled" aria-label="Seleccionar {{ $product->name }}" aria-describedby="pos-product-help">Seleccionar</button></div>
                        </article>
                    @empty<p class="pos-empty">No hay productos disponibles con estos filtros. Prueba otra búsqueda o limpia los filtros.</p>@endforelse
                </div>
                <p id="pos-product-help" class="pos-muted pos-small pos-spaced">{{ !$cash ? 'Agregar productos requiere una venta abierta y una caja real.' : (!$critical ? 'Agregar productos requiere idempotencia durable.' : 'Selecciona una venta abierta con permiso de edición para agregar productos.') }}</p>
                @if($products) @include('sales::partials.pagination', ['total'=>$products->total, 'size'=>10, 'page'=>$productPage, 'target'=>'productPage', 'label'=>'productos']) @endif
            </section>
        </div>
        <aside class="pos-ticket pos-card" x-ref="ticketPanel" :class="{ 'pos-ticket-open': ticket }" x-trap.inert.noscroll="ticket && mobile" :role="mobile ? 'dialog' : 'complementary'" :aria-modal="ticket && mobile ? 'true' : null" aria-labelledby="pos-sale-title" @keydown.escape="ticket = false">
            <div class="pos-row"><div><p class="pos-eyebrow">Venta actual</p><h2 id="pos-sale-title" tabindex="-1" x-ref="panelHeading">{{ $saleId ? ($currentSale['type'] === 'table' ? 'Venta de mesa' : 'Mostrador') : 'Todo listo para empezar' }}</h2></div><button type="button" class="pos-mobile-only" @click="ticket = false; $refs.ticketButton.focus()">Cerrar panel</button></div>
            <div class="pos-error" data-pos-network hidden role="alert"><p data-pos-network-message></p><a href="{{ route('login') }}">Volver a iniciar sesión</a></div>
            @if($errors->any())<div class="pos-error pos-spaced" role="alert"><strong>Revisa la operación</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul><p>Tus notas pendientes se conservan.</p></div>@endif
            @if($saleId)
                <p class="pos-id">{{ $saleId }}</p><div class="pos-row"><span class="pos-badge">{{ ['open'=>'Abierta','paused'=>'Pausada','closed'=>'Cerrada','cancelled'=>'Cancelada','reopened'=>'Reabierta'][$currentSale['state']] }}</span><button type="button" wire:click="clearSale" wire:loading.attr="disabled">Cambiar venta</button></div>
                @if($currentSale['table_id'])<p class="pos-id">Mesa: {{ $currentSale['table_name'] }}</p>@endif
                <p class="pos-muted pos-small">Empleado activo: {{ $actor->name }}</p>
                <div class="pos-lines">
                    @forelse($currentSale['items'] as $item)
                        <article class="pos-line" wire:key="item-{{ $item['id'] }}"><div class="pos-row"><strong>{{ $item['name'] }}</strong><strong class="pos-money">${{ $item['line_total'] }}</strong></div><p class="pos-muted pos-small">{{ $item['quantity'] }} × ${{ $item['unit_price'] }} · Precio aplicado</p>@if($item['note'])<p class="pos-line-note">{{ $item['note'] }}</p>@endif
                        @if($item['removed_at'])<span class="pos-muted">Línea retirada · Conservada en historial</span>@else<div class="pos-quantity-controls"><button type="button" wire:click="adjustQuantity('{{ $item['id'] }}', -1)" @disabled(!$editable) wire:loading.attr="disabled" aria-label="Disminuir cantidad de {{ $item['name'] }}">−</button><span>{{ $item['quantity'] }}</span><button type="button" wire:click="adjustQuantity('{{ $item['id'] }}', 1)" @disabled(!$editable) wire:loading.attr="disabled" aria-label="Aumentar cantidad de {{ $item['name'] }}">+</button><button type="button" wire:click="editItem('{{ $item['id'] }}')" @disabled(!$editable) wire:loading.attr="disabled">Cantidad / nota</button></div>@endif</article>
                    @empty<p class="pos-empty">Esta venta aún no tiene productos.</p>@endforelse
                </div>
                <dl class="pos-totals"><div><dt>Subtotal</dt><dd>${{ $currentSale['subtotal'] }}</dd></div><div><dt>Descuento registrado</dt><dd>${{ $currentSale['discount'] }}</dd></div><div class="pos-total"><dt>Total</dt><dd>${{ $currentSale['total'] }}</dd></div></dl>
                <form wire:submit="saveSaleNote" class="pos-spaced"><label class="pos-label" for="pos-sale-note">Nota general</label><textarea id="pos-sale-note" wire:model="saleNote" maxlength="150" rows="2" @disabled(!$editable) aria-describedby="pos-sale-note-help pos-sale-note-error"></textarea><p id="pos-sale-note-help" class="pos-muted pos-small">Hasta 150 caracteres. {{ mb_strlen($saleNote) }}/150 guardados en el formulario.</p>@error('saleNote')<p id="pos-sale-note-error" class="pos-field-error">{{ $message }}</p>@enderror<button type="submit" @disabled(!$editable) wire:loading.attr="disabled">Guardar nota general</button></form>
                @if($productId || $itemId)
                    <form wire:submit="{{ $productId ? 'addProduct' : 'saveQuantity' }}" class="pos-editor pos-spaced"><h3>{{ $productId ? 'Agregar producto' : 'Editar línea' }} · {{ $productName }}</h3><label class="pos-label" for="pos-quantity">Cantidad</label><input id="pos-quantity" wire:model="quantity" type="number" inputmode="numeric" min="1" max="{{ config('sales.max_quantity') }}" step="1" @disabled(!$editable) aria-describedby="pos-quantity-error">@error('quantity')<p id="pos-quantity-error" class="pos-field-error">{{ $message }}</p>@enderror<label class="pos-label pos-spaced" for="pos-item-note">Nota del producto</label><textarea id="pos-item-note" wire:model="itemNote" rows="2" maxlength="100" @disabled(!$editable) aria-describedby="pos-item-note-help pos-item-note-error"></textarea><p id="pos-item-note-help" class="pos-muted pos-small">Hasta 100 caracteres.</p>@error('itemNote')<p id="pos-item-note-error" class="pos-field-error">{{ $message }}</p>@enderror<div class="pos-row"><button type="submit" class="pos-primary" @disabled(!$editable || ($productId && !$critical)) wire:loading.attr="disabled">{{ $productId ? 'Confirmar agregado' : 'Guardar cantidad' }}</button>@if($itemId)<button type="button" wire:click="saveItemNote" @disabled(!$editable) wire:loading.attr="disabled">Guardar nota de producto</button>@endif</div></form>
                @endif
                @if($currentSale['type'] === 'counter')<div class="pos-row pos-spaced"><button type="button" wire:click="pauseSale" @disabled(!$editable) wire:loading.attr="disabled">Pausar venta</button><button type="button" wire:click="resumeSale" @disabled(!$cash || $currentSale['state'] !== 'paused' || !in_array('sales.open.update', $actor->permissions, true)) wire:loading.attr="disabled">Recuperar venta</button></div>@endif
            @else
                <div class="pos-ticket-empty"><span aria-hidden="true">＋</span><h3>Selecciona una venta</h3><p>Consulta una venta de mostrador o elige una mesa ocupada. Aquí verás sus productos e importes.</p></div>
            @endif
            <div class="pos-future pos-spaced"><h3>Próximas operaciones</h3><p id="pos-future-help" class="pos-muted pos-small">Cobros, cierre, descuentos y tickets requieren etapas posteriores. Retirar o cancelar requiere motivos autorizados.</p><div class="pos-future-buttons">@foreach(['Cobrar','Cerrar venta','Descuento especial','Cancelar venta','Retirar línea','Imprimir ticket'] as $action)<button type="button" disabled aria-describedby="pos-future-help">{{ $action }}</button>@endforeach</div></div>
        </aside>
    </div>
    <button type="button" class="pos-mobile-ticket" x-ref="ticketButton" @click="ticket = true; $nextTick(() => $refs.panelHeading?.focus())" :aria-expanded="ticket" aria-controls="pos-sale-title">Ver venta actual <strong>${{ $currentSale['total'] ?? '0.0000' }}</strong></button>
    <div class="pos-connection" wire:offline role="alert">Sin conexión. Conserva tus notas y actualiza la venta al recuperar la conexión. Si la sesión expiró, <a href="{{ route('login') }}">inicia sesión de nuevo</a>.</div>
</section>
@script
<script>
    const root = $wire.$el;
    const notify = (message) => {
        if (!root.isConnected) return;
        root.querySelectorAll('[data-pos-network]').forEach(banner => {
            banner.querySelector('[data-pos-network-message]').textContent = message;
            banner.hidden = false;
            if (banner.getBoundingClientRect().width > 0) banner.scrollIntoView({block: 'nearest'});
        });
    };
    if (window.posStopIntercept) window.posStopIntercept();
    window.posStopIntercept = Livewire.interceptRequest(({request, onError, onFailure}) => {
        if (!root.isConnected) return;
        request.options.headers['Accept'] = 'application/json';
        onError(({response, preventDefault}) => {
            preventDefault();
            notify([401, 419].includes(response.status)
                ? 'La sesión expiró o cambió. Vuelve a iniciar sesión antes de continuar; tus notas siguen en pantalla.'
                : response.status === 403
                    ? 'El empleado activo o sus permisos cambiaron. Vuelve a abrir Punto de venta con tu acceso actual.'
                    : 'No se confirmó la operación. Conserva tus notas y actualiza la venta antes de reintentar.');
        });
        onFailure(() => notify('Sin conexión. Tus notas siguen en pantalla. Al recuperar la conexión, actualiza la venta antes de reintentar.'));
    });
</script>
@endscript
