<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\DiscountsSqlServerTestCase;
use Tests\Support\WorkerDiagnostics;

class SaleDiscountsConcurrencyTest extends DiscountsSqlServerTestCase
{
    public static function races(): array
    {
        $modes = ['two_discounts', 'add', 'quantity', 'remove', 'cancel', 'checkout', 'replace', 'delete', 'permission', 'lost', 'deadlock', 'rollback', 'same'];

        return array_combine($modes, array_map(fn ($mode) => [$mode], $modes));
    }

    private function waitFile(string $file, Process $process): void
    {
        $waitStarted = hrtime(true);
        $process->checkTimeout();
        $until = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            $process->checkTimeout();
            usleep(20000);
        }
        WorkerDiagnostics::record('barrier.observed', dirname($file), 'observed', $process, ['signal' => basename($file), 'found' => is_file($file), 'elapsed_ms' => (hrtime(true) - $waitStarted) / 1000000]);
        $diagnostics = $process->getErrorOutput().$process->getOutput();
        if (! is_file($file)) {
            foreach (glob(dirname($file).'/*result.json') as $result) {
                $diagnostics .= file_get_contents($result);
            }
            try {
                $diagnostics .= json_encode(['running' => $process->isRunning(), 'exit_code' => $process->getExitCode(), 'requests' => $this->database->select('SELECT session_id,status,command,wait_type,blocking_session_id,open_transaction_count FROM sys.dm_exec_requests WHERE database_id=DB_ID() AND session_id<>@@SPID')], JSON_THROW_ON_ERROR);
            } catch (\Throwable $diagnosticFailure) {
                $diagnostics .= ' Diagnostic unavailable: '.$diagnosticFailure::class;
            }
        }
        $this->assertFileExists($file, $diagnostics);
    }

    #[DataProvider('races')]
    public function test_independent_sql_http_connections_serialize_discount_intentions(string $mode): void
    {
        $sale = $this->sale(true);
        if (in_array($mode, ['replace', 'delete'], true)) {
            $sale = $this->discount($sale, new DiscountProposal('percent', 10));
        }
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $tokens = ['first' => $token, 'second' => $token];
        $racer = null;
        if ($mode === 'permission') {
            $users = app(UserAdministration::class);
            $user = $users->create($this->actor, ['name' => 'Discount racer', 'username' => 'discount.racer', 'password' => 'EmployeeInitial!123']);
            $racer = $user->id;
            $users->assignRole($this->actor, $racer, 'supervisor');
            app(IdentityAccess::class)->changePassword($racer, 'EmployeeInitial!123', 'EmployeeChanged!123');
            Auth::forgetGuards();
            $tokens['second'] = $this->postJson('/api/v1/auth/login', ['username' => 'discount.racer', 'password' => 'EmployeeChanged!123'])->assertOk()->json('data.token');
        }
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $this->database->table('personal_access_tokens')->update(['expires_at' => '2030-01-01']);
        $keys = ['first' => (string) Str::uuid()];
        $keys['second'] = in_array($mode, ['same', 'lost', 'rollback'], true) ? $keys['first'] : (string) Str::uuid();
        $base = '/api/v1/sales/'.$sale->sale->id;
        $operations = array_fill_keys(['first', 'second'], $base.'/discount');
        $methods = array_fill_keys(['first', 'second'], 'PUT');
        $payloads = ['first' => ['type' => 'percent', 'value' => 20], 'second' => ['type' => 'percent', 'value' => 30]];
        if (in_array($mode, ['same', 'lost', 'rollback'], true)) {
            $payloads['second'] = $payloads['first'];
        }
        if ($mode === 'delete') {
            $methods['first'] = $methods['second'] = 'DELETE';
            $payloads = ['first' => [], 'second' => []];
        }
        if ($mode === 'add') {
            $operations['second'] = $base.'/items';
            $methods['second'] = 'POST';
            $payloads['second'] = ['product_id' => $this->productId, 'quantity' => 1];
        }
        if ($mode === 'quantity') {
            $operations['second'] = $base.'/items/'.$sale->items[0]->id.'/quantity';
            $methods['second'] = 'PATCH';
            $payloads['second'] = ['quantity' => 2];
        }
        if ($mode === 'remove') {
            $operations['second'] = $base.'/items/'.$sale->items[0]->id;
            $methods['second'] = 'DELETE';
            $payloads['second'] = ['reason_code' => 'QA_REMOVE'];
        }
        if ($mode === 'cancel') {
            $operations['second'] = $base.'/cancel';
            $methods['second'] = 'POST';
            $payloads['second'] = ['reason_code' => 'QA_CANCEL'];
        }
        if ($mode === 'checkout') {
            $operations['second'] = $base.'/checkout';
            $methods['second'] = 'POST';
            $payloads['second'] = ['payments' => [$this->payment()]];
        }
        $revisions = array_fill_keys(['first', 'second'], $this->revision($sale));
        $actor = $this->actor;
        $sessionId = $this->sessionId;
        $saleId = $sale->sale->id;
        $directory = base_path('.tools/discounts-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'tokens', 'keys', 'operations', 'methods', 'payloads', 'actor', 'sessionId', 'saleId', 'revisions', 'racer'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/discounts-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 45);
        $second = new Process([...$command, 'second'], base_path(), timeout: 45);
        $retry = null;
        try {
            $parallel = ! in_array($mode, ['lost', 'rollback'], true);
            WorkerDiagnostics::start($first, $directory, 'first');
            $prepared = ['first' => $first];
            if ($parallel) {
                WorkerDiagnostics::start($second, $directory, 'second');
                $prepared['second'] = $second;
            }
            WorkerDiagnostics::awaitReady($prepared, $directory);
            file_put_contents($directory.'/first-start', 'execute');
            $this->waitFile($directory.'/holding', $first);
            $spidA = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $spidB = null;
            $wait = null;
            if ($parallel) {
                file_put_contents($directory.'/second-start', 'execute');
                $this->waitFile($directory.'/second-attempt.json', $second);
                $spidB = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
                $this->assertNotSame($spidA, $spidB);
                if ($mode === 'same') {
                    $second->wait();
                    $pending = json_decode(file_get_contents($directory.'/second-result.json'), true);
                    $this->assertSame(409, $pending['status']);
                    $this->assertSame('OPERATION_IN_PROGRESS', $pending['body']['code']);
                } else {
                    $until = microtime(true) + 8;
                    do {
                        $first->checkTimeout();
                        $second->checkTimeout();
                        $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$spidB]);
                        if ($wait && (int) $wait->blocking_session_id === $spidA) {
                            break;
                        }usleep(20000);
                    } while (microtime(true) < $until);
                    $this->assertNotNull($wait);
                    $this->assertSame($spidA, (int) $wait->blocking_session_id);
                    $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
                }
            }
            file_put_contents($directory.'/release', 'commit');
            $first->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            if (! $parallel || $mode === 'same') {
                $retry = new Process([...$command, 'retry'], base_path(), timeout: 35);
                WorkerDiagnostics::run($retry, $directory, 'retry');
                $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/retry-result.json'), true);
            } else {
                $second->wait();
                $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            }
            $expected = match ($mode) {
                'same','lost','rollback','deadlock' => 200,'permission' => 403,default => 409
            };
            $this->assertSame($expected, $b['status'], json_encode($b).(is_file($directory.'/second-diagnostics.txt') ? file_get_contents($directory.'/second-diagnostics.txt') : ''));
            $this->assertSame($mode === 'rollback' ? 500 : 200, $a['status'], json_encode($a));
            $current = app(SalesOperations::class)->sale($this->actor, $saleId);
            $expectedAmount = match ($mode) {
                'permission', 'delete' => '0.0000',
                'deadlock' => '54.0000',
                default => '36.0000',
            };
            $this->assertSame('open', $current->sale->state->value);
            $this->assertSame($expectedAmount, $current->sale->discount);
            $this->assertSame(0, $this->database->table('sales_items')->whereNotNull('removed_at')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            $this->assertSame($mode === 'permission' ? 0 : (in_array($mode, ['replace', 'delete'], true) ? 2 : 1), $this->database->table('audit_security_events')->where('event_type', 'like', 'sales.discount.%')->count());
            foreach (['cash_movements', 'sales_payments', 'sales_daily_sequences'] as $table) {
                $this->assertSame(0, $this->database->table($table)->count());
            }
            $this->assertTrue(app(SalesOperations::class)->table($this->actor, $sale->sale->tableId)->isOccupied);
            if ($mode === 'deadlock') {
                $this->assertContains(1205, $b['codes']);
            }
            if ($mode === 'lost') {
                $this->assertFalse($a['delivered']);
            }
            file_put_contents(base_path('.tools/discounts-race-'.$mode.'.json'), json_encode(['database' => 'fresapos_test', 'mode' => $mode, 'first_spid' => $spidA, 'second_spid' => $spidB, 'first_status' => $a['status'], 'second_status' => $b['status'], 'discount' => $expectedAmount, 'transient_sql_codes' => $b['codes']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach (array_filter([$first, $second, $retry]) as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
                WorkerDiagnostics::record('process.cleaned', $directory, 'cleanup', $process);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }rmdir($directory);
        }
    }
}
