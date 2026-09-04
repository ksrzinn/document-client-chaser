<?php

use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use Illuminate\Database\QueryException;

it('belongs to its request', function () {
    $request = DocumentRequest::factory()->create();
    $item = DocumentRequestItem::factory()->for($request)->create();

    expect($item->documentRequest->is($request))->toBeTrue();
    expect($request->items->first()->is($item))->toBeTrue();
});

it('rejects an item with a non-existent request', function () {
    expect(fn () => DocumentRequestItem::factory()->create(['document_request_id' => 999999]))
        ->toThrow(QueryException::class);
});

it('cascades delete when the owning request is deleted', function () {
    $request = DocumentRequest::factory()->create();
    $item = DocumentRequestItem::factory()->for($request)->create();

    $request->delete();

    expect(DocumentRequestItem::query()->find($item->id))->toBeNull();
});
