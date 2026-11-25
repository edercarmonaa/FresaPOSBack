<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Contracts\ReportData;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReceiptsSqlServerTestCase;

class ReportsHttpTest extends ReceiptsSqlServerTestCase
{
    private static array $samples = [];

    private array $cookies = [];

    private function token(string $username = 'finance.fixture', string $password = 'FinanceChanged!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $report, ?string $token, array $filters = [], ?string $format = null): TestResponse
    {
        Auth::forgetGuards();
        $path = '/api/v1/reports/'.$report.($format ? '/exports/'.$format : '');
        $headers = ['X-Request-Id' => 'reports-contract', ...($token ? ['Authorization' => 'Bearer '.$token] : [])];
        $response = $format ? $this->postJson($path, $filters, $headers) : $this->getJson($path.($filters ? '?'.http_build_query($filters) : ''), $headers);
        if (! $format || $response->status() !== 200) {
            self::$samples[] = ['method' => $format ? 'post' : 'get', 'path' => $format ? '/reports/{report}/exports/{format}' : '/reports/{report}', 'status' => $response->status(), 'body' => $response->json()];
            $response->assertHeader('X-Request-Id', 'reports-contract')->assertJsonPath('meta.request_id', 'reports-contract');
        }

        return $response;
    }

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

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/reports-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    public function test_all_nine_authenticated_queries_use_the_same_application_results_without_mutations(): void
    {
        $this->settledSale();
        $token = $this->token();
        $before = $this->financialState();
        foreach (array_keys(ReportDefinition::NAMES) as $type) {
            $expected = app(OperationalReports::class)->read($this->actor, $type)->jsonSerialize();
            $response = $this->api($type, $token)->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.report', $type);
            $actual = $response->json('data');
            unset($actual['generated_at'],$expected['generated_at']);
            $this->assertSame($expected, $actual);
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $this->assertSame($before, $this->financialState());
    }

    public function test_web_api_and_both_authenticated_downloads_remain_read_only(): void
    {
        $this->settledSale();
        $this->web('GET', '/reports')->assertRedirect(route('login'));
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $before = $this->financialState();
        foreach (array_keys(ReportDefinition::NAMES) as $type) {
            $this->web('GET', '/reports/'.$type)->assertOk()->assertSee(ReportDefinition::NAMES[$type])->assertSee('Exportar Excel');
        }
        $this->web('GET', '/reports/sales?sort=invalid')->assertStatus(422)->assertDontSee('SQLSTATE');
        $this->web('POST', '/reports/sales/exports/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->web('POST', '/reports/sales/exports/xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $token = $this->token();
        foreach (['pdf', 'xlsx'] as $format) {
            $response = $this->api('sales', $token, [], $format)->assertOk();
            $this->assertStringStartsWith($format === 'pdf' ? '%PDF-' : 'PK', $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
        $this->assertSame(4, $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->count());
        $this->assertSame($before, $this->financialState());
        $this->database->table('auth_access_sessions')->where('channel', 'web')->update(['revoked_at' => now()]);
        $this->web('GET', '/reports')->assertRedirect(route('login'));
    }

    public function test_validation_unauthenticated_unknown_report_and_wrong_format_have_safe_uniform_errors(): void
    {
        $this->api('summary', null)->assertUnauthorized();
        $this->api('sales', null, [], 'xlsx')->assertUnauthorized();
        $token = $this->token();
        foreach ([['sort' => 'total;select 1'], ['from' => '2026-02-30', 'until' => '2026-03-01'], ['from' => '2026-11-17'], ['per_page' => 1001], ['total' => '0']] as $filter) {
            $this->api('sales', $token, $filter)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->api('cash-reconciliations', $token, ['product_id' => $this->productId])->assertUnprocessable();
        $this->api('sales', $token, ['sort' => 'invalid'], 'xlsx')->assertUnprocessable();
        Auth::forgetGuards();
        $this->getJson('/api/v1/reports/unknown', ['Authorization' => 'Bearer '.$token])->assertNotFound();
        Auth::forgetGuards();
        $this->postJson('/api/v1/reports/sales/exports/csv', [], ['Authorization' => 'Bearer '.$token])->assertNotFound();
    }

    public static function roles(): array
    {
        return [['supervisor'], ['cashier'], ['waiter']];
    }

    #[DataProvider('roles')]
    public function test_non_administrative_roles_cannot_use_reports_to_bypass_blind_count(string $role): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Reports '.$role, 'username' => 'reports.'.$role, 'password' => 'ReportsInitial!123']);
        $users->assignRole($this->actor, $user->id, $role);
        app(IdentityAccess::class)->changePassword($user->id, 'ReportsInitial!123', 'ReportsChanged!123');
        $token = $this->token('reports.'.$role, 'ReportsChanged!123');
        $before = $this->financialState();
        foreach (['summary', 'sales', 'cash-sessions', 'cash-reconciliations', 'corrections'] as $type) {
            $this->api($type, $token)->assertForbidden();
            $this->api($type, $token, [], 'xlsx')->assertForbidden();
        }
        $this->assertSame($before, $this->financialState());
    }

    public static function revocations(): array
    {
        return [['token'], ['session'], ['inactive']];
    }

    #[DataProvider('revocations')]
    public function test_current_revocation_blocks_queries_and_exports(string $kind): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Reports revoked', 'username' => 'reports.revoked', 'password' => 'ReportsInitial!123']);
        $users->assignRole($this->actor, $user->id, 'admin');
        app(IdentityAccess::class)->changePassword($user->id, 'ReportsInitial!123', 'ReportsChanged!123');
        $token = $this->token('reports.revoked', 'ReportsChanged!123');
        if ($kind === 'token') {
            $this->database->table('personal_access_tokens')->delete();
        } elseif ($kind === 'session') {
            $this->database->table('auth_access_sessions')->where('channel', 'api')->update(['revoked_at' => now()]);
        } else {
            $users->setActive($this->actor, $user->id, false);
        }
        $this->api('sales', $token)->assertUnauthorized();
        $this->api('sales', $token, [], 'pdf')->assertUnauthorized();
    }

    public static function debugModes(): array
    {
        return [[true], [false]];
    }

    public function test_pin_employee_switch_uses_current_permissions_for_reports_and_exports(): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Reports PIN', 'username' => 'reports.pin', 'password' => 'ReportsInitial!123']);
        $users->assignRole($this->actor, $user->id, 'waiter');
        app(IdentityAccess::class)->changePassword($user->id, 'ReportsInitial!123', 'ReportsChanged!123');
        $this->web('POST', '/login', ['username' => 'reports.pin', 'password' => 'ReportsChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/pin', ['current_password' => 'ReportsChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/terminal', ['current_password' => 'FinanceChanged!123'])->assertRedirect();
        $this->web('GET', '/reports/sales')->assertOk();
        $this->web('POST', '/auth/employee', ['username' => 'reports.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->web('GET', '/reports/sales')->assertForbidden();
        $this->web('POST', '/reports/sales/exports/xlsx')->assertForbidden();
    }

    #[DataProvider('debugModes')]
    public function test_internal_failures_do_not_expose_financial_sql_paths_or_secrets(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $token = $this->token();
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $this->app->bind(OperationalReports::class, fn () => new class implements OperationalReports
        {
            public function read(string $actorId, string $report, array $filters = [], ?\Closure $access = null, bool $export = false): ReportData
            {
                throw new \RuntimeException('REPORT_SECRET select password C:\\private\\sql.php');
            }
        });
        $response = $this->api('summary', $token)->assertStatus(500);
        foreach (['REPORT_SECRET', 'select password', 'sql.php', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $web = $this->web('GET', '/reports/summary')->assertStatus(500);
        foreach (['REPORT_SECRET', 'select password', 'sql.php', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $web->getContent());
        }
    }
}
