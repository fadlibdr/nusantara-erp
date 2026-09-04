# Laporan Paket P5 — Alat sewa & PPK berbasis periode

Branch: feat/p5 · Commit: backend 12fad95; lane cetak+SPA 5a49ee2; lane dokumentasi =
commit yang membawa laporan ini (HEAD dasar merge P4 ef9bd1e) · Tanggal: 2026-08-30

Alat sewa menjadi warga register aset (`ast_assets.ownership` owned|rented — lessor,
tarif, basis, periode; **tanpa harga perolehan, tanpa penyusutan, tanpa pelepasan**),
dan komitmen uangnya mendapat dokumennya sendiri: **PPK** (`prc_work_orders`, mask
`PPK`, Approvable penuh) — baris tarif × basis (per_bulan / per_hari_8jam / per_jam) ×
**plafon** `qty_periods`, baris per_jam wajib menunjuk alatnya. **Tagihan per periode**
(`prc_work_order_billings`, mask `PPKB`) menurunkan kuantitas dari register hour-meter
(delta DALAM periode) atau kalender — tidak pernah diketik — lalu menjadi tagihan AP
lewat kolom baru `fin_ap_bills.work_order_billing_id`, kategori biaya **Alat**. Satu
formulir rumah baru: **F/PPK** (lembar plafon komitmen) — katalog cetak 55 → 56.
**Rekap Tagihan Alat** = laporan (sengaja tanpa formulir cetak); **Evaluasi Sewa vs
Beli** = layar baca-saja yang tidak menyimpan kesimpulan.

Paket ini dikerjakan tiga jalur dalam satu branch: **backend** (skema, service,
registri, seeder, 31 uji — 12fad95), **cetak+SPA** (F/PPK, kejujuran kartu aset sewa,
NAV, dua layar laporan, guard dispose alat sewa, 13 uji — 5a49ee2), dan
**dokumentasi** (seluruh suntingan dokumen di laporan ini + smoke test langkah 7 +
satu tambalan `schema.js` — Deviasi baru #8).

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.5 PPK/SPK alat & jasa per periode — "tanpa WO berbasis periode; alat sewa tak bisa masuk `ast_assets`" | 🟡 | ✅ | migrasi `2026_08_29_000544` (ownership) + `000868`/`000869`; `WorkOrderService` (gate vendor rental/supplier, per_jam wajib aset); `WorkOrderTest` (5), `AssetOwnershipTest` (9) |
| 3.5 Monitoring SPK/PPK; evaluasi alat — "sewa-vs-beli ⬜; alat sewa tak terlog" | 🟡 | ✅ | tabel "Tagihan periode yang sudah dibuat" di halaman PPK; utilisasi memuat `ownership` (`DeploymentService`); `RentVsOwnService` + layar baca-saja; `RentVsOwnTest` (4) |
| 3.6 Monitoring alat, sewa per vendor — "milik sendiri saja" | 🟡 | ✅ | `ast_assets.ownership` + kolom sewa; alat sewa ikut mobilisasi/log jam/utilisasi; `AssetOwnershipTest::test_utilisasi_memuat_aset_sewa` |
| 3.10 Rekap tagihan alat — "tak terikat periode sewa" | 🟡 | ✅ | `WorkOrderBillingService::recap` + layar Rekap Tagihan Alat (kolom AP jujur kosong); `WorkOrderBillingTest` (9) |
| Cetak F/PPK (katalog 55 → 56) | ⬜ | ✅ | `PrintableDocuments` entri `ppk-alat-jasa`; `WorkOrderPrintTest` (6), `PrintCatalogueBespokeTest` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **"Vendor rental/jasa" roadmap dibaca sebagai `vendor_type` rental ATAU supplier** —
  pemasok jasa terdaftar sebagai supplier hari ini; tidak ada tipe "jasa". Mandor dan
  subkontraktor DITOLAK dengan kalimat yang menunjuk pintunya (SP3/SPK): dua pintu
  untuk satu komitmen tidak bisa diaudit. Gate prakualifikasi P0-E ikut berjalan;
  penyempitan K3L/pakta TIDAK menjerat vendor rental/supplier
  (`VendorType::sendsWorkersToSite` — hanya yang mengirim pekerja ke site).
- **Aturan batas per_jam: delta DALAM periode saja** (pembacaan terakhir − pertama
  yang bertanggal di dalam periode). Konsekuensi jujurnya didokumentasikan sampai ke
  PANDUAN: jam antara pembacaan terakhir pra-periode dan pembacaan pertama dalam
  periode tidak tertagih di mana pun — jam tak terukur, dan menagih jam tak terukur
  berarti mengarang angka. Contoh dipin uji: 1.200,0 → 1.207,5 → 1.213,0 = 13,0 jam;
  pembacaan 1.195,0 pra-periode tidak bocor masuk (docblock
  `WorkOrderBillingService`).
- **per_bulan menagih bulan kalender utuh** (mulai tanggal 1, berakhir akhir bulan);
  prorata harian atas tarif bulanan adalah angka yang tidak pernah disepakati siapa
  pun. Basis kalender boleh menagih di muka; per_jam dengan sendirinya tidak bisa.
- **Periode tagihan TIDAK dipagari tanggal mulai/selesai PPK — sengaja**: plafonnya
  yang memagari uang, bukan kalender. Karena itu pula F/PPK menggariskan baris
  TANGGAL MULAI/SELESAI yang kosong alih-alih mengarang pagar yang tidak dipegang
  server (§13.5).
- **Tagihan periode (PPKB) sengaja BUKAN Approvable** — angkanya turunan register dan
  kalender; rupiahnya ber-maker-checker di tagihan AP yang dibuat darinya. Anti
  tagih-ganda empat lapis: periode saling-lepas per PPK (lockForUpdate), pasangan
  pembacaan jatuh di maksimal satu periode, plafon roll-forward terkunci id baris
  (argumen keamanan di migrasi 000868/000869), satu tagihan AP hidup per billing.
- **PPK tanpa ambang direktur berjenjang** — alasan yang sama dengan SP3 (P4):
  ambang Rp 200 jt lahir dari keputusan pemilik tentang PO/SPK; menerapkannya ke PPK
  tanpa arahan adalah perilaku tak terarah. Pertanyaan pemilik tetap terbuka.
- **`fin_ap_bills.work_order_billing_id` adalah KOLOM BARU** — cermin
  `labor_claim_id` (migrasi 2026_08_29_001125, P4): satu FK per tabel sumber, tanpa
  pemakaian ulang kolom tanpa diskriminator. Alasan di migrasi `2026_08_29_001127`.
- **Kepemilikan `createOnly`** — beli-putus alat sewa (kapitalisasi) adalah peristiwa
  akuntansi, bukan suntingan register; server menolak perubahan ownership lewat
  update.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

| Migrasi | Tabel | Catatan |
|---|---|---|
| `2026_08_29_000544` | `ast_assets` | `ownership` string default `'owned'` (+index) — **backfill semua baris lama → owned adalah restatement fakta** (pola `vendor_type` P4); kolom sewa `vendor_id` (bare id + index, vendor hidup di Procurement), `rental_rate`, `rate_basis`, `rental_start/end`. `acquisition_date`/`acquisition_cost`/`book_value` **dilonggarkan NULLABLE lewat `change()` pertama repo** — melonggarkan tidak menyentuh nilai tersimpan; diuji jalur backend pada SALINAN sqlite berisi data (6 aset, jumlah utuh). Aman di MySQL: `MODIFY` ke nullable adalah metadata-only untuk pelonggaran. |
| `2026_08_29_000868` | `prc_work_orders`, `prc_work_order_items` (baru) | `vendor_id`/`project_id` lintas-modul tanpa `constrained()`; baris `asset_id` (bare id) + description + rate + basis + `qty_periods` (plafon); snapshot `ppn_rate`. Argumen kunci plafon-roll-forward-terkunci-id-baris ditulis di migrasi ini. |
| `2026_08_29_000869` | `prc_work_order_billings`, `prc_work_order_billing_lines` (baru) | `code` unique (mask PPKB); `billing_no` urutan per PPK (gaya `claim_no` opname); `meter_start`/`meter_end` tersimpan per baris per_jam. Argumen anti-tagih-ganda empat lapis ditulis di migrasi ini. |
| `2026_08_29_001127` | `fin_ap_bills.work_order_billing_id` (+index) | Nullable, tanpa FK lintas-modul. Kolom baru, bukan pemakaian ulang. |

Penomoran baru di `config('erp.documents')`: `PPK/{Y}/{RM}/{N4}` dan `PPKB/{Y}/{RM}/{N4}`
— `SettingService::DOCUMENT_LABELS` ikut, `DocumentFormatValidationTest` 52 → 54.

Registri yang bertambah: `ApprovableDocuments` (PPK — notifikasi otomatis ikut),
`PrintableDocuments::procurement()` (F/PPK `ppk-alat-jasa`, potret). Seeder (kanon di
CONVENTIONS §8): VND-0007 PT Alat Berat Nusantara (rental, PKP), AST-0007 Excavator
Doosan DX225LCA sewa (kembaran dua seeder — pola menara GSP-T1), register 3.240,0 →
3.375,5, `PPK/2026/VI/0001` approved (Rp 585 jt plafon) + `PPKB/2026/VII/0001`
(135,5 jam + 1 bulan = Rp 69,2 jt). Tagihan AP-nya sengaja tidak diseed — alur demo.

## Uji

- **baru: 44** (DoD ≥ 10 terlampaui) — 42 metode + 2 kasus data-provider.
  Jalur backend (31): `WorkOrderTest` (5) · `WorkOrderBillingTest` (9) ·
  `AssetOwnershipTest` (7) · `RentVsOwnTest` (4) · `ApBillWorkOrderSeamTest` (4) ·
  +2 data-provider `DocumentFormatValidationTest`. Jalur cetak+SPA (13):
  `WorkOrderPrintTest` (6) · `AssetPrintTest` (+5) · `AssetOwnershipTest` (+2:
  saringan ?ownership=, guard dispose alat sewa).
- **lama yang diubah: 2.**
  - `DocumentFormatValidationTest::SHIPPED_DOCUMENT_TYPES` 52 → 54 (+PPK/PPKB) —
    angka yang memang dipaku untuk berubah; +2 kasus data-provider.
  - `PrintCatalogueBespokeTest` `assertCount(55)` → `56` (F/PPK); pesannya kini
    "katalog = 49 registri + 7 formulir rumah proyek".
- **suite penuh (jalur backend, 12fad95): OK (3.449 uji, 15.665 asersi)** — 3.418
  (pasca-merge P4) + 29 metode + 2 data-provider = 3.449.
- **suite penuh (jalur cetak+SPA, 5a49ee2): OK (3.462 uji, 15.734 asersi)** — +13
  metode; `NavRouteRegistryTest` ber-asersi per entri NAV (+5 entri).
- **suite penuh (jalur dokumentasi, tree ini): OK (3.462 uji, 15.734 asersi,
  8 menit 11 detik)** — `vendor/bin/phpunit` tanpa saringan setelah seluruh suntingan
  jalur ini (dokumen + satu tambalan `schema.js`, Deviasi baru #8; keseimbangan
  kurung diperiksa delta-nol terhadap HEAD, tanpa runtime JS di host).
  `vendor/bin/pint --dirty --test`: tidak ada berkas PHP kotor.

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

**Dijalankan dua kali.** Jalur backend menjalankan langkah 7 protokol pada **salinan
sqlite di scratchpad** (bukan db dev bersama — sesi paralel sedang aktif). Jalur
dokumentasi (laporan ini) mengulanginya 30 Agustus 2026, juga pada salinan scratchpad:
`DB_DATABASE=<scratchpad> php artisan migrate:fresh --seed` + `artisan serve` + curl
login `admin@nusantara.test`. **Enam pesan 422 di bawah keluar kata demi kata lewat
HTTP**, dan setiap pesan juga dipaku uji di sampingnya:

- Dispose alat sewa (`AssetDisposalService`,
  `AssetOwnershipTest::test_alat_sewa_tidak_bisa_dihapusbukukan`):
  > `Alat sewa tidak dihapusbukukan — alat milik vendor rental dikembalikan ke pemiliknya, bukan dilepas dari neraca. Akhiri mobilisasinya lalu nonaktifkan masternya.`
- Vendor bukan rental/pemasok (`WorkOrderService::rentalOrSupplierOrFail`,
  `WorkOrderTest`) — dicoba atas VND-0004:
  > `Vendor VND-0004 (CV Karya Sipil Sejahtera) bertipe Subkontraktor; PPK alat & jasa hanya untuk vendor rental atau pemasok jasa. Mandor memakai SP3, subkontraktor memakai SPK.`
- Periode tumpang-tindih (`WorkOrderBillingService::assertPeriodFree`,
  `WorkOrderBillingTest`) — dicoba 15 Jul–14 Agu atas PPK demo:
  > `Periode 2026-07-15 s.d. 2026-08-14 tumpang-tindih dengan tagihan PPKB/2026/VII/0001 (2026-07-01 s.d. 2026-07-31) pada PPK PPK/2026/VI/0001 — satu periode hanya ditagih sekali.`
- Tarif internal pada mobilisasi alat sewa (`DeploymentService::
  assertInternalRateFitsOwnership`, `AssetOwnershipTest`):
  > `Aset AST-2026-0008 adalah alat sewa: biayanya masuk lewat tagihan AP vendor rentalnya (tagihan periode PPK), sehingga tarif internal harian akan membebankan alat yang sama dua kali ke proyek. Kosongkan tarif internalnya.`
- Tagihan AP ganda atas satu billing (`ApBillService::createFromWorkOrderBilling`,
  `ApBillWorkOrderSeamTest`):
  > `Tagihan atas periode PPK PPKB/2026/VII/0001 sudah ada.`
- Kolom perolehan pada alat sewa (`AssetStoreRequest` prohibited_if,
  `AssetOwnershipTest`) — bawaan Laravel, berbahasa Inggris (lihat Deviasi baru #5):
  > `The acquisition cost field is prohibited when ownership is rented.`

Juga diverifikasi lewat HTTP pada seed segar: `PPK/2026/VI/0001` approved Rp 585 jt
dan AST-0007 rented dengan `acquisition_cost`/`book_value` **null** (bukan 0);
**DPP tagihan AP dari billing tidak bisa diketik ulang** — payload `"dpp":999`
menghasilkan BIL/2026/VIII/0002 ber-DPP 69.200.000 + PPN 7.612.000 (snapshot 11%) =
76.812.000; Rekap Tagihan Alat Juli menampilkan billing itu **beserta tagihan AP-nya
begitu dibuat** (sebelumnya kolom AP null — jujur kosong); rent-vs-own: enam aset
owned tanpa jam berkata *"Belum ada jam tercatat pada register — biaya per jam belum
dapat dihitung."*, AST-0007 135,5 jam × Rp 400.000 = Rp 54,2 jt, alat sewa
per_hari_8jam tanpa `rental_start` berkata *"Periode sewa (rental_start) belum diisi —
biaya sewa berjalan belum dapat dihitung."*; katalog cetak admin = **56** dengan
`ppk-alat-jasa`; F/PPK ter-render dengan kop Form F/PPK dan baris TOTAL PLAFON PPK.

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- **PANDUAN §5.14 (baru)** PPK Alat & Jasa (komitmen berplafon, gate vendor + kutipan
  penolakan, dua penolakan baris, tanggal PPK tidak memagari tagihan, F/PPK);
  **§5.15 (baru)** Tagihan Periode PPK (tabel kuantitas per basis, **aturan batas
  per_jam kata demi kata** dengan contoh 13,0 jam, lima penolakan kata demi kata,
  sisi penagihan AP); **§5.16 (baru)** Rekap Tagihan Alat (saringan, kolom AP
  bergaris = belum ditagihkan, kalimat kosong kata demi kata).
- **PANDUAN §5.9** — bantuan formulir AP dikutip ulang (kini menyebut tagihan periode
  PPK); baris **Dari tagihan periode PPK** (enam → tujuh kolom saat-membuat);
  paragraf kategori biaya dilengkapi (opname mandor → Upah, tagihan periode PPK →
  Alat — lihat Deviasi baru #4c).
- **PANDUAN §9** — intro dua bentuk aset; **§9.3** kolom+saringan Kepemilikan, dua
  bagian formulir kondisional, penolakan dua arah (kedua bahasa), dua bentuk halaman
  aset; **§9.4** dialog mobilisasi alat sewa tanpa kotak tarif internal + kutipan
  penolakan; **§9.7** alat sewa tidak pernah ikut run; **§9.8** utilisasi memuat alat
  sewa; **§9.9** guard dispose + jalan pulang sewa; **§9.10** KEPEMILIKAN, NILAI BUKU
  bergaris, kalimat fakta sewa; **§9.11 (baru)** Evaluasi Sewa vs Beli (aritmetika +
  empat kalimat kejujuran kata demi kata).
- **PANDUAN §1.4** — tabel menu: baris Pengadaan +3 layar P5, baris Aset +1; **§2.8**
  dan **§13.3** — 55 → 56; baris F/PPK (Pengadaan); **§13.5** — dua baris kejujuran
  (F/PPK: tanggal bergaris & lembar plafon; F/KA alat sewa: NILAI BUKU bergaris,
  bukan Rp 0). **§13.1** — angka basi "50" diperbaiki (Deviasi baru #4a).
- **PANDUAN-ADMINISTRATOR §2** — blurb Procurement (PPK, tagihan periode, rekap) dan
  Assets (**catatan migrasi ownership: backfill owned, acquisition_cost nullable,
  gate penyusutan**); Core 55 → 56. **§9.1** — 56 formulir (49 registri); baris
  `prc.view` 8 → 9. **§9.3** — "Empat belas dari 56" (F/PPK masuk daftar potret).
- **CONVENTIONS §8** — kanon VND-0007, AST-0007 (pola kembaran dua seeder),
  `PPK/2026/VI/0001`, `PPKB/2026/VII/0001`, dan alasan tagihan AP demo tidak diseed.
- **ARCHITECTURE.md** — blok panah P5 (Assets ┈┈▶ prc_vendors lessor; Procurement ──▶
  Assets baca register; Finance ──▶ Procurement `work_order_billing_id`) + alur
  "Assets (rented)".
- **LAPORAN-DEVIASI v2** — empat baris diperbarui (3.5 PPK ✅, 3.5 monitoring/evaluasi
  ✅, 3.6 monitoring sewa ✅, 3.10 rekap ✅) — mengikuti preseden P4.
  **README.md** — tidak ada modul baru, tidak ada baris baru; angka uji di README
  berubah saat merge (alasan P3/P4).

## Yang sengaja tidak dikerjakan, dan mengapa

- **Formulir cetak untuk Rekap Tagihan Alat.** Roadmap menyebut "laporan"; tidak ada
  tiga pihak yang menandatangani sebuah rekap, dan rupiahnya sudah ber-maker-checker
  di tagihan AP masing-masing. Katalog cetak tidak digelembungkan.
- **Pagar periode billing terhadap start/end PPK** — sengaja; plafonnya yang memagari
  uang (lihat Asumsi). Murah ditambahkan bila pemilik menghendaki kalender ikut
  memagari.
- **Ambang direktur berjenjang untuk PPK** — pertanyaan pemilik, preseden SP3 (P4).
- **Nilai enum `rental` pada `VendorClassification`** — klasifikasi vendor
  (material/jasa/ICT/sipil/ME) menjawab "apa yang ia pasok", bukan "bagaimana ia
  dibayar"; vendor rental demo memakai klasifikasi `jasa` dan `vendor_type` `rental`.
  Bila pemilik ingin klasifikasi tersendiri, itu suntingan enum + master, bukan
  perombakan.
- **Baris P4 `ApBillService::update` untuk `labor_claim_id` dibiarkan apa adanya** —
  P5 menutup DPP-diketik-ulang untuk `work_order_billing_id` (sikap tagihan parsial),
  tetapi mengubah perilaku P4 tanpa mandat bukan milik paket ini. Layak ditinjau
  (Deviasi baru #3).
- **Kapitalisasi (beli-putus) alat sewa** — ownership `createOnly`; peristiwa
  akuntansinya (mengangkat aset ke neraca pada harga beli) belum punya pintu. Bila
  terjadi hari ini: daftarkan baris aset baru `owned`, akhiri master sewanya.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **Dispose alat sewa memposting laba karangan — DITEMUKAN & DITUTUP jalur cetak+SPA.**
   Tanpa guard, "Hapus Buku / Jual" atas alat sewa memposting Dr 1-1300 sebesar nilai
   pelepasan lawan "laba" penuh di 7-1200 — piutang dan laba atas penjualan mesin
   milik vendor rental (harga perolehan NULL, akumulasi 0). Guard di
   `AssetDisposalService` + uji `test_alat_sewa_tidak_bisa_dihapusbukukan`; tombolnya
   ikut tidak digambar untuk rented.
2. **Fixture uji lama memakai classification vendor bebas** — enum
   `VendorClassification` tidak punya nilai `rental`; vendor rental demo dan fixture
   P5 memakai `jasa`. Dicatat sebagai keputusan (lihat "Yang sengaja tidak
   dikerjakan").
3. **`ApBillService::update` membiarkan DPP tagihan `labor_claim` (P4) diketik ulang**
   — P5 menutupnya untuk `work_order_billing`; baris P4 dibiarkan supaya tidak
   mengubah perilaku P4 tanpa mandat. Layak ditinjau pemilik/orkestrator.
   **DITUTUP pasca-P5 (30 Agu 2026, mandat pemilik):** guard yang sama untuk
   `labor_claim_id` — DPP tagihan mandor turunan opname (bruto minus potongan
   kasbon), perbaikannya batalkan-dan-terbitkan-ulang; uji
   `test_dpp_tagihan_opname_mandor_tidak_bisa_diketik_ulang` di
   `LaborClaimBillTest`.
4. **Dokumen basi yang ditemukan dan diperbaiki jalur dokumentasi** (kerabat pelajaran
   P3 #8 / P4 #2 — paket yang menambah layar harus mencari daftar-daftar lama):
   (a) PANDUAN §13.1 masih berbunyi "Ada **50** di antaranya" sementara §2.8/§13.3
   sudah 55 — terlewat sejak dua paket; kini 56 di ketiganya. (b) PANDUAN §1.4
   berbunyi "dua belas kelompok" dan tabelnya tidak memuat baris **Engineering** dan
   **Mutu (QA/QC)** (basi sejak P1) serta tiga layar P2 di baris Pengadaan (BA
   Negosiasi, Keputusan Pemenang, Rencana Pengadaan); dilengkapi — kini empat belas
   kelompok, diverifikasi terhadap `schema.js`. (c) PANDUAN §5.9: paragraf penurunan
   kategori biaya tidak menyebut opname mandor → **Upah** (basi sejak P4); dilengkapi
   bersama baris P5 → **Alat**. (d) PANDUAN-ADMINISTRATOR §8.1 masih berbunyi "Lima
   belas model memakai mesin bersama itu" — basi sejak P0-C; kini 26 (diverifikasi:
   26 model ber-trait `Approvable`, registri `ApprovableDocuments` 28 entri = 26 +
   pembayaran + baseline), dan kalimatnya kini menunjuk registri sebagai sumber
   kebenaran alih-alih mengulang enumerasi yang pasti basi lagi.
5. **Penolakan prohibited_if pada create aset berbahasa Inggris** (bawaan Laravel:
   *"The acquisition cost field is prohibited when ownership is rented."*), sementara
   guard update-nya (`AssetRegisterService::assertFieldsMatchOwnership`) berbahasa
   Indonesia. Kelas yang sama dengan pesan Inggris yang sudah diakui PANDUAN §2.10;
   didokumentasikan apa adanya di §9.3, tidak diubah jalur ini (pesan milik jalur
   backend).
6. **Empat berkas bersama masih gagal `pint --test` seluruh repo** (`bootstrap/app.php`,
   `bootstrap/providers.php`, `database/seeders/ProductionSeeder.php`,
   `database/factories/UserFactory.php`) — pra-ada, dilaporkan sejak P3; §0.3
   melarang paket menyentuhnya.
7. **Pertanyaan pemilik yang disurfakan paket ini**: (a) haruskah PPK ikut ambang
   direktur berjenjang? (b) haruskah kalender PPK ikut memagari periode billing?
   (c) apakah `VendorClassification` perlu nilai `rental`? (d) baris #3 di atas —
   tutup juga DPP-diketik-ulang untuk opname mandor P4?
8. **Dialog `Ajukan` PPK tanpa kotak alasan override — DITEMUKAN & DITUTUP jalur
   dokumentasi.** `WorkOrderController::submit` menjalankan ulang gate
   prakualifikasi atas data vendor hidup dan hanya membaca alasan dari payload
   submit (cermin SPK/SP3), tetapi entri `procurement/work-orders` di `schema.js`
   memakai `approvalActions('prc')` polos — PPK yang vendornya memburuk di antara
   draf dan pengajuan TIDAK PERNAH bisa diajukan dari SPA. Persis kelas celah P4
   yang lane cetak+SPA-nya sendiri tutup untuk SP3; pola submit-action SPK/SP3
   disalin ke entri PPK (konfigurasi `schema.js`; tidak satu pun uji yang bisa
   merah karenanya, keseimbangan kurung diperiksa).
