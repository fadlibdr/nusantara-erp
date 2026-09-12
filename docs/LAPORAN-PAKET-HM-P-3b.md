# Laporan Paket HM P-3b — Kepatuhan DJP terkini (registri format, NPWP 16 digit / NIK / NITKU, rekap PPh 21/26 bulanan)

**Cabang:** `feat/phase3-p3b` (dari `main` 09bb1c8) · **Tanggal:** 12 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 3 / P-3b (8 hari-orang + konsultan), tugas T3b.0–T3b.3
**Status:** bagian yang **tidak menunggu pemilik** selesai di cabang — **belum di-merge, belum
di-deploy** (keduanya langkah pemilik; deploy dilarang untuk agen di alur kerja ini). Bagian yang
menunggu pemilik/konsultan **tidak dibangun** dan dicatat di §10–§11.

---

## 0. Satu kalimat

Paket ini seluruhnya tentang kalimat **"sesuai DJP"** — dan keputusannya adalah **tidak pernah
mengucapkannya tanpa bukti**. Sebelum P-3b, peringatan "VERIFY THE LAYOUT BEFORE PRODUCTION USE"
hidup di docblock `TaxExportService` yang tidak dibaca operator; yang sampai ke layar adalah
tombol `Unduh CSV` dan berkas bernama `efaktur-2026-03.csv` yang tampak selesai. Sesudah P-3b:
**satu registri** (`DjpFormats`) mendaftar kelima format DJP/BPJS yang ada atau direncanakan,
masing-masing dengan `verified_against` = berkas contoh resmi di `docs/samples/pajak/` + tanggal,
**atau null = "BELUM DIVERIFIKASI terhadap template DJP"** — dan kalimat itu sampai ke jawaban
API, lencana per format di layar, nama berkas (`-belum-diverifikasi.csv`) dan baris pertama
berkas. Hari ini `docs/samples/pajak/` **kosong** (disengaja; README-nya adalah daftar belanja
pemilik/konsultan), jadi kelima format berkata begitu — dan uji memaku bahwa tidak satu pun
boleh berkata lain sampai berkasnya ada di pohon.

Tidak ada tata letak berkas DJP yang dikarang: **tidak ada** parameter `format=coretax_xml`,
tidak ada writer e-Bupot 21/26, tidak ada ekspor SIPP, tidak ada dependensi baru
(`git diff main...HEAD -- composer.json composer.lock package.json` = **0 baris**), tidak ada
migrasi (kolom `npwp` varchar(30) sudah memuat NITKU 22 digit).

---

## 1. Tugas → status → bukti

> Angka di laporan ini adalah angka di **ujung cabang** (§8); angka yang berlaku pada satu commit
> disebut bersama SHA-nya. Setiap angka keluar dari perintah yang dijalankan (pelajaran F-8/P-3a G-1).

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T3b.0 | Registri format DJP + label jujur, SEBELUM satu baris kode lain | ✅ | `16b0c61` — `docs/samples/pajak/README.md` (daftar berkas per kunci, konvensi nama bertanggal, siapa memverifikasi, **tanpa satu contoh kolom pun** — dipaku `DjpFormatsTest`: README menyebut setiap kunci + pola nama, dan TIDAK memuat `KD_JENIS_TRANSAKSI`/`NOMOR_BUKTI_POTONG`/`<xml`); `Modules\Finance\Support\DjpFormats` lima kunci literal (`efaktur_csv_legacy` ada, `efaktur_coretax_xml` menunggu, `ebupot_unifikasi_csv` ada, `ebupot_2126_bulanan` menunggu, `sipp_bpjs` menunggu) + `describe()` murni (klaim tanpa berkas → belum diverifikasi); tiga permukaan: `GET finance/tax-exports` → `data.formats` + `data.<tab>.format`; layar `.djp-format` per entri + kalimat di atas tab dari API (kalimat SPA "dapat berubah mengikuti ketentuan DJP" dihapus, PANDUAN §10.12 disapu 1 → 0); berkas: `DjpFormats::filename` (akhiran) + `stampCsv` (baris `#` di atas) — **writer kolom tidak disentuh** (uji memaku header FK/`NOMOR_BUKTI_POTONG` di baris kedua). Tutup buku: "siap masuk berkas ekspor pajak — <kalimat registri>", bukan "siap diekspor ke DJP". `DjpFormatsTest` **15 uji** di ujung cabang (14 di `16b0c61`); 7 mutasi merah (§4) |
| T3b.1 | Aturan NPWP 16 digit / NIK / NITKU tanpa checksum karangan; satu kelas nilai; SATU Rule di SETIAP pintu tulis | ✅ | `1be9ecd` — `Modules\Core\Support\Npwp` (**di Core**: kolomnya milik empat modul; §2A) — klasifikasi HANYA dari panjang digit **15/16/22** (literal di uji), tanpa digit periksa (`000000000000000` sah, dipaku), tampilan 15 berformat cetak lama / 16 & 22 digit utuh, nilai lama tidak dikenali dipulangkan apa adanya; `Modules\Core\Rules\ValidNpwp` (kalimat 422 menyebut ketiga bentuk) di **7 pintu** (§5); maju-saja lewat `unlessUnchanged()` (§2B); teks bantuan pada 4 formulir SPA. **56 − 15 − 14 = 27 uji** di 4 berkas (`NpwpTest` 14, `CustomerNpwpGateTest` 5, `VendorNpwpGateTest` 4, `EmployeeNpwpGateTest` 4), merah dulu (8 error + 13 gagal), 8 mutasi merah (§4). Chromium: 422 tergambar di bawah field dengan kalimatnya, koreksi ke 16 digit → "Pelanggan dibuat." (§7) |
| T3b.2 | Rekap PPh 21/26 bulanan dari snapshot slip run disetujui/diposting; endpoint baca-saja; layar; CSV berlabel; uji kesetaraan; run draf tidak masuk; sel kosong | ✅ | `9c9bbc3` — `Pph21RecapService::monthly()` membaca `hr_payslips.ter_category/ter_rate/pph21_amount` (bukan hitung ulang: gaji diubah sesudah run disetujui → rekap tidak bergeser, dipaku), hanya run `approved`/`closed` (status yang sama dengan `decemberTax`, `TaxEqualizationService`), draf/diajukan/ditolak DISEBUT di `runs.excluded`, terhapus lunak tidak di kedua daftar; THR + gaji satu bulan → satu baris pegawai (setiap slip disebut); identitas: npwp dikenali → NIK 16 digit → **kosong** + `tax_id_issue` + `summary.without_tax_id`; CSV `;` + desimal koma, baris pertama `# Rekap internal PPh 21/26 … BUKAN berkas impor DJP`, `rekap-internal-pph21-YYYY-MM.csv`. `GET hr/pph21-recap` di balik **`hr.view`** (izin yang sudah ada — §2C). Layar `#/rekap-pph21` (`views/rekappph21.js`; NAV SDM & Payroll di bawah Payroll; `SHELL_VERSION` 9 → 10). `Pph21RecapTest` **14 uji**, merah dulu (14 error); kesetaraan `SUM(hr_payslips.pph21_amount)` dipaku di uji DAN di harness atas sqlite salinan (§7); 10 mutasi merah (§4) |
| T3b.3 | Sapuan kejujuran: docblock ke permukaan; TER HANYA diverifikasi; NTPN tetap manual | ✅ dengan **satu temuan** | `75df8f2` — **`config/erp.php` TIDAK memuat tabel TER** (`grep -n "'ter'" config/erp.php` = 0 baris): tabel dan tanda "verify against the official PMK 168/2023 attachment" hidup di `Pph21TerService`; docblock `TaxExportService` yang menunjuk `config/erp.php` dibetulkan (`16b0c61`). Tandanya diangkat ke permukaan pemakai sebagai `Pph21TerService::VERIFICATION_NOTE` ("…ditandai perlu dicek terhadap peraturan yang berlaku…") di API dan kaki layar rekap — **tidak satu angka TER pun berubah** (`git diff main...HEAD -- Modules/HrPayroll/Services/Pph21TerService.php` hanya menambah satu konstanta kalimat + docblock-nya). NTPN: `kalenderpajak.js` ("MANUAL — NTPN diketik dari SSP/BPN asli, tidak ada integrasi e-filing"; "dipilih manual, tidak ada yang otomatis") dan `TaxObligationService` ("harus mencantumkan NTPN dari SSP/BPN-nya") dipaku; sapuan string tampil di `views/*.js`, `Modules/*/Services/*.php`, `Modules/*/Http/Controllers/*.php` untuk "NTPN otomatis", "otomatis dari DJP", "siap Coretax", "sesuai DJP", "siap diekspor ke DJP" = **0** (uji `test_ntpn_stays_manual_and_nothing_promises_automation_or_djp_conformance`) |
| 4 | Uji PHP + mutasi; per-direktori, bukan suite penuh | ✅ | **56 uji baru** di 6 berkas (15+14+5+4+4+14; `grep -c 'public function test_'`), 1 pin lama diperbarui (`TaxExportTest` nama berkas jujur); **25 mutasi, 25 merah, 0 LOLOS HIJAU** (§4); per-direktori §8 |
| 5 | Harness S38 (desktop + ponsel) → `results-phase-3.json` BERDASARKAN KUNCI | ✅ | `d7cde2b` — `[S38_kepatuhan_djp] ok 5240ms clicks=1` (**19 syarat**), `[S38_kepatuhan_djp_ponsel] ok 4264ms clicks=0` (**6 syarat**), `console_errors: []` keduanya; **10 kunci lama tetap, 2 ditambahkan** (dihitung: 10 → 12); 4 PNG. Run pertama S38 JATUH pada satu syarat karena harness membaca `innerText` ubin yang di-uppercase CSS — kode aplikasi tidak berubah, harness dibetulkan ke `textContent` (jebakan S37 pada `th`, muncul lagi pada `.stat .label`) |
| 6 | Cangkang PWA | ✅ | `9c9bbc3` — `js/views/rekappph21.js` di `SHELL`, `SHELL_VERSION` 9 → 10; `PwaServiceWorkerTest` hijau (bagian dari 32 uji kabel SPA) |
| 7 | `/app/` dimuat di Chromium, 0 galat konsol pada setiap layar yang disentuh, desktop + ponsel | ✅ | §7 — 8 rute × 2 viewport sebagai admin@ + 1 sesi finance@: ponsel `console_errors: []`, finance `[]`; desktop `[]` pada kedelapan pemuatan rute, lalu **satu** baris `Failed to load resource: 422` yang ditulis Chromium sendiri untuk **probe 422 yang disengaja** (formulir pelanggan, NPWP `123`); `http_errors: []` di luar probe; tidak ada gulir samping |
| 8 | Laporan ini | ✅ | berkas ini; sapuan dokumentasi §13 |

---

## 2. Tiga keputusan yang membuat paket ini

### (A) `Npwp` di `Modules/Core/Support`, bukan di Finance

Kolom `npwp` hidup di **empat** tabel milik **empat** modul: `core_company` (Core), `crm_customers`
(Crm), `prc_vendors` (Procurement), `hr_employees` (HrPayroll). CONVENTIONS melarang Core
meng-import modul fitur, dan modul fitur boleh meng-import Core — pola `PhoneNumber` (P-3a).
Meletakkannya di Finance berarti Crm/Procurement/HrPayroll meng-import Finance untuk sebuah
aturan bentuk string. Yang tetap di Finance: `DjpFormats` (registri berkas pajak) — HrPayroll
meng-import-nya untuk entri `ebupot_2126_bulanan`, seperti `PayrollPostingService` sudah
meng-import `JournalService`.

### (B) Maju-saja berarti "nilai yang BERUBAH diperiksa", bukan "baris lama tidak boleh disunting"

Formulir SPA mengirim **seluruh baris**. Tanpa pengecualian, menyunting nomor telepon vendor
lama ber-NPWP `N/A` ditolak 422 pada kolom yang tidak disentuh siapa pun — itu aturan baru yang
menyandera data lama, bukan menjaganya. `ValidNpwp::unlessUnchanged($tersimpan)` pada keempat
pintu UPDATE: nilai yang dikirim kembali **persis sama** bukan penulisan baru dan lolos; nilai
yang berubah diperiksa; mengosongkannya diterima. Untuk impor massal jalannya lain tetapi
setara: lembar yang **tidak membawa kolom** `npwp` membiarkan nilai lama apa adanya (perilaku
importer yang sudah ada, kini dipaku), sedangkan baris yang membawa nilai salah dilewati dengan
kalimatnya sementara baris lain mendarat. Kedua jalur diuji. Yang disimpan adalah yang diketik
(dipangkas), bukan bentuk kanonik: satu transformasi tulis = satu tempat lagi aturan bisa bocor,
dan pembaca yang butuh digit (`TaxExportService::digits`) sudah menormalkan sendiri.

Yang **sengaja tidak** dilakukan: klasifikasi 16 digit menjadi "NIK" vs "NPWP badan" dari digit
pertama (NIK tidak pernah berawalan 0; NPWP-16 badan = `0` + NPWP lama). Ia masuk akal, tetapi
ia inferensi yang tidak diminta dan tidak ada di peraturan sebagai aturan validasi — labelnya
berbunyi "NPWP 16 digit / NIK" dan berhenti di situ. Bila suatu hari dibutuhkan, itu satu
cabang di `Npwp::kind()` dengan alasan tertulis, bukan default hari ini.

### (C) Rekap di balik `hr.view`, dan satu baris per pegawai

Rute payroll yang ada tidak bergerbang izin pada GET-nya; tetapi rekap ini memasangkan nama
pegawai dengan NIK/NPWP dan penghasilannya — data pribadi yang sama yang menggerbangi register
sertifikat, cuti, dan absensi dengan `hr.view`. Izin yang sudah ada dipakai, tidak ada izin
baru; peran `finance` sudah memegang `hr.view` (RoleSeeder), jadi petugas pajak sampai ke
rekap lewat tautan di kartu registri Ekspor Pajak (diukur sebagai `finance@` di §7).

Satu baris per pegawai per masa (bukan per slip) karena itulah bentuk yang diisi ke e-Bupot
21/26; gaji + THR satu bulan dijumlahkan, kategori/tarif baris dari slip reguler, dan setiap
slip disebut di `slips` dengan tarifnya sendiri — slip THR menyimpan tarif TER penghasilan
gabungan (`buildThrPayslip`), jadi tarif baris × bruto baris memang tidak selalu = PPh baris,
dan rinciannya (dan `title` sel PPh di layar) yang menjelaskannya.

---

## 3. Temuan sendiri (bukan oleh mutasi)

1. **`config/erp.php` tidak memuat tabel TER** — docblock `TaxExportService` mengklaim "this
   mirrors how config/erp.php treats the PPh 21 TER brackets"; tabelnya ada di `Pph21TerService`.
   Dibetulkan di `16b0c61`, dipaku di `75df8f2` (uji merah bila `config/erp.php` suatu hari
   memuat kunci `'ter'` tanpa memindahkan kalimatnya).
2. **Sapuan string terlarang menangkap komentarnya sendiri** — dua komentar yang mengutip
   frasa terlarang di dalam tanda kutip (`"sesuai DJP"`, `"siap diekspor ke DJP"`) cocok dengan
   regex string-tampil. Diganti guillemet; yang dijaga tetap string yang tampil.
3. **Harness membaca ubin yang di-uppercase CSS** (`.stat .label { text-transform: uppercase }`)
   — `innerText` "PEGAWAI TANPA IDENTITAS PAJAK"; jebakan yang sama dengan `th` di S37. Diganti
   `textContent`. Aplikasi tidak berubah.

---

## 4. Mutasi — 25 dijalankan, **25 merah, 0 LOLOS HIJAU**

Setiap mutasi diterapkan pada kode yang **sudah di-commit**, ujinya dijalankan, lalu dikembalikan
dengan `git checkout` berkas itu (pohon bersih diperiksa sesudah setiap putaran).

| # | Mutasi | Uji yang merah |
|---|---|---|
| M1 | `stampCsvFor` tidak menambah baris komentar | `DjpFormatsTest` 2 gagal |
| M2 | `filenameFor` tanpa akhiran | 3 gagal |
| M3 | `describe()` percaya klaim tanpa berkas (`$exists = true`) | 1 gagal |
| M4 | `overview()` membuang `formats` | 1 error |
| M5 | SIPP mengaku otoritas DJP | 1 gagal |
| M6 | e-Faktur legacy mengaku terverifikasi (`verified_against` diisi, berkasnya `docs/samples/README.md` — ADA di pohon) | 4 gagal |
| M7 | layar memakai kalimat karangan lagi | 1 gagal |
| MN1 | panjang 14 diterima | 4 berkas gerbang NPWP: 2 gagal |
| MN2 | `CustomerUpdateRequest` tanpa aturan | 2 gagal |
| MN3 | `VendorStoreRequest` tanpa aturan | 1 gagal |
| MN4 | `unlessUnchanged` lolos apa pun (bukan hanya yang sama) | 9 gagal |
| MN5 | `CompanyController` tanpa aturan | 2 gagal |
| MN6 | kolom npwp impor `employees` tanpa aturan | 1 gagal |
| MN7 | `format()` memulangkan null untuk nilai lama | 1 gagal |
| MN8 | huruf ikut dibuang saat normalisasi (`N/A` → `NA` → … ) | 1 gagal |
| MR1 | run draf ikut masuk rekap | `Pph21RecapTest` 2 gagal |
| MR2 | run `closed` tidak masuk | 1 gagal |
| MR3 | `without_tax_id` selalu 0 | 1 gagal |
| MR4 | identitas kosong menjadi `—` | 2 gagal |
| MR5 | NIK dilewati (hanya npwp) | 1 gagal |
| MR6 | slip THR dihitung dua kali | 1 gagal |
| MR7 | label CSV kehilangan "BUKAN berkas impor DJP" (menjadi "siap diimpor ke DJP") | 1 gagal |
| MR8 | run terhapus lunak ikut masuk | 1 gagal |
| MR9 | endpoint tanpa `permission:hr.view` | 1 gagal |
| MR10 | tarif Desember null ditulis `0,00` | 1 gagal |

Catatan M6: mutasi ini menguji hal yang berbeda dari M3 — berkasnya **ada**, jadi `describe()`
dengan benar menaikkannya ke "diverifikasi"; yang merah adalah uji "hari ini tidak satu pun
format terverifikasi" (4 asersi) — persis uji yang dirancang merah pada hari pemilik meletakkan
berkasnya, dan ia merah bukan hanya untuk berkas yang benar tetapi juga untuk berkas apa pun yang
kebetulan ada di pohon. Itu yang dimaksud "bukti tidak boleh mendahului klaim": `describe()` hanya
memeriksa keberadaan berkas; pencocokan kolom demi kolom terhadap berkas itu tetap kerja manusia
(README §3), dan ujilah yang mengharuskan orang itu datang menyunting.

---

## 5. Permukaan — setiap aturan baru, diperiksa satu per satu

Cacat berulang kampanye ini: aturan yang benar di satu permukaan bocor di permukaan lain.

| Aturan | Request/Controller | Service | Resource/API | Layar SPA | Berkas unduhan | Cetakan | Impor |
|---|---|---|---|---|---|---|---|
| "BELUM DIVERIFIKASI terhadap template" | — | ✅ `TaxExportService` (`format`, `filename`, `csv`), `PeriodCloseService` (kalimat tutup buku), `Pph21RecapService` (`format`) | ✅ `GET finance/tax-exports` (`formats` + per tab), `GET hr/pph21-recap` (`format`) | ✅ `.djp-format` ×5 + `.djp-export-verification`; `.recap-format` | ✅ nama `-belum-diverifikasi` + baris `#` (e-Faktur, e-Bupot); rekap: label "BUKAN berkas impor DJP" | tidak ada cetakan berkas DJP | — |
| Format menunggu template = tanpa unduhan | — | `downloadable: false` | ✅ | ✅ blok tanpa tombol Unduh, kalimat `awaiting_file` | tidak ada berkas | — | — |
| NPWP 15/16/22 digit di pintu tulis | ✅ `CustomerStore/Update`, `VendorStore/Update`, `EmployeeStore/Update`, `PUT core/company` | — (tidak ada service yang menulis npwp: `grep -rn npwp Modules/*/Services` = pembaca saja) | resource memulangkan nilai apa adanya (tidak ada `npwp_kind` — tidak ada layar yang memakainya di luar rekap) | ✅ teks bantuan 4 formulir; 422 tergambar di bawah field (Chromium §7) | — | ✅ cetakan tidak menolak nilai lama (`Npwp::format` apa adanya; `PrintableDocuments`/blade tidak diubah) | ✅ kolom `npwp` vendors/customers/employees, per baris; lembar tanpa kolom = nilai lama utuh |
| Nilai lama tidak disentuh | ✅ `unlessUnchanged` ×4 | — | ✅ GET memulangkan `N/A` apa adanya (3 uji) | — | ✅ `TaxExportService::digits` tidak berubah (NPWP < 15 digit tetap penghalang, bukan galat) | ✅ | ✅ |
| Rekap = slip run disetujui/diposting saja | ✅ | ✅ `whereIn status` + `withCount` + terhapus lunak keluar | ✅ `runs.included/excluded` | ✅ kartu "Run payroll masa ini" | ✅ baris `#` menyebut run yang membentuk | — | — |
| Sel kosong untuk yang tidak diketahui | — | ✅ `tax_id: null`, `ter_category/ter_rate: null` | ✅ null, bukan 0 | ✅ `td.tax-id[data-empty]` teks kosong; TER kosong / "Ps. 17" hanya Desember | ✅ `;;` di CSV | — | — |

Pintu yang **diperiksa dan ternyata tidak menulis npwp**: `TenderQualificationService` (membaca
`vendors.npwp` untuk daftar kualifikasi), `DocumentImportService` (dokumen induk+baris, tidak
ada kolom npwp), `CoreDatabaseSeeder`/`ProductionSeeder` (seeder, bukan pintu), `Iam` (`users`
tidak punya npwp), `HrFormService`/`PrintableDocuments`/blade (pembaca). Daftar pintu impor
dipaku literal `['vendors', 'customers', 'employees']` di `NpwpTest`.

---

## 6. Perangkap yang disebut di perintah — apa yang terjadi pada masing-masing

| Perangkap | Keadaan |
|---|---|
| A. "Verifikasi" dari ingatan | `docs/samples/pajak/` kosong → kelima `verified_against` null; `describe()` menolak klaim tanpa berkas; README tanpa contoh kolom (dipaku) |
| B. Menolak data lama saat DIBACA | `Npwp::format()` memulangkan apa adanya; GET customer/vendor/employee/company dengan `N/A` = 200 (4 uji); pembaca lama tidak diubah |
| C. Bocor di pintu lain | 7 pintu (§5), daftar impor literal; probe Chromium pada formulir pelanggan |
| D. Draf/koreksi masuk rekap, slip ganda | status `approved`/`closed` saja; draf/ditolak DISEBUT; terhapus lunak keluar; tidak ada run koreksi (indeks unik + larangan hitung ulang run disetujui); THR + gaji satu baris tanpa dobel (MR6 merah) |
| E. "Rp 0" untuk yang tidak diketahui | `tax_id` null → sel kosong (MR4 merah), Desember TER null → `;;` (MR10 merah) |
| F. "siap Coretax"/"sesuai DJP" | sapuan string tampil = 0; kalimat tutup buku diubah; komentar penjelas memakai guillemet |
| G. Angka laporan tidak dari perintah | setiap angka di sini disertai perintahnya atau nama uji/harness-nya |

---

## 7. Bukti peramban (pelajaran P1-I: rilis SPA belum terverifikasi sampai DIMUAT)

Chromium sungguhan (Playwright), `php -S 127.0.0.1:8231` dengan router
`vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`, `PHP_CLI_SERVER_WORKERS=1`,
atas **salinan** `database/database.sqlite` di scratchpad (`DB_DATABASE=<salinan> php artisan
migrate --force` — tidak ada migrasi paket ini; yang berjalan hanya milik P-3a pada salinan);
`database/database.sqlite` tidak disentuh; server dimatikan berdasarkan PID.

**Harness S38** (`docs/bukti-uji/harness-playwright.py`, fixture lewat pipeline sungguhan: baris
pegawai warisan `EMP-S38` dengan `nik_ktp 'BELUM-ADA'` tanpa NPWP disisipkan sqlite; run gaji
Juli 2026 dibuat → dihitung → diajukan `admin@` → disetujui `direktur@` lewat API (maker-checker;
"Payroll run approved and posted to the ledger."), run THR Juli dibiarkan draf; idempoten):

```
[S38_kepatuhan_djp] ok 5240ms clicks=1        19 syarat, console []
[S38_kepatuhan_djp_ponsel] ok 4264ms clicks=0  6 syarat, console []
```

Yang diukur di layar (bukan dari API): lima `.djp-format` urut registri, semuanya
`data-verified="false"` dengan lencana "Belum diverifikasi terhadap template DJP" (SIPP: "…BPJS
Ketenagakerjaan"); tiga yang menunggu template menyebut `docs/samples/pajak/<stem>-<YYYY-MM-DD>`
dan tidak punya tombol Unduh; kedua tab berkotak amber "BELUM DIVERIFIKASI terhadap template
DJP"; judul kartu berkas `Isi berkas — efaktur-2026-06-belum-diverifikasi.csv` / `ebupot-…`;
API `formats` = `[(efaktur_csv_legacy, false, true), (efaktur_coretax_xml, false, false),
(ebupot_unifikasi_csv, false, true), (ebupot_2126_bulanan, false, false), (sipp_bpjs, false,
false)]`. Rekap Juli 2026: **9 pegawai**, `EMP-S38` sel identitas **kosong** (`data-empty="true"`,
`title` menyebut `BELUM-ADA`), ubin "Pegawai tanpa identitas pajak" = **1**, `EMP-0007` (data demo:
tanpa NPWP, ber-NIK) berlabel "NIK (16 digit)", THR draf "Status Draf — tidak masuk rekap…",
run disetujui "Masuk rekap"; **total PPh 21 22.974.350,00 dan bruto 196.720.000,00 = `SUM` atas
`hr_payslips` run `approved/closed` masa itu di sqlite salinan** (9 slip = 9 slip); baris pertama
CSV "# Rekap internal PPh 21/26 … BUKAN berkas impor DJP" dan baris `EMP-S38;Pegawai Warisan
(fixture S38);;;…`; catatan TER tampil; tanpa gulir samping di 1440×900 maupun 390×844.
PNG: `s38-ekspor-pajak-registri.png`, `s38-rekap-pph21.png`, `s38m-ekspor-pajak.png`,
`s38m-rekap-pph21.png`.

**Pemeriksaan pemuatan** (skrip scratchpad, tidak di-commit; tabel `cache` salinan dikosongkan
dulu — pembatas 120/menit): `admin@` × 2 viewport × 8 rute — `#/tax-exports`, `#/rekap-pph21`
(Juli 2026), `#/r/crm/customers`, `#/r/procurement/vendors`, `#/r/hr/employees`, `#/company`,
`#/dashboard`, `#/home` — `h1` yang benar pada semuanya, `scrolls_sideways: false` pada
keenambelas pemuatan; ponsel `console_errors: []`, `http_errors: []`; desktop `http_errors: []`
dan konsol kosong pada kedelapan rute, lalu **satu** baris `Failed to load resource: … 422` yang
Chromium tulis sendiri untuk **probe yang disengaja**: `Tambah Pelanggan` → NPWP `123` → Simpan
→ 422 tergambar di bawah field dengan kalimat `ValidNpwp::MESSAGE` utuh + toast "Periksa isian
yang ditandai."; teks bantuan "NPWP 15 digit (lama), 16 digit (baru / NIK), atau NITKU 22 digit;
titik dan strip boleh." ada di field; koreksi ke `0012345678011000` → modal tertutup, toast
"Pelanggan dibuat." (`browsercheck-npwp-422.png` di scratchpad). `finance@` (fin.view + hr.view):
kartu registri menampilkan tombol "Buka rekap internal PPh 21/26 bulanan" pada baris
`ebupot_2126_bulanan`, klik → `#/rekap-pph21`, `console_errors: []`.

---

## 8. Gerbang (per-direktori, sesuai perintah — bukan suite penuh)

- **Saat kerja:** sesudah T3b.1 — `tests/Feature/Crm` **315 uji** hijau, `tests/Feature/Procurement`
  **185** hijau, `tests/Feature/HrPayroll` **203** hijau, `tests/Feature/Core` **1.118 uji / 11
  dilewati** hijau; sesudah T3b.2 — `tests/Feature/HrPayroll` **217** hijau, `PeriodClose*` **56**
  hijau, kabel SPA (`PwaServiceWorkerTest`, `NavRouteRegistryTest`, `SidebarNavWiringTest`,
  `LauncherWiringTest`) **32** hijau.
- **Ujung cabang, SQLite (`:memory:`):** `tests/Feature/Finance` **906 uji / 4.563 asersi**, `tests/Feature/HrPayroll` **217 / 956**, `tests/Feature/Crm` **315 / 1.220**, `tests/Feature/Procurement` **185 / 741**, `tests/Feature/Core` **1.118 / 9.990, 11 dilewati** — semuanya hijau, satu proses berurutan (log `p3b/gate-sqlite.log` di scratchpad); total **2.741 uji** di lima direktori
- **Ujung cabang, MySQL 8.0 `erp_dryrun` (SATU proses; 9 berkas yang disentuh/baru; kredensial dari
  env, `DB_DATABASE=erp_dryrun` menimpa `phpunit.mysql.xml`):** **139 uji / 698 asersi, hijau** (1 mnt 27 dtk; `DjpFormatsTest`, `TaxExportTest`, `NpwpTest`, `CustomerNpwpGateTest`, `VendorNpwpGateTest`, `EmployeeNpwpGateTest`, `Pph21RecapTest`, `MasterDataImportTest`, `PeriodCloseChecklistTest`) — log `p3b/gate-mysql.log` di scratchpad
- `vendor/bin/pint --test` bersih pada setiap berkas PHP yang disentuh (dua kegagalan pint lama
  tidak disentuh); `EmployeeUpdateRequest` hanya dirapikan urutan import-nya oleh pint.
- Suite penuh dua driver dari worktree terisolasi = langkah gerbang rilis pemilik/alur kerja
  berikutnya (tidak diminta paket ini).

---

## 9. Keputusan pemilik

| # | Keputusan | Rekomendasi / yang dipakai kode sampai dijawab |
|---|---|---|
| A | **Letak kelas `Npwp`**: Core vs Finance | **Core** (`Modules\Core\Support\Npwp` + `Modules\Core\Rules\ValidNpwp`) — dipakai; alasan §2A |
| B | **Bentuk `verified_against` di registri**: konstanta di kode (`DjpFormats::entries()`) + berkas di `docs/samples/pajak/` + register manusia di README §4, vs tabel `core_settings` yang bisa diisi dari layar | **konstanta di kode** — dipakai; verifikasi tata letak adalah perubahan kode (writer dicocokkan kolom demi kolom), bukan pengaturan; klaim tanpa berkas di pohon diturunkan otomatis |
| C | **Izin rekap PPh 21**: `hr.view` (dipakai) vs `fin.view` vs keduanya | **`hr.view`** — data pribadi payroll; `finance` sudah memegang `hr.view`, `finance-manager` TIDAK (hanya `fin.view` + `crm/prc/scm.view`) — bila manajer keuangan harus membaca rekap ini, tambahkan `hr.view` ke perannya (satu baris RoleSeeder + migrasi peran), atau putuskan `fin.view` juga membuka rute ini |
| D | **Run mana yang masuk rekap**: `approved` + `closed` (dipakai) vs `approved` saja | dipakai `approved` + `closed`, konsisten dengan `decemberTax` dan Ekualisasi Pajak |
| E | **Nilai NPWP disimpan apa adanya** (dipakai) vs dikanonikkan saat simpan | dipakai apa adanya; alasan §2B |
| F | **16 digit tidak dibedakan NIK vs NPWP badan** dari digit pertama | dipakai (klasifikasi panjang saja, sesuai perintah); §2B menyebut inferensi yang TIDAK dipakai |
| G | **`unlessUnchanged` hanya di pintu API; importer per baris ketat** (lembar tanpa kolom = nilai lama utuh) | dipakai; §2B |
| H | Tabel TER: kalimat "perlu dicek" tampil di layar rekap sebagai catatan kaki | dipakai; angkanya tidak diubah — verifikasi terhadap lampiran PMK 168/2023 adalah pekerjaan konsultan |

## 10. Prasyarat pemilik — tidak satu pun ada di repo

1. **Berkas template resmi**, diunduh pemilik/konsultan ke `docs/samples/pajak/` sesuai README di
   sana (nama `<kunci>-<YYYY-MM-DD>.<ekstensi asli>`), satu per kunci registri:
   `efaktur-csv-legacy-…` (aplikasi e-Faktur desktop), `efaktur-coretax-xml-…` (Coretax),
   `ebupot-unifikasi-csv-…` (Coretax e-Bupot Unifikasi), `ebupot-2126-bulanan-…` (Coretax e-Bupot
   21/26), `sipp-bpjs-…` (SIPP Online BPJS Ketenagakerjaan). Folder itu **kosong pada 12 Sep 2026**.
2. **Konsultan pajak** (ledger #9) mengimpor **satu masa nyata** ke sandbox Coretax untuk setiap
   format yang punya writer, mencocokkan totalnya dengan layar Ekspor Pajak, dan mencatat hasilnya
   di README §4 — SEBELUM `verified_against` diisi.
3. **Keputusan `phpoffice/phpspreadsheet`** (ledger #9) — hanya bila template SIPP resmi ternyata
   XLSX; tidak ditambahkan di paket ini.
4. **Keputusan C** (§9): `hr.view` untuk `finance-manager`, atau tidak.
5. Sesudah 1–2: pengembang mengisi `verified_against` per entri, mencocokkan writer kolom demi
   kolom, memperbarui `DjpFormatsTest::test_today_no_format_claims_verification…` (dirancang merah
   pada hari itu), dan barulah kalimat "BELUM DIVERIFIKASI" hilang dari layar, API, dan berkas —
   dengan sendirinya.

## 11. Yang TIDAK dikerjakan (bagian kedua paket, per butir)

- **e-Faktur `format=coretax_xml`** — tidak ada parameter, tidak ada writer; entri registri
  "menunggu template" menyebut berkas yang ditunggu. Menambah parameter yang menghasilkan XML
  karangan dilarang perintah, dan memang tidak ada gunanya sebelum berkas resminya ada.
- **Verifikasi kolom e-Bupot Unifikasi** — writer legacy tidak disentuh; statusnya "ada, belum
  diverifikasi". Pencocokan kolom = sesudah prasyarat 1–2.
- **Writer berkas e-Bupot 21/26** — yang ada rekap INTERNAL berlabel; format impor menunggu template.
- **Ekspor SIPP BPJS** — tidak ada writer; keputusan phpspreadsheet menunggu.
- **Uji di sandbox Coretax** — milik konsultan.
- **`npwp_kind` pada resource pelanggan/vendor/pegawai** — tidak ditambahkan: tidak ada layar
  yang memakainya (perintah: hanya bila layar memakainya); jenis identitas hanya di muatan rekap.
- **Klasifikasi NIK vs NPWP badan dari digit pertama** — tidak (§2B, keputusan F).
- **Migrasi** — tidak ada; kolom `npwp` varchar(30) sudah memuat 22 digit. Blok §2 CONVENTIONS
  tidak berubah.
- **Deploy dan merge** — dilarang untuk agen di alur kerja ini.

## 12. Deviasi baru yang ditemukan

- Docblock `TaxExportService` menunjuk `config/erp.php` sebagai tempat tabel TER; tabelnya di
  `Pph21TerService`. **Ditutup** (docblock dibetulkan, dipaku).
- Kalimat daftar tutup buku "N dokumen siap diekspor ke DJP" menjanjikan kesesuaian yang tidak
  pernah diverifikasi. **Ditutup** ("siap masuk berkas ekspor pajak — <kalimat registri>").
- Kalimat layar Ekspor Pajak "dapat berubah mengikuti ketentuan DJP" dikarang SPA dan tidak
  menyebut bahwa tata letaknya belum pernah dicocokkan. **Ditutup** (kalimat dari registri).
- Baris pegawai warisan dengan NIK/NPWP yang tidak dikenali bisa ada di data lama (fixture
  harness membuktikan aplikasi menanganinya) — pintu tulis kini menolak bentuk itu untuk baris
  baru; baris lama tetap terbaca dan terhitung, dengan sel kosong yang menyebut sebabnya.
- Peran `finance-manager` tidak memegang `hr.view` → tidak bisa membuka rekap PPh 21 (keputusan C).
- `.stat .label` di-uppercase CSS — harness yang membandingkan label ubin harus memakai
  `textContent` (S37 mencatatnya untuk `th`; S38 menemukannya lagi di ubin).
- Berkas PID yang ditulis `nohup php -S … & echo $!` dari dalam alat shell memuat PID **pembungkus
  bash**, bukan proses `php`: `kill` atas PID itu membiarkan `php -S` hidup (port masih menjawab
  200). PID yang benar dibaca dari soket yang mendengarkan (`ss -ltnp 'sport = :8231'` →
  `pid=1181334`) lalu `kill <pid>`; port terbukti tertutup sesudahnya. Tetap berdasarkan PID,
  tanpa `pkill -f`.

## 13. Sapuan dokumentasi (CONVENTIONS §35, `grep -rn … | wc -l` di ujung cabang)

- `grep -rn "Tata letak kolom mengikuti" docs/ | grep -v LAPORAN-PAKET-HM-P-3b | wc -l` — **1 → 0**
  di luar laporan ini (PANDUAN-PENGGUNA §10.12 ditulis ulang untuk kartu registri, kalimat dari
  server, nama dan baris pertama berkas; tanpa `grep -v` hasilnya 1 — baris ini sendiri, V2-9).
- `grep -rn "Rekap PPh 21 Bulanan" docs/ --include=*.md | wc -l` — **8** baris di
  **5** berkas Markdown (PANDUAN-PENGGUNA daftar grup ×2 + §11.8 baru; CONVENTIONS §39;
  ONBOARDING hr.md, finance.md; laporan ini) — semuanya dibaca.
- `grep -rn "BELUM DIVERIFIKASI" docs/ --include=*.md | grep -v LAPORAN-PAKET-HM-P-3b | wc -l` —
  **6** baris (README samples, PANDUAN §10.12, CONVENTIONS §39, ROADMAP §5).
- Berkas yang disapu: `docs/samples/pajak/README.md` (baru), `docs/CONVENTIONS.md` §39 (baru),
  `docs/PANDUAN-PENGGUNA.md` (§10.12, §11 daftar grup, §11.2, §11.8 baru, tabel §1 daftar layar),
  `docs/PANDUAN-ADMINISTRATOR.md` (§4.2 aturan NPWP + samples, §4.9 kolom npwp impor),
  `docs/ONBOARDING/hr.md` (tujuh layar), `finance.md` (kartu format, nama berkas, tautan rekap),
  `sales.md` (NPWP 15/16/22), `admin.md` (bentuk vs isi), `docs/ROADMAP-HASHMICRO.md` §5 (catatan
  baris 9). Tidak ada perubahan di `config/erp.php`, `bootstrap/*`, `composer.json`, `routes/*` akar.

## 14. Commit (urut lama → baru)

```
16b0c61  T3b.0  docs/samples/pajak/README.md, DjpFormats, tiga permukaan, tutup buku, DjpFormatsTest 14
1be9ecd  T3b.1  Npwp (Core), ValidNpwp, 7 pintu, teks bantuan 4 formulir, 27 uji di 4 berkas
9c9bbc3  T3b.2  Pph21RecapService/Request/Controller, rute hr.view, VERIFICATION_NOTE, views/rekappph21.js,
                NAV, SHELL 10, Pph21RecapTest 14
75df8f2  T3b.3  sapuan kejujuran + paku NTPN/TER (DjpFormatsTest 15), CONVENTIONS §39, PANDUAN, ONBOARDING, ledger
d7cde2b  bukti  harness S38/S38m, results-phase-3.json BERDASARKAN KUNCI (10 → 12), 4 PNG
(commit ini)  laporan
```

Skema: **tidak ada migrasi**. `git diff --stat main...HEAD` di ujung cabang sebelum laporan ini:
46 berkas, 18 ditambah (A) + 28 diubah (M), +3.596/−54 baris.
