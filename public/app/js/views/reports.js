/* Finance reports: trial balance, P&L, balance sheet, AR/AP aging and
   project profitability — all read-only views over the posted ledger. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, errorState, skeletonTable, progressBar } from '../ui.js';
import * as fmt from '../format.js';
import { toCsv, downloadCsv, csvValue, csvFilename } from '../csv.js';
import { loadSource, optionsFor } from '../lookup.js';
// Badan kedua tab arus kas hidup di modul sendiri (cashflow.js) supaya file
// ini hanya bertambah dua entri TABS + dua baris builders.
import { cashFlowStatement, cashProjection } from './cashflow.js';

const TABS = [
  { key: 'trial-balance', label: 'Neraca Saldo' },
  { key: 'profit-loss', label: 'Laba Rugi' },
  { key: 'balance-sheet', label: 'Neraca' },
  { key: 'cash-flow', label: 'Arus Kas' },
  { key: 'cash-projection', label: 'Proyeksi Kas 90 Hari' },
  { key: 'ar-aging', label: 'Umur Piutang' },
  { key: 'ap-aging', label: 'Umur Hutang' },
  { key: 'project-profitability', label: 'Profitabilitas Proyek' },
];

const state = { tab: 'trial-balance', params: {} };

function moneyCell(value) {
  return el('td.right.num', { text: Number(value) === 0 ? '—' : fmt.rupiah(value) });
}

/* --------------------------------------------------------------- CSV unduh
 * Satu tombol per tab yang menserialisasi payload laporan yang SUDAH diambil
 * (bukan DOM): endpoint laporan tidak berhalaman, jadi layar == berkas.
 * Angka lewat csvValue milik csv.js — mentah berkoma desimal, bukan string
 * rupiah() — supaya Excel-ID si KAP membacanya sebagai angka. */
const num = (value) => csvValue({ value }, { key: 'value', type: 'number' });

function csvButton(build) {
  return button('Unduh CSV', {
    size: 'sm', variant: 'ghost', iconName: 'download',
    onClick: () => {
      const { filename, headers, rows } = build();
      downloadCsv(filename, toCsv(headers, rows));
    },
  });
}

function sectionTable(title, rows, total, { negate = false } = {}) {
  return el('.card', [
    el('.card-head', [el('h2', { text: title }), el('.spacer'), el('span.num', { text: fmt.rupiah(total), style: { fontWeight: '700' } })]),
    rows.length
      ? el('.table-wrap', el('table.data', [
        el('tbody', rows.map((row) => el('tr', [
          el('td.code', { text: row.account_code || '', style: { width: '1%' } }),
          el('td', { text: row.account_name }),
          moneyCell(negate ? -row.amount : (row.amount ?? row.balance)),
        ]))),
      ]))
      : el('.card-body', el('p.muted', { text: 'Tidak ada saldo.', style: { margin: 0 } })),
  ]);
}

async function trialBalance(host, controls) {
  const now = new Date();
  const year = Number(state.params.year || now.getFullYear());
  const month = Number(state.params.month || now.getMonth() + 1);

  controls.append(
    yearInput(year, (value) => { state.params.year = value; render(host); }),
    monthInput(month, (value) => { state.params.month = value; render(host); }),
  );

  const report = await api.get('finance/reports/trial-balance', { year, month });

  // Berkas andalan KAP: seluruh kolom yang tampil, plus baris Total sebagai
  // angka kontrol untuk direkonsiliasi.
  controls.appendChild(csvButton(() => ({
    filename: `neraca-saldo_${report.year}-${String(report.month).padStart(2, '0')}.csv`,
    headers: ['kode', 'nama akun', 'saldo awal debit', 'saldo awal kredit',
      'mutasi debit', 'mutasi kredit', 'saldo akhir debit', 'saldo akhir kredit'],
    rows: [
      ...report.rows.map((row) => [
        row.account_code, row.account_name,
        num(row.opening_debit), num(row.opening_credit),
        num(row.debit), num(row.credit),
        num(row.closing_debit), num(row.closing_credit),
      ]),
      ['', 'Total',
        num(report.totals.opening_debit), num(report.totals.opening_credit),
        num(report.totals.debit), num(report.totals.credit),
        num(report.totals.closing_debit), num(report.totals.closing_credit)],
    ],
  })));

  return el('div', [
    el('.alert.info', [
      icon('warn', 15),
      el('div', { text: `Periode ${fmt.periodLabel(report.year, report.month)} — ${report.balanced ? 'buku seimbang.' : 'PERHATIAN: debit dan kredit tidak seimbang.'}` }),
    ]),
    el('.card', { style: { marginTop: '14px' } }, el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }), el('th', { text: 'Akun' }),
        el('th.right', { text: 'Saldo awal D' }), el('th.right', { text: 'Saldo awal K' }),
        el('th.right', { text: 'Mutasi D' }), el('th.right', { text: 'Mutasi K' }),
        el('th.right', { text: 'Saldo akhir D' }), el('th.right', { text: 'Saldo akhir K' }),
      ])),
      el('tbody', report.rows.map((row) => el('tr', [
        el('td.code', { text: row.account_code }),
        el('td', { text: row.account_name }),
        moneyCell(row.opening_debit), moneyCell(row.opening_credit),
        moneyCell(row.debit), moneyCell(row.credit),
        moneyCell(row.closing_debit), moneyCell(row.closing_credit),
      ]))),
      el('tfoot', el('tr', [
        el('td', { text: 'Total', colspan: 2 }),
        moneyCell(report.totals.opening_debit), moneyCell(report.totals.opening_credit),
        moneyCell(report.totals.debit), moneyCell(report.totals.credit),
        moneyCell(report.totals.closing_debit), moneyCell(report.totals.closing_credit),
      ])),
    ]))),
  ]);
}

async function profitLoss(host, controls) {
  const now = new Date();
  const from = state.params.from || `${now.getFullYear()}-01-01`;
  const to = state.params.to || fmt.toDateInput(now);

  controls.append(
    dateInput('Dari', from, (value) => { state.params.from = value; render(host); }),
    dateInput('Sampai', to, (value) => { state.params.to = value; render(host); }),
    await projectSelect((value) => { state.params.project_id = value; render(host); }, state.params.project_id),
  );

  const report = await api.get('finance/reports/profit-loss', {
    from, to, project_id: state.params.project_id || undefined,
  });

  controls.appendChild(csvButton(() => {
    // 'bagian' menjaga baris tiap seksi tetap teridentifikasi setelah tabelnya
    // menjadi satu berkas datar; amount ?? balance mengikuti sectionTable.
    const section = (label, group) => [
      ...group.rows.map((row) => [label, row.account_code || '', row.account_name, num(row.amount ?? row.balance)]),
      [label, '', 'Total', num(group.total)],
    ];
    return {
      filename: `laba-rugi_${from}_${to}.csv`,
      headers: ['bagian', 'kode', 'nama akun', 'jumlah'],
      rows: [
        ...section('Pendapatan', report.revenue),
        ...section('Beban Proyek (HPP)', report.cogs),
        ...section('Beban Operasional', report.operating_expenses),
        ...(report.other.rows.length ? section('Pendapatan / Beban Lain', report.other) : []),
        ['Ringkasan', '', 'Laba kotor', num(report.gross_profit)],
        ['Ringkasan', '', 'Laba usaha', num(report.operating_profit)],
        ['Ringkasan', '', 'Laba bersih', num(report.net_profit)],
      ],
    };
  }));

  const line = (label, value, { strong = false, tone } = {}) => el('div', {
    style: {
      display: 'flex', justifyContent: 'space-between', gap: '16px',
      padding: strong ? '11px 0' : '6px 0',
      borderTop: strong ? '1px solid var(--border)' : 'none',
      fontWeight: strong ? '700' : '400',
      fontSize: strong ? '14px' : '13px',
      color: tone === 'bad' ? 'var(--danger)' : (tone === 'good' ? 'var(--success)' : 'inherit'),
    },
  }, [el('span', { text: label }), el('span.num', { text: fmt.rupiah(value) })]);

  return el('div', [
    el('.stat-row', [
      el('.stat', [el('.label', { text: 'Pendapatan' }), el('.value.sm', { text: fmt.rupiah(report.revenue.total) })]),
      el('.stat', [el('.label', { text: 'Laba kotor' }), el('.value.sm', { text: fmt.rupiah(report.gross_profit) })]),
      el('.stat', [
        el('.label', { text: 'Laba bersih' }),
        el('.value.sm', { text: fmt.rupiah(report.net_profit), style: { color: report.net_profit < 0 ? 'var(--danger)' : 'var(--success)' } }),
      ]),
    ]),
    sectionTable('Pendapatan', report.revenue.rows, report.revenue.total),
    sectionTable('Beban Proyek (HPP)', report.cogs.rows, report.cogs.total),
    sectionTable('Beban Operasional', report.operating_expenses.rows, report.operating_expenses.total),
    report.other.rows.length ? sectionTable('Pendapatan / Beban Lain', report.other.rows, report.other.total) : null,
    el('.card', el('.card-body', [
      line('Pendapatan', report.revenue.total),
      line('Beban proyek (HPP)', -report.cogs.total),
      line('Laba kotor', report.gross_profit, { strong: true }),
      line('Beban operasional', -report.operating_expenses.total),
      line('Laba usaha', report.operating_profit, { strong: true }),
      line('Pendapatan/beban lain', report.other.total),
      line('Laba bersih', report.net_profit, { strong: true, tone: report.net_profit < 0 ? 'bad' : 'good' }),
    ])),
  ]);
}

async function balanceSheet(host, controls) {
  const asOf = state.params.as_of || fmt.toDateInput(new Date());
  controls.append(dateInput('Per tanggal', asOf, (value) => { state.params.as_of = value; render(host); }));

  const report = await api.get('finance/reports/balance-sheet', { as_of: asOf });

  controls.appendChild(csvButton(() => {
    const section = (label, group) => [
      ...group.rows.map((row) => [label, row.account_code || '', row.account_name, num(row.amount ?? row.balance)]),
      [label, '', 'Total', num(group.total)],
    ];
    return {
      filename: `neraca_${fmt.toDateInput(report.as_of)}.csv`,
      headers: ['bagian', 'kode', 'nama akun', 'jumlah'],
      rows: [
        ...section('Aset', report.assets),
        ...section('Kewajiban', report.liabilities),
        ...section('Ekuitas', report.equity),
      ],
    };
  }));

  return el('div', [
    el(`.alert.${report.balanced ? 'info' : 'error'}`, [
      icon(report.balanced ? 'check' : 'warn', 15),
      el('div', {
        text: report.balanced
          ? `Neraca seimbang per ${fmt.dateLong(report.as_of)}.`
          : `Neraca TIDAK seimbang: aset ${fmt.rupiah(report.assets.total)} vs kewajiban+ekuitas ${fmt.rupiah(report.liabilities_and_equity)}.`,
      }),
    ]),
    el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: '16px', marginTop: '14px' } }, [
      sectionTable('Aset', report.assets.rows, report.assets.total),
      el('div', [
        sectionTable('Kewajiban', report.liabilities.rows, report.liabilities.total),
        sectionTable('Ekuitas', report.equity.rows, report.equity.total),
      ]),
    ]),
  ]);
}

async function aging(host, controls, side) {
  const report = await api.get(`finance/reports/${side}-aging`);

  // Rincian dokumennya yang diserialisasi — baris itulah yang direkonsiliasi;
  // ringkasan keranjang hanyalah penjumlahan ulang dari kolom sisa.
  controls.appendChild(csvButton(() => ({
    filename: csvFilename(side === 'ar' ? 'umur-piutang' : 'umur-hutang'),
    headers: ['dokumen', side === 'ar' ? 'pelanggan' : 'vendor', 'tanggal', 'jatuh tempo',
      'total', 'dibayar', 'sisa', 'hari terlambat'],
    rows: report.rows.map((row) => [
      row.code, row.partner || '',
      row.document_date ? fmt.toDateInput(row.document_date) : '',
      row.due_date ? fmt.toDateInput(row.due_date) : '',
      num(row.total), num(row.amount_paid), num(row.outstanding), num(row.days_overdue),
    ]),
  })));

  const labels = {
    current: 'Belum jatuh tempo', '1_30': '1–30 hari', '31_60': '31–60 hari',
    '61_90': '61–90 hari', over_90: '> 90 hari',
  };
  const tones = { current: '', '1_30': 'blue', '31_60': 'amber', '61_90': 'amber', over_90: 'red' };
  const max = Math.max(...Object.values(report.buckets), 1);

  return el('div', [
    el('.card', [
      el('.card-head', [
        el('h2', { text: `Ringkasan umur ${side === 'ar' ? 'piutang' : 'hutang'}` }),
        el('.spacer'),
        el('span.num', { text: fmt.rupiah(report.total_outstanding), style: { fontWeight: '700' } }),
      ]),
      el('.card-body', Object.entries(report.buckets).map(([key, value]) => el('.bar-row', [
        el('.name', { text: labels[key] }),
        el('div', progressBar((value / max) * 100, tones[key])),
        el('.amt', { text: fmt.rupiah(value) }),
      ]))),
    ]),
    el('.card', [
      el('.card-head', el('h2', { text: 'Rincian dokumen' })),
      report.rows.length
        ? el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: 'Dokumen' }), el('th', { text: side === 'ar' ? 'Pelanggan' : 'Vendor' }),
            el('th', { text: 'Tanggal' }), el('th', { text: 'Jatuh tempo' }),
            el('th.right', { text: 'Total' }), el('th.right', { text: 'Dibayar' }),
            el('th.right', { text: 'Sisa' }), el('th.center', { text: 'Umur' }),
          ])),
          el('tbody', report.rows.map((row) => el('tr', [
            el('td.code', { text: row.code }),
            el('td', { text: row.partner || '—' }),
            el('td', { text: fmt.date(row.document_date) }),
            el('td', { text: fmt.date(row.due_date) }),
            moneyCell(row.total), moneyCell(row.amount_paid), moneyCell(row.outstanding),
            el('td.center', badge(row.days_overdue > 0 ? `${row.days_overdue} hari` : 'Lancar', tones[row.bucket])),
          ]))),
        ]))
        : el('.card-body', el('p.muted', { text: 'Tidak ada dokumen terbuka.', style: { margin: 0 } })),
    ]),
  ]);
}

async function projectProfitability(host, controls) {
  const select = await projectSelect((value) => { state.params.project_id = value; render(host); }, state.params.project_id, true);
  controls.appendChild(select);

  if (!state.params.project_id) {
    return el('.alert.info', 'Pilih proyek untuk melihat profitabilitasnya.');
  }

  const report = await api.get(`finance/reports/project-profitability/${state.params.project_id}`);
  const maxCost = Math.max(...report.costs.map((cost) => Math.max(cost.actual, cost.budget || 0)), 1);

  // num(null) menghasilkan sel kosong, jadi kategori tanpa anggaran tidak
  // berpura-pura punya angka nol.
  controls.appendChild(csvButton(() => ({
    filename: csvFilename('profitabilitas-proyek'),
    headers: ['baris', 'realisasi', 'anggaran', 'selisih'],
    rows: [
      ...report.costs.map((cost) => [cost.label, num(cost.actual), num(cost.budget), num(cost.variance)]),
      ['Pendapatan tertagih', num(report.revenue), '', ''],
      ['Total biaya', num(report.total_cost), '', ''],
      ['Margin', num(report.margin), '', ''],
    ],
  })));

  return el('div', [
    el('.stat-row', [
      el('.stat', [el('.label', { text: 'Pendapatan tertagih' }), el('.value.sm', { text: fmt.rupiah(report.revenue) })]),
      el('.stat', [el('.label', { text: 'Realisasi biaya' }), el('.value.sm', { text: fmt.rupiah(report.total_cost) })]),
      el('.stat', [
        el('.label', { text: 'Margin' }),
        el('.value.sm', { text: fmt.rupiah(report.margin), style: { color: report.margin < 0 ? 'var(--danger)' : 'var(--success)' } }),
        report.margin_pct !== null ? el('.delta', { text: fmt.percent(report.margin_pct) }) : null,
      ]),
      report.total_budget !== null
        ? el('.stat', [el('.label', { text: 'Anggaran RAP' }), el('.value.sm', { text: fmt.rupiah(report.total_budget) })])
        : null,
    ]),
    el('.card', [
      el('.card-head', el('h2', { text: 'Realisasi vs anggaran per kategori' })),
      el('.card-body', report.costs.map((cost) => el('div', { style: { padding: '8px 0', borderBottom: '1px solid var(--border)' } }, [
        el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: '13px', marginBottom: '5px' } }, [
          el('b', { text: cost.label }),
          el('span.num', {
            text: cost.budget !== null
              ? `${fmt.rupiah(cost.actual)} / ${fmt.rupiah(cost.budget)}`
              : fmt.rupiah(cost.actual),
          }),
        ]),
        progressBar((cost.actual / maxCost) * 100, cost.variance !== null && cost.variance < 0 ? 'red' : ''),
        cost.variance !== null
          ? el('.cell-sub', {
            text: cost.variance >= 0 ? `Sisa anggaran ${fmt.rupiah(cost.variance)}` : `Melebihi anggaran ${fmt.rupiah(-cost.variance)}`,
            style: { color: cost.variance < 0 ? 'var(--danger)' : 'var(--muted)' },
          })
          : null,
      ]))),
    ]),
  ]);
}

/* ------------------------------------------------------------- controls */
function yearInput(value, onChange) {
  const input = el('input.filter-w', { type: 'number', value, min: 2000, max: 2100, 'aria-label': 'Tahun' });
  input.addEventListener('change', () => onChange(input.value));
  return input;
}

function monthInput(value, onChange) {
  const select = el('select.filter-w', { 'aria-label': 'Bulan' });
  fmt.MONTHS.forEach((label, index) => select.appendChild(el('option', { value: index + 1, text: label })));
  select.value = value;
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

function dateInput(label, value, onChange) {
  const input = el('input.filter-w', { type: 'date', value, title: label, 'aria-label': label });
  input.addEventListener('change', () => onChange(input.value));
  return input;
}

async function projectSelect(onChange, value, required = false) {
  const select = el('select.filter-w', { 'aria-label': 'Proyek' });
  select.appendChild(el('option', { value: '', text: required ? 'Pilih proyek…' : 'Semua proyek' }));
  const rows = await loadSource('projects').catch(() => []);
  optionsFor('projects', rows).forEach((option) =>
    select.appendChild(el('option', { value: option.value, text: option.label })));
  select.value = value || '';
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

/* ------------------------------------------------------------- renderer */
async function render(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [el('h1', { text: 'Laporan Keuangan' }), el('.desc', { text: 'Dihitung dari jurnal yang sudah diposting.' })]),
    el('.actions', [button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() })]),
  ]));

  const tabs = el('.tabs', TABS.map((tab) =>
    el(`button${tab.key === state.tab ? '.active' : ''}`, {
      text: tab.label,
      onclick: () => {
        if (state.tab === tab.key) return;
        state.tab = tab.key;
        state.params = {};
        render(host);
      },
    })));

  const controls = el('.filters', { style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' } });
  const body = el('div');
  host.append(tabs, controls, body);
  body.appendChild(skeletonTable(6, 6));

  const builders = {
    'trial-balance': () => trialBalance(host, controls),
    'profit-loss': () => profitLoss(host, controls),
    'balance-sheet': () => balanceSheet(host, controls),
    'cash-flow': () => cashFlowStatement(controls, state.params, () => render(host)),
    'cash-projection': () => cashProjection(controls, state.params, () => render(host)),
    'ar-aging': () => aging(host, controls, 'ar'),
    'ap-aging': () => aging(host, controls, 'ap'),
    'project-profitability': () => projectProfitability(host, controls),
  };

  try {
    const node = await builders[state.tab]();
    clear(body).appendChild(node);
  } catch (error) {
    clear(body).appendChild(errorState(error, () => render(host)));
  }
}

export async function renderReports(host) {
  if (!session.can('fin.view')) {
    clear(host);
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke laporan keuangan.'));
    return;
  }
  await render(host);
}
