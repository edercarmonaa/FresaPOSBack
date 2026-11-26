<section aria-label="Administración de categorías" class="ui-catalog-module">
    @if($status)<p role="status" class="ui-catalog-status">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="ui-catalog-errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <x-ui.filters label="Filtros de catálogo">
        <label class="ui-catalog-label">Buscar por nombre<x-ui.input type="search" wire:model.live.debounce.350ms="search" maxlength="100" /></label>
        <label>Estado<x-ui.select wire:model.live="stateFilter"><option value="">Todos</option><option value="active">Activas</option><option value="inactive">Inactivas</option></x-ui.select></label>
        <label>Por página<x-ui.select wire:model.live="perPage">@foreach([10,25,50,100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</x-ui.select></label>
        <x-ui.button variant="primary" type="button" wire:click="create" wire:loading.attr="disabled">Crear categoría</x-ui.button>
    </x-ui.filters>
    <p class="text-sm text-slate-600">Orden: prioridad ascendente, nombre e identificador. Edita la prioridad para cambiar el orden.</p>
    @if($formOpen)
        <form wire:submit="save" class="ui-catalog-form" aria-label="Formulario de categoría">
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Editar categoría' : 'Nueva categoría' }}</h2>
            <label class="ui-catalog-label">Nombre<x-ui.input id="category-name" wire:model="name" required maxlength="150" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" aria-describedby="category-name-errors" /></label>
            @include('catalog::field-error', ['field' => 'name', 'id' => 'category-name-errors'])
            <label class="ui-catalog-label">Descripción<x-ui.textarea id="category-description" wire:model="description" maxlength="1000" rows="3" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" aria-describedby="category-description-errors"></x-ui.textarea></label>
            @include('catalog::field-error', ['field' => 'description', 'id' => 'category-description-errors'])
            <label class="ui-catalog-label">Prioridad de visualización<x-ui.input id="category-order" type="number" wire:model="displayOrder" min="0" max="2147483647" required aria-invalid="{{ $errors->hasAny(['displayOrder', 'display_order']) ? 'true' : 'false' }}" aria-describedby="category-order-errors" /></label>
            @include('catalog::field-error', ['field' => 'displayOrder', 'aliases' => ['display_order'], 'id' => 'category-order-errors'])
            <label class="ui-catalog-check"><input id="category-active" type="checkbox" wire:model="isActive" aria-invalid="{{ $errors->hasAny(['isActive', 'is_active']) ? 'true' : 'false' }}" aria-describedby="category-active-errors"> Categoría activa</label>
            @include('catalog::field-error', ['field' => 'isActive', 'aliases' => ['is_active'], 'id' => 'category-active-errors'])
            <div class="ui-admin-actions"><x-ui.button variant="primary" type="submit" wire:loading.attr="disabled">Guardar categoría</x-ui.button><x-ui.button variant="secondary" type="button" wire:click="closeForm" wire:loading.attr="disabled">Cancelar</x-ui.button></div>
        </form>
    @endif
    <div wire:loading role="status" class="text-sm text-slate-600">Procesando…</div>
    <x-ui.table class="overflow-x-auto" caption="Listado de categorías"><thead class="bg-slate-100"><tr><th scope="col">Nombre y descripción</th><th scope="col">Estado</th><th scope="col">Prioridad</th><th scope="col">Acciones</th></tr></thead><tbody>
        @forelse($result->items as $category)
            <tr wire:key="category-{{ $category->id }}" class="border-t border-slate-200"><td ><span class="font-medium">{{ $category->name }}</span>@if($category->description)<p class="text-slate-600">{{ $category->description }}</p>@endif</td><td >{{ $category->isActive ? 'Activa' : 'Inactiva' }}</td><td >{{ $category->displayOrder }}</td><td ><div class="ui-admin-actions">
                <x-ui.button variant="secondary" type="button" wire:click="edit('{{ $category->id }}')" wire:loading.attr="disabled">Editar</x-ui.button>
                <x-ui.button variant="secondary" type="button" wire:click="toggle('{{ $category->id }}')" wire:confirm="¿Cambiar el estado de esta categoría? Esto afecta su disponibilidad para ventas." wire:loading.attr="disabled">{{ $category->isActive ? 'Desactivar' : 'Activar' }}</x-ui.button>
                <x-ui.button variant="danger" type="button" wire:click="remove('{{ $category->id }}')" wire:confirm="¿Eliminar lógicamente esta categoría? Sus referencias históricas se conservarán. No hay restauración de categorías en esta entrega." wire:loading.attr="disabled">Eliminar</x-ui.button>
            </div></td></tr>
        @empty<tr><td colspan="4" class="ui-catalog-empty">No hay categorías para estos filtros.</td></tr>@endforelse
        </tbody></x-ui.table>
    @include('catalog::pagination')
</section>
