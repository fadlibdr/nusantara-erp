/* Widget "Pipeline penjualan" (P1-D) - GET crm/reports/pipeline.
   Win-rate dihitung server dari keputusan yang BENAR-BENAR dicatat (aksi
   "Tandai Menang"/"Tandai Kalah"); penawaran yang ditolak internal dikeluarkan
   dari "masih berjalan", dan pengecualian itu ikut disebut supaya angkanya
   tidak dikira salah hitung. */

import { el } from '../../ui.js';
import * as fmt from '../../format.js';
import { navigate } from '../../router.js';
import { safe, failure, failedBody, statRow, stat, footLink, tileEmpty } from './kit.js';

const pct = (value) => (value === null || value === undefined ? '—' : `${fmt.num(Number(value) * 100, 1)}%`);

export async function build({ reload }) {
  const report = await safe('crm/reports/pipeline');
  if (failure(report)) return failedBody(failure(report), reload);

  const totals = (report && report.totals) || {};
  const quarters = (report && report.quarters) || [];

  if (!quarters.length) {
    return tileEmpty('Belum ada penawaran yang diputuskan menang atau kalah.');
  }

  return el('div', [
    el('.card-body', statRow([
      stat('Win-rate keseluruhan', pct(totals.win_rate), {
        sub: `${totals.won_count ?? 0} menang · ${totals.lost_count ?? 0} kalah`,
      }),
      stat('Nilai dimenangkan', fmt.rupiahShort(totals.won_value), { sub: 'DPP, sama dengan nilai kontraknya', tone: 'up' }),
      stat('Masih berjalan', String(totals.undecided_count ?? 0), {
        sub: `senilai ${fmt.rupiahShort(totals.undecided_value)}`
          + (totals.rejected_count ? ` · ${totals.rejected_count} ditolak internal tidak dihitung` : ''),
      }),
    ])),
    footLink('Buka analisis pipeline', () => navigate('pipeline')),
  ]);
}
