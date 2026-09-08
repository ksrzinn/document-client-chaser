# Production Deploy Infrastructure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a production Docker Compose stack (built images, no source bind-mount, no Vite dev server) so the app can be deployed to an Ubuntu/Docker VPS, without touching the existing dev compose or any application/business logic.

**Architecture:** Extend the existing multi-stage `Dockerfile` with a new `nginx` build stage (bakes `public/` in, no bind mount needed) and a small `fcgi` healthcheck addition to the `app` stage. Add a new `docker-compose.prod.yml` that reuses the same built image for `app`/`worker`/`scheduler`, keeps `postgres`/`redis` off the host network, and wires healthchecks + `depends_on: condition: service_healthy` correctly. Add `.env.production.example` (placeholders only) and `docs/deployment.md`.

**Tech Stack:** Docker, Docker Compose v2, PHP 8.5-fpm-alpine, nginx-alpine, PostgreSQL 18-alpine, Redis 8-alpine, Laravel 13.

**Spec:** This plan implements the production-readiness scope from the user's request in this conversation (no separate spec doc — audit findings + the user's itemized requirements list are the spec). Key confirmed decision: nginx gets `public/` via a dedicated build stage that copies it from the `app` build stage (not a shared volume) — see "Global Constraints".

## Global Constraints

- Do NOT modify `docker-compose.yml` (dev compose) in any way.
- Do NOT modify application/business logic: no changes to `app/`, `routes/`, upload/download flow, auth, tenant isolation, job/scheduler logic, or reminder cadence.
- No new dependencies unless strictly required for the infra task (the one exception: `fcgi` Alpine package, needed for a real php-fpm healthcheck — flagged, not a judgment call, standard pattern).
- No secrets committed to git. `.env.production.example` contains placeholders only, no real domain, no real credentials.
- `APP_URL` in the production example must NOT contain a fictitious domain — leave it as an IP placeholder comment, not a fake domain string.
- Postgres and Redis: no host port publishing in `docker-compose.prod.yml`.
- No Redis password added now (explicit deferral to later hardening).
- No `storage:link` usage for private uploads.
- No SSL/Certbot, no domain/DNS config, no CI/CD, no backups automation — out of scope per user.

---

### Task 1: php-fpm healthcheck support (fcgi + ping.path)

**Files:**
- Modify: `Dockerfile`
- Create: `docker/php/fpm-ping.conf`

**Interfaces:**
- Produces: an `app` image where `apk info -e fcgi` succeeds and FPM responds "pong" to a FastCGI `ping` request at `127.0.0.1:9000`, script name `/ping`. This is consumed by Task 3's `app` healthcheck.

- [ ] **Step 1: Create the FPM ping pool override**

`docker/php/fpm-ping.conf`:
```ini
[www]
ping.path = /ping
ping.response = pong
```

- [ ] **Step 2: Wire it into the Dockerfile's `app` stage**

In `Dockerfile`, in the `FROM php:${PHP_VERSION}-fpm-alpine AS app` stage, change:
```dockerfile
RUN apk add --no-cache postgresql-client icu-dev libzip-dev libpng-dev
```
to:
```dockerfile
RUN apk add --no-cache postgresql-client icu-dev libzip-dev libpng-dev fcgi
```
and add, alongside the existing `opcache.ini`/`uploads.ini` copies:
```dockerfile
COPY docker/php/fpm-ping.conf /usr/local/etc/php-fpm.d/zz-ping.conf
```
(placed right after the `COPY docker/php/uploads.ini ...` line, before `WORKDIR /var/www/html`).

- [ ] **Step 3: Verify — build the app stage and hit the ping endpoint**

```bash
docker build --target app -t cdc-app-test .
docker run --rm -d --name cdc-fpm-test cdc-app-test
sleep 1
docker exec cdc-fpm-test sh -c \
  "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000"
docker stop cdc-fpm-test
```
Expected: output contains `pong`.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile docker/php/fpm-ping.conf
git commit -m "feat: add fcgi ping healthcheck support to php-fpm image"
```

---

### Task 2: nginx production build stage

**Files:**
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: `public/` directory as it exists in the `app` stage after `COPY --chown=app:app . .` + the Vite build copy (Task none — already present in current Dockerfile).
- Produces: a `nginx` build target (image) containing `/var/www/html/public` (static assets + `index.php`) and `/etc/nginx/conf.d/default.conf`. Consumed by Task 3's `nginx` service (`build.target: nginx`).

- [ ] **Step 1: Append the nginx stage to the Dockerfile**

At the end of `Dockerfile`, after the existing `app` stage (after `CMD ["php-fpm"]`), add:
```dockerfile

FROM nginx:1.27-alpine AS nginx
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
```
(Version pinned to `1.27-alpine` to match what dev compose already uses for its `nginx` service image, so behavior stays identical between dev and prod.)

- [ ] **Step 2: Verify — build the nginx stage and confirm it serves the SPA shell**

```bash
docker build --target nginx -t cdc-nginx-test .
docker run --rm -d --name cdc-nginx-test -p 18080:80 cdc-nginx-test
sleep 1
curl -sf http://127.0.0.1:18080/ -o /dev/null && echo "OK: nginx served index"
docker exec cdc-nginx-test test -d /var/www/html/public/build && echo "OK: build assets present"
docker exec cdc-nginx-test sh -c '[ ! -d /var/www/html/storage ]' && echo "OK: storage/ absent from nginx image"
docker stop cdc-nginx-test
```
Expected: all three "OK" lines print. (The `/` request will hit `try_files` → `index.php`, which nginx will try to proxy to `app:9000` — that upstream won't exist in this standalone test, so nginx will return a 502; that's fine, `curl -sf` on `/` is only here to prove nginx itself is serving from the right root without crashing. Adjust: use `-o /dev/null` without `-f` if you want to just confirm nginx responds at all, not that PHP is reachable.)

Actually — simplify: since there's no `app` upstream in this isolated test, just confirm nginx starts and serves a static file directly:
```bash
docker exec cdc-nginx-test ls /var/www/html/public/build/manifest.json
```
Expected: prints a path, exit code 0 (nginx doesn't even need to be queried for this — it's confirming the COPY worked).

- [ ] **Step 3: Commit**

```bash
git add Dockerfile
git commit -m "feat: add nginx production build stage with baked-in public assets"
```

---

### Task 3: docker-compose.prod.yml

**Files:**
- Create: `docker-compose.prod.yml`

**Interfaces:**
- Consumes: `app` and `nginx` build targets from `Dockerfile` (Tasks 1–2), existing `docker/nginx/default.conf` (unchanged — nginx `root` is `/var/www/html/public`, and since the `nginx` image only ever contains `public/`, `storage/app/private` is physically absent from that container — no extra `deny` rule needed for it).
- Produces: the full production stack definition, consumed by `docs/deployment.md` (Task 5) deploy commands.

- [ ] **Step 1: Write the file**

`docker-compose.prod.yml`:
```yaml
services:
  app:
    build:
      context: .
      target: app
      args:
        UID: ${UID:-1000}
        GID: ${GID:-1000}
    image: client-document-chaser-app:latest
    restart: unless-stopped
    volumes:
      - private_storage:/var/www/html/storage/app/private
    env_file: .env
    healthcheck:
      test: ["CMD-SHELL", "SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 20s
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  nginx:
    build:
      context: .
      target: nginx
    restart: unless-stopped
    ports:
      - "80:80"
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- http://127.0.0.1/up > /dev/null || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 30s
    depends_on:
      app:
        condition: service_healthy

  worker:
    image: client-document-chaser-app:latest
    restart: unless-stopped
    command: php artisan queue:work --tries=3 --max-time=3600
    volumes:
      - private_storage:/var/www/html/storage/app/private
    env_file: .env
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  scheduler:
    image: client-document-chaser-app:latest
    restart: unless-stopped
    command: php artisan schedule:work
    volumes:
      - private_storage:/var/www/html/storage/app/private
    env_file: .env
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_healthy

  postgres:
    image: postgres:18-alpine
    restart: unless-stopped
    environment:
      POSTGRES_DB: client-document-chaser
      POSTGRES_USER: ${DB_USERNAME:-postgres}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-postgres} -d client-document-chaser"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: redis:8-alpine
    restart: unless-stopped
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

Notes baked into this design (no further decisions needed):
- `worker`/`scheduler` have no `build:` block — they reference the `image:` tag that `app`'s build produces, so `docker compose build` (which only builds services with a `build:` section) builds the image once and all three containers use the identical artifact, per the user's requirement.
- `worker` and `scheduler` both mount `private_storage`, matching the dev compose's existing behavior (dev already mounts it on `scheduler` too) — kept for parity/safety even though no current scheduled command touches uploaded files, per the research finding.
- No `ports:` on `postgres`, `redis`, `app`, `worker`, `scheduler` — only `nginx` publishes `80:80`.
- `app`/`worker`/`scheduler` have no source bind mount at all — only the `private_storage` named volume.

- [ ] **Step 2: Validate the compose file syntax**

```bash
docker compose -f docker-compose.prod.yml config -q
```
Expected: no output, exit code 0 (variables like `${DB_PASSWORD}` will resolve empty without a `.env` present, which is expected at this stage — `-q` suppresses the interpolation warning dump but still validates syntax/schema).

- [ ] **Step 3: Commit**

```bash
git add docker-compose.prod.yml
git commit -m "feat: add production docker-compose stack"
```

---

### Task 4: .env.production.example

**Files:**
- Create: `.env.production.example`

**Interfaces:**
- Consumes: nothing (standalone reference file).
- Produces: the template `docs/deployment.md` (Task 5) tells the operator to copy to `.env` on the VPS.

- [ ] **Step 1: Write the file**

`.env.production.example` (placeholders only, no real secrets, no fictitious domain):
```env
APP_NAME="Client Document Chaser"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
# First deploy: point this at the VPS IP, e.g. http://203.0.113.10
# Switch to https://<your-domain> once DNS + SSL are configured (out of scope for this deploy).
APP_URL=http://CHANGE_ME_VPS_IP

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=client-document-chaser
DB_USERNAME=postgres
DB_PASSWORD=CHANGE_ME_STRONG_PASSWORD

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
# Set to true once HTTPS is in front of the app (see docs/deployment.md).
SESSION_SECURE_COOKIE=false

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
UPLOAD_MAX_SIZE_KB=10240
QUEUE_CONNECTION=redis

CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=CHANGE_ME_BREVO_SMTP_USERNAME
MAIL_PASSWORD=CHANGE_ME_BREVO_SMTP_KEY
MAIL_FROM_ADDRESS="CHANGE_ME@yourdomain.example"
MAIL_FROM_NAME="${APP_NAME}"

REMINDER_INTERVAL_DAYS=2
REMINDER_MAX_COUNT=3

VITE_APP_NAME="${APP_NAME}"
```

- [ ] **Step 2: Verify no secret-shaped values leaked**

```bash
grep -E "=[A-Za-z0-9+/]{20,}" .env.production.example || echo "OK: no long opaque values"
```
Expected: "OK: no long opaque values" (everything is either empty, a `CHANGE_ME_*` placeholder, or a known non-secret default).

- [ ] **Step 3: Commit**

```bash
git add .env.production.example
git commit -m "docs: add production .env template with placeholders"
```

---

### Task 5: docs/deployment.md

**Files:**
- Create: `docs/deployment.md`

**Interfaces:**
- Consumes: exact service names/commands from `docker-compose.prod.yml` (Task 3), exact keys from `.env.production.example` (Task 4).
- Produces: nothing consumed by other tasks — this is the terminal deliverable for the human operator.

- [ ] **Step 1: Write the file**

`docs/deployment.md`:
```markdown
# Deployment (VPS, first production deploy)

Target: Ubuntu VPS with Docker + Docker Compose already installed.

This covers the *first* deploy only — no domain/DNS, no SSL/Certbot (see
"After this deploy" at the bottom). `APP_URL` uses the VPS IP for now.

## Prerequisites

- Docker Engine + Docker Compose v2 plugin installed on the VPS.
- This repository cloned onto the VPS (e.g. `git clone <repo-url> /opt/client-document-chaser`).
- A Brevo SMTP username + API/SMTP key (domain/sender authentication on Brevo's
  side is a separate task — not required for the app to *send*, only for
  reliable inbox delivery).

## 1. Create `.env`

On the VPS, inside the repo root:

```bash
cp .env.production.example .env
```

Edit `.env` and replace every `CHANGE_ME_*` placeholder:

- `APP_KEY` — leave blank for now, generated in step 3.
- `APP_URL` — the VPS's public IP, e.g. `http://203.0.113.10`.
- `DB_PASSWORD` — a strong random password (this also becomes the Postgres
  container's password via `docker-compose.prod.yml`).
- `MAIL_USERNAME` / `MAIL_PASSWORD` — Brevo SMTP credentials.
- `MAIL_FROM_ADDRESS` — the sending address.

Do not commit this file. It is already covered by `.gitignore`.

## 2. Build the images

```bash
docker compose -f docker-compose.prod.yml build
```

This builds the `app` image (used by `app`, `worker`, `scheduler`) and the
`nginx` image (with `public/` baked in) from the multi-stage `Dockerfile`.

## 3. Start the database and cache first

```bash
docker compose -f docker-compose.prod.yml up -d postgres redis
```

Wait for both to report healthy:

```bash
docker compose -f docker-compose.prod.yml ps
```

## 4. Generate the app key

```bash
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate
```

This writes `APP_KEY` into `.env` on the host (bind-mounted `.env` via
`env_file`, not the source tree — this is a one-off `run`, not a persistent
container).

## 5. Start the rest of the stack

```bash
docker compose -f docker-compose.prod.yml up -d
```

This starts `app`, `worker`, `scheduler`, and `nginx` (nginx waits for
`app`'s healthcheck via `depends_on: condition: service_healthy`).

## 6. Run migrations

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

`--force` is required because `APP_ENV=production` blocks interactive
migration prompts.

## 7. Cache config/routes/views

```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache
```

Re-run these three any time `.env` or route/view files change and you
redeploy — stale cached config is a common source of "it works locally but
not on the VPS" bugs.

## 8. Verify the app is up

```bash
curl -i http://<VPS_IP>/up
```
Expected: `HTTP/1.1 200 OK`.

## 9. Verify the worker is processing jobs

```bash
docker compose -f docker-compose.prod.yml logs -f worker
```
Expected: no repeated exceptions; on a real reminder send you'll see the job
log lines from `queue:work`.

## 10. Verify the scheduler is running

```bash
docker compose -f docker-compose.prod.yml logs -f scheduler
```
`schedule:work` logs each time it wakes up (every minute) and whenever it
dispatches `reminders:send` (hourly, per `routes/console.php`).

## Logs

```bash
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f nginx
docker compose -f docker-compose.prod.yml logs -f worker
docker compose -f docker-compose.prod.yml logs -f scheduler
docker compose -f docker-compose.prod.yml logs -f postgres
docker compose -f docker-compose.prod.yml logs -f redis
```

Laravel's own log is also inside the `app`/`worker`/`scheduler` containers at
`storage/logs/laravel.log` if you need to `exec` in and inspect it directly.

## Restarting a service

```bash
docker compose -f docker-compose.prod.yml restart app
docker compose -f docker-compose.prod.yml restart worker
docker compose -f docker-compose.prod.yml restart scheduler
docker compose -f docker-compose.prod.yml restart nginx
```

## Redeploying after a code change

```bash
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache
```

## Persistent data

Three named volumes, all Docker-managed (not bind mounts — survive
`docker compose down` as long as you don't pass `-v`):

- `pgdata` — PostgreSQL data directory.
- `redisdata` — Redis AOF persistence file.
- `private_storage` — uploaded client documents (`storage/app/private` inside
  `app`/`worker`/`scheduler`). Recreating any of these three containers does
  **not** delete uploads — the volume is external to the container
  filesystem.

## After this deploy (not covered here)

- Domain + DNS pointing at the VPS.
- SSL/TLS (Certbot or equivalent) in front of `nginx`, then set
  `SESSION_SECURE_COOKIE=true` and update `APP_URL` to `https://`.
- Brevo sender/domain authentication (SPF/DKIM) for reliable email delivery.
- Automated backups of `pgdata` and `private_storage`.
- Redis password (currently unset — the container isn't reachable from
  outside the Docker network, so this is a defense-in-depth item, not a
  blocker).
```

- [ ] **Step 2: Verify no secrets in the doc**

```bash
grep -E "PASSWORD=[^C\"'[:space:]]|KEY=[^C\"'[:space:]]" docs/deployment.md || echo "OK: no leaked secret-shaped values"
```
Expected: "OK: no leaked secret-shaped values" (the doc only ever shows env *keys* or `CHANGE_ME_*` placeholders, never real values).

- [ ] **Step 3: Commit**

```bash
git add docs/deployment.md
git commit -m "docs: add production deployment guide"
```

---

### Task 6: Full validation pass

**Files:** none created/modified — this task only runs verification commands across everything from Tasks 1–5.

**Interfaces:**
- Consumes: `Dockerfile` (Tasks 1–2), `docker-compose.prod.yml` (Task 3), `.env.production.example` (Task 4), `docs/deployment.md` (Task 5).
- Produces: nothing — terminal validation task.

- [ ] **Step 1: Validate compose file**

```bash
docker compose -f docker-compose.prod.yml config -q && echo "OK: compose config valid"
```

- [ ] **Step 2: Build both images end-to-end**

```bash
docker compose -f docker-compose.prod.yml build
```
Expected: both `app` and `nginx` targets build successfully with no errors.

- [ ] **Step 3: Run the existing test suite against the built app image**

The `app` image excludes dev dependencies (`composer install --no-dev`), so
Pest/PHPUnit aren't present in it — that's correct for a production image.
Run the suite instead against a disposable container using the same PHP
version, with dev deps installed, sqlite in-memory (matches `phpunit.xml`):

```bash
docker run --rm -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint sh php:8.5-cli-alpine -c '
    apk add --no-cache git unzip icu-dev libzip-dev libpng-dev $>/dev/null &&
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer &&
    install-php-extensions pdo_sqlite sqlite3 intl zip gd 2>/dev/null || docker-php-ext-install pdo_sqlite &&
    composer install --no-interaction --prefer-dist &&
    php artisan test
  '
```
Expected: all tests pass (0 failures). If the ad-hoc extension install is
flaky in the sandboxed environment, fall back to building the `app` target
with a temporary `--build-arg` that skips `--no-dev` for a throwaway local
tag, or run this step directly on a machine with PHP 8.4+/8.5 installed
(`composer install && php artisan test`). Either way, this step must show
real PASS output before Task 6 is considered done — do not report success
without pasting the actual result.

- [ ] **Step 4: Run the frontend build**

```bash
npm run build
```
Expected: Vite build completes, `public/build/manifest.json` is written.

- [ ] **Step 5: Confirm no dev-server / Vite-dev references leaked into prod files**

```bash
grep -n "vite\b" docker-compose.prod.yml || echo "OK: no vite service in prod compose"
grep -n "npm run dev" Dockerfile docker-compose.prod.yml || echo "OK: no dev server command in prod files"
```
Expected: both "OK" lines print.

- [ ] **Step 6: Confirm no secrets in anything staged for commit**

```bash
git diff --cached --name-only
git diff --cached -- .env.production.example docs/deployment.md | grep -iE "password|secret|key" 
```
Manually eyeball the second command's output — every match must be a
placeholder (`CHANGE_ME_*`), an env var *name*, or documentation prose, never
a real value. `.env` itself must not appear in `git status` as tracked.

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --test
```
(Run this from wherever `composer install` with dev deps succeeded — either
the Task 6 Step 3 container or a local PHP 8.4+/8.5 install, since Pint is a
dev dependency and won't exist in a `--no-dev` vendor tree.)
Expected: "PASS" — no files need formatting. If it reports files needing
fixes, that's pre-existing repo state, not something this plan introduces;
do not run `pint` (non-`--test`) to auto-fix, since that would touch
application files outside this plan's scope — report it instead.

- [ ] **Step 8: Final report**

Summarize for the user: which images built, test pass/fail counts, Pint
result, confirmation of no exposed ports on postgres/redis, confirmation no
secrets committed, and the exact command sequence from `docs/deployment.md`
as the literal "first deploy" instructions.
```

---

## Self-Review Notes

- **Spec coverage:** every numbered section of the user's request (1 Docker Compose prod, 2 Dockerfile, 3 Nginx, 4 Healthcheck, 5 Ambiente, 6 Storage, 7 Queue, 8 Scheduler, 9 Postgres, 10 Redis, 11 Documentação, 13 Validação) maps to Tasks 1–6 above. Section 12 ("NÃO FAZER") is enforced via Global Constraints, not a task.
- **Placeholder scan:** no TBD/TODO; every code block is literal, runnable content.
- **Type/name consistency:** `client-document-chaser-app:latest` image tag is used identically in Task 3's `app`, `worker`, `scheduler` service defs. `private_storage`, `pgdata`, `redisdata` volume names match the existing dev compose exactly (same physical volumes reused, not renamed) — intentional, since both dev and prod are expected to run on the *same* Docker host in different compose projects only if the operator deliberately shares them; on a fresh VPS this is moot (no dev compose ever runs there).
