<?php

namespace Tests\Integration;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\SqlServerTestCase;

class PersistenceTest extends SqlServerTestCase
{
    public function test_connection_and_schema_use_native_sql_server_types_and_clustered_primary_key(): void
    {
        $this->assertStringEndsWith('_test', strtolower($this->database->selectOne('SELECT DB_NAME() AS name')->name));
        $columns = $this->database->select(
            'SELECT c.name, t.name AS type_name, c.max_length, c.precision, c.scale FROM sys.columns c JOIN sys.types t ON c.user_type_id = t.user_type_id WHERE c.object_id = OBJECT_ID(?)',
            [$this->probeSchema->parents],
        );
        $columns = collect($columns)->keyBy('name');
        $this->assertSame('nchar', $columns['id']->type_name);
        $this->assertSame(52, (int) $columns['id']->max_length);
        $this->assertSame('decimal', $columns['amount']->type_name);
        $this->assertSame(19, (int) $columns['amount']->precision);
        $this->assertSame(4, (int) $columns['amount']->scale);
        $this->assertSame('datetime2', $columns['occurred_at']->type_name);
        $primary = $this->database->selectOne('SELECT type_desc FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND is_primary_key = 1', [$this->probeSchema->parents]);
        $this->assertSame('CLUSTERED', $primary->type_desc);
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make('db')->connection('sqlsrv');
    }

    public function test_eloquent_creates_recovers_and_serializes_a_native_ulid_as_a_string(): void
    {
        $record = $this->createRecord();
        $other = $this->createRecord('second');
        $id = $record->getKey();
        $this->assertIsString($id);
        $this->assertSame(26, strlen($id));
        $this->assertTrue(Str::isUlid($id));
        $this->assertSame(strtolower($id), $id);
        $this->assertNotSame($id, $other->getKey());
        $this->assertFalse($record->getIncrementing());
        $this->assertSame('string', $record->getKeyType());
        $loaded = $record->newQuery()->findOrFail($id);
        $this->assertSame($id, $loaded->getKey());
        $this->assertSame($id, $loaded->toArray()['id']);
        $this->assertSame($id, json_decode($loaded->toJson(), true, 512, JSON_THROW_ON_ERROR)['id']);
    }

    public function test_decimal_precision_utc_storage_and_unique_constraint(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $record = $this->createRecord();
        $record->amount = '999999999999999.1234';
        $record->save();
        $record->refresh();
        $this->assertSame('999999999999999.1234', $record->amount);
        $this->assertSame('2026-01-01 18:00:00', $record->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01T18:00:00.000000Z', $record->toArray()['occurred_at']);
        $this->assertConstraintViolation(fn () => $this->createRecord(), [2601, 2627]);
    }

    public function test_real_foreign_key_rejects_missing_parent_and_blocks_cascading_delete(): void
    {
        $parent = $this->createRecord();
        $children = $this->database->table($this->probeSchema->children);
        $this->assertConstraintViolation(fn () => $children->insert([
            'id' => strtolower((string) Str::ulid()), 'parent_id' => strtolower((string) Str::ulid()),
        ]), [547]);
        $children->insert(['id' => strtolower((string) Str::ulid()), 'parent_id' => $parent->getKey()]);
        $this->assertConstraintViolation(fn () => $parent->delete(), [547]);
        $this->assertSame(1, $children->count());
        $this->assertSame(1, $this->database->table($this->probeSchema->parents)->count());
        $constraint = $this->database->selectOne('SELECT delete_referential_action_desc AS action FROM sys.foreign_keys WHERE parent_object_id = OBJECT_ID(?)', [$this->probeSchema->children]);
        $this->assertSame('NO_ACTION', $constraint->action);
        $index = $this->database->selectOne('SELECT name FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?', [$this->probeSchema->children, $this->probeSchema->children.'_parent_id_index']);
        $this->assertNotNull($index);
    }

    public function test_test_only_migration_is_reversible_without_permanent_migration_files(): void
    {
        $schema = $this->database->getSchemaBuilder();
        $this->probeSchema->down();
        $this->assertFalse($schema->hasTable($this->probeSchema->parents));
        $this->assertFalse($schema->hasTable($this->probeSchema->children));
        $this->probeSchema->up();
        $this->assertTrue($schema->hasTable($this->probeSchema->parents));
        $this->assertTrue($schema->hasTable($this->probeSchema->children));
    }

    /** @param list<int> $numbers */
    private function assertConstraintViolation(Closure $operation, array $numbers): void
    {
        try {
            $operation();
            $this->fail('SQL Server debía rechazar la violación de integridad.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertContains((int) $exception->errorInfo[1], $numbers);
        }
    }
}
