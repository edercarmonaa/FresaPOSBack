<?php

namespace Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Auth\Application\EmployeeAccess;
use Modules\Auth\Application\SessionRegistry;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Symfony\Component\Process\Process;
use Tests\Support\DiscountsSqlServerTestCase;

class SaleDiscountsPinConcurrencyTest extends DiscountsSqlServerTestCase
{
    public function test_actual_pin_switch_revokes_prevalidated_request_waiting_for_discount_transaction(): void
    {
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'PIN race', 'username' => 'discount.pin.racer', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'waiter');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $makeRequest = function (string $actor): Request {
            Auth::guard('web')->loginUsingId($actor);
            $session = app('session')->driver('array');
            $session->regenerate();
            $session->start();
            $request = Request::create('/pos');
            $request->setLaravelSession($session);
            app(SessionRegistry::class)->createWeb($request, app(IdentityAccess::class)->inspect($actor));

            return $request;
        };
        app(EmployeeAccess::class)->setPin($makeRequest($employee->id), 'EmployeeChanged!123', '0042');
        $request = $makeRequest($this->actor);
        app(EmployeeAccess::class)->authorizeTerminal($request, 'FinanceChanged!123');
        $webSession = $request->session()->getId();
        $this->database->table('auth_access_sessions')->update(['expires_at' => '2030-01-01']);
        $sale = $this->sale();
        $initialIntents = $this->database->table('idempotency_records')->count();
        $directory = base_path('.tools/discounts-pin-'.bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/input.json', json_encode(['actor' => $this->actor, 'employee' => $employee->id, 'webSession' => $webSession, 'saleId' => $sale->sale->id, 'key' => (string) Str::uuid(), 'revision' => $this->revision($sale)], JSON_THROW_ON_ERROR));
        $command = [PHP_BINARY, base_path('tests/Fixtures/Finance/discounts-pin-worker.php'), $directory];
        $pin = new Process([...$command, 'pin'], base_path(), timeout: 40);
        $discount = new Process([...$command, 'discount'], base_path(), timeout: 40);
        $wait = function (string $name, Process $process) use ($directory): void {
            $until = microtime(true) + 15;
            while (! is_file($directory.'/'.$name) && $process->isRunning() && microtime(true) < $until) {
                usleep(20000);
            }$this->assertFileExists($directory.'/'.$name, $process->getErrorOutput().$process->getOutput());
        };
        try {
            $discount->start();
            $wait('validated', $discount);
            $pin->start();
            $wait('holding', $pin);
            file_put_contents($directory.'/execute', 'ready');
            $a = json_decode(file_get_contents($directory.'/pin-spid.json'), true)['spid'];
            $b = json_decode(file_get_contents($directory.'/discount-spid.json'), true)['spid'];
            $this->assertNotSame($a, $b);
            $until = microtime(true) + 8;
            do {
                $blocked = $this->database->selectOne('SELECT blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id=?', [$b]);
                if ($blocked && (int) $blocked->blocking_session_id === $a) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $until);
            $this->assertNotNull($blocked);
            $this->assertSame($a, (int) $blocked->blocking_session_id);
            $this->assertStringStartsWith('LCK_M_', $blocked->wait_type);
            file_put_contents($directory.'/release', 'ready');
            $pin->wait();
            $discount->wait();
            $this->assertSame(0, $pin->getExitCode(), $pin->getErrorOutput());
            $this->assertSame(0, $discount->getExitCode(), $discount->getErrorOutput());
            $this->assertSame($employee->id, json_decode(file_get_contents($directory.'/pin-result.json'), true)['actor']);
            $this->assertSame('revoked_session_rejected', json_decode(file_get_contents($directory.'/discount-result.json'), true)['status']);
            $this->assertNull($this->database->table('sales_sales')->value('discount_type'));
            $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'like', 'sales.discount.%')->count());
            $this->assertSame($initialIntents, $this->database->table('idempotency_records')->count());
            file_put_contents(base_path('.tools/discounts-race-pin.json'), json_encode(['database' => 'fresapos_test', 'first_spid' => $a, 'second_spid' => $b, 'wait_type' => $blocked->wait_type, 'result' => 'revoked_session_rejected'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            file_put_contents($directory.'/execute', 'release');
            file_put_contents($directory.'/release', 'release');
            foreach ([$pin, $discount] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }rmdir($directory);
        }
    }
}
