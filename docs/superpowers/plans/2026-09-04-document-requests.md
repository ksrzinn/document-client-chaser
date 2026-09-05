# Document Requests (Task 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Authenticated business-side CRUD (create/list/view/edit/archive) for DocumentRequest + nested DocumentRequestItems, with strict tenant/client/item ownership and no client-facing functionality.

**Architecture:** Follow the existing `ClientController` pattern exactly: plain `Request` + inline `validated()` helper, no Form Requests, no service layer, ownership always resolved through `$request->user()->documentRequests()` / `->clients()` relations, never bare `Model::find`. Item sync in `update` is done by diffing submitted item ids against the owned collection (update existing, create new, delete removed) inside a `DB::transaction`. Vue pages mirror `resources/js/Pages/Clients/*` structure and styling.

**Tech Stack:** Laravel (Eloquent, Pest), Inertia.js, Vue 3 (Options-free `<script setup>`), Tailwind classes matching existing components.

**Spec:** Product Task 4 requirements (given in the task prompt); existing schema from `database/migrations/2026_09_04_000002_create_document_requests_table.php` and `..._000003_create_document_request_items_table.php` (not modified by this plan).

## Global Constraints

- No new migrations, packages, Form Request classes, service/repository layers, or API routes.
- No destroy route; archive only sets `status = 'archived'`, never deletes.
- `user_id`, `status`, `document_request_id` must never be mass-assignable from request input.
- Every DocumentRequest/DocumentRequestItem lookup must go through the authenticated user's relation, never `Model::find`/`findOrFail`.
- `client_id` must resolve through `$request->user()->clients()->findOrFail(...)`.
- Route param constrained with `->whereNumber(...)`, matching `clients` routes.
- Out of scope: client portal, tokens, uploads, emails, queues, scheduler, status transitions beyond draft→archived.

---

## File Structure

- Create: `app/Http/Controllers/DocumentRequestController.php` — index/create/store/show/edit/update/archive, mirrors `ClientController`.
- Modify: `routes/web.php` — add `document-requests` resource + archive route.
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` — add nav link (desktop + mobile), mirrors `clients.index` link.
- Create: `resources/js/Pages/DocumentRequests/Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue`.
- Create: `tests/Feature/Http/DocumentRequestControllerTest.php` — full feature coverage per spec.

No changes to models, migrations, or factories — existing `DocumentRequest`/`DocumentRequestItem`/factories already fit.

---

### Task 1: Controller — index, create, store (with items)

**Files:**
- Create: `app/Http/Controllers/DocumentRequestController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Http/DocumentRequestControllerTest.php`

**Interfaces:**
- Produces: `DocumentRequestController::index`, `::create`, `::store` (routes `document-requests.index|create|store`).
- Consumes: `App\Models\DocumentRequest`, `App\Models\DocumentRequestItem`, `App\Models\Client` (existing, unchanged).

- [ ] **Step 1: Write failing tests for index + store + validation + client/user isolation**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DocumentRequestControllerTest`
Expected: FAIL (route/controller/view not found).

- [ ] **Step 3: Add routes**

In `routes/web.php`, add inside the existing `Route::middleware('auth')->group(function () { ... })` block, after the `clients` routes:

```php
    Route::resource('document-requests', DocumentRequestController::class)
        ->except(['destroy'])
        ->whereNumber('document_request');
    Route::post('document-requests/{document_request}/archive', [DocumentRequestController::class, 'archive'])
        ->whereNumber('document_request')
        ->name('document-requests.archive');
```

Add `use App\Http\Controllers\DocumentRequestController;` to the top imports.

- [ ] **Step 4: Implement controller (index, create, store) — full file including update/edit/show/archive stubs used by later tasks**

```php
<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DocumentRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $documentRequests = $request->user()->documentRequests()
            ->with('client')
            ->withCount('items')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(fn (DocumentRequest $documentRequest) => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'items_count']),
                'client' => $documentRequest->client->only(['id', 'name']),
            ]);

        return Inertia::render('DocumentRequests/Index', [
            'documentRequests' => $documentRequests,
        ]);
    }

    public function create(Request $request): Response
    {
        $clients = $request->user()->clients()
            ->orderBy('name')
            ->get()
            ->map->only(['id', 'name']);

        return Inertia::render('DocumentRequests/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $client = $request->user()->clients()->findOrFail($validated['client_id']);

        $documentRequest = DB::transaction(function () use ($request, $client, $validated) {
            $documentRequest = $request->user()->documentRequests()->create([
                'client_id' => $client->id,
                'message' => $validated['message'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            $documentRequest->items()->createMany(
                collect($validated['items'])->map(fn (array $item) => ['name' => $item['name']])->all()
            );

            return $documentRequest;
        });

        return redirect()->route('document-requests.show', $documentRequest);
    }

    public function show(Request $request, string $documentRequest): Response
    {
        $documentRequest = $request->user()->documentRequests()
            ->with(['client', 'items'])
            ->findOrFail($documentRequest);

        return Inertia::render('DocumentRequests/Show', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'updated_at']),
                'client' => $documentRequest->client->only(['id', 'name', 'email']),
                'items' => $documentRequest->items->map->only(['id', 'name', 'status']),
            ],
        ]);
    }

    public function edit(Request $request, string $documentRequest): Response
    {
        $documentRequest = $request->user()->documentRequests()
            ->with(['client', 'items'])
            ->findOrFail($documentRequest);

        $clients = $request->user()->clients()
            ->orderBy('name')
            ->get()
            ->map->only(['id', 'name']);

        return Inertia::render('DocumentRequests/Edit', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'client_id', 'message', 'due_at', 'expires_at']),
                'items' => $documentRequest->items->map->only(['id', 'name']),
            ],
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, string $documentRequest): RedirectResponse
    {
        $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

        $validated = $this->validated($request);

        $client = $request->user()->clients()->findOrFail($validated['client_id']);

        DB::transaction(function () use ($documentRequest, $client, $validated) {
            $documentRequest->update([
                'client_id' => $client->id,
                'message' => $validated['message'] ?? null,
                'due_at' => $validated['due_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
            ]);

            $existingIds = $documentRequest->items()->pluck('id');
            $submittedIds = collect($validated['items'])->pluck('id')->filter()->values();

            if ($submittedIds->diff($existingIds)->isNotEmpty()) {
                abort(404);
            }

            $documentRequest->items()->whereNotIn('id', $submittedIds)->delete();

            foreach ($validated['items'] as $item) {
                if (! empty($item['id'])) {
                    $documentRequest->items()->whereKey($item['id'])->update(['name' => $item['name']]);
                } else {
                    $documentRequest->items()->create(['name' => $item['name']]);
                }
            }
        });

        return redirect()->route('document-requests.show', $documentRequest);
    }

    public function archive(Request $request, string $documentRequest): RedirectResponse
    {
        $documentRequest = $request->user()->documentRequests()->findOrFail($documentRequest);

        if ($documentRequest->status !== 'archived') {
            $documentRequest->status = 'archived';
            $documentRequest->save();
        }

        return redirect()->route('document-requests.show', $documentRequest);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where('user_id', $request->user()->id),
            ],
            'message' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:due_at'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['sometimes', 'nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
        ]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DocumentRequestControllerTest`
Expected: index/store/validation/isolation tests PASS. `show`/`edit`/`update`/`archive` tests not yet written — no failures from those paths yet since routes exist and controller methods are implemented (safe to implement whole controller now to avoid rework; Task 2/3 add the remaining tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DocumentRequestController.php routes/web.php tests/Feature/Http/DocumentRequestControllerTest.php
git commit -m "feat: add document request create, store, and index"
```

---

### Task 2: Controller tests — show, edit, update, tenant/item isolation, archive

**Files:**
- Modify: `tests/Feature/Http/DocumentRequestControllerTest.php` (append)

**Interfaces:**
- Consumes: `DocumentRequestController` from Task 1 (already fully implemented — this task only adds test coverage that exercises the remaining branches).

- [ ] **Step 1: Append failing/verifying tests for show, edit, update, archive, and isolation**

```php
// --- Show / Edit ---

it('lets an authenticated user view their document request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    \App\Models\DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Passport']);

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Show')
        ->where('documentRequest.id', $documentRequest->id)
        ->where('documentRequest.status', 'draft')
        ->where('documentRequest.items.0.name', 'Passport')
    );
});

it('lets an authenticated user edit their document request', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();

    $response = $this->actingAs($user)->get(route('document-requests.edit', $documentRequest));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Edit')
        ->where('documentRequest.id', $documentRequest->id)
    );
});

// --- Update ---

it('lets an owner update their request and sync items', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create();
    $keepItem = \App\Models\DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Old name']);
    $removeItem = \App\Models\DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Remove me']);

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
    expect(\App\Models\DocumentRequestItem::find($keepItem->id)->name)->toBe('Renamed');
    expect(\App\Models\DocumentRequestItem::find($removeItem->id))->toBeNull();
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

it('rejects updating an item id that belongs to another request', function () {
    $userA = User::factory()->create();
    $clientA = Client::factory()->for($userA)->create();
    $requestA = DocumentRequest::factory()->for($userA)->for($clientA)->create();

    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();
    $requestB = DocumentRequest::factory()->for($userB)->for($clientB)->create();
    $itemB = \App\Models\DocumentRequestItem::factory()->for($requestB)->create();

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
```

- [ ] **Step 2: Run full test file**

Run: `php artisan test --filter=DocumentRequestControllerTest`
Expected: All PASS (controller already implemented in Task 1).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Http/DocumentRequestControllerTest.php
git commit -m "test: add document request show, edit, update, archive, and isolation coverage"
```

---

### Task 3: Vue pages — Index, Create, Edit, Show + nav link

**Files:**
- Create: `resources/js/Pages/DocumentRequests/Index.vue`
- Create: `resources/js/Pages/DocumentRequests/Create.vue`
- Create: `resources/js/Pages/DocumentRequests/Edit.vue`
- Create: `resources/js/Pages/DocumentRequests/Show.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: Inertia props from Task 1 controller — `documentRequests` (paginated: `id, status, message, due_at, expires_at, created_at, items_count, client:{id,name}`), `clients` (array of `{id,name}`), `documentRequest` (show/edit shape as built in controller), routes `document-requests.index|create|store|show|edit|update|archive`.

- [ ] **Step 1: Create `resources/js/Pages/DocumentRequests/Index.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    documentRequests: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Document Requests" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Document Requests
                </h2>
                <Link :href="route('document-requests.create')">
                    <PrimaryButton type="button">New Request</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Client</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Items</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Due</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Expires</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="documentRequest in documentRequests.data" :key="documentRequest.id">
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ documentRequest.client.name }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500 capitalize">{{ documentRequest.status }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ documentRequest.items_count }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ documentRequest.due_at ?? '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ documentRequest.expires_at ?? '—' }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                    <Link :href="route('document-requests.show', documentRequest.id)" class="text-indigo-600 hover:text-indigo-900">
                                        View
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="documentRequests.data.length === 0">
                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No document requests yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="documentRequests.links.length > 3" class="mt-4 flex justify-center gap-1 px-6 pb-4">
                        <template v-for="(link, index) in documentRequests.links" :key="index">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                v-html="link.label"
                                class="rounded px-3 py-1 text-sm"
                                :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-100'"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="rounded px-3 py-1 text-sm text-gray-400"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Create `resources/js/Pages/DocumentRequests/Create.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    clients: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    client_id: '',
    message: '',
    due_at: '',
    expires_at: '',
    items: [{ name: '' }],
});

const addItem = () => form.items.push({ name: '' });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.post(route('document-requests.store'));
};
</script>

<template>
    <Head title="New Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                New Document Request
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit">
                        <div>
                            <InputLabel for="client_id" value="Client" />
                            <select
                                id="client_id"
                                v-model="form.client_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                required
                            >
                                <option value="" disabled>Select a client</option>
                                <option v-for="client in clients" :key="client.id" :value="client.id">
                                    {{ client.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.client_id" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="message" value="Message (optional)" />
                            <textarea
                                id="message"
                                v-model="form.message"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                rows="3"
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.message" />
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="due_at" value="Due date (optional)" />
                                <TextInput id="due_at" type="date" class="mt-1 block w-full" v-model="form.due_at" />
                                <InputError class="mt-2" :message="form.errors.due_at" />
                            </div>
                            <div>
                                <InputLabel for="expires_at" value="Expires (optional)" />
                                <TextInput id="expires_at" type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                <InputError class="mt-2" :message="form.errors.expires_at" />
                            </div>
                        </div>

                        <div class="mt-6">
                            <InputLabel value="Requested documents" />
                            <div v-for="(item, index) in form.items" :key="index" class="mt-2 flex items-center gap-2">
                                <TextInput
                                    :id="`items-${index}-name`"
                                    type="text"
                                    class="block w-full"
                                    v-model="item.name"
                                    placeholder="e.g. Bank statement"
                                    required
                                />
                                <button
                                    type="button"
                                    class="text-sm text-red-600 hover:text-red-900"
                                    :disabled="form.items.length === 1"
                                    @click="removeItem(index)"
                                >
                                    Remove
                                </button>
                            </div>
                            <InputError class="mt-2" :message="form.errors.items" />
                            <button type="button" class="mt-2 text-sm text-indigo-600 hover:text-indigo-900" @click="addItem">
                                + Add document
                            </button>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-4">
                            <Link :href="route('document-requests.index')">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>

                            <PrimaryButton
                                :class="{ 'opacity-25': form.processing }"
                                :disabled="form.processing"
                            >
                                Create Request
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Create `resources/js/Pages/DocumentRequests/Edit.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
    clients: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    client_id: props.documentRequest.client_id,
    message: props.documentRequest.message ?? '',
    due_at: props.documentRequest.due_at ?? '',
    expires_at: props.documentRequest.expires_at ?? '',
    items: props.documentRequest.items.map((item) => ({ id: item.id, name: item.name })),
});

const addItem = () => form.items.push({ name: '' });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.put(route('document-requests.update', props.documentRequest.id));
};
</script>

<template>
    <Head title="Edit Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit Document Request
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit">
                        <div>
                            <InputLabel for="client_id" value="Client" />
                            <select
                                id="client_id"
                                v-model="form.client_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                required
                            >
                                <option v-for="client in clients" :key="client.id" :value="client.id">
                                    {{ client.name }}
                                </option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.client_id" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="message" value="Message (optional)" />
                            <textarea
                                id="message"
                                v-model="form.message"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                rows="3"
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.message" />
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="due_at" value="Due date (optional)" />
                                <TextInput id="due_at" type="date" class="mt-1 block w-full" v-model="form.due_at" />
                                <InputError class="mt-2" :message="form.errors.due_at" />
                            </div>
                            <div>
                                <InputLabel for="expires_at" value="Expires (optional)" />
                                <TextInput id="expires_at" type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                <InputError class="mt-2" :message="form.errors.expires_at" />
                            </div>
                        </div>

                        <div class="mt-6">
                            <InputLabel value="Requested documents" />
                            <div v-for="(item, index) in form.items" :key="item.id ?? `new-${index}`" class="mt-2 flex items-center gap-2">
                                <TextInput
                                    :id="`items-${index}-name`"
                                    type="text"
                                    class="block w-full"
                                    v-model="item.name"
                                    placeholder="e.g. Bank statement"
                                    required
                                />
                                <button
                                    type="button"
                                    class="text-sm text-red-600 hover:text-red-900"
                                    :disabled="form.items.length === 1"
                                    @click="removeItem(index)"
                                >
                                    Remove
                                </button>
                            </div>
                            <InputError class="mt-2" :message="form.errors.items" />
                            <button type="button" class="mt-2 text-sm text-indigo-600 hover:text-indigo-900" @click="addItem">
                                + Add document
                            </button>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-4">
                            <Link :href="route('document-requests.show', props.documentRequest.id)">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>

                            <PrimaryButton
                                :class="{ 'opacity-25': form.processing }"
                                :disabled="form.processing"
                            >
                                Save Changes
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Create `resources/js/Pages/DocumentRequests/Show.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
});

const archive = () => {
    router.post(route('document-requests.archive', props.documentRequest.id));
};
</script>

<template>
    <Head title="Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Document Request — {{ documentRequest.client.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Client</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.client.name }} ({{ documentRequest.client.email }})</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="mt-1 text-sm text-gray-900 capitalize">{{ documentRequest.status }}</dd>
                        </div>
                        <div v-if="documentRequest.message">
                            <dt class="text-sm font-medium text-gray-500">Message</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.message }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Due date</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.due_at ?? 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Expires</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ documentRequest.expires_at ?? 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Requested documents</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <ul class="list-disc pl-5">
                                    <li v-for="item in documentRequest.items" :key="item.id">
                                        {{ item.name }}
                                    </li>
                                </ul>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex items-center gap-4">
                        <Link :href="route('document-requests.edit', documentRequest.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="documentRequest.status !== 'archived'"
                            type="button"
                            @click="archive"
                        >
                            Archive
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Add nav link in `resources/js/Layouts/AuthenticatedLayout.vue`**

Locate the desktop `NavLink` block for `clients.index` (around line 42-47):

```vue
                                <NavLink
                                    :href="route('clients.index')"
                                    :active="route().current('clients.*')"
                                >
                                    Clients
                                </NavLink>
```

Add immediately after it:

```vue
                                <NavLink
                                    :href="route('document-requests.index')"
                                    :active="route().current('document-requests.*')"
                                >
                                    Document Requests
                                </NavLink>
```

Locate the mobile `ResponsiveNavLink` block for `clients.index` (around line 150-155):

```vue
                        <ResponsiveNavLink
                            :href="route('clients.index')"
                            :active="route().current('clients.*')"
                        >
                            Clients
                        </ResponsiveNavLink>
```

Add immediately after it:

```vue
                        <ResponsiveNavLink
                            :href="route('document-requests.index')"
                            :active="route().current('document-requests.*')"
                        >
                            Document Requests
                        </ResponsiveNavLink>
```

- [ ] **Step 6: Run full backend test suite + Pint**

Run: `php artisan test`
Expected: All PASS.

Run: `./vendor/bin/pint`
Expected: No style violations (or auto-fixed — re-run `php artisan test` after).

- [ ] **Step 7: Manually verify in dev environment**

Run: `docker compose up -d` (or existing dev command), visit `/document-requests`, create a request with 2 items, view it, edit it (rename one item, add one, remove one), archive it. Confirm nav link appears and highlights correctly.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/DocumentRequests resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat: add document request Inertia pages and nav link"
```

---

## Self-Review Notes

- **Spec coverage:** index/create/show/edit/archive ✅ (Task 1-3); tenant isolation, client ownership, item-id ownership ✅ (Task 1-2); validation (client/items/dates) ✅ (Task 1); no destroy route ✅; no status field exposed in UI ✅ (Create/Edit omit status input); mass-assignment protection (`user_id`/`status`/`document_request_id` never trusted) ✅ (controller builds explicit arrays, never spreads `$validated` into create/update wholesale).
- **Placeholder scan:** none found — every step has full code.
- **Type consistency:** `documentRequest` prop shape consistent across `edit`/`update`/`show` controller methods and `Edit.vue`/`Show.vue` consumers; `items` shape `{id?, name}` consistent between controller validation, update diffing logic, and both Vue forms.
