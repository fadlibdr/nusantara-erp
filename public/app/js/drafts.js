/* Draf formulir di peramban.
 *
 * Sesi berumur 12 jam dan tidak ada draf di server; sebelum modul ini, isian
 * yang sedang diketik saat token berakhir hilang tanpa pemulihan, dan panduan
 * pengguna menyuruh orang "Simpan tiap bagian" sebagai gantinya. Diukur 2 Sep
 * 2026: 13 field PO hilang lewat 7 klik.
 *
 * Yang disimpan hanyalah apa yang diketik pengguna (nilai read() setiap
 * kontrol, per resource + id), bukan token dan bukan data server — jadi draf
 * boleh hidup lebih lama daripada sesinya. Kadaluwarsa tujuh hari: draf PO
 * dari bulan lalu adalah kebingungan, bukan pertolongan.
 */

const PREFIX = 'nusantara_erp_draft:';
const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

const flushers = new Set();
let suspended = false;

/* app.js menyalakannya selama penutupan paksa 401, supaya onClose formulir
   tidak membuang draf yang justru sedang diselamatkan. */
export function suspendDraftRemoval(on) { suspended = Boolean(on); }
export function draftRemovalSuspended() { return suspended; }

export function draftKey(resourceKey, rowId) {
  return `${PREFIX}${resourceKey}:${rowId || 'new'}`;
}

export function saveDraft(resourceKey, rowId, data) {
  try {
    localStorage.setItem(draftKey(resourceKey, rowId), JSON.stringify({ ...data, savedAt: Date.now() }));
  } catch { /* kuota penuh atau mode privat — draf adalah bonus, bukan syarat */ }
}

export function loadDraft(resourceKey, rowId) {
  try {
    const raw = localStorage.getItem(draftKey(resourceKey, rowId));
    if (!raw) return null;
    const draft = JSON.parse(raw);
    if (!draft || Date.now() - draft.savedAt > MAX_AGE_MS) { removeDraft(resourceKey, rowId); return null; }
    return draft;
  } catch { return null; }
}

export function removeDraft(resourceKey, rowId) {
  localStorage.removeItem(draftKey(resourceKey, rowId));
}

/** Semua draf yang tersimpan, terbaru dulu — untuk tawaran "Pulihkan" setelah masuk kembali. */
export function listDrafts() {
  const out = [];
  for (let i = 0; i < localStorage.length; i += 1) {
    const k = localStorage.key(i);
    if (!k || !k.startsWith(PREFIX)) continue;
    try {
      const d = JSON.parse(localStorage.getItem(k));
      if (!d || Date.now() - d.savedAt > MAX_AGE_MS) continue;
      const [resourceKey, rowId] = k.slice(PREFIX.length).split(/:(?=[^:]*$)/);
      out.push({ ...d, resourceKey, rowId: rowId === 'new' ? null : rowId });
    } catch { /* abaikan */ }
  }
  return out.sort((a, b) => b.savedAt - a.savedAt);
}

/* Formulir yang terbuka mendaftarkan flush-nya di sini; app.js memanggil
   flushAll() tepat sebelum menutup paksa overlay pada 401, supaya ketikan
   sedetik terakhir (yang masih menunggu debounce) ikut tersimpan. */
export function registerDraftFlush(fn) {
  flushers.add(fn);
  return () => flushers.delete(fn);
}

export function flushAll() {
  flushers.forEach((fn) => { try { fn(); } catch { /* satu form rusak tidak boleh menahan yang lain */ } });
}

export function relativeAge(savedAt) {
  const minutes = Math.round((Date.now() - savedAt) / 60000);
  if (minutes < 1) return 'baru saja';
  if (minutes < 60) return `${minutes} menit lalu`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} jam lalu`;
  return `${Math.round(hours / 24)} hari lalu`;
}
