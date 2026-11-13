<?php

namespace Modules\Sales\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\ApiResponder;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Http\Requests\SalesRequest;
use Modules\Sales\Http\Resources\SaleItemResource;
use Modules\Sales\Http\Resources\SaleResource;

final class SaleController
{
    public function __construct(private readonly SalesOperations $sales, private readonly ApiResponder $responses,
        private readonly CoordinateSalesWrite $writes, private readonly CriticalSalesWrites $critical, private readonly CancellationApproval $cancellations) {}

    public function index(SalesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = $this->sales->sales($request->actor(), new SaleQuery(isset($data['state']) ? SaleState::from($data['state']) : null,
            isset($data['type']) ? SaleType::from($data['type']) : null, $data['table_id'] ?? null, $data['created_by'] ?? null,
            (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25), $data['date'] ?? null, $data['order'] ?? 'desc'));

        return $this->responses->success($request, ['sales' => array_map(fn ($sale) => (new SaleResource($sale))->resolve($request), $page->items)],
            ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total]);
    }

    public function show(SalesRequest $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->sales->sale($request->actor(), $id));
    }

    public function items(SalesRequest $request, string $id): JsonResponse
    {
        $sale = $this->sales->sale($request->actor(), $id);

        return $this->responses->success($request, ['items' => array_map(fn ($item) => (new SaleItemResource($item))->resolve($request), $sale->items)]);
    }

    public function store(SalesRequest $request): JsonResponse
    {
        $sale = $this->critical->run($request->actor(), 'sales.open', (string) $request->header('Idempotency-Key'), $request->validated(),
            fn () => $this->sales->open($request->actor(), new OpenSale(SaleType::from($request->validated('type')), $request->validated('table_id'), $request->validated('note'))));

        return $this->respond($request, $sale, 201);
    }

    public function addItem(SalesRequest $request, string $id): JsonResponse
    {
        $sale = $this->critical->run($request->actor(), 'sales.'.$id.'.add', (string) $request->header('Idempotency-Key'), $request->validated(),
            fn () => $this->writes->run($request->actor(), $id, false, $request->header('If-Match'),
                fn () => $this->sales->addProduct($request->actor(), $id, new AddProduct($request->validated('product_id'), $request->validated('quantity'), $request->validated('note')))));

        return $this->respond($request, $sale);
    }

    public function quantity(SalesRequest $request, string $id, string $itemId): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->changeQuantity($request->actor(), $id, $itemId, $request->validated('quantity')));
    }

    public function itemNote(SalesRequest $request, string $id, string $itemId): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->editItemNote($request->actor(), $id, $itemId, $request->validated('note')));
    }

    public function note(SalesRequest $request, string $id): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->editNote($request->actor(), $id, $request->validated('note')));
    }

    public function removeItem(SalesRequest $request, string $id, string $itemId): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->removeItem($request->actor(), $id, $itemId, $request->validated('reason_code')));
    }

    public function pause(SalesRequest $request, string $id): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->pause($request->actor(), $id));
    }

    public function resume(SalesRequest $request, string $id): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->resume($request->actor(), $id));
    }

    public function cancel(SalesRequest $request, string $id): JsonResponse
    {
        return $this->mutate($request, $id, fn () => $this->sales->cancel($request->actor(), $id,
            $this->cancellations->reason($request->actor(), $id, $request->validated('reason_code'))));
    }

    /** @param Closure(): SaleSnapshot $operation */
    private function mutate(SalesRequest $request, string $id, Closure $operation): JsonResponse
    {
        $sale = $this->writes->run($request->actor(), $id, false, $request->header('If-Match'), $operation);

        return $this->respond($request, $sale);
    }

    private function respond(SalesRequest $request, SaleSnapshot $sale, int $status = 200): JsonResponse
    {
        return $this->responses->success($request, (new SaleResource($sale))->resolve($request), status: $status);
    }
}
