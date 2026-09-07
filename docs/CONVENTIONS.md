# Module Conventions (BINDING CONTRACT)

Every module MUST follow this document exactly. Auditors reject deviations.

## 1. Module layout & wiring

```
Modules/<Name>/
  Database/Migrations/            # anonymous migration classes
  Database/Seeders/<Name>DatabaseSeeder.php   # + helper seeders it calls
  Enums/                          # PHP 8 string-backed enums
  Http/Controllers/               # thin controllers extending Modules\Core\Http\ApiController
  Http/Requests/                  # FormRequest per create/update
  Http/Resources/                 # JsonResource for main aggregates
  Models/                         # extend Modules\Core\Models\BaseModel
  Providers/<Name>ServiceProvider.php  # EXACTLY ONE provider, this name
  Routes/api.php
  Services/                       # business logic lives here, controllers stay thin
```

- Namespace root: `Modules\<Name>\` maps to `Modules/<Name>/` (PSR-4, already in composer.json).
- Modules are auto-discovered by `bootstrap/providers.php` — never edit shared files
  (`bootstrap/*`, `composer.json`, `database/seeders/DatabaseSeeder.php`, `routes/*`).
- The provider boots exactly:
  ```php
  public function boot(): void
  {
      $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
      Route::middleware('api')
          ->prefix('api/<route-prefix>')
          ->group(__DIR__.'/../Routes/api.php');
  }
  ```
- Console commands (if any) are registered in the same provider via `$this->commands([...])`.

## 2. Module registry

| Module      | Route prefix       | Table prefix | Migration block (2026_07_25_0xxxxx) |
|-------------|--------------------|--------------|--------------------------------------|
| Core        | `api/core`         | `core_`      | 000100–000199 |
| Iam         | `api/iam`          | (spatie)     | 000200–000299 |
| Crm         | `api/crm`          | `crm_`       | 000300–000399 |
| Inventory   | `api/inventory`    | `inv_`       | 000400–000499 |
| Assets      | `api/assets`       | `ast_`       | 000500–000599 |
| Estimation  | `api/estimation`   | `est_`       | 000600–000699 |
| Projects    | `api/projects`     | `prj_`       | 000700–000799 |
| Procurement | `api/procurement`  | `prc_`       | 000800–000899 |
| Subcontract | `api/subcontract`  | `scm_`       | 000900–000999 |
| HrPayroll   | `api/hr`           | `hr_`        | 001000–001099 |
| Finance     | `api/finance`      | `fin_`       | 001100–001199 |
| ServiceDesk | `api/servicedesk`  | `svc_`       | 001200–001299 |
| Engineering | `api/engineering`  | `eng_`       | 001300–001399 |
| Quality     | `api/quality`      | `qc_`        | 001400–001499 |

Migration filenames: `2026_07_25_000710_create_prj_wbs_tasks_table.php` (increment by 10
inside your block). Never use another module's block.

## 3. Shared-ID contract (cross-module references)

Canonical owner tables. Reference them with EXACTLY these column names:

| Column          | Points to             | Owner       |
|-----------------|-----------------------|-------------|
| `user_id`       | `users.id`            | app/Iam     |
| `employee_id`   | `hr_employees.id`     | HrPayroll   |
| `customer_id`   | `crm_customers.id`    | Crm         |
| `contract_id`   | `crm_contracts.id`    | Crm         |
| `vendor_id`     | `prc_vendors.id`      | Procurement (subcontractors are vendors with `is_subcontractor = true`) |
| `project_id`    | `prj_projects.id`     | Projects    |
| `boq_id` / `boq_item_id` | `est_boqs.id` / `est_boq_items.id` | Estimation |
| `item_id`       | `inv_items.id`        | Inventory   |
| `warehouse_id`  | `inv_warehouses.id`   | Inventory   |
| `account_id`    | `fin_accounts.id`     | Finance     |
| `asset_id`      | `ast_assets.id`       | Assets      |
| `service_contract_id` | `svc_contracts.id` | ServiceDesk |

**FK rule:** INSIDE your own module use `foreignId(...)->constrained(...)`. ACROSS modules
use `$table->unsignedBigInteger('project_id')->nullable(); $table->index('project_id');`
— indexed, but NO database constraint (keeps modules decoupled and migration order free).
Cross-module Eloquent relations (belongsTo another module's model) ARE allowed and encouraged.

## 4. Data types & casts

- Money: `$table->decimal('amount', 18, 2)` — IDR. Cast `'amount' => 'decimal:2'`.
- Percentages/rates: `decimal(8, 4)`. Quantities: `decimal(15, 3)`.
- Dates `date`, timestamps `dateTime`. Always `$table->timestamps();` plus `softDeletes()`
  on master data and document headers (not on line/detail tables).
- Status columns: `$table->string('status', 30)->default('draft');` cast to an enum.
- Every document header has `code` (`$table->string('code', 40)->unique();`) filled by
  the numbering trait (section 6).

## 5. Base classes (Modules/Core — READ THE SOURCE FIRST)

- Models extend `Modules\Core\Models\BaseModel` (`$guarded = []`).
- Controllers extend `Modules\Core\Http\ApiController`; respond with
  `$this->ok($data, $message?)`, `$this->created($data)`, `$this->error($msg, $status)`.
  Lists: `return $this->ok(SomeResource::collection($query->paginate($request->integer('per_page', 20))));`
- Generic document status enum: `Modules\Core\Enums\DocumentStatus`
  (`draft, submitted, approved, rejected, closed, cancelled`). Add module-specific enums
  only for genuinely different lifecycles (e.g. ticket status).
- Approval flow: use trait `Modules\Core\Traits\Approvable` (gives `submit() / approve() / reject()`
  + `approvals()` morph). Documents needing approval: PR, PO, SPK, claims, invoices, payroll runs,
  BOQ versions, stock adjustments.
- Document numbering: use trait `Modules\Core\Traits\HasDocumentNumber` and set
  `public string $documentType = 'PO';` on the model. Type keys come from `config/erp.php`
  → `documents`. A mask may carry `{PROJ}` (the document's project CODE; sequence then
  runs per `(type, year, project)`) — only for types whose documents always carry a
  project: a `{PROJ}` mask on a project-less document fails the mint loudly (P8).
- Generic revision (P8): a document that needs Rn revisions and has no pattern of its
  own uses trait `Modules\Core\Traits\Revisable` (columns `revision`, `superseded_at`,
  `superseded_by_id`; a revision is a NEW row via the module service's `revise()`, the
  predecessor keeps number/status/approval history and stays printable; guard every
  action with `assertRevisiBerlaku()`). Documents with their own versioning pattern
  (DrawingSubmittal, ProjectBaseline, MethodLibraryEntry, BOQ versions, quotation
  revisions) do NOT take this trait.
- Helpers: `Modules\Core\Support\Terbilang::rupiah()` (amount → Indonesian words),
  `Modules\Core\Support\Money::format()`.

## 6. API conventions

- Auth: every route group wrapped in `->middleware('auth:sanctum')`.
  Guard write/approve endpoints with `->middleware('permission:<prefix>.<action>')` where
  prefix = table prefix without underscore (`crm`, `prj`, …) and action ∈
  `view, create, update, delete, approve, post`.
- Standard endpoints per aggregate: `GET /` (paginated, `q` search param, sensible filters),
  `POST /`, `GET /{id}`, `PUT /{id}`, `DELETE /{id}`, plus lifecycle actions as
  `POST /{id}/submit`, `POST /{id}/approve`, `POST /{id}/reject`, and domain actions
  (`POST /invoices/{id}/payments`, `GET /projects/{id}/s-curve`, …).
- Validation ALWAYS via FormRequest. Line items validated as nested arrays
  (`items.*.item_id` etc.) and replaced wholesale on update.
- Header+lines documents are created/updated inside `DB::transaction()` in a Service class.

## 7. Language & domain terms

Code, identifiers, comments: English. Indonesian domain terms stay Indonesian where the
industry uses them — enum values/labels and seed data may use RAB, RAP, AHSP, BAST, SPK,
opname, termin, retensi. UI-facing labels (resource `label` fields) should be Indonesian.

## 8. Seed data canon (use these EXACT codes for cross-module references)

Seeders are idempotent: `Model::updateOrCreate(['code' => ...], [...])`. When referencing
another module's rows, look them up by these canonical codes and skip gracefully
(`if (! $row) return;`) if the other module isn't seeded yet.

- Customers: `CUST-0001` PT Graha Sentosa Propertindo (developer),
  `CUST-0002` PT Bank Artha Nusantara (bank), `CUST-0003` RS Medika Husada (hospital).
- Contracts: `CTR/2026/I/0001` (konstruksi: Gedung Kantor Graha Sentosa, Rp 48.5 M),
  `CTR/2026/II/0002` (integrasi: ELV & ICT 12 cabang Bank Artha, Rp 9.8 M),
  `CTR/2026/III/0003` (maintenance CCTV & akses kontrol RS Medika, Rp 480 jt/tahun).
- Projects: `PRJ-2026-001` Pembangunan Gedung Kantor Graha Sentosa (8 lantai, Jakarta Selatan),
  `PRJ-2026-002` Instalasi ELV & Data Center Bank Artha Nusantara.
- Addenda CTR/2026/I/0001 (`crm_contract_change_orders.customer_ref`, kodenya sendiri
  dibangkitkan): `ADD-I/GSP/2026` tambah volume galian 800 m3, `ADD-II/GSP/2026` kurang
  lingkup MEP lump sum senilai sama — **sepasang dan saling meniadakan**, sehingga nilai
  kontrak demo tetap Rp 48.5 M (= total BOQ/2026/0001). `ProjectsDatabaseSeeder` mencari
  keduanya lewat `customer_ref` itu untuk mengisi register volume `prj_contract_variations`.
- Lokasi (`core_locations`): `GSP-T1` Gedung Utama · `GSP-T1-L01` + `GSP-T1-L01-ZA`
  (EngineeringDatabaseSeeder) · `GSP-T1-L05` + `GSP-T1-L05-ZA`/`-ZB` (ProjectsDatabaseSeeder,
  zona BAPP dan baris opname per zona). Menara `GSP-T1` di-`updateOrCreate` dengan muatan
  yang sama oleh kedua seeder — mana pun yang jalan lebih dulu yang membuatnya.
- Vendors: `VND-0001` PT Semen Distribusi Utama, `VND-0002` CV Baja Mandiri,
  `VND-0003` PT Elektrindo Supply (ICT distributor), `VND-0004` CV Karya Sipil Sejahtera
  (subcontractor, sipil), `VND-0005` PT Mekanika Prima (subcontractor, ME),
  `VND-0006` Mandor Harjo Wibowo (`vendor_type` mandor, non-PKP, jasa — P4; register
  dokumennya memuat K3L + pakta integritas + CV mandor `cv_mandor`),
  `VND-0007` PT Alat Berat Nusantara (`vendor_type` rental, PKP, jasa — P5; lessor
  aset sewa demo).
- SP3 mandor (P4): `SP3/2026/III/0001` upah borongan pasangan bata & plesteran
  (VND-0006 × PRJ-2026-001, approved, PPh final UMKM 0,5%) dengan opname mandor
  `OPM/2026/III/0001` (approved, tanpa potongan kasbon — kasbon demo milik seeder
  Finance, menautkannya lintas-seeder rapuh terhadap urutan).
- Aset sewa (P5): `AST-0007` Excavator Doosan DX225LCA (sewa dari VND-0007,
  Rp 400.000/jam, periode 2026-06-01 s/d 2026-12-31) — di-`updateOrCreate` dengan
  muatan yang sama oleh `AssetsDatabaseSeeder` DAN `ProcurementDatabaseSeeder`
  (pola menara `GSP-T1`: mana pun yang jalan lebih dulu yang membuatnya, karena
  baris per_jam PPK demo harus bisa menunjuk alatnya berapa pun urutan seed).
  Register hour-meter demonya 3.240,0 → 3.375,5 (Juli 2026, = 135,5 jam).
- PPK alat & jasa (P5): `PPK/2026/VI/0001` sewa excavator per jam + scaffolding per
  bulan (VND-0007 × PRJ-2026-001, approved, PPN 11%, nilai plafon Rp 585 jt) dengan
  tagihan periode `PPKB/2026/VII/0001` (Juli 2026: 135,5 jam dari register + 1 bulan
  kalender = Rp 69,2 jt). Tagihan AP-nya sengaja TIDAK diseed — membuatnya adalah
  alur demo, dan seeder Finance memiliki jurnalnya sendiri (pelajaran P4).
- Items: `ITM-0001` Semen Portland 50kg (zak), `ITM-0002` Besi Beton D16 (btg),
  `ITM-0003` Kabel UTP Cat6 (roll), `ITM-0004` CCTV Dome 4MP (unit),
  `ITM-0005` Pasir Beton (m3), `ITM-0006` Switch Managed 24 Port (unit),
  `ITM-0007` Ready Mix K-300 (m3), `ITM-0008` Access Point WiFi 6 (unit).
- Warehouses: `WH-PUSAT` Gudang Pusat (Cakung), `WH-PRJ-2026-001`, `WH-PRJ-2026-002` (site).
- Employees: `EMP-0001` Budi Santoso (Direktur), `EMP-0002` Rina Wijaya (Project Manager),
  `EMP-0003` Agus Prasetyo (Site Manager), `EMP-0004` Dewi Lestari (Finance Manager),
  `EMP-0005` Andi Kurniawan (Procurement), `EMP-0006` Siti Rahayu (HR & GA),
  `EMP-0007` Joko Susilo (Teknisi ELV), `EMP-0008` Made Wirawan (Drafter/Estimator).
- COA roots: 1-xxxx Aset, 2-xxxx Kewajiban, 3-xxxx Ekuitas, 4-xxxx Pendapatan,
  5-xxxx Beban Proyek (HPP), 6-xxxx Beban Operasional, 7-xxxx Pendapatan/Beban Lain.

## 9. Builder output expectations

Every module ships: migrations, models (relations + casts), enums, services with the real
business math, FormRequests, Resources, thin controllers, routes, and a seeder producing a
believable demo dataset that exercises the module (documents in several statuses).
No TODO stubs for core flows. PHP 8.2+, typed signatures, `declare(strict_types=1);` NOT
used (match Laravel skeleton style). Tests are optional; correctness of business math is not.

## 10. Pustaka vendor SPA (`public/app/vendor/`)

Aturan (ROADMAP-HASHMICRO §5 keputusan #2): **tanpa CDN, tanpa npm saat runtime, tanpa pustaka
lain tanpa keputusan pemilik**. Yang di-vendor hanya SortableJS 1.15 dan sprite ikon Lucide subset,
masing-masing di `public/app/vendor/<lib>@<ver>/` bersama LICENSE-nya; grafik ditulis sendiri
(`js/charts.js`, §11). Manifestnya `public/app/vendor/VENDOR.md` — per pustaka: versi, URL sumber,
sha256 tarball, lisensi, gzip terukur, untuk apa, cara memperbarui (perintah persis); per berkas:
sha256. Uji `tests/Feature/Core/VendorManifestTest` memaku semuanya: setiap berkas vendor ada di
manifest dengan sha yang sama dan sebaliknya; tidak ada `<script src>`, `<link href>`, `import`,
`import()`, `new URL`, `fetch`, `url()`, `@import` yang menunjuk `http(s)://` atau `//host` di mana
pun di bawah `public/app` — `srcset`/`imagesrcset` diperiksa per kandidat, bukan hanya kandidat
pertamanya; literal http(s) yang bukan pemuat hanya boleh namespace W3C, tautan `<a>`/`href:`,
atau komentar (aturan tertulis di uji — tambah aturan, bukan allowlist). Aturan `href:` bukan
regex tetapi pindaian kurung berimbang: literal itu harus nilai langsung kunci `href` di argumen
objek pertama pemanggilan `el('a…', { … })` **terdalam** yang melingkupinya (urutan kunci bebas,
`el('div', {}, el('a', { onclick, href }))` sah), sedangkan `el('link'|'script'|'img'|'iframe'|
'source'|'video'|'audio'|'embed'|'object'|…, { src|href|srcset|poster|… })` ke luar adalah pemuat
betapa pun dalamnya ia bersarang di `el('a')` — `ui.js el()` memanggil `setAttribute`; jumlah gzip ≤ 60 KB
(dicetak saat uji); sprite XML sah dengan `<symbol id="lucide-…" viewBox>`; `Sortable.min.js`
identik dengan sha manifest.

Cara memakai: ikon lewat `ui.js svgIcon(nama, { size, label })` → `<svg class="lucide"><use
href="vendor/lucide@<ver>/sprite.svg#lucide-<nama>">` (nama kanonik Lucide; nama `icon()` lama
dipetakan). Sortable dimuat malas oleh layar yang memakainya (`<script src="vendor/sortablejs@
<ver>/Sortable.min.js">` sekali, lalu global `Sortable`) — bukan oleh shell, supaya layar yang
tidak menyeret apa pun tidak membayarnya. Memperbarui versi = folder baru, ubah rujukan
(`LUCIDE_SPRITE` di ui.js / pemuat Sortable), hapus folder lama, tabel manifest ditulis ulang
dari perintah di VENDOR.md, uji hijau.

## 11. Grafik (`public/app/js/charts.js`)

Semua grafik baru memakai `js/charts.js` — lima fungsi murni yang mengembalikan `<svg>`:
`lineChart`, `barChart`, `donutChart`, `sparkline`, `ganttChart`. **Docblock di kepala berkas
itu adalah referensi API-nya** (parameter, bawaan, perilaku data kosong/celah/satu titik/negatif,
gantt terbuka); jangan menyalin ulang aturannya ke sini. Yang wajib dipegang pemanggil:

- Warna hanya lewat token `--chart-1..8` (seri), `--chart-grid/-axis/-text/-today/-weekend/
  -baseline` (app.css, dua tema + blok cetak; rasio kontras ≥ 3:1 terhadap `--surface` tercatat di
  komentar tokennya). Tidak ada literal warna di charts.js maupun di pemanggil — kalau butuh warna
  khusus (mis. GRN vs PO), itu seri sendiri dengan label di legenda.
- Data kosong/null → grafik memasang placeholder "Belum ada data" (`data-empty="true"`); pemanggil
  boleh menggantinya dengan `ui.emptyState()`, tetapi tidak boleh mengganti null menjadi 0 sebelum
  memanggil grafik (aturan kejujuran §6). Nilai yang tak terukur dikirim sebagai `null`.
- Format angka/tanggal diberikan pemanggil (`yFormat`, `valueFormat`, `xFormat`) dari `format.js`
  (`fmt.rupiahShort`, `fmt.percent`, `fmt.date`) supaya sumbu, `<title>`, dan tabel di bawahnya
  memakai format yang sama. **`yFormat` bukan hanya sumbu**: charts.js memakainya juga untuk
  `<title>` bawaan setiap titik tanpa judul sendiri, jadi `` `${v}%` `` mencetak titik desimal
  Inggris di aplikasi berkoma — pakai `fmt.percent` (verifikasi P1-E).
- **Lebar viewBox mengikuti lebar layar**: `chartWidth()` (720 desktop / 380 di ≤ 560 px). svg
  ber-viewBox tetap diregangkan CSS ke lebar kartunya, jadi 720 pada kartu ponsel 328 px
  menuliskan legenda dan label sumbu pada 5,0 px terbaca. Diukur S20em, lantai 9 px.
- **Seri yang DIUKUR lebih tebal daripada seri acuannya**: `series[].width` (bawaan 2, dijepit
  1–4; 2,5 untuk seri terukur). Hierarki ini dulu hidup di `.chart .act` grafik tangan dan hilang
  tanpa suara saat charts.js menuliskan 2 untuk semuanya (verifikasi P1-E).
- **Pola putus yang ditulis pemanggil menang DI KERTAS juga**: aturan blok cetak memakai
  `:not([stroke-dasharray])`, karena deklarasi CSS mengalahkan atribut presentasi. Tanpa itu
  setiap `dash` yang dipilih pemanggil hilang begitu halamannya dicetak.
- Setiap mark membawa `<title>`; harness S20 (`docs/bukti-uji/harness-playwright.py`) menghitung
  `.mark > title` == `.mark`, warna terkomputasi == token di tema terang & gelap, dan placeholder — jangan
  menambah `<title>` di luar mark (legenda, label) karena hitungan `<title>` liar akan pecah. Satu
  pengecualian yang disengaja: label gantt yang dipotong (`data-truncated`) membawa nama lengkapnya.
- Gantt dibungkus `<div class="chart-scroll">` (menggulir mendatar di ponsel); grafik lain
  langsung di `.card-body`.
- **Tidak ada lagi grafik tangan (P1-E).** Kurva-S (`views/project.js`), kurva EVM
  (`views/evm.js`) dan tren harga satuan (`views/hargasatuan.js`) sekarang memanggil
  `lineChart`; ketiganya hanya menyusun DATA. `ChartMigrationTest` menolak
  `document.createElementNS` yang kembali ke ketiga berkas itu — grafik tangan keempat akan lahir
  tanpa token, tanpa `<title>` per tanda, dan tanpa blok cetak, yaitu persis tiga hal yang P1-A
  dibangun untuk memberikannya. Butuh sesuatu yang belum ada? Tambahkan di `charts.js`, supaya
  SEMUA grafik ikut mendapatkannya.
- **Sifat yang milik PEMANGGIL, bukan grafik**, dan karena itu dipaku uji per layar: sumbu EVM
  yang boleh naik melewati 100 % (`yMax = Math.max(100, …)` — sumbu yang ditahan di 100 % memotong
  garis biaya justru pada proyek yang sudah melewati anggarannya), dan sumbu tren harga yang TIDAK
  dipaksa memuat nol (`yMin/yMax` sendiri — bawaan charts.js selalu memuat nol, dan tren
  12.500 → 13.750 pada sumbu 0..14.000 tampak datar).
- **Satu legenda per grafik.** `charts.js` menggambar legendanya di dalam svg (ikut tercetak, ikut
  ter-skala), jadi blok `.legend` DOM di sebelahnya dibuang. Yang tersisa memakainya hanya tren
  harga satuan, karena pembedanya per TITIK (PO vs GRN pada satu garis kronologis, `points[].token`)
  dan itu tidak bisa dinyatakan legenda per-seri; swatch-nya memakai token `--chart-*` yang sama
  dengan titiknya. Alasannya KERTAS, bukan layar: blok cetak hanya menukar token `--chart-*`
  menjadi abu-abu, jadi titik ber-`--warning` tercetak BERWARNA di tengah grafik yang seluruhnya
  abu-abu (terukur 6 Sep 2026: `--warning` #96601a di layar dan di cetak, `--chart-7` #a16207 →
  #363636). Sebelum verifikasi P1-E alasan yang ditulis di sini adalah "swatch tercetak berbeda
  dari titiknya", dan itu tidak pernah benar — keduanya memakai `--warning` yang sama.

## 12. Aksen modul (`--accent-1..8`, P1-B)

Delapan slot warna departemen di `public/app/app.css` — `--accent-<n>`, `--accent-<n>-soft`,
`--accent-<n>-fg` didefinisikan **lima kali**: empat blok tema (root terang, media gelap,
`data-theme` terang/gelap) dan blok token `@media print`, yang memaksa ketiganya ke nilai TERANG
supaya pengguna bertema gelap tidak mencetak aksen gelap di kertas putih (slot 7 `#fbcd1a` = 1,52:1
sebelum blok itu ada). Slot baru harus ditambahkan di kelimanya.
Setiap grup NAV membawa `prefix` (prefix izinnya; Ringkasan = `ringkasan`) dan `schema.js MODULES`
memetakan prefix → `{ accent, icon, description }`. Pemetaan 14 grup → 8 slot, diturunkan dari isi
NAV (bukan grup baru):

| Slot | Grup NAV (prefix) | Alasan |
|---|---|---|
| 1 | Proyek (`prj`) | lapangan; slot 1 = `--primary` = `--chart-1` persis |
| 2 | Keuangan (`fin`) | keuangan & pajak |
| 3 | Pengadaan (`prc`) · Persediaan (`inv`) · Subkontrak (`scm`) | rantai pasok; subkon adalah vendor (`is_subcontractor`) |
| 4 | Penjualan (`crm`) · Estimasi (`est`) | komersial; RAB disusun bersama tender |
| 5 | SDM & Payroll (`hr`) | |
| 6 | Aset (`ast`) | peralatan |
| 7 | Layanan (`svc`) | tiket & kontrak layanan |
| 8 | Sistem (`iam`) · Mutu (`qc`) · Engineering (`eng`) · Ringkasan | fungsi penunjang, slot netral (abu kebiruan) |

**Hubungan dengan grafik:** `--accent-n` memakai sudut hue Lab yang sama dengan `--chart-n`
(toleransi ±12°), hanya L dan C yang digeser sampai semua pasangan slot berjarak **≥ 20 ΔE2000**
— palet grafik apa adanya gagal (terang 2–6 = 10,2; gelap 1–8 = 13,8). Terukur 5 Sep 2026
(CIEDE2000 di Lab; skrip turunan di scratch P1-B, matriks lengkap di komentar token app.css):
minimum antar slot **20,1 terang / 20,9 gelap** (hue vs `--chart-n` maks 11,8° terang / 7,3° gelap);
kontras WCAG aksen di `--surface` ≥ **5,20** terang /
**5,41** gelap (batas 3:1; dipasang ≥ **5,2** karena aksen dipakai sebagai teks remah — angka yang
sama dengan komentar blok token app.css), `-fg` di aksen
≥ 5,20 / 5,86, aksen di `-soft` ≥ 4,62 / 4,82. Harness **S21** mengukur nilai yang hidup di
halaman (kedua tema, desktop + ponsel) dan menulisnya ke `results-phase-1.json`; uji
`SidebarNavWiringTest` memaku slot 1..8 dan ketiga tokennya tepat lima kali (empat blok tema +
blok `@media print`), sedangkan S21 `print_accents` mengukur nilai cetaknya di kertas
(minimum 5,20 di `#ffffff`).

**Di mana aksen boleh tampil** (dan hanya di sini): penanda grup aktif di sidebar
(`.nav-group.has-active > button`: batang 3 px + judul), remah modul di bilah atas
(`#crumbs a.crumb-module`, tautan ke `#/m/<prefix>`), kepala beranda modul (`.module-head`), dan
tepi kartu launcher (P1-C). Mekanismenya satu atribut `data-accent="n"` yang app.css ubah menjadi
`--module-accent`/`-soft`/`-fg`; aturan komponen hanya menyebut ketiga variabel itu. **Tidak
pernah** pada lencana, alert, tombol, atau status — semantik `--success/--warning/--danger` tetap.
Catatan jujur: slot 3/6/7 sekeluarga hue dengan success/danger/warning (warisan palet grafik);
itulah sebabnya aksen tidak boleh muncul di bentuk yang sama dengan lencana.

## 13. Kepadatan (`--row-h`, P1-B)

Tiga profil, `data-density` di `<html>`: `compact` 32 px · `normal` 38,5 px · `comfortable` 48 px
per baris satu-baris `table.data`. **`normal` = angka yang diukur sebelum token ada** (13 px × 1,5 +
2 × 9 padding + 1 border = 38,5; berlencana 41,13; bertombol aksi 47) sehingga tanpa pilihan
tidak ada yang bergeser — lantai 38,5 tidak pernah mengikat. `compact` menurunkan padding ke 3 px
dan tombol aksi baris ke 24 px (hanya `pointer: fine` — di layar sentuh sasaran jempol 36 px
menang, baris rapat bertombol 43 px dan baris bertombol `normal` maupun `comfortable` 55 px;
tautan sidebar di layar sentuh ≥ 36 px di semua profil, baris radio dialog ≥ 40 px, dan petunjuk
dialog di sana menyebut dua angka "teks · bertombol"), `comfortable` menahan padding dan menaikkan lantai; di
kedua profil itu **semua** baris satu-baris tepat 32/48 (kunci S21 yang benar-benar ditulis harness:
`density.compact_ok` / `comfortable_ok`, `normal_equals_baseline` — termasuk tfoot daftar PO —
dan `tfoot_normal_41`). Token turunan: `--cell-py/--cell-px` (td, th), `--foot-py` (tfoot: 10 px normal —
angka sebelum token, baris total 41 px — · 4 rapat · 10 lega), `--nav-py` (baris sidebar), `--form-gap`
(`.form-grid`), `--kv-gap` (`.kv`). Kontrol: dialog Akun › **Kepadatan**
(tiga radio: Padat · Normal · Lega — "Padat", bukan "Rapat", yang di ERP terbaca sebagai
pertemuan), berlaku seketika. Simpanan: `localStorage`
`nusantara_erp_density:<id pengguna>` (`personalKey`, seperti favorit) dengan nilai
`compact|normal|comfortable`, dipasang saat evaluasi modul app.js dan lagi di `boot()` — sebelum
shell digambar, tanpa kedipan. **Sejak P1-C nilainya preferensi SERVER** (`core/me/preferences`,
§15); `localStorage` tinggal cermin yang menjawab seketika, dan kunci `nusantara_erp_density:<id>`
dinaikkan sekali lalu dihapus oleh `js/prefs.js`.

## 14. Keadaan kosong berilustrasi (`ui.emptyState`, P1-B)

`emptyState(message, { title, action, kind, compact })`; tanda tangan lama (`message`, `{ title,
action }`) tetap sah — bawaan `kind: 'inbox'`. Lima jenis di `js/illustrations.js`:

| kind | Arti | Pemakai |
|---|---|---|
| `inbox` | belum ada yang tercatat | daftar tanpa baris (+ Tambah), pemberitahuan, kalender, beranda modul tanpa layar yang boleh dibuka |
| `search` | pencarian tanpa hasil | daftar dengan `q` saja (`Tidak ada <label> yang cocok dengan "<q>".` + **Hapus pencarian**) |
| `filter` | filter menyaring semuanya | daftar tersaring (`Tidak ada <label> yang lolos filter yang dipasang.` + **Hapus filter**) |
| `error` | sumbernya gagal, bukan kosong | kotak masuk/Tugas Saya saat `meta.failed` — jangan pernah `inbox`/`done` untuk kegagalan |
| `done` | semuanya selesai | kotak masuk kosong, piutang/tiket tanpa yang tertunda, "Stok aman" (Saldo Stok), "Laci bersih" (Kasir Kas Kecil) |

Aturan gambar: SVG garis (stroke) 120 × 120, dibaca di 96–120 px (ubin `compact` 72 px), **tanpa
satu pun literal warna** — bentuk hanya membawa kelas `.ln/.ac/.fl/.fa` dan app.css memberi token
(`--border-strong`, `--primary`, `--surface-3`, `--primary-soft`; `error` → `--danger`, `done` →
`--success`), sehingga tema gelap otomatis dan S21 mengukur stroke terkomputasinya (`empty_kinds`);
≤ 1,5 KB per gambar (terukur 402–521 B). `list.js` membedakan "belum ada baris" dari "tersaring
habis", dan yang tersaring habis menyebut penyaringnya: pencarian (`… yang cocok dengan "<q>".` +
Hapus pencarian) atau filter (`… yang lolos filter yang dipasang.` + Hapus filter) — tiga kalimat,
tiga gambar; judul tidak mengulang kalimatnya. Ilustrasi
baru = entri di `ILLUSTRATIONS` + baris di tabel ini.

## 15. Preferensi pengguna (`core_user_preferences`, P1-C)

Apa pun yang seseorang PILIH untuk dirinya sendiri — favorit, "Terakhir dibuka", kepadatan, susunan
dasbor (P1-D) — hidup di `core_user_preferences` (satu baris per pengguna per kunci,
`UNIQUE(user_id, key)`), bukan di `localStorage`. Alasannya diukur: sampai P1-B ketiganya berkunci
`<nama>:<id pengguna>` di peramban, jadi bintang yang dipasang di desktop kantor tidak ada di tablet
lapangan milik orang yang sama, dan "Hapus data situs" menghapus semuanya tanpa jejak.

**Whitelist, bukan kolom bebas.** `Modules\Core\Support\UserPreferences::keys()` — satu entri per
kunci dengan `label`, `max_bytes`, `max_entries`, dan `validate`. Kunci di luar daftar dijawab
**422 yang menyebut kuncinya**; plafon keras **16 KB** per nilai (`MAX_BYTES`), tiap kunci boleh
lebih ketat. Angka-angka itu ditulis literal di `UserPreferencesTest` — sampai verifikasi P1-C
uji plafon membangun muatannya DARI konstanta yang diujinya, jadi menaikkan 16384 → 32768 lolos
hijau. Tanpa
daftar itu `PUT core/me/preferences/{key}` — yang sengaja tanpa gerbang izin, karena barisnya milik
pemanggil sendiri dan tidak ada parameter yang bisa menyebut orang lain (pola `GET core/inbox`) —
adalah penyimpanan bebas 16 KB × kunci sebanyak-banyaknya × jumlah pengguna, ikut ke setiap backup.

| kunci | isi | plafon |
|---|---|---|
| `favorites` | daftar rute NAV yang dibintangi (keanggotaan NAV diperiksa `Support\SpaNav`) | 50 entri / 4 KB |
| `recent` | `{route,label,sub,at}` dokumen terakhir dibuka; field di luar keempatnya ditolak | 20 entri / 8 KB |
| `density` | `compact` \| `normal` \| `comfortable` (§13) | 64 B |
| `dashboard.layout` | susunan dasbor P1-D: `[{id,size}]`, id diperiksa `Support\SpaWidgets`, size ∈ {kecil, sedang, lebar}, duplikat ditolak | 24 entri / 16 KB |
| `launcher.hidden` | prefix modul yang disembunyikan dari `#/home` | 32 entri / 512 B |

**Kejujuran.** Kunci yang belum pernah dipilih **tidak punya baris**; bawaan (`normal`, `[]`) milik
SPA. Baris `density: 'normal'` yang ditulis server berbohong bahwa orangnya pernah memilih.

**Plafon diumumkan, bukan disalin.** `GET core/me/preferences` menjawab `meta.keys` =
`UserPreferences::describe()`, satu objek `{key, label, max_bytes, max_entries}` per kunci, dan
`prefs.js` MEMBACANYA (`api.list`, karena `api.get` membuang meta). Bentuk lama — daftar nama +
`MAX_BYTES` saja — menjanjikan pencegahan yang tidak pernah terjadi: tidak ada yang membacanya,
klien tetap menyalin 50/20 sendiri, dan 16384 yang diumumkannya bukan plafon yang berlaku untuk
`favorites` (4096) maupun `recent` (8192).

**`SpaNav`** membaca rute dan prefix NAV dari `public/app/js/schema.js` (memo per proses). Menyalin
131 rute ke PHP akan basi pada sunting pertama, dan yang basi di sini adalah VALIDATOR. Berkas tidak
terbaca → daftar kosong → validator jatuh ke pemeriksaan bentuk saja; bintang yang ditolak karena
deploy terbaca sebagai bintang yang rusak.

**Sisi SPA** — `js/prefs.js`, dan hanya berkas itu yang boleh menyentuh kunci warisan (dipaku
`LauncherWiringTest`). Server adalah kebenaran; `localStorage` adalah CERMIN, untuk tiga hal yang
butuh jawaban seketika: kepadatan dipasang sebelum shell digambar (tanpa cermin ada kedipan), antrean
Lapangan yang luring, dan sesi yang berakhir di tengah kerja. Migrasi satu kali dijalankan **per
kunci**: server yang sudah punya barisnya MENANG, dan kunci lokal hanya dihapus setelah server
benar-benar punya nilainya. `set()` optimistis (cermin dulu, PUT menyusul); PUT yang tidak pernah
sampai dicoba lagi pada boot berikutnya. `load()` mengumumkan `erp:prefs-loaded`, dan layar yang
sudah tergambar dari cermin (launcher, beranda modul) menggambar ulang BAGIANNYA — bukan rutenya,
yang berarti setiap permintaan layar berjalan dua kali.

## 16. Registri `ModuleCounts` (P1-C)

Satu angka utama per modul, dipimpin ubin launcher `#/home` dan kepala beranda modul `#/m/<prefix>`.
`Modules\Core\Support\ModuleCounts::entries()` — satu entri per prefix grup NAV, **dalam urutan NAV**;
kelengkapan dan urutannya dipaku `ModuleCountsTest` terhadap `schema.js`, jadi grup ke-15 tanpa entri
menjatuhkan uji alih-alih diam-diam menghasilkan ubin tanpa angka selamanya.

Per entri: `label` (nama angkanya), `unit` (ubin menulis "7 proyek", bukan "7"), `permission`
(null = semua yang punya sesi), `tables` (setiap tabel yang disentuh; dijaga `Schema::hasTable`
dengan memo per proses, di-flush `ErpTestCase::setUp`), `count` (**satu** kueri `DB::table`), dan
`why` — alasan angka INI, bukan angka lain, yang memimpin modulnya.

**Biaya kuerinya diukur, bukan diasumsikan.** `EXPLAIN` keempat belas kueri di MySQL 8
(verifikasi P1-C putaran 2, 6 Sep 2026) menemukan tiga pemindaian tabel penuh (`type=ALL key=NULL`):
`qc_ncr.status`, `hr_leave_requests.status`, dan `eng_drawing_submittals(decision, superseded_at)`.
Ketiganya sekarang berindeks (migrasi Core `000196`, hanya indeks, berpenjaga `Schema::hasTable`),
dan `ModuleCountsTest::test_the_scanning_counts_have_their_indexes` menjaga agar tidak hilang lagi —
sejak P1-C hitungan ini berjalan setiap kali launcher `#/home` dibuka, yaitu landing ponsel setiap
pengguna. Satu pemindaian TERSISA dan disengaja: entri `inv` membandingkan `b.qty < i.min_stock`
antar dua tabel, dan tidak ada indeks yang bisa melayani perbandingan antar kolom; bila
`inv_stock_balances` tumbuh melewati ~100 rb baris, angka itu perlu tabel ringkasan, bukan indeks.
Entri baru: jalankan `EXPLAIN`-nya dan tulis hasilnya di sini atau tambahkan indeksnya.

`label` punya CERMIN di klien: `schema.js` `MODULES[prefix].kpi`. Ia ada karena ubin harus bisa
menyebut angka yang tidak dikirim server — entri yang izinnya tidak dipegang tidak ada di jawaban,
jadi tanpa cermin itu ubinnya menulis `—` telanjang tanpa satu kata pun. Kesetaraan kedua daftar
dipaku `ModuleCountsTest`.

Dua aturan yang sama dengan `WatchedDeadlines`: **tanpa mengimpor modul fitur** (literal string,
dipaku uji, jadi penggantian nama status di lane tim lain menjatuhkan uji dan bukan mengosongkan
ubin) dan **degradasi per entri**. `deleted_at` diperiksa tangan di setiap kueri — `DB::table`
melewati scope `SoftDeletes`.

Klaim "dipaku uji" itu hanya sekuat fixture-nya: satu baris per status membuat angka harapan (1)
benar untuk status apa pun, dan sampai verifikasi P1-C empat mutasi status/scope lolos hijau. Sejak
itu tiap entri berstatus punya **2 baris yang masuk hitungan dan 1 per status yang tidak**, satu
baris yang **sudah dibuang** di tiap tabel penghapus-lembut, dan baris untuk status yang
diperdebatkan entri itu sendiri (`svc` `pending_customer`) — 12 mutasi status/scope merah.

**Absen ≠ 0.** Izin tidak dipegang, atau tabel belum ada → entri **TIDAK ADA**. Kueri melempar →
`count: null` + `Log::warning`, tidak pernah 500. Sebuah 0 adalah pernyataan ("saya menghitung, dan
hasilnya nol"); "0 tiket" di layar orang yang memang tidak boleh melihat tiket adalah kebohongan
yang tampak seperti kabar baik. SPA menulis `—` untuk keduanya.

**Satu kueri per entri adalah batasan yang dipilih**: blok ini ikut jawaban dasbor
(`?include=modules`) dan endpoint launcher, keduanya dibaca di ponsel lapangan. Angka yang butuh join
berlapis atau "baris terakhir per grup" (mis. "aset jatuh tempo servis", yang aturannya sudah
dimiliki `WatchedDeadlines`) sengaja tidak diambil: salinan kedua sebuah aturan adalah penyimpangan
yang paling mahal. Tiga angka yang SUDAH punya pemilik lain dipaku setara — `prj` = dasbor
`projects.active_count`, `fin` = dasbor `ar_invoices.open_count`, `inv` =
`StockService::lowStockAlerts()->count()`.

**Endpoint.** `GET core/modules` (launcher) dan blok `modules` pada `GET core/dashboard/summary`
**hanya bila `?include=modules`** — tanpa parameter itu jumlah permintaan dan bentuk jawaban dasbor
tidak berubah sedikit pun (target metrik Fase 1). Keduanya tanpa gerbang izin, pola
`search`/`calendar`: registri menyaring dirinya sendiri per entri.

## 17. Widget dasbor (`public/app/js/views/widgets/`, P1-D)

Dasbor `#/dashboard` adalah **penyusun**, bukan penggambar. `views/dashboard.js` membaca susunan
orangnya, menggambar kerangka kartu dalam urutan itu, lalu memanggil `views/widgets/<id>.js` **per
batch 4**. Setiap angka, tabel dan cabang "gagal dimuat" hidup di berkas widget-nya sendiri.

**Katalog = satu daftar deklaratif** (`views/widgets/registry.js`), selera yang sama dengan
`ModuleCounts` / `UserPreferences`: widget berikutnya adalah satu entri array + satu berkas.
Per entri — `id` (nama berkas DAN kunci preferensi), `title`, `desc` (kalimat di laci), `module`
(prefix grup NAV → aksen §12), `perm` (nama izin, `null`, `'*.approve'`, atau daftar "salah satu
cukup"), `route` (layar yang memuat angkanya lengkap), `sizes` + `size`.

**Metadata di registry, kode di berkas widget.** Laci "Atur dasbor" harus menawarkan seluruh katalog
termasuk yang tidak dipakai; bila judul dan izinnya hidup di dalam berkas widget-nya, membuka laci
berarti mengunduh 19 modul yang belasan di antaranya tidak akan digambar. Penggambarnya diimpor
**dinamis** hanya bila widget-nya ada di susunan orangnya.

**`perm` sengaja string, bukan predikat.** `Modules\Core\Support\SpaWidgets` membacanya dari
registry.js dengan regex yang sama seperti `SpaNav` membaca NAV, dan `DashboardDefaultsTest`
memakainya untuk membuktikan — terhadap `RoleSeeder::intended()` yang asli — bahwa **setiap peran
demo mendapat sedikitnya satu widget**. Itulah metrik Fase 1 "0 peran tanpa ubin", dijadikan uji
alih-alih pengukuran yang basi pada sunting berikutnya.

**Aturan wajib per widget** (dipaku `DashboardTileFailureTest`, yang memindai folder — widget baru
ikut diperiksa tanpa satu baris pun ditambahkan di ujinya):

1. Berkas yang mengekspor `build(` **adalah** widget: ia wajib ada di katalog, dan katalog wajib
   punya berkasnya. Berkas tanpa entri = kode mati; entri tanpa berkas = kartu yang ditawarkan laci
   lalu gagal di-`import`.
2. Setiap widget **bercabang `failure(`** di KODE (komentar dibuang sebelum dipindai). Sumber yang
   gagal dan sumber yang kosong tidak boleh terbaca sama — pelajaran Temuan 79.
3. Tidak ada `.catch(() => …)` di mana pun di dasbor: catch yang tidak menerima error-nya tidak bisa
   memberi tahu ubinnya bahwa angkanya tidak diketahui.

**Perkakas bersama** `views/widgets/kit.js`: `safe()` / `safeList()` (fetch bertanda `loadFailure`),
`failure()`, `failedStat()` (`—`, tidak pernah Rp 0), `failedBody()`, `miniTable()`, `barRows()`.
Aturannya hidup satu kali; menyalinnya ke 19 berkas adalah cara paling pasti membuat 18 menyimpang.

**Ukuran**: `kecil` 1 kolom, `sedang` 2, `lebar` 3 (satu baris penuh) di kisi tiga kolom; dua kolom
di bawah 1180 px, satu kolom di bawah 760 px — titik potong yang sama dengan laci nav dan aturan
landing P1-C.

**Vendor dimuat malas.** SortableJS (seret-lepas di laci) diambil `js/vendorload.js` saat laci
DIBUKA, bukan oleh shell. Urutan tetap bisa diubah dengan tombol Naik/Turun tanpa satu byte vendor
pun; berkas vendor yang gagal dimuat mencatat sekali di konsol dan tidak mematikan apa pun.

## 18. Laporan Bebas — registri `ReportableResources` (P1-F)

Penyusun laporan atas **delapan** resource (keputusan pemilik ledger #4), satu layar
`#/laporan-bebas`, satu endpoint `POST core/reports/run` = **satu** kueri `DB::table` ber-whitelist.

**"Kolom = kolom layar daftar" tidak bisa harfiah, dan registri mengatakannya.** Hanya 31 dari 90
layar daftar yang seluruh kolomnya kolom tabel dasar; sisanya memuat jalur relasi (`vendor.name`)
atau medan yang dihitung kelas Resource (`outstanding`, `project_code`). Maka `columns` dikunci
dengan **kunci kolom layar, dalam urutan layar, setiap kunci hadir**, dan setiap kunci berakhir di
salah satu dari **tiga nasib** — tidak ada nasib keempat:

| nasib | bentuk | contoh |
|---|---|---|
| dipetakan | `select` = kolom tabel bernama sama | `amount` |
| digantikan | `select` = kolom lain + `lookup` | `customer.name` → `customer_id` |
| **ditolak** | tanpa `select`, dengan `why_not` | `outstanding` |

`why_not` adalah kalimat yang **dibaca orangnya di pemilih kolom**, di tempat ia mencari kolom itu.
Katalog yang diam-diam menghilangkan kolom "Sisa" membuat orang menjumlahkan "Total" dan menyangka
itu sisa tagihan. `ReportableResourcesTest` memaku kesetaraan kunci **dan urutannya** terhadap
`schema.js` di kedua arah, `select` terhadap skema hidup, `soft_deletes` terhadap ada-tidaknya
`deleted_at`, izin terhadap `PermissionSeeder`, dan `date_column` terhadap `meta.date_column`
endpoint daftarnya sendiri.

**Aturan mesin** (`Modules\Core\Services\ReportRunner`, dijaga `ReportRunnerSafetyTest`):

- **Tidak ada string klien yang menjadi teks SQL.** Identifier hanya dari registri, melewati
  `guardIdentifier()`; nilai selalu binding. Tidak ada `whereRaw`/`havingRaw`/`orderByRaw`/`fromRaw`/
  `DB::select` — hanya `selectRaw`/`groupByRaw` dengan string yang dibangun dari registri.
- **ONLY_FULL_GROUP_BY.** Menyala di MySQL, tidak di SQLite. Setiap ekspresi select bukan-agregat
  masuk `GROUP BY` **byte-identik**; `compile()` diekspos supaya ujinya menyapu setiap sumber ×
  dimensi × ember × agregat tanpa MySQL.
- **Ember tanggal `substr`.** `MONTH()`/`DATE_FORMAT` MySQL saja; `strftime` SQLite saja **dan**
  dipindai terlarang `MysqlPreflightCommand`. Ember **harian** pun memotong (`substr(col,1,10)`):
  kolom `date` terbaca `'2026-03-25 00:00:00'` di SQLite dan `'2026-03-25'` di MySQL.
- **SoftDeletes dengan tangan.** `DB::table` melewati scope-nya.
- **Pengurutan di PHP**, bukan `orderByRaw` — ≤ 200 kelompok, dan satu tempat lagi yang tidak
  menjadi teks SQL.

**Tiga keadaan sel, dan ketiganya berbeda** (syarat "sel kosong, bukan 0"):

| keadaan | JSON | layar | XLSX |
|---|---|---|---|
| tidak ada baris sumber | `cells[i] = null`, `counts[i] = 0` | `—` | sel kosong |
| ada baris, agregat NULL | `cells[i] = null`, `counts[i] > 0` | `—` | sel kosong |
| nol yang dijumlahkan | `cells[i] = 0.0` | `0` | `0` |

`array_key_exists`, tidak pernah `?? 0` dan tidak pernah `empty()`. Aturan sel XLSX punya **satu
pemilik**: `Modules\Core\Support\XlsxSheetWriter::putRow` (`$value !== null && $value !== ''`,
perbandingan KETAT — `empty()` menulis sel kosong untuk setiap nol yang sah).

**Setiap saringan memeriksa NILAInya, bukan hanya kuncinya.** Saringan ber-`kind: 'key'` menuntut
nomor baris; saringan ber-`kind: 'enum'` menuntut nilai yang benar-benar ada di enum-nya (dibaca
`SpaEnums`, berkas `enums.js` yang sama dengan layarnya) dan menolak dengan menyebut yang tersedia.
Tanpa lengan kedua, sebuah status yang sudah dicabut lolos ke `where status = 'x'`, laporannya
kembali kosong tanpa satu kata pun, dan definisi itu bisa DISIMPAN lalu dibagikan — saringan yang
diam-diam tidak cocok dengan apa pun adalah kebohongan yang paling sulit dilihat. `enums.js` yang
tidak terbaca menurunkan aturan ini menjadi "terima apa adanya" (degradasi SpaEnums), bukan
menolak semuanya.

**Saringan yang layar tidak punya kendalinya tetap PUNYA SUARA.** Laporan tersimpan boleh membawa
saringan ber-FK (pemilihnya ditunda ke v2) atau nilai enum yang sudah dicabut; layar menggambarnya
sebagai keping berlabel `labelFor()`/`enumLabel()` dan kartu hasil menuliskan `Disaring: …`.
Angka yang merupakan himpunan bagian karena saringan tak terlihat adalah kebohongan sejenis dengan
sel 0 yang seharusnya kosong.

**Baris SENDIRI selalu terlihat pemiliknya**, juga setelah izin sumbernya dicabut — ditandai
`readable: false`, dengan Buka/XLSX/Salin tertutup dan sebabnya tertulis. Kalau tidak, tidak ada
satu pun cara membuangnya: barisnya hilang dari daftar sementara DELETE atas id-nya tetap berhasil.

**Plafon DIUMUMKAN, dan ia PENOLAKAN bukan pemotongan.** 5.000 baris rincian / 200 kelompok, di
`meta.limits` (aturan yang sama dengan `meta.sortable` dan `UserPreferences::describe()`); SPA
membacanya, tidak menghafalnya. Melewatinya dijawab 422 — SUM atas 200 dari 340 kelompok adalah
angka salah yang berpakaian angka benar.

**Berbagi per peran menyimpan NAMA.** Ini rujukan peran pertama di seluruh basis data ini: id tidak
bisa membawa FK (peran milik Iam, tabel milik Core — §3), nama adalah yang sudah diseberangkan ke
klien dan yang dibaca `hasRole()` yang tidak pernah melempar. Penulisan divalidasi terhadap peran
yang hidup (berbagi tidak pernah **lahir** basi); peran yang kemudian diganti nama **ditandai** di
daftar, bukan diam-diam berhenti berbagi. Dua aturan yang tidak boleh dilanggar: **berbagi tidak
pernah memberi akses baru** (laporan tetap disaring izin sumbernya), dan **hanya pemilik yang
mengubah**, tanpa jalan pintas admin, ditolak **422 dari service** dengan kalimat yang menyebut
laporannya, pemiliknya, dan jalan keluarnya — bentuk `PettyCashVoucherService::assertCustodian`.

**Blok migrasi.** `core_saved_reports` mengambil **000197**. Ledger #5 menyarankan "Core 001400–",
tetapi §2 sudah memberikan 001400–001499 kepada Quality dan Quality memakainya sejak 001400 —
saran itu **tidak diikuti**. Core menyisakan **000198 dan 000199**; blok lanjutan Core perlu
diputuskan sebelum tabel Core berikutnya.

## 19. Papan kanban — blok `board:` (P1-G)

Tampilan KEDUA atas daftar yang sudah ada, di rute `#/b/<resource>`. Gerbangnya persis gerbang
layar daftarnya (`def.viewPerm || `${def.module}.view``) — papan bukan data baru.

**SATU ATURAN MENENTUKAN SELURUHNYA: drop menjalankan aksi yang SUDAH ADA lewat `runAction()`,**
jalur yang sama persis dengan tombol di halaman dokumen. Bukan endpoint baru, bukan `PUT {id}` yang
menulis status, bukan salinan aturan transisi. Yang ikut secara gratis karena itu: catatan
persetujuan inline, maker-checker, `confirmResubmit` bertingkat, dialog alasan wajib pada Tolak,
toast berbahasa Indonesia yang menyebut kode dokumennya, dan tawaran "dokumen berikutnya" setelah
menyetujui. Papan yang menulis statusnya sendiri kehilangan keenamnya sekaligus, diam-diam.

```js
board: {
  enum: 'documentStatus',                    // enum status; kolom dilabeli darinya
  lanes: ['draft', 'submitted', 'approved', 'rejected'],
  moves: { submitted: 'submit', approved: 'approve', rejected: 'reject' },
  why: '…',                                  // kenapa resource INI yang berpapan
}
```

`lanes` adalah nilai status yang **benar-benar tercapai** — `documentStatus` punya enam nilai dan
hampir tidak ada dokumen yang mencapai semuanya; kolom yang tidak pernah terisi hanya mengambil
ruang. `moves` memetakan **kolom tujuan → kunci aksi**; kolom tanpa entri tidak menerima kartu.

**DUA PENOLAKAN YANG BERBEDA, dan keduanya wajib ada:**

1. **Yang bisa diketahui sebelum mencoba** — izin dan `when`. Predikatnya diambil UTUH dari
   `actionButtons()`, dalam urutan yang sama (`session.can(action.perm)` lalu
   `!action.when || action.when(row)`): papan yang memakai predikat kedua menawarkan perpindahan
   yang tombolnya sendiri sembunyikan. Kartu kembali, dan kalimatnya menyebut dokumennya, kolom
   tujuannya DAN aksi yang kurang — *"PR PR/2026/III/0002 tidak bisa dipindah ke Disetujui: aksi
   Setujui tidak tersedia untuk Anda."*
2. **Yang hanya bisa diketahui dengan mencoba** — maker-checker, tangga persetujuan, ambang
   direktur, prasyarat BAST. Tidak satu pun ada di muatan daftar (`approvals` di-load hanya pada
   detail; tidak ada medan `can_approve` di mana pun). Papan **tidak boleh menebaknya**: ia mencoba,
   `runAction` menampilkan kalimat servernya, dan kartunya kembali.

**Mengembalikan kartu adalah pekerjaan tangan.** SortableJS tidak punya API batal — `onEnd` menyala
SETELAH DOM dipindahkan, dan tidak satu pun metode instansnya mengembalikannya. Satu-satunya jalan
adalah idiom pustakanya sendiri: simpan tetangga di kolom asal **sebelum** apa pun yang bisa gagal,
lalu `insertBefore` / `appendChild`. Karena itu pula `sort: false` — papan ini tentang KOLOM, dan
membiarkan pengurutan di dalam kolom menambah satu bentuk pembatalan lagi yang indeksnya bergeser.

**`runAction` selalu resolve `undefined` dan tidak pernah melempar**: batal, 422 dan berhasil tidak
bisa dibedakan dari nilai kembaliannya. Yang menandakan berhasil hanyalah `onDone` yang menyala —
papan memasang bendera di dalamnya dan mengembalikan kartu bila bendera itu tidak menyala. Karena
`onDone` **dilewati** untuk aksi ber-`navigateTo`/`navigateToResult`, aksi seperti itu tidak boleh
menjadi `moves` (dipaku `BoardWiringTest`).

**Yang TIDAK boleh berpapan** (dipaku uji): resource yang salah satu aksinya memposting ke buku
besar atau ke stok. Aturannya tentang **akibat**, bukan tentang kunci — lima resource
menyembunyikan posting di balik kunci bernama `approve`/`acknowledge`
(`inventory/stock-adjustments`, `finance/ar-invoices`, `finance/ap-bills`, `hr/payroll-runs`,
`servicedesk/field-reports`). Aksi ber-`opens` juga tidak: ia tidak punya `path` dan tidak pernah
POST.

**Menambahkan papan** = satu blok `board:` di entri RESOURCES + satu baris NAV `b/<key>`.
`BoardWiringTest` memeriksa sisanya: kolom adalah nilai enum sungguhan, setiap `moves` menunjuk
kolom papan itu DAN kunci aksi yang ada, aksinya punya `path` dan tidak berpindah halaman, dan
tidak satu pun resource yang memposting.

## 20. Tab "Jadwal" — gantt baca-saja atas WBS (P1-H)

`public/app/js/views/jadwal.js` menggambar gantt proyek dengan `charts.js ganttChart()` (§11).
Ia **tidak menambah satu endpoint pun**: seluruh layarnya berdiri di atas dua yang sudah ada,
`projects/{id}/wbs-tasks` (pohon hidup) dan `projects/baselines` → `projects/baselines/{id}`
(rencana beku). Yang menjadi aturan — bukan detail implementasi — adalah dari mana setiap medan
baris itu datang.

**BASELINE DICOCOKKAN LEWAT `wbs_code`, TIDAK PERNAH LEWAT `wbs_task_id`.**
`prj_baseline_tasks.wbs_task_id` sengaja tanpa FK: baris beku harus bertahan meski tugas hidupnya
dihapus, karena lingkup yang dihapus sesudah rencana disepakati adalah hal paling penting yang bisa
dilaporkan sebuah laporan deviasi. Akibatnya id itu **menggantung**, dan bukan sesekali:
`ProjectService::generateWbsFromBoq` MENGHAPUS seluruh WBS lalu membuatnya ulang dengan id baru
setiap kali "Buat WBS dari BOQ" ditekan. Diukur `JadwalGanttTest` terhadap layanan yang sungguhan —
sesudah **satu** kali tekan, **0 dari 11** id beku menemukan tugas hidup sementara **11 dari 11**
`wbs_code` cocok; berkas demo yang dikirim repositori ini punya bentuk yang sama (id beku 12–22, id
hidup 34–44). Jadi mencocokkan lewat id bukan "kurang aman" — ia menghasilkan gantt tanpa satu pun
bar pembanding, dan kaki kartu yang mengumumkan "0 dari 11 tugas cocok" untuk baseline yang
disetujui dan lengkap.

Satu-satunya tempat lain yang masih memakai id adalah `EvmService::physicalProgress`, yang mencoba
id **dulu** lalu jatuh ke kode. Perbedaan itu disengaja (kode WBS yang diganti nama adalah tugas
yang sama, dan di situ id-lah yang benar) dan pada data hari ini keduanya memberi hasil yang sama
justru karena setiap id meleset. Sejak P1-H ketidaksepakatan antara keduanya **disebut**: bila id
beku menunjuk tugas hidup berkode lain, laporan EVM memuat peringatan yang menyebut kedua kodenya.
Tidak ada FK yang bisa mencegah keadaan itu, jadi yang dijamin adalah ia tidak pernah diam.

**Kode WBS tidak dijamin unik.** Indeks `(baseline_id, wbs_code)` bukan `unique` dan validasinya
hanya `required|string|max:20`. Peta di jadwal.js diam-diam menyimpan yang terakhir, jadi
tabrakannya DIHITUNG dan disebutkan di bawah gantt. Angka yang salah yang mengaku dirinya salah
lebih baik daripada angka yang salah dan diam.

**Urutan baris diambil dari SARANGNYA, bukan dari muatan baseline.** Muatan beku diurutkan
`(sort_order, wbs_code)`, yang menyelang-nyeling induk dan anak dari cabang berbeda (A, A.1, B.1,
C.1, A.2, B, …); menggambar dalam urutan itu menghasilkan gantt acak. `prj_wbs_tasks` tidak punya
kolom level/depth, jadi kedalamannya dihitung dari sarang sisi hidup.

**Endpoint pohon mengirim SELURUH pohon.** `WbsTaskController::index()` merakit sarangnya dari satu
kueri, tanpa batas kedalaman, dan setiap baris membawa `children` (kosong bila daun). Batas tiga
tingkat yang lama (`with('children.children')`) benar untuk WBS dari BOQ dan salah untuk pintu kedua
ke tabel yang sama: `MppXmlImportService` menerima OutlineLevel sedalam apa pun, jadi jadwal empat
tingkat kehilangan seluruh tingkat keempatnya — tidak digambar gantt, tidak muncul di tabel WBS, dan
induk tingkat tiganya tampil sebagai daun bertombol "Perbarui" yang pasti ditolak server. **Sebuah
jadwal yang diam-diam kehilangan paket pekerjaan terlihat persis seperti jadwal yang benar.**
Sejak verifikasi 7 Sep 2026 janji itu juga berlaku untuk **siklus `parent_id`** (B.3 ↔ B.3.1 —
FK mengizinkannya, kedua baris ada): dulu anggota siklus tidak pernah menjadi akar dan tidak
pernah terjangkau dari akar mana pun, jadi 13 baris tersimpan berangkat sebagai 10. Perakitannya
kini menelusuri dari akar dengan himpunan "sudah dikirim" lalu MENGANGKAT sisanya menjadi akar
(sisi belakang siklus dipotong, jadi setiap baris muncul tepat sekali). Letak baris itu di pohon
memang berubah, dan karena itu ia DISEBUT: `meta.parent_cycles` memuat kodenya dan jadwal.js
mencetaknya di bawah gantt.

**Garis "Hari ini" datang dari SERVER.** `GET {project}/wbs-tasks` mengirim `meta.as_of` +
`meta.as_of_source: 'server'` — kanal yang sama dengan `DeadlineController` dan `EvmService` — dan
jadwal.js mengoperkannya ke `ganttChart({ today })`. Tanpa itu charts.js jatuh ke `localToday()`,
yaitu jam PERAMBAN: diukur 7 Sep 2026 pada berkas dan jam server yang sama, garisnya berpindah
mengikuti timezone pembacanya (Asia/Jakarta x=484,67 vs America/Los_Angeles x=483,27). Aturan
tertulis aplikasi ini berlawanan dengan itu — EvmService: *"an EVM report keyed off a skewed PC
clock manufactures schedule variance out of nothing"* — dan garis "Hari ini" pada gantt adalah
pembacaan keterlambatan yang persis sama, hanya dengan mata. Dua peramban dengan timezone
berjarak 25 jam (Pacific/Niue dan Pacific/Kiritimati, tidak pernah setanggal) mengukurnya di
S26_gantt_jam_server; harness juga menghitung harapannya dari `meta.as_of`, bukan dari jam
mesinnya sendiri — aplikasi berjalan di Asia/Jakarta sementara host harness UTC, jadi jam mesin
berselisih sehari dengan server selama tujuh jam setiap hari.

**Satuan.** `progress_pct` berjalan di kawat sebagai 0..100 dan sebagai *string* ('60.0000');
`ganttChart` menerima 0..1. Pembagian 100 di jadwal.js bukan kosmetik — tanpa itu setiap bar terbaca
100 %. Nilai di luar rentang tidak dijepit di jalur baca: gantt menjepit barnya sendiri lalu menulis
"(di luar 0–100 %)" di `<title>`, yang hanya mungkin bila angka aslinya sampai ke peramban.

**Ketergantungan antar tugas tidak digambar**, dan legenda di kaki kartu mengatakannya: kolomnya
belum ada di basis data dan impor MPP-XML mengabaikan `PredecessorLink` (ROADMAP menunda ini ke
Fase 2). Garis yang digambar dari kolom yang tidak ada adalah jadwal karangan.

**Cetak.** Lembar gantt memakai `@page` **bernama** (`@page gantt { size: A4 landscape }` +
`.gantt-sheet { page: gantt }`), tidak pernah `@page` telanjang: satu stylesheet melayani 16 layar
yang memanggil `window.print()` dan semuanya tabel potret. Bilah zoom disembunyikan di kertas, judul
kartu dan catatan sumber di dalam svg tidak — merekalah yang memberi tahu pembaca kertasnya apa yang
sedang ia lihat. Diukur S26: PDF Chromium memberi halaman 792×612 pt (lanskap) untuk lembar itu dan
612×792 pt (potret) untuk sisanya — yang dibuktikan angka itu adalah ORIENTASINYA, bukan ukuran
kertasnya: 792×612 pt adalah US Letter lanskap, dan yang memilih kertas adalah dialog cetak
(bawaan `page.pdf()` di harness), bukan `size: A4` di stylesheet.

**Jadwal yang lebih tinggi daripada satu kertas DIPOTONG SENDIRI menjadi beberapa svg.** Sebuah
`<svg>` tidak bisa dipaginasi: diukur 7 Sep 2026 pada jadwal 66 baris, satu gambar besar memberi
halaman kosong, satu baris terbelah di batas halaman, dan tiga dari empat halaman gambar tanpa
satu pun sumbu tanggal. `jadwal.js` karena itu menggambar satu svg per 16 baris untuk kertas —
masing-masing dengan pita bulan, tick, garis "Hari ini" dan legendanya sendiri, dan semuanya
memakai `from`/`to` yang SAMA supaya skalanya sebanding — lalu menyembunyikan gambar layarnya
(`.gantt-sheet.is-paginated .chart-scroll`). Kaki setiap halaman menyebut "halaman n dari m,
baris a–b dari N".

**Gerbang izin.** Ketiga GET layar proyek (`{project}/wbs-tasks`, `/s-curve`, `/dashboard`) menuntut
`permission:prj.view`, sama dengan `{project}/evm` dan `projects/baselines`. Sampai P1-H gerbang itu
hanya ada di app.js — separuh tab Jadwal dijaga server dan separuhnya tidak.

**Pohon WBS punya SATU pintu**, `{project}/wbs-tasks`, dan itu bukan kerapian melainkan syarat
gerbang di atas. `GET projects/{project}` dulu membawa salinan kedua pohon itu (`wbs_tasks` di
`ProjectResource`) sementara rutenya sendiri berjalan di bawah `auth:sanctum` saja: diukur 7 Sep
2026, peran tanpa satu pun izin `prj.*` (finance, teknisi, hr, procurement) mendapat 403 dari
pintu yang bergerbang dan 200 di sana — lengkap dengan kode, uraian, bobot, tanggal rencana dan
progres setiap paket. Salinan itu juga PENDEK (11 dari 13 baris pada pohon empat tingkat) dan
tanpa kunci `children`. Muatan proyek karena itu tidak lagi membawa pohonnya sama sekali. Aturan
umumnya: sebuah medan yang digerbangi di satu rute tidak boleh menumpang di rute lain yang tidak
digerbangi — gerbang yang bisa diputari bukan gerbang.
