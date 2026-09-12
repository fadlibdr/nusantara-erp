/* Ekspor Pajak — e-Faktur (PPN keluaran) and e-Bupot (PPh dipotong).
 *
 * The screen's job is to make the file inspectable BEFORE it reaches DJP's
 * importer: what will be exported, what cannot be and why, and the totals to
 * reconcile against the ledger. The download is built client-side from the CSV
 * text the API returns, because the API authenticates on a header and a plain
 * download link carries none.
 *
 * P-3b — SATU KALIMAT KEJUJURAN, DARI SERVER. Setiap format berkas DJP/BPJS
 * yang ada atau direncanakan hidup di registri server (DjpFormats), dan
 * registri itulah yang tahu apakah tata letaknya sudah dicocokkan dengan
 * berkas contoh resmi di docs/samples/pajak/. Layar ini TIDAK mengarang
 * kalimat «sesuai DJP» atau «dapat berubah mengikuti ketentuan»: lencana per
 * format, kalimat di atas setiap tab, nama berkas, dan baris pertama berkas
 * yang diunduh semuanya datang dari `data.formats` dan `data.<tab>.format`.
 * Format yang "menunggu template" tidak punya tombol unduh — yang ia punya
 * hanya kalimat yang menyebut berkas apa yang harus diletakkan di
 * docs/samples/pajak/. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, errorState, skeletonTable, confirmDialog, toast, toastError } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';
// Pola unduhan Blob+BOM file ini justru yang dibakukan csv.js — kini diimpor
// balik dari sana supaya polanya hidup di satu tempat.
import { downloadCsv } from '../csv.js';

const TABS = [
  { key: 'efaktur', label: 'e-Faktur (PPN Keluaran)', valueKey: 'ppn', valueLabel: 'PPN keluaran' },
  { key: 'ebupot', label: 'e-Bupot (PPh Dipotong)', valueKey: 'pph', valueLabel: 'PPh dipotong' },
];

const COLUMN_LABELS = {
  document: 'Dokumen',
  faktur_pajak_no: 'No. faktur pajak',
  invoice_date: 'Tgl faktur',
  bill_date: 'Tgl potong',
  slip_no: 'No. bukti potong',
  partner: 'Lawan transaksi',
  npwp: 'NPWP',
  tax_code: 'Jenis pajak',
  object_code: 'Kode objek',
  dpp: 'DPP',
  ppn: 'PPN',
  rate: 'Tarif',
  pph: 'PPh',
};

const MONEY = new Set(['dpp', 'ppn', 'pph']);

/** Default to the month just closed — that is the period a filing is prepared for. */
function defaultPeriod() {
  const now = new Date();
  const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  return { year: previous.getFullYear(), month: previous.getMonth() + 1 };
}

const state = { ...defaultPeriod(), tab: 'efaktur' };

/* Format yang belum punya writer tetapi sudah punya REKAP INTERNAL: tautan
   ke layarnya, hanya bila pemakai memegang izinnya. Rekap itu bukan berkas
   impor DJP dan layarnya mengatakannya sendiri. */
const INTERNAL_RECAP = {
  ebupot_2126_bulanan: { route: 'rekap-pph21', perm: 'hr.view', label: 'Buka rekap internal PPh 21/26 bulanan' },
};

function formatBadges(format) {
  return [
    badge(format.status_label, format.status === 'ada' ? 'blue' : 'amber'),
    format.verified
      ? badge(`Diverifikasi ${format.verified_against.date}`, 'green')
      : badge(`Belum diverifikasi terhadap template ${format.authority}`, 'amber'),
  ];
}

/* Satu blok per entri registri — kelima format, termasuk yang tidak punya
   writer, supaya orang yang mencari "e-Faktur Coretax XML" menemukan
   jawabannya di sini dan bukan menyimpulkan bahwa CSV legacy adalah itu. */
function formatsCard(formats) {
  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Format berkas DJP/BPJS — status verifikasi' }),
      el('.spacer'),
      badge(`${formats.filter((f) => f.verified).length} dari ${formats.length} diverifikasi`, formats.every((f) => f.verified) ? 'green' : 'amber'),
    ]),
    el('.card-body', { style: { display: 'grid', gap: '10px' } }, formats.map((format) => {
      const recap = INTERNAL_RECAP[format.key];
      return el('.djp-format', {
        'data-key': format.key,
        'data-status': format.status,
        'data-verified': String(Boolean(format.verified)),
        style: { display: 'grid', gap: '4px', padding: '10px 12px', border: '1px solid var(--border)', borderRadius: 'var(--radius)' },
      }, [
        el('div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' } }, [
          el('strong', { text: format.label }),
          ...formatBadges(format),
        ]),
        el('.muted', { style: { fontSize: '12px' }, text: `Sumber: ${format.source}` }),
        el('.muted.djp-verification', { style: { fontSize: '12px' }, text: format.verification }),
        format.awaiting_file
          ? el('.djp-awaiting-file', { style: { fontSize: '12px', color: 'var(--warning)' }, text: format.awaiting_file })
          : null,
        recap && session.can(recap.perm)
          ? el('div', button(recap.label, { size: 'sm', onClick: () => navigate(recap.route) }))
          : null,
      ]);
    })),
  ]);
}

function summaryTiles(exp, tab) {
  const s = exp.summary;
  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Siap diekspor' }),
      el('.value', { text: String(s.exported) }),
      el('.delta', { text: `dari ${s.exported + s.blocked} dokumen periode ini` }),
    ]),
    el('.stat', [el('.label', { text: 'Total DPP' }), el('.value.sm', { text: fmt.rupiah(s.dpp) })]),
    el('.stat', [el('.label', { text: tab.valueLabel }), el('.value.sm', { text: fmt.rupiah(s[tab.valueKey]) })]),
    el('.stat', [
      el('.label', { text: 'Tertahan' }),
      el('.value', { text: String(s.blocked), style: s.blocked ? { color: 'var(--danger)' } : {} }),
      el('.delta', { text: s.blocked ? 'perlu dilengkapi' : 'tidak ada' }),
    ]),
  ]);
}

function rowsTable(exp) {
  if (!exp.rows.length) {
    return el('.card-body', el('p.muted', {
      text: 'Tidak ada dokumen yang dapat diekspor untuk periode ini.',
      style: { margin: 0 },
    }));
  }

  const cols = exp.columns;

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', cols.map((c) =>
      el(`th${MONEY.has(c) || c === 'rate' ? '.right' : ''}`, { text: COLUMN_LABELS[c] || c })))),
    el('tbody', exp.rows.map((row) => el('tr', cols.map((c) => {
      if (MONEY.has(c)) return el('td.right.num', { text: fmt.rupiah(row[c]) });
      if (c === 'rate') return el('td.right.num', { text: fmt.percent(row[c]) });
      if (c === 'invoice_date' || c === 'bill_date') return el('td', { text: fmt.date(row[c]) });
      if (c === 'document' || c === 'faktur_pajak_no' || c === 'slip_no' || c === 'npwp' || c === 'object_code') {
        return el('td.code', { text: row[c] || '—' });
      }
      return el('td', { text: row[c] ?? '—' });
    })))),
    el('tfoot', el('tr', cols.map((c, i) => {
      if (MONEY.has(c)) return el('td.right', { text: fmt.rupiah(exp.summary[c] ?? 0) });
      return el('td', { text: i === 0 ? 'Total' : '' });
    }))),
  ]));
}

function blockersCard(exp, tab, onIssueNumbers) {
  if (!exp.blockers.length) return null;

  /* Satu-satunya penghalang yang tidak bisa diperbaiki di dokumennya sendiri:
     nomor bukti potong diterbitkan per masa, sekali. Tanpa tombol ini layar
     menyuruh operator "terbitkan nomor bukti potong masa ini lebih dulu" lalu
     tidak menyediakan satu pun jalan untuk melakukannya. */
  const needsNumbers = tab === 'ebupot'
    && exp.blockers.some((b) => String(b.reason || '').includes('bukti potong'));

  return el('.card', [
    el('.card-head', [
      el('h2', { text: `Tertahan — tidak masuk file (${exp.blockers.length})` }),
      el('.spacer'),
      needsNumbers && session.can('fin.approve')
        ? button('Terbitkan nomor bukti potong', { variant: 'primary', onClick: onIssueNumbers })
        : null,
      badge('Perlu tindakan', 'amber'),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Dokumen' }),
        el('th', { text: 'Lawan transaksi' }),
        el('th.right', { text: 'DPP' }),
        el('th', { text: 'Sebabnya' }),
      ])),
      el('tbody', exp.blockers.map((b) => el('tr', [
        el('td.code', { text: b.document }),
        el('td', { text: b.partner || '—' }),
        el('td.right.num', { text: fmt.rupiah(b.dpp) }),
        el('td', { text: b.reason }),
      ]))),
    ])),
  ]);
}

export async function renderTaxExport(host) {
  clear(host);

  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke ekspor pajak.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Ekspor Pajak' }),
      el('.desc', {
        text: 'Berkas impor untuk aplikasi DJP — dibentuk dari dokumen yang sudah disetujui. '
          + 'Status verifikasi tiap format terhadap template resmi tertulis di kartu pertama; '
          + 'tidak ada pengiriman ke DJP dari layar ini.',
      }),
    ]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const tabs = el('.tabs');
  const body = el('div');
  host.append(tabs, controls, body);

  const yearInput = el('input.filter-w', { type: 'number', value: state.year, min: 2000, max: 2100, 'aria-label': 'Tahun pajak' });
  const monthSelect = el('select.filter-w', { 'aria-label': 'Masa pajak' });
  fmt.MONTHS.forEach((label, i) => monthSelect.appendChild(el('option', { value: i + 1, text: label })));
  monthSelect.value = state.month;

  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value); load(); });
  monthSelect.addEventListener('change', () => { state.month = Number(monthSelect.value); load(); });
  controls.append(monthSelect, yearInput);

  let payload = null;

  function paintTabs() {
    clear(tabs);
    TABS.forEach((tab) => {
      const count = payload ? payload[tab.key].summary.exported : null;
      tabs.appendChild(el(`button${tab.key === state.tab ? '.active' : ''}`, {
        text: count === null ? tab.label : `${tab.label} (${count})`,
        onclick: () => {
          if (state.tab === tab.key) return;
          state.tab = tab.key;
          paintTabs();
          paint();
        },
      }));
    });
  }

  function paint() {
    clear(body);
    if (!payload) return;

    const tab = TABS.find((t) => t.key === state.tab);
    const exp = payload[state.tab];

    // Registri format DI ATAS tab yang dipilih: ia berlaku untuk keduanya
    // (dan untuk tiga format yang tidak punya tab sama sekali).
    body.appendChild(formatsCard(payload.formats || []));

    /* Kalimat verifikasi datang dari registri server — bukan dari SPA. Warna
       kotaknya ikut: amber selama belum diverifikasi, biru sesudahnya. */
    body.appendChild(el(`.alert.${exp.format.verified ? 'info' : 'warn'}.djp-export-verification`, {
      'data-verified': String(Boolean(exp.format.verified)),
    }, [
      icon('warn', 15),
      el('div', [
        el('div', { text: `Periode ${exp.period.label} · NPWP ${exp.company.npwp || '—'} · ${exp.format.label}` }),
        el('.muted', { style: { fontSize: '12px' }, text: exp.format.verification }),
      ]),
    ]));

    body.appendChild(summaryTiles(exp, tab));

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: `Isi berkas — ${exp.filename}` }),
        el('.spacer'),
        button('Unduh CSV', {
          variant: 'primary',
          iconName: 'download',
          disabled: exp.rows.length === 0,
          onClick: () => downloadCsv(exp.filename, exp.csv),
        }),
      ]),
      rowsTable(exp),
    ]));

    const blockers = blockersCard(exp, tab, issueNumbers);
    if (blockers) body.appendChild(blockers);
  }

  /* Sekali per masa dan tidak berubah sesudahnya — nomor bukti potong adalah
     rujukan hukum, bukan nomor urut baris. Servernya idempoten: yang sudah
     bernomor dilewati. */
  async function issueNumbers() {
    const ok = await confirmDialog({
      title: 'Terbitkan nomor bukti potong',
      message: 'Nomor diterbitkan sekali untuk masa ini dan tidak berubah lagi. '
        + 'Tagihan yang sudah bernomor dilewati.',
      confirmLabel: 'Terbitkan',
      tone: 'primary',
    });

    if (!ok) return;

    try {
      const result = await api.post('finance/tax-exports/e-bupot/numbers', {
        year: state.year,
        month: state.month,
      });
      toast(`${(result.summary || {}).issued ?? 0} nomor bukti potong diterbitkan.`);
      await load();
    } catch (error) {
      toastError(error);
    }
  }

  async function load() {
    clear(body);
    body.appendChild(skeletonTable(6, 6));
    try {
      payload = await api.get('finance/tax-exports', { year: state.year, month: state.month });
      paintTabs();
      paint();
    } catch (error) {
      payload = null;
      clear(body).appendChild(errorState(error, load));
    }
  }

  paintTabs();
  await load();
}
