<section aria-label="Administración de productos" class="ui-catalog-module">
    @if($status)<p role="status" class="ui-catalog-status">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="ui-catalog-errors"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <x-ui.filters label="Filtros de catálogo">
        <label class="ui-catalog-label">Buscar producto<x-ui.input type="search" wire:model.live.debounce.350ms="search" maxlength="100" /></label>
        <label>Estado<x-ui.select wire:model.live="stateFilter"><option value="">Todos</option><option value="active">Activos</option><option value="inactive">Inactivos</option></x-ui.select></label>
        <label>Por página<x-ui.select wire:model.live="perPage">@foreach([10,25,50,100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</x-ui.select></label>
        @can('catalog.products.create')<x-ui.button variant="primary" type="button" wire:click="create" wire:loading.attr="disabled">Crear producto</x-ui.button>@endcan
    </x-ui.filters>
    <x-ui.filters label="Categoría y orden">
        <label>Buscar categoría<x-ui.input type="search" wire:model.live.debounce.350ms="categorySearch" maxlength="100" placeholder="Acota las primeras 100 coincidencias" /></label>
        <label>Categoría<x-ui.select wire:model.live="categoryFilter"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}{{ $category->isActive ? '' : ' (inactiva)' }}</option>@endforeach</x-ui.select></label>
        <label>Ordenar por<x-ui.select wire:model.live="sort"><option value="name">Nombre</option><option value="price">Precio</option></x-ui.select></label>
        <label>Dirección<x-ui.select wire:model.live="direction"><option value="asc">Ascendente</option><option value="desc">Descendente</option></x-ui.select></label>
    </x-ui.filters>
    @can('catalog.products.restore')<label class="ui-catalog-label">Vista<x-ui.select wire:model.live="deleted"><option value="without">Productos vigentes</option><option value="only">Papelera (solo eliminados)</option></x-ui.select></label>@endcan
    @if($formOpen)
        <form wire:submit="save" class="ui-catalog-form" aria-label="Formulario de producto">
            <h2 class="text-lg font-semibold">{{ $editingId ? 'Editar producto' : 'Nuevo producto' }}</h2>
            <label class="ui-catalog-label">Nombre<x-ui.input id="product-name" wire:model="name" required maxlength="150" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" aria-describedby="product-name-errors" /></label>
            @include('catalog::field-error', ['field' => 'name', 'id' => 'product-name-errors'])
            <label class="ui-catalog-label">Descripción<x-ui.textarea id="product-description" wire:model="description" maxlength="2000" rows="3" aria-invalid="{{ $errors->has('description') ? 'true' : 'false' }}" aria-describedby="product-description-errors"></x-ui.textarea></label>
            @include('catalog::field-error', ['field' => 'description', 'id' => 'product-description-errors'])
            <label class="ui-catalog-label">Categoría (activa para nuevas asignaciones)<x-ui.select id="product-category" wire:model="categoryId" required aria-invalid="{{ $errors->hasAny(['categoryId', 'category_id']) ? 'true' : 'false' }}" aria-describedby="product-category-errors"><option value="">Selecciona una categoría</option>@foreach($activeCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                @if($originalCategory && !collect($activeCategories)->contains('id', $originalCategory->id))<option value="{{ $originalCategory->id }}">{{ $originalCategory->name }} (actual)</option>@endif
            </x-ui.select></label>
            @include('catalog::field-error', ['field' => 'categoryId', 'aliases' => ['category_id'], 'id' => 'product-category-errors'])
            <p class="text-sm text-slate-600">Usa «Buscar categoría» para acotar las opciones de asignación.</p>
            <label class="ui-catalog-label">Precio<x-ui.input id="product-price" type="text" inputmode="decimal" wire:model="price" required placeholder="0.0000" aria-invalid="{{ $errors->has('price') ? 'true' : 'false' }}" aria-describedby="price-help product-price-errors" /></label>
            @include('catalog::field-error', ['field' => 'price', 'id' => 'product-price-errors'])
            <p id="price-help" class="text-sm text-slate-600">Punto decimal, hasta 4 decimales y sin separadores de miles.</p>
            @if(!$editingId || auth('web')->user()->can('catalog.products.deactivate'))<label class="ui-catalog-check"><input id="product-active" type="checkbox" wire:model="isActive" aria-invalid="{{ $errors->hasAny(['isActive', 'is_active']) ? 'true' : 'false' }}" aria-describedby="product-active-errors"> Producto activo</label>
                @include('catalog::field-error', ['field' => 'isActive', 'aliases' => ['is_active'], 'id' => 'product-active-errors'])
            @endif
            @can('catalog.products.update')
                <label class="ui-catalog-label">Imagen principal (opcional)<x-ui.input id="product-image" type="file" wire:model="image" accept="image/jpeg,image/png,image/webp" aria-invalid="{{ $errors->has('image') ? 'true' : 'false' }}" aria-describedby="image-help product-image-errors" /></label>
                @include('catalog::field-error', ['field' => 'image', 'id' => 'product-image-errors'])
                <p id="image-help" class="text-sm text-slate-600">JPEG, PNG o WebP, máximo 5 MB. Se optimiza al guardar. Seleccionar otra imagen sustituirá la actual.</p>
                @if($imagePreview)<img src="{{ $imagePreview }}" alt="Vista previa de la imagen seleccionada" class="ui-catalog-image"><x-ui.button variant="secondary" type="button" wire:click="clearUpload" wire:loading.attr="disabled">Descartar selección</x-ui.button>
                @elseif($currentImageUrl)<img src="{{ $currentImageUrl }}" alt="Imagen actual de {{ $name }}" class="ui-catalog-image">@endif
                @if($currentImageUrl && $editingId)<x-ui.button variant="danger" type="button" wire:click="removeImage" wire:confirm="¿Eliminar la imagen de este producto? Los otros campos del formulario no se guardarán con esta acción." wire:loading.attr="disabled">Eliminar imagen</x-ui.button>@endif
                <span wire:loading wire:target="image" role="status">Subiendo imagen…</span>
            @endcan
            <div class="ui-admin-actions"><x-ui.button variant="primary" type="submit" wire:loading.attr="disabled">Guardar producto</x-ui.button><x-ui.button variant="secondary" type="button" wire:click="closeForm" wire:loading.attr="disabled">Cancelar</x-ui.button></div>
        </form>
    @endif
    <div wire:loading role="status" class="text-sm text-slate-600">Procesando…</div>
    <x-ui.table class="overflow-x-auto" caption="Listado de productos"><thead class="bg-slate-100"><tr><th scope="col">Imagen</th><th scope="col">Producto</th><th scope="col">Categoría</th><th scope="col">Precio</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
        @forelse($items as $product)
            <tr wire:key="product-{{ $product['id'] }}" class="border-t border-slate-200"><td >@if($product['image'])<img src="{{ $product['image']['url'] }}" alt="{{ $product['name'] }}" loading="lazy" class="ui-catalog-image">@else<div class="ui-catalog-image-placeholder">{{ $product['name'] }}</div>@endif</td><td ><span class="font-medium">{{ $product['name'] }}</span>@if($product['description'])<p class="text-slate-600">{{ $product['description'] }}</p>@endif</td><td >{{ $product['category']['name'] }}</td><td class="ui-numeric">{{ \Modules\Catalog\Http\CatalogPresentation::money($product['price']) }}</td><td >{{ $product['deleted_at'] ? 'Eliminado' : ($product['is_active'] ? 'Activo' : 'Inactivo') }}</td><td ><div class="ui-admin-actions">
                @if($product['deleted_at'])
                    @can('catalog.products.restore')<x-ui.button variant="secondary" type="button" wire:click="restore('{{ $product['id'] }}')" wire:confirm="¿Restaurar y activar este producto? Su categoría debe estar disponible." wire:loading.attr="disabled">Restaurar</x-ui.button>@endcan
                @else
                    @can('catalog.products.update')<x-ui.button variant="secondary" type="button" wire:click="edit('{{ $product['id'] }}')" wire:loading.attr="disabled">Editar</x-ui.button>@endcan
                    @can('catalog.products.deactivate')<x-ui.button variant="secondary" type="button" wire:click="toggle('{{ $product['id'] }}')" wire:confirm="¿Cambiar el estado de este producto?" wire:loading.attr="disabled">{{ $product['is_active'] ? 'Desactivar' : 'Activar' }}</x-ui.button>
                    <x-ui.button variant="danger" type="button" wire:click="remove('{{ $product['id'] }}')" wire:confirm="¿Enviar este producto a la papelera? Se conservarán sus referencias históricas." wire:loading.attr="disabled">Eliminar</x-ui.button>@endcan
                @endif
            </div></td></tr>
        @empty<tr><td colspan="6" class="ui-catalog-empty">No hay productos para estos filtros.</td></tr>@endforelse
        </tbody></x-ui.table>
    @include('catalog::pagination')
</section>
