<?php

use App\Modules\Cripto\Controllers\CriptoController;
use Illuminate\Support\Facades\Route;

Route::prefix('cajero/cripto')->middleware(['jwt', 'role:1,2,4,6'])->group(function () {
    Route::get('/catalogo', [CriptoController::class, 'catalogo']);
    Route::get('/configuracion/binance-pay-qr', [CriptoController::class, 'binancePayQr']);
    Route::get('/pay/historial', [CriptoController::class, 'binancePayHistory']);
    Route::get('/pay/sincronizacion', [CriptoController::class, 'binancePaySyncStatus']);
    Route::get('/cajas', [CriptoController::class, 'cajas']);
    Route::get('/operaciones', [CriptoController::class, 'listar']);
    Route::get('/operaciones/{id}', [CriptoController::class, 'detalle'])->where('id', '[0-9]+');
    Route::post('/operaciones', [CriptoController::class, 'crear']);
    Route::post('/operaciones/{id}/confirmar-cobro', [CriptoController::class, 'confirmarCobro'])->where('id', '[0-9]+');
    Route::post('/operaciones/{id}/pagar-bob', [CriptoController::class, 'pagarBob'])->where('id', '[0-9]+');
    Route::post('/operaciones/{id}/cancelar', [CriptoController::class, 'cancelar'])->where('id', '[0-9]+');
    Route::post('/operaciones/{id}/confirmar-recepcion', [CriptoController::class, 'confirmarRecepcion'])->where('id', '[0-9]+');
});

// En esta primera versión solo el rol ADMIN puede actuar como Tesorería.
// Se limita explícitamente hasta que se asigne el rol real de Tesorería.
Route::prefix('cajero/cripto')->middleware(['jwt', 'role:2'])->group(function () {
    Route::get('/pay/operaciones-pendientes', [CriptoController::class, 'binancePayPendingOperations']);
    Route::post('/pay/vincular-manual', [CriptoController::class, 'vincularBinancePayManual']);
    Route::post('/configuracion/binance-pay-qr', [CriptoController::class, 'guardarBinancePayQr']);
    Route::post('/activos', [CriptoController::class, 'guardarActivo']);
    Route::post('/activos/{id}/ajuste', [CriptoController::class, 'ajustarInventario'])->where('id', '[0-9]+');
    Route::post('/operaciones/{id}/confirmar-envio', [CriptoController::class, 'confirmarEnvio'])->where('id', '[0-9]+');
    Route::post('/operaciones/{id}/rechazar', [CriptoController::class, 'rechazar'])->where('id', '[0-9]+');
});
