<?php

use App\Modules\GestionOperativa\Controllers\GestionOperativaController;
use Illuminate\Support\Facades\Route;

Route::prefix('gestion-operativa')->middleware(['jwt', 'role:1,2,3,12'])->group(function () {
    Route::get('/consolidado',     [GestionOperativaController::class, 'consolidado']);
    Route::get('/alertas',         [GestionOperativaController::class, 'alertas']);
    Route::get('/recomendaciones', [GestionOperativaController::class, 'recomendaciones']);
    Route::get('/transferencias',  [GestionOperativaController::class, 'transferencias']);
    Route::get('/solicitudes',     [GestionOperativaController::class, 'solicitudesPendientes']);
    Route::get('/resumen-ejecutivo', [GestionOperativaController::class, 'resumenEjecutivo']);
    Route::get('/detalle-agencia', [GestionOperativaController::class, 'detalleAgencia']);
    Route::get('/conciliacion-remesas', [GestionOperativaController::class, 'conciliacionRemesas']);
    Route::get('/movimientos-dia', [GestionOperativaController::class, 'movimientosDia']);
    Route::get('/cotizaciones-agencia', [GestionOperativaController::class, 'cotizacionesAgencia']);
    Route::get('/gastos-operativo',    [GestionOperativaController::class, 'gastosOperativo']);
    Route::get('/gastos-detalle-agencia',   [GestionOperativaController::class, 'gastosDetalleAgencia']);
    Route::get('/gastos-detalle-subcuenta', [GestionOperativaController::class, 'gastosDetalleSubcuenta']);
});
