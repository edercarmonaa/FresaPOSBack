<?php

namespace Tests\Integration;

use Illuminate\Support\Str;
use Modules\Reports\Contracts\OperationalReports;
use Symfony\Component\Process\Process;
use Tests\Support\ReceiptsSqlServerTestCase;
use Tests\Support\WorkerDiagnostics;

class ReportsConcurrencyTest extends ReceiptsSqlServerTestCase
{
    private function waitFile(string $file, Process $process): void
    {
        $until = microtime(true) + 15;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $until) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getErrorOutput().$process->getOutput());
    }

    public function test_concurrent_confirmation_cannot_mix_summary_queries_from_different_financial_versions(): void
    {
        $sale = $this->settledSale();
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        $directory = base_path('.tools/reports-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(['actor' => $this->actor, 'saleId' => $sale->sale->id, 'revision' => $row['revision'], 'key' => (string) Str::uuid(), 'payments' => [$this->payment()]], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/reports-worker.php'), $directory];
        $read = new Process([...$command, 'read'], base_path(), timeout: 40);
        $write = new Process([...$command, 'confirm'], base_path(), timeout: 40);
        $completed = false;
        try {
            $read->start();
            $this->waitFile($directory.'/holding', $read);
            $a = json_decode(file_get_contents($directory.'/read-attempt.json'), true)['spid'];
            $write->start();
            $this->waitFile($directory.'/confirm-attempt.json', $write);
            $b = json_decode(file_get_contents($directory.'/confirm-attempt.json'), true)['spid'];
            $this->assertNotSame($a, $b);
            $until = microtime(true) + 4;
            do {
                $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$b]);
                if ($wait && (int) $wait->blocking_session_id === $a) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $until);
            $this->assertNotNull($wait);
            $this->assertSame($a, (int) $wait->blocking_session_id);
            $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
            file_put_contents($directory.'/release', 'continue');
            $read->wait();
            $write->wait();
            $this->assertSame(0, $read->getExitCode(), $read->getErrorOutput());
            $this->assertSame(0, $write->getExitCode(), $write->getErrorOutput());
            $this->assertFileExists($directory.'/read-result.json', $read->getOutput().$read->getErrorOutput());
            $this->assertFileExists($directory.'/confirm-result.json', $write->getOutput().$write->getErrorOutput());
            $data = json_decode(file_get_contents($directory.'/read-result.json'), true);
            $this->assertContains($data['status'], [200, 503]);
            if ($data['status'] === 200) {
                $this->assertSame('180.0000', $data['data']['totals']['confirmed_income']);
                $this->assertSame('180.0000', $data['data']['totals']['cash_applied']);
                $this->assertSame('180.0000', $data['data']['series'][0]['income']);
                $this->assertSame(1, $data['data']['totals']['units_sold']);
            } else {
                $this->assertSame('SERVICE_UNAVAILABLE', $data['code']);
                $this->assertArrayNotHasKey('data', $data);
            }
            $current = app(OperationalReports::class)->read($this->actor, 'summary');
            $this->assertSame('360.0000', $current->totals['confirmed_income']);
            $this->assertSame(2, $current->totals['units_sold']);
            file_put_contents(base_path('.tools/reports-concurrency.json'), json_encode(['database' => 'fresapos_test', 'reader_spid' => $a, 'writer_spid' => $b, 'wait_type' => $wait->wait_type, 'reader_status' => $data['status'], 'reader_income' => $data['data']['totals']['confirmed_income'] ?? null, 'after_commit_income' => $current->totals['confirmed_income'], 'passed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $completed = true;
        } finally {
            file_put_contents($directory.'/diagnostic.json', json_encode(['read_out' => $read->getOutput(), 'read_err' => $read->getErrorOutput(), 'write_out' => $write->getOutput(), 'write_err' => $write->getErrorOutput()], JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/release', 'cleanup');
            foreach ([$read, $write] as $process) {
                if ($process->isRunning()) {
                    $process->stop(2);
                }
            }
            WorkerDiagnostics::removeDirectory($directory, ! $completed);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }
}
