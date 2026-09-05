/* Panduan onboarding per peran — panel berlabuh yang menunjukkan halaman
 * tiap langkah (v2), versi ponsel sebagai lembar bawah.
 *
 * v1 (5 Sep 2026 pagi) adalah satu modal: permintaan pemilik "make it pop-up
 * every user is logged in and create a button to skip … make the choice is
 * remembered". Masukan pemilik sesudah melihatnya, 5 Sep 2026: "it was great
 * to show the intended page/location while user displayed the onboarding the
 * also make it on mobile version". Modal menutupi persis halaman yang sedang
 * dibicarakan panduannya. Maka v2:
 *
 *  - DESKTOP (>760px): panel tetap di kanan, ~400px, setinggi layar, dipasang
 *    di body di luar #view sehingga pindah rute tidak membuangnya; halaman di
 *    bawahnya tetap hidup — sidebar diklik, formulir dibuka (overlay modal ada
 *    di atas panel). Bisa dilipat jadi tab tepi "Onboarding n/7".
 *  - PONSEL (≤760px, breakpoint drawer nav): lembar bawah ±58% tinggi layar
 *    dengan pegangan; terlipat jadi satu baris "Langkah n dari 7 · …". Saat
 *    langkah memindah halaman, lembarnya melipat sendiri supaya halaman
 *    tujuannya terlihat, dan bilahnya menyebut "Anda di: Grup › Layar".
 *  - TUR TERPANDU: server menyertakan `locations` per bagian — sebutan
 *    "Grup › Layar" yang ada di teksnya (OnboardingGuide::locations()). Di
 *    sini label itu dicocokkan ke NAV (schema.js) lewat visibleNav(), jadi
 *    hanya layar yang izinnya dipegang yang ditawarkan, sebagai keping
 *    "Buka › Grup › Layar" di bawah judul bagian. Saat masuk ke langkah:
 *    1 dan 6 → Dasbor; 2 → Dasbor + sorot sidebar (ponsel: sorot tombol drawer
 *    dan buka drawer 2 detik); 3, 5, 7 → lokasi pertama yang bisa dibuka
 *    (tetap di tempat bila rute sekarang sudah salah satunya); 4 → tetap.
 *    Tidak pernah berpindah selagi formulir terbuka — isian yang belum
 *    tersimpan tidak boleh terbuang — keping saja yang tampil. Sesudah pindah,
 *    tautan sidebar rutenya disorot (ui.js spotlight), dan pada layar daftar
 *    tombol "Tambah …" bila ada (desktop).
 *
 * Yang TIDAK berubah dari v1: tampil di setiap masuk selama belum memutuskan;
 * Lewati di setiap langkah; Esc / tombol tutup pada buka-otomatis = Lewati;
 * pilihan disimpan di server (users.onboarding_status), bukan localStorage —
 * tablet kantor lapangan dipakai bergantian; dibuka lagi dari menu akun dengan
 * Tutup + "Tampilkan lagi"; GET diulang pada galat selain 404. Salinan
 * mengikuti lima aturan kata: tombol bernama kata kerjanya (Lewati · Kembali ·
 * Lanjut · Selesai · Buka), tidak menyatakan yang tidak diketahui (bagian tanpa
 * sebutan layar tidak diberi tebakan), konsekuensi disebut apa adanya. */
import { api, session } from '../api.js';
import { el, clear, button, icon, toast, toastError, withBusy, modalDepth, spotlight, spotlightClear } from '../ui.js';
import { visibleNav } from '../schema.js';
import { navigate, currentPath } from '../router.js';

const ENDPOINT = 'iam/me/onboarding';

/* Breakpoint yang SAMA dengan drawer nav di app.css: di lebar ini sidebar
   adalah laci, dan panel kanan 400px akan memakan seluruh layar ponsel. */
const MOBILE = window.matchMedia('(max-width: 760px)');

/* Kalimat yang sama dengan `message` server — toast tetap tampil walau
   respons berhasil datang tanpa kalimat. */
const COPY = {
  skipped: 'Panduan onboarding dilewati — bisa dibuka lagi dari menu akun.',
  completed: 'Panduan onboarding selesai.',
  reset: 'Panduan onboarding akan tampil lagi saat Anda masuk berikutnya.',
  formOpen: 'Formulir sedang terbuka, jadi halaman tidak dipindah — isian yang belum tersimpan tidak boleh terbuang. Tutup formulirnya dulu, lalu tekan tombol Buka di atas.',
};

/* Centang daftar periksa hidup selama tab ini terbuka saja — tidak ada
   kolomnya di server, dan panduan menyebut itu apa adanya di bawah daftar.
   Disimpan per modul, bukan per panel, supaya menutup lalu membuka lagi dari
   menu akun tidak menghapus centang yang baru dibuat. Kunci: id bagian +
   urutan kotak. */
const ticks = new Map();

/* Satu panel pada satu waktu: membuka dari menu akun selagi panel masih ada
   mengganti yang lama tanpa mencatat apa pun. */
let active = null;

/*
 * GET panduan, dengan ulang-coba pada jalur otomatis. Pada masuk, enam
 * permintaan API berangkat bersamaan dan SQLite bisa menjawab salah satunya
 * "database is locked" (500) — verifikasi 5 Sep 2026 menjatuhkan GET ini
 * pada burst masuk, dan tanpa ulang-coba pop-up-nya diam-diam hilang untuk
 * sesi itu. Dua kali ulang berjarak 1,5 detik cukup untuk kunci yang lepas
 * dalam hitungan milidetik; 404 (peran tanpa panduan) tidak diulang.
 */
async function fetchGuide(auto) {
  const attempts = auto ? 3 : 1;
  for (let attempt = 1; ; attempt += 1) {
    try {
      return await api.get(ENDPOINT);
    } catch (error) {
      if (attempt >= attempts || (error && error.status === 404)) throw error;
      await new Promise((resolve) => setTimeout(resolve, 1500));
    }
  }
}

/**
 * Membuka panduan untuk pemanggil. `auto` = dibuka sendiri saat masuk:
 * menutup lewat Esc/tombol tutup dicatat sebagai Lewati supaya tidak
 * mengganggu lagi besok; peran tanpa panduan (404) diam saja. Dari menu akun
 * (`auto: false`) tidak ada yang dicatat kecuali orangnya menekan Lewati atau
 * Selesai, dan galat ditampilkan.
 */
export async function openOnboarding({ auto = false } = {}) {
  let guide;
  try {
    guide = await fetchGuide(auto);
  } catch (error) {
    /* Peran khusus tanpa berkas panduan (404): satu GET kecil per masuk, dan
       bila panduannya ditulis kemudian orangnya masih akan melihatnya —
       mencatat "dilewati" diam-diam di sini akan menghilangkan itu selamanya.
       Galat lain pada jalur otomatis sudah dicoba ulang oleh fetchGuide();
       yang tersisa dibiarkan diam supaya halaman masuk tidak diawali toast
       merah — status masih null, jadi panduan tampil pada masuk berikutnya. */
    if (!auto) toastError(error);
    else if (!error || error.status !== 404) console.warn('onboarding: panduan tidak termuat', error);
    return null;
  }

  /* Balapan dengan keputusan di perangkat lain: session.user dibaca dari
     localStorage sebelum refreshMe() selesai, sedangkan jawaban ini baru
     dari server. Server yang menang. */
  if (auto && guide.status) {
    syncStanding(guide.status, guide.seen_at);
    return null;
  }

  const sections = guide.sections || [];
  if (!sections.length) {
    if (!auto) toast('Panduan untuk peran Anda belum memuat satu bagian pun.', { tone: 'warn' });
    return null;
  }

  if (active) active.close({ record: false });
  active = mountDock(guide, { auto });
  return active;
}

/** Dipanggil app.js saat sesi berakhir (keluar, 401): panel hidup di body,
    di luar #root, jadi halaman masuk yang digambar ulang tidak membuangnya. */
export function closeOnboarding() {
  if (active) active.close({ record: false });
}

/* ------------------------------------------------------------------ panel */
function mountDock(guide, { auto }) {
  const user = session.user || {};
  const sections = guide.sections;
  const total = sections.length;

  let index = 0;
  /* Hanya buka-otomatis yang mencatat penutupan tanpa tombol; dimatikan
     begitu Lewati/Selesai sendiri yang mencatat, supaya penutupan sesudahnya
     tidak mencatat "dilewati" di atas "selesai". */
  let recordOnClose = auto;
  let closed = false;
  let locations = [];   // lokasi langkah ini yang bisa dibuka, sudah dicocokkan ke NAV
  let chips = [];       // keping "Buka ›" langkah ini
  let visited = null;   // lokasi yang dituju tur pada langkah ini
  let drawerTimer = 0;  // ponsel, langkah 2: drawer dibuka 2 detik oleh tur

  /* Drawer yang dibuka tur ditutup oleh tur; drawer yang dibuka orangnya
     sendiri dibiarkan. */
  function closeDrawerIfOurs() {
    if (!drawerTimer) return;
    clearTimeout(drawerTimer);
    drawerTimer = 0;
    document.body.classList.remove('nav-open');
  }

  /* ---- kepala */
  const counter = el('span.onboarding-counter');
  const collapse = button('', {
    variant: 'ghost', size: 'sm', iconName: 'chevronRight', title: 'Lipat panel',
    onClick: () => setState('collapsed'),
  });
  collapse.setAttribute('aria-label', 'Lipat panel panduan');
  const closeButton = button('', {
    variant: 'ghost', size: 'sm', iconName: 'close',
    title: auto ? 'Tutup panduan (dicatat sebagai Lewati)' : 'Tutup panduan',
    onClick: () => close({ record: recordOnClose }),
  });
  closeButton.setAttribute('aria-label', 'Tutup panduan');
  const head = el('.dock-head', [
    el('h2', { text: `Panduan · ${guide.title}`, title: guide.title }),
    counter, collapse, closeButton,
  ]);

  const guideLink = el('a', {
    href: guide.guide_url, target: '_blank', rel: 'noopener',
    title: guide.guide_path, text: 'Buka panduan lengkap',
  });
  const sub = el('.onboarding-head', [
    el('span', { text: `Selamat datang, ${user.name || 'rekan'}` }),
    el('.spacer'),
    guideLink,
  ]);
  /* "Tampilkan lagi": status kembali null, jadi panel muncul saat masuk
     berikutnya — untuk orang yang dulu menekan Lewati lalu berubah pikiran. */
  if (guide.status) {
    sub.insertBefore(button('Tampilkan lagi saat masuk berikutnya', {
      variant: 'ghost', size: 'sm',
      onClick: (event) => withBusy(event.currentTarget, async () => {
        try {
          session.setUser(await api.put(ENDPOINT, { status: null }));
          toast(COPY.reset);
        } catch (error) {
          toastError(error);
        }
      }),
    }), guideLink);
  }

  /* ---- kepingan langkah */
  const steps = sections.map((section, i) => {
    const step = button('', { onClick: () => go(i), title: section.heading });
    step.className = 'onboarding-step';
    step.append(
      el('span.n', { text: String(i + 1) }),
      el('span.t', { text: shortHeading(section) }),
    );
    return step;
  });
  const strip = el('ol.onboarding-steps', steps.map((step) => el('li', step)));

  const body = el('.onboarding-body', { tabindex: '-1' });

  /* ---- kaki */
  const back = button('Kembali', { iconName: 'back', onClick: () => go(index - 1) });
  const next = button('Lanjut', { variant: 'primary', onClick: () => go(index + 1) });
  const finish = button('Selesai', { variant: 'primary', iconName: 'check', onClick: (event) => decide('completed', event.currentTarget) });
  /* Lewati tampak di SETIAP langkah — orang yang sudah tahu pekerjaannya
     tidak dipaksa menekan Lanjut enam kali. Sesudah memutuskan, tombol kirinya
     Tutup: tidak ada yang dicatat lagi kecuali Selesai ditekan. */
  const leave = guide.status
    ? button('Tutup', { variant: 'ghost', onClick: () => close({ record: false }) })
    : button('Lewati', { variant: 'ghost', onClick: (event) => decide('skipped', event.currentTarget) });
  leave.classList.add('leave');
  const foot = el('.dock-foot', [leave, el('.spacer'), back, next, finish]);

  /* ---- bilah terlipat: tab tepi di desktop, bilah bawah di ponsel */
  const barText = el('span.t');
  const barChevron = icon('chevron', 14);
  barChevron.classList.add('chev');
  const bar = el('button.dock-bar', { type: 'button', title: 'Buka panel panduan', onclick: () => setState('open') }, [barText, barChevron]);

  /* Pegangan lembar (ponsel): ketuk atau seret ke bawah = lipat. */
  const handle = el('.dock-handle', { role: 'presentation' });
  let drag = null;
  handle.addEventListener('pointerdown', (event) => { drag = { y: event.clientY }; });
  handle.addEventListener('pointerup', (event) => {
    if (drag && event.clientY - drag.y > 40) setState('collapsed');
    drag = null;
  });
  handle.addEventListener('click', () => setState('collapsed'));

  const panel = el('.dock-panel', [handle, head, sub, strip, body, foot]);
  const dock = el('aside.onboarding-dock', {
    role: 'complementary', 'aria-label': 'Panduan onboarding', dataset: { state: 'open' },
  }, [bar, panel]);
  document.body.appendChild(dock);

  /* ---- wujud: desktop / ponsel, terbuka / terlipat */
  function applyMode() {
    const mobile = MOBILE.matches;
    dock.dataset.mode = mobile ? 'mobile' : 'desktop';
    collapse.title = mobile ? 'Lipat ke bilah bawah' : 'Lipat panel';
    syncBodyClass();
    updateBar();
  }

  function setState(state) {
    dock.dataset.state = state;
    syncBodyClass();
    updateBar();
    if (state === 'open') body.focus({ preventScroll: true });
  }

  /* .shell diberi margin selebar panel hanya saat panel terbuka di desktop —
     tab tepi yang terlipat cukup kecil untuk menumpang di tepi halaman. */
  function syncBodyClass() {
    const open = dock.dataset.state === 'open';
    document.body.classList.toggle('onboarding-docked', open && dock.dataset.mode === 'desktop');
    document.body.classList.toggle('onboarding-sheet', dock.dataset.mode === 'mobile');
  }

  /* Bilah terlipat menyebut tempat orangnya berada begitu rute sekarang adalah
     salah satu lokasi langkah ini — di ponsel halaman tujuannya terlihat
     penuh dan bilahnya yang memberi konteks. */
  function updateBar() {
    const at = whereAmI();
    if (dock.dataset.mode === 'mobile') {
      barText.textContent = at
        ? `Langkah ${index + 1} dari ${total} · Anda di: ${at.group} › ${at.label}`
        : `Langkah ${index + 1} dari ${total} · ${shortHeading(sections[index])}`;
    } else {
      barText.textContent = `Onboarding ${index + 1}/${total}`;
    }
    chips.forEach((chip) => chip.classList.toggle('here', Boolean(at) && chip.dataset.route === at.route));
  }

  function whereAmI() {
    const here = routeNow();
    return locations.find((loc) => onRoute(here, loc.route))
      || (visited && onRoute(here, visited.route) ? visited : null);
  }

  /* ---- pendengar dokumen */
  /* Esc menutup panel yang terbuka (= Lewati bila buka-otomatis) hanya bila
     tidak ada modal. Kedalaman modal dibaca pada fase CAPTURE, sebelum
     pendengar modal di ui.js (fase bubble, terdaftar lebih dulu) sempat
     menutup modalnya: tanpa itu satu Esc yang menutup formulir juga menutup
     panel — dan mencatat "dilewati" — karena saat giliran pendengar ini tiba
     tumpukan modal sudah kosong. Menu "Cetak ▾" mem-preventDefault Esc-nya;
     combobox menghentikan perambatannya. Panel yang terlipat bukan urusan
     Esc — orangnya sedang bekerja di halaman. */
  let modalAtKeydown = 0;
  function onKeyCapture(event) {
    if (event.key === 'Escape') modalAtKeydown = modalDepth();
  }
  function onKey(event) {
    if (event.key !== 'Escape' || event.defaultPrevented || modalAtKeydown > 0 || modalDepth() > 0) return;
    if (dock.dataset.state !== 'open') return;
    close({ record: recordOnClose });
  }
  document.addEventListener('keydown', onKeyCapture, true);
  document.addEventListener('keydown', onKey);
  window.addEventListener('hashchange', updateBar);
  MOBILE.addEventListener('change', applyMode);

  function close({ record = false } = {}) {
    if (closed) return;
    closed = true;
    document.removeEventListener('keydown', onKeyCapture, true);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('hashchange', updateBar);
    MOBILE.removeEventListener('change', applyMode);
    closeDrawerIfOurs();
    spotlightClear();
    dock.remove();
    document.body.classList.remove('onboarding-docked', 'onboarding-sheet');
    if (active === handleOut) active = null;
    if (!record) return;
    /* Esc atau tombol tutup pada buka-otomatis = Lewati: tidak ada tombol
       Lewati yang ditekan, tapi orangnya sudah memilih untuk tidak membacanya
       sekarang, dan panduan yang muncul lagi di setiap masuk adalah gangguan,
       bukan bantuan. Gagal mencatat hanya berarti panduan tampil lagi besok —
       tidak perlu galat. */
    api.put(ENDPOINT, { status: 'skipped' })
      .then((fresh) => { session.setUser(fresh); toast(COPY.skipped); })
      .catch(() => {});
  }

  async function decide(status, node) {
    await withBusy(node, async () => {
      try {
        const fresh = await api.put(ENDPOINT, { status });
        session.setUser(fresh);
        recordOnClose = false;
        close({ record: false });
        toast(COPY[status]);
      } catch (error) {
        toastError(error);
      }
    });
  }

  /* ---- langkah */
  function go(target) {
    index = Math.max(0, Math.min(total - 1, target));
    const section = sections[index];
    counter.textContent = `${index + 1} dari ${total}`;
    steps.forEach((step, i) => {
      step.classList.toggle('current', i === index);
      if (i === index) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });
    // Strip kepingan menggulir mendatar hanya di ponsel; di desktop ia melipat.
    if (MOBILE.matches) steps[index].scrollIntoView({ block: 'nearest', inline: 'nearest' });

    locations = resolveLocations(section);
    visited = null;
    closeDrawerIfOurs();
    spotlightClear();

    clear(body);
    body.appendChild(el('h3.onboarding-heading', { text: section.heading }));
    chips = locations.map((loc) => {
      const chip = el('button.onboarding-location', {
        type: 'button', title: loc.raw, dataset: { route: loc.route },
      }, [el('span.k', { text: 'Buka ›' }), el('span', { text: `${loc.group} › ${loc.label}` })]);
      chip.addEventListener('click', () => visit(loc, { addButton: true }));
      return chip;
    });
    if (chips.length) body.appendChild(el('.onboarding-locations', chips));

    const formOpen = modalDepth() > 0;
    if (formOpen && chips.length) body.appendChild(el('.onboarding-note.muted', { text: COPY.formOpen }));

    body.appendChild(sectionContent(section, guide));
    body.scrollTop = 0;

    back.disabled = index === 0;
    next.hidden = index === total - 1;
    finish.hidden = index !== total - 1;

    if (!formOpen) tour();
    updateBar();
  }

  /*
   * Halaman yang ditunjukkan saat masuk ke langkah (masukan pemilik 5 Sep
   * 2026). Nomor langkah mengikuti kerangka README §"Kerangka yang sama":
   * 1 Siapa Anda · 2 Hari pertama · 3 Pekerjaan Anda · 4 Yang akan menolak ·
   * 5 Formulir · 6 Daftar periksa · 7 Bila tersangkut.
   */
  function tour() {
    const dashboard = { group: 'Ringkasan', label: 'Dasbor', route: 'dashboard', raw: 'Ringkasan › Dasbor' };
    if (index === 0 || index === 5) { visit(dashboard); return; }
    if (index === 1) { visit(dashboard, { sidebar: true }); return; }
    if (index === 3) return; // kalimat penolakan — tidak ada satu layar untuknya
    const here = routeNow();
    const target = locations.find((loc) => onRoute(here, loc.route)) || locations[0];
    if (target) visit(target, { addButton: true });
  }

  /* Pindah ke lokasi (bila belum di sana) lalu sorot. Di ponsel lembarnya
     melipat begitu pindah, supaya halaman tujuannya terlihat. */
  function visit(target, { addButton = false, sidebar = false } = {}) {
    const moved = !onRoute(routeNow(), target.route);
    visited = target;
    if (moved) {
      navigate(target.route);
      if (dock.dataset.mode === 'mobile') setState('collapsed');
    }
    updateBar();
    whenRouted(target.route).then(() => {
      if (closed || visited !== target) return; // langkah sudah berganti
      highlight(target, { addButton, sidebar });
    });
  }

  function highlight(target, { addButton, sidebar }) {
    spotlightClear();
    closeDrawerIfOurs();
    const mobile = dock.dataset.mode === 'mobile';

    if (sidebar) {
      if (mobile) {
        /* Sidebar ponsel adalah laci: yang disorot tombol pembukanya, dan
           lacinya dibuka dua detik supaya isinya sempat terlihat. */
        spotlight(document.querySelector('.header .menu-toggle'), { label: 'Menu navigasi Anda' });
        document.body.classList.add('nav-open');
        drawerTimer = setTimeout(() => { drawerTimer = 0; document.body.classList.remove('nav-open'); }, 2000);
      } else {
        spotlight(document.querySelector('nav.nav'), { label: 'Sidebar Anda', pad: 2 });
      }
      return;
    }

    if (mobile) {
      /* Tautan sidebar tersembunyi di laci; judul halamannya yang disorot. */
      const heading = document.querySelector('#view .page-head h1');
      if (heading) spotlight(heading, { label: `${target.group} › ${target.label}` });
      return;
    }

    const link = document.querySelector(`nav.nav .nav-group:not([data-kind]) a[href="#/${target.route}"]`);
    if (link) spotlight(link, { label: 'Anda di sini' });
    if (addButton) {
      const add = document.querySelector('#view .page-head .actions .btn.primary');
      if (add) spotlight(add, { label: 'Mulai dari sini' });
    }
  }

  const handleOut = { node: dock, close, go };
  applyMode();
  go(0);
  body.focus({ preventScroll: true });
  return handleOut;
}

/* --------------------------------------------------------------- lokasi */
const norm = (text) => String(text || '').toLowerCase().replace(/\s+/g, ' ').trim();

/**
 * Sebutan "Grup › Layar" dari server dicocokkan ke NAV yang boleh dilihat
 * sesi ini: (grup, label) persis → label berawalan sama di grup itu ("Mutu"
 * untuk "Mutu (QA/QC)", "Ketidaksesuaian (NCR" yang tanda kurungnya terpotong)
 * → grup saja (baris pertamanya). "Dasbor › …" dan "Pengaturan › …" menyebut
 * layar sebagai grup: kartu dasbor dan tab pengaturan bukan baris NAV, jadi
 * layarnya yang dibuka. Yang izinnya tidak dipegang tidak lolos visibleNav()
 * dan tidak ditawarkan. Satu keping per rute.
 */
function resolveLocations(section) {
  const groups = visibleNav((perm) => session.can(perm));
  const out = [];
  const seen = new Set();
  for (const mention of section.locations || []) {
    const hit = resolveOne(mention, groups);
    if (!hit || seen.has(hit.route)) continue;
    seen.add(hit.route);
    out.push({ ...hit, raw: mention.raw });
  }
  return out;
}

function resolveOne(mention, groups) {
  const wantGroup = norm(mention.group);
  const wantItem = norm(mention.item);
  const group = groups.find((one) => norm(one.label) === wantGroup)
    || groups.find((one) => norm(one.label).startsWith(wantGroup));

  if (group) {
    const items = group.items.filter((one) => one.route);
    const item = items.find((one) => norm(one.label) === wantItem)
      || items.find((one) => norm(one.label).startsWith(wantItem))
      || items.find((one) => wantItem.startsWith(norm(one.label)))
      || items[0];
    return item ? { group: group.label, label: item.label, route: item.route } : null;
  }

  for (const one of groups) {
    const item = one.items.find((candidate) => candidate.route && norm(candidate.label) === wantGroup);
    if (item) return { group: one.label, label: item.label, route: item.route };
  }
  return null;
}

const routeNow = () => currentPath().split('?')[0];

/* Halaman detail d/<sumber>/<id> berada "di" layar daftarnya r/<sumber> —
   aturan yang sama dengan setActiveNav() di app.js. */
function onRoute(here, route) {
  return here === route || (route.startsWith('r/') && here.startsWith(`d/${route.slice(2)}/`));
}

/* Menunggu rute tiba dan #view punya kepala halaman: hashchange berjalan
   sesudah navigate() kembali, dan tombol Tambah baru ada sesudah renderList
   menggambar .page-head. Paling lama 3 detik, lalu jalan terus apa adanya. */
function whenRouted(route) {
  return new Promise((resolve) => {
    const started = Date.now();
    const tick = () => {
      if (onRoute(routeNow(), route) && document.querySelector('#view .page-head')) {
        setTimeout(() => resolve(true), 60);
      } else if (Date.now() - started > 3000) {
        resolve(false);
      } else {
        setTimeout(tick, 60);
      }
    };
    tick();
  });
}

const shortHeading = (section) => section.heading.replace(/^\d+\.\s*/, '');

/**
 * HTML bagian dari server, dirapikan untuk hidup di dalam panel: tautan
 * relatif panduan (finance.md, ../PANDUAN-PENGGUNA.md) diarahkan ke berkas
 * di repositori dan dibuka di tab baru — di dalam SPA, href relatif akan
 * menimpa halaman aplikasi; tabel lebar menggulir di dalam kotaknya sendiri;
 * kotak centang daftar periksa dihidupkan (GFM merender `disabled`).
 */
function sectionContent(section, guide) {
  const content = el('.onboarding-content', { html: section.html });

  content.querySelectorAll('a[href]').forEach((anchor) => {
    const href = anchor.getAttribute('href') || '';
    if (href.startsWith('#')) {
      anchor.removeAttribute('href'); // sasaran di dalam berkas — tidak ada di sini
      return;
    }
    if (!/^(https?:|mailto:)/i.test(href)) {
      try {
        anchor.href = new URL(href, guide.guide_url).href;
      } catch {
        anchor.removeAttribute('href');
        return;
      }
    }
    anchor.target = '_blank';
    anchor.rel = 'noopener';
  });

  content.querySelectorAll('table').forEach((table) => {
    const wrap = el('.onboarding-table');
    table.replaceWith(wrap);
    wrap.appendChild(table);
  });

  const boxes = content.querySelectorAll('input[type="checkbox"]');
  boxes.forEach((box, i) => {
    const key = `${section.id}:${i}`;
    box.disabled = false;
    box.checked = ticks.get(key) === true;
    box.addEventListener('change', () => ticks.set(key, box.checked));
  });
  if (boxes.length) {
    content.appendChild(el('.onboarding-note.muted', {
      text: 'Centang hanya untuk sesi ini — tidak tersimpan, hilang saat halaman dimuat ulang.',
    }));
  }

  return content;
}

/* Salinan sesi mengikuti jawaban server tanpa menunggu refreshMe(). */
function syncStanding(status, seenAt) {
  const user = session.user;
  if (!user) return;
  session.setUser({ ...user, onboarding_status: status, onboarding_seen_at: seenAt });
}
