# PROMPT — Claude Code: Menutup Deviasi `nusantara-erp` terhadap Master Prompt ERP

> **Cara pakai.** Simpan berkas ini sebagai `docs/ROADMAP-DEVIASI.md` di repo (bersama `LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md` dan patch spike), lalu buka Claude Code di root repo dan beri instruksi:
> `Baca docs/ROADMAP-DEVIASI.md lalu kerjakan paket P0-G.`
> Satu paket per sesi. Jangan beri dua paket sekaligus — Claude Code akan mengerjakan keduanya setengah-setengah.

---

## 0. Misi, dan batas yang tidak boleh dilewati

Anda mengerjakan repo `nusantara-erp` — Laravel 12 modular monolith untuk kontraktor konstruksi & integrator sistem Indonesia — untuk **menutup deviasi** yang tercatat di `docs/LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md`, **satu paket per sesi**, dengan pola kerja yang sudah dipakai repo ini: tes dulu, service tebal, controller tipis, tidak ada angka karangan di atas kertas bertanda tangan.

Batas:
1. **Satu paket per sesi.** Jangan mulai paket berikutnya sekalipun tersisa waktu. Laporkan, berhenti.
2. **Suite penuh harus tetap hijau** (3.001 uji pasca-spike, atau 2.986 tanpa spike). Satu uji lama yang merah = pekerjaan belum selesai, bukan "uji lamanya salah" — kecuali Anda bisa membuktikan ujinya menguji perilaku yang memang diubah oleh keputusan pemilik, dan Anda menuliskan bukti itu di laporan akhir.
3. **Jangan menyentuh berkas bersama:** `bootstrap/*`, `composer.json`, `database/seeders/DatabaseSeeder.php`, `routes/*`. Modul mendaftar sendiri (`CONVENTIONS.md §1`).
4. **Jangan pernah mengisi sel formulir cetak dengan nilai tebakan.** Sel tanpa sumber data tetap bergaris kosong (`PANDUAN-PENGGUNA.md §13.5`). Menutup deviasi berarti **menambah sumber data**, bukan melonggarkan aturan itu.
5. **Jangan membangun yang sudah ditolak tertulis:** portal pelanggan, multi-valuta, peminjaman alat kecil, bank host-to-host, WhatsApp, aplikasi native, multi-tenant.
6. Bila spesifikasi di sini bertentangan dengan `CONVENTIONS.md`, **CONVENTIONS.md menang** — catat pertentangannya di laporan akhir.

---

## 1. Baca dulu, dalam urutan ini (wajib, sebelum menulis satu baris pun)

1. `docs/CONVENTIONS.md` — kontrak mengikat: tata letak modul, blok nomor migrasi, aturan FK lintas-modul (**tanpa constraint**, hanya `unsignedBigInteger` + index), tipe kolom, base class, konvensi API, istilah.
2. `docs/ARCHITECTURE.md` — arah dependensi antarmodul. Modul baru boleh bergantung ke `Projects`/`Estimation`/`Core`; **`Core` tidak boleh bergantung ke modul mana pun**.
3. `Modules/Core/Traits/Approvable.php`, `Modules/Core/Support/ApprovableDocuments.php`, `AttachableDocuments.php`, `PrintableDocuments.php` — tiga registri; dokumen baru harus terdaftar di yang relevan agar notifikasi, lampiran, dan cetak ikut otomatis.
4. `Modules/Core/Services/FormPrintService.php` (baca komentar kelasnya) dan `Modules/Projects/Services/LaporanFormService.php` — cara formulir rumah disusun dan **mengapa** sel tertentu kosong.
5. `config/erp.php` — mask penomoran `documents`, ambang `approvals`, gerbang `procurement`.
6. `docs/PANDUAN-PENGGUNA.md` §7.3, §7.13, §13.3–13.5 — apa yang dijanjikan ke pengguna hari ini.
7. `tests/ErpTestCase.php`, `tests/Feature/Projects/BaselineFixtures.php`, `tests/Unit/Finance/FinanceFixtures.php` — fixture yang harus dipakai ulang, jangan buat yang baru kalau sudah ada.
8. Bila patch spike sudah diterapkan: `docs/PERSETUJUAN-EKSTERNAL.md`, `Modules/Core/Services/ExternalApprovalService.php`, `Modules/Core/Support/ExternalApprovableDocuments.php`.
9. `docs/LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md` Bagian 3, 7, 9, 10 — baris mana yang sedang Anda tutup, dan keputusan pemilik mana yang masih ⏳.

Setelah membaca, **tulis ringkasan 10 baris** tentang mekanisme repo yang akan Anda pakai untuk paket ini, sebelum mengubah apa pun. Kalau ringkasan itu tidak menyebut registri, blok migrasi, dan fixture yang relevan, Anda belum siap.

---

## 2. Keputusan pemilik — yang sudah, dan asumsi bila belum dijawab

| # | Keputusan | Status | Asumsi kerja bila masih ⏳ |
|---|---|---|---|
| 1 | MK/Owner menyetujui via tautan sekali-pakai (setuju / setuju dengan catatan / tolak) atau lembar fisik | ✅ 22 Agu | — |
| 2 | Urutan P1: Engineering+Quality dulu, atau tata kelola pengadaan dulu | ⏳ | **Engineering+Quality dulu** (perusahaan pengguna subkontraktor spesialis: bukti teknis menentukan tagihan) |
| 3 | Mandor: PPh final jasa konstruksi atau PPh 21 | ⏳ | **PPh final** (mandor borongan sebagai vendor); buat `pph_scheme` konfigurabel per kontrak agar pembalikan murah |
| 4 | Lampiran DWG/MPP & batas ukuran | ⏳ | izinkan `dwg dxf mpp xml pptx ppt`; batas 5 MB tetap untuk JSON base64, **25 MB** lewat multipart untuk tipe gambar teknik |
| 5 | Laporan deviasi masuk `docs/` | ⏳ | ya, sebagai `docs/LAPORAN-DEVIASI-v2-….md` |
| 6 | User proksi tanpa login sebagai aktor transisi eksternal | ⏳ | **pertahankan proksi** (tidak menyentuh 18 dokumen Approvable) |
| 7 | Tautan CCO hanya saat `submitted` | ⏳ | pertahankan |

Jangan menunggu jawaban; pakai asumsi, **catat di laporan akhir** setiap asumsi yang dipakai.

---

## 3. Aturan tambahan di luar CONVENTIONS.md (berlaku untuk semua paket)

- **Penomoran:** setiap dokumen baru mendapat kunci di `config('erp.documents')` dengan mask gaya repo (`XXX/{Y}/{RM}/{N4}`), dipakai lewat `HasDocumentNumber`. Jangan membuat counter sendiri.
- **Status:** `DocumentStatus` dari Core bila dokumen punya siklus persetujuan; jangan membuat enum status baru yang semakna.
- **Persetujuan:** trait `Approvable` + daftar di `ApprovableDocuments` (notifikasi otomatis). Maker-checker **tidak boleh dilewati**. Efek samping persetujuan ditulis di **service modul**, dipanggil dari controller; jangan menaruh logika di controller atau di trait.
- **Lampiran:** daftar di `AttachableDocuments` bila dokumen butuh berkas/foto. Izin mengikuti modul pemilik (`{prefix}.view` untuk melihat, `{prefix}.update` untuk menambah).
- **Cetak:** formulir rumah baru = satu baris di `FormPrintService::FORMS` + satu *composer method* + satu blade di `Modules/Core/Resources/views/forms/`, memakai `layout.blade.php` (kop 4 pihak, blok identitas, 3 kolom tanda tangan). Kode formulir `F/xx` unik; periksa daftar di `PANDUAN-PENGGUNA.md §13.3`.
- **Izin:** pakai skema `{prefix}.{view|create|update|delete|approve|post}` dari `PermissionSeeder`. Modul baru = tambah prefix ke `PermissionSeeder::PREFIXES` **dan** ke matriks peran seeder; jangan membuat izin ad hoc.
- **Migrasi lintas modul:** `unsignedBigInteger('project_id')->nullable(); index('project_id')` — **tanpa** `constrained()`. Di dalam modul sendiri pakai `constrained()`.
- **Cross-module read:** boleh relasi Eloquent ke modul lain; **tulis** ke tabel modul lain hanya lewat service modul itu.
- **Bahasa:** label, pesan 422, judul notifikasi dalam Bahasa Indonesia; kode & nama tabel Inggris.
- **UI:** satu layar = satu entri di `public/app/js/schema.js`; kartu khusus mengikuti pola `attachmentsCard` (`public/app/js/views/attachments.js`). Tanpa build step, tanpa dependensi CDN.
- **Tes:** `Tests\ErpTestCase` (RefreshDatabase, SQLite memori). Setiap aturan bisnis = minimal satu uji yang **gagal dulu** sebelum implementasi. Uji HTTP memakai `actingAs($this->userWith('prefix.action'))`.
- **Seed demo:** bila paket menambah entitas yang bermakna untuk demo, tambahkan ke seeder modul dengan kode kanon `CONVENTIONS.md §8`. `ProductionSeeder` tidak boleh memuat dokumen.
- **Dokumentasi:** setiap paket memperbarui `docs/PANDUAN-PENGGUNA.md` (bagian yang relevan, gaya yang sama: tombol, kolom, pesan penolakan kata demi kata) dan menambah satu baris ke `README.md` Modules bila modul baru.

---

## 4. Protokol kerja per paket

```
1. git checkout -b feat/<paket> main            # satu branch per paket
2. Tulis ringkasan 10 baris (Bagian 1)
3. Tulis uji yang GAGAL untuk setiap aturan di "Definition of Done"
4. Migrasi → model/enum → service → controller/request → route → registri → cetak → schema.js → seeder → docs
5. vendor/bin/pint --test  &&  vendor/bin/phpunit <uji paket>
6. vendor/bin/phpunit   (suite penuh; ~5 menit; WAJIB hijau)
7. php artisan migrate:fresh --seed  &&  smoke test endpoint baru lewat curl (login seeded user)
8. git commit — pesan dalam Bahasa Indonesia, gaya repo: apa yang diputuskan dan mengapa
9. Tulis laporan akhir (Bagian 6) ke docs/LAPORAN-PAKET-<paket>.md
```

Bila langkah 6 merah dan penyebabnya uji lama yang memang menguji perilaku yang berubah: ubah ujinya **bersama penjelasan di laporan**; bila penyebabnya regresi: perbaiki implementasinya, bukan ujinya.

---

## 5. Paket — spesifikasi dan *Definition of Done*

Urutan wajib: **P0-G → P0-A → P0-B → P0-C → P0-D → P0-E → P0-F → P1-ENG → P1-QC → P2 → P3 → P4 → P5 → P6 → P7 → P8.** P0-G paling dulu karena paket lain membangun di atas laporan harian yang validasinya sedang rusak di SQLite.

### P0-G — Cacat runtime T1–T4 (kecil, pemanasan)

**Tutup:** Laporan v2 Bagian 10.
- **T1:** `DailyReportStoreRequest`/`UpdateRequest` — normalisasi tanggal sebelum `Rule::unique` (bandingkan dengan `whereDate` atau simpan `report_date` sebagai `date:Y-m-d` sehingga SQLite menyimpan `YYYY-MM-DD`). Pilih yang **tidak mengubah data MySQL produksi**; tulis alasannya. Uji: duplikat → 422 dengan pesan Bahasa Indonesia *"Sudah ada laporan harian untuk proyek ini pada tanggal tersebut."* (ganti pesan Inggris bawaan).
- **T2:** perbaiki contoh rute di `README.md`.
- **T3:** `GET api/core/print/forms` memuat juga 7 formulir rumah proyek (`FormPrintService::FORMS`) dengan `permission` masing-masing; uji: admin melihat 40.
- **T4:** perbarui angka uji di `README.md`.

**DoD:** 4 uji baru hijau; suite penuh hijau; `migrate:fresh --seed` lalu POST laporan harian duplikat via curl → 422.

### P0-A — Laporan Harian penuh (FM-10-12 dari basis data)

**Tutup:** 3.7 Laporan Harian (🟡→✅ untuk sel yang kini bersumber), Bagian 2, kriteria #1.

Skema (`Modules/Projects`, blok 0007xx, nomor bebas — periksa `ls`):
- `prj_daily_report_manpower`: `daily_report_id` (constrained), `role_key` string 40 (enum `DailyReportRole` yang **menggantikan** konstanta `LaporanFormService::MANPOWER_ROLES` — satu sumber, dua pemakai), `headcount` unsignedSmallInteger, `notes` nullable. Unik `(daily_report_id, role_key)`.
- `prj_daily_report_equipment`: `daily_report_id`, `asset_id` nullable (ast_assets, tanpa FK), `description` 150, `qty` unsignedSmallInteger, `hours` decimal(8,2) nullable.
- `prj_daily_report_receipts`: `daily_report_id`, `goods_receipt_id` nullable (inv_goods_receipts, tanpa FK), `item_id` nullable, `description` 200, `qty_received` decimal(15,3), `qty_rejected` decimal(15,3) default 0, `unit` 20, `rejection_reason` 200 nullable.
- `prj_daily_report_activities`: `daily_report_id`, `wbs_task_id` nullable, `description` 300, `progress_note` 150 nullable, `target_note` 150 nullable, `obstacle` 300 nullable, `sort_order`.
- Kolom baru di `prj_daily_reports`: `work_start` time nullable, `work_end` time nullable, `lost_hours_reason` 300 nullable, `locked_at` dateTime nullable.

Aturan service (`DailyReportService`):
- Bila ada baris manpower, `manpower_count` **diturunkan** dari jumlah `headcount` (tolak nilai manual yang berbeda dengan 422 yang menyebut selisihnya). Bila tidak ada baris, `manpower_count` manual tetap berlaku (kompatibel dengan data lama).
- `work_end` > `work_start`; `qty_rejected` ≤ `qty_received`.
- `locked_at` diisi saat keputusan eksternal pertama tercatat (hook dari `ExternalApprovalService` — bila patch spike ada) **atau** saat BAST I disetujui (aturan lama). Laporan terkunci menolak update/delete dengan pesan yang menyebut siapa yang mengunci.
- Endpoint baca tambahan: `GET api/projects/daily-reports/{id}/receipts-candidates` — GRN terposting pada proyek & tanggal yang sama, untuk diimpor sebagai baris receipts (bukan otomatis: pengawas memilih).

Cetak (`LaporanFormService::harian`): 12 baris jabatan terisi **hanya untuk role_key yang punya baris**; sisanya tetap kosong. MATERIAL MASUK: kolom diterima/ditolak dari receipts. ALAT-ALAT dari equipment. URAIAN/PROGRESS/TARGET/HAMBATAN dari activities. Jam kerja dari `work_start/end`. Hapus kalimat "Yang tercetak BERGARIS KOSONG…" di `PANDUAN-PENGGUNA.md §7.3` **hanya** untuk sel yang kini bersumber; sisakan untuk PERPANJANGAN WAKTU (P0-B).

UI: form laporan harian mendapat empat tabel baris; layar *Lapangan (mobile)* mendapat **manpower per jabatan** (12 stepper) — sisanya cukup di form desktop.

Seed: tiga laporan demo diisi manpower per jabatan & dua baris activities.

**DoD:** ≥10 uji (derivasi `manpower_count`, tolak selisih manual, `qty_rejected` ≤ diterima, jam kerja, kunci, kandidat GRN, cetak terisi hanya yang bersumber, cetak kosong tanpa data, impor/ekspor kompat lama). `LaporanHarianFormTest` lama tetap hijau atau diubah dengan penjelasan. Suite hijau.

### P0-B — Addendum waktu

**Tutup:** 3.2 Addendum waktu (⬜→✅), kop PERPANJANGAN WAKTU I/II, kriteria #7 sebagian.
- `ChangeOrderType` tambah `case Waktu = 'waktu'`; kolom `days_change` smallInteger nullable + `new_end_date` date nullable di `crm_contract_change_orders`. Untuk tipe `waktu`: `value_change` wajib 0, `days_change` ≠ 0, `new_end_date` = `contract.end_date` + hari (dihitung service, bukan diinput).
- `ContractChangeOrderService::approve` untuk tipe waktu: geser `crm_contracts.end_date` **dan** `prj_projects.end_date` (lewat `ProjectService`, bukan update langsung), simpan `original_end_date` sekali.
- Kop formulir rumah: PERPANJANGAN WAKTU I/II diisi dari dua CCO tipe waktu **yang disetujui**, urut tanggal (`"+14 hari → 14 Agu 2027 (CCO/2026/VIII/0003)"`). CCO ketiga dst: cetak "lihat register" di kolom II — jangan memotong diam-diam.
- F/BATK: cabang tata letak untuk tipe waktu (tanpa baris nilai).
- Gerbang: `prj_projects` berstatus masa pemeliharaan/tutup menolak CCO waktu.

**DoD:** ≥6 uji; kop F/LH menampilkan perpanjangan setelah CCO waktu disetujui; suite hijau.

### P0-C — Tiga izin lapangan menjadi transaksi

**Tutup:** 3.7 PTW & Lembur, 3.6 Izin Masuk/Keluar (⬜→✅).
- `prj_work_permits` (`IKL/{Y}/{RM}/{N4}`): project_id, wbs_task_id nullable, `permit_date`, `shift` (pagi/siang/malam), `work_description`, `hazard_notes`, `ppe_required` json, `valid_from`/`valid_until` dateTime, `requested_by` employee, `status` Approvable. Approve oleh `prj.approve`; `safety_officer_id` employee nullable.
- `prj_overtime_permits` (`ILB/…`): project_id, `overtime_date`, `start`/`end` time, `reason`, baris `prj_overtime_permit_workers` (employee_id **atau** `worker_name` untuk non-karyawan, `hours`). Approve → **umpan ke `hr_attendance_recaps`** lewat `HrPayroll` service (forward-only, periode terposting dilewati — ikuti pola cuti #22). Ini satu-satunya tulisan lintas modul di paket ini; lewat service HR.
- `prj_gate_passes` (`IMK/…`): `direction` in/out, project_id, `vehicle_no`, `driver_name`, `counterparty` (vendor_id nullable / teks), baris item (item_id nullable, description, qty, unit), `goods_receipt_id`/`transfer_id` nullable sebagai rujukan, `checked_by` (security), `checked_at`. Approvable; pintu gerbang mencetak setelah approve.
- Tiga *composer* di `FormPrintService` yang sekarang mencetak kosong **diubah** menjadi mencetak dari baris ini; `PANDUAN §7.13` dan `§13.5` diperbarui; kata "tercetak kosong" dihapus untuk ketiganya.
- Registri: ketiganya ke `ApprovableDocuments` + `AttachableDocuments` (foto izin kerja, foto muatan).
- 🧪 Bila patch spike ada: tambahkan `prj_work_permits` ke `ExternalApprovableDocuments` mode **transisi** (MK menyetujui izin kerja berisiko tinggi) — dengan adapter service, **bukan** trait.

**DoD:** ≥12 uji (siklus tiap dokumen, umpan lembur ke rekap, periode terposting dilewati dengan pesan, gerbang tanggal/jam, cetak terisi); suite hijau.

### P0-D — Kebijakan lampiran gambar teknik & jadwal

**Tutup:** D8 (⬜→🟡/✅), 3.3 DWG.
- `AttachmentService::ALLOWED` tambah `dwg dxf mpp xml pptx ppt` dengan MIME yang **benar-benar** dilaporkan `finfo` untuk tiap tipe (uji dengan sampel biner nyata di `tests/fixtures/`; jangan menebak MIME). Tolak `.xml` yang isinya HTML.
- Batas ukuran per ekstensi (`SIZE_LIMITS`), bawaan 5 MB; gambar teknik & MPP 25 MB (asumsi #4).
- Endpoint multipart `POST api/core/attachments/upload` (file field) untuk berkas > 5 MB; JSON base64 tetap untuk yang kecil (kompat `api.js`). `api.js` mendapat `uploadFile()` yang memilih jalur otomatis.
- `docs/DEPLOYMENT.md`: catatan `post_max_size`/`upload_max_filesize` dan pengecualian rsync tetap berlaku.

**DoD:** ≥6 uji (tiap tipe diterima, MIME palsu ditolak, batas per tipe, multipart, izin); suite hijau.

### P0-E — Dokumen vendor K3L & pakta integritas, dan gerbang kontrak

**Tutup:** 3.5 Komitmen K3L (🟡→✅).
- `VendorDocumentType` tambah `K3lCommitment = 'k3l_commitment'`, `PaktaIntegritas = 'pakta_integritas'`.
- Gerbang kualifikasi yang sudah ada (`PoService`/`RfqService`/`SubcontractService`): saat **submit** PO/SPK, bila vendor bertipe subkon dan dokumen bertanda `is_mandatory` bertipe di atas **tidak ada atau kedaluwarsa** → 422 yang menyebut dokumen mana; `qualification_override_reason` yang ada tetap menjadi jalan keluar sadar.
- Template dokumen `PERSYARATAN K3L untuk Vendor` sebagai formulir rumah `F/K3V` (teks dari `docs/`-nya pemilik bila tersedia; bila tidak, judul + blok tanda tangan saja — jangan mengarang klausul).

**DoD:** ≥5 uji; suite hijau.

### P0-F — Tombol "Terbitkan tautan" dan kartu Persetujuan Eksternal di SPA (🧪 butuh patch spike)

**Tutup:** kekurangan spike yang tertulis di `docs/PERSETUJUAN-EKSTERNAL.md`.
- Kartu `externalApprovalsCard(slug, id, module)` di `public/app/js/views/` mengikuti `attachmentsCard`: daftar tautan (pihak, nama, status, keputusan, tanggal), tombol **Terbitkan tautan** (dialog: pihak, nama, organisasi, e-mail, masa berlaku), **Cabut**, **Catat tanda tangan fisik** (pilih lampiran dokumen ini). URL tampil sekali di dialog dengan tombol salin; sesudah ditutup tidak bisa dilihat lagi (sesuai server).
- Dipasang pada halaman Laporan Harian, CCO, dan (bila P0-C selesai) Izin Kerja.
- Lonceng: notifikasi "keputusan eksternal tercatat" sudah datang dari event; pastikan tautannya membuka dokumen.
- `PANDUAN-PENGGUNA.md`: bab baru "Persetujuan oleh Pemilik/MK" (dua pintu: tautan & kertas; apa yang tidak bisa dilakukan dari tautan).

**DoD:** uji jsdom ringan untuk kartu (opsional) + uji HTTP yang sudah ada; screenshot/GIF alur di laporan paket; suite hijau.

### P1-ENG — Modul baru `Engineering`

**Tutup:** seluruh 3.3 (⬜→✅), D10, kriteria #2, sebagian #3.

Registrasi modul: `Modules/Engineering`, prefix rute `api/engineering`, tabel `eng_`, **blok migrasi baru 001300–001399** — tambahkan barisnya ke tabel registri `CONVENTIONS.md §2` dan prefix `eng` ke `PermissionSeeder`. Dependensi: Engineering → Projects, Estimation, Core (tidak sebaliknya).

Skema:
- `core_locations` (di **Core**, karena dipakai Engineering, Quality, Projects): project_id (tanpa FK), parent_id, `kind` (tower/floor/zone/axis/room), `code`, `name`, `sort_order`. Endpoint CRUD `api/core/locations`, impor CSV lewat `master-data`.
- `eng_drawings`: project_id, `number`, `title`, `discipline` (struktur/arsitektur/mep/elv/ict), `planned_submit_date`, `status` (register). = FM-10-01.
- `eng_drawing_submittals` (`SDS/{Y}/{RM}/{N4}`): drawing_id, `revision` (R0…), `submitted_at`, `reviewer_party` (mk/owner), `decision` enum **`approved | approved_as_noted | revise_resubmit | rejected`** (= stempel FM-10 Stempel), `decided_at`, `notes`; lampiran file gambar (P0-D). Revisi baru = baris baru; yang lama `superseded`. = FM-10-03/21.
- `eng_material_submittals` (`SMS/…`): project_id, item_id nullable, `material_name`, `brand`, `spec_reference`, `sample_attached`, decision enum yang sama. = FM-10-05/22.
- `eng_transmittals` (`TRM/…`): project_id, `direction` (keluar/masuk), `to_party`, baris dokumen (morph ke drawing submittal / material submittal / dokumen lain), `received_by`, `received_at`.
- `eng_work_permits_ipp` (`IPP/{Y}/{RM}/{N4}`): project_id, `scope` (struktur/arsitek/mep), `location_id`, `description`, `planned_start`/`duration_days`, baris **bahan** (item_id, qty, unit), baris **alat**, baris **gambar** (drawing_submittal_id), baris **material approval** (material_submittal_id), `status` Approvable. = FM-10-11 & Master IPP.

Aturan:
- IPP **tidak bisa submit** bila ada baris gambar yang submittal-nya bukan `approved`/`approved_as_noted`, atau baris material yang submittal-nya belum approved; pesan 422 menyebut nomor dokumen penghambat (kriteria #2 prompt).
- `inv_issues` mendapat `ipp_id` nullable (tanpa FK). Bon yang menunjuk IPP mewarisi `wbs_task_id`-nya; bon tanpa IPP pada proyek yang **memiliki** IPP aktif memicu peringatan konfirmasi (pola `PriceDeviationService`), bukan blokir.
- Varian Material: tab baru "per IPP".
- 🧪 `eng_drawing_submittals` & `eng_material_submittals` ke `ExternalApprovableDocuments` mode transisi (keputusan MK dengan empat nilai, bukan tiga — perluas `ExternalDecision` atau petakan `approved_with_notes → approved_as_noted`; pilih yang lebih jujur dan tulis alasannya).
- Cetak: F/SD (persetujuan drawing), F/SM (material), F/TR (transmittal), F/IPP (ijin pelaksanaan) — kop 4 pihak, kolom keputusan MK dari DB.
- Seed: 3 gambar, 2 submittal, 1 IPP untuk PRJ-2026-001.

**DoD:** ≥20 uji termasuk gerbang IPP (kriteria #2 dibuktikan), revisi superseded, transmittal tanda terima, bon mewarisi WBS dari IPP, lokasi hierarkis; `ARCHITECTURE.md` diagram & `README.md` modul diperbarui; suite hijau.

### P1-QC — Modul baru `Quality`

**Tutup:** seluruh 3.8, 3.7 NCR, kriteria #3 sebagian. Blok migrasi **001400–001499**, prefix `qc`.
- `qc_inspection_templates` + `_items`: `code` (Q1…Q31), `work_package`, `stage` (before/during/after), item: `check_text`, `acceptance`, `tolerance`. Impor dari XLSX IK Inspeksi lewat `document-import` (kolom: paket, tahap, butir, kriteria).
- `qc_inspections` (`QCI/…`): project_id, `ipp_id` nullable, `location_id`, template_id, `inspected_at`, `inspector_employee_id`, `witness_party` (mk/owner), baris hasil (`item_id`, `result` ok/nok/na, `remark`), `overall` pass/fail, lampiran foto. Approvable (MK menyetujui = 🧪 eksternal transisi).
- `qc_ncr` (`NCR/…`): project_id, `inspection_id` nullable, `location_id`, `description`, `root_cause`, `corrective_action`, `preventive_action`, `responsible_employee_id` **atau** `subcontract_id`, `due_date`, `verified_by/at`, status open/under_correction/verified/closed. **NCR terbuka pada `location_id` memblokir submit inspeksi tahap berikutnya di lokasi yang sama** (422 menyebut nomor NCR).
- `qc_concrete_samples` (FM-10-24): project_id, `pour_date`, `location_id`, `grade` (K-350/fc'…), `slump_cm`, `truck_no`, `volume_m3`, `sample_count`; `qc_concrete_tests` (FM-10-23): sample_id, `age_days` (7/14/28), `strength_mpa`, `lab`, `pass` (dihitung vs target grade; rumus di service dengan komentar sumber SNI).
- BAST I prasyarat diperluas: **tidak ada NCR terbuka** (tambahkan ke `BastPrerequisiteService`, dengan pesan).
- Cetak: F/QI (checklist inspeksi terisi), F/NCR, F/BU (benda uji & hasil).

**DoD:** ≥18 uji termasuk blokir NCR, perhitungan lulus kuat tekan, prasyarat BAST; suite hijau.

### P2 — Tata kelola pengadaan (DAN 4.8 / 31 / 4.5)

**Tutup:** 3.5 baris skor, nego, keputusan pemenang, rencana; D3; kriteria #4.
- `config('erp.procurement.bid_weights')` = `['harga'=>50,'mutu'=>30,'waktu'=>5,'keuangan'=>10,'k3'=>5]` (jumlah harus 100; validasi saat boot). `prc_bid_evaluations` per RFQ: baris per vendor dengan skor 0–100 per aspek + nilai RAB pembanding; skor harga dihitung otomatis dari rasio ke RAB (tabel urutan DAN 4.8 di service, dengan komentar), aspek lain diinput. Peringkat otomatis. F/TBP diperluas menjadi tabulasi berbobot.
- `prc_negotiation_minutes` (`BAN/…`): rfq_id, vendor_id, `meeting_date`, peserta (json nama/jabatan/pihak), baris item harga awal → harga nego; lampiran daftar hadir.
- `prc_award_decisions` (`BAP/…` — periksa bentrok kode dengan BAPP; pakai `AWD/…` bila bentrok): rfq_id, vendor pemenang, `rab_amount`, `awarded_amount`, `deviation_amount` + `deviation_reason` (wajib bila > 0), anggota komite (json), Approvable dengan **ambang n-level**: perluas `config('erp.approvals')` menjadi daftar `[ ['to'=>100e6,'levels'=>1], ['to'=>1e9,'levels'=>2], ['to'=>null,'levels'=>3] ]` dan `Approvable` mendukung `required_levels` lewat hitung baris `approved` berbeda user; PO/SPK dari RFQ **tidak bisa approve** tanpa award decision approved.
- `prc_procurement_plans` + items: dari RAP (paket, metode, target tgl kontrak, PIC, status) — "Pola Belanja".

**DoD:** ≥15 uji; kriteria #4 dibuktikan (award tanpa BA nego saat harga berubah → 422); suite hijau.

### P3 — Opname owner, BAPP per zona, BAST subkon, UM & denda

**Tutup:** 3.10 baris opname owner, risalah, klaim per zona, BAST subkon; kriteria #5, #6.
- `prj_progress_measurements` (`OPN/…`) per kontrak per periode: baris per `boq_item_id` dengan `qty_prev`, `qty_this`, `qty_cum` (≤ qty kontrak + CCO; 422 bila lebih), `location_id` opsional, foto. Approvable (MK = 🧪 eksternal). Menjadi sumber `actual_pct` proyek (berbobot nilai) **menggantikan** input % manual di `prj_weekly_progress` bila ada — kompat: mingguan tanpa opname tetap manual.
- `prj_zone_certificates` (BAPP): project_id, `location_id`, `status` done/check/waiting_repair, `certified_at`, `certified_by_party`; defect terbuka di lokasi itu → tidak bisa `done`.
- Klaim owner: termin progress dapat "disusun dari opname" — `fin_ar_invoices` mendapat `measurement_id` nullable; invoice menolak memasukkan zona `waiting_repair` (kriteria #6) dan menghitung `advance_recovery_amount` proporsional terhadap termin DP yang pernah ditagih, serta `penalty_amount` (manual, wajib alasan).
- `scm_handovers` (BAST subkon 1/2) mengikuti `prj_bast` + prasyarat (opname terakhir approved, retensi belum rilis untuk BAST1).
- Cetak: F/OPN (backsheet opname), F/BAPP, F/BST-SK.

**DoD:** ≥18 uji; suite hijau.

### P4 — Mandor & upah borongan

**Tutup:** 3.5 SPK mandor, 3.10 opname mandor, 3.11 upah. Asumsi #3.
- `prc_vendors.vendor_type` enum `supplier|subcontractor|mandor|rental` (migrasi mengisi dari `is_subcontractor`; kolom lama dipertahankan, *deprecated* di komentar). CV Mandor = `prc_vendor_documents` tipe `cv_mandor` + F/CVM.
- `scm_labor_contracts` (`SP3/…`, "SP3 Induk"): vendor mandor, project_id, baris `boq_item_id` × `unit_rate` upah × `qty`; `pph_scheme` konfigurabel (bawaan PPh final per asumsi #3; bila pemilik memilih PPh 21, skema `pph21_ter` memakai mesin payroll — **siapkan cabangnya, jangan implementasikan keduanya penuh**).
- `scm_labor_claims` (opname mandor): periode, `qty_this` per item ≤ sisa, potongan kasbon (tautan `fin_kasbons`), → AP bill. Rekap upah per proyek per periode = F/RU.

**DoD:** ≥12 uji; suite hijau.

### P5 — Alat sewa & PPK berbasis periode

**Tutup:** 3.5 PPK, 3.6 monitoring sewa, 3.10 rekap tagihan alat.
- `ast_assets.ownership` enum owned/rented; untuk rented: `vendor_id`, `rental_rate`, `rate_basis` (per_bulan/per_hari_8jam/per_jam), `rental_start/end`; penyusutan dilewati; `acquisition_cost` nullable untuk rented (migrasi aman).
- `prc_work_orders` (`PPK/…`): vendor rental/jasa, project_id, baris (asset_id/description, `rate`, `basis`, `qty_periods`), periode; **tagihan per periode** dibuat dari `ast_equipment_logs` (jam) atau kalender (bulan) → AP bill; Rekap Tagihan Alat = laporan.
- Utilisasi aset mencakup rented; layar "Evaluasi sewa vs beli" (baca saja) dari log jam × tarif vs harga beli/penyusutan.

**DoD:** ≥10 uji; suite hijau.

### P6 — HSE terstruktur

**Tutup:** 3.9. Modul `Projects` (atau `Quality` bila sudah ada — pilih satu, tulis alasannya).
- `prj_hse_daily` (FM-10-13): toolbox meeting (topik, peserta), APD per kategori, temuan & tindak lanjut; terhubung ke laporan harian tanggal sama.
- `prj_risk_register` (IBPRP): aktivitas, bahaya, risiko awal (L×S), pengendalian, risiko sisa; per proyek; cetak F/IBPRP.
- Checklist 5R memakai `qc_inspection_templates` jenis `5r`.
- `prj_safety_incidents` ke `AttachableDocuments` (foto) — temuan panduan §7.7.

**DoD:** ≥8 uji; suite hijau.

### P7 — Paket tender

**Tutup:** 3.1 TKDN, RKK, metode, register lelang, kualifikasi.
- `crm_tender_packages`: lead_id, register dokumen lelang (judul, bab, tanggal terbit, addendum ke-n), BA aanwijzing (tanggal, catatan), checklist kelengkapan paket (json dari template).
- `crm_tkdn_worksheets`: per item penawaran, komponen DN/LN, % — rumus mengikuti formulir Kemenperin **terkini** (cari & kutip sumbernya di komentar; jangan memakai rumus 2013 dari korpus).
- `crm_rkk_documents`: struktur Permen PUPR 10/2021 (kebijakan, IBPRP → tautan P6, program, biaya SMKK → baris BoQ). Cetak F/RKK.
- `core_method_library`: pustaka metode kerja (kategori, paket, versi, lampiran pptx/docx — P0-D), dirujuk dari penawaran sebagai "Metode Pelaksanaan".
- Penyusun kualifikasi: dari `hr_certificates` (personil + "surat bersedia ditugaskan" F/SBD), `ast_assets` (dukungan alat F/DA), `prc_vendors` (daftar subkon).

**DoD:** ≥10 uji; suite hijau.

### P8 — Lintas-modul

**Tutup:** D2, D5, D9, D12, kriteria #8, #10.
- Token `{PROJ}` pada mask (`core_number_sequences` unik `(type, year, scope)`), opsional per jenis dokumen lewat Pengaturan.
- `core_rate_history` (opsional, asumsi: hanya untuk PPN & PPh final) — **snapshot per dokumen tetap sumber kebenaran**; tabel ini hanya riwayat.
- Impor MPP-XML (MS Project XML, bukan .mpp biner) → `prj_wbs_tasks` + baseline; uji dengan berkas contoh kecil di `tests/fixtures/`.
- Importer warisan via `document-import`: Laporan Harian (XLS korpus), Stock Card, Opname/SP3, Progress Pay mapping — pemetaan kolom didokumentasikan di `docs/IMPOR-WARISAN.md`.
- Ekspor XLSX untuk 10 formulir rumah tersering (`maatwebsite/excel` sudah ada).
- Revisi generik: `revision` + `superseded_by_id` pada dokumen yang belum punya (izin lapangan, IPP, inspeksi) — jangan menyentuh yang sudah punya pola sendiri.

**DoD:** ≥12 uji; suite hijau.

---

## 6. Laporan akhir setiap paket (format wajib) — `docs/LAPORAN-PAKET-<paket>.md`

```
# Laporan Paket <kode> — <judul>
Branch: feat/<paket> · Commit: <sha> · Tanggal

## Yang ditutup (baris Laporan Deviasi v2 → status baru)
| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

## Uji
- baru: N (daftar nama)
- lama yang diubah: N (nama + alasan)
- suite penuh: OK (N uji, N asersi, waktu)

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

## Yang sengaja tidak dikerjakan, dan mengapa

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)
```

Kalau ada bagian yang kosong, tulis "tidak ada" — jangan hapus judulnya. Laporan ini adalah satu-satunya cara pemilik memperbarui Laporan Deviasi v2 tanpa membaca ulang kode.

---

## 7. Larangan keras (ringkasan untuk dibaca ulang sebelum commit)

- Memanggil `->approve()`/`->reject()` trait langsung untuk dokumen yang punya service approve — selalu lewat service modul.
- Mengisi kolom tanda tangan Pemilik/MK dengan nama dari master proyek "supaya terlihat lengkap" — nama hanya dari keputusan yang tersimpan.
- `constrained()` lintas modul; `foreignId` ke `users`, `hr_employees`, `prj_projects` dari modul lain.
- Mengubah `core_approvals`, `core_number_sequences`, `core_attachments` tanpa migrasi kompatibel-mundur dan uji migrasi dengan data seed.
- Menaikkan batas lampiran tanpa memeriksa `post_max_size` dan catatan rsync di `DEPLOYMENT.md`.
- Menambah dependensi Composer/npm; membuat build step untuk SPA.
- Mengerjakan dua paket dalam satu branch.
- Menulis "selesai" tanpa angka suite penuh.
