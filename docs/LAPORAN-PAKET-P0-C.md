# Laporan Paket P0-C — Tiga izin lapangan menjadi transaksi (IKL, ILB, IMK)

Branch: main (langsung; disiplin feat/<paket> mulai P1) · Commit: c566120 ·
28 Agustus 2026

> Laporan ini disusun-ulang 28 Agustus dari pesan commit, pohon kode, dan keluaran
> verifikasi adversarial paketnya — laporan §6 tidak sempat ditulis pada sesinya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.7 Izin Kerja Lapangan (PTW) — "F/IK kosong" | ⬜ 🔬 | ✅ `prj_work_permits` (`IKL/{Y}/{RM}/{N4}`), Approvable, blok bahaya/APD tercetak dari baris | migrasi 000724; `WorkPermitService.php:90` (jendela `permit_date`); `WorkPermitTest` |
| 3.7 Izin Lembur — "F/IL kosong; lembur dihitung payroll; tanpa pengajuan per kejadian" | ⬜ 🔬 | ✅ `prj_overtime_permits` + `_workers` (`ILB/…`); approve mengumpan `hr_attendance_recaps` lewat service HR | migrasi 000725; `OvertimePermitService.php:89` (`approve`); `OvertimeRecapService.php:43` (`applyMonthlyOvertime`); `OvertimePermitTest` |
| 3.6 Izin Masuk Material/Peralatan — "F/IM dicetak kosong" | ⬜ 🔬 | ✅ `prj_gate_passes` + `_items` (`IMK/…`), arah `in`, cetak dari baris muatan | migrasi 000726; `GatePassTest` |
| 3.6 Izin Keluar Alat/Material — "F/IM kosong" | ⬜ | ✅ dokumen yang sama, arah `out`; cap `checked_by/checked_at` hanya setelah disetujui | `GatePassService.php:64` (`periksa`); `GatePassTest` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Tidak ada asumsi bernomor Bagian 2 yang terpakai (umpan rekap mengikuti pola cuti #22
sesuai perintah paketnya). Keputusan lokal, tertulis di kode/uji, yang perlu
konfirmasi:

- Jendela `permit_date` INKLUSIF kedua tepi `start_date..end_date` rencana pelaksanaan
  — pasangan yang sama dengan kop WAKTU PELAKSANAAN, supaya lembar dan gerbang tidak
  bisa berselisih; tepi NULL tidak menegakkan apa-apa.
- Lembur lintas tengah malam sah (`end_time` < `start_time`, mis. 22:00 s/d 02:00);
  hanya durasi nol yang ditolak; dibukukan ke bulan tanggal lembarnya.
- Kolom `start_time`/`end_time` (bukan `start`/`end` spek): `END` kata kunci SQL;
  preseden rumah `work_start/work_end`.
- Penyetuju ketiganya `prj.approve`; periksa gerbang `prj.update` (memeriksa adalah
  kerja site, bukan persetujuan kedua; tidak ada peran satpam yang di-seed).
- ILB sengaja di LUAR registri lampiran — bukti lemburnya tanda tangan per orang di
  kertas; IKL dan IMK masuk `AttachableDocuments` (foto izin kerja, foto muatan).
- Baris `worker_name` (non-karyawan) tercetak di lembar tetapi tidak mengalir ke rekap
  — tidak ada rekap untuk orang yang bukan karyawan.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

- `2026_08_28_000724_create_prj_work_permits_table.php` — `prj_work_permits`: project
  (constrained, satu modul), `wbs_task_id`/`requested_by`/`safety_officer_id` tanpa FK,
  `permit_date`, `shift`, `work_description`, `hazard_notes`, `ppe_required` json,
  `valid_from/until` dateTime, `status`, softDeletes.
- `2026_08_28_000725_create_prj_overtime_permit_tables.php` — `prj_overtime_permits`
  (`overtime_date`, `start_time`/`end_time`, `reason`) + `prj_overtime_permit_workers`
  (`employee_id` XOR `worker_name`, `hours` decimal(5,2)).
- `2026_08_28_000726_create_prj_gate_pass_tables.php` — `prj_gate_passes` (`direction`,
  `pass_date`, `vehicle_no`, `driver_name`, `vendor_id`/`counterparty`,
  `goods_receipt_id`/`transfer_id` rujukan tanpa FK, `checked_by`/`checked_at`) +
  `prj_gate_pass_items` (`item_id` tanpa FK, `description`, `qty`, `unit`, `notes`).
- `config/erp.php`: tiga mask baru `IKL/ILB/IMK = …/{Y}/{RM}/{N4}`.

Aman dengan data lama: murni `CREATE TABLE` + tiga entri config, tanpa penulisan data;
aman juga di MySQL. Tambahan sadar di luar daftar spek: `pass_date` (baris TANGGAL
lembar) dan `notes` pada baris muatan (kolom KETERANGAN F/IM).

## Uji

- baru: 20 — `WorkPermitTest` (7), `OvertimePermitTest` (8), `GatePassTest` (5):
  siklus maker-checker tiap dokumen, jendela tanggal, lembur tengah malam, umpan rekap
  per (karyawan, bulan) yang tidak menggandakan pada sinkron ulang, periode terposting
  dilewati dan dilaporkan, urutan periksa-setelah-setuju, cap gerbang sekali, cetak
  dari baris.
- lama yang diubah: `QcFormPrintTest` — uji pad-kosong tiga izin DIGANTI (memaku persis
  perilaku yang dihapus paket; penjelasan di doblok berkasnya; termasuk pin baru baris
  NO. IZIN + STATUS sehingga draf di kertas menyebut dirinya draf);
  `PrintCatalogueBespokeTest` (param baris izin kini per-dokumen);
  `DocumentFormatValidationTest` (jumlah format 35→38).
- suite penuh: OK (3.066 uji, 14.015 asersi, 900,8 dtk; run verifikasi kedua 14 m 58
  dtk memberi angka yang sama).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Endpoint baru: `api/projects/work-permits`, `api/projects/overtime-permits`,
`api/projects/gate-passes` (CRUD + submit/approve/reject; gate pass juga `periksa`).
Pesan yang dipaku uji:

Tanggal izin di luar masa pelaksanaan → 422:

> "Tanggal izin {tanggal} di luar waktu pelaksanaan proyek {kode} ({mulai} s/d
> {selesai}). Izin kerja hanya untuk hari di dalam masa pelaksanaan — perpanjangan
> waktu dicatat lewat CCO waktu, bukan lewat izin."

ILB tanpa baris pekerja → 422:

> "Izin lembur tanpa satu pun baris pekerja bukan izin — lembar ini ditandatangani per
> orang."

Lembur berdurasi nol → 422:

> "Jam selesai ({jam}) sama dengan jam mulai ({jam}) — lembur berdurasi nol. Lembur
> yang melewati tengah malam ditulis dengan jam selesai lebih kecil dari jam mulai
> (mis. 22:00 s/d 02:00)."

Periksa gerbang sebelum disetujui → 422:

> "Izin {kode} belum disetujui (status: {label}) — pemeriksaan gerbang hanya untuk izin
> yang sudah disetujui manajemen."

Periksa ulang → 422:

> "Izin {kode} sudah diperiksa oleh {nama} pada {waktu} — cap gerbang adalah bukti satu
> kejadian dan tidak ditimpa."

Approve ILB pada periode payroll terposting (200, pesan memberi tahu — bukan menulis
ulang):

> "Izin lembur disetujui. Rekap {YYYY-MM} tidak diubah — payroll periode itu sudah
> diposting."

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

PANDUAN-PENGGUNA: §7.13 (tiga register izin — layar, tombol, pesan penolakan verbatim;
ditempatkan di bab formulir rumah yang sudah dirujuk §13.3 dan panduan admin), §2.7
(IKL/IMK bisa dilampiri; ILB sengaja tidak, dengan alasannya), §11.5 (peringatan baru:
rekap lembur dihitung ulang wholesale saat ILB disetujui — jangan mengedit tangan),
§1.7 (daftar pengecualian kartu dasbor), §13.2–13.5 (tombol cetak per baris register,
kalimat "tercetak kosong" dihapus untuk ketiganya). PANDUAN-ADMINISTRATOR: §9.2 nomor
baris `schema.js` dimutakhirkan; tabel sel kosong disesuaikan. README, CONVENTIONS,
ARCHITECTURE: tidak ada.

## Yang sengaja tidak dikerjakan, dan mengapa

- 🧪 `ExternalApprovableDocuments` mode transisi untuk IKL: patch spike absen dari
  pohon; satu komentar seam di doblok `WorkPermit` menandai tempat adapternya.
- Draf tetap BISA dicetak — lembarnya menyebut dirinya draf (baris NO. IZIN + STATUS);
  kontrol yang dipaksakan adalah URUTAN periksa-setelah-setuju, bukan larangan cetak.
- `goods_receipt_id`/`transfer_id` pada IMK tidak dimunculkan di form SPA (API-only);
  panduan tidak mendokumentasikannya sebagai kolom layar (aturan kejujuran).
- Peran "satpam" tidak dibuat — `prj.update` adalah gerbang nyatanya; panduan menulis
  "ditekan oleh yang mewakili pos jaga", bukan mengarang peran.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

Verifikasi ganda (dua pemeriksa): 18 konfirmasi. Satu-satunya masalah yang dilaporkan
keduanya adalah kegagalan `pint --test` PRA-ADA pada 4 berkas scaffolding tak
tersentuh (`bootstrap/app.php`, `bootstrap/providers.php`,
`database/factories/UserFactory.php`, `database/seeders/ProductionSeeder.php`) — sejak
commit awal repo, di luar permukaan paket, dan terlarang disentuh; berkas paket lolos
`pint --test --dirty`. Tidak diperbaiki (bukan cacat paket); dicatat supaya klaim
"pint lolos" tidak terbaca menutupi seluruh repo.
