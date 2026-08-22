/* Impor dokumen berjenjang — penawaran, BOQ, AHSP dan RAP.
 *
 * The sibling of #/master-data, and deliberately a separate screen rather than
 * one more entry in that list. Master data is FLAT: one row is one record, so
 * "1.240 baris terbaca, 12 ditolak" says everything there is to say about a
 * file. These four are a parent plus its lines — a BOQ is bagian yang berisi
 * item — and every question an estimator has to answer before saving is about
 * that nesting: berapa dokumen, berapa baris di masing-masing, baris mana yang
 * ditolak dan kenapa. One row count answers none of them, so the preview here is
 * a card per DOCUMENT with its own rows nested inside it, and the file-level
 * tally is only the header above them.
 *
 * Two steps for the same reason master data has two: pratinjau dulu, simpan
 * kemudian. Nothing is written until the operator has seen, document by
 * document, what would happen — an import that matches an existing code REPLACES
 * that document's lines wholesale, so a mistyped code is not a duplicate, it is
 * an overwrite.
 *
 * Nothing here knows what a BOQ is. The row types, the columns, which of them
 * are required and which document types exist all come from
 * GET core/document-import, so a fifth document registered in
 * ImportableDocuments appears on this screen without a line changing here.
 */

import { api } from '../api.js';
// `append` rather than appendChild throughout: every messages() call site can
// legitimately produce nothing, and appendChild(null) is a TypeError that would
// blank the whole screen on the commonest case of all — a file with no errors.
import { el, append, clear, button, badge, icon, toast, toastError, errorState, skeletonTable, withBusy } from '../ui.js';
import { downloadPdf } from '../print.js';
import { invalidateByPath } from '../lookup.js';
import * as fmt from '../format.js';

const state = { resource: null, filename: null, content: null, preview: null, outcome: null };

/* Which picker cache a committed import has to drop. A freshly imported BOQ must
   be selectable on a RAP the same minute, and a freshly imported AHSP on the
   next BOQ line. cost-budgets feeds no picker, so it maps to nothing and
   invalidateByPath('') matches no source — a deliberate no-op, not an omission. */
const LOOKUP_PATHS = {
  quotations: 'crm/quotations',
  boqs: 'estimation/boqs',
  ahsp: 'estimation/ahsp',
};

/* Rows shown per document before truncating. A gedung BOQ runs to several
   hundred lines and a file may hold a dozen of them; painting all of it costs
   more than it tells anyone. Refused rows are never the ones dropped — see
   rowsToShow(). */
const ROW_CEILING = 150;

/*
 * One number formatter for every parsed cell. The screen cannot know which
 * column is money and which is a koefisien — the registry does not publish that
 * — and fmt.qty() stops at three decimals, which would print an AHSP koefisien
 * of 0,0004 as "0". A preview that shows a zero where the file says 0,0004 is
 * worse than no preview at all, so six decimals here and no rounding of
 * anything the operator typed.
 */
const decimal = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 6 });

function cellText(value) {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'number') return decimal.format(value);
  if (typeof value === 'boolean') return value ? 'Ya' : 'Tidak';
  return String(value);
}

function readAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('Berkas tidak dapat dibaca.'));
    reader.readAsDataURL(file);
  });
}

/** A CSV download, fetched as a blob so it carries the session token. */
function downloadCsv(path, filename, trigger) {
  return downloadPdf(path, filename, trigger);
}

/** Document codes carry slashes (BOQ/2026/0002), which a filename cannot. */
function slug(value) {
  return String(value || '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

/** A list of messages inside one alert — a five-line refusal has to stay readable. */
function messages(list, tone, iconName) {
  if (!list || !list.length) return null;

  return el(`.alert.${tone}`, [
    icon(iconName, 15),
    el('div', { style: { flex: '1' } }, list.map((text) => el('div', { text }))),
  ]);
}

/*
 * How deep a row sits, read off the payload path the engine assigned it.
 * 'items.0' and 'sections.0' are both level 0; 'sections.0.items.3' is level 1.
 * Deriving the nesting from the path rather than from the row type is what keeps
 * this file ignorant of BOQ's two levels and RAP's one.
 */
function depthOf(path) {
  if (!path) return 0;
  return Math.max(0, Math.floor(String(path).split('.').length / 2) - 1);
}

/*
 * Which value columns this document actually uses, in the file's own column
 * order. A BOQ declares fourteen columns but a given sheet may carry eight, and
 * six empty columns per row is a table nobody reads.
 */
function valueColumnsOf(doc, order) {
  const present = new Set();
  doc.rows.forEach((row) => Object.keys(row.values || {}).forEach((header) => present.add(header)));
  return order.filter((header) => present.has(header));
}

/*
 * A refused row is the entire reason the operator opened this table, so it is
 * never the row that gets truncated away — the ceiling only eats clean rows.
 */
function rowsToShow(rows) {
  if (rows.length <= ROW_CEILING) return rows;

  const flagged = rows.filter((row) => !row.valid || row.warnings.length);
  const clean = rows.filter((row) => row.valid && !row.warnings.length);
  const room = Math.max(0, ROW_CEILING - flagged.length);

  return [...flagged, ...clean.slice(0, room)].sort((a, b) => a.line - b.line);
}

/** "2 judul bagian · 6 baris pekerjaan" — the count at every level, not just the total. */
function levelCounts(doc, typeLabels) {
  const counts = new Map();

  doc.rows.forEach((row) => {
    const key = row.tipe || '?';
    counts.set(key, (counts.get(key) || 0) + 1);
  });

  return [...counts.entries()]
    .map(([tipe, n]) => `${n} ${tipe === '?' ? 'baris tak dikenali' : String(typeLabels.get(tipe) || tipe).toLowerCase()}`)
    .join(' · ');
}

function summaryStrip(summary) {
  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Dokumen terbaca' }),
      el('.value.sm', { text: String(summary.documents) }),
    ]),
    el('.stat', [el('.label', { text: 'Akan dibuat' }), el('.value.sm', { text: String(summary.to_create) })]),
    el('.stat', [el('.label', { text: 'Akan diperbarui' }), el('.value.sm', { text: String(summary.to_update) })]),
    el('.stat', [
      el('.label', { text: 'Dokumen ditolak' }),
      el('.value.sm', { text: String(summary.refused) }),
      summary.refused > 0 ? el('.delta.down', { text: 'tidak akan disimpan' }) : null,
    ]),
    el('.stat', [
      el('.label', { text: 'Baris rincian' }),
      el('.value.sm', { text: String(summary.lines_read) }),
      summary.lines_refused > 0 ? el('.delta.down', { text: `${summary.lines_refused} ditolak` }) : null,
    ]),
  ]);
}

/** The header row's own cells, in the operator's column headings. */
function headerFacts(doc) {
  const entries = Object.entries(doc.header || {});
  if (!entries.length) return null;

  return el('dl.kv', entries.map(([header, value]) => [
    el('dt', { text: header }),
    el('dd', { text: cellText(value) }),
  ]));
}

function rowsTable(doc, typeLabels, columns) {
  const shown = rowsToShow(doc.rows);
  const span = 1 + columns.length;

  const body = el('tbody');

  shown.forEach((row) => {
    body.appendChild(el('tr', [
      el('td.mono', { text: String(row.line) }),
      el('td', [
        el('span.cell-main', {
          text: row.tipe ? String(typeLabels.get(row.tipe) || row.tipe) : 'Tidak dikenali',
          style: { paddingLeft: `${depthOf(row.path) * 18}px` },
        }),
        el('span.cell-sub', {
          text: row.tipe || '—',
          style: { paddingLeft: `${depthOf(row.path) * 18}px` },
        }),
      ]),
      ...columns.map((header) => {
        const value = (row.values || {})[header];
        return el(`td${typeof value === 'number' ? '.right.num' : ''}`, { text: cellText(value) });
      }),
    ]));

    if (!row.errors.length && !row.warnings.length) return;

    // The reasons go in a row of their own rather than a last column: a BOQ
    // table is nine columns wide and scrolls sideways, and the one thing that
    // must never be scrolled out of sight is why a line was refused.
    body.appendChild(el('tr', [
      el('td'),
      el('td', { colspan: String(span) }, [
        ...row.errors.map((text) => el('div', { text, style: { color: 'var(--danger)' } })),
        ...row.warnings.map((text) => el('div.muted', { text })),
      ]),
    ]));
  });

  return el('div', [
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Baris' }),
        el('th', { text: 'Jenis' }),
        ...columns.map((header) => el('th', { text: header })),
      ])),
      body,
    ])),
    shown.length < doc.rows.length
      ? el('.card-body', el('span.cell-sub', {
        text: `Menampilkan ${shown.length} dari ${doc.rows.length} baris — setiap baris yang ditolak selalu ikut ditampilkan.`,
      }))
      : null,
  ]);
}

/*
 * `fileRefused` is not decoration. A document's own `valid` flag says only
 * whether ITS rows parsed; a file-level error refuses every document in the file
 * regardless, because a row nobody could attribute to a document may belong to
 * any of them. Without this flag a perfectly good BOQ sits under a green "Buat
 * baru" badge in a file that will write nothing at all — the screen promising
 * something the engine has already decided against.
 */
function documentCard(doc, typeLabels, order, fileRefused = false) {
  const columns = valueColumnsOf(doc, order);

  const held = doc.valid && fileRefused;
  const tone = !doc.valid || held ? 'red' : (doc.action === 'update' ? 'amber' : 'green');
  const label = !doc.valid
    ? 'Ditolak'
    : (held ? 'Tertahan' : (doc.action === 'update' ? 'Perbarui' : 'Buat baru'));

  const facts = [`baris ${doc.line}`];
  if (doc.rows.length) facts.push(levelCounts(doc, typeLabels));
  if (doc.action === 'update' && doc.target) facts.push(`menimpa ${doc.target}`);

  // A document refused before its header could be read has no cells and no
  // rows; an empty .card-body would still paint 16px of nothing under the title.
  const notes = [
    messages(doc.errors, 'error', 'warn'),
    messages(doc.warnings, 'warn', 'warn'),
    headerFacts(doc),
  ].filter(Boolean);

  return el('.card', [
    el('.card-head', [
      el('div', [
        el('h2', { text: doc.group }),
        el('.sub', { text: facts.join(' · ') }),
      ]),
      el('.spacer'),
      badge(label, tone),
    ]),
    notes.length ? el('.card-body', notes) : null,
    doc.rows.length ? rowsTable(doc, typeLabels, columns) : null,
    // A document refused before a single line was read has no arithmetic to
    // show; "Rp 0 menurut importir" under it would read as a finding.
    doc.rows.length
      ? el('.card-foot', { style: { justifyContent: 'space-between' } }, [
        /* Rp 0 is printed only when the importer really computed zero. A BOQ
           priced from an AHSP analysis has no unit price in the sheet at all —
           it is costed on commit — so a confident "Rp 0" here contradicted the
           warning above it and told the estimator their bill was worthless. */
        el('span.cell-sub', {
          text: doc.totals.unpriced_lines > 0
            ? `Jumlah baris yang berharga di berkas: ${fmt.rupiah(doc.totals.computed_total)} `
              + `— ${doc.totals.unpriced_lines} baris lain dihitung dari analisa AHSP saat disimpan.`
            : `Jumlah seluruh baris menurut importir: ${fmt.rupiah(doc.totals.computed_total)}`,
        }),
        doc.totals.stated_total === null
          ? el('span.cell-sub', { text: 'berkas tanpa kolom jumlah — tidak ada pemeriksaan silang' })
          : el('span.cell-sub', { text: `menurut kolom jumlah di berkas: ${fmt.rupiah(doc.totals.stated_total)}` }),
      ])
      : null,
    /* An update REPLACES lines wholesale. The engine reports what it is about
       to destroy; saying nothing here is how a filtered 1-of-400-line export
       silently deletes the other 399. */
    doc.replaces && doc.replaces.deleted > 0
      ? el('.card-foot', el('span.cell-sub', {
        style: { color: 'var(--danger)' },
        text: `Menimpa dokumen berisi ${doc.replaces.lines} baris senilai `
          + `${fmt.rupiah(doc.replaces.total)}; berkas ini membawa ${doc.replaces.incoming_lines} baris — `
          + `${doc.replaces.deleted} baris akan DIHAPUS.`,
      }))
      : null,
  ]);
}

/** The grammar of the file: which words go in `tipe`, and what each row needs. */
function shapeCard(resource) {
  const rowTypes = el('div', [
    el('.card-head', el('h2', { text: 'Jenis baris — isi kolom tipe' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'tipe' }),
        el('th', { text: 'Baris itu adalah' }),
        el('th', { text: 'Kolom yang wajib ada isinya' }),
      ])),
      el('tbody', [
        ...resource.row_types.map((row) => el('tr', [
          el('td.mono', { text: row.tipe }),
          el('td', { text: row.label }),
          el('td', { text: row.required_columns.length ? row.required_columns.join(', ') : '—' }),
        ])),
        // Universal and engine-enforced, so it is in no registry entry and would
        // otherwise never reach the screen — yet it is the word that turns a
        // "SUB TOTAL" line into a deliberate omission instead of a refusal.
        el('tr', [
          el('td.mono', { text: 'abaikan' }),
          el('td', { text: 'Baris subtotal atau rekapitulasi — dibaca, lalu dilewati' }),
          el('td', { text: '—' }),
        ]),
      ]),
    ])),
  ]);

  const columns = el('div', [
    el('.card-head', el('h2', { text: 'Kolom yang dikenali' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kolom' }),
        el('th', { text: 'Dipakai baris bertipe' }),
        el('th', { text: 'Wajib' }),
      ])),
      el('tbody', resource.columns.map((column) => el('tr', [
        el('td.mono', { text: column.header }),
        el('td.mono', { text: column.row_types.join(', ') }),
        el('td', column.required ? badge('Selalu', 'amber') : el('span.muted', { text: '—' })),
      ]))),
    ])),
  ]);

  return el('.card', [
    el('.card-head', el('h2', { text: 'Bentuk berkas' })),
    el('.card-body', [
      el('p', {
        text: 'Satu berkas boleh memuat beberapa dokumen sekaligus. Kolom tipe menentukan jenis setiap '
          + `baris, dan kolom ${resource.group_column} mengikat baris ke dokumennya — baris rincian yang `
          + `kolom ${resource.group_column}-nya dikosongkan ikut dokumen di atasnya.`,
      }),
      el('p.muted', {
        text: `Kolom tipe dan kolom ${resource.group_column} wajib ada di berkas; kolom lain yang tidak `
          + 'dikenali diabaikan dan disebutkan di pratinjau. Baris judul boleh berada di bawah beberapa '
          + 'baris kop; importir mencarinya sendiri.',
        style: { marginTop: '8px' },
      }),
    ]),
    rowTypes,
    columns,
    el('.card-body', el('p.muted', {
      text: 'Kolom tanpa tanda "Selalu" masih bisa diperlukan tergantung isi barisnya — pratinjaulah '
        + 'yang menyebutkan dengan tepat baris mana yang kurang.',
      style: { margin: 0 },
    })),
  ]);
}

function templateCard(resource) {
  /* .filter-w, not a bare input: every text input in this app is width:100%, so
     inside a flex row it would swallow the whole line and push the export button
     onto the next one. */
  const kode = el('input.filter-w', {
    type: 'text',
    placeholder: 'kode dokumen',
    'aria-label': 'Kode dokumen yang diekspor',
  });

  return el('.card', [
    el('.card-head', [
      el('h2', { text: resource.label }),
      el('.spacer'),
      resource.can_import ? null : badge('Hanya baca', 'blue'),
    ]),
    el('.card-body', [
      el('p', {
        text: 'Template berisi baris contoh dan catatan pengisian. Ekspor menghasilkan berkas dengan '
          + 'bentuk yang sama persis seperti yang diterima importir, jadi cara tercepat mengubah dokumen '
          + 'yang sudah ada adalah: ekspor, ubah di Excel, impor kembali.',
      }),
      el('.row-actions', { style: { marginTop: '12px' } }, [
        button('Unduh template', {
          iconName: 'download',
          onClick: (event) => downloadCsv(
            `core/document-import/${resource.key}/template`,
            `template-${resource.key}.csv`,
            event.currentTarget,
          ),
        }),
        kode,
        button('Ekspor dokumen', {
          iconName: 'download',
          title: 'Kosongkan kotak kode untuk mengekspor semua dokumen.',
          onClick: (event) => {
            const code = kode.value.trim();
            return downloadCsv(
              `core/document-import/${resource.key}/export${code ? `?kode=${encodeURIComponent(code)}` : ''}`,
              code ? `${resource.key}-${slug(code)}.csv` : `${resource.key}.csv`,
              event.currentTarget,
            );
          },
        }),
      ]),
      el('p.muted', {
        text: 'Isi kotak dengan satu kode dokumen untuk mengekspor dokumen itu saja, atau kosongkan '
          + `untuk mengekspor semuanya. Kolom ${resource.group_column} pada hasil ekspor sudah berisi kode `
          + 'dokumennya, sehingga berkas itu diimpor kembali sebagai perubahan di tempat, bukan sebagai '
          + 'dokumen baru.',
        style: { marginTop: '12px' },
      }),
    ]),
  ]);
}

export async function renderDocumentImport(host) {
  clear(host);

  /* A preview is a plan drawn against the database at the moment it was made.
     commit() re-reads the file server-side, so the WRITE is always fresh — but
     the plan on screen would not be, and the plan on screen is what the operator
     approves. Coming back to this screen therefore starts from the file picker. */
  state.filename = null;
  state.content = null;
  state.preview = null;
  state.outcome = null;

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Impor Dokumen' }),
      el('.desc', {
        text: 'Penawaran, BOQ, AHSP dan RAP — dokumen berikut seluruh barisnya, satu berkas Excel/CSV. '
          + 'Berkas diperiksa lebih dulu: tidak ada yang tersimpan sebelum Anda melihat isinya per dokumen.',
      }),
    ]),
  ]));

  const body = el('div');
  host.appendChild(body);

  let resources;
  try {
    resources = await api.get('core/document-import');
  } catch (error) {
    return body.appendChild(errorState(error, () => renderDocumentImport(host)));
  }

  if (!resources.length) {
    return body.appendChild(el('.alert.info', el('div', { style: { flex: '1' } }, [
      'Anda tidak memiliki akses ke jenis dokumen mana pun di sini. Data master yang datar — item, '
      + 'vendor, pelanggan, karyawan — diimpor di ',
      el('a', { href: '#/master-data', text: 'Impor Data Master' }),
      '.',
    ])));
  }

  const chooser = el('.filters');
  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Pilih jenis dokumen' })),
    el('.card-body', chooser),
  ]));

  const panel = el('div');
  body.appendChild(panel);

  const current = resources.find((resource) => resource.key === state.resource) || resources[0];
  state.resource = current.key;

  function paintChooser() {
    clear(chooser);

    resources.forEach((resource) => {
      chooser.appendChild(button(resource.label, {
        variant: resource.key === state.resource ? 'primary' : '',
        onClick: () => {
          if (state.resource === resource.key) return;
          // A BOQ preview left standing under the AHSP heading would be a lie
          // about a file that was never read as AHSP.
          state.resource = resource.key;
          state.filename = null;
          state.content = null;
          state.preview = null;
          state.outcome = null;
          paintChooser();
          select(resource);
        },
      }));
    });
  }

  function select(resource) {
    clear(panel);

    const typeLabels = new Map(resource.row_types.map((row) => [row.tipe, row.label]));

    // Template first, reference second: getting the template is the first thing
    // anyone does, and the two reference tables are what they come back to.
    panel.appendChild(templateCard(resource));
    panel.appendChild(shapeCard(resource));

    if (!resource.can_import) {
      panel.appendChild(el('.alert.info',
        'Anda dapat mengunduh dokumen ini, tetapi tidak mengimpornya. Impor juga memperbarui dokumen '
        + 'yang sudah ada, sehingga memerlukan izin ubah selain izin tambah.'));
      return;
    }

    const picker = el('input', { type: 'file', accept: '.csv,.xlsx,.xls', 'aria-label': 'Berkas dokumen' });
    const result = el('div');

    picker.addEventListener('change', async () => {
      const file = picker.files && picker.files[0];
      if (!file) return;

      state.outcome = null;
      clear(result).appendChild(skeletonTable(5, 4));

      try {
        state.filename = file.name;
        state.content = await readAsBase64(file);
        state.preview = await api.post(`core/document-import/${resource.key}/preview`, {
          filename: state.filename,
          content: state.content,
        });
      } catch (error) {
        clear(result);
        toastError(error);
        return;
      }

      showPreview();
    });

    function showPreview() {
      clear(result);
      const preview = state.preview;

      const fileRefused = preview.errors.length > 0;

      append(result, messages(preview.errors, 'error', 'warn'));

      if (fileRefused) {
        // Not a per-document refusal: a row nobody could attribute to a document
        // may belong to any of them, so the engine writes none of the file. Said
        // BEFORE the cards, because the cards below are now a description of a
        // file that will not be saved.
        result.appendChild(el('.alert.warn',
          'Berkas ini ditolak seluruhnya — kesalahan di atas tidak dapat dikaitkan ke satu dokumen '
          + 'tertentu, jadi tidak ada satu pun dokumen yang akan disimpan, termasuk yang isinya benar. '
          + 'Perbaiki berkas lalu unggah lagi.'));
      }

      append(result, messages(preview.warnings, 'warn', 'warn'));
      result.appendChild(summaryStrip(preview.summary));

      preview.documents.forEach((doc) => {
        result.appendChild(documentCard(doc, typeLabels, preview.columns, fileRefused));
      });

      if (fileRefused) return;

      const willSave = preview.summary.to_create + preview.summary.to_update;

      if (willSave === 0) {
        result.appendChild(el('.alert.warn',
          'Tidak ada dokumen yang dapat disimpan. Perbaiki berkas lalu unggah lagi.'));
        return;
      }

      result.appendChild(el('.row-actions', { style: { marginTop: '16px' } }, [
        button(`Simpan ${willSave} dokumen`, {
          variant: 'primary',
          onClick: (event) => commit(event.currentTarget),
        }),
        preview.summary.refused > 0
          ? el('span.cell-sub', {
            text: `${preview.summary.refused} dokumen yang ditolak dilewati; sisanya tetap disimpan.`,
          })
          : null,
      ]));
    }

    async function commit(trigger) {
      try {
        await withBusy(trigger, async () => {
          const outcome = await api.post(`core/document-import/${resource.key}/import`, {
            filename: state.filename,
            content: state.content,
          });

          // Every reference picker in the app reads from these caches. A BOQ that
          // has just been imported and cannot be picked on a RAP is not an import.
          invalidateByPath(LOOKUP_PATHS[resource.key] || '');

          state.outcome = outcome;
          state.preview = null;
          picker.value = '';

          toast(
            `${outcome.created} dokumen dibuat, ${outcome.updated} diperbarui, ${outcome.skipped} dilewati.`,
            { tone: outcome.skipped > 0 ? 'info' : 'ok' },
          );

          showOutcome();
        });
      } catch (error) {
        toastError(error);
      }
    }

    function showOutcome() {
      clear(result);
      const outcome = state.outcome;
      const codes = Object.entries(outcome.codes || {});

      // A create-import mints a fresh document number for a free-text label. If
      // the operator uploads the same file again, that label matches nothing and
      // a SECOND document is created — this map is the only thing standing
      // between them and two BOQs that disagree, so it is the loudest thing here.
      const minted = codes.filter(([group, code]) => group !== code);

      result.appendChild(el('.stat-row', [
        el('.stat', [el('.label', { text: 'Dibuat' }), el('.value.sm', { text: String(outcome.created) })]),
        el('.stat', [el('.label', { text: 'Diperbarui' }), el('.value.sm', { text: String(outcome.updated) })]),
        el('.stat', [
          el('.label', { text: 'Dilewati' }),
          el('.value.sm', { text: String(outcome.skipped) }),
          outcome.skipped > 0 ? el('.delta.down', { text: 'tidak tersimpan' }) : null,
        ]),
      ]));

      /* commit() reports what the write actually did, and a destructive
         replacement is the one thing the operator must still see AFTER the
         fact — the preview warned, but the outcome screen replaces it. */
      const written = outcome.warnings || [];

      if (written.length) {
        result.appendChild(el('.card', [
          el('.card-head', el('h2', { text: 'Yang perlu diperiksa setelah menyimpan' })),
          el('.card-body', written.map((line) => el('.alert.warn', [
            icon('warn', 15),
            el('div', { text: line }),
          ]))),
        ]));
      }

      if (codes.length) {
        result.appendChild(el('.card', [
          el('.card-head', el('h2', { text: 'Kode dokumen yang tersimpan' })),
          el('.table-wrap', el('table.data', [
            el('thead', el('tr', [
              el('th', { text: `Kolom ${resource.group_column} di berkas` }),
              el('th', { text: 'Kode dokumen' }),
            ])),
            el('tbody', codes.map(([group, code]) => el('tr', [
              el('td', { text: group }),
              el('td.mono', { text: code }),
            ]))),
          ])),
          minted.length
            ? el('.card-body', el('.alert.warn', [
              icon('warn', 15),
              el('div', {
                style: { flex: '1' },
                text: 'Catat kode di atas. Berkas yang sama diunggah sekali lagi akan MEMBUAT dokumen '
                  + `kedua, karena isi kolom ${resource.group_column} belum menunjuk dokumen mana pun. `
                  + 'Ganti isinya dengan kode di sebelah kanan bila berkas ini akan diunggah ulang, atau '
                  + 'ekspor ulang dokumennya — hasil ekspor sudah membawa kodenya.',
              }),
            ]))
            : null,
        ]));
      }

      append(result, messages(outcome.errors, 'error', 'warn'));

      if (outcome.documents.length) {
        result.appendChild(el('.alert.warn',
          `${outcome.documents.length} dokumen tidak tersimpan. Perbaiki lalu unggah ulang bagian itu saja `
          + '— dokumen yang sudah tersimpan tidak perlu diunggah lagi.'));

        // The commit answer carries no `columns` list of its own, so the
        // registry's own column order stands in for it. Same order, same source.
        const order = resource.columns.map((column) => column.header);

        outcome.documents.forEach((doc) => {
          result.appendChild(documentCard(doc, typeLabels, order, outcome.errors.length > 0));
        });
      }
    }

    panel.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Unggah berkas' })),
      el('.card-body', [
        el('p', { text: 'Format .csv, .xlsx atau .xls — maksimal 5 MB dan 5.000 baris. Berkas yang lebih '
          + 'besar dibagi menurut dokumen, bukan dipotong di tengah dokumen.' }),
        picker,
        el('p.muted', {
          text: `Dokumen dicocokkan lewat kolom ${resource.group_column}: bila isinya kode dokumen yang `
            + 'sudah ada, dokumen itu DIPERBARUI dan seluruh barisnya diganti dengan isi berkas — bukan '
            + 'ditambahkan. Isi dengan label bebas untuk membuat dokumen baru. Hanya dokumen berstatus '
            + 'Draf atau Ditolak yang dapat ditimpa; yang sudah Diajukan, Disetujui atau Selesai harus '
            + 'dibuatkan Versi Baru lebih dulu.',
          style: { marginTop: '10px' },
        }),
      ]),
    ]));

    panel.appendChild(result);
  }

  paintChooser();
  select(current);
}
