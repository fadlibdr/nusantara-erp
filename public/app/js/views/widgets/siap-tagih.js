/* Widget "Termin siap ditagih" (P1-D) - GET crm/contract-termins/billing-ready.
   Pekerjaan yang sudah BERHAK ditagih dan belum ditagih. Di data nyata angka
   ini pernah diam empat bulan pada Rp 14,55 miliar karena tidak ada satu pun
   layar yang menyebutkannya - dan ubinnya kemudian LENYAP saat SQLite berebut
   kunci, yang membuat pembacanya menyimpulkan tidak ada yang bisa ditagih.
   Itulah widget yang paling tidak boleh diam saat sumbernya gagal. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safeList, failure, rowsOf, failedStats, statRow, stat, footLink, tileEmpty } from './kit.js';

export async function build({ reload }) {
  // api.list, bukan api.get: `meta.total_amount` adalah jumlah SERVER, dan
  // menjumlah ulang di klien hanya menambah satu tempat yang bisa berselisih.
  const payload = await safeList('crm/contract-termins/billing-ready');
  if (failure(payload)) return failedStats(['Termin siap ditagih'], reload);

  const rows = rowsOf(payload);
  if (!rows.length) return tileEmpty('Tidak ada termin yang siap ditagih.', 'done');

  const meta = payload.meta || {};
  const total = Number(meta.total_amount ?? rows.reduce((sum, row) => sum + Number(row.amount || 0), 0));

  return el('div', [
    el('.card-body', statRow([
      // Umur tunggu, bukan cuma jumlahnya: "3 termin" mudah diabaikan,
      // "terlama 126 hari" tidak. Server mengurutkan terlama lebih dulu.
      stat('Termin siap ditagih', fmt.rupiahShort(total), {
        sub: `${rows.length} termin · terlama ${rows[0].days_waiting} hari`,
        tone: 'down',
      }),
    ])),
    footLink('Buka daftar siap tagih', () => navigate('siap-tagih')),
  ]);
}
