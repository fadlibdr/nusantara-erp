/*
 * Beranda modul #/m/<prefix> (P1-B) — sasaran remah modul di bilah atas.
 *
 * Versi minimal, sengaja: kepala beraksen (nama grup NAV, satu kalimat dari
 * schema.js MODULES) dan kisi kartu layar yang boleh dibuka pemanggil. Kartu
 * dibaca dari visibleNav() — penyaring izin yang SAMA dengan sidebar dan
 * Ctrl+K — jadi beranda ini tidak pernah menawarkan layar yang barisnya
 * disembunyikan dari menu, dan grup yang izinnya tidak dipegang berakhir di
 * keadaan kosong, bukan panel akses-ditolak (grupnya memang tidak ada bagi
 * orang itu). Tidak ada hitungan, tidak ada KPI: angka datang di P1-C bersama
 * "Terakhir dibuka" dan launcher #/home.
 *
 * Keterangan kartu hanya dari yang sudah ada (def.description resource daftar,
 * atau item.sub NAV) — tanpa itu kartu hanya ikon + label; tidak ada kalimat
 * yang dikarang per layar.
 *
 * Struktur (verifikasi P1-B 5 Sep 2026): kisi adalah <ul> berlabel dengan
 * <li> per kartu, pemisah NAV menjadi <h2> yang melabeli <section>-nya —
 * sebelumnya aria-label dipasang pada <div> polos (peran generic, label
 * diabaikan pembaca layar) dan pemisah adalah <div> bergaya eyebrow.
 */

import { session } from '../api.js';
import { el, svgIcon, emptyState } from '../ui.js';
import { RESOURCES, moduleFor, visibleNav } from '../schema.js';

export function renderModuleHome(host, { prefix }) {
  const module = moduleFor(prefix);
  if (!module) {
    host.appendChild(el('.alert.error', `Modul "${prefix}" tidak dikenal.`));
    return;
  }

  const visible = visibleNav((perm) => session.can(perm)).find((group) => group.prefix === prefix);
  const items = visible ? visible.items : [];

  host.appendChild(el('.module-head', { dataset: { accent: String(module.accent), prefix } }, [
    el('.module-icon', svgIcon(module.icon, { size: 22 })),
    el('div', [
      el('.eyebrow', { text: 'Modul' }),
      el('h1', { text: module.label }),
      el('.desc', { text: module.description }),
    ]),
  ]));

  if (!items.some((item) => item.route)) {
    host.appendChild(el('.card', emptyState('Tidak ada layar yang bisa Anda buka di modul ini', {
      title: 'Tidak ada layar', kind: 'search',
    })));
    return;
  }

  const screens = el('.module-screens', sections(items, prefix).map(({ caption, id, screens: list }) => {
    const grid = el('ul.module-grid', caption ? { 'aria-labelledby': id } : { 'aria-label': `Layar modul ${module.label}` },
      list.map((item) => el('li', screenCard(item))));
    return caption
      ? el('section.module-section-block', [el('h2.module-section', { id, text: caption }), grid])
      : grid;
  }));
  screens.addEventListener('keydown', arrowNavigation);
  host.appendChild(screens);
}

/* Item NAV → blok per pemisah: kartu sebelum pemisah pertama (bila ada) jadi
   satu kisi tanpa judul, tiap pemisah membuka blok baru berjudul. */
function sections(items, prefix) {
  const blocks = [];
  let current = null;
  items.forEach((item, index) => {
    if (item.divider) {
      current = { caption: item.divider, id: `module-section-${prefix}-${index}`, screens: [] };
      blocks.push(current);
      return;
    }
    if (!current) {
      current = { caption: null, id: null, screens: [] };
      blocks.push(current);
    }
    current.screens.push(item);
  });
  return blocks;
}

function screenCard(item) {
  const def = item.route.startsWith('r/') ? RESOURCES[item.route.slice(2)] : null;
  const hint = (def && def.description) || item.sub || null;
  return el('a.module-card', { href: `#/${item.route}`, dataset: { route: item.route } }, [
    svgIcon(def ? 'table' : 'layout-grid', { size: 18 }),
    el('div', [el('b', { text: item.label }), hint ? el('span.hint', { text: hint }) : null]),
  ]);
}

/*
 * Panah memindah fokus antar kartu; Tab/Enter sudah bekerja karena kartunya
 * <a>. Jumlah kolom dibaca dari trek grid yang terkomputasi, bukan ditebak
 * dari lebar, supaya atas/bawah tetap benar di ponsel (satu kolom) maupun
 * layar lebar (lima kolom); Home/End ke kartu pertama/terakhir.
 */
function arrowNavigation(event) {
  const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: 'up', ArrowDown: 'down', Home: 'first', End: 'last' };
  if (!(event.key in moves)) return;
  const cards = [...event.currentTarget.querySelectorAll('.module-card')];
  const index = cards.indexOf(document.activeElement);
  if (index === -1) return;

  const grid = document.activeElement.closest('.module-grid');
  const columns = Math.max(1, getComputedStyle(grid).gridTemplateColumns.split(' ').length);
  const move = moves[event.key];
  let next = index;
  if (move === 'first') next = 0;
  else if (move === 'last') next = cards.length - 1;
  else if (move === 'up') next = index - columns;
  else if (move === 'down') next = index + columns;
  else next = index + move;

  if (next < 0 || next >= cards.length) return;
  event.preventDefault();
  cards[next].focus();
}
