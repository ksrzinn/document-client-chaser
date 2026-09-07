<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

// --- Model: currentAccessLink() ---

it('has no access link before a token is generated', function () {
    $documentRequest = DocumentRequest::factory()->create();

    expect($documentRequest->currentAccessLink())->toBeNull();
});

it('derives the access link from the persisted encrypted token after generating one', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => now()]);
    $token = $documentRequest->generateAccessToken();

    expect($documentRequest->currentAccessLink())->toBe(route('public.document-request.show', $token));
});

it('returns null once the request is archived, even though the token still exists', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => now()]);
    $documentRequest->generateAccessToken();
    $documentRequest->status = 'archived';
    $documentRequest->save();

    expect($documentRequest->currentAccessLink())->toBeNull();
});

it('returns null once the request has expired, even though the token still exists', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now()->subDays(5),
        'expires_at' => now()->subDay(),
    ]);
    $documentRequest->generateAccessToken();

    expect($documentRequest->currentAccessLink())->toBeNull();
});

it('does not change the token or expiration state when the access link is read repeatedly', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => now()]);
    $documentRequest->generateAccessToken();
    $documentRequest->refresh();

    $hashBefore = $documentRequest->access_token_hash;
    $expiresBefore = $documentRequest->expires_at;

    $linkOne = $documentRequest->currentAccessLink();
    $linkTwo = $documentRequest->currentAccessLink();

    expect($linkOne)->toBe($linkTwo);
    expect($documentRequest->fresh()->access_token_hash)->toBe($hashBefore);
    expect($documentRequest->fresh()->expires_at)->toEqual($expiresBefore);
});

// --- Controller: show() payload ---

it('does not include an access link in the show payload before a request is sent', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertInertia(fn ($page) => $page->where('documentRequest.access_link', null));
});

it('includes the correct access link in the show payload after sending', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertInertia(function ($page) use ($documentRequest) {
        $link = $page->toArray()['props']['documentRequest']['access_link'];

        expect($link)->toContain('/request/');

        preg_match('#/request/([A-Za-z0-9]{40})#', $link, $matches);
        expect(DocumentRequest::findPubliclyAccessible($matches[1])?->is($documentRequest))->toBeTrue();
    });
});

it('does not regenerate or otherwise change the token when show() is called repeatedly', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));
    $documentRequest->refresh();
    $hashAfterSend = $documentRequest->access_token_hash;

    $firstResponse = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));
    $secondResponse = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $firstLink = $firstResponse->viewData('page')['props']['documentRequest']['access_link'];
    $secondLink = $secondResponse->viewData('page')['props']['documentRequest']['access_link'];

    expect($firstLink)->toBe($secondLink);
    expect($documentRequest->fresh()->access_token_hash)->toBe($hashAfterSend);
});

it('does not include an access link once the request is archived', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));
    $documentRequest->status = 'archived';
    $documentRequest->save();

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertInertia(fn ($page) => $page->where('documentRequest.access_link', null));
});

it('does not include an access link once the request has expired', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));
    $documentRequest->update(['expires_at' => now()->subMinute()]);

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertInertia(fn ($page) => $page->where('documentRequest.access_link', null));
});
