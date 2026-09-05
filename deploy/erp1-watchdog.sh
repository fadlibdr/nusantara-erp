#!/usr/bin/env bash
#
# Pengawas penjadwal erp1 (Fase 0 / P-0b, T0b.2). Dijalankan root oleh
# /etc/cron.d/erp1-watchdog tiap 15 menit; keluarannya diarahkan cron ke
# /var/log/erp1/watchdog.log.
#
# Satu pertanyaan: kapan erp:heartbeat terakhir menulis? Bila lebih tua dari
# ERP1_HEARTBEAT_MAX_AGE detik (bawaan 1200 = 20 menit, sama dengan
# config/erp.php scheduler.heartbeat_max_age_s) atau belum pernah ada (`?`):
#   1. systemctl restart erp1-scheduler   (bila unitnya enabled)
#   2. php artisan erp:watchdog-alarm     (alarm dalam aplikasi ke pemegang
#                                          core.update; menghitung ulang umurnya
#                                          sendiri, jadi tidak mempercayai skrip ini)
# dan keluar 1. Sehat = satu baris log + keluar 0: baris "sehat" itulah bukti
# bahwa pengawasnya sendiri masih berjalan.
#
# Variabel lingkungan (untuk gladi di checkout lain / port lain, BUKAN untuk produksi):
#   ERP1_SITE               direktori aplikasi  (bawaan /var/www/erp1.pi2.co.id)
#   ERP1_SCHEDULER_UNIT     nama unit systemd   (bawaan erp1-scheduler)
#   ERP1_RUN_AS             pengguna artisan    (bawaan www-data; sama dengan pengguna
#                           saat ini = tanpa sudo)
#   ERP1_HEARTBEAT_MAX_AGE  ambang detik        (bawaan 1200)
set -uo pipefail

SITE=${ERP1_SITE:-/var/www/erp1.pi2.co.id}
UNIT=${ERP1_SCHEDULER_UNIT:-erp1-scheduler}
RUN_AS=${ERP1_RUN_AS:-www-data}
MAX_AGE=${ERP1_HEARTBEAT_MAX_AGE:-1200}

log() { printf '%s watchdog: %s\n' "$(date -Is)" "$*"; }

artisan() {
    # HOME=/tmp: psysh/composer di bawah www-data menulis ke ~/.config dan
    # berhenti dengan "Writing to directory /var/www/.config is not allowed".
    if [ "$(id -un)" = "$RUN_AS" ]; then
        env HOME=/tmp php artisan "$@"
    else
        sudo -u "$RUN_AS" env HOME=/tmp php artisan "$@"
    fi
}

cd "$SITE" 2>/dev/null || { log "GAGAL: direktori $SITE tidak ada"; exit 2; }

# Baris terakhir keluaran = umur dalam detik, atau `?` bila belum pernah ada.
age="$(artisan erp:heartbeat --age 2>&1 | tail -n 1 | tr -d '[:space:]')"

stale=0
case "$age" in
    '?')        stale=1; reason='detak jantung belum pernah tercatat (?)' ;;
    ''|*[!0-9]*) stale=1; reason="keluaran erp:heartbeat --age tidak terbaca: '${age}' (basis data mati?)" ;;
    *) if [ "$age" -gt "$MAX_AGE" ]; then stale=1; reason="detak terakhir ${age} detik lalu > ambang ${MAX_AGE}"; fi ;;
esac

if [ "$stale" = 0 ]; then
    log "sehat: detak terakhir ${age} detik lalu"
    exit 0
fi

log "PENJADWAL MACET: $reason"

if systemctl is-enabled --quiet "$UNIT" 2>/dev/null; then
    if systemctl restart "$UNIT"; then
        log "systemctl restart $UNIT: ok"
    else
        log "systemctl restart $UNIT: GAGAL (exit $?) — periksa journalctl -u $UNIT"
    fi
else
    log "unit $UNIT tidak enabled — tidak ada yang dimulai ulang (masih cron? lihat deploy/systemd/README.md)"
fi

# Keluar 1 = alarm dinaikkan; 0 = ternyata sudah segar lagi. Keduanya dicatat.
artisan erp:watchdog-alarm 2>&1 | sed 's/^/    /'
exit 1
