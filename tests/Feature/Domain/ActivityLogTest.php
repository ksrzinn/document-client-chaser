<?php

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Database\QueryException;

it('belongs to a user with optional client and request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $request = DocumentRequest::factory()->for($user)->for($client)->create();

    $log = ActivityLog::factory()->for($user)->create([
        'client_id' => $client->id,
        'document_request_id' => $request->id,
        'event' => 'request_sent',
        'metadata' => ['channel' => 'email'],
    ]);

    expect($log->user->is($user))->toBeTrue();
    expect($log->client->is($client))->toBeTrue();
    expect($log->documentRequest->is($request))->toBeTrue();
    expect($log->metadata)->toBe(['channel' => 'email']);
});

it('allows a null client and document request', function () {
    $log = ActivityLog::factory()->create(['client_id' => null, 'document_request_id' => null]);

    expect($log->client)->toBeNull();
    expect($log->documentRequest)->toBeNull();
});

it('rejects a log with a non-existent user', function () {
    expect(fn () => ActivityLog::factory()->create(['user_id' => 999999]))
        ->toThrow(QueryException::class);
});
