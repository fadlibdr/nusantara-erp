# Antrean & penjadwal sebagai layanan systemd (Fase 0 / P-0b)

Dua unit menggantikan baris `schedule:run` di `/etc/cron.d/erp1` dan mengisi
lubang yang selama ini terbuka: `QUEUE_CONNECTION=database` tanpa satu pun
pekerja yang mengambil job dari tabel `jobs`.

| Unit | Perintah | Log |
|---|---|---|
| `erp1-queue.service` | `php artisan queue:work database --tries=5 --backoff=60 --timeout=60 --max-time=3600 --sleep=3 --queue=default` | `/var/log/erp1/queue.log` |
| `erp1-scheduler.service` | `php artisan schedule:work` | `/var/log/erp1/scheduler.log` |

Keduanya berjalan sebagai `www-data` dari `/var/www/erp1.pi2.co.id`,
`Restart=always`, `RestartSec=5`. Pengawas terpisah (`deploy/cron.d/erp1-watchdog`,
root, tiap 15 menit) memulai ulang penjadwal dan menaikkan alarm dalam aplikasi
bila detak jantung `erp:heartbeat` (tiap 5 menit) lebih tua dari 20 menit —
lihat T0b.2 di bawah.

Semua yang ada di berkas ini dijalankan **oleh orang di server** (root), bukan
oleh deploy: `sync-erp1.sh` hanya `restart` unit yang sudah `enabled`.

## Pasang (sekali, urutannya penting)

```bash
SITE=/var/www/erp1.pi2.co.id

# 1. Direktori log milik www-data (unit menulis dengan StandardOutput=append:).
install -d -o www-data -g www-data -m 0755 /var/log/erp1

# 2. Unit + logrotate.
cp $SITE/deploy/systemd/erp1-queue.service     /etc/systemd/system/erp1-queue.service
cp $SITE/deploy/systemd/erp1-scheduler.service /etc/systemd/system/erp1-scheduler.service
cp $SITE/deploy/logrotate/erp1                 /etc/logrotate.d/erp1
systemctl daemon-reload

# 3. HAPUS baris schedule:run dari /etc/cron.d/erp1 SEBELUM unit dinyalakan —
#    dua penjadwal = tiap perintah jalan dua kali (akrual ganda, PM ganda), dan
#    tidak satu pun perintah terjadwal memakai withoutOverlapping. Urutan ini
#    berharga paling lama satu menit tanpa penjadwal; urutan sebaliknya
#    berharga satu jalan ganda. Baris cadangan (root) TETAP.
#    Lakukan langkah 3–4 di LUAR 05:00–09:00 WIB (jendela perintah harian:
#    ast:accrue-plant, svc:generate-pm, fin:ensure-calendar, tiga pengawas) —
#    satu menit tanpa penjadwal tepat pada menit dailyAt() berarti perintah
#    itu terlewat hari ini.
#    Yang dihapus adalah barisnya BESERTA tiga baris komentarnya ("Without
#    this, svc:generate-pm never runs…") — komentar yang ditinggalkan sendirian
#    adalah undangan untuk menambahkan barisnya kembali dan mendapat dua
#    penjadwal. Diuji pada salinan berkas produksi 5 Sep 2026: hanya empat
#    baris itu yang berubah, baris cadangan utuh.
sed -i "/^# Laravel's scheduler\. Without this/,/artisan schedule:run/c\\# Penjadwal: unit systemd erp1-scheduler (deploy/systemd/README.md), bukan cron." /etc/cron.d/erp1
grep -c 'schedule:run' /etc/cron.d/erp1   # harus 0

# 4. Nyalakan dan periksa.
systemctl enable --now erp1-queue erp1-scheduler
systemctl --no-pager status erp1-queue erp1-scheduler
tail -n 5 /var/log/erp1/queue.log /var/log/erp1/scheduler.log

# 5. Pengawas (root): heartbeat > 20 menit -> restart erp1-scheduler + alarm dalam aplikasi.
#    TUNGGU detak pertama dulu. erp:heartbeat baru ditulis pada menit kelipatan
#    5 berikutnya (paling lama ~5 menit setelah langkah 4); pengawas yang
#    dijalankan sebelum itu membaca `?` = macet, memulai ulang unit yang baru
#    saja dinyalakan, dan mengirim alarm "Penjadwal tidak berjalan" palsu ke
#    semua pemegang core.update — alarm yang tidak dicabut siapa pun saat
#    detaknya datang (gladi 5 Sep 2026, drill.log baris 2–7). Jangan menulis
#    detaknya dengan tangan: itu memalsukan 20 menit "sehat". Berkas cron-nya
#    dipasang SETELAH detak ada, supaya tick :00/:15/:30/:45 tidak mendahului.
bash -n $SITE/deploy/erp1-watchdog.sh
for i in $(seq 1 24); do   # paling lama 6 menit
  age=$(cd $SITE && sudo -u www-data env HOME=/tmp php artisan erp:heartbeat --age 2>/dev/null | tail -n 1)
  [ "$age" != '?' ] && [ -n "$age" ] && break
  sleep 15
done
echo "detak pertama: umur ${age:-?} detik"     # harus angka; masih `?` = periksa systemctl status erp1-scheduler
bash $SITE/deploy/erp1-watchdog.sh              # harus "sehat: detak terakhir N detik lalu", keluar 0
install -m 0644 $SITE/deploy/cron.d/erp1-watchdog /etc/cron.d/erp1-watchdog
```

Sesudahnya `GET /api/core/health` (pemegang `core.view`) menjawab
`scheduler_status: "ok"` dan `scheduler_heartbeat_age_s` < 300; dasbor pemegang
`core.update` tidak menampilkan spanduk "Penjadwal tidak berjalan".

## Setelah deploy kode

`deploy/sync-erp1.sh` menjalankan `systemctl restart erp1-queue erp1-scheduler`
bila keduanya `is-enabled`. Pekerja antrean memegang kode yang dimuat saat ia
mulai — tanpa restart, job baru dijalankan oleh kode lama sampai `--max-time`
(1 jam) habis. Deploy tangan: `systemctl restart erp1-queue erp1-scheduler`.

## Cut-over MySQL (`deploy/cutover-erp1.sh`)

`down` memarkir pengawas (`/etc/cron.d/erp1-watchdog` →
`erp1-watchdog.cutover-parked`) lalu menghentikan kedua unit (bila `enabled`)
sebelum membekukan SQLite — pekerja antrean yang masih menulis ke basis data
yang sedang dipindahkan adalah kehilangan data, bukan sekadar risiko, dan
pengawas yang dibiarkan hidup akan memulai ulang penjadwal 20 menit kemudian
dan menulis alarm ke berkas yang dibekukan. `up` dan `rollback` menyalakan
unit dan mengembalikan pengawas. Cron `/etc/cron.d/erp1` tetap diparkir
seperti sebelumnya (baris cadangan). Lapisan kedua: `erp1-watchdog.sh` diam
(keluar 0) selama `storage/framework/down` ada — `php artisan down` tangan
pun aman.

## Membaca yang salah

| Gejala | Lihat |
|---|---|
| Spanduk "Penjadwal tidak berjalan sejak …" di dasbor | `systemctl status erp1-scheduler`; `journalctl -u erp1-scheduler -n 50`; `/var/log/erp1/scheduler.log`; `/var/log/erp1/watchdog.log` |
| `GET api/core/health` → `queue_oldest_pending_age_s` terus naik | `systemctl status erp1-queue`; `/var/log/erp1/queue.log` — pekerja mati atau job macet |
| `failed_jobs_count` > 0 | Sistem › Antrean Gagal di aplikasi (kirim ulang / hapus), atau `php artisan queue:failed` |
| Pengiriman e-mail `failed` | Sistem › Pengiriman Notifikasi — pesan galat penyedia tersimpan di kolom `error`; tombol Kirim ulang |
| Unit tidak mau start: "Writing to directory /var/www/.config is not allowed" | `Environment=HOME=/tmp` hilang dari unit |

## Kembali ke cron (rollback unit)

```bash
systemctl disable --now erp1-queue erp1-scheduler
rm /etc/cron.d/erp1-watchdog
# kembalikan baris (dan komentarnya) ke /etc/cron.d/erp1:
sed -i '/^# Penjadwal: unit systemd erp1-scheduler/d' /etc/cron.d/erp1
printf '%s\n' \
  "# Laravel's scheduler. Without this, svc:generate-pm never runs and preventive" \
  "# maintenance visits go past due silently — and erp:backup-watch (08:00 WIB)" \
  "# never raises its backup alarms." \
  '* * * * * www-data cd /var/www/erp1.pi2.co.id && php artisan schedule:run >> /var/log/erp1-schedule.log 2>&1' \
  >> /etc/cron.d/erp1
grep -c 'schedule:run' /etc/cron.d/erp1   # harus 1
```

Tanpa pekerja antrean, pengiriman e-mail (T0b.3) tinggal `queued` di tabel
`core_notification_deliveries` dan terlihat begitu di Sistem › Pengiriman
Notifikasi — tidak hilang, tidak pura-pura terkirim.
