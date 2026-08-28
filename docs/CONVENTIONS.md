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
  → `documents`.
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
- Vendors: `VND-0001` PT Semen Distribusi Utama, `VND-0002` CV Baja Mandiri,
  `VND-0003` PT Elektrindo Supply (ICT distributor), `VND-0004` CV Karya Sipil Sejahtera
  (subcontractor, sipil), `VND-0005` PT Mekanika Prima (subcontractor, ME).
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
