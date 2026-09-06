/* Widget "Termin siap ditagih" (P1-D) - GET crm/contract-termins/billing-ready.
   Pekerjaan yang sudah BERHAK ditagih dan belum ditagih. Di data nyata angka
   ini pernah diam empat bulan pada Rp 14,55 miliar karena tidak ada satu pun
   layar yang menyebutkannya - dan ubinnya kemudian LENYAP saat SQLite berebut
   kunci, yang membuat pembacanya menyimpulkan tidak ada yang bisa ditagih.
   Itulah widget yang paling tidak boleh diam saat sumbernya gagal. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedStat, statRow, stat, footLink, tileEmpty } from './kit.js';

export async function build({ reload }) {
  const rows = await safe('crm/contract-termins/billing-ready');
  if (failure(rows)) return el('.card-body', statRow([failedStat('Termin siap ditagih')]));
  if (!rows.length) return tileEmpty('Tidak ada termin yang siap ditagih.', 'done');

  const total = rows.reduce((sum, row) => sum + Number(row.amount || 0), 0);

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
