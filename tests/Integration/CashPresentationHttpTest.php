<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Tests\Support\ReconciliationSqlServerTestCase;

class CashPresentationHttpTest extends ReconciliationSqlServerTestCase
{
    public function test_authorized_cash_page_loads_each_local_presentation_resource_once(): void
    {
        $this->get('/cash')->assertRedirect(route('login'));
        $response = $this->post('/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        Auth::forgetGuards();
        $response = $this->call('GET', '/cash', [], $cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        $response->assertOk()->assertSee('ui-cash-page', false)->assertSee('wire:click="beginClosing"', false)->assertSee('data-cash-network', false);
        foreach (['/css/ui/cash.css', '/css/ui/admin.css', '/js/ui/admin.js'] as $asset) {
            $this->assertSame(1, substr_count($response->getContent(), $asset));
        }
    }
}
