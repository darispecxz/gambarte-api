<?php

use App\Modules\Riesgos\Controllers\RisksController;
use Illuminate\Support\Facades\Route;

Route::prefix('risks')->middleware(['jwt', 'role:2,3,5,13'])->group(function () {
    Route::get('/',             [RisksController::class, 'index']);
    Route::get('/dashboard',    [RisksController::class, 'dashboard']);
    Route::get('/{slug}',       [RisksController::class, 'show']);
    Route::get('/{slug}/pdf',   [RisksController::class, 'pdf']);
});
