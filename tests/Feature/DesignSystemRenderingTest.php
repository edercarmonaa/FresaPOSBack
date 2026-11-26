<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DesignSystemRenderingTest extends TestCase
{
    public function test_button_preserves_livewire_attributes_and_defaults_to_non_submit(): void
    {
        $html = Blade::render('<x-ui.button wire:click="save" wire:loading.attr="disabled" class="caller">Guardar</x-ui.button>');

        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('wire:click="save"', $html);
        $this->assertStringContainsString('wire:loading.attr="disabled"', $html);
        $this->assertStringContainsString('caller', $html);
        $this->assertStringContainsString('ui-button--primary', $html);
    }

    public function test_explicit_submit_and_disabled_are_preserved(): void
    {
        $html = Blade::render('<x-ui.button type="submit" disabled variant="danger">Confirmar</x-ui.button>');

        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('ui-button--danger', $html);
    }

    public function test_field_preserves_input_contract_and_escapes_error_text(): void
    {
        $html = Blade::render('<x-ui.field input-id="probe" label="Nombre" :error="$error"><x-ui.input id="probe" name="product_name" wire:model="productName" aria-describedby="probe-error" :invalid="true" /></x-ui.field>', ['error' => '<script>alert(1)</script>']);

        foreach (['for="probe"', 'id="probe"', 'name="product_name"', 'wire:model="productName"', 'aria-describedby="probe-error"', 'aria-invalid="true"', 'id="probe-error"'] as $attribute) {
            $this->assertStringContainsString($attribute, $html);
        }
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_unknown_variants_fall_back_and_titles_are_escaped(): void
    {
        $html = Blade::render('<x-ui.alert tone="unknown" :title="$title">Información</x-ui.alert><x-ui.badge tone="unknown">Estado</x-ui.badge>', ['title' => '<img src=x onerror=alert(1)>']);

        $this->assertStringContainsString('ui-alert--info', $html);
        $this->assertStringContainsString('ui-badge--neutral', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_table_has_caption_and_keyboard_accessible_scroll_region(): void
    {
        $html = Blade::render('<x-ui.table caption="Muestra"><thead><tr><th scope="col">Nombre</th></tr></thead><tbody><tr><td>Ejemplo</td></tr></tbody></x-ui.table>');

        foreach (['<caption>Muestra</caption>', 'tabindex="0"', 'role="region"', 'aria-label="Muestra"', 'scope="col"'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_existing_application_loads_the_local_design_system_in_ui03(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('/css/ui/tokens.css', false)
            ->assertSee('/css/ui/components.css', false)
            ->assertSee('/css/ui/layouts.css', false)
            ->assertSee('/js/ui/theme.js', false)
            ->assertSee('/js/ui/layout.js', false)
            ->assertSee('ui-root', false);
    }
}
