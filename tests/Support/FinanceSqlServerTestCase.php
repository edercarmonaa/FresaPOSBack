<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;
use Modules\Cash\Application\Services\ManagePaymentMethods;
use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashOperations;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;

abstract class FinanceSqlServerTestCase extends CashSqlServerTestCase
{
    private ?Migration $paymentSchema = null;

    protected string $actor;

    protected string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentSchema = require base_path('Modules/Sales/database/migrations/2026_11_17_000011_create_prepared_sale_payments.php');
        $this->database->transaction(fn () => $this->paymentSchema->up());
        $this->actor = app(BootstrapAdministrator::class)->create('Finance fixture', 'finance.fixture', 'FinanceInitial!123');
        app(IdentityAccess::class)->changePassword($this->actor, 'FinanceInitial!123', 'FinanceChanged!123');
        app(ManagePaymentMethods::class)->provision();
        $register = app(ProvisionCashRegister::class)->run();
        $this->sessionId = app(CashOperations::class)->open($this->actor, $register->id, '100.0000', 'finance-open-fixture-1234')->id;
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }TestDatabaseGuard::assertConnected($this->database);
            $this->paymentSchema?->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function closeFixture(): void
    {
        $this->database->table('cash_sessions')->where('id', $this->sessionId)->update(['status' => 'closed', 'closed_at' => now()->utc(), 'closed_by' => $this->actor]);
    }

    protected function funds(): ?array
    {
        return app(CashMovements::class)->balance($this->actor);
    }
}
