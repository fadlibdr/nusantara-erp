/* Widget "Saldo bank" (P1-D) - GET finance/reports/bank-balances.
   Endpoint ini sudah agregat SQL penuh tanpa paginasi, jadi penjumlahan sisi
   klien di bawah menjumlah SELURUH rekening, bukan halaman pertama.

   Saldo negatif ditampilkan apa adanya (BCA demo jujur -232.545.000) dan
   rekening yang minus DISEBUT NAMANYA: angka gabungan yang masih positif bisa
   menyembunyikan satu rekening yang sudah merah, dan yang menentukan apakah
   cek hari ini cair adalah rekening itu, bukan jumlahnya. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedStats, statRow, stat, footLink, tileEmpty } from './kit.js';

export async function build({ reload }) {
  const rows = await safe('finance/reports/bank-balances');
  // Gagal LEBIH DULU daripada .length: sumber yang jatuh panjangnya 0, dan
  // cabang "kosong" akan menuliskan Rp 0 di atas kas yang tidak diketahui.
  if (failure(rows)) return failedStats(['Saldo bank'], reload);
  if (!rows.length) return el('div', [
      tileEmpty('Belum ada rekening bank yang terdaftar.'),
      /* Kaki kartu ikut pada keadaan kosong: pembaca yang belum punya rekening
         justru yang butuh pintu ke layar pendaftarannya (verifikasi P1-D
         putaran 2, 6 Sep 2026). */
      footLink('Buka rekening bank', () => navigate('r/finance/bank-accounts')),
    ]);

  const total = rows.reduce((sum, row) => sum + Number(row.balance || 0), 0);
  const minus = rows.filter((row) => Number(row.balance || 0) < 0);

  return el('div', [
    el('.card-body', statRow([
      stat('Saldo seluruh rekening', fmt.rupiahShort(total), {
        sub: minus.length ? `${minus.map((row) => row.name || row.code).join(', ')} negatif` : `${rows.length} rekening`,
        tone: total < 0 || minus.length ? 'down' : undefined,
      }),
    ])),
    footLink('Buka rekening bank', () => navigate('r/finance/bank-accounts')),
  ]);
}
