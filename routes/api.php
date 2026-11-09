<?php

use Illuminate\Support\Facades\Route;

// ── Salud ───────────────────────────────────────────────────
Route::get('/health', fn () => response()->json([
    'success' => true,
    'data'    => ['status' => 'ok', 'time' => date('c')],
    'message' => null,
    'errors'  => [],
]));
