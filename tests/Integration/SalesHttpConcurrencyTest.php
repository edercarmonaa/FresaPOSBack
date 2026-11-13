<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Sales\TestCashSession;
use Tests\Support\SalesSqlServerTestCase;

class SalesHttpConcurrencyTest extends SalesSqlServerTestCase
{
    public static function modes(): array
    {
        return [['quantity'], ['note'], ['pause'], ['table'], ['blocked_open']];
    }

    #[DataProvider('modes')]
    public function test_two_http_kernels_detect_conflicts_under_actual_sql_lock(string $mode): void
    {
        $actor = app(BootstrapAdministrator::class)->create('HTTP concurrency', 'http.race', 'RaceInitial!123');
        app(IdentityAccess::class)->changePassword($actor, 'RaceInitial!123', 'RaceChanged!123');
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'http.race', 'password' => 'RaceChanged!123'])->assertOk()->json('data.token');
        $cash = strtolower((string) Str::ulid());
        $this->app->instance(ActiveCashSession::class, new TestCashSession($cash));
        $sales = app(SalesOperations::class);
        $table = $sales->createTable($actor, 'Concurrent HTTP');
        $snapshot = $mode === 'blocked_open' ? $table : $sales->open($actor, new OpenSale(SaleType::Counter));
        $id = $mode === 'blocked_open' ? $table->id : $snapshot->sale->id;
        $method = 'PATCH';
        if ($mode === 'quantity') {
            $catalog = app(CatalogAdministration::class);
            $category = $catalog->createCategory($actor, ['name' => 'Fixture'])->id;
            $product = $catalog->createProduct($actor, ['name' => 'Producto', 'category_id' => $category, 'price' => '10.0000'])->id;
            $snapshot = $sales->addProduct($actor, $id, new AddProduct($product, 1));
            $path = '/'.$id.'/items/'.$snapshot->items[0]->id.'/quantity';
            $payloads = ['first' => ['quantity' => 2], 'second' => ['quantity' => 3]];
        } elseif ($mode === 'note') {
            $path = '/'.$id.'/note';
            $payloads = ['first' => ['note' => 'Primera'], 'second' => ['note' => 'Segunda']];
        } elseif ($mode === 'pause') {
            $method = 'POST';
            $path = '/'.$id.'/pause';
            $payloads = ['first' => [], 'second' => []];
        } elseif ($mode === 'blocked_open') {
            $method = 'POST';
            $path = '';
            $payloads = ['first' => ['type' => 'table', 'table_id' => $table->id], 'second' => ['type' => 'table', 'table_id' => $table->id]];
        } else {
            $snapshot = $table;
            $path = '/tables/'.$table->id.'/status';
            $payloads = ['first' => ['is_active' => false], 'second' => ['is_active' => true]];
        }
        $directory = base_path('.tools/sales-http-race-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(['mode' => $mode, 'app_key' => config('app.key'), 'cash' => $cash, 'token' => $token, 'path' => $path, 'method' => $method, 'payloads' => $payloads, 'revision' => SalesRevision::of($snapshot)], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Sales/concurrent-http.php'), $directory];
        $first = new Process([...$command, 'first'], base_path(), timeout: 35);
        $second = new Process([...$command, 'second'], base_path(), timeout: 35);
        try {
            $first->start();
            $this->waitFile($directory.'/holding', $first);
            $a = json_decode(file_get_contents($directory.'/first-attempt.json'), true)['spid'];
            $second->start();
            $this->waitFile($directory.'/second-attempt.json', $second);
            $b = json_decode(file_get_contents($directory.'/second-attempt.json'), true)['spid'];
            $blocked = false;
            $deadline = microtime(true) + 5;
            do {
                $wait = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$b]);
                if ($wait && (int) $wait->blocking_session_id === (int) $a && str_starts_with((string) $wait->wait_type, 'LCK_M_')) {
                    $blocked = true;
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($blocked, 'Debe observarse bloqueo SQL real entre ambos kernels.');
            $this->assertNotSame($a, $b);
            file_put_contents($directory.'/release', 'commit');
            $first->wait();
            $second->wait();
            foreach ([$first, $second] as $process) {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $ra = json_decode(file_get_contents($directory.'/first-result.json'), true);
            $rb = json_decode(file_get_contents($directory.'/second-result.json'), true);
            $this->assertSame($mode === 'blocked_open' ? 503 : 200, $ra['status'], json_encode($ra));
            $this->assertSame($mode === 'blocked_open' ? 503 : 409, $rb['status'], json_encode($rb));
            $this->assertSame($mode === 'blocked_open' ? 'SERVICE_UNAVAILABLE' : 'CONFLICT', $rb['body']['code']);
            if ($mode === 'blocked_open') {
                $this->assertSame(0, $this->database->table('sales_sales')->count());
                $this->assertSame(0, $this->database->table('sales_items')->count());
            }
            $this->assertSame('http-race-second', $rb['body']['meta']['request_id']);
            if ($mode === 'quantity') {
                $this->assertSame(2, $sales->sale($actor, $id)->items[0]->quantity);
            }
            if ($mode === 'note') {
                $this->assertSame('Primera', $sales->sale($actor, $id)->sale->note);
            }
            if ($mode === 'pause') {
                $this->assertSame('paused', $sales->sale($actor, $id)->sale->state->value);
            }
            if ($mode === 'table') {
                $this->assertFalse($sales->table($actor, $table->id)->isActive);
            }
            file_put_contents(base_path('.tools/sales-1.5b-race-'.$mode.'.json'), json_encode(['mode' => $mode, 'first_spid' => $a, 'second_spid' => $b, 'lock' => $wait->wait_type, 'first_status' => $ra['status'], 'second_status' => $rb['status']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/release', 'release');
            foreach ([$first, $second] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            } rmdir($directory);
        }
    }

    private function waitFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($path, $process->getOutput().$process->getErrorOutput());
    }
}
