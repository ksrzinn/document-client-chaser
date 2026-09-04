# Client Document Chaser — Infra Setup

Infrastructure-only setup: Docker environment + minimal Laravel/Inertia/Vue3
app. No product features yet (see `PRODUCT.md`, `CLAUDE.md`).

## Requirements

- Docker + Docker Compose

## First run

1. Copy env and fill in DB password:
   ```bash
   cp .env.example .env
   # edit .env, set DB_PASSWORD
   ```

2. Build and start:
   ```bash
   docker compose up -d --build
   ```

3. Generate app key:
   ```bash
   docker compose exec app php artisan key:generate
   ```

4. Run migrations:
   ```bash
   docker compose exec app php artisan migrate
   ```

5. Verify:
   - App: http://localhost:8080/environment-check
   - Health: http://localhost:8080/up
   - Vite dev server: http://localhost:5173

## Services

- `app` — PHP 8.5-fpm-alpine, Laravel 13
- `nginx` — serves the app on :8000
- `worker` — `php artisan queue:work` (Redis queue)
- `scheduler` — `php artisan schedule:work`
- `postgres` — PostgreSQL 18, database `client-document-chaser`
- `redis` — Redis 8
- `vite` — Vite dev server on :5173, resolved directly by the browser

`app`, `worker`, and `scheduler` all build from the same `Dockerfile` `app`
target — only the `command:` differs — so PHP version/extensions stay
identical across them.

## Tests

```bash
docker compose exec app php artisan test
```

## Storage

Files are stored on the `local` disk at `storage/app/private`, backed by a
persistent Docker volume (`private_storage`). `serve` is disabled — no
public URLs. Never use `storage/app/public` for private documents.
