/*
 * Preferensi pengguna (P1-C, T1C.1) — server sebagai kebenaran, localStorage
 * sebagai CERMIN.
 *
 * Sampai P1-B favorit, "Terakhir dibuka" dan kepadatan hidup di localStorage
 * berkunci id pengguna. Itu benar untuk tablet lapangan yang dipakai bergantian
 * (kasir tidak mewarisi lima dokumen pengawas) tetapi salah untuk ORANGNYA:
 * bintang yang dipasang di desktop kantor tidak ada di tabletnya, dan peramban
 * yang dibersihkan menghapus semuanya. Sejak paket ini yang menyimpan adalah
 * `core_user_preferences`.
 *
 * KENAPA CERMIN LOKAL TETAP ADA — tiga alasan, semuanya terukur:
 *  1. Kepadatan dipasang di <html> SEBELUM shell digambar (P1-B: tanpa itu ada
 *     kedipan normal → padat). Jawaban server datang sesudah refreshMe(); cermin
 *     menjawab seketika.
 *  2. Luring. Antrean Lapangan bekerja tanpa jaringan; sidebar favoritnya tidak
 *     boleh kosong di sana.
 *  3. 401. Sesi berakhir di tengah kerja — yang sudah dipilih orangnya tetap
 *     berlaku di layar ini sampai ia masuk lagi.
 * Cermin TIDAK PERNAH menang atas server: load() menimpa cermin dengan jawaban
 * server, dan hanya kunci yang server BELUM punya barisnya yang dinaikkan dari
 * lokal (satu kali, lalu kunci lamanya dihapus).
 *
 * Bawaan ada DI SINI, bukan di server: baris `density: 'normal'` yang ditulis
 * server berbohong bahwa orangnya pernah memilih. get(key, fallback) yang
 * memutuskan.
 */

import { api, session } from './api.js';
import { RESOURCES } from './schema.js';

const MIRROR_KEY = 'nusantara_erp_prefs';

/*
 * Kunci localStorage P1-B yang dinaikkan sekali lalu dihapus. Namanya ditulis
 * di sini persis seperti di app.js sebelum paket ini — LAPORAN P1-B § "Yang
 * sengaja tidak dikerjakan" menyebutnya supaya migrasi ini bisa ditulis.
 */
const LEGACY = { favorites: 'nusantara_erp_fav', recent: 'nusantara_erp_recent', density: 'nusantara_erp_density' };

const DENSITIES = ['compact', 'normal', 'comfortable'];
/* Field yang boleh ada di satu entri "Terakhir dibuka" — cermin dari
   UserPreferences::RECENT_FIELDS; entri lama tanpa `at` tetap sah. */
const RECENT_FIELDS = ['route', 'label', 'sub', 'at'];

/*
 * Plafon jumlah entri datang DARI SERVER: load() membaca meta.keys[].max_entries
 * milik jawaban core/me/preferences. Angka di bawah hanyalah nilai yang berlaku
 * SEBELUM jawaban pertama tiba (bintang bisa dipasang saat itu) dan bila server
 * tidak menyebutkan kuncinya.
 *
 * Kenapa dibaca dan bukan ditulis dua kali: sampai verifikasi P1-C (6 Sep 2026)
 * 50 dan 20 ditulis di whitelist server DAN di sini, sementara meta yang ada
 * justru "supaya klien tidak menyalin daftarnya" tidak pernah dibaca satu baris
 * pun — dan angka yang diumumkannya (16384) bukan angka yang berlaku untuk
 * kedua kunci ini (4096 dan 8192).
 */
const LIMIT_DEFAULTS = { favorites: 50, recent: 20 };
const limits = { ...LIMIT_DEFAULTS };

function limitOf(key) {
  return limits[key] || LIMIT_DEFAULTS[key];
}

/** meta.keys server → plafon yang dipakai penulis di bawah. */
function readLimits(meta) {
  const keys = meta && Array.isArray(meta.keys) ? meta.keys : [];
  for (const entry of keys) {
    if (entry && typeof entry.key === 'string' && Number.isInteger(entry.max_entries)) limits[entry.key] = entry.max_entries;
  }
}

/** key → value. Objek yang SAMA sepanjang hidup halaman: session.prefs menunjuk ke sini. */
const state = {};

/** Kunci yang SERVER punya barisnya (null = belum pernah dimuat dari server). */
let serverKeys = null;

/**
 * `load()` sudah SELESAI — dijawab server atau menyerah (luring, 401).
 *
 * Bukan sama dengan `serverKeys !== null`: yang menyerah tidak punya kunci
 * server tetapi tetap selesai, dan penulis yang menunggu "server sudah
 * menjawab" akan menunggu selamanya di tablet lapangan yang luring. Yang
 * membaca ini adalah penggambar yang harus tahu bahwa cerminnya sudah
 * sepadan dengan yang bisa didapat — dasbor P1-D memakainya untuk tidak
 * MENIMPA susunan orangnya dengan bawaan peran (verifikasi P1-D).
 */
let settled = false;

/** Id pengguna yang cerminnya sedang dimuat — masuk sebagai orang lain memuat ulang. */
let mirrorFor = Symbol('belum');

/** PUT per kunci dirantai: dua klik bintang cepat tidak boleh mendarat terbalik. */
const chains = {};

function userId() {
  return (session.user || {}).id ?? 'anon';
}

function personalKey(base) {
  return `${base}:${userId()}`;
}

function readMirror() {
  const id = userId();
  if (mirrorFor === id) return;
  mirrorFor = id;
  for (const key of Object.keys(state)) delete state[key];
  let stored = null;
  try {
    stored = JSON.parse(localStorage.getItem(personalKey(MIRROR_KEY)) || 'null');
  } catch {
    stored = null;
  }
  if (stored && typeof stored === 'object' && !Array.isArray(stored)) Object.assign(state, stored);
}

function writeMirror() {
  try {
    localStorage.setItem(personalKey(MIRROR_KEY), JSON.stringify(state));
  } catch {
    /* kuota penuh: cermin hilang, server tetap punya jawabannya */
  }
}

/* ------------------------------------------------------------------ bacaan */

/**
 * Nilai preferensi, atau `fallback` bila orangnya belum pernah memilih.
 * Sinkron: cermin sudah ada sebelum permintaan pertama selesai.
 */
function get(key, fallback = null) {
  readMirror();
  return Object.prototype.hasOwnProperty.call(state, key) ? state[key] : fallback;
}

/** Sudah pernah dipilih (bukan "kebetulan sama dengan bawaan")? */
function has(key) {
  readMirror();
  return Object.prototype.hasOwnProperty.call(state, key);
}

/* ----------------------------------------------------------------- tulisan */

/**
 * Simpan optimistis: cermin dan pembacanya berubah SEKARANG, PUT menyusul.
 * Gagal (luring, 401, 422) tidak mengembalikan nilainya — yang dipilih orangnya
 * tetap berlaku di layar ini; jawaban server pada boot berikutnya yang menang.
 */
function set(key, value) {
  readMirror();
  state[key] = value;
  writeMirror();

  chains[key] = Promise.resolve(chains[key])
    .catch(() => {})
    .then(() => api.put(`core/me/preferences/${key}`, { value }))
    .then(() => { if (serverKeys) serverKeys.add(key); })
    .catch((error) => {
      // 422 = nilai yang ditolak whitelist: itu bug kita, bukan keadaan
      // pemakai, jadi tercatat di konsol dan bukan toast di wajahnya.
      if (error && error.status === 422) console.error('Preferensi ditolak server', key, error.message);
      return null;
    });

  return chains[key];
}

/* -------------------------------------------------------------------- muat */

/**
 * Dipanggil SEKALI di boot, sesudah refreshMe() (izin dan id pengguna sudah
 * yang terbaru). Jawaban server menimpa cermin; kunci yang server belum punya
 * barisnya dinaikkan dari localStorage P1-B, sekali.
 */
async function load() {
  readMirror();

  let payload = null;
  try {
    // api.list, bukan api.get: yang kedua membuang meta — dan meta membawa
    // plafon per kunci yang dipakai penulis di bawah.
    payload = await api.list('core/me/preferences');
  } catch {
    /* Luring atau sesi berakhir: cermin yang berlaku, dan tidak ada yang
       dinaikkan (menaikkan tanpa tahu isi server bisa menimpa pilihan yang
       dibuat di perangkat lain).

       TETAP mengumumkan, dan tetap `settled`: yang mendengarkan harus tahu
       bahwa tidak akan ada jawaban lagi. Tanpa ini penggambar yang menunggu
       preferensi (dasbor P1-D) menunggu selamanya di tablet lapangan. */
    settled = true;
    announce('erp:prefs-loaded', { keys: Object.keys(state), from: 'cermin' });
    return state;
  }

  readLimits(payload && payload.meta);
  const rows = payload && Array.isArray(payload.data) ? payload.data : [];

  serverKeys = new Set();
  for (const row of rows) {
    if (!row || typeof row.key !== 'string') continue;
    serverKeys.add(row.key);
    state[row.key] = row.value;
  }
  /* Kunci yang ada di cermin tetapi TIDAK di server sengaja dibiarkan hidup.
     Tidak ada endpoint yang menghapus preferensi, jadi kunci semacam itu hanya
     bisa berarti satu hal: set() yang PUT-nya belum pernah sampai (luring,
     401). Membuangnya di sini berarti membuang pilihan yang baru saja dibuat
     orangnya, justru pada boot pertama setelah jaringannya kembali. */

  await liftLegacy();

  /* …dan yang tersisa di cermin tanpa baris server dicoba kirim lagi: satu-
     satunya cara sebuah kunci ada di sini tanpa ada di sana adalah set() yang
     PUT-nya tidak pernah sampai (luring, sesi berakhir). Tanpa baris ini
     pilihan itu hidup di satu peramban selamanya dan tidak pernah menyusul
     orangnya ke perangkat lain. */
  for (const key of Object.keys(state)) {
    if (!serverKeys.has(key)) set(key, state[key]);
  }

  writeMirror();
  /* Layar yang SUDAH tergambar sebelum jawaban ini tiba menggambar favorit dan
     "Terakhir dibuka" dari cermin — dan di peramban baru cermin itu kosong.
     Diukur 6 Sep 2026 (S22 ponsel, konteks peramban baru): landing #/home
     digambar saat boot, prefs.load() selesai sesudahnya, dan baris Favorit
     tidak pernah muncul walau sidebar (yang digambar ulang refreshNav) sudah
     memuatnya. Yang mendengarkan menggambar ulang bagiannya sendiri; tidak ada
     yang me-resolve ulang rutenya, karena itu berarti setiap permintaan layar
     dijalankan dua kali. */
  settled = true;
  announce('erp:prefs-loaded', { keys: Object.keys(state), from: 'server' });
  return state;
}

/**
 * Migrasi satu kali dari localStorage P1-B. Per KUNCI, bukan per himpunan:
 * seseorang yang sudah memilih kepadatan di ponsel dan baru sekarang masuk dari
 * desktop tidak boleh kehilangan pilihan itu karena desktopnya punya favorit
 * lokal. Aturannya satu kalimat — server yang sudah punya barisnya MENANG, dan
 * kunci lokal hanya dihapus setelah server benar-benar punya nilainya.
 */
async function liftLegacy() {
  for (const [key, base] of Object.entries(LEGACY)) {
    const raw = localStorage.getItem(personalKey(base));
    if (raw === null) continue;

    if (serverKeys.has(key)) {
      // Sudah ada di server (dipilih di perangkat lain): salinan lokal ini
      // sudah tidak berarti apa-apa.
      localStorage.removeItem(personalKey(base));
      continue;
    }

    const value = fromLegacy(key, raw);
    if (value === null) {
      localStorage.removeItem(personalKey(base)); // rusak/kosong: tidak ada yang bisa dinaikkan
      continue;
    }

    try {
      await api.put(`core/me/preferences/${key}`, { value });
      serverKeys.add(key);
      state[key] = value;
      localStorage.removeItem(personalKey(base));
    } catch {
      // Kunci lokal DIBIARKAN: boot berikutnya mencobanya lagi. Yang hilang di
      // sini bukan preferensi orang lain, melainkan miliknya sendiri.
      state[key] = value;
    }
  }
}

/** Nilai warisan → bentuk yang diterima whitelist server; null bila tidak ada yang layak. */
function fromLegacy(key, raw) {
  if (key === 'density') return DENSITIES.includes(raw) ? raw : null;

  let list = null;
  try {
    list = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!Array.isArray(list) || !list.length) return null;

  if (key === 'favorites') {
    const routes = list.filter((one) => typeof one === 'string' && one).slice(0, limitOf('favorites'));
    return routes.length ? routes : null;
  }

  // recent: {route,label,sub} P1-B → field yang sama, `at` belum pernah dicatat.
  const entries = list
    .filter((one) => one && typeof one === 'object' && typeof one.route === 'string' && one.route)
    .map((one) => Object.fromEntries(RECENT_FIELDS
      .filter((field) => typeof one[field] === 'string' && one[field])
      .map((field) => [field, one[field]])))
    .slice(0, limitOf('recent'));
  return entries.length ? entries : null;
}

/* ------------------------------------------------- favorit & terakhir dibuka
 *
 * Dua kunci dengan aturan isi sendiri, dipakai tiga penggambar (sidebar app.js,
 * launcher home.js, beranda modul module.js). Aturannya hidup di SINI, bukan
 * disalin tiga kali: "bintang menyalakan/mematikan", "dokumen terakhir naik ke
 * atas", dan penyaring izin yang membuang tautan ke halaman akses-ditolak.
 */

/** @returns {string[]} rute NAV yang dibintangi, urut pembintangan. */
export function favorites() {
  const list = get('favorites', []);
  return Array.isArray(list) ? list.filter((one) => typeof one === 'string' && one) : [];
}

export function isFavorite(route) {
  return favorites().includes(route);
}

/**
 * Nyalakan/matikan bintang; mengembalikan daftar baru.
 *
 * Peristiwa `erp:favorites-changed` di window adalah cara sidebar tahu harus
 * menggambar ulang tanpa module.js atau home.js perlu mengimpor app.js —
 * impor yang akan melingkar (app.js sudah mengimpor keduanya). detail.was
 * dibawa serta karena sidebar memakainya untuk membedakan "bintang PERTAMA"
 * (grup Favorit baru lahir, buka sendiri) dari bintang kesekian (grup yang
 * dilipat pemakainya tetap terlipat).
 */
export function toggleFavorite(route) {
  const list = favorites();
  const next = list.includes(route) ? list.filter((one) => one !== route) : [...list, route].slice(-limitOf('favorites'));
  set('favorites', next);
  announce('erp:favorites-changed', { was: list.length, now: next.length, route });
  return next;
}

/** @returns {Array<{route:string,label:string,sub:?string,at:?string}>} */
export function recent() {
  const list = get('recent', []);
  return Array.isArray(list) ? list.filter((one) => one && typeof one === 'object' && typeof one.route === 'string') : [];
}

/** Dokumen yang baru dibuka naik ke atas; duplikatnya dibuang, bukan ditumpuk. */
export function rememberRecent(route, label, sub) {
  const entry = { route, label, sub: sub || null, at: new Date().toISOString() };
  const was = recent();
  const next = [entry, ...was.filter((one) => one.route !== route)].slice(0, limitOf('recent'));
  set('recent', next);
  announce('erp:recent-changed', { was: was.length, now: next.length, route });
  return next;
}

function announce(name, detail) {
  window.dispatchEvent(new CustomEvent(name, { detail }));
}

/** Kunci resource di balik rute `d/<kunci>/<id>`. */
export function resourceKeyOf(route) {
  return String(route).replace(/^d\//, '').replace(/\/[^/]+$/, '');
}

/**
 * "Terakhir dibuka" yang boleh dibuka pemanggil, lengkap dengan definisi
 * resource-nya (beranda modul memakai def.module untuk menyaring per modul).
 * Entri yang izinnya tidak dipegang DILEWATI, tidak dihapus: izin bisa kembali,
 * dan daftar tersimpan bukan milik penggambar.
 */
export function visibleRecent(can) {
  return recent()
    .map((one) => ({ ...one, key: resourceKeyOf(one.route), def: RESOURCES[resourceKeyOf(one.route)] || null }))
    .filter((one) => one.def && can(one.def.viewPerm || `${one.def.module}.view`));
}

/** `load()` sudah selesai (dijawab server ATAU menyerah)? */
function loaded() {
  return settled;
}

export const prefs = {
  get, set, has, load, loaded, favorites, isFavorite, toggleFavorite, recent, rememberRecent, visibleRecent, resourceKeyOf,
  limitOf, DENSITIES,
};

// Dibaca dari luar sebagai session.prefs (objek yang sama, bukan salinan).
readMirror();
session.prefs = state;
