/*
 * Palet titik kalender per departemen (P1-B), dipindahkan dari
 * views/dashboard.js ke berkas sendiri pada P1-D.
 *
 * KENAPA PINDAH. Sampai P1-C palet ini diekspor oleh dasbor dan diimpor layar
 * kalender penuh — arah ketergantungan yang benar selama dasbor MEMILIKI
 * kalendernya. Sejak P1-D dasbor hanyalah penyusun 18 widget, dan kalender
 * adalah salah satunya: `views/widgets/kalender.js` dan `views/kalender.js`
 * sama-sama menggambar titik departemen, dan tidak satu pun dari keduanya
 * memiliki yang lain. Isinya TIDAK berubah satu nilai pun — 16 hex yang sama,
 * urutan slot yang sama.
 */

import { el } from './ui.js';

/* ----------------------------------------------------- kalender: palet */
/* Palet titik kalender per departemen — 8 slot kategorikal dengan urutan
   TETAP (slot mengikuti urutan kanonik departemen pada kontrak GET
   core/calendar). Urutan slot inilah mekanisme keselamatan buta-warnanya:
   pasangan bersebelahan tervalidasi ΔE-CVD >= 8,4 pada kedua tema (palet
   referensi dataviz, Juli 2026) — jangan diacak ulang, dan jangan "dirapikan"
   ke variabel tema yang hanya punya 6 warna untuk 8 departemen. Identitas
   tidak pernah dibawa warna sendirian: legenda, tooltip, dan daftar agenda
   selalu menulis nama departemennya. Diekspor supaya kalender penuh
   (views/kalender.js) memakai pemetaan yang persis sama. */
export const KALENDER_DEPTS = ['Penjualan', 'Proyek', 'Keuangan', 'SDM', 'Pengadaan', 'Layanan', 'Aset', 'Persediaan'];

const KAL_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
const KAL_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];

/** Warna titik sebuah departemen — var CSS yang diisi ensureKalenderPalette(). */
export function kalenderDeptColor(department) {
  const index = KALENDER_DEPTS.indexOf(department);
  // Departemen tak dikenal (sumber baru dirilis di server sebelum SPA ini
  // ikut dirilis ulang) tetap mendapat titik — abu-abu, bukan tak terlihat.
  return index === -1 ? 'var(--muted)' : `var(--kal-${index + 1})`;
}

/* Tema gelap butuh step warna sendiri (bukan warna terang yang dibalik), dan
   inline style tidak bisa ikut media query — maka nilainya di-inject sekali
   sebagai <style>, meniru pola app.css: blok [data-theme] menang atas blok
   prefers-color-scheme karena spesifisitasnya lebih tinggi. */
export function ensureKalenderPalette() {
  if (document.getElementById('kal-palette')) return;
  const assign = (steps) => steps.map((hex, index) => `--kal-${index + 1}:${hex}`).join(';');
  document.head.appendChild(el('style#kal-palette', {
    text: `.kal-scope{${assign(KAL_LIGHT)}}`
      + `@media (prefers-color-scheme: dark){.kal-scope{${assign(KAL_DARK)}}}`
      + `:root[data-theme="light"] .kal-scope{${assign(KAL_LIGHT)}}`
      + `:root[data-theme="dark"] .kal-scope{${assign(KAL_DARK)}}`,
  }));
}

/** Titik warna departemen; nama tekstualnya selalu berdiri di sebelahnya. */
export function kalDot(department, size = 6) {
  return el('span', {
    style: {
      width: `${size}px`, height: `${size}px`, borderRadius: '50%',
      background: kalenderDeptColor(department), flex: 'none',
    },
  });
}
