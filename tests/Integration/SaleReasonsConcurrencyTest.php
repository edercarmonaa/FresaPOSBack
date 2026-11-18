<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ReasonsSqlServerTestCase;

class SaleReasonsConcurrencyTest extends ReasonsSqlServerTestCase
{
    public static function races(): array
    {
        $modes = ['remove_remove', 'remove_quantity', 'remove_checkout', 'cancel_checkout', 'two_cancel', 'same_cancel', 'cancel_add', 'deactivate', 'permission', 'lost', 'deadlock', 'rollback'];

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
    public function test_real_independent_sql_http_connections_preserve_controlled_operations(string $mode): void
    {
        $sale = $this->sale(true);
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $tokens = ['first' => $token, 'second' => $token];
        $racer = null;
        if ($mode === 'permission') {
            $users = app(UserAdministration::class);
            $user = $users->create($this->actor, ['name' => 'Reason racer', 'username' => 'reason.racer', 'password' => 'EmployeeInitial!123']);
            $racer = $user->id;
            $users->assignRole($this->actor, $racer, 'supervisor');
            app(IdentityAccess::class)->changePassword($racer, 'EmployeeInitial!123', 'EmployeeChanged!123');
            Auth::forgetGuards();
            $tokens['second'] = $this->postJson('/api/v1/auth/login', ['username' => 'reason.racer', 'password' => 'EmployeeChanged!123'])->assertOk()->json('data.token');
        }
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $this->database->table('personal_access_tokens')->update(['expires_at' => '2030-01-01']);
        $keys = ['first' => (string) Str::uuid()];
        $keys['second'] = in_array($mode, ['same_cancel', 'lost', 'rollback'], true) ? $keys['first'] : (string) Str::uuid();
        $base = '/api/v1/sales/'.$sale->sale->id;
        $remove = str_starts_with($mode, 'remove_');
        $operations = array_fill_keys(['first', 'second'], $remove ? $base.'/items/'.$sale->items[0]->id : $base.'/cancel');
        $methods = array_fill_keys(['first', 'second'], $remove ? 'DELETE' : 'POST');
        $payloads = array_fill_keys(['first', 'second'], ['reason_code' => $remove ? 'QA_REMOVE' : 'QA_CANCEL']);
        $revisions = array_fill_keys(['first', 'second'], $this->revision($sale));
        if ($mode === 'remove_quantity') {
            $operations['second'] .= '/quantity';
            $methods['second'] = 'PATCH';
            $payloads['second'] = ['quantity' => 2];
        }
        if (in_array($mode, ['remove_checkout', 'cancel_checkout'], true)) {
            $operations['second'] = $base.'/checkout';
            $methods['second'] = 'POST';
            $payloads['second'] = ['payments' => [$this->payment()]];
        }
        if ($mode === 'cancel_add') {
            $operations['second'] = $base.'/items';
            $payloads['second'] = ['product_id' => $this->productId, 'quantity' => 1];
        }
        if ($mode === 'deactivate') {
            $operations['first'] = '/api/v1/sales/reasons/'.$this->cancellation['id'].'/status';
            $methods['first'] = 'PATCH';
            $payloads['first'] = ['is_active' => false];
            $revisions['first'] = '"'.$this->cancellation['revision'].'"';
        }
        $actor = $this->actor;
        $sessionId = $this->sessionId;
        $saleId = $sale->sale->id;
        $directory = base_path('.tools/reasons-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'tokens', 'keys', 'operations', 'methods', 'payloads', 'actor', 'sessionId', 'saleId', 'revisions', 'racer'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/reasons-worker.php'), $directory];
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
                if ($mode === 'same_cancel') {
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
                        }
                        usleep(20000);
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
            if (! $parallel || $mode === 'same_cancel') {
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
                'same_cancel', 'lost', 'rollback', 'deadlock' => 200, 'permission' => 403, default => 409,
            };
            $this->assertSame($expected, $b['status'], json_encode($b).(is_file($directory.'/second-diagnostics.txt') ? file_get_contents($directory.'/second-diagnostics.txt') : ''));
            $this->assertSame($mode === 'rollback' ? 500 : 200, $a['status'], json_encode($a));
            $cancelled = ! $remove && ! in_array($mode, ['deactivate', 'permission'], true);
            $this->assertSame($cancelled ? 'cancelled' : 'open', $this->database->table('sales_sales')->value('status'));
            $this->assertSame($remove ? 1 : 0, $this->database->table('sales_items')->whereNotNull('removed_at')->count());
            $this->assertSame($cancelled ? 1 : 0, $this->database->table('audit_security_events')->where('event_type', 'sales.cancelled')->count());
            $this->assertSame($remove ? 1 : 0, $this->database->table('audit_security_events')->where('event_type', 'sales.item_removed')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            foreach (['cash_movements', 'sales_payments', 'sales_daily_sequences'] as $table) {
                $this->assertSame(0, $this->database->table($table)->count());
            }
            $this->assertSame(! $cancelled, app(SalesOperations::class)->table($this->actor, $sale->sale->tableId)->isOccupied);
            if ($mode === 'deadlock') {
                $this->assertContains(1205, $b['codes']);
            }
            if ($mode === 'lost') {
                $this->assertFalse($a['delivered']);
            }
            file_put_contents(base_path('.tools/reasons-race-'.$mode.'.json'), json_encode(['database' => 'fresapos_test', 'mode' => $mode, 'first_spid' => $spidA, 'second_spid' => $spidB, 'first_status' => $a['status'], 'second_status' => $b['status'], 'cancelled' => $cancelled, 'transient_sql_codes' => $b['codes']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach (array_filter([$first, $second, $retry]) as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
