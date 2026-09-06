---
name: concurrency-idioms
description: When to use conditional-UPDATE claims vs lockForUpdate in this codebase, and why the sqlite test config cannot verify row locking.
metadata:
  type: project
---

Two distinct concurrency idioms are in use, and the choice depends on whether the guard condition lives on the same row:

- **Single-row atomic claim** (conditional `UPDATE ... WHERE <precondition>` + check affected rows) is correct when the precondition is on the row being updated. Used for the reminder counter claim in `SendDocumentRequestReminderJob`.
- **`lockForUpdate()` on the parent row** is required when the condition is *derived from child rows* (e.g. "all `document_request_items` are received" gating `document_requests.status = completed`). A conditional UPDATE with a `NOT EXISTS` subquery is **not** sufficient there: under READ COMMITTED, two transactions each writing a different last-remaining child both evaluate the subquery against a snapshot that excludes the other's uncommitted write, so both see "not complete" and neither transitions. Locking the parent row serializes them.

**Why:** correctness of the second idiom depends on Postgres READ COMMITTED taking a fresh per-statement snapshot after `SELECT ... FOR UPDATE` unblocks. Under REPEATABLE READ it fails loudly with a serialization error rather than silently missing, so it is safe either way — but the default isolation level must not be raised without revisiting this.

**How to apply:** the child-row write must happen before acquiring the parent lock, in the same outer transaction. Postgres holds row locks to end-of-transaction (not end-of-savepoint), so a nested `DB::transaction` savepoint still holds the lock for the whole outer request.

**Testing constraint:** `phpunit.xml` forces `DB_CONNECTION=sqlite` in-memory. Laravel's SQLite grammar compiles `lockForUpdate()` to an empty string, and `:memory:` cannot host two concurrent connections — so no test in this suite can validate a locking claim. Test idempotency/ordering/guards deterministically instead, and verify locking behaviour manually against Postgres. Do not write a "concurrency test" on sqlite that passes vacuously.

Related: [[stack-versions]], [[public-client-access-design]]
