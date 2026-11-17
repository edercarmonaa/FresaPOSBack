<?php

use Illuminate\Support\Facades\Route;
use Modules\Cash\Http\Middleware\CashAccess;

Route::view('cash', 'cash::desk')->middleware(['auth.no-store', 'auth.access.web', CashAccess::class])->name('cash.desk');
