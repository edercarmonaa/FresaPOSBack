<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Contracts\ConfirmedSaleDocument;
use Modules\Sales\Contracts\ConfirmedSalesDocuments;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReceiptsSqlServerTestCase;

class SaleReceiptsHttpTest extends ReceiptsSqlServerTestCase
{
    private static array $samples = [];

    private function token(string $username = 'finance.fixture', string $password = 'FinanceChanged!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $path, ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        $response = $this->getJson('/api/v1'.$path, ['X-Request-Id' => 'receipt-contract', ...($token === null ? [] : ['Authorization' => 'Bearer '.$token])]);
        $response->assertHeader('X-Request-Id', 'receipt-contract')->assertJsonPath('meta.request_id', 'receipt-contract');
        if ($response->status() !== 404) {
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $documentPath = explode('?', $path, 2)[0];
        if (preg_match('~\A/sales/(?:folio/[0-9]{12}|[0-7][0-9a-hjkmnp-tv-z]{25})/receipt\z~i', $documentPath)) {
            $template = str_contains($documentPath, '/folio/') ? '/sales/folio/{folio}/receipt' : '/sales/{id}/receipt';
            self::$samples[] = ['method' => 'get', 'path' => $template, 'status' => $response->status(), 'body' => $response->json()];
        }

        return $response;
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/receipts-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    public function test_query_by_id_and_folio_returns_the_same_safe_uniform_document_without_financial_mutations(): void
    {
        $sale = $this->settledSale();
        $token = $this->token();
        $before = $this->financialState();
        $byId = $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.total', '180.0000')->assertJsonPath('data.payments.0.change_cash', '20.0000')->json('data');
        $byFolio = $this->api('/sales/folio/'.$sale->sale->folio.'/receipt', $token)->assertOk()->json('data');
        $this->assertSame($byId, $byFolio);
        $this->assertSame($before, $this->financialState());
        foreach (['reference', 'payment_method_id', 'cash_session_id', 'authorized_by', 'password', 'token', 'cost'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', json_encode($byId, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
    }

    public static function roles(): array
    {
        return [['admin'], ['supervisor'], ['cashier'], ['waiter']];
    }

    #[DataProvider('roles')]
    public function test_existing_roles_may_read_shared_sales_only_with_their_current_permissions(string $role): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Receipt '.$role, 'username' => 'receipt.'.$role, 'password' => 'ReceiptInitial!123']);
        $users->assignRole($this->actor, $user->id, $role);
        app(IdentityAccess::class)->changePassword($user->id, 'ReceiptInitial!123', 'ReceiptChanged!123');
        $sale = $this->settledSale();
        $token = $this->token('receipt.'.$role, 'ReceiptChanged!123');
        $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertOk()->assertJsonPath('data.folio', $sale->sale->folio);
        $users->removeRole($this->actor, $user->id, $role);
        $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertForbidden();
        $this->api('/sales/folio/'.$sale->sale->folio.'/receipt', $token)->assertForbidden();
    }

    public function test_knowledge_of_id_or_folio_does_not_grant_public_access(): void
    {
        $sale = $this->settledSale();
        $this->api('/sales/'.$sale->sale->id.'/receipt')->assertUnauthorized();
        $this->api('/sales/folio/'.$sale->sale->folio.'/receipt')->assertUnauthorized();
    }

    public static function missingPermissions(): array
    {
        return [[['tickets.view', 'tickets.print']], [['sales.view', 'tickets.print']]];
    }

    #[DataProvider('missingPermissions')]
    public function test_receipt_requires_both_sales_and_ticket_read_permissions(array $permissions): void
    {
        $users = app(UserAdministration::class);
        $users->createRole($this->actor, 'receipt_partial', 'Acceso incompleto QA');
        $users->setRolePermissions($this->actor, 'receipt_partial', $permissions);
        $user = $users->create($this->actor, ['name' => 'Receipt partial', 'username' => 'receipt.partial', 'password' => 'ReceiptInitial!123']);
        $users->assignRole($this->actor, $user->id, 'receipt_partial');
        app(IdentityAccess::class)->changePassword($user->id, 'ReceiptInitial!123', 'ReceiptChanged!123');
        $sale = $this->settledSale();
        $token = $this->token('receipt.partial', 'ReceiptChanged!123');
        $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertForbidden();
        $this->api('/sales/folio/'.$sale->sale->folio.'/receipt', $token)->assertForbidden();
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
    }

    public static function revoked(): array
    {
        return [['token'], ['session'], ['inactive']];
    }

    #[DataProvider('revoked')]
    public function test_revoked_token_session_or_employee_cannot_retrieve_receipts(string $kind): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Receipt revoked', 'username' => 'receipt.revoked', 'password' => 'ReceiptInitial!123']);
        $users->assignRole($this->actor, $user->id, 'cashier');
        app(IdentityAccess::class)->changePassword($user->id, 'ReceiptInitial!123', 'ReceiptChanged!123');
        $sale = $this->settledSale();
        $token = $this->token('receipt.revoked', 'ReceiptChanged!123');
        if ($kind === 'token') {
            $this->database->table('personal_access_tokens')->delete();
        } elseif ($kind === 'session') {
            $this->database->table('auth_access_sessions')->where('channel', 'api')->update(['revoked_at' => now()]);
        } else {
            $users->setActive($this->actor, $user->id, false);
        }
        $before = $this->financialState();
        $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertUnauthorized();
        $this->api('/sales/folio/'.$sale->sale->folio.'/receipt', $token)->assertUnauthorized();
        $this->assertSame($before, $this->financialState());
    }

    public function test_open_active_correction_missing_and_malformed_references_and_payloads_are_rejected(): void
    {
        $sale = $this->sale();
        $token = $this->token();
        $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertConflict();
        $paid = $this->settledSale();
        $this->reopen($paid);
        $this->api('/sales/'.$paid->sale->id.'/receipt', $token)->assertConflict();
        $this->api('/sales/folio/'.$paid->sale->folio.'/receipt', $token)->assertConflict();
        $this->api('/sales/'.strtolower((string) Str::ulid()).'/receipt', $token)->assertNotFound();
        $this->api('/sales/folio/200001010001/receipt', $token)->assertNotFound();
        $this->api('/sales/not-a-ulid/receipt', $token)->assertNotFound();
        $this->api('/sales/folio/invalid/receipt', $token)->assertNotFound();
        $this->api('/sales/'.$sale->sale->id.'/receipt?total=0', $token)->assertUnprocessable();
    }

    public function test_api_after_confirmation_reflects_the_last_financial_version_without_consuming_sequence(): void
    {
        $sale = $this->settledSale();
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        $this->correction($row, 'confirm', ['payments' => [$this->payment()]]);
        $token = $this->token();
        $before = $this->financialState();
        $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertOk()->assertJsonPath('data.total', '360.0000')
            ->assertJsonPath('data.folio', $sale->sale->folio)->assertJsonPath('data.correction.id', $row['id'])->assertJsonCount(2, 'data.payments');
        $this->assertSame($before, $this->financialState());
    }

    public static function debugModes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('debugModes')]
    public function test_internal_failures_are_sanitized_with_debug_on_or_off(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $sale = $this->settledSale();
        $token = $this->token();
        $this->app->bind(ConfirmedSalesDocuments::class, fn () => new class implements ConfirmedSalesDocuments
        {
            public function find(string $actorId, ?string $saleId = null, ?string $folio = null): ConfirmedSaleDocument
            {
                throw new \RuntimeException('RECEIPT_SECRET SELECT bank FROM private C:\\private\\source.php');
            }
        });
        $response = $this->api('/sales/'.$sale->sale->id.'/receipt', $token)->assertStatus(500);
        foreach (['RECEIPT_SECRET', 'SELECT bank', 'source.php', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }
}
