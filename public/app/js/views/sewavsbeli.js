/* Evaluasi Sewa vs Beli (P5, deviasi 3.5 "sewa-vs-beli") — BACA SAJA.

   Satu baris per aset hidup: sisi SEWA (jam register x tarif, atau kalender x
   tarif untuk basis bulanan/harian) berdampingan dengan sisi BELI (harga
   perolehan, penyusutan). Pembacanya yang membandingkan antar baris; layar
   ini tidak menulis apa pun dan tidak menyimpan kesimpulan apa pun.

   KEJUJURAN SEL, dari RentVsOwnService dan dijaga di sini:
   - alat sewa tanpa jam tercatat tampil '—' (bergaris), bukan 0 — "belum ada
     data" dan "tidak pernah dipakai" adalah dua kalimat berbeda;
   - aset beli tanpa harga perolehan berkata "Tidak dapat dibandingkan",
     bukan dibandingkan dengan Rp 0;
   - kolom Catatan memuat alasan service persis, bukan ringkasan layar. */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable, emptyState } from '../ui.js';
import * as fmt from '../format.js';
import { enumLabel } from '../enums.js';
import { navigate } from '../router.js';

export async function renderSewaVsBeli(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Evaluasi Sewa vs Beli' }),
      el('.desc', { text: 'Baca saja: jam register × tarif sewa vs harga perolehan/penyusutan. Tidak ada yang ditulis, tidak ada kesimpulan yang disimpan.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderSewaVsBeli(host) })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(6, 9));

  let report;
  try {
    report = await api.get('assets/reports/rent-vs-own');
  } catch (error) {
    return clear(body).appendChild(errorState(error, () => renderSewaVsBeli(host)));
  }

  clear(body);
  const rows = report.rows || [];

  if (!rows.length) {
    body.appendChild(emptyState('Belum ada aset hidup untuk dibandingkan.'));
    return;
  }

  // '—' adalah sel bergaris layar: nilai yang datanya tidak ada, bukan nol.
  const money = (value) => fmt.rupiah(value, { decimals: 2 });
  const hours = (value) => (value === null || value === undefined ? '—' : fmt.qty(value, 'jam'));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Perbandingan per aset' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Aset' }),
        el('th', { text: 'Kepemilikan' }),
        el('th.right', { text: 'Jam tercatat' }),
        el('th.right', { text: 'Tarif sewa' }),
        el('th.right', { text: 'Biaya sewa berjalan' }),
        el('th.right', { text: 'Harga perolehan' }),
        el('th.right', { text: 'Akum. penyusutan' }),
        el('th.right', { text: 'Biaya per jam' }),
        el('th', { text: 'Catatan' }),
      ])),
      el('tbody', rows.map((row) => {
        const node = el('tr', { style: { cursor: 'pointer' } }, [
          el('td', [
            el('div.mono', { text: row.asset_code || '—' }),
            el('.cell-sub', { text: [row.asset_name, row.category].filter(Boolean).join(' · ') }),
          ]),
          el('td', [
            badge(enumLabel('assetOwnership', row.ownership) || '—', row.ownership === 'rented' ? 'blue' : ''),
            row.vendor_name ? el('.cell-sub', { text: row.vendor_name }) : null,
          ]),
          el('td.right.num', { text: hours(row.hours_logged) }),
          el('td.right.num', row.rental_rate !== null && row.rental_rate !== undefined
            ? { text: `${money(row.rental_rate)} ${(enumLabel('rateBasis', row.rate_basis) || '').toLowerCase()}`.trim() }
            : { text: '—' }),
          el('td.right.num', { text: money(row.rental_cost) }),
          el('td.right.num', { text: money(row.acquisition_cost) }),
          el('td.right.num', { text: money(row.accumulated_depreciation) }),
          el('td.right.num.strong', { text: money(row.cost_per_hour) }),
          el('td', { text: row.note || '—', style: row.comparable ? {} : { color: 'var(--warning)' } }),
        ]);
        node.addEventListener('click', () => navigate(`d/assets/assets/${row.asset_id}`));
        return node;
      })),
    ])),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara membaca' })),
    el('.card-body', [
      el('p', { text: 'Jam tercatat = Σ (pembacaan hour-meter terakhir − pertama) per mobilisasi hidup aset itu. Mobilisasi dengan kurang dari dua pembacaan tidak menyumbang jam yang terukur.' }),
      el('p', { text: 'Biaya sewa berjalan: per jam = jam tercatat × tarif; per hari/bulan dihitung dari periode sewa pada master aset. Biaya per jam sisi beli = akumulasi penyusutan ÷ jam tercatat.' }),
      el('p', { text: 'Sel bergaris (—) berarti datanya belum ada — bukan nol. Baris "Tidak dapat dibandingkan" berarti salah satu sisinya tidak punya angka yang jujur untuk dibandingkan.' }),
    ]),
  ]));
}
