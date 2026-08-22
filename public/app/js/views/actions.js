/* Lifecycle actions (submit / approve / post / assign / …) declared in schema.js. */

import { api, session } from '../api.js';
import { button, toast, toastError, confirmDialog, withBusy } from '../ui.js';
import { invalidateByPath } from '../lookup.js';
import { promptFields } from './form.js';
import { navigate } from '../router.js';

/** Run one action against a row (or the collection when `row` is null). */
export async function runAction(action, row, def, { trigger, onDone } = {}) {
  const path = row
    ? `${def.api}/${String(action.path).replace('{id}', row.id)}`
    : `${def.api}/${action.path}`;

  let payload = {};

  if (action.fields && action.fields.length) {
    const values = await promptFields(action.label, action.fields, { submitLabel: action.label });
    if (values === null) return;
    payload = values;
  } else if (action.confirm) {
    const ok = await confirmDialog({
      title: action.label,
      message: action.confirm,
      confirmLabel: action.label,
      tone: action.variant === 'danger' ? 'danger' : 'primary',
    });
    if (!ok) return;
  }

  const call = async () => {
    const result = await api[action.method === 'PUT' ? 'put' : 'post'](path, payload);
    invalidateByPath(def.api);
    toast(`${action.label} berhasil.`);

    if (action.navigateTo && result && result.id) {
      navigate(`d/${action.navigateTo}/${result.id}`);
    } else if (action.navigateToResult && result && result.id) {
      navigate(`d/${def.apiKey || keyOf(def)}/${result.id}`);
    } else if (onDone) {
      onDone(result);
    }
  };

  /*
   * Alur konfirmasi-lanjut untuk AKSI — cermin def.form.confirmResubmit di
   * form.js (GRN harga 0, temuan #72): server menolak 422 pada kunci galat
   * yang cocok `test` sampai payload membawa `flag`. Ajukan PO memakai DUA
   * aturan (kendali harga #34: items.N.unit_price, lalu gate anggaran #33:
   * budget) yang bisa muncul BERURUTAN, jadi mesinnya berputar — satu putaran
   * mengonfirmasi satu jenis peringatan, lalu mencoba lagi. Hanya berjalan
   * bila SEMUA kunci galat cocok satu aturan: galat campuran berarti ada yang
   * tetap ditolak walau dikonfirmasi, jadi ditampilkan biasa saja.
   */
  const rules = [].concat(action.confirmResubmit || []);

  const run = async () => {
    for (let attempt = 0; attempt <= rules.length; attempt += 1) {
      try {
        return await call();
      } catch (error) {
        const keys = error.errors ? Object.keys(error.errors) : [];
        const rule = rules.find((candidate) => !payload[candidate.flag]
          && keys.length && keys.every((key) => candidate.test.test(key)));
        if (!rule) throw error;

        const ok = await confirmDialog({
          title: rule.title,
          message: keys.map((key) => [].concat(error.errors[key])[0]).join(' '),
          confirmLabel: rule.confirmLabel,
        });
        if (!ok) return;
        payload[rule.flag] = true;
      }
    }
  };

  try {
    if (trigger) await withBusy(trigger, run);
    else await run();
  } catch (error) {
    toastError(error);
  }
}

function keyOf(def) {
  return def.api;
}

/** Buttons for every action available on this row, permission- and state-gated. */
export function actionButtons(def, row, onDone) {
  return (def.actions || [])
    .filter((action) => session.can(action.perm))
    .filter((action) => !action.when || action.when(row))
    .map((action) =>
      button(action.label, {
        variant: action.variant || '',
        onClick: (event) => runAction(action, row, def, { trigger: event.currentTarget, onDone }),
      }));
}
