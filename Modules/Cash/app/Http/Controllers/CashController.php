<?php

namespace Modules\Cash\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Http\Requests\CashRequest;
use Modules\Cash\Http\Resources\RegisterResource;
use Modules\Cash\Http\Resources\SessionResource;
use Modules\Core\Contracts\ApiResponder;

final class CashController
{
    public function __construct(private readonly CashOperations $cash, private readonly ApiResponder $responses) {}

    public function registers(CashRequest $request): JsonResponse
    {
        return $this->responses->success($request, ['registers' => array_map(fn ($register) => (new RegisterResource($register))->resolve($request), $this->cash->registers($request->actor()))]);
    }

    public function current(CashRequest $request): JsonResponse
    {
        $session = $this->cash->current($request->actor());

        return $this->responses->success($request, ['session' => $session ? (new SessionResource($session))->resolve($request) : null]);
    }

    public function open(CashRequest $request): JsonResponse
    {
        $session = $this->cash->open($request->actor(), $request->validated('register_id'), $request->validated('opening_fund'), (string) $request->header('Idempotency-Key'));

        // 200 tanto en primera ejecución como en replay: mismo recurso, sin afirmar una nueva creación.
        return $this->responses->success($request, ['session' => (new SessionResource($session))->resolve($request)]);
    }
}
