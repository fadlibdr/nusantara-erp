/* Rekap Tagihan Alat (P5, deviasi 3.10) — laporan billing periode PPK.

   Semua tagihan periode yang MENYENTUH rentang tanggal terpilih, per vendor,
   dengan tagihan AP-nya bila sudah ada. Laporan, bukan lembar tanda tangan:
   keputusan lane backend (dan dihormati di sini) — TIDAK ada formulir cetak
   untuk rekap ini, karena tidak ada tiga pihak yang menandatanganinya;
   rupiahnya sudah ber-maker-checker di tagihan AP masing-masing.

   KEJUJURAN KOLOM: tagihan periode yang belum dibuatkan tagihan AP tampil
   '—' pada kolom AP — bergaris, bukan "draft" karangan; sebuah tagihan AP
   yang dibatalkan tidak dihitung hidup (WorkOrderBillingService::liveApBill,
   sikap yang sama dengan anti tagih-ganda empat lapisnya). */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor, labelFor, preload } from '../lookup.js';
import { navigate } from '../router.js';

function monthBound(which) {
  const now = new Date();
  const d = which === 'start'
    ? new Date(now.getFullYear(), now.getMonth(), 1)
    : new Date(now.getFullYear(), now.getMonth() + 1, 0);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const state = {
  from: monthBound('start'),
  to: monthBound('end'),
  vendorId: '',
  projectId: '',
};

export async function renderRekapAlat(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Rekap Tagihan Alat' }),
      el('.desc', { text: 'Tagihan periode PPK yang menyentuh rentang tanggal — per vendor, dengan tagihan AP-nya bila sudah ada.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderRekapAlat(host) })]),
  ]));

  const dateInput = (label, key) => {
    const input = el('input.filter-w', { type: 'date', value: state[key], title: label, 'aria-label': label });
    input.addEventListener('change', () => { state[key] = input.value; });
    return input;
  };

  const sourceSelect = async (source, label, key, allLabel) => {
    const select = el('select.filter-w', { 'aria-label': label });
    select.appendChild(el('option', { value: '', text: allLabel }));
    const rows = await loadSource(source).catch(() => []);
    optionsFor(source, rows).forEach((option) =>
      select.appendChild(el('option', { value: option.value, text: option.label })));
    select.value = state[key] || '';
    select.addEventListener('change', () => { state[key] = select.value; });
    return select;
  };

  const body = el('div');
  const tampilkan = button('Tampilkan', { variant: 'primary', size: 'sm', onClick: () => load(body) });
  host.appendChild(el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  }, [
    dateInput('Dari tanggal', 'from'),
    dateInput('Sampai tanggal', 'to'),
    await sourceSelect('vendors', 'Vendor', 'vendorId', 'Semua vendor'),
    await sourceSelect('projects', 'Proyek', 'projectId', 'Semua proyek'),
    tampilkan,
  ]));
  host.appendChild(body);

  await load(body);
}

async function load(body) {
  clear(body);
  body.appendChild(skeletonTable(6, 8));

  let report;
  try {
    [report] = await Promise.all([
      api.get('procurement/work-orders/reports/billing-recap', {
        from: state.from,
        to: state.to,
        vendor_id: state.vendorId || undefined,
        project_id: state.projectId || undefined,
      }),
      preload(['projects']),
    ]);
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => load(body)));
  }

  clear(body);
  const rows = report.rows || [];
  const byVendor = report.summary_by_vendor || [];
  const totals = report.totals || {};

  if (!rows.length) {
    body.appendChild(el('.alert.info',
      'Tidak ada tagihan periode PPK yang menyentuh rentang ini. '
      + 'Rekap hanya membaca tagihan periode yang sudah dibuat dari layar Tagihan Periode PPK — '
      + 'periode sewa yang belum ditagih tidak dikarang di sini.'));
    return;
  }

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Tagihan periode' }),
      el('.value.sm', { text: String(rows.length) }),
      el('.delta', { text: `${fmt.date(report.period?.from)} – ${fmt.date(report.period?.to)}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Total nilai (DPP)' }),
      el('.value.sm', { text: fmt.rupiah(totals.total_amount) }),
      el('.delta', { text: 'kuantitas turunan register/kalender' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Belum ada tagihan AP' }),
      el('.value.sm', { text: String(rows.filter((row) => !row.ap_bill_code).length) }),
      el('.delta', { text: 'kolom AP bergaris = belum ditagihkan' }),
    ]),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Tagihan per periode' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Tagihan' }),
        el('th', { text: 'PPK' }),
        el('th', { text: 'Vendor' }),
        el('th', { text: 'Proyek' }),
        el('th', { text: 'Periode' }),
        el('th.right', { text: 'Nilai (DPP)' }),
        el('th', { text: 'Tagihan AP' }),
      ])),
      el('tbody', rows.map((row) => {
        const node = el('tr', { style: { cursor: 'pointer' } }, [
          el('td.mono', { text: row.billing_code || '—' }),
          el('td.mono', { text: row.work_order_code || '—' }),
          el('td', { text: row.vendor_name || '—' }),
          el('td', { text: row.project_id ? (labelFor('projects', row.project_id) || `#${row.project_id}`) : '—' }),
          el('td', { text: `${fmt.date(row.period_start)} – ${fmt.date(row.period_end)}` }),
          el('td.right.num', { text: fmt.rupiah(row.total_amount, { decimals: 2 }) }),
          row.ap_bill_code
            ? el('td', [
              el('span.mono', { text: row.ap_bill_code }),
              el('.cell-sub', badge(row.ap_bill_status || '—', fmt.statusTone(row.ap_bill_status))),
            ])
            : el('td.muted', { text: '—' }),
        ]);
        node.addEventListener('click', () => navigate(`d/procurement/work-order-billings/${row.billing_id}`));
        return node;
      })),
    ])),
  ]));

  if (byVendor.length) {
    body.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Ringkasan per vendor' })),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Vendor' }),
          el('th.right', { text: 'Total nilai (DPP)' }),
        ])),
        el('tbody', byVendor.map((row) => el('tr', [
          el('td', { text: row.vendor_name || '—' }),
          el('td.right.num', { text: fmt.rupiah(row.total_amount, { decimals: 2 }) }),
        ]))),
      ])),
    ]));
  }
}
