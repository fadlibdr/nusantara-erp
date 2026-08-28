# Laporan Paket P0-B — Addendum waktu

Branch: main (langsung; disiplin feat/<paket> mulai P1) · Commit: 34f0d13 ·
28 Agustus 2026

> Laporan ini disusun-ulang 28 Agustus dari pesan commit, pohon kode, dan keluaran
> verifikasi adversarial paketnya — laporan §6 tidak sempat ditulis pada sesinya.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.2 Addendum waktu — "Tidak ada tempat mencatat perpanjangan waktu"; kop mencetak kosong | ⬜ 🔬 | ✅ CCO `change_type=waktu`: `days_change` bertanda, `new_end_date` dihitung saat persetujuan; approve menggeser end_date kontrak DAN proyek | `Modules/Crm/Enums/ChangeOrderType.php:26`; `ContractChangeOrderService.php:446` (`assertTimeAddendum`); `ProjectService.php:131` (`shiftEndDateForContract`); `ContractTimeAddendumTest` |
| 3.5 Addendum generik — "waktu ⬜" | 🟡 | ✅ untuk CCO kontrak owner (ADS subkon tetap tanpa tipe waktu — lihat bagian tidak dikerjakan) | idem |
| Kop PERPANJANGAN WAKTU I/II pada semua formulir rumah (§13.5 panduan) | garis kosong, "tidak ada yang mencatatnya" | ✅ tercetak dari dua CCO waktu DISETUJUI pertama, urut tanggal; CCO ke-3 dst → "lihat register"; tanpa CCO → tetap bergaris (byte-identik fixture emas) | `Modules/Core/Services/FormPrintService.php:302` (`timeExtensionLines`), `:332`; `FormPrintKopWaktuTest`; fixture `tests/fixtures/data-proyek-pra-p0b.html` |
| Kriteria penerimaan #7 (cetak ulang revisi tepat) — sebagian | 🟡 🔬 | membaik: F/BATK bercabang per instrumen (tabel uang vs tabel PERUBAHAN WAKTU), judul ber-resolver | `CrmFormPrintTest::test_a_waktu_cco_prints_the_time_layout_and_no_value_rows` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

Tidak ada asumsi bernomor Bagian 2 yang terpakai (asumsi #7 — tautan CCO hanya saat
`submitted` — tidak tersentuh paket ini). Keputusan lokal yang perlu konfirmasi:

- `days_change` BERTANDA: negatif = pengurangan waktu yang sah (percepatan yang
  disepakati); nol ditolak di kedua lapis; batas ±32767 mengikuti tipe kolomnya.
- Proyek `OnHold` sengaja LOLOS gerbang — penangguhan justru alasan perpanjangan
  diteken; yang ditolak hanya Masa Pemeliharaan dan Ditutup.
- Pembatalan CCO tidak diciptakan: CCO memang tidak bisa dibatalkan hari ini; instrumen
  pembaliknya addendum lawan berhari negatif (diuji menumpuk dan kembali).

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

- `2026_08_28_000385_add_time_addendum_to_crm_contract_change_orders_table.php`
  (Modules/Crm) — `days_change` smallInteger nullable + `new_end_date` date nullable
  pada `crm_contract_change_orders`; `original_end_date` date nullable pada
  `crm_contracts`
  (ditulis SEKALI oleh persetujuan waktu pertama, idiom `original_value`).

Aman dengan data lama: hanya `ADD COLUMN` nullable, tanpa backfill — kontrak lama tetap
bermakna "tidak pernah diperpanjang" lewat NULL. Aman juga di MySQL.

## Uji

- baru: 26 — `FormPrintKopWaktuTest` (7: dua baris kop terisi urut tanggal, format
  `+14 hari → …`, "lihat register" pada CCO ke-3, draf/tolak tidak mencapai kop,
  byte-identik tanpa CCO), `ContractTimeAddendumTest` (16: aturan wajib-0/wajib-hari,
  hitung saat persetujuan, CCO berurutan menumpuk, `original_end_date` sekali, gerbang
  masa pemeliharaan/tutup di kedua titik, OnHold lolos, addendum negatif membalikkan),
  `CrmFormPrintTest` (+3: tata letak waktu tanpa baris nilai, kutip tanggal tercap,
  tambah-kurang byte-identik pra-P0-B).
- lama yang diubah: 0 perilaku — tiga berkas hanya doblok/komentar (`FormPrintTest`
  kehilangan doblok basi "tidak ada yang mencatat perpanjangan waktu";
  `QcFormPrintTest` dan `SubcontractPrintTest` menyesuaikan kalimatnya).
- suite penuh: OK (3.048 uji, 13.866 asersi, 467 dtk).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Tidak ada endpoint baru — CCO memakai rute lamanya dengan `change_type: "waktu"`.
Pesan penolakan yang dipaku uji:

Validasi request (yang dilihat layar):

> "Addendum waktu tidak memindahkan nilai — value_change wajib 0."

> "days_change hanya bermakna pada addendum waktu (change_type waktu)."

> "new_end_date dihitung sistem saat addendum disetujui — tanggal selesai kontrak
> berjalan + days_change — bukan diinput."

Kembaran pertahanan-berlapis di service:

> "Addendum waktu tidak memindahkan nilai — value_change wajib 0; perubahan nilai
> dicatat sebagai CCO tambah-kurang tersendiri."

> "Addendum waktu tanpa hari bukan perubahan — days_change wajib diisi dan tidak boleh
> 0 (negatif berarti pengurangan waktu)."

Gerbang proyek (otoritatif di dalam transaksi persetujuan):

> "Proyek {kode} berstatus {Masa Pemeliharaan|Ditutup}; addendum waktu hanya berlaku
> atas pekerjaan yang masih berjalan — perpanjangan setelah serah terima adalah
> instrumen lain."

Kop sesudah persetujuan: `PERPANJANGAN WAKTU I: +14 hari → 14 Agu 2027
(CCO/2026/VIII/0002)`; addendum ketiga membuat baris II berbunyi `lihat register`.

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

PANDUAN-PENGGUNA: §3.6 Kontrak (tanggal selesai sesuai tanda tangan hanya di cetakan —
`ContractResource` sengaja tidak memancarkan `original_end_date`), §3.7 Pekerjaan
Tambah-Kurang (alur addendum waktu, pesan penolakan verbatim, judul paragraf approve
dipersempit menjadi "Menyetujui CCO nilai…"), baris kop PERPANJANGAN WAKTU pada bab
formulir. PANDUAN-ADMINISTRATOR: baris tabel "PERPANJANGAN WAKTU I dan II kosong"
ditulis ulang (kosong kini berarti tidak ada addendum waktu disetujui). README,
CONVENTIONS, ARCHITECTURE: tidak ada.

## Yang sengaja tidak dikerjakan, dan mengapa

- Jalur pembatalan CCO: tidak ada hari ini dan tidak diciptakan — instrumen baru di
  luar paket; pembaliknya addendum lawan (diuji).
- Backfill `original_end_date`/`days_change` ke CCO lama: forward-only.
- Tipe waktu untuk addendum SPK subkon: enum Subcontract tidak punya case `Waktu` dan
  request-nya menolak nilainya; SPA memakai daftar terkurasi `spkAddendumType` agar
  tidak menawarkan pilihan yang selalu ditolak server.
- Approve waktu tidak menyentuh jalur uang (ppn/total/contract_value proyek): addendum
  waktu yang diam-diam "membetulkan" uang adalah penulis kedua atas fakta yang bukan
  miliknya.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

Verifikasi adversarial: 12 konfirmasi, NOL temuan — verifikasi bersih pertama sesi
penutupan deviasi. Catatan kecil yang tetap layak dicatat:

- `pint --test` di akar repo gagal pada 4 berkas scaffolding PRA-ADA yang tak tersentuh
  (`bootstrap/app.php`, `bootstrap/providers.php`, `database/factories/UserFactory.php`,
  `database/seeders/ProductionSeeder.php`) — identik dengan HEAD, di luar permukaan
  paket, dan `bootstrap/*`/seeder terlarang disentuh. Berkas paket sendiri lolos pint.
