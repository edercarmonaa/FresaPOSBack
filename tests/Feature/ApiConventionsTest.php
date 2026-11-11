<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\ApiResponder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\Api\ProbeConflict;
use Tests\Fixtures\Api\ProbeRequest;
use Tests\TestCase;

class ApiConventionsTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('es');
        $this->logs = new TestHandler;
        $handler = $this->logs;
        Log::extend('api-memory', static fn () => new Logger('api-test', [$handler]));
        config(['logging.default' => 'api-memory', 'logging.channels.api-memory' => ['driver' => 'api-memory']]);
        Auth::viaRequest('api-probe', fn () => null);
        config(['auth.guards.api-probe' => ['driver' => 'api-probe']]);
        Gate::define('api-probe-denied', fn (?Authenticatable $user) => false);
        app(ApiExceptionMappings::class)->register(ProbeConflict::class, ApiCode::CONFLICT);

        // Estas rutas existen exclusivamente en la aplicación arrancada por PHPUnit.
        Route::prefix('api/v1/_test')->middleware('api')->group(function (): void {
            Route::get('status/{status}', function (int $status): never {
                throw new HttpException($status, 'INTERNAL_SECRET', headers: $status === 503 ? ['Retry-After' => '30'] : []);
            });
            Route::get('auth', fn () => ['ok' => true])->middleware('auth:api-probe');
            Route::get('forbidden', function (): void {
                Gate::authorize('api-probe-denied');
            });
            Route::post('validation', fn (ProbeRequest $request, ApiResponder $responses) => $responses->success($request, $request->validated()));
            Route::get('domain', function (): never {
                throw new ProbeConflict('PRIVATE_DOMAIN_STATE');
            });
            Route::get('runtime', function (): never {
                throw new RuntimeException('INTERNAL_SECRET C:\\private\\server.php password=secret');
            });
            Route::get('query', function (): never {
                throw new QueryException('private_connection', 'select password from private_table where token = ?', ['SECRET_BINDING'], new \PDOException('SQLSTATE_PRIVATE'));
            });
            Route::get('direct', fn () => response()->json(['sql' => 'INTERNAL_SECRET'], 500));
            Route::get('response-exception', function (): never {
                throw new HttpResponseException(response()->json(['sql' => 'INTERNAL_SECRET'], 500));
            });
            Route::get('log', function (): array {
                Log::info('API probe');

                return ['ok' => true];
            });
            Route::get('reported', function (): array {
                report(new RuntimeException('REPORTED_SECRET'));

                return ['ok' => true];
            });
        });
        Route::get('_test/web-error', function (): never {
            throw new RuntimeException('WEB_SECRET');
        });
        Route::get('_test/web-log', function (): string {
            Log::info('Web probe');

            return 'web';
        });
        Route::get('livewire/_test-error', function (): never {
            throw new RuntimeException('LIVEWIRE_SECRET');
        });
    }

    public function test_ping_has_localized_envelope_and_the_only_permanent_api_route(): void
    {
        $response = $this->get('/api/v1/ping', ['X-Request-Id' => 'client_123-ABC']);
        $response->assertOk()->assertHeader('X-Request-Id', 'client_123-ABC')->assertExactJson([
            'success' => true, 'code' => 'OPERATION_SUCCESS', 'message' => 'Operación completada correctamente.',
            'data' => ['status' => 'ok'], 'meta' => ['request_id' => 'client_123-ABC'],
        ]);
        $this->assertSame('/api/v1/ping', route('api.v1.core.ping', absolute: false));
        $this->app->setLocale('en');
        $this->get('/api/v1/ping')->assertJsonPath('message', 'Operation completed successfully.');
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/') && ! str_contains($route->uri(), '_test'));
        $this->assertEqualsCanonicalizing(['api.v1.core.ping', 'api.v1.auth.login', 'api.v1.auth.logout', 'api.v1.auth.me', 'api.v1.auth.password'], $routes->map(fn ($route) => $route->getName())->values()->all());
    }

    public static function errorStatuses(): array
    {
        return [[400, 'BAD_REQUEST'], [403, 'FORBIDDEN'], [404, 'NOT_FOUND'], [409, 'CONFLICT'], [422, 'VALIDATION_ERROR'], [429, 'TOO_MANY_REQUESTS'], [500, 'INTERNAL_ERROR'], [503, 'SERVICE_UNAVAILABLE']];
    }

    #[DataProvider('errorStatuses')]
    public function test_http_errors_are_uniform_without_client_accept_header(int $status, string $code): void
    {
        $response = $this->get('/api/v1/_test/status/'.$status, ['X-Request-Id' => 'status-'.$status]);
        $response->assertStatus($status)->assertHeader('X-Request-Id', 'status-'.$status)
            ->assertJsonPath('success', false)->assertJsonPath('code', $code)->assertJsonPath('meta.request_id', 'status-'.$status)
            ->assertJsonMissingPath('data')->assertJsonMissingPath('errors')->assertDontSee('INTERNAL_SECRET');
        $this->assertEqualsCanonicalizing(['success', 'code', 'message', 'meta'], array_keys($response->json()));
        if ($status === 503) {
            $response->assertHeader('Retry-After', '30');
        }
    }

    public function test_unauthenticated_is_401_without_login_route_or_redirect(): void
    {
        $this->get('/api/v1/_test/auth', ['X-Request-Id' => 'auth-request'])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')->assertHeaderMissing('Location')->assertHeader('X-Request-Id', 'auth-request')->assertJsonPath('meta.request_id', 'auth-request');
    }

    public function test_authorization_and_domain_mapping(): void
    {
        $this->get('/api/v1/_test/forbidden', ['X-Request-Id' => 'forbidden-request'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN')->assertHeader('X-Request-Id', 'forbidden-request')->assertJsonPath('meta.request_id', 'forbidden-request');
        $this->get('/api/v1/_test/domain')->assertConflict()->assertJsonPath('code', 'CONFLICT')->assertDontSee('PRIVATE_DOMAIN_STATE');
    }

    public function test_validation_preserves_nested_field_keys_and_spanish_messages(): void
    {
        $response = $this->postJson('/api/v1/_test/validation', ['items' => [[]]], ['X-Request-Id' => 'validation-probe']);
        $response->assertUnprocessable()->assertHeader('X-Request-Id', 'validation-probe')->assertJsonPath('meta.request_id', 'validation-probe')->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.')->assertJsonMissingPath('data');
        $this->assertSame(['El campo nombre del elemento es obligatorio.'], $response->json('errors')['items.0.name']);
        $this->postJson('/api/v1/_test/validation', ['name' => 'probe', 'items' => [['name' => 'nested']]])->assertOk()->assertJsonPath('data.items.0.name', 'nested');
        // El diccionario se añadió a una copia, no al traductor global.
        $this->assertSame('The :attribute field is required.', __('validation.required'));
    }

    public function test_unmatched_routes_and_method_not_allowed_keep_ids_and_allow_header(): void
    {
        foreach (['/api/v1/absent', '/api/v2/absent', '/api/absent', '/api'] as $path) {
            $response = $this->get($path);
            $response->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
            $this->assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
        }
        $this->post('/api/v1/ping', [], ['X-Request-Id' => 'method-request'])->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')->assertJsonPath('code', 'METHOD_NOT_ALLOWED')->assertHeader('X-Request-Id', 'method-request')->assertJsonPath('meta.request_id', 'method-request');
    }

    public function test_real_throttle_produces_429_and_preserves_retry_after(): void
    {
        RateLimiter::for('api-v1', fn () => Limit::perMinute(1)->by('api-test-limit'));
        $this->get('/api/v1/ping')->assertOk();
        $response = $this->get('/api/v1/ping');
        $response->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
    }

    public static function invalidIds(): array
    {
        return [[''], [str_repeat('x', 65)], ["bad\r\nInjected: header"], ['bad id'], ['bad/id'], ['á']];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_request_ids_are_replaced_with_ulid(string $id): void
    {
        $response = $this->get('/api/v1/ping', ['X-Request-Id' => $id]);
        $response->assertOk();
        $actual = $response->json('meta.request_id');
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $actual);
        $response->assertHeader('X-Request-Id', $actual);
    }

    public function test_boundary_id_and_consecutive_generated_ids(): void
    {
        $id = str_repeat('A', 64);
        $this->get('/api/v1/ping', ['X-Request-Id' => $id])->assertJsonPath('meta.request_id', $id);
        $this->flushHeaders();
        $first = $this->get('/api/v1/ping')->json('meta.request_id');
        $second = $this->get('/api/v1/ping')->json('meta.request_id');
        $this->assertNotSame($first, $second);
    }

    public static function internalErrors(): array
    {
        return [[false, 'runtime'], [true, 'runtime'], [false, 'query'], [true, 'query']];
    }

    #[DataProvider('internalErrors')]
    public function test_internal_errors_never_leak_and_are_logged_with_same_id(bool $debug, string $kind): void
    {
        config(['app.debug' => $debug]);
        $id = 'failure-'.$kind.'-'.($debug ? 'debug' : 'production');
        $response = $this->get('/api/v1/_test/'.$kind, ['X-Request-Id' => $id]);
        $response->assertStatus(500)->assertHeader('X-Request-Id', $id)->assertJsonPath('code', 'INTERNAL_ERROR')->assertJsonPath('meta.request_id', $id);
        foreach (['INTERNAL_SECRET', 'SQLSTATE_PRIVATE', 'private_connection', 'private_table', 'SECRET_BINDING', 'server.php', 'trace', 'exception'] as $secret) {
            $response->assertDontSee($secret);
        }
        $records = $this->logs->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame($id, $records[0]->context['request_id']);
        $this->assertInstanceOf($kind === 'query' ? QueryException::class : RuntimeException::class, $records[0]->context['exception']);
    }

    public function test_direct_error_responses_and_response_exceptions_are_sanitized(): void
    {
        foreach (['direct', 'response-exception'] as $path) {
            $this->get('/api/v1/_test/'.$path)->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')->assertDontSee('INTERNAL_SECRET');
        }
    }

    public function test_logs_share_request_context_and_do_not_leak_it_to_web_or_next_request(): void
    {
        $this->get('/api/v1/_test/log', ['X-Request-Id' => 'first-request'])->assertOk();
        $this->get('/api/v1/_test/log', ['X-Request-Id' => 'second-request'])->assertOk();
        $this->get('/_test/web-log')->assertOk()->assertHeaderMissing('X-Request-Id');
        $records = $this->logs->getRecords();
        $this->assertSame('first-request', $records[0]->context['request_id']);
        $this->assertSame('second-request', $records[1]->context['request_id']);
        $this->assertArrayNotHasKey('request_id', $records[2]->context);
        $this->assertArrayNotHasKey('request_id', Log::sharedContext());
    }

    public function test_explicit_report_is_not_silenced_even_when_request_succeeds(): void
    {
        $this->get('/api/v1/_test/reported', ['X-Request-Id' => 'reported-request'])->assertOk();
        $records = $this->logs->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('reported-request', $records[0]->context['request_id']);
        $this->assertSame('REPORTED_SECRET', $records[0]->context['exception']->getMessage());
    }

    public function test_web_and_livewire_retain_native_behavior_but_json_errors_use_convention(): void
    {
        config(['app.debug' => false]);
        $this->get('/')->assertOk()->assertHeaderMissing('X-Request-Id')->assertSee('data-csrf', false);
        $this->get('/up')->assertOk()->assertHeaderMissing('X-Request-Id');
        $this->get('/_test/web-error')->assertStatus(500)->assertHeaderMissing('X-Request-Id')->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->getJson('/_test/web-error')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')->assertHeaderMissing('X-Request-Id');
        $this->getJson('/livewire/_test-error', ['X-Livewire' => 'true'])->assertStatus(500)->assertJsonMissingPath('success')->assertJsonMissingPath('code')->assertHeaderMissing('X-Request-Id');
    }
}
