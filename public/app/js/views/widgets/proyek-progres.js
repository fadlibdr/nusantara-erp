/* Widget "Progres proyek" (P1-D) - GET projects.
   Enam proyek berjalan dengan DEVIASI TERBESAR lebih dulu, bukan enam yang
   kebetulan paling baru: sebuah widget enam baris yang menampilkan proyek yang
   tepat jadwal sementara satu proyek tertinggal 18 % ada di baris ketujuh tidak
   memberi tahu apa pun.

   Widget ini TIDAK menyebut jumlah proyek. Daftarnya berhalaman (per_page 100)
   dan hitungan sisi klien atas halaman pertama adalah persis Temuan 79; angka
   "Proyek aktif" yang benar sudah ada di ubin launcher, dijumlah di SQL. */

import { el, progressBar } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, miniTable, footLink } from './kit.js';

export async function build({ reload }) {
  const projects = await safe('projects', { per_page: 100 });
  if (failure(projects)) return failedBody(failure(projects), reload);

  const active = projects
    .filter((project) => ['active', 'finishing'].includes(project.status))
    .map((project) => ({
      ...project,
      deviation: Number(project.actual_progress_pct || 0) - Number(project.planned_progress_pct || 0),
    }))
    .sort((a, b) => a.deviation - b.deviation)
    .slice(0, 6);

  return el('div', [
    miniTable(
      [
        { label: 'Proyek', render: (row) => el('span', [el('span.cell-main', { text: row.name }), el('span.cell-sub.mono', { text: row.code })]) },
        {
          label: 'Progres',
          render: (row) => {
            const actual = Number(row.actual_progress_pct || 0);
            const planned = Number(row.planned_progress_pct || 0);
            return el('div', { style: { minWidth: '140px' } }, [
              el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: '11.5px', marginBottom: '3px' } }, [
                el('span.num', { text: fmt.percent(actual) }),
                el('span.muted.num', { text: `rencana ${fmt.percent(planned)}` }),
              ]),
              progressBar(actual, actual + 0.01 < planned ? 'amber' : 'green'),
            ]);
          },
        },
      ],
      active,
      (row) => navigate(`d/projects/${row.id}`),
      { empty: { message: 'Tidak ada proyek yang sedang berjalan.' } },
    ),
    footLink('Semua proyek', () => navigate('r/projects')),
  ]);
}
