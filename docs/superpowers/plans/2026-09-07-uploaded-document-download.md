# Uploaded Document Download Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authenticated business user download a client-uploaded document from the Document Request detail page, securely and tenant-scoped.

**Architecture:** Add one `download` action to the existing `DocumentRequestController` (matches its `archive`/`accessLink`/`send` idiom of resolving via `$request->user()->documentRequests()`), one new GET route in the existing `auth` middleware group, and a download link per uploaded-file row in `DocumentRequests/Show.vue`. The file is served via `Storage::disk($document->disk)->download()`, which streams through Flysystem — never touches `storage_path()` or `Storage::url()`.

**Tech Stack:** Laravel 13, Pest, Inertia + Vue3.

**Spec:** User's task instructions in this conversation (no separate spec file — full requirements are reproduced in Global Constraints below).

## Global Constraints

- Route must sit inside the existing `auth` middleware group in `routes/web.php`.
- UploadedDocument must be resolved through `$documentRequest->uploadedDocuments()->findOrFail($id)` — never `UploadedDocument::findOrFail()` directly — so an ID cannot cross tenant/request boundaries.
- Never expose `storage_path()`, never use `Storage::url()`, never create a public symlink.
- Use the DB-stored `original_filename` for the download name and DB-stored `mime_type` for `Content-Type`.
- 404 if the DB row's physical file is missing on disk — required because the `local` disk is configured with `throw => false`, so a missing file fails silently instead of raising.
- Downloading must not mutate `UploadedDocument`, `DocumentRequestItem`, or `DocumentRequest` state.
- Do not change upload behavior, upload validation, secure-link generation/regeneration, expiration logic, status logic, or any other unrelated `Show.vue` behavior.
- Add `X-Content-Type-Options: nosniff` — `mime_type` originates from a client-supplied file at upload time.
- Sanitize `original_filename` with `basename()` before handing it to the `Content-Disposition` header — old rows are untrusted, and a `/` or `\` in the name would throw inside Symfony's `HeaderUtils::makeDisposition`.

---

### Task 1: Backend route + controller action + feature tests

**Files:**
- Modify: `routes/web.php` (add one route inside the existing `Route::middleware('auth')->group(...)` block, after the `access-link` route)
- Modify: `app/Http/Controllers/DocumentRequestController.php` (add `downloadDocument` method + two `use` imports)
- Create: `tests/Feature/Http/DocumentRequestDownloadTest.php`

**Interfaces:**
- Produces: route name `document-requests.documents.download`, accepting `[documentRequest, document]` route params (both `whereNumber`), e.g. `route('document-requests.documents.download', [$documentRequest->id, $document->id])`. Consumed by Task 2 (frontend).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/DocumentRequestDownloadTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function makeDownloadableDocument(array $overrides = []): array
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $path = 'uploads/'.$documentRequest->id.'/'.\Illuminate\Support\Str::uuid();
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from the worktree root, via the isolated app container):
```bash
docker compose -p cdc-uploaded-download run --rm --no-deps app php artisan test --filter=DocumentRequestDownloadTest
```
Expected: FAIL — route `document-requests.documents.download` does not exist (`RouteNotFoundException`).

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the existing `Route::middleware('auth')->group(function () { ... })` block, immediately after the `document-requests.access-link` route:

```php
    Route::get('document-requests/{document_request}/documents/{document}/download', [DocumentRequestController::class, 'downloadDocument'])
        ->whereNumber('document_request')
        ->whereNumber('document')
        ->name('document-requests.documents.download');
```

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/DocumentRequestController.php`, add these imports at the top alongside the existing ones:

```php
use App\Models\UploadedDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
```

(`Rule`, `DB`, `Mail`, `Inertia`, `Response` etc. already exist — only add the three above if not already present; `DB` and the rest stay as-is.)

Add this method, placed after `accessLink` and before `send` (or anywhere else in the class — order doesn't matter functionally, but grouping it near the other request-scoped actions matches the file's existing organization):

```php
    public function downloadDocument(Request $request, string $documentRequest, string $document): StreamedResponse
    {
        $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

        /** @var UploadedDocument $document */
        $document = $documentRequest->uploadedDocuments()->findOrFail($document);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->storage_path), 404);

        return $disk->download(
            $document->storage_path,
            basename($document->original_filename),
            [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
docker compose -p cdc-uploaded-download run --rm --no-deps app php artisan test --filter=DocumentRequestDownloadTest
```
Expected: PASS, all 12 tests.

- [ ] **Step 6: Run the full test suite to check for regressions**

```bash
docker compose -p cdc-uploaded-download run --rm --no-deps app php artisan test
```
Expected: PASS, no regressions (234 previously-passing tests + 12 new = 246).

- [ ] **Step 7: Run Pint**

```bash
docker compose -p cdc-uploaded-download run --rm --no-deps app vendor/bin/pint --dirty
```
Expected: no style violations (or auto-fixed cleanly).

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/DocumentRequestController.php tests/Feature/Http/DocumentRequestDownloadTest.php
git commit -m "feat: add secure download endpoint for uploaded documents

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01YJzMqLkkxEKvsQPcHfFo5v"
```

---

### Task 2: Frontend download action in Show.vue

**Files:**
- Modify: `resources/js/Pages/DocumentRequests/Show.vue` (the per-document row inside the requested-documents list, around line 178-182)

**Interfaces:**
- Consumes: route `document-requests.documents.download` from Task 1, called as `route('document-requests.documents.download', [documentRequest.id, document.id])`. `document.id` is already present in the existing show payload (`DocumentRequestController@show` already maps `'id' => $document->id`) — no backend payload change needed.

- [ ] **Step 1: Add the download link to the document row**

In `resources/js/Pages/DocumentRequests/Show.vue`, replace the existing document row block (lines 178-182):

```html
                        <div v-for="document in item.documents" :key="document.id" class="ml-9 mt-2.5 flex items-center gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent-700)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="flex-none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                            <span class="min-w-0 flex-1 truncate text-sm">{{ document.original_filename }}</span>
                            <span class="flex-none text-xs text-steel-600">{{ Math.round(document.size / 1024) }} KB</span>
                        </div>
```

with:

```html
                        <div v-for="document in item.documents" :key="document.id" class="ml-9 mt-2.5 flex items-center gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent-700)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="flex-none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                            <span class="min-w-0 flex-1 truncate text-sm">{{ document.original_filename }}</span>
                            <span class="flex-none text-xs text-steel-600">{{ Math.round(document.size / 1024) }} KB</span>
                            <a
                                :href="route('document-requests.documents.download', [documentRequest.id, document.id])"
                                class="flex-none inline-flex items-center gap-1 rounded-control px-2 py-1 text-xs font-medium text-accent-700 hover:bg-white hover:text-accent-900"
                                aria-label="`Download ${document.original_filename}`"
                            >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" /></svg>
                                Download
                            </a>
                        </div>
```

(This is a plain `<a>` tag, not an Inertia `<Link>` or `router.get()` — the download response is a `StreamedResponse` with `Content-Disposition: attachment`, which only works as a normal browser navigation, not an Inertia XHR request.)

- [ ] **Step 2: Verify the production build compiles**

```bash
docker compose -p cdc-uploaded-download run --rm --no-deps app npm run build
```
Expected: build succeeds with no errors.

- [ ] **Step 3: Manually verify in the browser**

Start the dev stack, log in as a business user with a document request that has an uploaded document, open its show page, click "Download" on an uploaded document row, confirm the browser downloads the file with the correct original filename and the file opens correctly.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/DocumentRequests/Show.vue
git commit -m "feat: add download action to uploaded documents in Show.vue

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01YJzMqLkkxEKvsQPcHfFo5v"
```

---

## Self-Review Notes

- **Spec coverage:** route/controller/tenant-scoping/disk-only access/404s/mime/filename/no-mutation → Task 1. UI download action → Task 2. Upload flow, secure-link generation, expiration, status logic, DB schema — untouched, no task modifies them. `X-Content-Type-Options: nosniff` and `basename()` sanitization are in the Task 1 Step 4 code.
- **Placeholder scan:** no TBD/TODO; all steps have literal code.
- **Type consistency:** `downloadDocument(Request $request, string $documentRequest, string $document): StreamedResponse` matches the route binding (`{document_request}`, `{document}` as route params, string until resolved via `findOrFail`) and the frontend call `route('document-requests.documents.download', [documentRequest.id, document.id])`.
