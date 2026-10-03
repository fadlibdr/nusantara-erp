/* Kotak masuk persetujuan — SATU pemuat untuk tiga pembaca (UX-D.2, ERRATA E-4).
 *
 * Lencana "Persetujuan" di sidebar dan bilah bawah, widget "Menunggu persetujuan
 * Anda" di dasbor, dan layar Persetujuan sendiri semuanya membaca GET core/inbox.
 * Tanpa pemuat bersama, lencana shell menambah satu permintaan pada setiap
 * halaman — dan pada #/dashboard ia berdiri di samping permintaan widget untuk
 * jawaban yang sama persis. Bound S1 ("permintaan saat membuka halaman landing
 * tidak lebih tinggi dari hari ini") ada untuk mencegah tepat itu, jadi:
 *
 *   - permintaan yang sedang berjalan DIBAGI: dua pemanggil dalam satu detik
 *     mendapat satu janji;
 *   - jawaban disimpan MAX_AGE ms untuk pembaca yang cukup dengan angka kemarin
 *     (lencana), sementara widget dan layar meminta maxAge: 0;
 *   - TIDAK ADA permintaan untuk pembaca tanpa izin approve (aturan yang sudah
 *     ada: ANY_APPROVE menggerbangi tautan, kartu, dan permintaannya —
 *     ApprovalInboxGateTest). loadInbox() menjawab null, bukan daftar kosong:
 *     "tidak ditanyakan" bukan "tidak ada".
 *
 * meta.failed ikut dibawa: jenis yang gagal dimuat di server berarti angkanya
 * BUKAN jumlah sebenarnya, dan setiap pembaca harus mengatakannya (tugas.js:
 * "Daftar ini belum lengkap"; lencana: angka dengan tanda, bukan angka telanjang).
 */

import { api, session } from './api.js';
import { ANY_APPROVE } from './schema.js';

const MAX_AGE = 60000;

/* Jawaban DIKUNCI pada pengguna yang menanyakannya. Keluar lalu masuk sebagai
   orang lain di tab yang sama tidak memuat ulang modul ini, jadi tanpa kunci ini
   lencana orang kedua menampilkan jumlah antrean orang pertama sampai MAX_AGE
   habis — di tablet lapangan yang dipakai bergantian, itu angka milik orang lain. */
let cache = { userId: null, at: 0, payload: null };
let inflight = null;

const currentUserId = () => (session.user && session.user.id) || null;
const cacheIsMine = () => cache.payload !== null && cache.userId === currentUserId();

/** Ringkasan jawaban terakhir, atau null bila belum pernah ditanyakan. */
export function inboxSummary() {
  if (!cacheIsMine()) return null;
  const meta = cache.payload.meta || {};
  const rows = Array.isArray(cache.payload.data) ? cache.payload.data : [];
  return { total: meta.total ?? rows.length, failed: meta.failed || [], at: cache.at };
}

/** Simpan jawaban yang sudah dimuat pembaca lain dan umumkan ke lencana. */
export function publishInbox(payload, userId = currentUserId()) {
  cache = { userId, at: Date.now(), payload: payload || { data: [], meta: {} } };
  window.dispatchEvent(new CustomEvent('erp:inbox-changed', { detail: inboxSummary() }));
  return cache.payload;
}

/** Lupakan jawaban terakhir: keputusan baru saja diambil, angkanya sudah lain. */
export function forgetInbox() {
  cache = { userId: null, at: 0, payload: null };
}

/**
 * Muat kotak masuk. null bila pemanggil tidak memegang izin approve apa pun
 * (tidak ada permintaan). Melempar bila server gagal — pemanggil yang punya
 * tempat untuk kalimat galatnya (widget, layar) yang memutuskan.
 */
export function loadInbox({ maxAge = MAX_AGE } = {}) {
  if (!session.can(ANY_APPROVE)) return Promise.resolve(null);
  const userId = currentUserId();
  if (cacheIsMine() && Date.now() - cache.at < maxAge) return Promise.resolve(cache.payload);
  // Permintaan yang sedang berjalan hanya dibagi kepada pengguna YANG SAMA.
  if (!inflight || inflight.userId !== userId) {
    const request = api.list('core/inbox')
      .then((payload) => (currentUserId() === userId ? publishInbox(payload, userId) : payload))
      .finally(() => { if (inflight === request) inflight = null; });
    request.userId = userId;
    inflight = request;
  }
  return inflight;
}
