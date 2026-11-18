<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Cash\Http\Livewire\CashClosingPanel;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Infrastructure\Persistence\SqlOperationLock;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\ReconciliationSqlServerTestCase;
use Tests\Support\TestDatabaseGuard;

class CashReconciliationLivewireTest extends ReconciliationSqlServerTestCase
{
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
    }

    private function web(string $method, string $path, array $data = [], bool $wire = false): TestResponse
    {
        Livewire::flushState();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $wire ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR)) : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function protocol(): array
    {
        $page = $this->web('GET', '/cash')->assertOk();
        $dom = new DOMDocument;
        $dom->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        preg_match('/data-update-uri="([^"]+)"/', $page->getContent(), $m);
        foreach ((new DOMXPath($dom))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = $element->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true)['memo']['name'] === 'cash.closing') {
                return [$snapshot, html_entity_decode($m[1])];
            }
        }
        $this->fail('Missing native closing panel');
    }

    private function wire(string $uri, string $snapshot, string $action, array $updates = [], int $times = 1): TestResponse
    {
        return $this->web('POST', $uri, ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => array_fill(0, $times, ['path' => '', 'method' => $action, 'params' => []])]]], true);
    }

    public function test_native_blind_form_double_click_old_snapshot_lost_response_and_reload(): void
    {
        [$snapshot,$uri] = $this->protocol();
        $prepared = $this->wire($uri, $snapshot, 'beginClosing')->assertOk();
        $snapshot = $prepared->json('components.0.snapshot');
        $this->assertStringNotContainsString('expected_cash', $snapshot);
        $this->assertStringNotContainsString('Efectivo esperado', $prepared->json('components.0.effects.html'));
        $payload = ['countedCash' => '99', 'observation' => 'Faltante observado al contar'];
        $this->wire($uri, $snapshot, 'confirmClosing', $payload, 2)->assertOk();
        $this->wire($uri, $snapshot, 'confirmClosing', $payload)->assertOk();
        [$fresh] = $this->protocol();
        $this->assertStringContainsString('shortage', $fresh);
        $this->assertStringContainsString('-1.0000', $fresh);
        $this->assertSame(1, $this->database->table('cash_reconciliations')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.session.closed')->count());
        $this->web('GET', '/pos')->assertOk();
    }

    public function test_pending_sales_block_form_and_pending_intention_keeps_uuid(): void
    {
        $panel = Livewire::test(CashClosingPanel::class)->call('beginClosing')->assertSet('formOpen', true);
        $key = $panel->get('key');
        config(['database.connections.closing_hold' => config('database.connections.sqlsrv_testing')]);
        $holding = app('db')->connection('closing_hold');
        TestDatabaseGuard::assertConnected($holding);
        $locks = app(SqlOperationLock::class);
        $resource = $locks->resource(OperationIntent::context($this->actor), $key);
        $this->assertTrue($locks->acquire($holding->getPdo(), $resource));
        try {
            $panel->set('countedCash', '100')->set('observation', 'Conteo')->call('confirmClosing')->call('queryClosing')->assertSet('pending', true);
            $panel->call('beginClosing')->assertSet('key', $key)->assertSet('pending', true);
        } finally {
            $locks->release($holding->getPdo(), $resource);
            app('db')->purge('closing_hold');
        }
        $panel->call('queryClosing')->assertSet('pending', false)->call('confirmClosing')->assertHasNoErrors()->assertSee('Caja cuadrada');
    }

    public function test_real_pin_switch_invalidates_old_closing_snapshot_and_attributes_new_actor(): void
    {
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'PIN closing fixture', 'username' => 'closing.pin', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'cashier');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->web('POST', '/login', ['username' => 'closing.pin', 'password' => 'EmployeeChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        [$snapshot,$uri] = $this->protocol();
        $snapshot = $this->wire($uri, $snapshot, 'beginClosing')->assertOk()->json('components.0.snapshot');
        $this->web('POST', '/auth/employee', ['username' => 'closing.pin', 'pin' => '0042'])->assertRedirect();
        $this->wire($uri, $snapshot, 'confirmClosing', ['countedCash' => '100', 'observation' => 'Conteo'])->assertForbidden();
        [$snapshot,$uri] = $this->protocol();
        $snapshot = $this->wire($uri, $snapshot, 'beginClosing')->assertOk()->json('components.0.snapshot');
        $this->wire($uri, $snapshot, 'confirmClosing', ['countedCash' => '100', 'observation' => 'Conteo por empleado activo'])->assertOk();
        $this->assertSame($employee->id, $this->database->table('cash_sessions')->value('closed_by'));
        $users->removeRole($this->actor, $employee->id, 'cashier');
        $users->assignRole($this->actor, $employee->id, 'waiter');
        $this->wire($uri, $snapshot, 'confirmClosing', ['countedCash' => '100', 'observation' => 'Conteo por empleado activo'])->assertForbidden();
    }
}
