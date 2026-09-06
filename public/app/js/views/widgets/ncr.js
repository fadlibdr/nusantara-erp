/* Widget "NCR terbuka" (P1-D) - GET quality/ncr.

   DUA PERMINTAAN, dengan sengaja. "NCR terbuka" berarti open ATAU
   under_correction - cermin NcrStatus::isOpen(), angka yang sama yang dipimpin
   ubin launcher modul Mutu (ModuleCounts entri 'qc'). Endpoint daftar hanya
   menerima SATU status per permintaan, jadi pilihannya: menanyakan satu status
   dan menyebut angka yang berbeda dari launcher, atau menyaring sisi klien atas
   halaman pertama (persis Temuan 79). Keduanya berbohong; dua permintaan
   per_page kecil tidak. meta.total yang dijumlah adalah hitungan SERVER, bukan
   panjang halaman. */

import { el } from '../../ui.js';
import { navigate } from '../../router.js';
import { safeList, failure, rowsOf, totalOf, failedStats, statRow, stat, footLink, miniTable } from './kit.js';

const OPEN_STATUSES = ['open', 'under_correction'];

export async function build({ reload }) {
  const payloads = await Promise.all(
    OPEN_STATUSES.map((status) => safeList('quality/ncr', { status, per_page: 3 })),
  );

  // Satu dari dua status gagal = angkanya TIDAK diketahui. Menjumlah yang
  // berhasil saja akan menuliskan angka yang terlalu kecil dengan penuh
  // percaya diri, dan "NCR terbuka 1" pada mutu yang sebenarnya punya 6 adalah
  // kabar baik palsu.
  const broken = payloads.find((payload) => failure(payload));
  if (broken) return failedStats(['NCR terbuka'], reload);

  const total = payloads.reduce((sum, payload) => sum + (totalOf(payload) || 0), 0);
  const rows = payloads.flatMap((payload) => rowsOf(payload)).slice(0, 4);

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, statRow([
      stat('NCR terbuka', String(total), {
        sub: 'terbuka atau sedang dikoreksi',
        tone: total > 0 ? 'down' : 'up',
      }),
    ])),
    miniTable(
      [
        /* NcrResource tidak punya `title` — yang ada `description`, dipotong
           satu baris seperti kolom Keterangan kotak masuk. */
        { label: 'NCR', render: (row) => el('span', [
          el('span.cell-main.mono', { text: row.code }),
          el('span.cell-sub', { text: row.description || '—', title: row.description || '', style: { display: 'block', maxWidth: '220px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }),
        ]) },
        { label: 'Status', render: (row) => el('span.cell-sub', { text: row.status_label || row.status }) },
      ],
      rows,
      (row) => navigate(`d/quality/ncr/${row.id}`),
      { empty: { kind: 'done', message: 'Tidak ada NCR yang terbuka.' } },
    ),
    /* SELALU ada kakinya. Bentuk `N > M ? footLink(...) : null` membuang
       satu-satunya pintu kartu ini persis ketika daftarnya PENDEK — yaitu
       keadaan yang paling sering, dan keadaan yang paling perlu diperiksa
       (verifikasi kedua P1-D: 10 dari 19 kartu tanpa .card-foot). */
    footLink(total > rows.length ? `Lihat semua (${total})` : 'Buka NCR', () => navigate('r/quality/ncr')),
  ]);
}
