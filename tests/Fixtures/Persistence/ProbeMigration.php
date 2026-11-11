<?php

namespace Tests\Fixtures\Persistence;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\TestDatabaseGuard;

final class ProbeMigration extends Migration
{
    public function __construct(
        private readonly Connection $database,
        public readonly string $parents,
        public readonly string $children,
    ) {}

    public function up(): void
    {
        TestDatabaseGuard::assertConnected($this->database);
        $schema = $this->database->getSchemaBuilder();
        $schema->create($this->parents, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->decimal('amount', 19, 4);
            $table->dateTime('occurred_at', 6);
        });
        $schema->create($this->children, function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('parent_id');
            $table->foreign('parent_id')->references('id')->on($this->parents)->noActionOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        TestDatabaseGuard::assertConnected($this->database);
        $schema = $this->database->getSchemaBuilder();
        $schema->dropIfExists($this->children);
        $schema->dropIfExists($this->parents);
    }
}
