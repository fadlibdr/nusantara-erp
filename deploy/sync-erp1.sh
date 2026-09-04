#!/usr/bin/env bash
#
# Push the working tree at /root/construction-erp to the live site at
# https://erp1.pi2.co.id.
#
# The live site is a COPY, not a symlink, so that a half-finished edit in the
# source tree is never served. This script is the only thing that moves code
# between them.
#
# Never copied, because the live instance owns them:
#   .env                       production config, its own APP_KEY
#   database/database.sqlite   live data
#   storage/, bootstrap/cache/ runtime state
#
# Usage:  sudo bash deploy/sync-erp1.sh          deploy
#         bash deploy/sync-erp1.sh --check        only the permission-drift check,
#                                                 against THIS checkout's database;
#                                                 no rsync, no migrate, no prod
set -euo pipefail

SRC=/root/construction-erp
SITE=/var/www/erp1.pi2.co.id

# --check: the drift check on its own, exit code passed through. Measured on
# erp1 on 4 Sep 2026: admin held 74 of 86 permissions because eng.*/qc.* were
# added to PermissionSeeder::PREFIXES and nothing re-ran the seeders on the
# live database (HASIL-UJI §6.2 P-1). Runs before the site-directory guard on
# purpose: a developer box has no /var/www/erp1.pi2.co.id and must still be
# able to ask the question.
if [[ "${1:-}" == "--check" ]]; then
  cd "$SRC"
  exec php artisan erp:permission-check
fi
[[ $# -eq 0 ]] || { echo "usage: $0 [--check]" >&2; exit 2; }

[[ -d "$SRC" && -d "$SITE" ]] || { echo "source or site directory missing" >&2; exit 1; }

echo "==> Running the test suite before touching the live site"
( cd "$SRC" && ./vendor/bin/phpunit --no-output ) || {
  echo "TESTS FAILED — refusing to deploy." >&2
  exit 1
}

echo "==> Syncing code"
rsync -a --delete \
  --exclude='.env' \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='database/database.sqlite' \
  --exclude='storage/logs/*' \
  `# Uploaded attachments are LIVE DATA, like the database. Without these two` \
  `# the --delete above wipes every file anyone has ever attached, on every` \
  `# deploy, leaving rows in core_attachments that point at nothing.` \
  --exclude='storage/app/private/**' \
  --exclude='storage/app/public/**' \
  --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='bootstrap/cache/*' \
  --exclude='.phpunit.result.cache' \
  "$SRC/" "$SITE/"

echo "==> Restoring ownership and permissions"

# The runtime trees are PRUNED from the site-wide pass, not fixed up after it.
#
# The old order chmod'ed every one of ~11 000 paths to 750/640 — including
# storage/, database/ and bootstrap/cache/ — and only restored 770 once the walk
# finished. For the seconds in between, php-fpm could not write its session
# files, its cache, the SQLite database or an uploaded attachment, and any
# request served during the window failed. The site is live throughout a deploy;
# there is no maintenance mode here.
RUNTIME_PRUNE=( -path "$SITE/storage" -o -path "$SITE/database" -o -path "$SITE/bootstrap/cache" )

chown -R root:www-data "$SITE"
find "$SITE" \( "${RUNTIME_PRUNE[@]}" \) -prune -o -type d -exec chmod 750 {} +
find "$SITE" \( "${RUNTIME_PRUNE[@]}" \) -prune -o -type f -exec chmod 640 {} +

chown -R www-data:www-data "$SITE/storage" "$SITE/bootstrap/cache" "$SITE/database"
chmod -R 770 "$SITE/storage" "$SITE/bootstrap/cache" "$SITE/database"
chown root:www-data "$SITE/.env"
chmod 640 "$SITE/.env"

# A migration is the one step in this script that can destroy data the excludes
# above protect, and it has no undo. Three of the shipped migrations have a
# deliberately empty down() because they post real journals — rolling those back
# would be an unbooked accounting change — so the snapshot IS the way back.
echo "==> Snapshotting the database before migrating"
# --local-only: the deploy needs a rollback snapshot on this disk, now. The
# offsite half has its own crons and its own alarm; an unreachable or not-yet-
# configured destination must not be able to block a code deploy.
if ! "$SRC/deploy/backup-erp1.sh" --local-only >/dev/null; then
    echo "    backup failed — refusing to migrate" >&2
    exit 1
fi
echo "    ok"

echo "==> Migrating and rebuilding caches"
cd "$SITE"
# clear the PREVIOUS deploy's cached config BEFORE migrating: a stale
# config:cache made `migrate` read an old state and report "Nothing to
# migrate" while migrate:status showed pending rows — Engineering (P1-ENG)
# and Quality (P1-QC) both landed on prod with their whole migration block
# skipped until re-run by hand. Clear first, migrate against live config.
sudo -u www-data php artisan config:clear >/dev/null
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear >/dev/null
sudo -u www-data php artisan config:cache >/dev/null
sudo -u www-data php artisan route:cache  >/dev/null
sudo -u www-data php artisan event:cache  >/dev/null

echo "==> Reloading php-fpm (clears the opcode cache)"
systemctl reload php8.3-fpm

echo "==> Smoke test"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://erp1.pi2.co.id/up || true)
if [[ "$code" == "401" ]]; then
  echo "    /up -> 401 (the access gate is up, as expected)"
elif [[ "$code" == "200" ]]; then
  echo "    /up -> 200 — NOTE: the access gate is NOT in front of the site."
else
  echo "    /up -> $code — check https://erp1.pi2.co.id and /var/log/nginx/erp1.error.log" >&2
  exit 1
fi

# Last, not right after migrate: a check that fails there would leave the box
# with new code, a cleared config cache and an un-reloaded php-fpm — a worse
# state than a fully deployed site whose roles need a re-seed. By here the
# deploy is complete and consistent; drift is reported on top of it, loudly,
# and the exit code says the deploy is NOT done until the roles match.
echo "==> Permission drift check (seeders vs live roles)"
if ! sudo -u www-data php artisan erp:permission-check; then
  # Not "--check": that mode reads the CHECKOUT's database, not the live one.
  echo "PERMISSION DRIFT — code and migrations are live, but the live roles do not match the seeders. Run the re-seed printed above in $SITE, then: cd $SITE && sudo -u www-data php artisan erp:permission-check" >&2
  exit 1
fi

echo "Done."
