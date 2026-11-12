<section aria-label="Administración de productos" class="space-y-5">
    @if($status)<p role="status" class="rounded bg-green-50 p-3 text-green-800">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded bg-red-50 p-3 text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="flex flex-wrap items-end gap-3">
        <label class="grow">Buscar producto<input type="search" wire:model.live.debounce.350ms="search" maxlength="100" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
        <label>Estado<select wire:model.live="stateFilter" class="mt-1 block rounded border border-slate-300 p-2"><option value="">Todos</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select></label>
        <label>Por página<select wire:model.live="perPage" class="mt-1 block rounded border border-slate-300 p-2">@foreach([10,25,50,100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></label>
        @can('catalog.products.create')<button type="button" wire:click="create" wire:loading.attr="disabled" class="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50">Crear producto</button>@endcan
    </div>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label>Buscar categoría<input type="search" wire:model.live.debounce.350ms="categorySearch" maxlength="100" placeholder="Acota las primeras 100 coincidencias" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
        <label>Categoría<select wire:model.live="categoryFilter" class="mt-1 block w-full rounded border border-slate-300 p-2"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}{{ $category->isActive ? '' : ' (inactiva)' }}</option>@endforeach</select></label>
        <label>Ordenar por<select wire:model.live="sort" class="mt-1 block w-full rounded border border-slate-300 p-2"><option value="name">Nombre</option><option value="price">Precio</option></select></label>
        <label>Dirección<select wire:model.live="direction" class="mt-1 block w-full rounded border border-slate-300 p-2"><option value="asc">Ascendente</option><option value="desc">Descendente</option></select></label>
    </div>
    @can('catalog.products.restore')<label class="block">Vista<select wire:model.live="deleted" class="ml-2 rounded border border-slate-300 p-2"><option value="without">Productos vigentes</option><option value="only">Papelera (solo eliminados)</option></select></label>@endcan
    @if($formOpen)
        <form wire:submit="save" class="space-y-4 rounded border border-slate-200 bg-white p-5" aria-label="Formulario de producto">
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Editar producto' : 'Nuevo producto' }}</h2>
            <label class="block">Nombre<input wire:model="name" required maxlength="150" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
            <label class="block">Descripción<textarea wire:model="description" maxlength="2000" rows="3" class="mt-1 block w-full rounded border border-slate-300 p-2"></textarea></label>
            <label class="block">Categoría (activa para nuevas asignaciones)<select wire:model="categoryId" required class="mt-1 block w-full rounded border border-slate-300 p-2"><option value="">Selecciona una categoría</option>@foreach($activeCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                @if($originalCategory && !collect($activeCategories)->contains('id', $originalCategory->id))<option value="{{ $originalCategory->id }}">{{ $originalCategory->name }} (actual)</option>@endif
            </select></label>
            <p class="text-sm text-slate-600">Usa «Buscar categoría» para acotar las opciones de asignación.</p>
            <label class="block">Precio<input type="text" inputmode="decimal" wire:model="price" required placeholder="0.0000" aria-describedby="price-help" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
            <p id="price-help" class="text-sm text-slate-600">Punto decimal, hasta 4 decimales y sin separadores de miles.</p>
            @if(!$editingId || auth('web')->user()->can('catalog.products.deactivate'))<label class="flex items-center gap-2"><input type="checkbox" wire:model="isActive"> Producto activo</label>@endif
            <p class="text-sm text-slate-600">Las imágenes existentes se conservan. Su carga estará disponible en una entrega posterior.</p>
            <div class="flex gap-3"><button type="submit" wire:loading.attr="disabled" class="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50">Guardar producto</button><button type="button" wire:click="closeForm" wire:loading.attr="disabled" class="rounded border px-4 py-2">Cancelar</button></div>
        </form>
    @endif
    <div wire:loading role="status" class="text-sm text-slate-600">Procesando…</div>
    <div class="overflow-x-auto rounded border border-slate-200 bg-white">
        <table class="w-full text-left text-sm"><caption class="sr-only">Listado de productos</caption><thead class="bg-slate-100"><tr><th class="p-3">Imagen</th><th class="p-3">Producto</th><th class="p-3">Categoría</th><th class="p-3">Precio</th><th class="p-3">Estado</th><th class="p-3">Acciones</th></tr></thead><tbody>
        @forelse($items as $product)
            <tr wire:key="product-{{ $product['id'] }}" class="border-t border-slate-200"><td class="p-3">@if($product['image'])<img src="{{ $product['image']['url'] }}" alt="{{ $product['name'] }}" loading="lazy" class="h-20 w-20 rounded object-contain">@else<div class="flex min-h-20 w-24 items-center justify-center rounded bg-slate-100 p-2 text-center text-xs">{{ $product['name'] }}</div>@endif</td><td class="p-3"><span class="font-medium">{{ $product['name'] }}</span>@if($product['description'])<p class="text-slate-600">{{ $product['description'] }}</p>@endif</td><td class="p-3">{{ $product['category']['name'] }}</td><td class="whitespace-nowrap p-3">{{ \Modules\Catalog\Http\CatalogPresentation::money($product['price']) }}</td><td class="p-3">{{ $product['deleted_at'] ? 'Eliminado' : ($product['is_active'] ? 'Activo' : 'Inactivo') }}</td><td class="p-3"><div class="flex flex-wrap gap-2">
                @if($product['deleted_at'])
                    @can('catalog.products.restore')<button type="button" wire:click="restore('{{ $product['id'] }}')" wire:confirm="¿Restaurar y activar este producto? Su categoría debe estar disponible." wire:loading.attr="disabled" class="rounded border px-3 py-2">Restaurar</button>@endcan
                @else
                    @can('catalog.products.update')<button type="button" wire:click="edit('{{ $product['id'] }}')" wire:loading.attr="disabled" class="rounded border px-3 py-2">Editar</button>@endcan
                    @can('catalog.products.deactivate')<button type="button" wire:click="toggle('{{ $product['id'] }}')" wire:confirm="¿Cambiar el estado de este producto?" wire:loading.attr="disabled" class="rounded border px-3 py-2">{{ $product['is_active'] ? 'Desactivar' : 'Activar' }}</button>
                    <button type="button" wire:click="remove('{{ $product['id'] }}')" wire:confirm="¿Enviar este producto a la papelera? Se conservarán sus referencias históricas." wire:loading.attr="disabled" class="rounded border px-3 py-2 text-red-700">Eliminar</button>@endcan
                @endif
            </div></td></tr>
        @empty<tr><td colspan="6" class="p-6 text-slate-600">No hay productos para estos filtros.</td></tr>@endforelse
        </tbody></table>
    </div>
    @include('catalog::pagination')
</section>
