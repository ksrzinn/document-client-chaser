<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\Public\ClientRequestController;
use App\Http\Controllers\Public\UploadedDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/environment-check');
});

Route::get('/environment-check', fn () => inertia('EnvironmentCheck'));

Route::get('/request/{token}', [ClientRequestController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:client-request')
    ->name('public.document-request.show');

Route::post('/request/{token}/items/{item}/upload', [UploadedDocumentController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->whereNumber('item')
    ->middleware('throttle:client-upload')
    ->name('public.document-request.upload');

Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::resource('clients', ClientController::class)->except(['destroy'])->whereNumber('client');
    Route::post('clients/{client}/archive', [ClientController::class, 'archive'])
        ->whereNumber('client')
        ->name('clients.archive');

    Route::resource('document-requests', DocumentRequestController::class)
        ->except(['destroy'])
        ->whereNumber('document_request');
    Route::post('document-requests/{document_request}/archive', [DocumentRequestController::class, 'archive'])
        ->whereNumber('document_request')
        ->name('document-requests.archive');
    Route::post('document-requests/{document_request}/access-link', [DocumentRequestController::class, 'accessLink'])
        ->whereNumber('document_request')
        ->name('document-requests.access-link');
    Route::post('document-requests/{document_request}/send', [DocumentRequestController::class, 'send'])
        ->whereNumber('document_request')
        ->middleware('throttle:document-request-send')
        ->name('document-requests.send');
});

require __DIR__.'/auth.php';
