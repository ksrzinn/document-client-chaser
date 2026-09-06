<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\UploadedDocument;
use App\Models\User;

// --- Route constraints ---

it('returns 404 for a non-numeric document request id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/document-requests/not-a-number')
        ->assertNotFound();
});

// --- Authentication ---

it('redirects unauthenticated users away from every document request route', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->get(route('document-requests.index'))->assertRedirect(route('login'));
    $this->get(route('document-requests.create'))->assertRedirect(route('login'));
    $this->post(route('document-requests.store'), [])->assertRedirect(route('login'));
    $this->get(route('document-requests.show', $documentRequest))->assertRedirect(route('login'));
    $this->get(route('document-requests.edit', $documentRequest))->assertRedirect(route('login'));
    $this->put(route('document-requests.update', $documentRequest), [])->assertRedirect(route('login'));
    $this->post(route('document-requests.archive', $documentRequest))->assertRedirect(route('login'));
});

// --- Create ---

it('lets an authenticated user create a request for their own client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->post(route('document-requests.store'), [
        'client_id' => $client->id,
        'message' => 'Please send your documents',
        'due_at' => '2026-10-01',
        'expires_at' => '2026-11-01',
        'items' => [
            ['name' => 'Bank statement'],
            ['name' => 'ID'],
        ],
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('document_requests', [
        'user_id' => $user->id,
        'client_id' => $client->id,
        'status' => 'draft',
        'message' => 'Please send your documents',
    ]);
    $documentRequest = DocumentRequest::where('user_id', $user->id)->firstOrFail();
    expect($documentRequest->items)->toHaveCount(2);
    expect($documentRequest->items->pluck('name')->all())->toBe(['Bank statement', 'ID']);
});

it('ignores a spoofed user_id and status and assigns the request to the authenticated user as draft', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $client = Client::factory()->for($userA)->create();

    $this->actingAs($userA)->post(route('document-requests.store'), [
        'client_id' => $client->id,
        'user_id' => $userB->id,
        'status' => 'completed',
        'items' => [['name' => 'Invoice']],
    ]);

    $this->assertDatabaseHas('document_requests', [
        'client_id' => $client->id,
        'user_id' => $userA->id,
        'status' => 'draft',
    ]);
});

it('rejects creating a request using another tenants client id', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $response = $this->actingAs($userA)->post(route('document-requests.store'), [
        'client_id' => $clientB->id,
        'items' => [['name' => 'Invoice']],
    ]);

    $response->assertInvalid(['client_id']);
    $this->assertDatabaseMissing('document_requests', ['client_id' => $clientB->id, 'user_id' => $userA->id]);
});

// --- Validation ---

it('rejects request creation with a missing client', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('document-requests.store'), [
        'items' => [['name' => 'Invoice']],
    ])->assertInvalid(['client_id']);
});

it('rejects request creation with an invalid client id', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('document-requests.store'), [
        'client_id' => 999999,
        'items' => [['name' => 'Invoice']],
    ])->assertInvalid(['client_id']);
});

it('rejects request creation with no items', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('document-requests.store'), [
        'client_id' => $client->id,
        'items' => [],
    ])->assertInvalid(['items']);
});

it('rejects request creation with an empty item name', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('document-requests.store'), [
        'client_id' => $client->id,
        'items' => [['name' => '']],
    ])->assertInvalid(['items.0.name']);
});

it('rejects request creation with an invalid due date', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('document-requests.store'), [
        'client_id' => $client->id,
        'due_at' => 'not-a-date',
        'items' => [['name' => 'Invoice']],
    ])->assertInvalid(['due_at']);
});

it('rejects request creation when expires_at is before due_at', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('document-requests.store'), [
        'client_id' => $client->id,
        'due_at' => '2026-11-01',
        'expires_at' => '2026-10-01',
        'items' => [['name' => 'Invoice']],
    ])->assertInvalid(['expires_at']);
});

// --- Index / tenant isolation ---

it('lists only the authenticated users document requests', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientA = Client::factory()->for($userA)->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestA = DocumentRequest::factory()->for($userA)->for($clientA)->create();
    DocumentRequest::factory()->for($userB)->for($clientB)->create();

    $response = $this->actingAs($userA)->get(route('document-requests.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Index')
        ->has('documentRequests.data', 1)
        ->where('documentRequests.data.0.id', $requestA->id)
    );
});

// --- Show / Edit ---

it('lets an authenticated user view their document request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(['due_at' => '2026-10-01']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Passport']);

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Show')
        ->where('documentRequest.id', $documentRequest->id)
        ->where('documentRequest.status', 'draft')
        ->where('documentRequest.items.0.name', 'Passport')
        ->where('documentRequest.due_at', '2026-10-01')
    );
});

it('includes display_status for each row in the index payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)
        ->create(['sent_at' => now(), 'expires_at' => null, 'status' => 'draft']);

    $response = $this->actingAs($user)->get(route('document-requests.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Index')
        ->where('documentRequests.data.0.display_status', 'awaiting_client'));
});

it('includes display_status in the show payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)
        ->create(['sent_at' => null, 'status' => 'draft']);

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Show')
        ->where('documentRequest.display_status', 'draft'));
});

it('lets an authenticated user edit their document request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(['due_at' => '2026-10-01']);

    $response = $this->actingAs($user)->get(route('document-requests.edit', $documentRequest));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Edit')
        ->where('documentRequest.id', $documentRequest->id)
        ->where('documentRequest.due_at', '2026-10-01')
    );
});

// --- Update ---

it('lets an owner update their request and sync items', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $keepItem = DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Old name']);
    $removeItem = DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Remove me']);

    $response = $this->actingAs($user)->put(route('document-requests.update', $documentRequest), [
        'client_id' => $client->id,
        'message' => 'Updated message',
        'items' => [
            ['id' => $keepItem->id, 'name' => 'Renamed'],
            ['name' => 'Brand new item'],
        ],
    ]);

    $response->assertRedirect();
    expect($documentRequest->fresh()->message)->toBe('Updated message');
    expect(DocumentRequestItem::find($keepItem->id)->name)->toBe('Renamed');
    expect(DocumentRequestItem::find($removeItem->id))->toBeNull();
    expect($documentRequest->fresh()->items)->toHaveCount(2);
});

it('cannot inject status or user_id through update', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->put(route('document-requests.update', $documentRequest), [
        'client_id' => $client->id,
        'status' => 'completed',
        'user_id' => User::factory()->create()->id,
        'items' => [['name' => 'Invoice']],
    ]);

    expect($documentRequest->fresh()->status)->toBe('draft');
    expect($documentRequest->fresh()->user_id)->toBe($user->id);
});

it('rejects updating a request to use another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientA = Client::factory()->for($userA)->create();
    $clientB = Client::factory()->for($userB)->create();
    $documentRequest = DocumentRequest::factory()->for($userA)->for($clientA)->create();

    $response = $this->actingAs($userA)->put(route('document-requests.update', $documentRequest), [
        'client_id' => $clientB->id,
        'items' => [['name' => 'Invoice']],
    ]);

    $response->assertInvalid(['client_id']);
    expect($documentRequest->fresh()->client_id)->toBe($clientA->id);
});

it('does not orphan an item that already has an uploaded document when removed from the update payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $uploadedItem = DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Bank statement']);
    $otherItem = DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'ID']);
    $document = UploadedDocument::factory()
        ->for($user)->for($client)->for($documentRequest)->for($uploadedItem, 'documentRequestItem')
        ->create();

    $response = $this->actingAs($user)->put(route('document-requests.update', $documentRequest), [
        'client_id' => $client->id,
        'items' => [
            ['id' => $otherItem->id, 'name' => 'ID'],
        ],
    ]);

    $response->assertRedirect();
    expect(DocumentRequestItem::query()->find($uploadedItem->id))->not->toBeNull();
    expect($document->fresh()->document_request_item_id)->toBe($uploadedItem->id);
});

it('rejects updating an item id that belongs to another request', function () {
    $userA = User::factory()->create();
    $clientA = Client::factory()->for($userA)->create();
    $requestA = DocumentRequest::factory()->for($userA)->for($clientA)->create();

    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestB = DocumentRequest::factory()->for($userB)->for($clientB)->create();
    $itemB = DocumentRequestItem::factory()->for($requestB)->create();

    $this->actingAs($userA)->put(route('document-requests.update', $requestA), [
        'client_id' => $clientA->id,
        'items' => [['id' => $itemB->id, 'name' => 'Hacked']],
    ])->assertNotFound();

    expect($itemB->fresh()->name)->not->toBe('Hacked');
});

// --- Tenant isolation ---

it('returns 404 when a user tries to view another tenants document request', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestB = DocumentRequest::factory()->for($userB)->for($clientB)->create();

    $this->actingAs($userA)->get(route('document-requests.show', $requestB))
        ->assertNotFound();
});

it('returns 404 when a user tries to edit another tenants document request', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestB = DocumentRequest::factory()->for($userB)->for($clientB)->create();

    $this->actingAs($userA)->get(route('document-requests.edit', $requestB))
        ->assertNotFound();
});

it('returns 404 when a user tries to update another tenants document request', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestB = DocumentRequest::factory()->for($userB)->for($clientB)->create();

    $this->actingAs($userA)->put(route('document-requests.update', $requestB), [
        'client_id' => $clientB->id,
        'items' => [['name' => 'Hacked']],
    ])->assertNotFound();
});

it('returns 404 when a user tries to archive another tenants document request', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestB = DocumentRequest::factory()->for($userB)->for($clientB)->create();

    $this->actingAs($userA)->post(route('document-requests.archive', $requestB))
        ->assertNotFound();

    expect($requestB->fresh()->status)->not->toBe('archived');
});

// --- Archive ---

it('lets an owner archive their document request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->post(route('document-requests.archive', $documentRequest));

    $response->assertRedirect();
    expect($documentRequest->fresh()->status)->toBe('archived');
});

it('does not delete the request when archived', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.archive', $documentRequest));

    $this->assertDatabaseHas('document_requests', ['id' => $documentRequest->id]);
});

it('is idempotent when archiving an already-archived request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.archive', $documentRequest));
    $this->actingAs($user)->post(route('document-requests.archive', $documentRequest))
        ->assertRedirect();

    expect($documentRequest->fresh()->status)->toBe('archived');
});

// --- Access link ---

it('lets the owning user generate a secure client link for their own request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->post(route('document-requests.access-link', $documentRequest));

    $response->assertRedirect();
    $response->assertSessionHas('accessLink');
    expect($documentRequest->fresh()->access_token_hash)->not->toBeNull();
});

it('returns the same link on repeated calls instead of rotating the token', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.access-link', $documentRequest));
    $firstHash = $documentRequest->fresh()->access_token_hash;

    $this->actingAs($user)->post(route('document-requests.access-link', $documentRequest));
    $secondHash = $documentRequest->fresh()->access_token_hash;

    expect($secondHash)->toBe($firstHash);
});

it('does not let a user generate an access link for another tenant\'s request', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $client = Client::factory()->for($owner)->create();
    $documentRequest = DocumentRequest::factory()->for($owner)->for($client)->create();

    $this->actingAs($otherUser)->post(route('document-requests.access-link', $documentRequest))
        ->assertNotFound();
});

it('redirects unauthenticated users away from the access link route', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->post(route('document-requests.access-link', $documentRequest))
        ->assertRedirect(route('login'));
});

it('shows each item\'s received documents with safe metadata only', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    $document = UploadedDocument::factory()
        ->for($user)->for($client)->for($documentRequest)->for($item, 'documentRequestItem')
        ->create([
            'original_filename' => 'statement.pdf',
            'storage_path' => 'uploads/1/secret-uuid',
            'mime_type' => 'application/pdf',
            'size' => 12345,
        ]);

    $response = $this->actingAs($user)->get("/document-requests/{$documentRequest->id}");

    $response->assertInertia(fn ($page) => $page
        ->where('documentRequest.items.0.status', 'received')
        ->where('documentRequest.items.0.documents.0.original_filename', 'statement.pdf')
        ->where('documentRequest.items.0.documents.0.mime_type', 'application/pdf')
        ->where('documentRequest.items.0.documents.0.size', 12345)
        ->has('documentRequest.items.0.documents.0.uploaded_at')
    );

    $response->assertDontSee($document->storage_path, false);
});

it('shows the completion timestamp once a request is completed', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get("/document-requests/{$documentRequest->id}");

    $response->assertInertia(fn ($page) => $page
        ->where('documentRequest.status', 'completed')
        ->has('documentRequest.completed_at')
    );
});
