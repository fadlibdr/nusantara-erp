# Handoff: Nusantara ERP UI/UX Rearchitecture (Phases C–I)

Target repo: `fadlibdr/nusantara-erp` (branch `main`), SPA in `public/app/` — plain ES modules, no build, no CDN, vendored libs only (`VendorManifestTest`).

## About the design files
Everything in this folder is a **design reference built in HTML** (Design Components). Do **not** copy the HTML/React-ish runtime into the repo. Recreate the look and behavior with the repo's own stack: `ui.js el()` DOM builders, `app.css` tokens/classes, `schema.js` data, `views/*.js`. Fidelity: **hi-fi** — match colors, type, spacing, radii and copy; adapt only where the repo's accessibility/density/print rules dictate.

Files:
- `Nusantara ERP Redesign.dc.html` — interactive prototype, 9 screens (Beranda, Persetujuan, Pekerjaan Saya, Pengadaan, Daftar PO, Form PO, Detail PO, Dasbor, Lapangan), light/dark, responsive ≥390 px.
- `Current UI.dc.html` — faithful recreation of today's SPA (uses the repo's `app.css`), for before/after diffing.
- `UX Audit & Brief.dc.html`, `Before After.dc.html`, `UIUX-REDESIGN.md` — rationale, findings, target architecture, risks.

## Non-negotiables (from the brief; verify each PR)
No React/Vue, no npm runtime, no CDN (vendor the fonts), no RBAC bypass or hard-coded permissions, no change to API contracts/calculations/workflow semantics, keep dark mode, PWA (`sw.js SHELL` + `SHELL_VERSION` bump for every added/changed shell file), print blocks, density profiles, `—`-not-0 honesty, module accents never on status.

---

## Phase C — Foundation (`app.css`, `ui.js`, `enums.js`, vendor fonts)

### C1 Tokens (replace the `:root` / dark / `[data-theme]` / `@media print` blocks — same 5-block pattern as CONVENTIONS §12)
Light: `--bg #f4f5f7 --surface #ffffff --surface-2 #f8f9fb --surface-3 #eef0f3 --border #e2e5ea --border-strong #c6ccd4 --text #13181e --text-2 #3d4651 --muted #626c78 --primary #2b4fc8 --primary-hover #2341a6 --primary-soft #e9eefb --primary-fg #ffffff --attn #8a5a0f --attn-soft #fbf1dc` (new: "needs your action" accent; never for status).
Dark: `--bg #0e1216 --surface #161b21 --surface-2 #1b2128 --surface-3 #222931 --border #283039 --border-strong #3a4450 --text #e8ecf1 --text-2 #b6bfca --muted #8c96a3 --primary #7d9df3 --primary-hover #98b2f6 --primary-soft #1a2540 --primary-fg #0e1216 --attn #e6b95c --attn-soft #2b2410`.
Keep existing `--success/--warning/--danger/--info(-soft)`, `--chart-*`, `--accent-1..8(-soft/-fg)` values unchanged. Add `--radius-lg: 14px; --radius: 10px; --radius-sm: 8px`. Shadows: `--shadow-sm 0 1px 2px rgba(16,24,40,.05),0 1px 3px rgba(16,24,40,.07); --shadow-md 0 6px 20px rgba(16,24,40,.10),0 2px 6px rgba(16,24,40,.06)`.
Re-measure contrast (S8/S21): `--muted` on `--bg` ≥ 4.5 at 11 px; `--attn` on `--attn-soft` ≥ 4.5; add rows to the token comment block as the file does today.

### C2 Fonts (vendored)
`public/app/vendor/instrument-sans@1.0/` and `public/app/vendor/ibm-plex-mono@6.4/` with LICENSE (OFL), woff2 for 400/500/600(/700). `@font-face` in `app.css`. Update `VENDOR.md` table + sha256; `sw.js SHELL` lines. `--font: "Instrument Sans", system-ui, …`; `--mono: "IBM Plex Mono", ui-monospace, …`. Document numbers, Rp amounts, KPI values use `.mono` / `font-variant-numeric: tabular-nums`.

### C3 ui.js primitives (vanilla, reuse `el()`; each gets a unit test in a new `tests/Feature/Core/UiPrimitivesTest` that parses the source like `SidebarNavWiringTest` does — presence + no hex literals)
- `pageHeader({ title, description, actions })` → `.page-head` (24 px/600, desc 13.5 px muted).
- `docHeader({ kind, code, status, party, context })` → `.doc-head` (kind 12.5 px muted · badge · code `.mono` 26 px/600 · party 15 px/500 · context link).
- `statusBadge(status, enumName)` → `.badge` with **icon + word** from `enums.js` `STATUS_UI` (below). Replaces `badge()` call sites gradually; `badge()` stays for non-status.
- `actionPanel({ tone: 'attn'|'success'|'danger', title, text, actions })` → `.action-panel` (1 px border in tone, 14 px radius, soft bg for `attn`; 3 px left rail for success/danger).
- `tabs(items, { active, onChange })` → `.tabs` (38 px, nowrap, `overflow-x:auto; overflow-y:hidden`, 2 px underline, count pill).
- `filterChip({ label, value, onRemove })`, `overflowMenu(items)` (button ⋯ + `.menu-pop`, `role=menu`, Esc/outside-click close, destructive items after a divider in `--danger`), `keyValueTile({ label, value, note, tone })`, `stepper(steps)` (process map, see Phase F), `drawer()` (mobile nav), `errorSummary(errors, { onJump })`.
Remove the 16-path hand `icon()` set: alias every name to `svgIcon()` (sprite already has all needed symbols: home, inbox, clipboard-check, star, chevron-*, search, plus, bell, ellipsis, filter, sliders-horizontal, arrow-*, check, x, circle-check, circle-x, circle-alert, clock, printer, pencil, trash-2, copy, eye, download, paperclip, send, warehouse, camera, map-pin, wifi-off, menu).

### C4 enums.js — one status vocabulary
```js
export const STATUS_UI = {
  draft:     { label: 'Draf', tone: 'neutral', icon: 'pencil',       sentence: 'Belum diajukan — lengkapi lalu tekan Ajukan.' },
  submitted: { label: 'Menunggu persetujuan', tone: 'attn', icon: 'clock', sentence: (c) => c.mine ? `Menunggu persetujuan Anda (tingkat ${c.level} dari ${c.levels}).` : 'Diajukan ke penyetuju; tidak bisa diubah sampai diputus.' },
  rejected:  { label: 'Ditolak — perlu perbaikan', tone: 'danger', icon: 'circle-x', sentence: (c) => `Ditolak oleh ${c.by}: “${c.note}”. Perbaiki lalu ajukan lagi.` },
  approved:  { label: 'Disetujui', tone: 'success', icon: 'circle-check', sentence: (c) => `Disetujui dan terkunci.${c.next ? ` Langkah berikutnya: ${c.next}.` : ''}` },
  posted:    { label: 'Diposting', tone: 'info', icon: 'check', sentence: 'Sudah dibukukan; perubahan hanya lewat pembalikan.' },
  closed:    { label: 'Selesai', tone: 'info', icon: 'check', sentence: 'Selesai.' },
  cancelled: { label: 'Dibatalkan', tone: 'neutral', icon: 'x', sentence: 'Dibatalkan.' },
};
```
`fmt.statusTone()` reads `STATUS_UI[status].tone`. Module-specific enums (ticket, lead) add their own entries; test pins that every `documentStatus` value has an entry.

---

## Phase D — Shell (`app.js buildShell/renderNav`, `crumbs.js`, `search.js`, `sw.js`)
- Grid: sidebar `--sidebar-w: 248px`, collapsed rail `64px` (`body.rail-collapsed`, stored in prefs key `nav.rail` — add to `UserPreferences::keys()` whitelist, 16 B). Tablet 760–1023 px: rail only. ≤760 px: no sidebar; **bottom bar** `.bottom-nav` (62 px, 4 items: Beranda · Persetujuan [count from core/inbox meta.total] · Cari · Menu→drawer). Check z-index vs onboarding sheet (45) and drawer (50).
- Sidebar order: **Lapis 1** fixed rows Beranda(`home`), Persetujuan(`tugas`, perm ANY_APPROVE, count), Pekerjaan Saya(`pekerjaan`, new); **Favorit** (prefs); **Bidang kerja** = `visibleNav()` groups, collapsed by default except active; each group shows first 8 routes + "Semua layar <label> →" to `#/m/<prefix>`. Active group keeps the 3 px accent marker. Group header icon from `MODULES[prefix].icon`.
- Top bar: crumbs (unchanged logic) · real search field (button styled as input, `Ctrl K` kbd) opening the existing palette · **Buat ▾** menu: collect `RESOURCES` entries with `form` where `session.can(createPerm)`; when inside a module, list that module's first; else top 6 by `prefs recent`. Each item navigates to the form route (Phase E3). · bell · account (theme toggle moves inside the account dialog).
- Landing: `#/home` for every width (remove the 760 px branch; update `LauncherWiringTest`, S22 `landing_boundary`, onboarding opening step).
- `search.js`: hit renderer shows `kind — party` title, `.mono` code, sub (project · amount · submitter), status badge; add an **Aksi** group (create actions from the Buat ▾ source + "Setujui yang menunggu").
- `sw.js`: add new files, bump `SHELL_VERSION`; `PwaServiceWorkerTest` listener count unchanged.

## Phase E — Generic views
### E1 `list.js`
- Header via `pageHeader` (+ `def.description`). Status quick-tabs from `statusColumn.enum` when `def.quickFilters` includes `'status'` (default on for approvable resources). Toolbar: search · `Filter` button (count pill) opening a popover with the existing filter controls · active `filterChip`s · "Hapus semua" · count text (`N dari M <label> · tersaring` only when filtered) · column settings. `th` sort arrows always visible (muted `arrow-up-down`, active `arrow-down/up`).
- Row click opens detail (keep `tr.clickable`, keyboard Enter). Replace per-row buttons with `overflowMenu`: Buka, Cetak…, Ubah, Duplikat sebagai draf (only if `editableWhen`), divider, Hapus… (danger). Keep `data-label` text on coarse pointers for the ⋯ trigger.
- Mobile: existing `hideOnNarrow` stays; `primaryColumn` gets `cell-main/cell-sub`.
### E2 `detail.js`
- `docHeader` + `statusBadge`; **actionPanel** rendered from `statusStrip` data when the caller can act (`decisions.length`): title = sentence, text = submitter/levels/age/server checks, actions = existing decision buttons (`runAction` unchanged). Reject opens `modal` with required note (≥ 8 chars) → `runAction(reject, { note })`. After the action, re-render; `approved` panel shows `next` from new `def.detail.next` (e.g. PO → `'Penerimaan Barang (GRN)'` with route `r/inventory/goods-receipts`).
- Key values row from `def.detail.headline` (fallback: `detail.summary` first 5) via `keyValueTile` with `.mono`.
- Tabs only when >1 of: lines tables, approvals, attachments, activities. Information card uses labels from `def.form.sections[].fields[].label`; unlabeled keys go into a collapsed `<details>` "Bidang lainnya (n)". Remove `titleize()` as a label source.
### E3 `form.js`
- Add route `f/<resource>/new` and `f/<resource>/<id>` (`router.js`, `app.js`). Decide layout: `def.form.layout ?? (lines || fields.length > 8 ? 'page' : 'modal')`. Page layout: sticky section nav (≥1024 px) with numbered sections and error dots; sections from `form.sections` + "Item" + "Lanjutan" (`section.advanced: true` → collapsed `<details>`); inputs 38 px, lines inputs 34 px; mobile renders each line as a card.
- `errorSummary` at top after 422: one item per error with link that focuses the field; keep per-field `.err`. Sticky footer: Batal · Simpan draf · **Simpan & ajukan** (only when the resource has a `submit` action and `session.can(perm)`; it calls create then `runAction(submit)` — same server gates, `confirmResubmit` flow unchanged). Live totals (DPP/PPN/Total) read from the same `lines.total` fn. `liveNote` output styled as the warning line under the field.
- Drafts: reuse `drafts.js` for autosave (shows "Draf tersimpan otomatis di perangkat ini").

## Phase F — Home, Pekerjaan Saya, module page, dashboard
- `home.js` → workspace: `Perlu tindakan Anda` cards (core/inbox top 2 + WatchedDeadlines overdue, oldest first, rail color attn/danger, CTA) · Aksi cepat (Buat ▾ source, max 6) · Angka (ModuleCounts the caller holds, max 4, `—` honest) · Terakhir dibuka (prefs) · Bidang kerja tiles (visibleNav + `MODULES[].kpi`). Dasbor link in header.
- New `views/pekerjaan.js` (`#/pekerjaan`): tabs Semua · Perlu diperbaiki (status rejected, created_by me) · Draf saya · Menunggu orang lain (submitted by me, approved awaiting follow-up doc). Source: existing list endpoints with `created_by=me` filter where available (add server filter only if missing — then it's a backend PR with tests); rows show status badge + "what happens next" sentence from `STATUS_UI` + one CTA. Empty tab → `emptyState({ kind: 'done' })`.
- `module.js` → process page: `MODULES[prefix].flow = [{ label, route, count: '<ModuleCounts key>' }]` (data only, Pengadaan: PR → RFQ → Evaluasi → BA Negosiasi → Keputusan Pemenang → PO → GRN; note rules text under it); `stepper()` renders counts from `GET core/modules?include=…` or `—`. Then "Perlu perhatian" (WatchedDeadlines + inbox for this module), recent docs for this module (prefs recent filtered), and the existing card grid under "Semua layar".
- `dashboard.js`/`widgets/*`: card shell = 8 px accent square + title + CTA link; `kit.js` adds `footSentence()` so each widget ends with one actionable sentence; `failedBody()` text "Tidak dapat dimuat — angkanya tidak diketahui, jangan dibaca sebagai nol" + Coba lagi. `DashboardTileFailureTest` rules unchanged.

## Phase G — Specialized screens
Migrate one at a time to the primitives (pageHeader, tabs, statusBadge, actionPanel, overflowMenu): `lapangan.js` (camera button 56 px, photo rows with distance badge icon `map-pin`), `project.js`, `tugas.js` (becomes Persetujuan: list + sticky context panel ≥1024 px; bulk mode banner), `board.js`, `bankrecon.js`, `kaskecil.js`, `laporanbebas.js`, reports. No workflow changes.

## Phase H — A11y & responsive pass
390/768/1024/1440 × light/dark × density ×3; keyboard: palette, menus (`role=menu`, arrow keys, Esc), tabs, dialogs (focus trap, return focus), tables (`th scope`, sr-only action header must be inside a `position:relative` cell); `prefers-reduced-motion` kills `fadeUp`; touch targets ≥ 44 px on coarse pointer; status never color-only (icon + word); print: hide `.bottom-nav`, `.action-panel` buttons, chips popover, tabs — show all tab panels stacked.

## Phase I — Regression
`php artisan test` green (update DOM-pinning tests: `SidebarNavWiringTest`, `LauncherWiringTest`, `PwaServiceWorkerTest`, `VendorManifestTest`, `ChartMigrationTest` untouched); harness `docs/bukti-uji/harness-playwright.py` S8 (console errors 0, smallest font ≥ 11 px), S20, S21 (accents, density, empty kinds), S22 landing; run the seven journeys of brief §22 with seeded users (`procurement@`, `warehouse@`, `finance@`, `site-manager@` on 390 px, `sales@`, `hr@`, `direktur@`). Record results in `docs/UIUX-REDESIGN.md` §Verification.

## Design tokens quick sheet
Type: UI 13/13.5/14 body, 15 section h2 (600), 24 page h1 (600, -0.02em), 26 mono doc code; 11 px uppercase .08em labels; mono for codes & Rp. Radii 8/10/14. Buttons 36 px (primary bg `--primary`, fg `--primary-fg`, 600), small 32/34, mobile primary 56. Inputs 38 px, lines 34 px, focus `0 0 0 3px var(--primary-soft)`. Badges 11.5 px/600, 999 radius, icon 11–12 px. Cards 1 px `--border`, 14 px radius, `--shadow-sm`. Sidebar 248/64, header 60, bottom bar 62.

## Screenshots (`screenshots/`)
Light, desktop: 01 Beranda · 02 Persetujuan · 03 Pekerjaan Saya · 04 Pengadaan · 05 Daftar PO · 06 Form PO · 07 Detail PO · 08 Dasbor · 09 Lapangan.
Dark, desktop: 01 Beranda · 02 Persetujuan · 03 Daftar PO · 04 Detail PO.
Mobile 390 px, light: 01 Beranda · 02 Persetujuan · 03 Daftar PO · 04 Detail PO · 05 Lapangan.
Captured from the prototype at the preview width; the prototype itself reflows live, so open it for 768/1024/1440.
