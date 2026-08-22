/* EVM & baseline kurva-S — SPI/CPI seluruh proyek terhadap rencana yang dibekukan.

   Sampai layar ini ada, satu-satunya "rencana" yang bisa dibandingkan dengan
   realisasi adalah kolom planned_pct pada laporan progres mingguan: delapan
   baris yang berhenti di 29 Maret 2026 untuk proyek yang berjalan sampai Juni
   2027, dan yang bisa ditulis ulang kapan saja lewat updateOrCreate. Data demo
   memperlihatkan akibatnya telanjang — PRJ-2026-001 melaporkan rencana 62,00%
   pada 29 Maret sementara kurva yang dibekukan dari WBS-nya sendiri baru 16,11%
   di akhir Maret. Pembanding yang bisa bergeser diam-diam membuat keterlambatan
   tidak pernah menjadi angka, dan klaim perpanjangan waktu tidak pernah punya
   bukti selain ingatan orang.

   Dengan baseline BSL/2026/VIII/0001 yang beku, keterlambatan itu punya nama:
   SPI 0,8913 per 1 Agustus 2026 — nilai diperoleh Rp 23,20 miliar terhadap
   nilai rencana Rp 26,02 miliar, yaitu Rp 2,83 miliar pekerjaan yang menurut
   rencana yang disetujui seharusnya sudah selesai.

   CPI 101,63 DI DATA DEMO BUKAN SALAH LAYAR INI, dan layar ini tidak boleh
   memolesnya. fin_project_costs untuk PRJ-2026-001 baru berisi kategori
   material Rp 228.240.000, sedangkan RAP-nya menganggarkan upah, subkon, alat
   dan overhead — empat kategori dengan anggaran dan nol rupiah realisasi.
   Karena itu server mengirim cpi_reliable=false dan kartu CPI di sini selalu
   ambar, tidak pernah hijau, dengan SPI 0,8913 duduk di sebelahnya sebagai
   angka yang memang bisa dipercaya.

   SATU BERKAS, DUA PEMAKAI. evmCard() dan baselineCard() dipakai
   views/project.js sebagai kartu di ruang kerja proyek; renderEvm() adalah
   layar tersendiri untuk seluruh portofolio. Keduanya membaca endpoint yang
   sama — api/projects/evm dan api/projects/baselines — sehingga angka pada dua
   tempat itu tidak mungkin berbeda. */

import { api, session } from '../api.js';
import {
  el, clear, button, badge, emptyState, errorState, skeletonTable,
  toast, toastError, confirmDialog,
} from '../ui.js';
import { promptFields } from './form.js';
import { loadSource, optionsFor } from '../lookup.js';
import { ENUMS } from '../enums.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

/* ------------------------------------------------------------------ helpers */

const ns = 'http://www.w3.org/2000/svg';

const TONE = { up: 'var(--success)', down: 'var(--danger)', warn: 'var(--warning)' };

const idx4 = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 4 });

/** Rupiah yang boleh kosong. fmt.rupiahShort(null) terbaca "Rp 0" — dan nol
    rupiah biaya bukan hal yang sama dengan biaya yang belum diketahui. */
function money(value) {
  return value === null || value === undefined ? '—' : fmt.rupiahShort(value);
}

function exact(value) {
  return value === null || value === undefined ? null : fmt.rupiah(value, { decimals: 2 });
}

/**
 * A ratio tile. A null index shows the Indonesian reason there is no number
 * instead of a bare dash, because "—" and "0" look identical to a reader in a
 * hurry and mean opposite things.
 *
 * `tone` is applied to the value AND the note, never to the whole tile: an
 * unreliable CPI must not read as a healthy green number with small print.
 * `basis` says WHAT is being divided by WHAT and per kapan — an index without
 * its basis is a number nobody can check or act on.
 */
function indexTile(label, value, { note, basis, tone, decimals = 4 } = {}) {
  const missing = value === null || value === undefined;
  const colour = TONE[tone] || null;

  return el('.stat', [
    el('.label', { text: label }),
    el('.value', {
      text: missing
        ? '—'
        : new Intl.NumberFormat('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value)),
      style: colour && !missing ? { color: colour } : null,
    }),
    missing || note
      ? el('.delta', { text: note || '', style: colour ? { color: colour } : null })
      : null,
    basis ? el('.delta.muted', { text: basis }) : null,
  ]);
}

function moneyTile(label, value, sub, { tone } = {}) {
  // Angka yang tidak ada tidak boleh berwarna. VAC null (karena CPI tidak dapat
  // dihitung) pernah tampil sebagai "—" berwarna hijau, yang terbaca sebagai
  // "hemat" padahal artinya "tidak diketahui".
  const colour = value === null || value === undefined ? null : (TONE[tone] || null);

  return el('.stat', [
    el('.label', { text: label }),
    // Ringkas di kartu, angka penuhnya di tooltip: "Rp 26.023.834.782,60"
    // tidak muat di petak 158px dan yang dibaca sekilas memang ordenya.
    el('.value.sm', { text: money(value), title: exact(value), style: colour ? { color: colour } : null }),
    sub ? el('.delta', { text: sub }) : null,
  ]);
}

/** Tone untuk sebuah indeks: hanya hijau kalau server bilang boleh dipercaya. */
function indexTone(value, reliable = true) {
  if (value === null || value === undefined) return null;
  if (!reliable) return 'warn';
  return Number(value) < 1 ? 'down' : 'up';
}

function kvCard(title, pairs, extra) {
  return el('.card', [
    el('.card-head', el('h2', { text: title })),
    el('.card-body', [
      el('dl.kv', pairs.filter(Boolean).flatMap(([term, value]) => [
        el('dt', { text: term }),
        el('dd', { text: value === null || value === undefined || value === '' ? '—' : String(value) }),
      ])),
      extra || null,
    ]),
  ]);
}

/* -------------------------------------------------------------------- chart */

/**
 * PV / EV / AC as percentages of BAC, on a real DATE axis.
 *
 * Sumbu tanggal, bukan sumbu indeks. Titik baseline berjarak sebulan sedangkan
 * titik terakhir selalu tanggal laporan: pada data demo titik "1 Agustus 2026"
 * hanya berjarak satu hari dari akhir Juli, dan menaruhnya sejauh satu bulan
 * penuh dari tetangganya membuat kurva terlihat mendatar di ujung.
 *
 * Sumbu Y ikut naik kalau biaya melewati BAC. Menahannya di 100% akan memotong
 * garis biaya persis pada proyek yang paling perlu terlihat — yang sudah
 * membelanjakan lebih dari anggarannya.
 */
export function evmCurve(points, bac) {
  const rows = (points || [])
    .filter((point) => point && point.period_end && Number.isFinite(Date.parse(point.period_end)))
    .slice()
    .sort((a, b) => Date.parse(a.period_end) - Date.parse(b.period_end));

  if (!rows.length) return null;

  const budget = Number(bac) > 0 ? Number(bac) : 0;
  const costPct = (row) => (budget > 0 && row.actual_cost !== null && row.actual_cost !== undefined
    ? (Number(row.actual_cost) / budget) * 100
    : null);

  const values = [];
  rows.forEach((row) => {
    [row.planned_pct, row.actual_pct, costPct(row)].forEach((value) => {
      const n = Number(value);
      if (value !== null && value !== undefined && Number.isFinite(n)) values.push(n);
    });
  });

  const peak = values.length ? Math.max(...values) : 100;
  const yMax = Math.max(100, Math.ceil(peak / 25) * 25);
  const gridStep = Math.max(25, Math.ceil(yMax / 4 / 25) * 25);

  const W = 720;
  const H = 260;
  const PAD = { top: 14, right: 16, bottom: 28, left: 42 };
  const plotW = W - PAD.left - PAD.right;
  const plotH = H - PAD.top - PAD.bottom;

  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'chart');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'Kurva EVM: rencana baseline, nilai diperoleh dan biaya aktual terhadap BAC');

  const add = (tag, attrs, text) => {
    const node = document.createElementNS(ns, tag);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    if (text !== undefined) node.textContent = text;
    svg.appendChild(node);
    return node;
  };

  const first = Date.parse(rows[0].period_end);
  const last = Date.parse(rows[rows.length - 1].period_end);
  const span = last - first;

  const x = (row) => (span > 0
    ? PAD.left + ((Date.parse(row.period_end) - first) / span) * plotW
    : PAD.left + plotW / 2);
  const y = (value) => PAD.top + plotH - (Math.max(0, Math.min(yMax, value)) / yMax) * plotH;

  for (let value = 0; value <= yMax; value += gridStep) {
    add('line', { class: 'grid', x1: PAD.left, x2: W - PAD.right, y1: y(value), y2: y(value) });
    add('text', { x: PAD.left - 7, y: y(value) + 3.5, 'text-anchor': 'end' }, `${value}%`);
  }
  add('line', { class: 'axis', x1: PAD.left, x2: PAD.left, y1: PAD.top, y2: PAD.top + plotH });

  const step = Math.max(1, Math.ceil(rows.length / 8));
  rows.forEach((row, index) => {
    if (index % step === 0 || index === rows.length - 1) {
      add('text', { x: x(row), y: H - 9, 'text-anchor': 'middle' }, fmt.date(row.period_end));
    }
  });

  /* A series stops where its data stops: drawing an actual line across months
     that have not happened yet would invent progress the report never claimed. */
  const line = (pick) => {
    let started = false;
    const parts = [];
    rows.forEach((row) => {
      const value = pick(row);
      if (value === null || value === undefined || !Number.isFinite(Number(value))) return;
      parts.push(`${started ? 'L' : 'M'}${x(row).toFixed(1)},${y(Number(value)).toFixed(1)}`);
      started = true;
    });
    return parts.join(' ');
  };

  add('path', { class: 'base', d: line((row) => row.planned_pct) });
  add('path', { class: 'ev', d: line((row) => row.actual_pct) });
  add('path', { class: 'act', d: line(costPct) });

  rows.forEach((row) => {
    if (row.actual_pct === null || row.actual_pct === undefined) return;
    const point = add('circle', { class: 'pt', cx: x(row), cy: y(Number(row.actual_pct)), r: row.is_as_of ? 4 : 2.5 });
    const title = document.createElementNS(ns, 'title');
    title.textContent = `${fmt.date(row.period_end)} — rencana ${fmt.percent(row.planned_pct)}, `
      + `fisik ${fmt.percent(row.actual_pct)}, biaya ${fmt.rupiah(row.actual_cost)}`;
    point.appendChild(title);
  });

  return svg;
}

function curveLegend() {
  return el('.legend', [
    el('span', [el('i.base'), 'Rencana baseline (kurva beku)']),
    el('span', [el('i.ev'), 'Progres fisik (EV)']),
    el('span', [el('i.act'), 'Biaya aktual terhadap BAC']),
  ]);
}

/* --------------------------------------------------------- baseline actions */

/**
 * Bekukan baseline baru.
 *
 * `reason` wajib begitu proyek SUDAH punya baris baseline apa pun — bukan hanya
 * yang disetujui. BaselineService menomori revisi dari max(revision_no) seluruh
 * status, jadi draf yang ditolak pun menaikkan revisi; menanyakan alasannya
 * hanya kalau ada yang disetujui membuat pengguna mengisi formulir penuh lalu
 * ditolak server karena kolom yang tidak pernah ditampilkan.
 */
async function freezeBaseline(projectId, hasAnyBaseline, onDone) {
  const fields = [
    { key: 'effective_date', label: 'Tanggal berlaku', type: 'date', required: true, help: 'Biasanya tanggal tanda tangan kontrak atau tanggal adendum.' },
    { key: 'reference_type', label: 'Jenis dokumen acuan', type: 'text', help: 'mis. CCO, Addendum, Perpanjangan waktu' },
    { key: 'reference_no', label: 'Nomor dokumen acuan', type: 'text' },
    { key: 'bac_override', label: 'BAC manual', type: 'currency', help: 'Kosongkan agar BAC diambil dari RAP proyek. Isi hanya bila anggaran ditetapkan di luar RAP.' },
    { key: 'notes', label: 'Catatan', type: 'textarea' },
  ];

  if (hasAnyBaseline) {
    fields.splice(1, 0, {
      key: 'reason', label: 'Alasan re-baseline', type: 'textarea', required: true,
      help: 'Wajib. Inilah yang membedakan re-baseline dari penghapusan keterlambatan diam-diam.',
    });
  }

  const values = await promptFields('Bekukan baseline', fields, { submitLabel: 'Buat draf' });
  if (!values) return;

  try {
    await api.post('projects/baselines', { ...values, project_id: Number(projectId) });
    toast('Draf baseline dibuat. Ajukan, lalu minta pengguna lain menyetujuinya agar rencana benar-benar beku.');
    onDone();
  } catch (error) {
    toastError(error);
  }
}

/** Tombol siklus hidup satu baris baseline, sama persis di kartu dan di layar. */
function baselineRowActions(row, onChanged, extra = []) {
  const canUpdate = session.can('prj.update');
  const canDelete = session.can('prj.delete');
  const canApprove = session.can('prj.approve');
  const frozen = row.status === 'approved';

  const act = async (action, message) => {
    try {
      await api.post(`projects/baselines/${row.id}/${action}`, {});
      toast(message);
      onChanged();
    } catch (error) {
      toastError(error);
    }
  };

  const edit = async () => {
    // promptFields TIDAK mengirim isian yang dikosongkan (read() memulangkan
    // null dan null tidak masuk payload), jadi mengosongkan sebuah kolom di sini
    // berarti "biarkan seperti semula", bukan "hapus isinya". Dikatakan di layar
    // supaya tidak ada yang mengira alasannya sudah terhapus padahal masih ada.
    const values = await promptFields(`Ubah ${row.code}`, [
      { key: 'effective_date', label: 'Tanggal berlaku', type: 'date', required: true, default: row.effective_date },
      { key: 'reason', label: 'Alasan', type: 'textarea', default: row.reason, help: 'Dikosongkan berarti nilainya tidak diubah.' },
      { key: 'reference_type', label: 'Jenis dokumen acuan', type: 'text', default: row.reference_type },
      { key: 'reference_no', label: 'Nomor dokumen acuan', type: 'text', default: row.reference_no },
      { key: 'notes', label: 'Catatan', type: 'textarea', default: row.notes },
    ], { submitLabel: 'Simpan' });
    if (!values) return;

    try {
      await api.put(`projects/baselines/${row.id}`, values);
      toast('Kepala baseline diperbarui. Bobot, tanggal dan BAC tidak ikut berubah — gunakan "Ambil ulang" untuk itu.');
      onChanged();
    } catch (error) {
      toastError(error);
    }
  };

  const remove = async () => {
    const yes = await confirmDialog({
      title: `Hapus ${row.code}?`,
      message: 'Draf ini beserta bobot dan kurva bekunya akan dihapus. Baseline yang sudah disetujui '
        + 'tidak pernah bisa dihapus — hanya draf.',
      confirmLabel: 'Hapus draf',
    });
    if (!yes) return;

    try {
      await api.del(`projects/baselines/${row.id}`);
      toast('Draf baseline dihapus.');
      onChanged();
    } catch (error) {
      toastError(error);
    }
  };

  return [
    // Ubah / ambil ulang / hapus mengikuti aturan server persis: yang dilarang
    // hanyalah baseline yang SUDAH disetujui, bukan yang sudah diajukan.
    !frozen && canUpdate ? button('Ubah', { size: 'sm', onClick: edit }) : null,
    !frozen && canUpdate
      ? button('Ambil ulang', {
        size: 'sm',
        title: 'Bekukan ulang bobot, tanggal rencana dan BAC dari WBS dan RAP hari ini',
        onClick: () => act('resnapshot', 'Snapshot diambil ulang dari WBS dan RAP terkini.'),
      })
      : null,
    (row.status === 'draft' || row.status === 'rejected') && canUpdate
      ? button(row.status === 'rejected' ? 'Ajukan ulang' : 'Ajukan', {
        size: 'sm', onClick: () => act('submit', 'Baseline diajukan untuk persetujuan.'),
      })
      : null,
    row.status === 'submitted' && canApprove
      ? button('Bekukan', {
        size: 'sm', variant: 'primary',
        title: 'Menyetujui baseline ini dan menggantikan revisi sebelumnya',
        onClick: () => act('approve', 'Baseline dibekukan; revisi sebelumnya digantikan tetapi tetap tersimpan.'),
      })
      : null,
    row.status === 'submitted' && canApprove
      ? button('Tolak', { size: 'sm', onClick: () => act('reject', 'Baseline ditolak.') })
      : null,
    !frozen && canDelete ? button('Hapus', { size: 'sm', variant: 'ghost', onClick: remove }) : null,
    // Disebar, bukan dibungkus <span>: .row-actions memberi jarak antar ANAK
    // langsungnya, jadi satu pembungkus akan merapatkan tombol tambahan itu.
    ...extra,
  ];
}

/* --------------------------------------------------------------- EVM report */

/**
 * `prefetched` lets the project screen reuse the report it already fetched for
 * the kurva-S overlay instead of asking for it twice. Passing nothing (or null,
 * which is what a failed fetch upstream leaves behind) falls back to fetching
 * here, so the 403 and error messages below still reach the user.
 */
export async function evmCard(projectId, prefetched) {
  let report = prefetched || null;

  try {
    if (!report) report = await api.get(`projects/${projectId}/evm`);
  } catch (error) {
    /* A 403 used to render as an unexplained empty card, which reads as "there
       is nothing here" rather than "you may not see this". */
    const message = error && error.status === 403
      ? 'Anda tidak memiliki hak akses prj.view untuk laporan EVM.'
      : `Laporan EVM tidak dapat dimuat: ${(error && error.message) || 'kesalahan tidak dikenal'}`;
    return el('.card', [
      el('.card-head', [el('h2', { text: 'Kinerja biaya & jadwal (EVM)' })]),
      el('.card-body', el('p.muted', { text: message, style: { margin: 0 } })),
    ]);
  }

  if (!report || report.state === 'no_baseline') {
    return el('.card', [
      el('.card-head', [el('h2', { text: 'Kinerja biaya & jadwal (EVM)' })]),
      el('.card-body', el('p.muted', {
        text: (report && report.message)
          || 'Proyek ini belum punya baseline yang disetujui.',
        style: { margin: 0 },
      })),
    ]);
  }

  const m = report.measures;
  const bridge = report.poc_reconciliation || {};
  const coverage = report.cost_coverage || {};
  const per = fmt.date(report.as_of);

  const body = el('.card-body');

  body.appendChild(el('.stat-row', [
    indexTile('SPI (jadwal)', m.spi, {
      note: m.spi_note,
      basis: `EV ÷ PV per ${per}`,
      tone: indexTone(m.spi),
    }),
    /* NEVER a green tile while the cost base is incomplete: on the demo the
       honest CPI is 101,63 because four of five budgeted categories have zero
       rupiah of actuals, and a green 101,63 would be read as good news. */
    indexTile('CPI (biaya)', m.cpi, {
      note: m.cpi_reliable ? m.cpi_note : (m.cpi_note || 'Biaya aktual belum lengkap'),
      basis: `EV ÷ AC per ${per}`,
      tone: indexTone(m.cpi, m.cpi_reliable),
    }),
    indexTile('TCPI', m.tcpi, {
      note: m.tcpi_note,
      basis: 'Efisiensi yang masih harus dicapai untuk selesai pada BAC',
    }),
    moneyTile('Nilai rencana (PV)', m.pv, `${fmt.percent(m.planned_pct)} dari BAC ${money(m.bac)}`),
    moneyTile('Nilai diperoleh (EV)', m.ev, `${fmt.percent(m.physical_pct)} fisik × BAC`),
    moneyTile('Biaya aktual (AC)', m.ac, `biaya proyek s.d. ${per}`),
    moneyTile('Deviasi jadwal (SV)', m.sv, m.sv_pct === null ? 'EV − PV' : `${fmt.percent(m.sv_pct)} dari PV`, {
      tone: m.sv < 0 ? 'down' : 'up',
    }),
    moneyTile('Deviasi biaya (CV)', m.cv, m.cv_pct === null ? 'EV − AC' : `${fmt.percent(m.cv_pct)} dari EV`, {
      tone: m.cpi_reliable ? (m.cv < 0 ? 'down' : 'up') : 'warn',
    }),
  ]));

  if (!m.cpi_reliable && coverage.warning) {
    body.appendChild(el('p.muted', {
      text: coverage.warning,
      style: { margin: '2px 0 12px', fontSize: '12.5px' },
    }));
  }

  const curve = evmCurve((report.curve || {}).points, Number(m.bac) || 0);
  if (curve) {
    body.appendChild(curve);
    body.appendChild(curveLegend());
  }

  /* The two completion percentages, printed together with the sentence that
     explains why they differ — their ratio IS the cost performance index. */
  body.appendChild(el('div', {
    style: {
      marginTop: '14px', paddingTop: '12px', borderTop: '1px solid var(--border)',
    },
  }, [
    el('div', { style: { display: 'flex', gap: '18px', flexWrap: 'wrap', marginBottom: '6px' } }, [
      el('span', { text: `Progres fisik (WBS): ${fmt.percent(bridge.physical_pct)}`, style: { fontSize: '13px' } }),
      el('span', { text: `Penyelesaian PSAK 115: ${fmt.percent(bridge.poc_pct)}`, style: { fontSize: '13px' } }),
      badge(bridge.poc_source === 'posted_run' ? `Run ${bridge.poc_run_code}` : 'Belum ada run diposting',
        bridge.poc_source === 'posted_run' ? 'green' : ''),
    ]),
    el('p.muted', { text: bridge.explanation || '', style: { margin: 0, fontSize: '12.5px' } }),
  ]));

  const warnings = report.warnings || [];
  if (warnings.length) {
    body.appendChild(el('ul', {
      style: { margin: '12px 0 0', paddingLeft: '18px', fontSize: '12.5px', color: 'var(--text-2)' },
    }, warnings.map((text) => el('li', { text }))));
  }

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Kinerja biaya & jadwal (EVM)' }),
      el('.spacer'),
      badge(`Baseline ${report.baseline.code} rev ${report.baseline.revision_no}`),
      badge(report.baseline.bac_source_label, report.baseline.bac_source === 'rap_approved' ? 'green' : 'amber'),
      button('Layar EVM', {
        size: 'sm', variant: 'ghost',
        title: 'Buka layar EVM & baseline untuk seluruh portofolio',
        onClick: () => navigate('evm'),
      }),
    ]),
    body,
  ]);
}

/* ------------------------------------------------------------- baseline list */

export async function baselineCard(projectId, onChanged) {
  let rows = [];
  let denied = false;

  try {
    rows = (await api.get('projects/baselines', { project_id: projectId, per_page: 20 })) || [];
  } catch (error) {
    // A 403 says "you may not see this"; anything else leaves the ordinary
    // empty state, which says "there is nothing here". They are different
    // answers and the card must not give the second one for the first reason.
    denied = Boolean(error && error.status === 403);
  }

  const canCreate = session.can('prj.create');

  const renderRow = (row) => el('div', {
    style: { padding: '8px 0', borderBottom: '1px solid var(--border)' },
  }, [
    el('div', { style: { display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }, [
      el('span.mono', { text: row.code, style: { fontSize: '12.5px' } }),
      badge(`rev ${row.revision_no}`),
      badge(row.status_label || row.status, fmt.statusTone(row.status)),
      row.is_current ? badge('Berlaku', 'green') : null,
    ]),
    el('.cell-sub', {
      text: `BAC ${fmt.rupiah(row.bac)} · berlaku ${fmt.date(row.effective_date)} · ${row.bac_source_label || ''}`,
    }),
    row.reason ? el('.cell-sub', { text: `Alasan: ${row.reason}` }) : null,
    row.superseded_by_id
      ? el('.cell-sub', { text: 'Digantikan oleh revisi berikutnya — tetap tersimpan sebagai bukti.' })
      : null,
    el('.row-actions', { style: { marginTop: '5px' } }, baselineRowActions(row, onChanged)),
  ]);

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Baseline proyek' }),
      el('.spacer'),
      canCreate && !denied
        ? button('Bekukan baseline', {
          size: 'sm',
          onClick: () => freezeBaseline(projectId, rows.length > 0, onChanged),
        })
        : null,
    ]),
    denied
      ? el('.card-body', el('p.muted', { text: 'Anda tidak memiliki hak akses prj.view untuk melihat baseline.', style: { margin: 0 } }))
      : (rows.length
        ? el('div', { style: { padding: '4px 16px 12px' } }, rows.map(renderRow))
        : el('.card-body', el('p.muted', {
          text: 'Belum ada baseline. Bekukan rencana WBS dan RAP agar SPI dan CPI punya pembanding yang tidak bisa diubah diam-diam.',
          style: { margin: 0, fontSize: '13px' },
        }))),
  ]);
}

/* ============================================================================
   Layar tersendiri: portofolio, laporan per proyek, dan register baseline.
   ========================================================================== */

const state = {
  tab: 'portfolio',
  projectId: null,
  /** Kosong berarti "pakai tanggal server". Jam browser tidak pernah dipercaya. */
  asOf: '',
  /** Tanggal hari ini MENURUT SERVER, dipelajari dari jawaban pertama. */
  serverToday: '',
  /** Revisi lain yang sedang dibaca; null berarti baseline yang berlaku. */
  baselineId: null,
  openBaselineId: null,
};

const TABS = [
  { key: 'portfolio', label: 'Portofolio' },
  { key: 'report', label: 'Laporan EVM' },
  { key: 'baseline', label: 'Baseline' },
];

/* ----------------------------------------------------------- portfolio view */

/**
 * Satu baris per proyek, TERMASUK yang belum punya baseline.
 *
 * Proyek tanpa baseline sengaja ikut ditampilkan dengan seluruh ukurannya
 * kosong: PRJ-2026-002 belum punya RAP sehingga belum bisa dibekukan, dan kalau
 * barisnya dihilangkan orang akan membaca daftar ini sebagai "semua proyek
 * sudah terukur" — persis kesimpulan yang salah.
 */
function portfolioView(host, payload, onPick) {
  const rows = payload.rows || [];
  const per = fmt.date(payload.as_of);

  if (!rows.length) {
    host.appendChild(el('.alert.info', 'Belum ada proyek yang terdaftar.'));
    return;
  }

  const measured = rows.filter((row) => row.baseline_code);
  const unmeasured = rows.length - measured.length;
  const sum = (key) => measured.reduce((total, row) => total + (Number(row[key]) || 0), 0);

  const pv = sum('pv');
  const ev = sum('ev');
  const ac = sum('ac');
  const spi = pv > 0 ? ev / pv : null;

  /* CPI portofolio hanya dihitung kalau SEMUA proyek berbaseline punya biaya
     yang lengkap. Menjumlahkan proyek yang biayanya baru sebagian ke dalam satu
     angka gabungan akan menyembunyikan justru kelemahan yang server sudah repot
     tandai per proyek. */
  const incomplete = measured.filter((row) => !row.cpi_reliable);
  const cpi = incomplete.length === 0 && ac > 0 ? ev / ac : null;

  host.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Proyek terukur' }),
      el('.value', { text: `${measured.length}/${rows.length}` }),
      el('.delta', {
        text: unmeasured
          ? `${unmeasured} proyek belum punya baseline`
          : 'seluruh proyek punya baseline beku',
      }),
    ]),
    indexTile('SPI portofolio', spi, {
      note: spi === null ? 'Belum ada nilai rencana' : null,
      basis: `Σ EV ÷ Σ PV per ${per}`,
      tone: indexTone(spi),
    }),
    indexTile('CPI portofolio', cpi, {
      note: incomplete.length
        ? `${incomplete.length} proyek biaya aktualnya belum lengkap`
        : (cpi === null ? 'Belum ada biaya tercatat' : null),
      basis: `Σ EV ÷ Σ AC per ${per}`,
      tone: cpi === null ? 'warn' : indexTone(cpi),
    }),
    moneyTile('Nilai rencana (Σ PV)', pv, `dari Σ BAC ${money(sum('bac'))}`),
    // Tanpa satu pun proyek berbaseline seluruh jumlah ini nol, dan nol hijau
    // akan terbaca "tepat jadwal" padahal artinya "tidak ada yang diukur".
    moneyTile('Nilai diperoleh (Σ EV)', ev, `selisih jadwal ${money(ev - pv)}`, {
      tone: measured.length ? (ev < pv ? 'down' : 'up') : null,
    }),
    moneyTile('Biaya aktual (Σ AC)', ac, `biaya proyek s.d. ${per}`),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Kinerja per proyek' }),
      el('.spacer'),
      el('.cell-sub', { text: `posisi per ${per} (tanggal server)` }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Proyek' }),
        el('th', { text: 'Baseline' }),
        el('th.right', { text: 'Rencana (PV)' }),
        el('th.right', { text: 'Diperoleh (EV)' }),
        el('th.right', { text: 'Biaya (AC)' }),
        el('th.right', { text: 'SPI' }),
        el('th.right', { text: 'CPI' }),
        el('th.right', { text: 'Deviasi jadwal' }),
      ])),
      el('tbody', rows.map((row) => {
        const node = el('tr.clickable', [
          el('td', el('span', [
            el('span.cell-main', { text: row.name }),
            el('span.cell-sub.mono', { text: row.code }),
          ])),
          el('td', row.baseline_code
            ? el('span', [
              el('span.cell-main.mono', { text: row.baseline_code }),
              el('span.cell-sub', { text: `revisi ${row.revision_no}` }),
            ])
            : el('span', [
              badge(row.state === 'before_effective_date' ? 'Belum berlaku' : 'Belum ada baseline', 'amber'),
              el('span.cell-sub', {
                text: row.state === 'before_effective_date'
                  ? 'baseline berlaku setelah tanggal laporan'
                  : 'bekukan rencana agar terukur',
              }),
            ])),
          el('td.right.num', { text: money(row.pv), title: exact(row.pv) }),
          el('td.right.num', { text: money(row.ev), title: exact(row.ev) }),
          el('td.right.num', { text: money(row.ac), title: exact(row.ac) }),
          el('td.right.num.strong', {
            text: row.spi === null || row.spi === undefined ? '—' : idx4.format(Number(row.spi)),
            style: row.spi === null || row.spi === undefined ? null : { color: TONE[indexTone(row.spi)] },
          }),
          // Peringatan "biaya belum lengkap" hanya dipasang kalau ADA angkanya.
          // Proyek tanpa baseline juga membawa cpi_reliable=false, dan tooltip
          // itu di atas tanda "—" menjelaskan hal yang bukan penyebabnya.
          el('td.right.num.strong', {
            text: row.cpi === null || row.cpi === undefined ? '—' : idx4.format(Number(row.cpi)),
            title: row.cpi !== null && row.cpi !== undefined && !row.cpi_reliable
              ? 'Biaya aktual belum lengkap — CPI belum dapat dipercaya.'
              : null,
            style: row.cpi === null || row.cpi === undefined
              ? null
              : { color: TONE[indexTone(row.cpi, row.cpi_reliable)] },
          }),
          el('td.right.num', {
            text: money(row.sv),
            title: exact(row.sv),
            style: row.sv === null || row.sv === undefined
              ? null
              : { color: Number(row.sv) < 0 ? 'var(--danger)' : 'var(--success)' },
          }),
        ]);
        node.addEventListener('click', () => onPick(row.project_id));
        return node;
      })),
    ])),
  ]));
}

/* -------------------------------------------------------------- report view */

function coverageCard(coverage) {
  const budget = coverage.budget_by_category || {};
  const actual = coverage.actual_by_category || {};
  const empty = coverage.empty_categories || [];

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Cakupan biaya aktual' }),
      el('.spacer'),
      badge(coverage.cpi_reliable ? 'CPI dapat dipercaya' : 'CPI belum dapat dipercaya',
        coverage.cpi_reliable ? 'green' : 'amber'),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kategori biaya' }),
        el('th.right', { text: `Anggaran (${coverage.budget_source || 'tanpa RAP'})` }),
        el('th.right', { text: 'Realisasi (seluruh tanggal)' }),
        el('th', { text: '' }),
      ])),
      el('tbody', ENUMS.costCategory.map((category) => {
        const isEmpty = empty.includes(category.value);
        return el('tr', [
          el('td', { text: category.label }),
          el('td.right.num', { text: money(budget[category.value]), title: exact(budget[category.value]) }),
          el('td.right.num', {
            text: money(actual[category.value]),
            title: exact(actual[category.value]),
            style: isEmpty ? { color: 'var(--warning)' } : null,
          }),
          el('td', isEmpty ? badge('Dianggarkan, belum tercatat', 'amber') : null),
        ]);
      })),
    ])),
    el('.card-body', el('p.muted', {
      // Realisasi sengaja dijumlah untuk SELURUH tanggal, bukan sampai tanggal
      // laporan: kategori yang nol sepanjang waktu pasti nol pada tanggal itu,
      // jadi jendela yang lebih lebar tidak pernah melemahkan peringatannya.
      text: coverage.warning
        || 'Setiap kategori yang dianggarkan sudah punya realisasi, sehingga CPI mengukur efisiensi biaya yang sebenarnya.',
      style: { margin: 0, fontSize: '12.5px' },
    })),
  ]);
}

function bridgeCard(bridge) {
  const boundary = bridge.boundary_day_amounts || [];

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Jembatan ke PSAK 115' }),
      el('.spacer'),
      badge(bridge.poc_source === 'posted_run' ? `Run ${bridge.poc_run_code}` : 'Belum ada run diposting',
        bridge.poc_source === 'posted_run' ? 'green' : ''),
      badge(bridge.matches_cpi ? 'Rasio = CPI' : 'Rasio ≠ CPI', bridge.matches_cpi ? 'green' : 'amber'),
    ]),
    el('.card-body', [
      el('dl.kv', [
        el('dt', { text: 'Progres fisik (bobot WBS beku)' }),
        el('dd.num', { text: fmt.percent(bridge.physical_pct, { decimals: 4 }) }),
        el('dt', { text: 'Penyelesaian PSAK 115 (biaya ÷ EAC)' }),
        el('dd.num', { text: fmt.percent(bridge.poc_pct, { decimals: 4 }) }),
        el('dt', { text: 'Rasio keduanya = CPI × (EAC ÷ BAC)' }),
        el('dd.num', { text: bridge.ratio === null || bridge.ratio === undefined ? '—' : idx4.format(Number(bridge.ratio)) }),
        el('dt', { text: 'Biaya s.d. tanggal — versi PSAK 115' }),
        el('dd.num', { text: fmt.rupiah(bridge.poc_cost_to_date), title: exact(bridge.poc_cost_to_date) }),
        el('dt', { text: 'Biaya s.d. tanggal — versi EVM (AC)' }),
        el('dd.num', { text: fmt.rupiah(bridge.ac), title: exact(bridge.ac) }),
        el('dt', { text: 'EAC yang dipakai buku besar' }),
        el('dd.num', { text: `${fmt.rupiah(bridge.poc_eac)} (${bridge.poc_eac_source || '—'})` }),
        el('dt', { text: 'Dasar biaya' }),
        el('dd', {
          text: bridge.cost_base_scope === 'contract'
            ? `Kontrak (${(bridge.contract_project_ids || []).length} proyek)`
            : 'Proyek ini saja',
        }),
      ]),
      el('p.muted', { text: bridge.explanation || '', style: { margin: '10px 0 0', fontSize: '12.5px' } }),
      // boundary_day_amounts adalah daftar NILAI (float), bukan daftar objek —
      // ia berasal dari pluck('amount'). Menjumlahkan item.amount di sini akan
      // selalu menghasilkan Rp 0 dan menyembunyikan justru selisih yang
      // peringatan ini ada untuk menyebutkannya.
      boundary.length
        ? el('.alert.warn', { style: { marginTop: '12px' } },
          `${boundary.length} baris biaya bertanggal persis pada tanggal laporan, senilai `
          + `${fmt.rupiah(boundary.reduce((total, amount) => total + (Number(amount) || 0), 0))}, `
          + 'ikut dihitung oleh EVM tetapi terlewat oleh perbandingan tanggal mesin PSAK 115. '
          + 'Selama selisih itu ada, rasio kedua persentase di atas tidak akan sama persis dengan CPI.')
        : null,
    ]),
  ]);
}

function deviationCard(deviation) {
  if (!deviation) return null;

  const days = Number(deviation.planned_finish_delta_days) || 0;

  if (!deviation.is_rebaselined) {
    return el('.card', [
      el('.card-head', el('h2', { text: 'Penyimpangan dari baseline awal' })),
      el('.card-body', el('p.muted', {
        text: `Proyek ini masih memakai baseline aslinya (${deviation.original_baseline_code}, revisi 0). `
          + 'Belum pernah ada re-baseline, jadi seluruh deviasi jadwal di atas diukur terhadap rencana '
          + 'yang disepakati sejak awal.',
        style: { margin: 0, fontSize: '13px' },
      })),
    ]);
  }

  return kvCard('Penyimpangan dari baseline awal', [
    ['Baseline awal', `${deviation.original_baseline_code} (revisi ${deviation.original_revision_no})`],
    ['Baseline berlaku', `${deviation.current_baseline_code} (revisi ${deviation.current_revision_no})`],
    ['Alasan re-baseline pertama', deviation.original_reason],
    ['Dokumen acuan', deviation.original_reference_no],
    ['Perubahan BAC', fmt.rupiah(deviation.bac_delta)],
    ['Perubahan nilai kontrak', fmt.rupiah(deviation.contract_value_delta)],
    ['Pergeseran tanggal selesai rencana', `${days >= 0 ? '+' : ''}${days} hari`],
    ['Selesai rencana — awal', fmt.date(deviation.original_planned_finish)],
    ['Selesai rencana — sekarang', fmt.date(deviation.planned_finish)],
    ['Selesai menurut kontrak', fmt.date(deviation.contract_finish)],
  ], el('p.muted', {
    text: 'Inilah berkas bukti yang dipakai untuk klaim perpanjangan waktu atau pembelaan atas denda '
      + 'keterlambatan: revisi 0 tidak pernah dihapus, jadi rencana asli selalu bisa ditunjukkan.',
    style: { margin: '10px 0 0', fontSize: '12.5px' },
  }));
}

function scopeDriftCard(drift) {
  const removed = drift.tasks_removed || [];
  const added = drift.tasks_added || [];
  const gap = Math.abs(Number(drift.live_progress_pct) - Number(drift.baseline_progress_pct));

  if (!removed.length && !added.length && !(gap > 0.01)) return null;

  return el('.card', [
    el('.card-head', el('h2', { text: 'Pergeseran lingkup sejak baseline dibekukan' })),
    el('.card-body', [
      el('dl.kv', [
        el('dt', { text: 'Progres bobot LIVE (header proyek)' }),
        el('dd.num', { text: fmt.percent(drift.live_progress_pct, { decimals: 4 }) }),
        el('dt', { text: 'Progres bobot BEKU (dipakai EVM)' }),
        el('dd.num', { text: fmt.percent(drift.baseline_progress_pct, { decimals: 4 }) }),
      ]),
      removed.length
        ? el('p', {
          text: `Ada di baseline tetapi sudah tidak ada di WBS: ${removed.join(', ')}. `
            + 'Bobotnya dihitung nol — menghapus paket pekerjaan bukan cara menyelesaikannya.',
          style: { margin: '10px 0 0', fontSize: '13px' },
        })
        : null,
      added.length
        ? el('p', {
          text: `Ada di WBS tetapi tidak ada di baseline: ${added.join(', ')}. `
            + 'Lingkup baru belum menghasilkan nilai sampai baseline barunya disetujui.',
          style: { margin: '8px 0 0', fontSize: '13px' },
        })
        : null,
      el('p.muted', {
        text: 'EVM memakai bobot beku dengan sengaja: kalau bobot boleh diubah setelah rencana disetujui, '
          + 'menambah bobot pada pekerjaan yang sudah selesai akan menaikkan nilai diperoleh tanpa satu pun '
          + 'pekerjaan tambahan di lapangan.',
        style: { margin: '10px 0 0', fontSize: '12.5px' },
      }),
    ]),
  ]);
}

function reportView(host, report, ctx) {
  if (report.state === 'no_baseline') {
    host.appendChild(el('.card', [
      el('.card-head', el('h2', { text: `EVM — ${report.project.name}` })),
      el('.card-body', emptyState(
        report.message || 'Proyek ini belum punya baseline yang disetujui.',
        {
          title: 'Belum ada rencana untuk dibandingkan',
          // .empty memusatkan teksnya lewat text-align, yang tidak berlaku bagi
          // .row-actions karena ia flex — tanpa baris ini ketiga tombolnya
          // menempel ke tepi kiri di tengah kartu yang rata tengah.
          action: el('.row-actions', { style: { justifyContent: 'center' } }, [
            session.can('prj.create')
              ? button('Bekukan baseline', {
                variant: 'primary',
                onClick: () => freezeBaseline(report.project.id, ctx.hasBaseline, ctx.reload),
              })
              : null,
            button('Buka RAP', { variant: 'ghost', onClick: () => navigate('r/estimation/cost-budgets') }),
            button('Buka proyek', { variant: 'ghost', onClick: () => navigate(`d/projects/${report.project.id}`) }),
          ]),
        },
      )),
    ]));
    return;
  }

  const m = report.measures;
  const b = report.baseline;
  const per = fmt.date(report.as_of);

  if (ctx.pinned) {
    host.appendChild(el('.alert.info', [
      el('div', { style: { flex: '1' } },
        `Laporan ini dibaca terhadap ${b.code} revisi ${b.revision_no}, bukan baseline yang sedang berlaku.`),
      button('Kembali ke baseline berlaku', { size: 'sm', onClick: ctx.unpin }),
    ]));
  }

  host.appendChild(el('.stat-row', [
    indexTile('SPI (jadwal)', m.spi, {
      note: m.spi_note,
      basis: `EV ÷ PV per ${per}`,
      tone: indexTone(m.spi),
    }),
    indexTile('CPI (biaya)', m.cpi, {
      note: m.cpi_reliable ? m.cpi_note : (m.cpi_note || 'Biaya aktual belum lengkap'),
      basis: `EV ÷ AC per ${per}`,
      tone: indexTone(m.cpi, m.cpi_reliable),
    }),
    indexTile('TCPI', m.tcpi, {
      note: m.tcpi_note,
      basis: 'Efisiensi sisa pekerjaan agar selesai tepat pada BAC',
    }),
    moneyTile('Anggaran total (BAC)', m.bac, `${b.bac_source_label} · ${b.cost_budget_code || 'tanpa RAP'}`),
    moneyTile('Nilai rencana (PV)', m.pv, `${fmt.percent(m.planned_pct)} dari BAC per ${per}`),
    moneyTile('Nilai diperoleh (EV)', m.ev, `${fmt.percent(m.physical_pct)} fisik × BAC`),
    moneyTile('Biaya aktual (AC)', m.ac, `biaya proyek s.d. ${per}`),
    moneyTile('Deviasi jadwal (SV)', m.sv, m.sv_pct === null ? 'EV − PV' : `${fmt.percent(m.sv_pct)} dari PV`, {
      tone: m.sv < 0 ? 'down' : 'up',
    }),
    moneyTile('Deviasi biaya (CV)', m.cv, m.cv_pct === null ? 'EV − AC' : `${fmt.percent(m.cv_pct)} dari EV`, {
      tone: m.cpi_reliable ? (m.cv < 0 ? 'down' : 'up') : 'warn',
    }),
  ]));

  /* Ramalan biaya, dipisahkan dari ukuran posisi dan diberi peringatan sendiri:
     EAC(EVM) = BAC ÷ CPI ikut cacat kalau CPI-nya cacat. Pada data demo CPI
     101,63 menghasilkan EAC Rp 414,98 juta untuk proyek Rp 42,17 miliar —
     angka yang jelas keliru dan justru harus terlihat keliru, bukan disembunyikan. */
  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Ramalan biaya sampai selesai' }),
      el('.spacer'),
      badge(m.cpi_reliable ? 'Berdasarkan CPI yang lengkap' : 'Ikut cacat selama CPI cacat',
        m.cpi_reliable ? 'green' : 'amber'),
    ]),
    el('.card-body', [
      el('.stat-row', { style: { marginBottom: '0' } }, [
        moneyTile('Perkiraan biaya akhir (EAC)', m.eac_evm, 'BAC ÷ CPI', { tone: m.cpi_reliable ? null : 'warn' }),
        moneyTile('Sisa biaya (ETC)', m.etc, 'EAC − AC', { tone: m.cpi_reliable ? null : 'warn' }),
        moneyTile('Selisih terhadap anggaran (VAC)', m.vac, 'BAC − EAC', {
          tone: m.cpi_reliable ? (Number(m.vac) < 0 ? 'down' : 'up') : 'warn',
        }),
      ]),
      el('p.muted', {
        text: 'Buku besar TIDAK memakai angka ini. Pengakuan pendapatan PSAK 115 memakai EAC dari RAP '
          + '(atau override manajemen), dan itu memang keputusan yang harus ditinjau manusia — bukan '
          + 'hasil bagi statistik. Kedua ramalan sengaja ditampilkan berdampingan dengan nama berbeda.',
        style: { margin: '12px 0 0', fontSize: '12.5px' },
      }),
    ]),
  ]));

  const curve = evmCurve((report.curve || {}).points, Number(m.bac) || 0);
  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Kurva-S baseline (rencana, diperoleh, biaya)' }),
      el('.spacer'),
      el('.cell-sub', { text: `titik terakhir = tanggal laporan ${per}` }),
    ]),
    el('.card-body', curve
      ? el('div', [
        curve,
        curveLegend(),
        el('p.muted', {
          text: (report.curve || {}).reason
            || 'Garis rencana adalah kurva yang dibekukan dari bobot dan tanggal WBS pada saat baseline '
              + 'disetujui; garis fisik memakai laporan progres mingguan, dan titik terakhirnya memakai '
              + 'agregat bobot beku yang juga dipakai seluruh angka di atas.',
          style: { margin: '10px 0 0', fontSize: '12.5px' },
        }),
      ])
      : el('p.muted', { text: 'Baseline ini tidak punya titik kurva.', style: { margin: 0 } })),
  ]));

  host.appendChild(kvCard('Baseline yang dipakai', [
    ['Kode', `${b.code} · revisi ${b.revision_no}`],
    ['Status', b.status_label],
    ['Berlaku sejak', fmt.date(b.effective_date)],
    ['Dibekukan pada', b.approved_at ? fmt.dateTime(b.approved_at) : '—'],
    ['BAC', `${fmt.rupiah(b.bac)} — ${b.bac_source_label}`],
    ['Sumber anggaran', `${b.cost_budget_code || '—'} (${b.cost_budget_status || 'tanpa RAP'})`],
    ['Kontrak', `${b.contract_code || '—'} · ${fmt.rupiah(b.contract_value)}`],
    ['Rencana mulai — selesai', `${fmt.date(b.planned_start)} — ${fmt.date(b.planned_finish)} (${b.planned_duration_days} hari)`],
    ['Selesai menurut kontrak', fmt.date(b.contract_finish)],
    ['Paket pekerjaan beku', `${b.leaf_task_count} daun · total bobot ${fmt.percent(b.leaf_weight_total, { decimals: 4 })}`],
    ['Alasan', b.reason],
    ['Dokumen acuan', [b.reference_type, b.reference_no].filter(Boolean).join(' ') || null],
  ]));

  host.appendChild(coverageCard(report.cost_coverage || {}));
  host.appendChild(bridgeCard(report.poc_reconciliation || {}));

  const deviation = deviationCard(report.baseline_deviation);
  if (deviation) host.appendChild(deviation);

  const drift = scopeDriftCard(report.scope_drift || {});
  if (drift) host.appendChild(drift);

  const warnings = report.warnings || [];
  if (warnings.length) {
    host.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: 'Catatan atas laporan ini' }),
        el('.spacer'),
        badge(`${warnings.length} catatan`, 'amber'),
      ]),
      el('.card-body', el('ul', {
        style: { margin: 0, paddingLeft: '18px', fontSize: '13px', color: 'var(--text-2)' },
      }, warnings.map((text) => el('li', { text, style: { marginBottom: '5px' } })))),
    ]));
  }

  host.appendChild(el('.row-actions', [
    button('Buka ruang kerja proyek', {
      iconName: 'chevron',
      onClick: () => navigate(`d/projects/${report.project.id}`),
    }),
    button('Buka biaya proyek', {
      variant: 'ghost',
      onClick: () => navigate('r/finance/project-costs'),
    }),
  ]));
}

/* ------------------------------------------------------------ baseline view */

function baselineDetail(detail) {
  const tasks = detail.tasks || [];
  const points = detail.points || [];
  const curve = evmCurve(points.map((point) => ({
    period_end: point.period_end,
    planned_pct: point.planned_pct,
    actual_pct: null,
    actual_cost: null,
    is_as_of: false,
  })), Number(detail.bac) || 0);

  return el('.card', [
    el('.card-head', [
      el('h2', { text: `Isi beku ${detail.code}` }),
      el('.spacer'),
      badge(`${tasks.filter((task) => task.is_leaf).length} paket daun`),
      badge(`${points.length} titik kurva`),
    ]),
    el('.card-body', [
      curve || el('p.muted', { text: 'Baseline ini tidak punya titik kurva.', style: { margin: 0 } }),
      curve ? el('.legend', [el('span', [el('i.base'), 'Rencana kumulatif saat dibekukan'])]) : null,
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }),
        el('th', { text: 'Uraian' }),
        el('th.right', { text: 'Bobot beku' }),
        el('th', { text: 'Rencana mulai' }),
        el('th', { text: 'Rencana selesai' }),
        el('th.right', { text: 'Progres sekarang' }),
      ])),
      el('tbody', tasks.map((task) => {
        // live_exists hanya ada saat relasi liveTask dimuat (endpoint show).
        // Bedakan "tugasnya dihapus dari WBS" dari "kami tidak menanyakannya".
        const gone = task.live_exists === false;
        return el('tr', [
          el('td.code', { text: task.wbs_code || '' }),
          el('td', el('span', [
            el('span.cell-main', {
              text: task.name,
              style: gone ? { textDecoration: 'line-through', color: 'var(--muted)' } : null,
            }),
            gone ? el('span.cell-sub', { text: 'sudah tidak ada di WBS — bobotnya dihitung nol' }) : null,
          ])),
          el('td.right.num', { text: fmt.percent(task.weight_pct, { decimals: 4 }) }),
          el('td', { text: fmt.date(task.planned_start) }),
          el('td', { text: fmt.date(task.planned_end) }),
          el('td.right.num', {
            text: task.live_progress_pct === undefined ? '—' : fmt.percent(task.live_progress_pct),
          }),
        ]);
      })),
    ])),
    points.length
      ? el('.card-body', { style: { paddingBottom: '0' } }, el('p.muted', {
        text: 'Titik kurva beku. Inilah yang menjadi nilai rencana (PV) pada setiap tanggal laporan — '
          + 'bukan kolom rencana pada laporan progres mingguan.',
        style: { margin: 0, fontSize: '12.5px' },
      }))
      : null,
    points.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Akhir periode' }),
          el('th.right', { text: 'Rencana kumulatif' }),
          el('th.right', { text: 'Nilai rencana (PV)' }),
        ])),
        el('tbody', points.map((point) => el('tr', [
          el('td', { text: fmt.date(point.period_end) }),
          el('td.right.num', { text: fmt.percent(point.planned_pct, { decimals: 4 }) }),
          el('td.right.num', { text: fmt.rupiah(point.planned_value), title: exact(point.planned_value) }),
        ]))),
      ]))
      : null,
  ]);
}

function baselineView(host, rows, detail, ctx) {
  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Register baseline' }),
      el('.spacer'),
      session.can('prj.create')
        ? button('Bekukan baseline', {
          size: 'sm', variant: 'primary',
          onClick: () => freezeBaseline(ctx.projectId, rows.length > 0, ctx.reload),
        })
        : null,
    ]),
    rows.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }),
          el('th.right', { text: 'Revisi' }),
          el('th', { text: 'Status' }),
          el('th', { text: 'Berlaku' }),
          el('th.right', { text: 'BAC' }),
          el('th', { text: 'Alasan / acuan' }),
          el('th', { text: '' }),
        ])),
        el('tbody', rows.map((row) => el('tr', [
          el('td.code', { text: row.code }),
          el('td.right.num', { text: String(row.revision_no) }),
          el('td', el('span', [
            badge(row.status_label || row.status, fmt.statusTone(row.status)),
            row.is_current ? el('div', badge('Berlaku', 'green')) : null,
            row.superseded_by_id
              ? el('.cell-sub', { text: 'digantikan revisi berikutnya — tetap tersimpan' })
              : null,
          ])),
          el('td', { text: fmt.date(row.effective_date) }),
          el('td.right.num', el('span', [
            el('span.cell-main', { text: fmt.rupiah(row.bac) }),
            el('span.cell-sub', { text: row.bac_source_label || '' }),
          ])),
          el('td', el('span', [
            el('span.cell-main', { text: row.reason || '—' }),
            el('span.cell-sub', { text: [row.reference_type, row.reference_no].filter(Boolean).join(' ') }),
          ])),
          el('td.right', el('.row-actions', { style: { justifyContent: 'flex-end' } },
            baselineRowActions(row, ctx.reload, [
              // Hanya revisi yang SUDAH disetujui yang boleh dibaca sebagai
              // laporan: baseline_id pada endpoint EVM memang menolak yang lain.
              row.status === 'approved'
                ? button('Lihat laporan', { size: 'sm', variant: 'ghost', onClick: () => ctx.pin(row.id) })
                : null,
              button(ctx.openId === row.id ? 'Tutup isi' : 'Lihat isi', {
                size: 'sm', variant: 'ghost',
                onClick: () => ctx.open(ctx.openId === row.id ? null : row.id),
              }),
            ]))),
        ]))),
      ]))
      : el('.card-body', emptyState(
        'Bekukan rencana WBS dan RAP agar SPI dan CPI punya pembanding yang tidak bisa diubah diam-diam.',
        { title: 'Belum ada baseline untuk proyek ini' },
      )),
  ]));

  if (detail) host.appendChild(baselineDetail(detail));
}

/* ------------------------------------------------------------ cara kerjanya */

function howItWorks() {
  return el('.card', [
    el('.card-head', el('h2', { text: 'Cara kerjanya' })),
    el('.card-body', [
      el('p', { text: 'Baseline adalah salinan beku dari WBS proyek: bobot tiap paket pekerjaan, tanggal rencana mulai dan selesainya, serta BAC yang diambil dari RAP. Begitu disetujui, isinya tidak bisa diubah lagi — perubahan rencana harus lewat revisi baru yang menggantikan revisi lama, dan revisi lama tidak pernah dihapus.' }),
      el('p', { text: 'Nilai rencana (PV) dibaca dari kurva beku itu, bukan dari kolom rencana pada laporan mingguan yang bisa ditulis ulang. Nilai diperoleh (EV) = progres fisik × BAC, dengan progres fisik dihitung memakai BOBOT BEKU: menaikkan bobot pekerjaan yang sudah selesai setelah rencana disetujui tidak akan menambah nilai diperoleh sepeser pun. Biaya aktual (AC) adalah penjumlahan biaya proyek sampai tanggal laporan.' }),
      el('p', { text: 'SPI = EV ÷ PV menjawab "seberapa jauh di depan atau di belakang jadwal", CPI = EV ÷ AC menjawab "seberapa efisien uangnya". Keduanya tidak berarti apa-apa tanpa tanggal, karena itu setiap angka di layar ini selalu disertai "per tanggal" — dan tanggal itu datang dari server, bukan dari jam komputer Anda.' }),
      el('p', { text: 'CPI bisa menolak dipercaya. Kalau ada kategori biaya yang dianggarkan di RAP tetapi belum satu rupiah pun tercatat di buku biaya proyek — pada data demo: upah, subkon, alat dan overhead — maka AC terlalu kecil dan CPI menjadi terlalu bagus. Dalam keadaan itu kartunya berwarna ambar, tidak pernah hijau, dan kategori yang kosong disebut namanya di kartu Cakupan biaya.' }),
      el('p', { text: 'Progres fisik dan persentase penyelesaian PSAK 115 memang berbeda dan tidak boleh dipaksa sama: yang pertama mengukur pekerjaan, yang kedua mengukur biaya terhadap perkiraan biaya akhir. Rasio keduanya justru sama dengan CPI × (EAC ÷ BAC). Laporan ini hanya MEMBACA angka PSAK 115; ia tidak pernah membuat jurnal dan tidak pernah mengubah EAC yang dipakai buku besar.' }),
      el('p', { text: 'Menyetujui baseline memakai aturan maker-checker yang sama dengan dokumen lain: orang yang mengajukan tidak boleh menyetujui sendiri.' }),
    ]),
  ]);
}

/* ------------------------------------------------------------------- screen */

export async function renderEvm(host) {
  clear(host);

  if (!session.can('prj.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki hak akses prj.view untuk laporan EVM.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'EVM & Baseline Kurva-S' }),
      el('.desc', {
        text: 'Kinerja jadwal (SPI) dan biaya (CPI) setiap proyek terhadap rencana yang sudah dibekukan '
          + 'dan disetujui — bukan terhadap rencana yang masih bisa diubah.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() })]),
  ]));

  const tabs = el('.tabs');
  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(tabs, controls, body);

  const projectRows = await loadSource('projects').catch(() => []);
  const options = optionsFor('projects', projectRows);

  const projectSelect = el('select.filter-w', {
    'aria-label': 'Proyek',
    onchange: () => {
      state.projectId = projectSelect.value ? Number(projectSelect.value) : null;
      state.baselineId = null;
      state.openBaselineId = null;
      load();
    },
  });
  options.forEach((option) => projectSelect.appendChild(el('option', { value: option.value, text: option.label })));

  if (!options.some((option) => String(option.value) === String(state.projectId))) {
    state.projectId = options.length ? Number(options[0].value) : null;
  }
  projectSelect.value = state.projectId === null ? '' : String(state.projectId);

  /*
   * Kosong berarti "hari ini menurut server". Nilai maksimalnya BARU dipasang
   * setelah server menyebut tanggalnya sendiri — memakai jam browser untuk
   * membatasi kolom ini akan menolak tanggal yang sah pada komputer yang jamnya
   * mundur, dan menerima tanggal masa depan pada komputer yang jamnya maju.
   */
  const asOfInput = el('input.filter-w', {
    type: 'date',
    value: state.asOf,
    'aria-label': 'Tanggal laporan',
    title: 'Kosongkan untuk memakai tanggal hari ini menurut server',
    onchange: () => { state.asOf = asOfInput.value; load(); },
  });
  if (state.serverToday) asOfInput.max = state.serverToday;

  const asOfNote = el('span.cell-sub', {
    text: 'kosong = tanggal server',
    style: { marginLeft: '-2px' },
  });

  controls.append(
    projectSelect,
    asOfInput,
    asOfNote,
    el('.spacer'),
    button('Muat ulang', { size: 'sm', variant: 'ghost', iconName: 'refresh', onClick: () => load() }),
  );

  function paintTabs() {
    clear(tabs);
    TABS.forEach((tab) => tabs.appendChild(el(`button${tab.key === state.tab ? '.active' : ''}`, {
      text: tab.label,
      onclick: () => {
        if (state.tab === tab.key) return;
        state.tab = tab.key;
        paintTabs();
        load();
      },
    })));
  }

  /** Tanggal server hanya diketahui saat kita TIDAK mengirim as_of sendiri. */
  function rememberServerToday(asOf) {
    if (state.asOf || !asOf) return;
    state.serverToday = asOf;
    asOfInput.max = asOf;
  }

  function pick(projectId) {
    state.projectId = projectId;
    state.baselineId = null;
    state.openBaselineId = null;
    state.tab = 'report';
    projectSelect.value = String(projectId);
    paintTabs();
    load();
  }

  async function load() {
    clear(body);
    // Pemilih proyek disembunyikan di portofolio: tab itu memang mencakup
    // SEMUA proyek, dan pemilih yang tetap terlihat akan terbaca sebagai
    // penyaring yang sedang tidak bekerja.
    projectSelect.style.display = state.tab === 'portfolio' ? 'none' : '';
    body.appendChild(skeletonTable(6, 6));

    try {
      if (state.tab === 'portfolio') {
        const payload = await api.get('projects/evm', { as_of: state.asOf || undefined });
        rememberServerToday(payload.as_of);
        clear(body);
        portfolioView(body, payload, pick);
        body.appendChild(howItWorks());
        return;
      }

      if (state.projectId === null) {
        clear(body).appendChild(el('.alert.warn',
          'Belum ada proyek yang dapat dibaca. Buat proyek lebih dulu, atau minta hak akses prj.view.'));
        return;
      }

      if (state.tab === 'baseline') {
        const rows = (await api.get('projects/baselines', {
          project_id: state.projectId, per_page: 50,
        })) || [];

        // Baris yang sedang dibuka mungkin sudah lenyap (dihapus di tab lain,
        // atau proyeknya berganti) — jangan meminta detail yang tidak ada lagi.
        const openRow = rows.find((row) => row.id === state.openBaselineId) || null;
        const detail = openRow ? await api.get(`projects/baselines/${openRow.id}`).catch(() => null) : null;

        clear(body);
        baselineView(body, rows, detail, {
          projectId: state.projectId,
          openId: openRow ? openRow.id : null,
          reload: load,
          open: (id) => { state.openBaselineId = id; load(); },
          pin: (id) => { state.baselineId = id; state.tab = 'report'; paintTabs(); load(); },
        });
        body.appendChild(howItWorks());
        return;
      }

      const [report, baselines] = await Promise.all([
        api.get(`projects/${state.projectId}/evm`, {
          as_of: state.asOf || undefined,
          baseline_id: state.baselineId || undefined,
        }),
        // Hanya untuk menjawab "sudah pernah ada baseline?", yang menentukan
        // apakah alasan re-baseline wajib diisi pada dialog pembekuan.
        api.get('projects/baselines', { project_id: state.projectId, per_page: 1 }).catch(() => []),
      ]);

      rememberServerToday(report.as_of);
      clear(body);
      reportView(body, report, {
        hasBaseline: (baselines || []).length > 0,
        pinned: state.baselineId !== null,
        unpin: () => { state.baselineId = null; load(); },
        reload: load,
      });
      body.appendChild(howItWorks());
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
    }
  }

  paintTabs();
  await load();
}
