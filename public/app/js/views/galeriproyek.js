/* Galeri Foto Proyek — Temuan 16.
 *
 * Foto lapangan sudah ter-geotag dan tervalidasi jaraknya, tetapi tersebar per
 * dokumen: merakit lampiran satu termin berarti membuka laporan harian dan
 * BAST satu per satu, mengunduh berkas satu per satu. Layar ini membaca GET
 * core/projects/{id}/photos — semua lampiran ber-mime image lintas dokumen
 * proyek, terurut tanggal pengambilan — dan menampilkannya sebagai grid
 * thumbnail dengan lencana geotag yang datanya memang sudah tersimpan.
 *
 * Thumbnail dimuat lewat api.blob(), bukan <img src> polos: unduhan lampiran
 * berautentikasi lewat header token, jadi <img> tanpa header hanya akan
 * menggambar 401. Setiap object URL dicatat dan dicabut saat muat ulang —
 * tanpa itu, menelusuri 10 halaman galeri menahan ratusan blob foto di memori
 * tab sampai layarnya ditutup. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, errorState, modal } from '../ui.js';
import * as fmt from '../format.js';
import { navigate, back } from '../router.js';
import { RESOURCES } from '../schema.js';

const PER_PAGE = 24;

/* Lencana jarak — ambang yang sama dengan lapangan.js: 250 m menampung situs
 * besar plus GPS yang melenceng; lewat 1 km, fotonya bukan diambil di tempat
 * yang diklaim pekerjaannya. */
function distanceBadge(photo) {
  if (photo.distance_from_site_m === null || photo.distance_from_site_m === undefined) {
    if (!photo.geo_source) return null; // tanpa geotag: tidak ada klaim untuk dilencanai
    return badge('Ber-GPS, lokasi proyek belum diisi', '');
  }

  const metres = photo.distance_from_site_m;
  const label = metres < 1000 ? `${metres} m dari lokasi` : `${(metres / 1000).toFixed(1)} km dari lokasi`;

  return badge(label, metres <= 250 ? 'green' : (metres <= 1000 ? 'amber' : 'red'));
}

function geoSourceLabel(photo) {
  if (photo.geo_source === 'exif') return 'lokasi dari foto';
  if (photo.geo_source === 'device') return 'lokasi dari perangkat';
  return null;
}

export async function renderGaleriProyek(host, { id }) {
  clear(host);

  if (!session.can('prj.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke galeri foto proyek.'));
    return;
  }

  host.appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '35%' } }))));

  let project;
  try {
    project = await api.get(`projects/${id}`);
  } catch (error) {
    clear(host);
    host.append(
      el('.page-head', [button('Kembali', { iconName: 'back', onClick: () => back() })]),
      errorState(error, () => renderGaleriProyek(host, { id })),
    );
    return;
  }

  clear(host);

  // State per kunjungan, sengaja bukan modul-level: galeri proyek A yang
  // membekas (halaman 4, filter minggu lalu) di atas proyek B menyesatkan.
  const state = { dateFrom: '', dateTo: '', page: 1 };

  // Object URL yang hidup pada render saat ini; dicabut setiap muat ulang.
  let liveUrls = [];
  const revokeAll = () => {
    liveUrls.forEach((url) => URL.revokeObjectURL(url));
    liveUrls = [];
  };

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Galeri Foto Progres' }),
      el('.desc', { text: `${project.code} · ${project.name}` }),
    ]),
    el('.actions', [
      button('', { iconName: 'back', title: 'Kembali', onClick: () => back() }),
      button('Ke proyek', { onClick: () => navigate(`d/projects/${id}`) }),
    ]),
  ]));

  const fromInput = el('input.filter-w', {
    type: 'date', value: state.dateFrom, 'aria-label': 'Dari tanggal', title: 'Dari tanggal',
    onchange: (event) => { state.dateFrom = event.target.value; state.page = 1; load(); },
  });
  const toInput = el('input.filter-w', {
    type: 'date', value: state.dateTo, 'aria-label': 'Sampai tanggal', title: 'Sampai tanggal',
    onchange: (event) => { state.dateTo = event.target.value; state.page = 1; load(); },
  });

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  }, [
    fromInput, toInput,
    el('.spacer'),
    button('Muat ulang', { size: 'sm', variant: 'ghost', iconName: 'refresh', onClick: () => load() }),
  ]);

  const body = el('div');
  host.append(controls, body);

  function thumbNode(photo) {
    const img = el('img', {
      alt: photo.caption || photo.original_name,
      style: { width: '100%', height: '100%', objectFit: 'cover', display: 'block' },
    });

    const frame = el('div', {
      style: {
        height: '150px', background: 'var(--bg-2, rgba(127,127,127,.08))',
        display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden',
      },
    }, img);

    api.blob(`core/attachments/${photo.id}/download`).then((blob) => {
      const url = URL.createObjectURL(blob);
      liveUrls.push(url);
      img.src = url;
    }).catch(() => {
      // Bingkai kosong yang diam adalah foto yang "hilang"; sebut gagalnya.
      clear(frame).append(icon('warn', 18), el('span.cell-sub', { text: 'Gagal dimuat' }));
    });

    return frame;
  }

  function openPhoto(photo, frame) {
    const resource = photo.document && photo.document.slug ? RESOURCES[photo.document.slug] : null;
    const full = frame.querySelector('img');

    const dialog = modal({
      title: photo.caption || photo.original_name,
      width: 'wide',
      body: el('div', [
        // Blob thumbnail dipakai ulang apa adanya — unduhan penuh sudah satu
        // klik di footer, dan modal yang menunggu fetch kedua terasa mati.
        full && full.src
          ? el('img', { src: full.src, alt: photo.original_name, style: { maxWidth: '100%', maxHeight: '60vh', display: 'block', margin: '0 auto 12px' } })
          : null,
        el('dl.kv', [
          el('dt', { text: 'Dokumen' }),
          el('dd', { text: `${photo.document.label}${photo.document.code ? ` · ${photo.document.code}` : ''}` }),
          el('dt', { text: 'Tanggal' }),
          el('dd', { text: `${fmt.date(photo.taken_at || photo.created_at)}${photo.taken_at ? '' : ' (waktu unggah — kamera tidak mencatat waktu ambil)'}` }),
          el('dt', { text: 'Pengunggah' }),
          el('dd', { text: (photo.uploader && photo.uploader.name) || '—' }),
          el('dt', { text: 'Lokasi' }),
          el('dd', [
            distanceBadge(photo) || el('span.muted', { text: 'Tanpa geotag' }),
            geoSourceLabel(photo)
              ? el('span.cell-sub', { text: ` ${geoSourceLabel(photo)}${photo.accuracy_m ? ` · ±${photo.accuracy_m} m` : ''}`, style: { marginLeft: '6px' } })
              : null,
          ]),
        ]),
      ]),
      footer: [
        resource
          ? button('Buka dokumen', {
            onClick: () => { dialog.close(); navigate(`d/${photo.document.slug}/${photo.document.id}`); },
          })
          : null,
        button('Tutup', { onClick: () => dialog.close() }),
      ].filter(Boolean),
    });
  }

  function photoCard(photo) {
    const frame = thumbNode(photo);
    const node = el('div', {
      style: {
        border: '1px solid var(--border)', borderRadius: 'var(--radius)',
        overflow: 'hidden', cursor: 'pointer', background: 'var(--card, transparent)',
      },
      onclick: () => openPhoto(photo, frame),
    }, [
      frame,
      el('div', { style: { padding: '8px 10px', display: 'grid', gap: '3px' } }, [
        el('.cell-main', { text: photo.caption || photo.original_name, style: { fontSize: '12.5px' } }),
        el('.cell-sub', { text: `${photo.document.label}${photo.document.code ? ` · ${photo.document.code}` : ''}` }),
        distanceBadge(photo),
      ]),
    ]);
    return node;
  }

  async function load() {
    revokeAll();
    clear(body).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '120px' } }))));

    try {
      const payload = await api.list(`core/projects/${id}/photos`, {
        page: state.page,
        per_page: PER_PAGE,
        date_from: state.dateFrom || undefined,
        date_to: state.dateTo || undefined,
      });
      const photos = (payload && payload.data) || [];
      const meta = (payload && payload.meta) || {};

      clear(body);

      // Chip sumber: dari mana saja foto-foto ini — hitungan PRA-paginasi,
      // jadi tetap jujur di halaman mana pun.
      if ((meta.sources || []).length) {
        body.appendChild(el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px', marginBottom: '12px' } },
          meta.sources.map((source) => badge(`${source.label} ${source.count}`, ''))));
      }

      if (!photos.length) {
        body.appendChild(el('.card', el('.card-body', el('p.muted', {
          text: (state.dateFrom || state.dateTo)
            ? 'Tidak ada foto pada rentang tanggal ini.'
            : 'Belum ada foto pada dokumen proyek ini. Foto diunggah dari layar Lapangan atau dari kartu Lampiran tiap dokumen.',
          style: { margin: 0 },
        }))));
        return;
      }

      // Grid per tanggal: pemakainya membaca "kemajuan minggu ini", bukan
      // deretan berkas — tanggalnya tanggal AMBIL bila kamera mencatatnya.
      let currentDate = null;
      let grid = null;

      photos.forEach((photo) => {
        if (photo.date !== currentDate) {
          currentDate = photo.date;
          body.appendChild(el('h2', {
            text: fmt.date(currentDate),
            style: { fontSize: '13.5px', margin: '14px 0 8px' },
          }));
          grid = el('div', {
            style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '12px' },
          });
          body.appendChild(grid);
        }
        grid.appendChild(photoCard(photo));
      });

      const total = meta.total || photos.length;
      const lastPage = meta.last_page || 1;

      body.appendChild(el('.filters', { style: { marginTop: '16px' } }, [
        el('span.cell-sub', { text: `${meta.from || 1}–${meta.to || photos.length} dari ${total} foto` }),
        el('.spacer'),
        button('Sebelumnya', {
          size: 'sm', variant: 'ghost', disabled: state.page <= 1,
          onClick: () => { state.page -= 1; load(); },
        }),
        button('Berikutnya', {
          size: 'sm', variant: 'ghost', disabled: state.page >= lastPage,
          onClick: () => { state.page += 1; load(); },
        }),
      ]));
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
    }
  }

  await load();
}
