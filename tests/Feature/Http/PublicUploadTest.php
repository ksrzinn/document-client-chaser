<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('client-upload');
});

function makeUploadableRequest(array $overrides = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    return DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'draft',
        'sent_at' => now(),
    ], $overrides));
}

function uploadUrl(string $token, int $itemId): string
{
    return "/request/{$token}/items/{$itemId}/upload";
}

it('rate limits repeated upload attempts to the same request', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    for ($i = 0; $i < 10; $i++) {
        $this->post(uploadUrl($token, $item->id), [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);
    }

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ]);

    $response->assertStatus(429);
});
