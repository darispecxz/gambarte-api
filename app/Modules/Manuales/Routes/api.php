<?php

use App\Modules\Manuales\Controllers\ManualLecturaController;
use Illuminate\Support\Facades\Route;

Route::prefix('manuales')->middleware('jwt')->group(function () {
    Route::get('/videos/{manualKey}/info', [ManualLecturaController::class, 'videoInfo'])->where('manualKey', '[a-z0-9_]+');
    Route::get('/videos/{manualKey}', [ManualLecturaController::class, 'video'])->where('manualKey', '[a-z0-9_]+');
    Route::post('/videos', [ManualLecturaController::class, 'subirVideo'])->middleware('role:2');
    Route::get('/lecturas/mias', [ManualLecturaController::class, 'mias']);
    Route::post('/lecturas', [ManualLecturaController::class, 'marcarLeido']);
    Route::middleware('role:2')->get('/lecturas/seguimiento', [ManualLecturaController::class, 'seguimiento']);
});
