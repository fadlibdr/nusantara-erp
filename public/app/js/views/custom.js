/* Screens that need more than the generic detail view can express. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, progressBar, errorState, emptyState, toast, toastError, field, withBusy, confirmDialog } from '../ui.js';
import { downloadPdf, pdfName } from '../print.js';
import * as fmt from '../format.js';
import { loadSource, optionsFor, preload, labelFor } from '../lookup.js';
import { ENUMS, enumLabel } from '../enums.js';
import { RESOURCES } from '../schema.js';
import { openForm, promptFields, buildInput } from './form.js';
import { actionButtons } from './actions.js';
import { approvalTimeline, formButtons } from './detail.js';
import { printButtonsFor } from '../printcatalog.js';
import { navigate, back } from '../router.js';
// Kas kecil / kasbon: badan modulnya mendaftarkan RESOURCES/NAV/ENUMS/route
// miliknya sendiri saat impor — sebelum boot() app.js berjalan.
import { fundPaymentPanels } from './kaskecil.js';
import { csvValue, toCsv, downloadCsv, csvFilename } from '../csv.js';

/* Label ringkas jenis potongan pajak pada baris alokasi penerimaan. Ini
   cerminan Modules/Finance/Enums/WithholdingType; jenis yang belum dikenal
   tampil apa adanya, bukan diam-diam dinamai jenis lain — dulu peta dua-cabang
   `pph_final ? ... : 'PPN wapu'` melabeli PPh 23 sebagai PPN wapu. */
const LABEL_POTONGAN = {
  pph_final: 'PPh final',
  pph_23: 'PPh 23',
  ppn_wapu: 'PPN wapu',
  other_deduction: 'Potongan lain-lain',
};

/* "Cetak <formulir>" pada layar detail khusus.
 *
 * Layar generik mendapatkannya dari detail.js; layar di file ini menyusun baris
 * aksinya sendiri, jadi masing-masing memanggil ini SATU baris di dalam array
 * aksinya. Katalognya sudah dimuat app.js sebelum layar custom digambar —
 * printButtonsFor sinkron, jadi tombolnya ikut tergambar sekali jadi, bukan
 * muncul belakangan seperti kedipan.
 *
 * def boleh kosong: yang menentukan tombol apa yang keluar adalah kunci
 * RESOURCES-nya, bukan isi entri skemanya. */
function houseFormButtons(resource, record) {
  return formButtons(printButtonsFor(RESOURCES[resource] || {}, resource), record);
}

function pageHead(title, subtitle, actions, status) {
  document.title = `${title} · Nusantara ERP`;

  // Replace the placeholder id the router drew before the record loaded.
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

async function loadOrFail(host, loader, retry) {
  /* Kerangka (skeleton) yang sama dengan renderDetail generik, dipasang DI SINI
     supaya semua layar custom mendapatkannya sekaligus: dulu host dikosongkan
     lalu await fetch tanpa placeholder, dan di koneksi site yang lambat klik
     payroll/tiket tampak mati 1-3 detik — pengguna mengklik ulang. Pemanggil
     yang sukses selalu clear(host) sendiri sebelum menggambar hasilnya. */
  clear(host).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '40%' } }))));
  try {
    return await loader();
  } catch (error) {
    clear(host);
    host.append(el('.page-head', [button('Kembali', { iconName: 'back', onClick: () => back() })]), errorState(error, retry));
    return null;
  }
}

/* ============================================================ STOK === */
export async function renderStock(host) {
  clear(host);

  const state = { warehouse: '', q: '', tab: 'balances' };

  const head = pageHead('Saldo Stok', 'Kartu stok moving-average per gudang.', [
    button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => load() }),
  ]);
  head.querySelector('.actions').firstChild.remove(); // no "back" on a top-level page

  const tabs = el('.tabs', [
    el('button.active', { text: 'Saldo per gudang', onclick: () => switchTab('balances') }),
    el('button', { text: 'Kartu stok (ledger)', onclick: () => switchTab('ledger') }),
    el('button', { text: 'Di bawah minimum', onclick: () => switchTab('low') }),
  ]);

  const controls = el('.filters', { style: { border: '1px solid var(--border)', borderRadius: 'var(--radius)', marginBottom: '16px' } });
  const body = el('div');
  host.append(head, tabs, controls, body);

  const warehouses = await loadSource('warehouses').catch(() => []);
  await loadSource('items').catch(() => []);

  const select = el('select.filter-w', { 'aria-label': 'Gudang' });
  select.appendChild(el('option', { value: '', text: 'Semua gudang' }));
  optionsFor('warehouses', warehouses).forEach((option) =>
    select.appendChild(el('option', { value: option.value, text: option.label })));
  select.addEventListener('change', () => { state.warehouse = select.value; load(); });

  const search = el('input', { type: 'text', placeholder: 'Cari item…' });
  let timer;
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { state.q = search.value.trim(); load(); }, 320);
  });

  controls.append(el('.search', [icon('search', 14), search]), select);

  function switchTab(tab) {
    state.tab = tab;
    [...tabs.children].forEach((node, index) =>
      node.classList.toggle('active', ['balances', 'ledger', 'low'][index] === tab));
    load();
  }

  async function load() {
    clear(body);
    body.appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '16px' } }))));

    try {
      if (state.tab === 'balances') {
        const payload = await api.list('inventory/stock/balances', {
          warehouse_id: state.warehouse || undefined, q: state.q || undefined, per_page: 200,
        });
        const rows = payload.data || [];
        const meta = payload.meta || {};
        const totals = meta.totals || null;
        /* Jumlah 200 baris yang termuat — hanya untuk footer tabel; ubin
           "Nilai persediaan" membaca total SERVER (meta.totals, dihitung SQL
           atas SELURUH baris terfilter), karena reduce halaman diam-diam
           kurang begitu saldo melewati per_page (T28/T29). */
        const pageValue = rows.reduce((sum, row) => sum + Number(row.stock_value || 0), 0);
        const onHand = totals && totals.on_hand_value != null ? Number(totals.on_hand_value) : pageValue;
        const inTransit = totals ? Number(totals.in_transit_value || 0) : 0;
        /* Barang di jalan tidak berada di gudang mana pun dan angkanya
           se-perusahaan, jadi di bawah filter gudang/pencarian jumlah
           "on-hand terfilter + transit global" bukan angka apa pun — kedua
           ubin transit hanya tampil pada tampilan tanpa filter, saat
           Total dimiliki benar-benar identitas yang terpatri ke GL 1-1400. */
        const showTransit = inTransit > 0 && !state.warehouse && !state.q;
        clear(body).appendChild(el('div', [
          el('.stat-row', [
            el('.stat', [el('.label', { text: 'Nilai persediaan' }), el('.value.sm', { text: fmt.rupiah(onHand) })]),
            showTransit ? el('.stat', [
              el('.label', { text: `Dalam perjalanan · ${Number(totals.in_transit_transfers || 0)} transfer` }),
              el('.value.sm', { text: fmt.rupiah(inTransit) }),
            ]) : null,
            showTransit ? el('.stat', [
              el('.label', { text: 'Total dimiliki' }),
              el('.value.sm', { text: fmt.rupiah(Number(totals.owned_value || 0)) }),
            ]) : null,
            el('.stat', [el('.label', { text: 'Baris saldo' }), el('.value', { text: String(meta.total != null ? meta.total : rows.length) })]),
          ]),
          el('.card', rows.length ? el('.table-wrap', el('table.data', [
            el('thead', el('tr', [
              el('th', { text: 'Item' }), el('th', { text: 'Gudang' }),
              el('th.right', { text: 'Qty' }), el('th.right', { text: 'HPP rata-rata' }), el('th.right', { text: 'Nilai' }),
            ])),
            el('tbody', rows.map((row) => el('tr', [
              el('td', el('span', [
                el('span.cell-main', { text: (row.item || {}).name || '—' }),
                el('span.cell-sub.mono', { text: (row.item || {}).code || '' }),
              ])),
              el('td', { text: (row.warehouse || {}).name || '—' }),
              el('td.right.num', { text: fmt.qty(row.qty, (row.item || {}).unit) }),
              el('td.right.num', { text: fmt.rupiah(row.avg_cost) }),
              el('td.right.num', { text: fmt.rupiah(row.stock_value) }),
            ]))),
            /* Footer tetap jumlah halaman ini — dan diberi label jujur,
               bukan "Total" yang dulu mengaku total padahal cuma 200 baris. */
            el('tfoot', el('tr', [
              el('td', { text: 'Total halaman ini', colspan: 4 }),
              el('td.right', { text: fmt.rupiah(pageValue) }),
            ])),
          ])) : emptyState('Belum ada saldo stok.')),
        ]));
        return;
      }

      if (state.tab === 'ledger') {
        const rows = await api.get('inventory/stock/ledger', {
          warehouse_id: state.warehouse || undefined, q: state.q || undefined, per_page: 200,
        });
        clear(body).appendChild(el('.card', rows.length ? el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: 'Tanggal' }), el('th', { text: 'Item' }), el('th', { text: 'Gudang' }),
            el('th.center', { text: 'Arah' }), el('th.right', { text: 'Qty' }),
            el('th.right', { text: 'HPP satuan' }), el('th.right', { text: 'Saldo setelah' }), el('th', { text: 'Referensi' }),
          ])),
          el('tbody', rows.map((row) => el('tr', [
            el('td', { text: fmt.date(row.trx_date) }),
            el('td', { text: (row.item || {}).name || labelFor('items', row.item_id) || `#${row.item_id}` }),
            el('td', { text: (row.warehouse || {}).name || labelFor('warehouses', row.warehouse_id) || '—' }),
            el('td.center', badge(row.direction === 'in' ? 'Masuk' : 'Keluar', row.direction === 'in' ? 'green' : 'amber')),
            el('td.right.num', { text: fmt.qty(row.qty) }),
            el('td.right.num', { text: fmt.rupiah(row.unit_cost) }),
            el('td.right.num', { text: fmt.qty(row.balance_qty_after) }),
            el('td.mono', { text: String(row.reference_type || '').split('\\').pop() || '—', style: { fontSize: '11.5px' } }),
          ]))),
        ])) : emptyState('Belum ada mutasi stok.')));
        return;
      }

      const rows = await api.get('inventory/stock/low-stock', { warehouse_id: state.warehouse || undefined });
      clear(body).appendChild(el('.card', rows.length ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Item' }), el('th', { text: 'Gudang' }),
          el('th.right', { text: 'Stok' }), el('th.right', { text: 'Minimum' }), el('th.right', { text: 'Kurang' }),
        ])),
        el('tbody', rows.map((row) => el('tr', [
          el('td', el('span', [el('span.cell-main', { text: row.item_name }), el('span.cell-sub.mono', { text: row.item_code })])),
          el('td', { text: row.warehouse_name }),
          el('td.right.num', { text: fmt.qty(row.qty, row.unit) }),
          el('td.right.num', { text: fmt.qty(row.min_stock) }),
          el('td.right.num', { text: fmt.qty(row.shortage_qty), style: { color: 'var(--danger)' } }),
        ]))),
      ])) : emptyState('Semua item berada di atas stok minimum.', { title: 'Stok aman' })));
    } catch (error) {
      clear(body).appendChild(errorState(error, load));
    }
  }

  await load();
}

/* ========================================================= PAYROLL === */
export async function renderPayrollRun(host, { id }) {
  clear(host);
  const def = RESOURCES['hr/payroll-runs'];
  const reload = () => renderPayrollRun(host, { id });

  const data = await loadOrFail(host, async () => ({
    run: await api.get(`hr/payroll-runs/${id}`),
    payslips: await api.get(`hr/payroll-runs/${id}/payslips`).catch(() => []),
  }), reload);
  if (!data) return;

  const { run, payslips } = data;
  await preload(['employees']);

  clear(host);
  host.appendChild(pageHead(
    run.code,
    `${fmt.periodLabel(run.period_year, run.period_month)} · ${run.run_type_label || run.run_type}`,
    [
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      ...houseFormButtons('hr/payroll-runs', run),
      ...actionButtons(def, run, reload),
    ],
    badge(run.status_label || run.status, fmt.statusTone(run.status)),
  ));

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Total bruto' }), el('.value.sm', { text: fmt.rupiah(run.total_gross) })]),
    el('.stat', [el('.label', { text: 'Total potongan' }), el('.value.sm', { text: fmt.rupiah(run.total_deductions) })]),
    el('.stat', [el('.label', { text: 'Total netto' }), el('.value.sm', { text: fmt.rupiah(run.total_net) })]),
    el('.stat', [el('.label', { text: 'Jumlah slip' }), el('.value', { text: String(payslips.length) })]),
  ]));

  if (!payslips.length) {
    host.appendChild(el('.alert.info', 'Belum ada slip gaji. Jalankan "Hitung Payroll" untuk membuatnya.'));
    return;
  }

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Slip gaji' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Karyawan' }),
        el('th.right', { text: 'Gaji pokok' }),
        el('th.right', { text: 'Tunjangan' }),
        el('th.right', { text: 'Lembur' }),
        el('th.right', { text: 'THR' }),
        el('th.right', { text: 'Bruto' }),
        el('th.right', { text: 'BPJS (karyawan)' }),
        el('th.center', { text: 'TER' }),
        el('th.right', { text: 'PPh 21' }),
        el('th.right', { text: 'Netto' }),
        el('th', { text: '' }),
      ])),
      el('tbody', payslips.map((slip) => {
        const employee = slip.employee || {};
        return el('tr', [
          el('td', el('span', [
            el('span.cell-main', { text: employee.name || labelFor('employees', slip.employee_id) || `#${slip.employee_id}` }),
            el('span.cell-sub.mono', { text: employee.code || '' }),
          ])),
          el('td.right.num', { text: fmt.rupiah(slip.basic_salary) }),
          el('td.right.num', { text: fmt.rupiah(slip.allowances_total) }),
          el('td.right.num', { text: fmt.rupiah(slip.overtime_pay) }),
          el('td.right.num', { text: fmt.rupiah(slip.thr_amount) }),
          el('td.right.num', { text: fmt.rupiah(slip.gross_income) }),
          el('td.right.num', { text: fmt.rupiah(slip.bpjs_employee_total) }),
          el('td.center', slip.ter_category ? badge(`${slip.ter_category} · ${fmt.percent(slip.ter_rate)}`) : el('span.muted', { text: 'Ps. 17' })),
          el('td.right.num', {
            text: fmt.rupiah(slip.pph21_amount),
            style: Number(slip.pph21_amount) < 0 ? { color: 'var(--success)' } : {},
          }),
          el('td.right.num.strong', { text: fmt.rupiah(slip.net_pay) }),
          // The slip an employee is actually handed. One per row rather than a
          // bulk export: the run is what gets posted, the slip is what gets given.
          el('td.center', button('', {
            iconName: 'download', size: 'sm', variant: 'ghost', title: 'Unduh slip gaji (PDF)',
            onClick: (event) => downloadPdf(
              `core/print/payslips/${slip.id}`,
              pdfName('slip-gaji', `${employee.code || slip.employee_id}-${run.code}`),
              event.currentTarget,
            ),
          })),
        ]);
      })),
      el('tfoot', el('tr', [
        el('td', { text: 'Total', colspan: 5 }),
        el('td.right', { text: fmt.rupiah(run.total_gross) }),
        el('td', { colspan: 3 }),
        el('td.right', { text: fmt.rupiah(run.total_net) }),
      ])),
    ])),
  ]));

  const december = Number(run.period_month) === 12 && run.run_type === 'regular';
  host.appendChild(el('.alert.info', [
    icon('warn', 15),
    el('div', {
      text: december
        ? 'Periode Desember memakai perhitungan tahunan Pasal 17 (true-up) dikurangi TER yang sudah dipotong Januari–November. Nilai PPh 21 negatif berarti kelebihan potong yang dikembalikan.'
        : 'PPh 21 dipotong dengan Tarif Efektif Rata-rata (TER) sesuai PMK 168/2023; koreksi tahunan dilakukan pada payroll Desember.',
    }),
  ]));

  /* Aturan yang sama dengan layar detail generik (detail.js): tanpa kunci
     approvals di respons, tidak ada kartu. PayrollRunResource belum
     mengirimkannya, dan approvalTimeline(undefined) menulis 'Belum ada riwayat
     persetujuan.' — kalimat yang BOHONG untuk run yang sudah diajukan dan
     disetujui, karena jejaknya memang ada di core_approvals. Diam lebih jujur
     daripada menyangkal; kartunya kembali sendiri begitu resource mengirim. */
  if (Array.isArray(run.approvals)) {
    host.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Riwayat Persetujuan' })),
      el('.card-body', approvalTimeline(run.approvals)),
    ]));
  }
}

/* ========================================================== TICKET === */
export async function renderTicket(host, { id }) {
  clear(host);
  const def = RESOURCES['servicedesk/tickets'];
  const reload = () => renderTicket(host, { id });

  const ticket = await loadOrFail(host, () => api.get(`servicedesk/tickets/${id}`), reload);
  if (!ticket) return;

  await preload(['employees']);

  clear(host);
  host.appendChild(pageHead(
    ticket.title,
    `${ticket.code} · ${ticket.customer_name || ''} · ${(ticket.site || {}).site_name || ''}`,
    [
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      session.can('svc.update') ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'servicedesk/tickets', row: ticket, onSaved: reload }) }) : null,
      ...actionButtons(def, ticket, reload),
    ],
    badge(ticket.status_label || ticket.status, fmt.statusTone(ticket.status, 'ticketStatus')),
  ));

  const slaCard = (label, dueAt, doneAt, breached) => el('.stat', [
    el('.label', { text: label }),
    el('.value.sm', { text: doneAt ? fmt.dateTime(doneAt) : fmt.dateTime(dueAt), style: breached && !doneAt ? { color: 'var(--danger)' } : {} }),
    el('.delta', {
      text: doneAt ? 'Tercapai' : (breached ? 'SLA terlampaui' : `Target ${fmt.relativeDays(dueAt)}`),
      class: doneAt ? 'up' : (breached ? 'down' : ''),
    }),
  ]);

  host.appendChild(el('.stat-row', [
    el('.stat', [
      el('.label', { text: 'Prioritas' }),
      el('.value.sm', { text: ticket.priority_label || ticket.priority }),
      el('.delta', { text: ticket.category_label || ticket.category }),
    ]),
    slaCard('SLA respons', ticket.response_due_at, ticket.first_response_at, ticket.response_breached),
    slaCard('SLA penyelesaian', ticket.resolution_due_at, ticket.resolved_at, ticket.resolution_breached),
    el('.stat', [
      el('.label', { text: 'Teknisi' }),
      el('.value.sm', { text: labelFor('employees', ticket.assigned_to) || 'Belum ditugaskan' }),
    ]),
  ]));

  const main = el('div');
  const side = el('div');

  main.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Deskripsi masalah' })),
    el('.card-body', el('p', { text: ticket.description || '—', style: { margin: 0, whiteSpace: 'pre-wrap' } })),
  ]));

  if (ticket.resolution_notes) {
    main.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Catatan penyelesaian' })),
      el('.card-body', el('p', { text: ticket.resolution_notes, style: { margin: 0, whiteSpace: 'pre-wrap' } })),
    ]));
  }

  const activities = ticket.activities || [];
  main.appendChild(el('.card', [
    el('.card-head', el('h2', { text: `Aktivitas (${activities.length})` })),
    el('.card-body', activities.length
      ? el('.timeline', activities.map((entry) => el('.timeline-item', [
        el('b', { text: entry.activity_type_label || entry.activity_type }),
        // user_name, bukan user.name: TicketActivityResource memipihkan nama
        // pelakunya ke satu kunci dan tidak pernah mengirim objek user, jadi
        // membaca .user membuat SETIAP baris — termasuk yang jelas dikerjakan
        // teknisi — tertulis 'Sistem' dan jejak siapa-mengerjakan-apa hilang.
        el('.meta', { text: `${entry.user_name || 'Sistem'} · ${fmt.dateTime(entry.created_at)}${entry.minutes_spent ? ` · ${entry.minutes_spent} menit` : ''}` }),
        el('.note', { text: entry.body, style: { whiteSpace: 'pre-wrap' } }),
      ])))
      : el('p.muted', { text: 'Belum ada aktivitas.', style: { margin: 0 } })),
  ]));

  side.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Detail tiket' })),
    el('.card-body', el('dl.kv', [
      el('dt', { text: 'Kontrak' }), el('dd', { text: ticket.service_contract_code || '—' }),
      el('dt', { text: 'Kanal' }), el('dd', { text: ticket.channel || '—' }),
      el('dt', { text: 'Pelapor' }), el('dd', { text: ticket.reported_by_name || '—' }),
      el('dt', { text: 'Dilaporkan' }), el('dd', { text: fmt.dateTime(ticket.reported_at) }),
      el('dt', { text: 'Respons pertama' }), el('dd', { text: fmt.dateTime(ticket.first_response_at) }),
      el('dt', { text: 'Diselesaikan' }), el('dd', { text: fmt.dateTime(ticket.resolved_at) }),
      el('dt', { text: 'Ditutup' }), el('dd', { text: fmt.dateTime(ticket.closed_at) }),
    ])),
  ]));

  const reports = await api.get('servicedesk/field-reports', { ticket_id: id }).catch(() => []);
  side.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Berita acara' })),
    el('.card-body', reports.length
      ? el('div', reports.map((report) => el('div', {
        style: { display: 'flex', justifyContent: 'space-between', gap: '8px', padding: '6px 0', cursor: 'pointer' },
        onclick: () => navigate(`d/servicedesk/field-reports/${report.id}`),
      }, [
        el('span.mono', { text: report.code, style: { fontSize: '12.5px' } }),
        badge(report.status_label || report.status, fmt.statusTone(report.status)),
      ])))
      : el('p.muted', { text: 'Belum ada berita acara.', style: { margin: 0, fontSize: '13px' } })),
  ]));

  host.appendChild(el('.detail-grid', [main, side]));
}

/* ===================================================== SUBCONTRACT === */
export async function renderSubcontract(host, { id }) {
  clear(host);
  const def = RESOURCES['subcontract/subcontracts'];
  const reload = () => renderSubcontract(host, { id });

  const data = await loadOrFail(host, async () => ({
    spk: await api.get(`subcontract/subcontracts/${id}`),
    retention: await api.get(`subcontract/subcontracts/${id}/retention`).catch(() => null),
    advance: await api.get(`subcontract/subcontracts/${id}/advance`).catch(() => null),
    claims: await api.get('subcontract/progress-claims', { subcontract_id: id, per_page: 50 }).catch(() => []),
  }), reload);
  if (!data) return;

  const { spk, retention, advance, claims } = data;
  await preload(['projects', 'vendors']);

  clear(host);
  host.appendChild(pageHead(
    spk.code,
    `${spk.title} · ${(spk.vendor || {}).name || ''}`,
    [
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      ...houseFormButtons('subcontract/subcontracts', spk),
      session.can('scm.update') && ['draft', 'rejected'].includes(spk.status)
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'subcontract/subcontracts', row: spk, onSaved: reload }) })
        : null,
      ...actionButtons(def, spk, reload),
      retention && session.can('scm.post') && retention.balance > 0
        ? button('Bayar Retensi', {
          onClick: async () => {
            const values = await promptFields('Pembayaran retensi', [
              { key: 'release_date', label: 'Tanggal pembayaran', type: 'date', required: true, defaultToday: true },
              { key: 'amount', label: 'Jumlah', type: 'currency', required: true, default: retention.balance },
              { key: 'notes', label: 'Catatan', type: 'textarea' },
              {
                /* Temuan #75: sebelum akhir masa pemeliharaan — atau pada SPK
                   yang belum mencatat tanggalnya — server menolak pelepasan
                   tanpa alasan ini, dan alasannya disimpan di baris pelepasan. */
                key: 'override_reason', label: 'Alasan pelepasan dini (override)', type: 'textarea',
                help: retention.defect_liability_until
                  ? `Wajib diisi bila dilepas sebelum ${fmt.date(retention.defect_liability_until)}.`
                  /* "lengkapi tanggalnya di SPK" yang lama menyesatkan: tombol
                     Ubah tersembunyi begitu SPK disetujui, jadi saran itu
                     buntu dan SEMUA pelepasan SPK lama terpaksa override.
                     Kini ada pintunya sendiri (PUT defect-liability, izin
                     scm.update) yang bisa dipakai pada SPK approved/submitted. */
                  : 'SPK belum mencatat akhir masa pemeliharaan — wajib diisi. Atau catat dulu '
                    + 'tanggalnya lewat aksi Catat masa pemeliharaan (bisa walau SPK sudah '
                    + 'disetujui), supaya pelepasan berikutnya tidak perlu override.',
              },
            ], { submitLabel: 'Bayar' });
            if (!values) return;
            try {
              await api.post(`subcontract/subcontracts/${id}/retention-release`, values);
              toast('Pembayaran retensi dicatat.');
              reload();
            } catch (error) {
              toastError(error);
            }
          },
        })
        : null,
      /* Uang muka subkon (temuan #49): satu klaim DP per SPK, disetujui lewat
         alur opname biasa, lalu dicairkan oleh pemegang scm.post + fin.approve
         — pencairan mencetak tagihan yang LANGSUNG approved, alasan gate ganda
         yang sama dengan pelepasan retensi. */
      advance && session.can('scm.create') && spk.status === 'approved' && !advance.claim_id
        ? button('Klaim Uang Muka', {
          onClick: async () => {
            const values = await promptFields('Klaim uang muka (DP)', [
              { key: 'amount', label: 'Jumlah DP (DPP)', type: 'currency', required: true },
              { key: 'claim_date', label: 'Tanggal', type: 'date', required: true, defaultToday: true },
              { key: 'notes', label: 'Catatan', type: 'textarea' },
            ], { submitLabel: 'Buat klaim' });
            if (!values) return;
            try {
              await api.post(`subcontract/subcontracts/${id}/advance-claim`, values);
              toast('Klaim uang muka dibuat; ajukan dan setujui seperti opname biasa.');
              reload();
            } catch (error) {
              toastError(error);
            }
          },
        })
        : null,
      advance && session.can('scm.post') && session.can('fin.approve')
        && advance.claim_status === 'approved' && !advance.paid_out
        ? button('Cairkan Uang Muka', {
          onClick: async () => {
            const values = await promptFields('Pencairan uang muka', [
              { key: 'payout_date', label: 'Tanggal pencairan', type: 'date', required: true, defaultToday: true },
            ], { submitLabel: 'Cairkan' });
            if (!values) return;
            try {
              await api.post(`subcontract/subcontracts/${id}/advance-payout`, values);
              toast('Uang muka dicairkan; tagihan pembayaran diterbitkan.');
              reload();
            } catch (error) {
              toastError(error);
            }
          },
        })
        : null,
    ],
    badge(spk.status_label || spk.status, fmt.statusTone(spk.status)),
  ));

  const claimedGross = claims
    .filter((claim) => claim.status === 'approved')
    .reduce((sum, claim) => sum + Number(claim.gross_amount || 0), 0);
  const progressPct = Number(spk.value) > 0 ? (claimedGross / Number(spk.value)) * 100 : 0;

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Nilai SPK' }), el('.value.sm', { text: fmt.rupiah(spk.value) })]),
    el('.stat', [
      el('.label', { text: 'Sudah diopname' }),
      el('.value.sm', { text: fmt.rupiah(claimedGross) }),
      el('.delta', { text: `${fmt.percent(progressPct)} dari nilai SPK` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Retensi ditahan' }),
      el('.value.sm', { text: fmt.rupiah(retention ? retention.retained : 0) }),
      el('.delta', { text: `Dibayar ${fmt.rupiah(retention ? retention.released : 0)}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Saldo retensi' }),
      el('.value.sm', { text: fmt.rupiah(retention ? retention.balance : 0) }),
    ]),
    advance && advance.claim_id ? el('.stat', [
      el('.label', { text: 'Uang muka (DP)' }),
      el('.value.sm', { text: fmt.rupiah(advance.amount) }),
      el('.delta', {
        text: advance.paid_out
          ? `Belum diperhitungkan ${fmt.rupiah(advance.outstanding)}`
          : (advance.claim_status === 'approved' ? 'Disetujui — belum dicairkan' : 'Menunggu persetujuan'),
      }),
    ]) : null,
    el('.stat', [
      el('.label', { text: 'PPh final konstruksi' }),
      el('.value.sm', { text: fmt.percent(spk.pph_rate) }),
      el('.delta', { text: spk.pph_scheme_label || '' }),
    ]),
    el('.stat', [
      el('.label', { text: 'PPN' }),
      el('.value.sm', { text: fmt.percent(spk.ppn_rate) }),
      el('.delta', { text: (spk.vendor || {}).is_pkp ? 'Vendor PKP' : 'Vendor non-PKP' }),
    ]),
  ]));

  const main = el('div');
  const side = el('div');

  main.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Rincian pekerjaan' })),
    (spk.items || []).length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'ID' }), el('th', { text: 'Kode WBS' }), el('th', { text: 'Uraian' }),
          el('th.right', { text: 'Volume' }), el('th.right', { text: 'Harga satuan' }),
          el('th.right', { text: 'Nilai' }), el('th', { text: 'Progres' }),
        ])),
        el('tbody', spk.items.map((item) => el('tr', [
          el('td.mono', { text: String(item.id) }),
          el('td.code', { text: item.wbs_code || '—' }),
          el('td', { text: item.description }),
          el('td.right.num', { text: fmt.qty(item.qty, item.unit) }),
          el('td.right.num', { text: fmt.rupiah(item.unit_price) }),
          el('td.right.num', { text: fmt.rupiah(item.amount) }),
          el('td', { style: { minWidth: '120px' } }, el('div', [
            el('div.num', { text: fmt.percent(item.progress_pct), style: { fontSize: '11.5px', marginBottom: '3px' } }),
            progressBar(item.progress_pct, Number(item.progress_pct) >= 100 ? 'green' : ''),
          ])),
        ]))),
        el('tfoot', el('tr', [
          el('td', { text: 'Total', colspan: 5 }),
          el('td.right', { text: fmt.rupiah(spk.value) }),
          el('td'),
        ])),
      ]))
      : el('.card-body', el('p.muted', { text: 'Belum ada baris pekerjaan.', style: { margin: 0 } })),
  ]));

  main.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: 'Opname (progress claim)' }),
      el('.spacer'),
      session.can('scm.create') && spk.status === 'approved'
        ? button('Buat opname', {
          size: 'sm', variant: 'primary',
          onClick: () => openForm({ def: RESOURCES['subcontract/progress-claims'], key: 'subcontract/progress-claims', onSaved: reload }),
        })
        : null,
    ]),
    claims.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Kode' }), el('th.center', { text: 'Ke-' }), el('th', { text: 'Periode' }),
          el('th.right', { text: 'Bruto' }), el('th.right', { text: 'Retensi' }),
          el('th.right', { text: 'PPh' }), el('th.right', { text: 'Netto' }), el('th', { text: 'Status' }),
        ])),
        el('tbody', claims.map((claim) => {
          const tr = el('tr.clickable', [
            el('td.code', { text: claim.code }),
            el('td.center.num', { text: String(claim.claim_no) }),
            el('td', { text: `${fmt.date(claim.period_start)} – ${fmt.date(claim.period_end)}` }),
            el('td.right.num', { text: fmt.rupiah(claim.gross_amount) }),
            el('td.right.num', { text: fmt.rupiah(claim.retention_amount) }),
            el('td.right.num', { text: fmt.rupiah(claim.pph_amount) }),
            el('td.right.num.strong', { text: fmt.rupiah(claim.net_payable) }),
            el('td', badge(claim.status_label || claim.status, fmt.statusTone(claim.status))),
          ]);
          tr.addEventListener('click', () => navigate(`d/subcontract/progress-claims/${claim.id}`));
          return tr;
        })),
      ]))
      : el('.card-body', el('p.muted', { text: 'Belum ada opname.', style: { margin: 0 } })),
  ]));

  side.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Informasi SPK' })),
    el('.card-body', el('dl.kv', [
      el('dt', { text: 'Proyek' }), el('dd', { text: labelFor('projects', spk.project_id) || `#${spk.project_id}` }),
      el('dt', { text: 'Subkontraktor' }), el('dd', { text: (spk.vendor || {}).name || '—' }),
      el('dt', { text: 'Periode' }), el('dd', { text: `${fmt.date(spk.start_date)} – ${fmt.date(spk.end_date)}` }),
      el('dt', { text: 'Masa pemeliharaan s/d' }), el('dd', { text: spk.defect_liability_until ? fmt.date(spk.defect_liability_until) : '—' }),
      el('dt', { text: 'Retensi' }), el('dd', { text: fmt.percent(spk.retention_pct) }),
      el('dt', { text: 'Nilai asal (pra-addendum)' }), el('dd', { text: spk.original_value ? fmt.rupiah(spk.original_value) : '—' }),
      el('dt', { text: 'Terbilang' }), el('dd', el('em', { text: spk.value_terbilang || '—' })),
      el('dt', { text: 'Lingkup' }), el('dd', { text: spk.scope || '—' }),
    ])),
  ]));

  /* Lihat catatan pada payroll run di atas: SubcontractResource juga belum
     mengirim approvals, jadi kartu ini dulu selalu berkata 'Belum ada riwayat
     persetujuan.' — bahkan pada SPK yang persetujuan direkturnya justru syarat
     terbitnya. Ikut aturan detail.js: tanpa kunci, tanpa kartu. */
  if (Array.isArray(spk.approvals)) {
    side.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Riwayat Persetujuan' })),
      el('.card-body', approvalTimeline(spk.approvals)),
    ]));
  }

  host.appendChild(el('.detail-grid', [main, side]));
}

/* ========================================================= PAYMENT === */
export async function renderPayment(host, { id }) {
  clear(host);
  const def = RESOURCES['finance/payments'];
  const reload = () => renderPayment(host, { id });

  const payment = await loadOrFail(host, () => api.get(`finance/payments/${id}`), reload);
  if (!payment) return;

  const isIn = payment.direction === 'in';
  const isOut = !isIn;

  /* Uang keluar berjalan draf -> diajukan -> disetujui -> diposting; penerimaan
     tetap draf -> diposting. Yang boleh diubah hanyalah draf dan yang ditolak —
     kalau yang sudah diajukan masih bisa diubah, persetujuannya tidak berarti
     apa-apa. */
  const editable = payment.status === 'draft' || payment.status === 'rejected';
  const awaiting = isOut && payment.status === 'submitted';
  const readyToPost = isOut && payment.status === 'approved';
  const reversed = payment.status === 'reversed';
  const locked = payment.status === 'posted' || reversed || awaiting || readyToPost;
  /* Hanya pembayaran biasa yang terposting. Transfer kas kecil dibalik dengan
     transfer berlawanan arah — server menolak pembalikannya, dan tombol yang
     selalu ditolak lebih buruk daripada tidak ada tombol. */
  const reversible = payment.status === 'posted' && !payment.petty_cash_fund_id;

  // Mengajukan adalah fin.update (menyiapkan dokumen), memposting fin.post.
  const canStage = editable && (isIn ? session.can('fin.post') : session.can('fin.update'));
  // Pembayaran kas kecil tidak mengalokasikan tagihan — daftar terbuka tidak
  // perlu diambil; panelnya digambar kaskecil.js di bawah.
  const openDocs = canStage && !payment.petty_cash_fund_id
    ? await api.get(isIn ? 'finance/ar-invoices' : 'finance/ap-bills', { status: 'approved', per_page: 200 }).catch(() => [])
    : [];

  /* Kewajiban non-AP (gaji, pajak, BPJS) yang boleh dilunasi langsung, beserta
     plafon yang PERSIS akan dipakai guard submit untuk tanggal pembayaran ini —
     layar tidak pernah menawarkan angka yang bakal ditolak server. Hanya uang
     keluar: penerimaan menolak baris gl_account (uang muka pelanggan 2-1400
     menunggu mesin kewajiban kontraknya sendiri). */
  const settleables = canStage && isOut && !payment.petty_cash_fund_id
    ? await api.get('finance/payments/settleable-liabilities', { date: payment.payment_date }).catch(() => [])
    : [];

  const storedAllocations = (payment.allocations || []).map((allocation) => ({
    payable_type: allocation.payable_type,
    payable_id: allocation.payable_id,
    amount: Number(allocation.amount),
    // Referensi baris akun (NTPN/no. bukti) menumpang DI LUAR tanda tangan
    // type#id@cents yang dibandingkan server — teks yang dikoreksi setelah
    // persetujuan tetap ikut terposting tanpa membatalkan persetujuannya.
    ...(allocation.remark ? { remark: allocation.remark } : {}),
  }));

  // Kode dokumennya dipegang terpisah supaya badan POST tetap persis kolom
  // yang dibandingkan server dengan himpunan alokasi yang sudah disetujui.
  const storedCodes = new Map((payment.allocations || []).map((allocation) =>
    [allocation.payable_id, allocation.payable_code || `#${allocation.payable_id}`]));

  const approvals = payment.approvals || [];

  /* Yang ditolak kembali ke meja petugas: alasannya harus jadi hal pertama yang
     dibaca, bukan sesuatu yang perlu dicari di timeline paling bawah. Diurutkan
     sendiri karena relasi approvals() tidak memasang orderBy — sebuah dokumen
     yang ditolak dua kali harus menunjukkan alasan yang TERAKHIR. */
  const lastRejection = payment.status === 'rejected'
    ? [...approvals].sort((a, b) => b.id - a.id).find((entry) => entry.action === 'rejected')
    : null;

  /*
   * Penolakan pemisahan tugas panjangnya tiga kalimat: menyebut nama pengaju,
   * izin yang harus dipegang penyetujunya, dan letak sakelar di Pengaturan.
   * Toast hilang setelah 8 detik dengan separuh kalimat belum terbaca, jadi
   * penolakan dari server menetap di panel ini sampai ditutup atau halaman
   * dimuat ulang.
   */
  const refusalSlot = el('div');
  const clearRefusal = () => clear(refusalSlot);
  const showRefusal = (error) => {
    clear(refusalSlot).appendChild(el('.alert.error', { style: { marginBottom: '14px' } }, [
      icon('warn', 16),
      el('div', { style: { flex: '1' } }, [
        el('div', { text: error.message || String(error) }),
        ...(error.details || []).map((line) => el('.muted', { text: line, style: { fontSize: '12px' } })),
      ]),
      button('', { size: 'sm', variant: 'ghost', iconName: 'close', title: 'Tutup', onClick: clearRefusal }),
    ]));
    refusalSlot.scrollIntoView({ block: 'nearest' });
  };

  const decide = (path, label) => {
    const btn = button(label, { variant: path === 'approve' ? 'success' : 'danger' });
    btn.addEventListener('click', async () => {
      // Bentuk isian yang sama dengan tombol Setujui/Tolak dokumen lain
      // (approvalActions di schema.js). Alasan penolakan divalidasi DI DALAM
      // dialog: kalau dicek setelah dialog tertutup, catatan yang sudah
      // terlanjur diketik ikut hilang dan harus diketik ulang dari nol.
      const values = await promptFields(`${label} ${payment.code}`, [
        path === 'approve'
          ? { key: 'note', label: 'Catatan persetujuan', type: 'textarea' }
          : {
            key: 'note', label: 'Alasan penolakan', type: 'textarea', required: true,
            help: 'Petugas yang menyiapkan harus tahu apa yang perlu diperbaiki.',
          },
      ], { submitLabel: label });

      if (values === null) return;

      await withBusy(btn, async () => {
        try {
          clearRefusal();
          await api.post(`finance/payments/${id}/${path}`, { note: values.note || undefined });
          toast(path === 'approve'
            ? 'Pembayaran disetujui dan siap diposting.'
            : 'Pembayaran ditolak dan dikembalikan ke draf.');
          reload();
        } catch (error) {
          // Penolakan pemisahan tugas datang dari server dalam bahasa
          // Indonesia dan menyebut siapa pengajunya; tampilkan apa adanya.
          showRefusal(error);
        }
      });
    });

    return btn;
  };

  const postApproved = button('Posting Pembayaran', { variant: 'primary' });
  postApproved.addEventListener('click', async () => {
    /* Klik inilah yang benar-benar mengeluarkan uang — jurnalnya langsung
       terbentuk dan tidak ada tombol batal sesudahnya. Persetujuannya sudah
       diberikan orang lain, jadi yang perlu dipastikan tinggal: rekening ini,
       tagihan ini. */
    const confirmed = await confirmDialog({
      title: `Posting ${payment.code}`,
      message: `${fmt.rupiah(payment.amount)} keluar dari ${(payment.bank_account || {}).name || 'rekening ini'} `
        + `untuk melunasi ${[...storedCodes.values()].join(', ') || 'tagihan yang disetujui'}. `
        + 'Jurnalnya langsung terbentuk dan pembayaran tidak dapat diubah lagi.',
      confirmLabel: 'Posting Pembayaran',
      tone: 'primary',
    });
    if (!confirmed) return;

    await withBusy(postApproved, async () => {
      try {
        clearRefusal();
        // Kirim ulang persis alokasi yang disetujui — server menolak himpunan
        // yang berbeda dari yang sudah disetujui.
        await api.post(`finance/payments/${id}/post`, { allocations: storedAllocations });
        toast('Pembayaran diposting dan jurnal dibuat.');
        reload();
      } catch (error) {
        showRefusal(error);
      }
    });
  });

  /* Satu-satunya jalan keluar dari pembayaran yang sudah diposting. Sebelum ada
     ini, penerimaan yang salah alokasi mengunci fakturnya dari pembatalan
     SELAMANYA: ArInvoiceService menolak faktur dengan amount_paid > 0 dan tidak
     ada apa pun yang bisa menurunkannya kembali. Yang diposting adalah jurnal
     PEMBALIK — jurnal aslinya tidak pernah disentuh. */
  const reverseButton = button('Balikkan Pembayaran', { variant: 'danger' });
  reverseButton.addEventListener('click', async () => {
    const values = await promptFields(`Balikkan ${payment.code}`, [{
      key: 'reason', label: 'Alasan pembalikan', type: 'textarea', required: true,
      help: 'Tercatat permanen pada pembayaran dan di jejak audit.',
    }], { submitLabel: 'Balikkan Pembayaran' });

    if (values === null) return;

    await withBusy(reverseButton, async () => {
      try {
        clearRefusal();
        await api.post(`finance/payments/${id}/reverse`, { reason: values.reason });
        toast('Pembayaran dibalik; dokumen yang dilunasinya dibuka kembali.');
        reload();
      } catch (error) {
        showRefusal(error);
      }
    });
  });

  /* Persetujuan yang tidak bisa dilihat lagi setelahnya bukan kontrol. Timeline
     yang sama dengan dokumen lain, dan ditampilkan pada SETIAP status — bukan
     hanya setelah terkunci, karena justru pembayaran yang ditolaklah yang
     alasannya paling perlu dibaca. */
  const trailCard = () => el('.card', [
    el('.card-head', el('h2', { text: 'Riwayat Persetujuan' })),
    el('.card-body', approvalTimeline(approvals)),
  ]);

  clear(host);
  host.appendChild(pageHead(
    payment.code,
    `${payment.direction_label} · ${(payment.bank_account || {}).name || ''} · ${fmt.date(payment.payment_date)}`,
    [
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      ...houseFormButtons('finance/payments', payment),
      editable && session.can('fin.update')
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'finance/payments', row: payment, onSaved: reload }) })
        : null,
      awaiting && session.can('fin.approve') ? decide('approve', 'Setujui') : null,
      awaiting && session.can('fin.approve') ? decide('reject', 'Tolak') : null,
      readyToPost && session.can('fin.post') ? postApproved : null,
      reversible && session.can('fin.post') ? reverseButton : null,
    ],
    badge(payment.status_label || payment.status, fmt.statusTone(payment.status)),
  ));

  if (reversed) {
    host.appendChild(el('.alert.warn', { style: { marginBottom: '14px' } }, [
      icon('warn', 15),
      el('div', { style: { flex: '1' } }, [
        el('div', { text: `Dibalik ${payment.reversed_at ? fmt.dateTime(payment.reversed_at) : ''}` }),
        payment.reversal_reason ? el('div', { text: payment.reversal_reason, style: { marginTop: '3px' } }) : null,
        el('.muted', {
          text: 'Jurnal aslinya tetap berdiri dan jurnal pembaliknya diposting di sebelahnya; '
            + 'dokumen yang dilunasinya sudah dibuka kembali.',
          style: { fontSize: '12px' },
        }),
      ]),
    ]));
  }

  host.appendChild(refusalSlot);

  if (lastRejection) {
    host.appendChild(el('.alert.warn', { style: { marginBottom: '14px' } }, [
      icon('warn', 15),
      el('div', { style: { flex: '1' } }, [
        el('div', { text: `Ditolak oleh ${lastRejection.user ? lastRejection.user.name : 'Sistem'} · ${fmt.dateTime(lastRejection.created_at)}` }),
        lastRejection.note ? el('div', { text: lastRejection.note, style: { marginTop: '3px' } }) : null,
        storedAllocations.length
          ? el('.muted', { text: 'Perbaiki lalu ajukan ulang; alokasi yang sebelumnya diajukan tetap tersimpan.', style: { fontSize: '12px' } })
          : null,
      ]),
    ]));
  }

  /* Tanpa baris ini layar seorang penyetuju yang belum memegang fin.approve —
     dan layar petugas yang menunggu — hanya menampilkan lencana status tanpa
     satu pun tombol, yang terbaca sebagai "layarnya rusak". */
  if (awaiting && !session.can('fin.approve')) {
    host.appendChild(el('.alert.info', { style: { marginBottom: '14px' } }, [
      icon('warn', 15),
      el('div', { text: 'Menunggu persetujuan. Hanya pemegang izin fin.approve — peran finance-manager '
        + 'atau direktur — yang dapat menyetujui atau menolak pembayaran ini, dan bukan orang yang mengajukannya.' }),
    ]));
  }

  if (readyToPost && !session.can('fin.post')) {
    host.appendChild(el('.alert.info', { style: { marginBottom: '14px' } }, [
      icon('warn', 15),
      el('div', { text: 'Sudah disetujui dan menunggu diposting oleh pemegang izin fin.post.' }),
    ]));
  }

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Jumlah' }), el('.value.sm', { text: fmt.rupiah(payment.amount) })]),
    el('.stat', [el('.label', { text: 'Rekening' }), el('.value.sm', { text: (payment.bank_account || {}).bank_name || '—' })]),
    el('.stat', [el('.label', { text: 'Referensi' }), el('.value.sm', { text: payment.reference || '—' })]),
  ]));

  /* Isi ulang / setoran kas kecil: alokasinya SATU baris petty_cash_fund yang
     jumlahnya dikunci aturan imprest oleh server — baik tabel alokasi terkunci
     maupun editor tagihan terbuka di bawah tidak berlaku. Tombol Setujui/Tolak/
     Posting di kepala halaman tetap bekerja: storedAllocations sudah berisi
     baris dananya. */
  if (payment.petty_cash_fund_id) {
    await fundPaymentPanels(host, payment, { canStage, showRefusal, clearRefusal, reload });
    if (isOut && approvals.length) host.appendChild(trailCard());
    return;
  }

  if (locked) {
    // Menyebut tahapnya, bukan sekadar "Alokasi": post() membandingkan badan
    // permintaan dengan HIMPUNAN INI, jadi inilah yang sebenarnya disetujui.
    const allocationsTitle = {
      submitted: 'Alokasi yang diajukan',
      approved: 'Alokasi yang disetujui',
    }[payment.status] || 'Alokasi';

    host.appendChild(el('.card', [
      el('.card-head', el('h2', { text: allocationsTitle })),
      (payment.allocations || []).length
        ? el('.table-wrap', el('table.data', [
          el('thead', el('tr', [el('th', { text: 'Dokumen' }), el('th', { text: 'Tipe' }), el('th.right', { text: 'Jumlah' })])),
          el('tbody', payment.allocations.map((allocation) => {
            const isAccount = allocation.payable_type === 'gl_account';
            return el('tr', [
              el('td.mono', el('span', [
                el('a', {
                  text: allocation.payable_code || `#${allocation.payable_id}`,
                  href: isAccount
                    ? `#/d/finance/accounts/${allocation.payable_id}`
                    : `#/d/finance/${allocation.payable_type === 'ar_invoice' ? 'ar-invoices' : 'ap-bills'}/${allocation.payable_id}`,
                }),
                // Baris akun menyebut kewajibannya dengan kata-kata (payable_label
                // membawa nama akunnya) dan referensinya ikut terbaca di sini —
                // NTPN yang hanya tersimpan di database bukan arsip pajak.
                isAccount && allocation.payable_label ? el('span.cell-sub', { text: allocation.payable_label }) : null,
                allocation.remark ? el('span.cell-sub', { text: allocation.remark }) : null,
              ])),
              el('td', {
                text: isAccount
                  ? 'Akun kewajiban'
                  : (allocation.payable_type === 'ar_invoice' ? 'Invoice AR' : 'Tagihan AP'),
              }),
              el('td.right.num', { text: fmt.rupiah(allocation.amount) }),
            ]);
          })),
        ]))
        : el('.card-body', el('p.muted', { text: 'Tidak ada alokasi.', style: { margin: 0 } })),
    ]));

    if ((payment.withholdings || []).length) {
      host.appendChild(el('.card', [
        el('.card-head', [
          el('h2', { text: 'Potongan oleh pemberi kerja (pajak & denda)' }),
          el('.cell-sub', { text: 'dipotong dari kas, invoice tetap lunas penuh' }),
        ]),
        el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: 'Invoice' }), el('th', { text: 'Jenis' }), el('th', { text: 'Akun' }),
            el('th', { text: 'Bukti potong' }), el('th.right', { text: 'Jumlah' }),
          ])),
          el('tbody', payment.withholdings.map((row) => el('tr', [
            el('td.mono', { text: row.invoice_code || '—' }),
            el('td', { text: row.type_label || row.type }),
            el('td.mono', { text: row.account_code || '—' }),
            el('td', el('span', [
              el('span', { text: row.certificate_no || '—' }),
              row.certificate_date ? el('.cell-sub', { text: fmt.date(row.certificate_date) }) : null,
            ])),
            el('td.right.num', { text: fmt.rupiah(row.amount) }),
          ]))),
        ])),
      ]));
    }

    if (isOut && approvals.length) host.appendChild(trailCard());

    return;
  }

  /* Draf atau ditolak: alokasikan ke dokumen terbuka, lalu ajukan (uang keluar)
     atau posting (penerimaan). */
  const rows = [];
  const accountRows = [];
  /* Kunci himpunan pulihan memuat TIPENYA: id tagihan AP dan id akun COA bisa
     kebetulan bernilai sama, dan tabrakan itu membuat peringatan "tidak dapat
     diisikan kembali" salah sasaran. */
  const restored = new Set();
  const body = el('tbody');
  const accountBody = el('tbody');
  const summary = el('div', { style: { padding: '11px 16px', borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: '18px', fontSize: '13px', fontWeight: '600' } });

  function refresh() {
    const billsAllocated = rows.reduce((sum, row) => sum + (Number(row.input.value) || 0), 0);
    const accountsAllocated = accountRows.reduce((sum, row) => sum + (Number(row.input.value) || 0), 0);
    const allocated = billsAllocated + accountsAllocated;
    const withheld = rows.reduce((sum, row) => sum
      + (row.withholdings || []).reduce((inner, w) => inner + Number(w.amount || 0), 0), 0);

    /* Server menolak himpunan campuran tagihan+akun; mengetik di satu kartu
       karenanya MEMATIKAN isian kartu lainnya — salah alokasinya dicegah di
       layar, bukan ditunggu menjadi penolakan server. */
    if (accountRows.length) {
      rows.forEach((row) => {
        row.input.disabled = accountsAllocated > 0;
        row.fill.disabled = accountsAllocated > 0;
      });
      accountRows.forEach((row) => {
        // Plafon 0 tetap digambar (angkanya menjelaskan dirinya sendiri) tapi
        // tidak bisa diisi — kecuali sedang memulihkan alokasi lama yang
        // plafonnya menyusut, supaya angkanya masih bisa dikoreksi turun.
        const off = billsAllocated > 0 || (row.ceiling <= 0 && !(Number(row.input.value) > 0));
        row.input.disabled = off;
        row.remark.disabled = off;
        row.fill.disabled = billsAllocated > 0 || row.ceiling <= 0;
      });
    }

    // Invarian server: Σ alokasi = kas + Σ potongan. Kas adalah mutasi bank yang
    // sudah tercatat pada pembayaran ini, jadi yang harus cocok adalah selisihnya.
    const cash = allocated - withheld;
    const diff = Number(payment.amount) - cash;

    // append() bukan el(): null yang lolos ke sini dicetak sebagai teks "null".
    clear(summary).append(...[
      el('span', [el('span.muted', { text: 'Dilunasi: ' }), el('span.num', { text: fmt.rupiah(allocated) })]),
      withheld > 0
        ? el('span', [el('span.muted', { text: 'Potongan pajak: ' }), el('span.num', { text: fmt.rupiah(withheld) })])
        : null,
      el('span', [el('span.muted', { text: 'Kas diterima: ' }), el('span.num', { text: fmt.rupiah(cash) })]),
      el('span', {
        text: Math.abs(diff) <= 0.01 ? 'Sesuai mutasi bank ✓' : `Selisih ${fmt.rupiah(diff)}`,
        style: { color: Math.abs(diff) <= 0.01 ? 'var(--success)' : 'var(--danger)' },
      }),
    ].filter(Boolean));
  }

  for (const doc of openDocs) {
    const outstanding = Number(doc.outstanding || 0);
    if (outstanding <= 0) continue;

    const input = el('input', { type: 'number', step: '0.01', min: 0, max: outstanding, placeholder: '0' });
    input.addEventListener('input', refresh);

    // Pembayaran yang ditolak kembali dengan alokasinya utuh: petugas
    // memperbaiki, bukan mengetik ulang dan membuat kesalahan kedua.
    const previous = storedAllocations.find((allocation) => allocation.payable_id === doc.id
      && allocation.payable_type === (isIn ? 'ar_invoice' : 'ap_bill'));
    if (previous) {
      input.value = previous.amount;
      restored.add(`${previous.payable_type}#${previous.payable_id}`);
    }

    const entry = {
      doc,
      input,
      withholdings: [],
      // Dipegang sebagai referensi supaya refresh() bisa mematikannya selagi
      // kartu kewajiban non-AP sedang dipakai.
      fill: button('Lunasi', { size: 'sm', onClick: () => { input.value = outstanding; refresh(); } }),
    };
    const potonganCell = el('td.cell-sub');
    rows.push(entry);

    function paintPotongan() {
      clear(potonganCell);

      if (!entry.withholdings.length) return;

      const total = entry.withholdings.reduce((sum, w) => sum + Number(w.amount || 0), 0);
      potonganCell.append(
        el('div', { text: `Potongan ${fmt.rupiah(total)}`, style: { color: 'var(--warning)' } }),
        el('div', { text: entry.withholdings.map((w) => `${LABEL_POTONGAN[w.type] || w.type} ${fmt.rupiah(w.amount)}`).join(' · ') }),
      );
    }

    /*
     * Pemberi kerja badan usaha WAJIB memotong PPh final jasa konstruksi saat
     * membayar, dan pemilik BUMN/pemerintah memungut sendiri PPN-nya. Uang yang
     * masuk ke bank karenanya selalu lebih kecil dari nilai invoice — tanpa
     * baris ini invoice akan menggantung "kurang bayar" selamanya.
     */
    const aturPotongan = async () => {
      /* 'pajak & lainnya': sejak temuan #15 baris denda/klaim (bukan pajak)
         ikut diisi lewat modal ini — judul lama menjanjikan pajak saja. */
      const values = await promptFields(`Potongan pajak & lainnya — ${doc.code}`, [
        {
          key: 'pph_final', label: 'PPh final dipotong pelanggan', type: 'currency',
          help: 'Jasa konstruksi, PP 9/2022: 1,75%–6% dari DPP tergantung kualifikasi. Kosongkan bila tidak dipotong.',
        },
        {
          key: 'certificate_no', label: 'Nomor bukti potong PPh final',
          help: 'Wajib bila ada potongan PPh final — arsip untuk kredit pajak.',
        },
        { key: 'certificate_date', label: 'Tanggal bukti potong', type: 'date' },
        /* Jasa integrasi sistem — pemasangan jaringan, pemeliharaan perangkat,
           konsultasi teknis — dipotong PPh Pasal 23 2%, BUKAN PPh final. Yang
           satu kredit pajak yang mengurangi PPh Badan, yang lain habis di situ;
           mencampurnya membuat SPT Tahunan salah ke salah satu arah. */
        {
          key: 'pph_23', label: 'PPh 23 jasa dipotong pelanggan', type: 'currency',
          help: 'Jasa non-konstruksi: 2% dari nilai jasa untuk penyedia ber-NPWP.',
        },
        {
          key: 'pph_23_certificate_no', label: 'Nomor bukti potong PPh 23',
          help: 'Wajib bila ada potongan PPh 23 — nomor inilah bukti kredit pajaknya.',
        },
        {
          key: 'ppn_wapu', label: 'PPN dipungut pemilik (wapu)', type: 'currency',
          help: 'Untuk pemberi kerja pemungut PPN — PPN-nya disetor sendiri oleh mereka.',
        },
        /* Temuan #15: denda keterlambatan / potongan lain-lain — bukan pajak.
           Tidak ada bukti potong; ALASAN tertulislah jejak auditnya, dan
           server menolak baris tanpa alasan. */
        {
          key: 'other_deduction', label: 'Potongan lain-lain (denda/klaim)', type: 'currency',
          help: 'Denda keterlambatan (lazim 1‰/hari, plafon 5%) atau klaim yang dipotong pemberi kerja. Kosongkan bila tidak ada.',
        },
        {
          key: 'other_deduction_reason', label: 'Alasan potongan lain-lain',
          help: 'Wajib bila ada potongan lain-lain — mis. "denda keterlambatan 10 hari × 1‰, pasal 12 kontrak".',
        },
      ], { submitLabel: 'Simpan potongan' });

      if (values === null) return;

      const next = [];

      if (Number(values.pph_final) > 0) {
        next.push({
          type: 'pph_final',
          amount: Number(values.pph_final),
          certificate_no: values.certificate_no || null,
          certificate_date: values.certificate_date || null,
        });
      }

      if (Number(values.pph_23) > 0) {
        next.push({
          type: 'pph_23',
          amount: Number(values.pph_23),
          certificate_no: values.pph_23_certificate_no || null,
          certificate_date: values.certificate_date || null,
        });
      }

      if (Number(values.ppn_wapu) > 0) {
        next.push({
          type: 'ppn_wapu',
          amount: Number(values.ppn_wapu),
          certificate_no: null,
          certificate_date: null,
        });
      }

      if (Number(values.other_deduction) > 0) {
        next.push({
          type: 'other_deduction',
          amount: Number(values.other_deduction),
          reason: values.other_deduction_reason || null,
          certificate_no: null,
          certificate_date: null,
        });
      }

      entry.withholdings = next;
      // Yang dilunasi adalah nilai invoice penuh; kas = alokasi − potongan.
      if (next.length && !Number(input.value)) input.value = outstanding;
      paintPotongan();
      refresh();
    };

    body.appendChild(el('tr', [
      el('td.code', el('span', [el('span.cell-main.mono', { text: doc.code }), potonganCell])),
      el('td', { text: (doc.customer || doc.vendor || {}).name || '—' }),
      el('td', { text: fmt.date(doc.due_date) }),
      el('td.right.num', { text: fmt.rupiah(outstanding) }),
      el('td', { style: { width: '170px' } }, input),
      el('td', el('.row-actions', [
        entry.fill,
        isIn ? button('Potongan pajak', { size: 'sm', variant: 'ghost', onClick: aturPotongan }) : null,
      ])),
    ]));
  }

  /* Kartu kedua, hanya uang keluar: kewajiban non-AP dari registry server.
     Satu baris per akun allowlist; plafonnya angka yang PERSIS akan dipakai
     guard submit (saldo diposting s.d. akhir bulan tanggal pembayaran,
     dikurangi klaim pembayaran lain yang belum diposting). */
  for (const account of settleables) {
    const ceiling = Number(account.ceiling || 0);

    const input = el('input', {
      type: 'number', step: '0.01', min: 0, max: ceiling > 0 ? ceiling : null,
      placeholder: '0', 'aria-label': `Alokasi ${account.code}`,
    });
    input.addEventListener('input', refresh);

    const remark = el('input', {
      type: 'text', maxlength: 150, placeholder: 'NTPN / no. bukti (opsional)',
      'aria-label': `Referensi ${account.code}`,
    });

    // Pemulihan pembayaran yang ditolak berlaku juga untuk baris akun —
    // termasuk referensinya, karena NTPN yang diketik ulang adalah NTPN salah
    // ketik yang kedua.
    const previous = storedAllocations.find((allocation) =>
      allocation.payable_type === 'gl_account' && allocation.payable_id === account.account_id);
    if (previous) {
      input.value = previous.amount;
      if (previous.remark) remark.value = previous.remark;
      restored.add(`gl_account#${previous.payable_id}`);
    }

    const entry = {
      account,
      ceiling,
      input,
      remark,
      /* Σ alokasi harus PERSIS sama dengan mutasi bank, jadi "Lunasi" di sini
         mengisi sisa pembayaran yang belum teralokasi (dibatasi plafon) —
         bukan plafonnya bulat-bulat seperti pada tagihan: plafon adalah batas
         atas, bukan tagihan yang harus dihabiskan. */
      fill: button('Lunasi', {
        size: 'sm',
        onClick: () => {
          const others = accountRows.reduce((sum, row) =>
            (row === entry ? sum : sum + (Number(row.input.value) || 0)), 0);
          const sisa = Math.round((Number(payment.amount) - others) * 100) / 100;
          input.value = Math.min(ceiling, Math.max(0, sisa)) || '';
          refresh();
        },
      }),
    };
    accountRows.push(entry);

    accountBody.appendChild(el('tr', [
      el('td', el('span', [
        el('span.cell-main', [el('span.mono', { text: account.code }), ` ${account.name}`]),
        /* PPN keluaran disetor NETONYA: reklas PPN masukan dulu, baru bayar
           kurang bayarnya — tanpa JV itu plafon 2-1300 masih PPN keluaran
           bruto (di demo: Rp 1.067.000.000). */
        account.code === '2-1300'
          ? el('span.cell-sub', { text: 'Kompensasikan PPN masukan lebih dulu (JV reklas Dr 2-1300 / Cr 1-1600) sebelum menyetor — plafon ini masih PPN keluaran bruto; yang dibayar hanya kurang bayar netonya.' })
          : null,
        ceiling <= 0 && !previous
          ? el('span.cell-sub', { text: 'Tidak ada saldo yang bisa dilunasi s.d. akhir bulan tanggal pembayaran.' })
          : null,
      ])),
      el('td.right.num', { text: fmt.rupiah(ceiling), style: ceiling <= 0 ? { color: 'var(--muted)' } : null }),
      el('td', { style: { width: '170px' } }, input),
      el('td', { style: { width: '210px' } }, remark),
      el('td', el('.row-actions', [entry.fill])),
    ]));
  }

  /* Penerimaan langsung diposting; pembayaran keluar diajukan dulu supaya ada
     orang kedua yang melihat tagihan mana yang dilunasi. */
  const stageLabel = isIn
    ? 'Posting Pembayaran'
    : (payment.status === 'rejected' ? 'Ajukan Ulang' : 'Ajukan Pembayaran');
  const postButton = button(stageLabel, { variant: 'primary' });
  postButton.addEventListener('click', async () => {
    const allocations = rows
      .filter((row) => Number(row.input.value) > 0)
      .map((row) => ({
        payable_type: isIn ? 'ar_invoice' : 'ap_bill',
        payable_id: row.doc.id,
        amount: Number(row.input.value),
      }));

    const accountAllocations = accountRows
      .filter((row) => Number(row.input.value) > 0)
      .map((row) => ({
        payable_type: 'gl_account',
        payable_id: row.account.account_id,
        amount: Number(row.input.value),
        remark: row.remark.value.trim() || undefined,
      }));

    // Cermin aturan server, ditolak sebelum menyentuh jaringan — kalimatnya
    // sama persis supaya petugas tidak membaca dua penjelasan berbeda untuk
    // satu aturan. Normalnya tak terjangkau karena refresh() sudah mematikan
    // kartu yang tidak dipakai.
    if (allocations.length && accountAllocations.length) {
      toast('Satu pembayaran melunasi tagihan vendor ATAU kewajiban non-AP, tidak keduanya — pisahkan sesuai mutasi banknya.', { tone: 'err' });
      return;
    }

    const chosen = allocations.length ? allocations : accountAllocations;

    if (!chosen.length) {
      toast('Tentukan minimal satu alokasi.', { tone: 'err' });
      return;
    }

    await withBusy(postButton, async () => {
      try {
        clearRefusal();
        const withholdings = rows.flatMap((row) => Number(row.input.value) > 0
          ? (row.withholdings || []).map((w) => ({ ...w, ar_invoice_id: row.doc.id }))
          : []);

        if (isIn) {
          await api.post(`finance/payments/${id}/post`, withholdings.length
            ? { allocations: chosen, withholdings }
            : { allocations: chosen });
          toast('Penerimaan diposting dan jurnal dibuat.');
        } else {
          await api.post(`finance/payments/${id}/submit`, { allocations: chosen });
          toast('Pembayaran diajukan untuk persetujuan.');
        }

        reload();
      } catch (error) {
        showRefusal(error);
      }
    });
  });

  /*
   * Tagihan yang sudah lunas — atau yang statusnya berubah — tidak muncul lagi
   * di daftar terbuka, jadi alokasinya tidak bisa diisikan kembali. Tanpa baris
   * ini pembayaran yang ditolak diam-diam kehilangan sebagian alokasinya dan
   * petugas hanya melihat "Selisih Rp 45.000.000" tanpa tahu dari mana.
   */
  const dropped = canStage
    ? storedAllocations.filter((allocation) => !restored.has(`${allocation.payable_type}#${allocation.payable_id}`))
    : [];

  if (dropped.length) {
    host.appendChild(el('.alert.warn', { style: { marginBottom: '14px' } }, [
      icon('warn', 15),
      el('div', { text: `${dropped.length} alokasi yang sebelumnya diajukan tidak dapat diisikan kembali karena `
        + 'tagihannya sudah lunas, dibatalkan, atau belum disetujui — atau daftar akun kewajibannya gagal dimuat: '
        + dropped.map((allocation) => `${storedCodes.get(allocation.payable_id)} ${fmt.rupiah(allocation.amount)}`).join(', ')
        + '. Alokasikan ulang sebelum mengajukan.' }),
    ]));
  }

  host.appendChild(el('.card', [
    el('.card-head', [
      el('h2', { text: isIn ? 'Alokasikan ke invoice terbuka' : 'Alokasikan ke tagihan terbuka' }),
      el('.spacer'),
      canStage ? postButton : null,
    ]),
    rows.length
      ? el('div', [
        el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: 'Dokumen' }), el('th', { text: isIn ? 'Pelanggan' : 'Vendor' }),
            el('th', { text: 'Jatuh tempo' }), el('th.right', { text: 'Sisa' }),
            el('th', { text: 'Alokasi' }), el('th', { text: '' }),
          ])),
          body,
        ])),
        // Dengan kartu akun di bawah, garis rekonsiliasinya pindah ke strip
        // bersama setelah KEDUA kartu — jumlahnya milik pembayaran, bukan
        // milik salah satu kartu.
        accountRows.length ? null : summary,
      ])
      // Daftar dokumen terbuka tidak pernah diambil tanpa hak mengajukan, jadi
      // kosongnya di sini berarti "Anda tidak boleh", bukan "tidak ada tagihan".
      : el('.card-body', el('p.muted', {
        text: canStage
          ? 'Tidak ada dokumen terbuka untuk dialokasikan.'
          : 'Anda tidak memiliki hak untuk mengalokasikan pembayaran ini.',
        style: { margin: 0 },
      })),
  ]));

  if (accountRows.length) {
    host.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: 'Bayar kewajiban non-AP (gaji, pajak, BPJS)' }),
        el('.cell-sub', { text: 'Plafon = saldo diposting s.d. akhir bulan tanggal pembayaran, dikurangi pembayaran lain yang belum diposting.' }),
      ]),
      el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Akun kewajiban' }), el('th.right', { text: 'Plafon' }),
          el('th', { text: 'Alokasi' }), el('th', { text: 'Referensi' }), el('th', { text: '' }),
        ])),
        accountBody,
      ])),
    ]));
    host.appendChild(el('.card', summary));
  }

  if (isOut && approvals.length) host.appendChild(trailCard());

  refresh();
}

/* ============================================================ ROLE === */
export async function renderRole(host, { id }) {
  clear(host);
  const reload = () => renderRole(host, { id });

  const data = await loadOrFail(host, async () => ({
    role: await api.get(`iam/roles/${id}`),
    permissions: await api.get('iam/permissions'),
  }), reload);
  if (!data) return;

  const { role, permissions } = data;
  const selected = new Set(role.permissions || []);
  const canEdit = session.can('iam.update') && role.name !== 'admin';

  const MODULE_LABELS = {
    core: 'Core', iam: 'Pengguna & Akses', crm: 'Penjualan', inv: 'Persediaan', ast: 'Aset',
    est: 'Estimasi', prj: 'Proyek', prc: 'Pengadaan', scm: 'Subkontrak',
    hr: 'SDM & Payroll', fin: 'Keuangan', svc: 'Layanan',
  };
  const ACTION_LABELS = {
    view: 'Lihat', create: 'Buat', update: 'Ubah',
    delete: 'Hapus', approve: 'Setujui', post: 'Posting',
  };

  const save = button('Simpan Hak Akses', { variant: 'primary' });
  save.addEventListener('click', async () => {
    await withBusy(save, async () => {
      try {
        await api.post(`iam/roles/${id}/permissions`, { permissions: [...selected] });
        toast('Hak akses peran diperbarui.');
        reload();
      } catch (error) {
        toastError(error);
      }
    });
  });

  clear(host);
  host.appendChild(pageHead(
    role.name,
    `${role.users_count ?? 0} pengguna · ${selected.size} hak akses`,
    canEdit ? [save] : [],
  ));

  if (role.name === 'admin') {
    host.appendChild(el('.alert.info', 'Peran admin memiliki seluruh hak akses dan tidak dapat diubah.'));
  }

  const grid = el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: '14px' } });

  for (const [module, names] of Object.entries(permissions)) {
    const boxes = [];
    const moduleCard = el('.card', [
      el('.card-head', [
        el('h2', { text: MODULE_LABELS[module] || module }),
        el('.spacer'),
        canEdit
          ? button('Semua', {
            size: 'sm', variant: 'ghost',
            onClick: () => {
              const turnOn = boxes.some((box) => !box.checked);
              boxes.forEach((box) => {
                box.checked = turnOn;
                box.dispatchEvent(new Event('change'));
              });
            },
          })
          : null,
      ]),
      el('.card-body', { style: { display: 'grid', gap: '7px' } }, names.map((name) => {
        const action = name.split('.')[1];
        const checkbox = el('input', { type: 'checkbox', disabled: !canEdit });
        checkbox.checked = selected.has(name);
        checkbox.addEventListener('change', () => {
          if (checkbox.checked) selected.add(name);
          else selected.delete(name);
        });
        boxes.push(checkbox);
        return el('label', {
          style: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px', cursor: canEdit ? 'pointer' : 'default' },
        }, [checkbox, ACTION_LABELS[action] || action, el('span.muted.mono', { text: name, style: { marginLeft: 'auto', fontSize: '11px' } })]);
      })),
    ]);
    grid.appendChild(moduleCard);
  }

  host.appendChild(grid);
}

/* ======================================================== EMPLOYEE === */
export async function renderEmployee(host, { id }) {
  clear(host);
  const def = RESOURCES['hr/employees'];
  const reload = () => renderEmployee(host, { id });

  const data = await loadOrFail(host, async () => ({
    employee: await api.get(`hr/employees/${id}`),
    payslips: await api.get(`hr/employees/${id}/payslips`).catch(() => []),
    // Saldo cuti dihitung server (LeaveService::balance). .catch(null):
    // gagalnya endpoint saldo tidak boleh merobohkan halaman karyawan —
    // cukup kartunya yang tidak tampil.
    leaveBalance: await api.get(`hr/employees/${id}/leave-balance`).catch(() => null),
  }), reload);
  if (!data) return;

  const { employee, payslips, leaveBalance } = data;

  clear(host);
  host.appendChild(pageHead(
    employee.name,
    /* Departemen lewat peta enum, bukan mentah. hr_employees.department
       menyimpan kunci ('servis', 'hrga'), dan EmployeeResource meneruskannya
       apa adanya tanpa department_label — jadi baris ini pernah membaca
       "EMP-0007 · Teknisi ELV · servis" sementara kolom Departemen di daftar
       karyawan tepat di sebelahnya menuliskan "Servis" untuk baris yang sama.
       Urutan cadangannya menyalin cells.js: label dari server kalau suatu hari
       ada, kalau tidak peta enums.js, dan enumLabel() mengembalikan nilai
       aslinya untuk kunci yang belum dikenal — kunci tak dikenal lebih jujur
       daripada tanda hubung yang menyangkal kolom NOT NULL ini punya isi. */
    `${employee.code} · ${employee.position} · ${employee.department_label || enumLabel('department', employee.department)}`,
    [
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      session.can('hr.update')
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'hr/employees', row: employee, onSaved: reload }) })
        : null,
    ],
    badge(employee.status === 'active' ? 'Aktif' : 'Resign', fmt.statusTone(employee.status)),
  ));

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Gaji pokok' }), el('.value.sm', { text: fmt.rupiah(employee.base_salary) })]),
    el('.stat', [el('.label', { text: 'Tunjangan tetap' }), el('.value.sm', { text: fmt.rupiah(employee.fixed_allowances_total) })]),
    el('.stat', [
      el('.label', { text: 'PTKP / TER' }),
      el('.value.sm', { text: `${employee.ptkp_status} · ${employee.ter_category || '—'}` }),
    ]),
    el('.stat', [
      el('.label', { text: 'Masa kerja' }),
      el('.value.sm', { text: fmt.date(employee.join_date) }),
      el('.delta', { text: employee.employment_type_label || '' }),
    ]),
  ]));

  const main = el('div');
  const side = el('div');

  main.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Riwayat slip gaji' })),
    payslips.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', [
          el('th', { text: 'Periode' }), el('th', { text: 'Jenis' }),
          el('th.right', { text: 'Bruto' }), el('th.right', { text: 'BPJS' }),
          el('th.right', { text: 'PPh 21' }), el('th.right', { text: 'Netto' }),
        ])),
        el('tbody', payslips.map((slip) => {
          const run = slip.payroll_run || {};
          return el('tr', [
            el('td', { text: fmt.periodLabel(run.period_year, run.period_month) }),
            el('td', { text: run.run_type_label || run.run_type || '—' }),
            el('td.right.num', { text: fmt.rupiah(slip.gross_income) }),
            el('td.right.num', { text: fmt.rupiah(slip.bpjs_employee_total) }),
            el('td.right.num', { text: fmt.rupiah(slip.pph21_amount) }),
            el('td.right.num.strong', { text: fmt.rupiah(slip.net_pay) }),
          ]);
        })),
      ]))
      : el('.card-body', el('p.muted', { text: 'Belum ada slip gaji.', style: { margin: 0 } })),
  ]));

  if (leaveBalance) {
    side.appendChild(el('.card', [
      el('.card-head', el('h2', { text: 'Saldo Cuti Tahunan' })),
      el('.card-body', leaveBalance.eligible
        ? el('dl.kv', [
          el('dt', { text: 'Sisa' }), el('dd.num.strong', { text: `${leaveBalance.remaining} hari` }),
          el('dt', { text: 'Terpakai' }), el('dd.num', { text: `${leaveBalance.used} hari` }),
          el('dt', { text: 'Menunggu persetujuan' }), el('dd.num', { text: `${leaveBalance.pending} hari` }),
          ...(leaveBalance.carried_over ? [el('dt', { text: 'Bawaan tahun lalu' }), el('dd.num', { text: `${leaveBalance.carried_over} hari` })] : []),
          el('dt', { text: 'Tahun hak' }), el('dd', { text: `${fmt.date(leaveBalance.window_start)} – ${fmt.date(leaveBalance.window_end)}` }),
        ])
        // UU 13/2003 Pasal 79: hak cuti belum terbit sebelum 12 bulan.
        : el('p.muted', { text: `Belum berhak cuti tahunan — hak terbit ${fmt.date(leaveBalance.eligible_from)} (12 bulan masa kerja).`, style: { margin: 0 } })),
    ]));
  }

  side.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Data karyawan' })),
    el('.card-body', el('dl.kv', [
      el('dt', { text: 'NIK KTP' }), el('dd.mono', { text: employee.nik_ktp || '—' }),
      el('dt', { text: 'NPWP' }), el('dd.mono', { text: employee.npwp || '—' }),
      el('dt', { text: 'Tanggal lahir' }), el('dd', { text: fmt.date(employee.birth_date) }),
      el('dt', { text: 'BPJS Kesehatan' }), el('dd.mono', { text: employee.bpjs_kesehatan_no || '—' }),
      el('dt', { text: 'BPJS TK' }), el('dd.mono', { text: employee.bpjs_tk_no || '—' }),
      el('dt', { text: 'Bank' }), el('dd', { text: `${employee.bank_name || '—'} ${employee.bank_account_no || ''}` }),
    ])),
  ]));

  const allowances = employee.fixed_allowances || {};
  side.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Tunjangan tetap' })),
    el('.card-body', Object.keys(allowances).length
      ? el('dl.kv', Object.entries(allowances).flatMap(([name, amount]) => [
        el('dt', { text: name }),
        el('dd.num', { text: fmt.rupiah(amount) }),
      ]))
      : el('p.muted', { text: 'Tidak ada tunjangan tetap.', style: { margin: 0 } })),
  ]));

  host.appendChild(el('.detail-grid', [main, side]));
}

/* ============================================== ASSET UTILIZATION === */
/**
 * Riwayat aset — mobilisasi, perawatan, penyusutan.
 *
 * The endpoint behind this has existed since the Assets module was written and
 * nothing ever called it: the asset show() loads only its category and its
 * active deployment, so where a machine has been, what has been done to it, and
 * how its book value got where it is were all unreachable from the screen.
 *
 * That is most of what anybody asks an asset register.
 */
export async function renderAsset(host, { id }) {
  clear(host);
  const def = RESOURCES['assets/assets'];
  const reload = () => renderAsset(host, { id });

  const data = await loadOrFail(host, () => api.get(`assets/assets/${id}/history`), reload);
  if (!data) return;

  const { asset, deployments, maintenances } = data;
  const depreciation = data.depreciation_entries || [];
  const rented = asset.ownership === 'rented';
  await preload(['projects', 'employees', ...(rented ? ['vendors'] : [])]);

  clear(host);
  host.appendChild(pageHead(
    asset.name,
    [asset.code, asset.category?.name, asset.serial_no].filter(Boolean).join(' · '),
    [
      button('', { iconName: 'print', title: 'Cetak', onClick: () => window.print() }),
      ...houseFormButtons('assets/assets', asset),
      ...actionButtons(def, asset, reload),
      session.can('ast.update')
        ? button('Ubah', { iconName: 'edit', onClick: () => openForm({ def, key: 'assets/assets', row: asset, onSaved: reload }) })
        : null,
    ],
    badge(asset.status_label || asset.status, fmt.statusTone(asset.status)),
  ));

  // Fakta pelepasan hidup di aksi, bukan di form — tanpa blok ini tanggal,
  // nilai, dan alasan yang aksi itu wajibkan tidak tampil di mana pun.
  if (asset.status === 'disposed') {
    host.appendChild(el('.alert.warn', {
      text: `Dihapusbukukan ${fmt.date(asset.disposal_date)} — ${asset.disposal_reason || 'tanpa alasan tercatat'}`
        + ` · hasil pelepasan ${fmt.rupiah(asset.disposal_value)}`,
    }));
  }

  /* P5 — dua bentuk kartu ringkas, mengikuti kepemilikan. Alat SEWA tidak
     punya harga perolehan/penyusutan (kolomnya NULL — bergaris, bukan Rp 0:
     alat itu tidak ada di neraca kita), jadi barisnya menyebut fakta sewanya:
     lessor, tarif, periode. */
  host.appendChild(rented
    ? el('.stat-row', [
      el('.stat', [
        el('.label', { text: 'Kepemilikan' }),
        el('.value.sm', { text: asset.ownership_label || 'Sewa' }),
        el('.delta', { text: asset.vendor_id ? (labelFor('vendors', asset.vendor_id) || `vendor #${asset.vendor_id}`) : 'vendor belum tercatat' }),
      ]),
      el('.stat', [
        el('.label', { text: 'Tarif sewa' }),
        el('.value.sm', { text: fmt.rupiah(asset.rental_rate) }),
        asset.rate_basis_label ? el('.delta', { text: asset.rate_basis_label.toLowerCase() }) : null,
      ]),
      el('.stat', [
        el('.label', { text: 'Periode sewa' }),
        el('.value.sm', {
          text: asset.rental_start || asset.rental_end
            ? `${asset.rental_start ? fmt.date(asset.rental_start) : '—'} – ${asset.rental_end ? fmt.date(asset.rental_end) : '—'}`
            : '—',
        }),
      ]),
      el('.stat', [
        el('.label', { text: 'Nilai buku' }),
        el('.value.sm', { text: fmt.rupiah(asset.book_value) }),
        el('.delta', { text: 'alat sewa — tidak di neraca, tidak disusutkan' }),
      ]),
    ])
    : el('.stat-row', [
      el('.stat', [el('.label', { text: 'Harga perolehan' }), el('.value.sm', { text: fmt.rupiah(asset.acquisition_cost) })]),
      el('.stat', [
        el('.label', { text: 'Akumulasi penyusutan' }),
        el('.value.sm', { text: fmt.rupiah(asset.accumulated_depreciation) }),
        el('.delta', { text: `${fmt.rupiah(asset.monthly_depreciation)} / bulan` }),
      ]),
      el('.stat', [el('.label', { text: 'Nilai buku' }), el('.value.sm', { text: fmt.rupiah(asset.book_value) })]),
      el('.stat', [
        el('.label', { text: 'Umur manfaat' }),
        el('.value.sm', { text: `${asset.useful_life_months} bulan` }),
        el('.delta', { text: asset.depreciation_start_date ? `mulai ${fmt.date(asset.depreciation_start_date)}` : 'belum disusutkan' }),
      ]),
    ]));

  const historyCard = (title, rows, headers, cells, empty) => el('.card', [
    el('.card-head', [el('h2', { text: title }), el('.cell-sub', { text: `${rows.length} baris` })]),
    rows.length
      ? el('.table-wrap', el('table.data', [
        el('thead', el('tr', headers.map((header) => el(header.right ? 'th.right' : 'th', { text: header.label })))),
        el('tbody', rows.map((row) => el('tr', cells(row)))),
      ]))
      : el('.card-body', el('p.muted', { text: empty, style: { margin: 0 } })),
  ]);

  host.appendChild(historyCard(
    'Mobilisasi',
    deployments,
    [{ label: 'Kode' }, { label: 'Proyek' }, { label: 'Dari' }, { label: 'Sampai' }, { label: 'Tarif harian', right: true }, { label: 'Status' }],
    (row) => [
      el('td.mono', { text: row.code || '—' }),
      el('td', { text: labelFor('projects', row.project_id) || `#${row.project_id}` }),
      el('td', { text: fmt.date(row.deployed_from) }),
      el('td', { text: row.returned_at ? fmt.date(row.returned_at) : '—' }),
      el('td.right.num', { text: fmt.rupiah(row.daily_rate_internal) }),
      el('td', badge(row.status_label || row.status, fmt.statusTone(row.status))),
    ],
    'Aset ini belum pernah dimobilisasi ke proyek.',
  ));

  // Register BBM & jam alat (deviasi #13) — riwayat pembacaan tampil di
  // tempat mesinnya, di samping mobilisasi yang menampungnya. Register saja:
  // tidak ada rupiah di tabel ini, biaya solar sudah hidup di kas kecil
  // (kategori BbmTol).
  host.appendChild(historyCard(
    'Log BBM & jam alat',
    data.equipment_logs || [],
    [{ label: 'Tanggal' }, { label: 'Mobilisasi' }, { label: 'Hour meter (jam)', right: true }, { label: 'BBM (liter)', right: true }, { label: 'Dicatat oleh' }, { label: 'Catatan' }],
    (row) => [
      el('td', { text: fmt.date(row.log_date) }),
      el('td.mono', { text: row.deployment?.code || '—' }),
      el('td.right.num', { text: row.hour_meter !== null && row.hour_meter !== undefined ? fmt.qty(row.hour_meter) : '—' }),
      el('td.right.num', { text: row.fuel_liters !== null && row.fuel_liters !== undefined ? fmt.qty(row.fuel_liters) : '—' }),
      el('td', { text: row.logged_by_name || '—' }),
      el('td', { text: row.notes || '—' }),
    ],
    'Belum ada log BBM atau jam alat tercatat.',
  ));

  host.appendChild(historyCard(
    'Perawatan',
    maintenances,
    [{ label: 'Kode' }, { label: 'Tanggal' }, { label: 'Jenis' }, { label: 'Uraian' }, { label: 'Biaya', right: true }],
    (row) => [
      el('td.mono', { text: row.code || '—' }),
      el('td', { text: fmt.date(row.maintenance_date) }),
      el('td', { text: row.maintenance_type_label || row.maintenance_type }),
      el('td', { text: row.description || '—' }),
      el('td.right.num', { text: fmt.rupiah(row.cost) }),
    ],
    'Belum ada catatan perawatan.',
  ));

  host.appendChild(historyCard(
    'Penyusutan',
    depreciation,
    [{ label: 'Run' }, { label: 'Periode' }, { label: 'Beban', right: true }, { label: 'Nilai buku setelah', right: true }, { label: 'Status' }],
    (row) => [
      el('td.mono', { text: row.run_code || '—' }),
      // The API hands back "2026-06"; every other date on this screen is
      // written the way an Indonesian reader writes one.
      el('td', { text: /^\d{4}-\d{2}$/.test(row.period || '') ? fmt.periodLabel(...row.period.split('-')) : (row.period || '—') }),
      el('td.right.num', { text: fmt.rupiah(row.amount) }),
      el('td.right.num', { text: fmt.rupiah(row.book_value_after) }),
      el('td', badge(enumLabel('postingStatus', row.run_status) || '—', fmt.statusTone(row.run_status))),
    ],
    // P5 — kalimat kosong yang jujur: pada alat sewa "belum" akan berbohong
    // (menyiratkan suatu saat akan), karena gate ownership di
    // DepreciationService memastikan tidak akan pernah.
    rented
      ? 'Alat sewa tidak pernah disusutkan — biayanya tagihan vendor, bukan penyusutan.'
      : 'Belum ada penyusutan yang diposting untuk aset ini.',
  ));
}

/**
 * Pengakuan pendapatan PSAK 115 — layar telaah dan posting satu periode.
 *
 * Angka di layar ini adalah perhitungan yang akan ditanyakan auditor: harga
 * transaksi, EAC dan sumbernya, biaya, % penyelesaian, pendapatan kumulatif
 * vs tertagih, dan posisi aset/liabilitas kontrak. Kebijakan lengkapnya di
 * docs/KEBIJAKAN-PENDAPATAN.md.
 */
export async function renderRevenueRun(host, { id }) {
  clear(host);
  const reload = () => renderRevenueRun(host, { id });

  const run = await loadOrFail(host, () => api.get(`finance/revenue-recognition/${id}`), reload);
  if (!run) return;

  const draft = run.status === 'draft';

  // Takes the NODE, not the event: by the time an awaited confirm dialog
  // resolves, event.currentTarget has been nulled by the DOM dispatch and
  // withBusy would crash on it.
  const act = (path, okMessage) => async (node) => {
    try {
      await withBusy(node, async () => {
        await api.post(`finance/revenue-recognition/${id}/${path}`);
        toast(okMessage);
        reload();
      });
    } catch (error) {
      toastError(error);
    }
  };

  /* WIP schedule adalah working paper standar yang diminta auditor kontraktor;
     sebelum ada ekspor ini angkanya — justru yang paling ditelaah KAP — harus
     disalin manual dari layar ke Excel. Diekspor dari run.lines yang sudah ada
     di layar, kolom demi kolom sama dengan tabel "Perhitungan per kontrak";
     konvensi berkasnya milik csv.js (pemisah ';', BOM UTF-8, koma desimal). */
  const WIP_EAC_SOURCE = {
    // Cermin label EAC_BADGES di bawah, plus label untuk sumber yang di layar
    // sengaja tanpa lencana karena memang kondisi normalnya.
    rap_approved: 'RAP disetujui',
    rap_unapproved: 'RAP belum disetujui',
    override: 'EAC manajemen',
    none: 'Tanpa estimasi — margin nol',
  };
  const WIP_COLUMNS = [
    { key: 'contract_code', label: 'Kontrak' },
    { key: 'contract_title', label: 'Judul kontrak' },
    { key: 'transaction_price', label: 'Harga transaksi', type: 'currency' },
    { key: 'estimated_total_cost', label: 'EAC', type: 'currency' },
    { key: 'eac_source', label: 'Sumber EAC' },
    { key: 'cost_to_date', label: 'Biaya s.d. kini', type: 'currency' },
    { key: 'progress_pct', label: '% penyelesaian', type: 'percent' },
    { key: 'revenue_cumulative', label: 'Pendapatan kumulatif', type: 'currency' },
    { key: 'billed_cumulative', label: 'Tertagih', type: 'currency' },
    { key: 'contract_balance', label: 'Aset/(liabilitas) kontrak', type: 'currency' },
    { key: 'revenue_adjustment', label: 'Penyesuaian run ini', type: 'currency' },
  ];
  const exportWip = () => {
    const csv = toCsv(
      WIP_COLUMNS.map((column) => column.label),
      (run.lines || []).map((line) => WIP_COLUMNS.map((column) => {
        // Fallback yang sama dengan tabel di layar: baris tanpa kode kontrak
        // tetap membawa identitas, bukan sel kosong di samping angka-angkanya.
        if (column.key === 'contract_code') return line.contract_code || `#${line.contract_id}`;
        if (column.key === 'eac_source') return WIP_EAC_SOURCE[line.eac_source] || line.eac_source || '';
        return csvValue(line, column);
      })),
    );
    downloadCsv(csvFilename(`WIP Schedule ${run.code}`), csv);
  };

  clear(host);
  host.appendChild(pageHead(
    run.code,
    `${fmt.periodLabel(run.period_year, run.period_month)} · persentase penyelesaian (PSAK 115)`,
    [
      button('', { iconName: 'print', title: 'Cetak WIP schedule', onClick: () => window.print() }),
      button('WIP Schedule (CSV)', {
        iconName: 'download',
        title: 'Unduh perhitungan per kontrak run ini sebagai CSV',
        disabled: !(run.lines || []).length,
        onClick: () => exportWip(),
      }),
      draft && session.can('fin.create')
        ? button('Hitung Ulang', { iconName: 'refresh', onClick: (event) => act('recalculate', 'Dihitung ulang.')(event.currentTarget) })
        : null,
      draft && session.can('fin.post')
        ? button('Posting Jurnal', {
          variant: 'primary',
          onClick: async (event) => {
            const node = event.currentTarget;
            const ok = await confirmDialog({
              title: `Posting ${run.code}?`,
              message: 'Angka dihitung ulang dari basis data saat posting (draf hanyalah pratinjau), '
                + 'lalu satu jurnal penyesuaian ditulis pada tanggal akhir periode dan run terkunci.',
              confirmLabel: 'Posting',
              tone: 'primary',
            });
            if (!ok) return;
            await act('post', 'Jurnal pengakuan pendapatan diposting.')(node);
          },
        })
        : null,
    ],
    badge(run.status_label || run.status, fmt.statusTone(run.status)),
  ));

  /* Judul versi cetak — hanya tampil saat print (.print-title di app.css).
     Di layar judulnya kode run; kertas yang dilampirkan KAP sebagai working
     paper harus menyebut nama laporannya, bukan "REV/2026/…" telanjang. */
  host.appendChild(el('.print-title', [
    el('h1', { text: 'WIP Schedule' }),
    el('.desc', { text: `${run.code} · ${fmt.periodLabel(run.period_year, run.period_month)} · pengakuan pendapatan persentase penyelesaian (PSAK 115)` }),
  ]));

  host.appendChild(el('.stat-row', [
    el('.stat', [el('.label', { text: 'Kontrak dihitung' }), el('.value.sm', { text: String(run.lines_count) })]),
    el('.stat', [
      el('.label', { text: 'Penyesuaian run ini' }),
      el('.value.sm', { text: fmt.rupiah(run.total_adjustment) }),
      el('.delta', { text: 'pendapatan dihasilkan − tertagih, selisih vs run terakhir' }),
    ]),
    el('.stat', [
      el('.label', { text: 'Status' }),
      el('.value.sm', { text: run.status_label }),
      run.posted_at ? el('.delta', { text: `diposting ${fmt.dateTime(run.posted_at)}` }) : null,
    ]),
  ]));

  if (draft) {
    host.appendChild(el('.alert.info',
      'Draf: telaah EAC tiap kontrak sebelum posting. EAC dari RAP belum disetujui atau tanpa '
      + 'estimasi (margin nol, para 45) diberi tanda. "Ubah EAC" menyimpan telaah manajemen '
      + 'dan bertahan saat dihitung ulang.'));
  }

  const EAC_BADGES = {
    rap_approved: null,
    rap_unapproved: ['RAP belum disetujui', 'amber'],
    override: ['EAC manajemen', 'blue'],
    none: ['Tanpa estimasi — margin nol', 'red'],
  };

  const overrideEac = (line) => async () => {
    const values = await promptFields(`EAC ${line.contract_code}`, [{
      key: 'eac', label: 'Estimasi total biaya penyelesaian', type: 'currency', required: true,
      help: 'Telaah manajemen atas biaya sampai proyek selesai. Minimal sebesar biaya terjadi.',
    }], { submitLabel: 'Simpan & hitung ulang' });
    if (values === null) return;

    try {
      await api.post(`finance/revenue-recognition/${id}/recalculate`, {
        eac_overrides: { [line.contract_id]: values.eac },
      });
      toast('EAC diperbarui.');
      reload();
    } catch (error) {
      toastError(error);
    }
  };

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Perhitungan per kontrak' })),
    el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Kontrak' }),
        el('th.right', { text: 'Harga transaksi' }),
        el('th.right', { text: 'EAC' }),
        el('th.right', { text: 'Biaya s.d. kini' }),
        el('th.right', { text: '%' }),
        el('th.right', { text: 'Pendapatan kumulatif' }),
        el('th.right', { text: 'Tertagih' }),
        el('th.right', { text: 'Aset/(liab.) kontrak' }),
        el('th.right', { text: 'Penyesuaian' }),
        draft && session.can('fin.create') ? el('th', { text: '' }) : null,
      ])),
      el('tbody', (run.lines || []).map((line) => {
        const flag = EAC_BADGES[line.eac_source];
        const balance = Number(line.contract_balance);

        return el('tr', [
          el('td', el('span', [
            el('span.cell-main.mono', { text: line.contract_code || `#${line.contract_id}` }),
            el('span.cell-sub', { text: line.contract_title || '' }),
          ])),
          el('td.right.num', { text: fmt.rupiah(line.transaction_price) }),
          el('td.right', el('span', [
            el('span.num', { text: fmt.rupiah(line.estimated_total_cost) }),
            flag ? el('div', badge(flag[0], flag[1])) : null,
          ])),
          el('td.right.num', { text: fmt.rupiah(line.cost_to_date) }),
          el('td.right.num', { text: line.eac_source === 'none' ? '—' : `${fmt.num(line.progress_pct, 2)}%` }),
          el('td.right.num', { text: fmt.rupiah(line.revenue_cumulative) }),
          el('td.right.num', { text: fmt.rupiah(line.billed_cumulative) }),
          el('td.right.num', {
            text: fmt.rupiah(balance),
            style: balance < 0 ? { color: 'var(--warning)' } : {},
            title: balance >= 0 ? 'Aset kontrak 1-1360' : 'Liabilitas kontrak 2-1410',
          }),
          el('td.right.num.strong', { text: fmt.rupiah(line.revenue_adjustment) }),
          draft && session.can('fin.create') ? el('td', button('Ubah EAC', { size: 'sm', variant: 'ghost', onClick: overrideEac(line) })) : null,
        ]);
      })),
    ])),
  ]));

  const onerous = (run.lines || []).filter((line) => Number(line.provision_balance) > 0);

  if (onerous.length) {
    host.appendChild(el('.alert.warn',
      `Kontrak merugi (PSAK 237): ${onerous.map((line) => `${line.contract_code} provisi ${fmt.rupiah(line.provision_balance)}`).join(' · ')}. `
      + 'Seluruh taksiran rugi diakui seketika, dilepas seiring kemajuan.'));
  }

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Cara membaca' })),
    el('.card-body', [
      el('p', { text: '% penyelesaian = biaya kumulatif ÷ EAC (metode input biaya-ke-biaya, PSAK 115 B18).' }),
      el('p', { text: 'Pendapatan kumulatif = % × harga transaksi (DPP, termasuk CCO disetujui). Selisihnya terhadap penagihan menjadi aset kontrak (kurang tagih) atau liabilitas kontrak (lebih tagih — termasuk uang muka).' }),
      el('p', { text: 'Posting menulis satu jurnal penyesuaian; invoice termin tetap seperti biasa. Basis pajak (PPN, PPh final) tidak berubah.' }),
    ]),
  ]));
}

export async function renderAssetUtilization(host) {
  clear(host);
  host.appendChild(el('.page-head', [
    el('div', [el('h1', { text: 'Utilisasi Aset' }), el('.desc', { text: 'Hari mobilisasi dan nilai internal per proyek.' })]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderAssetUtilization(host) })]),
  ]));

  const body = el('div');
  host.appendChild(body);

  try {
    const [report] = await Promise.all([api.get('assets/reports/utilization'), preload(['projects', 'assets'])]);
    const rows = Array.isArray(report) ? report : (report.rows || []);

    if (!rows.length) {
      clear(body).appendChild(emptyState('Belum ada data mobilisasi aset.'));
      return;
    }

    const keys = Object.keys(rows[0]);
    const money = keys.filter((key) => /(value|cost|amount|rate)/.test(key));

    clear(body).appendChild(el('.card', el('.table-wrap', el('table.data', [
      el('thead', el('tr', keys.map((key) => el(`th${money.includes(key) ? '.right' : ''}`, {
        text: key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()),
      })))),
      el('tbody', rows.map((row) => el('tr', keys.map((key) => {
        if (money.includes(key)) return el('td.right.num', { text: fmt.rupiah(row[key]) });
        if (key === 'project_id') return el('td', { text: labelFor('projects', row[key]) || `#${row[key]}` });
        if (key === 'asset_id') return el('td', { text: labelFor('assets', row[key]) || `#${row[key]}` });
        // P5 — utilisasi mencakup alat sewa; nilai enumnya diberi label.
        if (key === 'ownership') return el('td', { text: enumLabel('assetOwnership', row[key]) || '—' });
        return el('td', { text: row[key] === null || row[key] === undefined ? '—' : String(row[key]) });
      })))),
    ]))));
  } catch (error) {
    clear(body).appendChild(errorState(error, () => renderAssetUtilization(host)));
  }
}

/* ========================================================= COMPANY === */
export async function renderCompany(host) {
  clear(host);
  const reload = () => renderCompany(host);

  const company = await loadOrFail(host, () => api.get('core/company'), reload);
  if (!company) return;

  const canEdit = session.can('core.update');

  const FIELDS = [
    { key: 'name', label: 'Nama perusahaan', type: 'text', required: true, span: 2 },
    { key: 'legal_name', label: 'Nama badan hukum', type: 'text', span: 2 },
    { key: 'npwp', label: 'NPWP', type: 'text' },
    { key: 'nib', label: 'NIB', type: 'text' },
    { key: 'is_pkp', label: 'Pengusaha Kena Pajak (PKP)', type: 'bool' },
    { key: 'sppkp_number', label: 'Nomor SPPKP', type: 'text' },
    { key: 'address', label: 'Alamat', type: 'textarea', span: 2 },
    { key: 'city', label: 'Kota', type: 'text' },
    { key: 'province', label: 'Provinsi', type: 'text' },
    { key: 'postal_code', label: 'Kode pos', type: 'text' },
    { key: 'phone', label: 'Telepon', type: 'text' },
    { key: 'email', label: 'Email', type: 'text' },
    { key: 'website', label: 'Website', type: 'text' },
  ];

  const controls = {};
  const grid = el('.form-grid');

  for (const spec of FIELDS) {
    const control = buildInput(spec, company[spec.key]);
    controls[spec.key] = control;
    if (!canEdit) {
      (control.input || control.node).setAttribute('disabled', '');
      control.node.querySelectorAll?.('input, select, textarea').forEach((node) => node.setAttribute('disabled', ''));
    }
    const wrapper = spec.type === 'bool'
      ? el('label.field', [el('label', { text: ' ' }), control.node])
      : field(spec.label, control.node, { required: spec.required });
    if (spec.span === 2) wrapper.classList.add('span2');
    grid.appendChild(wrapper);
  }

  const save = button('Simpan Profil', { variant: 'primary' });
  save.addEventListener('click', async () => {
    const payload = Object.fromEntries(Object.entries(controls).map(([key, control]) => [key, control.read()]));
    await withBusy(save, async () => {
      try {
        await api.put('core/company', payload);
        toast('Profil perusahaan disimpan.');
      } catch (error) {
        toastError(error);
      }
    });
  });

  clear(host);
  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Profil Perusahaan' }),
      el('.desc', { text: 'Dipakai pada kop dokumen, faktur pajak dan laporan.' }),
    ]),
    el('.actions', canEdit ? [save] : []),
  ]));

  host.appendChild(el('.card', [
    el('.card-head', el('h2', { text: 'Identitas & kontak' })),
    el('.card-body', grid),
  ]));

  if (!canEdit) {
    host.appendChild(el('.alert.info', 'Anda hanya dapat melihat profil perusahaan.'));
  }
}
