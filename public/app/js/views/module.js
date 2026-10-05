/*
 * Beranda modul #/m/<prefix> — sasaran remah modul dan ubin launcher.
 *
 * P1-B membangun versinya yang minimal: kepala beraksen (nama grup NAV, satu
 * kalimat dari schema.js MODULES) dan kisi kartu layar yang boleh dibuka
 * pemanggil. Kartu dibaca dari visibleNav() — penyaring izin yang SAMA dengan
 * sidebar, Ctrl+K dan launcher — jadi beranda ini tidak pernah menawarkan layar
 * yang barisnya disembunyikan dari menu, dan grup yang izinnya tidak dipegang
 * berakhir di keadaan kosong, bukan panel akses-ditolak (grupnya memang tidak
 * ada bagi orang itu).
 *
 * P1-C menambahkan tiga hal, dan SATU permintaan untuk semuanya:
 *
 *  - UBIN ANGKA. Angka utama modul dari registri ModuleCounts, plus paling
 *    banyak tiga angka sekunder — dan HANYA yang sudah ada di endpoint yang
 *    dipanggil, tidak satu kueri baru pun. Untuk Proyek dan Keuangan itu
 *    berarti `core/dashboard/summary?include=modules`, yang membawa blok modul
 *    DAN ubin uang dasbor sekaligus: satu permintaan, bukan dua. Modul lain
 *    memanggil `core/modules` saja dan memimpin dengan angka utamanya. Tidak
 *    ada modul yang mendapat angka karangan supaya kepalanya tampak penuh.
 *  - TERAKHIR DIBUKA modul ini, disaring dari preferensi server yang sama
 *    dengan sidebar. Kosong → "Belum ada yang dibuka", bukan baris hilang.
 *  - BINTANG per kartu, menulis ke preferensi server. Bintangnya <button> di
 *    samping <a>, bukan di dalamnya: tombol di dalam tautan bukan HTML yang sah
 *    dan Enter di atasnya membuka layarnya alih-alih memasang bintang.
 *
 * Kejujuran angka mengikuti registrinya: entri yang tidak dikirim server (izin
 * hitungannya tidak dipegang) dan count null sama-sama '—', tidak pernah 0.
 *
 * UX-F2.3 menjadikannya halaman proses (README Fase F, ERRATA E-8): peta urutan
 * lazim lewat stepper() untuk modul yang punya `flow` (FLOWS di schema.js; hari
 * ini hanya Pengadaan), "Perlu perhatian" (dokumen modul ini yang menunggu
 * keputusan pembaca + tenggat modul ini, lewat pemuat bersama inbox.js dan
 * deadlines.js — tidak ada permintaan baru di halaman ini selain yang di atas),
 * dan kisi kartu di bawah "Semua layar <modul>". Angka tahap dan angka utama
 * datang dari jawaban yang SAMA; tahap tanpa kunci terdaftar tidak pernah
 * memamerkan angka.
 */

import { api, session } from '../api.js';
import { el, svgIcon, icon, emptyState, stepper } from '../ui.js';
import { NAV, RESOURCES, moduleFor, visibleNav } from '../schema.js';
import { prefs } from '../prefs.js';
import { loadInbox } from '../inbox.js';
import { loadDeadlines } from '../deadlines.js';
import * as fmt from '../format.js';

/** Dua modul yang angka sekundernya SUDAH ada di jawaban dasbor. */
const SUMMARY_PREFIXES = ['prj', 'fin'];

/** Umur jawaban tenggat (ms) yang boleh dipakai Beranda dan beranda modul: tenggat yang baru diselesaikan tidak boleh bertahan semenit. */
const DEADLINES_MAX_AGE = 15000;

/** Entri "Terakhir dibuka" yang muat di kepala modul tanpa mendorong kartu ke bawah lipatan. */
const RECENT_MAX = 6;

export function renderModuleHome(host, { prefix }) {
  const module = moduleFor(prefix);
  if (!module) {
    host.appendChild(el('.alert.error', `Modul "${prefix}" tidak dikenal.`));
    return;
  }

  const visible = visibleNav((perm) => session.can(perm)).find((group) => group.prefix === prefix);
  /* Baris `chrome` (schema.js — hari ini hanya Beranda) dibuang: ia baris menu,
     bukan layar modul. Kartu 'Beranda' di #/m/ringkasan menutup lingkaran ke
     launcher yang baru saja ditinggalkan pemakainya, dan ubin launcher yang
     menghitungnya mengaku "5 layar" untuk 4. */
  const items = (visible ? visible.items : []).filter((item) => !item.chrome);

  host.appendChild(el('.module-head', { dataset: { accent: String(module.accent), prefix } }, [
    el('.module-icon', svgIcon(module.icon, { size: 22 })),
    el('div', [
      el('.eyebrow', { text: 'Modul' }),
      el('h1', { text: module.label }),
      el('.desc', { text: module.description }),
    ]),
  ]));

  if (!items.some((item) => item.route)) {
    // Tidak ada yang dicari di sini — gambar nampan (inbox), bukan kaca pembesar.
    host.appendChild(el('.card', emptyState('Tidak ada layar yang bisa Anda buka di modul ini', {
      title: 'Tidak ada layar', kind: 'inbox',
    })));
    return;
  }

  /* UX-F2.3 (README Fase F, ERRATA E-8) — beranda modul sebagai halaman proses:
     peta urutan lazim (hanya modul yang punya `flow`), angka utama, "Perlu
     perhatian", Terakhir dibuka, lalu kisi kartu di bawah "Semua layar". Angka
     tahap dan angka utama datang dari SATU jawaban (loadKpis); tahap tanpa kunci
     terdaftar tidak pernah memamerkan angka. */
  const flowHost = el('div');
  host.appendChild(flowHost);
  if (module.flow) paintFlow(flowHost, module, undefined);

  const kpis = el('.module-kpis');
  host.appendChild(kpis);
  loadKpis(kpis, prefix).then((payload) => {
    if (module.flow && flowHost.isConnected) paintFlow(flowHost, module, payload);
  });

  /* "Perlu perhatian" menyaring ke modul INI lewat resource dan tautan temuan — grup Ringkasan tidak memiliki
     dokumen maupun tautan tenggat mana pun (isinya Persetujuan, Tenggat, Pekerjaan Saya: lintas modul), jadi
     di sana sebuah saringan akan SELALU menggambar "tidak ada yang perlu perhatian" di atas antrean yang
     penuh. Lintas modul adalah pekerjaan Beranda ("Perlu tindakan Anda"), bukan halaman ini. */
  if (prefix !== 'ringkasan') host.appendChild(attentionSection(prefix, module.label));

  const recentHost = el('div');
  recentHost.appendChild(recentSection(prefix, module.label));
  host.appendChild(recentHost);

  const screens = el('.module-screens', sections(items, prefix).map(({ caption, id, screens: list }) => {
    const grid = el('ul.module-grid', caption ? { 'aria-labelledby': id } : { 'aria-label': `Layar modul ${module.label}` },
      list.map((item) => el('li.module-cell', [screenCard(item), starButton(item)])));
    return caption
      ? el('section.module-section-block', [el('h2.module-section', { id, text: caption }), grid])
      : grid;
  }));
  screens.addEventListener('keydown', arrowNavigation);
  // Judulnya BUKAN `.module-section` (pemisah NAV yang melabeli kisinya; S21 memeriksa itu).
  host.appendChild(el('section.module-all', [
    el('h2.module-block-title', { text: `Semua layar ${module.label}` }),
    screens,
  ]));

  watchFavorites(screens);

  /* Alasan yang sama dengan launcher: pada boot pertama di peramban baru
     beranda modul digambar sebelum core/me/preferences menjawab, jadi
     "Terakhir dibuka" lahir kosong dan bintangnya padam walau server
     memilikinya. Kedua bagian itu digambar ulang di tempat; rutenya tidak
     di-resolve ulang. */
  const onPrefs = () => {
    if (!screens.isConnected) {
      window.removeEventListener('erp:prefs-loaded', onPrefs);
      return;
    }
    recentHost.replaceChildren(recentSection(prefix, module.label));
    syncStars(screens);
  };
  // Hanya bila jawaban server BELUM tiba: tanpa penjaga ini setiap kunjungan meninggalkan satu pendengar yatim.
  if (!prefs.loaded()) window.addEventListener('erp:prefs-loaded', onPrefs);
}

/* ------------------------------------------------------------------ angka */

async function loadKpis(host, prefix) {
  const wantsSummary = SUMMARY_PREFIXES.includes(prefix);

  let payload = null;
  try {
    payload = wantsSummary
      ? await api.get('core/dashboard/summary', { include: 'modules' })
      : { modules: await api.get('core/modules') };
  } catch {
    payload = null;
  }

  if (!host.isConnected) return payload; // halaman sudah berganti selagi menunggu

  if (payload === null) {
    host.appendChild(el('.muted.module-kpi-note', { text: 'Angka modul tidak dapat dimuat sekarang.' }));
    return null;
  }

  const headline = (payload.modules || []).find((one) => one.prefix === prefix) || null;
  const tiles = [headlineTile(headline), ...secondaryTiles(prefix, payload)].filter(Boolean);
  tiles.forEach((tile) => host.appendChild(tile));
  return payload;
}

/* ------------------------------------------------------------ peta proses */

/**
 * Peta urutan lazim modul (FLOWS di schema.js) lewat stepper(). `payload`:
 * undefined = jawabannya belum tiba (tanpa angka dulu); null = permintaan gagal
 * ('—' pada tahap yang punya kunci); selain itu blok `modules` jawaban. Tahap
 * tanpa kunci terdaftar, atau yang kuncinya tidak dikirim server (izin
 * hitungannya tidak dipegang), tidak menampilkan angka — E-8, bukan 0.
 */
function paintFlow(host, module, payload) {
  const open = new Set(visibleNav((perm) => session.can(perm)).flatMap((group) => group.items.map((item) => item.route)));
  const entries = payload ? (payload.modules || []) : [];
  const steps = module.flow.steps.map((step) => {
    let count;
    let title;
    if (step.count && payload === null) {
      count = null;
    } else if (step.count && payload) {
      const entry = entries.find((one) => one.prefix === step.count);
      if (entry) {
        count = entry.count === null || entry.count === undefined ? null : entry.count;
        title = entry.label;
      }
    }
    return { label: step.label, href: open.has(step.route) ? `#/${step.route}` : undefined, count, title };
  });

  const strip = host.querySelector('.stepper');
  if (!strip) {
    host.appendChild(el('section.module-flow', [
      el('h2.module-block-title', { text: `Alur ${module.label}` }),
      stepper(steps),
      module.flow.note ? el('p.module-flow-note', { text: module.flow.note }) : null,
    ]));
    return;
  }
  // Sudah tergambar (jawaban angka baru tiba): hanya angkanya yang diperbarui DI TEMPAT. Mengganti seluruh
  // stepper membuang fokus papan ketik di sebuah tahap dan menggulirkan stripnya kembali ke awal.
  strip.querySelectorAll('.step').forEach((item, index) => {
    const step = steps[index];
    const link = item.querySelector('.step-link');
    let node = item.querySelector('.step-count');
    if (step.count === undefined) {
      if (node) node.remove();
      return;
    }
    if (!node) {
      node = el('span.step-count.mono');
      link.appendChild(node);
    }
    node.textContent = step.count === null ? '—' : String(step.count);
    if (step.title) link.title = step.title;
  });
}

/* ----------------------------------------------------------- perlu perhatian */

/**
 * "Perlu perhatian": yang menuntut pembaca DI MODUL INI — dokumen modul ini yang
 * menunggu keputusannya (core/inbox lewat pemuat bersama; hanya penyetuju) dan
 * tenggat modul ini yang lewat atau menipis (core/deadlines lewat pemuat
 * bersama). Dua sumber, dua kegagalan terpisah, satu kalimat per kegagalan; yang
 * gagal tidak pernah menjadi "tidak ada yang perlu perhatian".
 */
function attentionSection(prefix, moduleLabel) {
  const content = el('div');
  const node = el('section.module-attention', [el('h2.module-block-title', { text: 'Perlu perhatian' }), content]);
  const state = { inbox: undefined, due: undefined }; // undefined = belum; null = tidak ditanyakan; 'failed'
  const redraw = () => { if (node.isConnected) paintAttention(content, state, prefix, moduleLabel); };
  const settle = async (key, load) => {
    try {
      state[key] = await load();
    } catch {
      state[key] = 'failed';
    }
    redraw();
  };
  settle('inbox', () => loadInbox());
  // Jawaban 15 dtk boleh dipakai ulang (Beranda baru saja mengisinya); lebih lama dari itu diminta lagi.
  settle('due', () => loadDeadlines({ maxAge: DEADLINES_MAX_AGE }));
  return node;
}

/** Awalan modul sebuah temuan tenggat: `r/<kunci>` lewat resource-nya, `m/<p>` apa adanya, rute lain lewat grup NAV-nya. */
function prefixOfLink(link) {
  // Beberapa tautan membawa saringan (`r/crm/activities?state=open`): tanpa memotongnya temuan itu tidak
  // pernah dikaitkan ke modulnya dan modulnya menulis "tidak ada yang perlu perhatian".
  const path = String(link || '').split('?')[0];
  if (path.startsWith('m/')) return path.slice(2);
  if (path.startsWith('r/')) return groupPrefixFor(path.slice(2));
  const group = NAV.find((one) => one.items.some((item) => item.route === path));
  return group ? group.prefix : null;
}

function paintAttention(content, state, prefix, moduleLabel) {
  content.replaceChildren();
  const rows = [];
  const inboxRows = state.inbox && state.inbox !== 'failed' ? (state.inbox.data || []) : [];
  const inboxMeta = state.inbox && state.inbox !== 'failed' ? (state.inbox.meta || {}) : {};
  const mine = inboxRows.filter((row) => groupPrefixFor(row.resource) === prefix);
  if (mine.length) {
    const more = inboxMeta.failed && inboxMeta.failed.length ? '+' : '';
    rows.push(attentionRow('attn', `${mine.length}${more} dokumen ${moduleLabel} menunggu keputusan Anda`, 'Buka Persetujuan', '#/tugas'));
  }
  const dueOk = state.due && state.due !== 'failed';
  const findings = dueOk ? (state.due.data || []) : [];
  const dueMeta = dueOk ? (state.due.meta || {}) : {};
  const ofModule = findings.filter((finding) => prefixOfLink(finding.link) === prefix);
  ofModule
    .filter((finding) => finding.tier !== 'tanpa_tanggal')
    .sort((a, b) => (a.tier === b.tier ? 0 : a.tier === 'lewat' ? -1 : 1))
    .forEach((finding) => rows.push(attentionRow(
      finding.tier === 'lewat' ? 'danger' : 'warn',
      finding.title || finding.label,
      `${finding.count} ${finding.label} · ${finding.tier === 'lewat' ? 'sudah lewat' : 'segera jatuh tempo'}`,
      `#/${finding.link}`,
    )));

  // Temuan TANPA tanggal bukan baris "perlu perhatian" di sini, tetapi juga bukan "tidak ada": satu kalimat
  // yang menunjuk Tenggat, dan gambar "done" tidak boleh terlukis di atasnya.
  const undated = ofModule.filter((finding) => finding.tier === 'tanpa_tanggal').reduce((sum, finding) => sum + rowsIn(finding), 0);
  const notes = [];
  if (state.inbox === 'failed') notes.push('Persetujuan tidak dapat dimuat sekarang.');
  if (state.due === 'failed') notes.push('Tenggat tidak dapat dimuat sekarang.');
  if (inboxMeta.failed && inboxMeta.failed.length) notes.push(`Gagal dimuat: ${inboxMeta.failed.join(', ')}. Daftar persetujuan ini belum lengkap.`);
  if (dueMeta.skipped > 0) notes.push('Pemindaian tenggat belum lengkap: sebagian pemeriksaan dilewati.');
  if (undated > 0) notes.push(`${undated} temuan tanpa tanggal — lihat Tenggat.`);

  if (rows.length) content.appendChild(el('ul.attn-list', rows.map((row) => el('li', row))));
  notes.forEach((text) => content.appendChild(el('.muted.module-kpi-note', { text })));

  const settled = state.inbox !== undefined && state.due !== undefined;
  if (settled && !rows.length && !notes.length) {
    content.appendChild(emptyState(`Tidak ada tenggat menipis atau lewat yang bisa Anda tindak, dan tidak ada dokumen ${moduleLabel} yang menunggu keputusan Anda.`, {
      title: 'Tidak ada yang perlu perhatian', kind: 'done', compact: true,
    }));
  }
}

/** Jumlah baris tenggat sebuah temuan (`count` dari server); temuan tanpa angka yang sah tidak menyumbang. */
function rowsIn(finding) {
  const n = Number(finding.count);
  return Number.isFinite(n) ? n : 0;
}

function attentionRow(tone, title, sub, href) {
  return el('a.attn-row', { href, dataset: { tone } }, [
    el('span.attn-dot'),
    el('span.attn-text', [el('b', { text: title }), el('span.attn-sub', { text: sub })]),
    icon('chevron-right', 15),
  ]);
}

/**
 * Angka utama. Entri yang tidak dikirim server berarti izin hitungannya tidak
 * dipegang — dan itu bukan "0", melainkan tidak ada ubinnya sama sekali di
 * sini: berbeda dengan launcher (yang harus menggambar SATU ubin per modul dan
 * karena itu menulis '—'), kepala modul boleh diam.
 */
function headlineTile(entry) {
  if (!entry) return null;
  return kpiTile(entry.label, entry.count === null ? '—' : String(entry.count), entry.count === null ? null : entry.unit);
}

/**
 * Paling banyak tiga angka sekunder, semuanya dari blok yang SUDAH ada di
 * jawaban yang baru saja diambil. Blok yang tidak ada (izin modulnya tidak
 * dipegang — DashboardController menyaringnya) tidak menghasilkan ubin.
 */
function secondaryTiles(prefix, payload) {
  if (prefix === 'prj' && payload.projects) {
    return [kpiTile('Nilai kontrak berjalan', fmt.rupiahShort(payload.projects.contract_value), null)];
  }

  if (prefix === 'fin') {
    const out = [];
    if (payload.ar_invoices) out.push(kpiTile('Piutang belum tertagih', fmt.rupiahShort(payload.ar_invoices.outstanding), null));
    if (payload.ap_bills) {
      out.push(kpiTile('Utang vendor', fmt.rupiahShort(payload.ap_bills.outstanding), null));
      out.push(kpiTile('Tagihan vendor terbuka', String(payload.ap_bills.open_count), 'tagihan'));
    }
    return out;
  }

  return [];
}

function kpiTile(label, value, unit) {
  return el('.module-kpi', [
    el('.module-kpi-value', [el('span', { text: value }), unit ? el('span.module-kpi-unit', { text: unit }) : null]),
    el('.module-kpi-label', { text: label, title: label }),
  ]);
}

/* -------------------------------------------------------- terakhir dibuka */

/**
 * Dokumen modul ini yang terakhir dibuka orangnya. Modul sebuah entri dibaca
 * dari GRUP NAV yang memuat layar daftarnya — bukan dari def.module — karena
 * tujuh layar duduk di grup modul lain (Lokasi Tapak milik Core ada di grup
 * Engineering, Log BBM di grup Aset dengan izin proyek): entri yang dibuka dari
 * grup Engineering harus muncul kembali di beranda Engineering.
 */
function recentSection(prefix, moduleLabel) {
  const rows = prefs.visibleRecent((perm) => session.can(perm))
    .filter((one) => groupPrefixFor(one.key) === prefix)
    .slice(0, RECENT_MAX);

  /* Judulnya BUKAN .module-section: kelas itu berarti "pemisah NAV yang
     melabeli kisinya", dan SidebarNav/S21 memeriksa bahwa SETIAP .module-section
     punya id yang ditunjuk aria-labelledby sebuah .module-grid. Judul yang
     menumpang kelas itu membuat pemeriksaan struktur merah untuk alasan yang
     bukan cacat (terukur 6 Sep 2026: sections_label_their_grid false di #/m/fin). */
  return el('section.module-recent', [
    el('h2.module-recent-title', { text: 'Terakhir dibuka' }),
    rows.length
      ? el('ul.home-chips', { 'aria-label': `Dokumen ${moduleLabel} yang terakhir dibuka` }, rows.map((one) => el('li',
        el('a.home-chip', { href: `#/${one.route}` }, [
          el('b', { text: one.label }),
          el('span.hint', { text: one.sub || one.def.labelOne || one.def.label }),
        ]))))
      : el('.muted.module-kpi-note', { text: 'Belum ada yang dibuka.' }),
  ]);
}

function groupPrefixFor(resourceKey) {
  const group = NAV.find((one) => one.items.some((item) => item.route === `r/${resourceKey}`));
  if (group) return group.prefix;
  const def = RESOURCES[resourceKey];
  const module = def ? moduleFor(def.module) : null;
  return module ? module.prefix : null;
}

/* ---------------------------------------------------------------- bintang */

function starButton(item) {
  const on = prefs.isFavorite(item.route);
  const node = el('button.star', {
    type: 'button', 'aria-pressed': String(on),
    'aria-label': label(on), title: label(on),
    dataset: { route: item.route },
  }, icon('star', 13));
  node.classList.toggle('on', on);
  node.addEventListener('click', () => prefs.toggleFavorite(item.route));
  return node;
}

function label(on) {
  return on ? 'Hapus dari Favorit' : 'Tandai sebagai Favorit';
}

/*
 * Bintang di sini dan bintang di sidebar menulis daftar yang SAMA, jadi salah
 * satunya harus mengikuti yang lain — di desktop keduanya terlihat sekaligus,
 * dan bintang beranda modul yang tetap padam setelah barisnya dibintangi dari
 * sidebar terbaca sebagai bintang yang rusak. Pendengarnya membuang dirinya
 * sendiri begitu layarnya diganti (view() mengosongkan #view, jadi node ini
 * lepas dari dokumen): tanpa itu setiap kunjungan ke beranda modul meninggalkan
 * satu pendengar window yang menggambar node yatim.
 */
function watchFavorites(screens) {
  const sync = () => {
    if (!screens.isConnected) {
      window.removeEventListener('erp:favorites-changed', sync);
      return;
    }
    syncStars(screens);
  };
  window.addEventListener('erp:favorites-changed', sync);
}

function syncStars(screens) {
  screens.querySelectorAll('button.star').forEach((star) => {
    const on = prefs.isFavorite(star.dataset.route);
    star.setAttribute('aria-pressed', String(on));
    star.setAttribute('aria-label', label(on));
    star.title = label(on);
    star.classList.toggle('on', on);
  });
}

/* ------------------------------------------------------------------ kartu */

/* Item NAV → blok per pemisah: kartu sebelum pemisah pertama (bila ada) jadi
   satu kisi tanpa judul, tiap pemisah membuka blok baru berjudul. */
function sections(items, prefix) {
  const blocks = [];
  let current = null;
  items.forEach((item, index) => {
    if (item.divider) {
      current = { caption: item.divider, id: `module-section-${prefix}-${index}`, screens: [] };
      blocks.push(current);
      return;
    }
    if (!current) {
      current = { caption: null, id: null, screens: [] };
      blocks.push(current);
    }
    current.screens.push(item);
  });
  return blocks;
}

function screenCard(item) {
  const def = item.route.startsWith('r/') ? RESOURCES[item.route.slice(2)] : null;
  const hint = (def && def.description) || item.sub || null;
  return el('a.module-card', { href: `#/${item.route}`, dataset: { route: item.route } }, [
    svgIcon(def ? 'table' : 'layout-grid', { size: 18 }),
    el('div', [el('b', { text: item.label }), hint ? el('span.hint', { text: hint }) : null]),
  ]);
}

/*
 * Panah memindah fokus antar kartu; Tab/Enter sudah bekerja karena kartunya
 * <a>. Kiri/kanan = urutan DOM (sama dengan urutan visual kisi); atas/bawah
 * dipilih dari GEOMETRI: kartu terdekat di baris berikutnya yang kolomnya
 * tumpang tindih dengan kartu sekarang — bukan indeks ± jumlah kolom, yang
 * meleset begitu sebuah pemisah memutus kisi dan baris sebelum pemisah tidak
 * penuh (verifikasi P1-B 5 Sep 2026: #/m/fin 17 dari 20 salah, #/m/prj 11).
 * Baris parsial tanpa kartu di kolom itu dilewati ke baris berikutnya yang
 * punya; tidak ada kartu di bawah → fokus diam. Home/End ke kartu
 * pertama/terakhir.
 */
function arrowNavigation(event) {
  const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: 'up', ArrowDown: 'down', Home: 'first', End: 'last' };
  if (!(event.key in moves)) return;
  const cards = [...event.currentTarget.querySelectorAll('.module-card')];
  const index = cards.indexOf(document.activeElement);
  if (index === -1) return;

  const move = moves[event.key];
  let next = index;
  if (move === 'first') next = 0;
  else if (move === 'last') next = cards.length - 1;
  else if (move === 'up' || move === 'down') next = verticalNeighbour(cards, index, move === 'down' ? 1 : -1);
  else next = index + move;

  if (next < 0 || next >= cards.length) return;
  event.preventDefault();
  cards[next].focus();
}

function verticalNeighbour(cards, index, direction) {
  const from = cards[index].getBoundingClientRect();
  let best = -1;
  let bestDistance = Infinity;
  cards.forEach((card, i) => {
    if (i === index) return;
    const rect = card.getBoundingClientRect();
    const overlapsColumn = rect.left < from.right - 1 && rect.right > from.left + 1;
    const distance = direction > 0 ? rect.top - from.bottom : from.top - rect.bottom;
    if (!overlapsColumn || distance < -1) return;
    if (distance < bestDistance) { bestDistance = distance; best = i; }
  });
  return best;
}
