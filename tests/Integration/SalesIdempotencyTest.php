<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\CashOperations;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\IdempotencySqlServerTestCase;

class SalesIdempotencyTest extends IdempotencySqlServerTestCase
{
    private string $actor;

    private string $token;

    private string $register;

    private string $product;

    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = app(BootstrapAdministrator::class)->create('Idempotency HTTP fixture', 'idempotency.admin', 'InitialIdem!123');
        app(IdentityAccess::class)->changePassword($this->actor, 'InitialIdem!123', 'ChangedIdem!123');
        $this->register = app(ProvisionCashRegister::class)->run()->id;
        $this->token = $this->login();
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Idempotency category']);
        $this->product = $catalog->createProduct($this->actor, ['name' => 'Idempotency product', 'category_id' => $category->id, 'price' => '10.1234'])->id;
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/idempotency-1.5d2a-contract-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function login(string $username = 'idempotency.admin', string $password = 'ChangedIdem!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null, ?string $revision = null, ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $response = $this->json($method, '/api/v1'.$path, $data, ['Authorization' => 'Bearer '.($token ?? $this->token), 'X-Request-Id' => 'durable-http',
            ...($key === null ? [] : ['Idempotency-Key' => $key]), ...($revision === null ? [] : ['If-Match' => '"'.$revision.'"'])]);
        $response->assertHeader('X-Request-Id', 'durable-http')->assertJsonPath('meta.request_id', 'durable-http');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $pattern = preg_replace('~/[0-7][0-9a-hjkmnp-tv-z]{25}(?=/|$)~i', '/{id}', $path);
        $pattern = str_replace('/items/{id}', '/items/{itemId}', $pattern);
        if (str_starts_with($path, '/operations/')) {
            $pattern = '/operations/{idempotencyKey}/status';
        }
        self::$samples[] = ['method' => strtolower($method), 'path' => $pattern, 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    private function cash(): string
    {
        return app(CashOperations::class)->open($this->actor, $this->register, '100', 'cash-durable-fixture-123456')->id;
    }

    private function open(?string $key = null): TestResponse
    {
        return $this->api('POST', '/sales', ['type' => 'counter'], $key ?? (string) Str::uuid())->assertCreated();
    }

    public function test_open_counter_replays_persisted_result_and_has_real_cash_actor_without_folio(): void
    {
        $session = $this->cash();
        $key = (string) Str::uuid();
        $first = $this->open($key);
        $replay = $this->api('POST', '/sales', ['note' => '', 'type' => 'counter'], strtoupper($key))->assertCreated();
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(1, $this->database->table('sales_sales')->count());
        $this->assertSame($session, $this->database->table('sales_sales')->value('cash_session_id'));
        $this->assertSame($this->actor, $first->json('data.created_by'));
        $this->assertNull($first->json('data.folio'));
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->count());
        $this->api('GET', '/operations/'.$key.'/status')->assertOk()->assertJsonPath('data.operation.state', 'completed')->assertJsonPath('data.operation.http_status', 201)->assertJsonPath('data.operation.resource_id', $first->json('data.id'));
        $this->api('POST', '/sales', ['type' => 'counter', 'note' => 'Otro pedido'], $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'idempotency.key_conflict')->count());
    }

    public function test_cash_absent_or_closed_prevents_new_effects_and_same_key_can_retry_after_dependency_recovers(): void
    {
        $key = (string) Str::uuid();
        $this->api('POST', '/sales', ['type' => 'counter'], $key)->assertStatus(503);
        $this->assertSame(0, $this->database->table('idempotency_records')->count());
        $session = $this->cash();
        $this->open($key);
        $this->database->table('cash_sessions')->where('id', $session)->update(['status' => 'closed', 'closed_at' => now()->utc()->addSecond(), 'closed_by' => $this->actor]);
        $this->api('POST', '/sales', ['type' => 'counter'], (string) Str::uuid())->assertStatus(503);
        // Replay devuelve un hecho confirmado, no abre otra venta ni usa Cash para escribir.
        $this->open($key);
        $this->assertSame(1, $this->database->table('sales_sales')->count());
    }

    public function test_table_open_replays_and_other_intent_cannot_occupy_same_table(): void
    {
        $this->cash();
        $table = app(SalesOperations::class)->createTable($this->actor, 'Idempotent table');
        $key = (string) Str::uuid();
        $body = ['type' => 'table', 'table_id' => $table->id];
        $first = $this->api('POST', '/sales', $body, $key)->assertCreated();
        $this->api('POST', '/sales', $body, $key)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $other = (string) Str::uuid();
        $this->api('POST', '/sales', $body, $other)->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $this->api('GET', '/operations/'.$other.'/status')->assertOk()->assertJsonPath('data.operation.state', 'failed');
        $this->api('POST', '/sales', $body, $other)->assertConflict();
        $this->assertSame(1, $this->database->table('sales_sales')->count());
    }

    public function test_add_product_replays_once_and_new_intent_consolidates_without_overwriting_snapshot(): void
    {
        $this->cash();
        $sale = $this->open();
        $id = $sale->json('data.id');
        $key = (string) Str::uuid();
        $body = ['product_id' => $this->product, 'quantity' => 2, 'note' => '  Sin   hielo  '];
        $first = $this->api('POST', '/sales/'.$id.'/items', $body, $key, $sale->json('data.revision'))->assertOk()->assertJsonPath('data.total', '20.2468');
        $body['note'] = 'Sin hielo';
        $replay = $this->api('POST', '/sales/'.$id.'/items', $body, $key, $sale->json('data.revision'))->assertOk();
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame(2, (int) $this->database->table('sales_items')->value('quantity'));
        $second = $this->api('POST', '/sales/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 1, 'note' => 'Sin hielo'], (string) Str::uuid(), $first->json('data.revision'))->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 3);
        app(CatalogAdministration::class)->updateProduct($this->actor, $this->product, ['price' => '99.0000']);
        $this->api('POST', '/sales/'.$id.'/items', $body, $key, $sale->json('data.revision'))->assertOk()->assertJsonPath('data.items.0.unit_price', '10.1234');
        $this->api('GET', '/sales/'.$id)->assertOk()->assertJsonPath('data.total', '30.3702')->assertJsonPath('data.revision', $second->json('data.revision'));
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'sales.item_added')->count());
    }

    public function test_quantity_notes_pause_resume_are_durable_and_do_not_reapply_old_revisions(): void
    {
        $this->cash();
        $sale = $this->open();
        $id = $sale->json('data.id');
        $sale = $this->api('POST', '/sales/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 2], (string) Str::uuid(), $sale->json('data.revision'))->assertOk();
        $item = $sale->json('data.items.0.id');
        foreach ([['PATCH', '/items/'.$item.'/quantity', ['quantity' => 3], 'sales.quantity_changed'], ['PATCH', '/items/'.$item.'/note', ['note' => 'Poco hielo'], 'sales.item_note_updated'], ['PATCH', '/note', ['note' => 'Para llevar'], 'sales.note_updated'], ['POST', '/pause', [], 'sales.paused'], ['POST', '/resume', [], 'sales.resumed']] as [$method,$path,$body,$event]) {
            $key = (string) Str::uuid();
            $revision = $sale->json('data.revision');
            $sale = $this->api($method, '/sales/'.$id.$path, $body, $key, $revision)->assertOk();
            $replay = $this->api($method, '/sales/'.$id.$path, $body, $key, $revision)->assertOk();
            $this->assertSame($sale->json('data'), $replay->json('data'));
            $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', $event)->count());
        }
        $this->api('PATCH', '/sales/'.$id.'/items/'.$item.'/quantity', ['quantity' => 0], (string) Str::uuid(), $sale->json('data.revision'))->assertUnprocessable();
        $this->api('POST', '/sales/'.$id.'/cancel', ['reason_code' => 'NOT_IMPLEMENTED'], revision: $sale->json('data.revision'))->assertStatus(503);
        $this->assertSame(0, $this->database->table('sales_sales')->whereNotNull('folio')->count());
    }

    public function test_header_validation_status_privacy_permissions_revocation_and_expiry(): void
    {
        $this->cash();
        foreach ([null, '', 'wrong', (string) Str::ulid(), '3d6f0a97-2b10-1f71-8b3d-9e98c7b78e62'] as $key) {
            $this->api('POST', '/sales', ['type' => 'counter'], $key)->assertBadRequest();
        }
        $key = (string) Str::uuid();
        $sale = $this->open($key);
        $users = app(UserAdministration::class);
        $other = $users->create($this->actor, ['name' => 'Other', 'username' => 'idem.other', 'password' => 'OtherInitial!123'])->id;
        $users->assignRole($this->actor, $other, 'waiter');
        app(IdentityAccess::class)->changePassword($other, 'OtherInitial!123', 'OtherChanged!123');
        $token = $this->login('idem.other', 'OtherChanged!123');
        $this->api('GET', '/operations/'.$key.'/status', token: $token)->assertNotFound()->assertJsonMissingPath('data');
        $otherSale = $this->api('POST', '/sales', ['type' => 'counter'], $key, token: $token)->assertCreated()->assertJsonPath('data.created_by', $other);
        $this->assertNotSame($sale->json('data.id'), $otherSale->json('data.id'));
        $users->setRolePermissions($this->actor, 'waiter', ['sales.view']);
        $this->api('POST', '/sales', ['type' => 'counter'], $key, token: $token)->assertForbidden();
        $users->setActive($this->actor, $other, false);
        $this->api('GET', '/operations/'.$key.'/status', token: $token)->assertUnauthorized();
        $this->travel(25)->hours();
        // Acceso API nuevo; la expiración del token no se confunde con la del recibo.
        $this->token = $this->login();
        $this->api('GET', '/operations/'.$key.'/status')->assertOk()->assertJsonPath('data.operation.state', 'expired')->assertJsonPath('data.operation.resource_id', $sale->json('data.id'));
        $this->api('POST', '/sales', ['type' => 'counter'], $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(2, $this->database->table('sales_sales')->count());
    }

    public function test_audit_failure_rolls_back_business_and_receipt_then_same_key_recovers(): void
    {
        $this->cash();
        $key = (string) Str::uuid();
        $fail = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $event) use (&$fail): void {
            if ($fail && $event->type === 'sales.opened') {
                throw new \RuntimeException('private-sql-fixture');
            }
        });
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $this->api('POST', '/sales', ['type' => 'counter'], $key)->assertStatus(500)->assertDontSee('private-sql');
        }
        $this->assertSame(0, $this->database->table('sales_sales')->count());
        $this->assertSame(0, $this->database->table('idempotency_records')->count());
        $fail = false;
        $this->open($key);
        $this->open($key);
        $this->assertSame(1, $this->database->table('sales_sales')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->count());
    }
}
