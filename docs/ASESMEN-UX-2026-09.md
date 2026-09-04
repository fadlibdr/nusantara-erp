# Asesmen UX — Arsitektur Informasi, Alur Kerja, Sistem Copy, dan Rencana Riset

**Nusantara ERP** · disusun 2 September 2026 · menindaklanjuti [ASSESSMENT-LANJUTAN.md](ASSESSMENT-LANJUTAN.md) Bagian C

> **Posisi dokumen ini.** Bagian C asesmen 1 Agustus menanyakan *"apakah layarnya
> bekerja?"* — dan jawabannya kini hampir seluruhnya ya: combobox, dirty-check, urut
> kolom, ekspor CSV, galat per sel, focus-trap, header sticky, cetak dari tema gelap —
> 77 dari 81 temuan ditutup. Asesmen ini menanyakan pertanyaan berikutnya: *"apakah
> pengalaman **di antara** layar-layar itu bekerja?"* — bagaimana pekerjaan sampai
> ke orang yang tepat, berapa langkah dari niat ke hasil, apakah bahasa antarmuka
> konsisten di 130 layar, dan apakah asumsi-asumsi desainnya pernah diuji pada
> pengguna sungguhan.
>
> **Metode.** Pembacaan kode SPA (`public/app/`, 32 ribu baris) dan katalog
> layar (`schema.js`, 92 resource + 40 rute khusus), dibaca berdampingan dengan
> `PANDUAN-PENGGUNA.md` — panduan itu ditulis dengan aturan "hanya yang benar-benar
> ada di layar", sehingga ia sendiri adalah bukti perilaku produk. Setiap temuan
> membawa rujukan `berkas:baris`. **Yang tidak dilakukan:** uji klik di situs live
> dan wawancara pengguna — keduanya justru menjadi isi Bagian 6 (rencana riset),
> karena beberapa temuan di sini adalah **hipotesis** yang harus dibuktikan, bukan
> fakta yang sudah selesai.
>
> **Bahasa.** Ditulis dalam bahasa Indonesia mengikuti konvensi `docs/`, karena
> rekomendasi copy-nya adalah string Indonesia dan pembacanya adalah tim yang
> membaca panduan itu.

---

## Ringkasan eksekutif

Nusantara ERP hari ini adalah produk yang **benar dan jujur** — kode yang menolak
berbohong tentang angka (ubin dasbor yang gagal berkata "gagal", bukan Rp 0),
dialog konfirmasi yang menyebut akibatnya, dan dokumentasi yang mengakui
batasannya sendiri. Itu fondasi yang jarang dimiliki ERP manapun, dan asesmen ini
tidak menyarankan mengubahnya.

Yang belum dibangun di atas fondasi itu adalah **jalur kerja**: sistem tahu apa
yang menunggu siapa, tetapi menyebarkannya ke tiga pintu yang harus diperiksa
bergantian; sidebar memuat 121 tautan yang sama bentuknya untuk direktur maupun
kerani gudang; dokumen 40 baris hidup di dalam modal yang isinya hilang saat sesi
berakhir; dan ~80% pesan validasi server masih berbahasa Inggris dengan nama kolom
mentah (`The vendor id field is required.`) di aplikasi yang seluruh layarnya
Indonesia.

**Lima hal yang paling berdampak, urut dari yang termurah:**

| # | Temuan | Dampak | Usaha |
|---|---|---|---|
| 1 | Pesan validasi Inggris di ~178 dari 216 FormRequest — tidak ada `lang/id/validation.php` | Setiap galat 422 di setiap formulir | ½ hari |
| 2 | Kotak persetujuan dasbor mencakup 11 dari 28 jenis dokumen, dibatasi 10 baris, tanpa "lihat semua" | Penyetuju berhenti mencari; dokumen bernilai besar menunggu di jenis yang tak tercakup | 2 hari |
| 3 | Sesi 12 jam, tanpa draf lokal — BOQ 40 baris hilang tanpa pemulihan | Kehilangan pekerjaan; panduan menyuruh "Simpan tiap bagian" sebagai gantinya | 1–2 hari |
| 4 | Token `--muted` 4,26:1 di atas `--bg` pada teks 10,5–11,5 px — di bawah AA | Delapan jam sehari bagi pembaca 40+ tahun | 1 jam |
| 5 | 121 tautan / 14 grup, satu bentuk untuk semua peran; tanpa favorit, tanpa "terakhir dibuka", tanpa beranda per peran | Waktu cari menu; onboarding peran baru | 3–5 hari (bertahap) |

Rincian, bukti, dan alternatif yang dipertimbangkan ada di Bagian 1–5. Bagian 6
adalah rencana riset 3 minggu untuk menguji hipotesis-hipotesis ini pada 8 pengguna
lintas peran sebelum ada satu pun yang dibangun besar-besaran. Bagian 7 adalah
prioritas dengan estimasi. Bagian 8 adalah bahan komunikasi internal.

---

## Bagian 0 — Cara asesmen ini bernalar

Sebelum temuan, tiga asumsi tersembunyi yang perlu dinyatakan — karena kalau salah
satu keliru, rekomendasinya ikut keliru.

**Asumsi A: pengguna utama adalah operator harian, bukan penyetuju.** Kode SPA
dioptimalkan untuk *mengisi* dokumen (combobox, galat per sel, impor Excel). Jalur
*memutus* dokumen — membuka, membaca, menyetujui, lanjut ke berikutnya — tidak
mendapat optimasi setara. Kalau kenyataannya direktur dan manajer keuangan adalah
pengguna yang paling sering membuka aplikasi (dan di kontraktor 50–200 orang, itulah
yang biasanya terjadi: satu orang menyetujui, dua puluh orang mengajukan), maka
prioritasnya terbalik. **Ini harus diukur, bukan ditebak** — lihat §6.

**Asumsi B: navigasi berbasis modul cocok dengan cara orang berpikir.** Sidebar
dikelompokkan per modul (Penjualan, Estimasi, Proyek, …) — cermin arsitektur
server. Tetapi seorang manajer proyek berpikir per *proyek* ("Gedung Kantor 8
Lantai: BOQ-nya, PO-nya, opname subkonnya, NCR-nya"), bukan per modul. Workspace
proyek sudah ada (`views/project.js`), tetapi ia bukan pintu masuk; sidebar tetap
pintu masuknya. Card sort di §6 dirancang untuk menguji asumsi ini.

**Asumsi C: tanpa framework, tanpa build — dipertahankan.** Asesmen ini **tidak**
mengusulkan mengganti tumpukan front-end. Setiap rekomendasi harus bisa
diimplementasikan dalam pola yang sudah ada (`el()`, `schema.js`, `modal()`).
Rekomendasi yang membutuhkan React/Vue ditolak di §9 dengan alasannya.

---

## Bagian 1 — Kritik desain (kerangka: kesan pertama, kegunaan, hierarki, konsistensi, aksesibilitas)

### 1.1 Kesan pertama (dua detik pertama)

**Layar masuk** — bersih, satu tugas, satu tombol. Akun demo yang bisa diklik
(`app.js:136-143`) adalah kemewahan yang tepat untuk lingkungan demo dan **harus
hilang di produksi** — kode tidak membedakan keduanya (`renderLogin` tidak membaca
env). Ini bukan temuan kosmetik: daftar email peran internal di halaman masuk publik
`erp1.pi2.co.id` adalah peta untuk siapa pun yang mencoba menebak kredensial.

**Dasbor** — mata jatuh ke ubin uang (nilai kontrak, piutang, hutang, saldo bank,
termin siap ditagih), lalu ke "Menunggu persetujuan Anda". Untuk direktur dan
finance ini benar. Untuk site manager, estimator, atau teknisi, dasbor yang sama
menampilkan ubin yang tidak mereka miliki izinnya — jadi kosong — dan kartu
persetujuan untuk dokumen yang tidak pernah mereka setujui. Dasbor adalah **satu
layar untuk sebelas peran**, dan hanya dua peran yang dilayaninya dengan baik.

**Sidebar** — dibuka penuh secara bawaan (`app.js:186` — `isOpen` true bila belum
ada preferensi), 14 grup, 121 tautan. Pada monitor 1080p, sekitar 45 tautan terlihat
sebelum harus menggulung. Reaksi emosional pertama seorang pengguna baru adalah
"banyak sekali" — dan itu sebelum ia tahu bahwa `SDS`, `SMS`, `IPP`, `IKL`, `ILB`,
`IMK`, `BAPP`, `PPK`, `SP3`, `NCR`, `QCI` adalah sebelas akronim yang harus ia
pelajari untuk membaca menunya.

### 1.2 Kegunaan

| Temuan | Bukti | Tingkat | Rekomendasi |
|---|---|---|---|
| **Pekerjaan masuk lewat tiga pintu yang harus diperiksa bergantian**: kartu dasbor (11/28 jenis dokumen, maks 10 baris), lonceng (basi setelah dibaca), Tenggat (hanya yang lewat). Panduan sendiri berkata *"Pakailah ketiganya."* | `dashboard.js:338-379`; `ApprovableDocuments.php` (28 entri); `PANDUAN-PENGGUNA.md` §1.7 | 🔴 Kritis | Satu layar **Tugas Saya** (`#/tugas`): gabungan semua dokumen `submitted` yang boleh disetujui pemanggil **dihitung di server** (satu endpoint `core/inbox` yang berjalan atas `ApprovableDocuments`), tenggat yang lewat, dan dokumen milik sendiri yang ditolak. Kartu dasbor menjadi cuplikan 5 baris + tautan "Lihat semua (N)". Lonceng tetap ada untuk *peristiwa*; Tugas Saya untuk *keadaan*. |
| **Menyetujui satu dokumen = 4 langkah, tanpa jalur ke dokumen berikutnya**: klik baris → baca → `Setujui` → modal catatan → `Setujui` lagi → kembali ke dasbor → cari baris berikutnya. Untuk 15 PO sehari ini ~90 klik. | `schema.js:26-44` (approve membawa `fields`, jadi selalu lewat `promptFields`); `actions.js:16-20` | 🔴 Kritis | (a) Catatan persetujuan **opsional inline** di halaman dokumen, bukan modal — hanya `Tolak` yang butuh alasan wajib. (b) Setelah memutus, tampilkan strip *"Disetujui. Berikutnya: PO/2026/IX/0013 — Rp 84 jt"* dengan tombol **Buka**. (c) Di Tugas Saya: centang beberapa PR/PO nilai kecil → **Setujui terpilih**, dengan ambang nilai yang bisa dikonfigurasi (nilai besar tetap satu per satu). |
| **Sesi 12 jam tanpa draf**: formulir yang terbuka saat token kedaluwarsa hilang total; panduan menyuruh pengguna "Simpan tiap bagian" — memindahkan beban ke manusia. | `PANDUAN-PENGGUNA.md` §1.2; `api.js` hanya menyimpan token/user ke `localStorage`; tidak ada draf lokal di seluruh `public/app/js` | 🔴 Kritis | Simpan snapshot formulir ke `localStorage` per `def.api` + `row.id` setiap 10 detik bila `dirty()` (fungsi `snapshot()` di `form.js:759` **sudah ada**). Saat 401, `api.js` memanggil `onUnauthorized` — simpan snapshot terakhir sebelum layar login muncul; setelah masuk kembali, tawarkan *"Ada isian PO yang belum tersimpan (12 menit lalu). Pulihkan?"* Draf dihapus saat simpan berhasil. |
| **`Ubah` dan `Hapus` menghilang tanpa penjelasan** begitu status keluar dari Draf/Ditolak. Pengguna yang tidak membaca §2.6 panduan menyimpulkan "tidak punya izin". | `detail.js:634-676`; `PANDUAN-PENGGUNA.md` §0 kalimat 2 | 🟡 Sedang | Strip status di bawah judul dokumen: *"Diajukan 2 Sep oleh Andi · menunggu persetujuan Finance Manager · untuk mengubah, minta penyetuju menolaknya."* Satu kalimat yang berganti per status — datanya sudah ada di `record.approvals`. |
| **Tidak ada ganti kata sandi mandiri, tidak ada "lupa kata sandi"** — setiap reset lewat administrator. | tidak ada rute `password` di `Modules/Iam/Routes/api.php`; `PANDUAN-PENGGUNA.md` §0 kalimat 5 | 🟡 Sedang | `PUT iam/me/password` (butuh sandi lama) + menu di chip pengguna. "Lupa kata sandi" via email hanya bila `MAIL_MAILER` bukan `log` — kalau tidak, tombolnya menyebut nama administrator. |
| **Bilah aksi dokumen mencampur navigasi, cetak, ubah, dan keputusan dalam satu baris**: Kembali · Cetak · PDF · [N formulir rumah] · Ubah · Ajukan/Setujui/Tolak/Posting. PO dengan dua formulir rumah = 8 tombol setara secara visual; keputusan bernilai ratusan juta duduk di sebelah "Cetak halaman". | `detail.js:653-678` | 🟡 Sedang | Tiga zona: kiri = navigasi (Kembali); tengah = keluaran (satu tombol **Cetak ▾** yang menampung PDF + formulir rumah); kanan = keputusan (Ubah · Ajukan/Setujui/Tolak), dengan keputusan utama sebagai satu-satunya `.primary`. |
| **Baris item di modal 960 px**: BOQ/PO/RAP dengan puluhan baris diedit di jendela yang menggulung di dalam overlay; tidak ada Enter-untuk-baris-baru, tidak ada tempel dari Excel ke tabel baris. Impor Excel ada, tetapi untuk koreksi 3 baris orang tetap ke modal. | `form.js:754` (`width: 'wide'`), `app.css:704`; tidak ada handler `keydown`/`paste` di `form.js` | 🟡 Sedang | Untuk resource dengan `lines`, buka form sebagai **halaman** (`#/e/<resource>/<id>`), bukan modal — lebar penuh, URL bisa dibagikan, tombol Simpan sticky. Tambahkan: Enter di sel terakhir = baris baru; Ctrl+V multi-baris = tempel kolom sesuai urutan header. |
| **Input tanggal native** tampil `mm/dd/yyyy` di peramban berlokal EN — masih terbuka dari Bagian C. | `form.js:121-125` | 🟢 Kecil | Tetap `type=date` (kalender native berguna di ponsel) tetapi tambahkan `help` yang menampilkan nilai terformat id-ID di bawahnya: *"= 2 Sep 2026"*. Murah, menghilangkan keraguan bulan/hari. |

### 1.3 Hierarki visual

- **Apa yang menarik mata lebih dulu?** Di daftar: badge status berwarna, lalu kode
  monospace, lalu nilai — urutan yang benar untuk memindai antrean. Di dokumen: judul +
  badge status, lalu strip ringkasan (`summaryStrip`) — benar. Di dasbor: ubin uang —
  benar untuk finance, salah untuk lapangan.
- **Alur baca** mengikuti kiri-atas ke kanan-bawah dengan wajar. Yang patah adalah
  **bilah aksi**: mata harus memindai 6–8 tombol setara untuk menemukan satu keputusan.
- **Penekanan**: skala tipografi ada tetapi sempit — h1 20 px, h2 kartu 13,5 px, teks
  13 px, sekunder 11–11,5 px. Jarak antara "judul kartu" dan "isi kartu" hanya 0,5 px;
  yang membedakan keduanya adalah bobot, bukan ukuran. Pada tabel padat ini bekerja;
  pada halaman detail dengan 6 kartu bersusun, batas antar kartu bergantung pada
  garis tepi. Pertimbangkan h2 kartu 14 px dan label uppercase 11 px → 11,5 px.
- **Ruang kosong** dipakai efisien untuk aplikasi data; tidak ada yang boros.

### 1.4 Konsistensi

| Elemen | Ketidakkonsistenan | Bukti | Rekomendasi |
|---|---|---|---|
| **Pola label menu dengan akronim** — tiga pola bercampur: *Indonesia (AKRONIM)* "Persetujuan Gambar (SDS)"; *AKRONIM (Indonesia)* "RFQ (Banding Penawaran)"; *akronim saja* "BAST", "RAP", "AHSP", "Milestone" (Inggris). | `schema.js:5529-5760` | Satu pola: **istilah yang diucapkan orang di lapangan, akronim dalam kurung hanya bila akronim itu yang tertulis di formulir cetak**. Jadi "RFQ (Banding Penawaran)" → "Banding Penawaran (RFQ)"; "BAST" tetap (itu nama formulirnya); "Milestone" → "Milestone" boleh — istilah serapan yang lazim di kontrak. |
| **Warna status ditetapkan per string, bukan per makna**: `open` → hijau untuk tiket, insiden K3, defect, dan NCR. NCR *terbuka* memblokir BAST — itu keadaan yang salah, dan hijau berkata sebaliknya. | `format.js:129-140`; `enums.js:252,280,293,352` | `statusTone(value, enumName)`: `open` hijau untuk tiket layanan (tiket baru = normal), **merah** untuk NCR/insiden/defect. Atau: enum membawa tone-nya sendiri di `enums.js` sehingga `format.js` tidak perlu tahu semantik modul. |
| **Toast sukses generik** — `${action.label} berhasil.` menghasilkan "Ajukan berhasil.", "Posting berhasil.", "Setujui berhasil." — kata kerja perintah + "berhasil", tanpa objek. | `actions.js:34` | *"PO/2026/IX/0012 diajukan · menunggu Finance Manager"* — nomor dokumen, kata kerja pasif, keadaan berikutnya. Datanya ada di `result`. |
| **Nama kolom Inggris bocor di pesan galat**: `The vendor id field is required.`, `The items.0.unit price field is required.` | tidak ada `lang/id/`; `config/app.php:91` (`APP_LOCALE` bawaan `en`); 38 dari 216 FormRequest punya `messages()` | Lihat §3.1 — satu berkas `lang/id/validation.php` + peta `attributes`. |
| **Ikon tanpa label untuk aksi baris** (Ubah/Hapus/Cetak) hanya membawa `title` — tooltip tidak ada di sentuhan. | `list.js:620-660` | Di `pointer: coarse`, tampilkan label teks di sebelah ikon, atau kumpulkan ke satu tombol "⋯" bermenu. |
| **Dua kosakata untuk satu hal**: "Pemberitahuan" (lonceng) vs "notifikasi" (Pengaturan › Notifikasi di README) ; "Opname" dipakai untuk tiga hal berbeda (stok, subkon, owner) — benar secara industri, tetapi kolom "Opname" tanpa kualifikasi di Tugas Saya nanti akan ambigu. | `notifications.js:98`; README | Glosarium satu halaman (§3.3) yang mengikat `schema.js`, panduan, dan formulir cetak. |

### 1.5 Aksesibilitas

Yang sudah baik — dan ini di atas rata-rata ERP: `role="dialog"` + `aria-modal` +
focus-trap + pengembalian fokus (`ui.js:355-405`), satu tab-stop per tabel dengan
panah atas/bawah (`ui.js:482-560`), `aria-labelledby` yang memisahkan nama kolom dari
hint-nya (`ui.js:630-660`), live region di combobox, `:focus-visible` di semua
kontrol, target sentuh 42 px pada `pointer: coarse` untuk opsi combobox.

Yang belum:

- **Kontras token `--muted` (#6b7684)**: 4,62:1 di atas `--surface` (lulus AA),
  **4,46:1 di atas `--surface-2`** (header tabel, 11 px uppercase — gagal AA tipis),
  **4,26:1 di atas `--bg`** (gagal). `--success` di atas `--success-soft` 4,42:1
  (badge 11,5 px — gagal). Semua teks ini berukuran 10,5–11,5 px, yaitu "teks
  kecil" menurut WCAG, sehingga ambangnya 4,5:1, bukan 3:1.
  **Perbaikan satu baris**: `--muted: #5e6874` (5,23 / 5,47 / 5,66 pada ketiga
  permukaan) dan `--success: #17714a` (5,29). Token gelap sudah lulus semua.
- **Ukuran huruf lantai 10 px** (teks sumbu grafik, `app.css:757`) dan 10,5 px
  (`brand-text span`, `userchip span`). Naikkan lantai ke 11 px; grafik ke 11 px.
- **Kode warna kalender** sudah dipasangkan dengan nama departemen di legenda dan
  tooltip (`dashboard.js:106-115`) — bagus. Badge status juga membawa teks. Grafik
  kurva-S membedakan garis dengan pola dash — bagus.
- **`<tr class="clickable">` tanpa nama aksesibel** — pembaca layar menyebutkan isi
  sel, tetapi tidak menyebut bahwa Enter membuka dokumen. Tambahkan
  `aria-description="Tekan Enter untuk membuka"` sekali di tbody (bukan per baris).
- **Target sentuh tombol `.sm` 28 px** di bawah 44 px pedoman. Di `pointer: coarse`,
  naikkan `.btn.sm` ke 36 px seperti yang sudah dilakukan untuk `.combo-opt`.

### 1.6 Yang sudah bekerja dengan baik — dan tidak boleh "dirapikan"

1. **Kejujuran tentang kegagalan.** Ubin yang gagal berkata "—  Gagal dimuat", bukan
   Rp 0; kotak persetujuan yang tidak lengkap berkata *"Daftar ini belum lengkap"*
   (`dashboard.js:52-70, 382-397`). Ini prinsip desain, bukan detail, dan harus jadi
   bagian dari pedoman copy (§3).
2. **Dialog konfirmasi yang menyebut akibat**, bukan "Yakin?": *"Posting GRN ini? Stok
   dan HPP rata-rata bergerak akan diperbarui dan dokumen tidak bisa diubah lagi."*
   (`schema.js:2759`). Tombol berlabel kata kerjanya, fokus awal pada yang aman
   (`ui.js:460`). Ini contoh yang benar untuk 22 aksi berkonfirmasi.
3. **Layar Lapangan** dirancang dari batasan fisik ("satu tangan bebas, di bawah
   matahari") dan jarak foto ke titik proyek diberi warna **dan** angka.
4. **Formulir rumah dari katalog server** — 40 dokumen cetak tanpa 40 suntingan
   front-end.
5. **Pencarian global Ctrl+K** yang menyaring per izin — ini pintu yang, bila
   diperkuat (§2.3), bisa menggantikan sebagian besar navigasi sidebar.
6. **Panduan pengguna yang hanya menulis yang ada.** Itu artefak riset UX sekaligus:
   setiap kalimat "Anda tidak bisa…" di §14 adalah backlog.

---

## Bagian 2 — Arsitektur informasi & navigasi

### 2.1 Angkanya

| Grup | Tautan | Grup | Tautan |
|---|---|---|---|
| Ringkasan | 3 | Persediaan | 8 |
| Penjualan | 11 | Subkontrak | 6 |
| Estimasi | 5 | **Keuangan** | **20** |
| Engineering | 6 | SDM & Payroll | 6 |
| **Proyek** | **20** | Layanan | 5 |
| Mutu (QA/QC) | 4 | Aset | 8 |
| **Pengadaan** | **13** | Sistem | 6 |

**121 tautan, 14 grup** (`schema.js:5529-5760`). Penyaringan per izin
(`app.js:158-172`) memendekkannya per peran, tetapi tidak banyak untuk peran yang
justru paling sering membuka aplikasi: `project-manager` memegang `prj`, `est`,
`scm`, `eng`, `qc`, `ast` → sekitar 55 tautan; `direktur`/`admin` melihat semuanya.

Grup Proyek dan Keuangan masing-masing 20 tautan **tanpa sub-hierarki** — batas
yang lazim dipakai (7 ± 2 per tingkat, atau hingga ~12 bila ada pemindaian visual)
terlampaui dua kali lipat. Komentar kode menunjukkan urutannya sudah dipikirkan
("opname di bawah progres mingguan karena itulah hubungannya"), tetapi hubungan
itu hanya terlihat oleh yang membaca komentarnya. Di layar, 20 baris teks 13 px
berjarak 6 px adalah satu kolom rata.

### 2.2 Hipotesis: model mental pengguna adalah *proses* dan *proyek*, bukan *modul*

Bukti tidak langsung dari kode sendiri:

- Panduan pengguna disusun per **proses** ("Penawaran sampai penagihan",
  "Permintaan sampai pembayaran"), bukan per modul — penulisnya menemukan bahwa
  itulah cara menjelaskannya.
- Komentar di `NAV` berkali-kali memindahkan tautan keluar dari modul pemiliknya
  karena "di situlah orangnya bekerja" (Lokasi Tapak ke Engineering, Pustaka Metode
  ke Estimasi, Impor ke Sistem dengan izin sendiri). Ini navigasi yang sedang
  mencoba menjadi berbasis peran, satu pengecualian demi satu pengecualian.
- Workspace proyek (`project.js`) sudah menghimpun kurva-S, WBS, aktivitas — tetapi
  BOQ, PO, SPK, NCR, BAST proyek itu tetap dicapai lewat sidebar + filter proyek.

**Ini hipotesis, dan card sort di §6.3 adalah ujinya.** Kalau terbukti, arahnya
di bawah; kalau tidak, cukup 2.3 dan 2.4.

### 2.3 Rekomendasi bertahap (tidak perlu semuanya)

**Tahap 1 — tanpa mengubah struktur (1–2 hari)**

- **"Terakhir dibuka"** (5 dokumen) dan **"Favorit"** (tautan yang ditandai bintang)
  sebagai grup pertama di sidebar, disimpan di `localStorage` seperti `NAV_STATE_KEY`.
  Orang yang membuka 6 layar yang sama setiap hari tidak perlu menggulung 121 tautan.
- **Grup tertutup secara bawaan** kecuali grup yang berisi rute aktif dan Ringkasan.
  Pengguna baru melihat 14 judul, bukan 121 baris; yang sudah biasa membuka yang ia
  perlukan (preferensi sudah dipertahankan — `NAV_STATE_KEY`).
- **Ctrl+K sebagai pintu utama untuk layar, bukan hanya dokumen**: ketik "opname"
  → tiga layar bernama Opname muncul dengan grupnya. Hari ini `search.js` mencari
  dokumen; menambahkan `NAV` sebagai sumber pencarian adalah pekerjaan kecil.
- **Pemisah visual di dalam grup panjang** — `NAV` menerima item
  `{ divider: 'Izin lapangan' }`; Proyek menjadi 4 sub-blok (Pelaksanaan · Serah
  terima · Izin & K3 · Register), Keuangan menjadi 4 (AR/AP · Kas · Pelaporan ·
  Pajak · Master). Struktur datanya tetap datar; hanya renderer yang membaca
  pemisah.

**Tahap 2 — beranda per peran (3–5 hari, setelah §6)**

- Dasbor membaca **peran utama** (`session.user.roles[0]`) dan memilih susunan ubin
  + kartu: `direktur`/`finance` = uang & persetujuan (yang ada sekarang);
  `project-manager` = proyek saya (deviasi kurva-S, NCR terbuka, PO terlambat, opname
  menunggu); `site-manager` = tombol besar ke Lapangan + IPP hari ini + izin kerja;
  `procurement` = PR menunggu, RFQ terbuka, baris PO terlambat; `warehouse` = stok
  minimum, GRN menunggu, transfer dalam perjalanan. Sakelar "Proyek saya" yang sudah
  ada (`dashboard.js:12-16`) adalah cikal bakalnya.
- **Proyek sebagai pintu**: di workspace proyek, tab per proses (Estimasi · Pengadaan
  · Subkon · Mutu · Serah terima · Keuangan) yang membuka daftar generik dengan
  filter `project_id` terkunci. Tidak ada layar baru — hanya `renderList` dengan
  filter awal, yang sudah didukung `list.js` lewat query hash.

### 2.4 Mobile

Di ≤ 760 px (`app.css:786-820`) sidebar menjadi laci 264 px berisi 121 tautan yang
sama. Layar Lapangan sudah benar; masalahnya adalah **sampai** ke Lapangan: site
manager membuka ponsel → laci → grup Proyek (20 baris) → baris ke-3. Tahap 1 di
atas (favorit + grup tertutup) sudah menyelesaikan sebagian besar. Tambahan murah:
bila `pointer: coarse` dan pengguna memegang `prj.create`, tampilkan **Lapangan**
sebagai tombol tetap di header, bukan di laci.

---

## Bagian 3 — Sistem copy (ux-copy)

Prinsip yang sudah dijalankan kode dan **harus ditulis sebagai aturan** supaya 130
layar tetap satu suara saat orang yang menulisnya berganti:

1. **Jangan pernah menyatakan sesuatu yang tidak diketahui.** "Tidak ada dokumen yang
   dapat ditampilkan" ≠ "Tidak ada dokumen yang menunggu" (`dashboard.js:388`).
2. **Sebut akibatnya, bukan kepastian.** "Posting GRN ini? Stok dan HPP … tidak bisa
   diubah lagi", bukan "Yakin?".
3. **Tombol berlabel kata kerjanya.** Hapus / Posting / Terbitkan / Buat PO — bukan OK.
4. **Nomor dokumen adalah subjek kalimat.** Bukan "berhasil", tetapi
   "PO/2026/IX/0012 diajukan".
5. **Bahasa yang diucapkan orang di lapangan** (opname, termin, retensi, aanwijzing),
   dengan akronim hanya bila akronim itu yang tercetak di formulir.

### 3.1 Prioritas 1 — pesan validasi (½ hari, dampak setiap formulir)

**Keadaan**: `.env` menyetel `APP_LOCALE=id`, tetapi tidak ada direktori `lang/id/`,
sehingga Laravel jatuh ke `en`. Hanya 38 dari 216 FormRequest menulis `messages()`
sendiri; 1 menulis `attributes()`. `PurchaseOrderStoreRequest.php:18-37` — formulir
yang dipakai berkali-kali sehari — mengirim:

```
The vendor id field is required.
The items.0.unit price field is required.
The order date is not a valid date.
```

Front-end memetakannya ke kolom yang benar (perbaikan Bagian C), tetapi
**kalimatnya tetap Inggris dengan nama kolom basis data**.

**Perbaikan**: `php artisan lang:publish` → salin ke `lang/id/validation.php`
(terjemahan resmi Laravel-lang tersedia), lalu **satu** peta `attributes` global di
`lang/id/validation.php`:

```php
'attributes' => [
    'vendor_id' => 'vendor', 'customer_id' => 'pelanggan', 'project_id' => 'proyek',
    'order_date' => 'tanggal pesanan', 'due_date' => 'jatuh tempo',
    'items.*.description' => 'uraian baris', 'items.*.qty' => 'kuantitas',
    'items.*.unit_price' => 'harga satuan', 'items.*.item_id' => 'item',
    // … ±80 kunci; sisanya diturunkan otomatis dari snake_case → spasi
],
```

Hasil: *"Vendor wajib diisi."*, *"Harga satuan pada baris 1 wajib diisi."* Untuk
`items.N.*`, ganti `:attribute` lewat `attributes()` di satu `BaseFormRequest` yang
menyisipkan "pada baris N+1" — supaya galat tabel baris menyebut nomor baris yang
dilihat manusia (1-based), bukan indeks array.

### 3.2 Pola copy per konteks — rekomendasi dan alternatif

**Toast sukses aksi** (`actions.js:34`)

| Opsi | Copy | Nada | Cocok untuk |
|---|---|---|---|
| Sekarang | `Ajukan berhasil.` | mekanis | — |
| **A (disarankan)** | `PO/2026/IX/0012 diajukan · menunggu Finance Manager` | informatif, menyebut keadaan berikutnya | semua aksi lifecycle; nama penyetuju berikutnya dari `required_levels` bila ada |
| B | `Diajukan. PO/2026/IX/0012 kini menunggu persetujuan.` | netral | bila penyetuju berikutnya tidak diketahui |
| C | `PO/2026/IX/0012 diajukan.` + tombol **Buka** | ringkas + jalan kembali | aksi dari daftar (bukan dari halaman dokumen) |

**Strip status dokumen** (baru, di bawah judul — `detail.js` setelah `page-head`)

| Status | Copy |
|---|---|
| draft | *Draf · belum diajukan. Ubah dan Hapus tersedia sampai diajukan.* |
| submitted | *Diajukan 2 Sep oleh Andi · menunggu {penyetuju}. Untuk mengubah, minta penyetuju menolaknya.* |
| rejected | *Ditolak 3 Sep oleh Budi: "{alasan}". Perbaiki lalu ajukan lagi.* |
| approved / posted | *Disetujui 3 Sep oleh Budi · dokumen terkunci. Perubahan hanya lewat revisi/pembalikan.* |
| superseded | (sudah ada — `detail.js:686-700`, pertahankan) |

Ini menjawab langsung kalimat ke-2, ke-3, dan ke-4 dari "enam kalimat untuk semua
orang" di panduan — yang hari ini harus dibaca orang di dokumen, bukan di layar.

**Keadaan kosong** (`list.js:690-698`) — sudah benar bentuknya. Yang perlu ditambah
adalah **keadaan kosong bersyarat lintas modul**, mengikuti preseden layar impor yang
"saling menunjuk":

| Layar | Kosong karena | Copy |
|---|---|---|
| PO | belum ada PR disetujui | *Belum ada pesanan pembelian. PO biasanya dibuat dari PR yang disetujui — {N} PR sedang menunggu persetujuan.* |
| Invoice Termin | belum ada kontrak aktif | *Belum ada invoice. Termin ditagih dari kontrak yang Aktif — lihat Termin Siap Ditagih.* |
| Opname Subkon | belum ada SPK disetujui | *Belum ada opname. Opname diajukan atas SPK yang disetujui.* |
| Inspeksi Mutu | belum ada template | *Belum ada inspeksi. Buat Template Inspeksi dulu — inspeksi mengikuti daftar periksanya.* |

Aturannya: keadaan kosong menyebut **dokumen hulu** yang harus ada, dengan tautan.
Datanya sudah ada di `schema.js` (`prefill`/`importPick` mendeklarasikan sumber
hulu setiap resource).

**Galat jaringan / 5xx** (`api.js:15`) — `Terjadi kesalahan (HTTP 500).` Ganti
dengan struktur *apa + mengapa + lalu apa*: *"Server tidak merespons. Isian Anda
masih ada di layar ini — coba Simpan lagi dalam beberapa detik."* (benar hanya
setelah §1.2 draf lokal; sebelum itu: *"…Isian Anda belum tersimpan."*).

**Sesi berakhir** (`app.js` banner) — sudah baik. Setelah draf lokal: *"Sesi Anda
berakhir. Isian PO yang sedang Anda buat tersimpan di peramban ini — masuk kembali
untuk melanjutkan."*

### 3.3 Glosarium — satu sumber untuk `schema.js`, panduan, dan formulir cetak

Daftar minimum yang harus dikunci (istilah · pemakaian · akronim tercetak):

| Istilah di layar | Jangan pakai | Akronim di formulir |
|---|---|---|
| Penawaran | Quotation, Quote | — |
| Termin | Milestone billing, Progress billing | — |
| Opname stok / Opname subkon / Opname owner | Stock take, Progress claim, Measurement | — / — / OPN |
| Pesanan pembelian | Purchase order (di label) | PO |
| Permintaan pembelian | Purchase requisition | PR |
| Banding penawaran | RFQ (sebagai kata utama) | RFQ |
| Berita acara serah terima | Handover | BAST |
| Persetujuan gambar / material | Shop drawing submittal | SDS / SMS |
| Ijin pelaksanaan pekerjaan | Work permit (bahasa PO) | IPP |
| Ketidaksesuaian | Non-conformance | NCR |
| Pemberitahuan | Notifikasi | — |

Konsistensi label EN/ID yang disebut Bagian C ("Update", "rollup") ditutup dengan
satu grep atas `schema.js` terhadap daftar ini — bukan pekerjaan besar, tetapi
harus punya pemilik.

### 3.4 Catatan lokalisasi

- Kalimat Indonesia rata-rata 15–20% lebih panjang dari Inggris; label kolom
  `th` 11 px uppercase dengan `white-space: nowrap` (`app.css:493-503`) akan
  memanjang. Terjemahan atribut di §3.1 hanya menyentuh pesan galat, bukan header.
- Format tanggal `2 Sep 2026` dan uang `Rp 1.234.567` sudah terpusat di `format.js` —
  jangan pernah memformat di tempat lain.
- Bentuk pasif ("diajukan", "disetujui") adalah nada yang tepat untuk dokumen resmi
  Indonesia; bentuk perintah ("Ajukan", "Setujui") untuk tombol. Jangan campur:
  toast "Ajukan berhasil" mencampur keduanya.

---

## Bagian 4 — Alur kerja yang paling sering dijalani

Empat alur yang, menurut struktur produk, dijalani berkali-kali sehari. Setiap
alur: langkah hari ini → hambatan → usulan. **Hitungan langkah adalah pembacaan
kode, bukan pengukuran** — §6 mengukurnya.

### 4.1 Menyetujui dokumen (direktur, finance manager, PM)

```
Hari ini:  Dasbor → kartu (maks 10, 11 jenis) → klik baris → baca → Setujui
           → modal catatan → Setujui → toast "Setujui berhasil." → Kembali
           → dasbor dimuat ulang (≈20 permintaan paralel) → cari baris berikutnya
Usulan:    Tugas Saya (semua jenis, dihitung server) → klik baris → baca
           → Setujui (catatan inline opsional) → strip "Berikutnya: …" → Buka
```

Penghematan per dokumen: 2 klik + 1 muat dasbor. Untuk penyetuju dengan 15
dokumen/hari, sekitar 45 klik dan 15 muat dasbor sehari — dan yang lebih penting,
**tidak ada dokumen yang tidak terlihat** karena jenisnya di luar 11.

Catatan tentang maker-checker: aturan "tidak boleh menyetujui dokumen sendiri"
sudah dijaga server dan pesannya menyebut nama orang yang harus didatangi
(panduan §0 kalimat 3) — pertahankan; Tugas Saya cukup tidak menampilkan dokumen
yang diajukan pemanggil sendiri.

### 4.2 Menyusun dokumen panjang (estimator, procurement)

```
Hari ini:  Daftar → Tambah → modal 960 px → isi 12 kolom header → gulung
           → tabel baris (Tambah baris × N, combobox per baris) → Simpan
           → bila 422: galat per sel (baik) → bila sesi habis: hilang
Usulan:    Daftar → Tambah → halaman penuh #/e/… (URL) → header di kiri, baris
           di kanan/bawah, Simpan sticky → draf lokal tiap 10 dtk → Enter = baris
           baru, Ctrl+V = tempel dari Excel → Simpan
```

Impor Excel (`dokumenimpor.js`) tetap jalur utama untuk BOQ ratusan baris; halaman
penuh melayani koreksi dan dokumen 5–40 baris yang hari ini paling sering diketik.

### 4.3 Laporan harian dari lokasi (site manager)

Sudah dirancang dengan benar di `lapangan.js`. Dua hambatan yang tersisa ada di
**jalan menuju** layar itu (§2.4) dan di **umpan balik unggah**: foto 5 MB lewat
JSON base64 (README) di jaringan seluler lokasi bisa 20–40 detik tanpa indikator
kemajuan. Tambahkan bilah kemajuan per foto (XHR `upload.onprogress` — `fetch`
tidak menyediakannya; ini alasan teknis yang sah untuk satu pengecualian dari
`api.js`) dan antrean lokal: foto yang gagal tetap di daftar dengan tombol
**Kirim ulang**, bukan hilang bersama toast.

### 4.4 Menemukan "di mana dokumen ini sekarang" (semua peran)

Pertanyaan paling umum di ERP: *"PO saya sudah sampai mana?"* Hari ini jawabannya
tersebar: badge status di daftar, kartu Persetujuan di sisi kanan halaman dokumen,
lonceng. Strip status (§3.2) menjawabnya dalam satu kalimat di tempat orang
melihatnya lebih dulu.

---

## Bagian 5 — Argumen tandingan yang dipertimbangkan

Setiap rekomendasi di atas punya versi yang lebih besar yang **ditolak**. Supaya
diskusinya tidak berulang:

| Usulan yang ditolak | Mengapa ditolak |
|---|---|
| **Ganti ke React/Vue + build step** untuk "UX modern" | Melanggar Asumsi C. Tidak ada temuan di sini yang tidak bisa diselesaikan dengan `el()` dan `schema.js`. Biaya deploy `git pull` dan keterbacaan seluruh UI di satu repo adalah nilai nyata yang akan hilang. |
| **Grayed-out untuk menu tanpa izin** | Sidebar 121 tautan yang semuanya tampil abu-abu untuk gudang adalah lebih buruk daripada yang ada. Menyembunyikan adalah pilihan yang benar; yang kurang hanyalah penjelasan saat sebuah *aksi* (bukan menu) hilang — itulah strip status. |
| **Dasbor "semua metrik" dengan filter** | Satu dasbor yang mencoba melayani 11 peran dengan filter adalah dasbor yang harus disetel setiap hari. Beranda per peran lebih murah dan lebih cepat dipakai. |
| **Notifikasi WhatsApp** untuk persetujuan | README sudah menjelaskan mengapa tidak (gateway, template Meta). Tugas Saya di dalam aplikasi menyelesaikan masalah "tidak melihat" tanpa dependensi luar. |
| **Setujui massal tanpa ambang** | Persetujuan bernilai ratusan juta memang harus dibuka satu per satu — itu bukan gesekan, itu kendali. Ambang yang dapat dikonfigurasi (misal ≤ Rp 25 jt) memisahkan keduanya. |
| **Autosave ke server** (draf di basis data) | Menyentuh 90 resource, izin, dan penomoran dokumen. Draf lokal di peramban menyelesaikan 95% kasus (sesi habis, tab tertutup) dengan 1–2 hari kerja dan nol perubahan server. |
| **Mengganti font sistem dengan font khusus** untuk konsistensi lintas OS | Tidak ada temuan yang disebabkan font. Angka sudah `tabular-nums`. Font khusus menambah unduhan dan tidak menjawab satu pun temuan di atas. (Bila ada sistem desain lain yang dimaksudkan untuk trunk ini, itu keputusan terpisah — lihat catatan penutup.) |

---

## Bagian 6 — Rencana riset pengguna (3 minggu)

Asesmen ini dibangun dari kode dan dokumen. Tiga hal di dalamnya adalah **hipotesis**
yang cukup mahal kalau salah: (H1) penyetuju adalah pengguna paling sering; (H2)
model mental pengguna adalah proses/proyek, bukan modul; (H3) kehilangan isian
karena sesi habis benar-benar terjadi, bukan sekadar mungkin. Rencana ini menguji
ketiganya sebelum Tahap 2 (§2.3) dibangun.

### 6.1 Tujuan & pertanyaan riset

| # | Pertanyaan | Metode | Hipotesis yang diuji |
|---|---|---|---|
| R1 | Berapa kali sehari tiap peran membuka aplikasi, dan untuk apa? | Log server (7 hari) + wawancara kontekstual | H1 |
| R2 | Bagaimana orang mencari layar? Menu, Ctrl+K, atau bookmark? | Observasi + log rute | H2 |
| R3 | Bagaimana orang mengelompokkan 121 layar bila diminta? | Card sort terbuka | H2 |
| R4 | Berapa lama dan berapa langkah untuk 4 tugas inti? Di mana gagal? | Uji kegunaan bertugas | §4 |
| R5 | Pernahkah kehilangan isian? Apa yang dilakukan sesudahnya? | Wawancara + diary | H3 |
| R6 | Pesan galat mana yang tidak dipahami? | Uji kegunaan (memicu 422 sengaja) | §3.1 |

### 6.2 Peserta

8 orang, satu per peran yang paling sering menyentuh alur §4, ditambah dua yang
jarang (untuk menangkap onboarding):

| Peran | Jumlah | Alasan |
|---|---|---|
| Direktur / finance manager (penyetuju) | 2 | H1; alur 4.1 |
| Manajer proyek | 1 | H2; workspace proyek |
| Estimator | 1 | alur 4.2 (BOQ) |
| Procurement | 1 | alur 4.2 (PO), 4.1 |
| Site manager | 1 | alur 4.3; mobile |
| Petugas keuangan (AR/AP) | 1 | volume dokumen tertinggi |
| Gudang atau teknisi (pengguna jarang) | 1 | onboarding, navigasi |

Kriteria: sudah memakai ERP ≥ 2 minggu; separuh peserta di lokasi proyek, bukan
kantor. Bila belum ada pengguna sungguhan sebanyak itu, 5 orang cukup untuk R2–R6;
R1 tetap dari log.

### 6.3 Protokol

**Minggu 1 — Ukur dulu (R1, R2).** Tambahkan satu baris log ringan: `route`,
`user_id`, `role`, timestamp pada setiap perubahan hash (`router.js`) → endpoint
`core/telemetry` (atau cukup log nginx bila hash dikirim sebagai query). Setelah 7
hari: rute per peran per hari, urutan rute (dari mana ke mana), rasio Ctrl+K vs
klik menu. Ini data yang hari ini **tidak ada sama sekali** — dan setelah ada, banyak
perdebatan di §2 selesai dengan sendirinya.

**Minggu 2 — Wawancara kontekstual (60 menit × 8) + card sort.**

Panduan wawancara:

1. *Pemanasan (5')* — jelaskan tujuan; tidak ada jawaban salah; kita menguji
   layarnya, bukan Anda.
2. *Konteks (10')* — "Ceritakan hari kerja kemarin. Kapan pertama membuka ERP?
   Untuk apa? Apa yang Anda lakukan sebelum membukanya (WhatsApp? Excel? kertas?)"
3. *Pendalaman (20')* — tiga probe:
   - "Tunjukkan bagaimana Anda tahu ada yang menunggu Anda." (R1, tiga pintu)
   - "Tunjukkan bagaimana Anda sampai ke layar X." Catat: menu / Ctrl+K / URL. (R2)
   - "Pernahkah ada isian yang hilang? Ceritakan." (R5) — jangan memancing
     "sesi"; biarkan mereka menyebutnya.
4. *Reaksi (15')* — tunjukkan mockup Tugas Saya dan strip status (cukup sketsa
   HTML dari `el()` di layar staging). "Apa yang akan Anda klik pertama?"
5. *Penutup (5')* — "Kalau boleh mengubah satu hal, apa?" Rekam kalimatnya
   verbatim untuk highlight reel.

Card sort terbuka (30' terpisah, 6 peserta): 60 kartu (semua tautan menu minus
Sistem dan yang jelas administratif), aplikasi gratis (kardSort/OptimalSort atau
kertas). Peserta mengelompokkan dan memberi nama. Analisis: matriks kesamaan —
bila ≥ 4 dari 6 mengelompokkan "Opname Subkon" bersama "Daftar Proyek" dan bukan
"SPK Subkon", H2 terbukti.

**Minggu 3 — Uji kegunaan bertugas (45 menit × 6) di staging dengan data demo.**

| Tugas | Peran | Ukuran keberhasilan |
|---|---|---|
| T1: "Ada PO yang menunggu Anda. Setujui yang di bawah Rp 50 jt." | penyetuju | menemukan **semua** (termasuk yang tidak di kartu dasbor); waktu; klik |
| T2: "Buat PO 8 baris dari PR/2026/IX/0004." | procurement | selesai tanpa galat 422 kedua; waktu; apakah menemukan `prefill` |
| T3: "Sesi Anda baru saja berakhir" (kami memutus token di tengah T2). "Lanjutkan." | procurement/estimator | reaksi verbal; berapa banyak yang diketik ulang |
| T4: "Cek apakah RAP proyek Gedung 8 Lantai masih di bawah kontrak." | PM | jalur yang dipilih (workspace proyek vs menu); waktu |
| T5: "Kirim foto progres lantai 3 dari ponsel." | site manager | di ponsel sungguhan, di luar ruangan; waktu sampai layar Lapangan |
| T6: "Simpan formulir ini." (kami menyiapkan formulir dengan 3 kolom kosong) | siapa saja | apakah peserta memahami pesan galat tanpa bertanya |

Skor: keberhasilan (selesai / selesai dengan bantuan / gagal), waktu, jumlah
klik, SUS 10 pertanyaan di akhir (target ≥ 68; ERP internal yang baik 70–75).

### 6.4 Sintesis

- **Affinity map** dari catatan wawancara → tema (target 6–10 tema).
- **Matriks dampak × usaha** atas tema + temuan asesmen ini — beberapa temuan di
  sini akan **turun** prioritasnya setelah riset, dan itu hasil yang baik.
- **Peta perjalanan** untuk dua alur (persetujuan; dokumen panjang) dengan titik
  nyeri yang diberi kutipan peserta.
- Keluaran: `docs/RISET-PENGGUNA-2026-09.md` (temuan + rekomendasi + kutipan) dan
  pembaruan §7 dokumen ini.

### 6.5 Yang tidak perlu diriset

Kontras warna, ukuran target sentuh, pesan validasi Inggris — ini standar, bukan
pertanyaan. Kerjakan langsung (§7, P0).

---

## Bagian 7 — Prioritas & estimasi

Usaha adalah orang-hari untuk satu pengembang yang sudah mengenal `public/app/`.

### P0 — kerjakan sekarang, tidak bergantung riset (total ≈ 4 hari)

| # | Pekerjaan | Berkas | Usaha |
|---|---|---|---|
| P0-1 | `lang/id/validation.php` + peta `attributes` + "pada baris N" untuk `items.*` | `lang/id/`, satu `BaseFormRequest` | ½ |
| P0-2 | Token `--muted #5e6874`, `--success #17714a`; lantai 11 px; `.btn.sm` 36 px di `pointer: coarse` | `app.css:6-40, 234, 271, 757` | ¼ |
| P0-3 | Draf lokal formulir: snapshot tiap 10 dtk bila `dirty`, simpan saat 401, tawarkan pulihkan setelah masuk | `form.js:759`, `api.js` (`onUnauthorized`), `app.js` (`renderLogin`) | 1½ |
| P0-4 | Toast aksi menyebut nomor dokumen + keadaan berikutnya | `actions.js:34` | ¼ |
| P0-5 | Sembunyikan akun demo di login bila `APP_ENV=production` (kirim flag lewat `GET core/settings/public` atau `meta` di `index.html`) | `app.js:136-143` | ¼ |
| P0-6 | `statusTone` per enum: `open` merah untuk NCR/insiden/defect | `format.js:129`, `enums.js` | ¼ |
| P0-7 | Strip status di halaman dokumen (5 kalimat, data dari `record.approvals`) | `detail.js` setelah `page-head` | 1 |

### P1 — setelah minggu 1 riset (log rute) (total ≈ 6 hari)

| # | Pekerjaan | Usaha |
|---|---|---|
| P1-1 | Endpoint `core/inbox` (semua `ApprovableDocuments` berstatus submitted yang boleh disetujui pemanggil, minus milik sendiri) + layar Tugas Saya + kartu dasbor menjadi cuplikan 5 + "Lihat semua" | 2 |
| P1-2 | Catatan persetujuan inline (bukan modal) + strip "Berikutnya" setelah memutus | 1 |
| P1-3 | Sidebar: Terakhir dibuka + Favorit; grup tertutup bawaan; pemisah dalam grup; `NAV` masuk sumber Ctrl+K | 1½ |
| P1-4 | Zonasi bilah aksi dokumen; tombol **Cetak ▾** menampung PDF + formulir rumah | 1 |
| P1-5 | Ganti kata sandi mandiri | ½ |

### P2 — setelah riset selesai (bergantung H1/H2) (total ≈ 8 hari)

| # | Pekerjaan | Usaha |
|---|---|---|
| P2-1 | Beranda per peran (5 susunan) | 3 |
| P2-2 | Formulir ber-`lines` sebagai halaman penuh + Enter/Ctrl+V di tabel baris | 3 |
| P2-3 | Setujui terpilih dengan ambang nilai | 1 |
| P2-4 | Lapangan: kemajuan unggah + antrean kirim ulang; tombol Lapangan di header ponsel | 1 |

**Yang sengaja tidak dijadwalkan**: workspace proyek sebagai pintu utama (§2.3
Tahap 2 poin 2) — menunggu H2; bila card sort tidak mendukungnya, tidak dibangun.

---

## Bagian 8 — Bahan komunikasi internal

### 8.1 Pembaruan 3P (untuk pemilik & tim, minggu 1–5 September 2026)

🧭 **Nusantara ERP — UX** (1–5 Sep 2026)
**Progress:** Asesmen UX lapis kedua selesai — 19 temuan di atas 77 temuan Bagian C
yang sudah ditutup, dengan 5 prioritas P0 (≈4 hari) yang tidak menunggu riset:
pesan validasi Indonesia (178 formulir), draf lokal anti-sesi-habis, kontras
token, strip status dokumen, toast bernomor.
**Plans:** Minggu depan: kerjakan P0 sekaligus pasang log rute 7 hari (R1); rekrut 8
peserta lintas peran untuk wawancara minggu ke-2 dan uji kegunaan minggu ke-3.
**Problems:** Tiga keputusan besar (Tugas Saya, beranda per peran, formulir halaman
penuh) bergantung pada data pemakaian yang hari ini belum ada — jangan bangun
sebelum log minggu 1 dibaca. Butuh nama 8 pengguna sungguhan dan izin merekam sesi.

### 8.2 FAQ singkat untuk pengguna (dikirim bersama rilis P0)

**Mengapa pesan galat sekarang berbahasa Indonesia?** Karena seharusnya sejak awal.
Kalau masih ada yang Inggris, itu bug — laporkan dengan nama layarnya.

**Isian saya hilang saat sesi berakhir. Masih begitu?** Tidak lagi. Sejak rilis ini,
formulir yang sedang terbuka disimpan di peramban Anda setiap 10 detik. Setelah masuk
kembali, Anda ditawari memulihkannya. Ini hanya berlaku di peramban dan komputer
yang sama.

**Tombol Ubah hilang dari dokumen saya.** Baca kalimat di bawah judul dokumen — ia
menyebut status, siapa yang sedang memegangnya, dan apa yang harus Anda lakukan.

**Warna NCR terbuka berubah jadi merah. Kenapa?** Karena NCR terbuka memang menahan
BAST — merah adalah warnanya yang jujur. Tiket layanan terbuka tetap hijau.

**Akun demo hilang dari halaman masuk.** Di server produksi memang tidak boleh ada.
Di staging tetap ada.

### 8.3 Pesan singkat untuk grup kerja (Slack/WhatsApp)

> Asesmen UX lapis kedua sudah di `docs/ASESMEN-UX-2026-09.md`. Ringkasannya:
> layar-layarnya sudah benar; yang belum adalah jalur di antara layar — pekerjaan
> masuk lewat 3 pintu, menu 121 baris, dan isian hilang saat sesi habis. Ada 7
> pekerjaan P0 (≈4 hari) yang bisa mulai Senin tanpa menunggu apa pun, dan rencana
> riset 3 minggu sebelum keputusan yang lebih besar. Saya butuh nama 8 pengguna
> lintas peran untuk wawancara minggu depan.

---

## Bagian 9 — Data yang belum diketahui, dan hipotesis kerja

| Yang tidak diketahui | Mengapa penting | Hipotesis sementara | Cara mengetahuinya |
|---|---|---|---|
| Frekuensi buka per peran | Menentukan apakah 4.1 atau 4.2 yang paling berharga | Penyetuju paling sering (H1), karena struktur maker-checker mengalirkan semua ke sedikit orang | Log rute minggu 1 |
| Berapa pengguna sungguhan hari ini | Menentukan skala riset dan apakah onboarding penting | 10–30 akun aktif (kontraktor 50–200 orang, seed 11 peran) | `iam_users.last_login_at` bila ada; kalau tidak, log |
| Perangkat lapangan (Android? versi Chrome?) | `type=date`, kamera, GPS, base64 5 MB berperilaku berbeda | Android 10–13, Chrome 110+, jaringan 4G tidak stabil | User-Agent di log |
| Apakah GARIS / sistem desain lain dimaksudkan untuk trunk ini | Menentukan rujukan konsistensi §1.4 | Tidak — `app.css` adalah sistem desainnya sendiri, font sistem, dan tidak ada rujukan lain di repo | Konfirmasi pemilik |
| Jumlah dokumen `submitted` yang menunggu > 7 hari per jenis | Membuktikan (atau membantah) biaya "tiga pintu" | Ada, terutama di 17 jenis yang tidak masuk kartu dasbor | Satu kueri SQL atas `core_approvals` |

---

## Catatan penutup

Asesmen ini sengaja tidak menyentuh apa yang sudah benar. Kalau hanya satu kalimat
yang dibawa dari dokumen ini: **kode ini sudah tahu apa yang menunggu siapa, di mana
dokumen terkunci, dan mengapa — yang belum dilakukannya adalah mengatakannya di
layar, di tempat orang melihat, dalam bahasa yang dipakai orang itu.** Tujuh
pekerjaan P0 adalah cara termurah untuk mulai mengatakannya.
