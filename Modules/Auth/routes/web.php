<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\WebAuthenticationController;

Route::middleware('auth.no-store')->group(function (): void {
    Route::view('login', 'auth::login')->name('login');
    Route::post('login', [WebAuthenticationController::class, 'login'])->name('auth.web.login');
    Route::middleware('auth.access.web')->group(function (): void {
        Route::get('account', [WebAuthenticationController::class, 'account'])->name('auth.account');
        Route::view('admin', 'auth::admin')->middleware('can:users.view')->name('auth.admin');
        Route::post('logout', [WebAuthenticationController::class, 'logout'])->name('auth.web.logout');
        Route::view('auth/password', 'auth::password')->name('auth.password');
        Route::post('auth/password', [WebAuthenticationController::class, 'password'])->middleware('throttle:auth-security')->name('auth.password.update');
        Route::post('auth/pin', [WebAuthenticationController::class, 'pin'])->name('auth.pin');
        Route::post('auth/pin/disable', [WebAuthenticationController::class, 'disablePin'])->name('auth.pin.disable');
        Route::post('auth/terminal', [WebAuthenticationController::class, 'authorizeTerminal'])->name('auth.terminal');
        Route::post('auth/employee', [WebAuthenticationController::class, 'switchEmployee'])->name('auth.employee');
        Route::post('auth/sessions/{id}/revoke', [WebAuthenticationController::class, 'revoke'])->whereUlid('id')->name('auth.session.revoke');
    });
});

// Las rutas del módulo se incorporan cuando existe un caso de uso autorizado.
