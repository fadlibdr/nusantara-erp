/* Schema-driven create/edit form, including repeatable line-item tables. */

import { api } from '../api.js';
import { el, clear, button, badge, icon, modal, field, setFieldError, toast, toastError, withBusy, confirmDialog } from '../ui.js';
import { renderCell } from '../cells.js';
import { ENUMS } from '../enums.js';
import { loadSource, optionsFor, preload, invalidateByPath, invalidate, sourceState, noticeFor } from '../lookup.js';
import { combobox } from '../combobox.js';
import { moneyInput } from '../money.js';
import { MONTHS, rupiah, toDateInput, toDateTimeInput, today, date as fmtDate } from '../format.js';
import { navigate } from '../router.js';
import { saveDraft, loadDraft, removeDraft, registerDraftFlush, relativeAge, draftRemovalSuspended } from '../drafts.js';

/* ------------------------------------------------------ lookup field glue */

/** The option list a lookup field renders; `valueKey` submits a column, not the id. */
function lookupOptions(spec, rows) {
  const options = optionsFor(spec.lookup, rows);
  return spec.valueKey
    ? options.map((option) => ({ ...option, value: option.row[spec.valueKey] }))
    : options;
}

/* A source that answered 403, blew up, or is genuinely empty gives nothing to
   pick from — the field says which of the three it is instead of sitting there
   looking like a complete but unlucky list. */
const lookupUsable = (state) => state.status === 'ok' && state.rows.length > 0;

function lookupPlaceholder(spec, state) {
  if (state.status === 'idle' || state.status === 'loading') return 'Memuat…';
  if (state.status === 'forbidden') return 'Tidak ada hak akses';
  if (state.status === 'failed') return 'Gagal memuat';
  if (!state.rows.length) return 'Belum ada data';
  return spec.required ? 'Pilih…' : '—';
}

/**
 * What the box shows for a value that is not in the option list.
 *
 * This is the visible half of the bug the combobox exists to kill: a <select>
 * handed a value with no matching <option> resets itself to '', read() returns
 * null, and openForm writes that null into the PUT. subcontractors is filtered
 * is_subcontractor:1 and postableAccounts is_postable:1, so unflagging a vendor
 * and then editing its subcontract silently dropped the vendor on save. The id
 * is now kept whatever the list says, and shown rather than hidden.
 */
function lookupLabel(value, options, state) {
  if (value === null || value === undefined || value === '') return '';

  const found = options.find((option) => String(option.value) === String(value));
  if (found) return found.label;

  // Nothing is proven while the rows are still coming.
  if (state.status === 'idle' || state.status === 'loading') return '';
  // Only a successful load proves the row is really gone from the source.
  return state.status === 'ok' ? `#${value} (tidak ada di daftar)` : `#${value}`;
}

let dateHintSeq = 0;

/**
 * Build one input for a field descriptor; returns { node, read, write }.
 * `compact` is passed only by buildLines — a line-table cell, not a form field.
 */
export function buildInput(spec, initial, { compact = false } = {}) {
  const value = initial === undefined ? spec.default : initial;

  switch (spec.type) {
    /*
     * A value the row must carry but nobody should type. po_item_id is the
     * reason it exists: the three-way match lives or dies on the receipt line
     * knowing which PO line it satisfies, and that id is meaningless to a
     * warehouse clerk. Filled by "salin baris dari PO", never by hand.
     */
    case 'hidden': {
      const node = el('input', { type: 'hidden' });
      node.value = value ?? '';
      return { node, read: () => (node.value === '' ? null : Number(node.value)) };
    }

    case 'textarea': {
      const node = el('textarea', { rows: spec.rows || 3 });
      node.value = value ?? '';
      return { node, read: () => (node.value.trim() === '' ? null : node.value) };
    }

    case 'bool': {
      const node = el('input', { type: 'checkbox' });
      node.checked = Boolean(value);
      return { node: el('.check-row', [node, el('label', { text: spec.checkboxLabel || spec.label })]), input: node, read: () => node.checked };
    }

    /*
     * Rupiah lewat money.js, bukan <input type=number> polos: 15000000000 dan
     * 1500000000 tak terbedakan mata, dan scroll-wheel bisa menggeser angkanya
     * diam-diam. Ke-87 field currency di schema.js ikut tanpa satu pun edit
     * skema; percent/qty/number tetap natif — bahaya miliaran itu khas currency.
     * read() memulangkan Number yang sama dengan input lama, jadi snapshot
     * dirty-check openForm tidak berubah makna.
     */
    case 'currency': {
      return moneyInput({ value });
    }

    case 'percent': {
      const node = el('input', { type: 'number', step: '0.01', min: spec.min ?? 0, max: spec.max ?? 100, inputmode: 'decimal' });
      node.value = value ?? '';
      const wrap = el('.input-affix.suffix', [el('span', { text: '%' }), node]);
      return { node: wrap, input: node, read: () => (node.value === '' ? null : Number(node.value)) };
    }

    case 'qty': {
      const node = el('input', { type: 'number', step: '0.001', min: spec.min ?? 0, inputmode: 'decimal' });
      node.value = value ?? '';
      return { node, read: () => (node.value === '' ? null : Number(node.value)) };
    }

    case 'number': {
      const node = el('input', { type: 'number', step: spec.step || '1', min: spec.min, max: spec.max });
      node.value = value ?? '';
      return { node, read: () => (node.value === '' ? null : Number(node.value)) };
    }

    /*
     * Petunjuk tanggal id-ID di bawah <input type=date>. Input natif
     * menggambar mm/dd/yyyy pada Chromium en-US — terlihat 2 Sep 2026
     * (HASIL-UJI §1 "Tanggal native"), dan urutan itu milik locale OS
     * pemakai, bukan aplikasi: 09/04/2026 terbaca 9 April oleh siapa pun yang
     * terbiasa dd/mm. Inputnya dipertahankan (pemilih tanggal, keyboard,
     * validasi format gratis); yang ditambah hanya pembacaan ulang
     * "= 04 Sep 2026" lewat fmt.date — format yang sama dengan kolom tanggal
     * di daftar dan detail, jadi angka yang diketik dan angka yang nanti
     * tampil tidak pernah berbeda urutan. Kosong bila belum ada nilai:
     * Chromium memancarkan 'input' per segmen dengan value '' selama tanggal
     * belum lengkap, jadi tidak ada tanggal setengah jadi yang dibaca.
     * Sel tabel baris (compact) tidak memuatnya — seperti hint money.js,
     * <td> 31px tidak punya tempat untuk baris kedua. aria-hidden +
     * aria-describedby juga mengikuti money.js: teks polos di dalam <label>
     * dilebur jadi NAMA field oleh screen reader.
     */
    case 'date': {
      const node = el('input', { type: 'date' });
      node.value = value ? toDateInput(value) : (spec.defaultToday ? today() : '');
      const read = () => node.value || null;
      if (compact) return { node, read };

      const help = el('.help.date-hint', { id: `date-hint-${++dateHintSeq}`, 'aria-hidden': 'true' });
      const syncHelp = () => {
        const text = node.value ? `= ${fmtDate(node.value)}` : '';
        help.textContent = text;
        help.hidden = text === '';
        if (text) node.setAttribute('aria-describedby', help.id);
        else node.removeAttribute('aria-describedby');
      };
      node.addEventListener('input', syncHelp);
      node.addEventListener('change', syncHelp);
      syncHelp();
      return { node: el('div', [node, help]), input: node, read };
    }

    case 'datetime': {
      const node = el('input', { type: 'datetime-local' });
      node.value = value ? toDateTimeInput(value) : '';
      return { node, read: () => (node.value ? node.value.replace('T', ' ') : null) };
    }

    /*
     * 'HH:MM' apa adanya — jam kerja laporan harian (P0-A). Resource sudah
     * menormalkan ke 'HH:MM', tetapi slice tetap dipasang: kolom TIME MySQL
     * yang sampai lewat jalur lain membawa detik ('08:00:00'), dan <input
     * type=time> menolak nilai itu DIAM-DIAM — kotak kosong di atas data
     * yang ada. read() memulangkan 'HH:MM', persis date_format:H:i server.
     */
    case 'time': {
      const node = el('input', { type: 'time' });
      node.value = value ? String(value).slice(0, 5) : '';
      return { node, read: () => node.value || null };
    }

    case 'password': {
      const node = el('input', { type: 'password', autocomplete: 'new-password' });
      return { node, read: () => (node.value === '' ? null : node.value) };
    }

    case 'select': {
      const node = el('select');
      node.appendChild(el('option', { value: '', text: spec.required ? 'Pilih…' : '—' }));
      const options = spec.options === 'months'
        ? MONTHS.map((label, index) => ({ value: index + 1, label }))
        : (spec.enum ? ENUMS[spec.enum] : spec.options) || [];
      options.forEach((option) => node.appendChild(el('option', { value: option.value, text: option.label })));
      node.value = value ?? '';
      return { node, read: () => (node.value === '' ? null : node.value) };
    }

    /*
     * One combobox behind every reference field in the app. The old control was
     * a <select> holding the entire source: reaching "Semen Portland Tipe I
     * 40 kg" meant scrolling two thousand rows, on every line, on every PO.
     */
    case 'lookup': {
      const state = sourceState(spec.lookup);
      const options = lookupOptions(spec, state.rows);

      const combo = combobox({
        value: value === undefined || value === '' ? null : value,
        label: lookupLabel(value, options, state),
        options,
        placeholder: lookupPlaceholder(spec, state),
        allowEmpty: !spec.required,
        notice: noticeFor(state),
        compact,
        onRetry: state.status === 'failed' ? () => retry() : null,
      });

      /*
       * Unusable, but NOT `disabled`. A natively disabled input is not in the
       * tab order at all: with the API down, a keyboard user on
       * procurement/purchase-orders → Tambah tabbed from "Tanggal PO" straight
       * to "Catatan", never met the required Vendor field, and then got a 422
       * vendor_id back about a control they could neither reach nor hear — the
       * sentence explaining why sits in that same unreachable field. Anyone
       * missing pur.view got exactly the same silence. readOnly keeps the field
       * tabbable and announced (the placeholder says "Gagal memuat" / "Tidak ada
       * hak akses", and the popup still opens to show the full reason) while
       * refusing every keystroke just as before.
       *
       * The three declarations are `.combo .combo-input:disabled` (app.css:917),
       * repeated here because the field deliberately no longer matches it — a
       * broken picker has to keep looking broken, especially in a 24%-wide item
       * column where the placeholder is truncated.
       */
      const markUsable = (usable) => {
        combo.input.readOnly = !usable;
        combo.input.setAttribute('aria-disabled', String(!usable));
        combo.input.style.backgroundColor = usable ? '' : 'var(--surface-3)';
        combo.input.style.color = usable ? '' : 'var(--muted)';
        combo.input.style.cursor = usable ? '' : 'not-allowed';
      };

      markUsable(lookupUsable(state));

      const refresh = () => {
        const next = sourceState(spec.lookup);
        const nextOptions = lookupOptions(spec, next.rows);
        combo.setOptions(nextOptions, {
          label: lookupLabel(value, nextOptions, next),
          placeholder: lookupPlaceholder(spec, next),
          notice: noticeFor(next),
          onRetry: next.status === 'failed' ? () => retry() : null,
        });
        markUsable(lookupUsable(next));
      };

      const retry = () => {
        invalidate(spec.lookup); // status too, or a granted permission stays denied all session
        refresh();               // straight back to "Memuat…" so the click is felt
        loadSource(spec.lookup).then(refresh, refresh);
      };

      /*
       * openForm and promptFields both await preload() before the first
       * buildInput, so every real screen takes the synchronous path above. This
       * is for a direct caller, and for a source nobody warmed.
       *
       * 'failed' belongs in that list. The <select> this replaced re-fetched on
       * every build, so one 502 from the proxy during preload healed itself on
       * the next render; gating on idle/loading alone left all 15 item cells of
       * a purchase order dead for the life of the modal, showing a truncated
       * "Gagal memuat" — and a compact cell renders no "Coba lagi" to press, so
       * the only cure was closing the form and losing the header edits with it.
       * loadSource's inflight map collapses those 15 calls into one request,
       * which is exactly what the old control did.
       */
      if (state.status === 'idle' || state.status === 'loading' || state.status === 'failed') {
        loadSource(spec.lookup).then(refresh, refresh);
        // loadSource flips the source to 'loading' synchronously, so this second
        // pass repaints "Gagal memuat" into "Memuat…" instead of leaving the
        // clerk staring at last attempt's failure while a new one is in flight.
        refresh();
      }

      return {
        node: combo.node,
        input: combo.input,
        read: () => {
          const picked = combo.read();
          // Character for character the rule the <select> used, so all 94 fields
          // put the same foreign key type in the payload as before.
          return picked === null || picked === '' ? null : (spec.valueKey ? picked : Number(picked));
        },
      };
    }

    case 'multiselect': {
      const box = el('div', {
        style: {
          border: '1px solid var(--border-strong)', borderRadius: 'var(--radius-sm)',
          padding: '8px 10px', maxHeight: '190px', overflowY: 'auto',
          display: 'flex', flexWrap: 'wrap', gap: '4px 14px',
        },
      });
      const selected = new Set((Array.isArray(value) ? value : []).map(String));
      loadSource(spec.lookup).then((rows) => {
        clear(box);
        optionsFor(spec.lookup, rows).forEach((option) => {
          const key = String(spec.valueKey ? option.row[spec.valueKey] : option.value);
          const checkbox = el('input', { type: 'checkbox' });
          checkbox.checked = selected.has(key);
          checkbox.addEventListener('change', () => {
            if (checkbox.checked) selected.add(key);
            else selected.delete(key);
          });
          box.appendChild(el('label', {
            style: { display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', cursor: 'pointer' },
          }, [checkbox, option.label]));
        });
      });
      return { node: box, read: () => [...selected] };
    }

    case 'tags': {
      const node = el('textarea', { rows: 4, placeholder: 'Satu poin per baris' });
      node.value = Array.isArray(value) ? value.join('\n') : (value ?? '');
      return {
        node,
        read: () => {
          const list = node.value.split('\n').map((line) => line.trim()).filter(Boolean);
          return list.length ? list : null;
        },
      };
    }

    case 'json': {
      const rows = el('div', { style: { display: 'grid', gap: '6px' } });
      const addRow = (name = '', amount = '') => {
        const nameInput = el('input', { type: 'text', placeholder: 'nama tunjangan', value: name });
        const amountInput = el('input', { type: 'number', step: '0.01', placeholder: '0', value: amount });
        const row = el('div', { style: { display: 'grid', gridTemplateColumns: '1fr 1fr auto', gap: '6px' } }, [
          nameInput, amountInput,
          button('', { size: 'sm', variant: 'ghost', iconName: 'close', title: 'Hapus', onClick: () => row.remove() }),
        ]);
        row._read = () => (nameInput.value.trim() ? [nameInput.value.trim(), Number(amountInput.value || 0)] : null);
        rows.appendChild(row);
      };
      Object.entries(value || {}).forEach(([name, amount]) => addRow(name, amount));
      if (!Object.keys(value || {}).length) addRow();
      const node = el('div', [rows, button('Tambah baris', { size: 'sm', iconName: 'plus', onClick: () => addRow() })]);
      return {
        node,
        read: () => {
          const result = {};
          [...rows.children].forEach((row) => {
            const pair = row._read();
            if (pair) result[pair[0]] = pair[1];
          });
          return Object.keys(result).length ? result : null;
        },
      };
    }

    default: {
      const node = el('input', { type: 'text', maxlength: spec.maxlength });
      node.value = value ?? '';
      // A value the row carries for context but nobody edits — the butir text
      // and its acceptance on a QCI result line belong to the template, not the
      // inspector, so they are shown but locked (out of the tab order too).
      // read() still returns the value; the server drops unvalidated keys.
      if (spec.readonly) { node.readOnly = true; node.tabIndex = -1; }
      return { node, read: () => (node.value.trim() === '' ? null : node.value.trim()) };
    }
  }
}

/** Repeatable line-item table bound to one `lines` descriptor.
    `record` is the full record on edit (null on create) — importPick needs
    its id to ask the server for candidate rows. */
function buildLines(lineDef, initialRows, headerValues, record) {
  const controls = [];
  const body = el('tbody');

  const hasTotal = typeof lineDef.total === 'function';
  const hasBalance = Boolean(lineDef.balance);

  const totalsRow = el('div.lines-total', {
    style: { display: 'flex', justifyContent: 'flex-end', gap: '18px', padding: '10px 4px 0', fontSize: '13px', fontWeight: '600' },
  });

  function refreshTotals() {
    clear(totalsRow);
    if (hasTotal) {
      const sum = controls.reduce((acc, row) => acc + (Number(lineDef.total(row.read())) || 0), 0);
      totalsRow.appendChild(el('span', [el('span.muted', { text: 'Subtotal: ' }), el('span.num', { text: rupiah(sum) })]));
    }
    if (hasBalance) {
      const debit = controls.reduce((acc, row) => acc + (Number(row.read()[lineDef.balance.debit]) || 0), 0);
      const credit = controls.reduce((acc, row) => acc + (Number(row.read()[lineDef.balance.credit]) || 0), 0);
      const balanced = Math.abs(debit - credit) <= 0.01 && debit > 0;
      totalsRow.append(
        el('span', [el('span.muted', { text: 'Debit: ' }), el('span.num', { text: rupiah(debit) })]),
        el('span', [el('span.muted', { text: 'Kredit: ' }), el('span.num', { text: rupiah(credit) })]),
        el('span', {
          text: balanced ? 'Seimbang ✓' : `Selisih ${rupiah(Math.abs(debit - credit))}`,
          style: { color: balanced ? 'var(--success)' : 'var(--danger)' },
        }),
      );
    }
  }

  function addRow(data = {}) {
    const inputs = {};
    const hiddenNodes = [];
    const cells = [];

    for (const column of lineDef.columns) {
      const control = buildInput(column, data[column.key], { compact: true });
      inputs[column.key] = control;

      // A hidden column rides inside the first cell: it holds a value, it is
      // not a column the clerk sees or tabs through.
      if (column.type === 'hidden') {
        hiddenNodes.push(control.node);
        continue;
      }

      (control.input || control.node).addEventListener('input', refreshTotals);
      (control.input || control.node).addEventListener('change', refreshTotals);
      cells.push(el('td', { style: column.width ? { width: column.width } : {} }, control.node));
    }

    if (hiddenNodes.length && cells.length) cells[0].append(...hiddenNodes.splice(0));

    const row = el('tr', [
      ...cells,
      hasTotal ? el('td.right.row-total', { text: '—' }) : null,
      el('td.right', button('', {
        size: 'sm', variant: 'ghost', iconName: 'trash', title: 'Hapus baris',
        onClick: () => {
          const index = controls.indexOf(entry);
          if (index >= 0) controls.splice(index, 1);
          row.remove();
          refreshTotals();
        },
      })),
    ]);

    const entry = {
      read: () => Object.fromEntries(Object.entries(inputs).map(([key, control]) => [key, control.read()])),
      row,
      inputs,
    };

    if (hasTotal) {
      const cell = row.querySelector('.row-total');
      const update = () => { cell.textContent = rupiah(lineDef.total(entry.read())); };
      row.addEventListener('input', update);
      row.addEventListener('change', update);
      update();
    }

    controls.push(entry);
    body.appendChild(row);
    refreshTotals();
  }

  (initialRows && initialRows.length ? initialRows : Array.from({ length: lineDef.min || 1 })).forEach((row) => addRow(row || {}));

  function setRows(rows) {
    controls.length = 0;
    clear(body);
    (rows.length ? rows : [{}]).forEach((row) => addRow(row));
  }

  /*
   * Fill the lines from another document — "salin baris dari PO" on a goods
   * receipt. The point is not typing convenience: it is the only way po_item_id
   * ever reaches the server, and without it PoService::registerReceipt never
   * runs, qty_received stays zero forever, and every final bill on a goods PO is
   * refused until somebody closes the PO by hand.
   */
  const prefill = lineDef.prefill
    ? button(lineDef.prefill.label, {
      size: 'sm', variant: 'ghost', iconName: 'download',
      onClick: async (event) => {
        const sourceId = headerValues ? headerValues()[lineDef.prefill.sourceField] : null;

        if (!sourceId) {
          toast(lineDef.prefill.missingSource);
          return;
        }

        try {
          await withBusy(event.currentTarget, async () => {
            const rows = await lineDef.prefill.load(sourceId, api);

            if (!rows.length) {
              toast(lineDef.prefill.emptyMessage || 'Tidak ada baris yang bisa disalin.');
              return;
            }

            setRows(rows);
            toast(`${rows.length} baris disalin.`);
          });
        } catch (error) {
          toastError(error);
        }
      },
    })
    : null;

  /*
   * Impor-pilih (P0-A — tabel MATERIAL MASUK laporan harian): kebalikan sadar
   * dari prefill di atas. prefill MENGGANTI seluruh baris tanpa bertanya —
   * pas untuk baris PO yang memang satu-satunya isi sebuah GRN. Impor GRN
   * tidak boleh begitu: kandidatnya SEMUA penerimaan gudang site pada tanggal
   * laporan, dan pengawas MEMILIH mana yang benar-benar tiba di lapangan —
   * aturan paketnya tegas "bukan otomatis". Baris terpilih DITAMBAHKAN;
   * baris yang sudah diketik tangan tidak tersentuh.
   */
  const importPick = lineDef.importPick
    ? button(lineDef.importPick.label, {
      size: 'sm', variant: 'ghost', iconName: 'download',
      onClick: async (event) => {
        const spec = lineDef.importPick;

        // Endpoint kandidat hidup di /{id}/… — sebelum tersimpan tidak ada id,
        // dan proyek+tanggal yang dibaca server adalah yang TERSIMPAN.
        if (!record || !record.id) {
          toast(spec.requiresRecord);
          return;
        }

        let candidates;
        try {
          candidates = await withBusy(event.currentTarget, () => spec.load(record, api));
        } catch (error) {
          toastError(error);
          return;
        }

        if (!candidates || !candidates.length) {
          toast(spec.empty);
          return;
        }

        const picked = await pickRowsDialog({
          title: spec.title,
          rows: candidates,
          columns: spec.columns,
          note: spec.note,
          hint: spec.hint,
        });
        if (!picked || !picked.length) return;

        picked.forEach((candidate) => addRow(spec.map ? spec.map(candidate) : candidate));
        toast(`${picked.length} baris ditambahkan.`);
      },
    })
    : null;

  const node = el('.form-section', [
    el('.lines-head', [
      el('h3', { text: lineDef.label }),
      el('.spacer'),
      prefill,
      importPick,
      button('Tambah baris', { size: 'sm', iconName: 'plus', onClick: () => addRow() }),
    ]),
    lineDef.help ? el('.help', { text: lineDef.help, style: { marginBottom: '8px' } }) : null,
    el('.table-wrap', el('table.lines', [
      el('thead', el('tr', [
        ...lineDef.columns.filter((column) => column.type !== 'hidden').map((column) => el('th', { text: column.label })),
        hasTotal ? el('th.right', { text: 'Jumlah' }) : null,
        el('th', { text: '' }),
      ])),
      body,
    ])),
    totalsRow,
  ]);

  const isFilled = (values) => Object.values(values).some((value) => value !== null && value !== '' && value !== false);

  /*
   * Baris TERISI, dalam urutan tampil — filternya SAMA PERSIS dengan read(),
   * sehingga indeks server == indeks payload == indeks entries(). Itulah yang
   * membuat 'items.6.qty' dari 422 selalu jatuh ke baris terlihat ke-7, bukan
   * ke baris kosong yang tidak ikut terkirim.
   */
  const entries = () => controls.filter((entry) => isFilled(entry.read()));

  return {
    node,
    read: () => entries().map((entry) => entry.read()),
    entries,
  };
}

/*
 * Galat setingkat SEL untuk tabel baris. setFieldError buta secara struktural
 * di sini: sel <td> tidak punya pembungkus .field — persis alasan items.6.qty
 * tidak pernah sampai ke baris 7. Penanda dibersihkan saat sel itu diketik
 * ulang ATAU nilainya di-commit, dan di awal setiap submit.
 */
function setCellError(control, message) {
  const target = control.input || control.node;
  const cell = target.closest ? target.closest('td') : null;
  if (!cell) return;

  cell.querySelectorAll('.err').forEach((node) => node.remove());
  cell.classList.add('cell-invalid');
  cell.appendChild(el('.err', { text: message }));
  cell.addEventListener('input', () => clearCellError(cell), { once: true });
  // Combobox yang di-commit murni lewat mouse (klik opsi, klik ×) sengaja
  // hanya memancarkan 'change' (combobox.js) — tanpa pendengar ini penanda
  // merah bertahan di atas nilai yang sudah sah. clearCellError idempoten,
  // jadi pendengar sisa yang terpicu belakangan tidak berefek apa-apa.
  cell.addEventListener('change', () => clearCellError(cell), { once: true });
}

function clearCellError(cell) {
  cell.classList.remove('cell-invalid');
  cell.querySelectorAll('.err').forEach((node) => node.remove());
}

/**
 * Open a create/edit modal for a resource.
 * `row` present => edit (PUT), absent => create (POST).
 */
export async function openForm({ def, key, row, prefill, onSaved }) {
  const isEdit = Boolean(row);
  const sections = def.form.sections || [];
  const lineDefs = def.form.lines || [];

  // Editing a header+lines document needs the full record, not the list row.
  // A create can still arrive with values the caller already knows — "Tagih
  // termin ini" knows the contract, the customer and which termin.
  let record = row || prefill || null;
  if (isEdit && lineDefs.length) {
    try {
      record = await api.get(`${def.api}/${row.id}`);
    } catch (error) {
      /* JANGAN membuka form dari baris daftar: baris tidak membawa tabel
         barisnya, form akan tampil dengan semua tabel KOSONG, dan Simpan
         mengirimkannya sebagai penghapusan seluruh rincian. Satu gangguan
         jaringan saat mengklik Ubah tidak boleh bisa menghapus lima tabel.
         Gagal membuka lebih jujur daripada berhasil membuka yang salah. */
      toastError(error);
      return;
    }
  }

  await preload([
    ...sections.flatMap((section) => section.fields.map((f) => f.lookup)),
    ...lineDefs.flatMap((line) => line.columns.map((c) => c.lookup)),
  ]);

  /*
   * Draf di peramban (drafts.js). Bila formulir yang sama (resource + id) pernah
   * ditinggalkan dengan isian — sesi berakhir, tab tertutup, laptop mati — isian
   * itu ditawarkan kembali SEBELUM kontrol dibangun, supaya nilai header masuk
   * lewat jalur `record` yang sama dengan Ubah, dan baris lewat initialRows yang
   * sama dengan salin-dari-PO. Menolak = draf dibuang; tidak ada tawaran kedua.
   */
  const draftRowId = isEdit ? row.id : null;
  const draft = loadDraft(key, draftRowId);
  if (draft && (draft.header || draft.lines)) {
    const restore = await confirmDialog({
      title: 'Pulihkan isian yang belum tersimpan?',
      message: `${def.labelOne} ini pernah diisi ${relativeAge(draft.savedAt)} dan belum disimpan — sesi berakhir atau tab tertutup. Pulihkan isiannya?`,
      confirmLabel: 'Pulihkan',
      cancelLabel: 'Mulai kosong',
      tone: 'primary',
    });
    if (restore) {
      record = { ...(record || {}), ...(draft.header || {}), ...(draft.lines || {}) };
    } else {
      removeDraft(key, draftRowId);
    }
  }

  const controls = {};
  const fieldControls = []; // [{ spec, control }] urut tampil — untuk validasi wajib-isi
  /*
   * visibleWhen(values, record, isEdit): field yang ikut-menghilang mengikuti
   * NILAI field lain pada form yang sama — reaktif, dievaluasi ulang setiap
   * kali sebuah field header berubah, berbeda dari hideWhen yang statis pada
   * KEADAAN dokumen saat form dibuka. Pemakai pertamanya form aset (P5):
   * kolom perolehan hanya untuk ownership=owned, kolom sewa hanya untuk
   * rented, dan server MENOLAK kolom pihak lain dengan prohibited_if dua
   * arah — jadi field yang sedang tersembunyi TIDAK masuk payload sama
   * sekali (bukan dikirim kosong), dan wajib-isinya tidak diperiksa: sebuah
   * field yang tidak tampil bukanlah field yang belum diisi.
   */
  const hiddenKeys = new Set();
  const visibilityWatchers = []; // [{ spec, wrapper, sectionNode, sectionSpecs }]
  const body = el('div');

  for (const section of sections) {
    const fields = section.fields
      .filter((spec) => (isEdit ? !spec.createOnly : !spec.editOnly))
      /*
       * hideWhen(record): field yang tidak boleh ada pada KEADAAN dokumen ini —
       * bukan sekadar mode create/edit. Pemakai pertamanya manpower_count
       * laporan harian (P0-A): begitu laporan punya rincian per jabatan,
       * angkanya TURUNAN dan server menolak klaim manual yang berbeda dengan
       * 422; menampilkan kotaknya berarti mengirim angka basi setiap kali
       * rinciannya diedit. Field tersembunyi tidak masuk payload sama sekali,
       * dan server mengartikan absennya sebagai "tidak ada klaim baru".
       */
      .filter((spec) => !spec.hideWhen || !spec.hideWhen(record, isEdit));
    if (!fields.length) continue;

    const grid = el('.form-grid');
    const sectionWatchers = [];
    for (const spec of fields) {
      const initial = record ? record[spec.key] : (spec.defaultYear ? new Date().getFullYear() : undefined);
      const control = buildInput(spec, initial);
      controls[spec.key] = control;
      fieldControls.push({ spec, control });

      const wrapper = spec.type === 'bool'
        ? el('label.field', [el('label', { text: ' ' }), control.node, spec.help ? el('.help', { text: spec.help }) : null])
        : field(spec.label, control.node, { required: spec.required, help: spec.help });

      if (spec.span === 2) wrapper.classList.add('span2');
      grid.appendChild(wrapper);
      if (spec.visibleWhen) sectionWatchers.push({ spec, wrapper });
    }

    const sectionNode = el('.form-section', [
      sections.length > 1 && section.title ? el('h3', { text: section.title }) : null,
      section.help ? el('.alert.info', { style: { marginBottom: '12px' } }, section.help) : null,
      grid,
    ]);
    body.appendChild(sectionNode);

    for (const watcher of sectionWatchers) {
      visibilityWatchers.push({ ...watcher, sectionNode, sectionSpecs: fields });
    }
  }

  const headerValues = () => Object.fromEntries(
    Object.entries(controls)
      .filter(([fieldKey]) => !hiddenKeys.has(fieldKey))
      .map(([fieldKey, control]) => [fieldKey, control.read()]),
  );

  const applyVisibility = () => {
    if (!visibilityWatchers.length) return;
    // Predikatnya membaca nilai MENTAH semua control (termasuk yang sedang
    // tersembunyi) — field pengendali (mis. ownership) tidak pernah membawa
    // visibleWhen sendiri, jadi tidak ada rekursi definisi di sini.
    const raw = Object.fromEntries(
      Object.entries(controls).map(([fieldKey, control]) => [fieldKey, control.read()]),
    );
    for (const { spec, wrapper } of visibilityWatchers) {
      const visible = Boolean(spec.visibleWhen(raw, record, isEdit));
      wrapper.style.display = visible ? '' : 'none';
      if (visible) hiddenKeys.delete(spec.key); else hiddenKeys.add(spec.key);
    }
    // Satu section yang SEMUA field-nya tersembunyi ikut hilang — judul
    // "Perolehan & penyusutan" di atas grid kosong hanya membingungkan.
    const seen = new Set();
    for (const { sectionNode, sectionSpecs } of visibilityWatchers) {
      if (seen.has(sectionNode)) continue;
      seen.add(sectionNode);
      const allHidden = sectionSpecs.every((spec) => spec.visibleWhen && hiddenKeys.has(spec.key));
      sectionNode.style.display = allHidden ? 'none' : '';
    }
  };

  if (visibilityWatchers.length) {
    applyVisibility();
    // Delegasi pada body: combobox memancarkan 'change', input native
    // memancarkan 'input' — keduanya cukup untuk mengevaluasi ulang.
    body.addEventListener('input', applyVisibility);
    body.addEventListener('change', applyVisibility);
  }

  const lineControls = lineDefs.map((lineDef) => {
    const control = buildLines(lineDef, record ? record[lineDef.key] : null, headerValues, isEdit ? record : null);
    body.appendChild(control.node);
    return { def: lineDef, control };
  });

  if (def.form.note) {
    body.appendChild(el('.alert.info', { style: { marginTop: '16px' } }, def.form.note));
  }

  const save = button(isEdit ? 'Simpan Perubahan' : 'Simpan', { variant: 'primary', type: 'submit' });

  /*
   * Unsaved-data guard: one snapshot of every control at open, compared on
   * close. Not input-event tracking — "salin baris dari PO" replaces every line
   * through setRows() without an input event reaching the container, and typing
   * a character then deleting it would leave an event-tracked form dirty for
   * good. Every read() in buildInput is synchronous and settled on the first
   * tick, the combobox included: its read() returns the committed id and is
   * never derived from rows that arrive later. With the old <select> this could
   * not have worked at all — the options landed after the snapshot and
   * select.value flipped from '' to the real id, so every edit form in the app
   * would have opened already dirty and the prompt would be pure noise.
   */
  const snapshot = () => JSON.stringify([
    Object.entries(controls).map(([fieldKey, control]) => [fieldKey, control.read()]),
    lineControls.map(({ control }) => control.read()),
  ]);
  const pristine = snapshot();

  /*
   * Draf otomatis: setiap ketikan menjadwalkan simpanan ke localStorage 1,2
   * detik kemudian; app.js meminta flush langsung saat 401. Formulir yang
   * kembali bersih (isian dihapus lagi) mencabut drafnya, dan draf hilang saat
   * Simpan berhasil atau pengguna sendiri memilih "Buang isian" — tetapi TIDAK
   * saat overlay ditutup paksa oleh sesi yang berakhir (draftRemovalSuspended).
   */
  let draftTimer = null;
  const persistDraft = () => {
    clearTimeout(draftTimer);
    if (snapshot() === pristine) { removeDraft(key, draftRowId); return; }
    saveDraft(key, draftRowId, {
      label: def.labelOne,
      header: Object.fromEntries(Object.entries(controls).map(([fieldKey, control]) => [fieldKey, control.read()])),
      lines: Object.fromEntries(lineControls.map(({ def: lineDef, control }) => [lineDef.key, control.read()])),
    });
  };
  const scheduleDraft = () => { clearTimeout(draftTimer); draftTimer = setTimeout(persistDraft, 1200); };
  body.addEventListener('input', scheduleDraft, true);
  body.addEventListener('change', scheduleDraft, true);
  const unregisterFlush = registerDraftFlush(persistDraft);

  const dialog = modal({
    title: `${isEdit ? 'Ubah' : 'Tambah'} ${def.labelOne}`,
    width: lineDefs.length ? 'wide' : '',
    body,
    // Batal sits right beside Simpan in .modal-foot; a mis-aimed click there
    // loses the same 15 lines a mis-aimed Escape does.
    footer: [button('Batal', { onClick: () => dialog.requestClose() }), save],
    dirty: () => snapshot() !== pristine,
    onClose: () => {
      clearTimeout(draftTimer);
      unregisterFlush();
      if (!draftRemovalSuspended()) removeDraft(key, draftRowId);
    },
  });

  /*
   * 'items.6.qty' dari 422 → sel qty pada baris terlihat ke-7. entries()
   * memakai filter baris-terisi yang sama dengan read(), jadi indeks server
   * (indeks payload) langsung menjadi indeks entries(). Kunci yang tidak
   * berhasil dipetakan jatuh kembali ke jalur lama (setFieldError / toast),
   * jadi tidak ada yang mundur.
   */
  function applyLineError(fieldKey, message, scrolled) {
    const match = /^([A-Za-z_]\w*)\.(\d+)\.(\w+)$/.exec(fieldKey);
    if (!match) return false;
    const line = lineControls.find(({ def: lineDef }) => lineDef.key === match[1]);
    if (!line) return false;
    const entry = line.control.entries()[Number(match[2])];
    const cell = entry && entry.inputs[match[3]];
    if (!cell) return false;

    setCellError(cell, message);
    if (!scrolled.done) {
      entry.row.scrollIntoView({ block: 'center', behavior: 'smooth' });
      scrolled.done = true;
    }
    return true;
  }

  save.addEventListener('click', async () => {
    // Bersihkan penanda putaran sebelumnya dulu — kegagalan lama tidak boleh
    // menumpuk dengan hasil putaran ini.
    body.querySelectorAll('.field.invalid').forEach((node) => {
      node.classList.remove('invalid');
      node.querySelectorAll('.err').forEach((err) => err.remove());
    });
    body.querySelectorAll('td.cell-invalid').forEach((cell) => clearCellError(cell));

    /*
     * Wajib-isi diperiksa SEBELUM request — hanya wajib-isi: format sudah
     * dijamin input native date/number, dan aturan bisnis tetap milik server.
     * Baris yang diperiksa adalah baris yang sama persis dengan yang akan
     * dikirim read() (baris kosong tersaring), jadi tidak ada penanda pada
     * baris yang tidak ikut terkirim.
     */
    const invalid = [];
    for (const { spec, control } of fieldControls) {
      if (!spec.required) continue;
      // Field yang sedang tersembunyi (visibleWhen) tidak dikirim, jadi
      // wajib-isinya juga tidak berlaku — ia bukan field yang belum diisi.
      if (hiddenKeys.has(spec.key)) continue;
      const value = control.read();
      if (value === null || value === '') {
        setFieldError(control.input || control.node, 'Wajib diisi.');
        invalid.push(control);
      }
    }
    for (const { def: lineDef, control } of lineControls) {
      for (const entry of control.entries()) {
        for (const column of lineDef.columns) {
          if (!column.required) continue;
          const cell = entry.inputs[column.key];
          const value = cell.read();
          if (value === null || value === '') {
            setCellError(cell, 'Wajib diisi.');
            invalid.push(cell);
          }
        }
      }
    }
    if (invalid.length) {
      toast('Periksa isian yang ditandai.', { tone: 'err' });
      const first = invalid[0].input || invalid[0].node;
      first.scrollIntoView({ block: 'center', behavior: 'smooth' });
      first.focus({ preventScroll: true });
      return;
    }

    const payload = {};
    for (const [fieldKey, control] of Object.entries(controls)) {
      // Field yang sedang tersembunyi (visibleWhen) tidak masuk payload sama
      // sekali — server memvalidasinya prohibited_if, dan nilai sisa ketikan
      // di field yang sudah disembunyikan bukan pernyataan pengguna.
      if (hiddenKeys.has(fieldKey)) continue;
      const value = control.read();
      if (value !== null || isEdit) payload[fieldKey] = value;
    }
    for (const { def: lineDef, control } of lineControls) {
      payload[lineDef.key] = control.read();
    }

    await withBusy(save, async () => {
      const submit = () => (isEdit
        ? api.put(`${def.api}/${record.id}`, payload)
        : api.post(def.api, payload));

      const finish = (saved) => {
        invalidateByPath(def.api);
        removeDraft(key, draftRowId);
        toast(`${def.labelOne} ${isEdit ? 'diperbarui' : 'dibuat'}.`);
        dialog.close();
        if (onSaved) onSaved(saved);
      };

      /*
       * Toast 422 hanya menyebut kunci yang TIDAK terpetakan ke kontrol.
       * Diukur 2 Sep 2026 pada PO (HASIL-UJI §2.4, harness S3): sel qty
       * sudah merah "Kuantitas minimal 0.001." dan toast masih membaca
       * `items.0.qty: Kuantitas minimal 0.001.` — awalan mentah itu hanya
       * berguna untuk kunci yang tidak punya kontrol di layar (kolom yang
       * tidak ada di formulir, field yang sedang disembunyikan visibleWhen —
       * penanda di pembungkus display:none bukan penanda yang tampak). Bila
       * semua kunci berhasil dilukis, toastnya kalimat yang sama dengan
       * pemeriksaan wajib-isi di atas; bila hanya sebagian, kalimat itu jadi
       * judul dan sisanya tetap disebut dengan kuncinya; bila tidak ada satu
       * pun yang dilukis, jalur lama utuh — jangan bilang "ditandai" untuk
       * galat yang tidak ditandai.
       */
      const paintErrors = (error) => {
        if (!error.errors) {
          toastError(error);
          return;
        }
        const scrolled = { done: false };
        const unmapped = [];
        for (const [fieldKey, messages] of Object.entries(error.errors)) {
          const message = [].concat(messages)[0];
          if (applyLineError(fieldKey, message, scrolled)) continue;
          const headKey = fieldKey.split('.')[0];
          const control = controls[headKey];
          if (control && !hiddenKeys.has(headKey)) {
            setFieldError(control.input || control.node, message);
            continue;
          }
          unmapped.push(`${fieldKey}: ${message}`);
        }
        if (!unmapped.length) {
          toast('Periksa isian yang ditandai.', { tone: 'err' });
          return;
        }
        const painted = unmapped.length < Object.keys(error.errors).length;
        toastError({ message: painted ? 'Periksa isian yang ditandai.' : error.message, details: unmapped });
      };

      try {
        finish(await submit());
      } catch (error) {
        /*
         * Alur konfirmasi-lanjut (def.form.confirmResubmit — GRN harga 0
         * pada baris tertaut PO adalah pemakainya, Temuan 72): server
         * menolak 422 pada field yang cocok `test` sampai payload membawa
         * `flag`. Dialog memakai pesan server apa adanya — pesan itulah
         * yang menyebut nama barang berharga nol. Hanya berjalan bila SEMUA
         * galat cocok pola: bila ada galat lain, dikonfirmasi pun kiriman
         * ulang tetap ditolak, jadi galatnya dilukis biasa saja.
         */
        const rule = def.form.confirmResubmit;
        const keys = error.errors ? Object.keys(error.errors) : [];
        if (rule && !payload[rule.flag] && keys.length && keys.every((key) => rule.test.test(key))) {
          const ok = await confirmDialog({
            title: rule.title,
            message: keys.map((key) => [].concat(error.errors[key])[0]).join(' '),
            confirmLabel: rule.confirmLabel,
          });
          if (!ok) return;
          payload[rule.flag] = true;
          try {
            finish(await submit());
          } catch (retryError) {
            paintErrors(retryError);
          }
          return;
        }
        paintErrors(error);
      }
    });
  });
}

/**
 * Dialog pilih-baris — saudara promptFields untuk data berbentuk TABEL:
 * sejumlah baris kandidat, centang yang mau diambil, dan jawabannya adalah
 * baris-baris terpilih itu sendiri (null = batal). Pemakai pertamanya "Impor
 * dari GRN" pada laporan harian (P0-A); mesinnya generik untuk impor-pilih
 * berikutnya. Barisnya datar (teks/angka siap tampil), jadi renderCell cukup
 * tanpa preload lookup.
 *
 * columns  kolom tampilan (kosakata renderCell; sub untuk baris kedua)
 * note     (row) => teks | null — badge amber di ujung baris, mis. penanda
 *          "Sudah diimpor" supaya baris ganda lahir dengan mata terbuka,
 *          bukan dilarang (server memang mengizinkannya)
 * hint     satu kalimat pengantar di atas tabel
 */
function pickRowsDialog({ title, rows, columns, note, hint, confirmLabel = 'Tambahkan yang dipilih' }) {
  return new Promise((resolve) => {
    const checks = [];

    const body = el('tbody', rows.map((row) => {
      const check = el('input', { type: 'checkbox', 'aria-label': 'Pilih baris ini' });
      checks.push(check);

      const noteText = note ? note(row) : null;
      const tr = el('tr', { style: { cursor: 'pointer' } }, [
        el('td', { style: { width: '34px' } }, check),
        ...columns.map((column) => el(`td${column.align ? `.${column.align}` : ''}`, renderCell(row, column))),
        note ? el('td', noteText ? badge(noteText, 'amber') : null) : null,
      ]);

      // Satu baris = satu sasaran sentuh: klik di mana pun membalik centangnya.
      tr.addEventListener('click', (event) => {
        if (event.target !== check) check.checked = !check.checked;
      });

      return tr;
    }));

    const submit = button(confirmLabel, { variant: 'primary' });
    submit.addEventListener('click', () => {
      const picked = rows.filter((_, index) => checks[index].checked);
      if (!picked.length) {
        toast('Centang dulu baris yang mau diambil.', { tone: 'err' });
        return;
      }
      // Selesaikan SEBELUM menutup — close() memicu onClose yang menjawab null
      // (urutan yang sama dengan promptFields di bawah).
      resolve(picked);
      dialog.close();
    });

    const dialog = modal({
      title,
      width: 'wide',
      body: el('div', [
        hint ? el('.help', { text: hint, style: { marginBottom: '10px' } }) : null,
        el('.table-wrap', el('table.data', [
          el('thead', el('tr', [
            el('th', { text: '' }),
            ...columns.map((column) => el(`th${column.align ? `.${column.align}` : ''}`, { text: column.label })),
            note ? el('th', { text: '' }) : null,
          ])),
          body,
        ])),
      ]),
      footer: [button('Batal', { onClick: () => { resolve(null); dialog.close(); } }), submit],
      onClose: () => resolve(null),
    });
  });
}

/**
 * Ad-hoc modal form for lifecycle actions (approve note, assign, faktur…).
 *
 * `message` is the sentence the fields answer, shown above them — the
 * confirm-resubmit engine in actions.js passes the server's 422 text here
 * (which vendor, which mandatory document expired since when) so the operator
 * reads the refusal in the same dialog that asks for the override reason,
 * instead of typing a reason for a refusal that only flashed by as a toast.
 */
export async function promptFields(title, fields, { submitLabel = 'Kirim', message } = {}) {
  await preload(fields.map((spec) => spec.lookup));

  return new Promise((resolve) => {
    const controls = {};
    const grid = el('.form-grid');
    // Same paragraph style as confirmDialog's body, so the two dialogs of one
    // Ajukan round (a Ya/Batal warning, then a prompt) read as one voice.
    const body = message
      ? el('div', [el('p', { text: message, style: { margin: '0 0 12px', color: 'var(--text-2)' } }), grid])
      : grid;

    for (const spec of fields) {
      const control = buildInput(spec, undefined);
      controls[spec.key] = control;
      const wrapper = spec.type === 'bool'
        ? el('label.field', [el('label', { text: ' ' }), control.node])
        : field(spec.label, control.node, { required: spec.required, help: spec.help });
      wrapper.classList.add('span2');
      grid.appendChild(wrapper);
    }

    const submit = button(submitLabel, { variant: 'primary' });
    const dialog = modal({
      title,
      width: 'narrow',
      body,
      footer: [button('Batal', {
        // Resolve BEFORE closing, the same order as confirmDialog's cancel
        // button in ui.js, and do not "tidy" it back. close() fires onClose,
        // which settles this promise itself: today both answers are null so the
        // order does not show, but the day promptFields gets a `dirty` guard —
        // it is a form with lookups too — Batal routes through requestClose()
        // and a surviving close() on this line would settle the promise before
        // the user has answered the discard prompt. Every action with fields
        // (faktur, assign, pembayaran retensi at custom.js:417) would then look
        // cancelled and silently do nothing.
        onClick: () => { resolve(null); dialog.close(); },
      }), submit],
      onClose: () => resolve(null),
    });

    submit.addEventListener('click', () => {
      const payload = {};
      let valid = true;
      for (const spec of fields) {
        const value = controls[spec.key].read();
        if (spec.required && (value === null || value === '')) {
          setFieldError(controls[spec.key].input || controls[spec.key].node, 'Wajib diisi.');
          valid = false;
        }
        if (value !== null) payload[spec.key] = value;
      }
      if (!valid) return;
      // Resolve before closing: close() fires onClose, which would settle this
      // promise as `null` first and make the action look cancelled.
      resolve(payload);
      dialog.close();
    });
  });
}
