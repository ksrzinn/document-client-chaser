<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/environment-check');
});

Route::get('/environment-check', fn () => inertia('EnvironmentCheck'));

Route::get('/dashboard', function () {
    return inertia('Dashboard');
})->middleware('auth')->name('dashboard');

require __DIR__.'/auth.php';
