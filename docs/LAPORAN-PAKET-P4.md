# Laporan Paket P4 — Mandor & upah borongan

Branch: feat/p4 · Commit: backend c5b37c2; lane cetak+SPA & dokumentasi = commit yang
membawa laporan ini (HEAD dasar merge P3 ffebd6e) · Tanggal: 2026-08-29

Mandor menjadi apa yang selama ini bukan: **jenis vendor** (`prc_vendors.vendor_type`
supplier|subcontractor|mandor|rental, diisi dari `is_subcontractor` yang dipertahankan dan
di-*deprecated*). **SP3 Induk** (`scm_labor_contracts`, mask `SP3`) memberi upah borongan
kontraknya — baris volume × tarif upah, PPh final UMKM 0,5% (PP 55/2022) di-snapshot per
asumsi #3, skema `pph21_ter` disiapkan sebagai pintu 422 yang jujur. **Opname mandor**
(`scm_labor_claims`, mask `OPM`) mengukur volume per periode dengan plafon keras per baris,
memotong kasbon lewat seam KasbonService, dan menjadi tagihan AP lewat kolom baru
`fin_ap_bills.labor_claim_id`. Dua formulir rumah baru: **F/CVM** (CV mandor) dan **F/RU**
(rekap upah per proyek per periode) — katalog cetak 53 → 55.

Paket ini dikerjakan dua jalur dalam satu branch: **jalur backend** (skema, service,
registri, seeder, 34 uji — sudah di c5b37c2) dan **jalur cetak+SPA+dokumentasi** (NAV,
layar vendor `vendor_type`, kolom kejujuran kode kasbon, dan seluruh suntingan dokumen di
laporan ini).

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.5 Vendor master — "mandor bukan tipe vendor; CV Mandor ⬜" | 🟡 | ✅ | migrasi `2026_08_29_000867` + `Vendor::booted` (sinkron dua kolom); `VendorDocumentType::CvMandor`; `VendorTypeTest` (8 uji) |
| 3.5 SPK Mandor / SP3 Induk / opname mandor | ⬜ | ✅ | `scm_labor_contracts`/`_items`, `scm_labor_claims`/`_items`; `LaborContractTest` (6), `LaborClaimTest` (9) |
| 3.10 Opname mandor / rekap upah | ⬜ | ✅ | plafon volume `LaborClaimService::assertWithinItemQty` + guard basi saat approve; F/RU `PrintableDocuments::subcontract()`; `LaborFormPrintTest` (3) |
| 3.11 Upah tenaga kerja — separuh mandor | 🟡 | 🟡 (mandor ✅, harian tetap ⬜) | `ApBillService::createFromLaborClaim` → jurnal + potongan kasbon; `LaborClaimBillTest` (8) |
| Cetak F/CVM + F/RU (katalog 53 → 55) | ⬜ | ✅ | `PrintableDocuments::procurement()` / `::subcontract()`; `PrintCatalogueBespokeTest`, `PrintFormReachabilityTest` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **Asumsi #3 DIPAKAI, dan keputusan pemiliknya formal masih ⏳: mandor dipotong PPh
  final, sebagai vendor.** "PPh final"-nya adalah **PPh final UMKM 0,5% (PP 55/2022
  Pasal 56, melanjutkan PP 23/2018)** — bukan tarif jasa konstruksi PP 9/2022
  (`PphConstructionScheme`), karena mandor borongan perorangan bukan penyedia jasa
  konstruksi bersertifikat. Tarifnya di `config('erp.tax.pph_final_umkm_rate')`, barisnya
  `fin_taxes` `PPH4A2-UMKM` → 2-1230 (TaxSeeder; `object_code` sengaja kosong — kolom
  petugas e-Bupot, aturan yang sama dengan baris PP 9/2022), dan di-snapshot per SP3.
  **`pph_scheme` konfigurabel per kontrak agar pembalikan murah**: enum `LaborPphScheme`
  memuat `pph21_ter`, tetapi memilihnya adalah 422 *"belum diaktifkan"* yang menunjuk
  `Modules\HrPayroll\Services\Pph21TerService` (TER PMK 168/2023) sebagai mesin yang akan
  dipakai — pintu yang belum dibangun, bukan angka yang diam-diam salah
  (`LaborContractService::assertSchemeActive`; roadmap: *"siapkan cabangnya, jangan
  implementasikan keduanya penuh"*).
- **Mandor IKUT dikenai penyempitan K3L/pakta P0-E — keputusan P4 yang didokumentasikan
  (roadmap diam soal mandor).** Alasan P0-E — "mengirim pekerjanya ke site" — adalah
  persis seluruh bisnis seorang mandor borongan; membebaskannya berarti gerbang menahan
  CV subkon ber-akta tetapi meloloskan rombongan tukang tanpa komitmen K3L apa pun.
  F/CVM menambah CV Mandor sebagai lembar **kualifikasi**, bukan pengganti komitmen
  keselamatan — CV menjawab "siapa dia", K3L menjawab "bagaimana orang-orangnya bekerja
  dengan selamat". (`VendorQualificationService::sendsWorkersToSite`; perilaku untuk
  `vendor_type=subcontractor` identik dengan perilaku lama `is_subcontractor=true` —
  `VendorTypeTest` memakukan keduanya.)
- **`fin_ap_bills.labor_claim_id` adalah KOLOM BARU, bukan pemakaian ulang
  `subcontract_claim_id`.** Satu kolom yang menunjuk dua tabel tanpa diskriminator tak
  bisa diaudit — setiap pembaca `subcontract_claim_id` hari ini men-join ke
  `scm_progress_claims` dan akan diam-diam membaca opname subkon orang lain ketika id-nya
  kebetulan sama (alasan lengkap di migrasi `2026_08_29_001125`).
- **Plafon opname dikunci pada id baris kontrak (`labor_contract_item_id`) — pelajaran P3
  dijawab, bukan disalin.** Footgun P3 (id baris BOQ mati saat `copyVersion`, riwayat
  lenyap) tidak berlaku di sini karena baris SP3 **tidak punya jalur regenerasi apa pun**
  setelah approved; id barisnya justru kunci yang paling jujur. Guard basi tetap berjalan
  ulang atas data hidup saat approve (pola `ClaimService` subkon).
- **Potongan kasbon: klaim hanya memeriksa dan mencatat NIAT; faktanya terjadi saat
  tagihan AP disetujui.** Jurnal tagihan mengkredit **1-1370 Piutang Karyawan** (bukan
  1-1500 Uang Muka Proyek — uangnya keluar lewat laci kas kecil ke karyawan, bukan lewat
  uang muka vendor), dan `KasbonService::offsetAgainstWageBill` / `releaseWageOffset`
  (seam terdokumentasi Finance) mencatat/mengembalikan offset-nya di dalam transaksi yang
  sama. Tidak ada baris pertanggungjawaban yang dirakit tangan; `settle()` kuitansi
  menjadi sadar-offset. Kasbon yang habis oleh offset langsung SETTLED.
- **Potongan kasbon hanya untuk kasbon proyek SP3 yang sama** — uang muka site A tidak
  dipulihkan dari upah proyek B; keputusan P4 yang didokumentasikan (roadmap hanya
  berkata "tautan fin_kasbons"). Empat fakta yang membuat potongan boleh dicatat ada di
  docblock `LaborClaimService::assertKasbonDeductible`.
- **SP3 tanpa ambang direktur dan tanpa gerbang anggaran RAP — sengaja tidak dibangun**
  (perilaku tak terarah; dua-duanya pertanyaan pemilik, lihat "Yang sengaja tidak
  dikerjakan").

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

| Migrasi | Tabel | Catatan |
|---|---|---|
| `2026_08_29_000867` | `prc_vendors.vendor_type` (+index) | String default `'supplier'`, lalu `UPDATE … WHERE is_subcontractor = true AND vendor_type = 'supplier'` → `'subcontractor'`. **Restatement fakta yang sudah ada di barisnya — bukan fakta akuntansi baru**, itulah yang membuatnya sah di bawah aturan forward-only. Backfill dijaga agar up() yang berjalan ulang tak pernah menimpa baris yang sudah diketik mandor/rental. `is_subcontractor` DIPERTAHANKAN (deprecated; 18 pembaca lama) dan disinkronkan satu hook `Vendor::booted`. Aman di MySQL: satu kolom ber-default + satu UPDATE idempoten. |
| `2026_08_29_000980` | `scm_labor_contracts`, `scm_labor_contract_items` (baru) | `vendor_id`/`project_id`/`boq_item_id` lintas-modul **tanpa** `constrained()`; di dalam modul pakai constraint. `pph_scheme` string + `pph_rate` snapshot. Uraian/qty/satuan/tarif baris adalah kolom sendiri — revisi BOQ tidak mengubah SP3. |
| `2026_08_29_000990` | `scm_labor_claims`, `scm_labor_claim_items` (baru) | `unique (labor_contract_id, claim_no)`; `kasbon_id` lintas-modul tanpa FK; `qty_prev`/`qty_this`/`amount` tersimpan (roll-forward terkunci id baris kontrak — lihat asumsi). |
| `2026_08_29_001125` | `fin_ap_bills.labor_claim_id` (+index) | Nullable, tanpa FK lintas-modul. Kolom baru, bukan pemakaian ulang — alasannya di komentar migrasinya. |
| `2026_08_29_001126` | `fin_kasbons.wage_offset_total` | Decimal default 0. Sisa kasbon = `amount − wage_offset_total`; `settle()` mengkredit 1-1370 hanya sebesar sisa. Baris lama semuanya 0 = fakta, bukan tebakan. |

Penomoran baru di `config('erp.documents')`: `SP3/{Y}/{RM}/{N4}` (SPK tetap milik
subkon), `OPM/{Y}/{RM}/{N4}` (CLM milik opname subkon, OPN milik opname owner) —
`SettingService::DOCUMENT_LABELS` ikut, `DocumentFormatValidationTest` 50 → 52.

Registri yang bertambah: `ApprovableDocuments` (SP3 mandor, opname mandor — notifikasi
otomatis ikut), `PrintableDocuments::procurement()` (F/CVM `cv-mandor`) dan
`::subcontract()` (F/RU `rekap-upah`, mendatar). Config pajak baru:
`erp.tax.pph_final_umkm_rate` (0,5). Seeder: VND-0006 Mandor Harjo Wibowo (K3L + pakta +
CV mandor), `SP3/2026/III/0001` approved (Rp 261,6 jt) + `OPM/2026/III/0001` approved —
kanon di CONVENTIONS §8.

## Uji

- **baru: 34** (DoD ≥ 12 terlampaui).
  `LaborContractTest` (6) · `LaborClaimTest` (9) · `LaborClaimBillTest` (8) ·
  `VendorTypeTest` (8) · `LaborFormPrintTest` (3).
  Fixture bersama: `tests/Unit/Subcontract/LaborFixtures.php`.
- **lama yang diubah: 2.**
  - `DocumentFormatValidationTest::SHIPPED_DOCUMENT_TYPES` 50 → 52 (+SP3/OPM) — angka
    yang memang dipaku untuk berubah; kenaikannya menambah **2 kasus data-provider**.
  - `PrintCatalogueBespokeTest` `assertCount(53)` → `55` — dua formulir registri baru.
- **suite penuh (jalur backend, pada c5b37c2): OK (3.410 uji, 15.466 asersi)** —
  aritmetikanya tutup persis: 3.374 (pembuka orkestrator) + 34 + 2 data-provider = 3.410.
- **suite penuh (jalur cetak+SPA+dokumentasi, tree ini): OK (3.410 uji, 15.468 asersi,
  7 menit 52 detik)** — `vendor/bin/phpunit` tanpa saringan, setelah seluruh suntingan
  jalur ini. **Dua asersi lebih banyak dari angka backend (15.466), dan keduanya
  terpertanggungjawabkan**: `NavRouteRegistryTest` ber-asersi satu kali per entri NAV,
  dan jalur ini menambah dua entri (SP3 Mandor, Opname Mandor). `vendor/bin/pint
  --dirty --test`: passed. Keseimbangan kurung `schema.js`/`enums.js`/`lookup.js`/
  `cells.js`/`views/detail.js` diperiksa skrip sekali pakai (delta nol terhadap HEAD;
  surplus `[` +1 pada schema.js pra-ada — artefak regex literal pada pengupas skrip,
  bukan kurung yang benar-benar timpang).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Belum dijalankan pada tree ini (langkah 7 protokol butuh `migrate:fresh --seed` pada tree
bersama; milik orkestrator — pola yang sama dengan P3). Pesan yang dijanjikan, dikutip
dari sumbernya dan **diuji** oleh uji di sampingnya:

- Skema PPh yang belum dibangun (`LaborContractService::assertSchemeActive`,
  `LaborContractTest`):
  > `Skema PPh 21 (TER) untuk upah mandor belum diaktifkan; SP3 saat ini memakai PPh final UMKM 0,5% (PP 55/2022) sesuai asumsi #3. Bila pemilik memutuskan PPh 21, jalur ini akan memakai mesin payroll (Pph21TerService).`
- Vendor bukan mandor (`LaborContractService::mandorOrFail`, `LaborContractTest`):
  > `Vendor {kode} ({nama}) bukan vendor bertipe mandor; SP3 hanya dapat dibuat untuk mandor. Ubah jenis vendornya di master vendor, atau pakai SPK subkon untuk subkontraktor.`
- Plafon volume per baris (`LaborClaimService::assertWithinItemQty`, `LaborClaimTest`):
  > `Volume {x} {satuan} pada baris "{uraian}" melebihi sisa SP3 {y} {satuan} (qty kontrak {q}, sudah di-opname {p}).`
- Potongan melebihi sisa kasbon (`LaborClaimService::assertKasbonDeductible`,
  `LaborClaimTest`):
  > `Potongan kasbon {x} melebihi sisa kasbon {kode} ({y}).`
- Netto tidak boleh minus (`assertKasbonDeductible`, `LaborClaimTest`):
  > `Potongan kasbon {x} melebihi upah yang terbayarkan pada opname ini ({y}); netto tidak boleh minus — sisanya dipotong pada opname berikutnya.`
- Kasbon proyek lain (`assertKasbonDeductible`, `LaborClaimTest`):
  > `Kasbon {kode} milik proyek lain; potongan upah hanya untuk kasbon proyek SP3 ini.`
- Guard basi saat approve (`LaborClaimService::approve`, `LaborClaimTest`):
  > `Volume approved baris "{uraian}" kini {x} {satuan}, bukan {y} seperti saat opname {kode} disusun; ubah dan ajukan ulang opname ini.`
- Tagihan ganda / belum approved (`ApBillService::createFromLaborClaim`,
  `LaborClaimBillTest`):
  > `Opname mandor {kode} berstatus {status}; hanya opname yang sudah disetujui yang dapat ditagihkan.` · `Tagihan atas opname mandor {kode} sudah ada.`
- Pembatalan yang jujurnya menolak (`KasbonService::releaseWageOffset`,
  `LaborClaimBillTest`):
  > `Kasbon {kode} sudah dicap pada pembayaran pengisian ulang kas kecil; potongan upahnya tidak dapat dibuka kembali dari sini — koreksi lewat jurnal.`

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- **PANDUAN §8 intro** — "Empat layar" → enam; **§8.9 (baru)** SP3 Mandor (tiga
  perbedaan yang disengaja dari SPK subkon, gerbang prakualifikasi yang berbunyi dua
  kali — saat membuat DAN saat mengajukan atas data hidup, tabel skema PPh dengan
  kutipan penolakan `pph21_ter`, kolom ID yang penting); **§8.10 (baru)** Opname Mandor
  (volume periode
  ini — bukan kumulatif, matematika kolom tersimpan, sembilan pesan 422 kata demi kata,
  sisi penagihan, potongan kasbon menjadi fakta saat tagihan disetujui, F/RU dan aturan
  tanpa-totalnya).
- **PANDUAN §5.2** — layar vendor: kolom/saringan/isian **Jenis vendor** menggantikan
  centang Subkontraktor; bullet "tiga kolom yang menggerakkan uang" diperbarui dengan
  penolakan SP3; tombol **`Cetak CV Mandor`** (F/CVM). **§5.3** — jenis `CV Mandor` di
  daftar isian, dan **daftar jenis yang ternyata sudah basi dua paket dilengkapi**
  (Komitmen K3L / Pakta Integritas absen dari prosa sejak P0-E — lihat Deviasi baru #2).
- **PANDUAN §5.9** — bantuan formulir AP dikutip ulang (kini menyebut opname mandor);
  baris **Dari opname mandor** pada tabel kolom saat-membuat (lima → enam).
- **PANDUAN §10.7** — "Jalan pulang kedua: potongan upah mandor": kasbon Selesai tanpa
  kuitansi, `settle()` sadar-offset, dua penolakan pembatalan.
- **PANDUAN §1** (tabel menu) — baris Subkontrak +2 layar. **§2.8** dan **§13.3** — 53 →
  55; baris F/CVM (Pengadaan) dan F/RU (Subkontrak); **§13.5** — dua baris kejujuran
  (F/CVM: 8 baris bergaris untuk pengalaman di luar sistem, riwayat SP3 hanya yang
  disaksikan sistem; F/RU: tanpa baris total, status per baris, periode tanpa opname
  tidak punya baris).
- **PANDUAN-ADMINISTRATOR §2 (Core)** dan **§9.1** — 53 → 55 (46 → 48 registri); baris
  `prc.view` 7 → 8, `scm.view` 4 → 5 (tabel per modul kini berjumlah 55). **§9.3** —
  "Tiga belas dari 53 lanskap" → **empat belas dari 55** (F/RU mendatar; diverifikasi
  terhadap kode: 12 `'orientation' => 'landscape'` di `PrintableDocuments` + 2 di
  `FormPrintService`), F/CVM masuk daftar potret.
- **CONVENTIONS §8** — kanon VND-0006 Mandor Harjo Wibowo (+ register dokumennya),
  `SP3/2026/III/0001`, `OPM/2026/III/0001`, dan alasan opname demo tanpa potongan kasbon.
- **ARCHITECTURE.md** — blok panah P4: Subcontract → Procurement (gerbang mandor),
  Subcontract → Estimation (baris BOQ opsional), Subcontract ┈┈▶ `fin_kasbons` (baca
  niat), Finance → Subcontract (`labor_claim_id`; tulis offset lewat seam KasbonService).
- **LAPORAN-DEVIASI v2** — empat baris diperbarui (3.5 vendor master ✅, 3.5 SP3 ✅,
  3.10 opname mandor ✅, 3.11 upah 🟡 dengan mandor ✅) — atas instruksi orkestrator;
  P0–P3 membiarkan baris-barisnya untuk pemilik, jadi sebutkan ini bila pemilik menagih
  konsistensi. **README.md** — tidak ada modul baru, tidak ada baris baru; angka uji di
  README belum disegarkan (basi sebelum dibaca — alasan P3).

## Yang sengaja tidak dikerjakan, dan mengapa

- **Ambang direktur dan gerbang anggaran RAP untuk SP3.** Roadmap P4 tidak menyebut
  keduanya untuk SP3, dan membangunnya tanpa arahan adalah perilaku tak terarah: ambang
  Rp 200 juta SPK lahir dari keputusan pemilik tentang SPK, dan sisi RAP mana yang diadu
  (upah? subkon?) adalah pertanyaan anggaran yang belum dijawab siapa pun. Dua-duanya
  murah untuk ditambahkan begitu pemilik memutuskan — pertanyaannya tercatat di bawah.
- **Pemilih kasbon (lookup) pada formulir opname — ID kasbon tetap diketik mentah.**
  Preseden P3 (`boqItems`): endpoint daftar kasbon dijaga `fin.view` sementara layar
  opname dijaga `scm.*` — memberi pemilih berarti memberi pembaca `scm` isi laci kas
  kecil, keputusan izin backend yang bukan milik jalur cetak+SPA. Layar membawa bantuan
  yang menyebut dari mana angkanya dibaca, server menolak kasbon yang salah dengan 422
  bernama, dan begitu tersimpan, layar menampilkan **kode kasbonnya** (bukan hanya
  angka) di daftar maupun panel Terkait.
- **Skema `pph21_ter` tidak diimplementasikan** — sengaja, per roadmap ("siapkan
  cabangnya"). Yang ada: nilai enum, kolom, dan 422 yang menunjuk `Pph21TerService`.
- **Smoke test curl (langkah 7)** — butuh `migrate:fresh --seed` pada tree bersama;
  milik orkestrator.
- **Angka uji di `README.md`** — berubah lagi begitu orkestrator meng-commit.
- **`prj_projects` tidak diberi relasi balik ke SP3** — halaman proyek tidak menampilkan
  SP3 proyeknya; daftar SP3 tersaring proyek sudah menjawab kebutuhan yang sama lewat
  saringan Proyek.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **Empat celah SPA yang dikapalkan jalur backend dan ditutup jalur ini** — layar baru
   tanpa NAV (terjangkau hanya lewat URL — persis lubang yang `NavRouteRegistryTest`
   sebut "invisible to exactly the people it was built for", walau ujinya hanya menguji
   arah sebaliknya), formulir vendor tanpa isian `vendor_type` (vendor mandor mustahil
   dibuat dari SPA), enum `vendorDocumentType` SPA tanpa `cv_mandor` (CV mandor mustahil
   direkam dari SPA — F/CVM selamanya mencetak register tanpa CV), dan dialog `Ajukan`
   SP3 tanpa kotak alasan override — padahal `LaborContractController::submit` menjalankan
   ulang gate atas data hidup dan hanya membaca alasan dari payload submit: SP3 yang
   mandornya memburuk di antara draf dan pengajuan TIDAK PERNAH bisa diajukan dari SPA
   (jebakan yang persis dikomentari entri SPK subkon; polanya disalin ke sini).
   Semuanya konfigurasi `schema.js`/`enums.js`; tidak satu pun uji yang bisa merah
   karenanya.
2. **PANDUAN §5.3 tidak pernah menyebut jenis dokumen K3L/pakta di daftar isiannya** —
   basi sejak P0-E menambahkan keduanya ke enum; prosa "Jenis (wajib: NIB / … /
   Lainnya)" melewatkannya. Dilengkapi di paket ini bersama `cv_mandor`. Kerabat
   pelajaran P3 #8: paket yang menambah nilai enum harus mencari **daftar-daftar lama**
   yang mengutip enum itu, bukan hanya menulis bab baru.
3. **Kolom `sub` diabaikan renderer sel uang** (`cells.js` case `'currency'`) — deklarasi
   `sub:` pada kolom currency mana pun diam-diam tidak digambar. Diperbaiki generik di
   paket ini (komposisi yang sama dengan case default) karena aturan kejujuran P4
   ("potongan kasbon menyebut kode kasbonnya") membutuhkannya; tanpa perbaikan itu
   dokumentasi yang menjanjikan kode kasbon di daftar akan bohong.
4. **`LaborClaimResource` tidak memuat kode kasbon** — layar hanya bisa menunjukkan
   `kasbon_id` mentah. Ditambah `kasbon {id, code}` (whenLoaded) + eager-load di
   controller; panel Terkait dan sub-kolom daftar kini menyebut kodenya.
5. **Label Indonesia untuk kunci-kunci P4 belum ada di peta label detail generik**
   (`views/detail.js`) — tanpa entri, `titleize()` jatuh ke "Labor Contract" / "Kasbon
   Id" pada layar berbahasa Indonesia (kelas kesalahan yang peta itu sendiri
   komentari pada `cancelled_by`). Ditambah di paket ini.
6. **Empat berkas bersama masih gagal `pint --test` seluruh repo** (`bootstrap/app.php`,
   `bootstrap/providers.php`, `database/seeders/ProductionSeeder.php`,
   `database/factories/UserFactory.php`) — pra-ada, dilaporkan P3 (#4), §0.3 melarang
   paket menyentuhnya; masih menunggu satu kali `pint` oleh yang berwenang.
7. **Pertanyaan pemilik yang disurfakan paket ini** (untuk Bagian 10 Laporan Deviasi):
   (a) asumsi #3 — `final_umkm` hidup, `pph21_ter` pintu; bila pemilih memilih PPh 21,
   jalurnya sudah disiapkan; (b) haruskah SP3 ikut ambang direktur dan/atau gerbang
   anggaran RAP-upah? Keduanya sengaja belum dibangun.
