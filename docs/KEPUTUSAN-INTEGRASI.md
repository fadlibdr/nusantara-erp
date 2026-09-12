# Keputusan integrasi: WhatsApp — pencabutan penolakan tertulis

> **Status: PENOLAKAN DICABUT, dengan TIGA prasyarat milik pemilik yang belum terpenuhi.**
> Paket: HM **P-3a** (ROADMAP-HASHMICRO Fase 3, tugas T3a.0), 11 September 2026.
> Berkas ini adalah keluaran PERTAMA paket itu — ditulis sebelum satu baris kode
> WhatsApp pun — dan bentuknya mengikuti [`SIKAP-E-SIGN.md`](SIKAP-E-SIGN.md) (F-8):
> apa yang dulu ditolak, di mana, mengapa dicabut sekarang, syaratnya, dan apa yang
> terjadi selama syarat itu belum terpenuhi.

## 1. Keputusan, satu kalimat

Nusantara ERP **membangun kanal WhatsApp** sebagai kanal LUAR ketiga di kotak keluar
`core_notification_deliveries` (P-0b) — di samping e-mail dan di bawah kanal dalam
aplikasi yang tetap menjadi **kanal kebenaran** — **tetapi kanal itu tidak mengirim
satu pesan pun sampai pemilik memenuhi tiga prasyarat di §4**, dan selama itu setiap
baris pengirimannya berstatus **`skipped` dengan sebab yang terbaca di layar**, bukan
`sent`, bukan `failed` yang diulang lima kali sia-sia.

## 2. Apa yang dulu ditolak, dan di mana tertulisnya

Penolakan WhatsApp bukan lisan. Ia tertulis di empat tempat, dan keempatnya masih
ada apa adanya supaya sejarahnya bisa dibaca:

| Tempat | Kalimatnya |
|---|---|
| [`ROADMAP-DEVIASI.md`](ROADMAP-DEVIASI.md) §0 batas 5 (baris 18) | "Jangan membangun yang sudah ditolak tertulis: portal pelanggan, multi-valuta, peminjaman alat kecil, bank host-to-host, **WhatsApp**, aplikasi native, multi-tenant." |
| [`LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md`](LAPORAN-DEVIASI-v2-PROMPT-vs-NUSANTARA-ERP.md) baris 216 dan 258 | "Penolakan tertulis: … WhatsApp … — *out of scope* eksplisit" |
| [`PANDUAN-ADMINISTRATOR.md`](PANDUAN-ADMINISTRATOR.md) §5.10 dan "Yang tidak ada" | "WhatsApp tidak ada. Butuh akun gateway dan template yang disetujui Meta, *none of which can ship inside the application*." |
| [`PANDUAN-PENGGUNA.md`](PANDUAN-PENGGUNA.md) §1.6 dan tabel "siapa yang bisa" | "Email mati secara bawaan, dan WhatsApp tidak ada." |

ROADMAP-HASHMICRO baris 139 menyebut juga "ASSESSMENT" sebagai tempat penolakan;
diukur `grep -in whatsapp docs/ASSESSMENT*.md` pada 11 Sep 2026: **nol baris**.
Penolakan di sana tidak pernah tertulis dengan kata itu; yang ada adalah empat
tempat di atas. Dicatat supaya rujukan berikutnya menunjuk berkas yang benar.

Alasan penolakan lama, dibaca dari kalimat PANDUAN-ADMINISTRATOR: WhatsApp Business
menuntut **akun** di pihak ketiga dan **template pesan yang disetujui Meta** — dua hal
yang tidak bisa dikirim di dalam sebuah rilis perangkat lunak. Alasan itu **masih benar
hari ini**, dan justru itulah yang menjadi tiga prasyarat §4.

## 3. Mengapa dicabut sekarang

**Pemilik yang mencabutnya.** ROADMAP-HASHMICRO (disetujui pemilik 5 Sep 2026) §2
memetakan "Notifikasi WhatsApp / e-mail" sebagai kesenjangan ⬜ terhadap HashMicro,
dan Fase 3 / P-3a memerintahkan T3a.0 "mencabut penolakan WhatsApp tertulis … dengan
TIGA prasyarat milik pemilik". Berkas ini melaksanakan perintah itu; ia tidak
mengambil keputusannya sendiri.

**Prasyarat teknisnya sudah ada sejak P-0b (5 Sep 2026).** Penolakan lama lahir ketika
pengiriman notifikasi masih sinkron di dalam listener dan kegagalannya ditelan
`guard()`; sebuah kanal luar yang bisa gagal diam-diam adalah kanal yang lebih baik
tidak ada. Sekarang ada kotak keluar `core_notification_deliveries` dengan
`queued|sent|failed|skipped`, job `DeliverNotification` (5 percobaan, backoff
60/300/900/3600 s), layar Sistem › Pengiriman Notifikasi dengan Kirim ulang, dan
`GET core/health`. Kegagalan kirim **terlihat**. Itu mengubah pertanyaannya dari
"apakah kita berani punya kanal luar" menjadi "apakah kanalnya jujur".

**Kanal dalam aplikasi tidak sampai ke orang yang tidak membuka aplikasi.** Alarm yang
paling penting di sistem ini — cadangan basi, penjadwal mati, invoice lewat jatuh
tempo, eskalasi persetujuan — justru ditujukan kepada direktur dan administrator yang
tidak duduk di depan ERP sepanjang hari. Lonceng di header (polling 90 detik) hanya
bekerja untuk yang sedang masuk.

**Yang tidak berubah**: penolakan enam butir lain di ROADMAP-DEVIASI batas 5 (portal
pelanggan, multi-valuta, peminjaman alat kecil, bank host-to-host, aplikasi native,
multi-tenant) **tetap berlaku** — ROADMAP-HASHMICRO §6 kalimat terakhir. Hanya WhatsApp
yang dicabut, dan hanya dengan syarat di bawah.

## 4. Tiga prasyarat milik PEMILIK — kanal tidak mengirim sebelum ketiganya ada

Ketiganya adalah **data pemilik**, bukan tebakan agen, dan **tidak satu pun ada di repo**.
Kode menerimanya lewat `.env` (nama variabelnya di [`DEPLOYMENT.md`](DEPLOYMENT.md) §11,
tanpa nilai), dan sampai terisi kanal menjawab `skipped` dengan kalimat yang menyebut
mana yang kurang.

1. **Akun WhatsApp Business (WABA) yang terverifikasi Meta.** Verifikasi bisnis Meta
   menuntut dokumen legalitas perusahaan (NIB/akta) dan nomor telepon yang belum
   pernah dipakai WhatsApp pribadi. Prosesnya milik pemilik dan memakan waktu berhari-hari.
   Keluarannya yang dibutuhkan kode: **token akses sistem** dan **Phone Number ID**
   (Meta Cloud API), atau kredensial Qontak bila jalur §5 kedua yang dipilih.
   Tanpa ini: setiap baris WhatsApp `skipped` — "Kanal WhatsApp belum dikonfigurasi".
2. **Template pesan yang disetujui Meta — satu per peristiwa, lima peristiwa.** Meta
   **menolak teks bebas** di luar jendela 24 jam sejak pesan terakhir pelanggan; sebuah
   alarm sistem hampir tidak pernah berada di dalam jendela itu. Jadi kanal ini
   **hanya mengirim pesan template**, dengan tiga placeholder `{{1}}` judul, `{{2}}`
   isi, `{{3}}` tautan (kontrak di CONVENTIONS §38). Lima peristiwanya: `deadline.due`,
   `approval.escalated`, `ar.dunning`, `backup.stale`, `scheduler.down`. Persetujuan
   template oleh Meta memakan **1–7 hari dan bisa DITOLAK** (kategori "utility" dengan
   kalimat yang dianggap promosi ditolak; penolakan berarti menulis ulang dan
   mengajukan lagi). **Nama template dan status persetujuannya adalah data pemilik** —
   disimpan di `.env` (`WHATSAPP_TEMPLATE_*`), kosong di repo. Tanpa nama untuk sebuah
   peristiwa: barisnya `skipped` — "Template WhatsApp … belum disetujui Meta / belum
   diisi di .env". Peristiwa **di luar kelima itu** (pengajuan/persetujuan dokumen,
   pengingat persetujuan yang belum eskalasi) **tidak punya template WhatsApp** dan
   `skipped` dengan kalimat yang mengatakannya — bukan dikirim sebagai teks bebas
   yang akan ditolak Meta.
3. **Anggaran per percakapan.** Meta menagih **per percakapan** (jendela 24 jam per
   nomor per kategori), dalam USD, ke kartu kredit/akun iklan bisnis; Qontak menagih
   IDR per pesan/percakapan plus langganan. Sebuah alarm harian ke lima direktur adalah
   ±150 percakapan sebulan; eskalasi dan penagihan menambahnya. Pemilik menetapkan
   plafon bulanan dan kategori template (utility, bukan marketing — tarifnya berbeda).
   Kode tidak memaksakan plafon itu (ia tidak melihat tagihan Meta); yang dilakukannya
   adalah membuat **setiap pesan yang keluar tercatat** di Sistem › Pengiriman
   Notifikasi dengan `provider_id` (wamid) dan status balik dari webhook, sehingga
   angka tagihan bisa dicocokkan dengan hitungan baris `sent`.

Ditambah dua hal yang bukan prasyarat kanal tetapi prasyarat **orang**:

- **Nomor E.164 dan opt-in berstempel waktu per pengguna** (`users.phone_e164`,
  `whatsapp_opt_in_at`, `whatsapp_opt_in_via`). Persetujuan yang tidak bertanggal
  bukan persetujuan; pengguna tanpa nomor atau tanpa opt-in `skipped` dengan
  kalimatnya. Diisi orangnya sendiri di Profil › Notifikasi, atau administrator
  di Sistem › Pengguna atas persetujuan yang diberikan di luar aplikasi.
- **Sakelar `notifications.whatsapp_enabled`** di Pengaturan, mati secara bawaan —
  pasangan `notifications.email_enabled`. Menyalakannya sebelum `.env` terisi hanya
  menghasilkan baris `skipped` yang lebih spesifik, bukan pesan.

## 5. Pilihan penyedia, dan alasannya

Keputusan pemilik #7 (ROADMAP-HASHMICRO §5 baris 246) — **belum dijawab** (⏳);
rekomendasinya dipakai sampai dijawab:

| Penyedia | Keputusan | Alasan |
|---|---|---|
| **Meta Cloud API langsung** (`graph.facebook.com`) | **Jalur utama** — dibangun di P-3a (`WHATSAPP_PROVIDER=meta`) | Tanpa perantara dan tanpa biaya platform: yang dibayar hanya tarif percakapan Meta. Syaratnya bisnis sudah terverifikasi Meta (prasyarat §4.1). Klien HTTP Laravel (`Http::`), tanpa SDK — dependensi Composer nol, sesuai batas paket. Webhook status ditandatangani `X-Hub-Signature-256` (HMAC-SHA256 badan mentah dengan app secret) — permukaan publik satu-satunya, dan verifikasinya wajib. |
| **Qontak** (Mekari) | **Jalur kedua** bila verifikasi bisnis Meta tidak dapat ditempuh — dikenali di konfigurasi (`WHATSAPP_PROVIDER=qontak`), **pengirimnya belum ditulis** | Penyedia solusi bisnis (BSP) resmi Meta di Indonesia: faktur IDR, dukungan lokal, mengurus verifikasi WABA atas nama pelanggan. Biayanya langganan + per pesan. Bentuk API-nya (id template, id integrasi kanal, parameter) berbeda dari Meta, dan **tidak boleh dikarang** — ditulis setelah pemilik memilih jalur ini dan memberi akses sandbox-nya. Sampai itu, memilihnya menghasilkan `skipped` "penyedia qontak belum diimplementasikan". |
| **Fonnte, Wablas, dan gateway "WhatsApp Web" sejenis** | **DITOLAK** | Mereka menjalankan nomor WhatsApp BIASA lewat otomasi antarmuka, bukan API resmi: **nomornya bisa diblokir Meta** tanpa peringatan, dan pesan alarm perusahaan hilang bersama nomornya. Murah di bulan pertama, mahal pada hari nomor itu diblokir. Tidak ada mode `WHATSAPP_PROVIDER` untuknya, dengan sengaja. |

## 6. Apa yang terjadi SEBELUM prasyaratnya terpenuhi — kanal ada, jujur

Ini bagian yang paling penting, karena inilah keadaan produksi hari ini dan mungkin
berminggu-minggu ke depan.

- **Kanal WhatsApp ADA** di `DeliveryChannels` dan di enum layar; setiap notifikasi
  yang ditulis untuk seseorang menghasilkan satu baris kotak keluar per kanal luar
  (e-mail DAN WhatsApp), persis seperti e-mail sejak P-0b.
- **Setiap baris WhatsApp berstatus `skipped`**, dengan sebab yang **terbaca di kolom
  "Galat / alasan"** layar Sistem › Pengiriman Notifikasi, dalam urutan pemeriksaan:
  "WhatsApp dinonaktifkan di Pengaturan." → "Kanal WhatsApp belum dikonfigurasi (…)"
  → "Dimatikan pengguna di Profil › Notifikasi." → "Penerima tidak punya nomor
  WhatsApp" → "Penerima belum opt-in WhatsApp." → "Template WhatsApp … belum
  disetujui Meta / belum diisi di .env." Sebab pertama yang benar yang ditulis.
- **Tidak ada `failed` yang diulang lima kali** untuk keadaan yang bukan kegagalan
  penyedia: kekurangan konfigurasi adalah `skipped` (tidak pernah dicoba), dan
  Kirim ulang atas baris itu ditolak 422 dengan kalimat yang sama plus apa yang harus
  dilengkapi.
- **Tidak ada status `sent` tanpa pengenal dari penyedia** — untuk kanal mana pun.
  Perbaikan ini ikut mengoreksi e-mail: sebelum P-3a, dengan `MAIL_MAILER=log`
  (keadaan produksi erp1) baris e-mail ditandai `sent` dengan Message-ID buatan
  lokal (`…@example.co.id`) — sebuah klaim tanpa server di baliknya. Sekarang ia
  `skipped` — "MAIL_MAILER=log — belum ada server surel".
- **Kanal dalam aplikasi tidak tersentuh**: barisnya ditulis lebih dulu, sinkron,
  sebelum satu baris kotak keluar pun — dan jam tenang pengguna (T3a.2) hanya menunda
  kotak keluar, tidak pernah barisnya.
- **Tidak ada permintaan HTTP yang keluar** ke Meta selama kanal belum dikonfigurasi:
  pemeriksaan konfigurasi berjalan SEBELUM `Http::` dipanggil, dan uji memaku
  `Http::preventStrayRequests()`.

## 7. Yang tetap DITOLAK di dalam kanal ini — daftar yang eksplisit

1. **Teks bebas** ke nomor WhatsApp. Hanya template (§4.2). Termasuk "balas pesan ini
   untuk menyetujui" — tidak ada webhook pesan masuk, hanya webhook STATUS.
2. **Menyimpan token, app secret, atau sandi SMTP di `core_settings`, jawaban API,
   kolom error kotak keluar, atau log.** Hanya `.env`. Jawaban penyedia disaring
   (`ProviderErrorScrubber`) sebelum masuk kolom error: token, header Bearer, query
   URL, dan nomor telepon disamarkan — dipaku uji dengan token palsu.
3. **Mengirim dari uji atau harness.** `Http::fake()` + `preventStrayRequests()` di
   PHP; transport `log` di harness. Tidak satu pesan pun keluar dari mesin ini selama
   pembangunan; pesan pertama yang benar-benar terkirim adalah bukti penerimaan Fase 3
   milik pemilik (ROADMAP-HASHMICRO §4).
4. **Webhook tanpa tanda tangan.** Tanpa `WHATSAPP_APP_SECRET`, endpoint POST menjawab
   403 untuk semua orang; tanda tangan yang hilang/salah 403 tanpa menyentuh satu
   baris pun; wamid yang tidak dikenal 200-dan-diabaikan (Meta mengulang webhook yang
   tidak 200). Webhook **tidak pernah membuat baris** dan hanya memperbarui baris yang
   `provider_id`-nya cocok.
5. **Nomor tanpa opt-in bertanggal.** Boolean telanjang ditolak sejak desain kolomnya.

## 8. Cara memeriksa klaim berkas ini

| Klaim | Cara memeriksa |
|---|---|
| Kanal ada tetapi tidak mengirim tanpa konfigurasi | `tests/Feature/Core/WhatsAppChannelTest.php` — `Http::preventStrayRequests()` aktif di setiap uji; uji "belum dikonfigurasi" berakhir `skipped` tanpa satu permintaan HTTP |
| Tidak ada `sent` tanpa pengenal penyedia | `tests/Feature/Core/DeliveryHonestyTest.php` — kanal yang memulangkan `null` berakhir `failed`, bukan `sent`; `MAIL_MAILER=log` berakhir `skipped` di tiga permukaan (kotak keluar, job, Kirim ulang) |
| Rahasia tidak bocor lewat kolom error | `WhatsAppChannelTest` — jawaban penyedia yang memuat token palsu `uji-token-RAHASIA-…` tidak pernah sampai ke `error` |
| Webhook hanya menyentuh baris yang cocok | `tests/Feature/Core/WhatsAppWebhookTest.php` |
| Tidak ada dependensi baru | `git diff main...feat/phase3-p3a -- composer.json composer.lock` kosong |
| Tidak ada rahasia di repo | `git grep -n "WHATSAPP_" -- .env.example config/` menunjukkan NAMA variabel saja |

## 9. Dokumen yang menyebut penolakan lama — apa yang diubah

ROADMAP-DEVIASI baris 18 diberi catatan kaki yang menunjuk ke berkas ini (kalimat
aslinya dipertahankan — ia sejarah); PANDUAN-ADMINISTRATOR dan PANDUAN-PENGGUNA
diperbarui pada kalimat "WhatsApp tidak ada" (sapuan CONVENTIONS §35); LAPORAN-DEVIASI-v2
tidak disunting (laporan bertanggal). SIKAP-E-SIGN §6 sudah menyebut pencabutan ini
sejak F-8.

---

## 10. Bank (P-3c, 12 Sep 2026): folder terpantau DIPILIH; SFTP TIDAK; host-to-host TETAP DITOLAK

Paket HM **P-3c** (ROADMAP-HASHMICRO Fase 3) membangun impor rekening koran dari
**folder terpantau** dan **tidak** membangun dua hal lain yang biasa disandingkan
dengannya. Paragraf ini ditulis SEBELUM satu baris kode P-3c pun, dengan bentuk yang
sama dengan §1–§9: apa yang ditolak, mengapa, apa yang dipilih, dan batasnya.

**Yang tetap DITOLAK — dan tetap tertulis di tempat lamanya.**

| Yang ditolak | Di mana penolakannya tertulis | Mengapa masih benar hari ini |
|---|---|---|
| **Bank host-to-host** (koneksi langsung ke API bank, saldo/mutasi ditarik aplikasi) | [`ROADMAP-DEVIASI.md`](ROADMAP-DEVIASI.md) §0 batas 5; [`ROADMAP-HASHMICRO.md`](ROADMAP-HASHMICRO.md) §6 kalimat terakhir; P-3c: "host-to-host tetap ditolak" | Menuntut perjanjian host-to-host per bank, kredensial bank hidup di server aplikasi, alamat IP terdaftar, dan audit keamanan yang tidak bisa dikirim di dalam rilis perangkat lunak. Kegagalannya diam: sebuah pemetaan yang salah pada aliran otomatis mengimpor rekening koran yang **seimbang dan salah**, setiap jam, tanpa pratinjau siapa pun. |
| **Klien SFTP/FTP di dalam aplikasi** (aplikasi mengunduh berkas dari server bank/pemilik) | P-3c: "SFTP tidak" — baris ini | Sama: kredensial jauh di server aplikasi, koneksi keluar terjadwal ke host yang tidak dikelola aplikasi, dan satu permukaan lagi yang bisa gagal diam-diam. Tidak ada dependensi Composer baru di paket ini, dan `ext-ssh2` tidak terpasang di produksi. |

**Yang DIPILIH: folder terpantau — dan aplikasi HANYA MEMBACANYA.**

- Satu folder di server (`BANK_INBOX_PATH` di `.env`; bawaan
  `storage/app/private/bank-inbox`, di bawah folder yang sudah dikecualikan
  `rsync --delete` oleh `deploy/sync-erp1.sh` dan ikut dicadangkan `deploy/backup-erp1.sh`
  bersama lampiran), dengan **sub-folder per KODE rekening bank** (mis. `BANK-BCA-OPS/`).
- Berkas sampai ke sana **dari luar aplikasi**: `scp`/`rclone`/salinan manual oleh
  pemilik atau administrator (runbook `docs/PANDUAN-ADMINISTRATOR.md` §5.13). Aplikasi
  **tidak pernah menulis, memindah, mengganti nama, atau menghapus** satu berkas pun di
  folder itu — prinsip docblock `BankStatementParseRequest` ("nothing in this application
  writes to disk") tetap berdiri; yang ditulis adalah **ledger** `fin_bank_inbox_files`
  di basis data (jalur relatif, sha256, status, sebab), sehingga pemeriksaan per jam
  idempoten dan pembersihan folder adalah pekerjaan pemilik.
- Pemeriksaan berjalan **tiap jam** lewat penjadwal P-0b (`fin:bank-inbox`), atau saat
  tombol *Periksa sekarang* ditekan di layar. Setiap berkas melewati **jalur impor yang
  sama** dengan layar Impor (`BankStatementImportService`: tie-out, rantai periode/saldo,
  identitas sha256) — tidak ada jalur kedua yang lebih longgar. Yang gagal menjadi baris
  ledger `failed` dengan kalimatnya **dan satu notifikasi** (sekali per berkas, bukan
  tiap jam); yang berhasil menjadi rekening koran `BST/…` dengan notifikasi ringkas.
- **CSV di folder hanya bisa diimpor bila rekeningnya punya preset yang memetakan kolom
  saldo**: tidak ada operator yang mengetik periode/saldo, jadi keduanya diturunkan dari
  kolom saldo berkas (saldo awal = saldo baris pertama − mutasi pertama, saldo akhir =
  saldo baris terakhir, periode = tanggal min/max) dan tie-out-nya adalah aritmetika
  berkas sendiri. Preset tanpa kolom saldo → `failed` "preset tanpa kolom saldo tidak
  bisa diimpor otomatis; impor lewat layar". MT940 tidak butuh preset.

**Batasnya — apa yang TIDAK otomatis, dan dikatakan di setiap permukaan.**

1. Berkasnya tidak datang sendiri dari bank. Tidak ada kalimat «otomatis dari bank»,
   «langsung dari bank», atau «terhubung ke bank» di layar, notifikasi, README, maupun
   panduan — dipaku uji `BankInboxTest` atas berkas paket ini utuh (layar, service,
   perintah, controller, registri, `docs/samples/bank/README.md`, ONBOARDING finance/admin)
   dan atas irisan PANDUAN-PENGGUNA §10.4, PANDUAN-ADMINISTRATOR §5.13, dan §10 ini.
2. Preset bawaan BCA/Mandiri/BNI/BRI **tidak ada** sampai pemilik meletakkan berkas
   ekspor nyata di `docs/samples/bank/` (README di sana daftar belanjanya); yang ada
   hari ini adalah preset **per rekening** yang disimpan operator dari pratinjau yang
   berhasil atas berkasnya sendiri.
3. Folder yang belum ada (keadaan bawaan setiap instalasi baru, termasuk produksi
   sesudah deploy) membuat perintahnya berkata begitu dan keluar 0 — tanpa galat, tanpa
   notifikasi, tanpa baris ledger.
4. Apakah penjadwalnya hidup **tidak diklaim layar ini**: sumbernya `GET core/health`
   dan spanduk dasbor P-0b. Layar hanya menampilkan "terakhir diperiksa" dari stempel
   yang benar-benar ditulis perintah.

## 11. API & webhook (P-3d, 12 Sep 2026): CORS KOSONG; alamat internal DITOLAK; token yang bisa mencetak token DITOLAK

Paket HM **P-3d** membuka API ini untuk sistem lain — token akses pribadi dan
webhook keluar. Membuka sesuatu adalah keputusan, dan tiga di antaranya
dituliskan di sini dengan bentuk yang sama dengan §1–§10: apa yang ditolak,
mengapa, apa yang dipilih, dan batasnya.

### 11.1 CORS tetap KOSONG

Ledger pemilik [`ROADMAP-HASHMICRO.md`](ROADMAP-HASHMICRO.md) §5 baris 10:
**"CORS / laju token integrasi → kosong / 300 per menit"**. Tidak satu pun
header `Access-Control-Allow-Origin` dikirim aplikasi ini, dan itu tetap
demikian sesudah P-3d.

Alasannya bukan kehati-hatian umum. Sebuah API yang membuka CORS bisa dipanggil
oleh JavaScript di halaman asal lain **dengan kredensial orang yang membuka
halaman itu**; token integrasi sebaliknya dipakai **server ke server**, tempat
ia tidak pernah terlihat pemakai dan tidak pernah ada peramban yang bisa
dibujuk. Sebuah integrasi yang menuntut CORS adalah integrasi yang menaruh token
di dalam JavaScript — yaitu menerbitkan tokennya.

Uji `OpenApiDriftTest::test_cors_is_actually_empty` memeriksa konfigurasi yang
benar-benar berjalan, bukan kalimat di dokumen ini.

### 11.2 URL webhook: alamat internal DITOLAK, redirect TIDAK DIIKUTI

Sebuah aplikasi yang mengirim POST bertanda tangan ke alamat apa pun yang
diketik pemakainya adalah **proxy permintaan ke dalam jaringannya sendiri**.

| Yang ditolak | Mengapa |
|---|---|
| `http://` apa pun | Muatan memuat nomor dan status dokumen; tanda tangan tidak melindungi isinya dari siapa pun yang membaca kabel |
| loopback (`127.0.0.0/8`, `::1`), privat (`10/8`, `172.16/12`, `192.168/16`, `fc00::/7`), link-local (`169.254/16`, `fe80::/10`), CGNAT (`100.64/10`), `0.0.0.0/8` | `169.254.169.254` memulangkan kredensial mesin di sebagian besar penyedia awan; `127.0.0.1:9200` adalah layanan tetangga di server yang sama |
| nama berakhiran `.local`, `.internal`, `.localhost`, `.home.arpa`, dan `localhost` telanjang | Nama yang tidak pernah keluar dari jaringan sendiri |
| URL yang membawa nama pengguna/kata sandi | Rahasianya adalah tanda tangan, bukan URL-nya |
| **Redirect** (`3xx`) | Sebuah penerima yang menjawab `302 Location: http://169.254.169.254/` memindahkan kiriman bertanda tangan kita ke sana tanpa satu pun baris di atas berlaku lagi |
| **Bentuk samaran dari alamat yang sama** — `[::ffff:127.0.0.1]`, `[::ffff:169.254.169.254]`, `[::10.0.0.1]`, NAT64 `[64:ff9b::7f00:1]`, dan bentuk numerik `2130706433` / `0177.0.0.1` / `127.1` | Sebuah alamat ditulis dengan lebih dari satu cara dan mendarat di soket yang SAMA. `FILTER_FLAG_NO_PRIV_RANGE\|NO_RES_RANGE` milik PHP TIDAK menutup `::ffff:0:0/96`, dan `filter_var` tidak mengenali bentuk numerik sebagai IP sama sekali sehingga host-nya diperlakukan sebagai NAMA. Maka alamatnya dinormalkan lebih dulu, lalu dinilai (putaran verifikasi V-webhook-2) |

**Diperiksa DUA KALI: saat menyimpan DAN saat mengirim.** DNS bisa berubah di
antara keduanya — sebuah nama yang hari ini menunjuk ke alamat publik bisa besok
menunjuk ke `127.0.0.1`, dan itu bukan serangan teoretis melainkan teknik dengan
nama sendiri (DNS rebinding). Waktu tunggu dibatasi (5 s koneksi, 10 s total)
supaya penerima yang menggantung tidak menahan pekerja antrean.

**Yang TIDAK dilakukan:** aplikasi ini tidak memelihara daftar-putih host, dan
tidak menawarkan "izinkan alamat internal untuk instalasi di dalam kantor".
Sebuah sakelar seperti itu akan dinyalakan satu kali untuk satu kebutuhan yang
masuk akal dan tetap menyala selamanya.

### 11.3 Sebuah token tidak boleh mencetak token

Ability token adalah **subset izin pemiliknya**, dan ditegakkan di satu tempat
yang dilewati setiap pemeriksaan izin. Tiga pintu layanan mandiri tetap menuntut
**sesi** (masuk lewat halaman masuk), karena ability tidak bisa mempersempit
sesuatu yang memang tidak dijaga izin:

- `POST/DELETE iam/me/api-tokens` — kalau tidak, token "hanya baca keuangan"
  mencetak token kedua dengan SELURUH izin pemiliknya, dan setiap pembatasan
  yang dibangun paket ini berumur satu permintaan;
- `PUT iam/me/password` — kunci akun berpindah tangan;
- `PUT iam/me/phone` — nomor WhatsApp dan persetujuan bertanggalnya; alarm
  operasional perusahaan diarahkan ke nomor lain.

### 11.4 Batasnya — apa yang ability TIDAK batasi, dikatakan di setiap permukaan

Ability menyempitkan **gerbang izin**, dan tidak menciptakan gerbang di tempat
aplikasi ini sendiri tidak menggerbangi apa pun. Diukur 12 Sep 2026: **862** rute
di bawah `/api`, **644** dijaga sebuah izin, **218** hanya menuntut autentikasi.
Endpoint di golongan kedua dijangkau token terbatas seseorang persis seperti sesi
peramban orang itu, dan kalimat itu ditulis di layar Token API, di
PANDUAN-PENGGUNA §20, dan di `docs/api/openapi.json`. Angka di atas **diukur**
dengan `Route::getRoutes()`, bukan dipaku; yang dipaku `UngatedApiRouteCensusTest`
adalah daftar **31** rute TULIS tanpa gerbang izin, supaya
yang ke-32 memerahkan gerbang alih-alih diam-diam memperlebar apa yang bisa
dilakukan sebuah token "hanya baca".
