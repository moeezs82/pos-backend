<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Lightweight health-check used by the Flutter app to detect when the
// embedded FrankenPHP backend has finished booting. No auth, no DB query.
Route::get('/up', function () {
    return response()->json(['status' => 'ok']);
});
