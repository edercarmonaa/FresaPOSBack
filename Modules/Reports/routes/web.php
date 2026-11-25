<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Reports\Http\Controllers\ReportController;

Route::middleware(['auth.no-store', 'auth.access.web'])->group(function (): void {
    Route::get('reports', [ReportController::class, 'web'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'web'])->whereIn('report', array_keys(ReportDefinition::NAMES))->name('reports.show');
    Route::post('reports/{report}/exports/{format}', [ReportController::class, 'webExport'])->whereIn('report', array_keys(ReportDefinition::NAMES))->whereIn('format', ['pdf', 'xlsx'])->name('reports.export');
});

// Las rutas del módulo se incorporan cuando existe un caso de uso autorizado.
