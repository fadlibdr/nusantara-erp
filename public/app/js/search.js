/* Pencarian global — one box over everything the user may read.

   There was no search box in the shell and no command palette; navigation was
   the sidebar and nothing else, so finding PO/2026/VII/0042 meant knowing it was
   a purchase order first. */

import { api } from './api.js';
import { el, clear, modal, closeModal, modalDepth, toast } from './ui.js';

const MIN_LENGTH = 2;
const DEBOUNCE_MS = 220;

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

/* The "close the modal first" banner, so a held Ctrl+K raises one, not thirty. */
let refusal = null;

function resultRow(hit, onPick) {
  const node = el('button.search-hit', { type: 'button' }, [
    el('span.cell-main.mono', { text: hit.code || `#${hit.id}` }),
    hit.title ? el('span.cell-sub', { text: hit.title }) : null,
  ]);

  node.addEventListener('click', () => onPick(hit));
  return node;
}

function render(body, payload, onPick) {
  clear(body);

  if (payload === null) {
    body.appendChild(el('p.muted', { text: `Ketik minimal ${MIN_LENGTH} huruf — kode dokumen, nama, atau nomor.` }));
    return;
  }

  if (payload === 'loading') {
    body.appendChild(el('p.muted', { text: 'Mencari…' }));
    return;
  }

  if (!payload.groups.length) {
    body.appendChild(el('p.muted', { text: `Tidak ada hasil untuk "${payload.term}".` }));
    return;
  }

  for (const group of payload.groups) {
    body.appendChild(el('.search-group', [
      el('.search-group-label', { text: group.label }),
      ...group.results.map((hit) => resultRow(hit, onPick)),
    ]));
  }
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
      render(body, null, pick);
      return;
    }

    const mine = ++sequence;
    render(body, 'loading', pick);

    try {
      const payload = await api.get('core/search', { q: term });
      if (mine === sequence) render(body, payload, pick);
    } catch {
      if (mine === sequence) {
        clear(body).appendChild(el('p.muted', { text: 'Pencarian gagal. Coba lagi.' }));
      }
    }
  };

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(run, DEBOUNCE_MS);
  });

  // Enter opens the first hit — the reason somebody types a full document code.
  input.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    const first = body.querySelector('.search-hit');
    if (first) first.click();
  });

  const dialog = modal({
    title: 'Pencarian',
    width: 'narrow',
    body: el('div', [input, body]),
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
