<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\WorkerDiagnostics;
use Tests\TestCase;

class WorkerDiagnosticsTest extends TestCase
{
    public function test_failure_evidence_survives_cleanup_without_copying_worker_input(): void
    {
        $directory = base_path('.tools/block05-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', '{"token":"synthetic-do-not-archive"}');
        file_put_contents($directory.'/first-result.json', '{"status":500}');
        file_put_contents($directory.'/first-exception.txt', 'Synthetic internal stack');

        WorkerDiagnostics::removeDirectory($directory, true);

        $this->assertDirectoryDoesNotExist($directory);
        $manifest = json_decode(file_get_contents(base_path('.tools/stability-cleanup-'.basename($directory).'.json')), true, flags: JSON_THROW_ON_ERROR);
        $archive = base_path('.tools/'.$manifest['failure_archive']);
        $this->assertFileExists($archive.'/first-result.json');
        $this->assertSame('{"status":500}', file_get_contents($archive.'/first-result.json'));
        $this->assertFileExists($archive.'/first-exception.txt');
        $this->assertFileDoesNotExist($archive.'/input.json');
    }

    public static function foreignDirectories(): array
    {
        return [['storage/framework/foreign-'], ['.tools/foreign-']];
    }

    #[DataProvider('foreignDirectories')]
    public function test_cleanup_refuses_foreign_directories_and_preserves_their_files(string $prefix): void
    {
        $directory = base_path($prefix.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/sentinel.txt', 'preserve');
        try {
            try {
                WorkerDiagnostics::removeDirectory($directory, false);
                $this->fail('A foreign directory must be rejected.');
            } catch (\RuntimeException) {
                $this->assertSame('preserve', file_get_contents($directory.'/sentinel.txt'));
                $this->assertDirectoryExists($directory);
            }
        } finally {
            unlink($directory.'/sentinel.txt');
            rmdir($directory);
        }
    }

    public function test_unexpected_nested_directory_is_rejected_before_any_file_is_removed(): void
    {
        $directory = base_path('.tools/reports-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        mkdir($directory.'/unexpected');
        file_put_contents($directory.'/input.json', '{}');
        try {
            try {
                WorkerDiagnostics::removeDirectory($directory, false);
                $this->fail('Unexpected directory must be rejected.');
            } catch (\RuntimeException) {
                $this->assertFileExists($directory.'/input.json');
                $this->assertDirectoryExists($directory.'/unexpected');
            }
        } finally {
            rmdir($directory.'/unexpected');
            WorkerDiagnostics::removeDirectory($directory, false);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_prepared_workers_cannot_execute_before_both_readiness_signals_are_observed(): void
    {
        $directory = base_path('.tools/reports-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        $workers = [];
        foreach (['first', 'second'] as $role) {
            $workers[$role] = new Process([PHP_BINARY, base_path('tests/Fixtures/Finance/prepared-worker.php'), $directory, $role], base_path(), timeout: 45);
        }
        try {
            foreach ($workers as $role => $worker) {
                WorkerDiagnostics::start($worker, $directory, $role);
            }
            WorkerDiagnostics::awaitReady($workers, $directory);
            foreach ($workers as $role => $worker) {
                $this->assertFileDoesNotExist($directory.'/'.$role.'-executed');
            }
            foreach ($workers as $role => $worker) {
                file_put_contents($directory.'/'.$role.'-start', 'execute');
            }
            foreach ($workers as $role => $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode());
                $this->assertFileExists($directory.'/'.$role.'-executed');
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            WorkerDiagnostics::removeDirectory($directory, false);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_worker_exit_before_readiness_is_reported_without_starting_financial_work(): void
    {
        $directory = base_path('.tools/reports-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        $worker = new Process([PHP_BINARY, '-r', 'exit(7);'], base_path(), timeout: 45);
        try {
            WorkerDiagnostics::start($worker, $directory, 'first');
            try {
                WorkerDiagnostics::awaitReady(['first' => $worker], $directory);
                $this->fail('A terminated worker cannot be ready.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('terminated before readiness', $exception->getMessage());
                $this->assertSame(7, $worker->getExitCode());
            }
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
            WorkerDiagnostics::removeDirectory($directory, false);
        }
        $this->assertDirectoryDoesNotExist($directory);
    }
}
