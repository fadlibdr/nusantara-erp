/* Widget "Stok di bawah minimum" (P1-D) - GET inventory/stock/low-stock.
   Endpoint ini sudah agregat penuh per gudang x item (item nonaktif dan item
   ber-min_stock 0 sudah dibuang di server), jadi panjang daftarnya adalah
   hitungan yang sebenarnya - bukan panjang sebuah halaman. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, miniTable, footLink } from './kit.js';

export async function build({ reload }) {
  const rows = await safe('inventory/stock/low-stock');
  // Judul kartu tidak pernah memuat "(0)" pada sumber yang gagal: itu janji
  // bahwa tidak ada item di bawah minimum, dan janji itu belum bisa dibuat.
  if (failure(rows)) return failedBody(failure(rows), reload);

  // Yang paling jauh di bawah minimumnya lebih dulu - urutan gudang tidak
  // memberi tahu siapa pun mana yang harus dipesan hari ini.
  const worst = [...rows]
    .sort((a, b) => (Number(a.qty) - Number(a.min_stock)) - (Number(b.qty) - Number(b.min_stock)))
    .slice(0, 6);

  return el('div', [
    miniTable(
      [
        { label: 'Item', render: (row) => el('span', [el('span.cell-main', { text: row.item_name }), el('span.cell-sub.mono', { text: row.item_code })]) },
        { label: 'Gudang', render: (row) => el('span', { text: row.warehouse_name }) },
        { label: 'Stok / min.', align: 'right', render: (row) => el('span.num', [el('span', { text: fmt.qty(row.qty, row.unit) }), el('span.cell-sub', { text: `min ${fmt.qty(row.min_stock)}` })]) },
      ],
      worst,
      () => navigate('stock'),
      { empty: { kind: 'done', message: 'Tidak ada item di bawah stok minimum.' } },
    ),
    /* SELALU ada kakinya. Bentuk `N > M ? footLink(...) : null` membuang
       satu-satunya pintu kartu ini persis ketika daftarnya PENDEK — yaitu
       keadaan yang paling sering, dan keadaan yang paling perlu diperiksa
       (verifikasi kedua P1-D: 10 dari 19 kartu tanpa .card-foot). */
    footLink(rows.length > worst.length ? `Lihat semua (${rows.length})` : 'Buka Stok', () => navigate('stock')),
  ]);
}
