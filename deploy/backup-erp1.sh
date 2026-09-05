#!/usr/bin/env bash
#
# Daily backup for the bare-metal deployment at erp1.pi2.co.id — SQLite until
# the Fase 0 cut-over, MySQL 8 after it (ENGINE below; docs/DEPLOYMENT.md §10).
#
# deploy/backup/backup.sh is the Docker script and CANNOT run here: it dumps
# through containers, and this host runs php-fpm and MySQL as plain services.
#
# Two things are backed up, and both are irreplaceable:
#
#   the database              every document, journal and user —
#                             database/database.sqlite (engine sqlite) or the
#                             MySQL schema named in the conf (engine mysql)
#   storage/app/private       uploaded attachments — scans of faktur pajak,
#                             site photographs, vendor invoices
#
# SQLite is copied with VACUUM INTO, not cp. Copying a SQLite file while
# anything is writing to it yields a torn file that looks fine until you need it:
# VACUUM INTO takes a read lock and writes a consistent, compacted snapshot. The
# result is then opened and integrity-checked before it is allowed to count as a
# backup, because an unverified backup is a guess.
#
# MySQL is dumped with `mysqldump --single-transaction`: one consistent InnoDB
# snapshot without locking writers (the ERP keeps running through the dump),
# routines and triggers included, --set-gtid-purged=OFF because erp1.cnf keeps
# the binary log off (no replica, no PITR — an owner decision, §10.2), and
# --no-tablespaces so the account needs no global PROCESS privilege. The dump
# counts as a backup only when its `-- Dump completed` trailer is present (a
# mysqldump killed mid-way leaves no trailer) and it carries users rows. The
# credential comes from a my.cnf-style defaults file named in the conf
# (--defaults-extra-file), never from argv or the environment, where `ps` and
# /proc would show it.
#
# Every artifact is built under a .part name and renamed only after its
# container verifies (gzip -t, tar -t): a crash mid-write must never leave a
# truncated file wearing a finished name.
#
# THE OFFSITE HALF. Everything local sits on the same disk as the thing it
# protects, so it survives a bad migration and a mistaken DELETE — and not the
# disk failing, which is the case that ends the company. After the local backup,
# every artifact is encrypted (GPG AES-256, key at /etc/erp1/backup.key) and
# pushed to the destination named in /etc/erp1/backup.conf, then read back and
# checksummed, because an upload nobody verified is a wish. Push is by sync:
# whatever the remote is missing goes up, so a failed night heals itself the
# next run. Until a destination is configured this script says so loudly and
# exits non-zero — a silent local-only backup is exactly the failure mode the
# offsite copy exists to end.
#
# THE STATUS CONTRACT. /var/lib/erp1/offsite-status.json is read by the ERP's
# erp:backup-watch, which is the only alarm channel a human actually sees. The
# rule for every field: it must be impossible for this file to look healthy
# while the offsite copy is unrestorable. That is why "ok" additionally requires
# a non-empty remote AND records the newest artifact's own timestamp — a sync
# that pushes nothing because the local side stopped producing backups keeps
# "succeeding" forever, and only the artifact date betrays it.
#
# A hostile or corrupted DESTINATION can still lie in its listings; nothing in a
# push-side script can prove remote bytes except by fetching them. That is what
# --restore-drill is for, and why cron runs it monthly.
#
# Usage:
#   backup-erp1.sh                  nightly: local backup, then offsite sync
#   backup-erp1.sh --local-only     just the verified local snapshot (used by
#                                   sync-erp1.sh for its pre-migration copy —
#                                   a rollback snapshot must not be hostage to
#                                   the offsite destination being reachable)
#   backup-erp1.sh --offsite-only   retry the offsite sync (afternoon cron)
#   backup-erp1.sh --restore-drill  fetch the newest offsite copy, decrypt it,
#                                   and prove it restores. A backup you have
#                                   never restored is a hypothesis. Engine
#                                   sqlite: open + integrity_check + count
#                                   users. Engine mysql: load into the
#                                   erp_restore_check schema, count users and
#                                   journals, then `erp:migration-verify`
#                                   against the LIVE database — 0 differing
#                                   tables = passed, N = "drift" (work done
#                                   since the dump; expected by day, not at
#                                   03:30), restore or verify error = failed.
#     --source=local                drill from the newest LOCAL artifact
#                                   instead of offsite (no GPG; proves the
#                                   restore path before an offsite destination
#                                   exists; does not touch the status file,
#                                   which is about the OFFSITE copy)
#   --engine sqlite|mysql           which database this host runs; default
#                                   from ERP_BACKUP_ENGINE, then BACKUP_ENGINE
#                                   in the conf, then sqlite. The cut-over
#                                   runbook sets BACKUP_ENGINE=mysql in the
#                                   conf so every cron line and sync-erp1.sh's
#                                   --local-only switch at once.
#
# Install: see /etc/cron.d/erp1 and docs/DEPLOYMENT.md.

set -euo pipefail

SITE="${ERP_SITE:-/var/www/erp1.pi2.co.id}"
DEST="${ERP_BACKUP_DIR:-/var/backups/erp1}"
KEEP_DAYS="${ERP_BACKUP_KEEP_DAYS:-14}"
CONF="${ERP_BACKUP_CONF:-/etc/erp1/backup.conf}"
STATUS_DIR="${ERP_BACKUP_STATUS_DIR:-/var/lib/erp1}"
STATUS="$STATUS_DIR/offsite-status.json"
# /run, not /var/lock: /var/lock is a world-writable sticky tmpfs where any
# local user (www-data included) could pre-create the lock file and, under
# fs.protected_regular, make root's open fail — a one-line denial of backups.
LOCK="${ERP_BACKUP_LOCK:-/run/erp1-backup.lock}"
STAMP="$(date +%Y%m%d-%H%M%S)"

DB="$SITE/database/database.sqlite"
FILES="$SITE/storage/app/private"
SPOOL="$DEST/.spool"

# Never rotate or prune below this many artifacts of each type, no matter what
# the dates say. Dates come from clocks and clocks go wrong; a forward-skewed
# clock must never be able to age every copy in existence past the cutoff.
MIN_KEEP=3

# Set by /etc/erp1/backup.conf. OFFSITE_MODE empty means "not configured", and
# that state is reported as a failure, never as success.
OFFSITE_MODE=""
OFFSITE_RSYNC_DEST=""          # user@host:/absolute/path
OFFSITE_SSH_PORT="22"
OFFSITE_RCLONE_REMOTE=""       # remote:bucket/prefix
OFFSITE_KEEP_DAYS="30"
OFFSITE_KEY="/etc/erp1/backup.key"
CONF_ERROR=""

# Engine (Fase 0 T0.6). Resolved after the conf is read:
# --engine flag > ERP_BACKUP_ENGINE > BACKUP_ENGINE (conf) > sqlite.
BACKUP_ENGINE=""
ENGINE_FLAG=""
ENGINE=""
DB_EXT=""                      # sqlite | sql — the database artifact's extension
# mysql engine only. The defaults file is my.cnf syntax with a [client]
# section (user, password, host) — see deploy/erp1-mysql-backup.cnf.example —
# mode 600, read by mysqldump and mysql through --defaults-extra-file.
MYSQL_DEFAULTS_FILE="/etc/erp1/mysql-backup.cnf"
MYSQL_DATABASE="erp"           # the production schema
# The ONLY schema the drill ever writes. Not configurable on purpose: a typo
# in a conf key must not be able to point a restore at production.
MYSQL_RESTORE_DATABASE="erp_restore_check"
# Tables erp:migration-verify skips in the drill: queue and session state,
# which a restore is not expected to reproduce byte for byte.
VOLATILE_TABLES="sessions,cache,cache_locks,jobs,job_batches,failed_jobs,password_reset_tokens"
# The app connection the drill verifies AGAINST (the live database) and the
# user artisan runs as (www-data owns storage/; root would leave root-owned
# logs behind). ERP_ARTISAN_USER=root runs artisan directly — for rehearsals.
VERIFY_FROM="${ERP_VERIFY_FROM:-mysql}"
ARTISAN_USER="${ERP_ARTISAN_USER:-www-data}"
DRILL_SOURCE="offsite"
DRILL_DIFF=""

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fail() { log "FAILED: $*" >&2; exit 1; }

MODE="full"
while [ $# -gt 0 ]; do
    case "$1" in
        --local-only) MODE="local" ;;
        --offsite-only) MODE="offsite" ;;
        --restore-drill) MODE="drill" ;;
        --source=local) DRILL_SOURCE="local" ;;
        --source=offsite) DRILL_SOURCE="offsite" ;;
        --engine) shift; ENGINE_FLAG="${1:-}" ;;
        --engine=*) ENGINE_FLAG="${1#*=}" ;;
        *) fail "unknown option '$1' (use --local-only, --offsite-only, --restore-drill [--source=local], --engine sqlite|mysql)" ;;
    esac
    shift
done

# One run at a time. The nightly and the afternoon retry share the sync state,
# and two concurrent pushes of the same spool would double-write the remote.
exec 200>"$LOCK"
flock -n 200 || fail "another backup run holds $LOCK"

# The conf is validated BEFORE it is sourced, and a bad conf is recorded and
# survived rather than obeyed. Sourcing sits under set -e: one typo like
# 'OFFSITE_MODE = rsync' would otherwise abort the script right here — before
# the local backup — turning a config mistake in the OFFSITE half into zero
# database snapshots, with nothing but 'command not found' in a log nobody
# reads. Only plain KEY=value assignments of the known keys are accepted.
load_conf() {
    [ -r "$CONF" ] || return 0

    if LC_ALL=C grep -qvE '^[[:space:]]*(#|$)|^(OFFSITE_MODE|OFFSITE_RSYNC_DEST|OFFSITE_SSH_PORT|OFFSITE_RCLONE_REMOTE|OFFSITE_KEEP_DAYS|OFFSITE_KEY|BACKUP_ENGINE|MYSQL_DEFAULTS_FILE|MYSQL_DATABASE)=[A-Za-z0-9@:./_-]*$' "$CONF"; then
        CONF_ERROR="$CONF contains something other than plain KEY=value lines (check for spaces around '=', quotes, or CRLF line endings)"
        return 0
    fi

    . "$CONF"
}
load_conf

ENGINE="${ENGINE_FLAG:-${ERP_BACKUP_ENGINE:-${BACKUP_ENGINE:-sqlite}}}"
case "$ENGINE" in
    sqlite) DB_EXT="sqlite" ;;
    mysql) DB_EXT="sql" ;;
    *) fail "engine must be sqlite or mysql (got '$ENGINE')" ;;
esac

# ======================================================================= mysql

check_mysql_config() {
    [ -r "$MYSQL_DEFAULTS_FILE" ] || fail "engine mysql: no credential file at $MYSQL_DEFAULTS_FILE (see deploy/erp1-mysql-backup.cnf.example; set MYSQL_DEFAULTS_FILE in $CONF)"

    case "$(stat -c %a "$MYSQL_DEFAULTS_FILE")" in
        600|400) ;;
        *) fail "$MYSQL_DEFAULTS_FILE must be mode 600 or 400 — it holds the database password" ;;
    esac

    grep -q '^\[client\]' "$MYSQL_DEFAULTS_FILE" || fail "$MYSQL_DEFAULTS_FILE must be my.cnf syntax with a [client] section"
    [[ "$MYSQL_DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || fail "MYSQL_DATABASE must be a plain schema name (got '$MYSQL_DATABASE')"
    [ "$MYSQL_DATABASE" != "$MYSQL_RESTORE_DATABASE" ] || fail "MYSQL_DATABASE must not be the drill schema $MYSQL_RESTORE_DATABASE"
    command -v mysqldump >/dev/null || fail "mysqldump is not installed"
}

# The credential travels ONLY through --defaults-extra-file: never on argv,
# never in MYSQL_PWD (both readable in /proc by every local user).
mysql_cli() {
    mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --batch --skip-column-names "$@"
}

# What makes a dump file a backup: the trailer mysqldump writes LAST (a run
# killed by OOM, disk-full or a timeout leaves none), the users table, and at
# least one users row — the same "not empty" rule the SQLite path applies.
verify_mysql_dump() {
    local file="$1" tables

    tail -c 400 "$file" | grep -q -- '^-- Dump completed' \
        || { log "dump has no '-- Dump completed' trailer — truncated or interrupted" >&2; return 1; }
    grep -q '^CREATE TABLE `users`' "$file" || { log "dump has no users table" >&2; return 1; }
    grep -q '^INSERT INTO `users`' "$file" || { log "dump contains no users rows — refusing to keep it" >&2; return 1; }

    tables="$(grep -c '^CREATE TABLE ' "$file" || true)"
    log "verified: $tables tables, users rows present"
}

dump_mysql() {
    local out="$1"

    mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
        --single-transaction --routines --triggers --set-gtid-purged=OFF \
        --no-tablespaces --hex-blob --default-character-set=utf8mb4 --quick \
        "$MYSQL_DATABASE" > "$out" \
        || { rm -f "$out"; fail "mysqldump of $MYSQL_DATABASE failed"; }

    verify_mysql_dump "$out" || { rm -f "$out"; fail "database dump did not verify"; }
}

run_artisan() {
    if [ "$(id -un)" = "$ARTISAN_USER" ] || [ "$ARTISAN_USER" = root ]; then
        php artisan "$@"
    else
        sudo -u "$ARTISAN_USER" php artisan "$@"
    fi
}

# ======================================================================= local

# Container-level verification for a finished artifact. The DB content was
# already proven by PRAGMA integrity_check; this proves the .gz/.tar.gz wrapper
# survived being written, so a crash mid-write can never leave a truncated file
# under a finished name for the offsite half to faithfully encrypt and ship.
finish_artifact() {
    local part="$1" final="$2"

    gzip -t "$part" || { rm -f "$part"; return 1; }

    if [[ "$final" == *.tar.gz ]]; then
        tar -tzf "$part" > /dev/null || { rm -f "$part"; return 1; }
    fi

    mv "$part" "$final"
    chmod 600 "$final"
}

run_local_backup() {
    if [ "$ENGINE" = mysql ]; then
        check_mysql_config
    else
        [ -f "$DB" ] || fail "database not found at $DB"
    fi

    mkdir -p "$DEST"
    chmod 700 "$DEST"

    # Leftovers from a run that died mid-write. Never eligible for shipping
    # (the offsite glob wants *.gz), but no reason to hoard corpses either.
    rm -f "$DEST"/erp1-*.part

    # ------------------------------------------------------------ database
    local db_out="$DEST/erp1-db-$STAMP.$DB_EXT"

    if [ "$ENGINE" = mysql ]; then
        log "dumping MySQL schema $MYSQL_DATABASE"
        dump_mysql "$db_out"
    else
        log "snapshotting database"
        snapshot_sqlite "$db_out"
    fi

    gzip -9 -c "$db_out" > "$db_out.gz.part" || { rm -f "$db_out" "$db_out.gz.part"; fail "gzip failed"; }
    rm -f "$db_out"
    finish_artifact "$db_out.gz.part" "$db_out.gz" || fail "database artifact failed container verification"
    log "database -> $(basename "$db_out").gz ($(du -h "$db_out.gz" | cut -f1))"

    # --------------------------------------------------------- attachments
    backup_attachments

    # -------------------------------------------------------------- rotate
    rotate_local

    log "local done — $(find "$DEST" -maxdepth 1 -name 'erp1-*' -type f | wc -l) files, $(du -sh "$DEST" | cut -f1) total"
}

snapshot_sqlite() {
    local db_out="$1"

    [ -f "$DB" ] || fail "database not found at $DB"

    php -r '
$source = $argv[1];
$target = $argv[2];

$pdo = new PDO("sqlite:{$source}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("VACUUM INTO ".$pdo->quote($target));

// Prove it before calling it a backup.
$check = new PDO("sqlite:{$target}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = $check->query("PRAGMA integrity_check")->fetchColumn();

if ($result !== "ok") {
    fwrite(STDERR, "integrity_check said: {$result}\n");
    exit(1);
}

// A structurally valid but empty file is not a backup either.
$users = (int) $check->query("select count(*) from users")->fetchColumn();

if ($users < 1) {
    fwrite(STDERR, "snapshot contains no users — refusing to keep it\n");
    exit(1);
}

fwrite(STDOUT, "verified: {$users} users\n");
' "$DB" "$db_out" || { rm -f "$db_out"; fail "database snapshot did not verify"; }
}

backup_attachments() {
    if [ -d "$FILES" ]; then
        local files_out="$DEST/erp1-files-$STAMP.tar.gz"
        tar -czf "$files_out.part" -C "$SITE/storage/app" private \
            || { rm -f "$files_out.part"; fail "attachment tar failed"; }
        finish_artifact "$files_out.part" "$files_out" || fail "attachment artifact failed container verification"
        log "attachments -> $(basename "$files_out") ($(du -h "$files_out" | cut -f1), $(find "$FILES" -type f | wc -l) files)"
    else
        log "no attachments directory at $FILES — skipping"
    fi
}

# Age-based rotation with a floor: the newest MIN_KEEP of each type survive no
# matter what their mtime claims. Otherwise a clock jumped forward — or a long
# outage — deletes every local copy in one silent pass.
rotate_local() {
    local keep old
    keep="$(
        { ls -1 "$DEST"/erp1-db-*.gz 2>/dev/null | sort | tail -"$MIN_KEEP"
          ls -1 "$DEST"/erp1-files-*.tar.gz 2>/dev/null | sort | tail -"$MIN_KEEP"; } || true
    )"

    while IFS= read -r old; do
        [ -n "$old" ] || continue

        if grep -qxF "$old" <<<"$keep"; then
            continue
        fi

        rm -f -- "$old"
        log "rotated out $(basename "$old")"
    done < <(find "$DEST" -maxdepth 1 -name 'erp1-*' -type f -mtime "+$KEEP_DAYS")
}

# ===================================================================== offsite

# The two transport backends behind one contract: list remote basenames, push
# the spool, checksum one pushed file, delete named remote files, fetch one
# file. Everything above the contract — what to encrypt, what to prune, what
# counts as verified — is backend-independent on purpose, so a move from a
# borrowed VPS to object storage is a config edit and not a rewrite.

ssh_target() { printf '%s' "${OFFSITE_RSYNC_DEST%%:*}"; }
ssh_path() { printf '%s' "${OFFSITE_RSYNC_DEST#*:}"; }

remote_ls() {
    case "$OFFSITE_MODE" in
        rsync)
            ssh -p "$OFFSITE_SSH_PORT" -o BatchMode=yes -o ConnectTimeout=20 "$(ssh_target)" \
                "mkdir -p '$(ssh_path)' && ls -1 '$(ssh_path)'"
            ;;
        rclone)
            rclone mkdir "$OFFSITE_RCLONE_REMOTE" && rclone lsf --files-only "$OFFSITE_RCLONE_REMOTE"
            ;;
    esac
}

remote_push_spool() {
    case "$OFFSITE_MODE" in
        rsync)
            rsync -a --chmod=F600 -e "ssh -p $OFFSITE_SSH_PORT -o BatchMode=yes -o ConnectTimeout=20" \
                "$SPOOL/" "$OFFSITE_RSYNC_DEST/"
            ;;
        rclone)
            rclone copy "$SPOOL" "$OFFSITE_RCLONE_REMOTE" \
                && rclone check --one-way "$SPOOL" "$OFFSITE_RCLONE_REMOTE"
            ;;
    esac
}

# sha256 of one remote file. For rsync this reads the file back on the far
# side; for rclone, `check --one-way` in remote_push_spool already compared
# hashes (or sizes, on backends without hashes), so this is only the rsync leg.
remote_sha256() {
    ssh -p "$OFFSITE_SSH_PORT" -o BatchMode=yes -o ConnectTimeout=20 "$(ssh_target)" \
        "sha256sum '$(ssh_path)/$1'" | cut -d' ' -f1
}

remote_delete() {
    case "$OFFSITE_MODE" in
        rsync)
            ssh -p "$OFFSITE_SSH_PORT" -o BatchMode=yes -o ConnectTimeout=20 "$(ssh_target)" \
                "cd '$(ssh_path)' && rm -f $*"
            ;;
        rclone)
            local name
            for name in "$@"; do rclone deletefile "$OFFSITE_RCLONE_REMOTE/$name"; done
            ;;
    esac
}

remote_fetch() {
    case "$OFFSITE_MODE" in
        rsync)
            scp -P "$OFFSITE_SSH_PORT" -o BatchMode=yes -o ConnectTimeout=20 \
                "$(ssh_target):$(ssh_path)/$1" "$2"
            ;;
        rclone)
            rclone copyto "$OFFSITE_RCLONE_REMOTE/$1" "$2"
            ;;
    esac
}

offsite_label() {
    case "$OFFSITE_MODE" in
        rsync) printf 'rsync:%s' "$OFFSITE_RSYNC_DEST" ;;
        rclone) printf 'rclone:%s' "$OFFSITE_RCLONE_REMOTE" ;;
        *) printf 'unconfigured' ;;
    esac
}

key_fingerprint() {
    if [ -f "$OFFSITE_KEY" ]; then
        sha256sum "$OFFSITE_KEY" | cut -c1-16
    else
        printf 'none'
    fi
}

# The status file is how anything OUTSIDE this script — the in-app watcher, an
# operator at 3am — learns whether the offsite copy is alive. It is written on
# every outcome including "not configured", and last_success survives failures,
# because "when did this last work" is the question a dead disk gets asked.
#
# newest_artifact is the load-bearing field: last_success only proves the SYNC
# ran, and a sync that pushes nothing keeps succeeding after the local backup
# has died. The timestamp baked into the newest remote artifact's name is the
# only number that goes stale when backups stop being made.
write_status() {
    local result="$1" pushed="${2:-0}" remote_count="${3:-null}" newest="${4:-}" drill="${5:-}"

    mkdir -p "$STATUS_DIR"
    STATUS_FILE="$STATUS" RESULT="$result" LABEL="$(offsite_label)" \
    PUSHED="$pushed" REMOTE_COUNT="$remote_count" NEWEST="$newest" \
    DRILL="$drill" KEY_FP="$(key_fingerprint)" ENGINE="$ENGINE" python3 - <<'PY'
import json, os, datetime

path = os.environ["STATUS_FILE"]
now = datetime.datetime.now().astimezone().isoformat(timespec="seconds")

previous = {}
try:
    with open(path) as handle:
        previous = json.load(handle)
except (OSError, ValueError):
    pass
if not isinstance(previous, dict):
    previous = {}

result = os.environ["RESULT"]
remote = os.environ["REMOTE_COUNT"]
newest = os.environ["NEWEST"]
drill = os.environ["DRILL"]

status = {
    "configured": result != "unconfigured",
    "destination": os.environ["LABEL"],
    "last_run": now,
    "last_result": result,
    "last_success": now if result == "ok" else previous.get("last_success"),
    "last_pushed": int(os.environ["PUSHED"]),
    "remote_count": None if remote == "null" else int(remote),
    "newest_artifact": newest if newest else previous.get("newest_artifact"),
    # The drill writes these; sync runs carry them forward untouched.
    "last_drill": now if drill else previous.get("last_drill"),
    "last_drill_result": drill if drill else previous.get("last_drill_result"),
    # So the copy of the key the owner stored elsewhere can be checked against
    # the one actually in use, before the day that difference matters.
    "key_fingerprint": os.environ["KEY_FP"],
    # Which database engine produced the artifacts this status describes.
    "engine": os.environ["ENGINE"],
}

tmp = path + ".tmp"
with open(tmp, "w") as handle:
    json.dump(status, handle, indent=2)
    handle.write("\n")
os.replace(tmp, path)
os.chmod(path, 0o640)
PY
    # Readable by the app (erp:backup-watch runs as www-data), nobody else.
    chgrp www-data "$STATUS" 2>/dev/null || true
    chmod 750 "$STATUS_DIR" 2>/dev/null || true
    chgrp www-data "$STATUS_DIR" 2>/dev/null || true
}

check_key() {
    if [ ! -f "$OFFSITE_KEY" ]; then
        write_status failed
        cat >&2 <<EOF
No encryption key at $OFFSITE_KEY. Create one:

    install -d -m 700 $(dirname "$OFFSITE_KEY")
    umask 077 && openssl rand -hex 32 > $OFFSITE_KEY

then COPY IT SOMEWHERE OFF THIS MACHINE (password manager, printed page in a
drawer). The offsite copies are encrypted with it; if this disk dies and the
key lived only here, the backups are unreadable noise.
EOF
        exit 1
    fi

    case "$(stat -c %a "$OFFSITE_KEY")" in
        600|400) ;;
        *) write_status failed; fail "$OFFSITE_KEY must be mode 600 or 400" ;;
    esac

    [ "$(wc -c < "$OFFSITE_KEY")" -ge 32 ] || { write_status failed; fail "$OFFSITE_KEY is shorter than 32 bytes — not a real key"; }
}

check_offsite_config() {
    if [ -n "$CONF_ERROR" ]; then
        write_status failed
        fail "refusing to use $CONF: $CONF_ERROR"
    fi

    case "$OFFSITE_MODE" in
        rsync)
            [[ "$OFFSITE_RSYNC_DEST" == *@*:/* ]] \
                || { write_status failed; fail "OFFSITE_RSYNC_DEST must look like user@host:/absolute/path (got '$OFFSITE_RSYNC_DEST')"; }
            ;;
        rclone)
            command -v rclone >/dev/null || { write_status failed; fail "OFFSITE_MODE=rclone but rclone is not installed"; }
            [ -n "$OFFSITE_RCLONE_REMOTE" ] || { write_status failed; fail "OFFSITE_RCLONE_REMOTE is empty"; }
            ;;
        "")
            write_status unconfigured
            cat >&2 <<EOF

OFFSITE COPY NOT CONFIGURED — every backup this host owns is on the disk it is
trying to protect. Edit $CONF (see deploy/erp1-backup.conf.example):

  another server you control:   OFFSITE_MODE=rsync
                                OFFSITE_RSYNC_DEST=user@host:/srv/erp1-backups
                                (authorize /root/.ssh/id_ed25519.pub there)

  any cloud bucket:             OFFSITE_MODE=rclone
                                OFFSITE_RCLONE_REMOTE=remote:bucket/erp1
                                (run 'rclone config' once, as root)

Then run:  $0 --offsite-only   and expect "offsite ok".
EOF
            exit 3
            ;;
        *)
            write_status failed
            fail "OFFSITE_MODE must be rsync, rclone, or empty (got '$OFFSITE_MODE')"
            ;;
    esac

    # Offsite is the copy of last resort; retiring it before the local copy
    # would invert that. A misconfigured 7 here against local 14 would push,
    # age, and delete remote artifacts the local side still counts on.
    [ "$OFFSITE_KEEP_DAYS" -ge "$KEEP_DAYS" ] 2>/dev/null \
        || { write_status failed; fail "OFFSITE_KEEP_DAYS ($OFFSITE_KEEP_DAYS) must be a number >= KEEP_DAYS ($KEEP_DAYS)"; }
}

do_offsite() {
    local listing missing name pushed=0

    # A sync with nothing local to sync is not a success — it means the local
    # backup stopped being produced, and "offsite ok" would launder that.
    if ! ls "$DEST"/erp1-db-*."$DB_EXT".gz >/dev/null 2>&1; then
        log "no local database artifact in $DEST — the LOCAL backup is broken, refusing to report offsite ok" >&2
        return 1
    fi

    # Rebuilt from scratch each run: a stale spool from a failed push must not
    # ship yesterday's ciphertext for a file the remote meanwhile received.
    rm -rf "$SPOOL"
    mkdir -p "$SPOOL"
    chmod 700 "$SPOOL"

    listing="$(remote_ls)" || return 1

    # Encrypt whatever the remote is missing. GPG symmetric AES-256 with the
    # keyfile: the artifacts cross infrastructure this company does not own,
    # and they contain payroll, NIK and bank accounts.
    missing=0
    for file in "$DEST"/erp1-*.gz; do
        [ -e "$file" ] || continue
        name="$(basename "$file").gpg"

        if ! grep -qxF "$name" <<<"$listing"; then
            gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
                --passphrase-file "$OFFSITE_KEY" --output "$SPOOL/$name" "$file" \
                || return 1
            missing=$((missing + 1))
        fi
    done

    if [ "$missing" -gt 0 ]; then
        log "pushing $missing file(s) to $(offsite_label)"

        if ! remote_push_spool; then
            # A failed or unverified push may have left partial files on the
            # remote under final names; the sync would then trust them forever.
            quarantine_spool
            return 1
        fi

        # Read-back verification. rclone verified inside remote_push_spool;
        # the rsync leg is checked here, hash by hash.
        if [ "$OFFSITE_MODE" = rsync ]; then
            for file in "$SPOOL"/*.gpg; do
                [ -e "$file" ] || continue
                name="$(basename "$file")"
                local want got
                want="$(sha256sum "$file" | cut -d' ' -f1)"
                got="$(remote_sha256 "$name")" || { quarantine_spool; return 1; }

                if [ "$want" != "$got" ]; then
                    log "checksum mismatch on $name (local $want, remote $got) — deleting the remote copy" >&2
                    remote_delete "$name" || true
                    return 1
                fi
            done
        fi

        pushed=$missing
    else
        log "remote already has every local artifact"
    fi

    rm -rf "$SPOOL"

    # Remote retention, by the timestamp in the filename rather than remote
    # mtimes — object stores reset mtime on upload, filenames do not lie. The
    # name filter doubles as an injection guard: remote_delete hands names to a
    # shell on the far side, and a compromised destination could list a file
    # called 'erp1-x; anything.gz.gpg'. Only the exact shape this script itself
    # uploads is eligible — for pruning, counting, or anything else.
    local cutoff keep stale=() valid_db=() valid_files=()
    cutoff="$(date -d "-$OFFSITE_KEEP_DAYS days" +%Y%m%d)"

    listing="$(remote_ls)" || return 1
    while IFS= read -r name; do
        # Both database shapes stay eligible: after the cut-over the remote
        # holds .sqlite.gz.gpg from before and .sql.gz.gpg from after, and the
        # older ones must keep counting and keep ageing out.
        [[ "$name" =~ ^erp1-(db|files)-([0-9]{8})-[0-9]{6}\.(sqlite|sql|tar)\.gz\.gpg$ ]] || continue

        case "${BASH_REMATCH[1]}" in
            db) valid_db+=("$name") ;;
            files) valid_files+=("$name") ;;
        esac
    done <<<"$listing"

    # Same floor as local rotation: the newest MIN_KEEP of each type are
    # immortal. Age alone must never be able to empty the remote — age is a
    # clock's opinion, and the last copies are not the place to trust it.
    keep="$(
        { printf '%s\n' "${valid_db[@]:-}" | sort | tail -"$MIN_KEEP"
          printf '%s\n' "${valid_files[@]:-}" | sort | tail -"$MIN_KEEP"; } || true
    )"

    for name in "${valid_db[@]:-}" "${valid_files[@]:-}"; do
        [ -n "$name" ] || continue
        grep -qxF "$name" <<<"$keep" && continue
        [[ "$name" =~ -([0-9]{8})-[0-9]{6}\. ]] || continue
        [ "${BASH_REMATCH[1]}" -lt "$cutoff" ] && stale+=("$name")
    done

    if [ "${#stale[@]}" -gt 0 ]; then
        log "pruning ${#stale[@]} offsite file(s) older than $OFFSITE_KEEP_DAYS days"
        remote_delete "${stale[@]}" || return 1
    fi

    # What survives, and how fresh the freshest database copy is. Both go into
    # the status file; an empty remote is a failure by definition — a sync that
    # ends with zero offsite copies did not do its job, whatever its exit codes.
    local remote_count newest="" stale_list
    remote_count=$(( ${#valid_db[@]} + ${#valid_files[@]} - ${#stale[@]} ))
    stale_list="$(printf '%s\n' "${stale[@]:-}")"

    for name in "${valid_db[@]:-}"; do
        [ -n "$name" ] || continue
        grep -qxF "$name" <<<"$stale_list" && continue
        [[ "$name" =~ -([0-9]{8}-[0-9]{6})\. ]] || continue
        [[ "${BASH_REMATCH[1]}" > "$newest" ]] && newest="${BASH_REMATCH[1]}"
    done

    if [ "$remote_count" -lt 1 ] || [ -z "$newest" ]; then
        log "remote holds no database artifact after sync — refusing to report ok" >&2
        return 1
    fi

    write_status ok "$pushed" "$remote_count" "$newest"
    log "offsite ok — $remote_count file(s) at $(offsite_label), newest $newest, $pushed pushed this run"
}

# Best-effort removal of everything this run attempted to upload. Runs on the
# failure path, so its own failures are logged and swallowed — the run is
# already returning non-zero.
quarantine_spool() {
    local names=() file

    for file in "$SPOOL"/*.gpg; do
        [ -e "$file" ] || continue
        names+=("$(basename "$file")")
    done

    if [ "${#names[@]}" -gt 0 ]; then
        log "quarantining ${#names[@]} possibly-partial remote file(s)" >&2
        remote_delete "${names[@]}" 2>/dev/null || log "quarantine delete failed — remote may hold partial files" >&2
    fi
}

run_offsite() {
    check_offsite_config
    check_key

    if ! do_offsite; then
        write_status failed
        fail "offsite sync to $(offsite_label) did not complete — the OFFSITE copy is not in a good state"
    fi
}

# ======================================================================= drill
#
# Fetch the newest offsite database artifact (or, with --source=local, take the
# newest local one), decrypt it with the key, and RESTORE it. This exercises
# the exact path a disaster recovery would take: remote reachable, artifact
# intact, key correct, ciphertext decryptable, database sound. It is also the
# ONLY check a hostile or bit-rotted destination cannot fake, because it reads
# real bytes back — which is why cron runs it monthly and its result lands in
# the status file for the watcher.
#
# Engine sqlite restores INTO A TEMP DIR and touches nothing live. Engine mysql
# loads the dump into the erp_restore_check schema — the one schema the drill
# may write — counts users and journals there, then runs erp:migration-verify
# (T0.5) between the LIVE database and the restored copy: row counts, decimal
# sums and key hashes per table. Zero differing tables is "passed"; differing
# tables are "drift" — the live database moved since the dump was taken (a
# day's work, or the 75 minutes between the 02:15 backup and the 03:30 drill,
# which is why the drill is scheduled at night) — reported with the count so
# an operator can tell a Tuesday afternoon from a broken restore.

DRILL_TMP=""

# The artifact, decompressed, at $1. Sets DRILL_ARTIFACT and DRILL_AGE_DAYS.
fetch_drill_artifact() {
    local target="$1" listing newest

    if [ "$DRILL_SOURCE" = local ]; then
        newest="$(ls -1 "$DEST"/erp1-db-*."$DB_EXT".gz 2>/dev/null | sort | tail -1 || true)"
        [ -n "$newest" ] || { log "no local $DB_EXT database artifact in $DEST — run a backup first" >&2; return 1; }
        DRILL_ARTIFACT="$(basename "$newest")"
        [[ "$DRILL_ARTIFACT" =~ ([0-9]{8})-([0-9]{6}) ]] || { log "unparseable artifact name $DRILL_ARTIFACT" >&2; return 1; }
        DRILL_AGE_DAYS=$(( ($(date +%s) - $(date -d "${BASH_REMATCH[1]}" +%s)) / 86400 ))

        log "drill: using local $DRILL_ARTIFACT (${DRILL_AGE_DAYS}d old, no GPG on the local copy)"
        gunzip -c "$newest" > "$target" || { log "decompress failed" >&2; return 1; }
        return 0
    fi

    listing="$(remote_ls)" || { log "cannot list $(offsite_label)" >&2; return 1; }

    # Exact-shape match, not a loose glob: this name is about to travel through
    # scp/rclone as a path, and the listing is remote-controlled data. The
    # `|| true` matters: with pipefail, grep finding nothing would kill the
    # script mid-assignment with no diagnostic at all.
    newest="$(grep -E "^erp1-db-[0-9]{8}-[0-9]{6}\\.${DB_EXT}\\.gz\\.gpg\$" <<<"$listing" | sort | tail -1 || true)"
    [ -n "$newest" ] || { log "no $DB_EXT database artifact at $(offsite_label) — nothing to drill against" >&2; return 1; }
    DRILL_ARTIFACT="$newest"

    # How old is the thing we would be restoring? If the answer is a week, the
    # drill "passes" and the operator still needs to hear it.
    [[ "$newest" =~ ([0-9]{8})-([0-9]{6}) ]] || { log "unparseable artifact name $newest" >&2; return 1; }
    DRILL_AGE_DAYS=$(( ($(date +%s) - $(date -d "${BASH_REMATCH[1]}" +%s)) / 86400 ))

    log "drill: fetching $newest (${DRILL_AGE_DAYS}d old, key $(key_fingerprint))"
    remote_fetch "$newest" "$target.gz.gpg" || { log "fetch failed" >&2; return 1; }

    gpg --batch --quiet --decrypt --passphrase-file "$OFFSITE_KEY" \
        --output "$target.gz" "$target.gz.gpg" \
        || { log "decrypt failed — WRONG OR ROTTEN KEY" >&2; return 1; }
    gunzip "$target.gz" || { log "decompress failed" >&2; return 1; }
}

drill_check_sqlite() {
    php -r '
$check = new PDO("sqlite:{$argv[1]}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = $check->query("PRAGMA integrity_check")->fetchColumn();
if ($result !== "ok") { fwrite(STDERR, "integrity_check said: {$result}\n"); exit(1); }
$users = (int) $check->query("select count(*) from users")->fetchColumn();
$journals = (int) $check->query("select count(*) from fin_journals")->fetchColumn();
if ($users < 1) { fwrite(STDERR, "restored database has no users\n"); exit(1); }
fwrite(STDOUT, "restored copy verified: {$users} users, {$journals} journals\n");
' "$1" || { log "restored database did not verify" >&2; return 1; }
}

# Load the dump into erp_restore_check (emptied first, every table, so a
# table production dropped since cannot linger and skew the comparison), then
# prove it against the live database.
drill_restore_mysql() {
    local file="$1" tables users journals

    verify_mysql_dump "$file" || return 1

    tables="$(mysql_cli -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$MYSQL_RESTORE_DATABASE'")" \
        || { log "cannot list $MYSQL_RESTORE_DATABASE — credential or grant problem" >&2; return 1; }

    if [ -n "$tables" ]; then
        {
            echo "SET FOREIGN_KEY_CHECKS = 0;"
            while IFS= read -r t; do
                [ -n "$t" ] || continue
                [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] || { log "refusing odd table name in $MYSQL_RESTORE_DATABASE: $t" >&2; return 1; }
                echo "DROP TABLE IF EXISTS \`$t\`;"
            done <<<"$tables"
        } | mysql_cli "$MYSQL_RESTORE_DATABASE" || { log "could not empty $MYSQL_RESTORE_DATABASE" >&2; return 1; }
    fi

    log "drill: loading dump into $MYSQL_RESTORE_DATABASE"
    mysql_cli "$MYSQL_RESTORE_DATABASE" < "$file" || { log "restore into $MYSQL_RESTORE_DATABASE FAILED" >&2; return 1; }

    users="$(mysql_cli -e 'SELECT COUNT(*) FROM users' "$MYSQL_RESTORE_DATABASE")" || return 1
    journals="$(mysql_cli -e 'SELECT COUNT(*) FROM fin_journals' "$MYSQL_RESTORE_DATABASE")" || return 1
    [ "$users" -ge 1 ] 2>/dev/null || { log "restored database has no users" >&2; return 1; }
    log "restored copy verified: $users users, $journals journals in $MYSQL_RESTORE_DATABASE"

    drill_verify_mysql
}

# erp:migration-verify --from=<live> --to=mysql_restore_check. Its exit 1 means
# "tables differ", which here is drift, not failure; anything else it prints
# without a verdict line is a failure of the verify itself.
drill_verify_mysql() {
    local out rc=0 verdict report

    out="$(cd "$SITE" && run_artisan erp:migration-verify --from="$VERIFY_FROM" --to=mysql_restore_check --ignore="$VOLATILE_TABLES" 2>&1)" || rc=$?

    verdict="$(grep -E '^[0-9]+ tabel dibandingkan' <<<"$out" | tail -1 || true)"
    report="$(grep -E '^Laporan: ' <<<"$out" | tail -1 | sed 's/^Laporan: //' || true)"

    if [ -z "$verdict" ]; then
        log "erp:migration-verify did not run to a verdict (exit $rc):" >&2
        printf '%s\n' "$out" | tail -5 >&2
        return 1
    fi

    log "verify: $verdict"
    [ -n "$report" ] && log "verify report: $report"

    if [ "$rc" -eq 0 ]; then
        DRILL_DIFF=0
    else
        if [[ "$verdict" =~ ,\ ([0-9]+)\ selisih,\ ([0-9]+)\ tidak\ diketahui ]]; then
            DRILL_DIFF=$(( BASH_REMATCH[1] + BASH_REMATCH[2] ))
        else
            DRILL_DIFF='?'
        fi
        log "verify: $DRILL_DIFF table(s) differ from the live database — drift since the dump (${DRILL_AGE_DAYS}d old), or a problem if nothing was written meanwhile; read the report"
    fi

    return 0
}

do_drill() {
    # Deliberately a global: the EXIT trap fires after this function has
    # returned, and under `set -u` an out-of-scope local would blow up the trap
    # itself — turning a passed drill into a non-zero exit.
    DRILL_TMP="$(mktemp -d /tmp/erp1-drill.XXXXXX)"
    trap 'rm -rf "${DRILL_TMP:-}"' EXIT

    fetch_drill_artifact "$DRILL_TMP/db.$DB_EXT" || return 1

    case "$ENGINE" in
        sqlite) drill_check_sqlite "$DRILL_TMP/db.sqlite" || return 1 ;;
        mysql) drill_restore_mysql "$DRILL_TMP/db.sql" || return 1 ;;
    esac

    local where
    [ "$DRILL_SOURCE" = local ] && where="local $DEST" || where="$(offsite_label)"

    if [ "${DRILL_DIFF:-0}" = 0 ]; then
        log "RESTORE DRILL PASSED — $DRILL_ARTIFACT, ${DRILL_AGE_DAYS} day(s) old, from $where"
    else
        log "RESTORE DRILL PASSED WITH DRIFT ($DRILL_DIFF table(s)) — $DRILL_ARTIFACT, ${DRILL_AGE_DAYS} day(s) old, from $where"
    fi

    [ "$DRILL_AGE_DAYS" -gt 2 ] && log "WARNING: newest $DB_EXT copy is ${DRILL_AGE_DAYS} days old — check the nightly cron"
    return 0
}

run_drill() {
    if [ "$ENGINE" = mysql ]; then
        check_mysql_config
    fi

    if [ "$DRILL_SOURCE" = local ]; then
        # Says nothing about the offsite copy, so writes nothing to its status.
        do_drill || fail "restore drill from the local artifact FAILED"
        return 0
    fi

    check_offsite_config
    check_key

    if do_drill; then
        if [ "${DRILL_DIFF:-0}" = 0 ]; then
            write_status ok 0 null "" passed
        else
            write_status ok 0 null "" drift
        fi
    else
        write_status failed 0 null "" failed
        fail "restore drill against $(offsite_label) FAILED — the offsite copies may not be restorable"
    fi
}

# ======================================================================== main

case "$MODE" in
    full)
        run_local_backup
        run_offsite
        ;;
    local)
        run_local_backup
        ;;
    offsite)
        [ -d "$DEST" ] || fail "no local backups at $DEST yet — run a full backup first"
        run_offsite
        ;;
    drill)
        run_drill
        ;;
esac
