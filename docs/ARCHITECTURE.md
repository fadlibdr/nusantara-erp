# Architecture

## Style: modular monolith

One Laravel 12 application, one database, one deployment unit — but the business is split
into self-registering modules under `Modules/` with hard conventions (see
[CONVENTIONS.md](CONVENTIONS.md)). A module owns its tables (prefix), routes (prefix),
migrations, business services, and seed data. Modules are discovered from disk by
`bootstrap/providers.php`; adding or deleting a module directory is the whole
registration story.

Coupling rules:

- **Data**: cross-module columns carry no DB foreign-key constraint (indexed
  `unsignedBigInteger` only). Referential integrity across module boundaries is enforced
  in services. This keeps migration order free and makes a future extraction of a module
  into its own service mechanical rather than surgical.
- **Code**: cross-module Eloquent relations and service calls are allowed one-way along
  the dependency arrows below; where a module merely *feeds* another (e.g. Assets
  depreciation → Finance journal), it exposes data and the consumer pulls.

## Modules & dependency direction

```
                 ┌────────┐   ┌─────┐
                 │  Core  │   │ Iam │          (kernel: base classes, numbering,
                 └───▲────┘   └──▲──┘           approvals, auth, RBAC)
   every module ─────┘───────────┘
                                                sales side          delivery side
┌─────┐    won     ┌────────────┐  contract  ┌──────────┐
│ Crm ├───────────▶│ Estimation │───────────▶│ Projects │
└──┬──┘  quotation │ (RAB/AHSP) │  BOQ→WBS   └────┬─────┘
   │               └────────────┘                 │
   │ termin schedule                              │ site demand
   │                                              ▼
   │            ┌─────────────┐  PR→PO  ┌─────────────┐
   │            │ Procurement │◀────────│  Inventory  │  (gudang pusat + site,
   │            └──────┬──────┘   GRN   └──────┬──────┘   moving-average ledger)
   │                   │ vendors               │ material issue → project cost
   │                   ▼                       │
   │            ┌─────────────┐                │
   │            │ Subcontract │ SPK/opname/retensi/PPh final
   │            └──────┬──────┘                │
   ▼                   ▼                       ▼
┌──────────────────────────────────────────────────┐
│                     Finance                      │  COA · journals · AR termin+PPN ·
│  (single source of accounting truth)             │  AP+PPh · payments · project P&L
└──────────────────▲───────────────▲───────────────┘
                   │               │
        ┌──────────┴───┐   ┌───────┴─────┐   ┌─────────────┐
        │  HrPayroll   │   │   Assets    │   │ ServiceDesk │
        │ BPJS·PPh21   │   │ depreciation│   │ SLA·tickets │──▶ Inventory (parts)
        └──────────────┘   └─────────────┘   └─────────────┘
```

## Core document flows

**Sales → delivery (construction):**
1. Crm: lead → quotation (penawaran) → won → contract with **termin** schedule
   (DP / progress / BAST / retensi — validated to 100%).
2. Estimation: BOQ/RAB priced from **AHSP** analyses; approved BOQ spawns **RAP**
   (internal cost budget at target margin).
3. Projects: project created from contract; WBS generated from BOQ sections/items with
   value-based weights; laporan harian, weekly progress vs plan (**kurva-S**), milestones,
   **BAST** at handover, retention release after masa pemeliharaan.

**Procure → pay:**
PR (site or warehouse) → approval (two-level above Rp 100 jt) → PO (PPN only for PKP
vendors) → GRN posts stock at moving average → AP bill (3-way reference) → journal
(Dr Persediaan/Beban + PPN Masukan, Cr Hutang Usaha) → payment out.

**Subcontract:**
SPK (scope from BOQ items) → periodic **opname** claims: gross → retensi withheld →
PPN on full DPP → **PPh final jasa konstruksi** (PP 9/2022 rate by classification)
→ net payable → AP bill in Finance.

**Bill → cash (termin billing):**
Contract termin due → AR invoice: DPP, PPN Keluaran, optional retention withheld,
faktur pajak number, **terbilang** line → journal (Dr Piutang + Piutang Retensi,
Cr Pendapatan + PPN Keluaran) → payment received, aging tracked.

**Payroll:**
Attendance recap → payroll run: overtime (base/173), BPJS Kesehatan + JHT/JP/JKK/JKM
with statutory caps, **PPh 21 TER** monthly (PMK 168/2023) with December annual true-up
(Pasal 17 + PTKP), THR runs with tenure proration.

**After-sales (system integrator):**
Service contract with SLA → tickets (response/resolution due computed in business
hours) → field service reports with parts consumption → preventive-maintenance
schedules auto-generate visits (daily scheduler).

**Assets:**
Deployment (mobilisasi) to projects with internal daily rate → monthly straight-line
depreciation run → Finance journal; utilization reporting per project.

## Cross-cutting kernel services (Modules/Core)

- `DocumentNumberService` — race-safe yearly sequences, formats like `PO/2026/VII/0001`
  (roman month), configured per type in `config/erp.php`.
- `Approvable` trait — draft → submitted → approved/rejected with an audit trail in
  `core_approvals`.
- `Terbilang` — amounts to Indonesian words for kwitansi/invoices.
- Company profile (NPWP, NIB, PKP status) + settings store.

## Single legal entity — and where a KSO stands

One deployment is the ledger of exactly **one PT**, by design: the company profile
(`core_company`) is a one-row table, journals and documents carry no entity dimension, and
document numbering, COA, fiscal periods, and tax identity (NPWP, PKP status, faktur pajak)
each exist once. This is a conscious refusal, not a gap — see "Yang sengaja TIDAK
disarankan" in [ASSESSMENT-LANJUTAN.md](ASSESSMENT-LANJUTAN.md).

**KSO (kerja sama operasi).** A tender won through a KSO does not move this boundary. The
joint operation is its own accounting (and, once it invoices in its own name, tax) subject;
its books — JO ledger, partner current accounts, the KSO's own faktur pajak — are kept by
the KSO administration **outside** this system. Inside it, the KSO is an ordinary project
recording only the PT's **proportionate share**: a contract whose value is the PT's share
of the KSO contract, AR termin billed to the KSO (or to the owner, as the KSO agreement
directs), and only the PT's own costs. The rule of thumb: nothing in this database may
represent another legal entity's ledger — mixing the JO's transactions into the PT's books
misstates both.

**If a second PT ever arrives**, "add a company column" is not the change. It would mean:
an entity dimension on every journal, document, and numbering sequence (and inside every
uniqueness and period-close rule); per-entity COA, fiscal calendar, and close; per-entity
tax identity and exports (PPN, PPh, e-Bupot); RBAC scoped by entity; and intercompany +
elimination entries for any consolidated report — weeks of architecture work touching
every module. Until that day the honest shape is one deployment per PT, with consolidation
done outside the system.

## Tech decisions

| Decision | Why |
|---|---|
| No modules package (hand-rolled discovery) | Zero magic: ~15 lines in `bootstrap/providers.php`, standard Laravel everywhere else. |
| API-first (Sanctum tokens, JSON resources) | The ERP front-end (SPA/mobile) evolves independently; endpoints are the contract. |
| spatie/laravel-permission | De-facto standard RBAC; permissions generated per module prefix × action. |
| Single DB, decoupled schemas | Reporting stays trivial (joins allowed), extraction stays possible (constraints don't cross module lines). |
| Effective PPN stored (11%) with statutory note | PMK 131/2024 (12% × 11/12 DPP nilai lain) — one configurable number, documented. |
| Tax/BPJS parameters in `config/erp.php` | Statutory numbers change yearly; they are data, not code. |
