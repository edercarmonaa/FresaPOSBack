<?php

namespace Tests\Feature;

use Illuminate\Database\DeadlockException;
use Modules\Core\Infrastructure\Persistence\SqlTransientErrors;
use PDOException;
use PHPUnit\Framework\TestCase;

class NestedSqlDeadlockTest extends TestCase
{
    public function test_only_recognized_sql_codes_survive_laravel_nested_deadlock_wrappers(): void
    {
        $classifier = new SqlTransientErrors;
        $sql = new PDOException('Private SQL diagnostic');
        $sql->errorInfo = ['40001', 1205];
        $nested = new DeadlockException('Nested', 0, new DeadlockException('Nested', 0, $sql));
        $this->assertTrue($classifier->recoverable($nested));
        $this->assertFalse($classifier->reconnect($nested));
        $this->assertSame(1205, $classifier->code($nested));
        $sql->errorInfo = ['23000', 547];
        $this->assertFalse($classifier->recoverable($nested));
        $this->assertFalse($classifier->recoverable(new DeadlockException('deadlock 1205')));
        $this->assertFalse($classifier->recoverable(new \RuntimeException('1205', 1205, $nested)));
    }
}
