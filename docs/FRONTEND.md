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
                        --row-h density, --chevron), layout, components, print
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
    views/anggaran.js   Anggaran vs Realisasi (F-2): portfolio, per project × month, overhead
                        (OVB). Every number comes from Finance\Services\BudgetRealisationService
                        — the SAME class the PO/SPK budget gate reads — so the screen and the
                        gate can never answer differently. A cell with no answer is RULED with
                        its reason, never drawn as Rp 0 / 0 %
    views/ambang.js     Ambang & Batas (F-2): the WatchedThresholds registry, sibling of
                        views/tenggat.js — actual vs limit, with "no limit set", "budgeted at
                        zero" and "nothing measured yet" printed as their own sentences
                        (CONVENTIONS §24). A limit of Rp 0 that has already been SPENT is the
                        loudest row there is, not a missing one — measured, it used to vanish
    kalenderpalette.js  the 8 department dot colours (ΔE-CVD validated) shared by the calendar
                        widget and the full calendar screen — owned by neither
    prefs.js            user preferences (favourites, recent, density, launcher.hidden,
                        dashboard.layout) —
                        SERVER is the truth (core/me/preferences), localStorage is a mirror;
                        one-time lift of the P1-B keys, per key; announces
                        erp:favorites-changed / erp:recent-changed / erp:prefs-loaded so the
                        sidebar and the views redraw without importing app.js (CONVENTIONS §15)
    inbox.js            the ONE loader of GET core/inbox (UX-D.2, ERRATA E-4): the sidebar and
                        bottom-bar badge, the dashboard widget, Persetujuan, Beranda and the module
                        page share one in-flight request and one answer keyed by user id; the badge,
                        Beranda and the module page accept a 60 s-old answer, the dashboard widget and
                        Persetujuan ask with `maxAge: 0` (and refresh the cache for the others);
                        null (not an empty list) for a reader without an approve permission;
                        `meta.failed` travels with the answer
    deadlines.js        the view-level loader of GET core/deadlines (UX-F2.2, the inbox.js pattern):
                        Beranda, the module page and Tenggat share one request and one 60 s answer
                        keyed by user id (Tenggat asks with `maxAge: 0`); it THROWS when the server
                        fails, so no caller turns "unknown" into "no deadlines". Dashboard widgets
                        keep `safeList` from kit.js (CONVENTIONS §17)
    create.js           the source of "Buat ▾" in the top bar, of the Aksi group in the Ctrl+K
                        palette and of Beranda's "Aksi cepat" (UX-D.4): the lists' own "Tambah …"
                        buttons, gathered; `canCreateResource()` is the one predicate it shares
                        with list.js
    format.js           id-ID money/date/percent formatting
    ui.js               el() DOM builder, buttons, badges, modal, toast (with an optional single
                        action button), offlineRibbon()/networkDown(), fields, svgIcon(),
                        emptyState({ kind }) — CONVENTIONS §14 — and reportHead(title, meta),
                        the identity block of a financial report (UI-6a): its name and the
                        parameters that produced it, on screen AND on paper. Measured before
                        it existed: the print block hides .tabs and .filters, so all eight
                        #/reports tabs came out of the printer under the same heading
                        ("Laporan Keuangan") with no period on any of them
    illustrations.js    five stroke-only empty-state drawings (inbox/search/filter/error/done),
                        coloured by app.css tokens — no hex literals
    charts.js           SVG charts (line/bar/donut/sparkline/gantt) — since P1-E the ONLY
                        chart code in the app; the header docblock is
                        the API reference; colours only via --chart-* tokens (harness S20)
    cells.js            value renderer shared by tables and detail panels — because EVERY table and
                        EVERY detail panel goes through it, an inline style here is paid once per
                        ROW, not once per screen: the `bool` cell wrote `color: var(--success)`
                        inline 14 times across two list screens before UI-6c made it `.cell-yes`
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
      list.js           generic list: a toolbar built once per screen (search that keeps
                        focus across reloads, Filter popover holding the screen's filter
                        controls, active-filter chips with Hapus semua, CSV), saved views,
                        table, pagination
      form.js           generic create/edit form — dialog or page (#/f/, sticky .form-foot), repeatable line items, Simpan & ajukan on the page (route-passed `submit`)
      detail.js         generic document detail: head, status panel, Informasi (label: form → LABELS → "Bidang lainnya"), lines, approvals
      actions.js        lifecycle actions (submit/approve/post/…); withConfirmResubmit() is the one confirm-resubmit engine (runAction and the page form's Simpan & ajukan), toastActionDone() the one success sentence
      dashboard.js      cross-module dashboard + approval inbox
      home.js           Beranda #/home (UX-F2.2): the landing at EVERY width (ERRATA D-2) and a
                        workspace, in this order — head "Beranda" with a "Dasbor" button; the search
                        box (same screen index as Ctrl+K); "Perlu tindakan Anda" (the two documents
                        that have waited longest for the reader's decision, via js/inbox.js and for
                        approvers only, plus up to three overdue deadlines, via js/deadlines.js; all
                        cards oldest first, one button each — "Tinjau & putuskan" to the document,
                        "Tindak lanjuti" to that deadline's list; the two sources settle and fail
                        separately, a failed one is ONE sentence and never the `done` picture, and
                        the other's cards still draw); "Aksi cepat" (create.js's own source, at most
                        six, absent for a role that may create nothing); "Angka" (at most four
                        headline numbers the reader holds, NAV order, `ringkasan` skipped, each
                        linking to its module page — they repeat numbers the tiles carry); Favorit and
                        "Terakhir dibuka" from server prefs; and "Bidang kerja" (titled "Modul" before
                        UX-F2.2), one tile per module the caller may open (visibleNav()) with its
                        ModuleCounts headline and "n layar". The quick actions, Favorit and
                        "Terakhir dibuka" redraw on erp:prefs-loaded. "Beranda" is the first NAV row
                        (marked `chrome: true`, so it is not counted as one of Ringkasan's screens)
                        and a house button (`.home-btn`, title "Beranda") in the header that goes to
                        #/home, at every width (app.js).
                        Unknown count = '—' AND the number's name from MODULES.kpi ("— Job gagal"),
                        never 0 (LauncherWiringTest). The page adds ONE request to the landing
                        (core/deadlines, HomeWiringTest) and asks nothing of core/my-work
                        (PekerjaanViewWiringTest allows no other caller): a non-approver's own
                        rejected documents are Pekerjaan Saya's, not a card here
      pekerjaan.js      Pekerjaan Saya #/pekerjaan (UX-F2.1): the reader's OWN submitted documents from
                        one `core/my-work` request, two tabs "Perlu diperbaiki" and
                        "Menunggu orang lain" (ERRATA D-3) — see Conventions, "Pekerjaan Saya"
      tugas.js          Persetujuan #/tugas: the full approval queue — one `core/inbox` request through
                        js/inbox.js, a filter per document type, the delegation banner and bulk
                        approval (F-1, see Conventions). Not Pekerjaan Saya: this queue is what waits
                        for the READER'S decision, Pekerjaan Saya is the reader's OWN documents
      module.js         module PROCESS page #/m/<prefix> (UX-F2.3; the breadcrumb's module target and
                        the Beranda tile's target): the accent head (eyebrow "Modul"); "Alur <Modul>"
                        for a prefix that has an entry in FLOWS (schema.js, a data map kept OUTSIDE the
                        MODULES lines — today only Pengadaan) drawn by stepper(), with a note that the
                        sequence is "lazim, bukan wajib" and names three rules the server enforces
                        (ERRATA E-8: a stage shows a number only from the module's OWN registered
                        ModuleCounts key — today the PO stage, "PO terbuka"; "—" when that answer
                        failed; no number on a stage without a key; a stage links only to a screen the
                        reader may open, by the same visibleNav() filter as the sidebar); the KPI tiles
                        (ModuleCounts headline + up to 3 secondaries that are already in the same
                        response — prj/fin read dashboard/summary?include=modules, so it stays ONE
                        request); "Perlu perhatian" (for approvers
                        one row "N dokumen <Modul> menunggu keputusan Anda" — "N+" when the inbox is
                        incomplete — plus one row per overdue
                        or approaching deadline of this module, all read through js/inbox.js and
                        js/deadlines.js so the page asks for nothing of its own; one sentence per failed
                        source, never the `done` picture); "Terakhir dibuka" for this module; then the
                        card grid of the NAV screens the caller may open under "Semua layar <Modul>",
                        with a favourite star BESIDE each card (never inside the <a>; styled since
                        UX-F2.3). Grid is auto-fill minmax(220px, 1fr): measured 4 columns at 1440 px,
                        1 at 390 (S21 admin_modules[*].columns) — not "3 columns". "Terakhir dibuka"
                        and the stars redraw on erp:prefs-loaded. ModuleFlowTest pins the strip
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

## Prototipe rancangan (UI-0)

`docs/prototipe-ui/nusantara-erp-2026.html` adalah **mockup statis** dari tampilan yang
diusulkan kampanye modernisasi UI — 20 layar, dua tema, tiga kepadatan, empat lebar, tanpa
server dan tanpa API. Ia bukan bagian dari aplikasi dan **tidak** ada di `SHELL` `sw.js`
(letaknya di `docs/`, bukan `public/app/`).

Gunanya dua: menjadi rujukan rancangan yang disepakati sebelum paket UI berikutnya menyentuh
`public/app/`, dan menjadi tempat mencoba pola baru tanpa risiko. `README.md` di folder yang
sama menjelaskan cara membacanya; `docs/LAPORAN-PAKET-UI-0.md` mencatat apa yang ditutup, apa
yang sengaja tidak dikerjakan, dan enam jebakan CSS yang ditemukan sambil membangunnya.

Satu aturan yang lahir dari prototipe itu dan berlaku di seluruh SPA: **`var()` yang tidak
terdefinisi tidak menghasilkan galat** — ia jatuh ke nilai AWAL properti, dan `fill` yang jatuh
ke hitam menggambar balok pekat tanpa satu pun pesan. Itulah sebabnya `charts.js` memegang
daftar putih token (§11), dan sebabnya menambah token baru berarti menambahnya di SEMUA blok
tema yang memakainya.

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
  form: { sections: [ { title: '…', fields: [ … ] }, { title: 'Lanjutan', advanced: true, fields: [ … ] } ], lines: [ { …, total, discountField } ] },  // advanced → folded <details> (UX-E3.5); discountField → live DPP under the lines, PPN/Total stay the server's (UX-E3.6)
  detail: { summary: ['dpp', 'total'], tables: [ … ] },
  actions: [ … ],                // lifecycle buttons
}
```

Column and field `type`s are listed at the top of `schema.js`. A resource with
`customDetail: 'project'` renders a hand-written view from `CUSTOM_DETAILS` in `app.js`
instead of the generic detail screen.

### A live note under a field (`liveNote`, F-2)

A field spec may carry `liveNote: '<key>'`. The note carries `.tone-warn` / `.tone-neg` (a class,
never an inline colour — UX-E3.4; S29 reads the class). `views/form.js` keeps the providers in one
registry (`LIVE_NOTES`) and renders a `.help` line under that field, refreshed whenever the
form changes:

```js
{ key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', liveNote: 'projectBudgetPo' }
```

Four rules, all learned the hard way:

* the provider lives in `form.js`, NOT in `schema.js` — `schema.js` is data and imports
  nothing, and giving it `import { api }` would tie the SPA's largest file to its transport;
* the refresh is DELEGATED on the form body (`body.addEventListener('change', …)`), the same
  way `visibleWhen` is: a combobox emits its `change` from the `<input>` inside it, and a
  listener bound to the lookup wrapper does not see it on every path (measured, harness S29);
* failure is SILENT. A 403, a module that does not answer, a dropped network — none of them
  may block the document being created. The warning disappears; the server-side gate still
  stands behind it with its own refusal sentence;
* the note must print the number that judges THIS document, not a related one (F-2
  verification). One provider served both the PO and the SPK form with the project's TOTAL
  remaining, while the gate measures a PO against the non-subcon side and an SPK against the
  subcon side: measured on the demo data, the PO form promised "sisa Rp 1.697.500.000" over a
  non-subcon side of −Rp 105.039.400, and a PO of Rp 1 was refused 422.

Shipped users: the Proyek field of the PO form (`projectBudgetPo` — the non-subcon ceiling)
and of the SPK form (`projectBudgetSpk` — the subcon ceiling), each with its own sentence,
its own ≥ 90 % colour, and the same words the gate uses when it refuses that document.

### A card on the document screen (`activitiesCard`, F-3)

Some cards belong to a whole FAMILY of screens rather than one resource — attachments,
external approvals, and since F-3 the CRM activity card. They are wired the same way, in one
line inside `renderDetail`, and the card itself decides membership from a registry mirror:

```js
const activities = activitiesCard(key, record.id, def.module);   // views/detail.js
if (activities) main.appendChild(activities);
```

```js
export const ACTIVITY_DOCUMENTS = {                              // views/activities.js
  'crm/leads': 'lead', 'crm/quotations': 'quotation', 'crm/customers': 'customer',
};
```

The PHP side of the mirror is `Modules\Crm\Support\ActivityDocuments`, and
`tests/Feature/Crm/ActivityRegistryTest.php` reads both and fails when they diverge — a slug
only in the SPA renders a card whose every request 422s; a slug only in PHP is a document that
quietly cannot record a single activity. Same shape as `AttachmentRegistryTest`.

Three rules the card itself lives by (all in CONVENTIONS §25): an empty card SAYS it is empty
("Belum ada aktivitas dicatat untuk dokumen ini", never "0 aktivitas"); "overdue" is the
server's `is_overdue`, never a browser-clock comparison; and a card that draws only the first
N rows SAYS SO and takes its totals from `meta` — which is why it calls `api.list`, not
`api.get`. `api.get` throws the envelope away, so a card using it counts whatever happened to
load: measured 8 Sep 2026, "100 terbuka." on a document holding 110, next to a board card that
said 110 (verifikasi F-3).

### Informasi labels (`fieldLabel`, UX-E2.2, ERRATA E-7)

The Informasi card names a field in this order: the label of the resource's own FORM field
(`def.form.sections[].fields[].label` — the words the person saw when typing it), then the
`LABELS` dictionary in `views/detail.js` (computed fields no form declares: `dpp`,
`ppn_amount`, `required_levels` …), then nothing. A key with no label in either is not drawn
as an auto-English title between the labelled rows; `renderDetail` folds every such key into a
closed `details.kv-more` — "Bidang lainnya (n)" — as the LAST child of the same card, and only
there does `titleize()` give it a text, together with its console warning. README E2 asked for
`titleize()` to go as a label source and ERRATA E-7 asked to keep `LABELS` and the warning;
both hold when the auto title can only appear inside the fold. A key named by a `detail.groups`
entry stays in its group even unlabelled: the schema placed it. The fold lives inside the card
so `.card:has(h2 'Informasi') dt` counters and `dl.kv dt` readers keep finding it, and it never
prints "(0)". `DetailViewWiringTest` pins the order, the fold's position and, statically, that
no key `PurchaseOrderResource` emits can reach the fold.

Below the status panel the document shows its key values as `.kv-tiles` (`keyValueTile`, UX-E2.3):
`def.detail.headline`, or, when a resource declares none, the WHOLE `detail.summary` — never a
first-five cut, because the invoice guide promises Total · Sudah dibayar · Sisa. Each value is
formatted by the same key regexes `autoValue` uses (percent before money, so `ppn_rate` reads
"11%" and not "Rp 11"), and an empty value prints "—". The keys drawn as tiles are exactly the
keys the Informasi card leaves out. PO is the pilot of `headline`, identical to its summary.
Routes: `#/f/<resource>` and `#/f/<resource>/new` both open the create page, `#/f/<resource>/<id>`
the edit page (the route fetches the record once and passes `fetched: true` to `openForm`);
`formIsPaged()` = `form.layout` if declared, else lines OR more than eight fields (README E3; UX-E3.8 moved the 20 long forms to the page — the static census pin in FormViewWiringTest lists them). On the
page, "Tinggalkan" keeps the browser draft (`onClose({ keepDraft: true })`); the dialog's
"Buang isian" still discards; `offerDrafts` reopens through `startForm`, so line resources come
back as their page. The page focuses its first real input like the modal.

`def.detail.next` `{ label, route }` (UX-E2.5) adds a display-only link "Buka <label>" to the LOCKED
(success) panel's `actions` slot, and only for readers whose sidebar lists that route — `visibleNav()`
with `session.can`, the same filter the sidebar and Ctrl+K use; it never claims the follow-up document
exists or not. PO → Penerimaan Barang (GRN) is the only user; the link is hidden on paper as a control.

### Kanban hooks (`board.api`, `board.card.fields`, `action.body`, `action.boardOnly`, F-3)

`views/board.js` (P1-G) gained three hooks so the pipeline board could stand on it unchanged.
They are generic; any board may use them. See CONVENTIONS §26 for the table of what each one
exists to prevent:

```js
board: {
  enum: 'leadStatus',
  lanes: ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'],
  moves: { new: 'to-new', …, won: 'to-won', lost: 'to-lost' },
  api: 'crm/pipeline/board',                     // read source; moves still POST to def.api
  card: { fields: ['owner_user_name', 'activity_note'] },
}
```

```js
{ key: 'to-won', label: 'Pindahkan ke Menang', path: '{id}/pipeline', method: 'POST',
  perm: 'crm.update', boardOnly: true, body: { status: 'won' }, confirmResubmit: ALASAN_MUNDUR }
```

`body` is the action's fixed payload (`runAction` merges dialog answers over it), and
`boardOnly` keeps an action out of `actionButtons()`. The won/lost lanes are mapped to actions
the server ALWAYS refuses — on purpose: the card comes back carrying the server's own sentence
("… lalu tekan "Tandai Menang" di penawaran itu"), instead of the board's generic one.

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
| `noDetail: true` list           | `list.js` row ⋯ menu — there is no detail screen to carry it |
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
- **The shell (UX-D)**: the landing is `#/home` at every width (ERRATA D-2). NAV rows with `pinned: true`
  (Beranda, Persetujuan, Pekerjaan Saya — in that order) are drawn as fixed rows above Favorit, and the
  first two that pass `visibleNav()` are the first slots of the ≤ 760 px bottom bar: Beranda, then
  Persetujuan for approvers or Pekerjaan Saya for everyone else (ERRATA E-4, no shell code — the order in
  NAV decides). The rows stay owned by their NAV group and filtered by `visibleNav()`, so the shell writes
  no permission. `views/pekerjaan.js` (`#/pekerjaan`, no `perm`) makes ONE `core/my-work` request, shows
  ERRATA D-3's two tabs, and takes its sentence from `statusStrip()` in `views/detail.js`. `icon` names their glyph; `count: 'inbox'` asks for
  the approval badge. Groups with more than 10 routes show 8 plus "Semua layar <modul> →"; the rest stay
  in the DOM (`.beyond-cap`) so the filter, the module-home comparison and the active route still reach
  them. **`js/inbox.js` is the only `core/inbox` caller**: badge, dashboard widget, the Persetujuan screen
  and the next-document offer share one request, the cache is keyed by user id, nothing is asked without
  ANY_APPROVE, and `meta.failed` renders as "N+" with tugas.js's sentence, never a bare number.
  **`js/create.js`** gathers the lists' own "Tambah …" buttons for Buat ▾ and the palette's Aksi group:
  `canCreateResource()` is the one predicate `list.js` and the shell share. The rail (64 px) is per device
  (`localStorage nusantara_erp_nav_rail`), always on at 761–1023 px; in it a module icon opens
  `#/m/<prefix>` and the full menu opens over the page through the same `ui.drawer()` the phone uses.
  `ShellWiringTest` pins all of the above.
- **One icon system (UI-3c)**: `icon(name, size)` now delegates to `svgIcon(name, {size})`, so every icon in
  the SPA comes from the vendored Lucide sprite. The two functions' signatures do **not** match — one takes a
  number, the other an object — so a mechanical `icon(` → `svgIcon(` rename is valid JavaScript that silently
  resizes 67 icons to 16px; delegating inside `icon()` avoids touching any of the 79 call sites, and
  `LUCIDE_ALIAS` already maps the legacy names. Two of those call sites are funnels — `button({iconName})` and
  `menuButton` — carrying 212 more icons between them, so flipping one line moves 291 icons at once.
  `LauncherWiringTest` now checks that **every** name reaching an icon resolves to a real symbol in the sprite:
  a `<use href="…#lucide-nope">` draws nothing and raises nothing, which is how `bell`, `camera`, `copy` and
  `list` shipped invisible on six buttons for months. **CSS must never reach inside an icon.** A `<use>` has no
  `<path>` in the DOM, and `fill` does not cross that boundary — each sprite symbol carries its own
  `fill="none"` — so a state like the favourite star's must live on the icon's own box (`.star.on` uses a
  `--primary-soft` chip), never on its internals.
- **The page head (UI-3c)**: `.page-head` is built 69 times across 54 files and there is **no shared builder** —
  unifying them belongs to UI-4/UI-6, not to a shell package. What is fixed is the standard itself, pinned by
  `SpaPageHeadStandardTest`: the title and description take `--fs-page` and `--fs-body` from the UI-1 scale
  rather than raw px (`--fs-page` had existed since UI-1 with zero consumers), and the action bar finally has a
  phone behaviour — before UI-3c `.page-head` had no responsive rule anywhere in app.css, and at 390px the
  eight flat buttons on `#/d/projects/1` produced a 256px page head, nearly a third of the screen before any
  content. Crowded bars (three or more buttons, or any zoned bar) become one horizontally scrolling row:
  172px, with every button still reachable. Bars with one or two buttons are left exactly as they were — the
  first version of the rule applied to all of them and made the single-button case *worse*.
  The three-zone bar (`.zone.navigasi` / `.keluaran` / `.keputusan`) still has exactly one producer,
  `views/detail.js`; that count is a **floor** in the test, free to rise as screens adopt it.
- **Shared primitives (UX-C.3)**: `ui.js` exports eleven builders from the UI/UX handoff — `pageHeader`,
  `docHeader`, `statusBadge`, `actionPanel`, `tabs`, `filterChip`, `overflowMenu`, `keyValueTile`, `stepper`,
  `drawer`, `errorSummary` — each with its rules in the `primitif UX-C.3` section at the end of `app.css` and
  pinned by `UiPrimitivesTest` (exported, no hex literal in `ui.js`, every class it writes has a stylesheet
  rule). `docHeader()` has its first caller since UX-E2.4, `views/detail.js`: it renders the document
  number as `h1.doc-code.mono` (the number is the page title; harness and a11y read `.page-head h1`),
  and `.page-head` remains the container because the harness and `SpaPageHeadStandardTest` pin it; the
  code's size follows `.page-head h1` (`--fs-page`), not `.doc-code`'s 26 px (OQ-25).
  `statusBadge(status, enumName, { label })` is **icon + word**, never colour alone: tone from
  `fmt.statusTone()`, glyph from `fmt.statusIcon()` (the enum's own fourth tuple element in `enums.js`, else
  the shared word map). `overflowMenu(items)` is `menuButton` with an ellipsis trigger; items may carry
  `{ divider: true }` and `danger: true` so "Hapus…" stands after a rule in `--danger`. `actionPanel({ tone })`
  is the only place `--attn` appears (`tone: 'attn'` = the reader's turn); `success`/`danger`/`warn`/`info`
  draw a 3 px rail. `tabs(items, { active, onChange })` keeps buttons as direct children of `.tabs` (harness
  clicks `.tabs button:nth-child(n)`), with `count: null` rendered as `—`. `drawer()` is a handle over the
  existing `body.nav-open` drawer; the hamburger in `app.js` goes through it, and so will the bottom bar.
  New screens use these instead of hand-rolling `.page-head`; existing views migrate per package (D, E, G).
- **The command field and the palette (UI-3b)**: the header's search control stays a `<button>`,
  never an `<input>`. Its accessible name comes from the `<span>Cari</span>` inside it — that is what
  the `≤760px` rule hides visually while keeping the name — and an input's name cannot come from its
  contents. It also has nothing to type into: Ctrl+K opens a palette that has its own box, so a second
  one would only duplicate state. Above `1200px` the button *looks* like a field (border, surface-2,
  a `.gs-hint` continuation of the label and a `.gs-kbd` shortcut chip, both `aria-hidden` so the name
  stays exactly "Cari"). That breakpoint is measured, not chosen: header free space is 348px at 1440
  and 188px at 1280, but **zero at 1024, 820 and 768**, where the breadcrumb is already compressed from
  377px to 149px. Below 1200px the button is byte-for-byte what it was.
  The palette is still `modal()` — stacking, focus trap, `inert` and the dirty guard are all already
  right there — with `width: 'palette'` (620px, not the 420px `narrow`). Rows are one line: code left,
  `.search-where` right. The right column is the whole point; the module was always in the data, but
  stacked underneath it read as a caption rather than as the thing that tells five screens named
  "Opname" apart. Arrow keys move a `.active` row **without moving focus out of the box** (the next
  letter typed must not be lost), announced via `aria-activedescendant`; Enter opens the selected row,
  or the first when nothing is selected. Matches are wrapped in `<mark>` by substring — what the eye is
  looking for — even though screens are *matched* on word starts. Harness **S14** gates all of it.
  Until UX-D.7 there was deliberately no actions group: both sources were lookups and nothing could
  produce a command. Since Buat ▾ (UX-D.4) there is one: the **Aksi** group lists "Buat <form>" rows from
  `js/create.js` — the same source as the top bar, so the two doors never disagree — plus "Setujui yang
  menunggu" for ANY_APPROVE holders. Matching is per word (every typed word must start a word of the
  label), so a full document code never matches an action and Enter on it still opens the document.
  Picking an action closes the palette first, then runs it, so a dialog form never stacks on the palette.
  Document rows are typed from data the server already sends (the group's `resource` key → module icon,
  `labelOne` when there is no title); party, amount, submitter and status are not in `core/search` and
  are not invented.
- **Layout primitives and the type scale (UI-2)**: compose spacing with `.stack` (12 px, card
  contents), `.stack.tight` (8), `.stack.loose` (16, page blocks — the same step as `.card + .card`),
  `.row`, `.row.top`, `.cluster` (wrapping) and `.grid-auto`; all four gaps come from the UI-1
  `--space-*` scale, so nothing re-invents a number. `.spacer { flex: 1 }` is now defined
  **unscoped** — before UI-2 there were only nine scoped copies (`.header .spacer`,
  `.card-head .spacer`, …), so a `.spacer` written anywhere else was silently inert; two of them,
  in the EVM and bank-reconciliation toolbars, had been dead since they were written.
  Font-size utilities are deliberately **only four** — `.fs-caption` 11, `.fs-secondary` 12,
  `.fs-body` 13, `.fs-section` 14. `--fs-label`, `--fs-table`, `--fs-page` and `--fs-kpi` are used
  directly in component rules: once there is a class for every size, the scale stops being a scale
  and becomes a vocabulary. **`.fs-*` must stay written AFTER `.mono`** — `.mono` sets
  `font-size: .92em` at the same specificity (0,1,0), so placed before it the utilities lose
  silently on every mono element and `.mono.fs-secondary` quietly renders at `.92em` of its
  inherited size. Harness **S43** pins both the gaps and that ordering, and sweeps every `var(--x)`
  in `app.css` for one that does not resolve — in light, dark **and** print, because the palette
  lives in five copies and a token forgotten in one block is visible only in that block.
- **Surfaces carry an edge, not a shadow (UI-2)**: `.card`, `.module-head`, `.module-card`,
  `.module-kpi`, `.home-tile` and `.stat` sit flat on the page and are separated by `--border`.
  `--shadow-sm` survives for genuinely floating layers only — today a single user, the onboarding
  `.spotlight-label`. Table headers are divided from the body by `--border-strong`, not `--border`.
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
  **On PAPER, table headers may wrap** (UI-7): `table.data th` is `white-space: nowrap`, which is
  right on screen — a wide table scrolls inside `.table-wrap` and ragged headers read badly — and
  wrong on paper, where the print block has just removed that wrapper. Unwrappable headers then
  push the table off the sheet. Measured across all 143 routes under `emulate_media('print')`,
  exactly one overflowed: `#/asset-utilization` at 1474px on a 1440px page, and the width was in
  the HEADERS, not the data ("USULAN BEBAN INTERNAL" 182px to hold "Rp 505.000.000").
  **A chart's rendered SIZE is the screen's decision, not the chart's** (UI-6b): `.chart` forces
  `width: 100%`, so a 720×260 viewBox in a 1112 px card draws at 1112×402. `#/harga-satuan` was
  spending 446,520 px² on two data points of equal value — 223,260 px² per point, nine times looser
  than the project S-curve — next to four 16 px numbers. `.chart-cap` on the card body caps a chart
  at its own natural width; ask for it wherever the data is sparse.
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
- **Panah `<select>`**: warnanya datang dari token `--chevron`, bukan dari literal di dalam
  data-URI-nya. Ia didefinisikan di kelima blok palet (root terang, media gelap, `data-theme`
  terang/gelap, dan blok `@media print`) dan dipakai dua kali — `select` dan `.combo-input`.
  Sebelum token itu ada, warnanya dipanggang sebagai `stroke='%236b7684'` dan disalin
  byte-per-byte ke keduanya: ia tidak pernah berganti di tema gelap, dan nilainya adalah abu-abu
  yang sudah ditarik dari `--muted` pada 2 Sep 2026.
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
- **Landing**: after login with no hash the landing is `#/home` at EVERY width (ERRATA D-2, UX-D.1);
  the earlier rule — `#/dashboard` above 760 px, `#/home` at or below it (owner decision #3) — is
  withdrawn. The Dasbor is one click away: the **Dasbor** button in Beranda's head and the "Dasbor" row of
  Ringkasan. 760 px is still where the sidebar folds into a drawer (`@media (max-width: 760px)` is
  inclusive) and `LauncherWiringTest` still pins it; it no longer chooses the page, and S22
  `landing_boundary` measures 759/760/761 and expects `#/home` at all three. The rule reads
  `location.hash`, not `currentPath()` — the latter invents `dashboard` when the hash is empty, which
  would hijack every deep link; a re-login in the same tab keeps the page the person was reading. The
  onboarding tour follows the same rule: its opening step visits `home` at every width, or step 1 would
  navigate the person off the landing page and collapse the mobile sheet (S19).
- **Empty states**: always `ui.emptyState()` with the right `kind` — a failed source is
  `error`, never `inbox`/`done`; a filtered-out list offers "Hapus filter".
- **Money and dates**: always through `format.js` (`Rp 1.234.567`, `26 Jul 2026`).
- **The KPI tile's value is the ANSWER, not "a number" (UI-7)**: `.stat .value` is 22px and
  `.value.sm` is 16px, and which one a screen uses follows the FORM of what it prints, not
  taste. Measured across 143 routes: the seven top-level rows at 22px carry short-form money
  or counts (`Rp 33,68 M`, `55%`, `1/2`); the six at 16px carry full rupiah a person
  reconciles against paper (`Rp 10.534.455.000`). Unifying to 22px fits at 1440/1280 (0 of 21
  tiles wrap) but wraps 4 of 21 at 1024 and **12 of 21 at 390** — a full rupiah broken across
  two lines inside a one-line tile. The slot also legitimately holds a SENTENCE: `csat.js`
  holds a written rule that zero ratings is a sentence ("Belum ada penilaian"), never `0,0`
  and never a bare `—`. One size would force one form, so both sizes stay. Inside a card the
  16px tile is a different role again (37 tiles against 3) and UI-5 keeps it deliberately;
  `S53` gates the only thing that is always a defect — **two sizes inside ONE row**.
- **Vertical rhythm inside a card is `--space-3`, and nothing adds to the card's padding
  (UI-7)**: `.card-body > p` and `.card-body > dl` now carry `margin: 0 0 var(--space-3)` with the
  last child at `0` (25 of the 26 cases are paragraphs; the one `dl.kv` has the same cause —
  `.kv` sets `display`/`grid-template-columns`/`gap` but never its own margin). Until this package that gap came from the browser's own `1em`, so **26 card bodies
  ended 14px lower than the card's 16px padding** — 30px against a neighbour's 16px — and
  `p.muted` ended at two different distances (0px in 13 cards where someone had patched
  `style: { margin: 0 }`, 14px in 6 where the next person had not). A correction to the UI-6b
  note that said "zero paragraphs still carry a margin": it measured only ONLY-CHILD
  paragraphs, and siblings are the majority.
- **A rule between stacked peer rows is `.divided` (UI-7)**: `.divided > * + *` draws the line
  with `--space-3` on both sides. The same idea had been rewritten each time it was needed,
  with a different spacing each time — `.info-group` 16/12, `.form-section` 20/18,
  `.absensi-side` 10/10, and in `periods.js` **11/11 inline, one style object per list row**.
  11px is in no scale; `app.css` itself still uses it as component padding in eight places
  (`.filters`, `.alert`, `.toast`, …), and those stay — moving them moves row heights that
  S21/S53 pin, which is a density decision, not a sweep.
- **An axis never labels a range the data does not have (UI-7)**: when a series is genuinely
  flat, `lineChart({ yTicks: [value] })` draws ONE gridline at the value. `#/harga-satuan` drew
  two points both worth Rp 62.000 over ticks `Rp 61,23 rb · Rp 61,61 rb · Rp 62 rb ·
  Rp 62,39 rb · Rp 62,78 rb` — a ±1,3% band invented by the padding that keeps a flat line off
  the plot edge, then labelled. The padding stays; the labels go. The non-zero-based axis
  itself remains deliberate and is written for users in `PANDUAN-PENGGUNA` §4.6.
- **Pekerjaan Saya (UX-F1 endpoint, UX-F2.1 screen)**: `GET core/my-work` (ERRATA E-9) is the caller's own
  documents still `submitted` (`bucket: 'waiting'`) or `rejected` (`bucket: 'needs_fix'`, with `decided_at`,
  `decided_by` — "Budi a.n. Sari" when delegated — and `note`): the inbox row shape (including `submitted_by` and `submitted_by_id`) plus `status`, ordered
  needs_fix first (oldest decision), then waiting (oldest submission); `meta.failed` names types that threw.
  Ownership is `ApprovalQueue::ownerOf()`, the inbox's own function. **`views/pekerjaan.js` is its only
  consumer**: one request per opening, ERRATA D-3's two tabs "Perlu diperbaiki" and "Menunggu orang lain"
  (there is no "Draf saya" tab and none for "approved, waiting for a follow-up document": 25 of the 29
  document tables carry none of the creator columns in `ApprovalQueue::OWNER_KEYS` — a draft there has
  no owner, `hr_leave_requests` being owned through `employee_id` — and no API knows whether the
  follow-up exists), and each row's sentence
  is `statusStrip()` of `views/detail.js` — the document page's own sentence, fed a one-line trace built from
  the row — followed by the static per-(enum, value) hint `enumHint()` from `enums.js`. The button of a
  rejected row is **Perbaiki** only when the document page offers Ubah (`canFix()`: edit permission, a form,
  `editableWhen`), otherwise **Lihat** like a waiting row; all of them open the document page, and so does
  the row itself. The waiting sentence names the submitter from the row's `submitted_by` / `submitted_by_id`
  ("Anda" only when the id is the reader's; no name and no date when the server has none). A source that did not answer is never "empty": a failed request leaves the tab counts
  at "—" under the error panel, `meta.failed` writes one sentence and turns the counts into "n+", and an
  empty tab draws `done` only when nothing failed (`error` otherwise; CONVENTIONS §14). `PekerjaanViewWiringTest` and harness S59 pin it.
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
