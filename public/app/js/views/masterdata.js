/* Impor & ekspor data master.

   Two steps on purpose: the file is previewed first and committed second, so
   nobody discovers a bad column after two thousand rows have already landed. */

import { api } from '../api.js';
import { el, clear, button, badge, toast, toastError, errorState, skeletonTable, withBusy } from '../ui.js';
import { downloadPdf } from '../print.js';
import { invalidateByPath } from '../lookup.js';

const state = { resource: null, filename: null, content: null, preview: null };

const LOOKUP_PATHS = {
  items: 'inventory/items',
  vendors: 'procurement/vendors',
  customers: 'crm/customers',
  employees: 'hr/employees',
};

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

function summaryStrip(summary) {
  return el('.stat-row', [
    el('.stat', [el('.label', { text: 'Baris terbaca' }), el('.value.sm', { text: String(summary.total) })]),
    el('.stat', [el('.label', { text: 'Akan dibuat' }), el('.value.sm', { text: String(summary.to_create) })]),
    el('.stat', [el('.label', { text: 'Akan diperbarui' }), el('.value.sm', { text: String(summary.to_update) })]),
    el('.stat', [
      el('.label', { text: 'Ditolak' }),
      el('.value.sm', { text: String(summary.invalid) }),
      summary.invalid > 0 ? el('.delta.down', { text: 'baris ini tidak akan disimpan' }) : null,
    ]),
  ]);
}

function rowsTable(rows) {
  const shown = rows.slice(0, 200);

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Rincian baris' }),
      rows.length > shown.length
        ? el('.cell-sub', { text: `menampilkan ${shown.length} dari ${rows.length} baris` })
        : null,
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Baris' }),
        el('th', { text: 'Kode' }),
        el('th', { text: 'Tindakan' }),
        el('th', { text: 'Catatan' }),
      ])),
      el('tbody', shown.map((row) => el('tr', [
        el('td.mono', { text: String(row.line) }),
        el('td.mono', { text: row.key || '—' }),
        el('td', row.valid
          ? badge(row.action === 'update' ? 'Perbarui' : 'Buat baru', row.action === 'update' ? 'amber' : 'green')
          : badge('Ditolak', 'red')),
        el('td', row.errors.length
          ? el('span', { text: row.errors.join(' · '), style: { color: 'var(--danger)' } })
          : el('span.muted', { text: '—' })),
      ]))),
    ])),
  ]);
}

export async function renderMasterData(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Impor & Ekspor Data Master' }),
      el('.desc', {
        text: 'Unduh template, isi di Excel, unggah. Berkas diperiksa lebih dulu — '
          + 'tidak ada yang tersimpan sebelum Anda melihat hasilnya.',
      }),
    ]),
  ]));

  const body = el('div');
  host.appendChild(body);

  /* Somebody looking for "impor BOQ" lands here first, finds four flat tables,
     and concludes the feature does not exist. It does — on its own screen,
     because a BOQ is a parent plus its lines and this one is one row per record.
     Naming `items` explicitly matters too: it IS imported here, so nobody adds a
     second, conflicting importer for it over there.
     Inside `body`, not `host`: only there is it a sibling of the card below it,
     which is what gives it its bottom margin (.alert + .card). */
  body.appendChild(el('.alert.info', el('div', { style: { flex: '1' } }, [
    'Layar ini untuk data master yang datar — satu baris satu catatan, item/barang termasuk. '
    + 'Dokumen berikut barisnya — penawaran, BOQ, AHSP dan RAP — diimpor di ',
    el('a', { href: '#/impor-dokumen', text: 'Impor Dokumen' }),
    '.',
  ])));

  let resources;
  try {
    resources = await api.get('core/master-data');
  } catch (error) {
    return body.appendChild(errorState(error, () => renderMasterData(host)));
  }

  if (!resources.length) {
    return body.appendChild(el('.alert.info', 'Anda tidak memiliki akses ke data master mana pun.'));
  }

  const panel = el('div');

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Pilih data master' })),
    el('.card-body', el('.filters', resources.map((resource) =>
      button(resource.label, {
        variant: state.resource === resource.key ? 'primary' : '',
        onClick: () => {
          state.resource = resource.key;
          state.filename = null;
          state.content = null;
          state.preview = null;
          select(resource);
        },
      })))),
  ]));

  body.appendChild(panel);

  function select(resource) {
    clear(panel);

    // Re-render the chooser so the active button reflects the choice.
    const chooser = body.querySelector('.filters');
    if (chooser) {
      [...chooser.children].forEach((node, index) => {
        node.classList.toggle('primary', resources[index].key === state.resource);
      });
    }

    const required = resource.columns.filter((column) => column.required).map((column) => column.header);

    panel.appendChild(el('.card', [
      el('.card-head', el('h2', { text: resource.label })),
      el('.card-body', [
        el('p', { text: `Kolom: ${resource.columns.map((column) => column.header).join(', ')}.` }),
        el('p', { text: `Wajib diisi: ${required.join(', ')}.`, style: { color: 'var(--muted)' } }),
        el('.row-actions', [
          button('Unduh template', {
            iconName: 'download',
            onClick: (event) => downloadCsv(
              `core/master-data/${resource.key}/template`,
              `template-${resource.key}.csv`,
              event.currentTarget,
            ),
          }),
          button('Ekspor data saat ini', {
            iconName: 'download',
            title: 'Cara tercepat mengubah ribuan baris: ekspor, ubah di Excel, impor kembali.',
            onClick: (event) => downloadCsv(
              `core/master-data/${resource.key}/export`,
              `${resource.key}.csv`,
              event.currentTarget,
            ),
          }),
        ]),
      ]),
    ]));

    if (!resource.can_import) {
      panel.appendChild(el('.alert.info',
        'Anda dapat mengunduh data ini, tetapi tidak mengimpornya. Impor memperbarui baris '
        + 'yang sudah ada, sehingga memerlukan izin ubah selain izin tambah.'));
      return;
    }

    const picker = el('input', { type: 'file', accept: '.csv,.xlsx,.xls' });
    const result = el('div');

    picker.addEventListener('change', async () => {
      const file = picker.files && picker.files[0];
      if (!file) return;

      clear(result).appendChild(skeletonTable(4));

      try {
        state.filename = file.name;
        state.content = await readAsBase64(file);
        state.preview = await api.post(`core/master-data/${resource.key}/preview`, {
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

      result.appendChild(summaryStrip(preview.summary));
      result.appendChild(rowsTable(preview.rows));

      if (preview.summary.valid === 0) {
        result.appendChild(el('.alert.warn', 'Tidak ada baris yang dapat disimpan. Perbaiki berkas lalu unggah lagi.'));
        return;
      }

      result.appendChild(el('.row-actions', [
        button(`Simpan ${preview.summary.valid} baris`, {
          variant: 'primary',
          onClick: async (event) => {
            try {
              await withBusy(event.currentTarget, async () => {
                const outcome = await api.post(`core/master-data/${resource.key}/import`, {
                  filename: state.filename,
                  content: state.content,
                });
                // The lookup caches feed every reference picker in the app; a
                // fresh 2.000-item catalogue that nothing can select is not an
                // import.
                invalidateByPath(LOOKUP_PATHS[resource.key] || '');
                toast(`${outcome.created} dibuat, ${outcome.updated} diperbarui, ${outcome.skipped} dilewati.`);
                state.preview = null;
                picker.value = '';
                clear(result);
              });
            } catch (error) {
              toastError(error);
            }
          },
        }),
      ]));
    }

    panel.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Unggah berkas' })),
      el('.card-body', [
        el('p', { text: 'Format .csv, .xlsx atau .xls — maksimal 5 MB dan 5.000 baris.' }),
        picker,
        el('p.muted', {
          text: 'Baris dicocokkan berdasarkan kode: kode yang sudah ada akan diperbarui, '
            + 'bukan digandakan. Kolom yang tidak ada di berkas tidak diubah.',
          style: { marginTop: '10px' },
        }),
      ]),
    ]));

    panel.appendChild(result);
  }

  select(resources.find((resource) => resource.key === state.resource) || resources[0]);
  state.resource = state.resource || resources[0].key;
}
