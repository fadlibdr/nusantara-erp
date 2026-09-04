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
