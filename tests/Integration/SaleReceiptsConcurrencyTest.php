<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinancialIntegrity;
use Tests\Support\ReceiptsSqlServerTestCase;

class SaleReceiptsConcurrencyTest extends ReceiptsSqlServerTestCase
{
    public static function races(): array
    {
        return [['read_before_reopen'], ['reopen_before_read'], ['confirm_before_read']];
    }

    private function waitFile(string $file, Process $process): void
    {
        $until = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getOutput().$process->getErrorOutput());
    }

    #[DataProvider('races')]
    public function test_real_http_sql_interleavings_never_mix_confirmed_and_draft_financial_versions(string $mode): void
    {
        $sale = $this->settledSale();
        $saleId = $sale->sale->id;
        $sessionId = $this->sessionId;
        $initialPayments = $this->database->table('sales_payments')->get()->all();
        $sequence = $this->database->table('sales_daily_sequences')->get()->all();
        $base = '/api/v1/sales/'.$saleId;
        $keys = ['first' => (string) Str::uuid(), 'second' => (string) Str::uuid()];
        $operations = ['first' => $base.'/receipt', 'second' => $base.'/reopen'];
        $methods = ['first' => 'GET', 'second' => 'POST'];
        $payloads = ['first' => [], 'second' => []];
        $revisions = ['first' => null, 'second' => $this->revision($sale)];
        if ($mode !== 'read_before_reopen') {
            $operations = ['first' => $base.'/reopen', 'second' => $base.'/receipt'];
            $methods = ['first' => 'POST', 'second' => 'GET'];
            $revisions = ['first' => $this->revision($sale), 'second' => null];
        }
        if ($mode === 'confirm_before_read') {
            $row = $this->reopen($sale);
            $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
            $operations['first'] = $base.'/correction/confirm';
            $payloads['first'] = ['payments' => [$this->payment()]];
            $revisions['first'] = '"'.$row['revision'].'"';
        }
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $this->database->table('personal_access_tokens')->update(['expires_at' => '2030-01-01']);
        // Reutiliza el trabajador HTTP existente de 1.6c; no introduce otro motor de carreras.
        $directory = base_path('.tools/corrections-race-receipt-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'keys', 'operations', 'methods', 'payloads', 'revisions', 'token', 'saleId', 'sessionId'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/corrections-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 45);
        $second = new Process([...$command, 'second'], base_path(), timeout: 45);
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $spidA = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $second->start();
            $this->waitFile($directory.'/second-attempt.json', $second);
            $spidB = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
            $this->assertNotSame($spidA, $spidB);
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
            file_put_contents($directory.'/release', 'continue');
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            $this->assertSame(200, $a['status'], json_encode($a));
            $this->assertSame($mode === 'reopen_before_read' ? 409 : 200, $b['status'], json_encode($b));
            if ($mode === 'read_before_reopen') {
                $this->assertSame('180.0000', $a['body']['data']['total']);
                $this->assertSame(1, $a['body']['data']['items'][0]['quantity']);
                $this->assertCount(1, $a['body']['data']['payments']);
            } elseif ($mode === 'confirm_before_read') {
                $this->assertSame('360.0000', $b['body']['data']['total']);
                $this->assertSame(2, $b['body']['data']['items'][0]['quantity']);
                $this->assertCount(2, $b['body']['data']['payments']);
                $this->assertSame($sale->sale->folio, $b['body']['data']['folio']);
                $this->assertSame($sale->sale->closedAt, $b['body']['data']['closed_at']);
            }
            $this->assertEquals($initialPayments, $this->database->table('sales_payments')->where('id', $initialPayments[0]->id)->get()->all());
            $this->assertEquals($sequence, $this->database->table('sales_daily_sequences')->get()->all());
            $this->assertSame(0, $this->database->table('cash_movements')->count());
            $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
            $invariants = FinancialIntegrity::inspect($this->database);
            $this->assertTrue($invariants['passed'], json_encode($invariants));
            file_put_contents(base_path('.tools/receipts-race-'.$mode.'.json'), json_encode(['database' => 'fresapos_test',
                'mode' => $mode, 'first_spid' => $spidA, 'second_spid' => $spidB, 'wait_type' => $wait->wait_type,
                'first_status' => $a['status'], 'second_status' => $b['status'], 'folio_preserved' => true,
                'original_payments_preserved' => true, 'financial_invariants' => $invariants, 'passed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            if (! is_file($directory.'/release')) {
                file_put_contents($directory.'/release', 'cleanup');
            }
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
        }
    }
}
