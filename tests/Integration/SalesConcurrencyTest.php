<?php

namespace Tests\Integration;

use Illuminate\Support\Str;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SalesSqlServerTestCase;

class SalesConcurrencyTest extends SalesSqlServerTestCase
{
    public static function concurrencyModes(): array
    {
        return [['application'], ['unique_index']];
    }

    #[DataProvider('concurrencyModes')]
    public function test_two_sql_sessions_cannot_commit_open_sales_for_the_same_table(string $mode): void
    {
        $actor = app(BootstrapAdministrator::class)->create('Concurrency fixture', 'race.admin', 'RaceInitial!123');
        app(IdentityAccess::class)->changePassword($actor, 'RaceInitial!123', 'RaceChanged!123');
        $table = app(SalesOperations::class)->createTable($actor, 'Concurrente');
        $directory = base_path('.tools/sales-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(['mode' => $mode, 'actor' => $actor, 'table' => $table->id, 'cash' => strtolower((string) Str::ulid())], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Sales/concurrent-open.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 30);
        $second = new Process([...$command, 'second'], base_path(), timeout: 30);
        try {
            $first->start();
            $this->waitForFile($directory.'/holding', $first);
            $firstSpid = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $second->start();
            $this->waitForFile($directory.'/second-attempt.json', $second);
            $secondSpid = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $wait = $this->database->selectOne('SELECT blocking_session_id, wait_type FROM sys.dm_exec_requests WHERE session_id = ?', [$secondSpid]);
                if ($wait && (int) $wait->blocking_session_id === (int) $firstSpid && str_starts_with((string) $wait->wait_type, 'LCK_M_')) {
                    $blocked = true;
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked, 'La segunda sesión debe esperar un lock real del primer proceso.');
            $this->assertNotSame($firstSpid, $secondSpid);
            $this->assertFileDoesNotExist($directory.'/second-result.json');
            file_put_contents($directory.'/release', 'commit');
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            $this->assertSame('created', $a['status']);
            $this->assertSame('conflict', $b['status']);
            if ($mode === 'unique_index') {
                $this->assertContains($b['sql_code'], [2601, 2627]);
            }
            $this->assertSame(1, $this->database->table('sales_sales')->where('table_id', $table->id)->where('status', 'open')->count());
            $this->assertTrue(app(SalesOperations::class)->table($actor, $table->id)->isOccupied);
            file_put_contents(base_path('.tools/sales-1.5a-race-'.$mode.'.json'), json_encode([
                'database' => $this->database->getConfig('database'), 'mode' => $mode,
                'first_spid' => $firstSpid, 'second_spid' => $secondSpid, 'observed_lock_wait' => $wait->wait_type,
                'blocking_session_id' => $wait->blocking_session_id, 'first_result' => $a['status'],
                'second_result' => $b['status'], 'second_sql_code' => $b['sql_code'] ?? null, 'active_sales' => 1,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach ([$first, $second] as $process) {
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

    private function waitForFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }
}
