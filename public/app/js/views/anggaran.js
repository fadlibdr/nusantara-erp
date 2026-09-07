/* Anggaran vs Realisasi — portofolio dan per proyek × bulan (F-2 / T2.2, T2.3).

   DUA JANJI YANG DIPEGANG LAYAR INI.

   (1) ANGKA DI SINI ADALAH ANGKA YANG MENOLAK PO. Sisa anggaran yang tercetak
   pada tab Portofolio bukan hitungan layar: ia datang dari
   BudgetRealisationService, kelas yang sama yang dibaca gerbang anggaran saat
   sebuah PO atau SPK diajukan. Sampai F-2 tidak ada layar yang menampilkannya
   sama sekali, dan sebuah layar yang menghitungnya sendiri adalah jawaban kedua
   atas satu pertanyaan — hari mereka berselisih adalah hari seorang manajer
   proyek membaca "sisa Rp 80 juta" lalu ditolak saat memesan Rp 50 juta.

   (2) SEL YANG TIDAK PUNYA JAWABAN DIGARIS, TIDAK PERNAH DINOLKAN. Anggaran
   bulanan adalah TURUNAN: RAP × bobot fase baseline, dan kalimat penurunannya
   dicetak di atas tabelnya. Proyek tanpa baseline tidak punya bobot fase, jadi
   tidak punya anggaran bulanan — selnya "—" dengan sebabnya, bukan RAP yang
   diratakan per dua belas bulan. Bulan tanpa realisasi juga "—": "Rp 0" pada
   bulan depan terbaca "anggaran terjaga", padahal yang benar adalah "belum ada
   apa-apa yang tercatat". */

import { api } from '../api.js';
import { el, clear, button, badge, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor } from '../lookup.js';

const TABS = [
  { key: 'portofolio', label: 'Portofolio' },
  { key: 'bulan', label: 'Per bulan' },
];

const STATE = {
  lampau: ['Melampaui', 'red'],
  mendekati: ['Mendekati', 'amber'],
  aman: ['Aman', 'green'],
  tanpa_batas: ['Tanpa RAP', ''],
  tidak_terukur: ['Belum terukur', ''],
};

/* Kenapa sel anggaran sebuah bulan kosong — kalimat milik layar, kodenya milik
   server, jadi kata yang dibaca manajer proyek bisa diperbaiki di sini saja. */
const SEBAB_KOSONG = {
  tanpa_baseline: 'Belum ada baseline disetujui',
  tanpa_rap: 'Belum ada RAP disetujui',
  di_luar_rentang_baseline: 'Di luar rentang baseline',
};

const state = { tab: 'portofolio', projectId: null };

function money(value) {
  return value === null || value === undefined ? '—' : fmt.rupiah(value);
}

/** Sel rupiah yang boleh tidak diketahui — digaris, tidak dinolkan. */
function selMoney(value, { tone } = {}) {
  if (value === null || value === undefined) return el('td.right', el('span.cell-sub', { text: '—' }));
  return el('td.right.num', { text: fmt.rupiah(value), style: tone ? { color: tone } : {} });
}

function selPersen(pct, stateKey) {
  if (pct === null || pct === undefined) {
    const [label] = STATE[stateKey] || [stateKey];
    return el('td.right', el('span.cell-sub', { text: label }));
  }

  const tone = stateKey === 'lampau' ? 'var(--danger)' : (stateKey === 'mendekati' ? 'var(--warning)' : 'var(--text)');

  return el('td.right.num.strong', { text: fmt.percent(pct, { decimals: 1 }), style: { color: tone } });
}

/* ------------------------------------------------------------- portofolio */

function paintPortfolio(body, payload) {
  const rows = payload.data || [];
  const meta = payload.meta || {};

  if (!rows.length) {
    body.appendChild(el('.alert.info', 'Belum ada proyek berjalan untuk dibandingkan dengan anggarannya.'));
    return;
  }

  const count = (key) => rows.filter((row) => row.state === key).length;

  body.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Melampaui anggaran' }),
      el('.value.sm', { text: String(count('lampau')), style: count('lampau') ? { color: 'var(--danger)' } : {} }),
      el('.delta.down', { text: 'realisasi + komitmen ≥ RAP' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Mendekati anggaran' }),
      el('.value.sm', { text: String(count('mendekati')) }),
      el('.delta', { text: `≥ ${fmt.percent(rows[0].warn_pct, { decimals: 0 })} terpakai` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Tanpa RAP disetujui' }),
      el('.value.sm', { text: String(meta.without_budget || 0) }),
      el('.delta', { text: 'tidak ada anggaran untuk dilampaui' }),
    ]),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Anggaran, realisasi dan komitmen per proyek' }),
      el('span.cell-sub', { text: `${rows.length} proyek` }),
    ]),
    el('.card-body', el('p.help', {
      text: 'Angka pada tabel ini adalah angka yang dibaca gerbang anggaran saat PO atau SPK diajukan: '
        + 'sisa = RAP − realisasi − komitmen berjalan. Kolom "Sisa" adalah DPP terbesar yang masih '
        + 'diterima tanpa konfirmasi pelampauan.',
    })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Proyek' }),
        el('th.right', { text: 'Nilai kontrak' }),
        el('th.right', { text: 'RAP' }),
        el('th.right', { text: 'Realisasi' }),
        el('th.right', { text: 'Komitmen' }),
        el('th.right', { text: 'Sisa' }),
        el('th.right', { text: 'Terpakai' }),
        el('th', { text: 'Keadaan' }),
        el('th', { text: '' }),
      ])),
      el('tbody', rows.map((row) => {
        const [label, tone] = STATE[row.state] || [row.state, ''];

        return el('tr', [
          el('td', [
            el('span.cell-main', { text: row.project_code }),
            el('span.cell-sub', { text: row.rap_code ? `${row.project_name} · RAP ${row.rap_code}` : row.project_name }),
          ]),
          selMoney(row.contract_value),
          selMoney(row.budget),
          selMoney(row.actual),
          selMoney(row.committed),
          selMoney(row.remaining, { tone: row.remaining !== null && row.remaining < 0 ? 'var(--danger)' : null }),
          selPersen(row.pct, row.state),
          el('td', badge(label, tone)),
          el('td.right', button('Per bulan', {
            size: 'sm',
            onClick: () => { state.projectId = row.project_id; state.tab = 'bulan'; render(); },
          })),
        ]);
      })),
    ])),
  ]));
}

/* ---------------------------------------------------------------- per bulan */

function paintMonthly(body, payload) {
  const rows = payload.rows || [];

  body.appendChild(el(payload.derived ? '.alert.info' : '.alert.warning', payload.derivation));

  if (!rows.length) {
    body.appendChild(el('.alert.info',
      'Proyek ini belum punya baseline maupun satu baris biaya pun, jadi tidak ada bulan untuk ditampilkan.'));
    return;
  }

  body.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Anggaran vs realisasi per bulan' }),
      el('span', [
        payload.rap_code ? badge(`RAP ${payload.rap_code}`, '') : badge('Tanpa RAP', 'amber'),
        payload.baseline_code
          ? badge(`Baseline ${payload.baseline_code} rev ${payload.baseline_revision}`, '')
          : badge('Tanpa baseline', 'amber'),
      ]),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Bulan' }),
        el('th.right', { text: 'Bobot fase' }),
        el('th.right', { text: 'Anggaran (turunan)' }),
        el('th.right', { text: 'Realisasi' }),
        el('th.right', { text: 'Selisih' }),
        el('th.right', { text: 'Kumulatif anggaran' }),
        el('th.right', { text: 'Kumulatif realisasi' }),
      ])),
      el('tbody', rows.map((row) => el('tr', [
        el('td', el('span.cell-main', { text: row.label })),
        el('td.right', row.weight_pct === null
          ? el('span.cell-sub', { text: '—' })
          : el('span.num', { text: fmt.percent(row.weight_pct, { decimals: 2 }) })),
        row.budget === null
          ? el('td.right', el('span.cell-sub', { text: SEBAB_KOSONG[row.budget_state] || '—' }))
          : el('td.right.num', { text: fmt.rupiah(row.budget), title: 'Turunan: RAP × bobot fase baseline bulan ini' }),
        selMoney(row.actual),
        selMoney(row.variance, { tone: row.variance !== null && row.variance < 0 ? 'var(--danger)' : null }),
        selMoney(row.cumulative_budget),
        selMoney(row.cumulative_actual),
      ]))),
      el('tfoot', el('tr', [
        el('td', el('span.cell-main', { text: 'Total' })),
        el('td', { text: '' }),
        el('td.right.num.strong', { text: money(payload.totals.budget) }),
        el('td.right.num.strong', { text: money(payload.totals.actual) }),
        el('td', { text: '' }),
        el('td', { text: '' }),
        el('td', { text: '' }),
      ])),
    ])),
  ]));

  body.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Apa arti sel yang bergaris' })),
    el('.card-body', [
      el('p', { text: '"Belum ada baseline disetujui" — anggaran bulanan diturunkan dari bobot fase baseline. Tanpa baseline tidak ada bobot, dan membagi RAP rata per bulan akan mencetak rencana yang tidak pernah disetujui siapa pun.' }),
      el('p', { text: '"Belum ada RAP disetujui" — bobot fasenya ada, totalnya belum: tidak ada rupiah untuk dibagi.' }),
      el('p', { text: '"Di luar rentang baseline" — biaya mendarat di bulan yang tidak ada dalam rencana. Barisnya tetap tampil, karena biayanya sungguh terjadi.' }),
      el('p', { text: 'Kolom realisasi "—" berarti bulan itu belum punya satu baris biaya pun. Itu bukan Rp 0.' }),
    ]),
  ]));
}

/* -------------------------------------------------------------------- layar */

let host = null;
let bodyNode = null;
let projectSelect = null;

async function load() {
  clear(bodyNode);
  bodyNode.appendChild(skeletonTable(5, 6));

  if (state.tab === 'bulan' && !state.projectId) {
    clear(bodyNode).appendChild(el('.alert.info', 'Pilih proyek untuk melihat anggaran per bulannya.'));
    return;
  }

  try {
    // list() memulangkan AMPLOP (data + meta) — portofolio membutuhkan
    // meta.without_budget; get() memulangkan isi `data` saja.
    const payload = state.tab === 'portofolio'
      ? await api.list('finance/budget/portfolio')
      : await api.get(`finance/budget/projects/${state.projectId}/monthly`);

    clear(bodyNode);

    if (state.tab === 'portofolio') paintPortfolio(bodyNode, payload);
    else paintMonthly(bodyNode, payload);
  } catch (error) {
    clear(bodyNode).appendChild(errorState(error, load));
  }
}

export async function renderAnggaran(hostNode) {
  host = hostNode;
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Anggaran vs Realisasi' }),
      el('.desc', {
        text: 'RAP, realisasi biaya dan komitmen berjalan per proyek — angka yang sama yang dibaca '
          + 'gerbang anggaran saat PO dan SPK diajukan — dan penurunannya per bulan.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => load() })]),
  ]));

  const tabs = el('.tabs');
  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  bodyNode = el('div');
  host.append(tabs, controls, bodyNode);

  TABS.forEach((tab) => {
    tabs.appendChild(el(`button.tab${state.tab === tab.key ? '.active' : ''}`, {
      type: 'button',
      text: tab.label,
      onclick: () => { state.tab = tab.key; render(); },
    }));
  });

  const projectRows = await loadSource('projects').catch(() => []);
  const options = optionsFor('projects', projectRows);

  projectSelect = el('select.filter-w', {
    'aria-label': 'Proyek',
    onchange: () => { state.projectId = projectSelect.value ? Number(projectSelect.value) : null; load(); },
  });
  projectSelect.appendChild(el('option', { value: '', text: '— pilih proyek —' }));
  options.forEach((option) => projectSelect.appendChild(el('option', { value: option.value, text: option.label })));
  projectSelect.value = state.projectId === null ? '' : String(state.projectId);

  if (state.tab === 'bulan') {
    controls.appendChild(el('label.filter', [el('span', { text: 'Proyek' }), projectSelect]));
  } else {
    controls.appendChild(el('span.help', {
      text: 'Portofolio menampilkan setiap proyek yang belum ditutup. Buka "Per bulan" dari tombol di barisnya.',
    }));
  }

  await load();
}

function render() {
  if (host) renderAnggaran(host);
}
