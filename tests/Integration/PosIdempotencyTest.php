<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\CashOperations;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Infrastructure\Persistence\SqlOperationLock;
use Modules\Sales\Http\Livewire\PointOfSale;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Tests\Support\IdempotencySqlServerTestCase;
use Tests\Support\TestDatabaseGuard;

class PosIdempotencyTest extends IdempotencySqlServerTestCase
{
    private string $actor;

    private string $product;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = app(BootstrapAdministrator::class)->create('Durable POS fixture', 'durable.pos', 'PosInitial!123');
        app(IdentityAccess::class)->changePassword($this->actor, 'PosInitial!123', 'PosChanged!123');
        $register = app(ProvisionCashRegister::class)->run()->id;
        app(CashOperations::class)->open($this->actor, $register, '0', 'cash-durable-pos-123456');
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Durable POS']);
        $this->product = $catalog->createProduct($this->actor, ['name' => 'Durable product', 'category_id' => $category->id, 'price' => '10.1234'])->id;
        $this->web('POST', '/login', ['username' => 'durable.pos', 'password' => 'PosChanged!123'])->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
    }

    private function web(string $method, string $path, array $data = [], bool $wire = false): TestResponse
    {
        Livewire::flushState();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $wire ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function protocol(TestResponse $page): array
    {
        $dom = new DOMDocument;
        $dom->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach ((new DOMXPath($dom))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = $element->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true)['memo']['name'] === 'sales.pos') {
                preg_match('/data-update-uri="([^"]+)"/', $page->getContent(), $m);

                return [$snapshot, html_entity_decode($m[1])];
            }
        }
        $this->fail('Debe montar POS real.');
    }

    private function wire(string $snapshot, string $uri, array $calls, array $updates = []): TestResponse
    {
        return $this->web('POST', $uri, ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]]], true);
    }

    private function callFor(string $name, array $params = []): array
    {
        return ['path' => '', 'method' => $name, 'params' => $params];
    }

    public function test_native_lost_open_response_replay_and_browser_refresh_do_not_create_another_sale(): void
    {
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $key = json_decode($snapshot, true)['data']['openKey'];
        $this->assertSame($key, OperationIntent::key($key));
        // Descartar el cuerpo para el cliente y reenviar el snapshot anterior firmado.
        $this->wire($snapshot, $uri, [$this->callFor('startSale')])->assertOk();
        $this->wire($snapshot, $uri, [$this->callFor('startSale')])->assertOk();
        [$fresh] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $state = json_decode($fresh, true)['data'];
        $this->assertSame($this->database->table('sales_sales')->value('id'), $state['saleId']);
        $this->assertSame($key, $state['openKey']);
        $this->assertSame(1, $this->database->table('sales_sales')->count());
        $this->assertSame(1, $this->database->table('idempotency_records')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->count());
    }

    public function test_native_add_replay_and_two_batched_quantity_clicks_apply_one_intention(): void
    {
        $screen = Livewire::test(PointOfSale::class)->call('startSale')->assertHasNoErrors();
        $id = $screen->get('saleId');
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $response = $this->wire($snapshot, $uri, [$this->callFor('chooseProduct', [$this->product])])->assertOk();
        $before = $response->json('components.0.snapshot');
        $this->wire($before, $uri, [$this->callFor('addProduct')], ['quantity' => '2'])->assertOk();
        $this->wire($before, $uri, [$this->callFor('addProduct')], ['quantity' => '2'])->assertOk();
        $this->assertSame(2, (int) $this->database->table('sales_items')->value('quantity'));
        $item = $this->database->table('sales_items')->value('id');
        [$snapshot] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $calls = [$this->callFor('adjustQuantity', [$item, 1]), $this->callFor('adjustQuantity', [$item, 1])];
        $this->wire($snapshot, $uri, $calls)->assertOk();
        $this->wire($snapshot, $uri, $calls)->assertOk();
        $this->assertSame(3, (int) $this->database->table('sales_items')->value('quantity'));
        $this->assertSame('30.3702', $this->database->table('sales_sales')->where('id', $id)->value('total'));
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.item_added')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.quantity_changed')->count());
    }

    public function test_new_intentions_change_keys_and_notes_pause_resume_survive_remount(): void
    {
        $screen = Livewire::test(PointOfSale::class)->call('startSale')->assertHasNoErrors();
        $id = $screen->get('saleId');
        $key = $screen->get('mutationKey');
        $screen->set('saleNote', 'Nota durable')->call('saveSaleNote')->assertHasNoErrors()->assertSet('currentSale.note', 'Nota durable');
        $this->assertNotSame($key, $screen->get('mutationKey'));
        $screen->call('pauseSale')->assertHasNoErrors()->assertSet('currentSale.state', 'paused');
        Livewire::test(PointOfSale::class)->assertSet('saleId', $id)->assertSet('currentSale.state', 'paused')->call('resumeSale')->assertHasNoErrors()->assertSet('currentSale.state', 'open');
        $old = $screen->get('openKey');
        $screen->call('clearSale')->assertHasNoErrors();
        $this->assertNotSame($old, $screen->get('openKey'));
        $prepared = $screen->get('openKey');
        Livewire::test(PointOfSale::class)->assertSet('saleId', null)->assertSet('openKey', $prepared)->call('startSale')->assertHasNoErrors();
        $this->assertSame(2, $this->database->table('sales_sales')->count());
    }

    public function test_pending_real_sql_lock_keeps_key_blocks_new_intent_and_recovers_after_release(): void
    {
        $screen = Livewire::test(PointOfSale::class);
        $key = $screen->get('openKey');
        config(['database.connections.sqlsrv_pos_hold' => config('database.connections.sqlsrv_testing')]);
        $holding = app('db')->connection('sqlsrv_pos_hold');
        TestDatabaseGuard::assertConnected($holding);
        $locks = app(SqlOperationLock::class);
        $resource = $locks->resource(OperationIntent::context($this->actor), $key);
        $pdo = $holding->getPdo();
        $this->assertTrue($locks->acquire($pdo, $resource));
        try {
            $screen->call('startSale')->assertHasErrors('operation')->assertSet('intentPending', true)->assertSee('Operación en proceso');
            $screen->call('clearSale')->assertSet('openKey', $key)->assertSet('intentPending', true);
            $this->assertSame(0, $this->database->table('sales_sales')->count());
        } finally {
            $locks->release($pdo, $resource);
            app('db')->purge('sqlsrv_pos_hold');
        }
        $screen->call('resolveIntent')->assertSet('intentPending', false)->assertSet('openKey', $key)->call('startSale')->assertHasNoErrors();
        $this->assertSame(1, $this->database->table('sales_sales')->count());
    }
}
