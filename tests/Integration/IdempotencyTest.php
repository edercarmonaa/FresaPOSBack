<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Infrastructure\Persistence\SqlOperationStore;
use Tests\Support\IdempotencySqlServerTestCase;

class IdempotencyTest extends IdempotencySqlServerTestCase
{
    private function intent(?string $key = null, array $data = ['value' => 1]): OperationIntent
    {
        return new OperationIntent(OperationIntent::context('fixture-owner'), $key ?? (string) Str::uuid(), 'probe.write', 'POST', null, $data);
    }

    private function reject(callable $operation, ApiCode $code): void
    {
        try {
            $operation();
            $this->fail('Se esperaba rechazo.');
        } catch (OperationRejected $exception) {
            $this->assertSame($code, $exception->apiCode);
        }
    }

    public function test_business_and_result_commit_atomically_and_replay_without_executing_again(): void
    {
        $intent = $this->intent();
        $ops = app(IdempotentOperations::class);
        $result = $ops->run($intent, fn (bool $locking) => null, function (): OperationResult {
            $this->assertSame('pending', $this->database->table('idempotency_records')->value('state'));
            $row = $this->createRecord('atomic');

            return new OperationResult(201, ['id' => $row->id, 'amount' => '1234.5600'], $row->id);
        });
        $this->assertSame('completed', $ops->status($intent->ownerContext, $intent->key)->state);
        $this->assertSame(201, $ops->status($intent->ownerContext, $intent->key)->httpStatus);
        $replay = app(IdempotentOperations::class)->run($intent, fn (bool $locking) => null, fn () => throw new \LogicException('No repetir'));
        $this->assertEquals($result, $replay);
        $this->assertSame(1, $this->database->table($this->probeSchema->parents)->count());
        $this->assertSame(1, $this->database->table('idempotency_records')->count());
    }

    public function test_rollback_definitive_failure_and_unknown_internal_failure_do_not_leave_partial_effects(): void
    {
        $ops = app(IdempotentOperations::class);
        $intent = $this->intent();
        $this->reject(fn () => $ops->run($intent, fn (bool $locking) => null, function (): never {
            $this->createRecord('rollback');
            throw new OperationRejected(ApiCode::CONFLICT);
        }), ApiCode::CONFLICT);
        $this->assertSame(0, $this->database->table($this->probeSchema->parents)->count());
        $this->assertSame('failed', $ops->status($intent->ownerContext, $intent->key)->state);
        $this->reject(fn () => $ops->run($intent, fn (bool $locking) => null, fn () => throw new \LogicException('No repetir')), ApiCode::CONFLICT);
        $other = $this->intent();
        try {
            $ops->run($other, fn (bool $locking) => null, function (): never {
                $this->createRecord('internal');
                throw new \RuntimeException('Internal fixture failure');
            });
            $this->fail('Debe revertir.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Internal fixture failure', $exception->getMessage());
        }
        $this->assertSame('not_found', $ops->status($other->ownerContext, $other->key)->state);
        $this->assertSame(0, $this->database->table($this->probeSchema->parents)->count());
    }

    public function test_expiry_compacts_only_responses_and_never_allows_historical_key_reexecution(): void
    {
        $ops = app(IdempotentOperations::class);
        $intent = $this->intent();
        $ops->run($intent, fn (bool $locking) => null, fn () => new OperationResult(200, ['secret_fixture' => 'erase after TTL']));
        $pending = $this->intent();
        $this->database->transaction(fn () => app(SqlOperationStore::class)->pending($pending));
        $this->travel(25)->hours();
        $this->assertTrue($ops->status($intent->ownerContext, $intent->key)->expired);
        $this->assertSame(0, Artisan::call('operations:compact'));
        $this->assertSame(2, $this->database->table('idempotency_records')->count());
        $this->assertNull($this->database->table('idempotency_records')->where('idempotency_key', $intent->key)->value('response'));
        $this->assertSame('pending', $ops->status($pending->ownerContext, $pending->key)->state);
        $this->reject(fn () => $ops->run($intent, fn (bool $locking) => null, fn () => throw new \LogicException('No repetir')), ApiCode::IDEMPOTENCY_KEY_CONFLICT);
        $this->reject(fn () => $ops->run($pending, fn (bool $locking) => null, fn () => throw new \LogicException('No apropiarse de estado incierto')), ApiCode::OPERATION_IN_PROGRESS);
    }

    public function test_scope_hash_uniqueness_and_authorization_are_enforced_before_replay(): void
    {
        $ops = app(IdempotentOperations::class);
        $intent = $this->intent();
        $ops->run($intent, fn (bool $locking) => null, fn () => new OperationResult(200, ['value' => 1]));
        $this->reject(fn () => $ops->run($this->intent($intent->key, ['value' => 2]), fn (bool $locking) => null, fn () => throw new \LogicException('No ejecutar')), ApiCode::IDEMPOTENCY_KEY_CONFLICT);
        $this->expectException(AuthorizationException::class);
        $ops->run($intent, fn (bool $locking) => throw new AuthorizationException, fn () => throw new \LogicException('No consultar'));
    }

    public function test_retry_cap_is_five_and_validation_is_not_retried(): void
    {
        $attempts = 0;
        try {
            app(IdempotentOperations::class)->run($this->intent(), fn (bool $locking) => null, function () use (&$attempts): never {
                $attempts++;
                $this->createRecord('cap-'.$attempts);
                // Clasificador/cota: excepción sintética; la otra suite provoca deadlock SQL real.
                $previous = new \PDOException('Synthetic deadlock victim');
                $previous->errorInfo = ['40001', 1205, 'fixture'];
                throw new QueryException('sqlsrv_testing', 'fixture', [], $previous);
            });
            $this->fail('Debe agotar intentos.');
        } catch (QueryException) {
            $this->assertSame(5, $attempts);
        }
        $this->assertSame(0, $this->database->table($this->probeSchema->parents)->count());
        $this->assertSame(0, $this->database->table('idempotency_records')->count());
        $attempts = 0;
        $validation = function () use (&$attempts): void {
            app(IdempotentOperations::class)->run($this->intent(), fn (bool $locking) => null, function () use (&$attempts): never {
                $attempts++;
                throw new OperationRejected(ApiCode::VALIDATION_ERROR, ['value' => ['Inválido']]);
            });
        };
        $this->reject($validation, ApiCode::VALIDATION_ERROR);
        $this->assertSame(1, $attempts);
    }
}
