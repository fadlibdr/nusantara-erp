/* Kalender Pajak — register kewajiban masa (PPh 21, PPh 23, PPh final 4(2), PPN).
 *
 * Satu baris per (jenis, masa) dengan tenggat setor menurut aturan yang SAMA
 * dengan proyeksi kas (TaxDeadlines di server): PPh tanggal 10 bulan
 * berikutnya, PPN akhir bulan berikutnya. Status disetor/dilapor adalah entri
 * MANUAL — NTPN diketik dari SSP/BPN asli, tidak ada integrasi e-filing, dan
 * tautan JV hanyalah referensi yang dipilih operator. Baris masa dicetak
 * idempoten per tahun lewat tombol "Lengkapi kalender". */

import { api, session } from '../api.js';
import { el, clear, button, badge, field, modal, errorState, skeletonTable, toast, toastError } from '../ui.js';
import { moneyInput } from '../money.js';
import { combobox } from '../combobox.js';
import { loadSource, optionsFor, labelFor } from '../lookup.js';
import { openPrintable } from '../print.js';
import * as fmt from '../format.js';

const state = { year: new Date().getFullYear() };

function statusBadge(row) {
  if (row.status === 'dilapor') return badge('Dilapor', 'green');
  if (row.status === 'disetor') return badge('Disetor', 'blue');

  const today = new Date().toISOString().slice(0, 10);
  return row.due_date < today ? badge('Lewat tenggat', 'red') : badge('Belum disetor', 'amber');
}

function dueCell(row) {
  const today = new Date().toISOString().slice(0, 10);
  const days = Math.round((new Date(row.due_date) - new Date(today)) / 86400000);
  const late = row.status === 'belum' && row.due_date < today;

  return el('td', [
    el('div', { text: fmt.date(row.due_date) }),
    el('.muted', {
      style: { fontSize: '12px', color: late ? 'var(--danger)' : undefined },
      text: late ? `${Math.abs(days)} hari lalu` : (days === 0 ? 'hari ini' : `${days} hari lagi`),
    }),
  ]);
}

/* Modal "Catat setor/lapor": input DIPRAISI dengan nilai baris — payload yang
 * dikirim selalu lengkap, jadi menyimpan tanpa mengubah apa pun tidak akan
 * mengosongkan NTPN yang sudah tercatat (PUT-nya mengganti seluruh kolom
 * manual). promptFields tidak dipakai persis karena ia selalu mulai kosong. */
function recordModal(row, onSaved) {
  const amount = moneyInput({ value: row.amount });
  const ntpn = el('input', { type: 'text', value: row.ntpn || '', maxLength: 30 });
  const disetor = el('input', { type: 'date', value: row.disetor_date || '' });
  const dilapor = el('input', { type: 'date', value: row.dilapor_date || '' });
  const notes = el('input', { type: 'text', value: row.notes || '', maxLength: 500 });

  let journalId = row.journal_id ?? null;
  const journal = combobox({
    value: journalId,
    label: journalId !== null ? (labelFor('journals', journalId) || `#${journalId}`) : null,
    options: [],
    placeholder: 'Memuat…',
    allowEmpty: true,
  });
  loadSource('journals').then((rows) => {
    journal.setOptions(optionsFor('journals', rows), {
      label: journalId !== null ? (labelFor('journals', journalId) || `#${journalId}`) : null,
      placeholder: 'Cari JV…',
    });
  }).catch(() => journal.setOptions([], { placeholder: 'Gagal memuat jurnal' }));

  const grid = el('.form-grid', [
    field('Jumlah disetor (SSP)', amount.node, {
      help: 'Nihil? Biarkan kosong/nol — masa nihil boleh langsung dicatat dilapor.',
    }),
    field('NTPN', ntpn, { help: 'Wajib saat mencatat tanggal setor — nomor bukti dari SSP/BPN.' }),
    field('Tanggal disetor', disetor),
    field('Tanggal dilapor', dilapor),
    field('JV penyetoran (opsional)', journal.node, {
      help: 'Referensi jurnal yang menyetorkan masa ini — dipilih manual, tidak ada yang otomatis.',
    }),
    field('Catatan', notes),
  ]);

  const save = button('Simpan', { variant: 'primary' });
  const dialog = modal({
    title: `Catat ${row.name}`,
    width: 'narrow',
    body: grid,
    footer: [button('Batal', { onClick: () => dialog.close() }), save],
  });

  save.addEventListener('click', async () => {
    try {
      const picked = journal.read();
      await api.put(`finance/tax-obligations/${row.id}`, {
        amount: amount.read(),
        ntpn: ntpn.value.trim() || null,
        disetor_date: disetor.value || null,
        dilapor_date: dilapor.value || null,
        journal_id: picked === null || picked === '' ? null : Number(picked),
        notes: notes.value.trim() || null,
      });
      toast(`${row.name} tercatat.`);
      dialog.close();
      onSaved();
    } catch (error) {
      toastError(error);
    }
  });
}

function obligationsTable(rows, reload) {
  if (!rows.length) {
    return el('.card-body', el('p.muted', {
      style: { margin: 0 },
      text: 'Belum ada baris masa untuk tahun ini — tekan "Lengkapi kalender" untuk mencetaknya.',
    }));
  }

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', [
      el('th', { text: 'Kewajiban' }),
      el('th', { text: 'Jatuh tempo setor' }),
      el('th.right', { text: 'Jumlah' }),
      el('th', { text: 'NTPN' }),
      el('th', { text: 'Disetor' }),
      el('th', { text: 'Dilapor' }),
      el('th', { text: 'JV' }),
      el('th', { text: 'Status' }),
      el('th', { text: '' }),
    ])),
    el('tbody', rows.map((row) => el('tr', [
      el('td', [el('div', { text: row.tax_type_label }), el('.muted', { style: { fontSize: '12px' }, text: row.name })]),
      dueCell(row),
      el('td.right.num', { text: row.amount === null ? '—' : fmt.rupiah(row.amount) }),
      el('td.code', { text: row.ntpn || '—' }),
      el('td', { text: row.disetor_date ? fmt.date(row.disetor_date) : '—' }),
      el('td', { text: row.dilapor_date ? fmt.date(row.dilapor_date) : '—' }),
      el('td.code', { text: row.journal ? row.journal.code : '—' }),
      el('td', statusBadge(row)),
      el('td', session.can('fin.update')
        ? button('Catat', { size: 'sm', onClick: () => recordModal(row, reload) })
        : null),
    ]))),
  ]));
}

function summaryTiles(rows) {
  const today = new Date().toISOString().slice(0, 10);
  const late = rows.filter((r) => r.status === 'belum' && r.due_date < today).length;
  const open = rows.filter((r) => r.status === 'belum').length;
  const reported = rows.filter((r) => r.status === 'dilapor').length;

  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Lewat tenggat setor' }),
      el('.value', { text: String(late), style: late ? { color: 'var(--danger)' } : {} }),
      el('.delta', { text: late ? 'perlu tindakan' : 'tidak ada' }),
    ]),
    el('.stat', [el('.label', { text: 'Belum disetor' }), el('.value', { text: String(open) })]),
    el('.stat', [el('.label', { text: 'Sudah dilapor' }), el('.value', { text: String(reported) })]),
  ]);
}

export async function renderKalenderPajak(host) {
  clear(host);

  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke kalender pajak.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Kalender Pajak' }),
      el('.desc', {
        text: 'Kewajiban masa PPh & PPN dengan tenggat setor menurut aturan yang sama '
          + 'dengan proyeksi kas. Status dicatat manual dari SSP/BPN — tidak ada integrasi e-filing.',
      }),
    ]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(controls, body);

  const yearInput = el('input.filter-w', {
    type: 'number', value: state.year, min: 2000, max: 2100, 'aria-label': 'Tahun kalender pajak',
  });
  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value); load(); });
  controls.appendChild(yearInput);

  if (session.can('fin.create')) {
    controls.appendChild(button('Lengkapi kalender', {
      variant: 'primary',
      onClick: async () => {
        try {
          const result = await api.post('finance/tax-obligations/generate', { year: state.year });
          toast(result.message || 'Kalender dicetak.');
          await load();
        } catch (error) {
          toastError(error);
        }
      },
    }));
  }

  /* Register setahun dalam format formulir rumah (Form F/KP, lanskap).

     Sheet-nya menjangkarkan diri pada SATU baris — server membaca tahunnya
     dari baris itu lalu mencetak seluruh masa tahun tersebut — jadi tombolnya
     baru bisa hidup setelah muatan pertama tiba. Selama tahun yang dipilih
     belum punya satu baris pun, tidak ada yang bisa dicetak dan tombolnya
     mengatakan alasannya, bukan diam. */
  const printAnchor = { id: null };
  const printButton = button('Cetak Register', {
    iconName: 'print',
    disabled: true,
    title: 'Muat kalender terlebih dahulu',
    onClick: (event) => openPrintable(`core/print/forms/kewajiban-pajak/${printAnchor.id}`, event.currentTarget),
  });
  controls.appendChild(printButton);

  async function load() {
    clear(body);
    body.appendChild(skeletonTable(8, 8));
    printButton.disabled = true;
    try {
      // 4 jenis x 12 masa = 48 baris per tahun; satu halaman cukup selamanya.
      const payload = await api.get('finance/tax-obligations', { year: state.year, per_page: 60 });
      const rows = payload.data || payload;
      printAnchor.id = rows.length ? rows[0].id : null;
      printButton.disabled = !printAnchor.id;
      printButton.title = printAnchor.id
        ? `Cetak register kewajiban masa ${state.year} dalam format formulir perusahaan`
        : `Belum ada baris masa ${state.year} untuk dicetak`;
      clear(body);
      body.appendChild(summaryTiles(rows));
      body.appendChild(el('.card', [
        el('.card-head', [el('h2', { text: `Kewajiban masa ${state.year}` })]),
        obligationsTable(rows, load),
      ]));
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
    }
  }

  await load();
}
