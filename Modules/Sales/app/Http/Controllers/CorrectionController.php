<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Contracts\AuthenticatedRequestAccess;
use Modules\Core\Contracts\ApiResponder;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Sales\Http\Requests\CorrectionRequest;
use Modules\Sales\Http\Resources\CorrectionResource;

final class CorrectionController
{
    public function __construct(private readonly SaleCorrections $corrections, private readonly ApiResponder $responses) {}

    public function reopen(CorrectionRequest $request, string $id): JsonResponse
    {
        return $this->write($request, $id, 'reopen');
    }

    public function edit(CorrectionRequest $request, string $id): JsonResponse
    {
        return $this->write($request, $id, 'edit');
    }

    public function confirm(CorrectionRequest $request, string $id): JsonResponse
    {
        return $this->write($request, $id, 'confirm');
    }

    public function discard(CorrectionRequest $request, string $id): JsonResponse
    {
        return $this->write($request, $id, 'discard');
    }

    private function write(CorrectionRequest $request, string $id, string $action): JsonResponse
    {
        $result = $this->corrections->write($request->actor(), $id, $action, $request->validated(), (string) $request->header('Idempotency-Key'), $request->header('If-Match'),
            fn () => app(AuthenticatedRequestAccess::class)->requireApi($request, $request->actor()));

        return $this->responses->success($request, (new CorrectionResource($result))->resolve($request));
    }

    public function current(CorrectionRequest $request, string $id): JsonResponse
    {
        return $this->responses->success($request, (new CorrectionResource($this->corrections->current($request->actor(), $id)))->resolve($request));
    }

    public function history(CorrectionRequest $request, string $id): JsonResponse
    {
        $page = $this->corrections->history($request->actor(), $id, (int) $request->validated('page', 1));

        return $this->responses->success($request, ['corrections' => array_map(fn ($row) => (new CorrectionResource($row))->resolve($request), $page['items'])],
            ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $page['total']]);
    }
}
