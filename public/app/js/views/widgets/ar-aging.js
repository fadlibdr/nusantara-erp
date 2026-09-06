/* Widget "Umur piutang" (P1-D) - GET finance/reports/ar-aging.
   Keranjang umur dijumlah DI SERVER atas seluruh dokumen terbuka; widget ini
   tidak pernah menjumlah ulang baris halaman pertama (Temuan 79). */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, barRows, footLink, tileEmpty } from './kit.js';
import { AGING_BUCKETS, agingEntries } from './aging.js';

export async function build({ reload }) {
  const report = await safe('finance/reports/ar-aging');
  if (failure(report)) return failedBody(failure(report), reload);

  const buckets = (report && report.buckets) || {};
  /* Keranjang selalu lima, juga ketika semuanya nol — jadi `Object.keys` tidak
     bisa membedakan "tidak ada dokumen terbuka" dari "ada, semuanya belum
     jatuh tempo". Yang membedakan adalah baris rinciannya, dan server
     mengirimkannya utuh (laporan ini tidak berhalaman). Lima batang nol di
     atas angka Rp 0 bukan kebohongan, hanya kebisingan; kalimatnya lebih
     berguna. */
  if (!((report && report.rows) || []).length || !Object.keys(buckets).length) {
    return tileEmpty('Tidak ada dokumen piutang terbuka.', 'done');
  }

  const total = Number(report.total_outstanding || 0);
  const lewat = AGING_BUCKETS
    .filter((bucket) => bucket.key !== 'current')
    .reduce((sum, bucket) => sum + Number(buckets[bucket.key] || 0), 0);

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, el('.stat-row', [
      el('.stat', [
        el('.label', { text: 'Sisa piutang' }),
        el('.value.sm', { text: fmt.rupiah(total) }),
        el('.delta', { text: 'seluruh dokumen terbuka' }),
      ]),
      el('.stat', [
        el('.label', { text: 'Sudah jatuh tempo' }),
        el('.value.sm', { text: fmt.rupiah(lewat) }),
        el(`.delta${lewat > 0 ? '.down' : ''}`, { text: total > 0 ? `${Math.round((lewat / total) * 100)}% dari sisa` : 'tidak ada' }),
      ]),
    ])),
    barRows(agingEntries(buckets), { format: (value) => fmt.rupiahShort(value) }),
    footLink('Buka umur piutang', () => navigate('reports?tab=ar-aging')),
  ]);
}
