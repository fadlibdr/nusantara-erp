/*
 * Remah roti "Modul › Layar › (Dokumen)" — SATU pembuat untuk seluruh aplikasi.
 *
 * Dulu ada dua: setCrumbs() di app.js (P1-B: remah modul bertaut ke #/m/<prefix>,
 * beraksen, remah terakhir aria-current) dan sebuah crumbs() pribadi di
 * views/kaskecil.js yang hanya menempel <span> › <b>. Dua detail Kas Kecil dan
 * Kasbon karena itu tidak punya remah modul maupun aria-current, dan sejak
 * app.css memakai #crumbs[data-root] untuk memutuskan remah mana yang boleh
 * disembunyikan di ponsel, salinan kedua itu akan diam-diam jatuh ke perilaku
 * ketiga. Dipindah ke sini supaya hanya ada satu bentuk yang mungkin.
 *
 * Remah pertama yang berupa nama grup NAV menjadi tautan ke beranda modul
 * #/m/<prefix> dan membawa aksen modulnya (data-accent → app.css); remah layar
 * pada halaman detail menjadi tautan ke daftarnya bila pemanggil memberi
 * screenHref. Remah terakhir tetap <b> — layar detail menimpanya dengan kode
 * dokumen begitu rekamannya tiba (detail.js/custom.js membaca '#crumbs b').
 * Satu remah (Dasbor, beranda modul sendiri) tidak ditautkan ke mana pun.
 */
import { el, clear, icon } from './ui.js';
import { moduleForLabel } from './schema.js';

export function setCrumbs(parts, { screenHref } = {}) {
  const host = document.getElementById('crumbs');
  if (!host) return;
  clear(host);
  const module = parts.length > 1 ? moduleForLabel(parts[0]) : null;
  /* Bentuk rantainya ditulis di DOM karena app.css ≤ 760 px harus tahu remah mana yang boleh
     disembunyikan. 'module' = berakar pada grup NAV: remah modul sendirian sudah menyebut tempatnya.
     'screen' = tidak ada remah modul — akarnya penanda "ERP" (groupLabelFor untuk layar di luar NAV)
     atau memang cuma satu remah; di sana remah LAYAR yang harus tampil, kalau tidak header ponsel
     kosong sama sekali (verifikasi P1-B putaran 2, 6 Sep 2026: 7 layar, tinggi remah 0 px). */
  host.dataset.root = module ? 'module' : 'screen';
  parts.forEach((part, index) => {
    if (index) host.appendChild(icon('chevronRight', 12));
    const last = index === parts.length - 1;
    if (index === 0 && module) {
      // Label di <span> sendiri supaya di ponsel bisa dielipsiskan (app.css ≤ 760 px).
      host.appendChild(el('a.crumb-module', {
        href: `#/m/${module.prefix}`, dataset: { accent: String(module.accent) }, title: `Beranda modul ${part}`,
      }, el('span.lbl', { text: part })));
    } else if (index === 1 && !last && screenHref) {
      host.appendChild(el('a.crumb-screen', { href: screenHref, text: part }));
    } else {
      host.appendChild(last ? el('b', { text: part, 'aria-current': 'page' }) : el('span', { text: part }));
    }
  });
  document.title = `${parts[parts.length - 1]} · Nusantara ERP`;
}
