<?php

namespace Modules\Core\Infrastructure\Persistence;

use PDO;

/** Lock de sesión SQL: exclusión inmediata incluso antes del lock global de identidad. */
final class SqlOperationLock
{
    public function resource(string $ownerContext, string $key): string
    {
        return 'fresapos.idempotency.'.hash('sha256', $ownerContext.':'.$key);
    }

    public function acquire(PDO $pdo, string $resource, bool $shared = false): bool
    {
        $statement = $pdo->prepare("SET NOCOUNT ON; DECLARE @result int; EXEC @result=sys.sp_getapplock @Resource=?, @LockMode=?, @LockOwner='Session', @LockTimeout=0; SELECT @result AS result;");
        $statement->execute([$resource, $shared ? 'Shared' : 'Exclusive']);
        while ($statement->columnCount() === 0 && $statement->nextRowset()) {
        }
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        if (! is_array($result)) {
            throw new \RuntimeException('SQL Server no devolvió el resultado del lock.');
        }
        $code = (int) $result['result'];
        if ($code < -1) {
            throw new \RuntimeException('No se pudo adquirir el lock de operación.');
        }

        return $code >= 0;
    }

    public function release(PDO $pdo, string $resource): void
    {
        $statement = $pdo->prepare("SET NOCOUNT ON; EXEC sys.sp_releaseapplock @Resource=?, @LockOwner='Session';");
        $statement->execute([$resource]);
        $statement->closeCursor();
    }
}
