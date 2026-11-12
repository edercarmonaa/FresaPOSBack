<section aria-label="Administración de categorías" class="space-y-5">
    @if($status)<p role="status" class="rounded bg-green-50 p-3 text-green-800">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded bg-red-50 p-3 text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="flex flex-wrap items-end gap-3">
        <label class="grow">Buscar por nombre<input type="search" wire:model.live.debounce.350ms="search" maxlength="100" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
        <label>Estado<select wire:model.live="stateFilter" class="mt-1 block rounded border border-slate-300 p-2"><option value="">Todos</option><option value="active">Activas</option><option value="inactive">Inactivas</option></select></label>
        <label>Por página<select wire:model.live="perPage" class="mt-1 block rounded border border-slate-300 p-2">@foreach([10,25,50,100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></label>
        <button type="button" wire:click="create" wire:loading.attr="disabled" class="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50">Crear categoría</button>
    </div>
    <p class="text-sm text-slate-600">Orden: prioridad ascendente, nombre e identificador. Edita la prioridad para cambiar el orden.</p>
    @if($formOpen)
        <form wire:submit="save" class="space-y-4 rounded border border-slate-200 bg-white p-5" aria-label="Formulario de categoría">
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Editar categoría' : 'Nueva categoría' }}</h2>
            <label class="block">Nombre<input wire:model="name" required maxlength="150" class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
            <label class="block">Descripción<textarea wire:model="description" maxlength="1000" rows="3" class="mt-1 block w-full rounded border border-slate-300 p-2"></textarea></label>
            <label class="block">Prioridad de visualización<input type="number" wire:model="displayOrder" min="0" max="2147483647" required class="mt-1 block w-full rounded border border-slate-300 p-2"></label>
            <label class="flex items-center gap-2"><input type="checkbox" wire:model="isActive"> Categoría activa</label>
            <div class="flex gap-3"><button type="submit" wire:loading.attr="disabled" class="rounded bg-blue-700 px-4 py-2 text-white disabled:opacity-50">Guardar categoría</button><button type="button" wire:click="closeForm" wire:loading.attr="disabled" class="rounded border px-4 py-2">Cancelar</button></div>
        </form>
    @endif
    <div wire:loading role="status" class="text-sm text-slate-600">Procesando…</div>
    <div class="overflow-x-auto rounded border border-slate-200 bg-white">
        <table class="w-full text-left text-sm"><caption class="sr-only">Listado de categorías</caption><thead class="bg-slate-100"><tr><th class="p-3">Nombre y descripción</th><th class="p-3">Estado</th><th class="p-3">Prioridad</th><th class="p-3">Acciones</th></tr></thead><tbody>
        @forelse($result->items as $category)
            <tr wire:key="category-{{ $category->id }}" class="border-t border-slate-200"><td class="p-3"><span class="font-medium">{{ $category->name }}</span>@if($category->description)<p class="text-slate-600">{{ $category->description }}</p>@endif</td><td class="p-3">{{ $category->isActive ? 'Activa' : 'Inactiva' }}</td><td class="p-3">{{ $category->displayOrder }}</td><td class="p-3"><div class="flex flex-wrap gap-2">
                <button type="button" wire:click="edit('{{ $category->id }}')" wire:loading.attr="disabled" class="rounded border px-3 py-2">Editar</button>
                <button type="button" wire:click="toggle('{{ $category->id }}')" wire:confirm="¿Cambiar el estado de esta categoría? Esto afecta su disponibilidad para ventas." wire:loading.attr="disabled" class="rounded border px-3 py-2">{{ $category->isActive ? 'Desactivar' : 'Activar' }}</button>
                <button type="button" wire:click="remove('{{ $category->id }}')" wire:confirm="¿Eliminar lógicamente esta categoría? Sus referencias históricas se conservarán. No hay restauración de categorías en esta entrega." wire:loading.attr="disabled" class="rounded border px-3 py-2 text-red-700">Eliminar</button>
            </div></td></tr>
        @empty<tr><td colspan="4" class="p-6 text-slate-600">No hay categorías para estos filtros.</td></tr>@endforelse
        </tbody></table>
    </div>
    @include('catalog::pagination')
</section>
