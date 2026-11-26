<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Modules\Users\Infrastructure\Persistence\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LayoutNavigationTest extends TestCase
{
    public static function permissionCases(): array
    {
        return [
            'none' => [[]],
            'sales only' => [['sales.view']],
            'catalog read only' => [['catalog.products.view']],
            'cashier' => [['sales.view', 'catalog.products.view', 'cash.view']],
            'category manager' => [['catalog.categories.manage']],
            'product editor' => [['catalog.products.view', 'catalog.products.update']],
            'product action without view' => [['catalog.products.create']],
            'reports only' => [['reports.view']],
            'settings only' => [['settings.update']],
            'reasons' => [['settings.update', 'sales.view']],
            'users only' => [['users.view']],
            'administrator' => [['sales.view', 'catalog.products.view', 'cash.view', 'catalog.categories.manage', 'catalog.products.create', 'settings.update', 'reports.view', 'users.view']],
        ];
    }

    #[DataProvider('permissionCases')]
    public function test_navigation_destinations_and_visibility_match_legacy_for_each_context(array $abilities): void
    {
        $user = new User;
        $user->forceFill(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'UI fixture', 'username' => 'ui_fixture']);
        $this->actingAs($user, 'web');
        // Gate aislado: no roles, permisos ni usuarios se consultan o escriben en SQL.
        Gate::swap(new AccessGate($this->app, fn () => $user));
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true));

        foreach (['catalog.products', 'sales.pos', 'auth.password'] as $routeName) {
            request()->setRouteResolver(fn () => (new Route('GET', '/', fn () => null))->name($routeName));
            $legacy = '';
            foreach (['sales-navigation', 'cash-navigation', ...($routeName === 'sales.pos' ? [] : ['catalog-navigation', 'reasons-navigation', 'reports-navigation'])] as $fixture) {
                $legacy .= Blade::render(file_get_contents(base_path('tests/Fixtures/UI03/'.$fixture.'.blade.php')));
            }
            $current = view('layouts.partials.navigation')->render();
            $this->assertSame($this->links($legacy), $this->links($current), $routeName.' / '.implode(',', $abilities));
        }
    }

    public function test_guest_navigation_contains_no_authenticated_destinations(): void
    {
        $this->assertSame([], $this->links(view('layouts.partials.navigation')->render()));
    }

    public function test_login_keeps_form_contract_and_auth_layout(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('data-ui-context="auth"', false)
            ->assertSee('wire:submit="login"', false)
            ->assertSee('action="'.route('auth.web.login').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('wire:model="username"', false)
            ->assertSee('wire:model="password"', false)
            ->assertSee('autocomplete="current-password"', false);
    }

    public function test_shared_layout_retains_livewire_and_accessible_preferences(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="#ui-main"', false)
            ->assertSee('id="ui-main"', false)
            ->assertSee('aria-labelledby="ui-preferences-title"', false)
            ->assertSee('data-ui-preference="theme"', false)
            ->assertSee('data-ui-preference="density"', false)
            ->assertSee('data-ui-preference="sidebar"', false)
            ->assertSee('data-ui-preference="text"', false)
            ->assertSee('data-ui-preference="motion"', false)
            ->assertSee('data-csrf', false);
    }

    private function links(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $links = [];
        foreach ((new DOMXPath($dom))->query('//a') as $link) {
            $links[] = [$link->getAttribute('href'), trim(preg_replace('/\s+/u', ' ', $link->textContent))];
        }
        sort($links);

        return $links;
    }
}
