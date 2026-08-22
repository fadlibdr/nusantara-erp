/* Arus kas — dua tab laporan yang badannya dipanggil dari reports.js:
 *
 *  - 'Arus Kas'            : laporan PSAK 2 metode langsung (historis)
 *  - 'Proyeksi Kas 90 Hari': saldo berjalan mingguan ke depan
 *
 * File terpisah dari reports.js dengan sengaja: reports.js hanya menambah dua
 * entri TABS + dua baris builders, sehingga laporan lama tidak tersentuh.
 * Kontrak angka penting di sini: bucket 'Lewat jatuh tempo' TIDAK ikut saldo
 * berjalan (piutang telat tidak diketahui kapan cair), sedangkan hutang telat
 * sudah dibebankan server ke minggu pertama — jadi baris overdue digambar
 * berbeda dan tidak menampilkan saldo.
 */

import { api } from '../api.js';
import { el, icon } from '../ui.js';
import * as fmt from '../format.js';

const ACTIVITY_LABELS = {
  operasi: 'Aktivitas Operasi',
  investasi: 'Aktivitas Investasi',
  pendanaan: 'Aktivitas Pendanaan',
  lainnya: 'Belum Terpetakan (Lainnya)',
};

function moneyCell(value) {
  return el('td.right.num', { text: Number(value) === 0 ? '—' : fmt.rupiah(value) });
}

function dateInput(label, value, onChange) {
  const input = el('input.filter-w', { type: 'date', value, title: label, 'aria-label': label });
  input.addEventListener('change', () => onChange(input.value));
  return input;
}

/* ------------------------------------------------------------- laporan */

function activityCard(key, activity) {
  const showJournals = key === 'lainnya';

  return el('.card', [
    el('.card-head', [
      el('h2', { text: ACTIVITY_LABELS[key] }),
      el('.spacer'),
      el('span.num', { text: fmt.rupiah(activity.total), style: { fontWeight: '700' } }),
    ]),
    activity.rows.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }), el('th', { text: 'Akun lawan' }),
          el('th.right', { text: 'Masuk' }), el('th.right', { text: 'Keluar' }),
          el('th.right', { text: 'Neto' }),
        ])),
        el('tbody', activity.rows.map((row) => el('tr', [
          el('td.code', { text: row.account_code, style: { width: '1%' } }),
          el('td', showJournals
            ? [
              el('span', { text: row.account_name }),
              el('span.cell-sub.mono', { text: (row.journal_codes || []).join(', ') }),
            ]
            : { text: row.account_name }),
          moneyCell(row.inflow), moneyCell(row.outflow), moneyCell(row.net),
        ]))),
      ]))
      : el('.card-body', el('p.muted', { text: 'Tidak ada arus.', style: { margin: 0 } })),
  ]);
}

export async function cashFlowStatement(controls, params, refresh) {
  const now = new Date();
  const from = params.from || `${now.getFullYear()}-01-01`;
  const to = params.to || fmt.toDateInput(now);

  controls.append(
    dateInput('Dari', from, (value) => { params.from = value; refresh(); }),
    dateInput('Sampai', to, (value) => { params.to = value; refresh(); }),
  );

  const report = await api.get('finance/reports/cash-flow', { from, to });
  const act = report.activities;
  const lainnyaMoved = act.lainnya.rows.length > 0;

  const line = (label, value, { strong = false, tone } = {}) => el('div', {
    style: {
      display: 'flex', justifyContent: 'space-between', gap: '16px',
      padding: strong ? '11px 0' : '6px 0',
      borderTop: strong ? '1px solid var(--border)' : 'none',
      fontWeight: strong ? '700' : '400',
      fontSize: strong ? '14px' : '13px',
      color: tone === 'bad' ? 'var(--danger)' : (tone === 'good' ? 'var(--success)' : 'inherit'),
    },
  }, [el('span', { text: label }), el('span.num', { text: fmt.rupiah(value) })]);

  return el('div', [
    el(`.alert.${report.reconciled ? 'info' : 'error'}`, [
      icon(report.reconciled ? 'check' : 'warn', 15),
      el('div', {
        text: report.reconciled
          ? `Rekonsiliasi cocok: saldo awal + arus per aktivitas = saldo akhir (${fmt.date(report.from)} s.d. ${fmt.date(report.to)}).`
          : 'PERHATIAN: saldo awal + arus per aktivitas TIDAK sama dengan saldo akhir — ada mutasi kas yang tidak terjelaskan.',
      }),
    ]),
    lainnyaMoved
      ? el('.alert.warn', { style: { marginTop: '10px' } }, [
        icon('warn', 15),
        el('div', {
          text: `Ada ${act.lainnya.rows.length} akun lawan yang belum terpetakan ke aktivitas PSAK 2 `
            + `(total ${fmt.rupiah(act.lainnya.total)}) — lihat bagian "Belum Terpetakan" di bawah beserta contoh jurnalnya.`,
        }),
      ])
      : null,
    el('.stat-row', { style: { marginTop: '14px' } }, [
      el('.stat', [el('.label', { text: 'Saldo awal kas' }), el('.value.sm', { text: fmt.rupiah(report.opening_balance) })]),
      el('.stat', [
        el('.label', { text: 'Perubahan neto' }),
        el('.value.sm', { text: fmt.rupiah(report.net_change), style: { color: report.net_change < 0 ? 'var(--danger)' : 'var(--success)' } }),
      ]),
      el('.stat', [el('.label', { text: 'Saldo akhir kas' }), el('.value.sm', { text: fmt.rupiah(report.closing_balance) })]),
    ]),
    activityCard('operasi', act.operasi),
    activityCard('investasi', act.investasi),
    activityCard('pendanaan', act.pendanaan),
    lainnyaMoved ? activityCard('lainnya', act.lainnya) : null,
    el('.card', el('.card-body', [
      line('Saldo awal kas', report.opening_balance),
      line('Arus kas dari aktivitas operasi', act.operasi.total),
      line('Arus kas dari aktivitas investasi', act.investasi.total),
      line('Arus kas dari aktivitas pendanaan', act.pendanaan.total),
      lainnyaMoved ? line('Arus belum terpetakan', act.lainnya.total) : null,
      line('Saldo akhir kas', report.closing_balance, {
        strong: true, tone: report.closing_balance < 0 ? 'bad' : 'good',
      }),
      // Info, bukan aktivitas: transfer antar rekening kas tidak mengubah total.
      line('Mutasi antar rekening (info)', report.internal_transfers),
    ])),
    el('.card', [
      el('.card-head', el('h2', { text: 'Saldo per rekening' })),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }), el('th', { text: 'Rekening' }),
          el('th.right', { text: 'Saldo awal' }), el('th.right', { text: 'Saldo akhir' }),
        ])),
        el('tbody', report.accounts.map((row) => el('tr', [
          el('td.code', { text: row.account_code, style: { width: '1%' } }),
          el('td', { text: row.account_name }),
          moneyCell(row.opening), moneyCell(row.closing),
        ]))),
      ])),
    ]),
    el('.alert.info', { style: { marginTop: '14px' } }, [
      icon('warn', 15),
      el('div', report.policy.map((sentence) => el('div', { text: sentence }))),
    ]),
  ]);
}

/* ------------------------------------------------------------ proyeksi */

function daysInput(value, onChange) {
  const input = el('input.filter-w', {
    type: 'number', value, min: 7, max: 180, step: 1, title: 'Horizon (hari)', 'aria-label': 'Horizon (hari)',
  });
  input.addEventListener('change', () => onChange(input.value));
  return input;
}

export async function cashProjection(controls, params, refresh) {
  const days = Number(params.days || 90);

  controls.append(daysInput(days, (value) => { params.days = value; refresh(); }));

  const report = await api.get('finance/reports/cash-projection', { days });
  const weekly = report.buckets.filter((bucket) => bucket.key !== 'overdue');
  const overdueBucket = report.buckets.find((bucket) => bucket.key === 'overdue');

  return el('div', [
    report.warnings.length
      ? el('.alert.error', [
        icon('warn', 15),
        el('div', report.warnings.map((warning) => el('div', { text: warning }))),
      ])
      : null,
    el('.stat-row', { style: { marginTop: report.warnings.length ? '14px' : '0' } }, [
      el('.stat', [
        el('.label', { text: 'Saldo kas hari ini' }),
        el('.value.sm', { text: fmt.rupiah(report.opening.total) }),
        // Jam klien tidak dipercaya: "hari ini" adalah as_of dari server.
        el('.delta', { text: `per ${fmt.date(report.as_of)}` }),
      ]),
      el('.stat', [
        el('.label', { text: 'Titik terendah' }),
        el('.value.sm', {
          text: fmt.rupiah(report.lowest.balance),
          style: { color: report.lowest.balance < 0 ? 'var(--danger)' : 'inherit' },
        }),
        el('.delta', { text: `minggu ${report.lowest.label}` }),
      ]),
      el('.stat', [
        el('.label', { text: `Saldo akhir (${report.days} hari)` }),
        el('.value.sm', {
          text: fmt.rupiah(report.ending_balance),
          style: { color: report.ending_balance < 0 ? 'var(--danger)' : 'var(--success)' },
        }),
      ]),
    ]),
    el('.card', [
      el('.card-head', el('h2', { text: 'Saldo berjalan per minggu' })),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Minggu' }),
          el('th.right', { text: 'Masuk AR' }), el('th.right', { text: 'Masuk termin' }),
          el('th.right', { text: 'Keluar AP' }), el('th.right', { text: 'Gaji' }),
          el('th.right', { text: 'Pajak' }), el('th.right', { text: 'Pembayaran disetujui' }),
          el('th.right', { text: 'Neto' }), el('th.right', { text: 'Saldo' }),
        ])),
        el('tbody', [
          // Baris telat digambar beda dan tanpa saldo: piutangnya TIDAK ikut
          // saldo berjalan, hutangnya sudah dibebankan ke minggu pertama.
          overdueBucket
            ? el('tr', { style: { background: 'var(--surface-2, rgba(0,0,0,0.03))' } }, [
              el('td', [
                el('span', { text: overdueBucket.label, style: { fontWeight: '600' } }),
                el('span.cell-sub', {
                  text: `piutang ${report.overdue.ar.count} dok · hutang ${report.overdue.ap.count} dok`
                    + (report.overdue.ar.oldest_days ? ` · terlama ${report.overdue.ar.oldest_days} hari` : ''),
                }),
              ]),
              moneyCell(overdueBucket.inflow_ar), el('td.right', { text: '—' }),
              moneyCell(overdueBucket.outflow_ap), el('td.right', { text: '—' }),
              el('td.right', { text: '—' }), el('td.right', { text: '—' }),
              el('td.right', { text: '—' }), el('td.right', { text: '—' }),
            ])
            : null,
          ...weekly.map((week) => el('tr', [
            el('td', { text: week.label }),
            moneyCell(week.inflow_ar), moneyCell(week.inflow_termin),
            moneyCell(week.outflow_ap), moneyCell(week.outflow_payroll),
            moneyCell(week.outflow_tax), moneyCell(week.outflow_payments_approved),
            el('td.right.num', {
              text: fmt.rupiah(week.net),
              style: { color: week.net < 0 ? 'var(--danger)' : 'inherit' },
            }),
            el('td.right.num', {
              text: fmt.rupiah(week.running_balance),
              style: {
                fontWeight: '600',
                color: week.running_balance < 0 ? 'var(--danger)' : 'inherit',
              },
            }),
          ])),
        ]),
      ])),
    ]),
    report.kas_kecil
      ? el('.card', [
        el('.card-head', el('h2', { text: 'Kas kecil (informasi — bukan arus keluar)' })),
        el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: 'Dana' }),
            el('th.right', { text: 'Kebutuhan isi ulang' }),
            el('th.right', { text: 'Kasbon berjalan' }),
          ])),
          el('tbody', report.kas_kecil.funds.map((fund) => el('tr', [
            el('td', [el('span', { text: fund.name }), el('span.cell-sub.mono', { text: fund.code })]),
            moneyCell(fund.replenishment_due), moneyCell(fund.outstanding_kasbon),
          ]))),
          el('tfoot', el('tr', [
            el('td', { text: 'Total' }),
            moneyCell(report.kas_kecil.replenishment_due_total),
            moneyCell(report.kas_kecil.outstanding_kasbon_total),
          ])),
        ])),
      ])
      : null,
    el('.alert.info', { style: { marginTop: '14px' } }, [
      icon('warn', 15),
      el('div', [
        el('div', { text: 'Asumsi proyeksi:', style: { fontWeight: '600', marginBottom: '4px' } }),
        ...report.assumptions.map((sentence) => el('div', { text: `• ${sentence}` })),
      ]),
    ]),
  ]);
}
