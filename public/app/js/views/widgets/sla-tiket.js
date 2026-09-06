/* Widget "Tiket lewat SLA" (P1-D) - GET servicedesk/tickets-sla-breaches.
   Daftarnya sudah disaring server ke tiket yang BELUM selesai dan SUDAH lewat
   batas waktu, jadi widget ini tidak menyaring apa pun sendiri; meta.total
   adalah hitungan server, bukan panjang halaman yang diminta. */

import { el, badge } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safeList, failure, rowsOf, totalOf, failedBody, statRow, stat, miniTable, footLink } from './kit.js';

/** Jam keterlambatan terhadap jam peramban; null bila batasnya tidak ada. */
function overdueBy(due) {
  if (!due) return null;
  return Math.max(0, Math.round((Date.now() - new Date(due).getTime()) / 3600000));
}

function overdueLabel(hours) {
  if (hours === null) return '—';
  if (hours < 48) return `${hours} jam`;
  return `${Math.round(hours / 24)} hari`;
}

export async function build({ reload }) {
  const payload = await safeList('servicedesk/tickets-sla-breaches', { per_page: 5 });
  if (failure(payload)) return failedBody(failure(payload), reload);

  const tickets = rowsOf(payload);
  const total = totalOf(payload);
  const worst = tickets.length
    ? Math.max(...tickets.map((ticket) => overdueBy(ticket.resolution_due_at) ?? 0))
    : null;

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, statRow([
      stat('Tiket lewat SLA', total === null ? '—' : String(total), {
        sub: total ? 'sudah melewati janji ke pelanggan' : 'tidak ada yang terlampaui',
        tone: total ? 'down' : 'up',
      }),
      stat('Terlama', overdueLabel(worst), { sub: 'di antara yang ditampilkan' }),
    ])),
    miniTable(
      [
        { label: 'Tiket', render: (row) => el('span', [el('span.cell-main.mono', { text: row.code }), el('span.cell-sub', { text: row.title || '—' })]) },
        { label: 'Prioritas', render: (row) => badge(row.priority_label || row.priority, fmt.statusTone(row.priority)) },
        {
          label: 'Terlambat',
          align: 'right',
          render: (row) => el('span.num', { text: overdueLabel(overdueBy(row.resolution_due_at)), style: { color: 'var(--danger)' } }),
        },
      ],
      tickets,
      (row) => navigate(`d/servicedesk/tickets/${row.id}`),
      { empty: { kind: 'done', message: 'Tidak ada tiket yang melewati SLA.' } },
    ),
    /* SELALU ada kakinya. Bentuk `N > M ? footLink(...) : null` membuang
       satu-satunya pintu kartu ini persis ketika daftarnya PENDEK — yaitu
       keadaan yang paling sering, dan keadaan yang paling perlu diperiksa
       (verifikasi kedua P1-D: 10 dari 19 kartu tanpa .card-foot). */
    footLink(total > tickets.length ? `Lihat semua (${total})` : 'Buka Pelanggaran SLA', () => navigate('sla-breaches')),
  ]);
}
