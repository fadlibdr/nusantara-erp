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
import { el, clear, button, badge, icon, errorState, toast, toastError, withBusy, field } from '../ui.js';
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

/** The camera button plus the list of what has already been filed. */
function captureCard(slug, id, onChanged) {
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

    await withBusy(shoot, async () => {
      try {
        // Ask for the position and read the file at the same time — the GPS fix
        // is the slow half and there is no reason to wait for it serially.
        const [content, position] = await Promise.all([readAsBase64(file), devicePosition()]);

        await api.post('core/attachments', {
          document_type: slug,
          document_id: id,
          filename: file.name || `foto-${Date.now()}.jpg`,
          content,
          ...(position || {}),
        });

        toast(position ? 'Foto terkirim dengan lokasi.' : 'Foto terkirim (tanpa lokasi).');
        load();
      } catch (error) {
        toastError(error);
      } finally {
        input.value = '';
      }
    });
  });

  async function load() {
    clear(body).appendChild(el('p.muted', { text: 'Memuat…', style: { margin: 0 } }));

    try {
      const list = rows(await api.get('core/attachments', { document_type: slug, document_id: id }));
      clear(body);
      body.append(shoot, input, photoStrip(list, load));
      if (onChanged) onChanged();
    } catch (error) {
      // Keep the camera: a failed list must not remove the only control on the
      // screen, which on site means the photo cannot be filed at all.
      clear(body);
      body.append(shoot, input, errorState(error, load));
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
        slot.appendChild(captureCard('projects/daily-reports', report.id));
        return;
      }

      slot.appendChild(newReportCard(paint));
    } catch (error) {
      clear(slot).appendChild(el('.alert.error', { text: error.message || 'Gagal memuat laporan.' }));
    }
  }

  await paint();
}

function newReportCard(onCreated) {
  const manpower = el('input.field-select', { type: 'number', min: 0, value: 0, inputmode: 'numeric' });
  const activities = el('textarea.field-select', { rows: 3, placeholder: 'Pekerjaan hari ini…' });

  const save = button('Buat laporan hari ini', {
    variant: 'primary',
    size: 'lg',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        await api.post('projects/daily-reports', {
          project_id: state.projectId,
          report_date: state.date,
          manpower_count: Number(manpower.value) || 0,
          activities: activities.value.trim(),
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
      field('Jumlah tenaga kerja', manpower),
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

    slot.appendChild(captureCard('servicedesk/tickets', state.ticketId));
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
  host.append(tabs, body);

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
