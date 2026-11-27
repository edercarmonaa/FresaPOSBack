<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Modules\Reports\Domain\ReportDefinition;
use Tests\Support\ReceiptsSqlServerTestCase;

class ReportAdministrationPresentationHttpTest extends ReceiptsSqlServerTestCase
{
    public function test_nine_authorized_report_pages_and_error_load_each_stylesheet_once(): void
    {
        $this->get('/reports')->assertRedirect(route('login'));
        $response = $this->post('/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        foreach ([...array_map(fn ($type) => '/reports/'.$type, array_keys(ReportDefinition::NAMES)), '/reports/sales?sort=invalid'] as $path) {
            Auth::forgetGuards();
            $response = $this->call('GET', $path, [], $cookies, [], ['HTTP_ACCEPT' => 'text/html']);
            $response->assertStatus(str_contains($path, '?') ? 422 : 200)->assertSee('ui-report-admin-page', false);
            foreach (['/css/ui/reports.css', '/css/ui/report-admin.css'] as $asset) {
                $this->assertSame(1, substr_count($response->getContent(), $asset));
            }
            foreach ($response->headers->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
    }
}
