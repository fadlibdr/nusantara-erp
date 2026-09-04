# Nusantara ERP — UX & Process Fix Backlog (for Claude Code)

Recap of everything found in the 2–4 Sep 2026 assessment cycle, written as
tasks Claude Code can execute in `fadlibdr/nusantara-erp`. Sources:
`ASESMEN-UX-2026-09.md` (critique + research plan), `HASIL-UJI-UX-2026-09.md`
(measured before/after, sandbox + production), `ANALISIS-PROSES-BISNIS-2026-09.md`
(process chains, gaps), `ux-p0-and-process.patch` (already-built fixes),
`bukti-uji/` (screenshots, raw results, Playwright harness).

Instructions are in English for the agent; **all user-facing strings stay in
Bahasa Indonesia** and follow the repo's own copy rules (§ Conventions).

---

## Conventions Claude Code must keep

- **No framework, no build step.** Front-end is vanilla ES modules in
  `public/app/`. Build UI with `el()` from `ui.js`; screens are declared in
  `schema.js` (`RESOURCES`, `NAV`); modals via `modal()`; toasts via `toast()`.
- **Comments explain *why*, with the measured evidence** — the codebase already
  does this (see `dashboard.js`, `SegregationOfDuties.php`). Keep the habit.
- **Core never imports feature modules.** In `Modules/Core`, use `DB::table` and
  string literals (see `WatchedDeadlines.php` header).
- **Every behaviour change ships with a Feature test** under `tests/Feature/<Module>/`.
  Base class `Tests\ErpTestCase`; user/role helper pattern in
  `tests/Feature/Core/DeadlineWatchTest.php::userWith()`.
- **Copy rules** (from the codebase, now written down):
  1. Never state what you don't know ("Tidak ada dokumen yang dapat ditampilkan" ≠ "Tidak ada dokumen").
  2. Confirmations name the consequence, not "Yakin?".
  3. Buttons are labelled with their verb (Hapus / Posting / Buat PO), never OK.
  4. The document number is the subject of the sentence (`PO/2026/IX/0004 diajukan`).
  5. Field terms, acronyms only when printed on the form (`Banding Penawaran (RFQ)`).
- Run tests per directory (the full suite exceeds 20 min):
  `vendor/bin/phpunit --no-progress tests/Feature/Core` etc.
- Local run for UI checks: `cd public && php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
  then `python3 bukti-uji/harness-playwright.py S10 S1 S2 S3 S4 S11` (Playwright, headless Chromium).

---

## Phase 0 — Apply what is already built

**T0.1 Apply the patch**
```
git checkout -b ux/p0-measured
git am docs/patches/ux-p0-and-process.patch   # 2 commits, 22 files (uploads were moved under docs/patches/, T0.0)
php artisan config:clear
vendor/bin/phpunit --no-progress tests/Feature/Core tests/Feature/Procurement tests/Feature/Iam
```
Contents (all measured before/after in HASIL-UJI §1 and §4):

| Area | What the patch does | Files |
|---|---|---|
| Validation language | `lang/id/validation.php`: 70 rules + 586 attribute names generated from `schema.js` labels + 162 `prefix.*.column` entries | `lang/id/validation.php` |
| Approval inbox | `GET core/inbox` over all 28 `ApprovableDocuments`; dashboard card 5 rows + "Lihat semua"; new `#/tugas` screen; nav item | `Modules/Core/Support/ApprovalQueue.php`, `InboxController.php`, `Routes/api.php`, `views/dashboard.js`, `views/tugas.js`, `schema.js` |
| Session expiry | Local drafts (`drafts.js`), autosave 1.2 s, flush on 401, `closeAllModals()`, login banner, "Pulihkan / Buang" offer after re-login | `drafts.js`, `views/form.js`, `ui.js`, `app.js` |
| Flow | Create lands on the new document; toasts name the document; "Berikutnya" offer after approve/reject | `views/list.js`, `views/actions.js` |
| Status strip | One sentence under the title per status (draft / submitted / rejected / locked) | `views/detail.js` |
| Tokens | `--muted #5e6874`, `--success #17714a`, chart text 11 px, `.btn.sm` 36 px on coarse pointer | `app.css` |
| Demo accounts | `GET iam/auth/demo-accounts` empty in production | `Modules/Iam/Routes/api.php`, `app.js` |
| 422 toast | No duplicated title | `ui.js` |
| Approval aging | `erp:approval-watch` 08:45, setting `approvals.aging_days` (default 5), escalation to `fin.approve` at 2×; owner fallback in the queue | `Console/Commands/ApprovalWatchCommand.php`, `CoreServiceProvider.php`, `SettingService.php`, `config/erp.php`, `tests/Feature/Core/ApprovalWatchTest.php` |

**T0.2 Fix the pre-existing red test** (red on `main` today, date-dependent):
`tests/Feature/Finance/RevenueRecognitionTest::test_the_catch_up_lands_in_the_month_the_reversal_was_posted`
— `cancel()` uses the real clock; move `$this->travelTo(...)` before the cancel
(or freeze `Carbon::setTestNow` in `setUp`). Acceptance: green on any calendar date.

---

## Phase 1 — Production deployment (do first, highest impact per hour)

**T1.1 Permission drift — re-seed roles.** Production `admin` holds 74/86
permissions; `eng.*` and `qc.*` missing → Engineering shows 1 screen, Mutu group
absent, IPP/inspection/NCR unreachable by anyone.
- Run on the box: `php artisan db:seed --class=Modules\\Iam\\Database\\Seeders\\PermissionSeeder && php artisan db:seed --class=Modules\\Iam\\Database\\Seeders\\RoleSeeder`
  (`findOrCreate` + `syncPermissions`, idempotent; **overwrites manual role edits** — check `Sistem › Peran & Hak Akses` first).
- Add a drift check to `deploy/sync-erp1.sh`: compare `Permission::count()` with the seeder's expected count (`PermissionSeeder::PREFIXES × ACTIONS + DIRECTOR_APPROVALS`); exit non-zero with a message if they differ.
- Acceptance: `iam/auth/me` for admin returns 86 permissions; sidebar shows 14 groups / 122 links (121 + Tugas Saya).

**T1.2 SQLite stability.** Two 503s in three days; requests hang under a burst
of ~40; dashboard fires 21 parallel requests per open.
- Verify/set `PRAGMA journal_mode=WAL` and `busy_timeout` (≥ 5000 ms) in `config/database.php` sqlite connection options; document in `docs/DEPLOYMENT.md`.
- Check `pm.max_children` in php-fpm pool; raise to 10–16 if ≤ 5.
- Add `/up` to the deploy health check with a 3-request burst.
- Acceptance: 40 sequential+parallel list requests from the harness complete without a 503.

**T1.3 Harden demo logins** — `erp:harden-demo-logins` exists; confirm it ran on production; the login page no longer lists accounts after T0.1.

---

## Phase 2 — UX items not yet built (P1)

Each task: evidence → files → steps → acceptance. Effort in person-days.

**T2.1 422 toast: hide raw keys when the field was painted** (¼ d)
Evidence: toast shows `items.0.qty: Kuantitas minimal 0.001.` while the cell is already marked.
Files: `views/form.js` (`paintErrors`), `ui.js` (`toastError`).
Steps: collect the keys successfully mapped by `applyLineError`/`setFieldError`; pass only unmapped errors to `toastError`; if none unmapped, toast `Periksa isian yang ditandai.` only.
Acceptance: harness `S3 › toast_on_422` contains no `items.N.` prefix.

**T2.2 Date input helper** (¼ d)
Evidence: native `type=date` renders `mm/dd/yyyy` under en-US locale.
Files: `views/form.js` (`case 'date'`).
Steps: keep native input; add `.help` line that shows `= 2 Sep 2026` via `fmt.date` on change; empty when no value.
Acceptance: screenshot in harness S3 shows the helper under `Tanggal PO`.

**T2.3 Approval note inline, not modal** (1 d)
Evidence: every approval = extra modal step; note is optional.
Files: `schema.js::approvalActions`, `views/actions.js`, `views/detail.js`.
Steps: for `approve`, render an optional `textarea` "Catatan persetujuan" in the detail action zone (collapsed by default, "Tambah catatan" toggle); `Setujui` posts directly with the note if present. Keep the modal path for `reject` (reason required).
Acceptance: harness S2 clicks per approval = 2 (Setujui, Buka berikutnya); API contract unchanged.

**T2.4 PO "Ajukan" override reason via confirm-resubmit** (½ d)
Evidence: every PO submit opens a modal for an optional field.
Files: `schema.js` PO `submit` action (~line 2237), `views/actions.js` (`confirmResubmit`).
Steps: remove `fields` from the submit action; add a `confirmResubmit` rule that catches the prequalification 422 (`qualification_override_reason`) and prompts for the reason, then resubmits with it. Extend `confirmResubmit` to support a `promptField` (textarea, required) in addition to the boolean flag.
Acceptance: submitting a PO to a healthy vendor = 1 click; blocked vendor → prompt with server message → resubmit succeeds; existing tests `PoQualificationOverrideAuditTest` green.

**T2.5 Sidebar: favorites, recent, collapsed-by-default, dividers, nav in Ctrl+K** (1½ d)
Evidence: admin 121 links / 4.9 viewports; all groups open; Proyek & Keuangan 20 links flat.
Files: `app.js` (`buildNav`, `NAV_STATE_KEY`), `schema.js` (`NAV`), `search.js`, `app.css`.
Steps: (a) `{ divider: 'Izin & K3' }` items in `NAV`, rendered as a small caption; Proyek → Pelaksanaan · Serah terima · Izin & K3 · Register; Keuangan → AR/AP · Kas · Pelaporan · Pajak · Master. (b) Groups collapsed by default except the active one and Ringkasan; preference persists as today. (c) "Favorit" group (star toggle on hover, `localStorage`) and "Terakhir dibuka" (last 5 detail routes) above Ringkasan. (d) `search.js`: include `NAV` entries as a source ("Layar") filtered by permission.
Acceptance: harness S5 `viewportsTall` for admin ≤ 2.0 with defaults; Ctrl+K "opname" lists 3 screens.

**T2.6 Detail action bar zoning + "Cetak ▾"** (1 d)
Evidence: PR/PO bars mix Kembali · Cetak · PDF · N house forms · Ubah · decisions in one row.
Files: `views/detail.js` (page-head actions), `printcatalog.js`, `ui.js` (a small menu primitive), `app.css`.
Steps: left = Kembali; centre = one `Cetak ▾` menu containing "Cetak halaman", "Unduh PDF", and every house form; right = Ubah + lifecycle actions, only the primary decision uses `.primary`.
Acceptance: harness S2 `action_bar` length ≤ 4 on a PO with 2 house forms.

**T2.7 Self-service password** (½ d)
Evidence: account menu = Tutup · Keluar only.
Files: `Modules/Iam/Routes/api.php`, `AuthController`, `app.js` (userchip menu).
Steps: `PUT iam/me/password { current, password, password_confirmation }` (validates current); menu item "Ganti kata sandi" → modal; "Lupa kata sandi" on login only when `MAIL_MAILER !== 'log'`, otherwise a line naming the administrator.
Acceptance: Feature test for wrong current password (422, Indonesian message) and success.

**T2.8 Status tone per enum** (¼ d)
Evidence: `open` → green for NCR, K3 incidents, defects (`format.js:129`).
Files: `format.js` (`statusTone`), `enums.js`.
Steps: let enum definitions carry `tone` per value; `statusTone(value, enumName)` prefers it; `open` red for `ncr`, `safety_incident`, `defect`; green stays for service tickets.
Acceptance: unit-style test in a small JS test file or harness S7 on a seeded open NCR.

**T2.9 Lapangan upload progress + retry queue** (1 d)
Evidence: base64 JSON upload up to 5 MB on mobile networks, no progress indicator.
Files: `views/lapangan.js`, `api.js` (one XHR exception for `upload.onprogress`).
Steps: per-photo progress bar; failed uploads stay listed with "Kirim ulang"; queue persists in `localStorage` until sent.
Acceptance: throttled network in Playwright (`context.route` delay) shows progress; retry succeeds.

**T2.10 Accessibility leftovers** (¼ d)
Files: `ui.js` (`installRowKeys`), `app.css`.
Steps: `aria-description="Tekan Enter untuk membuka"` once per clickable tbody; raise the last 10 px text (`bell-count` etc.) to 11 px; on `pointer: coarse` show text labels next to row-action icons or collapse into one "⋯" menu.
Acceptance: harness S8 `smallest_font_px` ≥ 11.

**T2.11 Ubin per role on the dashboard** (P2, after research — see §Research)
Evidence: procurement and hr see zero stat tiles; card "Menunggu persetujuan Anda" renders for roles with no approve permission.
Minimal now: hide the approvals card when `session.can` has no `.approve` permission; show `Tugas Saya` link only when relevant.

---

## Phase 3 — Process items (from ANALISIS-PROSES §3)

**T3.1 AP bill payment due-date watch** (½ d) — B1
Evidence: BIL/2026/VII/0002 approved, 69 days past due, unpaid.
Files: `Modules/Core/Support/WatchedDeadlines.php` (one new entry), `tests/Feature/Core/DeadlineWatchTest.php`.
Entry: key `ap_due`, table `fin_ap_bills`, date `due_date`, display `code`, value `total_payable`/`net_payable` (check column), label `Tagihan vendor`, `date_word` `jatuh tempo`, `lead_days` 7, permission `fin.create`, link `r/finance/ap-bills`, scope `status='approved'` and not fully paid (check the paid/outstanding column used by AP aging; mirror `DanglingDocuments`).
Also: "Buat pembayaran" button on an approved AP bill (`schema.js` `prefill` to `finance/payments`) if not already present.
Acceptance: works/refused pair in DeadlineWatchTest; production dry-run lists BIL/2026/VII/0002.

**T3.2 Ticket SLA watch** (½ d) — D2
Evidence: 4 tickets past SLA, no escalation.
Files: `WatchedDeadlines.php`, ServiceDesk ticket resource (expose `sla_due_at`), `DeadlineWatchTest`.
Entry: key `ticket_sla`, table `svc_tickets`, date `sla_due_at`, scope status in (`open`,`assigned`,`in_progress`), lead 0, permission `svc.update`.
Acceptance: watch fires for an assigned ticket past `sla_due_at`; resolved tickets silent.

**T3.3 Approval trail visible on every approvable document** (½ d) — C2
Evidence: only 5 of 28 `show()` methods load `approvals`; PR/PO/RAP/BOQ/SPK/invoice pages have no "Persetujuan" card; status strip falls back to a sentence without name/date.
Files: the 23 controllers' `show()` (list: `grep -L "approvals" Modules/*/Http/Controllers/*Controller.php` intersected with `ApprovableDocuments::all()`), their Resources (`'approvals' => $this->whenLoaded('approvals', ...)` — copy the shape from `PaymentResource.php:90`).
Acceptance: `GET procurement/purchase-requisitions/{id}` returns `approvals[]` with `action, user.name, note, created_at`; harness S2 `explanation_under_title` contains a name and date after approval.

**T3.4 Maker-checker for documents without a submit trail** (½ d) — C3
Evidence: PR/2026/III/0002 (seeded to `submitted`) approved by its own requester on production.
Files: `Modules/Core/Support/SegregationOfDuties.php`, `DocumentImportService.php`, seeders that write `status => 'submitted'`.
Steps: (a) `assertNotSubmitter`: when no `submitted` row exists, fall back to `requested_by`/`created_by`/`submitted_by` if the column exists — refuse when it equals the approver (keep the documented "submit as nobody" path for `RetentionService`, which has no owner column match). (b) `DocumentImportService` and seeders write a `submitted` approval row (user = importer/seed admin) when they set `submitted`.
Acceptance: test: seeded-submitted PR with `requested_by = approver` → refused with the existing named-person message; retention release bill still approvable.

**T3.5 Required dates that drive the watchers** (½ d) — D1
Evidence: PO without `expected_date` and PR without `needed_date` are invisible to `deadline-watch`.
Files: `schema.js` (PO/PR forms `required: true`), FormRequests (`required` rule), `svc` ticket creation computes `sla_due_at` from priority (check `ServiceDesk` settings for SLA hours).
Acceptance: creating a PO without `expected_date` → 422 `Perkiraan kirim wajib diisi.`; a new ticket has `sla_due_at`.

**T3.6 Contract from a won quotation** (1 d) — A1
Evidence: QTN Rp 2,04 M → CTR Rp 1,84 M typed by hand, no link.
Files: `Modules/Crm/Routes/api.php` (`POST quotations/{q}/create-contract`), `ContractService`, `schema.js` (action "Buat kontrak" on a won quotation, `prefill`), contract model `quotation_id` (migration).
Steps: copy customer, project, value, line items, proposed termins; store `quotation_id`; when contract value differs from quotation total by > 0 require `value_change_reason`.
Acceptance: Feature test; the contract detail shows "Dari penawaran QTN/…".

**T3.7 Dunning letters as house forms** (1–2 d) — A2
Files: `Modules/Core/Support/PrintableDocuments.php` (register 3 forms), templates under the existing house-form path, `fin_ar_invoices.dunning_level` (migration), action "Cetak surat penagihan ke-N" that increments the level.
Acceptance: three printable letters; `deadline-watch` "Invoice pelanggan" body mentions the current dunning level.

**T3.8 PO without PR needs a reason** (½ d) — E3
Files: `PurchaseOrderStoreRequest`, `PoService`, `schema.js` PO form (`pr_bypass_reason` visible when `purchase_requisition_id` empty — use `visibleWhen`).
Acceptance: 422 without reason; audit shows the reason (mirror `qualification_override_reason`).

**T3.9 Approval value thresholds** (1–2 d, after direksi sets the numbers) — E2
Files: `SettingService` registry (`approvals.threshold_single`, `approvals.threshold_director` per prefix), `ApprovalLevels`, `schema.js` (`required_levels` badge).
Acceptance: PO ≤ threshold single level; > director threshold requires `fin.approve` as the last level.

**T3.10 Approval delegation during leave** (2–3 d, after policy) — E1
Files: new `core_approval_delegations` (from_user, to_user, from_date, to_date, prefixes), `NotificationService::approvers`, `ApprovalQueue`, Pengaturan screen.
Acceptance: delegate sees the delegator's queue and can approve; trail records "atas nama".

---

## Phase 4 — Larger UX (P2, gated on research H1/H2)

**T4.1 Role home dashboards** (3 d) — direktur/finance (current), PM (S-curve deviation, open NCR, late PO lines, opname waiting), site manager (big Lapangan button, today's IPP, permits), procurement (PR waiting, RFQ open, late PO lines), warehouse (low stock, GRN waiting, transfers). Read `session.user.roles[0]`; reuse the "Proyek saya" switch.

**T4.2 Full-page forms for resources with `lines`** (3 d) — route `#/e/<resource>/<id>`; header left, lines right/below; sticky Simpan; Enter on last cell = new row; multi-line Ctrl+V pastes columns by header order. Keep the modal for line-less forms.

**T4.3 Batch approve with a value cap** (1 d) — Tugas Saya checkboxes; "Setujui terpilih" only for rows ≤ `approvals.batch_cap` (setting); larger values stay one-by-one.

**T4.4 Project workspace as the entry point** (only if card sort confirms H2) — tabs per process in `views/project.js` opening `renderList` with `project_id` locked.

---

## Verification — targets for the whole backlog

Re-run `bukti-uji/harness-playwright.py` after each phase; production numbers via the app's own screens, sequentially.
"After phase 2" was measured 4 Sep 2026 on a fresh scratch seed (`S10 S1 S11` then `S2 S3 S4 S5 S8`, one merged
`bukti-uji/results-phase-2.json`; method and per-scenario numbers in `PROGRESS-UX-PROSES.md` § Gate Phase 2).
Production was not measured in that pass. The last two rows were added 4 Sep for T2.10 / T2.11.

| Metric | Baseline (2 Sep) | After patch (2 Sep) | After phase 2 (4 Sep) | Target |
|---|---|---|---|---|
| Fields lost on session expiry (13 typed) | 13 | 0 | 0 (13 field + 3 baris dipulihkan, S4) | 0 |
| Approval clicks per document | 4 + search | 3 | 2 (S2: baris → Setujui; Buka → Kembali) | 2 (T2.3) |
| API calls per approval round-trip | 28 | 16 | 14 on a leave-request detail (16 on a subcontract detail — the count is the detail page's, not the approval's) | ≤ 12 (T2.3 + inbox) |
| Dashboard API calls per open | 21 | 11 | 11 direktur · 6 warehouse (S1; `core/inbox` skipped without `.approve`) | ≤ 10 |
| Inbox coverage (types shown / awaiting) | 11/28, 3 of 4 docs | 28/28, 4 of 4 | 28/28, 4 of 4 (S1) | 28/28 |
| Create→submit PO (2 lines) clicks | 13 | 12 | 12 on a fresh profile (T2.4 −1, T2.5's collapsed group +1); 11 once the sidebar preference is saved | ≤ 10 (T2.4, T4.2) |
| English validation strings on 422 | 178/216 requests | 0 | 0 (S10, 3 requests) | 0 |
| Contrast `--muted` on `--bg` | 4.26 | 5.23 | 5.23 (S8; 5.47 on `--surface-2`, 5.29 success badge) | ≥ 4.5 |
| Admin sidebar height (viewports) | 4.9 | 4.9 | 0.7 (606 px of sidebar content; `scrollHeight` reads 1.4 = the grid row's floor, S5) | ≤ 2.0 (T2.5) |
| Documents awaiting approval > 10 days (production) | 2 (33 d, 40 d) | reminders + escalation daily | — (production not measured) | 0 |
| Approved AP bills past due (production) | 1 (69 d) | — | — (production not measured) | 0 (T3.1) |
| Admin permissions on production | 74/86 | — | — (production not measured; `erp:permission-check` ready since T1.1) | 86/86 (T1.1) |
| Demo logins shown an approvals card they cannot act on (of 11) | 8 | 8 | 0 (S5 › cards: admin, direktur, project-manager only) | 0 (T2.11) |
| Smallest font on the PO list (px) | 10 | 10 | 11 (S8) | ≥ 11 (T2.10) |

---

## Not for Claude Code — needs people

- Research plan (ASESMEN-UX §6): 7-day route log, 8 interviews, card sort, 6 timed tasks, SUS. T4.x waits on H1/H2.
- Director decisions: approval value thresholds (T3.9), delegation policy (T3.10), payment prioritisation (why auto-pay was rejected).
- Rotate the `demo` basic-auth password that was pasted in chat.
- Revert PR/2026/III/0002 on production if desired (HASIL-UJI §6.5).

---

## Suggested Claude Code prompts

**Phase 0/1 (one session):**
> Apply `ux-p0-and-process.patch` on a branch `ux/p0-measured`, run `tests/Feature/Core tests/Feature/Procurement tests/Feature/Iam`, then fix `RevenueRecognitionTest::test_the_catch_up_lands_in_the_month_the_reversal_was_posted` so it passes on any calendar date. Then add a permission-drift check to `deploy/sync-erp1.sh` (T1.1) and WAL/busy_timeout to the sqlite connection config (T1.2). Keep the repo's comment style: every non-obvious change carries the measured reason from HASIL-UJI-UX-2026-09.md.

**Phase 2 (one task per session, in order T2.1 → T2.10):**
> Implement T2.N from RECAP-UX-PROSES-2026-09.md. Read the referenced files first, keep `el()`/`schema.js` patterns, Indonesian copy per the five copy rules, and add a Feature test where the server changes. Finish by running the named harness scenario and pasting the metric.

**Phase 3 (T3.1 → T3.5 first — each is a registry entry or a load()):**
> Implement T3.N. For WatchedDeadlines entries, mirror the `po_expected` entry and add the works/refused pair to DeadlineWatchTest. For T3.3, generate the controller list with grep and patch every `show()` in one commit; update each Resource using the PaymentResource shape.
