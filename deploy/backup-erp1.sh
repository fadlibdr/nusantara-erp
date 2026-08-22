#!/usr/bin/env bash
#
# Daily backup for the bare-metal SQLite deployment at erp1.pi2.co.id.
#
# deploy/backup/backup.sh is the Docker/MySQL script and CANNOT run here: it only
# knows mysqldump, and this host serves SQLite from php-fpm with no containers.
# Until that stack exists, this is the backup.
#
# Two things are backed up, and both are irreplaceable:
#
#   database/database.sqlite   every document, journal and user
#   storage/app/private        uploaded attachments — scans of faktur pajak,
#                              site photographs, vendor invoices
#
# The database is copied with VACUUM INTO, not cp. Copying a SQLite file while
# anything is writing to it yields a torn file that looks fine until you need it:
# VACUUM INTO takes a read lock and writes a consistent, compacted snapshot. The
# result is then opened and integrity-checked before it is allowed to count as a
# backup, because an unverified backup is a guess. Every artifact is built under
# a .part name and renamed only after its container verifies (gzip -t, tar -t):
# a crash mid-write must never leave a truncated file wearing a finished name.
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
#                                   and prove it opens. A backup you have never
#                                   restored is a hypothesis.
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

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fail() { log "FAILED: $*" >&2; exit 1; }

MODE="full"
case "${1:-}" in
    "") ;;
    --local-only) MODE="local" ;;
    --offsite-only) MODE="offsite" ;;
    --restore-drill) MODE="drill" ;;
    *) fail "unknown option '$1' (use --local-only, --offsite-only or --restore-drill)" ;;
esac

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

    if LC_ALL=C grep -qvE '^[[:space:]]*(#|$)|^(OFFSITE_MODE|OFFSITE_RSYNC_DEST|OFFSITE_SSH_PORT|OFFSITE_RCLONE_REMOTE|OFFSITE_KEEP_DAYS|OFFSITE_KEY)=[A-Za-z0-9@:./_-]*$' "$CONF"; then
        CONF_ERROR="$CONF contains something other than plain KEY=value lines (check for spaces around '=', quotes, or CRLF line endings)"
        return 0
    fi

    . "$CONF"
}
load_conf

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
    [ -f "$DB" ] || fail "database not found at $DB"

    mkdir -p "$DEST"
    chmod 700 "$DEST"

    # Leftovers from a run that died mid-write. Never eligible for shipping
    # (the offsite glob wants *.gz), but no reason to hoard corpses either.
    rm -f "$DEST"/erp1-*.part

    # ------------------------------------------------------------ database
    local db_out="$DEST/erp1-db-$STAMP.sqlite"

    log "snapshotting database"
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

    gzip -9 -c "$db_out" > "$db_out.gz.part" || { rm -f "$db_out" "$db_out.gz.part"; fail "gzip failed"; }
    rm -f "$db_out"
    finish_artifact "$db_out.gz.part" "$db_out.gz" || fail "database artifact failed container verification"
    log "database -> $(basename "$db_out").gz ($(du -h "$db_out.gz" | cut -f1))"

    # --------------------------------------------------------- attachments
    if [ -d "$FILES" ]; then
        local files_out="$DEST/erp1-files-$STAMP.tar.gz"
        tar -czf "$files_out.part" -C "$SITE/storage/app" private \
            || { rm -f "$files_out.part"; fail "attachment tar failed"; }
        finish_artifact "$files_out.part" "$files_out" || fail "attachment artifact failed container verification"
        log "attachments -> $(basename "$files_out") ($(du -h "$files_out" | cut -f1), $(find "$FILES" -type f | wc -l) files)"
    else
        log "no attachments directory at $FILES — skipping"
    fi

    # -------------------------------------------------------------- rotate
    rotate_local

    log "local done — $(find "$DEST" -maxdepth 1 -name 'erp1-*' -type f | wc -l) files, $(du -sh "$DEST" | cut -f1) total"
}

# Age-based rotation with a floor: the newest MIN_KEEP of each type survive no
# matter what their mtime claims. Otherwise a clock jumped forward — or a long
# outage — deletes every local copy in one silent pass.
rotate_local() {
    local keep old
    keep="$(
        { ls -1 "$DEST"/erp1-db-*.sqlite.gz 2>/dev/null | sort | tail -"$MIN_KEEP"
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
    DRILL="$drill" KEY_FP="$(key_fingerprint)" python3 - <<'PY'
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
    if ! ls "$DEST"/erp1-db-*.sqlite.gz >/dev/null 2>&1; then
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
        [[ "$name" =~ ^erp1-(db|files)-([0-9]{8})-[0-9]{6}\.(sqlite|tar)\.gz\.gpg$ ]] || continue

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
# Fetch the newest offsite database artifact, decrypt it with the key, open it,
# and count users. This exercises the exact path a disaster recovery would take:
# remote reachable, artifact intact, key correct, ciphertext decryptable,
# database sound. It is also the ONLY check a hostile or bit-rotted destination
# cannot fake, because it reads real bytes back — which is why cron runs it
# monthly and its result lands in the status file for the watcher. It restores
# INTO A TEMP DIR and touches nothing live.

do_drill() {
    local listing newest tmp

    listing="$(remote_ls)" || { log "cannot list $(offsite_label)" >&2; return 1; }

    # Exact-shape match, not a loose glob: this name is about to travel through
    # scp/rclone as a path, and the listing is remote-controlled data. The
    # `|| true` matters: with pipefail, grep finding nothing would kill the
    # script mid-assignment with no diagnostic at all.
    newest="$(grep -E '^erp1-db-[0-9]{8}-[0-9]{6}\.sqlite\.gz\.gpg$' <<<"$listing" | sort | tail -1 || true)"
    [ -n "$newest" ] || { log "no database artifact at $(offsite_label) — nothing to drill against" >&2; return 1; }

    # How old is the thing we would be restoring? If the answer is a week, the
    # drill "passes" and the operator still needs to hear it.
    [[ "$newest" =~ ([0-9]{8})-([0-9]{6}) ]] || { log "unparseable artifact name $newest" >&2; return 1; }
    local age_days=$(( ($(date +%s) - $(date -d "${BASH_REMATCH[1]}" +%s)) / 86400 ))

    # Deliberately a global: the EXIT trap fires after this function has
    # returned, and under `set -u` an out-of-scope local would blow up the trap
    # itself — turning a passed drill into a non-zero exit.
    DRILL_TMP="$(mktemp -d /tmp/erp1-drill.XXXXXX)"
    trap 'rm -rf "${DRILL_TMP:-}"' EXIT
    tmp="$DRILL_TMP"

    log "drill: fetching $newest (${age_days}d old, key $(key_fingerprint))"
    remote_fetch "$newest" "$tmp/db.sqlite.gz.gpg" || { log "fetch failed" >&2; return 1; }

    gpg --batch --quiet --decrypt --passphrase-file "$OFFSITE_KEY" \
        --output "$tmp/db.sqlite.gz" "$tmp/db.sqlite.gz.gpg" \
        || { log "decrypt failed — WRONG OR ROTTEN KEY" >&2; return 1; }
    gunzip "$tmp/db.sqlite.gz" || { log "decompress failed" >&2; return 1; }

    php -r '
$check = new PDO("sqlite:{$argv[1]}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$result = $check->query("PRAGMA integrity_check")->fetchColumn();
if ($result !== "ok") { fwrite(STDERR, "integrity_check said: {$result}\n"); exit(1); }
$users = (int) $check->query("select count(*) from users")->fetchColumn();
$journals = (int) $check->query("select count(*) from fin_journals")->fetchColumn();
if ($users < 1) { fwrite(STDERR, "restored database has no users\n"); exit(1); }
fwrite(STDOUT, "restored copy verified: {$users} users, {$journals} journals\n");
' "$tmp/db.sqlite" || { log "restored database did not verify" >&2; return 1; }

    log "RESTORE DRILL PASSED — $newest, ${age_days} day(s) old, from $(offsite_label)"
    [ "$age_days" -gt 2 ] && log "WARNING: newest offsite copy is ${age_days} days old — check the nightly cron"
    return 0
}

run_drill() {
    check_offsite_config
    check_key

    if do_drill; then
        write_status ok 0 null "" passed
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
