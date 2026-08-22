/* Buku besar — baris jurnal di balik satu saldo akun.

   Neraca saldo menjawab "berapa", layar ini menjawab "dari mana". Sebelum ada
   layar ini pertanyaan kedua tidak punya jawaban di dalam aplikasi: Persediaan
   Material 1-1400 tertulis Rp 332.510.000 di neraca saldo Juli dan tidak ada
   satu pun halaman yang menunjukkan bahwa angka itu adalah penerimaan
   Rp 351.250.000 (GRN/2026/VII/0001) dikurangi pemakaian Rp 18.740.000
   (ISS/2026/VII/0001). Akuntan yang diminta menjelaskan sebuah saldo harus
   membuka basis data.

   Angka yang membuatnya layak dipercaya: saldo akhir di sini SAMA PERSIS
   dengan saldo akhir akun tersebut di Neraca Saldo untuk bulan yang sama —
   perinciannya dan ringkasannya membaca jurnal yang sama dengan cara yang
   sama. Kalau keduanya pernah berbeda, yang salah adalah salah satunya, dan
   itulah yang dikunci oleh GeneralLedgerTest.

   Saldo berjalan mengikuti SISI NORMAL akun: 5-1100 Beban Material naik pada
   debit, 2-1300 PPN Keluaran naik pada kredit. Jadi "positif" di kolom saldo
   berarti "wajar", bukan "debit". */

import { api, session } from '../api.js';
// append() dari ui.js, bukan Element.append() bawaan DOM: yang bawaan
// mengubah null menjadi teks "null" di layar, sedangkan beberapa blok di
// bawah ini memang null kalau kondisinya tidak berlaku.
import { el, append, clear, button, icon, errorState, skeletonTable, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';
import { combobox } from '../combobox.js';
import { loadSource, optionsFor, sourceState, noticeFor } from '../lookup.js';
import { toCsv, downloadCsv, csvValue } from '../csv.js';
import { navigate } from '../router.js';

/* Sejalan dengan GeneralLedgerService::DEFAULT_PER_PAGE. Buku besar bank di
   tahun yang sibuk berisi puluhan ribu baris; layar ini tidak pernah meminta
   semuanya sekaligus. */
const PER_PAGE = 100;

/* Ekspor menelusuri halaman seperti list.js — ukuran halaman penelusuran sama
   dengan plafon server (GeneralLedgerService::MAX_PER_PAGE), dan plafon
   barisnya sama dengan plafon list.js supaya "terlalu besar untuk diekspor"
   berarti hal yang sama di seluruh aplikasi. */
const EXPORT_PAGE = 500;
const EXPORT_CEILING = 10000;

const state = {
  accountId: null,
  accountLabel: '',
  from: '',
  to: '',
  projectId: '',
  page: 1,
};

/** Angka mentah berkoma desimal untuk Excel-ID, sama seperti reports.js. */
const num = (value) => csvValue({ value }, { key: 'value', type: 'number' });

/** Sel uang: nol dicetak '—' supaya kolom debit pada baris kredit tidak ramai. */
function moneyCell(value) {
  return el('td.right.num', { text: Number(value) === 0 ? '—' : fmt.rupiah(value) });
}

function defaultPeriod() {
  if (state.from && state.to) return;
  const now = new Date();
  state.from = fmt.toDateInput(new Date(now.getFullYear(), now.getMonth(), 1));
  state.to = fmt.toDateInput(now);
}

/* ------------------------------------------------------------- kontrol */

/*
 * Pemilih akun memakai SELURUH bagan akun, bukan hanya yang postable.
 * Justru akun yang ditandai "tidak dapat diposting" padahal sudah berjurnal
 * yang paling perlu dibuka di sini: ia hilang dari neraca saldo dan neraca,
 * dan tidak ada layar lain yang menunjukkan bahwa barisnya masih ada.
 */
async function accountPicker(onPick) {
  const rows = await loadSource('accounts').catch(() => []);
  const options = optionsFor('accounts', rows);

  const combo = combobox({
    value: state.accountId,
    label: state.accountLabel,
    options,
    placeholder: 'Ketik kode atau nama akun…',
    notice: noticeFor(sourceState('accounts')),
  });

  combo.input.setAttribute('aria-label', 'Akun');
  combo.node.style.minWidth = '260px';

  combo.input.addEventListener('change', () => {
    const picked = combo.read();
    state.accountId = picked;
    state.accountLabel = picked === null
      ? ''
      : ((options.find((option) => String(option.value) === String(picked)) || {}).label || '');
    state.page = 1;
    onPick();
  });

  return combo.node;
}

function dateInput(label, value, onChange) {
  const input = el('input.filter-w', { type: 'date', value, title: label, 'aria-label': label });
  input.addEventListener('change', () => onChange(input.value));
  return input;
}

async function projectSelect(onChange) {
  const select = el('select.filter-w', { 'aria-label': 'Proyek' });
  select.appendChild(el('option', { value: '', text: 'Semua proyek' }));
  const rows = await loadSource('projects').catch(() => []);
  optionsFor('projects', rows).forEach((option) =>
    select.appendChild(el('option', { value: option.value, text: option.label })));
  select.value = state.projectId || '';
  select.addEventListener('change', () => onChange(select.value));
  return select;
}

/* --------------------------------------------------------------- ekspor */

function params(page, perPage) {
  return {
    account_id: state.accountId,
    from: state.from,
    to: state.to,
    project_id: state.projectId || undefined,
    page,
    per_page: perPage,
  };
}

/*
 * Berkas yang diserahkan ke KAP harus berisi SELURUH periode, bukan halaman
 * yang kebetulan sedang dibuka — jadi ekspor menelusuri halaman, persis pola
 * list.js. Baris "Saldo awal" dan "Saldo akhir" ikut ditulis: tanpa keduanya
 * kolom saldo berjalan tidak bisa direkonsiliasi dengan neraca saldo.
 */
function csvButton(report) {
  return button('Unduh CSV', {
    size: 'sm',
    variant: 'ghost',
    iconName: 'download',
    disabled: report.pagination.total === 0,
    title: report.pagination.total === 0 ? 'Tidak ada baris untuk diekspor' : 'Unduh buku besar periode ini (semua halaman)',
    onClick: async (event) => {
      if (report.pagination.total > EXPORT_CEILING) {
        toast(`Terlalu banyak baris (${report.pagination.total.toLocaleString('id-ID')}). Persempit rentang tanggalnya dulu.`, { tone: 'err' });
        return;
      }

      try {
        await withBusy(event.currentTarget, async () => {
          /*
           * Parameter DIBEKUKAN saat tombol ditekan, dan angka kepalanya
           * diambil dari halaman pertama yang benar-benar terambil — bukan
           * dari payload yang sedang tampil. Penelusuran memakan beberapa
           * permintaan sementara bilah saringnya tetap hidup; membaca state
           * per halaman menjahit dua pertanyaan berbeda menjadi satu berkas,
           * dan saldo awal/akhirnya lalu tidak menjelaskan baris-barisnya.
           */
          const frozen = params(1, EXPORT_PAGE);
          const rows = [];
          let head = null;
          let page = 1;
          let lastPage = 1;
          do {
            const batch = await api.get('finance/reports/general-ledger', { ...frozen, page });
            head = head || batch;
            rows.push(...batch.rows);
            lastPage = batch.pagination.last_page;
            page += 1;
          } while (page <= lastPage);

          downloadCsv(
            `buku-besar_${head.account.code}_${head.from}_${head.to}.csv`,
            toCsv(
              ['tanggal', 'jurnal', 'keterangan', 'dokumen', 'proyek', 'debit', 'kredit', 'saldo'],
              [
                ['', '', 'Saldo awal', '', '', '', '', num(head.opening)],
                ...rows.map((row) => [
                  row.journal_date, row.journal_code, row.description || '',
                  [row.reference_label, row.reference_id].filter(Boolean).join(' #'),
                  row.project_code || '',
                  num(row.debit), num(row.credit), num(row.balance),
                ]),
                ['', '', 'Saldo akhir', '', '', num(head.movement.debit), num(head.movement.credit), num(head.closing)],
              ],
            ),
          );
        });
      } catch (error) {
        toastError(error);
      }
    },
  });
}

/* ----------------------------------------------------------------- badan */

function summary(report) {
  const sisi = report.account.normal_balance === 'credit' ? 'kredit' : 'debit';

  return el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Saldo awal' }),
      el('.value.sm', { text: fmt.rupiah(report.opening) }),
      el('.delta', { text: `per ${fmt.date(report.from)}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Mutasi debit' }),
      el('.value.sm', { text: fmt.rupiah(report.movement.debit) }),
    ]),
    el('.stat', [
      el('.label', { text: 'Mutasi kredit' }),
      el('.value.sm', { text: fmt.rupiah(report.movement.credit) }),
    ]),
    el('.stat', [
      el('.label', { text: 'Saldo akhir' }),
      el('.value.sm', {
        text: fmt.rupiah(report.closing),
        style: { color: Number(report.closing) < 0 ? 'var(--danger)' : 'inherit' },
      }),
      // Saldo minus pada sisi normalnya sendiri adalah anomali yang layak
      // dilihat (bank bersaldo kredit, beban bersaldo kredit), bukan sekadar
      // tanda kurang.
      el('.delta', { text: `saldo normal ${sisi}` }),
    ]),
  ]);
}

function ledgerTable(report, reload) {
  const rows = report.rows;
  const carried = report.pagination.current_page > 1;

  if (!rows.length) {
    return el('.card', [
      el('.card-head', el('h2', { text: 'Rincian baris jurnal' })),
      el('.card-body', el('p.muted', {
        text: 'Tidak ada baris jurnal terposting pada rentang ini. Jurnal yang masih draf memang tidak pernah muncul di buku besar.',
        style: { margin: 0 },
      })),
    ]);
  }

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Rincian baris jurnal' }),
      el('.spacer'),
      el('.cell-sub', { text: `${report.pagination.total.toLocaleString('id-ID')} baris` }),
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Tanggal' }),
        el('th', { text: 'Jurnal' }),
        el('th', { text: 'Keterangan' }),
        el('th', { text: 'Dokumen' }),
        el('th', { text: 'Proyek' }),
        el('th.right', { text: 'Debit' }),
        el('th.right', { text: 'Kredit' }),
        el('th.right', { text: 'Saldo' }),
      ])),
      el('tbody', [
        el('tr', { style: { background: 'var(--surface-2)' } }, [
          el('td', { text: carried ? 'Saldo pindahan' : 'Saldo awal', colspan: 5, style: { fontWeight: '600' } }),
          el('td.right.num', { text: '—' }),
          el('td.right.num', { text: '—' }),
          el('td.right.num.strong', { text: fmt.rupiah(report.page_opening) }),
        ]),
        ...rows.map((row) => {
          const tr = el('tr', { style: { cursor: 'pointer' }, title: `Buka ${row.journal_code}` }, [
            el('td', { text: fmt.date(row.journal_date) }),
            el('td.code', { text: row.journal_code }),
            el('td', { text: row.description || '—' }),
            el('td', row.reference_label
              ? el('span', [
                el('span.cell-main', { text: row.reference_label }),
                row.reference_id ? el('span.cell-sub', { text: `#${row.reference_id}` }) : null,
              ])
              : el('span.muted', { text: '—' })),
            el('td', row.project_code
              ? el('span', [
                el('span.cell-main.mono', { text: row.project_code }),
                el('span.cell-sub', { text: row.project_name || '' }),
              ])
              : el('span.muted', { text: '—' })),
            moneyCell(row.debit),
            moneyCell(row.credit),
            el('td.right.num.strong', { text: fmt.rupiah(row.balance) }),
          ]);

          // Setiap baris membuka jurnalnya sendiri — di situlah ayat jurnal
          // lengkapnya (lawan debit/kreditnya) terbaca. Buku besar yang
          // berhenti pada satu sisi ayat memaksa pembacanya menebak sisi lain.
          tr.addEventListener('click', () => navigate(`d/finance/journals/${row.journal_id}`));

          return tr;
        }),
      ]),
      el('tfoot', el('tr', [
        el('td', { text: `Mutasi ${fmt.date(report.from)} – ${fmt.date(report.to)}`, colspan: 5 }),
        moneyCell(report.movement.debit),
        moneyCell(report.movement.credit),
        el('td.right.num.strong', { text: fmt.rupiah(report.closing) }),
      ])),
    ])),
    report.pagination.last_page > 1 ? pager(report, reload) : null,
  ]);
}

function pager(report, reload) {
  const meta = report.pagination;
  const first = (meta.current_page - 1) * meta.per_page + 1;
  const last = Math.min(meta.current_page * meta.per_page, meta.total);

  return el('.pager', [
    el('span', { text: `Menampilkan ${first}–${last} dari ${meta.total.toLocaleString('id-ID')} baris` }),
    el('.spacer'),
    button('', {
      size: 'sm', iconName: 'back', title: 'Sebelumnya',
      disabled: meta.current_page <= 1,
      onClick: () => { state.page = meta.current_page - 1; reload(); },
    }),
    el('span.num', { text: `${meta.current_page} / ${meta.last_page}` }),
    button('', {
      size: 'sm', iconName: 'chevronRight', title: 'Berikutnya',
      disabled: meta.current_page >= meta.last_page,
      onClick: () => { state.page = meta.current_page + 1; reload(); },
    }),
  ]);
}

function howToRead() {
  return el('.card', [
    el('.card-head', el('h2', { text: 'Cara membaca layar ini' })),
    el('.card-body', [
      el('p', { text: 'Buku besar menampilkan setiap baris jurnal TERPOSTING yang menyentuh satu akun, berurutan menurut tanggal, dengan saldo berjalan di kolom terakhir. Jurnal yang masih draf dan jurnal yang sudah dihapus tidak pernah muncul di sini — sama seperti di neraca saldo.' }),
      el('p', { text: 'Saldo akhir di bawah kolom saldo sama persis dengan saldo akhir akun ini di Neraca Saldo untuk bulan yang sama. Kalau Anda memeriksa satu bulan penuh (tanggal 1 sampai akhir bulan), kedua angka itu wajib bertemu; itulah gunanya layar ini sebagai penelusuran.' }),
      el('p', { text: 'Kolom saldo mengikuti sisi normal akun: akun beban dan aset naik pada debit, akun kewajiban, ekuitas, dan pendapatan naik pada kredit. Karena itu saldo bernilai positif berarti wajar, dan saldo negatif berarti akunnya berada di sisi yang berlawanan dengan sifatnya — hal yang layak ditelusuri.' }),
      el('p', { text: 'Saringan proyek mempersempit baris DAN saldo awalnya, jadi kolom saldo tetap konsisten. Angkanya lalu menjadi buku besar proyek tersebut, bukan lagi angka yang muncul di neraca saldo perusahaan.' }),
      el('p', { text: 'Klik baris mana pun untuk membuka jurnalnya dan melihat ayat lengkapnya.' }),
    ]),
  ]);
}

/* --------------------------------------------------------------- renderer */

async function render(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Buku Besar' }),
      el('.desc', {
        text: 'Rincian baris jurnal di balik saldo satu akun, lengkap dengan saldo berjalan. '
          + 'Saldo akhirnya sama persis dengan Neraca Saldo periode yang sama.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() })]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(controls, body);
  body.appendChild(skeletonTable(6, 8));

  const reload = () => render(host);

  try {
    controls.append(
      await accountPicker(reload),
      dateInput('Dari', state.from, (value) => { state.from = value; state.page = 1; reload(); }),
      dateInput('Sampai', state.to, (value) => { state.to = value; state.page = 1; reload(); }),
      await projectSelect((value) => { state.projectId = value; state.page = 1; reload(); }),
    );

    if (!state.accountId) {
      append(clear(body), [
        el('.alert.info', 'Pilih akun untuk melihat buku besarnya — ketik kode (mis. 1-1400) atau namanya.'),
        howToRead(),
      ]);
      return;
    }

    const report = await api.get('finance/reports/general-ledger', params(state.page, PER_PAGE));
    controls.appendChild(csvButton(report));

    append(clear(body), [
      el('.card', el('.card-body', el('div', {
        style: { display: 'flex', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap' },
      }, [
        el('div', [
          el('h2', { text: `${report.account.code} — ${report.account.name}`, style: { margin: 0 } }),
          el('.cell-sub', { text: `${report.account.account_type_label} · saldo normal ${report.account.normal_balance === 'credit' ? 'kredit' : 'debit'}` }),
        ]),
        el('.cell-sub', { text: `Periode ${fmt.date(report.from)} – ${fmt.date(report.to)}` }),
      ]))),
      // Temuan audit T1/T41: akun berjurnal yang ditandai "tidak dapat
      // diposting" lenyap dari neraca saldo dan neraca — dan tidak ada layar
      // yang menunjuk akun mana penyebabnya. Buku besar tetap memegang
      // barisnya, jadi di sinilah peringatan itu berguna.
      report.account.is_postable ? null : el('.alert.warn', [
        icon('warn', 15),
        el('div', {
          text: `Akun ${report.account.code} ditandai TIDAK DAPAT DIPOSTING. Buku besar ini masih memegang barisnya, `
            + 'tetapi neraca saldo, neraca, dan laba rugi hanya menghitung akun yang dapat diposting — '
            + 'jadi saldo di bawah ini tidak muncul di laporan mana pun sampai tandanya dikembalikan.',
        }),
      ]),
      state.projectId ? el('.alert.info', [
        icon('warn', 15),
        el('div', {
          text: 'Disaring per proyek: saldo awal dan saldo akhir di bawah hanya mencakup baris proyek tersebut, '
            + 'sehingga tidak sama dengan angka akun ini di neraca saldo perusahaan.',
        }),
      ]) : null,
      summary(report),
      ledgerTable(report, reload),
      howToRead(),
    ]);
  } catch (error) {
    clear(body).appendChild(errorState(error, reload));
  }
}

export async function renderBukuBesar(host) {
  if (!session.can('fin.view')) {
    clear(host);
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke buku besar.'));
    return;
  }

  defaultPeriod();
  await render(host);
}
