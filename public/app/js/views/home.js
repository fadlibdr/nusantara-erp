/*
 * App launcher `#/home` (Fase 1 / P1-C, T1C.3) — halaman pertama di ponsel.
 *
 * KENAPA ADA. Diukur dengan MASUK sebagai kedua belas akun demo (harness S22):
 * DUA peran — procurement dan hr — mendarat di dasbor tanpa satu ubin angka pun
 * (0 `.stat`), karena tak satu pun blok `core/dashboard/summary` (prj/fin) dan
 * tak satu pun sumber ubin lainnya berlaku untuk mereka. Halaman pembuka yang
 * kosong. Dan di ponsel sidebar terlipat jadi laci: jalan ke Lapangan adalah
 * hamburger → grup Proyek → Lapangan, TIGA ketukan, dua di antaranya hanya untuk
 * membuka menu. Launcher ini menjawab keduanya: satu ubin per modul yang BOLEH
 * dibuka orangnya, masing-masing dengan satu angka utama (registri ModuleCounts)
 * dan jumlah layarnya — terukur 3 ubin (hr, teknisi) sampai 14 (admin), 0 peran
 * tanpa ubin, dan 2 ketukan ke Lapangan.
 *
 * ANGKA. Ubin memimpin dengan hitungan dari GET core/modules. Aturannya sama
 * dengan registrinya: modul yang server tidak sebutkan (izin hitungannya tidak
 * dipegang) atau yang count-nya null menulis '—', TIDAK PERNAH 0 — "0 tiket"
 * pada layar orang yang memang tidak boleh melihat tiket adalah kebohongan yang
 * tampak seperti kabar baik. Permintaannya gagal seluruhnya (luring) → semua
 * ubin '—' dan satu baris yang mengatakan kenapa; ubinnya sendiri tetap bisa
 * ditekan, karena navigasi tidak butuh angka.
 *
 * UBIN MANA. visibleNav() — penyaring izin yang SAMA dengan sidebar, Ctrl+K dan
 * beranda modul; modul tanpa satu pun layar yang bisa dibuka tidak berubin.
 * Jadi "0 peran tanpa ubin" bukan klaim di sini melainkan akibat: setiap orang
 * yang bisa masuk memegang sedikitnya satu layar.
 */

import { api, session } from '../api.js';
import { el, clear, append, button, svgIcon, emptyState, pageHeader, keyValueTile } from '../ui.js';
import { MODULES, moduleFor, visibleNav } from '../schema.js';
import { prefs } from '../prefs.js';
import { screenHits } from '../search.js';
import { navigate } from '../router.js';
import { loadInbox } from '../inbox.js';
import { loadDeadlines } from '../deadlines.js';
import { createEntries, startCreate } from '../create.js';
import * as fmt from '../format.js';

/** Hasil layar yang ditampilkan saat mencari — di atas ini jawabannya adalah modulnya. */
const SEARCH_MAX = 24;

export async function renderHome(host) {
  clear(host);

  const groups = visibleNav((perm) => session.can(perm)).filter((group) => group.items.some((item) => item.route));
  const hidden = hiddenPrefixes();

  host.appendChild(pageHeader({
    title: 'Beranda',
    description: `Selamat datang, ${(session.user || {}).name || 'pengguna'}. Mulai dari yang menunggu Anda, atau pilih bidang kerja.`,
    actions: [button('Dasbor', { onClick: () => navigate('dashboard') })],
  }));

  const grid = el('ul.home-grid', { 'aria-label': 'Bidang kerja' });
  const tiles = new Map();
  for (const group of groups) {
    if (hidden.includes(group.prefix)) continue;
    const tile = moduleTile(group);
    tiles.set(group.prefix, tile);
    grid.appendChild(el('li', tile.node));
  }

  /* Kotak cari di ATAS pintasan dan ubin: di ponsel ia adalah jalan tercepat
     ke layar yang namanya sudah diketahui, dan menaruhnya di bawah kisi berarti
     menggulir melewati 14 ubin lebih dulu. */
  const results = el('.home-results', { hidden: true });
  host.appendChild(searchBox(results, groups, grid));
  host.appendChild(results);

  const body = el('div');
  host.appendChild(body);

  /* UX-F2.2 (README Fase F): ruang kerja DI ATAS ubin — apa yang perlu Anda
     tindak, aksi tercepat, tiga-empat angka. Semuanya digambar dari pemuat
     bersama (inbox.js, deadlines.js) dan cermin lokal; satu-satunya permintaan
     baru di halaman landing adalah core/deadlines. */
  const work = workSection();
  body.appendChild(work.node);
  const quick = el('div');
  body.appendChild(quick);
  paintQuick(quick);
  redrawWhenPrefsLand(quick, () => paintQuick(clear(quick)));
  const numbers = el('div');
  body.appendChild(numbers);

  const shortcuts = el('div');
  body.appendChild(shortcuts);
  shortcutRows(shortcuts);
  redrawWhenPrefsLand(shortcuts, () => shortcutRows(clear(shortcuts)));

  if (!tiles.size) {
    body.appendChild(el('.card', emptyState('Tidak ada modul yang bisa Anda buka.', { title: 'Belum ada modul', kind: 'inbox' })));
    return;
  }

  body.appendChild(el('section.home-section', [el('h2.home-section-title', { text: 'Bidang kerja' }), grid]));
  append(body, hiddenFooter(hidden, host));

  /* Angka menyusul; ubin sudah bisa ditekan sebelum jawabannya datang, dan
     itulah sebabnya kisi digambar lebih dulu. */
  let counts = null;
  try {
    counts = await api.get('core/modules');
  } catch {
    counts = null;
  }

  if (counts === null) {
    tiles.forEach((tile) => tile.fail());
    paintNumbers(numbers, groups, null);
    body.appendChild(el('.muted.home-note', { text: 'Angka modul tidak dapat dimuat sekarang — menu di bawah tetap bisa dipakai.' }));
    return;
  }

  const byPrefix = new Map((Array.isArray(counts) ? counts : []).map((one) => [one.prefix, one]));
  tiles.forEach((tile, prefix) => tile.fill(byPrefix.get(prefix) || null));
  paintNumbers(numbers, groups, byPrefix);
}

/* ------------------------------------------------------- perlu tindakan Anda */

/** Kartu per sumber yang tampil: persetujuan teratas dan tenggat lewat. */
const WORK_INBOX_MAX = 2;
const WORK_DEADLINE_MAX = 3;

/**
 * "Perlu tindakan Anda": dua dokumen yang paling lama menunggu keputusan Anda
 * (core/inbox lewat pemuat bersama; hanya pemegang hak approve) dan tenggat yang
 * SUDAH LEWAT (core/deadlines lewat pemuat bersama), yang paling lama di atas.
 * Dua sumber, dua kegagalan terpisah: yang pertama tiba menggambar duluan, yang
 * gagal menulis SATU kalimat dan tidak pernah menjadi "tidak ada yang perlu
 * ditindak" (CONVENTIONS §14 — daftar kosong karena gagal bukan daftar kosong).
 */
function workSection() {
  const sub = el('p.home-section-sub', { text: 'Memuat…' });
  const content = el('div');
  const node = el('section.home-section#perlu-tindakan', [
    el('h2.home-section-title', { text: 'Perlu tindakan Anda' }), sub, content,
  ]);
  // undefined = belum menjawab; null = tidak ditanyakan (bukan penyetuju); 'failed' = gagal.
  const state = { inbox: undefined, due: undefined };
  const redraw = () => { if (node.isConnected) paintWork(sub, content, state); };

  const settle = async (key, load) => {
    try {
      state[key] = await load();
    } catch {
      state[key] = 'failed';
    }
    redraw();
  };
  settle('inbox', () => loadInbox());
  settle('due', () => loadDeadlines());
  return { node };
}

function paintWork(sub, content, state) {
  clear(content);
  const answered = (value) => value !== undefined;
  const failedOf = (value) => value === 'failed';
  const inboxRows = state.inbox && state.inbox !== 'failed' ? (state.inbox.data || []) : [];
  const inboxMeta = state.inbox && state.inbox !== 'failed' ? (state.inbox.meta || {}) : {};
  const findings = state.due && state.due !== 'failed' ? (state.due.data || []) : [];
  const overdue = findings.filter((finding) => finding.tier === 'lewat');

  // Kalimat hitungan di bawah judul; sumber yang gagal atau belum menjawab menulis '—', bukan 0.
  const pieces = [];
  if (state.inbox !== null) {
    pieces.push(failedOf(state.inbox) || !answered(state.inbox)
      ? '— dokumen menunggu keputusan Anda'
      : `${inboxMeta.failed && inboxMeta.failed.length ? `${inboxMeta.total ?? inboxRows.length}+` : (inboxMeta.total ?? inboxRows.length)} dokumen menunggu keputusan Anda`);
  }
  pieces.push(failedOf(state.due) || !answered(state.due) ? '— tenggat sudah lewat' : `${overdue.length} tenggat sudah lewat`);
  sub.textContent = pieces.join(' · ');

  const cards = [
    ...inboxRows.slice(0, WORK_INBOX_MAX).map((row) => ({ age: Number(row.days_waiting) || 0, node: approvalCard(row) })),
    ...overdue
      .map((finding) => ({ finding, age: maxOverdueDays(finding) }))
      .sort((a, b) => b.age - a.age)
      .slice(0, WORK_DEADLINE_MAX)
      .map(({ finding, age }) => ({ age, node: deadlineCard(finding) })),
  ].sort((a, b) => b.age - a.age);

  const notes = [];
  if (failedOf(state.inbox)) notes.push('Persetujuan tidak dapat dimuat sekarang — daftarnya di Persetujuan tetap bisa dibuka.');
  if (failedOf(state.due)) notes.push('Tenggat tidak dapat dimuat sekarang.');
  if (inboxMeta.failed && inboxMeta.failed.length) notes.push(`Gagal dimuat: ${inboxMeta.failed.join(', ')}. Daftar persetujuan ini belum lengkap.`);

  if (cards.length) content.appendChild(el('ul.home-cards', cards.map((card) => el('li', card.node))));
  notes.forEach((text) => content.appendChild(el('.alert.warn.home-alert', text)));

  const settled = answered(state.inbox) && answered(state.due);
  if (settled && !cards.length && !notes.length) {
    content.appendChild(emptyState(
      state.inbox === null
        ? 'Tidak ada tenggat yang sudah lewat pada modul yang boleh Anda lihat.'
        : 'Tidak ada dokumen yang menunggu keputusan Anda dan tidak ada tenggat yang sudah lewat.',
      { title: 'Tidak ada yang perlu ditindak', kind: 'done', compact: true },
    ));
  }

  const more = [
    state.inbox !== null && state.inbox !== undefined ? el('a', { href: '#/tugas', text: 'Semua persetujuan →' }) : null,
    el('a', { href: '#/tenggat', text: 'Semua tenggat →' }),
  ].filter(Boolean);
  content.appendChild(el('.home-more', more));
}

/** Hari terlama sebuah temuan terlewat (item.days bertanda: negatif = sudah lewat). */
function maxOverdueDays(finding) {
  const days = (finding.items || []).map((item) => (typeof item.days === 'number' ? -item.days : 0));
  return days.length ? Math.max(...days) : 0;
}

function homeCard({ tone, kindIcon, kind, title, meta, href, cta }) {
  return el('article.home-card', { dataset: { tone } }, [
    el('.home-card-kind', [svgIcon(kindIcon, { size: 14 }), el('span', { text: kind })]),
    el('b.home-card-title', { text: title }),
    meta ? el('.home-card-meta', { text: meta }) : null,
    el('a.btn.sm.primary', { href }, el('span', { text: cta })),
  ]);
}

function approvalCard(row) {
  const waited = row.days_waiting === null || row.days_waiting === undefined ? null : `menunggu ${row.days_waiting} hari`;
  const amount = row.amount === null || row.amount === undefined ? null : fmt.rupiah(row.amount);
  return homeCard({
    tone: 'attn', kindIcon: 'clipboard-check', kind: 'Menunggu keputusan Anda',
    title: `${row.code} · ${row.title || row.label}`,
    meta: [row.label, row.submitted_by ? `diajukan ${row.submitted_by}` : null, amount, waited].filter(Boolean).join(' · '),
    href: row.link, cta: 'Tinjau & putuskan',
  });
}

function deadlineCard(finding) {
  const oldest = (finding.items || [])[0];
  const late = oldest && typeof oldest.days === 'number' ? `${oldest.code} lewat ${Math.abs(oldest.days)} hari` : null;
  return homeCard({
    tone: 'danger', kindIcon: 'clock', kind: 'Tenggat lewat',
    title: finding.title || finding.label,
    meta: [`${finding.count} ${finding.label}`, late].filter(Boolean).join(' · '),
    href: `#/${finding.link}`, cta: 'Tindak lanjuti',
  });
}

/* -------------------------------------------------------------- aksi cepat */

const QUICK_MAX = 6;

/**
 * "Aksi cepat": SUMBER YANG SAMA dengan Buat ▾ (create.js) — formulir yang
 * sudah ditawarkan daftarnya kepada pembaca ini, dengan predikat yang sama.
 * Dari Beranda tidak ada modul "di sini", jadi yang tampil adalah yang baru
 * dipakai, atau enam pertama dalam urutan NAV. Kosong → tidak menggambar apa pun.
 */
function paintQuick(host) {
  const entries = createEntries('home');
  const list = [...entries.module, ...entries.recent].slice(0, QUICK_MAX);
  if (!list.length) return;
  host.appendChild(el('section.home-section', [
    el('h2.home-section-title', { text: 'Aksi cepat' }),
    el('ul.home-quick', { 'aria-label': 'Aksi cepat' }, list.map((entry) => el('li',
      el('button.home-quick-tile', { type: 'button', onclick: () => startCreate(entry) }, [
        el('span.home-quick-icon', svgIcon('plus', { size: 16 })),
        el('span', { text: `Buat ${entry.def.labelOne || entry.def.label}` }),
      ])))),
  ]));
}

/* ------------------------------------------------------------------ angka */

const NUMBERS_MAX = 4;

/**
 * "Angka": paling banyak empat angka utama modul yang HITUNGANNYA dipegang
 * pembaca (registri ModuleCounts), urutan NAV. `ringkasan` dilewati — ia
 * mengulang angka lonceng. Tidak ada jawaban sama sekali → empat nama dari
 * cermin lokal MODULES.kpi dengan '—': nama tanpa angka tetap memberi tahu
 * apa yang tidak diketahui, dan 0 palsu tidak pernah muncul.
 */
function paintNumbers(host, groups, byPrefix) {
  clear(host);
  const order = groups.filter((group) => group.prefix && group.prefix !== 'ringkasan');
  const picks = byPrefix === null
    ? order.slice(0, NUMBERS_MAX).map((group) => ({ group, entry: null }))
    : order.filter((group) => byPrefix.has(group.prefix)).slice(0, NUMBERS_MAX).map((group) => ({ group, entry: byPrefix.get(group.prefix) }));
  if (!picks.length) return;
  host.appendChild(el('section.home-section', [
    el('h2.home-section-title', { text: 'Angka' }),
    el('ul.home-stats', { 'aria-label': 'Angka utama' }, picks.map(({ group, entry }) => el('li',
      el('a.home-stat', { href: `#/m/${group.prefix}` }, keyValueTile({
        label: entry ? entry.label : (MODULES[group.prefix] || {}).kpi,
        value: entry && entry.count !== null && entry.count !== undefined ? entry.count : null,
        note: entry && entry.unit ? `${entry.unit} · ${group.label}` : group.label,
      }))))),
  ]));
}

/* ------------------------------------------------------------------- ubin */

/**
 * Satu ubin modul: aksen slotnya, ikon Lucide, nama, angka utama, jumlah layar.
 * Angka dipasang belakangan lewat fill()/fail() — DOM-nya sudah ada sejak awal
 * supaya tidak ada lompatan tata letak saat jawabannya tiba.
 */
function moduleTile(group) {
  const module = moduleFor(group.prefix) || { accent: 8, icon: 'layout-grid', label: group.label };
  // Baris `chrome` (Beranda) tidak dihitung: launcher adalah tempat ubin ini
  // ditekan DARI, bukan salah satu layar modulnya — "5 layar" untuk 4 adalah
  // janji yang tidak ditepati kisi kartu di seberangnya.
  const screens = group.items.filter((item) => item.route && !item.chrome).length;

  const value = el('.home-kpi-value', { text: '·' });
  const unit = el('.home-kpi-unit', { text: '' });
  const caption = el('.home-kpi-label', { text: 'memuat…' });

  const node = el('a.home-tile', {
    href: `#/m/${group.prefix}`,
    dataset: { accent: String(module.accent), prefix: group.prefix },
  }, [
    el('.home-tile-icon', svgIcon(module.icon, { size: 20 })),
    el('.home-tile-body', [
      el('b.home-tile-label', { text: group.label }),
      el('.home-kpi', [value, unit]),
      caption,
    ]),
    el('.home-tile-screens', { text: `${screens} layar` }),
  ]);

  return {
    node,
    fill(entry) {
      if (!entry) return this.fail();
      // count null = registri tahu ada angkanya tetapi kuerinya gagal; entri
      // yang tidak dikirim server = izin hitungannya tidak dipegang. Keduanya
      // '—'; yang berbeda hanya apakah kita tahu NAMA angkanya — dan nama
      // tanpa angka lebih baik daripada tidak keduanya: "—  Job gagal"
      // memberi tahu pembacanya apa yang tidak diketahui.
      caption.textContent = entry.label || '';
      caption.title = entry.label || '';
      if (entry.count === null || entry.count === undefined) {
        value.textContent = '—';
        unit.textContent = '';
        return;
      }
      value.textContent = String(entry.count);
      unit.textContent = entry.unit;
    },
    /*
     * Angkanya tidak diketahui — karena izin hitungannya tidak dipegang (server
     * tidak mengirim entri modul ini) atau karena seluruh permintaannya gagal.
     * Keterangannya TETAP menyebut angka apa yang tidak diketahui, dari cermin
     * lokal MODULES[prefix].kpi: '—' telanjang tanpa keterangan tidak memberi
     * tahu pembacanya apa pun, dan kasus "izin tidak dipegang" justru yang
     * paling sering (terukur 6 Sep 2026: warehouse@ 390×844 — tiga dari enam
     * ubin ber-'—' tanpa satu kata pun; sesudah ini "— Job gagal").
     */
    fail() {
      value.textContent = '—';
      unit.textContent = '';
      caption.textContent = module.kpi || '';
      caption.title = module.kpi || '';
    },
  };
}

/* -------------------------------------------------- favorit & terakhir dibuka */

/** Dua baris pintasan di atas kisi; yang kosong tidak menggambar apa pun. */
function shortcutRows(body) {
  // Satu jalan atas NAV, bukan satu per chip: rute → { item, grup }. Dengan 50
  // favorit (plafon server) pencarian per chip berarti 50 × 14 grup tiap gambar.
  const byRoute = new Map();
  for (const group of visibleNav((perm) => session.can(perm))) {
    for (const item of group.items) {
      if (item.route) byRoute.set(item.route, { item, group });
    }
  }

  const favorites = prefs.favorites()
    .map((route) => byRoute.get(route))
    .filter(Boolean)
    .map(({ item, group }) => shortcut(`#/${item.route}`, item.label, group.label));

  const recent = prefs.visibleRecent((perm) => session.can(perm))
    .slice(0, 8)
    .map((one) => shortcut(`#/${one.route}`, one.label, one.sub || (one.def.labelOne || one.def.label)));

  if (favorites.length) body.appendChild(shortcutSection('Favorit', favorites));
  if (recent.length) body.appendChild(shortcutSection('Terakhir dibuka', recent));
}

function shortcutSection(title, chips) {
  return el('section.home-section', [
    el('h2.home-section-title', { text: title }),
    el('ul.home-chips', { 'aria-label': title }, chips.map((chip) => el('li', chip))),
  ]);
}

function shortcut(href, label, sub) {
  return el('a.home-chip', { href }, [
    el('b', { text: label }),
    sub ? el('span.hint', { text: sub }) : null,
  ]);
}

/*
 * Boot pertama di peramban baru: launcher digambar SEBELUM jawaban
 * core/me/preferences tiba (prefs.js menjawab dari cermin lokal, yang di sana
 * kosong), jadi baris Favorit dan Terakhir dibuka lahir kosong dan tidak pernah
 * terisi. Yang digambar ulang hanya dua baris itu — bukan rutenya — supaya
 * permintaan layar tidak berjalan dua kali. Pendengarnya membuang dirinya
 * sendiri begitu simpulnya lepas dari dokumen (view() mengosongkan #view).
 */
function redrawWhenPrefsLand(node, redraw) {
  const handler = () => {
    if (!node.isConnected) {
      window.removeEventListener('erp:prefs-loaded', handler);
      return;
    }
    redraw();
  };
  window.addEventListener('erp:prefs-loaded', handler);
}

/* ------------------------------------------------------------------ cari */

/*
 * Satu kotak untuk dua hal: ubin modul yang namanya cocok, dan LAYAR yang
 * namanya cocok. Indeks layarnya persis indeks Ctrl+K (search.js screenHits,
 * penyaring visibleNav yang sama), jadi palet dan launcher tidak akan pernah
 * menawarkan daftar layar yang berbeda untuk kata yang sama. Yang tidak
 * dilakukan di sini adalah mencari DOKUMEN: itu satu permintaan per ketikan,
 * dan Ctrl+K sudah memilikinya.
 */
function searchBox(results, groups, grid) {
  const input = el('input.home-search', {
    type: 'search', placeholder: 'Cari modul atau layar…', 'aria-label': 'Cari modul atau layar',
    autocomplete: 'off',
  });

  const draw = () => {
    const term = input.value.trim();
    clear(results);
    // Yang disembunyikan adalah <li>-nya, bukan <a>-nya: sel kisi yang kosong
    // meninggalkan lubang di grid.
    const cells = [...grid.children];
    results.hidden = term.length < 2;
    if (results.hidden) {
      cells.forEach((cell) => { cell.hidden = false; });
      const all = grid.closest('.home-section');
      if (all) all.hidden = false;
      return;
    }

    const needle = term.toLowerCase();
    const prefixes = groups
      .filter((group) => group.label.toLowerCase().includes(needle)
        || String((MODULES[group.prefix] || {}).description || '').toLowerCase().includes(needle))
      .map((group) => group.prefix);
    cells.forEach((cell) => {
      const tile = cell.querySelector('.home-tile');
      cell.hidden = !(tile && prefixes.includes(tile.dataset.prefix));
    });
    // Seksi "Modul" berjudul di atas kisi yang seluruh selnya tersembunyi
    // terbaca sebagai modul yang hilang, bukan sebagai pencarian tanpa hasil.
    const section = grid.closest('.home-section');
    if (section) section.hidden = !prefixes.length;

    const hits = screenHits(term, SEARCH_MAX);
    results.appendChild(el('h2.home-section-title', { text: `Layar (${hits.length})` }));
    results.appendChild(hits.length
      ? el('ul.home-chips', { 'aria-label': 'Hasil layar' }, hits.map((hit) => el('li', shortcut(hit.link, hit.code, hit.title))))
      : el('.muted.home-note', { text: `Tidak ada layar yang namanya cocok dengan "${term}".` }));
  };

  input.addEventListener('input', draw);
  // Enter membuka hasil layar pertama — perilaku Ctrl+K, supaya dua kotak cari
  // di aplikasi yang sama tidak menjawab tombol yang sama dengan cara berbeda.
  input.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    const first = results.querySelector('a.home-chip');
    if (first) { event.preventDefault(); first.click(); }
  });

  return el('.home-searchbar', input);
}

/* --------------------------------------------------------- modul disembunyikan */

function hiddenPrefixes() {
  const list = prefs.get('launcher.hidden', []);
  return Array.isArray(list) ? list.filter((one) => typeof one === 'string') : [];
}

/*
 * Preferensi 'launcher.hidden' belum punya tombol "sembunyikan" (P1-D yang
 * memilikinya bersama laci Atur dasbor), tetapi ia SUDAH dihormati di sini —
 * dan justru karena itu jalan kembalinya harus ada sekarang. Sebuah kunci yang
 * bisa ditulis tanpa cara membatalkannya adalah modul yang hilang selamanya
 * dari beranda seseorang.
 */
function hiddenFooter(hidden, host) {
  if (!hidden.length) return null;
  return el('.home-note.muted', [
    el('span', { text: `${hidden.length} modul disembunyikan dari beranda. ` }),
    button('Tampilkan semua', {
      size: 'sm',
      onClick: () => { prefs.set('launcher.hidden', []); renderHome(host); },
    }),
  ]);
}
