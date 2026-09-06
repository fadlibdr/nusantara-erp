/*
 * App launcher `#/home` (Fase 1 / P1-C, T1C.3) — halaman pertama di ponsel.
 *
 * KENAPA ADA. Diukur pada 12 peran demo: tiga di antaranya (procurement, hr,
 * teknisi) tidak memegang satu pun dari prj.view / fin.view, jadi
 * GET core/dashboard/summary menjawab {} dan dasbor mereka tidak punya satu
 * angka pun — halaman pembuka yang kosong. Dan di ponsel sidebar terlipat jadi
 * laci: jalan ke Lapangan adalah hamburger → grup Proyek → Lapangan, tiga
 * ketukan, dua di antaranya untuk membuka menu. Launcher ini menjawab keduanya:
 * satu ubin per modul yang BOLEH dibuka orangnya, masing-masing dengan satu
 * angka utama (registri ModuleCounts) dan jumlah layarnya.
 *
 * ANGKA. Ubin memimpin dengan hitungan dari GET core/modules. Aturannya sama
 * dengan registrinya: modul yang server tidak sebutkan (izin hitungannya tidak
 * dipegang) atau yang count-nya null menulis '—', TIDAK PERNAH 0 — "0 tiket"
 * pada layar orang yang memang tidak boleh melihat tiket adalah kebohongan yang
 * tampak seperti kabar baik. Permintaannya gagal seluruhnya (luring) → semua
 * ubin '—' dan satu baris yang mengatakan kenapa; ubinnya sendiri tetap bisa
 * ditekan, karena navigasi tidak butuh angka.
 *
 * UBIN MANA. visibleNav() — penyaring izin yang SAMA dengan sidebar, Ctrl+K dan
 * beranda modul; modul tanpa satu pun layar yang bisa dibuka tidak berubin.
 * Jadi "0 peran tanpa ubin" bukan klaim di sini melainkan akibat: setiap orang
 * yang bisa masuk memegang sedikitnya satu layar.
 */

import { api, session } from '../api.js';
import { el, clear, append, button, svgIcon, emptyState } from '../ui.js';
import { MODULES, moduleFor, visibleNav } from '../schema.js';
import { prefs } from '../prefs.js';
import { screenHits } from '../search.js';
import { navigate } from '../router.js';

/** Hasil layar yang ditampilkan saat mencari — di atas ini jawabannya adalah modulnya. */
const SEARCH_MAX = 24;

export async function renderHome(host) {
  clear(host);

  const groups = visibleNav((perm) => session.can(perm)).filter((group) => group.items.some((item) => item.route));
  const hidden = hiddenPrefixes();

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Beranda' }),
      el('.desc', { text: `Selamat datang, ${(session.user || {}).name || 'pengguna'}. Pilih modul untuk mulai bekerja.` }),
    ]),
    el('.actions', [button('Dasbor', { onClick: () => navigate('dashboard') })]),
  ]));

  const grid = el('ul.home-grid', { 'aria-label': 'Modul' });
  const tiles = new Map();
  for (const group of groups) {
    if (hidden.includes(group.prefix)) continue;
    const tile = moduleTile(group);
    tiles.set(group.prefix, tile);
    grid.appendChild(el('li', tile.node));
  }

  /* Kotak cari di ATAS pintasan dan ubin: di ponsel ia adalah jalan tercepat
     ke layar yang namanya sudah diketahui, dan menaruhnya di bawah kisi berarti
     menggulir melewati 14 ubin lebih dulu. */
  const results = el('.home-results', { hidden: true });
  host.appendChild(searchBox(results, groups, grid));
  host.appendChild(results);

  const body = el('div');
  host.appendChild(body);

  shortcutRows(body);

  if (!tiles.size) {
    body.appendChild(el('.card', emptyState('Tidak ada modul yang bisa Anda buka.', { title: 'Belum ada modul', kind: 'inbox' })));
    return;
  }

  body.appendChild(el('section.home-section', { id: 'home-modules' }, [el('h2.home-section-title', { text: 'Modul' }), grid]));
  append(body, hiddenFooter(hidden, host));

  /* Angka menyusul; ubin sudah bisa ditekan sebelum jawabannya datang, dan
     itulah sebabnya kisi digambar lebih dulu. */
  let counts = null;
  try {
    counts = await api.get('core/modules');
  } catch {
    counts = null;
  }

  if (counts === null) {
    tiles.forEach((tile) => tile.fail());
    body.appendChild(el('.muted.home-note', { text: 'Angka modul tidak dapat dimuat sekarang — menu di bawah tetap bisa dipakai.' }));
    return;
  }

  const byPrefix = new Map((Array.isArray(counts) ? counts : []).map((one) => [one.prefix, one]));
  tiles.forEach((tile, prefix) => tile.fill(byPrefix.get(prefix) || null));
}

/* ------------------------------------------------------------------- ubin */

/**
 * Satu ubin modul: aksen slotnya, ikon Lucide, nama, angka utama, jumlah layar.
 * Angka dipasang belakangan lewat fill()/fail() — DOM-nya sudah ada sejak awal
 * supaya tidak ada lompatan tata letak saat jawabannya tiba.
 */
function moduleTile(group) {
  const module = moduleFor(group.prefix) || { accent: 8, icon: 'layout-grid', label: group.label };
  const screens = group.items.filter((item) => item.route).length;

  const value = el('.home-kpi-value', { text: '·' });
  const unit = el('.home-kpi-unit', { text: '' });
  const caption = el('.home-kpi-label', { text: 'memuat…' });

  const node = el('a.home-tile', {
    href: `#/m/${group.prefix}`,
    dataset: { accent: String(module.accent), prefix: group.prefix },
  }, [
    el('.home-tile-icon', svgIcon(module.icon, { size: 20 })),
    el('.home-tile-body', [
      el('b.home-tile-label', { text: group.label }),
      el('.home-kpi', [value, unit]),
      caption,
    ]),
    el('.home-tile-screens', { text: `${screens} layar` }),
  ]);

  return {
    node,
    fill(entry) {
      if (!entry) return this.fail();
      // count null = registri tahu ada angkanya tetapi kuerinya gagal; entri
      // yang tidak dikirim server = izin hitungannya tidak dipegang. Keduanya
      // '—'; yang berbeda hanya apakah kita tahu NAMA angkanya — dan nama
      // tanpa angka lebih baik daripada tidak keduanya: "—  Job gagal"
      // memberi tahu pembacanya apa yang tidak diketahui.
      caption.textContent = entry.label || '';
      caption.title = entry.label || '';
      if (entry.count === null || entry.count === undefined) {
        value.textContent = '—';
        unit.textContent = '';
        return;
      }
      value.textContent = String(entry.count);
      unit.textContent = entry.unit;
    },
    fail() {
      value.textContent = '—';
      unit.textContent = '';
      caption.textContent = '';
    },
  };
}

/* -------------------------------------------------- favorit & terakhir dibuka */

/** Dua baris pintasan di atas kisi; yang kosong tidak menggambar apa pun. */
function shortcutRows(body) {
  const flat = visibleNav((perm) => session.can(perm)).flatMap((group) => group.items.filter((item) => item.route));

  const favorites = prefs.favorites()
    .map((route) => flat.find((item) => item.route === route))
    .filter(Boolean)
    .map((item) => shortcut(`#/${item.route}`, item.label, groupOf(item.route)));

  const recent = prefs.visibleRecent((perm) => session.can(perm))
    .slice(0, 8)
    .map((one) => shortcut(`#/${one.route}`, one.label, one.sub || (one.def.labelOne || one.def.label)));

  if (favorites.length) body.appendChild(shortcutSection('Favorit', favorites));
  if (recent.length) body.appendChild(shortcutSection('Terakhir dibuka', recent));
}

function shortcutSection(title, chips) {
  return el('section.home-section', [
    el('h2.home-section-title', { text: title }),
    el('ul.home-chips', { 'aria-label': title }, chips.map((chip) => el('li', chip))),
  ]);
}

function shortcut(href, label, sub) {
  return el('a.home-chip', { href }, [
    el('b', { text: label }),
    sub ? el('span.hint', { text: sub }) : null,
  ]);
}

/** Nama grup NAV yang memuat rute ini (keterangan kecil di chip favorit). */
function groupOf(route) {
  const group = visibleNav((perm) => session.can(perm)).find((one) => one.items.some((item) => item.route === route));
  return group ? group.label : null;
}

/* ------------------------------------------------------------------ cari */

/*
 * Satu kotak untuk dua hal: ubin modul yang namanya cocok, dan LAYAR yang
 * namanya cocok. Indeks layarnya persis indeks Ctrl+K (search.js screenHits,
 * penyaring visibleNav yang sama), jadi palet dan launcher tidak akan pernah
 * menawarkan daftar layar yang berbeda untuk kata yang sama. Yang tidak
 * dilakukan di sini adalah mencari DOKUMEN: itu satu permintaan per ketikan,
 * dan Ctrl+K sudah memilikinya.
 */
function searchBox(results, groups, grid) {
  const input = el('input.home-search', {
    type: 'search', placeholder: 'Cari modul atau layar…', 'aria-label': 'Cari modul atau layar',
    autocomplete: 'off',
  });

  const draw = () => {
    const term = input.value.trim();
    clear(results);
    // Yang disembunyikan adalah <li>-nya, bukan <a>-nya: sel kisi yang kosong
    // meninggalkan lubang di grid.
    const cells = [...grid.children];
    results.hidden = term.length < 2;
    if (results.hidden) {
      cells.forEach((cell) => { cell.hidden = false; });
      const all = document.getElementById('home-modules');
      if (all) all.hidden = false;
      return;
    }

    const needle = term.toLowerCase();
    const prefixes = groups
      .filter((group) => group.label.toLowerCase().includes(needle)
        || String((MODULES[group.prefix] || {}).description || '').toLowerCase().includes(needle))
      .map((group) => group.prefix);
    cells.forEach((cell) => {
      const tile = cell.querySelector('.home-tile');
      cell.hidden = !(tile && prefixes.includes(tile.dataset.prefix));
    });
    // Seksi "Modul" berjudul di atas kisi yang seluruh selnya tersembunyi
    // terbaca sebagai modul yang hilang, bukan sebagai pencarian tanpa hasil.
    const section = document.getElementById('home-modules');
    if (section) section.hidden = !prefixes.length;

    const hits = screenHits(term, SEARCH_MAX);
    results.appendChild(el('h2.home-section-title', { text: `Layar (${hits.length})` }));
    results.appendChild(hits.length
      ? el('ul.home-chips', { 'aria-label': 'Hasil layar' }, hits.map((hit) => el('li', shortcut(hit.link, hit.code, hit.title))))
      : el('.muted.home-note', { text: `Tidak ada layar yang namanya cocok dengan "${term}".` }));
  };

  input.addEventListener('input', draw);
  // Enter membuka hasil layar pertama — perilaku Ctrl+K, supaya dua kotak cari
  // di aplikasi yang sama tidak menjawab tombol yang sama dengan cara berbeda.
  input.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    const first = results.querySelector('a.home-chip');
    if (first) { event.preventDefault(); first.click(); }
  });

  return el('.home-searchbar', input);
}

/* --------------------------------------------------------- modul disembunyikan */

function hiddenPrefixes() {
  const list = prefs.get('launcher.hidden', []);
  return Array.isArray(list) ? list.filter((one) => typeof one === 'string') : [];
}

/*
 * Preferensi 'launcher.hidden' belum punya tombol "sembunyikan" (P1-D yang
 * memilikinya bersama laci Atur dasbor), tetapi ia SUDAH dihormati di sini —
 * dan justru karena itu jalan kembalinya harus ada sekarang. Sebuah kunci yang
 * bisa ditulis tanpa cara membatalkannya adalah modul yang hilang selamanya
 * dari beranda seseorang.
 */
function hiddenFooter(hidden, host) {
  if (!hidden.length) return null;
  return el('.home-note.muted', [
    el('span', { text: `${hidden.length} modul disembunyikan dari beranda. ` }),
    button('Tampilkan semua', {
      size: 'sm',
      onClick: () => { prefs.set('launcher.hidden', []); renderHome(host); },
    }),
  ]);
}
