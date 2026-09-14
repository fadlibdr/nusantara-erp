# Deviasi: ROADMAP-HASHMICRO.md vs keadaan nyata

**Tanggal: 14 September 2026, direvisi 14 September 2026 malam.** Basis: `main` di `4301df9`
(= `origin/main`), produksi `erp1.pi2.co.id` disinkronkan 14 Sep 2026 sesudah F-5 dan perbaikan
gerbang alamat. Rencana yang dibandingkan: `docs/ROADMAP-HASHMICRO.md`, disetujui pemilik 5 Sep 2026.

> **Apa yang berubah sejak revisi pertama** (basis waktu itu `b05f1d5`): **F-5 dibangun, digabung dan
> di-deploy** — penundaan satu-satunya di seluruh rencana dicabut, jadi Fase 2 kini 9/9 dan seluruh
> 25 paket terbangun (§2, §3.7). Dua butir gerbang alamat yang audit keamanan tinggalkan **ditutup**,
> dan putaran penutupnya menemukan tujuh bentuk IPv6 lagi (§2 "Di luar rencana"). Migrasi 001094
> mendarat di produksi (batch 59) dan `SHELL_VERSION` 15 tayang — **diverifikasi lewat PDO**, bukan
> diasumsikan. **Tidak satu pun dari sepuluh prasyarat pemilik di §4 berubah**, dan kedua deviasi
> terbesar (§3.1, §3.2) berdiri persis seperti sebelumnya.

Dokumen ini membandingkan APA YANG DIRENCANAKAN dengan APA YANG ADA. Ia bukan laporan kemajuan:
paket yang selesai disebut satu baris, dan yang mendapat tempat adalah **selisihnya**.

## 0. Ringkasan dalam satu paragraf

Rencana memuat **25 paket** (Fase 0: 2 · Fase 1: 9 · Fase 2: 9 · Fase 3: 5). **Kedua puluh limanya
kini dibangun, digabung, dan di-deploy** — F-5, satu-satunya yang ditunda, dicabut penundaannya 14
Sep (§3.7). Tetapi dua hal membuat "25 dari 25" menyesatkan bila dibaca sendirian. **Pertama: prasyarat teknis Fase 0 — pindah ke MySQL — tidak pernah dijalankan di
produksi.** Rencana menyebutnya "prasyarat teknis yang harus mendahului apa pun yang menambah beban
baca/tulis"; Fase 1, 2 dan 3 tetap dibangun dan dikirim di atas SQLite, dan produksi hari ini masih
`DB_CONNECTION=sqlite` (diukur). **Kedua: setiap kanal luar Fase 3 hidup di kode dan mati di
produksi** — SMTP, WhatsApp dan web push semuanya menunggu nilai yang hanya pemilik bisa isi, dan
sampai itu terjadi setiap barisnya `skipped` dengan sebabnya. Yang dibangun bekerja; yang belum
terjadi adalah lima keputusan dan satu jendela pemeliharaan yang bukan milik siapa pun kecuali
pemilik.

## 1. Metode dan sumber angka

| Bagaimana sebuah baris di bawah bisa dipercaya | |
|---|---|
| **Diukur hari ini** | perintah dijalankan 14 Sep 2026 pada pohon `main` `4301df9` dan/atau pada `/var/www/erp1.pi2.co.id` — ditandai *(diukur)* |
| **Dari gerbang rilis** | angka uji dua driver yang tercatat di pesan commit gabungan paket itu |
| **Dari laporan paket** | `docs/LAPORAN-PAKET-HM-<id>.md`, yang masing-masing memuat bagian "Yang TIDAK dikerjakan" |

Kelas deviasi yang dipakai tabel §2:

* **SESUAI** — dikirim seperti yang tertulis.
* **LEBIH** — dikirim melebihi yang tertulis.
* **BENTUK BERBEDA** — tujuan sama, wujud berbeda; sebabnya tertulis di laporan paket.
* **SEBAGIAN** — separuh dikirim, separuh menunggu pihak luar; batasnya dinyatakan di layar.
* **DITUNDA** — sengaja tidak dikerjakan, sebab ditulis sebelum, bukan sesudah.
* **BELUM DIPAKAI** — dibangun, diuji dan dikirim, tetapi tidak dipakai di produksi.

## 2. Paket per paket

### Fase 0 — Platform (rencana 20 h-o)

| Paket | Rencana | Nyata | Kelas |
|---|---|---|---|
| **P-0** MySQL & cadangan | T0.1–T0.7; cut-over dengan runbook; produksi berjalan di MySQL 8 | T0.1–T0.6 dibangun, digabung, di-deploy. `erp:mysql-preflight`, indeks unik parsial → `live_key`, CI MySQL, harness burst, `erp:sqlite-to-mysql` + `erp:migration-verify`, cadangan `mysqldump` + drill restore — semuanya ada dan hijau. **Cut-over TIDAK dijalankan.** Produksi `DB_CONNECTION=sqlite` *(diukur)*; basis data MySQL yang ada di mesin hanya `erp_dryrun`, `erp_gapgate`, `erp_restore_check`, `erp_scratch`, `erp_test`, `erp_vb` — tidak ada basis data produksi *(diukur)* | **BELUM DIPAKAI** |
| **P-0b** Antrean & penjadwal | unit systemd, heartbeat + watchdog, `core/health`, kotak keluar + `DeliverNotification`, layar antrean gagal | Semuanya ada. Unit `erp1-queue` dan `erp1-scheduler` **aktif** di produksi sejak 12 Sep 2026 *(diukur: keduanya `active`)*; baris cron lama dihapus lebih dulu, watchdog dipasang sesudah heartbeat pertama | **SESUAI** |

### Fase 1 — Tampilan & BI (rencana 33 h-o, 9 paket)

Sembilan paket A–I digabung dan di-deploy 5–7 Sep 2026; gerbang rilis fase 4.025 uji hijau di kedua
driver. Deviasi yang tercatat:

| Paket | Deviasi | Kelas |
|---|---|---|
| **P1-A** vendor + `charts.js` | — | SESUAI |
| **P1-B** penyegaran visual | — | SESUAI |
| **P1-C** preferensi + launcher + beranda modul | — | SESUAI |
| **P1-D** dasbor yang bisa diatur | rencana **18 widget**, dikirim **19** | LEBIH |
| **P1-E** migrasi tiga grafik | — | SESUAI |
| **P1-F** report builder v1 | 8 resource seperti rencana; **tetapi peran teknisi melihat 0 sumber dan gudang 1** padahal keduanya melihat menunya (LAPORAN-RILIS-FASE-1 §keputusan) | SEBAGIAN |
| **P1-G** kanban | — | SESUAI |
| **P1-H** gantt baca-saja | — | SESUAI |
| **P1-I** PWA | push sengaja ditunda ke Fase 3 seperti tertulis | SESUAI |

**Metrik Fase 1 yang meleset:** target "dasbor direktur ≤ 10 permintaan (dari 11)" — hasilnya
**12**, naik bukan turun, dan itu dicatat sebagai keputusan rancangan yang menunggu pemilik, bukan
disembunyikan.

### Fase 2 — Fitur modul (rencana 42 h-o)

| Paket | Nyata | Kelas |
|---|---|---|
| **F-1** matriks persetujuan + delegasi + setujui massal | dikirim; dua cacat uang ditemukan verifikasi (jurnal ganda pada mode "tambahan tingkat"; ambang direktur distempel tapi tidak ditegakkan) | SESUAI |
| **F-2** anggaran vs realisasi + overhead + revisi RAP | dikirim; menetapkan blok migrasi lanjutan Finance/Projects di CONVENTIONS §2 | SESUAI |
| **F-3** CRM aktivitas + pipeline | dikirim | SESUAI |
| **F-4** absensi GPS + selfie | dikirim | SESUAI |
| **F-6** reorder + barcode | dikirim; menetapkan blok lanjutan Inventory 001700–001799 yang **belum ada di ledger pemilik** | SESUAI |
| **F-7** servis alat per hour-meter | dikirim | SESUAI |
| **F-8** kedaluwarsa lampiran; sikap e-sign | dikirim; e-sign tetap DITOLAK tertulis seperti rencana | SESUAI |
| **F-9** CSAT tiket | dikirim | SESUAI |
| **F-5** timesheet & lembur | **dibangun, digabung `9ea59c4`, di-deploy 14 Sep.** Penundaannya dicabut dengan cara yang menghapus sebabnya: pemilik MENYEBUTKAN aturan pembulatannya sendiri, jadi tidak ada yang dikarang (§3.7). Ia juga memperbaiki kekurangan yang payroll akui sendiri sejak P0: 1,5x rata atas total bulanan menjadi 1,5x/2x per hari | **SESUAI** |

### Fase 3 — Integrasi & kepatuhan (rencana 35 h-o)

| Paket | Deviasi | Kelas |
|---|---|---|
| **P-3a** kanal notifikasi | Dibangun penuh: SMTP, WhatsApp Meta Cloud API, preferensi kanal, jam tenang, ulang-kirim. **Di produksi `MAIL_MAILER=log` dan `WHATSAPP_TOKEN` kosong** *(diukur)* — jadi setiap baris `skipped` dengan sebabnya. Qontak dikenali tetapi pengirimnya tidak ditulis | SEBAGIAN |
| **P-3b** kepatuhan DJP | Dikirim: registri `DjpFormats`, NPWP 15/16/22 digit di delapan pintu, rekap PPh 21/26 dari snapshot slip. **TIDAK dikirim: `format=coretax_xml`, verifikasi kolom e-Bupot unifikasi, ekspor SIPP BPJS** — ketiganya menunggu template resmi. `docs/samples/pajak/` berisi **hanya README** *(diukur)*; kelima format berlabel "BELUM DIVERIFIKASI" di layar | SEBAGIAN |
| **P-3c** bank | Preset disimpan **per REKENING**, rencana menulis "per bank". Preset bawaan BCA/Mandiri/BNI/BRI **tidak ada**: `docs/samples/bank/` berisi hanya README *(diukur)*. Folder terpantau `storage/app/private/bank-inbox` **belum dibuat di produksi** *(diukur)* — pemindaian per jam berjalan, mengatakan foldernya tidak ada, dan tidak pernah membuatnya | BENTUK BERBEDA + SEBAGIAN |
| **P-3d** API & webhook | Dikirim penuh. Angka rencana "793 rute" basi: **865 rute di bawah `api/`** hari ini *(diukur)*; 20 endpoint didokumentasikan tangan seperti rencana. Batas jujur yang diumumkan: 218 dari 862 rute tidak bergerbang izin dan tetap terjangkau token sempit | SESUAI |
| **P-3e** PWA push | Dipenuhi harfiah: Web Push standar, tanpa SDK Firebase, tanpa aplikasi native. **`VAPID_*` kosong di produksi** *(diukur)* — tombolnya ada, kalimatnya jujur, dan tidak satu pemberitahuan pun bisa berangkat | SEBAGIAN |

### Di luar rencana — pekerjaan yang tidak ada di roadmap

| Pekerjaan | Sebab |
|---|---|
| Kenaikan `guzzlehttp/guzzle` 7.15.2, `league/commonmark` 2.10.1, `maatwebsite/excel` 3.1.70 | `composer audit` melaporkan 13 nasihat keamanan; sekarang **nol** |
| **Dua butir §11.2 terakhir ditutup** (`4301df9`): byte kendali di otoritas, dan multicast IPv6 `ff00::/8` | Ditinggalkan audit keamanan sebagai "BELUM ditutup, dan diketahui". Keberatan "itu pengurai kedua" dijawab dengan 32 bentuk URL yang dijalankan sebelum satu baris produksi ditulis |
| **Tujuh bentuk IPv6 lagi**, ditemukan putaran penutup butir di atas | Paket itu menulis "tidak ada rentang IPv6 lain" dan klaimnya SALAH: `fec0::1`, `2001:2::1`, dua bentuk 6to4 yang membungkus `127.0.0.1` dan `10.0.0.5`, NAT64 local-use, Teredo, discard-only — semuanya tersimpan di kedua pintu |
| Empat perbaikan gerbang alamat keluar (SSRF) | ditemukan saat menaikkan pustaka dan saat menggabungkan: bentuk samaran titik ekor, kelas kedua yang tidak ikut tertambal, gerbang vs transport yang berselisih, rentang IANA yang PHP anggap publik |

## 3. Deviasi yang penting — enam, dengan akibatnya

### 3.1 Fase 0 dibangun untuk sebuah cut-over yang tidak terjadi

Rencana menyusun seluruh urutan fase di sekitar satu kalimat: MySQL adalah prasyarat teknis yang
harus mendahului apa pun yang menambah beban baca/tulis, karena `lockForUpdate()` dipanggil di 141
titik dan SQLite mengompilasinya menjadi string kosong. Lalu Fase 1 menambahkan dasbor 12
permintaan, Fase 2 menambahkan delapan modul fitur, dan Fase 3 menambahkan antrean pengiriman —
**semuanya di atas SQLite**.

Yang membuat ini bisa ditanggung: alat cut-over ADA dan terbukti (preflight, migration-verify, drill
restore, harness burst), dan beban nyata erp1 hari ini kecil. Yang tidak berubah: penomoran dokumen,
jurnal, stok dan laporan lapangan masih aman hanya karena satu kunci tulis global SQLite, dan kunci
itu pula yang memberi 503 pada ~40 permintaan bersamaan. **Ini deviasi terbesar dalam dokumen ini,
dan ia satu-satunya yang bisa ditutup dalam satu jendela pemeliharaan** — runbook `DEPLOYMENT.md`
§10.9, rollback = `.env` kembali ke SQLite, jendela 24 jam.

### 3.2 Tiga kanal luar yang hidup di kode dan mati di produksi

SMTP, WhatsApp dan web push semuanya dibangun, diuji, dan dikirim. Di produksi ketiganya menunggu
nilai `.env` yang hanya pemilik bisa isi. Perilaku hari ini **benar dan disengaja**: setiap
pengiriman tercatat `Dilewati` dengan kalimat yang menyebut apa yang kurang, bukan `Terkirim` yang
bohong — itulah cacat P-0b yang memicu seluruh rancangan kotak keluar. Tetapi artinya juga: **bagian
"Fase 3" dari rencana verifikasi belum bisa dijalankan sama sekali** ("pemilik menerima satu e-mail,
satu WhatsApp, satu push nyata dari erp1").

### 3.3 DJP berhenti persis di garis yang digambar sendiri

Rencana menulis prinsipnya lebih dulu: **tidak mengarang tata letak berkas DJP**. Prinsip itu
ditepati, dan harganya terlihat — tiga dari lima isi P-3b tidak dikirim karena `docs/samples/pajak/`
masih kosong. Yang ada di layar adalah registri yang mengatakan "BELUM DIVERIFIKASI" pada kelima
format, bukan tombol ekspor yang menghasilkan berkas yang akan ditolak Coretax. Ini deviasi yang
BENAR; ia dicatat di sini supaya tidak terbaca sebagai kelalaian.

### 3.4 Preset bank per rekening, bukan per bank

Rencana menulis "preset per bank"; yang dibangun adalah satu preset JSON per **rekening bank**,
disimpan hanya dari pratinjau yang cocok dan dipakai hanya dengan `use_preset`. Sebabnya tertulis di
laporan: satu perusahaan bisa memegang dua rekening di bank yang sama dengan format ekspor berbeda
(kantor cabang, jenis produk), dan tabel "banyak preset per bank" menuntut pilihan yang tidak punya
jawaban benar saat impor. Akibat yang harus diketahui pemilik: **tidak ada preset bawaan sama
sekali** sampai berkas ekspor nyata diletakkan, jadi rekening pertama tiap bank tetap butuh satu
pratinjau manual.

### 3.5 Ledger keputusan #5 keliru, dan kekeliruannya tidak menghasilkan galat

Ledger pemilik menulis blok migrasi lanjutan Core sebagai "001400–?". Rentang itu **milik Quality**
di CONVENTIONS §2, dan sudah berisi enam migrasi. Paket berikutnya yang mempercayai ledger akan
mengambil nomor yang bertabrakan — dan nomor yang bertabrakan tidak menghasilkan galat, ia hanya
menghapus batas yang §2 lahir untuk menjaga. Core memakai **001800–001899**, ditetapkan di tabel §2
pada commit pemakaian pertamanya. **Ledger di roadmap masih belum diperbarui pemilik**, begitu pula
baris Inventory 001700–001799 yang ditetapkan F-6.

### 3.6 Angka di roadmap yang sudah basi

| Ditulis 5 Sep | Diukur 14 Sep |
|---|---|
| 776 rute API | **865 di bawah `api/`** (880 total) |
| 3.768 uji | **5.366** (gerbang rilis dua driver di `d83f2ef`) |
| ~35.000 baris JS | **49.087** |
| 1.503 baris CSS | **2.374** |
| "793 rute tak terkurasi" (teks P-3d) | 862 saat paket itu, 865 hari ini |

Modul tetap **14**; migrasi berjumlah **259** (batch 59 di produksi). Angka-angka ini bukan deviasi — ia ukuran pertumbuhan,
dan dicatat supaya rencana berikutnya tidak dihitung dari basis yang salah.

### 3.7 Penundaan satu-satunya dicabut — dan sebabnya layak dicatat

F-5 ditunda 5 Sep dengan sebab yang ditulis LEBIH DULU: "tanpa ≥ 1 bulan data F-4 aturan pembulatan
lembur dikarang". Prasyarat itu **diukur sebelum membangun, dan ia tidak terpenuhi** — produksi
memegang NOL baris `hr_attendances` enam hari sesudah F-4 di-deploy, NOL izin lembur ILB, dan 8
baris rekap benih bertanggal 26 Juli. Menunggu sampai 8 Oktober tidak akan mengubahnya kalau tidak
ada yang mengabsen.

Yang mencabut penundaan bukan waktu melainkan **pemilik menyebutkan aturannya sendiri** (15 menit,
minimum 30, toleransi 10, hari 8 jam, Kepmenaker 1,5x/2x). Dengan itu tidak ada satu aturan pun yang
dikarang, dan aturannya dikirim sebagai SETELAN — kebijakan yang berbeda adalah satu suntingan,
bukan rilis.

**Pelajarannya, dan ia berlaku untuk setiap paket yang ditunda:** periksa prasyaratnya dengan
MENGUKUR, bukan dengan membaca tanggal.

F-5 juga menutup kekurangan yang payroll **akui sendiri** sejak P0 — komentarnya berbunyi "the
per-day 1.5x/2x split needs daily attendance detail (future enhancement)". Dan putaran verifikasinya
menangkap cacat uang terbesar kampanye ini: istirahat tidak dipotong, sehingga hari kerja 08:00–17:00
menghasilkan **satu jam lembur palsu setiap hari untuk setiap orang** — Rp 2.479.768 pada upah
sebulan Rp 11 juta, yaitu 22,5 % upahnya, untuk lembur yang tidak ada. Ia gagal DIAM-DIAM: tidak ada
bendera, batas 3 jam/hari tidak tersentuh, dan angkanya persis sebesar yang orang percaya masuk akal.

**Satu hal yang harus diketahui pemilik:** F-5 dikirim ke register yang KOSONG. Layarnya benar dalam
keadaan itu — ia mengatakan registernya belum berisi alih-alih menggambar tabel nol — tetapi paket
ini tidak akan menghasilkan satu angka pun sampai ada yang benar-benar mengabsen (§4 baris 11).

## 4. Prasyarat pemilik yang belum terpenuhi

Semuanya di luar kode, dan masing-masing memblokir sesuatu yang sudah dibangun dan dibayar.

| # | Yang ditunggu | Memblokir | Bukti hari ini |
|---|---|---|---|
| 1 | Jendela cut-over MySQL | seluruh alasan Fase 0 | `DB_CONNECTION=sqlite` *(diukur)* |
| 2 | Kotak surat SMTP di `.env` | kanal e-mail P-3a | `MAIL_MAILER=log` *(diukur)* |
| 3 | Akun WABA + 5 template disetujui Meta + anggaran | kanal WhatsApp P-3a | `WHATSAPP_TOKEN` tidak ada *(diukur)* |
| 4 | `php artisan core:vapid-keys` → `VAPID_*` di `.env`, lalu sakelar Pengaturan | seluruh P-3e | 0 kunci terisi *(diukur)* |
| 5 | Template resmi DJP ke `docs/samples/pajak/` + satu masa di sandbox Coretax | separuh P-3b | hanya README *(diukur)* |
| 6 | Berkas ekspor bank nyata ke `docs/samples/bank/` | preset bawaan P-3c | hanya README *(diukur)* |
| 7 | `mkdir storage/app/private/bank-inbox` di erp1 | folder terpantau P-3c | folder tidak ada *(diukur)* |
| 8 | Pengesahan ledger #5: Core 001800–001899, Inventory 001700–001799 | kebersihan blok migrasi berikutnya | roadmap §5 belum diubah |
| 9 | Lima keputusan rancangan Fase 1 (termasuk dasbor 12 permintaan) | metrik Fase 1 | LAPORAN-RILIS-FASE-1 §keputusan |
| 10 | Kebijakan pemangkasan `core_notification_deliveries` | pertumbuhan tabel; P-3e mengalikannya per perangkat | terbuka sejak P-3a |
| 11 | **Seseorang benar-benar mengabsen lewat layar F-4** | seluruh F-5: timesheet, lembur turunan, usulan rekap | `hr_attendances` **0 baris**, enam hari sesudah F-4 di-deploy *(diukur)* |

## 5. Status ledger keputusan pemilik (§5 roadmap)

| # | Keputusan | Status |
|---|---|---|
| 1 | Platform front-end | **DIPAKAI** — (1) vanilla; tidak ada framework/build yang masuk |
| 2 | Mesin grafik / pustaka vendor | **DIPAKAI** — SVG tangan + SortableJS + sprite Lucide |
| 3 | Landing setelah masuk | **DIPAKAI** |
| 4 | Report builder 8 resource / berbagi per peran | **DIPAKAI**, dengan catatan §2 (teknisi 0 sumber) |
| 5 | Blok migrasi lanjutan | **KELIRU DAN DIKOREKSI DI KODE, BELUM DI LEDGER** — §3.5 |
| 6 | Jendela cut-over / retensi arsip | **BELUM DIJAWAB** — §3.1 |
| 7 | Penyedia WhatsApp & anggaran | **BELUM DIJAWAB**; kode memakai rekomendasi (`meta`) |
| 8 | SMTP | **BELUM DIJAWAB**; kode membaca `MAIL_*` |
| 9 | Konsultan pajak; `phpspreadsheet` | **BELUM DIJAWAB**; tidak ada dependensi ditambahkan |
| 10 | CORS / laju token integrasi | **DIPAKAI** — CORS kosong, 300/menit per token |
| 11 | `minishlink/web-push` | **DIPAKAI** — v10.1.0 terpasang |
| 12 | OQ-4 / OQ-5 (F-1) | **DIPAKAI** — layar dikirim dengan nilai hari ini |
| 13 | Fase bulanan = baseline; ambang 90 % | **DIPAKAI** |
| 14 | Geofence 500 m, dicatat bukan ditolak | **DIPAKAI** |
| 15 | Code 128 tanpa dependensi | **DIPAKAI** |
| 16 | `queue:retry` no-op jujur | **DIPAKAI** — perilaku hari ini |

Sepuluh dari enam belas dipakai apa adanya. Lima belum dijawab dan semuanya menunggu pihak luar.
Satu (#5) salah dan sudah dikoreksi di kode tanpa menunggu.

## 6. Yang tetap di luar cakupan

Tidak berubah sejak 5 Sep, dan tidak satu pun tergelincir masuk: multi-perusahaan / multi-tenant /
multi-currency, portal pelanggan, host-to-host bank, SFTP, aplikasi native, OCR nota, PSrE e-sign
berbayar, dan pembangkit OpenAPI otomatis. Satu penolakan **dicabut dengan syarat** seperti
direncanakan: WhatsApp.

## 7. Apa yang menutup deviasi terbesar

Urut menurut nilai per usaha:

1. **Jendela cut-over MySQL.** Alatnya siap dan terbukti; yang kurang adalah satu Sabtu pagi dan
   keputusan retensi arsip. Ini menutup §3.1 seluruhnya.
2. **Empat baris `.env` dan satu perintah** (`core:vapid-keys`, `VAPID_*`, `MAIL_*`, `WHATSAPP_*`)
   plus dua sakelar Pengaturan. Ini menghidupkan tiga kanal yang sudah dibayar dan membuat bagian
   "Fase 3" dari rencana verifikasi bisa dijalankan untuk pertama kalinya.
3. **Dua folder berisi berkas** (`docs/samples/pajak/`, `docs/samples/bank/`) plus satu `mkdir` di
   erp1. Ini membuka separuh P-3b dan preset bawaan P-3c.
4. **Satu suntingan ledger** (#5) supaya paket berikutnya tidak membaca rentang yang bertabrakan.
5. ~~**F-5** sesudah 8 Okt 2026~~ — **SELESAI 14 Sep 2026**, tanpa menunggu, karena yang kurang
   ternyata sebuah keputusan dan bukan sebulan data (§3.7). Yang menggantikannya di daftar ini:
   **seseorang mengabsen lewat layar F-4**, karena tanpa itu F-5 tidak menghasilkan satu angka pun.

Tidak satu pun dari lima butir di atas menuntut kode baru.
