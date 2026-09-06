/* Application shell: login gate, layout, navigation, routing. */

import { api, session, login, logout, refreshMe, setUnauthorizedHandler } from './api.js';
import { notificationBell, startNotificationPolling, stopNotificationPolling } from './notifications.js';
import { el, clear, button, icon, toast, toastError, field, withBusy, setFieldError, modal, closeAllModals } from './ui.js';
import { initials } from './format.js';
import { NAV, RESOURCES, visibleNav, moduleFor } from './schema.js';
import { route, fallback, navigate, start, currentPath } from './router.js';
import { setCrumbs } from './crumbs.js';
import { prefs } from './prefs.js';
import { renderModuleHome } from './views/module.js';
import { renderHome } from './views/home.js';
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
import { renderRekapAlat } from './views/rekapalat.js';
import { renderSewaVsBeli } from './views/sewavsbeli.js';
import { renderTkdnWorksheet, renderRkkDocument, renderKualifikasi } from './views/tender.js';
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
import { renderTugas } from './views/tugas.js';
import { openForm } from './views/form.js';
import { openOnboarding, closeOnboarding } from './views/onboarding.js';
import { listDrafts, removeDraft, flushAll, suspendDraftRemoval, relativeAge } from './drafts.js';

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

function cycleTheme() {
  const order = ['system', 'light', 'dark'];
  const current = localStorage.getItem(THEME_KEY) || 'system';
  const next = order[(order.indexOf(current) + 1) % order.length];
  localStorage.setItem(THEME_KEY, next);
  applyTheme(next);
  toast(`Tema: ${{ system: 'mengikuti sistem', light: 'terang', dark: 'gelap' }[next]}`, { timeout: 2200 });
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

function navItemNode(item, favorites) {
  const link = el('a', { href: `#/${item.route}`, dataset: { route: item.route }, title: item.sub || null }, [
    el('span.tick'),
    el('span.lbl', { text: item.label }),
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

function navGroupNode(nav, group, favorites, stored) {
  const items = el('.nav-items', group.items.map((item) => (item.divider
    ? el('.nav-divider', { text: item.divider })
    : navItemNode(item, favorites))));

  // Class 'chev' dipasang di sini, bukan di icon(): selector rotasi
  // `.nav-group[data-open="false"] > button .chev` di app.css tidak pernah
  // menemukan sasarannya karena icon() merender svg polos — chevron diam
  // saat grup ditutup dan satu-satunya penanda buka/tutup adalah
  // muncul-hilangnya item.
  const chev = icon('chevron', 13);
  chev.classList.add('chev');
  const groupNode = el('.nav-group', { dataset: { open: String(groupOpenByDefault(group, stored)) } }, [
    el('button', { type: 'button' }, [group.label, chev]),
    items,
  ]);
  if (group.kind) groupNode.dataset.kind = group.kind;
  // Aksen modul (P1-B): slot warna grup, dipakai penanda grup aktif
  // (.has-active, dipasang setActiveNav). Grup pintasan tidak beraksen.
  const module = group.prefix ? moduleFor(group.prefix) : null;
  if (module) {
    groupNode.dataset.prefix = module.prefix;
    groupNode.dataset.accent = String(module.accent);
  }

  groupNode.querySelector('button').addEventListener('click', () => {
    const next = groupNode.dataset.open !== 'true';
    groupNode.dataset.open = String(next);
    const open = [...nav.querySelectorAll('.nav-group')]
      .filter((node) => node.dataset.open === 'true')
      .map((node) => node.querySelector('button').textContent.trim());
    localStorage.setItem(NAV_STATE_KEY, JSON.stringify(open));
  });

  return groupNode;
}

function renderNav(nav) {
  clear(nav);
  const groups = navForSession();
  const favorites = prefs.favorites();
  const stored = storedOpenGroups();
  for (const group of [...shortcutGroups(groups), ...groups]) {
    nav.appendChild(navGroupNode(nav, group, favorites, stored));
  }
}

/* Bangun ulang seluruh sidebar (121 tautan, sekali gambar) alih-alih menambal
   satu grup: satu jalur kode untuk bintang, dokumen terakhir, dan izin yang
   berubah. Status aktif dipasang lagi karena ia tidak pernah disimpan. */
function refreshNav() {
  const nav = document.querySelector('nav.nav');
  if (!nav) return;
  renderNav(nav);
  setActiveNav(currentPath().split('?')[0]);
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
      Object.assign(button('Cari', {
        variant: 'ghost',
        iconName: 'search',
        title: 'Pencarian global (Ctrl+K)',
        onClick: () => openSearch(),
      }), { className: 'btn ghost global-search' }),
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
      densityControl(),
    ]),
    footer: [
      button('Tutup', { onClick: () => dialog.close() }),
      // Menu akun hanya "Tutup · Keluar" sampai 2 Sep 2026 (HASIL-UJI §1, S9).
      button('Ganti kata sandi', { onClick: () => { dialog.close(); openChangePassword(); } }),
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
  document.querySelectorAll('.nav-group.has-active').forEach((group) => group.classList.remove('has-active'));
  document.querySelectorAll('.nav-items a').forEach((link) => {
    const target = link.dataset.route;
    const active = path === target ||
      (target.startsWith('r/') && path.startsWith(`d/${target.slice(2)}/`)) ||
      (target === 'r/projects' && /^d\/projects\/\d+$/.test(path));
    link.classList.toggle('active', active);
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
  // P7 — keduanya menyusun baris milik dokumen/modul lain, jadi keduanya butuh
  // pemilih yang menampilkan baris aslinya; kisi generik hanya punya kotak id.
  tkdn: renderTkdnWorksheet,
  rkk: renderRkkDocument,
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
    setCrumbs(['Ringkasan', 'Tugas Saya']);
    setActiveNav('tugas');
    const host = view();
    // Tanpa gerbang izin: core/inbox menyaring per {modul}.approve pemanggil.
    return guard(host, () => renderTugas(host));
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
 * Landing sesudah masuk (keputusan pemilik #3, ROADMAP-HASHMICRO §5): dasbor di
 * atas 760 px, app launcher #/home pada 760 px ke bawah. 760 px bukan angka
 * baru — itulah titik potong yang SUDAH dipakai app.css untuk melipat sidebar
 * menjadi laci, dan di bawahnya menu tidak terlihat sampai seseorang menekan
 * hamburger: mendarat di dasbor berarti mendarat di halaman tanpa jalan keluar
 * yang terlihat. DUA dari 12 peran demo bahkan mendarat di dasbor KOSONG:
 * procurement dan hr, yang tidak memegang prj.view maupun fin.view — diukur
 * dengan masuk sebagai kedua belas akun demo (S22_roles_with_tiles,
 * roles_without_dashboard_stat = ['procurement','hr']). Teknisi memegang
 * inv.view + svc.view dan mendapat SATU ubin angka di dasbor, jadi ia bukan
 * yang ketiga: dasbor punya sumber ubin selain kedua blok itu.
 *
 * PEMBANDINGNYA `>`, BUKAN `>=`. `@media (max-width: 760px)` INKLUSIF: pada
 * lebar 760 px tepat, sidebar sudah menjadi laci. Aturan ini dulu memakai
 * `>= 760` — juga inklusif — jadi 760 px adalah satu-satunya lebar yang
 * mendapat KEDUANYA: laci DAN dasbor, persis gabungan yang aturan ini ditulis
 * untuk mencegah (terukur 6 Sep 2026 sebagai admin@: 759 → #/home,
 * 760 → #/dashboard dengan matchMedia('(max-width: 760px)') true dan nav di
 * luar layar, 761 → #/dashboard dengan sidebar terlihat).
 *
 * Hanya berlaku ketika TIDAK ADA hash: tautan-dalam (notifikasi ke
 * `#/d/finance/ap-bills/12`, tab yang dipulihkan peramban, tombol Kembali)
 * mendarat di tempat yang diminta. Karena itu location.hash yang dibaca, bukan
 * currentPath() yang mengarang 'dashboard' saat hash kosong.
 */
function landOnDefault() {
  const hash = location.hash;
  if (hash && hash !== '#' && hash !== '#/') return;
  navigate(window.innerWidth > 760 ? 'dashboard' : 'home', { replace: true });
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
        openForm({
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
    else {
      renderLogin({ message: 'Tidak dapat menghubungi server. Coba masuk kembali.' });
    }
  }
}

init();
