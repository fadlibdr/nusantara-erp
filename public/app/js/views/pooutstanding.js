/* Baris PO terbuka — pemantauan pengiriman pengadaan.

   Sebelum layar ini, expected_date PO berhenti sebagai tampilan dan qty vs
   qty_received hanya terlihat per dokumen: kiriman terlambat baru ketahuan
   saat site kehabisan material, dan ekspeditor membuka PO satu per satu untuk
   tahu apa yang belum datang. Endpoint procurement/reports/outstanding
   mengembalikan baris yang belum terkirim penuh pada PO approved, yang lewat
   jadwal diurutkan paling atas. */

import { api } from '../api.js';
import { el, clear, button, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { preload, labelFor } from '../lookup.js';
import { navigate } from '../router.js';

export async function renderPoOutstanding(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Baris PO Terbuka' }),
      el('.desc', { text: 'Barang yang sudah dipesan (PO disetujui) tetapi belum diterima penuh di gudang — yang lewat batas kirim tampil paling atas.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderPoOutstanding(host) })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(6, 8));

  let report;
  try {
    [report] = await Promise.all([api.get('procurement/reports/outstanding'), preload(['projects'])]);
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderPoOutstanding(host)));
  }

  clear(body);

  const summary = report.summary || {};
  const rows = report.rows || [];

  if (!rows.length) {
    body.appendChild(el('.alert.info', 'Tidak ada baris PO yang terbuka. '
      + 'Daftar ini hanya memuat baris PO disetujui yang belum diterima penuh; '
      + 'PO draf dan PO yang sudah ditutup tidak dihitung.'));
    return;
  }

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Baris terbuka' }),
      el('.value.sm', { text: String(summary.total_lines ?? rows.length) }),
      el('.delta', { text: 'belum diterima penuh' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Lewat batas kirim' }),
      el('.value.sm', { text: String(summary.overdue_lines ?? 0) }),
      (summary.overdue_lines ?? 0) > 0 ? el('.delta.down', { text: 'kejar vendornya hari ini' }) : null,
    ]),
    el('.stat', [
      el('.label', { text: 'Nilai belum diterima' }),
      el('.value.sm', { text: fmt.rupiah(summary.total_outstanding_value) }),
      el('.delta', { text: 'komitmen yang belum jadi stok' }),
    ]),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Baris belum terkirim penuh' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'PO' }),
        el('th', { text: 'Vendor' }),
        el('th', { text: 'Uraian barang' }),
        el('th.right', { text: 'Diterima / dipesan' }),
        el('th.right', { text: 'Sisa' }),
        el('th.right', { text: 'Nilai sisa' }),
        el('th', { text: 'Batas kirim' }),
        el('th.right', { text: 'Telat' }),
      ])),
      el('tbody', rows.map((row) => {
        const projectName = row.project_id ? labelFor('projects', row.project_id) : null;
        const node = el('tr', { style: { cursor: 'pointer' } }, [
          el('td.mono', { text: row.po_code }),
          el('td', { text: row.vendor_name || '—' }),
          el('td', [
            el('div', { text: row.description || '—' }),
            projectName ? el('.cell-sub', { text: projectName }) : null,
          ]),
          el('td.right.num', { text: `${fmt.qty(row.qty_received)} / ${fmt.qty(row.qty, row.unit)}` }),
          el('td.right.num', { text: fmt.qty(row.outstanding_qty, row.unit) }),
          el('td.right.num', { text: fmt.rupiah(row.outstanding_value) }),
          el('td', { text: row.expected_date ? fmt.date(row.expected_date) : '—' }),
          el('td.right.num', row.is_overdue
            ? { text: `${row.overdue_days} hari`, style: { color: 'var(--danger)', fontWeight: '600' } }
            : { text: '—' }),
        ]);

        node.addEventListener('click', () => navigate(`d/procurement/purchase-orders/${row.po_id}`));
        return node;
      })),
    ])),
  ]));
}
