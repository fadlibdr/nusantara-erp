# Laporan Paket P0-A — Laporan Harian penuh (FM-10-12 dari basis data)

Branch: main (langsung; disiplin feat/<paket> mulai P1) · Commit: 3fec5d5 (+ 08cfa55
lewat PR #1, pembersihan enums.js) · 28 Agustus 2026

> Laporan ini disusun-ulang 28 Agustus dari pesan commit, pohon kode, dan keluaran
> verifikasi adversarial paketnya — laporan §6 tidak sempat ditulis pada sesinya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.7 Laporan Harian (FM-10-12): "tanpa manpower per peran, material masuk/ditolak, alat, progress/target, jam kerja" | 🟡 🔬 🧪 | ✅ untuk sel yang kini bersumber — 12 jabatan, material masuk diterima/ditolak, alat, uraian/progress/target/hambatan, jam kerja; sel lain (tanda tangan MK/Owner) tetap menunggu spike | migrasi 000722/000723; `Modules/Projects/Services/LaporanFormService.php:95` (`harian()`); `LaporanHarianPenuhTest` |
| Bagian 2 (cetak-jujur → transaksi) untuk FM-10-12 | pad garis kosong | lembar tercetak DARI BARIS, hanya yang berdata; laporan lama byte-identik | `test_a_legacy_shaped_report_prints_byte_identically_to_the_pre_p0a_renderer`; fixture `tests/fixtures/laporan-harian-pra-p0a.html` |
| Kriteria penerimaan #1 (laporan mobile, PDF setara FM-10-12) | 🟡 🔬 | membaik: F/LH terisi dari data; Lapangan (mobile) mendapat 12 stepper jabatan | `public/app/js/views/lapangan.js`; `DailyReportFullSheetTest` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Tidak ada asumsi bernomor Bagian 2 yang terpakai. Keputusan lokal (spek diam) yang
perlu konfirmasi pemilik:

- Lingkup kunci BAST I: yang terkunci hanya laporan bertanggal ≤ tanggal serah terima —
  tanda tangan tiga pihak membekukan pekerjaan SAMPAI serah terima; laporan sesudahnya
  bukan bagian yang diserahkan dan tetap terbuka.
- Kandidat GRN diambil dari gudang site proyek yang sama (bukan dari PO proyek): FM-10
  mencatat yang TIBA DI SITE; GRN proyek ini yang diterima gudang pusat bukan kedatangan
  site.
- Hook `locked_at` dari keputusan eksternal pertama menunggu patch spike (absen dari
  pohon ini); satu komentar seam di `DailyReportService::lockForApprovedBastOne`
  menandai pintu keduanya.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

- `2026_08_28_000722_add_work_hours_and_lock_columns_to_prj_daily_reports_table.php` —
  `work_start`/`work_end` time nullable, `lost_hours_reason` string(300) nullable,
  `locked_at` dateTime nullable pada `prj_daily_reports`.
- `2026_08_28_000723_create_prj_daily_report_line_tables.php` — empat tabel baris:
  `prj_daily_report_manpower` (`role_key` enum `DailyReportRole`, `headcount`, unik
  `(daily_report_id, role_key)`), `prj_daily_report_equipment` (`asset_id` tanpa FK,
  `qty`, `hours`), `prj_daily_report_receipts` (`goods_receipt_id`/`item_id` tanpa FK,
  `qty_received`/`qty_rejected` decimal(15,3), `rejection_reason`),
  `prj_daily_report_activities` (`wbs_task_id` tanpa FK — pohon WBS diregenerasi,
  `sort_order`).

Aman dengan data lama: semua kolom baru nullable, tabel baru kosong, migrasi tidak
menulis satu baris pun (forward-only). Laporan lama membawa NULL dan tercetak persis
seperti sebelumnya. `ALTER TABLE ... ADD COLUMN` nullable dan `CREATE TABLE` juga aman
di MySQL.

## Uji

- baru: 27 — `DailyReportFullSheetTest` (19: derivasi `manpower_count`, tolak selisih
  manual terhadap baris terkirim maupun tersimpan, kompat angka manual tanpa baris,
  kosongkan baris memulihkan angka manual, role_key ganda/asing ditolak,
  `work_end` > `work_start` termasuk pembaruan parsial, `qty_rejected` ≤ diterima,
  round-trip empat tabel, kunci BAST ≤ serah terima, laporan terkunci menolak
  update/delete menyebut BAST-nya, kandidat GRN tersaring status/gudang/tanggal,
  kandidat butuh `prj.view`) dan `LaporanHarianPenuhTest` (8: cetak per sel bersumber,
  jabatan tetangga tetap bergaris, byte-identik pra-P0-A).
- lama yang diubah: tidak ada — `LaporanHarianFormTest` tak tersentuh dan hijau
  (9 uji / 68 asersi, diverifikasi berdiri sendiri).
- suite penuh: OK (3.022 uji, 13.777 asersi; run verifikasi terekam 14 m 19 dtk pada
  13.774 asersi, sebelum pengerasan uji kandidat menambah tiga asersi).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Endpoint baru: `GET api/projects/daily-reports/{id}/receipts-candidates` (butuh
`prj.view`) — GRN terposting gudang site proyek yang sama pada tanggal yang sama,
dengan penanda `already_imported` per (GRN, item); impor selalu dipilih pengawas.

Angka manual berbeda dengan rincian → 422 pada `manpower_count`:

> "Jumlah tenaga kerja manual (100) berbeda dengan total rincian per jabatan (81);
> selisih 19. Kosongkan angka manual atau samakan dengan rinciannya — rincian per
> jabatan adalah sumbernya."

Laporan terkunci di-PUT/DELETE → 422 menyebut pengunci:

> "Laporan {kode} terkunci oleh BAST I {kode BAST} (serah terima {tanggal}) dan tidak
> dapat diubah: pekerjaan sebelum serah terima sudah ditandatangani tiga pihak."

Jumlah ditolak melebihi diterima → 422:

> "Jumlah ditolak ({x}) melebihi jumlah diterima ({y}) pada baris \"{deskripsi}\" —
> yang ditolak adalah bagian dari yang datang."

Jam kerja terbalik → 422: "Jam selesai ({jam}) harus setelah jam mulai ({jam})."

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

PANDUAN-PENGGUNA: §7.3 (kalimat "BERGARIS KOSONG" dihapus hanya untuk sel yang kini
bersumber; PERPANJANGAN WAKTU disisakan untuk P0-B), §7.4 (Lapangan mobile: 12
stepper), §7.13 (baris sumber F/LH), §13.5 (baris menjadi per-laporan; catatan kaki
kondisional). PANDUAN-ADMINISTRATOR: baris "jam kerja kosong" ditulis ulang; nomor
baris `schema.js` dimutakhirkan. README, CONVENTIONS, ARCHITECTURE: tidak ada.

## Yang sengaja tidak dikerjakan, dan mengapa

- Hook spike persetujuan eksternal: patch-nya absen dari pohon; hanya komentar seam.
- Backfill rincian pada laporan lama: forward-only — laporan pra-P0-A tetap manual dan
  tercetak persis seperti dulu.
- Impor GRN otomatis: kandidat hanya disarankan, pengawas memilih (aturan paket).
- Kolom "Terkunci" di daftar: `locked_at` di detail adalah sinyal yang jujur; badge
  flag di setiap baris tak terkunci hanya bising.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

Empat temuan verifikasi adversarial (15 konfirmasi), semuanya ditutup sebelum commit:

1. Uji kandidat GRN tidak bisa gagal — GRN pengecohnya tanpa baris item, sehingga tiga
   saringan (status/gudang/tanggal) tak teruji. Ditutup: pengecoh diberi baris item;
   dibuktikan merah saat saringannya dicabut.
2. `already_imported` per GRN saja — baris kedua GRN dua-baris mengaku sudah diambil.
   Ditutup: penanda per (GRN, item).
3. Cabang mati "BAST tanpa tanggal serah terima mengunci semua" — skema `handover_date`
   NOT NULL, keadaan itu mustahil. Ditutup: cabang dan klaim dobloknya dihapus.
4. `form.js` diam-diam membuka form Ubah dengan lima tabel kosong saat refetch gagal —
   PUT sesudahnya menghapus seluruh rincian. Ditutup: gagal membuka lebih jujur
   daripada Simpan yang menghapus.

Selain itu: deklarasi `vendorDocumentType` ganda di `public/app/js/enums.js` ditemukan
saat pengerjaan, diperbaiki lewat sesi terpisah (08cfa55, PR #1) agar diff paket tetap
satu tujuan.
