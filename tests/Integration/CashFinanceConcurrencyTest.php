<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashMovements;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinanceSqlServerTestCase;

class CashFinanceConcurrencyTest extends FinanceSqlServerTestCase
{
    public static function races(): array
    {
        return array_map(fn ($mode) => [$mode], ['in_in', 'out_out', 'overspend', 'transition', 'same', 'different', 'rollback', 'lost', 'connection_after_commit']);
    }

    private function waitFile(string $file, Process $process): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }$this->assertFileExists($file, $process->getOutput().$process->getErrorOutput());
    }

    #[DataProvider('races')]
    public function test_independent_sql_sessions_preserve_money_and_durable_result(string $mode): void
    {
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $actor = $this->actor;
        $sessionId = $this->sessionId;
        $key = (string) Str::uuid();
        $same = in_array($mode, ['same', 'different', 'rollback', 'lost', 'connection_after_commit'], true);
        $keys = ['first' => $key, 'second' => $same ? $key : (string) Str::uuid()];
        $type = in_array($mode, ['out_out', 'overspend'], true) ? 'cash_out' : 'cash_in';
        $amounts = match ($mode) {
            'out_out' => ['30', '40'],'overspend' => ['70', '70'],'in_in' => ['10', '20'],'different' => ['10', '20'],default => ['10', '10']
        };
        $payloads = [];
        foreach (['first', 'second'] as $i => $who) {
            $payloads[$who] = ['cash_session_id' => $sessionId, 'type' => $type, 'amount' => $amounts[$i], 'concept' => 'Carrera autorizada'];
        }
        $directory = base_path('.tools/finance-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'actor', 'sessionId', 'token', 'keys', 'payloads'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/http-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 40);
        $second = new Process([...$command, 'second'], base_path(), timeout: 40);
        $extra = [];
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $spidA = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $parallel = in_array($mode, ['in_in', 'out_out', 'overspend', 'transition', 'same', 'different'], true);
            if ($parallel) {
                $second->start();
                $this->waitFile($directory.'/second-attempt.json', $second);
                $spidB = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
                $this->assertNotSame($spidA, $spidB);
                if (in_array($mode, ['same', 'different'], true)) {
                    $second->wait();
                    $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                    $pending = json_decode(file_get_contents($directory.'/second-result.json'), true);
                    $this->assertSame(409, $pending['status']);
                    $this->assertSame('OPERATION_IN_PROGRESS', $pending['body']['code']);
                } else {
                    $deadline = microtime(true) + 7;
                    $wait = null;
                    do {
                        $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$spidB]);
                        if ($wait && (int) $wait->blocking_session_id === $spidA) {
                            break;
                        }usleep(20000);
                    } while (microtime(true) < $deadline);
                    $this->assertNotNull($wait);
                    $this->assertSame($spidA, (int) $wait->blocking_session_id);
                    $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
                }
            }
            file_put_contents($directory.'/release', 'commit');
            $first->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            if ($parallel && ! in_array($mode, ['same', 'different'], true)) {
                $second->wait();
                $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            } else {
                $retry = new Process([...$command, 'retry'], base_path(), timeout: 25);
                $extra[] = $retry;
                $retry->run();
                $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/retry-result.json'), true);
            }
            $expectedSecond = in_array($mode, ['overspend', 'transition', 'different'], true) ? 409 : 201;
            $this->assertSame($expectedSecond, $b['status'], json_encode($b));
            $this->assertSame($mode === 'rollback' ? 500 : ($mode === 'transition' ? 200 : 201), $a['status'], json_encode($a));
            $count = in_array($mode, ['in_in', 'out_out'], true) ? 2 : ($mode === 'transition' ? 0 : 1);
            $balance = match ($mode) {
                'in_in' => '130.0000','out_out' => '30.0000','overspend' => '30.0000','transition' => '100.0000',default => '110.0000'
            };
            $this->assertSame($count, $this->database->table('cash_movements')->count());
            $this->assertSame($count, $this->database->table('audit_security_events')->where('event_type', 'cash.movement.recorded')->count());
            $this->assertSame($count, $this->database->table('idempotency_records')->where('state', 'completed')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            $this->assertSame($balance, app(CashMovements::class)->balance($actor, $sessionId)['expected_cash']);
            if ($mode === 'lost') {
                $this->assertFalse($a['delivered']);
            }
            if ($mode === 'connection_after_commit') {
                $this->assertTrue($a['connection_killed']);
                // Un SPID puede reutilizarse al reconectar: el GUID identifica
                // la conexión física sin depender de la asignación de sesiones.
                $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/i', $a['initial_connection_id']);
                $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/i', $a['final_connection_id']);
                $this->assertNotSame($a['initial_connection_id'], $a['final_connection_id']);
            }
            file_put_contents(base_path('.tools/finance-1.5d2b-race-'.$mode.'.json'), json_encode(['mode' => $mode, 'database' => 'fresapos_test', 'first_spid' => $spidA, 'first_status' => $a['status'], 'second_status' => $b['status'], 'movement_count' => $count, 'expected_cash' => $balance, 'pending_records' => 0, 'connection_killed' => $a['connection_killed'] ?? false, 'final_first_spid' => $a['final_spid'] ?? $spidA], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach ([$first, $second, ...$extra] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }rmdir($directory);
        }
    }
}
