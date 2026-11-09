<?php

use App\Modules\Operaciones\Controllers\OperationsController;
use App\Modules\Operaciones\Controllers\OperationsReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations')->middleware(['jwt', 'role:1,2,3,5,7,8,9,10,12,13'])->group(function () {
    Route::get('/',         [OperationsController::class, 'index']);
    Route::get('/{action}', [OperationsController::class, 'show']);
});

Route::prefix('op-reports')->middleware(['jwt', 'role:1,2,3,5,7,8,9,10,12,13'])->group(function () {
    Route::get('/cambios',     [OperationsReportController::class, 'cambios']);
    Route::get('/giros',       [OperationsReportController::class, 'giros']);
    Route::get('/remesas',     [OperationsReportController::class, 'remesas']);
    Route::get('/remesas-cgr', [OperationsReportController::class, 'remesasCgr']);
    Route::get('/{type}/{id}', [OperationsReportController::class, 'detail'])
         ->where(['type' => 'cambio|giro|remesa', 'id' => '[0-9]+']);
});
