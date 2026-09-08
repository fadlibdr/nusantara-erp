/* Antrean kirim — kotak keluar aplikasi ini.
 *
 * Dulu tinggal di dalam views/lapangan.js dan hanya tahu satu bentuk muatan
 * (foto lampiran). F-4 menambah bentuk kedua (absen masuk/pulang dari ponsel)
 * yang butuh persis perilaku yang sama: bilah kemajuan per butir, yang gagal
 * tetap terdaftar dengan "Kirim ulang", butir bertahan melewati muat-ulang
 * halaman dan sesi yang berakhir, dan satu kirim pada satu waktu karena uplink
 * ponsel dibagi rata. Menyalinnya berarti dua salinan yang akan berbeda dalam
 * enam bulan; memindahkannya ke sini berarti satu.
 *
 * Sebelum T2.9 satu foto adalah satu api.post() di balik withBusy(): tombol
 * berputar, lalu toast — dan bila jaringan lokasi putus, toast merah dan
 * fotonya lenyap bersama posisi GPS yang sudah diminta (ASESMEN-UX §4.3: 5 MB
 * base64 di jaringan seluler lokasi 20–40 detik tanpa tanda hidup). Kini tiap
 * butir menjadi baris antrean: bilah kemajuan dari XHR upload.onprogress
 * (api.upload — satu-satunya jalur XHR di api.js; diukur 4 Sep 2026, harness
 * S15, unggahan dicekik 200 kB/s: 70 peristiwa kemajuan untuk foto 1 MB), dan
 * antreannya ditulis ke localStorage dengan idiom drafts.js — awalan sendiri
 * supaya listDrafts() tidak menawarkan 1,4 juta karakter base64 sebagai
 * "Pulihkan" — sehingga hidup melewati muat-ulang halaman dan sesi yang
 * berakhir.
 *
 * Per pengguna, seperti Favorit (T2.5): tablet kantor lapangan dipakai
 * bergantian, dan foto yang terkirim atas nama orang lain adalah jejak audit
 * (uploaded_by) yang salah.
 *
 * Batasnya jujur: localStorage Chromium memuat ~5,2 juta karakter (diukur
 * 4 Sep 2026: 5 234 375), yakni satu foto ≤ ~3,7 MB atau beberapa foto kecil;
 * butir yang tidak muat tetap di antrean memori dan barisnya berkata begitu.
 * Posisi yang tersimpan adalah posisi saat memotret, bukan saat mengirim
 * ulang — itulah yang ditanya AttachmentService::geotag().
 *
 * KENAPA KEDUA BENTUK MUATAN DIDAFTARKAN DI BERKAS INI, bukan oleh layarnya
 * masing-masing: butir hidup lebih lama daripada layar yang membuatnya. Kalau
 * 'clock' didaftarkan views/absensisaya.js, sebuah absen yang gagal terkirim
 * lalu halaman dibuka lagi di layar Lapangan akan menjadi butir yang tidak
 * dikenali siapa pun — terlihat di kartu "belum terkirim", tetapi tidak bisa
 * dikirim ulang sampai orangnya kebetulan membuka layar yang benar. Dua bentuk
 * di satu berkas tidak punya urutan impor yang bisa salah.
 */

import { api, session } from './api.js';
import { el, clear, button, toast, progressBar, confirmDialog } from './ui.js';

/** Cukup lama untuk fix GPS dingin di luar ruangan, cukup singkat untuk tidak terasa rusak. */
export const GEO_TIMEOUT_MS = 12_000;

/** Sama dengan AttachmentService::MAX_BYTES. Foto ponsel modern bisa melebihinya. */
export const MAX_BYTES = 5 * 1024 * 1024;

const QUEUE_PREFIX = 'nusantara_erp_upload:';

/*
 * Bentuk muatan yang dikenal antrean ini.
 *
 * `fields` butir + `position` butir + (`contentKey`: isi berkas) = badan
 * permintaan. `message` menyusun kalimat toast; ia menerima jawaban server
 * karena untuk absen, kalimat server-lah yang membawa kebenaran yang tidak
 * diketahui klien (jarak ke titik proyek, di dalam atau di luar radius).
 */
const KINDS = {
  attachment: {
    contentKey: 'content',
    message: (item) => (item.position
      ? `Foto ${item.filename} terkirim dengan lokasi.`
      : `Foto ${item.filename} terkirim (tanpa lokasi).`),
  },
  clock: {
    contentKey: 'selfie_content',
    // Server yang tahu jaraknya ke titik proyek; klien hanya tahu ia mengirim.
    message: (item, response) => (response && response.message)
      || 'Absensi terkirim.',
  },
};

let queueCache = { userId: null, items: null };
let sending = false;
const listeners = new Set();

/**
 * Posisi perangkat, atau null.
 *
 * TIDAK PERNAH ditolak. Izin yang ditolak, tidak ada perangkat keras, waktu
 * habis di dalam ruangan — ketiganya berarti "tanpa posisi", dan tidak satu
 * pun boleh menghentikan sesuatu terkirim.
 */
export function devicePosition() {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null);

    /* Batas waktu MILIK KITA di samping milik peramban, dan bukan sabuk
       pengaman berlebih: menurut spesifikasi Geolocation, penghitung `timeout`
       baru berjalan SESUDAH izin diberikan. Permintaan izin yang tidak dijawab
       — persis yang terjadi pada pemakaian pertama, di gerbang proyek pukul
       tujuh — menggantung selamanya, dan butirnya tinggal di keadaan
       'locating' tanpa "Kirim ulang", tanpa "Buang", tanpa terkirim. Terukur
       8 Sep 2026 dengan getCurrentPosition yang tidak pernah menjawab: masih
       'Menunggu posisi GPS…' pada detik ke-18. */
    let settled = false;
    const answer = (value) => { if (!settled) { settled = true; resolve(value); } };

    setTimeout(() => answer(null), GEO_TIMEOUT_MS);

    navigator.geolocation.getCurrentPosition(
      (position) => answer({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy_m: Math.round(position.coords.accuracy),
      }),
      () => answer(null),
      { enableHighAccuracy: true, timeout: GEO_TIMEOUT_MS, maximumAge: 60_000 },
    );
  });
}

export function readAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('Foto tidak dapat dibaca.'));
    reader.readAsDataURL(file);
  });
}

function queueKey(item) {
  return `${QUEUE_PREFIX}${item.userId}:${item.key}`;
}

/** Butir antrean pengguna yang masuk, terlama dulu. Dibaca dari localStorage sekali per pengguna. */
export function readQueue() {
  const userId = (session.user || {}).id || 0;
  if (queueCache.items && queueCache.userId === userId) return queueCache.items;

  const items = [];
  const mine = `${QUEUE_PREFIX}${userId}:`;
  for (let i = 0; i < localStorage.length; i += 1) {
    const key = localStorage.key(i);
    if (!key || !key.startsWith(mine)) continue;
    try {
      const item = JSON.parse(localStorage.getItem(key));
      if (!item) continue;

      /* Butir yang ditulis versi SEBELUM F-4 tidak punya `kind` sama sekali
         (lihat git show main:public/app/js/views/lapangan.js — enqueue tidak
         pernah menuliskannya), dan semuanya adalah lampiran. Tanpa baris ini
         setiap foto yang sedang mengantre di ponsel orang saat rilis ini
         mendarat akan lenyap dari layar DAN tetap memakan kuota selamanya:
         butir yang tidak pernah masuk readQueue() tidak pernah sampai ke
         forget(). Terukur pada butir berformat main, 8 Sep 2026. */
      if (!item.kind) {
        item.kind = 'attachment';
        item.endpoint = item.endpoint || 'core/attachments';
        item.fields = item.fields || {
          document_type: item.slug,
          document_id: item.id,
          filename: item.filename,
        };
      }

      /* Bentuk yang tetap tidak dikenali datang dari versi LEBIH BARU (turun
         versi, atau dua tab beda rilis). Ia dibuang dari localStorage alih-alih
         dilewati: butir yang tidak pernah terlihat tidak pernah bisa dikirim
         maupun dihapus orangnya, dan 3,7 MB kuota per foto tidak kembali. */
      if (!KINDS[item.kind]) { localStorage.removeItem(key); continue; }
      if (KINDS[item.kind].contentKey && item.requiresContent && !item.content) continue;
      // Halaman ditutup sebelum jawabannya sampai: yang tersisa hanya muatannya,
      // dan itu cukup untuk dikirim ulang.
      if (item.state === 'sending') { item.state = 'failed'; item.error = 'Terputus sebelum jawaban server tiba.'; }
      if (item.state !== 'failed') { item.state = 'failed'; item.error = 'Halaman ditutup sebelum terkirim.'; }
      items.push({ ...item, loaded: 0, total: null, persisted: true });
    } catch { /* butir rusak diabaikan */ }
  }
  items.sort((a, b) => a.savedAt - b.savedAt);
  queueCache = { userId, items };
  return items;
}

function persist(item) {
  const { persisted, loaded, total, ...stored } = item;
  try {
    localStorage.setItem(queueKey(item), JSON.stringify(stored));
    item.persisted = true;
  } catch {
    // Kuota penuh atau mode privat: butir tetap di memori, barisnya berkata begitu.
    item.persisted = false;
  }
}

export function forget(item) {
  localStorage.removeItem(queueKey(item));
  const items = readQueue();
  const at = items.indexOf(item);
  if (at >= 0) items.splice(at, 1);
}

/* Pendengar: 'change' (butir masuk/berubah keadaan/dibuang), 'progress' (byte
   terkirim), 'sent' (sampai di server), 'mounted' (kartu dokumen dipasang atau
   dilepas — kartu "belum terkirim" menghitung ulang miliknya). Dilepas sendiri
   begitu simpulnya keluar dari dokumen. */
export function listen(node, fn) {
  const wrapped = (event, item) => {
    if (!node.isConnected) { listeners.delete(wrapped); return; }
    fn(event, item);
  };
  listeners.add(wrapped);
}

export function notify(event, item) {
  [...listeners].forEach((fn) => {
    try { fn(event, item); } catch { /* satu pendengar rusak tidak menahan yang lain */ }
  });
}

export function enqueue(fields) {
  const item = {
    key: `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`,
    userId: (session.user || {}).id || 0,
    kind: 'attachment',
    state: 'queued',
    error: null,
    attempts: 0,
    savedAt: Date.now(),
    loaded: 0,
    total: null,
    ...fields,
  };
  readQueue().push(item);
  persist(item);
  notify('change', item);
  pump();
  return item;
}

export function retry(item) {
  item.state = 'queued';
  item.error = null;
  item.loaded = 0;
  persist(item);
  notify('change', item);
  pump();
}

function bodyOf(item) {
  const kind = KINDS[item.kind];
  const body = { ...(item.fields || {}), ...(item.position || {}) };

  if (kind.contentKey && item.content) body[kind.contentKey] = item.content;

  return body;
}

export async function pump() {
  if (sending) return;
  const item = readQueue().find((one) => one.state === 'queued');
  if (!item) return;

  sending = true;
  item.state = 'sending';
  item.loaded = 0;
  item.total = null;
  item.attempts += 1;
  persist(item);
  notify('change', item);

  try {
    // raw: amplop utuh. `message` server adalah satu-satunya tempat kalimat
    // yang benar tentang absensi ini ada — lihat api.upload().
    const response = await api.upload(item.endpoint, bodyOf(item), ({ loaded, total }) => {
      item.loaded = loaded;
      item.total = total;
      notify('progress', item);
    }, { raw: true });

    forget(item);
    toast(KINDS[item.kind].message(item, response));
    notify('sent', item);
  } catch (error) {
    item.state = 'failed';
    // 401 sudah memaksa layar masuk (app.js); barisnya menyebut jalan keluarnya,
    // bukan "Unauthenticated." milik server.
    item.error = error.status === 401 ? 'Sesi berakhir — masuk lagi, lalu kirim ulang.' : (error.message || String(error));
    persist(item);
    notify('change', item);
  } finally {
    sending = false;
  }

  pump();
}

function sizeLabel(bytes) {
  if (!bytes) return 'tanpa foto';
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function percentOf(item) {
  return item.total ? Math.min(100, Math.floor((item.loaded / item.total) * 100)) : 0;
}

function stateLine(item) {
  if (item.state === 'locating') return 'Menunggu posisi GPS…';
  if (item.state === 'queued') return 'Menunggu giliran.';
  if (item.state === 'sending') {
    return item.total && item.loaded >= item.total ? 'Menunggu jawaban server…' : `Mengirim… ${percentOf(item)} %`;
  }
  return `Belum terkirim — ${item.error}`;
}

function subLine(item, withDocument) {
  return [withDocument ? item.label : null, sizeLabel(item.size), stateLine(item)].filter(Boolean).join(' · ');
}

function refreshRow(row, item, withDocument) {
  row.dataset.state = item.state;
  const sub = row.querySelector('.cell-sub');
  if (sub) sub.textContent = subLine(item, withDocument);
  const bar = row.querySelector('.progress');
  if (bar) {
    bar.firstChild.style.width = `${percentOf(item)}%`;
    bar.setAttribute('aria-valuenow', percentOf(item));
  }
}

function queueRow(item, withDocument) {
  const main = el('.upload-item-main', [
    el('.attachment-name', { text: item.filename }),
    el('.cell-sub', { text: subLine(item, withDocument) }),
  ]);

  if (item.state === 'sending') {
    const bar = progressBar(percentOf(item));
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-label', `Kemajuan kirim ${item.filename}`);
    bar.setAttribute('aria-valuemin', '0');
    bar.setAttribute('aria-valuemax', '100');
    bar.setAttribute('aria-valuenow', percentOf(item));
    main.appendChild(bar);
  }

  if (item.persisted === false) {
    main.appendChild(el('.cell-sub.upload-warn', {
      text: 'Tidak muat disimpan di peramban: bila halaman ini ditutup sebelum terkirim, harus diulang.',
    }));
  }

  const actions = item.state === 'failed' ? el('.row-actions', [
    button('Kirim ulang', { variant: 'primary', size: 'sm', iconName: 'refresh', onClick: () => retry(item) }),
    button('Buang', {
      size: 'sm',
      iconName: 'trash',
      onClick: () => confirmDialog({
        title: item.discardTitle || 'Buang foto ini?',
        message: item.discardMessage || `${item.filename} dibuang dari antrean dan tidak dapat dikirim lagi dari sini.`,
        confirmLabel: 'Buang',
        onConfirm: () => { forget(item); notify('change', item); },
      }),
    }),
  ]) : null;

  return el('.upload-item', { dataset: { state: item.state, key: item.key } }, [main, actions]);
}

/**
 * Daftar butir antrean yang lolos `filter`, dilukis ulang sendiri. `doc`
 * menandai daftar milik satu dokumen (slug:id) sehingga kartu "belum terkirim"
 * tahu butir mana yang sudah tampil di tempat lain.
 */
export function queueRows(filter, { doc = null, withDocument = false } = {}) {
  const host = el('.upload-queue', { dataset: doc ? { doc } : null });

  const paint = () => {
    clear(host);
    readQueue().filter(filter).forEach((item) => host.appendChild(queueRow(item, withDocument)));
  };

  paint();
  listen(host, (event, item) => {
    if (event === 'progress') {
      const row = host.querySelector(`.upload-item[data-key="${item.key}"]`);
      if (row) refreshRow(row, item, withDocument);
      return;
    }
    paint();
  });
  return host;
}

/**
 * Butir yang belum terkirim untuk dokumen yang TIDAK sedang tampil — laporan
 * kemarin, tiket lain, absen kemarin. Tanpa kartu ini butir yang tersimpan dari
 * tanggal lain tak pernah terlihat lagi, apalagi terkirim.
 *
 * Judul dan keterangannya bisa diganti pemanggil; nilai bawaannya adalah
 * kalimat yang dipakai layar Lapangan sejak T2.9, supaya layar itu memanggil
 * pendingCard() persis seperti sebelum pemindahan berkas ini.
 */
export function pendingCard({
  title = 'Belum terkirim',
  description = null,
  filter = null,
} = {}) {
  const shownElsewhere = (item) => Boolean(document.querySelector(`.upload-queue[data-doc="${item.slug}:${item.id}"]`));
  const list = queueRows((item) => !shownElsewhere(item) && (filter === null || filter(item)), { withDocument: true });

  const line = el('p.cell-sub');

  const card = el('.card.upload-pending', [
    el('.card-head', [el('h2', { text: title }), el('.spacer')]),
    el('.card-body', [line, list]),
  ]);

  /* Keterangannya mengikuti APA yang sedang mengantre, bukan layar tempat
     kartunya kebetulan tampil. Sebelum ini kartu di layar Lapangan berbunyi
     "Dari laporan atau tiket lain. Setelah terkirim, foto tampil di
     dokumennya." di atas baris absensi bertuliskan "tanpa foto" — tiga
     pernyataan salah sekaligus di atas satu baris yang membantahnya sendiri. */
  const describe = () => {
    if (description) return description;

    const kinds = new Set(readQueue().filter((item) => (filter === null || filter(item))
      && !shownElsewhere(item)).map((item) => item.kind));

    if (kinds.size === 1 && kinds.has('clock')) {
      return 'Absensi yang belum sampai ke server. Tekan "Kirim ulang" setelah sinyal kembali.';
    }
    if (kinds.size === 1 && kinds.has('attachment')) {
      return 'Foto dari laporan atau tiket lain. Setelah terkirim, foto tampil di dokumennya.';
    }
    return 'Foto dan absensi dari layar lain. Tekan "Kirim ulang" setelah sinyal kembali.';
  };

  const sync = () => {
    card.hidden = !list.childElementCount;
    line.textContent = describe();
  };
  sync();
  // Terdaftar SETELAH pendengar daftarnya, jadi menghitung anak yang sudah dilukis ulang.
  listen(card, sync);
  return card;
}
