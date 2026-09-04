/*
 * Which house forms this user may print, and on which screen each button goes.
 *
 * The seven Projects forms were wired the only way seven forms can be wired:
 * a `printForms` array on the schema.js entry, one line per form. Forty
 * documents cannot be wired that way — forty schema.js edits by ten different
 * lanes, all in one 3 500-line file — so the server answers the question
 * instead. GET core/print/forms returns the documents THIS caller may print,
 * each naming the RESOURCES key its button belongs to, and a module lane that
 * adds one entry to Modules\Core\Support\PrintableDocuments gets its button
 * with no front-end change at all.
 *
 * Cached exactly the way lookup.js caches a reference source, and for the same
 * reason: it is small, it does not change inside a session, and every detail
 * screen would otherwise re-ask for it. The one difference is that a failure
 * here is silent — a screen whose print buttons are missing is still a working
 * screen, and a red toast on every navigation because one endpoint is down
 * would be worse than the missing button.
 *
 * PERMISSION IS THE SERVER'S ANSWER, not this file's. The catalogue arrives
 * already filtered, so there is no can() call here to drift out of step with
 * the permission the endpoint actually enforces.
 */

import { api } from './api.js';

let cache = null;
let inflight = null;

/*
 * Which session the catalogue in memory belongs to.
 *
 * invalidatePrintForms() nulls `cache` and `inflight`, but a request that is
 * ALREADY IN THE AIR has neither been cancelled nor forgotten — fetch has no
 * cancel, and its success handler still holds a reference to nothing but the
 * module's own variables. So the answer to the DEPARTED user's request would
 * land afterwards and install itself as the new user's catalogue. This counter
 * is the only thing that can tell those two answers apart.
 */
let generation = 0;

/**
 * The catalogue, fetched once per session.
 *
 * Never rejects: callers are render paths, and a print button is not worth
 * failing a screen over.
 */
export function loadPrintForms() {
  if (cache) return Promise.resolve(cache);
  if (inflight) return inflight;

  const era = generation;

  inflight = api.get('core/print/forms')
    .then((payload) => {
      /*
       * api.get() sudah membuka amplop { data } (api.js request()), jadi yang
       * tiba di sini adalah ARRAY-nya — `payload.data` pada array adalah
       * undefined, dan katalog ini kosong pada setiap sesi sejak berkas ini
       * lahir: tidak satu pun tombol formulir rumah dari katalog pernah
       * tergambar. Diukur 4 Sep 2026: GET core/print/forms menjawab 23 entri
       * untuk procurement, loadPrintForms() memulangkan 0; bilah PO memuat
       * PDF tanpa Pesanan Pembelian, dan kolom "Sesudah" 2 Sep 2026 (S3
       * detail_action_bar) sama. Bentuk lama tetap diterima untuk jawaban
       * yang belum dibuka.
       */
      const answer = Array.isArray(payload) ? payload : ((payload && payload.data) || []);

      /*
       * Berangkat pada sesi lain: jawabannya dibuang, bukan dipasang.
       *
       * Tanpa perbandingan ini, orang kedua yang masuk di tab yang sama —
       * sementara permintaan orang pertama masih terbang — mewarisi katalog
       * orang pertama: tombol yang seharusnya ada tidak muncul, dan tombol
       * yang muncul dijawab 403 (endpoint-nya tetap menyaring izin sendiri,
       * jadi ini tombol yang salah, bukan data yang bocor).
       *
       * `inflight` sengaja TIDAK disentuh di cabang ini: ia sudah milik
       * permintaan generasi baru, dan menulisinya null akan membuat pemanggil
       * berikutnya menembakkan permintaan ketiga.
       */
      if (era !== generation) return [];

      cache = answer;
      inflight = null;
      return cache;
    })
    .catch(() => {
      // Not cached: a later screen retries rather than being stuck with an
      // empty catalogue for the rest of the session because one request lost
      // its connection. Sama seperti cabang sukses, kegagalan sesi lama tidak
      // boleh membuang `inflight` milik sesi yang sedang berjalan.
      if (era === generation) inflight = null;
      return [];
    });

  return inflight;
}

/**
 * The buttons for one resource, in the shape formButtons() already renders —
 * `form` (the slug), `label`, `idField`, `params`.
 *
 * Synchronous, so a screen renders its final state in one tick; every caller
 * awaits loadPrintForms() first, which means the answer is already here.
 */
export function printFormsFor(resource) {
  return (cache || [])
    .filter((entry) => entry.resource === resource)
    .map((entry) => ({
      form: entry.slug,
      label: entry.label,
      idField: entry.idField || 'id',
      params: entry.params || {},
      /* P8 — which forms also answer at …/{id}/xlsx. The list has ONE owner
         (FormXlsxExportService::FORMS di PHP) and arrives as this flag; a slug
         yang ditambahkan di sana menumbuhkan tombolnya tanpa perubahan
         front-end. Ketat `=== true` supaya katalog lama tanpa kunci ini
         berarti "tidak ada tombol", bukan undefined yang truthy-diragukan. */
      xlsx: entry.xlsx === true,
      /* T3.7 — which ROWS of the resource get this button: {field, equals}
         from the registry's onlyWhen, or null for every row. Data, not a
         predicate, so the three surat penagihan (one resource, one level
         each) do not draw two dead menu items on every invoice. */
      onlyWhen: entry.onlyWhen || null,
    }));
}

/**
 * Whether one button belongs on one row: the row must carry the id the form
 * anchors on, and — for a form declared onlyWhen — the field must hold the
 * value. Compared as strings: the catalogue's `equals` arrives as JSON and
 * the row's field may be a number or a string depending on the Resource.
 */
export function printableFor(form, row) {
  if (!row || !row[form.idField || 'id']) return false;
  if (!form.onlyWhen) return true;
  return String(row[form.onlyWhen.field]) === String(form.onlyWhen.equals);
}

/**
 * The endpoint path for one button and one row.
 *
 * A parameter whose field the row does not carry is LEFT OUT rather than sent
 * empty: the server's own default (today, this month, the whole register) is
 * better than a blank, and `?tanggal=` with nothing after it is a 422.
 */
export function printablePath(form, row) {
  const query = Object.entries(form.params || {})
    .filter(([, field]) => row[field] !== null && row[field] !== undefined && row[field] !== '')
    .map(([param, field]) => `${param}=${encodeURIComponent(row[field])}`)
    .join('&');

  return `core/print/forms/${form.form}/${row[form.idField || 'id']}${query ? `?${query}` : ''}`;
}

/**
 * P8 — the same button, second format: the export URL is the print URL with
 * an /xlsx tail BEFORE the query. Dibangun dari printablePath dan bukan
 * dirakit ulang per layar, supaya ?tanggal= milik tombol laporan harian ikut
 * ke ekspornya — XLSX yang menjawab hari yang berbeda dari lembar yang sedang
 * dilihat adalah dua kebenaran untuk satu tombol.
 */
export function xlsxPath(form, row) {
  const base = printablePath(form, row);
  const query = base.indexOf('?');

  return query === -1 ? `${base}/xlsx` : `${base.slice(0, query)}/xlsx${base.slice(query)}`;
}

/**
 * Every button for a screen: the schema.js entries first, then the catalogue's,
 * with anything already declared in schema.js dropped.
 *
 * The overlap is not hypothetical. The seven Projects forms carry query
 * parameters the catalogue cannot know from a row alone (?tanggal= off a daily
 * report, ?minggu= off a progress row), so they stay declared in schema.js —
 * and a document that appeared in both places would draw two identical buttons
 * side by side, which reads as a bug in the screen rather than a duplicate
 * registration.
 */
export function printButtonsFor(def, resource) {
  const declared = def.printForms || [];
  const known = new Set(declared.map((entry) => entry.form));

  /* P8: a schema.js-declared entry borrows the catalogue's xlsx flag for its
     own slug — the catalogue lists every printable slug including the seven
     declared ones, and the flag's owner stays the server even for buttons the
     catalogue does not draw. */
  const xlsxBySlug = new Map((cache || []).map((entry) => [entry.slug, entry.xlsx === true]));

  return [
    ...declared.map((entry) => ({ ...entry, xlsx: xlsxBySlug.get(entry.form) === true })),
    ...printFormsFor(resource).filter((entry) => !known.has(entry.form)),
  ];
}

/**
 * Drop the cache — after a login as somebody else, the answer differs.
 *
 * The generation bump is the half that matters when a request is still in
 * flight: nulling `inflight` hides the promise from the next caller but does
 * nothing to the fetch behind it, so the bump is what stops that answer from
 * re-installing itself. See loadPrintForms().
 */
export function invalidatePrintForms() {
  cache = null;
  inflight = null;
  generation += 1;
}
