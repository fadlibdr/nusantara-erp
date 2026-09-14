/*
 * Service worker SPA Nusantara ERP (P1-I). Lingkupnya /app/ — berkas ini
 * dilayani sebagai /app/sw.js, dan sebuah worker hanya boleh menguasai path di
 * bawah folder skripnya sendiri. Itu BUKAN kebetulan: lingkup /app/ persis
 * cangkang aplikasi, dan /api/ berada DI LUARNYA.
 *
 * ====================================================================
 *  ATURAN YANG TIDAK PERNAH DI-CACHE — RUJUKAN, bukan ringkasan
 * ====================================================================
 *  Worker ini MENJAWAB sebuah permintaan hanya bila permintaan itu memenuhi
 *  SEMUA syarat berikut (shellRequest di bawah):
 *
 *    1. metodenya GET;
 *    2. asalnya sama dengan asal worker (bukan lintas asal);
 *    3. path-nya dimulai dengan lingkup worker, yaitu '/app/';
 *    4. tidak membawa header Authorization;
 *    5. bukan berkas worker ini sendiri (/app/sw.js).
 *
 *  Permintaan yang tidak memenuhinya TIDAK DISENTUH: tanpa respondWith(),
 *  jadi peramban mengambilnya seolah worker ini tidak terpasang. Karena
 *  `/api/*`, `/storage/*` dan setiap unduhan lampiran TIDAK berada di bawah
 *  `/app/`, tidak ada satu jalur kode pun di berkas ini yang bisa menyimpan
 *  atau menyajikannya — bukan "cache paling akhir", melainkan TIDAK PERNAH.
 *
 *  Bentuknya sengaja DAFTAR IZIN, bukan daftar larangan. Daftar larangan
 *  membusuk: endpoint baru yang lupa didaftarkan langsung ikut ter-cache, dan
 *  kegagalannya diam — orang berikutnya di tablet lapangan yang dipakai
 *  bergantian membaca daftar milik orang sebelumnya. Daftar izin dengan satu
 *  awalan tidak bisa membusuk begitu; syarat 1–5 dipaku
 *  tests/Feature/Core/PwaServiceWorkerTest.
 *
 *  Yang boleh masuk cache lebih sempit lagi daripada yang dijawab: hanya
 *  jawaban 200 bertipe 'basic' (asal sama, bukan opaque, bukan 206 Range).
 *
 * ------------------------------------------------------------------
 *  STRATEGI: JARINGAN DULU
 * ------------------------------------------------------------------
 *  Setiap permintaan cangkang pergi ke jaringan lebih dulu; jawabannya
 *  disalin ke cache lalu diteruskan apa adanya. Cache dibaca HANYA ketika
 *  fetch() melempar — yaitu benar-benar tidak ada jaringan. Jawaban HTTP yang
 *  sah tetapi tidak menyenangkan (404 sesudah rilis membuang berkas, 401 dari
 *  gerbang HTTP) diteruskan apa adanya, TIDAK ditutupi salinan lama: cangkang
 *  basi yang menutupi jawaban server adalah persis kebohongan yang paket ini
 *  ada untuk mencegahnya.
 *
 *  Cangkang tidak memuat data siapa pun. Token sesi hidup di localStorage,
 *  yang tidak pernah disentuh worker; jadi cangkang ter-cache yang dibuka
 *  sesi yang sudah keluar hanya menggambar halaman masuk.
 *
 * ------------------------------------------------------------------
 *  VERSI CACHE
 * ------------------------------------------------------------------
 *  SHELL_VERSION dinaikkan pada setiap rilis yang mengubah berkas cangkang.
 *  Itu satu-satunya hal yang membuat peramban memasang worker baru (peramban
 *  membandingkan BYTE sw.js), dan karena itu satu-satunya hal yang memunculkan
 *  toast "Versi baru siap — Muat ulang" di tab yang sudah terbuka berhari-hari.
 *  Rilis yang lupa menaikkannya tidak menyesatkan siapa pun — jaringan-dulu
 *  tetap menyajikan kode terbaru kepada siapa saja yang memuat ulang — ia hanya
 *  tidak mengumumkan dirinya. Prosedurnya di CONVENTIONS § 21.
 *
 *  SHELL berisi SETIAP berkas yang dimuat peramban saat aplikasi berjalan
 *  (html/css/js/svg/webmanifest di bawah public/app, kecuali folder icons/ yang
 *  dibaca sistem operasi, bukan halaman). Daftarnya dipaku dua arah oleh
 *  tests/Feature/Core/PwaServiceWorkerTest: tidak boleh ada baris yang berkasnya
 *  hilang, dan tidak boleh ada berkas yang tidak tercatat — sebuah layar baru
 *  yang lupa didaftarkan akan membuat aplikasi ini setengah luring tanpa suara.
 */

const SHELL_VERSION = '15';
const CACHE = `nusantara-shell-v${SHELL_VERSION}`;

/** Lingkup worker: '/app/' bila berkas ini dilayani sebagai /app/sw.js. */
const SCOPE = new URL('./', self.location).pathname;

/*
 * Berkas yang harus ADA agar aplikasi bisa dibuka tanpa jaringan. Semua yang
 * lain (layar, widget) diimpor saat dibutuhkan: kehilangan satu di antaranya
 * berarti satu layar tidak bisa dibuka luring, bukan aplikasi yang membeku.
 *
 * Sampai verifikasi ulang P1-I (7 Sep 2026) cache dibuang bila SATU dari 102
 * entri gagal — dan pemicunya tidak eksotis: rsync yang belum selesai, satu 5xx
 * sesaat, satu permintaan jatuh. Terukur: satu berkas widget yang dimuat malas
 * tidak tersedia selama install → caches {}, ke-101 berkas lain ikut dibuang,
 * termasuk semua yang dibutuhkan untuk boot. Yang benar adalah membuang hanya
 * ketika yang hilang membuat boot mustahil.
 */
const CORE = ['./', 'index.html', 'app.css', 'js/app.js', 'js/api.js', 'js/ui.js', 'js/router.js', 'js/schema.js'];

const SHELL = [
  './',
  'app.css',
  'favicon.svg',
  'index.html',
  'js/api.js',
  'js/app.js',
  'js/cells.js',
  'js/charts.js',
  'js/combobox.js',
  'js/crumbs.js',
  'js/csv.js',
  'js/drafts.js',
  'js/enums.js',
  'js/format.js',
  'js/illustrations.js',
  'js/kalenderpalette.js',
  'js/lookup.js',
  'js/money.js',
  'js/notifications.js',
  'js/prefs.js',
  'js/print.js',
  'js/printcatalog.js',
  'js/router.js',
  'js/schema.js',
  'js/search.js',
  'js/ui.js',
  // F-4 — antrean kirim bersama (foto lampiran + absen masuk/pulang), dipindah
  // ke luar views/lapangan.js supaya kedua layar memakai satu perilaku.
  'js/uploadqueue.js',
  'js/vendorload.js',
  'js/views/absensi.js',
  // F-4 — layar ponsel "Absensi Saya".
  'js/views/absensisaya.js',
  'js/views/actions.js',
  // F-3 — kartu Aktivitas CRM di layar prospek/penawaran/pelanggan.
  'js/views/activities.js',
  'js/views/attachments.js',
  'js/views/bankrecon.js',
  'js/views/board.js',
  'js/views/bukubesar.js',
  'js/views/cashflow.js',
  // F-9 — kartu CSAT di layar tiket + layar ringkasan #/csat.
  'js/views/csat.js',
  'js/views/custom.js',
  'js/views/dashboard.js',
  'js/views/dashsetup.js',
  'js/views/defect.js',
  'js/views/detail.js',
  'js/views/dokumenimpor.js',
  'js/views/ekualisasi.js',
  'js/views/evm.js',
  'js/views/external.js',
  'js/views/form.js',
  'js/views/galeriproyek.js',
  'js/views/hargasatuan.js',
  'js/views/home.js',
  'js/views/jadwal.js',
  'js/views/k3.js',
  'js/views/kalender.js',
  'js/views/kalenderpajak.js',
  'js/views/kaskecil.js',
  'js/views/lapangan.js',
  'js/views/laporanbebas.js',
  'js/views/list.js',
  'js/views/masterdata.js',
  'js/views/module.js',
  'js/views/onboarding.js',
  'js/views/periods.js',
  'js/views/pipeline.js',
  'js/views/pooutstanding.js',
  // P-3a — Profil & Notifikasi (kanal luar, jam tenang, nomor WhatsApp).
  'js/views/profil.js',
  'js/views/project.js',
  // F-6 — pindai barcode item (jalur kamera + jalur ketik).
  'js/views/pindai.js',
  // F-6 — usulan pesan ulang dari kekurangan stok.
  'js/views/reorder.js',
  'js/views/rekapalat.js',
  // P-3b — rekap internal PPh 21/26 bulanan.
  'js/views/rekappph21.js',
  'js/views/reports.js',
  'js/views/retensi.js',
  'js/views/rfq.js',
  'js/views/sertifikat.js',
  'js/views/settings.js',
  'js/views/sewavsbeli.js',
  'js/views/siaptagih.js',
  'js/views/slabreaches.js',
  'js/views/taxexport.js',
  'js/views/tender.js',
  'js/views/tenggat.js',
  // F-5 — layar Timesheet & Lembur (HR) dan Timesheet Saya (pemiliknya sendiri).
  'js/views/timesheet.js',
  'js/views/ambang.js',
  'js/views/anggaran.js',
  'js/views/tugas.js',
  'js/views/tutupproyek.js',
  // F-4 — usulan rekap bulanan dari register absensi.
  'js/views/usulanrekap.js',
  'js/views/varian.js',
  'js/views/webhook.js',
  'js/views/widgets/aging.js',
  'js/views/widgets/ap-aging.js',
  'js/views/widgets/ar-aging.js',
  'js/views/widgets/defect.js',
  'js/views/widgets/evm.js',
  'js/views/widgets/inbox.js',
  'js/views/widgets/kalender.js',
  'js/views/widgets/kit.js',
  'js/views/widgets/ncr.js',
  'js/views/widgets/pajak.js',
  'js/views/widgets/payroll.js',
  'js/views/widgets/pipeline.js',
  'js/views/widgets/po-outstanding.js',
  'js/views/widgets/proyek-progres.js',
  'js/views/widgets/proyeksi-kas.js',
  'js/views/widgets/registry.js',
  'js/views/widgets/ringkasan-uang.js',
  'js/views/widgets/saldo-bank.js',
  'js/views/widgets/siap-tagih.js',
  'js/views/widgets/sla-tiket.js',
  'js/views/widgets/stok-minimum.js',
  'js/views/widgets/tenggat.js',
  'manifest.webmanifest',
  'vendor/lucide@1.41.0/sprite.svg',
  'vendor/sortablejs@1.15.7/Sortable.min.js',
];

/* ------------------------------------------------------------------ pasang */

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // Satu per satu dengan allSettled, BUKAN addAll: addAll menolak seluruhnya
    // bila satu berkas menjawab 404, dan install yang gagal berarti worker baru
    // tidak pernah aktif — satu berkas yang tertinggal saat rilis akan
    // membekukan pembaruan bagi semua orang. Yang gagal dicatat; uji dua arah
    // atas SHELL-lah yang menjaga daftar ini benar.
    const results = await Promise.allSettled(SHELL.map((path) => cache.add(path)));
    const failed = SHELL.filter((_, i) => results[i].status === 'rejected');
    const missingCore = failed.filter((path) => CORE.includes(path));

    if (failed.length && !missingCore.length) {
      // Cangkang tanpa satu-dua layar: setiap berkas CORE ada, jadi aplikasinya
      // TETAP bisa dibuka luring; yang hilang adalah layar yang gagal itu, dan
      // jalur fetch mengisinya pada kunjungan daring berikutnya.
      console.warn('[sw] cangkang tanpa', failed.length, 'berkas non-inti; lapisan luring tetap dipasang:', failed);
    }

    if (missingCore.length) {
      /*
       * CANGKANG SETENGAH LEBIH BURUK DARIPADA TIDAK ADA CANGKANG.
       *
       * Terukur 7 Sep 2026 dengan kuota origin dibatasi 1,2 MB (CDP
       * Storage.overrideQuotaForOrigin; cangkang ini ~2,1 MB): 42 dari 102
       * entri masuk, worker tetap aktif dan menguasai halaman. DARING semuanya
       * baik-baik saja — jaringan-dulu. LURING, muat ulang menyajikan
       * index.html dari cache sementara modul-modul yang hilang gagal dengan
       * net::ERR_FAILED, dan halamannya berhenti di pemutar boot: shell false,
       * body kosong, 1.532 char, tanpa satu kalimat pun kepada orangnya.
       *
       * Karena itu cache yang tidak lengkap dibuang seluruhnya. Perangkatnya
       * turun ke "tidak punya lapisan luring" — yang jujur dan bisa dipulihkan
       * sendiri pada kunjungan berikutnya yang muat — bukan ke "aplikasi
       * membeku saat sinyal hilang". Install-nya sendiri tetap BERHASIL (beda
       * dengan cache.addAll yang menolak seluruhnya dan membekukan pembaruan
       * bagi semua orang), jadi worker baru tetap dipasang dan rilis berikutnya
       * tetap bisa mengambil alih.
       *
       * Jaring pengaman kedua ada di index.html: pengawas boot yang mengganti
       * pemutar dengan kalimat, karena cache juga bisa terisi separuh lewat
       * jalur fetch di perangkat yang penyimpanannya sempit.
       */
      console.warn('[sw] berkas inti tidak sampai, cache dibuang:', missingCore, '(total gagal:', failed.length, ')');
      await caches.delete(CACHE);
    }
  })());
  // TIDAK skipWaiting(): worker baru menunggu sampai orangnya menekan
  // "Muat ulang" pada toast (app.js kirim pesan SKIP_WAITING). Mengambil alih
  // diam-diam berarti memuat ulang halaman di bawah tangan orang yang sedang
  // mengisi formulir.
});

/* ------------------------------------------------------------------ aktif */

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names
      .filter((name) => name.startsWith('nusantara-shell-') && name !== CACHE)
      .map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

/* --------------------------------------------------------------- pengambil */

/**
 * Syarat 1–5 dari rujukan di kepala berkas. Satu-satunya gerbang; tidak ada
 * jalan lain menuju cache.
 */
function shellRequest(request) {
  if (request.method !== 'GET') return false;
  if (request.headers.has('Authorization')) return false;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return false;
  if (!url.pathname.startsWith(SCOPE)) return false;
  if (url.pathname === self.location.pathname) return false;

  return true;
}

/** Hanya jawaban yang aman disimpan: 200, asal sama, bukan opaque, bukan 206. */
function storable(response) {
  return Boolean(response) && response.status === 200 && response.type === 'basic';
}

async function networkFirst(event) {
  const request = event.request;

  try {
    /*
     * fetch() DULU. Cache dibuka hanya ketika ada yang perlu disimpan (di
     * waitUntil, di luar jalur jawaban) atau ketika jaringannya melempar.
     *
     * Versi pertama menunggu `await caches.open(CACHE)` SEBELUM memulai fetch,
     * jadi setiap satu dari 76 permintaan cangkang membayar satu caches.open
     * sebelum satu byte pun diminta. Terukur 7 Sep 2026 pada kunjungan KEDUA
     * (worker sudah menguasai halaman), 14 putaran per varian, kedua varian
     * DISELANG-SELING putaran demi putaran supaya drift mesin mengenai
     * keduanya: cat pertama median 268 ms → 192 ms, loadEventEnd 338,5 ms →
     * 253,5 ms, permintaan cangkang terakhir selesai 334,5 ms → 251,5 ms
     * (76 permintaan di kedua varian).
     *
     * Strateginya, daftar izinnya dan storable() tidak berubah sedikit pun —
     * hanya urutan menunggunya. Uji memaku urutan itu: di dalam networkFirst,
     * fetch() harus dimulai sebelum caches.open() mana pun.
     */
    const response = await fetch(request);
    if (storable(response)) {
      /*
       * clone() SEKARANG, bukan di dalam .then(): badan jawaban hanya bisa
       * dibaca sekali, dan begitu `return response` menyerahkannya ke halaman,
       * clone() melempar "Response body is already used" — di dalam waitUntil,
       * jadi tidak ada yang melihatnya kecuali seluruh cangkang berhenti
       * tersimpan (76 tulisan gagal; luring kembali menjadi layar kosong).
       * Ditemukan verifikasi ulang P1-I, 7 Sep 2026, sebagai regresi dari
       * perbaikan urutan fetch-sebelum-caches.open di atas — urutan itu tetap,
       * yang pindah hanya salinannya.
       */
      const copy = response.clone();
      event.waitUntil(caches.open(CACHE).then((cache) => cache.put(request, copy)));
    }
    return response;
  } catch (error) {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;
    // Navigasi ke path /app/ mana pun jatuh ke cangkang: router aplikasi ini
    // memakai hash, jadi setiap halaman adalah index.html yang sama.
    if (request.mode === 'navigate') {
      const shell = await cache.match(SCOPE);
      if (shell) return shell;
    }
    throw error;
  }
}

self.addEventListener('fetch', (event) => {
  if (!shellRequest(event.request)) return;
  event.respondWith(networkFirst(event));
});

/* -------------------------------------------------------------- web push
 *
 * P-3e (T3e.5). TIGA pendengar baru, dan satu aturan yang berlaku untuk
 * ketiganya: TIDAK SATU PUN dari mereka menyentuh cache.
 *
 * Itu bukan kehati-hatian berlebihan. Aturan "tidak pernah di-cache" di kepala
 * berkas ini berbentuk daftar izin dengan SATU gerbang (shellRequest) dan SATU
 * tulisan; sebuah pendengar baru yang menulis ke cache adalah gerbang kedua
 * yang harus mengulang kelima syaratnya, dan gerbang kedua itulah bentuk
 * kebocoran yang paling mungkin ditulis paket berikutnya. Muatan push berisi
 * judul dan isi pemberitahuan seseorang; ia tidak boleh menyentuh penyimpanan
 * bersama di perangkat yang dipakai bergantian. PwaServiceWorkerTest memaku
 * ketiga badan pendengar ini bebas dari caches/.put(/permintaan ke API.
 */

self.addEventListener('push', (event) => {
  /*
   * SELALU menampilkan notifikasi — termasuk ketika muatannya hilang atau
   * tidak bisa dibaca.
   *
   * Itu kontrak `userVisibleOnly: true`: peramban yang menerima push dan TIDAK
   * melihat notifikasi muncul boleh mencabut langganan perangkat itu (Chrome
   * menghitungnya dan menampilkan "Situs ini diperbarui di latar belakang"
   * lebih dulu). Jadi muatan rusak dijawab kalimat umum, bukan diam: satu
   * kalimat yang kurang tepat jauh lebih murah daripada langganan yang dicabut
   * peramban tanpa ada yang tahu.
   */
  event.waitUntil((async () => {
    let isi = {};
    try {
      isi = event.data ? event.data.json() : {};
    } catch (error) {
      isi = {};
    }
    if (!isi || typeof isi !== 'object') isi = {};

    await self.registration.showNotification(isi.judul || 'Nusantara ERP', {
      body: isi.isi || 'Ada pemberitahuan baru. Buka Nusantara ERP untuk membacanya.',
      // tag = id notifikasinya: pemberitahuan yang sama yang sampai dua kali
      // MENIMPA yang lama alih-alih menumpuk dua baris identik.
      tag: isi.tag || 'nusantara-erp',
      icon: 'icons/icon-192.png',
      badge: 'icons/icon-192.png',
      data: { tautan: isi.tautan || null },
    });
  })());
});

/*
 * Tautan dari MUATAN push hanya dipakai bila ia berada di asal ini.
 *
 * Muatannya memang disusun server kita (WebPushChannel::payloadFor() selalu
 * memberi awalan config('app.url').'/app/'), tetapi yang menavigasi tab
 * aplikasi orang itu adalah baris di bawah — dan satu APP_URL yang salah di
 * .env, atau satu perubahan muatan di paket berikutnya, sudah cukup untuk
 * memindahkannya ke asal lain (putaran verifikasi: C-1). Nilai yang tidak bisa
 * diurai atau berada di asal lain jatuh ke SCOPE: membuka beranda aplikasi
 * selalu benar, membuka situs orang lain tidak pernah.
 */
function tautanAman(nilai) {
  try {
    const url = new URL(String(nilai || ''), self.location.href);
    return url.origin === self.location.origin ? url.href : SCOPE;
  } catch (error) {
    return SCOPE;
  }
}

self.addEventListener('notificationclick', (event) => {
  // Tutup dulu: notifikasi yang tetap menggantung sesudah diketuk adalah
  // notifikasi yang diketuk dua kali.
  event.notification.close();

  const tautan = tautanAman(event.notification.data && event.notification.data.tautan);

  event.waitUntil((async () => {
    // Tab yang SUDAH terbuka difokuskan, bukan ditimpa jendela baru: orang
    // yang sedang mengisi formulir di tab itu tidak boleh kehilangan isinya,
    // dan dua tab aplikasi yang sama adalah dua sesi yang membingungkan.
    const terbuka = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

    for (const klien of terbuka) {
      if (new URL(klien.url).pathname.startsWith(SCOPE)) {
        await klien.focus();
        if ('navigate' in klien) {
          try {
            await klien.navigate(tautan);
          } catch (error) {
            // Peramban yang menolak navigate() (lintas asal, klien tidak
            // dikuasai) tetap mendapat tab yang fokus — itu sudah lebih baik
            // daripada tidak terjadi apa-apa.
          }
        }
        return;
      }
    }

    await self.clients.openWindow(tautan);
  })());
});

self.addEventListener('pushsubscriptionchange', (event) => {
  /*
   * Peramban MEMUTAR langganannya sendiri (kunci kedaluwarsa, profil
   * dipulihkan). Tanpa pendengar ini, baris di server menunjuk endpoint mati
   * sampai layanan push menjawab 410 pada pengiriman berikutnya — yaitu satu
   * pemberitahuan yang hilang, diam-diam, per perangkat.
   *
   * Endpoint LAMA ikut dikirim supaya server MENGGANTI barisnya, bukan
   * menumpuk baris kedua yang membuat orangnya menerima dua kali.
   *
   * Alamatnya BUKAN endpoint bersesi: worker tidak bisa membaca token sesi
   * (ia ada di localStorage, yang tidak punya API di sini) dan peristiwa ini
   * menyala ketika tidak ada satu tab pun terbuka. Kapabilitasnya adalah
   * endpoint lama itu sendiri; server tidak pernah MEMBUAT baris dari
   * permintaan ini. Lihat Modules/Core/Http/Controllers/PushRotationController.
   */
  event.waitUntil((async () => {
    const lama = event.oldSubscription || null;
    if (!lama) return;

    let baru = event.newSubscription || null;

    if (!baru) {
      const kunci = lama.options ? lama.options.applicationServerKey : null;
      if (!kunci) return;
      baru = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: kunci });
    }

    const isi = baru.toJSON();

    await fetch(new URL('../push/rotate', self.location), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ old_endpoint: lama.endpoint, endpoint: isi.endpoint, keys: isi.keys }),
    });
  })());
});

/* ---------------------------------------------------------------- pesan */

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});
