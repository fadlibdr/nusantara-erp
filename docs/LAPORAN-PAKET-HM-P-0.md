# Laporan Paket P-0 (ROADMAP-HASHMICRO Fase 0) — MySQL & cadangan

Branch: `feat/phase0-mysql` (9 commit) → merge `cfb3f85` ke main · 5 September 2026 (Sabtu)

> Status jujur: kode T0.0–T0.6 **merged dan ter-deploy** ke erp1 (09:5x WIB). **Cut-over
> produksi SQLite → MySQL BELUM dijalankan** dan **T0.7 belum diukur** — lihat § "Yang
> sengaja tidak dikerjakan". Produksi hari ini masih SQLite; MySQL 8.0.46 aktif di erp1
> sebagai layanan, basis data `erp` produksi belum dibuat.

## Yang ditutup (ROADMAP-HASHMICRO Fase 0 / P-0 → status)

| Tugas | Status | Bukti (commit / berkas / angka terukur) |
|---|---|---|
| T0.0 MySQL 8 sebagai layanan systemd di erp1 + drop-in `deploy/mysql/erp1.cnf` | ✅ | `778520c`; `systemctl is-active mysql`; terikat 127.0.0.1; DEPLOYMENT.md §10.1–10.3 |
| T0.1 `erp:mysql-preflight` (desimal, JSON, SQL khusus SQLite; tak diketahui → `?`) | ✅ | `71a569f`, `41e581b`; salinan prod: 264 kolom desimal 0 lepas-skala, 17 kolom JSON 0 rusak, 5 situs SQL SQLite-only (dua migrasi indeks parsial) — `docs/bukti-uji/mysql-preflight-erp1-2026-09-05.json` |
| T0.2 dua indeks unik parsial → kolom generated `live_key` + UNIQUE (migrasi 000746 berpenjaga driver; 000721/000742 diberi cabang sqlite) | ✅ | `a5b8eda`; `migrate:fresh --seed` di MySQL 8.0.46 hijau, 256 langkah, 45 dtk; satu nama indeks 73 karakter diperpendek |
| T0.3 `phpunit.mysql.xml` + job CI `phpunit-mysql` (nightly/tag), `SqlitePragmaTest` dilewati di MySQL, `MysqlModeTest` | ✅ | `bb61895`; suite penuh di MySQL: 3.785 tes / 17.874 asersi / 3 dilewati, 34 mnt 41 dtk |
| T0.4 harness burst 20/40/80 paralel pada lima layanan berisiko | ✅ | `ee708aa`; 980 permintaan, 0×5xx, 0×503, 0 deadlock, nomor PR/JV/PM/BP kontigu, stok tak pernah negatif — `docs/bukti-uji/burst-mysql-2026-09-05.json`; **dua balapan MySQL-only** diperbaiki lewat URUTAN kunci (bukan retry) |
| T0.5 `erp:sqlite-to-mysql` + `erp:migration-verify` | ✅ | `43046af`; gladi salinan prod → `erp_dryrun`: 189 tabel / 1.240 baris dipindah; verifikasi 190 tabel / 1.468 baris / 264 kolom desimal identik, 0 pembulatan — `docs/bukti-uji/migration-verify-dryrun-2026-09-05.md` |
| T0.6 cadangan `backup-erp1.sh --engine mysql` + drill restore + runbook cut-over `deploy/cutover-erp1.sh` | ✅ kode | `f816ec5`, `9ccc5e3`; drill: dump 190 tabel, restore 13 dtk ke `erp_restore_check`, 1.421/1.421 baris, 0 selisih, drift terdeteksi — `docs/bukti-uji/restore-drill-mysql-2026-09-05.md`; DEPLOYMENT.md §10.9 |
| T0.6 **eksekusi cut-over** | ⏳ **belum** | diblokir untuk agen (lihat bawah); dijalankan pemilik dengan tangan |
| T0.7 metrik 40/80 paralel (0×503, p95 ≤ 1,5 s / ≤ 3 s) | ⏳ **belum diukur** | resep di DEPLOYMENT.md §10.10; bergantung pada cut-over |

## Asumsi yang dipakai dan yang perlu dikonfirmasi

- Keputusan pemilik #5/#6 (ROADMAP §5) dipakai sesuai rekomendasi: cut-over Sabtu pagi,
  arsip SQLite 30 hari (skrip `snapshot` menyimpan `.sqlite.gz` + `.gpg` di
  `/var/backups/erp1/cutover/`). Blok migrasi Core lanjutan BELUM dibutuhkan paket ini.
- Kredensial akun MySQL `erp` hanya ada di berkas mode 600 di scratchpad sesi
  (`…/scratchpad/phase0/mysql-erp.cred`, baris `DB_USERNAME=`/`DB_PASSWORD=`). `/tmp` bisa
  hilang saat reboot — bila hilang, pemilik memberi kata sandi baru per §10.3.
- Akun smoke (`SMOKE_EMAIL`/`SMOKE_PASSWORD`) harus pengguna nyata dengan `prc.view` +
  `prj.create`; skrip tidak pernah mencetaknya.

## Skema yang berubah — dan apakah migrasi aman di MySQL dengan data lama

Satu migrasi baru, tanpa penulisan data: `Modules/Projects/Database/Migrations/
2026_09_05_000746_add_live_key_unique_for_mysql.php` — hanya berjalan bila driver `mysql`
(di SQLite mencatat diri di ledger dan berhenti; ter-deploy ke SQLite produksi 5 Sep
09:5x: `DONE 2.86ms`). Di MySQL ia menambah kolom generated tersimpan `live_key`
(`CASE WHEN deleted_at IS NULL THEN CONCAT(project_id,'|',report_date) END`) + UNIQUE
`{table}_live_unique` pada `prj_daily_reports` dan `prj_hse_daily` — padanan indeks
parsial SQLite yang tidak dimiliki MySQL. Data lama aman: kolom generated dihitung dari
baris yang ada; bila ada duplikat hidup di data lama, ALTER gagal dan cut-over berhenti
di `basisdata`/`salin` — itu memang yang diinginkan. Dua migrasi lama (000721, 000742)
diberi cabang `if driver === 'sqlite'`, tidak ditulis ulang.

Koneksi baru `sqlite_legacy` di `config/database.php` (env `SQLITE_LEGACY_PATH`) dipakai
`erp:sqlite-to-mysql` dan `erp:migration-verify` untuk membaca berkas lama.

## Uji

- baru: `MysqlPreflightCommandTest`, `LiveKeyUniqueTest` (dua driver), `MysqlModeTest`,
  `SqliteToMysqlCommandTest` (7; 5 hanya-MySQL), `MigrationVerifyCommandTest`,
  `BackupEngineTest`; uji `SqlitePragmaTest` dilewati di MySQL.
- lama yang diubah: `SqliteToMysqlCommandTest::…nextId` — `assertSame(max+1)` →
  `assertGreaterThanOrEqual(max+1)`: counter AUTO_INCREMENT InnoDB tidak di-rollback
  transaksi per-uji, jadi di suite penuh nilainya 239 vs 13 (temuan verifier). Dibuktikan
  bebas-urutan dengan counter dipra-naikkan ke 500.
- suite penuh SQLite di `f816ec5`: **3.797 tes / 17.916 asersi, hijau** (run verifier);
  di `cfb3f85` dijalankan ulang oleh gerbang `deploy/sync-erp1.sh` (hijau — skrip menolak
  deploy bila merah).
- suite penuh MySQL di `cfb3f85`: berjalan dari worktree terpisah saat laporan ini ditulis
  (hasil di scratchpad `phase0/suite-mysql-cfb3f85.txt`; angka diisi di § Deviasi baru).

## Smoke test

Tidak ada endpoint HTTP baru. Tiga perintah artisan baru (`erp:mysql-preflight`,
`erp:sqlite-to-mysql`, `erp:migration-verify`) dan dua skrip deploy
(`backup-erp1.sh --engine mysql`, `cutover-erp1.sh`). Dry-run cut-over dari kode
ter-deploy: `pra` menolak sebelum kode Fase 0 ada di situs (sebelum deploy), setiap
langkah lain menolak sebelum pendahulunya tercatat di `/var/backups/erp1/cutover/state`;
tanpa `--yes` skrip hanya mencetak rencana.

## Dokumentasi yang diperbarui

`docs/DEPLOYMENT.md` §10 (10.1–10.10: pemasangan MySQL, kredensial, `.cnf`, CI, burst,
pindah data, verifikasi, cadangan/drill, runbook cut-over, resep T0.7);
`docs/ROADMAP-HASHMICRO.md` tidak diubah (ledger keputusan tetap); `.github/workflows/ci.yml`
job `phpunit-mysql`; `tests/harness/burst.py`.

## Yang sengaja tidak dikerjakan, dan mengapa

- **Cut-over produksi tidak dijalankan oleh agen.** `bash /var/www/erp1.pi2.co.id/deploy/
  cutover-erp1.sh --yes pra` ditolak dua kali oleh pengklasifikasi mode-otomatis sandbox
  (dengan kata sandi smoke di baris perintah, dan dengan kata sandi dibaca dari berkas
  mode 600). Ini tidak diakali: runbook memang dirancang dijalankan pemilik/orkestrator
  dengan tangan (§10.9). Perintahnya, sebagai root di erp1, satu langkah per pemanggilan:

  ```
  export CUTOVER_CRED=/tmp/claude-0/-root-construction-erp/e2856f5d-42c3-434f-acb3-d5d72299d7bd/scratchpad/phase0/mysql-erp.cred
  export SMOKE_EMAIL=<akun nyata dengan prc.view + prj.create> SMOKE_PASSWORD=<kata sandinya>
  S=/var/www/erp1.pi2.co.id/deploy/cutover-erp1.sh
  bash $S --yes pra; bash $S --yes basisdata; bash $S --yes down; bash $S --yes snapshot
  bash $S --yes salin; bash $S --yes verifikasi; bash $S --yes env; bash $S --yes smoke; bash $S --yes up
  ```

  Rollback dalam 24 jam: `bash $S --yes rollback`. Sesudah `up`: ukur T0.7 per §10.10 dan
  simpan `cutover/migration-verify-<ts>.md` ke `docs/bukti-uji/`.
- **T0.7 belum diukur** karena bergantung pada cut-over; angka hari ini (SQLite): 503 pada
  ~40 paralel (DEPLOYMENT.md, HASIL-UJI §6.4). Angka MySQL dari harness burst (T0.4) diukur
  pada `erp_scratch`, bukan lewat nginx/php-fpm produksi — bukan pengganti T0.7.
- Tidak pindah ke docker, tidak ada Redis (keputusan ROADMAP §3 Fase 0).

## Deviasi baru yang ditemukan

- Verifikasi adversarial P-0: 15 klaim dikonfirmasi, 4 masalah — semuanya ditutup di
  `9ccc5e3`: (1) uji bergantung urutan (di atas); (2) skrip cut-over tanpa `--yes`;
  (3) `mv` cron tak dijaga → `up` tidak bisa diulang bila drill gagal; (4) rahasia bypass
  `down --secret=` tercetak oleh helper `artisan()` dan curl smoke → disamarkan `<rahasia>`.
- `Schema::getTables()` di MySQL mendaftar semua skema yang terlihat akun (380 tabel
  untuk basis data 190 tabel) — preflight dibatasi ke skema aktif (`41e581b`).
- Dua balapan yang hanya muncul di MySQL (SQLite menyembunyikannya dengan kunci tulis
  global) — diperbaiki lewat urutan kunci (`ee708aa`); ini menegaskan alasan Fase 0
  mendahului Fase 1.
- Suite penuh MySQL di `cfb3f85`: (diisi saat selesai).
