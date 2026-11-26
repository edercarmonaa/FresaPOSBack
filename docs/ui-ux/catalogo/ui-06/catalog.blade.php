@extends('ui06qa::layout')
@section('content')

<main id="ui-main" class="qa-shell" tabindex="-1">
<header class="qa-top"><div><p class="ui-muted">UI-06 · Catálogo documental · Datos ficticios</p><h1 class="ui-heading">Componentes administrativos</h1><p>Sin sesión, conexión SQL u operaciones reales. Integración de módulos pendiente de UI-07.</p></div><label class="ui-field">Tema<select class="ui-select" data-ui-theme-select><option value="auto">Automático</option><option value="light">Claro</option><option value="dark">Oscuro</option></select></label></header>
<x-ui.notification title="Muestra persistente" tone="info">Este mensaje es documental. No informa una operación real ni desaparece automáticamente.</x-ui.notification>
<section class="qa-stack" aria-labelledby="forms-heading"><h2 class="qa-heading" id="forms-heading">Formulario y filtros</h2>
<form id="qa-form" class="qa-demo-form" action="/_qa/no-operation" method="post">
<x-ui.filters label="Filtros de ejemplo">
    <x-ui.field input-id="qa-search" label="Buscar" hint="Campo de muestra, sin consulta."><x-ui.input id="qa-search" name="search" type="search" aria-describedby="qa-search-help" maxlength="100" wire:model.live.debounce.350ms="search" /></x-ui.field>
    <x-ui.field input-id="qa-state" label="Estado"><x-ui.select id="qa-state" name="state" wire:model.live="stateFilter"><option value="">Todos</option><option value="active">Activo</option><option value="inactive">Inactivo</option></x-ui.select></x-ui.field>
    <x-ui.field input-id="qa-name" label="Nombre" :required="true"><x-ui.input id="qa-name" name="name" required maxlength="150" wire:model="name" /></x-ui.field>
    <x-ui.field input-id="qa-description" label="Descripción"><x-ui.textarea id="qa-description" name="description" maxlength="1000" wire:model="description">Texto ficticio editable; no se guarda.</x-ui.textarea></x-ui.field>
    <x-ui.field input-id="qa-invalid" label="Campo con error de muestra" error="Mensaje ficticio de validación."><x-ui.input id="qa-invalid" name="invalid" :invalid="true" aria-describedby="qa-invalid-error" /></x-ui.field>
    <x-ui.field input-id="qa-disabled" label="Campo deshabilitado"><x-ui.input id="qa-disabled" name="disabled" disabled value="No disponible" /></x-ui.field>
    <x-slot:actions><x-ui.button type="submit">Validar muestra local</x-ui.button><x-ui.button variant="secondary" type="reset">Restablecer campos</x-ui.button></x-slot:actions>
</x-ui.filters>
</form></section>
<section aria-labelledby="table-heading"><h2 class="qa-heading" id="table-heading">Tabla, densidad y paginación</h2>
<label class="ui-field" style="max-width:240px;margin-bottom:20px">Densidad<select class="ui-select" data-ui-preference="density"><option value="standard">Estándar</option><option value="compact">Compacta</option><option value="comfortable">Cómoda</option></select></label>
<x-ui.table caption="Filas ficticias para comprobar el desplazamiento"><thead><tr><th scope="col">Identificador</th><th scope="col">Nombre</th><th scope="col">Estado</th><th scope="col">Cantidad de muestra</th><th scope="col">Acciones de muestra</th></tr></thead><tbody>
@foreach(['Ejemplo de nombre largo que conserva todo su texto','Segundo registro ficticio'] as $index=>$name)<tr><td>UI06-{{ $index+1 }}</td><td>{{ $name }}</td><td><x-ui.badge tone="success">Activo</x-ui.badge></td><td class="ui-numeric">{{ $index+1 }}</td><td><x-ui.button id="open-drawer-{{ $index }}" variant="secondary" data-ui-dialog-open="qa-drawer">Ver panel de muestra</x-ui.button></td></tr>@endforeach
</tbody></x-ui.table>
<x-ui.pagination label="Paginación documental"><x-slot:summary>1–2 de 2 · Página 1 de 1 (muestra)</x-slot:summary><x-ui.button variant="secondary" disabled wire:click="gotoPage(1)">Anterior</x-ui.button><x-ui.button variant="secondary" disabled wire:click="gotoPage(2)">Siguiente</x-ui.button></x-ui.pagination>
</section>
<section aria-labelledby="dialogs-heading"><h2 class="qa-heading" id="dialogs-heading">Modal y panel lateral</h2><div class="ui-admin-actions"><x-ui.button id="open-modal" data-ui-dialog-open="qa-modal">Abrir modal de muestra</x-ui.button><x-ui.button id="open-drawer" variant="secondary" data-ui-dialog-open="qa-drawer">Abrir panel lateral</x-ui.button><x-ui.button id="plain-action" variant="secondary">Botón sin comportamiento añadido</x-ui.button></div></section>
<div class="qa-grid"><x-ui.state title="Sin resultados de muestra" description="El estado vacío no añade registros ni cambia filtros." /><x-ui.state kind="loading" title="Estado de carga de muestra" description="Ejemplo estático; no hay una consulta en curso." /><x-ui.state kind="error" title="Error de muestra" description="No corresponde a una operación real."><x-ui.button variant="secondary" id="qa-retry">Acción proporcionada por el consumidor</x-ui.button></x-ui.state></div>
<x-ui.notification id="notice-dismissible" title="Mensaje cerrable de muestra" tone="success" :dismissible="true" return-focus="plain-action">Su cierre es exclusivamente visual y requiere una decisión explícita del consumidor.</x-ui.notification>
<x-ui.notification title="Advertencia de muestra" tone="warning">El color acompaña un texto explícito.</x-ui.notification>
<x-ui.notification title="Error de muestra" tone="error">Los mensajes críticos son persistentes por defecto.</x-ui.notification>
<x-ui.notification id="floating-notice" title="Notificación flotante de muestra" :floating="true" :dismissible="true" return-focus="plain-action" hidden>Estado ilustrativo; sin autodescarte ni temporizador.</x-ui.notification>
</main>
<x-ui.dialog id="qa-modal" title="Modal de muestra" description="Solo presentación. Escape y el botón de cierre regresan al origen."><x-ui.field input-id="modal-name" label="Nombre de muestra"><x-ui.input id="modal-name" name="modal_name" wire:model="name" /></x-ui.field><a class="ui-button ui-button--secondary" href="#qa-name">Enlace de muestra</a><x-slot:footer><x-ui.button variant="secondary" data-ui-dialog-close>Volver</x-ui.button></x-slot:footer></x-ui.dialog>
<x-ui.dialog id="qa-drawer" title="Panel lateral de muestra" description="Datos ficticios. No edita registros." variant="drawer"><p>La interacción abre y cierra únicamente este panel.</p><x-ui.field input-id="drawer-description" label="Descripción"><x-ui.textarea id="drawer-description" name="drawer_description" /></x-ui.field><x-slot:footer><x-ui.button variant="secondary" data-ui-dialog-close>Cerrar panel</x-ui.button></x-slot:footer></x-ui.dialog>

@endsection
@push('scripts')
<script>document.getElementById('qa-form').addEventListener('submit',event=>event.preventDefault());</script>
@endpush
