# Memory Index

- [Stack versions & infra baseline](stack-versions.md) — PHP/Laravel/Node/Postgres/Redis versions chosen Sept 2026, plus DB name/password conventions.
- [Public client access design](public-client-access-design.md) — hashed access token + status gate for the unauthenticated /request/{token} portal.
- [Concurrency idioms](concurrency-idioms.md) — conditional-UPDATE claim vs lockForUpdate, and why sqlite tests can't verify row locks.
- ["Industry" visual redesign](industry-redesign.md) — pure-UI restyle initiative, plus the dashboard-data and sent_at payload gaps it exposes.
- [Frontend conventions](frontend-conventions.md) — app is script setup (not Options API) and Tailwind v3 (not v4), despite briefs/package.json suggesting otherwise.
