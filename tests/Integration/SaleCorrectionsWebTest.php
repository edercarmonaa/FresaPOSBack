<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Http\Livewire\CheckoutPanel;
use Modules\Sales\Http\Livewire\CorrectionPanel;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\CorrectionsSqlServerTestCase;

class SaleCorrectionsWebTest extends CorrectionsSqlServerTestCase
{
    private array $cookies = [];

    private function web(string $method, string $path, array $data = [], bool $livewire = false): TestResponse
    {
        Livewire::flushState();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $livewire ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function login(string $username = 'finance.fixture', string $password = 'FinanceChanged!123'): void
    {
        $this->web('POST', '/login', compact('username', 'password'))->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
        $session = app('session')->driver();
        $this->app->rebinding('request', function ($app, $request) use ($session): void {
            if (! $request->hasSession()) {
                $request->setLaravelSession($session);
            }
        });
    }

    private function protocol(string $html, string $name): array
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach ((new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = $element->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true)['memo']['name'] === $name) {
                preg_match('/data-update-uri="([^"]+)"/', $html, $matches);

                return [$snapshot, html_entity_decode($matches[1])];
            }
        }
        $this->fail('Missing native correction component');
    }

    private function payload(string $snapshot, string $method, array $params = []): array
    {
        return ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]];
    }

    public function test_reopen_edit_confirm_double_click_and_mount_recover_one_functional_summary(): void
    {
        $paid = $this->settledSale();
        $this->login();
        $panel = Livewire::test(CorrectionPanel::class, ['saleId' => $paid->sale->id])->assertSee('Corregir venta')->call('prepare', 'reopen')->set('observation', 'Revisión Web')->call('submit')->assertHasNoErrors()->assertSet('result.status', 'active');
        $key = $panel->get('key');
        $panel->call('submit')->assertHasNoErrors()->assertSet('key', $key);
        $this->assertSame(1, $this->database->table('sales_corrections')->count());
        $panel->call('prepare', 'note')->set('note', 'Nota Web')->call('submit')->assertHasNoErrors()->assertSet('result.proposed.note', 'Nota Web');
        $this->assertNull(app(SalesOperations::class)->sale($this->actor, $paid->sale->id)->sale->note);
        $panel->call('prepare', 'confirm')->assertSee('Sin cobro adicional')->call('submit')->assertHasNoErrors()->assertSet('result.status', 'confirmed')->assertSet('result.sale.folio', $paid->sale->folio);
        $key = $panel->get('key');
        Livewire::test(CorrectionPanel::class, ['saleId' => $paid->sale->id])->assertSet('key', $key)->assertSet('opened', false)->assertSee('Historial de correcciones')->call('queryOperation')->assertSet('key', $key);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
        $this->assertSame(1, $this->database->table('sales_payments')->count());
    }

    public function test_additional_cash_preview_and_confirmation_keep_original_folio_and_cash_net(): void
    {
        $paid = $this->settledSale();
        $this->login();
        $panel = Livewire::test(CorrectionPanel::class, ['saleId' => $paid->sale->id]);
        $panel->call('prepare', 'reopen')->call('submit')->assertHasNoErrors();
        $panel->call('prepare', 'quantity', $paid->items[0]->id)->set('quantity', '2')->call('submit')->assertHasNoErrors();
        $panel->call('prepare', 'confirm')->set('payments.0.applied_amount', '180')->set('payments.0.received_cash', '200')->call('calculate')->assertHasNoErrors()->assertSet('preview.cash_applied', '180.0000')->assertSet('preview.change', '20.0000')->call('submit')->assertHasNoErrors()->assertSet('result.financial.additional_paid', '180.0000');
        $this->assertSame('460.0000', $this->funds()['expected_cash']);
        $this->assertSame(2, $this->database->table('sales_payments')->count());
    }

    public function test_refund_is_explained_and_confirmation_rejected_without_mutating_payments(): void
    {
        $paid = $this->settledSale();
        $this->login();
        $panel = Livewire::test(CorrectionPanel::class, ['saleId' => $paid->sale->id])->call('prepare', 'reopen')->call('submit')->assertHasNoErrors();
        $panel->call('prepare', 'discount')->set('discountValue', '20')->call('submit')->assertHasNoErrors()->assertSee('requiere devolver dinero');
        $panel->call('prepare', 'confirm')->call('submit')->assertHasErrors('correction');
        $panel->call('prepare', 'discard')->call('submit')->assertHasNoErrors()->assertSet('result.status', 'discarded');
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
    }

    public function test_native_pin_switch_rejects_old_authorizer_snapshot_and_uses_current_authorized_employee(): void
    {
        $users = app(UserAdministration::class);
        $users->createRole($this->actor, 'correction_authorizer', 'Autorizador de prueba');
        $users->setRolePermissions($this->actor, 'correction_authorizer', ['catalog.products.view', 'sales.view', 'sales.create', 'sales.open.update', 'sales.reopen', 'sales.special.authorize', 'sales.close', 'cash.view']);
        $employee = $users->create($this->actor, ['name' => 'Correction PIN', 'username' => 'correction.pin', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'correction_authorizer');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->login('correction.pin', 'EmployeeChanged!123');
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->login();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        $paid = $this->settledSale();
        [$parent,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk()->getContent(), 'sales.pos');
        $this->web('POST', $uri, $this->payload($parent, 'selectSale', [$paid->sale->id]), true)->assertOk();
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk()->getContent(), 'sales.corrections');
        $this->web('POST', '/auth/employee', ['username' => 'correction.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->web('POST', $uri, $this->payload($snapshot, 'prepare', ['reopen']), true)->assertForbidden();
        $this->web('GET', '/pos')->assertOk();
        Livewire::test(CorrectionPanel::class, ['saleId' => $paid->sale->id])->call('prepare', 'reopen')->call('submit')->assertHasNoErrors()->assertSet('result.reopened_by', $employee->id);
        $this->assertSame($employee->id, $this->database->table('sales_corrections')->value('reopened_by'));
    }

    public function test_cashier_can_read_history_without_reopening_permission(): void
    {
        $paid = $this->settledSale();
        $this->reopen($paid);
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Read history cashier', 'username' => 'correction.cashier', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, 'cashier');
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->login('correction.cashier', 'EmployeeChanged!123');
        Livewire::test(CorrectionPanel::class, ['saleId' => $paid->sale->id])->assertSee('Historial de correcciones')->assertDontSee('wire:click="prepare(', false)->call('prepare', 'reopen')->assertForbidden();
        $this->assertSame(1, $this->database->table('sales_corrections')->count());
    }

    public function test_existing_checkout_receipt_refreshes_after_additional_collection_without_changing_its_key(): void
    {
        $paid = $this->settledSale();
        $this->login();
        $checkout = Livewire::test(CheckoutPanel::class, ['saleId' => $paid->sale->id])->assertSet('receipt.total', '180.0000');
        $key = $checkout->get('key');
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
        $this->correction($row, 'confirm', ['payments' => [$this->payment('cash', '180', '200')]]);
        $checkout->call('refreshTicket', $paid->sale->id)->assertHasNoErrors()->assertSet('receipt.total', '360.0000')->assertSet('receipt.folio', $paid->sale->folio)->assertSet('key', $key);
        $this->assertCount(2,$checkout->get('receipt.payments'));
    }
}
