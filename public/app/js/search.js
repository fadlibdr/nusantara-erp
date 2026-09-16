/* Pencarian global — one box over everything the user may read.

   There was no search box in the shell and no command palette; navigation was
   the sidebar and nothing else, so finding PO/2026/VII/0042 meant knowing it was
   a purchase order first. */

import { api, session } from './api.js';
import { el, clear, modal, closeModal, modalDepth, toast, svgIcon } from './ui.js';
import { visibleNav } from './schema.js';

const MIN_LENGTH = 2;
const DEBOUNCE_MS = 220;

/* Layar per pencarian. Di atas ini jawabannya adalah sidebar. */
const SCREEN_MAX = 8;

/**
 * Guards against an out-of-order response overwriting a newer one.
 *
 * Typing "PO/2" then "PO/20" fires two requests; the first can land second on a
 * slow connection and put the wrong list on screen. Only the newest sequence
 * number is allowed to render.
 */
let sequence = 0;

/* The search box of the palette that is currently open, if any. */
let palette = null;

/* Id unik per baris hasil, untuk aria-activedescendant. */
let rowSeq = 0;

/* The "close the modal first" banner, so a held Ctrl+K raises one, not thirty. */
let refusal = null;

/*
 * Sumber "Layar" (T2.5): baris NAV yang boleh dilihat pemanggil, dicocokkan
 * pada awal kata label. Ctrl+K sampai 2 Sep 2026 hanya mencari dokumen, jadi
 * sampai ke layar Opname berarti membaca 121 tautan sidebar (HASIL-UJI §1,
 * S5) — padahal palet ini sudah ada di bawah setiap tangan.
 *
 * Diekspor sejak P1-C: kotak cari launcher #/home memakai indeks yang SAMA
 * (dengan batas sendiri, karena halaman punya ruang yang palet tidak punya) —
 * dua kotak cari yang menawarkan daftar layar berbeda untuk kata yang sama
 * adalah dua aplikasi.
 *
 * Awal kata, bukan substring: "po" harus menemukan "Pesanan (PO)" dan "Baris
 * PO Terbuka", bukan "La-po-ran Harian" — dan Enter membuka hasil pertama,
 * jadi derau di sini berarti orang yang mengetik kode dokumen mendarat di
 * layar yang salah. Kode dokumen lengkap ("PO/2026/IX/0004") tidak pernah
 * cocok dengan awal kata label mana pun, sehingga baris pertamanya tetap
 * dokumen itu. Disaring lewat visibleNav() yang sama dengan sidebar, supaya
 * palet tidak menawarkan layar yang barisnya disembunyikan dari menu.
 */
export function screenHits(term, limit = SCREEN_MAX) {
  const needle = term.toLowerCase();
  const hits = [];

  for (const group of visibleNav((perm) => session.can(perm))) {
    for (const item of group.items) {
      if (!item.route) continue;
      const words = item.label.toLowerCase().split(/[^\p{L}\p{N}]+/u).filter(Boolean);
      const rank = words.join(' ').startsWith(needle) ? 0 : words.some((word) => word.startsWith(needle)) ? 1 : null;
      if (rank === null) continue;
      hits.push({ rank, code: item.label, title: group.label, link: `#/${item.route}`, screen: true });
    }
  }

  return hits.sort((a, b) => a.rank - b.rank).slice(0, limit);
}

/*
 * Menandai bagian yang cocok (UI-3b).
 *
 * Dipakai untuk kedua sumber dengan aturan yang sama — SUBSTRING, tidak
 * peduli-huruf — walau layar dicocokkan pada AWAL KATA. Itu disengaja: yang
 * ditandai adalah apa yang MATA cari di baris itu, bukan aturan yang membuat
 * baris itu lolos. Menandai "po" di "Pesanan (PO)" berguna; menyembunyikannya
 * karena kecocokannya kebetulan di kata kedua tidak.
 *
 * Teks dipasang lewat el({text}) dan document.createTextNode — tidak ada
 * innerHTML di sini. Kode dokumen datang dari server dan nama pelanggan datang
 * dari pengguna; keduanya tidak pernah boleh jadi markup.
 */
function marked(text, term) {
  const value = String(text == null ? '' : text);
  const needle = term.trim().toLowerCase();
  if (!needle) return [document.createTextNode(value)];

  const parts = [];
  const haystack = value.toLowerCase();
  let at = 0;
  for (;;) {
    const found = haystack.indexOf(needle, at);
    if (found === -1) break;
    if (found > at) parts.push(document.createTextNode(value.slice(at, found)));
    parts.push(el('mark', { text: value.slice(found, found + needle.length) }));
    at = found + needle.length;
  }
  if (!parts.length) return [document.createTextNode(value)];
  if (at < value.length) parts.push(document.createTextNode(value.slice(at)));
  return parts;
}

function resultRow(hit, onPick, term) {
  /*
   * Kolom kanan (.search-where) menggantikan baris kedua yang ditumpuk.
   * Alasannya contoh yang prototipe pakai: tiga layar bernama "Opname" di tiga
   * modul. Modulnya SUDAH ada di data sejak T2.5 — yang kurang hanya
   * tempatnya, dan bertumpuk ia terbaca sebagai keterangan, bukan sebagai
   * pembeda. Sebagian grup dokumen server menjawab title kosong; barisnya
   * cukup tidak punya kolom kanan, tanpa tempat kosong yang dipesan.
   */
  const node = el(`button.search-hit${hit.screen ? '.screen' : ''}`, { type: 'button' }, [
    el(`span.cell-main${hit.screen ? '' : '.mono'}`, marked(hit.code || `#${hit.id}`, term)),
    hit.title ? el('span.search-where', { text: hit.title }) : null,
  ]);

  node.addEventListener('click', () => onPick(hit));
  return node;
}

function groupNode(group, onPick, term) {
  return el('.search-group', [
    el('.search-group-label', { text: group.label }),
    ...group.results.map((hit) => resultRow(hit, onPick, term)),
  ]);
}

/* Layar di atas dokumen, dan sudah tergambar selagi server masih mencari:
   hasilnya ada di memori, dan orang yang mengetik "opname" mencari layar. */
function render(body, payload, onPick, screens = [], term = '') {
  clear(body);

  if (payload === null) {
    body.appendChild(el('p.muted', { text: `Ketik minimal ${MIN_LENGTH} huruf — kode dokumen, nama, atau layar.` }));
    return;
  }

  if (screens.length) body.appendChild(groupNode({ label: 'Layar', results: screens }, onPick, term));

  if (payload === 'loading') {
    body.appendChild(el('p.muted', { text: 'Mencari dokumen…' }));
    return;
  }

  if (payload === 'failed') {
    body.appendChild(el('p.muted', { text: 'Pencarian dokumen gagal. Coba lagi.' }));
    return;
  }

  if (!payload.groups.length && !screens.length) {
    body.appendChild(el('p.muted', { text: `Tidak ada hasil untuk "${payload.term}".` }));
    return;
  }

  for (const group of payload.groups) body.appendChild(groupNode(group, onPick, term));
}

export function openSearch() {
  const input = el('input.search-input', {
    type: 'search',
    placeholder: 'Cari kode dokumen, nama, proyek…',
    autocomplete: 'off',
    'aria-label': 'Pencarian global',
  });

  const body = el('.search-results');
  let timer = null;

  const pick = (hit) => {
    closeModal();
    window.location.hash = hit.link;
  };

  const run = async () => {
    const term = input.value.trim();

    if (term.length < MIN_LENGTH) {
      renderAndReset(body, null, pick);
      return;
    }

    const mine = ++sequence;
    const screens = screenHits(term);
    renderAndReset(body, 'loading', pick, screens, term);

    try {
      const payload = await api.get('core/search', { q: term });
      if (mine === sequence) renderAndReset(body, payload, pick, screens, term);
    } catch {
      if (mine === sequence) renderAndReset(body, 'failed', pick, screens, term);
    }
  };

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(run, DEBOUNCE_MS);
  });

  /*
   * Lapisan keyboard (UI-3b): panah memilih, Enter membuka yang TERPILIH.
   *
   * Sebelum ini Enter selalu mengklik `.search-hit` PERTAMA di DOM dan tidak ada
   * cara memilih yang lain tanpa melepas papan ketik — padahal barisnya sudah
   * <button>, jadi Tab + Enter bekerja. Yang hilang adalah jalan yang tangan
   * cari: panah, tanpa memindahkan fokus keluar dari kotak ketik.
   *
   * Fokus SENGAJA tetap di input. Memindahkan fokus ke baris berarti huruf
   * berikutnya yang diketik hilang — dan orang yang menekan panah bawah lalu
   * berubah pikiran akan mengetik ke dalam sebuah tombol.
   * Barisnya ditandai `aria-selected` + `.active`, dan input menunjuknya lewat
   * aria-activedescendant supaya pembaca layar ikut membacanya.
   */
  let activeIndex = -1;
  const rows = () => [...body.querySelectorAll('.search-hit')];

  const paintActive = () => {
    const all = rows();
    if (activeIndex >= all.length) activeIndex = all.length - 1;
    if (activeIndex < -1) activeIndex = -1;
    all.forEach((row, index) => {
      const on = index === activeIndex;
      row.classList.toggle('active', on);
      row.setAttribute('aria-selected', String(on));
      if (!row.id) row.id = `search-hit-${++rowSeq}`;
    });
    const current = all[activeIndex];
    if (current) {
      input.setAttribute('aria-activedescendant', current.id);
      current.scrollIntoView({ block: 'nearest' });
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  };

  // Setiap render membuang barisnya; pilihan mulai lagi dari atas.
  const renderAndReset = (...args) => { render(...args); activeIndex = -1; paintActive(); };

  input.addEventListener('keydown', (event) => {
    const all = rows();

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!all.length) return;
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      // Melingkar. Dari "belum ada pilihan", panah bawah mengambil yang pertama
      // dan panah atas mengambil yang terakhir.
      activeIndex = activeIndex === -1
        ? (step === 1 ? 0 : all.length - 1)
        : (activeIndex + step + all.length) % all.length;
      paintActive();
      return;
    }

    if (event.key === 'Enter') {
      // Enter tanpa pilihan tetap membuka yang pertama — alasan orang mengetik
      // kode dokumen lengkap lalu menekan Enter tanpa melihat daftarnya.
      const target = all[activeIndex] || all[0];
      if (target) { event.preventDefault(); target.click(); }
      return;
    }

    if (event.key === 'Home' && all.length) { event.preventDefault(); activeIndex = 0; paintActive(); }
    if (event.key === 'End' && all.length) { event.preventDefault(); activeIndex = all.length - 1; paintActive(); }
  });

  const dialog = modal({
    title: 'Pencarian',
    width: 'palette',
    body: el('div', [
      el('.palette-field', [svgIcon('search', { size: 16 }), input]),
      body,
      el('.palette-foot', [
        el('span', [el('kbd', { text: '↑' }), el('kbd', { text: '↓' }), ' pilih']),
        el('span', [el('kbd', { text: 'Enter' }), ' buka']),
        el('span', [el('kbd', { text: 'Esc' }), ' tutup']),
      ]),
    ]),
    footer: null,
  });

  render(body, null, pick);
  setTimeout(() => input.focus(), 30);

  // Ctrl+K while the palette is already up should land in this box, not be
  // refused as "something is open". Read back through isConnected so a closed
  // palette needs no bookkeeping to forget.
  palette = input;

  return dialog;
}

/** Ctrl/Cmd-K from anywhere, which is where every hand already reaches. */
export function registerSearchShortcut() {
  document.addEventListener('keydown', (event) => {
    if (!((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k')) return;

    // Swallowed even when the palette is refused below: Ctrl+K is the browser's
    // own "search from the address bar" in Chrome and Firefox, and letting it
    // through would throw the caret out of a trapped dialog into browser chrome.
    event.preventDefault();

    if (palette && palette.isConnected) {
      palette.focus();
      palette.select();
      return;
    }

    /*
     * Refuse while ANY modal is open. This listener is bound to document, and
     * the `inert` that ui.js puts on #root — which is what disables the sidebar,
     * the header and its own Cari button — does not stop a key shortcut. Opening
     * here stacked the palette ON TOP of a half-typed 15-line PO: picking a hit
     * called closeModal(), which under the modal stack closes only the top layer,
     * so the router repainted the page behind while the PO form stayed on screen
     * — still PUTting to the original PO, over a document it did not belong to,
     * with #root left inert and the whole shell dead.
     *
     * Refusing, rather than closing the form first: the only close that skips
     * the unsaved-data guard is close(), and those 15 lines are exactly what
     * this guard exists to protect, while requestClose() would put a discard
     * prompt on screen that the user never asked for by pressing a search
     * shortcut. Escape or Batal first, then search — one extra keystroke, and
     * the form is never at risk. It also ends the unlimited stacking: a held
     * Ctrl+K used to open one palette per key repeat.
     */
    if (modalDepth()) {
      // Silence here would read as "Ctrl+K is broken".
      if (!refusal || !refusal.isConnected) {
        refusal = toast('Tutup dulu jendela yang terbuka — hasil pencarian akan pindah halaman.', {
          tone: 'info',
          timeout: 4000,
        });
      }
      return;
    }

    openSearch();
  });
}
