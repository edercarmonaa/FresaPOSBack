<?php

namespace Tests\Integration;

use Illuminate\Support\Str;
use Modules\Reports\Contracts\OperationalReports;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinancialIntegrity;
use Tests\Support\ReceiptsSqlServerTestCase;
use Tests\Support\WorkerDiagnostics;

class Block05ConcurrencyTest extends ReceiptsSqlServerTestCase
{
    public static function interleavings(): array
    {
        return [['export_checkout'], ['reprint_confirmation']];
    }

    private function waitFile(string $file, Process $process): void
    {
        $until = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getOutput().$process->getErrorOutput());
    }

    #[DataProvider('interleavings')]
    public function test_independent_sql_connections_do_not_publish_partial_exports_or_stale_reprints(string $mode): void
    {
        $paid = $this->settledSale();
        $receiptRevision = $this->receipt($paid->sale->id)->revision;
        if ($mode === 'export_checkout') {
            $target = $this->sale();
            $saleId = $target->sale->id;
            $revision = $this->revision($target);
        } else {
            $saleId = $paid->sale->id;
            $correction = $this->reopen($paid);
            $correction = $this->correction($correction, 'edit', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2]);
            $revision = '"'.$correction['revision'].'"';
        }
        $directory = base_path('.tools/block05-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(['mode' => $mode, 'actor' => $this->actor, 'saleId' => $saleId, 'revision' => $revision, 'payments' => [$this->payment()], 'key' => (string) Str::uuid(), 'receiptRevision' => $receiptRevision, 'reprintKey' => (string) Str::uuid()], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/block05-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 40);
        $second = new Process([...$command, 'second'], base_path(), timeout: 40);
        $completed = false;
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $a = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $second->start();
            $this->waitFile($directory.'/second-attempt.json', $second);
            $b = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
            $this->assertNotSame($a, $b);
            $deadline = microtime(true) + 4;
            do {
                $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$b]);
                if ($wait && (int) $wait->blocking_session_id === $a) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertNotNull($wait);
            $this->assertSame($a, (int) $wait->blocking_session_id);
            $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
            file_put_contents($directory.'/release', 'continue');
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $one = json_decode(file_get_contents($directory.'/first-result.json'), true);
            $two = json_decode(file_get_contents($directory.'/second-result.json'), true);
            if ($mode === 'export_checkout') {
                $this->assertContains($one['status'], [200, 503]);
                if ($one['status'] === 200) {
                    $this->assertTrue($one['binary_xlsx']);
                    $this->assertSame('180.0000', $one['metrics']['confirmed_income']);
                    $this->assertSame('180.0000', $one['metrics']['cash_applied']);
                    $this->assertSame(1, $one['metrics']['paid_sales']);
                    $this->assertSame(1, $one['metrics']['units_sold']);
                } else {
                    $this->assertSame('SERVICE_UNAVAILABLE', $one['code']);
                    $this->assertFileDoesNotExist($directory.'/concurrent-summary.xlsx');
                }
                $this->assertSame(200, $two['status']);
            } else {
                $this->assertSame(200, $one['status']);
                $this->assertSame('confirmed', $one['correction_status']);
                $this->assertSame(409, $two['status']);
                $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());
                $this->assertSame(1, (int) $this->database->table('sales_daily_sequences')->value('last_value'));
            }
            $this->assertSame('360.0000', app(OperationalReports::class)->read($this->actor, 'summary')->totals['confirmed_income']);
            $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);
            file_put_contents(base_path('.tools/block05-concurrency-'.$mode.'.json'), json_encode(['database' => 'fresapos_test', 'mode' => $mode, 'first_spid' => $a, 'second_spid' => $b, 'wait_type' => $wait->wait_type, 'first_result' => $one, 'second_result' => $two, 'passed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $completed = true;
        } finally {
            file_put_contents($directory.'/release', 'cleanup');
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
            WorkerDiagnostics::removeDirectory($directory, ! $completed);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }
}
