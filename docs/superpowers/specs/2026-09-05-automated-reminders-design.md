# Automated Email Reminders — Design

## Product rules used (from PRODUCT.md §16, §17, §19, §21, §27, §28)

- Reminder email sent automatically while required documents are still missing (§16).
- Reminders stop when all documents received, or request closed/expired (§17).
- "Configurable reminder interval" (§17) — no numeric default given.
- Expired requests no longer accept uploads; reminders must respect `expires_at` (§19).
- Activity log must show `reminder sent` (§21).
- Reminder processing must be queued (§27); scheduled via Laravel scheduler (§28).

## Gaps in PRODUCT.md and adopted defaults (approved by user 2026-09-05)

| Gap | Decision | Config |
|---|---|---|
| Reminder interval | 2 days, matches §17 example | `REMINDER_INTERVAL_DAYS` (default `2`) |
| Max reminders per request | 3 | `REMINDER_MAX_COUNT` (default `3`) |
| Relation to `due_at` | Reminders continue after `due_at` passes; only `expires_at`, completion, and archive stop them | n/a |
| Completion detection | No reliable `status=completed`/`completed_at` flow exists yet (nothing in the codebase sets it). Eligibility checks directly whether any item still has `status != 'received'` instead of trusting those fields. | n/a |
| Token/link on reminder | Token is regenerated on each reminder (mirrors the existing `DocumentRequest::regenerateAccessToken()` behavior already used by manual re-send in `DocumentRequestController::send()`). Raw token is never persisted — only its hash. Each new reminder invalidates the previous email's link; this is an accepted, pre-existing product tradeoff from Task 7, not introduced here. | n/a |

## Eligibility rule (single source of truth)

A `DocumentRequest` is eligible for a reminder when ALL of:
1. `sent_at` is not null.
2. `status` is not `archived` and not `completed`.
3. `expires_at` is null OR in the future.
4. Client exists and has a non-blank, valid-format email.
5. At least one `items` row has `status != 'received'`.
6. `reminder_count < REMINDER_MAX_COUNT`.
7. `last_reminder_sent_at` is null (and `sent_at <= now() - interval`), OR `last_reminder_sent_at <= now() - interval`.

Implemented as `DocumentRequest::scopeEligibleForReminder()` (bulk query, used by the scheduler command for chunking) and `DocumentRequest::isEligibleForReminder()` (single-row re-check inside the job, defense against staleness between query and execution).

## Idempotency / concurrency

New columns on `document_requests`: `last_reminder_sent_at` (nullable timestamp), `reminder_count` (unsigned integer, default 0).

Correctness mechanism: a single atomic conditional `UPDATE`:

```sql
UPDATE document_requests
SET last_reminder_sent_at = ?, reminder_count = reminder_count + 1
WHERE id = ?
  AND reminder_count < ?
  AND (last_reminder_sent_at IS NULL OR last_reminder_sent_at <= ?)
```

If the affected row count is 0, the job stops without sending mail — another worker/run already claimed this reminder window. This is safe under concurrent workers and concurrent scheduler runs because PostgreSQL evaluates the `WHERE` predicate and applies the row-level write atomically; two concurrent `UPDATE`s against the same row serialize at the database and only one can match the predicate's still-true state after the first commits.

`SendDocumentRequestReminderJob` additionally declares `ShouldBeUnique` (unique per `document_request_id` while queued) as a secondary, defense-in-depth layer — not the correctness mechanism.

## Flow

```
Scheduler (hourly, `reminders:send`)
  → DocumentRequest::eligibleForReminder() chunked query
  → dispatch SendDocumentRequestReminderJob($documentRequestId) per row
  → Job: reload row, isEligibleForReminder() re-check
  → Job: atomic conditional UPDATE (claims the send)
  → Job: regenerate access token, queue DocumentRequestReminder mail, write ActivityLog('reminder_sent')
```

## Mail

`app/Mail/DocumentRequestReminder.php`, mirrors `DocumentRequestSent` fields (businessName, clientName, requestMessage, dueAt, link) plus a list of missing item names. View clearly reads as a reminder (distinct subject/copy), reuses the same escaping-safe Blade patterns as `emails/document-request-sent.blade.php`.

## ActivityLog

Event `reminder_sent`, metadata `{'client_email' => $email}` — same shape as `request_sent`. No token/hash ever included.

## Out of scope (explicitly deferred)

- Auto-completion of requests (`status=completed`/`completed_at`).
- Any reminder-management UI.
- Configurable reminder interval per-request (product only asked for a global configurable value).
