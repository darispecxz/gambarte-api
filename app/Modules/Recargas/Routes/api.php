<?php

use App\Modules\Recargas\Controllers\RecargasController;
use Illuminate\Support\Facades\Route;

Route::prefix('cajero/recargas')->middleware(['jwt', 'role:1,2,4,6'])->group(function () {
    Route::get('/modo', [RecargasController::class, 'modo']);
    Route::get('/pendientes', [RecargasController::class, 'pendientes']);
    Route::get('/realizadas', [RecargasController::class, 'realizadas']);
    Route::get('/lineas', [RecargasController::class, 'lineas']);
    Route::post('/registrar-y-confirmar', [RecargasController::class, 'registrarYConfirmar']);
    Route::post('/{id}/reservar', [RecargasController::class, 'reservar'])->where('id', '[0-9]+');
    Route::post('/{id}/confirmar', [RecargasController::class, 'confirmar'])->where('id', '[0-9]+');
    Route::post('/{id}/liberar', [RecargasController::class, 'liberar'])->where('id', '[0-9]+');
});

Route::prefix('cajero/recargas')->middleware(['jwt', 'role:2'])->group(function () {
    Route::post('/uat/reiniciar', [RecargasController::class, 'reiniciarUat']);
});
