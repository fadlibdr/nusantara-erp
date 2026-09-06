/*
 * Perkakas bersama setiap widget dasbor (P1-D).
 *
 * KENAPA BERKAS SENDIRI. Sampai P1-C potongan-potongan ini — safe(), failure(),
 * failedStat(), failedBody(), miniTable() — hidup di dalam views/dashboard.js
 * dan hanya bisa dipakai oleh dasbor itu sendiri. P1-D memecah dasbor menjadi
 * 19 widget di 19 berkas; menyalin aturan "gagal tidak boleh terbaca sebagai
 * kosong" ke 19 tempat adalah cara paling pasti untuk membuat 18 di antaranya
 * menyimpang. Aturannya hidup di sini, satu kali.
 *
 * ATURAN YANG DIJAGA BERKAS INI, ditulis ulang karena inilah alasan P1-D tidak
 * boleh menyentuhnya: sebuah fetch yang GAGAL dan sebuah sumber yang memang
 * KOSONG tidak boleh menghasilkan nilai yang sama. Sampai Temuan 79 keduanya
 * `[]`, dan setiap ubin yang digambar hanya bila `.length` benar lenyap tanpa
 * jejak — di data nyata itu berarti ubin "Termin siap ditagih" senilai
 * Rp 14,55 miliar hilang saat SQLite berebut kunci, sementara dasbornya tampak
 * sehat. Pembacanya menyimpulkan tidak ada yang bisa ditagih.
 *
 * safe() karena itu mengembalikan array bertanda `loadFailure`: setiap
 * .filter/.reduce/.length di bawahnya tetap bekerja apa adanya (itulah yang
 * menjaga dasbor hidup saat satu sumber jatuh), tetapi widget-nya bisa berkata
 * "gagal dimuat" alih-alih menghilang atau menulis Rp 0.
 */

import { api } from '../../api.js';
import { el, button, emptyState, errorState, progressBar } from '../../ui.js';

/**
 * Fetch yang tidak pernah melempar. Satu 403 pada satu widget tidak boleh
 * menggelapkan seluruh dasbor — tetapi kabar kegagalannya IKUT dibawa.
 */
export function safe(path, params) {
  return api.get(path, params)
    .then((rows) => rows || [])
    .catch((error) => {
      // Sebab aslinya tetap masuk konsol; layar hanya perlu tahu bahwa
      // angkanya tidak boleh dipercaya.
      console.error(`Dasbor: ${path} gagal dimuat`, error);
      return Object.assign([], { loadFailure: error });
    });
}

/**
 * Saudara safe() untuk endpoint yang meta-nya ikut dibutuhkan (paginasi:
 * meta.total adalah hitungan SEBENARNYA, bukan panjang halaman pertama).
 * Objek — bukan array — jadi `.length` di pemanggilnya tidak diam-diam 0.
 */
export function safeList(path, params) {
  return api.list(path, params)
    .then((payload) => payload || { data: [], meta: {} })
    .catch((error) => {
      console.error(`Dasbor: ${path} gagal dimuat`, error);
      return { loadFailure: error };
    });
}

/** Error sumber yang gagal, atau null. `[]` dari gerbang izin tetap null. */
export const failure = (value) => (value && value.loadFailure) || null;

/** Baris data sebuah jawaban api.list, apa pun bentuk kegagalannya. */
export const rowsOf = (payload) => (failure(payload) ? [] : ((payload && payload.data) || []));

/** meta.total bila server mengirimkannya; null berarti "tidak tahu", bukan 0. */
export function totalOf(payload) {
  if (failure(payload)) return null;
  const meta = (payload && payload.meta) || {};
  return Number.isFinite(meta.total) ? meta.total : rowsOf(payload).length;
}

/* ------------------------------------------------------------------ bentuk */

export function stat(label, value, { sub, tone, onClick, small = true } = {}) {
  const node = el('.stat', [
    el('.label', { text: label }),
    el(`.value${small ? '.sm' : ''}`, { text: value }),
    sub ? el(`.delta${tone ? `.${tone}` : ''}`, { text: sub }) : null,
  ]);
  if (onClick) {
    node.style.cursor = 'pointer';
    node.addEventListener('click', onClick);
  }
  return node;
}

/**
 * Ubin angka untuk sumber yang gagal: '—', bukan Rp 0. Nol adalah pernyataan
 * tentang uang, dan pernyataan itu belum tentu benar — "Hutang belum dibayar
 * Rp 0" pada dasbor yang gagal memuat fin/ap-bills adalah kebohongan yang rapi.
 */
export const failedStat = (label) => stat(label, '—', { sub: 'Gagal dimuat', tone: 'down' });

/**
 * Badan widget untuk ubin ANGKA yang gagal — dengan "Coba lagi"-nya.
 *
 * failedStat() sendiri hanya menggambar ubinnya, dan sampai verifikasi kedua
 * P1-D empat widget (ringkasan-uang, saldo-bank, siap-tagih, ncr) memakainya
 * begitu saja: kartunya menulis '— Gagal dimuat' TANPA satu tombol pun, jadi
 * satu-satunya pemulihannya adalah "Muat ulang" di kepala halaman — yang
 * menggambar ulang SELURUH dasbor, yaitu persis perilaku P1-C yang paket ini
 * menyatakan sudah digantikannya. Terukur: kartu uang direktur = 0 tombol,
 * sementara kartu ar-aging yang gagal = ['Coba lagi'] dan satu klik = satu
 * permintaan.
 *
 * Kaki yang sama dengan failedBody(): satu widget yang jatuh memuat ulang
 * dirinya sendiri.
 */
export const failedStats = (labels, retry) => el('div', [
  el('.card-body', statRow(labels.map((label) => failedStat(label)))),
  retry ? footLink('Coba lagi', retry) : null,
]);

export function statRow(children) {
  return el('.stat-row', children.filter(Boolean));
}

export function tileEmpty(message, kind = 'inbox') {
  return el('.card-body.flush', emptyState(message, { kind, compact: true, title: null }));
}

/**
 * Badan widget untuk sumber yang gagal. errorState() yang sama dengan layar
 * lain supaya kegagalan terbaca seragam di seluruh aplikasi; baris detail
 * pertama yang khas dasbor — isinya kosong karena pengambilannya gagal, BUKAN
 * karena datanya tidak ada. Tombolnya memuat ulang WIDGET ini saja: sejak P1-D
 * satu widget yang jatuh tidak perlu lagi menyeret 17 permintaan lain ikut
 * diulang.
 */
export const failedBody = (error, retry) => el('.card-body', errorState({
  message: 'Data widget ini gagal dimuat.',
  details: ['Jangan dibaca sebagai "tidak ada data" — isinya tidak diketahui.', error.message || String(error)],
}, retry));

/* Tabel ringkas di dalam widget. `empty.kind` 'done' hanya untuk widget yang
   kosongnya berarti tidak ada yang tertunda (piutang jatuh tempo, tiket
   aktif); yang lain memakai 'inbox' yang netral. */
export function miniTable(columns, rows, onRowClick, { empty = {} } = {}) {
  if (!rows.length) {
    return tileEmpty(empty.message || 'Tidak ada data.', empty.kind || 'inbox');
  }
  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', columns.map((column) => el(`th${column.align ? `.${column.align}` : ''}`, { text: column.label })))),
    el('tbody', rows.map((row) => {
      const tr = el(`tr${onRowClick ? '.clickable' : ''}`, columns.map((column) =>
        el(`td${column.align ? `.${column.align}` : ''}`, column.render(row))));
      if (onRowClick) tr.addEventListener('click', () => onRowClick(row));
      return tr;
    })),
  ]));
}

/** Kaki widget dengan satu pintu ke layar yang memuat angkanya secara lengkap. */
export function footLink(label, onClick) {
  return el('.card-foot', button(label, { size: 'sm', variant: 'ghost', onClick }));
}

/**
 * Baris batang berlabel — bentuk yang dipakai umur piutang/hutang. Nilai
 * dibandingkan terhadap `max` pemanggil, bukan terhadap total: keranjang
 * terbesar harus penuh, supaya perbandingannya terbaca dalam satu pandang.
 */
export function barRows(entries, { format }) {
  const max = Math.max(...entries.map((entry) => Math.abs(Number(entry.value) || 0)), 1);
  return el('.card-body', entries.map((entry) => el('.bar-row', [
    el('.name', { text: entry.label }),
    el('div', progressBar((Math.abs(Number(entry.value) || 0) / max) * 100, entry.tone || '')),
    el('.amt', { text: format(entry.value) }),
  ])));
}
