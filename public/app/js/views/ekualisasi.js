/* Ekualisasi Pajak — kertas kerja rekonsiliasi buku vs SPT per tahun fiskal
 * (PPN keluaran, PPN masukan, PPh 21, PPh dipotong).
 *
 * SELURUH angka datang dari GET finance/tax-equalization — layar ini tidak
 * menghitung apa pun sendiri, karena dua aritmetika (layar dan server) adalah
 * dua jawaban berbeda untuk pertanyaan yang sama di depan pemeriksa pajak.
 * Baris residu ("selisih belum terjelaskan") SELALU dirender saat server
 * mengirimnya: nol dirender sebagai pernyataan positif ("Rp 0 — teruji"),
 * bukan dikosongkan — residu yang diam-diam nol adalah kertas kerja palsu,
 * dan justru kejujuran itulah nilai layar ini. Tahun tanpa data menampilkan
 * peringatan "Tidak ada …" dari server TANPA tabel, karena tabel kosong bisa
 * terbaca "tidak ada yang perlu direkonsiliasi" padahal faktanya "tidak ada
 * data".
 *
 * Divergensi PPN keluaran BUKAN kesalahan: pendapatan mengikuti kemajuan
 * (PSAK 115) sedangkan faktur pajak mengikuti termin — lembar ini menunjukkan
 * bahwa selisihnya persis pergerakan saldo kontrak, lewat baris kind:'derived'
 * yang membawa angkanya di kolom Selisih. */

import { api, session } from '../api.js';
import { el, clear, button, errorState, skeletonTable } from '../ui.js';
import { openPrintable } from '../print.js';
import * as fmt from '../format.js';

/* Default = tahun dari bulan yang BARU SAJA berakhir — pembacaan yang sama
 * dengan server (TaxEqualizationRequest): pada Januari yang direkonsiliasi
 * auditor adalah tahun LALU, dan default tahun berjalan akan merender empat
 * lembar yang lengkap hanya karena kebetulan. */
function defaultYear() {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth() - 1, 1).getFullYear();
}

const state = { year: defaultYear() };

/* null berarti kolom TIDAK BERLAKU untuk baris itu (dirender '—'); 0 adalah
 * nol tersimpan dan dirender Rp 0,00 — membedakan keduanya adalah inti lembar
 * ini. Dua desimal, karena membulatkan angka kertas kerja berarti layar dan
 * lembar cetak menyebut dua angka untuk satu fakta. */
function uang(value) {
  return value === null || value === undefined ? '—' : fmt.rupiah(value, { decimals: 2 });
}

function amountCell(value, tone) {
  return el('td.right.num', { style: tone, text: uang(value) });
}

function dataRow(row) {
  // Baris 'section' adalah sub-judul lembar dua panel (PPh dipotong) —
  // seluruh kolom angkanya null by design, jadi satu sel penuh, bukan empat
  // strip yang terbaca seperti data.
  if (row.kind === 'section') {
    return el('tr', el('td', {
      colspan: 4,
      style: { fontWeight: '600', background: 'var(--surface-2)' },
      text: row.label,
    }));
  }

  // 'warning' menuntut perhatian, 'info' adalah pengungkapan di luar
  // aritmetika — dibedakan lewat warna, bukan teks, supaya label tetap persis
  // label server dan bisa dicocokkan dengan lembar cetaknya.
  const tone = row.kind === 'warning'
    ? { color: 'var(--warning)' }
    : (row.kind === 'info' ? { color: 'var(--muted)' } : undefined);

  return el('tr', [
    el('td', { style: tone, text: row.label }),
    amountCell(row.buku, tone),
    amountCell(row.spt, tone),
    amountCell(row.selisih, tone),
  ]);
}

/* Baris residu: satu-satunya baris tebal di tabel. Nol dirender "Rp 0 —
 * teruji" (pernyataan positif hasil rekonsiliasi, bukan sel kosong); selain
 * nol adalah TEMUAN dan tampil merah lengkap dengan tandanya — residu yang
 * tandanya terbalik lebih buruk daripada tidak ada, jadi tidak ada abs() di
 * sini. */
function residualRow(residual) {
  const zero = residual.amount === 0;
  return el('tr.total-row', [
    el('td', { text: residual.label }),
    el('td.right.num', { text: '—' }),
    el('td.right.num', { text: '—' }),
    el('td.right.num', {
      style: { color: zero ? 'var(--success)' : 'var(--danger)' },
      text: zero ? 'Rp 0 — teruji' : uang(residual.amount),
    }),
  ]);
}

function worksheetTable(ws) {
  const rows = [];
  let residualPlaced = false;

  ws.rows.forEach((row) => {
    /* Residu milik panel A (dipotong perusahaan): dirender saat aritmetika
       panel A selesai, SEBELUM seksi panel B — dipisah lewat kunci `panel`
       dari server, bukan parsing label. Lembar tanpa panel jatuh ke append
       di bawah. */
    if (!residualPlaced && ws.residual !== null && row.panel === 'dipotong_pelanggan') {
      rows.push(residualRow(ws.residual));
      residualPlaced = true;
    }
    rows.push(dataRow(row));
  });

  if (ws.residual !== null && !residualPlaced) rows.push(residualRow(ws.residual));

  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', [
      el('th', { text: 'Uraian' }),
      el('th.right', { text: 'Menurut buku (Rp)' }),
      el('th.right', { text: 'Menurut SPT/faktur (Rp)' }),
      el('th.right', { text: 'Selisih (Rp)' }),
    ])),
    el('tbody', rows),
  ]));
}

function worksheetCard(ws) {
  const children = [el('.card-head', [el('h2', { text: ws.title })])];

  // Peringatan sebagai strip alert DI ATAS tabel — termasuk "Tidak ada …"
  // tahun kosong, yang tampil tanpa tabel sama sekali.
  if (ws.warnings.length) {
    children.push(el('.card-body', { style: { paddingBottom: '0' } },
      ws.warnings.map((warning) => el('.alert.warn', warning))));
  }

  if (ws.rows.length || ws.residual !== null) children.push(worksheetTable(ws));

  return el('.card', children);
}

export async function renderEkualisasi(host) {
  clear(host);

  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke ekualisasi pajak.'));
    return;
  }

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Ekualisasi Pajak' }),
      el('.desc', {
        text: 'Kertas kerja buku vs SPT per tahun fiskal — PPN keluaran, PPN masukan, PPh 21, '
          + 'PPh dipotong. Semua angka diturunkan dari dokumen dan buku besar; selisih yang belum '
          + 'terjelaskan dicetak apa adanya, tidak pernah dipaksa nol.',
      }),
    ]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const body = el('div');
  host.append(controls, body);

  const yearInput = el('input.filter-w', {
    type: 'number', value: state.year, min: 2000, max: 2100, 'aria-label': 'Tahun ekualisasi pajak',
  });
  yearInput.addEventListener('change', () => { state.year = Number(yearInput.value); load(); });
  controls.appendChild(yearInput);

  /* Lembar F/EQ menjangkar pada SATU baris masa kalender pajak — pola yang
     sama dengan Register Kewajiban Pajak: server membaca tahun dari baris itu
     lalu mencetak seluruh ekualisasi tahunnya. Selama tahun terpilih belum
     punya baris masa, tidak ada jangkar — dan tombolnya mengatakan alasan
     itu, bukan diam. */
  const printAnchor = { id: null };
  const printButton = button('Cetak Ekualisasi', {
    iconName: 'print',
    disabled: true,
    title: 'Muat ekualisasi terlebih dahulu',
    onClick: (event) => openPrintable(`core/print/forms/ekualisasi-pajak/${printAnchor.id}`, event.currentTarget),
  });
  controls.appendChild(printButton);

  async function load() {
    clear(body);
    body.appendChild(skeletonTable(6, 4));
    printButton.disabled = true;
    try {
      const [payload, masa] = await Promise.all([
        api.get('finance/tax-equalization', { year: state.year }),
        // Satu baris masa mana pun dari tahun terpilih cukup sebagai jangkar.
        api.get('finance/tax-obligations', { year: state.year, per_page: 1 }),
      ]);
      const data = payload.data || payload;
      const masaRows = masa.data || masa;

      printAnchor.id = masaRows.length ? masaRows[0].id : null;
      printButton.disabled = !printAnchor.id;
      printButton.title = printAnchor.id
        ? `Cetak ekualisasi ${data.year} dalam format formulir perusahaan (Form F/EQ)`
        : `Belum ada baris masa ${state.year} di Kalender Pajak untuk dijadikan jangkar cetak — `
          + 'tekan "Lengkapi kalender" di layar Kalender Pajak dahulu';

      clear(body);
      data.worksheets.forEach((ws) => body.appendChild(worksheetCard(ws)));
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
    }
  }

  await load();
}
