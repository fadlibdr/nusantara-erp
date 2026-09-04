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
  test fix-forward was needed. One **style** fix-forward was: CI runs `vendor/bin/pint --test`
  (`.github/workflows/ci.yml:68`) and three patch files failed it (`ApprovalWatchCommand.php`
  not_operator_with_successor_space; `Core/Routes/api.php` ordered_imports; `ApprovalQueue.php`
  7 fixers incl. braces/indentation). Commit 3925894 applies Pint to those three files only and
  moves the `ApprovalQueue::pending()` docblock prose above `@return` (Pint's phpdoc_align had
  pushed it ~70 columns right). After it: `pint --test` over every PHP file on the branch →
  passed; `tests/Feature/Core` → OK (564 tests, 3435 assertions) again. No behaviour change.

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

### Gate Phase 0 — harness
- Commit: 7ba7eb8 (T0.1 — harness reads BASE/DB/OUT from env) + the commit that carries this
  block and `docs/bukti-uji/results-phase-0.json` (T0.2 gate; sha in
  `git log --grep='hasil harness fase 0'`)
- Files: `docs/bukti-uji/harness-playwright.py` (header only: `ERP_BASE`, `ERP_DB`, `UXTEST_OUT`
  with the original `/home/claude` literals as defaults — proven by exec'ing the header with and
  without the env vars), `docs/bukti-uji/results-phase-0.json` (the gate run's `results.json`,
  verbatim; screenshots stay in the session scratchpad)
- Acceptance: `harness-playwright.py S10 S1 S2 S3 S4 S11 S8` → 7 scenarios `ok`, 0 `ERROR`, 61 s.
  **S3 12 klik**, `PO/2026/IX/0004`, `Diajukan`; **S2 5 klik** (baris → Setujui → Setujui →
  Buka → Kembali), toast `RAP/2026/0001 disetujui.`, strip `Diajukan · menunggu persetujuan.`;
  **S4** banner `Sesi Anda berakhir. Isian PO …`, modalVisible **false**, loginVisible **true**,
  Masuk `reachable`, 13 field + 3 baris dipulihkan; **S1** 4 / 4; **S10** all three 422 bodies
  identical to Sesudah; **S11** h1 `Tugas Saya`; **S8 5,23 / 5,47 / 5,29**. Table below.
- Notes:
  - **Tooling installed (all new on this box):** `apt-get install python3-venv python3-pip`
    (3.12.3-0ubuntu2.1 / 24.0+dfsg-1ubuntu1.3); `python3 -m venv /root/.venv-playwright`;
    `pip install playwright` → 1.62.0; `python -m playwright install --with-deps chromium` →
    Chromium headless shell 151.0.7922.34 (build 1234, 114.7 MiB) + ffmpeg 1011 + the apt libs
    `--with-deps` pulls, exit 0 first time. Runs as root.
  - **Environment, never the live DB:** `database/database.sqlite` untouched (mtime still
    31 Aug). Scratch DB `<scratchpad>/ux/ux.sqlite` built with
    `DB_DATABASE=<scratch> php artisan migrate:fresh --seed --force` after `config:clear`
    (a cached config would ignore the env var); served with
    `cd public && DB_DATABASE=<scratch> APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    harness run with `ERP_DB=<scratch> UXTEST_OUT=<scratch>/out`. S4 deletes tokens in
    `ERP_DB` directly, so it must be the file php -S serves.
  - **Precondition replayed, and why:** the Sesudah column was measured on a DB that had
    already absorbed the Sebelum run — `results-sebelum.json` S2 approved `PR/2026/III/0002`
    and S3 created + submitted `PO/2026/IX/0003`; Sesudah S1 accordingly lists
    `purchase-orders: 1` and no PR, and Sesudah S3 yields `PO/2026/IX/0004`. A fresh seed can
    only yield 0003, so before the gate the two Sebelum actions were replayed on the fresh
    scratch seed: `POST procurement/purchase-requisitions/2/approve` as direktur (HTTP 200) and
    one harness `S3` pass (12 klik, `PO/2026/IX/0003`, `Diajukan`; its results.json discarded).
    Nothing else was written. A first pass on the bare seed (no replay, not kept) gave the
    same clicks / booleans / strings / contrast with `PO/2026/IX/0003` and the PR in the queue.
  - **Field-by-field diff vs `results-sesudah.json`** (timing keys, pixel heights,
    `tokens_revoked`, request samples excluded): 17 differing leaves, all four causes are
    environment, none is code —
    1. *Queue tie-break.* `ApprovalQueue` sorts by the `submitted` row's `created_at`, falling
       back to `updated_at` (`ApprovalQueue.php:107,131`); RAP and CTI have no submit row and
       the seed wrote them at :02 and SPK's row at :03, so CTI sorts before SPK here (Sesudah:
       SPK before CTI — its seed landed inside one second, leaving registry order). This is
       why S1 rows[1]/[2] swap, why "Berikutnya" opens `CTI/2026/VIII/0002` instead of
       `SPK/2026/III/0002`, and why S2 counts **14** requests instead of 16: a leave-request
       detail loads 3 requests (`hr/leave-requests/2`, `hr/employees`, attachments) where a
       subcontract detail loads 5 (`subcontracts/2`, `retention`, `advance`,
       `progress-claims`, `vendors`) — same 9 requests before that point in both samples.
    2. *Toast lifetime.* `toast_on_422` here is `items.0.qty: Kuantitas minimal 0.001.` alone;
       Sesudah also listed `Periksa isian yang ditandai.` — that is the **client-side** toast
       from step 1 (`form.js:880`), which lives 5200 ms (`ui.js:123`). Proven standalone:
       present right after the empty Simpan, gone 6 s later. Sesudah's fill phase was fast
       enough (create_ms 7572) for it to still be on screen at the 422 capture; here it was
       not (11 727 ms). The 422 toast itself is identical.
    3. *`results-sesudah.json` is a merge of several harness invocations*, not one sequential
       run: the harness merges the previous `results.json`, and S5/S6/S7/S9 carry `_ms`
       values byte-identical to `results-sebelum.json` (31265 / 5939 / 9822 / 3630). Its S11
       snapshot still lists `RAP/2026/0001` as pending although its S2 had approved it —
       impossible in the sequential order `S10 S1 S2 S3 S4 S11 S8`, where S11 sees 4 rows
       (CTI, SPK, PO/0003, PO/0004) and `Semua jenis (4)`.
    4. *`Diajukan oleh Sistem` on the leave request* needs a `submitted` `core_approvals`
       row with no user and no date for `CTI/2026/VIII/0002` (`detail.js:538-553`).
       `HrPayrollDatabaseSeeder::seedLeaveRequests()` writes the status only (unchanged since
       the initial commit), and a fresh seed has zero LeaveRequest rows in `core_approvals`,
       so the strip reads `Diajukan · menunggu persetujuan.` — the same code path, same second
       sentence. Whatever wrote that row in the 2 Sep sandbox is not in the repo; not
       reconstructed.
  - Re-run in one line (server already up): `ERP_DB=<scratch>/ux.sqlite UXTEST_OUT=<scratch>/out /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S10 S1 S2 S3 S4 S11 S8`.
    The php server was stopped after the run (`pkill -f 'php -S 127.0.0.1:8000'`).

| Ukuran | Sebelum (2 Sep) | Sesudah (2 Sep) | Diukur sekarang (4 Sep) |
|---|---|---|---|
| S10 · PO 422 `vendor_id` | `The vendor id field is required.` | `Vendor wajib diisi.` | `Vendor wajib diisi.` |
| S10 · PO 422 `items.0.qty` | `The items.0.qty field must be at least 0.001.` | `Kuantitas minimal 0.001.` | `Kuantitas minimal 0.001.` |
| S10 · pelanggan 422 `name` | `The name field is required.` | `Nama wajib diisi.` | `Nama wajib diisi.` |
| S1 · judul kartu | `Menunggu persetujuan Anda (3)` | `Menunggu persetujuan Anda (4)` | `Menunggu persetujuan Anda (4)` |
| S1 · baris kartu / dokumen `submitted` di server | 3 / 4 | 4 / 4 | 4 / 4 |
| S1 · lebar kartu (px) | 371.328125 | 565 | 565 |
| S2 · klik (baris → Setujui → Setujui → Buka → Kembali) | 4 | 5 | 5 |
| S2 · toast pertama | `Setujui berhasil.` | `RAP/2026/0001 disetujui.` | `RAP/2026/0001 disetujui.` |
| S2 · strip di bawah judul (baris 1) | `Informasi` | `Diajukan · menunggu persetujuan.` | `Diajukan · menunggu persetujuan.` |
| S2 · Berikutnya membuka | — | `SPK/2026/III/0002` | `CTI/2026/VIII/0002` |
| S2 · permintaan API detail → kembali | 28 | 16 | 14 |
| S3 · klik buat → ajukan PO 2 baris | 13 | 12 | 12 |
| S3 · nomor PO | `PO/2026/IX/0003` | `PO/2026/IX/0004` | `PO/2026/IX/0004` |
| S3 · mendarat di | `#/r/procurement/purchase-orders` | `#/d/procurement/purchase-orders/4` | `#/d/procurement/purchase-orders/4` |
| S3 · galat 422 di sel | `The items.0.qty field must be at least 0.001.` | `Kuantitas minimal 0.001.` | `Kuantitas minimal 0.001.` |
| S3 · toast saat 422 | `Periksa isian yang ditandai. · The items.0.qty field must be at least 0.001.` | `Periksa isian yang ditandai. · items.0.qty: Kuantitas minimal 0.001.` | `items.0.qty: Kuantitas minimal 0.001.` |
| S3 · toast setelah Ajukan | `Ajukan berhasil.` | `PO/2026/IX/0004 diajukan · menunggu persetujuan.` | `PO/2026/IX/0004 diajukan · menunggu persetujuan.` |
| S3 · status setelah Ajukan | `Diajukan` | `Diajukan` | `Diajukan` |
| S4 · field terisi sebelum sesi berakhir | 13 | 13 | 13 |
| S4 · modal masih terbuka | true | false | false |
| S4 · halaman masuk tampil | true | true | true |
| S4 · tombol Masuk | `blocked by overlay` | `reachable` | `reachable` |
| S4 · banner | `Sesi Anda berakhir. Silakan masuk kembali.` | `Sesi Anda berakhir. Isian PO yang sedang Anda buat tersimpan di peramban ini — masuk kembali untuk memulihkannya.` | `Sesi Anda berakhir. Isian PO yang sedang Anda buat tersimpan di peramban ini — masuk kembali untuk memulihkannya.` |
| S4 · tawaran pemulihan setelah masuk | false | true | true |
| S4 · field / baris dipulihkan | — | 13 / 3 | 13 / 3 |
| S11 · h1 | — | `Tugas Saya` | `Tugas Saya` |
| S11 · baris | — | 5: RAP/2026/0001, SPK/2026/III/0002, CTI/2026/VIII/0002, PO/2026/IX/0003, PO/2026/IX/0004 | 4: CTI/2026/VIII/0002, SPK/2026/III/0002, PO/2026/IX/0003, PO/2026/IX/0004 |
| S11 · tombol detail cuti | — | `Kembali · Cetak halaman · Setujui · Tolak` | `Kembali · Cetak halaman · Setujui · Tolak` |
| S11 · strip status cuti (baris 1) | — | `Diajukan oleh Sistem · menunggu persetujuan.` | `Diajukan · menunggu persetujuan.` |
| S8 · `--muted` | `#6b7684` | `#5e6874` | `#5e6874` |
| S8 · kontras `--muted` / `--bg` | 4.26 | 5.23 | 5.23 |
| S8 · kontras `--muted` / `--surface-2` | 4.46 | 5.47 | 5.47 |
| S8 · kontras badge sukses | 4.42 | 5.29 | 5.29 |
| S8 · font terkecil (px) | 10 | 10 | 10 |


---

## Phase 1

### T1.1 — Permission-drift check: `erp:permission-check` + `deploy/sync-erp1.sh --check`
- Commit: 4754faf
- Files: `Modules/Iam/Console/Commands/PermissionCheckCommand.php` (new),
  `Modules/Iam/Providers/IamServiceProvider.php` (registers it, the `$this->commands([...])`
  pattern Core uses), `Modules/Iam/Database/Seeders/PermissionSeeder.php` (new
  `public static expected()`; `run()` now iterates it), `Modules/Iam/Database/Seeders/RoleSeeder.php`
  (new `public static intended()` role→permission map; `run()` now iterates it — same file,
  same comments, moved into the map), `deploy/sync-erp1.sh` (`--check` mode + post-deploy gate),
  `tests/Feature/Iam/PermissionCheckTest.php` (new, 9 tests)
- Acceptance:
  - `bash deploy/sync-erp1.sh --check` locally (checkout DB, read-only) → exit **0**:
    ```
    Izin: 86 diharapkan (14 awalan × 6 aksi + 2 persetujuan direktur), 86 di basis data.
    +-----------------+------------+----------+--------+-------+---------+
    | Peran           | Diharapkan | Dipegang | Kurang | Lebih | Keadaan |
    +-----------------+------------+----------+--------+-------+---------+
    | admin           | 86         | 86       | 0      | 0     | sesuai  |
    | direktur        | 30         | 30       | 0      | 0     | sesuai  |
    | project-manager | 24         | 24       | 0      | 0     | sesuai  |
    | site-manager    | 11         | 11       | 0      | 0     | sesuai  |
    | estimator       | 10         | 10       | 0      | 0     | sesuai  |
    | procurement     | 8          | 8        | 0      | 0     | sesuai  |
    | warehouse       | 5          | 5        | 0      | 0     | sesuai  |
    | finance         | 11         | 11       | 0      | 0     | sesuai  |
    | finance-manager | 5          | 5        | 0      | 0     | sesuai  |
    | hr              | 6          | 6        | 0      | 0     | sesuai  |
    | sales           | 7          | 7        | 0      | 0     | sesuai  |
    | teknisi         | 5          | 5        | 0      | 0     | sesuai  |
    +-----------------+------------+----------+--------+-------+---------+
    Tidak ada penyimpangan izin: 86 izin dan 12 peran sesuai seeder.
    ```
    `bash deploy/sync-erp1.sh --bogus` → `usage: deploy/sync-erp1.sh [--check]`, exit **2**,
    before any deploy step. The deploy path itself was **not** run.
  - The 86 is derived, never typed: `count(PREFIXES)=14 × count(ACTIONS)=6 + count(DIRECTOR_APPROVALS)=2`
    (`PermissionSeeder::expected()`); the test pins that arithmetic and that `run()` mints
    exactly that list. On the drifted fixture (one permission deleted) the same header reads
    `86 diharapkan … 85 di basis data`, `Kurang di basis data (1): eng.view`, and six roles
    (`admin, direktur, project-manager, site-manager, estimator, procurement`) each get a
    `<peran> kurang: eng.view` line, exit **1**. Strip `fin.post` from `finance` → exit **1**,
    `PENYIMPANGAN IZIN: 0 izin kurang, 0 izin tidak dikenal, 1 peran menyimpang (finance)` +
    `finance kurang: fin.post`.
  - `tests/Feature/Iam` (whole directory) → OK (**32 tests, 146 assertions**), 15 s
    (23 + 9 new; `TeknisiInventoryPostingPermissionTest`'s five-permission pin and
    `SegregationOfDutiesRoleTest` still hold against the refactored `RoleSeeder`).
  - `tests/Feature/Core` → OK (**564 tests, 3435 assertions**), 1 min 47 s.
  - `vendor/bin/pint --test` on the five PHP files → passed.
- Notes:
  - **Local DB is already clean** (86/86, every role count equals the seeder's), so the
    production 74/86 (HASIL-UJI §6.2 P-1) could only be reproduced in the test fixture, not
    against a real database here. The server-side re-seed on erp1 remains the owner's step
    (RECAP § Not for Claude Code); after it, `cd /var/www/erp1.pi2.co.id && sudo -u www-data php artisan erp:permission-check`
    is the confirmation — **not** `--check`, which reads the checkout's database.
  - **Per-role diff needed the seeder's intent without re-seeding**, so `RoleSeeder` now
    exposes `public static intended()` and `run()` iterates the same map (the two cannot
    drift). One semantic nuance, documented in the docblock: `admin` is now spelled out as
    `PermissionSeeder::expected()` rather than "every row in the permissions table". Identical
    on a healthy database; on a drifted one a row the seeder never minted is reported as
    `Tidak dikenal seeder` instead of being silently absorbed by admin on re-seed. Nothing in
    the application mints permissions outside `PermissionSeeder` (the roles API only assigns
    existing names; migrations 000220–000242 all mint canonical names), so no existing
    installation changes shape.
  - **Roles the seeder does not know** (created on Sistem › Peran & Hak Akses — the petty-cash
    custodian case in `PermissionSeeder`'s NAMED SEAM comment) are listed as
    `Peran di luar seeder, tidak diperiksa (n): …` and are **not** drift: `RoleSeeder` never
    touches them, so there is no intent to compare against. Tested.
  - **Gate placement in the deploy path: last, after the smoke test, not right after
    `migrate`.** A failure immediately after migrate would abort before `config:cache` /
    `route:cache` / the php-fpm reload, leaving new code with a cleared config cache and a
    stale opcache — a worse state than a fully deployed site whose roles need a re-seed. The
    deploy still exits **1** on drift (`set -euo pipefail` intact; the check is an explicit
    `if !` so the message is printed before the exit), with the re-seed hint the command
    prints plus the exact re-check command. The RECAP wording "compare `Permission::count()`"
    is exceeded on purpose: a count check would pass a database with one wrong permission
    swapped for another.
  - `--json` was cheap (the same report array) and is tested; `drift`, `missing`, `extra`,
    `roles.<name>.{missing,extra,exists}`, `drifted_roles`, `unmanaged_roles`.
  - The command's re-seed hint prints `Modules\\Iam\\…` with doubled backslashes on purpose —
    the shell-escaped form RECAP T1.1 uses, pasteable into bash — and the test asserts that
    form.
  - `docs/DEPLOYMENT.md` deliberately untouched (T1.2 owns it in this run; a later docs pass
    may add one line about `--check`). No SPA/API contract touched. The PROGRESS block is a
    separate docs commit, as in Phase 0, because it must carry the task commit's sha.

### T1.2 — SQLite WAL + `busy_timeout` on connect: the proof and `DEPLOYMENT.md`
- Commit: 610537d
- Files: `tests/Feature/Core/SqlitePragmaTest.php` (new, 3 tests / 10 assertions),
  `docs/DEPLOYMENT.md` (new § 9 "SQLite: WAL, busy_timeout, synchronous (bare-metal erp1)",
  9.1–9.4; one pointer paragraph in the § 5.1 erp1 note; one troubleshooting row in § 8).
  `config/database.php` untouched.
- Acceptance:
  - `vendor/bin/phpunit --no-progress tests/Feature/Core/SqlitePragmaTest.php` → OK
    (**3 tests, 10 assertions**): a file-backed probe connected through Laravel's
    `SQLiteConnector` reads back `busy_timeout` **5000**, `journal_mode` **wal**,
    `synchronous` **1** (NORMAL); the suite's `:memory:` connection reads back 5000 / 1 /
    **memory**; a row committed through the probe is absent from a `cp` of the main file and
    present in a `VACUUM INTO` snapshot, with `-wal`/`-shm` present beside the file.
  - Red first, both ways: (a) probe built from a config copy without the three keys →
    **2 failures** ("Failed asserting that 60000 is identical to 5000"; `-wal` file does not
    exist), the `:memory:` test still green because the default connection kept its keys;
    (b) `DB_BUSY_TIMEOUT=null DB_JOURNAL_MODE=null DB_SYNCHRONOUS=null` in the environment
    (Laravel's `env()` maps the string `null` to PHP null; the connector then sends nothing)
    → **3 failures**.
  - `tests/Feature/Core` → OK (**567 tests, 3445 assertions**), 1 min 42 s
    (564 / 3435 before + 3 / 10).
  - `vendor/bin/pint --test tests/Feature/Core/SqlitePragmaTest.php` → passed.
  - The § 9.2 blockquote diffed against `config/database.php:46-64` after prefix
    normalisation → identical (19 lines).
- Notes:
  - **The configuration predated the task.** `busy_timeout 5000` / `journal_mode WAL` /
    `synchronous NORMAL` have been in `config/database.php` since the initial commit 3b933f1
    (22 Aug 2026), WHY comment included, and Laravel 12's `SQLiteConnector`
    (`vendor/laravel/framework/src/Illuminate/Database/Connectors/SQLiteConnector.php:111-145`)
    sends each as a PRAGMA on every connect. What was missing was the proof (no test read them
    back) and the documentation (`DEPLOYMENT.md` had no mention of WAL or `busy_timeout`).
    RECAP T1.2's "verify/set" resolved to "verify"; nothing in the config changed.
  - **Why a file probe.** phpunit.xml pins `DB_DATABASE=:memory:`, and SQLite answers
    `memory` to `pragma journal_mode = WAL` on an in-memory database — a `journal_mode`
    assertion on the test connection would pass vacuously or fail for the wrong reason. The
    test copies `config('database.connections.sqlite')` into a second connection
    (`pragma_probe`) pointing at a `tempnam()` file, connects via `DB::connection()`, and
    purges + unlinks (`-wal`, `-shm` too) in `tearDown`. The lane spec said "under the
    scratchpad"; a committed test cannot hard-code a session path, so it uses
    `sys_get_temp_dir()` (honours `TMPDIR`) and every run here set
    `TMPDIR=<scratchpad>/t12/tmp`. No `pragma_probe_*` file was left behind after any run
    (the `erpimp_*` / `test_*` / `xlsx_export_*` files seen there are other Core tests'
    `tempnam` litter — DocumentImport / MasterDataImport / FormXlsxExport — not this task).
  - **Measured refinement, documented rather than re-arguing the comment:** with PHP 8.3.6 /
    SQLite 3.45.1 a PDO handle that receives no pragma reports `busy_timeout = 60000`
    (pdo_sqlite's own `PDO::ATTR_TIMEOUT` default), `synchronous = 2`, and
    `journal_mode = delete` on a fresh file — so the comment's "fails IMMEDIATELY" is not what
    happens on this stack; 5000 is a ceiling on how long a php-fpm worker waits, which is the
    more relevant reading for the 503s in HASIL-UJI §6.4 (workers waiting 60 s exhaust
    `pm.max_children` faster than workers waiting 5 s). Written into § 9.2; the comment in
    `config/database.php` left as is (not one of this lane's files; a later pass may want to
    soften that one sentence). `journal_mode` persists in the file, the other two are per
    connection — also documented, because it decides what the `sqlite3` CLI can verify.
  - **Verify-on-the-box commands** in § 9.3 were not run on erp1 (no production access in
    this lane). The `php artisan tinker --execute` one-liner was verified locally against the
    scratch copy of the seed (`5000 wal 1`; a bare `new PDO` on the same file: `60000 wal 2`),
    with `env HOME=/tmp` from the memory note on psysh under www-data. § 9.3 insists on
    `sudo -u www-data` for every command because a root-created `-shm` is unwritable for
    php-fpm (SQLite documentation, not measured here).
  - **Finding for a T1.1 addendum (`deploy/`, not touched in this lane):**
    `deploy/sync-erp1.sh`'s `rsync -a --delete` excludes `database/database.sqlite` but not
    `database.sqlite-wal` / `-shm`. Dry run over the same exclude set on scratch directories,
    4 Sep 2026: a live `-wal`/`-shm` on the site is **deleted**
    ("deleting database/database.sqlite-wal"), and a stray `-wal` in the source checkout is
    **pushed** to the site; `--exclude='database/database.sqlite-*'` closes both (dry run
    lists nothing). Documented as the rule in § 9.4 (3) with the interim precaution (deploy
    only when idle, one file on each side); the one-line script change is the T1.1 owner's
    call under rule 7. The first half is a race (php-fpm closes the file per request and the
    last close removes the side files, so it needs a request in flight during the rsync); the
    second half (a local `-wal` from a killed `php -S` landing on prod) needs no race.
  - `docs/DEPLOYMENT.md` section numbers are referenced from `PANDUAN-ADMINISTRATOR.md`
    (§3, §4, §5.1, §5.2, §7.1), so the new material is § 9 at the end; nothing renumbered.
    `PANDUAN-ADMINISTRATOR.md:131` still says "437 baris" for DEPLOYMENT.md (now 644) —
    cosmetic, left for a docs pass.
  - No `## Open questions` needed: no business decision arose. RECAP § Phase 0 still says
    `git am ux-p0-and-process.patch` from `docs/` (file is `docs/patches/…`) — unchanged,
    still pending for a docs pass.

### Gate Phase 1
- `tests/Feature/Core` → OK (**567 tests, 3445 assertions**).
- `bash deploy/sync-erp1.sh --check` locally (checkout DB, read-only) → exit **0**:
  "Izin: 86 diharapkan (14 awalan × 6 aksi + 2 persetujuan direktur), 86 di basis data." —
  12 roles all `sesuai`; "Tidak ada penyimpangan izin: 86 izin dan 12 peran sesuai seeder."
  `database/database.sqlite` only read; no `-wal`/`-shm` left beside it after the command's
  connection closed.
- Production untouched; the deploy path was never executed.

---

## Phase 2

### T2.1 — Toast 422 tanpa kunci mentah bila field sudah dilukis
- Commit: 1773843 (placeholder `(this commit)` replaced in the T2.2 commit — one task, one commit)
- Files: `public/app/js/views/form.js` (`paintErrors` in `openForm`), `docs/PROGRESS-UX-PROSES.md`
- Acceptance: harness `S3 › toast_on_422` → **`["Periksa isian yang ditandai."]`** — no `items.N.`
  prefix (gate Phase 0 measured `["items.0.qty: Kuantitas minimal 0.001."]`). Same run:
  `server_errors_rendered` still `["Kuantitas minimal 0.001."]` (the cell stays painted),
  **12 klik**, `saved` true, landing `#/d/procurement/purchase-orders/3`, `PO/2026/IX/0003`,
  status `Diajukan`, 0 `ERROR`, 15 990 ms. `toast_after_save` reads
  `Periksa isian yang ditandai. · PO dibuat.` (the 422 toast's 8 s lifetime, as before).
- Notes:
  - Scope exactly the entry's Steps: keys that `applyLineError` or `setFieldError` painted are
    collected; only the rest reach `toastError`; none left → `toast('Periksa isian yang
    ditandai.')`, the sentence the client-side wajib-isi path already uses (`form.js:~880`).
    Partial case: that sentence becomes the title and the unmapped keys stay listed with their
    key (the key is the only pointer the operator has). Nothing painted → the old path is
    unchanged (Laravel's own message + details), because "ditandai" would then be untrue.
  - A header key whose field is currently hidden by `visibleWhen` counts as **unmapped**:
    `setFieldError` would paint into a `display:none` wrapper, which is not a mark the operator
    can see. `hiddenKeys` is the set the payload builder already uses (`form.js:~892`).
  - `ui.js` (`toastError`) untouched: it already accepts a `{ message, details }` shape (the
    `details` getter on `ApiError` is duck-typed there) and its title dedupe still applies.
  - No server behaviour changed → no Feature test (rule 3 is for server changes); the harness
    scenario named by the entry is the acceptance. No node on the box, so the harness run is
    also the syntax check (a broken module would not open the form).
  - Environment: fresh scratch seed `<scratchpad>/ux/t2.sqlite`
    (`DB_DATABASE=<scratch>/ux/t2.sqlite php artisan migrate:fresh --seed --force`; config not
    cached, `bootstrap/cache` has no `config.php`), served with
    `cd public && DB_DATABASE=<scratch>/ux/t2.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
    harness `ERP_DB=<scratch>/ux/t2.sqlite UXTEST_OUT=<scratch>/ux/out-t2 /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S3`.
    Bare seed, no Sebelum replay → `PO/2026/IX/0003` (the gate replayed one S3 pass to reach
    0004; the number is not what this task measures). `database/database.sqlite` untouched
    (mtime 13:10:55 before and after, no `-wal`/`-shm` beside it). Screenshots in
    `<scratchpad>/ux/out-t2/s3-server-errors.png` (toast bottom-right reads only
    `Periksa isian yang ditandai.`, qty cell still red).

### T2.2 — Petunjuk tanggal id-ID di bawah input date
- Commit: 38ed3ad (placeholder `(this commit)` replaced in the T2.8 commit — one task, one commit)
- Files: `public/app/js/views/form.js` (`buildInput` `case 'date'`; import `date as fmtDate`
  from `format.js`; module-level `dateHintSeq`), `docs/bukti-uji/s3-tanggal-po-petunjuk-t2.2.png`
  (new — the acceptance screenshot, verbatim copy of the harness's `s3-form-empty.png`),
  `docs/PROGRESS-UX-PROSES.md`
- Acceptance: harness S3 screenshot shows the helper under `Tanggal PO` —
  `<scratchpad>/ux/out-t22/s3-form-empty.png` and `s3-form-filled.png`: the native input still
  draws `09/04/2026` (Chromium headless, en-US) and directly beneath it a `.help` line reads
  **`= 04 Sep 2026`**; the empty `Perkiraan kirim` beside it shows no line. Committed copy:
  `docs/bukti-uji/s3-tanggal-po-petunjuk-t2.2.png`. Same S3 run: `ok`, **12 klik**,
  `toast_on_422` `["Periksa isian yang ditandai."]`, `server_errors_rendered`
  `["Kuantitas minimal 0.001."]`, `saved` true, `PO/2026/IX/0004` (second PO on the same scratch
  DB), `Diajukan`, 16 772 ms. Regression on the new wrapper node: **S4** → banner
  `Sesi Anda berakhir. Isian PO …`, modalVisible **false**, loginVisible **true**, Masuk
  `reachable`, recoveryOffer true, restored **13 field / 3 baris**, 8 klik, 14 192 ms —
  identical to the gate.
- Notes:
  - The line is `fmt.date`'s output — `= 04 Sep 2026`, zero-padded day — not the RECAP's
    illustrative `= 2 Sep 2026`: the entry says "via `fmt.date`", and that is the format every
    list/detail date column already uses (`cells.js`), so the helper reads exactly as the row
    will after save. `dateLong` (`4 September 2026`) deliberately not used.
  - Native input kept. The `.help` line sits inside the control, is `hidden` while the value is
    empty (money.js's hint toggle — no 4 px ghost margin), and is refreshed on `input` and
    `change` (Playwright's `fill()` and the native picker both fire them; typing a date segment
    by segment leaves `value` `''` until the date is complete, so nothing half-typed is read).
    `aria-hidden` + `aria-describedby` copied from money.js: plain text inside `<label>` would
    otherwise be folded into the field's accessible NAME.
  - The control now returns `{ node: div[input, help], input, read }` — the shape `percent`,
    `bool` and `currency` already return from `buildInput`; `field()` finds the input by
    `querySelector` for `aria-labelledby`, and every consumer uses `control.input || control.node`.
    External callers checked: `settings.js` builds only percent/currency/integer/boolean/select
    through `buildInput` (its `control.node.value =` writes never meet a date); `custom.js` uses
    `control.input || control.node` and `querySelectorAll`. Drafts restore through `record` →
    `buildInput` initial value, not `node.value` (S4 above proves it).
  - Compact (line-table cells) still returns the bare input: `compact` is the flag `buildLines`
    already passes, and a second line does not fit a 31 px `<td>` (money.js's own reason).
  - No server change → no Feature test; the entry's acceptance is the S3 screenshot. No CSS
    change: `.field .help` already styles the line.
  - Environment as T2.1 (same server on `t2.sqlite`; harness `… S3` into `out-t22`, then `… S4`
    for the regression). Server stopped after the run (`pkill -f 'php -S 127.0.0.1:8000'`);
    `database/database.sqlite` untouched throughout.

### T2.8 — Warna lencana status per enum: `open` merah untuk NCR/K3/defect
- Commit: 4cfdd8e (placeholder `(this commit)` replaced in the T2.10 commit — one task, one commit)
- Files: `public/app/js/enums.js` (`opts` takes an optional third element `tone`; tones on
  `ncrStatus`, `incidentStatus`, `defectStatus`; new `enumTone()` beside `enumLabel()`),
  `public/app/js/format.js` (`statusTone(value, enumName)` prefers the enum's own tone, `''`
  included; imports `enumTone`), `public/app/js/cells.js` (`case 'status'` passes `column.enum`,
  label falls back to `enumLabel`), `public/app/js/views/detail.js` (page-head badge takes the
  enum of the `status` column in `def.columns`), `public/app/js/views/custom.js` (ticket detail
  names `ticketStatus`), `public/app/js/views/defect.js` (private `STATUS_TONE` map removed —
  the register now reads `statusTone(row.status, 'defectStatus')`), `public/app/js/schema.js`
  (NCR / K3 / defect `status` columns `type: 'enum'` → `type: 'status'`, `enum:` kept),
  `docs/bukti-uji/harness-playwright.py` (S7 also opens the first row and records the
  page-head badge as `<key>_detail`), `docs/PROGRESS-UX-PROSES.md`
- Acceptance: harness **S7** on a scratch seed with one open NCR, K3 incident, defect and ticket
  planted through the API (`POST quality/ncr`, `projects/safety-incidents`, `projects/defects`,
  `servicedesk/tickets` as admin — `NCR/2026/IX/0002`, `K3/2026/IX/003`, `DEF/2026/IX/0001`,
  `TKT-202609-0005`, all `status: open`):
  - **Before** (same harness, same DB, code at 38ed3ad): `ncr` **`[]`** (the list wrote the
    status as plain text — no badge to measure), `ncr_detail` **`Terbuka → green`**;
    `k3` `["— → ", "Ya → red"]` (only the overdue flag), `k3_detail` **`Terbuka → green`**;
    `defects` `["Mayor → amber", "Terbuka → red"]` (the register's private map),
    `defects_detail` **`Terbuka → green`** — the same document red on one screen, green on the
    next; `tickets_detail` `Ditugaskan → blue`.
  - **After**: `ncr` **`["Terbuka → red", "Ditutup → green"]`**, `ncr_detail` **`Terbuka → red`**;
    `k3` `["— → ", "Terbuka → red", "Ya → red", "Investigasi → amber", "Selesai → green"]`,
    `k3_detail` **`Terbuka → red`**; `defects` `["Mayor → amber", "Terbuka → red"]`,
    `defects_detail` **`Terbuka → red`**; `tickets` `[…, "Terbuka → green", …]` and
    `tickets_detail` **`Terbuka → green`** on the planted ticket — green stays for service
    tickets. 0 `ERROR`, 4 klik, 16 033 ms.
  - Regression on the shared `detail.js` badge: **S2** → `status_badge` `Diajukan`, toast
    `RAP/2026/0001 disetujui.`, strip `Diajukan · menunggu persetujuan.`, 5 klik, Berikutnya
    opened `PR/2026/III/0002` — as the gate.
- Notes:
  - **Where the green actually showed.** HASIL-UJI §1 could not observe the finding (no open
    NCR in the seed). Measured here: the NCR and K3 *lists* never had a status badge — their
    `status` columns were `type: 'enum'` (plain text) since P1-QC / the initial commit, so
    S7's `table.data .badge` selector had nothing to read; the green badge lived on the
    detail page (`detail.js` page-head) and, for defects, only there (the register had its
    own red map). Two consequences for scope: (a) S7 was extended by four lines to also open
    the first row and record the page-head badge — that is the measurement the entry's
    evidence describes; (b) the three list columns became `type: 'status'` (badge) with their
    `enum` kept, so the list and the detail of one NCR read the same colour. Without (b) the
    entry's acceptance ("harness S7 on a seeded open NCR") measures nothing on the NCR list.
    Both are flagged here because neither is literally in the entry's Steps.
  - `tone` is only set on the option when the tuple names it, so `statusTone` can tell "this
    enum decides" (`'tone' in option`, `''` = neutral, as `waived`) from "fall back to the
    word map". `ticketStatus` deliberately carries no tone: `open` green is the word map's
    answer and the right one there — written at the enum.
  - `views/defect.js`'s private `STATUS_TONE` map existed only because `statusTone` could not
    know its enum (its own comment said so); with the tones on `defectStatus` the map would be
    a second copy that drifts, so the register now calls `statusTone(value, 'defectStatus')`.
    The WHY (retention, `waived` neutral) moved to the enum comment.
  - `slabreaches.js:92` passes a *priority* through `statusTone` — not a status, not touched.
  - No server behaviour changed → no Feature test (rule 3). No node on the box: the harness run
    is the syntax check for all seven modules (every S7 route rendered, 0 console `ERROR`).
  - Observed, not touched: `NcrStoreRequest` marks `location_id` nullable but `NcrService`
    rejects a missing location with the "bukan bagian dari proyek" message (422) — the planted
    NCR needed `location_id: 3`. Possible tidy-up for a later pass.
  - Environment: fresh scratch seed `<scratchpad>/ux/t28.sqlite`
    (`DB_DATABASE=<scratch>/ux/t28.sqlite php artisan migrate:fresh --seed --force`; config not
    cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t28.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    planting script `<scratchpad>/ux/plant-open.py`; harness
    `ERP_DB=<scratch>/ux/t28.sqlite UXTEST_OUT=<scratch>/ux/out-t28-before … S7 S8` (before),
    `… UXTEST_OUT=<scratch>/ux/out-t28b … S7 S2` (after). `UXTEST_OUT` must exist beforehand
    (the harness does not `mkdir`). `database/database.sqlite` untouched (mtime 13:10:55, no
    `-wal`/`-shm`).

### T2.10 — Sisa aksesibilitas: `aria-description` per tbody, lantai 11 px, label ikon aksi baris di layar sentuh
- Commit: 5950ac0 (placeholder `(this commit)` replaced in the T2.7 commit — one task, one commit)
- Files: `public/app/js/ui.js` (`installRowKeys` → `stampRows`: `aria-description="Tekan Enter
  untuk membuka"` once per tbody that holds `tr.clickable`), `public/app/app.css`
  (`.brand-text span` 10.5 → 11 px, `.userchip .who span` 10.5 → 11 px, `.bell-count` 10 →
  11 px; `@media (pointer: coarse)`: `table.data td .btn.icon[data-label]` grows to its content
  and `::after { content: attr(data-label) }` writes the verb beside the icon),
  `public/app/js/views/list.js` (`labelled()` stamps `data-label` on the four row-action icon
  buttons: `Cetak`, `Unduh`, `Ubah`, `def.deleteLabel || 'Hapus'`),
  `public/app/js/views/dashboard.js` (calendar weekday names 10.5 → 11 px),
  `docs/PROGRESS-UX-PROSES.md`
- Acceptance: harness **S8** → `smallest_font_px` **11** (before, same DB and harness: **10.5**;
  the gate measured **10** with a `.bell-count` on screen). Same run: `th_font` 11px,
  contrast 5.23 / 5.47 / 5.29 unchanged, `page_head_buttons` `Muat ulang · Tambah PO`.
  Enumerating every `body *` on the PO list after the change: the smallest computed sizes are
  11 px (`.brand-text span` "Konstruksi & SI", `.userchip .who span` "admin", `.avatar`, the
  11 px nav-group buttons); nothing below.
  Extra evidence (scratch Playwright, not the harness):
  - Desktop context, `#/r/crm/customers`: one `table.data tbody`, `aria-description`
    `["Tekan Enter untuk membuka"]`, `tr[aria-description]` **0**; the Ubah button is still
    icon-only, 28 × 28 px, `::after` `none`. `#/r/projects/weekly-progress` (noDetail, rows not
    clickable): `[null]` — the sentence is only written where Enter does open something.
  - Touch context (390 × 844, `has_touch`, `is_mobile` — `matchMedia('(pointer: coarse)')`
    **true**): the same buttons read `::after` **`"Ubah"`** / **`"Cetak"`**, 74.9 × 36 px and
    77.7 × 36 px; `data-label` seen: `Ubah`, `Hapus` (customers), `Cetak` (weekly progress).
    Screenshot `<scratchpad>/ux/out-t210/coarse-customers-actions.png`.
  - `.bell-count` was not on screen (admin has no unread notification on this seed), so a
    throw-away `span.bell-count` was appended to the bell button and read back:
    `font-size` **11px**; removed again.
- Notes:
  - `.chart text` (RECAP's `app.css ~757`) was already 11 px — the Phase 0 patch raised it
    ("teks grafik 11 px", HASIL-UJI §1 patch table); nothing to do there. `.badge` (RECAP's
    "~863 badge/bell-count") is 11.5 px; only `.bell-count` at that line was 10 px.
  - The text-label option was chosen over the "⋯" menu: it needs no menu primitive (T2.6
    introduces one later) and no JS beyond a data attribute. On fine pointers nothing changes
    (icon-only, `title` tooltip); on coarse pointers the verb is CSS-generated from
    `data-label`, so the long print titles ("Cetak Detail Schedule dalam format formulir
    perusahaan") stay as tooltips and the button says `Cetak`. Generated content is part of
    the accessible name in current browsers, and it matches the `title` verb anyway.
  - Scope kept to `table.data td` icon buttons built by `list.js` — not the `table.lines`
    row-delete buttons in forms, not `.row-actions` in notifications/EVM/kas kecil.
  - No server change → no Feature test (rule 3). The harness runs are the syntax check for
    `ui.js`, `list.js`, `dashboard.js` (login, dashboard and the lists rendered, 0 `ERROR`).
  - Environment: same server and scratch DB as T2.8 (`t28.sqlite`, with the S2 approval and
    the planted rows), harness `ERP_DB=<scratch>/ux/t28.sqlite UXTEST_OUT=<scratch>/ux/out-t210 … S8`;
    scratch scripts `<scratchpad>/ux/fonts.py`, `coarse.py`, `coarse2.py`. Server stopped
    after the run (kill by PID — `pkill -f 'php -S …'` also matches the tool's own shell);
    `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`).

### T2.7 — Layanan mandiri kata sandi: `PUT iam/me/password`, menu "Ganti kata sandi", "Lupa kata sandi" hanya bila surat sampai
- Commit: 7668fc4 (placeholder `(this commit)` replaced in the T2.4 commit — one task, one commit)
- Files: `Modules/Iam/Http/Controllers/AuthController.php` (`changePassword`, `passwordHelp`,
  `forgotPassword`, `resetPassword`), `Modules/Iam/Http/Requests/ChangePasswordRequest.php`,
  `ForgotPasswordRequest.php`, `ResetPasswordRequest.php` (new), `Modules/Iam/Support/PasswordHelp.php`
  (new: `resetByEmail()`, `administratorName()`, `askAdministrator()`, `resetUrl()`),
  `Modules/Iam/Routes/api.php` (`GET auth/password-help`, `POST auth/forgot-password`,
  `POST auth/reset-password` public — the last two `throttle:10,1` like login; `PUT me/password`
  under `auth:sanctum`), `Modules/Iam/Providers/IamServiceProvider.php`
  (`ResetPassword::createUrlUsing` → `#/reset-password?token=…&email=…`; `toMailUsing` → surat
  Indonesia), `lang/id/validation.php` (rule `current_password`; attributes `current`,
  `password_confirmation`, `token`), `lang/id.json` (new: the mail template's one subcopy
  string), `public/app/js/app.js` (login page `.password-help` line from the server; `openChangePassword()`,
  `openForgotPassword()`, `renderResetPassword()`, `resetLinkParams()` read in `init()` before the
  session check; account menu button), `public/app/app.css` (`.login .password-help`, `.link-btn`),
  `docs/bukti-uji/harness-playwright.py` (S9 also records the login line and drives the dialog),
  `docs/bukti-uji/s9-ganti-kata-sandi-t2.7.png` (new), `docs/PANDUAN-PENGGUNA.md` (§0 kalimat 5,
  §14.1 two rows), `docs/PANDUAN-ADMINISTRATOR.md` (Iam module paragraph, the "Tidak ada layanan
  mandiri" bullet), `tests/Feature/Iam/SelfServicePasswordTest.php` (new, 13 tests),
  `docs/PROGRESS-UX-PROSES.md`
- Acceptance:
  - `vendor/bin/phpunit --no-progress tests/Feature/Iam/SelfServicePasswordTest.php` → OK
    (**13 tests, 69 assertions**); red first: 13/13 failed with 404 before the routes existed.
    Wrong current → **422**, `errors.current.0` = `message` = **`Kata sandi saat ini salah.`**,
    old password still logs in (200), new never stored (401). Success → 200
    `Kata sandi Anda diperbarui.`, then `auth/login` old → **401**, new → **200**; the token that
    made the change still answers `auth/me` 200. Also pinned: `Konfirmasi Kata sandi tidak cocok.`,
    `Kata sandi minimal 8 karakter.`, `Kata sandi saat ini wajib diisi.`, 401 without a session;
    `password-help` → `reset_by_email` false on `log` (and `PasswordHelp::resetByEmail()` false on
    `array`/`null`), true on `smtp`, `administrator` = first ACTIVE admin-role user by id (an
    inactive earlier admin skipped), null when there is none; forgot-password → **409** on `log`
    naming the administrator, nothing sent; on `smtp` + `Notification::fake` → 200 with the neutral
    sentence, `ResetPassword` sent, subject `Atur ulang kata sandi Nusantara ERP`, action URL
    `url('/')#/reset-password?token=…&email=…`, rendered mail contains `Halo Rina Kartika`,
    `60 menit`, `salin dan tempel`, no `Regards`/`Hello!`; unknown and inactive addresses get the
    SAME 200 sentence and nothing is sent; second link within a minute → **429**; a valid token
    resets once (old 401 / new 200) and its replay → 422 `errors.token`; a bogus token or an
    inactive account cannot reset.
  - `tests/Feature/Iam` (whole directory) → OK (**45 tests, 215 assertions**; 32 / 146 before).
    `tests/Feature/Core` → OK (**567 tests, 3445 assertions**, 1 min 45 s) — insurance for the
    shared `lang/id/validation.php` (no other FormRequest has a field named `current`/`token`).
  - Harness **S9** (finance, fresh scratch seed): `login_password_help`
    **`Lupa kata sandi? Minta Administrator Sistem (administrator) mengatur ulang kata sandi Anda.`**
    (`MAIL_MAILER=log`); `account_menu_items` **`Tutup · Ganti kata sandi · Keluar`** (Sesudah 2 Sep
    and gate 4 Sep: `Tutup · Keluar`); dialog `Ganti kata sandi` with labels `Kata sandi saat ini*`,
    `Kata sandi baru*`, `Ulangi kata sandi baru*`, buttons `Batal · Simpan kata sandi`, help
    `Minimal 8 karakter.` + `Berlaku untuk masuk berikutnya. Sesi yang sedang terbuka di perangkat
    lain tetap berjalan.`; wrong current → `.err` **`Kata sandi saat ini salah.`**, dialog still
    open, no toast; correct current → dialog closed, toast **`Kata sandi Anda diperbarui.`**;
    **4 klik**, 12 517 ms. Screenshot `docs/bukti-uji/s9-ganti-kata-sandi-t2.7.png`.
    `curl` PUT with the wrong current → 422 in 0.24 s; in Chromium the same 422 arrived 1.92 s after
    the click through `php -S`, so S9 waits for the response (first measurement with a fixed 1.5 s
    read `errors []` — the harness's fault, not the code's).
  - Reset link in Chromium (scratch script, token minted with `Password::broker()->createToken()`
    via `php artisan tinker` on the scratch DB): `#/reset-password?token=…&email=finance%40…` renders
    h1 `Atur ulang kata sandi`, email prefilled, focus on the password; mismatch caught before the
    request (`Konfirmasi kata sandi tidak cocok.`); submit → **200**, hash cleared, login page with
    banner **`Kata sandi diperbarui. Masuk dengan kata sandi baru Anda.`**; the new password signs
    in (nav visible); the same link again → **422**, `.alert.error`
    `Tautan pengaturan ulang tidak berlaku lagi (berlaku 60 menit, sekali pakai). Minta tautan baru
    dari halaman masuk.`; API: old password 401, new 200; then restored to `password` through
    `PUT iam/me/password` (200, old 200 again) so the scratch seed stays usable. 0 page errors.
  - `MAIL_MAILER=smtp` branch (server restarted with that env, nothing sent): `password-help`
    `reset_by_email` **true**; login line **`Lupa kata sandi? Kirim tautan pengaturan ulang`**
    (a `.link-btn`); click → dialog `Kirim tautan pengaturan ulang`, `Email akun Anda*` prefilled
    from the login field, help `Tautan berlaku 60 menit dan hanya sekali pakai.`, buttons
    `Batal · Kirim tautan`; empty email → `Wajib diisi.` before any request; Batal → 0 modals.
  - Regression **S4** (login page changed): banner `Sesi Anda berakhir. Isian PO …`, modalVisible
    **false**, loginVisible **true**, Masuk `reachable`, recoveryOffer true, restored **13 field /
    3 baris**, 8 klik, 14 715 ms — as the gate.
  - `vendor/bin/pint --test` on the seven PHP files + the test → passed.
- Notes:
  - **"Lupa kata sandi" does its verb.** The entry says the link shows only when
    `MAIL_MAILER !== 'log'`; a link that shows must send something, so the email path is Laravel's
    own broker (`password_reset_tokens` from the initial migration, 60 min / 1 per minute from
    `config/auth.php`) behind two public routes and one SPA screen — the smallest thing that makes
    the sentence true. It is dormant on erp1 (`MAIL_MAILER=log` in `.env.example`): the server
    refuses `forgot-password` with **409** in that state so an API client gets the same answer as
    the login page, and the login page names the administrator instead. Enabling it is a deploy
    step (`MAIL_*` in the box's `.env`), not a policy decision — no `## Open questions` entry.
  - **`array` and `null` count as "not delivered"**, not only `log`: "sent" to the testing array
    or the null transport reaches nobody either (copy rule 1 — never state what you don't know).
    Written at `PasswordHelp::UNDELIVERED`, tested.
  - **The administrator is the first active admin-role user, name only.** The entry wants a line
    "naming the administrator"; the seed's is `Administrator Sistem`, erp1's is whatever
    `ERP_ADMIN_NAME` was. `password-help` is public, so it gives the NAME and never the email
    (colleagues know how to reach the person; a public endpoint should not hand out addresses).
    Deactivated admins are skipped; with none the line reads `administrator sistem` — nothing invented.
  - **Tokens are not revoked** on a self-service change — neither the caller's (the person is at
    the keyboard) nor other devices' — the same rule the admin path documents
    (PANDUAN-ADMINISTRATOR §3.4: token Sanctum bertahan melewati penggantian sandi) and the dialog
    says so in one sentence (copy rule 2). An opt-in "keluarkan sesi di perangkat lain" (OWASP ASVS
    3.3.3 wording: *the option*) is a candidate for a later entry, not this one's Steps. A reset via
    email in a tab that still holds a session does call `logout()` first — that person is being
    told to sign in with the new password and must not slide back into the old session on reload.
  - Messages come from `lang/id/validation.php` through the attribute map, as the entry says:
    `current_password` rule + `current` attribute give `Kata sandi saat ini salah.`; the shared
    `password` attribute (`Kata sandi`) gives `Konfirmasi Kata sandi tidak cocok.` with the
    capital K mid-sentence — the file's existing pattern for every `confirmed`/`different` rule,
    left alone. The SPA catches the mismatch client-side first with `Konfirmasi kata sandi tidak
    cocok.`, and the server's sentence lands under `Kata sandi baru` when it does arrive (Laravel
    pins `confirmed` to the source field).
  - `lang/id.json` is new: the notification mail template's subcopy ("If you're having trouble
    clicking…") is a JSON-string translation, and greeting/salutation are set on the message. No
    other `__()` key in the codebase matches it.
  - **Docs corrected, deliberately outside the entry's file list:** PANDUAN-PENGGUNA §0 kalimat 5
    ("Anda tidak bisa mengganti kata sandi sendiri") and the two §14.1 rows, plus two
    PANDUAN-ADMINISTRATOR passages ("Tidak ada layanan mandiri kata sandi"), would state a falsehood
    after this commit. Minimal rewrites; §3.4 and §3.7's route table untouched (`password-help` is
    unauthenticated, not "terbuka bagi setiap akun yang sudah masuk").
  - `.link-btn` is a button styled as a link on purpose: `<a href="#">` moves the hash and wakes the
    router on a page that has no shell yet (after a logout the router is registered). The reset
    screen clears the hash with `history.replaceState` for the same reason.
  - `password-help` in the SPA: no answer → no line (like demo-accounts), never a guess.
  - No node on the box: the harness runs (login, dashboard, both dialogs, the reset screen) are the
    syntax check for `app.js`; 0 `pageerror` in every scratch run.
  - Environment: fresh scratch seed `<scratchpad>/ux/t27.sqlite`
    (`DB_DATABASE=<scratch>/ux/t27.sqlite php artisan migrate:fresh --seed --force`; config not
    cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t27.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`
    (and once more with `MAIL_MAILER=smtp` for the link branch); harness
    `ERP_DB=<scratch>/ux/t27.sqlite UXTEST_OUT=<scratch>/ux/out-t27 /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S9 S4`;
    scratch scripts `<scratchpad>/ux/t27-reset.py`, `t27-smtp.py`, `t27-console2.py`. Server stopped
    after each run (kill by PID from `pgrep -f '^php -S 127.0.0.1:8000'` — the `^` anchor skips the
    tool's own wrapper shell); `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`).

### T2.4 — Ajukan PO: alasan override prakualifikasi lewat confirm-resubmit, bukan modal pada setiap pengajuan
- Commit: 7856e4a
- Files: `public/app/js/schema.js` (PO `submit` action: `fields` removed, first `confirmResubmit`
  rule with `promptField`), `public/app/js/views/actions.js` (`confirmResubmit` engine: `promptField`
  next to `flag`), `public/app/js/views/form.js` (`promptFields` gains a `message` option),
  `Modules/Procurement/Http/Controllers/PurchaseOrderController.php` (`submit()`: the
  prequalification 422 now carries `errors.qualification_override_reason`, message unchanged),
  `tests/Feature/Procurement/VendorQualificationTest.php` (+2 tests),
  `docs/bukti-uji/harness-playwright.py` (new scenario `S12_po_override`),
  `docs/bukti-uji/s12-override-prompt-t2.4.png`
- Acceptance:
  - Healthy vendor = **1 click**: harness **S12** `healthy.submit_clicks` **1**, `modal_opened`
    **false**, toast `PO/2026/IX/0009 diajukan · menunggu persetujuan.`, badge `Diajukan`
    (before, same harness on 7668fc4: **2** clicks, `modal_opened` true, modal titled `Ajukan`
    with the optional field and no server sentence). Harness **S3** create→submit PO 2 lines:
    **11 klik** (gate / T2.2: 12; Sebelum 2 Sep: 13), `submit_modal_fields` **absent** (was
    `['Alasan override prakualifikasi']`), `PO/2026/IX/0008`, `Diajukan`, `toast_on_422`
    `['Periksa isian yang ditandai.']`, landing `#/d/procurement/purchase-orders/8`, 15 678 ms.
  - Blocked vendor → prompt with the server message → resubmit succeeds: **S12** (VND-0001 set
    `inactive` in sqlite AFTER the draft was created — the gate stands at submit, a PO to a blocked
    vendor is never born as a draft): prompt title `Vendor belum lolos prakualifikasi — tetap
    ajukan?`, message = the server's 422 sentence verbatim (`Vendor VND-0001 (PT Semen Distribusi
    Utama) belum lolos prakualifikasi: vendor berstatus nonaktif. …`), field
    `Alasan override prakualifikasi*`, help `Tersimpan di PO sebagai jejak audit. …`, buttons
    `Batal · Ajukan dengan alasan ini`; empty reason → `Wajib diisi.` with the dialog still open
    and no request; typed reason → toast `PO/2026/IX/0010 diajukan · menunggu persetujuan.`,
    badge `Diajukan`, `GET` shows `status: submitted` and
    `qualification_override_reason: "UJI-UX — pembelian darurat, vendor tunggal pemegang lisensi"`;
    3 clicks including the deliberate empty attempt (2 without it). Before (7668fc4): the optional
    modal closed on an empty submit, the refusal flashed as a toast, PO stayed `Draf`, and the
    harness could not find a field to type the reason into (`Page.fill: Timeout`).
  - `vendor/bin/phpunit --no-progress tests/Feature/Procurement` → **OK (165 tests, 651
    assertions)** (was 163 / 643 without the two new tests); `PoQualificationOverrideAuditTest` green, server tests unchanged.
    The new key test was red first (`errors.qualification_override_reason` null on 7668fc4).
  - Regression **S2** (`promptFields` is shared with the approve dialog): 5 klik, modal fields
    `['Catatan persetujuan']`, buttons `['Batal', 'Setujui']`, toast `RAP/2026/0001 disetujui.`,
    Berikutnya opened `PR/2026/III/0002` — as the gate.
  - `vendor/bin/pint --test --dirty` → passed.
- Notes:
  - **The key the entry names was not on the wire.** `submit()` answered the prequalification
    refusal with `catch (LogicException) → error($message)`: a bare `{message}`, no `errors` map —
    the confirm-resubmit engine matches on `errors` keys, and matching the sentence's prose instead
    would leave NO way to enter a reason the day someone rewords it (the `fields` modal is gone).
    So the entry's file list gained the controller: one `catch (VendorNotQualifiedException)` ahead
    of the generic catch, answering the same message plus
    `errors: {qualification_override_reason: [message]}`. Additive — `message` is unchanged, so
    every existing assertion and API client reads what it read before; the pair of tests pins that
    the key appears for the prequalification refusal and for NO other submit refusal (a status
    refusal would otherwise be answered with an override prompt for something that cannot be
    overridden).
  - `promptField` reuses `promptFields()` (one dialog primitive, `Wajib diisi.` and whitespace
    handling included — the textarea `read()` returns null for blanks, so a reason of spaces never
    reaches the server) with a new `message` option that renders the server sentence above the
    field in confirmDialog's paragraph style; the engine's "already answered" guard is
    `payload[key] === undefined` for both shapes, same semantics as the old `!payload[flag]`.
  - The server sentence still ends with `Sertakan alasan override (qualification_override_reason)
    bila tetap harus diajukan.` and the dialog shows it verbatim — the entry's principle for the
    two existing rules ("pesan dialog adalah pesan server apa adanya"). The raw-key trailer exists
    for API clients (VendorNotQualifiedException docblock); now that the key is structured it is
    redundant on screen — a copy tidy-up candidate, not this entry's Steps.
  - The same modal-on-every-submit pattern remains on SPK (`schema.js` work-orders `submit`,
    `WorkOrderController::submit`), subcontracts and labor contracts (`fields` with
    `qualification_override_reason`). The entry says PO; those would need the same one-line
    server key each — noted, not done.
  - Harness: S12 flips the vendor in the served sqlite (the S4 pattern) and restores it in
    `finally`, so later scenarios are unaffected; on the old flow it records the failure inside
    its own result instead of losing the healthy-path numbers. It creates two POs per run.
  - Environment: fresh scratch seed `<scratchpad>/ux/t24.sqlite`
    (`DB_DATABASE=<scratch>/ux/t24.sqlite php artisan migrate:fresh --seed --force`; config not
    cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t24.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    before-run with the five app/test files stashed:
    `ERP_DB=<scratch>/ux/t24.sqlite UXTEST_OUT=<scratch>/ux/out-t24-before /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S3 S12`;
    after-run `… UXTEST_OUT=<scratch>/ux/out-t24 … S3 S12 S2`. Server stopped by PID
    (`pgrep -f '^php -S 127.0.0.1:8000'`); `database/database.sqlite` untouched (mtime 13:10:55,
    no `-wal`/`-shm`).

### T2.3 — Catatan persetujuan inline di bilah aksi, bukan modal; Setujui memutus langsung
- Commit: ffa87b3
- Files: `public/app/js/schema.js` (`approvalActions` approve: `fields` → `inlineNote`, header
  comment of the action shape; the CCO in-file copy of the same shape converted too),
  `public/app/js/views/actions.js` (`inlineNote()` — `<details>/<summary>` panel; `runAction`
  gains `inline`; `actionButtons` returns the panel after all buttons), `public/app/app.css`
  (`.action-note` rules after `.page-head .actions`), `docs/bukti-uji/harness-playwright.py`
  (S2: modal step conditional + `approve_modal_opened` / `approve_note_inline`; new
  `S13_approve_with_note`; shared `NOTE_PANEL` reader), `docs/bukti-uji/s13-catatan-inline-t2.3.png`
- Acceptance:
  - **Harness S2 clicks per approval = 2** (Setujui, Buka berikutnya): `S2_approve_loop`
    `_clicks` **4** (baris → Setujui → Buka → Kembali; gate / T2.4: 5, the fifth being Setujui
    again inside the note modal), `approve_modal_opened` **false**, `approve_modal_fields` /
    `approve_modal_buttons` null, `approve_note_inline` `{toggle: 'Tambah catatan', open: false,
    textarea_visible: false}`, toast `RAP/2026/0001 disetujui.` + `Berikutnya menunggu Anda (3)
    PR/2026/III/0002 …`, `opened_next` `PR/2026/III/0002`, `action_bar`
    `['Kembali', 'Cetak halaman', 'Setujui', 'Tolak']` (unchanged — the toggle is a `summary`,
    not a button), `api_calls_detail_to_back` 16 (unchanged), `approve_total_ms` 2 038
    (gate 2 577), 10 026 ms, 0 ERROR.
    Before, same adapted harness on 7856e4a: `_clicks` **5**, `approve_modal_opened` **true**,
    fields `['Catatan persetujuan']`, buttons `['Batal', 'Setujui']` — the conditional modal
    step fires on the old build, so the count is not obtained by deleting the click.
  - **With a note (new S13)**: `_clicks` **3** (baris → Tambah catatan → Setujui) — the same 3 the
    modal path cost with OR without a note; `after_toggle` `{toggle: 'Batalkan catatan', open:
    true, label: 'Catatan persetujuan', help: 'Opsional. Ikut tersimpan pada riwayat persetujuan
    PR/2026/III/0002.', width: 440, focused: true}`; `approve_payload`
    `{"note":"UJI-UX — catatan persetujuan inline"}`; `modal_opened` false; toast
    `PR/2026/III/0002 disetujui.`; sqlite `core_approvals` latest `approved` row `note` equals the
    typed text → `note_stored` **true**; 6 131 ms. Before (7856e4a): `before` null, stops at 1 click.
  - **API contract unchanged**: `POST {id}/approve` still carries `{ note }` when typed and `{}`
    when blank — scratch probe (`<scratchpad>/ux/t23-probe.py`, approve request intercepted and
    aborted so the scratch state stays put): blank `"   "` → payload `{}`; typed → `{"note":"isi
    sungguhan"}`. No PHP touched, so no PHPUnit / pint run for this task.
  - **Layout / keyboard probe** (same script; 1440×900, 800×900, 390×844 touch): closed, the panel
    is exactly the width of the button row (226 px) and Tolak ends flush at the `.actions` right
    edge in both states; open, `.actions` grows to 440 / 440 / 362 (full width on the phone) and
    the textarea fills it; Tab from Tolak lands on `summary "Tambah catatan"`, Enter opens it and
    focuses the textarea; "Batalkan catatan" closes it with `value ''`; 0 `pageerror` at all
    three widths.
  - Regressions: **S1** 4 / 4 (card `Menunggu persetujuan Anda (4)`), **S11** h1 `Tugas Saya`,
    leave-request bar `['Kembali', 'Cetak halaman', 'Setujui', 'Tolak']`, **S3** 11 klik,
    `PO/2026/IX/0003` `Diajukan`, `toast_on_422` `['Periksa isian yang ditandai.']`.
- Notes:
  - `<details>/<summary>`, not a button, for the toggle: the browser's own disclosure — keyboard
    reachable (Enter/Space), exposes expanded/collapsed itself — and `.page-head .actions button`
    stays at 4, which is what T2.6's gate (`action_bar` ≤ 4) counts. Closing the panel EMPTIES
    the textarea (hidden text must never ride along silently) and the open-state label says so:
    `Batalkan catatan`. Blank/whitespace notes never reach the server: the textarea is
    `buildInput`'s, whose `read()` returns null for those.
  - Two layout facts measured on the way, both now in the CSS comments: (1) a `<details>` renders
    its non-summary children inside an internal slot box (`::details-content`); as a flex item of
    the panel that box shrink-wrapped to the help text — textarea 355 px inside a 440 px panel —
    so the panel is block-level and the summary is right-aligned with `width: fit-content;
    margin-left: auto`. (2) `contain: inline-size` keeps label/help/textarea from widening
    `.actions`, but the wrapped panel still contributes one flex `gap` (8 px) to the container's
    max-content width, so the buttons stopped 8 px short of the right edge; `.actions:has(.action-
    note)` is right-justified for as long as the panel exists. Browsers without `:has()` lose only
    those two tidy-ups.
  - Harness: `textarea_visible` reads `checkVisibility()`, not `offsetParent` — Chromium renders a
    closed `<details>`' content with `content-visibility: hidden`, so `offsetParent` is non-null
    and the first run reported the hidden textarea as visible (22 px wide). S2's modal step is
    conditional on `.modal` actually appearing (waits for the modal OR the decision toast — no
    fixed sleep, so `approve_total_ms` stays honest); the click counter increments only when it
    clicks. S13 is appended to the run order (after S12); it approves whichever document heads the
    inbox at that point, so on a full run it takes the document S2's "Berikutnya" pointed at.
  - Scope kept to `approvalActions` plus the CCO action that its own comment declares an exact
    mirror of it. Deliberately still on the modal: BAST approve (`schema.js` ~1618 — a second
    field, `override_reason`, that must stay reachable and would not be guessed under "Tambah
    catatan"), award-decision approve (~2412 — its note carries the levelled-approval help that
    should be read before pressing Setujui), and the payment `decide()` dialog in `custom.js`
    (~799, its own `promptFields`). Each is a one-line conversion once someone decides where that
    help belongs; not this entry's Steps.
  - `actionButtons` returns the panel after ALL buttons (flex-basis 100% makes it its own row);
    in `custom.js` bars that append buttons after `actionButtons()` (SPK's `Bayar Retensi`, the
    asset `Ubah`) those buttons would follow the panel — only reachable on approved/non-approvable
    rows, so never next to a Setujui panel today.
  - T2.4's PROGRESS placeholder now reads 7856e4a.
  - Environment: fresh scratch seed `<scratchpad>/ux/t23.sqlite`
    (`DB_DATABASE=<scratch>/ux/t23.sqlite php artisan migrate:fresh --seed --force`; config not
    cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t23.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    before-run (harness edited, app untouched):
    `ERP_DB=<scratch>/ux/t23.sqlite UXTEST_OUT=<scratch>/ux/out-t23-before /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S2 S13`;
    re-seed, then the final run `… UXTEST_OUT=<scratch>/ux/out-t23-final … S1 S2 S13 S11 S3`
    (the harness runs them in its own fixed order: S1, S2, S3, S11, S13). Server stopped by PID
    (`pgrep -f '^php -S 127.0.0.1:8000'`); `database/database.sqlite` untouched (mtime 13:10:55,
    no `-wal`/`-shm`).

### T2.6 — Bilah aksi tiga zona + menu "Cetak ▾" (halaman · PDF · formulir rumah · XLSX)
- Commit: 424acb0
- Files: `public/app/js/ui.js` (`menuButton()` — the menu primitive, after `withBusy`),
  `public/app/js/views/detail.js` (`formMenuItems()` as the one entry list, `formButtons()` now
  derived from it — same labels, same behaviour for the custom screens; new `printMenu()`; the
  page head split into `.zone.navigasi` · `.zone.keluaran` · `.zone.keputusan` with one `.primary`),
  `public/app/js/printcatalog.js` (`loadPrintForms()` reads the array `api.get()` already
  unwrapped — see Notes, the catalogue was empty on every session), `public/app/app.css`
  (`.zone` rules after the T2.3 `.action-note` block, `.menu-wrap` / `.menu-pop` / `.menu-item`),
  `docs/bukti-uji/harness-playwright.py` (S2 gains `po_bar` via `po_action_bar()` /
  `read_action_bar()`; shared `BAR` expression), `docs/bukti-uji/s2-cetak-menu-t2.6.png`
- Acceptance:
  - **Gate — harness S2 `action_bar` length ≤ 4 on a PO with 2 house forms**: `S2 › po_bar`
    (new): PO/2026/IX/0003 **as procurement, draft** `action_bar`
    `['Kembali', 'Cetak', 'Ubah', 'Ajukan']` = **4** (before, same harness, code at ffa87b3 with only
    the catalogue fix applied: `['Kembali', 'Cetak halaman', 'PDF', 'Cetak Pesanan Pembelian
    (Formulir Rumah)', 'XLSX', 'Ubah', 'Ajukan']` = **7**; at ffa87b3 as shipped: 5 — see Notes);
    **as direktur, submitted** `['Kembali', 'Cetak', 'Setujui', 'Tolak']` = **4** (before 7 / 5).
    `zones` `[['Kembali'], ['Cetak'], ['Ubah', 'Ajukan']]` and `[['Kembali'], ['Cetak'], ['Setujui',
    'Tolak']]`; `primary` `['Ajukan']` / `[]`; menu `items` `['Cetak halaman', 'Unduh PDF', 'Cetak
    Pesanan Pembelian (Formulir Rumah)', 'Unduh Pesanan Pembelian (Formulir Rumah) (XLSX)']`,
    `focused_first` true, `aria-expanded` "true"; `after_escape` `{menu_open: false,
    focus_on_trigger: true, bar_buttons: 4}` in both views; 1 click each to open (counted in
    `po_bar.clicks`, not in `_clicks`).
    S2's own document (the inbox head): `action_bar` `['Kembali', 'Cetak', 'Setujui', 'Tolak']`
    (T2.3: `['Kembali', 'Cetak halaman', 'Setujui', 'Tolak']` — the RAP house form now rides in the
    menu), `_clicks` **4** (unchanged), `approve_total_ms` 2 062 / 2 050, `detail_ms` 1 145 / 1 139,
    `api_calls_detail_to_back` 16 / 14 (the 14 is the CTI-first tie-break documented in the Phase 0
    gate), toast `RAP/2026/0001 disetujui.` + `Berikutnya menunggu Anda (3) PR/2026/III/0002 …`,
    `approve_note_inline` `{toggle: 'Tambah catatan', open: false, textarea_visible: false, width:
    338}` (T2.3: 226 — the closed panel now spans the wider three-zone row), 0 ERROR.
  - **Probe** (`<scratchpad>/ux/t26-probe.py`, not committed; procurement draft PO at 1440 / 800 /
    390 px touch, direktur on five screens): `.actions` and all three zones **34 px** tall at every
    width (the first cut measured 72 px: `.zone.nav` collided with the sidebar's `.nav` rules —
    padding 10 px — hence the Indonesian zone names); dividers `1px solid` + `padding-left 12px`
    on zones 2 and 3, none on the first; closed menu = **0** `[role=menu]`/`.menu-item` nodes in
    the DOM. Keyboard: ArrowDown on the trigger opens with `Cetak halaman` focused, then Unduh PDF
    → Cetak Pesanan → Unduh (XLSX) → wraps to Cetak halaman; ArrowUp wraps back; End / Home;
    **Tab** closes and lands on `Ubah` (the next button), Shift+Tab from the closed trigger lands
    on `Kembali`; ArrowUp on the trigger opens with the LAST item focused; **Escape** closes,
    focus back on `Cetak`, `aria-expanded` "false"; Enter on the trigger opens (4 items), Enter on
    an item activates and closes. Mouse: outside click closes, second click on the trigger
    closes. Actions: `Cetak halaman` → `window.print` called once, menu closed, focus on the
    trigger; `Unduh PDF` → download `po-PO-2026-IX-0005.pdf`, trigger `disabled` + `.spin` during
    the fetch, label + chevron restored after; `Cetak Pesanan Pembelian` → new tab `blob:…` titled
    `SURAT PESANAN PEMBELIAN (PURCHASE ORDER)`; `Unduh … (XLSX)` → `order-pembelian-PO-2026-IX-0005.xlsx`.
    Route change with the menu open → 0 menu nodes left, ArrowDown afterwards harmless. 390 px
    touch: popup at left **8** / right 366 of 390 (first cut: left **−162** — hence the shove in
    `open()`), item heights **42** px (`pointer: coarse`), bar still one row of 4. 800 px: popup
    238–598, items 32 px. **0 `pageerror`** in every context.
  - **Custom heads left as they were** (same probe, direktur): RFQ/2026/IX/0001 (`rfq.js`)
    `['Muat ulang', 'Cetak Tabulasi Banding Penawaran']`; project (`project.js`) `['Kembali',
    'Cetak', 'Galeri Foto', 'Cetak Data Proyek', 'Tutup proyek']`; PYR/2026/03/001 (`custom.js`
    `pageHead`) `['Kembali', 'Cetak', 'Cetak Rekap Gaji']` — none carries a menu, all render, 0
    errors. Generic screens: PR/2026/III/0002 `['Kembali', 'Cetak', 'Setujui', 'Tolak']`,
    RAP/2026/0001 (approved) `['Kembali', 'Cetak']`, CTI leave request for direktur `['Kembali',
    'Cetak', 'Setujui', 'Tolak']` with `details.action-note` still the LAST child of `.actions` and
    the status strip still `.page-head`'s next sibling.
  - Regressions (full run `S1 S2 S3 S4 S8 S11 S12 S13` on a fresh seed): **S1** 4 / 4; **S3** 11
    klik, `toast_on_422` `['Periksa isian yang ditandai.']`, landing `#/d/procurement/purchase-orders/4`,
    `PO/2026/IX/0004` (S2's probe PO takes 0003 now — the same number the Sesudah column had),
    `detail_action_bar` `['Kembali', 'Cetak', 'Ubah', 'Ajukan']` (Sesudah 2 Sep: `['Kembali', 'Cetak
    halaman', 'PDF', 'Ubah', 'Ajukan']`), `bar_after_submit` `['Kembali', 'Cetak']`, `Diajukan`,
    15 665 ms; **S4** banner `Sesi Anda berakhir. Isian PO …`, modalVisible false, loginVisible
    true, recoveryOffer true, 13 field / 3 baris restored, 8 klik; **S8** 5.23 / 5.47 / 5.29,
    `smallest_font_px` 11; **S12** healthy 1 click no modal, blocked prompt + `Wajib diisi.` +
    stored `qualification_override_reason`, 3 klik; **S13** 3 klik, payload `{"note": …}`,
    `note_stored` true. **S11** failed inside that run (`tr:has-text('CTI/')` timeout) because
    S2 had approved CTI/2026/VIII/0002 — the seed's queue tie-break put CTI first this time, as the
    Phase 0 gate block documents — so it was re-run alone on a fresh seed: h1 `Tugas Saya`, 4 rows,
    `leave_detail_bar` `['Kembali', 'Cetak', 'Setujui', 'Tolak']` (T2.3: `'Cetak halaman'` — the
    leave-request house form now rides in the menu), status strip unchanged; **S7** on the same
    seed unchanged (`Ditutup → green`, `Investigasi → amber`, …).
  - No PHP touched → no PHPUnit / pint run for this task.
- Notes:
  - **The print catalogue was empty on every session since the initial commit (3b933f1).**
    `api.js request()` unwraps `{ data }` and returns the array; `printcatalog.js loadPrintForms()`
    then read `payload.data` on that array → `undefined` → `[]`. Measured 4 Sep 2026: `GET
    core/print/forms` answers 23 entries for procurement, `loadPrintForms()` resolved to 0; the PO
    bar showed `PDF` but never `Cetak Pesanan Pembelian` / `XLSX` — and the Sesudah column of
    2 Sep (`S3 › detail_action_bar`) shows the same 5 buttons, so ASESMEN-UX §1.2's "8 tombol" was
    read from the code, not seen. Fixed in this commit because T2.6's menu must contain "every
    house form" and would otherwise contain none. Side effect, all honest: the catalogue's forms
    now appear for the first time on every screen that asked for them — `Cetak RAP` on the RAP
    detail, the leave-request form for direktur, `Cetak Rekap Gaji` on the payroll run,
    `Cetak Tabulasi Banding Penawaran` on the RFQ, the row buttons on `noDetail` lists
    (`list.js printRowButtons`). The seven schema-declared `printForms` were never affected.
  - **A menu of one is a button**: `printMenu()` returns the old icon-only `Cetak halaman` button
    when the document has no PDF and no house form the caller may print (tickets, and leave
    requests for roles without `hr.view`) — a one-item menu is one extra click for no choice.
    Everywhere else the trigger reads `Cetak` (innerText; the caret is an `aria-hidden` SVG, so
    the harness sees `'Cetak'`, not `'Cetak ▾'`).
  - **Only the first `.primary` survives** in the decision zone: schema.js gives `variant:
    'primary'` to 26 actions and a few can show together (two asset actions without `when`);
    schema order is lifecycle order, so the first keeps it. `Ubah` is never primary.
  - **T2.3's panel stays a direct child of `.actions`, last** — `actionButtons()` still returns
    `[...buttons, ...panels]`; `renderDetail` partitions by `details.action-note` so the
    `flex-basis: 100%` / `:has(.action-note)` rules keep measuring what T2.3 measured, and the
    harness's `explanation_under_title` (`.page-head`'s next sibling) is untouched. `.zone`
    wrappers are the flex items of `.actions`, so every existing selector (`.page-head .actions
    button:has-text(…)`, `[title='Kembali']`, `details.action-note > summary`) resolves as before.
  - **Popup built on open, removed on close** — `.page-head .actions button` counts what can be
    pressed, which is what the gate counts; one menu open app-wide (module-level `openMenu`, the
    combobox.js pattern); `withBusy` spins on the TRIGGER because the chosen item is gone with the
    popup; `item.onClick` runs before the close and without `await` so `openPrintable`'s
    `window.open` still sits on the click.
  - Copy: menu items carry their verb (`Cetak halaman`, `Unduh PDF`, `Cetak <formulir>`, `Unduh
    <formulir> (XLSX)`); the stand-alone XLSX button on custom screens keeps its `XLSX` label
    (`short`) so those bars are byte-identical to before.
  - Deliberately not touched: `rfq.js`, `project.js`, `custom.js pageHead`, `tender.js`, `k3.js`,
    `defect.js` — each composes its own head, each still works (probe above); their conversion to
    `printMenu()` is a one-liner each once someone decides whether `Muat ulang` belongs in the
    navigation zone. `printcatalog.js printButtonsFor/printablePath/xlsxPath` unchanged.
  - Harness: S2's `po_bar` creates one draft PO via the API and submits it AFTER the approval
    loop (so `_clicks`, `api_calls_detail_to_back` and `approve_total_ms` stay the T2.3 numbers),
    reads it as procurement in a separate browser context (the direktur session in `pg` is not
    disturbed), then as direktur. Consequences on a full run: S3's PO number shifts by one (0003 →
    0004), the inbox grows by one (the newest submit — S13 still takes the document S2's
    "Berikutnya" pointed at), S11 shows 5 rows.
  - T2.3's PROGRESS placeholder now reads ffa87b3.
  - Environment: fresh scratch seed `<scratchpad>/ux/t26.sqlite`
    (`DB_DATABASE=<scratch>/ux/t26.sqlite php artisan migrate:fresh --seed --force`; config not
    cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t26.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    before-runs `ERP_DB=<scratch>/ux/t26.sqlite UXTEST_OUT=<scratch>/ux/out-t26-before … S2`
    (ffa87b3 as shipped) and `… out-t26-before2 … S2` (ffa87b3 + the catalogue fix only, re-seeded);
    after: `… out-t26 … S2`, probe `UXTEST_OUT=<scratch>/ux/out-t26-probe2 python t26-probe.py`,
    full run `… out-t26-full … S1 S2 S3 S4 S8 S11 S12 S13` (re-seeded), then `… out-t26-s11 … S11 S7`
    (re-seeded). Server stopped by PID (`pgrep -f '^php -S 127.0.0.1:8000'`);
    `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`).

### T2.5 — Sidebar: grup tertutup bawaan, pemisah, Favorit / Terakhir dibuka, sumber "Layar" di Ctrl+K
- Commit: a5b614f (placeholder `(this commit)` replaced in the T2.9 commit — one task, one commit)
- Files: `public/app/js/schema.js` (`{ divider }` entries — Proyek: Pelaksanaan · Serah terima ·
  Izin & K3 · Register, items in their old order; Keuangan: AR/AP · Kas · Pelaporan · Pajak ·
  Master, rows regrouped under the captions, no route renamed; `export function visibleNav(can)`
  right after `NAV` — the permission filter that used to live in app.js, now shared with search.js,
  dividers dropped when their block is empty), `public/app/js/app.js` (`renderNav()` /
  `navGroupNode()` / `navItemNode()` / `starButton()` / `shortcutGroups()` / `toggleFavorite()` /
  `rememberRecent()` / `refreshNav()`; `storedOpenGroups()` + `groupOpenByDefault()` — `null` =
  no preference = collapsed except Ringkasan and the two shortcut groups; `setActiveNav()` no
  longer forces a shortcut group open; the `d/*` route records the document after its
  `.page-head` is drawn), `public/app/js/search.js` (`screenHits()`, the "Layar" group rendered
  first and already during `Mencari dokumen…`, `render()` takes the client hits), `public/app/js/ui.js`
  (`star` icon path), `public/app/app.css` (`.nav-divider`, `.nav-item`, `.star`, coarse-pointer
  rule, `.search-hit.screen`), `public/app/js/views/project.js`, `views/rfq.js`, `views/tender.js`
  (fill the breadcrumb with the code, like detail.js/custom.js do — see Notes),
  `tests/Feature/Core/SidebarNavWiringTest.php` (new, 5 tests), `docs/bukti-uji/harness-playwright.py`
  (`nav_click()` helper used by S3/S6/S11; S5 reports `navContentPx`, `open_groups`,
  `shortcut_groups`, `dividers`; S6 reports `linkVisible` / `group_opened`; new **S14** Ctrl+K
  probe; `login()` honours `Retry-After` on 429), `docs/bukti-uji/s5-sidebar-admin-t2.5.png`,
  `docs/bukti-uji/s14-ctrl-k-opname-t2.5.png`, `docs/PROGRESS-UX-PROSES.md` (T2.6 placeholder →
  424acb0).
- Acceptance:
  - **Gate — harness S5 `viewportsTall` for admin ≤ 2.0 with default preferences** (fresh
    context, `localStorage.clear()` before login, run `<scratchpad>/ux/out-t25-s5b`, 11 / 11 roles,
    0 ERROR): **admin 1.4** (`navHeightPx` 1289, `navContentPx` **606** = 0.67 viewport, 14 groups /
    122 links, `open_groups` `['RINGKASAN']`, `shortcut_groups` 0, `dividers` `['Pelaksanaan',
    'Serah terima', 'Izin & K3', 'Register', 'AR/AP', 'Kas', 'Pelaporan', 'Pajak', 'Master']`);
    direktur 1.4 (606 px, 120 links); project-manager 1.0 (451 px, 63); site-manager 1.0 (389 px,
    45); estimator 1.0 (389, 48); procurement 0.9 (358, 36); warehouse 1.0 (358, 36); finance 1.0
    (389, 68); hr 0.9 (265, 15); sales 1.0 (389, 44); teknisi 1.0 (265, 17). Baseline 2 Sep
    (HASIL-UJI §1): admin **4,9** (4 447 px), direktur 4,9, finance 2,7, PM 2,6. `viewportsTall`
    has a floor: `nav.scrollHeight` is at least the grid row's height, which `.shell`
    (`min-height: 100dvh`, `minmax(0, 1fr)` rows) lets the dashboard stretch to 1 289 px for
    admin — hence the new `navContentPx` (sum of the groups' `offsetHeight` + padding), which is
    the sidebar itself.
  - **Ctrl+K "opname" (S14, admin, `out-t25-final`)**: group `Layar` first, **5** screens —
    `Opname Owner (OPN) · Proyek`, `Opname · Persediaan`, `Opname Subkon · Subkontrak`, `Opname
    Mandor · Subkontrak`, `Variasi Kontrak (Plafon Opname) · Proyek`; Enter → `#/r/projects/progress-measurements`,
    h1 `Opname Owner (OPN)`, modal closed. The RECAP says "3 screens": NAV holds five labels with
    the word *Opname* (four begin with it), all five are real screens the admin may open, and
    hiding two to reach a number would be the wrong fix — the "3" is ASESMEN-UX §2.3's prose
    ("tiga layar bernama Opname"), written before counting. Same probe (`t25-probe.py`):
    `po` → Layar `Pesanan (PO) · Pengadaan`, `Baris PO Terbuka · Pengadaan`, then the server groups
    (Pesanan Pembelian, Item, Tiket Layanan) — word-start matching, so `La-po-ran Harian` does
    not surface; `PO/2026` (typed key by key) → no screen, `PESANAN PEMBELIAN: PO/2026/III/0002 |
    PO/2026/II/0001`, so Enter still opens the document; `laporan` → 3 screens; `zz` → `Tidak ada
    hasil untuk "zz".`.
  - **Probe** (`<scratchpad>/ux/t25-probe.py`, `t25-recent3.py`, `t25-final-probe.py`; not
    committed; admin 1440 × 900, site-manager 1440 and 390 × 844 touch): fresh admin `stored_nav`
    null, all 14 groups `data-open=false` except Ringkasan, 122 stars (one per row), 0 on;
    `.nav-divider` computed font-size **11 px** (T2.10 floor). Click Proyek header → stored
    `["Ringkasan","Proyek"]`. Hover `Opname Owner (OPN)` → star opacity 1, `aria-label` `Tandai
    sebagai Favorit`, `aria-pressed` false, 26 px; click → group **Favorit** appears first with 1
    link, stored `["Ringkasan","Proyek","Favorit"]` (`ensureGroupOpen`), `nusantara_erp_fav:1`
    `["r/projects/progress-measurements"]`, 2 stars on (Favorit row + Proyek row), focus on the
    Proyek row's star (`.star.on`); un-star from the Favorit group → group gone, 0 on. Open
    `#/d/procurement/purchase-orders/1` → **Terakhir dibuka** appears above Ringkasan with
    `PO/2026/II/0001` (title attr `PO`), `.active`; after project → customer → PO the group reads
    `PO/2026/II/0001`, `CUST-0001`, `PRJ-2026-001`; seven documents in a row keep the newest
    **5** (`RECENT_MAX`), a 404 (`procurement/rfqs/1`) is **not** recorded; custom screens now
    give `RKK/2026/VIII/0001`, `TKD/2026/VIII/0001`, `PYR/2026/03/001`, ticket title (custom.js
    `pageHead` uses the title). Reload → Favorit 1 / Terakhir dibuka 3 / Ringkasan / Proyek /
    Pengadaan open, the rest collapsed. Mobile drawer (390 px, `pointer: coarse`): star opacity
    .45, **34 × 32** px, drawer 788 px, `data-label` rows of T2.10 unaffected. Site-manager fresh:
    7 groups / 45 links, 1.0. **0 `pageerror`** in every context.
  - Regressions (`out-t25-final`, fresh seed, `S3 S4 S5 S6 S8 S11 S14`): **S3 12 klik** with
    `nav_group_opened` **true** — on a fresh profile Pengadaan is collapsed, so the first click
    opens it (T2.6: 11; see Notes), `toast_on_422` `['Periksa isian yang ditandai.', …]`, landing
    `#/d/procurement/purchase-orders/3`, `PO/2026/IX/0003`, `Diajukan`, `detail_action_bar`
    `['Kembali', 'Cetak', 'Ubah', 'Ajukan']`, `bar_after_submit` `['Kembali', 'Cetak']`, 15 162 ms;
    **S4** modalVisible false, loginVisible true, recoveryOffer true, 13 field / 3 baris restored,
    8 klik; **S6** `taps_to_lapangan` **3** (`group_opened` true; HASIL-UJI: 2 — Proyek is
    collapsed in a fresh drawer, `linkVisible` false before the tap; after the preference persists
    it is 2 again), h1 `Lapangan`, 1 big button; **S8** 5.23 / 5.47 / 5.29, `smallest_font_px` 11,
    `th_font` 11px; **S11** h1 `Tugas Saya`, **2 klik** (Ringkasan is open by default, no group
    click), 5 rows, `leave_detail_bar` `['Kembali', 'Cetak', 'Setujui', 'Tolak']`; S5 in that
    run 10 / 11 (finance hit the login throttle — Notes), the S5-only re-run above is 11 / 11.
  - PHPUnit: `tests/Feature/Core` **OK (572 tests, 3 521 assertions)** (T2.7: 567 / 3 445 — the 5
    new `SidebarNavWiringTest` tests: captions in order per group, no caption over an empty
    block, the 20 + 20 routes of Proyek/Keuangan unchanged, one shared `visibleNav` import in
    app.js and search.js, and the refused half); `tests/Feature/Crm` OK (214 / 773);
    `NavRouteRegistryTest` + `CrossModuleSpaWiringTest` + `PrintFormReachabilityTest` +
    `TenderSpaWiringTest` + the new file: OK (24 / 470, 21 / 398 after the tender.js fix).
    `pint --test --dirty` passed.
- Notes:
  - **One extra click on a fresh profile, by design of (b).** Collapsed-by-default means the
    first visit to a screen outside Ringkasan costs a group click (S3 11 → 12, S6 2 → 3 taps);
    the preference persists (`NAV_STATE_KEY`) so the second visit costs what it did, and the
    star turns any daily screen into a one-click link above the fold. The harness counts that
    click (`nav_click()` reports `nav_group_opened`) rather than opening the group silently — the
    Verification table's "Create→submit PO" row should read 12 on a fresh profile / 11 with a
    saved preference, and the metric that this task targets (sidebar height) is the one that moved.
  - **`null` vs `[]` in `NAV_STATE_KEY`.** Before, `[]` (every group closed by hand) reloaded as
    "everything open" (`openGroups.size ? … : true`); now `null` alone means "no preference" and
    `[]` means exactly what was done. A stored list wins as before; a shortcut group that gains
    its first item is added to a stored list (`ensureGroupOpen`) so a first star never lands in a
    collapsed Favorit.
  - **Favorit / Terakhir dibuka are keyed per user id** (`nusantara_erp_fav:<id>`,
    `nusantara_erp_recent:<id>`), unlike `NAV_STATE_KEY`: the site-office tablet is shared.
    Favorites are stored as routes and resolved against `visibleNav()` at render, so a starred
    screen whose permission is revoked disappears without editing the list; recents are filtered
    by the resource's read permission the way the `d/*` route is. Both groups render only when
    non-empty — an empty "Favorit" would be one more heading for the new user whose "banyak
    sekali" is the measured problem; the star on every row is the discovery.
  - **Recents label = breadcrumb.** `project.js`, `rfq.js` and `tender.js` never replaced the
    router's `#id` crumb (detail.js, custom.js and kaskecil.js do) — so the first cut recorded
    `Proyek #1`, `RKK #1`, `Lembar TKDN #1`. Each now fills the crumb with its code in one line
    (a breadcrumb that reads `PRJ-2026-001` instead of `#1` is the same fix for the user); no
    other change in those screens. A screen that never drew its `.page-head` (404, error) is not
    recorded.
  - **`visibleNav(can)` moved to schema.js**, not duplicated: search.js needs the same
    group/item permission rule and a second copy would drift (the test pins that neither file
    maps NAV on its own). The function contains no `route: '` literal — `NavRouteRegistryTest`
    and the two SPA wiring tests scan everything after `export const NAV = [`.
  - **Layar matching is word-start**, ranked label-start first, capped at 8: Enter opens the
    first hit, so substring noise (`po` → `Laporan`) would send a typed document code to the
    wrong screen. A full code never word-matches a label, so the document stays first.
  - **S5 / the login throttle.** `iam/auth/login` is `throttle:10,1`; S5 logs 11 roles in ~30 s
    and one of them (teknisi, sales, finance — whichever falls 11th inside the window) got a 429
    whose toast expired before the 15 s wait ended, the POST never reaching the php -S log.
    S5 never ran on this box before (the Phase 0 gate was `S10 S1 S2 S3 S4 S11 S8`).
    `login()` now waits `Retry-After` + 1 s and clicks again (once: 37 s in the S5-only run,
    64 s total); no scenario's `_clicks` includes it.
  - **S14 typed before focus** on its first run: `openSearch()` focuses the input 30 ms after the
    modal appears and `keyboard.type` had already fired — `press_sequentially` on the input now.
  - Harness S6 read a zero rect for the hidden Lapangan link (`visibleWithoutScroll` true by
    accident); it now reports `linkVisible` and uses `nav_click()`.
  - T2.6's PROGRESS placeholder now reads 424acb0.
  - Environment: fresh scratch seed `<scratchpad>/ux/t25.sqlite`
    (`DB_DATABASE=<scratch>/ux/t25.sqlite php artisan migrate:fresh --seed --force`, re-seeded once
    before the final run; config not cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t25.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    runs `ERP_DB=<scratch>/ux/t25.sqlite UXTEST_OUT=<scratch>/ux/out-t25 … S3 S5 S6 S8 S11 S14`
    (first cut), `… out-t25b … S5 S14`, `… out-t25-final … S3 S4 S5 S6 S8 S11 S14` (re-seeded),
    `… out-t25-s5 … S5`, `… out-t25-s5b … S5` (throttle-aware `login()`). Server stopped by PID
    (`pgrep -f '^php -S 127.0.0.1:8000'`); `database/database.sqlite` untouched (mtime 13:10:55,
    no `-wal`/`-shm`).

### T2.9 — Lapangan: bilah kemajuan per foto (XHR `upload.onprogress`) + antrean kirim ulang di localStorage
- Commit: 6fa94e6 (placeholder `(this commit)` replaced in the T2.11 commit — one task, one commit)
- Files: `public/app/js/api.js` (`settle()` — the response half of `request()`, moved verbatim so
  the two transports read a 204 / 401 / 422 in one place; `requestWithProgress()` — the ONE
  `XMLHttpRequest` in the client, same headers, `upload.progress` → `onProgress({ loaded, total })`,
  error/abort/timeout → the same `ApiError(0, 'Tidak dapat terhubung ke server.')`; `api.upload(path,
  body, onProgress)`; everything else stays fetch), `public/app/js/views/lapangan.js` (queue section
  — `QUEUE_PREFIX = 'nusantara_erp_upload:'`, per-user key `<prefix><userId>:<key>`, `readQueue()`
  once per user from localStorage, `persist()` best-effort with `item.persisted`, `forget()`,
  `listen()`/`notify()` with `change · progress · sent · mounted` events, `enqueue()` / `retry()` /
  `pump()` sending one photo at a time, `stateLine()` `Menunggu posisi GPS… · Menunggu giliran. ·
  Mengirim… N % · Menunggu jawaban server… · Belum terkirim — <sebab>`, `queueRow()` with
  `role=progressbar` + `aria-valuenow`, "Kirim ulang" / "Buang" (confirmDialog naming the
  consequence), `queueRows()` self-repainting, `pendingCard()` "Foto belum terkirim" for documents not
  on screen; `captureCard(slug, id, label)` keeps one queue node across `load()` and re-fetches on
  `sent`; the shoot button no longer goes busy; `renderHarian` / `renderTiket` pass the document
  code and `notify('mounted')`; `renderLapangan` mounts `pendingCard()` above the tabs, hidden while
  empty), `public/app/app.css` (`.upload-queue`, `.upload-item`, `.upload-warn`, `.upload-pending`,
  ≤ 480 px: actions wrap under the name), `tests/Feature/Core/LapanganUploadQueueTest.php` (new,
  4 tests — exactly one `new XMLHttpRequest(` in api.js and it is the upload path, two `await fetch(`;
  lapangan.js posts through `api.upload(` and never `api.post('core/attachments'`; queue prefix and
  drafts prefix never prefix each other; lapangan `MAX_BYTES` = `AttachmentService::MAX_BYTES`),
  `docs/bukti-uji/harness-playwright.py` (`JPEG_SEED` — a 691-byte 8×8 GD JPEG — and
  `padded_jpeg()` growing it with COM segments; new **S15_lapangan_upload**),
  `docs/bukti-uji/s15-unggah-kemajuan-t2.9.png`, `docs/bukti-uji/s15-kirim-ulang-t2.9.png`,
  `docs/PROGRESS-UX-PROSES.md` (T2.5 placeholder → a5b614f).
- Acceptance:
  - **Harness S15** (site-manager, 390 × 844 touch, context position −6.2 / 106.8 ± 12 m; run
    `<scratchpad>/ux/out-t29-final`, fresh seed): `report_created` true (the day's first
    "Buat laporan hari ini", Kegiatan typed — the server's `Kegiatan wajib diisi.` rule);
    **throttled upload** (CDP `uploadThroughput` 200 kB/s + `context.route` holding the POST 1,5 s
    before `continue_()`), 1 MB JPEG: **68 samples, 68 distinct percentages, first 0 → last 100**,
    texts `1.0 MB · Mengirim… N %` then `1.0 MB · Menunggu jawaban server…`, 9 784 ms, toast
    `Foto uji-1mb.jpg terkirim dengan lokasi.`, `photos_after` 1, `queue_rows` 0, `stored_keys` 0
    (screenshot `s15-unggah-kemajuan-t2.9.png`: bar at 26 %); **network dropped**
    (`route.abort('connectionfailed')`): row `state failed`, `1.0 MB · Belum terkirim — Tidak dapat
    terhubung ke server.`, buttons `['Kirim ulang', 'Buang']`, `stored_keys` **1**, no toast
    (`s15-kirim-ulang-t2.9.png`); **after `page.reload()`**: the same row, same text, same buttons
    (localStorage); **retry** (route removed, click Kirim ulang): toast `Foto uji-putus.jpg
    terkirim dengan lokasi.`, `queue_rows` 0, `photos_after` **2**, `stored_keys` 0, newest photo
    `uji-putus.jpg · lokasi dari perangkat · ±12 m · hari ini · 2.8 km dari lokasi`; **0
    pageerror**, **4 klik** (Buat laporan · Ambil foto · Ambil foto · Kirim ulang), 23 249 ms. First
    full run (`out-t29`) identical apart from timing: 68 samples / 67 distinct / 10 326 ms.
  - Transport probe that shaped S15 (`<scratchpad>/ux/t29-probe.py`, raw XHR of a 1,4 M-char body
    from the logged-in page): loopback → **1** progress event, at 100 %, 409 ms; `context.route`
    delay 3 s alone → still 1 event, fired at 3 364 ms, i.e. only after `continue_()` (Chromium
    counts the body after the interception resumes); CDP throttle 200 kB/s → **70 events / 7 123
    ms**; both → 71 events, first at 3 498 ms; `route.abort` → `onerror`, status 0, no event; 503 →
    status 503 with the JSON body; `set_offline` → status 0. localStorage quota on this Chromium:
    **5 234 375** ASCII chars. Server accepts the COM-padded JPEG: 201, `image/jpeg`, `geo_source`
    device.
  - Probe of what S15 does not measure (`t29-probe2.py`, mobile 390 and desktop 1440, not
    committed): pending card `hidden` at start; failed row on 390 px: main 328 px wide, `Kirim
    ulang` **108 × 36**, `Buang` 75 × 36, wrapped under the text; on 1440: 28 px tall inline, main
    913 of 1 112 px. Date switched to 3 Sep (no report): **"Foto belum terkirim" shown** with
    `DRP/2026/09/0004 · 300 KB · Belum terkirim — Tidak dapat terhubung ke server.` + Kirim ulang ·
    Buang, document rows 0; Kirim ulang from there → toast, card hidden again, 0 keys; back to
    today → `gagal-A.jpg` listed on the report. Buang → dialog `Buang foto ini?` / `gagal-B.jpg
    dibuang dari antrean dan tidak dapat dikirim lagi dari sini.` / `Batal · Buang`, focus on Batal;
    confirmed → 0 rows, 0 keys, 0 modals. 4 MB photo (5,6 M chars > quota): row `4.0 MB · Menunggu
    jawaban server…` + `Tidak muat disimpan di peramban: bila halaman ini ditutup sebelum
    terkirim, foto harus diambil lagi.`, `stored` 0, and it still lands (toast, 0 rows). Token
    revoked in sqlite mid-run (S4's move) then a photo: login screen with `Sesi Anda berakhir.
    Silakan masuk kembali.`, key still stored (1); after re-login the row reads `Belum terkirim —
    Sesi berakhir — masuk lagi, lalu kirim ulang.` → Kirim ulang → sent, 0 rows / 0 keys. **0
    pageerror** in both contexts.
  - Regressions on the refactored `settle()` (`out-t29-reg`, `S10 S1 S4 S6`): **S10** `po_422`
    `Vendor wajib diisi.` / `Tanggal PO wajib diisi.` / `Kuantitas minimal 0.001.` / `Harga satuan
    wajib diisi.` (Indonesian, unchanged); **S1** card `Menunggu persetujuan Anda (4)`, 4 types
    visible; **S4** banner `Sesi Anda berakhir. Isian PO yang sedang Anda buat tersimpan …`,
    `modalVisible` false, `loginVisible` true, 13 field / 3 baris restored, 8 klik, 15 357 ms;
    **S6** `taps_to_lapangan` 3 (`group_opened` true, as since T2.5), h1 `Lapangan`, 1 big button.
  - PHPUnit: `LapanganUploadQueueTest` red first on a5b614f (**3 of 4 fail**: no XHR, `api.post`,
    no `QUEUE_PREFIX`; the `MAX_BYTES` mirror already held), green on the tree (4 / 14);
    `tests/Feature/Core` **OK (576 tests, 3 535 assertions)** (T2.5: 572 / 3 521).
    `pint --test --dirty` passed. Module syntax checked by importing `api.js`, `views/lapangan.js`,
    `drafts.js` in Chromium (`t29-syntax.py`): 0 pageerror.
- Notes:
  - **S15, not S12.** The task text names the scenario "S12"; S12–S14 were taken by T2.4 / T2.3 /
    T2.5, so the harness gains **S15** (registered with the mobile-context signature of S6).
  - **Why CDP throttling and not only `context.route` delay.** Measured above: under Playwright
    interception Chromium raises `upload.progress` only after `continue_()`, in one jump to 100 %
    — a route delay alone shows a bar stuck at 0 % and then the "Menunggu jawaban server…" state,
    never a moving bar. S15 keeps the RECAP's route delay for the held-response state and adds
    `Network.emulateNetworkConditions` for the movement; both numbers are in the result.
  - **Photo listed before the GPS fix.** The old flow awaited `readAsBase64` and `devicePosition()`
    together under `withBusy` — up to 12 s (`GEO_TIMEOUT_MS`) of spinner. Now the row appears as
    soon as the file is read (`state 'locating'`, "Menunggu posisi GPS…") and is released to the
    queue when the position resolves; the stored position is the capture-time one, which is what
    `AttachmentService::geotag()` asks. S15 never shows this state because the context position
    resolves instantly.
  - **No automatic resend on load** — after a page closed mid-send the server may or may not have
    the photo; a silent resend would duplicate it. The row says what happened (`Terputus sebelum
    jawaban server tiba.` / `Halaman ditutup sebelum foto dikirim.`) and the person taps Kirim ulang.
    Sends are sequential (one XHR at a time): the uplink is shared and one moving bar is honest.
  - **No toast on failure.** The row is the feedback and it stays; the old toast vanished with the
    photo. The 401 row names the way out instead of the server's `Unauthenticated.`.
  - **localStorage is the ceiling.** 5,2 M characters on Chromium (measured), so one photo up to
    ~3,7 MB persists, or a few small ones; a bigger one still uploads (the JSON route takes 7 M
    chars) but the row carries the warning. IndexedDB would lift this; the RECAP names
    localStorage and the drafts idiom, so this stays a documented limit, not a decision.
  - **Per-user keys** (`<prefix><userId>:<key>`), like T2.5's Favorit: the site-office tablet is
    shared and a resend by another person would carry the wrong `uploaded_by`.
  - **`settle()` extraction** is the only change to `request()`: the same lines moved so the XHR path
    cannot drift from fetch on 401 handling or the Indonesian fallbacks (S10 / S4 above).
  - Observed, not touched: `newReportCard` reports a 422 through `toastError` directly, so its toast
    still shows the raw key (`activities: Kegiatan wajib diisi.`) — T2.1 mapped the form.js path
    only; Kegiatan also carries no `*`. Outside T2.9.
  - Environment: fresh scratch seed `<scratchpad>/ux/t29.sqlite`
    (`DB_DATABASE=<scratch>/ux/t29.sqlite php artisan migrate:fresh --seed --force`, re-seeded
    before `out-t29` and `out-t29-final`; config not cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t29.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    runs `ERP_DB=<scratch>/ux/t29.sqlite UXTEST_OUT=<scratch>/ux/out-t29 … S15`,
    `… out-t29-reg … S10 S1 S4 S6`, `… out-t29-final … S15`. Server stopped by PID
    (`pgrep -f '^php -S 127.0.0.1:8000'`); `database/database.sqlite` untouched (mtime 13:10:55,
    no `-wal`/`-shm`).

### T2.11 — Kartu "Menunggu persetujuan Anda" dan tautan Tugas Saya hanya bagi pemegang izin `.approve` (bagian minimal)
- Commit: 91376e3 (placeholder `(this commit)` replaced in the Gate Phase 2 commit — one task, one commit)
- Files: `public/app/js/schema.js` (`ANY_APPROVE` — `(held) => held.some((one) => one.endsWith('.approve'))`,
  exported above `NAV`; the `Tugas Saya` item carries `perm: ANY_APPROVE`, so `visibleNav()` hides it
  for both consumers — the sidebar in app.js and the "Layar" source in search.js — without touching
  either), `public/app/js/api.js` (`session.can()` accepts a function and asks it with the list held;
  arrays and strings unchanged), `public/app/js/views/dashboard.js` (the `core/inbox` request and the
  approvals card — with its `Tugas Saya` / `Lihat semua` buttons — both behind
  `session.can(ANY_APPROVE)`; the card block re-indented, `git diff -w` shows the four real changes),
  `tests/Feature/Core/ApprovalInboxGateTest.php` (new, 3 tests — the server truth the predicate rests
  on, and the served-JS pins), `docs/bukti-uji/harness-playwright.py` (S1 gains the warehouse half:
  `approvals_card`, `tugas_link`, `cards`, `approve_perms` read from the page's own localStorage, and
  `dashboard_api_calls` per open for both logins — `CARD_AND_LINK`, `after_login()`),
  `docs/bukti-uji/s1-warehouse-dasbor-t2.11.png`, `docs/PROGRESS-UX-PROSES.md` (T2.9 placeholder →
  6fa94e6).
- Acceptance:
  - **Harness S1** (`<scratchpad>/ux/out-t211`, fresh seed `t211.sqlite`): **warehouse** —
    `approve_perms` `[]`, **`approvals_card` false, `tugas_link` false**, `cards` `['Kalender Acara',
    'Progres proyek']`, **`dashboard_api_calls` 6** (`core/notifications/unread-count`, `iam/auth/me`,
    `projects?per_page=100`, `inventory/stock/low-stock`, `core/calendar`, `core/dashboard/summary` —
    no `core/inbox`); **direktur** — `approvals_card` **true**, `tugas_link` **true**, card
    `Menunggu persetujuan Anda (4)` with 4 rows (`CTI/2026/VIII/0002`, `RAP/2026/0001`,
    `PR/2026/III/0002`, `SPK/2026/III/0002`) against 4 server types (`estimation/cost-budgets`,
    `procurement/purchase-requisitions`, `subcontract/subcontracts`, `hr/leave-requests`, 1 each),
    14 `.approve` permissions, `dashboard_api_calls` **11** (the 2 Sep "After patch" figure); 0 klik,
    9 859 ms, 0 `ERROR`. **Before** (same harness on 6fa94e6, `out-t211-before`): warehouse
    `approvals_card` **true**, `tugas_link` **true**, `approve_perms` `[]`, cards
    `['Menunggu persetujuan Anda', 'Kalender Acara', 'Progres proyek']`, `dashboard_api_calls` **7**
    (`core/inbox` among them); direktur identical to after.
  - Same-predicate probe (`<scratchpad>/ux/t211-probe.py`, not committed): warehouse — Ringkasan links
    `Dasbor · Tenggat · Kalender · Lokasi Tapak`, Ctrl+K "tugas" → `Tidak ada hasil untuk "tugas".`,
    direct `#/tugas` → h1 `Tugas Saya`, 0 rows, empty state `Kotak masuk kosong — Tidak ada dokumen
    yang menunggu keputusan Anda.`; direktur — `Dasbor · Tugas Saya · Tenggat · Kalender`, Ctrl+K
    "tugas" → `LAYAR Tugas Saya · Ringkasan`, direct `#/tugas` 4 rows; **0 pageerror** for both.
  - Regressions (`out-t211-reg`, S11 run first because CTI heads this seed's inbox and S2 approves the
    head): **S11** h1 `Tugas Saya`, 4 rows, `leave_detail_bar` `['Kembali','Cetak','Setujui','Tolak']`,
    2 klik, 4 376 ms; **S2** 4 klik, `action_bar` `['Kembali','Cetak','Setujui','Tolak']`,
    `approve_modal_opened` false, `api_calls_detail_to_back` 14 (CTI-first count, as in Gate Phase 0),
    `approve_total_ms` 2 290, `detail_ms` 1 267; **S14** "opname" → 5 Layar hits, Enter opens
    `#/r/projects/progress-measurements` `Opname Owner (OPN)`.
  - PHPUnit: `ApprovalInboxGateTest` **OK (3 tests, 12 assertions)** — a user with warehouse's bundle
    (`inv.*` + `prj.view`) gets `core/inbox` `meta.total 0`, `data []` while the same submitted PR is
    listed for a `prc.approve` holder; `prc.approve-director` alone gets `total 0`; schema.js /
    dashboard.js / api.js carry the predicate. Red first on 6fa94e6 (the three JS files stashed, test kept): the served-JS pin fails, the two
    server tests pass — the server was already right, the client was not. `tests/Feature/Core` **OK (579 tests, 3 547 assertions)** (T2.9: 576 / 3 535).
    `pint --test` on the new file passed (no other PHP touched).
- Notes:
  - **Minimal by design.** The RECAP marks the full T2.11 (tiles per role) P2, gated on research H1;
    this commit does exactly the two sentences under "Minimal now". Nothing replaces the card for
    procurement / hr — their dashboard is now Kalender Acara alone, which is what T4.1 is for.
  - **Why `.endsWith('.approve')` and not the director signature.** `ApprovalQueue::pending`
    (`ApprovalQueue.php:52-53`) keeps a document type only when the caller holds
    `<awalan>.approve`; `prc.approve-director` / `scm.approve-director` are the second-level ladder
    checked inside `Approvable::approve`, never by the inbox filter — a holder of the director
    permission without `.approve` gets an empty inbox (pinned by the second test). Counting it would
    show a card that is empty forever, the very thing the task removes. Of the 12 seeded roles, 8
    hold no `.approve` (site-manager, estimator, procurement, warehouse, finance, hr, sales,
    teknisi); on 2 Sep (S5 › cards) all 11 demo logins saw the card.
  - **One predicate, three places.** `session.can()` now accepts a function — the smallest contract
    extension that lets a NAV item be gated by the *shape* of a permission through the existing
    `visibleNav(can)` path, so the sidebar, the Ctrl+K "Layar" source and the dashboard all read
    `ANY_APPROVE`; no list of 14 module prefixes is mirrored into the SPA.
  - **The request goes too.** With the card gone the `core/inbox` call would have been a wasted
    request; it sits behind the same predicate (warehouse 7 → 6 per dashboard open). For approvers
    nothing changes (direktur 11 before and after).
  - **`#/tugas` by URL is not gated.** The entry names the card and the link; the screen answers a
    URL-typing warehouse user honestly (`Kotak masuk kosong`, 0 rows) and there is no `accessDenied`
    variant for a shape-of-permission gate — adding one is outside the entry's steps.
  - **Harness login budget.** S1's warehouse half reads permissions from `localStorage
    nusantara_erp_user` instead of a second `token_for()`: `iam/auth/login` is `throttle:10,1`, and
    S10 + S1 + S2 + S3 + S4 already spend 9 logins in the first minute of a full run; the warehouse
    browser login makes it 10, the maximum — S4's re-login is a manual fill + click with no 429
    retry, so an eleventh would break S4.
  - `dashboard_api_calls` is the number the RECAP row "Dashboard API calls per open" never had a
    scenario for; it counts every `/api/` request after the last `iam/auth/login` POST (the
    `iam/auth/me` refresh and the bell count included), which reproduces the 2 Sep figure (11).
  - Environment: fresh scratch seed `<scratchpad>/ux/t211.sqlite`
    (`DB_DATABASE=<scratch>/ux/t211.sqlite php artisan migrate:fresh --seed --force`; config not
    cached), served with
    `cd public && DB_DATABASE=<scratch>/ux/t211.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    runs `ERP_DB=<scratch>/ux/t211.sqlite UXTEST_OUT=<scratch>/ux/out-t211-before … S1` (app at
    6fa94e6), `… out-t211 … S1`, `… out-t211-reg … S11` then `… S2 S14`. `database/database.sqlite`
    untouched (mtime 13:10:55, no `-wal`/`-shm`).

### Gate Phase 2
- Commit: 2649f55 — `docs/bukti-uji/results-phase-2.json` (the run's `results.json`, verbatim),
  RECAP § Verification column "After phase 2 (4 Sep)" (+ two rows for T2.10 / T2.11), this block;
  T2.11 placeholder → 91376e3. No code changed in this pass.
- Acceptance: `harness-playwright.py` **S10 S1 S2 S3 S4 S5 S8 S11** on a fresh scratch seed
  (`<scratchpad>/ux/t211g.sqlite`, tree 91376e3) → **8 scenarios `ok`, 0 `ERROR`**, 98 s of
  scenario time. Two invocations into one `results.json` (the harness merges): **`S10 S1 S11`** first,
  then **`S2 S3 S4 S5 S8`** after the login-throttle window — because on this seed
  `CTI/2026/VIII/0002` heads the inbox (`ApprovalQueue` sorts by `submitted_at` ascending; RAP and
  CTI have no submit row and the HR seeder wrote its row a second before Estimation's, so the
  head is decided by a second boundary — Gate Phase 0 note 1), S2 approves the head, and S11's
  `tr:has-text('CTI/')` would find nothing after it. S11 measured before any approval is the same
  reading `results-sesudah.json` carries (its S11 still listed RAP). Headline numbers:
  - **S2** 4 klik for two approvals = **2 per document** (baris → Setujui, Buka → Kembali),
    `approve_modal_opened` false, toast `CTI/2026/VIII/0002 disetujui.` + `Berikutnya menunggu Anda
    (3) RAP/2026/0001 …`, `action_bar` `['Kembali','Cetak','Setujui','Tolak']`,
    `api_calls_detail_to_back` **14** (leave-request detail; 16 on a subcontract), `approve_total_ms`
    2 039, `detail_ms` 1 150; **po_bar** as direktur on a submitted PO: 4 buttons, zones
    `[['Kembali'],['Cetak'],['Setujui','Tolak']]`, menu `Cetak halaman · Unduh PDF · Cetak Pesanan
    Pembelian (Formulir Rumah) · Unduh … (XLSX)`, Escape → menu closed, focus back on the trigger.
  - **S5** admin `navContentPx` **606 = 0,7 viewport** (`viewportsTall` 1.4 = grid floor 1 233 px),
    14 groups / 122 links; direktur 606 / 120; PM 451 / 63; site-manager 357 / 44; estimator 357 /
    47; procurement 326 / 35; warehouse 326 / 35; finance 357 / 67; hr 233 / 14; sales 357 / 43;
    teknisi 233 / 16 (each non-approving role one link fewer than at T2.5: Tugas Saya). **`cards`:
    "Menunggu persetujuan Anda" for admin, direktur, project-manager only — 3 of 11**; 2 Sep: 11 of
    11 (`results-sesudah.json`). One `429` on estimator's login, absorbed by the harness's
    Retry-After wait (2 s).
  - **S3** **12 klik** on a fresh profile (`nav_group_opened` true — T2.5's collapsed Pengadaan
    group; 11 once the preference is saved), `toast_on_422` `['Periksa isian yang ditandai.']`,
    cell `Kuantitas minimal 0.001.`, `PO/2026/IX/0004` (S2's po_bar created 0003), `Diajukan`,
    toasts `PO dibuat.` / `PO/2026/IX/0004 diajukan · menunggu persetujuan.`, `detail_action_bar`
    `['Kembali','Cetak','Ubah','Ajukan']`, 18 API calls, 15 281 ms.
  - **S4** 13 field typed, banner `Sesi Anda berakhir. Isian PO yang sedang Anda buat tersimpan di
    peramban ini — masuk kembali untuk memulihkannya.`, `modalVisible` false, `loginVisible` true,
    Masuk `reachable`, `recoveryOffer` true, restored **13 field / 3 baris** (vendor `VND-0003 — PT
    Elektrindo Supply`, textarea intact), 8 klik.
  - **S1** direktur card `Menunggu persetujuan Anda (4)` = 4 server types (RAP, PR, SPK, CTI),
    `tugas_link` true, 11 dashboard requests; **warehouse `approvals_card` false, `tugas_link`
    false**, cards `Kalender Acara · Progres proyek`, 6 requests.
  - **S10** `Vendor wajib diisi.` / `Tanggal PO wajib diisi.` / `Kuantitas minimal 0.001.` /
    `Harga satuan wajib diisi.`; `Nama wajib diisi.`; AP bill `… wajib diisi bila tidak ada satu pun
    dari PO / GRN / …` — 0 English strings.
  - **S11** h1 `Tugas Saya`, 4 rows (CTI, RAP, PR, SPK), `leave_detail_bar`
    `['Kembali','Cetak','Setujui','Tolak']`, 2 klik.
  - **S8** `--muted #5e6874`, contrast **5.23 / 5.47 / 5.29**, `th_font` 11px, `smallest_font_px`
    **11**.
  - PHPUnit on the final tree (per directory, every module touched in phase 2): `tests/Feature/Core`
    **OK (579 tests, 3 547 assertions)**; `tests/Feature/Iam` **OK (45 tests, 215 assertions)**; `tests/Feature/Procurement`
    **OK (165 tests, 651 assertions)**; `tests/Feature/Crm` **OK (214 tests, 773 assertions)**.
- Notes:
  - **Missed targets and who closes them:** *API calls per approval round-trip* 14–16 vs ≤ 12 — the
    count is the detail page's own loads (a leave request 3, a subcontract 5, plus print catalogue,
    attachments, lookups); T3.3 (approvals in `show()`) removes nothing here, so this needs a detail
    page that stops loading lookups it does not render — not in the backlog yet, note for T4.x.
    *Dashboard API calls per open* 11 vs ≤ 10 for approvers (non-approvers are at 6 since T2.11) —
    the eleventh is `iam/auth/me` permission refresh + `core/notifications/unread-count`; folding
    the bell count into `core/dashboard/summary` would do it, not assigned. *Create→submit PO* 12 vs
    ≤ 10 — T4.2 (full-page lines form), gated on research. Everything else meets its target;
    production rows were not measured (this pass never touches production).
  - The per-role link drop (−1 for eight roles) is T2.11's Tugas Saya gate, expected; admin,
    direktur and PM keep 122 / 120 / 63.
  - Environment: `DB_DATABASE=<scratch>/ux/t211g.sqlite php artisan migrate:fresh --seed --force`
    (config not cached); `cd public && DB_DATABASE=<scratch>/ux/t211g.sqlite APP_ENV=local php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`;
    `ERP_DB=<scratch>/ux/t211g.sqlite UXTEST_OUT=<scratch>/ux/out-phase-2 /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S10 S1 S11`,
    then (≥ 66 s after the first login) `… S2 S3 S4 S5 S8`. Server stopped by PID afterwards;
    `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`).

| Ukuran | Sesudah (2 Sep) | Fase 0 (4 Sep) | Fase 2 (4 Sep) |
|---|---|---|---|
| S10 · PO 422 `vendor_id` / `items.0.qty` | `Vendor wajib diisi.` / `Kuantitas minimal 0.001.` | sama | sama |
| S1 · judul kartu direktur / baris / jenis di server | `(4)` / 4 / 4 | `(4)` / 4 / 4 | `(4)` / 4 / 4 |
| S1 · warehouse: kartu persetujuan / tautan Tugas Saya / permintaan dasbor | (tidak diukur) | (tidak diukur) | **false / false / 6** |
| S2 · klik per dokumen | 3 | 3 | **2** |
| S2 · modal catatan | ya | ya | **tidak** (inline) |
| S2 · tombol bilah aksi (PO diajukan, direktur) | 5 | 5 | **4** |
| S2 · permintaan API detail → kembali | 16 | 14 | 14 |
| S3 · klik buat → ajukan PO 2 baris | 12 | 12 | 12 (11 dengan preferensi sidebar tersimpan) |
| S3 · toast saat 422 | `Periksa isian yang ditandai. · items.0.qty: …` | `items.0.qty: …` | **`Periksa isian yang ditandai.`** |
| S3 · modal "Alasan override" saat Ajukan | ya | ya | **tidak** (confirm-resubmit) |
| S4 · modal masih terbuka / halaman masuk / dipulihkan | false / true / 13 · 3 | false / true / 13 · 3 | false / true / 13 · 3 |
| S5 · admin: tinggi isi sidebar / viewport | 4 447 px / 4,9 | (tidak diukur) | **606 px / 0,7** |
| S5 · peran dengan kartu persetujuan (dari 11) | 11 | (tidak diukur) | **3** |
| S8 · kontras `--muted`/`--bg` · font terkecil | 5,23 · 10 | 5,23 · 10 | 5,23 · **11** |
| S11 · h1 / baris / bilah detail cuti | `Tugas Saya` / 5 / `Kembali · Cetak halaman · Setujui · Tolak` | `Tugas Saya` / 4 / sama | `Tugas Saya` / 4 / `Kembali · Cetak · Setujui · Tolak` |


---

## Phase 3

### T3.3 — Jejak persetujuan tampil di setiap dokumen Approvable: `approvals.user` di 23 `show()`, kunci `approvals` bentuk PaymentResource di 23 Resource
- Commit: (this commit) — also turns the Gate Phase 2 placeholder above into 2649f55.
- Files: controllers (`show()` loads `approvals.user`; the WHY comment at every site, the measured
  paragraph on the PR controller the evidence names) — `Crm/QuotationController`,
  `Engineering/IppController` (DETAIL const), `Estimation/BoqController`, `Estimation/CostBudgetController`,
  `Finance/ApBillController`, `Finance/ArInvoiceController`, `HrPayroll/PayrollRunController`,
  `Procurement/AwardDecisionController`, `Procurement/PurchaseOrderController`,
  `Procurement/PurchaseRequisitionController`, `Procurement/WorkOrderController`, `Projects/BaselineController`,
  `Projects/BastController`, `Projects/GatePassController`, `Projects/OvertimePermitController`,
  `Projects/ProgressMeasurementController` (`loaded()` helper), `Projects/WorkPermitController`,
  `Quality/InspectionController` (DETAIL const), `Subcontract/HandoverController`, `Subcontract/LaborClaimController`,
  `Subcontract/LaborContractController`, `Subcontract/ProgressClaimController`, `Subcontract/SubcontractController`;
  the 23 matching `Http/Resources/*Resource.php` (`'approvals' => $this->whenLoaded('approvals', …)` in
  exactly the `PaymentResource.php:90` shape — id, action, note, created_at ISO-8601, user {id, name} or
  null — inserted before `created_at`, where PaymentResource keeps it);
  `tests/Feature/Procurement/PurchaseRequisitionApprovalTrailTest.php` (new, 4 tests),
  `tests/Feature/Core/ApprovalTrailOnShowTest.php` (new, 8 tests — one document per module that gained
  the key); `docs/bukti-uji/s2-jejak-persetujuan-sebelum-t3.3.png`, `…-sesudah-t3.3.png`; this block.
- Acceptance:
  - `GET procurement/purchase-requisitions/{id}` → `approvals[]` with `action`, `user.name`, `note`,
    `created_at`: **`PurchaseRequisitionApprovalTrailTest` OK (4 tests, 22 assertions)** — red on the
    unpatched tree first (2 errors + 1 failure: `data.approvals` null; the fourth test, "index carries no
    trail", is green on both trees by design). Pins the submit row (name, null note, `2026-09-01T10:00:00+07:00`),
    the approve row (name, `Harga sesuai RAB.`, `2026-09-04T09:15:00+07:00`), the exact key order
    `id, action, note, created_at, user`, `user: null` for the seeded submit-as-nobody path, `approvals: []`
    for a draft (a card that says "Belum ada riwayat persetujuan." is the truth for a draft; a missing card
    is not), and `assertJsonMissingPath('data.0.approvals')` on the list (whenLoaded — no query per row).
  - One document per module: **`ApprovalTrailOnShowTest` OK (8 tests, 64 assertions)** — Crm quotation,
    Engineering IPP, Estimation BOQ, Finance AP bill, HR payroll run, Projects BAST, Quality inspection,
    Subcontract SPK, each answering the PaymentResource shape with the actor's name and the frozen date.
  - **Harness S2** on `PR/2026/III/0002` at the head of direktur's queue, same seed state on both trees:
    - before (tree 2649f55): `explanation_under_title` = **`Diajukan · menunggu persetujuan.`**; after
      Setujui, strip = **`PR ini terkunci (Disetujui).`** — no name, no date, no Riwayat Persetujuan card,
      although the `submitted` row (Administrator Sistem) and the `approved` row (Budi Santoso) sit in
      `core_approvals`.
    - after (this tree): `explanation_under_title` = **`Diajukan 04 Sep 2026 oleh Administrator Sistem ·
      menunggu persetujuan.`** (+ the same second sentence), `after.strip` = **`Disetujui 04 Sep 2026 oleh
      Budi Santoso · dokumen terkunci.`**, card **Riwayat Persetujuan** with `Diajukan — Administrator
      Sistem · 04 Sep 2026 17.37` (screenshot pair in `bukti-uji/`). Unchanged around it: **4 klik** for two
      documents (2 per document, T2.3), `approve_modal_opened` false, `action_bar`
      `['Kembali','Cetak','Setujui','Tolak']`, toast `PR/2026/III/0002 disetujui.` + `Berikutnya menunggu
      Anda (1) SPK/2026/III/0002 …`, `opened_next` `SPK/2026/III/0002`, **`api_calls_detail_to_back` 18 on
      both trees** — the trail rides on `show()`, no request was added (18 is the PR detail's own count; the
      Phase 2 figure 14 was a leave-request detail).
  - Suites on the final tree (per directory, every module touched, one core, sequential):
    `tests/Feature/Procurement` **OK (169 tests, 673 assertions)** (165 + 4 new); `tests/Feature/Core`
    **OK (587 tests, 3 611 assertions)** (579 + 8 new); `tests/Feature/Finance` **OK (818, 3 888)**;
    `tests/Feature/Subcontract` **OK (134, 473)**; `tests/Feature/Projects` **OK (345, 1 823)**;
    `tests/Feature/Crm` **OK (214, 773)**; `tests/Feature/Estimation` **OK (77, 373)**;
    `tests/Feature/HrPayroll` **OK (143, 527)**; `tests/Feature/Quality` **OK (51, 209)**;
    `tests/Feature/Engineering` **OK (44, 245)** — 2 582 tests, 0 failures.
  - `vendor/bin/pint --test --dirty` → passed.
- Notes:
  - **The RECAP grep is over-inclusive, and that changes the count.** `grep -l "Approvable" Modules/*/Models/*.php`
    yields 38 models / 31 controllers today, but 10 of those models match on a header comment that says
    the opposite — `TenderPackage`, `DrawingSubmittal` ("NOT Approvable, on a written decision"),
    `NegotiationMinute` ("SENGAJA TANPA Approvable"), `ProcurementPlan`, `Rfq`, `Defect`, `Ncr`, `HseDaily`,
    `SafetyIncident`, `ZoneCertificate` — none has an `approvals()` relation, so `load('approvals.user')`
    there throws. Proven, not assumed: the first draft of `ApprovalTrailOnShowTest` used SafetyIncident and
    NCR and errored with `Call to undefined method …::approvals()`. Those 20 files were reverted before the
    commit; the test walks BAST and an inspection instead. What remains is 21 from the grep + 2 the grep
    cannot see because their file names break the Model→Controller convention it assumes:
    `IppController` (`WorkPermitIpp` carries the trait) and `BaselineController` (`ProjectBaseline` keeps
    its own `approvals()` morphMany on `core_approvals`, like `Payment`, and has submit/approve/reject
    routes) — **23**, the number the RECAP wrote, and with the 5 that already loaded the trail exactly the
    28 of `ApprovableDocuments::all()`. The RECAP's `grep -L … intersected with ApprovableDocuments::all()`
    is the honest form; the bare grep in the prompt is not.
  - `InspectionController::DETAIL`, `IppController::DETAIL` and `ProgressMeasurementController::loaded()`
    are read by store/update/decision responses as well as by `show()`, so those payloads carry the key
    too — the same thing `PaymentController` does at every return. No other action's payload changed.
  - Resources already exposing approvals in another shape: none among the 23. `AwardDecisionResource`
    keeps `approvals_given` (the ladder's distinct-approver count) next to the new `approvals`;
    `IppResource.material_approvals` is material submittals, untouched. Rule 6: no test asserts the key's
    absence (`assertExactJson` / `assertJsonMissing` over `tests/Feature` — one hit, on `core/deadlines`,
    unrelated); every SPA reader — `detail.js` `statusStrip()` / `approvalTimeline()`, and the payroll-run
    and SPK cards in `custom.js`, whose comments already say "kartunya kembali sendiri begitu resource
    mengirim" — reads the PaymentResource shape.
  - Browser date on the strip: the harness's Chromium runs in UTC, so `04 Sep 2026` there is
    `2026-09-05T00:37:39+07:00` in the payload (`fmt.date` formats in the viewer's zone) — a display
    fact of the sandbox, not a data one; the Feature tests pin the `+07:00` ISO strings.
  - Environment, as Gate Phase 2: fresh scratch seed per run (`<scratchpad>/ux/t33a.sqlite` before,
    `t33b.sqlite` after; `DB_DATABASE=<scratch> php artisan migrate:fresh --seed --force`, config not
    cached), served with `cd public && DB_DATABASE=<scratch> APP_ENV=local php -S 127.0.0.1:8000 …/server.php`,
    harness `ERP_DB=<scratch> UXTEST_OUT=<scratch>/out-t33-{before,after} /root/.venv-playwright/bin/python
    docs/bukti-uji/harness-playwright.py S2`. Precondition replayed on both seeds before S2, the way Gate
    Phase 0 did: `POST hr/leave-requests/2/approve` and `POST estimation/cost-budgets/1/approve` as
    direktur (both 200), so that `PR/2026/III/0002` — the document HASIL-UJI P-4 names — heads the inbox
    (on a bare seed the head is `CTI/2026/VIII/0002`, whose controller loaded the trail already and whose
    seed wrote no submit row, Gate Phase 0 note 4 — S2 there measures nothing this task changed). Server
    stopped by PID after each run; `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`).
  - Phase 2 carry-overs not re-opened: per-approval API calls (14–18, detail-page loads), dashboard 11,
    PO clicks 12 — T4.x / unassigned.

### T3.1 — Pengawas jatuh tempo tagihan vendor (`ap_due`), `erp:deadline-watch --dry-run`, tombol "Buat pembayaran" pada tagihan yang disetujui
- Commit: (this commit)
- Files: `Modules/Core/Support/WatchedDeadlines.php` (one entry, `ap_due`, placed after `ar_invoice_due`;
  the formula is in its comment), `Modules/Core/Console/Commands/DeadlineWatchCommand.php` (`--dry-run`),
  `public/app/js/schema.js` (`finance/ap-bills` action `create-payment` "Buat pembayaran"),
  `public/app/js/views/actions.js` (`opens` + `prefill` on a header action — the rowAction shape lifted
  into `actionButtons`), `tests/Feature/Core/DeadlineWatchTest.php` (fixtures `vendor()`, `apBill()`,
  `settleBill()`; 5 new tests), `tests/Feature/Finance/ApBillPaymentButtonTest.php` (new, served-JS pin),
  `docs/bukti-uji/harness-playwright.py` (scenario **S16** `ap_bill_payment_button`, 20 lines),
  `docs/bukti-uji/s16-buat-pembayaran-t3.1.png`, this block.
- Acceptance:
  - Works/refused pair in `DeadlineWatchTest`, red first on the unpatched tree (`--filter 'ap_bill|dry_run|the_aging'`:
    **4 errors** — three `ItemNotFoundException` on `->sole()` (no alarm), one `The "--dry-run" option does not exist`;
    the "silent" test is green on both trees by design) and `ApBillPaymentButtonTest` **1 failure** (no
    `create-payment` in the ap-bills block). After: `DeadlineWatchTest` + `DeadlineApiTest` + `CalendarApiTest`
    **OK (74 tests, 260 assertions)** (DeadlineWatchTest 46 → 51); works = approved bill due 27 Jun 2026 → title
    `Tagihan vendor lewat jatuh tempo`, body `… senilai Rp 48,5 jt jatuh tempo 27 Jun 2026 — 35 hari lalu.`, link
    `r/finance/ap-bills`, delivered to the `fin.create` holder only (a `prc.update` holder gets nothing); lead 7 =
    due 5 Agu → `Tagihan vendor mendekati jatuh tempo` "4 hari lagi", due 9 Agu silent; refused = fully paid by a
    posted payment dated 1 Jul silent, while a partly paid bill, a bill settled by a POSTED giro dated 15 Agu
    (post-dated) and a bill "settled" by a DRAFT payment all alarm (`Total 3 tagihan.`) — and
    `ReportService::agingReport('ap')` run in the same test lists exactly those three codes; draft / submitted /
    cancelled (`cancelled_at`) / soft-deleted bills silent; `--dry-run` prints
    `ap_due [lewat]: 1 row(s) -> fin.create` + the body line and writes **0** `core_notifications`.
    `tests/Feature/Core` **OK (592 tests, 3 637 assertions)** (587 + 5); `tests/Feature/Finance`
    **OK (819 tests, 3 895 assertions)** (818 + 1); `vendor/bin/pint --test --dirty` passed.
  - `php artisan erp:deadline-watch --dry-run` on a fresh scratch seed (`<scratchpad>/ux/t31.sqlite`,
    `migrate:fresh --seed --force`): **before planting** `Checked 20 watcher(s), skipped 0, blind 1, raised 6 alarm
    group(s). Dry-run: tidak ada notifikasi dikirim.` and **no `ap_due` line** — the seed's only vendor bill
    `BIL/2026/III/0001` (Rp 232.545.000) is fully settled by `PAY/2026/IV/0001` on 2 Apr, so there is nothing an
    honest watcher could list. **Planted one bill in the shape of the production case** (tinker on the scratch
    file only): `BIL/2026/VII/0002`, vendor CV Baja Mandiri, approved, total Rp 48.500.000, bill date 28 Mei, due
    27 Jun 2026, nothing paid. **After planting**: `ap_due [lewat]: 1 row(s) -> fin.create` /
    `  BIL/2026/VII/0002 senilai Rp 48,5 jt jatuh tempo 27 Jun 2026 — 70 hari lalu.` / `… raised 7 alarm group(s).
    Dry-run: tidak ada notifikasi dikirim.` (70, not the RECAP's 69: the command's "today" is Asia/Jakarta and the
    run happened after 00:00 WIB on 5 Sep.)
  - Harness **S16** on the same scratch server (finance login, `#/d/finance/ap-bills/2`): `action_bar`
    **`[Kembali, Cetak, Buat pembayaran, Batalkan Dokumen]`**, `primary` `[Buat pembayaran]`, zones
    `[Kembali] · [Cetak] · [Buat pembayaran, Batalkan Dokumen]`; **1 klik** → modal `Tambah Pembayaran` with
    Arah **`out`** (Pengeluaran (PAY)), Tanggal `2026-09-04`, Jumlah **`48.500.000`** (= `outstanding`), Catatan
    `Pembayaran BIL/2026/VII/0002 — CV Baja Mandiri`, buttons `Batal · Simpan`; Escape closes it
    (`modal_closed_on_escape` true); 8 014 ms, 0 `ERROR`. The Cetak menu (`Cetak halaman · Cetak Lembar
    Verifikasi Tagihan`) still opens/closes as T2.6 measured. Screenshot `s16-buat-pembayaran-t3.1.png`.
- Notes:
  - **"Belum lunas" = the AP aging's definition, written out.** `ReportService::agingReport('ap')` derives the
    remainder from `OutstandingAsOf::settled` — allocations of POSTED, undeleted payments dated on or before the
    as-of day — and refuses `amount_paid` because it is a lifetime figure (a posted giro dated 15 Sep moved Rp 300 jt
    off the AR aging six weeks early on the demo, `OutstandingAsOf` header). The entry's scope is that same
    formula as one correlated subquery on `DB::table` (literals `'ap_bill'` = `PaymentAllocation::TYPE_AP_BILL`,
    `'posted'` = `PaymentStatus::Posted`, pinned by the test, never imported — rule 2): `total_payable >
    COALESCE(SUM(allocations of posted payments with payment_date ≤ today), 0)`. `ar_invoice_due` above it still
    reads `paid_at`; the RECAP asked for the aging's column for AP specifically, and the test runs both surfaces
    over the same four bills so they cannot drift. `whereDate` on `payment_date`, not a string `<=`: the column is a
    `date` cast stored `"… 00:00:00"`, the footgun the file's header describes.
  - **"Today" inside the scope.** Scope closures receive only the `Builder`; the as-of bound is
    `CarbonImmutable::today()`, the same clock `DeadlineWatchCommand` hands `scan()` (and `Carbon::setTestNow`
    drives it in tests). Changing every entry's closure signature to pass `$today` through would have been the
    refactor the prompt forbids.
  - **`--dry-run` is not in the entry's Files, but it is in its Acceptance** ("production dry-run lists
    BIL/2026/VII/0002") and in the Phase 3 gate; `erp:deadline-watch` had no such option (`erp:approval-watch` has
    had one since the 2 Sep patch). Added in the same shape: the per-finding line is printed in both modes, the
    dry-run adds the body (which rows) and skips `NotificationService::system`; the closing line is unchanged in
    normal mode and gains `Dry-run: tidak ada notifikasi dikirim.` otherwise. Pinned by the fifth test.
  - **The button opens a form, it does not POST.** `def.actions` in the header bar only knew POST actions; the
    "open another resource's create form with values I already know" shape existed solely as `rowAction` on
    detail tables ("Tagih termin ini"). `actionButtons` now honours `opens` + `prefill` with the same `openForm`
    call and navigates to the saved document, as `navigateTo` does for POST actions. The prefill is `direction
    'out'`, `amount = outstanding`, `notes` naming the bill and vendor — not the bill id: the payment form carries
    no allocations by design; the allocation (and any PPh withholding) is chosen on the payment screen at submit,
    where the approved bills are listed (`custom.js` openDocs). Copying an id the form cannot show would be the
    "typed id from memory" the termin rowAction was built to remove. `when` = approved and `outstanding > 0`
    (`ApBillResource` always carries `outstanding`), so a settled bill never offers a second payment.
  - **`value` quotes the bill total**, not the remainder (the body can only quote one column; the remainder is
    on the aging screen). A partly paid bill therefore reads "senilai Rp 48,5 jt" — the bill's value, true.
  - **Comment count in `scoped()`'s docblock** still says "eighteen scopes"; it was already stale (19 before this
    task, 20 now) and is outside the entry's scope — left as is.
  - Environment: scratch only (`DB_DATABASE=<scratchpad>/ux/t31.sqlite` for `migrate:fresh --seed --force`, the
    tinker plant, the dry-run and `php -S`; `ERP_DB`/`UXTEST_OUT` for the harness); server stopped by PID;
    `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`).
  - No "## Open questions" entry needed.

### T3.2 — Pengawas SLA tiket layanan (`ticket_sla`) pada `svc_tickets.resolution_due_at`
- Commit: (this commit) — T3.1 above is 3804085.
- Files: `Modules/Core/Support/WatchedDeadlines.php` (one entry, `ticket_sla`, after `svc_contract_period_end`),
  `tests/Feature/Core/DeadlineWatchTest.php` (fixtures `serviceContract()`, `ticket()`; 5 new tests), this block.
  **No migration, no Resource change, no backfill** — see Notes.
- Acceptance:
  - Works/refused pair, red first on the tree without the entry (`--filter ticket`: **3 errors**, `ItemNotFoundException`
    on `->sole()`; the two "silent"/"not blind" pins green on both trees by design). After: `DeadlineWatchTest` +
    `DeadlineApiTest` + `CalendarApiTest` **OK (79 tests, 280 assertions)** (DeadlineWatchTest 51 → 56). Works =
    assigned ticket, resolution due 8 Jul 2026 14:00 (TKT-202607-0003's seeded shape) → title
    `Tiket layanan lewat batas SLA`, body `TKT-… batas penyelesaian 8 Jul 2026 — 24 hari lalu.`, link
    `r/servicedesk/tickets`, delivered to the `svc.update` holder only (a `crm.update` holder gets nothing); open and
    in_progress alarm too (`Total 2 tiket.`), pending_customer does not; refused = resolved (late but resolved), closed,
    cancelled all silent; a ticket due 3 Agu silent, one due today 16:30 reads `hari ini`; a contract-less ticket (no
    SLA by design) raises neither an alarm nor a `BLIND ticket_sla` line. `tests/Feature/ServiceDesk`
    **OK (44 tests, 196 assertions)**; `tests/Feature/Core` **OK (597 tests, 3 657 assertions)** (592 + 5);
    `vendor/bin/pint --test --dirty` passed.
  - **The watch fires for an assigned ticket past its deadline and stays silent for a resolved one**, on the seed
    itself — no planting needed: `php artisan erp:deadline-watch --dry-run` on the T3.1 scratch seed →
    `ticket_sla [lewat]: 2 row(s) -> svc.update` / `  TKT-202607-0003 batas penyelesaian 8 Jul 2026 — 59 hari lalu.
    TKT-202607-0004 batas penyelesaian 29 Jul 2026 — 38 hari lalu. Total 2 tiket.` / `Checked 21 watcher(s), skipped 0,
    blind 1, raised 8 alarm group(s). Dry-run: tidak ada notifikasi dikirim.` The seed's other two tickets —
    `TKT-202606-0002` (resolved 10 Jun, due 11 Jun) and `TKT-202606-0001` (closed) — are not named. A real run on a
    scratch COPY (`t32.sqlite`) wrote **2 `core_notifications` rows**, one per `svc.update` holder
    (`admin@nusantara.test`, `teknisi@nusantara.test`), same body, link `r/servicedesk/tickets`.
- Notes:
  - **The column already existed; the RECAP's `sla_due_at` never did.** The orchestrator's brief said the SLA
    was computed on the fly and asked for a new nullable `sla_due_at` column, a forward-only migration, service
    writes and a backfill. The tree says otherwise, and the evidence is exact: migration
    `2026_07_25_001220_create_svc_tickets_table.php:26-27` creates `response_due_at` and `resolution_due_at`
    (dateTime, nullable, indexed); `TicketService::applySlaDueDates` (lines 215-220) writes both from
    `SlaService::computeDueDates` on `create()` (line 38) and on `update()` whenever `service_contract_id`,
    `priority` or `reported_at` changes (line 65); `ServiceDeskDatabaseSeeder::seedTickets` seeds both per ticket
    (lines 170-248); `TicketResource` already exposes `resolution_due_at` + `resolution_breached` (lines 33-40);
    the SPA shows it as the `SLA selesai` column (`schema.js:4359`), the `SLA penyelesaian` card
    (`custom.js:394`), the `Tiket Lewat SLA` screen (`slabreaches.js`, hour-exact) and the dashboard's
    `N melewati SLA` (`dashboard.js:334`, from `TicketService::slaBreaches`). A second column equal to
    `resolution_due_at` would be a duplicate every write path has to keep in sync — the drift the registry's
    header warns against — and a backfill would recompute a value the rows already carry. So the entry watches
    `resolution_due_at` directly; "expose `sla_due_at` in the resource" is satisfied by the field that is there,
    under its real name, and no key was added. Resolution deadline, not response: that is what the dashboard
    count and the breaches screen use, and what ANALISIS D2 counted (the four `assigned` tickets "tanpa
    penyelesaian"). This is a technical finding, not a business decision, so no "## Open questions" entry.
  - **Scope = open / assigned / in_progress, contract-bearing, undeleted** — the RECAP's three statuses,
    pinned as literals of `TicketStatus`. `pending_customer` is deliberately out (the file's "an alarm always
    has an action left" rule; `slaBreaches()` still counts it, so the dashboard number can exceed the watcher's
    by those tickets — stated in the entry comment). `whereNotNull('service_contract_id')`: a ticket without a
    maintenance contract carries no SLA by design (`SlaService`), so its NULL must not trip the BLIND line that
    exists for forgotten dates.
  - **Day-granular by construction, like every entry.** `scan()` compares date strings; a datetime
    `"2026-08-01 16:30:00"` sorts before `"2026-08-02"`, so a deadline later today reads `hari ini` under the
    overdue title at 08:30 — the po_expected reading, pinned by a test rather than hidden. `lead_days` 0 as the
    RECAP says; the alternative that would make the due day MENIPIS (`valid_through_end`) requires a lead window
    and would contradict the entry.
  - The calendar (`CalendarEvents::sources`) picks the entry up automatically as `Tiket layanan batas
    penyelesaian` under Layanan / `svc.view`; `CalendarApiTest` green.
  - Environment: dry-run on `<scratchpad>/ux/t31.sqlite` (read-only for the scan), the real run on a copy
    `t32.sqlite`; `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`). No server needed —
    nothing in the SPA changed.

### T3.5 — Tanggal wajib yang menggerakkan pengawas: `expected_date` PO wajib (store + update), `needed_date` PR dan SLA tiket dipin uji
- Commit: (this commit) — T3.2 above is f0dd7ba.
- Files: `Modules/Procurement/Http/Requests/PurchaseOrderStoreRequest.php` (`expected_date` nullable → `required|date|after_or_equal:order_date`),
  `Modules/Procurement/Http/Requests/PurchaseOrderUpdateRequest.php` (`nullable` → `sometimes|required|date`),
  `public/app/js/schema.js` (PO form: `Perkiraan kirim` `required: true`, komentar alasan terukur),
  `tests/Feature/Procurement/RequiredWatchDatesTest.php` (new, 5 tests), `tests/Feature/ServiceDesk/TicketSlaOnCreateTest.php` (new, 2 tests),
  fixtures: `tests/Feature/Procurement/PoQualificationOverrideAuditTest.php` (2 call sites), `PoBoqLinkTest.php` (1), `AwardDecisionApprovalTest.php` (1),
  `docs/bukti-uji/harness-playwright.py` (4 spots: `po_action_bar` + `draft_po` API fixtures, S3 and S4 form fills), `docs/RECAP-UX-PROSES-2026-09.md` (new `## Open questions`, OQ-1), this block.
  **No lang change** — `lang/id/validation.php:311` already maps `expected_date` → `Perkiraan kirim` (and `:426` `needed_date` → `Dibutuhkan`), so `:attribute wajib diisi.` yields the sentence verbatim.
- Acceptance:
  - **POST PO without expected_date → 422 "Perkiraan kirim wajib diisi."** — three ways. (1) `RequiredWatchDatesTest`, red first on the
    unpatched tree: `test_a_po_without_expected_date…` **201 instead of 422**, `test_an_ubah_cannot_blank…` **200 instead of 422**
    (plus one assertion of mine corrected: sqlite stores the date cast as `2026-08-22 00:00:00`, now read back through the model);
    after: **OK (5 tests)**. (2) curl on the scratch server as procurement: `HTTP 422 {"message":"Perkiraan kirim wajib diisi.","errors":{"expected_date":["Perkiraan kirim wajib diisi."]}}`.
    (3) Harness **S10** `po_422` = `{vendor_id: "Vendor wajib diisi.", order_date: "Tanggal PO wajib diisi.", expected_date: "Perkiraan kirim wajib diisi.", items.0.qty: "Kuantitas minimal 0.001.", items.0.unit_price: "Harga satuan wajib diisi."}` — the four prior keys byte-identical to Sesudah, one key added; `customer_422` / `apbill_422` unchanged.
  - **PR needed_date already required on both sides** (`PurchaseRequisitionStoreRequest:20`, `schema.js:2095`) — verified, not re-added: POST PR without it → 422 `Dibutuhkan wajib diisi.` (green on both trees by design).
  - **A new ticket has its SLA deadline** — under its real name `svc_tickets.resolution_due_at` (T3.2's finding; the RECAP's `sla_due_at` never existed): `TicketSlaOnCreateTest` POST `servicedesk/tickets` (contract SLA 4 h / 24 h, priority low, reported Sun 5 Jul 2026 06:00) → `response_due_at 2026-07-06T12:00:00+07:00`, `resolution_due_at 2026-07-08T14:00:00+07:00`, DB `2026-07-08 14:00:00` — TKT-202607-0003's shape; green on both trees (the pin the task asked for). Contract-less ticket → `resolution_due_at null` by design, pinned and raised as OQ-1.
  - PHPUnit per directory, final tree: **Procurement OK (174 tests, 688 assertions)** (169 + 5), **ServiceDesk OK (46, 202)** (44 + 2), **Core OK (597, 3 657)** (unchanged — nothing in Core posts a PO); `vendor/bin/pint --test --dirty` passed.
  - Harness on a fresh scratch seed (`t35b.sqlite`), `S10 S2 S3 S4 S12` → **5 ok, 0 ERROR**:
    **S3 12 klik** (fresh profile, `nav_group_opened` true — unchanged from the Phase 2 gate; a date fill is not a click), form fields now
    `Vendor* · Dari PR · Proyek · Gudang tujuan · Tanggal PO* · Perkiraan kirim* · …`, empty-Simpan `client_errors` **4** (was 3: + Perkiraan kirim),
    `server_errors_rendered` = `["Kuantitas minimal 0.001."]` only, `toast_on_422` `Periksa isian yang ditandai.`, saved → `#/d/procurement/purchase-orders/4`
    **PO/2026/IX/0004**, toast `PO/2026/IX/0004 diajukan · menunggu persetujuan.`, status **Diajukan**; the row: `order_date 2026-09-04`, `expected_date 2026-09-18` (today + 14).
    **S4** `fields_typed_before_expiry` **14** (was 13: + Perkiraan kirim), `restored.filled` **14** / 3 baris, `modalVisible` **false**, `loginVisible` **true**, banner
    `Sesi Anda berakhir. Isian PO yang sedang Anda buat tersimpan di peramban ini — masuk kembali untuk memulihkannya.`, Masuk `reachable`, `recoveryOffer` true — 0 lost.
    **S2** 4 klik, `action_bar` `[Kembali, Cetak, Setujui, Tolak]`, `approve_modal_opened` false, `po_bar` present (the `po_action_bar` API fixture creates its PO); `api_calls_detail_to_back` 16 (SPK at the head of this seed — the queue tie-break the Phase 0 gate documented).
    **S12** healthy vendor `PO/2026/IX/0005` 1 klik no modal; blocked vendor prompt → `PO/2026/IX/0006` 3 klik, reason stored (the `draft_po` fixture creates both POs).
- Notes:
  - **Fixtures that went red and how they were fixed — never the rule.** Server tests: 4 `postJson('/api/procurement/purchase-orders')` call sites
    in 3 files (`PoQualificationOverrideAuditTest` ×2, `PoBoqLinkTest` ×1, `AwardDecisionApprovalTest` ×1) got `'expected_date' => '2026-08-22'`
    (order_date 2026-08-08 + 14). Every other Procurement/Core test builds POs through `PoService`/the model and never met the FormRequest.
    Harness (the JS-side test — node is not installed): `po_action_bar` (S2 helper) and `draft_po` (S12) API fixtures got `expected_date 2026-09-16`;
    S3 and S4 fill `Perkiraan kirim` in the form. **First S3 run failed honestly**: the fixed `2026-09-02` the harness uses for every date is
    before `Tanggal PO` (defaultToday = 4 Sep), and the store rule's `after_or_equal:order_date` — inert while the field was nullable — now
    answered `Perkiraan kirim harus pada atau setelah Tanggal PO.` (saved false, 11 klik). The fill is `today + 14 d`, computed, so the
    scenario does not rot with the calendar; S4 the same for consistency (it never reaches the server — 401 first — but the restored draft should be savable).
  - **Update request too, with `sometimes`.** Ubah renders the same form with the same `required` mark, so the server matches it: a PUT
    carrying `expected_date: ''` is refused with the same sentence; a PUT without the key (PoBoqLinkTest's line-only edit) still passes and
    keeps the stored date — both pinned. `after_or_equal` was never on the update request and was not added (no refactor of neighbours).
  - **`PurchaseOrderFromPrRequest` stays nullable on purpose:** "Buat PO" from an approved PR inherits the PR's `needed_date`
    (`PoService::createFromPr:134`), and `needed_date` is itself required — pinned (`test_a_po_created_from_a_pr_inherits_the_pr_needed_date`).
    The PR-side action modal (`schema.js:2135`) keeps the field optional for the same reason.
  - **Carry-overs, not in this entry's Steps (forms + FormRequests):** `RfqService::createPo:289` still writes `expected_date ?? null`
    (`POST rfqs/{rfq}/create-po` with `vendor_id` only → a date-less PO, RfqTest:300/304 do exactly that); `DocumentImportService` and the
    seeders bypass FormRequests. Both are creation paths that can still produce a PO invisible to `po_expected`. Neither is a business
    decision — a follow-up task, not an open question. Unchanged.
  - **Ticket: nothing to build, one thing to ask.** The RECAP assumed "`sla_due_at` computed from priority (ServiceDesk settings for SLA hours)";
    the tree computes the deadline from the CONTRACT's SLA hours (priority only picks clock vs business hours), there is no SLA setting, and a
    ticket without a maintenance contract carries no deadline by design (`SlaService`), hence sits outside `ticket_sla` — the D1 blindness in
    another shape. Whether such tickets should get a default SLA and with what hours per priority is a director/ops decision → recorded as
    **OQ-1** under the new `## Open questions` in the RECAP (rule 9). It does not unblock T3.9/T3.10.
  - **RECAP § Verification row "Fields lost on session expiry (13 typed)"** now types 14 (still 0 lost) — the phase-end pass (Prompt B4) fills that column; the table itself was not edited here.
  - Environment: fresh scratch seeds `<scratchpad>/ux/t35.sqlite` (first run, S3 saved false — kept as the failing evidence) and `t35b.sqlite`
    (clean re-run above); server `cd public && DB_DATABASE=<scratch> APP_ENV=local nohup php -S 127.0.0.1:8000 …/server.php`, killed by pid
    after each run (:8000 free); `database/database.sqlite` untouched (mtime 13:10:55, no `-wal`/`-shm`). Harness one-liner:
    `ERP_DB=<scratch>/t35b.sqlite UXTEST_OUT=<scratch>/out-t35b /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S10 S2 S3 S4 S12`.

### T3.4 — Maker-checker untuk dokumen tanpa jejak pengajuan: fallback kolom pemilik di `SegregationOfDuties`, baris `submitted` dari mesin impor dan seeder
- Commit: (this commit) — T3.5 above is 51dceab.
- Files: `Modules/Core/Support/SegregationOfDuties.php` (`makerIdOf()`, `ownerIdOf()`, `hasRecordedSubmission()`; `assertNotSubmitter` reads `makerIdOf`; header rewritten for the measured case),
  `Modules/Core/Services/DocumentImportService.php` (`commit(…, ?User $by = null)` + `recordSubmission()` inside the per-document transaction),
  `Modules/Core/Http/Controllers/DocumentImportController.php` (passes `$request->user()`),
  `Modules/Estimation/Database/Seeders/EstimationDatabaseSeeder.php` (RAP/2026/0001 gets its `submitted` row, seed admin),
  `Modules/HrPayroll/Database/Seeders/HrPayrollDatabaseSeeder.php` (CTI/2026/VIII/0002 gets its `submitted` row = the employee's own login; `backfillUserEmployeeLinks()` moved before `seedLeaveRequests()` so that login resolves on a single pass),
  `Modules/Core/Support/ApprovalQueue.php` + `tests/Feature/Core/ApprovalWatchTest.php` (one docblock sentence each — both said the guard "passes that case", which is no longer true),
  `tests/Feature/Core/MakerCheckerOwnerFallbackTest.php` (new, 7 tests), `tests/Feature/Core/DocumentImportTest.php` (fixture `penawaran-diajukan-uji` + 3 tests), `tests/Unit/Core/SegregationOfDutiesTest.php` (1 test), this block.
  **No migration, no API contract change** (`commit()` gained an optional trailing parameter; every existing caller and test is untouched).
- Acceptance:
  - **Seeded-submitted PR with `requested_by` = approver → refused with the existing named-person message.** Red first on the unpatched tree: `MakerCheckerOwnerFallbackTest` = **1 failure** (`test_a_pr_seeded_straight_to_submitted_is_refused_to_its_own_requester`: *Expected 422 but received 200* — the production case, HASIL-UJI §6 P-3) + 4 errors (`makerIdOf` undefined); `DocumentImportTest` new tests = 1 failure (trail `[]` instead of `[[submitted, importer]]`) + 1 error; `SegregationOfDutiesTest` new test = 1 error. After: `MakerCheckerOwnerFallbackTest` **OK (7 tests, 26 assertions)** — the refusal reads `Permintaan pembelian PR/… diajukan oleh Pemegang prc.approve; dokumen tidak boleh disetujui oleh pengajunya sendiri. Minta persetujuan pengguna lain pemegang izin prc.approve, …`, status stays `submitted`, 0 rows written; a second `prc.approve` holder gets 200; the switch off lets the requester through; a recorded row beats the column (Alice requests, Bob submits → Bob refused, Alice approves); `submit(null)` on a PR is NOT second-guessed (`makerIdOf` null, requester approves); a work permit's `requested_by` (an hr_employees id) resolves through `users.employee_id` — the login whose id collides with the employee number passes, the mandor's own login is refused.
  - **Replayed over HTTP on a fresh scratch seed** (`t34.sqlite`, PR/2026/III/0002's `submitted` row deleted to recreate production's `approvals: []`): admin `POST procurement/purchase-requisitions/2/approve` → **HTTP 422** `{"message":"Permintaan pembelian PR/2026/III/0002 diajukan oleh Administrator Sistem; dokumen tidak boleh disetujui oleh pengajunya sendiri. Minta persetujuan pengguna lain pemegang izin prc.approve, atau matikan \"Wajib pemisahan tugas\" di Pengaturan → Proyek & Persetujuan bila perusahaan Anda memang tidak memiliki petugas kedua."}`, `GET` afterwards: `submitted`, `approvals []`; direktur → **HTTP 200**, DB trail `[approved — Budi Santoso]`, status `approved`.
  - **Retention release bill still approvable:** `RetentionReleaseGateTest` + `RetentionReleaseLedgerTest` **OK (15 tests, 63 assertions)** (unchanged files — `test_a_releaser_holding_both_permissions_still_releases_and_owns_the_approved_row` still mints the bill "Diajukan: Sistem / Disetujui: <pelepas>"); and the proof the fallback cannot fire there is pinned: `test_a_bill_has_no_owner_column_so_the_fallback_cannot_fire` asserts `Schema::hasColumn('fin_ap_bills', …)` is false for all three owner columns, `makerIdOf` null both for a bill submitted as nobody and for one written straight to `submitted`, both approved by a `fin.approve` holder.
  - **The import path writes the row:** `DocumentImportTest::test_a_document_landed_as_submitted_names_the_importer_as_its_maker` — fixture definition whose `create` leaves the penawaran `submitted` → exactly `[[submitted, <importer id>]]`, `submitterIdOf` = importer, the importer's own `approve()` throws `SelfApprovalException` naming them, a `crm.approve` holder approves; `…_landed_as_a_draft_records_no_submission` (0 rows — the pre-existing path pinned); `…_endpoint_names_the_logged_in_user_as_the_importer` (`POST core/document-import/penawaran-diajukan-uji/import` as admin → row `user_id` = admin). `test_every_registered_definition_is_well_formed…` and `…only_the_documents_the_caller_may_read` still green with the 4th fixture.
  - **Seeders, on a fresh `migrate:fresh --seed` scratch copy (`t34.sqlite`):** every seeded `submitted` document now carries a `submitted` row with a named actor — `RAP/2026/0001` → `admin@nusantara.test` (new), `CTI/2026/VIII/0002` → `procurement@nusantara.test` = EMP-0005 Andi Kurniawan's own login (new), `PR/2026/III/0002` and `SPK/2026/III/0002` → admin (already written by their seeders' `writeApprovalTrail`, verified — not changed). `core_approvals` 32 → 34 rows.
  - PHPUnit per directory, final tree: **Core OK (607 tests, 3 695 assertions)** (597 + 10), **Finance OK (819, 3 895)**, **Procurement OK (174, 688)**, **Subcontract OK (134, 473)**, **Projects OK (345, 1 823)**, **HrPayroll OK (143, 527)**, **Estimation OK (77, 373)**, **Crm OK (214, 773)** (QuotationImportTest calls `commit()` without an actor — unchanged), **Unit/Core OK (231, 972)** (230 + 1); `vendor/bin/pint --test --dirty` passed.
  - Harness sanity on the fresh-seed copy `t34h.sqlite`, `S1 S2` → 2 ok, 0 ERROR: **S1** `server_submitted_visible_to_direktur` = RAP 1 · PR 1 · SPK 1 · CTI 1, card `Menunggu persetujuan Anda (4)` rows `[RAP/2026/0001, PR/2026/III/0002, SPK/2026/III/0002, CTI/2026/VIII/0002]` (4/4, unchanged — direktur submitted none of them), warehouse 6 calls; **S2** `_clicks` 4, `explanation_under_title` = `Diajukan 04 Sep 2026 oleh Administrator Sistem · menunggu persetujuan.` (the RAP's new seeded row is what the strip now reads — before this task the RAP had no trail), `action_bar [Kembali, Cetak, Setujui, Tolak]`, `approve_modal_opened` false, after = `Disetujui 04 Sep 2026 oleh Budi Santoso · dokumen terkunci.`, `opened_next PR/2026/III/0002`, `api_calls_detail_to_back` 16.
- Notes:
  - **"No row" ≠ "a row whose actor is nobody".** The fallback fires only when NO `submitted` row exists at all (the RECAP's wording); `submit(null)` — RetentionService, AdvanceService — writes a row and stays the documented silent state, pinned both on a table with an owner column (PR) and without (bill). `submitterIdOf()` keeps its meaning (NotificationService and ExternalApprovalService read it unchanged); the new `makerIdOf()` is what `assertNotSubmitter` reads.
  - **`prj_work_permits.requested_by` is an employee number, not a login** (migration comment "pemohon adalah pegawai", model `belongsTo(Employee)`), so a name-only rule would have refused the wrong person. Core keeps one string-literal exception (`EMPLOYEE_OWNER_COLUMNS`) and resolves it through `users.employee_id`; the other two carriers (`prc_purchase_requisitions.requested_by`, `prj_baselines.created_by`) are `users.id`. No table has `submitted_by` yet — listed because it is the name a future column would take. `hr_leave_requests.employee_id` is not among the three columns the entry names and is covered by (b) instead. Heads-up: `ApprovalQueue::OWNER_KEYS` still treats `requested_by` as a user id for every table, so the INBOX can mis-attribute a work permit (offer/hide the wrong login) — pre-existing, not touched here.
  - **The importer, not `Auth`.** `commit()` takes the actor explicitly (the controller passes `$request->user()`); with no actor nothing is written, because an unnamed row would silence the guard where the owner-column fallback could still speak. No shipped definition lands a document as `submitted` today (every `create` goes through the module service → draft), so the engine rule is the net for the next one; the fixture definition is what makes it testable now. `MasterDataController` uses `MasterDataImportService` — unrelated, untouched.
  - **Leave request: the employee's login, not the seed admin.** The RECAP's "(user = importer / seed admin)" is followed for the RAP; for CTI/2026/VIII/0002 the seed names the employee's own login (`users.employee_id` → EMP-0005 = `procurement@`), which is what `LeaveService::submit` would have written — a truer trail than admin — and falls back to the seed admin only for an employee without a login. That needed `backfillUserEmployeeLinks()` to run before `seedLeaveRequests()`; the back-fill only needs `hr_employees`, which `seedEmployees()` writes first, so the reorder is safe (`HrPayroll` suite green, single-pass `migrate:fresh --seed` verified).
  - Finance / Procurement / Subcontract seeders already wrote `submitted` rows through their `writeApprovalTrail` helpers (scratch seed rows 9 and 18 for PR 2 / SPK 2) — the orchestrator's list named them; nothing to add there. Production's `approvals: []` on PR/2026/III/0002 is an older seed, which is exactly why the guard needs the net and not just the seeder fix.
  - Carry-overs, not in this entry's Steps: `ExternalApprovalService::assertIssuerIsNotMaker` and `NotificationService::submitterOf` still read `submitterIdOf()` only (an external link for a trail-less document can still be issued by its requester; the requester of such a document is not notified of the decision). Both are the same one-line switch to `makerIdOf()` if wanted — a follow-up, not a business decision. The SoD header's "thirteen models" count is replaced by the 4 Sep 2026 count (28 registered, 3 with an owner column).
  - Environment: `php artisan config:clear`; fresh seed `<scratchpad>/ux/t34.sqlite` (HTTP replay, mutated) and its copy `t34h.sqlite` (harness); server `cd public && DB_DATABASE=<scratch> APP_ENV=local nohup php -S 127.0.0.1:8000 …/server.php` as its own statement, killed by pid after each run (:8000 free); harness one-liner `ERP_DB=<scratch>/t34h.sqlite UXTEST_OUT=<scratch>/out-t34h /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S1 S2`; `database/database.sqlite` untouched (mtime 2026-09-04 13:10:55, no `-wal`/`-shm`). No "## Open questions" entry needed.

### T3.8 — PO tanpa PR wajib beralasan: `pr_bypass_reason` ujung ke ujung (migrasi, `required_without`, PoService, Resource, formulir, detail, formulir cetak)
- Commit: (this commit) — T3.4 above is 1707755.
- Files: `Modules/Procurement/Database/Migrations/2026_09_04_000870_add_pr_bypass_reason_to_prc_purchase_orders.php` (new — `hasColumn` guard, `string(500)` nullable after `qualification_override_reason`, rollback drops it; the 2026_08_08_000853 pattern),
  `Modules/Procurement/Http/Requests/PurchaseOrderStoreRequest.php` (`pr_bypass_reason` → `required_without:purchase_requisition_id|nullable|string|max:500`),
  `Modules/Procurement/Http/Requests/PurchaseOrderUpdateRequest.php` (`Rule::when($this->has('purchase_requisition_id'), ['required_without:purchase_requisition_id'])|nullable|string|max:500`),
  `Modules/Procurement/Services/PoService.php` (`create()`: pulled out of mass-assignment, stored only when the PO has no PR; `update()`: excluded from `fill`, follows the post-edit state — cleared when a PR is linked, replaced when sent, kept when the key is absent),
  `Modules/Procurement/Http/Resources/PurchaseOrderResource.php` (`pr_bypass_reason`), `Modules/Procurement/Services/ProcurementFormService.php` (`orderNotes()`: `labelled('Alasan tanpa PR', …)` next to the override line — the printed house form),
  `lang/id/validation.php` (`pr_bypass_reason` → `Alasan tanpa PR`), `public/app/js/schema.js` (`PO_WITHOUT_PR` predicate + PO form field `Alasan tanpa PR`, textarea span 2, `required`, `visibleWhen`, under "Dari PR"), `public/app/js/views/detail.js` (LABELS entry),
  `Modules/Procurement/Database/Seeders/ProcurementDatabaseSeeder.php` (PO/2026/III/0002 carries its reason as data; `pr_bypass_reason` column in `updateOrCreate`, `?? null` for the PO from a PR),
  `tests/Feature/Procurement/PoPrBypassReasonTest.php` (new, 7 tests), fixtures that went red: `tests/Feature/Procurement/RequiredWatchDatesTest.php` (`poPayload()`), `PoQualificationOverrideAuditTest.php` (2 call sites), `PoBoqLinkTest.php` (1), `AwardDecisionApprovalTest.php` (1),
  `docs/bukti-uji/harness-playwright.py` (4 spots: `po_action_bar` + `draft_po` API fixtures, S3 and S4 form fills), this block.
  **API contract:** one key added to the PO resource, one field added to POST/PUT (required only without a PR); every existing key and every existing test is untouched apart from the fixtures listed.
- Acceptance:
  - **422 without reason** — three ways. (1) `PoPrBypassReasonTest`, red first on the unpatched tree: **4 failures** (`…without_a_reason_is_refused`: *201 instead of 422*; `…stores_its_reason…`: `data.pr_bypass_reason` null; `…cannot_blank…` and `…detaching_the_pr…`: *200 instead of 422*), 3 green by design (the from-PR paths were already null — no column, no attribute). After: **OK (7 tests, 32 assertions)**. (2) curl on the scratch server (`t38.sqlite`) as procurement: POST without PR and without reason → **HTTP 422** `{"message":"Alasan tanpa PR wajib diisi bila PR kosong.","errors":{"pr_bypass_reason":["Alasan tanpa PR wajib diisi bila PR kosong."]}}`; with a reason → **HTTP 201** `PO/2026/IX/0003`, `data.pr_bypass_reason` echoed. (3) Harness **S10** `po_422` = `{vendor_id: "Vendor wajib diisi.", order_date: "Tanggal PO wajib diisi.", expected_date: "Perkiraan kirim wajib diisi.", pr_bypass_reason: "Alasan tanpa PR wajib diisi bila PR kosong.", items.0.qty: "Kuantitas minimal 0.001.", items.0.unit_price: "Harga satuan wajib diisi."}` — the five prior keys byte-identical to T3.5, one added; `customer_422` / `apbill_422` unchanged.
  - **Audit shows the reason** — in the three places the override reason shows. API: `GET procurement/purchase-orders/{id}` → `data.pr_bypass_reason` on the curl-created PO and on the seeded direct PO `PO/2026/III/0002` = `PR ICT masih menunggu persetujuan; material tahap 1 dipesan langsung agar 4 cabang pertama tidak mundur dari jadwal.`; `PO/2026/II/0001` (from PR/2026/II/0001) → `null`. Detail: the Informasi panel row `Alasan tanpa PR` (LABELS, same rendering as `Alasan override kualifikasi`). Print: house form `GET core/print/forms/order-pembelian/2` (**HTTP 200 text/html**) notes block reads `Alasan tanpa PR : PR ICT masih menunggu persetujuan; …`, and the same form for PO 1 has **0** occurrences — the notes block rules itself, exactly as the override line does. `orderNotes()` is pinned by the test.
  - PHPUnit per directory, final tree: **Procurement OK (181 tests, 720 assertions)** (174 + 7), **Core OK (607, 3 695)** (unchanged — `ListingConcernTest` reads the PO index only); `vendor/bin/pint --test --dirty` passed.
  - Harness on a fresh scratch seed (`t38h.sqlite`), `S10 S2 S3 S4 S12` → **5 ok, 0 ERROR**:
    **S3 12 klik** (fresh profile, `nav_group_opened` true — unchanged since the Phase 2 gate; the reason is an isian, not a klik), form fields now `Vendor* · Dari PR · Alasan tanpa PR* · Proyek · Gudang tujuan · Tanggal PO* · Perkiraan kirim* · …`, empty-Simpan `client_errors` **5** (was 4: + Alasan tanpa PR), `server_errors_rendered` = `["Kuantitas minimal 0.001."]` only, `toast_on_422` `Periksa isian yang ditandai.`, saved → `#/d/procurement/purchase-orders/4` **PO/2026/IX/0004**, toast `PO/2026/IX/0004 diajukan · menunggu persetujuan.`, status **Diajukan**; the row: `purchase_requisition_id NULL`, `pr_bypass_reason "UJI-UX — pembelian langsung tanpa PR"`, `expected_date 2026-09-18`.
    **S4** `fields_typed_before_expiry` **14**, `restored.filled` **14** / 3 baris (see Notes — the harness's "first textarea" is now this field), `modalVisible` **false**, `loginVisible` **true**, banner `Sesi Anda berakhir. Isian PO yang sedang Anda buat tersimpan di peramban ini — masuk kembali untuk memulihkannya.`, Masuk `reachable`, `recoveryOffer` true, restored textarea = `UJI-UX — pembelian langsung tanpa PR` — 0 lost.
    **S2** 4 klik, `action_bar` `[Kembali, Cetak, Setujui, Tolak]`, `approve_modal_opened` false, `explanation_under_title` `Diajukan 04 Sep 2026 oleh Administrator Sistem · menunggu persetujuan.`, after `Disetujui 04 Sep 2026 oleh Budi Santoso · dokumen terkunci.`, toast `RAP/2026/0001 disetujui.` + `Berikutnya menunggu Anda (3) PR/2026/III/0002`, `api_calls_detail_to_back` 16 (RAP at the head of this seed — the tie-break the Phase 0 gate documented), `po_bar` `PO/2026/IX/0003`: draft `[Kembali, Cetak, Ubah, Ajukan]`, submitted-as-direktur `[Kembali, Cetak, Setujui, Tolak]`, Cetak menu 4 items.
    **S12** healthy vendor `PO/2026/IX/0005` 1 klik no modal; blocked vendor prompt → `PO/2026/IX/0006` 3 klik, `qualification_override_reason` stored. Every harness-created PO (3–6) carries `pr_bypass_reason` (no PR chosen by any of them); the two seeded POs keep 1 → null (from PR) and 2 → the seed's reason.
  - **`visibleWhen`, the reactive half**, proven with a standalone Playwright probe on `t38h` (scratch file, not committed): empty form → field visible; `PR/2026/II/0001` picked in "Dari PR" → field hidden, and an empty Simpan then marks only `Vendor*` and `Perkiraan kirim*` — the hidden field is neither required client-side nor sent (`form.js` drops hidden keys from the payload, so the server's `required_without` never fires for a field the user cannot see). The "cleared again → visible again" leg was NOT exercised: the probe's `fill('')` does not un-commit the combobox (it commits on selection); a limit of the probe, not a finding — the predicate reads the same live value in both directions.
- Notes:
  - **The measured reason, as measured.** E3's "Bukti produksi" column in ANALISIS-PROSES §3 is "—": no count, the gap is structural. What can be cited is production's one direct PO — production = seed + 5 human documents, and `PO/2026/III/0002` Rp 128 jt (the PO D1 already named) has `purchase_requisition_id NULL` with the only "why" living in a seeder comment (`// direct PO (PR ICT masih submitted)`), which neither auditor nor screen ever reads. Every comment written here cites that.
  - **Honesty contract mirrors the override column.** Stored only when the PO ends up without a PR: a reason typed for a PO that has a PR → `null` (the "vendor sehat" case of `PoQualificationOverrideAuditTest`); a direct PO later linked to its PR on Ubah → reason cleared; a PUT without the key keeps the stored value. All pinned. `createFromPr` and the RFQ path are untouched (their PO has, or inherits, a PR).
  - **Update request: `Rule::when(has('purchase_requisition_id'))` rather than `sometimes|required_without`.** `sometimes` would let `PUT {purchase_requisition_id: null}` (API, no reason key) strip the PR and leave a direct PO without a reason; `Rule::when` on the *PR key's presence* refuses exactly that, while a line-only PUT (PoBoqLinkTest) keeps the stored reason — the T3.5 precedent "Ubah renders the same required mark" with one edge closed. The SPA always sends both keys on Ubah (hidden fields excepted).
  - **Fixtures that went red and how they were fixed — never the rule.** The same four HTTP call sites T3.5 dated, plus `RequiredWatchDatesTest::poPayload()`, got `'pr_bypass_reason' => 'Fixture uji: pembelian langsung tanpa PR'`; the harness's two API fixtures got the same key and S3/S4 fill the field by label. Tests building POs through `PoService`/the model never met the FormRequest and are unchanged.
  - **Seeder.** `PO/2026/III/0002`'s reason moved from comment to data; the seeder's `updateOrCreate` now writes `pr_bypass_reason` (`?? null` for `PO/2026/II/0001`). Verified on the fresh seed. Re-seeding production is the owner's step (T1.1 list) — not run here.
  - **Field placement, and what it did to S4.** The field sits right under "Dari PR" (span 2) so the question stands where the answer is missing. Consequence: the harness's S4 fills the *first* `.modal textarea`, which is now this field, and the explicit fill by label then writes the same value — `Alamat pengiriman` stays empty, so `fields_typed_before_expiry` stays **14** instead of becoming 15. Same fields restored, 0 lost.
  - **Informasi panel shows `Alasan tanpa PR: —` on a PO from a PR**, exactly as `Alasan override kualifikasi: —` shows on every healthy PO today — mirrored on purpose: `WHEN_SET_KEYS` would also hide a MISSING reason on a direct PO, which is the very gap the row exists to expose. The printed form, by contrast, rules itself (notes block) on both.
  - **Carry-overs, not in this entry's Steps:** `RfqService::createPo` builds its PO with `purchase_requisition_id = $rfq->purchase_requisition_id` — an RFQ without a PR yields a direct PO with no reason (`POST rfqs/{rfq}/create-po`; RfqTest does that). The award decision is arguably that PO's reason; whether it should also stamp `pr_bypass_reason` is a follow-up, not a business decision. `DocumentImportService` and seeders bypass FormRequests (T3.5's same list). `PurchaseOrderFromPrRequest` always has a PR — nothing to add. No "## Open questions" entry needed.
  - Environment: `php artisan config:clear`; fresh scratch seeds `<scratchpad>/ux/t38.sqlite` (curl replay, mutated) and `t38h.sqlite` (harness, then the probe); server `cd public && DB_DATABASE=<scratch> APP_ENV=local nohup php -S 127.0.0.1:8000 …/server.php` as its own statement, killed by pid after each run (:8000 free); `database/database.sqlite` untouched (mtime 2026-09-04 13:10:55, no `-wal`/`-shm`). Harness one-liner: `ERP_DB=<scratch>/t38h.sqlite UXTEST_OUT=<scratch>/out-t38h /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S10 S2 S3 S4 S12`.

### T3.6 — Kontrak dari penawaran yang menang: `POST quotations/{q}/create-contract`, alasan selisih nilai (`value_change_reason`), tombol Buat/Lengkapi kontrak, "Dari penawaran" pada detail kontrak
- Commit: (this commit) — T3.8 above is 5253729.
- Files: `Modules/Crm/Database/Migrations/2026_09_04_000394_add_value_change_reason_to_crm_contracts_table.php` (new — `hasColumn` guard, `string(500)` nullable after `total_with_ppn`, rollback drops it; the 000870 pattern. **No `quotation_id` migration**: the column has existed since 000340 with `Contract::quotation()`, as the orchestrator established),
  `Modules/Crm/Services/ContractService.php` (`createFromQuotation()`, `isUnfilledShell()`, `applyValueChangeReason()` called from `create()` and `update()`; the reason pulled out of mass-assignment),
  `Modules/Crm/Http/Requests/ContractFromQuotationRequest.php` (new — no `customer_id`/`quotation_id`: those are the quotation's), `ContractStoreRequest.php` + `ContractUpdateRequest.php` (`value_change_reason` nullable string max 500),
  `Modules/Crm/Http/Controllers/QuotationController.php` (`createContract()` — 201 minted / 200 shell completed), `Modules/Crm/Routes/api.php` (`POST quotations/{quotation}/create-contract`, `crm.create`),
  `Modules/Crm/Http/Resources/ContractResource.php` (`value_change_reason`; `quotation_code` was already there), `QuotationResource.php` (`contract_code`, `contract_needs_schedule` — only when `show()` loads `contract`, which it already did without reading it),
  `lang/id/validation.php` (`value_change_reason` → Alasan perubahan nilai), `Modules/Crm/Database/Seeders/CrmDatabaseSeeder.php` (explicit `value_change_reason => null` — see Notes),
  `public/app/js/schema.js` (`CONTRACT_FROM_QUOTATION` prefill; quotation actions "Buat kontrak" / "Lengkapi kontrak" with `opens` + `submitTo`; contract form field "Alasan perubahan nilai" under "Nilai kontrak", `visibleWhen` quotation set),
  `public/app/js/views/actions.js` (`submitTo` on `opens` actions → `endpoint`, source cache invalidated), `public/app/js/views/form.js` (`openForm({ endpoint })` posts there instead of `def.api`; toast "`<code> tersimpan.`" on that path), `public/app/js/views/detail.js` (LABELS `quotation_code` → "Dari penawaran", `value_change_reason`; WHEN_SET_KEYS + HIDDEN_KEYS entries),
  `docs/bukti-uji/harness-playwright.py` (new scenario **S17**), `docs/RECAP-UX-PROSES-2026-09.md` (**OQ-2**), `tests/Feature/Crm/ContractFromQuotationTest.php` (new, 12 tests), this block.
  **API contract:** one route added; keys added to two Resources (`value_change_reason`; `contract_code` + `contract_needs_schedule`) and one optional field to contract POST/PUT; every existing key, message and test untouched — `mark-won` still returns its 201 draft contract.
- Acceptance:
  - **Feature test, both branches.** Red first on the unpatched tree: `ContractFromQuotationTest` = **12 failures** (9 × *404* — the route did not exist; `…generic_contract_store…`: *201 instead of 422* — the production shape, a linked contract at another value accepted without a word; 2 × `contract_code` absent from the quotation show). After: **OK (12 tests, 79 assertions)** — the copy (customer, title, scope, PPN rate, `value` = DPP 2 040 000 000, `total_with_ppn` = the offer's total, `quotation_id`/`quotation_code`, 2 termins 612 jt + 1 428 jt, `sign_date`, draft); `value` 1 840 000 000 without a reason → **422** `errors.value_change_reason[0]` = `Nilai kontrak Rp 1.840.000.000,00 berbeda dari nilai penawaran QTN/… Rp 2.040.000.000,00 (DPP, sebelum PPN; selisih Rp -200.000.000,00); isi alasan perubahan nilai.` and 0 contracts minted; with a reason → 201, echoed and shown on `GET crm/contracts/{id}`; a reason typed for an unchanged value → null; the generic `POST crm/contracts` with `quotation_id` applies the same rule; `PUT` moving the value away needs the reason, a line-only PUT keeps it, moving back to the DPP clears it; not-yet-won → 422 naming the quotation and "belum ditandai menang"; a quotation whose contract already has a schedule → 422 naming that CTR; `crm.view` only → 403; **the mark-won shell is completed, not duplicated** (same id/code, 2 termins, `Contract::count()` 1, `contract_needs_schedule` true → false) and completing it at another value needs the same reason.
  - **The contract detail shows "Dari penawaran QTN/…"** — harness **S17** (new, committed) on a fresh scratch seed `t36.sqlite`, **1 ok / 0 ERROR, 3 klik** (Lengkapi kontrak · Simpan · Simpan; +1 for the Cetak-menu probe `read_action_bar` counts separately). Fixture via API: `QTN/2026/IX/0004` (2 lines, DPP 2 040 000 000) submitted by sales, approved by direktur, mark-won by sales → shell `CTR/2026/IX/0004` (201). As sales on the quotation: `action_bar [Kembali, Cetak, Lengkapi kontrak, Buat Revisi]`, `primary [Lengkapi kontrak]`, Informasi `No. kontrak: CTR/2026/IX/0004`; the form opens prefilled — `Pelanggan* CUST-0001 — PT Graha Sentosa Propertindo · Dari penawaran QTN/2026/IX/0004 — … · Judul kontrak* … · Lingkup* system_integration · Nilai kontrak (DPP)* 2.040.000.000 · Alasan perubahan nilai (visible, empty) · Tarif PPN 11 · Retensi 5 · Masa pemeliharaan 12`; value changed to 1 840 000 000 + one termin 100 % → Simpan → **422 painted under "Alasan perubahan nilai"** with the sentence above (both amounts), toast `Periksa isian yang ditandai.`, modal stays; reason typed → Simpan → `#/d/crm/contracts/4`, h1 **`CTR/2026/IX/0004`** (= the shell's number), toast `CTR/2026/IX/0004 tersimpan.`, Informasi rows `Dari penawaran: QTN/2026/IX/0004` (`dari_penawaran_text` = "Dari penawaran\nQTN/2026/IX/0004") and `Alasan perubahan nilai: UJI-UX — negosiasi akhir, …`; API `value 1840000000.00`, `quotation_code QTN/2026/IX/0004`, 1 termin, draft; `contracts_for_quotation` **1**; back on the quotation the bar is `[Kembali, Cetak, Buat Revisi]` and `No. kontrak: CTR/2026/IX/0004`. Screenshots `s17-quotation-bar.png`, `s17-contract-form.png`, `s17-value-refused.png`, `s17-contract-detail.png` under the scratch out dir.
  - PHPUnit per directory, final tree: **Crm OK (226 tests, 856 assertions)** (214 + 12), **Core OK (607, 3 695)** (unchanged — `ListingConcernTest`/`ApprovableDocuments` read the quotation index and trail only), **Finance OK (819, 3 895)** (unchanged); `vendor/bin/pint --test --dirty` passed; the harness compiles (`py_compile`).
- Notes:
  - **The shell nobody mentioned.** `QuotationService::markWon` already mints a draft contract (customer, title, scope, value = DPP, `quotation_id`) with **no termin schedule** — production's `CTR/2026/VIII/0005`, "masih draf 13 hari" after `QTN/2026/VII/0004` won, IS that shell (ANALISIS-PROSES §0 table, §2 Q2C row 1). A `create-contract` that always minted would hand every won quotation a second CTR number and break `Quotation::contract()` (hasOne). So the endpoint has three outcomes, decided by ONE server definition (`isUnfilledShell`: draft AND no termin): no contract → mint (201); the untouched shell → complete it under its own number (200); anything with a schedule or past draft → refuse naming it ("nilai yang berubah … dicatat lewat pekerjaan tambah-kurang"). The SPA shows "Buat kontrak" / "Lengkapi kontrak" from `contract_code` / `contract_needs_schedule` — no rule copied into the browser. Completing the shell goes through `update()`, so the shell's own header fields are replaced by the form's (the shell only ever held quotation-copied fields; the form is prefilled from the same quotation). Retiring the shell from `markWon` is a follow-up, not done here: it is the `mark-won` API contract (201 + ContractResource) and outside the entry's Steps.
  - **What "copy customer, project, value, line items, proposed termins" could mean here.** Contracts carry no line items — `crm_contracts` + `crm_contract_termins` only (`Contract::termins()`, no `items()`), so the offer's lines are carried as its value (DPP) and not copied; said in the service docblock. Neither table has a project column: `prj_projects.contract_id` is Projects' link and is opened FROM the contract later (`Contract::project()`), so there is no project to copy. A quotation carries no termin proposal (no column, no setting) — the schedule stays the caller's/the person's, nothing is invented; whether the form should propose a house schedule per scope is **OQ-2** in the RECAP (rule 9 — a business decision, non-blocking).
  - **"Quotation total" is compared DPP to DPP.** `crm_contracts.value` excludes PPN and `crm_quotations.dpp` is the offer before PPN; the PPN rate is copied along, so the totals agree exactly when these do (pinned: `total_with_ppn` == the offer's `total`). The message says "(DPP, sebelum PPN)" so the person knows which pair of numbers is being compared, and formats with 2 decimals like `ContractChangeOrderService`.
  - **The rule lives in `ContractService`, not only in the endpoint** — `create()` and `update()` both judge a linked contract against its quotation, so `POST crm/contracts` with `quotation_id` and the Ubah form refuse the same way (T3.8's PUT precedent: a rule enforced only on create is bypassed on the first edit). Honesty contract mirrors `pr_bypass_reason`: stored only while it explains a difference; same value, or no quotation, stores null whatever was typed; an absent key on PUT keeps the stored reason. Hence `value_change_reason` sits in WHEN_SET_KEYS (null always means "same as the offer"), unlike T3.8's column which shows "—" on purpose.
  - **Form and machinery.** The contract form gains one field, "Alasan perubahan nilai" (textarea, `visibleWhen` a quotation is linked, right under "Nilai kontrak") — the 422 is painted there with the server's sentence, no `confirmResubmit` needed. `opens` actions gained `submitTo` (actions.js) and `openForm` an `endpoint` (form.js), ~10 lines each, so the prefilled contract form saves to the quotation's endpoint instead of `crm/contracts`; the source document's cache is invalidated on save. The save toast on that path is "`<code> tersimpan.`" — "Kontrak dibuat." would be false for the completed shell.
  - **Labels.** `quotation_code` is now "Dari penawaran" everywhere it renders (contract detail; the TKDN worksheet detail also exposes it, where the reading is the same) — the label the contract form already used for the lookup. The contract detail still also shows the ID_LOOKUPS row `Penawaran: QTN/… — <judul>` above it (pre-existing, `quotation_id`); not collapsed here.
  - **Seeder.** All three seeded contracts equal their quotation's DPP to the rupiah (48,5 M / 9,8 M / 480 jt — the summed lines), verified on the fresh seed (`value_change_reason` NULL ×3); the seeder now writes the column explicitly so a future edit that moves a seeded value meets the same question. The `CrmDatabaseSeeder` shape change is the only seeder touch B3 asked for.
  - **Carry-overs, not in this entry's Steps:** "Buat Revisi" still renders on a won quotation (its `when` is status-based; the server refuses with "has been won; revise via the contract") — a `when` tweak, pre-existing. `createFromQuotation` ignores a sent `customer_id`/`quotation_id` silently (the request has no rule for them, so `validated()` drops them) rather than refusing — documented in the request. The endpoint's `title`/`scope_type` overrides are validated but the SPA sends the prefilled ones. No Finance file changed (the entry's "touch Crm and Finance" applied to T3.7's dunning letters).
  - Environment: `php artisan config:clear`; fresh scratch seed `<scratchpad>/ux/t36.sqlite` (`DB_DATABASE=<scratch> php artisan migrate:fresh --seed --force`, column verified with sqlite3 `pragma table_info`); server started by `<scratchpad>/ux/run-s17.sh` as its own statement (`cd public; DB_DATABASE=<scratch>/t36.sqlite APP_ENV=local nohup php -S 127.0.0.1:8000 …/server.php &`, pid file, `/up` polled, killed by pid after the run — :8000 free); harness one-liner `ERP_DB=<scratch>/t36.sqlite UXTEST_OUT=<scratch>/out-t36 /root/.venv-playwright/bin/python docs/bukti-uji/harness-playwright.py S17`; `database/database.sqlite` untouched (mtime 2026-09-04 13:10:55, no `-wal`/`-shm`).

### T3.7 — Surat penagihan ke-1/2/3 sebagai formulir rumah: tiga entri registri (kunci baru `prose` + `onlyWhen`), `fin_ar_invoices.dunning_level` + `last_dunning_at`, aksi "Cetak surat penagihan ke-N" (POST `{id}/dunning` → cetak), jejak audit, badan pengawas `ar_invoice_due` menyebut tingkatnya
- Commit: (this commit) — T3.6 above is 04a8f75.
- Files: `Modules/Finance/Database/Migrations/2026_09_04_000395_add_dunning_to_fin_ar_invoices_table.php` (new — `dunning_level` unsignedTinyInteger default 0 + `last_dunning_at` datetime nullable after `paid_at`; `hasColumn` guard, rollback; the 000394/000870 pattern, forward-only: 0 is the truth for every existing invoice),
  `Modules/Finance/Models/ArInvoice.php` (casts; `DUNNING_LEVELS = 3`; **`dunningRefusal()`** — the ONE definition of when the next letter may be issued: cancelled / not approved / fully paid / `due_date > today` / already ke-3 → an Indonesian sentence, else null; `dunningNextLevel()`),
  `Modules/Finance/Services/ArInvoiceService.php` (`issueDunningLetter()` — `lockForUpdate`, refusal → `LogicException` (422), level +1, `last_dunning_at = now`, **`AuditService::event(…, 'dunning', …)`**),
  `Modules/Core/Services/AuditService.php` (new public `event()` for a named event on a model that is NOT observed — documents are absent from AuditedModels on purpose; `write()` takes an optional label),
  `Modules/Finance/Http/Controllers/ArInvoiceController.php` (`dunning()` — message "Surat penagihan ke-N <kode> diterbitkan."), `Modules/Finance/Routes/api.php` (`POST ar-invoices/{arInvoice}/dunning`, `fin.update` like `faktur`), `Modules/Finance/Http/Resources/ArInvoiceResource.php` (`dunning_level`, `last_dunning_at`, `dunning_next_level`),
  `Modules/Finance/Services/FinanceFormService.php` (`DUNNING_TITLES`, **`dunningLetterDate()`** = the gate: only the CURRENT level's sheet renders, dated `last_dunning_at`; a level not reached → "belum diterbitkan … Terbitkan lewat tombol …"; a level passed → "sudah digantikan surat ke-N; tanggal terbitnya tidak tersimpan …" — both `InvalidArgumentException` → 422 from FormPrintController; `dunningDaysLate()` counted to the LETTER's date; `dunningRows()`; **`dunningParagraphs()`** — three registers, the invoice number the subject of every sentence),
  `Modules/Core/Support/PrintableDocuments.php` (docblock for two new entry keys **`prose`** (one VALUE SPEC → list of paragraphs, handed record + sheet date) and **`onlyWhen`** (`['field','equals']`, data that crosses the wire); `definition()` defaults; `catalogue()` emits `onlyWhen` (null for the seven bespoke rows); `finance()` entries `surat-penagihan-1/2/3` via `suratPenagihan(int $level)` — customer band + PROYEK box, `date` = the letter date, identity NO. INVOICE · TANGGAL INVOICE · JATUH TEMPO · LEWAT JATUH TEMPO · KEPADA · ALAMAT · NO. KONTRAK · NILAI INVOICE · SUDAH DITERIMA · SISA TAGIHAN (+ **BATAS PEMBAYARAN ruled** on ke-2/ke-3), prose, one-row RINCIAN TAGIHAN + TERBILANG (SISA TAGIHAN), no Catatan box, signatures Diterima oleh (pelanggan, unnamed) · Disiapkan · Hormat kami/Direktur, `Form F/SP-N`),
  `Modules/Core/Services/FormPrintService.php` (`registryHeader()` hands back the composed Carbon date as `$header['date']`; `registryDocument()` passes `prose`; new `registryProse()` — trims, drops blanks, no cast, no ruling), `Modules/Core/Resources/views/forms/generic.blade.php` (the `prose` loop — see Notes for why it is flush-left with an inline style),
  `Modules/Core/Support/WatchedDeadlines.php` (new optional entry key **`detail`** `['columns' => […], 'text' => fn ($row)]`: extra columns of the entry's own table, read per row and appended as a clause — checked in `finding()` itself and DROPPED when absent, never a SKIP; `ar_invoice_due` declares it: "belum ada surat penagihan" / "surat penagihan ke-N dicetak <tanggal>"; `sentence()` appends "; <detail>"),
  `Modules/Finance/Database/Seeders/FinanceDatabaseSeeder.php` (explicit `dunning_level => 0`, `last_dunning_at => null` on INV/2026/II/0001 — settled by RCV/2026/II/0001, so 0 is the truth),
  `public/app/js/schema.js` (three actions `dunning-1/2/3` on `finance/ar-invoices`, `when: row.dunning_next_level === level`, `confirm` names the invoice + consequence, `printForm`, `toast`), `public/app/js/views/actions.js` (`confirm` may be a function of the row; `toast` may be a function; **`printForm`**: tab opened BEFORE the POST while the confirm click is on the stack, filled after both land, closed on refusal/cancel; popup blocked = no POST), `public/app/js/print.js` (`openPrintable` split into `openPrintTab()` + `showPrintable()`; existing callers unchanged), `public/app/js/printcatalog.js` (carries `onlyWhen`; new `printableFor(form, row)`), `public/app/js/views/detail.js` (`formMenuItems` filters with `printableFor`; LABELS `dunning_level` "Tingkat penagihan", `last_dunning_at` "Surat penagihan terakhir" (WHEN_SET); `dunning_next_level` hidden — button state), `public/app/js/views/list.js` (row print buttons filter with `printableFor`),
  `tests/Feature/Finance/DunningLetterTest.php` (new, 12 tests), `tests/Feature/Core/DeadlineWatchTest.php` (+4: works / level named / refused / columns-absent degrade), `tests/Feature/Core/PrintRegistryTest.php` (fixture `uji-prosa` + 2 engine tests), `tests/Feature/Core/PrintCatalogueBespokeTest.php` (61 → **64** = 57 registri + 7), this block, `docs/RECAP-UX-PROSES-2026-09.md` (OQ-3, non-blocking).
  **API contract:** one route added; three keys added to `ArInvoiceResource`; one key (`onlyWhen`) added to every catalogue row; no existing key, message, mask or number format changed — no DocumentNumberService mask (the letter carries no number of its own: the invoice number is the subject).
- Acceptance:
  - **Three printable letters render (markup read)** — fresh scratch seed `t37.sqlite` + one invoice planted in INV/2026/VIII/0004's production shape (Rp 15,42 M approved, `due_date` 2026-08-22 so it is overdue on the run day; the seed's own INV/2026/II/0001 is paid). As `finance@` (fin.update): `GET core/print/forms/surat-penagihan-1/2` BEFORE any letter → **422** "Surat penagihan ke-1 INV/2026/VIII/0004 belum diterbitkan — belum ada surat penagihan yang diterbitkan. Terbitkan lewat tombol \"Cetak surat penagihan ke-1\" pada invoice itu."; `POST ar-invoices/2/dunning` → **200** "Surat penagihan ke-1 INV/2026/VIII/0004 diterbitkan.", `dunning_level 1`, `last_dunning_at 2026-09-05T03:10:06+07:00`, `dunning_next_level 2`; `GET …/surat-penagihan-1/2` → **200 text/html 18 128 bytes**: `<div class="form-title">SURAT PENAGIHAN PERTAMA</div>`, `Form F/SP-1`, **5 `<p class="alinea">`**, "Bersama surat ini kami mengingatkan bahwa invoice INV/2026/VIII/0004 tertanggal 23 Juli 2026 senilai Rp 15.420.000.000,00 atas kontrak CTR/2026/I/0001 telah jatuh tempo pada 22 Agustus 2026. …"; `GET …/surat-penagihan-2/2` at level 1 → **422** "… belum diterbitkan — tingkat penagihannya masih ke-1 …". Letter ke-2 was issued THROUGH THE SPA (probe below). Then `POST` → **200** ke-3 (`dunning_next_level null`); 4th `POST` → **422** "Invoice INV/2026/VIII/0004 sudah pada surat penagihan ke-3 (terakhir); penyelesaian selanjutnya mengikuti ketentuan kontrak, bukan surat lagi."; `GET …/surat-penagihan-3/2` → **200 18 773 bytes**: `SURAT PENAGIHAN KETIGA (TERAKHIR)`, `Form F/SP-3`, 5 paragraphs, "Surat ini merupakan surat penagihan ketiga dan terakhir atas invoice INV/2026/VIII/0004 … sisa tagihan sebesar Rp 15.420.000.000,00 telah melewati jatuh tempo 22 Agustus 2026 selama 14 hari." and "… penyelesaian selanjutnya akan kami tempuh sesuai ketentuan kontrak CTR/2026/I/0001 …"; `GET …/surat-penagihan-1/2` and `-2/2` at level 3 → **422** "… sudah digantikan surat ke-3; tanggal terbitnya tidak tersimpan, sehingga tidak dicetak ulang dengan tanggal lain — cetak surat ke-3." Catalogue as finance: 34 rows, the three slugs with `onlyWhen {field: dunning_level, equals: N}`, every other row `null` (admin sees 64 — pinned). `core_audit_log`: 3 rows `event dunning`, `auditable_label INV/2026/VIII/0004`, user "Dewi Lestari", changes `{dunning_level: {from 0→1 | 1→2 | 2→3}, last_dunning_at: {…}}`, ip 127.0.0.1.
  - **SPA probe** (standalone Playwright on the same server, not committed — the entry names no harness scenario; `<scratch>/ux/probe-t37.py`, screenshots in `<scratch>/ux/out-t37/`): login finance → `#/d/finance/ar-invoices/2` at level 1: **0 console errors, 0 page errors**; `action_bar [Kembali, Cetak, Catat Faktur Pajak, Cetak surat penagihan ke-2, Batalkan Dokumen]` (ONLY the next level); Informasi rows "Tingkat penagihan", "Surat penagihan terakhir"; Cetak ▾ `[Cetak halaman, Unduh PDF, Cetak Surat Penagihan ke-1]` (only the current letter's reprint — not ke-2/ke-3); click → confirm "Surat penagihan ke-2 INV/2026/VIII/0004 akan dicetak dan tingkat penagihan invoice ini naik ke 2 — tercatat di jejak audit dan disebut pengawas jatuh tempo. Surat ke-1 tidak dapat dicetak ulang setelahnya." buttons `[Batal, Cetak surat penagihan ke-2]`; confirm → `POST 200`, **popup tab** title "SURAT PENAGIHAN KEDUA — PRJ-2026-001", `.form-title` SURAT PENAGIHAN KEDUA, `Form F/SP-2`, 5 paragraphs ("Merujuk Surat Penagihan Pertama kami atas invoice INV/2026/VIII/0004 …"); toast **"Surat penagihan ke-2 INV/2026/VIII/0004 diterbitkan."**; after reload `action_bar […, Cetak surat penagihan ke-3, …]`, Cetak ▾ `[…, Cetak Surat Penagihan ke-2]`.
  - **Watcher body carries the level** — `DB_DATABASE=<scratch>/t37.sqlite php artisan erp:deadline-watch --dry-run`: BEFORE any letter `ar_invoice_due [lewat]: 1 row(s) -> fin.create / INV/2026/VIII/0004 senilai Rp 15,4 M jatuh tempo 22 Agu 2026 — 14 hari lalu; belum ada surat penagihan.` (Checked 21, skipped 0, blind 1, raised 8); after ke-1 `… — 14 hari lalu; surat penagihan ke-1 dicetak 5 Sep 2026.`; after ke-3 `… surat penagihan ke-3 dicetak 5 Sep 2026.` (14 not 13: command "today" is Asia/Jakarta, run after 00:00 WIB 5 Sep — the T3.1 note).
  - **Tests, red first on the unpatched tree** (`git stash push -u -- Modules public`, tests kept): `DunningLetterTest` = **6 errors + 6 failures** of 12 (no column, no route, no slugs, schema text absent); Core new tests = **3 failures + 2 errors** of 7 (body lacks the clause; catalogue 61 ≠ 64; prose 0 ≠ 2; the level-named and columns-absent tests error on the missing column), 2 green by design (paid/cancelled/draft silent; penawaran without prose). After: **Finance OK (831 tests, 4 047 assertions)** (819 + 12), **Core OK (613, 3 715)** (607 + 6), **Crm + 16 print-test files of the other modules OK (426, 1 792)** — the blade change touches every registry sheet; `vendor/bin/pint --test --dirty` passed.
- Notes:
  - **The gate is the date, and it refuses in both directions.** `fin_ar_invoices` keeps ONE `last_dunning_at` (the entry's own scope: `dunning_level` + `last_dunning_at`), so the letter of the current level is dated by it and every "N hari" on the sheet counts to it — a reprint in December of the letter of 10 Oktober still says 10 Oktober and 18 hari (test `test_a_reprint_keeps_the_letters_own_date`; the foot's "Dicetak …" stamp stays honest about the reprint day). A level ABOVE the current one has no record of having gone out, and a level BELOW it went out on a day the ERP no longer holds — printing it re-dated to today is exactly what the penawaran entry's own docblock refuses — so both are 422 by name, each telling the person which letter they CAN print. Storing a date per letter would have been three more columns for a reprint the customer already holds; not done, stated.
  - **Why the escalation needs a POST and why the tab opens first.** The Cetak ▾ items are GETs and reprints; escalation is a state change (level +1, audit row), so it is an ACTION: `POST {id}/dunning`, no body — the next level is the invoice's, never the caller's, so two clicks cannot issue two letters bearing the same number (row lock + monotonic +1, refused at 3). The sheet for that level only exists AFTER the POST; `window.open()` after an `await` is blocked as a popup (print.js's own measured rule), so `runAction` opens the tab while the confirm-button click is still on the stack and fills it once the POST and the fetch land; refused or cancelled → the tab is closed, and a blocked popup means NO POST (a level must not move without a sheet to hold). `openPrintable()` was split into its sync and async halves for this; every existing caller is unchanged.
  - **`onlyWhen` — one resource, three letters, no dead menu items.** Without it every invoice's Cetak ▾ would carry three items of which two (or three, on a draft) answer 422 — the 403-button the catalogue exists to prevent. Declared as data (`{field, equals}`) so it crosses the wire; `printableFor()` is the one predicate for the detail menu, the custom screens' `formButtons` and the list rows. The catalogue count is unchanged in shape (+1 key on every row).
  - **What the letters say and do not say.** ke-1 reminds ("mengingatkan … mohon berkenan"), ke-2 demands by a date ("meminta … selambat-lambatnya"), ke-3 says it is the last and names what follows. No payment deadline of the ERP's invention: "7 hari kerja" nobody agreed would be a term stated under the letterhead, so ke-2/ke-3 point at the **BATAS PEMBAYARAN identity line, ruled for the pen** — the owner's own paper convention for a cell the ERP cannot answer. No penalty, interest or suspension is threatened (`fin_ar_invoices` holds no such clause; the contract that may is NAMED — "sesuai ketentuan kontrak CTR/…"). No bank account (the invoice PDF carries the transfer instruction; the letter asks for the invoice number on the transfer note, as that document does). Whether the company wants a house deadline / next step printed is **OQ-3** in the RECAP — non-blocking. The first letter is issuable from the due date itself (`due_date <= today`), the same morning the watcher's LEWAT tier first names the invoice, because the body claims "telah jatuh tempo pada <tanggal>".
  - **Audit-logged, explicitly.** `fin_ar_invoices` is not observed (AuditedModels: documents absent on purpose — their lifecycle is core_approvals) and a surat penagihan is not an approval transition; writing an `action 'dunning'` row into the approval trail would have mixed it into `ApprovalLevels::distinctApprovals` and the status strip. So `AuditService::event()` (new, public, guarded, the observer's own from/to shape) writes `core_audit_log` with `auditable_label` = the invoice number; the audit-log endpoint's `class_basename` filter finds it under "ArInvoice". Calling the existing `updated()` after `save()` would have logged nothing: `syncOriginal()` has already run and the from/to compare to equal.
  - **The engine change and the byte-identical trap.** A letter body has no honest place on a grid-only sheet (a one-column table titled ISI SURAT is not a letter on a customer's desk), so the registry gained `prose` — ~30 lines in FormPrintService + a Blade loop, tested on the fixture registry (`uji-prosa`: blanks dropped, order before the tables, none on a document without prose). First cut broke `CrmFormPrintTest::test_a_tambah_kurang_cco_prints_byte_identically_to_the_pre_p0b_renderer` twice (a CSS rule in layout.blade, then a Blade comment leaving a newline): that golden fixture must not be regenerated, so `layout.blade.php` is restored byte-for-byte, the paragraph style rides inline, and the `@foreach` is flush-left so a document without prose contributes zero bytes — said in generic.blade's docblock.
  - **`detail` on the watcher degrades, it does not skip.** The dunning columns are NOT in the entry's `columns` on purpose: those gate the whole entry (SKIP line), and the letter clause is an embellishment of the alarm, never its reason — an un-migrated `fin_ar_invoices` still raises the overdue alarm, only without the clause (test drops both columns and asserts no `SKIP ar_invoice_due`). The dedupe fingerprint (codes + count) is unchanged, so a level change does not re-fire inside the 3-day renag window; the next fire says the new level.
  - **Scope honoured:** no new document number/mask (`format count` untouched); no seeder beyond the explicit 0; `FormXlsxExportService::FORMS` not extended (a letter is not a spreadsheet); the harness was not extended — the entry names no scenario, and the SPA leg is the standalone probe above (kept in scratch, its JSON + 6 screenshots in `<scratch>/ux/out-t37/`).
  - Environment: `php artisan config:clear`; fresh scratch seed `DB_DATABASE=<scratch>/ux/t37.sqlite php artisan migrate:fresh --seed --force` (column verified with sqlite3 `pragma table_info`); the invoice planted with `DB_DATABASE=… php artisan tinker --execute=…` on the scratch file only; server as its own statement `cd public; DB_DATABASE=<scratch>/ux/t37.sqlite APP_ENV=local nohup php -S 127.0.0.1:8000 …/server.php > <scratch>/ux/server-t37.log 2>&1 &` (pid 34506 in a pid file, `/up` polled, killed by pid after — `:8000` free); probe `ERP_DB=<scratch>/ux/t37.sqlite UXTEST_OUT=<scratch>/ux/out-t37 /root/.venv-playwright/bin/python <scratch>/ux/probe-t37.py 2 2`; `database/database.sqlite` untouched (mtime 2026-09-04 13:10:55, no `-wal`/`-shm`).
