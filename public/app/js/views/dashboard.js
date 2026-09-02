/* Cross-module dashboard.
   Daftar (kartu, antrean persetujuan) tetap ditarik per modul dan diolah
   lokal; ANGKA UANG pada ubin datang dari GET core/dashboard/summary yang
   menjumlah di SQL — reduce sisi klien atas payload per_page:100 diam-diam
   kekurangan hitung begitu dokumen ke-101 ada (Temuan 79). */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, progressBar, emptyState, errorState } from '../ui.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

/* #80 'Proyek saya': status sakelar dasbor, bertahan antar kunjungan. Hanya
   berarti bagi akun yang tertaut karyawan (users.employee_id →
   prj_projects.project_manager_id) — tanpa tautan itu server menjawab kosong
   dengan jujur, jadi sakelarnya tidak ditawarkan sama sekali. */
const MINE_KEY = 'nusantara_erp_dash_mine';

function stat(label, value, { sub, tone, onClick } = {}) {
  const node = el('.stat', [
    el('.label', { text: label }),
    el('.value', { text: value }),
    sub ? el(`.delta${tone ? `.${tone}` : ''}`, { text: sub }) : null,
  ]);
  if (onClick) {
    node.style.cursor = 'pointer';
    node.addEventListener('click', onClick);
  }
  return node;
}

function card(title, body, { action } = {}) {
  return el('.card', [
    el('.card-head', [el('h2', { text: title }), el('.spacer'), action || null]),
    body,
  ]);
}

function miniTable(columns, rows, onRowClick) {
  if (!rows.length) {
    return el('.card-body', el('p.muted', { text: 'Tidak ada data.', style: { margin: 0, fontSize: '13px' } }));
  }
  return el('.table-wrap', el('table.data', [
    el('thead', el('tr', columns.map((column) => el(`th${column.align ? `.${column.align}` : ''}`, { text: column.label })))),
    el('tbody', rows.map((row) => {
      const tr = el(`tr${onRowClick ? '.clickable' : ''}`, columns.map((column) =>
        el(`td${column.align ? `.${column.align}` : ''}`, column.render(row))));
      if (onRowClick) tr.addEventListener('click', () => onRowClick(row));
      return tr;
    })),
  ]));
}

/* Fetch yang tidak pernah melempar — satu 403 tidak boleh menggelapkan seluruh
   dasbor. Yang TIDAK boleh ikut hilang adalah kabar kegagalannya: `.catch(() => [])`
   membuat "sumbernya gagal" dan "sumbernya memang kosong" terbaca persis sama,
   sehingga ubin yang hanya digambar kalau `.length` benar lenyap tanpa jejak.
   Itu bukan teori: saat SQLite berebut kunci, kartu Kalender dan ubin "Termin
   siap ditagih" hilang bergantian sementara dasbornya tetap tampak sehat —
   pembacanya menyimpulkan tidak ada termin yang siap ditagih, padahal ada
   Rp 14,55 miliar pekerjaan yang sudah berhak ditagih dan belum ditagih.

   Array kosong yang dikembalikan sekarang membawa tanda kegagalan: setiap
   .filter/.reduce/.length di bawah tetap bekerja apa adanya (itulah yang
   menjaga dasbor tetap hidup saat satu sumber jatuh), sedangkan ubin dan
   kartunya kini bisa berkata "gagal dimuat" alih-alih menghilang. */
function safe(path, params) {
  return api.get(path, params)
    .then((rows) => rows || [])
    .catch((error) => {
      // Sebab aslinya tetap masuk konsol; layar hanya perlu tahu bahwa
      // angkanya tidak boleh dipercaya.
      console.error(`Dasbor: ${path} gagal dimuat`, error);
      return Object.assign([], { loadFailure: error });
    });
}

/** Error sumber yang gagal, atau null. `[]` dari gerbang izin tetap null. */
const failure = (rows) => (rows && rows.loadFailure) || null;

/* Ubin angka untuk sumber yang gagal: '—', bukan Rp 0. Nol adalah pernyataan
   tentang uang, dan pernyataan itu belum tentu benar — "Hutang belum dibayar
   Rp 0" pada dasbor yang gagal memuat fin/ap-bills adalah kebohongan yang
   rapi. */
const failedStat = (label) => stat(label, '—', { sub: 'Gagal dimuat', tone: 'down' });

/* Badan kartu untuk sumber yang gagal. errorState() yang sama dengan layar lain
   supaya kegagalan terbaca seragam di seluruh aplikasi; baris detail pertama
   yang khas dasbor — kartunya kosong karena pengambilannya gagal, BUKAN karena
   datanya tidak ada. Tombolnya memuat ulang dasbor, karena kegagalan yang
   paling sering terjadi di sini (kunci SQLite) hilang pada percobaan kedua. */
const failedBody = (error, retry) => el('.card-body', errorState({
  message: 'Data kartu ini gagal dimuat.',
  details: ['Jangan dibaca sebagai "tidak ada data" — isinya tidak diketahui.', error.message || String(error)],
}, retry));

/* ----------------------------------------------------- kalender: palet */
/* Palet titik kalender per departemen — 8 slot kategorikal dengan urutan
   TETAP (slot mengikuti urutan kanonik departemen pada kontrak GET
   core/calendar). Urutan slot inilah mekanisme keselamatan buta-warnanya:
   pasangan bersebelahan tervalidasi ΔE-CVD >= 8,4 pada kedua tema (palet
   referensi dataviz, Juli 2026) — jangan diacak ulang, dan jangan "dirapikan"
   ke variabel tema yang hanya punya 6 warna untuk 8 departemen. Identitas
   tidak pernah dibawa warna sendirian: legenda, tooltip, dan daftar agenda
   selalu menulis nama departemennya. Diekspor supaya kalender penuh
   (views/kalender.js) memakai pemetaan yang persis sama. */
export const KALENDER_DEPTS = ['Penjualan', 'Proyek', 'Keuangan', 'SDM', 'Pengadaan', 'Layanan', 'Aset', 'Persediaan'];

const KAL_LIGHT = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
const KAL_DARK = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];

/** Warna titik sebuah departemen — var CSS yang diisi ensureKalenderPalette(). */
export function kalenderDeptColor(department) {
  const index = KALENDER_DEPTS.indexOf(department);
  // Departemen tak dikenal (sumber baru dirilis di server sebelum SPA ini
  // ikut dirilis ulang) tetap mendapat titik — abu-abu, bukan tak terlihat.
  return index === -1 ? 'var(--muted)' : `var(--kal-${index + 1})`;
}

/* Tema gelap butuh step warna sendiri (bukan warna terang yang dibalik), dan
   inline style tidak bisa ikut media query — maka nilainya di-inject sekali
   sebagai <style>, meniru pola app.css: blok [data-theme] menang atas blok
   prefers-color-scheme karena spesifisitasnya lebih tinggi. */
export function ensureKalenderPalette() {
  if (document.getElementById('kal-palette')) return;
  const assign = (steps) => steps.map((hex, index) => `--kal-${index + 1}:${hex}`).join(';');
  document.head.appendChild(el('style#kal-palette', {
    text: `.kal-scope{${assign(KAL_LIGHT)}}`
      + `@media (prefers-color-scheme: dark){.kal-scope{${assign(KAL_DARK)}}}`
      + `:root[data-theme="light"] .kal-scope{${assign(KAL_LIGHT)}}`
      + `:root[data-theme="dark"] .kal-scope{${assign(KAL_DARK)}}`,
  }));
}

/** Titik warna departemen; nama tekstualnya selalu berdiri di sebelahnya. */
function kalDot(department, size = 6) {
  return el('span', {
    style: {
      width: `${size}px`, height: `${size}px`, borderRadius: '50%',
      background: kalenderDeptColor(department), flex: 'none',
    },
  });
}

export async function renderDashboard(host) {
  clear(host);

  // Satu pegangan muat-ulang dipakai bersama oleh tombol segar di kepala
  // halaman DAN oleh setiap tombol "Coba lagi" pada kartu yang gagal, supaya
  // memulihkan satu kartu berarti hal yang sama dengan memuat ulang dasbor.
  const reload = () => renderDashboard(host);

  const user = session.user || {};
  const hour = new Date().getHours();
  const greeting = hour < 11 ? 'Selamat pagi' : hour < 15 ? 'Selamat siang' : hour < 19 ? 'Selamat sore' : 'Selamat malam';

  // Sakelar 'Proyek saya' hanya untuk akun yang tertaut karyawan: tanpa
  // employee_id, mine=1 memang kosong di server (akun itu tidak mengelola
  // proyek apa pun) — menawarkan sakelar yang selalu menjawab nol hanya akan
  // terbaca sebagai dasbor rusak.
  const mineCapable = session.can('prj.view') && Boolean(user.employee_id);
  const mineOnly = mineCapable && localStorage.getItem(MINE_KEY) === '1';

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: `${greeting}, ${String(user.name || '').split(' ')[0]}` }),
      el('.desc', { text: `Ringkasan operasional per ${fmt.dateLong(new Date())}` }),
    ]),
    el('.actions', [
      mineCapable
        ? button('Proyek saya', {
          variant: mineOnly ? 'primary' : 'ghost',
          title: mineOnly
            ? 'Sedang menampilkan proyek yang Anda kelola — klik untuk semua proyek'
            : 'Saring ubin & kartu proyek ke proyek yang Anda kelola',
          onClick: () => {
            localStorage.setItem(MINE_KEY, mineOnly ? '0' : '1');
            reload();
          },
        })
        : null,
      button('', { iconName: 'refresh', title: 'Muat ulang', onClick: reload }),
    ]),
  ]));

  const statRow = el('.stat-row');
  const grid = el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(420px, 1fr))', gap: '16px' } });
  host.append(statRow, grid);

  statRow.append(...Array.from({ length: 4 }, () => el('.stat', el('.skeleton', { style: { height: '40px' } }))));

  const [
    projects, arInvoices, apBills, tickets, purchaseOrders, lowStock, quotations, claims,
    requisitions, subcontracts, boqs, costBudgets, adjustments, payrollRuns, billingReady,
    bankBalances, agenda, summary, inboxPayload,
  ] = await Promise.all([
    session.can('prj.view') ? safe('projects', { per_page: 100, mine: mineOnly ? 1 : undefined }) : [],
    session.can('fin.view') ? safe('finance/ar-invoices', { per_page: 100 }) : [],
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    session.can('svc.view') ? safe('servicedesk/tickets', { per_page: 100 }) : [],
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    session.can('inv.view') ? safe('inventory/stock/low-stock') : [],
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    [], // dulu sumber kotak persetujuan per jenis — kini GET core/inbox di bawah
    // Pekerjaan yang sudah berhak ditagih dan belum ditagih — di data nyata
    // angka ini pernah diam empat bulan pada Rp 14,55 miliar karena tidak ada
    // satu pun layar yang menyebutkannya.
    session.can('fin.view') ? safe('crm/contract-termins/billing-ready') : [],
    // Saldo bank per rekening — bahan pertama pertanyaan "cukupkah kas bulan
    // depan"; proyeksi lengkapnya di Laporan Keuangan › Proyeksi 90 Hari.
    session.can('fin.view') ? safe('finance/reports/bank-balances') : [],
    // Kalender bulan berjalan. Tanpa syarat session.can: rutenya memang tidak
    // bergerbang (aturan yang sama dengan core/deadlines) dan server menyaring
    // per izin .view pemanggil, jadi "kosong" dan "tidak boleh melihat apa
    // pun" tampil sama. Gagal => objek bertanda loadFailure, dan kartunya TETAP
    // digambar sebagai "gagal dimuat": satu kegagalan tidak boleh menggelapkan
    // dasbor, tapi juga tidak boleh menghapus kartunya diam-diam — kartu ini
    // yang lenyap saat SQLite terkunci (pola safe(), tapi meta ikut dibutuhkan
    // di sini sehingga api.list, bukan api.get).
    api.list('core/calendar').catch((error) => {
      console.error('Dasbor: core/calendar gagal dimuat', error);
      return { loadFailure: error };
    }),
    // Angka ubin UANG, dijumlah di SQL atas seluruh tabel (Temuan 79): nilai
    // kontrak proyek berjalan, piutang, dan hutang dulunya reduce atas halaman
    // pertama per_page:100 — begitu dokumen ke-101 ada, angkanya diam-diam
    // terlalu kecil tanpa tanda apa pun. mine ikut dikirim supaya ubin proyek
    // dan kartunya selalu bercerita tentang himpunan yang sama.
    session.can('prj.view') || session.can('fin.view')
      ? safe('core/dashboard/summary', { mine: mineOnly ? 1 : undefined })
      : [],
    // Kotak persetujuan: SATU permintaan untuk semua jenis dokumen di
    // ApprovableDocuments (InboxController), menggantikan 11 permintaan per
    // jenis yang tidak pernah mencakup 17 jenis lainnya — diukur 2 Sep 2026:
    // pengajuan cuti yang menunggu tak terlihat direktur ber-hr.approve.
    api.list('core/inbox').catch((error) => {
      console.error('Dasbor: core/inbox gagal dimuat', error);
      return { loadFailure: error };
    }),
  ]);

  /* ------------------------------------------------------------- tiles */
  const activeProjects = projects.filter((project) => ['active', 'finishing'].includes(project.status));
  const openTickets = tickets.filter((ticket) => !['closed', 'cancelled', 'resolved'].includes(ticket.status));
  const breachedTickets = openTickets.filter((ticket) => ticket.resolution_breached || ticket.response_breached);

  /* Sumber angka ubin uang (Temuan 79): core/dashboard/summary, SUM di SQL.
     Yang pindah ke server: Proyek berjalan (jumlah + nilai kontrak), Piutang
     belum tertagih, Hutang belum dibayar — dulunya reduce atas halaman pertama.
     Yang SENGAJA tetap di klien: Saldo bank dan Termin siap ditagih (kedua
     endpoint-nya sudah agregat SQL penuh tanpa paginasi) dan Tiket aktif
     (hitungan baris yang butuh data per tiket untuk sub-baris SLA-nya). */
  const tiles = failure(summary) ? null : summary;

  clear(statRow);
  /* Setiap ubin di bawah punya dua bentuk: angkanya, atau failedStat() kalau
     sumbernya gagal. Ubin yang gagal TIDAK boleh menghilang dan TIDAK boleh
     menyatakan nol — keduanya sama-sama berbohong kepada pembaca yang tidak
     punya cara tahu bahwa fetch-nya jatuh. */
  if (session.can('prj.view')) {
    // Label ikut sakelarnya: angka yang berganti makna tidak boleh memakai
    // judul yang sama.
    const label = mineOnly ? 'Proyek saya (berjalan)' : 'Proyek berjalan';
    statRow.appendChild(tiles && tiles.projects
      ? stat(label, String(tiles.projects.active_count), {
        sub: `Nilai kontrak ${fmt.rupiahShort(Number(tiles.projects.contract_value || 0))}`,
        onClick: () => navigate('r/projects'),
      })
      : failedStat(label));
  }
  if (session.can('fin.view')) {
    statRow.appendChild(tiles && tiles.ar_invoices
      ? stat('Piutang belum tertagih', fmt.rupiahShort(Number(tiles.ar_invoices.outstanding || 0)), {
        sub: `${tiles.ar_invoices.open_count} invoice terbuka`,
        onClick: () => navigate('r/finance/ar-invoices'),
      })
      : failedStat('Piutang belum tertagih'));
    statRow.appendChild(tiles && tiles.ap_bills
      ? stat('Hutang belum dibayar', fmt.rupiahShort(Number(tiles.ap_bills.outstanding || 0)), {
        sub: `${tiles.ap_bills.open_count} tagihan terbuka`,
        onClick: () => navigate('r/finance/ap-bills'),
      })
      : failedStat('Hutang belum dibayar'));
  }
  // Gagal lebih dulu daripada `.length`: sumber yang jatuh punya length 0, dan
  // cabang lama akan melewatkan ubinnya tanpa suara.
  if (session.can('fin.view') && failure(bankBalances)) {
    statRow.appendChild(failedStat('Saldo bank'));
  } else if (session.can('fin.view') && bankBalances.length) {
    const saldoBank = bankBalances.reduce((sum, row) => sum + Number(row.balance || 0), 0);
    const minus = bankBalances.filter((row) => Number(row.balance || 0) < 0);

    // Negatif ditampilkan apa adanya — BCA demo jujur −232.545.000; angka
    // yang dipangkas ke nol adalah dasbor yang berbohong soal kas.
    statRow.appendChild(stat('Saldo bank', fmt.rupiahShort(saldoBank), {
      sub: minus.length
        ? `${minus.map((row) => row.name || row.code).join(', ')} negatif`
        : `${bankBalances.length} rekening`,
      tone: saldoBank < 0 || minus.length ? 'down' : undefined,
      onClick: () => navigate('r/finance/bank-accounts'),
    }));
  }
  // Ubin inilah yang lenyap saat SQLite terkunci sementara dasbor tampak sehat.
  if (session.can('fin.view') && failure(billingReady)) {
    statRow.appendChild(failedStat('Termin siap ditagih'));
  } else if (session.can('fin.view') && billingReady.length) {
    const siapTagih = billingReady.reduce((sum, row) => sum + Number(row.amount || 0), 0);

    statRow.appendChild(stat('Termin siap ditagih', fmt.rupiahShort(siapTagih), {
      // Umur tunggu, bukan cuma jumlahnya: "3 termin" mudah diabaikan,
      // "menunggu 126 hari" tidak.
      sub: `${billingReady.length} termin · terlama ${billingReady[0].days_waiting} hari`,
      tone: 'down',
      onClick: () => navigate('siap-tagih'),
    }));
  }
  if (session.can('svc.view')) {
    // "SLA aman" atas daftar tiket yang gagal dimuat adalah jaminan palsu.
    statRow.appendChild(failure(tickets)
      ? failedStat('Tiket aktif')
      : stat('Tiket aktif', String(openTickets.length), {
        sub: breachedTickets.length ? `${breachedTickets.length} melewati SLA` : 'SLA aman',
        tone: breachedTickets.length ? 'down' : 'up',
        onClick: () => navigate('r/servicedesk/tickets'),
      }));
  }
  if (!statRow.childElementCount) {
    statRow.appendChild(el('.alert.info', 'Peran Anda tidak memiliki akses ke ringkasan modul mana pun.'));
  }

  /* -------------------------------------------------------- approvals */
  const inboxRows = failure(inboxPayload) ? [] : (inboxPayload.data || []);
  const inboxMeta = failure(inboxPayload) ? {} : (inboxPayload.meta || {});
  const inboxFailed = failure(inboxPayload) ? ['kotak masuk'] : (inboxMeta.failed || []);
  const inboxTotal = failure(inboxPayload) ? 0 : (inboxMeta.total ?? inboxRows.length);
  const INBOX_PREVIEW = 5;

  grid.appendChild(card(
    `Menunggu persetujuan Anda${inboxTotal ? ` (${inboxTotal})` : ''}`,
    el('div', [
      inboxRows.length
        ? miniTable(
          [
            { label: 'Dokumen', render: (r) => el('span', [el('span.cell-main.mono', { text: r.code }), el('span.cell-sub', { text: r.label })]) },
            {
              label: 'Keterangan',
              // Satu baris, dipotong: uraian PR yang dibiarkan membungkus memakan
              // 8 baris per dokumen (2 Sep 2026). Pengaju dan umur antrean di
              // baris kedua, bukan kolom sendiri — tiga kolom muat di 565 px,
              // empat kolom menggulung mendatar dan memotong angka nilainya.
              render: (r) => el('span', [
                el('span.cell-main', { text: r.title || '—', title: r.title || '', style: { display: 'block', maxWidth: '250px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontWeight: '400' } }),
                el('span.cell-sub', { text: [r.submitted_by ? `oleh ${r.submitted_by}` : null, r.days_waiting === null || r.days_waiting === undefined ? null : (r.days_waiting >= 7 ? `menunggu ${r.days_waiting} hari` : `${r.days_waiting} hari`)].filter(Boolean).join(' · ') || '—' }),
              ]),
            },
            { label: 'Nilai', align: 'right', render: (r) => el('span.num', { text: r.amount === null || r.amount === undefined ? '—' : fmt.rupiah(r.amount) }) },
          ],
          inboxRows.slice(0, INBOX_PREVIEW),
          (r) => navigate(r.link.replace(/^#\//, '')),
        )
        : el('.card-body', el('p.muted', {
          // "Tidak ada yang menunggu" adalah pernyataan tentang dunia; bila
          // sumbernya gagal, yang bisa dikatakan hanya bahwa tidak ada yang
          // dapat ditampilkan.
          text: inboxFailed.length ? 'Tidak ada dokumen yang dapat ditampilkan.' : 'Tidak ada dokumen yang menunggu persetujuan.',
          style: { margin: 0, fontSize: '13px' },
        })),
      inboxTotal > INBOX_PREVIEW
        ? el('.card-foot', button(`Lihat semua (${inboxTotal})`, { size: 'sm', onClick: () => navigate('tugas') }))
        : null,
      inboxFailed.length
        ? el('.card-body', { style: { borderTop: '1px solid var(--border)', padding: '10px 16px' } },
          el('.alert.warn', { style: { margin: 0 } }, [
            icon('warn', 16),
            el('div', { text: `Gagal dimuat: ${inboxFailed.join(', ')}. Daftar ini belum lengkap.` }),
          ]))
        : null,
    ]),
    { action: button('Tugas Saya', { size: 'sm', variant: 'ghost', onClick: () => navigate('tugas') }) },
  ));

  /* --------------------------------------------------------- kalender */
  // "Apa yang terjadi KAPAN" — saudara layar Tenggat ("apa yang lewat").
  // Agustus 2026 di data demo hanya berisi 4 agenda (2 Layanan, 1 Penjualan,
  // 1 Keuangan); kartu tetap digambar saat bulan kosong supaya cincin "hari
  // ini" dan pintu ke kalender penuh selalu tersedia.
  if (failure(agenda)) {
    // Kartu inilah yang dilaporkan hilang-timbul saat SQLite berebut kunci.
    // Sekarang ia tetap ada dan mengaku gagal — bulan yang benar-benar kosong
    // sudah punya kalimatnya sendiri di bawah, jadi keduanya tidak lagi
    // tertukar.
    grid.appendChild(card('Kalender Acara', failedBody(failure(agenda), reload)));
  } else if (agenda && agenda.meta && agenda.meta.as_of) {
    ensureKalenderPalette();

    const events = agenda.data || [];
    const meta = agenda.meta;
    // Hari ini menurut JAM SERVER (WIB) — satu-satunya "hari ini" yang boleh
    // dipakai kartu ini. Browser demo tidak selalu di zona Jakarta; new Date()
    // di sini pernah berarti melingkari hari yang salah.
    const asOf = String(meta.as_of);
    const [year, monthNum] = String(meta.month).split('-').map(Number);

    const byDate = new Map();
    events.forEach((event) => {
      if (!byDate.has(event.date)) byDate.set(event.date, []);
      byDate.get(event.date).push(event);
    });

    // Hari ke-0 bulan berikutnya = hari terakhir bulan ini (Agu 2026 -> 31).
    const daysInMonth = new Date(year, monthNum, 0).getDate();
    // Kolom pertama Senin; getDay() memberi Minggu = 0, maka digeser 6.
    const lead = (new Date(year, monthNum - 1, 1).getDay() + 6) % 7;

    const cellStyle = { textAlign: 'center', padding: '3px 0 4px', borderRadius: '6px' };
    const cells = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'].map((name) =>
      el('div.muted', { text: name, style: { ...cellStyle, fontSize: '10.5px' } }));
    for (let i = 0; i < lead; i += 1) cells.push(el('div'));

    for (let day = 1; day <= daysInMonth; day += 1) {
      // Perbandingan string YYYY-MM-DD, tanpa Date.parse: string tanggal polos
      // diparse sebagai tengah malam UTC, lalu getDate() lokal bisa mundur
      // sehari di barat UTC — cincinnya pindah hari, titiknya ikut.
      const dateStr = `${meta.month}-${String(day).padStart(2, '0')}`;
      const dayEvents = byDate.get(dateStr) || [];
      const isToday = dateStr === asOf;

      cells.push(el('div', {
        // Judul lengkap lewat tooltip; titik dibatasi 4 — sel pada kartu
        // 330px hanya selebar ±40px.
        title: dayEvents.map((event) => `${event.title} — ${event.department}`).join('\n') || null,
        style: {
          ...cellStyle,
          fontSize: '11.5px',
          ...(isToday ? {
            background: 'var(--primary-soft)',
            color: 'var(--primary)',
            fontWeight: '600',
            boxShadow: 'inset 0 0 0 1px var(--primary)',
          } : {}),
        },
      }, [
        el('div.num', { text: String(day) }),
        el('div', { style: { display: 'flex', gap: '2px', justifyContent: 'center', minHeight: '5px', marginTop: '1px' } },
          dayEvents.slice(0, 4).map((event) => kalDot(event.department, 5))),
      ]));
    }

    // Legenda dari meta.departments — hitungan PRA-cap per pemanggil, jadi
    // angkanya tetap jujur walau bulan terpotong di 500 agenda.
    const rank = (department) => {
      const index = KALENDER_DEPTS.indexOf(department);
      return index === -1 ? KALENDER_DEPTS.length : index;
    };
    const legendEntries = Object.entries(meta.departments || {})
      .sort(([a], [b]) => rank(a) - rank(b));

    // "Mendatang" diiris dengan as_of server, dan data sudah terurut
    // (tanggal, departemen, kode) dari server — tinggal ambil 5 teratas.
    const upcoming = events.filter((event) => event.date >= asOf).slice(0, 5);

    // Selisih hari dihitung terhadap as_of, bukan jam browser (fmt.relativeDays
    // memakai new Date() — dilarang di kartu ini). Dua string YYYY-MM-DD sama-
    // sama diparse sebagai tengah malam UTC, selisihnya kelipatan bulat
    // 86.400.000 ms, jadi Math.round hanya jaring pengaman.
    const hariLagi = (eventDate) => {
      const days = Math.round((new Date(eventDate) - new Date(asOf)) / 86400000);
      if (days === 0) return 'hari ini';
      if (days === 1) return 'besok';
      return `${days} hari lagi`;
    };

    const tanggalPendek = (iso) => {
      const [y, m, d] = String(iso).split('-').map(Number);
      return y && m && d ? `${String(d).padStart(2, '0')} ${fmt.MONTHS_SHORT[m - 1]} ${y}` : '—';
    };

    const upcomingTable = upcoming.length
      ? miniTable(
        [
          /* Dari potongan string, bukan fmt.date: new Date('YYYY-MM-DD')
             diparse UTC, jadi peramban ber-zona negatif menulis '04 Agu' di
             samping titik grid tanggal 5 — satu agenda tampil di dua hari
             dalam satu kartu. */
          { label: 'Tanggal', render: (event) => el('span', [el('span.cell-main', { text: tanggalPendek(event.date) }), el('span.cell-sub', { text: hariLagi(event.date) })]) },
          {
            label: 'Agenda',
            render: (event) => el('span', [
              el('span.cell-main', { text: event.title }),
              // Untuk agenda bernama (kunjungan PM) code == title; menulis dua
              // kali hanya memakan tempat.
              event.code && event.code !== event.title ? el('span.cell-sub.mono', { text: event.code }) : null,
            ]),
          },
          {
            label: 'Departemen',
            // Nama departemen ditulis sebagai teks biasa dan titiknya hanya
            // pengiring: teks berwarna seri tidak dijamin kontrasnya di atas
            // permukaan kartu (kuning #eda100 di tema terang ~1,9:1).
            render: (event) => el('span.cell-sub', { style: { display: 'inline-flex', alignItems: 'center', gap: '5px' } }, [kalDot(event.department), event.department]),
          },
        ],
        upcoming,
        // Setiap agenda menunjuk layar SPA yang SUDAH ada (r/..., siap-tagih,
        // periods, ...) — bukan ke #/kalender yang belum dirutekan.
        (event) => navigate(event.link),
      )
      : el('.card-body', el('p.muted', {
        text: events.length
          ? 'Tidak ada agenda tersisa bulan ini.'
          : 'Tidak ada agenda bulan ini pada modul yang boleh Anda lihat.',
        style: { margin: 0, fontSize: '13px' },
      }));

    const kalCard = card('Kalender Acara', el('div', [
      el('.card-body', { style: { paddingBottom: '12px' } }, [
        el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', gap: '2px' } }, cells),
        legendEntries.length
          ? el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px 12px', marginTop: '10px' } },
            legendEntries.map(([department, count]) => el('span.cell-sub', { style: { display: 'inline-flex', alignItems: 'center', gap: '5px' } }, [kalDot(department), `${department} ${count}`])))
          : null,
        meta.capped
          ? el('p.cell-sub', {
            text: `Menampilkan ${meta.count} dari ${meta.total} agenda bulan ini — sisanya terpotong.`,
            style: { color: 'var(--warning)', margin: '8px 0 0' },
          })
          : null,
      ]),
      upcomingTable,
      // Tautan <a> polos, sengaja bukan tombol navigate(): rute #/kalender baru
      // didaftarkan lewat seam SETELAH tim lain melepas app.js. Selama belum
      // terdaftar, fallback router menampilkan 'Halaman "kalender" tidak
      // ditemukan' plus tombol "Ke dasbor" (fallback() di app.js) — terdegradasi
      // rapi, bukan layar kosong, jadi tautan ini aman dirilis lebih dulu.
      el('.card-body', { style: { borderTop: '1px solid var(--border)', padding: '10px 16px' } },
        el('a', { href: '#/kalender', text: 'Buka kalender lengkap' })),
    ]), { action: el('span.cell-sub', { text: fmt.periodLabel(year, monthNum) }) });

    kalCard.classList.add('kal-scope');
    grid.appendChild(kalCard);
  }

  /* --------------------------------------------------------- projects */
  // Judul kartu ikut sakelar 'Proyek saya' — daftarnya memang sudah tersaring
  // di server (param mine pada fetch projects di atas).
  const progressTitle = mineOnly ? 'Progres proyek saya' : 'Progres proyek';
  if (session.can('prj.view') && failure(projects)) {
    grid.appendChild(card(progressTitle, failedBody(failure(projects), reload)));
  } else if (session.can('prj.view') && activeProjects.length) {
    grid.appendChild(card(progressTitle, miniTable(
      [
        { label: 'Proyek', render: (row) => el('span', [el('span.cell-main', { text: row.name }), el('span.cell-sub.mono', { text: row.code })]) },
        {
          label: 'Progres',
          render: (row) => {
            const actual = Number(row.actual_progress_pct || 0);
            const planned = Number(row.planned_progress_pct || 0);
            return el('div', { style: { minWidth: '140px' } }, [
              el('div', { style: { display: 'flex', justifyContent: 'space-between', fontSize: '11.5px', marginBottom: '3px' } }, [
                el('span.num', { text: fmt.percent(actual) }),
                el('span.muted.num', { text: `rencana ${fmt.percent(planned)}` }),
              ]),
              progressBar(actual, actual + 0.01 < planned ? 'amber' : 'green'),
            ]);
          },
        },
      ],
      activeProjects.slice(0, 6),
      (row) => navigate(`d/projects/${row.id}`),
    ), { action: button('Semua', { size: 'sm', variant: 'ghost', onClick: () => navigate('r/projects') }) }));
  }

  /* ------------------------------------------------------- receivables */
  if (session.can('fin.view') && failure(arInvoices)) {
    // Kartu ini selalu digambar, jadi kegagalannya tidak menghilangkan apa pun
    // — yang berbahaya justru sebaliknya: tabel kosong dengan tulisan "Tidak
    // ada data" di atas piutang yang sebetulnya jatuh tempo.
    grid.appendChild(card('Piutang jatuh tempo terdekat', failedBody(failure(arInvoices), reload)));
  } else if (session.can('fin.view')) {
    const overdue = arInvoices
      .filter((invoice) => invoice.status === 'approved' && Number(invoice.outstanding) > 0)
      .sort((a, b) => String(a.due_date).localeCompare(String(b.due_date)));

    grid.appendChild(card('Piutang jatuh tempo terdekat', miniTable(
      [
        { label: 'Invoice', render: (row) => el('span', [el('span.cell-main.mono', { text: row.code }), el('span.cell-sub', { text: (row.customer || {}).name || '' })]) },
        {
          label: 'Jatuh tempo',
          render: (row) => {
            const late = new Date(row.due_date) < new Date();
            return el('span', [
              el('span', { text: fmt.date(row.due_date) }),
              el('span.cell-sub', { text: fmt.relativeDays(row.due_date), style: late ? { color: 'var(--danger)' } : {} }),
            ]);
          },
        },
        { label: 'Sisa', align: 'right', render: (row) => el('span.num', { text: fmt.rupiah(row.outstanding) }) },
      ],
      overdue.slice(0, 6),
      (row) => navigate(`d/finance/ar-invoices/${row.id}`),
    ), { action: button('Umur piutang', { size: 'sm', variant: 'ghost', onClick: () => navigate('reports') }) }));
  }

  /* ------------------------------------------------------------ tickets */
  if (session.can('svc.view') && failure(tickets)) {
    grid.appendChild(card('Tiket layanan aktif', failedBody(failure(tickets), reload)));
  } else if (session.can('svc.view') && openTickets.length) {
    grid.appendChild(card('Tiket layanan aktif', miniTable(
      [
        { label: 'Tiket', render: (row) => el('span', [el('span.cell-main', { text: row.title }), el('span.cell-sub.mono', { text: row.code })]) },
        {
          label: 'Prioritas',
          render: (row) => badge(row.priority_label || row.priority,
            { critical: 'red', high: 'amber', medium: 'blue', low: '' }[row.priority] || ''),
        },
        {
          label: 'SLA',
          render: (row) => (row.resolution_breached
            ? badge('Terlampaui', 'red')
            : el('span.cell-sub', { text: fmt.relativeDays(row.resolution_due_at) })),
        },
      ],
      openTickets.slice(0, 6),
      (row) => navigate(`d/servicedesk/tickets/${row.id}`),
    ), { action: button('Semua', { size: 'sm', variant: 'ghost', onClick: () => navigate('r/servicedesk/tickets') }) }));
  }

  /* ---------------------------------------------------------- low stock */
  if (session.can('inv.view') && failure(lowStock)) {
    // Judulnya tanpa hitungan: "(0)" pada sumber yang gagal adalah janji bahwa
    // tidak ada item di bawah minimum, dan janji itu belum bisa dibuat.
    grid.appendChild(card('Stok di bawah minimum', failedBody(failure(lowStock), reload)));
  } else if (session.can('inv.view') && lowStock.length) {
    grid.appendChild(card(`Stok di bawah minimum (${lowStock.length})`, miniTable(
      [
        { label: 'Item', render: (row) => el('span', [el('span.cell-main', { text: row.item_name }), el('span.cell-sub.mono', { text: row.item_code })]) },
        { label: 'Gudang', render: (row) => el('span', { text: row.warehouse_name }) },
        { label: 'Stok / min.', align: 'right', render: (row) => el('span.num', [el('span', { text: fmt.qty(row.qty, row.unit) }), el('span.cell-sub', { text: `min ${fmt.qty(row.min_stock)}` })]) },
      ],
      lowStock.slice(0, 6),
      () => navigate('stock'),
    )));
  }
}
