<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\ApiAuthenticationController;

Route::prefix('auth')->middleware('auth.no-store')->group(function (): void {
    Route::post('login', [ApiAuthenticationController::class, 'login'])->name('login');
    Route::middleware(['auth:sanctum', 'auth.access.api'])->group(function (): void {
        Route::get('me', [ApiAuthenticationController::class, 'me'])->name('me');
        Route::post('logout', [ApiAuthenticationController::class, 'logout'])->name('logout');
        Route::post('password', [ApiAuthenticationController::class, 'password'])->middleware('throttle:auth-security')->name('password');
    });
});
