/* Lifecycle actions (submit / approve / post / assign / …) declared in schema.js. */

import { api, session } from '../api.js';
import { el, button, toast, toastError, confirmDialog, withBusy } from '../ui.js';
import { invalidateByPath } from '../lookup.js';
import { promptFields } from './form.js';
import { navigate } from '../router.js';

const PAST = {
  submit: 'diajukan · menunggu persetujuan', approve: 'disetujui', reject: 'ditolak', post: 'diposting',
  close: 'ditutup', cancel: 'dibatalkan', activate: 'diaktifkan', reopen: 'dibuka kembali',
};

/*
 * Setelah memutus satu dokumen, tunjukkan yang berikutnya di antrean — tanpa
 * kembali ke dasbor (18 permintaan) dan mencari barisnya lagi. Satu permintaan
 * ke core/inbox; gagal = tidak ada tawaran, bukan galat.
 */
async function offerNext(justDecided) {
  try {
    const rows = (await api.get('core/inbox')) || [];
    const next = rows.find((r) => r.code !== justDecided);
    if (!next) { toast('Kotak masuk persetujuan Anda kosong.', { tone: 'info', timeout: 4000 }); return; }
    const node = toast(`${next.code} · ${next.label}${next.amount ? ` · Rp ${Number(next.amount).toLocaleString('id-ID')}` : ''}`, {
      tone: 'info', title: `Berikutnya menunggu Anda (${rows.length})`, timeout: 12000,
    });
    node.querySelector('.msg').appendChild(el('.row-actions', { style: { marginTop: '8px' } }, [
      button('Buka', { size: 'sm', variant: 'primary', onClick: () => { node.remove(); navigate(next.link.replace(/^#\//, '')); } }),
      rows.length > 1 ? button('Semua tugas', { size: 'sm', variant: 'ghost', onClick: () => { node.remove(); navigate('tugas'); } }) : null,
    ]));
  } catch { /* tawaran, bukan kewajiban */ }
}

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
    /* Nomor dokumen sebagai subjek kalimat, bukan "Ajukan berhasil.": toast
       adalah satu-satunya jejak yang dilihat orang setelah tombolnya hilang. */
    const code = (result && result.code) || (row && row.code) || '';
    const said = PAST[action.key];
    toast(code && said ? `${code} ${said}.` : `${action.label} berhasil.`);
    if (['approve', 'reject'].includes(action.key)) offerNext(code);

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
