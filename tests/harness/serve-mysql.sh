#!/usr/bin/env bash
# Menyajikan aplikasi dari basis data MySQL coretan lewat server CLI PHP untuk
# harness burst (tests/harness/burst.py, Fase 0 T0.4) — bukan untuk produksi.
#
#   set -a; . <berkas-cred>; set +a          # DB_USERNAME, DB_PASSWORD
#   tests/harness/serve-mysql.sh erp_scratch 8004
#
# PHP_CLI_SERVER_WORKERS=8: delapan proses pekerja, kira-kira jumlah worker
# php-fpm di erp1 — jadi paralelisme basis data yang diuji adalah yang nyata.
# API_RATE_LIMIT dinaikkan supaya yang diukur adalah kunci basis data, bukan
# pembatas 120 permintaan/menit per pengguna (config/erp.php).
set -euo pipefail

DB="${1:?nama basis data MySQL, mis. erp_scratch}"
PORT="${2:-8004}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

: "${DB_USERNAME:?DB_USERNAME tidak ada di lingkungan}"
: "${DB_PASSWORD:?DB_PASSWORD tidak ada di lingkungan}"

case "$DB" in
    erp) echo "menolak menyajikan basis data produksi 'erp' lewat server coretan" >&2; exit 2 ;;
esac

cd "$ROOT/public"
exec env \
    APP_ENV=local \
    DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE="$DB" \
    API_RATE_LIMIT=1000000 \
    PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}" \
    php -S "127.0.0.1:${PORT}" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
