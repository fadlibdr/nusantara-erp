/* Analitik win-rate tender (temuan #78).

   won_at/lost_at/lost_reason sudah dicatat aksi Tandai Menang/Kalah sejak
   awal, dan tidak satu layar pun membacanya kembali: keputusan pricing dan
   pemilihan tender berjalan tanpa data yang sebenarnya sudah tersimpan.
   Layar ini murni membaca crm/reports/pipeline — win-rate per kuartal
   KEPUTUSAN (bukan kuartal penawaran dibuat), nilai menang vs kalah, dan
   alasan kalah terbanyak. */

import { api } from '../api.js';
import { el, clear, button, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';

const pct = (value) => (value === null || value === undefined ? '—' : `${Number(value).toLocaleString('id-ID')}%`);

export async function renderPipeline(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Analitik Win-Rate' }),
      el('.desc', { text: 'Hasil tender dari penawaran yang sudah diputuskan — per kuartal keputusan, dengan alasan kalah terbanyak.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderPipeline(host) })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(5, 6));

  let report;
  try {
    report = await api.get('crm/reports/pipeline');
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderPipeline(host)));
  }

  clear(body);

  const totals = report.totals || {};
  const quarters = report.quarters || [];
  const reasons = report.lose_reasons || [];

  if (!quarters.length) {
    body.appendChild(el('.alert.info', 'Belum ada penawaran yang diputuskan menang atau kalah. '
      + 'Win-rate dihitung dari aksi "Tandai Menang" / "Tandai Kalah" pada penawaran — '
      + 'tanpa keputusan yang dicatat, tidak ada yang bisa dianalisis.'));
    return;
  }

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Win-rate keseluruhan' }),
      el('.value.sm', { text: pct(totals.win_rate) }),
      el('.delta', { text: `${totals.won_count ?? 0} menang · ${totals.lost_count ?? 0} kalah` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Nilai dimenangkan' }),
      el('.value.sm', { text: fmt.rupiah(totals.won_value) }),
      el('.delta', { text: 'DPP — angka yang sama dengan nilai kontraknya' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Nilai kalah' }),
      el('.value.sm', { text: fmt.rupiah(totals.lost_value) }),
      el('.delta', { text: 'tender yang lepas — pelajari alasannya di bawah' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Masih berjalan' }),
      el('.value.sm', { text: String(totals.undecided_count ?? 0) }),
      /* Penawaran yang ditolak internal bukan 'masih berjalan' — server
         mengeluarkannya dari undecided; label ini menyebut pengecualian itu
         supaya angkanya tidak dikira salah hitung. */
      el('.delta', { text: `senilai ${fmt.rupiah(totals.undecided_value)}`
        + (totals.rejected_count ? ` · ${totals.rejected_count} ditolak internal tidak dihitung` : '') }),
    ]),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Win-rate per kuartal' }),
      el('.cell-sub', { text: 'kuartal diambil dari tanggal keputusan, bukan tanggal penawaran dibuat' }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kuartal' }),
        el('th.right', { text: 'Menang' }),
        el('th.right', { text: 'Kalah' }),
        el('th.right', { text: 'Win-rate' }),
        el('th.right', { text: 'Nilai menang' }),
        el('th.right', { text: 'Nilai kalah' }),
      ])),
      el('tbody', quarters.map((row) => el('tr', [
        el('td.mono', { text: row.quarter }),
        el('td.right.num', { text: String(row.won_count) }),
        el('td.right.num', { text: String(row.lost_count) }),
        el('td.right.num', { text: pct(row.win_rate) }),
        el('td.right.num', { text: fmt.rupiah(row.won_value) }),
        el('td.right.num', { text: fmt.rupiah(row.lost_value) }),
      ]))),
    ])),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Alasan kalah terbanyak' }),
      el('.cell-sub', { text: 'dari kolom "alasan kalah" yang wajib diisi saat Tandai Kalah' }),
    ]),
    reasons.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Alasan' }),
          el('th.right', { text: 'Berapa kali' }),
          el('th.right', { text: 'Nilai yang hilang' }),
        ])),
        el('tbody', reasons.map((row) => el('tr', [
          el('td', { text: row.reason }),
          el('td.right.num', { text: String(row.count) }),
          el('td.right.num', { text: fmt.rupiah(row.value) }),
        ]))),
      ]))
      : el('.card-body', el('p.muted', { text: 'Belum ada penawaran kalah — belum ada alasan untuk dianalisis.', style: { margin: 0 } })),
  ]));
}
