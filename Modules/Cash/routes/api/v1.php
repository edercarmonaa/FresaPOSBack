<?php

use Illuminate\Support\Facades\Route;
use Modules\Cash\Http\Controllers\CashController;
use Modules\Cash\Http\Controllers\CashFinanceController;

Route::prefix('cash')->middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->group(function (): void {
    Route::get('payment-methods', [CashFinanceController::class, 'methods'])->name('payment-methods.index');
    Route::get('payment-methods/{id}', [CashFinanceController::class, 'method'])->whereUlid('id')->name('payment-methods.show');
    Route::get('movements', [CashFinanceController::class, 'index'])->name('movements.index');
    Route::get('movements/{id}', [CashFinanceController::class, 'show'])->whereUlid('id')->name('movements.show');
    Route::post('movements', [CashFinanceController::class, 'store'])->name('movements.store');
    Route::get('registers', [CashController::class, 'registers'])->name('registers');
    Route::get('sessions/current', [CashController::class, 'current'])->name('sessions.current');
    Route::post('sessions', [CashController::class, 'open'])->name('sessions.open');
});
