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
