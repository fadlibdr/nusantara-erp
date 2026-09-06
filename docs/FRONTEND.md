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
  index.html            shell: boot spinner, toast host, modal overlay
  app.css               design tokens (light/dark, chart tokens, --accent-1..8 module accents,
                        --row-h density), layout, components, print
  vendor/               third-party static files, one folder per <lib>@<ver> + LICENSE;
                        VENDOR.md = manifest (sha256, gzip, how to update) pinned by
                        tests/Feature/Core/VendorManifestTest — no CDN, no npm at runtime
    sortablejs@1.15.7/  drag & drop (dashboard widgets, kanban) — lazy-loaded by its screens
    lucide@1.41.0/      sprite.svg, 79 <symbol id="lucide-…"> for ui.js svgIcon()
  js/
    app.js              login gate, shell, navigation, route registration
    router.js           hash router (works from static hosting, no server rules)
    crumbs.js           setCrumbs() — the one breadcrumb builder: module crumb → #/m/<prefix>,
                        screen crumb → its list, #crumbs[data-root] = module | screen
    api.js              fetch wrapper, session storage, error normalisation
    format.js           id-ID money/date/percent formatting
    ui.js               el() DOM builder, buttons, badges, modal, toast, fields, svgIcon(),
                        emptyState({ kind }) — CONVENTIONS §14
    illustrations.js    five stroke-only empty-state drawings (inbox/search/filter/error/done),
                        coloured by app.css tokens — no hex literals
    charts.js           SVG charts (line/bar/donut/sparkline/gantt) — the header docblock is
                        the API reference; colours only via --chart-* tokens (harness S20)
    cells.js            value renderer shared by tables and detail panels
    enums.js            option lists mirrored from the PHP enums
    lookup.js           cached reference data for pickers and id -> name display
    schema.js           THE RESOURCE CATALOGUE — every screen is an entry here; NAV groups carry
                        `prefix`, MODULES maps prefix → { accent, icon, description } (CONVENTIONS §12)
    views/
      list.js           generic list: search, filters, table, pagination
      form.js           generic create/edit modal incl. repeatable line items
      detail.js         generic document detail: fields, lines, approvals
      actions.js        lifecycle actions (submit/approve/post/…)
      dashboard.js      cross-module dashboard + approval inbox
      module.js         module home #/m/<prefix>: accent header + cards of the NAV screens the
                        caller may open (same visibleNav() filter as the sidebar); breadcrumb target.
                        Grid is auto-fill minmax(220px, 1fr): measured 4 columns at 1440 px, 1 at 390
                        (S21 admin_modules[*].columns) — not "3 columns"
      project.js        project workspace: kurva-S, WBS tree, site activity
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
- **Density**: `data-density` on `<html>` (compact/normal/comfortable) drives `--row-h` and the
  cell paddings; chosen in the account dialog, stored per user in `localStorage`
  (`nusantara_erp_density:<userId>`) until P1-C moves it server-side.
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
