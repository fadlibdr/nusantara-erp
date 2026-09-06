/* Widget "Tenggat menipis & lewat" (P1-D) — GET core/deadlines.
   Rutenya tidak bergerbang izin dengan sengaja (aturan yang sama dengan
   core/calendar): server menyaring per izin `.view` pemanggil, jadi "kosong"
   dan "tidak boleh melihat apa pun" tampil sama — dan keduanya memang berarti
   hal yang sama bagi pembacanya. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safeList, failure, rowsOf, statRow, stat, tileEmpty, failedBody, miniTable, footLink } from './kit.js';

const TIER_LABEL = { lewat: 'lewat', menipis: 'menipis', tanpa_tanggal: 'tanpa tanggal' };

export async function build({ reload }) {
  const payload = await safeList('core/deadlines');
  if (failure(payload)) return failedBody(failure(payload), reload);

  const findings = rowsOf(payload);
  const meta = payload.meta || {};

  if (!findings.length) {
    return el('div', [
      tileEmpty('Tidak ada tenggat yang menipis atau lewat pada modul yang boleh Anda lihat.', 'done'),
      footLink('Buka semua tenggat', () => navigate('tenggat')),
    ]);
  }

  const totalOfTier = (tier) => findings.filter((f) => f.tier === tier).reduce((sum, f) => sum + f.count, 0);
  // "tanpa tanggal" ikut kolom kiri: sebuah dokumen yang tenggatnya tidak
  // pernah diisi tidak lebih aman daripada yang sudah lewat, ia hanya lebih
  // sulit dilihat.
  const lewat = totalOfTier('lewat') + totalOfTier('tanpa_tanggal');
  const menipis = totalOfTier('menipis');

  /* Kelompok terberat lebih dulu, lalu terbanyak: yang muncul di lima baris
     teratas widget harus yang paling menuntut tindakan, bukan yang kebetulan
     lebih dulu didaftarkan registri pengawas. */
  const rank = { lewat: 0, tanpa_tanggal: 1, menipis: 2 };
  const top = [...findings]
    .sort((a, b) => (rank[a.tier] ?? 9) - (rank[b.tier] ?? 9) || b.count - a.count)
    .slice(0, 5);

  return el('div', [
    statRow([
      stat('Sudah lewat / tanpa tanggal', String(lewat), { sub: 'butuh tindakan sekarang', tone: lewat ? 'down' : undefined }),
      stat('Menipis', String(menipis), { sub: 'masih di dalam jendela peringatan' }),
      stat('Kelompok pengawasan', String(findings.length), { sub: `dipindai per ${fmt.date(meta.today)}` }),
    ]),
    miniTable(
      [
        { label: 'Kelompok', render: (f) => el('span', [el('span.cell-main', { text: f.title }), el('span.cell-sub', { text: TIER_LABEL[f.tier] || f.tier })]) },
        { label: 'Baris', align: 'right', render: (f) => el('span.num', { text: String(f.count) }) },
      ],
      top,
      () => navigate('tenggat'),
    ),
    /* SELALU ada kakinya, bukan hanya saat daftarnya terpotong: sampai
       verifikasi kedua P1-D kartu ini kehilangan satu-satunya pintunya persis
       ketika kelompoknya lima atau kurang — yaitu keadaan yang paling sering. */
    footLink(
      findings.length > top.length ? `Lihat semua (${findings.length} kelompok)` : 'Buka semua tenggat',
      () => navigate('tenggat'),
    ),
  ]);
}
