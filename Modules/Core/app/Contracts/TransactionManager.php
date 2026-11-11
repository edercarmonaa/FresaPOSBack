<?php

namespace Modules\Core\Contracts;

use Closure;

interface TransactionManager
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed;

    public function afterCommit(Closure $callback): void;
}
