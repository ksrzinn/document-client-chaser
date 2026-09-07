<?php

use App\Mail\DocumentRequestSent;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

// --- Authorization / isolation ---

it('lets an authenticated user send their own request', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $response->assertRedirect();
    $documentRequest->refresh();
    expect($documentRequest->sent_at)->not->toBeNull();
    Mail::assertQueued(DocumentRequestSent::class, fn ($mail) => $mail->hasTo('client@example.com'));
});

it('flashes the secure link back to the sender so it can be copied without a second request', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $response->assertSessionHas('accessLink');
    $link = session('accessLink');
    expect($link)->toContain('/request/');

    // The flashed link must actually resolve to this same request, unauthenticated.
    $this->get($link)->assertOk();
});

it('does not let a user send another tenant\'s request', function () {
    Mail::fake();

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $client = Client::factory()->for($owner)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($owner)->for($client)->create();

    $this->actingAs($otherUser)->post(route('document-requests.send', $documentRequest))
        ->assertNotFound();

    Mail::assertNothingQueued();
});

it('redirects unauthenticated users away from the send route', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->post(route('document-requests.send', $documentRequest))
        ->assertRedirect(route('login'));
});

it('does not let an archived request be sent', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(['status' => 'archived']);

    $response = $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Archived requests cannot be sent.');
    expect($documentRequest->fresh()->sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('returns 404 for a non-numeric document request id on send', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/document-requests/not-a-number/send')
        ->assertNotFound();
});

// --- Validation / abuse ---

it('does not let a request be sent when the client has no email', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => '']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'The client does not have a valid email address.');
    Mail::assertNothingQueued();
});

// --- Token handling ---

it('redirects back with a flash success message after sending a request', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Request sent to the client.');
});

it('generates an access token when sending a request that has none', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    expect($documentRequest->access_token_hash)->toBeNull();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    expect($documentRequest->fresh()->access_token_hash)->not->toBeNull();
});

it('rotates an existing access token when sending, since the raw token is never persisted', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $documentRequest->generateAccessToken();
    $existingHash = $documentRequest->access_token_hash;

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    expect($documentRequest->fresh()->access_token_hash)->not->toBe($existingHash);
});

it('includes a working link to the correct public endpoint using the freshly generated token', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    Mail::assertQueued(DocumentRequestSent::class, function ($mail) use ($documentRequest) {
        $rendered = $mail->render();

        preg_match('#/request/([A-Za-z0-9]{40})#', $rendered, $matches);

        expect($matches)->toHaveKey(1);
        expect(DocumentRequest::findPubliclyAccessible($matches[1])?->is($documentRequest))->toBeTrue();

        return true;
    });
});

// --- Resend ---

it('allows sending an already-sent request again, rotating the token but preserving the original sent_at', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));
    $documentRequest->refresh();
    $firstSentAt = $documentRequest->sent_at;
    $hashAfterFirstSend = $documentRequest->access_token_hash;

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest))
        ->assertRedirect();

    $documentRequest->refresh();
    expect($documentRequest->sent_at->equalTo($firstSentAt))->toBeTrue();
    expect($documentRequest->access_token_hash)->not->toBe($hashAfterFirstSend);
    Mail::assertQueued(DocumentRequestSent::class, 2);
});

// --- Mail content ---

it('sends mail with correct subject, business name, client name, message, due date and link', function () {
    Mail::fake();

    $user = User::factory()->create(['name' => 'Acme Bookkeeping']);
    $client = Client::factory()->for($user)->create(['name' => 'John Smith', 'email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'message' => 'Please upload your documents.',
        'due_at' => '2026-10-15',
    ]);

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    Mail::assertQueued(DocumentRequestSent::class, function ($mail) {
        $rendered = $mail->render();
        expect($mail->envelope()->subject)->toContain('Acme Bookkeeping');
        expect($rendered)->toContain('John Smith');
        expect($rendered)->toContain('Please upload your documents.');
        expect($rendered)->toContain('2026-10-15');

        return true;
    });
});

it('escapes malicious content in the client message and client/business name', function () {
    Mail::fake();

    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $client = Client::factory()->for($user)->create(['name' => 'John Smith', 'email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'message' => '<img src=x onerror=alert(1)>',
    ]);

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    Mail::assertQueued(DocumentRequestSent::class, function ($mail) {
        $rendered = $mail->render();

        expect($rendered)->not->toContain('<script>alert(1)</script>');
        expect($rendered)->not->toContain('<img src=x onerror=alert(1)>');

        return true;
    });
});

it('does not include a due date section when the request has none', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(['due_at' => null]);

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    Mail::assertQueued(DocumentRequestSent::class, function ($mail) {
        $rendered = $mail->render();

        expect($rendered)->not->toContain('Please upload the requested documents by');

        return true;
    });
});

// --- Queue ---

it('dispatches the mail as queueable rather than sending synchronously', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    Mail::assertQueued(DocumentRequestSent::class);
    expect(new DocumentRequestSent(
        businessName: 'Acme',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
    ))->toBeInstanceOf(ShouldQueue::class);
});

// --- Activity log ---

it('logs a request_sent activity without leaking the token', function () {
    Mail::fake();

    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)->post(route('document-requests.send', $documentRequest));

    $log = ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'request_sent')
        ->first();

    expect($log)->not->toBeNull();
    expect(json_encode($log->metadata))->not->toContain('access_token_hash');

    $documentRequest->refresh();
    expect(json_encode($log->metadata))->not->toContain(substr($documentRequest->access_token_hash, 0, 10));
});
