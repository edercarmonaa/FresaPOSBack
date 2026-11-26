<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Tests\Support\CatalogSqlServerTestCase;

class CatalogPresentationHttpTest extends CatalogSqlServerTestCase
{
    public function test_authenticated_catalog_pages_load_each_local_resource_once(): void
    {
        $id = app(BootstrapAdministrator::class)->create('UI fixture', 'ui07.catalog', 'InitialUI07!123');
        app(IdentityAccess::class)->changePassword($id, 'InitialUI07!123', 'ChangedUI07!123');
        $response = $this->post('/login', ['username' => 'ui07.catalog', 'password' => 'ChangedUI07!123'])->assertRedirect();
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        foreach (['/admin/catalog/products', '/admin/catalog/categories'] as $path) {
            Auth::forgetGuards();
            $response = $this->call('GET', $path, [], $cookies, [], ['HTTP_ACCEPT' => 'text/html']);
            $response->assertOk()->assertSee('ui-catalog-page', false)->assertSee('wire:click="create"', false);
            foreach (['/css/ui/catalog.css', '/css/ui/admin.css', '/js/ui/admin.js'] as $asset) {
                $this->assertSame(1, substr_count($response->getContent(), $asset));
            }
            foreach ($response->headers->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
    }
}
