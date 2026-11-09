<?php

use App\Modules\Bancos\Controllers\BankingController;
use App\Modules\Bancos\Controllers\BankingOpsController;
use Illuminate\Support\Facades\Route;

Route::prefix('banking')->middleware(['jwt'])->group(function () {
    // ── Operaciones de bancos del POS ──────────────────────────────────
    // Los cajeros necesitan operar traspasos; antes no tenían ningún acceso
    // a bancos (solo roles contables), así que la recepción se hacía por el
    // back-office legacy.
    Route::prefix('ops')->middleware('role:1,2,3,4,6,7,8,12')->group(function () {
        Route::get('/cuentas',            [BankingOpsController::class, 'cuentas']);
        Route::get('/agencias',           [BankingOpsController::class, 'agencias']);
        Route::get('/traspasos',          [BankingOpsController::class, 'traspasos']);
        Route::get('/traspasos/pendientes', [BankingOpsController::class, 'pendientes']);
        Route::post('/traspasos',         [BankingOpsController::class, 'registrar']);
        Route::post('/traspasos/{id}/aceptar',  [BankingOpsController::class, 'aceptar'])->where('id', '[0-9]+');
        Route::post('/traspasos/{id}/rechazar', [BankingOpsController::class, 'rechazar'])->where('id', '[0-9]+');
        Route::post('/traspasos/{id}/revertir', [BankingOpsController::class, 'revertir'])->where('id', '[0-9]+');

        Route::get('/depositos',  [BankingOpsController::class, 'depositos']);
        Route::post('/depositos', [BankingOpsController::class, 'deposito']);
        Route::get('/movimientos', [BankingOpsController::class, 'movimientos']);

        // ── Cheques ────────────────────────────────────────────────────
        Route::get('/cheques/catalogos',   [BankingOpsController::class, 'chequesCatalogos']);
        Route::get('/cheques/chequeras',   [BankingOpsController::class, 'chequeras']);
        Route::get('/cheques/agencia',     [BankingOpsController::class, 'chequesAgencia']);
        Route::get('/cheques',             [BankingOpsController::class, 'cheques']);
        Route::post('/cheques/emitir',     [BankingOpsController::class, 'emitirCheque']);
        Route::post('/cheques/enviar',     [BankingOpsController::class, 'enviarCheque']);
        Route::post('/cheques/cobrar',     [BankingOpsController::class, 'cobrarCheque']);
        Route::post('/cheques/anular',     [BankingOpsController::class, 'anularCheque']);
        Route::post('/cheques/revertir-anulacion', [BankingOpsController::class, 'revertirAnulacion']);

        Route::get('/cheques/envios',             [BankingOpsController::class, 'enviosPendientes']);
        Route::post('/cheques/envios/{id}/recibir',  [BankingOpsController::class, 'recibirCheque'])->where('id', '[0-9]+');
        Route::post('/cheques/envios/{id}/rechazar', [BankingOpsController::class, 'rechazarEnvio'])->where('id', '[0-9]+');

        // ── Apertura / cierre de banca ─────────────────────────────────
        Route::get('/banca/estado',  [BankingOpsController::class, 'estadoBanca']);
        Route::post('/banca/abrir',  [BankingOpsController::class, 'abrirBanca']);
        Route::post('/banca/cerrar', [BankingOpsController::class, 'cerrarBanca']);

        // ── Comprobantes adjuntos ──────────────────────────────────────
        Route::get('/comprobante', [BankingOpsController::class, 'descargarComprobante']);

        Route::post('/traspasos/{id}/comprobante',  [BankingOpsController::class, 'subirComprobanteTraspaso'])->where('id', '[0-9]+');
        Route::post('/depositos/{id}/comprobante',  [BankingOpsController::class, 'subirComprobanteDeposito'])->where('id', '[0-9]+');
        Route::post('/cheques/envios/{id}/comprobante', [BankingOpsController::class, 'subirComprobanteEnvio'])->where('id', '[0-9]+');

        Route::get('/cheques/adjuntos',   [BankingOpsController::class, 'adjuntosCheque']);
        Route::post('/cheques/imagen',    [BankingOpsController::class, 'subirImagenCheque']);

        // ── Conciliación por extractos bancarios ───────────────────────
        Route::get('/conciliacion/catalogos', [BankingOpsController::class, 'conciliacionCatalogos']);
        Route::post('/conciliacion/extracto', [BankingOpsController::class, 'conciliarExtracto']);
        Route::post('/conciliacion/registrar', [BankingOpsController::class, 'registrarConciliacion']);
    });

    Route::middleware('role:1,2,3,7,8,12')->group(function () {
        Route::get('/saldos', [BankingController::class, 'saldos']);
    });

    Route::middleware('role:2,3,7,8')->group(function () {
        Route::get('/entities',              [BankingController::class, 'entities']);
        Route::get('/accounts',              [BankingController::class, 'accounts']);
        Route::get('/movements',             [BankingController::class, 'movements']);
        Route::get('/conciliacion',          [BankingController::class, 'conciliacion']);
        Route::get('/daily-summary',         [BankingController::class, 'dailySummary']);
        Route::get('/conciliacion-detalle',  [BankingController::class, 'conciliacionDetalle']);
        Route::get('/movement-detail',       [BankingController::class, 'movementDetail']);
        Route::get('/transfers',             [BankingController::class, 'transfers']);
    });
});
