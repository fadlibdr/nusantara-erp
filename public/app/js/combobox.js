/*
 * Type-ahead picker. One generic control behind every reference field in the
 * app; it knows nothing about lookup.js or schema.js — the caller hands it
 * options and reads back whatever value it committed.
 *
 * It replaces a plain <select>, which failed in two ways that cost money:
 *
 *  - No type-ahead. A PO line's item picker holds the whole catalogue, and the
 *    only way to reach "Semen Portland Tipe I 40 kg" was to scroll a native
 *    dropdown past two thousand rows, on every line, on every PO.
 *
 *  - A <select> silently drops a value it has no <option> for. Editing a
 *    subcontract whose vendor had been unflagged is_subcontractor set
 *    select.value back to '', read() returned null, and openForm wrote that null
 *    straight into the PUT — the reference vanished on save with nothing said.
 *    Here the committed value is held in a variable, never derived from the
 *    loaded rows, so an unlisted id survives an edit and shows itself instead.
 */

import { el, clear, icon, button, registerOverlayCleanup } from './ui.js';

/*
 * Nobody reads past 50 rows without typing, and 50 nodes is the entire point:
 * a <select> over the 2 000-item catalogue put 2 000 <option> elements in the
 * DOM per field, per line — 30 000 of them on a 15-line purchase order.
 */
const MAX_RENDER = 50;

/* Below this much room under the input, the popup opens upwards instead. */
const MIN_SPACE_BELOW = 200;

/* The "—" row, same glyph and same meaning as the old <option value="">—</option>. */
const EMPTY_ENTRY = { value: null, label: '—' };

let seq = 0;

/*
 * Exactly one popup is open application-wide, like a native <select>. Two lists
 * on screen would need an answer for which one Escape and the arrow keys belong
 * to, and there is no answer a user would guess right.
 */
let current = null;

function closeCurrent() {
  if (current) current.closePopup();
}

/*
 * The popup is position:fixed inside <body> (see reposition()), so nothing takes
 * it down together with the form that owns it. ui.js runs this on every modal
 * close, at any depth. Registered once, at module load, per its contract — and
 * this way round so ui.js never has to import a widget that imports ui.js.
 */
registerOverlayCleanup(closeCurrent);

/*
 * Third belt, after the singleton and the blur handler: capture phase, so a
 * handler further down that stops propagation still cannot leave an orphan list
 * floating over the screen.
 */
document.addEventListener('pointerdown', (event) => {
  if (current && !current.owns(event.target)) closeCurrent();
}, true);

/*
 * One visually-hidden live region for the whole app, next to the singleton
 * popup and, like it, a child of <body>.
 *
 * It carries the two things in the popup a screen reader can never reach: the
 * "Menampilkan 50 dari 2.000" footer and the truncation / permission sentence
 * are plain <div> children of the element that carries role="listbox", so
 * aria-activedescendant only ever lands on the .combo-opt rows and skips them.
 * Without this, someone arrowing through the item picker is told the list holds
 * 50 rows, never finds "Semen Portland Tipe I 40 kg" among the 2 000 in the
 * catalogue, and concludes it is not there — the same wrong conclusion the
 * 8-second truncation toast used to cause, fixed for sighted users only.
 *
 * It must live in <body> and NOT inside .combo: field() in ui.js wraps the
 * control in a <label>, and everything inside that label is folded into the
 * input's accessible name.
 */
const liveRegion = el('.sr-only', { role: 'status', 'aria-live': 'polite' });
document.body.appendChild(liveRegion);

/* Writing the same sentence again is still a mutation, so a screen reader
   repeats it — which on the keystroke path means once per character typed. */
function announce(text) {
  const next = text || '';
  if (liveRegion.textContent === next) return;
  liveRegion.textContent = next;
}

const hasValue = (value) => value !== null && value !== undefined && value !== '';
const sameValue = (a, b) => String(a) === String(b);

/**
 * @param {object}   options
 * @param {*}        options.value       committed value; read() returns THIS until a commit
 * @param {string}   options.label       display text for `value` ('' while the source loads)
 * @param {Array}    options.options     [{ value, label, row }] — what lookup.optionsFor() returns
 * @param {string}   options.placeholder
 * @param {boolean}  options.allowEmpty  renders the "—" row and the × clear button
 * @param {?string}  options.notice      pinned at the top of the popup and under the field
 * @param {boolean}  options.disabled
 * @param {boolean}  options.compact     31px rows, for line-table cells
 * @param {?Function} options.onRetry    renders a "Coba lagi" next to the notice
 */
export function combobox({
  value = null,
  label = '',
  options = [],
  placeholder = '',
  allowEmpty = false,
  notice = null,
  disabled = false,
  compact = false,
  onRetry = null,
} = {}) {
  const id = `combo-${++seq}`;

  let list = [];
  /* Lowercased labels, rebuilt only when the options change. Re-lowercasing
     10 000 strings on every keystroke is the one thing here that would actually
     be slow; matching against a prepared array is about a millisecond. */
  let hay = [];

  let committed = hasValue(value) ? value : null;
  let committedLabel = label || '';

  /* INVARIANT: `query` is '' whenever the popup is closed. Opening does not seed
     it from the input text — a picker that filtered by its own current label
     would show exactly one row, the one already chosen, and changing a value
     would be impossible without clearing it first. */
  let query = '';

  let matchIdx = null;  // null == every option, in source order
  let entries = [];     // what is rendered right now
  let optNodes = [];
  let activeIndex = -1;
  let pop = null;

  const input = el('input.combo-input', {
    type: 'text',
    role: 'combobox',
    autocomplete: 'off',
    spellcheck: 'false',
    'aria-autocomplete': 'list',
    'aria-expanded': 'false',
    'aria-controls': `${id}-pop`,
    placeholder: placeholder || '',
  });
  input.disabled = Boolean(disabled);

  const clearBtn = allowEmpty
    ? el('button.combo-clear', {
      type: 'button',
      tabindex: '-1',
      /* Hidden from the accessibility tree, not merely from the tab order. It
         sits inside field()'s <label>, and an embedded control encountered
         while a label is named from its content contributes its own name — so
         "Kosongkan" was being glued onto the field's, and every one of the
         dozens of nullable lookups announced itself as "Vendor Kosongkan".
         Nothing is lost: tabindex="-1" already made it mouse-only, and the "—"
         row plus an emptied box are the routes a keyboard user has. */
      'aria-hidden': 'true',
      title: 'Kosongkan',
    }, icon('close', 12))
    : null;

  const combo = el(`.combo${compact ? '.compact' : ''}`, [input, clearBtn]);

  /* The same sentence as the popup's, kept under the field so a truncated or
     forbidden source is visible without opening anything. Suppressed in compact
     mode: fifteen identical banners stacked down a PO's item column is noise,
     and there the disabled input's placeholder already carries the state.

     It sits OUTSIDE .combo deliberately. .combo is the positioning context for
     the × (top: 50%), so a notice inside it would centre the × against
     input-plus-notice and leave it hanging below the box on a truncated source. */
  const noteId = `${id}-note`;

  /* aria-hidden for the same reason as the × — this is a whole paragraph, and
     it was being read as the NAME of the field: "Vendor Daftar Vendor lebih
     dari 10.000 baris dan dipotong — …", re-read on every visit and every time
     the user asked for the label again. It still reaches a screen reader: a
     node referenced directly by aria-describedby (set in paintNotice) is exempt
     from the hidden rule, and the popup writes the same sentence to the live
     region above. */
  const noticeText = el('span', { id: noteId, 'aria-hidden': 'true' });

  /* Built once and shown/hidden, never re-created. paintNotice() used to clear()
     the notice and build a fresh button, which destroyed the "Coba lagi" the
     user had just pressed Enter on and left document.activeElement on <body>,
     outside the modal, with nothing announced. */
  const retryBtn = button('Coba lagi', {
    size: 'sm',
    onClick: (event) => {
      // .field is a <label>; see the clear button below for why both.
      event.preventDefault();
      event.stopPropagation();
      // Read late: setOptions can swap the handler out from under a button
      // that is still on screen.
      if (onRetry) onRetry();
    },
  });
  retryBtn.hidden = true;

  const inlineNotice = el('.combo-warn', [noticeText, retryBtn]);
  inlineNotice.hidden = true;

  /* tabindex="-1" is not a tab stop — ui.js's FOCUSABLE list excludes it — it is
     the one place paintNotice() can park focus when it has to hide the button
     that held it and the input is still disabled by a reloading source. */
  const node = compact ? combo : el('.combo-field', { tabindex: '-1' }, [combo, inlineNotice]);

  /* --------------------------------------------------------------- reading */

  const isOpen = () => current === api;

  function findOption(target) {
    if (!hasValue(target)) return null;
    return list.find((option) => sameValue(option.value, target)) || null;
  }

  /* ------------------------------------------------------------- filtering */

  function applyQuery() {
    const q = query.trim().toLowerCase();
    if (!q) {
      matchIdx = null;
      return;
    }

    /* AND over whitespace-separated tokens, so "sem 40" finds
       "SEM-001 — Semen Portland Tipe I 40 kg" — optionsFor() has already built
       the label as "CODE — Name", and people type the code and a scrap of the
       name in whichever order comes to mind. */
    const tokens = q.split(/\s+/);
    const starts = [];
    const rest = [];

    for (let i = 0; i < hay.length; i++) {
      const text = hay[i];
      let ok = true;
      for (let t = 0; t < tokens.length; t++) {
        if (!text.includes(tokens[t])) { ok = false; break; }
      }
      if (ok) (text.startsWith(q) ? starts : rest).push(i);
    }

    matchIdx = starts.concat(rest);
  }

  const matchCount = () => (matchIdx ? matchIdx.length : list.length);
  const matchAt = (index) => list[matchIdx ? matchIdx[index] : index];

  function visibleEntries() {
    const out = [];
    /* Trimmed, so a box holding only the space someone hit to scroll the modal
       still counts as blank. It has to agree with refreshList()'s test below:
       if the "—" row were dropped for a blank-but-not-empty query, the "an
       emptied box means clear" branch would put the highlight on entries[0] —
       the first real vendor — and Tab would commit one nobody chose. */
    if (allowEmpty && !query.trim()) out.push(EMPTY_ENTRY);
    const cap = Math.min(matchCount(), MAX_RENDER);
    for (let i = 0; i < cap; i++) out.push(matchAt(i));
    return out;
  }

  /* --------------------------------------------------------------- drawing */

  function paint() {
    clear(pop);
    optNodes = [];

    if (notice) pop.appendChild(el('.combo-warn', { text: notice }));

    entries.forEach((entry, index) => {
      /* el({ text }) only — never innerHTML. A vendor called
         `<img onerror=…>` is master data that a clerk can type. */
      const opt = el('button.combo-opt', {
        type: 'button',
        role: 'option',
        tabindex: '-1',
        id: `${id}-opt-${index}`,
        dataset: { index: String(index) },
        text: entry.label,
      });
      if (index === activeIndex) opt.classList.add('active');
      if (entry.value !== null && sameValue(entry.value, committed)) opt.setAttribute('aria-selected', 'true');
      optNodes.push(opt);
      pop.appendChild(opt);
    });

    const total = matchCount();
    if (!total) {
      pop.appendChild(el('.combo-empty', {
        text: query.trim() ? `Tidak ada yang cocok dengan "${query.trim()}".` : 'Belum ada data.',
      }));
    } else if (total > MAX_RENDER) {
      pop.appendChild(el('.combo-more', {
        text: `Menampilkan ${MAX_RENDER} dari ${total.toLocaleString('id-ID')} — ketik untuk mempersempit.`,
      }));
    }
  }

  function refreshList() {
    applyQuery();
    entries = visibleEntries();

    if (query.trim()) {
      // Typing highlights the best match, so Enter picks what the ranking put first.
      activeIndex = entries.length ? 0 : -1;
    } else if (allowEmpty && entries[0] === EMPTY_ENTRY && input.value === '') {
      /* STRICTLY empty — not merely blank after trimming. Tabbing into a field
         select-alls its label, so the Space someone hits to scroll a tall modal
         REPLACES that label with " ". Treating that as the clear gesture put the
         highlight on "—" and Tab then committed null: the nullable reference
         vanished from a field the user never meant to touch. Whitespace falls
         through to the branch below and re-commits what was already there.

         An emptied box IS the clear gesture — see the commit rules below — so
         the highlight has to move to the "—" row. Leaving it on the row that is
         still committed is what made Tab quietly put the deleted value back:
         on #/r/inventory/warehouses the nullable "Proyek" field went visibly
         blank, Tab re-committed the old project, commit() saw no change so no
         event fired, and the unsaved-data guard never even asked before Simpan
         wrote the old project_id. It only bit sources small enough for the
         committed row to be among the 50 rendered — the same three keystrokes
         really did clear an item picker over 2 000 rows.

         entries[0] is compared, not assumed: it is the "—" row only while the
         query is blank as well, which is not guaranteed on the setOptions
         path. */
      activeIndex = 0;
    } else {
      /* At rest, highlight what is already chosen — but only if it fell inside
         the rendered window. Item #900 of 2 000 simply is not on screen, and a
         highlight that points at nothing is worse than none. */
      activeIndex = entries.findIndex((entry) => entry.value !== null && sameValue(entry.value, committed));
    }

    paint();
    syncActiveDescendant();

    /* Both of these ran only in openPopup() before, so every keystroke after it
       left the list lying about itself:

        - the highlight Enter and Tab commit sat wherever the wheel had left the
          scroll, off-screen, and the user pressed Enter on an item they had
          never seen;
        - `top` was still the one computed for a full 288px list, so a popup
          that had opened upwards (last line of a 15-line PO) or been clamped to
          the viewport drifted away from its field as the matches narrowed —
          one match left it hovering ~250px above the cell it belongs to,
          looking like it belongs to a different row. */
    reposition();
    scrollActiveIntoView();

    /* The footer and the warning are unreachable by aria-activedescendant, so
       they are spoken instead. Only when there is something to say: a normal
       50-of-50 list stays silent rather than interrupting the option the screen
       reader is already reading out. */
    const shown = matchCount();
    announce([
      /* The warning describes the SOURCE, so it goes out once, when the list
         opens at rest. Repeating a 30-word paragraph on every keystroke would
         bury the count it is there to qualify. */
      query.trim() ? '' : notice,
      shown > MAX_RENDER ? `Menampilkan ${MAX_RENDER} dari ${shown.toLocaleString('id-ID')} hasil, ketik untuk mempersempit.` : '',
      // Word for word what paint() puts on screen, so the two cannot drift.
      shown ? '' : (query.trim() ? 'Tidak ada yang cocok.' : 'Belum ada data.'),
    ].filter(Boolean).join(' '));
  }

  function paintNotice() {
    if (compact) return;

    /* Pressing "Coba lagi" hides the button that was pressed: retry() puts the
       source back to 'loading', noticeFor() then returns null and the whole
       notice goes away. Hiding the focused node drops focus on <body>, outside
       the modal — a keyboard user's next Tab is caught by ui.js and dumps them
       back at the top of the form, and a screen-reader user hears nothing at
       all and cannot tell whether the button did anything. */
    const hadFocus = inlineNotice.contains(document.activeElement);
    /* Read before anything moves: it decides whether the state change gets
       spoken, and only for the person actually standing in this field. */
    const inField = node.contains(document.activeElement);

    noticeText.textContent = notice || '';
    retryBtn.hidden = !(notice && onRetry);
    inlineNotice.hidden = !notice;

    /* The sentence is aria-hidden so it stays out of the field's name; this is
       what makes it readable again, and it is announced on focus instead of
       being fused into the label. */
    if (notice) input.setAttribute('aria-describedby', noteId);
    else input.removeAttribute('aria-describedby');

    /* "Coba lagi" reaches this twice: once for the press, which puts the source
       back to 'loading' and says "Memuat…", and once when the request lands,
       which says the new failure sentence or that the field is usable. Both
       were silent before, so a screen-reader user could not tell whether the
       button had done anything — and the press had just taken their focus with
       it. The placeholder is form.js's own wording for every unusable state
       ('Memuat…', 'Gagal memuat', 'Tidak ada hak akses', 'Belum ada data'), so
       there is nothing to keep in step here. */
    if (inField) {
      announce(notice || (unusable() ? input.placeholder : 'Daftar siap, ketik untuk mencari.'));
    }

    if (!hadFocus) return;

    /* The button if it survived this repaint, otherwise the wrapper — not the
       input, which is exactly the node that is NOT available here: a retry puts
       the source back to 'loading', so form.js hands us disabled: true and
       setOptions has already applied it. The wrapper is the one node in the
       field that cannot vanish or turn disabled, and Tab carries on from it
       into the input if the source came back usable, or past the field if it
       did not — instead of ui.js's trap hauling the user to the top of the
       form. */
    (retryBtn.hidden ? node : retryBtn).focus({ preventScroll: true });
  }

  /* ------------------------------------------------------------ positioning */

  /*
   * position: fixed, parented to <body>. Not decoration — an absolutely
   * positioned popup is clipped by .modal-body's overflow-y and, for the eleven
   * line-table pickers, again by .table-wrap's overflow-x. Both are clipping
   * contexts, so the list would be cut off exactly where it matters most.
   */
  function reposition() {
    if (!pop || !pop.isConnected) return;

    const rect = input.getBoundingClientRect();
    const vw = document.documentElement.clientWidth;
    const vh = document.documentElement.clientHeight;

    // 280px minimum so a full "SEM-001 — Semen Portland Tipe I 40 kg" is
    // readable even inside a 24%-wide item column on a PO line.
    const width = Math.min(Math.max(rect.width, 280), vw - 16);
    pop.style.width = `${width}px`;

    const height = pop.offsetHeight;
    const below = vh - rect.bottom;
    const flip = below < MIN_SPACE_BELOW && rect.top > below;

    let top = flip ? rect.top - height - 4 : rect.bottom + 4;
    top = Math.max(8, Math.min(top, vh - height - 8));

    let left = rect.left;
    if (left + width > vw - 8) left = vw - 8 - width;
    left = Math.max(8, left);

    pop.style.top = `${top}px`;
    pop.style.left = `${left}px`;
  }

  /* Scrolling INSIDE the list does not move the list. Without this guard every
     wheel tick over a 50-row popup forces a layout flush to reposition it to
     where it already is. */
  function onScroll(event) {
    if (pop && event.target === pop) return;
    reposition();
  }

  /* ------------------------------------------------------------ open/close */

  function openPopup() {
    if (unusable() || isOpen()) return;
    closeCurrent();

    if (!pop) {
      pop = el('.combo-pop', { id: `${id}-pop`, role: 'listbox' });

      /*
       * mousedown, not click. Without preventDefault the input blurs first, blur
       * resets the text and tears the popup down, and the click then lands on
       * nothing — the single most common way a hand-rolled picker eats a
       * selection. A keyboard-only test passes with this bug fully present.
       */
      pop.addEventListener('mousedown', (event) => event.preventDefault());

      // One delegated listener for up to 50 rows, rebuilt on every keystroke.
      pop.addEventListener('click', (event) => {
        const opt = event.target instanceof Element ? event.target.closest('.combo-opt') : null;
        if (opt) choose(Number(opt.dataset.index));
      });
    }

    document.body.appendChild(pop);
    current = api;

    // refreshList() positions the list and scrolls the highlight into view on
    // every repaint, this first one included.
    refreshList();
    input.setAttribute('aria-expanded', 'true');

    // Capture: the element that actually scrolls is .modal-body, and its scroll
    // event never reaches window in the bubble phase.
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', reposition);
  }

  function closePopup() {
    const wasOpen = current === api;
    if (wasOpen) current = null;

    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', reposition);

    if (pop && pop.isConnected) pop.remove();

    query = '';
    matchIdx = null;
    entries = [];
    optNodes = [];
    activeIndex = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');

    /* Emptied, not left holding the last sentence: announce() only speaks text
       that changed, so a stale "Menampilkan 50 dari 2.000" would keep the very
       next opening of the same truncated picker silent. Only the list that was
       really open clears it — blur() runs this on every field, open or not, and
       it must not wipe what another one just said. */
    if (wasOpen) announce('');
  }

  /* -------------------------------------------------------------- movement */

  function syncActiveDescendant() {
    const active = optNodes[activeIndex];
    if (active) input.setAttribute('aria-activedescendant', active.id);
    else input.removeAttribute('aria-activedescendant');
  }

  function scrollActiveIntoView() {
    const active = optNodes[activeIndex];
    if (active) active.scrollIntoView({ block: 'nearest' });
  }

  function setActive(index) {
    if (!entries.length) return;
    const next = Math.max(0, Math.min(index, entries.length - 1));

    const previous = optNodes[activeIndex];
    if (previous) previous.classList.remove('active');

    activeIndex = next;
    const node2 = optNodes[activeIndex];
    if (node2) node2.classList.add('active');

    syncActiveDescendant();
    scrollActiveIntoView();
  }

  function move(delta) {
    if (!entries.length) return;
    // Wraps. With three rows left after filtering, stopping dead at the end is
    // the surprising behaviour, not wrapping.
    const next = activeIndex < 0
      ? (delta > 0 ? 0 : entries.length - 1)
      : (activeIndex + delta + entries.length) % entries.length;
    setActive(next);
  }

  /* ------------------------------------------------------------ committing */

  /*
   * THE COMMIT RULES. Three routes out of this field, and they have to agree —
   * this package exists because a form used to lose a user's work silently, so
   * a picker with its own silent-loss path would be worse than no picker.
   *
   *  - `committed` IS the value. read() returns it and nothing else; it is never
   *    re-derived from the loaded rows, and only commit() moves it. That is what
   *    lets an id with no row left in the source survive an edit.
   *
   *  - While the popup is OPEN the HIGHLIGHT is authoritative: Enter and Tab
   *    commit entries[activeIndex], and the text in the box is a query, not a
   *    value. refreshList() therefore has to keep the highlight honest — it is
   *    a promise about what the next Enter will do.
   *
   *  - While the popup is CLOSED the TEXT is authoritative: settle() runs on the
   *    way out and either finds a full, exact label or snaps the box back to
   *    committedLabel. A unique substring never commits.
   *
   *  - An EMPTY box on the way out means "clear" on a nullable field: the ×, the
   *    "—" row and select-all-then-delete are one gesture with one outcome,
   *    null. On a REQUIRED field it means nothing — there is no empty value to
   *    hold, so the text snaps back to what is still committed.
   *
   * The corollary, and the reason the focus listener that used to sit at the top
   * of the events block is gone: nothing in here may select the whole box on
   * behalf of the user. ui.js auto-focuses the first control 30ms after a modal
   * opens, which on finance/ap-bills is the nullable `vendor_id`; with the label
   * pre-selected, one Space to scroll the modal or one Backspace reflex replaced
   * it, and clicking away then committed null into an approved vendor bill.
   * Selecting on real mouse focus (the click handler) is a user's own gesture
   * and stays — as does the browser's own select-all when you Tab in, which is
   * what makes typing over a value work at all.
   */

  /* One name for "this control cannot be used": disabled (nothing to pick) or
     readOnly (the source 403'd or failed, so the field stays focusable to say
     why). Every gate below has to agree, or one of them stays open. */
  function unusable() {
    return input.disabled || input.readOnly;
  }

  function syncClear() {
    /* readOnly, not just disabled: form.js marks a 403'd or failed source
       readOnly so the field stays reachable by keyboard and can explain itself.
       Without this test the × stayed live on exactly those fields, and one click
       destroyed a foreign key the user had no way to pick again. */
    const show = Boolean(clearBtn) && !unusable() && hasValue(committed);
    if (clearBtn) clearBtn.hidden = !show;
    // Only reserve the extra 50px of padding when the × is really there: dead
    // space inside a 24%-wide item column is not free.
    combo.classList.toggle('clearable', show);
  }

  function syncText() {
    input.value = committedLabel;
    syncClear();
  }

  function commit(next, text) {
    const normalized = hasValue(next) ? next : null;
    const changed = String(normalized ?? '') !== String(committed ?? '');

    committed = normalized;
    if (normalized === null) committedLabel = '';
    else committedLabel = text || (findOption(normalized) || {}).label || committedLabel || String(normalized);

    syncText();

    /*
     * The only event this control fires, and only when the value really moved.
     * buildLines refreshes a PO's subtotal from `change` on control.input, and
     * the row picks the same event up by bubbling. Deliberately NOT `input`:
     * typing already floods that, and giving it a second meaning would make it
     * useless to everyone else.
     */
    if (changed) input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function choose(index) {
    const entry = entries[index];
    if (!entry) return;
    closePopup();
    commit(entry.value, entry.value === null ? '' : entry.label);
    input.focus({ preventScroll: true });
  }

  /*
   * The invariant: the visible text can never disagree with the stored value.
   * Whatever is in the box on the way out is either an exact label — which
   * commits — or it snaps back to what is actually held.
   */
  function settle() {
    const typed = input.value.trim();

    /* Text nobody touched means a value nobody touched. Without this, two rows
       sharing a label — two "PT Abadi" vendors, neither with a code — would let
       a plain tab-through swap the stored id for whichever one matches the text
       first, on a form the user only looked at. */
    if (typed === committedLabel.trim()) {
      syncText();
      return;
    }

    if (typed === '') {
      /* Select-all-and-delete is the third way to clear a nullable field — but
         only when the box is STRICTLY empty. A box holding " " trims to the same
         empty string while meaning the opposite: Tab-in select-alls the label and
         one Space overwrites it, so clearing on trimmed-blank threw away a
         reference nobody touched. Whitespace snaps back like any other text that
         matches no row. On a required field there is nothing to fall back to
         either way. */
      if (allowEmpty && input.value === '') commit(null);
      else syncText();
      return;
    }

    /*
     * Only a FULL, exact label commits. A unique substring deliberately does
     * not: in a 2 000-item catalogue "5" can be the sole substring match by pure
     * accident, and this ends up on a purchase order. You only ever get a value
     * you could see selected.
     */
    const hit = hay.indexOf(typed.toLowerCase());
    if (hit >= 0) commit(list[hit].value, list[hit].label);
    else syncText();
  }

  /* ---------------------------------------------------------------- events */

  input.addEventListener('click', () => {
    if (isOpen()) return;
    // Clicking opens but never toggles shut: with the text select-all'd, a
    // second click is far more often "put the caret here" than "close this".
    input.select();
    openPopup();
  });

  input.addEventListener('input', () => {
    query = input.value;
    if (isOpen()) refreshList();
    else openPopup();
  });

  input.addEventListener('blur', () => {
    settle();
    closePopup();
  });

  input.addEventListener('keydown', (event) => {
    if (input.disabled) return;
    const open = isOpen();

    switch (event.key) {
      case 'ArrowDown':
      case 'Down':
        event.preventDefault();
        if (open) move(1);
        else openPopup();
        return;

      case 'ArrowUp':
      case 'Up':
        event.preventDefault();
        if (open) move(-1);
        else openPopup();
        return;

      case 'Home':
        if (!open) return;
        event.preventDefault();
        setActive(0);
        return;

      case 'End':
        if (!open) return;
        event.preventDefault();
        setActive(entries.length - 1);
        return;

      case 'PageDown':
        if (!open) return;
        event.preventDefault();
        setActive(Math.max(0, activeIndex) + 10);
        return;

      case 'PageUp':
        if (!open) return;
        event.preventDefault();
        setActive(Math.max(0, activeIndex) - 10);
        return;

      case 'Enter':
        // There is no <form> element anywhere in this SPA — `save` is
        // type="submit" with no form ancestor — so Enter has never submitted and
        // must not start now.
        event.preventDefault();
        if (!open) return;
        if (activeIndex >= 0) choose(activeIndex);
        else { settle(); closePopup(); }
        return;

      case 'Tab':
        // No preventDefault, on purpose: "type SEM, Tab, type qty, Tab" is the
        // whole point on a 15-line PO.
        if (!open) return;
        if (activeIndex >= 0) choose(activeIndex);
        else { settle(); closePopup(); }
        return;

      case 'Escape':
      case 'Esc':
        if (!open) return; // popup already shut — let it reach the modal
        /*
         * ui.js listens for Escape on document in the BUBBLE phase precisely so
         * this can win: the first Escape closes only the list, the second one
         * reaches the form and gets the unsaved-data guard. If that listener
         * were ever "improved" to capture, one Escape would throw away the whole
         * purchase order instead.
         */
        event.preventDefault();
        event.stopPropagation();
        syncText();
        closePopup();
        return;

      // Every other key — printable characters included — is left to the
      // browser. Typing lands in the `input` handler above, which is what opens
      // the list and starts filtering.
      default:
    }
  });

  if (clearBtn) {
    /*
     * field() in ui.js returns a <label>, so any click inside it is forwarded to
     * the first labelable control — this very input. Without both of these,
     * clearing the field instantly reopens the popup.
     */
    clearBtn.addEventListener('mousedown', (event) => { event.preventDefault(); event.stopPropagation(); });
    clearBtn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closePopup();
      commit(null);
      input.focus({ preventScroll: true });
    });
  }

  /* ------------------------------------------------------------------- api */

  /** Hand over late-arriving rows without rebuilding the control. */
  function setOptions(next, opts = {}) {
    list = Array.isArray(next) ? next : [];
    hay = list.map((option) => String(option.label ?? '').trim().toLowerCase());

    if (opts.placeholder !== undefined) input.placeholder = opts.placeholder || '';
    if (opts.disabled !== undefined) input.disabled = Boolean(opts.disabled);
    if (opts.notice !== undefined || opts.onRetry !== undefined) {
      if (opts.notice !== undefined) notice = opts.notice;
      if (opts.onRetry !== undefined) onRetry = opts.onRetry;
      paintNotice();
    }

    if (hasValue(committed)) {
      const found = findOption(committed);
      if (found) committedLabel = found.label;
      else if (opts.label !== undefined) committedLabel = opts.label;
      // else keep what we have — the caller's "#4821 (tidak ada di daftar)".
    }

    // Never stomp on what somebody is in the middle of typing.
    if (document.activeElement === input) syncClear();
    else syncText();

    if (isOpen()) refreshList();
  }

  const api = {
    node,
    input,
    /* Returns the COMMITTED value, never anything derived from `options`. The
       unsaved-data guard in openForm compares read() at open against read() at
       close, so a value that changed by itself when the rows arrived would make
       every edit form open already dirty and the guard a liar. */
    read: () => committed,
    /** The selected source row, for a future "picking an item fills its price". */
    row: () => (findOption(committed) || {}).row || null,
    setOptions,
    // Internal, for the module-level singleton and the outside-click guard.
    closePopup,
    owns: (target) => Boolean(
      target instanceof Node && (node.contains(target) || (pop && pop.contains(target))),
    ),
  };

  paintNotice();
  setOptions(options);

  return api;
}
