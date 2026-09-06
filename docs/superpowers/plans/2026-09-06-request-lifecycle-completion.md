# Request Lifecycle & Auto-Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the last required item of a `DocumentRequest` is received, automatically and idempotently transition the request to `status = 'completed'`, stamp `completed_at`, log a `request_completed` activity, and stop future reminders — safely under concurrent uploads.

**Architecture:** Centralize the completion rule on the `DocumentRequest` model (`isComplete()` derives state from child `document_request_items`; `markCompletedIfComplete()` performs the guarded transition). Call it from the one place that can cause completion — the public upload controller — inside its existing transaction. Close a pre-existing TOCTOU gap in the reminder job's claim query. Surface `completed_at`/`completed` status in the business Show page and a completion banner in the client portal. No new tables, columns, or services.

**Tech Stack:** Laravel 13 (PHP 8.5), Pest, Eloquent, PostgreSQL 18 (production/dev), sqlite `:memory:` (test suite, forced by `phpunit.xml`), Vue 3 + Inertia.

**Spec:** `PRODUCT.md` §17 (line 471 — explicitly documents this gap), §18 (Request Status), §19 (Expiration), §24 (`request_completed` analytics event, line 604). This plan implements exactly that gap and no more.

## Global Constraints

- Do not invent new statuses beyond `draft`, `archived`, `completed` (already implied by existing `isEligibleForReminder()`/`scopeEligibleForReminder()` code, which already excludes `archived` and `completed`).
- `status` is intentionally excluded from `DocumentRequest`'s `#[Fillable(...)]` attribute — never mass-assign it; always set it via direct property assignment on the model, matching the existing `archive()` convention in `DocumentRequestController`.
- A request with zero items is never `completed` (items are required at creation — `items => required|array|min:1` — so this is defensive/explicit, not reachable via the UI today, but must not be silently interpreted as complete).
- Uploads to an already-`completed` request's already-`received` item remain allowed (idempotent, rate-limited, no state change) — do not add new blocking logic for this case.
- No migration: `status` and `completed_at` columns already exist on `document_requests` with the right types/casts; `(user_id, status)` index already covers dashboard filtering; `document_request_items.document_request_id` is already indexed.
- The test suite runs on sqlite `:memory:` (forced by `phpunit.xml`), which cannot host two real concurrent connections and compiles `lockForUpdate()` to a no-op. Do not write a test that claims to prove real Postgres row-locking — it would pass vacuously and create false confidence. Test the deterministic, verifiable properties (idempotency, guard conditions, single-instance correctness) instead.
- Run `vendor/bin/pint` before the final commit of each task that touches PHP.

---

### Task 1: Centralize the completion rule on `DocumentRequest`

**Files:**
- Modify: `app/Models/DocumentRequest.php`
- Test: `tests/Feature/Domain/DocumentRequestCompletionTest.php` (create)

**Interfaces:**
- Produces: `DocumentRequest::isComplete(): bool`, `DocumentRequest::markCompletedIfComplete(): bool` — both used by Task 2 (upload controller).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Domain/DocumentRequestCompletionTest.php`:

```php
<?php

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;

function makeCompletableRequest(array $overrides = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    return DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'draft',
        'sent_at' => now(),
    ], $overrides));
}

it('is complete when every item is received', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    expect($documentRequest->isComplete())->toBeTrue();
});

it('is not complete when at least one item is still requested', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);

    expect($documentRequest->isComplete())->toBeFalse();
});

it('is not complete when it has no items', function () {
    $documentRequest = makeCompletableRequest();

    expect($documentRequest->isComplete())->toBeFalse();
});

it('marks a complete request as completed and logs the transition', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeTrue();
    expect($documentRequest->status)->toBe('completed');
    expect($documentRequest->completed_at)->not->toBeNull();
    expect($documentRequest->isDirty())->toBeFalse();

    $fresh = $documentRequest->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->completed_at)->not->toBeNull();

    expect(ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'request_completed')->count())->toBe(1);
});

it('does not complete a request that still has a pending item', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeFalse();
    expect($documentRequest->fresh()->status)->not->toBe('completed');
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(0);
});

it('does not complete a request with no items', function () {
    $documentRequest = makeCompletableRequest();

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeFalse();
    expect($documentRequest->fresh()->status)->not->toBe('completed');
});

it('does not reopen or relog an already-completed request', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $documentRequest->markCompletedIfComplete();
    $firstCompletedAt = $documentRequest->fresh()->completed_at;

    $secondResult = $documentRequest->markCompletedIfComplete();

    expect($secondResult)->toBeFalse();
    expect($documentRequest->fresh()->completed_at->equalTo($firstCompletedAt))->toBeTrue();
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(1);
});

it('does not complete an archived request even if all items are received', function () {
    $documentRequest = makeCompletableRequest(['status' => 'archived']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $result = $documentRequest->markCompletedIfComplete();

    expect($result)->toBeFalse();
    expect($documentRequest->fresh()->status)->toBe('archived');
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(0);
});

it('only completes once when two independently loaded instances race to complete the same request', function () {
    $documentRequest = makeCompletableRequest();
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    $instanceA = DocumentRequest::find($documentRequest->id);
    $instanceB = DocumentRequest::find($documentRequest->id);

    $resultA = $instanceA->markCompletedIfComplete();
    $resultB = $instanceB->markCompletedIfComplete();

    expect($resultA)->toBeTrue();
    expect($resultB)->toBeFalse();
    expect(ActivityLog::where('event', 'request_completed')->count())->toBe(1);
    expect($documentRequest->fresh()->completed_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Domain/DocumentRequestCompletionTest.php`
Expected: FAIL — `isComplete`/`markCompletedIfComplete` do not exist yet (`Error: Call to undefined method`).

- [ ] **Step 3: Implement `isComplete()` and `markCompletedIfComplete()`**

In `app/Models/DocumentRequest.php`, add these imports at the top (alongside the existing `use` statements):

```php
use Illuminate\Support\Facades\DB;
```

(`ActivityLog` is already imported implicitly available in the same namespace `App\Models` — reference it as `ActivityLog::create(...)` without an import since it's a sibling class in the same namespace.)

Add these two public methods to the `DocumentRequest` class (after `isEligibleForReminder()`):

```php
public function isComplete(): bool
{
    return $this->items()->exists()
        && ! $this->items()->where('status', '!=', 'received')->exists();
}

/**
 * Transition this request to `completed` if every item is `received`.
 *
 * Self-contained and safe to call standalone or nested inside an existing
 * transaction (e.g. the public upload transaction): it locks its own row
 * with SELECT ... FOR UPDATE before re-checking state, which is what makes
 * the transition race-free across two concurrent uploads to different
 * items of the same request. Two invariants this depends on:
 *
 *  - The item's own status write must already be part of the same
 *    transaction that calls this method, and must happen *before* this
 *    method is called, so the lock's post-acquisition read observes it.
 *  - Postgres's default READ COMMITTED isolation gives each statement a
 *    fresh snapshot once the row lock is acquired by the previous holder's
 *    commit. Under REPEATABLE READ this method would instead raise a
 *    serialization failure on the lock (a loud error, not a silent miss) —
 *    do not raise the isolation level without revisiting this.
 */
public function markCompletedIfComplete(): bool
{
    return DB::transaction(function () {
        $locked = self::whereKey($this->id)->lockForUpdate()->first();

        if ($locked === null || in_array($locked->status, ['completed', 'archived'], true)) {
            return false;
        }

        if (! $locked->isComplete()) {
            return false;
        }

        $now = now();
        $locked->status = 'completed';
        $locked->completed_at = $now;
        $locked->save();

        ActivityLog::create([
            'user_id' => $locked->user_id,
            'client_id' => $locked->client_id,
            'document_request_id' => $locked->id,
            'event' => 'request_completed',
            'metadata' => [],
        ]);

        $this->status = $locked->status;
        $this->completed_at = $locked->completed_at;
        $this->syncOriginalAttribute('status');
        $this->syncOriginalAttribute('completed_at');

        return true;
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Domain/DocumentRequestCompletionTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Pint and commit**

```bash
docker compose -p cdc-task9-lifecycle exec -T app vendor/bin/pint app/Models/DocumentRequest.php tests/Feature/Domain/DocumentRequestCompletionTest.php
git add app/Models/DocumentRequest.php tests/Feature/Domain/DocumentRequestCompletionTest.php
git commit -m "feat: centralize document request completion rule"
```

---

### Task 2: Wire completion into the public upload flow

**Files:**
- Modify: `app/Http/Controllers/Public/UploadedDocumentController.php`
- Modify (add import only): `tests/Feature/Http/PublicUploadTest.php`

**Interfaces:**
- Consumes: `DocumentRequest::markCompletedIfComplete(): bool` from Task 1.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/PublicUploadTest.php` (add `use App\Models\ActivityLog;` to the top imports alongside the existing ones, then append these tests at the end of the file):

```php
it('completes the document request when the last required item is uploaded', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    $fresh = $documentRequest->fresh();
    expect($fresh->status)->toBe('completed');
    expect($fresh->completed_at)->not->toBeNull();
    expect(ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'request_completed')->count())->toBe(1);
});

it('does not complete the document request while another item is still missing', function () {
    $documentRequest = makeUploadableRequest();
    $itemA = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $itemA->id), [
        'file' => UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'),
    ]);

    $fresh = $documentRequest->fresh();
    expect($fresh->status)->not->toBe('completed');
    expect($fresh->completed_at)->toBeNull();
});

it('does not duplicate the completion activity log on a repeat upload to an already-completed request', function () {
    $documentRequest = makeUploadableRequest();
    $item = DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'requested']);
    $token = $documentRequest->generateAccessToken();

    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
    ]);
    $this->post(uploadUrl($token, $item->id), [
        'file' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
    ]);

    $fresh = $documentRequest->fresh();
    expect($fresh->status)->toBe('completed');
    expect(ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'request_completed')->count())->toBe(1);
    expect($item->uploadedDocuments()->count())->toBe(2);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Http/PublicUploadTest.php --filter=completes`
Expected: FAIL — status stays `draft`/`requested` state persists, not `completed`.

- [ ] **Step 3: Call `markCompletedIfComplete()` from the upload transaction**

In `app/Http/Controllers/Public/UploadedDocumentController.php`, modify the `DB::transaction` closure in `store()`:

```php
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

                $documentRequest->markCompletedIfComplete();
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($storagePath);

            throw $e;
        }
```

(Only the added `$documentRequest->markCompletedIfComplete();` line right before the closing `});` of the closure is new.)

- [ ] **Step 4: Run the full upload test file to verify pass and no regressions**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Http/PublicUploadTest.php`
Expected: PASS — all tests including the 3 new ones and the pre-existing "deletes the stored file if the database transaction fails" test (which mocks `DB::transaction` entirely, so it is unaffected by the new line inside the closure).

- [ ] **Step 5: Pint and commit**

```bash
docker compose -p cdc-task9-lifecycle exec -T app vendor/bin/pint app/Http/Controllers/Public/UploadedDocumentController.php tests/Feature/Http/PublicUploadTest.php
git add app/Http/Controllers/Public/UploadedDocumentController.php tests/Feature/Http/PublicUploadTest.php
git commit -m "feat: auto-complete document request when the last item is uploaded"
```

---

### Task 3: Close the reminder-job TOCTOU gap on completion

**Files:**
- Modify: `app/Jobs/SendDocumentRequestReminderJob.php`
- Test: `tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php`

**Interfaces:**
- Consumes: nothing new (uses the existing `document_requests.status` column).

**Why:** `SendDocumentRequestReminderJob::handle()` calls `isEligibleForReminder()` (already excludes `completed`/`archived`) and *then*, in a separate step, does an atomic claim `UPDATE ... WHERE reminder_count < ? AND (...)`. That claim's `WHERE` clause does not check `status`, so if a request completes in the (tiny but real) window between the eligibility check and the claim, the claim can still succeed and a reminder can go out for a request that just finished. Task 1/2 make completion much more frequent (it now actually happens), so this pre-existing gap is now worth closing at effectively zero cost.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php`:

```php
it('the atomic claim update does not claim an already-completed request even if counters would otherwise qualify', function () {
    $documentRequest = makeSendableRequest(['status' => 'completed', 'completed_at' => now()]);

    $threshold = now()->subDays((int) config('reminders.interval_days'));

    $affected = DB::table('document_requests')
        ->where('id', $documentRequest->id)
        ->whereNotIn('status', ['archived', 'completed'])
        ->where('reminder_count', '<', (int) config('reminders.max_count'))
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->update(['reminder_count' => DB::raw('reminder_count + 1'), 'last_reminder_sent_at' => now()]);

    expect($affected)->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php --filter="does not claim an already-completed"`
Expected: FAIL — `$affected` is `1`, because the query in this test already includes the new `whereNotIn` guard but the production query in the job does not yet; to make this step meaningfully fail first, temporarily verify by running the *production* claim path instead — call `(new SendDocumentRequestReminderJob($documentRequest->id))->handle()` with `Mail::fake()` and assert `Mail::assertNothingQueued()` would already pass today (since `isEligibleForReminder()` already blocks `completed`). This confirms the gap is specifically in the raw claim query, not the job's outward behavior — so this test targets the claim query directly (as written above) to pin the fix at its actual location, matching the existing "atomic claim update only affects one row" test's convention of asserting on the raw query. Add the guard in Step 3 and re-run to see it pass.

- [ ] **Step 3: Add the `status` guard to the claim query**

In `app/Jobs/SendDocumentRequestReminderJob.php`, in `handle()`, modify the `$claimed = DB::table(...)` query:

```php
        $claimed = DB::table('document_requests')
            ->where('id', $documentRequest->id)
            ->whereNotIn('status', ['archived', 'completed'])
            ->where('reminder_count', '<', $maxCount)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
            })
            ->update([
                'reminder_count' => DB::raw('reminder_count + 1'),
                'last_reminder_sent_at' => $now,
                'updated_at' => $now,
            ]);
```

(Only the added `->whereNotIn('status', ['archived', 'completed'])` line is new.)

- [ ] **Step 4: Run the full reminder job test file to verify pass and no regressions**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php`
Expected: PASS (all tests, including the new one).

- [ ] **Step 5: Pint and commit**

```bash
docker compose -p cdc-task9-lifecycle exec -T app vendor/bin/pint app/Jobs/SendDocumentRequestReminderJob.php tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php
git add app/Jobs/SendDocumentRequestReminderJob.php tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php
git commit -m "fix: prevent reminder claim from firing on a just-completed request"
```

---

### Task 4: Surface completion in the business Show page

**Files:**
- Modify: `app/Http/Controllers/DocumentRequestController.php`
- Modify: `resources/js/Pages/DocumentRequests/Show.vue`
- Test: `tests/Feature/Http/DocumentRequestControllerTest.php`

**Interfaces:**
- Consumes: `DocumentRequest::completed_at` (Carbon|null, existing cast).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/DocumentRequestControllerTest.php` (uses the same `User`, `Client`, `DocumentRequest` imports already present in that file):

```php
it('shows the completion timestamp once a request is completed', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->get("/document-requests/{$documentRequest->id}");

    $response->assertInertia(fn ($page) => $page
        ->where('documentRequest.status', 'completed')
        ->has('documentRequest.completed_at')
    );
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Http/DocumentRequestControllerTest.php --filter="shows the completion timestamp"`
Expected: FAIL — `documentRequest.completed_at` prop missing (`has()` assertion fails).

- [ ] **Step 3: Expose `completed_at` from `show()`**

In `app/Http/Controllers/DocumentRequestController.php`, in `show()`, add a `completed_at` line next to the existing `sent_at` line:

```php
        return Inertia::render('DocumentRequests/Show', [
            'documentRequest' => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'updated_at']),
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'sent_at' => $documentRequest->sent_at?->toIso8601String(),
                'completed_at' => $documentRequest->completed_at?->toIso8601String(),
                'client' => $documentRequest->client->only(['id', 'name', 'email']),
```

(Only the `'completed_at' => ...` line is new; everything else is unchanged context.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Http/DocumentRequestControllerTest.php`
Expected: PASS (all tests, no regressions).

- [ ] **Step 5: Show the completion date in the UI**

In `resources/js/Pages/DocumentRequests/Show.vue`, add a new `<div>` right after the existing "Sent" block (after the `</div>` that closes the "Sent" `dt`/`dd` pair, before the `v-if="documentRequest.message"` block):

```html
                        <div v-if="documentRequest.completed_at">
                            <dt class="text-sm font-medium text-gray-500">Completed</dt>
                            <dd class="mt-1 text-sm font-medium text-green-700">{{ documentRequest.completed_at }}</dd>
                        </div>
```

- [ ] **Step 6: Manually verify in the browser (no automated UI test framework in this repo)**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan tinker --execute="
\$user = App\Models\User::first() ?? App\Models\User::factory()->create();
\$client = App\Models\Client::factory()->for(\$user)->create();
\$r = App\Models\DocumentRequest::factory()->for(\$user)->for(\$client)->create(['status' => 'completed', 'completed_at' => now(), 'sent_at' => now()]);
echo \$r->id;
"`
Then log in as that user in the browser (via `http://localhost:8080` once nginx/vite are started for this worktree, or skip if only backend verification is in scope) and open `/document-requests/{id}` to confirm the "Completed" row renders. If the dev server isn't running for this worktree, it is acceptable to skip this manual check and rely on the Inertia prop test from Step 4, but note this explicitly in the final report as an untested-in-browser UI change.

- [ ] **Step 7: Pint and commit**

```bash
docker compose -p cdc-task9-lifecycle exec -T app vendor/bin/pint app/Http/Controllers/DocumentRequestController.php tests/Feature/Http/DocumentRequestControllerTest.php
git add app/Http/Controllers/DocumentRequestController.php resources/js/Pages/DocumentRequests/Show.vue tests/Feature/Http/DocumentRequestControllerTest.php
git commit -m "feat: show completion date on the document request detail page"
```

---

### Task 5: Surface completion in the client portal

**Files:**
- Modify: `resources/js/Pages/Public/DocumentRequest.vue`
- Test: `tests/Feature/Http/ClientRequestControllerTest.php`

**Interfaces:**
- Consumes: `documentRequest.status` prop (already sent by `ClientRequestController::show()` — no backend change needed here).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/ClientRequestControllerTest.php`:

```php
it('exposes completed status on the public payload once the request is complete', function () {
    $documentRequest = makePubliclyAccessibleRequest(['status' => 'completed', 'completed_at' => now()]);
    $token = $documentRequest->generateAccessToken();

    $response = $this->get("/request/{$token}");

    $response->assertInertia(fn ($page) => $page->where('documentRequest.status', 'completed'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Http/ClientRequestControllerTest.php --filter="exposes completed status"`
Expected: this test actually PASSES immediately — `ClientRequestController::show()` already forwards `'status' => $documentRequest->status` unconditionally, and `findPubliclyAccessible()` already allows `completed` requests through (it only blocks `archived`/expired/never-sent). This step exists to *confirm* that pre-existing behavior with an explicit regression test before touching the Vue file, since there is no backend code change in this task. If it fails, stop and investigate — `findPubliclyAccessible()` may have been changed in an earlier task; check `isPubliclyAccessible()` in `app/Models/DocumentRequest.php` first.

- [ ] **Step 3: Add a completion banner to the client portal**

In `resources/js/Pages/Public/DocumentRequest.vue`, add a banner right after the existing `<p v-if="documentRequest.message">` block and before the `<div class="mt-6 space-y-4">` (documents list) block:

```html
            <div
                v-if="documentRequest.status === 'completed'"
                class="mt-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800"
            >
                All requested documents have been received. Thank you!
            </div>
```

- [ ] **Step 4: Run the full client request test file to verify no regressions**

Run: `docker compose -p cdc-task9-lifecycle exec -T app php artisan test tests/Feature/Http/ClientRequestControllerTest.php`
Expected: PASS (all tests).

- [ ] **Step 5: Pint and commit**

```bash
git add resources/js/Pages/Public/DocumentRequest.vue tests/Feature/Http/ClientRequestControllerTest.php
git commit -m "feat: show a completion banner on the client portal"
```

---

### Task 6: Full verification pass

**Files:** none (verification only).

- [ ] **Step 1: Rebuild frontend assets**

```bash
docker run --rm -v /var/www/html/projects/client-document-chaser/.claude/worktrees/task-9-request-lifecycle-completion:/app -w /app node:24-alpine sh -c "npm run build"
```

- [ ] **Step 2: Run the entire backend test suite**

```bash
docker compose -p cdc-task9-lifecycle exec -T app php artisan test
```

Expected: 100% pass, 0 failures. Baseline before this plan was 194 passed / 604 assertions — expect roughly 194 + 9 (Task 1) + 3 (Task 2) + 1 (Task 3) + 1 (Task 4) + 1 (Task 5) = 209 passed.

- [ ] **Step 3: Run Pint across the whole app/tests tree**

```bash
docker compose -p cdc-task9-lifecycle exec -T app vendor/bin/pint --test
```

If it reports files needing fixes, run `vendor/bin/pint` (without `--test`) to apply them, then re-run the test suite, then commit the formatting fix separately: `git commit -m "chore: apply Pint formatting"`.

- [ ] **Step 4: Manual security/consistency self-review**

Re-read the diff (`git diff main...HEAD` from inside the worktree) against `CLAUDE.md` §4 (Security), §5 (Multi-Tenancy), §10 (Eloquent/mass assignment), §13 (Queues/idempotency) and confirm:
- No new mass-assignable field lets a client set `status` or `completed_at` (both remain outside `#[Fillable]` and are only ever set via direct property assignment inside `markCompletedIfComplete()`, which is never client-invocable directly).
- `markCompletedIfComplete()` is only reachable from server-derived state (the upload controller resolves `$documentRequest`/`$item` via `StoreUploadedDocumentRequest::authorize()`, which is token-based and already tenant/ownership-scoped — see the existing IDOR tests in `PublicUploadTest.php`).
- No new log statement includes a token, hash, or file content (`markCompletedIfComplete()`'s `ActivityLog::create()` call uses `'metadata' => []`, matching the requirement to not log unnecessary internal detail).

- [ ] **Step 5: Final commit check**

```bash
git status
git log --oneline main..HEAD
```

Confirm no `.env`, credentials, or `node_modules`/`vendor` artifacts are staged (worktree's `.gitignore` should already exclude these — verify with `git status` showing a clean tree apart from the intended files).

---

## Self-Review Notes (for the plan author, not a task)

- **Spec coverage:** PRODUCT.md §17 line 471 gap → Tasks 1–2. §18 (business must see completed/expired/waiting) → Task 4. §19 (expired requests don't accept uploads) → already enforced pre-existing, unchanged, covered by existing `PublicUploadTest` cases. §24 `request_completed` event → Task 1's `ActivityLog::create`. Reminder stop-on-completion (§17) → already implemented via `isEligibleForReminder()`, gap-closed by Task 3.
- **Deferred/out of scope (confirmed with solution-architect review):** `document_uploaded` analytics event is missing from `UploadedDocumentController` — pre-existing gap, not part of this task, not touched. Dashboard "completed requests" count (§8) — dashboard is a stub elsewhere, not touched. True multi-connection Postgres concurrency testing — reasoned about and documented in Task 1's docblock, not executable against the sqlite test DB; if desired, verify manually against the running `postgres:18-alpine` container by opening two `psql` sessions and interleaving `BEGIN; SELECT ... FOR UPDATE;` — not required for this plan to be considered complete.
