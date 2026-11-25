<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashMovements;
use Modules\Core\Contracts\DecimalAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CheckoutSqlServerTestCase;
use Tests\Support\WorkerDiagnostics;

class CheckoutConcurrencyTest extends CheckoutSqlServerTestCase
{
    public static function races(): array
    {
        $modes = ['two_keys', 'same', 'different', 'two_sales', 'mixed', 'edit', 'session', 'rollback', 'lost', 'deadlock', 'day_change', 'utc_local'];

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
    public function test_independent_http_sql_sessions_protect_checkout_and_daily_sequence(string $mode): void
    {
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        // El reloj financiero de cada worker puede cruzar medianoche; acceso fixture vigente.
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $this->database->table('personal_access_tokens')->update(['expires_at' => '2030-01-01']);
        $sale = $this->sale();
        $other = in_array($mode, ['two_sales', 'day_change', 'utc_local'], true) ? $this->sale() : $sale;
        $same = in_array($mode, ['same', 'different', 'rollback', 'lost'], true);
        $keys = ['first' => (string) Str::uuid()];
        $keys['second'] = $same ? $keys['first'] : (string) Str::uuid();
        $ids = ['first' => $sale->sale->id, 'second' => $other->sale->id];
        $revisions = ['first' => $this->revision($sale), 'second' => $this->revision($other)];
        $a = $mode === 'mixed' ? [$this->payment('cash', '80', '100'), $this->payment('card', '100')] : [$this->payment()];
        $payments = ['first' => $a, 'second' => $mode === 'different' ? [$this->payment('card')] : $a];
        $times = ['first' => '2026-11-17T20:00:00Z', 'second' => '2026-11-17T20:00:00Z'];
        if ($mode === 'day_change') {
            $times = ['first' => '2026-11-18T05:59:59Z', 'second' => '2026-11-18T06:00:00Z'];
        }
        if ($mode === 'utc_local') {
            $times = ['first' => '2026-11-18T00:00:00Z', 'second' => '2026-11-18T05:59:59Z'];
        }
        if ($mode === 'deadlock') {
            $this->database->table('sales_daily_sequences')->insert(['business_date' => '2026-11-17', 'last_value' => 1]);
        }
        $actor = $this->actor;
        $sessionId = $this->sessionId;
        $directory = base_path('.tools/checkout-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'token', 'actor', 'sessionId', 'keys', 'ids', 'revisions', 'payments', 'times'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/checkout-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 45);
        $second = new Process([...$command, 'second'], base_path(), timeout: 45);
        $extra = [];
        try {
            $parallel = ! in_array($mode, ['rollback', 'lost'], true);
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
            if ($parallel) {
                file_put_contents($directory.'/second-start', 'execute');
                $this->waitFile($directory.'/second-attempt.json', $second);
                $spidB = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
                $this->assertNotSame($spidA, $spidB);
                if (in_array($mode, ['same', 'different'], true)) {
                    $second->wait();
                    $pending = json_decode(file_get_contents($directory.'/second-result.json'), true);
                    $this->assertSame(409, $pending['status']);
                    $this->assertSame('OPERATION_IN_PROGRESS', $pending['body']['code']);
                } else {
                    $until = microtime(true) + 8;
                    $wait = null;
                    do {
                        $first->checkTimeout();
                        $second->checkTimeout();
                        $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$spidB]);
                        if ($wait && (int) $wait->blocking_session_id === $spidA) {
                            break;
                        } usleep(20000);
                    } while (microtime(true) < $until);
                    $this->assertNotNull($wait);
                    $this->assertSame($spidA, (int) $wait->blocking_session_id);
                    $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
                }
            }
            file_put_contents($directory.'/release', 'commit');
            $first->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertFileExists($directory.'/first-result.json', $first->getOutput().$first->getErrorOutput());
            $ra = json_decode(file_get_contents($directory.'/first-result.json'), true);
            if (! $parallel || in_array($mode, ['same', 'different'], true)) {
                $retry = new Process([...$command, 'retry'], base_path(), timeout: 35);
                $extra[] = $retry;
                WorkerDiagnostics::run($retry, $directory, 'retry');
                $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
                $rb = json_decode(file_get_contents($directory.'/retry-result.json'), true);
            } else {
                $second->wait();
                $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                $rb = json_decode(file_get_contents($directory.'/second-result.json'), true);
            }
            $rejected = in_array($mode, ['two_keys', 'different', 'mixed', 'edit', 'session'], true);
            $this->assertSame($rejected ? 409 : 200, $rb['status'], json_encode($rb));
            $this->assertSame($mode === 'rollback' ? 500 : 200, $ra['status'], json_encode($ra));
            $closed = $this->database->table('sales_sales')->where('status', 'closed')->orderBy('folio')->get();
            $count = in_array($mode, ['edit', 'session'], true) ? 0 : (in_array($mode, ['two_sales', 'day_change', 'utc_local'], true) ? 2 : 1);
            $this->assertCount($count, $closed);
            $this->assertSame($mode === 'mixed' ? 2 : $count, $this->database->table('sales_payments')->where('status', 'confirmed')->count());
            $this->assertSame($count, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
            $this->assertSame(0, $this->database->table('cash_movements')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            $folios = $closed->pluck('folio')->all();
            $this->assertCount(count(array_unique($folios)), $folios);
            if ($mode === 'day_change') {
                $this->assertSame(['202611170001', '202611180001'], $folios);
            }
            if (in_array($mode, ['two_sales', 'utc_local'], true)) {
                $this->assertSame(['202611170001', '202611170002'], $folios);
            }
            if ($mode === 'deadlock') {
                $this->assertContains(1205, $rb['transient_sql_codes']);
                $this->assertSame(['202611170002'], $folios);
            }
            if ($mode === 'lost') {
                $this->assertFalse($ra['delivered']);
            }
            $cash = $this->database->table('sales_payments')->where('status', 'confirmed')->where('method_code', 'cash')->sum('applied_amount');
            $balance = app(CashMovements::class)->balance($actor, $sessionId);
            $this->assertSame(DecimalAmount::from('100.0000')->add(DecimalAmount::parse((string) $cash))->value, $balance['expected_cash']);
            file_put_contents(base_path('.tools/checkout-1.5d3-race-'.$mode.'.json'), json_encode(['mode' => $mode, 'database' => 'fresapos_test', 'first_spid' => $spidA, 'second_spid' => $spidB ?? null, 'first_status' => $ra['status'], 'second_status' => $rb['status'], 'closed_sales' => $count, 'folios' => $folios, 'expected_cash' => $balance['expected_cash'], 'transient_sql_codes' => $rb['transient_sql_codes']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach ([$first, $second, ...$extra] as $p) {
                if ($p->isRunning()) {
                    $p->stop(1);
                }
                WorkerDiagnostics::record('process.cleaned', $directory, 'cleanup', $p);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
