<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

function cliniqo_resolve_file(string $relative): ?string {
    $candidates = [
        base_path($relative),
        base_path('../' . $relative),
        base_path('../frontend/' . $relative),
    ];
    foreach ($candidates as $cand) {
        if (file_exists($cand) && !is_dir($cand)) {
            return realpath($cand);
        }
    }
    return null;
}

function cliniqo_mime_type(string $file): string {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return match ($ext) {
        'html' => 'text/html; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'json' => 'application/json',
        default => 'text/plain; charset=UTF-8',
    };
}

Route::get('/', function (Request $request) {
    if ($request->wantsJson()) {
        return response()->json([
            'status' => 'operational',
            'service' => 'Cliniqo Medical API',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    $file = cliniqo_resolve_file('index.html');
    return $file ? response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']) : response()->json(['status' => 'operational']);
});

Route::get('/dashboard.html', function () {
    $file = cliniqo_resolve_file('dashboard.html');
    return $file ? response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']) : abort(404);
});

Route::get('/rendez-vous.html', function () {
    $file = cliniqo_resolve_file('rendez-vous.html');
    return $file ? response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']) : abort(404);
});

Route::get('/espace-patient.html', function () {
    $file = cliniqo_resolve_file('espace-patient.html');
    return $file ? response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']) : abort(404);
});

Route::get('/index.html', function () {
    $file = cliniqo_resolve_file('index.html');
    return $file ? response()->file($file, ['Content-Type' => 'text/html; charset=UTF-8']) : abort(404);
});

Route::get('/js/{file}', function ($file) {
    $clean = basename($file);
    $resolved = cliniqo_resolve_file("js/{$clean}");
    return $resolved ? response()->file($resolved, ['Content-Type' => 'application/javascript; charset=UTF-8']) : abort(404);
});

Route::get('/assets/{file}', function ($file) {
    $clean = basename($file);
    $resolved = cliniqo_resolve_file("assets/{$clean}");
    return $resolved ? response()->file($resolved, ['Content-Type' => cliniqo_mime_type($clean)]) : abort(404);
});

Route::get('/frontend/{path}', function ($path) {
    $resolved = cliniqo_resolve_file("frontend/{$path}") ?? cliniqo_resolve_file($path);
    return $resolved ? response()->file($resolved, ['Content-Type' => cliniqo_mime_type($resolved)]) : abort(404);
})->where('path', '.*');

