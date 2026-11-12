<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin/catalog')->middleware(['auth.no-store', 'auth.access.web', 'catalog.admin'])->group(function (): void {
    Route::view('categories', 'catalog::categories')->name('catalog.categories');
    Route::view('products', 'catalog::products')->name('catalog.products');
});
