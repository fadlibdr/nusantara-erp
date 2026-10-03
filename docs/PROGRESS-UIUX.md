# Progress — UI/UX rearchitecture (handoff `docs/design_handoff_nusantara_erp/`)

Integration branch `feat/ux-redesign`, one branch per package. Nothing here is merged to `main`; that is the owner's step.

**Base.** Local `main` at `492b9ca`, plus a `--no-ff` merge of `origin/main` (`cbd3e74`, the handoff upload, 3 Oct 2026). The merge was needed because local `main` has 45 commits that `origin/main` lacks, and `origin/main` has the one upload commit that local `main` lacks. The upload only adds files, so the merge was clean.

**Read this first: the handoff was written against a different main.** ERRATA says it was verified against `4301df9`. Local `main` is 45 commits past that, and those commits are an earlier UI campaign, UI-0 to UI-7, with reports in `docs/LAPORAN-PAKET-UI-*.md`. Several things the README and ERRATA treat as work still to do already exist. See § Drift between the handoff and main. It changes the scope of UX-C, UX-D and UX-E3, and it is why S3 and S4 are red before any change.

---

## UX-0 — Preflight

### UX-0.1 — Ledger rows 17–20 and RECAP supersession
- Commit: `3788603`
- Files: `docs/ROADMAP-HASHMICRO.md`, `docs/RECAP-UX-PROSES-2026-09.md`
- Acceptance: rows 17–20 present under row 16 in § 5, each marked ⏳ with the default in use; RECAP Phase 4 carries the supersession note for T4.1 and T4.2 → done.
- Deviations: none. Row text is Indonesian to match the ledger; the RECAP note is English to match that file.

### UX-0.2 — Baseline harness run
- Commit: `7c38c2a`
- Files: `docs/bukti-uji/results-ux-baseline.json`, `docs/bukti-uji/ux0-adapt-s3-s4.py`
- Acceptance: S1 S2 S3 S4 S5 S8 S10 S20 S21 S22 on a freshly seeded scratch SQLite (`migrate --seed --force`, `php -S 127.0.0.1:8000`). **Eight pass, S3 and S4 fail, exit 1.** Both failures are `waiting for locator(".modal") to be visible` after the Tambah click on the PO list.
- Deviations: the harness is not edited, because UX-0 is docs and measurements only. `ux0-adapt-s3-s4.py` writes page-targeting copies by selector substitution (`.modal` → `.form-page`, Simpan moves to `.page-head .actions`) for the fill-and-save phase only. Their numbers are in the table below and labelled as adapted. They are a one-off measurement, not a replacement for the real scenarios.

### UX-0.3 — Baseline screenshots
- Commit: `c39455b`
- Files: `docs/bukti-uji/ux-baseline/*.jpg` (28)
- Acceptance: landing, Tugas Saya, PO list, PO form, PO detail, Pengadaan module page and Lapangan, in light and dark, at 390 and 1440 px, from the running app. No page errors on any of them.
- Deviations: shot from the same scratch DB the harness had just used, so S22's `UJI-S22-*` rows show in Tugas Saya. Roles: admin (landing), direktur (Tugas Saya), procurement (PO and Pengadaan), site-manager (Lapangan). The 390 px landing is the launcher (`#/home`) and the 1440 px landing is `#/dashboard`, which is today's rule and the one D-2 replaces.

### UX-0.4 — This file, metrics and grep baselines
- Commit: the commit that adds this file.
- Files: `docs/PROGRESS-UIUX.md`
- Acceptance: metrics table and "Tugas Saya" counts below; `tests/Feature/Core` → green, 1,421 tests, 11,630 assertions, 11 skipped (the skips are the suite's own); `git diff main..feat/ux-redesign-ux0 -- public/app` is empty.
- Deviations: none.

---

## Metrics

Method for every row: the scenario named, on the fresh seed above, 1440 × 900, light theme unless stated. "Last recorded" is ERRATA §4, which quotes `RECAP-UX-PROSES-2026-09.md` of 5 Sep 2026.

| Metric | Last recorded | Bound | Baseline (UX-0) |
|---|---|---|---|
| Admin sidebar height (S5) | 0.7 viewport | ≤ 2.0 | **Content 840 px, 0.93 viewport.** The harness's raw `viewportsTall` reads 2.6 (`nav.scrollHeight` 2308 px), which its own comment says is the grid row's floor, not the content. S5 does not gate on either number. |
| Approval clicks per document (S2) | 2 | ≤ 2 | 4 clicks for two documents, so 2 each. S2 ok. |
| Create→submit PO, 2 lines (S3) | 12 clicks | ≤ 10 once the page form ships | **S3 as written: red, 3 clicks before it times out.** Page-adapted copy: **12 clicks**, saved to the detail page and submitted ("Diajukan"). |
| Fields lost on session expiry (S4) | 0 | 0 | **S4 as written: red, 1 click before it times out.** Page-adapted copy: 14 typed, 14 fields and 3 lines restored, so 0 lost. The restore opens as a dialog, not on the page. |
| English validation strings on 422 (S10) | 0 | 0 | 0 on 3 requests (PO, customer, AP bill), all Indonesian. |
| `--muted` on `--bg` (S8) | 5.23 | ≥ 4.5 | 5.23 light, 6.24 dark. 5.47 on `--surface-2`, 5.29 on the success badge. |
| Smallest font on the PO list (S8) | 11 px | ≥ 11 px | **10 px.** The only element under 11 px is the `Ctrl K` keycap in the top bar search. UI-4 chose that on purpose and S8's own floor is 10 px. See Open questions. |
| API calls on opening the landing page (S1) | 11 direktur, 6 warehouse | not higher than today for the same role | **12 direktur, 8 warehouse.** Those two numbers are the bound for UX-F2. |
| API calls, detail → approve → back (S2) | 14 | informational | 23 (`api_calls_detail_to_back`). Not comparable with 14: S2 now walks several documents. I did not trace the difference. |
| S20, S21, S22 | pass | pass | pass. S22 landing is `#/dashboard` at 1440 and `#/home` at 760 px and below. |

Package columns are added at the end of each package.

---

## "Tugas Saya" baseline (CONVENTIONS §35)

Counted as `grep -rIF "Tugas Saya" <path>` on `feat/ux-redesign-ux0`, tracked files. ERRATA's own counts are at `4301df9`.

| Where | Lines | Files | ERRATA at `4301df9` |
|---|---|---|---|
| `docs/`, excluding the handoff folder | 98 | 33 | 86 / 30 |
| `docs/design_handoff_nusantara_erp/` | 11 | 4 | not counted |
| `public/app/` | 11 | 6 | 11 |
| `tests/` | 5 | 2 | 5 |
| Harness (`docs/bukti-uji/harness-playwright.py`, already inside the `docs/` row) | 6 | 1 | 6 |
| `Modules/` | 5 | 5 | one user-facing string |

The docs rise from 86 to 98 because of the 45 commits since `4301df9` and the upload's own prompt file. In `Modules/`, only `SettingService.php:904` is user-facing help text. The other four are code comments.

---

## Drift between the handoff and main

Found by grep, one screenshot and reading `app.js`, `ui.js`, `sw.js` and commit `1067682`. I did not read every file, so treat "exists" as established and "missing" as established only for the names I searched.

| The handoff says | Main today |
|---|---|
| Forms: add route `#/f/<resource>`; pilot with PO, then 34 more, then 20 long forms (E-6, E3) | `route('f/*')` exists with a permission guard, crumbs, active-nav mapping and a router leave guard. It shipped in UI-4d for all 35 resources that declare `lines`. The 57 others stay dialogs. Callers with `prefill` or `endpoint` stay dialogs, as E-6 also says. A test scenario, S47, pins this. |
| Not yet there: Simpan & ajukan, the error summary, the sticky section nav | None of the three is in `form.js` (0 matches each). |
| Remove the hand-drawn icon table and alias to the sprite (E-2) | The table is gone (UI-3c). `PATHS` appears only in comments, and `LauncherWiringTest` no longer reads it. |
| `SHELL_VERSION` is `'15'` (E-1) | It is `'34'`. |
| New tokens `--attn`, `--attn-soft`, `--radius-lg/-sm` (C1) | `--attn` is absent. The three radius tokens exist with other values (8, 6 and 12 px against the handoff's 10, 8 and 14). `--primary` is `#1a56db`, not the handoff's `#2b4fc8`. So C.1 still has real work. |
| New primitives `pageHeader`, `docHeader`, `statusBadge`, `actionPanel`, `tabs`, `filterChip`, `overflowMenu`, `keyValueTile`, `stepper`, `drawer`, `errorSummary` (C3) | None is exported from `ui.js`. `menuButton`, `modal`, `emptyState`, `errorState` and `skeletonTable` are, as E-2 says. Filter chips exist inside `list.js` (UI-4a). |
| Sidebar pinned rows, link cap, rail, bottom bar (D) | No `.bottom-nav` or `rail-collapsed` in `app.css`. `NAV` has `home` and `tugas` but no `pekerjaan`. The sidebar already has module icons, a filter box and "Terakhir dibuka" (UI-3a), and the command palette is reworked (UI-3b). |
| Home, module page (F) | `home.js` and `module.js` already render `ModuleCounts` tiles. There is no `core/my-work` route. |
| `S3`, `S4` equal baseline (E-6 gate) | Both are red at baseline. See UX-0.2. |

---

## Open questions

**OQ-1 — Scope of UX-C, UX-D, UX-E3 against the earlier UI campaign.** The packages are specified against a `main` that predates UI-0 to UI-7. E3.1 and E3.2 (extract the builder, add the `f/` route with guard) are already shipped for every form with lines, so "modal behaviour byte-for-byte unchanged" has nothing to protect for those forms. What remains of E3 is smaller than written: Simpan & ajukan, the error summary, the section nav. I have not started any of it. Proposal: treat the current code as the baseline for every package, build only what the grep table above lists as missing, and say so in each package's Deviations line. This does not need an owner decision unless you want the earlier campaign's choices reversed. Also open: `origin/main` lacks the 45 commits, so pushing needs the owner to reconcile the divergence.

**OQ-2 — S3 and S4 target a dialog that no longer exists.** The E-6 gate "S3, S4 equal baseline" cannot be evaluated while both are red. Updating them to the page is allowed by ground rule 7 (the structure changed on purpose) and weakens nothing, but it is a harness change and UX-0 is measurements only. Proposal: do it as the first commit of UX-C, derived from `ux0-adapt-s3-s4.py`, before any gate that names them.

**OQ-3 — Smallest font bound.** ERRATA §4 says ≥ 11 px on the PO list. Main reads 10 px, from one keycap that UI-4 sets deliberately. The handoff's top bar keeps a `Ctrl K` keycap, so UX-D will hit this again. Proposal: gate on "no prose under 11 px, keycap exempt", matching S8's existing floor of 10 px. Needs your confirmation, because it relaxes a bound ERRATA states.

**OQ-4 — Which sidebar number is the bound.** S5 reports raw scroll height (2.6) and content height (0.93). ERRATA's 0.7 was a content figure. Proposal: gate UX-D on content height ≤ 2.0 viewports, which holds with wide margin.

---

## Not done in UX-0

- MySQL (`phpunit.mysql.xml`, `erp_dryrun`) was not run. UX-0 does not need it, and it stays on UX-F1 and UX-I.
- S3 and S4 have no real baseline numbers, only the adapted ones above.
- Screenshots were not diffed against the handoff's prototype shots. That belongs to UX-C.
