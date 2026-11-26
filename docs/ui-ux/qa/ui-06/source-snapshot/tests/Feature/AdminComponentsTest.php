<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AdminComponentsTest extends TestCase
{
    public function test_select_and_textarea_preserve_native_and_livewire_attributes(): void
    {
        $html = Blade::render('<x-ui.select name="state" wire:model.live="stateFilter" required disabled><option value="active" selected>Activa</option></x-ui.select><x-ui.textarea name="description" wire:model="description" maxlength="1000" :invalid="true" aria-describedby="description-error">{{ $text }}</x-ui.textarea>', ['text' => '<script>unsafe()</script>']);
        foreach (['name="state"', 'wire:model.live="stateFilter"', 'required', 'disabled', 'value="active" selected', 'name="description"', 'wire:model="description"', 'maxlength="1000"', 'aria-invalid="true"', 'aria-describedby="description-error"', '&lt;script&gt;unsafe()&lt;/script&gt;'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('<script>unsafe()', $html);
    }

    public function test_dialog_preserves_content_and_has_named_close_without_submit(): void
    {
        $html = Blade::render('<x-ui.dialog id="edit" title="Editar" description="Descripción" variant="drawer" wire:ignore.self><input name="label" wire:model="label"><x-slot:footer><x-ui.button wire:click="save">Guardar</x-ui.button></x-slot:footer></x-ui.dialog>');
        foreach (['<dialog id="edit"', 'ui-admin-dialog--drawer', 'wire:ignore.self', 'aria-labelledby="edit-title"', 'id="edit-title"', 'aria-describedby="edit-description"', 'id="edit-description"', 'data-ui-dialog-close', 'type="button"', 'name="label"', 'wire:model="label"', 'wire:click="save"'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('type="submit"', $html);
        $this->assertStringNotContainsString(' open', $html);
    }

    public function test_unknown_dialog_variant_falls_back_and_text_is_escaped(): void
    {
        $html = Blade::render('<x-ui.dialog id="probe" :title="$title" variant="unknown">Contenido</x-ui.dialog>', ['title' => '<img src=x onerror=bad()>']);
        $this->assertStringContainsString('ui-admin-dialog--modal', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('aria-describedby', $html);
    }

    public function test_assets_are_opt_in_and_pushed_once_for_multiple_components(): void
    {
        $html = Blade::render('<x-ui.filters>Filtros</x-ui.filters><x-ui.pagination>Controles</x-ui.pagination><x-ui.state title="Vacío" />@stack("styles")@stack("scripts")');
        $this->assertSame(1, substr_count($html, '/css/ui/admin.css'));
        $this->assertSame(1, substr_count($html, '/js/ui/admin.js'));
        $this->get('/')->assertOk()->assertDontSee('/css/ui/admin.css', false)->assertDontSee('/js/ui/admin.js', false);
    }

    public function test_pagination_and_filters_preserve_caller_actions_without_generating_them(): void
    {
        $html = Blade::render('<x-ui.filters label="Buscar"><input name="query" wire:model.live.debounce.350ms="search"><x-slot:actions><x-ui.button wire:click="resetFilters">Limpiar</x-ui.button></x-slot:actions></x-ui.filters><x-ui.pagination label="Productos"><x-slot:summary>Página 2 de 3</x-slot:summary><x-ui.button wire:click="gotoPage(1)" wire:loading.attr="disabled">Anterior</x-ui.button><x-ui.button disabled>Siguiente</x-ui.button></x-ui.pagination>');
        foreach (['aria-label="Buscar"', 'name="query"', 'wire:model.live.debounce.350ms="search"', 'wire:click="resetFilters"', 'aria-label="Productos"', 'Página 2 de 3', 'wire:click="gotoPage(1)"', 'wire:loading.attr="disabled"', 'disabled'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_notifications_are_persistent_by_default_and_live_only_when_requested(): void
    {
        $default = Blade::render('<x-ui.notification title="Información">Mensaje</x-ui.notification>');
        $this->assertStringContainsString('aria-live="off"', $default);
        $this->assertStringNotContainsString('data-ui-notice-dismiss', $default);
        $explicit = Blade::render('<x-ui.notification :dismissible="true" :floating="true" live="assertive" return-focus="origin">{{ $message }}</x-ui.notification>', ['message' => '<script>unsafe()</script>']);
        foreach (['data-ui-notice-dismiss', 'ui-admin-notice--floating', 'aria-live="assertive"', 'data-ui-notice-return="origin"', '&lt;script&gt;'] as $value) {
            $this->assertStringContainsString($value, $explicit);
        }
        $this->assertStringNotContainsString('<script>unsafe()', $explicit);
    }

    public function test_loading_and_error_are_explicit_presentation_states(): void
    {
        $empty = Blade::render('<x-ui.state title="Sin resultados" />');
        $loading = Blade::render('<x-ui.state title="Consultando" kind="loading" />');
        $error = Blade::render('<x-ui.state title="Error" kind="error"><x-ui.button wire:click="retry">Reintentar</x-ui.button></x-ui.state>');
        $this->assertStringNotContainsString('aria-busy', $empty);
        $this->assertStringContainsString('aria-busy="true"', $loading);
        $this->assertStringContainsString('aria-hidden="true"', $loading);
        $this->assertSame(3, substr_count($loading, '<span></span>'));
        $this->assertStringContainsString('ui-admin-state--error', $error);
        $this->assertStringContainsString('wire:click="retry"', $error);
    }
}
