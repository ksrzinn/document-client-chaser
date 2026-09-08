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

- `APP_KEY` — leave blank for now, generated in step 4 and pasted in
  manually before step 5.
- `APP_URL` — the VPS's public IP, e.g. `http://203.0.113.10`.
- `DB_PASSWORD` — a strong random password (this also becomes the Postgres
  container's password via `docker-compose.prod.yml`). Avoid a literal `$`
  character in the generated value: Docker Compose interpolates
  `${DB_PASSWORD}` when setting `POSTGRES_PASSWORD`, while the same raw value
  is passed to Laravel literally via `env_file` — a `$` can make the two
  diverge, which then looks like a Postgres auth bug.
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
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show
```

This prints a `base64:...` value — it does **not** write anything back to
`.env` on the host. `docker-compose.prod.yml` has no source bind-mount by
design (that's the point of the production image), so there is no host
`.env` file inside the container to write to; `env_file:` only injects
variables into the container's environment, it does not mount the file. Copy
the printed value and paste it into `.env` on the host yourself:

```
APP_KEY=base64:...
```

Do this **before** step 5 — starting the app with an empty `APP_KEY` makes
Laravel throw "No application encryption key has been specified" on every
request, including `/up`, so nginx's healthcheck never passes either.

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

Laravel's own application-level logs (exceptions, stack traces — not just
php-fpm/nginx access logs) flow directly to `docker compose logs app` /
`worker` / `scheduler` via `LOG_CHANNEL=stderr`, so no `exec` is needed to
read them.

## Restarting a service

```bash
docker compose -f docker-compose.prod.yml restart app
docker compose -f docker-compose.prod.yml restart worker
docker compose -f docker-compose.prod.yml restart scheduler
docker compose -f docker-compose.prod.yml restart nginx
```

If you recreate/restart the `app` container on its own, restart `nginx`
afterward too (`docker compose -f docker-compose.prod.yml restart nginx`) —
nginx resolves the `app` upstream hostname at its own startup, not
per-request, so a stale resolution can otherwise persist until nginx
restarts.

## Redeploying after a code change

```bash
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
# nginx resolves the `app` hostname at its own boot, not per-request, so a
# code-only redeploy can rebuild `app` (new container IP) with a byte-identical
# `nginx` image left untouched — restart it here so it doesn't stay pointed
# at a dead upstream.
docker compose -f docker-compose.prod.yml restart nginx
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
