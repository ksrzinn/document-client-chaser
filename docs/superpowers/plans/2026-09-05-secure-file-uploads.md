# Secure Client Document Uploads (Product Task 6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a client upload a document for each requested item through the existing public secure-token portal, store it privately with an opaque name, flip the item to `received`, and let the business see received/missing status — without any new architecture.

**Architecture:** Reuse the Task 5 access-token model end to end. A new `Public\UploadedDocumentController@store` resolves the `DocumentRequestItem` only through `DocumentRequest->items()` (never a bare `DocumentRequestItem::findOrFail`), validates the file server-side by sniffed content type (not client MIME/extension), stores it under an opaque UUID name on the existing private `local` disk, and creates the `UploadedDocument` row with every ownership field derived from the `DocumentRequest`, inside a DB transaction, deleting the stored file if the transaction fails.

**Tech Stack:** Laravel 13, Inertia (Vue 3, Options-free `<script setup>`), Pest, PostgreSQL, local private disk (`storage/app/private`), Laravel `RateLimiter`, Laravel `File::types()` validation rule.

**Spec:** Product Task 6 brief (given in-conversation; no separate spec file — `PRODUCT.md` §§ 12–15, 19–21, 25–26 is the authoritative product spec, `CLAUDE.md` is the architecture/security spec).

## Global Constraints

- Allowed formats per `PRODUCT.md` §13 (as revised for this task, with owner approval): PDF, JPG/JPEG, PNG, DOCX, XLSX. Legacy binary DOC/XLS are explicitly excluded — see `PRODUCT.md` §13's note on why. Max size 10 MB, must be configurable.
- Never trust client-supplied filename or MIME type (`PRODUCT.md` §13, §14).
- Files stored with randomized names on private disk; original filename may be stored as metadata only (`PRODUCT.md` §13).
- No public file URLs, no raw filesystem paths, no download endpoint in this task (task brief, "Explicitly OUT OF SCOPE").
- Every business-side lookup stays tenant-scoped through `$request->user()->...` relations, never a bare `Model::findOrFail` (`CLAUDE.md` §5, §10).
- Item must be resolved through the token-authenticated `DocumentRequest->items()`, never `DocumentRequestItem::findOrFail($id)` alone (task brief).
- Do not invent a `document_requests.status` transition in this task (task brief, confirmed by architecture review — leave `document_requests.status` untouched).
- Do not disable CSRF; do not regenerate or log the raw access token (task brief, `CLAUDE.md` §7, §15).
- Rate-limit the upload endpoint using Laravel's built-in `RateLimiter`, no new package (`PRODUCT.md` §14, task brief).
- No `v-html` for any user-controlled string in Vue (task brief, `CLAUDE.md` §17).
- No new abstraction layers (no generic `FileService`, no repository layer) — task brief, `CLAUDE.md` §3.
- Existing conventions to follow exactly: PHP models use `#[Fillable([...])]` attribute (not `protected $fillable`); Pest tests use bare `it(...)` closures (see `tests/Feature/Domain/*.php`, `tests/Feature/Http/ClientRequestControllerTest.php`); public routes live in `App\Http\Controllers\Public\*`; business routes/controllers use `$request->user()->...()->findOrFail(...)`.

---

## Environment Setup (read before Task 1)

This worktree is a separate checkout at `.claude/worktrees/task6-secure-file-uploads`. The docker containers already running on this machine (`client-document-chaser-app-1`, etc.) were started from the **main** checkout directory and bind-mount *that* directory — running `docker compose exec app ...` from inside the worktree without a distinct project name would silently execute against the main branch's code, not this branch's changes. This project also requires PHP 8.4+ (host PHP here is 8.3.30 — confirmed by `composer install` failing on `symfony/http-foundation` / `sebastian/*` platform requirements), so tests cannot run on host PHP either. All test/build commands in this plan therefore target an isolated, worktree-scoped docker stack.

`phpunit.xml` already forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync` for the `testing` environment — so `php artisan test` needs no Postgres/Redis at all. Only `app` needs to be up for every automated-test command in this plan; nginx/vite are only needed for the manual browser verification in Task 8.

- [ ] Create a `.env` in the worktree (not committed — gitignored): `cp .env.example .env`, then fill in `DB_PASSWORD` with any local value (e.g. `postgres`) and generate an app key.
- [ ] Bring up only the non-port-publishing services under an isolated project name:

```bash
docker compose -p cdc-task6-uploads up -d --build app postgres redis
docker compose -p cdc-task6-uploads exec app php artisan key:generate
```

Every `docker compose -p cdc-task6-uploads exec app ...` command in the tasks below assumes this stack is already up. If a session restarts, re-run the `up -d --build app postgres redis` line before resuming (add `worker`/`scheduler` too if a task needs the queue running, though none in this plan do).

---

## File Structure

| File | Responsibility |
|---|---|
| `config/uploads.php` (new) | Single configurable value: max upload size in KB. |
| `.env.example` (modify) | Document the new `UPLOAD_MAX_SIZE_KB` var. |
| `docker/php/uploads.ini` (new) | Raise PHP's `upload_max_filesize`/`post_max_size` ceiling above the app limit. |
| `Dockerfile` (modify) | Copy the new ini into the PHP image. |
| `docker/nginx/default.conf` (modify) | Raise nginx's `client_max_body_size` above the app limit. |
| `app/Models/DocumentRequest.php` (modify) | Add `findPubliclyAccessible()` — single source of truth for token→request resolution, used by both the show page and the upload endpoint. |
| `app/Http/Controllers/Public/ClientRequestController.php` (modify) | Use the new lookup; include item `id`/`status` and the raw `token` in the Inertia payload (needed by the Vue upload form); send `Referrer-Policy: no-referrer` (the token is a credential embedded in this page's URL). |
| `app/Providers/AppServiceProvider.php` (modify) | Add a `client-upload` rate limiter, keyed by hashed token and by IP. |
| `app/Http/Requests/Public/StoreUploadedDocumentRequest.php` (new) | Server-side file validation: sniffed MIME allowlist + extension allowlist + configurable max size. |
| `app/Http/Controllers/Public/UploadedDocumentController.php` (new) | Resolves token → request → item, stores the file under an opaque path, creates the `UploadedDocument` row and flips the item to `received` inside a transaction, cleans up the stored file on failure. |
| `routes/web.php` (modify) | New `POST /request/{token}/items/{item}/upload` route. |
| `resources/js/Pages/Public/DocumentRequest.vue` (modify) | Per-item file input + upload button, shows "✓ Received" after success, shows validation errors inline. |
| `app/Http/Controllers/DocumentRequestController.php` (modify) | `show()` payload includes each item's uploaded documents (safe metadata only). |
| `resources/js/Pages/DocumentRequests/Show.vue` (modify) | Shows Received/Missing per item, with filename/size/type/date for received documents. |
| `tests/Feature/Http/PublicUploadTest.php` (new) | All upload security/behavior tests. |
| `tests/Feature/Domain/DocumentRequestTest.php` (modify) | Tests for `findPubliclyAccessible()`. |
| `tests/Feature/Http/DocumentRequestControllerTest.php` (modify) | Tests for the new `documents` field on the business-side show payload. |

---

### Task 1: Upload size ceiling (Docker/PHP/nginx) + configurable max size

The 10 MB limit in `PRODUCT.md` is unreachable today: nginx defaults to a 1 MB body limit and PHP-FPM defaults to `upload_max_filesize=2M`/`post_max_size=8M`. When `post_max_size` is exceeded, PHP drops `$_POST` (and with it the CSRF token), and Laravel returns an unrelated 419 instead of a validation error. This must be fixed before any upload code is written, or every later manual/feature test that uses a real file near the limit will fail for an unrelated reason.

**Files:**
- Create: `config/uploads.php`
- Create: `docker/php/uploads.ini`
- Modify: `Dockerfile`
- Modify: `docker/nginx/default.conf`
- Modify: `.env.example`
- Test: `tests/Unit/Config/UploadsConfigTest.php`

**Interfaces:**
- Produces: `config('uploads.max_size_kb')` — int, kilobytes. Used by `StoreUploadedDocumentRequest` (Task 4).

- [ ] **Step 1: Write the failing test**

```php
<?php

it('defaults the configurable max upload size to 10 MB in kilobytes', function () {
    expect(config('uploads.max_size_kb'))->toBe(10240);
});

it('reads the max upload size from the UPLOAD_MAX_SIZE_KB env var', function () {
    config(['uploads.max_size_kb' => (int) '2048']);

    expect(config('uploads.max_size_kb'))->toBe(2048);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Unit/Config/UploadsConfigTest.php`
Expected: FAIL — `config/uploads.php` does not exist, `config('uploads.max_size_kb')` returns `null`.

- [ ] **Step 3: Create `config/uploads.php`**

```php
<?php

return [
    // Maximum size, in kilobytes, of a single client-uploaded document.
    // PRODUCT.md §13 requires this to be configurable; default matches the 10 MB MVP limit.
    'max_size_kb' => (int) env('UPLOAD_MAX_SIZE_KB', 10240),
];
```

- [ ] **Step 4: Add the env var to `.env.example`**

Add this line near `FILESYSTEM_DISK=local`:

```
UPLOAD_MAX_SIZE_KB=10240
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Unit/Config/UploadsConfigTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Raise the PHP ini ceiling above the app limit**

Create `docker/php/uploads.ini`:

```ini
upload_max_filesize=12M
post_max_size=13M
```

Modify `Dockerfile` — add this line directly after the existing `COPY docker/php/opcache.ini ...` line:

```dockerfile
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini
```

- [ ] **Step 7: Raise the nginx ceiling above the app limit**

Modify `docker/nginx/default.conf` — add one line inside the existing `server { ... }` block, directly after `index index.php;`:

```nginx
    client_max_body_size 12m;
```

- [ ] **Step 8: Rebuild the app image and verify the ini took effect**

Run: `docker compose -p cdc-task6-uploads build app worker scheduler && docker compose -p cdc-task6-uploads up -d app`
Run: `docker compose -p cdc-task6-uploads exec app php -i | grep -E 'upload_max_filesize|post_max_size'`
Expected: `upload_max_filesize => 12M => 12M`, `post_max_size => 13M => 13M`

- [ ] **Step 9: Commit**

```bash
git add config/uploads.php docker/php/uploads.ini Dockerfile docker/nginx/default.conf .env.example tests/Unit/Config/UploadsConfigTest.php
git commit -m "feat: raise upload size ceiling and add configurable max upload size

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 2: `DocumentRequest::findPubliclyAccessible()` + public page refactor

The upload endpoint and the existing show page both need "resolve token → request, or nothing" — duplicating the hash lookup plus `isPubliclyAccessible()` check in two controllers is exactly the kind of drift that produces an auth bug later. Centralize it on the model, used by both.

**Files:**
- Modify: `app/Models/DocumentRequest.php`
- Modify: `app/Http/Controllers/Public/ClientRequestController.php`
- Test: `tests/Feature/Domain/DocumentRequestTest.php`
- Test: `tests/Feature/Http/ClientRequestControllerTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `DocumentRequest::findPubliclyAccessible(string $token): ?DocumentRequest` — returns `null` if the token doesn't match any request, or matches one that isn't publicly accessible. Used by `UploadedDocumentController` (Task 5).

- [ ] **Step 1: Write the failing model tests**

Append to `tests/Feature/Domain/DocumentRequestTest.php`:

```php
it('resolves a publicly accessible request by its plaintext token', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => now()]);
    $token = $documentRequest->generateAccessToken();

    $found = DocumentRequest::findPubliclyAccessible($token);

    expect($found)->not->toBeNull();
    expect($found->is($documentRequest))->toBeTrue();
});

it('returns null from findPubliclyAccessible for an unknown token', function () {
    expect(DocumentRequest::findPubliclyAccessible(str_repeat('a', 40)))->toBeNull();
});

it('returns null from findPubliclyAccessible for a token whose request is not publicly accessible', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => null]);
    $token = $documentRequest->generateAccessToken();

    expect(DocumentRequest::findPubliclyAccessible($token))->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Domain/DocumentRequestTest.php`
Expected: FAIL — `Call to undefined method App\Models\DocumentRequest::findPubliclyAccessible()`

- [ ] **Step 3: Implement `findPubliclyAccessible()`**

In `app/Models/DocumentRequest.php`, add this method (after `isPubliclyAccessible()`):

```php
public static function findPubliclyAccessible(string $token): ?self
{
    $documentRequest = static::where('access_token_hash', hash('sha256', $token))->first();

    if ($documentRequest === null || ! $documentRequest->isPubliclyAccessible()) {
        return null;
    }

    return $documentRequest;
}
```

- [ ] **Step 4: Run to verify the model tests pass**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Domain/DocumentRequestTest.php`
Expected: PASS

- [ ] **Step 5: Write the failing controller test for the enriched payload**

Append to `tests/Feature/Http/ClientRequestControllerTest.php`:

```php
it('includes item id, status, and the plaintext token in the public payload', function () {
    $documentRequest = makePubliclyAccessibleRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Bank statement', 'status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion()])
        ->get("/request/{$token}");

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
```

- [ ] **Step 6: Run to verify failure**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/ClientRequestControllerTest.php`
Expected: FAIL — `token` prop missing, `items.0.id`/`items.0.status` missing, no `Referrer-Policy` header.

- [ ] **Step 7: Implement the controller changes**

Replace the full body of `app/Http/Controllers/Public/ClientRequestController.php`:

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientRequestController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $documentRequest = DocumentRequest::findPubliclyAccessible($token);

        abort_unless($documentRequest !== null, 404);

        $documentRequest->loadMissing(['client:id,name', 'items:id,document_request_id,name,status']);

        return Inertia::render('Public/DocumentRequest', [
            'token' => $token,
            'documentRequest' => [
                'client_name' => $documentRequest->client->name,
                'message' => $documentRequest->message,
                'status' => $documentRequest->status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'items' => $documentRequest->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status,
                ])->values(),
            ],
        ])->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }
}
```

- [ ] **Step 8: Run to verify the controller tests pass**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/ClientRequestControllerTest.php`
Expected: PASS (all tests, including the two new ones and the pre-existing "does not expose internal or cross-tenant fields" test — `token` is a new top-level prop, not on the `documentRequest` object, so that test's key list is unaffected)

- [ ] **Step 9: Commit**

```bash
git add app/Models/DocumentRequest.php app/Http/Controllers/Public/ClientRequestController.php tests/Feature/Domain/DocumentRequestTest.php tests/Feature/Http/ClientRequestControllerTest.php
git commit -m "refactor: centralize public token resolution on DocumentRequest::findPubliclyAccessible

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 3: `client-upload` rate limiter

30/min/IP (the existing `client-request` limiter) is far too loose for a 10 MB upload endpoint (300 MB/min/IP of disk+CPU) and, keyed only by IP, does nothing to bound abuse of one leaked token from behind shared/corporate NAT. Add a dedicated limiter with two independent limits: per-token (the meaningful one) and per-IP (the anti-scan backstop). Hash the token before using it as a cache key — a raw token must never appear in a cache/log system (`CLAUDE.md` §15), and rate-limiter keys are visible via Redis `MONITOR`/`SLOWLOG`.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Http/PublicUploadTest.php` (rate-limit test only in this task; the rest of this file is built out in Task 5)

**Interfaces:**
- Produces: rate limiter named `client-upload`, applied to the upload route in Task 5.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/PublicUploadTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/PublicUploadTest.php`
Expected: FAIL — route `/request/{token}/items/{item}/upload` does not exist (404, not 429).

- [ ] **Step 3: Add the limiter**

In `app/Providers/AppServiceProvider.php`, inside `boot()`, directly after the existing `client-request` limiter registration, add:

```php
        RateLimiter::for('client-upload', function ($request) {
            return [
                Limit::perMinute(10)->by('upload-req:'.hash('sha256', (string) $request->route('token'))),
                Limit::perMinute(30)->by('upload-ip:'.$request->ip()),
            ];
        });
```

(No new import needed — `Limit` and `RateLimiter` are already imported at the top of this file.)

This step alone will not make the test pass yet — the route doesn't exist until Task 5. Leave the test red; Task 5 turns it green as part of building the endpoint. Do not skip ahead and build the route here — Task 5 owns the route/controller/request-validation as one reviewable unit.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Http/PublicUploadTest.php
git commit -m "feat: add client-upload rate limiter keyed by hashed token and IP

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 4: `StoreUploadedDocumentRequest` — server-side file validation

This is the security-critical validation step. `mimes:` in Laravel is already content-sniffed (via `finfo`/`getMimeType()`, not the client's `Content-Type` header), so client-side MIME spoofing is not the actual gap. The real gap, confirmed against this repo's own container (`file-5.41`): `mimes:`/`extensions:` alone allow a content/extension mismatch (a real PDF renamed `x.exe` passes a content check alone, but the *extension* rule stops that) — use both the content check and the extension check together.

Allowed formats for this task, per the product owner's explicit decision to deviate from `PRODUCT.md`'s original list (see `PRODUCT.md` §13): PDF, JPG/JPEG, PNG, DOCX, XLSX. Legacy binary `.doc`/`.xls` (OLE2 compound files) are deliberately **excluded** — verified directly against this container's libmagic that a minimal valid legacy `.doc`/`.xls` is sniffed as the *generic* container type `application/CDFV2`, not a Word/Excel-specific MIME type, which would force the allowlist to accept "any OLE2 compound file" (including macro-bearing ones) to support them at all. DOCX/XLSX are ZIP-based and detect precisely, so this task tests that a synthetic legacy-format file is rejected, not accepted.

**Files:**
- Create: `app/Http/Requests/Public/StoreUploadedDocumentRequest.php`
- Test: `tests/Feature/Http/PublicUploadTest.php` (append)

**Interfaces:**
- Consumes: `config('uploads.max_size_kb')` (Task 1).
- Produces: `StoreUploadedDocumentRequest` — type-hinted by `UploadedDocumentController::store()` in Task 5; validated file available via `$request->file('file')`.

- [ ] **Step 1: Write the failing validation tests**

Append to `tests/Feature/Http/PublicUploadTest.php`:

```php
function buildMinimalOle2File(): string
{
    $le = fn (int $n, int $bytes) => $bytes === 2 ? pack('v', $n) : pack('V', $n);

    $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"; // OLE2 signature
    $header .= str_repeat("\x00", 16); // CLSID
    $header .= $le(0x003E, 2); // minor version
    $header .= $le(0x0003, 2); // major version (v3 => 512-byte sectors)
    $header .= "\xFE\xFF"; // byte order mark
    $header .= $le(9, 2); // sector shift (2^9 = 512)
    $header .= $le(6, 2); // mini sector shift (2^6 = 64)
    $header .= str_repeat("\x00", 6); // reserved
    $header .= $le(0, 4); // number of directory sectors (0 for v3)
    $header .= $le(1, 4); // number of FAT sectors
    $header .= $le(1, 4); // first directory sector = sector 1
    $header .= $le(0, 4); // transaction signature
    $header .= $le(0x1000, 4); // mini stream cutoff size
    $header .= "\xFE\xFF\xFF\xFF"; // first mini FAT sector = ENDOFCHAIN
    $header .= $le(0, 4); // number of mini FAT sectors
    $header .= "\xFE\xFF\xFF\xFF"; // first DIFAT sector = ENDOFCHAIN
    $header .= $le(0, 4); // number of DIFAT sectors
    $header .= $le(0, 4).str_repeat("\xFF\xFF\xFF\xFF", 108); // DIFAT: entry 0 = sector 0 (FAT), rest unused

    $fat = $le(0xFFFFFFFD, 4).$le(0xFFFFFFFE, 4).str_repeat("\xFF\xFF\xFF\xFF", 126);

    $name = mb_convert_encoding('Root Entry', 'UTF-16LE', 'UTF-8')."\x00\x00";
    $rootEntry = str_pad($name, 64, "\x00");
    $rootEntry .= $le(strlen($name), 2); // name length in bytes, incl. null terminator
    $rootEntry .= "\x05"; // object type: root storage
    $rootEntry .= "\x01"; // color flag: black
    $rootEntry .= "\xFF\xFF\xFF\xFF"; // left sibling: none
    $rootEntry .= "\xFF\xFF\xFF\xFF"; // right sibling: none
    $rootEntry .= "\xFF\xFF\xFF\xFF"; // child: none
    $rootEntry .= str_repeat("\x00", 16); // CLSID: none (generic, not Word/Excel-specific)
    $rootEntry .= $le(0, 4); // state bits
    $rootEntry .= str_repeat("\x00", 8); // created
    $rootEntry .= str_repeat("\x00", 8); // modified
    $rootEntry .= "\xFE\xFF\xFF\xFF"; // starting sector: ENDOFCHAIN (empty stream)
    $rootEntry .= str_repeat("\x00", 8); // stream size: 0

    $dirSector = str_pad($rootEntry, 512, "\x00");

    return $header.$fat.$dirSector;
}

it('accepts a real PDF upload', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionDoesntHaveErrors('file');
});

it('accepts a real JPEG and PNG upload', function () {
    $documentRequest = makeUploadableRequest();
    $itemJpeg = DocumentRequestItem::factory()->for($documentRequest)->create();
    $itemPng = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $itemJpeg->id), [
        'file' => UploadedFile::fake()->image('photo.jpg'),
    ])->assertSessionDoesntHaveErrors('file');

    $this->post(uploadUrl($token, $itemPng->id), [
        'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertSessionDoesntHaveErrors('file');
});

it('accepts a real DOCX and XLSX upload', function () {
    $documentRequest = makeUploadableRequest();
    $itemDocx = DocumentRequestItem::factory()->for($documentRequest)->create();
    $itemXlsx = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $itemDocx->id), [
        'file' => UploadedFile::fake()->create('contract.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ])->assertSessionDoesntHaveErrors('file');

    $this->post(uploadUrl($token, $itemXlsx->id), [
        'file' => UploadedFile::fake()->create('numbers.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
    ])->assertSessionDoesntHaveErrors('file');
});

it('rejects a legacy binary DOC/XLS file (out of scope for this MVP)', function () {
    $documentRequest = makeUploadableRequest();
    $itemDoc = DocumentRequestItem::factory()->for($documentRequest)->create();
    $itemXls = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $bytes = buildMinimalOle2File();
    $docPath = tempnam(sys_get_temp_dir(), 'ole').'.doc';
    $xlsPath = tempnam(sys_get_temp_dir(), 'ole').'.xls';
    file_put_contents($docPath, $bytes);
    file_put_contents($xlsPath, $bytes);

    $this->post(uploadUrl($token, $itemDoc->id), [
        'file' => new UploadedFile($docPath, 'legacy.doc', null, null, true),
    ])->assertSessionHasErrors('file');

    $this->post(uploadUrl($token, $itemXls->id), [
        'file' => new UploadedFile($xlsPath, 'legacy.xls', null, null, true),
    ])->assertSessionHasErrors('file');

    unlink($docPath);
    unlink($xlsPath);
});

it('rejects an unsupported file type', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('archive.zip', 100, 'application/zip'),
    ]);

    $response->assertSessionHasErrors('file');
});

it('rejects a PHP script disguised with a PDF extension', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $path = tempnam(sys_get_temp_dir(), 'php').'.pdf';
    file_put_contents($path, "<?php system(\$_GET['c']); ?>");

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => new UploadedFile($path, 'shell.pdf', null, null, true),
    ]);

    $response->assertSessionHasErrors('file');

    unlink($path);
});

it('rejects an HTML file disguised with a JPG extension', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $path = tempnam(sys_get_temp_dir(), 'html').'.jpg';
    file_put_contents($path, '<html><body><script>alert(1)</script></body></html>');

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => new UploadedFile($path, 'image.jpg', null, null, true),
    ]);

    $response->assertSessionHasErrors('file');

    unlink($path);
});

it('sanitizes dangerous original filenames without breaking storage', function () {
    $documentRequest = makeUploadableRequest();
    $dangerousNames = [
        '../../etc/passwd.pdf',
        '..\\..\\windows\\system32.pdf',
        '<script>alert(1)</script>.pdf',
        str_repeat('a', 500).'.pdf',
        "quote'd\"name.pdf",
        'ünïcödé-résumé-日本語.pdf',
    ];

    foreach ($dangerousNames as $dangerousName) {
        $item = DocumentRequestItem::factory()->for($documentRequest)->create();
        $token = $documentRequest->generateAccessToken();

        $response = $this->post(uploadUrl($token, $item->id), [
            'file' => UploadedFile::fake()->create($dangerousName, 100, 'application/pdf'),
        ]);

        $response->assertSessionDoesntHaveErrors('file');

        $document = $item->uploadedDocuments()->first();

        expect($document)->not->toBeNull();
        expect(strlen($document->original_filename))->toBeLessThanOrEqual(255);
        expect($document->storage_path)->not->toContain('..');
        expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($document->storage_path))->toBeTrue();
    }
});

it('rejects a file over the configured max size', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $tooBigKb = config('uploads.max_size_kb') + 1;

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('big.pdf', $tooBigKb, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('file');
});
```

- [ ] **Step 2: Run to verify all new tests fail**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/PublicUploadTest.php`
Expected: FAIL for every test except the rate-limit one (which is still 404-vs-429 from Task 3) — route doesn't exist yet, so every POST 404s.

- [ ] **Step 3: Create the Form Request**

Create `app/Http/Requests/Public/StoreUploadedDocumentRequest.php`:

```php
<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreUploadedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is token/item-ownership based, resolved in the controller
        // (token -> DocumentRequest -> items()), not expressible as a simple policy here.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // Content-sniffed MIME allowlist (the real security boundary) plus an
                // extension allowlist (consistency check only) — both must agree.
                // Legacy binary .doc/.xls are intentionally not supported (PRODUCT.md
                // §13): real .doc/.xls files are frequently sniffed by libmagic as the
                // generic OLE2 container type rather than a Word/Excel-specific MIME,
                // which would force accepting "any OLE2 compound file" to support them
                // at all. DOCX/XLSX are ZIP-based and detect precisely.
                File::types([
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->extensions(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'])
                    ->max(config('uploads.max_size_kb').'kb'),
            ],
        ];
    }
}
```

- [ ] **Step 4: These tests still won't pass — the route doesn't exist.** Confirm the failure mode changed from "route" to "validation only reachable once routed" by temporarily checking the rule in isolation is not needed; proceed directly to Task 5, which wires the route/controller and is the point at which every test in this file goes green together. Do not attempt to make this task's tests pass in isolation — they share the route built in Task 5 by design (this Form Request has no meaning without a controller to inject it into).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Public/StoreUploadedDocumentRequest.php tests/Feature/Http/PublicUploadTest.php
git commit -m "feat: add server-side file validation for client uploads

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 5: Upload endpoint — route, controller, storage, item status

This is the core of the task. Wires the route, resolves token→request→item safely, stores the file under an opaque name, creates the `UploadedDocument` row with every ownership field computed server-side, flips the item to `received`, and cleans up on failure. This task turns every test in `PublicUploadTest.php` (Tasks 3 and 4) green, plus adds the IDOR/isolation/mass-assignment/consistency tests.

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/Public/UploadedDocumentController.php`
- Test: `tests/Feature/Http/PublicUploadTest.php` (append)

**Interfaces:**
- Consumes: `DocumentRequest::findPubliclyAccessible()` (Task 2), `StoreUploadedDocumentRequest` (Task 4), `client-upload` limiter (Task 3).
- Produces: route `public.document-request.upload`.

- [ ] **Step 1: Write the failing IDOR/isolation/consistency tests**

Append to `tests/Feature/Http/PublicUploadTest.php`:

```php
it('creates an UploadedDocument with server-derived ownership on a valid upload', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionDoesntHaveErrors();

    $document = $documentRequest->uploadedDocuments()->first();

    expect($document)->not->toBeNull();
    expect($document->user_id)->toBe($documentRequest->user_id);
    expect($document->client_id)->toBe($documentRequest->client_id);
    expect($document->document_request_id)->toBe($documentRequest->id);
    expect($document->document_request_item_id)->toBe($item->id);
    expect($document->disk)->toBe('local');
    expect($document->mime_type)->toBe('application/pdf');
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists($document->storage_path))->toBeTrue();
    expect($document->storage_path)->not->toContain('statement');
});

it('flips the item status from requested to received on successful upload', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    expect($item->fresh()->status)->toBe('received');
});

it('allows a second upload to an already-received item without overwriting the first', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
    ]);
    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
    ]);

    expect($item->uploadedDocuments()->count())->toBe(2);
    expect($item->fresh()->status)->toBe('received');

    $paths = $item->uploadedDocuments()->pluck('storage_path');
    expect($paths[0])->not->toBe($paths[1]);
});

it('works without any authenticated business session', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertSessionDoesntHaveErrors();

    $this->assertGuest();
});

it('rejects an upload with an unknown token', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();

    $this->post(uploadUrl(str_repeat('a', 40), $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertNotFound();
});

it('rejects an upload to a request that was never sent', function () {
    $documentRequest = makeUploadableRequest(['sent_at' => null]);
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertNotFound();
});

it('rejects an upload to an archived request', function () {
    $documentRequest = makeUploadableRequest(['status' => 'archived']);
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertNotFound();
});

it('rejects an upload to an expired request', function () {
    $documentRequest = makeUploadableRequest(['expires_at' => now()->subMinute()]);
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertNotFound();
});

it('does not let one request\'s token upload to another request\'s item', function () {
    $requestA = makeUploadableRequest();
    $tokenA = $requestA->generateAccessToken();

    $requestB = makeUploadableRequest();
    $itemB = DocumentRequestItem::factory()->for($requestB)->create();

    $this->post(uploadUrl($tokenA, $itemB->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertNotFound();

    expect($itemB->fresh()->status)->toBe('requested');
    expect(\App\Models\UploadedDocument::query()->count())->toBe(0);
});

it('does not let a valid token upload to an arbitrary item id outside its own request', function () {
    $documentRequest = makeUploadableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $otherRequest = makeUploadableRequest();
    $otherItem = DocumentRequestItem::factory()->for($otherRequest)->create();

    $this->post(uploadUrl($token, $otherItem->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertNotFound();
});

it('ignores client-supplied ownership and storage fields', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();
    $otherUser = User::factory()->create();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
        'user_id' => $otherUser->id,
        'client_id' => 999999,
        'document_request_id' => 999999,
        'document_request_item_id' => 999999,
        'storage_path' => '../../etc/passwd',
        'disk' => 'public',
        'status' => 'received',
        'uploaded_at' => '2000-01-01',
    ]);

    $document = $documentRequest->uploadedDocuments()->first();

    expect($document->user_id)->toBe($documentRequest->user_id);
    expect($document->client_id)->toBe($documentRequest->client_id);
    expect($document->document_request_id)->toBe($documentRequest->id);
    expect($document->document_request_item_id)->toBe($item->id);
    expect($document->disk)->toBe('local');
    expect($document->storage_path)->not->toContain('..');
});

it('does not expose storage path or internal ids in any response', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    $response = $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    $document = $documentRequest->uploadedDocuments()->first();

    $response->assertDontSee($document->storage_path, false);
    $response->assertDontSee((string) $documentRequest->user_id, false);
});

it('deletes the stored file if the database transaction fails', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    // Force the transaction to fail after the file is stored, by deleting the
    // document request's client so the required client_id foreign key fails.
    $documentRequest->client()->delete();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ])->assertStatus(404);
})->skip('client_id cascades delete onto document_requests; see Step 6 note for the actual DB-failure test');
```

- [ ] **Step 2: Run to verify these all fail (mostly 404 vs expected assertions, since the route doesn't exist)**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/PublicUploadTest.php`
Expected: FAIL across the board with route-not-found style failures (or unrelated 404 assertions incidentally passing — check output carefully test-by-test, don't assume).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add directly after the existing `public.document-request.show` route (still outside the `auth` middleware group):

```php
Route::post('/request/{token}/items/{item}/upload', [UploadedDocumentController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->whereNumber('item')
    ->middleware('throttle:client-upload')
    ->name('public.document-request.upload');
```

Add the import at the top of the file alongside the other `use` statements:

```php
use App\Http\Controllers\Public\UploadedDocumentController;
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Public/UploadedDocumentController.php`:

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreUploadedDocumentRequest;
use App\Models\DocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadedDocumentController extends Controller
{
    public function store(StoreUploadedDocumentRequest $request, string $token, string $itemId): RedirectResponse
    {
        $documentRequest = DocumentRequest::findPubliclyAccessible($token);

        abort_unless($documentRequest !== null, 404);

        $item = $documentRequest->items()->findOrFail($itemId);

        $file = $request->file('file');
        $directory = 'uploads/'.$documentRequest->id;
        $name = (string) Str::uuid();

        $storagePath = Storage::disk('local')->putFileAs($directory, $file, $name);

        abort_if($storagePath === false, 500, 'Unable to store the uploaded file.');

        try {
            DB::transaction(function () use ($documentRequest, $item, $file, $storagePath) {
                $documentRequest->uploadedDocuments()->create([
                    'user_id' => $documentRequest->user_id,
                    'client_id' => $documentRequest->client_id,
                    'document_request_item_id' => $item->id,
                    'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                    'storage_path' => $storagePath,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_at' => now(),
                ]);

                if ($item->status !== 'received') {
                    $item->status = 'received';
                    $item->save();
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storagePath);

            throw $e;
        }

        return back()->with('uploaded', true);
    }
}
```

- [ ] **Step 5: Run the full upload test file**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/PublicUploadTest.php`
Expected: PASS for every test except the `->skip()`'d one from Step 1 (which documents that a client-delete cascades onto document_requests rather than producing an isolated FK failure — remove that test, it was written defensively before checking the cascade behavior and doesn't hold in this schema).

- [ ] **Step 6: Delete the skipped test and replace it with the real DB-failure test**

Remove the `it('deletes the stored file if the database transaction fails', ...)` block added in Step 1 and replace it with:

```php
it('deletes the stored file if the database transaction fails', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create();
    $token = $documentRequest->generateAccessToken();

    \Illuminate\Support\Facades\DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new \RuntimeException('simulated database failure'));

    expect(fn () => $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]))->toThrow(\RuntimeException::class);

    $files = \Illuminate\Support\Facades\Storage::disk('local')->allFiles('uploads/'.$documentRequest->id);
    expect($files)->toBeEmpty();
    expect(\App\Models\UploadedDocument::query()->count())->toBe(0);
});
```

- [ ] **Step 7: Run the full file again**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/PublicUploadTest.php`
Expected: PASS, all tests.

- [ ] **Step 8: Run the full test suite to check for regressions**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test`
Expected: PASS, 0 failures.

- [ ] **Step 9: Commit**

```bash
git add routes/web.php app/Http/Controllers/Public/UploadedDocumentController.php tests/Feature/Http/PublicUploadTest.php
git commit -m "feat: add secure public upload endpoint with opaque storage and item status transition

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 6: Public portal upload UI

**Files:**
- Modify: `resources/js/Pages/Public/DocumentRequest.vue`

No PHP/Pest test in this task (the project has no JS test runner — see `package.json`; existing convention tests Inertia payloads only, not Vue component behavior). Verify manually per Task 8.

**Interfaces:**
- Consumes: `token` prop (Task 2), `documentRequest.items[].id`/`.status` (Task 2), route `public.document-request.upload` (Task 5).

- [ ] **Step 1: Replace the file**

Replace the full body of `resources/js/Pages/Public/DocumentRequest.vue`:

```vue
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const state = reactive({});
props.documentRequest.items.forEach((item) => {
    state[item.id] = {
        file: null,
        uploading: false,
        error: null,
        received: item.status === 'received',
    };
});

function onFileChange(itemId, event) {
    state[itemId].file = event.target.files[0] ?? null;
    state[itemId].error = null;
}

function upload(itemId) {
    const entry = state[itemId];

    if (!entry.file) {
        entry.error = 'Choose a file first.';
        return;
    }

    const formData = new FormData();
    formData.append('file', entry.file);

    entry.uploading = true;
    entry.error = null;

    router.post(
        route('public.document-request.upload', { token: props.token, item: itemId }),
        formData,
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                entry.received = true;
                entry.file = null;
            },
            onError: (errors) => {
                entry.error = errors.file ?? 'Upload failed. Please try again.';
            },
            onFinish: () => {
                entry.uploading = false;
            },
        }
    );
}
</script>

<template>
    <Head title="Document Request" />

    <GuestLayout>
        <div class="mx-auto max-w-xl">
            <h1 class="text-xl font-semibold text-gray-800">Document Request</h1>

            <p class="mt-4 text-sm text-gray-700">
                Hi, {{ documentRequest.client_name }}
            </p>

            <p v-if="documentRequest.message" class="mt-2 text-sm text-gray-700">
                {{ documentRequest.message }}
            </p>

            <div class="mt-6 space-y-4">
                <h2 class="text-sm font-medium text-gray-500">Documents requested</h2>

                <div
                    v-for="item in documentRequest.items"
                    :key="item.id"
                    class="rounded border border-gray-200 p-4"
                >
                    <h3 class="text-sm font-medium text-gray-900">{{ item.name }}</h3>

                    <p v-if="state[item.id].received" class="mt-2 text-sm font-medium text-green-700">
                        ✓ Received
                    </p>

                    <div v-else class="mt-2 flex items-center gap-2">
                        <input
                            type="file"
                            class="text-sm text-gray-700"
                            @change="onFileChange(item.id, $event)"
                        />
                        <button
                            type="button"
                            class="rounded bg-gray-800 px-3 py-1 text-sm text-white disabled:opacity-50"
                            :disabled="state[item.id].uploading"
                            @click="upload(item.id)"
                        >
                            {{ state[item.id].uploading ? 'Uploading…' : 'Upload' }}
                        </button>
                    </div>

                    <p v-if="state[item.id].error" class="mt-1 text-sm text-red-600">
                        {{ state[item.id].error }}
                    </p>
                </div>
            </div>

            <div class="mt-6 space-y-1 text-sm text-gray-700">
                <p v-if="documentRequest.due_at">Due: {{ documentRequest.due_at }}</p>
                <p v-if="documentRequest.expires_at">Request expires: {{ documentRequest.expires_at }}</p>
            </div>
        </div>
    </GuestLayout>
</template>
```

- [ ] **Step 2: Build assets to catch syntax errors**

The `app` container has no Node.js — only the `vite` service does. Run: `docker compose -p cdc-task6-uploads run --rm vite sh -c "npm install && npm run build"`
Expected: build succeeds with no errors.

- [ ] **Step 3: Run the full Pest suite once more (Inertia payload assertions must still pass with the new template)**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Public/DocumentRequest.vue
git commit -m "feat: add per-item upload form to the public client portal

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 7: Business-side received/missing visibility

**Files:**
- Modify: `app/Http/Controllers/DocumentRequestController.php`
- Modify: `resources/js/Pages/DocumentRequests/Show.vue`
- Test: `tests/Feature/Http/DocumentRequestControllerTest.php`

**Interfaces:**
- Consumes: `UploadedDocument` model (existing), `DocumentRequestItem::uploadedDocuments()` relation (existing).
- Produces: `documentRequest.items[].documents[]` in the `show()` Inertia payload — each entry: `{ id, original_filename, mime_type, size, uploaded_at }`. No `storage_path`, `disk`, `user_id`, or `client_id`.

- [ ] **Step 1: Write the failing test**

This file has no shared setup helper — every test builds its own `User`/`Client`/`DocumentRequest` inline via factories (see the existing tests). Match that. Add `use App\Models\UploadedDocument;` to the file's `use` block at the top (it currently imports `Client`, `DocumentRequest`, `DocumentRequestItem`, `User` only). Append this test:

```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/DocumentRequestControllerTest.php`
Expected: FAIL — `documents` key missing from item payload.

- [ ] **Step 3: Implement the controller change**

In `app/Http/Controllers/DocumentRequestController.php`, replace the `show()` method body:

```php
    public function show(Request $request, string $documentRequest): Response
    {
        $documentRequest = $request->user()->documentRequests()
            ->with(['client', 'items.uploadedDocuments' => fn ($query) => $query->orderByDesc('uploaded_at')])
            ->findOrFail($documentRequest);

        return Inertia::render('DocumentRequests/Show', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'updated_at']),
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'client' => $documentRequest->client->only(['id', 'name', 'email']),
                'items' => $documentRequest->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status,
                    'documents' => $item->uploadedDocuments->map(fn ($document) => [
                        'id' => $document->id,
                        'original_filename' => $document->original_filename,
                        'mime_type' => $document->mime_type,
                        'size' => $document->size,
                        'uploaded_at' => $document->uploaded_at->toIso8601String(),
                    ])->values(),
                ]),
            ],
        ]);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test tests/Feature/Http/DocumentRequestControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Update the business-side Vue page**

In `resources/js/Pages/DocumentRequests/Show.vue`, replace the "Requested documents" `<div>` block:

```vue
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Requested documents</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <ul class="space-y-2">
                                    <li v-for="item in documentRequest.items" :key="item.id">
                                        <div class="flex items-center gap-2">
                                            <span>{{ item.name }}</span>
                                            <span
                                                class="rounded px-2 py-0.5 text-xs font-medium"
                                                :class="item.status === 'received' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                            >
                                                {{ item.status === 'received' ? 'Received' : 'Missing' }}
                                            </span>
                                        </div>
                                        <ul v-if="item.documents.length" class="mt-1 list-disc pl-5 text-xs text-gray-500">
                                            <li v-for="document in item.documents" :key="document.id">
                                                {{ document.original_filename }} ({{ Math.round(document.size / 1024) }} KB, {{ document.mime_type }})
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                            </dd>
                        </div>
```

- [ ] **Step 6: Build assets**

Run: `docker compose -p cdc-task6-uploads run --rm vite sh -c "npm install && npm run build"`
Expected: build succeeds.

- [ ] **Step 7: Run the full suite**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/DocumentRequestController.php resources/js/Pages/DocumentRequests/Show.vue tests/Feature/Http/DocumentRequestControllerTest.php
git commit -m "feat: show received/missing document status on the business-side request view

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 8: Full verification, manual test, security review, docs

**Files:** none new — verification only, plus `docs/` update if a docs skill flags stale content.

- [ ] **Step 1: Run the full automated suite**

Run: `docker compose -p cdc-task6-uploads exec app php artisan test`
Expected: PASS, 0 failures. Note the total test count for the final report.

- [ ] **Step 2: Run Pint**

Run: `docker compose -p cdc-task6-uploads exec app vendor/bin/pint --test`
Expected: no style violations. If any, run `docker compose -p cdc-task6-uploads exec app vendor/bin/pint` to fix, then re-run the test suite (Step 1) since Pint can reformat in ways worth re-verifying).

- [ ] **Step 3: Verify migrations need no changes**

Run: `docker compose -p cdc-task6-uploads exec app php artisan migrate:status`
Expected: all existing migrations still `Ran`; no new migration was needed for this task (the `uploaded_documents` and `document_request_items` tables already had every column this feature needs).

- [ ] **Step 4: Verify the private disk is not web-accessible**

Run: `docker compose -p cdc-task6-uploads exec app php artisan tinker --execute="echo config('filesystems.disks.local.serve') ? 'PUBLIC-BUG' : 'private-ok';"`
Expected: `private-ok`.

The main checkout's nginx already holds host port 8080, so bring this worktree's nginx up on a different port instead of colliding with it — create a worktree-local (not committed — it isn't tracked by git and shouldn't be) `docker-compose.override.yml`:

```yaml
services:
  nginx:
    ports:
      - "8081:80"
  vite:
    ports:
      - "5174:5173"
```

Run: `docker compose -p cdc-task6-uploads up -d nginx`
Run: `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8081/storage/uploads/1/anything`
Expected: `404` (nginx serves only `/public`; `storage/app/private` is outside webroot and outside the `public` disk symlink).

- [ ] **Step 5: Manual end-to-end test — happy path**

With the `docker-compose.override.yml` above in place and `docker compose -p cdc-task6-uploads up -d nginx`:
1. Register/login a business user at `http://localhost:8081/register`.
2. Create a client, then a document request with at least 2 items, set `sent_at` by using the existing "send"/access-link flow (check how `sent_at` currently gets populated — if there's no explicit "send" action yet, set it via `php artisan tinker` for this manual test: `$r = \App\Models\DocumentRequest::latest()->first(); $r->sent_at = now(); $r->save();`).
3. Click "Copy secure link" on the request's Show page, open the resulting `/request/{token}` URL in a new private/incognito window.
4. For one item, choose a small real PDF and click Upload — confirm it shows "✓ Received" without a page reload glitch.
5. Reload the business-side Show page — confirm that item now shows "Received" with the filename/size/type.
6. Confirm the still-open item shows "Missing".

- [ ] **Step 6: Manual test — rejection paths**

1. Archive the request (business side "Archive" button), then try to open its `/request/{token}` link again — confirm 404.
2. Using `tinker`, set `expires_at` to a past timestamp on a different, still-`sent` request; confirm its link 404s.
3. Visit `/request/` + 40 random characters — confirm 404.
4. On a still-valid link, open browser devtools, edit the upload form's action URL to point at a numeric item ID that belongs to a different request created via tinker — confirm 404 (this exercises the exact IDOR path the automated tests already cover; manual pass is a sanity check, not new coverage).
5. Try uploading a `.zip` — confirm an inline validation error, no stack trace.
6. Try uploading a file larger than `UPLOAD_MAX_SIZE_KB` — confirm an inline validation error, no 419/500.

- [ ] **Step 7: Inspect the actual filesystem for opaque naming**

Run: `docker compose -p cdc-task6-uploads exec app find storage/app/private/uploads -type f`
Expected: filenames are bare UUIDs (no extension, no original filename fragment), nested under `uploads/{document_request_id}/`.

- [ ] **Step 8: Dedicated security review**

Invoke the `security-review` skill against the diff (or review manually against this checklist — go through the actual code, not just the passing tests):

- [ ] IDOR/BOLA: item always resolved via `$documentRequest->items()->findOrFail()` — confirmed in `UploadedDocumentController::store()`.
- [ ] Tenant isolation: `user_id`/`client_id` on the created `UploadedDocument` always come from `$documentRequest->user_id`/`->client_id`, never request input — confirmed.
- [ ] Token validation: every upload goes through `DocumentRequest::findPubliclyAccessible()`, which re-checks `isPubliclyAccessible()` (sent/archived/expired) on every request — confirmed, no caching of the accessibility check across requests.
- [ ] Token leakage: no `Log::` call anywhere touches `$token`; rate-limiter key hashes it; Referrer-Policy set to `no-referrer` on the page that carries it in its URL.
- [ ] Path traversal: storage path is always `uploads/{id}/{Str::uuid()}` — no user input reaches `Storage::disk()->putFileAs()`'s path arguments.
- [ ] Filename injection / XSS: `original_filename` is Vue-interpolated with `{{ }}` (Show.vue), never `v-html`; length-capped via `Str::limit(...,255,'')` before the DB write.
- [ ] MIME/extension spoofing: `File::types()` (sniffed) + `->extensions()` (declared) both required to pass.
- [ ] Executable uploads: `.php`/`.phtml`/`.phar` blocked unconditionally by Laravel's `shouldBlockPhpUpload()`, exercised by the `Task 4` PHP-disguised-as-PDF test.
- [ ] Oversized uploads: app-level `File::types()->max()` plus PHP/nginx ceiling raised only slightly above it (Task 1) so the app, not the web server, is the source of the user-facing error.
- [ ] Public storage exposure: confirmed in Step 4 above.
- [ ] Mass assignment: `UploadedDocument`'s `#[Fillable(...)]` list only contains legitimate server-computed columns; the create call in the controller never spreads `$request->all()` or similar — confirmed by reading the controller.
- [ ] CSRF: no `VerifyCsrfToken` exception added anywhere; route uses the default `web` middleware group.
- [ ] Rate limiting: `client-upload` limiter (Task 3), covered by an automated test.
- [ ] Race conditions: two uploads to the same item both succeed and both persist (Task 5 test), no unique constraint violated, no lost update — each is an independent insert plus an idempotent status write.
- [ ] Orphaned storage/DB state: transaction-wrapped create + status update, with file cleanup in the `catch` (Task 5), tested via a forced-failure test.
- [ ] Unsafe Inertia props: verified no `storage_path`/`disk`/`user_id`/`client_id` appear in either the public or business-side payload (automated tests in Tasks 5 and 7).
- [ ] Sensitive logging: no code path logs file contents, the token, or exception messages containing file paths beyond Laravel's default exception handling (which already redacts in production per `APP_DEBUG=false`).

- [ ] **Step 9: Final report**

Compose the completion report (this is a reporting step, not a file to write) covering: files changed, no migrations needed, routes added, file validation rules and their documented limitation, storage strategy, `UploadedDocument`/item-status behavior, tests added (list count per file), full test suite result, security review findings (should be none outstanding — everything above was designed in, not found after the fact), any deferred items (download endpoint, request-level status transition, retention/cleanup jobs — all explicitly out of scope per the task brief), and the one assumption made in Step 5 about how `sent_at` currently gets populated (if there's no dedicated "send" UI action yet, note that as a pre-existing gap from an earlier task, not something this task should fix).

- [ ] **Step 10: Hand off per `finishing-a-development-branch`**

Do not merge/PR without following that skill's process (already required by the top-level workflow) — this step is a pointer, not a substitute; read and follow that skill now.
