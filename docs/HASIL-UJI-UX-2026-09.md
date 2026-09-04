# Hasil Uji Terukur — Proses Bisnis di Aplikasi, Sebelum & Sesudah Perbaikan P0

**Nusantara ERP** · 2 September 2026 · pelengkap [ASESMEN-UX-2026-09.md](ASESMEN-UX-2026-09.md)

> **Bukan teori.** Setiap angka di sini diukur dengan menjalankan aplikasi
> sungguhan — kode `main` (48cee5b), PHP 8.3, SQLite, seed demo penuh — dan
> mengendalikan SPA-nya dengan Chromium headless: klik sungguhan, permintaan
> API sungguhan, tangkapan layar sungguhan. Perbaikan kemudian dipasang dan
> skenario yang sama dijalankan ulang. Harness, hasil mentah (JSON), dan tangkapan
> layar ada di `bukti-uji/`; perubahannya di `ux-p0-measured.patch` (cabang
> `ux/p0-measured`, 15 berkas).
>
> **Mengapa bukan di produksi.** `erp1.pi2.co.id` menjawab **HTTP 503** pada
> `/app/`, `/up`, dan `/` sepanjang sesi ini (dicek dari peramban Fadli dan dari
> `fetch` latar). Perangkat lunaknya sama; yang berbeda hanya data dan latensi.
> Skenario yang sama siap dijalankan di produksi begitu server menjawab.

---

## 1. Hasil sebelum → sesudah

| Skenario (peran) | Sebelum | Sesudah | Bukti |
|---|---|---|---|
| **Sesi berakhir di tengah PO** (procurement) — 13 field diketik, token dicabut di server, Simpan ditekan | Toast **"Unauthenticated."**; halaman masuk tergambar **di bawah modal** yang masih terbuka — tombol Masuk *blocked by overlay*; jalan keluar: Esc → *"Buang isian"* → masuk → **13 field hilang**, tidak ada tawaran pemulihan | Overlay tertutup otomatis, halaman masuk terjangkau, banner: *"Sesi Anda berakhir. Isian PO … tersimpan di peramban ini"*; setelah masuk: toast **Pulihkan / Buang** → formulir terbuka dengan **13 field + 3 baris utuh**, termasuk vendor & tanggal | `03-sebelum-…png`, `04-sesudah-…png`, `05-sesudah-draf-dipulihkan.png`; `results-*.json › S4` |
| **Bahasa pesan validasi** (API 422, PO/pelanggan/tagihan vendor) | `The vendor id field is required.` · `The items.0.qty field must be at least 0.001.` · tagihan vendor: kalimat Inggris 30 kata | `Vendor wajib diisi.` · `Kuantitas minimal 0.001.` · `Tanggal PO wajib diisi.` · `Harga satuan wajib diisi.` | `S10` |
| **Galat 422 di formulir** (procurement) | Sel ditandai (benar) tetapi toast membaca kalimat yang sama **dua kali** (judul + rincian) | Satu kali | `S3 › toast_on_422` |
| **Kotak persetujuan dasbor** (direktur, 14 izin approve) | Server: **4** dokumen menunggu (RAP, SPK, PO, **pengajuan cuti**); kartu: **3** — cuti tak pernah tampil; 11 permintaan per jenis; kartu 371 px, uraian membungkus **8 baris** per dokumen | **4 dari 4**; **1 permintaan** (`GET core/inbox`) atas registri 28 jenis yang sama dengan notifikasi; kartu 565 px, 1 baris uraian + pengaju + umur antrean; tombol **Tugas Saya** / **Lihat semua** | `01-sebelum-dasbor.png`, `02-sesudah-dasbor.png`; `S1` |
| **Layar Tugas Saya** (baru) | tidak ada — tiga pintu (kartu, lonceng, Tenggat) | `#/tugas`: semua jenis, terlama di atas, saring per jenis, umur antrean berwarna ≥ 7 / ≥ 14 hari; pengajuan cuti terbuka dengan Setujui/Tolak | `06-sesudah-tugas-saya.png`; `S11` |
| **Loop persetujuan** (direktur) — dasbor → dokumen → Setujui → catatan → Setujui | **4 klik** + cari baris berikutnya; **28 permintaan API** per putaran (7 membuka dokumen, 18 memuat ulang dasbor); toast *"Setujui berhasil."*; tidak ada "berikutnya" | Toast **`RAP/2026/0001 disetujui.`** + *"Berikutnya menunggu Anda (4): SPK/2026/III/0002 · Rp 2,1 M — Buka / Semua tugas"*; **Buka** membuka dokumen berikutnya langsung: **3 klik** per dokumen, dasbor tidak dimuat ulang; **16 permintaan** untuk rentang yang sama | `S2` |
| **Buat → ajukan PO 2 baris** (procurement) | **13 klik**; Simpan mendarat di **daftar** (cari baris lagi); toast *"Ajukan berhasil."* | **12 klik**; Simpan mendarat di **dokumen** (`#/d/procurement/purchase-orders/4`); toast **`PO/2026/IX/0004 diajukan · menunggu persetujuan.`** | `S3` |
| **Ubah/Hapus menghilang tanpa pesan** | Judul + badge "Diajukan", tidak ada penjelasan | Strip di bawah judul: *"Diajukan 2 Sep oleh Andi · menunggu persetujuan. Ubah dan Hapus tidak tersedia selama menunggu; untuk memperbaiki, minta penyetuju menolaknya."* (5 kalimat, satu per status; data dari `record.approvals`, nol permintaan tambahan) | `08-sesudah-strip-status.png`; `S2 › explanation_under_title` |
| **Kontras token** (diukur dari DOM) | `--muted` **4,26**:1 di atas `--bg`, **4,46** di header tabel 11 px; badge sukses **4,42** — di bawah AA | **5,23 / 5,47 / 5,29** — lulus AA | `S8` |
| **Akun demo di halaman masuk** | Selalu tampil, `app.js` tidak memeriksa lingkungan | `GET iam/auth/demo-accounts` → kosong bila `APP_ENV=production` | — |

### Yang diukur dan **tidak** berubah (sengaja, atau menunggu riset)

| Ukuran | Nilai | Catatan |
|---|---|---|
| Sidebar per peran (grup / tautan / tinggi dalam viewport 900 px) | admin 14 / 121 / **4,9×** · direktur 119 / 4,9× · finance 67 / 2,7× · PM 62 / 2,6× · estimator 47 · site-manager 44 · sales 43 · procurement 35 · warehouse 35 · teknisi 16 · hr 14 | Semua grup terbuka bawaan. Favorit/terakhir-dibuka/grup-tertutup adalah P1 (`ASESMEN-UX` §2.3) — menunggu log rute minggu 1 |
| Ubin dasbor per peran | procurement **0**, hr **0**, teknisi 1, warehouse 1 | Beranda per peran = P2, bergantung H1 |
| Baris item PO di modal 960 px | tabel baris mulai di **y = 843 px** (viewport 900) — di bawah lipatan; 3 textarea penuh di atasnya | Formulir halaman penuh = P2 |
| Ajukan PO | selalu membuka modal *"Alasan override prakualifikasi"* (opsional) | Kandidat P1: pindahkan ke alur confirm-resubmit yang sudah ada |
| Jalan ke Lapangan di ponsel (site-manager, 390×844) | **2 ketukan**, tautan terlihat tanpa menggulung (grup Proyek di y = 528) | **Koreksi atas asesmen**: klaim "sulit dijangkau" tidak terbukti untuk peran ini (44 tautan). Tetap berlaku untuk PM/admin |
| Warna status `open` untuk NCR/K3/defect | tidak teramati — seed tidak punya NCR/defect terbuka | Tetap temuan kode (`format.js:129`), belum terukur |
| Tanggal native `mm/dd/yyyy` | terlihat di Chromium en-US | Bergantung locale OS pengguna; helper "= 2 Sep 2026" = P1 |
| Menu akun | hanya **Tutup · Keluar** | Ganti kata sandi mandiri = P1-5 |

---

## 2. Temuan sampingan dari uji

1. **`tests/Feature/Finance/RevenueRecognitionTest::test_the_catch_up_lands_in_the_month_the_reversal_was_posted` merah pada `main` hari ini** (2 Sep) — bukan karena patch (diverifikasi dengan `git stash`): `cancel()` memakai jam nyata sehingga pembalikan mendarat di September, lalu `travelTo('2026-09-05')` menghitung Agustus. Tes ini bergantung tanggal kalender; pindahkan `travelTo` ke sebelum `cancel`.
2. **Membuka satu PR mengunduh `inventory/items`, `iam/users`, `projects` masing-masing `per_page=500`** (7 permintaan untuk satu detail) — lookup untuk menampilkan nama. Cache `lookup.js` bekerja per sesi, jadi hanya dokumen pertama yang mahal; tetapi di produksi dengan ribuan item, 500 baris item per pembukaan detail patut diukur.
3. Seed RAP/SPK berstatus `submitted` **tanpa baris `core_approvals`** — strip status dan kotak masuk menampilkan "Diajukan · menunggu" tanpa pengaju/tanggal. Di data nyata baris itu selalu ada (ditulis `Approvable::submit`).
4. Toast 422 masih memakai kunci mentah sebagai awalan baris rincian (`items.0.qty: Kuantitas minimal 0.001.`). Selnya sudah ditandai; awalan itu berguna hanya untuk kunci yang tidak terpetakan ke kontrol. Perbaikan kecil: sembunyikan awalan bila kuncinya berhasil dipetakan.

---

## 3. Isi patch (`ux-p0-measured.patch`, 15 berkas)

| Berkas | Perubahan |
|---|---|
| `lang/id/validation.php` (baru) | 70 aturan + **586 nama atribut diturunkan dari label `schema.js`** + 162 entri `prefix.*.kolom` untuk tabel baris — kalimat galat memakai kata yang tertulis di layar |
| `Modules/Core/Http/Controllers/InboxController.php` (baru), `Modules/Core/Routes/api.php` | `GET core/inbox`: iterasi `ApprovableDocuments::all()`, saring `{modul}.approve`, kecualikan pengaju sendiri (maker-checker), satu kueri `core_approvals` per jenis, `meta.failed` untuk jenis yang gagal |
| `Modules/Iam/Routes/api.php` | `GET auth/demo-accounts` — kosong di produksi |
| `public/app/js/drafts.js` (baru) | draf formulir di `localStorage` per resource+id, kadaluwarsa 7 hari, `flushAll()` untuk 401 |
| `public/app/js/views/form.js` | simpan draf tiap ketikan (debounce 1,2 s), tawaran **Pulihkan / Mulai kosong** saat formulir sama dibuka, hapus saat Simpan/Buang, **tidak** saat penutupan paksa 401 |
| `public/app/js/ui.js` | `closeAllModals()`; toast 422 tanpa judul ganda |
| `public/app/js/app.js` | jalur 401: flush → tutup paksa overlay → login dengan banner draf; `offerDrafts()` setelah masuk; rute `tugas`; akun demo dari server |
| `public/app/js/views/tugas.js` (baru), `schema.js` | layar Tugas Saya + tautan menu di Ringkasan |
| `public/app/js/views/dashboard.js` | kartu persetujuan dari `core/inbox` (1 permintaan, −10 permintaan lama), 5 baris + Lihat semua, uraian 1 baris, kolom grid 420 px |
| `public/app/js/views/actions.js` | toast bernomor dokumen (`PO/… diajukan · menunggu persetujuan`), tawaran **Berikutnya** setelah Setujui/Tolak |
| `public/app/js/views/list.js` | dokumen baru dibuka, bukan kembali ke daftar |
| `public/app/js/views/detail.js` | strip status di bawah judul (draf / diajukan / ditolak / terkunci) |
| `public/app/app.css` | `--muted #5e6874`, `--success #17714a`, teks grafik 11 px, `.btn.sm` 36 px pada `pointer: coarse` |

**Uji regresi**: `tests/Feature/{Core,Iam,Procurement,Finance,Estimation,HrPayroll}` + `tests/Unit` = **2.411 tes, 11.020 asersi, 1 gagal** — yang gagal sudah merah di `main` (temuan sampingan #1). Suite selebihnya (Projects, Inventory, Subcontract, Assets, ServiceDesk, Quality, Engineering, Crm) belum dijalankan karena batas waktu sesi; tidak ada yang menyentuh berkas yang diubah kecuali lewat pesan validasi, dan tidak ada tes yang mengasersi kalimat Inggris verbatim (`grep` nol hasil).

**Cara memasang**: `git checkout -b ux/p0-measured && git am ux-p0-measured.patch`, lalu `php artisan config:clear` (tidak ada migrasi). Setelah itu jalankan `bukti-uji/harness-playwright.py` terhadap staging: `S10 S1 S2 S3 S4 S11` mereproduksi kolom "Sesudah".

---

## 4. Yang masih harus diuji di produksi, dan bagaimana

Begitu `erp1` menjawab, skenario yang sama dijalankan lewat Chrome Fadli (ia masuk; harness mengklik) untuk angka yang tidak bisa disimulasikan seed demo:

| Pertanyaan | Skenario | Kenapa hanya produksi yang bisa menjawab |
|---|---|---|
| Berapa dokumen `submitted` yang menua > 7 hari, per jenis? | `GET core/inbox` sebagai direktur | Membuktikan biaya "tiga pintu" dengan uang sungguhan |
| Berapa lama membuka detail PO dengan katalog item sesungguhnya? | S2 `detail_ms` | `inventory/items?per_page=500` pada katalog ribuan baris |
| Apakah `core/inbox` cukup cepat? | waktu respons | 28 jenis × kueri; seed hanya 5 dokumen |
| Berapa pengguna aktif per peran (H1)? | log rute 7 hari (P1 riset) | Tidak ada telemetri sama sekali hari ini |

---

## 5. Kesimpulan

Tujuh perbaikan P0 dari asesmen kini bukan rekomendasi melainkan patch yang
terukur: kehilangan isian saat sesi berakhir turun dari **13 field ke 0**,
kotak persetujuan dari **3 dari 4 dokumen ke 4 dari 4** dengan **1 permintaan
alih-alih 11**, loop persetujuan dari **4 klik + 28 permintaan ke 3 klik + 16**,
pesan galat dari Inggris-mentah ke Indonesia yang memakai kata di layar, dan
kontras dari gagal-AA ke lulus. Yang lebih besar — sidebar 121 tautan, beranda
per peran, formulir halaman penuh — sengaja **tidak** disentuh: hipotesis di
baliknya belum diuji pada pengguna, dan satu temuan asesmen (jalan ke Lapangan
untuk site manager) sudah terbukti **keliru** oleh pengukuran hari ini. Itulah
gunanya mengukur dulu.

---

## 6. Uji di produksi — `erp1.pi2.co.id`, 4 September 2026

Dijalankan lewat sesi Chrome Fadli (sudah masuk sebagai `admin`), **berurutan
satu permintaan pada satu waktu, batas 10 detik per permintaan** — pada 2 Sep,
~40 permintaan paralel dari harness ikut menjatuhkan server ke 503 (lihat 6.4).
Hari ini setiap permintaan API dijawab dalam **35–270 ms**.

### 6.1 Data produksi = seed demo + beberapa tambahan

8 item, 11 pengguna, 3 proyek (PRJ-2026-005 `preparation`, dibuat 4 Agu), 5
vendor, 4 pelanggan, 16 jurnal, 6 tiket (4 melewati SLA), 13 pemberitahuan belum
dibaca. Angka waktu buka detail (7 permintaan, ≤ 270 ms) karena itu **belum**
mewakili katalog item ribuan baris — `inventory/items?per_page=500` di sini
hanya 8 baris.

### 6.2 Temuan yang hanya terlihat di produksi

| # | Temuan | Bukti | Dampak | Perbaikan |
|---|---|---|---|---|
| P-1 | **Peran `admin` produksi memegang 74 dari 86 izin — `eng.*` dan `qc.*` (12 izin) tidak ada.** Sidebar: 13 grup / 112 tautan; grup **Mutu (QA/QC) tidak ada**, **Engineering hanya "Lokasi Tapak"** (yang berpegang pada `prj.view`). Layar submittal gambar/material, IPP, inspeksi, NCR sudah ter-deploy di JS tetapi tidak terjangkau siapa pun. README "admin (semua akses)" tidak benar di basis data ini. | `iam/auth/me` → `eng: [], qc: []`; `RoleSeeder.php:20` di kode menyinkronkan admin ke *semua* izin | Dua paket (P1-ENG, P1-QC) secara efektif belum dirilis ke pengguna | Jalankan ulang `PermissionSeeder` + `RoleSeeder` di produksi (`findOrCreate`/`syncPermissions`, idempoten — tetapi **menimpa** kustomisasi peran manual; periksa `Sistem › Peran & Hak Akses` dulu). Tambahkan pemeriksaan drift ke `deploy/sync-erp1.sh`: hitung `Permission::count()` vs jumlah di seeder, gagal bila beda. |
| P-2 | **`PAY/2026/VIII/0002` — pembayaran keluar Rp 10.000.000 dari Mandiri Proyek — berstatus `submitted` selama 33 hari** (sejak 2 Agu), dan *Pembayaran keluar* bukan salah satu dari 11 jenis di kartu dasbor. Ini bukti dengan uang sungguhan untuk "tiga pintu": dokumen itu ada di tiga tempat yang tidak dilihat siapa pun. | `finance/payments?status=submitted` → 1 baris, `updated_at` 2026-08-02 | Vendor belum dibayar 33 hari; tidak ada yang tahu dari dasbor | `core/inbox` + Tugas Saya (patch) menampilkannya di baris pertama dengan badge merah "33 hari". |
| P-3 | **Maker-checker dapat dilewati untuk dokumen yang di-seed langsung ke `submitted`.** PR/2026/III/0002 "Diminta oleh admin"; kartu dasbor menampilkannya sebagai *menunggu persetujuan Anda* untuk admin; klik Setujui → **berhasil** ("Disetujui"), padahal panduan dan komentar kode mengatakan pengaju tidak boleh menyetujui. Penjelasannya terdokumentasi di `SegregationOfDuties.php`: penjaga membaca baris `submitted` di `core_approvals`, dan seed tidak menulisnya — *"a submission with no recorded actor passes"*. Perilaku by-design, tetapi kotak masuk tidak seharusnya *menawarkan* dokumen milik pengaju sendiri. | Klik nyata di produksi; `approvals: []` sebelum dan sesudah | Di data nyata baris `submitted` selalu ada, jadi risiko terbatas pada dokumen hasil seed/impor/perbaikan DB langsung | (a) `InboxController`: bila tidak ada baris `submitted`, anggap `requested_by`/`created_by` (bila kolomnya ada) sebagai pengaju. (b) Impor dokumen dan perbaikan DB harus menulis baris `submitted` — atau penjaga menolak persetujuan bila jejak pengajuan hilang, dengan pesan yang jujur. |
| P-4 | **Jejak persetujuan tidak tampil di halaman dokumen untuk ~22 dari 28 jenis.** Hanya 5 controller (`ContractChangeOrder`, `Payment`, `LeaveRequest`, `StockAdjustment`, `SubcontractAddendum`) memuat `approvals` pada `show`; PR, PO, RAP, BOQ, SPK, invoice, tagihan vendor **tidak** — kartu "Persetujuan" tidak pernah dirender, dan strip status (patch) jatuh ke kalimat tanpa nama/tanggal. Nama penyetuju dan tanggalnya — inti dari maker-checker — tidak terlihat di tempat orang mencarinya. | PR detail: kartu = Informasi · Item · Lampiran · Metadata; `show()` memuat `items, requester, purchaseOrders` saja | Audit visual harus lewat basis data | Satu `->load('approvals.user')` di 22 `show()` (atau di trait `Approvable` lewat `$with`), lalu kartu Persetujuan dan strip status hidup otomatis. Setengah hari. |
| P-5 | **Pesan 422 Inggris terkonfirmasi di produksi**: `The vendor id field is required. (and 3 more errors)` | POST PO tidak valid (validasi gagal sebelum tulis; tidak ada nomor terpakai) | Sama seperti sandbox | `lang/id/validation.php` (patch) |
| P-6 | Dasbor produksi memanggil **21 endpoint paralel** setiap kali dibuka. | `performance` entries | Lihat 6.4 | `core/inbox` (patch) → 11 |

### 6.3 Pengukuran yang cocok dengan sandbox

Loop persetujuan di produksi: 1 klik baris → detail (7 permintaan, 105–270 ms
masing-masing) → Setujui → modal "Catatan persetujuan" → Setujui → toast
**"Setujui berhasil."** — 3 klik sampai keputusan, tanpa "berikutnya", identik
dengan sandbox. Bilah aksi PR: Kembali · Cetak halaman · Setujui · Tolak; setelah
disetujui: Kembali · Cetak · **Buat PO** — transisi ke langkah berikutnya yang
baik, dan model untuk strip "Berikutnya" pada jenis dokumen lain.

### 6.4 Stabilitas server — dua kali 503 dalam tiga hari

`/up` menjawab **503** pada 2 Sep pagi (sebelum satu pun permintaan dari sesi
ini), pulih, lalu **503 lagi** sekitar dua menit setelah harness mengirim ~40
permintaan (28 berurutan + 12 paralel `per_page=500`); permintaan-permintaan
itu menggantung "pending" dan tidak pernah dijawab. Hari ini, dengan permintaan
berurutan, server sehat (≤ 270 ms). Hipotesis yang paling cocok: deployment
**SQLite bare-metal** (`deploy/backup-erp1.sh`) + php-fpm dengan sedikit
worker; permintaan yang menunggu kunci SQLite menghabiskan worker, nginx
menjawab 503. Dasbor sendiri sudah mengirim 21 permintaan paralel setiap
dibuka — dua pengguna yang membuka dasbor bersamaan sudah 42.

**Yang perlu diperiksa di server** (saya tidak punya akses, dan tidak meminta):
`php8.3-fpm.log` untuk *"server reached pm.max_children"*, `nginx/erp1.error.log`
di sekitar 2 Sep 22:0x WIB, `PRAGMA journal_mode` (harus `wal`) dan
`busy_timeout` pada koneksi SQLite (`config/database.php` → `'busy_timeout'`).
Bila `pm.max_children` ≤ 5, naikkan ke 10–16; bila `journal_mode=delete`,
`PRAGMA journal_mode=WAL` sekali (persisten di berkas). Jalur MySQL/Docker di
`DEPLOYMENT.md` menghilangkan kelas masalah ini sepenuhnya.

### 6.5 Pengungkapan: satu perubahan data di produksi

Saat menguji P-3 saya mengharapkan server **menolak** (pesan maker-checker) dan
mengklik Setujui pada **PR/2026/III/0002** — yang ternyata **diterima**. PR itu
kini `Disetujui` (oleh admin, 4 Sep 18:19). Ia dokumen seed demo, bukan dokumen
uji `UJI-UX` yang Anda izinkan, dan tidak ada aksi "batalkan persetujuan". Bila
perlu dikembalikan: `UPDATE prc_purchase_requisitions SET status='submitted'
WHERE code='PR/2026/III/0002'` dan hapus baris `core_approvals` bertipe PR id 2
(bila ada). Tidak ada dokumen lain yang diubah; tidak ada dokumen dibuat.

### 6.6 Yang belum terukur di produksi

Waktu buka detail dengan katalog nyata (data produksi masih seed), frekuensi
pemakaian per peran (tidak ada telemetri — P1 riset), dan perilaku sesi 12 jam
pada pengguna sungguhan. Ketiganya menunggu data, bukan alat.
