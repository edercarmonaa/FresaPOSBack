<?php

use Illuminate\Support\Facades\Route;
use Modules\Tickets\Http\Controllers\ReceiptController;

Route::middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->group(function (): void {
    Route::get('sales/folio/{folio}/receipt', [ReceiptController::class, 'byFolio'])->where('folio', '[0-9]{12}')->name('folio');
    Route::get('sales/{id}/receipt', [ReceiptController::class, 'show'])->whereUlid('id')->name('show');
});
