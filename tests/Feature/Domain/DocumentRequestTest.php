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

it('allows multiple document requests to have a null access token hash', function () {
    $requestA = DocumentRequest::factory()->create();
    $requestB = DocumentRequest::factory()->create();

    expect($requestA->access_token_hash)->toBeNull();
    expect($requestB->access_token_hash)->toBeNull();
});

it('generates a 40-character access token and stores only its hash', function () {
    $documentRequest = DocumentRequest::factory()->create();

    $token = $documentRequest->generateAccessToken();

    expect($token)->toHaveLength(40);
    expect($documentRequest->access_token_hash)->toBe(hash('sha256', $token));
    expect($documentRequest->access_token_hash)->not->toBe($token);
});

it('throws when generating an access token for a request that already has one', function () {
    $documentRequest = DocumentRequest::factory()->create();
    $documentRequest->generateAccessToken();

    $documentRequest->generateAccessToken();
})->throws(RuntimeException::class);

it('is not publicly accessible when never sent', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => null]);

    expect($documentRequest->isPubliclyAccessible())->toBeFalse();
});

it('is not publicly accessible when archived', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'status' => 'archived',
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeFalse();
});

it('is not publicly accessible when expired', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'expires_at' => now()->subMinute(),
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeFalse();
});

it('is publicly accessible exactly at the expiry boundary but not after', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'expires_at' => now()->addSecond(),
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeTrue();

    $this->travel(2)->seconds();

    expect($documentRequest->fresh()->isPubliclyAccessible())->toBeFalse();
});

it('is publicly accessible when sent, not archived, and not expired', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'expires_at' => null,
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeTrue();
});
