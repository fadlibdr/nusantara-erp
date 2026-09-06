/* Widget "Payroll bulan berjalan" (P1-D) - GET hr/payroll-runs.
   Daftarnya diurut server `orderByDesc('id')`, jadi yang di atas adalah run
   yang TERAKHIR DIBUAT - bukan otomatis masa terbaru, dan widget ini menyebut
   persis itu. Payroll adalah pengeluaran berulang terbesar; sebuah run yang
   masih draf pada tanggal 27 adalah kabar yang harus terbaca dari dasbor. */

import { el, badge } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safeList, failure, rowsOf, failedBody, statRow, stat, miniTable, footLink, tileEmpty } from './kit.js';

export async function build({ reload }) {
  const payload = await safeList('hr/payroll-runs', { per_page: 3 });
  if (failure(payload)) return failedBody(failure(payload), reload);

  const runs = rowsOf(payload);
  if (!runs.length) return el('div', [
      tileEmpty('Belum ada run payroll yang dibuat.'),
      /* Kaki kartu ikut pada keadaan kosong: layar yang isinya kosong justru
         yang butuh pintu untuk diperiksa (verifikasi P1-D putaran 2, 6 Sep 2026). */
      footLink('Buka run payroll', () => navigate('r/hr/payroll-runs')),
    ]);

  const latest = runs[0];
  /* PayrollRunResource mengirim `period_year` + `period_month`, BUKAN `period`
     — kolom "Periode" di layar daftar adalah kolom komposit schema.js. Membaca
     `run.period` menggambar em dash di setiap baris tanpa satu galat pun
     (terukur 6 Sep 2026, dasbor finance). */
  const period = (run) => fmt.periodLabel(run.period_year, run.period_month);

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, statRow([
      stat('Netto run terbaru', fmt.rupiahShort(latest.total_net), {
        sub: `${latest.code} · ${period(latest)}`,
      }),
      stat('Bruto', fmt.rupiahShort(latest.total_gross), {
        sub: `potongan ${fmt.rupiahShort(latest.total_deductions)}`,
      }),
    ])),
    miniTable(
      [
        { label: 'Run', render: (row) => el('span', [el('span.cell-main.mono', { text: row.code }), el('span.cell-sub', { text: period(row) })]) },
        { label: 'Status', render: (row) => badge(row.status_label || row.status, fmt.statusTone(row.status)) },
        { label: 'Netto', align: 'right', render: (row) => el('span.num', { text: fmt.rupiah(row.total_net) }) },
      ],
      runs,
      (row) => navigate(`d/hr/payroll-runs/${row.id}`),
    ),
    el('p.cell-sub', {
      text: 'Tiga run terakhir DIBUAT (urutan server), bukan tiga masa terakhir.',
      style: { fontSize: '11.5px', margin: '0 16px 12px' },
    }),
    footLink('Buka payroll', () => navigate('r/hr/payroll-runs')),
  ]);
}
