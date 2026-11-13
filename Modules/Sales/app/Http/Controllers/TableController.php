<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Contracts\ApiResponder;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Http\Requests\SalesRequest;
use Modules\Sales\Http\Resources\TableResource;

final class TableController
{
    public function __construct(private readonly SalesOperations $sales, private readonly ApiResponder $responses, private readonly CoordinateSalesWrite $writes) {}

    public function index(SalesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = $this->sales->tables($request->actor(), new TableQuery($data['search'] ?? null,
            isset($data['is_active']) ? (bool) $data['is_active'] : null, isset($data['is_occupied']) ? (bool) $data['is_occupied'] : null,
            (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25)));

        return $this->responses->success($request, ['tables' => array_map(fn ($table) => (new TableResource($table))->resolve($request), $page->items)],
            ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total]);
    }

    public function show(SalesRequest $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->sales->table($request->actor(), $id));
    }

    public function store(SalesRequest $request): JsonResponse
    {
        return $this->respond($request, $this->sales->createTable($request->actor(), $request->validated('name'), (int) $request->validated('display_order', 0)), 201);
    }

    public function update(SalesRequest $request, string $id): JsonResponse
    {
        $record = $this->writes->run($request->actor(), $id, true, $request->header('If-Match'),
            fn () => $this->sales->updateTable($request->actor(), $id, $request->validated('name'), (bool) $request->validated('is_active'), (int) $request->validated('display_order', 0)));

        return $this->respond($request, $record);
    }

    public function status(SalesRequest $request, string $id): JsonResponse
    {
        $record = $this->writes->run($request->actor(), $id, true, $request->header('If-Match'),
            fn (DiningTableSnapshot $before) => $this->sales->updateTable($request->actor(), $id, $before->name, (bool) $request->validated('is_active'), $before->displayOrder));

        return $this->respond($request, $record);
    }

    private function respond(SalesRequest $request, DiningTableSnapshot $table, int $status = 200): JsonResponse
    {
        return $this->responses->success($request, (new TableResource($table))->resolve($request), status: $status);
    }
}
