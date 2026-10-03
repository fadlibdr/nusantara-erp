# Errata & addendum — Nusantara ERP UI/UX handoff

Applies to: `README.md`, `UIUX-REDESIGN.md`, `UX Audit & Brief.dc.html`, `Before After.dc.html`, `Current UI.dc.html` and the prototype in this folder.
Verified against: `fadlibdr/nusantara-erp@main`, commit `4301df9` (14 Sep 2026), by reading the code. PHPUnit and the app were not run.
Date: 3 Oct 2026.

**Precedence.** Repo tests and `docs/CONVENTIONS.md` win over everything. This file wins over the rest of the handoff. The handoff wins over the prototype's incidental details.

**How to read.**
- `D-n ⏳` are owner decisions. The stated default is built until the owner answers, the same way `ROADMAP-HASHMICRO.md` §5 treats ⏳ rows. Add them to that ledger as rows 17–20.
- `A-n` correct evidence in the audit that is wrong or stale.
- `E-n` are binding corrections to the build spec, keyed to the README phase they replace.

---

## 1. Owner decisions (ledger rows 17–20)

### D-1 ⏳ Fonts (README C2) — amends ledger row 2

The spec as written cannot pass. `VendorManifestTest` caps `public/app/vendor` at 61,440 B gzip (`tests/Feature/Core/VendorManifestTest.php:50`); 21,206 B is used (`public/app/vendor/VENDOR.md`, rule 4). Measured on 3 Oct 2026, latin subset only:

| File | gzip -9 |
|---|---|
| Instrument Sans variable 400–700 | 30,120 B |
| IBM Plex Mono 400 | 14,731 B |
| IBM Plex Mono 500 | 14,911 B |
| IBM Plex Mono 600 | 15,643 B |
| **All four + existing vendor** | **96,611 B** (ceiling 61,440) |

It also contradicts `VENDOR.md` rule 2 ("hanya dua pustaka ini") and a recommendation the repo already rejected (`docs/ASESMEN-UX-2026-09.md:492`; open owner question at `:690`).

- **Default:** keep the system UI stack for `--font`. Vendor IBM Plex Mono 400 and 600 only, for `--mono` (document numbers, Rupiah, KPI values). That is 30,374 B, about 53.5 KB in total with the OFL licence, inside the existing ceiling.
- **Alternative:** add Instrument Sans as well. Requires raising `GZIP_CEILING_BYTES` to at least 104 KB and rewriting `VENDOR.md` rules 2 and 4.

Either way: one folder per family per version with `LICENSE`, a row per file in the `VENDOR.md` table with sha256, source tarball sha256 recorded like the two existing libraries, and `font-display: swap`.

### D-2 ⏳ Landing (README Phase D) — amends ledger row 3

"Dasbor di desktop, launcher di ponsel" is owner decision #3 (`public/app/js/app.js:1553–1587`). The handoff replaces it with `#/home` at every width without saying so.

- **Default:** accept `#/home` at every width. The original reason (no visible way out at ≤ 760 px) is removed by the bottom bar. `#/dashboard` stays one click away.
- Update in the same commit: the comment block above `landOnDefault()`, `LauncherWiringTest.php:61–83` (keep its assertion that `app.css` still folds the sidebar at `@media (max-width: 760px)`), and the harness `landing_boundary` check (`docs/bukti-uji/harness-playwright.py:4343`).

### D-3 ⏳ Scope of Pekerjaan Saya (README Phase F)

The handoff sources this screen from "existing list endpoints with `created_by=me`". That path does not exist:
- `prc_purchase_orders` has no creator column (`Modules/Procurement/Database/Migrations/2026_07_25_000830_create_prc_purchase_orders_table.php`). About 20 tables have `created_by`; most document tables do not.
- No list endpoint filters on it. The PO index accepts `q`, `status`, `vendor_id`, `project_id` (`PurchaseOrderController.php:35–44`).
- `core_audit_log` cannot fill the gap: `AuditService` observes `AuditedModels` (master data), not every document.

What does exist is the ownership rule `ApprovalQueue` already uses to keep a reader's own documents out of their inbox: the user on the latest `submitted` row in `core_approvals`, else the first of `OWNER_KEYS`, else `employee_id` (`Modules/Core/Support/ApprovalQueue.php:62–120`).

- **Default (v1):** two tabs, **Perlu diperbaiki** (my rejected documents) and **Menunggu orang lain** (my submitted documents), from one new endpoint specified in E-9. No "Draf saya" tab and no "approved, awaiting follow-up document" tab.
- **Later, if wanted:** "Draf saya" needs creator capture on the document tables that lack it. That is a migration package of its own, and it can only cover drafts created after it ships.

### D-4 ⏳ Research gate

`docs/RECAP-UX-PROSES-2026-09.md` Phase 4 says role home (T4.1) and full-page forms (T4.2) wait on user research H1/H2, and the existing Claude Code prompt says "Phase 4 — do NOT start". This redesign builds both.

- **Default:** proceed; the owner commissioned the redesign. Record in the RECAP that T4.1 and T4.2 are superseded by this work, and use the harness metrics in §4 below as the evidence in place of the research.

---

## 2. Corrections to the audit

| # | Audit claim | What the repo shows | Consequence |
|---|---|---|---|
| A-1 | Sidebar: admin 121 links, 4.9 viewports (Kritis) | That is the 2 Sep baseline quoted in a comment (`app.js:470–478`). T2.5 already collapses every group except Ringkasan and the active one, with Favorit and Terakhir dibuka on top. Measured after: 0.7 viewport (`RECAP-UX-PROSES-2026-09.md:248`). | Phase D keeps the T2.5 open/closed logic. Net-new work is the pinned rows, the link cap, the rail and the bottom bar. |
| A-2 | Detail page shows "Discount Amount", "Dpp", "Ppn Amount", "Required Levels", "Pr Bypass Reason", "Created By 5" (Kritis) | `detail.js` has a `LABELS` dictionary of about 585 Indonesian labels (`detail.js:164`). All six are covered (`detail.js:165, 167, 186, 215, 364`); `created_by` resolves to a name. `titleize()` is the fallback and logs a warning (`:463`). | The flat-hierarchy criticism stands. `Current UI.dc.html` is not faithful on this screen; take "before" screenshots from the real app. See E-7. |
| A-3 | 422 errors: no jump to the first error | Jump and focus exist (`form.js:965`, `:1013–1016`). Only the summary is missing. | E3 adds the summary; it does not replace the existing behaviour. |
| A-4 | Reject needs a new dialog with a required reason | The `reject` action already declares a required `note` field and opens the `promptFields` modal (PO actions in `schema.js`; `actions.js:48`). | Nothing to build. See E-7. |
| A-5 | 131 routes; 3,127 tests | `NAV` has 144 routed items in 14 groups plus 9 dividers. The suite is 5,303 tests (`docs/LAPORAN-PAKET-HM-F-5.md:340–341`). | Use these numbers in reports. |

---

## 3. Corrections to the build spec

### E-1 Tokens (README C1): six co-changes the spec omits

Changing `--bg`, `--surface` and `--primary` breaks `PwaManifestTest` unless these move in the same commit:
1. `public/app/manifest.webmanifest:11–12`: `background_color` = light `--bg`, `theme_color` = light `--primary` (`PwaManifestTest.php:157–158`).
2. `public/app/index.html:13–14`: both `theme-color` metas = `--surface` of each theme (`PwaManifestTest.php:141`). Dark changes to `#161b21`.
3. `public/app/favicon.svg` and the three PNGs in `public/app/icons/` carry `#1a56db`; regenerate with `docs/bukti-uji/buat-ikon-pwa.py`.
4. `--attn`, `--attn-soft` and the radius tokens go into every theme block, including **both** print blocks (`app.css:1364` and `:1546`).
5. The fixed heights in the quick sheet (inputs 38 px, buttons 36 px) are the `normal` density. `compact` and `comfortable` keep deriving from `--row-h` (CONVENTIONS §13).
6. If fonts are vendored: add `'woff2'` to `SHELL_EXTENSIONS` (`PwaServiceWorkerTest.php:55`) so the shell-list test guards them, add the files to `SHELL`, and bump `SHELL_VERSION` (`sw.js:69`, currently `'15'`).

Contrast of the proposed tokens was computed and passes: lowest pair `--muted` on `--surface-3` light, 4.67:1.

### E-2 Primitives (README C3): reuse what exists

- `overflowMenu` is built on `menuButton` (`ui.js:290`), not a second menu implementation.
- Dialogs keep using `modal()` and its dirty prompt (`ui.js:621`). `emptyState`, `errorState` and `skeletonTable` already exist.
- The mobile drawer already exists as CSS at ≤ 760 px (`app.css:1479`); extend it.
- Removing `PATHS` (`ui.js:56`) means aliasing its 19 names to sprite ids: `close→x`, `edit→pencil`, `trash→trash-2`, `back→arrow-left`, `refresh→refresh-cw`, `warn→triangle-alert`, `logout→log-out`, `print→printer`, `chevron→chevron-down`; the rest match by name. All exist in the sprite. Update `LauncherWiringTest.php:58`, which reads `PATHS`.

### E-3 Status vocabulary (README C4): replace the flat `STATUS_UI` map

The flat map keyed by raw value cannot work:
- `enums.js` holds 96 enums, and several reuse a word with the opposite meaning. `open` is green for service tickets and red for NCR, K3 incidents and defects (`enums.js:325, 344, 406`; rationale in `format.js:131–141`).
- The repo's tone names are `green`, `amber`, `red`, `blue` and `''`.
- The README contradicts itself: C1 says `--attn` is never used for status, and C4 gives `submitted` the tone `attn`.

Do this instead:
1. Extend the `opts()` tuple in `enums.js` with an optional icon: `[value, label, tone, icon]`. Per enum, as today.
2. `statusTone(value, enumName)` keeps its current precedence: the enum's own tone first, then the shared word map.
3. `statusBadge(status, enumName)` renders icon + word. `submitted` stays `amber`. `--attn` belongs to `actionPanel` and the home cards only.
4. Sentences have one source: refactor `statusStrip()` (`detail.js:653`) to return `{ tone, text, sub }` and render that through `actionPanel`. It already reads `record.approvals` and `required_levels` and writes "Budi a.n. Sari" for delegated approvals. Do not add a second sentence generator in `enums.js`.
5. List and Pekerjaan Saya rows may carry a short static hint per `(enum, value)`; anything naming a person or a level comes from server data.
6. Test: every value of every enum used by a `type: 'status'` column has an icon, so status is never colour-only.

### E-4 Shell (README Phase D)

- **Pinned rows come from `NAV`.** Beranda (`home`) and Tugas Saya (`tugas`, `perm: ANY_APPROVE`) are already entries in the Ringkasan group (`schema.js:6438`). Add `pekerjaan` there. The shell renders these three at the top and omits them from their group. Permission filtering stays in `visibleNav()`; no permission is hard-coded in the shell. This also keeps `SpaNav`, Ctrl+K and onboarding aware of the new screen.
- **Link cap.** The active route is always rendered, even past the cap. Dividers inside the visible slice are kept. Apply the cap only to groups with more than 10 routes (Penjualan 13, Pengadaan 14, Persediaan 11, Proyek 20, Keuangan 22).
- **Rail state** is per device: store it in `localStorage` beside `nusantara_erp_nav` (`app.js:80`). No change to `UserPreferences::keys()`.
- **Bottom bar.** The brief and the README list different items. Use: Beranda · Persetujuan *(if the reader holds any approve permission, else Pekerjaan Saya)* · Cari · Menu.
- **Counts.** `core/inbox` returns `meta.failed` as well as `meta.total` (`InboxController`). When `failed` is non-empty the badge must not show a bare number; follow the "daftar ini belum lengkap" treatment in `tugas.js:291–293`. Do not call `core/inbox` for a reader with no approve permission (existing rule).
- **Renaming "Tugas Saya" to "Persetujuan"** triggers the documentation sweep in CONVENTIONS §35. Baseline counts on `4301df9`: 86 lines in 30 files under `docs/`, 11 lines in `public/app`, 5 in `tests/`, 6 in the harness, and one user-facing help string (`Modules/Core/Services/SettingService.php:904`). Re-count after this folder is copied into `docs/` (its files mention the label too), and report the before and after counts.
- **Persetujuan keeps everything `tugas.js` does today:** the delegation banner, "Delegasi saya", and "Setujui terpilih" under the owner's batch cap (`tugas.js:1–27`). The handoff's screen description omits all three.

### E-5 List (README E1)

- Drop "Duplikat sebagai draf". No duplicate action exists in the SPA or the API, and the master brief (§9) forbids actions the backend does not support. Copying a PO would also copy `purchase_requisition_id` and `rfq_id`, which the server ties to quantities and awards.
- The ⋯ menu keeps the current `editableWhen` and `deletableWhen` gating.

### E-6 Form (README E3)

- **One engine, two containers.** Extract the builder from `openForm()` (`form.js:678`) so the modal and the page render the same controls, validation, drafts and 422 mapping.
- **Who gets the page.** Under the handoff rule 55 of 92 forms change container (35 with `lines`, 20 with more than 8 fields). `openForm` has 25 call sites in 11 files, and many pass `prefill`, `endpoint` or `onSaved` that a URL cannot carry. The page is used only from the list's Tambah and Ubah, from Buat ▾ and from deep links. Callers that pass `prefill`, `endpoint` or `onSaved` stay modal in this pass.
- **Roll out in three steps**, harness S3 and S4 after each: PO as pilot, then the other 34 forms with `lines`, then the 20 long forms.
- **The page route needs** what `d/*` already has: a permission guard, breadcrumbs, the active-nav mapping (`app.js:796`), and a leave guard. No router-level guard exists today, so reuse the modal's dirty prompt. Form routes are not recorded in Terakhir dibuka.
- **Route name.** The RECAP planned `#/e/<resource>/<id>` (T4.2). Use the handoff's `#/f/<resource>/new` and `#/f/<resource>/<id>` and note the supersession in the RECAP.
- **"Simpan & ajukan"** is create, then the existing `runAction(submit)` including `confirmResubmit`. If the server refuses the submit, go to `#/d/<resource>/<id>` and say so with the server's sentence: "`<kode>` tersimpan sebagai draf — belum diajukan: `<alasan server>`". Never repeat the create.
- **Error summary** is added above the form. The first-error focus and the toast rule for unmapped keys (`form.js:1052`) stay as they are.

### E-7 Detail (README E2)

- **Label precedence:** form field label, then `LABELS`, then the collapsed "Bidang lainnya". Keep `LABELS` and the `titleize()` warning. Without this, computed fields that no form declares (`dpp`, `ppn_amount`, `required_levels`, `approvals_given`) would be hidden under "Bidang lainnya".
- **Reject** uses the existing action `fields` and modal. No 8-character minimum: on most document types the server takes the note as optional (`PurchaseOrderController.php:187`; only payments validate it, `PaymentController.php:150`), so a length rule in the browser would be a business rule the API does not enforce.
- **`def.detail.next`** is a display-only link, shown only when the reader may view the target screen. It never claims the follow-up document is missing; the page does not know that.

### E-8 Module process page (README Phase F)

- Stage counts come from keys registered in `ModuleCounts` (CONVENTIONS §16). A stage without a registered key shows no number. A registered key that fails to load shows "—".
- Label the strip as the usual sequence, not a required one. `procurementMethod` has four paths (`pembelian_langsung`, `penunjukan_langsung`, `rfq`, `tender`).

### E-9 New endpoint for Pekerjaan Saya (replaces the `created_by=me` idea)

`GET core/my-work`, authenticated, no permission gate of its own.

- Lives in `Modules/Core/Support` beside `ApprovalQueue` and follows its pattern: iterate `ApprovableDocuments::all()`, query through the registered class, read `core_approvals` with `DB::table`. Add no new import of a feature module to Core.
- For each registered type the caller may view (`<prefix>.view`), load documents with status `submitted` or `rejected` and keep those the caller owns.
- **Ownership uses the same function as `ApprovalQueue`**, extracted so the two cannot drift: user on the latest `submitted` row, else first non-empty `OWNER_KEYS`, else `employee_id` matching the caller's.
- Row: the inbox row shape (`id`, `code`, `label`, `resource`, `title`, `amount`, `submitted_at`, `days_waiting`, `link`) plus `status`, `bucket` (`needs_fix` or `waiting`), and for rejected documents `decided_at`, `decided_by` (with "a.n." where delegated) and `note` from the latest `rejected` row.
- `meta`: `total`, `failed` (labels of types that threw), `as_of`. Same honesty rule as the inbox.
- Order: `needs_fix` first, oldest decision first; then `waiting`, oldest submission first.
- Tests in `tests/Feature/Core/MyWorkTest.php`: owner sees own submitted and rejected documents; does not see another user's; a document with no submission row falls back to `OWNER_KEYS`; a type that throws lands in `meta.failed`; a type the caller cannot view contributes no rows.

---

## 4. Gates that carry over from the existing backlog

These were last recorded on 5 Sep 2026 in `RECAP-UX-PROSES-2026-09.md` §Verification. Re-measure them before starting, then hold them: the redesign must not make any of them worse.

| Metric | Last recorded | Bound |
|---|---|---|
| Admin sidebar height (S5) | 0.7 viewport | ≤ 2.0 |
| Approval clicks per document (S2) | 2 | ≤ 2 |
| Create→submit PO, 2 lines (S3) | 12 clicks | ≤ 10 once the page form ships |
| Fields lost on session expiry (S4) | 0 | 0 |
| English validation strings on 422 (S10) | 0 | 0 |
| `--muted` on `--bg` (S8) | 5.23 | ≥ 4.5 |
| Smallest font on the PO list (S8) | 11 px | ≥ 11 px |
| API calls on opening the landing page (S1) | 11 direktur, 6 warehouse | not higher than today for the same role |
