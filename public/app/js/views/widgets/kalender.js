/* Widget "Kalender acara" (P1-D) — GET core/calendar, dipindahkan apa adanya
   dari kartu dasbor P1-B/P1-C.

   "Apa yang terjadi KAPAN" — saudara layar Tenggat ("apa yang lewat"). Kartunya
   TETAP digambar saat bulannya kosong supaya cincin "hari ini" dan pintu ke
   kalender penuh selalu tersedia.

   HARI INI menurut JAM SERVER (meta.as_of), bukan jam peramban: peramban demo
   tidak selalu di zona Jakarta, dan new Date() di sini pernah berarti
   melingkari hari yang salah. Perbandingan tanggal dilakukan sebagai STRING
   'YYYY-MM-DD' — string tanggal polos diparse sebagai tengah malam UTC, lalu
   getDate() lokal bisa mundur sehari di barat UTC dan memindahkan cincinnya. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { KALENDER_DEPTS, ensureKalenderPalette, kalDot } from '../../kalenderpalette.js';
import { safeList, failure, tileEmpty, failedBody, miniTable, footLink } from './kit.js';

export async function build({ reload, card }) {
  const payload = await safeList('core/calendar');
  if (failure(payload)) return failedBody(failure(payload), reload);

  const meta = payload.meta || {};
  if (!meta.as_of) {
    return tileEmpty('Kalender bulan ini tidak dapat dibaca.', 'error');
  }

  ensureKalenderPalette();
  // Token --kal-1..8 hanya hidup di dalam .kal-scope; kartunyalah yang
  // membawanya, jadi titik di tabel bawah ikut berwarna benar.
  if (card) card.classList.add('kal-scope');

  const events = payload.data || [];
  const asOf = String(meta.as_of);
  const [year, monthNum] = String(meta.month).split('-').map(Number);

  const byDate = new Map();
  events.forEach((event) => {
    if (!byDate.has(event.date)) byDate.set(event.date, []);
    byDate.get(event.date).push(event);
  });

  // Hari ke-0 bulan berikutnya = hari terakhir bulan ini (Agu 2026 -> 31).
  const daysInMonth = new Date(year, monthNum, 0).getDate();
  // Kolom pertama Senin; getDay() memberi Minggu = 0, maka digeser 6.
  const lead = (new Date(year, monthNum - 1, 1).getDay() + 6) % 7;

  const cellStyle = { textAlign: 'center', padding: '3px 0 4px', borderRadius: '6px' };
  // 11 px: lantai ukuran huruf aplikasi (T2.10).
  const cells = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'].map((name) =>
    el('div.muted', { text: name, style: { ...cellStyle, fontSize: '11px' } }));
  for (let i = 0; i < lead; i += 1) cells.push(el('div'));

  for (let day = 1; day <= daysInMonth; day += 1) {
    const dateStr = `${meta.month}-${String(day).padStart(2, '0')}`;
    const dayEvents = byDate.get(dateStr) || [];
    const isToday = dateStr === asOf;

    cells.push(el('div', {
      title: dayEvents.map((event) => `${event.title} - ${event.department}`).join('\n') || null,
      style: {
        ...cellStyle,
        fontSize: '11.5px',
        ...(isToday ? {
          background: 'var(--primary-soft)',
          color: 'var(--primary)',
          fontWeight: '600',
          boxShadow: 'inset 0 0 0 1px var(--primary)',
        } : {}),
      },
    }, [
      el('div.num', { text: String(day) }),
      // Titik dibatasi 4 - sel pada kartu 330 px hanya selebar +-40 px.
      el('div', { style: { display: 'flex', gap: '2px', justifyContent: 'center', minHeight: '5px', marginTop: '1px' } },
        dayEvents.slice(0, 4).map((event) => kalDot(event.department, 5))),
    ]));
  }

  // Legenda dari meta.departments - hitungan PRA-cap per pemanggil, jadi
  // angkanya tetap jujur walau bulan terpotong di 500 agenda.
  const rank = (department) => {
    const index = KALENDER_DEPTS.indexOf(department);
    return index === -1 ? KALENDER_DEPTS.length : index;
  };
  const legend = Object.entries(meta.departments || {}).sort(([a], [b]) => rank(a) - rank(b));

  // "Mendatang" diiris dengan as_of server; data sudah terurut dari server.
  const upcoming = events.filter((event) => event.date >= asOf).slice(0, 5);

  // Selisih hari dihitung terhadap as_of, bukan jam peramban (fmt.relativeDays
  // memakai new Date() - dilarang di widget ini).
  const hariLagi = (eventDate) => {
    const days = Math.round((new Date(eventDate) - new Date(asOf)) / 86400000);
    if (days === 0) return 'hari ini';
    if (days === 1) return 'besok';
    return `${days} hari lagi`;
  };

  const tanggalPendek = (iso) => {
    const [y, m, d] = String(iso).split('-').map(Number);
    return y && m && d ? `${String(d).padStart(2, '0')} ${fmt.MONTHS_SHORT[m - 1]} ${y}` : '—';
  };

  return el('div', [
    el('.card-body', { style: { paddingBottom: '12px' } }, [
      el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: '2px' } }, cells),
      legend.length
        ? el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px 12px', marginTop: '10px' } },
          legend.map(([department, count]) => el('span.cell-sub', { style: { display: 'inline-flex', alignItems: 'center', gap: '5px' } }, [kalDot(department), `${department} ${count}`])))
        : null,
      meta.capped
        ? el('p.cell-sub', {
          text: `Menampilkan ${meta.count} dari ${meta.total} agenda bulan ini - sisanya terpotong.`,
          style: { color: 'var(--warning)', margin: '8px 0 0' },
        })
        : null,
    ]),
    upcoming.length
      ? miniTable(
        [
          /* Dari potongan string, bukan fmt.date: new Date('YYYY-MM-DD')
             diparse UTC, jadi peramban ber-zona negatif menulis '04 Agu' di
             samping titik grid tanggal 5 - satu agenda tampil di dua hari. */
          { label: 'Tanggal', render: (event) => el('span', [el('span.cell-main', { text: tanggalPendek(event.date) }), el('span.cell-sub', { text: hariLagi(event.date) })]) },
          {
            label: 'Agenda',
            render: (event) => el('span', [
              el('span.cell-main', { text: event.title }),
              event.code && event.code !== event.title ? el('span.cell-sub.mono', { text: event.code }) : null,
            ]),
          },
          {
            label: 'Departemen',
            // Nama departemen ditulis sebagai teks biasa dan titiknya hanya
            // pengiring: teks berwarna seri tidak dijamin kontrasnya di atas
            // permukaan kartu (kuning #eda100 di tema terang ~1,9:1).
            render: (event) => el('span.cell-sub', { style: { display: 'inline-flex', alignItems: 'center', gap: '5px' } }, [kalDot(event.department), event.department]),
          },
        ],
        upcoming,
        // Setiap agenda menunjuk layar SPA yang SUDAH ada.
        (event) => navigate(event.link),
      )
      : tileEmpty(events.length
        ? 'Tidak ada agenda tersisa bulan ini.'
        : 'Tidak ada agenda bulan ini pada modul yang boleh Anda lihat.', 'inbox'),
    /* Pintu ke kalender penuh, yang docblock di atas sebut "selalu tersedia"
       dan yang kartu kalender P1-C memang punya ("Buka kalender lengkap").
       Sampai verifikasi kedua P1-D berkas ini tidak memanggil footLink satu
       kali pun, jadi kalimat itu tidak benar untuk satu keadaan pun. */
    footLink('Buka kalender lengkap', () => navigate('kalender')),
  ]);
}
