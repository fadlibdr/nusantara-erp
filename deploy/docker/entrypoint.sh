#!/bin/sh
#
# Container entrypoint for the ERP image. Runs for the app (php-fpm), queue
# worker and scheduler services alike — only "$@" (the CMD) differs.
#
# Behavior:
#   1. ensure writable runtime directories exist
#   2. wait for the database (PDO connect loop, ~60s timeout)
#   3. rebuild the package manifest (composer ran with --no-scripts)
#   4. config:cache / route:cache / event:cache
#   5. migrate --force, ONLY when RUN_MIGRATIONS=1
#   6. exec "$@"
#
# It NEVER runs db:seed. Production seeding is an explicit, manual step:
#   docker compose -f docker-compose.prod.yml exec app \
#       php artisan db:seed --class=ProductionSeeder --force

set -e

cd /var/www/html

# --- 1. Writable runtime directories (defensive; cheap if they exist) -------
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# --- 2. Wait for the database ------------------------------------------------
DB_WAIT_TIMEOUT="${DB_WAIT_TIMEOUT:-60}"
echo "entrypoint: waiting for database at ${DB_HOST:-mysql}:${DB_PORT:-3306} (timeout: ${DB_WAIT_TIMEOUT}s)"

elapsed=0
until php -d display_errors=0 -r '
    $host = getenv("DB_HOST") ?: "mysql";
    $port = getenv("DB_PORT") ?: "3306";
    $name = getenv("DB_DATABASE") ?: "";
    $user = getenv("DB_USERNAME") ?: "root";
    $pass = getenv("DB_PASSWORD") ?: "";
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
    } catch (Throwable $e) {
        exit(1);
    }
'; do
    elapsed=$((elapsed + 2))
    if [ "$elapsed" -ge "$DB_WAIT_TIMEOUT" ]; then
        echo "entrypoint: database not reachable after ${DB_WAIT_TIMEOUT}s — giving up" >&2
        exit 1
    fi
    sleep 2
done
echo "entrypoint: database is up"

# --- 3. Package manifest -----------------------------------------------------
# The vendor stage installs with --no-scripts, so post-autoload-dump never ran.
php artisan package:discover --ansi

# NOTE: no storage:link. nginx serves the host checkout's ./public (read-only)
# and cannot resolve an in-container symlink, so the "public" disk is never
# web-served. All file downloads must stream through authenticated PHP routes
# (Storage::download / response()->file) — the right posture for an ERP anyway.

# --- 4. Bootstrap caches -----------------------------------------------------
php artisan config:cache
if ! php artisan route:cache; then
    # route:cache refuses closure-based route actions; run uncached rather
    # than refusing to boot. Convert closures to controllers to re-enable.
    echo "entrypoint: WARNING — route:cache failed (closure routes?); continuing with uncached routes" >&2
    php artisan route:clear
fi
php artisan event:cache

# --- 5. Optional migrations (never seeds) -------------------------------------
# Enable on ONE service only (docker-compose.prod.yml forces it off for the
# queue and scheduler services so concurrent starts never race migrations).
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "entrypoint: RUN_MIGRATIONS=1 — running php artisan migrate --force"
    php artisan migrate --force
fi

# --- 6. Hand off to the service command ---------------------------------------
exec "$@"
