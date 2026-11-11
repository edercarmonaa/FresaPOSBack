<?php

namespace Tests\Integration;

use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use Modules\Core\Contracts\TransactionManager;
use Modules\Core\Infrastructure\Persistence\LaravelTransactionManager;
use RuntimeException;
use Tests\Fixtures\Persistence\ProbeCommitted;
use Tests\Support\SqlServerTestCase;

class TransactionTest extends SqlServerTestCase
{
    public function test_contract_resolves_and_commits_the_application_operation(): void
    {
        $transactions = $this->app->make(TransactionManager::class);
        $this->assertInstanceOf(LaravelTransactionManager::class, $transactions);
        $id = $transactions->run(fn () => $this->createRecord()->getKey());
        $this->assertSame(1, $this->database->table($this->probeSchema->parents)->where('id', $id)->count());
        $this->assertSame(0, $this->database->transactionLevel());
    }

    public function test_exception_rolls_back_and_discards_after_commit_callbacks(): void
    {
        $transactions = $this->app->make(TransactionManager::class);
        $called = false;
        try {
            $transactions->run(function () use ($transactions, &$called): void {
                $this->createRecord();
                $transactions->afterCommit(function () use (&$called): void {
                    $called = true;
                });
                throw new RuntimeException('rollback probe');
            });
            $this->fail('La excepción debía propagarse.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback probe', $exception->getMessage());
        }
        $this->assertFalse($called);
        $this->assertSame(0, $this->database->table($this->probeSchema->parents)->count());
        $this->assertSame(0, $this->database->transactionLevel());
    }

    public function test_after_commit_callback_waits_for_outermost_commit(): void
    {
        $transactions = $this->app->make(TransactionManager::class);
        $called = false;
        $transactions->run(function () use ($transactions, &$called): void {
            $transactions->run(function () use ($transactions, &$called): void {
                $this->createRecord();
                $transactions->afterCommit(function () use (&$called): void {
                    $this->assertSame(0, $this->database->transactionLevel());
                    $called = true;
                });
            });
            $this->assertFalse($called);
        });
        $this->assertTrue($called);
    }

    public function test_native_after_commit_event_is_delivered_only_after_commit(): void
    {
        $transactions = $this->app->make(TransactionManager::class);
        $events = $this->app->make(Dispatcher::class);
        $received = [];
        $events->listen(ProbeCommitted::class, function (ProbeCommitted $event) use (&$received): void {
            $this->assertSame(0, $this->database->transactionLevel());
            $received[] = $event->id;
        });
        $id = $transactions->run(function () use ($events, &$received): string {
            $id = $this->createRecord()->getKey();
            $events->dispatch(new ProbeCommitted($id));
            $this->assertSame([], $received);

            return $id;
        });
        $this->assertSame([$id], $received);
    }

    public function test_native_event_is_discarded_when_operation_rolls_back(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $received = false;
        $events->listen(ProbeCommitted::class, function () use (&$received): void {
            $received = true;
        });
        try {
            $this->app->make(TransactionManager::class)->run(function () use ($events): void {
                $events->dispatch(new ProbeCommitted($this->createRecord()->getKey()));
                throw new RuntimeException('event rollback probe');
            });
            $this->fail('Debía propagarse el fallo.');
        } catch (RuntimeException $exception) {
            $this->assertSame('event rollback probe', $exception->getMessage());
        }
        $this->assertFalse($received);
        $this->assertSame(0, $this->database->table($this->probeSchema->parents)->count());
    }

    public function test_secondary_listener_failure_does_not_undo_committed_data(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(ProbeCommitted::class, fn () => throw new RuntimeException('secondary probe failure'));
        try {
            $this->app->make(TransactionManager::class)->run(function () use ($events): void {
                $events->dispatch(new ProbeCommitted($this->createRecord()->getKey()));
            });
            $this->fail('El fallo secundario debía propagarse.');
        } catch (RuntimeException $exception) {
            $this->assertSame('secondary probe failure', $exception->getMessage());
        }
        $this->assertSame(1, $this->database->table($this->probeSchema->parents)->count());
        $this->assertSame(0, $this->database->transactionLevel());
    }

    public function test_after_commit_callback_requires_an_active_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->app->make(TransactionManager::class)->afterCommit(fn () => $this->fail('No debe ejecutarse.'));
    }
}
