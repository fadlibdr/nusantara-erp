/*
 * Laporan Bebas (Fase 1 / P1-F) — penyusun laporan atas delapan sumber yang
 * sudah boleh dilihat pemakainya.
 *
 * BENTUKNYA MENGIKUTI KATALOG, bukan sebaliknya. Setiap pilihan di layar ini
 * datang dari `GET core/reports/resources`: sumber mana yang ada, kolom mana
 * yang bisa jadi dimensi, kolom mana yang bisa dijumlahkan, saringan apa yang
 * tersedia, dan plafon berapa yang berlaku. Layar ini tidak menghafal satu pun
 * di antaranya — aturan yang sama dengan meta.sortable pada layar daftar dan
 * meta.keys pada preferensi: plafon DIUMUMKAN, tidak disalin.
 *
 * KOLOM YANG DITOLAK TETAP DITAMPILKAN, nonaktif, dengan kalimat alasannya di
 * bawahnya. Itu keputusan yang disengaja dan ia biaya sedikit ruang: seseorang
 * yang mencari "Sisa" pada laporan invoice akan mencarinya DI SINI, dan
 * katalog yang diam-diam menghilangkannya membuat ia mengira aplikasinya rusak
 * — atau lebih buruk, membuatnya menjumlahkan "Total" dan menyangka itu sisa
 * tagihan.
 *
 * ANGKA TIDAK PERNAH DITULIS ULANG DI SINI. Nilai sel datang apa adanya dari
 * server; label enum dan nama relasi ditulis `enumLabel()`/`labelFor()` —
 * fungsi yang SAMA dengan yang dipakai layar daftarnya, jadi pratinjau tidak
 * bisa berbeda dari layar, dan CSV (yang dibangun dari deskriptor yang sama)
 * tidak bisa berbeda dari pratinjau.
 *
 * SEL KOSONG BUKAN NOL. Server mengirim `null` untuk sel yang tidak punya
 * baris sumber DAN untuk sel yang barisnya ada tetapi agregatnya NULL, dengan
 * `counts` di sebelahnya yang membedakan keduanya. Layar ini menuliskan '—'
 * untuk keduanya dan menyebut bedanya di tooltip; ia tidak pernah menulis 0.
 */

import { api, session } from '../api.js';
import { el, clear, button, badge, modal, closeModal, toast, toastError, field, errorState, emptyState, skeletonTable, confirmDialog } from '../ui.js';
import * as fmt from '../format.js';
import { ENUMS, enumLabel } from '../enums.js';
import { labelFor, preload } from '../lookup.js';
import { toCsv, downloadCsv, csvFilename, csvValue } from '../csv.js';

const MODES = [
  { key: 'group', label: 'Kelompok' },
  { key: 'pivot', label: 'Pivot (baris × kolom)' },
  { key: 'detail', label: 'Rincian' },
];

const AGGREGATES = [
  { key: 'sum', label: 'Jumlah (SUM)' },
  { key: 'count', label: 'Banyak baris (COUNT)' },
  { key: 'avg', label: 'Rata-rata (AVG)' },
  { key: 'min', label: 'Terkecil (MIN)' },
  { key: 'max', label: 'Terbesar (MAX)' },
];

const BUCKETS = { day: 'Harian', month: 'Bulanan', year: 'Tahunan' };

const state = {
  catalogue: [],
  limits: null,
  resource: null,
  mode: 'group',
  columns: [],
  row: null,
  rowBucket: null,
  column: null,
  columnBucket: null,
  agg: 'count',
  measureColumn: null,
  filters: { date_from: '', date_to: '', eq: {} },
  result: null,
  saved: [],
};

/** Entri katalog yang sedang dipilih. */
function current() {
  return state.catalogue.find((one) => one.key === state.resource) || null;
}

function columnsOf(entry) {
  return (entry && entry.columns) || [];
}

/** Kolom yang bisa jadi dimensi baris/kolom. */
function dimensions(entry) {
  return columnsOf(entry).filter((column) => column.dimension);
}

/** Kolom yang penjumlahannya berarti. */
function measures(entry) {
  return columnsOf(entry).filter((column) => column.measure);
}

/** Nilai sel apa adanya → teks. `null` selalu '—', tidak pernah 0. */
function cellText(value, type) {
  if (value === null || value === undefined) return '—';
  if (type === 'currency') return fmt.rupiah(value);
  if (type === 'percent' || type === 'progress') return fmt.percent(value);
  // Tanggal diformat seperti di seluruh aplikasi; tanpa cabang ini mode
  // rincian menuliskan '2026-03-25' mentah di sebelah layar daftar yang
  // menuliskan '25 Mar 2026'.
  if (type === 'date') return fmt.date(value);
  return typeof value === 'number' ? fmt.num(value, Number.isInteger(value) ? 0 : 2) : String(value);
}

/** Kunci kelompok → teks, lewat fungsi yang sama dengan layar daftarnya. */
function keyText(descriptor, key) {
  if (key === null || key === undefined || key === '') return '(kosong)';
  if (descriptor && descriptor.bucket) return String(key);
  if (descriptor && descriptor.enum) return enumLabel(descriptor.enum, key) || String(key);
  if (descriptor && descriptor.lookup) return labelFor(descriptor.lookup, key) || `#${key}`;
  return String(key);
}

/* ------------------------------------------------------------------ layar */

export async function renderLaporanBebas(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Laporan Bebas' }),
      el('.desc', {
        text: 'Susun laporan sendiri atas data yang sudah boleh Anda lihat: pilih sumber, kelompokkan, '
          + 'dan jumlahkan. Angkanya dihitung server dengan satu kueri — sama seperti layar daftarnya.',
      }),
    ]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 5));

  let payload;
  try {
    payload = await api.list('core/reports/resources');
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderLaporanBebas(host)));
  }

  state.catalogue = payload.data || [];
  state.limits = (payload.meta || {}).limits || null;

  if (!state.catalogue.length) {
    clear(body);
    body.appendChild(el('.alert.info',
      'Peran Anda belum memiliki akses ke satu pun sumber laporan. Laporan Bebas hanya menawarkan data yang '
      + 'layar daftarnya pun boleh Anda buka.'));
    return;
  }

  if (!state.resource || !current()) selectResource(state.catalogue[0].key);

  // Pemuatan awal daftar relasi supaya labelFor() punya isinya saat menggambar.
  preload(['projects', 'customers', 'vendors']);

  clear(body);
  const controls = el('.card');
  const output = el('div');
  const savedPanel = el('div');
  body.append(controls, output, savedPanel);

  const redraw = () => {
    paintControls(controls, output, savedPanel);
  };

  redraw();
  await refreshSaved(savedPanel, output, redraw);
}

/** Pilih sumber dan setel ulang pilihan yang tidak berlaku lagi untuknya. */
function selectResource(key) {
  state.resource = key;
  const entry = current();
  const dims = dimensions(entry);
  const money = measures(entry);

  state.columns = columnsOf(entry).filter((column) => column.dimension || column.measure).slice(0, 5).map((column) => column.key);
  state.row = dims.length ? dims[0].key : null;
  state.rowBucket = dims.length && dims[0].dimension === 'date' ? 'month' : null;
  state.column = dims.length > 1 ? dims[1].key : null;
  state.columnBucket = dims.length > 1 && dims[1].dimension === 'date' ? 'month' : null;
  state.agg = money.length ? 'sum' : 'count';
  state.measureColumn = money.length ? money[0].key : null;
  state.filters = { date_from: '', date_to: '', eq: {}, in: undefined };
  state.result = null;
}

function paintControls(host, output, savedPanel) {
  clear(host);
  const entry = current();
  const dims = dimensions(entry);
  const money = measures(entry);

  const select = (value, options, onChange, { allowEmpty = false } = {}) => {
    const node = el('select', {
      onchange: (event) => onChange(event.target.value || null),
    }, [
      allowEmpty ? el('option', { value: '', text: '— tidak ada —', selected: !value }) : null,
      ...options.map((option) => el('option', { value: option.key, text: option.label, selected: option.key === value })),
    ]);
    return node;
  };

  const dimensionOptions = dims.map((column) => ({ key: column.key, label: column.label }));

  host.appendChild(el('.card-head', [
    el('h2', { text: 'Susun laporan' }),
    el('.spacer'),
    state.limits
      ? el('.cell-sub', { text: `Maksimal ${fmt.num(state.limits.groups)} kelompok / ${fmt.num(state.limits.rows)} baris rincian` })
      : null,
  ]));

  const grid = el('.card-body', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: '12px' } });

  grid.appendChild(field('Sumber', select(state.resource, state.catalogue.map((one) => ({ key: one.key, label: one.label })), (value) => {
    selectResource(value);
    paintControls(host, output, savedPanel);
    clear(output);
  })));

  grid.appendChild(field('Bentuk', select(state.mode, MODES, (value) => {
    state.mode = value;
    paintControls(host, output, savedPanel);
  })));

  if (state.mode === 'detail') {
    grid.appendChild(field('Kolom', button(`Pilih kolom (${state.columns.length})`, {
      variant: 'ghost',
      onClick: () => openColumnPicker(entry, () => paintControls(host, output, savedPanel)),
    })));
  } else {
    grid.appendChild(field('Kelompokkan menurut', select(state.row, dimensionOptions, (value) => {
      state.row = value;
      const column = columnsOf(entry).find((one) => one.key === value);
      state.rowBucket = column && column.dimension === 'date' ? (state.rowBucket || 'month') : null;
      paintControls(host, output, savedPanel);
    })));

    const rowColumn = columnsOf(entry).find((one) => one.key === state.row);
    if (rowColumn && rowColumn.dimension === 'date') {
      grid.appendChild(field('Satuan periode', select(state.rowBucket,
        (rowColumn.buckets || []).map((one) => ({ key: one, label: BUCKETS[one] || one })),
        (value) => { state.rowBucket = value; })));
    }

    if (state.mode === 'pivot') {
      grid.appendChild(field('Kolom pivot', select(state.column,
        dimensionOptions.filter((one) => one.key !== state.row), (value) => {
          state.column = value;
          const column = columnsOf(entry).find((one) => one.key === value);
          state.columnBucket = column && column.dimension === 'date' ? (state.columnBucket || 'month') : null;
          paintControls(host, output, savedPanel);
        })));

      const colColumn = columnsOf(entry).find((one) => one.key === state.column);
      if (colColumn && colColumn.dimension === 'date') {
        grid.appendChild(field('Satuan periode kolom', select(state.columnBucket,
          (colColumn.buckets || []).map((one) => ({ key: one, label: BUCKETS[one] || one })),
          (value) => { state.columnBucket = value; })));
      }
    }

    grid.appendChild(field('Ukuran', select(state.agg, AGGREGATES, (value) => {
      state.agg = value;
      paintControls(host, output, savedPanel);
    })));

    if (state.agg !== 'count') {
      grid.appendChild(field('Kolom ukuran', select(state.measureColumn,
        money.map((one) => ({ key: one.key, label: one.label })), (value) => { state.measureColumn = value; })));
    }
  }

  if (entry.date_column) {
    grid.appendChild(field('Dari tanggal', el('input', {
      type: 'date', value: state.filters.date_from,
      onchange: (event) => { state.filters.date_from = event.target.value; },
    })));
    grid.appendChild(field('Sampai tanggal', el('input', {
      type: 'date', value: state.filters.date_to,
      onchange: (event) => { state.filters.date_to = event.target.value; },
    })));
  }

  /* Saringan enum saja di v1. Saringan ber-FK (proyek, vendor, pelanggan)
     butuh pemilih ber-cache lookup, dan menawarkan kotak teks untuk sebuah id
     adalah antarmuka yang menyuruh orang mengetik angka — ditulis di laporan
     paket sebagai yang sengaja ditunda. */
  (entry.filters || []).filter((filter) => filter.enum).forEach((filter) => {
    const options = (ENUMS[filter.enum] || []).map((one) => ({ key: one.value, label: one.label }));

    if (!options.length) return;

    grid.appendChild(field(filter.label, select(state.filters.eq[filter.key] || null, options, (value) => {
      if (value) state.filters.eq[filter.key] = value;
      else delete state.filters.eq[filter.key];
    }, { allowEmpty: true })));
  });

  host.appendChild(grid);

  host.appendChild(el('.card-foot', [
    button('Jalankan', { variant: 'primary', onClick: () => run(output) }),
    el('.spacer', { style: { flex: '1' } }),
    button('Simpan laporan…', {
      variant: 'ghost',
      onClick: () => openSaveDialog(() => refreshSaved(savedPanel, output, () => paintControls(host, output, savedPanel))),
    }),
  ]));
}

async function run(output) {
  clear(output).appendChild(skeletonTable(5, 5));

  try {
    const payload = await api.postRaw('core/reports/run', definition());
    state.result = payload;
  } catch (error) {
    state.result = null;
    clear(output).appendChild(el('.card', el('.card-body', errorState(error, () => run(output)))));
    return;
  }

  paintResult(output);
}

/** Definisi yang dikirim ke server — bentuk yang sama dengan yang disimpan. */
function definition() {
  const out = { resource: state.resource, mode: state.mode, filters: {} };

  if (state.mode === 'detail') {
    out.columns = state.columns;
  } else {
    out.row = state.rowBucket ? { column: state.row, bucket: state.rowBucket } : { column: state.row };
    out.measure = state.agg === 'count' ? { agg: 'count' } : { agg: state.agg, column: state.measureColumn };
    if (state.mode === 'pivot') {
      out.column = state.columnBucket ? { column: state.column, bucket: state.columnBucket } : { column: state.column };
    }
  }

  if (state.filters.date_from) out.filters.date_from = state.filters.date_from;
  if (state.filters.date_to) out.filters.date_to = state.filters.date_to;
  if (Object.keys(state.filters.eq || {}).length) out.filters.eq = state.filters.eq;
  if (state.filters.in && Object.keys(state.filters.in).length) out.filters.in = state.filters.in;

  return out;
}

function paintResult(output) {
  clear(output);
  const payload = state.result;
  const data = payload.data;
  const meta = payload.meta || {};
  const descriptors = data.descriptors || {};

  const head = el('.card-head', [
    el('h2', { text: data.resource_label }),
    el('.spacer'),
    el('.cell-sub', {
      text: meta.soft_deleted_excluded
        ? 'Dokumen yang sudah dihapus tidak dihitung'
        : 'Tabel ini tidak mengenal penghapusan lunak',
    }),
  ]);

  const table = data.mode === 'detail' ? detailTable(data, descriptors) : groupedTable(data, descriptors);

  /* Kelas penanda: harness menandai kartu HASIL dengan penanda aplikasi, bukan
     dengan "kartu terakhir di halaman" — daftar laporan tersimpan tumbuh di
     bawahnya dan membuat pemilih posisional itu salah pada jalan kedua. */
  output.appendChild(el('.card.report-result', [
    head,
    table,
    el('.card-foot', [
      button('Unduh CSV', {
        size: 'sm', variant: 'ghost', iconName: 'download',
        onClick: () => downloadResultCsv(data, descriptors),
      }),
      el('.spacer', { style: { flex: '1' } }),
      el('.cell-sub', { text: `${meta.queries} kueri · ${data.mode === 'detail' ? `${data.count} baris` : `${data.groups} kelompok`}` }),
    ]),
  ]));
}

function detailTable(data, descriptors) {
  const columns = descriptors.columns || [];

  if (!data.rows.length) {
    return el('.card-body.flush', emptyState('Tidak ada baris pada jendela ini.', { kind: 'filter', compact: true, title: null }));
  }

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', columns.map((column) => el('th', { text: column.label })))),
    el('tbody', data.rows.map((row) => el('tr', columns.map((column) => {
      const raw = row[column.key];
      const text = column.enum ? (enumLabel(column.enum, raw) || '—')
        : column.lookup ? (labelFor(column.lookup, raw) || (raw === null ? '—' : `#${raw}`))
          : cellText(raw, column.type);
      return el('td', { text });
    })))),
  ]));
}

function groupedTable(data, descriptors) {
  const rowDescriptor = descriptors.row || {};
  const columnDescriptor = descriptors.column || null;
  const measure = descriptors.measure || {};
  const pivot = data.mode === 'pivot';

  if (!data.rows.length) {
    return el('.card-body.flush', emptyState('Tidak ada baris pada jendela ini.', { kind: 'filter', compact: true, title: null }));
  }

  const headCells = [el('th', { text: rowDescriptor.label || 'Kelompok' })];
  (pivot ? data.column_keys : [null]).forEach((key) => {
    headCells.push(el('th.right', { text: pivot ? keyText(columnDescriptor, key) : (measure.label || 'Nilai') }));
  });
  if (pivot) headCells.push(el('th.right', { text: 'Total' }));

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', headCells)),
    el('tbody', data.rows.map((row) => {
      const cells = [el('td', { text: keyText(rowDescriptor, row.key) })];

      row.cells.forEach((value, index) => {
        /* Sel kosong '—', TIDAK PERNAH 0. Dua sebabnya berbeda dan tooltipnya
           menyebut yang mana: tidak ada baris sumber sama sekali, atau ada
           barisnya tetapi angkanya tidak ada (nilai buku alat sewa). */
        const n = (row.counts || [])[index];
        cells.push(el('td.right.num', {
          text: cellText(value, measure.type),
          title: value === null
            ? (n ? `${n} baris, tetapi nilainya tidak ada` : 'Tidak ada baris pada kombinasi ini')
            : `${n} baris`,
        }));
      });

      if (pivot) cells.push(el('td.right.num', { text: cellText(row.total, measure.type) }));

      return el('tr', cells);
    })),
  ]));
}

/** CSV dari deskriptor yang SAMA dengan pratinjau — jadi keduanya tidak bisa berbeda. */
function downloadResultCsv(data, descriptors) {
  const num = (value) => (value === null || value === undefined ? '' : csvValue({ value }, { key: 'value', type: 'number' }));
  let headers;
  let rows;

  if (data.mode === 'detail') {
    const columns = descriptors.columns || [];
    headers = columns.map((column) => column.label);
    rows = data.rows.map((row) => columns.map((column) => {
      const raw = row[column.key];
      if (raw === null || raw === undefined) return '';
      if (column.enum) return enumLabel(column.enum, raw) || raw;
      if (column.lookup) return labelFor(column.lookup, raw) || `#${raw}`;
      /* SETIAP jenis angka lewat csvValue, bukan hanya currency: berkas ini
         dipisah ';' dengan desimal KOMA (Excel-ID), dan sebuah persen yang
         lolos sebagai '12.5' terbaca 125 di sana. */
      return ['currency', 'percent', 'progress', 'number', 'qty'].includes(column.type) ? num(raw) : raw;
    }));
  } else {
    const pivot = data.mode === 'pivot';
    const measure = descriptors.measure || {};
    headers = [(descriptors.row || {}).label || 'Kelompok'];
    (pivot ? data.column_keys : [null]).forEach((key) => {
      headers.push(pivot ? keyText(descriptors.column, key) : (measure.label || 'Nilai'));
    });
    if (pivot) headers.push('Total');

    rows = data.rows.map((row) => {
      const line = [keyText(descriptors.row, row.key)];
      row.cells.forEach((value) => line.push(num(value)));
      if (pivot) line.push(num(row.total));
      return line;
    });
  }

  downloadCsv(csvFilename(`laporan-${data.resource.replace(/\//g, '-')}`), toCsv(headers, rows));
}

/* ------------------------------------------------------- pemilih kolom */

function openColumnPicker(entry, onDone) {
  const rows = columnsOf(entry).map((column) => {
    /* `selectable`, BUKAN `why_not`: sebuah kolom boleh punya alasan kenapa ia
       tidak bisa jadi dimensi ('Kode unik per dokumen…') dan tetap sempurna
       dapat DICETAK. Menyamakan keduanya mematikan setiap kolom kode, nama dan
       keterangan di seluruh katalog. */
    const available = column.selectable !== false;
    const checkbox = el('input', {
      type: 'checkbox',
      checked: state.columns.includes(column.key),
      disabled: !available,
      onchange: (event) => {
        if (event.target.checked) state.columns.push(column.key);
        else state.columns = state.columns.filter((one) => one !== column.key);
      },
    });

    return el('label.field', { style: { opacity: available ? '1' : '.7' } }, [
      el('span', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, [
        checkbox,
        el('span.cell-main', { text: column.label }),
        available ? null : badge('tidak tersedia', 'amber'),
      ]),
      // Kalimat penolakan katalog, di tempat orang mencarinya.
      available ? null : el('.help', { text: column.why_not }),
    ]);
  });

  modal({
    title: 'Pilih kolom',
    body: el('div', [
      el('p.cell-sub', {
        text: 'Kolom yang tidak tersedia adalah kolom layar daftar yang tidak bisa dihasilkan satu kueri; '
          + 'alasannya tertulis di bawah masing-masing.',
        style: { margin: '0 0 12px' },
      }),
      ...rows,
    ]),
    footer: button('Selesai', { variant: 'primary', onClick: () => { closeModal(); onDone(); } }),
  });
}

/* ------------------------------------------------------ laporan tersimpan */

async function refreshSaved(host, output, redraw) {
  let rows = [];
  let failure = null;

  try {
    rows = await api.get('core/reports/saved');
  } catch (error) {
    /* Gagal memuat daftar dan "Anda belum menyimpan laporan" tidak boleh
       terlihat sama: yang kedua adalah pernyataan tentang dunia, dan yang
       pertama adalah kita yang tidak tahu. Aturan Temuan 79, di layar ini. */
    console.error('Laporan Bebas: daftar laporan tersimpan gagal dimuat', error);
    failure = error;
    rows = [];
  }

  state.saved = rows || [];
  clear(host);

  if (failure) {
    host.appendChild(el('.card', { style: { marginTop: '16px' } },
      el('.card-body', errorState({
        message: 'Daftar laporan tersimpan gagal dimuat.',
        details: ['Jangan dibaca sebagai "belum ada laporan tersimpan" — isinya tidak diketahui.', failure.message || String(failure)],
      }, () => refreshSaved(host, output, redraw)))));
    return;
  }

  if (!state.saved.length) return;

  host.appendChild(el('.card', { style: { marginTop: '16px' } }, [
    el('.card-head', [el('h2', { text: `Laporan tersimpan (${state.saved.length})` })]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Nama' }), el('th', { text: 'Sumber' }),
        el('th', { text: 'Dibagikan ke' }), el('th', { text: '' }),
      ])),
      el('tbody', state.saved.map((report) => el('tr', [
        el('td', [
          el('span.cell-main', { text: report.name }),
          el('span.cell-sub', { text: report.is_owner ? 'milik Anda' : `milik ${report.owner_name || 'pengguna lain'}` }),
        ]),
        el('td', { text: report.resource_label }),
        el('td', [
          el('span', { text: report.shared_roles.length ? report.shared_roles.join(', ') : '—' }),
          // Peran yang sudah tidak ada lagi DISEBUT, bukan disembunyikan.
          report.stale_roles.length
            ? el('span.cell-sub', { text: `peran ${report.stale_roles.join(', ')} sudah tidak ada`, style: { color: 'var(--warning)' } })
            : null,
        ]),
        el('td.right', [
          button('Buka', { size: 'sm', variant: 'ghost', onClick: () => openSaved(report, output, redraw) }),
          button('XLSX', { size: 'sm', variant: 'ghost', onClick: () => downloadXlsx(report) }),
          button('Salin', { size: 'sm', variant: 'ghost', onClick: () => copySaved(report, host, output, redraw) }),
          report.is_owner
            ? button('Hapus', { size: 'sm', variant: 'ghost', onClick: () => deleteSaved(report, host, output, redraw) })
            : null,
        ]),
      ]))),
    ])),
  ]));
}

function openSaved(report, output, redraw) {
  /* Sumber yang sudah tidak ada di katalog (dicabut, atau izinnya dicabut)
     tidak boleh menjatuhkan layar: selectResource() akan membaca `null`. */
  if (!state.catalogue.some((one) => one.key === (report.definition || {}).resource || one.key === report.resource)) {
    toast(`Sumber "${report.resource_label || report.resource}" tidak ada di katalog Anda.`, { tone: 'warn' });
    return;
  }

  /* Salinan DALAM, bukan alias: menyunting kolom atau saringan sesudah 'Buka'
     tidak boleh menulis balik ke objek definisi milik baris tersimpan yang
     masih ditampilkan daftar di bawahnya. */
  const definition = JSON.parse(JSON.stringify(report.definition || {}));
  state.resource = definition.resource || report.resource;
  state.mode = definition.mode || 'group';
  state.columns = definition.columns || [];
  state.row = (definition.row || {}).column || null;
  state.rowBucket = (definition.row || {}).bucket || null;
  state.column = (definition.column || {}).column || null;
  state.columnBucket = (definition.column || {}).bucket || null;
  state.agg = (definition.measure || {}).agg || 'count';
  state.measureColumn = (definition.measure || {}).column || null;
  const filters = definition.filters || {};
  state.filters = {
    date_from: filters.date_from || '',
    date_to: filters.date_to || '',
    eq: filters.eq || {},
    // Saringan `in` dibawa APA ADANYA meski layar v1 belum menawarkan
    // pemilihnya: membuangnya diam-diam membuat laporan yang dibuka
    // menghasilkan angka yang berbeda dari laporan yang disimpan.
    in: filters.in || undefined,
  };

  redraw();
  run(output);
}

async function downloadXlsx(report) {
  try {
    const blob = await api.blob(`core/reports/saved/${report.id}/xlsx`);
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `${report.name}.xlsx`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    toastError(error);
  }
}

function copySaved(report, host, output, redraw) {
  api.post(`core/reports/saved/${report.id}/copy`, {})
    .then(() => { toast('Laporan disalin.'); return refreshSaved(host, output, redraw); })
    .catch(toastError);
}

function deleteSaved(report, host, output, redraw) {
  confirmDialog({
    title: 'Hapus laporan',
    message: `Hapus laporan "${report.name}"? Tindakan ini tidak bisa dibatalkan.`,
    confirmLabel: 'Hapus',
    onConfirm: () => api.del(`core/reports/saved/${report.id}`)
      .then(() => { toast('Laporan dihapus.'); return refreshSaved(host, output, redraw); })
      .catch(toastError),
  });
}

function openSaveDialog(onSaved) {
  const nameInput = el('input', { type: 'text', maxlength: '120', placeholder: 'mis. Biaya per kategori' });
  const roles = ((session.user || {}).roles || []);
  const checked = new Set();

  const roleRows = roles.map((role) => el('label.field', [
    el('span', { style: { display: 'flex', gap: '8px', alignItems: 'center' } }, [
      el('input', {
        type: 'checkbox',
        onchange: (event) => { if (event.target.checked) checked.add(role); else checked.delete(role); },
      }),
      el('span', { text: role }),
    ]),
  ]));

  modal({
    title: 'Simpan laporan',
    body: el('div', [
      field('Nama laporan', nameInput, { required: true }),
      el('p.cell-sub', {
        // Aturan yang paling mudah disalahpahami, ditulis apa adanya.
        text: 'Berbagi tidak memberi akses baru: laporan ini hanya sampai kepada orang yang memang boleh '
          + 'melihat sumbernya. Hanya Anda yang dapat mengubahnya — orang lain dapat menyalinnya.',
        style: { margin: '0 0 8px' },
      }),
      roleRows.length ? el('h3.dash-setup-head', { text: 'Bagikan ke peran' }) : null,
      ...roleRows,
    ]),
    initialFocus: nameInput,
    footer: el('div', { style: { display: 'flex', gap: '8px', width: '100%' } }, [
      el('.spacer', { style: { flex: '1' } }),
      button('Batal', { variant: 'ghost', onClick: () => closeModal() }),
      button('Simpan', {
        variant: 'primary',
        onClick: () => {
          api.post('core/reports/saved', {
            name: nameInput.value,
            definition: definition(),
            shared_roles: [...checked],
          }).then(() => {
            closeModal();
            toast('Laporan disimpan.');
            return onSaved();
          }).catch(toastError);
        },
      }),
    ]),
  });
}
