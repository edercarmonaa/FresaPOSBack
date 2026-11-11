<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Livewire\LivewireServiceProvider;
use Nwidart\Modules\LaravelModulesServiceProvider;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    public function test_application_and_layout_boot_with_local_livewire_assets(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('FresaPOS')
            ->assertSee('https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18', false)
            ->assertSee('data-csrf', false);

        $this->assertTrue($this->app->providerIsLoaded(LivewireServiceProvider::class));
        $this->assertMatchesRegularExpression('~src="([^"]*livewire[^\"]*\.js[^\"]*)"~', $response->getContent());
        preg_match('~src="([^"]*livewire[^\"]*\.js[^\"]*)"~', $response->getContent(), $matches);
        $asset = html_entity_decode($matches[1]);
        $this->assertFalse(str_contains($asset, 'cdn.'));
        $this->get($asset)->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }

    public function test_modules_provider_and_command_are_available(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(LaravelModulesServiceProvider::class));
        $this->assertSame(0, Artisan::call('module:list'));
        $this->assertCount(13, $this->app->make('modules')->allEnabled());
    }

    public function test_health_endpoint(): void
    {
        $this->get('/up')->assertOk();
    }
}
