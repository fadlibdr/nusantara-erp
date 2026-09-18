/* Widget "Proyeksi kas" (P1-D) - GET finance/reports/cash-projection.
   Tiga angka yang menjawab "cukupkah kas": saldo hari ini, titik TERENDAH dan
   minggu terjadinya, saldo akhir jendela. Titik terendah yang negatif adalah
   satu-satunya angka di dasbor ini yang boleh membuat orang menelepon bank,
   jadi ia tidak pernah dipangkas ke nol dan warnanya menyebut tandanya. */

import { el, icon } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, footLink, stat } from './kit.js';

export async function build({ reload }) {
  const report = await safe('finance/reports/cash-projection', { days: 90 });
  if (failure(report)) return failedBody(failure(report), reload);

  const opening = (report && report.opening) || {};
  const lowest = (report && report.lowest) || {};
  const warnings = (report && report.warnings) || [];
  const ending = Number(report && report.ending_balance);

  const money = (value) => (Number.isFinite(Number(value)) ? fmt.rupiah(value) : '—');
  // Kelas, bukan gaya sebaris: sejak UI-5 stat() menerima valueTone.
  const negative = (value) => (Number(value) < 0 ? 'tone-neg' : null);

  return el('div', [
    warnings.length
      ? el('.card-body', { style: { paddingBottom: '0' } }, el('.alert.error', { style: { margin: 0 } }, [
        icon('warn', 15),
        el('div', warnings.map((warning) => el('div', { text: warning }))),
      ]))
      : null,
    el('.card-body', el('.stat-row', [
      /* Ketiga ubin ini dulu dirakit tangan dengan nama kelas yang SAMA seperti
         stat() tetapi tanpa memanggilnya (UI-5) — satu-satunya alasannya adalah
         pewarnaan saldo negatif, yang dulu tidak punya jalan lewat stat() dan
         karena itu ditulis sebagai tiga gaya sebaris. `valueTone` sekarang
         menyediakannya sebagai kelas, dan ubinnya ikut setiap perubahan
         berikutnya pada ubin — yang pertama sudah datang di paket ini, berupa
         perataan subgrid. */
      // Jam klien tidak dipercaya: "hari ini" adalah as_of dari server.
      stat('Saldo kas hari ini', money(opening.total), {
        sub: `per ${fmt.date(report.as_of)}`,
        valueTone: negative(opening.total),
      }),
      stat('Titik terendah', money(lowest.balance), {
        sub: lowest.label ? `minggu ${lowest.label}` : 'belum terhitung',
        tone: Number(lowest.balance) < 0 ? 'down' : null,
        valueTone: negative(lowest.balance),
      }),
      el('.stat', [
        el('.label', { text: `Saldo akhir (${report.days || 90} hari)` }),
        el(`.value.sm${negative(ending) ? `.${negative(ending)}` : ''}`, { text: money(ending) }),
        /* TIGA keadaan, bukan dua. `Number(undefined)` adalah NaN, dan
           `NaN < 0` salah — jadi cabang dua-arah melaporkan sebuah angka yang
           TIDAK DIKETAHUI sebagai 'masih positif' berwarna hijau, tepat di
           bawah nilai yang digambar '—'. Itu bentuk yang aturan rumah paket
           ini larang: nol (dan hijau) adalah pernyataan tentang uang
           (verifikasi kedua P1-D). */
        Number.isFinite(ending)
          ? el(`.delta${ending < 0 ? '.down' : '.up'}`, { text: ending < 0 ? 'defisit di akhir jendela' : 'masih positif' })
          : el('.delta', { text: 'tidak diketahui' }),
      ]),
    ])),
    footLink('Buka proyeksi 90 hari', () => navigate('reports?tab=cash-projection')),
  ]);
}
