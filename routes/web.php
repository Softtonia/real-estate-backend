<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ResetPasswordController;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/clear', function() {
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('view:clear');
    Artisan::call('route:clear');
    return "Cache is cleared";
});

Route::get('/link', function() {
    Artisan::call('storage:link');
    return "Storage link is created";
});

Route::get('/uploads/{path}', function (string $path) {
    $path = trim($path, '/');

    if (
        str_contains($path, '..') ||
        str_starts_with($path, '/') ||
        str_starts_with($path, '\\')
    ) {
        abort(404);
    }

    if (!Storage::disk('public_uploads')->exists($path)) {
        abort(404);
    }

    $fullPath = Storage::disk('public_uploads')->path($path);

    if (!is_file($fullPath)) {
        abort(404);
    }

    return response()->file($fullPath, [
        'Cache-Control' => 'public, max-age=2592000',
    ]);
})->where('path', '.*');

Route::get('/check-file', function() {
    $path = 'uploads/media_icons/1749013663_Scenic_views.png';
    return response()->json([
        'exists' => file_exists(public_path($path)),
        'path' => public_path($path),
        'url' => asset($path)
    ]);
});


