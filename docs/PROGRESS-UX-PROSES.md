# Progress — UX & Process Backlog (RECAP-UX-PROSES-2026-09)

One block per task, appended as each task lands. Branch: `ux/p0-measured`
(from `main` 877dd41). Test runs are per directory
(`vendor/bin/phpunit --no-progress tests/Feature/<Module>`), never the full
suite. Dates are the run date; "today" in the T0.2 block is 4 Sep 2026.

---

## Phase 0

### T0.0 — Layout: `docs/bukti-uji/` and `docs/patches/`
- Commit: 8bf9e14
- Files: `docs/bukti-uji/harness-playwright.py`, `docs/bukti-uji/results-sebelum.json`,
  `docs/bukti-uji/results-sesudah.json`, `docs/patches/ux-p0-and-process.patch`,
  `docs/patches/ux-p0-measured.patch` (all `git mv` from flat `docs/`)
- Acceptance: `git apply --check docs/patches/ux-p0-and-process.patch` on 877dd41 → exit 0
  (patch touches no file changed by recent commits)
- Notes: the three "Add files via upload" commits (13fdcdc, 3bbe6b1, 877dd41) landed the
  evidence and patches flat in `docs/`; CLAUDE-CODE-PROMPT.md expects the two subdirectories,
  so this is pure housekeeping before T0.1. Not a RECAP task — numbered T0.0 so the commit
  format still parses. Observation for whoever runs the gate: the harness hardcodes
  `DB="/home/claude/nusantara-erp/database/database.sqlite"` and `OUT="/home/claude/uxtest"`
  (another sandbox); it must be pointed at the served scratch DB, not
  `database/database.sqlite` (live demo data).

### T0.1 — Apply `ux-p0-and-process.patch`
- Commit: 1e3a095 ("ux/p0: hasil uji terukur 2 Sep 2026 — …") + b321d90 ("proses:
  ApprovalQueue bersama …"), author preserved (`Claude (UX test bed) <claude@sandbox.local>`)
  via `git am`; `php artisan config:clear` run afterwards (no migrations in the patch).
- Files (21 unique, 22 touches across the two commits):
  - `Modules/Core/Console/Commands/ApprovalWatchCommand.php`
  - `Modules/Core/Http/Controllers/InboxController.php`
  - `Modules/Core/Providers/CoreServiceProvider.php`
  - `Modules/Core/Routes/api.php`
  - `Modules/Core/Services/SettingService.php`
  - `Modules/Core/Support/ApprovalQueue.php`
  - `Modules/Iam/Routes/api.php`
  - `config/erp.php`
  - `lang/id/validation.php`
  - `public/app/app.css`
  - `public/app/js/app.js`
  - `public/app/js/drafts.js`
  - `public/app/js/schema.js`
  - `public/app/js/ui.js`
  - `public/app/js/views/actions.js`
  - `public/app/js/views/dashboard.js`
  - `public/app/js/views/detail.js`
  - `public/app/js/views/form.js`
  - `public/app/js/views/list.js`
  - `public/app/js/views/tugas.js`
  - `tests/Feature/Core/ApprovalWatchTest.php`
- Acceptance: all three directories green on b321d90 —
  - `tests/Feature/Core` → OK (564 tests, 3435 assertions), 1 min 48 s
  - `tests/Feature/Procurement` → OK (163 tests, 643 assertions), 30 s
  - `tests/Feature/Iam` → OK (23 tests, 79 assertions), 13 s
- Notes: RECAP § Phase 0 says `git am ux-p0-and-process.patch` from `docs/`; after T0.0 the
  file lives at `docs/patches/ux-p0-and-process.patch` (the path CLAUDE-CODE-PROMPT.md uses).
  The patch's own tests (`tests/Feature/Core/ApprovalWatchTest.php`) passed first time — no
  fix-forward commit was needed.

### T0.2 — `RevenueRecognitionTest::test_the_catch_up_lands_in_the_month_the_reversal_was_posted` date-independent
- Commit: c263b48
- Files: `tests/Feature/Finance/RevenueRecognitionTest.php`
- Acceptance: `tests/Feature/Finance` (whole directory) → OK (818 tests, 3888 assertions),
  1 min 41 s. The test alone → OK (1 test, 7 assertions) under the real clock (4 Sep 2026) and
  under a wall clock pinned from a scratch PHPUnit `--bootstrap` to
  **2025-01-15**, **2026-12-31** and **2027-06-01** — OK (1 test, 7 assertions) each time.
  Before the fix, the same test under the real clock → "Failed asserting that 400000000.0
  matches expected 0" at line 752 (`billed_cumulative` for August). Contrast with the pre-fix
  copy (877dd41 version) under the same pinned bootstrap: clock **2027-06-01** → Errors: 1
  (`JournalService.php:377` `assertPeriodOpen` via `ArInvoiceService.php:559` — no 2027
  fiscal period is seeded, so the reversal has nowhere to land); clock **2026-08-20** →
  OK (1 test, 5 assertions). The old outcome depended on the runner's calendar twice over.
- Notes: root cause as HASIL-UJI §2.1 describes — `JournalService::reversalDate()` dates the
  reversal "today" because March is already measured, and the test then calculates August, so
  it only passed while the runner's calendar said August 2026. Fix: `$this->travelTo('2026-08-20')`
  as the first line of the test (before the March run and `cancel()`), plus one **added**
  assertion pinning the reversing journal to `2026-08-20`; the existing
  `travelTo('2026-09-05')` for the August run stays. No assertion was weakened (5 → 7:
  `assertSame` on the reversal date, and `cancellationDate()`'s own `assertNotNull`). The
  docblock carries the measured reason. The scratch bootstrap used for the proof lives outside
  the repo (session scratchpad) and is not committed.
