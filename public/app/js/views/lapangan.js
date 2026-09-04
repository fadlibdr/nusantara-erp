/* Lapangan — the phone screen.
 *
 * Built for someone standing on a slab in the sun with one hand free: one
 * column, large targets, almost no typing, and the camera one tap away. It is
 * the same SPA and the same API as everything else — there is no second
 * application to keep in step, and no app store between a site supervisor and a
 * fix.
 *
 * Photos are geotagged. The browser's position is asked for ONCE per capture
 * and sent with the upload, where it is used only if the image carries no EXIF
 * GPS of its own. Permission may be refused, the fix may time out, and the
 * screen keeps working — a photo with no position is worth more than no photo. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, errorState, toast, toastError, withBusy, field, progressBar, confirmDialog } from '../ui.js';
import { ENUMS } from '../enums.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';

const MODES = [
  { key: 'harian', label: 'Laporan Harian', module: 'prj' },
  { key: 'tiket', label: 'Tiket Servis', module: 'svc' },
];

/** Long enough for a cold GPS fix outdoors, short enough not to feel broken. */
const GEO_TIMEOUT_MS = 12_000;

/** Matches AttachmentService::MAX_BYTES. A modern phone photo can exceed it. */
const MAX_BYTES = 5 * 1024 * 1024;

const state = { mode: 'harian', projectId: null, date: fmt.today(), ticketId: null };

/* Some list endpoints paginate and some return a plain array, so the envelope
 * arrives as either. Reading `.data` off an array yields undefined and the
 * screen renders "no data" over a full response. */
function rows(payload) {
  if (Array.isArray(payload)) return payload;
  return (payload && payload.data) || [];
}

/**
 * The device's position, or null.
 *
 * Never rejects. Refused permission, no hardware, a timeout indoors — all of
 * them mean "no position", and none of them should stop a photo being filed.
 */
function devicePosition() {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null);

    navigator.geolocation.getCurrentPosition(
      (position) => resolve({
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy_m: Math.round(position.coords.accuracy),
      }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: GEO_TIMEOUT_MS, maximumAge: 60_000 },
    );
  });
}

function readAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(new Error('Foto tidak dapat dibaca.'));
    reader.readAsDataURL(file);
  });
}

/* ------------------------------------------------------------ upload queue */
/*
 * Antrean kirim foto — di memori dan, sebisanya, di localStorage.
 *
 * Sebelum T2.9 satu foto adalah satu api.post() di balik withBusy(): tombol
 * berputar, lalu toast — dan bila jaringan lokasi putus, toast merah dan
 * fotonya lenyap bersama posisi GPS yang sudah diminta (ASESMEN-UX §4.3: 5 MB
 * base64 di jaringan seluler lokasi 20–40 detik tanpa tanda hidup). Kini tiap
 * foto menjadi butir antrean: bilah kemajuan per foto dari XHR
 * upload.onprogress (api.upload — satu-satunya jalur XHR di api.js; diukur
 * 4 Sep 2026, harness S15, unggahan dicekik 200 kB/s: 70 peristiwa kemajuan
 * untuk foto 1 MB), yang gagal tetap terdaftar dengan "Kirim ulang", dan
 * antreannya ditulis ke localStorage dengan idiom drafts.js — awalan sendiri
 * supaya listDrafts() tidak menawarkan 1,4 juta karakter base64 sebagai
 * "Pulihkan" — sehingga hidup melewati muat-ulang halaman dan sesi yang
 * berakhir. Dikirim satu per satu: uplink ponsel dibagi rata, dan satu bilah
 * yang bergerak lebih jujur daripada tiga yang merayap.
 *
 * Per pengguna, seperti Favorit (T2.5): tablet kantor lapangan dipakai
 * bergantian, dan foto yang terkirim atas nama orang lain adalah jejak audit
 * (uploaded_by) yang salah.
 *
 * Batasnya jujur: localStorage Chromium memuat ~5,2 juta karakter (diukur
 * 4 Sep 2026: 5 234 375), yakni satu foto ≤ ~3,7 MB atau beberapa foto kecil;
 * foto yang tidak muat tetap di antrean memori dan barisnya berkata begitu.
 * Posisi yang tersimpan adalah posisi saat memotret, bukan saat mengirim
 * ulang — itulah yang ditanya AttachmentService::geotag().
 */
const QUEUE_PREFIX = 'nusantara_erp_upload:';

let queueCache = { userId: null, items: null };
let sending = false;
const listeners = new Set();

function queueKey(item) {
  return `${QUEUE_PREFIX}${item.userId}:${item.key}`;
}

/** Butir antrean pengguna yang masuk, terlama dulu. Dibaca dari localStorage sekali per pengguna. */
function readQueue() {
  const userId = (session.user || {}).id || 0;
  if (queueCache.items && queueCache.userId === userId) return queueCache.items;

  const items = [];
  const mine = `${QUEUE_PREFIX}${userId}:`;
  for (let i = 0; i < localStorage.length; i += 1) {
    const key = localStorage.key(i);
    if (!key || !key.startsWith(mine)) continue;
    try {
      const item = JSON.parse(localStorage.getItem(key));
      if (!item || !item.content) continue;
      // Halaman ditutup sebelum jawabannya sampai: yang tersisa hanya fotonya,
      // dan itu cukup untuk dikirim ulang.
      if (item.state === 'sending') { item.state = 'failed'; item.error = 'Terputus sebelum jawaban server tiba.'; }
      if (item.state !== 'failed') { item.state = 'failed'; item.error = 'Halaman ditutup sebelum foto dikirim.'; }
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

function forget(item) {
  localStorage.removeItem(queueKey(item));
  const items = readQueue();
  const at = items.indexOf(item);
  if (at >= 0) items.splice(at, 1);
}

/* Pendengar: 'change' (butir masuk/berubah keadaan/dibuang), 'progress' (byte
   terkirim), 'sent' (sampai di server), 'mounted' (kartu dokumen dipasang atau
   dilepas — kartu "belum terkirim" menghitung ulang miliknya). Dilepas sendiri
   begitu simpulnya keluar dari dokumen. */
function listen(node, fn) {
  const wrapped = (event, item) => {
    if (!node.isConnected) { listeners.delete(wrapped); return; }
    fn(event, item);
  };
  listeners.add(wrapped);
}

function notify(event, item) {
  [...listeners].forEach((fn) => {
    try { fn(event, item); } catch { /* satu pendengar rusak tidak menahan yang lain */ }
  });
}

function enqueue(fields) {
  const item = {
    key: `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 7)}`,
    userId: (session.user || {}).id || 0,
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

function retry(item) {
  item.state = 'queued';
  item.error = null;
  item.loaded = 0;
  persist(item);
  notify('change', item);
  pump();
}

async function pump() {
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
    await api.upload('core/attachments', {
      document_type: item.slug,
      document_id: item.id,
      filename: item.filename,
      content: item.content,
      ...(item.position || {}),
    }, ({ loaded, total }) => {
      item.loaded = loaded;
      item.total = total;
      notify('progress', item);
    });

    forget(item);
    toast(item.position ? `Foto ${item.filename} terkirim dengan lokasi.` : `Foto ${item.filename} terkirim (tanpa lokasi).`);
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
      text: 'Tidak muat disimpan di peramban: bila halaman ini ditutup sebelum terkirim, foto harus diambil lagi.',
    }));
  }

  const actions = item.state === 'failed' ? el('.row-actions', [
    button('Kirim ulang', { variant: 'primary', size: 'sm', iconName: 'refresh', onClick: () => retry(item) }),
    button('Buang', {
      size: 'sm',
      iconName: 'trash',
      onClick: () => confirmDialog({
        title: 'Buang foto ini?',
        message: `${item.filename} dibuang dari antrean dan tidak dapat dikirim lagi dari sini.`,
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
function queueRows(filter, { doc = null, withDocument = false } = {}) {
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
 * Foto yang belum terkirim untuk dokumen yang TIDAK sedang tampil — laporan
 * kemarin, tiket lain. Tanpa kartu ini butir yang tersimpan dari tanggal lain
 * tak pernah terlihat lagi, apalagi terkirim.
 */
function pendingCard() {
  const shownElsewhere = (item) => Boolean(document.querySelector(`.upload-queue[data-doc="${item.slug}:${item.id}"]`));
  const list = queueRows((item) => !shownElsewhere(item), { withDocument: true });

  const card = el('.card.upload-pending', [
    el('.card-head', [el('h2', { text: 'Foto belum terkirim' }), el('.spacer')]),
    el('.card-body', [
      el('p.cell-sub', { text: 'Dari laporan atau tiket lain. Setelah terkirim, foto tampil di dokumennya.' }),
      list,
    ]),
  ]);

  const sync = () => { card.hidden = !list.childElementCount; };
  sync();
  // Terdaftar SETELAH pendengar daftarnya, jadi menghitung anak yang sudah dilukis ulang.
  listen(card, sync);
  return card;
}

function distanceBadge(attachment) {
  if (attachment.distance_from_site_m === null || attachment.distance_from_site_m === undefined) {
    return badge(attachment.geo_source ? 'Lokasi proyek belum diisi' : 'Tanpa lokasi', '');
  }

  const metres = attachment.distance_from_site_m;
  const label = metres < 1000 ? `${metres} m dari lokasi` : `${(metres / 1000).toFixed(1)} km dari lokasi`;

  // 250 m covers a large site and a GPS fix that drifted; past 1 km somebody
  // was not where the photo says the work is.
  return badge(label, metres <= 250 ? 'green' : (metres <= 1000 ? 'amber' : 'red'));
}

function photoStrip(attachments, onChanged) {
  if (!attachments.length) {
    return el('p.muted', { text: 'Belum ada foto.', style: { margin: '4px 0 0' } });
  }

  return el('.field-photos', attachments.map((attachment) => el('.field-photo', [
    el('.field-photo-main', [
      el('.attachment-name', { text: attachment.original_name }),
      el('.cell-sub', {
        text: [
          attachment.geo_source === 'exif' ? 'lokasi dari foto' : (attachment.geo_source === 'device' ? 'lokasi dari perangkat' : null),
          attachment.accuracy_m ? `±${attachment.accuracy_m} m` : null,
          fmt.relativeDays(attachment.created_at),
        ].filter(Boolean).join(' · '),
      }),
    ]),
    distanceBadge(attachment),
  ])));
}

/**
 * The camera button, this document's upload queue, and the list of what has
 * already been filed. `label` is the document number the queue shows when the
 * photo outlives this screen (pendingCard).
 */
function captureCard(slug, id, label, onChanged) {
  const body = el('.card-body');
  const card = el('.card', [
    el('.card-head', [el('h2', { text: 'Foto lapangan' }), el('.spacer')]),
    body,
  ]);

  const input = el('input', {
    type: 'file',
    accept: 'image/*',
    // Opens the rear camera on a phone; on a desktop it is an ordinary picker.
    capture: 'environment',
    style: { display: 'none' },
  });

  // Built once and kept across load(): it is the node pendingCard looks for,
  // and the one whose progress listener must survive the list being refetched.
  const queueNode = queueRows((item) => item.slug === slug && item.id === id, { doc: `${slug}:${id}` });

  // The button stays live while a photo uploads: the next shot is taken while
  // the previous one travels, and the row under the button is where it shows.
  const shoot = button('Ambil foto', { variant: 'primary', size: 'lg', iconName: 'plus', onClick: () => input.click() });

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;

    // Checked here as well as on the server: a 12 MP photo would otherwise be
    // read, base64'd and posted before failing, which on site data is a slow
    // way to learn nothing happened.
    if (file.size > MAX_BYTES) {
      toastError(new Error(`Foto ${(file.size / 1024 / 1024).toFixed(1)} MB melebihi batas 5 MB.`));
      input.value = '';
      return;
    }

    // Ask for the position and read the file at the same time — the GPS fix
    // is the slow half (up to GEO_TIMEOUT_MS) — but the photo is listed as
    // soon as it is read: a row saying "Menunggu posisi GPS…" is the sign of
    // life that a spinning button never was.
    const positioning = devicePosition();
    let item = null;
    try {
      const content = await readAsBase64(file);
      item = enqueue({ slug, id, label, filename: file.name || `foto-${Date.now()}.jpg`, content, position: null, size: file.size, state: 'locating' });
    } catch (error) {
      toastError(error);
    } finally {
      input.value = '';
    }
    if (!item) return;

    item.position = await positioning;
    // A photo dropped from the queue while the GPS was still fixing must not
    // come back as "queued".
    if (!readQueue().includes(item)) return;
    retry(item);
  });

  listen(card, (event, item) => {
    if (event === 'sent' && item.slug === slug && item.id === id) load();
  });

  async function load() {
    const loading = el('p.muted', { text: 'Memuat…', style: { margin: '10px 0 0' } });
    // Detached and re-attached in one statement: no event can fire in between,
    // so queueNode's listener never sees itself disconnected.
    clear(body).append(shoot, input, queueNode, loading);

    try {
      const list = rows(await api.get('core/attachments', { document_type: slug, document_id: id }));
      loading.replaceWith(photoStrip(list, load));
      if (onChanged) onChanged();
    } catch (error) {
      // Keep the camera: a failed list must not remove the only control on the
      // screen, which on site means the photo cannot be filed at all.
      loading.replaceWith(errorState(error, load));
    }
  }

  load();
  return card;
}

/* ------------------------------------------------------------ daily report */

async function renderHarian(host) {
  const projects = rows(await api.get('projects', { per_page: 100 }));

  if (!projects.length) {
    host.appendChild(el('.alert.warn', 'Belum ada proyek.'));
    return;
  }

  if (!projects.some((p) => p.id === state.projectId)) state.projectId = projects[0].id;

  const projectSelect = el('select.field-select', {
    onchange: (event) => { state.projectId = Number(event.target.value); paint(); },
  });
  projects.forEach((p) => projectSelect.appendChild(el('option', { value: p.id, text: `${p.code} — ${p.name}` })));
  projectSelect.value = String(state.projectId);

  const dateInput = el('input.field-select', {
    type: 'date',
    value: state.date,
    onchange: (event) => { state.date = event.target.value; paint(); },
  });

  host.appendChild(el('.card', [
    el('.card-body', [field('Proyek', projectSelect), field('Tanggal', dateInput)]),
  ]));

  const slot = el('div');
  host.appendChild(slot);

  async function paint() {
    clear(slot).appendChild(el('p.muted', { text: 'Memuat…' }));

    try {
      const found = rows(await api.get('projects/daily-reports', {
        project_id: state.projectId,
        date_from: state.date,
        date_to: state.date,
        per_page: 1,
      }));

      clear(slot);

      if (found.length) {
        const report = found[0];
        slot.appendChild(el('.card', [
          el('.card-head', [el('h2', { text: report.code }), el('.spacer'), badge('Sudah ada', 'green')]),
          el('.card-body', el('dl.kv', [
            el('dt', { text: 'Tenaga kerja' }), el('dd', { text: `${report.manpower_count} orang` }),
            el('dt', { text: 'Kegiatan' }), el('dd', { text: report.activities || '—' }),
          ])),
        ]));
        slot.appendChild(captureCard('projects/daily-reports', report.id, report.code));
        notify('mounted');
        return;
      }

      slot.appendChild(newReportCard(paint));
      notify('mounted');
    } catch (error) {
      clear(slot).appendChild(el('.alert.error', { text: error.message || 'Gagal memuat laporan.' }));
    }
  }

  await paint();
}

/*
 * Tenaga kerja per jabatan — 12 stepper, satu per baris tabel JUMLAH ORANG
 * FM-10-12 (P0-A). Stepper, bukan 12 kotak angka: di terik dengan satu tangan,
 * dua ketukan besar lebih pasti daripada memunculkan keyboard angka — tetapi
 * kotaknya tetap input sungguhan supaya "23 tukang" tidak berarti 23 ketukan.
 *
 * read() memulangkan baris manpower[] persis bentuk API: hanya jabatan yang
 * terisi (> 0). Server MENURUNKAN manpower_count dari sini; layar ini tidak
 * pernah mengirim klaim manual di sampingnya, jadi 422 selisih tidak mungkin
 * lahir dari sini.
 */
function manpowerSteppers() {
  const inputs = new Map(); // role_key -> input
  const total = el('b', { text: '0' });

  const refreshTotal = () => {
    let sum = 0;
    inputs.forEach((input) => { sum += Math.max(0, Number(input.value) || 0); });
    total.textContent = String(sum);
  };

  const stepButton = (input, delta, role) => {
    const node = button(delta > 0 ? '+' : '−', {
      title: `${delta > 0 ? 'Tambah' : 'Kurangi'} ${role.label}`,
      onClick: () => {
        input.value = String(Math.max(0, (Number(input.value) || 0) + delta));
        refreshTotal();
      },
    });
    // Sasaran sentuh lapangan: lebar tombol minimum ~44px (ukuran jari).
    Object.assign(node.style, { minWidth: '44px', minHeight: '40px', fontSize: '17px', flex: 'none' });
    return node;
  };

  const rows = ENUMS.dailyReportRole.map((role) => {
    const input = el('input', {
      type: 'number', min: 0, value: 0, inputmode: 'numeric', 'aria-label': role.label,
      style: { width: '56px', textAlign: 'center', flex: 'none' },
      oninput: refreshTotal,
    });
    inputs.set(role.value, input);

    return el('div', {
      style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '5px 0' },
    }, [
      el('span', { text: role.label, style: { flex: '1', fontSize: '13.5px' } }),
      stepButton(input, -1, role),
      input,
      stepButton(input, +1, role),
    ]);
  });

  const node = el('div', [
    ...rows,
    el('div', {
      style: { display: 'flex', justifyContent: 'flex-end', gap: '5px', padding: '8px 0 0', fontSize: '14px' },
    }, [el('span.muted', { text: 'Total:' }), total, el('span.muted', { text: 'orang' })]),
  ]);

  const read = () => ENUMS.dailyReportRole
    .map((role) => ({ role_key: role.value, headcount: Math.max(0, Number(inputs.get(role.value).value) || 0) }))
    .filter((row) => row.headcount > 0);

  return { node, read };
}

function newReportCard(onCreated) {
  const manpower = manpowerSteppers();
  const activities = el('textarea.field-select', { rows: 3, placeholder: 'Pekerjaan hari ini…' });

  const save = button('Buat laporan hari ini', {
    variant: 'primary',
    size: 'lg',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        const rows = manpower.read();
        await api.post('projects/daily-reports', {
          project_id: state.projectId,
          report_date: state.date,
          activities: activities.value.trim(),
          // Baris per jabatan bila ada — server menurunkan manpower_count
          // darinya (P0-A). Tanpa satu pun jabatan terisi, klaim manual 0
          // tetap sah (hari hujan, site berhenti): jalur kompat data lama.
          ...(rows.length ? { manpower: rows } : { manpower_count: 0 }),
        });
        toast('Laporan harian dibuat.');
        onCreated();
      } catch (error) {
        toastError(error);
      }
    }),
  });

  return el('.card', [
    el('.card-head', el('h2', { text: 'Belum ada laporan untuk tanggal ini' })),
    el('.card-body', [
      field('Tenaga kerja per jabatan', manpower.node, {
        help: 'Total dihitung otomatis dari jabatan yang diisi.',
      }),
      field('Kegiatan', activities),
      session.can('prj.create')
        ? save
        : el('p.muted', { text: 'Anda tidak memiliki izin membuat laporan harian.' }),
    ]),
  ]);
}

/* ------------------------------------------------------------ service ticket */

async function renderTiket(host) {
  const tickets = rows(await api.get('servicedesk/tickets', { per_page: 50 }));
  const open = tickets.filter((t) => !['closed', 'resolved'].includes(String(t.status)));

  if (!open.length) {
    host.appendChild(el('.alert.info', [icon('check', 15), el('div', { text: 'Tidak ada tiket terbuka.' })]));
    return;
  }

  if (!open.some((t) => t.id === state.ticketId)) state.ticketId = open[0].id;

  const select = el('select.field-select', {
    onchange: (event) => { state.ticketId = Number(event.target.value); paint(); },
  });
  open.forEach((t) => select.appendChild(el('option', { value: t.id, text: `${t.code} — ${t.title || t.subject || ''}` })));
  select.value = String(state.ticketId);

  host.appendChild(el('.card', el('.card-body', field('Tiket', select))));

  const slot = el('div');
  host.appendChild(slot);

  function paint() {
    clear(slot);
    const ticket = open.find((t) => t.id === state.ticketId);

    slot.appendChild(el('.card', [
      el('.card-head', [el('h2', { text: ticket.code }), el('.spacer'), badge(ticket.priority || '—', 'amber')]),
      el('.card-body', el('dl.kv', [
        el('dt', { text: 'Judul' }), el('dd', { text: ticket.title || ticket.subject || '—' }),
        el('dt', { text: 'Status' }), el('dd', { text: ticket.status || '—' }),
      ])),
    ]));

    slot.appendChild(captureCard('servicedesk/tickets', state.ticketId, ticket.code));
    notify('mounted');
  }

  paint();
}

/* ------------------------------------------------------------------- shell */

export async function renderLapangan(host) {
  clear(host);

  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Lapangan' }),
      el('.desc', { text: 'Layar untuk di lokasi: buat laporan harian dan kirim foto ber-GPS langsung dari ponsel.' }),
    ]),
  ]));

  const tabs = el('.tabs');
  const body = el('div');
  // Above the tabs, hidden while empty: photos of a report or ticket that is
  // not on screen would otherwise stay in localStorage unseen and unsent.
  host.append(pendingCard(), tabs, body);

  const allowed = MODES.filter((mode) => session.can(`${mode.module}.view`));

  if (!allowed.length) {
    body.appendChild(el('.alert.error', 'Anda tidak memiliki akses ke layar lapangan.'));
    return;
  }

  if (!allowed.some((mode) => mode.key === state.mode)) state.mode = allowed[0].key;

  function paintTabs() {
    clear(tabs);
    allowed.forEach((mode) => tabs.appendChild(el(`button${mode.key === state.mode ? '.active' : ''}`, {
      text: mode.label,
      onclick: () => { if (state.mode === mode.key) return; state.mode = mode.key; paintTabs(); load(); },
    })));
  }

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…' }));

    try {
      clear(body);
      await (state.mode === 'harian' ? renderHarian(body) : renderTiket(body));
    } catch (error) {
      clear(body).appendChild(el('.alert.error', { text: error.message || 'Gagal memuat.' }));
    }
  }

  paintTabs();
  await load();
}
