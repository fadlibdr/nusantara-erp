/* Piutang retensi — 5% yang ditahan pelanggan di tiap termin, baru bisa ditagih
   setelah masa pemeliharaan berakhir (BAST II).

   Endpoint-nya sudah ada sejak retensi dibangun dan tidak pernah dipanggil satu
   layar pun: tabelnya ditulis setiap invoice termin disetujui lalu dibaca oleh
   NOL kode. Akibatnya saldo 1-1350 menumpuk tanpa ada yang menagih — persis
   yang tertulis di docblock service-nya, terulang di lapisan tampilan. */

import { api, session } from '../api.js';
import { el, clear, button, badge, toast, toastError, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor, preload } from '../lookup.js';
import { promptFields } from './form.js';
import { navigate } from '../router.js';

export async function renderRetensi(host) {
  clear(host);
  const reload = () => renderRetensi(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Piutang Retensi' }),
      el('.desc', {
        text: 'Retensi yang ditahan pelanggan dari tiap termin. Jatuh tempo tagih mengikuti '
          + 'tanggal pada BAST — biasanya akhir masa pemeliharaan.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const body = el('div');
  host.appendChild(body);
  body.appendChild(skeletonTable(4, 6));

  let data;
  try {
    [data] = await Promise.all([api.get('finance/ar-retentions'), preload(['projects'])]);
  } catch (error) {
    return clear(body).appendChild(errorState(error, reload));
  }

  clear(body);

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Total retensi tertahan' }),
      el('.value.sm', { text: fmt.rupiah(data.total_outstanding) }),
      el('.delta', { text: `${data.rows.length} baris dari invoice termin` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Sudah boleh ditagih' }),
      el('.value.sm', { text: fmt.rupiah(data.due_now) }),
      data.due_now > 0
        ? el('.delta.down', { text: 'masa pemeliharaan sudah lewat — tagih sekarang' })
        : el('.delta', { text: 'belum ada yang jatuh tempo' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Posisi per' }),
      el('.value.sm', { text: fmt.date(data.as_of) }),
      el('.delta', { text: 'akun 1-1350 Piutang Retensi' }),
    ]),
  ]));

  if (!data.rows.length) {
    body.appendChild(el('.alert.info',
      'Belum ada retensi tertahan. Baris muncul otomatis setiap invoice termin yang '
      + 'memotong retensi disetujui.'));
    return;
  }

  const canRelease = session.can('fin.post');

  const cair = (row) => async () => {
    const banks = await loadSource('bankAccounts').catch(() => []);

    if (!banks.length) {
      toast('Belum ada rekening bank. Buat dulu di Keuangan › Rekening Bank.');
      return;
    }

    const values = await promptFields(`Cairkan retensi ${row.project || ''}`.trim(), [
      {
        key: 'received_on', label: 'Tanggal uang diterima', type: 'date', required: true,
        default: fmt.today(),
      },
      {
        key: 'bank_account_id', label: 'Masuk ke rekening', type: 'select', required: true,
        options: optionsFor('bankAccounts', banks),
      },
    ], { submitLabel: `Catat penerimaan ${fmt.rupiah(row.amount)}` });

    if (values === null) return;

    try {
      await api.post(`finance/ar-retentions/${row.id}/release`, values);
      toast('Retensi dicairkan — jurnal penerimaan diposting.');
      reload();
    } catch (error) {
      toastError(error);
    }
  };

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Retensi belum cair' }),
      el('.cell-sub', { text: 'yang jatuh tempo di atas' }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Proyek' }),
        el('th', { text: 'Kontrak' }),
        el('th', { text: 'Dari invoice' }),
        el('th', { text: 'Jatuh tempo tagih' }),
        el('th.right', { text: 'Nilai retensi' }),
        el('th', { text: '' }),
      ])),
      // Yang sudah jatuh tempo naik ke atas: itu satu-satunya alasan layar ini ada.
      el('tbody', [...data.rows]
        .sort((a, b) => (b.is_due ? 1 : 0) - (a.is_due ? 1 : 0)
          || String(a.due_date || '9999').localeCompare(String(b.due_date || '9999')))
        .map((row) => el('tr', [
          el('td', el('span', [
            el('span.cell-main', { text: row.project_name || row.project || '—' }),
            el('span.cell-sub.mono', { text: row.project || '' }),
          ])),
          el('td.mono', { text: row.contract || '—' }),
          el('td.mono', { text: row.source_invoice || '—' }),
          el('td', row.due_date
            ? el('span', [
              el('span', { text: fmt.date(row.due_date) }),
              row.is_due ? el('div', badge('Sudah boleh ditagih', 'red')) : null,
            ])
            // Tanpa BAST, tanggalnya tidak diketahui — bukan berarti belum jatuh tempo.
            : el('span.muted', { text: 'Belum ada BAST', title: 'Terbitkan BAST agar tanggal pencairannya diketahui' })),
          el('td.right.num.strong', { text: fmt.rupiah(row.amount) }),
          el('td.right', canRelease
            ? button('Catat pencairan', { size: 'sm', variant: row.is_due ? 'primary' : 'ghost', onClick: cair(row) })
            : null),
        ]))),
    ])),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', { text: 'Retensi ditahan otomatis saat invoice termin yang memotongnya disetujui: nilainya pindah dari Piutang Usaha ke 1-1350 Piutang Retensi, jadi ia tidak ikut tampil sebagai piutang jatuh tempo biasa.' }),
      el('p', { text: 'Tanggal jatuh tempo tagih diambil dari BAST proyek (retention_release_due) — umumnya akhir masa pemeliharaan. Proyek tanpa BAST tidak punya tanggal, dan itu sendiri adalah pekerjaan yang tertinggal.' }),
      el('p', { text: 'Mencatat pencairan memposting penerimaan kas dan menutup baris ini; saldo 1-1350 di neraca ikut turun.' }),
    ]),
  ]));

  body.appendChild(el('.row-actions', [
    button('Lihat BAST proyek', { iconName: 'chevron', onClick: () => navigate('r/projects/bast') }),
  ]));
}
