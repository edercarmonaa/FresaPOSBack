<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SaleController;
use Modules\Sales\Http\Controllers\TableController;

Route::prefix('sales')->middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->group(function (): void {
    Route::prefix('tables')->name('tables.')->group(function (): void {
        Route::get('/', [TableController::class, 'index'])->name('index');
        Route::post('/', [TableController::class, 'store'])->name('store');
        Route::get('{id}', [TableController::class, 'show'])->whereUlid('id')->name('show');
        Route::put('{id}', [TableController::class, 'update'])->whereUlid('id')->name('update');
        Route::patch('{id}/status', [TableController::class, 'status'])->whereUlid('id')->name('status');
    });
    Route::get('/', [SaleController::class, 'index'])->name('index');
    Route::post('/', [SaleController::class, 'store'])->name('store');
    Route::get('{id}', [SaleController::class, 'show'])->whereUlid('id')->name('show');
    Route::get('{id}/items', [SaleController::class, 'items'])->whereUlid('id')->name('items');
    Route::post('{id}/items', [SaleController::class, 'addItem'])->whereUlid('id')->name('items.store');
    Route::patch('{id}/items/{itemId}/quantity', [SaleController::class, 'quantity'])->whereUlid(['id', 'itemId'])->name('items.quantity');
    Route::patch('{id}/items/{itemId}/note', [SaleController::class, 'itemNote'])->whereUlid(['id', 'itemId'])->name('items.note');
    Route::delete('{id}/items/{itemId}', [SaleController::class, 'removeItem'])->whereUlid(['id', 'itemId'])->name('items.destroy');
    foreach (['note' => 'patch', 'pause' => 'post', 'resume' => 'post', 'cancel' => 'post'] as $action => $method) {
        Route::$method('{id}/'.$action, [SaleController::class, $action])->whereUlid('id')->name($action);
    }
});
