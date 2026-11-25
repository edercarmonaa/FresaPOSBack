<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/** Evidencia cerrada de proceso/barrera; no guarda inputs, respuestas ni argumentos. */
final class WorkerDiagnostics
{
    private static ?string $file = null;

    public static function awaitReady(array $workers, string $directory, float $startupSeconds = 30): void
    {
        $started = hrtime(true);
        $deadline = $started + (int) ($startupSeconds * 1_000_000_000);
        $pending = $workers;
        while ($pending !== []) {
            foreach ($workers as $process) {
                $process->checkTimeout();
            }
            foreach ($pending as $role => $process) {
                $readyFile = $directory.'/'.$role.'-ready.json';
                if (is_file($readyFile)) {
                    if (! $process->isRunning()) {
                        self::record('worker.exited_after_ready', $directory, $role, $process);
                        throw new \RuntimeException('Worker terminated after readiness and before start.');
                    }
                    $ready = json_decode(file_get_contents($readyFile), true, flags: JSON_THROW_ON_ERROR);
                    if (($ready['run_id'] ?? null) !== basename($directory) || ($ready['worker'] ?? null) !== $role
                        || ! is_int($ready['pid'] ?? null) || ! is_int($ready['spid'] ?? null)) {
                        throw new \RuntimeException('Invalid worker readiness identity.');
                    }
                    self::record('worker.ready.observed', $directory, $role, $process, ['php_pid' => $ready['pid'], 'spid' => $ready['spid'], 'elapsed_ms' => (hrtime(true) - $started) / 1000000]);
                    unset($pending[$role]);
                } elseif (! $process->isRunning()) {
                    self::record('worker.exited_before_ready', $directory, $role, $process);
                    throw new \RuntimeException('Worker terminated before readiness; inspect private diagnostics.');
                }
            }
            if ($pending === []) {
                return;
            }
            if (hrtime(true) >= $deadline) {
                foreach ($pending as $role => $process) {
                    self::record('worker.startup_timeout', $directory, $role, $process, ['elapsed_ms' => (hrtime(true) - $started) / 1000000]);
                }
                throw new \RuntimeException('Worker preparation deadline exceeded; no financial start was signalled.');
            }
            usleep(20000);
        }
    }

    public static function removeDirectory(string $directory, bool $preserveFailure): void
    {
        $root = realpath(base_path('.tools'));
        $target = realpath($directory);
        if ($root === false || $target === false || strcasecmp(dirname($target), $root) !== 0
            || ! preg_match('/\A(?:block05-race-|reports-race-|corrections-race-receipt-)[a-f0-9]{12}\z/', basename($target))) {
            throw new \RuntimeException('Se rechazó una ruta de limpieza ajena al fixture.');
        }
        $files = glob($target.DIRECTORY_SEPARATOR.'*');
        foreach ($files as $file) {
            if (! is_file($file) || is_link($file) || strcasecmp(dirname(realpath($file)), $target) !== 0) {
                throw new \RuntimeException('El fixture contiene una ruta inesperada; no se elimina.');
            }
        }
        $archive = null;
        if ($preserveFailure) {
            $archive = $root.DIRECTORY_SEPARATOR.'stability-failure-'.basename($target).'-'.hrtime(true);
            mkdir($archive);
            foreach ($files as $file) {
                // Evidencia interna de fallo; nunca copiar tokens/credenciales del input.
                if (preg_match('/(?:result\.json|attempt\.json|exception\.txt|diagnostics\.txt|diagnostic\.json)\z/', basename($file))) {
                    if (! copy($file, $archive.DIRECTORY_SEPARATOR.basename($file))) {
                        throw new \RuntimeException('No se pudo preservar el diagnóstico interno.');
                    }
                }
            }
        }
        foreach ($files as $file) {
            if (! unlink($file)) {
                throw new \RuntimeException('No se pudo limpiar un archivo del fixture.');
            }
        }
        if (! rmdir($target)) {
            throw new \RuntimeException('No se pudo limpiar el directorio del fixture.');
        }
        file_put_contents($root.DIRECTORY_SEPARATOR.'stability-cleanup-'.basename($target).'.json', json_encode([
            'run_id' => basename($target), 'removed_files' => count($files), 'removed_directory' => true,
            'had_holding_signal' => in_array('holding', array_map('basename', $files), true),
            'had_release_signal' => in_array('release', array_map('basename', $files), true),
            'failure_archive' => $archive === null ? null : basename($archive),
        ], JSON_THROW_ON_ERROR));
    }

    public static function record(string $phase, string $directory, string $worker, Process $process, array $details = []): void
    {
        self::$file ??= base_path('.tools/stability-parent-'.getmypid().'-'.hrtime(true).'.jsonl');
        $row = ['phase' => $phase, 'run_id' => basename($directory), 'worker' => $worker,
            'parent_pid' => getmypid(), 'process_pid' => $process->getPid(), 'monotonic_ns' => hrtime(true),
            'running' => $process->isRunning(), 'exit_code' => $process->getExitCode()] + $details;
        file_put_contents(self::$file, json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
    }

    public static function start(Process $process, string $directory, string $worker): void
    {
        $started = hrtime(true);
        self::record('process.start.begin', $directory, $worker, $process);
        try {
            $process->start();
        } finally {
            self::record('process.start.returned', $directory, $worker, $process, ['elapsed_ms' => (hrtime(true) - $started) / 1000000]);
        }
    }

    public static function run(Process $process, string $directory, string $worker): void
    {
        $started = hrtime(true);
        self::record('process.run.begin', $directory, $worker, $process);
        try {
            $process->run();
        } finally {
            self::record('process.run.returned', $directory, $worker, $process, ['elapsed_ms' => (hrtime(true) - $started) / 1000000]);
        }
    }
}
