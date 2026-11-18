<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\ApiResponder;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Http\Requests\ReasonRequest;
use Modules\Sales\Http\Resources\ReasonResource;

final class ReasonController
{
    public function __construct(private readonly SaleOperationReasons $reasons, private readonly ApiResponder $responses) {}

    public function index(ReasonRequest $request): JsonResponse
    {
        $page = $this->reasons->search($request->actor(), $request->validated());

        return $this->responses->success($request, ['reasons' => array_map(fn ($row) => (new ReasonResource($row))->resolve($request), $page['items'])],
            ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $page['total']]);
    }

    public function show(ReasonRequest $request, string $id): JsonResponse
    {
        return $this->responses->success($request, ['reason' => (new ReasonResource($this->reasons->find($request->actor(), $id)))->resolve($request)]);
    }

    public function create(ReasonRequest $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(ReasonRequest $request, string $id): JsonResponse
    {
        return $this->save($request, $id);
    }

    public function status(ReasonRequest $request, string $id): JsonResponse
    {
        return $this->save($request, $id);
    }

    private function save(ReasonRequest $request, ?string $id = null): JsonResponse
    {
        $row = $this->reasons->write($request->actor(), $request->action(), $request->validated(),
            (string) $request->header('Idempotency-Key'), $id, $request->header('If-Match'));

        return $this->responses->success($request, ['reason' => (new ReasonResource($row))->resolve($request)], status: $id === null ? 201 : 200);
    }
}
