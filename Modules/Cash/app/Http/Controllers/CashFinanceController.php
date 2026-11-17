<?php

namespace Modules\Cash\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Cash\Http\Requests\CashFinanceRequest;
use Modules\Cash\Http\Resources\CashMovementResource;
use Modules\Cash\Http\Resources\PaymentMethodResource;
use Modules\Core\Contracts\ApiResponder;

final class CashFinanceController
{
    public function __construct(private readonly CashMovements $movements, private readonly PaymentMethods $methods, private readonly ApiResponder $responses) {}

    public function methods(CashFinanceRequest $request): JsonResponse
    {
        return $this->responses->success($request, ['payment_methods' => array_map(fn ($item) => (new PaymentMethodResource($item))->resolve($request), $this->methods->available($request->actor()))]);
    }

    public function method(CashFinanceRequest $request, string $id): JsonResponse
    {
        return $this->responses->success($request, ['payment_method' => (new PaymentMethodResource($this->methods->find($request->actor(), $id)))->resolve($request)]);
    }

    public function index(CashFinanceRequest $request): JsonResponse
    {
        $filters = $request->validated();
        foreach (['page', 'per_page'] as $field) {
            if (isset($filters[$field])) {
                $filters[$field] = (int) $filters[$field];
            }
        }
        $page = $this->movements->search($request->actor(), $filters);

        return $this->responses->success($request, ['movements' => array_map(fn ($item) => (new CashMovementResource($item))->resolve($request), $page['items']), 'balance' => $page['balance']], ['page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $page['total']]);
    }

    public function show(CashFinanceRequest $request, string $id): JsonResponse
    {
        return $this->responses->success($request, ['movement' => (new CashMovementResource($this->movements->find($request->actor(), $id)))->resolve($request)]);
    }

    public function store(CashFinanceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $movement = $this->movements->record($request->actor(), $data['cash_session_id'], $data['type'], $data['amount'], $data['concept'], (string) $request->header('Idempotency-Key'));

        return $this->responses->success($request, ['movement' => (new CashMovementResource($movement))->resolve($request)], status: 201);
    }
}
