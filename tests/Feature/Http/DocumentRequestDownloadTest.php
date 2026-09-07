<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
});

function makeDownloadableDocument(array $overrides = []): array
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $path = 'uploads/'.$documentRequest->id.'/'.Str::uuid();
    Storage::disk('local')->put($path, 'file contents here');

    $document = UploadedDocument::factory()
        ->for($user)->for($client)->for($documentRequest)->for($item, 'documentRequestItem')
        ->create(array_merge([
            'original_filename' => 'statement.pdf',
            'storage_path' => $path,
            'disk' => 'local',
            'mime_type' => 'application/pdf',
        ], $overrides));

    return [$user, $documentRequest, $document];
}

it('lets the owning user download their uploaded document', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    $response->assertHeader('content-disposition', 'attachment; filename=statement.pdf');
    expect($response->streamedContent())->toBe('file contents here');
});

it('returns the exact stored file contents', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();
    Storage::disk('local')->put($document->storage_path, 'exact bytes 123');

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    expect($response->streamedContent())->toBe('exact bytes 123');
});

it('uses the stored mime type for the response', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument(['mime_type' => 'image/png']);

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertHeader('content-type', 'image/png');
});

it('uses the original filename as the download filename', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument(['original_filename' => 'my report.pdf']);

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertHeader('content-disposition', 'attachment; filename="my report.pdf"');
});

it('redirects unauthenticated users to login', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();

    $this->get(route('document-requests.documents.download', [$documentRequest, $document]))
        ->assertRedirect(route('login'));
});

it('returns 404 when a user from another tenant tries to download the file', function () {
    [$owner, $documentRequest, $document] = makeDownloadableDocument();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]))
        ->assertNotFound();
});

it('returns 404 when the document request id in the url does not match the documents actual request', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();
    $client = Client::factory()->for($user)->create();
    $otherRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$otherRequest, $document]))
        ->assertNotFound();
});

it('returns 404 when the document id belongs to a different request entirely', function () {
    [$userA, $requestA] = makeDownloadableDocument();
    [$userB, $requestB, $documentB] = makeDownloadableDocument();

    $this->actingAs($userA)
        ->get(route('document-requests.documents.download', [$requestA, $documentB]))
        ->assertNotFound();
});

it('returns 404 when the physical file is missing from disk', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();
    Storage::disk('local')->delete($document->storage_path);

    $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]))
        ->assertNotFound();
});

it('lets the owner download from an archived request', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();
    $documentRequest->status = 'archived';
    $documentRequest->save();

    $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]))
        ->assertOk();
});

it('does not modify the uploaded document record on download', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();
    $before = $document->fresh();

    $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    expect($document->fresh()->toArray())->toBe($before->toArray());
});

it('does not modify the document request or its expiration state on download', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();
    $before = $documentRequest->fresh()->toArray();

    $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    expect($documentRequest->fresh()->toArray())->toBe($before);
});

it('never exposes the physical storage path in the response', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->not->toContain(storage_path());
    expect($response->headers->get('content-disposition'))->not->toContain($document->storage_path);
});

it('sets the nosniff content type options header', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument();

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertHeader('x-content-type-options', 'nosniff');
});

it('sanitizes a path-traversal-shaped original filename before it reaches the download header', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument([
        'original_filename' => '../../etc/passwd',
    ]);

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=passwd');
});

it('sanitizes a backslash-containing original filename before it reaches the download header', function () {
    [$user, $documentRequest, $document] = makeDownloadableDocument([
        'original_filename' => 'a\\b.pdf',
    ]);

    $response = $this->actingAs($user)
        ->get(route('document-requests.documents.download', [$documentRequest, $document]));

    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=b.pdf');
});
