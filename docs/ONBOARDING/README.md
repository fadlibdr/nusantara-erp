# Onboarding minggu pertama — indeks dan cara menyerahkannya

Dua belas halaman di folder ini, satu per peran akun, ditulis untuk **minggu pertama
seorang karyawan baru** di Nusantara ERP. Halaman-halaman itu **bukan manual** — manualnya
tetap `docs/PANDUAN-PENGGUNA.md` (disebut **PANDUAN**) dan, untuk administrator,
`docs/PANDUAN-ADMINISTRATOR.md`. Yang dilakukan tiap halaman adalah membuka jalan masuk ke
manual itu: siapa Anda di sistem, apa yang tampil di layar hari pertama, lima sampai
sepuluh pekerjaan yang benar-benar Anda kerjakan, kalimat penolakan yang akan Anda temui,
formulir yang Anda cetak, dan daftar periksa yang bisa dibuktikan di layar.

## Peran, akun demo, panduan, dan bab manualnya

Kolom terakhir adalah bab yang PANDUAN §0 *"Rujukan cepat per peran"* tetapkan untuk peran
itu — panduan onboarding hanya menunjuk ke sana, tidak mengulanginya.

| Pekerjaan | Peran akun | Akun demo | Panduan | Bab PANDUAN (§0) |
|---|---|---|---|---|
| Administrator sistem | `admin` | `admin@nusantara.test` | [admin.md](admin.md) | PANDUAN-ADMINISTRATOR seluruhnya; PANDUAN 1, 2, 14 |
| Direktur | `direktur` | `direktur@nusantara.test` | [direktur.md](direktur.md) | 1, 2, 14, lalu bab dokumen yang disetujui; 15 untuk tanda tangan MK/Owner |
| Manajer keuangan | `finance-manager` | `finance-manager@nusantara.test` | [finance-manager.md](finance-manager.md) | 1, 2, 14, lalu §3.11, §5.9, §5.10, §10.2 |
| Petugas keuangan (AR, AP, kas) | `finance` | `finance@nusantara.test` | [finance.md](finance.md) | 1, 2, 10 (+ §3.10–§3.14, §5.9–§5.10) |
| Sales / pemasaran | `sales` | `sales@nusantara.test` | [sales.md](sales.md) | 1, 2, 3 — berhenti di §3.9 |
| Estimator / drafter | `estimator` | `estimator@nusantara.test` | [estimator.md](estimator.md) | 1, 2, 4, 16 |
| Petugas pengadaan / pembelian | `procurement` | `procurement@nusantara.test` | [procurement.md](procurement.md) | 1, 2, 5 (+ §16.4, §4.7) |
| Penjaga gudang / logistik | `warehouse` | `warehouse@nusantara.test` | [warehouse.md](warehouse.md) | 1, 2, 6 (+ §16.5) |
| Manajer proyek | `project-manager` | `project-manager@nusantara.test` | [project-manager.md](project-manager.md) | 1, 2, 7, 8, 16, 17 (+ §4.4; bab 9 bila memegang alat; 15) |
| Site manager / pengawas lapangan | `site-manager` | `site-manager@nusantara.test` | [site-manager.md](site-manager.md) | 1, 2, 7 (§7.3–§7.6), §9.5, 16, 17 |
| Petugas SDM / payroll | `hr` | `hr@nusantara.test` | [hr.md](hr.md) | 1, 2, 11 |
| Teknisi servis | `teknisi` | `teknisi@nusantara.test` | [teknisi.md](teknisi.md) | 1, 2, 12 (+ §7.4 lewat `#/lapangan`, §6.1) |

Dua baris PANDUAN §0 tidak punya panduan sendiri karena bukan peran akun: **kasir kas
kecil** adalah *pemegang laci* yang ditunjuk pada data kas kecil (PANDUAN §10.5–§10.7;
dibahas di [finance.md](finance.md) butir 8), dan **admin alat berat / aset** adalah
`project-manager` atau `admin` yang memegang modul Aset (PANDUAN bab 9; dibahas di
[project-manager.md](project-manager.md)).

Nama pemegang akun demo (Budi Santoso, Rina Wijaya, dan seterusnya) adalah nama seeder,
bukan orang sungguhan di perusahaan. Kata sandinya ada pada administrator — bukan di
halaman ini (PANDUAN-ADMINISTRATOR §5.9; keputusan rotasinya menunggu pemilik, §12).

## Kerangka yang sama di setiap panduan

Tujuh bagian, selalu dengan urutan dan nomor yang sama, supaya atasan yang membaca dua
panduan berbeda tahu di mana mencari apa:

1. **Siapa Anda di sistem** — peran, akun demo, pekerjaan dalam satu kalimat, tempat
   peran itu pada rantai proses (ANALISIS-PROSES-BISNIS-2026-09 §1), siapa menyerahkan
   pekerjaan kepada Anda dan kepada siapa Anda menyerahkannya.
2. **Hari pertama** — masuk, sidebar *untuk peran ini* (bukan 121 layar), kartu dasbor,
   tenggat yang benar-benar ditujukan kepada peran ini, dan enam kalimat PANDUAN §0.
3. **Pekerjaan Anda** — walkthrough bernomor: pemicu → layar → nomor dokumen → siapa yang
   menyetujui atau apa yang terjadi berikutnya → pasal PANDUAN.
4. **Yang akan menolak Anda** — kalimat penolakan server, kata demi kata bila manual
   mengutipnya, dan tombol yang memang tidak digambar untuk peran ini.
5. **Formulir yang Anda cetak** — formulir rumah yang izin peran ini gambarkan, dengan
   kode F/-nya, dan aturan kejujuran: sel bergaris kosong berarti *tidak tercatat*, bukan
   nol (PANDUAN §13.5).
6. **Daftar periksa minggu pertama** — 8–12 butir yang bisa dibuktikan: nomor dokumen
   yang terbit, lencana status, nama penyetuju.
7. **Bila tersangkut** — kepada siapa bertanya per situasi, dan eskalasi dua baris
   (PANDUAN §14.5).

Setiap panduan ditutup kotak **"Yang berubah pada rilis UX berikutnya"**: cabang
`ux/p0-measured` yang **belum digabung dan belum tayang di erp1**. Yang berlaku hari ini
adalah `main`; kotak itu ada supaya karyawan baru tidak mencari layar Tugas Saya atau
tombol ganti kata sandi yang belum ada.

## Cara menyerahkan panduan kepada karyawan baru

1. **Administrator membuat akunnya lebih dulu** — `Sistem › Pengguna › Tambah Pengguna`:
   nama, email, kata sandi, **peran**, dan **Karyawan terkait** bila orangnya ada di master
   karyawan (PANDUAN-ADMINISTRATOR §3.4). Tanpa tautan karyawan, sakelar *Proyek saya* dan
   slip gaji tidak menemukan orangnya.
2. **Serahkan satu panduan — panduan perannya.** Cetak berkasnya, atau kirim tautannya di
   repositori (`docs/ONBOARDING/<peran>.md`). Jangan menyertakan panduan peran lain "untuk
   gambaran": tiap panduan hanya menggambarkan tombol yang peran itu punya, dan tombol
   peran lain akan dicari-cari lalu dilaporkan sebagai kerusakan.
3. **Satu jam latihan dengan akun demo peran itu** sebelum akun sendiri dipakai untuk
   dokumen sungguhan. Akun demo **bukan lingkungan terpisah** — ia masuk ke basis data
   erp1 yang sama dengan akun kerja. Maka: awali judul dokumen latihan dengan kata
   *LATIHAN*, hapus selagi masih Draf, minta `Tolak` untuk yang telanjur diajukan, dan
   **jangan menekan tombol posting apa pun** dengan akun demo — yang terposting tidak bisa
   dihapus (PANDUAN §14.4).
4. **Hari pertama dengan akun sendiri**: karyawan baru membuka bagian 2 panduannya dan
   membandingkannya dengan sidebar yang tampil. Bila kelompoknya kurang atau lebih dari
   yang digambarkan, peran akunnya berbeda dari yang dimaksud — minta administrator
   memeriksa `Sistem › Pengguna`, jangan mencari tombolnya.
5. **Akhir minggu**: atasan dan karyawan baru membuka bagian 6 bersama. Setiap butir
   dirancang bisa dibuktikan di layar (nomor dokumen, lencana status, nama penyetuju);
   butir yang tidak bisa dibuktikan belum selesai.

## Aturan yang mengikat setiap panduan

**Sebuah panduan hanya menggambarkan apa yang izin perannya bolehkan dilihat dan
dilakukan.** Peran bawaan dan izinnya ditetapkan seeder (PANDUAN-ADMINISTRATOR §3.2);
sidebar dibaca dari izin yang sama (PANDUAN §1.4); tombol persetujuan hanya untuk peran
pemegang `<modul>.approve`; baris Tenggat hanya untuk peran pemegang izin yang ditetapkan
tiap pengawas (PANDUAN §1.7); tombol cetak hanya untuk izin lihat modul formulir itu
(PANDUAN §13.3). Peran yang tidak menyetujui apa pun mengatakannya terus terang dan
menyebut siapa yang menyetujui; peran yang tidak menerima tenggat mengatakannya juga.

Dari aturan itu ikut aturan penulisannya: tidak ada layar, tombol, atau angka yang
dibayangkan; kalimat penolakan dikutip dari teks server; ambang rupiah yang disebut hanya
yang ada di `config/erp.php` — PO Rp 100 juta dan SPK Rp 200 juta ke atas butuh direktur,
tangga keputusan pemenang Rp 100 juta / Rp 1 miliar. Bila perusahaan mengubah izin sebuah
peran di `Sistem › Peran & Hak Akses`, panduan peran itu ikut usang dan harus ditulis
ulang dari sumber yang sama: seeder peran, sidebar (`public/app/js/schema.js`), registri
dokumen berpersetujuan, pengawas tenggat, dan registri formulir cetak.
