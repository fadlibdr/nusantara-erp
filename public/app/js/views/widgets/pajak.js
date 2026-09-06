/* Widget "Kewajiban pajak" (P1-D) - GET finance/tax-obligations.
   4 jenis x 12 masa = 48 baris per tahun; satu halaman cukup selamanya
   (per_page 60, alasan yang sama dengan layar Kalender Pajak).

   ATURAN "LEWAT TENGGAT" DISALIN PERSIS dari views/kalenderpajak.js, termasuk
   pemakaian tanggal PERAMBAN sebagai pembanding. Endpoint ini tidak mengirim
   as_of, dan dua layar yang berdebat tentang berapa masa yang sudah lewat
   lebih buruk daripada satu perbandingan yang sedikit longgar - bila kelak
   server mengirim as_of, KEDUANYA berpindah bersama. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, statRow, stat, miniTable, footLink, tileEmpty } from './kit.js';

export async function build({ reload }) {
  const year = new Date().getFullYear();
  // api.get membuka amplop `data`, jadi yang kembali sudah berupa baris.
  const rows = await safe('finance/tax-obligations', { year, per_page: 60 });
  if (failure(rows)) return failedBody(failure(rows), reload);
  if (!rows.length) return tileEmpty(`Belum ada baris kewajiban masa ${year}.`);

  const today = new Date().toISOString().slice(0, 10);
  const belum = rows.filter((row) => row.status === 'belum');
  const lewat = belum.filter((row) => row.due_date < today);
  // Yang paling dekat jatuh temponya lebih dulu; yang sudah lewat ada di atas
  // karena tanggalnya paling kecil.
  const terdekat = [...belum].sort((a, b) => String(a.due_date).localeCompare(String(b.due_date))).slice(0, 4);

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, statRow([
      stat('Lewat tenggat setor', String(lewat.length), {
        sub: lewat.length ? 'perlu tindakan' : 'tidak ada',
        tone: lewat.length ? 'down' : undefined,
      }),
      stat('Belum disetor', String(belum.length), { sub: `dari ${rows.length} masa ${year}` }),
    ])),
    miniTable(
      [
        { label: 'Masa', render: (row) => el('span', [el('span.cell-main', { text: row.label || row.tax_type || '—' }), el('span.cell-sub', { text: row.period || '' })]) },
        {
          label: 'Tenggat setor',
          render: (row) => el('span', [
            el('span.cell-main', { text: fmt.date(row.due_date) }),
            el('span.cell-sub', { text: row.due_date < today ? 'sudah lewat' : '', style: row.due_date < today ? { color: 'var(--danger)' } : {} }),
          ]),
        },
      ],
      terdekat,
      () => navigate('kalender-pajak'),
      { empty: { kind: 'done', message: 'Semua masa tahun ini sudah disetor atau dilapor.' } },
    ),
    footLink('Buka kalender pajak', () => navigate('kalender-pajak')),
  ]);
}
