/* Widget "Perlu dipesan ulang" (P1-D, ambangnya diubah F-6) —
   GET inventory/stock/low-stock. Endpoint ini sudah agregat penuh per gudang x
   item (item nonaktif dan pasangan ber-ambang 0 sudah dibuang di server), jadi
   panjang daftarnya adalah hitungan yang sebenarnya - bukan panjang sebuah
   halaman.

   AMBANGNYA BUKAN LAGI min_stock (F-6). Ia `reorder_point` yang sudah dihitung
   server: aturan reorder gudang bila ada yang aktif untuk pasangan itu, selain
   itu stok minimum item. Kartu ini membaca angka yang MENANG dan menyebut
   sumbernya - sebuah "min 100" di sini untuk baris yang sebenarnya diperintah
   aturan gudang bernilai 20 adalah kartu yang bertengkar dengan layar Stok
   tentang barang yang sama. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, miniTable, footLink } from './kit.js';

export async function build({ reload }) {
  const rows = await safe('inventory/stock/low-stock');
  // Judul kartu tidak pernah memuat "(0)" pada sumber yang gagal: itu janji
  // bahwa tidak ada item di bawah ambangnya, dan janji itu belum bisa dibuat.
  if (failure(rows)) return failedBody(failure(rows), reload);

  /* Yang paling jauh di bawah ambangnya lebih dulu - urutan gudang tidak
     memberi tahu siapa pun mana yang harus dipesan hari ini. shortage_qty
     dihitung server dari ambang yang MENANG; menghitungnya lagi di sini dari
     (qty - min_stock) akan mengurutkan daftar menurut angka yang tidak dipakai
     satu pun baris yang diperintah aturan. */
  const worst = [...rows]
    .sort((a, b) => Number(b.shortage_qty) - Number(a.shortage_qty))
    .slice(0, 6);

  return el('div', [
    miniTable(
      [
        { label: 'Item', render: (row) => el('span', [el('span.cell-main', { text: row.item_name }), el('span.cell-sub.mono', { text: row.item_code })]) },
        { label: 'Gudang', render: (row) => el('span', { text: row.warehouse_name }) },
        {
          label: 'Stok / ambang',
          align: 'right',
          render: (row) => el('span.num', [
            el('span', { text: fmt.qty(row.qty, row.unit) }),
            el('span.cell-sub', {
              text: row.threshold_source === 'rule'
                ? `ambang ${fmt.qty(row.reorder_point)} · aturan gudang`
                : `ambang ${fmt.qty(row.reorder_point)} · stok min. item`,
            }),
          ]),
        },
      ],
      worst,
      () => navigate('stock'),
      { empty: { kind: 'done', message: 'Tidak ada pasangan gudang × item di bawah ambangnya.' } },
    ),
    /* SELALU ada kakinya. Bentuk `N > M ? footLink(...) : null` membuang
       satu-satunya pintu kartu ini persis ketika daftarnya PENDEK — yaitu
       keadaan yang paling sering, dan keadaan yang paling perlu diperiksa
       (verifikasi kedua P1-D: 10 dari 19 kartu tanpa .card-foot). */
    footLink(rows.length > worst.length ? `Lihat semua (${rows.length})` : 'Buka Stok', () => navigate('stock')),
  ]);
}
