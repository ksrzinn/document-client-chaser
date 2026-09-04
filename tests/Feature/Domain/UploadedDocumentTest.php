<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Database\QueryException;

it('is associated with the expected tenant, client, and request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $request = DocumentRequest::factory()->for($user)->for($client)->create();
    $item = DocumentRequestItem::factory()->for($request)->create();

    $document = UploadedDocument::factory()
        ->for($user)
        ->for($client)
        ->for($request)
        ->for($item, 'documentRequestItem')
        ->create();

    expect($document->user->is($user))->toBeTrue();
    expect($document->client->is($client))->toBeTrue();
    expect($document->documentRequest->is($request))->toBeTrue();
    expect($document->documentRequestItem->is($item))->toBeTrue();
});

it('allows a null document request item', function () {
    $document = UploadedDocument::factory()->create(['document_request_item_id' => null]);

    expect($document->documentRequestItem)->toBeNull();
});

it('rejects an upload with a non-existent user', function () {
    expect(fn () => UploadedDocument::factory()->create(['user_id' => 999999]))
        ->toThrow(QueryException::class);
});

it('nullifies the item reference when the item is deleted, without deleting the upload', function () {
    $item = DocumentRequestItem::factory()->create();
    $document = UploadedDocument::factory()->create(['document_request_item_id' => $item->id]);

    $item->delete();

    expect($document->fresh()->document_request_item_id)->toBeNull();
    expect(UploadedDocument::query()->find($document->id))->not->toBeNull();
});
