# Laporan Deviasi v2 — Master Prompt ERP vs `nusantara-erp`
### Gabungan: analisis kode · verifikasi runtime · spike Persetujuan Eksternal

**Pembanding:** `PROMPT_ERP_Kontraktor_Dokumen_Lengkap.md` (22 Agustus 2026, dari inventori Google Drive) vs `github.com/fadlibdr/nusantara-erp` `main @ b645a41` (22 Agustus 2026). Status ditulis untuk **`main` apa adanya**; perubahan yang terjadi bila patch spike `fcf9578` diterapkan ditandai terpisah dengan 🧪.

> **Metode.** Tiga lapis, semuanya pada hari yang sama. (1) Membaca kode: 161 migrasi di 12 modul, `config/erp.php`, registri `Approvable/Attachable/Printable`, `FormPrintService`, `LaporanFormService`, `PANDUAN-PENGGUNA.md`, `ARCHITECTURE.md`, `LAPORAN-DEVIASI.md`. (2) Menjalankan: `composer install`, `migrate --seed` (SQLite), **suite PHPUnit penuh 2.986 uji hijau**, `artisan serve`, 26 skenario API/jsdom. (3) Membangun spike di branch terpisah untuk menguji keputusan pemilik tentang persetujuan MK/Owner; **15 uji baru, suite penuh 3.001 hijau tanpa regresi**.
>
> **Batas.** Dibandingkan *keberadaan dan kedalaman entitas/aturan*, bukan kualitas kode. Runtime memakai SQLite, bukan MySQL 8 produksi — temuan T1 membuktikan keduanya bisa berbeda. Halaman publik diuji jsdom, bukan peramban ponsel.

**Legenda:** ✅ setara prompt · 🟡 sebagian · ⬜ tidak ada (atau tercetak kosong) · ➕ melampaui prompt · 🚫 ditolak tertulis oleh repo · 🔬 **diverifikasi runtime** (bukan hanya dibaca) · 🧪 berubah bila patch spike diterapkan.

---

## 1. Ringkasan eksekutif

Dari **95 baris taksonomi dokumen** di Bagian 3 prompt (kalkulator teknik tidak dinilai):

| Status `main` | Jumlah | Makna |
|---|---|---|
| ✅ setara | 15 | inti *procure-to-pay*, gudang, subkon, BAST, defect, insiden |
| ➕ melampaui | 8 | jaminan, RAP+EVM, subkon, invoice/e-Faktur, pajak, garansi, kas kecil, P&L PSAK 115 |
| 🟡 sebagian | 29 | hampir semua dokumen lapangan, tender, pengadaan tingkat tata kelola |
| ⬜ tidak ada | 43 | **seluruh Engineering (9), Knowledge (4), QC (5)**, sebagian besar HSE, tender pack, mandor |

Empat kalimat yang merangkum:

1. **Repo unggul di rantai uang, prompt unggul di rantai mutu.** Semua yang menyentuh rupiah ada dan lebih dalam dari prompt; semua *bukti teknis* sebelum uang (shop drawing, material approval, IPP, inspeksi IK, NCR, benda uji) tidak ada sama sekali.
2. **Deviasi terbesar adalah filosofi**, bukan fitur: prompt menuntut *document-as-transaction*, repo memilih *cetak-jujur* — sel tanpa data digaris kosong. Tujuh formulir rumah sudah diadopsi **sebagai tata letak**, belum sebagai tabel. 🔬 Dikonfirmasi runtime: Form F/LH tercetak dengan 12 jabatan kosong, PERPANJANGAN WAKTU kosong.
3. **Deviasi dua arah.** Repo punya `ServiceDesk` (kontrak pemeliharaan, SLA, tiket, jadwal PM) yang prompt tidak sebut — padahal itu lini bisnis PM yang sedang dikejar.
4. 🧪 **Keputusan pemilik tentang persetujuan MK/Owner terbukti dapat dibangun tanpa menyentuh service modul mana pun** — hanya `Core`; spike menutup keputusan terbuka #1 dan mengubah empat baris laporan ini (ditandai 🧪).

---

## 2. Deviasi filosofis: transaksi vs cetak-jujur

| | Prompt (Prinsip 1, 2, 6) | Repo `main` | 🧪 Pasca-spike |
|---|---|---|---|
| Definisi "mengakomodasi dokumen" | formulir = entitas berstatus, bernomor, berapproval, dicetak dari data | formulir = tata letak; sel diisi dari DB **atau** digaris kosong — *"tidak ada opsi ketiga"* (`FormPrintService.php:39-46`) | mode ketiga muncul: **bukti eksternal** — keputusan pihak luar tersimpan dan dicetak, dokumen tetap tanpa status |
| Laporan Harian | manpower per peran, material masuk diterima/ditolak, alat, progress/target, jam kerja, tanda tangan 3 pihak | 🔬 `prj_daily_reports`: satu `manpower_count`, cuaca AM/PM, `activities`, `obstacles`, `safety_notes`, material dipakai; **bukan Approvable** | kolom Pemilik/MK **terisi nama + cap** bila ada keputusan eksternal; sel lain tetap kosong |
| Izin kerja / lembur / material | `work_permit_hse`, `overtime_request`, `gate_pass` | 🔬 *"tercetak BENAR-BENAR KOSONG"* (§7.13) | tidak berubah |
| Perpanjangan waktu | `contract_addendum` tipe waktu | 🔬 kop mencetak PERPANJANGAN WAKTU I/II kosong; CCO hanya `value_change` | tidak berubah |

**Penilaian.** Pilihan repo rasional sebagai anti-fabrikasi, dan tata letaknya sudah benar — pekerjaannya adalah **mengisi tabel di belakang layout yang ada**, bukan membangun ulang. Spike membuktikan pola itu: data bertambah, aturan kejujuran tidak dilonggarkan.

---

## 3. Matriks deviasi per modul

### 3.1 Tender & Estimasi (`TENDER`) — repo: `Crm` + `Estimation`

| Dokumen (prompt) | Status | Bukti di repo | Deviasi |
|---|---|---|---|
| Register Dok. Lelang / aanwijzing / addendum lelang | 🟡 | `crm_leads.source='tender'`, lampiran pada penawaran | tidak ada register RKS/BAB/BA aanwijzing sebagai entitas |
| BoQ berversi | ✅ | `est_boqs.version`, sections, items `wbs_code/ahsp_id`; impor Excel | — |
| Mesin AHSP | 🟡 | `est_ahsp` (kategori sipil/arsitektur/mep/elv/ict, `overhead_pct`), komponen labor/material/equipment × koefisien | tanpa komponen **biaya SMKK**; tanpa harga SD per wilayah/tanggal; pustaka SE 182/2025 belum dimuat |
| Surat Penawaran, Pernyataan, Pakta Integritas | 🟡 | F/PN (syarat & ketentuan kosong) | Pernyataan & Pakta ⬜ |
| Kualifikasi teknis, personil, alat, subkon | 🟡 | master ada: `hr_certificates` (SKK, diawasi kedaluwarsa), `ast_assets`, `prc_vendors.is_subcontractor` | tidak ada penyusun paket kualifikasi |
| TKDN | ⬜ | — | 0 berkas |
| Metode Pelaksanaan | ⬜ | — | tidak ada pustaka |
| RKK / Pra-RK3K | ⬜ | — | 0 berkas |
| Jaminan | ➕ | `crm_guarantees` bid/performance/advance/maintenance **+ CAR/TPL**, `erp:deadline-watch`, F/RJ | — |
| Paket submit & hasil | 🟡 | `won_at/lost_at/lost_reason`, analitik win-rate ➕ | tanpa checklist kelengkapan |

### 3.2 Kontrak Induk & Setup Proyek (`CONTRACT`) — repo: `Crm` + `Projects` + `Estimation`

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| SPK/Kontrak owner, termin, retensi, garansi | ✅ 🔬 | `crm_contracts` (+`contract_number_customer`), termin DP/progress/BAST/retensi | — |
| Addendum **waktu** | ⬜ 🔬 | — | *"Tidak ada tempat mencatat perpanjangan waktu"*; kop mencetak kosong |
| Monitoring VO (FM-10-33) | ✅ 🔬 | `crm_contract_change_orders` (`change_type` tambah_kurang/eskalasi_harga, tautan termin), F/BATK; approve dengan catatan mengubah nilai kontrak (48,5 → 48,625 M) | — |
| Site Instruction (FM-10-06) / Site Memo / MOU | ⬜ | — | — |
| Daftar kontak penting (FM-10-29) | 🟡 | `prj_projects.consultant_name/role` | bukan daftar |
| RABP & monitoring | ➕ | `est_cost_budgets` per `boq_item × cost_category`, `BudgetGateService`, `CommitmentService`, **EVM & baseline** | — |
| Schedule (MPP), Kurva S, cashflow | 🟡 | kurva S dari WBS + baseline, F/DS, `CashFlowService` | **tanpa impor MPP/XML** |

### 3.3 Engineering & Dokumen Teknis (`ENG`) — repo: **tidak ada padanan**

| Dokumen | Status | Catatan |
|---|---|---|
| Daftar Rencana Persetujuan Shop Drawing (FM-10-01) | ⬜ | 0 berkas |
| Form Persetujuan Drawing (FM-10-03) + status stempel | ⬜ | — |
| Monitoring Shop Drawing (FM-10-21) | ⬜ | — |
| Standard gambar DWG (FM-10-04) | ⬜ 🔬 | `.dwg` **ditolak** kebijakan lampiran (hanya pdf/gambar/doc(x)/xls(x)/csv/txt) |
| Form Persetujuan Material (FM-10-05) / Daftar (FM-10-22) | ⬜ | — |
| **IPP — Ijin Pelaksanaan Pekerjaan (FM-10-11)** | ⬜ | 0 berkas; tulang punggung rantai *drawing → material → IPP → inspeksi* tidak ada |
| Transmittal | ⬜ | — |
| Stempel status dokumen | ⬜ | — |
| Kalkulator teknik | n/a | lampiran |

### 3.4 Perpustakaan Metode Kerja & IK (`KNOWLEDGE`) — repo: tidak ada
Metode kerja, IK Proses P1–P31, template inspeksi Q1–Q31, Q-Plan: **semua ⬜**.

### 3.5 Pengadaan & Kontrak Vendor (`PROC`) — repo: `Procurement` + `Subcontract`

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| PR | ✅ | `prc_purchase_requisitions` + items `boq_item_id` | — |
| Pola Belanja / Schedule / Monitoring Perolehan | ⬜ | "Baris PO Terbuka", `CommitmentService` | monitoring komitmen ≠ rencana pengadaan |
| Vendor master + dokumen + VPI | ✅ | `prc_vendors` + `vendor_type` supplier/subcontractor/mandor/rental (P4; `is_subcontractor` deprecated, disinkron model), `prc_vendor_documents` (… + `k3l_commitment`/`pakta_integritas` P0-E + `cv_mandor` P4 → F/CVM), `prc_vendor_evaluations` (4 kriteria 1–5) | — |
| DPP/RFQ | ✅ | `prc_rfqs`, vendors, items, `rfq_quotes.is_winner`, F/TBP | — |
| BA Aanwijzing subkon | ⬜ | — | — |
| Penawaran vendor (SPH) | 🟡 | `rfq_quotes.unit_price` | tanpa validitas, franco, termin |
| Sistem penilaian DAN 4.8 berbobot | 🟡 | tabulasi harga; `is_winner` manual | tanpa skor berbobot pra-award |
| BA Klarifikasi & Negosiasi (DAN 31) | ⬜ | `PriceDeviationService` → konfirmasi "harga sudah dinegosiasi" | pengakuan ≠ risalah |
| BA Keputusan Pemenang / SK (DAN 4.4/4.5/4.9/32/36) | ⬜ | — | tanpa komite |
| Komitmen K3L vendor | 🟡 | bisa dipaksa via `is_mandatory` tipe `lainnya` | tanpa tipe dokumen; kontrak tidak memeriksa |
| PPB/PO | ✅ | PPN per PKP, `boq_item_id`, `qty_received`, ambang direktur, override kualifikasi, F/PO + PDF | tanpa Kode Tahap/SD, franco, B3/MSDS |
| PPK/SPK alat & jasa per periode | ✅ | `prc_work_orders` (PPK, Approvable, vendor rental/supplier, baris tarif × basis × plafon `qty_periods`, per_jam wajib menunjuk aset) + `prc_work_order_billings` (PPKB — kuantitas turunan register/kalender, anti tagih-ganda empat lapis) → AP bill `work_order_billing_id`; `ast_assets.ownership` rented (P5) | — |
| Kontrak Subkon + syarat | ➕ | `scm_subcontracts` (PPh final snapshot, retensi, `defect_liability_until`, gerbang kualifikasi), addenda, uang muka, rilis retensi | — |
| SPK Mandor / SP3 Induk / opname mandor | ✅ | `scm_labor_contracts` (SP3, PPh final UMKM 0,5% PP 55/2022 per asumsi #3, `pph_scheme` konfigurabel — `pph21_ter` pintu 422 "belum diaktifkan"), `scm_labor_claims` (OPM, plafon volume per baris, potongan kasbon) → AP bill `labor_claim_id` (P4) | ambang direktur & gerbang RAP untuk SP3 ⏳ keputusan pemilik |
| Addendum generik | 🟡 | ADS ✅; CCO nilai saja | waktu ⬜ |
| Monitoring SPK/PPK; evaluasi alat | ✅ | tabel "Tagihan periode yang sudah dibuat" di halaman PPK; utilisasi memuat ownership (alat sewa ikut); layar Evaluasi Sewa vs Beli baca-saja (`RentVsOwnService` — sewa tanpa jam bergaris, owned tanpa harga "Tidak dapat dibandingkan") — P5 | — |

### 3.6 Logistik & Gudang (`INV`) — repo: `Inventory` + `Assets`

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| Izin Masuk Material/Peralatan | ⬜ 🔬 | F/IM dicetak kosong | — |
| Pemeriksaan Material Datang (FM-10-07) | 🟡 | GRN (+`delivery_note_no`), retur pembelian | **qty ditolak tidak dicatat di mana pun** |
| Penerimaan & Pemakaian, Stock Card | ✅ | `inv_stock_ledger`, HPP rata-rata bergerak, F/SS | — |
| Stock Card Beton (FM-10-25) | ⬜ | — | tanpa register pengecoran |
| Bon Pengambilan (FM-10-10) | ✅ | `inv_issues` + `wbs_task_id` per baris, F/BM, retur | tanpa tautan IPP; peringatan lebih-BoQ hanya *post-hoc* |
| Izin Keluar Alat/Material | ⬜ | F/IM kosong; transfer antar gudang ✅ | peminjaman alat kecil 🚫 (#71) |
| Stock Opname | ✅ | `inv_stock_adjustments`, F/BAO | — |
| Analisa pemakaian vs BoQ / waste / Ra-Ri | ✅ | layar **Varian Material** | besi terpasang/cutting plan ⬜ |
| Monitoring alat, sewa per vendor | ✅ | `ast_assets.ownership` owned\|rented + `vendor_id`/`rental_rate`/`rate_basis`/periode sewa (P5); alat sewa masuk register, mobilisasi, log jam, utilisasi; Rekap Tagihan Alat per vendor | — |
| Pemeliharaan alat | ✅ | `ast_maintenances` | — |

### 3.7 Produksi & Pelaporan Lapangan (`SITE`) — repo: `Projects`

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| Laporan Harian (FM-10-12) | 🟡 🔬 🧪 | create via API ✅; F/LH kop 4 pihak, NO. SPK, MINGGU/HARI/SISA, 12 jabatan; foto ber-GPS ➕ 🔬 | tanpa manpower per peran, material masuk/ditolak, alat, progress/target, jam kerja. 🧪 **Pasca-spike:** MK/Owner dapat memutuskan via tautan/lembar fisik; keputusan tercetak di kolom tanda tangan; tetap 🟡 untuk sel lainnya |
| Laporan Mingguan / Bulanan | 🟡 | `prj_weekly_progress`, F/DS | tanpa dokumen bulanan tersusun |
| Izin Kerja Lapangan (PTW) | ⬜ 🔬 | F/IK kosong | — |
| Izin Lembur | ⬜ 🔬 | F/IL kosong; lembur dihitung payroll | tanpa pengajuan per kejadian |
| Monitoring produksi / sumber daya | 🟡 | WBS `progress_pct`, EVM | sumber daya rutin ⬜ |
| Defect List (FM-10-16) | ✅ | `prj_defects`, F/DT | ➕ |
| NCR (FM-10-14) | ⬜ | — | defect ≠ NCR |

### 3.8 Quality (`QC`) — repo: tidak ada
Inspeksi IK, benda uji & slump (FM-10-24), kuat tekan (FM-10-23), Q-Pass, Q-Plan: **semua ⬜**. Kendali mutu terstruktur hanya prasyarat BAST dan register defect.

### 3.9 HSE / SMKK (`HSE`) — repo: `Projects`

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| Form K3 harian (FM-10-13) | 🟡 | `daily_reports.safety_notes` | tanpa APD/toolbox terstruktur |
| BA Kecelakaan / Kebakaran | ✅ | `prj_safety_incidents` (severity, 12 kategori, akar masalah, korektif, `is_reportable`, severity rate ➕) | tanpa foto |
| Security log, HSE plan/IBPRP, 5R, register prosedur | ⬜ | — | — |
| Biaya SMKK di BoQ | 🟡 | seksi BoQ manual | tanpa template |

### 3.10 Komersial, Opname, Penagihan, Serah Terima (`COMM`)

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| Opname ke owner (volume per item BoQ) | 🟡 | progres % per WBS & mingguan | tanpa opname kuantitas |
| Opname mandor / rekap upah | ✅ | `scm_labor_claims` (OPM: qty_this per baris ≤ sisa SP3, guard basi saat approve) + rekap upah per proyek per periode F/RU (status per baris, tanpa total campur-status) — P4 | — |
| BAP Progress Subkon (FM-10-19) | ✅ | `scm_progress_claims`, F/BO | — |
| Risalah Pembayaran (FM-10-18) | 🟡 | subkon: retensi, uang muka, PPN, PPh | **denda** ⬜; owner tanpa pemulihan UM |
| Progress claim owner per zona (BAPP) | 🟡 | "Termin Siap Ditagih" via milestone/jadwal | termin-%, bukan volume per zona |
| BAST 1/2 owner | ✅ | `prj_bast` + prasyarat + PDF | ➕ |
| BAST subkon | ⬜ | `defect_liability_until` | — |
| Invoice, kwitansi, e-Faktur | ➕ | AR termin, faktur unik, terbilang, ekspor e-Faktur | — |
| Laporan pajak | ➕ | kalender, ekualisasi, e-Bupot | — |
| Garansi & komplain | ➕ | `ServiceDesk` | — |
| Rekap tagihan alat | ✅ | `WorkOrderBillingService::recap` — billing per periode per PPK per vendor, kolom tagihan AP jujur kosong bila belum ditagihkan; layar Rekap Tagihan Alat (P5, laporan — sengaja tanpa formulir cetak) | — |

### 3.11 Keuangan & SDM (`FIN`, `HR`)

| Dokumen | Status | Bukti | Deviasi |
|---|---|---|---|
| Petty cash | ➕ | kasir, voucher, kasbon | — |
| Upah tenaga kerja | 🟡 | payroll karyawan (TER, BPJS, THR); **mandor borongan ✅ P4** (SP3 → OPM → AP bill, PPh final UMKM, potongan kasbon lewat seam KasbonService) | harian ⬜ |
| Laporan keuangan konstruksi | ➕ | TB/L-R/neraca/aging, **PSAK 115**, periode fiskal | — |
| Laporan bulanan proyek | 🟡 | laporan ada; dokumen tersusun ⬜ | — |
| Perencanaan tenaga kerja | ⬜ | penugasan saja | — |
| Lembur | 🟡 | payroll ✅; pengajuan ⬜ | — |
| Peran SOP 30–39 | 🟡 🔬 | 10 peran seed + matriks izin (`{prefix}.{view,create,update,delete,approve,post}`) | QC, QS, supervisor, site engineer, bagian alat, admin proyek belum jadi peran |

---

## 4. Deviasi lintas-modul

| # | Prompt | Repo `main` | Status | 🧪 / Komentar |
|---|---|---|---|---|
| D1 | Document-as-transaction | cetak-jujur | 🟡 🔬 | 🧪 mode ketiga "bukti eksternal" terbukti jalan |
| D2 | Mask penomoran per jenis **per proyek** | `config('erp.documents')` mask per jenis, urut per tahun | 🟡 | `{RM}` ada; tanpa `{PROJ}` |
| D3 | Kewenangan n-level, komite | `Approvable` + ambang 2-level + **maker-checker dipaksa** ➕ | 🟡 🔬 | maker-checker dikonfirmasi runtime; 🧪 user proksi tanpa login memenuhi SoD untuk pihak luar |
| D4 | Cost code Tahap × SD | `boq_item × cost_category`; `inv_items.code` sebagai SD de facto | 🟡 | secara fungsi setara |
| D5 | Tarif bertanggal | config + **snapshot per dokumen** | 🟡 | tujuan prompt tercapai lewat snapshot |
| D6 | Print setia-visual, ekspor XLSX/DOCX | 40 formulir HTML + 4 PDF; kop 4 pihak, 3 kolom tanda tangan | 🟡 🔬 | ekspor formulir ke XLSX/DOCX ⬜ |
| D7 | Mobile **offline** | SPA responsif + *Lapangan*; tanpa service worker | 🟡 | — |
| D8 | Lampiran dwg/mpp/vsd/pptx | 22 jenis dokumen; **5 MB**; `.dwg` ditolak | ⬜ 🔬 | `.svg` ditolak sadar; isi ≠ ekstensi ditolak 🔬 |
| D9 | Revisi `Rn` generik | revisi per jenis (quotation, BOQ, baseline); kode `F/xx` | 🟡 | — |
| D10 | `location_tree` | string bebas | ⬜ | prasyarat BAPP zona & inspeksi per as |
| D11 | Multi-tenant | single company | ⬜ | sesuai pagar repo |
| D12 | Importer XLS warisan | importer penawaran/BOQ/AHSP/RAP/rekening koran | 🟡 | — |
| D13 | Label ID, i18n | label ID; `APP_LOCALE=en` → validasi Inggris | 🟡 🔬 | — |
| D14 | Audit trail, API | `core_audit_log`, `core_approvals`, 547 rute; notifikasi dengan catatan | ✅ 🔬 | — |
| D15 | e-Faktur/Coretax, bank, LPSE | ekspor CSV e-Faktur/e-Bupot; impor MT940/CSV | 🟡 | host-to-host 🚫 |

---

## 5. Arah sebaliknya — ada di repo, absen di prompt

| Kemampuan repo | Tindakan untuk prompt |
|---|---|
| **ServiceDesk** (kontrak pemeliharaan, SLA, tiket, PM otomatis, BA lapangan + sparepart) | tambah modul `SERVICE` |
| EVM & baseline | adopsi sebagai standar pengendalian |
| Maker-checker, gerbang anggaran, gerbang deviasi harga, gerbang kualifikasi vendor | masukkan ke Prinsip Desain |
| `erp:deadline-watch` jaminan/sertifikat/dokumen vendor/PKWT | masukkan ke pelaporan |
| Periode fiskal, rekonsiliasi bank, kalender & ekualisasi pajak, PSAK 115 | prompt terlalu tipis di FIN |
| Foto ber-GPS dengan jarak ke titik proyek 🔬 | wajib di mobile |
| Varian Material dengan "bon belum ditandai" | adopsi desainnya |
| Penolakan tertulis: portal pelanggan, multi-valuta, peminjaman alat, bank host-to-host, WhatsApp, app native | *out of scope* eksplisit |

---

## 6. Kriteria penerimaan prompt (§8) — status

| # | Kriteria | `main` | 🧪 Pasca-spike |
|---|---|---|---|
| 1 | Laporan Harian mobile ≤5 menit, PDF setara FM-10-12 | 🟡 🔬 | tetap 🟡 (sel masih kosong) |
| 2 | IPP ditolak bila drawing/material belum approved | ⬜ | — |
| 3 | Bon → cost code & IPP; Ra-Ri otomatis | 🟡 | — |
| 4 | Evaluasi DAN 4.8 + blok award tanpa BA nego | ⬜ | — |
| 5 | Risalah: UM, retensi, denda, PPN, PPh; tarif bertanggal | 🟡 | — |
| 6 | Klaim owner menolak zona "Nunggu perbaikan" | ⬜ | — |
| 7 | Cetak ulang pada revisi tepat, kode form & tanda tangan | 🟡 🔬 | **membaik**: kolom Pemilik/MK dari DB |
| 8 | Impor MPP-XML → kurva S | ⬜ | — |
| 9 | Audit trail | ✅ 🔬 | — |
| 10 | Tiga set data warisan via importer | ⬜ | — |

---

## 7. Backlog penutupan gap (diurutkan; memakai mekanisme repo)

| Prioritas | Paket | Isi ringkas | Mekanisme | 🧪 |
|---|---|---|---|---|
| **P0-A** | Laporan Harian penuh | tabel manpower per peran, alat, material masuk (diterima/ditolak), uraian progress/target, jam kerja; kunci edit setelah keputusan eksternal | `LaporanFormService::MANPOWER_ROLES` sudah jadi daftar | dependensi "MK bukan user" **gugur** |
| **P0-B** | Addendum waktu | CCO `change_type='waktu'` + `days_change` → `end_date`; isi PERPANJANGAN WAKTU di kop | CCO Approvable | — |
| **P0-C** | Tiga izin lapangan jadi transaksi | `prj_work_permits`, `prj_overtime_permits`, `prj_gate_passes` | `HasDocumentNumber`, `Approvable`, `FormPrintService::FORMS` | — |
| **P0-D** | Kebijakan lampiran | izinkan dwg/dxf/mpp/xml/pptx; batas per tipe; multipart >5 MB | `AttachmentService` | — |
| **P0-E** | Dokumen vendor K3L & pakta | `doc_type` baru + gerbang kontrak | `prc_vendor_documents`, gerbang kualifikasi | — |
| **P0-F** 🧪 | Tombol "Terbitkan tautan" di SPA + kartu tautan | `schema.js`, pola `attachmentsCard` | spike menyediakan API-nya | baru |
| **P0-G** | Cacat runtime T1–T3 | lihat Bagian 10 | — | baru |
| **P1** | Modul `Engineering` | drawing/material submittal, transmittal, **IPP**, `core_locations` | modul baru bergantung ke Projects/Estimation | — |
| **P1** | Modul `Quality` | template & record inspeksi, NCR (CAPA), benda uji beton; NCR terbuka memblokir inspeksi berikut | pola `prj_safety_incidents` | — |
| **P2** | Tata kelola pengadaan | skor berbobot, risalah nego, keputusan pemenang & komite, kewenangan n-level, rencana pengadaan | `rfq_quotes`, `PriceDeviationService` | — |
| **P3** | Opname owner & BAPP zona; BAST subkon; UM & denda di AR | `prj_progress_measurements`, `prj_zone_certificates`, `scm_handovers` | — | — |
| **P4** | Mandor | vendor `type='mandor'`, `scm_labor_contracts` (SP3), `scm_labor_claims` | reuse `Subcontract` | keputusan PPh ⚠️ |
| **P5** | Alat sewa & PPK | `ast_assets.ownership`, `prc_work_orders` per periode | `ast_equipment_logs` | — |
| **P6** | HSE terstruktur | K3 harian, IBPRP, 5R, foto insiden | `Attachable` | — |
| **P7** | Paket tender | register lelang, TKDN, RKK, pustaka metode | `Crm` + lampiran | — |
| **P8** | Lintas-modul | `{PROJ}` mask, tarif bertanggal (opsional), impor MPP-XML, importer warisan, XLSX formulir | — | — |

**Tidak dikerjakan** (pagar repo): portal pelanggan, multi-valuta, peminjaman alat kecil, bank host-to-host, WhatsApp, app native, multi-tenant.

---

## 8. Revisi untuk prompt

1. Tambah modul `SERVICE`.
2. Prinsip 3: "WBS/item BoQ × kategori biaya, kode SD bermaster untuk material **dan** upah/alat".
3. Prinsip 5: "tarif di-snapshot per dokumen; tabel bertanggal opsional".
4. Prinsip 6: adopsi *aturan kejujuran* + daftar hutang entitas sebagai target.
5. Prinsip 7: offline → *should*; foto ber-GPS → *must*.
6. §5.1: mask per jenis memadai; `{PROJ}` opsional.
7. §5.2: maker-checker + tiga gerbang sebagai kontrol wajib.
8. §3.2: "impor MPP-XML **atau** baseline dari WBS".
9. Bagian baru "Di luar lingkup (ditolak tertulis)".
10. Tambah peran QC, QS, Site Supervisor, Site Engineer, Bagian Alat, Admin Proyek.
11. 🧪 §10: pertanyaan "bagaimana pihak eksternal menyetujui" **terjawab** — tautan sekali-pakai dengan tiga tombol, atau lembar fisik yang dipindai; rujuk `docs/PERSETUJUAN-EKSTERNAL.md`.

---

## 9. Keputusan pemilik

| # | Keputusan | Status |
|---|---|---|
| 1 | Pihak eksternal (MK/owner) sebagai penyetuju | ✅ **Diputuskan 22 Agu**: via tautan (setuju / setuju dengan catatan / tolak) atau tanda tangan fisik; 🧪 terbukti |
| 2 | Prioritas Engineering/Quality vs tata kelola pengadaan | ⏳ |
| 3 | Mandor: PPh final atau PPh 21 | ⏳ (menentukan P4) |
| 4 | Kebijakan lampiran DWG/MPP dan batas MB | ⏳ (menentukan P0-D) |
| 5 | Laporan ini masuk `docs/` | ⏳ |
| 6 🧪 | User proksi tanpa login sebagai aktor transisi (vs mengubah trait `Approvable` di 18 dokumen) | ⏳ — spike memakai proksi |
| 7 🧪 | Tautan CCO hanya terbit saat `submitted` | ⏳ — spike memakai syarat ini |

---

## 10. Temuan runtime (bukan deviasi — cacat)

| # | Temuan | Bukti | Keparahan |
|---|---|---|---|
| T1 | Laporan harian tanggal duplikat → **HTTP 500** `UNIQUE constraint failed`, bukan 422 | SQLite menyimpan `2026-03-25 00:00:00`; `Rule::unique` membandingkan `2026-03-25` → validasi lolos, DB menolak | 🟡 SQLite saja; MySQL DATE menormalkan — tetapi demo & suite uji memakai SQLite |
| T2 | README contoh `GET /api/projects/projects` → 404 | rute benar `GET /api/projects` | 🟡 dokumentasi |
| T3 | `GET /api/core/print/forms` = 33 formulir; 7 formulir rumah proyek tidak ada di katalog | jalur `print/forms/{slug}/{id}` | 🟡 konsistensi |
| T4 | Jumlah uji: README 2.305 vs aktual **2.986** | suite penuh | ℹ️ dokumentasi tertinggal |

**Runtime yang lulus sesuai dokumentasi (9):** login per peran (throttle 10/menit), maker-checker, approve dengan catatan + propagasi nilai kontrak + notifikasi, laporan harian dengan material, F/LH tercetak, lampiran PNG + galeri, `.dwg` ditolak, HTML-berkedok-PDF ditolak.

---

## 11. Spike Persetujuan Eksternal — ringkasan bukti

**Artefak:** `0001-Persetujuan-eksternal-….patch` (14 berkas, +1.489/−7), **apply bersih pada fresh clone `main`**. Rancangan: `docs/PERSETUJUAN-EKSTERNAL.md` (dalam patch).

| # | Skenario ujung-ke-ujung (server hidup) | Hasil |
|---|---|---|
| E1 | Terbit tautan MK (site-manager, `prj.update`) | 201; token 48 char tampil sekali; DB hanya SHA-256 |
| E2 | GET publik tanpa login | 200: pihak, kode dokumen, status, **HTML F/LH 27 KB** |
| E3 | Token acak / cacat | 404 / 404 |
| E4 | "Setuju dengan catatan" tanpa catatan | 422 |
| E5 | Dengan catatan (UA iPhone) | 200; trail `approved`, `user_id NULL`, catatan beridentitas; IP/UA tersimpan |
| E6 | Tautan dipakai lagi | 410 |
| E7 | F/LH setelah keputusan | kolom MK: organisasi · `DISETUJUI DENGAN CATATAN — via tautan, tgl` · nama; kolom Pemilik kosong |
| E8 | Tautan CCO saat `draft` | ditolak |
| E9 | Terbit tanpa `crm.update` | 403 |
| E10 | "Tolak" tanpa alasan | 422 |
| E11 | Owner "Setuju" CCO | `approved` **lewat `ContractChangeOrderService`**, nilai kontrak +35 M tepat; aktor user proksi `is_active=0`; pengaju dinotifikasi |
| E12 | Login user proksi | ditolak |
| E13 | Tanda tangan fisik, pindaian pada dokumen lain | ditolak |
| E14 | Pindaian pada dokumen sama, dicatat `project-manager` | `signed_physically`; tautan daring → 410 |
| E15 | F/LH kedua kolom bercap (fisik + tautan) | ✅ |
| E16 | Cabut → decide 410; cabut yang sudah diputuskan → 422 | ✅ |
| E17 | Halaman `public/app/ext/` di jsdom: muat, guard, kirim, struk, kunjungi ulang, token palsu | ✅ |

**Uji otomatis:** `tests/Feature/Core/ExternalApprovalTest.php` — 15 uji, 84 asersi. **Suite penuh pasca-spike: 3.001 uji, 13.651 asersi, hijau.** Pint: lulus.

**Sengaja tidak dibangun:** pengiriman tautan otomatis, tanda tangan elektronik tersertifikasi/e-Materai, dokumen transisi selain CCO, tombol terbit tautan di SPA (→ P0-F).

---

*Tidak ada berkas di `main` yang diubah. Spike hidup di branch `spike/external-approval` (`fcf9578`) dan patch yang menyertainya.*
