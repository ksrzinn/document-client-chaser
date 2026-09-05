# Client Management (Task 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authenticated business user create, list, view, edit, and archive their own `Client` records, with hard server-side tenant isolation and no destructive delete.

**Architecture:** A single `ClientController` with `index/create/store/show/edit/update` (via `Route::resource(...)->except(['destroy'])`) plus one extra `archive` POST route. Every lookup goes through `$request->user()->clients()->findOrFail($client)` — never a bare `Client::findOrFail()`. Validation is inline `$request->validate()` (rules are 2 lines, identical between store/update — a Form Request would be pure duplication here). Frontend is 4 new Inertia/Vue pages under `resources/js/Pages/Clients/`, reusing existing Breeze components (`TextInput`, `InputLabel`, `InputError`, `PrimaryButton`, `SecondaryButton`) and `AuthenticatedLayout`.

**Tech Stack:** Laravel 13, Inertia.js, Vue 3 (Options-free `<script setup>`), Pest, PostgreSQL (sqlite in-memory for tests), Tailwind.

**Spec:** `PRODUCT.md` §9 (Client Management), §20 (Business/User Isolation), `CLAUDE.md` (project root) — multi-tenancy, mass-assignment, and validation sections apply in full.

## Global Constraints

- Every client lookup MUST be scoped via `$request->user()->clients()->findOrFail(...)` — never `Client::findOrFail($id)`.
- Cross-tenant access returns 404, never 403 (no existence leak).
- `user_id` must never be settable from request input, directly or via mass assignment.
- Do not modify the `clients` migration or `database/factories/ClientFactory.php`.
- Do not implement search, filters, pagination beyond a simple `paginate()`, delete, bulk ops, or any Task 4+ feature (document requests, uploads, tokens, emails).
- No new packages. No Form Request classes for this task (see rationale above) — plain `$request->validate()`.
- All new routes live under `middleware('auth')` in `routes/web.php` (this app has no email verification wired up — do not add `verified`).

---

### Task 1: Remove `user_id` from Client's mass-assignable attribute

**Files:**
- Modify: `app/Models/Client.php:12` (the `#[Fillable(...)]` attribute)
- Test: `tests/Feature/Domain/ClientTest.php`

**Interfaces:**
- Produces: `Client` model whose fillable set is `['name', 'email', 'archived_at']` — `user_id` is no longer mass-assignable from any array, closing the spoofing vector structurally (relationship `create()` still sets the FK directly, bypassing fillable, so `$user->clients()->create([...])` keeps working).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Domain/ClientTest.php`:

```php
it('does not allow user_id to be mass assigned', function () {
    $client = new Client();

    expect($client->isFillable('user_id'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test --filter="does not allow user_id to be mass assigned"`
Expected: FAIL (currently fillable includes `user_id`)

- [ ] **Step 3: Fix the model**

In `app/Models/Client.php`, change:

```php
#[Fillable(['user_id', 'name', 'email', 'archived_at'])]
```

to:

```php
#[Fillable(['name', 'email', 'archived_at'])]
```

- [ ] **Step 4: Run test to verify it passes, and full existing suite still green**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test`
Expected: PASS, all 41 tests green (40 existing + 1 new)

- [ ] **Step 5: Commit**

```bash
git add app/Models/Client.php tests/Feature/Domain/ClientTest.php
git commit -m "fix: remove user_id from Client mass-assignable attributes

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 2: Client routes

**Files:**
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: nothing new (references `App\Http\Controllers\ClientController`, created in Task 3 — routes can be added first since Laravel resolves controllers lazily at request time, not at route-registration time).
- Produces: named routes `clients.index`, `clients.create`, `clients.store`, `clients.show`, `clients.edit`, `clients.update`, `clients.archive` — Task 3+ tests reference these names via `route(...)`.

- [ ] **Step 1: Add the resource + archive route**

In `routes/web.php`, add the import and route group:

```php
use App\Http\Controllers\ClientController;
```

Below the existing `Route::get('/dashboard', ...)` block, add:

```php
Route::middleware('auth')->group(function () {
    Route::resource('clients', ClientController::class)->except(['destroy']);
    Route::post('clients/{client}/archive', [ClientController::class, 'archive'])
        ->name('clients.archive');
});
```

- [ ] **Step 2: Verify routes register (will 500 until controller exists — that's expected and checked in Task 3)**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan route:list --name=clients`
Expected: command errors with "Class ClientController does not exist" — confirms routes are wired to the right (not-yet-created) class name. This is the expected state at end of this task.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat: add client resource routes

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 3: ClientController + tenant-isolation and CRUD feature tests

This is the core task. Tests are written first (TDD), then the controller is implemented to make them pass.

**Files:**
- Create: `app/Http/Controllers/ClientController.php`
- Create: `tests/Feature/Http/ClientControllerTest.php`

**Interfaces:**
- Consumes: `Client` model (Task 1), routes `clients.*` (Task 2).
- Produces: `ClientController` with public actions `index(Request)`, `create()`, `store(Request)`, `show(Request, string $client)`, `edit(Request, string $client)`, `update(Request, string $client)`, `archive(Request, string $client)`. Each renders an Inertia component under `Clients/{Index,Create,Edit,Show}` (built in Tasks 4–5) or redirects to `route('clients.index')` / `route('clients.show', $client)`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/ClientControllerTest.php`:

```php
<?php

use App\Models\Client;
use App\Models\User;

// --- Normal CRUD ---

it('lets an authenticated user create a client', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('clients', [
        'user_id' => $user->id,
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);
});

it('lets an authenticated user view their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('clients.show', $client));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients/Show')
        ->where('client.id', $client->id)
        ->where('client.name', $client->name)
    );
});

it('lets an authenticated user edit their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->put(route('clients.update', $client), [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);
});

it('lets an authenticated user archive their client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->post(route('clients.archive', $client));

    $response->assertRedirect();
    expect($client->fresh()->archived_at)->not->toBeNull();
});

it('lets an authenticated user list their clients', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $response = $this->actingAs($user)->get(route('clients.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Clients/Index')
        ->has('clients.data', 1)
        ->where('clients.data.0.id', $client->id)
    );
});

// --- Ownership on create (mass-assignment / spoofing) ---

it('ignores a spoofed user_id and assigns the client to the authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->post(route('clients.store'), [
        'name' => 'Malicious Client',
        'email' => 'client@example.com',
        'user_id' => $userB->id,
    ]);

    $this->assertDatabaseHas('clients', [
        'name' => 'Malicious Client',
        'user_id' => $userA->id,
    ]);
    $this->assertDatabaseMissing('clients', [
        'name' => 'Malicious Client',
        'user_id' => $userB->id,
    ]);
});

// --- Tenant isolation ---

it('lists only the authenticated users clients, never another tenants', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientA = Client::factory()->for($userA)->create();
    $clientB = Client::factory()->for($userB)->create();

    $response = $this->actingAs($userA)->get(route('clients.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('Clients/Index')
        ->has('clients.data', 1)
        ->where('clients.data.0.id', $clientA->id)
    );
});

it('returns 404 when a user tries to view another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->get(route('clients.show', $clientB))
        ->assertNotFound();
});

it('returns 404 when a user tries to edit another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->get(route('clients.edit', $clientB))
        ->assertNotFound();
});

it('returns 404 when a user tries to update another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->put(route('clients.update', $clientB), [
        'name' => 'Hacked',
        'email' => 'hacked@example.com',
    ])->assertNotFound();

    expect($clientB->fresh()->name)->not->toBe('Hacked');
});

it('returns 404 when a user tries to archive another tenants client', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $clientB = Client::factory()->for($userB)->create();

    $this->actingAs($userA)->post(route('clients.archive', $clientB))
        ->assertNotFound();

    expect($clientB->fresh()->archived_at)->toBeNull();
});

it('redirects unauthenticated users away from every client route', function () {
    $client = Client::factory()->create();

    $this->get(route('clients.index'))->assertRedirect(route('login'));
    $this->get(route('clients.create'))->assertRedirect(route('login'));
    $this->post(route('clients.store'), [])->assertRedirect(route('login'));
    $this->get(route('clients.show', $client))->assertRedirect(route('login'));
    $this->get(route('clients.edit', $client))->assertRedirect(route('login'));
    $this->put(route('clients.update', $client), [])->assertRedirect(route('login'));
    $this->post(route('clients.archive', $client))->assertRedirect(route('login'));
});

// --- Validation ---

it('rejects client creation with a missing name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'email' => 'jane@example.com',
    ])->assertInvalid(['name']);
});

it('rejects client creation with a missing email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
    ])->assertInvalid(['email']);
});

it('rejects client creation with an invalid email format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
        'email' => 'not-an-email',
    ])->assertInvalid(['email']);
});

it('rejects client creation with a name over the max length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => str_repeat('a', 256),
        'email' => 'jane@example.com',
    ])->assertInvalid(['name']);
});

it('rejects client creation with an email over the max length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('clients.store'), [
        'name' => 'Jane Doe',
        'email' => str_repeat('a', 250).'@example.com',
    ])->assertInvalid(['email']);
});

it('allows two different tenants to have clients with the same email', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    Client::factory()->for($userA)->create(['email' => 'shared@example.com']);

    $this->actingAs($userB)->post(route('clients.store'), [
        'name' => 'Shared Email Client',
        'email' => 'shared@example.com',
    ])->assertRedirect();

    $this->assertDatabaseHas('clients', [
        'user_id' => $userB->id,
        'email' => 'shared@example.com',
    ]);
});

// --- Archive idempotency ---

it('does not error or change the timestamp when archiving an already-archived client', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('clients.archive', $client));
    $firstArchivedAt = $client->fresh()->archived_at;

    $this->actingAs($user)->post(route('clients.archive', $client))
        ->assertRedirect();
    $secondArchivedAt = $client->fresh()->archived_at;

    expect($secondArchivedAt->equalTo($firstArchivedAt))->toBeTrue();
});

it('keeps an archived client in the database, not deleted', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    $this->actingAs($user)->post(route('clients.archive', $client));

    $this->assertDatabaseHas('clients', ['id' => $client->id]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test tests/Feature/Http/ClientControllerTest.php`
Expected: FAIL — `ClientController` does not exist (route registration error).

- [ ] **Step 3: Implement the controller**

Create `app/Http/Controllers/ClientController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $clients = $request->user()->clients()
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $client = $request->user()->clients()->create($validated);

        return redirect()->route('clients.show', $client);
    }

    public function show(Request $request, string $client): Response
    {
        $client = $request->user()->clients()->findOrFail($client);

        return Inertia::render('Clients/Show', [
            'client' => $client,
        ]);
    }

    public function edit(Request $request, string $client): Response
    {
        $client = $request->user()->clients()->findOrFail($client);

        return Inertia::render('Clients/Edit', [
            'client' => $client,
        ]);
    }

    public function update(Request $request, string $client): RedirectResponse
    {
        $client = $request->user()->clients()->findOrFail($client);

        $validated = $this->validated($request);

        $client->update($validated);

        return redirect()->route('clients.show', $client);
    }

    public function archive(Request $request, string $client): RedirectResponse
    {
        $client = $request->user()->clients()->findOrFail($client);

        if ($client->archived_at === null) {
            $client->update(['archived_at' => now()]);
        }

        return redirect()->route('clients.show', $client);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test tests/Feature/Http/ClientControllerTest.php`
Expected: all tests PASS (except `index`/`show` Inertia-page tests, which will fail until the `.vue` pages exist in Task 4/5 — resolvePageComponent runs server-side via SSR? No: Inertia's `assertInertia` only inspects the JSON payload, it never resolves the `.vue` file. Test-runner does not require the Vue files to exist. Confirm this in the actual run — if any test fails because of a missing component file, note it and proceed to Task 4/5 before re-running.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ClientController.php tests/Feature/Http/ClientControllerTest.php
git commit -m "feat: add ClientController with tenant-scoped CRUD and archive

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 4: Create and Edit Vue pages

**Files:**
- Create: `resources/js/Pages/Clients/Create.vue`
- Create: `resources/js/Pages/Clients/Edit.vue`

**Interfaces:**
- Consumes: `route('clients.store')`, `route('clients.update', client)`, `route('clients.index')`; `AuthenticatedLayout`, `InputLabel`, `TextInput`, `InputError`, `PrimaryButton`, `SecondaryButton` (all pre-existing).
- Produces: nothing consumed by later tasks — these are leaf pages.

- [ ] **Step 1: Create `resources/js/Pages/Clients/Create.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
});

const submit = () => {
    form.post(route('clients.store'));
};
</script>

<template>
    <Head title="New Client" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                New Client
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.name"
                                required
                                autofocus
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="email" value="Email" />
                            <TextInput
                                id="email"
                                type="email"
                                class="mt-1 block w-full"
                                v-model="form.email"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div class="mt-4 flex items-center justify-end gap-4">
                            <Link :href="route('clients.index')">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>

                            <PrimaryButton
                                :class="{ 'opacity-25': form.processing }"
                                :disabled="form.processing"
                            >
                                Create Client
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Create `resources/js/Pages/Clients/Edit.vue`**

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
    client: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.client.name,
    email: props.client.email,
});

const submit = () => {
    form.put(route('clients.update', props.client.id));
};
</script>

<template>
    <Head title="Edit Client" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit Client
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.name"
                                required
                                autofocus
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="email" value="Email" />
                            <TextInput
                                id="email"
                                type="email"
                                class="mt-1 block w-full"
                                v-model="form.email"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div class="mt-4 flex items-center justify-end gap-4">
                            <Link :href="route('clients.show', props.client.id)">
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

- [ ] **Step 3: Run backend test suite (no frontend test runner in this project — confirm nothing broke)**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test`
Expected: all tests still PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Clients/Create.vue resources/js/Pages/Clients/Edit.vue
git commit -m "feat: add client create and edit Inertia pages

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 5: Index and Show Vue pages + nav link

**Files:**
- Create: `resources/js/Pages/Clients/Index.vue`
- Create: `resources/js/Pages/Clients/Show.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

**Interfaces:**
- Consumes: `clients` paginator prop shaped `{ data: [{id, name, email, archived_at}], links: [...] }` (Laravel's default `paginate()` JSON shape, as rendered by Inertia), `client` prop shaped `{id, name, email, archived_at}` — both produced by `ClientController` (Task 3).

- [ ] **Step 1: Create `resources/js/Pages/Clients/Index.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    clients: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Clients
                </h2>
                <Link :href="route('clients.create')">
                    <PrimaryButton type="button">New Client</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="client in clients.data" :key="client.id">
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ client.name }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ client.email }}</td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ client.archived_at ? 'Archived' : 'Active' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                    <Link :href="route('clients.show', client.id)" class="text-indigo-600 hover:text-indigo-900">
                                        View
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="clients.data.length === 0">
                                <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No clients yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Create `resources/js/Pages/Clients/Show.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const archive = () => {
    router.post(route('clients.archive', props.client.id));
};
</script>

<template>
    <Head :title="client.name" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ client.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Name</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Email</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ client.email }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Status</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ client.archived_at ? 'Archived' : 'Active' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex items-center gap-4">
                        <Link :href="route('clients.edit', client.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="!client.archived_at"
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

- [ ] **Step 3: Add a nav link to `resources/js/Layouts/AuthenticatedLayout.vue`**

Two edits to the existing file:

1. In the desktop nav (`<div class="hidden space-x-8 ...">` block, right after the `Dashboard` `NavLink`):

```vue
                                <NavLink
                                    :href="route('clients.index')"
                                    :active="route().current('clients.*')"
                                >
                                    Clients
                                </NavLink>
```

2. In the responsive nav (right after the `Dashboard` `ResponsiveNavLink`):

```vue
                        <ResponsiveNavLink
                            :href="route('clients.index')"
                            :active="route().current('clients.*')"
                        >
                            Clients
                        </ResponsiveNavLink>
```

- [ ] **Step 4: Run full backend test suite**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test`
Expected: all tests PASS (this now includes the `Index`/`Show`-referencing tests from Task 3, and the model test from Task 1 — full suite should be 40 original + 1 (Task 1) + 18 (Task 3) = 59 passing, 0 failing).

- [ ] **Step 5: Build the frontend assets to confirm no Vue/import errors**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html node:24-alpine sh -c "npm ci && npm run build"`
Expected: build succeeds with no errors (this validates Vue syntax/imports; it's the closest thing to a UI smoke test available without a live browser in this environment).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Clients/Index.vue resources/js/Pages/Clients/Show.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat: add client index/show pages and nav link

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 6: Final verification pass

**Files:** none (verification only)

**Interfaces:** none — this task confirms the whole feature works together.

- [ ] **Step 1: Run the complete backend test suite one more time**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test`
Expected: 100% PASS, no skipped/failed.

- [ ] **Step 2: Run `php artisan route:list --name=clients` and confirm the 7 expected routes exist**

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan route:list --name=clients`
Expected: `clients.index` (GET), `clients.create` (GET), `clients.store` (POST), `clients.show` (GET), `clients.edit` (GET), `clients.update` (PUT/PATCH), `clients.archive` (POST) — all under `auth` middleware.

- [ ] **Step 3: Grep for any remaining unscoped Client lookup**

Run: `grep -rn "Client::find\|Client::findOrFail" app/ --include="*.php"`
Expected: no matches (every lookup goes through `$request->user()->clients()`).

- [ ] **Step 4: Confirm Task 1/Task 2 (domain + auth) tests are untouched and still pass**

Already covered by Step 1 (full suite includes `tests/Feature/Auth/*` and `tests/Feature/Domain/*`), but explicitly re-run just those two directories to isolate:

Run: `docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/domain-foundation:/var/www/html -w /var/www/html --network client-document-chaser_default client-document-chaser-app:latest php artisan test tests/Feature/Auth tests/Feature/Domain`
Expected: all PASS, unchanged from the pre-Task-3 baseline (40 tests + 1 from Task 1 = 41).

- [ ] **Step 5: No commit needed — this task is verification-only.**

---

## Self-Review Notes

- **Spec coverage:** PRODUCT.md §9 fields (name, email, archive not delete) — Task 1/3. §20 tenant isolation — Task 3 tests (cross-tenant 404s, spoofed `user_id`, list scoping, unauth redirects). UI (create/list/show/edit/archive actions) — Tasks 4–5. Validation — Task 3. No unique-email constraint — explicitly tested (cross-tenant same email allowed). No delete route — confirmed absent (`->except(['destroy'])`).
- **Out-of-scope check:** no document requests, uploads, tokens, emails, search, filters, pagination beyond `paginate(15)`, or Form Request classes were added — consistent with Global Constraints.
- **Type/name consistency:** `clients.data` (paginator shape) used consistently in Task 3 tests and Task 5 `Index.vue`; `client` prop (singular object) used consistently in Task 3 tests (`show`/`edit`) and Task 4/5 `Edit.vue`/`Show.vue`.
