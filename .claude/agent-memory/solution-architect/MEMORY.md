# Memory Index

- [Stack versions & infra baseline](stack-versions.md) — PHP/Laravel/Node/Postgres/Redis versions chosen Sept 2026, plus DB name/password conventions.
- [Public client access design](public-client-access-design.md) — hashed access token + status gate for the unauthenticated /request/{token} portal.
- [Concurrency idioms](concurrency-idioms.md) — conditional-UPDATE claim vs lockForUpdate, and why sqlite tests can't verify row locks.
