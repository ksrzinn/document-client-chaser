<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\QueryException;

it('belongs to a user', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    expect($client->user->is($user))->toBeTrue();
    expect($user->clients->first()->is($client))->toBeTrue();
});

it('can be archived without deleting the record', function () {
    $client = Client::factory()->create();

    $client->update(['archived_at' => now()]);

    expect(Client::query()->find($client->id))->not->toBeNull();
    expect($client->fresh()->archived_at)->not->toBeNull();
});

it('rejects a client with a non-existent user', function () {
    expect(fn () => Client::factory()->create(['user_id' => 999999]))
        ->toThrow(QueryException::class);
});

it('cascades delete when the owning user is deleted', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $user->delete();

    expect(Client::query()->find($client->id))->toBeNull();
});
