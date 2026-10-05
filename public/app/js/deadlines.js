/* Pemuat tenggat — SATU pintu untuk GET core/deadlines di layar (UX-F2.2).
 *
 * Beranda ("Perlu tindakan Anda"), beranda modul ("Perlu perhatian") dan layar
 * Tenggat membaca pemindaian yang sama. Tanpa pemuat bersama, tiap layar yang
 * dibuka berturut-turut memindai ulang ±36 entri di server untuk jawaban yang
 * sama — dan Beranda adalah halaman landing, jadi permintaan itu jatuh pada
 * SETIAP masuk. Polanya persis inbox.js: permintaan yang berjalan dibagi,
 * jawaban disimpan MAX_AGE ms dan DIKUNCI pada pengguna yang menanyakannya
 * (tablet lapangan dipakai bergantian), layar Tenggat meminta maxAge: 0, dan Beranda dengan beranda modul
 * memakai ulang jawaban yang berumur ≤ 15 dtk saja — tenggat yang baru diselesaikan lewat "Tindak lanjuti"
 * tidak boleh bertahan semenit di kartu yang sama.
 *
 * Tidak ada gerbang izin di sini: endpoint-nya menyaring per-temuan menurut
 * izin pemanggil (DeadlineController). Pemuat MELEMPAR bila server gagal —
 * endpoint itu tidak punya try/catch per entri, jadi satu kueri yang gagal
 * menjatuhkan semuanya — dan pemanggil yang punya tempat untuk kalimatnya
 * memutuskan; tidak ada pemanggil yang boleh menelannya menjadi daftar kosong
 * ("tidak ada tenggat" untuk "tidak tahu" adalah kabar baik palsu).
 *
 * Widget dasbor tetap memakai safeList (kit.js): ia punya aturan kegagalannya
 * sendiri (DashboardTileFailureTest) dan paketnya UX-F3. */

import { api, session } from './api.js';

const MAX_AGE = 60000;

let cache = { userId: null, at: 0, payload: null };
let inflight = null;

const currentUserId = () => (session.user && session.user.id) || null;
const cacheIsMine = () => cache.payload !== null && cache.userId === currentUserId();

/**
 * Muat tenggat: { data: [temuan], meta: { today, checked, skipped } }.
 * Melempar bila server gagal.
 */
export function loadDeadlines({ maxAge = MAX_AGE } = {}) {
  const userId = currentUserId();
  if (cacheIsMine() && Date.now() - cache.at < maxAge) return Promise.resolve(cache.payload);
  if (!inflight || inflight.userId !== userId) {
    const request = api.list('core/deadlines')
      .then((payload) => {
        if (currentUserId() === userId) cache = { userId, at: Date.now(), payload };
        return payload;
      })
      .finally(() => { if (inflight === request) inflight = null; });
    request.userId = userId;
    inflight = request;
  }
  return inflight;
}
