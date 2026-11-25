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

Route::prefix('notificaciones/whatsapp')->middleware(['jwt','role:1,2,4,6','throttle:20,1'])->group(function () {
    Route::get('/availability', [\App\Modules\Notificaciones\WhatsApp\DocumentNotificationController::class,'availability']);
    Route::post('/preview', [\App\Modules\Notificaciones\WhatsApp\DocumentNotificationController::class,'preview']);
    Route::post('/send', [\App\Modules\Notificaciones\WhatsApp\DocumentNotificationController::class,'send']);
});
