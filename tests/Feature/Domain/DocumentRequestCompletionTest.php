<?php

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;

function makeCompletableRequest(array $overrides = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    return DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'draft',
        'sent_at' => now(),
    ], $overrides));
}

it('is complete when every item is received', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    expect($documentRequest->isComplete())->toBeTrue();
});

it('is not complete when at least one item is still requested', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);

    expect($documentRequest->isComplete())->toBeFalse();
});

it('is not complete when it has no items', function () {
    $documentRequest = makeCompletableRequest();

    expect($documentRequest->isComplete())->toBeFalse();
});

it('marks a complete request as completed and logs the transition', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeTrue();
    expect($documentRequest->status)->toBe('completed');
    expect($documentRequest->completed_at)->not->toBeNull();
    expect($documentRequest->isDirty())->toBeFalse();

    $fresh = $documentRequest->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->completed_at)->not->toBeNull();

    expect(ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'request_completed')->count())->toBe(1);
});

it('does not complete a request that still has a pending item', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeFalse();
    expect($documentRequest->fresh()->status)->not->toBe('completed');
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(0);
});

it('does not complete a request with no items', function () {
    $documentRequest = makeCompletableRequest();

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeFalse();
    expect($documentRequest->fresh()->status)->not->toBe('completed');
});

it('does not reopen or relog an already-completed request', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $documentRequest->markCompletedIfComplete();
    $firstCompletedAt = $documentRequest->fresh()->completed_at;

    $secondResult = $documentRequest->markCompletedIfComplete();

    expect($secondResult)->toBeFalse();
    expect($documentRequest->fresh()->completed_at->equalTo($firstCompletedAt))->toBeTrue();
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(1);
});

it('does not complete an archived request even if all items are received', function () {
    $documentRequest = makeCompletableRequest(['status' => 'archived']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeFalse();
    expect($documentRequest->fresh()->status)->toBe('archived');
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(0);
});

it('only completes once when two independently loaded instances race to complete the same request', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $instanceA = DocumentRequest::find($documentRequest->id);
    $instanceB = DocumentRequest::find($documentRequest->id);

    $resultA = $instanceA->markCompletedIfComplete();
    $resultB = $instanceB->markCompletedIfComplete();

    expect($resultA)->toBeTrue();
    expect($resultB)->toBeFalse();
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(1);
    expect($documentRequest->fresh()->completed_at)->not->toBeNull();
});
