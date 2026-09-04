<?php

use App\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/environment-check');
});

Route::get('/environment-check', fn () => inertia('EnvironmentCheck'));

Route::get('/dashboard', function () {
    return inertia('Dashboard');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('clients', ClientController::class)->except(['destroy']);
    Route::post('clients/{client}/archive', [ClientController::class, 'archive'])
        ->name('clients.archive');
});

require __DIR__.'/auth.php';
