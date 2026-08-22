#!/bin/sh
#
# Nightly backup for the Nusantara ERP production stack.
#
# What it does:
#   1. mysqldump --single-transaction --routines, gzip'd to
#        $BACKUP_DIR/erp-db-YYYYmmdd-HHMMSS.sql.gz
#   2. tar of storage/app (uploads, generated PDFs/Excel) to
#        $BACKUP_DIR/erp-storage-YYYYmmdd-HHMMSS.tar.gz
#   3. deletes local backups older than $BACKUP_KEEP_DAYS days
#
# By default everything goes THROUGH the running containers, so the Docker
# host needs no mysql client:
#   docker compose -f docker-compose.prod.yml exec -T mysql mysqldump ...
# (-T is required: cron has no TTY). Set DB_HOST in the environment to switch
# to a direct dump with a host-installed mysqldump instead.
#
# Usage — run on the Docker host from the repository root:
#   ./deploy/backup/backup.sh
#
# Configuration (environment variables, all optional unless noted):
#   BACKUP_DIR        target directory                 (default: /backups)
#   BACKUP_KEEP_DAYS  local retention in days          (default: 14)
#   COMPOSE_FILE      compose file to exec through     (default: docker-compose.prod.yml)
#   DB_HOST           if set: dump directly using host mysqldump with
#                     DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME /
#                     DB_PASSWORD, and tar STORAGE_PATH (default ./storage/app)
#                     instead of going through the containers.
#
# Scheduling — host cron (crontab -e on the VM):
#   # daily at 01:30 Asia/Jakarta, log to /var/log/erp-backup.log
#   30 1 * * * cd /opt/erp/construction-erp && ./deploy/backup/backup.sh >> /var/log/erp-backup.log 2>&1
#
# Offsite copy — STRONGLY recommended; a backup on the same VM is not a
# backup. Sync $BACKUP_DIR to remote storage right after this script, e.g.:
#   rclone copy /backups remote:erp-backups          # any S3/Drive/SFTP remote
#   aws s3 sync /backups s3://my-bucket/erp-backups/
# Either chain it in the same cron line (./deploy/backup/backup.sh && rclone
# copy /backups remote:erp-backups) or add it as a separate cron entry.

set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"

STAMP="$(date +%Y%m%d-%H%M%S)"
DB_FILE="$BACKUP_DIR/erp-db-$STAMP.sql.gz"
STORAGE_FILE="$BACKUP_DIR/erp-storage-$STAMP.tar.gz"

mkdir -p "$BACKUP_DIR"

# --- 1. Database dump --------------------------------------------------------
if [ -n "${DB_HOST:-}" ]; then
    # Direct mode: mysqldump installed on this machine, credentials from env.
    echo "backup: dumping ${DB_DATABASE:?DB_DATABASE required} directly from $DB_HOST"
    MYSQL_PWD="${DB_PASSWORD:?DB_PASSWORD required}" mysqldump \
        --single-transaction --routines --triggers \
        -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:?DB_USERNAME required}" \
        "$DB_DATABASE" | gzip > "$DB_FILE"
else
    # Container mode (default): MYSQL_ROOT_PASSWORD and MYSQL_DATABASE are
    # already present in the mysql container's environment (set by
    # docker-compose.prod.yml), so no credentials are needed on the host.
    echo "backup: dumping database via the mysql container"
    docker compose -f "$COMPOSE_FILE" exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --routines --triggers -uroot "$MYSQL_DATABASE"' \
        | gzip > "$DB_FILE"
fi

# POSIX sh has no pipefail, so a failed mysqldump could otherwise leave a
# tiny, silently-broken .sql.gz behind. mysqldump appends a "-- Dump
# completed" footer only on success — verify it before trusting the file.
if ! gzip -dc "$DB_FILE" | tail -n 1 | grep -q 'Dump completed'; then
    echo "backup: ERROR — $DB_FILE is incomplete (mysqldump failed?); removing it" >&2
    rm -f "$DB_FILE"
    exit 1
fi
echo "backup: database -> $DB_FILE"

# --- 2. storage/app (uploads, generated documents) ---------------------------
if [ -n "${DB_HOST:-}" ]; then
    # Direct mode: tar a local path.
    STORAGE_PATH="${STORAGE_PATH:-./storage/app}"
    tar -czf "$STORAGE_FILE" -C "$(dirname "$STORAGE_PATH")" "$(basename "$STORAGE_PATH")"
else
    # In the compose stack storage/app lives in the erp-storage NAMED VOLUME
    # (not in the git checkout), mounted in the app container — so tar it
    # from inside the container to back up the real data.
    docker compose -f "$COMPOSE_FILE" exec -T app \
        tar -czf - -C /var/www/html/storage app > "$STORAGE_FILE"
fi
echo "backup: storage  -> $STORAGE_FILE"

# --- 3. Retention ------------------------------------------------------------
find "$BACKUP_DIR" -maxdepth 1 -type f \
    \( -name 'erp-db-*.sql.gz' -o -name 'erp-storage-*.tar.gz' \) \
    -mtime "+$BACKUP_KEEP_DAYS" -exec rm -f {} +
echo "backup: pruned backups older than $BACKUP_KEEP_DAYS days in $BACKUP_DIR"

echo "backup: done ($STAMP)"
