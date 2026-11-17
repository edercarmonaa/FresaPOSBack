<?php

namespace Tests\Integration;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\IdempotencySqlServerTestCase;

class IdempotencyDeadlockTest extends IdempotencySqlServerTestCase
{
    public function test_real_sql_deadlock_retries_the_rolled_back_transaction_without_duplicate_effects(): void
    {
        $ids = [$this->createRecord('deadlock-a')->id, $this->createRecord('deadlock-b')->id];
        $directory = base_path('.tools/idempotency-deadlock-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(['table' => $this->probeSchema->parents, 'ids' => $ids, 'keys' => ['first' => (string) Str::uuid(), 'second' => (string) Str::uuid()]], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Idempotency/deadlock-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 35);
        $second = new Process([...$command, 'second'], base_path(), timeout: 35);
        try {
            $first->start();
            $this->waitFile($directory.'/first-holding', $first);
            $second->start();
            $this->waitFile($directory.'/second-holding', $second);
            $a = json_decode(file_get_contents($directory.'/first-spid.json'), true)['spid'];
            $b = json_decode(file_get_contents($directory.'/second-spid.json'), true)['spid'];
            $this->assertNotSame($a, $b);
            file_put_contents($directory.'/go', 'deadlock');
            $first->wait();
            $second->wait();
            foreach ([$first, $second] as $process) {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            }
            $ra = json_decode(file_get_contents($directory.'/first-result.json'), true);
            $rb = json_decode(file_get_contents($directory.'/second-result.json'), true);
            $this->assertSame(1, $ra['attempts']);
            $this->assertSame(2, $rb['attempts']);
            $this->assertSame([1205], $rb['transient_sql_codes']);
            $this->assertSame(200, $ra['status']);
            $this->assertSame(200, $rb['status']);
            $this->assertSame(2, $this->database->table('idempotency_records')->where('state', 'completed')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            foreach ($ids as $id) {
                $this->assertSame('1236.5600', $this->database->table($this->probeSchema->parents)->where('id', $id)->value('amount'));
            }
            file_put_contents(base_path('.tools/idempotency-1.5d2a-real-deadlock.json'), json_encode(['database' => 'fresapos_test', 'first_spid' => $a, 'second_spid' => $b, 'first' => $ra, 'second' => $rb, 'completed_records' => 2, 'each_row_incremented' => 2], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/go', 'release');
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }rmdir($directory);
        }
    }

    private function waitFile(string $file, Process $process): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getErrorOutput().$process->getOutput());
    }
}
