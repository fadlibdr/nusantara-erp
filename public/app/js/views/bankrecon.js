/* Rekonsiliasi Bank — import a rekening koran, match its lines against what the
 * ERP posted, and read the bridge from the bank's closing balance to the
 * general ledger.
 *
 * The screen never posts. Every button here either reads, or records that a
 * bank movement and an existing posting are the same event. When a difference
 * needs a journal, it says so and sends the operator to the journal screen —
 * two screens, one posting path.
 *
 * The file is sent as TEXT inside the ordinary JSON body: api.js authenticates
 * on a header and serialises every body as JSON, so FormData would arrive as
 * "{}". FileReader on this side, string on the wire, nothing written to disk. */

import { api, session } from '../api.js';
import {
  el, clear, append, button, badge, icon, errorState, skeletonTable,
  toast, toastError, modal, confirmDialog, withBusy, field,
} from '../ui.js';
import * as fmt from '../format.js';

const TABS = [
  // Every account at once, before drilling into one. The endpoint behind it has
  // existed since the bridge was built and nothing ever called it.
  { key: 'overview', label: 'Ringkasan Semua Rekening' },
  { key: 'reconcile', label: 'Rekonsiliasi' },
  { key: 'statements', label: 'Rekening Koran' },
  { key: 'import', label: 'Impor' },
];

const REASONS = {
  bank_charge: 'Biaya/admin bank',
  interest: 'Bunga/jasa giro',
  unrecorded_receipt: 'Penerimaan belum dicatat',
  unrecorded_payment: 'Pengeluaran belum dicatat',
  bank_error: 'Kesalahan bank (menunggu koreksi)',
  other: 'Lainnya',
};

/* What to do about each kind of difference. bank_error deliberately has no
 * "book it" instruction: the money will come back, and booking a charge that
 * later reverses puts fictitious expense in one month and fictitious income in
 * the next while every reconciliation in between looks perfect. */
const GUIDANCE = {
  bank_charge: 'Bukukan sebagai voucher jurnal (Dr beban admin bank / Cr rekening bank), lalu cocokkan baris ini ke jurnal itu.',
  interest: 'Bukukan sebagai voucher jurnal (Dr rekening bank / Cr pendapatan bunga), lalu cocokkan baris ini ke jurnal itu.',
  unrecorded_receipt: 'Catat penerimaannya di Keuangan › Pembayaran, alokasikan ke invoice, posting, lalu cocokkan.',
  unrecorded_payment: 'Catat pengeluarannya di Keuangan › Pembayaran, alokasikan ke tagihan, posting, lalu cocokkan.',
  bank_error: 'Jangan dibukukan. Tunggu koreksi dari bank; baris ini tetap tampil sebagai selisih sampai koreksinya masuk.',
  other: 'Selesaikan sesuai dokumen pendukungnya, lalu cocokkan atau biarkan tampil sebagai selisih.',
};

const DATE_FORMATS = ['dd/mm/yyyy', 'dd-mm-yyyy', 'yyyy-mm-dd', 'dd/mm/yy', 'dd/mm'];

/* Some Finance endpoints paginate and some return a plain list, so the envelope
 * arrives as either an array or a paginator. Reading `.data` off an array
 * yields undefined and the screen renders "no data" over a full response. */
function rows(payload) {
  if (Array.isArray(payload)) return payload;
  return (payload && payload.data) || [];
}

const state = {
  tab: 'reconcile',
  bankAccountId: null,
  asOf: '',
  statementId: null,
};

/* ---------------------------------------------------------------- overview */

/**
 * Every active bank account's bridge on one screen.
 *
 * The point of a list like this is the accounts that are NOT fine — an account
 * whose bridge does not close, or that cannot be reconciled at all because it
 * has no statement or no COA account. Those are shown as they are, not hidden
 * behind a dash.
 */
function renderOverview(host, overview, onPick) {
  const accounts = overview.rows || [];

  if (!accounts.length) {
    host.appendChild(el('.alert.warn', 'Belum ada rekening bank aktif.'));
    return;
  }

  const unreconciled = accounts.filter((row) => !row.fully_reconciled).length;
  const blocked = accounts.filter((row) => row.blocked).length;

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Rekening aktif' }), el('.value.sm', { text: String(accounts.length) })]),
    el('.stat', [
      el('.label', { text: 'Belum tuntas' }),
      el('.value.sm', { text: String(unreconciled) }),
      unreconciled ? el('.delta.down', { text: 'masih ada pos terbuka' }) : null,
    ]),
    el('.stat', [
      el('.label', { text: 'Tidak dapat direkonsiliasi' }),
      el('.value.sm', { text: String(blocked) }),
      blocked ? el('.delta.down', { text: 'lihat kolom catatan' }) : null,
    ]),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Ringkasan rekonsiliasi per rekening' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Rekening' }),
        el('th.right', { text: 'Saldo rekening koran' }),
        el('th.right', { text: 'Saldo buku besar' }),
        el('th.right', { text: 'Selisih awal' }),
        el('th.right', { text: 'Pos terbuka' }),
        el('th', { text: 'Status' }),
      ])),
      el('tbody', accounts.map((row) => {
        const node = el('tr', { style: { cursor: 'pointer' } }, [
          el('td', el('span', [
            el('span.cell-main', { text: row.bank_account.name }),
            el('span.cell-sub.mono', { text: `${row.bank_account.bank_name || ''} ${row.bank_account.account_no || ''}`.trim() }),
          ])),
          el('td.right.num', { text: fmt.rupiah(row.statement_closing) }),
          el('td.right.num', { text: fmt.rupiah(row.gl_balance) }),
          el('td.right.num', {
            text: fmt.rupiah(row.opening_difference),
            style: Number(row.opening_difference) ? { color: 'var(--warning)' } : {},
          }),
          el('td.right.num', { text: row.open_items === null || row.open_items === undefined ? '—' : String(row.open_items) }),
          el('td', row.blocked
            ? badge(row.blocked, 'red')
            : (row.fully_reconciled ? badge('Tuntas', 'green') : badge(row.bridge_closes ? 'Jembatan cocok' : 'Belum cocok', row.bridge_closes ? 'amber' : 'red'))),
        ]);

        node.addEventListener('click', () => onPick(row.bank_account.id));
        return node;
      })),
    ])),
  ]));
}

/* ------------------------------------------------------------------ import */

function importDefaults() {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const end = new Date(now.getFullYear(), now.getMonth(), 0);
  return {
    format: 'csv',
    content: '',
    filename: '',
    mapping: {
      delimiter: ';',
      skip_rows: 1,
      date_column: 0,
      date_format: 'dd/mm/yyyy',
      description_column: 1,
      amount_mode: 'debit_credit',
      debit_column: 2,
      credit_column: 3,
      amount_column: null,
      indicator_column: null,
      balance_column: null,
      reference_column: null,
      number_format: 'id',
      period_start: fmt.toDateInput(start),
      period_end: fmt.toDateInput(end),
      opening_balance: 0,
      closing_balance: 0,
    },
  };
}

function selectField(label, value, options, onChange, help) {
  const select = el('select', {
    onchange: (event) => onChange(event.target.value === '' ? null : event.target.value),
  });
  options.forEach(([optionValue, optionLabel]) => {
    select.appendChild(el('option', { value: optionValue, text: optionLabel }));
  });
  select.value = value === null || value === undefined ? '' : String(value);
  return field(label, select, { help });
}

function inputField(label, value, type, onChange, help) {
  const input = el('input', {
    type,
    value: value === null || value === undefined ? '' : value,
    oninput: (event) => onChange(event.target.value === '' ? null : event.target.value),
  });
  return field(label, input, { help });
}

function columnOptions() {
  const options = [['', '— tidak ada —']];
  for (let i = 0; i < 20; i++) options.push([String(i), `Kolom ${i + 1}`]);
  return options;
}

function renderImport(host, accounts, onImported) {
  const form = importDefaults();
  // Start from the account chosen in the toolbar, not from whichever happens
  // to be first — importing a statement against the wrong account is the one
  // mistake here that is expensive to undo.
  form.bankAccountId = state.bankAccountId ?? (accounts[0] && accounts[0].id);
  const body = el('div');
  const previewHost = el('div');

  function paint() {
    clear(body);

    const fileInput = el('input', {
      type: 'file',
      accept: '.csv,.txt,.sta,.940',
      onchange: async (event) => {
        const file = event.target.files && event.target.files[0];
        if (!file) return;
        form.content = await file.text();
        form.filename = file.name;
        if (/\.(sta|940)$/i.test(file.name) || form.content.includes(':61:')) form.format = 'mt940';
        paint();
        toast(`${file.name} dibaca (${Math.round(form.content.length / 1024)} kB).`);
      },
    });

    body.appendChild(el('.card', [
      el('.card-head', [el('h2', { text: '1 · Berkas' })]),
      el('.form-grid', [
        selectField('Rekening bank', form.bankAccountId,
          accounts.map((a) => [String(a.id), `${a.name} — ${a.bank_name} ${a.account_no}`]),
          (value) => { form.bankAccountId = Number(value); }),
        selectField('Format', form.format, [['csv', 'CSV rekening koran'], ['mt940', 'MT940 (SWIFT)']],
          (value) => { form.format = value; paint(); }),
        field('Berkas', fileInput, {
          help: form.filename
            ? `Terbaca: ${form.filename}`
            : 'Berkas dibaca di peramban dan dikirim sebagai teks; tidak ada berkas yang disimpan di server.',
        }),
      ]),
    ]));

    if (form.format === 'csv') body.appendChild(mappingCard(form, paint));

    body.appendChild(el('.card-foot', [
      button('Pratinjau', {
        variant: 'primary',
        iconName: 'search',
        onClick: (event) => withBusy(event.currentTarget, () => preview(event.currentTarget)),
      }),
    ]));
  }

  async function payload() {
    return {
      bank_account_id: form.bankAccountId,
      format: form.format,
      content: form.content,
      mapping: form.format === 'csv' ? form.mapping : undefined,
    };
  }

  async function preview() {
    clear(previewHost);
    if (!form.content) { toastError(new Error('Pilih berkas rekening koran lebih dulu.')); return; }
    try {
      const result = await api.post('finance/bank-statements/preview', await payload());
      previewHost.appendChild(previewCard(result, async (node) => {
        await withBusy(node, async () => {
          const created = await api.post('finance/bank-statements', await payload());
          toast(`Rekening koran ${created.code} diimpor.`);
          clear(previewHost);
          onImported(created);
        });
      }));
    } catch (error) {
      previewHost.appendChild(errorState(error, () => preview()));
    }
  }

  paint();
  host.append(body, previewHost);
}

function mappingCard(form, repaint) {
  const m = form.mapping;
  const set = (key) => (value) => { m[key] = value === null ? null : value; };
  const setNum = (key) => (value) => { m[key] = value === null ? null : Number(value); };

  const amountFields = m.amount_mode === 'debit_credit'
    ? [
      selectField('Kolom debit (keluar)', m.debit_column, columnOptions(), setNum('debit_column')),
      selectField('Kolom kredit (masuk)', m.credit_column, columnOptions(), setNum('credit_column')),
    ]
    : [
      selectField('Kolom nilai', m.amount_column, columnOptions(), setNum('amount_column')),
      m.amount_mode === 'single_with_indicator'
        ? selectField('Kolom penanda D/K', m.indicator_column, columnOptions(), setNum('indicator_column'),
          'Kosongkan bila penandanya menempel pada sel nilai, mis. "500.000,00 DB".')
        : null,
    ];

  return el('.card', [
    el('.card-head', [el('h2', { text: '2 · Pemetaan kolom' })]),
    el('.alert.info', [
      icon('warn', 15),
      el('div', { text: 'Kolom tidak ditebak. Tidak ada format CSV rekening koran yang baku di Indonesia, '
        + 'dan tebakan yang benar sembilan kali lalu salah sekali menghasilkan berkas yang terimpor rapi '
        + 'dan salah. Tetapkan kolomnya, lalu periksa pratinjaunya.' }),
    ]),
    el('.form-grid', [
      selectField('Pemisah kolom', m.delimiter,
        [[';', 'Titik koma ( ; )'], [',', 'Koma ( , )'], ['|', 'Garis tegak ( | )'], ['tab', 'Tab']],
        (value) => { m.delimiter = value; }),
      selectField('Format angka', m.number_format, [['id', 'Indonesia — 1.234.567,89'], ['en', 'Inggris — 1,234,567.89']],
        (value) => { m.number_format = value; }),
      inputField('Baris judul yang dilewati', m.skip_rows, 'number', setNum('skip_rows')),
      selectField('Kolom tanggal', m.date_column, columnOptions(), setNum('date_column')),
      selectField('Format tanggal', m.date_format, DATE_FORMATS.map((f) => [f, f]), (value) => { m.date_format = value; }),
      selectField('Kolom keterangan', m.description_column, columnOptions(), setNum('description_column')),
      selectField('Mode nilai', m.amount_mode, [
        ['debit_credit', 'Dua kolom: debit & kredit'],
        ['single_signed', 'Satu kolom bertanda (+/−)'],
        ['single_with_indicator', 'Satu kolom + penanda D/K'],
      ], (value) => { m.amount_mode = value; repaint(); }),
      ...amountFields.filter(Boolean),
      selectField('Kolom saldo', m.balance_column, columnOptions(), setNum('balance_column'),
        'Sangat dianjurkan. Saldo berjalan bank adalah satu-satunya pemeriksaan yang tidak bergantung '
        + 'pada angka yang Anda ketik, dan menunjuk baris yang salah baca.'),
      selectField('Kolom referensi', m.reference_column, columnOptions(), setNum('reference_column')),
      inputField('Periode mulai', m.period_start, 'date', set('period_start')),
      inputField('Periode selesai', m.period_end, 'date', set('period_end')),
      inputField('Saldo awal', m.opening_balance, 'number', setNum('opening_balance')),
      inputField('Saldo akhir', m.closing_balance, 'number', setNum('closing_balance')),
    ]),
  ]);
}

function previewCard(result, onImport) {
  const s = result.statement;
  const blocked = !result.can_import;

  return el('.card', [
    el('.card-head', [
      el('h2', { text: '3 · Pratinjau' }),
      el('.spacer'),
      badge(s.ties_out ? 'Seimbang' : 'Tidak seimbang', s.ties_out ? 'green' : 'red'),
    ]),
    el('.stat-row', [
      el('.stat', [el('.label', { text: 'Saldo awal' }), el('.value.sm', { text: fmt.rupiah(s.opening_balance) })]),
      el('.stat', [el('.label', { text: 'Mutasi' }), el('.value.sm', { text: fmt.rupiah(s.movement) })]),
      el('.stat', [el('.label', { text: 'Saldo akhir' }), el('.value.sm', { text: fmt.rupiah(s.closing_balance) })]),
      el('.stat', [el('.label', { text: 'Jumlah mutasi' }), el('.value', { text: String(s.line_count) })]),
    ]),
    ...(result.blockers || []).map((text) => el('.alert.error', [icon('warn', 15), el('div', { text })])),
    ...(s.warnings || []).map((text) => el('.alert.warn', [icon('warn', 15), el('div', { text })])),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: '#' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Keterangan' }),
        el('th', { text: 'Referensi' }), el('th.right', { text: 'Debit' }), el('th.right', { text: 'Kredit' }),
      ])),
      el('tbody', s.lines.slice(0, 200).map((line) => el('tr', [
        el('td.code', { text: String(line.line_no) }),
        el('td', { text: fmt.date(line.entry_date) }),
        el('td', { text: line.description || '—' }),
        el('td.code', { text: line.bank_reference || line.customer_reference || '—' }),
        el('td.right.num', { text: line.direction === 'debit' ? fmt.rupiah(line.amount) : '' }),
        el('td.right.num', { text: line.direction === 'credit' ? fmt.rupiah(line.amount) : '' }),
      ]))),
    ])),
    s.lines.length > 200 ? el('p.muted', { text: `Menampilkan 200 dari ${s.lines.length} baris.` }) : null,
    el('.card-foot', [
      button('Impor rekening koran', {
        variant: 'primary',
        iconName: 'check',
        disabled: blocked,
        title: blocked ? 'Perbaiki hal di atas lebih dulu' : undefined,
        onClick: (event) => onImport(event.currentTarget),
      }),
    ]),
  ]);
}

/* -------------------------------------------------------------- statements */

function renderStatements(host, statements, suggestions, onChanged) {
  if (!statements.length) {
    host.appendChild(el('.card', el('.card-body', el('p.muted', {
      text: 'Belum ada rekening koran yang diimpor untuk rekening ini.', style: { margin: 0 },
    }))));
    return;
  }

  const active = statements.find((s) => s.id === state.statementId) || statements[0];
  state.statementId = active.id;

  const optionLabel = (s) =>
    `${s.code} · ${fmt.date(s.period_start)} – ${fmt.date(s.period_end)} · ${s.matched_lines_count}/${s.lines_count} cocok`;

  const picker = el('select.filter-w', {
    onchange: (event) => { state.statementId = Number(event.target.value); onChanged(); },
  });
  const options = new Map();
  statements.forEach((s) => {
    const option = el('option', { value: s.id, text: optionLabel(s) });
    options.set(s.id, option);
    picker.appendChild(option);
  });
  picker.value = String(active.id);

  // Hitungan "X/Y cocok" pada picker ikut bergerak saat satu baris berubah —
  // tanpa render ulang, jadi tanpa kehilangan posisi gulir (Temuan 18).
  const refreshCounts = () => {
    const option = options.get(active.id);
    if (option) option.textContent = optionLabel(active);
  };

  host.appendChild(el('.filters', { style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' } }, [
    picker,
    el('.spacer'),
    session.can('fin.delete')
      ? button('Hapus', {
        variant: 'danger', size: 'sm', iconName: 'trash',
        onClick: () => confirmDialog({
          title: `Hapus ${active.code}?`,
          message: 'Rekening koran hanya dapat dihapus selama belum ada baris yang dicocokkan. '
            + 'Ini cara memperbaiki impor dengan pemetaan kolom yang salah.',
          onConfirm: async () => {
            await api.del(`finance/bank-statements/${active.id}`);
            state.statementId = null;
            toast('Rekening koran dihapus.');
            onChanged();
          },
        }),
      })
      : null,
  ]));

  host.appendChild(linesCard(active, suggestions, onChanged, refreshCounts));
}

function matchStatusBadge(line) {
  if (line.match_status === 'matched') return badge('Cocok', 'green');
  if (line.match_status === 'no_match') return badge('Tanpa padanan', 'amber');
  return badge('Belum ditinjau', '');
}

/* Satu <tr> yang menggambar ulang DIRINYA saja setelah cocok/batal/tanpa
 * padanan (Temuan 18). Jalur lama memanggil load(): seluruh body dibuang, dua
 * request diulang, tabel dirender dari nol — mencocokkan rekening koran 200
 * baris berarti 200 kali kembali ke puncak halaman. Sel yang berubah karena
 * satu aksi hanyalah sel status dan sel padanan baris itu; sel tanggal/nilai
 * ikut digambar ulang karena barisnya satu unit, tetapi DOM di luar baris ini
 * tidak disentuh sama sekali — posisi gulir bertahan.
 *
 * Respons match/unmatch/no-match sudah mengembalikan baris terbaru (kontrak
 * server tidak berubah); hanya matched_code yang tidak ikut — ia dilekatkan
 * show() dari dua query terpisah — jadi kode dokumen diambil dari kandidat
 * yang barusan diklik. Saran padanan baris LAIN dibiarkan apa adanya: bisa
 * basi (dokumen yang sama dimenangkan baris ini), dan server tetap menolak
 * pencocokan ganda — tombol "Muat ulang" di kepala kartu adalah jalur
 * sinkronisasi penuhnya. */
function lineRow(line, candidates, onCounted) {
  const tr = el('tr');
  let current = line;

  function applyUpdate(updated, matchedCode) {
    const wasMatched = current.match_status === 'matched';
    current = { ...current, ...updated, matched_code: matchedCode ?? null };
    const isMatched = current.match_status === 'matched';
    if (isMatched !== wasMatched) onCounted(isMatched ? 1 : -1);
    paint();
  }

  function paint() {
    clear(tr);
    append(tr, [
      el('td.code', { text: String(current.line_no) }),
      el('td', { text: fmt.date(current.entry_date) }),
      el('td', [
        el('.cell-main', { text: current.description || '—' }),
        el('.cell-sub', { text: current.bank_reference || current.customer_reference || '' }),
      ]),
      el('td.right.num', { text: current.direction === 'debit' ? fmt.rupiah(current.amount) : '' }),
      el('td.right.num', { text: current.direction === 'credit' ? fmt.rupiah(current.amount) : '' }),
      el('td', [
        matchStatusBadge(current),
        current.note_reason ? el('.cell-sub', { text: REASONS[current.note_reason] || current.note_reason }) : null,
      ]),
      el('td', matchCell(current, candidates, applyUpdate)),
    ]);
  }

  paint();
  return tr;
}

function linesCard(statement, suggestions, onChanged, onCountsChanged) {
  const rows = (statement.lines || []).map((line) => lineRow(line, suggestions[line.id] || [], (delta) => {
    statement.matched_lines_count = (statement.matched_lines_count || 0) + delta;
    onCountsChanged();
  }));

  return el('.card', [
    el('.card-head', [
      el('h2', { text: `Mutasi — ${statement.code}` }),
      el('.spacer'),
      el('.muted', { text: `${fmt.rupiah(statement.opening_balance)} → ${fmt.rupiah(statement.closing_balance)}` }),
      // Sinkronisasi penuh atas permintaan: ambil ulang detail + saran
      // padanan. Satu-satunya jalur yang menyegarkan saran baris lain setelah
      // serangkaian pencocokan in-situ.
      button('Muat ulang', {
        size: 'sm', variant: 'ghost', iconName: 'refresh',
        title: 'Ambil ulang detail rekening koran dan saran padanan',
        onClick: () => onChanged(),
      }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: '#' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Keterangan' }),
        el('th.right', { text: 'Debit' }), el('th.right', { text: 'Kredit' }),
        el('th', { text: 'Status' }), el('th', { text: 'Padanan' }),
      ])),
      el('tbody', rows),
    ])),
  ]);
}

/**
 * @param {Function} applyUpdate (updatedLine, matchedCode?) — repaints the ROW
 *                   in place; never re-renders the tab (Temuan 18).
 */
function matchCell(line, candidates, applyUpdate) {
  const canEdit = session.can('fin.update');
  const best = candidates[0];

  if (line.match_status === 'matched') {
    return el('div', [
      el('.cell-main', {
        text: line.matched_code
          || `${line.matched_type === 'payment' ? 'Pembayaran' : 'Jurnal'} #${line.matched_id}`,
      }),
      canEdit
        ? button('Batalkan', {
          size: 'sm', variant: 'ghost',
          onClick: (event) => withBusy(event.currentTarget, async () => {
            try {
              const updated = await api.post(`finance/bank-statement-lines/${line.id}/unmatch`);
              toast('Pencocokan dibatalkan.');
              applyUpdate(updated);
            } catch (error) {
              toastError(error);
            }
          }),
        })
        : null,
    ]);
  }

  if (!canEdit) return el('span.muted', { text: '—' });

  const actions = [];

  if (best) {
    actions.push(button(`${best.code} · ${fmt.date(best.date)}`, {
      size: 'sm',
      variant: best.confidence === 'high' ? 'primary' : '',
      title: `Skor ${best.score} · beda ${best.days_apart} hari`,
      onClick: (event) => withBusy(event.currentTarget, async () => {
        try {
          const updated = await api.post(`finance/bank-statement-lines/${line.id}/match`, {
            matched_type: best.matched_type, matched_id: best.matched_id,
          });
          toast('Baris dicocokkan.');
          applyUpdate(updated, best.code);
        } catch (error) {
          // Termasuk saran yang basi (dokumennya sudah dimenangkan baris
          // lain): server menolak, barisnya tidak berubah, toast menyebut
          // sebabnya — "Muat ulang" di kepala kartu menyegarkan sarannya.
          toastError(error);
        }
      }),
    }));
  }

  actions.push(button(candidates.length > 1 ? `Pilihan lain (${candidates.length - 1})` : 'Cari padanan', {
    size: 'sm', variant: 'ghost',
    onClick: () => openMatchModal(line, applyUpdate),
  }));

  actions.push(button('Tanpa padanan', {
    size: 'sm', variant: 'ghost',
    onClick: () => openNoMatchModal(line, applyUpdate),
  }));

  return el('.row-actions', actions);
}

function openMatchModal(line, applyUpdate) {
  const body = el('div', skeletonTable(4, 3));

  const dialog = modal({
    title: `Cari padanan — ${fmt.rupiah(line.amount)} ${line.direction === 'credit' ? 'masuk' : 'keluar'} ${fmt.date(line.entry_date)}`,
    body,
    width: 'wide',
  });

  api.get(`finance/bank-statement-lines/${line.id}/suggestions`).then((candidates) => {
    clear(body);

    if (!candidates.length) {
      body.appendChild(el('p.muted', {
        text: 'Tidak ada dokumen terposting dengan nilai yang sama persis pada rentang tanggal ini. '
          + 'Pencocokan sebagian tidak didukung: satu mutasi bank harus sama persis dengan satu dokumen.',
      }));
      return;
    }

    body.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Dokumen' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Keterangan' }),
        el('th.right', { text: 'Nilai' }), el('th', { text: 'Keyakinan' }), el('th', { text: '' }),
      ])),
      el('tbody', candidates.map((candidate) => el('tr', [
        el('td.code', { text: candidate.code }),
        el('td', { text: fmt.date(candidate.date) }),
        el('td', { text: candidate.description || '—' }),
        el('td.right.num', { text: fmt.rupiah(candidate.amount) }),
        el('td', badge(
          candidate.confidence === 'high' ? 'Tinggi' : candidate.confidence === 'medium' ? 'Sedang' : 'Rendah',
          candidate.confidence === 'high' ? 'green' : candidate.confidence === 'medium' ? 'amber' : '',
        )),
        el('td', button('Cocokkan', {
          size: 'sm', variant: 'primary',
          onClick: (event) => withBusy(event.currentTarget, async () => {
            try {
              const updated = await api.post(`finance/bank-statement-lines/${line.id}/match`, {
                matched_type: candidate.matched_type, matched_id: candidate.matched_id,
              });
              dialog.close();
              toast('Baris dicocokkan.');
              applyUpdate(updated, candidate.code);
            } catch (error) {
              toastError(error);
            }
          }),
        })),
      ]))),
    ])));
  }).catch((error) => clear(body).appendChild(errorState(error)));
}

function openNoMatchModal(line, applyUpdate) {
  let reason = 'bank_charge';
  const guidance = el('.alert.info', [icon('warn', 15), el('div', { text: GUIDANCE[reason] })]);

  const select = el('select', {
    onchange: (event) => {
      reason = event.target.value;
      clear(guidance).append(icon('warn', 15), el('div', { text: GUIDANCE[reason] }));
    },
  });
  Object.entries(REASONS).forEach(([value, label]) => select.appendChild(el('option', { value, text: label })));

  const note = el('textarea', { rows: 3, placeholder: 'Catatan (opsional)' });

  const save = button('Simpan', {
    variant: 'primary',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        const updated = await api.post(`finance/bank-statement-lines/${line.id}/no-match`, { reason, note: note.value || null });
        dialog.close();
        toast('Baris ditandai tanpa padanan.');
        applyUpdate(updated);
      } catch (error) {
        toastError(error);
      }
    }),
  });

  const dialog = modal({
    title: 'Tandai tanpa padanan',
    body: el('div', [
      el('p.muted', {
        text: 'Baris ini tetap dihitung sebagai selisih rekonsiliasi — ini uang yang benar-benar '
          + 'dipindahkan bank. Yang berubah hanya: baris ini sudah ditinjau, dan alasannya tercatat.',
      }),
      field('Alasan', select),
      field('Catatan', note),
      guidance,
    ]),
    footer: [button('Batal', { onClick: () => dialog.close() }), save],
  });
}

/* ---------------------------------------------------------- reconciliation */

function bridgeRow(label, value, { sign = '', strong = false, muted = false } = {}) {
  return el(`tr${strong ? '.total-row' : ''}`, [
    el('td', { text: label, style: muted ? { color: 'var(--text-2)' } : {} }),
    el('td.right', { text: sign }),
    el('td.right.num', { text: fmt.rupiah(value) }),
  ]);
}

function renderReconciliation(host, report) {
  const b = report.bridge;
  const s = report.summary;

  const tone = s.fully_reconciled ? 'green' : (s.bridge_closes ? 'amber' : 'red');
  const label = s.fully_reconciled
    ? 'Cocok sepenuhnya'
    : (s.bridge_closes ? `Selisih dijelaskan — ${s.open_items} pos terbuka` : 'Ada selisih yang belum dijelaskan');

  host.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Saldo rekening koran' }),
      el('.value.sm', { text: fmt.rupiah(b.statement_closing) }),
      el('.delta', { text: `s/d ${fmt.date(report.as_of)}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Saldo buku besar' }),
      el('.value.sm', { text: fmt.rupiah(b.gl_balance) }),
      el('.delta', { text: `${report.bank_account.coa_code} ${report.bank_account.coa_name || ''}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Pos terbuka' }),
      el('.value', { text: String(s.open_items) }),
      el('.delta', { text: `${s.matched_lines}/${s.total_lines} mutasi dicocokkan` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Selisih belum dijelaskan' }),
      el('.value.sm', { text: fmt.rupiah(b.residual), style: b.residual ? { color: 'var(--danger)' } : {} }),
      el('.delta', { text: b.residual ? 'periksa data' : 'nihil' }),
    ]),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', [el('h2', { text: 'Jembatan rekonsiliasi' }), el('.spacer'), badge(label, tone)]),
    el('.table-wrap', el('table.data', [
      el('tbody', [
        bridgeRow('Saldo akhir rekening koran', b.statement_closing),
        b.opening_difference
          ? bridgeRow('Selisih saldo awal (sebelum periode impor pertama)', b.opening_difference, { sign: '+' })
          : null,
        bridgeRow('Sudah dibukukan, belum tampak di bank — penerimaan', b.on_books_not_on_bank_debit, { sign: '+' }),
        bridgeRow('Sudah dibukukan, belum tampak di bank — pengeluaran', b.on_books_not_on_bank_credit, { sign: '−' }),
        bridgeRow('Ada di bank, belum dibukukan — penerimaan', b.on_bank_not_on_books_credit, { sign: '−' }),
        bridgeRow('Ada di bank, belum dibukukan — pengeluaran', b.on_bank_not_on_books_debit, { sign: '+' }),
        b.residual ? bridgeRow('Selisih belum dijelaskan', b.residual, { sign: '+' }) : null,
        bridgeRow('Saldo buku besar', b.gl_balance, { sign: '=', strong: true }),
      ].filter(Boolean)),
    ])),
  ]));

  if (!s.opening_difference_explained) {
    host.appendChild(el('.alert.warn', [
      icon('warn', 15),
      el('div', { text: 'Saldo awal rekening koran pertama tidak sama dengan saldo buku besar sebelum periode itu. '
        + 'Selisihnya bukan pos berjalan — sesuaikan saldo pembukaan, atau impor periode sebelumnya.' }),
    ]));
  }

  if ((report.possible_mismatches || []).length) {
    host.appendChild(mismatchCard(report.possible_mismatches));
  }

  host.appendChild(openItemsCard(
    'Ada di bank, belum dibukukan',
    report.categories.on_bank_not_on_books,
    (item) => [
      el('td', { text: fmt.date(item.date) }),
      el('td', [
        el('.cell-main', { text: item.description || '—' }),
        item.pending_counterpart_date
          ? el('.cell-sub', { text: `sudah dicocokkan, dibukukan ${fmt.date(item.pending_counterpart_date)}` })
          : el('.cell-sub', { text: item.note_reason_label || '' }),
      ]),
      el('td.right.num', { text: item.direction === 'debit' ? fmt.rupiah(item.amount) : '' }),
      el('td.right.num', { text: item.direction === 'credit' ? fmt.rupiah(item.amount) : '' }),
    ],
  ));

  host.appendChild(openItemsCard(
    'Sudah dibukukan, belum tampak di bank',
    report.categories.on_books_not_on_bank,
    (item) => [
      el('td', { text: fmt.date(item.date) }),
      el('td', [el('.cell-main', { text: item.code || '—' }), el('.cell-sub', { text: item.description || '' })]),
      el('td.right.num', { text: item.direction === 'debit' ? fmt.rupiah(item.amount) : '' }),
      el('td.right.num', { text: item.direction === 'credit' ? fmt.rupiah(item.amount) : '' }),
    ],
  ));
}

function openItemsCard(title, items, cells) {
  return el('.card', [
    el('.card-head', [el('h2', { text: `${title} (${items.length})` })]),
    items.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Tanggal' }), el('th', { text: 'Keterangan' }),
          el('th.right', { text: 'Debit' }), el('th.right', { text: 'Kredit' }),
        ])),
        el('tbody', items.map((item) => el('tr', cells(item)))),
      ]))
      : el('.card-body', el('p.muted', { text: 'Tidak ada.', style: { margin: 0 } })),
  ]);
}

function mismatchCard(rows) {
  return el('.card', [
    el('.card-head', [
      el('h2', { text: `Periksa — kemungkinan salah catat (${rows.length})` }),
      el('.spacer'),
      badge('Petunjuk', 'amber'),
    ]),
    el('.alert.warn', [
      icon('warn', 15),
      el('div', { text: 'Dua pos terbuka berlawanan arah dengan nilai hampir sama biasanya berarti satu '
        + 'peristiwa yang salah dicatat nilainya. Keduanya saling meniadakan sehingga jembatan tetap '
        + 'tertutup — selisihnya tidak akan muncul di baris mana pun.' }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Rekening koran' }), el('th.right', { text: 'Nilai bank' }),
        el('th', { text: 'Jurnal' }), el('th.right', { text: 'Nilai buku' }), el('th.right', { text: 'Selisih' }),
      ])),
      el('tbody', rows.map((row) => el('tr', [
        el('td', { text: fmt.date(row.statement_date) }),
        el('td.right.num', { text: fmt.rupiah(row.statement_amount) }),
        el('td.code', { text: `${row.journal_code} · ${fmt.date(row.journal_date)}` }),
        el('td.right.num', { text: fmt.rupiah(row.journal_amount) }),
        el('td.right.num', { text: fmt.rupiah(row.difference), style: { color: 'var(--danger)' } }),
      ]))),
    ])),
  ]);
}

/* ------------------------------------------------------------------- shell */

export async function renderBankRecon(host) {
  clear(host);

  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke rekonsiliasi bank.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Rekonsiliasi Bank' }),
      el('.desc', { text: 'Impor rekening koran, cocokkan mutasinya dengan pembayaran dan jurnal yang '
        + 'sudah diposting, lalu baca selisihnya. Layar ini tidak pernah membuat jurnal.' }),
    ]),
  ]));

  const tabs = el('.tabs');
  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(tabs, controls, body);

  let accounts = [];

  try {
    accounts = rows(await api.get('finance/bank-accounts', { per_page: 100 }));
  } catch (error) {
    host.appendChild(errorState(error, () => renderBankRecon(host)));
    return;
  }

  if (!accounts.length) {
    body.appendChild(el('.alert.warn', 'Belum ada rekening bank. Buat dulu di Keuangan › Rekening Bank.'));
    return;
  }

  if (!accounts.some((a) => a.id === state.bankAccountId)) state.bankAccountId = accounts[0].id;

  const accountSelect = el('select.filter-w', {
    onchange: (event) => { state.bankAccountId = Number(event.target.value); state.statementId = null; load(); },
  });
  accounts.forEach((a) => accountSelect.appendChild(el('option', { value: a.id, text: `${a.name} — ${a.account_no}` })));
  accountSelect.value = String(state.bankAccountId);

  const asOfInput = el('input.filter-w', {
    type: 'date', value: state.asOf, 'aria-label': 'Sampai tanggal',
    onchange: (event) => { state.asOf = event.target.value; load(); },
  });

  controls.append(accountSelect, asOfInput, el('.spacer'), button('Muat ulang', {
    size: 'sm', variant: 'ghost', iconName: 'refresh', onClick: () => load(),
  }));

  function paintTabs() {
    clear(tabs);
    TABS.forEach((tab) => tabs.appendChild(el(`button${tab.key === state.tab ? '.active' : ''}`, {
      text: tab.label,
      onclick: () => { if (state.tab === tab.key) return; state.tab = tab.key; paintTabs(); load(); },
    })));
  }

  async function load() {
    clear(body);
    // The overview covers every account, so the account picker would mislead;
    // the as-of date still applies, so the row is kept and only the picker hides.
    controls.style.display = state.tab === 'import' ? 'none' : '';
    accountSelect.style.display = state.tab === 'overview' ? 'none' : '';
    body.appendChild(skeletonTable(6, 5));

    try {
      if (state.tab === 'import') {
        clear(body);
        renderImport(body, accounts, () => { state.tab = 'statements'; paintTabs(); load(); });
        return;
      }

      if (state.tab === 'overview') {
        const overview = await api.get('finance/reports/bank-reconciliation-overview', {
          as_of: state.asOf || undefined,
        });
        clear(body);
        renderOverview(body, overview, (id) => {
          state.bankAccountId = id;
          state.tab = 'reconcile';
          accountSelect.value = String(id);
          paintTabs();
          load();
        });
        return;
      }

      if (state.tab === 'statements') {
        const statements = rows(await api.get('finance/bank-statements', {
          bank_account_id: state.bankAccountId, per_page: 100,
        }));
        const active = statements.find((s) => s.id === state.statementId) || statements[0];
        let detail = null;
        let suggestions = {};

        if (active) {
          [detail, suggestions] = await Promise.all([
            api.get(`finance/bank-statements/${active.id}`),
            api.get(`finance/bank-statements/${active.id}/suggestions`),
          ]);
        }

        clear(body);
        renderStatements(
          body,
          statements.map((s) => (detail && s.id === detail.id ? { ...s, ...detail } : s)),
          suggestions || {},
          load,
        );
        return;
      }

      const report = await api.get('finance/reports/bank-reconciliation', {
        bank_account_id: state.bankAccountId,
        as_of: state.asOf || undefined,
      });
      clear(body);
      renderReconciliation(body, report);
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
    }
  }

  paintTabs();
  await load();
}
