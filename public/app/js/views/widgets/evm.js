/* Widget "Kinerja EVM portofolio" (P1-D) - GET projects/evm.
   Angka-angkanya dijumlah dengan ATURAN YANG SAMA seperti layar EVM
   (views/evm.js portfolioView), termasuk dua penolakan yang membuatnya jujur:
   hanya proyek BERBASELINE yang ikut dijumlah, dan CPI portofolio hanya
   dihitung bila SETIAP proyek berbaseline biayanya lengkap - menjumlahkan
   proyek yang biayanya baru sebagian ke dalam satu angka gabungan
   menyembunyikan justru kelemahan yang server sudah repot tandai per proyek. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, statRow, stat, footLink, tileEmpty } from './kit.js';

const index = (value) => (value === null ? '—' : value.toFixed(2));
// Ambang yang sama dengan layar EVM: < 0,95 buruk, > 1,05 baik.
const tone = (value) => (value === null ? undefined : (value < 0.95 ? 'down' : (value > 1.05 ? 'up' : undefined)));

export async function build({ reload }) {
  const payload = await safe('projects/evm');
  if (failure(payload)) return failedBody(failure(payload), reload);

  const rows = (payload && payload.rows) || [];
  if (!rows.length) return tileEmpty('Belum ada proyek yang terdaftar.');

  const per = fmt.date(payload.as_of);
  const measured = rows.filter((row) => row.baseline_code);
  const sum = (key) => measured.reduce((total, row) => total + (Number(row[key]) || 0), 0);

  const pv = sum('pv');
  const ev = sum('ev');
  const ac = sum('ac');
  const spi = pv > 0 ? ev / pv : null;
  const incomplete = measured.filter((row) => !row.cpi_reliable);
  const cpi = incomplete.length === 0 && ac > 0 ? ev / ac : null;

  return el('div', [
    el('.card-body', { style: { paddingBottom: '0' } }, statRow([
      stat('Proyek terukur', `${measured.length}/${rows.length}`, {
        sub: measured.length === rows.length
          ? 'seluruh proyek punya baseline beku'
          : `${rows.length - measured.length} proyek belum punya baseline`,
        tone: measured.length === rows.length ? undefined : 'down',
      }),
      stat('SPI portofolio', index(spi), {
        sub: spi === null ? 'belum ada nilai rencana' : `Σ EV ÷ Σ PV per ${per}`,
        tone: tone(spi),
      }),
      stat('CPI portofolio', index(cpi), {
        sub: incomplete.length
          ? `${incomplete.length} proyek biaya aktualnya belum lengkap`
          : (cpi === null ? 'belum ada biaya tercatat' : `Σ EV ÷ Σ AC per ${per}`),
        tone: tone(cpi),
      }),
    ])),
    el('.card-body', { style: { paddingTop: '0' } }, statRow([
      stat('Nilai diperoleh (Σ EV)', fmt.rupiahShort(ev), {
        // Tanpa satu pun proyek berbaseline seluruh jumlah ini nol, dan nol
        // hijau akan terbaca "tepat jadwal" padahal artinya "tidak ada yang
        // diukur".
        sub: measured.length ? `selisih jadwal ${fmt.rupiahShort(ev - pv)}` : 'tidak ada yang diukur',
        tone: measured.length ? (ev < pv ? 'down' : 'up') : undefined,
      }),
      stat('Biaya aktual (Σ AC)', fmt.rupiahShort(ac), { sub: `biaya proyek s.d. ${per}` }),
    ])),
    footLink('Buka EVM', () => navigate('evm')),
  ]);
}
