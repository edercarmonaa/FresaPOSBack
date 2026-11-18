<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Sales\Http\Livewire\ReasonAdministration;
use Modules\Sales\Http\Livewire\SaleActionsPanel;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\ReasonsSqlServerTestCase;

class SaleReasonsWebTest extends ReasonsSqlServerTestCase
{
    private array $cookies = [];

    private function web(string $method, string $path, array $data = [], bool $livewire = false): TestResponse
    {
        Livewire::flushState();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $livewire
            ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function login(): void
    {
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
    }

    public function test_administration_and_control_panels_confirm_keep_the_same_key_and_recover_receipts(): void
    {
        $this->web('GET', '/admin/sales/reasons')->assertRedirect(route('login'));
        $this->login();
        $this->web('GET', '/admin/sales/reasons')->assertOk()->assertSee('Motivos autorizados');
        $screen = Livewire::test(ReasonAdministration::class)->call('create')->set('code', 'QA_WEB')->set('name', 'Motivo Web de pruebas')->call('save')->assertHasNoErrors()->assertSet('formOpen', false);
        $key = $screen->get('key');
        $screen->call('save')->call('queryOperation')->assertSet('key', $key)->assertHasNoErrors();
        $this->assertSame(1, $this->database->table('sales_operation_reasons')->where('code', 'QA_WEB')->count());
        $sale = $this->sale();
        $panel = Livewire::test(SaleActionsPanel::class, ['saleId' => $sale->sale->id])->call('prepareRemoval', $sale->items[0]->id)->assertSet('formOpen', true)->assertSee('Confirmar retiro');
        $key = $panel->get('key');
        $panel->call('confirmOperation')->assertHasErrors('reasonCode')->set('reasonCode', 'QA_REMOVE')->call('confirmOperation')->assertHasNoErrors()->assertSet('result.total', '0.0000')->assertSet('key', $key);
        $panel->call('confirmOperation')->assertHasNoErrors()->call('queryOperation')->assertSet('key', $key);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.item_removed')->count());
        Livewire::test(SaleActionsPanel::class, ['saleId' => $sale->sale->id])->assertSet('key', $key)->assertSet('formOpen', false)->assertSet('result.total', '0.0000');
        $panel->call('prepareCancellation')->set('reasonCode', 'QA_CANCEL')->call('confirmOperation')->assertHasNoErrors()->assertSet('result.state', 'cancelled');
    }

    public function test_native_livewire_snapshot_rejects_actor_tampering_and_stale_identity_after_pin_switch(): void
    {
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'Empleado PIN motivos', 'username' => 'reason.pin', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'waiter');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->web('POST', '/login', ['username' => 'reason.pin', 'password' => 'EmployeeChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->login();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        $response = $this->web('GET', '/admin/sales/reasons')->assertOk();
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $snapshot = null;
        foreach ((new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $candidate = $element->getAttribute('wire:snapshot');
            if (json_decode($candidate, true)['memo']['name'] === 'sales.reasons') {
                $snapshot = $candidate;
            }
        }
        $this->assertNotNull($snapshot);
        preg_match('/data-update-uri="([^"]+)"/', $response->getContent(), $matches);
        $path = html_entity_decode($matches[1]);
        $valid = ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'create', 'params' => []]]]]];
        $this->web('POST', $path, $valid, true)->assertOk();
        $payload = ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => ['actorContext' => '01aaaaaaaaaaaaaaaaaaaaaaaa'], 'calls' => [['path' => '', 'method' => 'create', 'params' => []]]]]];
        $this->web('POST', $path, $payload, true)->assertStatus(419)->assertDontSee('SQLSTATE');
        $this->web('POST', '/auth/employee', ['username' => 'reason.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $payload['components'][0]['updates'] = [];
        $this->web('POST', $path, $payload, true)->assertForbidden();
        $this->web('GET', '/admin/sales/reasons')->assertForbidden();
        $this->web('GET', '/pos')->assertOk()->assertDontSee('Cancelar venta no pagada')->assertDontSee('Motivos autorizados');
        $this->assertSame(2, $this->database->table('sales_operation_reasons')->count());
    }
}
