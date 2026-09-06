# Laporan Paket P1-F (ROADMAP-HASHMICRO Fase 1) — Report builder "Laporan Bebas" v1

Branch: `feat/phase1-f` (dari `feat/phase1-e`) · 6 September 2026

> Status jujur: **dibangun, diuji, diukur di peramban, dan diverifikasi adversarial satu
> putaran** — enam lensa baca-saja mengangkat **42 temuan berbeda**; **20 di antaranya cacat
> sungguhan dan sudah diperbaiki**, masing-masing dengan ujinya. Putaran verifikasi KEDUA belum
> dijalankan. Paket ini didahului dua putaran orkestrasi: survei
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
| `ReportableResourcesTest` | 10 / 254 |
| `ReportRunnerSafetyTest` | 4 / 39 |
| `SavedReportTest` | 14 / 60 |
| `ReportXlsxExportTest` | 5 / 21 |
| `ReportEndpointTest` | 6 / 22 |

`tests/Feature/Core` + `tests/Feature/Iam` setelah perbaikan verifikasi: **858 hijau / 6.630
asersi** (11 dilewati, 154 s).
**Suite penuh (SQLite) setelah perbaikan verifikasi: 3.961 uji / 20.288 asersi hijau, 11
dilewati, 543 s** — 49 uji dan 525 asersi lebih banyak daripada P1-E, seluruhnya milik paket ini;
tidak ada uji lain yang berubah hasilnya. MySQL: (diisi — job CI nightly).

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

## Verifikasi adversarial — putaran pertama

Enam lensa baca-saja (SQL & angka, injeksi & otorisasi, kecocokan dengan codebase, aturan
kejujuran, layar, dan kontrak roadmap klausa demi klausa) mengangkat **42 temuan berbeda**. Dua
puluh adalah cacat sungguhan dan sudah diperbaiki; masing-masing dipaku uji. Yang paling penting:

| # | temuan | akibatnya |
|---|---|---|
| 1 | **Pemilih kolom menyamakan `why_not` dengan "tidak tersedia"** | 15 kolom yang sempurna dapat DICETAK (setiap Kode, Nama, Keterangan) mati di pemilihnya, karena `why_not` menjelaskan kenapa sebuah kolom tidak bisa jadi DIMENSI. Katalog kini menyatakan `selectable` terpisah. |
| 2 | **Ketujuh kolom `status` tanpa `enum`** (ditemukan lima lensa terpisah) | Mengelompokkan menurut Status menuliskan `approved`/`available` di layar, CSV DAN XLSX, di tempat layar daftarnya menuliskan `Disetujui`/`Tersedia` — layar daftar mendapatkannya dari `status_label` kelas Resource, dan laporan tidak lewat Resource sama sekali. |
| 3 | **`catch (LogicException)` sebelum `catch (InvalidArgumentException)`** | `InvalidArgumentException` MEWARISI `LogicException` di PHP, jadi lengan kedua kode mati: setiap galat definisi dilabeli `owner` dan pemiliknya sendiri diberi tahu "hanya pemiliknya yang dapat mengubah" ketika yang salah adalah nama kolomnya. |
| 4 | **XLSX rincian menulis DECIMAL sebagai TEKS di MySQL** | PDO MySQL mengembalikan DECIMAL sebagai string; `is_numeric && !is_string` menolaknya, jadi kolom uang tercetak rata kiri dan `SUM` Excel atasnya nol. Service kini meng-cast lewat jenis kolom registri. |
| 5 | **Uji ONLY_FULL_GROUP_BY menuliskan kutip SQLite** | Uji yang ada JUSTRU untuk menjaga MySQL akan merah di suite MySQL. Kini membandingkan SQL tanpa karakter kutip di kedua sisi. |
| 6 | **`create()` tidak memeriksa izin sumber** | Seseorang bisa menyimpan laporan atas sumber yang tidak boleh ia lihat; barisnya tak terlihat olehnya tetapi TETAP ADA, dan membagikannya ke peran yang memegang izin itu berarti ia menyusun laporan atas data yang tidak pernah boleh ia sentuh. |
| 7 | **`canRead` menolak pemilik yang kehilangan izin sumber** | Orang yang kehilangan `fin.view` tidak bisa lagi menghapus laporannya sendiri — barisnya tinggal selamanya. Kini pemilik selalu boleh MENGELOLA barisnya (menamai, membagikan, menghapus); MEMBACA angkanya tetap butuh izin. |
| 8 | **Binding rute implisit membocorkan keberadaan** | Id yang tidak ada dijawab pesan Laravel, laporan tersembunyi dijawab kalimat kami — dua 404 yang berbeda bunyinya adalah cara menghitung laporan milik orang lain. Id kini diselesaikan di controller, satu kalimat untuk keduanya. |
| 9 | **Anggota `filters.in` tidak diperiksa jenisnya** | Anggota berupa array sampai ke pembangun kueri: `(string) []` adalah 500, `(int) []` diam-diam 0 — saringan yang mengembalikan laporan kosong tanpa satu kata pun. |
| 10 | **`tableExists()` hanya dipakai katalog** | Separuh kedua aturan degradasi registri tidak berlaku pada `run()`: laporan tersimpan atas modul yang belum termigrasi menjawab 500 alih-alih kalimatnya. |
| 11 | **`refreshSaved()` menelan galat** | "Gagal memuat" dan "Anda belum menyimpan laporan" tergambar sama — aturan Temuan 79, di layar ini. |
| 12 | **`XlsxSheetWriter` mengaku satu pemilik, padahal dua** | `FormXlsxExportService::line()` masih menyimpan salinannya, dan CONVENTIONS §18 mengklaim sebaliknya. Kini benar-benar satu pemilik. |
| 13–20 | plafon pivot menyebut "kelompok" padahal menghitung kombinasi; `'Buka'` meng-alias objek definisi baris tersimpan; sumber yang hilang dari katalog melempar TypeError; saringan `in` dibuang saat membuka; tanggal mode rincian tercetak ISO mentah; CSV rincian menulis desimal titik untuk non-currency; XLSX tidak menyebut agregatnya; saringan ber-kunci menerima teks apa pun dan diam-diam menjadi 0 | masing-masing diperbaiki dan dipaku |

Ditambah satu cacat di **harness sendiri**: S24 mengambil "kartu terakhir" sebagai kartu hasil,
yang benar hanya pada basis data bersih — begitu satu laporan tersimpan ada di bawahnya, jalan
KEDUA membaca kartu yang salah. Kartu hasil kini ditandai aplikasinya (`.report-result`), dan S24
menghapus laporan yang dibuatnya sendiri sehingga ia benar-benar dapat diulang.

**Yang TIDAK diperbaiki, dan alasannya**: berbagi ke peran yang tidak dipegang penyimpan (sah —
seorang admin membagikan ke peran yang tidak ia pegang adalah pemakaian normal, dan penerimanya
tetap disaring izin sumbernya); `MODES`/`AGGREGATES` yang disalin ke SPA (dua daftar lima kata yang
tidak pernah berubah tanpa mengubah validatornya juga); dan saringan ber-FK yang belum punya
pemilih di layar (§ Deviasi 3).

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Putaran verifikasi KEDUA belum dijalankan.** Putaran pertama (42 temuan, 20 diperbaiki) ada
   di atas; pola paket-paket sebelumnya menemukan temuan susulan pada putaran kedua, termasuk
   temuan TENTANG perbaikan putaran pertama. Fase penyanggahan otomatis (tiga penyanggah per
   temuan) DIHENTIKAN karena 129 agen pada mesin dua-konkurensi akan memakan berjam-jam; triase
   dilakukan tangan terhadap kodenya, dan setiap perbaikan dipaku uji.
2. **Suite MySQL belum dijalankan.** SQLite penuh hijau (3.950/20.155).
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
