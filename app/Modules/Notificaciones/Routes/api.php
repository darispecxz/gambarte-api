<?php

use App\Modules\Notificaciones\Controllers\NotificacionController;
use Illuminate\Support\Facades\Route;

Route::prefix('notificaciones')->middleware('jwt')->group(function () {
    Route::get('/',              [NotificacionController::class, 'listar']);
    Route::get('/no-leidas',    [NotificacionController::class, 'contarNoLeidas']);
    Route::post('/leer',        [NotificacionController::class, 'marcarLeida']);
    Route::post('/leer-todas',  [NotificacionController::class, 'marcarTodasLeidas']);
    Route::post('/generar',     [NotificacionController::class, 'generar']);
});
