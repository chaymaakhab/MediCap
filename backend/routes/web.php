<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'operational',
        'service' => 'Cliniqo Medical API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});
