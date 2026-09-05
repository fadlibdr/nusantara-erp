# Deployment Runbook — Nusantara ERP (Production)

Operator guide for running the ERP in production with Docker Compose. Every
command below is copy-pasteable and assumes the repository is checked out at
`/opt/erp/construction-erp` on the server. (Panduan operator; semua perintah
bisa langsung di-copy-paste.)

The stack (`docker-compose.prod.yml`):

| Service | Image | Peran |
|---|---|---|
| `app` | built from `Dockerfile` | php-fpm (API) |
| `nginx` | `nginx:1.27-alpine` | HTTP server, port `127.0.0.1:8080` → app:9000 |
| `queue` | same image as `app` | `php artisan queue:work redis --tries=3 --backoff=10 --max-time=3600` |
| `scheduler` | same image as `app` | `php artisan schedule:work` (jadwal PM ServiceDesk, dll.) |
| `mysql` | `mysql:8.0` | database, volume `erp-mysql-data` |
| `redis` | `redis:7-alpine` | cache + session + queue, volume `erp-redis-data` |

TLS terminates **outside** this stack: nginx listens plain HTTP on
`127.0.0.1:8080`, and a reverse proxy on the host provides HTTPS (see
[TLS](#2-tls--reverse-proxy)).

---

## 1. Prerequisites

- **VM**: 4 GB+ RAM, 2+ vCPU, 40 GB+ disk (MySQL data + backups grow),
  Ubuntu 22.04/24.04 or similar.
- **Docker Engine** (≥ 24) **with the compose plugin** — verify with
  `docker compose version`. Install per <https://docs.docker.com/engine/install/>.
- **git** on the server (deploys are git-pull based).
- **DNS**: an A record for your domain (mis. `erp.example.co.id`) pointing at
  the VM.
- A decision on **TLS** (next section) — pick one before the first deploy so
  `APP_URL` is correct from the start.
- Somewhere **offsite** to copy backups (S3/object storage/rclone remote).

## 2. TLS / reverse proxy

The compose stack binds nginx to `127.0.0.1:8080` only — nothing is exposed
publicly until you put a TLS-terminating proxy in front of it. **The proxy
MUST send `X-Forwarded-Proto: https`** (secure cookies and `https://` URL
generation depend on it). Pick one option:

**Option A — host Caddy (recommended, simplest).** Install Caddy on the VM,
then `/etc/caddy/Caddyfile`:

```
erp.example.co.id {
    reverse_proxy 127.0.0.1:8080
}
```

`systemctl reload caddy` — certificates are obtained and renewed
automatically, and Caddy sets `X-Forwarded-Proto` by itself.

**Option B — host nginx + certbot.** Install nginx and certbot on the VM,
create a site:

```nginx
server {
    listen 80;
    server_name erp.example.co.id;

    client_max_body_size 26m;      # match the in-stack nginx upload limit

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    }
}
```

then `certbot --nginx -d erp.example.co.id` (certbot rewrites the site to
443 + redirect and installs the renewal timer).

**Option C — Traefik.** If the host already runs Traefik, add a router/service
pointing at `127.0.0.1:8080` (file provider) with a certresolver; make sure
`X-Forwarded-Proto` passes through (Traefik does this by default).

**Option D — containerized Caddy.** `docker-compose.prod.yml` ships a
commented-out `caddy` service (bottom of the file): uncomment it plus its two
volumes, set `ERP_DOMAIN` in `.env`, and skip the host proxy entirely.

### 2.1 Batas unggah lampiran (post_max_size / upload_max_filesize)

Attachment size limits live in three places that must agree, and the deploy
already sets them consistently — change one, change all:

| Layer | Setting | Value | Where |
|---|---|---|---|
| PHP | `upload_max_filesize` | **25M** | `deploy/docker/php.ini` |
| PHP | `post_max_size` | **26M** | `deploy/docker/php.ini` |
| in-stack nginx | `client_max_body_size` | **26m** | `deploy/nginx/app.conf` |
| host proxy | body limit | **26m** | section 2 above |

The application enforces its own per-extension policy on top
(`AttachmentService`): 5 MB default, 25 MB for `dwg`/`dxf`/`mpp`. The
arithmetic that shapes the two upload routes: the JSON route carries files as
base64, which inflates by a third — 5 MB becomes ~6.7 MB on the wire, safely
inside `post_max_size`; 25 MB would become ~33.4 MB, over the 26M limit, which
is why files of the 25 MB class travel raw on the multipart route
(`POST api/core/attachments/upload`), where 25 MB + framing still fits 26M and
exactly matches `upload_max_filesize`. A request the proxy or php-fpm refuses
dies as an empty **413** before the app can say anything friendly (see
Troubleshooting) — the limits above exist so that in practice only the app's
own, explained refusals are ever hit.

One deliberate seam remains: a multipart file over 25M whose body still fits
`post_max_size` is dropped by PHP alone (`upload_max_filesize`), so Laravel
answers **422 `The file failed to upload.`** — English, no size named —
instead of the app's Indonesian per-extension message. Because the app's own
cap is the same 25 MB, only a genuinely over-limit file can ever land there;
the friendly message is what users see below that threshold.

Uploaded attachments are **live data**, like the database: the backup tars
`storage/app` (section 5), and code-sync scripts must keep excluding it —
`deploy/sync-erp1.sh` excludes `storage/app/private/**` and
`storage/app/public/**` from its `rsync --delete` precisely so a deploy can
never wipe what users attached. That exclusion stays, whatever the limits
above are tuned to.

## 3. First deployment (langkah demi langkah)

**3.1 — Clone and configure**

```bash
sudo mkdir -p /opt/erp && cd /opt/erp
git clone <REPO_URL> construction-erp
cd /opt/erp/construction-erp
cp .env.production.example .env
chmod 600 .env
```

**3.2 — Build the image** (needed before we can generate a key — there is no
PHP on the host):

```bash
docker compose -f docker-compose.prod.yml build
```

**3.3 — Generate `APP_KEY`** without local PHP, using the image just built
(`--entrypoint php` skips the container entrypoint, which would otherwise
wait for a database that isn't up yet):

```bash
docker run --rm --entrypoint php construction-erp-app:latest artisan key:generate --show
```

Copy the printed `base64:...` value into `APP_KEY=` in `.env`.

**3.4 — Fill in `.env`.** Every `CHANGE_ME` must go. Minimum:

- `APP_URL` — your real HTTPS URL, e.g. `https://erp.example.co.id`.
- `DB_USERNAME` / `DB_PASSWORD` — strong random password:
  `openssl rand -base64 32`. Also `DB_ROOT_PASSWORD` (different value) if you
  want a distinct MySQL root password; MySQL is initialized from these on
  **first** boot only.
- `CORS_ALLOWED_ORIGINS` — the exact origin(s) of your frontend, or leave
  empty for no browser cross-origin access.
- `ERP_ADMIN_NAME` / `ERP_ADMIN_EMAIL` / `ERP_ADMIN_PASSWORD` — the initial
  admin account (min. 16 random chars for the password; wajib diganti setelah
  login pertama).

**3.5 — Start the stack:**

```bash
docker compose -f docker-compose.prod.yml up -d
```

The entrypoint waits for MySQL to become healthy, caches config/routes, and —
because the template ships `RUN_MIGRATIONS=1` — runs
`php artisan migrate --force` automatically on the `app` container. Watch it:

```bash
docker compose -f docker-compose.prod.yml logs -f app
```

(If you set `RUN_MIGRATIONS=0`, migrate manually instead:
`docker compose -f docker-compose.prod.yml exec app php artisan migrate --force`.)

**3.6 — Seed production data** (master data + roles/permissions + the one
admin from `ERP_ADMIN_*` — **no demo documents**; this is the only seeder
that may ever run in production):

```bash
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=ProductionSeeder --force
```

**3.7 — Verify:**

```bash
docker compose -f docker-compose.prod.yml ps        # everything Up / healthy
curl -fsS http://127.0.0.1:8080/up                  # Laravel health endpoint
curl -s -X POST https://erp.example.co.id/api/iam/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"<ERP_ADMIN_EMAIL>","password":"<ERP_ADMIN_PASSWORD>"}'
```

The login response contains a bearer token; use it as
`Authorization: Bearer <token>`.

**3.8 — IMMEDIATELY after first login (jangan ditunda):**

1. Create real user accounts (`POST /api/iam/users`, assign roles via
   `POST /api/iam/users/{id}/roles`) — nobody works day-to-day as admin.
2. Rotate the admin password (`PUT /api/iam/users/{id}`) to a fresh value
   stored only in your password manager.
3. Scrub `ERP_ADMIN_PASSWORD` from `.env` (the seeder only reads it during
   seeding) — replace the value with `ROTATED`.
4. Update the company profile via `api/core` (NPWP/NIB/PKP asli — seed values
   are placeholders).
5. Review the statutory tax/payroll parameters in `config/erp.php` — see the
   README section "Catatan pajak & payroll".

## 4. Routine deployment (update rilis)

```bash
cd /opt/erp/construction-erp
git pull                                            # or: git checkout v1.2.3
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d --no-deps app queue scheduler nginx
```

`up -d` recreates the containers from the new image; the entrypoint re-runs
`config:cache` / `route:cache` / `event:cache` automatically, and the queue
worker gets the new code by being replaced (it has a 60 s grace period to
finish the job in flight). MySQL/Redis are left untouched (`--no-deps`).

**Migrations** during routine deploys, either:

- automatic — keep `RUN_MIGRATIONS=1` in `.env`; the `app` container migrates
  at boot (queue/scheduler have it forced off, so there is no race), or
- manual, with maintenance mode for schema changes that aren't
  backward-compatible:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan down --retry=60
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan up
```

(`php artisan down` also accepts `--render=<view>` for a custom maintenance
page; for this API-only backend the default 503 response is fine.)

**Rollback**: `git checkout <previous-tag>`, then the same build + up
commands. Migrations are not automatically reversed — restore the database
from backup if a migration must be undone (section 5).

## 5. Backups & restore

### 5.1 Making backups

`deploy/backup/backup.sh` dumps MySQL (`mysqldump --single-transaction
--routines` through the `mysql` container) and tars `storage/app` (from the
`erp-storage` volume) into `/backups`, then prunes files older than
`BACKUP_KEEP_DAYS` (default 14). Test it once by hand:

```bash
cd /opt/erp/construction-erp
mkdir -p /backups
./deploy/backup/backup.sh
ls -lh /backups
```

Then schedule it with host cron (`crontab -e`):

```cron
30 1 * * * cd /opt/erp/construction-erp && ./deploy/backup/backup.sh >> /var/log/erp-backup.log 2>&1
45 1 * * * rclone copy /backups remote:erp-backups
```

The second line is the **offsite copy** — configure any rclone remote
(S3/GCS/SFTP/Drive) or use `aws s3 sync`. Backup di VM yang sama BUKAN
backup: the VM dying takes both with it.

> **Bare-metal SQLite deployment (erp1.pi2.co.id):** use
> `deploy/backup-erp1.sh` instead — it snapshots SQLite with `VACUUM INTO`,
> verifies the copy, then **encrypts (GPG AES-256) and pushes it offsite
> itself**, verifying the upload by checksum and pruning remote copies past
> `OFFSITE_KEEP_DAYS`. Configure the destination once in
> `/etc/erp1/backup.conf` (template: `deploy/erp1-backup.conf.example`) and
> keep a copy of `/etc/erp1/backup.key` somewhere that is not the server —
> without the key the offsite copies are unreadable exactly when needed.
> Prove the path end to end after configuring:
>
> ```bash
> /var/www/erp1.pi2.co.id/deploy/backup-erp1.sh --offsite-only    # "offsite ok"
> /var/www/erp1.pi2.co.id/deploy/backup-erp1.sh --restore-drill   # "RESTORE DRILL PASSED"
> ```
>
> Until a destination is configured every run exits non-zero and
> `erp:backup-watch` (scheduled 08:00 WIB) raises an in-app alarm to
> `core.approve` holders. That nagging is intentional — a local-only backup
> is the deficiency, not the alarm.
>
> While php-fpm has the database open there are also `database.sqlite-wal`
> and `database.sqlite-shm` next to it. Section 9.4 explains what they hold
> and why nothing but `backup-erp1.sh` may copy, restore or sync anything
> under `database/`.

### 5.2 Restore procedure

Say the files to restore are `erp-db-20260725-013000.sql.gz` and
`erp-storage-20260725-013000.tar.gz`. On the (repaired or fresh) server with
the stack configured as in section 3 (fresh server: run 3.1–3.5 first, skip
seeding):

```bash
cd /opt/erp/construction-erp

# 1. Stop traffic and background work; keep app, mysql, redis running.
docker compose -f docker-compose.prod.yml stop nginx queue scheduler

# 2. Restore the database (drops/recreates tables as per the dump).
gzip -dc /backups/erp-db-20260725-013000.sql.gz | \
  docker compose -f docker-compose.prod.yml exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"'

# 3. Restore storage/app into the erp-storage volume.
gzip -dc /backups/erp-storage-20260725-013000.tar.gz | \
  docker compose -f docker-compose.prod.yml exec -T app \
  tar -xzf - -C /var/www/html/storage

# 4. Bring everything back and verify.
docker compose -f docker-compose.prod.yml up -d
curl -fsS http://127.0.0.1:8080/up
```

Log in and spot-check a few documents (invoice terakhir, stok, payroll run).
**Do a restore drill on a scratch VM at least once** before you need it for
real — an untested backup is a hope, not a plan.

## 6. Monitoring

- **Health endpoint** — Laravel's `/up` (also used by uptime monitors;
  point yours at `https://erp.example.co.id/up`):

  ```bash
  curl -fsS http://127.0.0.1:8080/up
  ```

- **Container status** — all services should be `Up`, mysql/redis `healthy`:

  ```bash
  docker compose -f docker-compose.prod.yml ps
  ```

- **Queue backlog** — alert if pending jobs exceed the threshold:

  ```bash
  docker compose -f docker-compose.prod.yml exec app php artisan queue:monitor redis:default --max=100
  ```

- **Logs** — the app logs to stderr (`LOG_CHANNEL=stderr`,
  `LOG_LEVEL=warning`), so everything is in Docker's log stream:

  ```bash
  docker compose -f docker-compose.prod.yml logs -f --tail=100 app queue scheduler
  docker compose -f docker-compose.prod.yml logs -f nginx mysql redis
  ```

  To ship logs off-box, point a drain (promtail/vector/journald forwarding)
  at the Docker json-file logs, and set log rotation in
  `/etc/docker/daemon.json` (`"log-opts": {"max-size": "50m", "max-file": "5"}`).

- **Disk** — MySQL data and `/backups` grow; watch `df -h` and
  `docker system df`. Prune old images after deploys:
  `docker image prune -f`.

## 7. Security checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` — debug pages leak config and
      SQL. Never flip debug on in production; reproduce locally instead.
- [ ] `APP_KEY` set and stored in the password manager (losing it invalidates
      encrypted data).
- [ ] Strong unique `DB_PASSWORD` and `DB_ROOT_PASSWORD`
      (`openssl rand -base64 32` each; nilai berbeda).
- [ ] Redis and MySQL are reachable only on the compose network (no `ports:`
      mapping) — keep it that way; never publish 3306/6379.
- [ ] `CORS_ALLOWED_ORIGINS` pinned to the exact frontend origin(s) — no `*`.
- [ ] `SANCTUM_TOKEN_EXPIRATION=720` (12 h) — don't set it to `null`;
      stolen tokens must expire.
- [ ] `FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`, and the host proxy
      sends `X-Forwarded-Proto`.
- [ ] Firewall: only 80/443 (proxy) and SSH open — e.g.
      `ufw default deny incoming && ufw allow OpenSSH && ufw allow 80,443/tcp && ufw enable`.
      Port 8080 stays loopback-only (it already is in the compose file).
- [ ] `fail2ban` on the host for SSH; SSH by key only
      (`PasswordAuthentication no`).
- [ ] `.env` is `chmod 600`, never committed; `ERP_ADMIN_PASSWORD` scrubbed
      after seeding; admin password rotated after first login.
- [ ] Backups run nightly, copied offsite, and a restore has been drilled
      (section 5).
- [ ] **Yearly statutory review (tinjauan tahunan wajib)**: BPJS plafon &
      persentase, tabel PPh 21 TER, tarif PPN efektif, tarif PPh final jasa
      konstruksi — all parameters live in `config/erp.php` (TER bracket data
      in `Modules/HrPayroll`). See README "Catatan pajak & payroll". Angka
      statutori berubah; jadwalkan review tiap Januari.

### 7.1 Taking the erp1 demo gate down (ordered, and the order matters)

> **Status 5 Sep 2026 — the gate is DOWN.** Removed at the owner's instruction
> ("remove demo authentication") **without step 3 (rotation)**: all eleven seeded
> accounts remain on the seeded password (owner decision, 31 Aug 2026), and the
> `main` login page still lists four of them. The two `auth_basic` lines are
> commented out in `/etc/nginx/sites-available/erp1.pi2.co.id`;
> `/etc/nginx/.htpasswd-erp1` is untouched. **Rollback = uncomment those two
> lines, then `nginx -t && systemctl reload nginx`.** The `.bak-gate-20260904-210857`
> copy beside it was taken *after* the lines were already commented, so it is not
> a pre-change snapshot. `/up`, `/` and `/app/` answer with
> `X-Robots-Tag: noindex, nofollow`; login stays throttled `10,1`. What is public
> now: a writable ERP whose seeded credentials are documented in this file.

`erp1.pi2.co.id` sits behind an nginx Basic-auth gate that is **not** the ERP
login. The nginx config says why in its own comment: the demo carries the eleven
seeded accounts and their password is literally `password`, `admin@nusantara.test`
among them — an account holding every permission the application defines, able to
post journals, close periods, move stock and delete documents. **The gate is the
only thing making that publishable.** Remove it first and you have published a
writable ERP to anyone who can read the seeder.

Do it in this order, and stop if a step reports something unexpected.

1. **See the scope.** `php artisan erp:harden-demo-logins --dry-run` lists every
   account still on the shipped password, its roles, and warns if `admin` is in
   the set. Changes nothing, asks for nothing.

2. **Decide what the demo is FOR after the gate is off.** This is the step people
   skip, and skipping it undoes the rest. A demo nobody can sign into is not a
   demo, so publishing the site means publishing a working login — and a rotated
   password that then appears on the landing page has landed exactly where it
   started: one shared credential with full write access. The durable shape is a
   split:
   - one **view-only** account, published freely, holding `.view` permissions and
     nothing else, so the worst a stranger can do is read;
   - every **write-capable** account rotated to a value that is never published.

   Keep the split with `--except`:
   `php artisan erp:harden-demo-logins --except=demo@nusantara.test`

3. **Rotate.** `php artisan erp:harden-demo-logins` prompts for the new password
   twice and never takes it as an argument — an argument would land in shell
   history and in `ps`, both readable by exactly the person this is defending
   against. It refuses anything under 12 characters or on its retype-guard list,
   and it revokes every Sanctum token afterwards, because a password change does
   not: a bearer token minted while the demo was open outlives the rotation.

4. **Verify the rotation actually landed** before touching nginx:
   `php artisan erp:harden-demo-logins --dry-run` should now report
   "No account is still on the seeded password."

5. **Only now, remove the gate.** Delete these two lines from
   `/etc/nginx/sites-enabled/erp1.pi2.co.id`:

   ```
   auth_basic           "Akses demo — username: demo (bukan login ERP)";
   auth_basic_user_file /etc/nginx/.htpasswd-erp1;
   ```

   then `nginx -t && systemctl reload nginx`. Keep a dated backup of the config
   first; restoring those two lines is the whole rollback.

6. **Confirm what is now public.** `curl -sS -o /dev/null -D - https://erp1.pi2.co.id/`
   should answer `200` with `X-Robots-Tag: noindex, nofollow` present. That header
   is already configured, in the server block **and** repeated inside `location
   ^~ /app/` — nginx drops every inherited `add_header` at any level that declares
   one of its own, so a header set only once would silently not cover the SPA.

Already in place, and worth not undoing: login is throttled at `throttle:10,1`
(`Modules/Iam/Routes/api.php`) and the whole API by `throttleApi()`, so
brute-forcing the new password is bounded rather than free.

## 8. Troubleshooting (first-boot & common)

| Symptom | Likely cause | Fix |
|---|---|---|
| `app` exits with `database not reachable after 60s` | MySQL still initializing (first boot takes ~30 s) or wrong `DB_HOST` | `docker compose -f docker-compose.prod.yml logs mysql`; ensure `DB_HOST=mysql`; raise `DB_WAIT_TIMEOUT` in `.env`; `up -d` again |
| `SQLSTATE[HY000] [1045] Access denied` | `DB_*` in `.env` changed **after** the mysql volume was initialized (creds live in the volume, not the env) | Restore matching creds, or change the password inside MySQL (`ALTER USER`), or — first boot only, destroys data — `docker compose -f docker-compose.prod.yml down -v` and start over |
| `MissingAppKeyException` / "No application encryption key" | `APP_KEY` empty in `.env` | Step 3.3, paste the key, `docker compose -f docker-compose.prod.yml up -d` |
| nginx returns **502 Bad Gateway** | `app` container not up yet (waiting for DB) or crashed | `docker compose -f docker-compose.prod.yml ps` + `logs app` |
| Every request returns **503** | Maintenance mode is on | `docker compose -f docker-compose.prod.yml exec app php artisan up` |
| Browser calls fail with CORS errors | `CORS_ALLOWED_ORIGINS` empty or origin mismatch (scheme/port count) | Set the exact origin in `.env`, then recreate `app` (`up -d --no-deps app`) so config re-caches |
| Redirects/URLs generated as `http://`, cookies rejected | Host proxy not sending `X-Forwarded-Proto`, or `FORCE_HTTPS` unset | Fix proxy headers (section 2); `FORCE_HTTPS=true` |
| **413 Request Entity Too Large** on uploads | Host proxy body limit below 26 m | Add `client_max_body_size 26m;` (nginx) or equivalent on the host proxy |
| **422 `The file failed to upload.`** on `attachments/upload` | File over `upload_max_filesize` (25M): PHP dropped it before Laravel saw it, body still fit `post_max_size` | Working as designed — the app's own cap is the same 25 MB (section 2.1). If legitimate files hit this, both numbers must move together |
| Entrypoint warns `route:cache failed (closure routes?)` | A route file defines closure routes | Non-fatal — app runs with uncached routes; convert closures to controllers to re-enable the cache |
| `ProductionSeeder` aborts about `ERP_ADMIN_*` | `ERP_ADMIN_EMAIL`/`ERP_ADMIN_PASSWORD` missing or too weak | Set them in `.env`, `up -d --no-deps app`, re-run the seed command (idempotent) |
| `Permission denied` writing `storage/app` | Volume created with root ownership before the image's `erp` user ran | `docker compose -f docker-compose.prod.yml exec -u root app chown -R erp:erp /var/www/html/storage/app` |
| Jobs stuck as `pending`, emails/exports never happen | `queue` container down, or `QUEUE_CONNECTION` not `redis` | `docker compose -f docker-compose.prod.yml ps` + `logs queue`; check `.env` |
| Scheduled tasks (PM ServiceDesk) not firing | `scheduler` container down | `docker compose -f docker-compose.prod.yml ps` + `logs scheduler` |
| Requests hang as "pending", then nginx answers **503** during a burst (erp1, bare-metal SQLite — measured 2 Sep 2026 at ~40 requests, HASIL-UJI-UX-2026-09 §6.4) | php-fpm workers queue on the SQLite write lock and exhaust `pm.max_children` | Section 9: confirm the three PRAGMAs reach the app's connection (`5000 wal 1`); if `pm.max_children` ≤ 5, raise it to 10–16 |

## 9. SQLite: WAL, busy_timeout, synchronous (bare-metal erp1)

This section applies only where `DB_CONNECTION=sqlite` — today the bare-metal
demo at `erp1.pi2.co.id`, where the whole ERP is one file,
`database/database.sqlite`, served by php-fpm without Docker. The MySQL stack
above never sends these pragmas. (Hanya untuk deployment SQLite.)

### 9.1 What is set, and where

`config/database.php`, connection `sqlite`, has carried three keys since the
initial commit (3b933f1, 22 Aug 2026). Laravel's `SQLiteConnector`
(`vendor/laravel/framework/src/Illuminate/Database/Connectors/SQLiteConnector.php`)
turns each one into a `PRAGMA` right after it opens the PDO handle — on
**every** connection, which under php-fpm means every request:

| Key | Default | `.env` override | PRAGMA sent on connect |
|---|---|---|---|
| `busy_timeout` | `5000` (ms) | `DB_BUSY_TIMEOUT` | `pragma busy_timeout = 5000` |
| `journal_mode` | `WAL` | `DB_JOURNAL_MODE` | `pragma journal_mode = WAL` |
| `synchronous` | `NORMAL` | `DB_SYNCHRONOUS` | `pragma synchronous = NORMAL` |

The connector sends a pragma only if its key **is set**. A key that is `null`
— which is what `DB_JOURNAL_MODE=null` in `.env` produces — means "send
nothing", not "use the default"; it is the state the config comment quoted
below says it replaced.

`tests/Feature/Core/SqlitePragmaTest.php` reads all three back through the
same connector on a file-backed database and fails when any is missing
(run on 4 Sep 2026 against a copy of the config without the keys: `60000`
instead of `5000`, and no `-wal` file). It has to open a file of its own
because the suite's connection is `:memory:`, which SQLite can never put into
WAL mode — `pragma journal_mode = WAL` answers `memory`, without an error —
so a `journal_mode` assertion on the test connection would pass vacuously or
fail for the wrong reason.

### 9.2 Why these three — the config comment, verbatim

> busy_timeout: without it, a connection that finds the database
> locked fails IMMEDIATELY with "database is locked" rather than
> waiting. Under the rollback journal a single long report blocks
> every write in the system, so that failure reaches users as a 500
> on save. Five seconds is far longer than any query here.
>
> journal_mode WAL: the default rollback journal makes readers block
> writers and writers block readers. WAL lets them run concurrently,
> which is the difference between the reports screen being slow and
> the reports screen taking the whole application down with it.
>
> synchronous NORMAL: under WAL this is the documented safe setting —
> durable against application crashes, and only at risk from an OS
> crash or power loss mid-write, which is what the daily backup is
> for. FULL costs an fsync per transaction for a guarantee this
> deployment does not need.
>
> None of this fixes lockForUpdate(), which SQLite compiles to an
> empty string — see docs/ASSESSMENT.md §3.2. That needs MySQL.

One measured refinement to the first paragraph (4 Sep 2026, PHP 8.3.6,
SQLite 3.45.1): a PHP handle that receives **no** pragma does not fail
immediately — `pdo_sqlite` applies its own `PDO::ATTR_TIMEOUT` default and
reports `busy_timeout = 60000`, with `synchronous = 2` (FULL) and, on a fresh
file, `journal_mode = delete`. On this stack `5000` is therefore a **ceiling
on how long a php-fpm worker sits waiting for the lock**, not the difference
between waiting and failing — and that is exactly the failure in
HASIL-UJI-UX-2026-09 §6.4: `/up` answered 503 twice in three days, the second
time about two minutes after ~40 requests (28 sequential + 12 parallel), the
requests hanging "pending" until nginx gave up. Workers that wait 60 s each
exhaust `pm.max_children` far sooner than workers that wait 5 s. The pragma
is still worth sending for the reason the comment gives — it bounds the
damage — and the sibling fix on the box is the php-fpm pool: if
`pm.max_children` ≤ 5, raise it to 10–16.

Two of the three are per connection and are re-sent on every request
(`busy_timeout`, `synchronous`). `journal_mode = WAL` is different: SQLite
writes it into the database file once, and from then on every handle —
including one that sends nothing, and the `sqlite3` CLI — reads `wal`.

### 9.3 Verify on the box

Run everything as `www-data`. Opening a WAL database creates its `-shm` side
file when absent; one created by root is unwritable for php-fpm, and SQLite
requires write access to `-shm` from every process that opens the database
(https://sqlite.org/wal.html).

```bash
cd /var/www/erp1.pi2.co.id

# 1. What the file itself says — journal_mode persists in the file; no app needed.
sudo -u www-data php -r '$p = new PDO("sqlite:database/database.sqlite"); echo $p->query("pragma journal_mode")->fetchColumn(), PHP_EOL;'
#    -> wal
sudo -u www-data sqlite3 database/database.sqlite 'pragma journal_mode;'   # same answer, if the CLI is installed

# 2. What the application's own connection receives — all three, through the connector
#    (HOME=/tmp because psysh cannot write to /var/www/.config as www-data).
sudo -u www-data env HOME=/tmp php artisan tinker --execute='echo DB::selectOne("pragma busy_timeout")->timeout, " ", DB::selectOne("pragma journal_mode")->journal_mode, " ", DB::selectOne("pragma synchronous")->synchronous, PHP_EOL;'
#    -> 5000 wal 1       (1 = NORMAL)
#    -> 60000 wal 2      means the pragmas are NOT reaching the app: check .env, then config:cache

# 3. The side files, present while a request holds the database open:
ls -l database/database.sqlite*
```

Locally the same three-number line comes from
`DB_DATABASE=<file> php artisan tinker --execute='…'` (4 Sep 2026, against a
scratch copy of the seed: `5000 wal 1`; a bare `new PDO` on the same file:
`60000 wal 2`).

**Changing them.** Set `DB_BUSY_TIMEOUT` / `DB_SYNCHRONOUS` in the site's
`.env`, then `sudo -u www-data php artisan config:cache`
(`deploy/sync-erp1.sh` does this on every deploy); the new values reach the
next request's connection. Leave `DB_JOURNAL_MODE` alone on erp1: taking a
live database out of WAL needs every other handle closed, and `null` silently
stops sending the pragma — the test guards the repository defaults, not the
box's `.env`.

### 9.4 Backups, restore and rsync: the `-wal` and `-shm` files

While the application holds the database open SQLite keeps two side files
next to it: `database.sqlite-wal` — committed transactions not yet written
into the main file — and `database.sqlite-shm`, its shared index. A
checkpoint folds the WAL into the main file automatically (every 1000 WAL
pages by default) and when the last connection closes; under php-fpm that is
the end of each request, so on a quiet site the two files come and go and on
a busy one they are always there. Three consequences:

1. **Copying `database.sqlite` alone is not a backup.** `SqlitePragmaTest`
   demonstrates it rather than asserting it: a row committed through the
   application is absent from a `cp` of the main file taken a moment later,
   and present in a `VACUUM INTO` snapshot taken through a live connection,
   because that reads through the WAL. `deploy/backup-erp1.sh` snapshots
   with `VACUUM INTO` and integrity-checks the result (its header explains
   why) — keep using it; never `cp`, `tar` or `rsync` the file directly, and
   let `--restore-drill` prove the artifact.

2. **Restore on erp1** (section 5.2 is the MySQL/Docker path). Stop
   everything that opens the file — `systemctl stop php8.3-fpm`, then wait
   until no artisan entry from `/etc/cron.d/erp1` is running and
   `fuser database/database.sqlite*` (or `lsof`) reports nothing. **Delete
   any leftover `database.sqlite-wal` and `-shm` before** putting the
   decompressed snapshot in place as `www-data`, then start php-fpm. SQLite
   replays a WAL it finds next to the file on the next open, whatever file
   that now is: a stale WAL from the old database applied to the restored
   one is corruption. The snapshot need not be in WAL mode itself; the first
   application connection sends `pragma journal_mode = WAL` and the file is
   back in WAL from then on.

3. **The code sync must exclude the side files, not just the database.**
   `deploy/sync-erp1.sh` runs `rsync -a --delete` with
   `--exclude='database/database.sqlite'`. Measured 4 Sep 2026 with a dry
   run over that exact exclude set: a live `database.sqlite-wal`/`-shm` on
   the site is **deleted** by the sync (a request in flight during the deploy
   loses its uncheckpointed commits, and the next connection starts a fresh
   WAL that disagrees with the handles still holding the old one), and a
   stray `database/database.sqlite-wal` in the source checkout — what a
   killed local `php -S` leaves behind — is **pushed onto production**.
   `--exclude='database/database.sqlite-*'` closes both, also measured.
   Check that the script's exclude list carries it before deploying onto a
   busy site; while it does not, deploy only when the site is idle
   (`ls /var/www/erp1.pi2.co.id/database/database.sqlite*` shows one file)
   and after confirming `ls /root/construction-erp/database/database.sqlite*`
   shows one file too.

## 10. MySQL on erp1 (Fase 0 — T0.0)

Roadmap HashMicro Fase 0 moves erp1 off the single SQLite file: measured 2–4
Sep 2026 (HASIL-UJI §6.4, section 9 above), a ~40-request harness burst left
php-fpm workers waiting on the SQLite write lock and nginx answered `503`.
MySQL 8 runs on the same host as a plain systemd service — no Docker (that path
changes too many variables at once), no Redis (queue stays `database`). This
section records what was installed, how it is configured, and where the
credential lives. **Until the T0.6 cut-over runbook has been executed,
production still reads `DB_CONNECTION=sqlite`** — installing the server changes
nothing the application sees. (Hanya catatan instalasi; produksi masih SQLite
sampai cut-over.)

### 10.1 What was installed (5 Sep 2026)

```
apt-get install -y mysql-server
```

| Package | Version |
|---|---|
| `mysql-server`, `mysql-server-8.0`, `mysql-server-core-8.0`, `mysql-client-8.0` | `8.0.46-0ubuntu0.24.04.4` |
| `mysql-common` | `5.8+1.1.0build1` |

Ubuntu's package enables and starts the unit on install; `systemctl is-active
mysql` → `active`, `systemctl is-enabled mysql` → `enabled`. The root account
uses `auth_socket` (only a root shell can open it, no password exists). PHP
8.3.6 on the box already ships `pdo_mysql` and `mysqli`.

### 10.2 Configuration: `deploy/mysql/erp1.cnf`

The tuning lives in the repository as `deploy/mysql/erp1.cnf` and is installed
verbatim:

```
cp deploy/mysql/erp1.cnf /etc/mysql/mysql.conf.d/erp1.cnf
chmod 644 /etc/mysql/mysql.conf.d/erp1.cnf
systemctl restart mysql
```

`erp1.cnf` sorts after the distribution's `mysqld.cnf`, so its keys win. Every
value carries its reason in the file itself; the short table:

| Key | Value | Why |
|---|---|---|
| `bind-address` | `127.0.0.1` | ERP and MySQL share the host; port 3306 is never reachable from outside |
| `mysqlx` | `0` | X-protocol listener (33060) unused |
| `character-set-server` / `collation-server` | `utf8mb4` / `utf8mb4_unicode_ci` | same pair as `config/database.php` (`DB_COLLATION` default) |
| `sql_mode` | `STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO` | server floor for non-Laravel clients (CLI, `mysqldump` restore); Laravel sets a stricter session mode itself |
| `max_connections` | `60` | php-fpm has few workers; 151 only lets a runaway burst hold more RAM |
| `innodb_buffer_pool_size` | `384M` | whole ERP is ~3 MB; leaves ~2 GB for php-fpm; no swap on this host |
| `innodb_lock_wait_timeout` | `10` | `lockForUpdate()` is real on MySQL (141 sites): fail a waiter in 10 s, not 50 |
| `disable_log_bin` | — | no replica, no PITR; backups are `mysqldump --single-transaction`; binlog would double every write |
| `slow_query_log` / `long_query_time` | `1` / `2` s → `/var/log/mysql/slow.log` | SQLite era had no per-query timing |

Read back on 5 Sep 2026 after the restart (`mysql -e "SELECT @@sql_mode,
@@max_connections, @@innodb_buffer_pool_size/1024/1024, @@collation_server,
@@log_bin, @@slow_query_log, @@innodb_lock_wait_timeout, @@bind_address\G"`):
every value above, `ss -ltnp` shows one `mysqld` listener, `127.0.0.1:3306`.
Memory measured with `free -m`: 1 379 MB used before the install, 1 707 MB
used with the server idle after it (`mysqld` RSS 408 MB), 2 207 MB still
available — inside the roadmap's "+≥1 GB" allowance.

### 10.3 Users, databases, and where the credential lives

One application account, `'erp'@'localhost'` (`caching_sha2_password`, the 8.0
default — `pdo_mysql` on PHP 8.3 speaks it). It holds `ALL PRIVILEGES` on
exactly five schemas and nothing else:

| Schema | Purpose |
|---|---|
| `erp` | **production** — created by the cut-over runbook (T0.6), not before |
| `erp_scratch` | seeded demo for the burst harness and smoke tests (T0.4, T0.7) |
| `erp_test` | phpunit on MySQL (`phpunit.mysql.xml`, T0.3) |
| `erp_dryrun` | SQLite → MySQL rehearsal target (`erp:sqlite-to-mysql`, T0.5) |
| `erp_restore_check` | restore drill target (T0.6) |

The four working schemas exist (created `CHARACTER SET utf8mb4 COLLATE
utf8mb4_unicode_ci`); `erp` does not yet. A `GRANT` on a schema that does not
exist is legal in MySQL, so the account is ready for the cut-over without a
second grant.

**The password is 32 random characters and is written in exactly one place:**
`mysql-erp.cred` (`chmod 600`, `DB_USERNAME=` / `DB_PASSWORD=` lines) in the
Fase 0 scratch directory of the session that created the account. It is not
in this repository, not in any commit message, not in a log, and this document
does not contain it. At cut-over the operator copies the two lines into
`/var/www/erp1.pi2.co.id/.env` and deletes the scratch file. Lost it? Do not
hunt for it — set a new one from a root shell (`ALTER USER 'erp'@'localhost'
IDENTIFIED BY '…'`) and update `.env`; nothing else holds it.

To confirm the account without printing the secret:

```
set -a; . /path/to/mysql-erp.cred; set +a
MYSQL_PWD="$DB_PASSWORD" mysql -u erp -h 127.0.0.1 -e "SELECT CURRENT_USER(); SHOW DATABASES;"
```

### 10.4 What this does NOT change (yet)

- `/var/www/erp1.pi2.co.id/.env` — still `DB_CONNECTION=sqlite`.
- `/etc/cron.d/erp1`, nginx, php-fpm — untouched.
- `deploy/backup-erp1.sh` — still snapshots the SQLite file; the MySQL branch
  (`mysqldump --single-transaction` + restore drill) is T0.6.
- The application code — the two SQLite-only partial unique indexes and the
  preflight audit are T0.1/T0.2, in this same phase, and the cut-over itself
  is a separate, ordered runbook.

### 10.5 Running the test suite on MySQL (T0.3)

`phpunit.mysql.xml` is `phpunit.xml` with `DB_CONNECTION=mysql`,
`DB_HOST=127.0.0.1`, `DB_DATABASE=erp_test`. It carries **no credential**:
PHPUnit's `<env>` never overrides a variable that is already set, so the
account comes from the shell —

```
set -a; . /path/to/mysql-erp.cred; set +a     # DB_USERNAME, DB_PASSWORD
vendor/bin/phpunit -c phpunit.mysql.xml
```

`RefreshDatabase` runs `migrate:fresh` on `erp_test` **once per PHP process**
(≈27 s) and wraps every test in a real transaction. Two consequences the
SQLite suite never had:

- **One process per database.** Two phpunit processes on `erp_test` at the
  same time drop each other's tables (each starts with `migrate:fresh`) and
  deadlock on the seeders — measured 5 Sep 2026 as a spurious `1213` in
  `PermissionSeeder`. Run one at a time, or point the second at another
  schema with `DB_DATABASE=…`.
- **DDL inside a test commits the transaction.** SQLite rolls `CREATE TABLE`
  back with the test; MySQL commits it implicitly. Laravel notices (the PDO is
  no longer in a transaction) and re-runs `migrate:fresh` before the next test
  — the four schema-degradation tests in `CalendarApiTest` / `DeadlineWatchTest`
  each cost that. Fixture tables tests create for themselves go through
  `tests/Support/FixtureSchema.php` (a second connection, table left in place)
  so they do not.

`SqlitePragmaTest` skips itself on MySQL; `tests/Feature/Core/MysqlModeTest`
skips itself on SQLite and reads back, on the application connection: the
session `sql_mode` (`STRICT_TRANS_TABLES`, `ONLY_FULL_GROUP_BY`, `NO_ZERO_DATE`
— Laravel's `'strict' => true`, stricter than the server floor in `erp1.cnf`),
`innodb_lock_wait_timeout ≤ 10`, the generated `live_key` columns and their
unique indexes (T0.2), and that a second connection really waits on
`lockForUpdate()` (fails with `1205` after its 1-second timeout — on SQLite the
same call is a no-op).

CI runs this suite nightly at 02:00 WIB and on `v*` tags (job
`phpunit-mysql`, service container `mysql:8.0` configured like `erp1.cnf` in
its first step); the SQLite job is unchanged and still gates every push.

