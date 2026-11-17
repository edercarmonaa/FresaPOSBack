<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Contracts\ApiResponder;
use Modules\Core\Http\OperationStatusController;

Route::get('ping', fn (Request $request, ApiResponder $responses) => $responses->success($request, ['status' => 'ok']))->name('ping');
Route::get('operations/{idempotencyKey}/status', OperationStatusController::class)
    ->middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->name('operations.status');
