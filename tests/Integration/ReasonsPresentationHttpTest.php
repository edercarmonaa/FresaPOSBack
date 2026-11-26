<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Tests\Support\ReasonsSqlServerTestCase;

class ReasonsPresentationHttpTest extends ReasonsSqlServerTestCase
{
    public function test_authorized_page_loads_each_presentation_asset_once(): void
    {
        $this->get('/admin/sales/reasons')->assertRedirect(route('login'));
        $response = $this->post('/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        Auth::forgetGuards();
        $response = $this->call('GET', '/admin/sales/reasons', [], $cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        $response->assertOk()->assertSee('ui-reasons-page', false)->assertSee('wire:click="create"', false);
        foreach (['/css/ui/reasons.css', '/css/ui/admin.css', '/js/ui/admin.js'] as $asset) {
            $this->assertSame(1, substr_count($response->getContent(), $asset));
        }
    }
}
