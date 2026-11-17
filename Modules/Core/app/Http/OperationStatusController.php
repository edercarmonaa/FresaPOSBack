<?php

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiResponder;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;

final class OperationStatusController
{
    public function __invoke(Request $request, string $idempotencyKey, IdempotentOperations $operations, ApiResponder $responses): JsonResponse
    {
        $status = $operations->status(OperationIntent::context((string) $request->user()->getAuthIdentifier()), $idempotencyKey);
        if ($status->state === 'not_found') {
            return $responses->error($request, ApiCode::NOT_FOUND);
        }

        return $responses->success($request, ['operation' => ['key' => strtolower($idempotencyKey), 'state' => $status->expired ? 'expired' : $status->state,
            'operation' => $status->operation, 'resource_id' => $status->resourceId, 'http_status' => $status->httpStatus,
            'completed_at' => $status->completedAt]]);
    }
}
