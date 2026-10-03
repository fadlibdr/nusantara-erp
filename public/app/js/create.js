/* Sumber "Buat ▾" (UX-D.4, handoff README Fase D) — tombol Tambah yang sudah ada, dikumpulkan.
 *
 * Bilah atas menawarkan formulir buat-baru tanpa harus membuka daftarnya lebih dulu.
 * Isinya BUKAN daftar kedua yang dirawat tangan: setiap entri adalah resource yang
 * daftarnya SUDAH menggambar tombol "Tambah …" bagi pembaca ini, dengan predikat yang
 * sama persis (canCreateResource — list.js memakainya juga), ditambah izin baca
 * daftarnya, karena tombol Tambah hanya ada di daftar yang boleh dibuka. Tidak ada izin
 * yang ditulis di sini; semuanya datang dari schema.js lewat session.can().
 *
 * Urutan: formulir modul tempat pembaca berada lebih dulu (urutan NAV), lalu yang
 * resource-nya baru saja dibuka (prefs "Terakhir dibuka"), dan bila keduanya kosong
 * enam formulir pertama dalam urutan NAV. Palet Ctrl+K memakai sumber yang sama untuk
 * grup "Aksi" (UX-D.7), supaya dua pintu tidak menawarkan daftar yang berbeda.
 */

import { session } from './api.js';
import { RESOURCES, NAV } from './schema.js';
import { prefs, resourceKeyOf } from './prefs.js';
import { startForm } from './views/form.js';
import { navigate } from './router.js';

const MODULE_MAX = 8;
const RECENT_MAX = 6;

/** Predikat tombol "Tambah …" di daftar — SATU, dipakai list.js dan Buat ▾. */
export function canCreateResource(def) {
  // def.createPerm menimpa `${module}.create` untuk register lintas-modul (lihat list.js).
  return Boolean(def) && def.canCreate !== false && Boolean(def.form)
    && session.can(def.createPerm || `${def.module}.create`);
}

const canView = (def) => session.can(def.viewPerm || `${def.module}.view`);

/* Posisi rute daftar di NAV, untuk urutan yang sama dengan sidebar. */
function navOrder() {
  const order = new Map();
  let index = 0;
  for (const group of NAV) {
    for (const item of group.items) {
      if (item.route && item.route.startsWith('r/') && !order.has(item.route.slice(2))) order.set(item.route.slice(2), index);
      index += 1;
    }
  }
  return order;
}

/** Kunci RESOURCES dari jalur rute r/ d/ f/ b/, atau null. */
function resourceKeyFromPath(path) {
  const match = /^(?:r|d|f|b)\/(.+)$/.exec(path || '');
  if (!match) return null;
  if (RESOURCES[match[1]]) return match[1];
  const cut = match[1].lastIndexOf('/');
  return cut > 0 && RESOURCES[match[1].slice(0, cut)] ? match[1].slice(0, cut) : null;
}

/** Awalan modul tempat pembaca berada, atau null (Beranda, Dasbor, layar lintas modul). */
export function moduleOfPath(path) {
  if (!path) return null;
  if (path.startsWith('m/')) return path.slice(2) || null;
  const key = resourceKeyFromPath(path);
  if (key) return RESOURCES[key].module || null;
  const group = NAV.find((one) => one.items.some((item) => item.route === path));
  return group && group.prefix !== 'ringkasan' ? group.prefix : null;
}

/** Semua resource yang boleh dibuat DAN daftarnya boleh dibuka, dalam urutan NAV. */
export function creatable() {
  const order = navOrder();
  return Object.entries(RESOURCES)
    .filter(([, def]) => canCreateResource(def) && canView(def))
    .map(([key, def]) => ({ key, def, at: order.has(key) ? order.get(key) : Number.MAX_SAFE_INTEGER }))
    .sort((a, b) => a.at - b.at || a.def.label.localeCompare(b.def.label, 'id'));
}

/** Entri Buat ▾ untuk jalur sekarang: { module: [...], recent: [...] }. */
export function createEntries(path) {
  const all = creatable();
  const here = moduleOfPath(path);
  const inModule = here ? all.filter((entry) => entry.def.module === here).slice(0, MODULE_MAX) : [];
  const taken = new Set(inModule.map((entry) => entry.key));
  const recentKeys = [...new Set(prefs.recent().map((one) => resourceKeyOf(one.route)))];
  let recent = recentKeys
    .map((key) => all.find((entry) => entry.key === key))
    .filter((entry) => entry && !taken.has(entry.key))
    .slice(0, RECENT_MAX);
  if (!inModule.length && !recent.length) recent = all.slice(0, RECENT_MAX);
  return { here, module: inModule, recent };
}

/** Buka formulir buat-baru sebuah entri: halaman bila formulirnya halaman, dialog bila bukan. */
export function startCreate({ key, def }) {
  return startForm({
    def,
    key,
    // Sama dengan afterCreate di list.js: dokumen baru dibuka; resource tanpa layar detail kembali ke daftarnya.
    onSaved: (saved) => navigate(saved && saved.id && !def.noDetail ? `d/${key}/${saved.id}` : `r/${key}`),
  });
}
