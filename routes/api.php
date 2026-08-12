<?php

use App\Http\Controllers\Api\ContactIngestController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'success' => true,
    'message' => 'ok',
    'data' => ['time' => now()->toIso8601String()],
]));

Route::middleware('api.auth')->group(function () {
    Route::post('/contacts', [ContactIngestController::class, 'store']);
});
