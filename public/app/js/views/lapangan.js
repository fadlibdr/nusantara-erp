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
import { el, clear, button, badge, icon, errorState, toast, toastError, withBusy, field, offlineRibbon } from '../ui.js';
import { ENUMS } from '../enums.js';
import * as fmt from '../format.js';
import { navigate } from '../router.js';
import {
  MAX_BYTES, devicePosition, readAsBase64, readQueue, enqueue, retry, listen, notify,
  queueRows, pendingCard,
} from '../uploadqueue.js';
import { validityNode } from './attachments.js';

const MODES = [
  { key: 'harian', label: 'Laporan Harian', module: 'prj' },
  { key: 'tiket', label: 'Tiket Servis', module: 'svc' },
];

/* Antrean kirim (foto ber-GPS) tinggal di js/uploadqueue.js sejak F-4: layar
   "Absensi Saya" memakai antrean yang SAMA — bilah kemajuan, "Kirim ulang",
   butir yang bertahan melewati muat-ulang halaman — dan dua salinan perilaku
   itu akan berbeda dalam enam bulan. Layar ini memakainya persis seperti
   sebelumnya; yang berpindah hanya tempat kodenya. */

const state = { mode: 'harian', projectId: null, date: fmt.today(), ticketId: null };

/* Some list endpoints paginate and some return a plain array, so the envelope
 * arrives as either. Reading `.data` off an array yields undefined and the
 * screen renders "no data" over a full response. */
function rows(payload) {
  if (Array.isArray(payload)) return payload;
  return (payload && payload.data) || [];
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

/* F-8. Strip ini adalah permukaan BACA lampiran yang KEDUA: ia memanggil
   core/attachments TANPA menyaring jenis berkas, jadi polis, sertifikat atau
   garansi yang dilampirkan lewat kartu Lampiran dokumen yang sama ikut tampil
   di sini. Sebuah berkas yang berbunyi "Kedaluwarsa 06 Sep 2026 · 4 hari lalu"
   di kartu tidak boleh diam di layar yang justru dibuka teknisi di lapangan.

   Keadaan NORMAL sengaja TIDAK ikut ke sini: hampir setiap baris strip ini
   adalah foto lapangan tanpa masa berlaku, dan satu baris "Tanpa masa berlaku"
   di bawah setiap foto adalah kebisingan, bukan keterangan. Kartu Lampiran
   menuliskannya karena di sanalah tombol untuk mengubahnya. */
function validityLine(attachment) {
  const node = validityNode(attachment, { hideWhenNone: true });

  return node ? el('.cell-sub.attachment-validity', [node]) : null;
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
      validityLine(attachment),
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
      const filename = file.name || `foto-${Date.now()}.jpg`;
      item = enqueue({
        kind: 'attachment',
        endpoint: 'core/attachments',
        // Dikirim server apa adanya; `filename` diulang di butir karena itulah
        // yang dibaca barisnya di layar, dan baris tanpa nama tidak bisa
        // dikenali orang yang menekan "Kirim ulang".
        fields: { document_type: slug, document_id: id, filename },
        requiresContent: true,
        slug, id, label, filename, content, position: null, size: file.size, state: 'locating',
      });
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
  /* Pita luring DI ATAS antrean (P1-I), dan kalimatnya menyebut apa yang
     sebenarnya terjadi: foto yang sudah masuk antrean aman di localStorage
     ponsel ini, tetapi TIDAK ada yang mengirimnya sendiri saat sinyal kembali —
     pump() hanya berjalan saat foto dimasukkan atau "Kirim ulang" ditekan.
     Menjanjikan pengiriman otomatis akan membuat orang menutup layarnya dan
     pulang; kalimat ini menyuruhnya menekan tombol yang memang ada.

     DUA kalimat, dipilih saat pita menyala (verifikasi 7 Sep 2026): kalimat
     "tekan Kirim ulang pada barisnya" hanya benar bila ada barisnya. Terukur di
     390x844 dengan antrean kosong — kartu "Foto belum terkirim" tersembunyi,
     nol tombol "Kirim ulang" di seluruh halaman — dan pita tetap menyuruh
     menekannya. */
  const ribbon = offlineRibbon(() => (readQueue().length
    ? 'Tanpa koneksi. Foto yang sudah diambil tersimpan di ponsel ini — setelah sinyal kembali, '
      + 'tekan "Kirim ulang" pada barisnya.'
    : 'Tanpa koneksi. Foto yang Anda ambil sekarang tersimpan di ponsel ini sampai sinyal kembali.'));
  // Above the tabs, hidden while empty: photos of a report or ticket that is
  // not on screen would otherwise stay in localStorage unseen and unsent.
  // pendingCard() menyusun keterangannya dari isi antreannya (uploadqueue.js):
  // layar ini bisa memuat foto lapangan DAN absensi yang belum terkirim, dan
  // keterangan tetap "foto tampil di dokumennya" pernah berdiri di atas baris
  // absensi bertuliskan "tanpa foto".
  host.append(ribbon, pendingCard(), tabs, body);

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
