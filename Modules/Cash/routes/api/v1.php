<?php

use Illuminate\Support\Facades\Route;
use Modules\Cash\Http\Controllers\CashController;

Route::prefix('cash')->middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->group(function (): void {
    Route::get('registers', [CashController::class, 'registers'])->name('registers');
    Route::get('sessions/current', [CashController::class, 'current'])->name('sessions.current');
    Route::post('sessions', [CashController::class, 'open'])->name('sessions.open');
});
