<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\SensitiveErrors;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class SecurityDiagnosticsTest extends TestCase
{
    public function test_database_error_logs_preserve_codes_without_sql_values_or_driver_messages(): void
    {
        $handler = new TestHandler;
        Log::extend('security-memory', static fn () => new Logger('security-test', [$handler]));
        config(['logging.default' => 'security-memory', 'logging.channels.security-memory' => ['driver' => 'security-memory']]);
        $previous = new \PDOException('Driver repeated SecretCredential!123');
        $previous->errorInfo = ['23000', 2627, 'Driver repeated SecretCredential!123'];
        $exception = new QueryException('sqlsrv', 'INSERT INTO private_table (pin_hash) VALUES (?)', ['SecretCredential!123'], $previous);
        Route::get('/api/v1/_security/diagnostic', fn () => throw $exception)->middleware('api');
        $this->getJson('/api/v1/_security/diagnostic')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')->assertDontSee('SecretCredential!123');
        app(SensitiveErrors::class)->report($exception, Request::create('/login'));
        $records = $handler->getRecords();
        $this->assertCount(2, $records);
        foreach ($records as $record) {
            $formatted = (new LineFormatter)->format($record);
            foreach (['SecretCredential!123', 'private_table', 'pin_hash', 'Driver repeated'] as $secret) {
                $this->assertStringNotContainsString($secret, $formatted);
            }
            $this->assertSame('23000', $record->context['sqlstate']);
            $this->assertSame(2627, $record->context['driver_code']);
            $this->assertArrayHasKey('source_line', $record->context);
        }
    }
}
