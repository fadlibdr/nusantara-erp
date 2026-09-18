/* Widget "Umur hutang" (P1-D) - GET finance/reports/ap-aging.
   Pasangan umur piutang, dengan keranjang dan warna yang SAMA: dua widget yang
   memakai skala berbeda untuk sumbu yang sama tidak bisa dibandingkan sekilas,
   dan membandingkannya sekilas adalah satu-satunya alasan keduanya ada di satu
   layar. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, barRows, footLink, tileEmpty, stat } from './kit.js';
import { AGING_BUCKETS, agingEntries } from './aging.js';

export async function build({ reload }) {
  const report = await safe('finance/reports/ap-aging');
  if (failure(report)) return failedBody(failure(report), reload);

  const buckets = (report && report.buckets) || {};
  /* Keranjang selalu lima, juga ketika semuanya nol — jadi `Object.keys` tidak
     bisa membedakan "tidak ada dokumen terbuka" dari "ada, semuanya belum
     jatuh tempo". Yang membedakan adalah baris rinciannya, dan server
     mengirimkannya utuh (laporan ini tidak berhalaman). Lima batang nol di
     atas angka Rp 0 bukan kebohongan, hanya kebisingan; kalimatnya lebih
     berguna. */
  /* Kaki kartu IKUT digambar pada keadaan kosong — alasan yang sama dengan
     ar-aging: layar yang kosong justru yang paling perlu diperiksa
     (verifikasi kedua P1-D). */
  if (!((report && report.rows) || []).length || !Object.keys(buckets).length) {
    return el('div', [
      tileEmpty('Tidak ada tagihan vendor terbuka.', 'done'),
      footLink('Buka umur hutang', () => navigate('reports?tab=ap-aging')),
    ]);
  }

  const total = Number(report.total_outstanding || 0);
  const lewat = AGING_BUCKETS
    .filter((bucket) => bucket.key !== 'current')
    .reduce((sum, bucket) => sum + Number(buckets[bucket.key] || 0), 0);

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, el('.stat-row', [
      /* Ubin ini dulu dirakit tangan dengan nama kelas yang SAMA seperti
         stat() tetapi tanpa memanggilnya (UI-5). Bentuknya kebetulan cocok
         hari ini; yang tidak ikut adalah setiap perubahan BERIKUTNYA pada
         ubin — dan yang pertama sudah datang di paket ini, berupa perataan
         subgrid. Formatter angkanya sengaja TIDAK diubah: memilih presisi
         sebuah ubin uang adalah keputusan isi, bukan perapian gaya. */
      /* rupiahShort, bukan rupiah penuh (UI-5). format.js:33 sudah menamai
         bentuk ini "Compact rupiah for dashboard tiles", dan bilah di bawah
         ubin ini memang memakainya — jadi sampai sekarang widget ini tidak
         sepakat dengan DIRINYA SENDIRI: "Rp 14.550.000.000" di ubin, di atas
         bilah yang menulis angka yang sama sebagai "Rp 14,5 M". Angka penuhnya
         satu klik jauhnya lewat kaki kartu. */
      stat('Sisa hutang', fmt.rupiahShort(total), { sub: 'seluruh tagihan terbuka' }),
      stat('Sudah jatuh tempo', fmt.rupiahShort(lewat), {
        sub: total > 0 ? `${Math.round((lewat / total) * 100)}% dari sisa` : 'tidak ada',
        tone: lewat > 0 ? 'down' : null,
      }),
    ])),
    barRows(agingEntries(buckets), { format: (value) => fmt.rupiahShort(value) }),
    footLink('Buka umur hutang', () => navigate('reports?tab=ap-aging')),
  ]);
}
