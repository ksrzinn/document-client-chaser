---
name: stack-versions
description: Agreed baseline versions and infra decisions for the Client Document Chaser Docker environment (Sept 2026)
metadata:
  type: project
---

Baseline stack recommended for the infra/Docker setup work (verified Sept 2026): PHP 8.5, Laravel 13 (min PHP 8.3, released 2026-03-17), Node 24 LTS, PostgreSQL 18, Redis 8. Database name is `client-document-chaser`; DB password is intentionally left blank in `.env.example` for the user to fill in.

**Why:** The repo was empty at the time — no composer.json to derive versions from. PHP 8.4 leaves active support 2026-12-31, so 8.5 was chosen for runway. Laravel 13 bug fixes run to Q3 2027.

**How to apply:** Treat as the intended baseline, but verify against `composer.json` / `Dockerfile` once they exist — those are authoritative. Re-check EOL dates before recommending a bump.
