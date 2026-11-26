<section class="reasons-admin ui-reasons-module" aria-labelledby="reasons-title">
    <header><h1 id="reasons-title" class="ui-heading">Motivos autorizados de ventas</h1><p>Configura los motivos que podrán utilizar los empleados autorizados. El código y la operación se conservan para siempre.</p></header>
    @if($status)<p role="status" class="ui-reasons-status">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="ui-reasons-errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <button class="ui-button ui-button--primary" type="button" wire:click="create" wire:loading.attr="disabled">Crear motivo</button>
    @if($submitted)<button class="ui-button ui-button--secondary" type="button" wire:click="queryOperation" wire:loading.attr="disabled">Consultar estado de la operación</button>@endif
    @if($formOpen)
        <form wire:submit="save" class="ui-reasons-form" aria-label="Formulario de motivo">
            @if($action==='status')
                <p>Confirma {{ ($frozen['is_active'] ?? false) ? 'activar' : 'desactivar' }} el motivo <strong>{{ $name }}</strong>. Las operaciones históricas no se modifican.</p>
            @else
                <fieldset @disabled($submitted) class="ui-reasons-list">
                    <label class="ui-field" for="reason-code">Código estable<input class="ui-input" id="reason-code" wire:model="code" maxlength="64" @disabled($action!=='create') autocomplete="off"></label>
                    <label class="ui-field" for="reason-operation">Operación<select class="ui-select" id="reason-operation" wire:model="operation" @disabled($action!=='create')><option value="sale_item_removal">Retirar producto</option><option value="sale_cancellation">Cancelar venta no pagada</option></select></label>
                    <label class="ui-field" for="reason-name">Nombre<input class="ui-input" id="reason-name" wire:model="name" maxlength="150" required></label>
                    <label class="ui-field" for="reason-description">Descripción opcional<textarea class="ui-textarea" id="reason-description" wire:model="description" maxlength="500" rows="3"></textarea></label>
                    <label class="ui-field" for="reason-order">Orden<input class="ui-input" id="reason-order" wire:model="displayOrder" type="number" min="0" max="2147483647" required></label>
                </fieldset>
                <p>Crear un motivo lo deja activo. Los cambios solo se aplican a operaciones nuevas.</p>
            @endif
            <button class="ui-button ui-button--primary" type="submit" wire:confirm="¿Confirmas este cambio del catálogo de motivos?" wire:loading.attr="disabled">Confirmar motivo</button>
        </form>
    @endif
    <div class="ui-reasons-filters ui-admin-filter-fields">
        <label for="reason-filter-operation">Operación<select class="ui-select" id="reason-filter-operation" wire:model.live="filterOperation"><option value="">Todas</option><option value="sale_item_removal">Retiro de producto</option><option value="sale_cancellation">Cancelación</option></select></label>
        <label for="reason-filter-active">Estado<select class="ui-select" id="reason-filter-active" wire:model.live="filterActive"><option value="">Todos</option><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label>
    </div>
    <div class="ui-reasons-list">
        @forelse($reasons['items'] as $reason)
            <article class="ui-reasons-card" wire:key="reason-{{ $reason['id'] }}"><h2 class="ui-section-title">{{ $reason['name'] }}</h2><p><code>{{ $reason['code'] }}</code> · {{ $reason['operation']==='sale_item_removal' ? 'Retiro de producto' : 'Cancelación de venta' }}</p><p>{{ $reason['description'] }}</p><p>{{ $reason['is_active'] ? 'Activo' : 'Inactivo' }} · Orden {{ $reason['display_order'] }}</p><div class="ui-admin-actions"><button class="ui-button ui-button--secondary" type="button" wire:click="edit('{{ $reason['id'] }}')" wire:loading.attr="disabled">Editar</button><button class="ui-button ui-button--secondary" type="button" wire:click="changeStatus('{{ $reason['id'] }}')" wire:loading.attr="disabled">{{ $reason['is_active'] ? 'Desactivar' : 'Activar' }}</button></div></article>
        @empty<p class="ui-reasons-empty">No hay motivos configurados para estos filtros. Retiro y cancelación permanecen bloqueados sin un motivo activo compatible.</p>@endforelse
    </div>
    <nav aria-label="Paginación de motivos" class="ui-admin-actions ui-reasons-pagination"><button class="ui-button ui-button--secondary" type="button" wire:click="$set('page', {{ max(1,$page-1) }})" @disabled($page<=1)>Anteriores</button><span>Página {{ $page }} · {{ $reasons['total'] }} motivos</span><button class="ui-button ui-button--secondary" type="button" wire:click="$set('page', {{ $page+1 }})" @disabled($page*10 >= $reasons['total'])>Siguientes</button></nav>
    <p class="ui-reasons-pending" wire:loading role="status">Procesando… Conservamos la intención.</p><p class="ui-reasons-offline" wire:offline role="alert">Sin conexión. Consulta el estado antes de preparar otro cambio.</p>
</section>
