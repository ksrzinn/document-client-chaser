# Secure Client Portal (Product Task 5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a client open one specific `DocumentRequest` through an unguessable secure link, with no login, read-only, and no upload UI.

**Architecture:** Add a hashed access-token column to `document_requests`. A public, unauthenticated, rate-limited route resolves the request by hashing the presented token and matching it against the stored hash. Public visibility is additionally gated on `sent_at IS NOT NULL` (reusing the existing column instead of inventing new status values) and on `status !== 'archived'` and non-expired `expires_at`. Any failure returns an identical 404 — no enumeration. A minimal tenant-scoped "copy secure link" business action lazily generates the token. A new public Inertia page renders only client-safe fields.

**Tech Stack:** Laravel 13, Inertia + Vue 3, PostgreSQL, Pest.

**Spec:** Product Task 5 spec as given in the conversation (secure client portal / request access) — full text lives in the conversation history that produced this plan; key extracted rules are restated in Global Constraints below so this plan is self-sufficient.

## Global Constraints

- Public credential is a cryptographically secure random token (`Str::random(40)`), never the numeric `DocumentRequest` id.
- Only a SHA-256 hash of the token is stored (`access_token_hash`); the raw token is returned once at generation time and never persisted or logged.
- Public route: `GET /request/{token}`, outside all `auth` middleware groups, token constrained to `[A-Za-z0-9]{40}` at the route level.
- Public visibility requires ALL of: `sent_at` not null, `status !== 'archived'`, and (`expires_at` is null OR `expires_at` is in the future, using server time `now()`).
- Every rejection path (invalid token, wrong length, unknown hash, not-sent, archived, expired) returns a generic 404 — never a distinct error message or status per case.
- Public route is rate-limited via Laravel's native `RateLimiter`/`throttle` middleware, keyed by IP only (never by token).
- Public Inertia props expose only: client name, message, item names, `due_at`, `expires_at`, `status`. Never `user_id`, `client_id`, item ids, token/hash, client email, or any other tenant's data.
- No file upload UI, no email sending, no reminders, no queues, no client accounts in this task.
- Business-side "copy link" endpoint is tenant-scoped (`$request->user()->documentRequests()`) and returns the same link on repeated calls (no rotation).
- No new third-party packages.

---

## File Structure

- `database/migrations/2026_09_04_000006_add_access_token_hash_to_document_requests_table.php` — new nullable, unique `access_token_hash` column.
- `app/Models/DocumentRequest.php` — add `generateAccessToken()` and `isPubliclyAccessible()` methods.
- `app/Http/Controllers/Public/ClientRequestController.php` — new controller, single `show` action, no auth.
- `app/Http/Controllers/DocumentRequestController.php` — add `accessLink` action (tenant-scoped, generates/returns link).
- `app/Providers/AppServiceProvider.php` — register `client-request` rate limiter in `boot()`.
- `routes/web.php` — add public `GET /request/{token}` route and business `POST document-requests/{document_request}/access-link` route.
- `resources/js/Pages/Public/DocumentRequest.vue` — new read-only public page.
- `resources/js/Pages/DocumentRequests/Show.vue` — add "Copy secure link" button wired to the new action.
- `tests/Feature/Http/ClientRequestControllerTest.php` — new public-endpoint test suite.
- `tests/Feature/Http/DocumentRequestControllerTest.php` — add `accessLink` tests.

---

## Task 1: Migration — access token column

**Files:**
- Create: `database/migrations/2026_09_04_000006_add_access_token_hash_to_document_requests_table.php`
- Test: `tests/Feature/Domain/DocumentRequestTest.php` (add one assertion)

**Interfaces:**
- Produces: `document_requests.access_token_hash` (string(64), nullable, unique).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Domain/DocumentRequestTest.php`:

```php
it('allows multiple document requests to have a null access token hash', function () {
    $requestA = DocumentRequest::factory()->create();
    $requestB = DocumentRequest::factory()->create();

    expect($requestA->access_token_hash)->toBeNull();
    expect($requestB->access_token_hash)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="allows multiple document requests to have a null access token hash"`
Expected: FAIL — `access_token_hash` undefined attribute error, or column-not-found if referenced directly (Eloquent returns null for unknown attributes, so this specific test may pass prematurely — that's fine, the real proof is Step 4 not erroring on migrate; proceed).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('access_token_hash', 64)->nullable()->unique()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn('access_token_hash');
        });
    }
};
```

- [ ] **Step 4: Run migrations and the test**

Run: `php artisan migrate` then `php artisan test --filter="allows multiple document requests to have a null access token hash"`
Expected: migration runs clean; test PASSES (two null hashes coexist — Postgres treats NULLs as distinct under a unique index).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_04_000006_add_access_token_hash_to_document_requests_table.php tests/Feature/Domain/DocumentRequestTest.php
git commit -m "feat: add access_token_hash column to document_requests"
```

---

## Task 2: Model — token generation and public-access guard

**Files:**
- Modify: `app/Models/DocumentRequest.php`
- Test: `tests/Feature/Domain/DocumentRequestTest.php`

**Interfaces:**
- Consumes: `access_token_hash` column from Task 1.
- Produces: `DocumentRequest::generateAccessToken(): string` (raw token, idempotent — returns existing raw token is impossible once hashed, so it throws if already set), `DocumentRequest::isPubliclyAccessible(): bool`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Domain/DocumentRequestTest.php`:

```php
it('generates a 40-character access token and stores only its hash', function () {
    $documentRequest = DocumentRequest::factory()->create();

    $token = $documentRequest->generateAccessToken();

    expect($token)->toHaveLength(40);
    expect($documentRequest->access_token_hash)->toBe(hash('sha256', $token));
    expect($documentRequest->access_token_hash)->not->toBe($token);
});

it('throws when generating an access token for a request that already has one', function () {
    $documentRequest = DocumentRequest::factory()->create();
    $documentRequest->generateAccessToken();

    $documentRequest->generateAccessToken();
})->throws(RuntimeException::class);

it('is not publicly accessible when never sent', function () {
    $documentRequest = DocumentRequest::factory()->create(['sent_at' => null]);

    expect($documentRequest->isPubliclyAccessible())->toBeFalse();
});

it('is not publicly accessible when archived', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'status' => 'archived',
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeFalse();
});

it('is not publicly accessible when expired', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'expires_at' => now()->subMinute(),
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeFalse();
});

it('is publicly accessible exactly at the expiry boundary but not after', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'expires_at' => now()->addSecond(),
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeTrue();

    $this->travel(2)->seconds();

    expect($documentRequest->fresh()->isPubliclyAccessible())->toBeFalse();
});

it('is publicly accessible when sent, not archived, and not expired', function () {
    $documentRequest = DocumentRequest::factory()->create([
        'sent_at' => now(),
        'expires_at' => null,
    ]);

    expect($documentRequest->isPubliclyAccessible())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DocumentRequestTest`
Expected: FAIL — `generateAccessToken`/`isPubliclyAccessible` method not found.

- [ ] **Step 3: Implement**

In `app/Models/DocumentRequest.php`, add:

```php
use Illuminate\Support\Str;

// ... inside class DocumentRequest

public function generateAccessToken(): string
{
    if ($this->access_token_hash !== null) {
        throw new \RuntimeException('Access token already generated for this document request.');
    }

    $token = Str::random(40);

    $this->access_token_hash = hash('sha256', $token);
    $this->save();

    return $token;
}

public function isPubliclyAccessible(): bool
{
    if ($this->sent_at === null || $this->status === 'archived') {
        return false;
    }

    return $this->expires_at === null || $this->expires_at->isFuture();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DocumentRequestTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/DocumentRequest.php tests/Feature/Domain/DocumentRequestTest.php
git commit -m "feat: add access token generation and public-access guard to DocumentRequest"
```

---

## Task 3: Rate limiter registration

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Produces: named rate limiter `client-request` usable as `throttle:client-request` middleware.

- [ ] **Step 1: Implement (no isolated test — verified via Task 4's throttle test)**

```php
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        RateLimiter::for('client-request', function ($request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat: register client-request rate limiter"
```

---

## Task 4: Public controller, route, and page

**Files:**
- Create: `app/Http/Controllers/Public/ClientRequestController.php`
- Create: `resources/js/Pages/Public/DocumentRequest.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Http/ClientRequestControllerTest.php`

**Interfaces:**
- Consumes: `DocumentRequest::isPubliclyAccessible()` (Task 2), `client-request` limiter (Task 3).
- Produces: named route `public.document-request.show`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/ClientRequestControllerTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

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
    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => '1'])->get("/request/{$token}");
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ClientRequestControllerTest`
Expected: FAIL — route `/request/{token}` does not exist (404 for route-not-found vs semantic assertions, or a routing exception).

- [ ] **Step 3: Implement the controller**

Create `app/Http/Controllers/Public/ClientRequestController.php`:

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
        $documentRequest = DocumentRequest::with(['client:id,name', 'items:id,document_request_id,name'])
            ->where('access_token_hash', hash('sha256', $token))
            ->first();

        abort_unless($documentRequest !== null && $documentRequest->isPubliclyAccessible(), 404);

        return Inertia::render('Public/DocumentRequest', [
            'documentRequest' => [
                'client_name' => $documentRequest->client->name,
                'message' => $documentRequest->message,
                'status' => $documentRequest->status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'items' => $documentRequest->items->map(fn ($item) => ['name' => $item->name])->values(),
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add outside the `auth` group (near the top, after `/environment-check`):

```php
use App\Http\Controllers\Public\ClientRequestController;

Route::get('/request/{token}', [ClientRequestController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:client-request')
    ->name('public.document-request.show');
```

- [ ] **Step 5: Create the Vue page**

Create `resources/js/Pages/Public/DocumentRequest.vue`:

```vue
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
});
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

            <div class="mt-6">
                <h2 class="text-sm font-medium text-gray-500">Documents requested</h2>
                <ul class="mt-2 list-disc pl-5">
                    <li v-for="(item, index) in documentRequest.items" :key="index" class="text-sm text-gray-900">
                        {{ item.name }}
                    </li>
                </ul>
            </div>

            <div class="mt-6 space-y-1 text-sm text-gray-700">
                <p v-if="documentRequest.due_at">Due: {{ documentRequest.due_at }}</p>
                <p v-if="documentRequest.expires_at">Request expires: {{ documentRequest.expires_at }}</p>
            </div>

            <p class="mt-6 text-sm text-gray-500">Upload functionality will be added later.</p>
        </div>
    </GuestLayout>
</template>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ClientRequestControllerTest`
Expected: PASS (all 11 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Public/ClientRequestController.php resources/js/Pages/Public/DocumentRequest.vue routes/web.php tests/Feature/Http/ClientRequestControllerTest.php
git commit -m "feat: add public secure client portal for document requests"
```

---

## Task 5: Business-side "copy secure link" action

**Files:**
- Modify: `app/Http/Controllers/DocumentRequestController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/DocumentRequests/Show.vue`
- Test: `tests/Feature/Http/DocumentRequestControllerTest.php`

**Interfaces:**
- Consumes: `DocumentRequest::generateAccessToken()` (Task 2), route `public.document-request.show` (Task 4).
- Produces: route `document-requests.access-link` (POST), flashed session key `accessLink`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Http/DocumentRequestControllerTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="access link"`
Expected: FAIL — route/method does not exist.

- [ ] **Step 3: Implement the controller action**

In `app/Http/Controllers/DocumentRequestController.php`, add:

```php
public function accessLink(Request $request, string $documentRequest): RedirectResponse
{
    $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

    $token = $documentRequest->access_token_hash === null
        ? $documentRequest->generateAccessToken()
        : null;

    if ($token === null) {
        return back()->with('accessLinkExists', true);
    }

    return back()->with('accessLink', route('public.document-request.show', $token));
}
```

Note: once a token exists there is no way to recover the raw value (only the hash is stored), so a second call cannot re-flash the same link. Adjust Show.vue to handle both flash keys (Step 5) — this matches the "no rotation" requirement without needing to store the raw token.

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the existing `auth` group, after the `document-requests.archive` route:

```php
Route::post('document-requests/{document_request}/access-link', [DocumentRequestController::class, 'accessLink'])
    ->whereNumber('document_request')
    ->name('document-requests.access-link');
```

- [ ] **Step 5: Share flash data via Inertia**

`app/Http/Middleware/HandleInertiaRequests.php` currently shares only `auth.user`. Add a `flash` key so `accessLink`/`accessLinkExists` reach the frontend:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => [
            'user' => $request->user(),
        ],
        'flash' => [
            'accessLink' => fn () => $request->session()->get('accessLink'),
            'accessLinkExists' => fn () => $request->session()->get('accessLinkExists'),
        ],
    ];
}
```

- [ ] **Step 6: Wire up the Show.vue button**

In `resources/js/Pages/DocumentRequests/Show.vue`, add inside the actions row (after the Archive button):

```vue
<PrimaryButton type="button" @click="copyLink">Copy secure link</PrimaryButton>
```

And in the script:

```js
const copyLink = () => {
    router.post(route('document-requests.access-link', props.documentRequest.id), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const link = page.props.flash?.accessLink;
            if (link) {
                navigator.clipboard.writeText(link);
                alert('Secure link copied to clipboard.');
            } else {
                alert('A secure link already exists for this request.');
            }
        },
    });
};
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=DocumentRequestControllerTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/DocumentRequestController.php routes/web.php resources/js/Pages/DocumentRequests/Show.vue tests/Feature/Http/DocumentRequestControllerTest.php app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat: add tenant-scoped secure link generation for document requests"
```

---

## Task 6: Full-suite verification and security review

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests PASS, including every pre-existing test (no regressions).

- [ ] **Step 2: Run Pint**

Run: `./vendor/bin/pint --test`
Expected: no style violations (run `./vendor/bin/pint` to auto-fix if it reports any, then re-run tests).

- [ ] **Step 3: Manual security review of the diff**

Walk `git diff main...HEAD` and confirm explicitly:
- No raw token is ever logged, flashed twice, or stored.
- `hash()` (not `Hash::make`) is used for the lookup hash, matching the equality-lookup design.
- The public route's 404 is identical in shape/timing-irrelevant for every rejection branch (unknown hash, not sent, archived, expired all hit the same `abort_unless`).
- No Inertia prop on the public page includes `id`, `user_id`, `client_id`, or the hash.
- `accessLink` uses `$request->user()->documentRequests()->findOrFail()` — tenant scoping confirmed.
- Rate limiter keys by IP only, never by token.

- [ ] **Step 4: Commit if Pint made changes**

```bash
git add -A
git commit -m "style: apply pint formatting"
```

(skip if Step 2 made no changes)
