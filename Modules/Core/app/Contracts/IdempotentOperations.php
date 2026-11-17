<?php

namespace Modules\Core\Contracts;

use Closure;

interface IdempotentOperations
{
    /** $authorize(false) verifica permisos sin tomar locks; true coordina su lock en la TX Core.
     * @param  Closure(bool): void  $authorize
     * @param  Closure(): OperationResult  $operation
     */
    public function run(OperationIntent $intent, Closure $authorize, Closure $operation): OperationResult;

    /** El llamador debe autenticar y derivar el contexto del actor real. No devuelve respuestas almacenadas. */
    public function status(string $ownerContext, string $key): OperationStatus;
}
