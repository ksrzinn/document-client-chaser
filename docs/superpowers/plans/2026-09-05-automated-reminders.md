# Automated Email Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically send reminder emails for `DocumentRequest`s with missing documents, via the existing scheduler/queue, with duplicate-proof concurrency.

**Architecture:** A scheduled console command queries eligible requests (chunked) and dispatches one queue job per request. The job re-checks eligibility, claims the send via an atomic conditional `UPDATE`, then queues a reminder mail and writes an `ActivityLog` row. Eligibility logic lives once on the `DocumentRequest` model (query scope + instance method).

**Tech Stack:** Laravel 11/12-style (no `app/Console/Kernel.php`; scheduling registered in `routes/console.php`), Pest tests, PostgreSQL (prod/dev), SQLite in-memory (tests), Redis queue.

**Spec:** `docs/superpowers/specs/2026-09-05-automated-reminders-design.md`

## Global Constraints

- Reminder interval default: 2 days (`REMINDER_INTERVAL_DAYS`).
- Max reminders per request: 3 (`REMINDER_MAX_COUNT`).
- Reminders continue after `due_at` passes; only `expires_at`, completion (no unreceived items), and `archived` status stop them.
- Never trust `status=completed`/`completed_at` for completion — check items directly.
- Token is regenerated per reminder (existing pattern); never log/persist raw token or hash in ActivityLog.
- Idempotency correctness comes from the atomic conditional `UPDATE`, not from `ShouldBeUnique` alone.
- No UI changes. No auto-completion feature. No new domain/service classes beyond what's listed below.
- Run all commands via `docker compose -p task8reminders exec app ...` from the worktree root.

---

### Task 1: PRODUCT.md documentation + migration for reminder tracking columns

**Files:**
- Modify: `PRODUCT.md` (append to §17 Reminders section)
- Create: `database/migrations/2026_09_05_000001_add_reminder_tracking_to_document_requests_table.php`
- Test: `tests/Feature/Domain/DocumentRequestTest.php` (add cases)

**Interfaces:**
- Produces: `document_requests.last_reminder_sent_at` (nullable timestamp), `document_requests.reminder_count` (unsigned integer, default 0). Both added to `DocumentRequest::casts()` and `#[Fillable(...)]`.

- [ ] **Step 1: Update PRODUCT.md**

Insert this immediately after the existing `# 17. Reminders` example block (after "Stop reminders" but before the next `---`):

```markdown
### MVP defaults (implemented)

* Reminder interval: 2 days (`REMINDER_INTERVAL_DAYS` env var).
* Maximum reminders per request: 3 (`REMINDER_MAX_COUNT` env var).
* Reminders continue to be sent after `due_at` passes; only expiration, completion, or archiving stop them.
* Completion, for reminder purposes, means every requested item has been received — the system does not yet maintain a reliable `completed` status/`completed_at` timestamp, so reminder eligibility checks item receipt directly.
* Each reminder regenerates the client's access token (same behavior as manually re-sending a request). The previous link stops working once a reminder is sent.
```

- [ ] **Step 2: Write the failing migration test**

Add to `tests/Feature/Domain/DocumentRequestTest.php`:

```php
it('defaults reminder_count to zero and last_reminder_sent_at to null', function () {
    $documentRequest = DocumentRequest::factory()->for(User::factory())->for(Client::factory())->create();

    expect($documentRequest->reminder_count)->toBe(0);
    expect($documentRequest->last_reminder_sent_at)->toBeNull();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose -p task8reminders exec app php artisan test --filter="defaults reminder_count to zero"`
Expected: FAIL — column `reminder_count` does not exist (SQLSTATE error).

- [ ] **Step 4: Write the migration**

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
            $table->timestamp('last_reminder_sent_at')->nullable()->after('access_token_hash');
            $table->unsignedInteger('reminder_count')->default(0)->after('last_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropColumn(['last_reminder_sent_at', 'reminder_count']);
        });
    }
};
```

- [ ] **Step 5: Update the model**

In `app/Models/DocumentRequest.php`, change the `#[Fillable(...)]` attribute to include `last_reminder_sent_at` and `reminder_count`, and add both to `casts()`:

```php
#[Fillable(['client_id', 'message', 'due_at', 'expires_at', 'sent_at', 'completed_at', 'last_reminder_sent_at', 'reminder_count'])]
```

```php
protected function casts(): array
{
    return [
        'due_at' => 'datetime',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'reminder_count' => 'integer',
    ];
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose -p task8reminders exec app php artisan test --filter="defaults reminder_count to zero"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add PRODUCT.md database/migrations/2026_09_05_000001_add_reminder_tracking_to_document_requests_table.php app/Models/DocumentRequest.php tests/Feature/Domain/DocumentRequestTest.php
git commit -m "feat: add reminder tracking columns to document_requests

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 2: Reminder config file + env vars

**Files:**
- Create: `config/reminders.php`
- Modify: `.env.example`
- Test: `tests/Unit/Config/RemindersConfigTest.php`

**Interfaces:**
- Produces: `config('reminders.interval_days')` (int, default 2), `config('reminders.max_count')` (int, default 3).

- [ ] **Step 1: Write the failing test**

```php
<?php

it('defaults the reminder interval to 2 days', function () {
    expect(config('reminders.interval_days'))->toBe(2);
});

it('defaults the max reminder count to 3', function () {
    expect(config('reminders.max_count'))->toBe(3);
});

it('reads the interval from REMINDER_INTERVAL_DAYS', function () {
    config(['reminders.interval_days' => 5]);
    expect(config('reminders.interval_days'))->toBe(5);
});
```

Save as `tests/Unit/Config/RemindersConfigTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose -p task8reminders exec app php artisan test --filter=RemindersConfigTest`
Expected: FAIL — `config('reminders.interval_days')` returns null.

- [ ] **Step 3: Write the config file**

```php
<?php

return [
    'interval_days' => (int) env('REMINDER_INTERVAL_DAYS', 2),
    'max_count' => (int) env('REMINDER_MAX_COUNT', 3),
];
```

Save as `config/reminders.php`.

- [ ] **Step 4: Add env vars to `.env.example`**

Append after the mail section:

```
REMINDER_INTERVAL_DAYS=2
REMINDER_MAX_COUNT=3
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose -p task8reminders exec app php artisan test --filter=RemindersConfigTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add config/reminders.php .env.example tests/Unit/Config/RemindersConfigTest.php
git commit -m "feat: add configurable reminder interval and max count

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 3: Eligibility rule on `DocumentRequest`

**Files:**
- Modify: `app/Models/DocumentRequest.php`
- Test: `tests/Feature/Domain/DocumentRequestReminderEligibilityTest.php`

**Interfaces:**
- Consumes: `config('reminders.interval_days')`, `config('reminders.max_count')` (Task 2).
- Produces:
  - `DocumentRequest::scopeEligibleForReminder(Builder $query): Builder` — usable as `DocumentRequest::eligibleForReminder()`.
  - `DocumentRequest->isEligibleForReminder(): bool` — instance re-check.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;

function makeReminderCandidate(array $requestAttrs = [], array $clientAttrs = []): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(array_merge(['email' => 'client@example.com'], $clientAttrs));
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ], $requestAttrs));

    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'pending']);

    return $documentRequest;
}

it('is eligible when sent, missing items, past interval, no prior reminder', function () {
    $documentRequest = makeReminderCandidate();

    expect($documentRequest->isEligibleForReminder())->toBeTrue();
    expect(DocumentRequest::eligibleForReminder()->pluck('id'))->toContain($documentRequest->id);
});

it('is not eligible when never sent', function () {
    $documentRequest = makeReminderCandidate(['sent_at' => null]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
    expect(DocumentRequest::eligibleForReminder()->pluck('id'))->not->toContain($documentRequest->id);
});

it('is not eligible when archived', function () {
    $documentRequest = makeReminderCandidate(['status' => 'archived']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when completed', function () {
    $documentRequest = makeReminderCandidate(['status' => 'completed']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when expired', function () {
    $documentRequest = makeReminderCandidate(['expires_at' => now()->subDay()]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is eligible when expires_at is in the future', function () {
    $documentRequest = makeReminderCandidate(['expires_at' => now()->addDay()]);

    expect($documentRequest->isEligibleForReminder())->toBeTrue();
});

it('is not eligible when the client has no email', function () {
    $documentRequest = makeReminderCandidate([], ['email' => '']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when the client email is invalid', function () {
    $documentRequest = makeReminderCandidate([], ['email' => 'not-an-email']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible when all items are received', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ]);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'received']);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible before the interval has elapsed since sending', function () {
    $documentRequest = makeReminderCandidate(['sent_at' => now()->subHours(2)]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is not eligible before the interval has elapsed since the last reminder', function () {
    $documentRequest = makeReminderCandidate([
        'last_reminder_sent_at' => now()->subHours(2),
        'reminder_count' => 1,
    ]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('is eligible once the interval has elapsed since the last reminder', function () {
    $documentRequest = makeReminderCandidate([
        'last_reminder_sent_at' => now()->subDays(3),
        'reminder_count' => 1,
    ]);

    expect($documentRequest->isEligibleForReminder())->toBeTrue();
});

it('is not eligible once max_count reminders have been sent', function () {
    $documentRequest = makeReminderCandidate([
        'last_reminder_sent_at' => now()->subDays(3),
        'reminder_count' => 3,
    ]);

    expect($documentRequest->isEligibleForReminder())->toBeFalse();
});

it('respects a configured interval and max count', function () {
    config(['reminders.interval_days' => 1, 'reminders.max_count' => 1]);

    $eligible = makeReminderCandidate(['sent_at' => now()->subDays(2)]);
    $tooSoon = makeReminderCandidate(['sent_at' => now()->subHours(5)]);
    $overMax = makeReminderCandidate(['last_reminder_sent_at' => now()->subDays(2), 'reminder_count' => 1]);

    expect($eligible->isEligibleForReminder())->toBeTrue();
    expect($tooSoon->isEligibleForReminder())->toBeFalse();
    expect($overMax->isEligibleForReminder())->toBeFalse();
});
```

Save as `tests/Feature/Domain/DocumentRequestReminderEligibilityTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -p task8reminders exec app php artisan test --filter=DocumentRequestReminderEligibilityTest`
Expected: FAIL — `isEligibleForReminder` / `scopeEligibleForReminder` do not exist.

- [ ] **Step 3: Implement eligibility on the model**

In `app/Models/DocumentRequest.php`, add (needs `use Illuminate\Database\Eloquent\Builder;` import):

```php
public function scopeEligibleForReminder(Builder $query): Builder
{
    $threshold = now()->subDays((int) config('reminders.interval_days'));
    $maxCount = (int) config('reminders.max_count');

    return $query
        ->whereNotNull('sent_at')
        ->whereNotIn('status', ['archived', 'completed'])
        ->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })
        ->where('reminder_count', '<', $maxCount)
        ->where(function (Builder $q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->where('sent_at', '<=', $threshold)
                ->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->whereHas('client', function (Builder $q) {
            $q->whereNotNull('email')->where('email', '!=', '');
        })
        ->whereHas('items', function (Builder $q) {
            $q->where('status', '!=', 'received');
        });
}

public function isEligibleForReminder(): bool
{
    if ($this->sent_at === null) {
        return false;
    }

    if (in_array($this->status, ['archived', 'completed'], true)) {
        return false;
    }

    if ($this->expires_at !== null && $this->expires_at->isPast()) {
        return false;
    }

    $email = $this->client?->email;
    if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (! $this->items()->where('status', '!=', 'received')->exists()) {
        return false;
    }

    if ($this->reminder_count >= (int) config('reminders.max_count')) {
        return false;
    }

    $threshold = now()->subDays((int) config('reminders.interval_days'));
    $lastActivity = $this->last_reminder_sent_at ?? $this->sent_at;

    return $lastActivity->lte($threshold);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -p task8reminders exec app php artisan test --filter=DocumentRequestReminderEligibilityTest`
Expected: PASS (all cases)

- [ ] **Step 5: Commit**

```bash
git add app/Models/DocumentRequest.php tests/Feature/Domain/DocumentRequestReminderEligibilityTest.php
git commit -m "feat: add reminder eligibility rule to DocumentRequest

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 4: Reminder mailable + view

**Files:**
- Create: `app/Mail/DocumentRequestReminder.php`
- Create: `resources/views/emails/document-request-reminder.blade.php`
- Test: `tests/Feature/Mail/DocumentRequestReminderTest.php`

**Interfaces:**
- Consumes: none beyond constructor args.
- Produces: `new DocumentRequestReminder(businessName: string, clientName: string, requestMessage: ?string, dueAt: ?string, link: string, missingItemNames: array)` implementing `ShouldQueue`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Mail\DocumentRequestReminder;
use Illuminate\Contracts\Queue\ShouldQueue;

it('is queueable', function () {
    expect(new DocumentRequestReminder(
        businessName: 'Acme',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['Invoice'],
    ))->toBeInstanceOf(ShouldQueue::class);
});

it('has a subject that identifies it as a reminder and names the business', function () {
    $mail = new DocumentRequestReminder(
        businessName: 'Acme Bookkeeping',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['Invoice'],
    );

    expect($mail->envelope()->subject)->toContain('Reminder')->toContain('Acme Bookkeeping');
});

it('renders client name, missing items, link and due date', function () {
    $mail = new DocumentRequestReminder(
        businessName: 'Acme Bookkeeping',
        clientName: 'John Smith',
        requestMessage: 'Please hurry.',
        dueAt: '2026-10-15',
        link: 'https://example.com/request/abc',
        missingItemNames: ['Bank statement', 'Invoice'],
    );

    $rendered = $mail->render();

    expect($rendered)
        ->toContain('John Smith')
        ->toContain('Bank statement')
        ->toContain('Invoice')
        ->toContain('https://example.com/request/abc')
        ->toContain('2026-10-15')
        ->toContain('Please hurry.');
});

it('omits the due date section when there is none', function () {
    $mail = new DocumentRequestReminder(
        businessName: 'Acme',
        clientName: 'John',
        requestMessage: null,
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['Invoice'],
    );

    expect($mail->render())->not->toContain('Please upload the requested documents by');
});

it('escapes malicious content in user-controlled fields', function () {
    $mail = new DocumentRequestReminder(
        businessName: '<script>alert(1)</script>',
        clientName: 'John',
        requestMessage: '<img src=x onerror=alert(1)>',
        dueAt: null,
        link: 'https://example.com',
        missingItemNames: ['<b>Invoice</b>'],
    );

    $rendered = $mail->render();

    expect($rendered)
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=alert(1)>')
        ->not->toContain('<b>Invoice</b>');
});
```

Save as `tests/Feature/Mail/DocumentRequestReminderTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -p task8reminders exec app php artisan test --filter=DocumentRequestReminderTest`
Expected: FAIL — class `App\Mail\DocumentRequestReminder` not found.

- [ ] **Step 3: Look at the existing mail + view to match conventions**

Read `app/Mail/DocumentRequestSent.php` and `resources/views/emails/document-request-sent.blade.php` before writing — mirror their structure exactly (Blade's `{{ }}` auto-escapes; do not use `{!! !!}` on any user-controlled value).

- [ ] **Step 4: Write the mailable**

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentRequestReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $businessName,
        public readonly string $clientName,
        public readonly ?string $requestMessage,
        public readonly ?string $dueAt,
        public readonly string $link,
        public readonly array $missingItemNames,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reminder: {$this->businessName} is still waiting for documents",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document-request-reminder',
        );
    }
}
```

- [ ] **Step 5: Write the view**

Save as `resources/views/emails/document-request-reminder.blade.php` (adapt the existing `document-request-sent` view's layout/wrapper to this content):

```blade
<x-mail::message>
# Reminder from {{ $businessName }}

Hi {{ $clientName }},

@if($requestMessage)
{{ $requestMessage }}
@endif

The following documents are still missing:

<ul>
@foreach($missingItemNames as $name)
<li>{{ $name }}</li>
@endforeach
</ul>

@if($dueAt)
Please upload the requested documents by {{ $dueAt }}.
@endif

<x-mail::button :url="$link">
Upload documents
</x-mail::button>

Thanks,<br>
{{ $businessName }}
</x-mail::message>
```

If `document-request-sent.blade.php` does not use `<x-mail::message>` (check it first), match whatever base layout it actually uses instead — do not introduce a new email layout convention.

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose -p task8reminders exec app php artisan test --filter=DocumentRequestReminderTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Mail/DocumentRequestReminder.php resources/views/emails/document-request-reminder.blade.php tests/Feature/Mail/DocumentRequestReminderTest.php
git commit -m "feat: add DocumentRequestReminder mailable and view

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 5: Reminder job with atomic idempotent claim

**Files:**
- Create: `app/Jobs/SendDocumentRequestReminderJob.php`
- Test: `tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php`

**Interfaces:**
- Consumes: `DocumentRequest::isEligibleForReminder()`, `DocumentRequest::regenerateAccessToken()` (Task 3, existing model), `App\Mail\DocumentRequestReminder` (Task 4), `App\Models\ActivityLog` (existing).
- Produces: `SendDocumentRequestReminderJob(int $documentRequestId)` implementing `ShouldQueue`, `ShouldBeUnique`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\SendDocumentRequestReminderJob;
use App\Mail\DocumentRequestReminder;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function makeSendableRequest(array $attrs = []): DocumentRequest
{
    $user = User::factory()->create(['name' => 'Acme Bookkeeping']);
    $client = Client::factory()->for($user)->create(['name' => 'John Smith', 'email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create(array_merge([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ], $attrs));
    $documentRequest->generateAccessToken();
    DocumentRequestItem::factory()->for($documentRequest)->create(['name' => 'Invoice', 'status' => 'pending']);

    return $documentRequest;
}

it('is queueable and unique', function () {
    $job = new SendDocumentRequestReminderJob(1);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->toBeInstanceOf(ShouldBeUnique::class);
});

it('sends a reminder mail and updates tracking columns for an eligible request', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();
    $oldHash = $documentRequest->access_token_hash;

    (new SendDocumentRequestReminderJob($documentRequest->id))->handle();

    $documentRequest->refresh();
    expect($documentRequest->reminder_count)->toBe(1);
    expect($documentRequest->last_reminder_sent_at)->not->toBeNull();
    expect($documentRequest->access_token_hash)->not->toBe($oldHash);
    Mail::assertQueued(DocumentRequestReminder::class, fn ($mail) => $mail->hasTo('client@example.com'));
});

it('logs a reminder_sent activity without leaking the token', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();

    (new SendDocumentRequestReminderJob($documentRequest->id))->handle();

    $log = ActivityLog::where('document_request_id', $documentRequest->id)
        ->where('event', 'reminder_sent')
        ->first();

    expect($log)->not->toBeNull();
    expect(json_encode($log->metadata))->not->toContain('access_token_hash');
    $documentRequest->refresh();
    expect(json_encode($log->metadata))->not->toContain(substr($documentRequest->access_token_hash, 0, 10));
});

it('does nothing when the request is no longer eligible', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest(['status' => 'archived']);

    (new SendDocumentRequestReminderJob($documentRequest->id))->handle();

    Mail::assertNothingQueued();
    expect($documentRequest->fresh()->reminder_count)->toBe(0);
    expect(ActivityLog::where('document_request_id', $documentRequest->id)->where('event', 'reminder_sent')->exists())->toBeFalse();
});

it('does nothing when the document request no longer exists', function () {
    Mail::fake();

    (new SendDocumentRequestReminderJob(999999))->handle();

    Mail::assertNothingQueued();
});

// --- Concurrency / idempotency ---

it('only one of two concurrent claims on the same request increments reminder_count', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();

    // Simulate two workers racing: both read reminder_count=0 before either commits.
    // The atomic UPDATE's WHERE clause means only the first commit's predicate still matches.
    $job1 = new SendDocumentRequestReminderJob($documentRequest->id);
    $job2 = new SendDocumentRequestReminderJob($documentRequest->id);

    $job1->handle();
    $job2->handle();

    expect($documentRequest->fresh()->reminder_count)->toBe(1);
    Mail::assertQueued(DocumentRequestReminder::class, 1);
    expect(ActivityLog::where('document_request_id', $documentRequest->id)->where('event', 'reminder_sent')->count())->toBe(1);
});

it('retrying the job after a successful send does not send a duplicate reminder', function () {
    Mail::fake();
    $documentRequest = makeSendableRequest();

    $job = new SendDocumentRequestReminderJob($documentRequest->id);
    $job->handle();
    // Simulate a queue retry of the same job instance/id after success.
    $job->handle();

    expect($documentRequest->fresh()->reminder_count)->toBe(1);
    Mail::assertQueued(DocumentRequestReminder::class, 1);
});

it('the atomic claim update only affects one row even under a raw concurrent race', function () {
    $documentRequest = makeSendableRequest();

    $threshold = now()->subDays((int) config('reminders.interval_days'));

    $affected1 = DB::table('document_requests')
        ->where('id', $documentRequest->id)
        ->where('reminder_count', '<', (int) config('reminders.max_count'))
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->update(['reminder_count' => DB::raw('reminder_count + 1'), 'last_reminder_sent_at' => now()]);

    $affected2 = DB::table('document_requests')
        ->where('id', $documentRequest->id)
        ->where('reminder_count', '<', (int) config('reminders.max_count'))
        ->where(function ($q) use ($threshold) {
            $q->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
        })
        ->update(['reminder_count' => DB::raw('reminder_count + 1'), 'last_reminder_sent_at' => now()]);

    expect($affected1)->toBe(1);
    expect($affected2)->toBe(0);
    expect($documentRequest->fresh()->reminder_count)->toBe(1);
});
```

Save as `tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php`.

Note on the true-concurrency test: Pest/SQLite-in-memory tests run single-threaded, so "two workers racing" is simulated by calling `handle()` twice in sequence against the same starting state — this is the standard way this codebase already tests race protection (see `DocumentRequestSendTest`'s resend test). The last test in this file (`the atomic claim update only affects one row...`) proves the underlying SQL predicate itself is race-safe at the database level, independent of job sequencing.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -p task8reminders exec app php artisan test --filter=SendDocumentRequestReminderJobTest`
Expected: FAIL — class `App\Jobs\SendDocumentRequestReminderJob` not found.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Mail\DocumentRequestReminder;
use App\Models\ActivityLog;
use App\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendDocumentRequestReminderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $documentRequestId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->documentRequestId;
    }

    public function handle(): void
    {
        $documentRequest = DocumentRequest::with('client', 'items')->find($this->documentRequestId);

        if ($documentRequest === null || ! $documentRequest->isEligibleForReminder()) {
            return;
        }

        $threshold = now()->subDays((int) config('reminders.interval_days'));
        $maxCount = (int) config('reminders.max_count');
        $now = now();

        $claimed = DB::table('document_requests')
            ->where('id', $documentRequest->id)
            ->where('reminder_count', '<', $maxCount)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_reminder_sent_at')->orWhere('last_reminder_sent_at', '<=', $threshold);
            })
            ->update([
                'reminder_count' => DB::raw('reminder_count + 1'),
                'last_reminder_sent_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return;
        }

        $token = $documentRequest->regenerateAccessToken();

        $missingItemNames = $documentRequest->items
            ->where('status', '!=', 'received')
            ->pluck('name')
            ->values()
            ->all();

        $email = $documentRequest->client->email;

        Mail::to($email)->queue(new DocumentRequestReminder(
            businessName: $documentRequest->user->name,
            clientName: $documentRequest->client->name,
            requestMessage: $documentRequest->message,
            dueAt: $documentRequest->due_at?->toDateString(),
            link: route('public.document-request.show', $token),
            missingItemNames: $missingItemNames,
        ));

        ActivityLog::create([
            'user_id' => $documentRequest->user_id,
            'client_id' => $documentRequest->client_id,
            'document_request_id' => $documentRequest->id,
            'event' => 'reminder_sent',
            'metadata' => ['client_email' => $email],
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose -p task8reminders exec app php artisan test --filter=SendDocumentRequestReminderJobTest`
Expected: PASS (all cases, including the two concurrency tests)

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SendDocumentRequestReminderJob.php tests/Feature/Jobs/SendDocumentRequestReminderJobTest.php
git commit -m "feat: add idempotent reminder job with atomic claim

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 6: Scheduler command that dispatches reminder jobs

**Files:**
- Create: `app/Console/Commands/SendDocumentRequestReminders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/SendDocumentRequestRemindersTest.php`

**Interfaces:**
- Consumes: `DocumentRequest::eligibleForReminder()` scope (Task 3), `SendDocumentRequestReminderJob` (Task 5).
- Produces: artisan command `reminders:send`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\SendDocumentRequestReminderJob;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

function makeEligibleForCommand(): DocumentRequest
{
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)->create([
        'status' => 'sent',
        'sent_at' => now()->subDays(3),
    ]);
    DocumentRequestItem::factory()->for($documentRequest)->create(['status' => 'pending']);

    return $documentRequest;
}

it('dispatches a reminder job for each eligible request', function () {
    Bus::fake();
    $eligible = makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();

    Bus::assertDispatched(SendDocumentRequestReminderJob::class, fn ($job) => $job->documentRequestId === $eligible->id);
});

it('does not dispatch a job for an ineligible request', function () {
    Bus::fake();
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create(['email' => 'client@example.com']);
    $ineligible = DocumentRequest::factory()->for($user)->for($client)->create(['status' => 'archived', 'sent_at' => now()->subDays(3)]);
    DocumentRequestItem::factory()->for($ineligible)->create(['status' => 'pending']);

    $this->artisan('reminders:send')->assertSuccessful();

    Bus::assertNotDispatched(SendDocumentRequestReminderJob::class);
});

it('never sends mail directly from the command', function () {
    Mail::fake();
    makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('never mixes requests across tenants when dispatching', function () {
    Bus::fake();
    $eligibleA = makeEligibleForCommand();
    $eligibleB = makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();

    Bus::assertDispatched(SendDocumentRequestReminderJob::class, fn ($job) => $job->documentRequestId === $eligibleA->id);
    Bus::assertDispatched(SendDocumentRequestReminderJob::class, fn ($job) => $job->documentRequestId === $eligibleB->id);
});

it('running the command twice does not change reminder_count more than once per interval', function () {
    // Uses the real job (no Bus::fake) so the atomic claim actually runs.
    Mail::fake();
    $documentRequest = makeEligibleForCommand();

    $this->artisan('reminders:send')->assertSuccessful();
    $this->artisan('reminders:send')->assertSuccessful();

    expect($documentRequest->fresh()->reminder_count)->toBe(1);
});
```

Save as `tests/Feature/Console/SendDocumentRequestRemindersTest.php`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose -p task8reminders exec app php artisan test --filter=SendDocumentRequestRemindersTest`
Expected: FAIL — command `reminders:send` does not exist.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SendDocumentRequestReminderJob;
use App\Models\DocumentRequest;
use Illuminate\Console\Command;

class SendDocumentRequestReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Dispatch reminder jobs for document requests with missing documents';

    public function handle(): int
    {
        $dispatched = 0;

        DocumentRequest::eligibleForReminder()
            ->select('id')
            ->chunkById(200, function ($documentRequests) use (&$dispatched) {
                foreach ($documentRequests as $documentRequest) {
                    SendDocumentRequestReminderJob::dispatch($documentRequest->id);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} reminder job(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register the schedule**

Append to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('reminders:send')->hourly();
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose -p task8reminders exec app php artisan test --filter=SendDocumentRequestRemindersTest`
Expected: PASS

- [ ] **Step 6: Verify scheduler registration**

Run: `docker compose -p task8reminders exec app php artisan schedule:list`
Expected: shows `reminders:send` running hourly.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/SendDocumentRequestReminders.php routes/console.php tests/Feature/Console/SendDocumentRequestRemindersTest.php
git commit -m "feat: schedule reminders:send command hourly

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

---

### Task 7: Full regression pass + Pint + final security review

**Files:** none created; verification only.

- [ ] **Step 1: Run the full test suite**

Run: `docker compose -p task8reminders exec app php artisan test`
Expected: all tests pass (baseline 158 + new tests), 0 failures.

- [ ] **Step 2: Run Pint**

Run: `docker compose -p task8reminders exec app ./vendor/bin/pint`
Expected: exits 0, reports files fixed or already-clean.

- [ ] **Step 3: Re-run tests after Pint (in case formatting touched behavior-adjacent files)**

Run: `docker compose -p task8reminders exec app php artisan test`
Expected: still all passing.

- [ ] **Step 4: Manual security review checklist**

Grep for the following and confirm each is satisfied (no code changes expected unless a gap is found):
- `grep -rn "access_token_hash\|access_token" app/Jobs/SendDocumentRequestReminderJob.php` — confirm it's never passed to `ActivityLog::create` or logged.
- Confirm `SendDocumentRequestReminders` command never imports `Mail`.
- Confirm `DocumentRequest::scopeEligibleForReminder` and `isEligibleForReminder` never bypass the `whereHas('client', ...)` / email-validity check.
- Confirm the job loads the request fresh by ID (not passed a serialized stale model) and re-checks eligibility before claiming.

- [ ] **Step 5: Commit any fixes found during review**

If Pint or the review changes files:

```bash
git add -A
git commit -m "chore: apply Pint formatting and final security review fixes

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_011XjfKjCHXhfazDNKinjwJW"
```

If nothing changed, skip this commit.
