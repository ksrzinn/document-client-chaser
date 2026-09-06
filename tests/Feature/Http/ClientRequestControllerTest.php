<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;

beforeEach(function () {
    RateLimiter::clear('client-request');
});

function makePubliclyAccessibleRequest(array $overrides = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['name' => 'Jane Client', 'email' => 'jane@example.com']);

    return DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'draft',
        'sent_at' => now(),
        'message' => 'Please send your documents',
        'due_at' => now()->addWeek(),
        'expires_at' => now()->addMonth(),
    ], $overrides));
}

it('allows a valid token to access its publicly-accessible document request', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Bank statement']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'ID']);
    $token = $documentRequest->generateAccessToken();

    $response = $this->get("/request/{$token}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Public/DocumentRequest')
        ->where('documentRequest.client_name', 'Jane Client')
        ->where('documentRequest.message', 'Please send your documents')
        ->where('documentRequest.items.0.name', 'Bank statement')
        ->where('documentRequest.items.1.name', 'ID')
        ->has('documentRequest.due_at')
        ->has('documentRequest.expires_at')
    );
});

it('does not expose internal or cross-tenant fields on the public page', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $token = $documentRequest->generateAccessToken();

    $response = $this->get("/request/{$token}");

    $props = json_decode($response->getContent(), true)['props']['documentRequest'] ?? null;

    // Fallback for non-JSON (initial HTML) responses: force an Inertia XHR request instead.
    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])->get("/request/{$token}");
    $props = $response->json('props.documentRequest');

    expect($props)->not->toHaveKeys(['id', 'user_id', 'client_id', 'access_token_hash']);
    expect($props)->not->toHaveKey('client');
});

it('rejects an unknown token with a 404', function () {
    $this->get('/request/'.str_repeat('a', 40))->assertNotFound();
});

it('rejects an empty token with a 404', function () {
    $this->get('/request/')->assertNotFound();
});

it('rejects a numeric document request id used as a token', function () {
    $documentRequest = makePubliclyAccessibleRequest();

    $this->get("/request/{$documentRequest->id}")->assertNotFound();
});

it('rejects a token for a request that was never sent', function () {
    $documentRequest = makePubliclyAccessibleRequest(['sent_at' => null]);
    $token = $documentRequest->generateAccessToken();

    $this->get("/request/{$token}")->assertNotFound();
});

it('rejects a token for an archived request', function () {
    $documentRequest = makePubliclyAccessibleRequest(['status' => 'archived']);
    $token = $documentRequest->generateAccessToken();

    $this->get("/request/{$token}")->assertNotFound();
});

it('rejects a token for an expired request', function () {
    $documentRequest = makePubliclyAccessibleRequest(['expires_at' => now()->subMinute()]);
    $token = $documentRequest->generateAccessToken();

    $this->get("/request/{$token}")->assertNotFound();
});

it('does not let a token for one request access another request', function () {
    $requestA = makePubliclyAccessibleRequest();
    $tokenA = $requestA->generateAccessToken();
    $requestB = makePubliclyAccessibleRequest();
    $requestB->generateAccessToken();

    $response = $this->get("/request/{$tokenA}");

    $response->assertInertia(fn ($page) => $page->where('documentRequest.message', $requestA->message));
});

it('serves the public request page without any authenticated session', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $token = $documentRequest->generateAccessToken();

    $this->get("/request/{$token}")->assertOk();
    $this->assertGuest();
});

it('rate limits the public endpoint after repeated requests', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $token = $documentRequest->generateAccessToken();

    for ($i = 0; $i < 30; $i++) {
        $this->get("/request/{$token}")->assertOk();
    }

    $this->get("/request/{$token}")->assertStatus(429);
});

it('includes item id, status, and the plaintext token in the public payload', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Bank statement', 'status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $response = $this->get("/request/{$token}");

    $response->assertInertia(fn ($page) => $page
        ->where('token', $token)
        ->where('documentRequest.items.0.id', $item->id)
        ->where('documentRequest.items.0.status', 'requested')
    );
});

it('sends a no-referrer policy on the public request page', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $token = $documentRequest->generateAccessToken();

    $this->get("/request/{$token}")->assertHeader('Referrer-Policy', 'no-referrer');
});

it('exposes the upload size limit and allowed extensions to the client portal', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $token = $documentRequest->generateAccessToken();

    $response = $this->get("/request/{$token}");

    $response->assertInertia(fn ($page) => $page
        ->component('Public/DocumentRequest')
        ->where('maxSizeMb', config('uploads.max_size_kb') / 1024)
        ->where('allowedExtensions', 'pdf, jpg, jpeg, png, docx, xlsx')
    );
});

it('exposes completed status on the public payload once the request is complete', function () {
    $documentRequest = makePubliclyAccessibleRequest(['status' => 'completed', 'completed_at' => now()]);
    $token = $documentRequest->generateAccessToken();

    $response = $this->get("/request/{$token}");

    $response->assertInertia(fn ($page) => $page->where('documentRequest.status', 'completed'));
});
