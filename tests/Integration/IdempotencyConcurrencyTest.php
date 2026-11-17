<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\CashOperations;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\IdempotencySqlServerTestCase;

class IdempotencyConcurrencyTest extends IdempotencySqlServerTestCase
{
    public static function modes(): array
    {
        return [['same'], ['different'], ['add'], ['table'], ['rollback'], ['lost'], ['crash'], ['after_commit']];
    }

    #[DataProvider('modes')]
    public function test_independent_http_kernels_recover_without_duplicate_sales_items_or_audit(string $mode): void
    {
        $actor = app(BootstrapAdministrator::class)->create('Durable race', 'durable.race', 'RaceInitial!123');
        app(IdentityAccess::class)->changePassword($actor, 'RaceInitial!123', 'RaceChanged!123');
        $register = app(ProvisionCashRegister::class)->run()->id;
        app(CashOperations::class)->open($actor, $register, '0', 'cash-durable-race-123456');
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'durable.race', 'password' => 'RaceChanged!123'])->assertOk()->json('data.token');
        $key = (string) Str::uuid();
        $keys = ['first' => $key, 'second' => $mode === 'table' ? (string) Str::uuid() : $key];
        $method = 'POST';
        $path = '/sales';
        $revision = null;
        $payloads = ['first' => ['type' => 'counter'], 'second' => ['type' => 'counter']];
        if ($mode === 'different') {
            $payloads['second']['note'] = 'Otro contenido';
        }
        $sale = null;
        if ($mode === 'add') {
            $catalog = app(CatalogAdministration::class);
            $category = $catalog->createCategory($actor, ['name' => 'Race']);
            $product = $catalog->createProduct($actor, ['name' => 'Product', 'category_id' => $category->id, 'price' => '10.0000']);
            $sale = app(SalesOperations::class)->open($actor, new OpenSale(SaleType::Counter));
            $path = '/sales/'.$sale->sale->id.'/items';
            $revision = SalesRevision::of($sale);
            $payloads = ['first' => ['product_id' => $product->id, 'quantity' => 2], 'second' => ['product_id' => $product->id, 'quantity' => 2]];
        } elseif ($mode === 'table') {
            $table = app(SalesOperations::class)->createTable($actor, 'Concurrent durable table');
            $payloads = ['first' => ['type' => 'table', 'table_id' => $table->id], 'second' => ['type' => 'table', 'table_id' => $table->id]];
        }
        $directory = base_path('.tools/idempotency-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(compact('mode', 'actor', 'token', 'keys', 'method', 'path', 'revision', 'payloads'), JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Idempotency/http-worker.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 35);
        $second = new Process([...$command, 'second'], base_path(), timeout: 35);
        $extra = [];
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $spidA = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $applicationLocks = $this->database->select("SELECT request_mode,request_status FROM sys.dm_tran_locks WHERE resource_type='APPLICATION' AND request_session_id=?", [$spidA]);
            $this->assertNotEmpty($applicationLocks);
            if (! in_array($mode, ['lost', 'crash', 'after_commit'], true)) {
                $second->start();
                $this->waitFile($directory.'/second-attempt.json', $second);
                $spidB = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
                $this->assertNotSame($spidA, $spidB);
                if ($mode === 'table') {
                    $deadline = microtime(true) + 6;
                    $wait = null;
                    do {
                        $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$spidB]);
                        if ($wait && (int) $wait->blocking_session_id === $spidA) {
                            break;
                        }usleep(20000);
                    } while (microtime(true) < $deadline);
                    $this->assertNotNull($wait);
                    $this->assertSame($spidA, (int) $wait->blocking_session_id);
                } else {
                    $second->wait();
                    $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                    $pending = json_decode(file_get_contents($directory.'/second-result.json'), true);
                    $this->assertSame(409, $pending['status'], json_encode($pending));
                    $this->assertSame('OPERATION_IN_PROGRESS', $pending['body']['code']);
                    $this->assertTrue($first->isRunning());
                    $this->assertSame('pending', app(IdempotentOperations::class)->status(OperationIntent::context($actor), $key)->state);
                    if ($mode === 'same') {
                        $status = new Process([...$command, 'status'], base_path(), timeout: 15);
                        $extra[] = $status;
                        $status->run();
                        $this->assertSame(0, $status->getExitCode(), $status->getErrorOutput());
                        $statusBody = json_decode(file_get_contents($directory.'/status-result.json'), true);
                        $this->assertSame(200, $statusBody['status']);
                        $this->assertSame('pending', $statusBody['body']['data']['operation']['state']);
                    }
                }
            }
            if ($mode === 'crash') {
                $first->stop(.2);
            } else {
                file_put_contents($directory.'/release', 'commit');
                $first->wait();
                $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            }
            if ($mode === 'table') {
                $second->wait();
                $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
                $out = json_decode(file_get_contents($directory.'/second-result.json'), true);
                $this->assertSame(409, $out['status']);
                $this->assertSame('CONFLICT', $out['body']['code']);
            } else {
                $retry = new Process([...$command, 'retry'], base_path(), timeout: 20);
                $extra[] = $retry;
                $retry->run();
                $this->assertSame(0, $retry->getExitCode(), $retry->getErrorOutput());
                $out = json_decode(file_get_contents($directory.'/retry-result.json'), true);
                $this->assertSame($mode === 'different' ? 409 : ($mode === 'add' ? 200 : 201), $out['status'], json_encode($out));
                if ($mode === 'different') {
                    $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $out['body']['code']);
                }
            }
            if ($mode !== 'crash') {
                $a = json_decode(file_get_contents($directory.'/first-result.json'), true);
                $this->assertSame(in_array($mode, ['rollback', 'after_commit'], true) ? 500 : ($mode === 'add' ? 200 : 201), $a['status'], json_encode($a));
                if ($mode === 'lost') {
                    $this->assertFalse($a['delivered']);
                }
            }
            $this->assertSame(1, $this->database->table('sales_sales')->count());
            $this->assertSame($mode === 'add' ? 1 : 0, $this->database->table('sales_items')->count());
            if ($mode === 'add') {
                $this->assertSame(2, (int) $this->database->table('sales_items')->value('quantity'));
                $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.item_added')->count());
            }
            $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->count());
            $this->assertSame(1, $this->database->table('idempotency_records')->where('state', 'completed')->count());
            $this->assertSame($mode === 'table' ? 1 : 0, $this->database->table('idempotency_records')->where('state', 'failed')->count());
            $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
            file_put_contents(base_path('.tools/idempotency-1.5d2a-race-'.$mode.'.json'), json_encode(['mode' => $mode, 'database' => 'fresapos_test', 'first_spid' => $spidA, 'first_killed' => $mode === 'crash', 'final_status' => $out['status'], 'final_code' => $out['body']['code'], 'sales' => 1, 'completed_records' => 1, 'pending_records' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach ([$first, $second, ...$extra] as $process) {
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
        $deadline = microtime(true) + 14;
        while (! is_file($file) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($file, $process->getOutput().$process->getErrorOutput());
    }
}
