<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Cash\Http\Livewire\CashMovementsPanel;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Infrastructure\Persistence\SqlOperationLock;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\FinanceSqlServerTestCase;
use Tests\Support\TestDatabaseGuard;

class CashFinanceLivewireTest extends FinanceSqlServerTestCase
{
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $this->web('GET', '/account')->assertOk();
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
        foreach ((new DOMXPath($dom))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = $element->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true)['memo']['name'] === 'cash.movements') {
                preg_match('/data-update-uri="([^"]+)"/', $page->getContent(), $match);

                return [$snapshot, html_entity_decode($match[1])];
            }
        }
        $this->fail('Falta el componente financiero real.');
    }

    private function submit(string $snapshot, string $uri, array $updates = [], int $calls = 1): TestResponse
    {
        return $this->web('POST', $uri, ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => array_fill(0, $calls, ['path' => '', 'method' => 'recordMovement', 'params' => []])]]], true);
    }

    public function test_native_double_click_lost_response_and_refresh_preserve_the_same_intention(): void
    {
        [$snapshot,$uri] = $this->protocol();
        $key = json_decode($snapshot, true)['data']['movementKey'];
        $payload = ['amount' => '10.25', 'concept' => 'Reposición desde Web', 'movementType' => 'cash_in'];
        $this->submit($snapshot, $uri, $payload, 2)->assertOk(); // Descartar cuerpo y reenviar snapshot firmado anterior.
        $this->submit($snapshot, $uri, $payload)->assertOk();
        [$fresh] = $this->protocol();
        $state = json_decode($fresh, true)['data'];
        $this->assertSame($key, $state['movementKey']);
        $this->assertTrue($state['resolved']);
        $this->assertSame(1, $this->database->table('cash_movements')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.movement.recorded')->count());
        $this->assertSame('110.2500', $this->funds()['expected_cash']);
        $panel = Livewire::test(CashMovementsPanel::class)->call('prepareMovement')->assertHasNoErrors();
        $this->assertNotSame($key, $panel->get('movementKey'));
        $panel->set('movementType', 'cash_out')->set('amount', '0.25')->set('concept', 'Retiro autorizado')->call('recordMovement')->assertHasNoErrors();
        $this->assertSame('110.0000', $this->funds()['expected_cash']);
    }

    public function test_validation_insufficient_funds_and_closed_session_show_safe_errors(): void
    {
        $panel = Livewire::test(CashMovementsPanel::class)->assertSee('100.0000')->set('amount', '0')->set('concept', 'Validar')->call('recordMovement')->assertHasErrors('amount');
        $panel->set('amount', '1')->set('concept', '')->call('recordMovement')->assertHasErrors('concept');
        $panel->set('amount', '100.0001')->set('concept', 'Retiro')->set('movementType', 'cash_out')->call('recordMovement')->assertHasErrors('amount')->assertSee('Saldo insuficiente');
        $panel->call('prepareMovement')->assertHasNoErrors();
        $this->closeFixture();
        $panel->set('amount', '1')->set('concept', 'Sesión cerrada')->call('recordMovement')->assertHasErrors('cash_session_id');
        $this->assertSame(0, $this->database->table('cash_movements')->count());
    }

    public function test_pending_sql_lock_prevents_key_replacement_and_recovers(): void
    {
        $panel = Livewire::test(CashMovementsPanel::class);
        $key = $panel->get('movementKey');
        config(['database.connections.finance_hold' => config('database.connections.sqlsrv_testing')]);
        $holding = app('db')->connection('finance_hold');
        TestDatabaseGuard::assertConnected($holding);
        $locks = app(SqlOperationLock::class);
        $resource = $locks->resource(OperationIntent::context($this->actor), $key);
        $this->assertTrue($locks->acquire($holding->getPdo(), $resource));
        try {
            $panel->set('amount', '2')->set('concept', 'Pendiente')->call('recordMovement')->assertSet('pending', true)->assertSee('sigue en proceso');
            $panel->call('prepareMovement')->assertSet('movementKey', $key);
        } finally {
            $locks->release($holding->getPdo(), $resource);
            app('db')->purge('finance_hold');
        }
        $panel->call('resolveMovement')->assertSet('pending', false)->call('recordMovement')->assertHasNoErrors();
        $this->assertSame(1, $this->database->table('cash_movements')->count());
    }

    public function test_pin_switch_rejects_previous_actor_snapshot_and_attributes_new_movement_to_employee(): void
    {
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'Cashier PIN fixture', 'username' => 'finance.pin', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'cashier');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->web('POST', '/login', ['username' => 'finance.pin', 'password' => 'EmployeeChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        [$old,$uri] = $this->protocol();
        $this->web('POST', '/auth/employee', ['username' => 'finance.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->submit($old, $uri, ['amount' => '1', 'concept' => 'Identidad anterior'])->assertForbidden();
        [$fresh,$uri] = $this->protocol();
        $this->submit($fresh, $uri, ['amount' => '1', 'concept' => 'Empleado por PIN'])->assertOk();
        $this->assertSame($employee->id, $this->database->table('cash_movements')->value('created_by'));
        $this->assertSame($employee->id, $this->database->table('audit_security_events')->where('event_type', 'cash.movement.recorded')->value('actor_id'));
        $users->removeRole($this->actor, $employee->id, 'cashier');
        $users->assignRole($this->actor, $employee->id, 'waiter');
        $this->web('GET', '/cash')->assertForbidden();
    }

    public function test_fresh_permission_revocation_blocks_submit_and_hides_form(): void
    {
        [$snapshot,$uri] = $this->protocol();
        $this->database->table('role_has_permissions')->where('permission_id', $this->database->table('permissions')->where('name', 'cash.movements.create')->value('id'))->delete();
        $this->submit($snapshot, $uri, ['amount' => '1', 'concept' => 'Permiso retirado'])->assertForbidden();
        $this->web('GET', '/cash')->assertOk()->assertSee('No tienes permiso para registrar entradas')->assertDontSee('Confirmar movimiento');
        $this->assertSame(0, $this->database->table('cash_movements')->count());
    }
}
