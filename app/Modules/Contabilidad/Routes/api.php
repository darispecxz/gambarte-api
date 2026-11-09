<?php

use App\Modules\Contabilidad\Controllers\AccountingController;
use Illuminate\Support\Facades\Route;

Route::prefix('accounting')->middleware(['jwt', 'role:1,2,3,7,8'])->group(function () {
    Route::get('/daily-summary',    [AccountingController::class, 'dailySummary']);
    Route::get('/cashier-details',  [AccountingController::class, 'cashierDetails']);
    Route::get('/movement-summary', [AccountingController::class, 'movementSummary']);
    Route::get('/agency-closings',  [AccountingController::class, 'agencyClosings']);
    Route::get('/agencies',         [AccountingController::class, 'agencies']);
    Route::get('/ejercicios',       [AccountingController::class, 'ejercicios']);
    Route::get('/subcuentas',       [AccountingController::class, 'subcuentas']);
    Route::get('/balance',          [AccountingController::class, 'balance']);
    Route::get('/libro-mayor',      [AccountingController::class, 'libroMayor']);
    Route::get('/comprobantes',     [AccountingController::class, 'comprobantes']);
    Route::get('/asiento-detalle',  [AccountingController::class, 'asientoDetalle']);
    Route::get('/export',           [AccountingController::class, 'export']);
});
