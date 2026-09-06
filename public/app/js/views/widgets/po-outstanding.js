/* Widget "PO belum diterima penuh" (P1-D) - GET procurement/reports/outstanding.
   Ringkasannya dijumlah server atas SELURUH baris PO disetujui yang belum
   lengkap; PO draf dan PO yang sudah ditutup tidak dihitung - kalimat itu ikut
   ditulis di widget supaya angkanya tidak dikira "semua PO". */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, statRow, stat, miniTable, footLink } from './kit.js';

export async function build({ reload }) {
  const report = await safe('procurement/reports/outstanding');
  if (failure(report)) return failedBody(failure(report), reload);

  const summary = (report && report.summary) || {};
  const rows = (report && report.rows) || [];
  const overdue = Number(summary.overdue_lines ?? 0);

  // Yang paling lama lewat batas kirim lebih dulu; baris tanpa batas kirim
  // turun ke bawah (tidak ada yang bisa dikejar dari tanggal yang tidak ada).
  const late = [...rows]
    .filter((row) => Number(row.overdue_days || 0) > 0)
    .sort((a, b) => Number(b.overdue_days || 0) - Number(a.overdue_days || 0))
    .slice(0, 5);

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, statRow([
      stat('Baris terbuka', String(summary.total_lines ?? rows.length), { sub: 'belum diterima penuh' }),
      stat('Lewat batas kirim', String(overdue), {
        sub: overdue > 0 ? 'kejar vendornya hari ini' : 'semuanya di dalam janji kirim',
        tone: overdue > 0 ? 'down' : 'up',
      }),
      stat('Nilai belum diterima', fmt.rupiahShort(summary.total_outstanding_value), { sub: 'komitmen yang belum jadi stok' }),
    ])),
    miniTable(
      [
        { label: 'PO', render: (row) => el('span', [el('span.cell-main.mono', { text: row.po_code }), el('span.cell-sub', { text: row.vendor_name || '—' })]) },
        { label: 'Barang', render: (row) => el('span.cell-sub', { text: row.description || '—' }) },
        { label: 'Telat', align: 'right', render: (row) => el('span.num', { text: `${row.overdue_days} hari`, style: { color: 'var(--danger)' } }) },
      ],
      late,
      () => navigate('po-outstanding'),
      { empty: { kind: 'done', message: 'Tidak ada baris PO yang lewat batas kirim.' } },
    ),
    footLink('Buka PO outstanding', () => navigate('po-outstanding')),
  ]);
}
