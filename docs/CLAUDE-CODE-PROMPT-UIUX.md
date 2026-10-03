# Claude Code prompt — Nusantara ERP UI/UX rearchitecture

## Before starting

Put these in the working tree so Claude Code can read them:

```
docs/design_handoff_nusantara_erp/     the unzipped Claude Design bundle (README.md, UIUX-REDESIGN.md,
                                       *.dc.html, screenshots/)
docs/design_handoff_nusantara_erp/ERRATA.md
docs/CLAUDE-CODE-PROMPT-UIUX.md        this file
```

Start Claude Code in the repo root on `main` (verified against commit `4301df9`). Use **Prompt A** for the
whole job, or **Prompt B** to run or resume a single package.

Four owner decisions in ERRATA §1 (D-1 fonts, D-2 landing, D-3 Pekerjaan Saya scope, D-4 research gate)
carry defaults. If you want something other than a default, edit ERRATA.md before starting; the prompt
tells Claude Code to build whatever that file says.

---

## Prompt A — master prompt

```
You are working in fadlibdr/nusantara-erp: a Laravel 12 modular monolith with a vanilla-ES-module SPA
under public/app/ (no framework, no build step, no CDN). Your job is to implement the UI/UX
rearchitecture specified in docs/design_handoff_nusantara_erp/, package by package, with tests and
measured evidence.

## Read first, in this order
1. docs/design_handoff_nusantara_erp/ERRATA.md   — binding corrections and owner decisions
2. docs/design_handoff_nusantara_erp/README.md   — the build spec, phases C–I
3. docs/design_handoff_nusantara_erp/UIUX-REDESIGN.md
4. docs/FRONTEND.md, docs/CONVENTIONS.md §10–17, §21, §35
5. docs/CLAUDE-CODE-PROMPT.md Prompt A — the house ground rules this prompt extends

Precedence when they disagree: repo tests and CONVENTIONS > ERRATA > README > the prototype.
The .dc.html files are a visual reference. Never copy their runtime, markup or Google Fonts link into
the repo. "Current UI.dc.html" is not faithful on the document detail screen (ERRATA A-2); take
"before" screenshots from the running app.

## Ground rules (non-negotiable)
1. Front end stays vanilla ES modules: el() from ui.js, screens declared in schema.js, tokens in
   app.css. No React/Vue, no npm runtime, no bundler, no CDN. Anything new under public/app/vendor
   needs a VENDOR.md row and must keep VendorManifestTest green.
2. No change to RBAC, API contracts, calculations, tax, workflow or approval semantics. The only new
   endpoint is GET core/my-work (ERRATA E-9). Permission filtering stays in visibleNav() and
   session.can(); never hard-code a permission in the shell.
3. Do not add an action the backend does not support, and do not enforce a business rule in the
   browser that the API does not enforce (ERRATA E-5, E-7).
4. Honesty rules stay: "—" is not 0; a failed widget says it failed; an incomplete queue says it is
   incomplete; module accents are never used for status.
5. Dark mode, the three density profiles, print blocks and the PWA keep working. Every file added or
   changed under public/app goes into sw.js SHELL with a SHELL_VERSION bump.
6. Every server behaviour change ships with a Feature test extending Tests\ErpTestCase. Every new
   ui.js primitive gets a source-parsing test in tests/Feature/Core/UiPrimitivesTest (pattern:
   SidebarNavWiringTest): present, and no hex literal.
7. When a DOM-pinning test fails because the structure changed on purpose, update what it pins. Never
   loosen or delete an assertion to get green. If a test can only pass by weakening it, stop that
   task and write it under "## Open questions" in docs/PROGRESS-UIUX.md.
8. User-facing strings are Bahasa Indonesia and follow the copy rules in
   docs/RECAP-UX-PROSES-2026-09.md § Conventions. Comments explain WHY and cite the measurement.
9. When a label or screen name changes, do the CONVENTIONS §35 sweep and report the grep counts
   before and after.
10. One task = one commit: "<area>: <what> — <measured why> (UX-<pkg>.<n>)".
11. Do not touch production, .env files or deploy/. Do not run the full suite in one go while
    working; run per directory: vendor/bin/phpunit --no-progress tests/Feature/<Module>.
12. Owner decisions D-1…D-4: build the default written in ERRATA §1 as it stands when you start. Do
    not wait for an answer and do not pick a different option yourself.
13. A real business-rule ambiguity that the repo cannot settle: stop that task, record it under
    "## Open questions", continue with the next task.

## Branching
Integration branch: feat/ux-redesign, cut from main. One branch per package
(feat/ux-redesign-<pkg>) merged into the integration branch with --no-ff when its gate is green.
Never merge into main and never push to production: production deploys from main, and a half-merged
redesign must not be deployable. Merging to main is the owner's step.

## Local run and harness
Server:   cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
DB:       a scratch SQLite file, php artisan migrate --seed --force (never the live demo DB)
Harness:  ERP_BASE=http://127.0.0.1:8000 ERP_DB=<scratch.sqlite> UXTEST_OUT=<dir> \
          python3 docs/bukti-uji/harness-playwright.py <scenarios>
          It exits non-zero when a scenario fails. Treat that as a failed gate.
Users:    <role>@nusantara.test / password — admin, direktur, project-manager, site-manager,
          estimator, procurement, warehouse, finance, hr, sales, teknisi

## Packages, in order

UX-0  Preflight (docs and measurements only)
  - Add ledger rows 17–20 to docs/ROADMAP-HASHMICRO.md §5 from ERRATA D-1…D-4, marked ⏳ with the
    default in use. Note in docs/RECAP-UX-PROSES-2026-09.md that T4.1 and T4.2 are superseded.
  - Create docs/PROGRESS-UIUX.md.
  - Baseline: run harness S1 S2 S3 S4 S5 S8 S10 S20 S21 S22 on a fresh seed; save as
    docs/bukti-uji/results-ux-baseline.json. Copy the table in ERRATA §4 into PROGRESS-UIUX.md and
    add a "Baseline (UX-0)" column with these numbers.
  - Baseline grep counts for "Tugas Saya" (docs, public/app, tests, harness).
  - Screenshots of the real app, light and dark, 390 and 1440 px: landing, Tugas Saya, PO list,
    PO form, PO detail, Pengadaan module page, Lapangan.
  Gate: tests/Feature/Core green; nothing under public/app changed.

UX-C  Foundation — README Phase C with ERRATA D-1, E-1, E-2, E-3
  C.1 tokens in all theme and print blocks + the six co-changes of E-1, in ONE commit
  C.2 fonts per D-1
  C.3 ui.js primitives, reusing menuButton/modal/emptyState/errorState (E-2); icon() aliased to the sprite
  C.4 status vocabulary per E-3 (per-enum icon, statusTone precedence unchanged, statusStrip as the
      single sentence source)
  Gate: VendorManifestTest, PwaManifestTest, PwaServiceWorkerTest, LauncherWiringTest,
  SidebarNavWiringTest, ChartMigrationTest, ToastToneTest green; harness S8 S20 S21 green; the seven
  baseline screens re-shot in both themes with no unstyled or half-styled element.

UX-D  Shell — README Phase D with ERRATA D-2, E-4
  Pinned rows from NAV, link cap, icon rail, top bar with Buat ▾, bottom bar, landing rule,
  "Tugas Saya" → "Persetujuan" with the §35 sweep, typed search hits.
  Gate: S5 admin sidebar ≤ 2.0 viewports; S22 landing; S2 approval clicks ≤ 2; Persetujuan still
  shows the delegation banner, "Delegasi saya" and "Setujui terpilih"; a role without any approve
  permission makes no core/inbox call; 390 px: bottom bar does not cover the onboarding sheet or drawer.

UX-E1 List — README E1 with ERRATA E-5
  Gate: tests/Feature/Core green; S8; row actions respect editableWhen/deletableWhen; no
  "Duplikat" item anywhere.

UX-E2 Detail — README E2 with ERRATA E-3, E-7
  Gate: S2; on a PO detail, DPP/PPN/required levels render with their LABELS text and are not under
  "Bidang lainnya"; console shows no new titleize warning.

UX-E3 Form — README E3 with ERRATA E-6
  E3.1 extract the shared form builder; modal behaviour byte-for-byte unchanged (S3, S4 equal baseline)
  E3.2 page container + route f/<resource>/new|<id>, guard, crumbs, leave prompt; PO only
  E3.3 the other forms with lines
  E3.4 the long forms without lines
  Gate after each step: S3, S4, S10. After E3.2: create→submit PO ≤ 10 clicks; a refused submit lands
  on the draft's detail page with the server's sentence and no second create request.

UX-F1 Backend — GET core/my-work exactly as ERRATA E-9, with MyWorkTest
  Gate: tests/Feature/Core green on SQLite, and on MySQL (phpunit.mysql.xml) if a MySQL instance is
  available. If it is not, record "MySQL not run" and carry it to UX-I.

UX-F2 Home, Pekerjaan Saya, module process pages — README Phase F with ERRATA D-3, E-8
  Gate: landing-page API calls per role not higher than baseline; counts show "—" when unknown;
  S22 launcher counts still correct.

UX-F3 Dashboard widget shell and footSentence — README Phase F
  Gate: DashboardTileFailureTest unchanged and green; S20.

UX-G  Specialised screens — README Phase G, one screen per commit, no workflow change
  Order: lapangan, project, tugas, board, bankrecon, kaskecil, laporanbebas, reports.
  Gate per screen: its existing Feature tests and harness scenario, plus 390 px and dark screenshots.

UX-H  Accessibility and responsive pass — README Phase H
  Gate: 390/768/1024/1440 × light/dark × three densities on the seven baseline screens; keyboard
  path through palette, menus, tabs, dialogs; prefers-reduced-motion; print preview of PO detail and
  PO list with the new components hidden or stacked as the README lists.

UX-I  Regression
  - Full suite on SQLite and on MySQL erp_dryrun (the release gate; expect 5,303+ tests). If no
    MySQL instance is available, say so plainly: that run then remains the owner's gate before main.
  - Harness: every scenario run in UX-0, saved as docs/bukti-uji/results-ux-final.json.
  - The seven journeys of the master brief §22 with procurement@, warehouse@, finance@,
    site-manager@ at 390 px, sales@, hr@, direktur@: record navigation actions to reach the task.
  - Move UIUX-REDESIGN.md to docs/UIUX-REDESIGN.md, corrected per ERRATA, with a § Verification
    holding the numbers above and a § Known limitations.

## Reporting
After every task append to docs/PROGRESS-UIUX.md:

### UX-<pkg>.<n> — <title>
- Commit: <sha>
- Files: <list>
- Acceptance: <test or harness scenario> → <result, with the number>
- Deviations: <anything that differs from README/ERRATA, and why>

At the end of each package, add that package's column to the metrics table in PROGRESS-UIUX.md and
state plainly what was not done. Do not describe unfinished work as complete.

Start with UX-0.
```

---

## Prompt B — one package, or resume

```
Read docs/design_handoff_nusantara_erp/ERRATA.md, docs/CLAUDE-CODE-PROMPT-UIUX.md Prompt A (ground
rules, branching, local run, reporting) and docs/PROGRESS-UIUX.md. Check out feat/ux-redesign.

Do package UX-<pkg> only, exactly as Prompt A scopes it. If PROGRESS-UIUX.md shows it partly done,
continue from the first task without a commit. Run the package gate, paste the results into
PROGRESS-UIUX.md, merge the package branch into feat/ux-redesign if the gate is green, and stop.
```
