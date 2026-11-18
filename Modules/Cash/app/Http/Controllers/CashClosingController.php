<?php

namespace Modules\Cash\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Cash\Contracts\CashClosings;
use Modules\Cash\Http\Requests\CashClosingRequest;
use Modules\Core\Contracts\ApiResponder;

final class CashClosingController
{
    public function __construct(private readonly CashClosings $closings, private readonly ApiResponder $responses) {}

    public function close(CashClosingRequest $request, string $id): JsonResponse
    {
        return $this->responses->success($request, ['reconciliation' => $this->closings->close($request->actor(), $id, $request->input('counted_cash'), $request->input('observation'), (string) $request->header('Idempotency-Key'))]);
    }

    public function summary(CashClosingRequest $request, string $id): JsonResponse
    {
        return $this->reconciliation($request, $id);
    }

    public function reconciliation(CashClosingRequest $request, string $id): JsonResponse
    {
        return $this->responses->success($request, ['reconciliation' => $this->closings->reconciliation($request->actor(), $id)]);
    }

    public function pending(CashClosingRequest $request, string $id): JsonResponse
    {
        $page = $this->closings->pending($request->actor(), $id, (int) $request->input('page', 1));

        return $this->responses->success($request, ['pending_sales' => $page['items']], ['total' => $page['total'], 'page' => $page['page'], 'per_page' => $page['per_page']]);
    }

    public function history(CashClosingRequest $request): JsonResponse
    {
        $page = $this->closings->history($request->actor(), $request->validated());

        return $this->responses->success($request, ['closings' => $page['items']], ['total' => $page['total'], 'page' => $page['page'], 'per_page' => $page['perPage']]);
    }
}
