<?php

use App\Modules\Uif\Controllers\UifController;
use Illuminate\Support\Facades\Route;

Route::prefix('uif')->middleware(['jwt', 'role:2,3,5,9,11'])->group(function () {
    Route::get('/agencies',      [UifController::class, 'agencies']);
    Route::get('/pcc-report',    [UifController::class, 'pccReport']);
    Route::get('/cambios',       [UifController::class, 'cambios']);
    Route::get('/giros',         [UifController::class, 'giros']);
    Route::get('/remesas',       [UifController::class, 'remesas']);
    Route::get('/usuario-financiero', [UifController::class, 'usuarioFinanciero']);
    Route::get('/export',        [UifController::class, 'export']);
    Route::get('/pcc06-pdf/{id}', [UifController::class, 'pcc06Pdf'])->where('id', '[0-9]+');
    Route::get('/pcc08-pdf/{operacion}/{id}', [UifController::class, 'pcc08Pdf'])->where(['operacion' => '[0-9]+', 'id' => '[0-9]+']);
    Route::get('/pcc-blank/{type}', [UifController::class, 'pccBlank'])->where('type', '6|8');
    Route::get('/report-pdf/{id}', [UifController::class, 'reportPdf'])->where('id', '[0-9]+');
});
