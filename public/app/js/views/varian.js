/* Varian material — teori (koefisien AHSP × volume BOQ) vs aktual (bon gudang).

   Kedua angka itu tidak pernah bertemu di layar mana pun sebelum ini. RAB
   PRJ-2026-001 memesan 948.000 kg besi beton ulir dengan koefisien AHSP 1,05
   kg/kg, jadi teorinya 995.400 kg — Rp 12,44 miliar besi saja pada satu paket
   pekerjaan. Bon pengeluarannya hidup di tabel lain dan tidak pernah
   dikurangkan dari angka itu, sehingga kelebihan pakai bahan baru ketahuan
   waktu proyek ditutup, kalau ketahuan.

   LAYAR INI HANYA BISA SEJUJUR PENANDAAN PAKET PEKERJAAN PADA BON. Di basis
   data demo ketiga baris bon masih kosong paket pekerjaannya, jadi Rp 18,74
   juta bahan yang sudah keluar untuk PRJ-2026-001 tidak bisa ditunjuk ke paket
   mana pun: kolom aktual membaca nol padahal barangnya jelas sudah dipakai.
   Karena itu "bon belum ditandai" duduk sebagai kartu keempat di baris paling
   atas dan tidak pernah disembunyikan — selama angkanya 100%, seluruh kolom
   aktual di bawahnya tidak berarti apa-apa. Itu ukuran pemakaian pemilih paket
   pekerjaan yang baru, bukan cacat laporan.

   SATUAN. AHSP mengukur besi dalam kg, gudang menyimpannya dalam btg. Untuk
   baris seperti itu selisih kuantitas ditulis "—", bukan 995.400 dikurangi 80:
   angka yang salah satuannya lebih berbahaya daripada angka yang tidak ada.
   Selisih NILAI tetap dihitung, karena rupiah boleh dijumlahkan apa pun
   satuannya.

   Semua "per tanggal" datang dari server (as_of), bukan dari jam komputer
   pemakainya. Layar ini hanya membaca: tidak pernah membuat jurnal, tidak
   pernah menyentuh bon. */

import { api, session } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor } from '../lookup.js';
import { navigate } from '../router.js';

const TABS = [
  { key: 'paket', label: 'Per paket pekerjaan' },
  { key: 'material', label: 'Per material' },
  { key: 'belum', label: 'Bon belum ditandai' },
];

/* Catatan baris dikirim server sebagai kode, bukan kalimat: kalimatnya milik
   layar, supaya kata yang dibaca pengawas lapangan bisa diperbaiki di sini. */
const CATATAN = {
  satuan_berbeda: ['Satuan berbeda', 'amber'],
  tanpa_teori: ['Tidak ada di RAB', 'amber'],
  tanpa_ahsp: ['AHSP tanpa rincian bahan', 'amber'],
};

const TONE = { over: 'var(--danger)', under: 'var(--warning)', flat: 'var(--text)' };

const state = {
  projectId: null,
  asOf: '',
  basis: 'progress',
  tab: 'paket',
  onlyFlagged: false,
  serverToday: null,
};

/* ------------------------------------------------------------------ angka */

/** Rupiah ringkas yang boleh tidak diketahui. Nol rupiah dan "belum dihitung"
    adalah dua hal berbeda, jadi hanya yang kedua menjadi "—". */
function money(value) {
  return value === null || value === undefined || value === '' ? '—' : fmt.rupiahShort(value);
}

function exact(value) {
  return value === null || value === undefined || value === '' ? null : fmt.rupiah(value, { decimals: 2 });
}

/** Selisih selalu dibaca "aktual dikurangi teori", jadi tandanya wajib ikut —
    tanpa tanda, Rp 1,2 jt kelebihan pakai dan Rp 1,2 jt sisa terbaca sama. */
function signedMoney(value) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return n > 0 ? `+${fmt.rupiahShort(n)}` : fmt.rupiahShort(n);
}

function signedQty(value, unit) {
  if (value === null || value === undefined || value === '') return '—';
  const n = Number(value);
  if (!Number.isFinite(n)) return '—';
  return n > 0 ? `+${fmt.qty(n, unit)}` : fmt.qty(n, unit);
}

function arah(value) {
  const n = Number(value) || 0;
  if (n > 0) return 'over';
  if (n < 0) return 'under';
  return 'flat';
}

function n0(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/* ------------------------------------------------------------------ potong */

function twoLine(main, sub) {
  return el('span', [
    el('span.cell-main', { text: main }),
    sub ? el('span.cell-sub', { text: sub }) : null,
  ]);
}

function statCard(label, value, hint, { title, alarming = false } = {}) {
  return el('.stat', [
    el('.label', { text: label }),
    el('.value.sm', { text: value, title: title || null }),
    hint ? el(`.delta${alarming ? '.down' : ''}`, { text: hint }) : null,
  ]);
}

/**
 * Status satu baris.
 *
 * "Dalam ambang" hijau hanya boleh muncul kalau memang ADA pemakaian yang
 * tercatat. Baris dengan aktual nol karena bonnya belum ditandai akan terbaca
 * sebagai penghematan sempurna kalau diberi warna hijau — itu kebalikan dari
 * yang sebenarnya terjadi.
 */
function statusBadge(row) {
  if (row.note) {
    const [label, tone] = CATATAN[row.note] || [row.note, 'amber'];
    return badge(label, tone);
  }
  if (!n0(row.actual_value) && !n0(row.actual_qty)) return badge('Belum ada pemakaian', '');
  if (!row.flagged) return badge('Dalam ambang', 'green');
  return n0(row.variance_value) > 0 ? badge('Lewat teori', 'red') : badge('Di bawah teori', 'amber');
}

/** Sel dua baris: kuantitas di atas (yang memisahkan pemakaian dari harga),
    nilai rupiahnya di bawah. */
function qtyValueCell(qtyText, valueText, colour) {
  return el('td.right', el('span', [
    el('span.cell-main.num', { text: qtyText, style: colour ? { color: colour } : {} }),
    el('span.cell-sub', { text: valueText }),
  ]));
}

/* ------------------------------------------------------------ pengelompokan */

function groupByTask(rows) {
  const groups = [];
  const index = new Map();

  rows.forEach((row) => {
    const key = row.wbs_task_id === null || row.wbs_task_id === undefined
      ? `tanpa-paket-${row.wbs_code || ''}`
      : row.wbs_task_id;
    let group = index.get(key);

    if (!group) {
      group = {
        key,
        wbs_code: row.wbs_code,
        wbs_name: row.wbs_name,
        progress_pct: row.progress_pct,
        rows: [],
        theory: 0,
        actual: 0,
      };
      index.set(key, group);
      groups.push(group);
    }

    group.rows.push(row);
    group.theory += n0(row.theory_value);
    group.actual += n0(row.actual_value);
  });

  return groups;
}

/**
 * Satu material di beberapa paket pekerjaan sekaligus.
 *
 * Kuantitas hanya dijumlahkan kalau SELURUH barisnya memakai satuan yang sama;
 * begitu ada satu baris kg berhadapan dengan btg, total kuantitasnya tidak
 * ditampilkan sama sekali. Nilai rupiah tetap dijumlahkan.
 */
function byMaterial(rows) {
  const map = new Map();

  rows.forEach((row) => {
    const key = row.item_id === null || row.item_id === undefined
      ? `x-${row.item_code || row.item_name || ''}`
      : row.item_id;
    let entry = map.get(key);

    if (!entry) {
      entry = {
        item_code: row.item_code,
        item_name: row.item_name,
        unit: row.theory_unit || row.actual_unit || '',
        theory_qty: 0,
        actual_qty: 0,
        theory_value: 0,
        actual_value: 0,
        tasks: 0,
        flagged: false,
        qtyComparable: true,
      };
      map.set(key, entry);
    }

    const theoryUnit = row.theory_unit || '';
    const actualUnit = row.actual_unit || '';
    const mismatched = (theoryUnit && actualUnit && theoryUnit !== actualUnit)
      || (theoryUnit && entry.unit && theoryUnit !== entry.unit)
      || (actualUnit && entry.unit && actualUnit !== entry.unit);
    if (mismatched) entry.qtyComparable = false;

    entry.theory_qty += n0(row.theory_qty);
    entry.actual_qty += n0(row.actual_qty);
    entry.theory_value += n0(row.theory_value);
    entry.actual_value += n0(row.actual_value);
    entry.tasks += 1;
    if (row.flagged) entry.flagged = true;
  });

  // Yang paling besar rupiah selisihnya di atas — itu yang orang cari di sini.
  return [...map.values()].sort((a, b) =>
    Math.abs(b.actual_value - b.theory_value) - Math.abs(a.actual_value - a.theory_value));
}

/* -------------------------------------------------------------- tampilan */

function statRow(report, summary) {
  const basisText = report.basis === 'full'
    ? 'volume kontrak penuh'
    : 'sampai progres tiap paket';
  const unattributedPct = summary.unattributed_issue_pct;
  const belumAda = unattributedPct === null || unattributedPct === undefined;

  return el('.stat-row', [
    statCard('Teori bahan', money(summary.theory_value),
      `${basisText} · per ${fmt.date(report.as_of)}`,
      { title: exact(summary.theory_value) }),
    statCard('Aktual keluar gudang', money(summary.actual_value),
      'bon terposting yang sudah ditandai paket',
      { title: exact(summary.actual_value) }),
    statCard('Selisih', signedMoney(summary.variance_value),
      `aktual − teori · ${fmt.percent(summary.variance_pct)} terhadap teori`,
      { title: exact(summary.variance_value), alarming: n0(summary.variance_value) > 0 }),
    statCard('Bon belum ditandai',
      belumAda ? '—' : fmt.percent(unattributedPct, { decimals: 0 }),
      `${money(summary.unattributed_value)} belum masuk hitungan`,
      { title: exact(summary.unattributed_value), alarming: n0(summary.unattributed_value) > 0 }),
  ]);
}

/** Kalimat yang harus dibaca sebelum angka mana pun di bawahnya dipercaya. */
function peringatan(summary) {
  const value = n0(summary.unattributed_value);
  if (value <= 0) return null;

  // Rupiahnya yang memimpin kalimat, bukan persennya: persen bisa saja tidak
  // dikirim, sedangkan nilai yang tidak bisa ditelusuri selalu ada angkanya.
  const pct = summary.unattributed_issue_pct;
  const porsi = pct === null || pct === undefined
    ? ''
    : ` — ${fmt.percent(pct, { decimals: 0 })} dari seluruh nilai bon proyek ini`;

  return el('.alert.warn',
    `${money(value)} bahan sudah keluar gudang tanpa penandaan paket pekerjaan${porsi}. `
    + 'Selama itu belum dibereskan, kolom "aktual" di bawah lebih kecil daripada pemakaian yang '
    + 'sebenarnya, dan selisih di bawah teori bukan berarti hemat. Penandaannya diisi di bon '
    + 'pengeluaran — ada di kepala bon dan bisa ditimpa per baris.');
}

/** Berapa banyak paket yang memang tidak punya pembanding, disebut terus terang. */
function cakupan(summary) {
  const leaf = summary.leaf_task_count;
  const withTheory = summary.tasks_with_theory;
  if (leaf === null || leaf === undefined || withTheory === null || withTheory === undefined) return null;
  if (n0(withTheory) >= n0(leaf)) return null;

  return el('.alert.info',
    `${n0(leaf) - n0(withTheory)} dari ${n0(leaf)} paket pekerjaan tidak punya teori bahan — `
    + 'entah paketnya tidak terhubung ke baris BOQ, atau AHSP-nya tidak merinci bahan (hanya upah '
    + 'dan alat). Paket seperti itu tidak muncul di tabel ini, dan pemakaian bahannya tidak sedang '
    + 'diawasi oleh siapa pun.');
}

function kosong(text) {
  return el('.alert.info', text);
}

function paketView(host, rows, summary, hiddenCount) {
  if (!rows.length) {
    host.appendChild(kosong(hiddenCount > 0
      ? `Semua ${hiddenCount} baris berada di dalam ambang. Hilangkan centang "Hanya yang melewati ambang" untuk melihatnya.`
      : 'Belum ada paket pekerjaan yang bisa dibandingkan. Baris muncul begitu paket pekerjaan '
        + 'terhubung ke baris BOQ yang AHSP-nya merinci bahan.'));
    return;
  }

  const lines = el('tbody');

  groupByTask(rows).forEach((group) => {
    const selisih = group.actual - group.theory;

    lines.appendChild(el('tr.section-row', [
      el('td', { colspan: 3 }, twoLine(
        [group.wbs_code, group.wbs_name].filter(Boolean).join(' · ') || '—',
        `progres ${fmt.percent(group.progress_pct, { decimals: 1 })} · teori ${money(group.theory)} · aktual ${money(group.actual)}`,
      )),
      el('td.right.num', { text: signedMoney(selisih), style: { color: TONE[arah(selisih)] } }),
      el('td'),
    ]));

    group.rows.forEach((row) => {
      lines.appendChild(el('tr', [
        el('td', twoLine(row.item_name || '—', row.item_code || '')),
        qtyValueCell(fmt.qty(row.theory_qty, row.theory_unit), money(row.theory_value)),
        qtyValueCell(fmt.qty(row.actual_qty, row.actual_unit), money(row.actual_value)),
        qtyValueCell(
          signedQty(row.variance_qty, row.theory_unit),
          signedMoney(row.variance_value),
          TONE[arah(row.variance_value)],
        ),
        el('td', statusBadge(row)),
      ]));
    });
  });

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Teori vs aktual per paket pekerjaan' }),
      el('.cell-sub', { text: 'kuantitas di atas, nilai rupiah di bawahnya' }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Material' }),
        el('th.right', { text: 'Teori' }),
        el('th.right', { text: 'Aktual' }),
        el('th.right', { text: 'Selisih' }),
        el('th', { text: 'Status' }),
      ])),
      lines,
      // Total datang dari server, bukan dari penjumlahan baris yang kebetulan
      // sedang tampil — filter ambang tidak boleh mengubah total proyek.
      el('tfoot', el('tr', [
        el('td', twoLine('Total proyek',
          hiddenCount > 0 ? `${hiddenCount} baris disembunyikan filter, tetap dihitung di sini` : '')),
        el('td.right.num', { text: money(summary.theory_value) }),
        el('td.right.num', { text: money(summary.actual_value) }),
        el('td.right.num', {
          text: signedMoney(summary.variance_value),
          style: { color: TONE[arah(summary.variance_value)] },
        }),
        el('td'),
      ])),
    ])),
  ]));
}

function materialView(host, rows, hiddenCount) {
  if (!rows.length) {
    host.appendChild(kosong(hiddenCount > 0
      ? `Semua ${hiddenCount} baris berada di dalam ambang.`
      : 'Belum ada material yang bisa dibandingkan pada proyek ini.'));
    return;
  }

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Rekap per material' }),
      el('.cell-sub', { text: 'selisih rupiah terbesar di atas' }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Material' }),
        el('th.right', { text: 'Teori' }),
        el('th.right', { text: 'Aktual' }),
        el('th.right', { text: 'Selisih' }),
        el('th', { text: '' }),
      ])),
      el('tbody', byMaterial(rows).map((entry) => {
        const selisih = entry.actual_value - entry.theory_value;

        return el('tr', [
          el('td', twoLine(entry.item_name || '—',
            [entry.item_code, `${entry.tasks} paket`].filter(Boolean).join(' · '))),
          qtyValueCell(
            entry.qtyComparable ? fmt.qty(entry.theory_qty, entry.unit) : '—',
            money(entry.theory_value),
          ),
          qtyValueCell(
            entry.qtyComparable ? fmt.qty(entry.actual_qty, entry.unit) : '—',
            money(entry.actual_value),
          ),
          qtyValueCell(
            entry.qtyComparable ? signedQty(entry.actual_qty - entry.theory_qty, entry.unit) : '—',
            signedMoney(selisih),
            TONE[arah(selisih)],
          ),
          el('td', entry.qtyComparable
            ? (entry.flagged ? badge('Melewati ambang', selisih > 0 ? 'red' : 'amber') : badge('Dalam ambang', 'green'))
            : badge('Satuan berbeda', 'amber')),
        ]);
      })),
    ])),
  ]));
}

function belumView(host, report, summary) {
  const rows = report.unattributed || [];

  if (!rows.length) {
    host.appendChild(kosong('Setiap baris bon proyek ini sudah ditandai paket pekerjaannya. '
      + 'Kolom aktual di tab lain memuat seluruh pemakaian yang tercatat.'));
    return;
  }

  const total = summary.unattributed_line_count;
  const dipotong = n0(total) > rows.length;
  const canOpen = session.can('inv.view');

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Baris bon tanpa paket pekerjaan' }),
      el('.cell-sub', {
        text: dipotong
          ? `${rows.length} dari ${n0(total)} baris ditampilkan`
          : `${rows.length} baris`,
      }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Bon' }),
        el('th', { text: 'Tanggal' }),
        el('th', { text: 'Material' }),
        el('th.right', { text: 'Qty' }),
        el('th.right', { text: 'Nilai' }),
        el('th', { text: '' }),
      ])),
      el('tbody', rows.map((row) => el('tr', [
        el('td', el('span.cell-main.mono', { text: row.issue_code || '—' })),
        el('td', { text: fmt.date(row.issue_date) }),
        el('td', twoLine(row.item_name || '—', row.item_code || '')),
        el('td.right.num', { text: fmt.qty(row.qty, row.unit) }),
        el('td.right.num', { text: fmt.rupiah(row.amount) }),
        el('td.right', canOpen && row.issue_id
          ? button('Buka bon', {
            size: 'sm',
            // Penandaannya diperbaiki di bonnya sendiri: paket pekerjaan ada di
            // kepala bon dan bisa ditimpa per baris.
            onClick: () => navigate(`d/inventory/issues/${row.issue_id}`),
          })
          : null),
      ]))),
    ])),
  ]));
}

function caraKerjanya(report) {
  const t = report.thresholds || {};
  // Ambang yang tidak dikirim server tidak boleh dikarang di sini — kalimatnya
  // yang berubah, bukan angkanya diganti nol.
  const ambangDiketahui = t.pct !== null && t.pct !== undefined;

  return el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', { text: 'Teori = koefisien bahan pada AHSP × volume BOQ paket pekerjaan, dikalikan progres paket itu. Paket yang tidak terhubung ke baris BOQ, atau yang AHSP-nya hanya berisi upah dan alat, tidak punya teori dan sengaja tidak ditampilkan sebagai nol.' }),
      el('p', { text: 'Aktual = baris bon pengeluaran yang sudah diposting DAN ditandai paket pekerjaan itu. Bon draft tidak dihitung. Baris yang belum ditandai tidak jatuh ke paket mana pun; semuanya dikumpulkan di tab "Bon belum ditandai" supaya tidak hilang diam-diam.' }),
      el('p', { text: 'Satuan tidak pernah dipaksa sama. AHSP mengukur besi beton dalam kg sedangkan gudang menyimpannya dalam btg, jadi selisih kuantitas baris seperti itu ditulis "—" dan diberi catatan "Satuan berbeda". Selisih nilainya tetap dihitung, karena rupiah bisa dijumlahkan apa pun satuannya.' }),
      el('p', { text: 'Nilai teori memakai harga satuan AHSP (harga yang dianggarkan), nilai aktual memakai harga pokok persediaan saat barang keluar. Karena itu selisih NILAI memuat dua sebab sekaligus — pemakaian dan harga — sedangkan selisih KUANTITAS hanya memuat pemakaian. Untuk menuduh orang boros, baca kolom kuantitas.' }),
      el('p', {
        text: ambangDiketahui
          ? `Baris ditandai melewati ambang kalau selisihnya lebih dari ${fmt.percent(t.pct)} DAN `
            + `${fmt.rupiah(t.value)}, atau kalau nilainya sendiri sudah ${fmt.rupiah(t.always_show_value)} `
            + 'ke atas berapa pun persennya. Ambang ini disetel di pengaturan, bukan di layar ini.'
          : 'Ambang penandaan diatur di pengaturan sistem dan tidak disebutkan pada jawaban server ini, '
            + 'jadi angkanya tidak ditampilkan di sini.',
      }),
      el('p', { text: 'Laporan ini hanya membaca. Ia tidak membuat jurnal, tidak mengubah bon, dan tidak mengubah RAB — memperbaiki angkanya berarti memperbaiki dokumennya.' }),
    ]),
  ]);
}

/* ---------------------------------------------------------------- layar */

export async function renderVarian(host) {
  clear(host);

  if (!session.can('prj.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki hak akses prj.view untuk laporan varian material.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Varian Material' }),
      el('.desc', {
        text: 'Bahan yang seharusnya terpakai menurut AHSP dan volume BOQ, dibandingkan dengan '
          + 'bahan yang benar-benar keluar gudang untuk paket pekerjaan yang sama.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() })]),
  ]));

  const tabs = el('.tabs');
  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(tabs, controls, body);

  const projectRows = await loadSource('projects').catch(() => []);
  const options = optionsFor('projects', projectRows);

  const projectSelect = el('select.filter-w', {
    'aria-label': 'Proyek',
    onchange: () => {
      state.projectId = projectSelect.value ? Number(projectSelect.value) : null;
      load();
    },
  });
  options.forEach((option) => projectSelect.appendChild(el('option', { value: option.value, text: option.label })));

  if (!options.some((option) => String(option.value) === String(state.projectId))) {
    state.projectId = options.length ? Number(options[0].value) : null;
  }
  projectSelect.value = state.projectId === null ? '' : String(state.projectId);

  const basisSelect = el('select.filter-w', {
    'aria-label': 'Dasar perhitungan teori',
    title: 'Teori diukur sampai progres tiap paket, atau untuk seluruh volume kontrak',
    onchange: () => { state.basis = basisSelect.value; load(); },
  });
  basisSelect.appendChild(el('option', { value: 'progress', text: 'Teori sampai progres paket' }));
  basisSelect.appendChild(el('option', { value: 'full', text: 'Teori volume kontrak penuh' }));
  basisSelect.value = state.basis;

  /* Kosong berarti "hari ini menurut server". Batas atasnya baru dipasang
     setelah server menyebut tanggalnya sendiri: memakai jam browser akan
     menolak tanggal yang sah di komputer yang jamnya mundur. */
  const asOfInput = el('input.filter-w', {
    type: 'date',
    value: state.asOf,
    'aria-label': 'Posisi tanggal',
    title: 'Kosongkan untuk memakai tanggal hari ini menurut server',
    onchange: () => { state.asOf = asOfInput.value; load(); },
  });
  if (state.serverToday) asOfInput.max = state.serverToday;

  const flaggedBox = el('input', {
    type: 'checkbox',
    id: 'varian-hanya-ambang',
    onchange: () => { state.onlyFlagged = flaggedBox.checked; paint(); },
  });
  flaggedBox.checked = state.onlyFlagged;

  const flaggedRow = el('.check-row', [
    flaggedBox,
    el('label', {
      for: 'varian-hanya-ambang',
      text: 'Hanya yang melewati ambang',
      title: 'Baris yang tidak bisa dihitung — satuan berbeda, tanpa teori — tetap ditampilkan.',
    }),
  ]);

  controls.append(
    projectSelect,
    basisSelect,
    asOfInput,
    el('span.cell-sub', { text: 'kosong = tanggal server' }),
    flaggedRow,
    el('.spacer'),
    button('Muat ulang', { size: 'sm', variant: 'ghost', iconName: 'refresh', onClick: () => load() }),
  );

  let report = null;

  function paintTabs() {
    clear(tabs);
    TABS.forEach((tab) => tabs.appendChild(el(`button${tab.key === state.tab ? '.active' : ''}`, {
      text: tab.label,
      onclick: () => {
        if (state.tab === tab.key) return;
        state.tab = tab.key;
        paintTabs();
        // Ketiga tab dibaca dari SATU jawaban server; berpindah tab tidak boleh
        // memanggil ulang laporan yang berat ini.
        paint();
      },
    })));
  }

  /** Tanggal server hanya diketahui saat kita TIDAK mengirim as_of sendiri. */
  function rememberServerToday(asOf) {
    if (state.asOf || !asOf) return;
    state.serverToday = asOf;
    asOfInput.max = asOf;
  }

  function paint() {
    if (!report) return;
    clear(body);

    // Ambang tidak menyaring apa pun di daftar bon yang belum ditandai, dan
    // penyaring yang terlihat tetapi tidak bekerja terbaca sebagai rusak.
    flaggedRow.style.display = state.tab === 'belum' ? 'none' : '';

    const summary = report.summary || {};
    const allRows = report.rows || [];
    // Baris bercatatan ikut tampil walau tidak melewati ambang: satuan yang
    // berbeda atau teori yang tidak ada berarti selisihnya TIDAK DIKETAHUI, dan
    // menyembunyikannya di balik penyaring "hanya yang bermasalah" akan
    // membuatnya terbaca sebagai baris yang sudah diperiksa dan wajar.
    const shown = state.onlyFlagged ? allRows.filter((row) => row.flagged || row.note) : allRows;
    const hidden = allRows.length - shown.length;

    body.appendChild(statRow(report, summary));

    const warning = peringatan(summary);
    if (warning) body.appendChild(warning);

    if (state.tab === 'paket') {
      const coverage = cakupan(summary);
      if (coverage) body.appendChild(coverage);
      paketView(body, shown, summary, hidden);
    } else if (state.tab === 'material') {
      materialView(body, shown, hidden);
    } else {
      belumView(body, report, summary);
    }

    body.appendChild(caraKerjanya(report));

    body.appendChild(el('.row-actions', [
      session.can('inv.view')
        ? button('Buka pengeluaran barang', { iconName: 'chevron', onClick: () => navigate('r/inventory/issues') })
        : null,
      state.projectId
        ? button('Buka proyek', { iconName: 'chevron', onClick: () => navigate(`d/projects/${state.projectId}`) })
        : null,
    ]));
  }

  async function load() {
    clear(body);

    if (state.projectId === null) {
      body.appendChild(el('.alert.warn',
        'Belum ada proyek yang dapat dibaca. Buat proyek lebih dulu, atau minta hak akses prj.view.'));
      return;
    }

    body.appendChild(skeletonTable(6, 5));

    try {
      report = await api.get(`projects/${state.projectId}/material-variance`, {
        as_of: state.asOf || undefined,
        basis: state.basis,
      });
    } catch (error) {
      report = null;
      clear(body).appendChild(errorState(error, load));
      return;
    }

    // 204 dan badan jawaban yang bukan JSON sama-sama sampai ke sini sebagai
    // null; membacanya langsung akan mematikan seluruh halaman, bukan layar ini
    // saja, dan tanpa satu kata pun di layar.
    if (!report) {
      clear(body).appendChild(el('.alert.warn',
        'Server tidak mengirimkan isi laporan untuk proyek ini. Coba muat ulang; '
        + 'kalau tetap kosong, laporannya belum tersedia di server ini.'));
      return;
    }

    rememberServerToday(report.as_of);
    paint();
  }

  paintTabs();
  await load();
}
