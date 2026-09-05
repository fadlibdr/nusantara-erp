#!/usr/bin/env bash
#
# Cut-over erp1.pi2.co.id: SQLite → MySQL 8 (ROADMAP-HASHMICRO Fase 0, T0.6).
#
# DIJALANKAN MANUAL OLEH PEMILIK/ORKESTRATOR — satu langkah per pemanggilan,
# dalam urutan runbook docs/DEPLOYMENT.md §10.9. Skrip ini TIDAK pernah
# dijalankan otomatis (bukan cron, bukan deploy), dan tidak melakukan apa pun
# tanpa nama langkah. Setiap langkah:
#
#   1. mencetak apa yang AKAN dilakukannya,
#   2. menolak berjalan bila langkah sebelumnya belum tercatat selesai di
#      $WORK/state (urutan adalah bagian dari keselamatan: `salin` sebelum
#      `down` berarti menyalin basis data yang masih ditulis),
#   3. berhenti pada galat pertama (set -e) — tidak ada langkah yang
#      "melanjutkan saja",
#   4. mencatat dirinya selesai HANYA bila semua pemeriksaannya lulus.
#
#   cutover-erp1.sh pra          pemeriksaan pra-terbang (tanpa mengubah apa pun)
#   cutover-erp1.sh basisdata    CREATE DATABASE erp + migrate:fresh (skema kosong)
#   cutover-erp1.sh down         php artisan down --secret, parkir cron, bekukan SQLite
#   cutover-erp1.sh snapshot     snapshot SQLite terakhir + GPG (arsip 30 hari)
#   cutover-erp1.sh salin        erp:sqlite-to-mysql
#   cutover-erp1.sh verifikasi   erp:migration-verify — wajib 0 selisih
#   cutover-erp1.sh env          .env → mysql; migrate --pretend wajib kosong; config:cache
#   cutover-erp1.sh smoke        masuk, daftar PO, laporan harian ganda → 422, permission-check
#   cutover-erp1.sh up           php artisan up, cron kembali, cadangan beralih ke mysqldump
#   cutover-erp1.sh rollback     .env kembali ke SQLite (berkas tidak pernah disentuh) — jendela 24 jam
#   cutover-erp1.sh status       apa yang sudah tercatat
#
#   --dry-run    cetak perintahnya saja, tidak menjalankan apa pun, tidak
#                mencatat apa pun. Aman dijalankan kapan saja.
#   --yes        WAJIB untuk benar-benar menjalankan langkah apa pun selain
#                `status` — tanpa --yes skrip hanya mencetak rencana (= --dry-run).
#
# Lingkungan yang dibutuhkan:
#   CUTOVER_CRED=/path/mysql-erp.cred   berkas mode 600 berisi DB_USERNAME= dan
#                                       DB_PASSWORD= (§10.3). Tidak pernah dicetak.
#   SMOKE_EMAIL, SMOKE_PASSWORD         akun untuk langkah smoke (pengguna nyata
#                                       dengan izin prc.view + prj.create).
#
# Rollback = .env kembali ke SQLite. Berkas database.sqlite tidak pernah
# ditulis oleh skrip ini sesudah `down` (hanya dibaca oleh erp:sqlite-to-mysql
# dan erp:migration-verify), jadi rollback adalah pergantian .env, bukan
# pemulihan data. Yang ditulis pengguna ke MySQL antara `up` dan `rollback`
# TIDAK ikut kembali — itulah mengapa jendelanya 24 jam dan cut-over dijadwalkan
# Sabtu pagi (keputusan pemilik #6).

set -euo pipefail

SITE="${ERP_SITE:-/var/www/erp1.pi2.co.id}"
WORK="${CUTOVER_DIR:-/var/backups/erp1/cutover}"
STATE="$WORK/state"
CRED="${CUTOVER_CRED:-}"
BASE_URL="${ERP_BASE_URL:-https://erp1.pi2.co.id}"
DB_NAME="${CUTOVER_DB:-erp}"
CRON_FILE=/etc/cron.d/erp1
# A dot in the name: cron ignores files in /etc/cron.d whose names contain
# one, so "parking" the file disables it without editing it.
CRON_PARKED=/etc/cron.d/erp1.cutover-parked
ENV_FILE="$SITE/.env"
SQLITE="$SITE/database/database.sqlite"
BACKUP_CONF=/etc/erp1/backup.conf
BACKUP_KEY=/etc/erp1/backup.key
MYSQL_CNF=/etc/erp1/mysql-backup.cnf
PHP_FPM="${ERP_PHP_FPM:-php8.3-fpm}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DRY=0
YES=0
STEPS=(pra basisdata down snapshot salin verifikasi env smoke up)
STEP=""

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=1 ;;
        --yes) YES=1 ;;
        pra|basisdata|down|snapshot|salin|verifikasi|env|smoke|up|rollback|status) STEP="$arg" ;;
        *) printf 'opsi tidak dikenal: %s\n' "$arg" >&2; exit 2 ;;
    esac
done

[ -n "$STEP" ] || { sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

# Without an explicit --yes nothing is executed or recorded: the plan is
# printed as a dry-run. `status` is read-only and needs no confirmation.
if [ "$YES" != 1 ] && [ "$STEP" != status ] && [ "$DRY" != 1 ]; then
    printf 'Tanpa --yes: hanya mencetak rencana langkah %s (tambahkan --yes untuk menjalankan).\n' "$STEP"
    DRY=1
fi

# ------------------------------------------------------------------ helpers

say() { printf '%s\n' "$*"; }
akan() { printf '    AKAN  %s\n' "$*"; }
fail() { printf 'GAGAL: %s\n' "$*" >&2; exit 1; }
banner() { printf '\n==== LANGKAH %s — %s ====\n' "$STEP" "$*"; [ "$DRY" = 1 ] && say "    (dry-run: tidak ada yang dijalankan, tidak ada yang dicatat)"; true; }

# Simple argv commands. Printed, then executed unless --dry-run.
run() { printf '    $ %s\n' "$*"; [ "$DRY" = 1 ] || "$@"; }
# Pipelines and redirections, as one string. Same rule.
run_sh() { printf '    $ %s\n' "$1"; [ "$DRY" = 1 ] || bash -o pipefail -ec "$1"; }

mark_done() { [ "$DRY" = 1 ] && return 0; mkdir -p "$WORK"; printf '%s %s\n' "$STEP" "$(date -Is)" >> "$STATE"; say "    tercatat: $STEP selesai"; }
is_done() { [ -f "$STATE" ] && grep -q "^$1 " "$STATE"; }
require_done() { is_done "$1" || fail "langkah '$1' belum tercatat selesai di $STATE — urutan: ${STEPS[*]}"; }
require_not_done() {
    is_done "$STEP" || return 0
    fail "langkah '$STEP' sudah tercatat selesai ($(grep "^$STEP " "$STATE" | tail -1 | cut -d' ' -f2-)). Bila memang harus diulang, hapus barisnya dari $STATE dengan sadar."
}
require_root() { [ "$(id -u)" = 0 ] || fail "harus dijalankan sebagai root"; }

# The credential: read from the file into the environment, never printed,
# never on an argv. artisan sees it through sudo --preserve-env (root's
# sudoers entry matches ALL, which implies SETENV).
load_cred() {
    [ -n "$CRED" ] || fail "CUTOVER_CRED=<berkas dengan DB_USERNAME= dan DB_PASSWORD=> wajib diekspor"
    [ -r "$CRED" ] || fail "CUTOVER_CRED $CRED tidak terbaca"

    case "$(stat -c %a "$CRED")" in
        600|400) ;;
        *) fail "$CRED harus mode 600 atau 400" ;;
    esac

    set -a
    # shellcheck disable=SC1090
    . "$CRED"
    set +a
    : "${DB_USERNAME:?DB_USERNAME tidak ada di $CRED}" "${DB_PASSWORD:?DB_PASSWORD tidak ada di $CRED}"

    # A my.cnf-style copy for the mysql client of THIS script (deleted in `up`).
    if [ "$DRY" != 1 ]; then
        mkdir -p "$WORK"; chmod 700 "$WORK"
        ( umask 077; printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\nport=3306\n' "$DB_USERNAME" "$DB_PASSWORD" > "$WORK/.cred.cnf" )
    fi
}

# The application's own artisan, as www-data, pointed at MySQL BEFORE .env
# says so (steps basisdata..verifikasi) — through the environment only.
artisan_mysql() {
    printf '    $ (cd %s && sudo -u www-data DB_CONNECTION=mysql DB_DATABASE=%s … php artisan %s)\n' "$SITE" "$DB_NAME" "$*"
    [ "$DRY" = 1 ] && return 0
    (
        cd "$SITE"
        export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE="$DB_NAME" DB_USERNAME DB_PASSWORD SQLITE_LEGACY_PATH="$SQLITE"
        sudo -u www-data --preserve-env=DB_CONNECTION,DB_HOST,DB_PORT,DB_DATABASE,DB_USERNAME,DB_PASSWORD,SQLITE_LEGACY_PATH php artisan "$@"
    )
}

# artisan as the site is configured (.env) — after `env`, that is MySQL.
artisan() {
    # The maintenance bypass secret never reaches the transcript: any
    # --secret=… argument is printed masked (verifier 5 Sep 2026).
    local shown; shown="$(printf '%s ' "$@" | sed -E 's/--secret=[^ ]*/--secret=<rahasia>/g')"
    printf '    $ (cd %s && sudo -u www-data php artisan %s)\n' "$SITE" "${shown% }"
    [ "$DRY" = 1 ] && return 0
    (cd "$SITE" && sudo -u www-data php artisan "$@")
}
# Like run_sh, but prints a masked rendering instead of the command itself.
run_sh_masked() { printf '    $ %s\n' "$2"; [ "$DRY" = 1 ] || bash -o pipefail -ec "$1"; }

mysql_app() { mysql --defaults-extra-file="$WORK/.cred.cnf" --batch --skip-column-names "$@"; }

# Unit systemd P-0b (deploy/systemd/README.md). Dijaga is-enabled: sebelum unit
# dipasang, cut-over berjalan persis seperti sebelumnya (hanya cron yang
# diparkir). Sesudahnya, pekerja antrean yang masih menulis ke SQLite yang
# sedang dipindahkan adalah kehilangan data — jadi keduanya dihentikan di
# `down` SEBELUM berkas dibekukan, dan dinyalakan lagi di `up` / `rollback`.
ERP_UNITS=(erp1-queue erp1-scheduler)
units_stop() {
    local u
    for u in "${ERP_UNITS[@]}"; do
        if systemctl is-enabled --quiet "$u" 2>/dev/null; then run systemctl stop "$u"; else say "    $u tidak enabled — dilewati"; fi
    done
}
units_start() {
    local u
    for u in "${ERP_UNITS[@]}"; do
        if systemctl is-enabled --quiet "$u" 2>/dev/null; then run systemctl start "$u"; else say "    $u tidak enabled — dilewati"; fi
    done
}

# ------------------------------------------------------------------ steps

step_pra() {
    banner "pemeriksaan pra-terbang (tidak mengubah apa pun)"
    require_root
    require_not_done

    akan "memastikan MySQL aktif, kode baru sudah di-deploy, SQLite masih basis data aktif, tidak ada migrasi tertunda"
    akan "menguji kredensial erp dan grant pada $DB_NAME.*"
    akan "menjalankan erp:mysql-preflight pada SQLite hidup → $WORK/preflight-$STAMP.json (verdict wajib ok)"
    akan "memeriksa ruang disk dan akun smoke"

    run systemctl is-active --quiet mysql
    [ -f "$SQLITE" ] || fail "$SQLITE tidak ada"
    grep -q '^DB_CONNECTION=sqlite' "$ENV_FILE" || fail "$ENV_FILE bukan DB_CONNECTION=sqlite — cut-over sudah terjadi, atau .env bukan yang diharapkan"
    grep -q -- '--engine' "$SITE/deploy/backup-erp1.sh" 2>/dev/null || fail "kode Fase 0 belum di-deploy ke $SITE (deploy/backup-erp1.sh tanpa --engine) — jalankan deploy/sync-erp1.sh dulu"
    run_sh "cd '$SITE' && sudo -u www-data php artisan list --raw | grep -q '^erp:sqlite-to-mysql'"
    run_sh "cd '$SITE' && sudo -u www-data php artisan migrate:status | grep -qi pending && { echo 'ada migrasi tertunda pada SQLite — deploy belum menjalankannya'; exit 1; } || true"

    load_cred
    run_sh "mysql --defaults-extra-file='$WORK/.cred.cnf' -e 'SELECT CURRENT_USER()' >/dev/null"
    run_sh "mysql --defaults-extra-file='$WORK/.cred.cnf' -e 'SHOW GRANTS' | grep -q 'ON \`$DB_NAME\`\\.\\*'"

    mkdir -p "$WORK"; chmod 700 "$WORK"
    run_sh "cd '$SITE' && sudo -u www-data php artisan erp:mysql-preflight --json > '$WORK/preflight-$STAMP.json'"
    if [ "$DRY" != 1 ]; then
        python3 - "$WORK/preflight-$STAMP.json" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))
print(f"    preflight: verdict={r['verdict']} off_scale={r['decimals']['off_scale_rows']} overflow={r['decimals']['overflow_rows']} json_invalid={r['json']['invalid_rows']} unguarded_sql={r['sqlite_only_sql']['unguarded']}")
sys.exit(0 if r['verdict'] == 'ok' else 1)
PY
        say "    bandingkan dengan docs/bukti-uji/mysql-preflight-erp1-2026-09-05.json — hanya generated_at dan flag guarded yang boleh berbeda"
    fi

    run_sh "df -h / | tail -1"
    if [ -z "${SMOKE_EMAIL:-}" ] || [ -z "${SMOKE_PASSWORD:-}" ]; then
        say "    PERHATIAN: SMOKE_EMAIL/SMOKE_PASSWORD belum diekspor — langkah smoke akan menolak berjalan"
    fi

    mark_done
    say "Siap. Langkah berikutnya: $0 basisdata"
}

step_basisdata() {
    banner "CREATE DATABASE $DB_NAME + migrate:fresh (skema kosong)"
    require_root; require_done pra; require_not_done
    load_cred

    akan "CREATE DATABASE IF NOT EXISTS $DB_NAME utf8mb4/utf8mb4_unicode_ci sebagai root (auth_socket)"
    akan "migrate:fresh --force pada $DB_NAME lewat artisan www-data (256 langkah, ±45 s) — basis data KOSONG sesudahnya"

    if [ "$DRY" = 1 ]; then
        say "    $ mysql -e \"CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\""
    else
        mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    fi

    artisan_mysql migrate:fresh --force
    run_sh "mysql --defaults-extra-file='$WORK/.cred.cnf' -N -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'\" | grep -qx 190 || { echo 'jumlah tabel bukan 190'; exit 1; }"

    mark_done
    say "Langkah berikutnya: $0 down   (mulai jendela tanpa layanan)"
}

step_down() {
    banner "php artisan down --secret, parkir cron, bekukan SQLite"
    require_root; require_done basisdata; require_not_done

    local secret
    secret="$(openssl rand -hex 16)"

    akan "menyimpan rahasia bypass di $WORK/down-secret (mode 600) untuk langkah smoke"
    akan "php artisan down --secret=… --retry=60 (pengguna melihat halaman pemeliharaan; API menjawab 503)"
    akan "menghentikan unit systemd ${ERP_UNITS[*]} bila enabled (pekerja antrean tidak boleh menulis ke SQLite yang dibekukan)"
    akan "memarkir $CRON_FILE → $CRON_PARKED (scheduler & backup cron berhenti), menunggu schedule:run yang sedang berjalan"
    akan "menunggu 10 detik agar permintaan yang sedang berjalan selesai, lalu PRAGMA wal_checkpoint(TRUNCATE) dan sha256 berkas SQLite → $WORK/sqlite-frozen.sha256"

    if [ "$DRY" != 1 ]; then
        mkdir -p "$WORK"; chmod 700 "$WORK"
        ( umask 077; printf '%s\n' "$secret" > "$WORK/down-secret" )
    fi

    artisan down --secret="$secret" --retry=60
    units_stop
    [ -f "$CRON_FILE" ] && run mv "$CRON_FILE" "$CRON_PARKED"
    run_sh "for i in \$(seq 1 60); do pgrep -u www-data -f 'artisan schedule:run' >/dev/null || break; sleep 2; done; ! pgrep -u www-data -f 'artisan schedule:run' >/dev/null"
    run sleep 10
    run_sh "cd '$SITE' && sudo -u www-data php -r '\$p = new PDO(\"sqlite:$SQLITE\"); \$r = \$p->query(\"PRAGMA wal_checkpoint(TRUNCATE)\")->fetch(PDO::FETCH_NUM); echo \"checkpoint: busy={\$r[0]} log={\$r[1]} ckpt={\$r[2]}\\n\"; if ((int) \$r[0] !== 0) exit(1);'"
    run_sh "sha256sum '$SQLITE' > '$WORK/sqlite-frozen.sha256' && cat '$WORK/sqlite-frozen.sha256'"

    mark_done
    say "Langkah berikutnya: $0 snapshot"
}

step_snapshot() {
    banner "snapshot SQLite terakhir + GPG (arsip 30 hari, keputusan pemilik #6)"
    require_root; require_done down; require_not_done

    akan "backup-erp1.sh --engine sqlite --local-only (VACUUM INTO + integrity_check + gzip ke /var/backups/erp1)"
    akan "menyalin artefak terbaru ke $WORK/erp1-sqlite-final-$STAMP.sqlite.gz, mengenkripsinya dengan $BACKUP_KEY, mencatat sha256 keduanya"

    run_sh "ERP_BACKUP_ENGINE=sqlite bash '$SITE/deploy/backup-erp1.sh' --local-only"
    run_sh "newest=\$(ls -1 /var/backups/erp1/erp1-db-*.sqlite.gz | sort | tail -1); cp -p \"\$newest\" '$WORK/erp1-sqlite-final-$STAMP.sqlite.gz'"
    run_sh "gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --passphrase-file '$BACKUP_KEY' --output '$WORK/erp1-sqlite-final-$STAMP.sqlite.gz.gpg' '$WORK/erp1-sqlite-final-$STAMP.sqlite.gz'"
    run_sh "sha256sum '$WORK'/erp1-sqlite-final-$STAMP.sqlite.gz* | tee -a '$WORK/SNAPSHOT.sha256'"
    say "    arsip ini disimpan 30 hari; berkas hidup $SQLITE TIDAK disentuh"

    mark_done
    say "Langkah berikutnya: $0 salin"
}

step_salin() {
    banner "erp:sqlite-to-mysql — pindahkan data"
    require_root; require_done snapshot; require_not_done
    load_cred

    akan "memastikan berkas SQLite masih persis yang dibekukan (sha256)"
    akan "erp:sqlite-to-mysql --from=$SQLITE --to=mysql (tujuan $DB_NAME wajib kosong; satu transaksi; log ke $WORK/sqlite-to-mysql-$STAMP.log)"

    run_sh "sha256sum -c '$WORK/sqlite-frozen.sha256'"
    run_sh "cd '$SITE' && export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE='$DB_NAME' DB_USERNAME DB_PASSWORD SQLITE_LEGACY_PATH='$SQLITE' && sudo -u www-data --preserve-env=DB_CONNECTION,DB_HOST,DB_PORT,DB_DATABASE,DB_USERNAME,DB_PASSWORD,SQLITE_LEGACY_PATH php artisan erp:sqlite-to-mysql --from='$SQLITE' --to=mysql 2>&1 | tee '$WORK/sqlite-to-mysql-$STAMP.log'"
    run_sh "grep -q '^Perubahan nilai: 0' '$WORK/sqlite-to-mysql-$STAMP.log' || { echo 'ADA perubahan nilai — baca log di atas dan putuskan sebelum melanjutkan'; exit 1; }"

    mark_done
    say "Langkah berikutnya: $0 verifikasi"
}

step_verifikasi() {
    banner "erp:migration-verify — wajib 0 selisih"
    require_root; require_done salin; require_not_done
    load_cred

    akan "erp:migration-verify --from=sqlite_legacy --to=mysql, laporan ke $WORK/migration-verify-$STAMP.md; exit 1 = berhenti di sini"

    run_sh "cd '$SITE' && export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE='$DB_NAME' DB_USERNAME DB_PASSWORD SQLITE_LEGACY_PATH='$SQLITE' && sudo -u www-data --preserve-env=DB_CONNECTION,DB_HOST,DB_PORT,DB_DATABASE,DB_USERNAME,DB_PASSWORD,SQLITE_LEGACY_PATH php artisan erp:migration-verify --from=sqlite_legacy --to=mysql --report='$SITE/storage/app/migration-verify-cutover-$STAMP.md' 2>&1 | tee '$WORK/migration-verify-$STAMP.log'"
    run cp -p "$SITE/storage/app/migration-verify-cutover-$STAMP.md" "$WORK/migration-verify-$STAMP.md"

    mark_done
    say "Simpan $WORK/migration-verify-$STAMP.md ke docs/bukti-uji/. Langkah berikutnya: $0 env"
}

step_env() {
    banner ".env → MySQL; migrate --pretend wajib kosong; config:cache"
    require_root; require_done verifikasi; require_not_done
    load_cred

    akan "menyalin $ENV_FILE → $WORK/env.sqlite-$STAMP (rollback membacanya)"
    akan "menulis DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=$DB_NAME DB_USERNAME DB_PASSWORD SQLITE_LEGACY_PATH=$SQLITE (mode 640 root:www-data)"
    akan "config:clear; migrate --pretend wajib 'Nothing to migrate'; config:cache route:cache event:cache; reload $PHP_FPM"
    akan "erp:migration-verify lewat .env yang baru (bukti .env benar) — wajib identik lagi"

    run cp -p "$ENV_FILE" "$WORK/env.sqlite-$STAMP"
    run_sh "ln -sfn 'env.sqlite-$STAMP' '$WORK/env.sqlite-latest'"

    if [ "$DRY" = 1 ]; then
        say "    (python3: tulis ulang kunci DB_* dan SQLITE_LEGACY_PATH di $ENV_FILE)"
    else
        DB_NAME="$DB_NAME" SQLITE="$SQLITE" ENV_FILE="$ENV_FILE" python3 - <<'PY'
import os, re
path = os.environ["ENV_FILE"]
want = {
    "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": "3306",
    "DB_DATABASE": os.environ["DB_NAME"], "DB_USERNAME": os.environ["DB_USERNAME"],
    "DB_PASSWORD": os.environ["DB_PASSWORD"], "SQLITE_LEGACY_PATH": os.environ["SQLITE"],
}
lines = open(path).read().splitlines()
seen = set()
out = []
for line in lines:
    m = re.match(r'^\s*#?\s*(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|SQLITE_LEGACY_PATH)=', line)
    if m and m.group(1) not in seen:
        key = m.group(1)
        out.append(f'{key}="{want[key]}"' if key == "DB_PASSWORD" else f"{key}={want[key]}")
        seen.add(key)
    elif m:
        continue  # a second/commented duplicate: drop it
    else:
        out.append(line)
for key, value in want.items():
    if key not in seen:
        out.append(f'{key}="{value}"' if key == "DB_PASSWORD" else f"{key}={value}")
tmp = path + ".cutover.tmp"
with open(tmp, "w") as fh:
    fh.write("\n".join(out) + "\n")
os.chmod(tmp, 0o640)
os.replace(tmp, path)
print("    .env ditulis ulang (kunci DB_* dan SQLITE_LEGACY_PATH)")
PY
        chown root:www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"
    fi

    artisan config:clear
    run_sh "cd '$SITE' && sudo -u www-data php artisan migrate --pretend 2>&1 | tee '$WORK/migrate-pretend-$STAMP.log' | grep -qi 'nothing to migrate'"
    artisan config:cache
    artisan route:cache
    artisan event:cache
    run systemctl reload "$PHP_FPM"
    run_sh "cd '$SITE' && sudo -u www-data php artisan erp:migration-verify --from=sqlite_legacy --to=mysql --report='$SITE/storage/app/migration-verify-env-$STAMP.md' 2>&1 | tail -2"

    mark_done
    say "Langkah berikutnya: $0 smoke   (export SMOKE_EMAIL SMOKE_PASSWORD dulu)"
}

step_smoke() {
    banner "smoke lewat nginx/php-fpm dengan rahasia bypass: masuk, daftar PO, laporan harian ganda → 422, permission-check"
    require_root; require_done env; require_not_done
    load_cred
    : "${SMOKE_EMAIL:?SMOKE_EMAIL wajib}" "${SMOKE_PASSWORD:?SMOKE_PASSWORD wajib}"

    local jar="$WORK/.smoke-cookies" secret
    secret="$( [ -r "$WORK/down-secret" ] && cat "$WORK/down-secret" || echo '<rahasia>' )"

    akan "GET $BASE_URL/<rahasia> → cookie bypass pemeliharaan; GET /up → 200"
    akan "POST /api/iam/auth/login ($SMOKE_EMAIL) → 200 + token"
    akan "GET /api/procurement/purchase-orders → 200 dengan 'data'"
    akan "POST /api/projects/daily-reports untuk (proyek, tanggal) yang SUDAH ADA → 422 dan jumlah baris tidak bertambah"
    akan "erp:permission-check → exit 0"

    run_sh_masked "curl -sS -o /dev/null -c '$jar' -w '    bypass: %{http_code}\\n' '$BASE_URL/$secret'" "curl -sS -o /dev/null -c '$jar' -w '    bypass: %{http_code}\\n' '$BASE_URL/<rahasia>'"
    run_sh "curl -sS -o /dev/null -b '$jar' -w '%{http_code}' '$BASE_URL/up' | grep -qx 200"

    if [ "$DRY" != 1 ]; then
        local token
        token="$(curl -sS -b "$jar" -H 'Accept: application/json' -H 'Content-Type: application/json' \
            -d "$(SMOKE_EMAIL="$SMOKE_EMAIL" SMOKE_PASSWORD="$SMOKE_PASSWORD" python3 -c 'import json,os;print(json.dumps({"email":os.environ["SMOKE_EMAIL"],"password":os.environ["SMOKE_PASSWORD"]}))')" \
            "$BASE_URL/api/iam/auth/login" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["data"]["token"])')" \
            || fail "login smoke gagal"
        say "    login: ok"

        local po
        po="$(curl -sS -b "$jar" -H "Authorization: Bearer $token" -H 'Accept: application/json' -w '\n%{http_code}' "$BASE_URL/api/procurement/purchase-orders")"
        [ "$(tail -1 <<<"$po")" = 200 ] || fail "daftar PO: HTTP $(tail -1 <<<"$po")"
        head -n -1 <<<"$po" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert "data" in d; print("    daftar PO: 200, %d baris pada halaman ini" % len(d["data"]))'

        local row project_id report_date before after code body
        row="$(mysql_app -e "SELECT project_id, report_date FROM prj_daily_reports WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1" "$DB_NAME")"
        if [ -z "$row" ]; then
            say "    laporan harian ganda: TIDAK TERUJI — tidak ada laporan harian hidup di $DB_NAME"
        else
            project_id="${row%%$'\t'*}"; report_date="${row##*$'\t'}"
            before="$(mysql_app -e "SELECT COUNT(*) FROM prj_daily_reports" "$DB_NAME")"
            body="$(curl -sS -b "$jar" -H "Authorization: Bearer $token" -H 'Accept: application/json' -H 'Content-Type: application/json' \
                -d "{\"project_id\": $project_id, \"report_date\": \"$report_date\"}" -w '\n%{http_code}' "$BASE_URL/api/projects/daily-reports")"
            code="$(tail -1 <<<"$body")"
            after="$(mysql_app -e "SELECT COUNT(*) FROM prj_daily_reports" "$DB_NAME")"
            [ "$code" = 422 ] || fail "laporan harian ganda: HTTP $code, bukan 422 — $(head -n -1 <<<"$body" | head -c 300)"
            head -n -1 <<<"$body" | grep -q 'report_date' || fail "422 tetapi bukan karena report_date: $(head -n -1 <<<"$body" | head -c 300)"
            [ "$before" = "$after" ] || fail "jumlah laporan harian berubah $before → $after"
            say "    laporan harian ganda (proyek $project_id, $report_date): 422 karena report_date, baris tetap $after"
        fi
    fi

    artisan erp:permission-check
    run rm -f "$jar"

    mark_done
    say "Langkah berikutnya: $0 up"
}

step_up() {
    banner "php artisan up, cron kembali, cadangan beralih ke mysqldump"
    require_root; require_done smoke; require_not_done
    load_cred

    akan "php artisan up; $CRON_PARKED → $CRON_FILE; systemctl start ${ERP_UNITS[*]} bila enabled"
    akan "memasang $MYSQL_CNF (mode 600) dan menambah BACKUP_ENGINE=mysql, MYSQL_DEFAULTS_FILE, MYSQL_DATABASE=$DB_NAME ke $BACKUP_CONF"
    akan "backup-erp1.sh --local-only lalu --restore-drill --source=local (mysqldump → erp_restore_check → verify) sebagai bukti"
    akan "menghapus salinan kredensial sementara $WORK/.cred.cnf dan mencetak jendela rollback 24 jam"

    artisan up
    # Guarded so a re-run after a failed drill does not die here (verifier 5 Sep 2026).
    [ -f "$CRON_PARKED" ] && run mv "$CRON_PARKED" "$CRON_FILE"
    units_start

    if [ "$DRY" != 1 ]; then
        [ -f "$MYSQL_CNF" ] || ( umask 077; printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\nport=3306\n' "$DB_USERNAME" "$DB_PASSWORD" > "$MYSQL_CNF" )
        chmod 600 "$MYSQL_CNF"
        grep -q '^BACKUP_ENGINE=' "$BACKUP_CONF" || printf 'BACKUP_ENGINE=mysql\nMYSQL_DEFAULTS_FILE=%s\nMYSQL_DATABASE=%s\n' "$MYSQL_CNF" "$DB_NAME" >> "$BACKUP_CONF"
    fi

    run_sh "bash '$SITE/deploy/backup-erp1.sh' --local-only"
    run_sh "bash '$SITE/deploy/backup-erp1.sh' --restore-drill --source=local | tee '$WORK/drill-$STAMP.log' | grep -q 'RESTORE DRILL PASSED'"
    run rm -f "$WORK/.cred.cnf"

    mark_done
    say ""
    say "CUT-OVER SELESAI $(date -Is). Jendela rollback berakhir $(date -Is -d '+24 hours')."
    say "  - $SQLITE tidak disentuh (sha256 di $WORK/sqlite-frozen.sha256); arsip GPG di $WORK, simpan 30 hari."
    say "  - Hapus berkas kredensial coretan (\$CUTOVER_CRED) — .env dan $MYSQL_CNF kini memegangnya (§10.3)."
    say "  - Ukur T0.7 (DEPLOYMENT.md §10.10) dan simpan ke docs/bukti-uji/."
}

step_rollback() {
    banner "ROLLBACK: .env kembali ke SQLite — berkas SQLite tidak pernah disentuh"
    require_root; require_done env

    local upat
    upat="$( { grep '^up ' "$STATE" 2>/dev/null || true; } | tail -1 | cut -d' ' -f2)"

    akan "php artisan down; parkir cron; salin $WORK/env.sqlite-latest → $ENV_FILE; config:clear/cache route/event cache; reload $PHP_FPM"
    akan "memastikan sha256 $SQLITE = yang dibekukan (bila berbeda: berkas berubah sesudah down — hentikan dan periksa)"
    akan "mematikan BACKUP_ENGINE=mysql di $BACKUP_CONF; php artisan up; cron kembali"
    if [ -n "$upat" ]; then
        say "    up tercatat $upat — yang ditulis pengguna ke MySQL sejak itu TIDAK ikut kembali."
        if [ "$DRY" != 1 ] && [ "$(( $(date +%s) - $(date -d "$upat" +%s) ))" -gt 86400 ] && [ "${ROLLBACK_FORCE:-0}" != 1 ]; then
            fail "jendela 24 jam sudah lewat sejak $upat; rollback sekarang membuang lebih dari sehari pekerjaan. Bila tetap, ROLLBACK_FORCE=1."
        fi
    fi

    artisan down --retry=60
    units_stop
    [ -f "$CRON_FILE" ] && run mv "$CRON_FILE" "$CRON_PARKED"
    run_sh "sha256sum -c '$WORK/sqlite-frozen.sha256'"
    run_sh "cp -p '$WORK/env.sqlite-latest' '$ENV_FILE' && chown root:www-data '$ENV_FILE' && chmod 640 '$ENV_FILE'"
    run_sh "grep -q '^DB_CONNECTION=sqlite' '$ENV_FILE'"
    artisan config:clear
    artisan config:cache
    artisan route:cache
    artisan event:cache
    run systemctl reload "$PHP_FPM"
    run_sh "cd '$SITE' && sudo -u www-data php artisan migrate:status | tail -3"
    run_sh "sed -i 's/^BACKUP_ENGINE=mysql/#BACKUP_ENGINE=mysql/' '$BACKUP_CONF'"
    artisan up
    [ -f "$CRON_PARKED" ] && run mv "$CRON_PARKED" "$CRON_FILE"
    units_start

    [ "$DRY" = 1 ] || printf 'rollback %s\n' "$(date -Is)" >> "$STATE"
    say "Kembali ke SQLite. Basis data MySQL $DB_NAME dibiarkan apa adanya untuk diperiksa; jangan dihapus sebelum penyebabnya dipahami."
}

step_status() {
    say "state: $STATE"
    [ -f "$STATE" ] && cat "$STATE" || say "    (belum ada langkah yang tercatat)"
    say "berikutnya: $(for s in "${STEPS[@]}"; do is_done "$s" || { echo "$s"; break; }; done)"
}

case "$STEP" in
    pra) step_pra ;;
    basisdata) step_basisdata ;;
    down) step_down ;;
    snapshot) step_snapshot ;;
    salin) step_salin ;;
    verifikasi) step_verifikasi ;;
    env) step_env ;;
    smoke) step_smoke ;;
    up) step_up ;;
    rollback) step_rollback ;;
    status) step_status ;;
esac
