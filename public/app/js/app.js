/* Application shell: login gate, layout, navigation, routing. */

import { api, session, login, logout, refreshMe, setUnauthorizedHandler } from './api.js';
import { notificationBell, startNotificationPolling, stopNotificationPolling } from './notifications.js';
import { el, clear, button, icon, toast, toastError, field, withBusy, setFieldError, modal } from './ui.js';
import { initials } from './format.js';
import { NAV, RESOURCES } from './schema.js';
import { route, fallback, navigate, start, currentPath } from './router.js';
import { loadPrintForms, invalidatePrintForms } from './printcatalog.js';
import { renderList } from './views/list.js';
import { renderDetail } from './views/detail.js';
import { renderDashboard } from './views/dashboard.js';
import { renderProject } from './views/project.js';
import { renderReports } from './views/reports.js';
import {
  renderStock, renderPayrollRun, renderTicket, renderSubcontract,
  renderPayment, renderRole, renderEmployee, renderAsset, renderAssetUtilization, renderCompany, renderRevenueRun,
} from './views/custom.js';
import { renderSettings } from './views/settings.js';
import { renderTaxExport } from './views/taxexport.js';
import { renderBankRecon } from './views/bankrecon.js';
import { renderLapangan } from './views/lapangan.js';
import { renderK3 } from './views/k3.js';
import { renderEvm } from './views/evm.js';
import { renderDefects } from './views/defect.js';
import { renderMasterData } from './views/masterdata.js';
import { renderDocumentImport } from './views/dokumenimpor.js';
import { openSearch, registerSearchShortcut } from './search.js';
import { renderSlaBreaches } from './views/slabreaches.js';
import { renderRetensi } from './views/retensi.js';
import { renderSiapTagih } from './views/siaptagih.js';
import { renderPeriods } from './views/periods.js';
import { renderVarian } from './views/varian.js';
import { renderHargaSatuan } from './views/hargasatuan.js';
import { renderPoOutstanding } from './views/pooutstanding.js';
import { renderTenggat } from './views/tenggat.js';
import { renderSertifikat } from './views/sertifikat.js';
import { renderAbsensi } from './views/absensi.js';
import { renderKalender } from './views/kalender.js';
import { renderKasKecil } from './views/kaskecil.js';
import { renderBukuBesar } from './views/bukubesar.js';
import { renderKalenderPajak } from './views/kalenderpajak.js';
import { renderEkualisasi } from './views/ekualisasi.js';
import { renderGaleriProyek } from './views/galeriproyek.js';
import { renderPipeline } from './views/pipeline.js';
import { renderRfq } from './views/rfq.js';

const root = document.getElementById('root');
const THEME_KEY = 'nusantara_erp_theme';
const NAV_STATE_KEY = 'nusantara_erp_nav';

/* ------------------------------------------------------------------ theme */
function applyTheme(theme) {
  if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
  else delete document.documentElement.dataset.theme;
}

function cycleTheme() {
  const order = ['system', 'light', 'dark'];
  const current = localStorage.getItem(THEME_KEY) || 'system';
  const next = order[(order.indexOf(current) + 1) % order.length];
  localStorage.setItem(THEME_KEY, next);
  applyTheme(next);
  toast(`Tema: ${{ system: 'mengikuti sistem', light: 'terang', dark: 'gelap' }[next]}`, { timeout: 2200 });
}

applyTheme(localStorage.getItem(THEME_KEY) || 'system');

/* ------------------------------------------------------------------ login */
function renderLogin({ message } = {}) {
  clear(root);
  root.className = '';

  const emailInput = el('input', { type: 'email', autocomplete: 'username', required: true, placeholder: 'nama@nusantara.test' });
  const passwordInput = el('input', { type: 'password', autocomplete: 'current-password', required: true, placeholder: '••••••••' });
  const submit = button('Masuk', { variant: 'primary', type: 'submit' });

  const form = el('form', { novalidate: true }, [
    field('Email', emailInput, { required: true }),
    field('Kata sandi', passwordInput, { required: true }),
    submit,
  ]);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFieldError(emailInput, '');
    setFieldError(passwordInput, '');

    if (!emailInput.value.trim() || !passwordInput.value) {
      setFieldError(emailInput.value.trim() ? passwordInput : emailInput, 'Wajib diisi.');
      return;
    }

    await withBusy(submit, async () => {
      try {
        await login(emailInput.value.trim(), passwordInput.value);
        /* Katalog formulir rumah disaring izin DI SERVER dan di-cache untuk
           "satu sesi" — dan sesinya berganti tepat di baris ini, bukan di mana
           pun yang lain. Tanpa pembatalan ini, orang kedua yang masuk di tab
           yang sama mewarisi daftar dokumen orang pertama: tombol yang selalu
           membalas 403, dan — lebih buruk — tombol yang seharusnya ada tapi
           tidak muncul karena orang sebelumnya tidak boleh mencetaknya.

           DI SINI, bukan hanya di tombol Keluar: sesi yang berakhir karena 401
           (token kedaluwarsa, dicabut admin) tidak pernah melewati logout()
           sama sekali — api.js membersihkan sesi lalu memanggil
           onUnauthorized(). Masuk adalah satu-satunya pintu yang dilewati
           SEMUA jalan. */
        invalidatePrintForms();
        boot();
      } catch (error) {
        setFieldError(passwordInput, error.status === 401 ? error.message : '');
        toastError(error);
      }
    });
  });

  const fill = (email) => {
    emailInput.value = email;
    passwordInput.value = 'password';
    passwordInput.focus();
  };

  root.appendChild(el('.login', el('.login-card', [
    el('.login-brand', [
      el('img', { src: 'favicon.svg', alt: '', width: 38, height: 38 }),
      el('div', [el('strong', { text: 'Nusantara ERP' }), el('span', { text: 'Konstruksi & Integrasi Sistem' })]),
    ]),
    el('h1', { text: 'Masuk ke akun Anda' }),
    el('.sub', { text: 'Gunakan email dan kata sandi yang diberikan administrator.' }),
    message ? el('.alert.info', { style: { marginBottom: '14px' } }, message) : null,
    form,
    el('.login-hint', [
      el('div', { text: 'Akun demo (kata sandi: password):' }),
      el('div', [
        el('code', { text: 'admin@nusantara.test', onclick: () => fill('admin@nusantara.test') }), ' · ',
        el('code', { text: 'direktur@nusantara.test', onclick: () => fill('direktur@nusantara.test') }), ' · ',
        el('code', { text: 'finance@nusantara.test', onclick: () => fill('finance@nusantara.test') }), ' · ',
        el('code', { text: 'project-manager@nusantara.test', onclick: () => fill('project-manager@nusantara.test') }),
      ]),
    ]),
  ])));

  emailInput.focus();
}

/* ------------------------------------------------------------------ shell */
/**
 * Groups are gated by their own permission; an item may carry one too.
 *
 * A group whose own permission fails is still shown when one of its items is
 * individually permitted — which is how "Impor Data Master" reaches a warehouse
 * officer who has inv.create but no business with the rest of Sistem.
 */
function visibleNav() {
  return NAV
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.perm || session.can(item.perm)),
    }))
    .map((group) => ({
      ...group,
      // An item with its own permission has already been checked; the group
      // permission only gates the items that do not declare one.
      items: group.perm && !session.can(group.perm)
        ? group.items.filter((item) => item.perm)
        : group.items,
    }))
    .filter((group) => group.items.length > 0);
}

function buildShell() {
  clear(root);
  root.className = '';

  const user = session.user || {};
  const main = el('main.main', { id: 'view' });

  const openGroups = new Set(JSON.parse(localStorage.getItem(NAV_STATE_KEY) || '[]'));
  const nav = el('nav.nav', { 'aria-label': 'Navigasi utama' });

  for (const group of visibleNav()) {
    const isOpen = openGroups.size ? openGroups.has(group.label) : true;
    const items = el('.nav-items', group.items.map((item) =>
      el('a', { href: `#/${item.route}`, dataset: { route: item.route } }, [el('span.tick'), item.label])));

    // Class 'chev' dipasang di sini, bukan di icon(): selector rotasi
    // `.nav-group[data-open="false"] > button .chev` di app.css tidak pernah
    // menemukan sasarannya karena icon() merender svg polos — chevron diam
    // saat grup ditutup dan satu-satunya penanda buka/tutup adalah
    // muncul-hilangnya item.
    const chev = icon('chevron', 13);
    chev.classList.add('chev');
    const groupNode = el('.nav-group', { dataset: { open: String(isOpen) } }, [
      el('button', { type: 'button' }, [group.label, chev]),
      items,
    ]);

    groupNode.querySelector('button').addEventListener('click', () => {
      const next = groupNode.dataset.open !== 'true';
      groupNode.dataset.open = String(next);
      const open = [...nav.querySelectorAll('.nav-group')]
        .filter((node) => node.dataset.open === 'true')
        .map((node) => node.querySelector('button').textContent.trim());
      localStorage.setItem(NAV_STATE_KEY, JSON.stringify(open));
    });

    nav.appendChild(groupNode);
  }

  const userButton = el('button.userchip', { type: 'button' }, [
    el('.avatar', { text: initials(user.name) }),
    el('.who', [
      el('b', { text: user.name || '—' }),
      el('span', { text: (user.roles || []).join(', ') || 'tanpa peran' }),
    ]),
  ]);
  userButton.addEventListener('click', () => openUserMenu(user));

  const menuToggle = button('', { variant: 'ghost', iconName: 'menu', title: 'Menu' });
  menuToggle.classList.add('menu-toggle');
  menuToggle.addEventListener('click', () => document.body.classList.toggle('nav-open'));

  nav.addEventListener('click', (event) => {
    if (event.target.closest('a')) document.body.classList.remove('nav-open');
  });

  root.appendChild(el('.shell', [
    el('.brand', [
      el('img', { src: 'favicon.svg', alt: '', width: 28, height: 28 }),
      el('.brand-text', [el('strong', { text: 'Nusantara ERP' }), el('span', { text: 'Konstruksi & SI' })]),
    ]),
    el('header.header', [
      menuToggle,
      el('.crumbs', { id: 'crumbs' }),
      el('.spacer'),
      button('Cari', {
        variant: 'ghost',
        iconName: 'search',
        title: 'Pencarian global (Ctrl+K)',
        onClick: () => openSearch(),
      }),
      button('', {
        variant: 'ghost',
        iconName: (localStorage.getItem(THEME_KEY) || 'system') === 'dark' ? 'moon' : 'sun',
        title: 'Ganti tema',
        onClick: (event) => {
          cycleTheme();
          const next = (localStorage.getItem(THEME_KEY) || 'system') === 'dark' ? 'moon' : 'sun';
          clear(event.currentTarget).appendChild(icon(next, 15));
        },
      }),
      notificationBell(),
      userButton,
    ]),
    nav,
    main,
  ]));

  return main;
}

function openUserMenu(user) {
  const dialog = modal({
    title: 'Akun',
    width: 'narrow',
    body: el('div', [
      el('div', { style: { display: 'flex', gap: '12px', alignItems: 'center', marginBottom: '16px' } }, [
        el('.avatar', { text: initials(user.name), style: { width: '42px', height: '42px', fontSize: '15px' } }),
        el('div', [
          el('b', { text: user.name }),
          el('.muted', { text: user.email, style: { fontSize: '12.5px' } }),
        ]),
      ]),
      el('dl.kv', [
        el('dt', { text: 'Peran' }),
        el('dd', { text: (user.roles || []).join(', ') || '—' }),
        el('dt', { text: 'Hak akses' }),
        el('dd', { text: `${(user.permissions || []).length} izin` }),
      ]),
    ]),
    footer: [
      button('Tutup', { onClick: () => dialog.close() }),
      button('Keluar', {
        variant: 'danger', iconName: 'logout',
        onClick: async (event) => {
          await withBusy(event.currentTarget, async () => {
            stopNotificationPolling();
            await logout();
            /* Pasangan dari pembatalan di renderLogin(): sesi ini berakhir di
               sini, jadi katalognya ikut dibuang di sini. Berlebihan hanya
               selama masuk berikutnya benar-benar melewati form login — dan
               yang tersisa di memori sampai saat itu adalah daftar dokumen
               milik orang yang baru saja pergi, di layar yang boleh ditinggal
               terbuka semalaman. */
            invalidatePrintForms();
            dialog.close();
            renderLogin({ message: 'Anda telah keluar.' });
          });
        },
      }),
    ],
  });
}

function setActiveNav(path) {
  document.querySelectorAll('.nav-items a').forEach((link) => {
    const target = link.dataset.route;
    const active = path === target ||
      (target.startsWith('r/') && path.startsWith(`d/${target.slice(2)}/`)) ||
      (target === 'r/projects' && /^d\/projects\/\d+$/.test(path));
    link.classList.toggle('active', active);
    if (active) {
      const group = link.closest('.nav-group');
      if (group) group.dataset.open = 'true';
    }
  });
}

function setCrumbs(parts) {
  const host = document.getElementById('crumbs');
  if (!host) return;
  clear(host);
  parts.forEach((part, index) => {
    if (index) host.appendChild(icon('chevronRight', 12));
    host.appendChild(index === parts.length - 1 ? el('b', { text: part }) : el('span', { text: part }));
  });
  document.title = `${parts[parts.length - 1]} · Nusantara ERP`;
}

/* ----------------------------------------------------------------- routes */
function view() {
  const node = document.getElementById('view');
  node.scrollTop = 0;
  return clear(node);
}

function accessDenied(host, moduleKey) {
  host.appendChild(el('.alert.error', [
    icon('warn', 16),
    el('div', `Anda tidak memiliki hak akses "${moduleKey}.view" untuk halaman ini.`),
  ]));
}

/** Render a view, surfacing any failure in place instead of blanking the page. */
function guard(host, work) {
  return Promise.resolve()
    .then(work)
    .catch((error) => {
      console.error('View failed', error);
      clear(host).appendChild(el('.alert.error', [
        icon('warn', 16),
        el('div', [
          el('div', { text: error.message || String(error) }),
          el('.muted', { text: 'Muat ulang halaman atau hubungi administrator.', style: { fontSize: '12px' } }),
        ]),
      ]));
    });
}

const CUSTOM_DETAILS = {
  project: renderProject,
  payroll: renderPayrollRun,
  ticket: renderTicket,
  subcontract: renderSubcontract,
  payment: renderPayment,
  role: renderRole,
  employee: renderEmployee,
  asset: renderAsset,
  revenueRun: renderRevenueRun,
  rfq: renderRfq,
};

function registerRoutes() {
  route('dashboard', () => {
    setCrumbs(['Dasbor']);
    setActiveNav('dashboard');
    const host = view();
    guard(host, () => renderDashboard(host));
  });

  route('reports', () => {
    setCrumbs(['Keuangan', 'Laporan']);
    setActiveNav('reports');
    const host = view();
    guard(host, () => renderReports(host));
  });

  /* Drill-down di balik satu baris neraca saldo. Neraca saldo Juli 2026
     menuliskan Persediaan Material 1-1400 sebesar Rp 332.510.000 dan berhenti
     di situ; rute ini yang membawa pembacanya ke jurnal pembentuknya. Tanpa
     rute, bukubesar.js hanya kode mati — layar tanpa rute pernah ikut rilis di
     sini sekali. Gerbang izin di sini bukan pengaman (renderBukuBesar menolak
     sendiri juga di fin.view), melainkan supaya penolakannya tampil sebagai
     panel akses-ditolak baku modul, bukan alert telanjang. */
  route('buku-besar', () => {
    setCrumbs(['Keuangan', 'Buku Besar']);
    setActiveNav('buku-besar');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderBukuBesar(host));
  });

  route('stock', () => {
    setCrumbs(['Persediaan', 'Saldo Stok']);
    setActiveNav('stock');
    const host = view();
    if (!session.can('inv.view')) return accessDenied(host, 'inv');
    return guard(host, () => renderStock(host));
  });

  route('asset-utilization', () => {
    setCrumbs(['Aset', 'Utilisasi']);
    setActiveNav('asset-utilization');
    const host = view();
    if (!session.can('ast.view')) return accessDenied(host, 'ast');
    return guard(host, () => renderAssetUtilization(host));
  });

  route('tax-exports', () => {
    setCrumbs(['Keuangan', 'Ekspor Pajak']);
    setActiveNav('tax-exports');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderTaxExport(host));
  });

  route('kalender-pajak', () => {
    setCrumbs(['Keuangan', 'Kalender Pajak']);
    setActiveNav('kalender-pajak');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderKalenderPajak(host));
  });

  route('ekualisasi-pajak', () => {
    setCrumbs(['Keuangan', 'Ekualisasi Pajak']);
    setActiveNav('ekualisasi-pajak');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderEkualisasi(host));
  });

  route('bank-recon', () => {
    setCrumbs(['Keuangan', 'Rekonsiliasi Bank']);
    setActiveNav('bank-recon');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderBankRecon(host));
  });

  route('kas-kecil', () => {
    setCrumbs(['Keuangan', 'Kasir Kas Kecil']);
    setActiveNav('kas-kecil');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderKasKecil(host));
  });

  route('lapangan', () => {
    setCrumbs(['Proyek', 'Lapangan']);
    setActiveNav('lapangan');
    const host = view();
    return guard(host, () => renderLapangan(host));
  });

  /* Galeri foto progres per proyek (Temuan 16). Rute berparameter id karena
     galeri selalu milik satu proyek; pintunya tombol "Galeri Foto" di halaman
     detail proyek. Gerbang izin di sini demi panel akses-ditolak baku modul —
     renderGaleriProyek menolak sendiri juga, dan per-SUMBER foto disaring
     server menurut izin .view pemanggil. */
  route('galeri-proyek/:id', ({ id }) => {
    setCrumbs(['Proyek', 'Galeri Foto']);
    setActiveNav('r/projects');
    const host = view();
    if (!session.can('prj.view')) return accessDenied(host, 'prj');
    return guard(host, () => renderGaleriProyek(host, { id }));
  });

  route('k3', () => {
    setCrumbs(['Proyek', 'Laporan K3']);
    setActiveNav('k3');
    const host = view();
    if (!session.can('prj.view')) return accessDenied(host, 'prj');
    return guard(host, () => renderK3(host));
  });

  route('evm', () => {
    setCrumbs(['Proyek', 'EVM & Baseline']);
    setActiveNav('evm');
    const host = view();
    if (!session.can('prj.view')) return accessDenied(host, 'prj');
    return guard(host, () => renderEvm(host));
  });

  route('defects', () => {
    setCrumbs(['Proyek', 'Register Defect (Punch List)']);
    setActiveNav('defects');
    const host = view();
    if (!session.can('prj.view')) return accessDenied(host, 'prj');
    return guard(host, () => renderDefects(host));
  });

  route('varian', () => {
    setCrumbs(['Proyek', 'Varian Material']);
    setActiveNav('varian');
    const host = view();
    if (!session.can('prj.view')) return accessDenied(host, 'prj');
    return guard(host, () => renderVarian(host));
  });

  route('harga-satuan', () => {
    setCrumbs(['Estimasi', 'Riwayat Harga Satuan']);
    setActiveNav('harga-satuan');
    const host = view();
    if (!session.can('est.view')) return accessDenied(host, 'est');
    return guard(host, () => renderHargaSatuan(host));
  });

  route('po-outstanding', () => {
    setCrumbs(['Pengadaan', 'Baris PO Terbuka']);
    setActiveNav('po-outstanding');
    const host = view();
    if (!session.can('prc.view')) return accessDenied(host, 'prc');
    return guard(host, () => renderPoOutstanding(host));
  });

  route('siap-tagih', () => {
    setCrumbs(['Keuangan', 'Termin Siap Ditagih']);
    setActiveNav('siap-tagih');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderSiapTagih(host));
  });

  route('pipeline', () => {
    setCrumbs(['Penjualan', 'Analitik Win-Rate']);
    setActiveNav('pipeline');
    const host = view();
    if (!session.can('crm.view')) return accessDenied(host, 'crm');
    return guard(host, () => renderPipeline(host));
  });

  route('retensi', () => {
    setCrumbs(['Keuangan', 'Piutang Retensi']);
    setActiveNav('retensi');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderRetensi(host));
  });

  route('tenggat', () => {
    setCrumbs(['Ringkasan', 'Tenggat']);
    setActiveNav('tenggat');
    const host = view();
    // Tanpa gerbang izin: API core/deadlines sudah menyaring entri menurut izin pemanggil.
    return guard(host, () => renderTenggat(host));
  });

  route('kalender', () => {
    setCrumbs(['Ringkasan', 'Kalender']);
    setActiveNav('kalender');
    const host = view();
    // Tanpa gerbang izin: API core/calendar sudah menyaring agenda menurut izin lihat pemanggil.
    return guard(host, () => renderKalender(host));
  });

  route('periods', () => {
    setCrumbs(['Keuangan', 'Periode Fiskal']);
    setActiveNav('periods');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderPeriods(host));
  });

  route('sertifikat', () => {
    setCrumbs(['SDM & Payroll', 'Register Sertifikat & PKWT']);
    setActiveNav('sertifikat');
    const host = view();
    if (!session.can('hr.view')) return accessDenied(host, 'hr');
    return guard(host, () => renderSertifikat(host));
  });

  route('absensi', () => {
    setCrumbs(['SDM & Payroll', 'Absensi Harian']);
    setActiveNav('absensi');
    const host = view();
    if (!session.can('hr.view')) return accessDenied(host, 'hr');
    return guard(host, () => renderAbsensi(host));
  });

  route('sla-breaches', () => {
    setCrumbs(['Layanan', 'Tiket Lewat SLA']);
    setActiveNav('sla-breaches');
    const host = view();
    if (!session.can('svc.view')) return accessDenied(host, 'svc');
    return guard(host, () => renderSlaBreaches(host));
  });

  route('master-data', () => {
    setCrumbs(['Sistem', 'Impor Data Master']);
    setActiveNav('master-data');
    const host = view();
    // No module gate: the screen lists only the tables the caller may read, and
    // shows nothing at all when that list is empty.
    return guard(host, () => renderMasterData(host));
  });

  route('impor-dokumen', () => {
    setCrumbs(['Sistem', 'Impor Dokumen']);
    setActiveNav('impor-dokumen');
    const host = view();
    // No module gate, same reason as master-data: GET core/document-import
    // returns only the document types the caller may read, and the screen says
    // so when that list is empty. A gate here on one module would also be wrong
    // — penawaran is crm, BOQ/AHSP/RAP are est, and one screen serves both.
    return guard(host, () => renderDocumentImport(host));
  });

  route('company', () => {
    setCrumbs(['Sistem', 'Profil Perusahaan']);
    setActiveNav('company');
    const host = view();
    guard(host, () => renderCompany(host));
  });

  route('settings', () => {
    setCrumbs(['Sistem', 'Pengaturan']);
    setActiveNav('settings');
    const host = view();
    guard(host, () => renderSettings(host));
  });

  // r/<resource path> — list screen
  route('r/*', (_, path) => {
    const key = path.slice(2);
    const def = RESOURCES[key];
    const host = view();

    if (!def) {
      host.appendChild(el('.alert.error', `Halaman "${key}" tidak dikenal.`));
      return;
    }

    setCrumbs([groupLabelFor(key), def.label]);
    setActiveNav(`r/${key}`);

    if (!session.can(`${def.module}.view`)) return accessDenied(host, def.module);
    return guard(host, () => renderList(host, { key, def }));
  });

  // d/<resource path>/<id> — detail screen
  route('d/*', (_, path) => {
    const rest = path.slice(2);
    const lastSlash = rest.lastIndexOf('/');
    const key = rest.slice(0, lastSlash);
    const id = rest.slice(lastSlash + 1);
    const def = RESOURCES[key];
    const host = view();

    if (!def) {
      host.appendChild(el('.alert.error', `Halaman "${key}" tidak dikenal.`));
      return;
    }

    setCrumbs([groupLabelFor(key), def.label, `#${id}`]);
    setActiveNav(`d/${key}/${id}`);

    if (!session.can(`${def.module}.view`)) return accessDenied(host, def.module);

    const custom = def.customDetail && CUSTOM_DETAILS[def.customDetail];

    // The custom screens build their own action row in one pass, so the house-
    // form catalogue has to be in hand BEFORE they render — renderDetail awaits
    // it itself. Cached for the session, so this costs one request in total.
    if (custom) {
      return guard(host, async () => {
        /* Kerangka dipasang SEBELUM menunggu, dan itulah seluruh maksud baris
           ini. view() di atas sudah mengosongkan #view, sementara layar custom
           baru menggambar kerangkanya sendiri SETELAH await di bawah selesai —
           jadi tautan-dalam dingin ke layar detail custom (katalog belum
           ter-cache) menampilkan HALAMAN PUTIH selama permintaan itu berjalan.
           Menunggunya tetap benar: setiap layar custom merakit barisan aksinya
           sekali jadi, dan tombol cetak yang menyembul sedetik setelah layar
           tenang terbaca sebagai kedipan bug. Kerangka ini dibuang oleh
           clear(host) yang mengawali kesepuluh renderer custom. */
        host.appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '18px', width: '40%' } }))));
        await loadPrintForms();
        return custom(host, { id });
      });
    }

    return guard(host, () => renderDetail(host, { key, def, id }));
  });

  fallback((path) => {
    if (!path || path === '/') {
      navigate('dashboard', { replace: true });
      return;
    }
    setCrumbs(['Tidak ditemukan']);
    const host = view();
    host.appendChild(el('.alert.warn', [
      icon('warn', 16),
      el('div', [
        el('div', `Halaman "${path}" tidak ditemukan.`),
        button('Ke dasbor', { size: 'sm', onClick: () => navigate('dashboard') }),
      ]),
    ]));
  });
}

function groupLabelFor(key) {
  const group = NAV.find((entry) => entry.items.some((item) => item.route === `r/${key}`));
  return group ? group.label : 'ERP';
}

/* ------------------------------------------------------------------- boot */
let routesRegistered = false;

async function boot() {
  startNotificationPolling();
  buildShell();

  if (!routesRegistered) {
    registerRoutes();
    // Bound to the document once, not per shell rebuild: a re-login would
    // otherwise stack a second listener and open two dialogs on one Ctrl+K.
    registerSearchShortcut();
    routesRegistered = true;
    start();
  } else {
    // Re-render the current route into the freshly built shell.
    const path = currentPath();
    navigate('dashboard', { replace: true });
    if (path !== 'dashboard') navigate(path, { replace: true });
    else start();
  }

  // Refresh permissions in the background — roles may have changed since login.
  refreshMe().catch(() => {});
}

setUnauthorizedHandler(() => {
  renderLogin({ message: 'Sesi Anda berakhir. Silakan masuk kembali.' });
});

// A view that blows up must say so rather than leaving a blank page behind.
window.addEventListener('unhandledrejection', (event) => {
  const reason = event.reason || {};
  if (reason.status === 401) return; // already handled by the auth flow
  console.error('Unhandled rejection', reason);
  toast(reason.message || String(reason), { tone: 'err', title: 'Terjadi kesalahan', timeout: 9000 });
});

window.addEventListener('error', (event) => {
  if (event.error) console.error('Uncaught error', event.error);
});

async function init() {
  if (!session.token) {
    renderLogin();
    return;
  }

  try {
    await refreshMe();
    boot();
  } catch (error) {
    if (error.status === 401) renderLogin({ message: 'Sesi Anda berakhir. Silakan masuk kembali.' });
    else {
      renderLogin({ message: 'Tidak dapat menghubungi server. Coba masuk kembali.' });
    }
  }
}

init();
