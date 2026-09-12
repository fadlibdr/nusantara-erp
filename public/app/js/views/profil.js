/* Profil & Notifikasi — P-3a (T3a.2, diperluas T3a.3).
 *
 * Layar milik ORANGNYA SENDIRI: kanal luar mana yang ia mau (e-mail,
 * WhatsApp), jam tenangnya, dan — sejak T3a.3 — nomor WhatsApp beserta
 * opt-in-nya. Tidak ada gerbang izin: setiap endpoint yang dipanggil di sini
 * hanya membaca dan menulis rekam pemanggil (core/me/*, iam/me/*).
 *
 * SATU ATURAN YANG DIPEGANG BERKAS INI: layar tidak boleh menjanjikan
 * "Anda akan menerima e-mail" bila kotak keluar akan menulis Dilewati.
 * Keadaan tiap kanal dibaca dari GET core/me/notification-channels, yang
 * menjawab dengan SEBAB YANG SAMA yang ditulis DeliveryGate ke kolom
 * "Galat / alasan" di Sistem › Pengiriman Notifikasi — bukan dari tebakan
 * klien. Sakelar yang nyala di atas server surel yang masih `log` tampil
 * sebagai "Dilewati: MAIL_MAILER=log — belum ada server surel", dan itu
 * benar.
 *
 * Preferensi ditulis LANGSUNG lewat PUT core/me/preferences/<kunci>, bukan
 * lewat prefs.set(): kedua kunci ini dibaca SERVER (pengirim), bukan SPA,
 * jadi tidak butuh cermin localStorage — dan 422 dari whitelist server harus
 * sampai ke orangnya sebagai kalimat, bukan ke konsol (prefs.set menelannya,
 * dengan alasan yang benar untuk favorit).
 *
 * Jam tenang MENUNDA, tidak membuang: kalimatnya ditulis di layar persis
 * seperti itu, karena orang yang memasang 22:00–06:00 harus tahu alarm pukul
 * 02.00 tetap masuk kotak masuknya seketika dan e-mail-nya berangkat 06.00. */

import { api, session } from '../api.js';
import { el, clear, button, badge, icon, toast, toastError, withBusy, errorState } from '../ui.js';
import { initials } from '../format.js';

const CHANNEL_HELP = {
  email: 'Pemberitahuan dokumen dan alarm sistem ke alamat e-mail akun Anda.',
  whatsapp: 'Lima alarm operasional (tenggat, eskalasi, penagihan, cadangan, penjadwal) sebagai pesan template WhatsApp.',
};

/** "12 Sep 2026 06:00 WIB" dari ISO-8601 — dalam WIB, karena jam tenangnya WIB. */
function wib(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const parts = new Intl.DateTimeFormat('id-ID', {
    timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(d).reduce((acc, p) => ({ ...acc, [p.type]: p.value }), {});
  return `${parts.day} ${parts.month} ${parts.year} ${parts.hour}:${parts.minute} WIB`;
}

function accountCard(user) {
  return el('.card', [
    el('.card-head', [el('h2', { text: 'Akun' }), el('.spacer')]),
    el('.card-body', [
      el('div', { style: { display: 'flex', gap: '12px', alignItems: 'center', marginBottom: '12px' } }, [
        el('.avatar', { text: initials(user.name), style: { width: '42px', height: '42px', fontSize: '15px' } }),
        el('div', [el('b', { text: user.name }), el('.muted', { text: user.email, style: { fontSize: '12.5px' } })]),
      ]),
      el('dl.kv', [
        el('dt', { text: 'Peran' }),
        el('dd', { text: (user.roles || []).join(', ') || '—' }),
        el('dt', { text: 'Kata sandi' }),
        el('dd', { text: 'Ganti kata sandi dan panduan onboarding ada di menu akun (nama Anda di pojok kanan atas).' }),
      ]),
    ]),
  ]);
}

/* Baris keadaan satu kanal: lencana + kalimatnya. Kalimatnya dari server —
   ini yang membedakan "akan dicoba" dari "Dilewati karena …". */
function channelStatus(channel) {
  if (channel.will_deliver) {
    return el('.cell-sub.profil-status', { dataset: { state: 'ready' } }, [
      badge('Akan dikirim', 'green'), ' ', `ke ${channel.address}`,
    ]);
  }
  return el('.cell-sub.profil-status', { dataset: { state: 'skipped' } }, [
    badge('Dilewati', ''), ' ', channel.reason || 'Kanal ini tidak akan mengirim.',
  ]);
}

function channelsCard(state, reload) {
  const boxes = {};
  const rows = (state.channels || []).map((channel) => {
    const box = el('input', { type: 'checkbox', id: `chan-${channel.channel}` });
    box.checked = Boolean(channel.enabled_by_user);
    boxes[channel.channel] = box;
    return el('.profil-channel', { dataset: { channel: channel.channel }, style: { padding: '10px 0', borderTop: '1px solid var(--border)' } }, [
      el('.check-row', [box, el('label', { for: `chan-${channel.channel}`, text: channel.label })]),
      el('.cell-sub', { text: CHANNEL_HELP[channel.channel] || '' }),
      channelStatus(channel),
    ]);
  });

  const save = button('Simpan pilihan kanal', {
    variant: 'primary', iconName: 'check',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      const value = {};
      Object.keys(boxes).forEach((key) => { value[key] = boxes[key].checked; });
      try {
        await api.put('core/me/preferences/notify.channels', { value });
        // Bukan "akan tercatat 'Dilewati — dimatikan pengguna'": sebab yang lebih
        // global (mailer log, sakelar Pengaturan) menang di kotak keluar, dan
        // lencana di bawah membaca DeliveryGate yang sama (verifikasi P-3a).
        toast('Pilihan kanal disimpan — lencana di bawah menunjukkan sebab yang akan tercatat di kotak keluar.');
        await reload();
      } catch (error) {
        toastError(error);
      }
    }),
  });

  return el('.card', [
    el('.card-head', [el('h2', { text: 'Kanal notifikasi' }), el('.spacer')]),
    el('.card-body', [
      el('.muted', {
        style: { fontSize: '12.5px', marginBottom: '6px' },
        text: 'Pemberitahuan di dalam aplikasi (lonceng) selalu aktif dan tidak bisa dimatikan — ia kanal kebenaran. '
          + 'Di bawah ini kanal LUAR: lencana menunjukkan apa yang benar-benar akan terjadi hari ini, bukan apa yang Anda pilih.',
      }),
      ...rows,
      el('.row-actions', { style: { marginTop: '12px' } }, [save]),
    ]),
  ]);
}

/* Nomor WhatsApp + opt-in (T3a.3). Persetujuan BERTANGGAL: yang dikirim hanya
   sikap (true/false) dan nomornya; stempel "kapan, lewat apa" dipasang server
   (WhatsAppConsent, via 'profil') dan dibaca kembali dari jawaban. Mengganti
   nomor mengosongkan persetujuan lama — kalimat itu ditulis di layar. */
function phoneCard(state, reload) {
  const wa = state.whatsapp || {};
  const phone = el('input', { type: 'tel', id: 'wa-phone', placeholder: '+6281234567890', autocomplete: 'tel' });
  phone.value = wa.phone_e164 || '';
  const optIn = el('input', { type: 'checkbox', id: 'wa-optin' });
  optIn.checked = Boolean(wa.opt_in_at);

  const stamp = wa.opt_in_at
    ? el('.cell-sub.profil-optin', { dataset: { state: 'on' }, text: `Opt-in tercatat ${wib(wa.opt_in_at)} lewat ${wa.opt_in_via === 'admin' ? 'administrator' : 'Profil'}.` })
    : el('.cell-sub.profil-optin', { dataset: { state: 'off' }, text: 'Belum ada persetujuan tercatat — WhatsApp tidak akan dikirim ke nomor ini.' });

  // Persetujuan melekat pada NOMOR: begitu angkanya diubah, kotak yang
  // tercentang dari persetujuan nomor lama dilepas dan orangnya harus
  // mencentang lagi untuk nomor baru — kalau tidak, "centang lagi bila nomor
  // baru juga disetujui" di bawah tidak pernah terjadi (verifikasi P-3a,
  // 12 Sep 2026: nomor baru distempel tanpa satu tindakan pun).
  const savedPhone = wa.phone_e164 || '';
  phone.addEventListener('input', () => {
    const changed = phone.value.trim() !== savedPhone;
    if (changed && optIn.checked) optIn.checked = false;
    if (changed && wa.opt_in_at) {
      stamp.dataset.state = 'off';
      stamp.textContent = 'Nomor berubah — persetujuan nomor lama tidak berlaku; centang bila nomor baru disetujui.';
    } else if (!changed && wa.opt_in_at) {
      stamp.dataset.state = 'on';
      stamp.textContent = `Opt-in tercatat ${wib(wa.opt_in_at)} lewat ${wa.opt_in_via === 'admin' ? 'administrator' : 'Profil'}.`;
      optIn.checked = true;
    }
  });

  const readiness = el('.cell-sub', {
    text: `Kesiapan kanal di server: penyedia ${wa.provider || 'meta'}, kredensial ${wa.configured ? 'terisi' : 'belum diisi'}, `
      + `template disetujui Meta ${wa.templates_ready ?? 0} dari ${wa.templates_total ?? 5} — prasyarat pemilik (docs/KEPUTUSAN-INTEGRASI.md).`,
  });

  const save = button('Simpan nomor & opt-in', {
    variant: 'primary', iconName: 'check',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        const payload = await api.put('iam/me/phone', { phone_e164: phone.value.trim() || null, whatsapp_opt_in: optIn.checked });
        // Jawabannya berbentuk auth/me: salinan sesi ikut diperbarui.
        if (payload && payload.id) session.setUser(payload);
        toast('Nomor WhatsApp disimpan.');
        await reload();
      } catch (error) {
        // 422 E.164 dari server: kalimatnya ke orangnya, di bawah kotaknya.
        toastError(error);
      }
    }),
  });

  return el('.card.profil-phone', [
    el('.card-head', [el('h2', { text: 'Nomor WhatsApp' }), el('.spacer')]),
    el('.card-body', [
      el('.form-grid', [
        el('.field', [el('label', { for: 'wa-phone', text: 'Nomor (format internasional E.164)' }), phone,
          el('.help', { text: 'Contoh +6281234567890, tanpa spasi/strip. Nomor lokal 08… diterima dan diubah ke +62. Kosongkan untuk menghapus.' })]),
        el('.field', [
          el('label', { text: 'Persetujuan' }),
          el('.check-row', [optIn, el('label', { for: 'wa-optin', text: 'Saya setuju menerima pemberitahuan Nusantara ERP lewat WhatsApp di nomor ini' })]),
          stamp,
        ]),
      ]),
      el('.cell-sub', { style: { marginTop: '6px' }, text: 'Mengganti nomor mengosongkan persetujuan lama; centang lagi bila nomor baru juga disetujui.' }),
      readiness,
      el('.row-actions', { style: { marginTop: '12px' } }, [save]),
    ]),
  ]);
}

function quietHoursCard(state, reload) {
  const quiet = state.quiet_hours;
  const on = el('input', { type: 'checkbox', id: 'quiet-on' });
  on.checked = Boolean(quiet);
  const start = el('input', { type: 'time', id: 'quiet-start' });
  const end = el('input', { type: 'time', id: 'quiet-end' });
  start.value = quiet ? quiet.start : '22:00';
  end.value = quiet ? quiet.end : '06:00';
  const syncDisabled = () => { start.disabled = !on.checked; end.disabled = !on.checked; };
  on.addEventListener('change', syncDisabled);
  syncDisabled();

  const save = button('Simpan jam tenang', {
    variant: 'primary', iconName: 'check',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      const value = on.checked ? { start: start.value, end: end.value } : false;
      try {
        await api.put('core/me/preferences/notify.quiet_hours', { value });
        toast(on.checked
          ? `Jam tenang ${start.value}–${end.value} WIB disimpan. E-mail/WhatsApp di jendela itu ditunda sampai ${end.value}, tidak dibuang.`
          : 'Jam tenang dimatikan.');
        await reload();
      } catch (error) {
        // 422 whitelist server (mis. jam mulai = jam selesai): kalimatnya ke orangnya.
        toastError(error);
      }
    }),
  });

  return el('.card.profil-quiet', [
    el('.card-head', [el('h2', { text: 'Jam tenang' }), el('.spacer')]),
    el('.card-body', [
      state.quiet_now
        ? el('.alert.info', { style: { marginBottom: '10px' } }, [
          icon('warn', 16),
          el('div', { text: `Sekarang di dalam jam tenang Anda: e-mail/WhatsApp yang ditulis saat ini berangkat ${wib(state.postponed_until)}.` }),
        ])
        : null,
      el('.check-row', [on, el('label', { for: 'quiet-on', text: 'Aktifkan jam tenang (WIB)' })]),
      el('.form-grid', { style: { marginTop: '8px' } }, [
        el('.field', [el('label', { for: 'quiet-start', text: 'Mulai' }), start]),
        el('.field', [el('label', { for: 'quiet-end', text: 'Selesai' }), end]),
      ]),
      el('.cell-sub', {
        style: { marginTop: '8px' },
        text: 'Selama jam tenang, e-mail dan WhatsApp DITUNDA sampai jam selesai — tidak pernah dibuang. '
          + 'Pemberitahuan di dalam aplikasi tetap masuk seketika. Jendela boleh melintasi tengah malam (mis. 22:00–06:00). '
          + 'Zona waktu Asia/Jakarta (WIB).',
      }),
      el('.row-actions', { style: { marginTop: '12px' } }, [save]),
    ]),
  ]);
}

export async function renderProfil(host) {
  clear(host);
  host.appendChild(el('.page-head', [
    el('div', [
      el('h1', { text: 'Profil & Notifikasi' }),
      el('.desc', { text: 'Kanal pemberitahuan luar dan jam tenang milik Anda sendiri — lonceng di dalam aplikasi selalu aktif.' }),
    ]),
    el('.actions', [button('', { iconName: 'refresh', title: 'Muat ulang', onClick: () => renderProfil(host) })]),
  ]));

  const user = session.user || {};
  host.appendChild(accountCard(user));

  const body = el('div');
  host.appendChild(body);

  async function reload() {
    let state;
    try {
      state = await api.get('core/me/notification-channels');
    } catch (error) {
      clear(body).appendChild(el('.card', el('.card-body', errorState(error, reload))));
      return;
    }
    clear(body);
    body.appendChild(channelsCard(state, reload));
    body.appendChild(phoneCard(state, reload));
    body.appendChild(quietHoursCard(state, reload));
  }

  clear(body).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '48px' } }))));
  await reload();
}

/* Dipakai harness/uji: nama-nama yang dijanjikan layar ini. */
export const PROFIL_SELECTORS = {
  channel: '.profil-channel',
  status: '.profil-status',
  phone: '.profil-phone',
  optin: '.profil-optin',
  quiet: '.profil-quiet',
};
