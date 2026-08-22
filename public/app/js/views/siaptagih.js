/* Termin siap ditagih — antrean serah-terima dari PM ke Finance.

   Sebelum layar ini, sambungan itu hidup di luar aplikasi: milestone "syarat
   penagihan" tercapai dan tidak ada satu pun sinyal yang keluar. Di data demo
   akibatnya terbaca telanjang — Rp 14,55 miliar pekerjaan yang syaratnya sudah
   terpenuhi sejak 27 Maret masih belum ditagih empat bulan kemudian, dan satu
   kuartal penuh kontrak pemeliharaan lewat begitu saja.

   Server sudah mengurutkan yang paling lama menunggu di atas; jangan diurut
   ulang di sini. */

import { api, session } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

const ALASAN = {
  milestone: ['Milestone tercapai', 'green'],
  jadwal: ['Jadwal jatuh tempo', 'amber'],
};

/** Umur tunggu adalah angka yang menjual layar ini — beri warna sesuai bebannya. */
function warnaTunggu(days) {
  if (days >= 60) return 'var(--danger)';
  if (days >= 30) return 'var(--warning)';
  return 'var(--text)';
}

export async function renderSiapTagih(host) {
  clear(host);
  const reload = () => renderSiapTagih(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Termin Siap Ditagih' }),
      el('.desc', {
        text: 'Termin yang syarat penagihannya sudah terpenuhi — milestone tercapai atau '
          + 'jadwalnya jatuh tempo — tetapi invoicenya belum terbit.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 6));

  let payload;
  try {
    payload = await api.list('crm/contract-termins/billing-ready');
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  const rows = payload.data || [];
  const meta = payload.meta || {};

  clear(body);

  if (!rows.length) {
    body.appendChild(el('.alert.info',
      'Tidak ada termin yang menunggu ditagih. Baris muncul di sini begitu milestone '
      + 'syarat penagihan tercapai, atau tanggal rencana tagih terlewati.'));
    return;
  }

  const terlama = rows[0];

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Nilai siap ditagih' }),
      el('.value.sm', { text: fmt.rupiah(meta.total_amount) }),
      el('.delta.down', { text: `${meta.count ?? rows.length} termin menunggu invoice` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Menunggu terlama' }),
      el('.value.sm', { text: `${terlama.days_waiting} hari` }),
      el('.delta.down', { text: `${terlama.contract_code} · ${fmt.rupiah(terlama.amount)}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Dari milestone' }),
      el('.value.sm', { text: String(rows.filter((r) => r.reason === 'milestone').length) }),
      el('.delta', { text: `${rows.filter((r) => r.reason === 'jadwal').length} dari jadwal kalender` }),
    ]),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Antrean penagihan' }),
      el('.cell-sub', { text: 'paling lama menunggu di atas' }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kontrak / pelanggan' }),
        el('th', { text: 'Termin' }),
        el('th', { text: 'Pemicu' }),
        el('th.right', { text: 'Menunggu' }),
        el('th.right', { text: 'Nilai' }),
        el('th', { text: '' }),
      ])),
      el('tbody', rows.map((row) => {
        const [label, tone] = ALASAN[row.reason] || [row.reason, ''];

        return el('tr', [
          el('td', el('span', [
            el('span.cell-main.mono', { text: row.contract_code }),
            el('span.cell-sub', { text: row.customer_name || row.contract_title || '' }),
          ])),
          el('td', el('span', [
            el('span.cell-main', { text: `#${row.termin_no} ${row.termin_name || ''}`.trim() }),
            row.billing_condition ? el('span.cell-sub', { text: row.billing_condition }) : null,
          ])),
          el('td', el('span', [
            badge(label, tone),
            el('.cell-sub', { text: fmt.date(row.trigger_date) }),
            row.milestone_name ? el('.cell-sub', { text: row.milestone_name }) : null,
          ])),
          el('td.right.num.strong', {
            text: `${row.days_waiting} hari`,
            style: { color: warnaTunggu(row.days_waiting) },
          }),
          el('td.right.num.strong', { text: fmt.rupiah(row.amount) }),
          el('td.right', button('Buka kontrak', {
            size: 'sm',
            variant: 'primary',
            // Tombol "Tagih termin ini" ada di baris termin pada detail kontrak,
            // lengkap dengan konteks nilai dan syaratnya.
            onClick: () => { window.location.hash = row.link; },
          })),
        ]);
      })),
    ])),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', { text: 'Termin progres dilepas oleh milestone: begitu tanggal tercapainya diisi di layar Milestone, pemegang izin buat-invoice menerima pemberitahuan dan terminnya masuk antrean ini.' }),
      el('p', { text: 'Termin kalender (kontrak pemeliharaan triwulanan) tidak punya milestone — ia dipicu oleh "Rencana tagih" pada jadwal termin di detail kontrak. Termin tanpa tanggal itu tidak akan pernah muncul di sini.' }),
      el('p', { text: 'Baris hilang dari antrean segera setelah invoice terminnya disetujui.' }),
    ]),
  ]));

  if (session.can('fin.create')) {
    body.appendChild(el('.row-actions', [
      button('Lihat invoice termin', { iconName: 'chevron', onClick: () => navigate('r/finance/ar-invoices') }),
    ]));
  }
}
