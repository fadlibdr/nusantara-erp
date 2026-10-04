/* Schema-driven create/edit form, including repeatable line-item tables. */

import { api } from '../api.js';
import { el, clear, button, badge, icon, modal, field, setFieldError, toast, toastError, withBusy, confirmDialog, errorSummary, stepper } from '../ui.js';
import { renderCell } from '../cells.js';
import { ENUMS } from '../enums.js';
import { loadSource, optionsFor, preload, invalidateByPath, invalidate, sourceState, noticeFor } from '../lookup.js';
import { combobox } from '../combobox.js';
import { moneyInput } from '../money.js';
import { MONTHS, rupiah, toDateInput, toDateTimeInput, today, date as fmtDate } from '../format.js';
import { navigate } from '../router.js';
import { saveDraft, loadDraft, removeDraft, registerDraftFlush, relativeAge, draftRemovalSuspended } from '../drafts.js';

/*
 * CATATAN HIDUP DI BAWAH SEBUAH FIELD (F-2 / T2.6).
 *
 * Sebuah peringatan anggaran yang hanya ada di layar laporan adalah peringatan
 * yang dibaca sesudah uangnya dibelanjakan. Ini memasangnya DI TEMPAT UANGNYA
 * DIBELANJAKAN: begitu sebuah proyek dipilih pada formulir PO atau SPK, server
 * ditanya berapa anggaran proyek itu yang sudah terpakai, dan jawabannya
 * dicetak di bawah kotak Proyek sebelum satu baris pun diketik.
 *
 * Bentuknya registri berkunci, bukan closure di schema.js: schema.js adalah
 * DATA (tidak mengimpor apa pun), dan memberinya `import { api }` akan
 * menautkan berkas terbesar SPA ke transport-nya.
 *
 * Kegagalan = DIAM. Pemakai tanpa izin membaca angka anggaran (403), modul
 * Finance yang tidak menjawab, jaringan yang putus — tidak satu pun boleh
 * menghalangi pembuatan PO. Yang hilang hanyalah peringatannya, dan gerbang di
 * server tetap berdiri di belakangnya dengan kalimat penolakannya sendiri.
 */
/* SISI YANG BENAR, BUKAN TOTALNYA (verifikasi F-2). Gerbang mengukur sebuah PO
   terhadap sisa NON-SUBKON dan sebuah SPK terhadap sisa SUBKON; catatan yang
   mencetak sisa TOTAL menjanjikan ruang yang tidak dimiliki dokumen yang sedang
   dibuat orangnya. Terukur pada salinan data demo: catatan formulir PO berbunyi
   "sisa Rp 1.697.500.000" sementara sisi non-subkonnya −Rp 105.039.400, dan PO
   Rp 1 ditolak 422. Maka formulir PO memakai sides.non_subcon dan formulir SPK
   sides.subcon — kalimat yang kata-katanya sama dengan penolakan gerbang. */
async function projectBudgetSide(value, key) {
  if (!value) return null;

  const budget = await api.get(`finance/budget/projects/${value}`);
  const side = budget && budget.sides ? budget.sides[key] : null;

  if (!side) return null;

  return {
    tone: side.state === 'lampau' ? 'danger' : (side.state === 'mendekati' ? 'warning' : ''),
    text: side.sentence,
  };
}

const LIVE_NOTES = {
  projectBudgetPo: (value) => projectBudgetSide(value, 'non_subcon'),
  projectBudgetSpk: (value) => projectBudgetSide(value, 'subcon'),
};



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

  const totalsRow = el('div.lines-total');
  /*
   * DPP hidup (UX-E3.6, README E3 "live totals"): Subtotal − min(Diskon, Subtotal) — rumus
   * PoService::recalcTotals yang sama, dari dua angka yang memang ada di peramban.
   * `lineDef.discountField` menyebut field kepala diskonnya. PPN dan Total TIDAK dihitung
   * di sini: tarifnya `vendor.is_pkp ? tax.ppn_rate : 0` ada di server (config + status
   * vendor), dan menebak 11 di peramban adalah aturan bisnis kedua (aturan dasar 2/3).
   * Satu kalimat jujur menggantikannya.
   */
  const discountField = lineDef.discountField || null;

  function refreshTotals() {
    clear(totalsRow);
    if (hasTotal) {
      const sum = controls.reduce((acc, row) => acc + (Number(lineDef.total(row.read())) || 0), 0);
      totalsRow.appendChild(el('span', [el('span.muted', { text: 'Subtotal: ' }), el('span.num', { text: rupiah(sum) })]));
      if (discountField && headerValues) {
        const discount = Math.min(Number(headerValues()[discountField]) || 0, sum);
        totalsRow.appendChild(el('span', [el('span.muted', { text: 'Diskon: ' }), el('span.num', { text: rupiah(discount) })]));
        totalsRow.appendChild(el('span', [el('span.muted', { text: 'DPP: ' }), el('span.num', { text: rupiah(sum - discount) })]));
        totalsRow.appendChild(el('span.muted.lines-note', { text: 'PPN dan total dihitung server saat disimpan.' }));
      }
    }
    if (hasBalance) {
      const debit = controls.reduce((acc, row) => acc + (Number(row.read()[lineDef.balance.debit]) || 0), 0);
      const credit = controls.reduce((acc, row) => acc + (Number(row.read()[lineDef.balance.credit]) || 0), 0);
      const balanced = Math.abs(debit - credit) <= 0.01 && debit > 0;
      totalsRow.append(
        el('span', [el('span.muted', { text: 'Debit: ' }), el('span.num', { text: rupiah(debit) })]),
        el('span', [el('span.muted', { text: 'Kredit: ' }), el('span.num', { text: rupiah(credit) })]),
        el(`span.${balanced ? 'tone-pos' : 'tone-neg'}`, {
          text: balanced ? 'Seimbang ✓' : `Selisih ${rupiah(Math.abs(debit - credit))}`,
        }),
      );
    }
  }

  /*
   * Baris TERISI = baris yang pernah disentuh orang (atau datang membawa data), DAN
   * punya nilai. Kolom dengan `default` (qty: 1 pada PO, penawaran, PR) membuat baris
   * yang baru lahir langsung "bernilai" — sebelum UX-E3.3 baris kosong dengan qty 1
   * ikut terkirim dan ditolak "Wajib diisi." pada uraian/harganya (terukur 4 Okt 2026,
   * S3/S4 pada baris otomatis). `touched` dipasang pada 'input'/'change' di fase
   * tangkap, sebelum pendengar lain pada baris yang sama membacanya.
   */
  const hasValue = (values) => Object.values(values).some((value) => value !== null && value !== '' && value !== false);
  const isFilled = (entry) => entry.touched && hasValue(entry.read());

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
      // data-label: judul kolom untuk kartu baris di ≤ 760 px (UX-E3.6, CSS ::before) — DOM tabelnya tetap.
      cells.push(el('td', { 'data-label': column.label, style: column.width ? { width: column.width } : {} }, control.node));
    }

    if (hiddenNodes.length && cells.length) cells[0].append(...hiddenNodes.splice(0));

    const row = el('tr', [
      ...cells,
      hasTotal ? el('td.right.row-total', { text: '—', 'data-label': 'Jumlah' }) : null,
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
      touched: Object.values(data || {}).some((value) => value !== null && value !== undefined && value !== ''),
    };
    const touch = () => { entry.touched = true; };
    row.addEventListener('input', touch, true);
    row.addEventListener('change', touch, true);

    if (hasTotal) {
      const cell = row.querySelector('.row-total');
      const update = () => { cell.textContent = rupiah(lineDef.total(entry.read())); };
      row.addEventListener('input', update);
      row.addEventListener('change', update);
      update();
    }

    /*
     * Baris kosong berikutnya lahir sendiri begitu baris TERAKHIR terisi (UX-E3.3) —
     * diukur 4 Okt 2026 (results-ux-e3-base.json S3): satu klik "Tambah baris" per
     * baris kedua pada setiap dokumen dua baris. Baris kosong tidak pernah ikut
     * terkirim, diperiksa, atau dipetakan 422: entries() menyaringnya (isFilled).
     * "Tambah baris" tetap ada untuk yang mengetik dari bawah; baris baru tidak
     * merebut fokus. Kotak pilihan mengirim 'change' saat Enter, teks 'input'
     * pada huruf pertama.
     */
    const appendIfLast = () => {
      if (controls[controls.length - 1] === entry && isFilled(entry)) addRow();
    };
    row.addEventListener('input', appendIfLast);
    row.addEventListener('change', appendIfLast);

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
    lineDef.help ? el('.help.lines-help', { text: lineDef.help }) : null,
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

  /*
   * Baris TERISI, dalam urutan tampil — filternya SAMA PERSIS dengan read(),
   * sehingga indeks server == indeks payload == indeks entries(). Itulah yang
   * membuat 'items.6.qty' dari 422 selalu jatuh ke baris terlihat ke-7, bukan
   * ke baris kosong yang tidak ikut terkirim.
   */
  const entries = () => controls.filter((entry) => isFilled(entry));

  return {
    node,
    read: () => entries().map((entry) => entry.read()),
    entries,
    refresh: refreshTotals,
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
 * `endpoint` (create only): POST ke jalur ini, bukan def.api — formulir
 * kontrak yang disimpan ke crm/quotations/{id}/create-contract (T3.6);
 * lihat `submitTo` di actions.js.
 */
/*
 * Presenter bawaan: dialog — bentuk yang dipakai 57 sumber daya tanpa `lines`.
 * `footer` diterima sebagai fungsi supaya tombol Batal bisa memanggil
 * requestClose() milik permukaan yang BELUM ada saat footernya disusun.
 */
export function presentModal(spec) {
  return modal(spec);
}

/*
 * Dialog atau halaman? (UI-4d)
 *
 * Aturan pemutusnya keputusan pemilik #2 seperti yang rencana tulis: apakah
 * sumber daya menyatakan `lines`. Terukur pada schema.js — 92 sumber daya
 * berformulir, 35 menyatakan lines. Formulir master-data empat medan tidak
 * mendapat apa-apa dari sebuah halaman; yang punya tabel baris mendapat 89 px
 * (ASESMEN-UX §1.2: tabel baris mulai y = 843 di modal 960 px, 754 di halaman).
 */
export function formIsPaged(def) {
  // `form.layout: 'page' | 'modal'` (README E3) mengalahkan aturan baris; belum ada yang menyatakannya (OQ).
  if (def && def.form && def.form.layout) return def.form.layout === 'page';
  return Boolean(def && def.form && (def.form.lines || []).length);
}

/*
 * Jalan masuk GENERIK ke formulir: halaman bila sumber dayanya berbaris,
 * dialog bila tidak.
 *
 * `prefill` dan `endpoint` memaksa dialog, dan itu batas yang jujur: keduanya
 * membawa konteks yang hanya dimiliki pemanggilnya ("Tagih termin ini" sudah
 * tahu kontrak, pelanggan dan termin mana), dan sebuah URL tidak bisa
 * menyusunnya kembali. Memaksa mereka ke halaman berarti membuang isian yang
 * sudah diketahui.
 */
export function startForm({ def, key, row, prefill, onSaved, endpoint = null }) {
  if (!prefill && !endpoint && formIsPaged(def)) {
    navigate(row ? `f/${key}/${row.id}` : `f/${key}`);
    return undefined;
  }
  return openForm({ def, key, row, prefill, onSaved, endpoint });
}

/*
 * `submit` (UX-E3.2, README E3 / ERRATA E-6): hanya rute halaman f/* yang memberinya —
 * { action, run(saved), done(code, result) } yang dirakit app.js dari actions.js
 * (withConfirmResubmit + postAction + toastActionDone). form.js TIDAK mengimpor
 * actions.js: actions.js mengimpor form.js, dan sebuah siklus modul adalah utang yang
 * tidak perlu dibayar. Pemanggil dialog (prefill/endpoint/onSaved, S16/S17) tidak
 * mengenal opsi ini, jadi dialognya sebyte pun tidak berubah.
 */
export async function openForm({ def, key, row, prefill, onSaved, endpoint = null, present = presentModal, submit = null, fetched = false }) {
  const isEdit = Boolean(row);
  const sections = def.form.sections || [];
  const lineDefs = def.form.lines || [];

  // Editing a header+lines document needs the full record, not the list row.
  // A create can still arrive with values the caller already knows — "Tagih
  // termin ini" knows the contract, the customer and which termin.
  let record = row || prefill || null;
  if (isEdit && lineDefs.length && !fetched) {  // rute f/ memberi dokumen lengkap (UX-E3.7): satu GET, bukan dua
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

  const noteWatchers = [];

  const steps = [];
  const numbered = sections.filter((section) => (section.fields || []).some((spec) => (isEdit ? !spec.createOnly : !spec.editOnly))).length + lineDefs.length > 1;
  // Bagian `advanced` digambar SESUDAH tabel baris (README E3: sections + Item + Lanjutan).
  const renderSection = (section) => {
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
    if (!fields.length) return;

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
      if (spec.liveNote && LIVE_NOTES[spec.liveNote]) noteWatchers.push({ spec, wrapper, control });
    }

    /*
     * Bagian bernomor dan "Lanjutan" yang dilipat (UX-E3.5, README E3). Langkah
     * (steps) = bagian kepala + satu per tabel baris + bagian `advanced`; bila
     * langkahnya ≥ 2 judulnya bernomor ("1 · Pesanan pembelian") dan halaman
     * memasang navigasi bagian (stepper) di ≥ 1024 px. `section.advanced` menjadi
     * <details> tertutup berjudul "Lanjutan": wajib-isi di dalamnya TETAP diperiksa,
     * dan lipatannya dibuka begitu ada galat (focusField/markSteps). Bagian kepala
     * tanpa judul memakai nama dokumennya sebagai label langkah.
     */
    const stepIndex = steps.length + 1;
    const title = section.title || (section.advanced ? 'Lanjutan' : def.labelOne);
    const heading = numbered ? `${stepIndex} · ${title}` : title;
    const sectionNode = section.advanced
      ? el('details.form-section.advanced', [
        el('summary', [el('h3', { text: heading }), el('span.muted', { text: `${fields.length} isian` })]),
        section.help ? el('.alert.info', section.help) : null,
        grid,
      ])
      : el('.form-section', [
        numbered || (sections.length > 1 && section.title) ? el('h3', { text: heading }) : null,
        section.help ? el('.alert.info', section.help) : null,
        grid,
      ]);
    steps.push({ label: title, node: sectionNode });
    body.appendChild(sectionNode);

    for (const watcher of sectionWatchers) {
      visibilityWatchers.push({ ...watcher, sectionNode, sectionSpecs: fields });
    }
  };
  sections.filter((section) => !section.advanced).forEach(renderSection);

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

  /* Catatan hidup: satu <div class=help> per field, diperbarui setiap kali
     nilainya berubah. `seq` menjaga urutan — jawaban untuk proyek yang dipilih
     lebih dulu tidak boleh menimpa jawaban untuk proyek yang dipilih kemudian
     hanya karena ia datang belakangan.

     Pendengarnya DIDELEGASIKAN pada body, bukan dipasang pada control-nya, dan
     itu bukan selera: combobox memancarkan 'change' dari <input> di dalamnya
     (combobox.js), dan sebuah pendengar pada pembungkus lookup tidak
     menerimanya di setiap jalur — diukur pada harness S29 putaran pertama,
     yang memilih PRJ-2026-001 di formulir PO dan tidak mendapat satu pun
     catatan. Delegasi adalah pola yang sama yang sudah dipakai visibleWhen di
     atas, dengan alasan yang persis sama. */
  const noteRefreshers = noteWatchers.map((watcher) => {
    const note = el('.help', { role: 'status' });
    note.hidden = true;
    watcher.wrapper.appendChild(note);

    let seq = 0;
    let last;

    return async () => {
      const value = watcher.control.read();
      if (value === last) return;
      last = value;

      const mine = ++seq;

      try {
        const result = await LIVE_NOTES[watcher.spec.liveNote](value);
        if (mine !== seq) return;
        note.hidden = !result;
        note.textContent = result ? result.text : '';
        // Warna lewat kelas, bukan gaya sebaris (UX-E3.4; CONVENTIONS §13a).
        note.classList.toggle('tone-neg', Boolean(result && result.tone === 'danger'));
        note.classList.toggle('tone-warn', Boolean(result && result.tone === 'warning'));
      } catch {
        // Diam: peringatan yang gagal dimuat tidak boleh menghalangi dokumen.
        if (mine !== seq) return;
        note.hidden = true;
      }
    };
  });

  if (noteRefreshers.length) {
    const refreshNotes = () => noteRefreshers.forEach((run) => run());
    body.addEventListener('change', refreshNotes);
    refreshNotes();
  }

  const lineControls = lineDefs.map((lineDef) => {
    const control = buildLines(lineDef, record ? record[lineDef.key] : null, headerValues, isEdit ? record : null);
    if (numbered) control.node.querySelector('.lines-head h3').textContent = `${steps.length + 1} · ${lineDef.label}`;
    steps.push({ label: lineDef.label, node: control.node });
    body.appendChild(control.node);
    return { def: lineDef, control };
  });

  sections.filter((section) => section.advanced).forEach(renderSection);

  // Diskon diketik di kepala → DPP di bawah tabel mengikuti (didelegasikan di badan, seperti liveNote).
  if (lineControls.some(({ def: lineDef }) => lineDef.discountField)) {
    const refreshLines = () => lineControls.forEach(({ control }) => control.refresh());
    body.addEventListener('input', refreshLines);
    body.addEventListener('change', refreshLines);
  }

  if (def.form.note) {
    body.appendChild(el('.alert.info.form-note', def.form.note));
  }

  /*
   * Navigasi bagian (UX-E3.5): stepper vertikal yang menempel di kiri pada ≥ 1024 px,
   * hanya bila langkahnya ≥ 2 — satu tombol per bagian melompat ke bagiannya dan
   * memfokuskan kontrol pertamanya; `aria-current="step"` mengikuti gulir; titik merah
   * (li.has-error) mengikuti penanda galat. Dialog mengabaikannya (present() dialog tidak
   * membaca `nav`): 640/960 px tidak punya ruang untuknya.
   */
  const jumpTo = (step) => {
    if (step.node.tagName === 'DETAILS') step.node.toggleAttribute('open', true);
    step.node.scrollIntoView({ block: 'start', behavior: 'smooth' });
    const target = step.node.querySelector('input:not([type=hidden]), select, textarea, button');
    if (target) target.focus({ preventScroll: true });
  };
  const nav = numbered
    ? el('nav.form-nav', { 'aria-label': 'Bagian formulir' }, stepper(steps.map((step) => ({ label: step.label, onClick: () => jumpTo(step) }))))
    : null;
  const stepItems = nav ? [...nav.querySelectorAll('li.step')] : [];
  function markSteps() {
    steps.forEach((step, index) => {
      const bad = Boolean(step.node.querySelector('.field.invalid, td.cell-invalid'));
      if (bad && step.node.tagName === 'DETAILS') step.node.toggleAttribute('open', true);
      if (stepItems[index]) {
        stepItems[index].classList.toggle('has-error', bad);
        const link = stepItems[index].querySelector('.step-link');
        if (link) link.title = bad ? 'Ada isian yang perlu diperbaiki di bagian ini' : '';
      }
    });
  }
  function markActive() {
    if (!nav) return;
    const limit = 96;
    let current = 0;
    steps.forEach((step, index) => { if (step.node.getBoundingClientRect().top <= limit) current = index; });
    stepItems.forEach((item, index) => {
      item.classList.toggle('active', index === current);
      if (index === current) item.setAttribute('aria-current', 'step'); else item.removeAttribute('aria-current');
    });
  }
  if (nav) {
    let ticking = false;
    const onScroll = () => { if (ticking) return; ticking = true; requestAnimationFrame(() => { ticking = false; markActive(); }); };
    window.addEventListener('scroll', onScroll, { passive: true });
    document.querySelector('.main')?.addEventListener('scroll', onScroll, { passive: true });
    requestAnimationFrame(markActive);
  }

  /*
   * Simpan & ajukan (README E3). Diukur 4 Okt 2026 (results-ux-e3-base.json S3): 12 klik
   * buat→ajukan, dua yang terakhir Simpan lalu Ajukan di halaman dokumen — satu keputusan
   * yang dibayar dua klik dan satu perpindahan layar. Tombolnya ada hanya bila rute
   * memberi `submit` (halaman), dokumennya baru atau statusnya masih mengizinkan
   * (action.when), dan pembacanya boleh menjalankan aksinya (session.canAct di app.js —
   * tidak ada nama izin yang ditulis di sini, aturan dasar 2). Aksi submit yang membawa
   * `fields`/`confirm`/`inlineNote` butuh dialognya sendiri dan tetap lewat Ajukan di
   * halaman dokumen (app.js menyaringnya). Label Simpan menjadi "Simpan draf" hanya bila
   * ada Simpan & ajukan di sampingnya dan dokumennya memang (akan) berstatus draf —
   * PO yang ditolak lalu disimpan ulang bukan draf, labelnya tetap "Simpan Perubahan".
   */
  const canSubmit = Boolean(submit && submit.action) && !prefill && !endpoint
    && (!isEdit || !submit.action.when || submit.action.when(record));
  const isDraftLike = !isEdit || !record || record.status === 'draft';
  const saveLabel = canSubmit && isDraftLike ? 'Simpan draf' : (isEdit ? 'Simpan Perubahan' : 'Simpan');
  const save = button(saveLabel, { variant: canSubmit ? '' : 'primary', type: 'submit' });
  const saveSubmit = canSubmit ? button('Simpan & ajukan', { variant: 'primary', type: 'submit', iconName: 'send' }) : null;

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

  /*
   * Permukaannya dipilih pemanggil (UI-4d), isinya tidak.
   *
   * Semua di atas — kontrol, tabel baris, snapshot, draf otomatis, pemetaan
   * galat 422 — dibangun sekali dan tidak tahu ia akan duduk di dalam dialog
   * atau di dalam halaman. Yang membedakan keduanya hanya DUA hal, dan itulah
   * sebabnya seam ini sesempit ini: `requestClose()` yang bertanya lebih dulu,
   * dan `close()` yang tidak. Tidak ada cabang `if (halaman)` di bawah sini.
   *
   * Tombol Batal menutup `dialog` sebelum ia ada — sama seperti sebelumnya:
   * penanganya baru berjalan setelah present() kembali.
   */
  // eslint-disable-next-line prefer-const -- footer menutup `dialog` sebelum ia ada
  let dialog;
  dialog = present({
    title: `${isEdit ? 'Ubah' : 'Tambah'} ${def.labelOne}`,
    width: lineDefs.length ? 'wide' : '',
    body,
    // Batal sits right beside Simpan in .modal-foot; a mis-aimed click there
    // loses the same 15 lines a mis-aimed Escape does.
    footer: [button('Batal', { onClick: () => dialog.requestClose() }), save, saveSubmit].filter(Boolean),
    nav,
    dirty: () => snapshot() !== pristine,
    onClose: ({ keepDraft = false } = {}) => {
      clearTimeout(draftTimer);
      unregisterFlush();
      // Tinggalkan (halaman): simpan isian yang masih tertunda (debounce 1,2 s), dan biarkan
      // persistDraft membuang drafnya sendiri bila formulirnya ternyata bersih. Dialog "Buang
      // isian" (ui.js) memanggil onClose() tanpa argumen → membuang, sesuai kalimatnya.
      if (keepDraft) { persistDraft(); return; }
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

  /*
   * Ringkasan galat di atas formulir (UX-E3.4, README E3 / ERRATA E-6 + A-3): satu baris
   * per galat — pemeriksaan wajib-isi di klien maupun 422 server — yang melompat ke
   * kolom atau sel barisnya. Tanda merah per kolom tetap; fokus ke galat pertama tetap
   * (dan kini juga untuk kunci KEPALA dari 422, yang dulu hanya dilukis tanpa gulir
   * maupun fokus — pembaca reader E3). Dirakit primitif errorSummary() (ui.js),
   * dipasang sebagai anak PERTAMA badan formulir: di halaman maupun dialog, di dalam
   * .form-page / .modal-body, sehingga pembaca `.form-page .field .err` harness tidak
   * berubah. Kunci yang tidak punya kontrol tampil dengan nama kuncinya tanpa lompatan —
   * toast kunci-tak-terpetakan tetap menyebutnya (aturan di paintErrors).
   */
  let summaryNode = null;
  function clearSummary() {
    if (summaryNode) summaryNode.remove();
    summaryNode = null;
  }
  function resolveControl(fieldKey) {
    const match = /^([A-Za-z_]\w*)\.(\d+)\.(\w+)$/.exec(fieldKey);
    if (match) {
      const line = lineControls.find(({ def: lineDef }) => lineDef.key === match[1]);
      const entry = line && line.control.entries()[Number(match[2])];
      const cell = entry && entry.inputs[match[3]];
      return cell ? (cell.input || cell.node) : null;
    }
    const control = controls[fieldKey.split('.')[0]];
    return control && !hiddenKeys.has(fieldKey.split('.')[0]) ? (control.input || control.node) : null;
  }
  function focusField(fieldKey) {
    const target = resolveControl(fieldKey);
    if (!target) return;
    const fold = target.closest('details');
    if (fold) fold.toggleAttribute('open', true);
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    target.focus({ preventScroll: true });
  }
  function labelFor(fieldKey) {
    const match = /^([A-Za-z_]\w*)\.(\d+)\.(\w+)$/.exec(fieldKey);
    if (match) {
      const line = lineControls.find(({ def: lineDef }) => lineDef.key === match[1]);
      const column = line && line.def.columns.find((one) => one.key === match[3]);
      return line ? `${line.def.label} · baris ${Number(match[2]) + 1} · ${column ? column.label : match[3]}` : fieldKey;
    }
    const found = fieldControls.find(({ spec }) => spec.key === fieldKey.split('.')[0]);
    return found ? found.spec.label : fieldKey;
  }
  function showSummary(items) {
    clearSummary();
    if (!items.length) return;
    summaryNode = errorSummary(items, { onJump: (fieldKey) => focusField(fieldKey) });
    body.prepend(summaryNode);
  }

  /*
   * Satu jalan simpan untuk dua tombol: `trigger` yang sibuk, `afterSave` yang (bila ada)
   * menggantikan finish() sesudah dokumen tersimpan. Tombol kembarannya dinonaktifkan
   * selama berjalan — withBusy hanya mengunci tombol yang diklik (ui.js), dan dua POST
   * pembuatan berarti dua dokumen bernomor (tidak ada idempotensi di server).
   */
  const attempt = async (trigger, afterSave) => {
    const siblings = [save, saveSubmit].filter((node) => node && node !== trigger);
    siblings.forEach((node) => { node.disabled = true; });
    try {
      await attemptSave(trigger, afterSave);
    } finally {
      siblings.forEach((node) => { node.disabled = false; });
    }
  };
  save.addEventListener('click', () => attempt(save, null));
  if (saveSubmit) saveSubmit.addEventListener('click', () => attempt(saveSubmit, submitAfterSave));

  /*
   * Simpan & ajukan, babak kedua (ERRATA E-6): dokumen SUDAH tersimpan — `saved` ada —
   * lalu aksi submit lewat mesin jawaban yang sama dengan Ajukan (withConfirmResubmit:
   * prakualifikasi, kendali harga, gate anggaran). Ditolak server, atau dialognya
   * dibatalkan: pergi ke halaman dokumennya dan katakan dengan kalimat server —
   * "<kode> tersimpan sebagai draf — belum diajukan: <alasan>" — dan JANGAN pernah
   * mengulang POST pembuatan. Alasan disusun dari pesan pertama tiap kunci (seperti
   * actions.js), bukan error.message yang Laravel beri ekor "(and N more errors)"
   * berbahasa Inggris (S10).
   */
  async function submitAfterSave(saved) {
    invalidateByPath(def.api);
    removeDraft(key, draftRowId);
    const code = (saved && saved.code) || def.labelOne;
    try {
      const result = await submit.run(saved);
      if (result === undefined) {
        toast(`${code} tersimpan sebagai draf — belum diajukan: pengajuan dibatalkan.`, { tone: 'warn', timeout: 0 });
      } else {
        submit.done(code, result);
      }
    } catch (error) {
      const reason = error.errors
        ? Object.values(error.errors).map((messages) => [].concat(messages)[0]).join(' ')
        : (error.message || 'server menolak pengajuan.');
      toast(`${code} tersimpan sebagai draf — belum diajukan: ${reason}`, { tone: 'warn', timeout: 0 });
    }
    dialog.close();
    if (onSaved) onSaved(saved);
  }

  async function attemptSave(trigger, afterSave) {
    // Bersihkan penanda putaran sebelumnya dulu — kegagalan lama tidak boleh
    // menumpuk dengan hasil putaran ini.
    body.querySelectorAll('.field.invalid').forEach((node) => {
      node.classList.remove('invalid');
      node.querySelectorAll('.err').forEach((err) => err.remove());
    });
    body.querySelectorAll('td.cell-invalid').forEach((cell) => clearCellError(cell));
    clearSummary();
    markSteps();

    /*
     * Wajib-isi diperiksa SEBELUM request — hanya wajib-isi: format sudah
     * dijamin input native date/number, dan aturan bisnis tetap milik server.
     * Baris yang diperiksa adalah baris yang sama persis dengan yang akan
     * dikirim read() (baris kosong tersaring), jadi tidak ada penanda pada
     * baris yang tidak ikut terkirim.
     */
    const invalid = [];
    const summaryItems = [];
    for (const { spec, control } of fieldControls) {
      if (!spec.required) continue;
      // Field yang sedang tersembunyi (visibleWhen) tidak dikirim, jadi
      // wajib-isinya juga tidak berlaku — ia bukan field yang belum diisi.
      if (hiddenKeys.has(spec.key)) continue;
      const value = control.read();
      if (value === null || value === '') {
        setFieldError(control.input || control.node, 'Wajib diisi.');
        invalid.push(control);
        summaryItems.push({ field: spec.key, label: spec.label, message: 'Wajib diisi.' });
      }
    }
    for (const { def: lineDef, control } of lineControls) {
      control.entries().forEach((entry, index) => {
        for (const column of lineDef.columns) {
          if (!column.required) continue;
          const cell = entry.inputs[column.key];
          const value = cell.read();
          if (value === null || value === '') {
            setCellError(cell, 'Wajib diisi.');
            invalid.push(cell);
            const fieldKey = `${lineDef.key}.${index}.${column.key}`;
            summaryItems.push({ field: fieldKey, label: labelFor(fieldKey), message: 'Wajib diisi.' });
          }
        }
      });
    }
    if (invalid.length) {
      showSummary(summaryItems);
      markSteps();
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

    await withBusy(trigger, async () => {
      const send = () => (isEdit
        ? api.put(`${def.api}/${record.id}`, payload)
        : api.post(endpoint || def.api, payload));
      const settle = (saved) => (afterSave ? afterSave(saved) : finish(saved));

      const finish = (saved) => {
        invalidateByPath(def.api);
        removeDraft(key, draftRowId);
        // Lewat endpoint lain, dokumennya bisa dibuat ATAU dilengkapi (cangkang
        // Tandai Menang, T3.6) — "dibuat" akan berbohong pada separuh kasus;
        // nomor dokumennya yang jadi subjek, dan "tersimpan" benar di keduanya.
        toast(endpoint && saved && saved.code
          ? `${saved.code} tersimpan.`
          : `${def.labelOne} ${isEdit ? 'diperbarui' : 'dibuat'}.`);
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
        const items = [];
        let firstPainted = null;
        for (const [fieldKey, messages] of Object.entries(error.errors)) {
          const message = [].concat(messages)[0];
          if (applyLineError(fieldKey, message, scrolled)) {
            items.push({ field: fieldKey, label: labelFor(fieldKey), message });
            firstPainted = firstPainted || fieldKey;
            continue;
          }
          const headKey = fieldKey.split('.')[0];
          const control = controls[headKey];
          if (control && !hiddenKeys.has(headKey)) {
            setFieldError(control.input || control.node, message);
            items.push({ field: fieldKey, label: labelFor(fieldKey), message });
            firstPainted = firstPainted || fieldKey;
            continue;
          }
          unmapped.push(`${fieldKey}: ${message}`);
          items.push({ field: fieldKey, label: fieldKey, message });
        }
        showSummary(items);
        markSteps();
        // Galat 422 pertama yang dilukis difokuskan seperti galat wajib-isi (UX-E3.4);
        // dulu kunci kepala hanya dilukis, tanpa gulir dan fokus.
        if (firstPainted) focusField(firstPainted);
        if (!unmapped.length) {
          toast('Periksa isian yang ditandai.', { tone: 'err' });
          return;
        }
        const painted = unmapped.length < Object.keys(error.errors).length;
        toastError({ message: painted ? 'Periksa isian yang ditandai.' : error.message, details: unmapped });
      };

      try {
        await settle(await send());
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
            await settle(await send());
          } catch (retryError) {
            paintErrors(retryError);
          }
          return;
        }
        paintErrors(error);
      }
    });
  }
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
