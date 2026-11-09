<?php

use App\Modules\UtilidadCambiaria\Controllers\UtilidadController;
use Illuminate\Support\Facades\Route;

Route::prefix('utilidad-cambiaria')->middleware(['jwt', 'role:1,2,3,5,7,8,9,10,12,13'])->group(function () {
    Route::get('/meta',     [UtilidadController::class, 'meta']);
    Route::get('/{vista}',  [UtilidadController::class, 'show']);
});
