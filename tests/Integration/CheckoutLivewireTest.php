<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Infrastructure\Persistence\SqlOperationLock;
use Modules\Sales\Http\Livewire\CheckoutPanel;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Support\CheckoutSqlServerTestCase;
use Tests\Support\TestDatabaseGuard;

class CheckoutLivewireTest extends CheckoutSqlServerTestCase
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
        $r = $wire ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR)) : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($r->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $r;
    }

    private function snapshot(TestResponse $page, string $component): string
    {
        $dom = new DOMDocument;
        $dom->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach ((new DOMXPath($dom))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = $element->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true)['memo']['name'] === $component) {
                return $snapshot;
            }
        }
        $this->fail('Missing real component '.$component);
    }

    private function callWire(string $uri, string $snapshot, string $method, array $updates = [], array $params = [], int $times = 1): TestResponse
    {
        return $this->web('POST', $uri, ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => array_fill(0, $times, ['path' => '', 'method' => $method, 'params' => $params])]]], true);
    }

    public function test_pos_native_checkout_double_click_old_signed_snapshot_and_reload_recover_one_receipt(): void
    {
        $sale = $this->sale();
        $page = $this->web('GET', '/pos')->assertOk();
        preg_match('/data-update-uri="([^"]+)"/', $page->getContent(), $m);
        $uri = html_entity_decode($m[1]);
        $parent = $this->snapshot($page, 'sales.pos');
        $selected = $this->callWire($uri, $parent, 'selectSale', [], [$sale->sale->id])->assertOk();
        $html = $selected->json('components.0.effects.html');
        $childPage = new TestResponse(new Response($html));
        $child = $this->snapshot($childPage, 'sales.checkout');
        $prepared = $this->callWire($uri, $child, 'openCheckout')->assertOk();
        $snapshot = $prepared->json('components.0.snapshot');
        $this->callWire($uri, $snapshot, 'confirmCheckout', ['payments' => [$this->payment()]], [], 2)->assertOk();
        // Discard response; resend the previously signed snapshot and same intention.
        $this->callWire($uri, $snapshot, 'confirmCheckout', ['payments' => [$this->payment()]])->assertOk();
        $fresh = $this->snapshot($this->web('GET', '/pos')->assertOk(), 'sales.checkout');
        $this->assertStringContainsString($this->database->table('sales_sales')->where('id', $sale->sale->id)->value('folio'), $fresh);
        $this->assertSame(1, $this->database->table('sales_payments')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
        $this->assertSame('280.0000', $this->funds()['expected_cash']);
    }

    public function test_server_calculates_mixed_change_and_requires_new_intent_after_definitive_rejection(): void
    {
        $sale = $this->sale();
        $panel = Livewire::test(CheckoutPanel::class, ['saleId' => $sale->sale->id])->call('openCheckout')->assertHasNoErrors();
        $key = $panel->get('key');
        $panel->set('payments', [$this->payment('cash', '80', '100'), $this->payment('card', '100')])->call('calculate')->assertHasNoErrors()->assertSee('20.0000');
        $panel->set('payments', [$this->payment('cash', '1', '1')])->call('confirmCheckout')->assertHasErrors('checkout');
        $panel->call('openCheckout')->assertHasNoErrors();
        $this->assertNotSame($key, $panel->get('key'));
        $panel->set('payments', [$this->payment('cash', '80', '100'), $this->payment('card', '100')])->call('confirmCheckout')->assertHasNoErrors()->assertSee('Venta cobrada');
        $this->assertSame('180.0000', $this->funds()['expected_cash']);
    }

    public function test_locked_context_permissions_pin_change_and_closed_session_protect_financial_writes(): void
    {
        $sale = $this->sale();
        $panel = Livewire::test(CheckoutPanel::class, ['saleId' => $sale->sale->id])->call('openCheckout');
        $this->closeFixture();
        $panel->call('confirmCheckout')->assertHasErrors('checkout');
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $users = app(UserAdministration::class);
        $other = $users->create($this->actor, ['name' => 'Other cashier', 'username' => 'other.checkout', 'password' => 'OtherInitial!123']);
        $users->assignRole($this->actor, $other->id, 'cashier');
        app(IdentityAccess::class)->changePassword($other->id, 'OtherInitial!123', 'OtherChanged!123');
        $model = User::query()->findOrFail($other->id);
        Auth::guard('web')->setUser($model);
        $panel->call('confirmCheckout')->assertForbidden();
    }

    public function test_real_pin_switch_rejects_old_snapshot_and_checkout_attributes_the_new_employee(): void
    {
        $sale = $this->sale();
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'Checkout PIN', 'username' => 'checkout.pin', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'cashier');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->web('POST', '/login', ['username' => 'checkout.pin', 'password' => 'EmployeeChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        $page = $this->web('GET', '/pos')->assertOk();
        preg_match('/data-update-uri="([^"]+)"/', $page->getContent(), $m);
        $uri = html_entity_decode($m[1]);
        $selected = $this->callWire($uri, $this->snapshot($page, 'sales.pos'), 'selectSale', [], [$sale->sale->id])->assertOk();
        $childPage = new TestResponse(new Response($selected->json('components.0.effects.html')));
        $prepared = $this->callWire($uri, $this->snapshot($childPage, 'sales.checkout'), 'openCheckout')->assertOk()->json('components.0.snapshot');
        $this->web('POST', '/auth/employee', ['username' => 'checkout.pin', 'pin' => '0042'])->assertRedirect();
        $this->callWire($uri, $prepared, 'confirmCheckout')->assertForbidden();
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $page = $this->web('GET', '/pos')->assertOk();
        $selected = $this->callWire($uri, $this->snapshot($page, 'sales.pos'), 'selectSale', [], [$sale->sale->id])->assertOk();
        $childPage = new TestResponse(new Response($selected->json('components.0.effects.html')));
        $prepared = $this->callWire($uri, $this->snapshot($childPage, 'sales.checkout'), 'openCheckout')->assertOk()->json('components.0.snapshot');
        $this->callWire($uri, $prepared, 'confirmCheckout')->assertOk();
        $this->assertSame($employee->id, $this->database->table('sales_sales')->value('closed_by'));
        $this->assertSame($employee->id, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->value('actor_id'));
        $users->removeRole($this->actor, $employee->id, 'cashier');
        $users->assignRole($this->actor, $employee->id, 'waiter');
        $this->callWire($uri, $prepared, 'confirmCheckout')->assertForbidden();
        $this->assertSame(1, $this->database->table('sales_payments')->count());
    }

    public function test_pending_sql_intention_preserves_checkout_key_until_resolved(): void
    {
        $sale = $this->sale();
        $panel = Livewire::test(CheckoutPanel::class, ['saleId' => $sale->sale->id])->call('openCheckout');
        $key = $panel->get('key');
        config(['database.connections.checkout_hold' => config('database.connections.sqlsrv_testing')]);
        $holding = app('db')->connection('checkout_hold');
        TestDatabaseGuard::assertConnected($holding);
        $locks = app(SqlOperationLock::class);
        $resource = $locks->resource(OperationIntent::context($this->actor), $key);
        $this->assertTrue($locks->acquire($holding->getPdo(), $resource));
        try {
            $panel->call('confirmCheckout')->assertSet('pending', true)->assertHasErrors('checkout');
            $panel->call('openCheckout')->assertSet('key', $key)->assertSet('pending', true);
        } finally {
            $locks->release($holding->getPdo(), $resource);
            app('db')->purge('checkout_hold');
        }
        $panel->call('queryCheckout')->assertSet('pending', false)->call('confirmCheckout')->assertHasNoErrors();
        $this->assertSame(1, $this->database->table('sales_payments')->count());
    }
}
