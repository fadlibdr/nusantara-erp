# Panduan Pengguna — Nusantara ERP

**PT Nusantara Karya Integrasi** · untuk orang yang memakai ERP ini untuk mengerjakan
pekerjaannya: sales, estimator, pengadaan, gudang, lapangan, proyek, keuangan, SDM,
teknisi.

> Panduan ini menjelaskan **cara mengerjakan pekerjaan Anda di layar** — langkah demi
> langkah, dengan nama tombol persis seperti yang tertulis di layar Anda.
>
> Ia **bukan** panduan administrator. Segala hal tentang membuat pengguna, memberi
> peran, mengubah pengaturan, menutup buku, mencadangkan basis data, dan menjalankan
> perintah di server ada di **`docs/PANDUAN-ADMINISTRATOR.md`**. Bila panduan ini
> berkata "minta administrator", di sanalah orang itu membaca apa yang harus ia
> lakukan.
>
> **Aturan yang mengikat halaman ini** — sama dengan aturan yang mengikat formulir
> cetak dan panduan administrator: yang ditulis di sini hanyalah **apa yang benar-benar
> ada di layar dan benar-benar dilakukan sistem**. Tidak ada tombol yang "nanti ada",
> tidak ada kolom yang "seharusnya". Bila sebuah pekerjaan tidak bisa diselesaikan dari
> layar, panduan ini mengatakannya dan menyebut apa yang harus Anda lakukan sebagai
> gantinya. Panduan yang menjanjikan tombol yang tidak ada lebih buruk daripada halaman
> yang hilang, karena pembacanya percaya justru pada saat ia sedang buntu.

---

## Daftar isi

0. [**Rujukan cepat per peran**](#0-rujukan-cepat-per-peran) — bab mana yang Anda baca
1. [Memulai](#1-memulai)
2. [Hal yang sama di setiap layar](#2-hal-yang-sama-di-setiap-layar)
3. [Penawaran sampai penagihan](#3-penawaran-sampai-penagihan)
4. [Estimasi — BOQ, AHSP, RAP](#4-estimasi--boq-ahsp-rap)
5. [Permintaan sampai pembayaran](#5-permintaan-sampai-pembayaran)
6. [Gudang & persediaan](#6-gudang--persediaan)
7. [Pelaksanaan proyek di lapangan](#7-pelaksanaan-proyek-di-lapangan)
8. [Subkontraktor](#8-subkontraktor)
9. [Aset & alat berat](#9-aset--alat-berat)
10. [Keuangan harian](#10-keuangan-harian)
11. [SDM](#11-sdm)
12. [Layanan & pemeliharaan](#12-layanan--pemeliharaan)
13. [Mencetak formulir rumah](#13-mencetak-formulir-rumah)
14. [Yang tidak bisa Anda lakukan sendiri][bab14]
15. [Persetujuan oleh Pemilik/MK](#15-persetujuan-oleh-pemilikmk)
16. [Engineering — gambar, submittal, IPP](#16-engineering--gambar-submittal-ipp)
17. [Mutu — inspeksi, NCR, benda uji beton](#17-mutu--inspeksi-ncr-benda-uji-beton)

[bab14]: #14-yang-tidak-bisa-anda-lakukan-sendiri--dan-siapa-yang-bisa

---

## 0. Rujukan cepat per peran

Cari baris pekerjaan Anda. Baca bab yang disebut, dan berhentilah di situ — sisa
panduan ini milik orang lain.

| Saya seorang… | Peran akun | Baca bab |
|---|---|---|
| Sales / pemasaran | `sales` | 1, 2, **3** (berhenti di §3.9 — penagihan bukan pekerjaan Anda) |
| Estimator / drafter | `estimator` | 1, 2, **4**, **16** (Anda drafter yang mendaftarkan gambar & menyiapkan submittal — bab 16) |
| Petugas pengadaan / pembelian | `procurement` | 1, 2, **5** (+ §16.4 — baca daftar SMS sebelum membeli material yang belum disetujui MK) |
| Penjaga gudang / logistik | `warehouse` | 1, 2, **6** (+ §16.5 — bon bisa menunjuk IPP) |
| Manajer proyek | `project-manager` | 1, 2, **7**, **8**, **16**, **17** (+ §4.4 RAP, bab 9 bila memegang alat, **15** bila meminta tanda tangan MK/Owner; bab 17 — Anda yang menyetujui inspeksi & memverifikasi NCR) |
| Site manager / pengawas lapangan | `site-manager` | 1, 2, **7** (§7.3–§7.6 saja; + §9.5 — Anda salah satu pencatat log BBM & jam alat; + **16** — Anda yang mengajukan IPP; + **17** — inspeksi mutu, NCR, & benda uji di lokasi Anda) |
| Petugas keuangan (AR, AP, kas) | `finance` | 1, 2, **10** (+ §3.10–§3.14 penagihan, §5.9–§5.10 bayar vendor) |
| Manajer keuangan / direktur | `finance-manager`, `direktur` | 1, 2, **14**, lalu bab dokumen yang Anda setujui (+ **15** untuk tanda tangan MK/Owner) |
| Kasir kas kecil | peran khusus (§14) | 1, 2, **§10.5–§10.7** |
| Petugas SDM / payroll | `hr` | 1, 2, **11** |
| Teknisi servis | `teknisi` | 1, 2, **12** (+ layar Lapangan lewat alamat `#/lapangan` — §7.4; sejak 22 Agu 2026 peran Anda memegang izin posting stok — §6.1) |
| Admin alat berat / aset | `project-manager` atau admin | 1, 2, **9** |

**Enam kalimat yang berlaku untuk semua orang**, apa pun peran Anda:

1. **Menu yang tidak Anda punya izinnya tidak abu-abu — ia tidak ada.** Sidebar Anda
   lebih pendek daripada sidebar rekan Anda, dan itu normal.
2. **Tombol `Ubah` dan `Hapus` menghilang tanpa pesan** begitu dokumen keluar dari
   status Draf atau Ditolak. Jalan kembali ke bisa-diubah adalah tombol **`Tolak`**,
   bukan tombol Ubah yang tidak akan muncul lagi sendiri.
3. **Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri.** Itu aturan, bukan
   kerusakan; pesan penolakannya menyebut nama orang yang harus Anda datangi.
4. **Tidak ada tombol batal untuk apa pun yang sudah diposting.** Yang ada hanyalah
   pembalikan, dan pembalikan meninggalkan dua jurnal berdampingan selamanya.
5. **Anda tidak bisa mengganti kata sandi sendiri.** Tidak ada layar untuk itu dan
   tidak ada tautan "lupa kata sandi". Minta administrator (bab 14).
6. **Sesi Anda berumur 12 jam.** Bila habis di tengah formulir, seluruh isian hilang
   tanpa bisa dipulihkan. Dokumen panjang disimpan bertahap.

---

## 1. Memulai

### 1.1 Masuk

1. Buka **https://erp1.pi2.co.id**. Halaman pertama berjudul **"Masuk ke akun Anda"**
   dengan kalimat *"Gunakan email dan kata sandi yang diberikan administrator."*
2. Isi **Email** dan **Kata sandi**. Keduanya wajib.
3. Tekan **`Masuk`**.

Yang bisa Anda temui:

| Yang tampil | Artinya | Yang harus Anda lakukan |
|---|---|---|
| `Wajib diisi.` di bawah sebuah kolom | Kolom itu kosong; permintaan belum dikirim ke server | Isi kolomnya |
| `Email atau password salah.` | Salah satu dari keduanya salah | Periksa ejaan email; sandi hanya bisa direset administrator |
| `Akun Anda dinonaktifkan. Hubungi administrator.` | Akun ada tetapi dimatikan | Hubungi administrator — Anda tidak bisa mengaktifkannya sendiri |
| `Tidak dapat terhubung ke server.` | Jaringan atau server | Coba lagi; bila berulang, laporkan |
| `Terlalu banyak permintaan — coba lagi sebentar lagi.` | Terlalu banyak percobaan (batas 10 kali per menit) | Tunggu satu menit |

**Blok "Akun demo" di halaman masuk.** Halaman masuk memuat daftar empat alamat contoh
beserta kata sandinya. Itu bawaan pemasangan, bukan akun Anda, dan bukan undangan untuk
dipakai. Bila blok itu masih ada di instalasi perusahaan Anda, sebutkan kepada
administrator — penanganannya ada di `docs/PANDUAN-ADMINISTRATOR.md` §5.9 dan §12(a).

### 1.2 Sesi — 12 jam, dan apa yang terjadi saat berakhir

Sesi Anda berlaku **12 jam** sejak masuk, dan tersimpan di peramban ini saja. Masuk di
komputer lain adalah sesi lain.

Begitu sesi berakhir — atau bila administrator mencabut token Anda — layar apa pun yang
sedang terbuka **langsung berganti menjadi halaman masuk** dengan banner
*"Sesi Anda berakhir. Silakan masuk kembali."*

**Apa pun yang sedang Anda ketik pada saat itu hilang.** Tidak ada draf otomatis dan
tidak ada pemulihan. Bila Anda sedang menyusun BOQ 40 baris atau kontrak dengan sepuluh
termin, tekan **`Simpan`** setiap kali satu bagian selesai, lalu buka lagi lewat
**`Ubah`**.

Bila server tidak terjangkau saat membuka aplikasi, banner-nya berbunyi
*"Tidak dapat menghubungi server. Coba masuk kembali."*

### 1.3 Keluar

Klik nama Anda di pojok kanan atas → dialog **Akun** → tombol **`Keluar`**. Halaman
masuk muncul dengan pesan *"Anda telah keluar."*

Keluar hanya mencabut sesi **di peramban ini**. Tidak ada daftar sesi aktif dan tidak
ada tombol "keluarkan saya dari semua perangkat"; itu pekerjaan administrator
(`docs/PANDUAN-ADMINISTRATOR.md` §3.5).

### 1.4 Tampilan — apa yang ada di layar

**Bilah atas**, dari kiri ke kanan: ikon **Menu** (hanya berguna di layar sempit —
membuka/menutup sidebar), remah roti (mis. `Keuangan › Invoice Termin (AR) › INV/…`),
tombol **`Cari`**, tombol tema, ikon lonceng, dan kotak akun berisi inisial, nama, dan
peran Anda (atau "tanpa peran").

**Sidebar** berisi dua belas kelompok. Setiap judul kelompok bisa diklik untuk melipat
isinya, dan keadaan lipatan itu diingat peramban Anda.

| Kelompok | Isinya |
|---|---|
| Ringkasan | Dasbor · Tenggat · Kalender |
| Penjualan | Pelanggan · Prospek · Penawaran · Kontrak · Pekerjaan Tambah-Kurang · Analitik Win-Rate · Jaminan & Asuransi |
| Estimasi | AHSP · BOQ / RAB · RAP · Riwayat Harga Satuan |
| Proyek | Daftar Proyek · Laporan Harian · Lapangan (mobile) · Progres Mingguan · EVM & Baseline · Milestone · BAST · Izin Kerja (IKL) · Izin Lembur (ILB) · Izin Material (IMK) · Register K3 (SMK3) · Laporan K3 · Register Defect (Punch List) · Varian Material · Penugasan Personel |
| Pengadaan | Vendor & Subkon · Dokumen Vendor · Permintaan (PR) · RFQ (Banding Penawaran) · Pesanan (PO) · Baris PO Terbuka · Evaluasi Vendor |
| Persediaan | Saldo Stok · Item · Kategori Item · Gudang · Penerimaan (GRN) · Pengeluaran · Transfer · Opname |
| Subkontrak | SPK Subkon · Addendum SPK · Opname Subkon |
| Keuangan | Invoice Termin (AR) · Tagihan Vendor (AP) · Pembayaran · Kasir Kas Kecil · Kas Kecil & Kasbon · Jurnal · Biaya Proyek · Termin Siap Ditagih · Piutang Retensi · Pengakuan Pendapatan · Periode Fiskal · Laporan Keuangan · Buku Besar · Ekspor Pajak · Kalender Pajak · Ekualisasi Pajak · Rekonsiliasi Bank · Bagan Akun · Pajak · Rekening Bank |
| SDM & Payroll | Karyawan · Sertifikat & PKWT · Cuti & Izin · Absensi Harian · Rekap Absensi · Payroll |
| Layanan | Tiket · Tiket Lewat SLA · Kontrak Layanan · Jadwal Preventif · Berita Acara |
| Aset | Daftar Aset · Kategori Aset · Mobilisasi · Log BBM & Jam Alat · Perawatan · Penyusutan · Utilisasi Aset |
| Sistem | Pengguna · Peran & Hak Akses · Profil Perusahaan · Impor Data Master · Impor Dokumen · Pengaturan |

**Kelompok yang izinnya tidak Anda pegang tidak digambar sama sekali.** Sidebar seorang
petugas gudang tidak punya kelompok Keuangan; sidebar sales tidak punya kelompok
Persediaan. Itu bukan kerusakan.

Perkecualiannya tiga baris yang punya izinnya sendiri: **Impor Data Master** dan
**Impor Dokumen** di kelompok **Sistem**, dan **Log BBM & Jam Alat** di kelompok
**Aset**. Itu sebabnya seorang estimator bisa melihat kelompok "Sistem" yang hanya
berisi satu baris **Impor Dokumen**, dan seorang site manager melihat kelompok "Aset"
yang hanya berisi **Log BBM & Jam Alat** — masing-masing memang satu-satunya baris
kelompok itu yang boleh mereka buka.

**Tema.** Tombol matahari/bulan berputar antara *mengikuti sistem* → *terang* → *gelap*,
dan memberi tahu pilihannya lewat notifikasi kecil. Pilihan tema, lipatan sidebar, dan
jumlah baris per halaman **disimpan di peramban ini**, bukan di akun Anda — komputer lain
mulai dari bawaan lagi.

**Dialog Akun** (klik kotak nama): memperlihatkan nama, email, **Peran**, dan
**Hak akses** sebagai *jumlahnya saja* (mis. `31 izin`). Daftar izinnya tidak
diperlihatkan di mana pun. Tombolnya hanya **`Tutup`** dan **`Keluar`** — tidak ada
ganti sandi, ganti email, foto, atau pengaturan pemberitahuan.

**Halaman yang salah alamat.** Alamat yang tidak dikenal menghasilkan
`Halaman "…" tidak ditemukan.` dengan tombol **`Ke dasbor`**. Layar yang izinnya tidak
Anda pegang menghasilkan `Anda tidak memiliki hak akses "<modul>.view" untuk halaman ini.`

### 1.5 Pencarian

1. Tekan **Ctrl+K** (atau **Cmd+K** di Mac), atau klik **`Cari`** di bilah atas.
2. Ketik **minimal 2 huruf** — kode dokumen, nama, atau nomor.
3. Tekan **Enter** untuk membuka hasil pertama, atau klik hasil yang Anda mau.

Yang dicari hanyalah **kode dokumen** dan **satu kolom nama/judul** per jenis dokumen,
maksimal **5 hasil per kelompok**. Kelompok yang dicakup: Proyek · Pelanggan · Kontrak ·
Penawaran · Vendor · Pesanan Pembelian · Permintaan Pembelian · Item · Karyawan · Aset ·
Invoice AR · Tagihan Vendor AP · Pembayaran · SPK Subkon · Tiket Layanan · Insiden K3.

Yang **tidak** dicari: uraian, catatan, isi aktivitas, isi lampiran, dan seluruh jenis
dokumen di luar keenam belas itu — prospek, BOQ, RAP, AHSP, jurnal, GRN, bon
pengeluaran, transfer, BAST, laporan harian, cuti, absensi.

Kelompok yang izinnya tidak Anda pegang **tidak ikut dicari sama sekali**, jadi
"tidak ada hasil" dan "ada tetapi bukan hak Anda" terlihat sama.

**Ctrl+K ditolak selama ada jendela dialog terbuka**, dengan pesan
*"Tutup dulu jendela yang terbuka — hasil pencarian akan pindah halaman."* Itu
disengaja: satu klik hasil akan memindahkan halaman di belakang formulir yang sedang
Anda isi. Tekan Escape atau **`Batal`** dulu.

### 1.6 Lonceng pemberitahuan

Ikon lonceng menampilkan angka merah pemberitahuan yang belum dibaca (berhenti di
`99+`). Klik untuk membuka dialog **Pemberitahuan** berisi **50 terbaru**.

Setiap baris membawa lencana **Menunggu** (kuning) / **Disetujui** (hijau) / **Ditolak**
(merah), judul, isi, umur relatif, dan sampai dua tombol: **`Buka dokumen`** (menandai
terbaca lalu pindah ke dokumennya) dan **`Tandai dibaca`**. Di kaki dialog ada
**`Tandai semua dibaca`** dan **`Tutup`**.

Yang perlu Anda ketahui tentang lonceng:

- **Ia diperiksa setiap 90 detik, dan hanya selama tab ini terlihat.** Tab yang
  ditinggalkan di latar belakang menampilkan angka beku. Muat ulang halaman sebelum
  memercayai angka nol.
- **Membaca pemberitahuan tidak menyelesaikan apa pun.** Dokumennya tetap menunggu.
- **Menyetujui dokumen tidak menghapus pemberitahuan "menunggu" di kotak masuk penyetuju
  lain.** Mereka masih akan melihatnya sampai mereka sendiri menandainya terbaca.
- Alarm sistem (cadangan basi, tutup buku telat, tenggat harian) muncul dengan lencana
  kosong **"—"**, jadi tampilannya berbeda dari tiga jenis pemberitahuan dokumen di
  sekitarnya.
- **Email mati secara bawaan, dan WhatsApp tidak ada.** Bila perusahaan Anda ingin
  pemberitahuan dikirim lewat email, itu keputusan administrator.

### 1.7 Tiga pintu tempat pekerjaan sampai kepada Anda

Ketiganya menjawab pertanyaan yang berbeda. Pakailah ketiganya.

**a. Dasbor → kartu "Menunggu persetujuan Anda"** — kotak masuk persetujuan. Berisi
dokumen berstatus **Diajukan** yang boleh Anda setujui, terbaru di atas, **paling banyak
10 baris**. Klik baris untuk membukanya.

Kartu ini hanya mencakup **11 jenis dokumen**: Penawaran, BOQ/RAB, RAP, Permintaan (PR),
Pesanan (PO), SPK subkon, Opname subkon, Opname stok, Invoice termin, Tagihan vendor,
Payroll. **Tidak** termasuk: pembayaran, pekerjaan tambah-kurang, BAST, addendum SPK,
baseline proyek, pengajuan cuti, dan ketiga izin lapangan (IKL/ILB/IMK, §7.13) —
kesembilannya hanya sampai lewat lonceng dan lewat layar daftarnya sendiri yang
disaring ke status **Diajukan**.

Bila salah satu sumber gagal dimuat, kartu berkata *"Tidak ada dokumen yang dapat
ditampilkan"* dan menyebut sumber yang gagal. **Daftar yang pendek bukan bukti tidak ada
yang menunggu.**

**b. Lonceng** — memberi tahu bahwa sesuatu *terjadi*. Ia menjadi basi.

**c. Ringkasan → Tenggat** — memberi tahu bahwa sesuatu *masih salah*. Layar ini
menghitung ulang setiap kali dibuka, jadi tenggat yang belum dibereskan tetap tampil
walau pemberitahuannya sudah lama ditandai terbaca. Tiga golongan: **Lewat** (merah) ·
**Menipis** (kuning) · **Tanpa tanggal** (merah), dengan tombol **`Buka`** per baris.

Baris hilang dari Tenggat **hanya ketika sebabnya dibereskan** — penawaran ditandai
menang/kalah, PO ditutup, jaminan diubah statusnya, sertifikat diperpanjang, tanggal
PKWT diisi. Tidak ketika Anda membacanya.

Yang diawasi Tenggat, dan siapa yang melihatnya:

| Yang diawasi | Diperingatkan | Terlihat oleh pemegang |
|---|---|---|
| Penawaran mendekati akhir masa berlaku | 14 hari | `crm.update` |
| Kontrak mendekati tanggal berakhir | 30 hari | `crm.approve` |
| Termin kontrak mendekati rencana tagih | 7 hari | `fin.create` |
| Jaminan & asuransi mendekati berakhir | 30 hari | `crm.approve` |
| Retensi BAST mendekati jadwal pengembalian | 14 hari | `fin.create` |
| Invoice pelanggan lewat jatuh tempo | 0 hari | `fin.create` |
| Setoran pajak masa | 7 hari | `fin.create` |
| Permintaan pembelian mendekati tanggal dibutuhkan | 7 hari | `prc.create` |
| Pesanan pembelian lewat tanggal terima | 0 hari | `prc.update` |
| Dokumen vendor mendekati akhir masa berlaku | 30 hari | `prc.update` |
| SPK subkontraktor mendekati tanggal selesai | 14 hari | `scm.update` |
| Milestone proyek | 7 hari | `prj.update` |
| Tindak lanjut insiden K3 | 3 hari | `prj.update` |
| Servis aset berikutnya | 14 hari | `ast.update` |
| Penempatan aset melewati rencana kembali | 7 hari | `ast.update` |
| Kontrak layanan mendekati akhir periode | 60 hari | `crm.update` |
| PKWT karyawan | 60 hari | `hr.update` |
| Sertifikat keahlian | 60 hari | `hr.update` |

Pemindaian yang sama berjalan otomatis setiap hari pukul **08.30 WIB** dan mengirim
pemberitahuan. Yang menipis diingatkan lagi paling sering seminggu sekali; yang sudah
lewat paling sering tiga hari sekali.

**Ringkasan → Kalender** adalah saudaranya: *apa yang terjadi kapan*, satu bulan sekali
tampil, dengan panah bulan, tombol **`Hari ini`**, dan warna per departemen. Klik satu
kotak tanggal untuk melihat agendanya. Bila satu bulan memuat lebih banyak agenda
daripada yang muat, layar mengatakannya.

---

## 2. Hal yang sama di setiap layar

Bab ini berlaku di hampir semua layar. Bab-bab berikutnya hanya menyebut yang berbeda.

### 2.1 Layar daftar

Susunannya dari atas ke bawah:

1. **Judul** = nama di sidebar. Di kanan atas: tombol aksi tingkat halaman (bila ada),
   ikon **Muat ulang**, dan **`Tambah <nama dokumen>`** — yang terakhir hanya muncul
   bila Anda memegang izin membuat.
2. **Bilah saring**: kotak **Cari** (bekerja 320 milidetik setelah Anda berhenti
   mengetik), saringan khusus layar itu, sepasang **Dari tanggal** / **Sampai tanggal**
   (hanya pada layar yang punya kolom tanggal dokumen — tidak semua punya), tombol
   **`Reset`** (muncul hanya bila ada saringan aktif), dan **`Ekspor CSV`**.
3. **Tabel**. Kolom uang mendapat baris kaki berlabel **"Total halaman ini"**.
4. **Pager**: `Menampilkan 1–20 dari 137 data`, pemilih jumlah baris (20 bawaan, lalu
   25/50/100/200), nomor halaman, dan panah maju-mundur.

**"Total halaman ini" adalah jumlah baris yang sedang tampil — bukan jumlah seluruh
saringan.** Ubah ke 100 baris per halaman dan angkanya berubah. Satu-satunya total yang
jujur atas seluruh saringan adalah berkas CSV.

**Mengurutkan.** Judul kolom bisa diklik hanya bila server mengizinkan kolom itu
diurutkan. Klik berputar: **naik ▲ → turun ▼ → kembali ke urutan bawaan**. Mengubah
urutan mengembalikan Anda ke halaman 1.

**Membuka baris.** Klik baris untuk membuka halaman dokumennya. Beberapa layar memang
tidak punya halaman dokumen (mis. Bagan Akun, Progres Mingguan, Rekap Absensi); di sana
baris tidak bisa diklik dan yang tersedia hanya ikon di ujung kanan baris.

**Dengan papan ketik**: Tab masuk ke tabel sekali, lalu ↑/↓ berpindah baris, Home/End ke
ujung, Enter atau spasi membuka baris.

**Ikon di ujung kanan baris**: ikon printer (hanya pada layar tanpa halaman dokumen),
pensil **Ubah**, tong sampah **Hapus**. Keduanya hanya muncul bila izin Anda
mengizinkan **dan** status barisnya mengizinkan.

**Menghapus.** Konfirmasinya berbunyi `Hapus "<kode>"? Tindakan ini tidak dapat
dibatalkan.` — dan itu apa adanya. **Tidak ada tempat sampah, tidak ada pemulihan, tidak
ada undelete di seluruh aplikasi.**

**Alamat yang bisa dibagikan.** Begitu Anda mencari, menyaring, mengurutkan, atau
berpindah halaman, alamat di bilah peramban ikut berubah, mis.
`#/r/finance/ar-invoices?page=2&q=PLN&status=submitted&sort=due_date&dir=asc`. Salin
alamat itu dan rekan Anda melihat pemandangan yang sama — **selama izinnya sama**.
Rekan dengan izin lebih sempit mendapat daftar yang lebih pendek, dan tidak seorang pun
diberi tahu.

**Kotak cari yang tidak melakukan apa-apa.** Kotak Cari digambar di setiap daftar,
tetapi lima layar mengabaikannya — mengetik di sana tidak menyaring apa pun:
**Progres Mingguan**, **Evaluasi Vendor**, **Penyusutan**, **Biaya Proyek**,
**Pengakuan Pendapatan**. Pakai saringan atau rentang tanggalnya.

**Keadaan kosong.** Belum ada apa-apa → *"Belum ada <label> yang tercatat."* dengan
tombol Tambah bila Anda boleh membuat. Tersaring habis → *"Tidak ada data yang cocok
dengan pencarian atau filter."* — tekan **`Reset`**.

### 2.2 Ekspor CSV

Tombol **`Ekspor CSV`** ada di setiap layar daftar dan **tidak butuh izin tambahan**:
kalau Anda boleh melihat daftarnya, Anda boleh mengekspornya.

Yang perlu diketahui sebelum menekannya:

- **Ia mengekspor seluruh hasil saringan, semua halaman** — bukan hanya 20 baris yang
  tampak, dan bukan pula seluruh tabel tanpa saringan.
- **Kolomnya persis kolom yang tampil di layar itu**, dengan judul yang sama. Bukan
  seluruh kolom dokumen.
- Nilainya berbentuk data, bukan tampilan: angka mentah tanpa "Rp", tanggal
  `yyyy-mm-dd`, waktu `yyyy-mm-dd hh:mm`, ya/tidak sebagai `Ya`/`Tidak`.
- Nama berkas: `<nama-layar>_yyyy-mm-dd.csv`.
- Di atas **1.000 baris** ia bertanya dulu: *"Akan mengunduh N baris dalam beberapa
  permintaan. Lanjutkan?"* Di atas **10.000 baris** ia menolak: *"Terlalu banyak baris
  (N). Persempit dengan filter tanggal dulu."*
- Bila satu halaman gagal diambil di tengah jalan, **seluruh ekspor dibatalkan**.
  Berkas yang terpotong diam-diam tidak akan pernah ada.

**Berkasnya memakai titik koma (`;`) sebagai pemisah kolom dan koma sebagai desimal** —
bentuk yang dibaca Excel dengan setelan wilayah Indonesia. Bila Excel Anda diset bahasa
Inggris, berkas terbuka sebagai satu kolom. Perbaikannya: buka Excel dulu, lalu
**Data → Text to Columns**, pilih *Delimited* → *Semicolon*; atau ubah pemisah pada
setelan wilayah Windows Anda.

### 2.3 Formulir

Formulir terbuka sebagai jendela berjudul **Tambah <dokumen>** atau **Ubah <dokumen>**,
dibagi menjadi beberapa bagian berjudul. Kolom wajib bertanda `*` merah; keterangan abu
di bawah kolom adalah bantuan resmi layar itu — bacalah, ia sering menyimpan aturan yang
tidak ada di tempat lain.

Tombolnya **`Batal`** dan **`Simpan`** (**`Simpan Perubahan`** saat mengubah).

**Yang diperiksa peramban sebelum mengirim**: hanya kolom wajib yang kosong. Masing-masing
ditandai `Wajib diisi.`, muncul pesan **"Periksa isian yang ditandai."**, dan layar
menggulir ke kolom pertama yang bermasalah.

**Yang diperiksa server** ditandai langsung pada kolom yang disebutnya. Pada tabel baris,
kesalahan mendarat pada **sel baris yang bersangkutan**, berwarna merah, dan barisnya
digulirkan ke tengah layar. Tanda merah hilang begitu Anda mengetik ulang di sel itu.

**Penjaga isian belum tersimpan.** Escape, klik di luar, tombol **×**, dan **`Batal`**
semuanya bertanya lebih dulu: judul **"Tutup tanpa menyimpan?"**, isi *"Formulir ini
punya isian yang belum tersimpan — termasuk baris yang sudah diketik. Kalau ditutup
sekarang, semuanya hilang."*, tombol **`Buang isian`** / **`Kembali mengisi`**.

**Kolom pencarian (lookup)** — setiap kolom yang menunjuk data lain (proyek, vendor,
item, karyawan, akun): ketik untuk menyaring, ↑/↓ berpindah, Enter atau Tab mengambil
baris yang tersorot, **Escape menutup daftarnya saja — Escape kedua baru sampai ke
formulir**, tanda `×` atau baris `—` mengosongkan kolom yang tidak wajib.

Ia menampilkan **paling banyak 50 baris sekaligus**, dengan kaki
*"Menampilkan 50 dari 2.000 — ketik untuk mempersempit."* **Nilai yang tidak ada di 50
baris pertama itu ada — Anda hanya perlu mengetiknya.** Mengetik bukan kemudahan, ia
mekanismenya.

Bacalah teks pengganti di kolom kosong sebelum menyimpulkan datanya tidak ada:

| Yang tertulis | Artinya |
|---|---|
| `Memuat…` | Sedang diambil |
| `Pilih…` / `—` | Siap, silakan pilih |
| `Belum ada data` | Memang belum ada barisnya |
| **`Tidak ada hak akses`** | Daftarnya ada, tetapi akun Anda tidak boleh membacanya |
| **`Gagal memuat`** | Gangguan jaringan/server — ada tombol **Coba lagi** |

Bila daftarnya lebih dari 10.000 baris, di bawah kolom muncul peringatan bahwa daftarnya
dipotong dan *"baris di bawah itu tidak muncul di sini"*. Nilai tersimpan yang barisnya
sudah hilang ditampilkan sebagai `#42 (tidak ada di daftar)` dan **tetap ikut tersimpan**
saat Anda menekan Simpan.

**Kolom rupiah** mengelompokkan angka sambil Anda mengetik (`15.000.000.000`). Ia tidak
bereaksi terhadap roda tetikus, dan Anda boleh menempel `Rp 15.000.000.000` langsung dari
portal bank.

**Tabel baris** (item, termin, entri jurnal): tombol **`Tambah baris`**, ikon tong sampah
per baris, dan **Subtotal** hidup di bawah tabel. Pada jurnal, kakinya menampilkan
**Debit / Kredit / Seimbang ✓** atau **Selisih Rp …**. Baris yang seluruhnya kosong tidak
ikut dikirim.

Beberapa tabel punya tombol salin — mis. **`Salin baris dari PO`** pada penerimaan barang.
Pada dokumen-dokumen itu, tombol salin bukan kemudahan: **hanya lewat tombol itu baris
Anda terhubung ke baris asalnya** (§6.4).

### 2.4 Halaman dokumen

Kepala halaman: kode dokumen sebagai judul, **lencana status** di sebelahnya, lalu baris
tombol: panah kembali, ikon **Cetak halaman**, tombol **`PDF`** pada empat dokumen yang
punya, satu tombol **`Cetak <nama formulir>`** per formulir rumah, **`Ubah`**, lalu
tombol-tombol siklus hidup.

Isi kolom kiri: kartu **Informasi** (semua kolom dokumen), lalu satu kartu per tabel
baris. Kolom kanan: **Terkait**, **Riwayat Persetujuan**, **Lampiran**, **Metadata**
(ID, Dibuat, Diperbarui).

**Warna lencana status:**

| Warna | Status |
|---|---|
| Hijau | Disetujui · Aktif · Terposting/Diposting · Diterima · Menang · Terselesaikan · Disahkan Pelanggan · Terbuka |
| Kuning | **Diajukan** · Dalam Perjalanan · Menunggu Pelanggan · Ditangguhkan · Dalam Perawatan · Berakhir |
| Merah | Ditolak · Dibatalkan · **Dibalik** · Kalah · Diputus · Nonaktif · Resign · Dihapusbukukan · Dicairkan |
| Biru | Dikerjakan · Ditugaskan · Termobilisasi · Masa Pemeliharaan · Finishing · Terkualifikasi · Penawaran Dikirim |
| Abu-abu | **Draf**, dan sisanya |

### 2.5 Persetujuan — tiga tombol yang sama di mana-mana

| Tombol | Muncul saat status | Butuh izin | Isian |
|---|---|---|---|
| **`Ajukan`** | Draf atau Ditolak | `<modul>.update` | tidak ada — langsung jalan |
| **`Setujui`** | Diajukan | `<modul>.approve` | *Catatan persetujuan* (opsional) |
| **`Tolak`** | Diajukan | `<modul>.approve` | *Alasan penolakan* (**wajib**) |

Notifikasi sukses selalu berbentuk `<Label tombol> berhasil.`

**Ditolak mengembalikan dokumen ke keadaan bisa diubah.** Penolakan bukan akhir dokumen —
ia adalah cara resmi membuka kembali dokumen yang sudah terlanjur diajukan.

**Kartu Riwayat Persetujuan** di kolom kanan menampilkan garis waktu Diajukan (kuning) /
Disetujui (hijau) / Ditolak (merah) beserta nama orang, waktu, dan catatannya. Dua
dokumen sengaja tidak punya kartu ini karena datanya memang tidak dikirim: **Jurnal**
dan **SPK Subkontraktor** — pada keduanya, siapa yang membuat dan siapa yang memposting
terbaca di kartu Informasi.

**Pemisahan tugas (maker-checker).** Orang yang terakhir menekan **`Ajukan`** tidak boleh
menekan **`Setujui`**, walau ia memegang izinnya. Penolakannya berbunyi kata demi kata:

> "{Dokumen} {KODE} diajukan oleh {Nama}; dokumen tidak boleh disetujui oleh pengajunya
> sendiri. Minta persetujuan pengguna lain pemegang izin {modul}.approve, atau matikan
> "Wajib pemisahan tugas" di Pengaturan → Proyek & Persetujuan bila perusahaan Anda
> memang tidak memiliki petugas kedua."

Yang dihitung sebagai pengaju adalah **penekan `Ajukan` yang terakhir**, bukan yang
mengetik dokumennya. Bila A mengajukan, B menolak, lalu B mengajukan ulang, maka **B**
yang terkunci — bukan A. Mematikan aturan itu adalah keputusan administrator
(`docs/PANDUAN-ADMINISTRATOR.md` §3.8).

**Menolak dokumen sendiri diperbolehkan.** Hanya menyetujui yang dijaga.

Pesan penolakan sepanjang itu **tetap tampil sampai Anda menutupnya** — notifikasi di
atas 160 karakter tidak punya penghitung waktu.

### 2.6 Ubah dan Hapus yang menghilang

Begitu dokumen keluar dari Draf/Ditolak, tombol **`Ubah`** dan **`Hapus`** **dihapus dari
layar tanpa pesan apa pun** — tidak abu-abu, tidak ada penjelasan.

| Jenis dokumen | Terkunci sejak status |
|---|---|
| Kebanyakan dokumen berpersetujuan | Diajukan |
| RFQ, GRN, Pengeluaran, Transfer, Retur | apa pun selain Draf |
| PO, Opname stok | apa pun selain Draf **atau Ditolak** |
| Kontrak | Disetujui (lewat `Aktifkan Kontrak`) |

Artinya pada GRN, bon pengeluaran, transfer, dan retur, **menekan `Posting` itu sendiri
adalah titik tanpa jalan kembali untuk penyuntingan.** Periksa dulu. PO dan opname stok
mengikuti aturan dokumen berpersetujuan: yang **Ditolak** tetap bisa diubah dan dihapus
(§5.6, §6.7).

Jalan kembali ke bisa-diubah adalah **`Tolak`** (pada dokumen berpersetujuan), bukan
menunggu tombol Ubah muncul lagi.

### 2.7 Lampiran

Kartu **Lampiran** hanya ada pada 33 jenis dokumen. Untuk melihatnya Anda butuh
`<modul>.view`; untuk **`Tambah lampiran`** dan **`Hapus`** Anda butuh `<modul>.update`.

Yang bisa berlampiran: penawaran · kontrak · jaminan · BOQ · RAP · proyek · laporan
harian · BAST · temuan defect · izin kerja lapangan · izin masuk/keluar material · PR ·
PO · vendor · dokumen vendor · penerimaan barang · opname stok · SPK subkon · opname
subkon · invoice AR · tagihan AP · pembayaran · jurnal · voucher kas kecil · kasbon ·
karyawan · sertifikat · cuti · tiket · berita acara servis · aset.

Yang **tidak** bisa: RFQ, evaluasi vendor, bon pengeluaran, transfer, kedua jenis retur,
insiden K3, milestone, progres mingguan, penugasan personel, izin kerja lembur (lembar
F/IL-nya mencetak baris pekerjanya sendiri — tidak ada foto yang perlu ditempel),
kontrak layanan, jadwal preventif, payroll, rekap absensi, kalender pajak, dan dana kas
kecil.

Yang diterima: PDF, gambar (jpg/png/webp/gif/heic), Word, Excel, PowerPoint
(pptx/ppt), CSV, teks, XML, gambar teknik AutoCAD (dwg/dxf), dan jadwal
MS Project (mpp).

Dua batas ukuran: **maksimal 5 MB per berkas**, kecuali **`.dwg`, `.dxf`, dan `.mpp`
yang boleh sampai 25 MB** — gambar kerja dan jadwal proyek memang sebesar itu. Peramban
menimbang lebih dulu dan menolak tanpa mengunggah: *"Berkas 30.1 MB melebihi batas
25.0 MB."* Yang lolos ditimbang ulang server, dengan batas menurut ekstensinya:
`Berkas berukuran 6.2 MB, melebihi batas 5 MB untuk berkas .pdf.`

Server memeriksa **isi berkas**, bukan namanya. Yang ditolak, dengan pesan persisnya:

- **Ekstensi di luar daftar:** `Jenis berkas ".zip" tidak diizinkan. Yang diterima:
  pdf, jpg, jpeg, png, webp, gif, heic, doc, docx, xls, xlsx, csv, txt, dwg, dxf, mpp,
  xml, pptx, ppt.` — `.svg`, HTML, dan semua format arsip sengaja tidak ada di daftar.
- **Isi tidak cocok dengan ekstensi:** `Isi berkas terbaca sebagai <mime>, tidak cocok
  dengan ekstensi ".<ext>". Berkas yang isinya berbeda dari namanya ditolak.` Kasus
  paling sering: berkas `.xlsx` yang diganti namanya menjadi `.csv`. Kasus baru yang
  penting: **DXF biner ditolak** (pesannya menyebut `application/octet-stream`) —
  hanya DXF **ASCII** yang diterima, jadi ekspor ulang dari CAD sebagai ASCII DXF.
- **`.xml` yang isinya HTML:** `Berkas .xml ini terlihat seperti dokumen HTML (diawali
  tag <script>), bukan data XML. Berkas HTML tidak diizinkan.` Bagian dalam kurung
  menyebut penanda HTML yang ditemukan di berkas Anda.
- **Nama tanpa ekstensi:** `Nama berkas tidak memiliki ekstensi, jadi jenisnya tidak
  dapat dipastikan.`

Menghapus lampiran permanen: *"Berkasnya dihapus dari penyimpanan dan tidak dapat
dikembalikan."*

### 2.8 Tiga tombol cetak yang berbeda

1. **Ikon printer "Cetak halaman"** — mencetak layar apa adanya lewat peramban, sidebar
   dan semuanya. Berguna untuk salinan cepat, bukan untuk dokumen yang ditandatangani.
2. **Tombol `PDF`** — hanya tiga dokumen menggambar tombol bernama `PDF`:
   **Invoice Termin (AR)**, **Pesanan Pembelian (PO)**, dan **BAST**. Berkas terunduh.
   **Slip gaji** juga PDF sungguhan, tetapi tombolnya bukan `PDF` — ia ikon unduh per
   baris slip di halaman Payroll run (§11.6, §13.4).
3. **Tombol `Cetak <nama formulir>`** — 48 formulir rumah perusahaan. Bab 13.

### 2.9 Dua layar impor

Keduanya di kelompok **Sistem**, keduanya bekerja sama: **pratinjau dulu, simpan
kemudian — tidak ada yang tersimpan sebelum Anda melihat isinya.** Keduanya menerima
`.csv`, `.xlsx`, `.xls`, maksimal **5 MB dan 5.000 baris**.

**Mengimpor butuh izin tambah DAN izin ubah.** Dengan izin tambah saja Anda hanya bisa
mengunduh: *"Anda dapat mengunduh data ini, tetapi tidak mengimpornya. Impor memperbarui
baris yang sudah ada, sehingga memerlukan izin ubah selain izin tambah."*

**a. Sistem → Impor Data Master** (`#/master-data`) — tabel datar, satu baris satu
catatan. Empat jenis: **Item / Material**, **Vendor & Subkontraktor**, **Pelanggan**,
**Karyawan**. Anda hanya melihat yang boleh Anda baca.

Langkahnya:

1. Pilih tabelnya. Layar menuliskan seluruh kolom dan mana yang wajib.
2. Tekan **`Unduh template`**, atau lebih baik **`Ekspor data saat ini`** — *"Cara
   tercepat mengubah ribuan baris: ekspor, ubah di Excel, impor kembali."*
3. Pilih berkasnya. Muncul empat kotak angka — **Baris terbaca / Akan dibuat / Akan
   diperbarui / Ditolak** — dan tabel **Rincian baris** (200 baris pertama) dengan kolom
   Baris · Kode · Tindakan (**Buat baru** hijau / **Perbarui** kuning / **Ditolak**
   merah) · Catatan.
4. Tekan **`Simpan N baris`**. Baris yang ditolak dilewati; sisanya tetap tersimpan.
   Notifikasi: `X dibuat, Y diperbarui, Z dilewati.`

**Pencocokan memakai kolom `kode`.** Kode yang sudah ada **diperbarui, bukan
diduplikasi**.

**Kolom yang tidak ada di berkas Anda dibiarkan apa adanya; kolom yang ada tetapi
kosong ditulis sebagai kosong.** Perbedaan itulah yang memisahkan "lembar tambal aman"
dari "penghapusan massal". Bila Anda hanya ingin memperbaiki nomor rekening semua orang,
kirim berkas berisi `kode` dan `bank`/`no_rekening` saja.

Penolakan seluruh berkas, kata demi kata:
`Kolom wajib tidak ditemukan di berkas: <kolom>.` ·
`Baris judul kolom tidak ditemukan — pastikan ada satu baris berisi kolom <daftar>.` ·
`Format berkas tidak didukung. Gunakan .csv, .xlsx, atau .xls.` · `Berkas kosong.` ·
`Berkas melebihi 5.000 baris. Bagi menjadi beberapa berkas.` ·
`Isi berkas tidak dapat dibaca.`

Penolakan per baris: `<kolom>: wajib diisi.` · `<kolom>: "<nilai>" tidak ditemukan.` ·
`kode "<X>" muncul dua kali dalam berkas ini (baris N).`

Baris yang kolom `kode`-nya diawali `#` dibaca sebagai komentar dan dilewati.

**b. Sistem → Impor Dokumen** (`#/impor-dokumen`) — untuk dokumen berkepala dan berbaris.
Empat jenis: **Penawaran**, **BOQ / RAB**, **AHSP / Analisa Harga Satuan**,
**RAP / Anggaran Pelaksanaan**. Rinciannya di §3.5 dan §4.5.

Dua hal yang berlaku pada keduanya dan sering menjadi kerugian:

- **Memperbarui dokumen MENGGANTI seluruh barisnya.** Pratinjau menuliskannya dengan
  merah: `Menimpa dokumen berisi N baris senilai Rp X; berkas ini membawa M baris —
  K baris akan DIHAPUS.` Mengekspor satu potongan tersaring, mengeditnya, lalu
  mengimpornya kembali akan **menghapus sisa dokumen itu**. Selalu ekspor dokumen utuh.
- **Mengunggah berkas yang sama dua kali membuat dokumen kedua.** Saat membuat baru,
  kolom pengelompok berisi label bebas Anda (mis. `RAB-GRAHA`); sistem menerbitkan nomor
  dokumen sendiri, dan label Anda tidak menunjuk apa pun pada unggahan berikutnya. Layar
  hasil memperlihatkan tabel **Kode dokumen yang tersimpan** — **salin nomor itu ke dalam
  berkas Anda**, atau ekspor ulang. Peringatan itu hanya muncul sekali.

Hanya dokumen berstatus **Draf** atau **Ditolak** yang boleh ditimpa: *"yang sudah
Diajukan, Disetujui atau Selesai harus dibuatkan Versi Baru lebih dulu."*

Meninggalkan lalu kembali ke layar ini sengaja menghapus pratinjau: rencana yang disusun
atas basis data kemarin bukan rencana.

### 2.10 Bahasa pesan penolakan

Pesan penolakan di aplikasi ini **bercampur Indonesia dan Inggris**, dan itu apa adanya.
Aturan kasarnya: penolakan yang ditulis khusus untuk sebuah aturan bisnis hampir selalu
berbahasa Indonesia; penolakan siklus dokumen baku dan pemeriksaan kolom bawaan Laravel
berbahasa Inggris. Contoh yang akan Anda temui berbahasa Inggris:

- `Cannot submit document QTN/2026/VIII/0001 while status is approved.`
- `Cannot approve document PO/2026/VIII/0003 while status is draft.`
- `The report date has already been taken.` (laporan harian ganda pada satu tanggal)
- `Journal JV/2026/08/0009 is not balanced: debit 5000000 vs credit 4500000.`

Panduan ini mengutip pesan-pesan itu apa adanya supaya Anda menemukannya saat mencari
teks merah yang ada di layar Anda.

---

## 3. Penawaran sampai penagihan

Jalur order-to-cash: dari prospek yang menelepon sampai uangnya masuk rekening.

### 3.1 Siapa mengerjakan apa

| Langkah | Layar | Yang mengerjakan | Yang dilihat orang berikutnya |
|---|---|---|---|
| 1. Catat prospek | Penjualan › Prospek | sales | — |
| 2. Jadikan pelanggan | Prospek → `Jadikan Pelanggan` | sales | pelanggan baru di daftar Pelanggan |
| 3. Susun penawaran | Penjualan › Penawaran | sales | — |
| 4. Ajukan penawaran | tombol `Ajukan` | sales | **direktur** mendapat pemberitahuan |
| 5. Setujui penawaran | tombol `Setujui` | direktur | sales melihat status Disetujui |
| 6. Tandai menang | tombol `Tandai Menang` | sales | **kontrak draf otomatis terbit** |
| 7. Lengkapi jadwal termin | Penjualan › Kontrak → `Ubah` | sales | — |
| 8. Aktifkan kontrak | tombol `Aktifkan Kontrak` | direktur | **keuangan** melihat termin di antrean |
| 9. Tagih termin | Keuangan › Termin Siap Ditagih | keuangan | — |
| 10. Setujui invoice | tombol `Setujui` pada invoice | manajer keuangan | termin tercap Ditagih; jurnal terbentuk |
| 11. Catat uang masuk | Keuangan › Pembayaran | keuangan | invoice lunas |
| 12. Cairkan retensi | Keuangan › Piutang Retensi | keuangan | — |

**Batas seorang sales.** Peran `sales` **tidak memegang satu pun izin keuangan**, jadi
kelompok **Keuangan** tidak ada di sidebarnya. Invoice, Pembayaran, Termin Siap Ditagih,
dan Piutang Retensi berada di luar jangkauannya. Sales juga **tidak memegang
`crm.approve`**, jadi ia tidak bisa menyetujui penawarannya sendiri, tidak bisa
mengaktifkan kontrak, dan tidak bisa menyetujui pekerjaan tambah-kurang. Bagi seorang
sales, bab ini berhenti di §3.9.

**Batas seorang direktur.** Direktur memegang `crm.approve` tetapi **tidak memegang
`crm.create` maupun `crm.update`** — ia tidak bisa mengetik penawaran, hanya
menyetujuinya (dan mengaktifkan kontrak).

### 3.2 Prospek — `Penjualan › Prospek`

Kolom daftar: Kode · Kontak (dengan nama perusahaan di bawahnya) · Sumber · Estimasi
nilai · Sales · **Follow-up** (tanggal beserta "3 hari lagi") · Status. Saringan:
Status. Kotak cari mencakup nama kontak, kode, dan nama perusahaan.

Status prospek: **Baru · Sudah Dihubungi · Terkualifikasi · Penawaran Dikirim · Menang ·
Kalah**.

**Mencatat prospek baru:**

1. Tekan **`Tambah Prospek`**.
2. Isi **Nama kontak** (wajib). Kolom lain opsional: Perusahaan, Sumber (bantuan di
   layar: *"mis. referral, tender, pameran"*), Status (bawaan Baru), Telepon, Email,
   Estimasi nilai, **Sales penanggung jawab**, **Follow-up berikutnya**, Ringkasan
   kebutuhan, Catatan.
3. **Kode** boleh dikosongkan — sistem menomorinya.
4. **`Simpan`**.

**Mengubah prospek menjadi pelanggan.** Tombol **`Jadikan Pelanggan`** hanya muncul di
halaman prospek yang statusnya **Menang** dan belum punya pelanggan. Konfirmasinya:
*"Buat pelanggan baru dari data lead ini?"* Setelah berhasil, layar melompat ke halaman
pelanggan yang baru.

Yang **ikut tersalin**: nama pelanggan (dari nama perusahaan; bila kosong dari nama
kontak), telepon, email, nama & telepon PIC, status Aktif.

Yang **tidak** tersalin dan harus Anda ketik sendiri di layar Pelanggan: **NPWP, alamat
penagihan, kota, provinsi, centang PKP, dan termin pembayaran.**

Menekan tombol itu dua kali tidak membuat dua pelanggan — jawabannya
`"Lead {kode} sudah menjadi pelanggan {kode}."`

**Prospek yang belum menang tetap butuh pelanggan.** Penawaran mewajibkan kolom
Pelanggan, sedangkan tombol `Jadikan Pelanggan` baru muncul setelah prospek berstatus
Menang. Untuk prospek yang baru mau ditawar, buat pelanggannya langsung di **Penjualan ›
Pelanggan**.

**Status prospek bergerak sendiri.** Menandai penawaran **Menang** mengubah prospek
tertautnya menjadi Menang; menandai **Kalah** mengubahnya menjadi Kalah — kecuali prospek
itu sudah Menang lewat penawaran lain.

### 3.3 Pelanggan — `Penjualan › Pelanggan`

Kolom: Kode · Nama (dengan nama badan hukum) · Kota · PIC (dengan teleponnya) · **PKP** ·
**TOP** · Status.

Formulir, dua bagian:

- *Identitas*: **Nama pelanggan** (wajib) · Nama badan hukum · **Kode** (*"Kosongkan
  untuk penomoran otomatis (CUST-xxxx)."*) · NPWP · **Pengusaha Kena Pajak (PKP)** ·
  Status.
- *Alamat & kontak*: Alamat penagihan · Kota · Provinsi · Telepon · Email · Nama PIC ·
  Telepon PIC · **Termin pembayaran (hari)** (bawaan 30, batas 0–365).

**Dua kolom yang menipu — bacalah keduanya sebelum menyimpan:**

**Centang PKP pada pelanggan tidak menghitung apa pun.** Berbeda dari vendor (di mana
non-PKP memaksa PPN 0 pada PO dan SPK), `PKP` pada pelanggan **hanya keterangan**. Tarif
PPN 11% tetap terisi otomatis di setiap penawaran, kontrak, dan invoice. Untuk pelanggan
non-PKP, tarif itu harus **Anda ketik menjadi 0 satu per satu di tiap dokumen**.

**Termin pembayaran (hari) memang menggerakkan uang.** Angka itu mengisi Jatuh tempo
setiap invoice AR pelanggan ini bila kolom Jatuh tempo dikosongkan (bawaan 30 hari bila
pelanggan tidak punya nilai). Salah isi di sini berarti seluruh invoice pelanggan itu
jatuh tempo pada tanggal yang salah, dan laporan Umur Piutang ikut salah.

**Jangan menghapus pelanggan.** Penghapusan pelanggan **tidak dijaga apa pun** — tidak
ada pemeriksaan kontrak, penawaran, atau invoice. Dokumennya tetap ada tetapi kehilangan
nama pelanggannya di layar. Ubah **Status** menjadi **Nonaktif**.

Tidak ada layar khusus yang menampilkan seluruh kontrak/penawaran/invoice satu
pelanggan. Gunakan saringan **Pelanggan** pada masing-masing daftar.

### 3.4 Penawaran — `Penjualan › Penawaran`

Kolom: Kode · Judul (dengan nama pelanggan) · Lingkup · Berlaku s/d · Total · Status.
Nomor terbit otomatis: `QTN/{tahun}/{bulan Romawi}/{4 digit}`.

Lingkup pekerjaan: **Konstruksi Gedung · Integrasi Sistem (ELV/ICT) · Pemeliharaan**.

**Menyusun penawaran:**

1. **`Tambah Penawaran`**.
2. Isi **Pelanggan** (wajib), **Judul penawaran** (wajib), **Lingkup pekerjaan** (wajib).
   Opsional: *Dari prospek*, *Berlaku sampai*, *Diskon*, *Tarif PPN (%)* (bawaan 11),
   *Catatan*.
3. Isi tabel **Rincian penawaran** — minimal satu baris: **Uraian** (wajib, maksimal 500
   karakter) · **Qty** (wajib) · Satuan · **Harga satuan** (wajib). Jumlah per baris
   dihitung layar.
4. **`Simpan`**.

Hitungannya: `Subtotal` = jumlah baris; `Diskon` dipotong dan **dibatasi tidak melebihi
subtotal**; `DPP` = subtotal − diskon; `PPN` = DPP × tarif ÷ 100; `Total` = DPP + PPN.
Keenam angka itu tampil sebagai strip ringkasan di halaman penawaran.

**Menyimpan penawaran mengganti SELURUH barisnya** dan menomori ulang sesuai urutan di
layar. Tidak ada penambahan baris "di belakang" — apa yang ada di tabel saat Anda menekan
Simpan itulah isi penawaran.

Penawaran bisa diubah dan dihapus **hanya saat Draf atau Ditolak**.

**Tombol di halaman penawaran:**

| Tombol | Muncul saat | Yang terjadi |
|---|---|---|
| **`Ajukan`** | Draf / Ditolak | Status → Diajukan; **semua direktur mendapat pemberitahuan** |
| **`Setujui`** | Diajukan | Isian *Catatan persetujuan* (opsional) → Disetujui |
| **`Tolak`** | Diajukan | Isian *Alasan penolakan* (**wajib**) → Ditolak, bisa diubah lagi |
| **`Tandai Menang`** | Disetujui, belum diputuskan | lihat di bawah — **membuat kontrak** |
| **`Tandai Kalah`** | **status apa pun** selama belum diputuskan | lihat di bawah — **tidak bisa dibatalkan** |
| **`Buat Revisi`** | Disetujui / Ditolak / Diajukan | kembali ke Draf, nomor revisi naik |

**`Tandai Menang` diam-diam menerbitkan KONTRAK.** Konfirmasinya hanya berbunyi
*"Tandai penawaran ini sebagai dimenangkan?"*, tetapi satu klik itu melakukan tiga hal
sekaligus:

1. mencap penawaran sebagai dimenangkan,
2. mengubah status prospeknya menjadi Menang,
3. **menerbitkan kontrak berstatus Draf** dengan nilai = DPP penawaran, tarif dan jumlah
   PPN, judul, dan lingkup ikut terbawa; retensi diisi dari setelan perusahaan (bawaan
   5%).

Layar hanya memuat ulang halaman penawaran, jadi kontraknya tidak diperlihatkan. **Ia
menunggu di `Penjualan › Kontrak`.** Jangan mengetik kontrak kedua secara manual. Klik
kedua tidak membuat kontrak kedua: `"Quotation … outcome has already been decided."`

Penolakan lain: `"Only an approved quotation can be marked won ({kode} is {status})."`

**`Tandai Kalah` tidak bisa dibatalkan, dan tombolnya tersedia terlalu dini.** Ia muncul
pada **status apa pun** — termasuk Draf — selama penawaran belum diputuskan. Dialognya
meminta **Alasan kalah** (wajib diisi di layar). Sekali diklik, status **dipaksa menjadi
Selesai**: tombol Ubah hilang, tombol Hapus hilang, dan **tombol `Buat Revisi` juga
hilang**. Penawaran yang salah ditandai kalah terkunci selamanya dan hanya bisa diganti
dengan penawaran baru bernomor lain. **Tidak ada tombol "batalkan kalah" di mana pun.**

**`Buat Revisi` menimpa, bukan menambah.** Ia adalah satu-satunya cara mengembalikan
dokumen dari Disetujui ke Draf di seluruh aplikasi. Konfirmasinya *"Buat revisi baru dari
penawaran ini?"*. Yang terjadi: nomor revisi naik satu, status kembali Draf, penanda
kalah dikosongkan. **Nomor QTN tidak berubah**, dan penghitung revisi itu **tidak ada di
kolom daftar mana pun** — ia hanya terlihat di kartu Informasi dan di baris **REVISI KE**
pada surat penawaran cetak. **Menekan tombolnya sendiri tidak menyentuh baris rincian** —
sesudahnya seluruh baris lama masih ada. Barulah **`Simpan` berikutnya menimpanya utuh**,
dan **tidak ada arsip isi revisi sebelumnya di mana pun.** Cetak dan simpan PDF revisi
lama sebelum mengubahnya. Penolakan: `"Quotation {kode} has been won; revise via the
contract instead."`

**Menghapus penawaran** ditolak bila masih ada jaminan berstatus Berlaku yang menempel:
> "Penawaran {kode} masih memiliki jaminan aktif ({nomor} — {penerbit}); tandai jaminan
> itu dikembalikan/dicairkan atau pindahkan dulu tautannya."

**Mencetak.** Tombol **`Cetak Penawaran`** menghasilkan **SURAT PENAWARAN HARGA**
(Form F/PN). Blok identitasnya: NO. PENAWARAN, **REVISI KE**, KEPADA, ALAMAT, LINGKUP
PEKERJAAN, BERLAKU S/D. Di bawah tabel: Subtotal / Diskon / DPP / PPN / TOTAL PENAWARAN,
lalu **TERBILANG**, lalu blok **SYARAT & KETENTUAN yang sengaja dikosongkan empat baris**
— sistem tidak menyimpan syarat penjualan, jadi itu ditulis tangan oleh yang
menandatangani. **Tanggal surat adalah tanggal dokumen dibuat**, bukan hari Anda
mencetak; penawaran memang tidak punya kolom "tanggal penawaran".

**Tidak ada pengiriman penawaran lewat email dari dalam aplikasi.** Cetak PDF-nya, lalu
kirim sendiri.

### 3.5 Mengimpor penawaran dari Excel

`Sistem › Impor Dokumen` → tombol **Penawaran**. Baca mekanika umumnya di §2.9 lebih
dulu.

Satu berkas boleh memuat beberapa penawaran. Kolom **`tipe`** menentukan jenis baris,
kolom **`dokumen`** mengelompokkannya.

| `tipe` | Kolom yang dibaca |
|---|---|
| `dokumen` | `pelanggan_kode` (**wajib**) · `prospek_kode` · `judul` (**wajib**) · `lingkup` (**wajib**) · `berlaku_sampai` · `diskon` · `ppn_persen` · `catatan` |
| `item` | `uraian` · `volume` · `satuan` · `harga_satuan` · `jumlah` (hanya pemeriksa silang) |
| `abaikan` | baris subtotal/rekapitulasi — dibaca lalu dilewati |

`lingkup` menerima "konstruksi"/"sipil", "integrasi"/"elv"/"ict", "pemeliharaan"/
"perawatan". `diskon` kosong dibaca 0; `ppn_persen` kosong memakai tarif PPN di
Pengaturan.

**Nomor QTN tidak boleh diisi dari berkas.** Isi kolom `dokumen` dengan nomor QTN yang
sudah ada untuk **memperbarui**, atau dengan label bebas (mis. `PNW-GRAHA`) untuk
**membuat baru**.

Mengubah pelanggan pada penawaran yang sudah ada **diberi peringatan, bukan ditolak**:
*"pelanggan penawaran ini berubah dari {kode lama}; pastikan itu memang yang dimaksud."*

Tidak ada impor untuk kontrak, jadwal termin, pekerjaan tambah-kurang, jaminan, atau
invoice. Yang bisa diimpor di jalur ini hanya **Pelanggan** (lewat Impor Data Master —
di sana kolom `kode` **wajib**, berbeda dari formulir layar) dan **Penawaran**.

### 3.6 Kontrak — `Penjualan › Kontrak`

Kolom: Kode · Judul (dengan pelanggan) · Lingkup · Tgl TTD · **Nilai (DPP)** · Status.
Nomor `CTR/{tahun}/{bulan Romawi}/{4 digit}`.

Kontrak biasanya sudah ada — diterbitkan otomatis oleh `Tandai Menang`. Yang Anda
kerjakan adalah melengkapinya.

**Melengkapi kontrak:**

1. Buka kontraknya, tekan **`Ubah`**.
2. Periksa **Nilai kontrak (DPP)** — nilai kontrak di sistem ini **selalu DPP tanpa
   PPN**; PPN dan Nilai termasuk PPN dihitung dari tarif.
3. Isi **No. kontrak pelanggan**, **Tanggal tanda tangan**, **Mulai**, **Selesai**
   (harus ≥ Mulai), **Retensi (%)** (bawaan 5), **Masa pemeliharaan (bulan)** (bawaan 12).
4. Isi tabel **Jadwal termin** — minimal satu baris. Teks bantuan di atas tabel berbunyi
   kata demi kata:

   > "Total persentase termin harus tepat 100%. Centang 'Retensi' pada termin retensi
   > (mis. 'Retensi 5%') — kontrak yang memuatnya menagih retensi lewat termin itu, dan
   > potongan retensi per invoice akan ditolak agar tidak tercatat dobel."

   Kolomnya: **Nama termin** (wajib) · **Persen (%)** (wajib, >0, ≤100) · Syarat
   penagihan · **Retensi** (centang) · **Rencana tagih** (tanggal).
5. **`Simpan Perubahan`**.

Rupiah tiap termin dihitung = nilai × persen ÷ 100, dan **termin terakhir menyerap sisa
pembulatan** supaya jumlahnya persis sama dengan nilai kontrak.

Penolakan yang akan Anda temui:

- `"Termin percents must sum to 100, got {angka}."`
- `"A contract needs at least one termin."`
- `"Contract {kode} has billed termins; the schedule can no longer be replaced."`
- `"Contract {kode} is {status} and can no longer be edited."`

**Jadwal termin terkunci begitu satu termin ditagih.** Setelah invoice pertama
**disetujui**, seluruh tabel Jadwal termin tidak bisa diganti lagi. Sebelum menagih
termin pertama, pastikan:

- termin retensi (bila memakai pola itu) sudah ada di jadwal,
- **seluruh** termin — termasuk termin pemeliharaan triwulanan — sudah lengkap,
- kolom **Rencana tagih** setiap termin sudah terisi.

Sebab termin tanpa `Rencana tagih` dan tanpa milestone **tidak akan pernah muncul di
Termin Siap Ditagih dan tidak akan pernah muncul di Tenggat.** Ia hilang dari setiap
pengingat yang dimiliki sistem — persis cara satu kuartal kontrak pemeliharaan lewat
tanpa ditagih.

**Mengaktifkan kontrak.** Tombol **`Aktifkan Kontrak`** (izin `crm.approve` — direktur)
dengan konfirmasi *"Aktifkan kontrak ini? Termin akan siap ditagih."* Ia memeriksa jadwal
ada dan persennya tepat 100, lalu **status langsung menjadi Disetujui**.

**Kontrak tidak punya siklus Ajukan/Setujui/Tolak** — `Aktifkan Kontrak` adalah
satu-satunya transisinya. Akibatnya kontrak tidak punya kartu Riwayat Persetujuan, tidak
memicu pemberitahuan, dan **tidak tunduk pemisahan tugas**: pemegang `crm.approve` boleh
mengaktifkan kontrak yang ia buat sendiri.

Penolakan: `"Contract {kode} is {status} and cannot be activated."` ·
`"Contract {kode} has no termin schedule."` ·
`"Contract {kode} termin percents sum to {x}, expected 100."`

**Tabel Jadwal termin di halaman kontrak** memperlihatkan **# · Termin · Persen · Nilai ·
Syarat · Retensi · Rencana tagih · Ditagih**, dengan total pada kolom Nilai. Tombol per
baris **`Tagih termin ini`** milik keuangan — §3.11.

**Mencetak.** **`Cetak Ringkasan Kontrak`** (Form F/RK) memuat NO. KONTRAK, NO. SPK / PO
PELANGGAN, TANGGAL TANDA TANGAN, MULAI/SELESAI, LINGKUP, NILAI KONTRAK (DPP), PPN, NILAI
TERMASUK PPN, RETENSI (%), NILAI RETENSI, MASA PEMELIHARAAN, lalu jadwal terminnya
dengan **dua total bersebelahan** — "Jumlah nilai termin terjadwal" dan "Nilai kontrak
(DPP)". Selisih di antara keduanya (termin pemeliharaan yang tidak dijadwalkan, termin
retensi yang lupa dimasukkan) memang dimaksudkan supaya terlihat.

**Halaman kontrak tidak memperlihatkan nilai tanda tangan maupun daftar CCO-nya.** Angka
`Nilai (DPP)` yang tampil selalu nilai **sekarang**, sudah tergerak oleh setiap pekerjaan
tambah-kurang yang disetujui, tanpa jejak di layar itu. Riwayatnya hanya bisa dibaca dari
daftar Pekerjaan Tambah-Kurang.

### 3.7 Pekerjaan Tambah-Kurang — `Penjualan › Pekerjaan Tambah-Kurang`

Kolom: Kode · Judul (dengan kode kontrak) · Tanggal · **Jenis** · **Perubahan nilai**
(bertanda — negatif berarti pekerjaan kurang) · **Perubahan waktu** (hari, bertanda —
terisi hanya pada addendum waktu) · **Selesai baru** (terisi begitu addendum waktunya
disetujui) · Status. Nomor `CCO/…`.

Jenis: **Tambah-Kurang**, **Eskalasi Harga**, dan **Addendum Waktu**. Dua yang pertama
menggerakkan nilai kontrak; **Addendum Waktu menggeser tanggal selesai kontrak dan
tidak menyentuh rupiah** — waktu dan nilai tidak pernah bergerak di satu lembar.

**Mencatat pekerjaan tambah-kurang:**

1. **`Tambah Pekerjaan Tambah-Kurang`**.
2. Isi **Kontrak** (wajib), **Tanggal** (wajib), **Judul** (wajib), **Jenis perubahan**
   (bantuan: *"Pilih 'Eskalasi Harga' untuk penyesuaian indeks kontrak multi-tahun;
   'Addendum Waktu' (P0-B) menggeser tanggal selesai, bukan nilai."*), **Perubahan
   nilai** (wajib; **tidak boleh 0** pada CCO nilai, **wajib 0** pada Addendum Waktu;
   bantuan: *"Positif untuk pekerjaan tambah, negatif untuk pekerjaan kurang. Untuk
   Addendum Waktu isi 0 — waktu dan nilai tidak pernah bergerak di satu lembar."*),
   **Perubahan waktu (hari)** (hanya untuk Addendum Waktu — bertanda, tidak boleh 0;
   bantuan: *"Hanya untuk Addendum Waktu: bertanda dan tidak boleh 0 — negatif
   memendekkan. Kosongkan pada jenis lain."*), Sebab, No. CCO pelanggan, Uraian.
3. **`Simpan`**, lalu **`Ajukan`**.

Ditolak bila kontraknya belum disetujui:
> "Kontrak {kode} berstatus {status}. Pekerjaan tambah-kurang hanya berlaku atas kontrak
> yang sudah disetujui — ubah nilainya langsung selama masih draf."

**Mencatat addendum waktu** memakai langkah yang sama dengan Jenis perubahan **Addendum
Waktu**: Perubahan nilai diisi **0**, **Perubahan waktu (hari)** diisi — bertanda,
negatif berarti pengurangan waktu. **Tanggal selesai barunya tidak pernah diketik**:
sistem menghitungnya dari tanggal selesai kontrak berjalan + hari **saat addendum
disetujui**, lalu menampilkannya di kolom Selesai baru. Penolakan yang menegakkannya:

- Perubahan nilai selain 0 pada Addendum Waktu:
  `"Addendum waktu tidak memindahkan nilai — value_change wajib 0."`
- Perubahan waktu diisi pada jenis lain:
  `"days_change hanya bermakna pada addendum waktu (change_type waktu)."`
- Klien yang mencoba mengirim tanggal selesai baru:
  `"new_end_date dihitung sistem saat addendum disetujui — tanggal selesai kontrak
  berjalan + days_change — bukan diinput."`
- Kontrak yang kolom Selesai-nya kosong:
  `"Kontrak {kode} tidak memiliki tanggal selesai — addendum waktu tidak punya dasar
  untuk digeser."`
- Proyek kontraknya sudah lewat serah terima (Masa Pemeliharaan atau Ditutup) —
  diperiksa saat membuat dan diperiksa ulang saat menyetujui:
  `"Proyek {kode} berstatus {status}; addendum waktu hanya berlaku atas pekerjaan yang
  masih berjalan — perpanjangan setelah serah terima adalah instrumen lain."`
  Proyek **Ditangguhkan** justru diterima — penangguhan adalah alasan paling lazim
  sebuah addendum waktu ditandatangani.

**Tombol `Ubah` pada CCO yang sudah tersimpan akan gagal.** Formulir mengirim kembali
kolom Kontrak, sementara server menolak kolom itu saat memperbarui — hasilnya galat
pemeriksaan pada isian Kontrak, **pada status apa pun termasuk Draf**. Selama ini belum
diperbaiki: **CCO yang salah harus dihapus selagi masih Draf dan diketik ulang.**

**Menyetujui CCO nilai langsung menggerakkan nilai kontrak.** Nilai tanda tangan dicatat
sekali, `Nilai (DPP)` kontrak menjadi nilai + perubahan, PPN dan total dihitung ulang,
dan **nilai kontrak yang tersalin ke layar proyek ikut diperbarui**. Notifikasi di layar
hanya `Setujui berhasil.` (§2.5) — nilai barunya dibaca di halaman kontrak, bukan dari
notifikasi.

Penolakan saat menyetujui:
`"Nilai kontrak setelah perubahan ({angka}) lebih kecil daripada yang sudah ditagihkan
({angka})."` · `"Nilai kontrak tidak boleh menjadi negatif."`

**Menyetujui addendum waktu menggeser tanggal selesai kontrak DAN proyeknya.** Tanggal
selesai tanda tangan dicatat sekali pada addendum waktu pertama — halaman kontrak tidak
menampilkannya (kolom Selesai selalu tanggal **sekarang**, persis seperti Nilai (DPP)
pada CCO nilai); yang mencetaknya adalah baris "Tanggal selesai kontrak sesuai tanda
tangan" pada lembar addendumnya. Kolom Selesai kontrak menjadi tanggal berjalan + hari,
dan tanggal selesai pada layar proyek ikut digeser. Tanggal hasilnya distempel ke
CCO-nya: kolom **Selesai baru** di daftar, **Tanggal selesai baru** di halamannya.
Addendum berikutnya menumpuk — dihitung dari tanggal yang sudah tergeser, bukan dari
tanggal tanda tangan. Notifikasi di layar tetap `Setujui berhasil.` (§2.5); tanggal
barunya dibaca dari kolom Selesai baru, bukan dari notifikasi.

Penolakan tambahan saat menyetujui, bila pengurangan waktu kebablasan:
`"Pengurangan {angka} hari menggeser tanggal selesai menjadi {tanggal} — mendahului
tanggal mulai kontrak ({tanggal})."`

**Addendum waktu yang disetujui bersifat final** — CCO tidak punya tombol batal.
Kekeliruan dikoreksi dengan addendum waktu kedua berhari negatif, yang diterima
seperti addendum lainnya.

**Menyetujui CCO TIDAK membuat termin.** Nilai tambah yang tidak dijadwalkan tidak punya
Rencana tagih, tidak masuk antrean Termin Siap Ditagih, dan tidak muncul di Tenggat.
Jalankan langkah berikut segera setelah persetujuan, atau nilai tambahnya akan terlupa:

**Menjadwalkan nilai tambah.** Tombol **`Jadwalkan Termin Nilai Tambah`** muncul pada CCO
yang **Disetujui, bernilai positif, bukan addendum waktu, dan belum pernah dijadwalkan**
— pada addendum waktu ia tidak pernah muncul, tidak ada nilai untuk dijadwalkan.
Isiannya:
**Rencana tagih** (tanggal, **wajib**; bantuan: *"Termin masuk antrean siap tagih begitu
tanggal ini lewat."*) dan **Nama termin** (opsional; *"Kosongkan untuk 'Pekerjaan tambah
<kode CCO>'."*).

Ia membuat **satu termin baru** di jadwal kontrak: nomor berikutnya, **persen 0**, nilai
= perubahan nilai, syarat penagihan terisi otomatis, bukan termin retensi. Notifikasi di
layar hanya `Jadwalkan Termin Nilai Tambah berhasil.` (§2.5); termin barunya terlihat di
tabel Jadwal termin pada halaman kontrak.

**Persen 0 pada termin CCO benar, jangan "diperbaiki".** Aturan "total persen = 100"
milik jadwal yang ditandatangani dan tidak pernah diperiksa ulang setelah kontrak aktif;
penagihan membaca kolom **Nilai**, bukan persen. Setelah satu CCO dijadwalkan, kolom
Persen memang tidak lagi berjumlah 100. Yang harus dicocokkan adalah dua total pada
Ringkasan Kontrak cetak.

Penolakan `Jadwalkan Termin Nilai Tambah`:

- `"Perubahan {kode} berstatus {status}; hanya perubahan yang sudah disetujui yang
  nilainya masuk kontrak — dan hanya nilai itu yang bisa dijadwalkan."`
- `"Addendum waktu {kode} tidak membawa nilai — tidak ada yang dijadwalkan untuk
  ditagih."`
- `"Perubahan {kode} bernilai {angka}; pekerjaan kurang mengurangi sisa yang boleh
  ditagih, bukan menambah jadwal — tidak ada termin baru untuk dijadwalkan."`
- `"Nilai tambah {kode} sudah dijadwalkan sebagai termin {no} (\"{nama}\") — satu
  perubahan, satu termin."`
- `"Seluruh termin kontrak {kode} sudah ditagihkan — jadwal penagihannya selesai; tagih
  nilai tambah {kode CCO} lewat invoice manual atas kontrak yang sama."`

**Daftar CCO tidak punya saringan Kontrak.** Untuk melihat seluruh CCO satu kontrak,
ketikkan kode kontraknya di kotak cari.

**Tidak ada mesin formula eskalasi harga.** Jenis `Eskalasi Harga` hanya membedakan makna
jejak auditnya; nilainya dihitung di luar aplikasi dan diketik ke kolom Perubahan nilai.

**Mencetak.** **`Cetak Berita Acara Tambah-Kurang`** (Form F/BATK), diberi tanggal oleh
**tanggal perubahan**, bukan hari mencetak. Tabel nilainya berisi empat baris: nilai
kontrak sesuai tanda tangan, nilai perubahan, PPN atas perubahan, dan nilai kontrak
sesudahnya — baris terakhir **hanya terisi bila CCO sudah disetujui**.

**CCO berjenis `waktu` mencetak lembar yang berbeda dari tombol yang sama**: judulnya
BERITA ACARA ADDENDUM WAKTU, tanpa pad rincian dan tanpa baris nilai (addendum waktu
tidak menggerakkan rupiah). Sebagai gantinya tabel PERUBAHAN WAKTU PELAKSANAAN: tanggal
selesai sesuai tanda tangan, perubahan hari (bertanda), lalu tanggal selesai sesudah
disetujui — pada draf, baris terakhir memuat tanggal selesai **berjalan** dan berbunyi
"belum disetujui", tidak pernah tanggal proyeksi yang belum disepakati siapa pun.

### 3.8 Jaminan & Asuransi — `Penjualan › Jaminan & Asuransi`

Ini **register**, bukan dokumen: tidak ada penomoran otomatis, tidak ada persetujuan, dan
**tidak ada satu pun tombol aksi**.

Kolom: Nomor (dengan penerbit) · Jenis · Nilai · **Berakhir** (tanggal + relatif) ·
Status. **Urutan bawaannya: yang paling cepat habis di baris teratas.**

Jenis: **Jaminan Penawaran · Jaminan Pelaksanaan · Jaminan Uang Muka · Jaminan
Pemeliharaan · Asuransi CAR · Asuransi TPL · Lainnya**.
Status: **Berlaku · Dikembalikan · Dicairkan**.

**Mencatat jaminan:**

1. **`Tambah Jaminan`**.
2. Isi **Jenis**, **Nomor (dari penerbit)**, **Penerbit**, **Nilai** (harus > 0),
   **Mulai berlaku**, **Berakhir** (≥ mulai). Semuanya wajib.
3. Isi **Kontrak** *atau* **Penawaran** — salah satu **wajib**. Bantuan di layar:
   *"Isi kontrak ATAU penawaran — jaminan penawaran terbit sebelum kontrak ada."*
4. Isi **Lokasi dokumen fisik**: *"Klaim pencairan butuh dokumen asli — catat di mana
   fisiknya disimpan."*
5. **`Simpan`**.

**Jaminan yang selesai diubah statusnya lewat tombol `Ubah` — jangan dihapus.** Nomor
jaminan **unik per penerbit, dan penguncian itu ikut menghitung baris yang sudah
dihapus**: jaminan yang dihapus lalu dicatat ulang dengan nomor yang sama akan ditolak.
Konfirmasi hapusnya sendiri mengatakannya:

> "Hapus jaminan ini dari register? Nomornya tetap terkunci per penerbit sampai baris
> dipulihkan — untuk jaminan yang sudah kembali, ubah status ke 'Dikembalikan', jangan
> dihapus."

Perpanjangan dicatat sebagai **baris jaminan baru**. Tidak ada tombol "Kembalikan",
"Cairkan", atau "Perpanjang".

Jaminan masih dianggap berlaku **pada** hari Berakhir — hari itu terbaca "0 hari lagi",
dan "lewat" mulai hari berikutnya.

**Peringatan jaminan hampir kedaluwarsa tidak sampai ke sales.** Baris Tenggat untuk
Jaminan & Asuransi (dan untuk Kontrak yang mendekati tanggal berakhir) bergerbang izin
`crm.approve` — hanya **direktur dan admin** yang menerimanya, baik di kotak masuk maupun
di layar Tenggat. **Seorang sales harus membuka register Jaminan sendiri**; baris teratas
selalu yang paling cepat habis.

**Mencetak.** **`Cetak Register Jaminan`** (Form F/RJ, mendatar) mencetak **seluruh
jaminan pada kontrak yang sama**, bukan hanya baris yang Anda buka, lengkap dengan kolom
SISA HARI dan LOKASI DOKUMEN FISIK.

### 3.9 Analitik Win-Rate — `Penjualan › Analitik Win-Rate`

Layar baca saja; tidak ada tombol selain muat ulang.

Empat kotak angka: **Win-rate keseluruhan** (x menang · y kalah) · **Nilai dimenangkan**
(memakai **DPP**, jadi sama persis dengan nilai kontrak) · **Nilai kalah** · **Masih
berjalan**.

Tabel **Win-rate per kuartal** — kuartalnya diambil dari **tanggal keputusan**
(kapan Anda menekan Tandai Menang/Kalah), bukan tanggal penawaran dibuat.

Tabel **Alasan kalah terbanyak** — Alasan · Berapa kali · Nilai yang hilang. Penawaran
kalah tanpa alasan tercatat dihitung dengan label **"Tidak dicatat"**. Itulah imbalan
langsung dari mengisi kolom Alasan kalah dengan sungguh-sungguh.

Win-rate ditampilkan `—`, bukan 0%, bila belum ada keputusan sama sekali. Penawaran yang
**ditolak internal** dikeluarkan dari perhitungan.

*Bagi seorang sales, bab ini selesai di sini. Sisanya milik keuangan.*

### 3.10 Termin Siap Ditagih — `Keuangan › Termin Siap Ditagih`

Ini penyerahan dari manajer proyek ke keuangan. Deskripsi layarnya: *"Termin yang syarat
penagihannya sudah terpenuhi — milestone tercapai atau jadwalnya jatuh tempo — tetapi
invoicenya belum terbit."*

Tiga kotak: **Nilai siap ditagih** · **Menunggu terlama** · **Dari milestone** /
"n dari jadwal kalender".

Tabel **Antrean penagihan**, paling lama menunggu di atas: Kontrak / pelanggan · Termin ·
**Pemicu** (lencana **Milestone tercapai** hijau / **Jadwal jatuh tempo** kuning) ·
**Menunggu** (hari; kuning ≥30 hari, merah ≥60) · Nilai · tombol **`Buka kontrak`**.

**Dua hal berbeda memasukkan termin ke antrean:**

1. **Milestone** — manajer proyek mengisi tanggal tercapai pada milestone yang tertaut ke
   termin itu. Saat itu juga, **semua pemegang `fin.create` menerima pemberitahuan**
   berjudul *"Termin {no} kontrak {kode} siap ditagih — Rp {nilai}"*.
2. **Jadwal** — kolom **Rencana tagih** termin sudah lewat atau jatuh hari ini. Ini
   satu-satunya pemicu bagi termin kalender (kontrak pemeliharaan triwulanan) yang tidak
   punya milestone.

Bila keduanya berlaku, **tanggal yang lebih awal yang dipakai**.

Hanya kontrak **Disetujui** yang dibaca. Termin tanpa Rencana tagih dan tanpa milestone
**tidak akan pernah muncul di sini** — layar mengatakannya sendiri di kartu "Cara
kerjanya".

**Baris hilang dari antrean saat invoice terminnya DISETUJUI**, bukan saat dibuat.
Selama invoice masih Draf atau Diajukan, terminnya masih tampil di antrean dan tombol
`Tagih termin ini` masih ada — tetapi invoice kedua atas termin yang sama akan ditolak:
`"An invoice already exists for termin …"`

**Memindahkan tanggal Rencana tagih satu termin tidak bisa dilakukan dari layar mana
pun.** Tabel Jadwal termin di halaman kontrak hanya menampilkan; tombol per barisnya
adalah `Tagih termin ini`. Kontrak pemeliharaan yang kuartal pertamanya sudah ditagih
karena itu tidak bisa lagi mencatat kapan kuartal berikutnya jatuh tempo lewat layar —
**minta administrator** (bab 14).

### 3.11 Invoice Termin (AR) — `Keuangan › Invoice Termin (AR)`

Kolom: Kode · Pelanggan (dengan kode kontrak) · Tgl invoice · Jatuh tempo · Total ·
**Sisa** (hijau bila nol) · Status. Kotak cari mencakup kode, keterangan, dan **nomor
faktur pajak**. Nomor `INV/…`.

Strip ringkasan di halaman invoice: **DPP · PPN · Retensi ditahan · Total · Sudah
dibayar · Sisa**.

**Cara yang benar menagih satu termin:**

1. Buka **halaman kontraknya** (dari Termin Siap Ditagih, tombol **`Buka kontrak`**).
2. Di tabel **Jadwal termin**, cari baris yang kolom **Ditagih**-nya masih kosong.
3. Tekan **`Tagih termin ini`** pada baris itu.
4. Formulir invoice terbuka **sudah terisi**: termin, kontrak, pelanggan, Keterangan
   (`{kode kontrak} — {nama termin}`), dan **centang retensi sudah diatur dengan benar**.
5. Periksa **Tanggal invoice** dan **Jatuh tempo**, lalu **`Simpan`**. Layar berpindah ke
   daftar Invoice Termin.

**Jangan mengetik `Termin kontrak (ID)` sendiri.** Kolom itu meminta **ID mentah dari
basis data**, bukan nomor termin (#1, #2). Mengetiknya sendiri adalah cara termudah
menagih termin milik kontrak lain, dan tidak ada yang menahannya.

**Invoice manual** (tanpa termin): kosongkan `Termin kontrak (ID)`. Bantuan di layar
berbunyi *"Isi 'Termin kontrak' untuk menagih satu termin — nilai, PPN dan retensi
dihitung otomatis. Kosongkan untuk invoice manual."* Dalam mode itu **Pelanggan,
Kontrak, Keterangan dan DPP menjadi wajib**.

Hitungannya: `PPN = DPP × tarif ÷ 100`; **`Total = DPP + PPN − Retensi ditahan`**. Jatuh
tempo bawaan = tanggal invoice + termin bayar pelanggan (§3.3), dan harus ≥ tanggal
invoice.

**Dialog milestone.** Bila termin yang ditagih punya milestone dan **tidak satu pun
tercapai**, muncul dialog **"Milestone syarat termin belum tercapai — tetap tagih?"**
dengan tombol **`Ya, tetap tagih (tercatat)`** dan pesan:

> "Milestone \"{nama}\" — syarat penagihan termin \"{nama termin}\" — belum tercapai.
> Menagih sekarang perlu konfirmasi eksplisit dan akan tercatat pada invoice."

Bila Anda mengonfirmasi, teks berikut **ditempelkan permanen ke Keterangan invoice dan
ikut tercetak**: ` [Konfirmasi: milestone "…" belum tercapai — tetap ditagih.]` Termin
kalender yang memang tidak punya milestone lewat tanpa dialog.

**Tombol pada halaman invoice:**

| Tombol | Izin | Muncul saat | Yang terjadi |
|---|---|---|---|
| **`Ajukan`** | `fin.update` | Draf / Ditolak | → Diajukan; manajer keuangan diberi tahu |
| **`Setujui`** | `fin.approve` | Diajukan | **Inilah yang membukukan semuanya** — lihat di bawah |
| **`Tolak`** | `fin.approve` | Diajukan | *Alasan penolakan* wajib |
| **`Catat Faktur Pajak`** | `fin.update` | Disetujui | *Nomor faktur pajak* wajib (mis. `010.000-26.00000001`) |
| **`Batalkan Dokumen`** | `fin.post` | Disetujui **dan** belum menerima pembayaran | *Alasan pembatalan* wajib, minimal 5 karakter |

**`Setujui` melakukan tiga hal sekaligus** dan tidak bisa dibatalkan dengan tombol
manapun: memposting jurnal piutang dan pendapatan, membuat baris **piutang retensi** bila
invoice memotong retensi, dan **memberi cap `Ditagih` pada termin kontrak** (yang
mengunci jadwal termin selamanya).

Penolakan yang akan Anda temui:

- `"Termin \"{nama}\" of contract {kode} is already billed."`
- `"An invoice already exists for termin \"{nama}\"."`
- `"Contract {kode} is {status}; only approved contracts can be billed."`
- `"Retention withheld cannot exceed the invoice DPP."`
- Retensi ganda:
  > "Kontrak {kode} menagih retensinya lewat termin retensi pada jadwalnya sendiri; hapus
  > potongan retensi pada invoice ini agar tidak tercatat dobel."
- Nomor faktur ganda:
  > "Nomor faktur pajak {no} sudah dipakai invoice {kode}; satu nomor seri dari DJP hanya
  > boleh dipakai satu faktur."

  (Mengulang nomor yang sama pada invoice yang **sama** tetap boleh — mengoreksi salah
  ketik bukan duplikat.)
- `"Faktur pajak can only be set on an approved invoice ({kode})."`
- Periode fiskal, saat menekan **Setujui**:
  > "Periode fiskal {tahun}-{bulan} sudah ditutup; jurnal tidak dapat diposting ke
  > dalamnya."

  atau *"Belum ada periode fiskal untuk {tanggal}. Buat kalender fiskal {tahun} lebih
  dulu di Keuangan › Periode Fiskal."*

**Membatalkan invoice.** **`Batalkan Dokumen`** memposting **jurnal pembalik** (jurnal
asli tidak pernah disentuh), menghapus baris piutang retensi yang lahir dari invoice ini,
dan **membuka kembali termin** sehingga invoice pengganti bisa diterbitkan. PDF invoice
yang dibatalkan mendapat pita **"DIBATALKAN {tanggal} — {alasan}"**.

Tiga hal yang harus Anda ketahui sebelum menekannya:

- **Nomor faktur pajak TIDAK dilepas.** Nota pembatalan ke DJP tetap pekerjaan manual di
  luar sistem; yang dijamin hanya bahwa invoice batal keluar dari ekspor e-Faktur.
- **Invoice yang sudah menerima pembayaran sepeser pun tidak bisa dibatalkan.**
  Penerimaannya harus dibalik lebih dulu (§3.12):
  > "Invoice {kode} sudah menerima pembayaran {jumlah}; hanya invoice yang belum dibayar
  > yang dapat dibatalkan — penerimaan yang terlanjur salah alokasi dikoreksi lewat
  > jurnal."
- **Periode fiskal yang sudah ditutup menghentikan `Setujui`, bukan `Batalkan`.** Jurnal
  pembalik ditaruh pada tanggal invoice selama periode itu masih terbuka; kalau tidak,
  ia jatuh **hari ini**. Artinya bulan pembatalan dan bulan invoice tidak lagi saling
  meniadakan.

Penolakan lain: `"Retensi dari invoice {kode} sudah dicairkan; invoice tidak dapat
dibatalkan."`

**Mencetak.** Hanya tombol **`PDF`** (mengunduh `invoice-{kode}.pdf`). **Tidak ada
formulir rumah untuk invoice AR**, dan tidak ada kwitansi/bukti terima dari sisi
penerimaan.

### 3.12 Menerima uang — `Keuangan › Pembayaran`

Kolom: Kode · Tanggal · **Arah** · Rekening · Jumlah · Referensi · Status. Nomor
**`RCV/…` untuk penerimaan** dan `PAY/…` untuk pengeluaran, ditentukan oleh Arah dan
**tidak bisa diubah setelah dibuat**.

**Mencatat uang masuk:**

1. **`Tambah Pembayaran`**.
2. **Arah** = *Penerimaan (RCV)* — hanya bisa dipilih saat membuat.
3. **Tanggal** (bawaan hari ini), **Rekening bank**, **Jumlah**, Referensi transfer,
   Catatan. **`Simpan`**.
4. Di halaman pembayaran, kartu **`Alokasikan ke invoice terbuka`** memuat setiap invoice
   AR **berstatus Disetujui** yang masih bersisa. Isi kotak **Alokasi**, atau tekan
   **`Lunasi`** untuk mengisi seluruh sisa.
5. Bila pelanggan memotong pajak, tekan **`Potongan pajak`** pada barisnya (lihat di
   bawah).
6. Periksa garis rekonsiliasi di kaki kartu: **`Dilunasi: … · Potongan pajak: … · Kas
   diterima: … · Sesuai mutasi bank ✓`**. Bila merah, ia berbunyi `Selisih Rp …`.
7. Tekan **`Posting Pembayaran`**. Notifikasi: *"Penerimaan diposting dan jurnal
   dibuat."*

**`Jumlah` adalah uang yang benar-benar masuk ke rekening, bukan nilai invoice.** Ini
titik yang paling sering salah. Bila pelanggan memotong PPh final atau memungut PPN,
isikan angka **yang tertera pada rekening koran**, lalu catat potongannya lewat tombol
`Potongan pajak` — invoice tetap dilunasi **penuh**. Mengisi Jumlah dengan nilai invoice
akan ditolak:

> "Alokasi ({x}) harus sama dengan uang diterima ({y}) ditambah potongan pajak ({z})."

**Penerimaan tidak melewati persetujuan siapa pun.** Ia berjalan Draf → Terposting
langsung — asimetri yang disengaja: uang masuk sudah dikuatkan rekening koran yang tidak
dikendalikan perusahaan; uang keluar tidak. Seorang pemegang `fin.post` bisa memposting
penerimaan sendirian, dan **`Posting Pembayaran` langsung membentuk jurnal tanpa tombol
batal**.

**Modal `Potongan pajak`** (judul `Potongan pajak & lainnya — {kode invoice}`, tombol
kirim **`Simpan potongan`**) punya delapan isian:

| Isian | Bantuan di layar |
|---|---|
| **PPh final dipotong pelanggan** | "Jasa konstruksi, PP 9/2022: 1,75%–6% dari DPP tergantung kualifikasi. Kosongkan bila tidak dipotong." |
| **Nomor bukti potong PPh final** | "Wajib bila ada potongan PPh final — arsip untuk kredit pajak." |
| Tanggal bukti potong | — |
| **PPh 23 jasa dipotong pelanggan** | "Jasa non-konstruksi: 2% dari nilai jasa untuk penyedia ber-NPWP." |
| **Nomor bukti potong PPh 23** | "Wajib bila ada potongan PPh 23 — nomor inilah bukti kredit pajaknya." |
| **PPN dipungut pemilik (wapu)** | "Untuk pemberi kerja pemungut PPN — PPN-nya disetor sendiri oleh mereka." |
| **Potongan lain-lain (denda/klaim)** | "Denda keterlambatan (lazim 1‰/hari, plafon 5%) atau klaim yang dipotong pemberi kerja." |
| **Alasan potongan lain-lain** | 'Wajib bila ada potongan lain-lain — mis. "denda keterlambatan 10 hari × 1‰, pasal 12 kontrak".' |

**PPh final dan PPh 23 adalah dua kotak berbeda yang tidak boleh tertukar.** PPh final
Pasal 4(2) (jasa konstruksi) habis di situ; PPh 23 (jasa integrasi sistem, pemeliharaan,
konsultasi teknis) adalah **kredit pajak** yang mengurangi PPh Badan akhir tahun.
Keduanya masuk akun berbeda, dan mencampurnya membuat SPT Tahunan salah ke salah satu
arah. **Nomor bukti potong wajib untuk keduanya.**

**`Potongan lain-lain` bukan pajak dan menuntut ALASAN tertulis, bukan bukti potong.**
Tanpa alasan, server menolak barisnya. Tulis dasarnya beserta pasal kontraknya — kalimat
itulah satu-satunya jejak audit yang akan ditemukan pemeriksa.

Membuka modal dan mengisi potongan **otomatis mengisi kotak Alokasi dengan seluruh sisa
invoice** bila masih kosong, karena yang dilunasi adalah nilai invoice penuh.

**Aturan pokoknya: Σ alokasi = kas yang masuk + Σ potongan.** Invoice tetap lunas penuh;
pihak lawan menyetor sebagian ke negara atas nama perusahaan.

Penolakan lain saat memposting:

- `"Invoice {kode} is not approved; it cannot receive payments."`
- `"Allocation {x} exceeds the outstanding {y} on {kode}."`
- `"Potongan pajak harus mengacu pada invoice yang dilunasi oleh pembayaran ini."`
- `"Potongan pajak {x} melebihi sisa tagihan {y} pada {kode}."`
- `"Nomor bukti potong wajib diisi untuk potongan PPh final dipotong pelanggan."`
- `"Alasan potongan lain-lain wajib diisi — sebutkan dasarnya (mis. \"denda keterlambatan
  10 hari × 1‰, pasal sekian kontrak\")."`
- `"Nilai potongan pajak harus lebih besar dari nol."`
- `"Tentukan minimal satu alokasi."`

**Membalikkan penerimaan.** Tombol merah **`Balikkan Pembayaran`** (izin `fin.post`) pada
penerimaan yang sudah Terposting, dengan **Alasan pembalikan** wajib. Ia **pembalikan,
bukan penyuntingan**: jurnal asli tetap berdiri, cerminnya diposting di sebelahnya,
`Sudah dibayar` pada invoice turun kembali, dan status menjadi **Dibalik** — status
akhir, **tidak kembali ke Draf**. Ini satu-satunya jalan keluar dari penerimaan yang
sudah diposting.

Dua hal yang membuat pembalikan **tidak mungkin lagi**, dan keduanya harus Anda periksa
**sebelum** memposting penerimaan atas invoice yang meragukan:

> "Pembayaran {kode} sudah dicocokkan dengan mutasi bank; buka dulu pencocokannya di
> rekonsiliasi bank sebelum membalik pembayaran ini."

> "Retensi dari invoice {kode} sudah dicairkan; pembayaran {kode} tidak dapat dibalik."

Yang kedua tidak punya jalan keluar sama sekali — dan invoice-nya pun tidak bisa
dibatalkan.

Penolakan lain: `"Pembayaran {kode} sudah dibalik pada {tanggal}."` ·
`"Pembayaran {kode} belum diposting (status: …), jadi tidak ada yang perlu dibalik —
hapus drafnya atau tolak pengajuannya."`

### 3.13 Piutang Retensi — `Keuangan › Piutang Retensi`

Deskripsi layarnya: *"Retensi yang ditahan pelanggan dari tiap termin. Jatuh tempo tagih
mengikuti tanggal pada BAST — biasanya akhir masa pemeliharaan."*

Tiga kotak: **Total retensi tertahan** · **Sudah boleh ditagih** · **Posisi per**
(tanggal). Tabel **Retensi belum cair**: Proyek · Kontrak · Dari invoice · **Jatuh tempo
tagih** · Nilai retensi · tombol **`Catat pencairan`**.

**Lencana `Sudah boleh ditagih` menuntut DUA hal sekaligus**, bukan satu:

1. tanggal pengembalian retensi pada BAST proyek sudah lewat, **dan**
2. proyek itu punya **BAST II berstatus Disetujui**.

BAST I yang masih draf, walau mencantumkan tanggal, tidak menyalakannya.

Baris bertulisan **"Belum ada BAST"** bukan berarti belum jatuh tempo — artinya
tanggalnya **tidak diketahui sama sekali**, dan itu sendiri pekerjaan yang tertinggal di
sisi proyek. Tooltip-nya: *"Terbitkan BAST agar tanggal pencairannya diketahui."*

**Mencatat pencairan:**

1. Tekan **`Catat pencairan`** pada barisnya. Modal berjudul `Cairkan retensi {kode
   proyek}` terbuka.
2. Isi **Tanggal uang diterima** (wajib) dan **Masuk ke rekening** (wajib).
3. Tekan **`Catat penerimaan Rp {nilai}`**. Notifikasi: *"Retensi dicairkan — jurnal
   penerimaan diposting."*

**Ini BUKAN dokumen Pembayaran.** Tidak ada nomor RCV, dan ia tidak muncul di daftar
Pembayaran. Ia jurnal langsung — di Rekonsiliasi Bank ia dicocokkan sebagai **baris
jurnal**, bukan sebagai pembayaran.

**Tidak ada pencairan sebagian.** Modalnya tidak punya kotak jumlah; **seluruh baris cair
sekaligus**.

Penolakan: `"Retensi ini sudah dicairkan pada {tanggal}."` · `"Nilai retensi nol; tidak
ada yang dapat dicairkan."` · dan bila belum ada rekening bank sama sekali:
*"Belum ada rekening bank. Buat dulu di Keuangan › Rekening Bank."*

Bila layarnya kosong: *"Belum ada retensi tertahan. Baris muncul otomatis setiap invoice
termin yang memotong retensi disetujui."*

### 3.14 Dua pola retensi — satu kontrak, satu pola saja

Ini aturan tunggal yang paling mudah dilanggar di jalur ini, dan pagarnya berdiri di
empat tempat.

| | **Pola A — potongan per invoice** | **Pola B — retensi sebagai termin** |
|---|---|---|
| Jadwal termin | **tidak** memuat termin bercentang Retensi | memuat satu termin bercentang **Retensi** sebagai bagian dari 100% |
| Cara retensi ditahan | centang `Tahan retensi sesuai kontrak` pada tiap invoice termin | tidak ada potongan retensi di invoice mana pun |
| Cara retensi ditagih | lewat layar **Piutang Retensi**, setelah BAST II disetujui | dengan **menagih termin retensi itu** seperti termin biasa |
| Layar Piutang Retensi | memuat barisnya | **tidak akan pernah memuat baris untuk kontrak ini** |

Server menolak pencampuran keduanya — termasuk bila retensi diketik belakangan saat
mengubah draf invoice:

> "Kontrak {kode} menagih retensinya lewat termin retensi pada jadwalnya sendiri; hapus
> potongan retensi pada invoice ini agar tidak tercatat dobel."

Tombol `Tagih termin ini` sudah mematikan centangnya lebih dulu — itu satu alasan lagi
untuk memakainya dan tidak mengetik ID termin sendiri.

---

## 4. Estimasi — BOQ, AHSP, RAP

Tiga dokumen, tiga fungsi berbeda:

- **AHSP** (Analisa Harga Satuan) — buku harga satuan pekerjaan. Tidak punya status,
  tidak punya persetujuan; menyimpannya berarti menerbitkannya.
- **BOQ / RAB** — daftar pekerjaan beserta volume dan harga jual. **Harga di BOQ yang
  disetujui menjadi plafon harga pembelian** (§4.7).
- **RAP** — anggaran biaya pelaksanaan, diturunkan dari BOQ dengan target margin.
  **RAP yang disetujui menjadi gerbang anggaran setiap PO dan SPK** (§4.7).

Peran `estimator` memegang izin buat/ubah/hapus pada ketiganya, tetapi **tidak memegang
`est.approve`** — estimator tidak bisa menyetujui BOQ atau RAP-nya sendiri. Persetujuan
ada pada direktur dan admin.

### 4.1 Sebelum Anda mulai: BOQ dan RAP tidak diisi lewat formulir

Ini fakta terpenting di bab ini, dan layarnya sendiri salah menyebutkannya.

**Catatan biru pada formulir BOQ berbunyi *"Bagian (section) dan item BOQ dikelola dari
halaman detail setelah BOQ dibuat."* — kalimat itu tidak benar.** Halaman BOQ hanya
menampilkan tabel baca-saja; tidak ada tombol tambah/ubah/hapus untuk bagian maupun item
di layar mana pun.

**Formulir RAP juga tidak punya tabel baris sama sekali.**

Jadi:

| Dokumen | Satu-satunya cara mengisi barisnya |
|---|---|
| **BOQ / RAB** | `Sistem › Impor Dokumen` → **BOQ / RAB**, atau tombol **`Versi Baru`** dari BOQ yang sudah berisi |
| **RAP** | tombol **`Buat dari BOQ`** pada halaman RAP, atau `Sistem › Impor Dokumen` → **RAP** |
| **AHSP** | formulirnya sendiri (punya tabel baris), atau `Sistem › Impor Dokumen` → **AHSP** |

BOQ baru yang disimpan lewat formulir **tinggal kosong bertotal Rp 0 selamanya** sampai
Anda mengimpor barisnya.

### 4.2 AHSP — `Estimasi › AHSP`

Satu catatan = satu "Analisa Harga Satuan". Kolom daftar: Kode · Uraian analisa ·
Kategori · Satuan · Overhead · Harga satuan. Saringan: Kategori (Sipil / Arsitektur /
MEP / ELV / ICT).

**Menyusun satu analisa:**

1. **`Tambah Analisa Harga Satuan`**.
2. Isi **Kode AHSP** (wajib, harus unik; bantuan: *"mis. A.2.3.1.1"*), **Satuan**
   (wajib), **Uraian pekerjaan** (wajib), **Kategori** (wajib), **Overhead & profit (%)**
   (bawaan 10, batas 0–100), Catatan.
3. Isi tabel **Komponen (upah / bahan / alat)** dengan tombol **`Tambah baris`**;
   tanda `×` menghapus baris. Kolomnya: **Jenis** (wajib: Upah/Bahan/Alat) · **Uraian**
   (wajib) · *Item stok* (pencarian ke daftar Item, boleh kosong) · **Satuan** (wajib) ·
   **Koefisien** (wajib) · **Harga satuan** (wajib). Jumlah per baris = koefisien ×
   harga satuan.
4. **`Simpan`**.

**Harga satuan analisa tidak pernah diketik.** Ia dihitung: jumlah semua (koefisien ×
harga satuan, masing-masing dibulatkan ke rupiah lebih dulu) lalu dikalikan
(1 + overhead ÷ 100). Angka itu disimpan dan ditulis ulang setiap kali Anda menyimpan.

**Kolom "Item stok" hanya tautan.** Ia **tidak** mengambil harga komponen dari master
item. Harga satuan komponen tetap Anda ketik.

**AHSP tidak punya siklus persetujuan dan tidak punya kolom status.** Siapa pun yang
memegang `est.update` bisa mengubah harga di buku analisa, dan harga baru itu langsung
mengalir ke baris BOQ berikutnya yang dibuat siapa pun.

Penolakan: menghapus analisa yang sedang dipakai →
`"AHSP {kode} is referenced by BOQ items and cannot be deleted."`; kode ganda ditandai
sebagai galat pada kolom Kode.

**Tidak ada riwayat harga AHSP.** Harga satuan analisa adalah satu angka yang ditimpa
setiap kali disimpan; analisa tidak berversi dan tidak bertanggal. Untuk melihat harga
beli sungguhan sepanjang waktu, pakai **Riwayat Harga Satuan** (§4.6).

**Mencetak:** **`Cetak AHSP`** — *ANALISA HARGA SATUAN PEKERJAAN* (Form F/AHSP).

### 4.3 BOQ / RAB — `Estimasi › BOQ / RAB`

Kolom: Kode · Judul · Versi (v1, v2…) · Proyek · Total · Status. Bisa diubah/dihapus
hanya saat **Draf** atau **Ditolak**.

**Membuat BOQ:**

1. **`Tambah BOQ`**. Isi **Judul** (wajib); Proyek, Kontrak, Penawaran, dan Catatan
   opsional. **`Simpan`**.
2. Buka `Sistem › Impor Dokumen` → **BOQ / RAB** dan impor bagian serta itemnya (§4.5),
   memakai **nomor BOQ yang baru terbit** pada kolom pengelompok.
3. Kembali ke BOQ. Halaman detailnya kini memuat dua tabel: **Seluruh item (datar)** —
   Kode · Uraian · Volume · Satuan · Harga satuan · Jumlah, dalam urutan BOQ, bentuk yang
   paling mudah disalin ke spreadsheet — dan **Bagian & item pekerjaan**, baris yang sama
   dikelompokkan di bawah bagiannya dengan baris Total.
4. **`Ajukan`** → direktur **`Setujui`** atau **`Tolak`**.

Subtotal bagian dan total BOQ dihitung sistem dan tidak punya kolom.

**Baris yang diimpor dengan `ahsp_kode` dan tanpa `harga_satuan` menyalin uraian, satuan,
dan harga dari analisanya PADA SAAT DIIMPOR — dan tidak pernah membacanya ulang.**
Memperbarui harga AHSP setelahnya tidak mengubah apa pun di BOQ itu.

**`Versi Baru`** (muncul saat Disetujui atau Ditolak) — konfirmasi *"Buat versi baru dari
BOQ ini? Versi baru dimulai sebagai draf."* Ia menyalin setiap bagian, item, dan tautan
AHSP ke **BOQ baru bernomor baru**, versi +1, status Draf, lalu membukanya.

**Versi Baru membuat dokumen baru, bukan revisi dokumen yang Anda buka.** BOQ lama tetap
Disetujui dan tetap yang ditunjuk proyek, RAP, dan tugas WBS sampai ada yang
menautkannya ulang.

**BOQ yang sudah disetujui membekukan harganya selamanya.** Memperbarui AHSP setelahnya
tidak mengubah apa pun, dan harga beku itulah yang dijadikan pembanding gerbang harga PO
(§4.7). Memakai harga baru berarti: perbaiki AHSP → buat **Versi Baru** BOQ → impor harga
baru ke versi itu.

Penolakan: `"Cannot {aksi} document {kode} while status is {status}."` ·
`"BOQ {kode} cannot be edited while status is {status}."` ·
`"BOQ {kode} cannot be deleted while status is {status}."`

**Mencetak:** **`Cetak RAB / BOQ`** (Form F/RAB). Berlampiran: ya.

### 4.4 RAP — `Estimasi › RAP`

Judul layarnya **RAP (Anggaran Biaya)**. Kolom: Kode · Dari BOQ · Proyek · Target
margin · Total anggaran · Status.

**Menyusun RAP:**

1. **`Tambah RAP`**. Isi **BOQ sumber** (wajib), Proyek (opsional), **Target margin (%)**
   (wajib, bawaan 15). **`Simpan`**.
2. Di halaman RAP, tekan **`Buat dari BOQ`**. Ia meminta **Target margin (%)** dengan
   bantuan *"Kosongkan untuk memakai margin yang tersimpan."*
3. **Tombol itu MENGHAPUS setiap baris yang ada lalu membangun ulang dari BOQ.** Tidak
   ada cara mempertahankan sebagian baris hasil generate dan menyetel sebagian lainnya
   dengan tangan.
4. Periksa tabel **Rincian anggaran**: Kategori (Material/Upah/Subkon/Alat/Overhead) ·
   Uraian · Volume · Satuan · Biaya satuan · Jumlah, dengan Total.
5. **`Ajukan`** → direktur **`Setujui`**.

Cara hitungnya, per baris BOQ: anggaran = jumlah BOQ ÷ (1 + margin ÷ 100).

- **Bila baris BOQ punya AHSP**, anggaran itu dipecah ke Upah/Bahan/Alat sesuai bauran
  komponennya, dan sisanya (bagian overhead + pembulatan) jatuh ke satu baris Overhead.
- **Bila baris BOQ tidak punya AHSP**, seluruh nilainya menjadi **satu baris SUBKON** —
  lingkup yang tidak dianalisa dianggap disubkontrakkan.

Penolakan: `"RAP {kode} cannot be regenerated while status is {status}."` ·
`"RAP {kode} cannot be edited while status is {status}."`

**Formulir RAP tidak punya kolom BOQ** — sekali dibuat, RAP tidak bisa dipindahkan ke
BOQ lain dari layarnya. Satu-satunya tempat perpindahan itu bisa dicoba adalah layar
impor dokumen (§4.5), dan di sana ia ditolak per baris dengan awalan nama kolomnya:
> "boq_kode: RAP {kode} milik {BOQ} dan tidak dapat dipindahkan ke BOQ lain; buat RAP
> baru untuk BOQ tersebut."

**RAP Rp 0 yang disetujui tetap menjadi gerbang anggaran** setiap PO pada proyek itu —
dan akan menolak setiap pembelian sampai ia dibuat ulang. Jangan menyetujui RAP kosong.

**Mencetak:** **`Cetak RAP`** (Form F/RAP). Berlampiran: ya.

### 4.5 Mengimpor BOQ, AHSP, dan RAP

`Sistem › Impor Dokumen`. Baca §2.9 lebih dulu — terutama dua peringatan besarnya:
memperbarui **mengganti seluruh baris**, dan mengunggah berkas yang sama dua kali
**membuat dokumen kedua**.

Alur layarnya: pilih jenis → **`Unduh template`** → (opsional) ketik kode dokumen lalu
**`Ekspor dokumen`** → pilih berkas → pratinjau per dokumen → **`Simpan {n} dokumen`** →
layar hasil dengan **Dibuat / Diperbarui / Dilewati** dan tabel **Kode dokumen yang
tersimpan**.

**BOQ / RAB.** `tipe` = `dokumen` / `bagian` / `item` / `abaikan`.

| `tipe` | Kolom |
|---|---|
| `dokumen` | `judul` (**wajib**) · `proyek_kode` · `kontrak_kode` · `penawaran_kode` · `catatan` |
| `bagian` | `nomor` (**wajib**, ≤10 karakter) · `uraian` (**wajib**) |
| `item` | `nomor` (**wajib**, ≤20 karakter) · `uraian` · `ahsp_kode` · `volume` (**wajib**) · `satuan` · `harga_satuan` · `jumlah` (hanya pemeriksa silang) |

Item menempel pada **bagian terdekat di atasnya**; item sebelum bagian mana pun ditolak.
Baris berisi `nomor` + `ahsp_kode` + `volume` saja masuk sudah berharga.

Peringatan yang akan Anda baca di pratinjau:

- nomor item berulang: *"nomor item berulang di dalam satu BOQ: … RAP dan tugas WBS
  mencari baris BOQ menurut nomornya dan akan menolak nomor ganda."*
- baris berharga AHSP: *"{n} dari {m} baris pekerjaan dihargai dari analisa AHSP (kolom
  harga_satuan kosong): … total impor yang tertera (Rp X) BELUM memuatnya … nilai BOQ ini
  setelah disimpan adalah Rp Z."*

Penolakan seluruh dokumen — impor BOQ menolak menimpa bila penggantian barisnya akan
merusak sesuatu di luar Estimasi:

> "RAP {kode} ({status}) dibuat dari BOQ ini dan seluruh barisnya akan terhapus; buat
> Versi Baru BOQ lalu impor ke versi itu."

> "{n} tugas WBS proyek / baris permintaan pembelian (PR) / baris SPK subkontraktor
> menunjuk baris BOQ ini; menggantinya memutus tautan itu tanpa jejak — laporan varian
> material kehilangan kuantitas teorinya. Buat Versi Baru BOQ lalu impor ke versi itu."

RAP yang masih **Draf** hanya diperingatkan: *"RAP {kode} dibuat dari BOQ ini; barisnya
akan terhapus dan harus dibuat ulang (Generate dari BOQ) setelah impor selesai."*

**AHSP.** `tipe` = `analisa` / `komponen`, dikelompokkan oleh kode analisanya sendiri.

| `tipe` | Kolom |
|---|---|
| `analisa` | `kode` (**wajib**) · `uraian` (**wajib**) · `satuan` (**wajib**) · `kategori` (**wajib**) · `overhead_persen` · `harga_analisa` |
| `komponen` | `uraian` (**wajib**) · `satuan` (**wajib**) · `jenis` (**wajib**: upah\|tenaga kerja / bahan / alat\|peralatan) · `item_kode` · `koefisien` (**wajib**) · `harga_satuan` (**wajib**) · `jumlah` |

Tiga catatan yang tercetak di layar dan wajib dibaca:

1. **"koefisien memakai koma sebagai desimal (1,05). Titik di kolom koefisien dibaca
   sebagai desimal — bukan pemisah ribuan."** `1.050` berarti satu koma nol lima nol.
   Dibaca terbalik, ia akan mengalikan harga satuan setiap baris BOQ yang memakai analisa
   itu dengan seribu — dan BOQ-nya tetap akan berjumlah benar.
2. `item_kode` hanya menautkan. *"Impor ini tidak pernah membuat item baru — pakai Impor
   Master Data untuk itu."*
3. `overhead_persen` kosong = 10% untuk analisa baru, dan **tidak mengubah** analisa yang
   sudah ada.

`harga_analisa` tidak pernah disimpan, hanya diperiksa. Bila selisihnya melewati toleransi
pembulatan, analisanya **ditolak**:

> "harga_analisa: berkas menulis {X}, tetapi jumlah (koefisien x harga satuan) ditambah
> overhead {n}% ({sumber}) = {Y}. Periksa apakah ada baris komponen yang tertinggal."

Mengimpor ulang analisa yang sedang dipakai diberi peringatan: *"{n} baris BOQ memakai
analisa ini; harga yang sudah tersimpan di BOQ TIDAK ikut berubah — perbarui BOQ-nya bila
harga baru harus dipakai."*

**RAP.** `tipe` = `dokumen` / `item`.

| `tipe` | Kolom |
|---|---|
| `dokumen` | `boq_kode` (**wajib**, tidak bisa dipindah saat memperbarui) · `proyek_kode` · `target_margin` (**wajib**) · `catatan` |
| `item` | `item_boq` (**wajib** — nomor baris BOQ, dicari di dalam BOQ milik RAP itu) · `kategori` (**wajib**) · `uraian` (**wajib**) · `volume` (**wajib**) · `satuan` (**wajib**) · `harga_satuan` (**wajib**) · `jumlah` |

`kategori` menerima material|bahan / upah|tenaga kerja / subkon|subkontrak /
alat|peralatan / overhead|biaya umum.

Impor RAP ditolak bila sudah ada baseline proyek yang membeku terhadapnya:

> "baseline {kode} sudah dibekukan terhadap RAP ini; mengganti rinciannya akan mengubah
> acuan biaya laporan EVM. Buat baseline revisi baru lalu impor ke RAP-nya."

### 4.6 Riwayat Harga Satuan — `Estimasi › Riwayat Harga Satuan`

Layar baca saja. Sub-judulnya: *"Harga beli aktual satu item dari PO yang disetujui dan
valuasi penerimaan gudang (GRN), digambar sebagai satu garis waktu — pembanding sebelum
harga AHSP atau RAB berikutnya ditulis."*

Kendalinya: pemilih item (**terbuka pada item pertama, bukan pada pilihan kosong**),
**Dari tanggal**, **Sampai tanggal** (*"kosong = seluruh riwayat"*), **`Muat ulang`**,
dan ikon cetak yang mencetak layar.

Empat kartu: **Harga terakhir** · **Terendah — tertinggi** · **Rata-rata tertimbang**
("ditimbang volume tiap pembelian") · **Cache item master**.

Grafiknya satu garis waktu: titik **biru = Harga PO (disepakati)**, titik **kuning =
Valuasi GRN (barang datang)**; arahkan tetikus untuk melihat dokumen dan vendornya.
**Sumbu harga tidak dimulai dari nol.**

Tabel **Rincian per dokumen**: Tanggal · Dokumen · Sumber · Vendor · Qty · Harga satuan,
paling lama di atas.

Yang dikatakan kartu "Cara membacanya" di layar itu sendiri: hanya PO **Disetujui atau
Selesai** yang dihitung (draf dan yang ditolak bukan riwayat); valuasi GRN boleh berbeda
sah dari harga PO (ongkos, kiriman parsial) dan **selisih itulah informasinya**; dan
tidak ada apa pun di layar ini yang mengubah harga yang sudah tersimpan di sebuah BOQ.

Bila kosong: *"Belum ada pembelian tercatat untuk item ini pada rentang tanggal terpilih…
Harga di layar lain (AHSP, BOQ) berarti masih berdiri di atas taksiran, bukan riwayat."*

### 4.7 Mengapa BOQ dan RAP mengikat pengadaan

Keduanya berbunyi pada saat yang sama: ketika seseorang menekan **`Ajukan`** pada sebuah
PO (atau SPK subkon). Bila keduanya berbunyi, dialognya datang **satu per satu — harga
dulu, lalu anggaran** — jadi orang yang mengklik lewat yang pertama lalu terkejut oleh
yang kedua tidak sedang mengajukan dua kali.

**a. Kendali harga — harga BOQ adalah plafonnya.** Baris PO yang bisa dilacak kembali ke
sebuah baris BOQ dibandingkan dengan **harga beku** baris itu. Lebih dari **10%** di
atasnya menahan pengajuan sampai dikonfirmasi. Judul dialognya **"Harga di atas harga BOQ
— tetap ajukan?"**, tombol konfirmasinya **`Ya, harga sudah dinegosiasi`**, dan pesannya:

> Baris 3 "Semen PCC 50 kg": harga PO Rp 78.000 di atas harga BOQ beku Rp 68.000
> (+14,71%, ambang 10%). Ajukan ulang dengan konfirmasi bila harga ini memang hasil
> negosiasi terbaik.

Hanya penyimpangan **ke atas** yang diperingatkan. Baris BOQ tanpa harga tidak pernah
dibandingkan.

**b. Gerbang anggaran — RAP yang disetujui adalah anggarannya.** DPP sebuah PO diadu
dengan **RAP disetujui terakhir** proyek itu, sisi **non-subkon** (material + upah + alat
+ overhead) dikurangi realisasi dikurangi komitmen PO yang masih berjalan. Sebuah SPK
subkon diadu dengan sisi **subkon**. Melewati sisanya memunculkan dialog dengan tombol
**`Ya, tetap ajukan`** dan pesan yang menyebut angka anggaran, realisasi, komitmen,
dokumen ini, dan besar pelampauannya.

**Proyek tanpa RAP disetujui tidak punya gerbang anggaran sama sekali** — setiap PO lolos
tanpa suara. RAP yang masih Draf tidak dihitung. Itulah biaya konkret meninggalkan RAP di
status Draf.

**Keduanya peringatan, bukan tembok.** Pembeli bisa mengonfirmasi melewatinya. Yang
ditinggalkannya adalah satu baris jejak persetujuan atas nama pengaju — jadi pembelian
30% di atas RAB bukan diabaikan, melainkan dilampaui secara tercatat.

---

## 5. Permintaan sampai pembayaran

Jalur procure-to-pay: dari lapangan yang butuh semen sampai uangnya keluar dari bank.

### 5.1 Siapa mengerjakan apa

| Langkah | Layar | Yang mengerjakan | Yang dilihat orang berikutnya |
|---|---|---|---|
| 1. Minta barang | Pengadaan › Permintaan (PR) | pengadaan / proyek | — |
| 2. Ajukan PR | tombol `Ajukan` | pengadaan | **direktur** diberi tahu |
| 3. Setujui PR | tombol `Setujui` | direktur | pengadaan melihat tombol `Buat PO` |
| 4. Banding harga (opsional) | Pengadaan › RFQ (Banding Penawaran) | pengadaan | — |
| 5. Buat PO | `Buat PO` dari PR atau RFQ | pengadaan | — |
| 6. Ajukan PO | tombol `Ajukan` | pengadaan | dua gerbang berbunyi (§4.7); direktur diberi tahu |
| 7. Setujui PO | tombol `Setujui` | direktur | **gudang** bisa menerima barangnya |
| 8. Terima barang | Persediaan › Penerimaan (GRN) | gudang, posting oleh admin | bab 6 |
| 9. Buat tagihan vendor | Keuangan › Tagihan Vendor (AP) | keuangan | — |
| 10. Setujui tagihan | tombol `Setujui` | manajer keuangan | hutang terbentuk |
| 11. Bayar | Keuangan › Pembayaran | keuangan menyiapkan, manajer menyetujui, keuangan memposting | — |

**Dua penyerahan yang harus dinamai karena orangnya berbeda:**

- Peran `procurement` **tidak memegang `prc.approve`** — petugas pengadaan tidak bisa
  menyetujui PR maupun PO-nya sendiri. Itu disengaja.
- Peran `procurement` juga **tidak memegang `inv.create`** — ia **tidak bisa membuat
  penerimaan barang**. Penerimaan diketik gudang (bab 6).

### 5.2 Vendor & Subkon — `Pengadaan › Vendor & Subkon`

Kolom: Kode · Nama vendor (dengan kota) · Klasifikasi · Subkon · PKP · Rating · Status.
Saringan: Klasifikasi, Status, Subkontraktor.

Formulir, dua bagian:

- *Identitas vendor*: **Nama vendor** (wajib) · Nama badan hukum · **Kode** (kosong =
  otomatis) · **Klasifikasi** (wajib: Material / Jasa / ICT / Sipil / Mekanikal &
  Elektrikal) · NPWP · **No. SPPKP** (bantuan: *"Wajib bila vendor berstatus PKP"* —
  server memaksanya) · **PKP** · **Subkontraktor** · Status · **Termin bayar (hari)**
  (bawaan 30).
- *Kontak & bank*: Alamat · Kota · Telepon · Email · Nama PIC · Bank · No. rekening ·
  Atas nama.

Tidak ada tombol aksi. Menghapus vendor yang punya PO ditolak:
`"Vendor {code} has purchase orders and cannot be deleted; set it inactive instead."`

**Tiga kolom yang menggerakkan uang, bukan sekadar keterangan:**

- **PKP.** Berbeda dari sisi pelanggan, di sisi vendor centang ini **menghitung**. Tarif
  PPN sebuah PO diisi tarif perusahaan **hanya bila vendornya PKP**, selain itu 0%. Dan
  tagihan yang memungut PPN dari vendor non-PKP ditolak:
  > "Vendor {nama} bukan PKP sehingga tidak dapat menerbitkan faktur pajak; tagihan ini
  > tidak boleh memungut PPN."
- **Termin bayar (hari)** diam-diam mengisi Jatuh tempo tagihan vendor (tanggal tagihan +
  termin), kecuali PO-nya menyebut termin sendiri.
- **Subkontraktor.** Hanya vendor yang bercentang ini yang boleh dipakai pada SPK subkon
  (bab 8): `"Vendor {code} ({nama}) is not registered as a subcontractor."`

**Rating tidak diketik di sini.** Ia rata-rata berjalan dari setiap Evaluasi Vendor
(§5.8), satu desimal, ditulis ulang setiap kali sebuah evaluasi disimpan atau dihapus.

### 5.3 Dokumen Vendor — `Pengadaan › Dokumen Vendor`

Kolom: Vendor · Jenis · Dokumen (dengan nomor) · Berlaku s/d · **Wajib** · **Kedaluwarsa**.
Saringan: Vendor, Jenis, Kedaluwarsa.

Teks bantuan di formulir, kata demi kata:

> "Berlaku s/d: masih sah PADA hari itu; kosongkan bila tidak kedaluwarsa (mis. NPWP).
> Dokumen WAJIB yang lewat masa berlakunya memblokir pengajuan PO/SPK vendor ini (bisa
> di-override beralasan)."

Kolomnya: **Vendor** (wajib) · **Jenis** (wajib: NIB / SIUP / NPWP / SPPKP (PKP) / SBU
Konstruksi / SKK Penanggung Jawab / Sertifikat Principal / Akta Perusahaan / Lainnya) ·
**Nama dokumen** (wajib) · Nomor · Penerbit · Terbit · Berlaku s/d (≥ Terbit) ·
**Wajib untuk PO/SPK** · Catatan.

**Centang "Wajib untuk PO/SPK" adalah gerbangnya.** Prakualifikasi hanya memblokir dua
fakta:

1. vendor berstatus **Nonaktif**, dan
2. ada dokumen **bercentang Wajib** yang **Berlaku s/d**-nya sudah lewat hari ini.

**Register yang kosong tidak memblokir apa pun**, dan dokumen kedaluwarsa yang **tidak**
bercentang Wajib juga tidak memblokir apa pun.

Tenggat memperingatkan **30 hari** di muka untuk pemegang `prc.update`, dan hanya untuk
dokumen milik vendor yang masih **Aktif**.

### 5.4 Permintaan Pembelian (PR) — `Pengadaan › Permintaan (PR)`

Judul layarnya **Permintaan Pembelian (PR)**. Kolom: Kode · Keperluan · Proyek · Gudang ·
Dibutuhkan · Status. Bisa diubah/dihapus hanya saat Draf atau Ditolak.

**Membuat permintaan:**

1. **`Tambah PR`**.
2. Isi kepala: Proyek, Gudang tujuan, **Dibutuhkan tanggal** (wajib), *Diminta oleh*
   (dikosongkan berarti Anda sendiri), Keperluan, Catatan.
3. Isi tabel **Item yang diminta** (minimal 1): Item · Uraian · **Qty** (wajib) · Satuan ·
   Estimasi harga. **Uraian menjadi wajib bila kolom Item dikosongkan.**
4. **`Simpan`**, lalu **`Ajukan`**.

Setelah direktur menekan **`Setujui`**, tombol **`Buat PO`** muncul.

**`Buat PO` dari PR** membuka dialog: **Vendor** (wajib), Tanggal PO (bawaan hari ini),
Perkiraan kirim, Alamat pengiriman, Catatan, dan **Alasan override prakualifikasi**
(*"Isi hanya bila vendor terblokir prakualifikasi dan PO tetap harus dibuat."*).

Ia menyalin **setiap baris PR pada estimasi harganya**, membawa proyek, gudang, dan
tautan BOQ, lalu membuka PO draf yang baru. **Bila kotak Catatan dibiarkan kosong**,
catatannya terisi otomatis *"Dibuat dari {kode PR}"*; catatan yang Anda ketik dipakai
apa adanya, tanpa tambahan.

Penolakan: `"PR {code} already has purchase orders and cannot be deleted."` ·
`"PR {code} is {status} and can no longer be edited."` · dan pemisahan tugas bila Anda
sendiri yang mengajukannya.

**Mencetak:** **`Cetak Permintaan Pembelian`** (Form F/PP). Tidak ada tombol PDF.

**Tenggat** mengingatkan PR yang mendekati/lewat tanggal dibutuhkan **7 hari** di muka,
untuk pemegang `prc.create`, dan **hanya untuk PR berstatus Diajukan atau Disetujui yang
belum punya PO**.

### 5.5 RFQ (Banding Penawaran) — `Pengadaan › RFQ (Banding Penawaran)`

**Tidak ada portal vendor dan tidak ada email di aplikasi ini.** Harga penawaran vendor
**diketik oleh petugas pengadaan** dari apa pun yang dikirim vendor lewat jalur lain.
Tidak ada aksi "kirim RFQ ke vendor"; satu-satunya keluaran adalah **Tabulasi Banding
Penawaran** tercetak, yaitu lembar perbandingan internal, bukan surat permintaan.

Kolom daftar: Kode · Tanggal · Batas penawaran · Proyek · Dari PR · Status. Bisa
diubah/dihapus hanya saat **Draf**.

RFQ **tidak punya siklus persetujuan sama sekali** — hanya Draf dan Selesai. Kendalinya
ada pada PO yang lahir darinya.

**Menyusun lembar banding:**

1. **`Tambah RFQ`**. Bantuan di layar: *"Isi 'Dari PR' untuk menyalin baris PR yang
   disetujui — daftar barang di bawah diabaikan. RFQ mandiri mengetik barisnya sendiri.
   Matriks harga diisi di halaman detail."*
2. Isi **Dari PR (disetujui)** (**hanya bisa diisi saat membuat**), Proyek, **Tanggal
   RFQ** (wajib), Batas masuk penawaran, **Vendor diundang** (wajib; bantuan: *"Harga
   hanya bisa diketik untuk vendor yang diundang di sini."*), Catatan.
3. Bila RFQ mandiri, isi baris: Item · **Uraian** (wajib) · **Qty** (wajib) · Satuan.
4. **`Simpan`**.

> **PERINGATAN — selesaikan kepala, daftar vendor, dan baris barang SEBELUM mengetik satu
> pun harga; setelah itu jangan pernah menekan `Ubah` lagi.**
>
> Menekan **`Ubah`** pada RFQ **menghapus seluruh matriks harga** — setiap harga yang
> sudah diketik dan setiap penanda Pemenang. Itu terjadi bahkan bila Anda hanya mengubah
> Catatan atau Batas penawaran. Dan setelah sebuah PO lahir dari lembar itu, tombol Ubah
> tidak akan pernah berhasil sama sekali:
> `"RFQ {kode} sudah menjadi dasar harga sebuah PO; barisnya tidak dapat diubah lagi —
> perubahan barang berarti lembar banding baru."`
>
> Hal yang sama berlaku pada **mengeluarkan satu vendor dari "Vendor diundang"**: seluruh
> kolom harga vendor itu ikut terhapus, tanpa dialog peringatan.

**Halaman RFQ** adalah layar khusus. Kepalanya: kode + status, `Tanggal … · batas masuk
penawaran … · proyek …`, dan tombol muat ulang, **`Cetak Tabulasi Banding Penawaran`**,
**`Ubah`**, **`Tutup RFQ`**, **`Buat PO dari RFQ`**.

Bila lembarnya lahir dari PR, ada bilah informasi: *"Baris lembar ini disalin dari PR #n
— kuantitas mengikuti kebutuhan yang disetujui di sana."*

**Kartu Tabulasi penawaran**: baris = barang, kolom = vendor terundang. Selama masih
Draf, tiap sel adalah kotak angka; tiap judul kolom vendor punya tombol kecil
**`Menangkan semua`** (*"Jadikan vendor ini pemenang seluruh baris (harus sudah menawar
semua baris)."*). Sel berisi harga memperlihatkan lencana hijau **Pemenang** atau tombol
**`Menang`**. Tombol utama di kepala kartu: **`Simpan harga`**.

Catatan kaki yang tercetak di bawah tabel:

> "Ketik harga satuan yang diterima dari tiap vendor lalu 'Simpan harga'. Tombol 'Menang'
> memilih pemenang per baris; 'Menangkan semua' memilih satu vendor untuk seluruh lembar.
> Pemenang bukan otomatis yang termurah — termurah yang tidak sanggup kirim bukan
> pemenang."

**Sel yang dikosongkan berarti "tidak menawar", bukan nol.** Nol adalah penawaran yang
sah.

Penolakan: `"Vendor {nama} tidak diundang pada RFQ {kode}; tambahkan dulu ke daftar
undangan."` · `"Vendor belum menawar baris 2, 5 pada RFQ {kode}; lengkapi harganya dulu
atau pilih pemenang per baris."` · dan bila tidak ada yang diketik: *"Belum ada harga
yang diketik."*

**`Buat PO dari RFQ`**: bila pemenangnya satu vendor, muncul konfirmasi *"Buat PO draf
untuk {vendor} berisi baris kemenangannya pada harga penawaran pemenang?"*; bila pemenang
terbelah, muncul pemilih **Vendor pemenang** dengan bantuan *"Pemenang terbelah ke
beberapa vendor — satu PO per vendor; ulangi untuk vendor berikutnya."* Notifikasi:
*"PO {kode} dibuat dari harga pemenang."* Tanpa pemenang: *"Pilih pemenang dulu sebelum
membuat PO."*

**Tombol ini tidak membawa kolom override prakualifikasi.** Bila vendor pemenang nonaktif
atau dokumen wajibnya kedaluwarsa, tombolnya gagal dengan penolakan prakualifikasi dan
**tidak ada jalan lewat di layar itu**. Yang harus Anda lakukan: buat PO-nya dari
`Pengadaan › Pesanan (PO)` → **`Tambah PO`** (di sana kolom overridenya ada), atau
perbaiki dokumen vendornya.

**`Tutup RFQ`** dengan konfirmasi *"Tutup lembar banding ini? Harga dan pemenangnya
membeku, tidak bisa diubah lagi."* Setelah ditutup: tidak bisa diubah, tidak bisa
menerima harga baru, tidak bisa mengganti pemenang, dan tidak bisa membuat PO.

Menghapus RFQ yang sudah melahirkan PO ditolak: `"RFQ {kode} sudah melahirkan PO dan
tidak dapat dihapus."`

**RFQ tidak bisa menampung lampiran.** Lembar penawaran vendor yang dipindai tidak bisa
diarsipkan di sini; tempat terdekatnya adalah PR atau PO yang dihasilkannya.

### 5.6 Pesanan Pembelian (PO) — `Pengadaan › Pesanan (PO)`

Judul layarnya **Pesanan Pembelian (PO)**. Kolom: Kode · Vendor · Proyek · Tgl PO ·
Total · Status. Bisa diubah/dihapus hanya saat **Draf atau Ditolak**.

Kepala formulir: **Vendor** (wajib) · Dari PR · Proyek · Gudang tujuan · **Tanggal PO**
(wajib) · Perkiraan kirim (≥ Tanggal PO) · **Termin bayar (hari)** (*"Kosongkan untuk
memakai termin vendor."*) · Diskon · Alamat pengiriman · Catatan · **Alasan override
prakualifikasi**.

Baris **Item pesanan** (minimal 1): Item · **Uraian** (wajib) · **Qty** (wajib) ·
Satuan · **Harga satuan** (wajib).

Strip ringkasan halaman PO: subtotal, diskon, DPP, PPN, total. Tabel barisnya
memperlihatkan Qty, **Diterima**, Satuan, Harga satuan, Jumlah.

**PPN dihitung, tidak pernah diketik:** subtotal − diskon = DPP; PPN = DPP × tarif
perusahaan **hanya bila vendor PKP**, selain itu 0; total = DPP + PPN.

> **PERINGATAN — menekan `Ubah` pada PO diam-diam mematikan gerbang harga.** Menyimpan
> ulang menulis ulang setiap baris tanpa tautan ke baris BOQ-nya, sehingga kendali harga
> (§4.7) tidak lagi punya pembanding. PO yang paling mungkin diubah justru PO yang
> harganya sedang dinegosiasi ulang — persis PO yang gerbang itu ada untuknya. Bila
> harganya berubah, pertimbangkan membuat PO baru dari PR-nya alih-alih mengubah.

**Mengajukan PO.** Tombol **`Ajukan`** **selalu membuka dialog lebih dulu**, berisi satu
kotak **Alasan override prakualifikasi** (*"Kosongkan bila vendor sehat. Isi hanya bila
pengajuan ditolak gate prakualifikasi dan tetap harus jalan (mis. pembelian darurat ke
pemegang lisensi tunggal)."*).

Yang diperiksa `Ajukan`, berurutan:

1. **Vendor terhapus** → *"Vendor PO {kode} sudah dihapus; pilih vendor lain sebelum
   mengajukan."*
2. **Gerbang prakualifikasi** →
   > "Vendor {kode} ({nama}) belum lolos prakualifikasi: vendor berstatus nonaktif;
   > dokumen wajib {nama} kedaluwarsa sejak dd-mm-yyyy. Sertakan alasan override
   > (qualification_override_reason) bila tetap harus diajukan."

   Mengetik alasan meloloskannya. **Alasan hanya tercap bila gerbangnya benar-benar
   memblokir**: alasan yang diketik untuk vendor sehat **dibuang dengan sengaja**, supaya
   jejak audit tidak pernah menuduh vendor yang bersih. Orang akan melaporkannya sebagai
   "catatan saya hilang" — itu memang perilakunya.
3. **Kendali harga** (§4.7) — dialog "Harga di atas harga BOQ — tetap ajukan?".
4. **Gerbang anggaran** (§4.7) — dialog "Melampaui sisa anggaran RAP — tetap ajukan?".

**Menyetujui PO.** Dua hal bisa menolaknya:

- **Pemisahan tugas** — penekan `Ajukan` tidak boleh menyetujui (§2.5).
- **Ambang direktur.** PO bernilai **Rp 100.000.000 atau lebih** dicap saat pengajuan
  sebagai perlu persetujuan direktur. Penyetuju bukan-direktur ditolak:
  > "Pesanan Pembelian {kode} senilai Rp … mencapai ambang persetujuan direktur Rp
  > 100.000.000; dokumen ini hanya dapat disetujui oleh pemegang izin prc.approve-director
  > — pada instalasi standar peran direktur. Minta persetujuan direktur, atau ubah
  > ambangnya di Pengaturan → Proyek & Persetujuan bila kebijakan perusahaan memang
  > berbeda."

  Aturan yang mengikat penyetuju adalah aturan yang tercap **saat pengajuan**, walau
  ambangnya berubah sesudahnya.

**Menutup PO.** Tombol **`Tutup PO`** (hanya pada PO Disetujui) dengan konfirmasi
*"Tutup PO ini? Sisa kuantitas yang belum diterima dibatalkan."*

**PO juga menutup DIRINYA SENDIRI** begitu baris terakhir diterima penuh — tidak ada yang
menekan Tutup PO. Akibat yang membuat orang tersandung: PO itu hilang dari Baris PO
Terbuka, menolak penerimaan barang berikutnya (`"…which is closed; only an approved
purchase order can receive goods"`), dan kiriman pengganti harus dibukukan atas nama
vendor tanpa nomor PO — kecuali sebuah retur yang diposting membukanya kembali.

**`Tutup PO` memaafkan sisa kiriman secara permanen.** Ia jalan keluar untuk kiriman
kurang yang memang Anda terima — **bukan** untuk kiriman yang tidak pernah datang.
Menutup PO yang belum menerima apa pun **tidak** membuat tagihannya bisa disetujui;
penolakan yang berbeda dan lebih panjang tetap berdiri (§5.9).

**Kalimat sukses `Tutup PO` dibuang layar.** Notifikasi selalu berbunyi
`Tutup PO berhasil.`, jadi pesan permintaan evaluasi vendor tidak pernah tampil di layar
— ia hanya sampai sebagai pemberitahuan (§5.8). Hal yang sama berlaku pada `Posting ke
Stok`.

**Mencetak.** Dua tombol berbeda: **`PDF`** (pesanan komersial yang dikirim ke pemasok)
dan **`Cetak Pesanan Pembelian (Formulir Rumah)`** (Form F/PO — lembar yang disimpan
berkas proyek).

### 5.7 Baris PO Terbuka — `Pengadaan › Baris PO Terbuka`

Layar baca saja. Sub-judulnya: *"Barang yang sudah dipesan (PO disetujui) tetapi belum
diterima penuh di gudang — yang lewat batas kirim tampil paling atas."*

Tiga kotak: **Baris terbuka** · **Lewat batas kirim** ("kejar vendornya hari ini") ·
**Nilai belum diterima** ("komitmen yang belum jadi stok").

Tabel **Baris belum terkirim penuh**: PO · Vendor · Uraian barang · Diterima / dipesan ·
Sisa · Nilai sisa · Batas kirim · **Telat** (merah). Klik baris untuk membuka PO-nya.

Hanya PO **Disetujui yang belum ditutup** yang muncul. PO tanpa Perkiraan kirim diurutkan
paling belakang dan tidak pernah dihitung telat.

**Layar ini tidak punya saringan, tidak punya kotak cari, dan tidak punya ekspor.**

Bila kosong: *"Tidak ada baris PO yang terbuka. Daftar ini hanya memuat baris PO disetujui
yang belum diterima penuh; PO draf dan PO yang sudah ditutup tidak dihitung."*

Tenggat juga memuat **"Pesanan pembelian lewat tanggal terima"** untuk pemegang
`prc.update`.

### 5.8 Evaluasi Vendor — `Pengadaan › Evaluasi Vendor`

Kolom: Vendor · Periode · Kualitas · Pengiriman · Harga · Layanan · **Skor**. Saringan:
Vendor saja. Bantuan di formulir: *"Skor 1 (buruk) sampai 5 (sangat baik). Skor total
adalah rata-rata keempatnya."*

Kolom: **Vendor** (wajib) · Proyek · **Periode** (wajib; *"mis. 2026-S1"*) · Dievaluasi
oleh · **Kualitas (1-5)** · **Ketepatan kirim (1-5)** · **Harga (1-5)** · **Layanan
(1-5)** · Catatan. Keempat skor wajib.

**Skor "Ketepatan kirim" Anda ketik sendiri.** Server bisa menghitungnya dari tanggal
penerimaan terhadap Perkiraan kirim PO, tetapi kolom itu wajib diisi di layar sehingga
usulan otomatisnya tidak pernah tercapai dari antarmuka. Anggap ia angka penilaian
manusia.

Menyimpan evaluasi menyegarkan **Rating** vendor (rata-rata berjalan seluruh evaluasi,
satu desimal); menghapus evaluasi juga menghitungnya ulang.

**Kapan sistem memintanya.** Menutup sebuah PO — manual maupun otomatis saat diterima
penuh — yang nilainya **Rp 100.000.000 atau lebih**, sementara vendornya belum dievaluasi
dalam 6 bulan terakhir, mengirim pemberitahuan ke **semua pemegang `prc.create`**:
judul *"Evaluasi vendor diperlukan"*, isi *"Nilai PO/2026/… (Rp …) mencapai ambang
evaluasi dan {vendor} belum dievaluasi 6 bulan terakhir — isi Evaluasi Vendor."*

**Mencetak:** **`Cetak Evaluasi Vendor`** (Form F/EV). Layar ini **tidak** menerima
lampiran.

### 5.9 Tagihan Vendor (AP) — `Keuangan › Tagihan Vendor (AP)`

Kolom: Kode · Vendor · Tgl tagihan · Jatuh tempo · Total bayar · **Sisa** (hijau bila
nol) · Status. Bisa diubah/dihapus hanya saat Draf atau Ditolak.

Bantuan di formulir: *"Isi PO atau opname subkon untuk menyalin nilainya otomatis;
kosongkan keduanya untuk tagihan manual."*

**Lima kolom pertama hanya ada saat MEMBUAT** — semuanya hilang dari formulir Ubah:

| Kolom | Bantuan di layar |
|---|---|
| **Dari PO** | — |
| **Dari opname subkon** | — |
| **Tagihan uang muka (DP) atas PO** | "DP ke pemasok sebelum barang datang. Dicatat sebagai uang muka, BUKAN beban proyek, lalu dikreditkan kembali otomatis saat tagihan final PO yang sama disetujui." |
| **Atas penerimaan barang (GRN)** | "Untuk barang yang sudah diterima tanpa PO: menagihkan akrual penerimaan yang menggantung di 2-1150 supaya tidak mengendap di neraca." |
| **Tagihan parsial: GRN yang ditagih** | "Isi bersama 'Dari PO' untuk menagih sebagian pengiriman: pilih penerimaan (GRN) PO itu yang difakturkan vendor — nilainya dihitung dari qty diterima x harga PO, diskon dan uang muka dipotong proporsional. Kosongkan untuk menagih seluruh PO." |

Sisanya: Vendor · Proyek · **No. invoice vendor** (**wajib dalam setiap mode**) ·
No. faktur pajak · Keterangan · DPP · PPN masukan · **Jenis PPh dipotong** · PPh dipotong
(*"Kosongkan untuk menghitung dari tarif pajak yang dipilih."*) · Tanggal tagihan ·
Jatuh tempo.

Aritmetikanya: **total bayar = DPP + PPN − PPh − retensi**.

**Empat mode, dipilih server berdasarkan kolom yang Anda isi**, dan **formulir tidak
memberi petunjuk mode mana Anda sedang berada:**

| Mode | Yang dipakai server |
|---|---|
| **Dari PO, tagihan final** | DPP dan PPN **disalin dari PO** dikurangi uang muka yang sudah disetujui. **Apa pun yang Anda ketik di DPP/PPN diabaikan diam-diam.** |
| **Dari PO, uang muka (DP)** | Anda **harus** menyebut DPP-nya; PPN mengikuti tarif PO kecuali Anda mengetiknya. |
| **Tagihan parsial** | DPP = Σ(qty diterima × harga PO) dari GRN yang disebut, dikurangi porsi diskon dan uang muka. Ketikan Anda diabaikan. |
| **Manual** | Vendor, Keterangan, dan DPP menjadi wajib. |

**Pemilih "GRN yang ditagih" memuat SETIAP penerimaan di sistem**, bukan hanya milik PO
yang Anda pilih. Salah pilih dijawab `"GRN {kode} bukan penerimaan atas {PO}."` Hal yang
sama berlaku pada pemilih "PO terkait" di layar GRN dan pemilih "Dari PR" di RFQ: draf
dan dokumen yang sudah ditutup ikut ditawarkan dan baru ditolak setelah Anda menyimpan.

**Kedua mode penagihan PO saling meniadakan dan pilihannya praktis tidak bisa dibatalkan:**
begitu ada satu tagihan parsial, Anda tidak akan pernah bisa menagih seluruh PO; begitu
ada tagihan seluruh PO, Anda tidak akan pernah bisa menagih parsial. **Tidak ada layar
yang memberi tahu sebuah PO terkunci di mode yang mana** — Anda mengetahuinya dari
penolakan.

**Uang muka harus tagihan PERTAMA pada PO-nya, dan hanya boleh satu.** Ia membukukan
**aset**, bukan biaya proyek, dan ia satu-satunya tagihan PO yang bisa disetujui dan
dibayar tanpa barang datang. Petugas yang mencentangnya "karena kami bayar di muka" pada
kiriman yang sudah tiba akan membuat realisasi proyek itu terlalu kecil sampai tagihan
finalnya masuk.

Penolakan saat membuat, yang paling sering ditemui:

- `"PO {kode} sudah memiliki tagihan atas seluruh pesanan; penerimaan yang datang
  setelahnya ditagihkan lewat penerimaan barangnya."`
- `"PO {kode} sudah ditagih parsial; pilih penerimaan barang yang akan ditagih pada
  tagihan berikutnya."`
- `"GRN {kode} sudah tercakup pada tagihan {kode}."` · `"GRN {kode} bukan penerimaan atas
  {PO}."` · `"GRN {kode} berstatus draft; hanya penerimaan yang sudah diposting dapat
  ditagih."`
- `"Uang muka atas {PO} tidak memotong PPh; potong PPh pada tagihan finalnya."` ·
  `"Uang muka atas {PO} tidak boleh melebihi nilai PO (…)."` · `"Uang muka hanya dapat
  dibuat atas pesanan pembelian (PO)."`
- `"A bill already exists for PO {kode}."` · `"PO {kode} is draft; only approved POs can
  be billed."`

**`Setujui` adalah pencocokan tiga arah, dan ia yang membukukan hutangnya.** Yang
ditolaknya:

- Barang belum diterima seluruhnya:
  > "Tagihan atas {PO} hanya dapat disetujui setelah barang diterima seluruhnya. Terima
  > sisa barang atau tutup PO terlebih dahulu."
- Belum ada penerimaan sama sekali (dan **menutup PO tidak melewati yang ini**):
  > "Tagihan atas {PO} hanya dapat disetujui setelah barang diterima: belum ada
  > penerimaan barang yang diposting atas pesanan ini, sehingga tidak ada nilai yang
  > dapat dicocokkan dan seluruh tagihan akan dibebankan ke proyek atas barang yang belum
  > ada — lalu dibebankan lagi ketika barangnya datang dan dipakai. Posting penerimaan
  > barangnya lebih dulu; kalau kirimannya sudah dicatat atas nama vendor tanpa nomor PO,
  > tagihkan lewat penerimaan barang tersebut; kalau barangnya memang tidak akan datang,
  > pesanan ini tidak boleh ditagih ke proyek."
- `"Uang muka atas {PO} masih menunggu persetujuan; setujui atau tolak dulu sebelum
  membuat tagihan final."`
- Vendor non-PKP dengan PPN (§5.2).
- PPh tanpa jenisnya:
  > "Tagihan yang memotong PPh harus menyebut jenis PPh-nya; pilih 'Jenis PPh dipotong'
  > agar potongannya masuk ke akun hutang pajak yang benar."
- Pemisahan tugas (§2.5).

**Kategori biaya RAP yang dibebani tagihan tidak bisa dipilih di layar.** Tidak ada kolom
untuk itu; penurunannya selalu: opname subkon → **Subkon**; apa pun yang punya PO atau
GRN → **Material**; sisanya → **Overhead**. Crane yang disewa lewat PO jasa karena itu
dibebankan ke proyek sebagai **Material**.

**Membatalkan tagihan.** **`Batalkan Dokumen`** (izin `fin.post`, hanya saat Disetujui
**dan** belum dibayar sepeser pun; *Alasan pembatalan* wajib minimal 5 karakter) memposting
**jurnal pembalik** — jurnal asli tidak pernah disentuh — melepas slot tagihan final PO,
menghapus klaim parsialnya, dan menghapus baris biaya proyeknya. Status **Dibatalkan**
adalah akhir, bukan jalan kembali ke draf.

**Urutannya penting:** tagihan yang sudah dibayar tidak bisa dibatalkan
(`"Tagihan {kode} sudah dibayar …; hanya tagihan yang belum dibayar yang dapat
dibatalkan…"`), jadi **balikkan pembayarannya dulu, baru batalkan tagihannya**.

**Tidak ada pembatalan sebagian dan tidak ada dokumen nota kredit.** Nota kredit vendor
dibukukan sebagai jurnal manual di Keuangan — pesan-pesan penolakan di atas mengatakannya
sendiri.

**Tidak ada saringan "belum lunas" pada daftar AP**, dan **tidak ada alarm Tenggat untuk
tagihan vendor yang jatuh tempo** (Tenggat hanya mengawasi invoice pelanggan). Pengganti
praktisnya: klik judul kolom **Jatuh tempo** untuk mengurutkan, lalu baca kolom **Sisa**
— ia berubah hijau di angka nol.

**Mencetak:** **`Cetak Lembar Verifikasi Tagihan`** (Form F/VT) — lembar yang
ditandatangani pemeriksa sebelum uang dilepas.

### 5.10 Membayar vendor — `Keuangan › Pembayaran`

**Uang keluar berjalan Draf → Diajukan → Disetujui → Terposting.** Tiga orang berbeda,
dan itu memang maksudnya.

1. **Petugas keuangan** menekan **`Tambah Pembayaran`**, memilih **Arah = Pengeluaran
   (PAY)** (hanya bisa dipilih saat membuat), mengisi Tanggal, **Rekening bank**,
   **Jumlah**, Referensi transfer, Catatan. **`Simpan`**.
2. Di halaman pembayaran, kartu **`Alokasikan ke tagihan terbuka`** memuat setiap tagihan
   AP **Disetujui** yang masih bersisa: Dokumen · Vendor · Jatuh tempo · Sisa · **Alokasi**
   · tombol **`Lunasi`**.
   Untuk uang keluar ada kartu kedua, **`Bayar kewajiban non-AP (gaji, pajak, BPJS)`**,
   lengkap dengan plafon per akun. **Mengetik di satu kartu mematikan kartu yang lain** —
   satu pembayaran melunasi tagihan vendor **atau** kewajiban akun, tidak keduanya.
3. Periksa kaki kartu: `Dilunasi: … · Kas diterima: … · Sesuai mutasi bank ✓` atau
   `Selisih Rp …` merah.
4. Tekan **`Ajukan Pembayaran`** (atau **`Ajukan Ulang`** setelah ditolak).
5. **Manajer keuangan** membuka pembayaran itu dan menekan **`Setujui`** atau **`Tolak`**
   (*Alasan penolakan* wajib; bantuannya: *"Petugas yang menyiapkan harus tahu apa yang
   perlu diperbaiki."*). Orang yang tidak berhak melihat bilah informasi: *"Menunggu
   persetujuan. Hanya pemegang izin fin.approve — peran finance-manager atau direktur —
   yang dapat menyetujui atau menolak pembayaran ini, dan bukan orang yang mengajukannya."*
6. **Petugas keuangan** kembali dan menekan **`Posting Pembayaran`**. Konfirmasinya
   menyebut angkanya: *"Rp 232.545.000 keluar dari BCA Operasional untuk melunasi
   BIL/2026/…. Jurnalnya langsung terbentuk dan pembayaran tidak dapat diubah lagi."*

**Konfirmasi itu adalah perhentian terakhir.** Setelahnya satu-satunya obat adalah
**`Balikkan Pembayaran`** (izin `fin.post`, *Alasan pembalikan* wajib), yang memposting
jurnal cermin, mengembalikan `Sudah dibayar` pada setiap dokumen, dan mendaratkan
pembayaran pada status akhir **Dibalik** — bukan kembali ke draf. Bannernya sesudah itu:
*"Jurnal aslinya tetap berdiri dan jurnal pembaliknya diposting di sebelahnya; dokumen
yang dilunasinya sudah dibuka kembali."*

Pembayaran yang **ditolak** kembali dengan alokasinya utuh dan sebuah banner yang
menyebut siapa yang menolak dan mengapa, plus *"Perbaiki lalu ajukan ulang; alokasi yang
sebelumnya diajukan tetap tersimpan."* Bila sebagian alokasi itu tidak bisa diisi lagi,
muncul peringatan: *"n alokasi yang sebelumnya diajukan tidak dapat diisikan kembali
karena tagihannya sudah lunas, dibatalkan, atau belum disetujui … Alokasikan ulang sebelum
mengajukan."*

Penolakan yang akan Anda temui:

- `"Allocations (…) must sum to the payment amount (…)."`
- `"Bill {kode} is not approved; it cannot be paid."`
- `"Allocation … exceeds the outstanding … on {kode}."`
- `"Satu pembayaran melunasi tagihan vendor ATAU kewajiban non-AP, tidak keduanya —
  pisahkan sesuai mutasi banknya."`
- `"Alokasi pembayaran {kode} berbeda dari yang disetujui. Ajukan ulang bila alokasinya
  berubah."` — teks Referensi/NTPN **sengaja di luar perbandingan itu**, jadi
  mengoreksinya setelah persetujuan tetap boleh.
- `"Pembayaran {kode} belum disetujui, jadi belum boleh diposting."`
- Pemisahan tugas (§2.5).

**Tidak ada tombol "Bayar" pada tagihan vendor**, dan **arah pembayaran tidak bisa diubah
setelah dibuat**. Setiap pembayaran selalu dimulai dari `Keuangan › Pembayaran` →
`Tambah Pembayaran`.

**Mencetak:** **`Cetak Bukti Pembayaran / Penerimaan`** (Form F/BP).

---

## 6. Gudang & persediaan

### 6.1 Baca ini lebih dulu: siapa yang boleh memposting

Pada susunan peran bawaan, izin posting stok (`inv.post`) dipegang **`admin` dan —
sejak 22 Agustus 2026 — `teknisi`**. Izin itu diberikan kepada teknisi supaya ia bisa
mengesahkan sendiri berita acara servis yang memakai suku cadang (§12.5); harganya,
yang diterima sadar, adalah bahwa izin ini juga membuka seluruh tombol stok di bab
ini. Setiap tombol yang benar-benar menggerakkan stok bergerbang izin itu:

**`Posting ke Stok`** (penerimaan dan bon) · **`Kirim`** dan **`Terima`** (transfer) ·
**`Posting Retur`** (kedua jenis retur) · **`Batalkan Penerimaan`** · **`Batalkan Bon`**.

Artinya seorang penjaga gudang dengan peran `warehouse` **bisa mengetik draf tetapi tidak
bisa memposting**, dan tombol-tombol itu **tidak digambar sama sekali** di layarnya —
bukan abu-abu, melainkan tidak ada. **Layar Anda tidak rusak.** Yang harus Anda lakukan:
siapkan drafnya sampai benar, lalu minta pemegang `inv.post` memposting.

Hal yang sama berlaku pada **`Setujui`** opname stok, yang butuh `inv.approve` — dipegang
admin dan direktur saja. Direktur pun **tidak** memegang `inv.post`.

Bila perusahaan Anda ingin penjaga gudangnya bisa memposting, itu perubahan peran oleh
administrator (`docs/PANDUAN-ADMINISTRATOR.md` §3).

### 6.2 Saldo Stok — `Persediaan › Saldo Stok`

Layar khusus, sub-judulnya *"Kartu stok moving-average per gudang."* Kendalinya: kotak
**"Cari item…"**, dropdown gudang (**"Semua gudang"**), dan ikon muat ulang. Tiga tab.

**Tab `Saldo per gudang`.** Kotak angka **Nilai persediaan** (dihitung atas seluruh
saringan) dan — **hanya ketika tidak ada saringan gudang maupun kata cari** — kotak
**"Dalam perjalanan · n transfer"** dan **"Total dimiliki"**. Tabelnya: Item · Gudang ·
Qty · HPP rata-rata · Nilai, dengan kaki berlabel **"Total halaman ini"** (200 baris,
tanpa pager).

**Tab `Kartu stok (ledger)`.** Tanggal · Item · Gudang · **Arah** (Masuk hijau / Keluar
kuning) · Qty · HPP satuan · Saldo setelah · Referensi. **200 baris pertama, PALING LAMA
DI ATAS, tanpa pager dan tanpa saringan tanggal** — pada item yang sibuk, justru mutasi
terbarulah yang tidak terlihat. Persempit dengan dropdown gudang.

**Kolom Referensi menampilkan nama teknis berbahasa Inggris, bukan nomor dokumen:**
`GoodsReceipt`, `Issue`, `Transfer`, `StockAdjustment`, `IssueReturn`, `PurchaseReturn`.
Ia tidak bisa diklik dan tidak pernah menyebut GRN atau bon yang mana. Temukan dokumennya
di layarnya sendiri lewat tanggal dan gudangnya.

**Tab `Di bawah minimum`.** Item · Gudang · Stok · Minimum · **Kurang** (merah). Bila
aman: *"Semua item berada di atas stok minimum."*

Dua hal yang membuat tab itu diam padahal tidak seharusnya:

- **Ia hanya memuat item yang sudah punya baris saldo di gudang itu.** Item yang belum
  pernah masuk ke sebuah gudang tidak akan pernah muncul, setinggi apa pun Stok
  minimumnya.
- **Stok minimum adalah satu angka pada master item yang diterapkan ke SETIAP gudang
  secara terpisah.** Angka 100 berarti "100 di tiap gudang yang pernah memegangnya",
  bukan 100 secara keseluruhan.

**Kotak cari hanya bekerja di tab pertama.** Di dua tab lain, mengetik tidak menyaring
apa pun.

**Selama transfer dalam perjalanan, barangnya tidak ada di gudang mana pun.** Ia hilang
dari baris Saldo kedua gudang. Nilainya duduk di kotak "Dalam perjalanan" — tetapi kotak
itu **hanya muncul bila tidak ada saringan gudang dan tidak ada kata cari**. Menyaring
per gudang membuat angkanya hilang sementara barangnya masih di jalan.

### 6.3 Master: Item, Kategori Item, Gudang

**`Persediaan › Item`.** Kolom: Kode · Nama item (dengan kategorinya) · Jenis · Satuan ·
Stok min. · HPP rata-rata · Aktif. Formulir: **Nama item** (wajib) · Kode (*"Kosongkan
untuk penomoran otomatis (ITM-xxxx)."*) · **Kategori** (wajib) · **Jenis item** (wajib,
bawaan Material) · **Satuan** (wajib) · Barcode · Stok minimum · Harga beli terakhir ·
Aktif. Tidak ada persetujuan; **HPP rata-rata tidak pernah bisa diketik**.

> **"Jenis item" menentukan pos biaya proyek yang dibebani setiap bon selamanya.**
> **Alat Bantu** membebani anggaran **Alat** proyek; **Material**, **Sparepart**, dan
> **Barang Dagangan** semuanya membebani **Material**. Satu dropdown yang dipilih sekali
> saat item dibuat mengarahkan setiap rupiah yang item itu habiskan di setiap proyek.

Menghapus item ditolak bila masih ada stoknya (*"Item masih memiliki stok dan tidak dapat
dihapus."*) atau masih dalam perjalanan (*"Item ini sedang dalam perjalanan antar gudang
dan tidak dapat dihapus. Terima dulu transfernya di gudang tujuan, baru hapus itemnya."*).

**"HPP rata-rata" berarti dua angka berbeda di dua layar.** Di daftar **Item** ia
rata-rata tertimbang item itu **di seluruh gudang**; di **Saldo Stok** ia rata-rata di
**satu gudang**. **Sebuah bon selalu dinilai pada angka GUDANG.**

**`Persediaan › Kategori Item`** — tanpa halaman detail; ubah/hapus dari ikon di baris.
Kode (wajib, unik) · Nama kategori (wajib) · Kategori induk. Menghapus ditolak:
*"Kategori masih dipakai oleh item atau sub-kategori."*

**`Persediaan › Gudang`.** **Kode** (wajib, unik, **tidak ada penomoran otomatis**) ·
**Nama gudang** (wajib) · **Proyek (gudang site)** (*"Diisi bila gudang berada di lokasi
proyek."*) · Penjaga gudang · Alamat · Aktif. Kolom "Gudang site" pada daftar bukan
kolom isian — ia bernilai benar tepat ketika Proyek terisi. Menghapus ditolak:
*"Gudang masih memiliki stok dan tidak dapat dihapus."*

Halaman gudang membawa tombol **`Cetak Daftar Saldo Stok`** (Form F/SS, mendatar) — dan
lembarnya **selalu bertanggal hari Anda mencetaknya**, karena sistem tidak menyimpan
riwayat saldo (§6.10).

**Mengimpor master.** Dari empat tabel di `Sistem › Impor Data Master`, hanya
**Item / Material** yang termasuk lajur ini: `kode` (**wajib**) · `nama` (**wajib**) ·
`satuan` (**wajib**) · `jenis` · `kategori_kode` (**wajib, harus sudah ada**) ·
`stok_minimum` · `harga_beli_terakhir` · `barcode` · `aktif`. **Gudang dan Kategori Item
tidak punya pengimpor**, dan tidak ada pengimpor untuk transaksi stok apa pun.

### 6.4 Penerimaan Barang (GRN) — `Persediaan › Penerimaan (GRN)`

Kolom: Kode · Tanggal · Gudang · PO · No. surat jalan · Status (Draf / Diposting /
Dibatalkan). Bisa diubah/dihapus **hanya saat Draf**.

**Menerima barang atas sebuah PO — urutan yang benar:**

1. **`Tambah GRN`**.
2. Isi **Gudang penerima** (wajib), **Tanggal terima** (wajib), **PO terkait**, Vendor,
   **No. surat jalan**, Catatan.
3. **Tekan `Salin baris dari PO`** di kepala tabel baris. Ia mengisi satu baris per baris
   PO dengan **sisa kuantitas** pada harga PO.
4. Sesuaikan Qty sesuai barang yang benar-benar datang. **`Simpan`**.
5. Minta pemegang `inv.post` menekan **`Posting ke Stok`**.

> **Mengetik baris dengan tangan alih-alih menekan `Salin baris dari PO` adalah kesalahan
> paling mahal di lajur ini.** Hanya baris hasil salin yang terhubung ke baris PO-nya.
> Tanpa tautan itu: kolom **Diterima** pada PO tetap 0, PO tidak pernah menutup, ia terus
> tampil di Baris PO Terbuka, batas kuantitas hanya diperiksa oleh pemeriksa cadangan
> yang lebih lemah, dan **tagihan final PO ditolak** dengan *"hanya dapat disetujui
> setelah barang diterima seluruhnya"*.
>
> Halaman GRN memperlihatkannya per baris pada kolom **Baris PO**: lencana **Tertaut**
> atau lencana kuning **Lepas**. Ajari mata Anda membaca kolom itu.

Teks bantuan tabel barisnya sendiri mengatakannya: *"Untuk penerimaan atas PO, pakai
'Salin baris dari PO' — hanya lewat jalur itu baris terhubung ke baris PO-nya, sehingga
kolom 'diterima' pada PO ikut terisi, penerimaan melebihi pesanan tertahan, dan tagihan
final PO bisa disetujui."*

**`Salin baris dari PO` MENGGANTI setiap baris yang sudah ada — ia tidak menambahkan.**
Dan ia menyalin **sisa** kuantitas, bukan kuantitas pesanan: pengiriman kedua atas PO
yang sama hanya menawarkan yang masih kurang. Tanpa PO terpilih: *"Pilih PO terkait dulu
di bagian atas formulir."* Bila sudah habis: *"Seluruh baris PO ini sudah diterima
penuh."*

**Penjaga harga Rp 0 — berbunyi saat MENYIMPAN, bukan saat memposting.** Baris yang
tertaut ke baris PO dengan Harga satuan 0 ditolak sampai dikonfirmasi. Dialog
**"Harga satuan Rp 0 — lanjutkan?"**, tombol **`Ya, barang gratis`**, pesan:

> "Semen PCC 50 kg" diterima dengan harga satuan Rp 0 padahal tertaut baris PO — stok
> masuk bernilai nol dan HPP rata-rata gudang ikut turun. Lanjutkan hanya bila memang
> barang gratis (free-issue/bonus).

**Baca nama item di dialog itu sebelum mengonfirmasi.** Bila Anda menekan "Ya, barang
gratis" pada baris yang harganya hanya terhapus tak sengaja, HPP rata-rata gudang turun
**permanen** — setiap bon berikutnya membebani proyek pada harga yang tertekan itu, dan
tidak ada layar yang pernah menandainya. Baris yang tidak tertaut PO memang boleh
berharga nol dan tidak ditanyai.

**`Posting ke Stok`** (konfirmasi *"Posting GRN ini? Stok dan HPP rata-rata bergerak akan
diperbarui dan dokumen tidak bisa diubah lagi."*) adalah **satu-satunya langkah di lajur
pengadaan yang menggerakkan stok** dan membukukan ke buku besar.

Penolakan saat memposting:

- `"GRN {kode} is {status}; only draft GRNs can be posted."` · `"GRN {kode} has no lines
  to post."`
- `"GRN {kode} references PO {kode}, which is closed; only an approved purchase order can
  receive goods. Record the delivery against the vendor without the purchase order so it
  can be billed on the receipt."`
- > "GRN {kode} menyebut PO {kode}, yang tagihannya sudah disetujui tanpa penerimaan
  > barang, sehingga pembeliannya langsung dibebankan ke 5-1100. Menerima barangnya
  > sekarang akan menaikkan persediaan dan memunculkan tagihan kedua untuk kiriman yang
  > sama, jadi biayanya terhitung dua kali. Catat pengiriman ini atas nama vendor tanpa
  > nomor PO."
- Kelebihan terima pada baris yang diketik tangan:
  > "GRN {kode} membuat total penerimaan Semen PCC 50 kg atas PO {kode} menjadi 160,000,
  > melebihi 100,000 yang dipesan. Baris ini tidak tertaut ke baris PO, sehingga batas
  > kuantitas PO tidak diperiksa lewat jalur biasa. Gunakan 'Salin baris dari PO' untuk
  > barang yang memang dipesan, atau perbaiki kuantitasnya."
- > "Baris pada GRN {kode} menunjuk baris PO #12 yang bukan milik PO {kode}. Perbaiki
  > tautannya lewat 'Salin baris dari PO' agar batas kuantitas PO ikut diperiksa."
- `"Receipt of 30 exceeds remaining quantity 20 on PO {kode} line 2."`
- Periode fiskal tertutup (§6.10).

**`Batalkan Penerimaan`** (izin `inv.post`, hanya pada GRN Diposting yang belum diretur;
*Alasan pembatalan* wajib minimal 5 karakter, *"Tercatat permanen di dokumen dan jejak
audit."*) memposting mutasi stok cermin dan **jurnal pembalik**, mengembalikan kuantitas
ke PO, dan **membuka kembali PO yang tadinya tertutup otomatis**. Notifikasi di layar
hanya `Batalkan Penerimaan berhasil.` — kalimat rinci server tentang stok, jurnal, dan
kuantitas PO dibuang layar (§2.5); yang terjadi adalah persis tiga hal di kalimat
sebelum ini.

Empat hal yang menolaknya, dan ketiga yang pertama berarti "sudah terlambat":

- > "Penerimaan {kode} sudah dikembalikan sebagian lewat retur pembelian (RTR/…);
  > membatalkan utuh di atasnya akan mengeluarkan stok dan membalik kliring melebihi yang
  > tersisa. Kembalikan sisanya lewat retur pembelian juga."
- > "Kliring penerimaan {kode} sudah disapu tagihan vendor sebesar 15.000.000,00; hutang
  > yang telah disetujui tidak boleh ditulis ulang dokumen stok. Mintakan nota kredit
  > vendor dan bukukan lewat Keuangan, dan keluarkan barangnya lewat opname bila memang
  > harus keluar."
- > "Penerimaan {kode} menyebut PO {kode} yang tagihannya sudah disetujui dengan
  > pembebanan langsung (tanpa menyapu kliring penerimaan), sehingga nilai barangnya sudah
  > menjadi beban lewat tagihan itu. Selesaikan lewat nota kredit vendor di Keuangan."
- Sebagian barangnya sudah keluar gudang:
  > "Stok Semen PCC 50 kg di Gudang Proyek A tinggal 40,000, kurang dari 100,000 yang
  > harus ditarik pembatalan utuh {kode} — sebagian sudah keluar lewat bon, transfer, atau
  > retur sejak diterima. Gunakan retur pembelian untuk mengembalikan bagian yang masih di
  > gudang, atau opname bila barangnya susut."

**Aturan waktu yang praktis: kembalikan barang SEBELUM tagihan vendornya disetujui.**
Setelah itu, layar stok berhenti menjadi jawaban dan urusannya pindah ke nota kredit di
Keuangan.

**`Buat Retur`** (izin `inv.create`, pada GRN Diposting yang menyebut PO atau vendor;
*Alasan retur* wajib minimal 5 karakter) membuat **draf** Retur Pembelian berisi semua
yang masih bisa dikembalikan, lalu membukanya. **Ia hanya draf** — §6.8.

**Mencetak:** **`Cetak Bukti Penerimaan Barang`** (Form F/BPB). Berlampiran: ya.

### 6.5 Pengeluaran Barang (bon) — `Persediaan › Pengeluaran`

Kode `ISS/…`. Kolom: Kode · Tanggal · Gudang · Proyek · Keperluan · Status. Bisa
diubah/dihapus hanya saat **Draf**.

Bantuan di formulir: *"Biaya per baris dinilai otomatis pada HPP rata-rata gudang saat
posting."*

**Mengeluarkan barang:**

1. **`Tambah Pengeluaran Barang`**.
2. Isi **Gudang asal** (wajib), **Tanggal keluar** (wajib), **Proyek tujuan**,
   **IPP (Ijin Pelaksanaan)**, **Paket pekerjaan (WBS)**, **Keperluan** (wajib,
   maksimal 500 karakter).
3. Isi **Item dikeluarkan** (minimal 1): Item · **Paket WBS** (per baris) · **Qty**.
   **Tidak ada kolom harga** — nilainya ditentukan saat posting.
4. **`Simpan`**, lalu minta **`Posting ke Stok`** (konfirmasi *"Posting pengeluaran ini?
   Stok akan berkurang dan dokumen dikunci."*).

Paket WBS pada **baris** menang; baris yang kosong memakai paket di kepala bon. Satu bon
boleh melayani dua paket pekerjaan.

> **Bon adalah satu-satunya dokumen stok yang mendarat sebagai BIAYA PROYEK.** Dua kolom
> di atas menentukan ke mana:
>
> - **`Proyek tujuan` yang dikosongkan membuat pengeluaran itu menjadi overhead kantor,
>   bukan biaya proyek.** Ia tidak akan pernah tampil di realisasi proyek, di EVM, di
>   laporan varian material, maupun di basis biaya PSAK 115. Dan karena bon yang sudah
>   diposting tidak bisa diubah, satu-satunya perbaikan adalah `Batalkan Bon` (pemegang
>   izin posting stok — §6.1) lalu mengeluarkan ulang.
> - **`Paket pekerjaan (WBS)` yang dikosongkan membuat pemakaiannya tidak masuk laporan
>   Varian Material** (§7.9) — kolom "aktual" di sana menjadi lebih kecil daripada
>   pemakaian yang sebenarnya.

**Mengubah `Proyek tujuan` pada draf bon diam-diam tidak berpengaruh.** Kolomnya ada di
formulir Ubah dan menerima nilai baru, penyimpanan berhasil, dan **server membuang
nilainya**. Tidak ada pesan apa pun. Draf bon yang salah proyek harus **dihapus dan
diketik ulang**.

Penolakan pemilih WBS: *"Tugas WBS yang dipilih bukan bagian dari WBS proyek ini."* ·
*"Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan paling bawah."* ·
*"Tugas WBS yang dipilih tidak terhubung ke item BOQ, sehingga pemakaian material tidak
dapat dibandingkan dengan analisa harga satuan."* Pemilihnya memuat tugas daun **seluruh
proyek**, dibedakan oleh sub-label seperti `PRJ-2026-001 · B.3` — perhatikan.

**IPP: dari mana paket pekerjaan bon bisa datang sendiri.** Bila proyeknya memakai modul
Engineering (bab 16), bon boleh menunjuk sebuah **IPP** — dan bila IPP itu membawa paket
pekerjaan, bon **mewarisi** paket itu tanpa Anda mengetiknya. Aturannya:

- **Hanya IPP yang sudah disetujui yang berlaku.** IPP draf, diajukan, atau ditolak tidak
  bisa jadi dasar bon: *"IPP {kode} masih berstatus {status}; hanya IPP yang disetujui
  yang dapat menjadi dasar pengeluaran material."*
- **IPP harus milik proyek yang sama:** *"IPP {kode} milik proyek lain dan tidak dapat
  menjadi dasar bon proyek ini."*
- **Kalau Anda mengisi paket WBS sendiri sekaligus menunjuk IPP, keduanya harus cocok.**
  Bila bentrok: *"Bon menunjuk {kode} yang paket pekerjaannya WBS {WBS}, tetapi tugas WBS
  bon diisi {WBS lain}. Kosongkan tugas WBS agar diwarisi dari IPP, atau lepaskan IPP-nya
  bila bon ini untuk pekerjaan lain."* — kosongkan paket WBS-nya dan biarkan diwarisi.

**Peringatan konfirmasi (bukan blokir).** Bila proyeknya **punya IPP aktif** tetapi bon
ini tidak menunjuk satu pun, server menahan sekali dengan: *"Proyek ini memiliki IPP
aktif: {kode,…}. Pilih IPP yang mendasari pengeluaran ini agar bon mewarisi paket
pekerjaannya, atau ajukan ulang dengan konfirmasi bila bon ini memang di luar cakupan
IPP."* Pilih IPP-nya, **atau** kirim ulang untuk menegaskan bon ini memang di luar IPP
(bahan habis pakai, kebersihan) — material di luar izin itu nyata dan tetap boleh keluar.
Peringatan hanya muncul saat bon dibuat dan saat penyuntingan menyentuh kolom IPP.

Penolakan posting: `"Issue {kode} is {status}; only draft issues can be posted."` ·
`"Issue {kode} has no lines to post."` ·
*"Stok tidak mencukupi: {item} di {gudang} (tersedia {n}, diminta {m})."* · periode
fiskal · larangan mundur tanggal (§6.10).

**`Batalkan Bon`** (izin `inv.post`; *Alasan pembatalan* wajib) mengembalikan stok dan
membalik jurnalnya. Notifikasi di layar hanya `Batalkan Bon berhasil.` — kalimat rinci
server tidak pernah tampil (§2.5). Ditolak pada:

- > "Bon {kode} dibuat otomatis dari pengesahan laporan lapangan dan tidak dapat
  > dibatalkan sendiri — koreksi laporan lapangannya, karena pengesahan dan pengeluaran
  > suku cadang adalah satu peristiwa yang sama."
- > "Bon {kode} sudah dikembalikan sebagian lewat retur material ({kode}); membatalkan
  > utuh di atasnya akan mengembalikan stok melebihi yang pernah keluar. Kembalikan
  > sisanya lewat retur material juga."

**Mencetak:** **`Cetak Bon Pengeluaran Barang`** (Form F/BM). **Bon tidak menerima
lampiran** — tidak ada tempat menempelkan foto surat jalannya.

### 6.6 Transfer Antar Gudang — `Persediaan › Transfer`

Kode `TRF/…`. Statusnya berbeda dari dokumen stok lain: **Draf · Dalam Perjalanan ·
Diterima**. Saringan hanya Status. Bisa diubah/dihapus hanya saat **Draf**.

1. **`Tambah Transfer`**. Isi **Gudang asal** (wajib), **Gudang tujuan** (wajib, harus
   berbeda), **Tanggal transfer** (wajib), Catatan.
2. Isi baris: Item · Qty. **Tidak ada kolom harga.**
3. **`Simpan`**, lalu **`Kirim`** (konfirmasi *"Kirim transfer ini? Stok keluar dari
   gudang asal pada HPP saat ini."*).
4. Di gudang tujuan, **`Terima`** (konfirmasi *"Terima transfer ini di gudang tujuan?"*).

> **Transfer tidak bisa dihentikan setelah dikirim.** `Kirim` searah: transfer yang dalam
> perjalanan tidak bisa diubah, dihapus, maupun dibatalkan — tombol yang tersisa hanyalah
> `Terima`. **Periksa gudang tujuan sebelum menekan `Kirim`.** Satu-satunya cara menarik
> barangnya kembali adalah mengirim transfer kedua ke arah sebaliknya.

Transfer **tidak membuat jurnal sama sekali** — itu memang desainnya. HPP asal dibekukan
pada baris saat `Kirim`, dan gudang tujuan menerimanya persis pada biaya itu, sehingga
transfer tidak menciptakan maupun menghancurkan nilai.

**`Terima` adalah satu-satunya aksi stok yang tidak menolak tanggal mundur** — bila
tanggal transfernya sudah tidak bisa dipakai, ia jatuh maju ke **hari ini**, supaya barang
tidak pernah tertahan di jalan. Tanggal terimanya disimpan pada dokumen.

Penolakan: `"Transfer {kode} is {status}; only draft transfers can be sent."` ·
`"…only in-transit transfers can be received."` · `"Transfer {kode} has no lines to
send."` · `"Transfer {kode} is {status} and can no longer be modified."` · stok asal tidak
cukup.

**Mencetak:** **`Cetak Surat Jalan Antar Gudang`** (Form F/SJ). Tidak menerima lampiran.

### 6.7 Penyesuaian Stok / Opname — `Persediaan › Opname`

Judul layarnya **Penyesuaian Stok (Opname)**, kode `ADJ/…`. Ini satu-satunya dokumen stok
yang memakai **siklus persetujuan**, bukan siklus posting. Kolom: Kode · Tanggal ·
Gudang · Alasan · Diposting · Status. Bisa diubah/dihapus saat Draf atau Ditolak.

1. **`Tambah Penyesuaian Stok`**. Bantuan di layar: *"Sistem menyimpan qty sistem saat
   ini sebagai pembanding hasil hitung fisik."*
2. Isi **Gudang** (wajib), **Tanggal opname** (wajib), **Alasan** (wajib: Stock Opname /
   Barang Rusak / Barang Hilang), Catatan.
3. Isi **Hasil hitung fisik**: Item · **Qty terhitung**. **Anda hanya memasukkan hasil
   hitungan Anda — tidak pernah selisihnya.**
4. **`Simpan`**, **`Ajukan`**, lalu direktur/admin menekan **`Setujui`**.

Halaman opname memperlihatkan **Hasil opname**: Item · Qty sistem · Qty fisik ·
**Selisih** (bertanda) · HPP satuan.

> **`Setujui` MEMPOSTINGNYA seketika.** Tidak ada langkah Posting terpisah dan tidak ada
> konfirmasi kedua: begitu penyetuju menekan Setujui, stok bergerak dan selisihnya
> dibukukan sebagai **beban**. Bila posting gagal, persetujuannya dibatalkan dan lembar
> itu kembali berstatus Diajukan.

> **Opname memposting selisih yang DIREKAMNYA, bukan selisih hari ini.** "Qty sistem" dan
> "Selisih" dibekukan saat lembar hitung terakhir **disimpan**. Bila ada bon keluar antara
> menyimpan dan menyetujui, persetujuan menerapkan **selisih lama** pada **saldo baru**,
> dan hasilnya bukan kuantitas yang Anda hitung. **Selesaikan persetujuannya hari itu
> juga**, atau buka dan simpan ulang lembarnya lebih dulu.

Penilaiannya: kekurangan keluar pada rata-rata gudang; kelebihan masuk pada rata-rata
gudang (turun ke rata-rata global item, lalu ke harga beli terakhir). Selisih bersihnya
dibukukan ke **akun selisih persediaan — sebuah BEBAN OPERASIONAL**, yaitu penyusutan
barang. Itulah sebabnya opname adalah dokumen yang **salah** untuk material yang kembali
dari lapangan, dan **salah** untuk stok awal.

**Tidak ada pembatalan dan tidak ada pembalikan pada opname.** Satu-satunya koreksi adalah
opname berikutnya.

Penolakan: `"Adjustment {kode} is {status}; only approved adjustments can be posted."` ·
`"Adjustment {kode} has already been posted."` · `"Adjustment {kode} is {status} and can
no longer be modified."` · stok tidak cukup untuk selisih negatif · periode fiskal ·
larangan mundur tanggal.

**Mencetak:** **`Cetak Berita Acara Stock Opname`** (Form F/BAO). Berlampiran: ya.

### 6.8 Dua jenis retur — keduanya tanpa entri di sidebar

**Retur Pembelian** (`RPB/…`) dan **Retur Material Proyek** (`RTM/…`) **tidak ada di
sidebar**. Keduanya hanya bisa dicapai lewat tombol **`Buat Retur`** pada GRN atau bon
yang sudah diposting — tombol itu langsung membuka draf yang baru dibuat. Bila Anda
menutup peramban di tengah jalan, kembalilah lewat dokumen sumbernya.

**`Buat Retur` hanya membuat DRAF.** Tidak ada yang bergerak dan tidak ada yang dibalik
sampai pemegang `inv.post` membuka draf itu dan menekan **`Posting Retur`**. Draf retur
yang terlantar tampak seperti retur selesai di kertas rak, sementara ia tidak terlihat
oleh rekening vendor maupun oleh biaya proyek.

**a. Retur Pembelian.** Bantuan di formulir: *"Saat diposting: stok keluar, irisan
kewajiban vendor yang dicatat GRN dibalik (tagihan vendor tidak bisa lagi menagih bagian
yang diretur), dan kolom 'diterima' PO berkurang. Bagian yang sudah disapu tagihan vendor
tidak bisa diretur — selesaikan lewat nota kredit di Keuangan."*

Kolom: **Penerimaan (GRN) asal** (wajib) · **Tanggal retur** (wajib) · **Alasan retur**
(wajib, minimal 5 karakter). Baris diisi lewat **`Salin baris dari GRN`** — bantuan:
*"baris retur harus menunjuk baris penerimaan asalnya, karena baris itulah yang membawa
harga terima barangnya."* Gudang dan vendor **disalin dari GRN**, tidak dipilih.

Konfirmasi posting: *"Posting retur ini? Stok keluar, sisa tagihan vendor berkurang
sebesar irisan retur, kolom 'diterima' PO berkurang, dan dokumen tidak bisa diubah lagi."*

Penolakan: `"Penerimaan {kode} berstatus {status}; retur pembelian hanya dapat
dibuat/diposting atas penerimaan yang sudah diposting."` ·
`"Penerimaan {kode} tidak menyebut PO maupun vendor (stok awal); tidak ada pihak yang bisa
menerima retur. Keluarkan lewat opname bila barangnya memang harus keluar."` ·
`"Baris penerimaan yang sama tidak boleh muncul dua kali dalam satu retur; gabungkan
kuantitasnya."` · kelebihan retur · dan:
> "Retur {kode} senilai {Rp} melebihi sisa penerimaan {kode} yang belum ditagih ({Rp}).
> Bagian yang sudah disapu tagihan vendor adalah hutang yang telah disetujui — mintakan
> nota kredit vendor dan bukukan lewat Keuangan, bukan lewat dokumen stok."

**GRN tanpa PO dan tanpa vendor (stok awal) tidak menawarkan tombol `Buat Retur` dan
tidak bisa diretur lewat jalur mana pun** — tidak ada pihak lawannya. Bila stok itu harus
keluar, ia keluar lewat opname, yang membukukan nilainya sebagai kerugian.

**Mencetak:** **`Cetak Bukti Retur Pembelian`** (Form F/RPB).

**b. Retur Material Proyek.** Bantuan di formulir: *"Barang kembali pada HARGA KELUARNYA
(harga beku baris bon), bukan rata-rata hari ini, dan biaya proyek berkurang sebesar
irisan yang sama saat diposting."*

Kolom: **Bon pengeluaran asal** (wajib) · **Tanggal kembali** (wajib) · **Alasan retur**
(wajib, minimal 5). Baris diisi lewat **`Salin baris dari bon`**. **Gudang disalin dari
bon** — barang kembali ke gudang tempat ia keluar.

Konfirmasi posting: *"Posting retur ini? Stok kembali pada harga keluarnya, jurnal Dr
Persediaan / Cr HPP diposting, biaya proyek berkurang, dan dokumen tidak bisa diubah
lagi."*

Penolakan: `"Bon {kode} berstatus {status}; retur material hanya dapat dibuat/diposting
atas bon yang sudah diposting."` · bon yang lahir dari berita acara servis (§12.5) ·
baris ganda · kelebihan retur · *"Alasan retur terlalu singkat; jelaskan mengapa material
ini kembali."*

**Mencetak:** **`Cetak Bukti Retur Material`** (Form F/RTM).

### 6.9 Dokumen mana untuk situasi mana

| Situasi | Dokumen yang benar | Yang salah, dan akibatnya |
|---|---|---|
| Seluruh GRN salah posting | **Batalkan Penerimaan** | opname → nilainya menjadi beban susut, bukan koreksi |
| Sebagian barang ditolak/berlebih, kembali ke vendor | **Retur Pembelian** | opname → hutang vendor tidak ikut berkurang |
| Seluruh bon salah proyek / salah gudang | **Batalkan Bon** | jurnal manual → biaya proyek tetap salah selamanya |
| Sisa material kembali dari lapangan | **Retur Material Proyek** | GRN tanpa vendor (kredit ekuitas) atau opname (kredit beban) — biaya proyek tidak pernah berkurang |
| Barang susut / rusak / hilang | **Opname** | — |
| Stok awal saat sistem mulai | **GRN tanpa PO dan tanpa vendor** | — (dan GRN itu tidak akan pernah bisa diretur) |

**Hanya GRN dan bon yang bisa dibatalkan.** Transfer, opname, retur pembelian, dan retur
material **tidak punya pembatalan sama sekali** — tidak ada tombolnya di mana pun.

### 6.10 HPP rata-rata bergerak, dan mengapa tanggal mundur ditolak

**Barang masuk** menghitung ulang rata-rata pasangan (gudang, item):

> rata-rata baru = (qty lama × rata-rata lama + qty masuk × harga masuk) ÷ (qty lama +
> qty masuk)

**Barang keluar tidak pernah mengubah rata-rata** — hanya kuantitasnya berkurang; barang
keluar pada rata-rata yang berlaku saat itu.

**Rata-ratanya PER GUDANG.** Item yang sama boleh punya HPP berbeda di dua gudang, dan
itu bukan kesalahan.

Transfer membekukan rata-rata gudang asal ke baris saat `Kirim`; retur material kembali
pada **harga beku baris bon**; retur pembelian keluar pada **harga beku baris GRN**.

> **Penghargaan pokok berjalan MAJU, dan karena itu tanggal mundur ditolak.** Dokumen yang
> bertanggal lebih awal daripada mutasi terakhir yang sudah tercatat untuk pasangan
> (gudang, item) itu ditolak dengan:
>
> "Dokumen {kode} bertanggal {tanggal}, lebih awal dari mutasi terakhir {tanggal} untuk
> {item} di {gudang}. Harga rata-rata dihitung maju menurut urutan pencatatan, jadi
> mundurnya tanggal akan menilai barang ini pada rata-rata hari ini dan membiarkan
> pengeluaran yang sudah terlanjur diposting memakai harga lama. Ubah tanggalnya menjadi
> {tanggal} atau sesudahnya, atau catat selisihnya lewat opname."
>
> Ia berlaku pada GRN, bon, pengiriman transfer, kedua retur, opname, dan kedua
> pembatalan. **`Terima` transfer adalah satu-satunya perkecualian** — ia jatuh maju
> alih-alih menolak.

**Periode fiskal yang sudah ditutup memblokir SETIAP mutasi stok**, termasuk transfer dan
opname bernilai nol yang tidak membuat jurnal apa pun:

> "Periode fiskal 2026-03 sudah ditutup; jurnal tidak dapat diposting ke dalamnya."

Penjaga gudang yang memundurkan tanggal penerimaan ke bulan lalu akan menemuinya, dan ia
sama sekali bukan tentang stok. Tutup buku bulanan ada di
`docs/PANDUAN-ADMINISTRATOR.md` §6.

**Tidak ada riwayat saldo stok.** Angka hari ini ditulis ulang oleh setiap mutasi,
sehingga tidak ada layar dan tidak ada formulir cetak yang bisa memperlihatkan berapa
nilai stok pada akhir bulan lalu. Itulah sebabnya **Daftar Saldo Stok** tercetak sengaja
bertanggal hari pencetakan.

**Yang tidak ada di lajur ini:** tidak ada pelacakan batch, nomor seri, kedaluwarsa, bin,
atau lokasi rak — satu item di satu gudang adalah satu kuantitas dan satu harga pokok.
Tidak ada reservasi atau alokasi stok untuk sebuah proyek. Tidak ada titik pemesanan
ulang dan tidak ada PR otomatis dari baris stok rendah — "Di bawah minimum" adalah daftar
yang dibaca, tanpa tombol di atasnya.

---

## 7. Pelaksanaan proyek di lapangan

### 7.1 Siapa boleh apa di modul Proyek

| Peran | Lihat | Buat/Ubah | Setujui | Hapus |
|---|---|---|---|---|
| admin | ✓ | ✓ | ✓ | ✓ |
| direktur | ✓ | — | ✓ | — |
| project-manager | ✓ | ✓ | ✓ | — |
| site-manager | ✓ | ✓ | — | — |
| estimator, warehouse, sales | ✓ | — | — | — |
| finance, hr, teknisi | tidak melihat modul Proyek sama sekali | | | |

Tiga akibatnya yang harus Anda ketahui sejak awal:

- **Tombol Hapus praktis tidak pernah ada.** Izin hapus tidak dipegang manajer proyek
  maupun site manager, jadi ikon tong sampah tidak muncul pada laporan harian, milestone,
  temuan, penugasan personel, draf BAST, maupun draf baseline. **Salah entri diperbaiki
  lewat `Ubah`, bukan hapus-buat-ulang.** Bila sebuah baris memang harus lenyap, minta
  administrator.
- **Site manager tidak bisa menyetujui apa pun.** Ia bisa mencatat temuan dan menekan
  `Selesai diperbaiki`, tetapi tidak bisa Verifikasi, Dispensasi, Buka kembali, menutup
  insiden K3, menyetujui BAST atau baseline, atau menutup proyek. Itu disengaja:
  menyatakan perbaikan selesai adalah pekerjaan lapangan; **menerimanya** adalah tindakan
  pelanggan.
- **Direktur tidak bisa membuat atau mengubah apa pun di modul Proyek** — ia hanya
  menyetujui dan memverifikasi.

**Layar yang tidak punya entri menu**, hanya bisa dicapai dari tombol:

| Layar | Pintunya |
|---|---|
| Galeri Foto | tombol **`Galeri Foto`** di halaman proyek |
| Struktur WBS | kartu di halaman proyek — bukan layar tersendiri |
| Tutup proyek | tombol **`Tutup proyek`** di halaman proyek |
| Baseline proyek | layar **EVM & Baseline** atau kartu Baseline di halaman proyek |

### 7.2 Halaman proyek — ruang kerja Anda

`Proyek › Daftar Proyek` → klik proyeknya.

**Daftar Proyek** berkolom Kode · Nama proyek (dengan kota) · Jenis · Nilai kontrak ·
Progres · Status; saringan Status, Jenis, Pelanggan, dan **Proyek saya** (Ya/Tidak).

*"Proyek saya" mencocokkan akun Anda dengan kolom manajer proyek lewat data karyawan.*
Memilih **Ya** pada akun yang tidak tertaut karyawan mengembalikan **nol baris** — itu
jujur, bukan rusak. Memilih **Tidak** benar-benar mengecualikan proyek Anda, bukan
menampilkan semuanya.

**Tombol di kepala halaman proyek:**

| Tombol | Syarat | Yang dilakukan |
|---|---|---|
| ikon printer | — | mencetak layar apa adanya |
| **`Galeri Foto`** | — | membuka galeri foto proyek |
| **`Cetak Data Proyek`** | — | Form F/DP di tab baru |
| **`Ubah`** | izin ubah | formulir proyek |
| **`Buat WBS dari BOQ`** | izin ubah + status **bukan** Ditutup | lihat peringatan di bawah |
| **`Tutup proyek`** (merah) | izin setujui + status bukan Ditutup | dialog berdaftar-periksa (§7.12) |

**Enam petak angka**, dan tiga di antaranya berarti sesuatu yang lain daripada dugaan
Anda:

| Petak | Yang benar-benar dihitung |
|---|---|
| Nilai kontrak | nilai **sekarang**, sudah tergerak setiap CCO disetujui |
| **Progres aktual** (+ "…% vs rencana") | aktual dari **bobot × progres tugas daun WBS**; angka *rencana* lihat peringatan di bawah |
| **Tenaga kerja hari ini** | jumlah **baris Penugasan Personel** yang aktif hari ini — **bukan** jumlah orang di laporan harian |
| Milestone terlambat | milestone lewat target dan belum tercapai |
| PO terbuka | PO proyek ini berstatus draf/diajukan/disetujui |
| Retensi ditahan | retensi (%) × nilai kontrak |

> **Angka "vs rencana" bisa menyesatkan.** *Aktual* dihitung dari progres WBS, sedangkan
> *rencana* hanya ditulis ulang ketika baris minggu **bernomor tertinggi** disimpan.
> Menyimpan minggu 3 setelah minggu 8 sudah ada tidak mengubah apa pun. Bila angka "vs
> rencana" terlihat aneh: buka **Progres Mingguan** dan **simpan ulang minggu bernomor
> tertinggi**. Kurva-S di kartu di bawahnya membaca baris minggu langsung dan tetap benar.

**Kartu-kartu di halaman proyek:**

- **Kurva-S (progres kumulatif)** — tombol **`Catat minggu`** di kepala kartu. Grafiknya
  menggambar tiga garis: *Rencana (laporan mingguan)*, *Aktual*, dan — bila ada baseline
  disetujui — *Rencana baseline (kurva beku)* sebagai garis putus-putus.

  **Tombol `Catat minggu` tidak mengisi proyeknya.** Dialog Progres Mingguan terbuka
  kosong walau Anda sedang berada di halaman satu proyek; kolom Proyek wajib dan harus
  Anda pilih sendiri. Salah pilih menaruh kurva-S proyek ini ke proyek lain, dan baris itu
  **tidak bisa diubah maupun dihapus dari layar mana pun**.
- **Kinerja biaya & jadwal (EVM)** — §7.10.
- **Baseline proyek** — tombol **`Bekukan baseline`**, §7.10.
- **Izin lapangan (IKL / ILB / IMK)** — papan penunjuk ke ketiga register izin, §7.13.
  Tombol cetaknya sendiri ada di halaman masing-masing izin, bukan di kartu ini.
- **Struktur WBS** — §7.2 di bawah.
- **Milestone** (20 terakhir) · **Personel di proyek** · **BAST** · **Punch list**
  (hanya temuan **terbuka**) · **Laporan harian terakhir** (5 terakhir) · **Lampiran**.

**Kartu Struktur WBS.** Pohon Kode / Uraian / Bobot / Progres. Lencana di kepala kartu:
**"Bobot daun 100,00%"** — hijau bila tepat 100, kuning bila tidak.

- Hanya baris **daun** punya tombol **`Perbarui`**. Baris induk menampilkan teks
  *"agregat dari sub-tugas"* dan progresnya adalah rata-rata berbobot anaknya. Mengisi
  100% pada satu daun berbobot 3% hanya menggerakkan progres proyek 3%.
- Dialog **`Perbarui`**: **Progres (%)** (wajib), Aktual mulai, Aktual selesai; tombol
  **`Simpan`**. Notifikasi: *"Progres diperbarui; progres induk dihitung ulang."*
- Aturan server: progres dijepit 0–100; *Aktual mulai* terisi hari ini bila progres > 0
  dan belum pernah diisi; *Aktual selesai* terisi hari ini bila progres mencapai 100.
- Bila WBS belum ada: *"WBS belum dibuat. Gunakan tombol 'Buat WBS dari BOQ'."*

> **`Buat WBS dari BOQ` MENGHAPUS seluruh WBS lalu membangunnya ulang — tanpa dialog
> konfirmasi.** Seluruh progres per paket yang sudah dientri hilang, dan
> `Progres aktual` proyek **direset ke 0**. Proyek yang sudah 100% kembali ke nol. Satu-
> satunya suara yang dikeluarkannya adalah notifikasi *"WBS dibuat ulang dari BOQ."*
>
> Tombolnya duduk di antara `Ubah` dan `Tutup proyek` di kepala halaman. Baseline yang
> sudah disetujui **tidak** ikut berubah — justru itulah yang membuat laporan EVM tetap
> terbaca, tetapi kartu "Pergeseran lingkup" akan menyebut paket yang hilang dan yang
> baru.

Cara kerjanya: ia mencari BOQ proyek (kolom BOQ pada proyek, atau BOQ terakhir yang
menunjuk proyek ini), lalu bagian BOQ menjadi tugas induk, item BOQ menjadi tugas daun,
bobot = nilai item ÷ total BOQ × 100 (daun terakhir menyerap sisa pembulatan).

Penolakan: `"No BOQ found for project {kode}; link a BOQ before generating the WBS."` ·
`"BOQ {kode} has no priced items; cannot derive WBS weights."`

> **Bobot daun harus 100,00% hijau sebelum baseline bisa dibekukan** (§7.10). Bila
> kuning, **satu-satunya cara memperbaikinya dari layar adalah `Buat WBS dari BOQ`** —
> yang menghapus semua progres. **Tidak ada layar untuk menambah, mengubah, atau menghapus
> satu tugas WBS**, dan tidak ada cara mengubah bobot, nama, kode, urutan, atau tanggal
> rencana satu paket pekerjaan. Perbaiki BOQ-nya lalu bangun ulang WBS-nya (dengan
> konsekuensi progres hilang), atau minta administrator.

**Formulir proyek** (tombol `Ubah`), tiga bagian:

- *Proyek*: Dari kontrak (*"Mengisi kontrak akan menyalin nilai, tanggal, retensi &
  garansi."*) · Pelanggan · **Nama proyek** (wajib kecuali "Dari kontrak" diisi) ·
  **Jenis proyek** (idem) · BOQ · Nilai kontrak · Retensi (%) (bawaan 5) · Masa
  pemeliharaan (bulan) (bawaan 12) · **Status** (hanya saat Ubah; bantuan: *"Menutup
  proyek lewat tombol 'Tutup proyek' di halaman proyek — bukan dari sini."* — dan pilihan
  **"Ditutup" memang tidak ada di daftarnya**).
- *Lokasi & jadwal*: Lokasi · Kota · Provinsi · **Lintang** · **Bujur** · Rencana mulai ·
  Rencana selesai · Aktual mulai · Aktual selesai.
- *Tim*: Project manager · Site manager · **Konsultan MK / pengawas** · **Sebutan
  konsultan** (*"Judul kotak pada kop — mis. Konsultan MK, Konsultan Pengawas. Kosong =
  'KONSULTAN MK'."*).

> **Empat kolom terakhir menentukan isi setiap kertas yang ditandatangani.**
> `Konsultan MK / pengawas` mengisi kotak kedua pita empat pihak dan kolom tanda tangan
> kedua **pada ketujuh formulir rumah proyek**. **Kosong berarti kotak kosong di kertas.**
> Isilah SEBELUM lembar pertama diarsipkan — kotak yang kosong saat dicetak tetap
> kosong di berkas yang ditandatangani.
>
> `Site manager` (atau, bila kosong, `Project manager`) mengisi **nama dan jabatan** pada
> kolom tanda tangan ketiga. Kolom PEMILIK dan KONSULTAN sengaja tanpa nama — sistem tidak
> menyimpan siapa yang menandatangani di sana.
>
> `Lintang`/`Bujur` dipakai lencana jarak foto (§7.4). Kosong → lencananya berbunyi
> *"Ber-GPS, lokasi proyek belum diisi"*.

Penolakan: mengubah Status menjadi Ditutup lewat formulir ini ditolak —
> "Proyek {kode} tidak dapat ditutup lewat ubah status biasa; gunakan aksi Tutup proyek,
> yang memeriksa item terbuka (defect, PO, termin, retensi) lebih dulu."

Menghapus proyek yang berjalan: `"Project {kode} is active; put it on hold or close it
before deleting."`

### 7.3 Laporan Harian — `Proyek › Laporan Harian`

Kolom: Kode (`DRP/2026/03/0001`) · Tanggal · Proyek · Tenaga kerja · Cuaca pagi · Cuaca
siang · Kegiatan. Saringan: Proyek, Dari, Sampai.

1. **`Tambah Laporan Harian`**.
2. Isi **Proyek** (wajib, **tidak bisa dipindah setelah tersimpan**), **Tanggal laporan**
   (wajib, bawaan hari ini), Cuaca pagi, Cuaca siang, **Jam mulai kerja**, **Jam selesai
   kerja**, **Alasan jam kerja hilang** (bantuan: *"Hujan, tunggu material, listrik
   padam — alasan jam efektif lebih pendek dari jam kerja."*), **Jumlah tenaga kerja**
   (lihat aturan turunannya di bawah), **Kegiatan hari ini** (wajib), Kendala, **Catatan
   K3** (bantuan: *"Catatan pengamatan harian. Kejadian kecelakaan atau near miss
   dicatat di Register K3 (SMK3), bukan di sini."*).
3. Isi lima tabel baris di bawah formulir. **Menyimpan dengan sebuah tabel terisi
   mengganti seluruh baris tabel itu**; tabel yang tidak disentuh tidak berubah, dan
   mengosongkan tabel menghapus seluruh barisnya.

   - **Tenaga kerja per jabatan** — tabel JUMLAH ORANG pada FM-10-12: **Jabatan**
     (wajib; dua belas pilihan: Project Manager · Deputy Project Manager · Engineering ·
     Komersial · Keuangan · Danlat · Produksi · Safety Officer · Mandor Sipil + Tukang ·
     Mandor Arsitek + Tukang · Mandor MEP + Tukang · Subkont; jabatan yang sama dua kali
     ditolak: *"Jabatan yang sama tercantum dua kali pada rincian tenaga kerja."*) ·
     **Jumlah orang** (wajib) · Keterangan.
   - **Uraian pekerjaan** — kolom URAIAN PEKERJAAN / PROGRESS / TARGET / HAMBATAN,
     satu baris per pekerjaan: Paket WBS · **Uraian pekerjaan** (wajib) · Progress ·
     Target · Hambatan.
   - **Material masuk** — kedatangan di lapangan hari ini, **BUKAN pemakaian**:
     **Material** (wajib) · **Diterima** (wajib) · Ditolak · **Satuan** (wajib) ·
     Alasan ditolak. Tombol **`Impor dari GRN`** mengisinya dari penerimaan gudang —
     lihat di bawah.
   - **Pemakaian material** — material yang **DIPAKAI** hari ini, tabel yang sudah ada
     sejak dulu: Item (wajib) · Qty dipakai (wajib) · Satuan (wajib).
   - **Alat-alat** — tabel ALAT-ALAT pada FM-10-12: Aset (untuk alat milik perusahaan;
     alat sewa cukup uraiannya) · **Uraian alat** (wajib) · **Jumlah** (wajib) · Jam
     operasi.
4. **`Simpan`**.

**Jumlah tenaga kerja menjadi TURUNAN begitu rincian per jabatan diisi.** Totalnya
dihitung dari jumlah orang seluruh baris, dan kotak manualnya tidak digambar lagi pada
laporan yang sudah punya rincian (bantuan kotaknya: *"Terhitung otomatis begitu tabel
"Tenaga kerja per jabatan" diisi — kosongkan saja. Isi manual hanya untuk laporan tanpa
rincian jabatan."*). Angka manual yang tetap terkirim dan berbeda ditolak dengan pesan
yang menyebut kedua angkanya:

> "Jumlah tenaga kerja manual ({manual}) berbeda dengan total rincian per jabatan
> ({turunan}); selisih {selisih}. Kosongkan angka manual atau samakan dengan
> rinciannya — rincian per jabatan adalah sumbernya."

Laporan lama tanpa rincian tidak dipaksa mundur: angka manualnya tetap berlaku, dan
kotaknya wajib diisi hanya selama tabel rinciannya kosong.

**`Impor dari GRN`** (di kepala tabel Material masuk) membuka daftar GRN **terposting
pada gudang site proyek ini dengan tanggal penerimaan = tanggal laporan yang
tersimpan** — per baris item, lengkap dengan kode GRN, no. surat jalan, dan vendornya.
Yang ditawarkan adalah penerimaan **gudang site proyek** (ke mana barangnya datang),
bukan GRN atas PO proyek yang diterima gudang lain. Centang baris yang benar-benar tiba
di lapangan lalu **`Tambahkan yang dipilih`** — baris terpilih DITAMBAHKAN ke tabel,
baris yang sudah diketik tangan tidak tersentuh, dan **tidak ada yang diimpor
otomatis**: Anda yang memilih. Baris berlencana **"Sudah diimpor"** akan menjadi baris
ganda bila dicentang lagi. Pada laporan yang belum tersimpan tombolnya menjawab:
*"Simpan laporan ini dulu, lalu buka Ubah — kandidat GRN dibaca dari proyek dan tanggal
laporan yang tersimpan."*; tanpa kandidat: *"Tidak ada GRN terposting di gudang site
proyek ini pada tanggal laporan."* Baris ketik tangan tetap sah untuk kedatangan tanpa
GRN.

**Satu laporan per proyek per tanggal.** Duplikat ditolak dengan pesan berbahasa Inggris
di bawah kolom Tanggal laporan: `The report date has already been taken.`

Penolakan lain, masing-masing di bawah kolomnya:

- Jam selesai ≤ jam mulai: *"Jam selesai ({selesai}) harus setelah jam mulai
  ({mulai})."* Pembandingnya nilai yang berlaku — menggeser jam selesai saja tetap
  diadu dengan jam mulai yang tersimpan.
- Ditolak > diterima pada Material masuk: *"Jumlah ditolak ({ditolak}) melebihi jumlah
  diterima ({diterima}) pada baris "{material}" — yang ditolak adalah bagian dari yang
  datang."*

Halaman laporan harian memuat kartu **Lampiran** (foto masuk ke sini dan ke Galeri) dan
tombol **`Cetak Laporan Harian`** (Form F/LH). Lembarnya diberi tanggal oleh **tanggal
laporan**, sehingga "HARI KE"/"SISA HARI" pada kop dihitung dari tanggal laporan, bukan
hari mencetak.

**Yang tercetak dari basis data — sel per sel, hanya bila laporannya mencatatnya:**

- dua belas baris JUMLAH ORANG per jabatan dari tabel Tenaga kerja per jabatan;
  jabatan tanpa baris tetap bergaris kosong, dan TOTAL tetap Jumlah tenaga kerja —
  kosong bila 0, karena kotak yang tidak pernah diisi dan site yang berhenti tidak
  boleh tercetak sama;
- tabel MATERIAL YANG MASUK HARI INI dari Material masuk — diterima, ditolak, dan
  alasan penolakan; DITOLAK dicetak walau 0, karena baris penerimaan yang dicatat
  adalah pernyataan "tidak ada yang ditolak", bukan kolom yang belum tersentuh;
- tabel MATERIAL YANG DIPAKAI HARI INI dari Pemakaian material — di bawah judulnya
  sendiri: pemakaian tidak pernah dicetak di bawah judul material masuk;
- tabel ALAT-ALAT dari Alat-alat, dengan jam operasi bila dicatat (bukan "0 jam");
- kolom URAIAN PEKERJAAN / PROGRESS / TARGET / HAMBATAN dari Uraian pekerjaan, urut
  barisnya; catatan yang kosong pada sebuah baris tetap bergaris kosong, dan laporan
  tanpa baris uraian mencetak teks Kegiatan dan Kendala seperti biasa;
- baris "Pekerjaan dimulai jam … s/d jam …" dan alasan jam kerja hilang, dari Jam
  mulai/selesai kerja dan Alasan jam kerja hilang;
- cuaca pagi/sore, kegiatan, kendala, catatan K3 — seperti sebelumnya.

**Sel yang laporannya tidak mencatat tetap bergaris kosong untuk diisi tangan**, dan
laporan lama tanpa satu pun baris tabel tercetak persis seperti sebelum tabel-tabelnya
ada. Catatan kaki *"Diisi manual di lapangan"* di kaki lembar kini menyebut **hanya
tabel yang masih manual pada laporan itu** — laporan yang seluruh tabelnya terisi tidak
membawa catatan kaki sama sekali. **PERPANJANGAN WAKTU I/II pada kop kini tercetak dari
addendum waktu yang DISETUJUI** pada kontrak proyeknya (CCO berjenis `waktu`, §3.7):
dua addendum pertama urut tanggal perubahan, format
`+14 hari → 14 Agu 2027 (CCO/2026/VIII/0003)`; addendum ketiga dst membuat baris II
berbunyi `lihat register` — tidak pernah dipotong diam-diam. Kontrak tanpa addendum
waktu yang disetujui tetap mencetak kedua baris BERGARIS KOSONG, persis seperti dulu.

**BAST I yang disetujui MENGUNCI laporan harian.** Saat BAST I disetujui, seluruh
laporan proyek itu yang bertanggal sampai dengan tanggal serah terima terkunci: tombol
`Ubah` dan `Hapus` tidak digambar lagi pada laporan terkunci, dan permintaan yang tetap
dikirim ditolak dengan:

> "Laporan {kode} terkunci oleh BAST I {kode BAST} (serah terima {tanggal}) dan tidak
> dapat {diubah|dihapus}: pekerjaan sebelum serah terima sudah ditandatangani tiga
> pihak."

> **Menyetujui BAST I mematikan seluruh entri lapangan.** Sejak saat itu — dan sejak
> proyek diubah ke **Ditangguhkan** — laporan harian baru, **koreksi laporan harian
> lama**, penghapusannya, progres mingguan, progres paket WBS, dan `Buat WBS dari BOQ`
> semuanya ditolak dengan:
>
> "Proyek {kode} berstatus Masa Pemeliharaan; {laporan harian|progres mingguan|progres
> paket pekerjaan|generate WBS} hanya dapat dientri pada proyek berstatus Persiapan,
> Berjalan, atau Finishing."
>
> Khusus laporan yang sudah terkunci BAST I, penolakannya memakai pesan kunci di atas —
> kuncinya diperiksa lebih dulu, supaya yang disebut adalah dokumen yang membekukan
> laporannya, bukan sekadar status proyek.
>
> **Rapikan seluruh laporan harian dan progres SEBELUM BAST I disetujui.**

### 7.4 Lapangan (mobile) dan Galeri Foto

**`Proyek › Lapangan (mobile)`** — satu kolom, tombol besar, kamera satu ketuk. Dua tab:
**Laporan Harian** dan **Tiket Servis**; tab yang izinnya tidak Anda pegang tidak
digambar. Tanpa keduanya: *"Anda tidak memiliki akses ke layar lapangan."*

**Teknisi servis: entri sidebar itu tidak ada di layar Anda.** Baris "Lapangan (mobile)"
tinggal di kelompok Proyek, dan seluruh kelompok itu hanya digambar untuk pemegang izin
lihat proyek — yang tidak dipegang peran `teknisi` bawaan. Layarnya sendiri **tetap
terbuka untuk Anda**: ketik alamatnya langsung — `#/lapangan` di belakang alamat
aplikasi — lalu simpan sebagai markah/ikon layar utama di ponsel. Anda hanya akan
melihat tab **Tiket Servis**. Bila entri sidebarnya memang dibutuhkan, minta izin lihat
proyek (`prj.view`) kepada administrator.

**Tab Laporan Harian:**

1. Pilih **Proyek** dan **Tanggal**.
2. Bila laporan sudah ada, muncul kartu kode laporan berlencana hijau **"Sudah ada"**.
3. Bila belum, muncul kartu **"Belum ada laporan untuk tanggal ini"** dengan **Tenaga
   kerja per jabatan** — dua belas baris stepper `−`/`+`, satu per jabatan FM-10-12,
   dengan total yang menghitung sendiri (*"Total dihitung otomatis dari jabatan yang
   diisi."*) — **Kegiatan**, dan tombol **`Buat laporan hari ini`**. Layar ini mencatat
   tenaga kerja **hanya** lewat stepper per jabatan — server yang menurunkan totalnya
   dari rincian (§7.3); tanpa satu pun jabatan terisi, laporan dibuat dengan total 0
   (site berhenti — hari hujan pun tercatat).

> **Formulir cepat itu hanya punya dua isian.** Cuaca, jam kerja, kendala, catatan K3,
> dan keempat tabel baris lainnya tidak ada di sana. Rincian per jabatan yang diisi dari
> ponsel ikut tercetak pada baris JUMLAH ORANG Form F/LH; kolom yang tidak ada di layar
> ini tercetak kosong. **Lengkapi lewat `Proyek › Laporan Harian` → `Ubah` sebelum
> lembarnya dicetak dan ditandatangani.**

> **Dropdown proyek di layar Lapangan menawarkan SEMUA proyek — termasuk yang ditutup dan
> ditangguhkan.** Tidak ada penyaringan status di sana; penolakan baru muncul setelah
> tombol `Buat laporan hari ini` ditekan. Di ponsel, di lokasi, itu berarti mengetik ulang.

**Kartu Foto lapangan** — tombol **`Ambil foto`** (membuka kamera belakang di ponsel).

- Batas **5 MB**, diperiksa di peramban: *"Foto {x} MB melebihi batas 5 MB."*
- Posisi GPS diminta sekali per jepretan, jeda 12 detik. Izin ditolak atau tanpa sinyal
  tetap mengirim fotonya: *"Foto terkirim dengan lokasi."* atau *"Foto terkirim (tanpa
  lokasi)."*
- Lencana jarak per foto: hijau ≤250 m, kuning ≤1 km, merah >1 km. Tanpa koordinat
  proyek: *"Lokasi proyek belum diisi"*. Tanpa GPS: *"Tanpa lokasi"*.
- Sumber lokasi ikut ditulis: *"lokasi dari foto"* (dari data kamera) atau *"lokasi dari
  perangkat"*, plus perkiraan ketelitiannya.

**`Galeri Foto Progres`** (tombol di halaman proyek). Saringan: Dari / Sampai +
**`Muat ulang`**. Di atas grid, chip per jenis dokumen dengan jumlahnya. Grid 24 foto per
halaman, **dikelompokkan per tanggal** — tanggal ambil dari kamera bila ada, kalau tidak
tanggal unggah. Klik foto membuka dialog berisi dokumen asalnya, tanggal, pengunggah,
lokasi, dan tombol **`Buka dokumen`**.

Fotonya diambil dari lampiran bergambar pada: proyek · laporan harian · BAST · temuan ·
izin kerja lapangan · izin masuk/keluar material · BOQ · RAP · SPK subkon · opname
subkon · PR · PO · penerimaan barang · invoice AR · tagihan AP · jurnal · voucher kas
kecil · kasbon. **Tiap sumber disaring izin modulnya** — foto nota vendor hanya terlihat
oleh yang boleh membuka Keuangan.

Bila kosong: *"Belum ada foto pada dokumen proyek ini. Foto diunggah dari layar Lapangan
atau dari kartu Lampiran tiap dokumen."*

### 7.5 Progres Mingguan — `Proyek › Progres Mingguan`

**Layar ini tidak punya halaman detail, tidak punya tombol Ubah, dan tidak punya tombol
Hapus.** Satu-satunya koreksi adalah menyimpan ulang nomor minggu yang sama pada proyek
yang sama — bantuan di formulirnya mengatakannya: *"Menyimpan ulang minggu yang sama akan
memperbarui data (upsert)."*

Kolom: Proyek · Minggu (`M-8`) · Mulai · Selesai · Rencana (%) · Aktual (%) ·
**Deviasi** (bertanda). Saringan: Proyek. Ikon printer per baris mencetak **Detail
Schedule** (§7.13).

1. **`Tambah Progres Mingguan`**.
2. Isi **Proyek** (wajib), **Minggu ke-** (wajib, 1–520), **Periode mulai** (wajib),
   **Periode selesai** (wajib, ≥ mulai), **Rencana kumulatif (%)** (wajib, 0–100),
   **Aktual kumulatif (%)** (wajib, 0–100), Catatan.
3. **`Simpan`**.

> **Kedua persen diketik tangan.** Tidak ada satu pun angka di layar ini yang dihitung
> dari WBS, laporan harian, atau baseline. Kolom **Aktual** di sini boleh berbeda dari
> progres WBS di halaman proyek. Satu-satunya turunan adalah **Deviasi** = aktual −
> rencana.
>
> Laporan EVM sengaja **mengabaikan kolom Rencana ini** dan memakai kurva baseline yang
> beku (§7.10).

Penolakan: *"Proyek {kode} berstatus {status}; progres mingguan hanya dapat dientri pada
proyek berstatus Persiapan, Berjalan, atau Finishing."*

### 7.6 Register Defect (Punch List) — `Proyek › Register Defect (Punch List)`

Sub-judulnya: *"Daftar temuan pekerjaan beserta perbaikan dan penerimaannya. Temuan
kritis dan mayor yang masih terbuka menahan BAST II — dan retensi yang menunggu di
belakangnya."*

**Lima petak ringkasan** — Temuan terbuka · **Menahan BAST II** · Lewat target perbaikan ·
Terbuka terlama · Posisi per. Kelimanya dihitung atas **seluruh temuan proyek terpilih**,
tidak ikut saringan tabel; kalimat kecil di bawahnya mengatakan itu.

Saringan: pencarian · Proyek · **Tampilkan** (Masih terbuka / Lewat target perbaikan /
Semua temuan) · Keparahan · Status · Sumber. Memilih status "Selesai (terverifikasi)"
atau "Dispensasi pelanggan" **otomatis** memindahkan Tampilkan ke *Semua temuan* — kalau
tidak, hasilnya selalu nol.

Kolom tabel: Kode · Temuan (dengan lokasi/paket WBS, atau *"lokasi tidak dicatat"*) ·
Proyek · **Keparahan** (dengan *"menahan BAST II"*) · Penanggung jawab (atau *"belum
ditunjuk"*) · Target perbaikan (dengan lencana merah **Lewat target**) · **Umur** dalam
hari (kuning ≥30, merah ≥60) · Status. Urutannya: kritis → mayor → minor, lalu paling
lama.

**Mencatat temuan:**

1. **`Catat temuan`**.
2. Isi **Proyek** (wajib, tidak bisa dipindah setelah tersimpan), **Temuan** (wajib,
   maksimal 200 karakter), **Keparahan** (wajib: Kritis (menghentikan fungsi) / Mayor /
   Minor (snagging)), **Sumber temuan** (wajib: Serah terima (BAST I) / Masa pemeliharaan
   / QC internal), Lokasi, Penanggung jawab perbaikan, Target perbaikan, **Tanggal
   temuan** (bawaan hari ini — **umur temuan dihitung dari sini**), Uraian.
3. **`Simpan`**.

**Tombol per baris:**

| Tombol | Izin | Muncul saat | Isian |
|---|---|---|---|
| **`Selesai diperbaiki`** | ubah | status bukan Selesai/Dispensasi | Tanggal perbaikan selesai |
| **`Verifikasi`** | **setujui** | idem | Tanggal diterima |
| **`Dispensasi`** | **setujui** | idem | **Alasan dispensasi** (wajib, min. 10 karakter) + tanggal |
| **`Buka kembali`** | **setujui** | status Selesai atau Dispensasi | **Alasan** (wajib, min. 10 karakter) |
| ikon Ubah | ubah | status bukan Selesai/Dispensasi | formulir temuan |

Alurnya: Terbuka → Perbaikan berjalan → **Menunggu verifikasi** → Selesai (terverifikasi)
atau Dispensasi pelanggan.

> **"Menunggu verifikasi" masih dihitung TERBUKA dan tetap menahan BAST II.** Menekan
> `Selesai diperbaiki` tidak mengurangi satu pun angka penahan; hanya `Verifikasi` atau
> `Dispensasi` yang menutup — dan keduanya butuh izin setujui, yang **tidak dipegang site
> manager**. Punch list yang "sudah semua dikerjakan" tetap menahan serah terima sampai
> manajer proyek atau MK memverifikasinya satu per satu.

Penolakan:

- *"Temuan {kode} berstatus {status} dan tidak dapat {diubah|ditandai selesai
  diperbaiki}. Buka kembali lebih dulu bila ada koreksi."*
- *"Temuan {kode} sudah diverifikasi selesai."* · *"Temuan {kode} sudah diberi dispensasi;
  buka kembali lebih dulu bila perlu diperbaiki."* · *"Temuan {kode} masih terbuka."*
- *"Dispensasi temuan harus disertai alasan, minimal 10 karakter."* / *"Pembukaan kembali
  temuan harus disertai alasan, minimal 10 karakter."*
- *"Temuan {kode} sudah ditindaklanjuti dan tidak dapat dihapus; gunakan dispensasi bila
  pelanggan menerimanya apa adanya."*

> **Menurunkan keparahan dari Kritis/Mayor ke Minor tidak bisa dilakukan dari layar mana
> pun.** Server menerimanya hanya bila disertai alasan tertulis, tetapi **kolom alasan itu
> tidak ada di formulir temuan mana pun** — baik di Register Defect maupun di formulir
> versi halaman dokumen. Setiap percobaan berakhir dengan penolakan
> *"Penurunan tingkat keparahan temuan harus disertai alasan, minimal 10 karakter."* tanpa
> tempat mengetiknya. Yang harus Anda lakukan: pakai **`Dispensasi`** (alasannya tersimpan
> pada temuan) bila pelanggan menerimanya apa adanya, atau minta administrator.

**Temuan boleh dicatat pada proyek berstatus apa pun, termasuk Ditutup** — klaim masa
pemeliharaan harus punya tempat mendarat. Temuan **kritis** di mana pun, dan temuan
**apa pun** pada proyek berstatus Masa Pemeliharaan atau Ditutup, mengirim pemberitahuan
ke pemegang izin ubah proyek.

> **Register defect yang KOSONG lolos prasyarat BAST II tanpa perlawanan.** Layarnya
> mengatakan sendiri: *"…register yang kosong lolos begitu saja — dan itu berarti belum
> ada yang memeriksa, bukan berarti pekerjaannya bersih."* Jangan membaca "tidak ada yang
> menahan serah terima" sebagai hasil pemeriksaan.

**Target perbaikan temuan tidak pernah muncul di Tenggat maupun Kalender.** Kolom **Umur**
dan lencana **Lewat target** di layar ini adalah satu-satunya alarm yang ada — dan hanya
terlihat kalau layarnya dibuka.

**Dua kolom yang hanya ada di formulir versi halaman dokumen** (`#/d/projects/defects/…`
→ `Ubah`), bukan di layar Register Defect: **SPK subkon** (*"Diisi bila perbaikannya
menjadi tanggungan subkontraktor."*). Untuk menautkan perbaikan ke subkontraktor, buka
temuannya lalu tekan Ubah di sana.

**Tidak ada kolom paket pekerjaan (WBS) di formulir temuan mana pun**, walau kolomnya
tampil di baris daftar. Lokasi hanya bisa ditulis sebagai teks bebas.

**Mencetak:** dari halaman satu temuan, tombol **`Cetak Daftar Temuan`** (Form F/DT,
mendatar). **Ia mencetak SELURUH register proyek itu, bukan temuan yang sedang Anda
buka** — itu disengaja (register dicetak per proyek), tetapi label tombolnya tidak
menjelaskannya.

### 7.7 K3 — Register dan Laporan

**`Proyek › Register K3 (SMK3)`.** Kolom: Kode (`K3/2026/VIII/001`) · Waktu kejadian ·
Proyek · Keparahan · Jenis · Hari hilang · **Tindakan telat** (merah) · Status.

Formulir, tiga bagian:

- *Kejadian*: **Proyek** (wajib, tidak bisa dipindah) · **Waktu kejadian** (tanggal
  **dan jam**, wajib, **tidak boleh di masa depan** — *"Waktu kejadian tidak boleh di masa
  depan."*) · Lokasi di site · **Keparahan** (wajib: Nyaris celaka (near miss) / P3K /
  Perawatan medis / Kehilangan hari kerja / Fatal) · **Jenis kejadian** (wajib, 12
  pilihan) · **Uraian kejadian** (wajib) · Jumlah orang terlibat · **Hari kerja hilang**
  (*"Pembilang severity rate pada laporan K3 bulanan."*) · Tindakan segera di lokasi.
- *Investigasi & tindak lanjut*: **Penyebab dasar** (*"Wajib diisi sebelum insiden dapat
  ditutup."*) · **Tindakan korektif** (bantuan sama) · **Penanggung jawab** · Target
  selesai.
- *Pelaporan*: **Wajib dilaporkan ke Disnaker/pemberi kerja** · Tanggal dilaporkan.

Aksi: **`Tutup Insiden`** (izin setujui; isian *Tanggal penutupan*) dan **`Buka Kembali`**
(izin setujui; konfirmasi *"Buka kembali insiden ini? Lakukan bila tindakan korektif
ternyata belum efektif."*). Insiden berstatus Selesai **tidak bisa diubah dan tidak bisa
dihapus**.

> **Insiden tidak bisa ditutup tanpa PENANGGUNG JAWAB, dan formulirnya tidak mengatakan
> itu.** Bantuan di layar hanya menandai *Penyebab dasar* dan *Tindakan korektif*; yang
> sebenarnya ditolak server adalah:
>
> "Insiden belum dapat ditutup — lengkapi dulu: penyebab dasar (root cause), tindakan
> korektif, penanggung jawab."
>
> Isi **ketiganya** lebih dulu.

Penolakan lain: *"Insiden ini sudah ditutup."* · *"Insiden ini belum ditutup."* ·
*"Insiden yang sudah ditutup tidak dapat diubah. Buka kembali lebih dulu bila ada
koreksi."*

**Status "Investigasi" tidak bisa dipilih.** Tidak ada kolom status di formulir dan tidak
ada aksi yang memindahkannya ke sana; satu-satunya jalan masuk adalah tombol
`Buka Kembali` pada insiden yang sudah ditutup.

**Insiden K3 tidak bisa menampung foto.** Tidak ada kartu Lampiran di halamannya, dan
fotonya tidak akan pernah muncul di Galeri Proyek. **Unggah foto kejadian ke laporan
harian hari itu** (atau ke proyek), dan tuliskan nomor insidennya pada keterangannya.

**`Proyek › Laporan K3`** — layar baca saja, tidak pernah menulis. Saringan Proyek +
Dari/Sampai (bawaan: tanggal 1 bulan berjalan sampai hari ini).

Petak baris 1: Total insiden (*termasuk near miss*) · **Tercatat (recordable)** (*dasar
frequency rate*) · Hari kerja hilang · **Fatal** (*wajib dilaporkan ke Disnaker* bila
> 0). Baris 2: **Frequency rate** · **Severity rate** · **Jam kerja orang** ·
**Hari sejak insiden terakhir**. Lalu tabel **Menurut keparahan** dan **Menurut jenis
kejadian**, dan kartu **Cara membaca**.

> **Frequency rate dan severity rate bergantung pada laporan harian yang rajin diisi.**
> Jam kerja orang = jumlah `Jumlah tenaga kerja` seluruh laporan harian × jam kerja per
> hari. Layar mengatakannya sendiri: *"Laporan harian yang belum diisi membuat rate tampak
> lebih buruk daripada seharusnya."* Nol man-hour menghasilkan **"—"**, bukan 0,00, dengan
> peringatan kuning *"Belum ada laporan harian pada periode ini…"*.

### 7.8 Milestone dan Penugasan Personel

**`Proyek › Milestone`.** Kolom: Proyek · Milestone · Target · Tercapai · Status ·
**Terlambat** (merah). Formulir: **Proyek** (wajib, tidak bisa dipindah) · **Nama
milestone** (wajib) · **Target tanggal** (wajib) · Tanggal tercapai · **ID termin
terkait** · Catatan.

> **Mengisi "Tanggal tercapai" untuk pertama kalinya mengirim pemberitahuan ke keuangan:**
> *"Termin {n} kontrak {kode} siap ditagih — Rp …"*. Hanya pada perpindahan kosong →
> terisi, hanya bila ada termin yang menempel, dan tidak pernah untuk termin yang sudah
> ditagih. **Itulah cara resmi menyerahkan penagihan ke keuangan** (§3.10).

**"ID termin terkait" adalah ANGKA MENTAH, bukan pemilih.** Anda harus tahu nomor id baris
terminnya; tidak ada dropdown. Angka salah ditolak dengan *"Termin yang dipilih bukan
termin kontrak proyek ini."*, tetapi angka yang benar hanya bisa didapat dari halaman
kontrak atau dari administrator.

**`Proyek › Penugasan Personel`.** Kolom: Proyek · Karyawan · Peran · Dari · Sampai ·
**Di lokasi hari ini**. Formulir: Proyek (wajib, tidak bisa dipindah) · Karyawan (wajib) ·
**Peran di proyek** (teks bebas, wajib) · **Ditugaskan dari** (wajib) · Sampai · Aktif.

Baris inilah yang dihitung petak **"Tenaga kerja hari ini"** di halaman proyek — **bukan**
angka dari laporan harian.

### 7.9 Varian Material — `Proyek › Varian Material`

Layar baca saja. Sub-judulnya: *"Bahan yang seharusnya terpakai menurut AHSP dan volume
BOQ, dibandingkan dengan bahan yang benar-benar keluar gudang untuk paket pekerjaan yang
sama."*

Saringan: Proyek · **dasar teori** (*Teori sampai progres paket* / *Teori volume kontrak
penuh*) · Posisi tanggal · centang **Hanya yang melewati ambang** · **`Muat ulang`** ·
ikon cetak. Tiga tab: **Per paket pekerjaan** · **Per material** · **Bon belum ditandai**.

Empat petak: **Teori bahan** · **Aktual keluar gudang** (*bon terposting yang sudah
ditandai paket*) · **Selisih** · **Bon belum ditandai** (% + rupiah).

Lencana baris: **Belum ada pemakaian** · **Dalam ambang** (hijau) · **Lewat teori**
(merah) · **Di bawah teori** (kuning) · **Satuan berbeda** · **Tidak ada di RAB** ·
**AHSP tanpa rincian bahan**.

> **Kolom "aktual" sepenuhnya bergantung pada penandaan paket pekerjaan di bon
> pengeluaran.** Bon yang belum ditandai tidak jatuh ke paket mana pun, sehingga baris
> yang sebenarnya boros terbaca "Belum ada pemakaian" atau "Di bawah teori". Petak
> **Bon belum ditandai** ada di baris paling atas dan tidak pernah disembunyikan — **kalau
> angkanya besar, seluruh kolom aktual di bawahnya tidak berarti apa-apa.**
>
> Perbaikannya **di bon**, bukan di layar ini. Tab **Bon belum ditandai** memberi tombol
> **`Buka bon`** per baris; kolom **Paket pekerjaan (WBS)** diisi di kepala bon dan bisa
> ditimpa per baris.

Peringatan yang tercetak di layar bila ada bon tak bertanda:

> "… bahan sudah keluar gudang tanpa penandaan paket pekerjaan… Selama itu belum
> dibereskan, kolom 'aktual' di bawah lebih kecil daripada pemakaian yang sebenarnya, dan
> selisih di bawah teori bukan berarti hemat. Penandaannya diisi di bon pengeluaran — ada
> di kepala bon dan bisa ditimpa per baris."

> **Selisih NILAI memuat dua sebab sekaligus.** Nilai teori memakai **harga AHSP** yang
> dianggarkan; nilai aktual memakai **harga pokok persediaan saat barang keluar**. Kenaikan
> harga karena itu terbaca sama dengan pemborosan. Kartu "Cara kerjanya" mengatakannya:
> *"Untuk menuduh orang boros, baca kolom kuantitas."* Baris yang satuannya berbeda
> sengaja menulis "—" di kolom itu.

### 7.10 EVM & Baseline — `Proyek › EVM & Baseline`

Tiga tab: **Portofolio** · **Laporan EVM** · **Baseline**. Saringan atas: pemilih
**Proyek**, **Tanggal laporan** (*"kosong = tanggal server"*), **`Muat ulang`**, ikon
cetak. Semua "per tanggal" berasal dari server — jam komputer Anda tidak pernah dipakai.

**Tab Portofolio.** Petak Proyek terukur · SPI portofolio · CPI portofolio · Σ PV ·
Σ EV · Σ AC, lalu tabel **Kinerja per proyek** (baris bisa diklik). Proyek tanpa baseline
tetap ditampilkan berlencana **Belum ada baseline** / **Belum berlaku** dengan seluruh
ukurannya kosong.

**Tab Laporan EVM.** Sembilan petak — SPI · CPI · TCPI · BAC · PV · EV · AC · SV · CV —
tiap petaknya menyebut basisnya. Lalu kartu **Ramalan biaya sampai selesai** (EAC, ETC,
VAC) dengan kalimat wajibnya: *"Buku besar TIDAK memakai angka ini. Pengakuan pendapatan
PSAK 115 memakai EAC dari RAP (atau override manajemen)…"*. Lalu **Kurva-S baseline**,
**Baseline yang dipakai**, **Cakupan biaya aktual**, **Jembatan ke PSAK 115**,
**Penyimpangan dari baseline awal**, **Pergeseran lingkup sejak baseline dibekukan**,
**Catatan atas laporan ini**, dan kartu **Cara kerjanya**.

Cara membacanya, dinyatakan layar itu sendiri:

- **PV dibaca dari kurva beku**, bukan dari kolom rencana laporan mingguan.
- **EV = progres fisik × BAC, dengan progres fisik memakai bobot beku** — menaikkan bobot
  pekerjaan yang sudah selesai setelah baseline disetujui tidak menambah EV sepeser pun.
- **AC** = penjumlahan Biaya Proyek sampai tanggal laporan.
- Angka yang tidak bisa dihitung tampil **"—"** beserta alasannya, bukan 0.

> **CPI boleh berwarna kuning selamanya, dan itu bukan kerusakan layar.** Selama ada
> kategori biaya yang dianggarkan di RAP tetapi realisasinya masih di bawah ambang, AC
> terlalu kecil dan CPI terlalu bagus. Kartu **Cakupan biaya** menyebut kategori kosongnya
> satu per satu dengan lencana *"Dianggarkan, belum tercatat"*, dan catatannya berbunyi
> *"Biaya aktual belum lengkap"*. **Baca SPI, jangan CPI**, sampai kategori-kategori itu
> punya realisasi. EAC/ETC/VAC ikut cacat selama CPI cacat, dan layar memberi lencana
> *"Ikut cacat selama CPI cacat"*.

**Membekukan baseline.** Tombol **`Bekukan baseline`** (di kepala kartu Baseline atau di
tab Baseline) membuka dialog **Bekukan baseline** dengan tombol kirim **`Buat draf`**:

| Kolom | Wajib | Catatan |
|---|---|---|
| **Tanggal berlaku** | ✓ | *"Biasanya tanggal tanda tangan kontrak atau tanggal adendum."* |
| **Alasan re-baseline** | ✓ **hanya bila proyek sudah punya baseline apa pun** | *"Wajib. Inilah yang membedakan re-baseline dari penghapusan keterlambatan diam-diam."* |
| Jenis dokumen acuan | — | mis. CCO, Addendum, Perpanjangan waktu |
| Nomor dokumen acuan | — | — |
| BAC manual | — | *"Kosongkan agar BAC diambil dari RAP proyek."* |
| Catatan | — | — |

Notifikasi: *"Draf baseline dibuat. Ajukan, lalu minta pengguna lain menyetujuinya agar
rencana benar-benar beku."*

Tombol per baris baseline: **`Ubah`** · **`Ambil ulang`** · **`Ajukan`** / **`Ajukan
ulang`** · **`Bekukan`** (= menyetujui) · **`Tolak`** · **`Hapus`** · **`Lihat laporan`**
(hanya yang disetujui) · **`Lihat isi`** / **`Tutup isi`**.

> **"Bekukan" pada BARIS baseline berarti MENYETUJUI, bukan membuat.** Membuat draf
> memakai tombol `Bekukan baseline` di kepala kartu; `Bekukan` pada barisnya adalah aksi
> persetujuan yang menggantikan revisi sebelumnya. Setelah itu baseline **tidak bisa
> diubah, diambil ulang, maupun dihapus selamanya**.

Penolakan:

- *"Proyek {kode} belum punya RAP; baseline tidak dapat dibekukan karena anggaran biaya
  (BAC) tidak ada. Susun RAP lebih dulu."*
- *"Proyek {kode} belum punya WBS; baseline tidak dapat dibekukan tanpa struktur
  pekerjaan."*
- *"Bobot tugas daun berjumlah {x}%, bukan 100%; perbaiki WBS sebelum membekukan
  baseline."*
- *"Tugas {kode} belum punya tanggal rencana mulai dan selesai; kurva rencana tidak dapat
  dibentuk tanpa keduanya."* · *"Tugas {kode} selesai sebelum mulai (… → …)."*
- *"Baseline revisi {n} untuk {kode} wajib menyebutkan alasan (mis. CCO, addendum, atau
  perpanjangan waktu yang disetujui)."*
- *"Baseline {kode} sudah disetujui dan tidak dapat {diubah|diambil ulang}. Buat revisi
  baru bila rencana memang berubah."* · *"…tidak dapat dihapus — inilah yang membuatnya
  bernilai sebagai bukti."*
- *"Baseline {kode} sedang menunggu persetujuan dan tidak dapat {diubah|dihapus}. Minta
  penolakan lebih dulu bila isinya memang perlu diubah."*
- *"BAC harus lebih besar dari nol."*
- Pemisahan tugas (§2.5) — **Anda tidak bisa menyetujui baseline yang Anda ajukan
  sendiri.**

**Nomor revisi dihitung dari SEMUA baseline termasuk yang ditolak** — draf yang pernah
ditolak tetap menaikkan nomor revisi. Dan begitu proyek punya baris baseline apa pun,
dialog pembekuan berikutnya **wajib** diisi Alasan re-baseline.

**Sumber BAC** ditampilkan sebagai lencana: **RAP disetujui** (hijau) · **RAP belum
disetujui** (kuning) · **Ditetapkan manual**. RAP yang **ditolak** tidak pernah dipakai.

> **Lencana kuning "RAP belum disetujui" berarti BAC-nya masih bisa berubah.** Bila RAP
> itu nanti disetujui dengan nilai lain, SPI dan CPI proyek berubah maknanya — dan
> baseline lama tidak ikut berubah karena memang beku. Yang harus Anda lakukan: **setujui
> RAP-nya dulu**, lalu bekukan baseline revisi berikutnya dengan alasan tertulis.

### 7.11 BAST — `Proyek › BAST`

Kolom: Kode · Proyek · Jenis · Tgl serah terima · Retensi jatuh tempo · Status. Bisa
diubah/dihapus hanya saat Draf atau Ditolak.

Formulir: **Proyek** (wajib, tidak bisa dipindah) · **Jenis BAST** (wajib: *BAST I —
Serah Terima Pertama* / *BAST II — Serah Terima Kedua*) · **Tanggal serah terima**
(wajib) · **Retensi dibayar setelah** (*"Otomatis dari masa pemeliharaan bila dikosongkan
(BAST I)."*; harus ≥ tanggal serah terima) · Wakil pelanggan · Catatan.

Aksi: **`Ajukan`** → **`Setujui`** / **`Tolak`**. Dialog **`Setujui`** punya **dua**
isian: *Catatan persetujuan* dan **"Alasan melewati prasyarat (bila ada peringatan)"**
(*"Minimal 20 karakter. Hanya melewati PERINGATAN — prasyarat wajib tidak dapat
dilewati."*). Tombol **`PDF`** mengunduh BAST berkop.

**Efek persetujuan:**

- **BAST I disetujui** → status proyek menjadi **Masa Pemeliharaan**, tanggal selesai
  aktual terisi dari tanggal serah terima bila kosong. **Sejak detik itu seluruh entri
  lapangan ditolak** (§7.3).
- **BAST II disetujui** → **proyek langsung menjadi Ditutup**, daftar prasyaratnya
  dibekukan pada BAST-nya, dan pemberitahuan *"Retensi proyek {kode} dapat ditagih —
  Rp …"* dikirim ke keuangan.

**Prasyarat BAST II** (BAST I tidak diperiksa sama sekali):

| Tingkat | Butirnya |
|---|---|
| **WAJIB — tidak bisa dilewati alasan** | BAST I sudah disetujui · belum ada BAST II lain yang disetujui · tanggal BAST II tidak mendahului BAST I · **tidak ada temuan kritis/mayor yang terbuka** (kode-kodenya disebut) |
| **PERINGATAN — bisa dilewati dengan alasan ≥20 karakter** | masa pemeliharaan sudah berakhir · tidak ada temuan minor terbuka · progres fisik 100% |
| **INFO** | berapa temuan tercatat · rupiah retensi yang dilepas · termin yang belum ditagih |

Penolakannya berupa satu kalimat: *"BAST II {kode} belum dapat disetujui — {daftar
item}."* atau *"…; sertakan alasan (minimal 20 karakter) bila tetap disetujui."*

> **Tidak ada layar yang memperlihatkan daftar prasyarat BAST II SEBELUM tombol Setujui
> ditekan.** Anda baru membacanya sebagai kalimat penolakan setelah persetujuan ditolak.
>
> **Yang harus Anda lakukan sebelum mengajukan BAST II:** buka **Register Defect**, saring
> ke proyek itu, dan baca petak **"Menahan BAST II"**. Angka di sana adalah angka yang
> persis akan menolak Anda.
>
> (Ini berbeda dari **Tutup proyek**, yang memang membaca daftar periksanya lebih dulu di
> dialog — §7.12.)

> **Menyetujui BAST II langsung menutup proyeknya** — tidak ada konfirmasi kedua dan tidak
> ada tombol pembatalan. Setelah itu semua entri lapangan tertutup, dan satu-satunya jalan
> kembali adalah mengubah status lewat formulir proyek.

**Anda tidak bisa menyetujui BAST yang Anda ajukan sendiri** (§2.5). Pada tim yang hanya
punya satu manajer proyek, itu berarti serah terima berhenti sampai orang kedua
(direktur atau admin) menekan Setujui.

### 7.12 Tutup proyek

Tombol **`Tutup proyek`** (merah) di halaman proyek, hanya untuk pemegang izin setujui dan
hanya bila status bukan Ditutup.

Dialognya membaca daftar periksanya **lebih dulu**, lalu menggambar:

Kalimat pembuka: *"Menutup {kode} — {nama}. Setelah ditutup, laporan harian dan progres
tidak bisa dientri lagi; membuka kembali dilakukan lewat ubah status."*

Enam baris berlencana **Beres** (hijau) / **Wajib dibereskan** (merah) / **Perlu alasan**
(kuning):

| Baris | Tingkat |
|---|---|
| Proyek belum berstatus Ditutup | **wajib** |
| Tidak ada temuan kritis/mayor yang terbuka (kode-kodenya disebut) | **wajib** |
| Tidak ada PO yang masih terbuka (*"…terima barangnya atau batalkan PO-nya"*) | **wajib** |
| Tidak ada temuan minor yang terbuka | peringatan |
| Semua termin kontrak sudah ditagih | peringatan |
| Tidak ada retensi yang belum dicairkan | peringatan |

Kotak alasan (minimal 20 karakter) **hanya digambar bila ada peringatan yang gagal**.
Bila ada blokir wajib, tombol **`Tutup proyek`** mati dan muncul peringatan: *"Ada item
yang wajib dibereskan dulu — alasan tidak bisa melewatinya. Bereskan itemnya, lalu buka
dialog ini lagi."*

Sukses → *"Proyek {kode} ditutup."* Yang tersimpan: status Ditutup, waktu dan pelaku
penutupan, **rekaman seluruh daftar periksa**, dan alasan yang dipakai melewatinya —
sehingga setahun kemudian pertanyaan "kenapa proyek ini tutup dengan termin terbuka?"
punya jawaban bernama.

**Tidak ada tombol "Buka kembali proyek".** Proyek yang tertutup hanya bisa dibuka lewat
formulir **`Ubah`** dengan memilih status lain — dialog Tutup proyek menyebutkannya tetapi
tidak menyediakan tombolnya. Dan setiap penutupan berikutnya harus memenuhi daftar
periksanya lagi.

### 7.13 Tujuh formulir rumah proyek

| Formulir | Tombolnya di mana | Sumber datanya |
|---|---|---|
| **Data Proyek** (F/DP) | halaman proyek, kepala halaman | 17 baris dari data proyek — semuanya terisi |
| **Laporan Harian** (F/LH) | **halaman** laporan harian | satu laporan + kelima tabel barisnya (§7.3) |
| **Detail Schedule** (F/DS) | **baris** daftar Progres Mingguan (ikon printer) | WBS + baseline + progres mingguan |
| **Daftar Temuan** (F/DT) | **halaman** satu temuan | **seluruh register temuan proyek itu** |
| **Izin Kerja Lapangan** (F/IK) | **halaman satu izin** — register `Izin Kerja (IKL)` | izin itu: shift, jam berlaku, pekerjaan, tabel bahaya/APD |
| **Izin Kerja Lembur** (F/IL) | **halaman satu izin** — register `Izin Lembur (ILB)` | izin + satu baris tercetak per pekerja |
| **Izin Material & Peralatan** (F/IM) | **halaman satu izin** — register `Izin Material (IMK)` | izin + baris barangnya; kotak MASUK/KELUAR dicentang |

**Ketiga izin lapangan adalah dokumen bernomor sekarang** — IKL/ILB/IMK, masing-masing
dengan registernya sendiri di menu **Proyek**: `Izin Kerja (IKL)`, `Izin Lembur (ILB)`,
`Izin Material (IMK)`. Satu baris register = satu izin, dan lembarnya **tercetak dari
baris itu** lewat tombol `Cetak …` di kepala halaman izinnya. Kartu **Izin lapangan
(IKL / ILB / IMK)** di halaman proyek tinggal papan penunjuk ke ketiga register —
tombol cetak lama yang mencetak pad kosong dari halaman proyek sudah tidak ada.

Ketiganya memakai siklus dokumen baku (§2.5): `Ajukan` oleh pemegang ubah proyek,
`Setujui`/`Tolak` oleh pemegang **setujui proyek** — pada peran bawaan direktur,
project-manager, dan admin — dengan maker-checker seperti dokumen lain. Ubah dan hapus
hanya saat Draf atau Ditolak. Izin hanya bisa dientri pada proyek operasional;
selain itu ditolak: *"Proyek {kode} berstatus {status}; izin … hanya dapat dientri
pada proyek berstatus Persiapan, Berjalan, atau Finishing."*

**Izin Kerja Lapangan — `Proyek › Izin Kerja (IKL)`.** `Tambah Izin Kerja Lapangan`:
Proyek · Tanggal izin (*"Harus di dalam waktu pelaksanaan proyek."*) · Shift
(Pagi/Siang/Malam) · Paket WBS · Berlaku mulai/sampai · Pekerjaan yang dimohonkan ·
Potensi bahaya (*"Satu potensi bahaya per baris — tercetak per baris pada tabel APD."*) ·
APD wajib (satu per baris: helm, harness, …) · Pemohon (pelaksana/mandor) · Petugas K3.
Lembar F/IK mencetak nomor & tanggal izin, shift dan jamnya, pekerjaan, tabel
bahaya/APD baris-sejajar, nama pemohon, dan nama petugas K3 bila diisi. Penolakan yang
akan Anda temui:

> *"Berlaku sampai ({waktu}) harus setelah berlaku mulai ({waktu})."*
>
> *"Tanggal izin {tanggal} di luar waktu pelaksanaan proyek {kode} ({mulai} s/d
> {selesai}). Izin kerja hanya untuk hari di dalam masa pelaksanaan — perpanjangan
> waktu dicatat lewat CCO waktu, bukan lewat izin."*

**Izin Kerja Lembur — `Proyek › Izin Lembur (ILB)`.** `Tambah Izin Kerja Lembur`:
Tanggal lembur · Jam mulai/selesai · Alasan lembur, lalu tabel **Daftar pekerja
lembur** — per baris pilih **Karyawan** ATAU ketik **Nama non-karyawan** (kru mandor),
tepat satu, plus **Jam** (> 0, ≤ 24). Lembur melewati tengah malam ditulis dengan jam
selesai lebih kecil (mis. 22:00 s/d 02:00) — lembarnya mencetak *"(lewat tengah
malam)"* di samping jamnya. Penolakannya:

> *"Jam selesai ({jam}) sama dengan jam mulai ({jam}) — lembur berdurasi nol. Lembur
> yang melewati tengah malam ditulis dengan jam selesai lebih kecil dari jam mulai
> (mis. 22:00 s/d 02:00)."*
>
> *"Izin lembur tanpa satu pun baris pekerja bukan izin — lembar ini ditandatangani per
> orang."*
>
> *"Baris pekerja #{n}: isi employee_id ATAU worker_name, tepat satu — karyawan dirujuk
> ke daftar karyawan, kru mandor non-karyawan ditulis namanya."*

**`Setujui` pada izin lembur mengisi rekap payroll.** Jam per KARYAWAN bulan itu
dihitung ulang dari seluruh izin yang disetujui dan ditulis ke kolom jam lembur rekap
absensi (§11.5) — baris nama non-karyawan tetap tercetak di lembar tetapi tidak
pernah menyentuh rekap. Periode yang payrollnya sudah diposting **tidak ditulis ulang**;
pesannya mengatakannya: *"Izin lembur disetujui. Rekap {YYYY-MM} tidak diubah — payroll
periode itu sudah diposting."* Register izinnya tetap menyimpan kebenarannya.

**Izin Masuk/Keluar Material — `Proyek › Izin Material (IMK)`.** `Tambah Izin
Masuk/Keluar Material`: Arah barang (Masuk/Keluar) · Tanggal · No. polisi kendaraan ·
Nama pengemudi · Vendor (bila terdaftar) atau Asal/tujuan (teks bebas), lalu tabel
**Rincian material / peralatan** (Item stok bila ada di gudang, Jenis barang, Jumlah,
Satuan, Keterangan). Urutannya ditegakkan server: **manajemen menyetujui dulu, baru
gerbang memeriksa** — tombol **`Periksa di gerbang`** (butuh izin ubah proyek; ditekan
oleh yang mewakili pos jaga) hanya muncul pada izin **Disetujui** yang belum dicap,
mengecap *Diperiksa oleh/pada* dengan pemakai yang menekannya — sekali saja, lalu
tombolnya hilang. Sukses: *"Muatan diperiksa di gerbang."* Penolakannya:

> *"Izin {kode} belum disetujui (status: {status}) — pemeriksaan gerbang hanya untuk
> izin yang sudah disetujui manajemen."*
>
> *"Izin {kode} sudah diperiksa oleh {nama} pada {waktu} — cap gerbang adalah bukti
> satu kejadian dan tidak ditimpa."*

Lembar F/IM mencentang kotak arah dari izinnya, mencetak baris barangnya, dan mengisi
kolom *Diperiksa* **hanya setelah** cap gerbang. Foto muatan ditempel lewat kartu
**Lampiran** izin itu (foto APD pada izin kerja juga begitu); keduanya ikut muncul di
Galeri Foto Progres (§7.4).

> **Aturan kejujuran tetap berlaku (§13.5)**: yang tercetak adalah baris izinnya; sel
> yang tidak punya sumber di basis data — lokasi/area dan tabel ALAT pada F/IK, jam per
> orang pada F/IL, kolom SPESIFIKASI pada F/IM, kolom PENGENDALIAN, dan sisa baris
> tabel — tetap **bergaris untuk ditulis tangan di lokasi**. Blok tanda tangan hanya
> membawa nama yang benar-benar tersimpan: pemohon dan petugas K3 pada F/IK, pemeriksa
> gerbang pada F/IM setelah dicap — kolom lainnya menunggu tanda tangan basah, karena
> riwayat persetujuan di aplikasi bukan klaim yang sama dengan "orang ini
> menandatangani lembarnya".

**Form F/DS (Detail Schedule).** Mendatar. Satu blok kolom per minggu ISO yang menyentuh
bulan itu, enam kolom hari Senin–Sabtu. Baris = pohon WBS.

- Kolom **VOLUME** = volume **kontrak** dari baris BOQ yang tertaut, bukan volume bulan
  berjalan. Kosong berarti paket itu belum tertaut ke BOQ.
- **Batang rencana diarsir dari baseline yang DISETUJUI**, bukan dari tanggal rencana WBS.
  Tanpa baseline, lembar mencetak *"Batang rencana TIDAK dicetak: proyek ini belum
  memiliki baseline yang disetujui, sehingga kolom hari diisi manual seperti pada form
  kertas."*
- Baris JUMLAH BOBOT RENCANA/REALISASI diambil dari progres mingguan **menurut rentang
  tanggal**, bukan nomor minggu; minggu tanpa baris dicetak **kosong**, bukan 0%.

> **Tombol cetak Detail Schedule pada baris Progres Mingguan selalu mencetak jadwal BULAN
> BERJALAN.** Mencetak dari baris minggu 3 (Februari) menghasilkan lembar bulan Agustus.
> Tidak ada cara memilih bulan dari layar. Cetaklah pada bulan yang bersangkutan, atau
> catat keterbatasan itu pada arsipnya.

**Kop empat pihak** sama di ketujuhnya: PEMILIK (nama pelanggan) / KONSULTAN MK / PROYEK /
KONTRAKTOR, lalu NO. SPK / KONTRAK (nomor kontrak **milik pelanggan** lebih dulu) ·
TANGGAL SPK · WAKTU PELAKSANAAN · **PERPANJANGAN WAKTU I & II (dari addendum waktu yang
disetujui — kosong bila kontraknya tidak punya)** · PERIODE · TANGGAL · MINGGU KE ·
HARI KE · SISA HARI. Ketiga angka terakhir kosong bila proyek belum punya tanggal.

**Perpanjangan waktu dicatat sebagai CCO berjenis `waktu`** (§3.7). Yang DISETUJUI
tercetak pada kedua baris kop, urut tanggal perubahan, format
`+14 hari → 14 Agu 2027 (CCO/2026/VIII/0003)`; addendum ketiga dst membuat baris II
berbunyi `lihat register`. Draf, yang ditolak, dan CCO nilai tidak pernah mencapai kop.

---

## 8. Subkontraktor

Tiga layar di kelompok **Subkontrak**: **SPK Subkon**, **Addendum SPK**, **Opname
Subkon**. Uang keluarnya lewat Tagihan Vendor (AP) dan Pembayaran di kelompok Keuangan.

### 8.1 Siapa mengerjakan apa

| Langkah | Layar / tombol | Yang mengerjakan |
|---|---|---|
| 1. Susun SPK | Subkontrak › SPK Subkon | manajer proyek |
| 2. Ajukan SPK | `Ajukan` | manajer proyek — gerbang prakualifikasi + anggaran berbunyi |
| 3. Setujui SPK | `Setujui` | **direktur** (dan wajib direktur bila nilainya ≥ Rp 200 juta) |
| 4. Opname progres | Subkontrak › Opname Subkon | manajer proyek |
| 5. Setujui opname | `Setujui` | direktur |
| 6. Tagihkan opname | Keuangan › Tagihan Vendor (AP), kolom **Dari opname subkon** | keuangan |
| 7. Setujui tagihan | `Setujui` | manajer keuangan |
| 8. Bayar | Keuangan › Pembayaran | keuangan (§5.10) |
| 9. Lepas retensi | tombol `Bayar Retensi` di halaman SPK | **admin** (§8.7) |

Peran `project-manager` memegang lihat/buat/ubah pada modul Subkontrak, **tetapi tidak
memegang izin setujui dan tidak memegang izin posting**. Dua tombol di halaman SPK —
**`Bayar Retensi`** dan **`Cairkan Uang Muka`** — menuntut **izin posting subkontrak DAN
izin persetujuan keuangan sekaligus**, yang pada susunan peran bawaan **hanya dipegang
admin**. Awas jebakannya: **`Bayar Retensi` digambar berdasarkan izin posting saja**,
jadi pemegang izin posting tanpa izin persetujuan keuangan tetap melihat tombolnya —
dan ditolak server saat menekannya (§8.7). Lihat bab 14.

### 8.2 SPK Subkontraktor — `Subkontrak › SPK Subkon`

Kolom: Kode · Pekerjaan (dengan nama subkon) · Proyek · **Nilai SPK** · **PPh final** ·
Status. Saringan: Status, Proyek, Subkontraktor. Bisa diubah/dihapus hanya saat Draf atau
Ditolak.

Teks bantuan di formulir: *"PPN mengikuti status PKP vendor; tarif PPh final PP 9/2022
di-snapshot dari skema yang dipilih."*

**Menyusun SPK:**

1. **`Tambah SPK`**.
2. Isi **Subkontraktor** (wajib) — pemilihnya **hanya memuat vendor yang bercentang
   Subkontraktor** pada master vendor (§5.2). Vendor biasa ditolak:
   `"Vendor {code} ({nama}) is not registered as a subcontractor."`
3. Isi **Proyek** (wajib), **Judul pekerjaan** (wajib), **Skema PPh final konstruksi**
   (wajib), **Retensi (%)** (bawaan 5), **Mulai** (wajib), Selesai.
4. Isi **Masa pemeliharaan s/d** bila sudah diketahui — bantuan: *"Retensi hanya dapat
   dilepas setelah tanggal ini (atau dengan alasan override)."*
5. Isi Lingkup pekerjaan dan Catatan.
6. Isi tabel **Rincian pekerjaan** (minimal 1): Kode WBS · Uraian · Volume · Satuan ·
   **Harga satuan** (wajib). Setiap baris butuh volume positif
   (`"Every SPK line needs a positive quantity."`).
7. **`Simpan`**, lalu **`Ajukan`**.

**Skema PPh final** menentukan tarif yang dibekukan pada SPK ini (PP 9/2022):

| Skema | Tarif |
|---|---|
| Pelaksanaan — kualifikasi kecil, bersertifikat | 1,75% |
| Pelaksanaan — bersertifikat menengah/besar | 2,65% |
| Pelaksanaan — tanpa sertifikat | 4% |
| Perancangan/pengawasan — bersertifikat | 3,5% |
| Perancangan/pengawasan — tanpa sertifikat | 6% |
| Terintegrasi — bersertifikat | 2,65% |
| Terintegrasi — tanpa sertifikat | 4% |

**Mengajukan SPK** melewati gerbang yang sama dengan PO:

1. **Prakualifikasi** (§5.3). Tombol `Ajukan` **selalu membuka dialog** berisi satu kotak
   **Alasan override prakualifikasi** (*"Kosongkan bila subkon sehat. Isi hanya bila
   pengajuan ditolak gate prakualifikasi dan tetap harus jalan."*). Sama seperti PO,
   **alasan hanya tercap bila gerbangnya benar-benar memblokir**.
2. **Gerbang anggaran** (§4.7), diadu dengan **sisi SUBKON** anggaran RAP. Dialognya
   berjudul **"Melampaui sisa anggaran RAP subkon — tetap ajukan?"** dengan tombol
   **`Ya, tetap ajukan`**.

**Menyetujui SPK.** Selain pemisahan tugas, ada **ambang direktur Rp 200.000.000**: SPK
yang nilainya mencapai atau melewatinya dicap saat pengajuan, dan penyetuju
bukan-direktur ditolak dengan pesan yang menyebut nilainya, ambangnya, dan izin yang
diperlukan.

**Halaman SPK** adalah layar khusus. Petak angkanya: **Nilai SPK** · **Sudah diopname**
(+ persen dari nilai SPK) · **Retensi ditahan** (+ berapa yang sudah dibayar) · **Saldo
retensi** · **Uang muka (DP)** bila ada · **PPh final konstruksi** (+ nama skemanya) ·
**PPN** (+ *"Vendor PKP"* / *"Vendor non-PKP"*).

Kartu **Rincian pekerjaan** memperlihatkan **ID · Kode WBS · Uraian · Volume · Harga
satuan · Nilai · Progres**. **Kolom ID itu penting** — angka itulah yang Anda ketik ke
formulir opname (§8.4).

Kartu **Opname (progress claim)** memuat tombol **`Buat opname`** (muncul hanya bila SPK
sudah Disetujui) dan tabel semua opname SPK itu.

Kartu samping **Informasi SPK**: Proyek · Subkontraktor · Periode · **Masa pemeliharaan
s/d** · Retensi · **Nilai asal (pra-addendum)** · Terbilang · Lingkup.

**SPK tidak punya kartu Riwayat Persetujuan** — siapa yang membuat dan menyetujuinya
tidak tampil di halaman itu, walau persetujuan direktur justru syarat terbitnya.

**Mencatat masa pemeliharaan setelah SPK disetujui.** Tombol **`Catat masa pemeliharaan`**
(muncul pada SPK berstatus Diajukan atau Disetujui) mengisi **satu kolom saja**:
**Masa pemeliharaan s/d** (wajib; *"Gate pelepasan retensi memakai tanggal ini."*). Pintu
itu ada karena tanggal masa pemeliharaan biasanya baru diketahui dari BAST I — setelah
tombol `Ubah` sudah hilang. Server menolaknya begitu retensi pernah dilepas.

Menghapus SPK yang punya opname ditolak: `"SPK {kode} has progress claims and cannot be
deleted."` Mengubah SPK yang sudah bergerak: `"SPK {kode} is {status} and can no longer
be edited."`

**Mencetak:** **`Cetak SPK Subkontraktor`** — *SURAT PERINTAH KERJA (SPK)
SUBKONTRAKTOR* (Form F/SP). Blok identitasnya memuat NO. SPK, TANGGAL SPK, SUBKONTRAKTOR,
ALAMAT, NPWP, PROYEK, TANGGAL MULAI/SELESAI, **MASA PEMELIHARAAN S/D**, NILAI SPK (DPP),
PPN, RETENSI, NILAI RETENSI. **Baris TERMIN PEMBAYARAN sengaja bergaris kosong** —
sistem tidak menyimpan jadwal pembayaran SPK apa pun, dan termin bayar pada master vendor
adalah bawaan penagihan vendor itu, **bukan syarat SPK ini**. Ditulis tangan.

Tanggal surat = **tanggal SPK dibuat di sistem**, bukan hari mencetak.

### 8.3 Addendum SPK — `Subkontrak › Addendum SPK`

Kolom: Kode · Judul (dengan kode SPK) · Tanggal · **Jenis** · **Perubahan nilai** ·
Status.

Teks bantuan di formulir, dan ia adalah aturan pokoknya:

> "Pekerjaan tambah masuk sebagai BARIS BARU — baris lama tidak pernah diubah, opnamenya
> terlanjur dihitung dari nilai lama. Pekerjaan kurang cukup nilai negatif tanpa baris."

Kolom: **SPK** (wajib, **hanya saat membuat**) · **Tanggal** (wajib) · **Judul** (wajib) ·
**Jenis perubahan** (bawaan Tambah-Kurang; *"Pilih 'Eskalasi Harga' untuk penyesuaian
harga — nilainya dihitung di luar dan masuk lewat perubahan nilai."*) · **Perubahan
nilai** (wajib; *"Positif untuk pekerjaan tambah (wajib membawa baris), negatif untuk
pekerjaan kurang."*) · Sebab · Uraian.

Tabel baris berjudul **"Baris pekerjaan tambahan (total harus sama dengan perubahan
nilai)"**: Kode WBS · Uraian · Volume · Satuan · **Harga satuan** (wajib). Setiap baris
butuh volume positif (*"Setiap baris addendum memerlukan volume yang positif."*).

Membuat addendum atas SPK yang belum disetujui ditolak:
> "SPK {kode} berstatus {status}. Addendum hanya berlaku atas SPK yang sudah disetujui —
> ubah nilainya langsung selama masih draf."

**Menyetujui addendum menggerakkan nilai SPK** dan menambahkan baris barunya ke rincian
pekerjaan. Penolakannya:

- `"Nilai SPK tidak boleh menjadi negatif."`
- *"Nilai SPK setelah addendum ({angka}) lebih kecil daripada yang sudah diopname
  ({angka})."*
- *"Sisa nilai SPK setelah addendum ({angka}) tidak lagi menampung uang muka yang belum
  diperhitungkan ({angka}); potongan uang muka tidak akan pernah selesai."*
- Addendum yang membawa nilai SPK melewati ambang direktur:
  > "Addendum {kode} membawa nilai SPK melewati ambang persetujuan direktur; dokumen ini
  > hanya dapat disetujui oleh pemegang izin scm.approve-director — pada instalasi standar
  > peran direktur."

**Mencetak:** **`Cetak Addendum SPK`** — *BERITA ACARA ADDENDUM SPK* (Form F/AS).

### 8.4 Opname Subkon — `Subkontrak › Opname Subkon`

Kolom: Kode · SPK (dengan judulnya) · **Opname ke-** · **UM** (penanda klaim uang muka) ·
Periode s/d · **Bruto** · **Netto dibayar** · Status. Bisa diubah/dihapus hanya saat Draf
atau Ditolak.

**Kolom "UM" ada karena klaim uang muka duduk di daftar yang sama dengan opname biasa.**
Tanpa penanda itu, DP Rp 40 juta terbaca sebagai opname pekerjaan.

Teks bantuan di formulir: *"Isi progres kumulatif per baris SPK. Nilai periode dihitung
dari selisih terhadap progres sebelumnya."*

**Membuat opname:**

1. Dari halaman SPK, tekan **`Buat opname`** (atau `Tambah Opname` dari daftarnya).
2. Isi **SPK** (wajib, **hanya saat membuat**), **Periode mulai** (wajib), **Periode
   selesai** (wajib), Catatan.
3. Isi tabel **Progres per baris pekerjaan**: **ID baris SPK** (wajib — **angka mentah**,
   salin dari kolom **ID** pada tabel Rincian pekerjaan di halaman SPK) dan **Progres
   kumulatif (%)** (wajib).
4. **`Simpan`**, lalu **`Ajukan`**, lalu direktur **`Setujui`**.

**Anda memasukkan progres KUMULATIF, bukan progres periode ini.** Nilai periodenya
dihitung sistem sebagai selisih terhadap progres yang tercatat sebelumnya.

Strip ringkasan di halaman opname memperlihatkan seluruh rantai angkanya: **Bruto ·
Retensi · Netto sebelum pajak · PPN · PPh · Potongan uang muka · Netto dibayar**. Tabel
rinciannya: Uraian · Progres lalu · Progres kini · Periode ini · Nilai.

Penolakan yang akan Anda temui:

- `"SPK {kode} is {status}; opname can only be raised against an approved SPK."`
- `"Line {id} does not belong to SPK {kode}."` — ID baris yang Anda ketik milik SPK lain.
- `"Progress on \"{uraian}\" cannot go backwards ({x}% < {y}%)."`
- `"Progress on \"{uraian}\" cannot exceed 100% (got {x}%)."`
- `"Opname of {bruto} exceeds the remaining SPK value {sisa} on {kode}."`
- `"Opname {kode} is {status} and can no longer be edited."`
- Progres bergeser sejak opname didraf:
  `"Line \"{uraian}\" progress is now {x}% but the opname was drafted at {y}%; edit and
  resubmit the claim."`
- Potongan uang muka basi:
  *"Potongan uang muka {x} pada {kode} melebihi sisa uang muka {y}; ubah dan ajukan ulang
  opname."*

Menyetujui opname **memperbarui kolom Progres pada baris SPK-nya**. Ia **tidak** membuat
tagihan — itu langkah keuangan berikutnya.

**Mencetak:** **`Cetak Berita Acara Opname`** — *BERITA ACARA OPNAME DAN PEMBAYARAN
SUBKONTRAKTOR* (Form F/BO, mendatar).

### 8.5 Menagihkan opname — sisi keuangan

Keuangan membuat tagihan dari `Keuangan › Tagihan Vendor (AP)` dengan mengisi kolom
**Dari opname subkon** (§5.9). Nilainya diturunkan dari opname: DPP = bruto dikurangi
potongan uang muka, plus PPN dan PPh sesuai skema SPK, dikurangi retensi.

Penolakan:

- `"Opname {kode} is {status}; only approved claims can be billed."`
- `"A bill already exists for opname {kode}."`
- Klaim uang muka yang salah jalur:
  > "{kode} adalah klaim uang muka; cairkan lewat menu Uang Muka pada SPK-nya, bukan lewat
  > tagihan opname."

**Tagihan dari opname selalu dibebankan ke kategori RAP `Subkon`** — itu satu-satunya
kategori yang diturunkan otomatis dan tidak bisa dipilih di layar (§5.9).

### 8.6 Uang muka subkontraktor

Dua tombol di halaman SPK, berurutan.

**a. `Klaim Uang Muka`** (izin buat subkontrak; muncul pada SPK **Disetujui** yang belum
punya klaim DP). Dialog **Klaim uang muka (DP)**: **Jumlah DP (DPP)** (wajib),
**Tanggal** (wajib), Catatan; tombol kirim **`Buat klaim`**. Notifikasi: *"Klaim uang muka
dibuat; ajukan dan setujui seperti opname biasa."*

Klaim itu muncul di daftar Opname Subkon berpenanda **UM**, dan berjalan lewat
`Ajukan` → `Setujui` seperti opname biasa.

Penolakan: `"SPK {kode} is {status}; uang muka hanya dapat diajukan atas SPK yang sudah
disetujui."` · `"SPK {kode} sudah memiliki klaim uang muka {kode}."` · *"Nilai uang muka
harus lebih besar dari nol."* · dan:
> "Uang muka {x} melebihi sisa nilai SPK yang belum diopname ({y}) pada {kode}; tidak akan
> ada opname yang cukup untuk memotongnya kembali."

**b. `Cairkan Uang Muka`** (muncul setelah klaimnya **Disetujui** dan belum dicairkan).
Dialog **Pencairan uang muka**: **Tanggal pencairan** (wajib); tombol **`Cairkan`**.
Notifikasi: *"Uang muka dicairkan; tagihan pembayaran diterbitkan."*

**Tombol itu butuh izin posting subkontrak DAN izin persetujuan keuangan sekaligus** —
pada susunan peran bawaan hanya admin (bab 14). Penolakan:
`"SPK {kode} belum memiliki klaim uang muka yang disetujui; tidak ada yang dapat
dicairkan."` · `"Uang muka {kode} sudah dicairkan lewat tagihan {kode}."`

**Uang muka dipotong kembali otomatis** dari opname berikutnya. Petak **Uang muka (DP)**
di halaman SPK menunjukkan sisa yang belum diperhitungkan.

### 8.7 Retensi subkontraktor

Retensi ditahan otomatis dari setiap opname sesuai **Retensi (%)** pada SPK. Empat petak
di halaman SPK memperlihatkan posisinya: **Retensi ditahan**, berapa yang **sudah
dibayar**, dan **Saldo retensi**.

**Melepas retensi.** Tombol **`Bayar Retensi`** muncul bila saldo retensi > 0, untuk
setiap pemegang izin posting subkontrak (`scm.post`). Tetapi **menjalankannya menuntut
dua izin sekaligus: `scm.post` DAN `fin.approve`** (§8.1) — satu klik di sini menerbitkan
tagihan vendor yang **sudah disetujui**. Pada susunan peran bawaan hanya **admin**
memegang keduanya. Jebakannya: pemegang `scm.post` tanpa `fin.approve` **tetap melihat
tombolnya**, dan baru ditolak server saat menekannya — layar menjawab
`User does not have the right permissions.` Itu bukan kerusakan; minta pemegang kedua
izin (admin) yang menekannya. (`Cairkan Uang Muka` tidak menjebak begini: tombolnya
hanya digambar bila kedua izin dipegang.)

Dialog **Pembayaran retensi**: **Tanggal pembayaran** (wajib) · **Jumlah** (wajib,
terisi saldo penuh) · Catatan · **Alasan pelepasan dini (override)**. Tombol **`Bayar`**.
Notifikasi: *"Pembayaran retensi dicatat."*

Bantuan pada kolom override berubah menurut keadaan SPK:

- SPK sudah punya tanggal masa pemeliharaan → *"Wajib diisi bila dilepas sebelum
  {tanggal}."*
- SPK belum punya tanggal itu → *"SPK belum mencatat akhir masa pemeliharaan — wajib
  diisi. Atau catat dulu tanggalnya lewat aksi Catat masa pemeliharaan (bisa walau SPK
  sudah disetujui), supaya pelepasan berikutnya tidak perlu override."*

Penolakan:

- *"SPK {kode} belum memiliki opname disetujui yang menahan retensi; tidak ada yang dapat
  dilepas."*
- *"Nilai pelepasan retensi harus lebih besar dari nol."*
- *"Pelepasan {x} melebihi saldo retensi {y} pada SPK {kode}."*
- Retensi belum menjadi kewajiban di buku besar:
  > "Retensi yang sudah dibukukan pada 2-1500 baru {x}; setujui dulu tagihan opname SPK
  > {kode} sebelum melepas {y}."

  Artinya: opname sudah menahan retensinya, tetapi **belum ada tagihan yang disetujui**
  yang mengubahnya menjadi kewajiban. Setujui tagihannya lebih dulu.
- Sebelum masa pemeliharaan berakhir, tanpa alasan override:
  > "Retensi SPK {kode} baru dapat dilepas setelah masa pemeliharaan berakhir ({tanggal});
  > pelepasan tanggal {tanggal} hanya dapat dilakukan dengan alasan override."
- SPK belum mencatat tanggalnya sama sekali, tanpa alasan override:
  > "SPK {kode} belum mencatat akhir masa pemeliharaan (defect_liability_until); lengkapi
  > tanggalnya pada SPK, atau lepaskan dengan alasan override bila retensi memang sudah
  > boleh dibayar."

Pelepasan retensi **menerbitkan tagihan vendor tersendiri**, yang lalu dibayar lewat jalur
Pembayaran biasa (§5.10). Alasan override, bila dipakai, tersimpan permanen pada baris
pelepasannya.

---

## 9. Aset & alat berat

Kelompok **Aset** berisi tujuh layar: **Daftar Aset · Kategori Aset · Mobilisasi ·
Log BBM & Jam Alat · Perawatan · Penyusutan · Utilisasi Aset**.

### 9.1 Siapa boleh apa

| Peran | Lihat | Buat/Ubah | Posting | Setujui |
|---|---|---|---|---|
| admin | ✓ | ✓ | ✓ | ✓ |
| **finance** | ✓ | — | **✓** | — |
| project-manager | ✓ | ✓ | — | — |
| direktur | ✓ | — | — | ✓ |

Pembagian itu disengaja: **sisi proyek mencatat asetnya, keuangan yang memposting
penyusutannya**, sehingga orang yang mencatat sebuah aset dan orang yang membebankan
penyusutannya bukan orang yang sama.

Tiga tombol butuh izin posting — **`Demobilisasi`**, **`Posting Penyusutan`**, dan
**`Hapus Buku / Jual`** — jadi seorang manajer proyek tidak akan melihatnya.

Satu layar berdiri di luar tabel di atas: **Log BBM & Jam Alat** (§9.5) memakai izin
Proyek, bukan izin Aset — mencatatnya butuh izin ubah proyek (site manager dan manajer
proyek), membacanya butuh izin lihat aset ATAU lihat proyek. Itu sebabnya seorang site
manager, yang tidak memegang satu pun izin `ast`, tetap melihat kelompok Aset berisi
satu baris itu.

### 9.2 Kategori Aset — isi ini lebih dulu

`Aset › Kategori Aset` (tanpa halaman detail; ubah/hapus dari ikon baris). Kolom: Kode ·
Nama kategori · Umur manfaat default · Akun beban · Akun akumulasi · Akun harga
perolehan.

Formulir: **Kode** (wajib) · **Nama kategori** (wajib) · **Umur manfaat default (bulan)**
(wajib) · **Kode akun beban penyusutan** · **Kode akun akumulasi** · **Kode akun harga
perolehan** — yang terakhir berbantuan: *"Dikredit saat aset dihapusbukukan/dijual (mis.
1-2300 Kendaraan). Tanpa akun ini pelepasan aset kategori ini ditolak."*

> **Ketiga kode akun itu bukan hiasan.** Tanpa akun beban dan akumulasi, posting
> penyusutan ditolak:
>
> "Kategori aset {nama} belum memiliki akun penyusutan/akumulasi. Lengkapi di Master Data
> › Kategori Aset sebelum memposting."
>
> Tanpa akun harga perolehan, penghapusbukuan ditolak dengan kalimat sejenis. Kode akunnya
> berasal dari Bagan Akun — bila Anda tidak tahu harus mengisi apa, tanyakan keuangan atau
> administrator (`docs/PANDUAN-ADMINISTRATOR.md` §4.4).

### 9.3 Daftar Aset — `Aset › Daftar Aset`

Kolom: Kode · Nama aset (dengan kategorinya) · No. seri · Harga perolehan · **Nilai
buku** · Proyek · Status. Saringan: Status, Kategori, Proyek.

Status aset: **Tersedia · Termobilisasi · Dalam Perawatan · Dihapusbukukan**.

**Mendaftarkan aset:**

1. **`Tambah Aset`**.
2. Bagian *Aset*: **Nama aset** (wajib) · **Kategori** (wajib) · Nomor seri · Merek ·
   Model · **Status** (hanya saat Ubah, dan pilihannya **hanya Tersedia dan Dalam
   Perawatan**) · Penanggung jawab · Gudang.
3. Bagian *Perolehan & penyusutan*: **Tanggal perolehan** (wajib) · **Harga perolehan**
   (wajib) · Nilai residu · **Umur manfaat (bulan)** (wajib) · Mulai disusutkan ·
   Catatan.
4. **`Simpan`**.

**Status "Dihapusbukukan" tidak ada di daftar pilihan** dan tidak bisa dicapai lewat
`Ubah` — ia hanya lahir dari aksi **`Hapus Buku / Jual`** (§9.9), yang memposting jurnal
pelepasannya.

**Halaman aset** memperlihatkan empat petak — Harga perolehan · **Akumulasi penyusutan**
(+ beban per bulan) · **Nilai buku** · **Umur manfaat** (+ "mulai {tanggal}" atau "belum
disusutkan") — lalu empat kartu riwayat: **Mobilisasi**, **Log BBM & jam alat**,
**Perawatan**, dan **Penyusutan**. Aset yang sudah dihapusbukukan mendapat panel
kuning di atas berisi tanggal, alasan, dan hasil pelepasannya.

### 9.4 Mobilisasi dan Demobilisasi

Dua pintu masuk yang sama hasilnya:

**a. Dari asetnya.** `Aset › Daftar Aset` → buka asetnya → **`Mobilisasi ke Proyek`**
(muncul hanya pada aset berstatus **Tersedia**). Isian: **Proyek** (wajib) · Mulai
(bawaan hari ini) · Rencana sampai · **Tarif internal per hari** · Catatan.

**b. Dari daftar mobilisasi.** `Aset › Mobilisasi` → **`Tambah Mobilisasi`**: **Aset**
(wajib) · **Proyek** (wajib) · **Mulai** (wajib) — ketiganya **hanya bisa diisi saat
membuat** — lalu Rencana sampai · Tarif internal per hari · Catatan.

Daftar Mobilisasi berkolom Kode · Aset · Proyek · Dari · Rencana sampai · **Tarif/hari** ·
Status (Aktif / Dikembalikan). Saringan: Proyek, Status.

> **`Tarif internal per hari` terlihat opsional tetapi menggerakkan akuntansi.**
>
> **Diisi** → hari-di-lokasi × tarif masuk ke Biaya Proyek pada kategori **Alat**,
> diakru per bulan dan sisanya dibebankan saat demobilisasi.
>
> **Dikosongkan** → alat itu **tidak pernah muncul** di AC maupun CPI proyek. Laporan EVM
> proyek itu akan memperlihatkan kategori Alat sebagai *"Dianggarkan, belum tercatat"*
> selamanya (§7.10).

**Demobilisasi.** Tombol **`Demobilisasi`** pada baris mobilisasi yang masih Aktif (izin
posting). Isian: **Tanggal kembali** (bawaan hari ini) · Catatan.

Penolakan — ketiganya **berbahasa Inggris**:

| Pesan | Artinya |
|---|---|
| `"Asset {kode} is {status}; only available assets can be deployed."` | Aset sedang termobilisasi, dalam perawatan, atau sudah dihapusbukukan |
| `"Deployment {kode} is already returned."` | Mobilisasi itu sudah didemobilisasi |
| `"Return date {tgl} is before deployment start {tgl}."` | Tanggal kembali mendahului tanggal mulai |

Satu penolakan berbahasa Indonesia:
> "Periode fiskal {YYYY-MM} sudah ditutup; demobilisasi {kode} bertanggal {tgl} tidak
> dapat membebankan pemakaian alat ke dalamnya."

**Tenggat** mengawasi mobilisasi yang melewati **Rencana sampai**, **7 hari** di muka,
untuk pemegang izin ubah aset.

### 9.5 Log BBM & Jam Alat — `Aset › Log BBM & Jam Alat`

Register pembacaan lapangan (dibangun 22 Agustus 2026, setelah keputusan pemilik —
deviasi #13): berapa jam alat sudah berjalan dan berapa liter BBM diisikan, per
mobilisasi. **Register ini murni catatan — ia tidak membuat jurnal, tidak
menggerakkan stok, dan tidak membawa rupiah.** Uang BBM-nya tetap dicatat lewat bon
kas kecil kategori **BBM & Tol** (§10.6); mengisi register ini TIDAK menggantikan
bonnya, dan bonnya tidak menggantikan register.

Kolom: Tanggal · Aset (dengan kode mobilisasinya) · Hour meter (jam) · BBM (liter) ·
Dicatat oleh · Catatan. Saringan: Mobilisasi, Proyek, dan rentang tanggal.

**Siapa boleh apa.** Mencatat butuh **izin ubah proyek** — site manager dan manajer
proyek, orang yang memang di lokasi. Membaca butuh izin lihat aset ATAU lihat
proyek. Teknisi servis tidak menulis register ini. Kolom "Dicatat oleh" diisi dari
akun yang sedang masuk, bukan dari formulir.

**Mencatat satu baris:**

1. **`Tambah Log Alat`**.
2. **Mobilisasi** (wajib) — pemilihnya menampilkan kode DEP beserta kode dan nama
   alatnya; mobilisasi yang sudah dikembalikan diberi tanda
   "(demobilisasi {tanggal})".
3. **Tanggal** (wajib, bawaan hari ini) · **Hour meter (jam)** · **BBM diisi
   (liter)** · Catatan. Bantuan di formulir: *"Hour meter adalah ANGKA YANG TERBACA
   di meter, bukan selisih. Isi minimal salah satu: hour meter atau liter BBM."*
4. **`Simpan`**.

Dua baris pada hari yang sama sah — pengisian pagi dan pengisian sore adalah dua
fakta. Log susulan yang tanggalnya masih di dalam rentang mobilisasi juga diterima,
termasuk pada mobilisasi yang sudah dikembalikan.

**Penolakan yang akan Anda temui** — semuanya menyebut angka dan tanggalnya:

- Formulir kosong dua-duanya: *"Isi hour meter atau liter BBM — baris log tanpa
  satu pun pembacaan tidak mencatat apa-apa."*
- Tanggal besok: *"Tanggal log {tanggal} masih di masa depan — register mencatat
  pembacaan yang sudah terjadi, bukan rencana."*
- Sebelum alat tiba: *"Mobilisasi {kode} baru mulai {tanggal}; log bertanggal
  {tanggal} mencatat alat yang belum ada di lokasi."* — dan sesudah alat pulang:
  *"Mobilisasi {kode} sudah demobilisasi {tanggal}; log bertanggal {tanggal}
  mencatat alat yang sudah tidak ada di lokasi."*
- Hour meter mundur: *"Hour meter {angka} lebih rendah dari pembacaan terakhir
  {angka} ({tanggal}) pada mobilisasi {kode}. Meter hanya berjalan maju — angka
  yang lebih rendah berarti salah ketik atau salah alat."* Log susulan yang
  angkanya melompati pembacaan sesudahnya ditolak dari arah sebaliknya: *"… Meter
  hanya berjalan maju — log susulan harus lebih kecil dari pembacaan sesudahnya."*

**Tidak ada tombol Ubah dan tidak ada tombol Hapus, untuk siapa pun** — register
ini hanya-tambah. Salah ketik dikoreksi dengan baris log baru berangka benar; API
pun menolak dengan kalimat: *"Baris register tidak diubah dan tidak dihapus —
register pembacaan dikoreksi oleh pembacaan berikutnya, bukan dengan menyunting
riwayat. Catat baris log baru dengan angka yang benar dan sebutkan koreksinya di
catatan."*

Riwayat pembacaannya juga tampil di **halaman aset** (kartu "Log BBM & jam alat" —
§9.3), di **halaman mobilisasi** (tabel dengan judul yang sama), dan tercetak pada
**Kartu Aset** sebagai tabel LOG BBM & JAM ALAT (§9.10).

### 9.6 Perawatan Aset — `Aset › Perawatan`

Kolom: Kode · Aset · Tanggal · Jenis · Biaya · **Berikutnya** (dengan hitungan relatif).
Saringan: Aset, Jenis.

Formulir: **Aset** (wajib) · **Tanggal** (wajib) · **Jenis perawatan** (wajib: Service
Rutin / Perbaikan / Kalibrasi) · Vendor · **Biaya** (wajib) · **Jadwal berikutnya** ·
Uraian pekerjaan.

> **Kolom Biaya di sini adalah CATATAN, bukan pembukuan.** Layar Perawatan tidak membuat
> jurnal apa pun dan tidak membebani biaya proyek. Biaya perawatan yang benar-benar
> dibayar tetap harus masuk lewat **Tagihan Vendor (AP)** atau **bon kas kecil** seperti
> pengeluaran lain. Angka di sini untuk riwayat alat, bukan untuk buku besar.

**`Jadwal berikutnya` adalah pengingat yang benar-benar berbunyi.** Tenggat mengawasinya
**14 hari** di muka untuk pemegang izin ubah aset, dan hanya baris perawatan **terbaru**
per aset yang membawa pengingat hidup — jadi mencatat perawatan baru otomatis
menggulirkannya.

### 9.7 Penyusutan — `Aset › Penyusutan`

Kolom: Kode · Periode · Jml aset · Total penyusutan · Diposting · Status (Draf /
Terposting). Baris **tidak bisa diubah**; draf bisa dihapus.

1. **`Tambah Run Penyusutan`**. Bantuan di layar: *"Periode harus lebih baru dari periode
   terakhir yang diposting."* Isi **Tahun** dan **Bulan**. **`Simpan`**.
2. Periksa tabel **Rincian penyusutan**: Kode · Aset · **Beban bulan ini** · **Nilai buku
   setelah**, dengan Total.
3. Tekan **`Posting Penyusutan`** (izin posting) — konfirmasi *"Posting run penyusutan
   ini? Akumulasi penyusutan dan nilai buku aset akan diperbarui."*

Penolakan:

- `"Depreciation for period {periode} is already posted."`
- `"Cannot run period 2026-05 at or before the last posted period 2026-06."`
- `"Depreciation run {periode} is {status}; only draft runs can be posted."`
- `"Depreciation run {periode} has no entries to post."`
- Kategori tanpa akun (§9.2).

**Penyusutan bulanan adalah langkah tutup buku yang dimiliki keuangan**, dan ia harus
diposting **sebelum** pengakuan pendapatan PSAK 115 bulan itu (§10.9). Urutannya:
payroll → penyusutan → pengakuan pendapatan.

### 9.8 Utilisasi Aset — `Aset › Utilisasi Aset`

Layar baca saja, sub-judul *"Hari mobilisasi dan nilai internal per proyek."* Tabel
ringkas per aset dan per proyek; satu-satunya tombol adalah muat ulang. Bila kosong:
*"Belum ada data mobilisasi aset."*

### 9.9 Hapus Buku / Jual

Tombol merah **`Hapus Buku / Jual`** di halaman aset (izin posting), muncul pada aset
berstatus **Tersedia** atau **Dalam Perawatan**.

Isian: **Tanggal pelepasan** (wajib) · **Nilai pelepasan (hasil penjualan)** (wajib;
*"Isi 0 untuk scrap/hilang tanpa hasil penjualan."*) · **Alasan (dijual / hilang / rusak
total)** (wajib).

Ia memposting **jurnal pelepasan** — harga perolehan dan akumulasi keluar dari neraca,
laba atau rugi diakui — lalu menandai asetnya **Dihapusbukukan**. **Ini satu-satunya jalan
ke status itu, dan ia tidak punya tombol pembatalan.**

Penolakan:

- `"Aset {kode} sudah dihapusbukukan pada {tanggal}."`
- `"Aset {kode} sedang termobilisasi; kembalikan dari proyek sebelum dihapusbukukan."`
- > "Run penyusutan {kode} ({periode}) masih draf dan memuat entri aset {kode}; posting
  > atau hapus run itu lebih dulu supaya beban bulan berjalan tidak menimpa aset yang
  > sudah dilepas."
- Kategori tanpa akun harga perolehan/akumulasi (§9.2).

### 9.10 Mencetak

| Formulir | Tombolnya di | Kode |
|---|---|---|
| **Kartu Aset** | halaman aset | Form F/KA |
| **Berita Acara Mobilisasi Alat** | halaman mobilisasi | Form F/BAM |

Kartu Aset mencetak tiga tabel riwayat: mobilisasi, perawatan, dan — sejak
22 Agustus 2026 — **LOG BBM & JAM ALAT** (§9.5), tanpa kolom rupiah, karena uang
BBM-nya hidup di kas kecil. Hari tanpa pembacaan meter atau tanpa pengisian dicetak
bergaris kosong, bukan nol — aturan kejujuran §13.5.

---

## 10. Keuangan harian

Bab ini memuat pekerjaan keuangan yang **bukan** penagihan pelanggan (bab 3) dan
**bukan** tagihan/pembayaran vendor (bab 5). Silakan lompat ke sana bila itu yang Anda
cari.

### 10.1 Siapa boleh apa

| Peran | Isi/siapkan | Setujui | Posting |
|---|---|---|---|
| **finance** | ✓ semua dokumen keuangan | — | ✓ |
| **finance-manager** | — (tidak bisa membuat apa pun) | ✓ | — |
| **direktur** | — | ✓ | — |
| admin | ✓ | ✓ | ✓ |

Itu **pemisahan tugas yang disengaja**: petugas keuangan menyiapkan dan membayar, ia tidak
menyetujui. Ia memegang izin posting supaya pengeluaran hanya bisa diposting atas
pembayaran yang **sudah disetujui orang lain** — model dua tanda tangan yang biasa.

Peran `finance` juga memegang izin lihat pada Penjualan, Pengadaan, Subkontrak, dan SDM
(konteks yang ia perlukan untuk menilai sebuah dokumen), plus izin lihat dan posting pada
Aset (§9.1).

**Tutup buku bulanan, periode fiskal, laporan keuangan, dan daftar dokumen menggantung
BUKAN isi panduan ini** — semuanya ada di `docs/PANDUAN-ADMINISTRATOR.md` §6 dan §7.

### 10.2 Jurnal — `Keuangan › Jurnal`

Kolom: Kode · Tanggal · Keterangan · Referensi · Status (Draf / Terposting). Saringan:
Status, Dari, Sampai.

Teks bantuan di formulir: *"Minimal dua baris; total debit harus sama dengan total
kredit."*

1. **`Tambah Jurnal`**.
2. Isi **Tanggal jurnal** (wajib, bawaan hari ini) dan **Keterangan** (wajib, maksimal 500
   karakter). *Tipe referensi* dan *ID referensi* opsional.
3. Isi **Baris jurnal** (minimal 2): **Akun** (wajib — pemilihnya **hanya memuat akun yang
   bisa diposting**) · Keterangan · Debit · Kredit · Proyek.
4. Perhatikan kaki tabel: `Debit: … Kredit: … Seimbang ✓` atau `Selisih Rp …`.
5. **`Simpan`**.
6. **Pemegang izin persetujuan keuangan** membuka jurnal itu dan menekan **`Posting
   Jurnal`** — konfirmasi *"Posting jurnal ini ke buku besar? Jurnal terposting tidak
   dapat diubah."*

> **Petugas keuangan tidak bisa memposting jurnalnya sendiri.** Tombol `Posting Jurnal`
> butuh izin **persetujuan** keuangan, yang tidak dipegang peran `finance`. Dan bahkan
> pemegang izin itu tidak bisa memposting jurnal **yang ia buat sendiri** — membuat jurnal
> menuliskan baris pengaju, sehingga pemisahan tugas (§2.5) menolaknya. **Selalu dua
> orang.**

> **Jurnal yang tidak seimbang tetap tersimpan.** Kaki tabel menampilkan `Selisih Rp …`
> merah, tetapi tombol Simpan tetap bekerja. Keseimbangan baru dipaksakan saat posting,
> dan penolakannya **berbahasa Inggris**:
> `Journal JV/2026/08/0009 is not balanced: debit 5000000 vs credit 4500000.`

Penolakan lain saat memposting:

- `"Journal … has no amounts to post."`
- `"A journal line is either debit or credit, not both."`
- `"COA account 1-1100 (Kas) is a group and cannot be posted to."`
- `"Journal … is already posted."`
- Periode fiskal (§6.10) atau: *"Belum ada periode fiskal untuk 2027-01-05. Buat kalender
  fiskal 2027 lebih dulu di Keuangan › Periode Fiskal."*
- Pemisahan tugas (§2.5).

Toleransinya satu sen. **Jurnal yang sudah diposting tidak pernah diubah** — koreksinya
adalah jurnal kedua yang berlawanan.

**Kolom "Tipe referensi" dan "ID referensi" adalah teks dan angka bebas.** Tidak ada yang
memeriksanya, dan isinya muncul di kolom **Dokumen** pada Buku Besar. Jurnal yang diketik
dengan tipe referensi `ap_bill` akan terbaca di buku besar seolah milik sebuah tagihan
vendor. Isi seadanya atau kosongkan.

**Halaman jurnal tidak punya kartu Riwayat Persetujuan.** Siapa yang mengetik dan siapa
yang memposting terbaca di kartu **Informasi** sebagai "Dibuat oleh" / "Diposting oleh".

**Mencetak:** **`Cetak Voucher Jurnal`** (Form F/VJ). Berlampiran: ya.

### 10.3 Buku Besar — `Keuangan › Buku Besar`

Layar baca saja.

1. Ketik kode atau nama akun di kotak **"Ketik kode atau nama akun…"**. Bila kosong:
   *"Pilih akun untuk melihat buku besarnya — ketik kode (mis. 1-1400) atau namanya."*
2. Setel **Dari** dan **Sampai** (bawaan: tanggal 1 bulan berjalan sampai hari ini) dan,
   bila perlu, pemilih proyek.
3. Baca strip angka: **Saldo awal / Mutasi debit / Mutasi kredit / Saldo akhir** (dengan
   "saldo normal debit|kredit" di bawahnya).
4. Tabelnya: Tanggal · Jurnal · Keterangan · Dokumen · Proyek · Debit · Kredit · Saldo,
   dengan baris "Saldo awal"/"Saldo pindahan" di atas. 100 baris per halaman.
   **Klik baris mana pun untuk membuka jurnalnya.**
5. **`Unduh CSV`** mengekspor seluruh rentang beserta baris Saldo awal dan Saldo akhir.

Kartu "Cara membaca layar ini" menyatakan empat aturannya: hanya jurnal **terposting**
yang muncul; saldo akhir sama dengan akun yang sama di Neraca Saldo bulan itu; saldo
berjalan mengikuti sisi normal akun (positif = normal, negatif = layak diselidiki); dan
saringan proyek **mengubah makna angkanya**.

Dua peringatan yang bisa muncul:

- Akun yang ditandai tidak-bisa-diposting tetapi masih punya baris: *"…neraca saldo,
  neraca, dan laba rugi hanya menghitung akun yang dapat diposting…"*
- Saringan proyek aktif: *"…saldo awal dan saldo akhir di bawah hanya mencakup baris
  proyek tersebut, sehingga tidak sama dengan angka akun ini di neraca saldo perusahaan."*

Pemilih akunnya mencakup **seluruh** bagan akun, termasuk akun kelompok.

### 10.4 Rekonsiliasi Bank — `Keuangan › Rekonsiliasi Bank`

**Layar ini tidak pernah membuat jurnal.** Deskripsinya mengatakan itu sendiri.

Empat tab: **Ringkasan Semua Rekening** · **Rekonsiliasi** · **Rekening Koran** ·
**Impor**. Bilah alat: pemilih rekening + **Sampai tanggal** + **`Muat ulang`**.

**a. Tab Impor** — tiga kartu.

*1 · Berkas*: **Rekening bank**, **Format** (CSV rekening koran / MT940 (SWIFT)),
**Berkas**. Berkasnya dibaca di peramban dan dikirim sebagai teks — *"tidak ada berkas
yang disimpan di server"*.

*2 · Pemetaan kolom* (khusus CSV), dengan pemberitahuan tetap **"Kolom tidak ditebak…"**:
Pemisah kolom · Format angka (Indonesia `1.234.567,89` / Inggris) · Baris judul yang
dilewati · **Kolom tanggal** · **Format tanggal** · **Kolom keterangan** · **Mode nilai**
(dua kolom debit&kredit / satu kolom bertanda / satu kolom + penanda D/K) · kolom
nilainya · **Kolom saldo** (*"Sangat dianjurkan…"*) · Kolom referensi · Periode
mulai/selesai · Saldo awal · Saldo akhir.

> **Kolom tidak pernah ditebak, dan itu disengaja:** tebakan yang benar sembilan kali lalu
> salah sekali menghasilkan berkas yang terimpor rapi dan salah. **Selalu isi Kolom
> saldo** — ia satu-satunya pemeriksa yang tidak bergantung pada angka yang Anda ketik
> sendiri.

*3 · Pratinjau* (setelah **`Pratinjau`**): lencana Seimbang/Tidak seimbang, empat kotak
angka, **penghalang** merah, **peringatan** kuning, 200 baris pertama, dan tombol
**`Impor rekening koran`** yang mati selama masih ada penghalang.

Penghalang yang akan Anda temui:

- > "Berkas tidak seimbang: saldo awal … ditambah mutasi … tidak sama dengan saldo akhir …
  > (selisih …). Ada baris yang belum terbaca — jangan diimpor sebelum selisihnya nol."
- mata uang selain rupiah;
- berkas yang sudah pernah diimpor: *"Berkas ini sudah diimpor sebagai BS/…"*;
- nomor rekening pada berkas MT940 tidak cocok dengan rekening yang dipilih;
- periode yang bertumpuk dengan rekening koran yang sudah ada;
- saldo awal/akhir tidak menyambung dengan rekening koran tetangganya:
  *"Ada periode yang belum diimpor di antaranya."*

**b. Tab Rekening Koran.** Pemilih rekening koran berbunyi `KODE · periode · X/Y cocok`,
tombol **`Hapus`**, dan tabel barisnya. Per baris:

| Tombol | Yang dilakukan |
|---|---|
| **`Cocokkan`** (berlabel `<KODE> · <tanggal>`) | mencocokkan ke usulan terbaik; tooltipnya menyebut skor dan beda hari |
| **`Cari padanan`** / **`Pilihan lain (N)`** | membuka daftar kandidat berlencana Tinggi/Sedang/Rendah |
| **`Tanpa padanan`** | memilih Alasan (Biaya/admin bank · Bunga/jasa giro · Penerimaan belum dicatat · Pengeluaran belum dicatat · Kesalahan bank (menunggu koreksi) · Lainnya) + Catatan |
| **`Batalkan`** | membuka kembali baris yang sudah dicocokkan |

Lencana status baris: **Cocok** / **Tanpa padanan** / **Belum ditinjau**.

Penolakan pencocokan:

- *"Baris ini sudah dicocokkan dengan PAY/…"*
- *"Pembayaran PAY/… belum diposting, jadi belum ada di buku besar."*
- *"… memakai rekening bank yang berbeda."*
- *"Baris ini debit, sedangkan PAY/… adalah penerimaan."*
- > "Nilai tidak sama: baris rekening koran Rp 5.000.000, PAY/… Rp 4.999.000. Pencocokan
  > sebagian belum didukung."
- *"Baris jurnal ini milik pembayaran JV/…. Cocokkan ke pembayarannya, bukan ke
  jurnalnya…"*
- *"Dokumen itu baru saja dicocokkan dengan baris rekening koran lain. Muat ulang halaman
  ini."*
- *"Batalkan pencocokan lebih dulu sebelum menandai baris ini tanpa padanan."*

> **Pencocokan sebagian tidak ada.** Satu mutasi bank harus sama persis dengan satu
> dokumen sampai sennya. Satu transfer yang melunasi tiga tagihan tidak punya padanan; ia
> hanya bisa ditandai **Tanpa padanan**, atau dokumennya yang dipecah.

> **"Tanpa padanan" hanya mencatat bahwa baris itu sudah ditinjau dan mengapa — ia tidak
> membukukan apa pun.** Barisnya tetap dihitung sebagai selisih. Untuk benar-benar
> membukukan biaya admin bank atau jasa giro: buat jurnalnya di `Keuangan › Jurnal`, lalu
> kembali ke sini dan cocokkan barisnya ke jurnal itu.

Menghapus rekening koran ditolak bila ia bukan yang terbaru (*"…bukan yang terbaru untuk
rekening ini — BS/… menyusul setelahnya. Menghapusnya akan memutus rantai saldo. Hapus
yang terbaru lebih dulu."*) atau bila sudah ada baris yang dicocokkan (*"…sudah memiliki N
baris yang dicocokkan. Batalkan pencocokan itu lebih dulu…"*).

**Menghapus rekening koran adalah obat untuk pemetaan kolom yang salah** — jadi periksa
pratinjaunya sebelum mengimpor, karena setelah beberapa pencocokan, kesalahan itu mahal.

**c. Tab Rekonsiliasi.** Empat kotak: Saldo rekening koran · Saldo buku besar · Pos
terbuka · **Selisih belum dijelaskan**. Lalu tabel **Jembatan rekonsiliasi**:

> Saldo akhir rekening koran → (+) selisih saldo awal → (+/−) sudah dibukukan belum tampak
> di bank → (−/+) ada di bank belum dibukukan → (+) selisih belum dijelaskan =
> **Saldo buku besar**

Lencananya: **`Cocok sepenuhnya`** / **`Selisih dijelaskan — N pos terbuka`** /
**`Ada selisih yang belum dijelaskan`**.

> **Jembatan bisa menutup di atas kesalahan yang nyata.** Dua pos terbuka di sisi
> berlawanan dengan nilai hampir sama saling meniadakan, sehingga jembatannya tetap
> tertutup. Itulah gunanya kartu **"Periksa — kemungkinan salah catat"**, yang
> menampilkannya beserta kalimat *"Keduanya saling meniadakan sehingga jembatan tetap
> tertutup — selisihnya tidak akan muncul di baris mana pun."* **Bacalah kartu itu walau
> jembatannya tampak sempurna.**

### 10.5 Kasir Kas Kecil — `Keuangan › Kasir Kas Kecil`

Layar harian pemegang laci.

Bila belum ada dana kas kecil: *"Belum ada dana kas kecil. Daftarkan laci pertama — kode,
akun 1-11xx, pemegang, dan dana tetap (float) — di register Kas Kecil & Kasbon."* dengan
tombol **`Daftarkan dana`**.

Bilah alat: pemilih laci (laci yang Anda pegang tertulis `KODE — Nama (laci Anda)` dan
terpilih otomatis), "Pemegang: {nama}", **`Muat ulang`**. Dua tab: **Bon di Laci** dan
**Kasbon**.

**Lima petak posisi**, dan mereka adalah satu persamaan:

> **Dana tetap (float)** − **Bon belum diganti** − **Kasbon berjalan** − **Belanja kasbon
> belum diganti** = **Seharusnya di laci**

Sub-barisnya masing-masing menjelaskan: "plafon per bon Rp …", "terposting, menunggu isi
ulang terposting", "di kantong karyawan", "bukti pertanggungjawaban, menunggu isi ulang",
dan "laci penuh — hitung fisik tiap tutup hari".

Bila persamaan itu tidak menutup, muncul peringatan kuning:

> "Identitas imprest tidak menutup: float − bon − kasbon berjalan − belanja kasbon = Rp X,
> tetapi saldo laci di GL Rp Y. Biasanya pendanaan awal di bawah float atau isi ulang yang
> belum diposting penuh — cocokkan dengan uang fisik sebelum tutup hari."

**Kartu `Catat bon`** (hanya untuk pemegang laci itu): **Tanggal bon** · **Kategori** ·
**Keterangan** (placeholder: *"mis. 'BBM + tol survei site' — tulis seperti di bonnya"*) ·
**Jumlah** · Proyek (*"Kosongkan untuk beban kantor."*) · ID tugas WBS. Dua tombol:
**`Simpan Draf`** dan **`Catat & Posting`**. Kaki kartunya: *"Uangnya sudah keluar dari
laci — ini pencatatannya."*

Orang selain pemegang laci melihat: *"Entri cepat hanya untuk pemegang laci ini ({nama})
— server menolak posting dari orang lain, apa pun perannya. Bon tetap bisa didraf lewat
register Voucher Kas Kecil."*

Bila `Catat & Posting` gagal di langkah postingnya, **draf-nya tetap selamat**:
*"{pesan server} — PCV/… tersimpan sebagai draf di tabel bawah."*

Tabel **Bon di laci** (draf dulu, lalu yang terposting menunggu penggantian): Kode ·
Tanggal · Kategori · Keterangan · Proyek · Jumlah · Status (+ "menunggu PAY/… diposting"),
dengan tombol **`Posting`** per baris untuk pemegang laci. Kaki: "Menunggu penggantian"
beserta totalnya.

Kartu **"Cara kerjanya"** memuat satu kalimat yang harus Anda ingat tiap akhir bulan:

> "Bon dan kasbon yang masih draf menahan tutup periode fiskal pada tanggalnya (pemeriksaan
> dokumen menggantung) — posting atau hapus sebelum akhir bulan."

### 10.6 Voucher Kas Kecil

Register bonnya. **Tidak ada di sidebar** — pintunya tombol **`Semua voucher`** di layar
Kasir Kas Kecil, atau klik kode bon.

Kolom: Kode · Tanggal · Kas kecil · Kategori · Keterangan · Proyek · Jumlah · Status
(Draf / Terposting / Dibatalkan). Bantuan di formulir: *"Uangnya sudah keluar dari laci —
voucher ini pencatatannya. Bon berproyek langsung menjadi biaya proyek (HPP 5-xxxx) hari
itu juga."*

Kolom: **Kas kecil** (wajib, hanya saat membuat) · **Tanggal bon** (wajib) · **Kategori**
(wajib) · **Jumlah** (wajib) · **Keterangan** (wajib) · Proyek · ID tugas WBS.

Enam kategori, dan masing-masing mendarat di akun yang berbeda tergantung ada tidaknya
proyek: **Material · Upah Harian · BBM & Tol · Konsumsi · Alat Bantu · Lain-lain**.

Kategori **BBM & Tol** adalah tempat UANG solar dicatat; jam mesin dan liter yang
diisikan ke alat berat dicatat terpisah di register **Log BBM & Jam Alat** (§9.5),
yang tidak membukukan apa pun — keduanya saling melengkapi, bukan saling
menggantikan.

**Aksi:**

- **`Posting Voucher`** — konfirmasi *"Posting bon ini? Beban dan saldo laci langsung
  terbukukan — hanya pemegang dananya yang diterima server."*
- **`Batalkan Voucher`** (izin posting; *Alasan pembatalan* wajib) — bantuan: *"Bon yang
  sudah diganti isi ulang terposting tidak dapat dibatalkan — koreksinya lewat JV."*

Penolakan saat memposting:

- **Bukan pemegang laci** — dan **tidak ada jalan pintas admin**:
  > "Hanya pemegang kas kecil KK-HO yang dapat memposting voucher — uang tunainya ada di
  > laci pemegang, bukan di layar orang lain. Bila pemegangnya berganti, ubah dulu pemegang
  > pada data kas kecilnya."
- Melewati plafon per bon:
  > "Voucher PCV/… sebesar X melebihi batas per bon Y pada kas kecil KK-HO. Belanja
  > sebesar ini ditagihkan lewat tagihan vendor (AP) dengan persetujuan, bukan lewat laci."
- Melewati saldo laci **pada tanggal bon itu**:
  *"…melebihi saldo laci KK-HO per 2026-05-20 (Z). Isi ulang dananya lebih dulu."*
- Periode fiskal tertutup.

Penolakan pembatalan:
> "Voucher PCV/… sudah diganti oleh isi ulang PAY/… yang terposting; pembatalan akan
> membuat uang penggantian tidak berdasar. Koreksinya dibukukan lewat jurnal penyesuaian
> (JV) oleh keuangan."

> **`Proyek` yang dikosongkan mengirim rupiah yang sama ke overhead kantor, bukan ke biaya
> proyek.** Kolomnya terlihat opsional; ia menentukan baris laba-rugi mana dan proyek mana
> yang dibebani. Dan bon berproyek yang diposting **langsung menggerakkan persentase
> penyelesaian PSAK 115 proyek itu hari itu juga**.

**"Bon belum diganti" berarti belum DIGANTI UANGNYA, bukan belum dicap.** Bon dicap begitu
sebuah isi ulang diajukan, tetapi ia tetap dihitung mengurangi laci sampai pembayaran
penggantinya **diposting**. Tabel voucher menunjukkan "diajukan" selama jendela itu, dan
tanda ✓ hanya setelahnya.

### 10.7 Kasbon

Juga **tidak ada di sidebar** — pintunya tombol **`Semua kasbon`** di layar Kasir Kas
Kecil, atau tab **Kasbon** di sana.

Kolom: Kode · Tanggal · Kas kecil · Karyawan · Keperluan · Jatuh tempo · Jumlah · Status
(Draf / Berjalan / Selesai).

Bantuan di formulir, dan ia adalah aturannya:

> "Pencairan membukukan piutang karyawan (1-1370), BUKAN biaya — biaya diakui saat
> pertanggungjawaban dengan bukti belanja. Satu kasbon berjalan per karyawan per laci."

Kolom: **Kas kecil** (wajib, hanya saat membuat) · **Karyawan** (wajib) · **Tanggal
pencairan** (wajib) · **Jumlah** (wajib) · **Keperluan** (wajib) · Proyek (*"Kosongkan
untuk memakai proyek si dana."*) · Batas pertanggungjawaban.

**Mencairkan.** Tombol **`Cairkan Kasbon`** di halaman kasbon (hanya pemegang laci),
konfirmasi: *"Rp X keluar dari laci {nama} ke {karyawan}. Piutang karyawan (1-1370)
langsung terbukukan — biaya baru diakui saat pertanggungjawaban."*

Penolakan: bukan pemegang laci (kalimat yang sama dengan §10.6) · dana nonaktif ·
melewati plafon kasbon · melewati saldo laci pada tanggalnya · dan:
> "Karyawan ini masih membawa kasbon KSB/… sebesar X yang belum dipertanggungjawabkan pada
> kas kecil KK-HO; selesaikan itu lebih dulu."

**Mempertanggungjawabkan.** Setelah cair, pemegang laci mendapat kartu
**"Pertanggungjawaban"**: kotak tanggal, tombol **`Tambah baris`**, tombol
**`Bukukan Pertanggungjawaban`**, dan editor baris (Kategori · Keterangan · Proyek
[bawaan "— kantor —"] · WBS · Jumlah). Kaki kartu menghitung hidup:
`Belanja: … Kasbon: … Sisa kembali ke laci Rp …` atau `Kekurangan dibayar laci Rp …`.

Orang selain pemegang laci hanya melihat: *"Menunggu pertanggungjawaban. Hanya pemegang
dana KK-HO yang dapat membukukannya — bukti belanja dan sisa uangnya ada di laci
pemegang."*

**Satu kasbon hanya bisa dipertanggungjawabkan sekali.** Kasbon yang uangnya sama sekali
tidak terpakai diselesaikan dengan nol baris.

Penolakan: *"…hanya kasbon yang sudah cair yang dapat dipertanggungjawabkan."* ·
*"Nilai setiap baris pertanggungjawaban harus lebih besar dari nol."* · *"Setiap baris
pertanggungjawaban harus menyebut belanjanya."* · dan bila kelebihan belanjanya melebihi
isi laci:
> "Belanja pertanggungjawaban … melebihi kasbon KSB/… dan kelebihannya … melebihi saldo
> laci KK-HO per {tanggal} (…). Isi ulang dananya lebih dulu."

> **Kasbon yang dicairkan BUKAN pengeluaran.** Ia piutang karyawan. Biaya baru diakui baris
> demi baris saat pertanggungjawaban. Manajer lokasi yang membaca posisi laci sebagai "kami
> sudah membelanjakan itu" salah membacanya: uangnya ada di kantong seseorang, dan
> perusahaan masih memilikinya.

### 10.8 Mengisi ulang dan menyetor kas kecil

**Isi ulang.** Di layar Kasir Kas Kecil, kartu **Isi ulang** muncul bila penggantian sudah
perlu. Tombol **`Minta Isi Ulang`** membuka dialog dengan **satu** isian — "Dari rekening
bank" — dan bantuan:

> "Draf PAY sebesar Rp … (float Rp … − saldo laci Rp …) — jumlahnya dihitung server, bukan
> diketik."

Tombol kirim **`Buat Draf Pembayaran`**. Notifikasi: *"Draf isi ulang dibuat — ajukan untuk
persetujuan dari layar pembayaran."*, lalu layar berpindah ke pembayarannya.

Di layar Pembayaran, pembayaran yang bercap kas kecil **mengganti editor alokasi biasa**
dengan dua kartu: kartu posisi laci, dan tabel **"Bon & kasbon yang akan diganti"**
(*"periksa bukti fisiknya sebelum menyetujui — inilah yang dibayar uang bank ini"*).
Setelah itu, pembayaran berjalan seperti pengeluaran biasa: **`Ajukan Isi Ulang`** →
`Setujui` → `Posting Pembayaran` (§5.10).

> **Jumlah isi ulang tidak pernah diketik.** Ia float − saldo laci − kasbon beredar,
> dihitung server dan diperiksa ulang saat pengajuan dan saat posting. Bila isi laci
> bergerak antara membuat draf dan mengajukannya, tombolnya **mati** dan layar menyebut
> angka barunya:
>
> "Saldo laci berubah sejak draf dibuat: isi ulang sekarang seharusnya Rp X, bukan Rp Y.
> Ubah jumlah pembayarannya dulu."
>
> Perbaikannya: ubah jumlah pembayarannya, bukan berdebat dengan angkanya. Penolakan
> server: *"Isi ulang kas kecil KK-HO harus tepat sebesar float dikurangi saldo laci dan
> kasbon beredar: 5000000 − 3800000 − 0 = 1200000, bukan 1500000."*

**Setor ke bank.** Di halaman dana kas kecil, tombol **`Setor ke Bank`** (muncul bila
saldo laci > 0): **Ke rekening bank** (wajib) dan **Jumlah** (wajib; *"Maksimal saldo laci
Rp … — untuk mengecilkan atau menutup dana."*), tombol **`Buat Draf Penerimaan`**.
Penolakan: *"Setoran kas kecil KK-HO ke bank sebesar X melebihi saldo laci (Y)."*

> **Transfer kas kecil tidak bisa dibalikkan.** Tombol `Balikkan Pembayaran` sengaja tidak
> digambar pada pembayaran isi ulang maupun setoran — koreksinya adalah transfer
> berlawanan arah.

**Register dana kas kecil** ada di `Keuangan › Kas Kecil & Kasbon` (kolom Kode · Nama ·
Pemegang · Float · Saldo laci · Perlu diisi · Aktif). Membuat sebuah laci — kode, akun
1-11xx, pemegang, float, dan plafon per bon — adalah pekerjaan penyiapan; aturan akunnya
dan penolakannya ada di sana, dan akun 1-11xx yang diperlukan dibuat di Bagan Akun.

### 10.9 Pengakuan Pendapatan (PSAK 115) — `Keuangan › Pengakuan Pendapatan`

**`Tambah Run PSAK 115`** hanya meminta **Tahun** dan **Bulan** (*"Persentase penyelesaian
dihitung per akhir bulan ini. Kebijakan lengkap: docs/KEBIJAKAN-PENDAPATAN.md."*). Run
**tidak pernah bisa diubah**; run yang masih **Draf** bisa dihapus (*"Hapus run draf ini?
Perhitungan dapat dibuat ulang kapan saja."*).

Tombol di halaman run: ikon printer · **`WIP Schedule (CSV)`** · **`Hitung Ulang`** (pada
draf) · **`Posting Jurnal`** (izin posting, pada draf) dengan konfirmasi:

> "Angka dihitung ulang dari basis data saat posting (draf hanyalah pratinjau), lalu satu
> jurnal penyesuaian ditulis pada tanggal akhir periode dan run terkunci."

Tabel **Perhitungan per kontrak**: Kontrak · Harga transaksi · **EAC** (berlencana
`RAP belum disetujui` kuning / `EAC manajemen` biru / `Tanpa estimasi — margin nol` merah)
· Biaya s.d. kini · % · Pendapatan kumulatif · Tertagih · **Aset/(liab.) kontrak** ·
Penyesuaian, dan tombol **`Ubah EAC`** per baris pada draf (isian *Estimasi total biaya
penyelesaian*, bantuan *"Telaah manajemen atas biaya sampai proyek selesai. Minimal sebesar
biaya terjadi."*, tombol **`Simpan & hitung ulang`**).

Kontrak yang merugi memunculkan peringatan kuning **"Kontrak merugi (PSAK 237)"** beserta
provisinya. Kartu **Cara membaca** menuliskan ketiga rumusnya.

> **Urutannya mengikat.** Posting run ditolak selama payroll dan penyusutan bulan itu
> belum diposting:
>
> "Payroll untuk periode 2026-06 belum diposting. Biaya bulan ini belum lengkap, sehingga
> persentase penyelesaian akan understated — posting payroll dan penyusutan lebih dulu."
>
> **payroll (§11.6) → penyusutan (§9.7) → pengakuan pendapatan.**

> **Draf yang tampil di layar bukan yang diposting.** Posting menghitung ulang seluruh
> periode dari basis data, hanya mempertahankan EAC yang di-override manual. Angkanya boleh
> bergerak antara pratinjau dan run terposting.

Penolakan lain: *"Periode 2026-9 belum berakhir — pengakuan dihitung setelah periode
selesai."* · *"Periode 2026-06 sudah diposting (REV/…). Pembatalan pengakuan adalah
penyajian kembali — bukan hitung ulang."* · *"Run yang sudah diposting tidak dapat dihapus
— jurnalnya ada di buku besar."* · *"EAC harus lebih besar dari nol."*

### 10.10 Kalender Pajak — `Keuangan › Kalender Pajak`

Kendalinya: kotak tahun · **`Lengkapi kalender`** · **`Cetak Register`** (Form F/KP,
mendatar — mati dengan tooltip *"Belum ada baris masa {tahun} untuk dicetak"* sampai tahun
itu punya baris).

Tiga kotak: **Lewat tenggat setor** · **Belum disetor** · **Sudah dilapor**.

Tabel: Kewajiban · **Jatuh tempo setor** (+ "N hari lagi"/"N hari lalu"/"hari ini") ·
Jumlah · NTPN · Disetor · Dilapor · JV · Status · tombol **`Catat`** per baris.

**`Lengkapi kalender`** menerbitkan empat jenis kewajiban × 12 masa = 48 baris setahun:
**PPh 21, PPh 23, PPh Final 4(2), PPN**. Tenggatnya: **PPh tanggal 10 bulan berikutnya;
PPN akhir bulan berikutnya**.

**Ia tidak pernah menimpa apa pun.** Ia hanya menerbitkan baris yang belum ada; NTPN dan
tanggal yang sudah Anda ketik dibiarkan. Menekannya dua kali aman: tidak ada baris baru
yang terbit, dan notifikasinya tetap `Kalender dicetak.` — layar memakai satu kalimat
sukses yang sama berapa pun baris yang lahir, jadi jangan membaca jumlah dari
notifikasi; baca tabelnya.

**Mencatat setoran dan pelaporan.** Tombol **`Catat {nama}`** membuka dialog berisi:
**Jumlah disetor (SSP)** (*"Nihil? Biarkan kosong/nol — masa nihil boleh langsung dicatat
dilapor."*) · **NTPN** (*"Wajib saat mencatat tanggal setor — nomor bukti dari SSP/BPN."*)
· **Tanggal disetor** · **Tanggal dilapor** · **JV penyetoran (opsional)** · Catatan.
Tombol **`Batal`** / **`Simpan`**.

Penolakan:

- > "Setoran PPh 21 masa Jun 2026 harus mencantumkan NTPN dari SSP/BPN-nya; tanpa NTPN
  > pembayarannya tidak dapat diverifikasi."
- > "… bernilai lebih dari nol dan belum disetor; catat setorannya (dengan NTPN) sebelum
  > mencatat pelaporannya. Masa nihil boleh langsung dilapor."

Statusnya **diturunkan dari tanggal**: tanpa tanggal = Belum disetor (atau Lewat tenggat
bila sudah lewat), tanggal setor saja = Disetor, tanggal lapor = Dilapor.

> **Kolom "JV penyetoran (opsional)" tidak bisa dipakai.** Pemilihnya tidak pernah berhasil
> memuat daftarnya dan selalu berakhir dengan "Gagal memuat" tanpa satu pun pilihan.
> Tautan yang sudah ada tetap tersimpan, tetapi tautan baru tidak bisa dibuat dari layar
> ini. **Tuliskan nomor JV-nya di kolom Catatan** — atau di Keterangan jurnalnya.

**Tidak ada integrasi e-filing / DJP di mana pun.** Status setor dan lapor diketik tangan
dari SSP/BPN.

### 10.11 Ekualisasi Pajak — `Keuangan › Ekualisasi Pajak`

Kertas kerja yang menjawab satu pertanyaan pemeriksa pajak: **mengapa angka buku dan
angka SPT/faktur berbeda, dan berapa selisih yang belum terjelaskan?** Layarnya milik
setiap pemegang izin lihat keuangan (`fin.view`), tepat di bawah Kalender Pajak di
sidebar.

Kendalinya satu kotak **tahun** — bawaannya tahun dari bulan yang baru saja berakhir,
jadi pada Januari yang tampil adalah tahun lalu — dan tombol **`Cetak Ekualisasi`**.
Seluruh angka dihitung server; layar tidak menghitung apa pun sendiri.

Empat lembar kerja, masing-masing bertabel **Uraian · Menurut buku (Rp) · Menurut
SPT/faktur (Rp) · Selisih (Rp)**:

- **Ekualisasi PPN Keluaran** — pendapatan menurut buku (mengikuti kemajuan, PSAK 115)
  dibandingkan faktur pajak keluaran (mengikuti termin); selisihnya dijelaskan baris
  pergerakan saldo kontrak, jadi angka yang berbeda di sini bukan otomatis salah.
- **Ekualisasi PPN Masukan** — PPN masukan menurut tagihan vendor di buku dibandingkan
  faktur pajak masukannya.
- **Ekualisasi PPh 21** — beban gaji dan potongan PPh 21 payroll dibandingkan masa
  yang disetor dan dilapor.
- **Ekualisasi PPh Dipotong** — dua panel: PPh yang perusahaan potong dari vendor dan
  subkontraktor, lalu bukti potong pelanggan atas pendapatan perusahaan.

**Baris residunya adalah intinya.** Tiap lembar memuat satu baris tebal berlabel
*"Selisih belum terjelaskan"* (pada lembar PPh Dipotong: *"Selisih belum terjelaskan
(PPh dipotong perusahaan)"*, di ujung panel pertamanya). Nol tampil hijau sebagai
**"Rp 0 — teruji"**; selain nol tampil merah lengkap dengan tandanya, dan **tidak
pernah dipaksa menjadi nol** — residu yang diam-diam dinolkan adalah kertas kerja
palsu, dan kejujuran itulah nilai layar ini.
Tahun tanpa data tidak menggambar tabel: yang tampil peringatan *"Tidak ada …"* dari
server, supaya tabel kosong tidak terbaca "tidak ada yang perlu direkonsiliasi".

**`Cetak Ekualisasi`** mencetak ekualisasi tahun terpilih ke **Form F/EQ** (mendatar).
Lembarnya menjangkar pada satu baris masa di Kalender Pajak; selama tahun terpilih belum
punya baris masa, tombolnya mati dengan alasannya di tooltip: *"Belum ada baris masa
{tahun} di Kalender Pajak untuk dijadikan jangkar cetak — tekan "Lengkapi kalender" di
layar Kalender Pajak dahulu"*.

### 10.12 Ekspor Pajak — `Keuangan › Ekspor Pajak`

Periode bawaannya bulan yang baru saja lewat. Dua tab: **e-Faktur (PPN Keluaran)** dan
**e-Bupot (PPh Dipotong)**, masing-masing menyebut jumlah yang siap diekspor.

Pemberitahuan tetap di layar:

> "Tata letak kolom mengikuti skema impor e-Faktur/e-Bupot dan dapat berubah mengikuti
> ketentuan DJP. Impor satu periode ke lingkungan uji dan cocokkan totalnya sebelum dipakai
> untuk pelaporan."

Kotak: Siap diekspor · Total DPP · PPN keluaran / PPh dipotong · **Tertahan**. Kartu
**"Isi berkas — {nama file}"** dengan **`Unduh CSV`**, lalu kartu **"Tertahan — tidak masuk
file (N)"** berisi alasan per dokumen.

Penghalang e-Faktur:

- *"Nomor faktur pajak belum diisi — catat nomor seri dari DJP pada invoice ini."* (§3.11)
- *"NPWP pelanggan {nama} belum diisi atau tidak lengkap."*
- *"Invoice tidak memungut PPN, jadi tidak ada faktur pajak keluaran."*

Penghalang e-Bupot:

- *"Jenis PPh pada BIL/… belum ditetapkan — pilih jenis pajaknya pada tagihan."*
- *"…menunjuk baris master pajak yang sudah tidak ada — pulihkan baris pajaknya di Master
  Data › Pajak."*
- *"Kode objek pajak untuk PPH23 belum diisi — lengkapi di Master Data › Pajak."*
- *"NPWP vendor {nama} belum diisi atau tidak lengkap."*
- *"Nomor bukti potong untuk BIL/… belum diterbitkan…"*

**Hanya penghalang terakhir yang punya tombol.** **`Terbitkan nomor bukti potong`** muncul
di tab e-Bupot saja, hanya bila ada penghalang bukti potong, dan **hanya untuk pemegang
izin persetujuan keuangan** — petugas yang menyiapkan ekspornya tidak bisa menekannya.
Konfirmasi: *"Nomor diterbitkan sekali untuk masa ini dan tidak berubah lagi. Tagihan yang
sudah bernomor dilewati."*

### 10.13 Biaya Proyek — `Keuangan › Biaya Proyek`

Layar baca saja: Tanggal · Proyek · Kategori · Keterangan · Sumber · Jumlah, disaring per
Proyek dan Kategori. Tidak ada tambah, ubah, hapus, maupun halaman detail. Kotak carinya
tidak bekerja (§2.1) — pakai saringannya.

Ini tempat memeriksa **dari mana** sebuah biaya proyek berasal ketika angka EVM atau
varian material tidak masuk akal.

### 10.14 Master keuangan

Ketiganya **tanpa halaman detail** — ubah dan hapus dari ikon di baris.

**`Keuangan › Bagan Akun`** (100 baris per halaman). Kolom: Kode (menjorok mengikuti
kodenya) · Nama akun · Tipe · Saldo normal · Dapat diposting · Aktif. Formulir: **Kode
akun** (wajib; *"mis. 1-1210"*) · **Nama akun** (wajib) · **Tipe akun** (wajib) ·
**Saldo normal** (wajib) · Akun induk · **Dapat diposting** (bawaan ya; *"Akun grup tidak
dapat menerima jurnal."*) · Aktif.
Menghapus ditolak: `"Account 1-1400 has journal lines and cannot be deleted."` /
`"… has child accounts and cannot be deleted."`

**`Keuangan › Pajak`.** Kode · Nama pajak · Jenis (PPN / PPh dipotong-dipungut) · Tarif ·
**Kode objek** · Akun COA. Kolom **Kode objek pajak** berbantuan: salin dari daftar kode
objek DJP; berbeda per skema; kosongkan bila tidak lewat e-Bupot — **dan ia yang menahan
ekspor e-Bupot bila kosong** (§10.12).
Menghapus menyebut dokumennya:
> "Pajak PPH23 masih dipakai 4 tagihan (mis. BIL/2026/III/0001) dan tidak dapat dihapus;
> bukti potongnya akan hilang dari file e-Bupot masa terkait."

**`Keuangan › Rekening Bank`.** **Semua kolom wajib kecuali Aktif**: Kode · Nama rekening ·
Bank · Nomor rekening · Atas nama · **Akun COA**. Menghapus ditolak:
`"Bank account BCA-01 has payments and cannot be deleted."`

---

## 11. SDM

Kelompok **SDM & Payroll**: **Karyawan · Sertifikat & PKWT · Cuti & Izin · Absensi
Harian · Rekap Absensi · Payroll**.

### 11.1 Siapa boleh apa

Peran `hr` memegang lihat/buat/ubah/hapus/posting pada modul SDM — **tetapi tidak
memegang izin persetujuan SDM**. Persetujuan cuti dan **persetujuan payroll** ada pada
**direktur dan admin**.

Itu pemisahan tugas yang disengaja dan sebabnya konkret: **menyetujui payroll ADALAH
memposting seluruh run ke buku besar dalam satu transaksi** (§11.6). Peran `finance` juga
memegang izin lihat SDM, untuk konteks.

### 11.2 Karyawan — `SDM & Payroll › Karyawan`

Kolom: Kode · Nama (dengan jabatan) · Departemen · Status kerja · PTKP · Gaji pokok ·
Status. Saringan: Departemen, Status, Status kerja.

Formulir, tiga bagian:

- *Data pribadi*: **Nama lengkap** (wajib) · **NIK KTP** (wajib, **tepat 16 digit dan
  unik**) · NPWP · **Jenis kelamin** (wajib) · **Tanggal lahir** (wajib, harus sebelum
  hari ini) · **Status PTKP** (wajib, TK/0 sampai K/3).
- *Kepegawaian*: **Jabatan** (wajib) · **Departemen** (wajib: Proyek / Engineering /
  Keuangan / HR & GA / Procurement / Servis) · **Status kerja** (wajib: Karyawan Tetap
  (PKWTT) / Karyawan Kontrak (PKWT) / Tenaga Harian Lepas) · **Dasar PKWT** · **Akhir
  PKWT** · **Tanggal masuk** (wajib) · Status (Aktif/Resign) · Tanggal resign.
- *Remunerasi & BPJS*: **Gaji pokok** (wajib) · Tunjangan tetap (peta nama→jumlah) ·
  No. BPJS Kesehatan · No. BPJS Ketenagakerjaan · Bank · No. rekening · Atas nama.

Halaman karyawan memperlihatkan petak Gaji pokok / Tunjangan tetap / PTKP · TER / Masa
kerja, dan kartu **Riwayat slip gaji**, **Saldo Cuti Tahunan**, **Data karyawan**,
**Tunjangan tetap**.

> **Kolom "Dasar PKWT" dan "Akhir PKWT" DITOLAK untuk karyawan non-kontrak.** Keduanya
> ada di setiap formulir Karyawan, tetapi server menolaknya kecuali **Status kerja =
> Karyawan Kontrak (PKWT)** — galatnya muncul tepat pada kedua kolom itu. Mengubah
> karyawan dari kontrak ke tetap **mengosongkan keduanya diam-diam**.
>
> Akhir PKWT juga harus setelah Tanggal masuk dan **paling lama lima tahun sesudahnya**
> (PP 35/2021 Pasal 8). Aturan yang sama diperiksa dari arah sebaliknya bila Anda menggeser
> Tanggal masuk saja.

**Tanggal resign wajib** bila Status = Resign.

> **Menghapus karyawan TIDAK dijaga apa pun.** Berbeda dari akun, pajak, dan rekening
> bank, layar Karyawan tidak memeriksa slip gaji, kasbon, atau tiket sebelum menghapus.
> **Pakai Status = Resign**, jangan hapus.

### 11.3 Sertifikat & PKWT — `SDM & Payroll › Sertifikat & PKWT`

Dua register pada satu layar, keduanya memberi makan pengingat harian pukul 08.30 WIB
dengan tenggat **60 hari**.

Tiga kotak: **Sertifikat lewat / menipis** · **PKWT lewat / menipis** · **PKWT tanpa
tanggal**.

**Tabel Sertifikat keahlian**: Karyawan · Sertifikat (dengan nomor dan penerbit) · Jenis ·
**Kedaluwarsa** (berlencana **Lewat** merah / **Menipis** kuning; "Tidak kedaluwarsa" bila
kosong) · **Sisa** · tombol **`Perpanjang`**, ikon Ubah, ikon Hapus. Klik baris membuka
halaman sertifikat — di sanalah PDF pindaiannya dilampirkan.

Jenis sertifikat: **SKK Konstruksi · Sertifikat K3/AK3 · Sertifikasi Principal ·
Lainnya**.

Bila register kosong, layar menjelaskan sendiri kenapa ia ada:

> "SKK Konstruksi, Sertifikat K3, dan sertifikasi principal dicatat di sini supaya
> kedaluwarsanya diingatkan 60 hari di muka. SKK yang lewat menaikkan PPh final pelaksanaan
> dari 2,65% ke 4,00% — selisih 1,35 poin dari setiap tagihan."

**`Perpanjang {nama}`** membuka dialog satu kolom — **Tanggal kedaluwarsa baru** (wajib) —
tombol **`Simpan perpanjangan`**. Notifikasi: *"Tanggal kedaluwarsa diperbarui —
pengingatnya berhenti."* Penolakan: *"Tanggal kedaluwarsa harus setelah tanggal terbit."*

Menghapus: *"Hapus '{nama}' dari register? Pengingat kedaluwarsanya ikut berhenti;
barisnya disimpan sebagai soft delete untuk jejak audit."*

> **Sertifikat tanpa tanggal kedaluwarsa TIDAK PERNAH diawasi.** Mengosongkan kolom itu
> dibaca sebagai "tidak kedaluwarsa", dan pengingat harian tidak akan pernah menyebutnya.
> Untuk sebuah SKK, itulah selisih 1,35 poin PPh yang sedang menunggu.

**Tabel PKWT karyawan kontrak** (hanya karyawan kontrak yang aktif): Karyawan · Jabatan ·
Masuk · **Akhir PKWT** (berlencana Lewat/Menipis, atau abu **Selesainya pekerjaan**, atau
merah **Tanpa tanggal**) · Sisa · tombol **`Isi tanggal`** / **`Ubah PKWT`**.

Dialognya meminta **Dasar PKWT** (wajib) dan **Tanggal akhir PKWT** — yang **sengaja tidak
diwajibkan**:

> "PKWT selesainya pekerjaan sah tanpa tanggal akhir (PP 35/2021 Pasal 9) — jangan mengarang
> tanggal hanya untuk mendiamkan pengingat."

Kartu **"Cara kerjanya"** menyebut irama pengingatnya: pemindaian 08.30, yang menipis
diingatkan lagi paling sering 7 hari sekali dan yang lewat 3 hari sekali; perpanjangan
berarti mengubah tanggal kedaluwarsa, sedangkan sertifikat yang tidak dipertahankan
dihapus dari register.

### 11.4 Cuti & Izin — `SDM & Payroll › Cuti & Izin`

Kolom: Kode · Karyawan · Jenis · Mulai · Selesai · **Hari** · Status.

1. **`Tambah Pengajuan Cuti`**.
2. Isi **Karyawan** (wajib), **Jenis** (wajib) — bantuan di layar: *"Hanya cuti tahunan
   yang memotong saldo 12 hari (UU 13/2003 Pasal 79); sakit/izin/khusus tercatat tanpa
   memotong."* — **Tanggal mulai**, **Tanggal selesai**, **Alasan / keperluan** (wajib).
3. **`Simpan`**, **`Ajukan`**, lalu direktur **`Setujui`** atau **`Tolak`**.

**Tidak ada kolom "jumlah hari".** Server menghitungnya sendiri dari rentang tanggal.

> **Hari Sabtu ikut memotong cuti.** Minggu kerja bawaan enam hari, jadi **hanya hari
> Minggu yang dilewati**.
>
> **Hari libur nasional TIDAK PERNAH dikecualikan** — sistem tidak punya kalender hari
> libur sama sekali. Cuti yang melintasi Lebaran memotong setiap harinya.

**Saldo cuti tahunan** tidak pernah disimpan; ia dihitung ulang setiap kali. **12 hari per
tahun hak**, dan tahun haknya berjalan dari ulang tahun masuk kerja ke ulang tahun
berikutnya.

> **Hak cuti tidak ada sebelum 12 bulan masa kerja, dan ia tidak menumpuk bulanan.** Masa
> kerja 11 bulan berarti **nol hari**, bukan sebelas per dua belas. Sisa cuti tahun lalu
> secara bawaan **tidak** dibawa ke tahun berikutnya.

Penolakan:

- *"Rentang tanggal tidak memuat satu pun hari kerja."*
- *"Tanggal selesai mendahului tanggal mulai."*
- *"Rentang cuti melebihi 90 hari — periksa tahunnya."*
- **Bertabrakan dengan pengajuan lain — termasuk yang masih DRAF**:
  *"Rentang tanggal bertabrakan dengan CTI/… (2026-08-10 s.d. 2026-08-14)."* Draf lama yang
  terlupa akan menolak pengajuan yang sungguhan. **Hapus drafnya**, jangan mencari jalan
  memutar.
- *"Cuti tahunan belum tersedia: masa kerja belum genap 12 bulan (UU 13/2003 Pasal 79).
  Hak cuti terbit 2027-01-15."*
- *"Saldo cuti tahunan tidak cukup: sisa 3 hari, diminta 5 hari (CTI/…)."* — diperiksa
  **dua kali**, saat Ajukan dan saat Setujui, dan diadu dengan tahun hak tempat tanggal
  **mulai** jatuh.

**Menyetujui cuti menulis ketidakhadirannya ke Rekap Absensi bulan itu**, menghitung ulang
kolom Sakit dan Cuti seluruhnya dari setiap pengajuan yang disetujui.

> **Apa pun yang diketik tangan ke kolom Sakit dan Cuti pada Rekap Absensi akan
> tertimpa.** Kolom lain tidak disentuh.
>
> Dan bila payroll bulan itu **sudah disetujui**, rekapnya **dilewati** — DIAM-DIAM.
> Layar hanya menampilkan *"Setujui berhasil."*; kalimat server yang menjelaskan
> pelewatannya tidak pernah sampai ke layar. Periksa Rekap Absensi bila Anda perlu
> memastikan bulan itu tidak berubah.

**Mencetak:** **`Cetak Pengajuan Cuti`** (Form F/PC). Berlampiran: ya.

### 11.5 Absensi Harian dan Rekap Absensi

**`SDM & Payroll › Absensi Harian`** — deskripsinya: *"Lembar absen lapangan: satu proyek,
satu tanggal, banyak karyawan. Tersimpan sebagai register — rekap bulanan payroll tetap
dokumen terpisah."*

1. Setel **Tanggal** (tidak bisa melewati hari ini) dan **Proyek** (*"Kosongkan untuk staf
   kantor."* — opsi pertamanya "— Tanpa proyek (kantor) —").
2. Lembarnya menampilkan satu baris per karyawan **aktif**, dengan tiga tombol
   **`Hadir`** / **`½ Hari`** / **`Absen`** dan kolom **Tersimpan** yang menunjukkan
   lencana yang sudah tercatat. Penghitung di kepala: "N dari M tercatat".
3. **Mengklik status yang sudah terpilih membatalkan pilihannya** — baris itu lalu tidak
   ikut dikirim sama sekali. *"Belum dicatat" bukan "absen".*
4. Tekan **`Simpan Lembar Absen`**. Notifikasi: *"Absensi tersimpan: N baru, M
   diperbarui."* Bila belum ada yang ditandai: *"Belum ada status yang dipilih."*

Menyimpan bekerja per pasangan **(karyawan, tanggal)** — mengirim ulang lembar setelah
koneksi putus **mengoreksi hari itu**, bukan menggandakannya. Perhatikan bahwa proyek pada
pemilih ditulis ke setiap baris yang dikirim, jadi menyimpan ulang seseorang di bawah
proyek lain **memindahkannya**.

**Mengubah pemilih Proyek tidak memuat ulang lembarnya** — itu disengaja, karena
pemuatan ulang akan membuang tanda yang belum disimpan. Ia hanya memindahkan jangkar cetak.

**`Cetak Daftar Hadir`** (Form F/DH, mendatar, berkolom tanda tangan basah) **mati sampai
pasangan tanggal-dan-proyek yang dipilih punya baris tersimpan**; tooltip-nya menyebutkan
alasannya: *"Belum ada absensi tersimpan untuk proyek ini pada 12 Agustus 2026"*.

Pengguna tanpa izin membuat melihat: *"Anda tidak memiliki izin hr.create — layar ini hanya
membaca."*

**`SDM & Payroll › Rekap Absensi`** — tanpa halaman detail; baris diubah di tempat. Kolom:
Kode · Karyawan · Periode · Hari kerja · Hadir · Sakit · Cuti · Alpa · **Lembur (jam)**.
Saringan: Karyawan, Tahun, Bulan.

Formulir: **Karyawan** · **Tahun** · **Bulan** · **Hari kerja** · **Hari hadir** · Sakit ·
Cuti · Alpa · **Jam lembur** (langkah 0,5). Satu rekap per karyawan per bulan; jumlah hari
0–31; lembur 0–999; dan **hadir + sakit + cuti + alpa tidak boleh melebihi hari kerja**
(pesannya hari ini berbahasa Inggris: `Present + sick + leave + alpha days may not exceed
the work days of the period.`).

> **Rekap Absensi hanya menggerakkan LEMBUR.** Perhitungan payroll membaca **satu** kolom
> dari rekap: **jam lembur**. Hari kerja, hadir, sakit, cuti, dan alpa **tidak berpengaruh
> sama sekali pada gaji** — karyawan yang tercatat 20 hari alpa tetap dibayar gaji pokok
> penuh.
>
> Dan **Absensi Harian belum memberi makan apa pun** — ia register kehadiran, bukan
> masukan payroll.
>
> Pemotongan gaji karena ketidakhadiran harus diurus di luar sistem, atau lewat jurnal
> penyesuaian.

> **Kolom jam lembur kini punya sumber di layar: Izin Lembur (ILB, §7.13).** Menyetujui
> sebuah ILB menghitung ulang jam lembur bulan itu untuk karyawan-karyawan izin itu —
> dari **seluruh** izin yang disetujui bulan itu, bukan ditambah-tambahkan — lalu
> menulisnya ke rekap. Jam lembur yang diketik tangan untuk karyawan yang punya ILB
> disetujui akan **tertimpa** pada persetujuan berikutnya; ketik tangan hanya untuk
> lembur yang memang tidak lewat izin. Periode yang payrollnya sudah diposting tidak
> pernah ditulis ulang — persetujuannya berkata: *"Rekap {YYYY-MM} tidak diubah —
> payroll periode itu sudah diposting."*

### 11.6 Payroll — `SDM & Payroll › Payroll`

Kolom: Kode · Periode · Jenis · Tgl bayar · Bruto · Potongan · Netto · Status. Saringan:
Status, Jenis, Tahun.

1. **`Tambah Payroll Run`**: **Tahun** · **Bulan** · **Jenis** (Gaji Bulanan /
   THR Keagamaan) · Tanggal pembayaran · Catatan.
2. **`Hitung Payroll`** — konfirmasi *"Hitung ulang payroll? Slip gaji yang sudah ada akan
   diganti."*
3. Periksa tabel slip: Karyawan · Gaji pokok · Tunjangan · Lembur · THR · Bruto · BPJS
   (karyawan) · **TER** (atau "Ps. 17") · PPh 21 · Netto. Ikon unduh per baris mengambil
   **slip gaji PDF** karyawan itu.
4. **`Ajukan`**.
5. **Direktur** menekan **`Setujui`** (atau **`Tolak`**, alasan wajib).

Empat petak di halaman run: Total bruto · Total potongan · Total netto · Jumlah slip.
Bila belum dihitung: *"Belum ada slip gaji. Jalankan 'Hitung Payroll' untuk membuatnya."*

**Yang dilakukan `Hitung Payroll`**: menghapus slip yang ada, lalu membuat satu slip per
karyawan **aktif** yang tanggal masuknya ≤ akhir periode.

- Gaji pokok + tunjangan tetap + lembur = **bruto**.
- **Lembur** = jam lembur dari rekap × ((gaji pokok + tunjangan tetap) ÷ 173) × **1,5**.
  Pemisahan tarif 1,5× / 2× per hari (Kepmenaker 102/2004) **tidak diterapkan** — tarif
  rata 1,5× dipakai karena rekap hanya menyimpan total jam.
- Dasar upah BPJS = gaji pokok + tunjangan tetap.
- **PPh 21 = TER (PMK 168/2023)**, kecuali **Desember**, yang memakai perhitungan tahunan
  Pasal 17 dikurangi TER yang sudah dipotong Januari–November. Kedua kalimat itu tercetak
  sebagai pemberitahuan tetap di halaman run, dan **nilai PPh 21 negatif pada Desember
  berarti kelebihan potong yang dikembalikan**.
- **THR**: masa kerja ≥ 12 bulan → 1× gaji pokok; 1–11 bulan → pro-rata; **< 1 bulan →
  tidak dibuatkan slip sama sekali**. Tidak ada BPJS pada THR.
- Setiap slip **membekukan proyek karyawan** dari penugasan personel yang bertumpang tindih
  dengan periode itu (penugasan yang paling belakangan dimulai yang menang). **Tidak ada
  pemecahan satu orang ke dua proyek dalam satu bulan.**

> **`Setujui` ADALAH postingnya.** Tidak ada langkah posting terpisah, tidak ada
> pembatalan persetujuan, dan tidak ada posting kedua
> (`"Payroll PYR/… is already posted as JV/…. Correcting it needs a reversing journal, not
> a second posting."`). Setelah itu, satu-satunya koreksi adalah jurnal pembalik oleh
> akuntan.

> **Jurnal payroll bertanggal HARI TERAKHIR PERIODE, bukan tanggal pembayaran.** Payroll
> Juni yang disetujui 5 Juli tetap dibukukan 30 Juni.

> **Menyetujui payroll TIDAK membayar siapa pun.** Ia membentuk kewajiban pada akun
> Hutang Gaji & Upah. Transfernya adalah **dokumen Pembayaran tersendiri** yang dialokasikan
> ke akun itu, dan ia berjalan lewat rantai persetujuannya sendiri (§11.7).

Penolakan lain: `"Payroll run PYR/… has no payslips yet — calculate it first."` ·
`"Payroll run … cannot be modified while status is submitted."` · pemisahan tugas (§2.5).

**Tidak ada cara mengeluarkan seorang karyawan dari sebuah run.** `Hitung Payroll`
mengambil setiap karyawan aktif yang tanggal masuknya jatuh pada atau sebelum akhir
periode.

**Mencetak:** **`Cetak Rekap Gaji`** (Form F/RG, mendatar), plus ikon unduh slip PDF per
baris.

### 11.7 Membayar gaji, BPJS, dan pajak

Pembayaran gaji dibuat seperti pengeluaran lain (§5.10), tetapi dialokasikan ke **akun
kewajiban**, bukan ke tagihan vendor — lewat kartu **"Bayar kewajiban non-AP (gaji, pajak,
BPJS)"** di halaman pembayaran.

Kewajiban yang boleh dilunasi langsung lewat pembayaran hanya delapan: **Hutang Gaji &
Upah · Hutang BPJS · Hutang PPh 21 · Hutang PPh 23 · Hutang PPh Final 4(2) · Hutang PPh
Badan · PPN Keluaran · Beban Yang Masih Harus Dibayar**. Selain itu ditolak:

> "Akun 2-1100 Hutang Usaha tidak termasuk kewajiban yang dapat dilunasi langsung lewat
> pembayaran. Hutang vendor dilunasi lewat tagihannya; akun lain punya mekanisme
> pelunasannya sendiri."

Plafon per akun yang ditampilkan layar adalah kredit terposting **sampai akhir bulan
tanggal pembayaran** dikurangi debit terposting — itulah yang memungkinkan pembayaran
tanggal 25 Juni melunasi akrual bertanggal 30 Juni.

---

## 12. Layanan & pemeliharaan

Kelompok **Layanan**: **Tiket · Tiket Lewat SLA · Kontrak Layanan · Jadwal Preventif ·
Berita Acara**.

### 12.1 Siapa boleh apa

Peran `teknisi` memegang lihat/buat/ubah pada modul Layanan, serta **lihat** dan —
sejak 22 Agustus 2026 — **posting** pada Persediaan. Ia **tidak** memegang izin hapus
layanan. Peran `sales` memegang lihat layanan (baca saja). Persetujuan layanan ada
pada direktur dan admin.

Izin posting stok itu diberikan justru untuk bab ini: **seorang teknisi kini bisa
menyelesaikan sendiri kunjungan yang memakai suku cadang** (§12.5). Izin yang sama
juga membuka tombol posting/pembatalan stok di bab 6 untuk peran teknisi — itu harga
yang diterima sadar saat izinnya diberikan (§6.1).

### 12.2 Kontrak Layanan — `Layanan › Kontrak Layanan`

Kolom: Kode · Kontrak (dengan nama pelanggan) · Mulai · Berakhir · Nilai · **SLA respons
(jam)** · Status (Aktif / Berakhir / Diputus). Saringan: Status, Pelanggan.

Formulir "Kontrak pemeliharaan": **Pelanggan** (wajib) · Kontrak CRM (tautan opsional) ·
**Nama kontrak** (wajib) · **Periode mulai** (wajib) · **Periode berakhir** (wajib) ·
**Nilai kontrak** (wajib) · **Siklus penagihan** (wajib: Bulanan / Triwulanan / Tahunan) ·
**SLA respons (jam)** (wajib) · **SLA penyelesaian (jam)** (wajib) · Status · Cakupan
layanan.

Tabel baris **Lokasi layanan** (minimal 1): **Nama lokasi** (wajib) · Alamat · Kota · PIC ·
Telepon PIC.

**Tabel Lokasi layanan pada halaman kontrak adalah tempat Anda membaca nomor id lokasi**,
yang diperlukan formulir tiket dan formulir jadwal preventif (§12.3).

Tenggat mengawasi kontrak layanan yang mendekati akhir periode **60 hari** di muka.

**Mencetak:** **`Cetak Ringkasan Kontrak Layanan`** (Form F/KL). Tidak menerima lampiran.

### 12.3 Tiket — `Layanan › Tiket`

Kolom: Kode · Judul (dengan pelanggan) · Kategori · Prioritas · Dilaporkan · **SLA
selesai** (memerah bila lewat) · Status. Saringan: Status, Prioritas, Kategori, Kontrak.

Bantuan di formulir: *"SLA respons & penyelesaian dihitung otomatis dari kontrak (jam
kerja, kecuali prioritas kritis yang 24/7)."*

Kolom: Kontrak layanan · Pelanggan · **ID lokasi** · **Judul** (wajib) · **Kategori**
(wajib: Gangguan / Permintaan / Pemeliharaan Preventif) · **Prioritas** (wajib:
Rendah/Sedang/Tinggi/Kritis, bawaan Sedang) · Kanal (Telepon/Email/WhatsApp/Portal/Sistem)
· Dilaporkan oleh · Waktu dilaporkan · Teknisi · Deskripsi.

**"ID lokasi" adalah kotak ANGKA MENTAH, bukan pemilih.** Anda harus tahu nomor id lokasi
kontraknya; nomornya terbaca di tabel **Lokasi layanan** pada halaman Kontrak Layanan.
Lokasi yang bukan milik kontrak terpilih ditolak: `"The selected site does not belong to
the selected service contract."`

**Tombol di halaman tiket** (semuanya izin ubah layanan):

| Tombol | Muncul saat | Isian |
|---|---|---|
| **`Tugaskan`** | tiket belum ditutup/dibatalkan | **Teknisi** (wajib) |
| **`Tambah Aktivitas`** | idem | **Jenis aktivitas** (Komentar / Perubahan Status / Penugasan / Catatan Pekerjaan) · **Isi** (wajib) · Waktu (menit) |
| **`Selesaikan`** | tiket belum selesai | **Catatan penyelesaian** (**wajib**) |
| **`Tutup Tiket`** | status Terselesaikan | konfirmasi *"Tutup tiket ini?"* |

Halaman tiket memperlihatkan petak **Prioritas / SLA respons / SLA penyelesaian /
Teknisi** — tiap petak SLA berbunyi "Tercapai", "SLA terlampaui", atau "Target dalam N
hari" — lalu kartu **Deskripsi masalah**, **Catatan penyelesaian**, **Aktivitas (N)**
sebagai garis waktu berisi pelaku, waktu, dan menitnya, serta di kolom samping **Detail
tiket** dan **Berita acara** (kode berita acara yang tertaut).

**Jam SLA:**

- **Prioritas Kritis berjalan pada jam dinding 24/7.**
- **Semua prioritas lain dihitung dalam jam kerja Senin–Jumat 08.00–17.00 WIB**; akhir
  pekan dilewati, dan tiket yang masuk di luar jam itu mulai dihitung pukul 08.00
  berikutnya.

> **Tiket tanpa Kontrak layanan tidak punya SLA sama sekali.** Kedua kolom tenggatnya
> tetap kosong, dan tiket itu **tidak akan pernah muncul di Tiket Lewat SLA**.

**Waktu respons pertama** dicap oleh **Komentar** atau **Catatan Pekerjaan** pertama
setelah teknisi ditugaskan — **atau oleh `Selesaikan` bila sebelumnya tidak ada apa pun
yang dicatat**. Teknisi yang memperbaiki sesuatu lalu baru membuka tiketnya akan
memperlihatkan SLA respons "tercapai" yang sebenarnya tidak pernah tercapai. Catat
aktivitas begitu Anda menyentuh tiket.

Catatan Pekerjaan pertama juga memindahkan status dari Ditugaskan ke Dikerjakan.

> **Tiket yang sudah Terselesaikan tidak bisa diubah dan tidak bisa ditugaskan ulang** —
> penolakannya berbahasa Inggris, mis. `Ticket TKT-… is resolved and can no longer be
> edited.` **Tidak ada tombol membuka kembali tiket.** Satu-satunya langkah yang tersisa
> adalah `Tutup Tiket`; bila persoalannya kembali, buat tiket baru.

Menghapus hanya diperbolehkan pada tiket Terbuka atau Dibatalkan.

### 12.4 Tiket Lewat SLA — `Layanan › Tiket Lewat SLA`

Layar baca saja. Dua kotak (**Tiket lewat SLA**, **Terlama**) dan tabel: Kode · Judul ·
Pelanggan · Prioritas · Batas selesai · **Terlambat** · Ditugaskan ke. Klik baris untuk
membuka tiketnya.

Yang masuk daftar: tiket yang **belum ditutup/dibatalkan** dan **(respons lewat tanpa
respons pertama ATAU penyelesaian lewat dan belum selesai)**.

Bila kosong: *"Tidak ada tiket yang melewati SLA. Daftar ini hanya memuat tiket yang belum
selesai dan sudah lewat batas waktu."*

### 12.5 Berita Acara Lapangan — `Layanan › Berita Acara`

Kolom: Kode · Tiket · Tanggal · Teknisi · TTD pelanggan · Status (Draf / Diajukan /
Disahkan Pelanggan). Bisa diubah/dihapus hanya saat **Draf**.

Kolom formulir: **Tiket** (wajib) · **Tanggal kunjungan** (wajib, bawaan hari ini) ·
**Teknisi** (wajib) · Nama penandatangan · **Gudang suku cadang** · **Temuan** (wajib) ·
**Tindakan** (wajib) · Rekomendasi. Tabel baris **Sparepart terpakai**: **Item** (wajib) ·
**Qty** (wajib) · Catatan.

**"Gudang suku cadang" terlihat opsional tetapi wajib dalam praktik begitu ada satu baris
sparepart.**

**Urutan yang benar:**

1. Isi seluruh laporan sampai benar — tanggal, gudang, temuan, tindakan, dan barisnya.
2. **`Ajukan`**.
3. Setelah pelanggan menandatangani di lokasi, **`Sahkan Pelanggan`** dengan mengisi
   **Nama penandatangan pelanggan** (wajib).

> **Semuanya harus benar SEBELUM `Ajukan`.** Mengajukan **mengunci setiap kolom**, dan
> laporan yang membawa sparepart menahan tutup buku bulan tanggal kunjungannya.
>
> Itulah sebabnya `Ajukan` **mencoba seluruh pengeluaran stoknya lebih dulu lalu
> membatalkannya kembali**. Bila percobaan itu gagal, pengajuannya ditolak dengan kalimat
> yang sama yang nanti akan dipakai pengesahan, dibungkus:
>
> "Laporan PM/… belum dapat diajukan. Pengesahan pelanggan nanti mengeluarkan suku
> cadangnya dari gudang, dan pengeluaran itu diuji sekarang — hasilnya ditolak: {pesan
> asli} Perbaiki selagi laporan masih berstatus draf: setelah diajukan seluruh kolomnya
> terkunci, dan periode yang memuat tanggal kunjungan tidak dapat ditutup sampai laporan
> ini selesai. Pemeriksaan ini tidak membuat bon maupun mutasi stok — nomor bon yang
> mungkin disebut di atas hanya nomor uji coba."

Tanpa gudang:
> "Laporan PM/… mencantumkan suku cadang, tetapi gudang asalnya belum diisi. Isi kolom
> 'Gudang suku cadang' pada laporan, lalu ulangi pengesahan pelanggan — tanpa gudang, stok
> tidak dapat dikeluarkan."

**`Kembalikan ke Draf`** (pada laporan Diajukan) dengan konfirmasi *"Kembalikan berita
acara ini ke draf agar tanggal, gudang dan sparepart-nya bisa diperbaiki?"* — itu
satu-satunya jalan mundur, dan **ia berhenti bekerja begitu pelanggan menandatangani**:

> "Laporan PM/… sudah disahkan pelanggan dan tidak dapat dikembalikan ke draf. Pengesahan
> itu sudah menerbitkan bon ISS/…: suku cadangnya sudah keluar dari gudang dan jurnalnya
> sudah ada di buku besar. Bon yang lahir dari berita acara tidak dapat dibatalkan, jadi
> koreksinya lewat opname."

> **`Sahkan Pelanggan` MENGGERAKKAN STOK SUNGGUHAN**, bertanggal **hari kunjungan**, bukan
> hari Anda mengklik. Bon yang lahir darinya **tidak bisa dibatalkan** — koreksinya adalah
> **opname stok** (§6.7), yang membukukan selisihnya sebagai beban.
>
> Kotak **"Nama penandatangan"** di formulir **bukan** bukti tanda tangan; hanya
> `Sahkan Pelanggan` yang mencapnya.

> **Pengesahan laporan bersuku-cadang menuntut izin posting stok di samping izin ubah
> layanan** — karena pengesahan itu memposting bon persediaan sungguhan dalam
> transaksi yang sama. **Sejak 22 Agustus 2026 peran `teknisi` memegang izin itu**,
> jadi seorang teknisi mengesahkan sendiri kunjungan yang ia lakukan — dulunya hanya
> admin yang bisa, dan setiap kunjungan bersuku-cadang menunggu akun admin.
>
> **Laporan tanpa sparepart adalah tanda tangan murni** — tidak butuh gudang, tidak
> menggerakkan stok, dan tidak butuh izin posting.
>
> Peran selain teknisi/admin yang menekan `Sahkan Pelanggan` pada laporan bersuku-
> cadang tetap menerima galat 403 berbahasa Inggris yang tidak menjelaskan apa-apa
> (`This action is unauthorized.`) — tombolnya digambar untuk siapa pun yang punya
> izin ubah layanan, servernya menuntut lebih.

**Mencetak:** **`Cetak Berita Acara Servis`** (Form F/BS). Berlampiran: ya.

### 12.6 Jadwal Preventif — `Layanan › Jadwal Preventif`

Kolom: Jadwal (dengan kode kontrak) · Lokasi · Frekuensi · **Jatuh tempo** (dengan "N hari
lagi") · Teknisi · Aktif. Saringan: Kontrak.

Formulir: **Kontrak layanan** (wajib) · **ID lokasi** (kotak angka, seperti pada tiket) ·
**Nama jadwal** (wajib) · **Frekuensi** (wajib: Bulanan / Triwulanan / Semesteran) ·
**Jatuh tempo berikutnya** (wajib) · Teknisi · Aktif · **Checklist** (satu butir per
baris — tampil sebagai daftar berpoin di halaman jadwal).

**Tombol di kepala daftar: `Buat Tiket PM`** dengan konfirmasi *"Buat tiket untuk semua
jadwal PM yang sudah jatuh tempo?"*

Ia membuat **satu tiket per jadwal yang jatuh tempo** — kategori Pemeliharaan Preventif,
prioritas Rendah, kanal "system", pelapor "Jadwal PM otomatis", judul
`{nama jadwal} — dd/mm/yyyy`, deskripsi = checklistnya — lalu **menggulirkan tanggal jatuh
tempo berikutnya maju sampai melewati hari ini**.

> **Satu tiket susulan per jadwal, bukan satu tiket per periode yang terlewat.** Jadwal
> bulanan yang tertinggal empat bulan menghasilkan **satu** tiket, bukan empat.
>
> Jadwal yang kontraknya hilang atau **tidak Aktif dilewati diam-diam**.
>
> Dan pekerjaan yang sama **sudah berjalan otomatis setiap malam**, jadi menekan tombolnya
> biasanya tidak perlu.

Jadwal preventif **tidak menerima lampiran**.

---

## 13. Mencetak formulir rumah

### 13.1 Cara kerjanya

Formulir rumah adalah kertas perusahaan — lembar berkop yang ditandatangani, diarsipkan,
dan diperlihatkan kepada pelanggan, konsultan MK, atau pemeriksa. Ada **48** di antaranya.

Menekan tombol **`Cetak <nama formulir>`**:

1. Membuka **tab baru** yang sesaat berbunyi *"Menyiapkan formulir…"*.
2. Menggambar lembarnya, lalu **membuka dialog cetak peramban dengan sendirinya**.

Ia **bukan** unduhan PDF. Bila peramban memblokir jendela barunya, muncul notifikasi:

> "Popup diblokir browser. Izinkan popup untuk situs ini, lalu cetak lagi."

**Setiap lembar membawa bilah petunjuk yang hanya tampil di layar, tidak ikut tercetak**,
yang mengingatkan Anda menekan Ctrl+P, memilih A4, orientasi yang benar, dan
**menyalakan "Grafik latar belakang"** — tanpa itu, kepala tabel yang berarsir tercetak
putih. Rinciannya (termasuk sebelas formulir mendatar) ada di
`docs/PANDUAN-ADMINISTRATOR.md` §9.3.

**Tombol yang izinnya tidak Anda pegang tidak digambar sama sekali** — daftar formulirnya
disaring server. **Tombol yang tidak ada berarti "tidak diizinkan", bukan "tidak
tersedia".**

### 13.2 Di mana tombolnya

Ini yang paling sering membuat orang menyimpulkan sebuah formulir tidak ada. Tombolnya
duduk di **empat tempat yang berbeda**, tergantung jenis layarnya:

| Jenis layar | Letak tombol | Contoh |
|---|---|---|
| Layar dokumen biasa | baris tombol di kepala halaman | Penawaran, PO, GRN, Payroll |
| Layar daftar **tanpa** halaman dokumen | **ikon printer di baris** | Progres Mingguan |
| Layar khusus | tombolnya sendiri di kepala layar atau di kartu | Kasir Kas Kecil, Absensi Harian, Kalender Pajak, halaman proyek |
| Register yang dicetak per induk | pada halaman **salah satu anggotanya** | Register Jaminan (per kontrak), Daftar Temuan (per proyek), Daftar Saldo Stok (per gudang) |

**Kalau Anda tidak menemukan tombol cetak di halaman dokumen, lihat barisnya di daftar.**

### 13.3 Daftar lengkap 48 formulir

**Penjualan** (izin lihat penjualan):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Surat Penawaran Harga | F/PN | halaman Penawaran |
| Ringkasan Kontrak | F/RK | halaman Kontrak |
| Berita Acara Pekerjaan Tambah / Kurang — CCO berjenis waktu tercetak **BERITA ACARA ADDENDUM WAKTU** dari tombol yang sama (§3.7) | F/BATK | halaman Pekerjaan Tambah-Kurang |
| Register Jaminan & Asuransi (mendatar) | F/RJ | halaman Jaminan — mencetak **seluruh jaminan kontrak itu** |

**Estimasi** (izin lihat estimasi):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| RAB / BOQ | F/RAB | halaman BOQ |
| Analisa Harga Satuan Pekerjaan | F/AHSP | halaman AHSP |
| RAP | F/RAP | halaman RAP |

**Engineering** (izin lihat engineering) — dijelaskan lengkap di §16:

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Lembar Persetujuan Shop Drawing | F/SD | halaman Persetujuan Gambar (SDS) |
| Lembar Persetujuan Material | F/SM | halaman Persetujuan Material (SMS) |
| Transmittal Dokumen | F/TR | halaman Transmittal |
| Ijin Pelaksanaan Pekerjaan | F/IPP | halaman IPP |

**Mutu (QA/QC)** (izin lihat mutu) — dijelaskan lengkap di §17:

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Lembar Inspeksi Mutu | F/QI | halaman Inspeksi Mutu (QCI) |
| Laporan Ketidaksesuaian | F/NCR | halaman Ketidaksesuaian (NCR) |
| Benda Uji Beton & Hasil Uji | F/BU | halaman Benda Uji Beton |

**Pengadaan** (izin lihat pengadaan):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Permintaan Pembelian | F/PP | halaman PR |
| Pesanan Pembelian (formulir rumah) | F/PO | halaman PO — **berbeda dari tombol `PDF`**, yang mencetak pesanan komersial untuk pemasok |
| Tabulasi Banding Penawaran (mendatar) | F/TBP | halaman RFQ |
| Evaluasi Vendor | F/EV | halaman Evaluasi Vendor |
| Persyaratan K3L Vendor | F/K3V | halaman Vendor |

**Persediaan** (izin lihat persediaan):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Bukti Penerimaan Barang | F/BPB | halaman GRN |
| Bon Pengeluaran Barang | F/BM | halaman Pengeluaran |
| Surat Jalan Antar Gudang | F/SJ | halaman Transfer |
| Berita Acara Stock Opname (mendatar) | F/BAO | halaman Opname |
| Daftar Saldo Stok (mendatar) | F/SS | **halaman Gudang** — selalu bertanggal hari mencetak |
| Bukti Retur Pembelian | F/RPB | halaman Retur Pembelian |
| Bukti Retur Material | F/RTM | halaman Retur Material |

**Subkontrak** (izin lihat subkontrak):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Surat Perintah Kerja (SPK) Subkontraktor | F/SP | halaman SPK |
| Berita Acara Addendum SPK | F/AS | halaman Addendum |
| Berita Acara Opname dan Pembayaran Subkontraktor (mendatar) | F/BO | halaman Opname Subkon |

**Keuangan** (izin lihat keuangan):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Lembar Verifikasi Tagihan Vendor | F/VT | halaman Tagihan Vendor (AP) |
| Bukti Pembayaran / Penerimaan Kas & Bank | F/BP | halaman Pembayaran |
| Voucher Jurnal | F/VJ | halaman Jurnal |
| Register Kewajiban Pajak Masa (mendatar) | F/KP | layar **Kalender Pajak**, tombol `Cetak Register` |
| Ekualisasi Pajak (mendatar) | F/EQ | layar **Ekualisasi Pajak**, tombol `Cetak Ekualisasi` (§10.11) |

*(Kode `F/BP` kini hanya milik Bukti Pembayaran / Penerimaan. Tabulasi Banding
Penawaran sempat memakai kode yang sama; kodenya sudah diganti menjadi `F/TBP`. Lembar
lama berkop "Form F/BP" berjudul TABULASI BANDING PENAWARAN di arsip berasal dari masa
sebelum penggantian itu.)*

**SDM & Payroll** (izin lihat SDM):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Rekapitulasi Gaji Karyawan (mendatar) | F/RG | halaman Payroll |
| Formulir Pengajuan Cuti / Izin | F/PC | halaman Cuti & Izin |
| Daftar Hadir Harian (mendatar) | F/DH | layar **Absensi Harian**, tombol `Cetak Daftar Hadir` |

**Layanan** (izin lihat layanan):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Berita Acara Pekerjaan Servis | F/BS | halaman Berita Acara |
| Ringkasan Kontrak Layanan | F/KL | halaman Kontrak Layanan |

**Aset** (izin lihat aset):

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Kartu Aset | F/KA | halaman Aset |
| Berita Acara Mobilisasi Alat | F/BAM | halaman Mobilisasi |

**Proyek** (izin lihat proyek) — ketujuhnya dijelaskan lengkap di §7.13:

| Formulir | Kode | Tombolnya di |
|---|---|---|
| Data Proyek | F/DP | halaman proyek |
| Laporan Harian | F/LH | halaman Laporan Harian |
| Detail Schedule / Program Kerja (mendatar) | F/DS | **baris** daftar Progres Mingguan |
| Daftar Temuan / Defect List (mendatar) | F/DT | halaman satu temuan — mencetak **seluruh register proyek** |
| Izin Kerja Lapangan | F/IK | halaman satu izin — register `Izin Kerja (IKL)` |
| Izin Kerja Lembur | F/IL | halaman satu izin — register `Izin Lembur (ILB)` |
| Izin Masuk / Keluar Material & Peralatan | F/IM | halaman satu izin — register `Izin Material (IMK)` |

### 13.4 Empat dokumen yang punya PDF sungguhan

Berbeda dari formulir rumah, keempat ini **mengunduh berkas**:

| Dokumen | Tombol | Berkas |
|---|---|---|
| Invoice Termin (AR) | `PDF` | `invoice-{kode}.pdf` |
| Pesanan Pembelian (PO) | `PDF` | pesanan komersial untuk pemasok |
| BAST | `PDF` | BAST berkop |
| Slip gaji | ikon unduh per baris di halaman Payroll | slip per karyawan |

### 13.5 Aturan kejujuran — kenapa sebagian lembar bergaris kosong

Setiap formulir rumah hanya mencetak apa yang benar-benar tersimpan. Yang tidak tersimpan
dicetak **bergaris kosong untuk ditulis tangan**, dan sebagian lembar menuliskan alasannya
di badan lembarnya sendiri. Itu bukan kekurangan cetakan — itu supaya tidak ada angka
karangan yang muncul di atas kertas bertanda tangan.

Yang paling sering ditemui:

| Lembar | Yang bergaris kosong | Sebabnya |
|---|---|---|
| Surat Penawaran Harga | blok **SYARAT & KETENTUAN**, 4 baris | sistem tidak menyimpan syarat penjualan |
| SPK Subkontraktor | **TERMIN PEMBAYARAN** | SPK tidak menyimpan jadwal pembayaran apa pun; termin bayar vendor bukan syarat SPK ini |
| Laporan Harian | hanya sel yang laporannya tidak mencatat: jabatan tanpa baris, tabel tanpa baris (termasuk semua laporan lama), catatan kosong di dalam baris uraian, jam kerja yang tidak diisi | keempat tabel FM-10-12 dan jam kerja kini punya sumber di layar Laporan Harian (§7.3); catatan kaki lembarnya menyebut hanya tabel yang masih manual pada laporan itu |
| Semua kop proyek | **PERPANJANGAN WAKTU I & II** — hanya pada kontrak tanpa addendum waktu yang disetujui | addendum waktu (CCO jenis `waktu`, §3.7) yang disetujui kini mengisi kedua baris; mulai addendum ketiga baris II berbunyi `lihat register`, tidak pernah dipotong diam-diam |
| Izin Kerja Lapangan / Lembur / Material | lokasi/area & tabel ALAT (F/IK) · jam per orang (F/IL) · kolom SPESIFIKASI & JAM (F/IM) · kolom PENGENDALIAN · sisa baris tabel · dua kolom tanda tangan pengawas | sejak izin menjadi dokumen (§7.13) badan lembar tercetak dari baris izinnya; yang tetap bergaris adalah sel yang tidak punya kolom di basis data — dan kolom *Diperiksa* F/IM baru terisi setelah cap `Periksa di gerbang` |
| Berita Acara Tambah-Kurang | baris **nilai kontrak sesudahnya** | hanya terisi bila CCO-nya sudah disetujui; lembar addendum waktu tidak punya baris nilai sama sekali — baris tanggal selesainya berbunyi "belum disetujui" selama draf (§3.7) |
| Detail Schedule | **batang rencana** | hanya diarsir bila ada baseline yang disetujui |
| Kop empat pihak | kotak **KONSULTAN MK** dan kolom tanda tangannya | kosong bila kolom Konsultan pada proyek belum diisi (§7.2) |
| Persetujuan Gambar / Material (F/SD, F/SM) | sel **KEPUTUSAN** — tetapi tidak bergaris: submittal yang belum dijawab MK tercetak *"Menunggu keputusan Konsultan MK"*, dan revisi yang tergantikan tercetak *"DIGANTIKAN oleh {kode SDS}"* | keputusan MK adalah fakta yang diketik (bab 16); yang belum diketik dicetak sebagai keadaan menunggu, bukan garis kosong dan bukan stempel karangan. Kolom tanda tangan MK tetap kosong — tak ada yang mencatat siapa membubuhkannya |
| Ijin Pelaksanaan (F/IPP) | tabel bahan/alat/gambar/material terisi dari baris IPP; kolom KEPUTUSAN per baris gambar/material tercetak stempel MK apa adanya | F/IPP mencetak keadaan tersimpan **apa adanya** sementara gerbang IPP (§16.5) menimbangnya terpisah — lembar boleh menampilkan "Disetujui dengan catatan" pada baris material yang gerbang tetap tolak |
| Inspeksi Mutu (F/QI) | sel **HASIL KESELURUHAN** — bergaris kosong selama lembar belum punya satu pun butir hasil | verdict lulus/tidak dicetak dari boolean tersimpan, tetapi `passed` hanya benar secara hampa pada checklist yang belum diisi; maka selama tak ada butir, selnya dikosongkan, bukan dicetak LULUS (bab 17) |
| Ketidaksesuaian (F/NCR) | kolom **DIVERIFIKASI OLEH** & **TANGGAL VERIFIKASI** | terisi hanya setelah NCR diverifikasi (§17.3); NCR yang masih terbuka tak pernah mencetak pemverifikasi yang belum ada |
| Benda Uji Beton (F/BU) | kolom **MEMENUHI** membaca hasil hitung tersimpan; **TARGET fc' (28 HARI)** bergaris kosong pada mutu yang tak terbaca | lulus/tidak adalah aritmetika ConcreteStrengthService saat uji direkam — bukan verdict yang diketik di samping kolom; mutu yang tak dikenali parser dikosongkan, bukan ditebak (§17.4) |

**Lembar yang sudah diisi dan ditandatangani itulah catatannya.** Arsipkan di berkas
proyek — tidak ada layar untuk merekamnya kembali.

---

## 14. Yang tidak bisa Anda lakukan sendiri — dan siapa yang bisa

Bab ini ada supaya Anda berhenti mencari tombol yang memang tidak ada.

### 14.1 Akun dan akses

| Yang Anda butuhkan | Kenapa tidak bisa sendiri | Minta ke |
|---|---|---|
| Mengganti kata sandi Anda | Tidak ada layar untuk itu; satu-satunya kolom sandi ada di `Sistem › Pengguna` | administrator |
| Kata sandi baru karena lupa | Tidak ada tautan "lupa kata sandi" dan tidak ada email reset | administrator |
| Mengganti nama atau email Anda | Tidak ada layar profil sendiri | administrator |
| Melihat daftar izin Anda | Dialog Akun hanya menampilkan **jumlahnya** | administrator |
| Menambah izin atau peran | Peran diatur di `Sistem › Peran & Hak Akses` | administrator |
| Membuka menu yang tidak ada di sidebar Anda | Kelompok menu bergerbang izin | administrator |
| Keluar dari perangkat lain | Tidak ada daftar sesi dan tidak ada "keluar dari semua perangkat" | administrator |
| Mengaktifkan pemberitahuan email | Mati secara bawaan; WhatsApp tidak ada | administrator |
| Mematikan "Wajib pemisahan tugas" | Ada di Pengaturan, butuh izin sistem | administrator (dan itu keputusan kebijakan, bukan kemudahan) |
| Mengubah tarif pajak, ambang persetujuan direktur, format penomoran dokumen | Ada di Pengaturan | administrator |
| Mengubah **Profil Perusahaan** (kop setiap formulir cetak) | Kolomnya terlihat tetapi mati tanpa izin sistem | administrator |

Rujukannya: `docs/PANDUAN-ADMINISTRATOR.md` §3 (akses & peran), §3.4 (kata sandi), §3.5
(sesi), §4.2 (profil perusahaan), §4.6 (pengaturan).

### 14.2 Tombol yang tidak akan pernah muncul untuk peran Anda

Ini bukan kerusakan. Tombol yang izinnya tidak Anda pegang **tidak digambar**.

| Anda | Yang tidak akan Anda lihat | Yang bisa |
|---|---|---|
| **sales** | `Setujui` penawaran/CCO · `Aktifkan Kontrak` · seluruh kelompok Keuangan · peringatan Tenggat untuk jaminan dan kontrak | direktur (dan keuangan untuk penagihan) |
| **estimator** | `Setujui` BOQ dan RAP | direktur |
| **procurement** | `Setujui` PR dan PO · membuat penerimaan barang (GRN) | direktur; gudang |
| **warehouse** | **`Posting ke Stok`** · **`Kirim`/`Terima`** transfer · **`Posting Retur`** · `Batalkan Penerimaan` · `Batalkan Bon` | **admin atau teknisi** — peran gudang sendiri tidak memegang izin posting stok (§6.1) |
| **site-manager** | `Verifikasi` / `Dispensasi` / `Buka kembali` temuan · `Tutup Insiden` K3 · `Setujui` BAST dan baseline · `Tutup proyek` | manajer proyek, direktur |
| **project-manager** | `Setujui` SPK, addendum, opname subkon · **`Bayar Retensi`** dan **`Cairkan Uang Muka`** pada SPK · `Demobilisasi` / `Posting Penyusutan` / `Hapus Buku` aset | direktur; **admin** untuk dua tombol SPK itu; keuangan atau admin untuk aset |
| **finance** | `Setujui` invoice/tagihan/pembayaran · **`Posting Jurnal`** · `Terbitkan nomor bukti potong` | manajer keuangan atau direktur |
| **finance-manager** | membuat dokumen apa pun; `Posting Pembayaran` | petugas keuangan |
| **hr** | `Setujui` cuti · **`Setujui` payroll** (yang sekaligus memposting) | direktur |
| **teknisi** | menghapus tiket (butuh izin hapus layanan; `Sahkan Pelanggan` bersuku-cadang sudah bisa sejak 22 Agu 2026 — §12.5) | **admin** |
| **direktur** | membuat/mengubah dokumen apa pun; `Posting ke Stok`; `Posting Penyusutan` | pemegang perannya masing-masing |
| **pemegang kas kecil** | memposting bon atau mencairkan kasbon **di laci orang lain** | pemegang laci itu sendiri |

**Dua tombol yang paling sering ditemui buntu** pada susunan peran bawaan:

1. **`Posting ke Stok`** dan seluruh keluarga tombol stok (bab 6) — butuh **admin
   atau teknisi** (yang terakhir sejak 22 Agustus 2026, §6.1).
2. **`Bayar Retensi`** dan **`Cairkan Uang Muka`** pada halaman SPK — keduanya menuntut
   izin posting subkontrak **dan** izin persetujuan keuangan **sekaligus** (§8.6,
   §8.7); hanya **admin** yang memenuhi keduanya.

**Tidak ada pemberian izin per orang di aplikasi ini** — semuanya lewat peran. Jadi
permintaan yang benar selalu berbentuk *"tolong tambahkan saya ke peran X"* atau *"tolong
posting dokumen ini"*, bukan *"tolong berikan saya izin Y"*.

### 14.3 Pekerjaan yang tidak punya layar sama sekali

Bukan soal izin — memang tidak ada tombolnya untuk siapa pun.

| Yang ingin Anda lakukan | Kenyataannya | Yang bisa Anda lakukan |
|---|---|---|
| Menambah/mengubah/menghapus satu **bagian atau item BOQ** | Tidak ada editornya; catatan biru di formulir BOQ keliru | `Sistem › Impor Dokumen`, atau **`Versi Baru`** dari BOQ yang sudah berisi (§4.1) |
| Mengetik baris **RAP** dengan tangan | Formulirnya tidak punya tabel baris | **`Buat dari BOQ`** atau Impor Dokumen (§4.4) |
| Menambah/mengubah **satu tugas WBS** (bobot, nama, tanggal rencana) | Tidak ada layarnya | Perbaiki BOQ-nya lalu **`Buat WBS dari BOQ`** (progres hilang), atau minta administrator (§7.2) |
| Menurunkan **keparahan temuan** dari Kritis/Mayor ke Minor | Kolom alasannya tidak ada di formulir mana pun | Pakai **`Dispensasi`**, atau minta administrator (§7.6) |
| Melihat **daftar prasyarat BAST II** sebelum menekan Setujui | Tidak dipanggil layar mana pun | Buka Register Defect, saring proyeknya, baca petak "Menahan BAST II" (§7.11) |
| Mengubah **Rencana tagih** satu termin pada kontrak yang jadwalnya terkunci | Tidak ada layar yang memanggilnya | Minta administrator (§3.10) |
| Melihat nilai kontrak **sebelum dan sesudah** semua CCO | Tidak ada layarnya | Cetak Berita Acara Tambah-Kurang per CCO; cari CCO lewat kotak cari dengan kode kontraknya (§3.7) |
| Membatalkan penawaran yang salah ditandai **Kalah** | Tidak ada tombolnya, dan status Selesai tidak bisa diubah | Buat penawaran baru bernomor lain (§3.4) |
| Mencetak **Detail Schedule** bulan selain bulan berjalan | Pemilih bulannya tidak ada | Cetak pada bulan yang bersangkutan (§7.13) |
| Menautkan **JV** ke sebuah kewajiban di Kalender Pajak | Pemilihnya tidak pernah berhasil memuat | Tulis nomor JV-nya di kolom Catatan (§10.10) |
| Mengirim penawaran atau invoice **lewat email** dari aplikasi | Tidak ada | Cetak PDF-nya, kirim sendiri |
| Mengirim **RFQ ke vendor** dari aplikasi | Tidak ada portal vendor dan tidak ada email | Kirim lewat jalur lain; ketik harga yang masuk ke lembar banding (§5.5) |
| Melihat mode penagihan (utuh vs parsial) sebuah PO | Tidak ada layarnya | Anda mengetahuinya dari penolakan (§5.9) |
| Nota kredit vendor | Tidak ada dokumennya | Jurnal manual di Keuangan (§5.9, §6.4) |
| **Pencairan retensi sebagian** (pelanggan) | Modalnya tidak punya kotak jumlah | Seluruh baris cair sekaligus (§3.13) |
| **Pencocokan sebagian** di rekonsiliasi bank | Tidak didukung | Tandai `Tanpa padanan`, atau pecah dokumennya (§10.4) |
| Membuka kembali **tiket** yang sudah selesai | Tidak ada tombolnya | Buat tiket baru (§12.3) |
| Membuka kembali **proyek** yang sudah ditutup | Tidak ada tombolnya | Ubah statusnya lewat formulir proyek (§7.12) |
| Memulihkan apa pun yang **dihapus** | Tidak ada tempat sampah dan tidak ada undelete | — (§2.1) |

**Yang juga tidak ada, secara umum:** kotak centang baris dan aksi massal · pemilih kolom ·
tampilan tersimpan / favorit / filter tersimpan (alamat URL adalah satu-satunya cara
menyimpan saringan, §2.1) · pelacakan batch/serial/kedaluwarsa/rak pada stok · reservasi
stok · kalender hari libur nasional · pemotongan gaji karena ketidakhadiran · pemecahan
gaji satu orang ke dua proyek · integrasi e-filing pajak · bantuan dalam aplikasi atau
daftar pintasan papan ketik.

### 14.4 Yang tidak bisa dibatalkan — satu daftar

Baca ini sebelum menekan, bukan sesudah.

| Tindakan | Apa yang terjadi | Satu-satunya jalan setelahnya |
|---|---|---|
| **`Tandai Kalah`** pada penawaran | Status dipaksa Selesai; Ubah, Hapus, dan Buat Revisi semuanya hilang | penawaran baru bernomor lain |
| **`Buat Revisi`** pada penawaran | Status kembali Draf; baris lama bertahan sampai `Simpan` berikutnya menimpanya utuh — tidak ada arsip versi lama | cetak PDF sebelum merevisi |
| **`Aktifkan Kontrak`** | Kontrak langsung Disetujui, tidak bisa diubah lagi | — |
| Invoice AR pertama **disetujui** | Jadwal termin kontrak terkunci selamanya | — |
| **`Setujui`** invoice / tagihan | Jurnal terbentuk | `Batalkan Dokumen` (jurnal pembalik), bila belum dibayar |
| **`Posting Pembayaran`** | Uang bergerak di buku besar | `Balikkan Pembayaran` (dua jurnal berdampingan selamanya) |
| **`Catat pencairan`** retensi | Jurnal langsung; seluruh baris cair | invoice-nya tidak bisa lagi dibatalkan, pembayarannya tidak bisa lagi dibalik |
| **`Posting ke Stok`** (GRN / bon) | Stok dan HPP bergerak, dokumen terkunci | `Batalkan Penerimaan` / `Batalkan Bon` (pemegang izin posting stok, §6.1), selama syaratnya terpenuhi |
| **`Kirim`** transfer | Tidak bisa diubah, dihapus, atau dibatalkan | transfer kedua ke arah sebaliknya |
| **`Setujui`** opname stok | **Sekaligus memposting**; selisihnya menjadi beban | opname berikutnya |
| **`Posting Retur`** | Stok dan kewajiban bergerak | tidak ada pembatalan sama sekali |
| **`Buat WBS dari BOQ`** | Seluruh WBS dan progresnya dihapus, tanpa konfirmasi | entri ulang progres satu per satu |
| **`Bekukan`** pada baris baseline | Baseline tidak bisa diubah, diambil ulang, atau dihapus | baseline revisi baru |
| **`Setujui`** BAST I | Seluruh entri lapangan proyek itu tertutup | ubah status proyek lewat formulir |
| **`Setujui`** BAST II | **Proyek langsung Ditutup**, tanpa konfirmasi kedua | ubah status proyek lewat formulir |
| **`Sahkan Pelanggan`** berita acara | Bon suku cadang terbit, bertanggal hari kunjungan | opname stok |
| **`Setujui`** payroll | **Sekaligus memposting seluruh run** | jurnal pembalik oleh akuntan |
| **`Posting Penyusutan`** | Akumulasi dan nilai buku berubah | jurnal pembalik |
| **`Hapus Buku / Jual`** aset | Jurnal pelepasan terposting | jurnal pembalik |
| **`Simpan`** log BBM / jam alat | Baris register permanen — tidak ada Ubah dan tidak ada Hapus (§9.5) | baris log baru berangka benar, koreksinya disebut di catatan |
| **`Posting Jurnal`** | Masuk buku besar | jurnal kedua yang berlawanan |
| **Impor Dokumen** memperbarui dokumen | Baris yang tidak dibawa berkas **dihapus** | impor ulang dari ekspor dokumen utuh |
| **Hapus** apa pun dari daftar | Permanen sejauh yang bisa dilakukan layar | — |

Untuk daftar administratifnya — penutupan bulan yang tidak bisa dibuka, jurnal PSAK 115,
dan konsekuensi lain di sisi server — lihat `docs/PANDUAN-ADMINISTRATOR.md` §8.4.

### 14.5 Cara meminta bantuan supaya cepat

Ketika sebuah layar menolak Anda, administrator hanya perlu empat hal. Kirimkan keempatnya
sekaligus:

1. **Alamat halamannya** — salin seluruh isi bilah alamat, termasuk bagian setelah `#`.
   Alamat itu membawa saringan, urutan, dan halaman yang Anda lihat (§2.1).
2. **Kode dokumennya** (mis. `PO/2026/VIII/0003`).
3. **Teks merah persis seperti yang tertulis di layar Anda** — jangan diringkas. Kalimat
   penolakan di sistem ini sengaja menyebut nama orang, angka, dan jalan keluarnya; kalimat
   itulah petunjuk yang dipakai administrator.
4. **Tombol apa yang Anda tekan**, dan **apa yang Anda harapkan terjadi**.

Bila yang hilang adalah **tombolnya**, sebutkan itu — "tombol `Posting ke Stok` tidak ada
di layar saya" adalah keterangan yang jauh lebih berguna daripada "tidak bisa posting",
karena tombol yang tidak ada hampir selalu berarti izin, bukan kerusakan.

Bila pesan merahnya panjang dan tidak hilang sendiri, itu memang disengaja — ia menunggu
Anda membacanya, dan biasanya sudah memuat nama orang yang harus Anda datangi.

---

## 15. Persetujuan oleh Pemilik/MK

MK dan Pemilik bukan pengguna sistem ini — mereka tidak punya akun, dan tidak akan
diberi akun. Keputusan mereka masuk lewat **dua pintu**, dan dua-duanya bermuara pada
register bukti yang sama:

1. **Tautan sekali-pakai** — Anda menerbitkan sebuah alamat web, mengirimkannya sendiri
   (WhatsApp / e-mail pribadi), dan MK/Owner membukanya di ponselnya, membaca ringkasan
   dokumennya, lalu menekan satu dari tiga tombol: **Setuju** · **Setuju dengan
   catatan** · **Tolak**. Tautan mati pada keputusan pertama.
2. **Lembar fisik** — dokumen dicetak, ditandatangani di lapangan, discan, dilampirkan
   pada dokumennya, lalu keputusannya dicatat dari kartu yang sama.

Keduanya dikerjakan dari kartu **Persetujuan Eksternal (MK/Owner)** di halaman detail
**tiga** dokumen — hanya tiga, yang lain tidak punya kartu ini:

| Dokumen | Akibat keputusan eksternal |
|---|---|
| Laporan Harian (§7.3) | Bukti dicatat, dan laporan **terkunci** pada keputusan pertama — seperti terkunci oleh BAST I |
| Pekerjaan Tambah-Kurang / CCO (§3.7) | **Bukti saja.** CCO tidak berpindah status — tombol `Setujui` internal tetap harus ditekan pemegang setujui CCO |
| Izin Kerja Lapangan / IKL (§7.13) | Keputusan eksternal **menggerakkan** izinnya: Setuju/Setuju dengan catatan menyetujui, Tolak menolak |

Melihat kartunya butuh izin lihat modul dokumen itu; **menerbitkan tautan, mencabutnya,
dan mencatat lembar fisik butuh izin setujui** modul itu (menerbitkan tautan keputusan
adalah kuasa setingkat menyetujui) — tanpa izin itu tombolnya tidak digambar, dan
server pun menolak: *"Anda tidak memiliki izin prj.approve."*

### 15.1 Menerbitkan tautan

Tekan **`Terbitkan Tautan`** pada kartunya. Isiannya: **Pihak** (MK / Pemilik) ·
**Nama pemutus** · **Organisasi** · **E-mail** (*"Opsional, arsip untuk siapa tautan
diterbitkan — sistem tidak mengirim e-mail."*) · **Masa berlaku (hari)** (*"Kosongkan
untuk 7 hari."*). Setelah `Terbitkan`, dialog **Tautan persetujuan diterbitkan**
menampilkan alamatnya dengan tombol `Salin` — dan ini satu-satunya kesempatan Anda:

> **Salin sekarang — tautan hanya ditampilkan sekali dan tidak dapat dilihat lagi.
> Bila hilang: cabut tautan ini, lalu terbitkan yang baru.**

Itu bukan kelalaian layar. Server hanya menyimpan sidik jari tautannya, bukan
tautannya, sehingga **tidak ada seorang pun** — termasuk administrator — yang bisa
menampilkannya lagi. Mengirimkannya kepada MK/Owner adalah pekerjaan Anda, lewat
saluran Anda; sistem tidak mengirim apa pun.

Penolakan penerbitan yang akan Anda temui:

> *"Tautan persetujuan pekerjaan tambah-kurang hanya dapat diterbitkan saat dokumen
> berstatus submitted — saat ini draft."* — CCO dan IKL harus **Diajukan** dulu;
> laporan harian bebas kapan saja.
>
> *"Maker-checker: pengaju izin kerja lapangan ini tidak boleh menerbitkan tautan
> persetujuan eksternal untuk dokumennya sendiri — keputusan dari tautan diterapkan
> atas nama penerbitnya. Minta pemegang izin approve yang lain menerbitkannya."* —
> hanya pada IKL, karena di sanalah keputusan eksternal benar-benar menggerakkan
> dokumen.

### 15.2 Apa yang dilihat — dan tidak bisa dilakukan — MK/Owner

Halaman tautan berdiri sendiri: ringkasan dokumen (kode, proyek/kontrak, angka
kuncinya), tiga tombol keputusan, dan satu kolom catatan. Tidak ada login, tidak ada
menu, tidak ada dokumen lain, tidak ada lampiran — pemegang tautan **hanya bisa
memutuskan dokumen itu, sekali**. Dua orang yang membuka tautan yang sama: keputusan
pertama yang tercatat; klik kedua melihat **struk** keputusan yang menang, bukan
formulir. Sesudah dipakai, tautan yang sama menampilkan struk itu selamanya; yang
dicabut atau kedaluwarsa berkata jujur (*"Tautan sudah dicabut oleh penerbitnya."* /
*"Tautan sudah kedaluwarsa sejak {waktu}."*), dan tautan yang salah ketik dijawab
*"Tautan tidak dikenal atau sudah tidak berlaku."* tanpa keterangan apa-apa lagi.

Pada IKL ada satu penolakan lagi di ujung: bila di antara terbit dan klik pengaju
izinnya berganti sehingga penerbit tautan menjadi pengajunya sendiri, halaman berkata
*"Keputusan tidak dapat dicatat: aturan pemisahan tugas internal kontraktor menolak
penerapannya. Tautan Anda belum terpakai — hubungi penerbit tautan."* — terbitkan
tautan baru lewat orang lain.

### 15.3 Mencatat lembar fisik

Urutannya dua langkah, dan langkah pertama bukan di kartu ini: **scan lembar
bertanda tangan dilampirkan dulu pada dokumen itu** lewat kartu **Lampiran** (§2.7).
Baru tekan **`Catat Tanda Tangan Fisik`**: pilih scannya (*"Hanya lampiran dokumen ini
yang bisa dipilih — scan lembar dokumen lain ditolak server."*), lalu Pihak · Nama
penanda tangan · Organisasi · **Keputusan** · Catatan keputusan · Tanggal keputusan
(*"Kosongkan untuk hari ini."*). Tanpa lampiran, kartu menolak sebelum formulirnya
terbuka: *"Lampirkan dulu scan lembar bertanda tangan pada dokumen ini (kartu
Lampiran), baru catat keputusannya."* Keputusan tanpa bukti memang tidak dicatat;
scan dari dokumen lain ditolak dengan menyebut nama berkasnya.

Pencatatan lembar fisik **tidak dibatasi status dokumen** — kertas boleh pulang
terlambat, berhari-hari setelah CCO-nya disetujui proksi internal; buktinya tetap
dicatat. (Pada IKL aturan siklus dokumen tetap berlaku, karena di sana keputusan
benar-benar menggerakkan izinnya.)

### 15.4 Sesudah keputusan tercatat

Dari pintu mana pun, tiga hal terjadi:

- **Lonceng** berbunyi untuk semua pemegang izin setujui modul itu: *"Keputusan
  eksternal tercatat: …"* — tombol `Buka dokumen` membawa ke halaman dokumennya.
- **Barisnya di kartu** menunjukkan chip `Diputuskan`, keputusannya, catatannya, via
  tautan atau lembar fisik, dan waktunya. Baris keputusan adalah **bukti permanen** —
  tidak bisa diubah dan tidak bisa dicabut.
- **Akibat per dokumen** pada tabel di atas berjalan. Laporan harian yang terkunci
  menolak ubah/hapus dengan menyebut pemutusnya: *"Laporan {kode} terkunci oleh
  keputusan eksternal {keputusan} — {nama} ({pihak}) pada {waktu} — dan tidak dapat
  {diubah/dihapus}: yang sudah diputuskan pihak luar bukan draf lagi."*

Pada CCO, sekali lagi: keputusan eksternal **tidak menggantikan** tombol `Setujui`
internal (§2.5). Bukti MK/Owner tercatat; yang menggerakkan status CCO tetap pemegang
setujui CCO di dalam sistem.

### 15.5 Mencabut tautan

Tombol **`Cabut`** ada di tiap baris tautan yang masih hidup (chip `Terbit`). Tautan
yang dicabut tidak bisa dipakai memutuskan. Yang tidak bisa dicabut:

> *"Tautan ini sudah dipakai mencatat keputusan (Setuju, {waktu}) — keputusan adalah
> bukti dan tidak dapat dicabut."*
>
> *"Tautan sudah dicabut pada {waktu}."*

Kedaluwarsa tidak perlu dicabut — chipnya berganti `Kedaluwarsa` sendiri dan tautannya
menolak dipakai.

### 15.6 Catatan jujur tentang produksi hari ini

Server produksi (erp1) masih berdiri di belakang gerbang kata sandi tingkat server
yang memblokir **semua** akses tanpa login — termasuk halaman tautan ini. Sampai
administrator menurunkan gerbang itu (`docs/PANDUAN-ADMINISTRATOR.md`), tautan yang
Anda terbitkan hanya bisa dibuka dari dalam gerbang, dan **pintu yang berfungsi penuh
untuk MK/Owner adalah lembar fisik**. Kartu, register bukti, dan loncengnya bekerja
sejak sekarang.

---

## 16. Engineering — gambar, submittal, IPP

Bab ini milik tim teknik: **drafter/estimator** yang mendaftarkan gambar dan menyiapkan
submittal, **site manager** yang mengajukan izin pelaksanaan, dan **manajer proyek** yang
mencatat stempel MK serta menyetujui IPP. Di sidebar, semuanya ada di grup **Engineering**,
tepat di antara Estimasi dan Proyek — karena di situlah pekerjaannya duduk: gambar dan
material disetujui MK **sebelum** lapangan boleh mulai.

**Satu aturan yang mendasari seluruh bab ini: keputusan MK adalah fakta yang diketik, bukan
persetujuan di dalam sistem.** Konsultan MK bukan pengguna aplikasi ini — ia mengembalikan
lembar berstempel, dan seseorang mengetik stempel itu ke kolom keputusan. Karena itu
submittal gambar dan material **tidak** memakai tombol `Ajukan`/`Setujui`/`Tolak` seperti
dokumen lain; ia memakai satu tombol **`Catat Keputusan MK`**. Hanya **IPP** yang berjalan
lewat persetujuan sungguhan di dalam sistem.

### 16.1 Siapa boleh apa

| Peran | Di modul Engineering |
|---|---|
| **Estimator / drafter** (`estimator`) | Daftar gambar, siapkan submittal SDS/SMS, buat transmittal & IPP, ajukan IPP. **Tidak** mencatat stempel MK (bukan pemegang `eng.approve`) |
| **Site manager** (`site-manager`) | Sama seperti drafter: buat & ajukan, termasuk mengajukan IPP dari lapangan |
| **Manajer proyek** (`project-manager`) | Semua di atas **plus** `Catat Keputusan MK` pada submittal, dan `Setujui`/`Tolak` IPP |
| **Direktur** (`direktur`) | Membaca semuanya; boleh mencatat stempel MK dan menyetujui IPP — tetapi **tidak membuat** dokumen (bukan pemegang `eng.create`) |
| **Petugas pengadaan** (`procurement`) | **Hanya membaca** — terutama daftar SMS: membeli material yang belum disetujui MK persis yang dicegah dengan membaca register itu (§16.3) |

Dua pagar melekat pada `Catat Keputusan MK` sekaligus: Anda perlu izin `eng.approve`,
**dan** Anda tidak boleh orang yang mengajukan submittal itu sendiri. Jadi drafter yang
menyiapkan submittal tak akan pernah mencatat stempelnya sendiri, dan seorang MP yang
kebetulan menyiapkannya pun ditolak — mesti pemegang `eng.approve` yang lain.

### 16.2 Register Gambar — `Engineering › Register Gambar`

Daftar shop drawing proyek (FM-10-01). Kolom: No. Gambar · Judul · Disiplin · Rencana ajuan
· SDS berlaku · Status. Disiplin salah satu dari **Struktur, Arsitektur, MEP, ELV, ICT**
(ELV dan ICT berdiri sendiri karena perusahaan ini juga integrator sistem).

1. **`Tambah Gambar`**. Isi **Proyek** (wajib, hanya saat membuat), **Nomor gambar**,
   **Judul**, **Disiplin**, **Rencana tanggal ajuan**.
2. Nomor gambar unik per proyek — nomor yang sama tidak bisa didaftarkan dua kali.

**Kolom Status bergerak sendiri.** Anda tidak mengetiknya: ia mengikuti keputusan MK pada
SDS terbaru gambar itu (Belum diajukan → Diajukan → salah satu dari empat stempel). Revisi
gambar diajukan dari layar **Persetujuan Gambar (SDS)**, bukan dari sini.

### 16.3 Persetujuan Gambar (SDS) — `Engineering › Persetujuan Gambar (SDS)`

Kode `SDS/…`. Ini pengajuan satu **revisi** gambar untuk diperiksa MK. Kolom daftar: Kode ·
No. Gambar · Rev · Diajukan · Pemeriksa · Keputusan.

1. **`Tambah Persetujuan Gambar`**. Isi **Gambar (register)** (wajib, hanya saat membuat),
   **Revisi** (bawaan `R0`), **Tanggal diajukan**, **Pemeriksa** (Konsultan MK / Pemilik).
2. Lampiran berkas gambar (dwg/pdf) menempel di sini, di kartu Lampiran submittal — bukan di
   register gambar. Yang distempel MK adalah satu revisi, dan berkasnya milik revisi itu.

**Revisi baru menggantikan yang lama.** Mengajukan revisi berikutnya (mis. R1) untuk gambar
yang sama membuat revisi sebelumnya bercap **Digantikan** dalam satu transaksi; kolom
keputusan revisi lama tidak disentuh — ia riwayat.

**Mencatat stempel MK.** Setelah lembar kembali dari MK, tekan **`Catat Keputusan MK`**
(butuh `eng.approve`) dan isi **Stempel**, **Tanggal stempel**, **Catatan stempel (apa
adanya)**. Empat stempel, persis lembar FM-10:

| Stempel | Arti | Membuka gerbang IPP? |
|---|---|---|
| **Disetujui** | Laksanakan | Ya |
| **Disetujui dengan catatan** | Laksanakan sambil memasukkan catatannya | Ya (untuk baris **gambar**) |
| **Revisi & ajukan ulang** | Perbaiki dan ajukan revisi baru | Tidak |
| **Ditolak** | Ditolak | Tidak |

Yang akan menahan Anda:

- Bukan pencatatnya: *"Pencatat keputusan tidak boleh orang yang mengajukan submittal {kode}
  sendiri — minta pemegang eng.approve lain mencatat lembar stempel MK."*
- Sudah pernah dicatat: *"Keputusan {stempel} sudah tercatat untuk {kode} pada {tanggal} dan
  tidak dapat ditimpa; bila lembar stempel berbeda, ajukan revisi baru."*
- Revisi sudah tergantikan: *"Submittal {kode} telah digantikan revisi {kode}; keputusan MK
  dicatat pada revisi terbarunya."*

### 16.4 Persetujuan Material (SMS) — `Engineering › Persetujuan Material (SMS)`

Kode `SMS/…`. Sama pola dengan SDS, untuk **material** (FM-10-05/22). Isi **Nama material**,
**Merek**, **Rujukan spesifikasi**, **Item persediaan** (opsional), **Sampel disertakan**,
**Pemeriksa**. Stempel dicatat dengan **`Catat Keputusan MK`** yang sama.

Bedanya dari gambar: **tak ada rantai revisi**. Material yang dikembalikan diajukan
**sebagai SMS baru** — keputusan yang sudah tercatat tak pernah ditimpa: *"Submittal
{kode} sudah berkeputusan {stempel} dan tidak dapat diubah; material yang dikembalikan
diajukan sebagai submittal baru."*

> **Asimetri yang wajib Anda ingat: "Disetujui dengan catatan" TIDAK meloloskan baris
> material pada IPP; hanya "Disetujui" penuh.** Catatan pada material ("ganti merek",
> "lengkapi sertifikat uji") mengubah apa yang boleh datang ke lapangan — beda dari catatan
> pada gambar yang hanya soal cara membaca lembar. Maka gerbang IPP (§16.5) menuntut
> material berstempel **Disetujui** utuh, sementara gambar boleh "Disetujui dengan catatan".

### 16.5 Ijin Pelaksanaan Pekerjaan (IPP) — `Engineering › Ijin Pelaksanaan (IPP)`

Kode `IPP/…` (FM-10-11). IPP mengumpulkan **gambar, material, bahan, dan alat** sebuah
pekerjaan, lalu diajukan agar disetujui **sebelum** pekerjaan berjalan. Inilah satu-satunya
dokumen Engineering yang memakai siklus `Ajukan` → `Setujui`/`Tolak` biasa.

1. **`Tambah Ijin Pelaksanaan Pekerjaan`**. Isi **Proyek** (wajib, hanya saat membuat),
   **Lingkup** (Struktur/Arsitektur/MEP), **Rencana mulai**, **Durasi (hari)**, **Lokasi
   tapak** (§16.7), **Paket pekerjaan (WBS)**, **Uraian pekerjaan**.
2. Isi baris **bahan** (item, qty, satuan), **alat**, **gambar** (menunjuk submittal SDS),
   dan **material** (menunjuk submittal SMS).
3. **`Simpan`**, lalu **`Ajukan`**.

**Paket pekerjaan (WBS) di IPP menetes ke bon.** Bila diisi, ia harus paket **daun** ber-BOQ
pada proyek yang sama (tiga penolakan sama persis dengan pemilih WBS pada bon, §6.5) —
karena bon gudang yang menunjuk IPP ini **mewarisi** paket itu. Submittal yang dirujuk
pun harus milik proyek yang sama: *"Submittal {kode} berada pada proyek lain dan tidak
dapat dirujuk IPP proyek ini."*

**Gerbangnya — inilah inti modul.** `Ajukan` **ditolak** selama ada baris yang belum beres,
dan pesannya menyebut **setiap** dokumen penghambat sekaligus (bukan satu per satu), supaya
Anda tahu persis lembar mana yang harus dikejar ke MK. **Tidak ada tombol konfirmasi di
sini** — bekerja di atas gambar yang belum disetujui adalah persis yang formulir ini
cegah. Bentuk pesannya:

> *"IPP {kode} tidak dapat diajukan: gambar {kode} ({no} {rev}) masih menunggu keputusan
> Konsultan MK; material {kode} ({nama}) berkeputusan Disetujui dengan catatan — baris
> material menuntut keputusan Disetujui penuh; bereskan catatannya dan ajukan ulang
> submittal-nya. Selesaikan persetujuan MK-nya dahulu."*

Tiap penghambat berbunyi salah satu dari:

- gambar belum dijawab: *"gambar {kode} ({no} {rev}) masih menunggu keputusan {pemeriksa}"*
- gambar berstempel bukan-pembuka: *"gambar {kode} ({no} {rev}) berkeputusan {stempel}"*
- gambar sudah tergantikan: *"gambar {kode} ({no} {rev}) telah digantikan revisi {kode} —
  rujuk revisi terbarunya"*
- material belum dijawab: *"material {kode} ({nama}) masih menunggu keputusan {pemeriksa}"*
- material belum Disetujui penuh: *"material {kode} ({nama}) berkeputusan Disetujui dengan
  catatan — baris material menuntut keputusan Disetujui penuh; bereskan catatannya dan
  ajukan ulang submittal-nya"*

Setelah lolos gerbang, IPP masuk siklus baku: pemegang `eng.approve` (MP, direktur)
menekan **`Setujui`** atau **`Tolak`**, dan Anda tetap tidak boleh menyetujui IPP yang Anda
ajukan sendiri (§2.5). IPP yang sudah keluar dari Draf/Ditolak tak bisa diubah lagi: *"IPP
{kode} berstatus {status} dan tidak dapat diubah lagi."*

### 16.6 Transmittal — `Engineering › Transmittal`

Kode `TRM/…`. Surat pengantar yang mencatat dokumen apa keluar (atau masuk) dari kendali
dokumen proyek. Isi **Arah** (Keluar/Masuk), **Kepada**, **Tanggal transmittal**, lalu baris
**Dokumen yang disertakan**: tiap baris berjenis `drawing_submittal`, `material_submittal`,
atau `lainnya` (teks bebas).

- Baris SDS/SMS diisi **ID dokumennya**; baris "lainnya" cukup uraian teks —
  *"Baris teks bebas wajib membawa uraian."*
- Jenis di luar ketiganya ditolak: *"Jenis baris \"{x}\" tidak dikenal. Yang tersedia:
  drawing_submittal, material_submittal, lainnya."*
- Dokumen proyek lain ditolak dan disebut nomornya: *"Dokumen {kode} berada pada proyek lain
  dan tidak dapat dimuat pada transmittal proyek ini."*

**Tanda terima mengunci lembar.** Tekan **`Catat Tanda Terima`** (butuh `eng.update` —
mencatat siapa menandatangani adalah tata usaha, bukan keputusan), isi **Diterima oleh**
dan tanggal (kosongkan untuk memakai waktu saat ini). Sesudahnya transmittal tak bisa
diubah/dihapus:
*"Transmittal {kode} sudah diterima {nama} pada {waktu} dan tidak dapat diubah lagi."*

### 16.7 Lokasi Tapak — `Engineering › Lokasi Tapak`

Rincian tapak hierarkis: **Tower › Lantai › Zona › As › Ruang**. Dipakai kolom LOKASI pada
IPP (dan inspeksi mutu kelak), maka tempatnya di samping dokumen yang memakainya. Layar ini
**digerbangi izin proyek** (`prj.*`), bukan `eng.*` — tim proyek yang menyusun rinciannya —
jadi site manager tanpa `eng.view` pun tetap melihat barisnya.

1. **`Tambah Lokasi`**. Isi **Proyek**, **Induk** (opsional), **Jenis**, **Kode** (unik),
   **Nama**, **Urutan**.
2. Untuk tapak besar, **impor CSV** lewat layar Data Master (kolom: kode, nama, proyek_kode,
   jenis, induk_kode, urutan) jauh lebih cepat daripada mengetik ratusan baris.

Pagar hirarkinya:

- Induk dan anak harus satu proyek: *"Induk lokasi {kode} berada pada proyek lain; induk dan
  anak harus pada proyek yang sama."*
- Tak boleh melingkar: *"Lokasi {kode} tidak boleh menjadi induk dari dirinya sendiri
  (siklus hirarki)."*
- Tak bisa dihapus selama beranak: *"Lokasi {kode} masih memiliki {n} sub-lokasi; hapus atau
  pindahkan dulu sub-lokasinya."*

### 16.8 Mencetak

Empat formulir rumah, semua kop empat pihak, dicetak dari tombol **`Cetak`** di halaman
daftar/detailnya (§13):

| Tombol | Formulir | Dari layar |
|---|---|---|
| `Cetak Persetujuan Gambar (SDS)` | F/SD Lembar Persetujuan Shop Drawing | Persetujuan Gambar |
| `Cetak Persetujuan Material (SMS)` | F/SM Lembar Persetujuan Material | Persetujuan Material |
| `Cetak Transmittal` | F/TR Transmittal Dokumen | Transmittal |
| `Cetak Ijin Pelaksanaan (IPP)` | F/IPP Ijin Pelaksanaan Pekerjaan | IPP |

Aturan kejujuran berlaku penuh (§13.5): submittal yang **belum** dijawab MK tercetak
*"Menunggu keputusan Konsultan MK"* — bukan stempel karangan, bukan garis kosong — dan
revisi yang tergantikan tercetak *"DIGANTIKAN oleh {kode SDS}"* di mukanya. F/IPP mencetak
stempel **apa adanya** sementara gerbang menimbangnya terpisah; kolom tanda tangan MK tetap
kosong, karena tak ada yang mencatat siapa membubuhkannya.

---

## 17. Mutu — inspeksi, NCR, benda uji beton

Bab ini milik tim mutu di lapangan: **site manager** dan **QC proyek** yang mengisi lembar
inspeksi dan mencatat benda uji, dan **manajer proyek** yang menyetujui lembar inspeksi
serta memverifikasi ketidaksesuaian. Di sidebar semuanya ada di grup **Mutu (QA/QC)**,
tepat sesudah Proyek — karena mutu berjalan di atas pekerjaan lapangan dan menggerbanginya:
sebuah ketidaksesuaian yang belum beres **menahan** inspeksi tahap berikutnya di lokasi yang
sama, dan menahan serah terima pertama (BAST I).

**Satu aturan yang mendasari seluruh bab ini: verdict adalah aritmetika, bukan pendapat.**
Lulus/tidak sebuah inspeksi dihitung dari butir yang Anda tandai — satu butir "tidak sesuai"
menggagalkan lembarnya, dan Anda tidak bisa mengetik "lulus" di atas butir yang tidak
mendukungnya. Lulus/tidak sebuah benda uji dihitung server terhadap target mutunya pada umur
uji — tidak pernah diketik. Ini bukan kerewelan; ini supaya tak ada angka karangan muncul di
atas lembar uji yang ditandatangani.

### 17.1 Siapa boleh apa

| Peran | Di modul Mutu |
|---|---|
| **Site manager** (`site-manager`) | Buat & ajukan inspeksi, catat NCR & mulai perbaikannya, catat benda uji & hasil, susun template checklist. **Tidak** menyetujui inspeksi atau memverifikasi NCR (bukan pemegang `qc.approve`) |
| **Manajer proyek** (`project-manager`) | Semua di atas **plus** `Setujui`/`Tolak` inspeksi, dan `Verifikasi` NCR |
| **Direktur** (`direktur`) | Membaca semuanya; boleh menyetujui inspeksi dan memverifikasi NCR — tetapi **tidak membuat** dokumen (bukan pemegang `qc.create`) |
| **Petugas lain** | Tanpa `qc.view`, grup **Mutu (QA/QC)** tidak muncul di sidebar sama sekali |

Seperti di seluruh aplikasi, pemegang `qc.approve` tidak boleh menyetujui inspeksi yang ia
ajukan sendiri (§2.5) — maker-checker berlaku penuh pada lembar inspeksi.

### 17.2 Inspeksi Mutu (QCI) — `Mutu › Inspeksi Mutu (QCI)`

Kode `QCI/…`. Sebuah inspeksi adalah **satu template checklist yang diisi** di satu lokasi
pada satu tanggal. Kolom daftar: Kode · Paket · Tahap · Tgl Inspeksi · Lulus · Status.

1. **`Tambah Inspeksi Mutu`**. Isi **Proyek** (wajib, hanya saat membuat), **Template
   checklist** (wajib, hanya saat membuat — §17.5), **Lokasi**, **IPP terkait** (opsional),
   **Tanggal inspeksi**, **Inspektor**, **Disaksikan** (Konsultan MK / Pemilik).
2. Tekan **`Muat butir dari template`** untuk menarik seluruh butir checklist ke bawah
   formulir. Butir dan kriteria terkunci (milik template); Anda mengisi **Hasil** tiap butir
   — **Sesuai / Tidak sesuai / Tidak berlaku** — dan **Catatan** bila perlu.
3. **`Simpan`**, lalu **`Ajukan`**.

**Hasil keseluruhan dihitung, bukan diketik.** Satu butir **Tidak sesuai** menggagalkan
lembar (kolom Lulus menjadi tidak); **Tidak berlaku** tak pernah menggagalkan — butir yang
tidak berlaku pada pengecoran ini tidak bisa membuat pengecorannya tidak sesuai.

**Disaksikan oleh ≠ yang menyetujui.** Konsultan MK yang menyaksikan adalah **fakta yang
dicatat** (dicetak di F/QI di samping kolom tanda tangan), bukan penyetuju di dalam sistem.
Inspeksi berjalan lewat maker-checker rumah biasa (`Ajukan` → `Setujui`/`Tolak`,
`qc.approve`) — persis IPP.

**Gerbang NCR — inti bab ini.** `Ajukan` **ditolak** bila ada NCR terbuka di lokasi yang
sama yang berasal dari **tahap sebelumnya**. Titik henti mutunya berurutan **sebelum → saat
→ setelah**: inspeksi ulang pada tahap yang **sama** tetap lolos, lokasi lain lolos; hanya
melangkah ke tahap berikutnya di atas ketidaksesuaian yang belum beres yang ditolak.
Pesannya menyebut **setiap** NCR penghambat sekaligus, dan **tidak ada tombol konfirmasi**
— bekerja di tahap berikutnya di atas NCR terbuka adalah persis yang gerbang ini cegah:

> *"Inspeksi {kode} tahap {tahap} tidak dapat diajukan: masih ada NCR terbuka di lokasi ini
> dari tahap sebelumnya — {NCR} ({tahap}, {status}); …. Selesaikan (verifikasi) NCR-nya
> dahulu sebelum melanjutkan ke tahap berikutnya."*

Penolakan lain yang mungkin Anda temui:

- Lokasi bukan milik proyek: *"Lokasi yang dipilih bukan bagian dari proyek inspeksi ini."*
- IPP proyek lain: *"IPP yang dipilih berada pada proyek lain dan tidak dapat mendasari
  inspeksi ini."*
- Butir asing: *"Butir hasil tidak termasuk dalam template inspeksi ini."*
- Sudah keluar dari draf: *"Inspeksi {kode} berstatus {status} dan tidak dapat diubah
  lagi."*

### 17.3 Ketidaksesuaian (NCR) — `Mutu › Ketidaksesuaian (NCR)`

Kode `NCR/…`. Laporan ketidaksesuaian dibuat ketika pekerjaan tidak memenuhi kriteria. Kolom
daftar: Kode · Tahap · Uraian · Batas Waktu · Status.

**NCR bukan dokumen persetujuan.** Ia tidak memakai `Ajukan`/`Setujui`; ia punya siklusnya
sendiri: **Terbuka → Perbaikan berjalan → Terverifikasi → Ditutup**. Setiap perpindahan
punya tombolnya sendiri:

| Tombol | Dari status | Butuh izin | Artinya |
|---|---|---|---|
| **`Mulai Perbaikan`** | Terbuka | `qc.update` | Penanggung jawab mulai memperbaiki |
| **`Verifikasi`** | Terbuka / Perbaikan | `qc.approve` | QC memeriksa ulang dan menerima koreksinya |
| **`Tutup`** | Terverifikasi | `qc.update` | Ditutup secara administratif |

`Mulai Perbaikan` bertanya *"Tandai NCR ini sedang dalam perbaikan oleh penanggung
jawabnya?"*. `Verifikasi` meminta **Tanggal verifikasi** (kosongkan untuk memakai hari
ini) — ini yang mencabut blokir: begitu NCR terverifikasi, ia tak lagi menahan inspeksi
maupun BAST I. `Tutup` bertanya *"Tutup NCR ini secara administratif? Setelah ditutup tidak
dapat diubah lagi."*

1. **`Tambah NCR`**. Isi **Proyek** (wajib, hanya saat membuat), **Inspeksi asal**
   (opsional — bila diisi, **Tahap** dan **Lokasi** NCR terisi otomatis dari inspeksi itu),
   **Lokasi**, **Tahap**, **Uraian ketidaksesuaian** (wajib), **Akar masalah**, **Tindakan
   koreksi**, **Tindakan pencegahan**, penanggung jawab, dan **Batas waktu**.
2. **Penanggung jawab tepat satu:** isi **karyawan sendiri** ATAU **subkontraktor**, tidak
   keduanya dan tidak kosong. Keduanya menyalahkan semua orang dan tak seorang pun.

Yang akan menahan Anda:

- Bukan tepat satu penanggung jawab: *"Isi tepat satu penanggung jawab: karyawan sendiri
  ATAU subkontraktor, tidak keduanya dan tidak kosong."*
- Tahap kosong pada NCR mandiri: *"Tahap NCR wajib diisi bila tidak mengacu pada inspeksi."*
- Inspeksi asal proyek lain: *"Inspeksi yang dirujuk berada pada proyek lain dan tidak dapat
  menjadi asal NCR ini."*
- Lokasi bukan milik proyek: *"Lokasi yang dipilih bukan bagian dari proyek NCR ini."*
- Perpindahan status yang salah: *"NCR {kode} berstatus {status} dan tidak dapat {tindakan}
  dari status itu."* (tindakan: memulai perbaikan / memverifikasi / menutup)
- NCR yang sudah ditutup: *"NCR {kode} sudah ditutup dan tidak dapat diubah lagi."*

**Dua pintu yang ditahan NCR terbuka.** Selama status masih **Terbuka** atau **Perbaikan
berjalan**, NCR itu (a) menahan inspeksi tahap berikutnya di lokasinya (§17.2), dan (b)
menahan **BAST I** proyeknya — serah terima pertama tidak dapat disetujui selama ada NCR
terbuka. Yang kedua muncul di layar BAST sebagai:

> *"BAST I {kode} belum dapat disetujui — {n} NCR masih terbuka ({daftar NCR}); verifikasi
> atau tutup dahulu sebelum serah terima pertama."*

(BAST II punya prasyaratnya sendiri — bab persetujuan proyek, §7 — dan tidak digerbangi NCR;
NCR menjaga **pintu masuk** masa pemeliharaan, bukan pintu keluarnya.)

### 17.4 Benda Uji Beton — `Mutu › Benda Uji Beton`

Serah-simpan benda uji sebuah pengecoran (FM-10-24) dan hasil uji tekannya (FM-10-23). Kolom
daftar: Tgl Cor · Mutu · Target fc' (MPa) · No. Truk · Volume · Jml.

1. **`Tambah Benda Uji`**. Isi **Proyek** (wajib, hanya saat membuat), **Lokasi**, **Tanggal
   cor**, **Mutu** (mis. `K-350`), **Slump**, **No. truk mixer**, **Volume**, **Jumlah benda
   uji**.
2. Isi baris **Hasil uji tekan**: **Umur** (7 / 14 / 28 hari), **Kekuatan (MPa)**,
   **Laboratorium**, **Tanggal uji**. Kolom **Memenuhi** tidak Anda isi — server
   menghitungnya saat menyimpan, dan menghitung ulang seluruh baris tiap kali Anda menyimpan
   perubahan.

**Bagaimana lulus/tidak dihitung — dan sumbernya.** Mutu ditulis dua cara: `K-xxx` (kekuatan
karakteristik **kubus** dalam kg/cm², konvensi PBI 1971) atau `fc'-xx` (kuat tekan
**silinder** dalam MPa, konvensi SNI 2847:2019). Karena SNI menilai keberterimaan pada fc'
silinder, sebuah mutu-K dikonversi lebih dulu:

- kg/cm² → MPa: **× 0,0980665**
- kubus → silinder: **× 0,83** (fc' silinder ≈ 0,83 × σbk kubus, konversi PBI yang lazim)
- contoh: **K-350** → 350 × 0,0980665 × 0,83 = **28,49 MPa** (target fc' 28 hari)

Keberterimaan ada pada fc' **28 hari**. Benda uji yang dipecah lebih awal dibandingkan
terhadap pecahan target menurut tabel kematangan **PBI 1971 (N.I.-2) Tabel 4.1.4** semen
tipe I: **7 hari = 0,65**, **14 hari = 0,88**, **28 hari = 1,00**. Jadi K-350 pada 7 hari
dibandingkan terhadap 0,65 × 28,49 ≈ 18,52 MPa. Benda uji **memenuhi** bila kekuatannya ≥
target umur itu.

Penolakan yang menjaga kejujuran hitungan:

- Mutu tak terbaca: *"Mutu beton \"{x}\" tidak dikenali; gunakan K-xxx (kubus, kg/cm²) atau
  fc'-xx (silinder, MPa)."*
- Umur di luar tabel: *"Umur uji {n} hari tidak ada pada tabel kematangan PBI 1971 (7, 14,
  atau 28 hari); pass/fail hanya dihitung pada umur baku, bukan ditebak."*

Angka yang tak bisa dihitung ditolak, bukan ditebak — menebak rasio umur adalah persis
pendapat yang layar ini ada untuk menjauhkannya dari lembar bertanda tangan.

### 17.5 Template Inspeksi — `Mutu › Template Inspeksi`

Pustaka checklist: satu template adalah satu lembar titik henti mutu (kode katalog `Q1…Q31`,
mis. `Q7` "Pengecoran kolom struktur"), milik satu paket pekerjaan dan satu **tahap**
(sebelum / saat / setelah). Butirnya adalah baris yang ditandai inspektor `ok/nok/na` di
lapangan.

1. **`Tambah Template Inspeksi`**. Isi **Kode katalog** (mis. `Q7`, unik), **Paket
   pekerjaan**, **Tahap (titik henti mutu)**, lalu baris **Butir pemeriksaan**: **Butir yang
   diperiksa**, **Kriteria keberterimaan**, **Toleransi** (boleh kosong).
2. **Kode `Q1…Q31` milik kantor mutu**, bukan nomor dokumen yang dicetak sistem — sama
   seperti kode AHSP. Karena itu seluruh pustaka bisa **diimpor massal** lewat **Impor
   Data Master** (§2.9): satu berkas memuat banyak template, kolom `kode, paket, tahap`
   untuk kepala dan `butir, kriteria, toleransi` untuk tiap butir; kode yang sudah ada
   **diperbarui**, kode baru **dibuat**.

> **Jangan mengubah butir template yang sudah dipakai inspeksi terisi.** Butir hasil sebuah
> inspeksi menunjuk butir template aslinya; mengganti butir yang sudah dirujuk belum
> didukung. Bila sebuah checklist perlu berbeda, **buat template baru** (kode baru) — jangan
> sunting butir template lama yang sudah terpakai. Formulir ini mengingatkannya di catatan
> bawahnya.

### 17.6 Mencetak

Tiga formulir rumah, semua kop empat pihak, dicetak dari tombol **`Cetak`** di halaman
daftar/detailnya (§13):

| Tombol | Formulir | Dari layar |
|---|---|---|
| `Cetak Inspeksi Mutu (QCI)` | F/QI Lembar Inspeksi Mutu | Inspeksi Mutu |
| `Cetak Ketidaksesuaian (NCR)` | F/NCR Laporan Ketidaksesuaian | Ketidaksesuaian |
| `Cetak Benda Uji Beton` | F/BU Benda Uji Beton & Hasil Uji | Benda Uji Beton |

Aturan kejujuran berlaku penuh (§13.5): F/QI mengosongkan sel **HASIL KESELURUHAN** selama
lembar belum punya butir hasil (bukan mencetak LULUS pada checklist kosong); F/NCR
mengosongkan kolom verifikasi selama NCR belum diverifikasi; F/BU mencetak kolom
**MEMENUHI** dari hasil hitung yang tersimpan, dan mengosongkan **TARGET fc'** pada mutu
yang tak terbaca — bukan menebak. Kolom tanda tangan Konsultan MK tetap kosong bila kolom
Konsultan pada proyek belum diisi (§7.2).

---

*Panduan ini menjelaskan Nusantara ERP sebagaimana layarnya berperilaku hari ini. Bila
sebuah layar bertindak berbeda dari yang tertulis di sini, layarnyalah yang benar —
laporkan selisihnya supaya halaman ini diperbaiki. Untuk segala hal yang bersifat
administratif, rujukannya `docs/PANDUAN-ADMINISTRATOR.md`.*
