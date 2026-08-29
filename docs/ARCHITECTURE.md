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

**Engineering (P1-ENG)** sits on the delivery side, between Estimation and Projects —
shop drawing register, drawing/material submittals with the four MK decision stamps,
transmittals, and the IPP (ijin pelaksanaan pekerjaan) whose submit gate refuses work
on an unapproved drawing or material. Its dependency arrows (arrow = *references,
one-way*):

```
   Engineering ──▶ Estimation   (material submittal item → inv/est master)
   Engineering ──▶ Projects     (project_id, WBS work-package task, EVM attribution)
   Engineering ──▶ Core         (numbering, attachments, locations, Approvable)
   Inventory   ──▶ Engineering  (bon menunjuk IPP → mewarisi wbs_task; konfirmasi bila
                                  proyek ber-IPP aktif tapi bon tak menunjuk satu pun)
```

**Quality (P1-QC)** sits on the delivery side too, on top of the field work — inspection
templates, filled inspections (QCI), non-conformance reports (NCR), and concrete-sample
strength tests. Its two gates: an OPEN NCR at a location refuses the submit of a
*later-stage* inspection there, and blocks BAST I on the project. Its dependency arrows
(arrow = *references, one-way*):

```
   Quality  ──▶ Projects     (project_id; open NCR is the BAST I prerequisite)
   Quality  ──▶ Engineering  (inspection ipp_id → eng_work_permits_ipp; shared location_id)
   Quality  ──▶ Core         (numbering QCI/NCR, attachments, locations, Approvable, import)
   Projects ┈┈▶ (qc_ncr)     (BastPrerequisiteService reads the qc_ncr TABLE behind
                              Schema::hasTable — NOT a code dependency: Projects must not
                              depend on Quality, so "open NCR" is a raw read by value)
```

Hierarchical site **locations** (`core_locations`: tower/floor/zone/axis/room) live in
**Core** — Engineering, Quality (P1-QC) and Projects all point at them — carrying a bare
`project_id` (no constraint, no relation back to Projects), because Core may depend on
no module.

**P3 — the owner opname, the BAPP and the subcontractor handover.** Measured volume per
BOQ item (`prj_progress_measurements`, OPN) becomes the project's value-weighted actual
percentage and the DPP of an owner claim; a per-zone BAPP (`prj_zone_certificates`) records
what an inspector saw; `scm_handovers` gives the subcontractor side the BAST it never had.
Two of its arrows are **reads by value** — the same device `BastPrerequisiteService` uses
on `qc_ncr`, and for the same reason:

```
   Projects ──▶ Estimation  (opname line → est_boq_items; the ceiling is BOQ qty)
   Projects ──▶ Core        (numbering OPN/BAPP, attachments, locations, Approvable,
                             external approval in TRANSITION mode — the MK signs the opname)
   Projects ┈┈▶ (crm_contract_change_orders.status)
                             MeasurementService reads the CCO status column BY VALUE to
                             decide which prj_contract_variations rows raise the ceiling.
                             One fact; pulling in a Crm service to fetch it would buy a
                             dependency for nothing.
   Finance  ┈┈▶ (prj_progress_measurements, prj_progress_measurement_items,
                 prj_zone_certificates, core_locations)
                             ArInvoiceService assembles an owner claim from an approved
                             opname and refuses to bill a zone whose latest BAPP says
                             waiting_repair (kriteria #6) — four tables read RAW, with
                             'waiting_repair' as a literal, because Finance must not depend
                             on Projects at runtime (TerminBillingService's rule, already
                             applied to prj_milestones). OwnerClaimTest drives both sides
                             in one test — the enum through ZoneCertificateService and the
                             literal through the billing gate — so the two cannot drift
                             apart without a red run.
   Subcontract ──▶ Finance   (HandoverService reads ApBill to tell a cancelled retention
                             release from a real one — the existing arrow, reused)
```

**P4 — the mandor labor contract (SP3), its opname and the kasbon offset.** A mandor is
a **vendor** (`prc_vendors.vendor_type = mandor`, asumsi #3), so the SP3
(`scm_labor_contracts`) and its per-period volume claims (`scm_labor_claims`) live in
Subcontract and bill through Finance like their subcon twins — with one new coupling in
each direction, both through documented seams:

```
   Subcontract ──▶ Procurement (SP3 vendor must be vendor_type=mandor; the P0-E
                             K3L/pakta narrowing now covers mandor too —
                             VendorQualificationService::sendsWorkersToSite)
   Subcontract ──▶ Estimation  (SP3 line may borrow description/qty/unit from
                             est_boq_items; the wage rate is always typed)
   Subcontract ┈┈▶ (fin_kasbons)
                             LaborClaimService READS the kasbon (status, project,
                             outstanding) to admit or refuse a wage deduction; it never
                             writes one. The write — the offset becoming an accounting
                             fact — happens in Finance when the AP bill is approved:
                             ApBillService credits 1-1370 and calls
                             KasbonService::offsetAgainstWageBill / releaseWageOffset,
                             the documented seam, inside the journal's own transaction.
   Finance  ──▶ Subcontract  (ApBillService::createFromLaborClaim builds the bill from
                             an approved scm_labor_claims row via
                             fin_ap_bills.labor_claim_id — a NEW column, not a reuse of
                             subcontract_claim_id: two FKs into two tables through one
                             column with no discriminator cannot be audited)
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

**Engineering (shop drawing → IPP → bon):**
Drawing register → drawing submittal (SDS) and material submittal (SMS) → the MK returns
the sheet stamped one of four decisions, typed in as **recorded fact** (not the Approvable
cycle) → transmittal records what left document control → **IPP** lists the work's
drawings, materials, tools; its **submit gate** refuses the permit while any drawing line
rides a submittal not approved/approved-as-noted or any material line one not approved
(the 422 names every blocker). An approved IPP carries a WBS work package; a **bon**
(material issue) pointing at it inherits that attribution, and a bon on an IPP-bearing
project that names no IPP triggers a confirmation, not a block.

**Quality (inspection → NCR → concrete test):**
An inspection fills a template checklist at a location; its overall pass/fail is DERIVED
from the ticked rows (any `nok` fails). Submitting runs the gate: an open NCR raised at an
earlier hold point at the same location refuses the submit (the 422 names every blocking
NCR). An NCR runs its own lifecycle (open → under_correction → verified → closed), not the
Approvable cycle; while open it also blocks BAST I on the project. A concrete sample's tests
store a `pass` **computed** against the grade's age-adjusted target (K-grade cube → cylinder
fc' via SNI 2847:2019 / PBI 1971), never typed.

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
