/* Application shell: login gate, layout, navigation, routing. */

import { api, session, login, logout, refreshMe, setUnauthorizedHandler } from './api.js';
import { notificationBell, startNotificationPolling, stopNotificationPolling } from './notifications.js';
import { el, clear, button, icon, svgIcon, toast, toastError, field, withBusy, setFieldError, modal, closeAllModals, modalDepth, confirmDialog, drawer, menuButton } from './ui.js';
import { initials } from './format.js';
import { NAV, RESOURCES, visibleNav, moduleFor } from './schema.js';
import { route, fallback, navigate, start, currentPath, setLeaveGuard, clearLeaveGuard, resolve } from './router.js';
import { setCrumbs } from './crumbs.js';
import { prefs } from './prefs.js';
import { loadInbox, inboxSummary } from './inbox.js';
import { createEntries, creatable, startCreate } from './create.js';
import { renderModuleHome } from './views/module.js';
import { renderHome } from './views/home.js';
import { loadPrintForms, invalidatePrintForms } from './printcatalog.js';
import { renderList } from './views/list.js';
import { renderDetail } from './views/detail.js';
import { renderDashboard } from './views/dashboard.js';
import { renderProject } from './views/project.js';
import { renderReports } from './views/reports.js';
import { renderLaporanBebas } from './views/laporanbebas.js';
import { renderBoard } from './views/board.js';
import {
  renderStock, renderPayrollRun, renderTicket, renderSubcontract,
  renderPayment, renderRole, renderEmployee, renderAsset, renderAssetUtilization, renderCompany, renderRevenueRun,
} from './views/custom.js';
import { renderSettings } from './views/settings.js';
import { renderTaxExport } from './views/taxexport.js';
import { renderBankRecon } from './views/bankrecon.js';
import { renderLapangan } from './views/lapangan.js';
import { renderAbsensiSaya } from './views/absensisaya.js';
import { renderUsulanRekap } from './views/usulanrekap.js';
import { renderTimesheet, renderTimesheetSaya } from './views/timesheet.js';
import { renderReorder } from './views/reorder.js';
import { renderPindai } from './views/pindai.js';
import { postAction, toastActionDone, withConfirmResubmit } from './views/actions.js';
import { renderK3 } from './views/k3.js';
import { renderEvm } from './views/evm.js';
import { renderDefects } from './views/defect.js';
import { renderMasterData } from './views/masterdata.js';
import { renderDocumentImport } from './views/dokumenimpor.js';
import { openSearch, registerSearchShortcut } from './search.js';
import { renderSlaBreaches } from './views/slabreaches.js';
import { renderCsat } from './views/csat.js';
import { renderRetensi } from './views/retensi.js';
import { renderSiapTagih } from './views/siaptagih.js';
import { renderPeriods } from './views/periods.js';
import { renderVarian } from './views/varian.js';
import { renderHargaSatuan } from './views/hargasatuan.js';
import { renderPoOutstanding } from './views/pooutstanding.js';
import { renderRekapAlat } from './views/rekapalat.js';
import { renderSewaVsBeli } from './views/sewavsbeli.js';
import { renderTkdnWorksheet, renderRkkDocument, renderKualifikasi } from './views/tender.js';
import { renderTenggat } from './views/tenggat.js';
import { renderAmbang } from './views/ambang.js';
import { renderAnggaran } from './views/anggaran.js';
import { renderSertifikat } from './views/sertifikat.js';
import { renderAbsensi } from './views/absensi.js';
import { renderKalender } from './views/kalender.js';
import { renderKasKecil } from './views/kaskecil.js';
import { renderBukuBesar } from './views/bukubesar.js';
import { renderKalenderPajak } from './views/kalenderpajak.js';
import { renderRekapPph21 } from './views/rekappph21.js';
import { renderEkualisasi } from './views/ekualisasi.js';
import { renderGaleriProyek } from './views/galeriproyek.js';
import { renderPipeline } from './views/pipeline.js';
import { renderRfq } from './views/rfq.js';
import { renderTugas } from './views/tugas.js';
import { renderProfil } from './views/profil.js';
import { renderWebhook } from './views/webhook.js';
import { openForm, startForm, formIsPaged } from './views/form.js';
import { openOnboarding, closeOnboarding } from './views/onboarding.js';
import { listDrafts, removeDraft, flushAll, suspendDraftRemoval, relativeAge } from './drafts.js';

/* Penanda untuk pengawas boot di index.html: modul SUDAH dijalankan.
   Tanpa ini pengawas 10 detik selalu berkata "sebagian berkas aplikasi tidak
   sampai" — juga ketika setiap berkas sampai dan yang lambat adalah jawaban
   server (verifikasi ulang P1-I, 7 Sep 2026). */
window.__erpModules = true;

const root = document.getElementById('root');
const THEME_KEY = 'nusantara_erp_theme';
const NAV_STATE_KEY = 'nusantara_erp_nav';
/*
 * Favorit dan Terakhir dibuka (T2.5) — sejak P1-C keduanya PREFERENSI SERVER
 * (core/me/preferences lewat prefs.js), bukan localStorage per id pengguna:
 * bintang yang dipasang di desktop kantor harus ada juga di tablet lapangan.
 * prefs.js tetap menyimpan cermin lokal per pengguna, jadi sifat "kasir tidak
 * mewarisi lima dokumen pengawas" yang dulu dijaga personalKey tetap berlaku.
 *
 * Berapa banyak yang DISIMPAN adalah urusan server (whitelist UserPreferences,
 * diumumkan lewat meta dan dibaca prefs.js); yang diputuskan di sini hanya
 * berapa yang DIGAMBAR sidebar — lima teratas. Beranda modul #/m/<prefix>
 * menyaring daftar yang sama per modul, jadi menyimpan lebih banyak daripada
 * yang muat di sidebar memang gunanya. Angka plafonnya sendiri tidak ditulis
 * lagi di berkas ini: salinan ketiganya yang pernah berdiri di sini tidak
 * dibaca satu baris pun (verifikasi P1-C, 6 Sep 2026).
 */
const RECENT_SIDEBAR = 5;
const FAVORITES_LABEL = 'Favorit';
const RECENT_LABEL = 'Terakhir dibuka';
/*
 * Kepadatan (P1-B): rapat 32 / normal 38,5 / lega 48 px per baris satu-baris
 * (angka diukur, blok token app.css). Sejak P1-C nilainya preferensi server
 * ('compact' | 'normal' | 'comfortable'); cermin lokal prefs.js yang menjawab
 * seketika, karena atribut data-density dipasang di <html> SEBELUM shell
 * digambar (evaluasi modul + boot()) dan jawaban server baru datang sesudah
 * refreshMe() — tanpa cermin ada kedipan normal → padat di setiap muat.
 */
// 'Padat', bukan 'Rapat': di ERP "rapat" terbaca lebih dulu sebagai pertemuan (verifikasi P1-B 5 Sep 2026).
const DENSITIES = { compact: 'Padat', normal: 'Normal', comfortable: 'Lega' };

/* ------------------------------------------------------------------ theme */
function applyTheme(theme) {
  if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme;
  else delete document.documentElement.dataset.theme;
}

const THEMES = { system: 'Ikuti perangkat', light: 'Terang', dark: 'Gelap' };

/*
 * Tema pindah ke dialog Akun (UX-D.4, handoff README Fase D): tiga pilihan yang
 * terlihat sekaligus, seperti Kepadatan, alih-alih tombol matahari/bulan yang
 * berputar tiga langkah tanpa memperlihatkan langkah berikutnya. Tetap preferensi
 * PERAMBAN (localStorage), bukan server: terang/gelap mengikuti perangkat dan cahaya
 * di sekitarnya, bukan orangnya. Slot bilah atas yang dikosongkan dipakai Buat ▾.
 */
function themeControl() {
  const current = localStorage.getItem(THEME_KEY) || 'system';
  return el('fieldset.theme-pick', [
    el('legend', { text: 'Tema' }),
    ...Object.entries(THEMES).map(([value, label]) => {
      const input = el('input', { type: 'radio', name: 'theme', value });
      input.checked = value === current;
      input.addEventListener('change', () => {
        if (!input.checked) return;
        localStorage.setItem(THEME_KEY, value);
        applyTheme(value);
      });
      return el('label.check-row', [input, el('span', { text: label })]);
    }),
  ]);
}

/*
 * Buat ▾ (UX-D.4): tombol Tambah yang sudah ada, dikumpulkan — js/create.js. Daftarnya
 * dihitung SAAT DIBUKA (rute dan "Terakhir dibuka" berubah), dan tombolnya tidak
 * digambar sama sekali bagi pembaca yang tidak boleh membuat apa pun.
 */
function createMenuItems() {
  const { here, module, recent } = createEntries(currentPath().split('?')[0]);
  const toItem = (entry) => ({ label: entry.def.label, onClick: () => startCreate(entry) });
  const group = here ? NAV.find((one) => one.prefix === here) : null;
  return [
    module.length ? { heading: group ? group.label : 'Modul ini' } : null,
    ...module.map(toItem),
    module.length && recent.length ? { divider: true } : null,
    recent.length ? { heading: module.length ? 'Terakhir dipakai' : 'Buat baru' } : null,
    ...recent.map(toItem),
  ];
}

function createMenu() {
  const wrap = menuButton('Buat', createMenuItems, { iconName: 'plus', variant: 'primary', title: 'Buat dokumen atau data baru' });
  wrap.classList.add('create-menu');
  wrap.hidden = creatable().length === 0;
  return wrap;
}

applyTheme(localStorage.getItem(THEME_KEY) || 'system');

/* ---------------------------------------------------------------- density */
function readDensity() {
  const stored = prefs.get('density');
  return DENSITIES[stored] ? stored : 'normal';
}

function applyDensity(density) {
  document.documentElement.dataset.density = DENSITIES[density] ? density : 'normal';
}

function setDensity(density) {
  prefs.set('density', density);
  applyDensity(density);
}

applyDensity(readDensity());

/* ------------------------------------------------------------------ login */
function renderLogin({ message } = {}) {
  /* Panel onboarding v2 hidup di body, di luar #root (supaya pindah rute tidak
     membuangnya) — jadi halaman masuk yang digambar ulang di sini (keluar,
     401, masuk ulang) tidak ikut membuangnya. Ditutup tanpa mencatat apa pun. */
  closeOnboarding();
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
  const passwordHelp = el('.password-help');
  const hint = el('.login-hint');

  root.appendChild(el('.login', el('.login-card', [
    el('.login-brand', [
      el('img', { src: 'favicon.svg', alt: '', width: 38, height: 38 }),
      el('div', [el('strong', { text: 'Nusantara ERP' }), el('span', { text: 'Konstruksi & Integrasi Sistem' })]),
    ]),
    el('h1', { text: 'Masuk ke akun Anda' }),
    el('.sub', { text: 'Gunakan email dan kata sandi yang diberikan administrator.' }),
    message ? el('.alert.info', { style: { marginBottom: '14px' } }, message) : null,
    form,
    passwordHelp,
    hint,
  ])));

  /* Lupa kata sandi (T2.7): server yang tahu apakah tautan email sungguh
     sampai ke orang. Dengan MAIL_MAILER=log (bawaan .env.example dan erp1)
     suratnya mendarat di storage/logs — tombol "Lupa kata sandi" di keadaan
     itu berbohong. GET iam/auth/password-help (saudara demo-accounts)
     menjawab dua hal: tautan sampai atau tidak, dan siapa administratornya.
     Tidak ada jawaban → tidak ada baris; halaman ini tidak menebak. */
  api.get('iam/auth/password-help').then((help) => {
    if (!help || typeof help !== 'object') return;
    if (help.reset_by_email) {
      passwordHelp.append('Lupa kata sandi? ', el('button.link-btn', {
        type: 'button',
        text: 'Kirim tautan pengaturan ulang',
        onclick: () => openForgotPassword(emailInput.value.trim()),
      }));
    } else {
      passwordHelp.textContent = `Lupa kata sandi? ${help.administrator
        ? `Minta ${help.administrator} (administrator)`
        : 'Minta administrator sistem'} mengatur ulang kata sandi Anda.`;
    }
  }).catch(() => {});

  // Akun demo hanya bila server mengaku bukan produksi (GET iam/auth/demo-accounts).
  // Dulu daftar email peran internal ini tercetak di halaman masuk publik tanpa
  // memeriksa lingkungan apa pun.
  api.get('iam/auth/demo-accounts').then((accounts) => {
    if (!Array.isArray(accounts) || !accounts.length) return;
    hint.appendChild(el('div', { text: 'Akun demo (kata sandi: password):' }));
    hint.appendChild(el('div', accounts.flatMap((email, index) => [
      index ? ' · ' : null,
      el('code', { text: email, onclick: () => fill(email) }),
    ])));
  }).catch(() => {});

  emailInput.focus();
}

/* ------------------------------------------------------------- kata sandi */
/*
 * Layanan mandiri kata sandi (T2.7). Sampai 2 Sep 2026 menu akun hanya
 * "Tutup · Keluar" (HASIL-UJI §1, S9) dan setiap penggantian sandi lewat
 * administrator — PANDUAN-PENGGUNA §0 kalimat 5 bahkan menyuruh orang berhenti
 * mencarinya. Tiga pintu di bawah: ganti sandi dari menu akun (sandi lama
 * wajib), kirim tautan dari halaman masuk, dan layar #/reset-password yang
 * dibuka tautan itu.
 */

/* Satu kalimat, sama persis dengan AuthController::LINK_SENT_MESSAGE — sengaja
   tidak menyebut "terkirim": halaman masuk tidak tahu apakah alamat itu ada. */
const LINK_SENT_MESSAGE = 'Jika email itu terdaftar dan aktif, tautan pengaturan ulang dikirim ke sana dan berlaku 60 menit.';

function passwordField(autocomplete) {
  return el('input', { type: 'password', autocomplete, required: true, placeholder: '••••••••' });
}

/* Galat 422 dilukis di bawah field yang bersangkutan; mengembalikan apakah ada
   yang terlukis supaya pemanggil tahu kapan toast masih perlu. Kunci `password`
   juga menampung galat konfirmasi (aturan `confirmed` Laravel menempel pada
   field asalnya, bukan pada password_confirmation). */
function paintPasswordErrors(error, controls) {
  const errors = (error && error.errors) || {};
  let painted = false;
  Object.entries(controls).forEach(([key, input]) => {
    const text = errors[key] ? [].concat(errors[key])[0] : '';
    setFieldError(input, text);
    if (text) painted = true;
  });
  return painted;
}

/* Isian kosong dan konfirmasi yang tidak sama ditangkap di sini, sebelum
   permintaan — kalimatnya sama dengan yang akan dikirim server. */
function passwordFormValid(controls, password, confirmation) {
  Object.values(controls).forEach((input) => setFieldError(input, ''));
  const empty = Object.values(controls).filter((input) => !input.value.trim());
  if (empty.length) {
    empty.forEach((input) => setFieldError(input, 'Wajib diisi.'));
    empty[0].focus();
    return false;
  }
  if (password.value !== confirmation.value) {
    setFieldError(confirmation, 'Konfirmasi kata sandi tidak cocok.');
    confirmation.focus();
    return false;
  }
  return true;
}

/** Menu akun › Ganti kata sandi. */
function openChangePassword() {
  const current = passwordField('current-password');
  const password = passwordField('new-password');
  const confirmation = passwordField('new-password');
  const controls = { current, password, password_confirmation: confirmation };

  const form = el('form', { novalidate: true }, [
    field('Kata sandi saat ini', current, { required: true }),
    field('Kata sandi baru', password, { required: true, help: 'Minimal 8 karakter.' }),
    field('Ulangi kata sandi baru', confirmation, { required: true }),
    /* Konsekuensinya disebut, bukan disembunyikan: token Sanctum bertahan
       melewati penggantian sandi, sama seperti lewat Sistem › Pengguna
       (PANDUAN-ADMINISTRATOR §3.4). */
    el('.help.muted', {
      text: 'Berlaku untuk masuk berikutnya. Sesi yang sedang terbuka di perangkat lain tetap berjalan.',
      style: { fontSize: '12px', lineHeight: '1.5' }, // .field .help hanya berlaku di dalam .field
    }),
  ]);
  const submit = button('Simpan kata sandi', { variant: 'primary', onClick: () => form.requestSubmit() });
  const dialog = modal({
    title: 'Ganti kata sandi',
    width: 'narrow',
    body: form,
    footer: [button('Batal', { onClick: () => dialog.close() }), submit],
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!passwordFormValid(controls, password, confirmation)) return;
    await withBusy(submit, async () => {
      try {
        await api.put('iam/me/password', {
          current: current.value,
          password: password.value,
          password_confirmation: confirmation.value,
        });
        dialog.close();
        toast('Kata sandi Anda diperbarui.');
      } catch (error) {
        // Sandi lama yang salah datang sebagai 422 pada `current` dengan
        // kalimat Indonesia dari lang/id/validation.php; dialog tetap terbuka.
        if (!paintPasswordErrors(error, controls)) toastError(error);
      }
    });
  });
}

/** Halaman masuk › Kirim tautan pengaturan ulang (hanya bila server mengaku suratnya sampai). */
function openForgotPassword(prefill) {
  const emailInput = el('input', { type: 'email', autocomplete: 'username', required: true, placeholder: 'nama@nusantara.test', value: prefill || '' });
  const form = el('form', { novalidate: true }, [
    field('Email akun Anda', emailInput, { required: true, help: 'Tautan berlaku 60 menit dan hanya sekali pakai.' }),
  ]);
  const submit = button('Kirim tautan', { variant: 'primary', onClick: () => form.requestSubmit() });
  const dialog = modal({
    title: 'Kirim tautan pengaturan ulang',
    width: 'narrow',
    body: form,
    footer: [button('Batal', { onClick: () => dialog.close() }), submit],
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFieldError(emailInput, '');
    if (!emailInput.value.trim()) { setFieldError(emailInput, 'Wajib diisi.'); return; }
    await withBusy(submit, async () => {
      try {
        await api.post('iam/auth/forgot-password', { email: emailInput.value.trim() });
        dialog.close();
        // Bertahan sampai ditutup: orang ini akan berpindah ke kotak masuknya.
        toast(LINK_SENT_MESSAGE, { tone: 'info', timeout: 0 });
      } catch (error) {
        // 429 (satu tautan per menit) dan 409 (surat hanya ke log) datang
        // dengan kalimat server; galat email dilukis di bawah field.
        if (!paintPasswordErrors(error, { email: emailInput })) toastError(error);
      }
    });
  });
}

/* Tautan dari surat: #/reset-password?token=…&email=…, dibaca di init()
   SEBELUM sesi diperiksa — orang yang lupa sandinya tidak punya sesi. */
function resetLinkParams() {
  const hash = location.hash || '';
  if (!/^#\/?reset-password(\?|$)/.test(hash)) return null;
  const cut = hash.indexOf('?');
  const query = new URLSearchParams(cut === -1 ? '' : hash.slice(cut + 1));
  return { token: query.get('token') || '', email: query.get('email') || '' };
}

function renderResetPassword({ token, email }) {
  clear(root);
  root.className = '';

  const emailInput = el('input', { type: 'email', autocomplete: 'username', required: true, value: email });
  const password = passwordField('new-password');
  const confirmation = passwordField('new-password');
  const controls = { email: emailInput, password, password_confirmation: confirmation };
  const refusal = el('.alert.error', { style: { marginBottom: '14px' } });
  refusal.hidden = true;
  const submit = button('Simpan kata sandi baru', { variant: 'primary', type: 'submit' });

  const form = el('form', { novalidate: true }, [
    field('Email', emailInput, { required: true }),
    field('Kata sandi baru', password, { required: true, help: 'Minimal 8 karakter.' }),
    field('Ulangi kata sandi baru', confirmation, { required: true }),
    submit,
  ]);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    refusal.hidden = true;
    if (!passwordFormValid(controls, password, confirmation)) return;
    await withBusy(submit, async () => {
      try {
        await api.post('iam/auth/reset-password', {
          token,
          email: emailInput.value.trim(),
          password: password.value,
          password_confirmation: confirmation.value,
        });
        /* Sesi yang kebetulan masih ada di tab ini ditutup: orang yang baru
           mengatur ulang sandinya diminta masuk dengan sandi itu, dan tidak
           tergelincir kembali ke sesi lama saat halaman dimuat ulang. */
        if (session.token) await logout();
        // replaceState, bukan location.hash = '': tidak memicu hashchange ke
        // router yang belum punya shell untuk digambari.
        history.replaceState(null, '', location.pathname + location.search);
        renderLogin({ message: 'Kata sandi diperbarui. Masuk dengan kata sandi baru Anda.' });
      } catch (error) {
        const painted = paintPasswordErrors(error, controls);
        const tokenError = error && error.errors && error.errors.token;
        if (tokenError) {
          // Tautan kedaluwarsa/terpakai: tidak ada field untuk dilukis —
          // kalimat server (menyebut jalan keluarnya) tampil di atas formulir.
          refusal.textContent = [].concat(tokenError)[0];
          refusal.hidden = false;
        } else if (!painted) {
          toastError(error);
        }
      }
    });
  });

  root.appendChild(el('.login', el('.login-card', [
    el('.login-brand', [
      el('img', { src: 'favicon.svg', alt: '', width: 38, height: 38 }),
      el('div', [el('strong', { text: 'Nusantara ERP' }), el('span', { text: 'Konstruksi & Integrasi Sistem' })]),
    ]),
    el('h1', { text: 'Atur ulang kata sandi' }),
    el('.sub', { text: 'Tautan dari surat "lupa kata sandi" berlaku 60 menit dan sekali pakai.' }),
    refusal,
    form,
    el('.password-help', [el('button.link-btn', {
      type: 'button',
      text: 'Kembali ke halaman masuk',
      onclick: () => { history.replaceState(null, '', location.pathname + location.search); renderLogin(); },
    })]),
  ])));

  (email ? password : emailInput).focus();
}

/* ------------------------------------------------------------------ shell */
/*
 * Sidebar (T2.5). Diukur 2 Sep 2026 (HASIL-UJI §1, S5): admin 14 grup / 121
 * tautan setinggi 4,9 viewport, direktur 4,9, finance 2,7, PM 2,6 — semua
 * grup terbuka bawaan, Proyek dan Keuangan 20 tautan rata. Empat hal di sini:
 * grup tertutup bawaan kecuali Ringkasan dan grup rute aktif; pemisah di
 * dalam grup panjang; grup Favorit (bintang di tiap baris) dan Terakhir
 * dibuka (lima dokumen terakhir) di atas Ringkasan. Penyaring izinnya
 * visibleNav() di schema.js, dipakai juga oleh sumber "Layar" di Ctrl+K.
 */
const navForSession = () => visibleNav((perm) => session.can(perm));

/*
 * null = belum pernah menyentuh grup mana pun, dan itulah yang membedakan
 * bawaan baru (tertutup) dari preferensi tersimpan (menang, seperti dulu).
 * Dulu daftar kosong pun berarti "semua terbuka"; kini daftar kosong berarti
 * persis itu: semuanya ditutup sendiri oleh pemakainya.
 */
function storedOpenGroups() {
  const raw = localStorage.getItem(NAV_STATE_KEY);
  return raw === null ? null : new Set(JSON.parse(raw));
}

/*
 * Rel ikon (UX-D.6, handoff README Fase D; ERRATA E-4): sidebar 248 px menciut menjadi
 * rel 64 px — atas pilihan di ≥ 1024 px, SELALU di 761–1023 px (tablet), tempat 248 px
 * memakan seperempat layar. Keadaannya milik PERANGKAT, disimpan di localStorage di
 * samping lipatan grup (nusantara_erp_nav) — bukan preferensi server: rel yang dipilih
 * di monitor kantor tidak boleh menciutkan sidebar laptop lapangan. UserPreferences
 * tidak berubah. Di rel, label tetap ada untuk pembaca layar dan sebagai tooltip;
 * ikon grup membuka beranda modulnya (daftar lengkap layarnya), dan menu lengkap
 * dibuka DI ATAS isi lewat laci yang sama dengan ponsel (ui.drawer()).
 */
const RAIL_KEY = 'nusantara_erp_nav_rail';
const tabletQuery = window.matchMedia('(min-width: 761px) and (max-width: 1023px)');
const wideQuery = window.matchMedia('(min-width: 1024px)');

function railChosen() {
  try { return localStorage.getItem(RAIL_KEY) === '1'; } catch { return false; }
}

function railActive() {
  return tabletQuery.matches || (wideQuery.matches && railChosen());
}

function applyRail() {
  document.body.classList.toggle('rail', railActive());
  updateRailToggle();
}

function updateRailToggle() {
  const node = document.querySelector('.rail-toggle');
  if (!node) return;
  const rail = document.body.classList.contains('rail');
  const open = document.body.classList.contains('nav-open');
  const label = tabletQuery.matches
    ? (open ? 'Tutup menu' : 'Buka menu lengkap')
    : (rail ? 'Lebarkan menu' : 'Ciutkan menu');
  if (node.title === label && node.dataset.state === `${rail}:${open}`) return;
  node.title = label;
  node.dataset.state = `${rail}:${open}`;
  node.setAttribute('aria-label', label);
  if (tabletQuery.matches) node.setAttribute('aria-expanded', String(open)); else node.removeAttribute('aria-expanded');
  clear(node).appendChild(svgIcon(rail && !open ? 'chevrons-right' : 'chevrons-left', { size: 15 }));
}

function toggleRail() {
  if (tabletQuery.matches) { shellDrawer.toggle(); return; }
  try { localStorage.setItem(RAIL_KEY, railChosen() ? '0' : '1'); } catch { /* peramban menolak: tetap berlaku di sesi ini */ }
  if (shellDrawer.isOpen()) shellDrawer.close();
  applyRail();
}

tabletQuery.addEventListener('change', applyRail);
wideQuery.addEventListener('change', applyRail);
new MutationObserver(updateRailToggle).observe(document.body, { attributeFilter: ['class'] });

function groupOpenByDefault(group, stored) {
  if (stored) return stored.has(group.label);
  return group.kind === 'shortcut' || group.label === 'Ringkasan';
}

/*
 * Grup yang baru mendapat isi pertamanya membuka diri walau preferensi lama
 * (tersimpan sebelum grup itu ada) tidak menyebutnya — bintang pertama yang
 * melahirkan grup Favorit dalam keadaan terlipat terbaca sebagai bintang
 * yang tidak bekerja.
 */
function ensureGroupOpen(label) {
  const stored = storedOpenGroups();
  if (!stored || stored.has(label)) return;
  stored.add(label);
  localStorage.setItem(NAV_STATE_KEY, JSON.stringify([...stored]));
}

/*
 * Bintang dan "Terakhir dibuka" ditulis prefs.js, dan prefs.js yang mengumumkan
 * perubahannya lewat peristiwa window — sidebar mendengarkan di bawah. Jalur
 * ini satu untuk SEMUA pemasang bintang (sidebar, beranda modul), jadi bintang
 * yang dinyalakan di kartu beranda modul menyalakan baris sidebarnya juga tanpa
 * module.js perlu mengimpor shell ini.
 */
function toggleFavorite(route) {
  prefs.toggleFavorite(route);
  // Fokus kembali ke bintang baris yang sama di grup asalnya: barisan
  // Favorit baru saja dibangun ulang (atau barisnya hilang), dan pengguna
  // papan ketik tidak boleh terlempar ke awal dokumen.
  const star = document.querySelector(`nav.nav .nav-group:not([data-kind]) .nav-item[data-route="${CSS.escape(route)}"] .star`);
  if (star) star.focus();
}

window.addEventListener('erp:favorites-changed', (event) => {
  const { was = 0, now = 0 } = event.detail || {};
  if (!was && now) ensureGroupOpen(FAVORITES_LABEL);
  refreshNav();
});

window.addEventListener('erp:recent-changed', (event) => {
  if (!(event.detail || {}).was) ensureGroupOpen(RECENT_LABEL);
  refreshNav();
});

/* Favorit dirujuk lewat rute ke NAV yang sedang terlihat: bintang pada layar
   yang izinnya dicabut ikut lenyap, dan kembali bila izinnya kembali (daftar
   tersimpan tidak disunting). Terakhir dibuka disaring izin bacanya seperti
   rute d/* sendiri, jadi tidak ada tautan ke halaman "akses ditolak". */
function shortcutGroups(groups) {
  const flat = groups.flatMap((group) => group.items.filter((item) => item.route));
  const favorites = prefs.favorites()
    .map((route) => flat.find((item) => item.route === route))
    .filter(Boolean);
  // Disimpan 20 (plafon server), digambar lima: sidebar bukan riwayat, dan
  // sisanya dipakai beranda modul yang menyaring daftar yang sama per modul.
  const recent = prefs.visibleRecent((perm) => session.can(perm))
    .slice(0, RECENT_SIDEBAR)
    .map((one) => ({ route: one.route, label: one.label, sub: one.sub, starrable: false, shortcut: true }));
  return [
    favorites.length ? { label: FAVORITES_LABEL, kind: 'shortcut', items: favorites } : null,
    recent.length ? { label: RECENT_LABEL, kind: 'shortcut', items: recent } : null,
  ].filter(Boolean);
}

function starButton(route, on) {
  const verb = on ? 'Hapus dari Favorit' : 'Tandai sebagai Favorit';
  const star = el('button.star', { type: 'button', 'aria-pressed': String(on), 'aria-label': verb, title: verb }, icon('star', 13));
  if (on) star.classList.add('on');
  star.addEventListener('click', () => toggleFavorite(route));
  return star;
}

// <svg>.className adalah SVGAnimatedString yang hanya-baca: menetapkannya melempar
// TypeError di modul (strict), dan shell berhenti di pemutar boot. classList, bukan className.
function navIcon(name) {
  const node = svgIcon(name, { size: 15 });
  node.classList.add('ni');
  return node;
}

function navItemNode(item, favorites) {
  const link = el('a', { href: `#/${item.route}`, dataset: { route: item.route }, title: item.sub || null }, [
    el('span.tick'),
    item.icon ? navIcon(item.icon) : null,
    el('span.lbl', { text: item.label }),
    // Lencana jumlah (UX-D.2): diisi paintInboxCounts() dari js/inbox.js; kosong = belum ditanyakan.
    item.count ? el('span.nav-count', { dataset: { count: item.count }, hidden: true }) : null,
  ]);
  // Baris kroma aplikasi (schema.js `chrome: true` — hari ini hanya Beranda):
  // ada di menu, tetapi bukan salah satu LAYAR modulnya. Ditandai di DOM supaya
  // pembaca luar (harness) menyaring dengan penanda yang sama seperti kisi
  // kartu beranda modul, bukan dengan daftar href yang dikarangnya sendiri.
  if (item.chrome) link.dataset.chrome = '1';
  const node = el(`.nav-item${item.shortcut ? '.shortcut' : ''}`, { dataset: { route: item.route } }, [link]);
  if (item.starrable !== false) node.appendChild(starButton(item.route, favorites.includes(item.route)));
  return node;
}

/*
 * Plafon tautan per grup (UX-D.2, ERRATA E-4). Grup yang rutenya lebih dari
 * CAP_GROUP menampilkan CAP_SHOW rute pertama lalu satu baris "Semua layar
 * <modul> →" ke beranda modulnya; sisanya tetap ADA di DOM (kelas beyond-cap,
 * disembunyikan app.css) supaya kotak saring, pembaca luar (S21 membandingkan
 * kartu beranda modul dengan tautan sidebar) dan rute aktif tetap menemukannya —
 * setActiveNav menandai rute aktif di balik plafon dengan cap-active, jadi ia
 * selalu tergambar. Pemisah di dalam irisan yang terlihat dipertahankan; yang
 * bloknya seluruhnya di balik plafon ikut tersembunyi. Terukur 3 Okt 2026:
 * Penjualan 13, Pengadaan 14, Persediaan 11, Proyek 20, Keuangan 22 rute.
 */
const CAP_GROUP = 10;
const CAP_SHOW = 8;

function navGroupNode(nav, group, favorites, stored) {
  const routes = group.items.filter((item) => item.route).length;
  const capped = Boolean(group.prefix) && routes > CAP_GROUP;
  let shown = 0;
  const items = el('.nav-items', group.items.map((item) => {
    const node = item.divider ? el('.nav-divider', { text: item.divider }) : navItemNode(item, favorites);
    if (capped) {
      if (item.divider ? shown >= CAP_SHOW : shown >= CAP_SHOW) node.classList.add('beyond-cap');
      if (item.route) shown += 1;
    }
    return node;
  }));
  if (capped) {
    items.appendChild(el('.nav-item.nav-more', [
      el('a.nav-more-link', { href: `#/m/${group.prefix}`, dataset: { route: `m/${group.prefix}`, chrome: '1' } }, [
        el('span.tick'),
        el('span.lbl', { text: `Semua layar ${group.label} →` }),
      ]),
    ]));
  }

  // Class 'chev' dipasang di sini, bukan di icon(): selector rotasi
  // `.nav-group[data-open="false"] > button .chev` di app.css tidak pernah
  // menemukan sasarannya karena icon() merender svg polos — chevron diam
  // saat grup ditutup dan satu-satunya penanda buka/tutup adalah
  // muncul-hilangnya item.
  const chev = icon('chevron', 13);
  chev.classList.add('chev');
  /* Ikon di kepala grup (UI-3). MODULES[prefix].icon sudah memuat nama Lucide
     sejak P1-B — keempat belasnya ada di sprite yang di-vendor (diperiksa
     16 Sep 2026), jadi tidak ada yang menggambar kotak kosong.
     Grup pintasan tidak punya prefix; ia mendapat ikon yang menggambarkan
     DIRINYA (bintang, riwayat), bukan sebuah modul — kalau ia dibiarkan kosong
     labelnya berdiri di kolom tempat ikon grup lain berada, dan sidebar
     kehilangan satu-satunya garis vertikal yang dimilikinya. */
  const groupModule = group.prefix ? moduleFor(group.prefix) : null;
  const shortcutIcon = group.label === FAVORITES_LABEL ? 'star'
    : group.label === RECENT_LABEL ? 'history' : null;
  const iconName = groupModule ? groupModule.icon : shortcutIcon;
  const gi = iconName ? svgIcon(iconName, { size: 14 }) : null;
  if (gi) gi.classList.add('gi');
  const groupNode = el('.nav-group', { dataset: { open: String(groupOpenByDefault(group, stored)) } }, [
    el('button', { type: 'button' }, [gi, el('span.nav-group-label', { text: group.label }), chev]),
    items,
  ]);
  /* Kunci keadaan buka/tutup, DIBACA DARI ATRIBUT, bukan dari textContent.
     groupOpenByDefault() membandingkan dengan group.label, tetapi penulisnya
     dulu memakai button.textContent.trim() — keduanya hanya sepakat selama
     tombolnya tidak memuat teks lain. Diukur 16 Sep 2026: menyisipkan <svg>
     memang tidak mengubah textContent ('Terakhir dibuka' tetap), tetapi sebuah
     <span> hitungan mengubahnya menjadi 'Terakhir dibuka20' — dan setiap
     preferensi sidebar yang tersimpan akan yatim tanpa satu pun galat. Nilai
     yang ditulis tetap SAMA PERSIS dengan sebelumnya, jadi tidak ada migrasi. */
  groupNode.dataset.label = group.label;
  if (group.kind) groupNode.dataset.kind = group.kind;
  // Aksen modul (P1-B): slot warna grup, dipakai penanda grup aktif
  // (.has-active, dipasang setActiveNav). Grup pintasan tidak beraksen.
  const module = group.prefix ? moduleFor(group.prefix) : null;
  if (module) {
    groupNode.dataset.prefix = module.prefix;
    groupNode.dataset.accent = String(module.accent);
  }

  // Tooltip untuk rel ikon (UX-D.6): labelnya tersembunyi secara visual di sana.
  groupNode.querySelector('button').title = group.label;
  groupNode.querySelector('button').addEventListener('click', () => {
    // Di rel (dan menu lengkap tidak sedang terbuka di atasnya) grup tidak bisa
    // "dibuka" di tempat: modulnya membuka berandanya — kartu SEMUA layarnya — dan
    // grup pintasan (Favorit, Terakhir dibuka) membuka menu lengkap di atas isi.
    if (document.body.classList.contains('rail') && !document.body.classList.contains('nav-open')) {
      if (group.prefix) { navigate(`m/${group.prefix}`); return; }
      groupNode.dataset.open = 'true';
      shellDrawer.open();
      return;
    }
    const next = groupNode.dataset.open !== 'true';
    groupNode.dataset.open = String(next);
    const open = [...nav.querySelectorAll('.nav-group')]
      .filter((node) => node.dataset.open === 'true')
      .map((node) => node.dataset.label);
    localStorage.setItem(NAV_STATE_KEY, JSON.stringify(open));
  });

  return groupNode;
}

/*
 * Kotak saring sidebar (UI-3).
 *
 * Diukur 16 Sep 2026 pada sesi admin: 149 tautan dalam 15 grup, tinggi isi
 * 2.203 px — 2,45 layar pada viewport 900 px, dan itu dengan hanya DUA grup
 * terbuka. (Rencana lama menulis "121 tautan / 4,9 layar"; keduanya sudah
 * basi.) Menggulung sebanyak itu untuk menemukan satu layar adalah ongkos
 * yang tidak perlu dibayar siapa pun.
 *
 * Ia menyaring DOM yang sudah tergambar, tidak membangun ulang: navGroupNode
 * memasang pendengar per grup, dan membangun ulang tiap ketukan tombol akan
 * membuang fokus kotak ini sendiri.
 *
 * Keadaan buka/tutup yang TERSIMPAN tidak boleh ikut berubah. Saat menyaring,
 * grup yang punya kecocokan dipaksa terbuka lewat atribut saja — localStorage
 * tidak disentuh, dan keadaan sebelum menyaring dikembalikan begitu kotaknya
 * dikosongkan. Menulis keadaan di sini akan membuat "mencari sesuatu" diam-diam
 * menjadi "menata ulang sidebar saya selamanya".
 */
const NAV_FILTER_STATE = { q: '', before: null };

function applyNavFilter(nav, raw) {
  const q = raw.trim().toLowerCase();
  const groups = [...nav.querySelectorAll('.nav-group')];
  const status = nav.querySelector('.nav-filter-status');

  if (!q) {
    if (NAV_FILTER_STATE.before) {
      for (const node of groups) {
        const was = NAV_FILTER_STATE.before.get(node.dataset.label);
        if (was !== undefined) node.dataset.open = was;
      }
      NAV_FILTER_STATE.before = null;
    }
    for (const node of nav.querySelectorAll('.nav-item, .nav-divider, .nav-group')) node.hidden = false;
    nav.dataset.filtering = 'false';
    if (status) status.textContent = '';
    const cleared = nav.querySelector('.nav-filter-empty');
    if (cleared) { cleared.hidden = true; cleared.textContent = ''; }
    NAV_FILTER_STATE.q = '';
    return;
  }

  // Potret keadaan buka/tutup sekali saja, pada ketukan pertama.
  if (!NAV_FILTER_STATE.before) {
    NAV_FILTER_STATE.before = new Map(groups.map((node) => [node.dataset.label, node.dataset.open]));
  }
  nav.dataset.filtering = 'true';

  let hits = 0;
  for (const node of groups) {
    let groupHits = 0;
    // Pemisah menyusul barisnya: ia disembunyikan kalau blok di bawahnya habis.
    let divider = null;
    let dividerHits = 0;
    for (const child of node.querySelectorAll('.nav-items > *')) {
      if (child.classList.contains('nav-divider')) {
        if (divider) divider.hidden = dividerHits === 0;
        divider = child; dividerHits = 0;
        continue;
      }
      const label = (child.querySelector('.lbl') || {}).textContent || '';
      const match = label.toLowerCase().includes(q);
      child.hidden = !match;
      if (match) { groupHits += 1; dividerHits += 1; }
    }
    if (divider) divider.hidden = dividerHits === 0;
    node.hidden = groupHits === 0;
    if (groupHits) node.dataset.open = 'true';
    hits += groupHits;
  }
  if (status) status.textContent = hits ? `${hits} menu cocok` : 'Tidak ada menu yang cocok';
  // Nol kecocokan HARUS terbaca mata, bukan hanya pembaca layar: sidebar yang
  // kosong tanpa satu kalimat pun tidak bisa dibedakan dari sidebar yang rusak.
  const empty = nav.querySelector('.nav-filter-empty');
  if (empty) {
    empty.hidden = hits !== 0;
    empty.textContent = hits ? '' : `Tidak ada menu yang cocok dengan “${raw.trim()}”`;
  }
  NAV_FILTER_STATE.q = raw;
}

function navFilterNode(nav) {
  const input = el('input.nav-filter-input', {
    type: 'search', value: NAV_FILTER_STATE.q,
    placeholder: 'Saring menu…',
    'aria-label': 'Saring menu',
    autocomplete: 'off', spellcheck: 'false',
  });
  input.addEventListener('input', () => applyNavFilter(nav, input.value));
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && input.value) {
      // Esc mengosongkan kotak; Esc pada kotak yang SUDAH kosong dibiarkan naik
      // supaya ia tetap menutup laci ponsel seperti sebelumnya.
      event.stopPropagation();
      input.value = '';
      applyNavFilter(nav, '');
    }
  });
  const railButton = el('button.rail-toggle', { type: 'button', 'aria-controls': 'nav-utama' });
  railButton.addEventListener('click', toggleRail);
  return el('.nav-filter', [
    svgIcon('search', { size: 14 }),
    input,
    // Hitungan dibacakan pembaca layar; ia tidak menggeser tata letak.
    el('span.nav-filter-status.sr-only', { role: 'status', 'aria-live': 'polite' }),
    railButton,
  ]);
}

function renderNav(nav) {
  clear(nav);
  nav.appendChild(navFilterNode(nav));
  nav.appendChild(el('p.nav-filter-empty', { hidden: true }));
  const groups = navForSession();
  const favorites = prefs.favorites();
  const stored = storedOpenGroups();
  /* Lapis 1 (UX-D.2): baris tetap dari NAV (`pinned: true`), digambar di atas
     Favorit dan dilewatkan di grupnya. Izinnya sudah disaring visibleNav(). Bukan
     .nav-group: ia tidak punya kepala yang bisa dilipat, dan pembaca luar yang
     membaca `.nav-group > button` (S5) tidak boleh menemukan grup tanpa tombol. */
  const pinned = groups.flatMap((group) => group.items.filter((item) => item.pinned).map((item) => ({ ...item, prefix: group.prefix })));
  if (pinned.length) {
    nav.appendChild(el('.nav-pinned', { 'aria-label': 'Ruang kerja saya' }, [
      el('.nav-items', pinned.map((item) => {
        const node = navItemNode({ ...item, starrable: false }, favorites);
        node.querySelector('a').dataset.prefix = item.prefix;
        node.querySelector('a').title = item.label; // tooltip di rel ikon (UX-D.6)
        return node;
      })),
    ]));
  }
  const unpinned = groups.map((group) => ({ ...group, items: group.items.filter((item) => !item.pinned) }));
  for (const group of [...shortcutGroups(groups), ...unpinned]) {
    nav.appendChild(navGroupNode(nav, group, favorites, stored));
  }
  paintInboxCounts();
  // Sidebar dibangun ulang tiap bintang dan tiap dokumen yang dibuka. Kalau
  // sebuah saringan sedang aktif, ia harus selamat dari pembangunan itu —
  // kalau tidak, memberi bintang saat menyaring akan menumpahkan 149 tautan
  // kembali ke layar.
  if (NAV_FILTER_STATE.q) {
    NAV_FILTER_STATE.before = null;
    applyNavFilter(nav, NAV_FILTER_STATE.q);
  }
  updateRailToggle();
}

/* Bangun ulang seluruh sidebar (121 tautan, sekali gambar) alih-alih menambal
   satu grup: satu jalur kode untuk bintang, dokumen terakhir, dan izin yang
   berubah. Status aktif dipasang lagi karena ia tidak pernah disimpan. */
function refreshNav() {
  const nav = document.querySelector('nav.nav');
  if (!nav) return;
  renderNav(nav);
  setActiveNav(currentPath().split('?')[0]);
  // Izin bisa berubah sesudah refreshMe(): Buat ▾ dan bilah bawah ikut, tanpa membangun ulang bilah atas.
  const create = document.querySelector('.header .create-menu');
  if (create) create.hidden = creatable().length === 0;
  renderBottomNav();
}

/*
 * Lencana jumlah persetujuan (UX-D.2, ERRATA E-4): setiap [data-count="inbox"]
 * di shell (sidebar, bilah bawah). Belum ditanyakan → tersembunyi; 0 → tersembunyi
 * (tidak ada yang menunggu, tidak perlu lencana); meta.failed tidak kosong →
 * angkanya BUKAN jumlah sebenarnya, jadi ia tidak boleh tampil telanjang:
 * "N+" dengan kalimat tugas.js ("Daftar ini belum lengkap") sebagai title.
 */
function paintInboxCounts() {
  const summary = inboxSummary();
  for (const node of document.querySelectorAll('[data-count="inbox"]')) {
    if (!summary) { node.hidden = true; continue; }
    const incomplete = summary.failed.length > 0;
    node.hidden = summary.total === 0 && !incomplete;
    node.textContent = incomplete ? `${summary.total}+` : String(summary.total);
    node.classList.toggle('incomplete', incomplete);
    node.title = incomplete
      ? `Gagal dimuat: ${summary.failed.join(', ')}. Daftar ini belum lengkap — sekurang-kurangnya ${summary.total} menunggu.`
      : `${summary.total} dokumen menunggu keputusan Anda`;
    node.setAttribute('aria-label', node.title);
  }
}
window.addEventListener('erp:inbox-changed', paintInboxCounts);

/*
 * Bilah bawah ≤ 760 px (UX-D.5, ERRATA E-4): Beranda · Persetujuan · Cari · Menu.
 *
 * Di ponsel sidebar adalah laci, jadi sebelum ini setiap perpindahan dimulai dengan
 * hamburger di pojok atas — sasaran yang paling jauh dari ibu jari. Isinya DATA, bukan
 * daftar kedua: dua slot pertama adalah baris tetap NAV (`pinned`) yang lolos
 * visibleNav() — Beranda, lalu Persetujuan bagi pemegang hak approve. ERRATA E-4
 * memberi slot itu kepada Pekerjaan Saya bagi yang lain; layar itu baru lahir di UX-F2,
 * dan begitu barisnya masuk NAV dengan `pinned: true` ia mengisi slot ini tanpa satu
 * baris pun berubah di sini. Sampai saat itu bilahnya tiga tombol bagi non-penyetuju.
 * Menu membuka laci yang SAMA dengan hamburger (ui.drawer()).
 */
const shellDrawer = drawer();

function bottomNavItems() {
  const pinned = navForSession().flatMap((group) => group.items.filter((item) => item.pinned));
  const home = pinned.find((item) => item.route === 'home');
  const second = pinned.find((item) => item.route !== 'home');
  const link = (item) => el('a.bn-item', { href: `#/${item.route}`, dataset: { route: item.route } }, [
    svgIcon(item.icon || 'home', { size: 20 }),
    el('span.bn-label', { text: item.label }),
    item.count ? el('span.nav-count', { dataset: { count: item.count }, hidden: true }) : null,
  ]);
  const action = (label, iconName, onClick, attrs = {}) => {
    const node = el('button.bn-item', { type: 'button', ...attrs }, [svgIcon(iconName, { size: 20 }), el('span.bn-label', { text: label })]);
    node.addEventListener('click', onClick);
    return node;
  };
  return [
    home ? link(home) : null,
    second ? link(second) : null,
    action('Cari', 'search', () => openSearch(), { title: 'Pencarian global' }),
    action('Menu', 'menu', () => shellDrawer.toggle(), {
      'aria-controls': 'nav-utama', 'aria-expanded': String(shellDrawer.isOpen()), 'data-drawer-toggle': '',
    }),
  ].filter(Boolean);
}

function renderBottomNav() {
  const host = document.querySelector('.bottom-nav');
  if (!host) return;
  clear(host);
  bottomNavItems().forEach((node) => host.appendChild(node));
  paintInboxCounts();
  markBottomNav(currentPath().split('?')[0]);
}

function markBottomNav(path) {
  document.querySelectorAll('.bottom-nav a[data-route]').forEach((link) => {
    const on = link.dataset.route === path;
    link.classList.toggle('active', on);
    if (on) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
  });
}
// Sesudah pindah halaman angka yang berumur lebih dari satu menit ditanyakan lagi —
// keputusan di layar dokumen mengubahnya, dan lencana yang berbohong sejam lebih
// buruk daripada satu permintaan ringan. loadInbox() diam tanpa izin approve.
window.addEventListener('hashchange', () => { loadInbox().catch(() => {}); });

/*
 * Tombol pencarian header, dan bentuk medannya di layar lebar (UI-3b).
 *
 * Dua hiasan ditambahkan, KEDUANYA aria-hidden supaya nama aksesibel tombolnya
 * tetap "Cari" — yang datang dari span label dan yang aturan ≤ 760 px andalkan:
 * petunjuk panjang yang membuatnya terbaca sebagai medan, dan chip pintasannya.
 * Keduanya hanya tergambar ≥ 1200 px; di bawah itu tombolnya persis seperti
 * sebelumnya, dan di bawah 760 px ia menyusut jadi ikon seperti sebelumnya.
 */
function commandField() {
  const node = Object.assign(button('Cari', {
    variant: 'ghost',
    iconName: 'search',
    title: 'Pencarian global (Ctrl+K)',
    onClick: () => openSearch(),
  }), { className: 'btn ghost global-search' });

  node.appendChild(el('span.gs-hint', { text: 'menu, dokumen, pelanggan…', 'aria-hidden': 'true' }));
  node.appendChild(el('kbd.gs-kbd', { text: navigator.platform.startsWith('Mac') ? '⌘K' : 'Ctrl K', 'aria-hidden': 'true' }));
  return node;
}

function buildShell() {
  clear(root);
  root.className = '';

  const user = session.user || {};
  const main = el('main.main', { id: 'view' });

  const nav = el('nav.nav', { 'aria-label': 'Navigasi utama' });
  renderNav(nav);

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
  menuToggle.setAttribute('aria-expanded', 'false');
  menuToggle.setAttribute('aria-controls', 'nav-utama');
  nav.id = 'nav-utama';

  /*
   * Laci ponsel: buka, tutup, dan SEMUA jalan keluarnya (UI-3).
   *
   * Sebelum ini laci hanya punya satu jalan keluar — menekan hamburger lagi, atau
   * mengikuti sebuah tautan. Menekan Esc tidak melakukan apa-apa dan menyentuh
   * kain gelap di sebelahnya juga tidak: kainnya `body.nav-open::after`, sebuah
   * pseudo-element, yang tidak bisa memegang pendengar. Keduanya adalah gerak
   * refleks orang yang membuka laci dan berubah pikiran.
   */
  /* Laci TERTUTUP harus keluar dari urutan Tab, bukan sekadar bergeser ke luar
     layar. Sebelum ini ia disembunyikan `transform` saja, jadi ke-150 tautannya
     — dan sejak UI-3a kotak saringnya juga — tetap bisa dijelajahi Tab oleh orang
     yang tidak pernah membukanya: fokus menghilang ke dalam panel tak terlihat.

     Dipakai `inert`, BUKAN `visibility: hidden`. Yang kedua sempat dicoba dan
     menjatuhkan seluruh harness dalam satu langkah: setiap skenario masuk lewat
     `wait_for_selector('nav.nav')`, dan nav yang visibility-hidden tidak pernah
     "visible" bagi Playwright — ke-77 skenario gagal di baris login, bukan pada
     hal yang diujinya. `inert` mencabut fokus tanpa menyentuh keterlihatan.

     Hanya berlaku di lebar laci: di atas 760 px sidebar adalah kolom permanen
     dan harus tetap bisa dijelajahi. */
  const drawerQuery = window.matchMedia('(max-width: 760px)');
  const syncInert = () => {
    nav.inert = drawerQuery.matches && !document.body.classList.contains('nav-open');
  };
  drawerQuery.addEventListener('change', syncInert);
  /* Kelasnya DIAMATI, bukan disetel dari satu tempat. Panduan onboarding membuka
     laci dengan menulis `document.body.classList.add('nav-open')` langsung
     (views/onboarding.js:462) dan menutupnya lagi dua detik kemudian — ia tidak
     lewat setDrawer(). Tanpa pengamat ini laci tur akan tergambar terbuka tetapi
     tetap inert: terlihat, dan tidak bisa disentuh siapa pun. */
  new MutationObserver(syncInert).observe(document.body, { attributeFilter: ['class'] });

  // Satu mekanisme laci (UX-C.3): ui.drawer() memegang kelasnya dan aria-expanded
  // hamburger; bilah bawah UX-D membuka laci lewat pegangan yang sama persis.
  const navDrawer = drawer();
  const setDrawer = (open) => {
    if (open) navDrawer.open(); else navDrawer.close();
    syncInert();
  };
  syncInert();
  menuToggle.addEventListener('click', () => setDrawer(!document.body.classList.contains('nav-open')));

  nav.addEventListener('click', (event) => {
    if (event.target.closest('a')) setDrawer(false);
  });

  document.addEventListener('keydown', (event) => {
    // Esc menutup laci — kecuali kalau ada dialog di atasnya, yang berhak
    // menutup dirinya sendiri lebih dulu.
    if (event.key !== 'Escape' || !document.body.classList.contains('nav-open')) return;
    if (modalDepth && modalDepth()) return;
    setDrawer(false);
    // Hamburger di ponsel, tombol rel di tablet/desktop: fokus kembali ke yang tergambar.
    const back = [menuToggle, document.querySelector('.rail-toggle')].find((node) => node && node.offsetParent !== null);
    if (back) back.focus();
  });

  document.addEventListener('pointerdown', (event) => {
    // Sentuhan di luar laci menutupnya. Hamburger dikecualikan: ia punya
    // pengalihnya sendiri, dan tanpa pengecualian ini keduanya saling
    // membatalkan sehingga laci tidak pernah terbuka.
    if (!document.body.classList.contains('nav-open')) return;
    if (event.target.closest('nav.nav') || event.target.closest('.menu-toggle, [data-drawer-toggle]')) return;
    setDrawer(false);
  });

  root.appendChild(el('.shell', [
    el('.brand', [
      el('img', { src: 'favicon.svg', alt: '', width: 28, height: 28 }),
      el('.brand-text', [el('strong', { text: 'Nusantara ERP' }), el('span', { text: 'Konstruksi & SI' })]),
    ]),
    el('header.header', [
      menuToggle,
      // Rumah ke launcher (P1-C), di sebelah hamburger dan sebelum remah roti:
      // di ponsel remah roti hanya menyebut TEMPAT SEKARANG, dan sebelum ini
      // satu-satunya jalan pulang adalah membuka laci menu lebih dulu.
      Object.assign(button('', {
        variant: 'ghost', iconName: 'home', title: 'Beranda', onClick: () => navigate('home'),
      }), { className: 'btn ghost icon home-btn' }),
      // <nav> berlabel: dua tautan di dalamnya (modul, layar) butuh landmark supaya
      // pembaca layar bisa melompat ke remah roti (verifikasi P1-B 5 Sep 2026).
      el('nav.crumbs', { id: 'crumbs', 'aria-label': 'Remah roti' }),
      el('.spacer'),
      // Kelas global-search: di ponsel labelnya disembunyikan secara VISUAL saja (app.css
      // ≤ 760 px; nama tombol tetap dari span-nya) supaya header 390 px tidak melebihi
      // lebarnya — verifikasi P1-B 5 Sep 2026: berlabel, remah 'Keuangan' pun terpotong.
      //
      // MEDAN PERINTAH (UI-3b). Ia tetap sebuah <button>, bukan <input>. Sebuah
      // input sungguhan akan memutus tiga hal sekaligus: nama aksesibelnya tidak
      // bisa lagi datang dari isinya (itulah yang aturan ≤ 760 px andalkan),
      // Ctrl+K sudah punya kotak ketiknya sendiri di dalam palet sehingga yang
      // kedua hanya menduplikasi keadaan, dan urutan fokus header bertambah satu
      // perhentian yang tidak menuju ke mana-mana.
      //
      // Yang berubah hanya BENTUKNYA, dan hanya di tempat yang terbukti punya
      // ruang. Diukur 16 Sep 2026 pada halaman dokumen: ruang bebas header
      // 1440 → 348 px, 1280 → 188 px, tetapi 1024 → 0, 820 → 0, 768 → 0 — di
      // ketiga lebar terakhir spacer sudah habis dan remah roti sendiri sudah
      // dimampatkan 377 → 149 px. Karena itu medan ini hanya muncul ≥ 1200 px;
      // di bawah itu tombolnya persis seperti sebelumnya.
      commandField(),
      createMenu(),
      notificationBell(),
      userButton,
    ]),
    nav,
    main,
    el('nav.bottom-nav', { 'aria-label': 'Navigasi bawah' }),
  ]));
  renderBottomNav();
  applyRail();

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
      themeControl(),
      densityControl(),
      installRow(),
    ]),
    footer: [
      button('Tutup', { onClick: () => dialog.close() }),
      // Menu akun hanya "Tutup · Keluar" sampai 2 Sep 2026 (HASIL-UJI §1, S9).
      button('Ganti kata sandi', { onClick: () => { dialog.close(); openChangePassword(); } }),
      // P-3a: kanal notifikasi luar, jam tenang, nomor WhatsApp — layar sendiri.
      button('Profil & Notifikasi', { onClick: () => { dialog.close(); navigate('profil'); } }),
      /* Jalan kembali ke panduan yang dilewati saat masuk (5 Sep 2026). Dibuka
         dari sini tidak mencatat apa pun; Lewati/Selesai di dalamnya tetap. */
      button('Panduan onboarding', { onClick: () => { dialog.close(); openOnboarding({ auto: false }).catch(() => {}); } }),
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

/*
 * Kontrol "Kepadatan" di dialog Akun (P1-B): tiga radio, berlaku seketika
 * (tanpa muat ulang) dan sejak P1-C diingat DI SERVER bersama favorit — jadi
 * pilihannya ikut orangnya ke tablet lapangan. Tema masih preferensi peramban:
 * terang/gelap mengikuti perangkat dan cahaya di sekitarnya, bukan orangnya.
 */
function densityControl() {
  const current = readDensity();
  // Petunjuk mengikuti perangkatnya (verifikasi P1-B 5 Sep 2026): di layar sentuh
  // tombol baris memegang sasaran jempol 36 px (app.css pointer: coarse), jadi baris
  // bertombol 43 (rapat) / 55 (normal DAN lega) — "48 px" akan berbohong di sana, dan
  // baris teks di ponsel hampir selalu membungkus (terukur: 0 baris teks satu-baris di
  // jurnal/item/proyek 390 px), jadi hanya angka bertombol yang disebut.
  const coarse = window.matchMedia('(pointer: coarse)').matches;
  const hints = coarse
    ? { compact: 'baris bertombol 43 px', normal: 'baris bertombol 55 px', comfortable: 'baris bertombol 55 px (= Normal)' }
    : { compact: '32 px per baris', normal: '38,5 px per baris', comfortable: '48 px per baris' };
  return el('fieldset.density-pick', [
    el('legend', { text: 'Kepadatan' }),
    ...Object.entries(DENSITIES).map(([value, label]) => {
      const input = el('input', { type: 'radio', name: 'density', value });
      input.checked = value === current;
      input.addEventListener('change', () => { if (input.checked) setDensity(value); });
      return el('label.check-row', [input, el('span', { text: label }), el('span.muted', { text: hints[value] })]);
    }),
    coarse ? el('p.density-note.muted', { text: 'Di layar sentuh tombol baris memegang sasaran jempol 36 px; baris teks yang membungkus mengikuti isinya.' }) : null,
  ]);
}

function setActiveNav(path) {
  markBottomNav(path);
  document.querySelectorAll('.nav-group.has-active').forEach((group) => group.classList.remove('has-active'));
  document.querySelectorAll('.nav-items a').forEach((link) => {
    const target = link.dataset.route;
    const active = path === target ||
      (target.startsWith('r/') && path.startsWith(`d/${target.slice(2)}/`)) ||
      (target === 'r/projects' && /^d\/projects\/\d+$/.test(path));
    link.classList.toggle('active', active);
    // Rute aktif di balik plafon tautan (UX-D.2) selalu tergambar.
    const item = link.closest('.nav-item');
    if (item) item.classList.toggle('cap-active', active && item.classList.contains('beyond-cap'));
    if (active) {
      const group = link.closest('.nav-group');
      // Grup rute aktif dibuka (tidak disimpan) — inilah pengecualian bawaan
      // tertutup. Favorit/Terakhir dibuka yang memuat rute yang sama tidak
      // dipaksa: yang dilipat sendiri oleh pemakainya tetap terlipat.
      // has-active = penanda grup beraksen (P1-B), juga hanya di grup asal.
      if (group && !group.dataset.kind) {
        group.dataset.open = 'true';
        group.classList.add('has-active');
      }
    }
  });
  // Beranda modul #/m/<prefix> tidak punya baris sendiri di sidebar; grupnya
  // yang ditandai dan dibuka, supaya orang tahu sedang berada di modul mana.
  if (path.startsWith('m/')) {
    const group = document.querySelector(`nav.nav .nav-group[data-prefix="${CSS.escape(path.slice(2))}"]`);
    if (group) {
      group.dataset.open = 'true';
      group.classList.add('has-active');
    }
  }
}

/* ----------------------------------------------------------------- routes */
function view() {
  const node = document.getElementById('view');
  node.scrollTop = 0;
  return clear(node);
}

/* `permission` menimpa gerbang `${moduleKey}.view` bawaan: sebuah layar yang
   digerbangi core.update harus menyebut core.update, bukan core.view. */
function accessDenied(host, moduleKey, permission) {
  host.appendChild(el('.alert.error', [
    icon('warn', 16),
    el('div', `Anda tidak memiliki hak akses "${permission || `${moduleKey}.view`}" untuk halaman ini.`),
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
  // P7 — keduanya menyusun baris milik dokumen/modul lain, jadi keduanya butuh
  // pemilih yang menampilkan baris aslinya; kisi generik hanya punya kotak id.
  tkdn: renderTkdnWorksheet,
  rkk: renderRkkDocument,
};

/*
 * Permukaan HALAMAN untuk openForm (UI-4d).
 *
 * form.js membangun isinya tanpa tahu ia akan duduk di mana; yang dibedakan
 * permukaan hanya dua hal — `requestClose()` yang bertanya lebih dulu, dan
 * `close()` yang tidak.
 *
 * Perbedaan sesungguhnya dengan dialog ada di JALAN KELUARNYA. Dialog punya
 * satu pintu dan `modal()` menjaganya dengan `dirty()`. Halaman tidak punya
 * pintu sama sekali: yang meninggalkannya adalah navigasi — tautan sidebar,
 * tombol Kembali peramban, Ctrl+K lalu Enter — dan tidak satu pun dari itu
 * pernah lewat sini. Karena itu penjaganya dipasang di ROUTER, dan
 * pembersihan drafnya ikut menumpang di sana: kalau ia hanya tergantung pada
 * tombol Batal, meninggalkan halaman lewat sidebar akan menyisakan draf
 * yatim selamanya.
 */
function formPagePresenter(host, { def, backTo }) {
  return (spec) => {
    let leaving = null;

    /* Dipanggil TEPAT SEKALI di tiap jalan keluar — tombol, simpan berhasil,
       atau navigasi yang diizinkan. clearLeaveGuard hanya melepas kalau yang
       terpasang memang milik kita. */
    /* keepDraft (UX-E3.7): dialog "Tinggalkan" di bawah BERJANJI isiannya tetap tersimpan
       sebagai draf, tetapi finish() → onClose() membuangnya (terukur 4 Okt 2026, S47:
       tidak ada kunci nusantara_erp_draft:… sesudah Tinggalkan). Jalan keluar yang
       bersih dan simpan berhasil tetap membuang; hanya Tinggalkan yang menyimpan. */
    const finish = (keepDraft = false) => {
      clearLeaveGuard(leaving);
      spec.onClose({ keepDraft });
    };

    leaving = async () => {
      if (!spec.dirty()) { finish(); return true; }
      const discard = await confirmDialog({
        title: 'Tinggalkan formulir ini?',
        message: `Perubahan pada ${def.labelOne} ini belum disimpan. Isiannya tetap tersimpan sebagai draf di peramban ini.`,
        confirmLabel: 'Tinggalkan',
        cancelLabel: 'Tetap di sini',
        tone: 'danger',
      });
      if (!discard) return false;
      finish(true);
      return true;
    };
    setLeaveGuard(leaving);

    clear(host);
    /*
     * Kaki formulir lengket (UX-E3.2, README E3), bukan tombol di kepala: diukur 4 Okt 2026
     * (ux-e3-probe-base.json) halaman PO 1143 px tinggi di 1440 (1622 di 390) dan jendelanya
     * yang menggulir — begitu pembaca sampai di tabel baris, Simpan di kepala sudah lewat
     * layar (PO/GRN/Penawaran di 390, PO di 1440). Kaki adalah anak TERAKHIR .form-page
     * supaya pembaca `.form-page …` harness tetap menemukannya; .page-head tinggal judul.
     */
    host.appendChild(el('.page-head', [el('div', [el('h1', { text: spec.title })])]));
    // Navigasi bagian (UX-E3.5) di kolom kiri bila formulirnya punya ≥ 2 langkah; .form-main memegang badan + kaki.
    host.appendChild(el(`.form-page${spec.width === 'wide' ? '.wide' : ''}${spec.nav ? '.has-nav' : ''}`, [
      spec.nav || null,
      el('.form-main', [spec.body, el('.form-foot', spec.footer)]),
    ]));
    /* Fokus awal = aturan dialog (ui.js modal(): input NYATA pertama, 30 ms, readonly
       dilewati). Terukur 4 Okt 2026 (ux-e3-probe-base.json): activeElement = body dan
       2 perhentian Tab sebelum medan pertama di halaman, 1 di dialog. Fokus tidak
       mengotori formulir (penjaga membaca snapshot), jadi S47 bersih_pergi tetap. */
    setTimeout(() => {
      const first = host.querySelector('.form-page input:not([type=hidden]):not([disabled]):not([readonly]), .form-page select:not([disabled]), .form-page textarea:not([disabled])');
      if (first && first.isConnected) first.focus({ preventScroll: true });
    }, 30);

    return {
      requestClose: async () => { if (await leaving()) navigate(backTo); },
      close: () => { finish(); },
    };
  };
}

function registerRoutes() {
  route('dashboard', () => {
    setCrumbs(['Dasbor']);
    setActiveNav('dashboard');
    const host = view();
    guard(host, () => renderDashboard(host));
  });

  /* `?tab=<kunci>` (P1-D): widget dasbor menaut ke laporan yang benar-benar
     memuat angkanya, bukan ke tab pertama. Query dipisah router sebelum
     pencocokan pola, jadi rute lama '#/reports' tidak berubah sedikit pun. */
  /* P1-F — penyusun laporan lintas modul. Tanpa guard() berizin tunggal:
     izin sebuah laporan adalah izin SUMBERnya, dan katalognya menyaring diri
     sendiri; peran tanpa satu sumber pun mendapat kalimat yang mengatakannya. */
  route('laporan-bebas', () => {
    setCrumbs(['Ringkasan', 'Laporan Bebas']);
    setActiveNav('laporan-bebas');
    renderLaporanBebas(view());
  });

  route('reports', (params, path, query) => {
    setCrumbs(['Keuangan', 'Laporan']);
    setActiveNav('reports');
    const host = view();
    const tab = new URLSearchParams(query || '').get('tab');
    guard(host, () => renderReports(host, tab));
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

  /* Pindai barcode (F-6). Gerbangnya inv.view: yang ditampilkan adalah kartu
     item dan saldo stoknya. Kameranya opsional dan layar mengatakan sendiri
     kapan ia tidak ada — jalur ketik selalu tersedia. */
  route('pindai', () => {
    setCrumbs(['Persediaan', 'Pindai Barcode']);
    setActiveNav('pindai');
    const host = view();
    if (!session.can('inv.view')) return accessDenied(host, 'inv');
    return guard(host, () => renderPindai(host));
  });

  /* Usulan pesan ulang (F-6): membaca kekurangan stok dan menawarkan PR DRAF.
     Gerbangnya inv.view karena angkanya adalah saldo persediaan; TOMBOL "Buat
     PR draf" di dalamnya menuntut prc.create tersendiri, dan endpoint-nya
     menegakkan itu sendiri — layar yang hanya menyembunyikan tombol adalah
     gerbang yang bisa dilewati siapa pun yang tahu alamat endpoint-nya. */
  route('usulan-pesan-ulang', () => {
    setCrumbs(['Persediaan', 'Usulan Pesan Ulang']);
    setActiveNav('usulan-pesan-ulang');
    const host = view();
    if (!session.can('inv.view')) return accessDenied(host, 'inv');
    return guard(host, () => renderReorder(host));
  });

  route('lapangan', () => {
    setCrumbs(['Proyek', 'Lapangan']);
    setActiveNav('lapangan');
    const host = view();
    return guard(host, () => renderLapangan(host));
  });

  /* "Absensi Saya" (F-4) TANPA gerbang izin, dan itu keputusan, bukan
     kelalaian: layar ini hanya menyentuh baris absensi milik pemanggilnya
     sendiri, dan orang yang paling membutuhkannya — tukang, teknisi, pengemudi
     — tidak memegang satu pun izin hr.*. Register absensi orang lain tetap
     dijaga hr.view di rutenya. Akun tanpa kartu karyawan mendapat kalimat dari
     server, bukan layar kosong. */
  /* Usulan rekap (F-4): hitungan register bulan itu sebagai isian awal formulir
     rekap yang sudah ada. hr.view karena angkanya adalah register absensi. */
  route('usulan-rekap', () => {
    setCrumbs(['SDM & Payroll', 'Usulan Rekap Absensi']);
    setActiveNav('usulan-rekap');
    const host = view();
    if (!session.can('hr.view')) return accessDenied(host, 'hr');
    return guard(host, () => renderUsulanRekap(host));
  });

  /* Timesheet & Lembur (F-5): turunan jam kerja/terlambat/lembur dari register
     absensi, disandingkan dengan jam ILB yang disetujui. hr.view karena isinya
     jam datang dan jam pulang orang — gerbang yang sama dengan register
     absensi sejak F-4. Layar ini tidak menyimpan apa pun. */
  route('timesheet', () => {
    setCrumbs(['SDM & Payroll', 'Timesheet & Lembur']);
    setActiveNav('timesheet');
    const host = view();
    if (!session.can('hr.view')) return accessDenied(host, 'hr');
    return guard(host, () => renderTimesheet(host));
  });

  /* "Timesheet Saya" (F-5) TANPA gerbang izin, dengan alasan yang sama seperti
     "Absensi Saya" di bawah: ia hanya menyentuh baris milik pemanggilnya
     sendiri, dan orang yang paling membutuhkannya — tukang, teknisi, pengemudi
     — tidak memegang satu pun izin hr.*. Sebuah timesheet yang hanya bisa
     dilihat HR adalah timesheet yang orangnya tidak pernah bisa membantah. */
  route('timesheet-saya', () => {
    setCrumbs(['Ringkasan', 'Timesheet Saya']);
    setActiveNav('timesheet-saya');
    const host = view();
    return guard(host, () => renderTimesheetSaya(host));
  });

  // P-3b — rekap internal PPh 21/26 bulanan (baca-saja, hr.view seperti API-nya).
  route('rekap-pph21', () => {
    setCrumbs(['SDM & Payroll', 'Rekap PPh 21 Bulanan']);
    setActiveNav('rekap-pph21');
    const host = view();
    if (!session.can('hr.view')) return accessDenied(host, 'hr');
    return guard(host, () => renderRekapPph21(host));
  });

  route('absensi-saya', () => {
    setCrumbs(['Ringkasan', 'Absensi Saya']);
    setActiveNav('absensi-saya');
    const host = view();
    return guard(host, () => renderAbsensiSaya(host));
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

  // P5 — Rekap Tagihan Alat: laporan billing periode PPK per vendor.
  route('rekap-alat', () => {
    setCrumbs(['Pengadaan', 'Rekap Tagihan Alat']);
    setActiveNav('rekap-alat');
    const host = view();
    if (!session.can('prc.view')) return accessDenied(host, 'prc');
    return guard(host, () => renderRekapAlat(host));
  });

  // P5 — Evaluasi Sewa vs Beli, baca saja.
  route('sewa-vs-beli', () => {
    setCrumbs(['Aset', 'Evaluasi Sewa vs Beli']);
    setActiveNav('sewa-vs-beli');
    const host = view();
    if (!session.can('ast.view')) return accessDenied(host, 'ast');
    return guard(host, () => renderSewaVsBeli(host));
  });

  /* P7 — Penyusun Kualifikasi: lampiran personil, alat dan subkon sebuah
     penawaran, dirakit baca-saja dari master SDM/Aset/Pengadaan.

     Digerbangi crm.view dan bukan hr.view/ast.view/prc.view, sama seperti
     gerbang rutenya di server: yang dilayani layar ini adalah tim tender, dan
     kolom yang dikembalikan servernya memang sudah sempit — nama, jabatan,
     sertifikat dan masa berlakunya; tidak ada gaji, harga perolehan, atau
     rekening yang bisa bocor lewat pintu ini. */
  route('kualifikasi', () => {
    setCrumbs(['Penjualan', 'Penyusun Kualifikasi']);
    setActiveNav('kualifikasi');
    const host = view();
    if (!session.can('crm.view')) return accessDenied(host, 'crm');
    return guard(host, () => renderKualifikasi(host));
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

  route('tugas', () => {
    setCrumbs(['Ringkasan', 'Persetujuan']);
    setActiveNav('tugas');
    const host = view();
    // Tanpa gerbang izin: core/inbox menyaring per {modul}.approve pemanggil.
    return guard(host, () => renderTugas(host));
  });

  /* P-3a — Profil & Notifikasi: kanal luar + jam tenang milik pemanggil
     sendiri. Tanpa gerbang izin: setiap endpoint-nya hanya menyentuh rekam
     pemanggil (core/me/*). */
  route('profil', () => {
    setCrumbs(['Ringkasan', 'Profil & Notifikasi']);
    setActiveNav('profil');
    const host = view();
    return guard(host, () => renderProfil(host));
  });

  /* P-3d — Sistem › Webhook. Gerbangnya core.update, sama dengan Pengiriman
     Notifikasi dan Antrean Gagal: ketiganya memutuskan ke mana peristiwa
     perusahaan ini dikirim. */
  route('webhook', () => {
    setCrumbs(['Sistem', 'Webhook']);
    setActiveNav('webhook');
    const host = view();
    /* V-OPENAPI-3: DIGERBANGI DI SINI, bukan hanya di API-nya. Sidebar memang
       menyembunyikan barisnya dari yang tidak berhak, tetapi orang membuka
       tautan yang di-share rekannya dan bookmark lama; tanpa baris ini layar
       menggambar teks mentah 403 server — «User does not have the right
       permissions.», berbahasa Inggris di layar berbahasa Indonesia — plus
       tombol «Coba lagi» yang tidak akan pernah berhasil dan satu galat konsol
       untuk setiap pemakai non-admin. Layar sebelah dengan gerbang yang sama
       (Pengiriman Notifikasi, Antrean Gagal) memakai kalimat rumah ini. */
    if (!session.can('core.update')) return accessDenied(host, 'core', 'core.update');
    return guard(host, () => renderWebhook(host));
  });

  route('tenggat', () => {
    setCrumbs(['Ringkasan', 'Tenggat']);
    setActiveNav('tenggat');
    const host = view();
    // Tanpa gerbang izin: API core/deadlines sudah menyaring entri menurut izin pemanggil.
    return guard(host, () => renderTenggat(host));
  });

  route('ambang', () => {
    setCrumbs(['Ringkasan', 'Ambang & Batas']);
    setActiveNav('ambang');
    const host = view();
    // Tanpa gerbang izin: API core/thresholds sudah menyaring ukuran menurut izin pemanggil.
    return guard(host, () => renderAmbang(host));
  });

  route('kalender', () => {
    setCrumbs(['Ringkasan', 'Kalender']);
    setActiveNav('kalender');
    const host = view();
    // Tanpa gerbang izin: API core/calendar sudah menyaring agenda menurut izin lihat pemanggil.
    return guard(host, () => renderKalender(host));
  });

  route('anggaran', () => {
    setCrumbs(['Keuangan', 'Anggaran vs Realisasi']);
    setActiveNav('anggaran');
    const host = view();
    if (!session.can('fin.view')) return accessDenied(host, 'fin');
    return guard(host, () => renderAnggaran(host));
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

  /* F-9 — ringkasan CSAT. Gerbangnya svc.view, sama dengan endpoint-nya:
     komentar pelanggan tentang seorang teknisi tidak pernah lebih longgar
     daripada itu. */
  route('csat', () => {
    setCrumbs(['Layanan', 'Kepuasan Pelanggan']);
    setActiveNav('csat');
    const host = view();
    if (!session.can('svc.view')) return accessDenied(host, 'svc');
    return guard(host, () => renderCsat(host));
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

  /* App launcher (P1-C) — halaman pertama di ponsel (keputusan pemilik #3) dan
     "Beranda" di sidebar/header pada semua lebar. Tanpa gerbang izin: kisinya
     dibangun dari visibleNav(), jadi orang tanpa satu layar pun mendapat
     keadaan kosong beranda itu sendiri — dan orang seperti itu tidak bisa
     masuk. */
  route('home', () => {
    setCrumbs(['Beranda']);
    setActiveNav('home');
    const host = view();
    guard(host, () => renderHome(host));
  });

  /* Beranda modul (P1-B) — sasaran remah modul; minimal: kepala beraksen +
     kartu layar yang boleh dibuka (views/module.js). Tanpa gerbang izin:
     grup yang izinnya tidak dipegang berakhir di keadaan kosong beranda itu
     sendiri, bukan panel akses-ditolak, karena grupnya memang tidak ada bagi
     orang itu. Prefix yang bukan grup NAV mana pun → alert "tidak dikenal". */
  route('m/:prefix', ({ prefix }) => {
    const module = moduleFor(prefix);
    const host = view();
    if (!module) {
      setCrumbs(['Tidak ditemukan']);
      host.appendChild(el('.alert.error', `Modul "${prefix}" tidak dikenal.`));
      return;
    }
    setCrumbs([module.label]);
    setActiveNav(`m/${prefix}`);
    guard(host, () => renderModuleHome(host, { prefix }));
  });

  // r/<resource path> — list screen
  /* b/<resource path> — papan kanban (P1-G). Gerbangnya PERSIS gerbang layar
     daftarnya (def.viewPerm || `${def.module}.view`): papan bukan data baru,
     ia tampilan kedua atas daftar yang sama. Resource tanpa blok `board:`
     menjawab kalimat, bukan layar kosong. */
  /*
   * Formulir sebagai HALAMAN (keputusan pemilik #2, UI-4d).
   *
   * Aturan pemutusnya sudah ditulis rencana dan sudah ada di kode: apakah
   * sumber daya menyatakan `lines`. Terukur pada schema.js — 92 sumber daya
   * berformulir, 35 menyatakan lines. Ketiga puluh lima itulah yang jadi
   * halaman; 57 sisanya tetap dialog, karena navigasi halaman untuk empat
   * medan lebih mahal daripada untungnya.
   *
   * Biayanya yang membuat keputusan ini diambil terukur di ASESMEN-UX §1.2:
   * pada viewport 900 px tabel baris di dalam modal 960 px mulai pada
   * y = 843 px — di bawah lipatan, sebelum satu baris pun diisi.
   *
   * `f/<sumber daya>` membuat, `f/<sumber daya>/<id>` mengubah. Keduanya
   * dibedakan dengan MENCOBA kunci utuh lebih dulu: kunci sumber daya tidak
   * pernah berakhir dengan ruas id, jadi tidak ada yang ambigu.
   */
  route('f/*', (_, path) => {
    const rest = path.slice(2);
    let key = rest;
    let id = null;
    if (!RESOURCES[key]) {
      const cut = rest.lastIndexOf('/');
      key = rest.slice(0, cut);
      id = rest.slice(cut + 1);
    }
    // README E3 / RECAP T4.2 menamai #/f/<sumber daya>/new; kunci sumber daya tidak pernah berakhir /new (UX-E3.7).
    if (id === 'new') id = null;
    const def = RESOURCES[key];
    const host = view();

    if (!def || !def.form) {
      host.appendChild(el('.alert.error', `Formulir "${key}" tidak dikenal.`));
      return;
    }

    const perm = id ? (def.updatePerm || `${def.module}.update`) : (def.createPerm || `${def.module}.create`);
    if (!session.can(perm)) return accessDenied(host, def.module, perm);

    setCrumbs(
      [groupLabelFor(key), def.label, id ? `Ubah #${id}` : `${def.labelOne} baru`],
      { screenHref: `#/r/${key}` },
    );
    setActiveNav(`r/${key}`);

    const backTo = id ? `d/${key}/${id}` : `r/${key}`;
    /*
     * Simpan & ajukan (UX-E3.2): aksi submit sumber daya ini, disaring seperti bilah aksi
     * menyaringnya (session.canAct) dan tanpa aksi yang membawa dialognya sendiri
     * (fields/confirm/inlineNote — PPK, subkontrak, kontrak mandor tetap lewat Ajukan).
     * Dirakit di sini dari actions.js supaya form.js tidak perlu mengimpornya.
     */
    const submitAction = (def.actions || []).find((action) => action.key === 'submit'
      && !action.fields && !action.confirm && !action.inlineNote);
    const submit = submitAction && session.canAct(submitAction) ? {
      action: submitAction,
      run: (saved) => {
        const payload = {};
        return withConfirmResubmit(submitAction, payload, () => postAction(submitAction, saved, def, payload));
      },
      done: (code, result) => toastActionDone(submitAction, code, result),
    } : null;
    guard(host, async () => {
      const row = id ? await api.get(`${def.api}/${id}`) : null;
      await openForm({
        def,
        key,
        row,
        fetched: Boolean(row), // rute sudah mengambil dokumen lengkap — openForm tidak mengambilnya lagi (UX-E3.7)
        present: formPagePresenter(host, { def, backTo }),
        onSaved: (saved) => navigate(saved && saved.id ? `d/${key}/${saved.id}` : backTo, { replace: true }),
        submit,
      });
    });
  });

  route('b/*', (_, path) => {
    const key = path.slice(2);
    const def = RESOURCES[key];
    const host = view();

    if (!def || !def.board) {
      host.appendChild(el('.alert.error', `Papan "${key}" tidak dikenal.`));
      return;
    }

    setCrumbs([groupLabelFor(key), `Papan ${def.label}`]);
    setActiveNav(`b/${key}`);

    if (!session.can(def.viewPerm || `${def.module}.view`)) return accessDenied(host, def.module);
    return guard(host, () => renderBoard(host, { key, def }));
  });

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

    // def.viewPerm (array = salah satu cukup, session.can sudah paham) menimpa
    // gerbang modul untuk layar lintas-modul: assets/equipment-logs dibaca
    // dengan ast.view ATAU prj.view — site manager memegang prj.* tanpa
    // ast.view, dan gerbang modul saja akan menolak justru orang yang mengisi
    // register itu. Cermin gerbang rutenya di server (permission:ast.view|prj.view).
    if (!session.can(def.viewPerm || `${def.module}.view`)) return accessDenied(host, def.module);
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

    setCrumbs([groupLabelFor(key), def.label, `#${id}`], { screenHref: `#/r/${key}` });
    setActiveNav(`d/${key}/${id}`);

    // Timpaan viewPerm yang sama dengan rute daftar r/* di atas.
    if (!session.can(def.viewPerm || `${def.module}.view`)) return accessDenied(host, def.module);

    const custom = def.customDetail && CUSTOM_DETAILS[def.customDetail];
    const route = `d/${key}/${id}`;

    // The custom screens build their own action row in one pass, so the house-
    // form catalogue has to be in hand BEFORE they render — renderDetail awaits
    // it itself. Cached for the session, so this costs one request in total.
    const shown = custom
      ? guard(host, async () => {
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
      })
      : guard(host, () => renderDetail(host, { key, def, id }));

    // Terakhir dibuka (T2.5) dicatat SETELAH layar tergambar: judulnya dibaca
    // dari remah roti, yang layar detail timpa dengan kode dokumen begitu
    // rekamannya tiba. Layar yang tidak pernah menggambar kepalanya (404,
    // galat — guard menggambar .alert.error saja) tidak dicatat: tautan ke
    // halaman galat bukan "terakhir dibuka". Bila halaman sudah berganti
    // sebelum selesai, atau kepalanya tidak mengisi remah roti, labelnya
    // nama sumber daya + id, bukan dibiarkan kosong.
    shown.then(() => {
      if (currentPath().split('?')[0] !== route || !host.querySelector('.page-head')) return;
      const crumb = document.querySelector('#crumbs b');
      const title = crumb && crumb.textContent.trim() !== `#${id}` ? crumb.textContent.trim() : '';
      prefs.rememberRecent(route, title || `${def.labelOne || def.label} #${id}`, def.labelOne || def.label);
    });

    return shown;
  });

  fallback((path) => {
    if (!path || path === '/') {
      landOnDefault();
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

/*
 * Label remah pertama untuk layar r/<key>: nama grup NAV yang memuatnya, dan
 * bila tidak ada grup yang memuatnya, nama modul si resource sendiri.
 *
 * Tujuh resource memang tidak ada di sidebar — dibuka dari layar lain, bukan
 * dari menu: projects/baselines, projects/defects, inventory/issue-returns,
 * inventory/purchase-returns, hr/certificates (schema.js) serta
 * finance/petty-cash-vouchers dan finance/kasbon (didaftarkan kaskecil.js).
 * Dulu semuanya berakar pada penanda 'ERP', yang bukan grup mana pun, sehingga
 * crumbs.js menandainya data-root='screen' — dan di ponsel HALAMAN DOKUMEN-nya
 * tetap menulis 'Keuangan' (kaskecil.js) alias data-root='module'. Daftar dan
 * dokumennya karena itu berganti subjek: '#/r/finance/kasbon' membaca 'Kasbon',
 * '#/d/finance/kasbon/1' membaca 'Keuangan' (diukur 390×844 dan 1440×900,
 * verifikasi P1-B putaran 2). Modul resource-nya adalah jawaban yang sama untuk
 * keduanya, dan bertaut ke beranda modul yang memang boleh dibuka pemakainya:
 * rute r/* menolak siapa pun tanpa `<module>.view`, izin yang sama yang membuat
 * beranda modul itu berisi.
 */
function groupLabelFor(key) {
  const group = NAV.find((entry) => entry.items.some((item) => item.route === `r/${key}`));
  if (group) return group.label;
  const def = RESOURCES[key];
  const module = def ? moduleFor(def.module) : null;
  return module ? module.label : 'ERP';
}

/* ------------------------------------------------------------------- boot */
let routesRegistered = false;

async function boot() {
  // Sesudah masuk id pengguna sudah ada: kepadatan MILIKNYA dipasang sebelum
  // shell digambar (cermin prefs.js; evaluasi modul di atas membaca cermin
  // pengguna sebelumnya atau 'anon').
  applyDensity(readDensity());
  startNotificationPolling();
  buildShell();
  // Lencana Persetujuan (UX-D.2): satu permintaan, dibagi dengan widget dasbor
  // lewat js/inbox.js; tidak ada permintaan tanpa izin approve.
  loadInbox().catch(() => {});

  if (!routesRegistered) {
    registerRoutes();
    // Bound to the document once, not per shell rebuild: a re-login would
    // otherwise stack a second listener and open two dialogs on one Ctrl+K.
    registerSearchShortcut();
    routesRegistered = true;
    landOnDefault();
    start();
  } else {
    /* Masuk ULANG di tab yang sama (sesi berakhir di tengah kerja): halaman yang
       sedang dibaca orangnya dipulihkan, dan landOnDefault() di bawah karena itu
       tidak melakukan apa-apa — hash-nya sudah terisi. Itu disengaja: yang baru
       saja kehilangan sesi sedang mengerjakan sesuatu (offerDrafts menawarkan
       isiannya kembali di baris berikutnya), dan melemparnya ke launcher berarti
       menghilangkan tempat ia berada. Aturan landing berlaku pada muat halaman
       yang tanpa hash — yaitu masuk yang sesungguhnya pertama. */
    const path = currentPath();
    navigate('dashboard', { replace: true });
    if (path !== 'dashboard') navigate(path, { replace: true });
    else {
      landOnDefault();
      start();
    }
  }

  // Refresh permissions in the background — roles may have changed since login.
  // The onboarding decision rides on the same answer, so it waits for it.
  // Preferensi ikut di belakangnya, dan dengan alasan yang sama: keputusan yang
  // dibuat di perangkat lain harus menang atas cermin lokal peramban ini.
  refreshMe()
    .catch(() => {})
    .then(() => prefs.load().catch(() => {}))
    .then(() => {
      // Jawaban server boleh berbeda dari cermin (dipilih di perangkat lain,
      // atau baru saja dinaikkan dari localStorage P1-B): pasang lagi.
      applyDensity(readDensity());
      refreshNav();
      maybeShowOnboarding();
    });
  offerDrafts();
}

/*
 * Landing sesudah masuk: #/home di SETIAP lebar (ERRATA D-2, ledger baris 18 —
 * UX-D.1). Keputusan pemilik #3 ("dasbor di meja, launcher di ponsel") dicabut:
 * alasannya satu-satunya adalah bahwa di ≤ 760 px dasbor tidak punya jalan keluar
 * yang terlihat sampai hamburger ditekan, dan bilah bawah UX-D menghapus alasan
 * itu. #/dashboard tetap satu klik: baris "Dasbor" di Ringkasan dan tombol
 * "Dasbor" di kepala Beranda. 760 px tetap titik lipat sidebar (app.css) dan tetap
 * dipaku LauncherWiringTest; ia hanya tidak lagi memilih halaman.
 *
 * Hanya berlaku ketika TIDAK ADA hash: tautan-dalam (notifikasi ke
 * `#/d/finance/ap-bills/12`, tab yang dipulihkan peramban, tombol Kembali)
 * mendarat di tempat yang diminta. Karena itu location.hash yang dibaca, bukan
 * currentPath() yang mengarang 'dashboard' saat hash kosong.
 */
function landOnDefault() {
  const hash = location.hash;
  if (hash && hash !== '#' && hash !== '#/') return;
  navigate('home', { replace: true });
}

/*
 * Panduan onboarding muncul di SETIAP masuk — akun lama maupun baru — sampai
 * orangnya menekan Lewati atau Selesai, dan tidak pernah lagi sesudahnya
 * (permintaan pemilik 5 Sep 2026: "on boarding is not working" — panduan
 * hanya ada sebagai berkas docs, tidak pernah tampil di aplikasi). Sejak v2
 * (masukan pemilik 5 Sep 2026: "show the intended page/location while user
 * displayed the onboarding … also make it on mobile version") yang dibuka
 * bukan modal melainkan panel berlabuh / lembar bawah yang memindah halaman
 * ke layar yang dibicarakan tiap langkah — views/onboarding.js.
 *
 * Yang dibaca adalah onboarding_status di auth/me, BUKAN localStorage:
 * keputusan harus mengikuti orangnya ke tablet lapangan yang dipakai
 * bergantian, dan tidak boleh diwarisi orang berikutnya di peramban yang
 * sama. Dipanggil sesudah refreshMe() supaya salinan sesi sudah memuat
 * keputusan terbaru dari server, termasuk yang dibuat di perangkat lain;
 * refreshMe() yang gagal (luring) jatuh ke salinan localStorage.
 */
function maybeShowOnboarding() {
  const user = session.user;
  if (!user || user.onboarding_status) return;
  openOnboarding({ auto: true }).catch(() => {});
}

/*
 * Sesi berakhir di tengah pekerjaan. Urutannya penting: (1) simpan draf
 * formulir yang sedang terbuka, (2) tutup paksa semua overlay TANPA membuang
 * draf itu, (3) baru gambar halaman masuk — yang dulu digambar DI BAWAH modal
 * yang masih terbuka, sehingga tombol Masuk tertutup backdrop dan jalan keluar
 * satu-satunya adalah Esc → "Buang isian" (diukur 2 Sep 2026).
 */
setUnauthorizedHandler(() => {
  flushAll();
  suspendDraftRemoval(true);
  try { closeAllModals(); } finally { suspendDraftRemoval(false); }
  const drafts = listDrafts();
  renderLogin({
    message: drafts.length
      ? `Sesi Anda berakhir. Isian ${drafts[0].label} yang sedang Anda buat tersimpan di peramban ini — masuk kembali untuk memulihkannya.`
      : 'Sesi Anda berakhir. Silakan masuk kembali.',
  });
});

/* Setelah masuk: tawarkan draf yang tertinggal, sekali, dengan jalan langsung
   ke formulirnya. Toast bertahan sampai ditutup — orang yang baru saja
   kehilangan sesi tidak boleh kehilangan tawarannya dalam lima detik. */
function offerDrafts() {
  const drafts = listDrafts().filter((d) => RESOURCES[d.resourceKey]);
  if (!drafts.length) return;
  const d = drafts[0];
  const node = toast(`Ada isian ${d.label} yang belum tersimpan (${relativeAge(d.savedAt)}).`, {
    tone: 'info', timeout: 0, title: 'Draf tersimpan di peramban ini',
  });
  node.querySelector('.msg').appendChild(el('.row-actions', { style: { marginTop: '8px' } }, [
    button('Pulihkan', {
      size: 'sm', variant: 'primary',
      onClick: () => {
        node.remove();
        const key = d.resourceKey;
        // startForm, bukan openForm (UX-E3.7): sumber daya berbaris dipulihkan di HALAMANNYA
        // (rute f/ mengambil dokumen lalu openForm menemukan drafnya dan bertanya "Pulihkan isian…?").
        // Sesi yang habis DI halaman formulir meninggalkan hash-nya apa adanya: navigate() ke hash
        // yang sama tidak memicu hashchange (terukur 4 Okt 2026, S4 — main kosong, tidak ada dialog),
        // jadi rute yang sama di-resolve ulang.
        const target = d.rowId ? `f/${key}/${d.rowId}` : `f/${key}`;
        if (formIsPaged(RESOURCES[key]) && currentPath() === target) {
          resolve();
          return;
        }
        startForm({
          def: RESOURCES[key], key, row: d.rowId ? { id: d.rowId } : null,
          onSaved: (saved) => navigate(saved && saved.id && !RESOURCES[key].noDetail ? `d/${key}/${saved.id}` : `r/${key}`),
        });
      },
    }),
    button('Buang', { size: 'sm', variant: 'ghost', onClick: () => { removeDraft(d.resourceKey, d.rowId); node.remove(); } }),
  ]));
}

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

/* ---------------------------------------------------------------- PWA (P1-I) */

/*
 * Tiga hal, satu tempat: mendaftarkan service worker, menangkap tawaran pasang
 * peramban, dan mengumumkan versi baru.
 *
 * Pendaftarannya SESUDAH aplikasi tergambar, tidak pernah sebelum. sw.js
 * memasang 102 berkas cangkang ke cache saat install, dan itu 102 permintaan
 * yang tidak boleh berebut dengan permintaan yang sedang ditunggu orangnya.
 * Karena itu: tunggu `load`, lalu satu putaran idle.
 */
let swRegistration = null;
let installPrompt = null;
let reloadOnControllerChange = false;
let updateToast = null;
let installPromptUsed = false;
let appInstalled = false;

/**
 * Sudah terpasang. `display-mode: standalone` hanya benar di JENDELA aplikasi,
 * jadi tab yang baru saja memasangnya tetap membaca false — karena itu
 * peristiwa `appinstalled` ikut diingat (P1-I, verifikasi 7 Sep 2026: tanpa itu
 * dialog Akun berkata "peramban ini belum menawarkannya" kepada orang yang baru
 * saja memasang aplikasinya dari dialog itu).
 */
function appIsInstalled() {
  return appInstalled || window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
}

/*
 * Peramban menawarkan pemasangan lewat peristiwa ini, dan tawarannya HANGUS
 * kalau tidak ditahan: preventDefault() menyimpannya sampai orangnya membuka
 * menu Akun. Didaftarkan di tingkat modul supaya sudah terpasang sebelum
 * peristiwa itu bisa menyala.
 *
 * Tidak semua peramban menyalakannya (Firefox dan Safari tidak punya
 * beforeinstallprompt sama sekali, dan Chromium headless juga tidak — terukur
 * 7 Sep 2026). Dialog Akun karena itu punya tiga keadaan, bukan dua: tombol,
 * "sudah terpasang", atau kalimat yang menyebut jalan lewat menu peramban.
 */
window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  installPrompt = event;
});

window.addEventListener('appinstalled', () => {
  installPrompt = null;
  appInstalled = true;
});

/**
 * Baris "Pasang aplikasi" untuk dialog Akun. Menu akun dipilih, bukan launcher
 * #/home: launcher adalah kisi MODUL (Proyek, Keuangan, …) dan sebuah tindakan
 * peramban di antaranya salah kategori, lagipula launcher hanya satu layar
 * sementara chip akun ada di header setiap layar — termasuk di ponsel, yaitu
 * perangkat yang justru dipasangi.
 */
function installRow() {
  if (appIsInstalled()) {
    return el('p.muted', { text: 'Aplikasi ini sudah terpasang di perangkat Anda.', style: { fontSize: '12.5px', margin: '14px 0 0' } });
  }

  /*
   * "Tawarannya sudah dipakai" BUKAN "belum pernah ditawarkan" (verifikasi
   * 7 Sep 2026). Tombol di bawah membuang `installPrompt` sebelum prompt() —
   * benar menurut spesifikasi, tawaran peramban hanya sah sekali — sehingga
   * dialog Akun yang dibuka lagi sesudahnya dulu berbunyi "Peramban ini belum
   * menawarkannya dari dalam halaman" kepada orang yang baru saja menekan
   * tawarannya, tanpa jalan kembali selain memuat ulang halaman. Terukur pada
   * jalur tolak (prompt_calls 1, tombol disabled) maupun jalur terima.
   */
  if (installPromptUsed) {
    return el('p.muted', {
      text: 'Tawaran pemasangan sudah dipakai pada kunjungan ini. Muat ulang halaman untuk '
        + 'menawarkannya lagi, atau pasang lewat menu peramban.',
      style: { fontSize: '12.5px', margin: '14px 0 0' },
    });
  }

  if (!installPrompt) {
    /*
     * Kalimat terakhir ini yang dibaca pemakai Firefox desktop (menunya memang
     * tidak punya "Instal aplikasi") dan pemakai iPhone (jalannya lembar
     * Bagikan, bukan menu peramban) — jadi keduanya disebut, bukan hanya satu.
     */
    return el('p.muted', {
      text: 'Pasang aplikasi lewat peramban: menu "Instal aplikasi" di Chrome atau Edge, dan di '
        + 'iPhone lewat tombol Bagikan → "Tambahkan ke Layar Utama". Peramban ini belum '
        + 'menawarkannya dari dalam halaman.',
      style: { fontSize: '12.5px', margin: '14px 0 0' },
    });
  }

  return el('div', { style: { marginTop: '14px' } }, [
    button('Pasang aplikasi', {
      iconName: 'download',
      onClick: async (event) => {
        const deferred = installPrompt;
        if (!deferred) return;
        // Tawaran peramban hanya boleh dipakai SEKALI.
        installPrompt = null;
        installPromptUsed = true;
        event.currentTarget.disabled = true;
        deferred.prompt();
        const choice = await deferred.userChoice.catch(() => null);
        if (choice && choice.outcome === 'accepted') toast('Aplikasi sedang dipasang.');
      },
    }),
    el('p.muted', {
      text: 'Membuka aplikasi dari layar utama, tanpa bilah alamat.',
      style: { fontSize: '12.5px', margin: '6px 0 0' },
    }),
  ]);
}

/*
 * Worker baru sudah terpasang dan MENUNGGU. Toast-nya bertahan (timeout 0):
 * pengumuman rilis yang lenyap dalam lima detik tidak mengumumkan apa pun.
 * Tombolnya menyuruh worker menunggu itu mengambil alih; muat ulang menyusul
 * di controllerchange, bukan di sini, supaya halaman tidak sempat dimuat ulang
 * sebelum worker barunya siap menjawab.
 */
function announceUpdate(registration) {
  /*
   * SATU pengumuman, bukan satu per rilis. Toast ini bertahan (timeout 0), jadi
   * tanpa baris ini setiap rilis menumpuk satu kalimat permanen yang berbunyi
   * persis sama di tab yang dibiarkan terbuka. Terukur 7 Sep 2026: rilis kedua
   * dan ketiga tanpa ada yang menekan apa pun meninggalkan dua toast identik,
   * masing-masing 88 px di 390x844, di atas hosting toast yang sudah menutup
   * 104 px dasar layar — tablet lapangan yang tidak pernah ditutup adalah
   * perangkat yang paling mungkin mengalaminya.
   *
   * Yang lama dibuang, bukan yang baru dilewati: `registration` yang dipegang
   * toast lama sudah menunjuk worker yang menunggu SEBELUMNYA.
   */
  if (updateToast) updateToast.remove();

  updateToast = toast('Versi baru siap — Muat ulang untuk memakainya.', {
    tone: 'info',
    timeout: 0,
    action: {
      label: 'Muat ulang',
      onClick: () => {
        reloadOnControllerChange = true;
        if (registration.waiting) registration.waiting.postMessage({ type: 'SKIP_WAITING' });
        else window.location.reload();
      },
    },
  });
}

function watchForUpdate(registration) {
  swRegistration = registration;

  // Sudah menunggu saat halaman dimuat (worker baru dipasang di kunjungan lalu).
  if (registration.waiting && navigator.serviceWorker.controller) announceUpdate(registration);

  registration.addEventListener('updatefound', () => {
    const installing = registration.installing;
    if (!installing) return;
    installing.addEventListener('statechange', () => {
      // `controller` yang null berarti ini PEMASANGAN PERTAMA, bukan pembaruan:
      // tanpa syarat itu setiap orang yang pertama kali membuka aplikasi
      // disuruh memuat ulang sesuatu yang baru saja dimuatnya.
      if (installing.state === 'installed' && navigator.serviceWorker.controller) announceUpdate(registration);
    });
  });
}

/*
 * SATU muat ulang, tidak pernah dua. `controllerchange` juga menyala pada
 * pemasangan pertama (sw.js memanggil clients.claim()), jadi bendera inilah
 * yang membedakan "orangnya menekan Muat ulang" dari "worker pertama baru saja
 * mengambil alih" — tanpa bendera itu, kunjungan pertama siapa pun memuat ulang
 * dirinya sendiri.
 */
function registerServiceWorker() {
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!reloadOnControllerChange) return;
    reloadOnControllerChange = false;
    window.location.reload();
  });

  navigator.serviceWorker.register('sw.js')
    .then(watchForUpdate)
    .catch((error) => {
      // Bukan alasan untuk mengganggu siapa pun: aplikasinya jalan tanpa worker.
      console.warn('Service worker tidak terdaftar:', error);
    });
}

function scheduleServiceWorker() {
  if (!('serviceWorker' in navigator)) return;
  const start = () => {
    if (window.requestIdleCallback) window.requestIdleCallback(registerServiceWorker, { timeout: 3000 });
    else setTimeout(registerServiceWorker, 500);
  };
  if (document.readyState === 'complete') start();
  else window.addEventListener('load', start, { once: true });
}

/*
 * Sesi yang berangkat dari cermin localStorage harus BERHENTI menjadi sesi
 * cermin begitu jaringannya kembali (P1-I, verifikasi 7 Sep 2026).
 *
 * Toast "Mode luring" dulu dipasang dengan timeout 0 dan tanpa satu pun
 * pendengar: terukur di 390x844, sesudah jaringan kembali dan layar Lapangan
 * memuat data hidup dengan pita luring sudah padam dan nol galat, toast itu
 * masih berbunyi "Tanpa koneksi …" — diperiksa lagi 8 detik kemudian, masih
 * sama, dan menutup 104 px paling bawah layar sampai orangnya menekan silang.
 * Ia juga satu-satunya penanda bahwa izin dan menu berasal dari cermin, dan
 * refreshMe() tidak pernah dijalankan lagi sesudah boot luring.
 *
 * Jadi: satu percobaan penyegaran pada peristiwa jaringan pertama yang
 * menjanjikan. BUKAN `online` saja — `online` hanya berarti antarmukanya
 * menyala (Wi-Fi berportal melaporkannya juga), dan penyegaran yang gagal
 * MEMBIARKAN toast-nya berdiri, karena saat itu kalimatnya masih benar.
 */
/*
 * Sesi yang dibuka dari salinan luring adalah CERMIN: izin dan menunya berasal
 * dari localStorage, bukan dari server. Ketika jaringan kembali, sesi itu harus
 * disegarkan — dan itu TIDAK boleh bergantung pada toast-nya masih terpasang.
 *
 * Sampai verifikasi ulang P1-I (7 Sep 2026) seluruh pemulihan dijaga
 * `!node.isConnected`: menekan "Tutup" pada toast — hal paling biasa yang
 * dilakukan orang, apalagi di ponsel tempat ia menutupi ~104 px — membatalkan
 * penyegaran itu diam-diam dan meninggalkan kedua pendengarnya terpasang
 * selamanya. Terukur: tutup toast, sambungkan lagi, buka dua layar, tunggu
 * 8 detik → 0 permintaan iam/auth/me. Toast-nya kini hanya kabar; yang
 * menentukan adalah `done`.
 */
function recoverFromOfflineBoot(node) {
  let running = false;
  let done = false;

  function stopListening() {
    window.removeEventListener('erp:network', onNetwork);
    window.removeEventListener('online', retry);
  }

  async function retry() {
    if (running || done) return;
    running = true;
    try {
      await refreshMe();
    } catch {
      running = false;   // belum sampai juga: kalimat toast-nya masih benar
      return;
    }
    done = true;
    stopListening();
    if (node.isConnected) node.remove();
    await prefs.load().catch(() => {});
    applyDensity(readDensity());
    refreshNav();
    toast('Kembali daring. Izin dan menu disegarkan; buka ulang layarnya untuk angka terbaru.');
  }

  function onNetwork(event) {
    if (event.detail && event.detail.ok) retry();
  }

  window.addEventListener('erp:network', onNetwork);
  window.addEventListener('online', retry);
}

async function init() {
  // Tautan "lupa kata sandi" dibuka tanpa sesi — diperiksa sebelum apa pun.
  const reset = resetLinkParams();
  if (reset) {
    renderResetPassword(reset);
    return;
  }

  if (!session.token) {
    renderLogin();
    return;
  }

  try {
    await refreshMe();
    boot();
  } catch (error) {
    if (error.status === 401) renderLogin({ message: 'Sesi Anda berakhir. Silakan masuk kembali.' });
    else if (error.status === 0 && session.user) {
      /*
       * LURING dengan sesi yang masih tersimpan (P1-I). Sampai paket ini, muat
       * ulang tanpa jaringan melempar orang yang sudah masuk ke halaman masuk
       * berbunyi "Coba masuk kembali" — halaman masuk yang, tanpa jaringan,
       * TIDAK BISA dilewati: POST iam/auth/login butuh server. Cangkang tergambar,
       * tetapi satu-satunya layar yang bisa dicapai adalah jalan buntu — dan
       * antrean foto Lapangan beserta pita luringnya ada di seberangnya.
       *
       * status 0 = transport gagal, bukan jawaban server (api.js). 401 di atas
       * tetap membuang sesi; yang ini menahannya. Izin yang dipakai adalah
       * cermin localStorage milik orang yang sama, dan begitu jaringan kembali,
       * permintaan pertama yang dijawab 401 tetap melempar keluar lewat
       * setUnauthorizedHandler. Jadi tidak ada satu pun data yang belum dimiliki
       * peramban ini yang terbuka karenanya.
       */
      boot();
      recoverFromOfflineBoot(toast(
        'Tanpa koneksi — aplikasi dibuka dari salinan di perangkat ini. Angkanya bisa tertinggal sampai sinyal kembali.',
        { tone: 'info', title: 'Mode luring', timeout: 0 },
      ));
    } else {
      renderLogin({ message: 'Tidak dapat menghubungi server. Coba masuk kembali.' });
    }
  }
}

init();

/* Sesudah init() — dan, di dalamnya, sesudah `load` + satu putaran idle: tidak
   ada satu byte pun dari pendaftaran worker yang berada di jalur cat pertama. */
scheduleServiceWorker();
