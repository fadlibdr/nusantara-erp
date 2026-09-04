/* Tugas Saya — kotak masuk persetujuan lengkap.
 *
 * Kartu dasbor hanya cuplikan lima baris; layar ini adalah antreannya: semua
 * jenis dokumen di ApprovableDocuments yang boleh disetujui pemanggil, yang
 * paling lama menunggu di atas, dengan saringan per jenis. Satu permintaan
 * (GET core/inbox); tidak ada logika per modul di sini — jenis dokumen baru
 * ikut otomatis begitu terdaftar di server.
 *
 * Dulu pekerjaan sampai lewat tiga pintu yang harus diperiksa bergantian
 * (kartu dasbor 11 jenis, lonceng yang basi, Tenggat untuk yang lewat).
 * Layar ini menjawab satu pertanyaan saja: "apa yang menunggu keputusan
 * saya sekarang" — dan menjawabnya lengkap. */
import { api } from '../api.js';
import { el, clear, button, badge, emptyState, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

export async function renderTugas(host) {
  clear(host);
  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Tugas Saya' }),
      el('.desc', { text: 'Dokumen yang menunggu keputusan Anda, yang paling lama menunggu di atas.' }),
    ]),
    el('.actions', [button('Muat ulang', { iconName: 'refresh', onClick: () => renderTugas(host) })]),
  ]));

  const card = el('.card');
  host.appendChild(card);
  card.appendChild(skeletonTable(5, 5));

  let payload;
  try {
    payload = await api.list('core/inbox');
  } catch (error) {
    clear(card).appendChild(el('.card-body', errorState(error, () => renderTugas(host))));
    return;
  }

  const rows = payload.data || [];
  const meta = payload.meta || {};
  const types = [...new Set(rows.map((r) => r.label))].sort();
  let filter = '';

  const select = el('select.filter-w', [
    el('option', { value: '', text: `Semua jenis (${rows.length})` }),
    ...types.map((t) => el('option', { value: t, text: `${t} (${rows.filter((r) => r.label === t).length})` })),
  ]);
  select.addEventListener('change', () => { filter = select.value; paint(); });

  const body = el('div');
  clear(card);
  card.appendChild(el('.filters', [el('span.muted', { text: 'Jenis dokumen' }), select]));
  card.appendChild(body);

  function paint() {
    clear(body);
    const shown = filter ? rows.filter((r) => r.label === filter) : rows;

    if (meta.failed && meta.failed.length) {
      body.appendChild(el('.alert.warn', { style: { margin: '12px 16px 0' } },
        `Gagal dimuat: ${meta.failed.join(', ')}. Daftar ini belum lengkap.`));
    }

    if (!shown.length) {
      body.appendChild(emptyState(
        meta.failed && meta.failed.length ? 'Tidak ada dokumen yang dapat ditampilkan.' : 'Tidak ada dokumen yang menunggu keputusan Anda.',
        { title: 'Kotak masuk kosong' },
      ));
      return;
    }

    body.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Dokumen' }), el('th', { text: 'Keterangan' }), el('th', { text: 'Diajukan oleh' }),
        el('th', { text: 'Menunggu' }), el('th.right', { text: 'Nilai' }),
      ])),
      el('tbody', shown.map((r) => {
        const tr = el('tr.clickable', [
          el('td', [el('span.cell-main.mono', { text: r.code }), el('span.cell-sub', { text: r.label })]),
          el('td', { text: r.title || '—', style: { maxWidth: '420px' } }),
          el('td', { text: r.submitted_by || '—' }),
          el('td', r.days_waiting === null || r.days_waiting === undefined
            ? '—'
            // 7 hari ke atas berwarna: antrean yang menua adalah temuan, bukan angka.
            : badge(`${r.days_waiting} hari`, r.days_waiting >= 14 ? 'red' : r.days_waiting >= 7 ? 'amber' : '')),
          el('td.right.num', { text: r.amount === null || r.amount === undefined ? '—' : fmt.rupiah(r.amount) }),
        ]);
        tr.addEventListener('click', () => navigate(r.link.replace(/^#\//, '')));
        return tr;
      })),
    ])));
  }

  paint();
}
