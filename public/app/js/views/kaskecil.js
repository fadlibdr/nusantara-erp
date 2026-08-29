/* Kas kecil / kasbon — layar kasir, registrasi resource, dan dua layar khusus.
 *
 * Celah yang ditutup layar ini terbaca telanjang di buku besar demo: akun
 * 1-1100 Kas ada di bagan sejak hari pertama dan tidak pernah disentuh SATU
 * baris jurnal pun — nol dari 18 baris di seluruh ledger — padahal dua proyek
 * aktif (gedung 8 lantai Graha Sentosa, data center Bank Artha) belanja tunai
 * tiap hari: BBM survei, tol, konsumsi lembur, upah harian lepas. Uang keluar
 * dari kantong site manager, bonnya menumpuk di amplop, dan buku besar baru
 * tahu kalau ada yang sempat merekap manual — atau tidak tahu sama sekali.
 *
 * Layar kasir (route kas-kecil) melayani PEMEGANG LACI lebih dulu: posisi
 * laci hari ini (float − bon − kasbon = uang yang seharusnya ada di laci),
 * entri bon secepat menulis di amplop, pencairan/pertanggungjawaban kasbon,
 * dan serah-terima permintaan isi ulang ke alur Pembayaran ber-maker-checker.
 *
 * ASUMSI URUTAN ROUTER (jangan dilanggar oleh refactor): file ini diimpor dari
 * custom.js, sehingga badan modulnya berjalan SEBELUM app.js memanggil
 * registerRoutes() di boot(). Array route milik router.js dicocokkan urut
 * pendaftaran, jadi route d/… yang didaftarkan di sini menang atas wildcard
 * d/* milik app.js. Kalau app.js suatu saat mendaftarkan wildcard-nya pada
 * lingkup modul (bukan di boot()), urutan ini terbalik dan layar dana/kasbon
 * jatuh ke detail generik.
 *
 * Semua layar lain (daftar dana, daftar voucher, detail voucher, daftar
 * kasbon) menumpang mesin list/detail/actions generik lewat entri RESOURCES
 * di bawah — hanya dua layar yang butuh UI tulisan tangan: detail dana
 * (kartu saldo vs float + tombol isi ulang) dan detail kasbon (editor baris
 * pertanggungjawaban, yang tidak bisa diekspresikan promptFields).
 */

import { api, session } from '../api.js';
import {
  el, clear, button, badge, icon, errorState, emptyState, toast, toastError, withBusy,
  confirmDialog, field, skeletonTable,
} from '../ui.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor, preload, labelFor, SOURCES } from '../lookup.js';
import { ENUMS } from '../enums.js';
import { RESOURCES } from '../schema.js';
import { openForm, promptFields } from './form.js';
import { route, navigate, back } from '../router.js';

const IS_DRAFT = (row) => row.status === 'draft';

/* Endpoint daftar kas kecil BERHALAMAN — api.get membuka amplop { data } satu
   kali saja, jadi yang tiba di sini adalah objek paginator, bukan array.
   Membaca .length langsung darinya menggambar "belum ada data" di atas respons
   yang sebenarnya penuh (pola yang sama dengan rows() milik bankrecon.js). */
const rows = (payload) => (Array.isArray(payload) ? payload : (payload && payload.data) || []);

/* ------------------------------------------------------------ registrasi */

ENUMS.pettyCashCategory = [
  { value: 'material', label: 'Material' },
  { value: 'upah', label: 'Upah Harian' },
  { value: 'bbm_tol', label: 'BBM & Tol' },
  { value: 'konsumsi', label: 'Konsumsi' },
  { value: 'alat_bantu', label: 'Alat Bantu' },
  { value: 'lainnya', label: 'Lain-lain' },
];

ENUMS.pettyCashVoucherStatus = [
  { value: 'draft', label: 'Draf' },
  { value: 'posted', label: 'Terposting' },
  { value: 'cancelled', label: 'Dibatalkan' },
];

ENUMS.kasbonStatus = [
  { value: 'draft', label: 'Draf' },
  { value: 'issued', label: 'Berjalan' },
  { value: 'settled', label: 'Selesai' },
];

SOURCES.pettyCashFunds = { path: 'finance/petty-cash-funds', label: 'name', sub: 'code', title: 'Kas kecil' };

RESOURCES['finance/petty-cash-funds'] = {
  module: 'fin', api: 'finance/petty-cash-funds', label: 'Kas Kecil & Kasbon', labelOne: 'Kas Kecil',
  lookupSource: 'pettyCashFunds',
  columns: [
    { key: 'code', label: 'Kode', type: 'code', width: '1%' },
    { key: 'name', label: 'Nama', type: 'text' },
    { key: 'custodian.name', label: 'Pemegang', type: 'text' },
    { key: 'float_amount', label: 'Float', type: 'currency', align: 'right' },
    { key: 'balance', label: 'Saldo laci', type: 'currency', align: 'right' },
    { key: 'replenishment_due', label: 'Perlu diisi', type: 'currency', align: 'right', toneZero: 'green' },
    { key: 'is_active', label: 'Aktif', type: 'bool', align: 'center' },
  ],
  form: {
    sections: [{
      title: 'Kas kecil',
      help: 'Satu laci satu akun 1-11xx dan satu pemegang. Plafon per bon mengarahkan '
        + 'belanja besar ke jalur tagihan vendor yang berpersetujuan.',
      fields: [
        { key: 'code', label: 'Kode', type: 'text', required: true, help: 'mis. KK-HO, KK-GRAHA' },
        { key: 'name', label: 'Nama', type: 'text', required: true },
        {
          key: 'coa_account_id', label: 'Akun COA (1-11xx)', type: 'lookup', lookup: 'pettyCashAccounts', required: true,
          help: 'Buat akun anak di bawah 1-1100 Kas lewat Bagan Akun, satu per laci.',
        },
        { key: 'custodian_id', label: 'Pemegang (kasir)', type: 'lookup', lookup: 'users', required: true },
        { key: 'project_id', label: 'Proyek (site)', type: 'lookup', lookup: 'projects' },
        { key: 'float_amount', label: 'Dana tetap (float)', type: 'currency', required: true },
        { key: 'max_voucher_amount', label: 'Batas per bon', type: 'currency' },
        { key: 'max_kasbon_amount', label: 'Batas per kasbon', type: 'currency' },
        { key: 'is_active', label: 'Aktif', type: 'bool', default: true },
      ],
    }],
  },
};

RESOURCES['finance/petty-cash-vouchers'] = {
  module: 'fin', api: 'finance/petty-cash-vouchers', label: 'Voucher Kas Kecil', labelOne: 'Voucher',
  columns: [
    { key: 'code', label: 'Kode', type: 'code', width: '1%' },
    { key: 'voucher_date', label: 'Tanggal', type: 'date' },
    { key: 'fund.name', label: 'Kas kecil', type: 'text', sub: 'fund.code' },
    { key: 'category', label: 'Kategori', type: 'enum', enum: 'pettyCashCategory' },
    { key: 'description', label: 'Keterangan', type: 'text', truncate: 60 },
    { key: 'project_id', label: 'Proyek', type: 'rel', lookup: 'projects' },
    { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
    { key: 'status', label: 'Status', type: 'status', width: '1%' },
  ],
  filters: [
    { key: 'status', label: 'Status', enum: 'pettyCashVoucherStatus' },
    { key: 'fund_id', label: 'Kas kecil', lookup: 'pettyCashFunds' },
    { key: 'project_id', label: 'Proyek', lookup: 'projects' },
  ],
  editableWhen: IS_DRAFT,
  deletableWhen: IS_DRAFT,
  form: {
    sections: [{
      title: 'Voucher kas kecil (bon)',
      help: 'Uangnya sudah keluar dari laci — voucher ini pencatatannya. Bon berproyek '
        + 'langsung menjadi biaya proyek (HPP 5-xxxx) hari itu juga.',
      fields: [
        { key: 'fund_id', label: 'Kas kecil', type: 'lookup', lookup: 'pettyCashFunds', required: true, createOnly: true },
        { key: 'voucher_date', label: 'Tanggal bon', type: 'date', required: true, defaultToday: true },
        { key: 'category', label: 'Kategori', type: 'select', enum: 'pettyCashCategory', required: true },
        { key: 'amount', label: 'Jumlah', type: 'currency', required: true },
        { key: 'description', label: 'Keterangan', type: 'text', required: true, span: 2, help: 'mis. "BBM + tol survei site Graha Sentosa"' },
        {
          key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects',
          help: 'Isi untuk membebankan ke HPP proyek (5-xxxx); kosongkan untuk beban kantor (6-xxxx).',
        },
        { key: 'wbs_task_id', label: 'ID tugas WBS', type: 'number', help: 'Opsional — atribusi per pekerjaan pada kurva biaya.' },
      ],
    }],
  },
  actions: [
    {
      key: 'post', label: 'Posting Voucher', path: '{id}/post', method: 'POST',
      // fin.create + penjaga pemegang DI server: hanya pemegang dana yang
      // diterima service, apa pun perannya (lihat komentar route api.php).
      perm: 'fin.create', variant: 'primary', when: IS_DRAFT,
      confirm: 'Posting bon ini? Beban dan saldo laci langsung terbukukan — hanya pemegang dananya yang diterima server.',
    },
    {
      key: 'cancel', label: 'Batalkan Voucher', path: '{id}/cancel', method: 'POST',
      perm: 'fin.post', variant: 'danger',
      when: (row) => row.status === 'posted',
      fields: [{
        key: 'reason', label: 'Alasan pembatalan', type: 'textarea', required: true,
        help: 'Bon yang sudah diganti isi ulang terposting tidak dapat dibatalkan — koreksinya lewat JV.',
      }],
    },
  ],
};

RESOURCES['finance/kasbon'] = {
  module: 'fin', api: 'finance/kasbon', label: 'Kasbon', labelOne: 'Kasbon',
  columns: [
    { key: 'code', label: 'Kode', type: 'code', width: '1%' },
    { key: 'advance_date', label: 'Tanggal', type: 'date' },
    { key: 'fund.name', label: 'Kas kecil', type: 'text', sub: 'fund.code' },
    { key: 'employee_id', label: 'Karyawan', type: 'rel', lookup: 'employees' },
    { key: 'purpose', label: 'Keperluan', type: 'text', truncate: 50 },
    { key: 'due_date', label: 'Jatuh tempo', type: 'date' },
    { key: 'amount', label: 'Jumlah', type: 'currency', align: 'right' },
    { key: 'status', label: 'Status', type: 'status', width: '1%' },
  ],
  filters: [
    { key: 'status', label: 'Status', enum: 'kasbonStatus' },
    { key: 'fund_id', label: 'Kas kecil', lookup: 'pettyCashFunds' },
  ],
  editableWhen: IS_DRAFT,
  deletableWhen: IS_DRAFT,
  form: {
    sections: [{
      title: 'Kasbon (uang muka kerja)',
      help: 'Pencairan membukukan piutang karyawan (1-1370), BUKAN biaya — biaya diakui '
        + 'saat pertanggungjawaban dengan bukti belanja. Satu kasbon berjalan per karyawan per laci.',
      fields: [
        { key: 'fund_id', label: 'Kas kecil', type: 'lookup', lookup: 'pettyCashFunds', required: true, createOnly: true },
        { key: 'employee_id', label: 'Karyawan', type: 'lookup', lookup: 'employees', required: true },
        { key: 'advance_date', label: 'Tanggal pencairan', type: 'date', required: true, defaultToday: true },
        { key: 'amount', label: 'Jumlah', type: 'currency', required: true },
        { key: 'purpose', label: 'Keperluan', type: 'text', required: true, span: 2 },
        { key: 'project_id', label: 'Proyek', type: 'lookup', lookup: 'projects', help: 'Kosongkan untuk memakai proyek si dana.' },
        { key: 'due_date', label: 'Batas pertanggungjawaban', type: 'date' },
      ],
    }],
  },
};

// Pintu NAV grup Keuangan kini dideklarasikan di schema.js seperti layar lain.

/* --------------------------------------------------------- kerangka layar */

function crumbs(parts) {
  const host = document.getElementById('crumbs');
  if (!host) return;
  clear(host);
  parts.forEach((part, index) => {
    if (index) host.appendChild(icon('chevronRight', 12));
    host.appendChild(index === parts.length - 1 ? el('b', { text: part }) : el('span', { text: part }));
  });
  document.title = `${parts[parts.length - 1]} · Nusantara ERP`;
}

function screenHost() {
  const node = document.getElementById('view');
  node.scrollTop = 0;
  return clear(node);
}

function head(title, subtitle, actions, status) {
  document.title = `${title} · Nusantara ERP`;
  const crumb = document.querySelector('#crumbs b');
  if (crumb) crumb.textContent = title;

  return el('.page-head', [
    el('div', [
      el('div', { style: { display: 'flex', alignItems: 'center', gap: '9px', flexWrap: 'wrap' } }, [
        el('h1', { text: title }),
        status || null,
      ]),
      subtitle ? el('.desc', { text: subtitle }) : null,
    ]),
    el('.actions', [button('', { iconName: 'back', title: 'Kembali', onClick: () => back() }), ...(actions || [])]),
  ]);
}

/* ------------------------------------------------------------ detail dana */

async function renderFund(host, { id }) {
  clear(host);
  const def = RESOURCES['finance/petty-cash-funds'];
  const reload = () => renderFund(host, { id });

  let fund;
  try {
    fund = await api.get(`finance/petty-cash-funds/${id}`);
  } catch (error) {
    host.append(el('.page-head', [button('Kembali', { iconName: 'back', onClick: () => back() })]), errorState(error, reload));
    return;
  }

  await preload(['projects', 'bankAccounts']);
  const [vouchers, kasbons] = await Promise.all([
    api.get('finance/petty-cash-vouchers', { fund_id: id, per_page: 100 }).then(rows).catch(() => []),
    api.get('finance/kasbon', { fund_id: id, per_page: 100 }).then(rows).catch(() => []),
  ]);

  const due = Number(fund.replenishment_due || 0);

  /* Isi ulang: hanya MEMBUAT draf PAY sebesar float − saldo; uangnya bergerak
     setelah pembayaran melewati rantai persetujuan yang sudah ada. */
  const replenish = button('Isi Ulang Dana', { variant: 'primary' });
  replenish.addEventListener('click', async () => {
    const values = await promptFields(`Isi ulang ${fund.code}`, [
      {
        key: 'bank_account_id', label: 'Dari rekening bank', type: 'lookup', lookup: 'bankAccounts', required: true,
        help: `Draf PAY sebesar ${fmt.rupiah(due)} (float ${fmt.rupiah(fund.float_amount)} − saldo laci ${fmt.rupiah(fund.balance)}).`,
      },
    ], { submitLabel: 'Buat Draf Pembayaran' });
    if (values === null) return;

    await withBusy(replenish, async () => {
      try {
        const payment = await api.post(`finance/petty-cash-funds/${id}/replenish`, { bank_account_id: values.bank_account_id });
        toast('Draf pembayaran isi ulang dibuat — ajukan untuk persetujuan.');
        navigate(`d/finance/payments/${payment.id}`);
      } catch (error) {
        toast(error.message || String(error), { tone: 'err' });
      }
    });
  });

  const returnBtn = button('Setor ke Bank', { variant: 'ghost' });
  returnBtn.addEventListener('click', async () => {
    const values = await promptFields(`Setor ${fund.code} ke bank`, [
      { key: 'bank_account_id', label: 'Ke rekening bank', type: 'lookup', lookup: 'bankAccounts', required: true },
      {
        key: 'amount', label: 'Jumlah', type: 'currency', required: true,
        help: `Maksimal saldo laci ${fmt.rupiah(fund.balance)} — untuk mengecilkan atau menutup dana.`,
      },
    ], { submitLabel: 'Buat Draf Penerimaan' });
    if (values === null) return;

    await withBusy(returnBtn, async () => {
      try {
        const payment = await api.post(`finance/petty-cash-funds/${id}/return`, {
          bank_account_id: values.bank_account_id, amount: Number(values.amount),
        });
        toast('Draf penerimaan setoran dibuat — posting untuk membukukan.');
        navigate(`d/finance/payments/${payment.id}`);
      } catch (error) {
        toast(error.message || String(error), { tone: 'err' });
      }
    });
  });

  host.appendChild(head(
    fund.code,
    `${fund.name} · pemegang ${(fund.custodian || {}).name || '—'}`,
    [
      session.can('fin.update')
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'finance/petty-cash-funds', row: fund, onSaved: reload }) })
        : null,
      session.can('fin.create') && due > 0 ? replenish : null,
      session.can('fin.create') && Number(fund.balance) > 0 ? returnBtn : null,
    ],
    badge(fund.is_active ? 'Aktif' : 'Nonaktif', fund.is_active ? 'green' : 'red'),
  ));

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Dana tetap (float)' }), el('.value.sm', { text: fmt.rupiah(fund.float_amount) })]),
    el('.stat', [el('.label', { text: 'Saldo laci (GL)' }), el('.value.sm', { text: fmt.rupiah(fund.balance) })]),
    el('.stat', [el('.label', { text: 'Perlu diisi ulang' }), el('.value.sm', {
      text: fmt.rupiah(Math.max(due, 0)),
      style: due > 0 ? { color: 'var(--warning)' } : {},
    })]),
    el('.stat', [el('.label', { text: 'Bon belum diganti' }), el('.value.sm', { text: fmt.rupiah(fund.unreplenished_voucher_total) })]),
    el('.stat', [el('.label', { text: 'Kasbon berjalan' }), el('.value.sm', { text: fmt.rupiah(fund.outstanding_kasbon_total) })]),
  ]));

  const tabs = el('.tabs', [
    el('button.active', { text: `Voucher (${vouchers.length})`, onclick: () => switchTab('vouchers') }),
    el('button', { text: `Kasbon (${kasbons.length})`, onclick: () => switchTab('kasbon') }),
  ]);
  const body = el('div');
  host.append(tabs, body);

  function voucherTable() {
    if (!vouchers.length) return emptyState('Belum ada voucher pada dana ini.');
    return el('.card', el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Kategori' }),
        el('th', { text: 'Keterangan' }), el('th.right', { text: 'Jumlah' }),
        el('th.center', { text: 'Diganti' }), el('th', { text: 'Status' }),
      ])),
      el('tbody', vouchers.map((row) => el('tr', [
        el('td.mono', el('a', { text: row.code, href: `#/d/finance/petty-cash-vouchers/${row.id}` })),
        el('td', { text: fmt.date(row.voucher_date) }),
        el('td', { text: (ENUMS.pettyCashCategory.find((option) => option.value === row.category) || {}).label || row.category }),
        el('td', { text: row.description }),
        el('td.right.num', { text: fmt.rupiah(row.amount) }),
        // ✓ hanya setelah pembayaran stempelnya TERPOSTING — stempel jatuh
        // saat diajukan, sebelum uang bank bergerak.
        el('td.center', row.replenishment_payment_id
          ? (((row.replenishment_payment || {}).status || 'posted') === 'posted'
            ? el('span', { text: '✓', style: { color: 'var(--success)', fontWeight: '700' } })
            : el('span.muted', { text: 'diajukan', style: { fontSize: '11.5px' } }))
          : el('span.muted', { text: '–' })),
        el('td', badge(row.status, fmt.statusTone(row.status))),
      ]))),
    ])));
  }

  function kasbonTable() {
    if (!kasbons.length) return emptyState('Belum ada kasbon pada dana ini.');
    return el('.card', el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kode' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Karyawan' }),
        el('th', { text: 'Keperluan' }), el('th.right', { text: 'Jumlah' }), el('th', { text: 'Status' }),
      ])),
      el('tbody', kasbons.map((row) => el('tr', [
        el('td.mono', el('a', { text: row.code, href: `#/d/finance/kasbon/${row.id}` })),
        el('td', { text: fmt.date(row.advance_date) }),
        el('td', { text: labelFor('employees', row.employee_id) || `#${row.employee_id}` }),
        el('td', { text: row.purpose }),
        el('td.right.num', { text: fmt.rupiah(row.amount) }),
        el('td', badge(
          (ENUMS.kasbonStatus.find((option) => option.value === row.status) || {}).label || row.status,
          row.status === 'issued' ? 'amber' : fmt.statusTone(row.status),
        )),
      ]))),
    ])));
  }

  async function switchTab(tab) {
    [...tabs.children].forEach((node, index) =>
      node.classList.toggle('active', ['vouchers', 'kasbon'][index] === tab));
    clear(body).appendChild(tab === 'vouchers' ? voucherTable() : kasbonTable());
  }

  await preload(['employees']).catch(() => {});
  await switchTab('vouchers');
}

/* ---------------------------------------------------------- detail kasbon */

async function renderKasbon(host, { id }) {
  clear(host);
  const def = RESOURCES['finance/kasbon'];
  const reload = () => renderKasbon(host, { id });

  let kasbon;
  try {
    kasbon = await api.get(`finance/kasbon/${id}`);
  } catch (error) {
    host.append(el('.page-head', [button('Kembali', { iconName: 'back', onClick: () => back() })]), errorState(error, reload));
    return;
  }

  await preload(['employees']).catch(() => {});
  const projectRows = await loadSource('projects').catch(() => []);

  const fund = kasbon.fund || {};
  const isCustodian = session.user && Number(session.user.id) === Number(fund.custodian_id);
  const statusLabel = (ENUMS.kasbonStatus.find((option) => option.value === kasbon.status) || {}).label || kasbon.status;

  const issueBtn = button('Cairkan Kasbon', { variant: 'primary' });
  issueBtn.addEventListener('click', async () => {
    const confirmed = await confirmDialog({
      title: `Cairkan ${kasbon.code}`,
      message: `${fmt.rupiah(kasbon.amount)} keluar dari laci ${fund.name || fund.code} ke `
        + `${labelFor('employees', kasbon.employee_id) || 'karyawan'}. Piutang karyawan (1-1370) `
        + 'langsung terbukukan — biaya baru diakui saat pertanggungjawaban.',
      confirmLabel: 'Cairkan',
      tone: 'primary',
    });
    if (!confirmed) return;

    await withBusy(issueBtn, async () => {
      try {
        await api.post(`finance/kasbon/${id}/issue`, {});
        toast('Kasbon dicairkan.');
        reload();
      } catch (error) {
        toast(error.message || String(error), { tone: 'err' });
      }
    });
  });

  const deleteBtn = button('Hapus', { variant: 'danger' });
  deleteBtn.addEventListener('click', async () => {
    const confirmed = await confirmDialog({
      title: `Hapus ${kasbon.code}`, message: 'Hapus draf kasbon ini?', confirmLabel: 'Hapus', tone: 'danger',
    });
    if (!confirmed) return;
    try {
      await api.del(`finance/kasbon/${id}`);
      toast('Kasbon dihapus.');
      back();
    } catch (error) {
      toast(error.message || String(error), { tone: 'err' });
    }
  });

  host.appendChild(head(
    kasbon.code,
    `${labelFor('employees', kasbon.employee_id) || `Karyawan #${kasbon.employee_id}`} · ${fund.name || ''} · ${fmt.date(kasbon.advance_date)}`,
    [
      kasbon.status === 'draft' && session.can('fin.update')
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'finance/kasbon', row: kasbon, onSaved: reload }) })
        : null,
      kasbon.status === 'draft' && session.can('fin.create') ? issueBtn : null,
      kasbon.status === 'draft' && session.can('fin.delete') ? deleteBtn : null,
    ],
    badge(statusLabel, kasbon.status === 'issued' ? 'amber' : fmt.statusTone(kasbon.status)),
  ));

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Jumlah kasbon' }), el('.value.sm', { text: fmt.rupiah(kasbon.amount) })]),
    el('.stat', [el('.label', { text: 'Keperluan' }), el('.value.sm', { text: kasbon.purpose || '—' })]),
    el('.stat', [el('.label', { text: 'Batas pertanggungjawaban' }), el('.value.sm', { text: kasbon.due_date ? fmt.date(kasbon.due_date) : '—' })]),
    kasbon.status === 'settled'
      ? el('.stat', [el('.label', { text: Number(kasbon.cash_returned) < 0 ? 'Kekurangan dibayar laci' : 'Sisa dikembalikan' }),
        el('.value.sm', { text: fmt.rupiah(Math.abs(Number(kasbon.cash_returned || 0))) })])
      : null,
  ]));

  /* Baris pertanggungjawaban yang sudah dibukukan — immutable. */
  if ((kasbon.lines || []).length) {
    host.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Baris pertanggungjawaban' })),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kategori' }), el('th', { text: 'Keterangan' }),
          el('th', { text: 'Proyek' }), el('th', { text: 'WBS' }), el('th.right', { text: 'Jumlah' }),
        ])),
        el('tbody', kasbon.lines.map((line) => el('tr', [
          el('td', { text: (ENUMS.pettyCashCategory.find((option) => option.value === line.category) || {}).label || line.category }),
          el('td', { text: line.description }),
          el('td', { text: line.project_id ? (labelFor('projects', line.project_id) || `#${line.project_id}`) : '—' }),
          el('td.mono', { text: line.wbs_task_id ? `#${line.wbs_task_id}` : '—' }),
          el('td.right.num', { text: fmt.rupiah(line.amount) }),
        ]))),
      ])),
    ]));
  }

  if (kasbon.status !== 'issued') return;

  if (!isCustodian) {
    host.appendChild(el('.alert.info', [
      icon('warn', 15),
      el('div', { text: `Menunggu pertanggungjawaban. Hanya pemegang dana ${fund.code || ''} yang dapat `
        + 'membukukannya — bukti belanja dan sisa uangnya ada di laci pemegang.' }),
    ]));
    return;
  }

  /* Editor pertanggungjawaban — satu-satunya layar kas kecil yang butuh tabel
     baris tulisan tangan (promptFields tidak bisa baris dinamis). Satu kali,
     satu transaksi di server: jurnal + biaya proyek + status. */
  const rows = [];
  const linesBody = el('tbody');
  const summary = el('div', {
    style: {
      padding: '11px 16px', borderTop: '1px solid var(--border)', display: 'flex',
      justifyContent: 'flex-end', gap: '18px', fontSize: '13px', fontWeight: '600',
    },
  });

  const dateInput = el('input', { type: 'date', value: new Date().toISOString().slice(0, 10) });

  function refresh() {
    const spent = rows.reduce((sum, row) => sum + (Number(row.amount.value) || 0), 0);
    const backAmount = Number(kasbon.amount) - spent;
    clear(summary).append(
      el('span', [el('span.muted', { text: 'Belanja: ' }), el('span.num', { text: fmt.rupiah(spent) })]),
      el('span', [el('span.muted', { text: 'Kasbon: ' }), el('span.num', { text: fmt.rupiah(kasbon.amount) })]),
      el('span', {
        text: backAmount >= 0
          ? `Sisa kembali ke laci ${fmt.rupiah(backAmount)}`
          : `Kekurangan dibayar laci ${fmt.rupiah(-backAmount)}`,
        style: { color: backAmount >= 0 ? 'var(--success)' : 'var(--warning)' },
      }),
    );
  }

  function addRow() {
    const category = el('select');
    ENUMS.pettyCashCategory.forEach((option) =>
      category.appendChild(el('option', { value: option.value, text: option.label })));
    const description = el('input', { type: 'text', placeholder: 'mis. "solar genset 20 liter"' });
    const amount = el('input', { type: 'number', step: '0.01', min: 0, placeholder: '0' });
    amount.addEventListener('input', refresh);
    const project = el('select');
    project.appendChild(el('option', { value: '', text: '— kantor (6-xxxx) —' }));
    optionsFor('projects', projectRows).forEach((option) =>
      project.appendChild(el('option', { value: option.value, text: option.label })));
    if (kasbon.project_id) project.value = String(kasbon.project_id);
    const wbs = el('input', { type: 'number', min: 1, placeholder: '—', style: { width: '72px' } });

    const row = { category, description, amount, project, wbs };
    rows.push(row);

    const tr = el('tr', [
      el('td', category),
      el('td', description),
      el('td', project),
      el('td', wbs),
      el('td', { style: { width: '150px' } }, amount),
      el('td', el('.row-actions', [button('', {
        size: 'sm', variant: 'ghost', iconName: 'close', title: 'Hapus baris',
        onClick: () => { rows.splice(rows.indexOf(row), 1); tr.remove(); refresh(); },
      })])),
    ]);
    linesBody.appendChild(tr);
    refresh();
  }

  const settleBtn = button('Bukukan Pertanggungjawaban', { variant: 'primary' });
  settleBtn.addEventListener('click', async () => {
    const lines = rows
      .filter((row) => Number(row.amount.value) > 0)
      .map((row) => ({
        category: row.category.value,
        description: row.description.value.trim(),
        amount: Number(row.amount.value),
        project_id: row.project.value ? Number(row.project.value) : null,
        wbs_task_id: row.wbs.value ? Number(row.wbs.value) : null,
      }));

    const spent = lines.reduce((sum, line) => sum + line.amount, 0);
    const backAmount = Number(kasbon.amount) - spent;
    const confirmed = await confirmDialog({
      title: `Pertanggungjawaban ${kasbon.code}`,
      message: lines.length
        ? `${lines.length} baris belanja ${fmt.rupiah(spent)}; `
          + (backAmount >= 0
            ? `sisa ${fmt.rupiah(backAmount)} kembali ke laci.`
            : `kekurangan ${fmt.rupiah(-backAmount)} dibayar dari laci.`)
        : `Tanpa belanja — seluruh ${fmt.rupiah(kasbon.amount)} kembali ke laci.`,
      confirmLabel: 'Bukukan',
      tone: 'primary',
    });
    if (!confirmed) return;

    await withBusy(settleBtn, async () => {
      try {
        await api.post(`finance/kasbon/${id}/settle`, {
          settlement_date: dateInput.value,
          lines,
        });
        toast('Pertanggungjawaban dibukukan; piutang karyawan lunas.');
        reload();
      } catch (error) {
        toast(error.message || String(error), { tone: 'err' });
      }
    });
  });

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Pertanggungjawaban' }),
      el('.spacer'),
      el('span.muted', { text: 'Tanggal: ', style: { fontSize: '12.5px' } }),
      dateInput,
      button('Tambah baris', { size: 'sm', onClick: addRow }),
      settleBtn,
    ]),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kategori' }), el('th', { text: 'Keterangan' }),
        el('th', { text: 'Proyek' }), el('th', { text: 'WBS' }),
        el('th', { text: 'Jumlah' }), el('th', { text: '' }),
      ])),
      linesBody,
    ])),
    summary,
  ]));

  addRow();
}

/* Registrasi SEBELUM wildcard d/* milik app.js — lihat komentar kepala file. */
route('d/finance/petty-cash-funds/:id', ({ id }) => {
  crumbs(['Keuangan', 'Kas Kecil', `#${id}`]);
  const host = screenHost();
  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki hak akses "fin.view" untuk halaman ini.'));
    return;
  }
  renderFund(host, { id }).catch((error) => {
    clear(host).appendChild(errorState(error, () => renderFund(host, { id })));
  });
});

route('d/finance/kasbon/:id', ({ id }) => {
  crumbs(['Keuangan', 'Kasbon', `#${id}`]);
  const host = screenHost();
  if (!session.can('fin.view')) {
    host.appendChild(el('.alert.error', 'Anda tidak memiliki hak akses "fin.view" untuk halaman ini.'));
    return;
  }
  renderKasbon(host, { id }).catch((error) => {
    clear(host).appendChild(errorState(error, () => renderKasbon(host, { id })));
  });
});

/* ------------------------------------------------------------ layar kasir */

/* Pilihan laci dan tab bertahan selama sesi — kasir yang mampir ke detail
   pembayaran isi ulang kembali ke laci dan tab yang sama. */
const kasir = { fundId: null, tab: 'bon' };

const KASIR_TABS = [
  { key: 'bon', label: 'Bon di Laci' },
  { key: 'kasbon', label: 'Kasbon' },
];

export async function renderKasKecil(host) {
  clear(host);
  const reload = () => renderKasKecil(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Kasir Kas Kecil' }),
      el('.desc', {
        text: 'Posisi laci hari ini, entri bon, dan kasbon — layar harian pemegang dana. '
          + 'Isi ulang berangkat dari sini ke alur Pembayaran yang berpersetujuan.',
      }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload })]),
  ]));

  const controls = el('.filters', {
    style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' },
  });
  const tabs = el('.tabs');
  const body = el('div');
  host.append(controls, tabs, body);
  body.appendChild(skeletonTable(4, 6));

  let funds;
  try {
    funds = rows(await api.get('finance/petty-cash-funds', { per_page: 100 }));
  } catch (error) {
    clear(body).appendChild(errorState(error, reload));
    return;
  }

  if (!funds.length) {
    controls.remove();
    tabs.remove();
    clear(body).appendChild(el('.alert.info',
      'Belum ada dana kas kecil. Daftarkan laci pertama — kode, akun 1-11xx, pemegang, '
      + 'dan dana tetap (float) — di register Kas Kecil & Kasbon.'));
    if (session.can('fin.create')) {
      body.appendChild(el('.row-actions', [
        button('Daftarkan dana', { iconName: 'chevron', onClick: () => navigate('r/finance/petty-cash-funds') }),
      ]));
    }
    return;
  }

  const user = session.user || {};
  // Kasir dulu: laci yang dipegang pengguna ini terpilih otomatis; pengguna
  // finance lain tetap bisa memantau laci mana pun lewat pemilih.
  const mine = funds.filter((fund) => Number(fund.custodian_id) === Number(user.id));
  if (!funds.some((fund) => fund.id === kasir.fundId)) kasir.fundId = (mine[0] || funds[0]).id;

  const picker = el('select.filter-w', {
    onchange: (event) => { kasir.fundId = Number(event.target.value); load(); },
  });
  funds.forEach((fund) => picker.appendChild(el('option', {
    value: fund.id,
    text: `${fund.code} — ${fund.name}${Number(fund.custodian_id) === Number(user.id) ? ' (laci Anda)' : ''}`,
  })));
  picker.value = String(kasir.fundId);

  const holder = el('span.muted', { style: { fontSize: '12.5px' } });
  controls.append(picker, holder, el('.spacer'), button('Muat ulang', {
    size: 'sm', variant: 'ghost', iconName: 'refresh', onClick: () => load(),
  }));

  function paintTabs() {
    clear(tabs);
    KASIR_TABS.forEach((tab) => tabs.appendChild(el(`button${tab.key === kasir.tab ? '.active' : ''}`, {
      text: tab.label,
      onclick: () => { if (kasir.tab === tab.key) return; kasir.tab = tab.key; paintTabs(); load(); },
    })));
  }

  async function load() {
    clear(body);
    body.appendChild(skeletonTable(4, 6));

    let fund; let vouchers; let kasbons;
    try {
      [fund, vouchers, kasbons] = await Promise.all([
        api.get(`finance/petty-cash-funds/${kasir.fundId}`),
        api.get('finance/petty-cash-vouchers', { fund_id: kasir.fundId, per_page: 200 }).then(rows),
        api.get('finance/kasbon', { fund_id: kasir.fundId, per_page: 200 }).then(rows),
        preload(['projects', 'employees', 'bankAccounts']).catch(() => {}),
      ]);
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
      return;
    }

    const projectRows = await loadSource('projects').catch(() => []);
    const isCustodian = Number(fund.custodian_id) === Number(user.id);
    holder.textContent = `Pemegang: ${(fund.custodian || {}).name || '—'}`;

    clear(body);
    body.appendChild(positionRow(fund));

    /* Identitas imprest: float − bon belum diganti − kasbon berjalan − belanja
       kasbon belum diganti − potongan upah belum diganti harus sama dengan
       saldo GL laci. Rumusnya dihitung
       SERVER (PettyCashFundService::imprestExpectation — satu rumah untuk satu
       rumus): versi layar yang lama menghitung sendiri tanpa suku belanja
       kasbon, sehingga setiap kasbon selesai meninggalkan alarm palsu
       permanen. Selisih adalah temuan (pendanaan awal di bawah float, isi
       ulang tidak penuh), bukan noise — tampilkan apa adanya. */
    const expected = Number(fund.imprest_expected);
    if (Number.isFinite(expected) && Math.abs(expected - Number(fund.balance)) > 0.01) {
      body.appendChild(el('.alert.warn', [
        icon('warn', 15),
        el('div', { text: 'Identitas imprest tidak menutup: float − bon − kasbon berjalan − belanja kasbon − potongan upah '
          + `= ${fmt.rupiah(expected)}, tetapi saldo laci di GL ${fmt.rupiah(fund.balance)}. Biasanya `
          + 'pendanaan awal di bawah float atau isi ulang yang belum diposting penuh — cocokkan dengan '
          + 'uang fisik sebelum tutup hari.' }),
      ]));
    }

    if (Number(fund.replenishment_due) > 0 && session.can('fin.create')) {
      body.appendChild(replenishCard(fund));
    }

    if (kasir.tab === 'bon') paintBon(fund, vouchers, isCustodian, projectRows);
    else paintKasbon(fund, kasbons, isCustodian);

    body.appendChild(caraKerjanya());
    body.appendChild(el('.row-actions', [
      button('Semua voucher', { iconName: 'chevron', onClick: () => navigate('r/finance/petty-cash-vouchers') }),
      button('Semua kasbon', { iconName: 'chevron', onClick: () => navigate('r/finance/kasbon') }),
      button('Register dana', { iconName: 'chevron', onClick: () => navigate(`d/finance/petty-cash-funds/${fund.id}`) }),
    ]));
  }

  /** Lima angka kasir: float, − bon, − kasbon, − belanja kasbon, = di laci —
      plus suku potongan upah HANYA saat ikut bermain: kasbon yang dipulihkan
      lewat potongan upah mandor (P4) mengkredit piutang laci dari jurnal
      tagihan upahnya, tanpa uang kembali ke laci, dan tanpa suku ini kolom-
      kolom di baris ini tidak lagi menjumlah ke imprest_expected server. */
  function positionRow(fund) {
    const due = Number(fund.replenishment_due || 0);
    const wageOffset = Number(fund.unreplenished_wage_offset_total || 0);
    return el('.stat-row', [
      el('.stat', [
        el('.label', { text: 'Dana tetap (float)' }),
        el('.value.sm', { text: fmt.rupiah(fund.float_amount) }),
        el('.delta', { text: fund.max_voucher_amount
          ? `plafon per bon ${fmt.rupiah(fund.max_voucher_amount)}`
          : 'tanpa plafon per bon' }),
      ]),
      el('.stat', [
        el('.label', { text: '− Bon belum diganti' }),
        el('.value.sm', { text: fmt.rupiah(fund.unreplenished_voucher_total) }),
        el('.delta', { text: 'terposting, menunggu isi ulang terposting' }),
      ]),
      el('.stat', [
        el('.label', { text: '− Kasbon berjalan' }),
        el('.value.sm', { text: fmt.rupiah(fund.outstanding_kasbon_total) }),
        el('.delta', { text: 'di kantong karyawan' }),
      ]),
      el('.stat', [
        el('.label', { text: '− Belanja kasbon belum diganti' }),
        el('.value.sm', { text: fmt.rupiah(fund.settled_kasbon_spend_total) }),
        el('.delta', { text: 'bukti pertanggungjawaban, menunggu isi ulang' }),
      ]),
      ...(wageOffset > 0 ? [el('.stat', [
        el('.label', { text: '− Potongan upah belum diganti' }),
        el('.value.sm', { text: fmt.rupiah(wageOffset) }),
        el('.delta', { text: 'kasbon dipotong upah mandor, menunggu isi ulang' }),
      ])] : []),
      el('.stat', [
        el('.label', { text: '= Seharusnya di laci' }),
        el('.value.sm', { text: fmt.rupiah(fund.balance) }),
        due > 0
          ? el('.delta.down', { text: `terpakai ${fmt.rupiah(due)} dari float` })
          : el('.delta.up', { text: 'laci penuh — hitung fisik tiap tutup hari' }),
      ]),
    ]);
  }

  /** Serah-terima ke alur Pembayaran: layar ini hanya MEMBUAT draf PAY. */
  function replenishCard(fund) {
    const due = Number(fund.replenishment_due);
    const request = button('Minta Isi Ulang', { variant: 'primary' });
    request.addEventListener('click', async () => {
      const values = await promptFields(`Isi ulang ${fund.code}`, [{
        key: 'bank_account_id', label: 'Dari rekening bank', type: 'lookup', lookup: 'bankAccounts', required: true,
        help: `Draf PAY sebesar ${fmt.rupiah(due)} (float ${fmt.rupiah(fund.float_amount)} − saldo laci `
          + `${fmt.rupiah(fund.balance)}) — jumlahnya dihitung server, bukan diketik.`,
      }], { submitLabel: 'Buat Draf Pembayaran' });
      if (values === null) return;

      await withBusy(request, async () => {
        try {
          const payment = await api.post(`finance/petty-cash-funds/${fund.id}/replenish`, {
            bank_account_id: values.bank_account_id,
          });
          toast('Draf isi ulang dibuat — ajukan untuk persetujuan dari layar pembayaran.');
          navigate(`d/finance/payments/${payment.id}`);
        } catch (error) {
          toastError(error);
        }
      });
    });

    return el('.card', [
      el('.card-head', [el('h2', { text: 'Isi ulang' }), el('.spacer'), request]),
      el('.card-body', el('p.muted', {
        style: { margin: 0 },
        text: `Laci terpakai ${fmt.rupiah(due)} dari float ${fmt.rupiah(fund.float_amount)}. Permintaan `
          + 'menjadi draf pembayaran: pengaju dan penyetuju harus orang berbeda, dan penyetuju membaca '
          + 'tumpukan bon yang dibekukan saat pengajuan.',
      })),
    ]);
  }

  /* -------------------------------------------------------------- tab bon */

  function paintBon(fund, vouchers, isCustodian, projectRows) {
    const drafts = vouchers.filter((row) => row.status === 'draft');
    /* "Belum diganti" berarti belum DIGANTI, bukan belum distempel: stempel
       jatuh saat isi ulang diajukan, sebelum uang bank bergerak. Bon yang
       stempelnya masih menunggu (submitted/approved) tetap di tumpukan —
       selaras dengan unreplenished_voucher_total di baris posisi. */
    const stampPending = (row) => row.replenishment_payment_id
      && ((row.replenishment_payment || {}).status || 'posted') !== 'posted';
    const pile = vouchers.filter((row) => row.status === 'posted'
      && (!row.replenishment_payment_id || stampPending(row)));

    if (isCustodian && session.can('fin.create')) {
      body.appendChild(quickEntry(fund, projectRows));
    } else {
      body.appendChild(el('.alert.info', [
        icon('warn', 15),
        el('div', { text: `Entri cepat hanya untuk pemegang laci ini (${(fund.custodian || {}).name || '—'}) — `
          + 'server menolak posting dari orang lain, apa pun perannya. Bon tetap bisa didraf lewat '
          + 'register Voucher Kas Kecil.' }),
      ]));
    }

    if (!drafts.length && !pile.length) {
      body.appendChild(emptyState('Laci bersih — tidak ada draf maupun bon yang menunggu penggantian.'));
      return;
    }

    const pileTotal = pile.reduce((sum, row) => sum + Number(row.amount || 0), 0);

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: 'Bon di laci' }),
        el('.cell-sub', { text: 'draf dulu, lalu bon terposting yang menunggu isi ulang' }),
      ]),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Kategori' }),
          el('th', { text: 'Keterangan' }), el('th', { text: 'Proyek' }),
          el('th.right', { text: 'Jumlah' }), el('th', { text: 'Status' }), el('th', { text: '' }),
        ])),
        el('tbody', [...drafts, ...pile].map((row) => el('tr', [
          el('td.mono', el('a', { text: row.code, href: `#/d/finance/petty-cash-vouchers/${row.id}` })),
          el('td', { text: fmt.date(row.voucher_date) }),
          el('td', { text: (ENUMS.pettyCashCategory.find((option) => option.value === row.category) || {}).label || row.category }),
          el('td', { text: row.description }),
          el('td', { text: row.project_id ? (labelFor('projects', row.project_id) || `#${row.project_id}`) : '—' }),
          el('td.right.num', { text: fmt.rupiah(row.amount) }),
          el('td', el('span', [
            badge(row.status === 'draft' ? 'Draf' : 'Terposting', fmt.statusTone(row.status)),
            stampPending(row)
              ? el('.cell-sub', { text: `menunggu ${(row.replenishment_payment || {}).code || 'isi ulang'} diposting` })
              : null,
          ])),
          el('td.right', row.status === 'draft' && isCustodian && session.can('fin.create')
            ? postVoucherButton(row)
            : null),
        ]))),
        pile.length
          ? el('tfoot', el('tr', [
            el('td', { text: 'Menunggu penggantian', colspan: 5 }),
            el('td.right', { text: fmt.rupiah(pileTotal) }),
            el('td', { colspan: 2 }),
          ]))
          : null,
      ])),
    ]));
  }

  function postVoucherButton(row) {
    const post = button('Posting', { size: 'sm', variant: 'primary' });
    post.addEventListener('click', async () => {
      const confirmed = await confirmDialog({
        title: `Posting ${row.code}`,
        message: `${fmt.rupiah(row.amount)} keluar dari saldo laci dan menjadi beban `
          + `${row.project_id ? 'proyek (HPP 5-xxxx)' : 'kantor (6-xxxx)'} hari itu juga.`,
        confirmLabel: 'Posting',
        tone: 'primary',
      });
      if (!confirmed) return;

      await withBusy(post, async () => {
        try {
          await api.post(`finance/petty-cash-vouchers/${row.id}/post`, {});
          toast('Bon diposting; beban dan saldo laci sudah dibukukan.');
          load();
        } catch (error) {
          toastError(error);
        }
      });
    });
    return post;
  }

  /** Entri secepat menulis di amplop: satu kartu, tombol "Catat & Posting". */
  function quickEntry(fund, projectRows) {
    const dateInput = el('input', { type: 'date', value: fmt.today() });
    const category = el('select');
    ENUMS.pettyCashCategory.forEach((option) =>
      category.appendChild(el('option', { value: option.value, text: option.label })));
    const amount = el('input', { type: 'number', step: '0.01', min: 0, placeholder: '0' });
    const description = el('input', { type: 'text', placeholder: 'mis. "BBM + tol survei site" — tulis seperti di bonnya' });
    const project = el('select');
    project.appendChild(el('option', { value: '', text: '— kantor (6-xxxx) —' }));
    optionsFor('projects', projectRows).forEach((option) =>
      project.appendChild(el('option', { value: option.value, text: option.label })));
    // Laci site membebani proyeknya sendiri secara default; tetap bisa diganti.
    if (fund.project_id) project.value = String(fund.project_id);
    const wbs = el('input', { type: 'number', min: 1, placeholder: '—' });

    async function submit(postNow, trigger) {
      const payload = {
        fund_id: Number(fund.id),
        voucher_date: dateInput.value,
        category: category.value,
        description: description.value.trim(),
        amount: Number(amount.value),
        project_id: project.value ? Number(project.value) : null,
        wbs_task_id: wbs.value ? Number(wbs.value) : null,
      };

      if (!payload.description || !(payload.amount > 0)) {
        toast('Isi keterangan dan jumlah bon dulu.', { tone: 'err' });
        return;
      }

      await withBusy(trigger, async () => {
        let voucher;
        try {
          voucher = await api.post('finance/petty-cash-vouchers', payload);
        } catch (error) {
          toastError(error);
          return;
        }

        if (!postNow) {
          toast(`${voucher.code} disimpan sebagai draf.`);
          load();
          return;
        }

        try {
          await api.post(`finance/petty-cash-vouchers/${voucher.id}/post`, {});
          toast(`${voucher.code} diposting; beban dan saldo laci sudah dibukukan.`);
        } catch (error) {
          /* Draf-nya TIDAK hilang — plafon/saldo/periode ditolak server, bonnya
             menunggu di tabel bawah untuk diperbaiki lalu diposting ulang. */
          toast(`${error.message || error} — ${voucher.code} tersimpan sebagai draf di tabel bawah.`, { tone: 'err', timeout: 9000 });
        }
        load();
      });
    }

    const draftBtn = button('Simpan Draf', { variant: 'ghost' });
    draftBtn.addEventListener('click', (event) => submit(false, event.currentTarget));
    const postBtn = button('Catat & Posting', { variant: 'primary' });
    postBtn.addEventListener('click', (event) => submit(true, event.currentTarget));

    const descField = field('Keterangan', description, { required: true });
    descField.classList.add('span2');
    const grid = el('.form-grid', [
      field('Tanggal bon', dateInput, { required: true }),
      field('Kategori', category, { required: true }),
      descField,
      field('Jumlah', amount, { required: true }),
      field('Proyek', project, { help: 'Kosongkan untuk beban kantor (6-xxxx).' }),
      field('ID tugas WBS', wbs, { help: 'Opsional — atribusi per pekerjaan pada kurva biaya.' }),
    ]);

    return el('.card', [
      el('.card-head', [el('h2', { text: 'Catat bon' }), el('.spacer'), draftBtn, postBtn]),
      el('.card-body', [
        grid,
        el('p.muted', {
          style: { margin: '12px 0 0', fontSize: '12.5px' },
          text: 'Uangnya sudah keluar dari laci — ini pencatatannya. '
            + (fund.max_voucher_amount
              ? `Plafon per bon ${fmt.rupiah(fund.max_voucher_amount)}; belanja di atas itu lewat Tagihan Vendor (AP).`
              : 'Belanja besar tetap lewat Tagihan Vendor (AP) agar berpersetujuan.'),
        }),
      ]),
    ]);
  }

  /* ----------------------------------------------------------- tab kasbon */

  function paintKasbon(fund, kasbons, isCustodian) {
    const open = kasbons.filter((row) => row.status !== 'settled');

    const newBtn = session.can('fin.create')
      ? button('Kasbon Baru', { variant: 'primary', iconName: 'plus', onClick: () => openForm({
        def: RESOURCES['finance/kasbon'],
        key: 'finance/kasbon',
        prefill: { fund_id: fund.id, advance_date: fmt.today(), project_id: fund.project_id },
        onSaved: () => load(),
      }) })
      : null;

    if (!open.length) {
      body.appendChild(el('.card', [
        el('.card-head', [el('h2', { text: 'Kasbon' }), el('.spacer'), newBtn]),
        el('.card-body', el('p.muted', {
          style: { margin: 0 },
          text: 'Tidak ada kasbon berjalan pada laci ini. Pencairan membukukan piutang karyawan '
            + '(1-1370), bukan biaya — biaya lahir saat pertanggungjawaban.',
        })),
      ]));
      return;
    }

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: 'Kasbon berjalan' }),
        el('.cell-sub', { text: 'satu kasbon berjalan per karyawan per laci — pencairan kedua ditolak server' }),
        el('.spacer'),
        newBtn,
      ]),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }), el('th', { text: 'Karyawan' }), el('th', { text: 'Tanggal' }),
          el('th', { text: 'Jatuh tempo' }), el('th', { text: 'Keperluan' }),
          el('th.right', { text: 'Jumlah' }), el('th', { text: 'Status' }), el('th', { text: '' }),
        ])),
        el('tbody', open.map((row) => {
          // toDateInput menormalkan ISO bertimestamp ke YYYY-MM-DD sebelum
          // dibandingkan leksikal; hari-H sendiri belum terhitung lewat.
          const overdue = row.status === 'issued' && row.due_date
            && fmt.toDateInput(row.due_date) < fmt.today();
          return el('tr', [
            el('td.mono', el('a', { text: row.code, href: `#/d/finance/kasbon/${row.id}` })),
            el('td', { text: labelFor('employees', row.employee_id) || `#${row.employee_id}` }),
            el('td', { text: fmt.date(row.advance_date) }),
            el('td', row.due_date
              ? el('span', [
                el('span', { text: fmt.date(row.due_date) }),
                overdue ? el('div', badge('Lewat tenggat', 'red')) : null,
              ])
              : el('span.muted', { text: '—' })),
            el('td', { text: row.purpose }),
            el('td.right.num', { text: fmt.rupiah(row.amount) }),
            el('td', badge(
              (ENUMS.kasbonStatus.find((option) => option.value === row.status) || {}).label || row.status,
              row.status === 'issued' ? 'amber' : fmt.statusTone(row.status),
            )),
            el('td.right', kasbonAction(fund, row, isCustodian)),
          ]);
        })),
      ])),
    ]));
  }

  function kasbonAction(fund, row, isCustodian) {
    if (!isCustodian || !session.can('fin.create')) return null;

    if (row.status === 'issued') {
      return button('Pertanggungjawaban', {
        size: 'sm',
        variant: 'primary',
        // Editor barisnya di layar detail kasbon — butuh tabel dinamis.
        onClick: () => navigate(`d/finance/kasbon/${row.id}`),
      });
    }

    if (row.status !== 'draft') return null;

    const issue = button('Cairkan', { size: 'sm', variant: 'primary' });
    issue.addEventListener('click', async () => {
      const confirmed = await confirmDialog({
        title: `Cairkan ${row.code}`,
        message: `${fmt.rupiah(row.amount)} keluar dari laci ${fund.name || fund.code} ke `
          + `${labelFor('employees', row.employee_id) || 'karyawan'}. Piutang karyawan (1-1370) `
          + 'langsung terbukukan — biaya baru diakui saat pertanggungjawaban.',
        confirmLabel: 'Cairkan',
        tone: 'primary',
      });
      if (!confirmed) return;

      await withBusy(issue, async () => {
        try {
          await api.post(`finance/kasbon/${row.id}/issue`, {});
          toast('Kasbon dicairkan.');
          load();
        } catch (error) {
          toastError(error);
        }
      });
    });
    return issue;
  }

  function caraKerjanya() {
    return el('.card', [
      el('.card-head', el('h2', { text: 'Cara kerjanya' })),
      el('.card-body', [
        el('p', { text: 'Laci imprest: dana tetapnya (float) tidak berubah. Setiap bon yang diposting langsung mengecilkan saldo laci dan menjadi beban hari itu juga — berproyek ke HPP 5-xxxx dan ikut kurva biaya proyek, tanpa proyek ke beban kantor 6-xxxx.' }),
        el('p', { text: 'Isi ulang selalu sebesar float − saldo laci; server yang menghitung dan memverifikasinya ulang saat posting, sehingga total penggantian identik dengan total bon. Permintaan isi ulang menjadi draf pembayaran ber-maker-checker: pengaju dan penyetuju orang berbeda, dan penyetuju membaca tumpukan bon yang dibekukan saat pengajuan.' }),
        el('p', { text: 'Kasbon adalah uang muka, bukan biaya: pencairan membukukan piutang karyawan 1-1370. Biaya lahir saat pertanggungjawaban — per baris bukti belanja — lalu sisanya kembali ke laci, atau kekurangannya dibayar laci. Satu kasbon berjalan per karyawan per laci.' }),
        el('p', { text: 'Bon dan kasbon yang masih draf menahan tutup periode fiskal pada tanggalnya (pemeriksaan dokumen menggantung) — posting atau hapus sebelum akhir bulan.' }),
      ]),
    ]);
  }

  paintTabs();
  await load();
}

/* Route 'kas-kecil' kini terdaftar di app.js registerRoutes() seperti layar
   lain — lengkap dengan sorotan nav. Route d/ di atas tetap di lingkup modul
   (lihat ASUMSI URUTAN ROUTER di kepala berkas). */

/* ------------------------------------- panel pembayaran isi ulang/setoran */

/**
 * Dipanggil renderPayment (custom.js) untuk pembayaran ber-petty_cash_fund_id.
 * Menggantikan tabel "tagihan terbuka": alokasinya SATU baris petty_cash_fund
 * yang jumlahnya dikunci aturan imprest oleh server, jadi yang digambar di
 * sini adalah kartu dana + tumpukan bon yang diganti, bukan editor alokasi.
 */
export async function fundPaymentPanels(host, payment, { canStage, showRefusal, clearRefusal, reload }) {
  const isIn = payment.direction === 'in';
  const editable = payment.status === 'draft' || payment.status === 'rejected';

  let fund = null;
  try {
    fund = await api.get(`finance/petty-cash-funds/${payment.petty_cash_fund_id}`);
  } catch {
    /* fin.view sudah lolos untuk membuka layar ini; gagal di sini berarti
       jaringan — kartu dana digambar seadanya dari data pembayaran. */
  }

  const embedded = payment.petty_cash_fund || {};
  const code = (fund || embedded).code || `#${payment.petty_cash_fund_id}`;
  const name = (fund || embedded).name || 'Kas kecil';

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: isIn ? 'Setoran kas kecil ke bank' : 'Isi ulang kas kecil' })),
    el('.card-body', el('dl.kv', [
      el('dt', { text: 'Kas kecil' }),
      el('dd', el('a', { text: `${code} — ${name}`, href: `#/d/finance/petty-cash-funds/${payment.petty_cash_fund_id}` })),
      ...(fund ? [
        el('dt', { text: 'Dana tetap (float)' }), el('dd.num', { text: fmt.rupiah(fund.float_amount) }),
        el('dt', { text: 'Saldo laci saat ini' }), el('dd.num', { text: fmt.rupiah(fund.balance) }),
        el('dt', { text: isIn ? 'Maksimal disetor' : 'Perlu diisi ulang' }),
        el('dd.num', { text: fmt.rupiah(isIn ? fund.balance : fund.replenishment_due) }),
      ] : []),
    ])),
  ]));

  /* Tumpukan yang diganti: set beku yang distempel saat diajukan (kunci yang
     dibaca penyetuju), atau — selagi masih draf — tumpukan berjalan. Dua
     jenis kertas: bon (covered_vouchers) DAN kasbon yang sudah selesai
     dipertanggungjawabkan (covered_kasbons) — belanjanya tercatat di baris
     pertanggungjawaban, bukan di bon, dan tanpa baris ini penyetuju membaca
     Rp 200.000 kertas untuk transfer Rp 1.000.000. */
  let pile = payment.covered_vouchers || [];
  let kasbonPile = payment.covered_kasbons || [];
  let draftKasbonSpend = 0;
  if (editable && !pile.length && !isIn) {
    pile = await api.get('finance/petty-cash-vouchers', {
      fund_id: payment.petty_cash_fund_id, status: 'posted', replenished: 0, per_page: 200,
    }).then(rows).catch(() => []);
  }
  if (editable && !kasbonPile.length && !isIn && fund) {
    // Draf belum menstempel apa-apa — total belanja kasbon yang menunggu
    // dibaca dari payload dana (dihitung server, satu rumah dengan identitas).
    draftKasbonSpend = Number(fund.settled_kasbon_spend_total || 0);
  }

  if (!isIn) {
    const voucherTotal = pile.reduce((sum, voucher) => sum + Number(voucher.amount || 0), 0);
    const kasbonTotal = kasbonPile.reduce((sum, kasbon) => sum + Number(kasbon.spend || 0), 0) + draftKasbonSpend;

    host.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: payment.status === 'draft' || payment.status === 'rejected' ? 'Bon & kasbon yang akan diganti' : 'Bon & kasbon yang diganti' }),
        el('.cell-sub', { text: 'periksa bukti fisiknya sebelum menyetujui — inilah yang dibayar uang bank ini' }),
      ]),
      (pile.length || kasbonPile.length || draftKasbonSpend > 0)
        ? el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: 'Dokumen' }), el('th', { text: 'Tanggal' }), el('th', { text: 'Kategori' }),
            el('th', { text: 'Keterangan' }), el('th.right', { text: 'Jumlah' }),
          ])),
          el('tbody', [
            ...pile.map((voucher) => el('tr', [
              el('td.mono', el('a', { text: voucher.code, href: `#/d/finance/petty-cash-vouchers/${voucher.id}` })),
              el('td', { text: fmt.date(voucher.voucher_date) }),
              el('td', { text: voucher.category_label
                || (ENUMS.pettyCashCategory.find((option) => option.value === voucher.category) || {}).label
                || voucher.category }),
              el('td', { text: voucher.description }),
              el('td.right.num', { text: fmt.rupiah(voucher.amount) }),
            ])),
            // Baris kasbon menampilkan BELANJANYA (Σ baris pertanggungjawaban)
            // — itulah uang laci yang diganti; uang muka dan kembaliannya
            // sudah saling meniadakan di saldo.
            ...kasbonPile.map((kasbon) => el('tr', [
              el('td.mono', el('a', { text: kasbon.code, href: `#/d/finance/kasbon/${kasbon.id}` })),
              el('td', { text: fmt.date(kasbon.settlement_date) }),
              el('td', { text: 'Kasbon (pertanggungjawaban)' }),
              el('td', { text: kasbon.purpose }),
              el('td.right.num', { text: fmt.rupiah(kasbon.spend) }),
            ])),
            draftKasbonSpend > 0
              ? el('tr', [
                el('td.mono', el('span.muted', { text: '—' })),
                el('td'),
                el('td', { text: 'Kasbon (pertanggungjawaban)' }),
                el('td', { text: 'Belanja kasbon selesai yang belum diganti — dirinci saat diajukan' }),
                el('td.right.num', { text: fmt.rupiah(draftKasbonSpend) }),
              ])
              : null,
          ].filter(Boolean)),
          el('tfoot', el('tr', [
            el('td', { text: kasbonTotal > 0 ? 'Total bon + belanja kasbon' : 'Total bon', colspan: 4 }),
            el('td.right', { text: fmt.rupiah(voucherTotal + kasbonTotal) }),
          ])),
        ]))
        : el('.card-body', el('p.muted', {
          text: 'Tidak ada bon atau belanja kasbon menunggu penggantian — selisihnya berasal dari '
            + 'kasbon yang masih berjalan atau pendanaan awal.',
          style: { margin: 0 },
        })),
    ]));
  }

  if (!editable || !canStage) return;

  /* Draf/ditolak: satu tombol, satu baris alokasi yang jumlahnya dihitung
     server-side — bukan editor. Selisih float − saldo yang berubah sejak draf
     dibuat ditolak server; perbaikannya mengubah jumlah pembayaran (Ubah). */
  const expected = fund
    ? Number(isIn ? payment.amount : fund.replenishment_due)
    : Number(payment.amount);
  const drift = fund && !isIn && Math.abs(Number(payment.amount) - expected) > 0.01;

  const stage = button(isIn ? 'Posting Penerimaan' : (payment.status === 'rejected' ? 'Ajukan Ulang' : 'Ajukan Isi Ulang'), { variant: 'primary' });
  stage.disabled = drift;
  stage.addEventListener('click', async () => {
    await withBusy(stage, async () => {
      try {
        clearRefusal();
        const allocations = [{
          payable_type: 'petty_cash_fund',
          payable_id: Number(payment.petty_cash_fund_id),
          amount: Number(payment.amount),
        }];
        if (isIn) {
          await api.post(`finance/payments/${payment.id}/post`, { allocations });
          toast('Setoran diposting dan jurnal dibuat.');
        } else {
          await api.post(`finance/payments/${payment.id}/submit`, { allocations });
          toast('Isi ulang diajukan untuk persetujuan.');
        }
        reload();
      } catch (error) {
        showRefusal(error);
      }
    });
  });

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: isIn ? 'Posting setoran' : 'Ajukan isi ulang' }),
      el('.spacer'),
      stage,
    ]),
    el('.card-body', [
      drift
        ? el('.alert.warn', [
          icon('warn', 15),
          el('div', { text: `Saldo laci berubah sejak draf dibuat: isi ulang sekarang seharusnya ${fmt.rupiah(expected)}, `
            + `bukan ${fmt.rupiah(payment.amount)}. Ubah jumlah pembayarannya dulu.` }),
        ])
        : el('p.muted', {
          text: isIn
            ? `Dr bank / Cr akun laci sebesar ${fmt.rupiah(payment.amount)} — diposting langsung karena rekening koran menguatkannya.`
            : `Satu alokasi petty_cash_fund sebesar ${fmt.rupiah(payment.amount)} (float − saldo laci); `
              + 'penyetuju membaca tumpukan bon di atas sebelum uang bank bergerak.',
          style: { margin: 0 },
        }),
    ]),
  ]));
}
