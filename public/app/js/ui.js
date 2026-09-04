/* DOM helpers and shared widgets. No framework, no build step. */

/** el('div.card', { onclick }, [children]) — tag supports .class and #id. */
export function el(spec, props, children) {
  const match = /^([a-z0-9]+)?([.#][^\s]*)?$/i.exec(spec) || [];
  const node = document.createElement(match[1] || 'div');

  if (match[2]) {
    for (const token of match[2].split(/(?=[.#])/)) {
      const name = token.slice(1);
      if (!name) continue; // e.g. '.card.' from an empty template slot
      if (token[0] === '#') node.id = name;
      else node.classList.add(name);
    }
  }

  if (Array.isArray(props) || typeof props === 'string' || props instanceof Node) {
    children = props;
    props = null;
  }

  for (const [key, value] of Object.entries(props || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') node.className = `${node.className} ${value}`.trim();
    else if (key === 'html') node.innerHTML = value;
    else if (key === 'text') node.textContent = value;
    else if (key === 'style' && typeof value === 'object') Object.assign(node.style, value);
    else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
    else if (key === 'dataset') Object.assign(node.dataset, value);
    else if (value === true) node.setAttribute(key, '');
    else node.setAttribute(key, value);
  }

  append(node, children);
  return node;
}

export function append(node, children) {
  if (children === null || children === undefined || children === false) return node;
  if (Array.isArray(children)) {
    children.forEach((child) => append(node, child));
    return node;
  }
  node.appendChild(children instanceof Node ? children : document.createTextNode(String(children)));
  return node;
}

export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

/* ------------------------------------------------------------------ icons */
const PATHS = {
  search: 'M11 11 15 15M7 12.5A5.5 5.5 0 1 0 7 1.5a5.5 5.5 0 0 0 0 11Z',
  chevron: 'M4 6.5 8 10.5 12 6.5',
  chevronRight: 'M6.5 4 10.5 8 6.5 12',
  plus: 'M8 3.5v9M3.5 8h9',
  close: 'M4 4l8 8M12 4l-8 8',
  menu: 'M2.5 4h11M2.5 8h11M2.5 12h11',
  check: 'M3.5 8.5 6.5 11.5 12.5 4.5',
  edit: 'M11.2 2.8a1.7 1.7 0 0 1 2.4 2.4L5.5 13.3l-3.2.8.8-3.2 8.1-8.1Z',
  trash: 'M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.2a1 1 0 0 0 1 .8h3.8a1 1 0 0 0 1-.8l.6-8.2',
  back: 'M9.5 3.5 5 8l4.5 4.5',
  refresh: 'M13.5 8a5.5 5.5 0 1 1-1.7-4M13.5 2v3.5H10',
  download: 'M8 2.5v8M4.5 7.5 8 11l3.5-3.5M2.5 13.5h11',
  inbox: 'M2 9.5h3.5l1 2h5l1-2H16M2.5 9.5 4.3 3.4A1 1 0 0 1 5.3 2.7h7.4a1 1 0 0 1 1 .7l1.8 6.1v3a1 1 0 0 1-1 1H3.5a1 1 0 0 1-1-1v-3Z',
  warn: 'M8 6v3.5M8 11.6v.1M7.1 2.6 1.6 12a1 1 0 0 0 .9 1.5h11a1 1 0 0 0 .9-1.5L8.9 2.6a1 1 0 0 0-1.8 0Z',
  logout: 'M6 14H3.5a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1H6M10.5 11.5 14 8l-3.5-3.5M14 8H6',
  print: 'M4.5 6V2.5h7V6M4.5 12H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-1.5M4.5 9.5h7v4h-7z',
  sun: 'M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM8 1v1.5M8 13.5V15M15 8h-1.5M2.5 8H1M12.9 3.1l-1 1M4.1 11.9l-1 1M12.9 12.9l-1-1M4.1 4.1l-1-1',
  moon: 'M13.5 9.6A5.8 5.8 0 0 1 6.4 2.5a5.8 5.8 0 1 0 7.1 7.1Z',
  star: 'M8 1.9l1.9 3.9 4.3.6-3.1 3 .7 4.3L8 11.6l-3.8 2.1.7-4.3-3.1-3 4.3-.6z',
};

export function icon(name, size = 16) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('width', size);
  svg.setAttribute('height', size);
  svg.setAttribute('viewBox', '0 0 16 16');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('aria-hidden', 'true');

  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', PATHS[name] || '');
  path.setAttribute('stroke', 'currentColor');
  path.setAttribute('stroke-width', '1.5');
  path.setAttribute('stroke-linecap', 'round');
  path.setAttribute('stroke-linejoin', 'round');
  svg.appendChild(path);
  return svg;
}

/* ----------------------------------------------------------------- badges */
export function badge(label, tone = '') {
  return el(`span.badge${tone ? `.${tone}` : ''}.dot`, { text: label ?? '—' });
}

/* ---------------------------------------------------------------- buttons */
export function button(label, { variant = '', size = '', iconName, onClick, disabled, title, type = 'button' } = {}) {
  const node = el(
    `button.btn${variant ? `.${variant}` : ''}${size ? `.${size}` : ''}`,
    { type, disabled: disabled || undefined, title, onclick: onClick },
    [iconName ? icon(iconName, size === 'sm' ? 13 : 15) : null, label ? el('span', { text: label }) : null],
  );
  if (!label) node.classList.add('icon');
  return node;
}

/** Swaps the button into a spinner for the duration of an async handler. */
export async function withBusy(node, fn) {
  const original = node.innerHTML;
  node.disabled = true;
  clear(node).appendChild(el('span.spin'));
  try {
    return await fn();
  } finally {
    node.disabled = false;
    node.innerHTML = original;
  }
}

/* ------------------------------------------------------------------- menu */
/*
 * Tombol yang membuka daftar perintah — pola WAI-ARIA "menu button".
 *
 * Pemakai pertamanya "Cetak ▾" di bilah aksi dokumen (detail.js printMenu,
 * T2.6). Diukur 4 Sep 2026 (harness S2 › po_bar): PO draf memajang 7 tombol
 * setara di satu baris — Kembali · Cetak halaman · PDF · Cetak Pesanan
 * Pembelian · XLSX · Ubah · Ajukan — dan keputusan bernilai ratusan juta duduk
 * di sebelah "Cetak halaman" (ASESMEN-UX §1.2). Empat keluaran itu kini satu
 * tombol.
 *
 * Popup-nya BARU dibangun saat dibuka dan dibuang saat ditutup: yang ada di
 * DOM adalah tombol yang bisa ditekan sekarang, bukan empat tombol yang
 * disembunyikan CSS — `.page-head .actions button` menghitung apa adanya.
 * Satu menu terbuka pada satu waktu, seperti popup combobox.js. Escape
 * menutup dan mengembalikan fokus ke tombolnya; Tab menutup lalu berjalan
 * dari tombolnya (item yang dibuang tidak punya "berikutnya"); klik di luar
 * dan pergantian rute menutup tanpa memindah fokus. Panah atas/bawah
 * berpindah item, Home/End ke ujung, panah pada tombolnya membuka.
 *
 * items: [{ label, iconName, title, onClick(event, trigger) }] — trigger
 * adalah tombol menunya, untuk withBusy: item yang dipilih sudah dibuang
 * bersama popup-nya, jadi spinner harus duduk di tombol yang masih terlihat.
 * onClick dipanggil SEBELUM popup ditutup dan tanpa await: openPrintable
 * (print.js) membuka tab pada klik yang masih di tumpukan.
 */
let openMenu = null;
let menuSeq = 0;

export function menuButton(label, items, { iconName, title, variant = '', size = '' } = {}) {
  const id = `menu-${++menuSeq}`;
  const trigger = button(label, { iconName, title, variant, size });
  trigger.id = `${id}-trigger`;
  trigger.classList.add('menu-trigger');
  trigger.appendChild(icon('chevron', 12));
  trigger.setAttribute('aria-haspopup', 'menu');
  trigger.setAttribute('aria-expanded', 'false');
  const wrap = el('span.menu-wrap', [trigger]);
  let pop = null;

  const itemsIn = () => (pop ? [...pop.querySelectorAll('.menu-item:not(:disabled)')] : []);

  function close({ refocus = false } = {}) {
    if (!pop) return;
    const held = pop.contains(document.activeElement);
    pop.remove();
    pop = null;
    trigger.setAttribute('aria-expanded', 'false');
    trigger.removeAttribute('aria-controls');
    document.removeEventListener('pointerdown', onOutside, true);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('hashchange', onRoute);
    if (openMenu === handle) openMenu = null;
    if (refocus || held) trigger.focus();
  }

  function onOutside(event) {
    if (!wrap.contains(event.target)) close();
  }

  function onRoute() {
    close();
  }

  function onKey(event) {
    const list = itemsIn();
    if (!list.length) return;
    const at = list.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      close({ refocus: true });
    } else if (event.key === 'Tab') {
      close({ refocus: true });
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      (list[at + 1] || list[0]).focus();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      (at > 0 ? list[at - 1] : list[list.length - 1]).focus();
    } else if (event.key === 'Home') {
      event.preventDefault();
      list[0].focus();
    } else if (event.key === 'End') {
      event.preventDefault();
      list[list.length - 1].focus();
    }
  }

  function open({ focusLast = false } = {}) {
    if (pop) return;
    if (openMenu) openMenu.close();
    pop = el('.menu-pop', { id, role: 'menu', 'aria-labelledby': trigger.id });
    items.filter(Boolean).forEach((item) => {
      const node = el('button.menu-item', {
        type: 'button', role: 'menuitem', tabindex: '-1', title: item.title, disabled: item.disabled || undefined,
      }, [item.iconName ? icon(item.iconName, 14) : null, el('span', { text: item.label })]);
      node.addEventListener('click', (event) => {
        item.onClick(event, trigger);
        close({ refocus: true });
      });
      pop.appendChild(node);
    });
    wrap.appendChild(pop);
    /* Rapat kanan pada tombolnya (app.css .menu-pop). Bila tepi kirinya keluar
       layar — ponsel 390 px: bilah aksi di 84–376 px, popup 358 px, terukur
       4 Sep 2026 left −162 — geser ke kanan secukupnya, tidak lebih dari sisa
       ruang di kanan; max-width popup < lebar layar, jadi selalu muat. */
    const box = pop.getBoundingClientRect();
    const shove = Math.min(Math.max(0, 8 - box.left), Math.max(0, window.innerWidth - 8 - box.right));
    if (shove) pop.style.right = `${-shove}px`;
    trigger.setAttribute('aria-expanded', 'true');
    trigger.setAttribute('aria-controls', id);
    document.addEventListener('pointerdown', onOutside, true);
    document.addEventListener('keydown', onKey);
    window.addEventListener('hashchange', onRoute);
    openMenu = handle;
    const list = itemsIn();
    if (list.length) (focusLast ? list[list.length - 1] : list[0]).focus();
  }

  const handle = { close };

  trigger.addEventListener('click', () => (pop ? close() : open()));
  trigger.addEventListener('keydown', (event) => {
    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !pop) {
      event.preventDefault();
      open({ focusLast: event.key === 'ArrowUp' });
    }
  });

  return wrap;
}

/* ----------------------------------------------------------------- toasts */
export function toast(message, { tone = 'ok', title, timeout = 5200 } = {}) {
  const host = document.getElementById('toasts');
  const node = el(`.toast.${tone}`, [
    el('div', { style: { flex: '1', minWidth: '0' } }, [
      title ? el('b', { text: title }) : null,
      el('.msg', { text: message }),
    ]),
    el('button', { 'aria-label': 'Tutup', onclick: () => node.remove() }, icon('close', 13)),
  ]);
  host.appendChild(node);
  if (timeout) setTimeout(() => node.remove(), timeout);
  return node;
}

export function toastError(error) {
  const details = error && error.details ? error.details : [];
  const message = details.length ? details.slice(0, 4).join('\n') : error.message || String(error);
  /* Laravel menaruh galat pertama sebagai `message` DAN sebagai baris pertama
     `errors`, jadi toast 422 dulu membaca kalimat yang sama dua kali — sekali
     sebagai judul, sekali sebagai rincian (diukur 2 Sep 2026 pada PO). Judul
     hanya dipakai bila ia memang kalimat lain. */
  const firstDetail = details.length ? details[0].replace(/^[^:]+:\s*/, '') : null;
  toast(message, {
    tone: 'err',
    title: details.length && firstDetail !== error.message ? error.message : undefined,
    /* A long refusal stays until dismissed. The maker-checker message is ~40
       words ending with the way out ("… matikan di Pengaturan"); at 200 wpm it
       needs ~12 detik, and the 8-second timer ate exactly the clause that told
       the operator what to do. toast() treats 0 as "tetap tampil". */
    timeout: message.length > 160 ? 0 : 8000,
  });
}

/* ------------------------------------------------------------------ modal */

/*
 * Modals are a STACK, not a single slot. The old version rendered every modal
 * into the shared #overlay and cleared it first, so opening a second one deleted
 * the first without a trace: Ctrl+K is a document-level shortcut that stays live
 * while a form is open (search.js), so one stray keystroke wiped a half-typed
 * 15-line purchase order. Depth 0 still renders into #overlay, so the ordinary
 * single-modal case is DOM- and CSS-identical to before; deeper layers get their
 * own overlay element stacked on top.
 */
const stack = [];

/*
 * Cleanups that run whenever ANY modal closes. combobox.js registers its
 * popup-closer here: that popup is position:fixed inside document.body because
 * .modal-body's overflow-y would otherwise clip it, which means nothing takes it
 * down together with the form that owns it. Registration goes this way round on
 * purpose — ui.js must never import a widget that imports ui.js.
 */
const overlayCleanups = new Set();

export function registerOverlayCleanup(fn) {
  overlayCleanups.add(fn);
  return () => overlayCleanups.delete(fn);
}

const DIRTY_PROMPT = {
  title: 'Tutup tanpa menyimpan?',
  message: 'Formulir ini punya isian yang belum tersimpan — termasuk baris yang sudah diketik. '
    + 'Kalau ditutup sekarang, semuanya hilang.',
  confirmLabel: 'Buang isian',
  cancelLabel: 'Kembali mengisi',
};

const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type=hidden])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

/** Visible focusables only — getClientRects() is what filters out display:none. */
function focusablesIn(node) {
  return [...node.querySelectorAll(FOCUSABLE)].filter((item) => item.getClientRects().length > 0);
}

let modalSeq = 0;

const topModal = () => (stack.length ? stack[stack.length - 1] : null);

/*
 * #root is inert while anything is open, so the sidebar and the list behind the
 * backdrop cannot be tabbed or clicked into. #toasts is a SIBLING of #root in
 * index.html, so a toastError raised by a failing save is still rendered and
 * still announced. Recomputed from the stack on every open and close instead of
 * being counted up and down by hand: a miscounted depth leaves the whole app
 * dead after a nested dialog, with nothing on screen to explain why.
 */
function syncInert() {
  const root = document.getElementById('root');
  if (root) root.toggleAttribute('inert', stack.length > 0);
  stack.forEach((entry, index) => entry.layer.toggleAttribute('inert', index < stack.length - 1));
}

/*
 * One keydown listener for the whole stack, in the BUBBLE phase, deliberately.
 * The lookup combobox swallows Escape with stopPropagation() while its popup is
 * open so that the first Escape closes only the popup; a capture-phase listener
 * here would fire first and tear down the entire form underneath it — exactly
 * the loss this guard exists to prevent. Do not "improve" this to capture.
 */
document.addEventListener('keydown', (event) => {
  const top = topModal();
  if (!top) return;
  if (event.key === 'Escape') top.requestClose();
  else if (event.key === 'Tab') trapTab(event, top);
});

/* A deterministic belt to inert's braces: keeps Tab cycling inside the TOP
   layer, so a confirm dialog over a form never leaks focus into the form. */
function trapTab(event, top) {
  const items = focusablesIn(top.node);

  if (!items.length) {
    event.preventDefault();
    top.node.focus();
    return;
  }

  const first = items[0];
  const last = items[items.length - 1];
  const active = document.activeElement;

  if (!top.node.contains(active)) {
    event.preventDefault();
    (event.shiftKey ? last : first).focus();
  } else if (event.shiftKey && active === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && active === last) {
    event.preventDefault();
    first.focus();
  }
}

export function modal({ title, body, footer, width = '', onClose, dirty, dirtyPrompt, initialFocus }) {
  const depth = stack.length;
  const layer = depth === 0
    ? document.getElementById('overlay')
    : el('.overlay.overlay-stacked', { style: { zIndex: String(60 + depth) } });

  if (depth === 0) {
    clear(layer); // empty already unless an earlier bug left something behind
    layer.hidden = false;
  } else {
    document.body.appendChild(layer);
  }

  // Whatever had focus gets it back on close: the row's Ubah button, the toolbar
  // Tambah button, the sidebar link the user tabbed to.
  const returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const titleId = `modal-title-${++modalSeq}`;
  let closed = false;
  let guarding = false;

  const close = () => {
    if (closed) return;
    closed = true;

    const index = stack.indexOf(entry);
    if (index >= 0) stack.splice(index, 1);

    overlayCleanups.forEach((fn) => {
      try { fn(); } catch { /* a broken cleanup must never trap the modal open */ }
    });

    layer.removeAttribute('inert'); // #overlay is reused; a leftover inert kills the next modal
    if (depth === 0) {
      layer.hidden = true;
      layer.onmousedown = null;
      layer.onclick = null;
      clear(layer);
    } else {
      layer.remove();
    }

    syncInert();

    // Restore focus BEFORE onClose. onSaved re-renders the list and destroys the
    // button that had focus, and .focus() on a detached node quietly lands on
    // <body> — which drops a keyboard user back at the top of the page.
    if (returnFocus && returnFocus.isConnected) returnFocus.focus({ preventScroll: true });
    if (onClose) onClose();
  };

  // Programmatic close() also fires onClose, which is what a caller wants for
  // Escape and the backdrop but NOT after its own submit handler has already
  // decided the answer. Callers that resolve a promise must therefore resolve it
  // BEFORE calling close(); a promise only settles once, so the onClose default
  // then lands as a no-op. Getting that order wrong makes the dialog look like
  // it worked and silently do nothing.

  /*
   * The "I did not mean to close this" routes — Escape, the backdrop, the X, and
   * openForm's Batal — all come through here. close() keeps its old meaning of
   * "close now, no questions", which is what the save path needs: a successful
   * POST is not a discard. Modals that pass no `dirty` behave exactly as before.
   */
  const requestClose = async () => {
    if (closed) return true;
    if (guarding) return false; // the guard dialog is already up; ignore the second Escape
    if (!dirty) { close(); return true; }

    let unsaved = false;
    try {
      unsaved = Boolean(dirty());
    } catch {
      unsaved = true; // a guard that throws must fail towards keeping the data
    }
    if (!unsaved) { close(); return true; }

    guarding = true;
    let discard = false;
    try {
      const prompt = { ...DIRTY_PROMPT, ...(dirtyPrompt || {}) };
      discard = await confirmDialog({
        title: prompt.title,
        message: prompt.message,
        confirmLabel: prompt.confirmLabel,
        cancelLabel: prompt.cancelLabel,
      });
    } finally {
      guarding = false;
    }

    if (!discard) return false;
    close();
    return true;
  };

  const node = el(`.modal${width ? `.${width}` : ''}`, {
    role: 'dialog',
    'aria-modal': 'true',
    'aria-labelledby': titleId,
    tabindex: '-1',
  }, [
    el('.modal-head', [
      el('h2', { id: titleId, text: title }),
      el('.spacer'),
      button('', { variant: 'ghost', size: 'sm', iconName: 'close', onClick: () => requestClose(), title: 'Tutup' }),
    ]),
    el('.modal-body', body),
    footer ? el('.modal-foot', footer) : null,
  ]);

  layer.appendChild(node);

  /*
   * The backdrop counts only when the press AND the release both land on it.
   * Selecting text inside a form and letting go of the mouse over the backdrop
   * used to read as a backdrop click and threw the whole form away.
   */
  let pressedBackdrop = false;
  layer.onmousedown = (event) => { pressedBackdrop = event.target === layer; };
  layer.onclick = (event) => {
    const onBackdrop = event.target === layer && pressedBackdrop;
    pressedBackdrop = false;
    if (onBackdrop) requestClose();
  };

  const entry = { node, layer, close, requestClose };
  stack.push(entry);
  syncInert();

  /*
   * Focus the first REAL input, not the first input. A goods receipt carries the
   * hidden po_item_id inside the first line-table cell, so the old
   * 'input, select, textarea, button.primary' selector focused an invisible
   * field and the clerk's first keystroke went nowhere. [readonly] is skipped
   * for the same reason: a lookup whose source 403'd or failed now STAYS in the
   * tab order deliberately (form.js), but opening a form with the caret parked
   * in a box that refuses every keystroke is not where the clerk wants to be.
   */
  setTimeout(() => {
    if (closed) return;
    const explicit = typeof initialFocus === 'function' ? initialFocus() : initialFocus;
    const target = (explicit && explicit.isConnected ? explicit : null)
      || node.querySelector('.modal-body input:not([type=hidden]):not([disabled]):not([readonly]), .modal-body select:not([disabled]), .modal-body textarea:not([disabled])')
      || node.querySelector('button.primary')
      || node;
    target.focus({ preventScroll: true });
  }, 30);

  return { node, close, requestClose };
}

/** Closes the TOP modal. Identical to before whenever only one is open. */
export function closeModal() {
  const top = topModal();
  if (top) top.close();
}

/*
 * Menutup SEMUA lapisan tanpa bertanya. Satu-satunya pemanggilnya adalah jalur
 * 401: sesi yang berakhir di tengah formulir dulu menggambar halaman masuk DI
 * BAWAH overlay yang masih terbuka — tombol Masuk tertutup backdrop, dan satu-
 * satunya jalan adalah Esc → "Buang isian" (diukur 2 Sep 2026). Isian tidak
 * hilang karena penutupan ini: form.js sudah menyimpan drafnya ke localStorage
 * pada setiap ketikan, dan app.js meminta flush sekali lagi sebelum memanggil
 * ini.
 */
export function closeAllModals() {
  while (stack.length) stack[stack.length - 1].close();
}

/*
 * How many layers are open, for the handlers `inert` cannot reach. A key
 * shortcut bound to document keeps firing while #root is inert, so search.js
 * asks this before opening the palette: stacking it over a half-typed purchase
 * order let one Ctrl+K navigate the page out from under the form.
 */
export const modalDepth = () => stack.length;

export function confirmDialog({
  title, message,
  confirmLabel = 'Ya, lanjutkan',
  cancelLabel = 'Batal',
  tone = 'danger',
  onConfirm,
}) {
  return new Promise((resolve) => {
    const confirm = button(confirmLabel, {
      variant: tone,
      onClick: async () => {
        await withBusy(confirm, async () => {
          try {
            if (onConfirm) await onConfirm();
            // Resolve first: close() fires onClose, which would otherwise settle
            // this promise as `false` and make every confirm-guarded action —
            // deletes included — quietly do nothing.
            resolve(true);
            dialog.close();
          } catch (error) {
            toastError(error);
          }
        });
      },
    });

    const cancel = button(cancelLabel, { onClick: () => { resolve(false); dialog.close(); } });

    const dialog = modal({
      title,
      width: 'narrow',
      body: el('p', { text: message, style: { margin: '0', color: 'var(--text-2)' } }),
      footer: [cancel, confirm],
      onClose: () => resolve(false),
      // A destructive dialog must not open with Hapus / Buang isian sitting under
      // the Enter key: the safe answer is the one every reflex produces.
      initialFocus: tone === 'danger' ? cancel : confirm,
    });
  });
}

/* -------------------------------------------------------- keyboard: rows */
/*
 * list.js, custom.js and dashboard.js all build `tr.clickable` rows whose only
 * way in was a mouse click — a <tr> cannot take focus without a tabindex, and no
 * CSS gave one. Rather than three edits in three files another team is touching,
 * ui.js (imported by every view) stamps the rows itself and delegates the key
 * handling. The rows stay rows: no role="link", which would hide the table
 * structure from a screen reader.
 *
 * ONE tab stop per table, not one per row. A flat tabindex="0" on the 20 rows
 * list.js paints (perPage defaults to 20) plus their Ubah/Hapus buttons put
 * about 60 stops between the search box and "Berikutnya" on every list screen:
 * the rows became reachable and the pager became unreachable in practice. What a
 * data table is expected to do instead is what this does — Tab enters the tbody
 * once, ArrowUp/ArrowDown move between rows and carry the stop with them,
 * Home/End jump to the ends, Tab leaves.
 */
function installRowKeys() {
  /* The next/previous row in the same tbody, skipping anything that is not a
     clickable row. Returns null at the ends: a 20-row list is not a carousel. */
  const step = (row, delta) => {
    let candidate = delta > 0 ? row.nextElementSibling : row.previousElementSibling;
    while (candidate && !candidate.classList.contains('clickable')) {
      candidate = delta > 0 ? candidate.nextElementSibling : candidate.previousElementSibling;
    }
    return candidate;
  };

  const edge = (row, delta) => {
    let candidate = row;
    for (let next = step(candidate, delta); next; next = step(candidate, delta)) candidate = next;
    return candidate;
  };

  /* Moving the focus moves the tab stop with it, so Tab always leaves from the
     row the user is actually looking at. */
  const focusRow = (from, to) => {
    if (!to || to === from) return;
    from.tabIndex = -1;
    to.tabIndex = 0;
    to.focus();
  };

  const stampRows = (scope) => {
    const bodies = new Map();
    scope.querySelectorAll('tr.clickable').forEach((row) => {
      if (!row.parentElement) return;
      if (!bodies.has(row.parentElement)) bodies.set(row.parentElement, []);
      bodies.get(row.parentElement).push(row);
    });

    for (const [body, rows] of bodies) {
      // Whichever row has focus keeps the stop: a repaint of one cell must not
      // drag the tab stop back to row 1 while the user is standing on row 12.
      const home = rows.find((row) => row === document.activeElement) || rows[0];
      rows.forEach((row) => { row.tabIndex = row === home ? 0 : -1; });

      // Pembaca layar membacakan isi sel, tetapi tidak pernah menyebut bahwa
      // Enter membuka dokumennya (ASESMEN-UX §1.5, 2 Sep 2026). SEKALI di tbody,
      // bukan per baris: 20 baris × satu kalimat yang sama adalah kebisingan.
      if (!body.hasAttribute('aria-description')) body.setAttribute('aria-description', 'Tekan Enter untuk membuka');
    }
  };

  document.addEventListener('keydown', (event) => {
    if (!(event.target instanceof Element)) return;

    // Only when the ROW itself has focus. Enter on the Hapus button inside the
    // row must delete, not also navigate to the detail page.
    const row = event.target.closest('tr.clickable');
    if (!row || event.target !== row) return;

    switch (event.key) {
      case 'Enter':
      case ' ':
      case 'Spacebar':
        event.preventDefault(); // Space would scroll the page out from under the table
        row.click();
        return;

      // preventDefault on all four: otherwise the page scrolls while the focus
      // ring stays put, and the row the user thinks they are on is off-screen.
      case 'ArrowDown':
      case 'Down':
        event.preventDefault();
        focusRow(row, step(row, 1));
        return;

      case 'ArrowUp':
      case 'Up':
        event.preventDefault();
        focusRow(row, step(row, -1));
        return;

      case 'Home':
        event.preventDefault();
        focusRow(row, edge(row, -1));
        return;

      case 'End':
        event.preventDefault();
        focusRow(row, edge(row, 1));
        return;

      default:
    }
  });

  /*
   * Scoped to #root, NOT documentElement. Every tr.clickable in the app is
   * painted inside #root (list.js, custom.js, dashboard.js), while the combobox
   * popup, #toasts and the modal overlays are all SIBLINGS of it in index.html.
   * On the whole document this observer had an unbounded input: the popup
   * rebuilds up to 50 option nodes on every keystroke, so typing "semen
   * portland" into one PO line's item picker scheduled 14 full-document
   * querySelectorAll sweeps that could not match anything — the rows behind were
   * stamped by the first sweep. Scoped here it wakes up once per list render,
   * which is the only moment a row can actually need stamping.
   *
   * Kept here rather than pushed into the three view files: the roving tabindex
   * above is one rule over a whole tbody, and three private copies of "first row
   * 0, the rest -1" in files another team is editing would drift apart the first
   * time one of them filters or re-orders its rows.
   */
  const root = document.getElementById('root');
  if (!root) return; // index.html always ships it; nothing to stamp without it

  // Coalesced to one sweep per animation frame in which something changed: a
  // per-mutation querySelectorAll would run hundreds of times while a 50-row
  // list paints.
  let queued = false;
  new MutationObserver(() => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; stampRows(root); });
  }).observe(root, { childList: true, subtree: true });

  stampRows(root);
}

installRowKeys();

/* ------------------------------------------------------------------ empty */
export function emptyState(message, { title = 'Belum ada data', action } = {}) {
  return el('.empty', [icon('inbox', 34), el('h3', { text: title }), el('p', { text: message }), action || null]);
}

export function errorState(error, retry) {
  return el('.alert.error', [
    icon('warn', 16),
    el('div', { style: { flex: '1' } }, [
      el('div', { text: error.message || String(error) }),
      ...(error.details || []).map((line) => el('div.muted', { text: line, style: { fontSize: '12px' } })),
    ]),
    retry ? button('Coba lagi', { size: 'sm', onClick: retry }) : null,
  ]);
}

export function skeletonTable(rows = 6, cols = 5) {
  return el('.card-body', Array.from({ length: rows }, () =>
    el('div', { style: { display: 'flex', gap: '14px', padding: '7px 0' } },
      Array.from({ length: cols }, (_, i) => el('.skeleton', { style: { flex: i === 0 ? '2' : '1' } }))),
  ));
}

/* ----------------------------------------------------------------- fields */
let fieldSeq = 0;

/*
 * A <label> names the control inside it from ALL of its text content, so every
 * hint, every validation error, and every button a widget puts in the wrapper
 * gets glued onto the field's accessible name. The combobox made that visible —
 * a nullable lookup announced itself as "Vendor Kosongkan", and one whose source
 * had been truncated read its entire 30-word warning as the field's name — but
 * it was never combobox-specific: `hint` and `help` have always leaked the same
 * way into every text and number field in the application.
 *
 * Pointing the control at the caption with aria-labelledby stops name-from-
 * content running at all, so the name is the caption and nothing else. The hint
 * and the error stay reachable as descriptions, which is what they are.
 */
export function field(label, control, { required, help, hint } = {}) {
  const captionId = `fld-${++fieldSeq}`;
  const caption = el('label', { id: captionId }, [label, required ? el('span.req', { text: '*' }) : null]);

  // The control may be the input itself or a widget wrapper around one.
  const named = control instanceof Element
    ? (control.matches('input, select, textarea') ? control : control.querySelector('input, select, textarea'))
    : null;

  // A widget that already named itself knows better than we do.
  if (named && !named.hasAttribute('aria-label') && !named.hasAttribute('aria-labelledby')) {
    named.setAttribute('aria-labelledby', captionId);
  }

  return el('label.field', [
    caption,
    control,
    hint ? el('.help', { text: hint }) : null,
    help ? el('.help', { text: help }) : null,
  ]);
}

export function setFieldError(control, message) {
  const wrap = control.closest('.field');
  if (!wrap) return;
  wrap.querySelectorAll('.err').forEach((node) => node.remove());
  wrap.classList.toggle('invalid', Boolean(message));
  if (message) wrap.appendChild(el('.err', { text: message }));
}

export function progressBar(value, tone = '') {
  const pct = Math.max(0, Math.min(100, Number(value) || 0));
  return el(`.progress${tone ? `.${tone}` : ''}`, el('span', { style: { width: `${pct}%` } }));
}

/** Resolve 'customer.name' style paths against a row. */
export function pluck(row, path) {
  return String(path).split('.').reduce((acc, key) => (acc === null || acc === undefined ? acc : acc[key]), row);
}
