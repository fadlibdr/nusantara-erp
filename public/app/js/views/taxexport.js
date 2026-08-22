/* Ekspor Pajak — e-Faktur (PPN keluaran) and e-Bupot (PPh dipotong).
 *
 * The screen's job is to make the file inspectable BEFORE it reaches DJP's
 * importer: what will be exported, what cannot be and why, and the totals to
 * reconcile against the ledger. The download is built client-side from the CSV
 * text the API returns, because the API authenticates on a header and a plain
 * download link carries none. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, errorState, skeletonTable, confirmDialog, toast, toastError } from '../ui.js';
import * as fmt from '../format.js';
// Pola unduhan Blob+BOM file ini justru yang dibakukan csv.js — kini diimpor
// balik dari sana supaya polanya hidup di satu tempat.
import { downloadCsv } from '../csv.js';

const TABS = [
  { key: 'efaktur', label: 'e-Faktur (PPN Keluaran)', valueKey: 'ppn', valueLabel: 'PPN keluaran' },
  { key: 'ebupot', label: 'e-Bupot (PPh Dipotong)', valueKey: 'pph', valueLabel: 'PPh dipotong' },
];

const COLUMN_LABELS = {
  document: 'Dokumen',
  faktur_pajak_no: 'No. faktur pajak',
  invoice_date: 'Tgl faktur',
  bill_date: 'Tgl potong',
  slip_no: 'No. bukti potong',
  partner: 'Lawan transaksi',
  npwp: 'NPWP',
  tax_code: 'Jenis pajak',
  object_code: 'Kode objek',
  dpp: 'DPP',
  ppn: 'PPN',
  rate: 'Tarif',
  pph: 'PPh',
};

const MONEY = new Set(['dpp', 'ppn', 'pph']);

/** Default to the month just closed — that is the period a filing is prepared for. */
function defaultPeriod() {
  const now = new Date();
  const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  return { year: previous.getFullYear(), month: previous.getMonth() + 1 };
}

const state = { ...defaultPeriod(), tab: 'efaktur' };

function summaryTiles(exp, tab) {
  const s = exp.summary;
  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Siap diekspor' }),
      el('.value', { text: String(s.exported) }),
      el('.delta', { text: `dari ${s.exported + s.blocked} dokumen periode ini` }),
    ]),
    el('.stat', [el('.label', { text: 'Total DPP' }), el('.value.sm', { text: fmt.rupiah(s.dpp) })]),
    el('.stat', [el('.label', { text: tab.valueLabel }), el('.value.sm', { text: fmt.rupiah(s[tab.valueKey]) })]),
    el('.stat', [
      el('.label', { text: 'Tertahan' }),
      el('.value', { text: String(s.blocked), style: s.blocked ? { color: 'var(--danger)' } : {} }),
      el('.delta', { text: s.blocked ? 'perlu dilengkapi' : 'tidak ada' }),
    ]),
  ]);
}

function rowsTable(exp) {
  if (!exp.rows.length) {
    return el('.card-body', el('p.muted', {
      text: 'Tidak ada dokumen yang dapat diekspor untuk periode ini.',
      style: { margin: 0 },
    }));
  }

  const cols = exp.columns;

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', cols.map((c) =>
      el(`th${MONEY.has(c) || c === 'rate' ? '.right' : ''}`, { text: COLUMN_LABELS[c] || c })))),
    el('tbody', exp.rows.map((row) => el('tr', cols.map((c) => {
      if (MONEY.has(c)) return el('td.right.num', { text: fmt.rupiah(row[c]) });
      if (c === 'rate') return el('td.right.num', { text: fmt.percent(row[c]) });
      if (c === 'invoice_date' || c === 'bill_date') return el('td', { text: fmt.date(row[c]) });
      if (c === 'document' || c === 'faktur_pajak_no' || c === 'slip_no' || c === 'npwp' || c === 'object_code') {
        return el('td.code', { text: row[c] || '—' });
      }
      return el('td', { text: row[c] ?? '—' });
    })))),
    el('tfoot', el('tr', cols.map((c, i) => {
      if (MONEY.has(c)) return el('td.right', { text: fmt.rupiah(exp.summary[c] ?? 0) });
      return el('td', { text: i === 0 ? 'Total' : '' });
    }))),
  ]));
}

function blockersCard(exp, tab, onIssueNumbers) {
  if (!exp.blockers.length) return null;

  /* Satu-satunya penghalang yang tidak bisa diperbaiki di dokumennya sendiri:
     nomor bukti potong diterbitkan per masa, sekali. Tanpa tombol ini layar
     menyuruh operator "terbitkan nomor bukti potong masa ini lebih dulu" lalu
     tidak menyediakan satu pun jalan untuk melakukannya. */
  const needsNumbers = tab === 'ebupot'
    && exp.blockers.some((b) => String(b.reason || '').includes('bukti potong'));

  return el('.card', [
    el('.card-head', [
      el('h2', { text: `Tertahan — tidak masuk file (${exp.blockers.length})` }),
      el('.spacer'),
      needsNumbers && session.can('fin.approve')
        ? button('Terbitkan nomor bukti potong', { variant: 'primary', onClick: onIssueNumbers })
        : null,
      badge('Perlu tindakan', 'amber'),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Dokumen' }),
        el('th', { text: 'Lawan transaksi' }),
        el('th.right', { text: 'DPP' }),
        el('th', { text: 'Sebabnya' }),
      ])),
      el('tbody', exp.blockers.map((b) => el('tr', [
        el('td.code', { text: b.document }),
        el('td', { text: b.partner || '—' }),
        el('td.right.num', { text: fmt.rupiah(b.dpp) }),
        el('td', { text: b.reason }),
      ]))),
    ])),
  ]);
}

export async function renderTaxExport(host) {
  clear(host);

  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke ekspor pajak.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Ekspor Pajak' }),
      el('.desc', { text: 'Berkas impor untuk aplikasi DJP — dibentuk dari dokumen yang sudah disetujui.' }),
    ]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const tabs = el('.tabs');
  const body = el('div');
  host.append(tabs, controls, body);

  const yearInput = el('input.filter-w', { type: 'number', value: state.year, min: 2000, max: 2100, 'aria-label': 'Tahun pajak' });
  const monthSelect = el('select.filter-w', { 'aria-label': 'Masa pajak' });
  fmt.MONTHS.forEach((label, i) => monthSelect.appendChild(el('option', { value: i + 1, text: label })));
  monthSelect.value = state.month;

  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value); load(); });
  monthSelect.addEventListener('change', () => { state.month = Number(monthSelect.value); load(); });
  controls.append(monthSelect, yearInput);

  let payload = null;

  function paintTabs() {
    clear(tabs);
    TABS.forEach((tab) => {
      const count = payload ? payload[tab.key].summary.exported : null;
      tabs.appendChild(el(`button${tab.key === state.tab ? '.active' : ''}`, {
        text: count === null ? tab.label : `${tab.label} (${count})`,
        onclick: () => {
          if (state.tab === tab.key) return;
          state.tab = tab.key;
          paintTabs();
          paint();
        },
      }));
    });
  }

  function paint() {
    clear(body);
    if (!payload) return;

    const tab = TABS.find((t) => t.key === state.tab);
    const exp = payload[state.tab];

    body.appendChild(el('.alert.info', [
      icon('warn', 15),
      el('div', [
        el('div', { text: `Periode ${exp.period.label} · NPWP ${exp.company.npwp || '—'}` }),
        el('.muted', {
          style: { fontSize: '12px' },
          text: 'Tata letak kolom mengikuti skema impor e-Faktur/e-Bupot dan dapat berubah mengikuti '
            + 'ketentuan DJP. Impor satu periode ke lingkungan uji dan cocokkan totalnya sebelum dipakai '
            + 'untuk pelaporan.',
        }),
      ]),
    ]));

    body.appendChild(summaryTiles(exp, tab));

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: `Isi berkas — ${exp.filename}` }),
        el('.spacer'),
        button('Unduh CSV', {
          variant: 'primary',
          iconName: 'download',
          disabled: exp.rows.length === 0,
          onClick: () => downloadCsv(exp.filename, exp.csv),
        }),
      ]),
      rowsTable(exp),
    ]));

    const blockers = blockersCard(exp, tab, issueNumbers);
    if (blockers) body.appendChild(blockers);
  }

  /* Sekali per masa dan tidak berubah sesudahnya — nomor bukti potong adalah
     rujukan hukum, bukan nomor urut baris. Servernya idempoten: yang sudah
     bernomor dilewati. */
  async function issueNumbers() {
    const ok = await confirmDialog({
      title: 'Terbitkan nomor bukti potong',
      message: 'Nomor diterbitkan sekali untuk masa ini dan tidak berubah lagi. '
        + 'Tagihan yang sudah bernomor dilewati.',
      confirmLabel: 'Terbitkan',
      tone: 'primary',
    });

    if (!ok) return;

    try {
      const result = await api.post('finance/tax-exports/e-bupot/numbers', {
        year: state.year,
        month: state.month,
      });
      toast(`${(result.summary || {}).issued ?? 0} nomor bukti potong diterbitkan.`);
      await load();
    } catch (error) {
      toastError(error);
    }
  }

  async function load() {
    clear(body);
    body.appendChild(skeletonTable(6, 6));
    try {
      payload = await api.get('finance/tax-exports', { year: state.year, month: state.month });
      paintTabs();
      paint();
    } catch (error) {
      payload = null;
      clear(body).appendChild(errorState(error, load));
    }
  }

  paintTabs();
  await load();
}
