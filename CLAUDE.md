# CLAUDE.md

## Role

Act as a senior software engineer responsible for implementing this product.

The product requirements are defined in `PRODUCT.md`.

`PRODUCT.md` is the source of truth for product scope.

Your job is to implement the smallest production-capable solution that satisfies the requirements.

---

# 1. Before Making Changes

Before implementing anything:

1. Read `PRODUCT.md`.
2. Inspect the existing repository.
3. Understand the current Docker/environment setup.
4. Inspect existing architecture and conventions.
5. Identify the smallest set of files that need to change.
6. Check whether the requested feature already exists partially.
7. Plan the implementation before writing code.

Do not blindly create new files or abstractions.

---

# 2. Scope Control

Never implement functionality that is not required by `PRODUCT.md` or explicitly requested by the user.

Do not add features because they "might be useful later".

Avoid:

* speculative abstractions;
* unnecessary configuration;
* unnecessary dependencies;
* unnecessary database tables;
* unnecessary APIs;
* unnecessary services;
* unnecessary design patterns.

If a requirement is ambiguous and the ambiguity affects architecture, security, cost or scope, ask before implementing.

Otherwise choose the simplest reasonable interpretation.

---

# 3. Architecture

Prefer a modular monolith.

Use Laravel-native architecture whenever possible.

Preferred stack:

* Laravel;
* PHP;
* Vue 3;
* Inertia.js;
* PostgreSQL;
* Redis;
* Laravel Queue;
* Laravel Scheduler;
* Docker.

Do NOT introduce:

* microservices;
* Kubernetes;
* event buses;
* CQRS;
* unnecessary repositories;
* unnecessary service layers;
* unnecessary interfaces;
* unnecessary abstractions.

Use classes/services when they provide a clear responsibility or prevent controllers/jobs from becoming unnecessarily complex.

---

# 4. Security

Security is mandatory.

Assume that all user input is malicious.

Always consider:

* authentication;
* authorization;
* IDOR/BOLA;
* mass assignment;
* CSRF;
* XSS;
* SQL injection;
* path traversal;
* malicious file uploads;
* insecure direct object references;
* session security;
* rate limiting;
* secret exposure;
* sensitive logging.

Never rely on frontend authorization.

Every protected resource must be authorized server-side.

Use Laravel's policies/gates and built-in security mechanisms where appropriate.

Do not implement custom cryptography when a standard Laravel/PHP mechanism exists.

---

# 5. Multi-Tenancy

The application is multi-tenant.

Every business-owned resource must have an unambiguous ownership relationship.

Before returning, modifying or deleting a resource, verify that it belongs to the authenticated user's organization/account.

Never trust:

* IDs supplied by the browser;
* hidden form fields;
* frontend state;
* route parameters alone.

Prevent horizontal privilege escalation.

Test cross-tenant access explicitly.

---

# 6. File Upload Security

Uploaded documents are untrusted.

Never trust:

* original filename;
* extension;
* MIME type supplied by the browser.

Validate the actual uploaded file.

Enforce:

* file size limits;
* allowed file types;
* valid upload state;
* safe storage names.

Never use the original filename as a filesystem path.

Never execute uploaded files.

Store uploaded documents on a private disk.

Never expose raw filesystem paths.

Downloads must be authorized server-side.

Avoid permanent public URLs.

---

# 7. Client Request Tokens

Client upload links are sensitive credentials.

Tokens must be:

* cryptographically random;
* sufficiently long;
* unguessable;
* stored/handled securely.

Never generate tokens from:

* sequential IDs;
* timestamps;
* emails;
* predictable hashes.

A client token must only grant access to its intended request.

Never allow a client token to access another request.

Do not expose unnecessary internal IDs through the client portal.

---

# 8. Validation

Validate all external input server-side.

Use Laravel Form Requests or equivalent validation mechanisms.

Validation must cover:

* required fields;
* string lengths;
* email formats;
* IDs;
* enum/status values;
* file types;
* file sizes;
* dates;
* ownership.

Do not rely on HTML validation or Vue validation for security.

Frontend validation is only for UX.

---

# 9. Database

Use migrations.

Use foreign keys where appropriate.

Use indexes for fields frequently used in:

* lookups;
* ownership filtering;
* status queries;
* scheduled jobs.

Use transactions when multiple related writes must succeed or fail together.

Avoid N+1 queries.

Do not modify an already-deployed migration to change production schema.

Create a new migration for schema changes.

Do not manually modify production databases unless explicitly instructed.

---

# 10. Eloquent

Prefer Eloquent and Laravel query builder.

Avoid raw SQL unless there is a concrete reason.

When raw SQL is necessary, use parameterized queries.

Never concatenate user input into SQL.

Be careful with mass assignment.

Use `$fillable` or guarded configuration intentionally.

---

# 11. Authentication and Authorization

Use Laravel's established authentication mechanisms.

Never implement custom password handling.

Passwords must never be logged or stored outside the standard authentication system.

Authorization must happen server-side.

A user must only be able to:

* view their own resources;
* modify their own resources;
* delete their own resources.

Test authorization boundaries.

---

# 12. Emails

Email sending must be asynchronous when practical.

Use Laravel Mailables/Notifications and queues.

Never expose sensitive internal information in emails.

Links sent to clients must use secure request tokens.

Do not put unnecessary personal or document information into email subjects.

---

# 13. Queues

Queue jobs must be safe to retry.

Assume a job can execute more than once.

Jobs should be idempotent whenever possible.

Handle:

* retries;
* timeouts;
* failures;
* duplicate execution.

Do not allow duplicate reminders or duplicate state transitions when retries occur.

Use explicit job timeouts and retry configuration.

---

# 14. Scheduled Jobs

Scheduled jobs must be safe to run repeatedly.

Never assume a scheduled task executes exactly once.

Use database state/constraints where necessary to prevent duplicate work.

Cleanup jobs must tolerate already-deleted files/resources.

---

# 15. Sensitive Data and Logging

Never log:

* document contents;
* uploaded file contents;
* passwords;
* authentication tokens;
* client request tokens;
* payment secrets;
* API secrets;
* private document URLs.

Be careful with exception messages because they can contain sensitive data.

Production logs should contain enough information for debugging without exposing customer data.

---

# 16. Error Handling

User-facing errors must be understandable.

Do not expose:

* stack traces;
* SQL queries;
* filesystem paths;
* internal exception details;
* secrets;
* infrastructure information.

Log technical details securely on the server when appropriate.

---

# 17. Frontend

Use Vue 3 + Inertia.

Keep components simple.

Do not introduce a frontend state-management library unless there is a demonstrated need.

Do not duplicate business logic between frontend and backend.

The backend remains authoritative.

Never trust frontend state for:

* authorization;
* ownership;
* payment status;
* request status;
* security decisions.

---

# 18. API

Do not create a public REST API unless `PRODUCT.md` explicitly requires it.

For the MVP, prefer Inertia requests and Laravel routes/controllers.

Do not build an API "for future use".

---

# 19. Dependencies

Before adding a package:

1. Check whether Laravel/PHP already provides the functionality.
2. Check whether the existing project already has a suitable dependency.
3. Consider maintenance/security implications.
4. Add the package only if it materially simplifies the implementation.

Avoid dependency bloat.

---

# 20. Testing

Write tests for important behavior.

Prioritize:

### Security

* unauthorized access;
* cross-tenant access;
* invalid tokens;
* expired tokens;
* unauthorized downloads;
* malicious uploads.

### Core business logic

* request creation;
* document requirements;
* uploads;
* completion;
* reminders;
* expiration.

### Jobs

* retry behavior;
* idempotency;
* duplicate prevention.

### Validation

* invalid inputs;
* invalid files;
* size limits;
* invalid states.

Use Laravel feature tests where they provide the most value.

Do not write tests purely to increase coverage numbers.

---

# 21. Performance

Do not prematurely optimize.

But avoid obvious problems:

* N+1 queries;
* loading unnecessary large datasets;
* synchronous email sending;
* unnecessary file reads;
* unbounded queries.

Use queues for potentially slow work.

Use pagination for lists that can grow.

---

# 22. Docker

The development environment must be reproducible.

Services should remain simple.

Expected initial services:

* app;
* nginx/web server;
* worker;
* scheduler;
* PostgreSQL;
* Redis.

The exact implementation can vary if there is a simpler approach.

Persistent data must use Docker volumes/bind mounts where necessary.

Do not put persistent PostgreSQL data inside an ephemeral container filesystem.

Private uploaded files must also use persistent storage outside the application container filesystem.

---

# 23. Configuration

All environment-specific configuration belongs in environment variables.

Never commit:

* API keys;
* passwords;
* SMTP credentials;
* Stripe secrets;
* application secrets.

Keep `.env.example` updated.

Never hardcode credentials.

---

# 24. Git

Keep commits small and meaningful.

Prefer commits such as:

```text
feat: add client request model
feat: add secure client upload portal
fix: prevent cross-tenant document access
test: add request authorization tests
```

Do not mix unrelated changes.

---

# 25. Implementation Workflow

For each feature:

1. Read the relevant requirements in `PRODUCT.md`.
2. Inspect existing code.
3. Identify affected files.
4. Explain the implementation plan briefly.
5. Implement the smallest solution.
6. Run relevant tests.
7. Run static analysis/linting when available.
8. Review the implementation for security issues.
9. Review for unnecessary complexity.
10. Report what changed and what was tested.

Do not move on to unrelated features automatically.

---

# 26. Definition of Done

Before considering a feature complete:

* requirements are satisfied;
* server-side validation exists;
* authorization is enforced;
* error handling exists;
* tests cover important behavior;
* security risks were reviewed;
* migrations are correct;
* queues/jobs are retry-safe;
* no secrets are committed;
* implementation is reasonably simple.

---

# 27. Product Priority

Optimize for:

1. Correctness
2. Security
3. Simplicity
4. Speed of development
5. Maintainability
6. Performance

Do not sacrifice security or correctness for speed.

Do not sacrifice simplicity for hypothetical future requirements.

---

# 28. Critical Rule

If you are unsure whether a feature belongs in the product, check `PRODUCT.md`.

If it is not required, do not implement it.

If a technical decision can be solved in multiple reasonable ways, prefer the simplest production-capable solution.

The objective is to build a secure MVP quickly and validate the business.

Do not turn the MVP into an enterprise platform.
