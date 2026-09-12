/* Rekap PPh 21/26 Bulanan (P-3b) — REKAP INTERNAL dari snapshot slip gaji.
 *
 * Layar baca-saja: satu baris per pegawai untuk satu masa — identitas pajak,
 * bruto, kategori TER, tarif, PPh 21 — dibentuk dari slip run yang SUDAH
 * disetujui/diposting. Run draf/diajukan/ditolak tidak masuk, dan layar ini
 * menyebutnya sebagai tidak masuk, supaya rekap kosong terbaca "run Juli
 * masih draf" dan bukan "tidak ada gaji Juli".
 *
 * TIGA KALIMAT KEJUJURAN, SEMUANYA DARI SERVER: label "rekap internal … BUKAN
 * berkas impor DJP" (payload.label), status format impor e-Bupot 21/26 dari
 * registri DjpFormats (payload.format.verification + awaiting_file), dan
 * catatan bahwa tabel TER ditandai perlu dicek (payload.ter_note). Tidak satu
 * pun dikarang di sini.
 *
 * SEL KOSONG UNTUK YANG TIDAK DIKETAHUI (pelajaran Fase 1): pegawai tanpa
 * NPWP/NIK yang dikenali mendapat sel identitas KOSONG — bukan 0, bukan "—" —
 * dengan judul (title) yang menyebut apa yang tersimpan, dan dihitung di ubin
 * "Pegawai tanpa identitas pajak". Desember (Pasal 17) tidak punya kategori
 * TER: selnya kosong juga. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, field, errorState, skeletonTable } from '../ui.js';
import * as fmt from '../format.js';
import { downloadCsv } from '../csv.js';

/** Bawaan: bulan yang baru lewat — masa yang rekapnya sedang disiapkan. */
function defaultPeriod() {
  const now = new Date();
  const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  return { year: previous.getFullYear(), month: previous.getMonth() + 1 };
}

const state = { ...defaultPeriod() };

function summaryTiles(payload) {
  const s = payload.summary;
  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Pegawai' }),
      el('.value', { text: String(s.employees) }),
      el('.delta', { text: `${s.slips} slip dari ${s.runs_included} run` }),
    ]),
    el('.stat', [el('.label', { text: 'Total bruto' }), el('.value.sm', { text: fmt.rupiah(s.gross) })]),
    el('.stat', [el('.label', { text: 'Total PPh 21' }), el('.value.sm', { text: fmt.rupiah(s.pph21) })]),
    el('.stat.without-tax-id', { 'data-count': String(s.without_tax_id) }, [
      el('.label', { text: 'Pegawai tanpa identitas pajak' }),
      el('.value', { text: String(s.without_tax_id), style: s.without_tax_id ? { color: 'var(--danger)' } : {} }),
      // V2-7/V3b-3: sel kosong ≠ tambahan 20 % — sebut berapa yang dihitung tarif normal.
      el('.delta', {
        text: !s.without_tax_id
          ? 'semua dikenali'
          : `sel identitas dibiarkan kosong · ${s.without_tax_id_normal_rate ?? 0} dihitung tarif normal`,
      }),
    ]),
  ]);
}

function runsCard(payload) {
  const { included, excluded } = payload.runs;
  if (!included.length && !excluded.length) return null;

  const row = (run, tone, note) => el('tr', { 'data-run': run.code, 'data-included': String(!note) }, [
    el('td.code', { text: run.code }),
    el('td', { text: run.run_type_label || run.run_type }),
    el('td', badge(run.status_label || run.status, tone)),
    el('td.right.num', { text: String(run.payslips) }),
    el('td', { text: note || 'Masuk rekap' }),
  ]);

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Run payroll masa ini' }),
      el('.spacer'),
      excluded.length ? badge(`${excluded.length} tidak masuk rekap`, 'amber') : badge('semua masuk', 'green'),
    ]),
    el('.table-wrap', el('table.data.runs', [
      el('thead', el('tr', [
        el('th', { text: 'Run' }),
        el('th', { text: 'Jenis' }),
        el('th', { text: 'Status' }),
        el('th.right', { text: 'Slip' }),
        el('th', { text: 'Keterangan' }),
      ])),
      el('tbody', [
        ...included.map((run) => row(run, 'green', null)),
        ...excluded.map((run) => row(run, 'amber', run.reason)),
      ]),
    ])),
  ]);
}

function terCell(row, december) {
  if (row.ter_category === null || row.ter_category === undefined) {
    // Desember: true-up Pasal 17, tidak ada kategori TER. Bulan lain tanpa
    // kategori adalah keadaan yang tidak diketahui — kosong, bukan angka.
    return el('td.center.ter', { 'data-empty': 'true' }, december ? el('span.muted', { text: 'Ps. 17' }) : null);
  }
  const rate = row.ter_rate === null || row.ter_rate === undefined ? '' : ` · ${fmt.percent(row.ter_rate)}`;
  return el('td.center.ter', badge(`${row.ter_category}${rate}`));
}

function rowsTable(payload) {
  if (!payload.rows.length) {
    const excluded = payload.runs.excluded.length;
    return el('.card-body', el('p.muted', {
      style: { margin: 0 },
      text: excluded
        ? `Belum ada run yang disetujui/diposting pada masa ini — ${excluded} run masih di luar rekap (lihat kartu di atas).`
        : 'Tidak ada run payroll pada masa ini.',
    }));
  }

  const december = Number(payload.period.month) === 12;

  return el('.table-wrap', el('table.data.recap', [
    el('thead', el('tr', [
      el('th', { text: 'Pegawai' }),
      el('th', { text: 'NIK / NPWP' }),
      el('th', { text: 'Jenis identitas' }),
      el('th.right', { text: 'Bruto' }),
      el('th.center', { text: 'TER' }),
      el('th.right', { text: 'PPh 21' }),
    ])),
    el('tbody', payload.rows.map((row) => el('tr', { 'data-employee': row.employee_code || String(row.employee_id) }, [
      el('td', el('span', [
        el('span.cell-main', { text: row.employee_name || `#${row.employee_id}` }),
        el('span.cell-sub.mono', { text: row.employee_code || '' }),
        // V2-7/V3b-3: perlakuan payroll pada baris tanpa identitas yang dikenali —
        // kalimat dari API, di bawah nama; sel identitas dan jenisnya TETAP kosong.
        row.tax_id_treatment
          ? el('span.cell-sub.tax-id-treatment', { style: { whiteSpace: 'normal', color: 'var(--warning)' }, text: row.tax_id_treatment })
          : null,
      ])),
      // KOSONG bila tidak dikenali — bukan 0, bukan "—". Sebabnya di title.
      el('td.mono.tax-id', {
        'data-empty': String(row.tax_id === null || row.tax_id === undefined),
        title: row.tax_id_issue || undefined,
        text: row.tax_id ?? '',
      }),
      el('td.tax-id-kind', { text: row.tax_id_kind_label ?? '' }),
      el('td.right.num', { text: fmt.rupiah(row.gross) }),
      terCell(row, december),
      el('td.right.num.strong', {
        text: fmt.rupiah(row.pph21),
        style: Number(row.pph21) < 0 ? { color: 'var(--success)' } : {},
        title: row.slips.length > 1
          ? row.slips.map((s) => `${s.run_code} (${s.run_type_label}): ${fmt.rupiah(s.pph21)}`).join(' + ')
          : undefined,
      }),
    ]))),
    el('tfoot', el('tr', [
      el('td', { text: 'Total', colspan: 3 }),
      el('td.right', { text: fmt.rupiah(payload.summary.gross) }),
      el('td'),
      el('td.right', { text: fmt.rupiah(payload.summary.pph21) }),
    ])),
  ]));
}

export async function renderRekapPph21(host) {
  clear(host);

  if (!session.can('hr.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke rekap PPh 21.'));
    return;
  }

  const desc = el('.desc');
  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Rekap PPh 21/26 Bulanan' }),
      desc,
    ]),
  ]));

  const yearInput = el('input.filter-w', { type: 'number', value: state.year, min: 2000, max: 2100, 'aria-label': 'Tahun' });
  const monthSelect = el('select.filter-w', { 'aria-label': 'Masa' });
  fmt.MONTHS.forEach((label, i) => monthSelect.appendChild(el('option', { value: i + 1, text: label })));
  monthSelect.value = state.month;

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  }, [monthSelect, yearInput]);
  const body = el('div');
  host.append(controls, body);

  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value) || state.year; load(); });
  monthSelect.addEventListener('change', () => { state.month = Number(monthSelect.value); load(); });

  let sequence = 0;

  async function load() {
    const mine = ++sequence;
    clear(body);
    body.appendChild(skeletonTable(6, 6));
    try {
      const payload = await api.get('hr/pph21-recap', { year: state.year, month: state.month });
      if (mine !== sequence) return;
      paint(payload);
    } catch (error) {
      if (mine !== sequence) return;
      clear(body).appendChild(errorState(error, load));
    }
  }

  function paint(payload) {
    clear(body);
    desc.textContent = payload.label;

    // Registri format (T3b.0): e-Bupot 21/26 menunggu template — kalimatnya dari server.
    body.appendChild(el(`.alert.${payload.format.verified ? 'info' : 'warn'}.recap-format`, {
      'data-format': payload.format.key,
      'data-verified': String(Boolean(payload.format.verified)),
    }, [
      icon('warn', 15),
      el('div', [
        el('div', { text: `Masa ${payload.period.label} · ${payload.format.label}: ${payload.format.status_label}` }),
        el('.muted', { style: { fontSize: '12px' }, text: payload.format.verification }),
        payload.format.awaiting_file
          ? el('.muted.recap-awaiting-file', { style: { fontSize: '12px' }, text: payload.format.awaiting_file })
          : null,
      ]),
    ]));

    body.appendChild(summaryTiles(payload));

    const runs = runsCard(payload);
    if (runs) body.appendChild(runs);

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: `Rekap per pegawai — ${payload.filename}` }),
        el('.spacer'),
        button('Unduh CSV rekap internal', {
          variant: 'primary',
          iconName: 'download',
          disabled: payload.rows.length === 0,
          onClick: () => downloadCsv(payload.filename, payload.csv),
        }),
      ]),
      rowsTable(payload),
    ]));

    body.appendChild(el('p.muted.recap-ter-note', { style: { fontSize: '12px' }, text: payload.ter_note }));
  }

  await load();
}
