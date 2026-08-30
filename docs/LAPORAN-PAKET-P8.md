# Laporan Paket P8 — Lintas-modul

Branch: feat/p8 · Commit: **belum dikomit** — ketiga lane (backend penomoran+revisi+tarif,
impor+ekspor, SPA+dokumentasi) bekerja di satu pohon kerja; HEAD `feat/p8` masih `28e96dd`
(merge P7), dan seluruh perubahan P8 berdiri sebagai perubahan yang belum di-stage ·
Tanggal: 2026-08-30

Paket penutup. **`{PROJ}`** menjadikan mask penomoran sadar-proyek: kunci urutan
`core_number_sequences` melebar dari `(type, year)` menjadi `(type, year, scope)` dengan
scope = kode proyek, opsional per jenis lewat Pengaturan — mask tanpa token menerbitkan
nomor **bita-demi-bita seperti sebelumnya**, dan mask ber-`{PROJ}` pada dokumen tanpa
proyek **gagal keras saat mencetak nomor**, bukan mencetak kosong. **`core_rate_history`**
mencatat setiap perubahan tarif PPN & PPh final (siapa, kapan, dari → menjadi) dan hanya
mencatat: snapshot per dokumen tetap sumber kebenaran, dan sebuah uji membuktikan
perubahan tarif di tengah hidup dokumen tidak menggeser satu angka pun. **Impor MPP-XML**
membaca jadwal MS Project (XML, bukan `.mpp` biner; parser DOM bawaan PHP, tanpa
dependensi baru) menjadi pohon WBS + baseline lewat `BaselineService::snapshot` — kurva S
impor keluar dari jalur `PlannedCurve` yang sama dengan EVM — dan **menolak proyek yang
sudah ber-WBS dengan menyebut apa yang ada**. **Empat importer warisan** (laporan harian,
kartu stok, SP3 mandor, progress pay) menumpang registri `document-import` yang ada,
forward-only (tidak ada jurnal/stok/piutang lahir dari unggahan), tercap `import_source`,
dipetakan kolom-demi-kolom di `docs/IMPOR-WARISAN.md` (baru). **Ekspor XLSX** untuk
sepuluh formulir rumah tersering membaca komposisi cetak yang sama
(`FormPrintService::composed`) — sel bergaris di kertas adalah sel **kosong** di Excel,
bukan 0 — dan tombolnya digambar dari flag `xlsx` katalog cetak, satu pemilik daftar
(`FormXlsxExportService::FORMS`). **Revisi generik** (`Revisable`) memberi izin kerja
lapangan, IPP, dan inspeksi mutu pola revisi SDS: revisi = baris baru bernomor baru,
pendahulu tercap dan tetap tercetak, riwayat persetujuan utuh, hanya baris hidup yang
bisa digerakkan.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| D2 Mask penomoran per jenis **per proyek** — *"{RM} ada; tanpa {PROJ}"* | 🟡 | ✅ | migrasi Core `2026_08_30_000192` (scope, unik `(type,year,scope)`); `DocumentNumberService::next` (`{PROJ}` = `prj_projects.code`, gagal keras tanpa proyek); `HasDocumentNumber` meneruskan konteks proyek hanya bila mask memintanya; `DocumentNumberServiceTest` (+4), `HasDocumentNumberTest` (+4), `DocumentFormatValidationTest` (+1); mint tanpa token terbukti tak bergeser (suite penuh + uji tabrakan scoped/unscoped) |
| D5 Tarif bertanggal — *"config + snapshot per dokumen"* | 🟡 | ✅ | migrasi Core `2026_08_30_000193` (`core_rate_history`); `RateHistoryService` (dipanggil dari `SettingService::set`, kunci PPN & PPh final saja — asumsi roadmap); `GET api/core/rate-history` (`core.view`, baca-saja); `RateHistoryTest` (6) — termasuk uji bahwa mengubah tarif TIDAK mengubah angka dokumen yang ada (stance snapshot dipertahankan) |
| D6 (sebagian) Ekspor XLSX formulir — *"ekspor formulir ke XLSX/DOCX ⬜"* | 🟡 | 🟡 **membaik** | `FormXlsxExportService` (10 slug, komposisi = komposisi cetak, sel bergaris = sel kosong); `GET …/print/forms/{form}/{id}/xlsx` (Routes/api.php:94); flag `xlsx` di katalog (`PrintableDocuments::catalogue`); tombol XLSX generik di SPA (`CrossModuleSpaWiringTest`); `FormXlsxExportTest` (4). DOCX tetap ⬜ (lihat *Yang sengaja tidak dikerjakan*) |
| D9 Revisi `Rn` generik | 🟡 | ✅ | trait `Modules/Core/Traits/Revisable.php`; migrasi `000744`/`001360`/`001450`; `revise()` di service ketiga modul + rute `POST {id}/revise` (`{prefix}.create`); `WorkPermitRevisionTest` (5), `IppRevisionTest` (3), `InspectionRevisionTest` (3); SPA: aksi Buat Revisi + kolom "Digantikan" + spanduk pendahulu (`CrossModuleSpaWiringTest`); dokumen berpola sendiri TIDAK disentuh (SDS, baseline, pustaka metode, versi BOQ, revisi penawaran — dipaku di uji sisi-tolak) |
| D12 Importer XLS warisan | 🟡 | ✅ | empat entri baru `ImportableDocuments` (`daily-reports`:746, `stock-cards`:904, `sp3`:960, `progress-pay`:1033); pemetaan kolom di `docs/IMPOR-WARISAN.md`; migrasi `000446`/`000745`/`000991` (kolom `import_source`); fixture korpus di `tests/fixtures/import-warisan/`; `DailyReportImportTest` (2), `StockCardImportTest` (1), `Sp3ImportTest` (1), `ProgressPayImportTest` (2) |
| Kriteria #8 Impor MPP-XML → kurva S | ⬜ | ✅ | `MppXmlImportService` (DOM PHP asli; subset skema didokumentasi di header + fixture `tests/fixtures/mpp-sample.xml`); `POST api/projects/{id}/import-mpp-xml` (`prj.update`); baseline via `BaselineService::snapshot` → kurva S dari `curve()` yang sama dengan EvmService; `MppXmlImportTest` (6); tombol di halaman proyek (`views/project.js`) |
| Kriteria #10 Tiga set data warisan via importer | ⬜ | ✅ (mesinnya) | keempat importer D12 + template unduhan per jenis + `IMPOR-WARISAN.md`. **Memuat set data pemilik yang sebenarnya adalah langkah operasional** — unggah berkasnya lewat layar Impor Dokumen; mesin, aturan, dan dokumentasinya kini ada |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **#3 (PPh mandor: PPh final)** — importer SP3 warisan men-default `skema_pph` kosong ke
  PPh final UMKM, mengikuti asumsi yang sudah dipakai P4; `pph21_ter` tetap ditolak
  "belum diaktifkan".
- **Asumsi roadmap P8 tentang `core_rate_history`**: hanya PPN & PPh final yang dicatat
  (`tax.ppn_rate`, `tax.ppn_headline_rate`, `tax.pph_final_umkm_rate`,
  `tax.pph_final_construction.*`). Tarif BPJS/lembur tidak — konfirmasi bila pemilik
  ingin riwayat untuk kelompok lain.
- **Keputusan yang diambil sendiri (perlu konfirmasi):** `{PROJ}` merender **kode
  proyek** (`prj_projects.code`, mis. `GSP-T1`), bukan id — id tidak berarti di kertas.
  Scope baris lama = `''` (bukan NULL — keunikan atas NULL berbeda per driver).
  Impor MPP-XML **menolak proyek ber-WBS** alih-alih menimpa; bobot daun = porsi durasi.
  Kartu stok warisan mendarat sebagai **opname draf** (mutasi lama tidak diputar ulang);
  SP3 warisan mendarat **tanpa** kolom opname kumulatifnya. Semua tertulis di
  `IMPOR-WARISAN.md` / header service masing-masing.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

| Migrasi | Perubahan | Aman di MySQL dengan data lama? |
|---|---|---|
| Core `2026_08_30_000192` | `core_number_sequences` + kolom `scope` (string 40, default `''`); unik `(type,year)` → `(type,year,scope)` | **Ya** — baris lama menjadi ber-scope-kosong dan counternya berlanjut; unik baru superset unik lama. **Deploy migrasi + kode bersama** (lihat Deviasi baru #1) |
| Core `2026_08_30_000193` | tabel baru `core_rate_history` | Ya — tabel baru |
| Projects `2026_08_30_000744`, Engineering `2026_08_30_001360`, Quality `2026_08_30_001450` | + `revision` (default 0), `superseded_at` (nullable), `superseded_by_id` (nullable, self-ref TANPA FK, ber-index) pada `prj_work_permits` / `eng_work_permits_ipp` / `qc_inspections` | Ya — semua kolom berdefault/nullable; baris lama otomatis "revisi 0, hidup" |
| Inventory `2026_08_30_000446`, Projects `2026_08_30_000745`, Subcontract `2026_08_30_000991` | + `import_source` (string 160, nullable) pada `inv_stock_adjustments`, `prj_daily_reports`+`prj_progress_measurements`, `scm_labor_contracts` | Ya — nullable; NULL = dientri manusia |

## Uji

- baru: **49** — `DocumentNumberServiceTest` +4 & `HasDocumentNumberTest` +4 &
  `DocumentFormatValidationTest` +1 (di berkas lama, murni tambahan);
  `RateHistoryTest` 6; `WorkPermitRevisionTest` 5; `IppRevisionTest` 3;
  `InspectionRevisionTest` 3; `MppXmlImportTest` 6; `DailyReportImportTest` 2;
  `StockCardImportTest` 1; `Sp3ImportTest` 1; `ProgressPayImportTest` 2;
  `FormXlsxExportTest` 4; `CrossModuleSpaWiringTest` 7 (lane SPA: satu-pemilik daftar
  XLSX, reachability importer/MPP/revisi/riwayat tarif, menu impor, sisi-tolak).
  DoD ≥12 terlampaui.
- lama yang diubah: **0 perilaku** — 4 berkas uji lama tumbuh 145 baris penambahan
  (metode baru + satu kolom fixture skema uji), tidak ada asersi lama yang diubah.
- suite penuh: **OK (3.630 uji, 16.763 asersi, 9:08)** — dari 3.581/16.290 (pasca-P7)
  → 3.623/16.605 (pasca lane 1+2, diverifikasi hijau sebelum lane SPA) → angka akhir.
  Keseimbangan kurung 7 berkas JS tersentuh dipindai delta-nol (tanpa runtime JS di
  host): print.js, printcatalog.js, schema.js, detail.js, list.js, settings.js,
  project.js — semua seimbang.

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Terhadap basis data scratch hasil `migrate:fresh --seed` (sqlite hidup **tidak
disentuh** — md5 `57d73e0379e64f4ad9b479ebd768e375` sebelum dan sesudah), login
`admin@nusantara.test` (persetujuan oleh `direktur@nusantara.test` — maker-checker):

- `GET /api/core/print/forms` → **61 baris katalog, 10 ber-`xlsx: true`**:
  laporan-harian, opname-owner, permintaan-pembelian, order-pembelian,
  penerimaan-barang, bon-material, berita-acara-opname, saldo-stok, spk-subkon,
  rekap-upah.
- `GET /api/core/print/forms/laporan-harian/1/xlsx` → 200,
  `application/vnd…spreadsheetml.sheet`, 7.725 B, magic `PK` (xlsx sah). Di luar
  daftar: *"Formulir data-proyek belum tersedia sebagai XLSX; cetak HTML-nya tetap ada.
  Daftar formulir ber-XLSX: laporan-harian, saldo-stok, bon-material,
  penerimaan-barang, berita-acara-opname, permintaan-pembelian, order-pembelian,
  spk-subkon, opname-owner, rekap-upah."*
- `GET /api/core/document-import` → **9 jenis**: quotations, boqs, ahsp, cost-budgets,
  inspection-templates, **daily-reports, stock-cards, sp3, progress-pay**.
- `PUT /api/core/settings {"tax.ppn_rate": 11.5}` lalu
  `GET /api/core/rate-history?key=tax.ppn_rate` →
  `tax.ppn_rate 11 -> 11.5 oleh Administrator Sistem`.
- Siklus revisi IKL: buat → `IKL/2026/VIII/0001`; ajukan; setujui (direktur);
  `POST …/1/revise` → `IKL/2026/VIII/0002, rev 1, draft`; `GET …/1` →
  `status approved · is_current false · superseded_by_code IKL/2026/VIII/0002`.
  Revisi ulang baris lama (422): *"Izin kerja lapangan IKL/2026/VIII/0001 telah
  digantikan revisi IKL/2026/VIII/0002 dan tidak dapat direvisi; buka revisi
  terbarunya."* Ajukan baris lama (422): *"… dan tidak dapat diajukan; buka revisi
  terbarunya."*
- MPP-XML ke proyek ber-WBS (422): *"Proyek PRJ-2026-001 sudah memiliki 11 tugas WBS
  (A Pekerjaan Persiapan, A.1 Mobilisasi & demobilisasi peralatan, B.1 Galian tanah
  basement & pondasi, …); impor MPP-XML hanya menata proyek yang belum ber-WBS.
  Kosongkan WBS dari layarnya sendiri lebih dulu bila jadwal memang akan diganti."*
- MPP-XML ke proyek segar (fixture `tests/fixtures/mpp-sample.xml`, `bac_override`
  900 jt) → `{"tasks":6,"baseline_code":"BSL/2026/VIII/0002","baseline_points":3}` ·
  *"6 tugas WBS diimpor dari jadwal.xml."*
- Deskripsi kelompok Penomoran Dokumen di `GET /api/core/settings` kini menyebut
  `{PROJ}` dan konsekuensinya (teks server, layar tidak berubah untuk itu).

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- `docs/IMPOR-WARISAN.md` — **baru**: pemetaan kolom keempat layout warisan + dua
  keputusan pemetaan yang disengaja + aturan yang berlaku untuk keempatnya.
- `PANDUAN-PENGGUNA.md`: §2.4 (kode ber-`{PROJ}`, tombol XLSX); §2.9a (lima jenis data
  master — Lokasi Tapak luput tercatat sejak P1-ENG); §2.9b (sembilan jenis impor
  dokumen, aturan dobel data warisan, 422 duplikat laporan harian kata demi kata);
  §7.2 (tombol + dialog Impor Jadwal MPP-XML, tiga penolakan kata demi kata);
  §7.13 (revisi IKL); §13.2a (**baru** — tombol XLSX, daftar 10, kalimat kejujuran
  "sel bergaris = sel kosong, bukan 0", penolakan di luar daftar); §16.5 (revisi IPP,
  gerbang SDS/SMS berlaku lagi pada revisi); §17.2 (revisi inspeksi); §17.5 (koreksi:
  impor template lewat Impor Dokumen, bukan Impor Data Master).
- `PANDUAN-ADMINISTRATOR.md`: §4.6 (blok Riwayat Tarif — record-only, kunci yang
  dicatat, cara membacanya); §4.8 (`{PROJ}`: token ketujuh, pembelahan urutan per
  proyek, gagal-keras kata demi kata, catatan migrasi skema + larangan kode lama di
  atas skema baru); §4.9 (lima sumber daya master; sembilan jenis impor dokumen +
  rujukan IMPOR-WARISAN).
- `CONVENTIONS.md` §5: aturan `{PROJ}` pada mask + pola `Revisable` untuk dokumen
  berevisi berikutnya (dan daftar dokumen yang TIDAK memakainya).
- README/ARCHITECTURE: **tidak berubah** — tidak ada modul baru, arah dependensi tetap.

## Yang sengaja tidak dikerjakan, dan mengapa

- **Ekspor DOCX** (separuh D6): tidak diminta P8, dan phpoffice/phpword tidak terpasang
  — menambah dependensi dilarang §3. XLSX menutup kebutuhan olah-lanjut angka.
- **Memutar ulang mutasi kartu stok warisan** dan **kolom opname kumulatif SP3/progress
  pay warisan**: melanggar forward-only — memposting jurnal/stok ke periode lampau dan
  melahirkan klaim yang menghitung ulang sejarah kertas. Saldo penutup & dokumen induk
  yang dibawa; alasannya tertulis di `IMPOR-WARISAN.md` §2–§4.
- **PredecessorLink/Duration/Cost/Assignments pada MPP-XML**: `prj_wbs_tasks` tidak
  punya kolom dependensi, dan biaya milik RAP — subset yang dibaca didokumentasikan di
  header service dan fixture.
- **Revisi untuk ILB/IMK**: keduanya sekali-pakai per tanggal/kendaraan; D9 menyebut
  izin lapangan (IKL), IPP, inspeksi.
- **`{PROJ}` pada layar Pengaturan sebagai contoh berkode**: pratinjau tidak punya
  proyek; token dibiarkan tampak dengan kalimat penjelas — kode proyek karangan bukan
  contoh.
- **Layar tersendiri untuk riwayat tarif**: kartunya dititip di kaki layar Pengaturan,
  di samping tarif yang dicatatnya; layar kedua = tempat kedua untuk lupa.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **(lane 1, penting untuk deploy)** Sebelum migrasi scope,
   `firstOrCreate(['type','year'])` pada DB yang **sudah** punya baris ber-scope akan
   cocok ke baris mana pun — layanan baru mengunci kunci penuh `(type,year,scope)`,
   jadi **jangan pernah menjalankan service lama di atas skema baru**; deploy migrasi +
   kode bersama (kini tertulis di PANDUAN-ADMINISTRATOR §4.8).
2. **(lane 2)** `UniqueDailyReportDate` mati di jalur impor — FormRequest yang
   di-instantiate telanjang tidak melihat `project_id` payload, jadi rule yang membaca
   `$this->input()` tidak menyala. Ditambal dengan closure check pada entri
   `daily-reports`; **pola yang sama akan mengenai entri impor masa depan** yang
   rule-nya membaca request.
3. **(lane SPA)** Menu **Impor Dokumen** bergerbang `['crm.create','est.create']` saja —
   kerani prj/inv/scm/qc yang importernya justru ada di layar itu tidak pernah melihat
   pintunya. Diperlebar ke enam izin; dipaku `CrossModuleSpaWiringTest`.
4. **(lane SPA)** Menu **Impor Data Master** tidak memuat `prj.create` padahal impor
   Lokasi Tapak (P1-ENG) bergerbang prj — ditambah; dan dokumen admin/pengguna masih
   menulis "empat sumber daya" padahal lima — dikoreksi.
5. **(lane SPA/docs)** PANDUAN §17.5 mengklaim impor template inspeksi lewat **Impor
   Data Master**; kenyataannya entri `inspection-templates` hidup di registri **Impor
   Dokumen**. Dikoreksi dengan catatan.
6. **(lane SPA, kecil)** Label generik `is_current` di panel detail berbunyi "Baseline
   berlaku" — salah untuk pustaka metode (P7) dan ketiga dokumen berevisi P8. Diganti
   "Revisi berlaku" (tetap benar untuk baseline).
7. **(lane SPA, konsistensi, tidak ditambal)** Dua jalur unggah base64 tidak sepakat:
   `SpreadsheetReader::decode` menoleransi awalan dataURL (`data:…;base64,`),
   `ProjectController::importMppXml` mendekode ketat. SPA menanggalkan awalannya
   sendiri (dipaku uji), tetapi pemanggil API langsung yang meniru pola document-import
   akan ditolak *"Isi berkas bukan base64 yang dapat dibaca…"* — samakan bila ada
   kesempatan.
8. **(lane 1/2, scratch)** Berkas bantu di scratchpad (`proof.sqlite`, `smoke.sqlite`,
   `proof-imports.sqlite`, `smoke-p8.sqlite`, `smoke-spa.sqlite`) semuanya sekali
   pakai; `database/database.sqlite` hidup tidak pernah disentuh (md5 dibuktikan tiap
   lane).
