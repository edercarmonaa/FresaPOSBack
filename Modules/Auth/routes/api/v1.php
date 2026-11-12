<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\ApiAuthenticationController;

Route::prefix('auth')->middleware('auth.no-store')->group(function (): void {
    Route::post('login', [ApiAuthenticationController::class, 'login'])->name('login');
    Route::middleware(['auth:sanctum', 'auth.access.api'])->group(function (): void {
        Route::get('sessions', [ApiAuthenticationController::class, 'sessions'])->name('sessions');
        Route::delete('sessions/{id}', [ApiAuthenticationController::class, 'revokeSession'])->whereUlid('id')->name('sessions.revoke');
        Route::delete('sessions', [ApiAuthenticationController::class, 'revokeSessions'])->name('sessions.revoke-all');
        Route::get('users/{userId}/sessions', [ApiAuthenticationController::class, 'sessions'])->whereUlid('userId')->middleware('can:users.status')->name('users.sessions');
        Route::delete('users/{userId}/sessions', [ApiAuthenticationController::class, 'revokeSessions'])->whereUlid('userId')->middleware('can:users.status')->name('users.sessions.revoke');
        Route::get('me', [ApiAuthenticationController::class, 'me'])->name('me');
        Route::post('logout', [ApiAuthenticationController::class, 'logout'])->name('logout');
        Route::post('password', [ApiAuthenticationController::class, 'password'])->middleware('throttle:auth-security')->name('password');
    });
});
