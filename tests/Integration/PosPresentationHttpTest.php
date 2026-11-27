<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Tests\Support\ReceiptsSqlServerTestCase;

class PosPresentationHttpTest extends ReceiptsSqlServerTestCase
{
    public function test_authorized_pos_loads_presentation_once_without_changing_access_control(): void
    {
        $this->get('/pos')->assertRedirect(route('login'));
        $response = $this->post('/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        Auth::forgetGuards();
        $response = $this->call('GET', '/pos', [], $cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        $response->assertOk()->assertSee('ui-pos-module', false)->assertSee('ui-pos-financial', false);
        $this->assertSame(1, substr_count($response->getContent(), '/css/ui/pos.css'));
    }
}
