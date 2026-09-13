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
import { el, clear, button, badge, icon, toast, toastError, withBusy, errorState, confirmDialog } from '../ui.js';
import { initials } from '../format.js';

const CHANNEL_HELP = {
  email: 'Pemberitahuan dokumen dan alarm sistem ke alamat e-mail akun Anda.',
  whatsapp: 'Lima alarm operasional (tenggat, eskalasi, penagihan, cadangan, penjadwal) sebagai pesan template WhatsApp.',
  webpush: 'Pemberitahuan di layar perangkat yang Anda daftarkan di kartu di bawah — satu baris kotak keluar per perangkat.',
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

  // Persetujuan melekat pada NOMOR: begitu angka nomor yang persetujuannya
  // TERCATAT diubah, centang dari persetujuan lama dilepas — bersama kalimat
  // "Nomor berubah" di bawah — dan orangnya harus mencentang lagi untuk nomor
  // baru (verifikasi P-3a, 12 Sep 2026: nomor baru distempel tanpa satu
  // tindakan pun). Dilepas HANYA pada peralihan dari nomor tersimpan itu, bukan
  // pada setiap ketikan: centang yang baru dipasang orangnya untuk nomor baru
  // tidak boleh hilang diam-diam ketika ia membetulkan satu angka, dan tanpa
  // persetujuan tercatat tidak ada yang perlu dibatalkan (verifikasi penutup
  // P-3a G-3: centang dilepas tanpa satu kalimat pun).
  const savedPhone = wa.phone_e164 || '';
  let previous = savedPhone;
  phone.addEventListener('input', () => {
    const current = phone.value.trim();
    const changed = current !== savedPhone;
    const leavingConsented = Boolean(wa.opt_in_at) && previous === savedPhone && changed;
    previous = current;
    if (leavingConsented && optIn.checked) optIn.checked = false;
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
          el('div', { text: `Sekarang di dalam jam tenang Anda: e-mail, WhatsApp dan web push yang ditulis saat ini berangkat ${wib(state.postponed_until)}.` }),
        ])
        : null,
      el('.check-row', [on, el('label', { for: 'quiet-on', text: 'Aktifkan jam tenang (WIB)' })]),
      el('.form-grid', { style: { marginTop: '8px' } }, [
        el('.field', [el('label', { for: 'quiet-start', text: 'Mulai' }), start]),
        el('.field', [el('label', { for: 'quiet-end', text: 'Selesai' }), end]),
      ]),
      el('.cell-sub', {
        style: { marginTop: '8px' },
        text: 'Selama jam tenang, KETIGA kanal luar — e-mail, WhatsApp dan web push — DITUNDA sampai jam selesai, tidak pernah dibuang. '
          + 'Pemberitahuan di dalam aplikasi tetap masuk seketika. Jendela boleh melintasi tengah malam (mis. 22:00–06:00). '
          + 'Zona waktu Asia/Jakarta (WIB).',
      }),
      el('.row-actions', { style: { marginTop: '12px' } }, [save]),
    ]),
  ]);
}


/* ------------------------------------------------------------- Web push
 *
 * P-3e (T3e.4). Tombol yang HANYA muncul ketika ia benar-benar bisa bekerja,
 * dan EMPAT jalan buntu yang masing-masing punya kalimatnya sendiri. Tombol
 * mati tanpa kalimat adalah cacat yang paling mahal di layar ini: orangnya
 * menekan, tidak terjadi apa-apa, dan tidak ada tempat untuk bertanya kenapa.
 *
 *  1. peramban tidak punya Push API sama sekali;
 *  2. iPhone/iPad: push baru ada sejak iOS 16.4 DAN hanya setelah aplikasinya
 *     dipasang lewat "Tambahkan ke Layar Utama". Di tab Safari biasa tombolnya
 *     TIDAK AKAN PERNAH bekerja — jadi yang ditampilkan adalah CARA
 *     memasangnya, bukan tombol yang gagal;
 *  3. izin sudah DITOLAK di tingkat peramban: aplikasi tidak bisa memintanya
 *     lagi (requestPermission langsung memulangkan 'denied' tanpa dialog), dan
 *     yang harus diubah adalah setelan situs di peramban;
 *  4. sisi server belum siap (VAPID kosong / sakelar Pengaturan mati) —
 *     kalimatnya datang dari `server_reason`, yaitu KALIMAT YANG SAMA yang
 *     ditulis DeliveryGate ke kolom "Galat / alasan", bukan kalimat kedua yang
 *     mirip.
 *
 * Urutannya sama dengan urutan DeliveryGate: dari yang paling global ke yang
 * paling pribadi. Menyuruh seseorang memasang aplikasi ke Layar Utama pada
 * pemasangan yang VAPID-nya kosong adalah menyuruhnya bekerja untuk tombol
 * yang tetap tidak akan mengirim apa pun.
 */

const IOS_INSTALL = 'Di iPhone dan iPad, pemberitahuan hanya bisa dinyalakan setelah aplikasi ini DITAMBAHKAN KE LAYAR UTAMA '
  + '(iOS/iPadOS 16.4 ke atas): di Safari tekan tombol Bagikan, pilih "Tambahkan ke Layar Utama", lalu buka Nusantara ERP '
  + 'dari ikonnya di layar utama dan kembali ke halaman ini. Di tab Safari biasa tombol ini tidak akan pernah bekerja — '
  + 'itu batas sistem operasinya, bukan setelan yang bisa diubah.';

const DENIED_HELP = 'Pemberitahuan DITOLAK untuk situs ini di peramban Anda, dan aplikasi tidak bisa memintanya lagi — '
  + 'permintaannya hanya boleh muncul sekali. Yang harus diubah adalah setelan situs di peramban: buka ikon gembok/info '
  + 'di sebelah alamat, cari "Pemberitahuan", ubah menjadi Izinkan (atau Tanya), lalu muat ulang halaman ini.';

const NO_API = 'Peramban ini tidak mendukung Push API, jadi pemberitahuan di luar aplikasi tidak bisa dinyalakan di sini. '
  + 'Yang mendukungnya: Chrome, Edge, Firefox dan Opera di Android/Windows/macOS/Linux, serta Safari di iOS 16.4+ '
  + 'lewat "Tambahkan ke Layar Utama". Pemberitahuan di dalam aplikasi (lonceng) tetap bekerja seperti biasa.';

/*
 * Jalan buntu 5 — dan kenapa ia BUKAN NO_API (putaran verifikasi: C-7).
 *
 * `'serviceWorker' in navigator` bernilai false di konteks yang tidak aman:
 * halaman tanpa TLS yang bukan localhost. Di sana kalimat NO_API menyalahkan PERAMBAN
 * dan menyodorkan daftar peramban lain — padahal peramban orang itu sudah
 * termasuk daftarnya, dan yang kurang ada di sisi pemasangan. Menyuruh orang
 * memasang peramban baru untuk masalah yang tidak ada padanya adalah bentuk
 * kalimat salah yang paling mahal: ia terdengar membantu.
 */
const NO_HTTPS = 'Halaman ini TIDAK dilayani lewat HTTPS, dan pemberitahuan di luar aplikasi hanya bisa dinyalakan '
  + 'di halaman HTTPS — itu aturan peramban, bukan setelan aplikasi. Peramban Anda tidak bermasalah; yang harus '
  + 'diubah ada di sisi pemasangan, jadi sampaikan kepada administrator (DEPLOYMENT.md §11.3). Pemberitahuan di '
  + 'dalam aplikasi (lonceng) tetap bekerja seperti biasa.';

/*
 * Jalan buntu 6: worker BELUM TERDAFTAR (putaran verifikasi: C-3).
 *
 * hasPushApi() hanya memeriksa ADANYA API, bukan adanya registrasi — dan
 * app.js sengaja menelan kegagalan pendaftaran worker (sw.js 404 sesudah rilis
 * yang setengah tersinkron, penyimpanan situs diblokir peramban). Tanpa jalan
 * buntu ini tombolnya DITAWARKAN, orangnya menekan, MEMBERIKAN izin
 * pemberitahuan — permanen, untuk tidak ada apa-apa — lalu
 * `navigator.serviceWorker.ready` tidak pernah selesai dan withBusy() berputar
 * sampai halaman dimuat ulang. Tombol mati tanpa kalimat, persis cacat yang
 * kartu ini ada untuk mencegahnya.
 */
const NO_WORKER = 'Aplikasi ini belum terpasang sebagai pekerja latar di peramban ini, jadi pemberitahuan di luar '
  + 'aplikasi belum bisa dinyalakan. Muat ulang halaman (Ctrl+Shift+R) dan coba lagi; kalau tetap begini, peramban '
  + 'Anda memblokir penyimpanan situs untuk alamat ini. Pemberitahuan di dalam aplikasi (lonceng) tetap bekerja.';

/*
 * Jalan buntu 7: kanalnya DIMATIKAN ORANGNYA di kartu di atas (putaran
 * verifikasi: C-5).
 *
 * `GET core/me/push-subscriptions` sudah menjawab `reason` — kalimat
 * DeliveryGate yang UTUH — dan versi pertama kartu ini hanya membaca
 * `server_reason`. Akibatnya orang yang menghapus centang "Web push" tetap
 * ditawari tombolnya dan, sesudah menekan, diberi tahu "pemberitahuan
 * berikutnya akan muncul" — sementara kotak keluar akan menulis "Dilewati —
 * Dimatikan pengguna di Profil › Notifikasi." pada setiap baris. Aturan berkas
 * ini sendiri: layar tidak boleh menjanjikan apa yang kotak keluar akan
 * lewati.
 *
 * Kalimatnya datang dari server apa adanya; yang ditambahkan klien hanyalah
 * PETUNJUK tindakannya, bukan sebab kedua.
 */
const USER_OFF_HINT = ' Hapus dulu keadaan itu di kartu "Kanal notifikasi" di atas: centang «Web push», lalu '
  + 'tekan Simpan pilihan kanal.';

/** base64url → Uint8Array. Ditulis tangan: applicationServerKey menuntut byte, bukan teks. */
function urlBase64ToUint8Array(value) {
  const padded = String(value).replace(/-/g, '+').replace(/_/g, '/');
  const base64 = padded + '='.repeat((4 - (padded.length % 4)) % 4);
  const raw = atob(base64);
  const bytes = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i += 1) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

/** Apple? — iPadOS 13+ menyamar sebagai Mac, jadi maxTouchPoints yang membedakannya. */
function isApple() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

/** Dibuka dari ikon Layar Utama (standalone), bukan dari tab peramban. */
function isInstalled() {
  return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
    || window.navigator.standalone === true;
}

function hasPushApi() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/*
 * Jalan buntu yang berlaku SEKARANG, atau null bila tombolnya boleh muncul.
 *
 * URUTANNYA ADALAH KALIMATNYA. Setiap baris di bawah memilih SATU kalimat dari
 * tujuh, jadi menukar dua baris berarti memberi orang yang sama nasihat yang
 * berbeda — dan pada pemasangan yang belum di belakang TLS, cabang iOS yang
 * berdiri lebih dulu memberi pengguna iPhone kalimat yang menyalahkan sistem
 * operasinya ("itu batas sistem operasinya, bukan setelan yang bisa diubah")
 * padahal yang kurang adalah HTTPS: ia akan memasang aplikasi ke Layar Utama,
 * dan tombolnya tetap tidak bekerja, karena service worker menuntut konteks
 * aman di mana pun (putaran penutup, V-7 — bentuk yang sama dengan C-7, yang
 * justru diangkat untuk menutupnya).
 *
 * Karena itu urutannya mengikuti DeliveryGate: dari yang paling GLOBAL ke yang
 * paling pribadi. `isSecureContext` adalah sifat PEMASANGAN — sama globalnya
 * dengan `server_reason` dan lebih global daripada perangkat yang dipegang
 * orangnya — jadi tempatnya sebelum cabang iOS, bukan sesudah.
 */
function pushBlocker(state) {
  if (state.server_reason) return { kind: 'server', text: state.server_reason };
  if (!window.isSecureContext) return { kind: 'insecure', text: NO_HTTPS };
  if (isApple() && !isInstalled()) return { kind: 'ios', text: IOS_INSTALL };
  if (!hasPushApi()) return { kind: 'unsupported', text: NO_API };
  if (state.no_worker) return { kind: 'no-worker', text: NO_WORKER };
  if (window.Notification && Notification.permission === 'denied') return { kind: 'denied', text: DENIED_HELP };
  if (state.user_off && state.reason) return { kind: 'user-off', text: state.reason + USER_OFF_HINT };
  return null;
}

/**
 * Keadaan worker di peramban INI: apakah ada registrasi sama sekali, dan
 * endpoint langganannya bila ada.
 *
 * Keduanya dibaca SEKALI, dari satu getRegistration(). Sebelum putaran
 * verifikasi (C-3) hanya endpoint-nya yang dibaca, dan "tidak ada registrasi"
 * tidak bisa dibedakan dari "belum berlangganan" — dua keadaan dengan dua
 * jalan keluar yang berbeda sama sekali.
 */
async function workerState() {
  if (!hasPushApi()) return { registered: false, endpoint: null };
  try {
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration) return { registered: false, endpoint: null };
    if (!registration.pushManager) return { registered: true, endpoint: null };
    const subscription = await registration.pushManager.getSubscription();
    return { registered: true, endpoint: subscription ? subscription.endpoint : null };
  } catch (error) {
    return { registered: false, endpoint: null };
  }
}

/** Langganan peramban ini sekarang — null bila belum ada worker atau belum berlangganan. */
async function currentSubscription() {
  if (!hasPushApi()) return null;
  try {
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration || !registration.pushManager) return null;
    return await registration.pushManager.getSubscription();
  } catch (error) {
    return null;
  }
}

async function subscribeHere(publicKey) {
  // requestPermission() HARUS dipanggil dari gestur pengguna: di luar gestur
  // peramban menolaknya diam-diam (Chrome memulangkan 'denied' tanpa dialog).
  // Karena itu ia dipanggil di sini, di dalam onClick, dan bukan saat layar
  // digambar.
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    // SATU KEADAAN, SATU KALIMAT (putaran verifikasi: C-6). Versi pertama
    // melempar kalimat pendek KEDUA untuk 'denied', sementara DENIED_HELP —
    // yang menyebut ikon gembok, menu Pemberitahuan, dan muat ulang — sudah
    // ditulis lengkap tepat di atas dan tidak pernah terlihat, karena kartu
    // baru berpindah ke jalan buntu 3 setelah halaman dimuat ulang.
    throw new Error(permission === 'denied'
      ? DENIED_HELP
      : 'Izin pemberitahuan belum diberikan — tekan "Izinkan" pada permintaan peramban.');
  }

  // `serviceWorker.ready` adalah janji yang TIDAK PERNAH SELESAI bila tidak
  // ada registrasi (putaran verifikasi: C-3). Tanpa batas waktu di sini,
  // withBusy() memasang pemutar di tombol dan `finally`-nya tidak pernah
  // berjalan: tombol berputar sampai orangnya memuat ulang halaman, sesudah
  // ia terlanjur memberikan izin pemberitahuan untuk tidak ada apa-apa.
  const registration = await Promise.race([
    navigator.serviceWorker.ready,
    new Promise((_, tolak) => setTimeout(() => tolak(new Error(NO_WORKER)), 8000)),
  ]);
  const options = { userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) };

  let subscription;
  let endpointLama = null;
  try {
    subscription = await registration.pushManager.subscribe(options);
  } catch (error) {
    // Langganan LAMA yang dibuat dengan applicationServerKey berbeda membuat
    // subscribe() menolak dengan InvalidStateError — itu persis yang terjadi
    // pada setiap perangkat setelah pemilik mengganti kunci VAPID. Yang benar
    // adalah membuang langganan lama lalu berlangganan dengan kunci baru.
    const stale = await registration.pushManager.getSubscription();
    if (!stale) throw error;
    // Endpoint lama DITANGKAP sebelum dibuang: berlangganan ulang memberi
    // endpoint BARU, dan tanpa nilai ini baris lama tinggal di server sebagai
    // perangkat hantu yang tidak pernah dibuang siapa pun — 404/410
    // menghapus, tetapi langganan lama sesudah ganti kunci dijawab 401/403
    // (putaran verifikasi: B-5/C-4).
    endpointLama = stale.endpoint || null;
    await stale.unsubscribe();
    subscription = await registration.pushManager.subscribe(options);
  }

  const json = subscription.toJSON();
  const muatan = { endpoint: json.endpoint, keys: json.keys };
  if (endpointLama && endpointLama !== json.endpoint) muatan.previous_endpoint = endpointLama;

  return api.post('core/me/push-subscriptions', muatan);
}

function deviceRow(device, thisEndpoint, reload) {
  const here = Boolean(thisEndpoint) && device.endpoint === thisEndpoint;

  return el('.push-device', { dataset: { id: String(device.id), here: here ? 'yes' : 'no' } }, [
    el('div', { style: { display: 'flex', gap: '8px', alignItems: 'baseline', flexWrap: 'wrap' } }, [
      el('b', { text: device.label }),
      here ? badge('Perangkat ini', 'green') : null,
    ]),
    el('.cell-sub', {
      text: `Didaftarkan ${wib(device.created_at)} · terakhir berhasil menerima `
        + `${device.last_success_at ? wib(device.last_success_at) : 'belum pernah'}`,
    }),
    el('.row-actions', { style: { marginTop: '6px' } }, [
      button('Cabut', {
        variant: 'danger', size: 'sm', iconName: 'trash',
        onClick: (event) => withBusy(event.currentTarget, async () => {
          if (!await confirmDialog({
            title: `Cabut «${device.label}»?`,
            message: here
              ? 'Perangkat ini berhenti menerima pemberitahuan di luar aplikasi. Lonceng di dalam aplikasi tetap aktif.'
              : 'Perangkat itu berhenti menerima pemberitahuan di luar aplikasi. Lonceng di dalam aplikasi tetap aktif.',
            confirmLabel: 'Cabut',
          })) return;
          try {
            await api.del(`core/me/push-subscriptions/${device.id}`);
            // Perangkat INI juga melepas langganannya di peramban: baris yang
            // dihapus di server tanpa unsubscribe meninggalkan langganan hidup
            // yang tidak dikenali siapa pun, dan peramban yang tidak pernah
            // melihat notifikasi boleh mencabutnya sendiri diam-diam.
            if (here) {
              const subscription = await currentSubscription();
              if (subscription) await subscription.unsubscribe();
            }
            toast('Perangkat dicabut.');
            await reload();
          } catch (error) {
            toastError(error);
          }
        }),
      }),
    ]),
  ]);
}

function pushCard(state, thisEndpoint, reload) {
  const blocker = pushBlocker(state);
  const devices = state.devices || [];
  const here = Boolean(thisEndpoint) && devices.some((device) => device.endpoint === thisEndpoint);

  const enable = button(here ? 'Daftarkan ulang perangkat ini' : 'Aktifkan notifikasi di perangkat ini', {
    variant: 'primary', iconName: 'bell',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        await subscribeHere(state.public_key);
        toast('Perangkat ini terdaftar — pemberitahuan berikutnya akan muncul walau aplikasinya tidak dibuka.');
        await reload();
      } catch (error) {
        toastError(error);
        // DAN KARTUNYA DIGAMBAR ULANG (putaran verifikasi: C-6). Keadaan yang
        // membuat percobaan ini gagal — izin baru saja menjadi 'denied', worker
        // ternyata tidak terdaftar — adalah keadaan yang punya jalan buntunya
        // sendiri; tanpa baris ini tombolnya tetap berdiri dan setiap tekanan
        // berikutnya menghasilkan toast yang sama.
        await reload();
      }
    }),
  });

  return el('.card.profil-push', [
    el('.card-head', [el('h2', { text: 'Pemberitahuan di luar aplikasi (web push)' }), el('.spacer')]),
    el('.card-body', [
      el('.muted', {
        style: { fontSize: '12.5px', marginBottom: '8px' },
        text: 'Pemberitahuan yang muncul di layar ponsel atau komputer Anda walau Nusantara ERP tidak sedang dibuka. '
          + 'Tidak ada aplikasi yang perlu dipasang dari toko aplikasi; setiap perangkat didaftarkan sekali, dari '
          + 'perangkat itu sendiri. Isi pesannya dienkripsi untuk perangkat Anda — layanan push tidak bisa membacanya.',
      }),
      blocker
        ? el('.alert.info.push-blocker', { dataset: { kind: blocker.kind } }, [
          icon('warn', 16),
          el('div', { text: blocker.text }),
        ])
        : el('.row-actions', { style: { marginBottom: '10px' } }, [enable]),
      devices.length
        ? el('.push-devices', devices.map((device) => deviceRow(device, thisEndpoint, reload)))
        : el('.cell-sub', { text: 'Belum ada perangkat terdaftar — selama itu setiap pemberitahuan web push tercatat Dilewati, bukan Terkirim.' }),
    ]),
  ]);
}


/* ---------------------------------------------------------------- Token API
 *
 * P-3d. Kredensial milik orangnya sendiri, dan TIGA kalimat yang tidak boleh
 * disusun layar ini:
 *
 *  - "Salin sekarang, tidak akan ditampilkan lagi" datang dari
 *    `data.shown_once` server. Ia BENAR karena yang disimpan server hanya
 *    sidik jari tokennya; sebuah layar yang menjanjikannya sendiri akan
 *    berbohong pada hari server berubah.
 *  - Daftar ability yang BOLEH dipilih datang dari `available_abilities` =
 *    izin pemanggil hari ini. Daftar yang disusun klien akan menawarkan izin
 *    yang tidak dimiliki pemakainya dan menghasilkan 422 yang tampak seperti
 *    kesalahan aplikasi.
 *  - Batas 365 hari dan laju 300/menit dibaca dari muatan, bukan diketik.
 *
 * Dan satu batas yang DIKATAKAN, bukan disembunyikan: ability menyempitkan
 * apa yang dijaga izin, dan sebagian endpoint aplikasi ini hanya menuntut
 * masuk — token terbatas menjangkau yang itu seperti sesi peramban pemiliknya.
 */

function tokenSecretCard(payload) {
  const field = el('input.token-value', { type: 'text', readOnly: true, value: payload.token });

  return el('.card.token-secret', { dataset: { state: 'shown' } }, [
    el('.card-head', [el('h2', { text: 'Token baru — tampil sekali' }), el('.spacer')]),
    el('.card-body', [
      el('.row-actions', { style: { gap: '8px', alignItems: 'center', flexWrap: 'wrap' } }, [
        field,
        button('Salin', {
          iconName: 'copy',
          onClick: async () => {
            field.select();
            try {
              await navigator.clipboard.writeText(payload.token);
              toast('Token disalin ke papan klip.');
            } catch (error) {
              toast('Peramban menolak papan klip. Teksnya sudah terpilih — tekan Ctrl+C.');
            }
          },
        }),
      ]),
      el('.cell-sub.token-once', { text: payload.shown_once }),
    ]),
  ]);
}

function tokenRow(row, reload) {
  return el('.token-row', { dataset: { id: String(row.id), expired: row.expired ? 'yes' : 'no' } }, [
    el('div', { style: { display: 'flex', gap: '8px', alignItems: 'baseline', flexWrap: 'wrap' } }, [
      el('b', { text: row.name }),
      row.expired ? badge('Kedaluwarsa', 'red') : badge('Berlaku', 'green'),
    ]),
    el('.cell-sub.token-abilities', { text: (row.abilities || []).join(', ') }),
    el('.cell-sub', {
      text: `Berlaku sampai ${wib(row.expires_at)} · dibuat ${wib(row.created_at)} · `
        + `terakhir dipakai ${row.last_used_at ? wib(row.last_used_at) : 'belum pernah'}`,
    }),
    el('.row-actions', { style: { marginTop: '6px' } }, [
      button('Cabut', {
        variant: 'danger', size: 'sm', iconName: 'trash',
        onClick: (event) => withBusy(event.currentTarget, async () => {
          if (!await confirmDialog({
            title: `Cabut token «${row.name}»?`,
            message: 'Klien yang masih memakainya akan mendapat 401 pada permintaan berikutnya. Token tidak bisa dikembalikan.',
            confirmLabel: 'Cabut',
          })) return;
          try {
            await api.del(`iam/me/api-tokens/${row.id}`);
            toast(`Token «${row.name}» dicabut.`);
            await reload();
          } catch (error) {
            toastError(error);
          }
        }),
      }),
    ]),
  ]);
}

function tokensCard(state, reload) {
  const name = el('input', { type: 'text', id: 'tok-name', placeholder: 'Integrasi akuntansi' });
  const days = el('input', { type: 'number', id: 'tok-days', min: '1', max: String(state.max_lifetime_days), value: '90' });
  const boxes = (state.available_abilities || []).map((ability) => {
    const box = el('input', { type: 'checkbox', id: `tok-ab-${ability}`, value: ability });
    return { ability, box, node: el('.check-row', [box, el('label', { for: `tok-ab-${ability}`, text: ability })]) };
  });

  const create = button('Buat token', {
    variant: 'primary', iconName: 'plus',
    onClick: (event) => withBusy(event.currentTarget, async () => {
      try {
        const payload = await api.post('iam/me/api-tokens', {
          name: name.value.trim(),
          abilities: boxes.filter((b) => b.box.checked).map((b) => b.ability),
          expires_in_days: Number(days.value),
        });
        name.value = '';
        boxes.forEach((b) => { b.box.checked = false; });
        await reload(payload);
      } catch (error) {
        toastError(error);
      }
    }),
  });

  const rows = (state.tokens || []).map((row) => tokenRow(row, reload));

  return el('.card.profil-tokens', [
    el('.card-head', [el('h2', { text: 'Token API' }), el('.spacer')]),
    el('.card-body', [
      el('.muted', {
        style: { fontSize: '12.5px', marginBottom: '8px' },
        text: 'Token dipakai sistem lain untuk memanggil API atas nama Anda. Teksnya tampil SEKALI saat dibuat. '
          + `Masa berlaku paling lama ${state.max_lifetime_days} hari, dan batas lajunya ${state.rate_limit_per_minute} permintaan per menit per token.`,
      }),
      el('.cell-sub.token-scope-note', {
        text: 'Ability sebuah token adalah SUBSET izin Anda: yang tidak dipilih ditolak 403, dan izin yang dicabut dari peran Anda '
          + 'mencabut aksesnya token juga. Yang TIDAK dibatasi ability adalah endpoint yang memang tidak dijaga izin apa pun — '
          + 'endpoint itu dijangkau token Anda persis seperti sesi peramban Anda.',
      }),
      rows.length ? el('div', rows) : el('.cell-sub', { text: 'Belum ada token.' }),
      el('.form-grid', { style: { marginTop: '12px' } }, [
        el('.field', [el('label', { for: 'tok-name', text: 'Nama token' }), name,
          el('.help', { text: 'Nama yang menyebutkan siapa yang memakainya, mis. "Integrasi akuntansi".' })]),
        el('.field', [el('label', { for: 'tok-days', text: 'Berlaku berapa hari' }), days,
          el('.help', { text: `1 sampai ${state.max_lifetime_days} hari.` })]),
      ]),
      // Kisi, bukan satu kolom panjang: seorang admin memegang 94 izin, dan 94
      // baris centang membuat kartunya 1.300 px — tombol "Buat token" jatuh
      // jauh di bawah lipatan pada layar mana pun (terukur S40, 12 Sep 2026).
      el('.field', { style: { marginTop: '8px' } }, [
        el('label', { text: 'Ability' }),
        el('.token-ability-grid', {
          style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '2px 12px' },
        }, boxes.map((b) => b.node)),
      ]),
      el('.row-actions', { style: { marginTop: '12px' } }, [create]),
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

  async function reload(justCreatedToken) {
    let state;
    let tokens;
    let push;
    try {
      state = await api.get('core/me/notification-channels');
      tokens = await api.get('iam/me/api-tokens');
      push = await api.get('core/me/push-subscriptions');
    } catch (error) {
      clear(body).appendChild(el('.card', el('.card-body', errorState(error, reload))));
      return;
    }
    clear(body);
    // Token yang baru lahir digambar DI ATAS, sekali. Memuat ulang layar
    // membuangnya — dan itu memang yang terjadi pada tokennya di server.
    if (justCreatedToken && justCreatedToken.token) body.appendChild(tokenSecretCard(justCreatedToken));
    body.appendChild(tokensCard(tokens, reload));
    body.appendChild(channelsCard(state, reload));
    // Endpoint langganan peramban INI — dibaca dari worker, bukan ditebak:
    // hanya dengan itu daftar perangkat bisa menandai "Perangkat ini".
    const worker = await workerState();
    body.appendChild(pushCard({ ...push, no_worker: !worker.registered }, worker.endpoint, reload));
    body.appendChild(phoneCard(state, reload));
    body.appendChild(quietHoursCard(state, reload));
  }

  clear(body).appendChild(el('.card', el('.card-body', el('.skeleton', { style: { height: '48px' } }))));
  await reload();
}

/* Dipakai harness/uji: nama-nama yang dijanjikan layar ini. */
export const PROFIL_SELECTORS = {
  tokens: '.profil-tokens',
  tokenRow: '.token-row',
  tokenSecret: '.token-secret',
  tokenOnce: '.token-once',
  tokenAbilities: '.token-abilities',
  channel: '.profil-channel',
  status: '.profil-status',
  phone: '.profil-phone',
  optin: '.profil-optin',
  quiet: '.profil-quiet',
  push: '.profil-push',
  pushDevice: '.push-device',
  pushBlocker: '.push-blocker',
};
