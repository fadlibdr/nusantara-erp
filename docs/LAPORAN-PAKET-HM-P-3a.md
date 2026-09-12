# Laporan Paket HM P-3a — Kanal notifikasi (SMTP + template, preferensi & jam tenang, WhatsApp, ulang-kirim)

**Cabang:** `feat/phase3-p3a` (dari `main` 649601d) · **Tanggal:** 11–12 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 3 / P-3a (10 hari-orang), tugas T3a.0–T3a.4
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik; deploy dilarang untuk agen di alur kerja ini)

---

## 0. Satu kalimat

Paket ini seluruhnya tentang satu kata: **"terkirim"**. Sebelum P-3a, dengan `MAIL_MAILER=log`
(keadaan `.env` pengembangan DAN produksi) sebuah pengajuan tagihan menghasilkan baris kotak
keluar berstatus `sent` dengan `provider_id` `…@example.co.id` — Message-ID buatan Symfony di
mesin ini sendiri, tanpa satu server surel pun di baliknya (diukur 11 Sep 2026, uji probe
sebelum satu baris kode diubah). Sesudah P-3a: **`sent` menuntut pengenal dari penyedia**, setiap
baris yang tidak dikirim berstatus **`skipped` dengan kalimat Indonesia yang menyebut apa yang
kurang** — di kotak keluar, di Kirim ulang, di job, dan di layar Profil — dan kanal WhatsApp
**ada** tetapi tidak mengirim satu pesan pun sampai tiga prasyarat pemilik terpenuhi.

Tidak ada surel atau pesan yang keluar dari mesin ini selama pembangunan (transport tangkap di
uji, `Http::preventStrayRequests()` di setiap uji WhatsApp, `MAIL_MAILER=log` di harness).

---

## 1. Tugas → status → bukti

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T3a.0 | Cabut penolakan WhatsApp tertulis di `docs/KEPUTUSAN-INTEGRASI.md` (bentuk SIKAP-E-SIGN) — SEBELUM satu baris kode | ✅ | `34370ed` — apa yang dulu ditolak & di mana (ROADMAP-DEVIASI baris 18, LAPORAN-DEVIASI-v2, kedua PANDUAN; **ASSESSMENT ternyata tidak memuat kata "WhatsApp"** — `grep -in whatsapp docs/ASSESSMENT*.md` = 0 baris), mengapa dicabut, TIGA prasyarat pemilik, penyedia (Meta langsung / Qontak / Fonnte ditolak), apa yang terjadi sebelum prasyarat terpenuhi; catatan kaki di ROADMAP-DEVIASI baris 18 |
| 1 | Kejujuran status (perangkap A): audit `MAIL_MAILER=log` → `sent` | ✅ **cacat nyata ditemukan & ditutup** | `0c71b28`, `626e159` — probe: `status=sent provider_id='49220f04…@example.co.id'`. Kini `Core\Support\MailTransport` (transport log/array/null = tidak keluar dari mesin; **TRANSPORT** yang diperiksa, bukan hanya nama mailer), `DeliveryGate` (satu daftar sebab, empat permukaan), `DeliverySkippedException` → `skipped` tanpa ulang, `DeliveryRejectedException` → `failed` seketika, `sent` hanya dengan pengenal. `Iam\Support\PasswordHelp` membaca predikat yang sama. `DeliveryHonestyTest` **13 uji**; 5 mutasi merah (§4) |
| T3a.1 | Template per peristiwa (5) untuk e-mail, satu tempat pemetaan; peristiwa lain memakai template umum DAN itu dikatakan | ✅ | `f0fcbcb` — `NotificationTemplates` (lima kunci literal), `core_notifications.template` (migrasi Core **001801**), `EventNotificationMail` (awalan subjek `[Cadangan]` dst.), `system(..., $template)` menolak kunci salah eja; watchdog → `scheduler.down`, 7 alarm backup-watch → `backup.stale`, approval-watch → `approval.escalated` HANYA eskalasi, deadline-watch → `ar.dunning` untuk invoice pelanggan LEWAT / `deadline.due` lainnya / umum untuk TANPA_TANGGAL. `NotificationTemplatesTest` **12 uji** menjalankan keempat perintah sungguhan; 4 mutasi merah |
| T3a.2 | Preferensi kanal per pengguna + jam tenang (TUNDA, bukan buang); tulis pilihan tempat penyimpanan; layar Profil | ✅ | `a662467` — dua kunci di `UserPreferences` (§9 keputusan A), `QuietHours` (Asia/Jakarta; melintasi tengah malam diuji dua sisi + dua batas), penundaan di kotak keluar, Kirim ulang, DAN job (`release()` pekerja sungguhan diukur: `available_at` = akhir jendela), `GET core/me/notification-channels`, `#/profil` (`views/profil.js`, NAV Ringkasan, menu akun), kolom "Berikutnya", `SHELL_VERSION` 8 → 9. `NotificationPreferencesTest` **14 uji**; 5 mutasi merah |
| T3a.3 | `users.phone_e164` + opt-in berstempel (Iam **000252**); `WhatsAppChannel` atas `Http::`; webhook publik bertanda tangan; konfigurasi + 5 nama template dari env, KOSONG di repo | ✅ | `6a019de`, `1760245` — `PhoneNumber` (E.164 ketat, 08… → +62…), `WhatsAppConsent` (satu-satunya penulis tiga kolom; ganti nomor mengosongkan stempel), `PUT iam/me/phone` (via `profil`) + `PUT iam/users/{id}` (via `admin`), `WhatsAppSetup`, `WhatsAppChannel` (Meta Cloud API, template + 3 parameter, wamid wajib, kode permanen vs sementara), `ProviderErrorScrubber`, `WhatsAppWebhookController` (GET verifikasi, POST HMAC atas badan mentah, hanya baris cocok), migrasi Core **001802** (`provider_status`, `provider_status_at`, indeks `provider_id`), `config/erp.php whatsapp.*` dari `.env`, `.env.example` NAMA variabel saja. `WhatsAppChannelTest` **13**, `WhatsAppWebhookTest` **8**, `PhoneOptInTest` **7** uji; 9 mutasi — **7 merah, 2 LOLOS HIJAU** lalu dipaku (§4) |
| T3a.4 | Verifikasi ulang-kirim 1/5/15/60 yang sudah ada dan paku; in-app tetap kanal kebenaran | ✅ | `6830a46` — `DeliveryRetryScheduleTest` **4 uji**: `[60, 300, 900, 3600]` dan `tries 5` literal; pekerja sungguhan lima kali dengan jam dimajukan → `next_attempt_at` DAN `available_at` job berjarak persis 60/300/900/3600 detik, lalu kosong + `failed`; flag unit `erp1-queue.service` (`--tries=5 --backoff=60`) ikut dipaku; kedua kanal luar mati → 2 baris kotak masuk seketika, 4 baris kotak keluar `skipped`, 0 HTTP; keduanya ditolak penyedia → kotak masuk tetap ada |
| 6 | DEPLOYMENT.md runbook SMTP + WhatsApp (variabel tanpa nilai) | ✅ | `ae6d7ef` — §11.1 SMTP 587 STARTTLS (ledger #8), §11.2 Meta (verifikasi bisnis, System User token, 5 template utility 3 placeholder, webhook + verify token, jalan pulang); Qontak/Fonnte dinyatakan; **tidak ada `.env` yang disentuh** |
| 7 | Uji PHP: Mail::fake tidak lagi cukup; `Http::fake` + `preventStrayRequests`; tanda tangan webhook dengan app secret palsu; penyaring dengan token palsu; mutasi | ✅ | **71 uji baru** di 7 berkas (13+12+14+13+8+7+4), 5 berkas uji lama diadaptasi; `tests/Support/CapturingMailTransport` + `UsesCapturingMailer`; **25 mutasi**: 23 merah, 2 LOLOS HIJAU dan dipaku (§4) |
| 8 | Harness S37 (Pengiriman Notifikasi menampilkan sebab `skipped`; Profil; desktop + ponsel) → `results-phase-3.json` | ✅ | `c756d48` — `[S37_kanal_notifikasi_profil] ok` (10 syarat), `[S37_kanal_notifikasi_profil_ponsel] ok` (6 syarat); fixture dari pipeline sungguhan (`erp:watchdog-alarm --force` atas ERP_DB); **8 kunci Fase 0 tetap, 2 ditambahkan**; 3 PNG di `docs/bukti-uji/` |
| 9 | Cangkang PWA | ✅ | `a662467` — `js/views/profil.js` di `SHELL`, `SHELL_VERSION` 8 → 9; `PwaServiceWorkerTest` hijau |
| 10 | `/app/` dimuat di Chromium, 0 galat konsol di setiap layar tersentuh | ✅ | §7 — `#/profil`, `#/r/core/notification-deliveries`, `#/r/iam/users`, `#/settings`, `#/dashboard` × 1440×900 dan 390×844, `console_errors: []`, `http_errors: []`, tidak ada gulir samping |
| 11 | Laporan ini | ✅ | berkas ini; sapuan dokumentasi §12 |

---

## 2. Empat keputusan yang membuat atau menggagalkan paket ini

### (A) `sent` menuntut pengenal — dan itu berarti `Mail::fake()` tidak lagi cukup

Aturan yang kelihatannya kecil ini merambat: `Mail::fake()` memulangkan `null` dari `send()`,
dan sejak P-3a `null` berarti "tidak ada bukti diterima" → percobaan gagal, bukan `sent`. Dua
uji lama (P-0b) mengharapkan `sent` dari `null` — persis klaim yang kini dilarang — dan diubah:
stub kanalnya menyebut pengenalnya, dan uji yang menguji jalur surel memakai
`tests/Support/CapturingMailTransport`, turunan `AbstractTransport` Symfony yang berlaku seperti
SMTP yang menjawab 250 tanpa mengirim apa pun. Transport `array` pun kini `skipped` (ia ada di
`MailTransport::UNDELIVERED`, dan memang tidak mengeluarkan apa-apa) — `QueueFailedJobsTest`
merah karenanya dan diberi transport tangkap (`626e159`).

### (B) Satu daftar sebab, empat permukaan — urutan dari yang paling global

Cacat berulang kampanye ini: aturan yang benar di satu permukaan bocor di permukaan lain. Maka
`DeliveryGate::reasonToSkip()` adalah satu-satunya tempat sebab `skipped` ditulis, dipanggil oleh
kotak keluar, Kirim ulang (422 + petunjuk), job (diperiksa **ulang** saat berjalan — sakelar
bisa dimatikan di antara tulis dan kirim), dan `GET core/me/notification-channels` (layar Profil).
Urutannya disengaja: Pengaturan → konfigurasi server → pilihan pengguna → alamat/nomor →
opt-in → template. Akibat yang terlihat di harness: dengan `MAIL_MAILER=log`, mematikan e-mail
di Profil **tersimpan** tetapi lencananya tetap "MAIL_MAILER=log" — sebab yang lebih global
menang, dan "Dimatikan pengguna" baru tampil setelah server surel ada. Itu bukan cacat; itu
mencegah "isi nomor Anda" disuruhkan kepada seseorang pada instalasi yang kanalnya belum ada.

### (C) Preferensi di `UserPreferences`, persetujuan di kolom

`notify.channels` dan `notify.quiet_hours` adalah "apa pun yang seseorang PILIH untuk dirinya
sendiri" (CONVENTIONS §15): whitelist, plafon, endpoint, dan cermin sudah ada; nol migrasi.
Yang menuntut kolom sungguhan adalah **persetujuan WhatsApp** — preferensi boleh dihapus tanpa
jejak, persetujuan tidak. Satu konsekuensi yang ditemukan uji: "mati" untuk jam tenang adalah
`false`, bukan `null` — kolom `value` NOT NULL dan preferensi tidak punya DELETE (§15: kunci
baru = satu entri, tidak pernah endpoint baru).

### (D) WhatsApp hanya template, dan tanpa kredensial tidak ada satu permintaan pun

Meta menolak teks bebas di luar jendela 24 jam; alarm sistem hampir tidak pernah di dalamnya.
Kanal ini mengirim template yang disetujui Meta dengan tiga placeholder tetap; notifikasi tanpa
kunci template (pengajuan dokumen, pengingat biasa) `skipped` dengan kalimat yang mengatakannya —
bukan dikirim sebagai teks bebas yang akan ditolak. Pemeriksaan konfigurasi berjalan SEBELUM
`Http::`; setiap uji WhatsApp memasang `Http::preventStrayRequests()` di `setUp`, jadi panggilan
yang tidak tertangkap `Http::fake()` menjatuhkan ujinya.

---

## 3. Dua cacat yang ditemukan uji sendiri (bukan oleh mutasi)

1. **Sentinel `'KEEP'` sampai ke `WhatsAppConsent` sebagai nomor telepon.** `UserService::
   splitConsent` memakai string sentinel untuk "nomor tidak dikirim"; `apply()` tidak mengenalnya,
   membaca "nomor berganti", dan mengosongkan stempel opt-in pada setiap sunting nama. Ditangkap
   `PhoneOptInTest` (administrator menyunting nama → stempel hilang). Diganti: nomor yang ada
   diteruskan apa adanya.
2. **Webhook menyimpan waktu tujuh jam lebih awal.** `CarbonImmutable::createFromTimestamp()`
   memulangkan objek berzona UTC; Eloquent memformat Carbon dalam zona objeknya, jadi 00:00 UTC
   tersimpan `"00:00"` dan terbaca 00:00 WIB. Kelas cacat yang sama dengan absensi F-4. Ditangkap
   `WhatsAppWebhookTest` (harapan pertama saya sendiri salah — 1789171200 adalah 07:00 WIB, dan
   uji yang benar merah terhadap kode yang salah). Dikonversi ke `app.timezone` sebelum disimpan;
   `QuietHours::resumeAt()` sudah melakukannya sejak awal dengan alasan yang sama.

---

## 4. Mutasi — 25 dijalankan, 23 merah, **2 LOLOS HIJAU** dan dipaku

Setiap mutasi diterapkan pada kode yang sudah di-commit, ujinya dijalankan, lalu dikembalikan
dengan `git checkout` (satu kali, pada langkah 1, `git checkout` atas berkas yang BELUM
di-commit menghapus suntingan saya sendiri — pelajaran: commit dulu, baru mutasi).

### Merah (23)

| # | Mutasi | Uji yang merah |
|---|---|---|
| M1 | job menerima pengenal kosong sebagai `sent` | `DeliveryHonestyTest` 2 uji |
| M2 | `MailTransport::leavesTheMachine()` selalu `true` | 10 uji (2 error, 8 gagal) |
| M3 | job melewati pemeriksaan ulang gerbang | 2 uji |
| M4 | Kirim ulang melewati gerbang | 2 uji |
| M5 | `DeliveryRejectedException` diperlakukan biasa (diulang) | 1 uji |
| MT1 | `MailChannel` mengabaikan template (selalu umum) | 2 uji |
| MT2 | approval-watch menstempel pengingat biasa juga | 1 uji |
| MT3 | invoice pelanggan lewat → `deadline.due` | 2 uji |
| MT4 | `system()` membuang kunci template | 7 uji |
| MQ1 | jendela melintasi tengah malam: akhir "hari ini" di sisi malam | 2 uji |
| MQ2 | kotak keluar membuang penundaan | 2 uji |
| MQ3 | job mengabaikan jam tenang | 1 uji |
| MQ4 | gerbang mengabaikan "dimatikan pengguna" | 3 uji |
| MQ5 | akhir jendela inklusif | 1 uji |
| MW1 | HMAC atas JSON yang di-encode ulang, bukan badan mentah | 1 uji |
| MW2 | webhook menerima tanpa tanda tangan bila secret kosong | 1 uji |
| MW4 | penyaring berhenti menyamarkan rahasia | 1 uji |
| MW5 | kanal & gerbang mengirim tanpa memeriksa konfigurasi | 2 uji |
| MW7 | stempel opt-in ditulis ulang setiap simpan | 1 uji |
| MW8 | normalisasi menerima digit telanjang | 1 uji |
| MW9 | konversi zona webhook dihapus | 1 uji |
| MW3′ | (setelah dipaku) saringan kanal webhook dihapus | 1 uji |
| MW6′ | (setelah dipaku) penjaga 429/5xx dihapus | 1 uji |

### LOLOS HIJAU — dan apa yang dilakukan (2)

| # | Mutasi | Mengapa lolos | Paku (`1760245`) |
|---|---|---|---|
| MW3 | `where(channel = whatsapp)` dihapus dari pencarian wamid | umpan baris e-mail ber-wamid sama dibuat SESUDAH baris sasaran — `first()` memilih yang benar lewat urutan id | umpan dibuat lebih dulu (id lebih kecil) |
| MW6 | penjaga "429/5xx = sementara" dihapus | kasus 429 membawa kode 130429 yang memang tidak ada di daftar permanen — penjaga tidak pernah dibutuhkan ujinya | kasus 500 dengan badan berkode permanen (100): harus tetap diulang |

Keduanya berbentuk sama dengan pelajaran F-6: asersi yang kebetulan benar karena fixture-nya
tersusun ramah. Keduanya merah sekarang.

---

## 5. Permukaan — setiap aturan baru, diperiksa satu per satu

| Aturan | Service | Job | Kanal | Controller/Request | Resource | SPA | Webhook | Log |
|---|---|---|---|---|---|---|---|---|
| `sent` menuntut pengenal | — | ✅ `handle` | ✅ `MailChannel` (Message-ID), `WhatsAppChannel` (wamid) | — | — | lencana `Terkirim` hanya dari `status` | tidak membuat `sent` | — |
| mailer log = `skipped` | ✅ `outbox` | ✅ re-check | ✅ `MailChannel` sebelum `Mail::` | ✅ `retry` 422 · `PasswordHelp` | `error` | Profil + kolom alasan | — | — |
| kanal dimatikan pengguna | ✅ | ✅ | — | ✅ 422 | — | ✅ toggle + lencana | — | — |
| jam tenang menunda | ✅ `outbox` delay | ✅ `release()` | — | ✅ `retry` delay + kalimat | `next_attempt_at` | ✅ kolom Berikutnya + alert Profil | — | — |
| WhatsApp prasyarat | ✅ | ✅ | ✅ sebelum `Http::` | ✅ 422 per sebab | — | ✅ lencana + kesiapan terukur | — | — |
| rahasia tidak bocor | — | `Str::limit` | ✅ scrubber | jawaban API tanpa nilai rahasia (dipaku) | — | — | ✅ scrubber | pesan pengecualian sudah disaring sebelum dilempar |
| webhook | — | — | — | ✅ HMAC badan mentah, `hash_equals`, 403 tanpa sentuh | — | kolom Status penyedia | ✅ | — |
| E.164 + opt-in bertanggal | `WhatsAppConsent` | — | `PhoneNumber::normalize` ulang | ✅ `UpdatePhoneRequest`, `Store/UpdateUserRequest` | ✅ tiga field + turunan | ✅ kartu Profil, form Pengguna | — | — |

Satu permukaan yang **sengaja tidak** menegakkan pilihan pengguna: `GET core/notifications`
(kotak masuk) — kanal dalam aplikasi tidak bisa dimatikan, dan layar Profil mengatakannya.

---

## 6. Perangkap I — bukti tidak ada yang keluar

- `WhatsAppChannelTest::setUp` dan `DeliveryRetryScheduleTest::setUp`:
  `Http::preventStrayRequests()`; uji "tanpa konfigurasi" ditutup `Http::assertNothingSent()`.
- Tidak ada `Mail::fake()` yang bisa menghasilkan `sent`; transport tangkap adalah kelas uji di
  `tests/Support`, bukan mailer produksi.
- `git diff main...HEAD -- composer.json composer.lock package.json` = **0 baris** (klien HTTP
  Laravel, tanpa SDK).
- `git grep -n "WHATSAPP_" -- .env.example config/` = **25 baris**, semuanya NAMA variabel;
  `.env` tidak disentuh (`git status` bersih terhadap berkas yang di-ignore, dan `.env` tidak
  pernah masuk `git add` bernama).
- `git grep -nE "EAAB[A-Za-z0-9]{10,}" -- . ':!vendor'` = **1 baris**: token PALSU
  `uji-token-RAHASIA-EAABsbCS1iHgBO9x` di `WhatsAppChannelTest` — sengaja berbentuk seperti token
  Meta supaya penyaringnya diuji terhadap bentuk yang sebenarnya.

---

## 7. Bukti peramban (pelajaran P1-I: rilis SPA belum terverifikasi sampai DIMUAT)

Chromium sungguhan (Playwright), server `php -S` dengan **router Laravel `server.php`** atas
**salinan** basis data demo di scratchpad (`database/database.sqlite` tidak disentuh; ketiga
migrasi dijalankan pada salinan itu dengan `DB_DATABASE=<salinan> php artisan migrate --force`).

```
admin@ × 2 viewport (1440×900, 390×844) × 5 rute
  profil · r/core/notification-deliveries · r/iam/users · settings · dashboard
console_errors : []
http_errors    : []      (setiap permintaan /api/ berstatus < 400)
scrolls_sideways : false pada kesepuluh pemuatan
```

Satu jebakan yang ditemukan di sini: `php -S -t public` (tanpa router) menjawab **405** untuk
`PUT core/me/preferences/notify.channels` — segmen terakhir bertitik dianggap berkas statis oleh
server bawaan PHP. Bukan cacat aplikasi (nginx `try_files` → `index.php`, dan uji PHP hijau);
server harness memakai `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`
seperti `tests/harness/serve-mysql.sh`.

Harness: `[S37_kanal_notifikasi_profil] ok 7710ms clicks=6`,
`[S37_kanal_notifikasi_profil_ponsel] ok 5339ms clicks=2` — 16 syarat hijau; PNG
`s37-pengiriman-dilewati.png` (dua baris Dilewati dengan kalimatnya, tidak ada Terkirim),
`s37-profil-sesudah.png`, `s37m-profil.png`.

---

## 8. Gerbang

- **Per-direktori saat kerja:** `tests/Feature/Core` **1.042 uji** dijalankan setelah langkah 1 —
  **2 merah** (`QueueFailedJobsTest`, stub penolaknya tidak pernah dipanggil karena `array` kini
  jujur `skipped`), diperbaiki `626e159`; `tests/Feature/Iam` **62 uji** hijau setelah langkah 1;
  **243 uji** (14 berkas notifikasi/SPA-pin/setting + seluruh Iam) hijau setelah T3a.3;
  **91 uji** SPA-pin (NAV/PWA/launcher/sidebar/vendor/uploadqueue/ModuleCounts) hijau setelah T3a.2.
- **Suite penuh SQLite di `c756d48`:** **4.758 uji / 32.387 asersi, 11 dilewati, hijau** (14 mnt 06 dtk; `main` 649601d membawa 4.687 — +71 uji paket ini).
- **Suite penuh MySQL 8.0.46 (`erp_dryrun`, worktree terpisah, vendor DISALIN) di `c756d48`:**
  **4.758 uji / 32.393 asersi, 9 dilewati, hijau** (34 mnt 20 dtk) — termasuk ketiga migrasi baru
  di atas skema MySQL nyata (indeks `provider_id` varchar(190) diterima).
- `vendor/bin/pint --test` bersih pada setiap berkas yang disentuh (dua kegagalan pint lama tidak
  disentuh).

---

## 9. Keputusan pemilik

| # | Keputusan | Rekomendasi / yang dipakai kode sampai dijawab |
|---|---|---|
| A | **Tempat penyimpanan preferensi kanal + jam tenang**: `UserPreferences` (P1-C) vs tabel baru | **`UserPreferences`** — dipakai. Alasan di §2(C) dan CONVENTIONS §38. Bila suatu hari perlu jejak audit atas perubahan jam tenang, saat itulah tabel |
| B | **Jam tenang melintasi tengah malam**: awal inklusif, akhir eksklusif, sisi malam → besok pagi, sisi pagi → hari ini; zona Asia/Jakarta tetap tanpa kolom zona per pengguna | dipakai; pengguna di luar WIB adalah pemicu kolom zona |
| C | **Penyedia WhatsApp** (ledger #7): Meta Cloud API langsung vs Qontak | kode memakai `meta`; `qontak` dikenali tetapi pengirimnya belum ditulis — bentuk API tidak dikarang; Fonnte/Wablas tidak punya mode |
| D | **Bentuk konfigurasi template**: nama template per peristiwa di `.env` (`WHATSAPP_TEMPLATE_<PERISTIWA>`), tiga placeholder tetap, bahasa satu untuk semua | dipakai; alternatif (tabel `core_settings` yang bisa disunting dari layar) ditolak karena nama template bukan rahasia tetapi status persetujuannya milik Meta, dan `.env` sudah tempat kredensialnya |
| E | **Pertumbuhan tabel kotak keluar**: dengan kedua sakelar mati (bawaan), setiap notifikasi menulis DUA baris `skipped` (sebelumnya satu) | tidak dipangkas di paket ini; kandidat: `erp:outbox-prune` untuk baris `skipped` > 90 hari, atau tidak menulis baris kanal yang sakelar globalnya mati (asimetris dengan e-mail P-0b) |
| F | **Peristiwa yang mendapat WhatsApp**: hanya lima operasional; pengajuan/persetujuan dokumen dan pengingat persetujuan biasa TIDAK (template umum, `skipped` di WhatsApp) | dipakai; menambah peristiwa = satu template Meta baru + satu entri registri |
| G | **Kirim ulang selama jam tenang** ditunda ke akhir jendela (bukan langsung); operator diberi tahu lewat kolom alasan | dipakai |
| H | **Webhook `failed` dari Meta** menandai `status` baris `failed` (bisa Kirim ulang), `sent_at` dipertahankan | dipakai |
| I | Ledger #8 SMTP: kotak surat domain perusahaan, 587 STARTTLS | runbook ditulis; nilai milik pemilik |

## 10. Prasyarat pemilik — tidak satu pun ada di repo

1. **Akun WABA terverifikasi Meta** → `WHATSAPP_TOKEN` (System User token permanen),
   `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN` di `.env` erp1.
2. **Lima template disetujui Meta** (kategori utility, bahasa `id`, tiga placeholder) →
   `WHATSAPP_TEMPLATE_DEADLINE_DUE`, `…_APPROVAL_ESCALATED`, `…_AR_DUNNING`, `…_BACKUP_STALE`,
   `…_SCHEDULER_DOWN`. 1–7 hari, bisa ditolak.
3. **Anggaran per percakapan** (USD, kartu bisnis Meta) — plafon bulanan pemilik; cocokkan dengan
   hitungan baris Terkirim kanal `whatsapp`.
4. **Kotak surat SMTP** domain perusahaan → `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587`,
   `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_*`.
5. Sesudah `.env`: `config:clear`, `systemctl restart erp1-queue`, lalu sakelar
   **Pengaturan › Notifikasi** (e-mail, WhatsApp) — dan setiap penerima mengisi nomor + opt-in di
   **Profil › Notifikasi** (atau administrator di Sistem › Pengguna).
6. Bukti penerimaan Fase 3 (ROADMAP §4): satu e-mail dan satu WhatsApp nyata dengan `provider_id`
   terlihat di layar — milik pemilik, sesudah 1–5.

## 11. Yang TIDAK dikerjakan

- **Pengirim Qontak** — dikenali di konfigurasi, `skipped` yang mengatakannya. Bentuk API-nya
  (id template, id integrasi kanal, parameter) ditulis setelah pemilik memilih jalur ini.
- **Web push** (P-3e) — `DeliveryChannels` tetap melempar "belum tersedia (Fase 3, P-3e)".
- **Webhook pesan MASUK** — hanya status; tidak ada "balas untuk menyetujui".
- **Zona waktu per pengguna** — Asia/Jakarta tetap.
- **Pemangkasan baris `skipped`** — keputusan pemilik E.
- **Pengiriman sungguhan** — tidak satu pun surel/pesan keluar dari mesin ini (perangkap I).
- **Deploy dan merge** — dilarang untuk agen di alur kerja ini.

## 12. Deviasi baru yang ditemukan

- `MAIL_MAILER=log` menghasilkan baris `sent` ber-Message-ID lokal sejak P-0b (5 Sep 2026) —
  ditutup di langkah 1. Setiap baris `sent` e-mail di produksi sebelum P-3a adalah klaim tanpa
  server (tidak ada, karena `notifications.email_enabled` mati di erp1 — tetapi jalurnya ada).
- `php -S -t public` tanpa router menolak segmen bertitik (405) — harness dan pemeriksaan
  peramban harus memakai `server.php`.
- `createFromTimestamp()` + kolom `datetime` bergeser tujuh jam — kelas cacat F-4 muncul lagi;
  pola aman: `->setTimezone(config('app.timezone'))` sebelum disimpan.
- Dengan kedua sakelar mati, kotak keluar tumbuh dua baris per notifikasi (keputusan E).
- Job yang dilepas `release()` oleh jam tenang menaikkan hitungan percobaan PEKERJA (bukan
  `attempts` baris): pesan yang lima malam berturut-turut jatuh di dalam jendela akan `failed`
  "Percobaan habis" — teoretis (backoff terpanjang 1 jam), dicatat.

## 13. Sapuan dokumentasi (CONVENTIONS §35)

`docs/KEPUTUSAN-INTEGRASI.md` (baru), `docs/ROADMAP-DEVIASI.md` baris 18 (catatan kaki),
`docs/DEPLOYMENT.md` §11 (baru), `docs/CONVENTIONS.md` §2 (dua migrasi Core) + §38 (baru),
`docs/PANDUAN-ADMINISTRATOR.md` (4 kalimat "WhatsApp tidak ada"), `docs/PANDUAN-PENGGUNA.md`
(§1.6 + tabel "siapa yang bisa"), `docs/ROADMAP-HASHMICRO.md` §5 (catatan baris 7/8),
`.env.example` (nama variabel), help kedua sakelar di Pengaturan.

## 14. Commit (urut lama → baru)

```
34370ed  T3a.0  docs/KEPUTUSAN-INTEGRASI.md + catatan kaki ROADMAP-DEVIASI
0c71b28  kejujuran status: MailTransport, DeliveryGate, dua hasil job, sent menuntut pengenal
626e159  susulan: QueueFailedJobsTest memakai transport tangkap
f0fcbcb  T3a.1  NotificationTemplates, migrasi 001801, EventNotificationMail, 4 pengawas
a662467  T3a.2  UserPreferences (2 kunci), QuietHours, me/notification-channels, #/profil, sw.js 9
6a019de  T3a.3  WhatsApp: migrasi 000252 + 001802, PhoneNumber, WhatsAppConsent, kanal, webhook, scrubber
1760245  T3a.3 paku: dua mutasi lolos hijau
6830a46  T3a.4  DeliveryRetryScheduleTest
ae6d7ef  dokumen: DEPLOYMENT §11, CONVENTIONS §38, PANDUAN, ledger
c756d48  bukti: harness S37/S37m, results-phase-3.json, 3 PNG
```

Skema yang berubah, dan apakah migrasinya aman di MySQL dengan data lama: tiga migrasi, semuanya
**aditif dan nullable, tanpa backfill** (`core_notifications.template`,
`core_notification_deliveries.provider_status/_at` + indeks `provider_id` varchar(190) = 760 B
< batas InnoDB, `users.phone_e164/whatsapp_opt_in_at/_via`). Tidak ada `->change()`, tidak ada
enum kolom, tidak ada FK lintas modul.
