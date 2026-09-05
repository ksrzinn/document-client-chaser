<?php

use App\Models\Client;
use App\Models\DocumentRequest;
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
