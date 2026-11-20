<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashClosings;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CorrectionsSqlServerTestCase;
use Tests\Support\FinancialIntegrity;

class SaleCorrectionsConcurrencyTest extends CorrectionsSqlServerTestCase
{
    public static function races(): array
    {
        $modes = ['two_reopens', 'reopen_close', 'reopen_count', 'two_confirms', 'checkout', 'discount', 'products', 'closing', 'table', 'same', 'different', 'lost', 'rollback', 'deadlock', 'refund', 'unchanged'];

        return array_combine($modes, array_map(fn ($mode) => [$mode], $modes));
    }

    private function waitFile(string $file, Process $process): void
    {
        $until = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            usleep(20000);
        }
        $diagnostics = $process->getErrorOutput().$process->getOutput();
        if (! is_file($file)) {
            foreach (glob(dirname($file).'/*exception.txt') as $failure) {
                $diagnostics .= file_get_contents($failure);
            }foreach (glob(dirname($file).'/*result.json') as $result) {
                $diagnostics .= file_get_contents($result);
            }
        }
        $this->assertFileExists($file, $diagnostics);
    }

    #[DataProvider('races')]
    public function test_independent_sql_http_connections_preserve_financial_correction_invariants(string $mode): void
    {
        $paid = $this->settledSale($mode === 'table');
        $saleId = $paid->sale->id;
        $sessionId = $this->sessionId;
        $initialPayments = $this->database->table('sales_payments')->get()->all();
        $sequence = $this->database->table('sales_daily_sequences')->get()->all();
        $other = null;
        if ($mode === 'table') {
            $other = app(SalesOperations::class)->open($this->actor, new OpenSale(SaleType::Table, $paid->sale->tableId));
        }
        $base = '/api/v1/sales/'.$saleId;
        $keys = ['first' => (string) Str::uuid(), 'second' => (string) Str::uuid()];
        if (in_array($mode, ['same', 'lost'], true)) {
            $keys['second'] = $keys['first'];
        }
        $operations = array_fill_keys(['first', 'second'], $base.'/correction/confirm');
        $methods = array_fill_keys(['first', 'second'], 'POST');
        $payloads = array_fill_keys(['first', 'second'], ['payments' => [$this->payment('cash', '180', '200')]]);
        if (in_array($mode, ['two_reopens', 'reopen_close', 'reopen_count', 'table'], true)) {
            $operations = array_fill_keys(['first', 'second'], $base.'/reopen');
            $payloads = ['first' => [], 'second' => []];
            $revisions = array_fill_keys(['first', 'second'], $this->revision($paid));
        } else {
            $row = $this->reopen($paid);
            if ($mode === 'refund') {
                $row = $this->correction($row, 'edit', ['action' => 'discount', 'type' => 'percent', 'value' => 20]);
                $payloads = ['first' => ['payments' => []], 'second' => ['payments' => []]];
            } elseif ($mode === 'unchanged') {
                $payloads = ['first' => ['payments' => []], 'second' => ['payments' => []]];
            } else {
                $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
            }
            $revisions = array_fill_keys(['first', 'second'], '"'.$row['revision'].'"');
        }
        if ($mode === 'reopen_close') {
            $operations['second'] = '/api/v1/cash/sessions/'.$sessionId.'/close';
            $payloads['second'] = ['counted_cash' => '280', 'observation' => 'Conteo concurrente'];
            $revisions['second'] = null;
        }
        if ($mode === 'reopen_count') {
            $operations['first'] = '/api/v1/cash/sessions/'.$sessionId.'/close';
            $payloads['first'] = ['counted_cash' => '280', 'observation' => 'Arqueo previo a reapertura'];
            $revisions['first'] = null;
        }
        if ($mode === 'closing') {
            $operations['second'] = '/api/v1/cash/sessions/'.$sessionId.'/close';
            $payloads['second'] = ['counted_cash' => '460', 'observation' => 'Conteo tras corrección'];
            $revisions['second'] = null;
        }
        if ($mode === 'checkout') {
            $operations['second'] = $base.'/checkout';
            $revisions['second'] = $this->revision($paid);
        }
        if ($mode === 'discount') {
            $operations['second'] = $base.'/correction';
            $methods['second'] = 'PATCH';
            $payloads['second'] = ['action' => 'discount', 'type' => 'percent', 'value' => 20];
        }
        if ($mode === 'products') {
            $operations['first'] = $base.'/correction';
            $methods['first'] = 'PATCH';
            $payloads['first'] = ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 3];
        }
        if ($mode === 'table') {
            $operations['second'] = '/api/v1/sales';
            $payloads['second'] = ['type' => 'table', 'table_id' => $paid->sale->tableId];
            $revisions['second'] = null;
        }
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $this->database->table('personal_access_tokens')->update(['expires_at' => '2030-01-01']);
        $directory = base_path('.tools/corrections-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'keys', 'operations', 'methods', 'payloads', 'revisions', 'token', 'saleId', 'sessionId'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/corrections-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 45);
        $second = new Process([...$command, 'second'], base_path(), timeout: 45);
        $retry = null;
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $spidA = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $second->start();
            $this->waitFile($directory.'/second-attempt.json', $second);
            $spidB = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
            $this->assertNotSame($spidA, $spidB);
            $wait = null;
            $pending = null;
            if (in_array($mode, ['same', 'lost'], true)) {
                $second->wait();
                $pending = json_decode(file_get_contents($directory.'/second-result.json'), true);
                $this->assertSame(409, $pending['status'], json_encode($pending));
                $this->assertSame('OPERATION_IN_PROGRESS', $pending['body']['code']);
            } else {
                $until = microtime(true) + 8;
                do {
                    $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$spidB]);
                    if ($wait && (int) $wait->blocking_session_id === $spidA) {
                        break;
                    }usleep(20000);
                } while (microtime(true) < $until);
                $this->assertNotNull($wait);
                $this->assertSame($spidA, (int) $wait->blocking_session_id);
                $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
            }
            file_put_contents($directory.'/release', 'continue');
            $first->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            if (in_array($mode, ['same', 'lost'], true)) {
                $retry = new Process([...$command, 'retry'], base_path(), timeout: 35);
                $retry->run();
                $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/retry-result.json'), true);
            } else {
                $second->wait();
                $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            }
            $expectedSecond = in_array($mode, ['same', 'lost', 'rollback', 'deadlock', 'closing'], true) ? 200 : 409;
            $this->assertSame($mode === 'rollback' ? 500 : ($mode === 'refund' ? 409 : 200), $a['status'], json_encode($a));
            $this->assertSame($expectedSecond, $b['status'], json_encode($b));
            if ($mode === 'deadlock') {
                $this->assertContains(1205, $b['codes']);
            }if ($mode === 'lost') {
                $this->assertFalse($a['delivered']);
            }
            $current = app(SalesOperations::class)->sale($this->actor, $saleId);
            $confirmed = ! in_array($mode, ['two_reopens', 'reopen_close', 'reopen_count', 'table', 'products', 'refund', 'unchanged'], true);
            $this->assertSame($confirmed ? '360.0000' : '180.0000', $current->sale->total);
            $this->assertSame($paid->sale->folio, $current->sale->folio);
            $this->assertSame($paid->sale->closedAt, $current->sale->closedAt);
            $this->assertSame($paid->sale->createdAt, $current->sale->createdAt);
            $this->assertSame('closed', $current->sale->state->value);
            $this->assertEquals($initialPayments, $this->database->table('sales_payments')->where('id', $initialPayments[0]->id)->get()->all());
            $this->assertEquals($sequence, $this->database->table('sales_daily_sequences')->get()->all());
            $this->assertSame($confirmed ? 2 : 1, $this->database->table('sales_payments')->count());
            $this->assertSame(0, $this->database->table('cash_movements')->count());
            $this->assertSame($confirmed ? 1 : 0, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            $this->assertSame(0, $this->database->table('sales_corrections')->whereNotNull('applying_transaction_id')->count());
            $active = in_array($mode, ['two_reopens', 'reopen_close', 'table', 'products', 'refund'], true);
            $this->assertSame($active ? 1 : 0, $this->database->table('sales_corrections')->whereNull('completed_at')->count());
            $closed = in_array($mode, ['closing', 'reopen_count'], true);
            $this->assertSame($closed ? 'closed' : 'open', $this->database->table('cash_sessions')->where('id', $sessionId)->value('status'));
            $this->assertSame($closed ? 1 : 0, $this->database->table('cash_reconciliations')->count());
            $balance = $closed ? app(CashClosings::class)->reconciliation($this->actor, $sessionId)['expected_cash'] : $this->funds()['expected_cash'];
            $this->assertSame($confirmed ? '460.0000' : '280.0000', $balance);
            if ($other !== null) {
                $this->assertEquals($other, app(SalesOperations::class)->sale($this->actor, $other->sale->id));
                $this->assertSame($other->sale->id, app(SalesOperations::class)->table($this->actor, $paid->sale->tableId)->activeSaleId);
            }
            $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
            file_put_contents(base_path('.tools/corrections-race-'.$mode.'.json'), json_encode(['database' => 'fresapos_test', 'mode' => $mode, 'first_spid' => $spidA, 'second_spid' => $spidB, 'wait_type' => $wait->wait_type ?? null, 'pending_status' => $pending['status'] ?? null, 'first_status' => $a['status'], 'second_status' => $b['status'], 'folio_preserved' => true, 'first_close_preserved' => true, 'original_payments_preserved' => true, 'additional_payments' => $confirmed ? 1 : 0, 'correction_events' => $confirmed ? 1 : 0, 'expected_cash' => $balance, 'transient_sql_codes' => $b['codes'], 'passed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release','release');
            foreach (array_filter([$first, $second, $retry]) as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }rmdir($directory);
        }
    }
}
