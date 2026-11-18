<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ReconciliationSqlServerTestCase;

class CashReconciliationConcurrencyTest extends ReconciliationSqlServerTestCase
{
    public static function races(): array
    {
        $modes = ['two_keys', 'same', 'different', 'other_actor', 'movement_first', 'movement_after', 'checkout_first', 'open_after', 'edit_first', 'lost', 'rollback', 'deadlock'];

        return array_combine($modes, array_map(fn ($mode) => [$mode], $modes));
    }

    private function waitFile(string $file, Process $process): void
    {
        $until = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getErrorOutput().$process->getOutput());
    }

    #[DataProvider('races')]
    public function test_independent_sql_http_connections_serialize_closure_with_financial_writes(string $mode): void
    {
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $tokens = ['first' => $token, 'second' => $token];
        if ($mode === 'other_actor') {
            $users = app(UserAdministration::class);
            $user = $users->create($this->actor, ['name' => 'Concurrent cashier', 'username' => 'closing.racer', 'password' => 'EmployeeInitial!123']);
            $users->assignRole($this->actor, $user->id, 'cashier');
            app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
            Auth::forgetGuards();
            $tokens['second'] = $this->postJson('/api/v1/auth/login', ['username' => 'closing.racer', 'password' => 'EmployeeChanged!123'])->assertOk()->json('data.token');
        }
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $this->database->table('personal_access_tokens')->update(['expires_at' => '2030-01-01']);
        $keys = ['first' => (string) Str::uuid()];
        $keys['second'] = in_array($mode, ['same', 'different', 'lost', 'rollback'], true) ? $keys['first'] : (string) Str::uuid();
        $operations = array_fill_keys(['first', 'second'], '/api/v1/cash/sessions/'.$this->sessionId.'/close');
        $payloads = array_fill_keys(['first', 'second'], ['counted_cash' => '100', 'observation' => 'Conteo físico concurrente']);
        $saleId = null;
        $revision = null;
        if ($mode === 'different') {
            $payloads['second']['counted_cash'] = '101';
        }
        if (in_array($mode, ['movement_first', 'movement_after'], true)) {
            $slot = $mode === 'movement_first' ? 'first' : 'second';
            $operations[$slot] = '/api/v1/cash/movements';
            $payloads[$slot] = ['cash_session_id' => $this->sessionId, 'type' => 'cash_in', 'amount' => '10', 'concept' => 'Ingreso concurrente'];
            if ($mode === 'movement_first') {
                $payloads['second']['counted_cash'] = '110';
            }
        }
        if (in_array($mode, ['checkout_first', 'edit_first'], true)) {
            $sale = $this->sale();
            $saleId = $sale->sale->id;
            $revision = $this->revision($sale);
            if ($mode === 'checkout_first') {
                $operations['first'] = '/api/v1/sales/'.$saleId.'/checkout';
                $payloads['first'] = ['payments' => [$this->payment()]];
                $payloads['second']['counted_cash'] = '280';
            }
        }
        if ($mode === 'open_after') {
            $operations['second'] = '/api/v1/sales';
            $payloads['second'] = ['type' => 'counter'];
        }
        $actor = $this->actor;
        $sessionId = $this->sessionId;
        $directory = base_path('.tools/closing-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'tokens', 'keys', 'operations', 'payloads', 'actor', 'sessionId', 'saleId', 'revision'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/closing-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 45);
        $second = new Process([...$command, 'second'], base_path(), timeout: 45);
        $retry = null;
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $spidA = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $parallel = ! in_array($mode, ['lost', 'rollback'], true);
            $spidB = null;
            $wait = null;
            if ($parallel) {
                $second->start();
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
                    do {
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
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            if (! $parallel || in_array($mode, ['same', 'different'], true)) {
                $retry = new Process([...$command, 'retry'], base_path(), timeout: 35);
                $retry->run();
                $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/retry-result.json'), true);
            } else {
                $second->wait();
                $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            }
            $expected = match ($mode) {
                'two_keys','different','other_actor','movement_after','edit_first' => 409,'open_after' => 503,default => 200
            };
            $this->assertSame($expected, $b['status'], json_encode($b));
            $this->assertSame($mode === 'rollback' ? 500 : ($mode === 'movement_first' ? 201 : 200), $a['status'], json_encode($a));
            $closed = $mode === 'edit_first' ? 0 : 1;
            $this->assertSame($closed, $this->database->table('cash_reconciliations')->count());
            $this->assertSame($closed, $this->database->table('audit_security_events')->where('event_type', 'cash.session.closed')->count());
            $this->assertSame($closed, $this->database->table('audit_security_events')->where('event_type', 'cash.reconciliation.confirmed')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            $this->assertSame($mode === 'movement_first' ? 1 : 0, $this->database->table('cash_movements')->count());
            $this->assertSame($mode === 'checkout_first' ? 1 : 0, $this->database->table('sales_payments')->count());
            if ($mode === 'deadlock') {
                $this->assertContains(1205, $b['codes']);
            }
            if ($mode === 'lost') {
                $this->assertFalse($a['delivered']);
            }
            if ($mode === 'edit_first') {
                $this->assertSame(2, (int) $this->database->table('sales_items')->value('quantity'));
            }
            $summary = $closed ? json_decode($this->database->table('cash_reconciliations')->value('summary'), true) : null;
            if ($summary) {
                $this->assertSame(match ($mode) {
                    'checkout_first' => '280.0000','movement_first' => '110.0000',default => '100.0000'
                }, $summary['expected_cash']);
            }
            file_put_contents(base_path('.tools/reconciliation-race-'.$mode.'.json'), json_encode(['database' => 'fresapos_test', 'mode' => $mode, 'first_spid' => $spidA, 'second_spid' => $spidB, 'first_status' => $a['status'], 'second_status' => $b['status'], 'closures' => $closed, 'expected_cash' => $summary['expected_cash'] ?? null, 'transient_sql_codes' => $b['codes']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach (array_filter([$first, $second, $retry]) as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }
}
