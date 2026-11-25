<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Reports\Http\Controllers\ReportController;

Route::prefix('reports')->middleware(['auth.no-store', 'auth:sanctum', 'auth.access.api'])->group(function (): void {
    Route::get('{report}', [ReportController::class, 'api'])->whereIn('report', array_keys(ReportDefinition::NAMES))->name('show');
    Route::post('{report}/exports/{format}', [ReportController::class, 'apiExport'])->whereIn('report', array_keys(ReportDefinition::NAMES))->whereIn('format', ['pdf', 'xlsx'])->name('export');
});
