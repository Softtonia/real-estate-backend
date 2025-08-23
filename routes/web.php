<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ResetPasswordController;
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



Route::get('/check-file', function() {
    $path = 'uploads/media_icons/1749013663_Scenic_views.png';
    return response()->json([
        'exists' => file_exists(public_path($path)),
        'path' => public_path($path),
        'url' => asset($path)
    ]);
});
