<?php

use Illuminate\Support\Facades\Route;
use Modules\Tickets\Http\Controllers\ReceiptController;

Route::prefix('pos/sales')->middleware(['auth.no-store', 'auth.access.web'])->group(function (): void {
    Route::get('{id}/receipt', [ReceiptController::class, 'preview'])->whereUlid('id')->name('tickets.preview');
    Route::post('{id}/receipt/print', [ReceiptController::class, 'print'])->whereUlid('id')->name('tickets.print');
});
