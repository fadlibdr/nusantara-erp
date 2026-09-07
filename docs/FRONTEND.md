# Front-end

A single-page application in `public/app/`, written as plain ES modules with no build
step, no package manager and no CDN dependencies. It is served as static files by the
same web server that serves the API, and talks to `/api/*` with a Sanctum bearer token.

## Why build-free

The API is the contract; the UI is a client of it. Keeping the front-end to hand-written
ES modules means:

- deployment is `git pull` — no `npm ci`, no build cache, no lockfile drift;
- the production image (php-fpm + nginx) needs no Node toolchain;
- the whole UI is readable in the same repository as the code it drives.

The trade-off is no framework: state is explicit and rendering is imperative DOM
construction. That is workable here because the screens are overwhelmingly the same
shape — a list, a document, a form — and that shape is generated from a schema rather
than written 50 times.

## Layout

```
public/app/
  index.html            shell: boot spinner, toast host, modal overlay; <link rel=manifest> and
                        the media-scoped theme-color pair (--surface, both themes)
  app.css               design tokens (light/dark, chart tokens, --accent-1..8 module accents,
                        --row-h density), layout, components, print
  manifest.webmanifest  PWA manifest — id/start_url/scope all /app/, display standalone;
                        pinned by tests/Feature/Core/PwaManifestTest (CONVENTIONS §21)
  sw.js                 service worker, scope /app/ only: network-first for the shell, and an
                        ALLOWLIST of one prefix — /api/*, /storage/* and attachments have no code
                        path to the cache at all. SHELL is a two-way list: every new screen adds
                        a line, or the app goes half-dead offline without saying so.
                        Pinned by tests/Feature/Core/PwaServiceWorkerTest (CONVENTIONS §21)
  icons/                icon-192 / icon-512 / icon-maskable-512, generated from favicon.svg by
                        docs/bukti-uji/buat-ikon-pwa.py — never hand-drawn, never a placeholder
  vendor/               third-party static files, one folder per <lib>@<ver> + LICENSE;
                        VENDOR.md = manifest (sha256, gzip, how to update) pinned by
                        tests/Feature/Core/VendorManifestTest — no CDN, no npm at runtime
    sortablejs@1.15.7/  drag & drop (dashboard widgets, kanban) — lazy-loaded by its screens
    lucide@1.41.0/      sprite.svg, 79 <symbol id="lucide-…"> for ui.js svgIcon()
  js/
    app.js              login gate, shell, navigation, route registration; PWA wiring (P1-I):
                        service-worker registration AFTER first paint (load + one idle turn),
                        "Pasang aplikasi" in the account dialog, the "Versi baru siap" toast
                        that reloads exactly once, and the offline boot from the cached session
    router.js           hash router (works from static hosting, no server rules)
    crumbs.js           setCrumbs() — the one breadcrumb builder: module crumb → #/m/<prefix>,
                        screen crumb → its list, #crumbs[data-root] = module | screen
    api.js              fetch wrapper, session storage, error normalisation; announces
                        erp:network { ok } from all three transports when a transport FAILS
                        (a 500 is not offline) — the offline ribbon's second source
    vendorload.js       lazy <script> loader for UMD vendor files (SortableJS), one promise per
                        src, rejects on failure so the caller decides how to degrade
    kalenderpalette.js  the 8 department dot colours (ΔE-CVD validated) shared by the calendar
                        widget and the full calendar screen — owned by neither
    prefs.js            user preferences (favourites, recent, density, launcher.hidden,
                        dashboard.layout) —
                        SERVER is the truth (core/me/preferences), localStorage is a mirror;
                        one-time lift of the P1-B keys, per key; announces
                        erp:favorites-changed / erp:recent-changed / erp:prefs-loaded so the
                        sidebar and the views redraw without importing app.js (CONVENTIONS §15)
    format.js           id-ID money/date/percent formatting
    ui.js               el() DOM builder, buttons, badges, modal, toast (with an optional single
                        action button), offlineRibbon()/networkDown(), fields, svgIcon(),
                        emptyState({ kind }) — CONVENTIONS §14
    illustrations.js    five stroke-only empty-state drawings (inbox/search/filter/error/done),
                        coloured by app.css tokens — no hex literals
    charts.js           SVG charts (line/bar/donut/sparkline/gantt) — since P1-E the ONLY
                        chart code in the app; the header docblock is
                        the API reference; colours only via --chart-* tokens (harness S20)
    cells.js            value renderer shared by tables and detail panels
    enums.js            option lists mirrored from the PHP enums
    lookup.js           cached reference data for pickers and id -> name display
    schema.js           THE RESOURCE CATALOGUE — every screen is an entry here; NAV groups carry
                        `prefix`, MODULES maps prefix → { accent, icon, description } (CONVENTIONS §12)
    views/
      dashboard.js      the dashboard COMPOSER (P1-D): reads the person's layout, draws every
                        widget shell first, then loads them four at a time
      dashsetup.js      the "Atur dasbor" drawer: add / remove / resize / reorder, saved once
      widgets/          one file per dashboard widget + registry.js (the catalogue) and
                        kit.js (safe/failure/failedStat — the "a failed fetch is not an empty
                        one" rule, written once) — CONVENTIONS §17
      board.js          kanban board (P1-G) at #/b/<resource> — a SECOND view over an existing
                        list; every drop runs an existing action through runAction()
      laporanbebas.js   Laporan Bebas (P1-F): the report builder over the eight catalogued
                        resources — every choice on screen comes from GET core/reports/resources,
                        including the ceilings, which are announced not memorised
      list.js           generic list: search, filters, table, pagination
      form.js           generic create/edit modal incl. repeatable line items
      detail.js         generic document detail: fields, lines, approvals
      actions.js        lifecycle actions (submit/approve/post/…)
      dashboard.js      cross-module dashboard + approval inbox
      home.js           app launcher #/home: search (same screen index as Ctrl+K), Favorit and
                        Terakhir dibuka rows from server prefs, one tile per module the caller may
                        open (visibleNav()) with its ModuleCounts headline. Landing on <= 760 px
                        (owner decision #3); "Beranda" is the first NAV row (marked `chrome: true`,
                        so it is not counted as one of Ringkasan's screens) and a house button in
                        the header. Unknown count = '—' AND the number's name from MODULES.kpi
                        ("— Job gagal"), never 0 (LauncherWiringTest)
      module.js         module home #/m/<prefix>: accent header + cards of the NAV screens the
                        caller may open (same visibleNav() filter as the sidebar); breadcrumb target.
                        Grid is auto-fill minmax(220px, 1fr): measured 4 columns at 1440 px, 1 at 390
                        (S21 admin_modules[*].columns) — not "3 columns".
                        P1-C: KPI tiles (ModuleCounts headline + up to 3 secondaries that are already
                        in the same response — prj/fin read dashboard/summary?include=modules, so it
                        stays ONE request), "Terakhir dibuka" for this module, and a favourite star
                        BESIDE each card (never inside the <a>)
      project.js        project workspace: two tabs — "Ringkasan" (kurva-S, WBS tree, site
                        activity) and "Jadwal". The tab lives in a MODULE-level variable because
                        `reload` redraws the whole screen from six places, and a tab variable
                        inside the render function would jump back to Ringkasan on every save
      jadwal.js         the "Jadwal" tab (P1-H): read-only gantt over the project WBS, drawn by
                        charts.js ganttChart() over TWO EXISTING endpoints (projects/{id}/wbs-tasks
                        + projects/baselines) — P1-H adds none. The frozen baseline is matched by
                        `wbs_code`, NEVER by `wbs_task_id` (CONVENTIONS §20: 0 of 11 ids survive one
                        "Buat WBS dari BOQ", 11 of 11 codes do). NOT imported by app.js — project.js
                        imports it, and on 7 Sep 2026 that is exactly what made it look like an
                        orphan worth dropping from a merge; one 404 module takes the whole ES-module
                        graph down with it (JadwalGanttTest pins the import). Sejak verifikasi
                        7 Sep 2026 ia juga: membaca AMPLOP endpoint pohon (api.list) untuk
                        `meta.as_of` — garis "Hari ini" datang dari SERVER, tidak pernah dari jam
                        peramban — dan `meta.parent_cycles`; membedakan baseline yang TIDAK ADA
                        dari baseline yang GAGAL dibaca (dengan tombol coba lagi); menggulir
                        gambar ke garis "Hari ini" pada gambar pertama dan mengulang kalimat
                        sumbernya sebagai teks DOM (di ponsel yang di dalam svg di luar jendela);
                        dan MEMOTONG lembar cetaknya sendiri jadi satu svg per 16 baris — sebuah
                        <svg> tidak bisa dipaginasi, dan satu gambar besar mencetak halaman kosong
                        serta halaman gambar tanpa sumbu tanggal
      reports.js        finance reports (TB, P&L, BS, aging, project P&L)
      custom.js         stock, payroll, ticket, subcontract, payment, role, …
```

## Adding a screen

Add an entry to `RESOURCES` in `js/schema.js` and a link in `NAV`:

```js
'crm/customers': {
  module: 'crm',                 // permission prefix: crm.view / crm.create / …
  api: 'crm/customers',          // path under /api
  label: 'Pelanggan',
  labelOne: 'Pelanggan',
  columns: [ { key: 'name', label: 'Nama', type: 'text', sub: 'legal_name' }, … ],
  filters: [ { key: 'status', label: 'Status', enum: 'activeStatus' } ],
  form: { sections: [ { title: '…', fields: [ … ] } ], lines: [ … ] },
  detail: { summary: ['dpp', 'total'], tables: [ … ] },
  actions: [ … ],                // lifecycle buttons
}
```

Column and field `type`s are listed at the top of `schema.js`. A resource with
`customDetail: 'project'` renders a hand-written view from `CUSTOM_DETAILS` in `app.js`
instead of the generic detail screen.

## Adding a "Cetak" button (formulir rumah)

You don't. You add ONE entry to `Modules\Core\Support\PrintableDocuments`, in your own
module's method, and the button appears — no `schema.js` edit, no view edit.

`GET api/core/print/forms` answers with the documents **this caller may print**
(permission-filtered server-side), each naming the `RESOURCES` key it belongs to.
`js/printcatalog.js` fetches that once per session and merges it with any `printForms`
declared on the schema entry; `detail.js` draws the result. That is what makes forty
documents cost forty array entries instead of forty front-end edits.

The four places a button can land, and why there are four:

| Screen kind                     | Where the button comes from                       |
|---------------------------------|---------------------------------------------------|
| generic detail                  | `detail.js` — automatic                            |
| `customDetail: '…'`             | one `houseFormButtons('<key>', $record)` line in the view |
| `noDetail: true` list           | `list.js` row action — there is no detail screen to carry it |
| route-only screen (`absensi`)   | its own button, anchored on a row of what is on screen |

Keep `printForms` on the schema entry only for a form that needs a query parameter the
catalogue cannot know from a row alone — `?tanggal=` off a daily report, `?minggu=` off
a progress row. Both sources render identically; `printButtonsFor()` drops the
duplicate if a slug appears in both.

Printing carries the owning module's `.view` permission and no other: printing is
reading, in another shape. There is no `print` action anywhere in the permission set.

## Conventions

- **Language**: UI strings are Indonesian, code and identifiers English (matching
  `CONVENTIONS.md` §7).
- **Permissions**: navigation groups are gated on `<prefix>.view`; create/edit/delete
  and each lifecycle action are gated on their own permission, so the same build serves
  every role.
- **Navigation chrome**: the breadcrumb is `Modul › Layar › (Dokumen)` — the module crumb
  links to `#/m/<prefix>` and carries the module accent (`data-accent`), the same colour as
  the active sidebar group marker; `setCrumbs(parts, { screenHref })` in `js/crumbs.js` (the
  only builder — a second one writes no `data-root` and no `aria-current`) derives the module
  from the first crumb's NAV group label, and records the chain shape in `#crumbs[data-root]`:
  `module` when the first crumb is a NAV group, `screen` otherwise. Below 760 px that attribute
  decides what may be hidden — a module-rooted chain shows the module crumb alone, a chain
  without one keeps its screen crumb (ellipsised), because the seven RESOURCES outside NAV are
  rooted on the `ERP` placeholder and would otherwise leave the header empty (harness S21
  `crumb_walk` walks every route). Accents never colour semantic states.
- **Kanban boards (P1-G)**: `#/b/<resource>` renders any RESOURCES entry carrying a `board:` block
  as columns of cards. A drop does not write a status — it runs the resource's own existing action
  through `runAction()`, the same path as the document-page buttons, so the inline approval note,
  maker-checker, `confirmResubmit` and the shared toasts all keep working. Refusals come in two
  kinds: permission/`when` (known before the drop, card returns with a sentence naming the document,
  the target column and the missing action) and server-only rules (attempted, refused, card
  returns). SortableJS has no cancel API, so the card is put back by hand from a neighbour captured
  before the drop. CONVENTIONS §19.
- **Laporan Bebas (P1-F)**: `#/laporan-bebas` builds reports over eight catalogued resources. The
  screen holds no knowledge of its own — sources, which columns may be a dimension, which may be
  summed, which filters exist and what the ceilings are all arrive from
  `GET core/reports/resources`. Columns the catalogue REFUSES are still rendered, disabled, with
  their reason underneath, because that is where someone looks for them. Values are never
  re-computed here: cells come from the server and labels go through `enumLabel()`/`labelFor()` —
  the same functions the list screen uses — so preview, CSV and screen cannot disagree. A `null`
  cell prints `—` and never `0`; its tooltip says which of the two reasons applies. CONVENTIONS §18.
- **Matriks persetujuan (F-1)**: the settings screen renders one group as a TABLE instead of a
  column of fields. `GET core/settings` carries `matrix` on that group — one row per approvable
  document type with the setting keys it uses — and `buildMatrix()` pivots the same registry
  entries into 28 rows x (threshold, mode, third level). The cells are ordinary `buildEntry()`
  controls in `compact` mode, so Save, Batalkan, per-field errors and "Kembalikan ke bawaan" work
  exactly as they do everywhere else, with no second save path. Three kinds of row carry a printed
  RULE instead of an input, and never a fabricated `Rp 0`: a type with no amount column ("Tanpa
  nilai rupiah - ambang tidak berlaku"), a type whose threshold belongs to another ("Mengikuti SPK
  subkontraktor"), and the three types whose module owns the gate (mode is stated, not offered).
  39 registry keys, 38 of them cells; the batch cap is the one that renders below the table as a
  normal field. CONVENTIONS §22.
- **Delegasi "a.n." + setujui massal (F-1)**: both live on `#/tugas`, the screen an approver
  already opens. The banner is drawn only when `meta.delegations` is non-empty - a banner that is
  always there stops being read on day two - and it names the giver, the window and the reason
  BEFORE anything is approved. Bulk approve appears only when `meta.batch_cap` is set: the loop is
  here, sequential, over each row's own `approve_url` (from `GET core/inbox`, read off the route
  table server-side, `null` when the module has no such endpoint - that box is disabled with the
  reason in its title). Failures do not stop the rest and every failure names its document code.
  The approval trail prints `Budi a.n. Sari` through `actorName()` in `views/detail.js`, used by
  both the timeline and the status strip. CONVENTIONS §23.
- **Charts (P1-A, P1-E)**: every chart in the app is `js/charts.js`. Screens supply data and the
  properties that are theirs to decide — the EVM axis being allowed above 100 %, the price-trend
  axis not being forced to zero — and nothing else; `ChartMigrationTest` refuses a
  `createElementNS` returning to `project.js` / `evm.js` / `hargasatuan.js`. Legends are drawn
  inside the SVG, so no `.legend` block sits beside a chart except the price trend, whose
  distinction (PO vs GRN) is per POINT rather than per series. CONVENTIONS §11.
- **Dashboard widgets (P1-D)**: `#/dashboard` is composed, not drawn. `views/widgets/registry.js`
  is the catalogue — 19 entries, each `{ id, title, desc, module, perm, route, sizes, size }` —
  and each widget's renderer lives in `views/widgets/<id>.js`, imported **dynamically** only when
  that widget is in the person's layout. Three rules are pinned by
  `tests/Feature/Core/DashboardTileFailureTest`, which scans the folder rather than a hand-kept
  list: a file that exports `build(` **is** a widget (so it must be catalogued, and the catalogue
  must have its file), every widget must branch on `failure(` in code, and no `.catch(() => …)`
  may appear anywhere in the dashboard. The layout is stored in the `dashboard.layout` preference
  (CONVENTIONS §15) and validated server-side against the same catalogue via `SpaWidgets`; a role
  that has never opened the drawer gets the default set written for its role, and
  `DashboardDefaultsTest` proves — against `RoleSeeder::intended()` — that no seeded role lands on
  an empty dashboard.
- **Density**: `data-density` on `<html>` (compact/normal/comfortable) drives `--row-h` and the
  cell paddings; chosen in the account dialog and, since P1-C, stored per user on the SERVER
  (`core/me/preferences`, CONVENTIONS §15). `localStorage` keeps a mirror so the attribute is set
  before the shell paints; `js/prefs.js` lifts the old `nusantara_erp_density:<userId>` key once
  and deletes it.
- **Personal state**: favourites, "Terakhir dibuka" and density go through `js/prefs.js` and
  nowhere else — a second reader of the legacy keys is a second source of truth, and
  `LauncherWiringTest` refuses one. Read with `prefs.get(key, fallback)` (defaults live in the
  SPA, never as a server row), write with `prefs.set(key, value)` (optimistic: mirror first, PUT
  after; a 401 keeps the local value). A view that draws prefs-backed content must redraw that
  part on `erp:prefs-loaded` — on a fresh browser the mirror is empty and the first paint happens
  before the server answers.
- **Module counts**: one headline number per module comes from `GET core/modules` (registry
  `ModuleCounts`, CONVENTIONS §16). A module the response does not mention, or one whose `count`
  is `null`, renders `—`. Never `count ?? 0`.
- **Landing**: after login with no hash, `> 760 px` → `#/dashboard`, `<= 760 px` → `#/home` (owner
  decision #3). Strictly greater, because `@media (max-width: 760px)` is inclusive: at exactly
  760 px the sidebar is already a drawer, and `>= 760` gave that one width both the drawer and the
  dashboard (S22 `landing_boundary` measures 759/760/761). The rule reads `location.hash`, not
  `currentPath()` — the latter invents `dashboard` when the hash is empty, which would hijack every
  deep link. The onboarding tour follows the same rule: its opening step visits `home` on mobile and
  `dashboard` above the breakpoint, or step 1 would navigate the person off the launcher and collapse
  the mobile sheet (S19).
- **Empty states**: always `ui.emptyState()` with the right `kind` — a failed source is
  `error`, never `inbox`/`done`; a filtered-out list offers "Hapus filter".
- **Money and dates**: always through `format.js` (`Rp 1.234.567`, `26 Jul 2026`).
- **Errors**: `api.js` normalises `{ message, errors }` into an `ApiError`; forms map
  `errors` back onto their fields, everything else raises a toast. A view that throws
  renders an error panel rather than a blank page.
- **Editability**: a document's own status decides it — `editableWhen` /
  `deletableWhen` in the schema mirror the server's `isEditable()` rules, so the UI
  hides actions the API would reject.

## Caching

Assets are referenced without a version query. In production, cache-bust by serving
`public/app/` with a revalidating `Cache-Control` (or add a query string to the
`index.html` script/link tags at release time). During development use a hard reload —
a normal reload can leave a stale mix of ES modules in memory.

Since P1-I a second cache sits in front of that one: the service worker's `nusantara-shell-v<n>`
in CacheStorage. It changes nothing about who wins — the strategy is network-first, so a released
file reaches anyone who reloads, and the cache is only ever read when `fetch()` throws. Two things
follow for anyone touching the front-end:

- **adding a file under `public/app/` means adding a line to `SHELL` in `sw.js`** (the test fails
  otherwise, in both directions);
- **a release that changes shell files should bump `SHELL_VERSION`** — that is what installs a new
  worker and shows "Versi baru siap — Muat ulang" in tabs that have been open for days;
- **adding a listener to `sw.js` means adding a test** — `PwaServiceWorkerTest` pins the listener
  list at exactly four, because a second `fetch` listener can cache a per-user `/api` answer without
  a single `respondWith()` and every other check in that file walks straight past it;
- **`index.html` carries an inline boot watchdog** — the one script in the app that depends on no
  other file. If a `<script>` fails or the app has not painted in 10 s it replaces the boot spinner
  with a sentence and a reload button. Anything that changes `#root`'s boot markup must keep
  `.boot-spinner` as the "still booting" signal.

During development the worker makes a hard reload less predictable, not more: use DevTools →
Application → Service Workers → *Update on reload*, or unregister it. CONVENTIONS §21 carries the
never-cache rule itself; DEPLOYMENT §2.3 carries the way to take the worker back out of the fleet
(deleting `sw.js` measurably does not).
