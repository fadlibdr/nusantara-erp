# Laporan Paket P1-F (ROADMAP-HASHMICRO Fase 1) — Report builder "Laporan Bebas" v1

Branch: `feat/phase1-f` (dari `feat/phase1-e`) · 6 September 2026

> Status jujur: **dibangun, diuji, dan diukur di peramban; verifikasi adversarial SEDANG
> BERJALAN saat laporan ini ditulis.** Paket ini didahului dua putaran orkestrasi: survei
> delapan lensa atas codebase (±70 jebakan terverifikasi) dan panel tiga rancangan yang
> dinilai tiga hakim pada tiga sumbu. Rancangan pemenang (*registry-purist*, 23,0/30)
> dipakai dengan koreksi yang diambil dari dua yang kalah. Satu migrasi baru (000197),
> tidak ada endpoint di luar `core/reports/*`, tidak ada dependensi baru.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-F → status)

| Klausa kontrak (baris 192) | Status | Bukti |
|---|---|---|
| Registri `ReportableResources` (8 resource) | ✅ | `Modules/Core/Support/ReportableResources.php`; keputusan pemilik ledger #4 |
| kolom = kolom layar daftar — **dipaku uji** | ✅ *dengan penafsiran yang ditulis* | `ReportableResourcesTest`, kesetaraan kunci DAN urutan, dua arah — lihat § Kolom |
| `POST core/reports/run` = SATU kueri `DB::table` ber-whitelist | ✅ | `ReportRunner`; diukur `DB::listen`, diumumkan `meta.queries` |
| group-by + pivot 1×1×1 | ✅ | satu dimensi baris × satu dimensi kolom × satu ukuran, tiga slot tervalidasi |
| batas 5.000 baris / 200 kelompok yang **DIUMUMKAN** | ✅ | `meta.limits`; **penolakan**, bukan pemotongan |
| izin = `{prefix}.view` layar daftar | ✅ | registri menyaring dirinya; 403 menyebut izinnya |
| `core_saved_reports` + bagi per peran (pemilik saja yang mengubah) | ✅ | migrasi 000197, `SavedReportService`, 11 uji |
| CSV/XLSX (sel kosong, bukan 0) | ✅ | CSV sisi klien (`csv.js`), XLSX sisi server; dibaca kembali dari berkasnya |
| Harness S24 | ✅ | 15 syarat hijau pada jalan pertama |

## Kolom — dan kenapa kontraknya tidak bisa dibaca harfiah

Survei menemukan angkanya: **hanya 31 dari 90 layar daftar** yang seluruh kolomnya kolom tabel
dasar. Dua pertiga sisanya memuat jalur relasi (`vendor.name`) atau medan yang dihitung kelas
Resource (`outstanding`, `project_code`, `is_current`) — dan tidak ada satu kueri pun yang bisa
memproduksinya. **Enam dari delapan** sumber yang dipilih pun begitu.

Registri memenuhinya dalam satu-satunya bentuk yang bisa BENAR sekaligus DIPAKU: `columns` dikunci
dengan kunci kolom layar, **dalam urutan layar**, **setiap kunci hadir**, dan setiap kunci berakhir
di salah satu dari tiga nasib — dipetakan, digantikan, atau **ditolak dengan kalimatnya**. Tidak ada
nasib keempat, dan tidak ada kolom yang hilang diam-diam.

**Penolakan yang paling penting adalah kolom "Sisa" pada invoice.** `outstanding` BUKAN
`total − amount_paid`: `ArInvoice::outstanding()` mengembalikan 0 untuk invoice yang DIBATALKAN,
dan codebase ini sudah mendokumentasikan selisih naifnya sebagai bug uang — ia menaruh angka penuh
di sebelah lencana "Dibatalkan" lalu mengirim penagihan mengejar uang yang sudah tidak ada. Aturan
itu punya pemilik (modelnya); menyalinnya ke registri sebagai ekspresi SQL adalah salinan kedua yang
bisa berselisih. Kolomnya karena itu ditolak, dan **kalimat penolakannya tergambar di pemilih
kolom** — S24 memaku bahwa ia benar-benar terbaca di sana.

## Delapan sumber

| resource | tabel | izin | kenapa |
|---|---|---|---|
| `crm/contracts` | `crm_contracts` | `crm.view` | buku pesanan per lingkup per bulan TTD |
| `projects` | `prj_projects` | `prj.view` | satu-satunya layar tanpa kolom relasi maupun hitungan |
| `finance/ar-invoices` | `fin_ar_invoices` | `fin.view` | laporan lintas peran bernilai tertinggi — dikirim BERSAMA penolakannya |
| `finance/project-costs` | `fin_project_costs` | `fin.view` | satu-satunya tabel fakta murni; nol kolom turunan |
| `procurement/purchase-orders` | `prc_purchase_orders` | `prc.view` | audiens pengadaan tidak punya `fin.view` sama sekali |
| `subcontract/subcontracts` | `scm_subcontracts` | `scm.view` | komitmen SPK per proyek per skema PPh |
| `hr/employees` | `hr_employees` | `hr.view` | tiga enum nyata di satu tabel; satu-satunya tanpa dimensi periode |
| `assets/assets` | `ast_assets` | `ast.view` | kolom polos terlebar, dan `book_value` NULL alat sewa = kasus uji terbaik |

Ketiga rancangan panel memilih **delapan yang identik** secara independen.

## Portabilitas — "angka yang sama di kedua driver"

Tiga jebakan yang seluruhnya membuat suite SQLite hijau dan produksi MySQL salah:

1. **ONLY_FULL_GROUP_BY** menyala di setiap sesi MySQL dan tidak di SQLite. Setiap ekspresi select
   bukan-agregat masuk `GROUP BY` **byte-identik**; `compile()` diekspos, dan `ReportRunnerTest`
   menyapu **60+ kombinasi** (setiap sumber × dimensi × ember × agregat) tanpa perlu MySQL.
2. **Tidak ada idiom bulan yang portabel.** `MONTH()`/`DATE_FORMAT` MySQL saja; `strftime` SQLite
   saja **dan** dipindai terlarang oleh `MysqlPreflightCommand`. Dipakai `substr` — dan ember
   **harian** pun memotong (`substr(col,1,10)`), karena kolom `date` terbaca
   `'2026-03-25 00:00:00'` di SQLite dan `'2026-03-25'` di MySQL: mengelompokkan nilai mentahnya
   memberi kunci kelompok yang **berbeda per driver**.
3. **`DB::table` melewati SoftDeletes.** Tujuh dari delapan tabel punya `deleted_at`; nilainya
   dinyatakan per entri dan **dipaku sama dengan skema hidup**, karena `true` yang salah adalah 500
   dan `false` yang salah adalah laporan yang menghitung dokumen yang sudah dibuang — diam-diam,
   dan selamanya.

## Tiga keadaan sel

| keadaan | JSON | layar | XLSX |
|---|---|---|---|
| tidak ada baris sumber | `null`, `counts=0` | `—` ("tidak ada baris pada kombinasi ini") | sel kosong |
| ada baris, agregat NULL | `null`, `counts>0` | `—` ("n baris, tetapi nilainya tidak ada") | sel kosong |
| nol yang dijumlahkan | `0.0` | `0` | `0` |

Diuji ujung ke ujung pada kasus yang benar-benar ada bentuknya: **nilai buku alat sewa adalah
NULL** — alat itu tidak ada di neraca perusahaan, dan menulis 0 di sana menaruhnya di sana.
`ReportXlsxExportTest` membuktikannya dengan **membaca kembali berkas XLSX-nya**, bukan array yang
menjadi sumbernya.

## Berbagi per peran — rujukan peran pertama di basis data ini

Tidak ada satu tabel pun di sistem ini yang menyebut peran sebelum paket ini, jadi kedua arahnya
baru. Disimpan **NAMA**:

- **Id tidak bisa membawa FK** — peran milik Iam, tabel milik Core, CONVENTIONS §3 melarangnya.
  Peran yang dihapus akan menggantung tanpa cascade dan tanpa tanda.
- **Nama sudah diseberangkan ke klien** (`iam/auth/me` mengirim `roles: [nama]`) dan dibaca
  `$user->hasRole($name)`, yang **tidak pernah melempar** — sementara scope `User::role($name)`
  milik Spatie melempar `RoleDoesNotExist` untuk nama basi, yaitu **500**, bukan daftar kosong.
- **Basi hanya lahir dari penggantian nama, dan basinya TERLIHAT**: penulisan divalidasi terhadap
  peran yang hidup, jadi berbagi tidak pernah *lahir* basi; peran yang kemudian diganti namanya
  ditandai di daftar laporan.

Dua aturan yang menjaganya aman, keduanya diuji:

- **Berbagi tidak pernah memberi akses baru.** Laporan Keuangan yang dibagikan ke gudang tetap 404
  baginya. Kalau ini salah, satu kotak centang menjadi cara memberi izin.
- **Hanya pemilik yang mengubah**, **tanpa jalan pintas admin**, ditolak **422 dari service** —
  bukan 403, karena orang itu BOLEH membaca laporannya; ia hanya bukan pemiliknya. Kalimatnya
  menyebut laporannya, pemiliknya, dan jalan keluarnya ("Simpan sebagai salinan"), bentuk
  `PettyCashVoucherService::assertCustodian` — satu-satunya penjaga identitas yang sudah ada di
  codebase ini.

## Uji

| berkas | uji / asersi |
|---|---|
| `ReportRunnerTest` | 10 / 128 |
| `ReportableResourcesTest` | 8 / 158 |
| `ReportRunnerSafetyTest` | 4 / 33 |
| `SavedReportTest` | 11 / 51 |
| `ReportXlsxExportTest` | 5 / 21 |

`tests/Feature/Core` seluruhnya: **790 hijau / 6.040 asersi** (11 dilewati). Suite penuh dan MySQL:
(diisi).

**Harness S24** (Chromium 1440×900): 15 syarat hijau pada jalan pertama — katalog delapan sumber
yang menyaring dirinya per izin (gudang melihat lebih sedikit), plafon yang **diumumkan server**
tercetak di layar, pivot berjalan dengan dimensi ber-FK yang **dilabeli** (`PRJ-2026-001 — …`, bukan
id telanjang), "1 kueri" diumumkan di kaki kartu, kolom yang ditolak **terlihat** dengan alasannya,
sel kosong `—` dengan keterangan yang membedakan kedua sebabnya, dan laporan tersimpan yang
menawarkan XLSX. Bukti: `docs/bukti-uji/results-phase-1.json`, `s24-*-p1f.png`.

## Deviasi

1. **Migrasi 000197, BUKAN "Core 001400" yang disarankan ledger #5.** CONVENTIONS §2 sudah
   memberikan 001400–001499 kepada Quality, dan Quality memakainya sejak 001400. Core menyisakan
   **000198 dan 000199** — blok lanjutan Core perlu diputuskan sebelum tabel Core berikutnya.
   **Keputusan pemilik.**
2. **"Kolom = kolom layar daftar" dipenuhi sebagai tiga-nasib, bukan salinan.** Alasannya di atas;
   ia lebih ketat daripada salinan (setiap kolom layar harus diputuskan), bukan lebih longgar.
3. **Saringan hanya enum di layar v1.** Registri mendukung saringan ber-FK (`eq`/`in` atas
   `project_id`, `vendor_id`, `customer_id`) dan endpoint menerimanya, tetapi layar belum
   menawarkan pemilihnya: sebuah kotak teks untuk id adalah antarmuka yang menyuruh orang mengetik
   angka. Ditunda dengan sengaja.
4. **XLSX hanya untuk laporan tersimpan.** Berkasnya menyebut nama laporannya; unduhan yang tidak
   bisa dilacak kembali ke pertanyaannya bukan lampiran rapat yang bisa dipertanggungjawabkan.
   Laporan ad-hoc mengunduh CSV.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Verifikasi adversarial sedang berjalan** (enam lensa, setiap temuan disanggah tiga penyanggah
   independen; yang bertahan dua dari tiga suara dilaporkan). Hasilnya belum masuk laporan ini.
2. **Suite penuh dan MySQL belum dijalankan** untuk paket ini; `tests/Feature/Core` hijau.
3. **Data demo tipis.** Tabel katalog berisi 1–9 baris; plafon 200 kelompok / 5.000 baris hanya
   pernah tersentuh oleh fixture yang dibuat ujinya sendiri, tidak pernah oleh data nyata.
   `crm/contracts`, `hr/employees` dan `subcontract/subcontracts` **belum pernah tergambar di
   layar** dengan data sungguhan.
4. **`teknisi` tidak mendapat satu pun sumber, `warehouse` hanya `projects`.** Ketiga rancangan
   panel memilih delapan yang sama dan lubang ini nyata; entri kesembilan termurah adalah
   `finance/ap-bills` (bentuknya identik dengan AR) atau saldo stok — yang perlu entri RESOURCES
   sendiri lebih dulu. **Keputusan pemilik**, karena angka delapan miliknya.
5. **Ekspor XLSX memakai `phpoffice/phpspreadsheet` yang hanya dependensi TRANSITIF**
   (`maatwebsite/excel ^3.1`, terkunci 1.30.6) — sama seperti `FormXlsxExportService` yang sudah
   ada. Tidak ada dependensi baru, tetapi ketergantungan itu tetap tidak dinyatakan di
   `composer.json`, dan sekarang DUA fitur bergantung padanya.
