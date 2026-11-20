<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Tickets\Contracts\SaleReceipts;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\ReceiptsSqlServerTestCase;

class SaleReceiptsWebTest extends ReceiptsSqlServerTestCase
{
    private array $cookies = [];

    private function web(string $method, string $path, array $data = []): TestResponse
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function login(string $username = 'finance.fixture', string $password = 'FinanceChanged!123'): void
    {
        $this->web('POST', '/login', compact('username', 'password'))->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
    }

    private function intent(TestResponse $response): string
    {
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $node = (new DOMXPath($document))->query('//input[@name="intent"]')->item(0);
        $this->assertNotNull($node);

        return $node->getAttribute('value');
    }

    public function test_preview_explicit_print_request_and_double_click_preserve_money_and_audit_once(): void
    {
        $sale = $this->settledSale();
        $this->login();
        $path = '/pos/sales/'.$sale->sale->id.'/receipt';
        $before = $this->financialState();
        $preview = $this->web('GET', $path)->assertOk()->assertSee('VISTA PREVIA')->assertDontSee('data-receipt-print', false);
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
        $intent = $this->intent($preview);
        $print = $this->web('POST', $path.'/print', compact('intent'))->assertOk()->assertSee('REIMPRESIÓN')->assertSee('window.print()', false);
        $this->assertTrue($print->headers->hasCacheControlDirective('no-store'));
        $this->web('POST', $path.'/print', compact('intent'))->assertOk();
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
        $this->assertSame($before, $this->financialState());
        $this->web('POST', $path.'/print', ['intent' => $intent, 'total' => '0'])->assertStatus(422);
    }

    public function test_unauthenticated_and_revoked_web_sessions_cannot_open_or_prepare_receipts(): void
    {
        $sale = $this->settledSale();
        $path = '/pos/sales/'.$sale->sale->id.'/receipt';
        $this->web('GET', $path)->assertRedirect(route('login'));
        $this->login();
        $intent = $this->intent($this->web('GET', $path)->assertOk());
        $this->database->table('auth_access_sessions')->where('channel', 'web')->update(['revoked_at' => now()]);
        $before = $this->financialState();
        $this->web('POST', $path.'/print', compact('intent'))->assertRedirect(route('login'));
        $this->assertSame($before, $this->financialState());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
    }

    public function test_uppercase_ulid_preview_and_reprint_use_one_canonical_intention(): void
    {
        $sale = $this->settledSale();
        $this->login();
        $id = $sale->sale->id;
        $upper = strtoupper($id);
        $preview = $this->web('GET', '/pos/sales/'.$upper.'/receipt')->assertOk();
        $this->assertStringContainsString('/pos/sales/'.$id.'/receipt/print', $preview->getContent());
        $intent = $this->intent($preview);
        $this->web('POST', '/pos/sales/'.$id.'/receipt/print', compact('intent'))->assertOk();
        $this->web('POST', '/pos/sales/'.$upper.'/receipt/print', compact('intent'))->assertOk();
        $payload = json_decode(Crypt::decryptString($intent), true, 512, JSON_THROW_ON_ERROR);
        $receipt = app(SaleReceipts::class)->reprint($this->actor, $upper, $payload['revision'], $payload['key']);
        $this->assertSame($sale->sale->folio, $receipt->folio);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
    }

    public function test_waiter_can_preview_but_cannot_prepare_a_reprint_or_reuse_another_actor_intent(): void
    {
        $sale = $this->settledSale();
        $path = '/pos/sales/'.$sale->sale->id.'/receipt';
        $this->login();
        $intent = $this->intent($this->web('GET', $path)->assertOk());
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Receipt waiter', 'username' => 'receipt.waiter.web', 'password' => 'ReceiptInitial!123']);
        $users->assignRole($this->actor, $user->id, 'waiter');
        app(IdentityAccess::class)->changePassword($user->id, 'ReceiptInitial!123', 'ReceiptChanged!123');
        $this->web('POST', '/logout')->assertRedirect();
        $this->login('receipt.waiter.web', 'ReceiptChanged!123');
        $this->web('GET', $path)->assertOk()->assertDontSee('name="intent"', false)->assertSee('requiere autorización');
        $this->web('POST', $path.'/print', compact('intent'))->assertForbidden();
    }

    public function test_active_correction_blocks_web_and_new_confirmed_version_appears_in_pos_and_receipt(): void
    {
        $sale = $this->settledSale();
        $this->login();
        $path = '/pos/sales/'.$sale->sale->id.'/receipt';
        $intent = $this->intent($this->web('GET', $path)->assertOk());
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        $this->web('GET', $path)->assertConflict()->assertSee('corrección activa');
        $this->correction($row, 'confirm', ['payments' => [$this->payment()]]);
        $this->web('POST', $path.'/print', compact('intent'))->assertConflict();
        $this->web('GET', $path)->assertOk()->assertSee('360.0000')->assertSee('Venta corregida')->assertSee($sale->sale->folio);
        $this->web('GET', '/pos?sale='.$sale->sale->id)->assertOk()->assertSee('Ver ticket / preparar impresión')->assertSee($path, false);
    }

    public function test_long_receipt_has_dynamic_height_safe_print_styles_and_escaped_establishment_text(): void
    {
        $sale = $this->sale();
        for ($line = 0; $line < 25; $line++) {
            $sale = app(SalesOperations::class)->addProduct($this->actor, $sale->sale->id, new AddProduct($this->productId, 1, 'Línea '.$line.' '.str_repeat('Nota larga ', 7)));
        }
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment('cash', $sale->sale->total, $sale->sale->total)], (string) Str::uuid(), $this->revision($sale));
        config(['tickets.establishment.name' => '<script>ESTABLISHMENT_XSS</script>', 'tickets.establishment.address' => str_repeat('Dirección QA extensa ', 15)]);
        $this->login();
        $response = $this->web('GET', '/pos/sales/'.$sale->sale->id.'/receipt')->assertOk()->assertSee('&lt;script&gt;ESTABLISHMENT_XSS&lt;/script&gt;', false)->assertDontSee('<script>ESTABLISHMENT_XSS</script>', false);
        $html = $response->getContent();
        $this->assertSame(26, substr_count($html, '<li class="receipt-line">'));
        foreach (['width:80mm', 'width:74mm', '@media print', '@page{size:auto;margin:3mm}', 'break-inside:avoid', 'overflow-wrap:anywhere', 'display:none!important'] as $rule) {
            $this->assertStringContainsString($rule, $html);
        }
        $this->assertStringNotContainsString('height:297mm', $html);
        $this->assertStringNotContainsString('size:80mm auto', $html);
    }

    public function test_pin_switch_rejects_the_previous_employee_intent_and_audits_the_current_employee(): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Receipt PIN', 'username' => 'receipt.pin', 'password' => 'ReceiptInitial!123']);
        $users->assignRole($this->actor, $user->id, 'cashier');
        app(IdentityAccess::class)->changePassword($user->id, 'ReceiptInitial!123', 'ReceiptChanged!123');
        $this->login('receipt.pin', 'ReceiptChanged!123');
        $this->web('POST', '/auth/pin', ['current_password' => 'ReceiptChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->login();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        $sale = $this->settledSale();
        $path = '/pos/sales/'.$sale->sale->id.'/receipt';
        $oldIntent = $this->intent($this->web('GET', $path)->assertOk());
        $this->web('POST', '/auth/employee', ['username' => 'receipt.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->web('POST', $path.'/print', ['intent' => $oldIntent])->assertForbidden();
        $intent = $this->intent($this->web('GET', $path)->assertOk());
        $this->web('POST', $path.'/print', compact('intent'))->assertOk();
        $this->assertSame($user->id, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->value('actor_id'));
    }

    public function test_auxiliary_failure_is_sanitized_in_web_and_does_not_undo_checkout(): void
    {
        config(['app.debug' => true]);
        $sale = $this->settledSale();
        $this->login();
        $path = '/pos/sales/'.$sale->sale->id.'/receipt';
        $intent = $this->intent($this->web('GET', $path)->assertOk());
        $before = $this->financialState();
        Event::listen(SecurityEvent::class, function ($event): void {
            if ($event->type === 'tickets.reprint.requested') {
                throw new \RuntimeException('WEB_RECEIPT_SECRET SELECT password C:\\private\\receipt.php');
            }
        });
        $response = $this->web('POST', $path.'/print', compact('intent'))->assertStatus(500)->assertSee('la venta y sus pagos se conservan');
        foreach (['WEB_RECEIPT_SECRET', 'SELECT password', 'receipt.php', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($before, $this->financialState());
    }
}
