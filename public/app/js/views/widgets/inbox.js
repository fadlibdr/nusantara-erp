/* Widget "Menunggu persetujuan Anda" (P1-D) — GET core/inbox.
   SATU permintaan untuk 28 jenis dokumen (InboxController), bukan satu per
   jenis: sampai 2 Sep 2026 dasbor menanyakan 11 jenis dan karena itu tidak
   pernah mencakup 17 lainnya — pengajuan cuti yang menunggu tak terlihat oleh
   direktur ber-hr.approve. */

import { el, icon, button } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safeList, failure, rowsOf, tileEmpty, failedBody, miniTable, footLink } from './kit.js';

const PREVIEW = 5;

export async function build({ reload }) {
  const payload = await safeList('core/inbox');
  if (failure(payload)) return failedBody(failure(payload), reload);

  const rows = rowsOf(payload);
  const meta = payload.meta || {};
  /* meta.failed = registri yang jatuh SENDIRI di server. Daftarnya belum
     lengkap, dan itu harus dikatakan — "tidak ada yang menunggu" atas kotak
     masuk yang separuh gagal adalah janji yang tidak bisa ditepati. */
  const failed = meta.failed || [];
  const total = meta.total ?? rows.length;

  return el('div', [
    rows.length
      ? miniTable(
        [
          { label: 'Dokumen', render: (r) => el('span', [el('span.cell-main.mono', { text: r.code }), el('span.cell-sub', { text: r.label })]) },
          {
            label: 'Keterangan',
            // Satu baris, dipotong: uraian PR yang dibiarkan membungkus memakan
            // 8 baris per dokumen. Pengaju dan umur antrean di baris kedua.
            render: (r) => el('span', [
              el('span.cell-main', { text: r.title || '—', title: r.title || '', style: { display: 'block', maxWidth: '250px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontWeight: '400' } }),
              el('span.cell-sub', {
                text: [
                  r.submitted_by ? `oleh ${r.submitted_by}` : null,
                  r.days_waiting === null || r.days_waiting === undefined
                    ? null
                    : (r.days_waiting >= 7 ? `menunggu ${r.days_waiting} hari` : `${r.days_waiting} hari`),
                ].filter(Boolean).join(' · ') || '—',
              }),
            ]),
          },
          { label: 'Nilai', align: 'right', render: (r) => el('span.num', { text: r.amount === null || r.amount === undefined ? '—' : fmt.rupiah(r.amount) }) },
        ],
        rows.slice(0, PREVIEW),
        (r) => navigate(String(r.link).replace(/^#\//, '')),
      )
      /* "Tidak ada yang menunggu" adalah pernyataan tentang dunia; bila sebagian
         registri gagal, yang bisa dikatakan hanya bahwa tidak ada yang dapat
         DITAMPILKAN — dan gambarnya pun gambar galat, bukan centang. */
      : (failed.length
        ? tileEmpty('Tidak ada dokumen yang dapat ditampilkan.', 'error')
        : tileEmpty('Tidak ada dokumen yang menunggu persetujuan.', 'done')),
    failed.length
      ? el('.card-body', { style: { borderTop: '1px solid var(--border)', padding: '10px 16px' } },
        el('.alert.warn', { style: { margin: 0 } }, [
          icon('warn', 16),
          el('div', { text: `Gagal dimuat: ${failed.join(', ')}. Daftar ini belum lengkap.` }),
        ]))
      : null,
    total > PREVIEW ? footLink(`Lihat semua (${total})`, () => navigate('tugas')) : null,
  ]);
}
