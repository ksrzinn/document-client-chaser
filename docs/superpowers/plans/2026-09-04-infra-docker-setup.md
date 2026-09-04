# Infra: Docker + Minimal Laravel Environment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reproducible Docker environment running a minimal Laravel + Vue3 + Inertia app, wired to PostgreSQL and Redis, with a working queue worker and scheduler. Health checked via Laravel's built-in `/up` route and Docker Compose healthchecks only — no custom aggregator endpoint. No product features.

**Architecture:** Single multi-stage `Dockerfile` (stages: `vendor`, `assets`, `app`) shared by `app`, `worker`, `scheduler` containers — only the `command:` differs, guaranteeing identical PHP/extensions across all three. `nginx` serves PHP via php-fpm over the shared codebase bind mount. `postgres` and `redis` each get a named volume. Vite runs as its own `node` container in dev, published on 5173, resolved directly by the browser (nginx never proxies it).

**Tech Stack:** PHP 8.5-fpm-alpine, Laravel 13, Node 24, PostgreSQL 18, Redis 8, Vue 3, Inertia.js, Vite, Docker Compose.

**Spec:** `../../../PRODUCT.md` (product scope, not implemented yet), `../../../CLAUDE.md` (engineering/security rules — binding for every task below).

## Global Constraints

- No product-domain code: no clients, document requests, uploads, client portal, reminders, business emails, dashboard, Stripe, analytics, domain migrations/models/controllers, product frontend.
- DB name is literally `client-document-chaser`; `DB_PASSWORD` stays blank in `.env.example` — user fills it in locally.
- No public storage/public links for files — private disk only, even though no upload feature exists yet.
- No microservices, no k8s, no event bus, no extra abstractions (CLAUDE.md §3).
- No secrets committed; `.env` stays gitignored, `.env.example` has placeholders only.
- No custom health aggregator endpoint — use Laravel's built-in `/up` route plus Docker Compose `healthcheck:` (pg_isready, redis-cli ping) only.

---

### Task 1: Bootstrap Laravel skeleton via throwaway Composer container

No PHP needed on host — use a disposable `composer` container to generate the app in place.

**Files:**
- Create: entire Laravel skeleton at repo root (`app/`, `bootstrap/`, `config/`, `routes/`, `artisan`, `composer.json`, etc.)

- [ ] **Step 1: Generate skeleton**

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app composer:2 \
  composer create-project laravel/laravel . "^13.0" --prefer-dist --no-interaction
```

- [ ] **Step 2: Verify skeleton created**

```bash
test -f artisan && test -f composer.json && cat composer.json | grep '"laravel/framework"'
```
Expected: `artisan` exists, `laravel/framework` pinned to `^13.0`.

- [ ] **Step 3: Install Inertia + Vue 3 scaffold**

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app composer:2 \
  composer require laravel/breeze --dev
docker run --rm -v "$PWD":/app -w /app node:24-alpine sh -c \
  "php artisan breeze:install vue --inertia --pest --no-interaction || true"
```
If `breeze:install` fails without PHP CLI in the node image, instead run it from a temporary PHP container:
```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.5-cli-alpine \
  php artisan breeze:install vue --pest --no-interaction
```
Expected: `resources/js/Pages`, `resources/js/app.js`, Inertia + Vue deps added to `package.json`.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: bootstrap Laravel 13 + Inertia/Vue3 skeleton via Breeze"
```

---

### Task 2: Dockerfile (multi-stage, shared by app/worker/scheduler)

**Files:**
- Create: `Dockerfile`
- Create: `docker/php/opcache.ini`

**Interfaces:**
- Produces: image target `app` used by all four PHP-driven compose services (`app`, `worker`, `scheduler`) — entrypoint is `php-fpm`; worker/scheduler override `command:`.

- [ ] **Step 1: Write Dockerfile**

```dockerfile
# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:${PHP_VERSION}-fpm-alpine AS app
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app

RUN apk add --no-cache postgresql-client icu-dev libzip-dev libpng-dev

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis bcmath intl zip gd opcache pcntl

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /var/www/html
COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /app/vendor ./vendor
COPY --from=assets --chown=app:app /app/public/build ./public/build

USER app
EXPOSE 9000
CMD ["php-fpm"]
```

- [ ] **Step 2: opcache tuning**

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

- [ ] **Step 3: Verify image builds**

```bash
docker build --target app -t cdc-app:test .
```
Expected: build succeeds, no errors pulling `pdo_pgsql`/`redis` extensions.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile docker/php/opcache.ini
git commit -m "feat: add multi-stage Dockerfile for app/worker/scheduler"
```

---

### Task 3: docker-compose.yml — full service topology

**Files:**
- Create: `docker-compose.yml`
- Create: `docker/nginx/default.conf`
- Modify: `.gitignore` (ensure `.env`, `vendor/`, `node_modules/`, `.claude/worktrees/` ignored)

**Interfaces:**
- Consumes: `Dockerfile` `app` target (Task 2).
- Produces: service names `app`, `nginx`, `worker`, `scheduler`, `postgres`, `redis`, `vite` — later tasks assume these exact names for `depends_on` and docs.

- [ ] **Step 1: Write nginx vhost**

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

- [ ] **Step 2: Write docker-compose.yml**

```yaml
services:
  app:
    build:
      context: .
      target: app
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    volumes:
      - .:/var/www/html
      - private_storage:/var/www/html/storage/app/private
    env_file: .env
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  nginx:
    image: nginx:1.27-alpine
    ports:
      - "8000:80"
    volumes:
      - .:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app

  worker:
    build:
      context: .
      target: app
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    command: php artisan queue:work --tries=3 --max-time=3600
    restart: unless-stopped
    volumes:
      - .:/var/www/html
      - private_storage:/var/www/html/storage/app/private
    env_file: .env
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  scheduler:
    build:
      context: .
      target: app
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    command: php artisan schedule:work
    restart: unless-stopped
    volumes:
      - .:/var/www/html
      - private_storage:/var/www/html/storage/app/private
    env_file: .env
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  vite:
    image: node:24-alpine
    working_dir: /var/www/html
    command: sh -c "npm install && npm run dev -- --host 0.0.0.0"
    ports:
      - "5173:5173"
    volumes:
      - .:/var/www/html

  postgres:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: client-document-chaser
      POSTGRES_USER: ${DB_USERNAME:-postgres}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-postgres} -d client-document-chaser"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: redis:8-alpine
    command: redis-server --appendonly yes
    volumes:
      - redisdata:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 10

volumes:
  pgdata:
  redisdata:
  private_storage:
```

- [ ] **Step 3: Ensure ignores**

```bash
grep -qxF '.env' .gitignore || echo '.env' >> .gitignore
grep -qxF 'vendor/' .gitignore || echo 'vendor/' >> .gitignore
grep -qxF 'node_modules/' .gitignore || echo 'node_modules/' >> .gitignore
grep -qxF '.claude/worktrees/' .gitignore || echo '.claude/worktrees/' >> .gitignore
```

- [ ] **Step 4: Validate compose config**

```bash
docker compose config -q
```
Expected: no error.

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml docker/nginx/default.conf .gitignore
git commit -m "feat: add docker-compose topology for app/nginx/worker/scheduler/postgres/redis/vite"
```

---

### Task 4: Environment configuration (.env.example, config/*.php wiring)

**Files:**
- Modify: `.env.example`
- Modify: `config/filesystems.php` (add `private` disk)
- Modify: `config/app.php` (timezone) — only if not already correct

**Interfaces:**
- Produces: `Storage::disk('private')` — used by Task 5's test to prove private storage works; will later back real uploads (not built now).

- [ ] **Step 1: Write .env.example**

```env
APP_NAME="Client Document Chaser"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_TIMEZONE=UTC
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=client-document-chaser
DB_USERNAME=postgres
DB_PASSWORD=

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

FILESYSTEM_DISK=private

VITE_DEV_SERVER_URL=http://localhost:5173
```

- [ ] **Step 2: Add private disk to config/filesystems.php**

Add alongside the existing `local` disk entry in the `disks` array:

```php
'private' => [
    'driver' => 'local',
    'root' => storage_path('app/private'),
    'serve' => false,
    'throw' => false,
],
```

- [ ] **Step 3: Confirm timezone in config/app.php**

```php
'timezone' => env('APP_TIMEZONE', 'UTC'),
```

- [ ] **Step 4: Verify config loads**

```bash
docker compose run --rm app php artisan config:show filesystems.disks.private
```
Expected: prints driver `local`, root ending in `storage/app/private`.

- [ ] **Step 5: Commit**

```bash
git add .env.example config/filesystems.php config/app.php
git commit -m "feat: configure postgres/redis/private-disk environment"
```

---

### Task 5: Private storage write/read smoke test

TDD: prove the private disk works before anything depends on it.

**Files:**
- Test: `tests/Feature/PrivateStorageTest.php`

**Interfaces:**
- Consumes: `private` disk from Task 4.

- [ ] **Step 1: Write failing test**

```php
<?php

use Illuminate\Support\Facades\Storage;

it('writes and reads a file on the private disk', function () {
    Storage::disk('private')->put('smoke-test.txt', 'ok');

    expect(Storage::disk('private')->exists('smoke-test.txt'))->toBeTrue();
    expect(Storage::disk('private')->get('smoke-test.txt'))->toBe('ok');

    Storage::disk('private')->delete('smoke-test.txt');
});

it('private disk is not publicly reachable', function () {
    expect(config('filesystems.disks.private.serve'))->toBeFalse();
});
```

- [ ] **Step 2: Run, verify it fails only if disk misconfigured**

```bash
docker compose run --rm app php artisan test --filter=PrivateStorageTest
```
Expected at this point: PASS (Task 4 already wired the disk) — if it fails, fix `config/filesystems.php` before proceeding, don't skip.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PrivateStorageTest.php
git commit -m "test: verify private disk read/write and non-public config"
```

---

### Task 6: Minimal Inertia/Vue test page (Browser→Nginx→Laravel→Inertia→Vue)

**Files:**
- Modify: `routes/web.php`
- Create: `resources/js/Pages/EnvironmentCheck.vue`
- Test: `tests/Feature/EnvironmentCheckPageTest.php`

**Interfaces:**
- Produces: `GET /environment-check` → Inertia response rendering component `EnvironmentCheck`.

- [ ] **Step 1: Write failing test**

```php
<?php

it('renders the environment check inertia page', function () {
    $response = $this->get('/environment-check');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('EnvironmentCheck'));
});
```

- [ ] **Step 2: Run, verify it fails**

```bash
docker compose run --rm app php artisan test --filter=EnvironmentCheckPageTest
```
Expected: FAIL — route missing.

- [ ] **Step 3: Add route**

Append to `routes/web.php`:

```php
Route::get('/environment-check', fn () => inertia('EnvironmentCheck'));
```

- [ ] **Step 4: Add Vue page**

```vue
<script setup>
</script>

<template>
  <div style="font-family: sans-serif; padding: 2rem;">
    <h1>Environment Check</h1>
    <p>Browser → Nginx → Laravel → Inertia → Vue: working.</p>
  </div>
</template>
```

- [ ] **Step 5: Run, verify it passes**

```bash
docker compose run --rm app php artisan test --filter=EnvironmentCheckPageTest
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/js/Pages/EnvironmentCheck.vue tests/Feature/EnvironmentCheckPageTest.php
git commit -m "feat: add minimal inertia/vue environment-check page"
```

---

### Task 7: README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Write README covering setup and verification**

```markdown
# Client Document Chaser — Infra Setup

## Requirements
- Docker + Docker Compose

## First run

1. Copy env and fill in DB password:
   cp .env.example .env
   # edit .env, set DB_PASSWORD

2. Build and start:
   docker compose up -d --build

3. Generate app key:
   docker compose exec app php artisan key:generate

4. Run migrations:
   docker compose exec app php artisan migrate

5. Verify:
   - App: http://localhost:8000/environment-check
   - Health: http://localhost:8000/up
   - Vite dev server: http://localhost:5173

## Services
- app (php-fpm), nginx (:8000), worker (queue:work), scheduler (schedule:work),
  postgres (:5432, db `client-document-chaser`), redis (:6379), vite (:5173)

## Tests
docker compose exec app php artisan test
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs: add infra setup and verification instructions"
```

---

### Task 8: Full-stack verification

No new files — this task proves the whole stack together, per user's explicit test checklist.

- [ ] **Step 1: Fresh boot**

```bash
docker compose down -v
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

- [ ] **Step 2: Wait for healthchecks, generate key, migrate**

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```
Expected: migrations table created, no errors.

- [ ] **Step 3: Confirm each service**

```bash
curl -sf http://localhost:8000/up
docker compose ps --format '{{.Name}}: {{.Status}}'
curl -sf http://localhost:8000/environment-check -H 'X-Inertia: true' | grep -q EnvironmentCheck
docker compose logs worker --tail=20
docker compose logs scheduler --tail=20
curl -sf http://localhost:5173/@vite/client
```
Expected: `/up` returns 200; `docker compose ps` shows `postgres`/`redis` as `healthy`; Inertia page returns component name; worker/scheduler logs show no crash loop; Vite client script served.

- [ ] **Step 4: Run full Laravel test suite**

```bash
docker compose exec app php artisan test
```
Expected: all tests pass (PrivateStorageTest, EnvironmentCheckPageTest, Breeze defaults).

- [ ] **Step 5: Record results in final report to user (no commit needed — this task is verification only)**

---

## Self-Review Notes

- Spec coverage: every explicit ask in the request (Docker services, Laravel bootstrap, PG/Redis config, queue worker, scheduler, private storage, Vite/Inertia/Vue chain, .env.example, README, migrate check, health check, DB name `client-document-chaser`, blank password) maps to Tasks 1–8.
- Explicitly out of scope per user/CLAUDE.md: no domain migrations/models/controllers, no public API, no product frontend — none introduced above.
- Health check uses Laravel's built-in `/up` route and Compose `healthcheck:` blocks only — no custom aggregator endpoint, per user decision.
