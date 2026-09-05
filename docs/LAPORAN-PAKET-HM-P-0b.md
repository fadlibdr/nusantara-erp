# Laporan Paket P-0b (ROADMAP-HASHMICRO Fase 0) — Antrean & penjadwal sebagai layanan

Branch: `feat/phase0-queue` (dari main `cfb3f85`; 27 commit kode + dokumen) · 5 September 2026 (Sabtu)

> Status jujur: kode T0b.1–T0b.4 dibangun, diverifikasi adversarial dua lensa, 16 temuan
> diperbaiki dan diverifikasi ulang, **merged ke main (`abb9d42`) dan ter-deploy ke erp1 5 Sep
> 12:50 UTC** (migrasi `000194` tercatat, tabel ada). Pemasangan unit: langkah 1–2 selesai;
> **langkah 3–5 (ganti cron → unit, pengawas) ditolak sandbox untuk agen — pemilik menjalankannya**
> per `deploy/systemd/README.md`. Produksi masih SQLite (cut-over P-0 menunggu pemilik).

## Yang ditutup (ROADMAP-HASHMICRO Fase 0 / P-0b → status)

| Tugas | Status | Bukti (commit / berkas / angka terukur) |
|---|---|---|
| T0b.1 unit `erp1-queue` (`queue:work database --tries=5 --backoff=60 --max-time=3600 --sleep=3 --timeout=60`) + `erp1-scheduler` (`schedule:work`), log `/var/log/erp1/*.log`, logrotate, README pasang/rollback, `sync-erp1.sh` restart unit bila `is-enabled`, cut-over stop/start unit + parkir pengawas | ✅ kode | `7808449`, `557fe63`→`9116ff7`, `c19b480`, `286513e`, `39425a6`, `44e84ee`, `55a4c8d`, `1d3d684`, `5a025b0`, `be53b81`; `systemd-analyze verify` bersih; `DeployUnitsTest` (9 uji) memaku flag unit, urutan README, sed cron, dry-run cut-over |
| T0b.2 `erp:heartbeat` tiap 5 menit → `core_settings.scheduler.heartbeat_at` (kunci internal, ditolak formulir Pengaturan); `GET api/core/health` (`core.view`): `scheduler_heartbeat_age_s`, `scheduler_status` ok/stale/unknown, `queue_oldest_pending_age_s`, `failed_jobs_count`, `queued_deliveries_older_than_1h` — null bila tak diketahui; `erp:watchdog-alarm`; `deploy/erp1-watchdog.sh` + `deploy/cron.d/erp1-watchdog` (root, */15); spanduk dasbor "Penjadwal tidak berjalan sejak …" | ✅ kode | `316043f`, `4603535`, `d597841`, `a695e08`; `SchedulerHeartbeatTest`: cadence `*/5 * * * *` dipaku, tabel hilang → null bukan 0, kunci internal ditolak `PUT settings` |
| T0b.3 kotak keluar `core_notification_deliveries` (channel, recipient, `queued|sent|failed|skipped`, attempts, provider_id, error, sent_at, next_attempt_at), `DeliveryChannel` + `MailChannel`, job `DeliverNotification` (`ShouldQueueAfterCommit`, tries 5, backoff 60/300/900/3600 dari hitungan pekerja, `failed()` → `failed` + pesan penyedia / kehabisan waktu), `NotificationService`: SEMUA baris in-app dulu, lalu kotak keluar per penerima di balik guard-nya sendiri; `GET/POST api/core/notification-deliveries[/{id}/retry]` (`core.update`); layar Sistem › Pengiriman Notifikasi | ✅ kode | `d39e4b2`, `9bb5582`, `995b853`, `947a460`, `37d3d18`, `2f8f736`, `a86dced`; `NotificationDeliveryTest` (21 uji) + `ApprovalNotificationTest` (kegagalan pengiriman tidak membatalkan persetujuan — tetap hijau) |
| T0b.4 `GET api/core/queue/failed[/{id}]`, `POST …/retry` (`core.update`, lewat `queue:retry`), `DELETE` (`core.delete`); layar Sistem › Antrean Gagal; baris dasbor "Antrean: N job gagal · N pengiriman antre > 1 jam"; retry job `DeliverNotification` DITOLAK 422 dengan penunjuk ke layar pengiriman | ✅ kode | `ef07fab`, `644346e`, `4ecd611`; `QueueFailedJobsTest` (8 uji, pekerja sungguhan `queue:work --once`) |
| Pemasangan di erp1 (README langkah 1–5) | 🟡 **1–2 selesai, 3–5 menunggu pemilik** | 5 Sep 13:02 UTC: `/var/log/erp1` (www-data 0755), unit + `/etc/logrotate.d/erp1` terpasang (`systemd-analyze verify` bersih, `logrotate -d /etc/logrotate.conf` keluar 0 tanpa error); langkah 3–4 (hapus baris cron, `enable --now`) DITOLAK sandbox agen — cron `schedule:run` masih berjalan, unit `disabled`, tidak ada dua penjadwal |
| `core/health` menjawab tiga angka di produksi | ⏳ menunggu pemasangan | — |

## Verifikasi adversarial (dua lensa baca-saja → perbaikan → verifikasi ulang)

- **Lensa kebenaran/kejujuran**: 10 temuan (2 BREAKS, 3 INCOMPLETE, 2 COSMETIC, 3 DESIGN), 18 klaim
  terkonfirmasi. **Lensa operasi/pemasangan**: 9 temuan (2 BREAKS, 3 INCOMPLETE, 3 COSMETIC, 1 DESIGN),
  12 klaim terkonfirmasi. Yang paling penting:
  - `Antrean Gagal › Kirim ulang` pada job `DeliverNotification` adalah no-op yang melapor sukses
    (baris sudah `failed`, `handle()` melewatinya, catatan gagal terhapus) → layar menolak 422 dan
    menunjuk ke Sistem › Pengiriman Notifikasi; `queue:retry` di shell tetap no-op **tetapi menulis
    satu peringatan** (keputusan pemilik #16, `4ecd611`).
  - Kirim ulang baris `skipped: tanpa alamat` buntu selamanya (alamat beku di baris) → alamat dibaca
    ulang dari pengguna saat retry (`2f8f736`).
  - "In-app selalu" mundur: satu tulisan kotak keluar yang gagal menghilangkan baris in-app penerima
    berikutnya → dua fase dipulihkan (`995b853`).
  - Backoff diindeks dari `attempts` baris (kumulatif) → `next_attempt_at` null setelah Kirim ulang;
    kini dari `$this->attempts()` pekerja (`947a460`). SMTP bisu = pekerja dibunuh, `attempts` tetap
    0 → `smtp.timeout` 30 s < `--timeout=60` < `retry_after` 90, attempts disimpan sebelum mengirim
    (`37d3d18`).
  - Cut-over tidak memarkir cron pengawas: 20 menit setelah `down` pengawas root memulai ulang
    penjadwal dan menulis alarm ke SQLite beku → gerbang sha256 gagal (`286513e`); pengawas baru
    dikembalikan setelah detak segar (`be53b81`).
  - Urutan pasang menjalankan dua penjadwal paralel sampai baris cron dihapus → baris cron dihapus
    dulu (`39425a6`); pengawas dijalankan sebelum detak pertama → alarm palsu (`4603535`);
    `su www-data` di logrotate tidak bisa merotasi log milik root — diukur, dibalik (`9116ff7`).
- Verifikasi ulang: 13/14 temuan FIXED; **ops-6 NOT_FIXED** (unit belum pernah dijalankan di
  systemd sungguhan — pemasangan itu sendiri yang membuktikannya). Tiga temuan susulan (bukti
  logrotate README, pengawas setelah `up`/`rollback`, `queue:retry` shell) diperbaiki
  (`5a025b0`, `be53b81`, `4ecd611`) dan diverifikasi ulang FIXED; dua koreksi dokumen terakhir
  (`d0850c5`: klaim `logrotate -d` menangkap blok `su www-data` ditarik — artefak direktori
  coretan; peringatan `queue:retry` ada di `storage/logs/laravel-<tanggal>.log`, bukan
  `/var/log/erp1/queue.log`).
- **Insiden produksi selama verifikasi (ditutup):** pengukuran logrotate re-verifier memakai
  `deploy/logrotate/erp1` yang blok keduanya (log lama `erp1-backup.log`/`erp1-schedule.log`)
  tidak ditulis ulang → `-f` merotasi paksa kedua log sungguhan pada 11:30:58 UTC;
  `www-data` tidak bisa membuat ulang `erp1-schedule.log` di `/var/log` (root:syslog 0775),
  cron `schedule:run` gagal pada pengalihan keluaran selama 11:31–11:41 UTC (10 tick; tidak ada
  perintah terjadwal yang jatuh tempo — semua 05:30–08:45 WIB). Orkestrator membuat ulang kedua
  berkas dengan pemilik aslinya pukul 11:41 UTC; isi lama utuh di `.1.gz`; tick 11:42 tercatat.
  Pelajaran ditulis di README dan menjadi aturan keras agen: replika logrotate harus menulis
  ulang SEMUA jalur.

## Asumsi yang dipakai dan yang perlu dikonfirmasi (ledger §5 ROADMAP)

- **#16 (baru)** `queue:retry` shell atas job pengiriman: no-op jujur (hari ini) vs menerima baris
  `failed` — rekomendasi tetap hari ini; dua jalur retry yang saling menimpa tidak direkomendasikan.
- Keputusan desain yang dicatat verifier, belum diputuskan (perilaku hari ini dipertahankan):
  Kirim ulang diizinkan pada baris `queued` (baris tidak tahu apakah job-nya masih ada) → pengiriman
  bersifat *at-least-once*; spanduk dasbor tampil juga pada `unknown`/permintaan gagal (bukan hanya
  `stale`) dan waktu basi ditampilkan dalam zona waktu peramban; dengan e-mail mati (bawaan erp1 hari
  ini) setiap notifikasi menulis baris `skipped` permanen — pertumbuhan tabel; `systemctl
  stop/restart erp1-scheduler` membunuh perintah terjadwal yang sedang berjalan (`KillMode`
  control-group).

## Skema yang berubah — dan apakah migrasi aman di MySQL dengan data lama

Satu migrasi baru `Modules/Core/Database/Migrations/2026_09_05_000194_create_core_notification_deliveries_table.php`
(slot Core 000194): tabel baru, tanpa penulisan data lama — aman di kedua driver. FK
`notification_id` → `core_notifications` (di MySQL nyata: `ApprovalNotificationTest` yang
menjatuhkan `core_notifications` untuk mensimulasikan gangguan harus menjatuhkan tabel anak
dulu — galat 3730 di suite MySQL penuh, `a86dced`); indeks `status+next_attempt_at`; indeks
`notification_id` hanya di SQLite (MySQL sudah mengindeks FK-nya, `9bb5582`). Kunci
`scheduler.heartbeat_at` ditulis ke `core_settings` yang sudah ada.

## Uji

- baru: `DeployUnitsTest` (9), `SchedulerHeartbeatTest`, `NotificationDeliveryTest` (21),
  `QueueFailedJobsTest` (8), `tests/Support/AlwaysFailingJob`; diubah: `ApprovalNotificationTest`
  (urutan DROP), `PayrollPostingTest` (kode proyek berurutan — tabrakan 1/900 di run MySQL P-0).
- per-direktori saat kerja: `tests/Feature/Core` 688 uji / 4.193 asersi hijau di `4ecd611`;
  `tests/Feature/Iam` 57/457.
- suite penuh SQLite di `9bb5582` (sebelum perbaikan verifikasi): 3.830 uji / 18.141 asersi hijau;
  suite penuh MySQL di `9bb5582`: 3.830 uji, 1 galat (FK DROP di atas, diperbaiki `a86dced`).
- **suite penuh di commit rilis `d0850c5`** (worktree terpisah): SQLite **3.848 uji / 18.308 asersi, 11 dilewati, hijau** (10 mnt 02 dtk); MySQL 8.0.46 **3.848 uji / 18.328 asersi, 4 dilewati, hijau** (24 mnt 03 dtk).

## Smoke test (endpoint baru)

```
GET  api/core/health                                  core.view    → 200 {scheduler_status:"unknown", scheduler_heartbeat_age_s:null, …} sebelum detak pertama
GET  api/core/notification-deliveries?status=failed   core.update  → 200 daftar (channel, recipient, status, attempts, error, next_attempt_at)
POST api/core/notification-deliveries/{id}/retry      core.update  → 200 baris → queued + job; 422 "Penerima tidak punya alamat e-mail; …" / "E-mail dinonaktifkan di Pengaturan"
GET  api/core/queue/failed                            core.update  → 200 (uuid, queue, baris pertama pengecualian, failed_at)
POST api/core/queue/failed/{id}/retry                 core.update  → 200; 422 "Job ini adalah pengiriman notifikasi #N … Kirim ulang dari Sistem › Pengiriman Notifikasi"
DELETE api/core/queue/failed/{id}                     core.delete  → 200
```

Kalimat 422 dipaku uji (`NotificationDeliveryTest`, `QueueFailedJobsTest`). Layar SPA:
`core/notification-deliveries` dan `core/queue/failed` di `schema.js` + NAV Sistem
(`NavRouteRegistryTest` hijau).

## Dokumentasi yang diperbarui

`deploy/systemd/README.md` (pasang, rollback, "Membaca yang salah"), `docs/DEPLOYMENT.md` §6
(cron → systemd, antrean, log) dan §10.9 (cut-over stop/start unit + parkir pengawas),
`docs/ROADMAP-HASHMICRO.md` §5 baris #16, PANDUAN §5.2 (pengawasan penjadwal).

## Yang sengaja tidak dikerjakan, dan mengapa

- **Pemasangan langkah 3–5 ditolak sandbox agen** (13:02 UTC, setelah langkah 1–2 berhasil).
  Pemilik menjalankan sebagai root di erp1, di luar 05:00–09:00 WIB, persis blok README
  "Pasang" langkah 3 (sed baris cron + `grep -c` = 0), 4 (`systemctl enable --now erp1-queue
  erp1-scheduler`, status, tail log), 5 (tunggu `erp:heartbeat --age` bukan `?`, jalankan pengawas
  sekali → "sehat", lalu `install -m 0644 $SITE/deploy/cron.d/erp1-watchdog /etc/cron.d/erp1-watchdog`).
  Sesudahnya `GET /api/core/health` (pemegang `core.view`) harus `scheduler_status: "ok"`.
  Salinan cron sebelum perubahan: buat dulu `cp -a /etc/cron.d/erp1 /root/erp1.cron.before-systemd`.
- **ops-6**: unit belum pernah hidup di systemd sungguhan (gladi memakai skrip di salinan coretan,
  bukan unit). Bukti pertamanya adalah `systemctl status` + `GET api/core/health` setelah pasang.
- Tidak ada Redis/Horizon; satu pekerja `database`; WhatsApp/push menunggu Fase 3 (kanal `email`
  saja hari ini, enum sudah menampung `whatsapp|webpush`).

## Deviasi baru yang ditemukan

- `NotificationService` pra-P-0b membungkus `Mail::to()` dalam guard yang menelan kegagalan — dari
  layar tidak ada bedanya terkirim, gagal, atau e-mail dimatikan (alasan T0b.3).
- Tidak satu pun jadwal memakai `withoutOverlapping`/`onOneServer`; `lockForUpdate` di
  `ast:accrue-plant` kosong di SQLite → dua penjadwal paralel = akrual ganda (alasan urutan pasang).
- Pengklasifikasi sandbox memblokir `queue:retry`-style dan pemasangan sistem untuk agen — pola
  yang sama dengan cut-over P-0.
