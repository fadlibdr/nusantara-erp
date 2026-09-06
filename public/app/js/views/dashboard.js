/*
 * Dasbor yang bisa diatur (Fase 1 / P1-D).
 *
 * SEJAK PAKET INI BERKAS INI TIDAK MENGGAMBAR SATU ANGKA PUN. Ia menyusun:
 * membaca susunan orangnya (preferensi `dashboard.layout`, bawaan per peran
 * bila ia belum pernah mengatur), menggambar kerangka setiap widget dalam
 * urutan itu, lalu memanggil `views/widgets/<id>.js` per BATCH 4. Setiap angka,
 * setiap tabel, setiap cabang "gagal dimuat" hidup di berkas widget-nya
 * sendiri, dan DashboardTileFailureTest memindai berkas-berkas itu.
 *
 * KENAPA BATCH 4. Sampai P1-C dasbor menembakkan seluruh sumbernya sekaligus
 * dalam satu Promise.all — 11 permintaan untuk direktur, dan urutan
 * kedatangannya ditentukan server. Dengan 19 widget yang bisa dipilih sendiri
 * oleh pemakainya, "semua sekaligus" berarti seseorang yang menaruh 12 widget
 * membuka 12 koneksi dari ponsel lapangan dan tidak melihat apa pun sampai
 * yang paling lambat selesai. Empat sekaligus, urut susunan: yang di ATAS
 * layar terisi lebih dulu, dan jumlah permintaan serentak tidak pernah
 * bergantung pada berapa banyak widget yang dipasang orangnya.
 *
 * KERANGKA DULU, ISI MENYUSUL. Kartu setiap widget digambar SEBELUM
 * permintaannya berangkat, jadi tinggi halaman tidak melompat-lompat saat
 * jawaban berdatangan, dan sebuah widget yang lambat tidak menggeser widget di
 * bawahnya setelah orangnya mulai membaca.
 *
 * SATU WIDGET YANG GAGAL MEMUAT ULANG DIRINYA SENDIRI. Sampai P1-C tombol
 * "Coba lagi" pada kartu yang gagal berarti menggambar ulang SELURUH dasbor —
 * 11 permintaan untuk memperbaiki satu. Sekarang ctx.reload sebuah widget
 * hanya menyentuh kartunya.
 */

import { api, session } from '../api.js';
import { el, clear, button, icon } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';
import { prefs } from '../prefs.js';
import { SPAN, resolveLayout, defaultLayout, normalise, accentOf } from './widgets/registry.js';
import { openDashboardSetup } from './dashsetup.js';

/* #80 'Proyek saya': status sakelar dasbor, bertahan antar kunjungan. Hanya
   berarti bagi akun yang tertaut karyawan (users.employee_id →
   prj_projects.project_manager_id) — tanpa tautan itu server menjawab kosong
   dengan jujur, jadi sakelarnya tidak ditawarkan sama sekali.

   TETAP DI localStorage, bukan di preferensi server: ini bukan pilihan tentang
   SIAPA orangnya melainkan tentang apa yang sedang ia kerjakan di layar ini,
   dan ia berbalik beberapa kali sehari. Satu PUT per baliknya adalah harga
   yang tidak dibayar apa pun. */
const MINE_KEY = 'nusantara_erp_dash_mine';

/** Berapa widget yang permintaannya berangkat bersamaan. */
const BATCH = 4;

/* P-0b — spanduk penjadwal untuk pemegang core.update (orang yang membuka
   Pengaturan). Sumbernya GET core/health: `scheduler_status` ok | stale |
   unknown, umur detak jantung erp:heartbeat dalam detik, null = tidak
   diketahui. Aturan salinan 1: tidak pernah menyatakan penjadwal BERJALAN bila
   tidak diketahui — `ok` menggambar tidak apa-apa, `stale` menyebut sejak
   kapan, `unknown` dan permintaan yang gagal menulis `?`. Permintaan ini tidak
   menahan widget mana pun: slot kosong dulu, spanduk menyusul.

   BUKAN widget, dan sengaja tidak bisa dilepas dari laci "Atur dasbor": ia
   bukan angka yang dipilih orang untuk dibaca, melainkan kabar bahwa bagian
   dari sistemnya sedang mati. Sesuatu yang boleh disembunyikan pemakainya
   bukan alarm. */
function schedulerBanner() {
  const slot = el('div');
  const show = (text, { tone = 'warn', links = [] } = {}) => {
    slot.appendChild(el(`.alert.${tone}`, { style: { marginBottom: '14px' } }, [
      icon('warn', 16),
      el('div', [
        el('span', { text }),
        ...links.map(({ label, route }) => button(label, {
          size: 'sm', variant: 'ghost', onClick: () => navigate(route),
        })),
      ]),
    ]));
  };
  // null = tidak diketahui → `?`, tidak pernah 0.
  const count = (value) => (value === null || value === undefined ? '?' : String(value));

  api.get('core/health').then((health) => {
    const status = health ? health.scheduler_status : null;
    if (status === 'stale') {
      show(`Penjadwal tidak berjalan sejak ${fmt.dateTime(health.scheduler_heartbeat_at)} — akrual alat, jadwal PM, `
        + 'pengawas tenggat dan alarm cadangan berhenti. Periksa "systemctl status erp1-scheduler" di server.');
    } else if (status !== 'ok') {
      show('Penjadwal belum pernah melapor (detak jantung: ?) — tidak dapat dipastikan ia berjalan. '
        + 'Periksa "systemctl status erp1-scheduler" di server.');
    }

    /* Antrean (T0b.3/T0b.4): job gagal dan pengiriman yang > 1 jam antre tanpa
       disentuh pekerja (yang menunggu backoff tidak dihitung) menunjuk ke
       layarnya. Bukan spanduk peringatan — keadaan yang bisa diselesaikan dari
       aplikasi, dan tautannya ada di kalimatnya. */
    const failed = health ? health.failed_jobs_count : null;
    const stuck = health ? health.queued_deliveries_older_than_1h : null;
    if ((failed ?? 0) > 0 || (stuck ?? 0) > 0 || failed === null || stuck === null) {
      show(`Antrean: ${count(failed)} job gagal · ${count(stuck)} pengiriman notifikasi antre lebih dari 1 jam tanpa diambil pekerja.`, {
        tone: 'info',
        links: [
          { label: 'Antrean Gagal', route: 'r/core/queue/failed' },
          { label: 'Pengiriman Notifikasi', route: 'r/core/notification-deliveries' },
        ],
      });
    }
  }).catch((error) => {
    console.error('Dasbor: core/health gagal dimuat', error);
    show('Status penjadwal dan antrean tidak dapat diperiksa (?) — GET core/health gagal.');
  });

  return slot;
}

/** Kerangka kartu satu widget: kepala + badan yang diisi belakangan. */
function widgetCard(widget, size) {
  const body = el('.widget-body', el('.card-body', el('.skeleton', { style: { height: '64px' } })));
  const card = el('.card.widget', {
    dataset: { widget: widget.id, size, accent: String(accentOf(widget)) },
    style: { gridColumn: `span ${SPAN[size] || 1}` },
  }, [
    el('.card-head', [el('h2', { text: widget.title }), el('.spacer')]),
    body,
  ]);
  return { card, body };
}

/**
 * Nomor gambar dasbor yang sedang berlaku.
 *
 * renderDashboard() bisa dipanggil ulang kapan saja (tombol Muat ulang,
 * sakelar 'Proyek saya', laci yang menyimpan, preferensi yang menyusul), dan
 * gambar LAMA tidak berhenti sendiri: `clear(host)` melepaskan kartunya tetapi
 * perulangan batch-nya masih menunggu. Setiap gambar mencatat nomornya dan
 * berhenti begitu nomor itu bukan lagi yang berlaku.
 */
let generation = 0;

export async function renderDashboard(host) {
  const mine = ++generation;
  clear(host);

  const user = session.user || {};
  const can = (perm) => session.can(perm);
  const hour = new Date().getHours();
  const greeting = hour < 11 ? 'Selamat pagi' : hour < 15 ? 'Selamat siang' : hour < 19 ? 'Selamat sore' : 'Selamat malam';

  // Sakelar 'Proyek saya' hanya untuk akun yang tertaut karyawan: tanpa
  // employee_id, mine=1 memang kosong di server (akun itu tidak mengelola
  // proyek apa pun) — menawarkan sakelar yang selalu menjawab nol hanya akan
  // terbaca sebagai dasbor rusak.
  const mineCapable = can('prj.view') && Boolean(user.employee_id);
  const mineOnly = mineCapable && localStorage.getItem(MINE_KEY) === '1';

  /* Susunan orangnya. `prefs.has` — bukan `prefs.get(..., bawaan)` — supaya
     "belum pernah mengatur" dan "sudah mengatur, dan hasilnya kosong" tidak
     tertukar: yang kedua adalah pilihan sadar (dasbor sengaja dikosongkan) dan
     mengembalikannya diam-diam ke bawaan akan menghapus pilihan itu setiap
     kali halaman dibuka. */
  const chosen = prefs.has('dashboard.layout');
  const stored = chosen
    ? prefs.get('dashboard.layout', [])
    : defaultLayout(user.roles || [], can);
  const layout = resolveLayout(stored, can);

  const reloadAll = () => renderDashboard(host);

  /* PREFERENSI YANG MENYUSUL — dan kenapa dasbor ini pernah mengabaikan
     susunan tersimpan sepenuhnya pada kunjungan pertama.
     `boot()` menggambar #/dashboard SECARA SINKRON (landOnDefault() + start())
     dan baru sesudahnya merantai `refreshMe().then(() => prefs.load())`. Di
     peramban, perangkat atau profil BARU cermin localStorage kosong, jadi
     `prefs.has('dashboard.layout')` di atas menjawab false dan yang tergambar
     adalah BAWAAN PERAN — terukur 6 Sep 2026: baris server 3 kartu, layar 7
     kartu bawaan, dan tidak pernah berubah sampai kunjungan kedua ke layar
     yang sama.
     Pola pemulihannya sama persis dengan yang dipasang verifikasi P1-C di
     home.js dan module.js untuk bug yang sama pada launcher: yang mendengarkan
     menggambar ulang BAGIANNYA sendiri, sekali, dan hanya bila nilai yang tiba
     benar-benar berbeda dari yang sudah tergambar. */
  if (!prefs.loaded()) {
    const onPrefs = () => {
      window.removeEventListener('erp:prefs-loaded', onPrefs);

      // Gambar ini sudah digantikan gambar lain: yang itu punya pendengarnya
      // sendiri, dan dua gambar ulang untuk satu peristiwa adalah dua kali
      // seluruh permintaan dasbor.
      if (mine !== generation) return;

      const arrived = prefs.has('dashboard.layout') ? prefs.get('dashboard.layout', []) : null;
      if (arrived === null) return;
      if (JSON.stringify(normalise(arrived)) === JSON.stringify(normalise(stored))) return;

      renderDashboard(host);
    };
    window.addEventListener('erp:prefs-loaded', onPrefs);
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: `${greeting}, ${String(user.name || '').split(' ')[0]}` }),
      el('.desc', { text: `Ringkasan operasional per ${fmt.dateLong(new Date())}` }),
    ]),
    el('.actions', [
      mineCapable
        ? button('Proyek saya', {
          variant: mineOnly ? 'primary' : 'ghost',
          title: mineOnly
            ? 'Sedang menampilkan proyek yang Anda kelola — klik untuk semua proyek'
            : 'Saring widget proyek ke proyek yang Anda kelola',
          onClick: () => {
            localStorage.setItem(MINE_KEY, mineOnly ? '0' : '1');
            reloadAll();
          },
        })
        : null,
      /* Laci dibuka dari susunan TERSIMPAN, bukan dari apa yang tergambar.
         Dua sebab yang keduanya terukur (verifikasi P1-D):
         (a) sebelum prefs.load() selesai yang tergambar adalah bawaan peran,
             dan menyimpannya menimpa susunan yang ditata orangnya di
             perangkat lain — permanen, tanpa riwayat, dengan toast
             "tersimpan";
         (b) resolveLayout() sudah MEMBUANG entri yang izinnya tidak dipegang,
             jadi menyimpan hasilnya menghapusnya dari preferensi — kebalikan
             persis dari yang dijanjikan docblock resolveLayout. */
      button('Atur dasbor', {
        variant: 'ghost',
        title: 'Tambah, hapus, ubah ukuran dan urutan widget',
        onClick: () => openDashboardSetup(stored, reloadAll),
      }),
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reloadAll }),
    ]),
  ]));

  // Hanya pemegang core.update: rutenya bergerbang core.view, tetapi yang bisa
  // berbuat sesuatu tentang penjadwal mati adalah yang memegang server.
  if (can('core.update')) host.appendChild(schedulerBanner());

  if (!layout.length) {
    /* Dua sebab, satu kalimat masing-masing: dasbor yang dikosongkan sendiri
       (ada pintu kembalinya) dan peran tanpa satu widget pun yang boleh
       dilihat (tidak ada yang bisa ditawarkan). Metrik Fase 1 menuntut yang
       kedua tidak pernah terjadi pada 12 peran demo — DashboardDefaultsTest
       yang membuktikannya, bukan kalimat ini. */
    host.appendChild(el('.alert.info', [
      el('div', { style: { flex: '1' } }, prefs.has('dashboard.layout')
        ? 'Dasbor Anda sedang kosong. Buka "Atur dasbor" untuk menambahkan widget.'
        : 'Peran Anda belum memiliki akses ke widget dasbor mana pun.'),
      button('Atur dasbor', { size: 'sm', onClick: () => openDashboardSetup(stored, reloadAll) }),
    ]));
    return;
  }

  const grid = el('.dash-grid');
  host.appendChild(grid);

  /* Kerangka SEMUA widget lebih dulu, dalam urutan susunan. */
  const mounted = layout.map(({ widget, size }) => ({ widget, size, ...widgetCard(widget, size) }));
  mounted.forEach(({ card }) => grid.appendChild(card));

  const paint = async (slot) => {
    const { widget, body, card } = slot;
    const ctx = {
      size: slot.size,
      mineOnly,
      card,
      // Memuat ulang SATU widget; tombol "Coba lagi" pada kartunya memakainya.
      reload: () => paint(slot),
    };

    clear(body).appendChild(el('.card-body', el('.skeleton', { style: { height: '64px' } })));

    try {
      const module = await import(`./widgets/${widget.id}.js`);
      const node = await module.build(ctx);
      clear(body).appendChild(node);
    } catch (error) {
      /* Modul widget yang tidak bisa diimpor (berkas hilang saat deploy, galat
         sintaks di rilis) adalah kegagalan yang berbeda dari fetch yang gagal,
         dan tidak ada widget yang bisa melaporkannya karena kodenya tidak
         pernah jalan. Kartunya tetap ada dan mengaku — dasbor yang kehilangan
         satu kartu tanpa suara adalah pelajaran yang sama dengan Temuan 79. */
      console.error(`Dasbor: widget ${widget.id} gagal dimuat`, error);
      clear(body).appendChild(el('.card-body', el('.alert.error', { style: { margin: 0 } }, [
        icon('warn', 16),
        el('div', { text: `Widget "${widget.title}" tidak dapat dijalankan. Isinya tidak diketahui — jangan dibaca sebagai "tidak ada data".` }),
      ])));
    }
  };

  for (let start = 0; start < mounted.length; start += BATCH) {
    // Berurutan per batch, serentak DI DALAM batch. Satu widget yang lambat
    // menahan batch-nya sendiri, bukan seluruh halaman — dan tidak ada saat
    // pun ketika lebih dari BATCH permintaan dasbor berjalan bersamaan.
    await Promise.all(mounted.slice(start, start + BATCH).map(paint));
  }
}

/* Dibaca uji: satu-satunya sumber kebenaran tentang ukuran batch. */
export const DASHBOARD_BATCH = BATCH;
