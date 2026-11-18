<section class="space-y-5 reasons-admin" aria-labelledby="reasons-title">
    <style>.reasons-admin button,.reasons-admin input,.reasons-admin select{min-height:44px}.reasons-admin input,.reasons-admin select,.reasons-admin textarea{width:100%;min-width:0;border:1px solid #94a3b8;border-radius:8px;padding:10px}.reasons-admin button{padding:10px 16px;border:1px solid #94a3b8;border-radius:8px}.reasons-admin fieldset{min-width:0}.reasons-admin code{overflow-wrap:anywhere}</style>
    <header><h1 id="reasons-title" class="text-3xl font-bold">Motivos autorizados de ventas</h1><p>Configura los motivos que podrán utilizar los empleados autorizados. El código y la operación se conservan para siempre.</p></header>
    @if($status)<p role="status" class="rounded bg-blue-50 p-4">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded bg-red-50 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <button type="button" wire:click="create" wire:loading.attr="disabled">Crear motivo</button>
    @if($submitted)<button type="button" wire:click="queryOperation" wire:loading.attr="disabled">Consultar estado de la operación</button>@endif
    @if($formOpen)
        <form wire:submit="save" class="space-y-4 rounded border bg-white p-4" aria-label="Formulario de motivo">
            @if($action==='status')
                <p>Confirma {{ ($frozen['is_active'] ?? false) ? 'activar' : 'desactivar' }} el motivo <strong>{{ $name }}</strong>. Las operaciones históricas no se modifican.</p>
            @else
                <fieldset @disabled($submitted) class="space-y-3">
                    <label class="block" for="reason-code">Código estable<input id="reason-code" wire:model="code" maxlength="64" @disabled($action!=='create') autocomplete="off"></label>
                    <label class="block" for="reason-operation">Operación<select id="reason-operation" wire:model="operation" @disabled($action!=='create')><option value="sale_item_removal">Retirar producto</option><option value="sale_cancellation">Cancelar venta no pagada</option></select></label>
                    <label class="block" for="reason-name">Nombre<input id="reason-name" wire:model="name" maxlength="150" required></label>
                    <label class="block" for="reason-description">Descripción opcional<textarea id="reason-description" wire:model="description" maxlength="500" rows="3"></textarea></label>
                    <label class="block" for="reason-order">Orden<input id="reason-order" wire:model="displayOrder" type="number" min="0" max="2147483647" required></label>
                </fieldset>
                <p>Crear un motivo lo deja activo. Los cambios solo se aplican a operaciones nuevas.</p>
            @endif
            <button type="submit" wire:confirm="¿Confirmas este cambio del catálogo de motivos?" wire:loading.attr="disabled">Confirmar motivo</button>
        </form>
    @endif
    <div class="grid gap-3 sm:grid-cols-2">
        <label for="reason-filter-operation">Operación<select id="reason-filter-operation" wire:model.live="filterOperation"><option value="">Todas</option><option value="sale_item_removal">Retiro de producto</option><option value="sale_cancellation">Cancelación</option></select></label>
        <label for="reason-filter-active">Estado<select id="reason-filter-active" wire:model.live="filterActive"><option value="">Todos</option><option value="active">Activo</option><option value="inactive">Inactivo</option></select></label>
    </div>
    <div class="space-y-3">
        @forelse($reasons['items'] as $reason)
            <article class="rounded border bg-white p-4" wire:key="reason-{{ $reason['id'] }}"><h2 class="text-xl font-bold">{{ $reason['name'] }}</h2><p><code>{{ $reason['code'] }}</code> · {{ $reason['operation']==='sale_item_removal' ? 'Retiro de producto' : 'Cancelación de venta' }}</p><p>{{ $reason['description'] }}</p><p>{{ $reason['is_active'] ? 'Activo' : 'Inactivo' }} · Orden {{ $reason['display_order'] }}</p><div class="mt-3 flex flex-wrap gap-3"><button type="button" wire:click="edit('{{ $reason['id'] }}')" wire:loading.attr="disabled">Editar</button><button type="button" wire:click="changeStatus('{{ $reason['id'] }}')" wire:loading.attr="disabled">{{ $reason['is_active'] ? 'Desactivar' : 'Activar' }}</button></div></article>
        @empty<p>No hay motivos configurados para estos filtros. Retiro y cancelación permanecen bloqueados sin un motivo activo compatible.</p>@endforelse
    </div>
    <nav aria-label="Paginación de motivos" class="flex flex-wrap gap-3"><button type="button" wire:click="$set('page', {{ max(1,$page-1) }})" @disabled($page<=1)>Anteriores</button><span>Página {{ $page }} · {{ $reasons['total'] }} motivos</span><button type="button" wire:click="$set('page', {{ $page+1 }})" @disabled($page*10 >= $reasons['total'])>Siguientes</button></nav>
    <p wire:loading role="status">Procesando… Conservamos la intención.</p><p wire:offline role="alert">Sin conexión. Consulta el estado antes de preparar otro cambio.</p>
</section>
