<?php

namespace Tests\Integration;

use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\CashOperations;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CashSqlServerTestCase;

class CashConcurrencyTest extends CashSqlServerTestCase
{
    public static function modes(): array
    {
        return [['application'], ['unique_index'], ['replay'], ['current'], ['transition'], ['table']];
    }

    #[DataProvider('modes')]
    public function test_two_independent_sql_sessions_preserve_cash_and_sales_integrity(string $mode): void
    {
        $actor = app(BootstrapAdministrator::class)->create('Cash race', 'cash.race', 'CashInitial!123');
        app(IdentityAccess::class)->changePassword($actor, 'CashInitial!123', 'CashChanged!123');
        $users = app(UserAdministration::class);
        $other = $users->create($actor, ['name' => 'Other cash employee', 'username' => 'cash.other', 'password' => 'OtherInitial!123'])->id;
        $users->assignRole($actor, $other, 'cashier');
        app(IdentityAccess::class)->changePassword($other, 'OtherInitial!123', 'OtherChanged!123');
        $register = app(ProvisionCashRegister::class)->run()->id;
        $table = null;
        if (in_array($mode, ['transition', 'table'], true)) {
            app(CashOperations::class)->open($actor, $register, '0', 'cash-pre-race-123456');
        }
        if ($mode === 'table') {
            $table = app(SalesOperations::class)->createTable($actor, 'Mesa concurrente Cash')->id;
        }
        $directory = base_path('.tools/cash-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'actor', 'other', 'register', 'table'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Cash/concurrent.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 35);
        $second = new Process([...$command, 'second'], base_path(), timeout: 35);
        try {
            $first->start();
            $this->waitForFile($directory.'/holding', $first);
            $firstSpid = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $second->start();
            $this->waitForFile($directory.'/second-attempt.json', $second);
            $secondSpid = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
            $this->assertNotSame($firstSpid, $secondSpid);
            $deadline = microtime(true) + 6;
            $wait = null;
            do {
                $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id = ?', [$secondSpid]);
                if ($wait && (int) $wait->blocking_session_id === $firstSpid && str_starts_with((string) $wait->wait_type, 'LCK_M_')) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertNotNull($wait);
            $this->assertSame($firstSpid, (int) $wait->blocking_session_id);
            $this->assertStringStartsWith('LCK_M_', $wait->wait_type);
            $this->assertFileDoesNotExist($directory.'/second-result.json');
            file_put_contents($directory.'/release', 'commit');
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput().$first->getOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput().$second->getOutput());
            $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
            $b = json_decode(file_get_contents($directory.'/second-result.json'), true);
            $this->assertSame($mode === 'transition' ? 'transitioned' : 'created', $a['status']);
            $this->assertSame(match ($mode) {
                'replay' => 'created', 'current' => 'queried', 'transition' => 'unavailable', default => 'conflict'
            }, $b['status']);
            if (in_array($mode, ['replay', 'current'], true)) {
                $this->assertSame($a['id'], $b['id']);
            }
            if ($mode === 'unique_index') {
                $this->assertContains($b['sql_code'], [2601, 2627]);
            }
            $this->assertSame($mode === 'transition' ? 0 : 1, $this->database->table('cash_sessions')->where('status', 'open')->count());
            $this->assertSame(1, $this->database->table('cash_sessions')->count());
            $this->assertSame($mode === 'table' ? 1 : 0, $this->database->table('sales_sales')->count());
            if ($mode !== 'unique_index') {
                $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.session.opened')->count());
            }
            file_put_contents(base_path('.tools/cash-1.5d1-race-'.$mode.'.json'), json_encode([
                'database' => $this->database->getConfig('database'), 'mode' => $mode, 'first_spid' => $firstSpid, 'second_spid' => $secondSpid,
                'wait_type' => $wait->wait_type, 'first' => $a, 'second' => $b, 'sessions' => 1,
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

    private function waitForFile(string $file, Process $process): void
    {
        $deadline = microtime(true) + 12;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getErrorOutput().$process->getOutput());
    }
}
