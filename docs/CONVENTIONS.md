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

**Blok lanjutan** (F-2, 7 Sep 2026; Inventory ditambahkan F-6, 8 Sep 2026). Blok pertama sebuah
modul bisa habis, dan tiga di antaranya sudah: Projects memakai 000799 pada 9 Agustus 2026,
Finance memakai 001199 pada 25 Juli 2026, dan Inventory memakai kesepuluh slot puluhannya
(000400…000490, ditambah luapan 000491 dan 000495–000499) pada 30 Agustus 2026.
Pemilik menyetujui rentang lanjutan Finance dan Projects (ROADMAP-HASHMICRO §5 baris 5), dan
**tabel di bawah ini — bukan prosa mana pun — adalah sumber kebenaran rentang blok:**

| Module      | Blok pertama  | Blok lanjutan | Status |
|-------------|---------------|---------------|--------|
| Finance     | 001100–001199 | **001500–001599** | DIPAKAI — `2026_09_07_001500_create_fin_overhead_budget_tables.php` (F-2) dan `2026_09_07_001501_add_cancellation_to_fin_overhead_budgets_table.php` (putaran verifikasi F-2) |
| Projects    | 000700–000799 | **001600–001699** | DIDAFTARKAN, belum dipakai — F-2 tidak butuh migrasi Projects |
| Inventory   | 000400–000499 | **001700–001799** | DIPAKAI — `2026_09_08_001700_create_inv_reorder_rules_table.php` (F-6) |
| Core        | 000100–000199 | **001800–001899** | DIPAKAI — `2026_09_09_001800_add_valid_until_to_core_attachments_table.php` (F-8) |

Rentang Inventory 001700–001799 **belum ada di ledger pemilik** (ROADMAP-HASHMICRO §5 baris 5
menyebut Core, Finance dan Projects saja). Ia ditetapkan di sini karena aturan di bawah menuntut
penetapannya pada commit pemakaian pertama dan F-6 membutuhkannya; 001700–001799 dipilih karena
ia rentang seratusan bebas pertama sesudah Projects (nomor ≥ 001400 yang terpakai hanya
001400/001410/001420/001430/001440/001450/001500/001501). Baris ini adalah usulan yang menunggu
pengesahan pemilik ke dalam ledger, bukan pengganti ledgernya.

Core (000100–000199) habis pada 7 September 2026 (F-1 memakai 000198 dan 000199), dan
**F-8 adalah paket pertama yang butuh migrasi Core sesudah itu** — jadi barisnya ditetapkan
di tabel di atas pada commit pemakaian pertamanya, 9 September 2026, sesuai aturan di bawah.
Rentang yang dipakai: **001800–001899**. **JANGAN memakai 001400–001499 untuk Core**, meski
ledger pemilik (ROADMAP-HASHMICRO §5 baris 5) menuliskan "Core 001400–?": rentang itu adalah
blok PERTAMA Quality pada tabel di atas, dan ia sudah berisi enam migrasi (001400/001410/
001420/001430/001440/001450). 001800–001899 dipilih karena ia rentang seratusan bebas pertama
sesudah Inventory (nomor ≥ 001400 yang terpakai pada 9 Sep 2026 hanya 001400/001410/001420/
001430/001440/001450/001500/001501/001700). Seperti baris Inventory, baris Core ini adalah
usulan yang menunggu pengesahan pemilik ke dalam ledger — bukan pengganti ledgernya.
Aturan itu berlaku untuk setiap blok lanjutan: didaftarkan **di tabel ini** pada
commit yang pertama kali memakainya, tidak pernah lebih dulu dan tidak pernah belakangan.

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
- **Setiap aturan "hanya boleh ada SATU" wajib punya jalan keluarnya sendiri** (verifikasi F-2,
  dua putaran). Sebuah gerbang unik yang menolak baris kedua sementara baris pertama tidak bisa
  ditarik kembali mengunci datanya selamanya — dan kalimat penolakannya menyuruh operator menekan
  tombol yang tidak ada. Dua yang ada hari ini: `OverheadBudgetService::cancel()` (satu OVB
  disetujui per tahun buku) dan `RapService::supersede()` (satu RAP yang mengatur per proyek).
  Bentuknya sama: alasan WAJIB, jejak di `core_approvals`, tidak satu byte pun isi dokumennya
  disentuh, dan jalan keluarnya DISEBUT di dalam kalimat penolakan yang menutup pintunya.
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
pengguna. Satu pemindaian TERSISA dan disengaja: entri `inv`. Sejak F-6 (8 Sep 2026) kuerinya bukan lagi
satu perbandingan `b.qty < i.min_stock` antar dua tabel melainkan DUA LENGAN yang saling
meniadakan lewat `r.id`, di atas `LEFT JOIN inv_reorder_rules` — ambangnya `r.reorder_point`
bila ada aturan AKTIF untuk pasangan gudang × item, dan `i.min_stock` bila tidak. `EXPLAIN`-nya
dijalankan ulang pada kedua driver (putaran perbaikan F-6, 8 Sep 2026):

```
-- SQLite (EXPLAIN QUERY PLAN, salinan basis data demo yang sudah dimigrasi)
SCAN b
SEARCH i USING INTEGER PRIMARY KEY (rowid=?)
SEARCH w USING INTEGER PRIMARY KEY (rowid=?)
SEARCH r USING INDEX inv_reorder_rules_warehouse_id_item_id_unique (warehouse_id=? AND item_id=?) LEFT-JOIN

-- MySQL 8 (erp_dryrun)
b  type=ALL     key=NULL                                            (pemindaian yang disengaja)
i  type=eq_ref  key=PRIMARY
w  type=eq_ref  key=PRIMARY
r  type=eq_ref  key=inv_reorder_rules_warehouse_id_item_id_unique   ref=b.warehouse_id, b.item_id
```

Join ke tabel aturan TIDAK menambah pemindaian: UNIQUE (warehouse_id, item_id) melayaninya
sebagai `eq_ref` di MySQL dan sebagai `SEARCH … USING INDEX` di SQLite. Yang tersisa tetap
`SCAN b` — tidak ada indeks yang bisa melayani perbandingan antar KOLOM (`b.qty <
r.reorder_point`), dan bila `inv_stock_balances` tumbuh melewati ~100 rb baris angka itu perlu
tabel ringkasan, bukan indeks.

Sejak putaran ketiga F-6 entri ini menghitung **dua kueri yang dijumlahkan** (§31): yang di atas,
dan pasangan yang aturannya hidup tetapi belum punya satu baris saldo pun. `EXPLAIN` yang kedua,
dijalankan pada kedua driver (9 Sep 2026):

```
-- SQLite (EXPLAIN QUERY PLAN, salinan basis data demo yang sudah dimigrasi)
SCAN r
SEARCH i USING INTEGER PRIMARY KEY (rowid=?)
SEARCH w USING INTEGER PRIMARY KEY (rowid=?)
SEARCH b USING COVERING INDEX inv_stock_balances_warehouse_id_item_id_unique (warehouse_id=? AND item_id=?) LEFT-JOIN

-- MySQL 8 (erp_dryrun)
r  type=ALL     key=NULL                                              Using where
i  type=eq_ref  key=PRIMARY                                           ref=r.item_id
w  type=eq_ref  key=PRIMARY                                           ref=r.warehouse_id
b  type=eq_ref  key=inv_stock_balances_warehouse_id_item_id_unique    ref=r.warehouse_id, r.item_id ; Not exists; Using index
```

`SCAN r` adalah pemindaian tabel ATURAN — sebesar jumlah aturan yang benar-benar ditetapkan orang,
bukan sebesar katalog atau sebesar `inv_stock_balances` — dan pencarian saldonya dilayani indeks
unik pasangan yang sama (`Not exists` di MySQL: ia berhenti pada baris saldo pertama yang cocok).
Jadi tidak ada pemindaian tabel penuh KEDUA.

Entri baru, ATAU entri lama yang kuerinya berubah bentuk: jalankan `EXPLAIN`-nya dan tulis
hasilnya di sini atau tambahkan indeksnya.

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
mengikuti timezone pembacanya (Asia/Jakarta x=484,67 vs America/Los_Angeles x=483,27; diukur
dengan kolom label 180, sebelum kolomnya dilebarkan). Aturan
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

## 21. PWA — manifest & service worker (P1-I)

Dua berkas statis di `public/app/`, dilayani tanpa satu baris pun konfigurasi server baru:
`manifest.webmanifest` dan `sw.js`. nginx sudah melayani `/app/` dengan
`try_files $uri $uri/index.html =404` dan `Cache-Control: no-cache`, dan `deploy/sync-erp1.sh`
sudah menyalin seluruh `public/`.

**Lingkup worker adalah `/app/`, dan itulah seluruh aturannya.** Sebuah service worker hanya boleh
menguasai path di bawah folder skripnya, jadi `sw.js` yang duduk di `/app/sw.js` tidak bisa
menyentuh `/api/`, `/storage/`, atau apa pun di luar cangkang — bahkan bila seseorang menuliskan
kodenya. Itu bukan kebetulan yang beruntung, itu alasan berkasnya diletakkan di sana.

**ATURAN "TIDAK PERNAH DI-CACHE".** Worker MENJAWAB sebuah permintaan hanya bila SEMUA benar:
(1) metodenya `GET`; (2) asalnya sama dengan asal worker; (3) path-nya diawali lingkup worker
(`/app/`); (4) tanpa header `Authorization`; (5) bukan `/app/sw.js` sendiri. Yang lain lewat
**tanpa `respondWith()` sama sekali** — peramban mengambilnya seolah tidak ada worker. Yang boleh
MASUK cache lebih sempit lagi: hanya jawaban `200` bertipe `basic`.

Bentuknya **daftar izin, bukan daftar larangan**, dan itu keputusan sadar. Daftar larangan
(`if (url.pathname.startsWith('/api/')) return;`) membusuk pada endpoint berikutnya yang lupa
didaftarkan, dan kegagalannya diam: tablet lapangan yang dipakai bergantian akan menyajikan daftar
dokumen milik orang sebelumnya, tanpa satu pun pesan galat, sampai ada yang menyadarinya. Satu
awalan tidak bisa membusuk begitu. `tests/Feature/Core/PwaServiceWorkerTest` memaku kelima syarat
itu, memaku bahwa hanya ada SATU `respondWith()` dan SATU `cache.put()`, dan memaku bahwa kode
(tanpa komentar, tanpa daftar `SHELL`) tidak menyebut `/api`, `storage`, `attachment`, `lampiran`,
`download` atau `unduh` sama sekali — **munculnya daftar larangan di sana adalah kegagalan uji.**

Uji itu memaku **bentuk, bukan ejaan**: badan `shellRequest()` dan `storable()` dibandingkan UTUH,
tulisan cache dihitung sebagai pola `\w+.put(`/`\w+.add(` (bukan nama variabel `cache`), dan DAFTAR
pendengar worker dipaku persis empat (`install`, `activate`, `fetch`, `message`). Alasannya terukur:
versi pertama yang menghitung potongan teks meloloskan empat mutasi yang benar-benar membocorkan
cache — antara lain pendengar `fetch` KEDUA yang menulis lewat `store.put()` tanpa satu pun
`respondWith()`, yang di peramban menyajikan `/api/core/dashboard/summary` kepada orang berikutnya
di perangkat yang sama, sesudah Keluar, tanpa token. **Menambah pendengar berarti menambah ujinya.**

**Strateginya jaringan-dulu.** Cache dibaca HANYA ketika `fetch()` melempar. Jawaban HTTP yang sah
tetapi tidak menyenangkan (404 sesudah rilis membuang berkas, 401 dari gerbang HTTP) diteruskan apa
adanya, tidak ditutupi salinan lama. `fetch()` juga **dimulai sebelum cache dibuka** — cache hanya
dibuka ketika ada yang perlu disimpan (di dalam `waitUntil`) atau ketika fetch melempar. Urutan
sebaliknya membuat ke-76 permintaan cangkang menunggu satu `caches.open` masing-masing sebelum satu
byte pun diminta; terukur pada kunjungan kedua, 14 putaran per varian yang diselang-seling, cat
pertama median 268 ms lawan 192 ms. Uji memaku urutan itu.

**Cangkang setengah dibuang.** Bila satu saja entri `SHELL` gagal dipasang, seluruh cache dibuang
(`caches.delete(CACHE)`) — install-nya sendiri tetap berhasil, jadi tidak ada pembaruan yang beku.
Alasannya terukur dengan kuota origin 1,2 MB (cangkang ~2,1 MB): 42 dari 102 entri masuk, daring
semuanya baik-baik saja, lalu muat ulang tanpa jaringan berhenti selamanya di pemutar boot dengan
body kosong. **Cangkang setengah lebih buruk daripada tidak ada cangkang.** Jaring keduanya ada di
`index.html`: pengawas boot sebaris — satu-satunya kode di sana yang tidak butuh berkas lain —
mengganti pemutar dengan kalimat dan tombol "Muat ulang" bila sebuah `<script>` gagal atau boot
belum selesai dalam 10 detik.

**`SHELL` adalah daftar dua arah.** Ia memuat `'./'` + setiap berkas `html/css/js/svg/webmanifest`
di bawah `public/app` (folder `icons/` tidak masuk — itu dibaca sistem operasi, bukan halaman; dan
`sw.js` tidak pernah men-cache dirinya sendiri). Uji menolak baris yang berkasnya hilang **dan**
berkas yang tidak terdaftar. Jadi: **setiap layar baru menambah satu baris di `SHELL`** — kalau
tidak, aplikasi tetap jalan daring dan setengah mati saat luring, yang tidak akan terlihat siapa pun
sampai seseorang membuka ponselnya di lokasi tanpa sinyal.

**Menaikkan versi cache.** `const SHELL_VERSION = '1';` di kepala `sw.js`. Naikkan pada setiap rilis
yang mengubah berkas cangkang. Peramban membandingkan **byte** `sw.js`, jadi mengubah angka itulah
satu-satunya hal yang membuat worker baru dipasang — dan karena itu satu-satunya hal yang
memunculkan toast "Versi baru siap — Muat ulang" di tab yang sudah terbuka berhari-hari. Rilis yang
lupa menaikkannya tidak menyesatkan siapa pun (jaringan-dulu tetap menyajikan kode terbaru kepada
siapa saja yang memuat ulang), ia hanya tidak mengumumkan dirinya. `activate` membuang setiap cache
`nusantara-shell-*` yang bukan versi berjalan.

**Warna.** `manifest.webmanifest` hanya boleh punya SATU `theme_color` dan SATU `background_color`,
dan keduanya dipakai layar splash sebelum dokumen ada — jadi keduanya nilai tema **terang**
(`--primary` `#1a56db` dan `--bg` `#f4f6f8`). Yang bisa bermedia adalah `<meta name="theme-color">`,
dan index.html memasangnya **berpasangan** dengan nilai `--surface` kedua tema (`#ffffff` /
`#151a21`) — `--surface`, bukan `--bg`, karena bilah peramban duduk persis di atas `.header`.
`PwaManifestTest` membaca app.css dan menuntut keempat nilai itu sama persis dengan tokennya.

**Ikon dibangkitkan, tidak digambar ulang.** `docs/bukti-uji/buat-ikon-pwa.py` memotret
`public/app/favicon.svg` dengan Chromium milik harness (tidak ada rasterizer di host ini dan paket
ini tidak menambah dependensi). Ukuran yang DIUMUMKAN manifest dipaku terhadap IHDR berkasnya:
manifest yang menulis `512x512` di atas berkas 192 px tidak pernah terlihat sampai ikonnya buram di
layar utama orang lain.

**Pita luring.** `ui.offlineRibbon()` membaca DUA sumber: `navigator.onLine` **dan** peristiwa
`erp:network` yang diumumkan `api.js` ketika sebuah transport gagal (fetch melempar, XHR status 0).
`navigator.onLine` sendiri tidak cukup — Wi-Fi lokasi di balik portal dan 4G satu bar sama-sama
melaporkan `true`. HTTP 500 **bukan** luring: server menjawab. Pita padam pada peristiwa `online`
atau pada permintaan berikutnya yang berhasil — paling lambat polling notifikasi 90 detik. Ia
sengaja tidak menyelidik jaringan sendiri: lalu lintas latar dari ponsel lapangan berkuota adalah
biaya nyata untuk informasi yang akan datang sendiri.

Dua sumber berarti dua ingatan, dan **keduanya harus dilupakan oleh peristiwa yang sama**: `api.js`
menyetel ulang `lastNetworkOk` pada `online`, persis seperti `ui.js` menyetel ulang
`networkTrouble`. Tanpa itu urutan luring → gagal → `online` meninggalkan `api.js` mengingat "sudah
diumumkan luring" sementara `ui.js` sudah melupakannya, sehingga setiap kegagalan berikutnya kena
dedupe dan pita tidak pernah menyala lagi — terukur: empat layar berturut yang seluruh
permintaannya gagal, `navigator.onLine` true, pita padam, nol peristiwa. Itu justru kasus yang pita
ini ada untuk melaporkannya.

Kalimat pita **milik pemanggil dan boleh berupa fungsi**, dibaca ulang setiap kali pita menyala:
layar Lapangan memilih antara "tekan Kirim ulang pada barisnya" (ada antrean) dan "foto yang Anda
ambil sekarang tersimpan di ponsel ini" (antrean kosong). Pita yang menyuruh menekan tombol yang
tidak ada di layar lebih buruk daripada pita yang diam.

**Toast yang bertahan (`timeout: 0`) harus dipegang.** Dua di paket ini: "Versi baru siap" dibuang
sebelum yang baru dibuat (kalau tidak, satu toast permanen menumpuk per rilis di tab yang tidak
pernah ditutup — terukur dua toast identik pada rilis kedua), dan "Mode luring" dibuang pada
peristiwa jaringan pertama yang berhasil, sekalian menyegarkan sesi cermin (`refreshMe`,
`prefs.load`, `refreshNav`). Penyegaran yang gagal MEMBIARKAN toast lama berdiri: saat itu
kalimatnya masih benar.

**Mencabut worker.** Worker adalah satu-satunya artefak paket ini yang menetap di setiap peramban
yang pernah membuka `/app/`, jadi "bagaimana mengambilnya kembali" adalah pertanyaan operasional
yang harus punya jawaban tertulis: **DEPLOYMENT § 2.3**. Ringkasnya — **menghapus `sw.js` tidak
mencopot apa pun** (terukur: sesudahnya worker tetap terdaftar, tetap menguasai halaman, cache tetap
utuh, dan `update()` melempar), yang bekerja adalah **mengganti isinya** dengan pencabut yang
menghapus cache dan memanggil `self.registration.unregister()`.

## 22. Matriks persetujuan (`ApprovalPolicy`, F-1)

Satu kebijakan per jenis dokumen di `ApprovableDocuments` — **ambang**, **mode**
(`single_director` / `extra_level`), **ambang tingkat ketiga** — disunting di
Pengaturan › Matriks Persetujuan dan disimpan lewat mekanisme setelan yang sudah
ada (`approvals.*` di `core_settings`). Bukan mekanisme kedua: baris PO dan SPK
menulis `approvals.purchase_order/subcontract.threshold_two_level`, **kunci yang
sudah dibaca `needs_director_approval`**, jadi tidak ada dua angka yang bisa
berbeda pendapat tentang ambang yang sama.

**Slug jenis dokumen = `Str::snake(class_basename($model))`** — dan itu bukan
kebetulan: kedua kunci yang sudah dikirim aplikasi ini persis bentuk itu. 28
jenis diperiksa, tidak ada dua yang bertabrakan.

**LIMA ATURAN YANG MENENTUKAN BENTUK LAYARNYA.**

1. **Bawaan = nilai HARI INI.** Sebuah paket yang menambahkan layar tidak boleh
   mengubah satu pun keputusan saat dipasang. Yang dikirim: PO Rp 100 juta, SPK
   Rp 200 juta, addendum mengikuti SPK, award Rp 100 juta / Rp 1 miliar
   berjenjang, dan **24 jenis lain tanpa ambang**.
2. **"Tanpa ambang" adalah `null` dan dirender sebagai ATURAN — tidak pernah
   `Rp 0`.** Ambang nol berarti setiap dokumen menuntut direktur, kebalikan
   persis keadaannya, dan satu-satunya angka yang bisa mengubah aturan uang
   tanpa seorang pun mengetiknya.
3. **Jenis TANPA kolom nilai tidak mendapat sel ambang sama sekali.** Tiga belas
   dari dua puluh delapan (izin kerja lapangan, izin lembur, izin masuk/keluar
   material, BAST, baseline proyek, opname progres owner, IPP, inspeksi mutu,
   pekerjaan tambah-kurang, permintaan pembelian, penyesuaian stok, BAST subkon,
   pengajuan cuti — diukur dari skema 7 Sep 2026). Sebuah izin kerja lapangan
   tidak berharga rupiah; menawarkan kotak isian di sana berarti menawarkan
   kendali yang tidak akan pernah berbunyi. Lihat
   `ApprovalPolicy::hasMeasurableAmount`.
4. **Tiga tabel yang membawa `needs_director_approval` sendiri** (`prc_purchase_orders`,
   `scm_subcontracts`, `scm_subcontract_addenda`) **tidak mendapat sel MODE**, dan
   Core tidak menggerbangi ulang dokumennya: modul merekalah yang menegakkan,
   lebih dulu, dengan kalimat penolakannya sendiri. Pertanyaan "tabel ini
   bergerbang sendiri?" dijawab dari **skema** (`Schema::hasColumn`), bukan dari
   daftar kelas yang harus diingat orang berikutnya.
5. **Baris yang MENGIKUTI jenis lain memantul ke kunci jenis itu**
   (`ApprovalPolicy::FOLLOWS`). Hari ini satu: addendum SPK menghitung
   `needs_director_approval`-nya terhadap ambang SPK, jadi kunci sendiri untuknya
   berarti sel yang bisa diedit dan tidak ada yang membacanya.

**KEBIJAKAN DISTEMPEL PADA BARIS `submitted`** (`core_approvals.policy`, json).
Yang dicap bukan hanya kebijakannya melainkan HASILNYA (`levels`, `director`)
beserta nilai dokumennya, jadi saat menyetujui tidak ada yang perlu dihitung
ulang. Sebelum ini, jenjang dibaca langsung dari config setiap kali seseorang
menekan Setujui — menaikkan ambang siang hari MENGURANGI tuntutan setiap dokumen
yang sedang menunggu, surut dan tanpa jejak. Stempelnya ditulis observer
`Approval::creating` (`ApprovalStamp`), **bukan** baris di dalam
`Traits\Approvable`: Payment, ProjectBaseline dan JournalService menulis baris
persetujuan tanpa trait itu, dan jalur yang terlewat adalah jalur yang dicari
sebuah penyelidikan. Dokumen yang diajukan sebelum kolomnya ada tidak punya
stempel dan jatuh ke resolusi langsung — maju-saja, tidak pernah ditulis surut.

**MENGUBAH `approvals.*` BUTUH DUA IZIN**: `core.update` **dan** salah satu
`*.approve-director`. Ditegakkan di `UpdateSettingsRequest` (422 per-parameter,
kalimat Indonesia) **dan lagi** di `SettingService::set()`, jadi memanggil
service langsung bukan jalan memutar. Penulisan tanpa pengguna masuk (seeder,
migrasi, konsol) tetap lewat: yang dijaga adalah ORANG. Setiap perubahan menulis
satu baris `core_audit_log` dengan nilai **efektif** dari → ke — termasuk reset
ke bawaan, yang tanpa ini hanya tercatat sebagai baris dihapus.

**IZIN DIREKTUR DITURUNKAN, BUKAN DIKETIK.** `PermissionSeeder::directorApprovals()`
mencetak satu `<awalan>.approve-director` per awalan yang memiliki setidaknya satu
dokumen di `ApprovableDocuments` — sepuluh (crm, est, prj, eng, qc, prc, inv, scm,
fin, hr). Empat awalan tanpa dokumen ber-approve (core, iam, ast, svc) tidak
mendapatkannya: sebuah izin yang tidak diperiksa apa pun terbaca sebagai kendali
yang ada.

**Setujui massal** (`approvals.batch_cap`, bawaan kosong = mati) adalah **loop di
klien** atas endpoint `POST <resource>/{id}/approve` milik tiap modul. Tidak ada
endpoint server massal, dan ketiadaannya dipaku uji yang memindai tabel rute:
satu endpoint massal akan melewati maker-checker, ambang direktur, jurnal, stok,
catatan dan pemberitahuan sekaligus.

**Blok migrasi Core HABIS.** 000198 dan 000199 adalah dua slot terakhir §2 (lihat
§18). Tabel Core berikutnya menuntut keputusan blok lanjutan dari pemilik —
ledger #5 belum menyebut satu pun rentang untuk Core.

## 23. Delegasi persetujuan "a.n." (`core_approval_delegations`, F-1)

Budi menyetujui atas nama Sari selama Sari cuti. Tanpa ini, cuti seorang direktur
berakhir di antrean yang berhenti (diukur 4 Sep 2026: PAY/2026/VIII/0002 menunggu
33 hari) atau kata sandi yang dipinjamkan, yang mengubah seluruh jejak persetujuan
aplikasi ini menjadi fiksi.

**Jendela TANGGAL, inklusif di kedua ujung** (Asia/Jakarta): "10 sampai 20
September" berarti apa yang dikatakannya. `ends_at` null = sampai dicabut, dan
layar mencetaknya sebagai delegasi tanpa akhir. **Lingkup** null = setiap hak
approve yang DIPEGANG pemberinya; `'prc'` = hanya `prc.approve` /
`prc.approve-director`. **Dicabut, bukan dihapus**: sebuah delegasi yang pernah
hidup adalah penjelasan bagi setiap baris "a.n." yang ditinggalkannya.

**`Gate::before` MENGEMBALIKAN `true` ATAU `null` — TIDAK PERNAH `false`.** Sebuah
`Gate::before` yang mengembalikan `false` MENOLAK ability itu di seluruh aplikasi,
mendahului setiap policy dan setiap middleware `permission:`. Polanya diperiksa
SEBELUM satu baris pun dibaca dan hanya cocok pada `<awalan>.approve` /
`.approve-director` dengan awalan yang benar-benar ada di registri.

**DAN HANYA DI PINTU KEPUTUSAN DOKUMEN** (`honouredOnThisRequest`, ditambahkan
pada putaran verifikasi F-1). Menyaring nama ability ternyata belum cukup: izin
`<awalan>.approve` itu sendiri menggerbangi 71 rute, dan **15 di antaranya bukan
approve/reject sebuah dokumen** — memposting jurnal manual, membuka kembali
periode fiskal, menerbitkan nomor e-Bupot, `advance-payout` dan
`retention-release` SPK, menutup proyek, verify/waive/reopen defect, close/reopen
insiden K3, mengaktifkan kontrak, dua keputusan submittal, verifikasi NCR.
Terukur: sebuah login `fin.view`+`fin.post` yang menutup periode 2026-06 ditolak
403 saat membukanya kembali, lalu 200 sesudah menerima delegasi cuti biasa —
mengalahkan aturan yang ditulis di komentar rutenya sendiri ("siapa pun yang bisa
memposting tidak boleh bisa membuka sendiri periode yang ingin diisinya").
Saringannya diturunkan dari BENTUK URI (`/{id}/approve`, `/{id}/reject`), jadi
rute ke-16 tertutup secara bawaan. **Tanpa rute (konsol, antrean, panggilan
langsung) delegasinya berlaku**, karena permukaan yang dijaga adalah permintaan
web. Antrean persetujuan memanggil `ApprovalDelegations::grants()` LANGSUNG,
bukan lewat `can()`: kotak masuk adalah bacaan, bukan pintu keputusan.

**TIDAK BERANTAI.** Pemberinya harus memegang izinnya SENDIRI —
`hasPermissionTo()`, bukan `can()`. Dua alasan, keduanya cukup sendirian:
`can()` masuk lagi ke `Gate::before` dan siklus A→B, B→A akan menggantung proses;
dan rantai tiga orang menyerahkan hak direktur kepada orang yang tidak pernah
dipilih siapa pun.

**DUA PENOLAKAN.** Delegat tidak boleh menyetujui yang diajukan DIRINYA
(maker-checker lama) **maupun** yang diajukan PEMBERI delegasinya. Yang kedua
dipasang DI DALAM `SegregationOfDuties::assertNotSubmitter`, tempat maker-checker
sudah berdiri, jadi keempat pemanggilnya (trait `Approvable`, `BaselineService`,
`PaymentService`, `JournalService`) mendapatkannya tanpa satu pun harus tahu
delegasi itu ada.

Yang kedua **hanya berlaku bila haknya memang dipinjam** (`refusesGiverSubmission`,
disempitkan pada putaran verifikasi F-1). Ia dikirim lebih luas — berlaku bahkan
bila delegatnya memegang hak itu sendiri — dengan alasan bahwa "hak yang mana yang
dipakainya tadi" tidak dapat ditentukan sesudah kejadian. Alasan itu tidak benar:
`actingForId()` menjawabnya secara deterministik dan sudah dipakai untuk mencap
"a.n." pada jejak. Harganya terukur: pemakaian paling biasa dari fitur ini —
pengaju menyerahkan haknya kepada penyetujunya sebelum cuti — membuat 2 dari 4
baris antrean penyetuju itu tidak dapat disetujui; dan karena siapa pun boleh
membuat baris yang menyebut dirinya sebagai pemberi, pengguna tanpa satu izin pun
dapat **melumpuhkan seorang direktur** yang memegang haknya sendiri. Ketiga
syaratnya sekarang harus benar sekaligus: pengajunya pemberi delegasi yang
tercakup lingkupnya, pemberinya benar-benar memegang hak itu, dan penyetujunya
TIDAK memegangnya sendiri (hak direktur ikut dihitung).

Ia mengikuti saklar `approvals.segregation_of_duties` yang sama: mematikan
maker-checker mematikan keduanya, karena aturan ini adalah maker-checker yang
dilihat lewat delegasi. **Antrean memakai predikat yang sama**
(`ApprovalQueue::pending`), supaya kotak masuk tidak menawarkan baris yang
dijamin ditolak — sebelum ini 2 dari 4 baris antrean seorang delegat pada dataset
demo dijamin gagal, lengkap dengan kotak centang "Setujui massal".

**Delegasi dicabut oleh pemberinya, PENERIMANYA, atau pemegang `iam.update`.**
Sebuah delegasi datang tanpa diminta, jadi ia harus bisa dikembalikan tanpa
meminta tolong. Kolom `revoked_by` mencatat siapa — pencabutan oleh orang
ketiga adalah persis kejadian yang ditanyakan sebuah penyelidikan, dan sebuah
stempel waktu tanpa nama tidak menjawabnya.

**`ApprovalDelegation` ADALAH MODEL YANG DIAUDIT** (`AuditedModels`), satu-satunya
pengecualian dari "dokumen sengaja absen" di daftar itu: barisnya bukan dokumen,
ia adalah izin dengan tanggal kedaluwarsa. F-1 mengaudit setiap perubahan
`approvals.*` dari→ke, jadi ATURAN uangnya tercatat; tanpa baris ini, pemberian
hak untuk menerapkan aturan itu tidak tercatat sama sekali. Judul barisnya
adalah atribut turunan `audit_label` ("Sari → Budi (est)"), supaya log tetap
terbaca sesudah kedua akunnya dihapus.

**"a.n." DICAP HANYA BILA DELEGASINYA YANG MEMBUATNYA MUNGKIN**
(`core_approvals.on_behalf_of_user_id`). Seseorang yang memegang izin approve-nya
sendiri menyetujui atas namanya sendiri, punya delegasi atau tidak; mencap "a.n."
pada persetujuan yang tidak membutuhkannya berarti menuliskan fiksi ke dalam
jejak.

**Dan ia ikut ke PEMBERITAHUAN yang sampai kepada pengaju** ("Budi a.n. Sari
menyetujui …"), ditambahkan pada putaran verifikasi F-1. Jejak dan layar detail
sudah benar sejak awal, tetapi pengaju tidak membuka layar detail untuk membaca
jejak — yang dibacanya adalah satu kalimat di kotak masuknya, dan kalimat itu
menyebut Budi saja. Namanya dibaca dari BARIS yang baru ditulis, bukan
ditanyakan ulang kepada `ApprovalDelegations`: pendengarnya berjalan sesudah
commit, dan sebuah fakta yang sudah tercatat tidak boleh dihitung ulang dengan
delegasi yang mungkin sudah dicabut semenit kemudian.

**Satu perender jejak, `Core\Http\Resources\ApprovalTrail`**, dipakai 25 resource.
Penutup yang sama disalin byte per byte di 25 berkas sebelum F-1; selama tidak ada
yang berubah itu tidak menyakiti siapa pun, tetapi sebuah fakta baru yang harus
muncul di 25 tempat akan muncul di 24.

**Memo delegasi adalah objek yang di-bind `scoped()`**, bukan statis — batas dan
alasan yang sama dengan `SettingService`: satu unit kerja membaca satu potret,
unit berikutnya membaca ulang. Memo statis akan membuat pekerja antrean memegang
delegasi yang sudah dicabut sampai ia direstart.

**Yang TIDAK bisa dicetak "a.n.": formulir rumah.** `FormPrintService` dan
`PrintableDocuments` sengaja meninggalkan setiap kolom tanda tangan TANPA NAMA —
"core_approvals tahu siapa menekan Setujui; itu bukan klaim yang sama dengan
'orang ini menandatangani dokumen'". Keputusan itu tidak diubah F-1, jadi "a.n."
muncul di jejak persetujuan, di pemberitahuan keputusan dan di layar detail —
bukan di kertas yang difile orang.

## 24. Registri ambang (`WatchedThresholds`, F-2)

`Modules\Core\Support\WatchedThresholds` adalah saudara `WatchedDeadlines`:
yang itu menjawab "tanggal apa yang lewat", yang ini "angka apa yang mendekati
atau melewati batasnya". Satu daftar deklaratif; batas berikutnya yang layak
diawasi ditambahkan sebagai **satu entri array**, bukan sebagai layar baru.

**ENAM KEADAAN, DAN TIGA DI ANTARANYA BUKAN ANGKA.** Inilah seluruh alasan
registri ini ada, dan aturan yang mengikat setiap entri baru:

| Keadaan | Artinya | Yang dicetak layar |
|---|---|---|
| `aman` | di bawah ambang peringatan | persentasenya |
| `mendekati` | di ambang peringatan atau di atasnya, masih di bawah batas | persentasenya, berwarna |
| `lampau` | di batas atau melewatinya — **tepat 100 % ada di sisi ini**, dan **batas Rp 0 yang sudah dibelanjakan ada di sini juga** | persentasenya, merah |
| `tanpa_anggaran` | batasnya DIKETAHUI dan besarnya nol, belum ada yang dibelanjakan | **aturannya** ("Tidak dianggarkan") |
| `tanpa_batas` | yang diukur ADA, batasnya tidak pernah disetel | **aturannya**, tidak pernah 0 % |
| `tidak_terukur` | yang diukurnya sendiri belum ada | **aturannya**, tidak pernah 0 % |

**KEADAAN DIBANDINGKAN PADA ANGKA YANG DICETAK, LAMPAU PADA RUPIAHNYA**
(verifikasi F-2). `mendekati` diadu dengan `displayPct()` — `pct()` yang
dibulatkan ke jumlah desimal yang benar-benar dicetak layar (satu) — karena
sebuah baris yang mencetak "90,0 %" lalu menyebut dirinya "Aman" di bawah judul
"Peringatan ≥ 90 %" adalah dua pernyataan yang bertentangan pada satu baris
(terukur: Rp 899.999.999 dari Rp 1.000.000.000). `lampau` diadu dengan
RUPIAHNYA (`$actual >= $limit`), bukan dengan persen yang dibulatkan: sebuah
baris "Melampaui" yang masih menyisakan satu rupiah yang DITERIMA gerbang
adalah perselisihan layar-vs-gerbang yang F-2 ada untuk menghapus.

`tidak_terukur` MENDAHULUI `tanpa_batas` (tanpa satu angka pun, "batasnya belum
disetel" bukan kalimat yang paling menolong), dan catatan barisnya menyebut
**kedua** sisi yang hilang supaya satu keadaan tidak menyembunyikan kekurangan
yang lain.

**BATASNYA TIDAK PERNAH DISIMPULKAN DARI NILAINYA** (verifikasi F-2 putaran 2).
Baris yang tidak punya batas mengirim `limit` **null**; nol adalah ANGKA. Aturan
lama "`limit` ≤ 0 berarti tidak ada batas" ditulis untuk `prj_projects.contract_value`
yang berbawaan 0 (0 di sana berarti belum dicatat, bukan kontrak nol rupiah) —
dan ia menelan sisi yang paling berbahaya yang ada: RAP yang menganggarkan
**Rp 0** untuk subkon sementara **Rp 200.000.000** biaya subkon sudah tercatat
hilang dari registri sebagai "Batas belum disetel", lalu — karena urutannya
menurut persentase, yang tidak ada — jatuh ke DASAR daftar yang seluruh tugasnya
menyebutkan apa yang melewati batasnya (terukur di kedua driver). Sekarang
`rapVersusContract` sendiri yang mengirim null saat nilai kontrak belum dicatat,
`state()` yang memutuskan arti sebuah batas nol (dibelanjakan → `lampau`, belum →
`tanpa_anggaran`), dan urutan registri memakai `stateRank()` **lebih dulu**,
persentase sesudahnya — satu definisi "lebih buruk", dipakai juga
`BudgetRealisationService::worstSide()`.

**PERSENTASE YANG DICETAK TIDAK PERNAH MEMBANTAH LENCANANYA** (verifikasi F-2
putaran 2). Sebuah baris yang MASIH di bawah batasnya tidak boleh tercetak
"100 %": `pct()` memulangkan angka terbesar yang masih tercetak di bawah 100 pada
presisi layar (99,9 % pada satu desimal) untuk `actual < limit` yang membulat
menjadi 100. Terukur di peramban: RAP Rp 24.250.000.000 terhadap nilai kontrak
Rp 24.250.000.001 berbunyi "100% · Mendekati batas", tepat di bawah kartu "Cara
membacanya" layar itu sendiri ("…menjadi 'Melampaui batas' tepat pada 100 %").
Pembulatannya condong seperti seluruh registri: boleh memperingatkan lebih awal,
tidak boleh menenangkan lebih lama.

**Aturan yang sama dengan `WatchedDeadlines`:** `DB::table`, literal string,
**tanpa impor modul fitur** (dipaku `ThresholdWatchTest::test_core_imports_no_feature_module_to_compute_a_threshold`,
yang memindai baris `use` berkas registrinya sendiri). Tabel dan kolom dijaga
`missingSchema()`, jadi modul yang belum bermigrasi menjadi baris SKIPPED —
bukan `QueryException`, dan bukan entri kosong yang terbaca "semua aman".

**ENTRI YANG ANGKANYA MILIK MODUL LAIN DIPASOK, BUKAN DISALIN.** `project_budget_pct`
mengukur realisasi + KOMITMEN terhadap RAP, dan aritmetika komitmen hidup di
`Finance\Services\CommitmentService` — yang dibaca `BudgetGateService` sebelum
menolak sebuah PO. Menyalin SQL-nya ke Core berarti dua jawaban atas satu
pertanyaan. Maka Core mendeklarasikan entrinya (label, izin, tautan, ambang,
keadaan) dan modul pemiliknya memasok barisnya:

```php
// Modules/Finance/Providers/FinanceServiceProvider::boot()
WatchedThresholds::supply(
    'project_budget_pct',
    static fn (): array => app(BudgetRealisationService::class)->thresholdRows(),
);
```

Closure, bukan hasil: pemindaian bisa terjadi kapan saja setelah boot, dan
menghitungnya saat boot membebani setiap permintaan. Selama tidak ada yang
memasok, entrinya SKIPPED. `flushSchemaMemo()` sudah dipasang di
`ErpTestCase::setUp`; **`flushSuppliers()` BELUM** — uji yang perlu melihat
entri yang dipasok modul sebagai SKIPPED harus memanggilnya sendiri (satu-satunya
pemanggil hari ini: `ThresholdWatchTest:296`).

**Ambang peringatan ada di `config('erp.thresholds.<kunci>')`**, satu kunci per
entri, bawaan 90 % (ROADMAP-HASHMICRO §5 baris 13). Ini PERINGATAN, bukan
gerbang: tidak ada satu dokumen pun yang ditolak karena angka di blok itu — yang
menolak PO/SPK yang menjebol RAP tetap `erp.procurement.budget_gate`, dengan
kalimatnya sendiri.

Entri yang dikirim F-2: `project_budget_pct` (dipasok Finance),
`rap_vs_kontrak_pct` dan `overhead_budget_pct` (dihitung Core). Layarnya
`#/ambang`, tetangga `#/tenggat` di grup Ringkasan. F-7 menambah
`maintenance_hour_meter` (dipasok Assets) — entri pertama yang satuannya BUKAN
rupiah dan ambangnya BUKAN persen; lihat dua paragraf berikut.

**SATUANNYA BUKAN SELALU RUPIAH (F-7).** Setiap entri sudah mendeklarasikan
`unit` sejak F-2 dan **tidak ada yang membacanya**: `ambang.js` memanggil
`fmt.rupiah` pada kedua sel angkanya, jadi entri berjam pertama mencetak
"Rp 3.375,50" untuk 3.375,5 JAM. Sekarang layar memformat menurut `unit`
(`rupiah` → `fmt.rupiah`, selain itu → `fmt.qty(value, unit)`), aturan yang sama
yang sudah dipegang kolom pertama tabelnya untuk `subject_word`: satuan yang
dideklarasikan sebuah entri tidak boleh hilang di tabel yang menampilkannya.
`cells.js` tipe `qty` ikut menerima `unit` opsional untuk alasan yang sama.

**DAN AMBANGNYA BUKAN SELALU PERSEN (F-7).** Sebuah entri yang kedua sisinya
BUKAN bagian-dari-keseluruhan mendeklarasikan `proportional => false`: `pct`
tidak dihitung sama sekali (barisnya mengirim `remaining`, dan layar mencetak
"24,5 jam lagi" / "150 jam lewat"), dan ambangnya dinyatakan dalam SATUAN entri
lewat `warn_margin_key` + `warn_margin_default` — `state()` menerima parameter
keempat `$warnMargin` yang menggantikan perbandingan `displayPct` dengan
`$actual >= $limit - $warnMargin`. **Empat keadaan yang lain tidak berubah**:
LAMPAU tetap dihakimi pada ANGKA-nya, dan ketiga keadaan yang bukan angka tetap
mendahului keduanya. Alasan terukurnya: meter kumulatif tidak pernah mulai dari
nol pada servis terakhir, jadi "96 % terpakai" di sana mengukur UMUR alat, bukan
sisa jatah servisnya — satu angka config 90 % memberi tenggang 350 jam pada alat
bertarget 3.500 jam dan 1.225 jam pada alat bertarget 12.250 jam. Urutan baris
entri seperti itu memakai `remaining` (yang tersisa paling sedikit lebih dulu),
bukan persentase yang akan mengurutkan menurut umur alat.

**Bawaan margin ada di DUA tempat dan keduanya harus satu angka**: entri Core
(`warn_margin_default`) dan modul pemasoknya (`MaintenanceDueService::WARN_MARGIN_DEFAULT`).
Ditemukan lewat mutasi: karena `config/erp.php` selalu menyebutkan kuncinya,
mengubah bawaan sisi Core dari 50 menjadi 999 **lolos hijau** sampai sebuah uji
mengosongkan config-nya lebih dulu. `ThresholdHourMeterTest` memaku keduanya.

**BARIS YANG DIPASOK ADALAH SISI YANG DITEGAKKAN, BUKAN AGREGATNYA**
(verifikasi F-2). `project_budget_pct` memasok sisi PROYEK yang paling dekat ke
batasnya — non-subkon (dihakimi saat PO diajukan) atau subkon (saat SPK) —
karena hanya batas per sisi itulah yang benar-benar menolak dokumen; totalnya
ikut di catatan barisnya. Sebuah registri yang mengukur agregat akan
membariskan proyek yang totalnya 16,7 % terpakai di antara yang aman sementara
setiap PO-nya sudah ditolak. Aturannya untuk entri berikutnya: **yang diawasi
adalah angka yang menolak sesuatu.**

Kolom pertama tabelnya memakai `subject_word` entri apa adanya (dikapitalkan),
bukan dua pilihan yang dipatok layar: entri yang menyebut satuannya sendiri
tidak boleh kehilangannya di tabel yang menampilkannya.

## 25. Aktivitas CRM (`crm_activities`, F-3)

Register pekerjaan penjualan: telepon / rapat / email / kunjungan / catatan, menggantung pada satu
prospek, penawaran, atau pelanggan. **Register, bukan dokumen** — tanpa nomor, tanpa persetujuan,
tanpa jurnal (selera `crm_guarantees`). Yang dikenali orang adalah subjeknya.

**Dua tanggal dengan dua tipe yang berbeda, dan itu disengaja.**

| Kolom | Tipe | Kenapa |
|---|---|---|
| `due_at` | `date` | "Hubungi lagi Senin depan" adalah sebuah HARI. Menyimpannya sebagai `00:00` memalsukan ketelitian yang tidak pernah diketik siapa pun (alasan yang sama tertulis di migrasi 000383 untuk `next_follow_up_at`, dan turunannya harus setipe dengan sumbernya). |
| `done_at` | `timestamp` | Dicap SERVER saat seseorang menekan "Selesai" — momen yang sungguhan terjadi, bersama `done_by_id`. |

`document_type` + `document_id` menyimpan **jenis pendek** (`lead` / `quotation` / `customer`),
tidak pernah nama kelas — pola `fin_payment_allocations.payable_type`, dan alasan
`AttachableDocuments`: endpoint yang menerima nama kelas mengizinkan penelepon menyebut kelas apa
pun sebagai induk sebuah baris. Daftar sahnya `Modules\Crm\Support\ActivityDocuments`, dan
cerminnya di klien (`public/app/js/views/activities.js` `ACTIVITY_DOCUMENTS`) dijaga
`ActivityRegistryTest` — slug yang hanya ada di satu sisi adalah kartu yang selalu 422, atau
dokumen yang diam-diam tidak bisa mencatat satu pun aktivitas.

**Satu pintu tulis: `ActivityService`.** `done_at`/`done_by_id` ditolak bila diketik (dan dibuang
lagi dengan `Arr::except` — ikat pinggang dan tali, §26);
induk yang tidak ada ditolak dengan kalimat yang menyebut jenis dokumennya dalam bahasa layar; dan
setiap perubahan memanggil `LeadFollowUpService` (§26). Dua pintu berarti satu di antaranya lupa
menghitung ulang turunannya.

**Kartunya** dipasang `renderDetail` dalam satu baris, seperti kartu Lampiran, dan keanggotaannya
diputuskan cermin registri di dalam kartu itu sendiri. Empat aturan kejujurannya:

1. **Kartu kosong mengatakan dirinya kosong** — "Belum ada aktivitas dicatat untuk dokumen ini",
   tidak pernah "0 aktivitas". Angka nol yang dipajang sebagai hasil pengukuran adalah kebohongan
   kecil yang paling sering dipercaya.
2. **"Lewat tanggal" dihitung SERVER** (`is_overdue`): jam peramban yang meleset dua hari akan
   mewarnai baris yang salah, dan warna itulah yang dipakai orang memilih pekerjaan hari ini.
3. **Kartu prospek menyebut asal tanggal tindak lanjutnya**, dengan subjek aktivitas yang
   menentukannya.
4. **Yang dipotong diakui, dan jumlahnya datang dari server** (verifikasi F-3, 8 Sep 2026). Kartu
   menggambar paling banyak 100 baris dan memakai `api.list` — `api.get` membuang amplopnya, jadi
   kartu yang memakainya menghitung ringkasannya dari baris yang KEBETULAN termuat: "100 terbuka."
   pada dokumen berisi 110, sementara kartu papan untuk dokumen yang sama menyebut 110. Kalimat
   pemotongannya sama bentuknya dengan papan ("100 dari 110 digambar"), dan pada kartu yang
   terpotong jumlah terbuka/lewat-tanggal DITANYAKAN LAGI ke server alih-alih ditaksir dari yang
   terlihat.

**Layar detailnya menyebut dokumen induknya** dan menautkannya (`document_id` berlabel "Dokumen",
tidak dibayangi `document_label`; peta jenis→layar dibalik dari registri yang sama). Antrean kerja
yang barisnya tidak bisa dibuka sampai ke pekerjaannya adalah antrean buntu.

**Pengawasnya** satu entri `WatchedDeadlines` (`crm_activity_due`, `lead_days` 3, izin
`crm.update`, tautan `r/crm/activities?state=open`) — bukan perintah baru. `done_at` yang
mendiamkannya; itu pula sebabnya "Selesai" mencap waktu alih-alih menghapus barisnya.

Dua hal yang harus tetap sejalan dengannya, dan pernah tidak (verifikasi F-3):

- **Hari jatuh temponya belum terlambat** (`valid_through_end`): telepon yang dijanjikan hari ini
  masih bisa ditelepon hari ini, jadi hari itu MENIPIS dan LEWAT mulai besok — sama dengan
  `Activity::isOverdue`, saringan `state=overdue`, dan hitungan lewat-tanggal kartu papan. Tanpa
  bendera itu orang dikabari pukul 08.30 bahwa pekerjaannya terlambat lalu tidak menemukan satu pun
  tanda terlambat di layar mana pun.
- **Tautannya membawa saringan yang layarnya deklarasikan.** `views/list.js` membuang kunci query
  yang tidak ada di `def.filters` DIAM-DIAM; tanpa saringan `state`, pemberitahuan jatuh tempo
  membuka daftar yang urut `due_at` lintas keadaan — baris pertamanya pekerjaan yang selesai 20
  bulan lalu.

## 26. Transisi pipeline prospek (`LeadStatus::canMoveTo`, F-3)

`crm_leads.status` bukan lagi kolom formulir. Sampai F-3 satu `PUT` dengan `{"status":"won"}`
memenangkan sebuah prospek **tanpa penawaran, tanpa nilai, tanpa tanggal keputusan** — sementara
win-rate per sales dihitung dari kolom itu.

**Matriksnya, satu tempat** (`LeadStatus::canMoveTo` memulangkan `LeadMove`, bukan boolean: sebuah
boolean hanya bisa mengatakan "tidak", dan setiap layar yang membacanya lalu harus MENEBAK sebabnya
untuk bisa menulis kalimatnya):

| dari \ ke | Baru | Sudah Dihubungi | Terkualifikasi | Penawaran Dikirim | Menang | Kalah |
|---|---|---|---|---|---|---|
| **Baru** | sama | maju | maju | maju | lewat penawaran | lewat penawaran |
| **Sudah Dihubungi** | mundur | sama | maju | maju | lewat penawaran | lewat penawaran |
| **Terkualifikasi** | mundur | mundur | sama | maju | lewat penawaran | lewat penawaran |
| **Penawaran Dikirim** | mundur | mundur | mundur | sama | lewat penawaran | lewat penawaran |
| **Menang** | terkunci | terkunci | terkunci | terkunci | sama | lewat penawaran |
| **Kalah** | terkunci | terkunci | terkunci | terkunci | lewat penawaran | sama |

- **maju** — bebas, boleh melompati tahap: prospek dari undangan tender memang lahir langsung
  terkualifikasi.
- **mundur** — boleh, **dengan alasan ≥ 5 karakter** yang tersimpan di `crm_lead_status_changes`
  (append-only) dan terbaca di kartu "Riwayat Tahap". Mundur adalah kabar buruk, dan corong yang
  bisa dimundurkan diam-diam adalah corong yang angka konversinya tidak berarti apa-apa.
- **lewat penawaran** — Menang/Kalah **hanya** lahir dari `QuotationService::markWon/markLost`
  (yang memanggil `LeadPipelineService::decideByQuotation`, jadi keputusannya ikut tercatat dengan
  kode QTN-nya). Penolakannya menyebut penawaran MANA yang harus ditandai — **yang tombolnya
  sungguh ada di sana**: "Tandai Menang" hanya lahir pada penawaran DISETUJUI, sedangkan "Tandai
  Kalah" ada pada setiap penawaran yang belum diputuskan. Yang belum disetujui disebut bersama
  langkah yang kurang ("masih draf — ajukan dan setujui dulu"); yang semua penawarannya sudah
  diputuskan disuruh membuat penawaran baru; dan yang memang belum punya satu pun diakui apa
  adanya. Sebuah penolakan yang menyebut alamat yang salah lebih buruk daripada penolakan tanpa
  alamat: yang kedua membuat orang bertanya, yang pertama membuatnya yakin aplikasinya rusak.
- **terkunci** — prospek yang sudah menang/kalah tidak bisa dikembalikan ke tahap mana pun; nasibnya
  milik penawarannya.

**Pintunya satu: `POST crm/leads/{id}/pipeline` `{status, reason?}`** (`LeadPipelineService`).
`LeadUpdateRequest` menolak `status` dan `next_follow_up_at` dengan **`missing`, bukan
`prohibited`**, `LeadStoreRequest` hanya menerima tahap TERBUKA saat membuat, dan isian status di
formulir SPA `createOnly`. Sebuah pintu kedua yang tidak memeriksa apa-apa membuat pintu pertama
sekadar saran.

> **`prohibited` bukan penolak yang Anda kira** (verifikasi F-3, 8 Sep 2026). Aturannya adalah
> kebalikan `required`, jadi ia LULUS untuk nilai kosong: `{"next_follow_up_at": null}` dijawab
> 200 dan MENGHAPUS kolom turunannya (`""` sama saja — `ConvertEmptyStringsToNull`), dan
> `{"status": null}` menjadi HTTP 500 "NOT NULL constraint failed". `missing` gagal begitu kuncinya
> ADA. Untuk field yang tidak boleh ditulis formulir, `missing` adalah aturannya — dan
> controllernya tetap menyaring sekali lagi (`Arr::except`), karena satu rule yang salah pilih
> tidak boleh cukup untuk membatalkan sebuah aturan.

**Alasan mundur diminta oleh SERVER, dijawab SPA.** Layanan menolak 422 berkunci `reason`; mesin
`confirmResubmit` (§ actions.js) membuka satu isian wajib berisi kalimat servernya lalu mengirim
ulang. Deklarasinya satu (`ALASAN_MUNDUR` di `schema.js`) dan dipakai tombol "Ubah Tahap" DAN
keenam perpindahan papan — dialog yang berbeda antar permukaan adalah cara sebuah aturan berhenti
terasa seperti satu aturan.

**Papan pipeline** (`b/crm/leads`) memakai mesin §19 apa adanya, dengan tiga kait kecil yang lahir
di sini dan berlaku untuk papan mana pun:

| Kait | Isi | Kenapa |
|---|---|---|
| `board.api` | rute baca papan (`crm/pipeline/board`) | Papan generik mengambil SATU halaman lalu mengelompokkannya di klien: 300 prospek Menang mendorong kolom "Baru" keluar halaman dan papannya tampak kosong justru di kolom yang paling dikerjakan. Rute papan mengambil N teratas **per kolom** dan memulangkan jumlah sebenarnya di `meta.lanes` — selisihnya dikatakan ("5 dari 7 digambar"). |
| `board.card.fields` | kunci baris yang dicetak apa adanya di kaki kartu | Kalimat siap pakai dari server ("Belum ditugaskan", "2 aktivitas lewat tanggal"). Nilai kosong DILEWATI: baris "0 aktivitas" yang selalu ada mengajari orang mengabaikan barisnya. |
| `action.body` + `action.boardOnly` | muatan tetap satu aksi; aksi yang tidak muncul di bilah dokumen | Satu endpoint melayani enam kolom. `boardOnly` menahan enam tombol "Pindahkan ke …" keluar dari bilah aksi — termasuk dua yang memang **selalu ditolak** (Menang/Kalah), yang dipetakan justru supaya kartunya kembali membawa KALIMAT SERVER, bukan kalimat generik papan ("tidak ada aksi yang memindahkan dokumen ke kolom itu") yang terdengar seperti aplikasi rusak. |

**Pemilik prospek: `owner_user_id`** (migrasi 000397 mengganti nama `user_id` — bukan menambah
kolom kedua; lihat docblock-nya). Boleh kosong, dan yang kosong berbunyi **"Belum ditugaskan"** di
setiap permukaan lewat SATU kalimat (`ActivityResource::ownerName`, dipakai juga `LeadResource`):
daftar, CSV daftar, kartu papan, dan layar dokumen. Pemilik yang baris penggunanya sudah tidak ada
berbunyi lain — itu data rusak, bukan "belum ditugaskan".

## 27. Absensi masuk/pulang dari ponsel (`hr_attendances.check_in_*`, F-4)

Absensi ponsel adalah pengukuran, dan setiap aturan di bawah lahir dari satu pertanyaan: apa yang
boleh dikatakan sistem ini kepada orang yang membacanya nanti.

**Mencatat, tidak pernah menolak.** Di luar radius proyek, di dalam, atau tidak diketahui —
absensinya tersimpan sama saja. Ambang geofence (`hr.attendance.geofence_metres`, bawaan 500 m)
hanya menentukan baris mana yang DITANDAI. Menolak absensi karena GPS berarti orang yang tetap
bekerja hari itu tidak punya catatan sama sekali, dan yang paling sering kena bukan orang yang
berbohong melainkan gudang berdinding beton, basement, dan ponsel murah.

**Tidak tahu bukan nol.** Proyek tanpa `latitude`/`longitude`, dan ponsel tanpa fix, sama-sama
menghasilkan `check_in_distance_m` **NULL**. Nol berarti "berdiri tepat di titik proyek". Kolom
`outside_geofence` sengaja TIDAK ADA: ia turunan penuh dari (`distance_m`, `geofence_m`), dan kolom
turunan yang disimpan adalah kolom yang suatu hari melenceng dari sumbernya.
`Attendance::outsideGeofence($side)` menghitungnya dan mengembalikan **null** untuk keadaan ketiga —
tiga keadaan, dan yang ketiga BUKAN "di dalam".

**Kalimatnya milik server.** `AttendanceResource` menerbitkan `distance_text` (`"—"` bila null),
`verdict` (`inside|outside|unknown`) dan `verdict_text` jadi. Layar mengulangnya; tidak ada layar
yang menyusun kalimatnya sendiri dari angka. Idiom yang sama dengan
`BudgetRealisationService::sideView()` (F-2), dan alasannya sama: layar kedua akan menyusunnya
sedikit berbeda, dan yang ketiga akan menulis "0 m".

**Waktu perangkat dicatat, tidak pernah menggantikan.** `*_at` = jam server saat catatan sampai;
`*_device_at` = jam ponsel saat tombol ditekan. Antrean luring bisa mengirim berjam-jam kemudian,
jadi jam server sendirian berbohong tentang kapan orangnya datang; jam ponsel bisa dipalsukan
siapa saja. **Satu-satunya keputusan yang boleh diambil jam ponsel adalah TANGGAL barisnya**, dan
hanya bila ia masih dalam ±48 jam dari jam server — tanpa itu antrean yang menyeberangi tengah
malam menaruh absen kemarin di hari ini dan menimpanya lewat kunci unik (karyawan, tanggal). Jam
ponsel yang berjalan maju tetap dipotong ke hari server. **`Carbon::parse()` atas ISO-8601 ber-Z
menghasilkan objek ber-zona UTC**: ia WAJIB `->setTimezone(config('app.timezone'))` sebelum dipakai,
atau absen pukul 06.30 WIB (23.30 UTC kemarin) diarsipkan ke tanggal kemarin.

**Ambang distempel, seperti kebijakan persetujuan F-1.** `*_geofence_m` menyimpan radius yang
BERLAKU saat itu, dan hanya ketika jaraknya benar-benar terukur. Tanpa stempel, menaikkan angka di
Pengaturan membersihkan setiap tanda "di luar lokasi" di masa lalu secara surut. `*_project_id`
menyimpan proyek ACUAN pengukuran, terpisah dari `project_id` baris: kerani boleh memindahkan baris
ke proyek lain sesudahnya, dan tanpa acuan tersimpan jaraknya diam-diam berubah arti.

**Idempotensi antrean.** Butir yang sama dikirim ulang membawa `device_at` yang sama — satu-satunya
tanda yang membedakan "kirim ulang" dari "ditekan dua kali". Absen masuk kedua: yang PERTAMA
menang, dan dikatakan. Absen pulang kedua: yang TERAKHIR menang (orang benar-benar pulang
belakangan) dan yang lama menjadi baris jejak.

**Pintu absen tidak menerima `employee_id`.** `POST hr/attendances/me/clock-in|clock-out` menulis
baris milik `users.employee_id` pemanggilnya. Sebuah field yang bisa menyebut orang lain
menjadikannya pintu untuk mengabsenkan rekan yang belum datang. Akun tanpa kartu karyawan mendapat
kalimat, bukan layar rusak dan bukan absensi orang lain.

**Balapan pada absen pertama hari itu.** `rowFor()` melakukan SELECT lalu `save()` melakukan
INSERT, dan di antaranya baris (karyawan, tanggal) yang sama bisa lahir dari pintu lain. Sekali
ulang pada `UniqueConstraintViolationException` — di pintu absen DAN di lembar kerani. Pintu yang
aturannya "mencatat, tidak pernah menolak" tidak boleh menjawab 500; itu penolakan paling keras
yang tersedia. Idiom rumah yang sama: `DailyReportService`, `HseDailyService`,
`BankStatementImportService`.

**Jam ponsel yang tidak terbaca kehilangan JAMNYA, bukan absennya.** `device_at` divalidasi sebagai
STRING, bukan `date`: aturan `date` berjalan sebelum service dan menjawab 422 untuk "banana" —
tidak ada baris, tidak ada selfie, tidak ada catatan bahwa orangnya datang. Dan
`Carbon::parse('0000-00-00 00:00:00')` TIDAK melempar (ia memulangkan tahun nol, yang MySQL ketat
menolak menulis), jadi `deviceTime()` membuang apa pun di luar tahun 2000–2100.

**Satu selfie per sisi, dan yang pertama bertahan.** Menimpa penunjuknya meninggalkan berkas
pertama di penyimpanan tanpa baris yang menyebutnya. Absen kedua yang membawa foto TETAP menyimpan
fotonya bila sisi itu belum punya — foto bukan jam — dan yang ditolak dikatakan, bukan ditelan.
Ekstensinya dibatasi ke gambar: daftar izin `AttachmentService` bersifat generik (PDF, Word, CSV),
dan `accept="image/*"` di HTML hanya saran kepada pemilih berkas.

**Menghapus baris absensi.** Ditolak bila barisnya membawa jejak koreksi ATAU absen dari ponsel.
Jejak tambah-saja yang bisa dimusnahkan pemegang `hr.delete` tidak membuktikan apa pun, dan absen
ponsel adalah catatan seseorang tentang dirinya. FK-nya `restrictOnDelete` sebagai lapis kedua
untuk jalur yang tidak lewat controller.

**Dua keadaan "tidak ada karyawan", dua kalimat.** Akun tanpa `employee_id` diminta menautkan;
akun yang tertaut ke kartu yang di-soft-delete diminta mengaktifkan kembali kartunya. Kalimat
pertama untuk keadaan kedua menyuruh HR menautkan yang sudah tertaut.

**`GET hr/attendances` menuntut `hr.view` sejak F-4.** Barisnya kini membawa koordinat, akurasi fix
dan selfie — riwayat posisi seseorang hari demi hari, setara dengan register sertifikat dan
pengajuan cuti yang sudah dijaga. `GET hr/attendances/me` adalah pintu tanpa izin untuk baris
sendiri: kueri yang secara struktur tidak bisa mengembalikan baris orang lain, bukan penyaring di
atas daftar yang sama.

## 28. Jejak koreksi absensi (`hr_attendance_corrections`, F-4)

TAMBAH-SAJA: tidak ada rute update maupun delete, dan tidak akan ada. Log yang bisa diedit tidak
membuktikan apa pun.

TIGA pintu bisa mengubah satu baris absensi, dan ketiganya menulis lewat SATU kelas
(`AttendanceCorrectionService`) — cacat yang paling sering ditemukan kampanye ini adalah aturan yang
ditegakkan di satu pintu lalu bocor di pintu kedua:

| `source` | Pintu | Alasan |
|---|---|---|
| `update` | `PUT hr/attendances/{id}` (pengawas) | **Diketik, wajib, minimal 5 karakter.** |
| `bulk` | `POST hr/attendances/bulk` (lembar kerani dikirim ulang) | Ditulis sistem. Memaksa 40 alasan per lembar berarti kerani kembali ke kertas, dan absensi yang tidak tercatat sama sekali jauh lebih buruk daripada jejak beralasan generik. |
| `clock` | absen pulang kedua dari ponsel | Ditulis sistem, menyebut jam pulang sebelumnya. |

`pending()` dipanggil SEBELUM `save()` (ia membaca `getDirty()`/`getRawOriginal()`), `write()`
sesudahnya (baris baru belum punya id). Nilai disimpan sebagai teks, dan **null tidak boleh menjadi
string kosong**: `""` berarti seseorang mengetik catatan kosong, `null` berarti tidak pernah ada
catatan.

**Kolom pengukuran tidak bisa dikoreksi**: koordinat, jarak, akurasi dan ambang tidak ada di
`AttendanceUpdateRequest`. Itu hasil pengukuran, bukan pendapat — dan orang yang paling
berkepentingan menghapus tanda "di luar lokasi" adalah orang yang ditandai. Yang BOLEH: status,
catatan, proyek, dan jam masuk/pulang ("lupa absen pulang" adalah kasus paling sering di lapangan).
Kunci yang ABSEN dari badan permintaan tidak disentuh; `null` EKSPLISIT mengosongkan.

**Lembar kerani tidak pernah menulis kolom jam.** Lembar kertas tidak tahu jam berapa orangnya
datang, dan menimpanya dengan null berarti lembar yang dikirim ulang menghapus bukti GPS hari itu.

## 29. Usulan rekap absensi — dan mengapa tidak ada POST-nya (F-4)

`GET hr/attendance-recaps/proposal` MEMBACA. Tidak ada endpoint pasangan yang menulis, tidak ada
jalur dari `hr_attendances` ke `hr_payroll_runs`/`hr_payslips`, dan
`AttendanceIsNotPayrollInputTest` memakukan keduanya dari dua arah: uji perilaku (menulis 30 hari
absen lalu menghitung ulang payroll tidak menggeser satu rupiah) DAN uji sumber (tujuh berkas
penghasil payroll tidak menyebut register absensi, lima berkas absensi tidak menyebut payroll).
Satu lapis tidak cukup — yang pertama hijau juga untuk jalur yang kebetulan belum ada datanya, yang
kedua hijau juga untuk kode yang memanggilnya lewat nama tabel mentah.

Alasannya bukan kehati-hatian yang samar: register absensi boleh dikoreksi kapan saja (F-4 justru
menambah pintu koreksinya), sedangkan payroll yang disetujui sudah membukukan jurnal dan membayar
orang.

**`whereDate`, bukan `whereBetween`.** Cast `date` MENYIMPAN tengah malam, dan SQLite membandingkan
STRING: `'2026-06-30 00:00:00' > '2026-06-30'`, sehingga tanggal terakhir setiap bulan jatuh keluar
dari rentangnya. MySQL punya kolom DATE sungguhan dan benar — jadi yang salah justru driver yang
dipakai produksi hari ini. Karyawan yang SATU-SATUNYA catatannya bulan itu jatuh di sana lenyap
seluruhnya, dan layarnya lalu mencetak "register bulan ini kosong" tentang orang yang ada di
dalamnya.

**"Absen ponsel" dihitung dari `check_in_device_at`, bukan `check_in_at`.** Pengawas boleh mengetik
jam masuk (§28 menganjurkannya untuk "lupa absen pulang"), dan hanya pintu absen ponsel yang
menulis jam PERANGKAT. Menghitung kolom yang salah membuat layar melaporkan hari yang tidak pernah
disentuh ponsel siapa pun sebagai absen ponsel tanpa jarak terukur.

**Yang tidak diusulkan sama pentingnya dengan yang diusulkan.** `not_proposed` membawa `field`,
`label` DAN `why` untuk sakit, cuti, hari kerja, jam lembur dan hari setengah — register tidak tahu
apa-apa tentang kelimanya. Mengisinya dengan 0 akan terlihat seperti jawaban dan terbawa ke slip
gaji sebagai hak yang hilang. Karyawan tanpa satu pun catatan bulan itu **tidak muncul** sebagai
baris nol: "0 hadir" membaca seperti absen sebulan penuh.

## 30. Antrean kirim bersama (`public/app/js/uploadqueue.js`, F-4)

Satu kotak keluar untuk seluruh aplikasi: bilah kemajuan per butir (XHR `upload.onprogress` lewat
`api.upload`), yang gagal tetap terdaftar dengan "Kirim ulang", butir bertahan di `localStorage`
melewati muat-ulang halaman dan sesi yang berakhir, satu kirim pada satu waktu. Awalan
`nusantara_erp_upload:<user id>:` — per pengguna (tablet lapangan dipakai bergantian) dan tidak
boleh saling mengawali dengan awalan draf `drafts.js`.

**Bentuk muatan didaftarkan DI DALAM berkas antrean** (`KINDS`), bukan oleh layar yang membuatnya.
Butir hidup lebih lama daripada layar: butir 'clock' yang bentuknya hanya dikenal
`views/absensisaya.js` akan terlihat di kartu "belum terkirim" tanpa bisa dikirim ulang sampai
orangnya kebetulan membuka layar yang benar.

**`api.upload(..., { raw: true })`** memulangkan AMPLOP, bukan `data`. Untuk absensi kalimat yang
benar ada di `message` server — tanpa amplopnya toast hanya bisa berkata "terkirim" tentang absen
yang tercatat 8 km di luar lokasi.

**Antrean digambar DI LUAR kotak yang dilukis ulang `load()`.** Selama luring `load()` gagal dan
segalanya yang dilukisnya lenyap; kalau barisnya ikut lenyap, pita luring menyuruh orang menekan
"Kirim ulang" pada baris yang tidak ada di layar. Tombol aksinya pun digambar ulang dari jawaban
terakhir yang berhasil, dengan pita "tidak dapat dimuat" di atasnya — antrean itu dibuat justru
untuk saat tidak ada sinyal.

**Butir tanpa `kind` dipulihkan sebagai lampiran.** Versi sebelum F-4 tidak pernah menulis field
itu. Butir yang tidak dikenali dan hanya dilewati akan lenyap dari layar DAN tetap memakan kuota
selamanya — yang tidak pernah masuk `readQueue()` tidak pernah sampai ke `forget()`. Bentuk yang
tetap tidak dikenali (dari versi lebih baru) DIBUANG dari `localStorage`, bukan dilewati.

**`devicePosition()` memegang batas waktunya sendiri.** Menurut spesifikasi Geolocation, penghitung
`timeout` baru berjalan sesudah izin diberikan; permintaan izin yang tidak dijawab — pemakaian
pertama, di gerbang proyek — menggantung selamanya, dan butirnya berhenti di `locating` tanpa
"Kirim ulang", tanpa "Buang", tanpa terkirim.

**Muatan yang ditolak klien tidak boleh membatalkan peristiwanya.** Selfie kebesaran mengorbankan
FOTONYA; absennya tetap masuk antrean, dan toastnya mengatakan keduanya. Kamera ponsel modern rutin
melewati 5 MB, jadi ini jalur yang sering, bukan jarang.

**Kunjungan pertama yang luring tetap punya tombolnya.** Pintu absen tidak membutuhkan daftar hari
maupun daftar proyek, jadi tombolnya digambar tanpa jawaban server sama sekali; yang tidak bisa
diketahui dinyatakan apa adanya, bukan ditebak. Layar yang hanya berisi panel galat sementara pita
luring menyuruh menekan tombol yang tidak ada adalah cacat P1-I yang terulang.

**`.btn.lg` menyetel `height`, bukan padding.** `.btn` punya `height: 34px` dan `box-sizing:
border-box`, jadi padding vertikal tidak menumbuhkan kotaknya sama sekali. Target sentuh rumah ini
42–44 px, dan layar yang dipakai satu tangan sambil berdiri adalah tempat terakhir yang boleh
melanggarnya.

**Layar yang memakai antrean tidak boleh mendeklarasikan `QUEUE_PREFIX` atau `MAX_BYTES` sendiri**
(dipaku `UploadQueueTest`): dua antrean di `localStorage` yang sama tidak akan pernah saling
melihat, dan salinan kedua batas ukuran adalah salinan yang suatu hari berbeda dari servernya.

## 31. Titik pesan ulang (`inv_reorder_rules`, F-6)

Ambang "perlu dipesan ulang" untuk sepasang **gudang × item**. Aturan AKTIF untuk pasangan itu
**MENGGANTIKAN** `inv_items.min_stock` — bukan menambahnya, bukan "yang paling ketat menang", dan
itu berlaku juga bila titiknya LEBIH RENDAH. Kalau tidak, aturan gudang site tidak pernah bisa
lebih longgar daripada angka perusahaan, yang adalah persis alasan tabelnya lahir. `reorder_point`
0 pada aturan aktif berarti "pasangan ini tidak pernah dipesan ulang", jadi syarat `> 0` berlaku
pada ambang yang **MENANG**, bukan pada `min_stock`.

**Definisinya hidup di DUA tempat, dengan sengaja:** `StockService::lowStockAlerts()` dan salinan
literalnya di registri Core `ModuleCounts` entri `inv` (Core tidak boleh mengimpor modul fitur,
§16). Yang disalin adalah **struktur query builder**, bukan potongan SQL mentah: bentuk dua lengan
`OR` yang saling meniadakan lewat `r.id`, dengan `is_active` **di klausa ON** — di WHERE ia
mengubah LEFT JOIN menjadi INNER JOIN dan setiap pasangan tanpa aturan hilang dari daftar
sekaligus. `ModuleCountsTest` memaku kesetaraan keduanya **dan** memaku bahwa fixture-nya
benar-benar memisahkan "dengan aturan" dari "hanya min_stock" — tanpa lengan kedua itu, sepasang
salinan yang sama-sama melupakan tabel aturan lolos hijau.

**DAN KUERINYA PUNYA DUA BAGIAN, karena satu pasangan bisa BELUM PUNYA BARIS SALDO.** Kueri di atas
berangkat `FROM inv_stock_balances`, jadi pasangan gudang × item yang belum pernah kemasukan barang
tidak punya baris untuk berangkat — dan itu justru keadaan yang paling membutuhkan pesan ulang:
seseorang menyatakan "gudang ini menyimpan barang ini, titik pesan ulang 100" untuk barang yang
stoknya nol karena belum pernah masuk. Sampai putaran ketiga F-6, `governs()` berkata `true`, daftar
aturan menggambarnya berlaku, kartu itemnya berkata "1 gudang memakai titik pesan ulang sendiri" —
sementara daftar kekurangan, usulan PR dan tab "Perlu dipesan ulang" semuanya kosong. Bagian kedua
karena itu berangkat dari `inv_reorder_rules`, membuang pasangan yang PUNYA baris saldo
(`whereNull('b.id')` atas LEFT JOIN ke saldo), dan memperlakukan sisanya sebagai qty 0. Syaratnya
sama semuanya — aturan aktif, item hidup, gudang hidup, item aktif, titik > 0 — kalau tidak ia
menjadi pintu belakang yang melewati `governing()`. **Sebuah baris aturan ADALAH pernyataan "gudang
ini menyimpan barang ini"**; tanpa pernyataan itu, "setiap item × setiap gudang" adalah perkalian
yang akan menerbitkan ribuan baris pada `min_stock` perusahaan, dan itulah kenapa bagian kedua
hanya berangkat dari tabel aturan. **Kedua salinan wajib membawa keduanya**, ditambahkan dan bukan
di-UNION (satu pasangan punya baris saldo atau tidak punya, jadi keduanya tidak bisa beririsan);
fixture `ModuleCountsTest` membawa satu pasangan tanpa saldo, jadi salinan yang melupakan bagian
kedua jatuh.

**UNIQUE (warehouse_id, item_id), dan karena itu TANPA softDeletes** (pola `ast_depreciation_runs`
dan `core_saved_reports`). Dua baris hidup untuk satu pasangan menggandakan setiap baris kekurangan
di keempat permukaannya — layar Saldo Stok, widget dasbor, ubin launcher, usulan PR — tanpa satu
pun galat. Sebuah baris yang dibuang lembut akan menempati pasangannya selamanya sehingga aturan
yang dihapus tidak pernah bisa dibuat ulang; saklarnya `is_active`, yang memang untuk itu.
`Rule::unique` di FormRequest menambahkan **kalimatnya**, bukan aturan kedua.

**PRIORITASNYA DITULIS DI LAYAR, bukan hanya berlaku di kode — KE DUA ARAH.** Setiap baris
kekurangan membawa ambang yang menang, nama sumbernya (`threshold_source_label`), DAN angka item
yang kalah: sebuah baris yang menulis "20" padahal kartu itemnya berkata 100 tanpa mengatakan dari
mana 20 itu datang adalah angka yang tidak bisa diperiksa siapa pun. **Arah sebaliknya sama
wajibnya**, dan ia terlewat sampai putaran perbaikan F-6: kartu item adalah satu-satunya layar yang
memajang angka yang KALAH, jadi `ItemResource` mengirim `reorder_rule_note` bila ada aturan yang
BERLAKU untuk item itu ("N gudang memakai titik pesan ulang sendiri… stok minimum di atas TIDAK
berlaku"). Tanpa itu, yang menaikkan `min_stock` di sana mengira ia sedang mengubah ambang gudang
yang punya aturan; ia tidak mengubah apa pun.

**"Aktif ✓" BUKAN "berlaku", DAN "BERLAKU" PUNYA SATU DEFINISI: `ReorderRule::governing()`.**
Empat syarat — aturannya `is_active`, itemnya hidup, gudangnya hidup, ITEMNYA `is_active` — dan
keempatnya sudah lama ditegakkan kueri kekurangan (`r.is_active` di klausa ON, `whereNull` pada
kedua join-nya, `i.is_active` di WHERE-nya). Item dan gudang menghapus-lembut, relasi aturan memakai
`withTrashed()` dengan sengaja (supaya namanya selamat dan barisnya tetap bisa dibuang orangnya),
jadi sebuah baris bisa terlihat hidup sementara ambangnya tidak menentukan apa pun. Item yang
DINONAKTIFKAN adalah syarat tersendiri, bukan bagian dari "itemnya hidup": menonaktifkan adalah
jalur NORMAL untuk barang yang berhenti dibeli — kartunya tetap ada, dan sampai putaran ketiga F-6
barisnya digambar tanpa satu keping pun dengan `applies: true`. `ReorderRuleResource` karena itu mengirim `applies` (= `->governs()`,
bentuk baris dari scope yang sama) dan `deleted_labels`. **Yang digambar daftarnya sebagai keping
hanyalah `deleted_labels`**; `applies` adalah fakta server yang belum punya kolom sendiri, jadi
baris yang tidak berlaku KARENA ITEMNYA NONAKTIF tidak membawa tanda apa pun di layar itu — kolom
"Aktif" di sebelahnya adalah saklar ATURANNYA, dan ia memang menyala. Layar itu tidak berbohong
(ia tidak pernah menulis "berlaku"), tetapi ia juga tidak menjawab pertanyaannya.

**Salinan yang ketiga adalah bagaimana ia dulu bocor.** Sampai putaran kedua F-6 setiap permukaan
menghitung "berlaku" sendiri, dengan isi yang berbeda: `loadCount` kartu item memeriksa `is_active`
saja (jadi kartunya berkata "stok minimum di atas TIDAK berlaku" untuk aturan yang gudangnya sudah
dibuang, sementara layar sebelahnya menandai baris yang sama "Gudang dibuang"), dan `applies`
memeriksa kedua `deleted_at` saja (jadi aturan NONAKTIF dikirim `applies: true`). Kueri kekurangan
**tidak bisa** memanggil scope-nya — ia berangkat dari `inv_stock_balances` dan menyapa tabel aturan
lewat LEFT JOIN, karena pasangan TANPA aturan pun harus muncul — jadi yang menjaga keduanya satu
arti adalah **ujinya**: `ReorderThresholdTest` memaku bahwa kumpulan `governing()` PERSIS kumpulan
aturan yang dipatuhi kueri kekurangan, dan bahwa predikat barisnya sepakat dengan kuerinya satu per
satu.

## 32. Usulan PR dari kekurangan stok (`ReorderService`, F-6)

**Draf, dan tidak selangkah lebih jauh.** Dokumennya dibuat lewat `PurchaseRequisitionService` yang
sudah ada — yang selalu menyimpan Draf — dan berhenti. Tidak ada rute Inventory yang mengajukan
atau menyetujui (dipaku `ReorderProposalTest`). Alasannya sama dengan §29: sebuah ambang yang salah
ketik satu digit akan mengubah dirinya menjadi PO, dan PO adalah uang yang keluar.

**Idempotensi DINYATAKAN, bukan disimpulkan.** Sebuah item dilewati bila ia sudah menjadi baris
pada **PR ATAU PO terbuka** (`draft`/`submitted`/`approved`, belum dibuang) untuk gudang yang sama
**atau** pada dokumen yang tidak menyebut gudang sama sekali. `rejected`/`closed`/`cancelled`
**bukan** terbuka: PR yang ditolak adalah permintaan yang seseorang tolak, dan PO yang `closed`
sudah diterima penuh (PoService menutupnya sendiri begitu SELURUH barisnya diterima) — kekurangan
yang tersisa sesudahnya memang nyata.

**PO IKUT, dan itu bukan kelebihan cakupan.** `PurchaseOrderStoreRequest` MENGIZINKAN PO tanpa PR
(`purchase_requisition_id` nullable + `pr_bypass_reason` wajib bila kosong). Versi pertama layanan
ini hanya mengkueri baris PR, jadi barang yang sudah ada di PO Disetujui — uangnya sudah terikat —
muncul lagi sebagai "Akan diusulkan", dan menekan tombolnya melahirkan permintaan kedua yang baru
terlihat ketika barangnya datang dua kali. Kalimatnya membedakan keduanya: **"Sudah dipesan pada
PO/…"** versus **"Sudah diminta pada PR/…"**; yang pertama sudah menjadi janji kepada pemasok.

Lengan "tanpa gudang" adalah pilihan ke arah yang lebih sepi, dan biayanya dibayar dengan
keterlihatan: tiap baris yang dilewati menuliskan **kode dokumen** yang menutupinya. Kalimat
aturannya dikirim server (`why_skipped`) — salinan di layar akan menyimpang pada suntingan
pertama, dan sampai putaran perbaikan F-6 tidak satu pun uji PHP memakunya (hanya harness), jadi ia
hilang dari gerbang rilis paket berikutnya.

**Satu PR per GUDANG**, karena PR punya satu `warehouse_id`. Proyeknya **diturunkan** dari
`inv_warehouses.project_id`, taksiran harganya dari `inv_items.last_price`, jumlahnya dari ambang
yang menang (atau `reorder_qty` aturan bila aturan menyebutnya). Tidak ada angka yang dikarang.
Gerbangnya **`prc.create`**, bukan `inv.*`: yang dibuat adalah dokumen Procurement, dari layar mana
pun tombolnya ditekan.

## 33. Code 128 & label F/LBL (`Modules\Core\Support\Code128`, F-6)

Barcode yang salah **tidak terlihat salah**: digit periksa mod-103 yang keliru menghasilkan gambar
rapi yang tidak terbaca pemindai mana pun — atau, lebih buruk, terbaca sebagai **kode lain**,
sehingga barang yang dipindai masuk ke kartu stok barang lain. Karena itu:

- **Ujinya memuat DEKODER** (`Code128Test`): ia membaca `<rect>` dari SVG produksi, menyusun ulang
  deret lebar batang DAN spasi, menghitung ulang digit periksanya dari nol, dan mengembalikan teks.
  Uji yang menghitung jumlah batang, memeriksa `viewBox`, atau membandingkan snapshot HIJAU untuk
  kedua kegagalan di atas. Tabel `PATTERNS` sendiri diperiksa terhadap sifatnya (107 simbol, 11
  modul, 13 untuk stop, elemen 1–4 modul, semuanya unik) — encoder dan dekoder membaca tabel yang
  sama, jadi satu angka tertukar akan bolak-balik dengan sempurna.
- **Arti sebuah nilai bergantung pada SET yang berlaku.** Di set C, 99 adalah pasangan angka "99";
  yang berarti "pindah ke set C" hanya di set A/B, dan yang berarti "pindah ke set B" di set C
  adalah 100. Dekoder yang mengabaikan itu membaca `ITM-9999` sebagai `ITM-`.
- **Zona tenang 10 modul WAJIB**, dan dipaku dengan **angka 10**, bukan dengan konstantanya
  sendiri: uji yang membandingkan lebar terhadap `Code128::QUIET_MODULES` ikut berubah bersama
  mutasinya, dan menyetel konstanta itu ke 0 — yang membuang seluruh zona tenang dan membuat
  pemindai gagal diam-diam — lolos HIJAU (diukur).
- **Teks terbaca-manusia WAJIB**, dari teks yang sama dengan yang dikodekan — dan **DIPATAHKAN,
  bukan dibiarkan keluar viewport**. `<text text-anchor="middle">` dipusatkan tanpa batas lebar dan
  akar SVG memotong yang keluar, DI KEDUA UJUNG, diam-diam: barcode 13 digit di bawah kode item 40
  karakter tercetak sebagai 12 digit yang terlihat lengkap, dan orang yang mengetiknya ulang
  mendapat "tidak ada item dengan kode itu". Dipatahkan dan bukan dikecilkan fontnya: font yang
  menyusut sampai muat berhenti bisa dibaca orang.
- **UKURAN CETAK DIHITUNG DI PHP, TIDAK DISERAHKAN KE CSS.** Lebar modul cetak = lebar kotak ÷
  `Code128::moduleCount()`, dan di bawah `Code128::MIN_MODULE_MM` (0,25 mm, X-dimension minimum
  GS1) batangnya menyatu di kertas dan pemindai gagal **diam-diam**. Versi pertama F/LBL memakai
  `.stiker { width: 62mm }` + `max-width: 100%`: kotaknya tetap dan GAMBARNYA yang dikecilkan —
  2,8% untuk barcode pemasok 100 karakter, modul 0,055 mm, 0 dari 5 garis pindai terbaca pada
  raster 600 dpi dari PDF cetaknya sendiri, tanpa satu kata pun di lembarnya. Sekarang **kotaknya
  yang menyesuaikan** (kisi jatuh 3 → 2 → 1 stiker per baris) dan kode yang tetap tidak muat
  DITOLAK dengan kalimat yang menyebut panjangnya — aturan kejujuran yang sama dengan karakter di
  luar ASCII 32–126, karena alasannya sama persis. Opsi `module` hanya memilih satuan viewBox;
  `widthMm` yang menentukan milimeter di kertas. Uji yang mengukur PIKSEL atribut SVG buta terhadap
  seluruh kelas cacat ini: tiga mutasi (lebar stiker, `module`, tinggi batang) lolos hijau pada 71
  uji / 5.963 asersi sebelum uji milimeter ada.
- **Tinggi batang ≥ 15% lebar simbol** (dan ≥ 8 mm): simbol lebar yang pendek adalah sehelai garis
  yang tidak bisa dilacak pemindai.
- **`.lembar` adalah KONTRAK dengan `print.js`, bukan gaya.** `printWhenLoaded()` menunggu
  `tab.document.querySelector('.lembar')` sebelum memanggil `tab.print()` — `readyState` saja tidak
  cukup, karena `about:blank` sudah `complete` dan yang tercetak akan menjadi halaman penampung.
  Lembar bespoke yang tidak mewarisi `forms.layout` **harus membawa pembungkus itu sendiri**: tanpa
  ia lembarnya tergambar sempurna di tab barunya dan dialog cetak TIDAK PERNAH muncul; sesudah
  ~7 detik `PRINT_POLL_LIMIT` menyerah tanpa satu pun pesan, dan di gudang itu terbaca sebagai
  "tombol cetaknya rusak". Dipaku `PrintFormReachabilityTest` untuk SETIAP formulir bespoke.
- **Barcode ganda disebut DI LEMBARNYA**, sebelum stikernya menempel di rak. Layar pindai memang
  sudah mengatakannya — tetapi ia mengatakannya berbulan kemudian, ketika seseorang memindai stiker
  yang sudah tertempel, yaitu pada saat yang paling mahal. Peringatannya berlaku pada **KODE**-nya,
  bukan pada gambarnya, jadi ia berdiri **di luar** ketiga cabang lembar ini: stiker yang batangnya
  tidak dicetak justru yang kodenya diketik ulang orangnya. Yang memutuskan "ganda" adalah
  `Item::matchingScanCode()` — aturan layar Pindai, bukan salinan (§34).
- **KODE TULIS-TANGAN DIPENGGAL DENGAN ATURAN YANG SAMA** dengan teks di bawah batang
  (`Code128::wrapLabel`, publik sejak putaran kedua F-6), dan `font-size`-nya dicetak dari konstanta
  PHP yang sama dengan yang dipakai menghitung penggalannya. Cabang penolakan mewarisi stiker
  tulis-tangan dari cabang NON-ASCII, yang tidak pernah punya aturan pemenggalan karena kode
  non-ASCII yang pernah jatuh ke sana selalu pendek: diukur di Chromium (media=print, kotak
  56,5 mm), 63 karakter = **120,43 mm** dan 100 karakter = **191,10 mm**, mendorong `.lembar` ke
  252 dan **322 mm** di atas kertas yang lebar isinya 194 mm — dua stiker tetangga tertimpa dan
  ekor kodenya di luar halaman, di balik 62 uji hijau yang semuanya menguji KALIMAT.
- **DAN SATU JARING UNTUK SELURUH LEMBAR** (`overflow-wrap: anywhere` pada `body`): setiap teks di
  lembar ini datang dari data yang diketik orang — nama item (200 karakter), kode (40), barcode
  (100), satuan, nama perusahaan. Dengan nama 120 karakter DAN barcode 100 karakter, `.lembar`
  terukur **376,11 mm**; sesudah jaringnya, keempat kombinasi terukur 194,01 mm = lebar isi halaman,
  0 kotak meluap. Jaring itu **bukan** aturannya: di mana kode tulis-tangan patah tetap diputuskan
  PHP, supaya angkanya bisa dipaku uji dan barisnya bisa dibaca orang baris demi baris. Harness S33
  memaku keduanya sekaligus — `Range.getClientRects()` menghitung kotak baris yang BENAR-BENAR
  digambar, jadi font yang dicetak berbeda dari font yang dipakai menghitung penggalan terlihat di
  situ meski jaringnya menahan luapannya; lebar barisnya diukur pada SALINAN teksnya dalam probe
  `white-space: pre`, karena jaring yang sama membuat `scrollWidth` (dan kotak Range) tidak pernah
  melebihi kotaknya, berapa pun lebar hurufnya. **Kesetaraan "font yang dicetak = font yang dipakai
  menghitung" dipaku uji PHP** (`LabelBarcodePrintTest`), bukan harness saja: harness bukan bagian
  gerbang rilis, dan suntingan satu baris pada blade — interpolasi ukuran font menjadi angka tetap —
  meninggalkan seluruh gerbang phpunit hijau.

**F/LBL adalah formulir BESPOKE**, bukan entri `PrintableDocuments`: registri itu menggambar
dokumen bertanda tangan (pita empat pihak, blok identitas, tiga kolom tanda tangan), dan lembar
label adalah kisi stiker yang digunting. Yang dikodekan: `inv_items.barcode` bila kartunya punya,
`code`-nya sendiri bila tidak — dan lembarnya **menuliskan yang mana**. Kode yang memuat karakter
di luar ASCII 32–126 mencetak stiker **tanpa batang** beserta kalimatnya; tidak pernah gambar yang
salah, tidak pernah kosong tanpa keterangan.

**Blade: `@else` yang didahului huruf BUKAN direktif.** Blade mencocokkan dengan `\B@`, jadi
`…berbeda@else` lolos sebagai teks, cabang `@if` di atasnya menelan sisa berkas, dan seluruh lembar
gagal dengan "unexpected end of file, expecting elseif". Satu direktif per baris.

## 34. Pindai barcode (`views/pindai.js`, F-6)

**`BarcodeDetector` tidak ada di iOS Safari**, dan itu ponsel separuh lapangan. Keberadaannya
diperiksa lewat `typeof globalThis.BarcodeDetector` — referensi telanjang ke pengenal yang tidak
ada adalah `ReferenceError` yang menjatuhkan seluruh layar, termasuk isian manualnya, yaitu
satu-satunya jalan yang tersisa. **Jalur ketik adalah isian PERTAMA di layar**, bukan jalan pintas
darurat; kamera adalah tambahan di atasnya.

**Empat keadaan, empat kalimat**, karena keempatnya menuntut tindakan berbeda dari yang membacanya:
peramban tanpa `BarcodeDetector` (tidak ada yang bisa diperbaiki) · halaman bukan konteks aman
(kamera memang tidak akan pernah diminta) · izin **ditolak** (ada yang bisa dicabut kembali, di
setelan situs) · **tidak ada** kamera (bukan soal izin). Plus keadaan kelima yang bukan galat:
menyala dan belum menemukan apa pun. Satu kalimat untuk keempatnya mengirim orang gudang mencari
setelan izin di ponsel yang memang tidak punya kamera.

**URUTAN PEMERIKSAANNYA BAGIAN DARI ATURANNYA: `isSecureContext` LEBIH DULU.** `BarcodeDetector`
ber-`[SecureContext]`, jadi pada asal http ia `undefined` DAN `navigator.mediaDevices` ikut
undefined. Memeriksa detektornya lebih dulu membuat pemakai http di Chrome Android selalu jatuh ke
cabang pertama dan membaca "buka halaman ini dengan Chrome di Android" — peramban yang sedang ia
pakai. Konteks tidak aman adalah sebab yang lebih spesifik: ia menjelaskan ketiadaan keduanya.
Harness yang menyuntikkan `BarcodeDetector` palsu ke dalam halaman tidak aman menguji kombinasi
yang tidak pernah diproduksi platform mana pun, dan hijau untuk urutan yang salah.

**SATU PENDENGAR PER TOMBOL.** `ui.js` memasang `onClick:` lewat `addEventListener`; menambahkan
`node.onclick = …` di atasnya adalah pendengar KEDUA, bukan pengganti. Pada tombol kamera itu
berarti satu klik menjalankan `startCamera()` DAN `stopCamera()`: akuisisi kedua menimpa `stream`
sebelum yang pertama sempat dihentikan, dan trek yang benar-benar dilihat orangnya kehilangan
seluruh rujukannya tanpa pernah di-`stop()` — layar berkata "Kamera belum dinyalakan" sementara
lampu kameranya menyala terus. Tukar SATU variabel handler, jangan menumpuk pendengar.

**PENCOCOKANNYA TIDAK PEDULI BESAR-KECIL HURUF ASCII, TETAPI PEDULI AKSEN — DAN `UPPER()` SAJA
TIDAK CUKUP UNTUK ITU.** `UPPER()` di kedua sisi menutup selisih huruf besar-kecil ASCII (SQLite
peka huruf pada `=`; papan ketik iOS mengapitalkan huruf pertama secara bawaan dan jalur ketik
adalah satu-satunya jalur di iPhone). Ia **tidak** menetralkan collation: yang membandingkan
hasilnya tetap collation kolomnya, dan kolom itu `utf8mb4_unicode_ci`, sehingga di MySQL 8.0.46
`UPPER('café') = 'CAFE'` memulangkan **1**. Terukur dengan kartu `CAFÉ-2026` dan `CAFE-2026`:
SQLite memulangkan satu item, MySQL memulangkan **dua** dan saringan "Barcode ganda" menyebut
keduanya ganda — yaitu persis selisih yang `UPPER()` dipasang untuk menutup. Maka di MySQL
perbandingannya dipaksa `COLLATE utf8mb4_bin` (satu cabang driver, di dalam
`Item::scanKeyExpression()` saja): yang memutuskan "sama" adalah BYTE hasil `UPPER()`-nya. Pindai
adalah pembacaan mesin — `café` dan `cafe` adalah dua kode berbeda di setiap pemindai di dunia.
**Yang MASIH berbeda antara kedua mesin** dan sengaja dibiarkan: huruf besar-kecil DI LUAR ASCII
(`UPPER()` MySQL melipat `é`→`É`, SQLite tanpa ICU tidak). Selisihnya satu arah — MySQL
memulangkan kumpulan yang sama atau LEBIH BESAR — jadi produksi tidak pernah diam-diam melewatkan
tabrakan yang mesin uji lihat, dan kode Code 128 sendiri wajib ASCII 32–126. Isiannya juga membawa
`autocapitalize="none"` dan `autocorrect="off"` supaya masukannya tidak diubah sebelum kode ini
melihatnya. Permukaan saudaranya (`GET inventory/items?q=`) sudah menjawab begitu sejak lama.

**Standar target sentuh 42–46 px berlaku untuk SETIAP tombol di layar ini**, bukan hanya isian
ketiknya: `.btn` 34 px dan `.btn.sm` 28 px adalah kotak yang dicoba ditekan dua kali oleh orang
bersarung tangan. `.btn.lg` menyetel `width: 100%`, jadi tingginya disetel lewat satu helper —
bukan ditaburkan per tombol dan terlupa pada tombol berikutnya.

**`video.play()` tidak boleh di-`await`.** Janjinya baru selesai ketika trek mengirim bingkai
pertamanya; kamera yang menyala tanpa mengirim apa pun menggantung baris itu selamanya, pemindainya
tidak pernah mulai, dan kalimat di layar berhenti di "Meminta izin kamera…" — yang persis salah.

**Kamera dimatikan lewat `video.isConnected`**, karena router ini tidak punya kait teardown:
berpindah rute mencabut `<video>` dari dokumen dan putaran pemindai menghentikan treknya sendiri
≤250 ms kemudian. Tanpa itu lampu kamera tetap menyala dan di Android menahan aplikasi lain.
**Yang diuji adalah keadaan TREKNYA** (`MediaStreamTrack.readyState`, `stream.active`), bukan
tombolnya dan bukan kalimat statusnya: melumpuhkan `stopCamera()` sepenuhnya meninggalkan ketiga
skenario harness F-6 hijau semuanya, dan yang menemukannya adalah orang gudang yang baterainya
habis sebelum jam dua.

**`inv_items.barcode` NULLABLE dan TIDAK UNIK.** Pemindaian yang menemukan dua item **tidak pernah
memilihkan**: server memulangkan semuanya dengan `status: 'ambiguous'`, dan layar menampilkan
keduanya. Memilih diam-diam berarti stok masuk ke kartu barang lain tanpa satu pun pesan, dan
kekeliruan itu baru terlihat pada opname berikutnya. Pencocokannya **PERSIS**, bukan `like`:
pemindaian adalah pembacaan mesin, ia tepat atau ia gagal. `items/scan` didaftarkan **di atas**
`items/{item}` — di bawahnya `scan` tertangkap sebagai `{item}` dan setiap pemindaian menjawab 404.

**"KODE MANA YANG DIANGGAP SAMA" HIDUP DI SATU EKSPRESI: `Modules\Inventory\Models\Item`.**
`SCAN_KEY_COLUMNS` (`barcode`, `code`) adalah satu-satunya daftar kolom kunci, dan
`whereScanKeyEquals()` satu-satunya bentuk perbandingannya, di atas satu-satunya ekspresi nilai
(`scanKeyExpression()`: `UPPER()` di kedua sisi, plus `COLLATE utf8mb4_bin` di MySQL). Di atas
keduanya berdiri dua scope, untuk dua pertanyaan yang berbeda dengan aturan yang sama:

| scope | pertanyaannya | pemanggilnya |
|---|---|---|
| `matchingScanCode($kode)` | item mana yang dipulangkan pemindaian kode INI | `ItemScanController`, `FormPrintService::labelBarcode()` (peringatan ganda pada lembar F/LBL) |
| `sharingScanCode($ya)` | item mana yang salah satu kodenya juga dijawab item lain | saringan **"Barcode ganda"** pada `GET inventory/items` |

**Tiga salinan adalah bagaimana ia dulu bocor**, dan ketiganya menjawab berbeda: lembar F/LBL
memakai `where('barcode', …)` yang **peka huruf** di SQLite (jadi ia DIAM untuk `F6DUP001` vs
`f6dup001` yang layar Pindai sebut ganda) dengan `withTrashed()` (jadi ia MEMPERINGATKAN tentang
kartu yang sudah dibuang, yang tidak akan pernah dipulangkan pemindaian), dan saringan auditnya
`GROUP BY barcode HAVING COUNT(*) > 1` — tidak pernah membandingkan barcode dengan **KODE** item
lain. Untuk `item5.barcode = 'ITM-0002'` (kode item 2), pemindaiannya `ambiguous` dengan dua item,
kedua lembar labelnya memperingatkan, dan **saringan auditnya memulangkan nol baris**. Itu jawaban
yang paling mahal yang bisa diberikan permukaan yang dibuat UNTUK keputusan pemilik: ia membaca
"Tidak ada data", menyimpulkan katalognya bersih, dan menyetujui `UNIQUE` — migrasi yang lalu gagal
saat deploy. `ScanCodeParityTest` memaku KESETARAAN ketiganya, termasuk satu sapuan katalog yang
menuntut jawaban yang sama tentang SETIAP item.

**Item yang DIBUANG bukan kembaran**, di ketiga permukaan: pemindaian tidak memulangkannya, jadi
peringatan "memindai stiker ini akan memulangkan lebih dari satu item" yang datang dari kartu
terbuang menjanjikan sesuatu yang tidak akan terjadi. Yang boleh `withTrashed()` adalah SUBJEK
lembarnya (label item terbuang tetap bisa dicetak), bukan kembarannya.

**DAN SUBJEK YANG DIBUANG TIDAK MENGHITUNG DIRINYA SENDIRI.** Yang dijanjikan kalimat di lembar itu
adalah keadaan PEMINDAIAN kode yang ia cetak, jadi yang dihitung adalah berapa item yang
`matchingScanCode()` pulangkan — bukan berapa kartu lain yang memakai kodenya. Menghitung kembaran
dan menganggap subjeknya selalu ikut membuat lembar kartu terbuang memperingatkan "lebih dari satu
item" sementara layar Pindai berkata "Satu item cocok" untuk kode yang sama, pada jalur yang memang
sengaja didukung.

**Lengan "Tidak" pada saringannya `whereNotExists`,** bukan `NOT IN` atas daftar barcode:
`NULL NOT IN (…)` bernilai NULL, bukan true, dan setiap item yang belum punya barcode — sebagian
besar katalog — lenyap dari lengan itu tanpa satu pun tanda.

## 35. Sapuan dokumentasi saat sebuah layar berubah (F-6, putaran kedua)

Sebuah paket yang menambah tombol, mengganti nama tab, atau mengubah arti sebuah angka
**menjadikan kalimat yang sudah tertulis di `docs/` SALAH** — dan kalimat yang salah di panduan
peran lebih mahal daripada kalimat yang hilang: ia dibaca sebagai janji, satu kali, pada hari
pertama orang itu memakai sistemnya, dan sesudah itu ia tidak membukanya lagi.

**ATURANNYA: grep, lalu HITUNG — sebelum dan sesudah.**

```
grep -rn "<label layar yang berubah>" docs/ | wc -l      # sebelum: N penyebutan
# …perbaiki…
grep -rn "<label layar yang berubah>" docs/ | wc -l      # sesudah: N yang sama, semuanya dibaca
```

**Perintahnya `grep -rn … | wc -l`, BUKAN `grep -rc …`:** yang terakhir mencetak satu hitungan
PER BERKAS (`path:n`) dan tidak pernah memulangkan satu angka, jadi ia tidak bisa dipakai
mengklaim apa pun. Kalau yang dicari adalah "berapa berkas", perintahnya `grep -rl … | wc -l`.
Diukur hari ini (9 Sep 2026, sesudah putaran ketiga F-6):

```
grep -rn "Perlu dipesan ulang" docs/ | wc -l      # 28 baris
grep -rl "Perlu dipesan ulang" docs/ | wc -l      # 10 berkas
grep -rn "Usulkan PR dari kekurangan ini" docs/ | wc -l   # 6 baris di 4 berkas
```

(Dua dari 28 baris itu adalah contoh perintah di atas: bagian ini ikut terhitung oleh
perintahnya sendiri, dan itu bukan alasan untuk tidak menuliskan angkanya.)

Dan LABELNYA harus tetap satu baris di berkas Markdown: pembungkusan baris yang memotong
"**Perlu dipesan / ulang**" di tengah membuat grep di atas tidak menemukannya lagi — sapuan
berikutnya melewatkan berkas itu tanpa satu tanda pun. Terjadi pada putaran ketiga ini sendiri:
dua berkas peran keluar dari daftar berkas (**8**, bukan 10) hanya karena label yang dibungkus
ulang, dan yang menemukannya adalah angka grep-nya, bukan pembacaan ulang.

Angkanya masuk laporan paket. "Keempat penyebutan sudah diperbaiki" tanpa angka grep-nya adalah
klaim yang tidak bisa diperiksa siapa pun — dan F-6 membuktikan kenapa: sapuan putaran pertamanya
menyebut "keempat penyebutan tab" sementara penyebutan yang sebenarnya ada belasan. Yang KELIMA
berdiri di berkas yang sama dengan salah satu dari keempatnya (`docs/ONBOARDING/procurement.md`),
dan ia berkata kepada petugas pengadaan bahwa daftar itu "tanpa tombol PR di atasnya" — tombol
yang justru **hanya dia** yang dapat. Diukur di Chromium,
dua sesi, tab "Perlu dipesan ulang" pada `#/stock` dengan satu baris kekurangan:

| akun | tombol di tab | tombol di kartu dasbor |
|---|---|---|
| `procurement@nusantara.test` | **`Usulkan PR dari kekurangan ini`** | `Buka Stok` |
| `warehouse@nusantara.test` | (tidak ada — tanpa `prc.create`) | `Buka Stok` |

**DAN PERIKSA KEDUA PERMUKAAN YANG MEMAKAI NAMA YANG SAMA.** "Perlu dipesan ulang" adalah nama
kartu dasbor DAN nama tab Saldo Stok. Kalimat yang benar tentang kartunya ("daftar yang dibaca")
menjadi bohong begitu pembacanya mengira ia berbicara tentang tabnya. Sebutkan yang mana, dan
sebutkan di mana tombolnya berdiri.

## 36. Servis alat: DUA pemicu yang berdiri sendiri (F-7)

`ast_maintenances` membawa dua kolom jatuh tempo, dan keduanya **berdiri
sendiri-sendiri**: `next_due_date` (kalender, migrasi 000530, diawasi
`WatchedDeadlines` entri `maintenance_next_due`) dan `next_due_hour_meter`
(jam operasi, migrasi 000545, diawasi `WatchedThresholds` entri
`maintenance_hour_meter`). **Yang mana pun tercapai lebih dulu, servisnya jatuh
tempo.**

**PERMUKAAN YANG MENEGAKKANNYA, DIDAFTAR — bukan "tidak ada permukaan yang
boleh".** Kalimat universal itu tidak benar dan sudah tidak dipakai lagi
(verifikasi F-7): daftar perawatan punya dua kolom, formulirnya dua kotak,
kartu aset menaruh "Pemicu tanggal" di stat row yang sama dengan sisa jamnya
(dengan umur relatifnya, merah bila sudah lewat), kartu aset cetak menaruh
keduanya di satu sel ("14 Desember 2026 / 5.500 jam"), dan catatan setiap
baris registri jam menyebut tanggal jatuh temponya. **Layar Tenggat SENGAJA
hanya bicara tanggal** — ia adalah layar pengawas tenggat, entrinya
`maintenance_next_due` tidak membawa kolom nilai, dan sisi jam memang tidak
mengirim pemberitahuan pagi (keputusan pemilik 4). Pembaca yang perlu sisi
jam pergi ke **Ringkasan › Ambang & Batas**. Membawa target jam ke baris
Tenggat adalah keputusan pemilik yang terbuka (LAPORAN-PAKET-HM-F-7 §8),
bukan aturan yang sedang dilanggar.

**Judul kartu di halaman aset menyebut sisi yang dihakimi lencananya**
("Servis berikutnya menurut jam"): lencana itu dihitung dari keadaan JAM
saja, dan sebuah kartu berjudul "Servis berikutnya" yang berlencana hijau
"Aman" di atas tanggal servis yang lewat 86 hari adalah vonis yang menyamar
sebagai vonis atas keduanya.

**DUA DEFINISI, DITULIS SEKALI DI `Assets\Services\MaintenanceDueService`:**

1. **"Pembacaan hour-meter terakhir" = pembacaan TERTINGGI yang tercatat**,
   bukan yang terbaru menurut `(log_date, id)`. Meter tidak berjalan mundur:
   angka yang turun hanya punya dua sebab (meter diganti, salah ketik) dan tidak
   satu pun berarti mesinnya berjalan lebih sedikit. Dengan "yang terbaru", satu
   digit yang hilang mengubah 5.120 jam yang sudah lewat target 5.000 menjadi
   512 jam yang "masih 4.488 jam lagi" — alarm mati persis pada alat yang paling
   perlu dilihat. Penjaga monoton `EquipmentLogService` hanya berlaku DI DALAM
   satu mobilisasi; penggantian meter justru terjadi di antara dua mobilisasi.
   Pembacaan terbaru tetap dibawa (`latest_reading`), dan bila lebih rendah,
   layar **mengatakannya** dengan pita peringatan.
   **HARGANYA, karena ia nyata:** MAX melindungi dari salah ketik yang TURUN
   dengan cara yang membuat salah ketik yang NAIK — dan penggantian meter —
   tidak bisa dikoreksi siapa pun. Terukur lewat HTTP: 33.755 di atas 3.375,5
   pada alat bertarget 3.400 jam mengunci "Melampaui batas · lewat 30.355
   jam", dan keempat pintunya tertutup (pembacaan berikutnya yang lebih
   rendah 422 di mobilisasi yang sama; PUT dan DELETE ditolak; baris koreksi
   di mobilisasi BARU diterima dan tidak mengubah vonisnya). Kalimat
   penolakan register mengatakan hal ini sekarang; MEKANISME koreksinya
   (penanda koreksi pada `ast_equipment_logs`, atau puncak yang dihitung
   sejak tanggal kartu servis yang berlaku) adalah keputusan pemilik yang
   terbuka — LAPORAN-PAKET-HM-F-7 §8.
2. **"Target yang berlaku" = milik catatan perawatan TERBARU** (menurut
   `maintenance_date`, lalu `id`) — **baris yang sama** yang dibaca pemicu
   tanggal lewat `latest_per_group`. Kartu servis terbaru menggantikan rencana
   sebelumnya; "target terkecil yang belum terlampaui" akan menghidupkan lagi
   target yang sudah digantikan mekanik, dan membuat dua pemicu pada satu tabel
   membaca dua baris yang berbeda. Kartu terbaru yang lupa mengisi target jam
   menjadi `TANPA_BATAS`, bukan mewarisi target lama.

**TIDAK TERUKUR BUKAN NOL, DAN ADA TIGA SEBABNYA** — tiga kalimat, bukan satu
"—", karena jalan keluarnya berbeda: belum pernah dimobilisasi / mobilisasinya
belum punya satu log pun / lognya ada tetapi `hour_meter`-nya NULL pada semuanya
(kolom itu nullable karena mengisi solar tanpa mencatat jam adalah kejadian biasa
di lapangan). 0 jam adalah PEMBACAAN — mesin baru yang meterannya masih nol.

**SIAPA YANG DIAWASI.** Alat yang punya target jam ATAU punya pembacaan jam;
yang tidak punya keduanya bukan alat berjam (scaffolding, rak server) dan tidak
dibariskan sebagai "belum terukur" selamanya — aturan "masih urusan seseorang"
milik `WatchedDeadlines`. **Aset `disposed` dan yang dihapus lunak keluar dari
KEDUA pemicu**, dan `MaintenanceHourMeterDueTest` menanyai keduanya atas satu
aset yang sama, karena aturan yang benar di satu pemicu dan bocor di pemicu kedua
adalah cacat yang berulang di kampanye ini.

**AKIBAT PADA PEMICU TANGGAL.** `alarm_when_date_missing` milik
`maintenance_next_due` kini dipersempit `missing_scope`: kartu servis yang
menjadwalkan **dengan jam saja** bukan kartu tanpa jadwal, dan alarm yang
menghukum pemakaian yang benar adalah alarm yang diajari orang untuk diabaikan.
Penjaga kolom ada DI DALAM closure (pola `superseded_at`), bukan di `columns` —
yang di `columns` menggugurkan SELURUH entri saat kolomnya belum ada.

**`next_due_hour_meter` nullable, `gt:0` DAN `decimal:0,3` di request.** NULL
berarti "belum disetel"; nol adalah ANGKA (§24: batas tidak pernah disimpulkan
dari nilainya),
dan "servis pada jam ke-0" tidak berarti apa pun untuk mesin mana pun — menerima
0 akan melahirkan keadaan `TANPA_ANGGARAN` ("Tidak dianggarkan") di sisi jam,
tempat kalimat itu tidak punya arti. Presisinya `decimal(15,3)`, sama persis
dengan `ast_equipment_logs.hour_meter`: kedua sisi perbandingan
"pembacaan >= target" harus punya presisi yang sama — dan **`gt:0` sendirian
tidak cukup** (verifikasi F-7): ia menghakimi angka yang DIKIRIM, jadi 0,0004
lulus lalu tersimpan `0.000` dan melahirkan keadaan yang paragraf ini bilang
mustahil. `decimal:0,3` di kedua pintu tulis memvalidasi presisi yang
benar-benar disimpan, dan kotak formulirnya berlantai 0,001 — bukan 0, yang
berselisih dengan gerbang servernya sendiri.

## 37. Masa berlaku lampiran (`core_attachments.valid_until`, F-8)

Satu kolom `date` nullable pada tabel yang tumbuh paling cepat di aplikasi, dan **NULL
adalah keadaan NORMAL**: hampir setiap baris `core_attachments` adalah foto lapangan, nota
atau gambar kerja yang tidak punya — dan tidak akan pernah punya — masa berlaku.

**Empat keadaan, dan yang pertama bukan cabang dari tiga lainnya.** Dihitung SEKALI, di
server (`Modules\Core\Models\Attachment::validityState()`), lalu ikut setiap lampiran yang
diserialisasi sebagai blok `validity` (`state`, `days`, `lead_days`):

| Keadaan | Arti | Di kartu lampiran |
|---|---|---|
| `tanpa_masa_berlaku` | `valid_until` NULL — berkas biasa | teks polos, **bukan lencana** |
| `berlaku` | > 30 hari lagi | teks polos |
| `menipis` | ≤ 30 hari lagi, termasuk hari terakhirnya | lencana kuning |
| `kedaluwarsa` | tanggalnya sudah lewat | lencana merah |

`Attachment::VALID_UNTIL_LEAD_DAYS` = **30**, dan angka itu dibaca DUA permukaan: kartu
lampiran dan entri `WatchedDeadlines`. Angka kedua di salah satunya adalah cara termurah
membuat kartu berkata "masih berlaku" sementara kotak masuk pagi berkata "mendekati akhir
masa berlaku" tentang berkas yang sama — kontradiksi yang sudah dibayar dua kali (F-3
aktivitas CRM, F-7 servis alat). **Hari terakhirnya MASIH berlaku** (`valid_through_end`),
bacaan yang sama dengan `VendorDocument::isExpired` dan `Guarantee::isExpired`.

**Pintu tulisnya tiga, dan semuanya menuntut izin yang sama dengan mengubah lampirannya**
(`{prefix}.update` dokumen pemiliknya, diturunkan dari `AttachableDocuments`): `POST
core/attachments` (JSON base64), `POST core/attachments/upload` (multipart, kelas 25 MB),
dan `PATCH core/attachments/{id}` — yang HANYA menerima `valid_until`, dengan aturan
`present` supaya mengosongkan harus dikatakan dan bukan terjadi karena kuncinya lupa
dikirim. Nama, isi, sha256 dan geotag sebuah berkas adalah fakta saat ia diunggah.

**Pengawasnya DUA BELAS entri, satu per prefix izin**, dibangkitkan dari
`AttachableDocuments::byPrefix()` (`attachment_valid_until_<prefix>`). Sebuah temuan
`WatchedDeadlines` membawa SATU izin dan SATU judul; satu entri untuk seluruh tabel berarti
memilih satu izin untuk 40 jenis dokumen dari 12 modul, dan izin apa pun yang dipilih salah.

Dua bendera registri lahir di sini (kamus lengkapnya di kepala `WatchedDeadlines`):

- **`dateless_is_normal`** — mematikan baris `BLIND` milik `scan()` untuk entri ini. Tanpa
  itu, 40.000 foto lapangan tanpa tanggal dilaporkan sebagai "data yang hilang" setiap pagi.
  Ia juga membuang dua `COUNT(*)` **tanpa saringan tanggal** atas tabel terbesar aplikasi —
  satu-satunya kueri registri ini yang menyentuh seluruh tabel. Saling eksklusif dengan
  `alarm_when_date_missing`, dipaku uji.
- **`calendar_source`** — `false` mengeluarkan entri dari `CalendarEvents`. Bawaannya
  `true`; sejauh ini hanya lampiran memakainya (alasannya di `CalendarEvents::sources()`).

**Induk yang sudah tidak ada tidak berbunyi.** Kelas di luar registri tidak pernah masuk
cakupan; induk yang dihapus lunak atau permanen gugur lewat `EXISTS` per kelas atas kolom
`table` — **literal string baru di `AttachableDocuments`**, dipaku `AttachmentRegistryTest`
terhadap model aslinya. Penjaga `deleted_at` duduk DI DALAM closure (pola `missing_scope`
F-7), bukan di `columns`: `hr_attendances` memang tidak menghapus-lunak, dan mendaftarkannya
akan menggugurkan seluruh entri `hr`.

### Indeks — EXPLAIN kedua driver

Indeksnya **`(attachable_type, valid_until)`**, bukan `(valid_until)`. Diukur 9 Sep 2026
atas 40.000 baris tiruan (38.577 foto laporan harian, 59 baris bertanggal), kueri
`attachable_type IN (…3 kelas prj…) AND valid_until <rentang>`:

```
SQLite, TANPA ANALYZE — keadaan produksi; repo ini tidak pernah menjalankannya
  indeks (valid_until)                 SEARCH … USING INDEX …attachable_type_attachable_id…   14,689 ms
  indeks (valid_until, attachable_type) SEARCH … USING INDEX …attachable_type_attachable_id…  14,758 ms
  indeks (attachable_type, valid_until) SEARCH … USING COVERING INDEX …type_valid_until…       0,024 ms

MySQL 8 (erp_scratch, sesudah ANALYZE TABLE)
  tanpa indeks baru                     type=ALL   key=NULL          rows=39.844  Using where           44,530 ms
  indeks (valid_until)                  type=range key=valid_until   rows=34      Using index condition  0,276 ms
  indeks (attachable_type, valid_until) type=range key=type_valid    rows=35      Using where; Using index 0,382 ms
```

Perencana SQLite tanpa statistik **selalu** memilih indeks kesetaraan yang sudah ada dan
mengabaikan indeks satu kolom `valid_until` sepenuhnya. Hanya pasangan berawalan
`attachable_type` dipakai kedua driver tanpa ANALYZE, dan di keduanya ia COVERING.
Rencana kueri PENGAWAS YANG SEBENARNYA (bukan kueri contoh) dipaku
`AttachmentDeadlineWatchTest::test_the_registry_scope_itself_is_planned_through_the_pair_index`.
