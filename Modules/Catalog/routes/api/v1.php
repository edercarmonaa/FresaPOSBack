<?php

use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\CategoryController;
use Modules\Catalog\Http\Controllers\ProductController;

Route::prefix('catalog')->middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->group(function (): void {
    foreach (['categories' => CategoryController::class, 'products' => ProductController::class] as $resource => $controller) {
        Route::get($resource, [$controller, 'index'])->name($resource.'.index');
        Route::post($resource, [$controller, 'store'])->name($resource.'.store');
        Route::get($resource.'/{id}', [$controller, 'show'])->whereUlid('id')->name($resource.'.show');
        Route::put($resource.'/{id}', [$controller, 'update'])->whereUlid('id')->name($resource.'.update');
        Route::patch($resource.'/{id}/status', [$controller, 'status'])->whereUlid('id')->name($resource.'.status');
        Route::delete($resource.'/{id}', [$controller, 'destroy'])->whereUlid('id')->name($resource.'.destroy');
    }
    Route::post('products/{id}/restore', [ProductController::class, 'restore'])->whereUlid('id')->name('products.restore');
});
