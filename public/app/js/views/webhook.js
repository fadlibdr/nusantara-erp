/* Sistem › Webhook — langganan keluar dan log pengirimannya (P-3d).
 *
 * DUA ATURAN YANG DIPEGANG BERKAS INI, dan keduanya tentang kejujuran:
 *
 * 1. RAHASIA TAMPIL SEKALI, dan layar mengatakannya dengan kalimat SERVER.
 *    Teks rahasia hanya ada di dalam jawaban permintaan yang membuat atau
 *    memutar langganannya; tidak ada pintu kedua yang membacakannya kembali.
 *    Kalimat "Salin sekarang…" datang dari `data.shown_once`, bukan diketik di
 *    sini — kalau server berubah pendapat, layar ikut berubah.
 *
 * 2. LAYAR TIDAK MENYUSUN SATU KALIMAT STATUS PUN SENDIRI. Status pengiriman
 *    ('terkirim', 'gagal', sebabnya), kalimat penonaktifan otomatis, label
 *    "Semua jenis dokumen", dan resep tanda tangan semuanya digambar apa adanya
 *    dari muatan API. Yang dipilih SPA hanya warnanya. Sebuah layar yang
 *    menyimpulkan "terkirim" dari kolom lain akan suatu hari berbeda pendapat
 *    dengan baris logya sendiri — dan yang dipercaya orang adalah layar.
 */

import { api } from '../api.js';
import { el, clear, button, badge, toast, toastError, withBusy, errorState, emptyState, confirmDialog } from '../ui.js';

const STATUS_TONE = { sent: 'green', queued: '', failed: 'red' };
const STATUS_LABEL = { sent: 'Terkirim', queued: 'Menunggu', failed: 'Gagal' };

/** "12 Sep 2026 17:05" dari ISO-8601, dalam WIB. */
function wib(iso) {
  if (!iso) return '—';
  const parts = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date(iso)).reduce((acc, p) => ({ ...acc, [p.type]: p.value }), {});
  return `${parts.day} ${parts.month} ${parts.year} ${parts.hour}:${parts.minute}`;
}

/* Rahasia yang baru lahir: satu kotak yang bisa disalin, dengan kalimat server
   di bawahnya. Ia hidup di layar sampai orangnya menutupnya, dan tidak pernah
   bisa dibuat muncul lagi tanpa memutar rahasianya. */
function secretCard(payload) {
  const field = el('input.secret-value', { type: 'text', readOnly: true, value: payload.secret });

  return el('.card.webhook-secret', { dataset: { state: 'shown' } }, [
    el('.card-head', [el('h2', { text: 'Rahasia langganan — tampil sekali' }), el('.spacer')]),
    el('.card-body', [
      el('.row-actions', { style: { gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }, [
        field,
        button('Salin', {
          iconName: 'copy',
          onClick: async () => {
            field.select();
            try {
              await navigator.clipboard.writeText(payload.secret);
              toast('Rahasia disalin ke papan klip.');
            } catch (error) {
              // Papan klip ditolak peramban (izin, konteks tidak aman): teksnya
              // sudah terpilih, jadi Ctrl+C tetap bekerja — dan itu yang
              // dikatakan, bukan "disalin" yang tidak terjadi.
              toast('Peramban menolak papan klip. Teksnya sudah terpilih — tekan Ctrl+C.');
            }
          },
        }),
      ]),
      el('.cell-sub.secret-note', { text: payload.shown_once }),
    ]),
  ]);
}

function signatureCard(state) {
  const s = state.signature || {};
  return el('.card.webhook-signature', [
    el('.card-head', [el('h2', { text: 'Cara penerima memeriksa kiriman' }), el('.spacer')]),
    el('.card-body', [
      el('dl.kv', [
        el('dt', { text: 'Header' }), el('dd', { text: s.header || '' }),
        el('dt', { text: 'Algoritma' }), el('dd', { text: s.algorithm || '' }),
        el('dt', { text: 'Yang ditandatangani' }), el('dd', { text: s.signed_value || '' }),
        el('dt', { text: 'Id peristiwa' }), el('dd', { text: s.event_header || '' }),
        el('dt', { text: 'Jendela waktu' }), el('dd', { text: `${s.tolerance_seconds ?? ''} detik` }),
        el('dt', { text: 'Bentuk rahasia' }), el('dd', { text: s.secret_form || '' }),
        el('dt', { text: 'Versi muatan' }), el('dd', { text: String(state.payload_version ?? '') }),
      ]),
      el('.cell-sub', { text: s.note || '' }),
    ]),
  ]);
}

function subscriptionRow(row, reload) {
  const disabled = Boolean(row.disabled_at);

  const actions = el('.row-actions', { style: { gap: '6px', flexWrap: 'wrap' } }, [
    button('Putar rahasia', {
      iconName: 'refresh',
      onClick: (event) => withBusy(event.currentTarget, async () => {
        if (!await confirmDialog({
          title: 'Putar rahasia langganan?',
          message: 'Penerima yang masih memakai rahasia lama akan menolak kiriman berikutnya sampai rahasia baru dipasang di sana.',
          confirmLabel: 'Putar',
        })) return;
        try {
          const payload = await api.post(`core/webhooks/${row.id}/rotate-secret`);
          await reload(payload);
        } catch (error) { toastError(error); }
      }),
    }),
    disabled ? button('Aktifkan lagi', {
      variant: 'primary', iconName: 'check',
      onClick: (event) => withBusy(event.currentTarget, async () => {
        try {
          await api.post(`core/webhooks/${row.id}/enable`);
          toast('Langganan diaktifkan lagi.');
          await reload();
        } catch (error) { toastError(error); }
      }),
    }) : null,
    button('Hapus', {
      variant: 'danger', iconName: 'trash',
      onClick: (event) => withBusy(event.currentTarget, async () => {
        if (!await confirmDialog({
          title: `Hapus langganan «${row.name}»?`,
          message: 'Kiriman berhenti seketika. Log pengiriman yang sudah ada tetap tersimpan.',
          confirmLabel: 'Hapus',
        })) return;
        try {
          await api.del(`core/webhooks/${row.id}`);
          toast('Langganan dihapus. Log pengiriman yang sudah ada tetap tersimpan.');
          await reload();
        } catch (error) { toastError(error); }
      }),
    }),
  ]);

  return el('.webhook-subscription', { dataset: { id: String(row.id), state: disabled ? 'disabled' : (row.is_active ? 'active' : 'off') } }, [
    el('div', { style: { display: 'flex', gap: '8px', alignItems: 'baseline', flexWrap: 'wrap' } }, [
      el('b', { text: row.name }),
      disabled ? badge('Dinonaktifkan otomatis', 'red') : (row.is_active ? badge('Aktif', 'green') : badge('Dimatikan', '')),
    ]),
    el('.cell-sub.webhook-url', { text: row.url }),
    el('.cell-sub', { text: `${(row.events || []).join(', ')} — ${row.document_types_label || ''}` }),
    // Kalimat penonaktifan datang dari server apa adanya: ia menyebut BERAPA
    // kali gagal dan SEBAB terakhirnya, dan layar tidak menebak keduanya.
    disabled ? el('.cell-sub.webhook-disabled-reason', { text: row.disabled_reason || '' }) : null,
    el('.cell-sub', {
      text: `Berhasil terakhir ${wib(row.last_success_at)} · gagal terakhir ${wib(row.last_failure_at)} · `
        + `gagal beruntun ${row.consecutive_failures ?? 0}`,
    }),
    actions,
  ]);
}

function formCard(state, reload) {
  const name = el('input', { type: 'text', id: 'wh-name', placeholder: 'Akuntansi eksternal' });
  const url = el('input', { type: 'url', id: 'wh-url', placeholder: 'https://contoh.co.id/nusantara/webhook' });
  const boxes = (state.selectable_events || []).map((event) => {
    const box = el('input', { type: 'checkbox', id: `wh-ev-${event}`, value: event });
    return { event, box, node: el('.check-row', [box, el('label', { for: `wh-ev-${event}`, text: event })]) };
  });

  const save = button('Buat langganan', {
    variant: 'primary', iconName: 'plus',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        const payload = await api.post('core/webhooks', {
          name: name.value.trim(),
          url: url.value.trim(),
          events: boxes.filter((b) => b.box.checked).map((b) => b.event),
        });
        name.value = '';
        url.value = '';
        boxes.forEach((b) => { b.box.checked = false; });
        await reload(payload);
      } catch (error) {
        toastError(error);
      }
    }),
  });

  return el('.card.webhook-form', [
    el('.card-head', [el('h2', { text: 'Langganan baru' }), el('.spacer')]),
    el('.card-body', [
      el('.form-grid', [
        el('.field', [el('label', { for: 'wh-name', text: 'Nama' }), name]),
        el('.field', [el('label', { for: 'wh-url', text: 'URL penerima' }), url,
          el('.help', { text: 'Harus https dan bisa dijangkau dari luar. Alamat di dalam jaringan server ini ditolak.' })]),
      ]),
      el('.field', { style: { marginTop: '8px' } }, [el('label', { text: 'Peristiwa' }), ...boxes.map((b) => b.node)]),
      el('.cell-sub', { text: 'Tanpa memilih jenis dokumen, langganan menerima semua jenis dokumen.' }),
      el('.row-actions', { style: { marginTop: '12px' } }, [save]),
    ]),
  ]);
}

function deliveryTable(rows) {
  const head = el('tr', [
    el('th', { text: 'Waktu' }), el('th', { text: 'Peristiwa' }), el('th', { text: 'Dokumen' }),
    el('th', { text: 'Langganan' }), el('th', { text: 'Status' }), el('th', { text: 'Percobaan' }),
  ]);

  const body = rows.map((row) => el('tr.webhook-delivery', { dataset: { status: row.status } }, [
    el('td', { text: wib(row.created_at) }),
    el('td', { text: row.event }),
    el('td', [
      el('div', { text: row.document_code || `#${row.document_id}` }),
      el('.cell-sub', { text: row.document_type }),
    ]),
    el('td', { text: row.subscription_name }),
    el('td', [
      badge(STATUS_LABEL[row.status] || row.status, STATUS_TONE[row.status] ?? ''),
      // SEBABNYA DIGAMBAR APA ADANYA. Sebuah baris `failed` tanpa kalimat
      // adalah baris yang menyuruh orang menebak.
      row.error ? el('.cell-sub.webhook-error', { text: row.error }) : null,
    ]),
    el('td', { text: String(row.attempts ?? 0) }),
  ]));

  return el('.table-wrap', el('table.table', [el('thead', head), el('tbody', body.length ? body : [
    el('tr', el('td', { colspan: '6', text: 'Belum ada pengiriman.' })),
  ])]));
}

export async function renderWebhook(host) {
  clear(host);
  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Webhook' }),
      el('.desc', { text: 'Pemberitahuan keluar ke sistem lain setiap kali sebuah dokumen diajukan, disetujui, atau ditolak.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderWebhook(host) })]),
  ]));

  const body = el('div');
  host.appendChild(body);

  async function reload(justCreated) {
    let state;
    let deliveries;
    try {
      state = await api.get('core/webhooks');
      deliveries = await api.get('core/webhooks/deliveries', { per_page: 25 });
    } catch (error) {
      clear(body).appendChild(el('.card', el('.card-body', errorState(error, reload))));
      return;
    }

    clear(body);

    if (justCreated && justCreated.secret) body.appendChild(secretCard(justCreated));

    body.appendChild(el('.card', [
      el('.card-head', [
        el('h2', { text: 'Langganan' }),
        el('.spacer'),
        el('.cell-sub', { text: `Dinonaktifkan otomatis setelah ${state.disable_after_failures} pengiriman gagal berturut-turut.` }),
      ]),
      el('.card-body', (state.subscriptions || []).length
        ? (state.subscriptions || []).map((row) => subscriptionRow(row, reload))
        : [emptyState('Belum ada langganan webhook. Tambahkan satu di kartu di bawah.', { title: 'Belum ada langganan' })]),
    ]));

    body.appendChild(formCard(state, reload));
    body.appendChild(signatureCard(state));

    body.appendChild(el('.card.webhook-log', [
      el('.card-head', [el('h2', { text: 'Log pengiriman' }), el('.spacer')]),
      el('.card-body', [deliveryTable(Array.isArray(deliveries) ? deliveries : [])]),
    ]));
  }

  clear(body).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '48px' } }))));
  await reload();
}

/* Dipakai harness/uji: nama-nama yang dijanjikan layar ini. */
export const WEBHOOK_SELECTORS = {
  subscription: '.webhook-subscription',
  secret: '.webhook-secret',
  secretNote: '.secret-note',
  delivery: '.webhook-delivery',
  error: '.webhook-error',
  signature: '.webhook-signature',
  disabledReason: '.webhook-disabled-reason',
};
