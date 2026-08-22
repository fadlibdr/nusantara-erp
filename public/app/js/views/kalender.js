/* Kalender — semua rencana bertanggal perusahaan, satu bulan satu layar.

   Setiap tanggal di sini sudah lama ada di sistem: jatuh tempo termin di detail
   kontrak, kunjungan PM di jadwal preventif, hari gajian di payroll, tutup buku
   di periode fiskal, target milestone di proyek — tersebar di sebelas layar
   berbeda. "Apa yang jatuh tempo minggu depan?" hanya bisa dijawab dengan
   membuka semuanya satu per satu. Layar ini membaca 23 sumber tanggal lewat
   GET core/calendar (16 registri pengawas tenggat + 7 khusus kalender) dan
   menaruhnya di satu grid bulan. Di data demo, Agustus 2026 memegang 4 agenda:
   PM CCTV Bulanan 5 Agu, PM Akses Kontrol & Alarm Bulanan 12 Agu (Layanan),
   QTN/2026/VII/0004 berlaku s/d 31 Agu (Penjualan, Rp 33,97 jt), dan Tutup
   buku Agustus 2026 di 31 Agu (Keuangan).

   Saudara kandung layar Tenggat, bukan penggantinya: Tenggat menjawab "apa
   yang lewat atau menipis" dan barisnya hilang begitu penyebabnya beres;
   Kalender menjawab "KAPAN sesuatu terjadi" — termasuk rencana yang bukan
   kewajiban (gajian, tutup buku, proyek mulai) yang tidak akan pernah
   muncul di Tenggat.

   "Hari ini" adalah meta.as_of dari server (jam aplikasi, WIB) — BUKAN
   new Date(): peramban demo tidak selalu di zona Jakarta, dan cincin hari
   yang meleset sehari membuat rapat pagi membahas agenda kemarin. */

import { api } from '../api.js';
import { el, clear, button, errorState, skeletonTable, modal } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';
// Satu palet untuk kartu dasbor DAN layar ini — pengguna belajar "kuning =
// SDM" di kartu, lalu membuka layar penuh; rona yang berpindah pemilik antara
// keduanya membatalkan pelajaran itu. dashboard.js pemilik tunggalnya
// (lengkap dengan step tema-gelapnya); layar ini hanya memakainya.
import { KALENDER_DEPTS, kalenderDeptColor, ensureKalenderPalette } from './dashboard.js';

/* Palet tema hanya punya enam rona untuk delapan departemen, jadi titik agenda
   teks di sebelahnya tetap var(--text). Urutan larik = urutan chip legenda,
   sama dengan urutan kontrak API. 'Persediaan' belum punya sumber bertanggal
   di skema hari ini, jadi chip-nya memang tidak pernah tampil — warnanya
   tetap dipesan supaya tidak ada tebak-tebakan saat sumbernya lahir. */
const DEPARTEMEN = KALENDER_DEPTS;

/** Departemen baru di server turun ke netral, bukan crash — grid tetap render. */
const warnaDept = (dept) => kalenderDeptColor(dept);

/** Senin dulu — konvensi kalender Indonesia; kolom terakhir (indeks 6) Minggu. */
const HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

/** Baris agenda per sel sebelum "+N lagi" — sel ~104px muat tiga baris 11.5px. */
const MAKS_PER_SEL = 3;

/** '2026-08' ± n bulan, murni aritmetika — tanpa objek Date, tanpa zona waktu. */
function geserBulan(bulan, delta) {
  const [y, m] = String(bulan).split('-').map(Number);
  const total = y * 12 + (m - 1) + delta;
  return `${Math.floor(total / 12)}-${String((total % 12) + 1).padStart(2, '0')}`;
}

/* 'YYYY-MM-DD' → '31 Agustus 2026' dari potongan string, bukan lewat
   fmt.dateLong: new Date('YYYY-MM-DD') diparse sebagai UTC, jadi di peramban
   ber-zona negatif (demo pernah dibuka dari luar WIB) tanggalnya mundur
   sehari — persis pergeseran yang dilarang kontrak API ini. */
function tanggalPanjang(iso) {
  const [y, m, d] = String(iso).split('-').map(Number);
  if (!y || !m || !d) return '—';
  return `${d} ${fmt.MONTHS[m - 1]} ${y}`;
}

const titik = (dept, size = 7) => el('span', {
  'aria-hidden': 'true',
  style: {
    flex: 'none',
    width: `${size}px`,
    height: `${size}px`,
    borderRadius: '50%',
    background: warnaDept(dept),
  },
});

/* Chip departemen: warna hanya di titiknya, nama tetap var(--text) — teks
   berwarna seri (kuning/cyan di tema terang) jatuh di bawah ambang kontras,
   pola yang catatan desain dasbor sendiri tolak. */
function badgeDept(dept) {
  return el('span', {
    style: { display: 'inline-flex', alignItems: 'center', gap: '5px' },
  }, [titik(dept), dept]);
}

/** Teks tooltip: judul, kode bila berbeda, nilai rupiah bila sumbernya punya. */
function keterangan(ev) {
  const nilai = ev.value === null || ev.value === undefined ? '' : ` · ${fmt.rupiah(ev.value)}`;
  return ev.title === ev.code ? `${ev.title}${nilai}` : `${ev.title} — ${ev.code}${nilai}`;
}

/** Satu baris agenda di dalam sel hari; klik membuka layar sumbernya. */
function entriAgenda(ev) {
  return el('button', {
    title: keterangan(ev),
    onclick: () => navigate(ev.link),
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: '5px',
      width: '100%',
      minWidth: '0',
      margin: '0',
      padding: '1px 3px',
      border: 'none',
      background: 'none',
      borderRadius: '4px',
      fontSize: '11.5px',
      color: 'var(--text)',
      textAlign: 'left',
      cursor: 'pointer',
    },
  }, [
    titik(ev.department),
    el('span', {
      text: ev.title,
      style: { overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis' },
    }),
  ]);
}

/** Rincian satu hari — tempat nilai rupiah dan kode dokumen terbaca penuh. */
function bukaHari(tanggal, daftar) {
  let dialog;

  const tutup = button('Tutup', { onClick: () => dialog.close() });

  dialog = modal({
    title: tanggalPanjang(tanggal),
    // #overlay berdiri di luar DOM layar, jadi scope paletnya dibawa sendiri.
    body: el('.table-wrap.kal-scope', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Agenda' }),
        el('th', { text: 'Departemen' }),
        el('th.right', { text: 'Nilai' }),
        el('th', { text: '' }),
      ])),
      el('tbody', daftar.map((ev) => el('tr', [
        el('td', el('span', [
          el('span.cell-main', { text: ev.title }),
          ev.code !== ev.title ? el('span.cell-sub.mono', { text: ev.code }) : null,
        ])),
        el('td', badgeDept(ev.department)),
        el('td.right.num', { text: ev.value === null || ev.value === undefined ? '—' : fmt.rupiah(ev.value) }),
        el('td.right', button('Buka', {
          size: 'sm',
          onClick: () => {
            dialog.close();
            navigate(ev.link);
          },
        })),
      ]))),
    ])),
    footer: [tutup],
  });
}

export async function renderKalender(host, bulan = null, saring = null) {
  clear(host);
  // Var --kal-N hidup di .kal-scope; tanpa kelas ini semua titik jatuh ke
  // fallback var(--muted) dan legendanya jadi abu-abu seragam.
  ensureKalenderPalette();
  host.classList.add('kal-scope');
  const reload = () => renderKalender(host, bulan, saring);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Kalender' }),
      el('.desc', {
        text: 'Semua rencana bertanggal dalam satu bulan — jatuh tempo, kunjungan PM, '
          + 'gajian, tutup buku — dari modul yang boleh Anda lihat.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(5, 7));

  let payload;
  try {
    payload = await api.list('core/calendar', bulan ? { month: bulan } : undefined);
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  const agenda = payload.data || [];
  const meta = payload.meta || {};
  const asOf = String(meta.as_of || '');
  const aktif = String(meta.month || bulan || asOf.slice(0, 7));
  const [tahun, nomorBulan] = aktif.split('-').map(Number);
  const hitungDept = meta.departments || {};

  // Saringan yang departemennya absen bulan ini dilepas: chip-nya tidak akan
  // dirender, jadi tanpa ini tidak ada jalan untuk membatalkan saringan.
  if (saring && !hitungDept[saring]) saring = null;

  const tampil = saring ? agenda.filter((ev) => ev.department === saring) : agenda;

  clear(body);

  /* ------------------------------------------------------- navigasi bulan */
  body.appendChild(el('div', {
    style: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  }, [
    button('', { iconName: 'back', title: 'Bulan sebelumnya', onClick: () => renderKalender(host, geserBulan(aktif, -1), saring) }),
    button('', { iconName: 'chevronRight', title: 'Bulan berikutnya', onClick: () => renderKalender(host, geserBulan(aktif, 1), saring) }),
    // null → server yang memilih bulan berjalan menurut jamnya sendiri.
    button('Hari ini', {
      size: 'sm',
      onClick: () => renderKalender(host, null, saring),
      disabled: aktif === asOf.slice(0, 7),
    }),
    el('h2', { text: `${fmt.MONTHS[nomorBulan - 1]} ${tahun}`, style: { fontSize: '16px', marginLeft: '2px' } }),
    el('.spacer', { style: { flex: '1' } }),
    el('span.muted', { text: `hari ini ${tanggalPanjang(asOf)} — jam server`, style: { fontSize: '12px' } }),
  ]));

  /* --------------------------------------------------------------- angka */
  // Perbandingan string murni terhadap as_of server — bukan new Date().
  const mendatang = agenda.filter((ev) => ev.date >= asOf).length;

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Agenda bulan ini' }),
      el('.value.sm', { text: String(meta.total ?? agenda.length) }),
      el('.delta', { text: `di ${Object.keys(hitungDept).length} departemen` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Mendatang' }),
      el('.value.sm', { text: String(mendatang) }),
      el('.delta', { text: 'terhitung dari hari ini' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Sumber dipindai' }),
      el('.value.sm', { text: String(meta.checked ?? '—') }),
      el('.delta', {
        // Tabel setengah termigrasi jadi baris dilewati, bukan crash — dua tim
        // memigrasi repositori ini tiap hari, jadi ini normal, bukan alarm.
        text: meta.skipped ? `${meta.skipped} dilewati (tabel sedang dimigrasi)` : 'semua sumber terbaca',
      }),
    ]),
  ]));

  if (meta.capped) {
    body.appendChild(el('.alert.warn', {
      style: { marginBottom: '12px' },
      text: `Menampilkan ${meta.count} dari ${meta.total} agenda bulan ini — sisanya terpotong. `
        + 'Tanggal-tanggal awal bulan tetap lengkap, dan angka pada legenda tetap menghitung semuanya.',
    }));
  }

  if (!agenda.length) {
    body.appendChild(el('.alert.info', {
      style: { marginBottom: '12px' },
      text: `Tidak ada agenda pada ${fmt.MONTHS[nomorBulan - 1]} ${tahun} untuk modul yang boleh Anda lihat. `
        + 'Agenda muncul begitu tanggalnya tercatat di dokumen sumbernya — dan sebagian memang tidak pernah '
        + 'bertanggal: termin tanpa "Rencana tagih" tidak tampil di kalender mana pun; layar Tenggat yang '
        + 'melaporkan kekosongan itu.',
    }));
  }

  /* -------------------------------------------------------------- legenda */
  if (agenda.length) {
    const chipLegenda = (dept) => {
      const dipilih = saring === dept;

      return el('button', {
        title: dipilih ? 'Tampilkan semua departemen' : `Hanya tampilkan agenda ${dept}`,
        'aria-pressed': dipilih ? 'true' : 'false',
        // Bulan dikunci eksplisit (aktif, bukan bulan) supaya menyaring tidak
        // melompat balik ke bulan berjalan saat pengguna sedang menjelajah.
        onclick: () => renderKalender(host, aktif, dipilih ? null : dept),
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          gap: '6px',
          padding: '3px 10px',
          borderRadius: '999px',
          border: `1px solid ${dipilih ? 'var(--primary)' : 'var(--border)'}`,
          background: dipilih ? 'var(--primary-soft)' : 'var(--surface)',
          fontSize: '12px',
          color: 'var(--text)',
          cursor: 'pointer',
        },
      }, [
        titik(dept, 8),
        // Angka dari meta.departments — kebenaran pra-cap, bukan hasil hitung
        // ulang data yang mungkin sudah terpotong 500.
        el('span', { text: `${dept} ${hitungDept[dept]}` }),
      ]);
    };

    // KALENDER_DEPTS adalah larik nama datar (pemiliknya dashboard.js), bukan
    // pasangan [nama, warna] — warnanya diambil lewat kalenderDeptColor().
    const chips = DEPARTEMEN
      .filter((dept) => hitungDept[dept])
      .map((dept) => chipLegenda(dept));

    // Departemen yang belum ada di peta warna tetap dapat chip netral —
    // menyaring tetap bisa, hanya warnanya belum khas.
    Object.keys(hitungDept)
      .filter((dept) => !DEPARTEMEN.includes(dept))
      .sort()
      .forEach((dept) => chips.push(chipLegenda(dept)));

    body.appendChild(el('div', {
      style: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
    }, chips));
  }

  /* ----------------------------------------------------------- grid bulan */
  const perHari = new Map();
  tampil.forEach((ev) => {
    if (!perHari.has(ev.date)) perHari.set(ev.date, []);
    perHari.get(ev.date).push(ev);
  });

  // Konstruktor Date NUMERIK memakai zona lokal (bukan parse-UTC string), dan
  // hari-dalam-minggu sebuah tanggal sipil sama di zona mana pun — aman.
  const jumlahHari = new Date(tahun, nomorBulan, 0).getDate();
  const awal = (new Date(tahun, nomorBulan - 1, 1).getDay() + 6) % 7; // getDay(): 0=Minggu

  const selKosong = () => el('div', { style: { background: 'var(--surface-2)', minHeight: '104px' } });
  const sel = [];

  HARI.forEach((nama, kolom) => sel.push(el('div', {
    text: nama,
    style: {
      padding: '6px 8px',
      background: 'var(--surface-2)',
      fontSize: '11.5px',
      fontWeight: '600',
      textTransform: 'uppercase',
      letterSpacing: '.04em',
      color: kolom === 6 ? 'var(--danger)' : 'var(--muted)',
    },
  })));

  for (let i = 0; i < awal; i++) sel.push(selKosong());

  for (let hari = 1; hari <= jumlahHari; hari++) {
    const tanggal = `${aktif}-${String(hari).padStart(2, '0')}`;
    const kolom = (awal + hari - 1) % 7;
    const daftar = perHari.get(tanggal) || [];
    const iniHariIni = tanggal === asOf;

    const isi = daftar.slice(0, MAKS_PER_SEL).map(entriAgenda);

    if (daftar.length > MAKS_PER_SEL) {
      isi.push(el('button', {
        text: `+${daftar.length - MAKS_PER_SEL} lagi`,
        title: 'Lihat semua agenda hari ini',
        onclick: () => bukaHari(tanggal, daftar),
        style: {
          alignSelf: 'flex-start',
          margin: '0',
          padding: '1px 3px',
          border: 'none',
          background: 'none',
          borderRadius: '4px',
          fontSize: '11px',
          color: 'var(--muted)',
          textAlign: 'left',
          cursor: 'pointer',
        },
      }));
    }

    sel.push(el('div', {
      style: {
        minHeight: '104px',
        minWidth: '0',
        padding: '5px',
        background: iniHariIni ? 'var(--primary-soft)' : 'var(--surface)',
        display: 'flex',
        flexDirection: 'column',
        gap: '2px',
      },
    }, [
      el('span', {
        text: String(hari),
        title: iniHariIni ? 'Hari ini (jam server, WIB)' : undefined,
        style: iniHariIni
          ? {
            alignSelf: 'flex-start',
            minWidth: '20px',
            height: '20px',
            padding: '0 4px',
            borderRadius: '999px',
            background: 'var(--primary)',
            color: 'var(--primary-fg)',
            fontSize: '11.5px',
            fontWeight: '700',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
          }
          : {
            alignSelf: 'flex-start',
            padding: '1px 4px',
            fontSize: '11.5px',
            fontWeight: '600',
            color: kolom === 6 ? 'var(--danger)' : 'var(--text-2)',
          },
      }),
      isi,
    ]));
  }

  const sisa = (7 - (awal + jumlahHari) % 7) % 7;
  for (let i = 0; i < sisa; i++) sel.push(selKosong());

  body.appendChild(el('.card', { style: { overflow: 'hidden', marginBottom: '16px' } }, el('div', {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
      gap: '1px',
      background: 'var(--border)',
    },
  }, sel)));

  /* -------------------------------------------------------- cara kerjanya */
  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', {
        text: 'Kalender membaca sumber tanggal yang sama dengan pengawas tenggat — jatuh tempo termin dan '
          + 'invoice, masa berlaku penawaran, jaminan, PKWT, sertifikat — ditambah rencana yang bukan '
          + 'kewajiban: hari gajian, tutup buku, mulai dan target selesai proyek, jadwal serah terima (BAST), '
          + 'dan kunjungan PM. Kontrak dan proyek tampil sebagai dua agenda satu-hari (mulai dan berakhir), '
          + 'bukan balok rentang.',
      }),
      el('p', {
        text: 'Pembagian kerja dengan layar Tenggat: Tenggat menjawab "apa yang lewat atau menipis" dan '
          + 'barisnya hilang begitu penyebabnya beres; Kalender menjawab "kapan sesuatu terjadi" dan tetap '
          + 'menampilkan rencananya. Kalender hanya bisa menunjukkan yang bertanggal — termin tanpa '
          + '"Rencana tagih" tidak muncul di sini, dan Tenggat yang menagih kekosongannya.',
      }),
      el('p', {
        text: '"Hari ini" memakai jam server (WIB), bukan jam peramban: cincin biru dan tombol Hari ini '
          + 'sama-sama mengikuti tanggal yang dikirim server. Hari libur nasional belum ditandai — sumber '
          + 'datanya belum ada di sistem.',
      }),
      el('p', {
        text: 'Yang tampil disaring menurut izin lihat modul Anda — agenda milik modul lain dikirim ke '
          + 'pemegang izinnya masing-masing, jadi bulan yang kosong berarti benar-benar tidak ada agenda '
          + 'pada modul yang boleh Anda lihat.',
      }),
    ]),
  ]));
}
