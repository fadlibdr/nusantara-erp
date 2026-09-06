/*
 * Katalog widget dasbor + susunan bawaan per peran (Fase 1 / P1-D, T4.1).
 *
 * SATU daftar deklaratif, dalam selera ModuleCounts / WatchedDeadlines /
 * UserPreferences: widget berikutnya adalah satu entri array plus satu berkas
 * di `views/widgets/<id>.js` — tidak pernah endpoint baru, tidak pernah kolom
 * baru. Seluruh P1-D berdiri di atas endpoint yang SUDAH ADA; itu batasan yang
 * dipilih, bukan kebetulan (lihat § Deviasi pada LAPORAN-PAKET-HM-P1-D).
 *
 * KENAPA METADATA DI SINI DAN KODENYA DI SANA. Laci "Atur dasbor" harus
 * menawarkan 18 widget termasuk yang tidak sedang dipakai; kalau judul dan
 * izinnya hidup di dalam berkas widget-nya, membuka laci berarti mengunduh 18
 * modul yang 14 di antaranya tidak akan digambar. Maka: nama, izin, ukuran dan
 * pintu keluarnya di SINI (satu berkas, selalu dimuat), penggambarnya di
 * `./<id>.js` yang diimpor DINAMIS hanya bila widget-nya benar-benar ada di
 * susunan orangnya. Diukur pada susunan bawaan: teknisi memuat 4 modul widget,
 * bukan 18.
 *
 * `perm` DIBACA DUA SISI. Nilainya sengaja string biasa (atau null), bukan
 * predikat: `Modules\Core\Support\SpaWidgets` membacanya dari berkas ini dengan
 * regex yang sama seperti SpaNav membaca NAV, dan DashboardDefaultsTest
 * memakainya untuk membuktikan — terhadap RoleSeeder::intended() yang asli —
 * bahwa setiap peran demo mendapat sedikitnya satu widget. Sebuah predikat
 * JavaScript tidak bisa dibuktikan begitu; '*.approve' bisa, dan diterjemahkan
 * ke ANY_APPROVE di bawah.
 *
 * UKURAN. 'kecil' 1 kolom, 'sedang' 2, 'lebar' seluruh baris. Setiap widget
 * menyatakan ukuran mana yang MASUK AKAL untuknya (`sizes`): ubin angka
 * tunggal yang dipaksa selebar layar hanya menyisakan ruang kosong, dan tabel
 * tujuh kolom di satu kolom menggulung mendatar.
 */

import { MODULES, ANY_APPROVE } from '../../schema.js';

export const SIZES = ['kecil', 'sedang', 'lebar'];

/**
 * Lebar kolom per ukuran; dipakai dashboard.js sebagai `grid-column: span N`
 * atas kisi EMPAT kolom.
 *
 * Kenapa empat dan bukan tiga. 'sedang' adalah ukuran yang dipakai sebagian
 * besar widget, dan di kisi tiga kolom dua widget 'sedang' TIDAK MUAT
 * bersebelahan (2 + 2 > 3): terukur 6 Sep 2026 pada susunan bawaan direktur,
 * tiga baris berturut-turut menyisakan sepertiga layar kosong di kanannya.
 * Dengan empat kolom, dua 'sedang' mengisi satu baris penuh, 'kecil' + 'kecil'
 * + 'sedang' juga, dan 'lebar' tetap satu baris utuh — tanpa `grid-auto-flow:
 * dense`, yang akan menyusun ulang kartu dan mematahkan urutan yang justru
 * dipilih sendiri oleh pemakainya.
 */
export const SPAN = { kecil: 1, sedang: 2, lebar: 4 };

/**
 * 19 widget, urut sebagaimana laci "Atur dasbor" menawarkannya (kelompok:
 * ringkasan → keuangan → proyek & mutu → rantai pasok & layanan → SDM).
 *
 * KENAPA 19 DAN BUKAN 18. ROADMAP-HASHMICRO menulis "18 widget atas endpoint
 * YANG SUDAH ADA" beserta daftar isi yang berakhir dengan "…", dan dua entri
 * daftar itu tidak punya endpoint: "RAP vs realisasi" tingkat portofolio baru
 * dibangun F-2 (Fase 2 memang memilikinya), sedangkan ubin uang dasbor P1-C —
 * proyek berjalan, piutang, hutang, ketiganya dijumlah di SQL sejak Temuan 79 —
 * tidak disebut daftar itu sama sekali dan akan HILANG bila katalog ini
 * berhenti di 18. Menghapus ubin Temuan 79 demi angka 18 adalah pertukaran yang
 * salah arah; deviasinya dicatat di LAPORAN-PAKET-HM-P1-D.
 *
 * Kunci per entri:
 *   id      — nama berkas `./<id>.js` DAN kunci di preferensi dashboard.layout.
 *   title   — judul kartu, apa adanya.
 *   desc    — satu kalimat di laci: apa yang dihitung widget ini.
 *   module  — prefix modul (schema.js MODULES) → aksen warna dan pengelompokan.
 *   perm    — izin yang HARUS dipegang; null = siapa pun yang punya sesi,
 *             '*.approve' = pemegang izin `.approve` mana pun, dan sebuah
 *             DAFTAR berarti "salah satu saja cukup" (session.can menerima
 *             ketiga bentuk apa adanya). Bentuk daftar hanya dipakai widget
 *             yang bloknya disaring server per izin, seperti ringkasan-uang.
 *   route   — layar yang memuat angka ini secara lengkap; kaki kartu ke sana.
 *   sizes   — ukuran yang masuk akal, urut dari yang paling sempit.
 *   size    — ukuran bawaan bila susunan tidak menyebutkannya.
 */
export const CATALOG = [
  {
    id: 'ringkasan-uang',
    title: 'Proyek, piutang & hutang',
    desc: 'Tiga angka uang yang dijumlah server atas seluruh tabel: proyek berjalan, piutang, hutang.',
    module: 'ringkasan', perm: ['prj.view', 'fin.view'], route: 'r/projects',
    sizes: ['sedang', 'lebar'], size: 'lebar',
  },
  {
    id: 'inbox',
    title: 'Menunggu persetujuan Anda',
    desc: 'Dokumen dari 28 registri yang menunggu tanda tangan Anda, terurut umur antrean.',
    module: 'ringkasan', perm: '*.approve', route: 'tugas',
    sizes: ['sedang', 'lebar'], size: 'lebar',
  },
  {
    id: 'tenggat',
    title: 'Tenggat menipis & lewat',
    desc: 'Tanggal yang masuk jendela peringatannya di seluruh modul yang boleh Anda lihat.',
    module: 'ringkasan', perm: null, route: 'tenggat',
    sizes: ['kecil', 'sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'kalender',
    title: 'Kalender acara',
    desc: 'Agenda bulan berjalan per departemen, dengan lima yang terdekat.',
    module: 'ringkasan', perm: null, route: 'kalender',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'ar-aging',
    title: 'Umur piutang',
    desc: 'Sisa tagihan pelanggan per keranjang umur, dijumlah server dari seluruh dokumen terbuka.',
    module: 'fin', perm: 'fin.view', route: 'reports',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'ap-aging',
    title: 'Umur hutang',
    desc: 'Sisa tagihan vendor per keranjang umur — pasangan umur piutang.',
    module: 'fin', perm: 'fin.view', route: 'reports',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'proyeksi-kas',
    title: 'Proyeksi kas',
    desc: 'Saldo berjalan 90 hari ke depan: titik terendah dan minggu terjadinya.',
    module: 'fin', perm: 'fin.view', route: 'reports',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'saldo-bank',
    title: 'Saldo bank',
    desc: 'Saldo per rekening, rekening negatif disebut namanya.',
    module: 'fin', perm: 'fin.view', route: 'r/finance/bank-accounts',
    sizes: ['kecil', 'sedang'], size: 'kecil',
  },
  {
    id: 'siap-tagih',
    title: 'Termin siap ditagih',
    desc: 'Pekerjaan yang sudah berhak ditagih dan belum ditagih, dengan umur tunggu terlama.',
    module: 'fin', perm: 'fin.view', route: 'siap-tagih',
    sizes: ['kecil', 'sedang'], size: 'kecil',
  },
  {
    id: 'pajak',
    title: 'Kewajiban pajak',
    desc: 'Masa pajak tahun berjalan yang belum dilaporkan atau belum disetor.',
    module: 'fin', perm: 'fin.view', route: 'kalender-pajak',
    sizes: ['kecil', 'sedang'], size: 'sedang',
  },
  {
    id: 'evm',
    title: 'Kinerja EVM portofolio',
    desc: 'SPI dan CPI seluruh proyek berbaseline, dengan jumlah proyek yang belum terukur.',
    module: 'prj', perm: 'prj.view', route: 'evm',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'proyek-progres',
    title: 'Progres proyek',
    desc: 'Progres aktual terhadap rencana untuk proyek yang sedang berjalan.',
    module: 'prj', perm: 'prj.view', route: 'r/projects',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'defect',
    title: 'Temuan lapangan (defect)',
    desc: 'Temuan terbuka, yang menahan BAST II, dan yang lewat target perbaikan.',
    module: 'prj', perm: 'prj.view', route: 'defects',
    sizes: ['kecil', 'sedang'], size: 'sedang',
  },
  {
    id: 'ncr',
    title: 'NCR terbuka',
    desc: 'Ketidaksesuaian mutu yang masih terbuka atau sedang dikoreksi.',
    module: 'qc', perm: 'qc.view', route: 'r/quality/ncr',
    sizes: ['kecil', 'sedang'], size: 'kecil',
  },
  {
    id: 'stok-minimum',
    title: 'Stok di bawah minimum',
    desc: 'Item per gudang yang saldonya di bawah stok minimum yang ditetapkan.',
    module: 'inv', perm: 'inv.view', route: 'stock',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'po-outstanding',
    title: 'PO belum diterima penuh',
    desc: 'Baris PO disetujui yang belum lengkap barangnya, dan berapa yang lewat batas kirim.',
    module: 'prc', perm: 'prc.view', route: 'po-outstanding',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'sla-tiket',
    title: 'Tiket lewat SLA',
    desc: 'Tiket layanan yang belum selesai dan sudah melewati janji ke pelanggan.',
    module: 'svc', perm: 'svc.view', route: 'sla-breaches',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
  {
    id: 'pipeline',
    title: 'Pipeline penjualan',
    desc: 'Win-rate dan nilai penawaran yang masih berjalan, menang, dan kalah.',
    module: 'crm', perm: 'crm.view', route: 'pipeline',
    sizes: ['kecil', 'sedang'], size: 'sedang',
  },
  {
    id: 'payroll',
    title: 'Payroll bulan berjalan',
    desc: 'Run payroll terbaru: status, bruto, potongan, dan netto yang akan dibayar.',
    module: 'hr', perm: 'hr.view', route: 'r/hr/payroll-runs',
    sizes: ['sedang', 'lebar'], size: 'sedang',
  },
];

/** id → entri katalog. */
export const BY_ID = Object.fromEntries(CATALOG.map((widget) => [widget.id, widget]));

/**
 * Susunan bawaan per peran (T4.1), ditulis `id:ukuran`.
 *
 * SATU BARIS PER PERAN, dan peran-perannya persis yang diseed RoleSeeder
 * ::intended(). Kesetaraan kedua daftar itu dipaku DashboardDefaultsTest, yang
 * juga membuktikan hal yang jauh lebih penting: setiap peran, DENGAN IZIN
 * PERAN ITU SENDIRI, mendapat sedikitnya satu widget. Metrik Fase 1 menuntut
 * "0 peran tanpa ubin"; P1-C mencapainya di launcher dengan cara mengukur 12
 * akun demo satu per satu, dan pengukuran seperti itu basi pada sunting
 * berikutnya. Di sini ia menjadi uji.
 *
 * Susunan ini BUKAN batas: laci "Atur dasbor" menawarkan seluruh katalog yang
 * izinnya dipegang. Ia hanya jawaban untuk "apa yang dilihat orang ini pada
 * hari pertama, sebelum ia pernah mengatur apa pun".
 */
export const DEFAULTS = {
  admin: ['ringkasan-uang:lebar', 'inbox:lebar', 'tenggat:sedang', 'proyeksi-kas:sedang', 'evm:sedang', 'stok-minimum:sedang', 'sla-tiket:sedang', 'kalender:sedang'],
  direktur: ['ringkasan-uang:lebar', 'inbox:lebar', 'proyeksi-kas:sedang', 'ar-aging:sedang', 'evm:sedang', 'siap-tagih:kecil', 'saldo-bank:kecil', 'pipeline:sedang', 'tenggat:sedang'],
  'project-manager': ['ringkasan-uang:lebar', 'inbox:lebar', 'proyek-progres:sedang', 'evm:sedang', 'defect:sedang', 'ncr:kecil', 'stok-minimum:sedang', 'tenggat:sedang'],
  'site-manager': ['proyek-progres:sedang', 'defect:sedang', 'ncr:kecil', 'tenggat:sedang', 'kalender:sedang'],
  estimator: ['proyek-progres:sedang', 'evm:sedang', 'tenggat:sedang', 'kalender:sedang'],
  procurement: ['po-outstanding:lebar', 'stok-minimum:sedang', 'tenggat:sedang', 'kalender:sedang'],
  warehouse: ['stok-minimum:lebar', 'ringkasan-uang:sedang', 'proyek-progres:sedang', 'tenggat:sedang', 'kalender:sedang'],
  finance: ['ringkasan-uang:lebar', 'ar-aging:sedang', 'ap-aging:sedang', 'proyeksi-kas:sedang', 'siap-tagih:kecil', 'saldo-bank:kecil', 'pajak:sedang', 'payroll:sedang', 'tenggat:sedang'],
  'finance-manager': ['ringkasan-uang:lebar', 'inbox:lebar', 'ar-aging:sedang', 'ap-aging:sedang', 'proyeksi-kas:sedang', 'saldo-bank:kecil', 'tenggat:sedang'],
  hr: ['payroll:lebar', 'tenggat:sedang', 'kalender:sedang'],
  sales: ['pipeline:sedang', 'proyek-progres:sedang', 'sla-tiket:sedang', 'tenggat:sedang', 'kalender:sedang'],
  teknisi: ['sla-tiket:lebar', 'stok-minimum:sedang', 'tenggat:sedang', 'kalender:sedang'],
};

/** Berapa widget yang dipilihkan untuk peran yang TIDAK ada di DEFAULTS. */
const FALLBACK_MAX = 6;

/** Predikat izin sebuah widget, dalam bentuk yang dimengerti session.can(). */
export function permOf(widget) {
  // '*.approve' adalah tulisan yang bisa dibaca PHP untuk predikat yang sudah
  // dipakai sidebar, Ctrl+K dan kartu persetujuan — bukan aturan kedua.
  if (widget.perm === '*.approve') return ANY_APPROVE;
  return widget.perm;
}

/** Aksen modul widget ini (P1-B --accent-1..8); 8 = netral bila prefix asing. */
export function accentOf(widget) {
  const module = MODULES[widget.module];
  return module ? module.accent : 8;
}

/**
 * Susunan tersimpan → daftar {widget, size} yang boleh digambar orang ini.
 *
 * Entri yang izinnya tidak dipegang DIBUANG dari gambar tetapi TIDAK dihapus
 * dari preferensinya — aturan yang sama dengan prefs.visibleRecent(): izin bisa
 * kembali, dan daftar tersimpan bukan milik penggambar. Entri yang id-nya sudah
 * tidak ada di katalog (widget dicabut di rilis berikutnya) ikut dilewati
 * diam-diam; ia tidak punya apa pun untuk digambar.
 */
export function resolveLayout(stored, can) {
  const seen = new Set();
  const out = [];

  for (const entry of normalise(stored)) {
    const widget = BY_ID[entry.id];
    if (!widget || seen.has(entry.id)) continue;
    seen.add(entry.id);
    if (!can(permOf(widget))) continue;
    out.push({ widget, size: widget.sizes.includes(entry.size) ? entry.size : widget.size });
  }

  return out;
}

/**
 * Bentuk apa pun yang tersimpan → list {id, size}. Dibuat toleran dengan
 * sengaja: nilai yang tersimpan hari ini boleh dibaca oleh SPA versi berikutnya,
 * dan sebuah baris preferensi yang tidak bisa dibaca akan mengosongkan dasbor
 * seseorang tanpa ia pernah menyentuh apa pun.
 */
export function normalise(stored) {
  if (!Array.isArray(stored)) return [];
  return stored
    .map((entry) => {
      if (typeof entry === 'string') {
        const [id, size] = entry.split(':');
        return { id, size };
      }
      if (entry && typeof entry === 'object' && typeof entry.id === 'string') {
        return { id: entry.id, size: typeof entry.size === 'string' ? entry.size : null };
      }
      return null;
    })
    .filter((entry) => entry && entry.id);
}

/** Susunan bawaan orang ini: perannya bila dikenal, jika tidak dari izinnya. */
export function defaultLayout(roles, can) {
  for (const role of roles || []) {
    if (DEFAULTS[role]) return normalise(DEFAULTS[role]);
  }

  /* Peran buatan pemilik (layar Peran memang membolehkannya) tidak punya baris
     di DEFAULTS, dan dasbor kosong untuk peran yang izinnya lengkap adalah
     kemunduran dari dasbor P1-C yang selalu menggambar sesuatu. Katalog urut,
     disaring izin, dipotong FALLBACK_MAX. */
  return CATALOG
    .filter((widget) => can(permOf(widget)))
    .slice(0, FALLBACK_MAX)
    .map((widget) => ({ id: widget.id, size: widget.size }));
}

/** Bentuk yang DISIMPAN ke preferensi — sependek mungkin, plafonnya 16 KB. */
export function toStored(layout) {
  return layout.map(({ widget, size }) => ({ id: widget.id, size }));
}
