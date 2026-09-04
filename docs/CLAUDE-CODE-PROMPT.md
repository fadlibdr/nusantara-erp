# Claude Code Prompts — Nusantara ERP UX & Process Patches

Before starting, copy these into the repo so Claude Code can read them from the
working tree:

```
docs/ASESMEN-UX-2026-09.md
docs/HASIL-UJI-UX-2026-09.md
docs/ANALISIS-PROSES-BISNIS-2026-09.md
docs/RECAP-UX-PROSES-2026-09.md
docs/patches/ux-p0-and-process.patch
docs/bukti-uji/            (harness-playwright.py, results-*.json, screenshots)
```

Use **Prompt A** for a single long-running session, or **Prompts B1–B4** one
per session. Both assume Claude Code is started in the repo root on `main`.

---

## Prompt A — master prompt (all patches, end to end)

```
You are working in fadlibdr/nusantara-erp, a Laravel 12 + vanilla-ES-module ERP for an Indonesian
electronic-security/network contractor. Your job is to land every UX and process fix listed in
docs/RECAP-UX-PROSES-2026-09.md, in order, with tests and measured evidence. Read that file first,
then docs/HASIL-UJI-UX-2026-09.md (the measurements each task cites). Do not ask me to re-explain
anything that is in those two files.

## Ground rules (non-negotiable)

1. No framework, no build step. Front-end stays vanilla ES modules in public/app/: build UI with
   el() from ui.js, declare screens in schema.js (RESOURCES / NAV), modals via modal(), toasts via
   toast(). Never add React/Vue/bundlers/npm dependencies.
2. Modules/Core never imports feature modules. Inside Core use DB::table and string literals
   (see the header comment of Modules/Core/Support/WatchedDeadlines.php).
3. Every server behaviour change ships with a Feature test under tests/Feature/<Module>/,
   extending Tests\ErpTestCase. Copy the userWith() helper pattern from
   tests/Feature/Core/DeadlineWatchTest.php when you need users with permissions.
4. Comments explain WHY, and cite the measured reason (e.g. "diukur 2 Sep 2026: 13 klik").
   Match the existing comment voice in dashboard.js / SegregationOfDuties.php.
5. All user-facing strings are Bahasa Indonesia and follow the five copy rules in
   RECAP § Conventions: never state what you don't know; confirmations name the consequence;
   buttons are labelled with their verb; the document number is the subject of the sentence;
   field terms with acronyms only when printed on the form.
6. Never change an API response contract that existing tests or the SPA rely on without updating
   both sides in the same commit.
7. Do not touch production, .env files, or anything under deploy/ except deploy/sync-erp1.sh
   for task T1.1. Do not run the full PHPUnit suite in one go (it exceeds 20 minutes); run
   per-directory: vendor/bin/phpunit --no-progress tests/Feature/<Module>.
8. One task = one commit. Commit message format:
   "<area>: <what> — <measured why> (T<n.n>)"
   e.g. "form: petunjuk tanggal id-ID di bawah input date — mm/dd/yyyy di Chromium en-US (T2.2)"
9. If a task is ambiguous or would require a business decision (thresholds, delegation policy),
   stop that task, write the open question into docs/RECAP-UX-PROSES-2026-09.md under a
   "## Open questions" section, and continue with the next task.
10. Before finishing each task, run the acceptance check named in the RECAP entry (harness
    scenario, test, or grep) and paste the result in your report.

## Local run + harness

Server:   cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
DB:       sqlite at database/database.sqlite (php artisan migrate --seed --force on a fresh copy)
Harness:  python3 docs/bukti-uji/harness-playwright.py S10 S1 S2 S3 S4 S11 S8
          (Playwright + headless Chromium; writes results.json + screenshots next to itself)
Demo users: <role>@nusantara.test / password (admin, direktur, project-manager, site-manager,
estimator, procurement, warehouse, finance, hr, sales, teknisi)

## Sequence

Phase 0
  T0.1  git checkout -b ux/p0-measured && git am docs/patches/ux-p0-and-process.patch
        php artisan config:clear
        Run tests/Feature/Core, tests/Feature/Procurement, tests/Feature/Iam. All green except the
        one named in T0.2.
  T0.2  Fix tests/Feature/Finance/RevenueRecognitionTest::test_the_catch_up_lands_in_the_month_the_reversal_was_posted
        so it passes on any calendar date (freeze time before cancel()). Do not weaken the assertion.
  Gate: harness S10 S1 S2 S3 S4 S11 reproduce the "Sesudah" column of HASIL-UJI §1.

Phase 1 (repo-side parts only; I run the server-side commands myself)
  T1.1  Permission-drift check in deploy/sync-erp1.sh (expected count derived from
        PermissionSeeder::PREFIXES × ACTIONS + DIRECTOR_APPROVALS; exit non-zero on mismatch) and
        an artisan command erp:permission-check that prints the diff per role.
  T1.2  SQLite connection: journal_mode WAL + busy_timeout 5000 in config/database.php (sqlite
        driver options / a connection listener), documented in docs/DEPLOYMENT.md. Test that
        the PRAGMA is applied on connect.
  Gate: tests/Feature/Core green; deploy/sync-erp1.sh --check runs locally.

Phase 2 — UX, in this order: T2.1, T2.2, T2.8, T2.10, T2.7, T2.4, T2.3, T2.6, T2.5, T2.9, T2.11(minimal)
  Each entry in the RECAP names evidence, files, steps, acceptance. Implement exactly that scope.
  Gates after T2.3: harness S2 clicks per approval = 2. After T2.5: S5 admin viewportsTall ≤ 2.0.
  After T2.6: S2 action_bar length ≤ 4 on a PO with 2 house forms.

Phase 3 — process, in this order: T3.3, T3.1, T3.2, T3.5, T3.4, T3.8, T3.6, T3.7
  T3.9 and T3.10 only if I have added the threshold numbers / delegation policy under
  "## Open questions" in the RECAP — otherwise skip and note it.
  Gates: DeadlineWatchTest works/refused pairs for T3.1 and T3.2; harness S2
  explanation_under_title contains an approver name and date after T3.3;
  php artisan erp:deadline-watch --dry-run and erp:approval-watch --dry-run list the seeded cases.

Phase 4 — do NOT start. It is gated on user research (H1/H2). Leave it.

## Reporting

After every task, append a block to docs/PROGRESS-UX-PROSES.md:

### T<n.n> — <title>
- Commit: <sha>
- Files: <list>
- Acceptance: <harness scenario / test> → <result, with the number>
- Notes: <anything you deviated from, and why>

At the end of each phase, run the metrics table from RECAP § Verification with the harness and
paste it into the same file, filling the "After phase N" column.

Start now with T0.1.
```

---

## Prompt B1 — Phase 0 + 1 only

```
Read docs/RECAP-UX-PROSES-2026-09.md § Conventions, § Phase 0 and § Phase 1, and
docs/HASIL-UJI-UX-2026-09.md §1 and §4. Follow the ground rules in docs/CLAUDE-CODE-PROMPT.md
Prompt A (no framework, Core imports nothing, one task = one commit with the "<area>: <what> —
<measured why> (T<n.n>)" message format, Feature test per server change, Indonesian copy).

Do, in order:
1. T0.1 — git checkout -b ux/p0-measured; git am docs/patches/ux-p0-and-process.patch;
   php artisan config:clear; run vendor/bin/phpunit --no-progress on tests/Feature/Core,
   tests/Feature/Procurement, tests/Feature/Iam and report counts.
2. T0.2 — make RevenueRecognitionTest::test_the_catch_up_lands_in_the_month_the_reversal_was_posted
   date-independent without weakening it.
3. Start the local server and run docs/bukti-uji/harness-playwright.py S10 S1 S2 S3 S4 S11 S8;
   confirm the numbers match the "Sesudah" column of HASIL-UJI §1 and paste them.
4. T1.1 — permission-drift check (deploy/sync-erp1.sh --check + erp:permission-check command +
   test).
5. T1.2 — SQLite WAL + busy_timeout on connect, documented in docs/DEPLOYMENT.md, with a test
   asserting the PRAGMA values on the test connection.

Write docs/PROGRESS-UX-PROSES.md with one block per task (commit, files, acceptance result).
```

---

## Prompt B2 — Phase 2 (UX), one task per invocation

```
Implement task T2.<N> from docs/RECAP-UX-PROSES-2026-09.md.

Before writing code: read the RECAP entry, the files it names, and the relevant scenario in
docs/HASIL-UJI-UX-2026-09.md so the comment you write cites the measured reason. Follow the ground
rules in docs/CLAUDE-CODE-PROMPT.md Prompt A. Scope is exactly the entry's "Steps"; do not
refactor neighbouring code.

When done:
- run the acceptance check named in the entry (harness scenario or test) and paste the number;
- commit as "<area>: <what> — <measured why> (T2.<N>)";
- append the report block to docs/PROGRESS-UX-PROSES.md.

If the entry's acceptance cannot be met within its scope, stop, explain why in the report block,
and do not commit a partial change.
```

Run order: T2.1 → T2.2 → T2.8 → T2.10 → T2.7 → T2.4 → T2.3 → T2.6 → T2.5 → T2.9 → T2.11.

---

## Prompt B3 — Phase 3 (process), one task per invocation

```
Implement task T3.<N> from docs/RECAP-UX-PROSES-2026-09.md, following docs/CLAUDE-CODE-PROMPT.md
Prompt A ground rules and reading docs/ANALISIS-PROSES-BISNIS-2026-09.md §1–§3 for the process
context the task cites.

Specific guidance:
- WatchedDeadlines entries (T3.1, T3.2): mirror the po_expected entry (same keys, half-open date
  ranges, DB::table only), pin the status strings, and add the works/refused pair to
  tests/Feature/Core/DeadlineWatchTest.php.
- T3.3 (approval trail on show): build the controller list with
  grep -L "approvals" $(grep -l "Approvable" Modules/*/Models/*.php | sed 's#Models/\(.*\).php#Http/Controllers/\1Controller.php#')
  and patch every show() in ONE commit; each Resource gets 'approvals' => $this->whenLoaded(...)
  in the shape of Modules/Finance/Http/Resources/PaymentResource.php:90.
- T3.4 (maker-checker without trail): keep the documented submit-as-nobody path for
  RetentionService; add the owner-column fallback only when a submit row is absent AND the
  column exists on the table; write a submitted row from DocumentImportService and seeders.
- T3.6/T3.7 touch Crm and Finance; add migrations with rollback and update the relevant seeder.
- T3.9 / T3.10: only if "## Open questions" in the RECAP contains the numbers/policy. Otherwise
  skip and say so.

Finish with the acceptance check, the commit, and the PROGRESS block, exactly as in Prompt B2.
```

Run order: T3.3 → T3.1 → T3.2 → T3.5 → T3.4 → T3.8 → T3.6 → T3.7 → (T3.9, T3.10 if unblocked).

---

## Prompt B4 — end-of-phase verification

```
Run the verification pass for the phase just completed:
1. Start the local server and run docs/bukti-uji/harness-playwright.py S10 S1 S2 S3 S4 S5 S8 S11.
2. Fill the "After phase N" column of the metrics table in docs/RECAP-UX-PROSES-2026-09.md
   § Verification from results.json (copy results.json to docs/bukti-uji/results-phase-N.json).
3. Run vendor/bin/phpunit --no-progress for every tests/Feature/<Module> directory touched in this
   phase and paste the counts.
4. List any metric that missed its target and which task should close it.
Do not change code in this pass.
```

---

## What Fadli does outside Claude Code (from RECAP § Not for Claude Code)

- Run the permission re-seed on production (T1.1 server side), then confirm 86/86.
- Apply WAL/`busy_timeout`/php-fpm pool changes on the box; check `/up` under a small burst.
- Rotate the `demo` basic-auth password.
- Decide the approval value thresholds (T3.9) and delegation policy (T3.10); add them under
  "## Open questions" in the RECAP to unblock those tasks.
- Optionally revert PR/2026/III/0002 (HASIL-UJI §6.5).
- Run the research plan (ASESMEN-UX §6) before any Phase 4 work.
