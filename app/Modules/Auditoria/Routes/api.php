<?php

use App\Modules\Auditoria\Controllers\LogsController;
use Illuminate\Support\Facades\Route;

Route::prefix('logs')->middleware(['jwt', 'role:2,3,10'])->group(function () {
    Route::get('/',               [LogsController::class, 'index']);
    Route::get('/excel/zip',      [LogsController::class, 'zip']);
    Route::get('/{code}',         [LogsController::class, 'show']);
    Route::get('/{code}/excel',   [LogsController::class, 'excel']);
});
