<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Http\Livewire\CheckoutPanel;
use Modules\Sales\Http\Livewire\DiscountPanel;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\DiscountsSqlServerTestCase;

class SaleDiscountsWebTest extends DiscountsSqlServerTestCase
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
        // El simulador de componentes desactiva middleware: aportar la sesión
        // real creada por login, sin sustituir la validación Auth ni SQL.
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
        $this->fail('Missing native component '.$name);
    }

    private function payload(string $snapshot, string $method, array $params = [], array $updates = []): array
    {
        return ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]];
    }

    public function test_preview_confirm_replace_remove_and_double_submit_use_one_server_authorization_per_intent(): void
    {
        $this->login();
        $sale = $this->sale();
        $panel = Livewire::test(DiscountPanel::class, ['saleId' => $sale->sale->id])->call('prepare')->set('value', '20')->call('calculate')->assertSet('preview.discount_amount', '36.0000')->assertSet('preview.total', '144.0000');
        $key = $panel->get('key');
        $panel->call('confirm')->assertHasNoErrors()->assertSet('result.total', '144.0000')->assertSet('key', $key);
        $panel->call('confirm')->assertHasNoErrors()->call('queryOperation')->assertSet('key', $key);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.discount.applied')->count());
        $panel->call('prepare')->set('type', 'fixed')->set('value', '50')->call('confirm')->assertHasNoErrors()->assertSet('result.total', '130.0000');
        $panel->call('prepare', true)->call('calculate')->assertSet('preview.total', '180.0000')->call('confirm')->assertHasNoErrors()->assertSet('result.discount', '0.0000');
    }

    public function test_lost_response_recovery_on_new_mount_keeps_receipt_and_original_uuid(): void
    {
        $this->login();
        $sale = $this->sale();
        $panel = Livewire::test(DiscountPanel::class, ['saleId' => $sale->sale->id])->call('prepare')->set('value', '100')->call('confirm')->assertHasNoErrors();
        $key = $panel->get('key');
        Livewire::test(DiscountPanel::class, ['saleId' => $sale->sale->id])->assertSet('key', $key)->assertSet('opened', false)->assertSet('result.total', '0.0000')->call('queryOperation')->assertSet('key', $key);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.discount.applied')->count());
        $checkout = Livewire::test(CheckoutPanel::class, ['saleId' => $sale->sale->id])->call('openCheckout')->assertHasNoErrors()->assertSet('payments', [])->assertSee('Confirmar cierre sin pago')->call('confirmCheckout')->assertHasNoErrors()->assertSet('receipt.total', '0.0000')->assertSee('Venta cerrada con descuento total');
        $this->assertNotEmpty($checkout->get('receipt.folio'));
        $this->assertSame(0, $this->database->table('sales_payments')->count());
    }

    public function test_changed_sale_invalidates_unsubmitted_checkout_quote_without_renewing_its_key(): void
    {
        $this->login();
        $sale = $this->sale();
        $checkout = Livewire::test(CheckoutPanel::class, ['saleId' => $sale->sale->id])->call('openCheckout')->assertSet('opened', true);
        $key = $checkout->get('key');
        $this->discount($sale, new DiscountProposal('percent', 20));
        $checkout->call('refreshTicket', $sale->sale->id)->assertSet('opened', false)->assertSet('key', $key)->call('openCheckout')->assertSet('preview.total', '144.0000');
    }

    public function test_native_pin_switch_rejects_previous_employee_component_and_records_current_supervisor(): void
    {
        $users = app(UserAdministration::class);
        $supervisor = $users->create($this->actor, ['name' => 'Supervisor PIN discounts', 'username' => 'discount.pin', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $supervisor->id, 'supervisor');
        app(IdentityAccess::class)->changePassword($supervisor->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->login('discount.pin', 'EmployeeChanged!123');
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->login();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        $sale = $this->sale();
        [$parent,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk()->getContent(), 'sales.pos');
        $this->web('POST', $uri, $this->payload($parent, 'selectSale', [$sale->sale->id]), true)->assertOk();
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk()->getContent(), 'sales.discounts');
        $this->web('POST', $uri, $this->payload($snapshot, 'prepare'), true)->assertOk();
        $this->web('POST', '/auth/employee', ['username' => 'discount.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->web('POST', $uri, $this->payload($snapshot, 'prepare'), true)->assertForbidden();
        $this->web('GET', '/pos')->assertOk();
        Livewire::test(DiscountPanel::class, ['saleId' => $sale->sale->id])->call('prepare')->set('value', '20')->call('confirm')->assertHasNoErrors()->assertSet('result.discount_authorization.authorized_by', $supervisor->id);
        $this->assertSame($supervisor->id, $this->database->table('audit_security_events')->where('event_type', 'sales.discount.applied')->value('actor_id'));
    }

    public function test_invalid_preview_and_confirm_cannot_create_a_financial_change(): void
    {
        $this->login();
        $sale = $this->sale();
        Livewire::test(DiscountPanel::class, ['saleId' => $sale->sale->id])->call('prepare')->set('value', '20.5')->call('calculate')->assertHasErrors('discount')->call('confirm')->assertHasErrors('discount');
        $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
    }
}
