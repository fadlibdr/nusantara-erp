/* Widget "Progres proyek" (P1-D) - GET projects.
   Enam proyek berjalan dengan DEVIASI TERBESAR lebih dulu, bukan enam yang
   kebetulan paling baru: sebuah widget enam baris yang menampilkan proyek yang
   tepat jadwal sementara satu proyek tertinggal 18 % ada di baris ketujuh tidak
   memberi tahu apa pun.

   Widget ini TIDAK menyebut jumlah proyek. Daftarnya berhalaman (per_page 100)
   dan hitungan sisi klien atas halaman pertama adalah persis Temuan 79; angka
   "Proyek aktif" yang benar sudah ada di ubin launcher, dijumlah di SQL.

   TETAPI PERINGKATNYA JUGA SISI KLIEN, dan itu hal kedua yang harus diakui.
   Server mengurutkan `orderByDesc('id')` lalu memotong 100; deviasi dihitung di
   sini. Pada portofolio lebih dari 100 proyek, yang paling tertinggal bisa ada
   di halaman kedua dan tidak pernah muncul — sementara kartunya berjanji "enam
   dengan deviasi terbesar". Karena itu widget ini memakai safeList (yang
   membawa meta.total) dan MENULIS kalimatnya di kaki kartu ketika daftarnya
   terpotong: "6 terburuk dari 100 proyek terbaru (dari N)". Yang tidak
   dilakukan adalah diam (verifikasi P1-D).

   SAKELAR 'Proyek saya' IKUT DIKIRIM. ringkasan-uang mengirim `mine` dan
   docblock-nya menjanjikan bahwa kedua kartu "selalu bercerita tentang
   himpunan proyek yang SAMA"; sampai verifikasi P1-D widget ini tidak
   mengirimnya sama sekali, jadi dasbor direktur dengan sakelar menyala menulis
   "PROYEK SAYA (BERJALAN) 0" di sebelah daftar yang memuat dua proyek yang
   bukan miliknya. Judul kartunya ikut berganti, karena angka yang berganti
   makna tidak boleh memakai judul yang sama. */

import { el, progressBar } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safeList, failure, failedBody, rowsOf, totalOf, miniTable, footLink } from './kit.js';

/** Berapa proyek yang diminta server per halaman — dipakai juga oleh kalimat kaki. */
const WINDOW = 100;

export async function build({ reload, mineOnly, card }) {
  const payload = await safeList('projects', { per_page: WINDOW, mine: mineOnly ? 1 : undefined });
  if (failure(payload)) return failedBody(failure(payload), reload);

  const projects = rowsOf(payload);
  const total = totalOf(payload);

  // Judul kartu mengikuti sakelarnya, seperti ringkasan-uang.
  const heading = card && card.querySelector('.card-head h2');
  if (heading) heading.textContent = mineOnly ? 'Progres proyek saya' : 'Progres proyek';

  const active = projects
    .filter((project) => ['active', 'finishing'].includes(project.status))
    .map((project) => ({
      ...project,
      deviation: Number(project.actual_progress_pct || 0) - Number(project.planned_progress_pct || 0),
    }))
    .sort((a, b) => a.deviation - b.deviation)
    .slice(0, 6);

  /* Terpotong = peringkatnya tidak menyapu seluruh portofolio. `total` null
     berarti server tidak menyebutkan jumlahnya; itu bukan alasan untuk diam,
     jadi kalimatnya tetap menyebut jendelanya. */
  const truncated = projects.length >= WINDOW && (total === null || total > projects.length);
  const footLabel = truncated
    ? `Semua proyek (peringkat ini dari ${WINDOW} proyek terbaru${total === null ? '' : ` dari ${total}`})`
    : 'Semua proyek';

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
      {
        empty: {
          message: mineOnly
            ? 'Tidak ada proyek yang Anda kelola sedang berjalan.'
            : 'Tidak ada proyek yang sedang berjalan.',
        },
      },
    ),
    footLink(footLabel, () => navigate('r/projects')),
  ]);
}
