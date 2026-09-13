# Laporan Paket HM P-3e — PWA push (Web Push standar, satu baris per perangkat, pengenal yang tidak dikarang)

**Cabang:** `feat/phase3-p3e` (dari `main` 2bd6066 = merge P-3d) · **Tanggal:** 13 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 3 / P-3e (5 hari-orang), tugas T3e.1–T3e.7 —
**paket terakhir Fase 3**
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik;
deploy dilarang untuk agen di alur kerja ini).

---

## 0. Satu kalimat

Paket ini menambahkan **kanal ketiga** ke mesin kotak keluar yang sudah ada, dan seluruh
kesulitannya ada pada satu hal yang tidak dimiliki dua kanal sebelumnya: **seseorang punya
beberapa perangkat, dan perangkat-perangkat itu menjawab berbeda**. Karena itu keputusan
terbesarnya adalah **satu baris pengiriman per LANGGANAN, bukan per orang** (§2) — dengan harganya
dikatakan apa adanya — dan keputusan kedua adalah **tidak mengarang pengenal penyedia**: Web Push
tidak punya message id dalam standarnya, jadi aturan rumah "tidak ada `sent` tanpa pengenal"
dilonggarkan lewat antarmuka penanda yang **dipaku uji tidak boleh merembes** ke e-mail dan
WhatsApp (§3).

Tiga hal lain ditolak dengan sengaja: **tidak ada aplikasi native**, **tidak ada SDK Firebase**
(endpoint `fcm.googleapis.com` yang muncul di tabel adalah **pilihan Chrome**, bukan integrasi
kita), dan **tidak ada klaim privasi yang lebih besar daripada yang dipegang kode** — isi
pemberitahuan memang tidak bisa dibaca layanan push, tetapi bahwa ada pesan, kapan, dan untuk
endpoint mana **tetap terlihat**, dan itu ditulis di layar, di panduan, dan di
`KEPUTUSAN-INTEGRASI.md` §12.

Bukti peramban menemukan satu cacat yang **tidak dilihat satu pun uji PHP**: label kanal di
`GET core/me/notification-channels` adalah sebuah ternary `email ? 'E-mail' : 'WhatsApp'` — benar
selama kanalnya dua, dan pada kanal ketiga baris **web push tampil berlabel "WhatsApp"** dengan
sebab Dilewati milik web push di bawahnya (§3.6).

Tidak ada sentuhan pada `bootstrap/*`, `routes/*` akar, atau `DatabaseSeeder`
(`git diff --stat main..HEAD -- bootstrap/ routes/ database/seeders/DatabaseSeeder.php` = **0
baris**). Satu dependensi baru, dan itu memang isi barisnya di roadmap: `minishlink/web-push`
^10.0 (kripto aes128gcm/VAPID tidak ditulis sendiri).

---

## 1. Tugas → status → bukti

> Setiap angka di laporan ini keluar dari perintah yang dijalankan. Angka gerbang ada di §8.

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T3e.1 | Konfigurasi VAPID tanpa satu kunci pun di repo: `config('erp.push')`, `WebPushSetup`, `core:vapid-keys`, sakelar Pengaturan | ✅ | `80bbcc3` + `6e98f8c` — `WebPushSetupTest` **8 uji / 26 asersi**; 2 mutasi merah (§4) |
| T3e.2 | `core_push_subscriptions` (migrasi Core **001804**) + kolom penghubung (**001805**) + `PushDeviceLabel` | ✅ | `b1b1f26` — `PushSubscriptionSchemaTest` **8 / 47**; 3 mutasi merah, satu di antaranya **hanya merah di MySQL** (§4) |
| T3e.3 | Kanal `webpush` di mesin kotak keluar: `WebPushChannel`, `WebPushSender`, fan-out, 404/410, gerbang | ✅ | `8dc6d07` — `WebPushChannelTest` **14 / 54**, `WebPushOutboxTest` **10 / 37**; 9 mutasi merah, **2 di antaranya LOLOS HIJAU lebih dulu** (§4) |
| T3e.4 | Endpoint perangkat milik sendiri + layar Profil dengan **empat** jalan buntu berkalimat sendiri | ✅ | `02ef731` — `PushSubscriptionEndpointTest` **9 / 33**, `WebPushSpaWiringTest` **9 / 46**; 4 mutasi merah (§4) |
| T3e.5 | `sw.js`: `push`, `notificationclick`, `pushsubscriptionchange`; `SHELL_VERSION` 12 → 13; rute rotasi | ✅ | `02ef731` — `PwaServiceWorkerTest` **15 / 210** (pendengar 4 → 7 + tiga pin baru), `PushRotationTest` **6 / 16** (termasuk sensus rute tulis publik di luar `api/`, §3.7); 5 mutasi merah (§4) |
| T3e.6 | Sistem › Pengiriman Notifikasi + `enums.js`; penyaring per kanal; Kirim ulang menolak perangkat yang hilang | ✅ | `09be261` — di dalam `WebPushSpaWiringTest`; 3 mutasi merah (§4) |
| T3e.7 | Dokumen (CONVENTIONS §42, ADMINISTRATOR §5.15, DEPLOYMENT §11.3, KEPUTUSAN-INTEGRASI §12, PANDUAN-PENGGUNA, ROADMAP) + harness S41/S41m | ✅ | commit dokumen ini; **12 syarat** desktop + **5 syarat** ponsel, `console_errors: []` keduanya; kunci `results-phase-3.json` 16 → **18** |
| 8 | Gerbang per-direktori + pint | ✅ | §8 |
| 9 | Laporan ini | ✅ | berkas ini |

**Uji baru paket ini: 7 berkas, 64 kasus** — `WebPushSetupTest` 8, `PushSubscriptionSchemaTest` 8,
`WebPushChannelTest` 14, `WebPushOutboxTest` 10, `PushSubscriptionEndpointTest` 9, `PushRotationTest`
6, `WebPushSpaWiringTest` 9. `PwaServiceWorkerTest` bertambah 3 kasus (12 → 15). Kesembilan berkas
yang disentuh dijalankan bersama: **80 uji / 469 asersi** hijau.

---

## 2. KEPUTUSAN BENTUK BARIS — satu baris per LANGGANAN (jalan a)

Ini bagian yang diminta berdiri sendiri, karena ia keputusan yang tidak bisa dibatalkan tanpa
migrasi kedua.

### 2.1 Masalahnya

Kotak keluar menulis **satu baris `core_notification_deliveries` per kanal per penerima** sejak
P-0b, dan `DeliverNotification` menolak menandai `sent` tanpa pengenal penyedia yang tidak kosong.
Web push tidak muat dalam bentuk itu: **satu orang punya nol atau lebih perangkat**, dan
perangkat-perangkat itu **menjawab berbeda dalam satu pengiriman yang sama** — 201 di ponsel, 410
di laptop yang peramban-nya baru dipasang ulang, timeout di tablet yang sedang di luar jangkauan.

### 2.2 Jalan (b) — satu baris per orang — dan kenapa ditolak

Bentuk tabel tidak berubah, tetapi tiga pertanyaan tidak punya jawaban yang jujur:

1. **Apa `provider_id`-nya ketika tiga perangkat menjawab berbeda?** Satu kolom harus memilih satu
   jawaban, dan setiap pilihan menyembunyikan dua lainnya.
2. **Apa statusnya ketika 1 dari 3 gagal?** `sent` menyembunyikan kegagalan; `failed` menyembunyikan
   keberhasilan. Kolom "Galat / alasan" akan memuat sebab satu perangkat sambil menyiratkan
   ketiganya.
3. **Apa yang terjadi pada Kirim ulang sesudah 1 dari 3 berhasil?** Ia mengirim ULANG ke perangkat
   yang **sudah menerima**. Itu bukan ketidaknyamanan kecil: alarm operasional yang muncul dua kali
   di layar orang yang sudah membacanya adalah cara tercepat membuat orang mematikan kanalnya.

Perintah paket ini menawarkan jalan keluar untuk (3) — "duplikat ditiadakan dengan tag notifikasi
yang menimpa, dipakukan uji". Tag itu **memang dipasang** (`tag` = id notifikasi, §3.4) dan ia
menyelesaikan tampilan di baki pemberitahuan. Yang **tidak** diselesaikannya: baris pengiriman yang
tetap berbohong tentang perangkat mana yang gagal, dan pengiriman ulang yang tetap membakar kuota
push dan tetap membangunkan perangkat yang sudah menerima. Menimpa notifikasi lama adalah menutupi
akibatnya, bukan menghilangkan sebabnya.

### 2.3 Yang dipilih: jalan (a), dan harganya

**Satu baris per LANGGANAN**, dengan kolom penghubung `push_subscription_id` (migrasi 001805).
Yang dibeli:

- **Retry per perangkat tepat.** Kirim ulang pada baris yang gagal menyentuh **hanya** perangkat
  itu; baris perangkat lain tidak berubah (dipaku
  `WebPushOutboxTest::test_retry_of_one_device_leaves_the_other_rows_untouched`).
- **`provider_id` jelas per perangkat** — dan kosong secara jujur bila layanan push tidak memberi
  `Location` (§3.2).
- **Kolom "Penerima / perangkat" menyebut perangkatnya** ("Chrome di Android"), bukan endpoint 188
  karakter yang tidak memberi tahu siapa pun apa pun.
- **404/410 punya sasaran yang tepat**: langganan yang mati dihapus, dan baris yang mencatat
  kejadian itu tetap menunjuk perangkat yang mana.

Harganya, dikatakan apa adanya: **barisnya berlipat sebanyak perangkat**. Satu notifikasi kepada
seseorang dengan tiga perangkat menghasilkan 1 baris e-mail + 1 baris WhatsApp + **3** baris web
push. Dengan sakelar mati (bawaan) dan tanpa perangkat, tetap **satu** baris `skipped` per
notifikasi — kanal yang tidak meninggalkan baris apa pun adalah kanal yang hilang dari layar.
Pertumbuhan tabel adalah keputusan pemilik yang sudah tercatat di LAPORAN P-3a; paket ini
menambahinya satu faktor yang harus disebut: **per perangkat, bukan per orang**.

### 2.4 Kolomnya TANPA foreign key, dengan sengaja

Baris pengiriman adalah **riwayat** dan harus hidup lebih lama daripada perangkatnya — 404/410
menghapus langganannya, dan baris yang mencatat kejadian itu justru yang harus tetap terbaca.
`nullOnDelete` akan mengosongkan penunjuknya ("perangkat yang mana?" tidak terjawab);
`cascadeOnDelete` akan menghapus buktinya. Jadi id-nya dibiarkan menggantung dan kodenya membacanya
sebagai "perangkat sudah tidak terdaftar" — kalimat yang sama yang menolak Kirim ulang dengan 422.

---

## 3. Keputusan lain yang membuat paket ini

### 3.1 Kunci privat VAPID tidak punya getter

`WebPushSetup` punya `publicKey()`, `subject()`, `configured()`, `skipReason()` — dan **tidak punya
`privateKey()` publik**. Kunci privat keluar lewat tepat dua pintu: `auth()` (ke pustaka penanda
tangan) dan `secrets()` (ke `ProviderErrorScrubber`). Yang dipaku bukan kalimat melainkan **bentuk
kelasnya**: uji memanggil **setiap** metode publik tanpa argumen dan menolak bila ada yang
memulangkan kunci privat selain kedua itu. Sebuah `publicKey()` yang salah ketik menjadi
`vapid_private_key` memerahkannya tanpa ada yang perlu menebak namanya lebih dulu (mutasi M1).

### 3.2 Pengenal yang tidak dikarang — dan pelonggaran yang tidak merembes

RFC 8030 §5 menjadikan header `Location` **opsional**. Dua pilihan, satu jujur:

- mengarang uuid supaya kolom "pengenal penyedia" terisi — yaitu persis kebohongan yang aturan itu
  dibuat untuk mencegahnya (P-3a: `MAIL_MAILER=log` menghasilkan `sent` ber-Message-ID lokal);
- mengatakan bahwa untuk kanal ini **bukti penerimaan adalah 201 dari layanan push**, dan
  membiarkan `provider_id` kosong bila `Location` tidak ada.

Dipilih yang kedua, dan bentuk tertulisnya adalah antarmuka penanda
`Core\Contracts\ChannelWithoutMessageId`. `DeliverNotification` melonggarkan syaratnya **hanya**
untuk kanal yang mengimplementasikannya. `MailChannel` dan `WhatsAppChannel` **tidak**, dan itu
dipaku `WebPushChannelTest::test_the_loosening_is_marked_on_the_channel_and_does_not_reach_email_or_whatsapp`
(mutasi M7: membuat `MailChannel` menandai dirinya → merah). Yang dilonggarkan hanya "kosong belum
tentu gagal"; **"gagal berarti gagal" tidak dilonggarkan sedikit pun** — kanal tetap wajib melempar.

### 3.3 404/410 dicatat di tempat yang bertahan sesudah barisnya hilang

Langganan yang dijawab 404/410 **dihapus**, dan kejadiannya ditulis ke **`core_audit_log`** dengan
label perangkatnya. Menuliskannya "di baris langganan" tidak berarti apa-apa: baris itulah yang
dihapus. Log audit adalah append-only dan tidak punya jalur hapus di aplikasi ini. **Aplikasi ini
belum punya layar Log Audit** — tidak ada entri navigasi dan tidak ada rute SPA (PANDUAN-ADMINISTRATOR
§3.10 mengatakannya apa adanya); yang membaca barisnya adalah
`GET api/core/audit-log?auditable_type=PushSubscription`, digerbangi `core.view`. Baris kotak keluar yang memicunya `failed` seketika dengan kalimat yang
menyebut perangkat itu — dua catatan, dua umur, satu kejadian.

### 3.4 Muatan dipotong 2.820 byte, bukan 4.078

Batas keras pustaka adalah 4.078 byte. Yang dipakai adalah **2.820** — panjang padding otomatisnya —
karena di bawah angka itu **setiap badan permintaan keluar dengan panjang yang sama persis** (diukur
**2.922 byte**, apa pun isinya). Panjang badan adalah **satu-satunya** hal tentang isi pesan yang
bisa dibaca layanan push; membiarkannya berubah membocorkan panjang pemberitahuan seseorang tanpa
satu byte pun isi. `tag` = id notifikasi, jadi pemberitahuan yang sama yang sampai dua kali menimpa
alih-alih menumpuk.

### 3.5 Rute rotasi yang publik, dan batas yang tersisa

`POST push/rotate` (Routes/web.php, bersama halaman persetujuan eksternal dan webhook WhatsApp)
tidak meminta sesi. Bukan kelalaian, dan bukan kenyamanan: service worker **tidak bisa membaca token
sesi** (ia di `localStorage`, yang tidak punya API di service worker), dan `pushsubscriptionchange`
menyala **ketika tidak ada satu tab pun terbuka** — itu seluruh gunanya. Pilihan ketiga, "titipkan
ke halaman", karena itu bukan jawaban.

Kapabilitasnya adalah **endpoint lama**, yang dalam standar Web Push sendiri sudah menjadi
kapabilitas. **Lima batas** (tiga sejak T3e.5, dua sejak putaran verifikasi): rute ini **tidak pernah
MEMBUAT** baris, **asal endpoint baru harus sama** dengan yang lama, **tidak pernah menyentuh baris
milik akun lain** (A-4 — tanpa ini, satu POST tanpa sesi menghapus langganan korban; diukur),
**bukan alamat internal** (A-1/B-2, penjaga yang sama dengan pendaftaran), dan lajunya dibatasi
(`throttle:30,1`).

**Batas yang TERSISA** (juga di KEPUTUSAN-INTEGRASI §12.4): seseorang yang berhasil membaca endpoint
milik orang lain dapat memindahkan langganan itu ke perangkatnya sendiri **di dalam akun pemiliknya,
di dalam layanan push yang sama** — pemiliknya berhenti menerima pemberitahuan di perangkat itu.
Endpoint bisa dibaca dari basis data atau dari peramban orang itu; **sumber KETIGA yang paragraf ini
dulu tidak sebut — kolom "Galat / alasan" di Sistem › Pengiriman Notifikasi, yang dibaca setiap
pemegang `core.update` dan ikut setiap cadangan — ditutup di putaran verifikasi (A-6/B-4): endpoint
kini disamarkan sebelum masuk kolom itu.** Siapa pun yang bisa melakukan salah satu dari dua yang
tersisa sudah memegang lebih banyak
daripada itu; ini dicatat bukan karena bisa ditutup dengan satu pemeriksaan lagi, melainkan supaya
tidak ditemukan sebagai kejutan.

### 3.6 Cacat yang hanya terlihat di peramban: kanal ketiga berlabel "WhatsApp"

`GET core/me/notification-channels` menulis label kanalnya sebagai ternary:

```php
'label' => $channel === NotificationDelivery::CHANNEL_EMAIL ? 'E-mail' : 'WhatsApp',
```

Benar selama kanalnya persis dua. Pada kanal ketiga, baris **web push tampil berlabel "WhatsApp"** —
dua baris "WhatsApp" berturut-turut, yang kedua dengan sebab Dilewati milik web push di bawahnya
("Penerima belum mendaftarkan satu perangkat pun…"). Seorang pemakai yang membacanya akan mencari
setelan WhatsApp untuk masalah web push.

**Tidak satu pun uji PHP yang ada melihatnya**, karena semuanya memeriksa `channel` dan `reason` dan
tidak pernah `label`. Yang menemukannya adalah tangkapan layar S41m. Diganti dengan peta satu baris
per kanal yang **gagal ke nama kanalnya sendiri**, bukan ke kanal lain, dan dipaku
`WebPushOutboxTest::test_every_channel_carries_its_own_name_on_the_screen` (mutasi M25).

### 3.7 Dua sensus yang harus ikut bertambah — dan satu yang belum pernah dihitung

`UngatedApiRouteCensusTest` (P-3d) memaku **daftar literal rute TULIS tanpa gerbang izin di bawah
`api/`**. Paket ini menambah dua (`POST api/core/me/push-subscriptions`,
`DELETE api/core/me/push-subscriptions/{id}`), dan gerbang **memerah** sampai keduanya dituliskan
beserta golongannya (self-service). Itu paku yang bekerja persis seperti yang dijanjikan P-3d.

Yang **tidak** dilihat sensus itu: rute tulis publik **di luar** `api/` — dan `POST push/rotate`
justru salah satunya. Jadi paket ini menambahkan sensus keduanya
(`PushRotationTest::test_the_public_write_routes_outside_the_api_are_the_five_that_were_counted`),
dan menghitungnya menemukan **lima, bukan tiga**: dua di antaranya tidak pernah disebut dokumen mana
pun.

| Rute | Kapabilitasnya | Asal |
|---|---|---|
| `POST penilaian/{token}` | token sekali-pakai di URL, `throttle:10,1` | CSAT (F-9) — **belum pernah tercatat di sensus mana pun** |
| `POST persetujuan/{token}` | token 20–64 karakter di URL, `throttle:10,1` | keputusan MK/Owner (P0-F) |
| `POST push/rotate` | **endpoint lama**, dan rutenya tidak pernah MEMBUAT baris | P-3e (§3.5) |
| `POST whatsapp/webhook` | HMAC-SHA256 atas badan **mentah**; tanpa App Secret semua 403 | P-3a |
| `PUT storage/{path}` | **tanda tangan relatif** — `Illuminate\Filesystem\ReceiveFile` abort tanpa `?upload=1` bertanda tangan sah | rute `storage.local.upload` milik **kerangka kerja sendiri**, terdaftar karena `filesystems.disks.local.serve = true` — **belum pernah tercatat di sensus mana pun** |

Kelimanya diperiksa satu per satu sebelum dituliskan; tidak ada yang dibiarkan dalam daftar tanpa
kalimat yang menyebut apa kapabilitasnya. Itu gunanya **menghitung** alih-alih mengingat.

---

## 4. Mutasi — 25 dijalankan, **23 merah, 2 LOLOS HIJAU → syarat diperbaiki lalu merah**

| # | Mutasi | Akibatnya | Hasil |
|---|---|---|---|
| M1 | `WebPushSetup::publicKey()` memulangkan `vapid_private_key` | kunci privat dikirim ke setiap peramban | **MERAH** (3 gagal; daftar pembawa bertambah `publicKey`) |
| M2 | Kalimat "MENGGANTI KUNCI VAPID MEMBATALKAN SELURUH LANGGANAN" dihapus dari perintah | pemilik mengganti kunci tanpa tahu biayanya | **MERAH** |
| M3 | `endpoint` menjadi `varchar(190)` | endpoint 188+ karakter terpotong/ditolak | **MERAH DI MYSQL** (`1406 Data too long`) — hijau di SQLite, yang tidak menegakkan panjang; karena itu mutasi ini dijalankan di `erp_dryrun` |
| M4 | indeks ditambahkan pada `endpoint` | bom waktu 760 byte batas InnoDB | **MERAH** |
| M5 | `endpoint_hash` kehilangan `unique` | satu peramban → banyak baris → pemberitahuan berganda | **MERAH** |
| M6 | Pelonggaran dihapus (pengenal selalu wajib) | setiap pengiriman web push yang BERHASIL dicatat gagal | **MERAH** |
| M7 | `MailChannel` ikut menandai dirinya `ChannelWithoutMessageId` | `sent` palsu `MAIL_MAILER=log` kembali | **MERAH** (pin bentuk kelas) |
| M8 | 410 tidak menghapus langganannya | endpoint mati dikirimi selamanya | **MERAH** |
| M9 | Penghapusan tidak dicatat di log audit | perangkat hilang tanpa jejak yang bertahan | **MERAH** |
| M10 | Web push tidak ber-fan-out (satu baris per orang) | jalan (b) yang ditolak §2 | **MERAH** |
| M11 | Kirim ulang tidak memeriksa perangkatnya | baris tanpa sasaran diantrekan untuk gagal lagi | **MERAH** |
| M12 | Urutan gerbang dibalik (perangkat sebelum sakelar) | "daftarkan perangkat" disuruhkan pada pemasangan yang kanalnya belum ada | **MERAH** |
| M13 | Muatan tidak dipotong | panjang badan permintaan membocorkan panjang isi | **LOLOS HIJAU** → uji memakai teks ASCII yang tidak pernah menyentuh plafon; diganti muatan multibyte (600 aksara × 4 byte) → **MERAH** (2.967 > 2.820) |
| M14 | Kanal tidak memeriksa konfigurasinya sendiri | kanal percaya pemanggilnya sudah memeriksa | **LOLOS HIJAU** → gerbang job menahannya lebih dulu; ditambah uji yang memanggil kanal LANGSUNG → **MERAH** |
| M15 | `destroy()` tanpa lingkup pemilik | seseorang mencabut perangkat orang lain | **MERAH** |
| M16 | `index()` memuat perangkat semua orang | endpoint (kapabilitas) orang lain bocor | **MERAH** |
| M17 | `push` diam saat muatan rusak | peramban mencabut langganan (kontrak userVisibleOnly) | **MERAH** (uji PHP **dan** harness S41) |
| M18 | Pendengar `push` menyimpan muatannya ke cache | judul/isi pemberitahuan di cache bersama | **MERAH** (2 pin) |
| M19 | Rotasi MEMBUAT baris untuk endpoint lama yang tak dikenal | siapa pun mendaftarkan langganan tanpa kredensial | **MERAH** |
| M20 | Rotasi tanpa pemeriksaan asal | langganan dialihkan ke layanan push penyerang | **MERAH** |
| M21 | Pendengar worker memanggil `/api` | premis aturan daftar izin cache runtuh | **MERAH** (2 pin) |
| M22 | Cabang iOS dihapus (konstanta teksnya dibiarkan) | tombol mati tanpa kalimat di iPhone | **LOLOS HIJAU** pada pin frasa → badan `pushBlocker()` dibandingkan UTUH → **MERAH** (uji PHP **dan** harness S41m) |
| M23 | Layar menyusun kalimat VAPID-nya sendiri | dua kalimat mirip yang akan berselisih | **MERAH** |
| M24 | Penyaring kanal diabaikan controller | "saring web push" menampilkan semuanya | **MERAH** |
| M25 | Label kanal kembali menjadi ternary | kanal ketiga berlabel "WhatsApp" (§3.6) | **MERAH** |

Dan satu "mutasi" yang tidak perlu dibuat karena ia terjadi sendiri: menambahkan dua rute tulis
tanpa gerbang izin **memerahkan** `UngatedApiRouteCensusTest` sampai keduanya dituliskan (§3.7).
Paku P-3d bekerja persis seperti yang dijanjikannya.

> M13, M14 dan M22 adalah pelajaran yang sama dengan bentuk berbeda: **sebuah pin yang hanya mencari
> teks akan hijau atas mutasi yang membiarkan teksnya berdiri**. Ketiganya diperbaiki dengan
> membandingkan bentuk (badan fungsi utuh), memanggil unit yang dimaksud secara langsung, atau
> memberi masukan yang benar-benar menyentuh batasnya.

---

## 5. Permukaan — setiap aturan baru, diperiksa satu per satu

| Permukaan | Yang harus benar | Diperiksa |
|---|---|---|
| Kotak keluar (`NotificationService::outbox`) | fan-out per perangkat; satu baris `skipped` bila gerbang menolak atau daftar kosong | `WebPushOutboxTest` ×3 |
| Kirim ulang (`retry`) | perangkat hilang → 422 dengan kalimatnya; baris perangkat lain tidak tersentuh; `sent` tidak pernah diulang | `WebPushOutboxTest` ×3, `WebPushSpaWiringTest` |
| Job (`DeliverNotification`) | gerbang diperiksa ULANG; pelonggaran pengenal hanya untuk kanal bertanda | `WebPushChannelTest` ×6 |
| `GET core/me/notification-channels` | sebab yang SAMA; label per kanal; `2 perangkat` | `WebPushOutboxTest` ×2, harness S41 |
| `GET/POST/DELETE core/me/push-subscriptions` | hanya milik pemanggil; 404 yang sama untuk "bukan milik Anda" dan "tidak ada" | `PushSubscriptionEndpointTest` ×9 |
| `POST push/rotate` | tidak pernah membuat; asal sama; satu baris tersisa | `PushRotationTest` ×5 |
| `public/app/sw.js` | tujuh pendengar; tiga yang baru tidak menyentuh cache/API; muatan rusak tetap menampilkan notifikasi | `PwaServiceWorkerTest` ×4 + harness S41 |
| Layar Profil | empat jalan buntu TERCAPAI, urutan seperti gerbang; klaim privasi tidak dilebihkan | `WebPushSpaWiringTest` ×5 + harness S41/S41m |
| Sistem › Pengiriman Notifikasi | penyaring kanal bekerja; kalimat konfirmasi menyebut perangkat | `WebPushSpaWiringTest` ×2 |

---

## 6. Batas yang jujur — apa yang paket ini TIDAK jamin

1. **Layanan push tetap melihat metadata.** Isi terenkripsi; **bahwa ada pesan, kapan, dan untuk
   endpoint mana** tidak. Bagi perusahaan yang tidak menerima itu, satu-satunya jalan yang jujur
   adalah tidak menyalakan kanalnya (KEPUTUSAN-INTEGRASI §12.3).
2. **iPhone/iPad hanya lewat Layar Utama, iOS 16.4+.** Di tab Safari biasa Push API tidak ada; layar
   mengatakan cara memasangnya alih-alih menampilkan tombol yang gagal.
3. **Rotasi publik** — batasnya di §3.5, **lima batas** (tiga sejak T3e.5, dua sejak putaran
   verifikasi A-4 dan A-1/B-2): tidak pernah membuat, asal harus sama, tidak pernah menyentuh baris
   milik akun lain, bukan alamat internal, dan laju dibatasi.
4. **Endpoint perangkat hanya boleh menunjuk ke LUAR jaringan server** (putaran verifikasi A-1/B-2).
   Ia melewati penjaga yang sama dengan URL webhook (P-3d §11): https wajib, loopback/privat/
   link-local/CGNAT/nama internal ditolak dalam bentuk apa pun ia ditulis, diperiksa saat menyimpan
   DAN saat mengirim, dan pengalihan tidak diikuti. Yang TIDAK dilakukan: daftar-izin host layanan
   push yang dikenal — ia harus benar untuk setiap peramban di dunia termasuk yang belum ada, dan
   sebuah layanan push yang sah tetapi tidak terdaftar akan gagal dengan kalimat yang menyalahkan
   orangnya. Plafon **10 perangkat per pengguna** (A-5) membatasi penguatan lalu lintas yang bisa
   dipicu pengguna biasa; ia bukan batas kenyamanan.
5. **Satu peramban = satu langganan, dan pemiliknya adalah orang yang TERAKHIR menekan Aktifkan.**
   Itu sifat protokolnya, bukan pilihan kita: di komputer yang dipakai bergantian, peramban
   memulangkan endpoint yang SAMA untuk siapa pun yang sedang masuk. Yang paket ini jamin sejak
   putaran verifikasi: perpindahannya menulis baris audit (A-3), dan baris kotak keluar milik
   pemilik LAMA tidak pernah dikirim ke perangkat itu (B-1) — ia `skipped` dengan kalimatnya.
6. **Mengganti kunci VAPID membatalkan SELURUH langganan.** Tidak ada jalan pintas; itu sifat
   protokolnya, dan yang bisa dilakukan paket ini hanyalah mengatakannya di tiga tempat (perintah,
   DEPLOYMENT §11.3, PANDUAN-ADMINISTRATOR §5.15).
7. **Tidak ada bukti "sampai ke orangnya".** `sent` berarti layanan push menerima pesannya. Web Push
   tidak punya webhook status seperti Meta; kolom "Status penyedia" tetap kosong untuk kanal ini,
   dan `last_success_at` perangkat adalah "layanan push menerima", bukan "orangnya melihat".
8. **Jam tenang berlaku sama** seperti kanal lain: menunda, tidak membuang. Tidak ada perilaku baru,
   dan tidak ada kode baru — `DeliveryGate::postponement()` yang sudah ada.

---

## 7. Bukti peramban (pelajaran 1: rilis SPA belum terverifikasi sampai DIMUAT)

Dua skenario baru, digabung **berdasarkan kunci** ke `docs/bukti-uji/results-phase-3.json`
(**16 → 18 kunci**, tidak satu pun kunci lama disentuh; keduanya `ok: true`, `console_errors: []`).

**S41 (1440×900, 12 syarat)** — dijalankan atas `php -S` + Chromium:

| Yang dibuktikan | Caranya |
|---|---|
| Jalan buntu 4 memakai KALIMAT SERVER | sakelar Pengaturan dimatikan di sqlite, memo `SettingService` dibuang; layar menampilkan "Web push dinonaktifkan di Pengaturan." dan **tidak menawarkan tombol** |
| Layar dan `GET core/me/notification-channels` mengucapkan kata yang SAMA | jawaban endpoint dibaca dari dalam halaman dan dibandingkan dengan teks di layar |
| Sakelar dinyalakan → tombolnya muncul | muat ulang; `blocker` hilang, tombol "Aktifkan notifikasi di perangkat ini" ada |
| Daftar perangkat: label, tanggal daftar, "terakhir berhasil **belum pernah**" | satu baris fixture (lihat catatan di bawah) |
| Perangkat yang BUKAN peramban ini tidak dilencanai "Perangkat ini" | `data-here="no"`, tanpa lencana |
| **Push sungguhan menggambar notifikasi** | CDP `ServiceWorker.deliverPushMessage` + `registration.getNotifications()` → judul, isi, dan `tag` persis dari muatan |
| **Muatan rusak TETAP menggambar notifikasi** | muatan `"{bukan json sama sekali"` → judul "Nusantara ERP", isi kalimat umum (kontrak `userVisibleOnly`) |
| Jalan buntu 1 (tanpa Push API) | `delete window.PushManager` di init script — itulah yang dilihat kode kita pada peramban yang memang tidak punya |
| Jalan buntu 3 (izin DITOLAK peramban) | CDP `Browser.setPermission` … `denied` **dengan `browserContextId`** |

**S41m (390×844, UA iPhone, 5 syarat)** — jalan buntu 2: di tab Safari tombolnya **tidak ada sama
sekali**, yang ada adalah cara memasang lewat "Tambahkan ke Layar Utama" (iOS 16.4+) beserta kalimat
"tombol ini tidak akan pernah bekerja"; kartu tidak menggulir ke samping; **0** simpul teks terpotong.

**Dua hal yang TIDAK bisa dijalankan, dan tidak ditandai hijau** (tercatat di `not_run` pada
hasilnya):

- **`PushManager.subscribe()` sungguhan** — menuntut layanan push sungguhan yang tidak ada di
  Chromium headless mesin ini. Karena itu baris daftar perangkat pada S41 adalah **fixture yang
  disuntikkan ke sqlite**, dan keluarannya mengatakan begitu; yang diukur adalah bagaimana daftar
  itu digambar, bukan bahwa berlangganan berhasil. Alur berlangganan sendiri tetap dipaku uji PHP
  (endpoint, updateOrCreate, label) dan oleh bentuk kodenya (`WebPushSpaWiringTest`).
- **`notificationclick`** — tidak ada pintu CDP untuk mengetuk sebuah notifikasi. Perilakunya dipaku
  sebagai bentuk kode (pin badan pendengar) saja.

**Catatan lingkungan yang ikut ditemukan** (dan yang membuat dua percobaan pertama S41 "gagal" tanpa
ada yang salah di kode kita):

1. `chromium.launch(headless=True)` Playwright menjalankan **headless shell**, yang tidak punya
   jembatan notifikasi: `Notification.permission` tetap `denied` walau `grant_permissions()`
   dipanggil, dan setiap `showNotification()` ditolak. S41 karena itu meluncurkan **Chromium penuh**
   (`channel="chromium"`, `--headless=new`) sendiri dan menutupnya sendiri; bila ia tidak tersedia,
   yang dilaporkan adalah `not_run`, bukan hijau.
2. `Browser.setPermission` **tanpa `browserContextId`** mengenai konteks bawaan, bukan konteks
   Playwright yang sedang dipakai.
3. `php -S` menjawab **405 miliknya sendiri** untuk `PUT` ke jalur yang tampak berekstensi
   (`core/me/preferences/notify.channels`). Itu bukan perilaku aplikasi — nginx melayaninya — dan
   menjalankan S37 menuntut skrip router kecil untuk `php -S`. Dicatat di sini karena skenario S37
   ada sejak P-3a dan siapa pun yang menjalankannya lagi akan menabraknya.

**S37/S37m ikut diperbarui dan dijalankan ulang** (keduanya hijau): daftar kanal di layar Profil
menjadi tiga, dan peta preferensi yang disimpan layar kini membawa tiga kunci. Keduanya adalah akibat
langsung penambahan kanal ketiga, bukan pelonggaran syarat.

---

## 7A. Sapuan dokumentasi (CONVENTIONS §35) — angka grep, sebelum dan sesudah

Paket ini mengganti satu nama kolom layar (**"Penerima" → "Penerima / perangkat"**) dan menambah
satu kartu di layar Profil, jadi sapuannya dijalankan dan angkanya dituliskan.

| Frasa | Sebelum | Sesudah | Catatan |
|---|---|---|---|
| `Pengiriman Notifikasi` | **17** baris / 8 berkas | **22** baris / 9 berkas | tambahannya di ADMINISTRATOR §5.15, DEPLOYMENT §11.3, KEPUTUSAN-INTEGRASI §12, laporan ini |
| `Profil › Notifikasi` | **4** baris / 3 berkas | **11** baris / 5 berkas | |
| `Profil & Notifikasi` | 17 baris / 4 berkas | **17** baris / 4 berkas | tidak berubah — semuanya dibaca, tidak satu pun menjadi salah |
| `Aktifkan notifikasi di perangkat ini` | 0 | **8** baris / 6 berkas | frasa baru paket ini, satu kalimat yang sama di mana-mana |

**Nama kolom yang berubah tidak punya penyebutan basi**: `grep -rn "kolom \"Penerima\"" docs/` dan
varian tabelnya memulangkan **0** baris — tidak ada dokumen yang pernah mendaftarkan kolom layar itu
satu per satu, jadi tidak ada kalimat yang menjadi salah karenanya.

Perintahnya `grep -rn … | wc -l` (bukan `grep -rc`, yang mencetak satu hitungan per berkas dan tidak
pernah memulangkan satu angka).

---

## 8. Gerbang

| Yang dijalankan | Hasil |
|---|---|
| Tujuh berkas uji baru + `PwaServiceWorkerTest` + `UngatedApiRouteCensusTest`, bersama | **OK — 80 uji / 469 asersi** |
| `tests/Feature/Core` + `tests/Unit` + `tests/Feature/Iam` (paket ini menyentuh `config/` dan rute) | **OK — 2.013 uji / 13.851 asersi, 11 dilewati**, 5 mnt 44 dtk |
| `vendor/bin/pint --dirty` atas setiap berkas yang disentuh | `passed` |
| Harness S41 + S41m + S37 + S37m atas `php -S` + Chromium | **4 skenario ok**, `console_errors: []` |
| *(putaran verifikasi)* Harness S41 + S41m diulang atas sqlite yang DIBUAT BARU dari migrasi + seeder | **S41 12/12, S41m 10/10**, `console_errors: []` keduanya |
| *(putaran verifikasi)* `tests/Feature/Core` + `tests/Unit` + `tests/Feature/Iam`, **SQLite**, atas `ffcbc1f` | **OK — 2.033 uji / 13.974 asersi, 11 dilewati**, 6 mnt 37 dtk |
| *(putaran verifikasi)* Suite yang sama, **MySQL 8** (`erp_p3e_vr`, root lewat soket) | **OK — 2.033 uji / 13.981 asersi, 9 dilewati**, 26 mnt 6 dtk |
| *(putaran verifikasi)* 16 mutasi sisi server + 6 `sw.js` + 7 `profil.js` + 1 harness | **30 MERAH, 0 lolos** |
| *(putaran verifikasi)* `vendor/bin/pint` atas setiap berkas yang disentuh | `passed` |
| Migrasi atas salinan sqlite demo (001804 + 001805) | `DONE` keduanya |

**Gerbang penuh dua driver SUDAH DIJALANKAN di putaran verifikasi** (dua baris terakhir tabel di
atas): angkanya **sama persis di kedua driver — 2.033 uji**, dan selisih asersi (13.974 vs 13.981)
serta jumlah yang dilewati (11 vs 9) adalah dua uji khusus-MySQL yang memang dilewati di SQLite.
Selama pembangunan, `PushSubscriptionSchemaTest` juga sudah hijau di `erp_dryrun` (**8 uji / 47
asersi**) — di sanalah M3 (endpoint `varchar(190)`) merah, dan ia **hanya** bisa merah di MySQL.

---

## 9. Keputusan pemilik yang TERBUKA

1. **Menjalankan `core:vapid-keys` dan mengisi `VAPID_*` di `.env` erp1.** Tidak ada nilai di repo,
   dan paket ini tidak menyentuh `.env` mana pun. Runbook: DEPLOYMENT §11.3.
2. **Menyalakan Pengaturan › Notifikasi › "Kirim juga lewat web push"** sesudah (1). Sampai itu
   setiap baris `skipped` dengan sebab yang menyebut apa yang kurang — perilaku yang benar, bukan
   cacat.
3. **Menerima metadata yang tetap dilihat layanan push** (§6.1). Ini keputusan kebijakan, bukan
   teknis, dan satu-satunya cara menolaknya adalah tidak menyalakan kanalnya.
4. **Pertumbuhan `core_notification_deliveries` per PERANGKAT** (§2.3). Kebijakan pemangkasan tabel
   itu masih keputusan pemilik yang terbuka sejak P-3a; paket ini menambah faktor pengalinya.

---

## 10. Yang TIDAK dikerjakan (per butir)

- **Tidak ada aplikasi native, tidak ada SDK FCM** — batas paket, dan alasannya di
  KEPUTUSAN-INTEGRASI §12.1–12.2.
- **Tidak ada kanal SMS.** Tidak ada di roadmap Fase 3.
- **Tidak ada perubahan pada kanal e-mail/WhatsApp** selain yang dituntut kanal ketiga: label per
  kanal di `NotificationChannelController` (§3.6), aritmetika baris di empat uji lama, kalimat
  "belum tersedia (Fase 3, P-3e)" di `DeliveryChannels` dan `DeliveryGate` yang kini tidak berlaku
  lagi, docblock `NotificationService`/`DeliveryChannel` yang masih menulis "web push masih Fase 3",
  dan tiga kalimat dokumen yang berbunyi "kedua kanal"/"dua kanal luar".
- **Sensus rute publik di luar `api/` tidak diperluas menjadi pemeriksaan isi.** Ia menghitung dan
  menamai; apa yang dilakukan `PUT storage/{path}` milik kerangka kerja bukan urusan paket ini
  (kapabilitasnya diperiksa: tanda tangan relatif — §3.7).
- **Tidak ada perbaikan nasihat keamanan `composer audit` yang sudah ada sebelumnya** (13 nasihat
  guzzle/commonmark/excel; tidak satu pun menyangkut dependensi baru paket ini).
- **Tidak ada backfill.** Kedua migrasi aditif; 001805 nullable tanpa nilai bawaan.
- **Tidak ada `Notification.requestPermission()` otomatis saat layar dibuka.** Ia hanya dipanggil
  dari gestur pengguna — di luar gestur peramban menolaknya diam-diam, dan permintaan izin yang
  muncul tanpa diminta adalah cara tercepat mendapat "Blokir" permanen.
- **Tidak ada ikon lencana, tidak ada notifikasi terjadwal di perangkat, tidak ada getar kustom.**
  Yang dipakai hanyalah `showNotification` dengan judul, isi, tag, ikon, dan tautan.
- **`notificationclick` tidak diuji di peramban** (§7) — perilakunya dipaku sebagai bentuk kode.

---

## 11. Commit (urut lama → baru)

| Commit | Isi |
|---|---|
| `80bbcc3` | T3e.1 langkah nol — dependensi `minishlink/web-push` ^10.0 |
| `6e98f8c` | T3e.1 — `config('erp.push')` hanya `env()`, `WebPushSetup` tanpa getter kunci privat, `core:vapid-keys`, sakelar Pengaturan |
| `b1b1f26` | T3e.2 — migrasi 001804/001805, `PushSubscription`, `PushDeviceLabel`, CONVENTIONS §2 |
| `8dc6d07` | T3e.3 — `WebPushChannel`, `WebPushSender`, `ChannelWithoutMessageId`, fan-out, 404/410, gerbang |
| `02ef731` | T3e.4 + T3e.5 — endpoint perangkat, kartu Profil, tiga pendengar `sw.js`, `SHELL_VERSION` 13, `push/rotate` |
| `09be261` | T3e.6 — layar Pengiriman Notifikasi, penyaring kanal, kalimat Kirim ulang |
| `47869dd` | T3e.7 — dokumen, harness S41/S41m, laporan, perbaikan label kanal ketiga (§3.6) |
| `4436683` | Putaran verifikasi (§13) — 12 temuan sisi server: SSRF endpoint, pengalihan, kepemilikan baris, plafon perangkat, endpoint di kolom error, gelung pemotong |
| `bf19aaf` | Putaran verifikasi (§13) — 7 temuan peramban: tiga jalan buntu baru, pendengar yang dipaku, `SHELL_VERSION` 14 |
| `5e74cfe` | Putaran verifikasi (§13) — C-8: S41m dua konteks, syarat 5 → 10 |
| `460a8f5` | Putaran verifikasi (§13) — §6/§3.5/§12.4/CONVENTIONS §42 dibetulkan, §13 ditulis, gagal resolusi dipisahkan dari alamat internal |
| `ffcbc1f` | Putaran verifikasi (§13) — muatan dibentuk di luar `try` pengirim: dua sebab berbeda tidak berbagi satu kalimat pembuka |
| `fa7150c` | Putaran verifikasi (§13) — angka gerbang dua driver |
| `9236ad2` | Putaran penutup (§14) — V-1/V-4/V-5/V-6: sapuan "kanal ketiga" sampai ke LAYAR; kartu jam tenang dan dua pin barunya |
| `6e8fb4a` | Putaran penutup (§14) — V-2/V-3: plafon perangkat tanpa jalan memutar, dan uji untuk penjaga penghapusan lintas-akun |
| `b4223ad` | Putaran penutup (§14) — V-7/V-8: konteks tidak aman mendahului cabang iOS; jumlah batas rotasi ditulis satu angka |
| (commit ini) | Putaran penutup (§14) — daftar commit dan angka gerbang penutup |

---

## 12. Penyimpangan konvensi yang disengaja

1. **`push_subscription_id` tanpa foreign key** meski berada di dalam modul yang sama (CONVENTIONS §3
   menyuruh `constrained()` di dalam modul sendiri). Alasannya di §2.4: baris riwayat harus hidup
   lebih lama daripada perangkatnya, dan kedua perilaku FK yang tersedia menghancurkan salah satu
   dari dua hal yang harus bertahan.
2. **Rute `push/rotate` tanpa autentikasi.** Alasannya di §3.5 — bukan kelonggaran, melainkan
   satu-satunya bentuk yang mungkin untuk pemanggil yang tidak punya akses ke `localStorage`.
3. **Pin `SHELL_VERSION` di `ApiTokenAndWebhookSpaTest` dilonggarkan** dari "sama dengan 12" menjadi
   "tidak pernah turun di bawah 12". Angka literal di sana menuntut setiap paket berikutnya
   menyunting uji paket LAIN hanya untuk menaikkan versi cangkang — dan uji yang harus disunting
   oleh orang yang tidak sedang memikirkannya adalah uji yang akan disunting sampai hijau.
4. **Pendengar `sw.js` naik dari empat menjadi tujuh.** Pelonggaran itu dibayar di tempat yang sama
   dengan tiga pin baru yang membaca badan ketiga pendengar satu per satu (CONVENTIONS §21 dan §42).
5. **Tiga penolong `WebhookUrl` menjadi publik** (`literalAddress`, `resolve`, `isPrivateName`) —
   putaran verifikasi. Pemakainya kedua, `PushEndpoint`, membutuhkan PENILAIAN alamatnya tanpa
   kalimat webhooknya. Alternatifnya adalah menulis aturan bentuk samaran untuk kedua kalinya, dan
   dua daftar yang sama hari ini adalah dua daftar yang berbeda enam bulan lagi — yang satu tahu
   `0177.0.0.1`, yang satu tidak.

---

## 13. Putaran verifikasi (13 Sep 2026) — 25 temuan tiga lensa

Tiga lensa membaca paket ini sesudah `47869dd` dan memulangkan 25 temuan. **24 diperbaiki, 1
ditolak.** Setiap perbaikan punya uji yang dibuktikan merah oleh mutasi; angka mutasinya di bawah.

### 13.1 Bentuk yang berulang

Enam temuan tertinggi punya satu bentuk yang sama, dan menamainya lebih berguna daripada
menghitungnya: **sebuah nilai yang datang dari luar dipercaya sebagai identitas.** Endpoint
dipercaya sebagai alamat yang boleh dituju (A-1/B-2), jawaban pengalihan dipercaya sebagai tujuan
yang sama (A-2), endpoint dipercaya sebagai kunci baris yang boleh ditulis (A-3) dan dihapus (A-4),
dan id langganan dipercaya sebagai perangkat penerimanya (B-1).

Yang paling mahal untuk diakui: **kebijakan SSRF sudah ada, lengkap, di pohon yang sama.** P-3d
menulisnya delapan hari sebelumnya — `WebhookUrl`, KEPUTUSAN-INTEGRASI §11 — beserta bentuk
samarannya, pemeriksaan ganda, dan resolver sebagai seam. P-3e tidak memakai satu baris pun.
Asimetri yang menunjukkan ini kelupaan dan bukan keputusan: **rotasi memaksa asal endpoint baru
sama dengan yang lama** karena "langganan bisa dialihkan ke layanan push penyerang", sementara
pendaftaran di pintu sebelah menerima host apa pun.

### 13.2 Diperbaiki

| # | Temuan | Perbaikan | Mutasi |
|---|---|---|---|
| A-1, B-2 | Endpoint https APA SAJA diterima; server benar-benar membuka soket ke alamat internal | `PushEndpoint` memakai penilaian `WebhookUrl` apa adanya; tiga pintu: simpan, rotasi, dan sekali lagi saat kirim. Alamat internal = permanen, nama yang tak terselesaikan = SEMENTARA | M1, M2, M3, M15 |
| A-2 | Pengalihan diikuti (termasuk https→http ke link-local), dan barisnya `sent` | `allow_redirects => false` + `connect_timeout`, ditimpakan DI BAWAH opsi uji; 3xx = gagal berkalimat | M4, M5 |
| A-3 | `store()` memindahkan kepemilikan baris orang lain tanpa jejak | Perpindahan tetap terjadi (itu sifat peramban bersama) tetapi menulis baris audit | M7 |
| A-4 | `push/rotate` tanpa sesi menghapus baris milik akun lain | Batas keempat: `$existing->user_id !== $old->user_id` → 422 | M10 |
| A-5 | Tidak ada plafon perangkat: fan-out sebagai penguat lalu lintas | `PushSubscriptions::MAX_PER_USER = 10`, 422 berkalimat; pendaftaran ulang perangkat yang ADA tetap boleh | M6 |
| A-6, B-4 | Endpoint utuh di kolom `error` yang dibaca setiap pemegang `core.update` | Endpoint ikut daftar samaran `ProviderErrorScrubber::webPush()`; docblock yang membantah `PushRotationController` dibetulkan | M11 |
| A-7, B-8 | Syarat henti gelung pemotong tidak pernah bisa menyala | `mb_strlen($body) > 1` + lemparan akhir berkalimat | M13 (merah dengan **menggantung** — itu bentuk cacatnya), M14, M16 |
| B-1 | Kanal mengirim ke langganan yang sudah pindah pemilik, dan mencatat `sent` | `subscriptionOf()` mencari di dalam lingkup pemilik baris; ketidakcocokan = `skipped`, bukan `failed` | M8 |
| B-3 | Lima kalimat menjanjikan layar "Sistem › Log Audit" yang tidak pernah dibangun | Kelimanya menyebut tabel + `GET api/core/audit-log` + PANDUAN §3.10 | (dokumen) |
| B-5, C-4 | Berlangganan ulang sesudah ganti kunci VAPID menumpuk baris hantu; `$previousEndpoint` kode mati | Klien mengirim `previous_endpoint`, `store()` meneruskannya — docblock-nya menjadi benar | M12, C-4m |
| B-6 | Lingkup pemilik di Kirim ulang tidak punya uji sama sekali | Uji tiga langkah; sebelumnya membuang lingkupnya meninggalkan 85 uji hijau | M9 |
| B-7 | COUNT perangkat dibayar setiap pemasangan yang web push-nya mati | `deviceSummary()` diam bila `webPushServerReason() !== null` | — |
| C-1 | `notificationclick` mengaku "dipaku" — seluruh badannya bisa dibuang dan gerbang hijau; `tautan` dipakai tanpa pemeriksaan asal | Badan dipaku (close, matchAll+focus SEBELUM openWindow); `tautanAman()` menjatuhkan asal lain ke SCOPE | 5 mutasi sw.js |
| C-2 | Pin muatan rusak tidak melihat jalan keluar sebelum `showNotification` | Tidak boleh ada `return`/`throw` sebelum panggilan itu | 1 mutasi sw.js |
| C-3 | Tanpa registrasi worker, tombol berputar selamanya sesudah izin diberikan | Jalan buntu 6 + `Promise.race` berkalimat | 2 mutasi profil.js |
| C-5 | Kartu mengabaikan `reason`: janji "akan muncul" untuk baris yang akan Dilewati | Jalan buntu 7 dengan penanda `user_off` dari server; kalimatnya tetap kalimat DeliveryGate | 1 mutasi profil.js |
| C-6 | Sesudah Blokir keluar kalimat KEDUA, dan kartu tidak berpindah sampai dimuat ulang | `DENIED_HELP` yang dilempar, dan cabang galat menggambar ulang kartunya | 2 mutasi profil.js |
| C-7 | "Peramban tidak mendukung Push API" juga keluar untuk peramban sehat di pemasangan `http://` | Jalan buntu 5 sendiri (`isSecureContext`); DEPLOYMENT §11.3 menyebut HTTPS sebagai syarat nol. Kalimatnya ditulis tanpa literal `http(s)://` — `VendorManifestTest` memindai setiap literal semacam itu di `public/app`, dan melonggarkan aturan anti-CDN demi sebuah kalimat adalah harga yang salah | 1 mutasi profil.js |
| C-8 | S41m mengukur pemotongan teks pada kartu tanpa satu baris perangkat pun | Dua konteks: iPhone (blocker) + Android 390 px dengan perangkat berlabel 33 karakter; syarat 5 → 10 | Skenario tanpa fixture → 2 syarat merah |
| C-10 | Satu-satunya penjaga jaringan adalah harfiah `/api`: `fetch()` ke host pihak ketiga lolos | Tidak ada alamat MUTLAK di ketiga badan pendengar | 1 mutasi sw.js |

Badan `pushBlocker()` tetap dibandingkan UTUH: **empat jalan buntu menjadi tujuh**, dalam urutan
DeliveryGate. `SHELL_VERSION` 13 → 14 (CONVENTIONS §21).

**Satu perbaikan yang tidak berasal dari temuan mana pun**, melainkan dari memeriksa perbaikan
A-1/B-2 sendiri: penjaga alamat punya DUA kegagalan dengan UMUR yang berbeda, dan versi pertama
perbaikan ini memperlakukan keduanya sama. "Alamatnya di dalam jaringan server" permanen —
mengulanginya lima kali hanya mengulang permintaan yang justru dilarang. "Namanya tidak bisa
diterjemahkan **sekarang**" sementara, dan menyatakan sebuah pemberitahuan gagal SELAMANYA karena
resolver tersendat sepuluh detik adalah penjaga yang menimbulkan kerugiannya sendiri. Dipisahkan di
kelas pengecualian (`LogicException` vs `RuntimeException`) dan dipaku (M15: menyatukannya lagi →
merah). Sebuah penjaga keamanan yang membuang pemberitahuan orang adalah penjaga yang akan
dimatikan orang.

**Dan satu lagi dari sumber yang sama**: muatan kini dibentuk DI LUAR `try` pengirim. Di dalamnya,
`catch (Throwable)` milik kegagalan PUSTAKA membungkus kalimat pemotong muatan (A-7) dengan
"Pengiriman web push gagal disiapkan: …" — dan orang yang membaca baris itu di layar akan pergi
memeriksa kunci VAPID untuk sebuah baris yang sebenarnya mengeluh tentang `APP_URL`. Dua sebab
berbeda tidak boleh berbagi satu kalimat pembuka (M16). Uji A-7 diperluas menjalankan jalur
pengiriman SUNGGUHAN, bukan hanya `payloadFor()` langsung — versi pertamanya tidak pernah menyentuh
pembungkus itu dan mutasinya lolos hijau.

### 13.3 Ditolak — satu

**C-9: "`listenerBody()` menjanjikan 'tanpa komentar' tetapi tidak membuang komentar."** Premisnya
salah. `listenerBody()` memanggil `$this->code()`, yang adalah `stripComments($this->worker())` —
persis seperti penolong sekerabatnya di baris 572/578 yang temuan itu sebut sebagai pembanding.
Dibuktikan langsung: menyisipkan `// Bentuk muatannya: judul, isi, tautan, tag }` (kurung tak
seimbang di dalam komentar, contoh temuan itu sendiri) tepat sebelum `let isi = {};` lalu
menjalankan `stripComments()` atas kedua versi — komentarnya **tidak ada** di keluaran (`str_contains(…,
'Bentuk muatannya') === false`), jadi kurungnya tidak pernah sampai ke penghitung kurung dan
potongan yang dipulangkan tidak bergeser. Berkas ujinya tetap hijau karena tidak ada yang rusak,
bukan karena pemeriksaannya lolos diam-diam. Tidak ada perubahan.

### 13.4 Yang MASIH diragukan sesudah putaran ini

1. **Perpindahan langganan di peramban bersama tetap terjadi**, dan itu memang keputusan: dua baris
   untuk satu langganan berarti pemberitahuan orang pertama tetap dikirim ke layar orang kedua.
   Yang berubah hanya bahwa ia tidak lagi diam-diam (audit) dan bahwa baris lama tidak lagi dikirim
   (B-1). Orang pertama tetap berhenti menerima web push di komputer itu **tanpa diberi tahu di
   layar** — hanya log audit yang tahu, dan log audit belum punya layar (B-3).
2. **Plafon 10 adalah angka yang dipilih, bukan diukur.** Ia menutup penguatan lalu lintas; ia tidak
   berdasar data pemakaian, karena belum ada pemakaian.
3. **`notificationclick` tetap tidak diuji di peramban** — tidak ada pintu CDP untuk mengetuk
   notifikasi. Yang berubah: sekarang ia dipaku sebagai bentuk kode, dan §7 tidak lagi mengaku
   lebih daripada itu.
4. **Badan jawaban penyedia masih masuk kolom `error`** (endpoint-nya yang disamarkan). Dengan SSRF
   tertutup, sasarannya adalah layanan push sungguhan; kalau suatu hari badan itu terbukti membawa
   sesuatu yang tidak boleh dilihat, potongan 480 karakter bukan jawabannya.
5. **Penjaga alamat berlaku SURUT untuk baris yang sudah ada.** Sebuah langganan yang tersimpan
   sebelum putaran ini dengan endpoint yang kini ditolak akan gagal permanen pada pengiriman
   berikutnya, dan rotasinya dijawab 422. Hari ini itu himpunan kosong — P-3e belum di-deploy dan
   `VAPID_*` erp1 masih kosong (§9.1), jadi belum ada satu baris `core_push_subscriptions` pun di
   produksi. Ia dicatat karena urutannya penting: kalau paket ini pernah hidup lebih dulu tanpa
   penjaga, penjaga yang datang belakangan harus menyapu, bukan hanya menolak yang baru.
6. **Satu kalimat "Sistem › Log Audit" TERSISA di luar paket ini**
   (`PANDUAN-ADMINISTRATOR.md` baris ~3984, matriks persetujuan) — cacat yang sama, tetapi milik
   paket lain; tidak disentuh supaya putaran ini tidak melebar.

## 14. Putaran penutup (13 Sep 2026) — 8 temuan verifier penutup, semuanya ditutup

Verifier penutup bekerja di worktree-nya sendiri atas `fa7150c`, menjalankan **36 mutasinya sendiri**
atas perbaikan §13.2 (34 merah; dua yang hijau adalah mutasi yang memang tidak mengubah perilaku),
memeriksa ulang satu penolakan §13.3, dan menjalankan migrasi ini pada basis data MySQL **berisi**
(rollback 001805 → isi tabel dengan baris e-mail + WhatsApp → jalankan lagi: DONE 47 ms, kedua baris
utuh, `push_subscription_id` NULL). Verdiktnya **BELUM SIAP**, atas dua hal yang masing-masing satu
sampai lima baris — dan atas satu BENTUK yang berulang empat kali.

### 14.1 Bentuk yang berulang: sapuan "kanal ketiga" berhenti satu lapis sebelum layar

LAPORAN dan PANDUAN-PENGGUNA sudah berbicara tentang tiga kanal; **layar belum**. Empat dari delapan
temuan adalah instans bentuk itu, dan yang pertama adalah kebohongan struktural yang §13 memang
dibuat untuk mengejar — dalam arah terbalik: layar **menyangkal** perilaku yang benar.

| # | Apa | Ditutup di |
|---|---|---|
| **V-1** (sedang) | Kartu "Jam tenang" berbunyi "e-mail dan WhatsApp DITUNDA" — dua kanal dari tiga, satu kartu di atas kartu web push. `outboxRow()` menunda SETIAP baris `queued` tanpa memandang kanal, jadi setiap baris web push ikut ditunda. Orang yang membacanya menyimpulkan ponselnya akan berbunyi pukul 02.00. Tidak ada satu uji pun yang menyentuh kalimat itu. | `9236ad2` |
| **V-4** (rendah) | `USER_OFF_HINT` menyuruh orangnya ke kartu bernama "Kanal pemberitahuan"; kartu itu berjudul "Kanal notifikasi". Cacat yang sama bentuknya dengan "Sistem › Log Audit" yang §13.2 tutup di lima tempat — dan diperkenalkan OLEH putaran itu. | `9236ad2` |
| **V-5** (rendah) | Pesan 422 `notify.channels` mengeja "{email, whatsapp}" — dua kanal, di pesan yang ADA untuk memberi tahu bentuk yang benar — dan `NotificationPreferencesTest` memakunya kata demi kata. | `9236ad2` |
| **V-6** (rendah) | Deskripsi kelompok Pengaturan › Notifikasi (digambar di atas KETIGA sakelarnya) dan CONVENTIONS §38 masih menghitung dua kanal / dua baris `skipped`. | `9236ad2` |

**V-1 ditutup dengan DUA pin, keduanya dibuktikan merah**, karena kalimat dan perilaku adalah dua hal:
`WebPushOutboxTest::test_quiet_hours_postpones_every_web_push_row_one_per_device` memaku yang
TERJADI (mutasi: `outboxRow()` melewati penundaan untuk kanal webpush → "Baris web push berangkat
SEKARANG di tengah jam tenang"), dan
`WebPushSpaWiringTest::test_the_quiet_hours_card_names_every_channel_it_actually_postpones` memaku
yang DIKATAKAN (mutasi: kalimat dikembalikan ke versi dua kanal → merah). Pin kedua mengulang
**daftar kanal**, bukan kalimatnya, sehingga kanal keempat memerahkannya; peta namanya **dieja di
uji dan tidak diturunkan dari kode yang diuji** — pin yang membaca harapannya sendiri tidak pernah
bisa merah (pelajaran Fase 2).

**V-5** ditutup dengan menurunkan bentuknya dari `DeliveryGate::USER_CHANNELS` di sisi produksi,
sementara ujinya tetap memaku literalnya: kanal berikutnya memerahkannya **sekali**, dengan sadar.

### 14.2 Dua batas yang tidak sekuat kalimatnya

| # | Apa | Ditutup di |
|---|---|---|
| **V-2** (sedang) | Plafon `MAX_PER_USER` diperiksa hanya ketika `$existing === null`. Pendaftaran yang MENGAMBIL ALIH endpoint milik akun lain — jalur "peramban bersama" yang memang disengaja — melewatinya: sepuluh perangkat menjadi sebelas. Tiga dokumen menyebutnya batas mutlak. Uji A-5 tidak bisa menangkapnya (ia mendaftarkan sebelas endpoint BARU). | `6e8fb4a` |
| **V-3** (sedang) | Lingkup pemilik pada penghapusan lewat `previous_endpoint` — sebuah pintu penghapusan lintas-akun di rute tanpa gerbang izin — **tidak punya satu uji pun**: mutasi yang membuangnya lolos hijau atas 99 uji. Kodenya benar sejak awal; ujinya yang tidak ada. Ini B-6 dalam bentuk kedua, di pintu yang lebih terbuka, dan ia lolos dari putaran §13. | `6e8fb4a` |

Uji V-2 memeriksa **dua** hal, bukan satu: pendaftaran ke-11 ditolak 422, DAN langganan orang lain
tidak ikut berpindah oleh pendaftaran yang gagal.

### 14.3 Urutan yang adalah kalimatnya, dan satu angka yang ditulis tiga kali berbeda

| # | Apa | Ditutup di |
|---|---|---|
| **V-7** (rendah) | `pushBlocker()` menempatkan cabang iOS SEBELUM cabang konteks tidak aman. Pada pemasangan `http://`, pengguna iPhone mendapat kalimat yang menyalahkan sistem operasinya ("itu batas sistem operasinya, bukan setelan yang bisa diubah") padahal yang kurang adalah HTTPS: ia akan memasang aplikasi ke Layar Utama dan tombolnya tetap tidak bekerja. Itu persis bentuk yang C-7 diangkat untuk menutup. | `b4223ad` |
| **V-8** (rendah) | Jumlah batas rute rotasi ditulis tiga angka berbeda: docblock "Tiga" (lalu mendaftar lima), LAPORAN §6.3 "EMPAT", LAPORAN §3.5 dan KEPUTUSAN §12.4 "Lima". Bagi pembaca yang menilai risiko rute publik itu, angka yang tidak bisa dipercaya lebih buruk daripada tidak ada angka. | `b4223ad` |

`isSecureContext` adalah sifat **pemasangan** — lebih global daripada perangkat yang dipegang
orangnya — jadi tempatnya tepat setelah `server_reason`. Urutannya dipaku UTUH sebagai satu string,
jadi pertukaran itu memerahkan `test_all_seven_dead_ends_are_actually_reachable_in_the_order_of_the_gate`
(mutasi dijalankan: merah).

### 14.4 Yang verifier penutup TOLAK, dan yang ia konfirmasi

Ia memeriksa ulang penolakan §13.3 (**C-9**) dan menyatakannya **benar**: `listenerBody()` memang
memanggil `$this->code()` = `stripComments($this->worker())`, jadi premis temuan itu salah. Dua
sub-saran yang ditolak juga pantas — daftar-izin host layanan push harus benar untuk peramban yang
belum ada, dan menghapus langganan pada 401/403 menjadikan satu salah ketik `VAPID_PRIVATE_KEY`
penghapus seluruh basis perangkat.

Gerbang per-direktori sesudah kedelapan temuan ditutup (`tests/Feature/Core` + `tests/Unit` +
`tests/Feature/Iam`, SQLite): **2.037 uji / 14.013 asersi, 11 dilewati**, 5 mnt 57 dtk — empat uji
lebih banyak daripada angka verifier penutup (2.033), yaitu persis keempat pin baru putaran ini.
`pint --test` atas setiap berkas yang disentuh: passed.

Lima pertanyaan wajibnya dijawab dengan bukti: **tidak ada jalan keluar bagi kunci privat VAPID**
(dibaca satu tempat, `private`, dua pintu keluar yang keduanya menuju penandatangan atau penyamar);
**kanal e-mail dan WhatsApp tidak berubah perilakunya** selain aritmetika baris 2 → 3 (keenam
suntingan uji lama dibaca satu per satu; pelonggaran "pengenal wajib" hanya berlaku bagi
`ChannelWithoutMessageId`, dan mutasi yang menandai `MailChannel` dengannya merah); dan **migrasinya
aman pada tabel berisi** (dijalankan, §14 pembuka). Satu hal yang pemilik harus tahu sebelum deploy
dan yang laporan ini sudah katakan di §2.3: sejak hari deploy, setiap notifikasi menulis **satu baris
`skipped` tambahan per penerima**, bahkan dengan sakelarnya mati.
