/* Lifecycle actions (submit / approve / post / assign / …) declared in schema.js. */

import { api, session } from '../api.js';
import { el, icon, field, button, toast, toastError, confirmDialog, withBusy } from '../ui.js';
import { invalidateByPath } from '../lookup.js';
import { promptFields, buildInput, openForm } from './form.js';
import { navigate } from '../router.js';
import { RESOURCES } from '../schema.js';

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

/*
 * Catatan persetujuan INLINE di bilah aksi, bukan modal. Diukur 2 Sep 2026
 * (HASIL-UJI §1, S2): satu persetujuan = Setujui → modal "Catatan persetujuan"
 * → Setujui lagi → Buka, 3 klik per dokumen, dan modal itu ada untuk isian
 * yang boleh kosong — penyetuju 15 dokumen sehari menutup 15 modal kosong.
 * Kini Setujui langsung memutus (2 klik); yang ingin menulis catatan membuka
 * "Tambah catatan" lebih dulu (3 klik — sama dengan jalur modal dulu, tanpa
 * modal). <details>/<summary>, bukan tombol: pelipat bawaan peramban yang
 * dijangkau keyboard (Enter/Spasi) dan menyatakan terbuka/tertutupnya
 * sendiri, dan bilah aksi tidak bertambah satu tombol lagi. Menutupnya
 * MENGOSONGKAN isian — teks tersembunyi tidak boleh ikut terkirim
 * diam-diam, dan label "Batalkan catatan" mengatakan akibatnya. Tolak tetap
 * lewat modal: alasannya wajib, promptFields yang menahannya ("Wajib
 * diisi."). Payload ke server persis seperti dulu: { note } bila terisi.
 */
const NOTE_OPEN = 'Tambah catatan';
const NOTE_CLOSE = 'Batalkan catatan';

function inlineNote(action, row) {
  const spec = action.inlineNote;
  // Textarea yang sama dengan form/modal: read() memulangkan null untuk isian
  // kosong atau spasi saja, jadi catatan "   " tidak pernah sampai ke server.
  const control = buildInput({ ...spec, type: 'textarea', rows: 2 }, undefined);
  const caption = el('span', { text: NOTE_OPEN });
  const summary = el('summary', [icon('plus', 13), caption]);
  const panel = el('details.action-note', [
    summary,
    field(spec.label, control.node, { help: `Opsional. Ikut tersimpan pada riwayat persetujuan ${row.code || 'dokumen ini'}.` }),
  ]);
  panel.addEventListener('toggle', () => {
    summary.replaceChild(icon(panel.open ? 'close' : 'plus', 13), summary.firstChild);
    caption.textContent = panel.open ? NOTE_CLOSE : NOTE_OPEN;
    if (panel.open) control.node.focus();
    else control.node.value = '';
  });
  return {
    node: panel,
    read: () => {
      const value = control.read();
      return value === null ? {} : { [spec.key]: value };
    },
  };
}

/**
 * Run one action against a row (or the collection when `row` is null).
 * `inline` — jawaban yang sudah ada di layar (catatan persetujuan inline),
 * dipanggil saat tombol ditekan dan digabung ke payload tanpa dialog.
 */
export async function runAction(action, row, def, { trigger, onDone, inline } = {}) {
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

  if (inline) Object.assign(payload, inline());

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
   * yang cocok `test` sampai payload membawa jawabannya. Dua bentuk jawaban:
   * `flag` (boolean — dialog Ya/Batal) dan `promptField` (satu isian wajib,
   * dikirim di bawah kuncinya sendiri). Bentuk kedua lahir untuk alasan
   * override prakualifikasi PO (#35): sebelumnya Ajukan membawa `fields` dan
   * membuka modal alasan pada SETIAP pengajuan, padahal alasannya hanya
   * berarti bila server menolak — diukur 2 Sep 2026 (HASIL-UJI §1, S3):
   * 12 klik buat→ajukan, dua di antaranya modal yang dikosongkan untuk
   * vendor sehat. Ajukan PO kini memakai TIGA aturan (prakualifikasi #35:
   * qualification_override_reason, kendali harga #34: items.N.unit_price,
   * gate anggaran #33: budget) yang bisa muncul BERURUTAN, jadi mesinnya
   * berputar — satu putaran menjawab satu jenis penolakan, lalu mencoba
   * lagi. Hanya berjalan bila SEMUA kunci galat cocok satu aturan: galat
   * campuran berarti ada yang tetap ditolak walau dijawab, jadi ditampilkan
   * biasa saja.
   */
  const rules = [].concat(action.confirmResubmit || []);
  const answerKey = (rule) => (rule.promptField ? rule.promptField.key : rule.flag);

  const run = async () => {
    for (let attempt = 0; attempt <= rules.length; attempt += 1) {
      try {
        return await call();
      } catch (error) {
        const keys = error.errors ? Object.keys(error.errors) : [];
        const rule = rules.find((candidate) => payload[answerKey(candidate)] === undefined
          && keys.length && keys.every((key) => candidate.test.test(key)));
        if (!rule) throw error;

        // Pesan server apa adanya: dialah yang menyebut vendor dan penyebab
        // blokirnya, atau angka harga/anggaran yang diminta dikonfirmasi.
        const message = keys.map((key) => [].concat(error.errors[key])[0]).join(' ');

        if (rule.promptField) {
          const values = await promptFields(rule.title, [rule.promptField], {
            submitLabel: rule.confirmLabel, message,
          });
          if (values === null) return;
          payload[rule.promptField.key] = values[rule.promptField.key];
        } else {
          const ok = await confirmDialog({ title: rule.title, message, confirmLabel: rule.confirmLabel });
          if (!ok) return;
          payload[rule.flag] = true;
        }
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

/**
 * Buttons for every action available on this row, permission- and state-gated.
 * Panel catatan inline (inlineNote) ikut dipulangkan DI BELAKANG semua tombol:
 * app.css memberinya flex-basis 100%, jadi ia menjadi baris sendiri di bawah
 * bilah — diselipkan di posisi aksinya, Tolak akan terlempar ke bawah panel.
 */
export function actionButtons(def, row, onDone) {
  const panels = [];
  const buttons = (def.actions || [])
    .filter((action) => session.can(action.perm))
    .filter((action) => !action.when || action.when(row))
    .map((action) => {
      /*
       * `opens`: aksi yang MEMBUKA formulir buat resource lain dengan isian
       * dari dokumen ini, bukan POST ke server — bentuk rowAction "Tagih
       * termin ini" (detail.js) diangkat ke bilah aksi untuk "Buat
       * pembayaran" pada tagihan vendor yang disetujui (T3.1: BIL/2026/VII/0002
       * 69 hari lewat jatuh tempo tanpa PAY). Tersimpan = pindah ke dokumen
       * barunya, seperti navigateTo pada aksi POST.
       */
      if (action.opens) {
        return button(action.label, {
          variant: action.variant || '',
          onClick: () => openForm({
            def: RESOURCES[action.opens],
            key: action.opens,
            prefill: action.prefill ? action.prefill(row) : null,
            onSaved: (saved) => (saved && saved.id ? navigate(`d/${action.opens}/${saved.id}`) : onDone && onDone(saved)),
          }),
        });
      }
      const note = action.inlineNote ? inlineNote(action, row) : null;
      if (note) panels.push(note.node);
      return button(action.label, {
        variant: action.variant || '',
        onClick: (event) => runAction(action, row, def, { trigger: event.currentTarget, onDone, inline: note ? note.read : null }),
      });
    });
  return [...buttons, ...panels];
}
