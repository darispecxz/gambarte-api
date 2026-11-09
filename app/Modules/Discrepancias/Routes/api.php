<?php

use App\Modules\Discrepancias\Controllers\DiscrepancyController;
use Illuminate\Support\Facades\Route;

Route::prefix('discrepancies')->middleware(['jwt', 'role:2,3'])->group(function () {
    Route::get('/',              [DiscrepancyController::class, 'index']);
    Route::get('/summary',       [DiscrepancyController::class, 'summary']);
    Route::get('/types',         [DiscrepancyController::class, 'types']);
    Route::post('/run',          [DiscrepancyController::class, 'run']);
    Route::post('/{id}/resolve', [DiscrepancyController::class, 'resolve']);
});
