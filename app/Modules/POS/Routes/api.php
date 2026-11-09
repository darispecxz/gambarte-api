<?php

use App\Modules\POS\Controllers\PosAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->middleware(['jwt', 'role:1,2,3,12'])->group(function () {
    Route::get('/dashboard',              [PosAdminController::class, 'dashboard']);
    Route::get('/movements',              [PosAdminController::class, 'movements']);
    Route::get('/arqueo',                 [PosAdminController::class, 'arqueo']);
    Route::get('/saldo-history/{idCaja}', [PosAdminController::class, 'saldoHistory'])
         ->where('idCaja', '[0-9]+');
    Route::get('/fallas-caja',            [PosAdminController::class, 'fallasCaja']);
    Route::post('/ratificar-falla',       [PosAdminController::class, 'ratificarFalla']);
    Route::post('/anular-cierre',          [PosAdminController::class, 'anularCierre']);
});
