<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Database\QueryException;

it('belongs to both a user and a client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $request = DocumentRequest::factory()->for($user)->for($client)->create();

    expect($request->user->is($user))->toBeTrue();
    expect($request->client->is($client))->toBeTrue();
});

it('rejects a request with a non-existent client', function () {
    expect(fn () => DocumentRequest::factory()->create(['client_id' => 999999]))
        ->toThrow(QueryException::class);
});

it('cascades delete when the owning client is deleted', function () {
    $client = Client::factory()->create();
    $request = DocumentRequest::factory()->for($client)->create();

    $client->delete();

    expect(DocumentRequest::query()->find($request->id))->toBeNull();
});
