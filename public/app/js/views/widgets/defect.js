/* Widget "Temuan lapangan (defect)" (P1-D) - GET projects/defects/summary.
   Server menghitung SELURUH temuan (bukan halaman), jadi keempat angka di
   bawah adalah angka yang sama dengan layar Defect - kesetaraan itu penting
   karena "menahan BAST II" adalah alasan sebuah serah terima batal, dan dua
   layar yang berbeda angkanya membuat orang mengabaikan keduanya. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, statRow, stat, footLink } from './kit.js';

export async function build({ reload }) {
  const summary = await safe('projects/defects/summary');
  if (failure(summary)) return failedBody(failure(summary), reload);

  const blocking = Number(summary.open_blocking_count || 0);
  const overdue = Number(summary.overdue_count || 0);
  const oldest = summary.oldest_open_days;

  return el('div', [
    el('.card-body', statRow([
      stat('Temuan terbuka', String(summary.open_count ?? '—'), { sub: 'termasuk yang menunggu verifikasi' }),
      stat('Menahan BAST II', String(blocking), {
        sub: blocking > 0 ? 'kritis/mayor yang belum diterima pelanggan' : 'tidak ada yang menahan serah terima',
        tone: blocking > 0 ? 'down' : 'up',
      }),
      stat('Lewat target perbaikan', String(overdue), {
        sub: overdue > 0 ? 'target perbaikannya sudah lewat' : 'semuanya di dalam target',
        tone: overdue > 0 ? 'down' : undefined,
      }),
      stat('Terbuka terlama', oldest === null || oldest === undefined ? '—' : `${oldest} hari`, {
        sub: summary.oldest_open_code || 'tidak ada temuan terbuka',
      }),
    ])),
    el('p.cell-sub', {
      // Basis yang tidak tertulis akan dibaca sebagai jawaban atas pertanyaan
      // yang kebetulan ada di layar.
      text: `Dihitung server dari SELURUH temuan per ${fmt.date(summary.as_of)} (${summary.total} temuan tercatat).`,
      style: { fontSize: '11.5px', margin: '0 16px 12px' },
    }),
    footLink('Buka register temuan', () => navigate('defects')),
  ]);
}
