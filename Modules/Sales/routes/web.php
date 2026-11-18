<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Middleware\PosAccess;

Route::view('pos', 'sales::pos')->middleware(['auth.no-store', 'auth.access.web', PosAccess::class])->name('sales.pos');
Route::view('admin/sales/reasons', 'sales::reasons')->middleware(['auth.no-store', 'auth.access.web', PosAccess::class, 'can:settings.update'])->name('sales.reasons');
