/* Settings screen: every editable ERP parameter from the Core registry.

   The server owns the catalogue — groups, labels, types, defaults and which
   parameters are currently overridden all come from GET core/settings, so this
   view never hard-codes a key. Saving sends only the parameters the operator
   actually touched; a null value resets one back to its shipped default. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, emptyState, errorState, setFieldError, toast, toastError, withBusy } from '../ui.js';
import * as fmt from '../format.js';
import { loadSource } from '../lookup.js';
import { buildInput } from './form.js';

const ACCOUNT_LIST_ID = 'settings-account-codes';
const ROMAN_MONTHS = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
const SEQUENCE_TOKEN = /\{N[345]\}/;

const SNAPSHOT_NOTE = 'Perubahan hanya berlaku untuk dokumen baru. Penawaran, kontrak, PO, SPK dan '
  + 'invoice menyimpan tarif yang berlaku saat dokumen dibuat, sehingga dokumen lama '
  + 'dan jurnal yang sudah diposting tidak ikut berubah.';

const READONLY_NOTE = 'Anda hanya dapat melihat pengaturan. Hak akses "core.update" diperlukan untuk menyimpan perubahan.';

/** Mirrors DocumentNumberService: {Y} {M2} {RM} {N3} {N4} {N5} — dan {PROJ}
    (P8), yang SENGAJA dibiarkan tampak apa adanya di contoh: pratinjau tidak
    punya proyek, dan kode proyek karangan bukan contoh; kalimat penjelasnya
    ditambahkan buildControl. */
export function previewNumber(format, sequence = 1, when = new Date()) {
  const month = when.getMonth() + 1;
  const tokens = {
    '{Y}': String(when.getFullYear()),
    '{M2}': String(month).padStart(2, '0'),
    '{RM}': ROMAN_MONTHS[month - 1],
    '{N3}': String(sequence).padStart(3, '0'),
    '{N4}': String(sequence).padStart(4, '0'),
    '{N5}': String(sequence).padStart(5, '0'),
  };
  return String(format ?? '').replace(/\{(?:Y|M2|RM|N3|N4|N5)\}/g, (token) => tokens[token]);
}

/** Human-readable rendering of a stored value, used for the "Bawaan: …" hint. */
function displayValue(setting, value) {
  if (value === null || value === undefined || value === '') return '—';

  switch (setting.type) {
    case 'percent':
      return fmt.percent(value, { decimals: 4 });
    case 'currency':
      return fmt.rupiah(value);
    case 'boolean':
      return value ? 'Aktif' : 'Nonaktif';
    case 'select': {
      const option = (setting.options || []).find((candidate) => String(candidate.value) === String(value));
      return option ? option.label : String(value);
    }
    default:
      return String(value);
  }
}

/** Loose equality across the string/number shapes the API and the DOM produce. */
function sameValue(a, b) {
  const emptyA = a === null || a === undefined || a === '';
  const emptyB = b === null || b === undefined || b === '';
  if (emptyA || emptyB) return emptyA && emptyB;
  if (typeof a === 'boolean' || typeof b === 'boolean') return Boolean(a) === Boolean(b);
  if (typeof a === 'number' || typeof b === 'number') return Number(a) === Number(b);
  return String(a) === String(b);
}

/**
 * One control per registry type.
 * Returns { node, input, read, write, extra? } — `input` is the element that
 * carries validation errors, `extra` an optional live hint under the control.
 */
function buildControl(setting, ctx) {
  const value = setting.value === null || setting.value === undefined ? '' : setting.value;

  switch (setting.type) {
    case 'percent':
    case 'currency': {
      const control = buildInput({ type: setting.type, min: setting.min, max: setting.max }, value);
      return {
        node: control.node,
        input: control.input,
        read: control.read,
        /*
         * Prefer the control's own write() — currency is money.js now, a text
         * input whose read() strips '.' as the id-ID thousands separator.
         * Assigning a raw Number to input.value writes its JS form
         * ('12500000.5'), which read() then parses back as 125000005: both
         * "Batalkan perubahan" and "Kembalikan ke bawaan" would silently
         * multiply a fractional parameter by ten. percent is still a plain
         * number input with no write(), and takes the value as-is.
         */
        write: (next) => {
          if (control.write) control.write(next);
          else control.input.value = next === null || next === undefined ? '' : next;
        },
      };
    }

    case 'integer': {
      const control = buildInput({
        type: 'number', step: '1', min: setting.min ?? 0, max: setting.max ?? 1000000,
      }, value);
      return {
        node: control.node,
        input: control.node,
        read: control.read,
        write: (next) => { control.node.value = next === null || next === undefined ? '' : next; },
      };
    }

    case 'boolean': {
      const input = el('input', { type: 'checkbox' });
      input.checked = Boolean(setting.value);
      return {
        node: el('.check-row', [input, el('label', { text: 'Aktif' })]),
        input,
        read: () => input.checked,
        write: (next) => { input.checked = Boolean(next); },
      };
    }

    case 'select': {
      const control = buildInput({ type: 'select', options: setting.options || [] }, value);
      return {
        node: control.node,
        input: control.node,
        read: control.read,
        write: (next) => { control.node.value = next === null || next === undefined ? '' : String(next); },
      };
    }

    case 'account': {
      const input = el('input', {
        type: 'text', class: 'mono', maxlength: 20, autocomplete: 'off', spellcheck: 'false', placeholder: 'mis. 1-1310',
      });
      input.value = setting.value ?? '';
      if (ctx.accountListId) input.setAttribute('list', ctx.accountListId);

      const hint = el('.help');
      const sync = () => {
        const code = input.value.trim();
        hint.style.color = '';
        if (!code) {
          hint.textContent = 'Kosongkan untuk memakai akun bawaan.';
          return;
        }
        if (!ctx.accounts.length) {
          hint.textContent = '';
          return;
        }
        const account = ctx.accountMap.get(code);
        if (account) {
          hint.textContent = account.name || '';
        } else {
          hint.textContent = 'Kode akun tidak ada di bagan akun atau bukan akun yang dapat diposting.';
          hint.style.color = 'var(--danger)';
        }
      };
      input.addEventListener('input', sync);
      sync();

      return {
        node: input,
        input,
        read: () => (input.value.trim() === '' ? null : input.value.trim()),
        write: (next) => { input.value = next ?? ''; sync(); },
        extra: hint,
      };
    }

    case 'document_format': {
      const input = el('input', {
        type: 'text', class: 'mono', maxlength: 60, autocomplete: 'off', spellcheck: 'false',
      });
      input.value = setting.value ?? '';

      const hint = el('.help');
      const sync = () => {
        const format = input.value.trim();
        hint.style.color = '';
        if (!format) {
          hint.textContent = 'Kosongkan untuk memakai format bawaan.';
          return;
        }
        if (!SEQUENCE_TOKEN.test(format)) {
          hint.textContent = 'Format wajib memuat nomor urut {N3}, {N4} atau {N5}.';
          hint.style.color = 'var(--danger)';
          return;
        }
        hint.textContent = `Contoh nomor berikutnya: ${previewNumber(format)}`;
        if (format.includes('{PROJ}')) {
          // P8 — {PROJ} membelah urutan per proyek. Dua fakta yang harus
          // terbaca SEBELUM disimpan: dari mana kodenya, dan bahwa jenis
          // dokumen tanpa proyek menolak menerbitkan nomor (gagal keras,
          // bukan mencetak kosong).
          hint.textContent += ' — {PROJ} diisi kode proyek dokumennya dan nomor urut berjalan '
            + 'terpisah per proyek; dokumen tanpa proyek menolak menerbitkan nomor bermask {PROJ}.';
        }
      };
      input.addEventListener('input', sync);
      sync();

      return {
        node: input,
        input,
        read: () => (input.value.trim() === '' ? null : input.value.trim()),
        write: (next) => { input.value = next ?? ''; sync(); },
        extra: hint,
      };
    }

    default: {
      const input = el('input', { type: 'text', maxlength: 255 });
      input.value = setting.value ?? '';
      return {
        node: input,
        input,
        read: () => (input.value.trim() === '' ? null : input.value.trim()),
        write: (next) => { input.value = next ?? ''; },
      };
    }
  }
}

/** One field: caption + badges + reset link, the control, then its hints. */
function buildEntry(setting, ctx) {
  const control = buildControl(setting, ctx);
  const input = control.input || control.node;
  const wrapper = el('.field');

  const pending = badge('Belum disimpan', 'amber');
  pending.classList.add('hidden');

  const entry = {
    key: setting.key,
    setting,
    control,
    input,
    wrapper,
    pending,
    loaded: setting.value,
    reset: false,
  };

  const resetButton = button('Kembalikan ke bawaan', {
    size: 'sm',
    variant: 'ghost',
    title: `Bawaan: ${displayValue(setting, setting.default)}`,
    onClick: () => {
      control.write(setting.default);
      setFieldError(input, '');
      entry.reset = true;
      ctx.refresh();
    },
  });
  resetButton.style.marginLeft = 'auto';

  wrapper.appendChild(el('label', { style: { display: 'flex', alignItems: 'center', gap: '7px', flexWrap: 'wrap' } }, [
    el('span', { text: setting.label }),
    setting.is_overridden ? badge('Diubah', 'primary') : null,
    pending,
    ctx.canEdit && setting.is_overridden ? resetButton : null,
  ]));

  wrapper.appendChild(control.node);
  if (control.extra) wrapper.appendChild(control.extra);
  if (setting.help) wrapper.appendChild(el('.help', { text: setting.help }));
  wrapper.appendChild(el('.help', { text: `Bawaan: ${displayValue(setting, setting.default)}` }));

  const onEdit = () => {
    entry.reset = false;
    setFieldError(input, '');
    ctx.refresh();
  };
  input.addEventListener('input', onEdit);
  input.addEventListener('change', onEdit);

  if (!ctx.canEdit) {
    wrapper.querySelectorAll('input, select, textarea').forEach((node) => { node.disabled = true; });
  }

  return entry;
}

/** Only the parameters the operator actually changed, keyed by dotted path. */
function collectChanges(entries) {
  const changed = {};

  for (const entry of entries) {
    if (entry.reset) {
      changed[entry.key] = null;
      continue;
    }
    const value = entry.control.read();
    if (!sameValue(value, entry.loaded)) changed[entry.key] = value;
  }

  return changed;
}

/** Map "settings.tax.ppn_rate" style validation errors back onto the controls. */
function applyFieldErrors(entries, error) {
  if (!error || !error.errors) return;

  const byKey = new Map(entries.map((entry) => [entry.key, entry]));
  let first = null;

  for (const [path, messages] of Object.entries(error.errors)) {
    const key = path.startsWith('settings.') ? path.slice('settings.'.length) : path;
    const entry = byKey.get(key);
    if (!entry) continue;
    setFieldError(entry.input, [].concat(messages)[0]);
    if (!first) first = entry;
  }

  if (first) first.wrapper.scrollIntoView({ block: 'center' });
}

async function loadPostableAccounts() {
  // Account pickers are finance data; without fin.view the field stays a plain input.
  if (!session.can('fin.view')) return [];
  try {
    return await loadSource('postableAccounts');
  } catch {
    return [];
  }
}

/* P8 — riwayat tarif PPN & PPh final (D5), kartu baca-saja di kaki layar
   Pengaturan: setiap penyimpanan tarif pajak lewat layar ini juga dicatat
   RateHistoryService, dan di sinilah catatannya dibaca — di samping tarif yang
   dicatatnya, bukan sebagai layar sendiri. Dimuat saat diminta, bukan saat
   layar terbuka: riwayat adalah bacaan sesekali, dan kegagalannya tidak boleh
   menumbangkan layar pengaturan. */
function rateHistoryCard() {
  const body = el('.card-body');
  const intro = el('p.muted', {
    style: { margin: '0 0 10px', fontSize: '12.5px' },
    text: 'Riwayat ini catatan semata — tidak ada angka dokumen yang dihitung ulang darinya; '
      + 'snapshot per dokumen tetap sumber kebenaran. Baris ditulis otomatis setiap tarif '
      + 'PPN / PPh final disimpan dari layar ini.',
  });

  const loadButton = button('Muat riwayat', {
    size: 'sm',
    onClick: (event) => load(event.currentTarget),
  });

  async function load(trigger) {
    let entries;
    try {
      await withBusy(trigger, async () => {
        entries = await api.get('core/rate-history', { per_page: 50 });
      });
    } catch (error) {
      toastError(error);
      return;
    }

    const rows = Array.isArray(entries) ? entries : [];
    clear(body).appendChild(intro);

    if (!rows.length) {
      body.appendChild(el('p.muted', { text: 'Belum ada perubahan tarif yang tercatat.', style: { margin: '0' } }));
      return;
    }

    body.appendChild(el('.table-wrap', el('table.data', [
      el('thead', el('tr', [
        el('th', { text: 'Waktu' }),
        el('th', { text: 'Parameter' }),
        el('th.right', { text: 'Dari' }),
        el('th.right', { text: 'Menjadi' }),
        el('th', { text: 'Oleh' }),
      ])),
      el('tbody', rows.map((row) => el('tr', [
        el('td', { text: fmt.dateTime(row.created_at) }),
        el('td.mono', { text: row.rate_key }),
        // Tarif pertama yang tercatat tidak punya "dari" — strip kosong,
        // bukan 0%: nol adalah tarif, ketiadaan bukan.
        el('td.right.num', { text: row.old_rate === null ? '—' : fmt.percent(row.old_rate, { decimals: 4 }) }),
        el('td.right.num', { text: row.new_rate === null ? '—' : fmt.percent(row.new_rate, { decimals: 4 }) }),
        el('td', { text: row.changed_by_name || '—' }),
      ]))),
    ])));
  }

  body.append(intro, loadButton);

  return el('.card', [
    el('.card-head', [
      el('h2', { text: 'Riwayat Tarif PPN & PPh Final' }),
      el('.spacer'),
      el('span.muted', { text: 'baca-saja', style: { fontSize: '12px' } }),
    ]),
    body,
  ]);
}

/* ======================================================== PENGATURAN === */
export async function renderSettings(host) {
  clear(host);

  const canEdit = session.can('core.update');
  const entries = [];
  const ctx = {
    canEdit,
    accounts: [],
    accountMap: new Map(),
    accountListId: '',
    datalist: null,
    refresh: () => refresh(),
  };

  const body = el('div', { style: { marginTop: '14px' } });
  // Dibangun SEKALI di luar paint(): paint() membersihkan body setiap simpan,
  // dan node yang sama dipasang ulang supaya riwayat yang sudah dimuat tidak
  // hilang hanya karena satu parameter lain disimpan.
  const historyCard = session.can('core.view') ? rateHistoryCard() : null;
  const status = el('span.muted', { text: 'Belum ada perubahan.', style: { fontSize: '12.5px' } });

  const revert = button('Batalkan perubahan', {
    disabled: true,
    onClick: () => {
      entries.forEach((entry) => {
        entry.reset = false;
        entry.control.write(entry.loaded);
        setFieldError(entry.input, '');
      });
      refresh();
      toast('Perubahan dibatalkan.', { tone: 'info', timeout: 2600 });
    },
  });

  const save = button('Simpan Perubahan', { variant: 'primary', disabled: true });

  const saveBar = el('.card', {
    style: {
      position: 'sticky', bottom: '0', zIndex: '5', marginTop: '16px',
      display: 'flex', alignItems: 'center', gap: '10px',
      padding: '11px 14px', flexWrap: 'wrap',
    },
  }, [status, el('span', { style: { flex: '1' } }), revert, save]);

  function refresh() {
    const changed = canEdit ? collectChanges(entries) : {};
    const count = Object.keys(changed).length;

    entries.forEach((entry) =>
      entry.pending.classList.toggle('hidden', !Object.prototype.hasOwnProperty.call(changed, entry.key)));

    save.disabled = !canEdit || count === 0;
    revert.disabled = !canEdit || count === 0;

    if (!canEdit) status.textContent = 'Mode baca saja — perubahan tidak dapat disimpan.';
    else if (!count) status.textContent = 'Belum ada perubahan.';
    else status.textContent = `${count} parameter akan disimpan.`;
  }

  function paint(groups) {
    entries.length = 0;
    clear(body);

    if (ctx.datalist) body.appendChild(ctx.datalist);

    if (!groups.length) {
      body.appendChild(el('.card', emptyState('Tidak ada parameter yang dapat diubah.')));
      if (historyCard) body.appendChild(historyCard);
      refresh();
      return;
    }

    for (const group of groups) {
      const settings = group.settings || [];
      const grid = el('.form-grid');

      for (const setting of settings) {
        const entry = buildEntry(setting, ctx);
        entries.push(entry);
        grid.appendChild(entry.wrapper);
      }

      body.appendChild(el('.card', [
        el('.card-head', [
          el('h2', { text: group.label }),
          el('.spacer'),
          el('span.muted', { text: `${settings.length} parameter`, style: { fontSize: '12px' } }),
        ]),
        el('.card-body', [
          group.description ? el('p.muted', { text: group.description, style: { margin: '0 0 14px', fontSize: '12.5px' } }) : null,
          grid,
        ]),
      ]));
    }

    if (historyCard) body.appendChild(historyCard);

    refresh();
  }

  save.addEventListener('click', async () => {
    const changed = collectChanges(entries);
    const count = Object.keys(changed).length;
    if (!count) return;

    entries.forEach((entry) => setFieldError(entry.input, ''));

    await withBusy(save, async () => {
      try {
        const payload = await api.put('core/settings', { settings: changed });
        toast(`${count} parameter disimpan. Berlaku untuk dokumen baru.`);
        paint(payload && Array.isArray(payload.groups) ? payload.groups : []);
      } catch (error) {
        applyFieldErrors(entries, error);
        toastError(error);
      }
    });

    // withBusy re-enables the button on its way out; recompute the real state.
    refresh();
  });

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Pengaturan Sistem' }),
      el('.desc', { text: 'Parameter statutori dan operasional yang dipakai seluruh modul.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderSettings(host) })]),
  ]));

  host.appendChild(el('.alert.info', [icon('warn', 15), el('div', { text: SNAPSHOT_NOTE })]));
  if (!canEdit) host.appendChild(el('.alert.info', [icon('warn', 15), el('div', { text: READONLY_NOTE })]));

  host.append(body, saveBar);
  body.appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '16px' } }))));
  refresh();

  let payload;
  try {
    const [settings, accounts] = await Promise.all([api.get('core/settings'), loadPostableAccounts()]);
    payload = settings;
    ctx.accounts = Array.isArray(accounts) ? accounts.filter((account) => account && account.code) : [];
    ctx.accountMap = new Map(ctx.accounts.map((account) => [String(account.code), account]));
    if (ctx.accounts.length) {
      ctx.accountListId = ACCOUNT_LIST_ID;
      ctx.datalist = el('datalist', { id: ACCOUNT_LIST_ID }, ctx.accounts.map((account) =>
        el('option', { value: account.code, label: account.name || '' })));
    }
  } catch (error) {
    clear(body).appendChild(errorState(error, () => renderSettings(host)));
    return;
  }

  paint(payload && Array.isArray(payload.groups) ? payload.groups : []);
}
