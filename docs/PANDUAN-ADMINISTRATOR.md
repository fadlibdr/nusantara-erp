# Panduan Administrator — Nusantara ERP

**PT Nusantara Karya Integrasi** · disusun 10 Agustus 2026, dimutakhirkan 22 Agustus
2026 · untuk pemegang peran `admin` yang juga memegang akses shell ke server

> Panduan ini menjelaskan cara **menjalankan** ERP ini hari demi hari: siapa boleh
> apa, apa yang harus disiapkan sebelum orang bekerja, perintah apa yang jalan
> sendiri dan mana yang harus diketik, bagaimana menutup buku, dan tindakan mana
> yang **tidak bisa dibatalkan**.
>
> **Aturan yang mengikat dokumen ini** — sama dengan aturan yang mengikat formulir
> cetaknya: yang ditulis di sini hanyalah **apa yang benar-benar dilakukan kode**.
> Tidak ada perilaku yang "seharusnya", tidak ada tombol yang "nanti ada". Bila
> sebuah kemampuan tidak ada, ia ditulis sebagai tidak ada. Bila sebuah prosedur
> belum pernah ditulis siapa pun, ia ditulis **"belum terdokumentasi"** berikut
> nama berkas yang harus dibuka pembaca untuk memastikannya sendiri. Panduan yang
> menjanjikan tombol yang tidak ada lebih buruk daripada halaman yang hilang,
> karena pembacanya percaya justru pada saat ia sedang buntu.

---

## Daftar isi

0. [**Rujukan cepat**](#rujukan-cepat--satu-halaman-untuk-hari-ketika-ada-yang-salah)
   — satu halaman untuk hari ketika ada yang salah
1. [Untuk siapa panduan ini](#1-untuk-siapa-panduan-ini-dan-apa-yang-tidak-ada-di-sini)
2. [Gambaran sistem — dua belas modul](#2-gambaran-sistem--dua-belas-modul)
3. [Akses & peran](#3-akses--peran)
4. [Penyiapan awal](#4-penyiapan-awal)
5. [Rutinitas & alarm](#5-rutinitas--delapan-perintah-dan-sistem-alarm)
6. [Tutup buku bulanan](#6-tutup-buku-bulanan)
7. [Tutup buku tahunan](#7-tutup-buku-tahunan)
8. [Siklus hidup dokumen](#8-siklus-hidup-dokumen--dan-apa-yang-tidak-bisa-dibatalkan)
9. [Mencetak formulir rumah](#9-mencetak-formulir-rumah)
10. [Cadangan & pemulihan](#10-cadangan--pemulihan)
11. [Pemecahan masalah](#11-pemecahan-masalah)
12. [Keputusan yang menunggu pemilik](#12-keputusan-yang-menunggu-pemilik)

---

## Rujukan cepat — satu halaman untuk hari ketika ada yang salah

Halaman ini **tidak memuat satu pun fakta baru**. Ia pintu masuk: setiap barisnya
menyebut bab yang menjelaskannya, dan bab itulah yang mengikat bila keduanya terbaca
berbeda.

**Bentuk baku setiap perintah** (§1) — pohon kerja `/root/construction-erp` **bukan**
situs hidup, dan artisan sebagai root di pohon produksi membuat situs menjawab
`attempt to write a readonly database`:

```bash
cd /var/www/erp1.pi2.co.id
```

```bash
sudo -u www-data env HOME=/tmp php artisan <perintah>
```

**Delapan perintah** (§5.1). Enam berjalan lewat satu baris `schedule:run` di
`/etc/cron.d/erp1`; dua tidak pernah terjadwal:

| Perintah | Jadwal | Dijelaskan di |
|---|---|---|
| `fin:ensure-calendar` | 05:30 WIB harian | §5.3 |
| `ast:accrue-plant` | 05:40 WIB harian | §5.4 |
| `svc:generate-pm` | 06:00 WIB harian | §5.5 |
| `erp:backup-watch` | 08:00 WIB harian | §5.6 |
| `fin:close-watch` | 08:15 WIB harian | §5.7 |
| `erp:deadline-watch` | 08:30 WIB harian | §5.8 |
| `erp:harden-demo-logins` | tangan saja — butuh TTY | §5.9 |
| `erp:inventory-method-check` | tangan saja | §4.7 |

Ketiga jadwal **cadangan** berjalan sebagai root dari berkas cron yang sama — 02:15 WIB
malam, 13:15 WIB retry offsite, 03:30 WIB restore drill tanggal 2 — dan semuanya
dipanggil lewat `bash …/deploy/backup-erp1.sh`, bukan langsung (§5.2, §10.2).

**Lima butir yang MEMBLOKIR tutup buku** (§6.3) — enam butir lain hanya peringatan yang
boleh dilewati dengan alasan tertulis:

**1** periode sudah berakhir · **2** periode sebelumnya sudah ditutup · **6** tidak ada
dokumen menggantung (§6.4) · **7** run PSAK 115 bulan itu sudah diposting · **8** neraca
saldo seimbang.

**Yang tidak bisa dibatalkan** — dibaca sebelum menekan, bukan sesudah:

- **Penutupan bulan, pada perusahaan ini** (§6.1). Begitu satu run PSAK 115 terposting,
  bulan itu **dan setiap bulan sebelumnya** tidak bisa dibuka kembali, selamanya.
- **Menyetujui payroll ADALAH memposting ke buku besar**, dalam satu transaksi (§8.2).
- **Persetujuan** — satu pengecualian, dan hanya satu: penawaran yang belum dimenangkan
  bisa dikembalikan ke draf lewat aksi **Revisi** (`QuotationService::revise`), yang
  menaikkan nomor revisi. Untuk **jenis dokumen lain tidak ada batal-setuju**; yang ada
  hanyalah dokumen baru (§8.4 Kategori C).
- **Jurnal terposting, opname, run penyusutan, pelepasan aset, demobilisasi, pelepasan
  retensi pelanggan, pengesahan laporan lapangan** — tidak ada batal (§8.4 Kategori C).
- **Retur material dan retur pembelian yang sudah diposting** — tidak ada pembatalan;
  koreksinya bon atau GRN berlawanan (§8.2).
- **Penghapusan** adalah soft delete, dan **tidak ada layar yang memulihkannya**
  (§8.4 Kategori D, §8.8).
- Yang **bisa** dibatalkan, masing-masing dengan syaratnya: invoice AR, tagihan AP,
  pembayaran, voucher kas kecil, penerimaan barang, bon material (§8.4 Kategori A dan B).

**Ke mana alarm keluar** (§5.10): **hanya lonceng di header aplikasi**, polling 90 detik.
Email mati secara bawaan; WhatsApp tidak ada. Keluaran CLI keenam perintah terjadwal
dibuang ke `/dev/null` (§5.2), jadi baris SKIP dan **BLIND** hanya terbaca bila
perintahnya dijalankan tangan. Kotak masuk `admin` menerima **setiap** kelompok alarm
sekaligus, tanpa penyaringan.

**Empat kegagalan tersering, dan pemeriksaan yang memastikan tiap-tiapnya** (§11.1):

| Gejala | Pemeriksaan yang memastikannya |
|---|---|
| Perintah artisan sukses, tetapi tidak ada yang berubah di erp1.pi2.co.id | `pwd` — harus `/var/www/erp1.pi2.co.id`, bukan pohon sumber (§1) |
| Situs menjawab `attempt to write a readonly database` | `ls -la /var/www/erp1.pi2.co.id/database/` — cari berkas `-wal`/`-shm` milik `root:root`, hapus, lalu ulangi sebagai `www-data` (§1) |
| Bulan tidak mau ditutup | Layar tutup buku sudah menyebut butir yang gagal — jangan menebak (§6.3). Bila sebabnya dokumen menggantung yang sudah **diajukan**: **tolak dulu** (`fin.approve`), baru sunting atau hapus (§6.4) |
| Tombol cetak hilang di sebuah layar | Katalog cetak di-cache seumur sesi browser dan hanya disegarkan saat **login** — suruh yang bersangkutan keluar-masuk sebelum menyimpulkan tombolnya tidak ada (§9.2) |

---

## 1. Untuk siapa panduan ini, dan apa yang tidak ada di sini

Pembacanya satu orang: **pemegang peran `admin`** — akun yang memegang seluruh 74
izin sistem, sekaligus orang yang bisa masuk ke server lewat shell. Anda diasumsikan
paham konstruksi dan akuntansi, dan **tidak** diasumsikan paham Laravel. Di mana
sebuah fakta hanya bisa dipastikan dengan membuka berkas kode, nama berkasnya
disebutkan supaya Anda bisa memeriksanya atau menyerahkannya kepada pengembang.

**Yang TIDAK ada di panduan ini, dan ada di tempat lain:**

| Kalau Anda mencari | Bukalah |
|---|---|
| Membangun server, nginx, TLS, skrip deploy, cadangan tingkat mesin, urutan menurunkan gerbang demo erp1 | [`DEPLOYMENT.md`](DEPLOYMENT.md) — berbahasa Inggris, 437 baris. §3 pemasangan pertama, §4 rilis rutin, §5 cadangan, §7.1 gerbang demo |
| Kebijakan pengakuan pendapatan PSAK 115 — persentase penyelesaian, basis biaya, liabilitas kontrak | [`KEBIJAKAN-PENDAPATAN.md`](KEBIJAKAN-PENDAPATAN.md) |
| Aturan penulisan kode, konvensi modul, struktur front-end | [`CONVENTIONS.md`](CONVENTIONS.md), [`ARCHITECTURE.md`](ARCHITECTURE.md), [`FRONTEND.md`](FRONTEND.md) — untuk pengembang, bukan untuk pembaca ini |
| Riwayat audit, temuan, dan apa yang sengaja tidak dibangun | [`ASSESSMENT-LANJUTAN.md`](ASSESSMENT-LANJUTAN.md), [`LAPORAN-DEVIASI.md`](LAPORAN-DEVIASI.md) |

Panduan ini **menunjuk** ke berkas-berkas itu dan hanya mengulang bagian yang harus
Anda kerjakan sendiri sebagai administrator aplikasi.

### Menjalankan perintah di server — baca ini sebelum mengetik apa pun

Ini perangkap pertama dan yang paling mahal, karena tidak menimbulkan pesan salah
satu pun.

**Pohon kerja bukan situs hidup.** `/root/construction-erp` adalah **sumber**.
Situs hidup adalah **salinan** di `/var/www/erp1.pi2.co.id`, dengan
`database/database.sqlite` miliknya sendiri: `deploy/sync-erp1.sh:34` sengaja
mengecualikan berkas itu dari penyalinan (`--exclude='database/database.sqlite'`), dan
alasannya berdiri di kepala skrip yang sama, `deploy/sync-erp1.sh:6-8` — *"The live site
is a COPY, not a symlink, so that a half-finished edit in the source tree is never
served."*
Penjadwal cron pun berjalan dengan `cd /var/www/erp1.pi2.co.id` (`/etc/cron.d/erp1`).
Perintah yang diketik dari `/root/construction-erp` **berhasil**, mencetak angka, dan
tidak mengubah apa pun di erp1.pi2.co.id.

**Jalankan sebagai `www-data`, bukan sebagai root.** Basis data produksi dan
direktorinya dimiliki `www-data` (`drwxrwx--- www-data:www-data`) dan berada dalam
mode WAL. Artisan yang dijalankan sebagai root di pohon produksi meninggalkan berkas
sisi `-wal`/`-shm` milik `root:root`, dan php-fpm — yang berjalan sebagai `www-data`
— tidak bisa lagi menulisinya: situs menjawab `attempt to write a readonly database`
sampai berkas itu dihapus.

Bentuk baku setiap perintah di panduan ini, dan `HOME=/tmp` bukan hiasan — tanpanya
psysh/tinker gagal menulis `/var/www/.config/psysh`:

```bash
cd /var/www/erp1.pi2.co.id
```

```bash
sudo -u www-data env HOME=/tmp php artisan fin:ensure-calendar
```

> **Belum terdokumentasi di tempat lain.** `DEPLOYMENT.md` tidak menyebut `www-data`
> sama sekali; kebiasaan ini hidup di catatan proyek dan baru dituliskan di sini.
> Dua berkas yang membuktikannya, dan yang harus Anda buka bila ragu:
> `/etc/cron.d/erp1` (siapa yang menjalankan penjadwal) dan
> `ls -la /var/www/erp1.pi2.co.id/database/` (siapa yang memiliki basis datanya).

---

## 2. Gambaran sistem — dua belas modul

Satu monolit modular. Dua belas modul, masing-masing dengan prefiks izin sendiri;
di sidebar, Core dan Iam bergabung menjadi satu grup **Sistem**.

**Core (`core`) — Sistem.** Fondasi bersama: profil perusahaan, pengaturan
(`core_settings`), penomoran dokumen, notifikasi (lonceng), log audit, pencarian
global, dasbor, kalender, layar Tenggat, impor data master & dokumen, dan mesin
cetak 40 formulir rumah. Modul ini tidak punya dokumen bisnisnya sendiri; ia yang
dipakai sebelas modul lain.

**Iam (`iam`) — Pengguna & Akses.** Pengguna, peran, izin, login. Tiga rute
otentikasi saja: masuk, keluar, "siapa saya". Tidak ada layanan mandiri kata sandi.

**Crm (`crm`) — Penjualan.** Pelanggan, prospek, penawaran, kontrak beserta jadwal
termin, pekerjaan tambah-kurang (CCO), jaminan & asuransi, analitik win-rate. Tidak
ada satu pun dokumen CRM yang memposting ke buku besar — kontrak adalah komitmen,
bukan jurnal.

**Estimation (`est`) — Estimasi.** AHSP (analisa harga satuan), BOQ/RAB, RAP
(anggaran pelaksanaan), riwayat harga satuan. Dokumen perencanaan; tidak memposting.
RAP-lah yang menjadi pembanding gerbang anggaran saat PO dan SPK diajukan.

**Projects (`prj`) — Proyek.** Daftar proyek, laporan harian & progres mingguan,
EVM & baseline, milestone, BAST, register K3, register defect (punch list), varian
material, penugasan personel, penutupan proyek. Sumber angka AC pada EVM adalah
`fin_project_costs`, bukan buku besar.

**Procurement (`prc`) — Pengadaan.** Vendor & subkon, dokumen vendor (prakualifikasi),
permintaan pembelian (PR), RFQ/banding penawaran, pesanan pembelian (PO), baris PO
terbuka, evaluasi vendor. PO adalah komitmen — tidak memposting jurnal.

**Inventory (`inv`) — Persediaan.** Saldo stok, item & kategori, gudang, penerimaan
(GRN), pengeluaran/bon material, transfer antar gudang, opname. Di bawah metode
perpetual, penerimaan dan pengeluaran memposting ke buku besar; transfer tidak
pernah memposting apa pun.

**Subcontract (`scm`) — Subkontrak.** SPK subkon, addendum SPK, opname subkon
(progress claim), uang muka subkon, dan pelepasan retensi subkon. Opname yang
disetujui menerbitkan tagihan vendor di Keuangan.

**Finance (`fin`) — Keuangan.** Invoice termin (AR), tagihan vendor (AP), pembayaran,
kas kecil & kasbon, jurnal, biaya proyek, termin siap ditagih, piutang retensi,
pengakuan pendapatan PSAK 115, periode fiskal, laporan keuangan, buku besar, ekspor
pajak, kalender pajak, ekualisasi pajak, rekonsiliasi bank, bagan akun, pajak,
rekening bank. Seluruh nilai yang sampai ke buku besar melewati satu pintu:
`JournalService`.

**HrPayroll (`hr`) — SDM & Payroll.** Karyawan, sertifikat & PKWT, cuti & izin,
absensi harian, rekap absensi, payroll. **Menyetujui run payroll = memposting ke
buku besar**, dalam satu transaksi yang sama.

**ServiceDesk (`svc`) — Layanan.** Tiket, tiket lewat SLA, kontrak layanan, jadwal
preventif, berita acara lapangan. Tiketnya tidak punya tahap persetujuan — berjalan
lewat assign/resolve/close — tetapi berita acara lapangan punya: `submit` lalu
`acknowledge`, dan acknowledge itulah yang menggerakkan suku cadang keluar dari gudang
(§8.2).

**Assets (`ast`) — Aset.** Daftar aset, kategori aset, mobilisasi ke proyek,
log BBM & jam alat (register pembacaan — tidak memposting apa pun; §12(c)),
perawatan, run penyusutan, utilisasi. Juga tidak punya tahap persetujuan: run
penyusutan berjalan draf → posting.

---

## 3. Akses & peran

### 3.1 Skema izin: 74 izin

Nama izin selalu `<prefiks modul>.<aksi>`. Dua belas prefiks —
`core, iam, crm, inv, ast, est, prj, prc, scm, hr, fin, svc` — dikali enam aksi —
`view, create, update, delete, approve, post` — menghasilkan 72 izin, ditambah dua
izin direktur `prc.approve-director` dan `scm.approve-director`. **Total 74**, dan
tabel `permissions` pada basis data hidup berisi tepat 74 baris.

Kedua izin direktur sengaja tidak dimekarkan ke dua belas prefiks. Alasannya ditulis
di `Modules/Iam/Database/Seeders/PermissionSeeder.php:21-31`: memekarkannya akan
mencetak sepuluh izin yang tidak diperiksa kode mana pun, dan "izin yang tidak
diperiksa, di layar peran, terbaca sebagai kontrol yang ada". Hanya PO dan SPK yang
menstempel `needs_director_approval`, jadi hanya dua izin itu yang dicetak.

**Arti tiap kata kerja.** Polanya seragam di seluruh aplikasi:

| Aksi | Artinya di aplikasi ini |
|---|---|
| `view` | Membaca — tetapi lihat §3.7: hanya sebagian modul yang benar-benar menggerbangi GET-nya |
| `create` | Membuat draf baru |
| `update` | Mengubah draf **dan mengajukan** dokumen untuk disetujui. Ke-18 rute `submit` di seluruh aplikasi digerbangi `.update`, bukan `.create` dan bukan `.approve` |
| `approve` | Menyetujui **dan menolak**. Di Keuangan juga: memposting JV manual, dan membuka kembali periode fiskal |
| `post` | Menulis ke buku besar atau menggerakkan stok — dan membatalkan/membalik apa yang sudah tertulis |

Bahwa **mengajukan itu `update`** adalah hal yang paling sering disalahpahami.
Alasannya ditulis di `Modules/Finance/Routes/api.php:106-109`: "menyiapkan dokumen
agar dinilai orang lain bukanlah mengesahkannya".

Rantai bakunya karena itu: satu orang **create+update** (menyiapkan dan mengajukan),
orang kedua **approve** (menilai), orang ketiga — atau orang pertama lagi — **post**
(membukukan). `RoleSeeder` sengaja memberi `fin.post` kepada `finance`, bukan kepada
`finance-manager`: "memindahkan posting ke penyetuju hanya membuat penyetuju
mengerjakan pekerjaan tata usaha".

**Tiga belas izin tidak menjaga apa pun.** Penelusuran seluruh `Modules/`, `app/`,
dan `public/app/js` tidak menemukan satu pun pemakaian untuk `core.create`,
`core.delete`, `core.post`, `iam.approve`, `iam.post`, `ast.approve`, `est.post`,
`crm.post`, `prj.post`, `prc.post`, `hr.post`, `svc.approve`, `svc.post`. Semuanya
ada di layar Peran dan bisa dicentang. **Jangan pernah merancang pembagian tugas
berdasarkan salah satu dari ketiga belasnya** — mencentangnya tidak memberi
kemampuan apa pun, mengosongkannya tidak mencabut apa pun.

`core.approve` dipakai, tetapi bukan sebagai gerbang rute: ia adalah **daftar
penerima peringatan cadangan** (`Modules/Core/Console/Commands/BackupWatchCommand.php`).

### 3.2 Dua belas peran

Diverifikasi baris per baris terhadap basis data hidup — isi `role_has_permissions`
cocok persis dengan seeder ditambah lima migrasi (yang kelima, `inv.post` untuk
teknisi, 22 Agustus 2026 — §12(b)); tidak ada peran yang disunting tangan.

| Peran | Izin | Untuk apa |
|---|---|---|
| **admin** | 74 | Seluruh izin sistem |
| **direktur** | 26 | `view` + `approve` kedua belas modul + dua izin direktur. **Tidak punya satu pun `create`, `update`, `delete`, atau `post`** — ia tidak bisa membuat atau membukukan apa pun |
| **project-manager** | 16 | `view/create/update` atas prj, est, scm, inv, ast + `prj.approve` |
| **finance** | 11 | `fin` view/create/update/delete/post; `crm.view`, `prc.view`, `scm.view`, `hr.view`; `ast.view` + `ast.post` |
| **estimator** | 7 | `est` view/create/update/delete/post + `prj.view` + `crm.view` |
| **procurement** | 7 | `prc` view/create/update/delete/post + `inv.view` + `est.view`. **Tidak** memegang `prc.approve` |
| **sales** | 7 | `crm` view/create/update/delete/post + `prj.view` + `svc.view` |
| **hr** | 6 | `hr` view/create/update/delete/post + `iam.view` |
| **finance-manager** | 5 | `fin.view`, `fin.approve`, `crm.view`, `prc.view`, `scm.view` |
| **site-manager** | 5 | `prj.view/create/update` + `inv.view/create` |
| **warehouse** | 5 | `inv` view/create/update/delete + `prj.view`. **Tidak memegang `inv.post`** — yang sejak 22 Agustus justru dipegang teknisi; regangan itu dicatat di §12(b) |
| **teknisi** | 5 | `svc.view/create/update` + `inv.view` + **`inv.post`** (keputusan pemilik 22 Agustus 2026 — §12(b)) |

Tiga rasional dari seeder yang perlu Anda bawa saat menata ulang peran:

- **`finance` tidak memegang `fin.approve`.** Sebelum pemisahan ini, satu peran
  memegang create+approve+post pada setiap dokumen keuangan, "sehingga satu login
  bisa menerbitkan tagihan vendor kepada vendor pilihannya sendiri, menyetujuinya,
  dan mencairkannya — jalur fraud satu orang yang ditulis auditor eksternal sebagai
  kelemahan material".
- **`finance` memegang `ast.view` + `ast.post` tetapi bukan `ast.create/update`.**
  Memposting run penyusutan bulanan adalah langkah tutup buku milik finance; register
  aset tetap di sisi proyek, "supaya yang mencatat aset dan yang memposting
  penyusutannya tetap dua orang berbeda".
- **`hr` tidak memegang `hr.approve`.** Di HR, menyetujui **adalah** memposting —
  `PayrollRunController::approve` membukukan seluruh run ke buku besar dalam transaksi
  yang sama. Maker-checker sendirian sempat menjaga jalur itu, "tetapi maker-checker
  hanya sejauh satu setelan yang bisa dimatikan".

**Siapa yang bisa menyetujui apa, pada instalasi baku:**

| Izin approve | Pemegang aktif |
|---|---|
| `fin.approve` | direktur, admin — **dan `finance-manager`, yang di erp1 tidak dipegang siapa pun** |
| `prj.approve` | direktur, project-manager, admin |
| `crm`, `est`, `prc`, `scm`, `hr`, `inv` `.approve` | direktur dan admin **saja** |
| `ast.approve`, `svc.approve`, `iam.approve`, `core.approve` | direktur dan admin — tetapi tidak ada rute yang memeriksa ketiga yang pertama |

Konsekuensinya nyata: penawaran, BOQ/RAP, PR/PO, SPK/addendum/opname subkon,
cuti/payroll, dan opname stok **semuanya menumpuk di satu login direktur**.

> **Peran `finance-manager` ada dan berisi 5 izin, tetapi tidak seorang pun
> memegangnya.** Dataset erp1 disemai sebelum baris yang membuat penggunanya ada.
> Peran tanpa pengguna **boleh dihapus** — `RoleController::destroy` hanya menolak
> bila masih ada pemegangnya. Menghapusnya sambil "merapikan" akan menghilangkan
> satu-satunya profil penyetuju keuangan non-direktur yang ada dan mengembalikan
> seluruh persetujuan tagihan ke login direktur — persis keadaan yang
> `RoleSeeder.php:85-91` sebut sebagai cara kontraktor kecil berakhir berbagi kata
> sandi direktur. **Jangan hapus. Isi.**

### 3.3 Peran admin, dan apa artinya bagi pemisahan tugas

Admin memegang 74 dari 74 izin. Setiap kontrol yang dibangun aplikasi ini masih
berlaku bagi admin **kecuali** kontrol yang bentuknya "peran A tidak memegang izin
B". Admin memegang semua B.

Konkretnya: **admin adalah satu-satunya login yang bisa menyetujui sebuah pembayaran
lalu memposting pembayaran yang sama**, karena `post()` tidak memeriksa siapa yang
menyetujui. Yang masih menahan admin adalah maker-checker: kalau admin yang menekan
Ajukan, admin tidak boleh menyetujui dokumen itu.

Peran `admin` dilindungi di API. `RoleController` menolak dengan 422 "Role admin
tidak dapat diubah." / "Role admin tidak dapat dihapus.", dan layar detail peran
menonaktifkan seluruh kotak centang. Penjagaannya berdasarkan **nama** `'admin'`,
jadi peran itu juga tidak bisa diganti nama.

Yang **tidak** dijaga: tidak ada apa pun yang mencegah pembuatan peran **baru** yang
mencentang seluruh 74 izin.

Admin juga satu-satunya pemegang bawaan untuk 15 izin — sebelumnya 16, sampai
`inv.post` (8 rute) keluar dari daftar ini pada 22 Agustus 2026 karena teknisi ikut
memegangnya (§12(b)). Dari daftar itu yang benar-benar dipakai kode: `core.update`
(menyimpan Pengaturan dan Profil Perusahaan), **ketiga** izin `iam` administratif —
`iam.create`, `iam.update`, `iam.delete` (`Modules/Iam/Routes/api.php:24, 29, 36`)
— **`scm.post`** (2 rute), dan keempat izin `delete` (`ast`, `prj`, `scm`, `svc`).

> **`iam.post` bukan yang keempat.** Ia nol pemakaian di seluruh basis kode dan sudah
> disebut di §3.1 sebagai salah satu dari tiga belas izin yang tidak menjaga apa pun.
> Mencentangnya pada sebuah peran tidak memberi kemampuan apa pun.

> **Perangkap operasional yang lahir dari itu — dua yang masih nyata hari ini, satu
> selesai 22 Agustus 2026:**
>
> 1. **Pergerakan stok yang menyentuh buku besar tidak bisa diselesaikan orang
>    gudang.** `warehouse` boleh **membuat** penerimaan barang, bon keluar, dan
>    transfer, tetapi tidak boleh **memposting** satu pun — dan direktur juga tidak
>    (ia memegang `inv.approve`, bukan `inv.post`). Pemegang non-admin satu-satunya
>    sejak 22 Agustus adalah **teknisi** (§12(b)) — jadi peran yang ada untuk
>    menggerakkan stok tetap tidak bisa memposting bon yang bisa diposting teknisi
>    lapangan. Regangan itu dicatat, bukan diputuskan.
> 2. **Pencairan uang muka subkon dan pelepasan retensi subkon menuntut `scm.post`
>    DAN `fin.approve` sekaligus.** Pada instalasi baku hanya akun admin yang
>    memenuhi keduanya.
> 3. ~~Menyelesaikan berita acara lapangan yang memakai suku cadang hanya bisa
>    dilakukan admin.~~ **Selesai**: pemilik memberi `teknisi` `inv.post` pada
>    22 Agustus 2026 — teknisi kini mengesahkan kunjungannya sendiri. Harga dan
>    pelebarannya di §12(b).

Satu lagi yang mengejutkan siapa pun yang mengira `fin.post` mencakup semua
pemostingan: **memposting JV manual digerbangi `fin.approve`, bukan `fin.post`.**
Peran `finance` — yang memposting semua pembayaran — tidak bisa memposting jurnal
yang diketiknya sendiri. Itu disengaja.

### 3.4 Pengguna: membuat, memberi peran, menonaktifkan, mengganti sandi

Layarnya: **Sistem → Pengguna** dan **Sistem → Peran & Hak Akses**, keduanya di grup
navigasi Sistem yang digerbangi `iam.view`.

**Membuat pengguna** (butuh `iam.create`). Formulirnya berisi Nama, Email, Kata sandi,
Karyawan terkait, Peran (multiselect), Aktif (default menyala). Kata sandi wajib
minimal **8 karakter** lewat layar; teks polos tidak pernah disimpan.

**Memberi/mengubah peran** (butuh `iam.update`). Lewat tombol pensil pada baris
pengguna.

> **Peran diganti WHOLESALE, bukan ditambah.** SPA selalu mengirim seluruh isian saat
> mengubah, jadi **menyimpan formulir Ubah dengan semua centang Peran kosong akan
> mencabut seluruh peran pengguna itu.** Tidak ada konfirmasi. Dan tidak ada
> penjagaan yang mencegah admin mencabut peran adminnya sendiri lewat formulir Ubah —
> penjagaan diri sendiri hanya ada pada tombol Nonaktifkan. Karena `iam.update` hanya
> dipegang peran admin dan di erp1 hanya ada **satu** akun admin, kesalahan ini
> mengunci administrasi pengguna sepenuhnya. **Sebelum menyunting akun admin
> satu-satunya, buat dulu akun admin kedua.**

Jalan pulang dari kesalahan itu hanya lewat shell, dan **belum terdokumentasi**
sebagai prosedur di mana pun — tidak ada perintah artisan untuk memberi peran. Yang
tersedia adalah tinker:

```bash
sudo -u www-data env HOME=/tmp php artisan tinker
```

lalu di dalamnya, cari penggunanya dan panggil `assignRole('admin')`.

**Menonaktifkan seseorang yang keluar** (butuh `iam.delete`). Tombol tong sampah
berlabel **"Nonaktifkan"**, dengan konfirmasi yang sudah tertulis di layar:
"Nonaktifkan pengguna ini? Semua token API-nya dicabut. Pengguna tidak pernah dihapus
permanen karena id-nya dipakai di dokumen."

Menonaktifkan menyetel `is_active=false` **dan menghapus seluruh token API** pengguna
itu dalam satu transaksi. **Tindakan ini mencabut akses seketika dan tidak dapat
dibatalkan bagi sesi yang sedang berjalan: yang bersangkutan harus masuk lagi.**
Pengguna tidak pernah dihapus keras karena id-nya dirujuk oleh persetujuan dan
dokumen di seluruh ERP; **emailnya tetap terpakai dan tidak bisa dipakai ulang untuk
orang lain.** Anda **tidak bisa** menonaktifkan akun sendiri — 422 "Tidak dapat
menonaktifkan akun sendiri."

**Mengaktifkan kembali** lewat formulir Ubah, mencentang "Aktif". Token yang sudah
dicabut tidak kembali.

**Mengganti kata sandi orang lain**: isi kolom Kata sandi di formulir Ubah lalu
simpan. Mengosongkannya berarti "pertahankan sandi lama".

> **Mengganti kata sandi TIDAK mengeluarkan orang itu dari sesi yang sedang
> berjalan.** Token Sanctum bertahan melewati penggantian sandi — dinyatakan tegas di
> `HardenDemoLoginsCommand.php:127-128`: "siapa pun yang memegang token yang
> diterbitkan selagi akun masih terbuka tetap punya akses sampai token itu dibuang."
> **Bila laptop seseorang hilang, satu-satunya tindakan yang benar-benar memutus
> akses adalah MENONAKTIFKAN akunnya** — itu menghapus seluruh tokennya — lalu
> mengaktifkannya kembali dengan sandi baru.

### 3.5 Sesi dan token

Login memeriksa sandi lalu memeriksa `is_active`; akun nonaktif ditolak 403 "Akun Anda
dinonaktifkan. Hubungi administrator." Bila lolos, satu token diterbitkan dan disimpan
SPA di localStorage.

**Server menerima token di DUA header, dan tidak menolak satu pun.**
`Modules/Iam/Providers/IamServiceProvider.php:38` menyelesaikannya sebagai
`$request->header('X-Api-Token') ?: $request->bearerToken()` — `X-Api-Token` diperiksa
lebih dulu, `Authorization: Bearer <token>` tetap sepenuhnya didukung. Contoh curl
ber-`Authorization: Bearer` di [`DEPLOYMENT.md`](DEPLOYMENT.md) §3.7 karena itu sah dan
memang berjalan.

**Yang memaksa SPA memakai `X-Api-Token` adalah gerbangnya, bukan API-nya.** Gerbang
Basic-auth nginx memiliki header `Authorization`. Browser yang sudah melewati tantangan
Basic melampirkan kredensial itu otomatis, tetapi begitu JavaScript menyetel
`Authorization` pada sebuah fetch, kredensialnya **diganti**, bukan digabung: gerbang
menolak, SPA membaca 401-nya sebagai sesi kedaluwarsa, dan pemakainya keluar sendiri
pada setiap panggilan. Praktisnya bagi Anda: **di balik gerbang pakai `X-Api-Token`
(dan kirim kredensial gerbang terpisah, mis. `curl -u`); tanpa gerbang, keduanya
sama-sama jalan.**

Masa berlaku token: **720 menit (12 jam)** (`SANCTUM_TOKEN_EXPIRATION`).

**`is_active` hanya diperiksa saat login.** Tidak ada middleware yang memeriksanya
ulang pada permintaan berikutnya. Karena itu `UserService` menghapus token begitu
`is_active` berubah menjadi false.

> **Menyunting kolom `is_active` langsung di basis data — bukan lewat layar — akan
> meninggalkan token yang sudah terbit tetap hidup, dan akun "nonaktif" itu punya
> akses API penuh sampai tokennya kedaluwarsa (12 jam).** Nonaktifkan lewat layar.

Hanya tiga hal mencabut token: (1) pengguna menekan Keluar — dan itu **hanya**
mencabut token yang sedang dipakai, bukan token lain milik orang yang sama;
(2) menonaktifkan pengguna — menghapus **semua** tokennya; (3)
`erp:harden-demo-logins`, untuk akun yang diputarnya.

**Perubahan peran tidak langsung terlihat di layar orang yang bersangkutan.** SPA
menyimpan daftar izin di localStorage dan menyegarkannya saat halaman dimuat. Selama
tab lama masih terbuka, menu dan tombol masih menampilkan hak yang lama — meski
server sudah menolak dengan 403. **Suruh yang bersangkutan memuat ulang halaman atau
keluar-masuk.**

**Pembatasan laju**: login 10 percobaan per menit; seluruh API 120 permintaan per
menit per pengguna.

### 3.6 Membuat dan menata peran

Layar **Peran & Hak Akses** menampilkan kolom Peran, Jumlah pengguna, dan Hak akses.
Formulir tambah/ubah **hanya** berisi kolom Nama peran — hak akses diatur dari halaman
detail. Jadi prosedurnya dua langkah:

1. Buat peran (nama saja). **Peran baru selalu lahir tanpa satu pun izin.**
2. Buka barisnya → centang izin per modul → **Simpan Hak Akses**.

Menyimpan **mengganti seluruh set**, bukan menambah. Menghapus peran ditolak selama
masih ada pengguna yang memegangnya: 422 "Role masih dipakai oleh user — lepaskan dulu
dari semua user."

Untuk mengetahui **siapa** yang memegang sebuah peran, halaman detail hanya memberi
jumlah; nama-namanya didapat dari layar Pengguna dengan filter **Peran**.

> **Menjalankan ulang `db:seed` (atau `ProductionSeeder`, yang memuat `RoleSeeder`)
> terhadap basis data hidup akan mengembalikan kedua belas peran bawaan ke bentuk
> semulanya** — `seedRole` memakai `syncPermissions`, yang mengganti, bukan
> menggabungkan. **Setiap penyetelan izin yang Anda lakukan lewat layar pada kedua
> belas peran itu akan hilang tanpa peringatan.** Peran buatan sendiri tidak
> tersentuh karena tidak ada di seeder.
>
> Dan: lima migrasi Iam mencari perannya berdasarkan **nama harfiah** (`'finance'`,
> `'hr'`, `'direktur'`, `'admin'`, `'teknisi'`). Mengganti nama salah satu peran
> bawaan lewat layar akan membuat migrasi perbaikan berikutnya diam-diam menjadi
> no-op pada instalasi ini.

### 3.7 Batas dari apa yang izin lakukan

**`.view` bukan penjagaan baca yang seragam.** Dihitung dari seluruh rute GET:

| Modul | Tergerbang `.view` | Terbuka bagi setiap akun yang sudah masuk |
|---|---|---|
| Finance | 47 | 0 |
| Estimation | 8 | 0 |
| Iam | 5 | 1 (`auth/me`) |
| Projects | 8 | 20 |
| Crm | 7 | 9 |
| Core | 5 | 19 |
| HrPayroll | 4 | 11 |
| Procurement | 2 | 12 |
| Assets | 1 | 12 |
| **Inventory, ServiceDesk, Subcontract** | **0** | **21, 9, 9** |

"Terbuka" berarti middleware-nya hanya `auth:sanctum`. Termasuk di dalamnya
`GET /api/hr/employees`, `GET /api/hr/employees/{id}/payslips`,
`GET /api/hr/payroll-runs/{id}/payslips`, seluruh daftar persediaan, subkontrak,
dan tiket layanan, serta seluruh daftar aset KECUALI satu: register log BBM
(22 Agustus 2026) adalah satu-satunya GET Aset yang tergerbang, dan gerbangnya
`ast.view` **ATAU** `prj.view` — pipa `|` spatie, pemakaian pertama di basis kode
ini (§12(c)).

> **Menyembunyikan menu lewat izin `.view` menyembunyikan LAYARNYA, bukan datanya.**
> Siapa pun yang bisa memanggil API dengan token yang sah bisa membacanya.

Yang `.view` benar-benar kendalikan di semua modul: apa yang tampil di sidebar, dan
apa yang disertakan oleh empat endpoint yang menyaring diri sendiri — pencarian
global, tenggat, kalender, dan ringkasan dasbor.

**Tidak ada pembatasan per baris.** Tidak ada mekanisme "hanya proyek saya" yang
otomatis; satu-satunya penyaringan berbasis identitas adalah toggle opsional "Proyek
saya" di dasbor dan daftar proyek, yang memetakan `users.employee_id` →
`projects.project_manager_id`. Konsekuensinya: **`hr.view` berarti melihat gaji SEMUA
karyawan; `fin.view` berarti melihat seluruh buku besar.** Tidak ada versi "hanya
divisi saya".

Satu efek samping yang perlu Anda ketahui: **peran `hr` memegang `iam.view`.** Bagian
SDM karena itu melihat seluruh daftar pengguna, peran dan hak akses setiap orang, dan
menu Sistem ikut tampil untuknya — termasuk Pengaturan dan Profil Perusahaan, yang
tombol simpannya akan menjawab 403 karena keduanya menuntut `core.update`.

**Kaitan pengguna↔karyawan tidak tervalidasi.** `employee_id` pada `users` adalah
rujukan lintas modul tanpa constraint basis data; lewat SPA praktis aman karena
kolomnya berupa pemilih karyawan, lewat API angka apa pun diterima.

### 3.8 Pemisahan tugas yang benar-benar ditegakkan kode

**1. Maker-checker.** `Modules/Core/Support/SegregationOfDuties.php`. Aturannya: yang
mengajukan dokumen tidak boleh menyetujuinya. Tiga hal yang harus Anda tahu apa
adanya:

- **"Siapa yang mengajukan" diambil dari baris `submitted` TERBARU** di
  `core_approvals`, bukan dari kolom `created_by`. Maker-checker menjaga tindakan
  pengakuan, bukan pengetikan — juru tulis yang mengetikkan tagihan yang diajukan
  orang lain tidak mengakui apa pun. Ini juga membuat tolak-lalu-ajukan-ulang benar:
  bila Alice mengajukan, Budi menolak, lalu Budi mengajukan ulang, **Budi**-lah
  pengajunya sekarang.
- **Pengajuan tanpa aktor tercatat LOLOS.** Ini keadaan yang didokumentasikan dan
  diandalkan `RetentionService`: tagihan pelepasan retensi dicetak mesin dari satu
  tindakan manusia yang rutenya sudah menuntut `scm.post` **dan** `fin.approve`.
- **Karyawan yang sudah keluar tetap dihitung sebagai pengaju.** Filter `is_active`
  sengaja tidak dipasang di sini: pengaju yang mengundurkan diri tidak boleh menjadi
  lubang pada penjagaan.

**Menolak tidak dijaga**, dan itu bukan kelalaian: menolak dokumen sendiri
mengembalikannya ke meja sendiri, tidak memindahkan uang dan tidak mengakui apa pun.

**Setelannya bisa dimatikan** di **Pengaturan → Proyek & Persetujuan**, label "Wajib
pemisahan tugas (maker-checker)", default aktif. Bantuan di layar sudah menyebut
konsekuensinya: "Matikan hanya bila perusahaan memang tidak punya petugas kedua —
riwayat persetujuan tetap mencatat bahwa pengaju dan penyetujunya orang yang sama."
Menyimpan Pengaturan butuh `core.update` = admin saja.

**2. Persetujuan level direktur.** `needs_director_approval` distempel saat pengajuan
terhadap ambang di Pengaturan, dan penjagaannya berupa **izin**
(`prc.approve-director` / `scm.approve-director`), bukan pemeriksaan nama peran —
supaya instalasi yang mengganti nama atau memecah perannya bisa menyerahkan hak itu
kepada siapa pun direkturnya. Keputusan berpijak pada **flag yang tersimpan**, bukan
ambang saat ini, sehingga aturan yang mengikat penyetuju adalah aturan yang
ditunjukkan kepada pengaju. Ia **maju-saja**: dokumen yang terlanjur disetujui sebelum
penjagaan ini ada tetap disetujui. Dan ia berpadu dengan maker-checker.

Ambangnya diubah di **Pengaturan → Proyek & Persetujuan**: "PO wajib persetujuan
direktur di atas" dan "SPK wajib persetujuan direktur di atas".

**3. Gerbang dua izin.** Dua rute menuntut **dua** izin sekaligus: pencairan uang muka
subkon dan pelepasan retensi subkon, keduanya `scm.post` + `fin.approve`. Alasannya:
satu klik di sana mencetak tagihan AP yang **sudah disetujui** dan akan dibayar tanpa
persetujuan lebih lanjut — jadi orang yang id-nya mendarat di baris `approved` tagihan
itu harus benar-benar memegang hak persetujuan AP.

**4. Tutup buku vs buka kembali.** Menutup periode = `fin.post`; membuka kembali =
`fin.approve`. "Siapa pun yang bisa memposting tidak boleh bisa membuka sendiri
periode yang ingin diisinya."

**5. Pemegang kas kecil.** `PettyCashVoucherService::assertCustodian` menolak siapa pun
selain `custodian_id` dana itu — **tanpa pengecualian untuk admin**. Pesannya: "Hanya
pemegang kas kecil {kode} yang dapat {tindakan} — uang tunainya ada di laci pemegang,
bukan di layar orang lain. Bila pemegangnya berganti, ubah dulu pemegang pada data kas
kecilnya." Mengganti pemegang adalah pintu daruratnya, dan itu meninggalkan jejak.

### 3.9 Jejak audit atas perubahan akses — dan tiga lubangnya

**Jejak audit tidak punya layar.** Ia terisi dan dapat dibaca, tetapi hanya lewat API —
caranya ada di §3.10 di bawah. Delapan model dicatat (`Vendor`, `BankAccount`,
`Setting`, `Tax`, `Account`, `User`, `Contract`, `Employee`); `User` termasuk. Hash kata
sandi, token, dan ketiga timestamp (`created_at`, `updated_at`, `deleted_at`) dilarang
menyentuh log — "log audit dibaca lebih banyak orang daripada tabel users".

**Yang TERCATAT**: pembuatan pengguna, perubahan nama/email/`employee_id`, dan
penonaktifan (`is_active` true→false).

> **Yang TIDAK tercatat, dan ini penting:**
>
> - **Perubahan peran seorang pengguna.** `syncRoles()` menulis ke tabel pivot, yang
>   bukan model teramati — **memberi seseorang peran `admin` tidak meninggalkan satu
>   baris pun di jejak audit.**
> - **Perubahan izin pada sebuah peran.** `Role` dan `Permission` tidak ada di daftar
>   model teraudit.
> - **Reset kata sandi.** `password` ada di daftar terlarang, dan bila ia satu-satunya
>   yang berubah maka tidak ada baris ditulis sama sekali.

### 3.10 Yang tidak ada di modul akses

- **Tidak ada layar Log Audit.** Tidak ada entri navigasi, tidak ada rute SPA, dan tidak
  ada berkas tampilan untuk jejak audit — mencarinya di menu **Sistem** adalah mencari
  sesuatu yang tidak pernah dibangun. **Endpointnya ada dan terisi**:
  `GET api/core/audit-log` (`Modules/Core/Routes/api.php:60`), digerbangi `core.view` —
  pada peran bawaan berarti **admin dan direktur**. Ia read-only secara rancangan: tidak
  ada endpoint tulis, "a log the application can write on request is not evidence".
  Parameternya: `auditable_type` (nama pendek model atau FQCN), `auditable_id`,
  `user_id`, `event` (`created`/`updated`/`deleted`), `date_from`/`date_to`
  (`YYYY-MM-DD`, keduanya inklusif), `sort=created_at` dengan `dir=asc|desc`, dan
  `per_page` (bawaan 25).

  **Dua cara membacanya, keduanya dari shell server.** Lewat API — ambil token dulu,
  dan ingat gerbang Basic-auth nginx memakan header `Authorization`, jadi tokennya
  dikirim di `X-Api-Token` (§3.5):

  ```bash
  curl -s -u '<pengguna-gerbang>:<sandi-gerbang>' \
    -X POST https://erp1.pi2.co.id/api/iam/auth/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"<email-admin>","password":"<sandi-admin>"}'
  ```

  Tokennya ada di `data.token` pada jawaban itu. Pakai ia sebagai `X-Api-Token`:

  ```bash
  curl -s -u '<pengguna-gerbang>:<sandi-gerbang>' \
    -H 'X-Api-Token: <token>' \
    'https://erp1.pi2.co.id/api/core/audit-log?auditable_type=User&per_page=100'
  ```

  Atau langsung dari basis datanya, tanpa token dan tanpa gerbang — tabelnya
  `core_audit_log`, append-only:

  ```bash
  cd /var/www/erp1.pi2.co.id
  ```

  ```bash
  sudo -u www-data env HOME=/tmp php artisan tinker --execute="
    \Modules\Core\Models\AuditLog::latest('id')->limit(20)
      ->get(['created_at','user_name','event','auditable_type','auditable_label'])
      ->each(fn (\$row) => print(\$row->toJson().PHP_EOL));
  "
  ```

  > Pada salinan pohon sumber per 10 Agustus 2026, `core_audit_log` berisi **2 baris**.
  > Angka di produksi harus dibaca di sana.

- **Tidak ada layanan mandiri kata sandi**: tidak ada "ganti sandi saya", tidak ada
  alur lupa-sandi, tidak ada email reset. Setiap penggantian sandi harus lewat
  pemegang `iam.update` — pada instalasi baku, akun admin.
- **Tidak ada layar "Profil Saya"**. Pengguna tidak dapat mengubah nama, email, maupun
  sandinya sendiri.
- **Tidak ada cara memberikan satu izin langsung kepada satu pengguna.** Peran adalah
  satu-satunya jalur pemberian hak.
- **Tidak ada 2FA, tidak ada kebijakan kedaluwarsa kata sandi, tidak ada penguncian
  akun setelah sekian kali gagal.**
- **Tidak ada daftar sesi/token aktif di layar mana pun.** Anda tidak bisa melihat
  siapa yang sedang masuk, dari perangkat apa, dan tidak bisa mencabut satu token
  tertentu — hanya seluruh token seseorang.
- **Tidak ada "keluar dari semua perangkat".**
- **Tidak ada perintah artisan untuk membuat pengguna, mengatur ulang kata sandi, atau
  memberi peran.** Satu-satunya jalur konsol adalah `AdminUserSeeder` (hanya membaca
  `ERP_ADMIN_*`, hanya menyentuh peran admin) dan `erp:harden-demo-logins` (hanya
  memutar akun yang masih memakai kata sandi `password`).
- **Tidak ada laporan atau ekspor matriks peran×izin.**
- **Batas panjang kata sandi lewat layar adalah 8 karakter** sementara perintah
  pengerasan menuntut minimal 12. Layar tidak akan menghentikan Anda memberi seorang
  admin kata sandi delapan huruf.

---

## 4. Penyiapan awal

Urutan di bawah **diturunkan dari foreign key dan penjagaan service**, bukan dari
sebuah daftar periksa yang dijalankan sistem. Tidak ada `erp:setup-check`, tidak ada
wizard first-run, dan tidak ada perintah yang melaporkan master data mana yang masih
kosong. Setiap langkah menyebutkan penjagaan yang memaksanya.

### 4.1 Apa yang dipasang pemasang, dan apa yang tidak

Ada satu seeder yang boleh berjalan di produksi, dan ia menyebutkan alasan urutannya
sendiri: "Order matters: roles need permissions, the admin user needs the admin role,
taxes look up chart-of-accounts rows." `ProductionSeeder` menjalankan, berurutan:

1. Profil perusahaan (satu baris).
2. Katalog izin → 12 peran → **satu** akun admin dari variabel `ERP_ADMIN_*`.
3. Bagan akun → pajak → kalender fiskal tahun berjalan.
4. 5 kategori item, 5 kategori aset.

Perintahnya milik [`DEPLOYMENT.md` §3.6](DEPLOYMENT.md); §3.8 memuat daftar langkah
pasca-login pertama. Panduan ini tidak mengulangnya.

**Yang TIDAK dibuat seeder, dan karenanya harus Anda buat sendiri sebelum ada orang
yang bisa bekerja:**

| Yang harus dibuat tangan | Layar | Catatan |
|---|---|---|
| **Gudang** | Persediaan → Gudang | Nol tersemai, **tidak ada importer**. `code` wajib dan tidak pernah dibuat otomatis |
| **Rekening bank** | Keuangan → Rekening Bank | Nol tersemai, **tidak ada importer**. Tiap rekening menuntut akun COA yang ada dan belum diklaim rekening lain |
| **Item, vendor, pelanggan, karyawan** | masing-masing modul | Nol tersemai. Keempatnya satu-satunya yang punya importer massal |
| **Proyek, kontrak, BOQ/RAP/AHSP** | masing-masing modul | Nol tersemai; tiga yang terakhir punya importer dokumen tersendiri |

**Urutan yang dipaksa kode** (bukan konvensi):

1. **Bagan akun sebelum apa pun yang memposting.** `JournalService` menolak dengan
   `"COA account {kode} does not exist; seed the chart of accounts first."` dan
   `"COA account {kode} ({nama}) is a group and cannot be posted to."`
2. **Kalender fiskal sebelum apa pun yang memposting.** Dua penolakan berbahasa
   Indonesia sampai apa adanya ke pemakai: *"Belum ada periode fiskal untuk {tanggal}.
   Buat kalender fiskal {tahun} lebih dulu di Keuangan › Periode Fiskal."* dan
   *"Periode fiskal YYYY-MM sudah ditutup; jurnal tidak dapat diposting ke dalamnya."*
   Pergerakan stok lewat gerbang yang sama.
3. **Bagan akun sebelum pajak.** Seeder pajak mencari `coa_account_id` berdasarkan
   kode; akun yang hilang diam-diam meninggalkan kolom itu null.
4. **Kategori item sebelum item** (FK nyata).
5. **Gudang sebelum dokumen stok apa pun** (FK nyata pada GRN, bon, transfer, opname,
   saldo, dan buku stok).
6. **Pelanggan sebelum penawaran/kontrak**; **vendor sebelum PO**; **karyawan sebelum
   sertifikat, absensi, rekap, payroll** (semua FK nyata).

**Yang TIDAK dipaksa**, dan Anda harus tahu: id lintas modul sengaja tidak
dikonstrain, "keeping modules decoupled". `prj_projects.contract_id / customer_id /
boq_id` hanya divalidasi sebagai integer.

> **Sebuah proyek bisa dibuat menunjuk pelanggan, kontrak, atau BOQ yang tidak ada,
> dan tidak ada satu pun tempat yang mengatakannya.** Lebih buruk: `contract_id` yang
> tidak ketemu **diabaikan diam-diam**, bukan ditolak — proyeknya tetap dibuat, tanpa
> nilai kontrak, tanggal, retensi, dan masa pemeliharaan yang seharusnya diwarisinya,
> lalu tampil sebagai proyek tanpa kontrak.

### 4.2 Profil perusahaan

**Sistem → Profil Perusahaan**, butuh `core.update`. Subtitel layarnya menyebut
perannya: "Dipakai pada kop dokumen, faktur pajak dan laporan." Simpan pertama pada
basis data kosong membuat barisnya.

Kolom mana yang benar-benar terpakai:

| Kolom | Terpakai di mana |
|---|---|
| `legal_name` | **Pilihan pertama** pada setiap kop surat, blok tanda tangan BAST/invoice/slip gaji, kotak KONTRAKTOR setiap formulir rumah |
| `name` | Cadangan bila `legal_name` kosong |
| `npwp` | Baris meta kop; baris "NPWP PERUSAHAAN" pada formulir register kewajiban pajak; **muatan ekspor pajak dan header layar Ekspor Pajak** |
| `address`, `city`, `province`, `postal_code` | Digabung ke baris meta kop. **`city` juga berdiri sendiri**: ia adalah "tempat" sebelum tanggal pada BAST, PO, slip gaji dan invoice, dan tempat cadangan pada formulir rumah bila proyeknya tidak punya kota |
| `phone` | Baris meta kop, berawalan "Telp" |
| `is_pkp`, `sppkp_number` | Hanya dilaporkan ke layar Ekspor Pajak. **Tidak menggerbangi apa pun** |
| `nib`, `email`, `website` | **Disimpan dan ditampilkan di layar profil saja.** Tidak dibaca template cetak, service, maupun ekspor mana pun |

> **Ekspor e-Faktur dan e-Bupot membawa NPWP, flag PKP dan nomor SPPKP perusahaan
> langsung dari tabel ini, dan TIDAK ADA yang memeriksanya.** Penjaga ekspor menolak
> invoice karena nomor faktur kosong, pelanggan kosong, atau NPWP pelanggan kurang
> dari 15 digit — tetapi tidak pernah karena identitas perusahaan sendiri. Instalasi
> yang tidak pernah menyunting profil akan mengekspor berkas pajak berkepala NPWP
> dummy yang tersemai, dan layar Ekspor Pajak mencetak dummy itu di headernya seolah
> nyata. **Pada erp1 hari ini `npwp` masih `01.234.567.8-012.000` — dummy seeder.**
> Memperbarui profil perusahaan bukan kosmetik; ia prasyarat pelaporan pajak.
>
> Tidak ada pemeriksaan di dalam aplikasi yang memberi tahu bahwa profil belum diisi.
> Satu-satunya instruksi jujur adalah: **buka Sistem → Profil Perusahaan dan periksa
> sendiri sebelum invoice pertama dan ekspor pajak pertama.**

**Logo perusahaan tidak bisa disetel dari aplikasi.** `logo_path` tidak ada di daftar
kolom tervalidasi `CompanyController::update()`, tidak ada di formulir SPA
(`public/app/js/views/custom.js:2062+`), dan tidak ada rute unggah untuk itu.
Menyetelnya berarti menaruh berkasnya di `storage/app/public/` pada server lalu
menulis path relatifnya ke `core_company.logo_path` langsung di basis data.

Format dan ukuran dipaku di kode: **PNG, JPG/JPEG, atau GIF saja, maksimal 1 MiB**.
Berkas yang hilang, kebesaran, atau bukan salah satu dari tiga format itu **tidak
menghasilkan logo sama sekali, tanpa pesan salah, tanpa baris log, tanpa petunjuk di
layar mana pun** — dokumennya tetap tercetak. Aturannya ditulis di kode: "a letterhead
without one is a letterhead; a broken image is not."

> Administrator yang mengunggah SVG akan menyimpulkan fiturnya rusak, bukan bahwa
> formatnya salah. Pada erp1 hari ini `logo_path` kosong — kop mencetak nama
> perusahaan sebagai teks.

### 4.3 Bagan akun

Tersemai lengkap: **77 akun** — 14 grup (tidak dapat diposting) dan 63 daun yang dapat
diposting. Diverifikasi pada basis data hidup: 77 baris, 63 dapat diposting. Layarnya
**Keuangan → Bagan Akun**.

Penjagaan penyuntingannya adalah paragraf terpenting di bagian ini, **karena laporan
membaca BAGAN, bukan buku besar**:

- **Menghapus** akun ditolak bila ia punya baris jurnal atau punya anak.
- **Empat kolom mengunci diri sendiri begitu akun membawa baris jurnal**: `code`,
  `account_type`, `normal_balance`, dan mematikan `is_postable`. Mengubah nama,
  memindahkan induk, dan menonaktifkan tetap mungkin seumur hidup akun itu.

Dua bahaya yang penjagaan itu tutup, dengan angka yang benar-benar diukur pada dataset
demo — keduanya layak Anda ketahui karena penjagaannya **hanya** menutup akun yang
sudah membawa baris:

> **Mematikan "Dapat diposting" pada 1-1220 Bank Mandiri Proyek mengeluarkan
> Rp 10.767.000.000 kas bank dari neraca** (aset 10.890.010.000 → 123.010.000) dengan
> buku besar tak tersentuh dan tetap seimbang sampai ke rupiah — lalu butir
> `trial_balance_balanced` pada tutup buku adalah **BLOCK tanpa jalan pengabaian**,
> sehingga bulan itu **dan setiap bulan sesudahnya** tidak bisa ditutup.
>
> **Membalik akun yang sama dari aset menjadi beban** memindahkan Rp 10,767 miliar
> yang sama dari neraca ke laba-rugi dengan **kedua flag "seimbang" tetap true dan
> daftar periksa tutup buku tetap bersih** — salah saji tanpa alarm di mana pun. Ini
> yang lebih senyap dan lebih buruk.

Menyalakan kembali `is_postable` sengaja diizinkan: itulah perbaikan untuk pembalikan
yang terjadi sebelum penjagaan ini ada.

**`1-1100 Kas` adalah kasus khusus.** Seeder menandainya dapat diposting, tetapi sebuah
migrasi membalikkannya menjadi **grup** pada instalasi yang belum pernah memposting
jurnal ke sana. **Pada basis data hidup ia adalah grup.** Setiap laci kas kecil butuh
anak `1-11xx` yang dapat diposting sendiri di bawahnya.

### 4.4 Kode akun yang menopang mesin

Dua kelas, dan keduanya harus dibedakan.

**(A) Tujuh yang BISA dikonfigurasi** — di **Pengaturan → Akun Jurnal Otomatis**:

| Setelan | Kode bawaan | Dibaca oleh |
|---|---|---|
| `accounting.inventory_account` | 1-1400 Persediaan Material | Stok: penerimaan / bon / opname |
| `accounting.grn_clearing_account` | 2-1150 Penerimaan Barang Belum Ditagih | Kredit penerimaan ber-PO; tagihan AP mendebetnya kembali |
| `accounting.receipt_accrual_account` | 2-1600 Beban YMH Dibayar | Penerimaan atas nama vendor tanpa PO |
| `accounting.stock_variance_account` | 6-4400 Selisih Persediaan | Jurnal opname |
| `accounting.opening_balance_account` | 3-3100 Saldo Awal (ekuitas) | Penerimaan **tanpa PO dan tanpa vendor** |
| `accounting.purchase_variance_account` | 6-4500 Selisih Harga Pembelian | Tiga-arah match tagihan AP |
| `accounting.purchase_advance_account` | 1-1500 Uang Muka Proyek | Uang muka tagihan AP |

Kontraknya dua arah dan ditegakkan: setiap kunci `accounting.*` yang dibaca service
saat runtime terdaftar di layar, dan setiap kunci yang terdaftar punya pembacanya.

> **`accounting.opening_balance_account` tidak boleh diarahkan ke 6-4400 Selisih
> Persediaan atau akun beban mana pun.** Melakukannya **melaporkan seluruh stok awal
> perusahaan sebagai pendapatan operasi di tahun go-live-nya.** Penerimaan tanpa
> lawan transaksi bukan pembelian — tidak ada liabilitas, tidak ada peristiwa
> laba-rugi — jadi lawan jurnalnya adalah ekuitas, dan itulah gunanya 3-3100.
>
> 3-3100 adalah akun antara: seorang akuntan menutupnya **sekali** ke 3-1100 Modal
> Disetor / 3-2100 Laba Ditahan setelah seluruh saldo awal masuk. Tidak ada seeder dan
> tidak ada migrasi yang memutuskan pembagian itu.

**(B) Yang TIDAK bisa dikonfigurasi** — konstanta dan cabang `match` di dalam kode.
Kode-kode ini **harus ada, harus dapat diposting, dan harus tetap pada maknanya**,
atau sebuah pemostingan mati dengan `"COA account X does not exist"` atau mendarat di
tempat yang salah:

| Kelompok | Kode |
|---|---|
| Piutang & pendapatan | 1-1300 Piutang Usaha, 1-1350 Piutang Retensi, 1-1360 Aset Kontrak, 2-1300 PPN Keluaran, 2-1410 Liabilitas Kontrak, 2-1700 Provisi Kerugian Kontrak, 5-1600 Beban Provisi; pendapatan 4-1100 / 4-1200 (sistem integrasi) / 4-1300 (pemeliharaan) |
| Hutang & pembelian | 2-1100 Hutang Usaha, 2-1500 Hutang Retensi Subkon, 6-4500, 1-1500, 1-1400, 6-4100 Beban Umum & Administrasi, 2-1220 Hutang PPh 23, 1-1600 PPN Masukan |
| Biaya proyek (5 kategori) | 5-1100 Material, 5-1200 Upah, 5-1300 Subkon, 5-1400 Peralatan, 5-1500 Overhead |
| Payroll | 5-1200, 6-1100 gaji kantor, 6-1200 beban BPJS, 2-1210 hutang PPh 21, 2-1120 hutang BPJS, 2-1110 hutang gaji |
| Potongan pajak | 1-1700 PPh final dibayar dimuka, 2-1300 PPN wapu, 1-1710 PPh 23 dibayar dimuka, 7-2400 Beban Denda & Potongan Lain-lain |
| Kasbon & aset | 1-1370 Piutang Karyawan (Kasbon); pelepasan aset ke 7-1200 Pendapatan Lain-lain |
| Kontrol tutup buku | 1-1300 vs invoice termin belum dibayar, 2-1100 vs tagihan vendor belum dibayar |

**Akun aset adalah master data per KATEGORI, bukan konstanta.** `depreciation_account_hint`,
`accum_account_hint`, `asset_account_hint` disunting di **Aset → Kategori Aset**
sebagai string 20 karakter bebas **tanpa pemeriksaan keberadaan**.

> Kategori yang kehilangan pasangan akunnya **ditolak, bukan ditebak** — tetapi
> penolakannya baru datang saat seseorang menekan Posting pada run penyusutan:
> *"Kategori aset X belum memiliki akun penyusutan/akumulasi. Lengkapi di Master Data
> › Kategori Aset sebelum memposting."* Kode yang salah ketik lolos formulir kategori
> lalu mati sebagai `"COA account X does not exist"`. Alasan penolakan itu tegas:
> mengkredit akun akumulasi penyusutan yang salah menyalahsajikan dua kelas aset
> sekaligus dan **tidak terlihat di muka neraca**, tempat semuanya duduk di 1-2000.

**Laci kas kecil** menuntut akun sendiri di keluarga **1-11xx** dan menolak apa pun
selain itu, dengan **tujuh** penolakan berurutan: akun tidak ditemukan → tidak aktif →
akun grup ("buat akun anak 1-11xx, mis. 1-1110 Kas Kecil Kantor Pusat, di bawah
1-1100 Kas") → bukan aset bersaldo debit → sudah diklaim rekening bank → sudah diklaim
laci lain → di luar 1-11xx. Penolakan ketujuh ada karena dua pesan sebelumnya (akun
grup dan sudah-diklaim-bank) sudah menjanjikan 1-11xx sementara tidak ada yang
menegakkannya: sebuah laci di 1-1500 lolos semua
pemeriksaan awal, lalu isi ulang Rp 5.000.000-nya dari bank terbaca sebagai **arus
keluar operasi** pada laporan arus kas, dengan kas akhir tidak menyertakan lacinya.

### 4.5 Kalender fiskal

Seeder produksi membuat **tahun berjalan saja**, dua belas bulan terbuka. Sesudah itu
kalender dijaga tetap di depan oleh perintah terjadwal `fin:ensure-calendar` (harian
05:30 WIB) yang membuat setiap bulan yang hilang dari bulan berjalan sampai
`calendar_months_ahead` (default **3**) ke depan, dan **tidak pernah menyentuh status
baris yang sudah ada** — cron tidak bisa membuka kembali periode yang sudah ditutup.

Kegagalan yang dicegahnya punya tanggal, dan perintahnya menyebutkannya: **1 Januari**
— invoice pertama, tagihan pertama, dan pembayaran pertama tahun itu semuanya ditolak
sekaligus, pada pagi tersibuk tahun akuntansi.

**Pembuatan malas ditolak, dan alasannya perlu Anda tahu**: membuat periode saat
diminta akan membuatnya **terbuka** persis pada saat seseorang memposting ke dalamnya
— memundurkan jurnal ke 2025-03-15 akan membuat-dan-membuka Maret 2025 lalu menerima
entrinya; penjagaan yang mengalahkan dirinya sendiri pada satu-satunya hal yang ia ada
untuk mencegahnya.

Anda juga bisa membuat satu tahun penuh dari layar **Keuangan → Periode Fiskal**,
tombol "Buat kalender {tahun}" (butuh `fin.create`; tahun antara 2000 dan sekarang+2).

> **"Buat kalender" untuk tahun lampau TIDAK membuat bulan terbuka.** Sebuah bulan
> dibuat **TERTUTUP** bila ada periode tertutup yang terletak sesudahnya, **atau** bila
> ia terletak sebelum periode paling awal yang dimiliki kalender sama sekali.
> Administrator yang menekan "buat kalender 2025" berharap bisa memundurkan entri
> saldo awal akan mendapat dua belas periode **tertutup**, dan pesannya menyebut aturan
> mana yang berlaku. Itu disengaja: sebuah probe audit pernah membuat 2024 sebagai dua
> belas bulan terbuka lalu memposting jurnal bertanggal 2024-05-10 yang beberapa menit
> sebelumnya ditolak. *"Dua belas periode 2024 yang tertutup adalah arti dari 'kami
> mulai memakai sistem ini pada 2026', entah ada yang sudah menekan Tutup Periode atau
> belum."*

### 4.6 `config/erp.php` — apa yang boleh diubah, dan dari mana

Dua lapis. `config/erp.php` adalah bawaan yang dikirim; apa pun yang Anda sunting
disimpan di `core_settings` di bawah kunci bertitik yang sama **dan menang**.

> **Menyunting sebuah parameter hanya memengaruhi dokumen yang dibuat SESUDAHNYA.**
> Penawaran, kontrak, PO, SPK dan invoice semuanya memotret tarifnya saat dibuat,
> sehingga riwayat tidak pernah ditulis ulang. Layar mengatakan ini kepada Anda.

Layarnya **Sistem → Pengaturan**, menyimpan butuh `core.update`. Kelompoknya:

| Kelompok | Isinya |
|---|---|
| **Pajak** | `tax.ppn_rate` (11% efektif) dan `tax.ppn_headline_rate` (12% statutori). **Sepasang, dan harus bergerak bersama**: 11% efektif = 12% × 11/12. Ekspor e-Faktur menghitung DPP nilai lain mundur dari PPN memakai tarif headline |
| **PPh Final Jasa Konstruksi (PP 9/2022)** | **Delapan kendali**: tujuh tarif skema (dipotret ke SPK saat dibuat) **plus `cashflow.termin_collection_days`**, yang juga berdiri di kelompoknya sendiri — catatan di bawah tabel |
| **BPJS & Lembur** | Tarif & plafon kesehatan/JHT/JP, kelas risiko JKK 1–5, JKM, pembagi lembur (Kepmenaker 102/2004: 1/173), dan tiga parameter cuti (cuti tahunan minimum 12 — "12 adalah lantai hukum", carry over, hari kerja 5 atau 6) |
| **Proyek & Persetujuan** | Retensi bawaan, jam kerja per hari, ambang cakupan CPI, tiga ambang varian material, **dua ambang persetujuan direktur**, dan **`approvals.segregation_of_duties`** |
| **Notifikasi** | `notifications.email_enabled`, dengan peringatan bahwa menyalakannya sebelum `MAIL_MAILER` menunjuk mailer sungguhan hanya menulis lalu lintas persetujuan ke log aplikasi |
| **Rekonsiliasi Bank** | Jendela pencocokan tanggal (1–30 hari) |
| **Proyeksi Arus Kas** | Hari penagihan termin (0–365) |
| **Akun Jurnal Otomatis** | Tujuh kode akun di §4.4(A) |
| **Penomoran Dokumen** | 35 format, satu per jenis dokumen — §4.7 |

> **Satu setelan muncul di DUA kelompok, dan layar tidak mengatakannya.**
> `cashflow.termin_collection_days` didaftarkan dua kali di
> `SettingService::definitions()`: sebagai kendali **kedelapan** kelompok PPh Final Jasa
> Konstruksi (`Modules/Core/Services/SettingService.php:170`, berlabel *"Asumsi lama
> penagihan termin (hari)"*) dan sebagai **satu-satunya** kendali kelompok Proyeksi Arus
> Kas (baris 372, berlabel *"Lag penagihan termin (hari)"*). Kuncinya sama, jadi
> nilainya satu:
>
> - mengubahnya di satu tempat **memindahkannya di tempat lain** begitu layar dimuat
>   ulang — bukan dua parameter yang kebetulan mirip;
> - selagi belum disimpan, penanda "akan disimpan" menyala pada **kedua** kendali
>   meskipun Anda hanya menyentuh satu, sementara penghitungnya tetap membaca satu
>   parameter;
> - dan bila Anda mengisi keduanya dengan angka **berbeda** dalam satu penyimpanan, yang
>   menang adalah kendali di **Proyeksi Arus Kas** — ia dikumpulkan belakangan — **tanpa
>   peringatan apa pun.** Isi satu, biarkan yang lain.

**Sengaja TIDAK ada di layar**, masing-masing dengan alasan tertulis:

- **`erp.closing.*` dan `erp.backup.*`** — konstanta saat instalasi. Alasannya ditulis
  dan diulang di perintahnya: *"an operator who can silence the close reminder from a
  web form will, and the reminder is the only thing that turns 'we forgot to close
  June' into something anybody notices."* Mengubahnya berarti menyunting
  `config/erp.php` di server dan men-deploy — lihat [`DEPLOYMENT.md`](DEPLOYMENT.md).
- **`tax.pph23_services_rate`** — pernah bisa disunting dan tidak dihormati apa pun.
  Yang benar-benar memotong PPh 23 saat runtime adalah tarif pada baris `fin_taxes`
  berkode PPH23, dirawat di **Keuangan → Pajak**.
- **`accounting.perpetual_inventory`** — §4.7.

**Apa yang layar tolak**, dan mengapa tiap penolakan ada:

| Penolakan | Pesan |
|---|---|
| Kunci yang `config/erp.php` tidak kenal sama sekali | *"Parameter {kunci} tidak dikenal."* |
| Kunci yang dikenal tetapi tidak dijelaskan registri | *"Parameter {kunci} ditetapkan saat instalasi di config/erp.php dan tidak dapat diubah dari layar ini; mengubahnya membutuhkan deploy."* |
| Kode akun yang bukan baris `fin_accounts` yang dapat diposting | *"Akun {kode} tidak ada di bagan akun atau bukan akun yang dapat diposting."* |
| Memindahkan setelan akun selagi akun lamanya masih bersaldo | *"Akun {kode} masih memiliki saldo {Rp}; memindahkannya akan meninggalkan saldo itu tanpa dokumen yang dapat menutupnya. Nolkan akun tersebut lewat jurnal terlebih dahulu."* |

> Penolakan terakhir itu satu-satunya jendela pemeriksaannya. Mesin berhenti memposting
> ke kode lama, jadi tidak akan pernah ada yang melunasinya, dan hanya jurnal yang
> ditulis tangan yang bisa. **Kode akun tetap bisa disunting justru karena sebuah
> instalasi memang harus memetakannya ke bagan akunnya sendiri — tetapi hanya selagi
> akunnya masih kosong, dan itu persis jendela saat instalasi tempat pemetaan itu
> seharusnya dikerjakan.** Setelah pemostingan pertama, pintunya tertutup.

`SettingService::invalidOverrides()` melaporkan setiap override tersimpan yang entri
registrinya sendiri akan tolak hari ini — **ia melaporkan; ia tidak pernah memperbaiki
dan tidak pernah menghapus**, karena nilai itu data operator dan hanya operator yang
tahu maksudnya. Pada basis data hidup `core_settings` **kosong (0 baris)**, jadi tidak
ada satu pun.

### 4.7 `accounting.perpetual_inventory` — metode, bukan parameter

Ini satu-satunya kunci yang dibaca saat runtime dan **sengaja tidak bisa disunting**
dari layar. Ia bukan parameter — ia **memilih METODE akuntansi**, dan kedua metode
berbeda pendapat tentang di mana nilai stok yang ada berada:

- **`true` = perpetual.** Setiap pergerakan stok memposting ke buku besar. Material
  menjadi beban saat **dipakai**.
- **`false` = periodik.** Stok dilacak dalam kuantitas saja, tidak ada pergerakan yang
  menyentuh buku besar. Material menjadi beban saat **tagihan vendor disetujui**.

Diukur pada basis kode ini, atas satu pembelian Rp 6.200.000:

| Skenario | Akibatnya |
|---|---|
| **menyala saat terima, dimatikan kemudian** | 1-1400 menahan 6.200.000 melawan sub-buku stok 0,00 sementara 5-1100 dan realisasi proyek keduanya tetap 0,00. **Materialnya tidak pernah dibebankan di mana pun, selamanya**, karena bon yang seharusnya melunasi 1-1400 tidak lagi memposting |
| **mati saat terima, dinyalakan kemudian** | Tagihan sudah membebankan pembeliannya ke 5-1100; bon lalu mendebet 5-1100 untuk kedua kalinya dan mengkredit akun persediaan yang tidak pernah didebet → **5-1100 = 12.400.000 untuk pembelian 6.200.000, dan 1-1400 = −6.200.000** |

Tidak satu pun dari keduanya adalah cacat yang bisa dicegah mesin: setiap pemostingan
**benar** menurut metode yang berlaku saat ia dibuat.

> **Perubahan metode yang sungguhan menuntut REVALUASI STOK di salah satu sisinya,
> dibukukan di batas periode fiskal. Itu pertimbangan akuntan dan jurnal akuntan.
> Tidak ada apa pun di aplikasi ini yang melakukannya untuk Anda, dan tidak ada yang
> berpura-pura melakukannya.** Verdict SAFE dari perintah pemeriksa bukan izin untuk
> mengubah metode tanpa jurnal revaluasi.

Perintah pemeriksanya melaporkan apakah perubahan aman sekarang. Ia **hanya melapor,
tidak pernah memigrasi apa pun**, dan keluar bukan-nol selama masih ada penghalang:

```bash
sudo -u www-data env HOME=/tmp php artisan erp:inventory-method-check
```

Lima yang diperiksanya, dan yang pertama adalah yang paling menipu:

1. **Baris override tersimpan** di `core_settings` untuk kunci itu. Instalasi yang
   menyimpannya selagi kunci ini masih bisa disunting **masih punya baris itu, dan
   resolver masih menghormatinya** — sengaja, supaya sebuah upgrade tidak diam-diam
   mengganti metode akuntansi sebuah perusahaan. Akibatnya lugas: **menyunting
   `config/erp.php` TIDAK berpengaruh apa pun selama baris itu ada, dan operatornya
   percaya sebaliknya.** Putuskan metodenya, tulis ke berkas, lalu hapus baris
   `core_settings`-nya.
2. **Penerimaan barang yang kliringnya belum dilunasi tagihan vendor** — dikelompokkan
   per PO persis seperti tagihan mengonsumsinya. Termasuk kebalikannya: kelompok yang
   di-clear **lebih** dari yang pernah dikredit, yang berarti akun kliring membawa
   saldo di sisi yang salah.
3. **Pergerakan stok yang sudah diposting di dalam periode fiskal yang masih terbuka.**
   Karena itu: **ubah metode hanya di batas periode**, supaya setiap periode
   terpertanggungjawabkan ujung ke ujung di bawah satu metode.
4. **Stok on hand yang masih bernilai**, dan — hanya di bawah perpetual — **apakah
   sub-buku stok dan GL 1-1400 benar-benar cocok.** Ini dinyatakan sebagai
   **satu-satunya titik tie-out persediaan-ke-GL di seluruh produk**: daftar periksa
   tutup buku hanya mencakup 1-1300 dan 2-1100, jadi selisih yang muncul di sini tidak
   muncul di mana pun juga. Toleransinya satu sen per baris saldo.
5. Barang dalam perjalanan disebut sebagai **angka rekonsiliasi**, bukan dibiarkan
   ditebak: kirim mengeluarkan stok dari gudang asal, terima memasukkannya ke tujuan,
   tanpa jurnal di antaranya — jadi sepanjang jendela perjalanan sub-buku berada
   **di bawah** buku besar persis sebesar nilai yang di jalan. Sisa apa pun setelah
   dikurangi itu bukan.

### 4.8 Penomoran dokumen

Nomor diisi otomatis saat dokumen dibuat. Formatnya dibaca dari Pengaturan (override)
atau `config/erp.php`, lalu di dalam satu transaksi baris urutan dikunci
(`lockForUpdate`) sehingga **dua permintaan bersamaan tidak bisa berbagi satu nomor**.

Token: `{Y}` tahun 4 digit, `{M2}` bulan 2 digit, `{RM}` bulan romawi, `{N3}/{N4}/{N5}`
urutan berimbuh nol. **Urutan reset per jenis per tahun.**

**35 jenis ada di Pengaturan → Penomoran Dokumen.** Setiap format **wajib memuat
`{Y}` dan salah satu dari `{N3}/{N4}/{N5}`**, maksimal 60 karakter, dan hanya boleh
memakai huruf, angka, spasi, serta `/ . _ -` di luar keenam token.

> **Format tanpa `{Y}` lolos di mata dan ditolak kode — dan andaikan tersimpan,
> kerusakannya muncul pada 1 Januari, bukan pada hari ia disetel.** Penghitung
> restart di 1 setiap Januari, jadi `PO-{N4}` menerbitkan ulang kode tahun lalu dan
> kolom `code` yang unik menolak penyimpanan: **dokumennya sama sekali tidak bisa
> dibuat.** Pola itu juga menolak token karangan seperti `{FOO}` (yang akan lolos
> substitusi apa adanya ke setiap kode jenis itu) dan setiap karakter di luar daftar.

**Mengubah format tidak menomori ulang dokumen lama** dan tidak mereset penghitung.

> **Enam jenis dokumen menomori dirinya di luar layar Pengaturan dan tidak bisa
> diformat ulang di mana pun**: **KSB** (kasbon), **PCV** (voucher kas kecil),
> **RTM** (retur dari proyek), **RPB** (retur pembelian), **DEF** (defect/punch list),
> dan **BSL** (baseline proyek). Keenamnya jatuh ke cadangan bawaan
> `<TIPE>/{Y}/{RM}/{N4}`, dan `SettingService::set()` menolak menyimpan override untuk
> kunci yang tidak dijelaskan registri — jadi tidak ada jalan lain. Buktinya hidup:
> ada baris `BSL / 2026` di tabel urutan. **Administrator yang menstandarkan seluruh
> penomoran dari layar Pengaturan akan menemukan keenam ini tidak berubah dan tidak
> akan menemukan layar yang menjelaskan mengapa.** Tidak ada layar atau perintah yang
> melaporkan jenis mana yang memakai cadangan; satu-satunya cara adalah membaca
> `public string $documentType` di `Modules/*/Models/*.php` dan membandingkannya
> dengan `config/erp.php`.

Satu efek lanjutan yang mudah terlewat: importer dokumen memakai **kepala harfiah**
format (mis. `BOQ/`) untuk satu penjagaan — nilai di kolom grup yang *tampak* seperti
nomor dokumen jenis itu tetapi tidak ada di mana pun **ditolak**, alih-alih diam-diam
mencetak dokumen kedua. **Setelah format berubah, kode yang terlanjur terbit dengan
prefiks lama berhenti dikenali penjagaan itu, dan salah ketik pada salah satunya
menjadi "buat dokumen baru" alih-alih sebuah kesalahan.**

### 4.9 Importer

Dua, dan keduanya bersaudara: CSV/XLSX masuk, CSV keluar; keduanya punya **pratinjau
yang tidak menulis apa pun** dan sebuah commit; keduanya mencocokkan pada kode bisnis
sehingga menjalankan ulang berkas yang sudah diperbaiki **memperbarui, bukan
menggandakan**.

**A. Data master rata** — Sistem → Impor Data Master. **Empat sumber daya saja:**

| Sumber daya | Izin | Kolom wajib |
|---|---|---|
| Item / Material | `inv.create` + `inv.update` | kode, nama, satuan, kategori_kode |
| Vendor & Subkontraktor | `prc.create` + `prc.update` | kode, nama |
| Pelanggan | `crm.create` + `crm.update` | kode, nama |
| Karyawan | `hr.create` + `hr.update` | kode, nama, nik_ktp, jenis_kelamin, tanggal_lahir, status_ptkp, tanggal_masuk, jenis_hubungan_kerja, jabatan, departemen |

Mengimpor menuntut `.create` **dan** `.update` sekaligus, karena impor yang mencocokkan
pada kode juga memperbarui baris yang ada — "somebody who may only create should not be
able to rewrite two thousand records by uploading a sheet".

Keempat itu dipilih karena mereka **rata**: satu baris di berkas = satu baris di tabel.
AHSP sengaja tidak ada, karena sebuah analisa adalah kepala plus N komponen dan
"berpura-pura itu muat di lembar rata akan diam-diam menjatuhkan komponennya".

Aturan yang perlu Anda nyatakan ke pemakai:

- **Satu baris mendarat atau tidak sama sekali.** Satu baris buruk tidak membatalkan
  1.999 baris baik — tetapi juga tidak setengah tertulis.
- **Header dicocokkan berdasarkan NAMA, tidak pernah ditebak dari posisi.** Kolom wajib
  yang hilang ditolak di depan dengan menyebut nama kolomnya.
- **Kolom yang tidak dibawa berkas dibiarkan apa adanya** — lembar parsial ("perbaiki
  saja nomor rekening semua orang") tidak boleh mengosongkan kolom lain.
- **Kode yang muncul dua kali dalam satu berkas adalah kesalahan**, bukan penimpaan
  diam-diam.
- **Ekspor ada untuk alasan yang sama dengan impor**: ekspor → sunting di Excel →
  impor kembali adalah jalur **sunting massal**.

Batas berkas, dan tiap batas berbeda dari yang lain: `.csv`/`.xlsx`/`.xls` saja;
**5 MB**; **5.000 baris isi**; **20.000 baris fisik** (subtotal, spanduk, baris kosong
ikut dihitung — disebut terpisah dengan sengaja, karena "5.000 baris isi" pada lembar
18.000 baris fisik terbaca seperti kebohongan); 256 kolom; `.xlsx` yang mengembang
lebih dari 64 MB ditolak.

Format Indonesia ditangani: `1.250.000,50`, `(1.250.000)` untuk negatif, awalan
`Rp`/`IDR`, `1.250.000,-`. Tanggal menerima `dd/mm/yyyy`, `dd-mm-yyyy`, `yyyy-mm-dd`,
`dd/mm/yy` dan serial Excel — `03/04/2026` adalah 3 April, bukan 4 Maret. NIK 16 digit,
nomor rekening berawalan nol, dan barcode dibaca sebagai **teks**.

> **Tiga jebakan importer data master yang harus Anda hindari:**
>
> 1. **Mengimpor ulang kode yang barisnya sudah DIHAPUS bukan pembaruan dan bukan
>    kesalahan bersih — itu 500 yang me-rollback SELURUH berkas.** Keempat model
>    memakai soft delete, jadi baris terhapus tak terlihat oleh pencarian importer,
>    lalu `create()` menabrak indeks unik pada `code`. **1.999 baris baik ikut hilang.**
> 2. **NIK ganda melakukan hal yang sama.** `hr_employees.nik_ktp` UNIK di skema, tetapi
>    aturan importer hanya `string, size:16` tanpa pemeriksaan unik — dua kode karyawan
>    berbagi satu NIK lolos pratinjau dan meledak saat commit. Importer dan formulir
>    karyawan juga tidak sepakat: formulir menuntut **16 digit** dan unik, importer
>    menerima 16 **karakter** apa pun. NIK 16 karakter non-numerik lolos impor lalu
>    gagal di formulir sunting sesudahnya.
> 3. **`kategori_kode` dicari tanpa filter soft-delete**, jadi kategori yang sudah
>    dihapus tetap ketemu dan item yang diimpor menempel padanya — item yang tidak
>    dicantumkan layar mana pun.
>
> Importer karyawan juga **tidak membawa kolom `pkwt_basis` maupun `pkwt_end_date` sama
> sekali**, jadi setiap karyawan kontrak yang dimuat massal datang tanpa tanggal akhir
> PKWT dan pengawas tenggat akan beralarm atas kekosongan itu.

**B. Dokumen berbaris** — Sistem → Impor Dokumen. Empat: **Penawaran** (`crm`),
**BOQ/RAB**, **AHSP**, **RAP** (ketiganya `est`).

Satu tata bahasa berkas untuk keempatnya: satu baris header; kolom `tipe` yang
menyatakan setiap baris **itu apa**; kolom grup supaya satu workbook bisa membawa
banyak dokumen. `abaikan` (alias `lewati`) berarti "ini subtotal atau pemisah,
lewati".

> **Baris yang berisi apa pun tetapi tanpa `tipe` yang dikenali DITOLAK, tidak pernah
> dilewati** — "diam-diam melewati baris yang membawa uang adalah bagaimana sebuah BOQ
> terimpor kurang 8% dan tidak ada yang menyadarinya". Kosakata pelewatan sengaja
> disempitkan: `subtotal` dan `rekap` **bukan** kata pelewat, karena kata yang menamai
> **isi** barisnya tidak boleh menjadi pelewat — baris ber-tipe REKAP yang sel
> terakhirnya berisi 999.000.000 akan lenyap dalam senyap.

Empat keputusan yang menopangnya:

- **Sebuah dokumen bersifat semua-atau-tidak-sama-sekali.** Satu baris buruk menolak
  seluruh dokumennya. "BOQ yang diam-diam menjatuhkan tiga baris adalah BOQ yang salah
  selamanya, dan setiap laporan varian yang ditulis terhadapnya ikut salah."
- **Berkasnya per-dokumen.** Tiap dokumen commit dalam transaksinya sendiri, jadi satu
  dokumen yang ditolak tidak membatalkan sebelas yang baik di sebelahnya. Kecuali:
  baris yang tidak bisa diatribusikan ke dokumen mana pun menolak **seluruh berkas**.
- **Pratinjau tidak menulis apa pun.**
- **Importer tidak pernah menyentuh model.** Ia merakit muatan yang dijelaskan
  FormRequest modul itu sendiri dan menyerahkannya ke service modul itu — sehingga
  setiap penjagaan status berlaku tanpa perubahan, dan **tidak ada definisi kedua
  tentang apa itu BOQ yang sah**.

Penjagaan yang akan ditemui berkas buruk, dengan pesannya:

| Keadaan | Yang terjadi |
|---|---|
| Grup yang tampak seperti nomor dokumen jenis itu tetapi tidak ada | *"{grup} tidak ditemukan; kosongkan atau ganti kolom {kolom grup} dengan label bebas untuk membuat dokumen baru, atau perbaiki nomornya."* |
| Sasaran sudah lewat draf | *"{kode} berstatus {label} dan tidak dapat diperbarui; buat Versi Baru lalu impor ke versi itu."* Tidak ada template yang membawa kolom status, jadi **impor tidak pernah bisa menyetujui apa pun** |
| Sasaran pernah diajukan lalu ditolak | Terimpor, dengan peringatan bahwa impor akan menimpa isinya |
| RAP dipindahkan ke BOQ lain | *"RAP {kode} milik {sekarang} dan tidak dapat dipindahkan ke BOQ lain; buat RAP baru untuk BOQ tersebut."* |
| Kolom `jumlah` tidak cocok qty × harga | Ditolak. Kolomnya dibaca sebagai **checksum**, tidak pernah disimpan — "aritmetika estimator sendiri memeriksa aritmetika kami, dan satu-satunya hal yang menangkap pemisah ribuan yang salah dibaca" |
| Harga kosong pada baris berbiaya AHSP | **Bukan harga nol.** Pratinjau melabeli totalnya sebagai parsial dengan jumlah baris tanpa harga, alih-alih mencetak Rp 0 yang percaya diri di bawah bill senilai ratusan juta |

> **`koefisien`/`persen` dan `uang`/`qty` memecahkan titik tunggal dengan aturan yang
> BERLAWANAN**, dan itulah sebabnya ada empat cast angka, bukan satu: "1.500" di kolom
> VOLUME adalah seribu lima ratus meter persegi, tetapi "1.050" di kolom KOEFISIEN
> adalah 1,05 — membacanya sebagai 1050 mengalikan harga satuan setiap item BOQ yang
> memakai analisa itu dengan seribu, **dan BOQ-nya tetap menjumlah**, sehingga tidak
> ada apa pun di hilir yang akan menangkapnya.

**Template dibuat sendiri oleh kedua importer**; tidak ada berkas yang perlu dicari di
disk. Template dokumen bahkan membawa contoh terisi dengan `#` di kolom `tipe`, supaya
operatornya mengaktifkannya dengan **menghapus satu karakter** alih-alih mengetik
ulang. Ekspor data yang sudah ada kembali dalam bentuk yang persis diterima importer —
itu jalur sunting massal, sekaligus cara mengetahui kode yang diberikan sistem setelah
impor pembuatan.

### 4.10 Yang tidak ada pada penyiapan

- **Tidak ada unggah logo.** Lihat §4.2. Berkas yang harus dibuka untuk memastikan:
  `Modules/Core/Http/Controllers/CompanyController.php` dan
  `public/app/js/views/custom.js:2062`.
- **Tidak ada dukungan lebih dari satu badan hukum.** `core_company` tabel satu baris
  tanpa relasi ke dokumen mana pun, `fin_journals` tidak punya kolom entitas, dan tidak
  ada konsep KSO di mana pun. Tercatat sebagai batasan yang diketahui di
  [`ASSESSMENT-LANJUTAN-LAMPIRAN.md`](ASSESSMENT-LANJUTAN-LAMPIRAN.md) §56, dengan
  konsekuensinya: tender yang dimenangkan lewat KSO atau transaksi dengan afiliasi
  harus dicampur ke buku entitas tunggal atau disimpan sepenuhnya di luar sistem.
- **Tidak ada importer untuk gudang, kategori item, kategori aset, rekening bank,
  pajak, atau bagan akun.** Semuanya formulir per formulir.
- **Tidak ada impor saldo awal dan tidak ada layar saldo awal.** Bagan akun mengirim
  akun lawan jurnalnya (3-3100 Saldo Awal), tetapi tidak ada prosedur, layar, maupun
  perintah di dalam aplikasi untuk memuat saldo awal AR, AP, bank, atau ekuitas.
  **Semuanya datang sebagai jurnal yang diketik tangan** lewat Keuangan → Jurnal;
  `Modules/Finance/Services/JournalService.php` adalah satu-satunya rutenya.
- **Tidak ada pemeriksaan di dalam aplikasi bahwa profil perusahaan sudah diisi.**
  Belum terdokumentasi sebagai prosedur; instruksi jujurnya ada di §4.2.
- **Menghapus gudang hanya memeriksa bahwa tidak ada baris saldo dengan qty > 0.** Stok
  yang sedang dalam perjalanan menuju gudang itu, dan seluruh buku besar historisnya,
  tidak menghalangi penghapusannya. (Menghapus item dijaga lebih baik: ia menolak atas
  stok on hand dan atas transfer dalam perjalanan.)

> **Dua jebakan pada menjalankan ulang `ProductionSeeder` setelah go-live.** Seeder itu
> idempoten untuk hampir semuanya, tetapi:
>
> 1. **Ia membuat baris perusahaan KEDUA bila namanya sudah diganti.** Kuncinya adalah
>    `['name' => 'PT Nusantara Karya Integrasi']`; begitu Anda menyetel nama perusahaan
>    yang sebenarnya, kunci itu tidak cocok lagi dan seeder **menyisipkan baris baru**
>    yang membawa NPWP, NIB, dan SPPKP dummy. `Company::current()` adalah `first()`
>    **tanpa pengurutan**, jadi baris mana dari keduanya yang memberi makan setiap kop
>    surat, setiap ekspor pajak, dan setiap formulir cetak **tidak ditentukan kode**.
>    Tidak ada apa pun di aplikasi yang menunjukkan bahwa baris kedua ada. Bila seeder
>    harus dijalankan ulang, **periksa `core_company` untuk baris tambahan sesudahnya.**
> 2. **Ia MERESET kata sandi admin** ke apa pun isi `ERP_ADMIN_PASSWORD` saat itu, dan
>    memberikan ulang peran admin. `DEPLOYMENT.md` §3.8 menyuruh operator menghapus
>    variabel itu menjadi `ROTATED` setelah login pertama — bila mereka menurut,
>    penyemaian ulang berikutnya mencoba menyetel kata sandi admin menjadi literal
>    `ROTATED` (7 karakter) dan ditolak karena di bawah 12
>    (`AdminUserSeeder::MIN_PASSWORD_LENGTH`); bila tidak, **kata sandi
>    yang sudah diputar diam-diam dibatalkan.**

---

## 5. Rutinitas — delapan perintah dan sistem alarm

Semua perintah di bab ini dijalankan dari direktori situs hidup, sebagai `www-data` —
baca §1 sebelum mengetik yang pertama.

### 5.1 Delapan perintah sekilas

| Perintah | Jadwal | Menulis data? | Yang dijaganya |
|---|---|---|---|
| `fin:ensure-calendar` | **05:30 WIB harian** | Ya — `fin_fiscal_periods` | Posting pertama 1 Januari tidak menabrak periode yang belum ada |
| `ast:accrue-plant` | **05:40 WIB harian** (bulan yang baru berakhir) | Ya — `fin_project_costs` | Biaya alat internal masuk ke bulan pemakaiannya, bukan menumpuk ke hari demobilisasi |
| `svc:generate-pm` | **06:00 WIB harian** | Ya — `svc_tickets` | Kunjungan preventif tidak lewat tenggat dalam senyap |
| `erp:backup-watch` | **08:00 WIB harian** | Tidak (kecuali baris notifikasi) | Kegagalan cadangan sampai ke orang, bukan ke mailbox yang tak dibaca |
| `fin:close-watch` | **08:15 WIB harian** | Tidak | "Periode 2026-02 belum ditutup" menunggu di layar saat finance membuka ERP |
| `erp:deadline-watch` | **08:30 WIB harian** | Tidak | Delapan belas tanggal yang bisa lewat tanpa ada yang menagih |
| `erp:harden-demo-logins` | **Tidak pernah terjadwal** | Ya — `users`, hapus token | Memutar kata sandi akun yang masih memakai sandi seeder |
| `erp:inventory-method-check` | **Tidak pernah terjadwal** | Tidak | Menjawab apakah metode persediaan aman diubah sekarang |

Urutan jam paginya punya alasan yang tertulis di kode: *"the morning reads in order of
blast radius — backups, then the ledger, then every other date that can slide."*
Kalender jam 05:30 dibuat "sebelum ada orang di depan keyboard, supaya posting pertama
hari itu tidak pernah bertemu periode yang hilang"; pengingat 08:15 supaya "'periode
2026-06 belum ditutup' sudah menunggu ketika finance membuka ERP, bukan datang di
tengah pekerjaan".

**Dua perintah tidak pernah terjadwal, dan tidak bisa dijadwalkan.**
`erp:harden-demo-logins` meminta kata sandi lewat prompt tersembunyi dua kali —
dijalankan tanpa TTY, kedua entri kosong dan ditolak pagar panjang minimal.
`erp:inventory-method-check` adalah laporan kesehatan, bukan pekerjaan rutin.

### 5.2 Roda yang memutar semuanya

Satu baris di `/etc/cron.d/erp1` menjalankan seluruh penjadwal, sebagai `www-data`,
setiap menit. Berkas itu juga memuat tiga cron cadangan (sebagai root): backup malam
**02:15 WIB**, retry offsite **13:15 WIB**, dan restore drill bulanan **03:30 WIB
tanggal 2**. Header berkasnya menjelaskan mengapa jamnya ditulis ganda UTC/WIB: *"'02:15'
that silently means 09:15 WIB is how a backup ends up racing the workday."*

> **Keluaran CLI keenam perintah terjadwal dibuang ke `/dev/null`.** Terlihat di
> `/var/log/erp1-schedule.log`: setiap eksekusi tercatat sebagai
> `artisan erp:deadline-watch > '/dev/null' 2>&1`. Artinya baris SKIP, baris **BLIND**,
> dan rincian rupiah akrual **tidak pernah terbaca siapa pun** kecuali perintahnya
> dijalankan tangan. Yang selamat dari jadwal harian hanyalah notifikasi dalam
> aplikasi.
>
> Dan: **tidak ada yang mengawasi penjadwal itu sendiri.** Bila baris `schedule:run` di
> `/etc/cron.d/erp1` berhenti, keenam perintah berhenti bersamaan dan **tidak ada alarm
> yang naik** — alarm-alarm itu justru yang dinaikkan oleh perintah yang berhenti.
> Satu-satunya bukti adalah berhentinya baris baru di `/var/log/erp1-schedule.log`.

### 5.3 `fin:ensure-calendar`

Membuat periode fiskal yang belum ada, dari bulan berjalan sampai N bulan ke depan
(default 3), semuanya **terbuka**. Idempoten dan aman berkali-kali: memakai
`firstOrCreate`, sehingga status baris yang sudah ada tidak pernah disentuh — **cron
tidak bisa membuka kembali periode yang sudah ditutup.**

Bila ada **tahun baru** yang lahir dari sebuah run, ia mengirim notifikasi ke pemegang
`fin.post` berjudul *"Kalender fiskal 2027 dibuat"* dengan pesan agar memeriksa format
penomoran dokumen dan saldo awal sebelum tahun berjalan. Alasannya: "Finance sebaiknya
tahu 2027 ada pada bulan Oktober, selagi masih ada waktu memutuskan."

Menjalankannya tangan:

```bash
sudo -u www-data env HOME=/tmp php artisan fin:ensure-calendar
```

> **Ia hanya membuat KE DEPAN dari bulan berjalan.** Lubang periode di masa lalu tidak
> pernah diisinya; itu pekerjaan tombol "Buat kalender {tahun}" di layar Periode Fiskal
> — yang sengaja membuat tahun lampau **tertutup** (§4.5).
>
> Catatan asimetris yang perlu diketahui: `ProjectCostService` justru **lolos** kalau
> periodenya tidak ada sama sekali — jadi biaya proyek tetap tercatat sementara
> jurnalnya tertolak.

### 5.4 `ast:accrue-plant`

Mengakru biaya pemakaian alat internal satu bulan: satu baris `fin_project_costs` per
(mobilisasi, bulan), bertanggal akhir bulan, sebesar jumlah hari di lapangan pada bulan
itu × tarif harian internal. Tanpa argumen = bulan yang baru saja berakhir.

Idempoten: kuncinya (jenis rujukan, id rujukan, kategori biaya) dengan id rujukan
`mobilisasi × 1.000.000 + tahun × 100 + bulan`.

Tiga penolakan, semuanya bersuara:

| Keadaan | Pesan |
|---|---|
| Bulan di luar 1–12 | *"Bulan 13 tidak dikenal — gunakan 1 sampai 12."* |
| Bulan **belum berakhir** | *"Bulan 2026-08 belum berakhir — akrual alat dijalankan setelah bulan berakhir, agar hari yang belum terjadi tidak ikut terbebankan."* |
| Periode fiskal bulan itu **sudah ditutup** | *"Periode fiskal 2026-03 sudah ditutup; akrual pemakaian alat tidak dapat dicatat ke dalamnya. Biaya alat mobilisasi terbuka bulan itu baru akan muncul pada tanggal demobilisasi."* |

Perbedaan penting antara run terjadwal dan run manual, dan Anda harus tahu: run
**tanpa argumen** ke bulan yang sudah tertutup keluar **sukses** dengan "Period 2026-07
is already closed — nothing left to accrue." Alasannya ditulis: "erroring daily from
cron for the rest of the month would teach everyone to ignore this command's output."
Run dengan **argumen eksplisit** ke bulan tertutup **gagal keras**, "because a human
asked for something the period gate forbids and deserves the sentence saying why."

> **"Cron bilang OK" bukan bukti bahwa bulan itu terakru.**

Hanya mobilisasi berstatus **aktif** yang diakru; yang sudah dikembalikan sudah
dilunasi seluruh rentangnya oleh baris residual saat demobilisasi — mengakrunya lagi
akan menghitung dua kali.

> **Empat jebakan pada perintah ini:**
>
> 1. **`ast:accrue-plant 2026` bukan "seluruh tahun 2026".** Tanda tangannya
>    `{year?} {month?}`; dengan hanya argumen tahun, bulannya diambil dari default —
>    bulan yang baru berakhir. Pada 10 Agustus 2026 itu berarti **Juli 2026**, bukan
>    dua belas bulan. **Selalu sebutkan tahun DAN bulan.**
> 2. **Menjalankan ulang bulan yang sudah terakru menulis ULANG angkanya dari tarif
>    harian yang berlaku SEKARANG.** Bila tarif sebuah mobilisasi disunting, menjalankan
>    ulang bulan lampau yang masih terbuka **diam-diam menyatakan ulang biaya bulan
>    itu**. Idempoten tidak sama dengan tak berubah.
> 3. **Kejar-tayang harus selesai SEBELUM alat didemobilisasi.** Begitu dikembalikan,
>    seluruh rentangnya dilunasi baris residual bertanggal hari kembali, dan
>    menjalankan bulan lampau sesudahnya menghasilkan "No open deployment to accrue".
>    **Bulan-bulan di antaranya tetap kosong selamanya.**
> 4. **Bulan yang DITUTUP tanpa akrual tidak bisa diperbaiki**, dan karena butir
>    daftar periksa `plant_accrued` hanya WARN (bisa diabaikan dengan alasan tertulis),
>    seorang penutup **bisa** menutup bulan itu — sesudahnya biaya alat bulan tersebut
>    baru muncul pada tanggal demobilisasi, permanen.

**Kejar-tayang yang sempat menunggu sudah dijalankan 22 Agustus 2026** — Maret–Juli
2026 terakru penuh, Rp 573.000.000; catatannya, termasuk angka per bulannya, di
§12(d). Sisa Agustus diakru jadwal 05:40 pada 1 September, selama alatnya belum
didemobilisasi (jebakan 3 di atas).

### 5.5 `svc:generate-pm`

Membuat satu tiket per jadwal preventif yang tanggal jatuh temponya sudah tiba, lalu
menggulirkan tanggal berikutnya ke depan sebesar frekuensi jadwal — dalam **satu
transaksi**. Karena itu menjalankannya dua kali sehari sama saja: run kedua tidak
menemukan jadwal yang jatuh tempo.

Jadwal dilewati bila kontrak layanannya hilang atau bukan aktif: "No PM visits for
missing, expired, or terminated contracts."

> **Perintah ini MERATAKAN kunjungan yang terlewat, bukan mengejarnya.** Jadwal yang
> terlewat berbulan-bulan menghasilkan **satu** tiket, lalu tanggal berikutnya
> digulirkan melewati hari ini — "one catch-up ticket, not a backlog flood". Bulan-bulan
> yang dilompati **tidak meninggalkan tiket dan tidak meninggalkan jejak.**

Data hidup per 10 Agustus 2026: dua jadwal aktif — "PM CCTV Bulanan" (jatuh tempo
2026-08-05) dan "PM Akses Kontrol & Alarm Bulanan" (2026-08-12), keduanya di bawah
kontrak layanan yang aktif sampai 2027-03-31.

### 5.6 `erp:backup-watch`

Membaca berkas status yang ditulis skrip cadangan (`/var/lib/erp1/offsite-status.json`)
dan menaikkan alarm dalam aplikasi ke pemegang **`core.approve`** — pada instalasi ini,
**admin dan direktur**. Ambang basi: **3 hari**.

Mengapa perintah ini ada, dari kodenya sendiri: *"The backup runs as root under cron,
entirely outside this application; its failures land in /var/log/erp1-backup.log and in
cron mail addressed to a mailbox nobody has. This command is the bridge: the one channel
an operator actually looks at every day is the ERP itself."*

Tujuh keadaan, masing-masing dengan judul alarmnya sendiri:

| Keadaan | Judul alarm |
|---|---|
| Berkas status tidak ada (**hanya di production**) | Cadangan tidak pernah berjalan |
| Berkas bukan JSON sah | Status cadangan tidak terbaca |
| Tujuan offsite belum dikonfigurasi | Salinan cadangan offsite belum dikonfigurasi |
| Sukses terakhir null atau ≥ 3 hari | Salinan cadangan offsite macet |
| Penyimpanan offsite kosong | Penyimpanan cadangan offsite kosong |
| Arsip terbaru ≥ 3 hari atau tak terbaca | Arsip cadangan offsite menua |
| Uji pemulihan terakhir gagal | Uji pemulihan cadangan gagal |

Pemeriksaan **arsip terbaru** adalah yang menopang, dan alasannya ditulis: *"last_success
only proves the SYNC ran. A sync with nothing to push keeps 'succeeding' forever after
the local backup has died."* Stempel arsip yang tidak bisa diurai dibaca sebagai null,
"and null is treated as an alarm, never as fresh".

**Keadaan hidup hari ini**: berkas status berisi `"configured": false`,
`"destination": "unconfigured"`, `"last_success": null`. Jadi **keadaan ketiga yang
berbunyi, setiap pagi.** Itu sesuai [`DEPLOYMENT.md` §5.1](DEPLOYMENT.md): *"That
nagging is intentional — a local-only backup is the deficiency, not the alarm."*

> Di luar production, ketiadaan berkas status membuat perintah ini **diam**. Instalasi
> yang `APP_ENV`-nya bukan production tidak akan pernah mengeluh soal cadangan sama
> sekali.

### 5.7 `fin:close-watch`

Menagih periode yang sudah berakhir tetapi masih terbuka lebih dari **10 hari**.
Notifikasi ke pemegang **`fin.post`** — pada instalasi ini **admin dan finance** —
berjudul "Periode 2026-06 belum ditutup".

Daftar periksa dihitung **hanya untuk periode tertua yang melanggar**, dan alasannya
ditulis: itu pembacaan terberat di modul Keuangan (satu rekonsiliasi bank per rekening,
satu neraca saldo, satu ikhtisar ekspor pajak), dan yang tertua toh satu-satunya yang
bisa ditutup berikutnya — urutannya ditegakkan.

Isi badan notifikasi: umur periode dalam hari, lalu daftar **penghalang keras**, atau
"tidak ada penghalang, tinggal ditutup."; ditutup dengan "Ada N periode lain yang juga
sudah berakhir dan masih terbuka." bila ada.

**Keadaan hidup**: 2026-01 tertutup, 2026-02 sampai 2026-12 terbuka. Periode tertua
yang ditagih adalah **Februari 2026**, yang berakhir 28 Februari — **163 hari lalu**
per 10 Agustus 2026.

### 5.8 `erp:deadline-watch`

Satu perulangan di atas registri 18 tenggat (§5.11). Tiga jenis baris di layar CLI:

- `SKIP <kunci>` — tabel atau kolomnya belum ada (tim lain sedang bermigrasi; bukan
  alarm).
- **`BLIND <kunci>`** — ada N baris dalam cakupan tetapi setiap tanggalnya NULL:
  *"pengawas ini tidak melihat apa-apa sampai tanggalnya diisi; diamnya di sini adalah
  data yang hilang, bukan tanda aman."*
- `<kunci> [<tingkat>]: N baris -> <izin>` untuk tiap kelompok alarm yang dinaikkan.

> **Baris BLIND hanya terlihat kalau perintahnya dijalankan tangan** (§5.2), dan itulah
> satu-satunya tempat sistem mengaku bahwa sebuah pengawas tidak melihat apa-apa
> **karena tanggalnya belum diisi**, bukan karena semuanya beres. Jalankan sesekali:

```bash
sudo -u www-data env HOME=/tmp php artisan erp:deadline-watch
```

### 5.9 `erp:harden-demo-logins`

Memutar kata sandi akun yang masih memakai kata sandi bawaan seeder. Urutannya lengkap
di [`DEPLOYMENT.md` §7.1](DEPLOYMENT.md) — panduan ini menunjuk ke sana. Tiga sifat
yang layak diulang karena administrator bisa terjebak:

- **Yang ditemukan hanya akun yang kata sandinya persis `password`.** Akun yang
  seseorang sudah ubah ke kata sandi lemah lain **tidak terlihat sama sekali**, dan
  `--dry-run` yang bersih berarti "tidak ada yang masih memakai `password`", **bukan**
  "tidak ada kata sandi lemah".
- **Kata sandi baru tidak bisa diberikan sebagai argumen** dan tidak dihasilkan
  sendiri; ia diminta lewat prompt tersembunyi dua kali — "so it never reaches the
  shell history, the process list, or a log line — all three of which are readable by
  anyone who later gets a shell on the box, which is precisely the person this is meant
  to stop." **Perintah ini tidak dapat diotomasi, dan itu disengaja.**
- **Ia menolak apa pun di bawah 12 karakter** dan tujuh nilai pada daftar penjaga
  ketik-ulang. "The check is deliberately crude: it is a guard against retyping
  'password', not a strength meter."

Melihat cakupannya tanpa mengubah apa pun:

```bash
sudo -u www-data env HOME=/tmp php artisan erp:harden-demo-logins --dry-run
```

Memutar, mempertahankan satu akun demo baca-saja:

```bash
sudo -u www-data env HOME=/tmp php artisan erp:harden-demo-logins \
  --except=demo@nusantara.test
```

> **Efek yang tidak simetris, dan harus disebut satu tarikan napas:** perintah ini
> **mencabut setiap token API** akun yang diputarnya — dan pencabutan itu tidak bisa
> dibatalkan — **tetapi sesi tetap hidup.** Ia mencetak sendiri: *"Sessions are
> unaffected: SESSION_DRIVER=database keeps anyone already signed in, signed in."*
> Siapa pun yang sudah masuk sebelum rotasi tetap di dalam.
>
> Bila `admin` masuk himpunan rotasi, ia memperingatkan sendiri: *"admin is in the
> rotation set — after this, nobody can sign in as admin without the new password."*

**Keadaan erp1 hari ini**: **kesebelas akun masih memakai kata sandi `password`** —
diperiksa langsung terhadap salinan basis data, sebelas dari sebelas cocok. Lihat
§12(a).

### 5.10 Sistem alarm — ke mana bunyinya keluar, dan bagaimana mematikannya

Semua alarm dari keempat pengawas melewati satu pintu. **Dua saluran, dan hanya satu
yang hidup secara bawaan:**

- **Dalam aplikasi, selalu** — baris masuk ke tabel notifikasi. "It needs no external
  service, so it is the channel that actually works on every installation."
- **Email, hanya bila** `notifications.email_enabled` menyala **dan** penerimanya punya
  alamat. **Mati secara bawaan**, karena mailer bawaan pada instalasi baru adalah `log`,
  dan "silently writing approval traffic into the application log is worse than not
  sending it".
- **WhatsApp tidak ada.** Butuh akun gateway dan template yang disetujui Meta, "none of
  which can ship inside the application".

**Permukaannya**: lonceng di header. Ia **polling setiap 90 detik**, bukan push, dan
dijeda saat tab tidak terlihat — tidak ada endpoint websocket/SSE, dan menambahkannya
berarti satu koneksi persisten per tab demi lencana yang berubah beberapa kali sehari.

**Siapa yang menerima** — pemegang izin yang **aktif**:

| Izin | Akun aktif yang menerima |
|---|---|
| `core.approve` (semua alarm cadangan) | admin, direktur |
| `fin.post` (tutup buku, kalender tahun baru) | admin, finance |
| `fin.create` (termin, retensi, invoice AR, pajak masa) | admin, finance |
| `prc.update` / `prc.create` | admin, procurement |
| `crm.approve` / `crm.update` | admin + direktur / admin + sales |
| `ast.update` | admin, project-manager |
| `hr.update` | admin, hr |
| `prj.update` | admin, project-manager, site-manager |
| `scm.update` | admin, project-manager |

> **Akun `admin` memegang setiap izin di sistem, jadi kotak masuk administrator
> menerima SETIAP kelompok alarm** — cadangan, tutup buku, dan kedelapan belas pengawas
> tenggat sekaligus. **Tidak ada penyaringan per-jenis di sisi penerima.** Kotak masuk
> yang penuh adalah kotak masuk yang berhenti dibaca, dan itu persis kegagalan yang
> dedupe dan jendela renag dirancang untuk mencegah.

Bila **tidak ada** pemegang aktif suatu izin, alarmnya hilang; yang tertinggal hanya
satu baris peringatan di log aplikasi.

**Cara mematikan alarm — dan mengapa "mematikan" bukan kata yang tepat.** Ada dua
peredam:

1. **Selama satu salinan belum dibaca**, judul yang sama tidak dikirim ulang ke orang
   itu. **Menandai dibaca justru membuka pintu untuk kiriman berikutnya.**
2. **Jendela renag** — hanya dipakai `erp:deadline-watch`: 7 hari untuk tenggat yang
   menipis, **3 hari** untuk yang sudah lewat ("Overdue nags harder than approaching").
   `erp:backup-watch`, `fin:close-watch`, dan `fin:ensure-calendar` **tidak** memakai
   jendela ini.

> **Menandai dibaca bukan menyelesaikan.** Bagi ketiga penerbit itu, alarm yang dibaca
> hari ini **berbunyi lagi besok pagi** — ini diuji, bukan efek samping. Satu-satunya
> cara menghentikan alarm cadangan adalah memperbaiki cadangannya; satu-satunya cara
> menghentikan "Periode 2026-02 belum ditutup" adalah menutup periodenya.

**Jalur yang tidak pernah boleh gagal.** Seluruh pengiriman dibungkus penjaga yang
menelan setiap kesalahan menjadi baris log. Aturannya ditulis di kepala kelasnya:
*"notifying must never break the thing it is reporting on … a lost notification is an
inconvenience, a lost approval is a corrupted book."* Untuk Anda artinya: **kegagalan
pengiriman notifikasi tidak akan pernah muncul di layar siapa pun; ia hanya ada di log
aplikasi.**

Detail kosmetik yang jujur disebut: lonceng hanya mengenal tiga peristiwa dokumen untuk
lencananya, jadi **alarm sistem tampil dengan lencana "—" tanpa warna** — bukan merah,
bukan kuning.

### 5.11 Registri tenggat — apa yang diawasi

Satu daftar deklaratif, **18 entri**. "In the taste of AuditedModels: one declarative
list, so the next date worth watching is added as one array entry — never a new command,
never a second loop."

| Kunci | Tanggal yang diawasi | Lead | Izin penerima |
|---|---|---|---|
| `quotation_valid_until` | Masa berlaku penawaran | 14 hari | `crm.update` |
| `contract_end` | Akhir kontrak | 30 | `crm.approve` |
| `termin_due` | Jatuh tempo termin | 7 | `fin.create` |
| `guarantee_end` | Akhir jaminan † | 30 | `crm.approve` |
| `bast_retention_release` | Jatuh tempo pelepasan retensi BAST | 14 | `fin.create` |
| `safety_incident_due` | Tindak lanjut insiden K3 | 3 | `prj.update` |
| `po_expected` | Tanggal barang diharapkan | **0** | `prc.update` |
| `pr_needed` | Tanggal kebutuhan PR | 7 | `prc.create` |
| `subcontract_end` | Akhir SPK subkon | 14 | `scm.update` |
| `milestone_due` | Jatuh tempo milestone | 7 | `prj.update` |
| `ar_invoice_due` | Jatuh tempo invoice AR | **0** | `fin.create` |
| `maintenance_next_due` | Perawatan aset berikutnya ‡ | 14 | `ast.update` |
| `deployment_planned_until` | Rencana akhir mobilisasi | 7 | `ast.update` |
| `svc_contract_period_end` | Akhir kontrak layanan | 60 | `crm.update` |
| `pkwt_end` | Akhir PKWT karyawan ‡ | 60 | `hr.update` |
| `certificate_expiry` | Kedaluwarsa sertifikat | 60 | `hr.update` |
| `vendor_document_valid_until` | Masa berlaku dokumen vendor † | 30 | `prc.update` |
| `tax_masa_due` | Jatuh tempo pajak masa | 7 | `fin.create` |

† Dokumen "berlaku s/d" masih sah **pada** hari terakhirnya, jadi hari itu terbaca
"menipis hari ini" dan "lewat" baru mulai keesokan harinya. ‡ Tanggal yang **kosong
adalah alarmnya sendiri**.

**Lead 0 berarti hanya keterlambatan yang berbunyi** — "a future expected date is
normal, only lateness alarms."

Tiap entri membawa **cakupan**: penawaran yang sudah menang/kalah, PO yang sudah
ditutup, mobilisasi yang sudah kembali, jaminan yang sudah dilepas — semuanya keluar
dari cakupan, "so an alarm always has an action left".

> **Layar Tenggat adalah kebenaran hidupnya, bukan kotak masuk.** Rute `#tenggat`
> menjalankan pemindaian yang sama secara langsung dan menyaringnya ke izin yang Anda
> pegang: *"A notification can be read on a Tuesday and forgotten by Friday; this
> endpoint re-runs the same scan the daily command runs, so an unresolved deadline stays
> visible no matter what happened in anyone's inbox."* **Konsekuensinya: kalau
> `erp:deadline-watch` mati, tidak ada data yang hilang — hanya dorongan hariannya.**
> Layar Kalender menyusun registri yang sama, supaya keduanya tidak bisa berselisih.

**Menambah satu pengawas** berarti menambah **satu entri array** di
`Modules/Core/Support/WatchedDeadlines.php` — tidak ada perintah baru, tidak ada
perulangan kedua. Ini pekerjaan pengembang, disebut di sini supaya Anda tahu bahwa
permintaan "tolong awasi tanggal X juga" adalah perubahan kecil, bukan proyek.

### 5.12 Yang tidak ada pada rutinitas

- **Tidak ada layar apa pun di aplikasi yang menampilkan status cadangan.** Satu-satunya
  permukaan kesehatan cadangan di dalam aplikasi adalah alarm lonceng. Kalau alarm itu
  ditandai dibaca dan cron-nya mati, tempat memeriksanya hanya
  `/var/log/erp1-backup.log` dan `/var/lib/erp1/offsite-status.json` di server.
- **Tidak ada yang mengawasi penjadwal.** §5.2.
- **Tidak ada perintah, layar, atau endpoint yang menjalankan kejar-tayang akrual alat
  lintas-bulan sekaligus.** Kejar-tayangnya adalah beberapa invokasi terpisah,
  dijalankan tangan, dari yang tertua.
- **Tidak ada notifikasi email yang menyala secara bawaan, dan tidak ada saluran
  WhatsApp sama sekali.**
- **Tidak ada halaman riwayat atau arsip alarm sistem di luar lonceng**, dan tidak ada
  penyaringan menurut jenis peristiwa di server.
- **Tidak ada perintah untuk membatalkan atau menghapus baris akrual alat yang telanjur
  salah.** Koreksi hanya lewat menjalankan ulang bulan yang sama (yang menimpa) atau
  lewat residual saat demobilisasi.
- **Tidak ada `--dry-run`** pada tujuh dari delapan perintah; hanya
  `erp:harden-demo-logins` yang punya.

---

## 6. Tutup buku bulanan

### 6.1 Sebelum apa pun: bagi perusahaan ini, setiap penutupan tidak dapat dibatalkan

Baca paragraf ini **sebelum** penutupan pertama, bukan sesudahnya.

Butir daftar periksa nomor 7 (**pengakuan pendapatan PSAK 115**) adalah **penghalang
keras** setiap kali ada kontrak konstruksi atau sistem integrasi yang berstatus
**`approved` ATAU `closed`** — `itemRevenueRecognition` menguji keduanya, jadi
perusahaan yang seluruh kontrak dalam-cakupannya sudah **ditutup** tetap terkena blok
keras ini. Satu-satunya cara membersihkannya adalah **memposting run PSAK 115 untuk
bulan itu**. Begitu run itu diposting, aturan pembukaan kembali menemukannya dan **menolak
membuka bulan itu DAN setiap bulan sebelumnya, selamanya.**

Basis data hidup punya dua kontrak semacam itu — `CTR/2026/I/0001` (konstruksi) dan
`CTR/2026/II/0002` (sistem integrasi), keduanya disetujui. **Jadi ini berlaku untuk
erp1 hari ini.** Tombol "Buka Kembali" yang Anda andalkan akan nonaktif selamanya,
dengan kalimat: *"Run yang sudah diposting tidak dapat dihitung ulang, jadi periode ini
tidak dapat dibuka lagi — koreksi yang ditemukan hari ini dibukukan hari ini."*

Hanya perusahaan yang **tidak punya satu pun** kontrak konstruksi atau sistem integrasi
berstatus disetujui maupun ditutup (butir 7 berstatus "tidak berlaku") yang menutup
bulan yang masih bisa dibuka kembali.

Kebijakannya sendiri ada di [`KEBIJAKAN-PENDAPATAN.md`](KEBIJAKAN-PENDAPATAN.md).

### 6.2 Di mana Anda berdiri

Menu: **Keuangan → Periode Fiskal**. Lima tindakan:

| Tindakan | Izin |
|---|---|
| Melihat kalender satu tahun | `fin.view` |
| Membuat kalender satu tahun | `fin.create` |
| Menghitung daftar periksa satu periode | `fin.view` |
| **Menutup** | **`fin.post`** |
| **Membuka kembali** | **`fin.approve`** |

Pemisahannya disengaja: *"Membuka kembali mengubah angka yang sudah dilaporkan, jadi
batasnya HARUS lebih tinggi daripada yang membukanya… Siapa pun yang bisa memposting
tidak boleh bisa membuka sendiri periode yang ingin diisinya."* Pada peran bawaan,
`finance` memegang `fin.post` tetapi **bukan** `fin.approve`.

> **Admin memegang keduanya, dan karena itu secara pribadi mengalahkan pemisahan ini.**
> Admin yang menutup lalu membuka kembali periodenya sendiri meninggalkan jejak audit
> dua baris yang menunjukkan persis itu — dan jejak itulah satu-satunya yang tersisa.

**Tidak ada perintah artisan untuk menutup atau membuka periode.** Menutup adalah
tindakan layar, titik.

Daftar periksa sengaja dipisahkan dari daftar kalender karena mahal: ia menjalankan
satu rekonsiliasi bank per rekening aktif, satu neraca saldo, dan satu ikhtisar ekspor
pajak — jadi ia berjalan atas klik eksplisit pada **satu** periode, tidak pernah untuk
setahun penuh.

### 6.3 Sebelas butir daftar periksa

Daftar ini dikembalikan **dalam urutan tutup buku — layar yang dibaca dari atas ke
bawah ITULAH runbook akhir bulan Anda.**

Hanya status **gagal** yang dihitung. Butir bertanda "tidak berlaku" — artinya
"perusahaan ini tidak memakai bagian itu" — tidak pernah menghalangi dan tidak perlu
diakui. **Tujuh dari sebelas butir punya cabang "tidak berlaku"** — butir 3, 4, 5, 7, 9,
10, dan 11; kolom "Kapan tidak berlaku" di tabel bawah menyebut syarat masing-masing.
Keempat sisanya — butir 1, 2, 6, dan 8 — tidak pernah bisa "tidak berlaku": tidak ada
cabang `NA` untuk mereka di `PeriodCloseService`, jadi keempatnya selalu dinilai dan
selalu bisa memblokir.

| # | Butir | Tingkat | Kapan "tidak berlaku" | Cara membersihkannya |
|---|---|---|---|---|
| 1 | Periode sudah berakhir | **BLOK** | — | Tunggu. Tidak ada pengabaian. Pemeriksaannya ketat: pada 30 Juni, Juni belum berakhir |
| 2 | Periode sebelumnya sudah ditutup | **BLOK** | — | Tutup bulan yang disebutkannya lebih dulu. Yang diperiksa adalah "setiap periode lebih lama yang ADA", bukan "setiap bulan lebih lama" |
| 3 | Payroll bulan ini sudah ada | Peringatan | Tidak ada karyawan | Buat run payroll di SDM & Payroll → Payroll. **Run DRAF adalah hal lain sama sekali dan merupakan blok keras**, lewat butir 6 |
| 4 | Penyusutan bulan ini sudah ada | Peringatan | Tidak ada aset ber-umur-manfaat | Buat run di Aset → Penyusutan |
| 5 | Akrual alat internal sudah dicatat | Peringatan | Periode belum berakhir, atau tidak ada mobilisasi aktif bertarif | Jalankan `ast:accrue-plant {tahun} {bulan}` — pesannya mencetak perintahnya, menyebut sampai tiga mobilisasi dengan hari dan rupiahnya |
| 6 | Tidak ada dokumen menggantung | **BLOK** | — | §6.4 |
| 7 | Pengakuan pendapatan PSAK 115 sudah diposting | **BLOK** ‡ | Tidak ada kontrak konstruksi/sistem integrasi berstatus `approved` **maupun** `closed` — kontrak yang sudah ditutup **tetap** menghidupkan blok ini | Hitung dan posting run bulan itu, berurutan maju |
| 8 | Neraca saldo seimbang | **BLOK** | — | Telusuri jurnalnya. Tidak ada pengabaian |
| 9 | Sub-ledger AP/AR cocok dengan buku besar | Peringatan | Akun 1-1300 atau 2-1100 tidak ada di bagan | Telusuri jurnal manual pada 1-1300/2-1100 |
| 10 | Rekening bank sudah terekonsiliasi | Peringatan | Tidak ada rekening bank aktif | Impor rekening koran dan rekonsiliasi — **tidak pernah memblokir** |
| 11 | Ekspor pajak siap | Peringatan | Bulan itu tidak punya dokumen keluaran/potongan | Lengkapi nomor faktur, NPWP, jenis PPh — **tidak pernah memblokir** |

‡ Butir 7 **melunak menjadi peringatan** bila sudah ada run terposting untuk periode
yang **lebih baru** — karena urutan maju-saja berarti bulan ini tidak akan pernah bisa
diukur lagi. Pesannya menyatakan penutupan boleh lanjut tetapi *"bulan ini tetap
tercatat di atas dasar penagihan, bukan persentase penyelesaian."*

**Alasan tiap peringatan menjadi peringatan, bukan blok** — bawa ini ke percakapan
dengan finance:

- **Payroll (3)**: perusahaan yang modul HR-nya belum hidup akan terjepit selamanya,
  "dan blok keras yang tidak bisa dipenuhi bisnisnya sama buruknya dengan tidak ada
  kontrol sama sekali". Tetapi konsekuensinya nyata dan tertulis di pesannya: *"upah
  bulan ini tidak akan pernah masuk ke buku besar bulan ini dan biaya proyek understated
  selamanya."*
- **Penyusutan (4)**: pesannya menyebut biayanya, dan itu **benar secara harfiah** —
  run penyusutan menolak periode yang berada pada atau sebelum run terposting terakhir,
  jadi *"bulan yang dilewati tidak dapat disusutkan menyusul dan beban itu hilang
  permanen."*
- **Akrual alat (5)**: bebannya alokasi manajemen yang tidak pernah menyentuh buku besar
  maupun neraca saldo, "so the closer may accept the understatement in writing". Ia ada
  di daftar periksa dan bukan hanya di jadwal malam **karena cron yang melewatkan satu
  bulan gagal dalam senyap; satu baris daftar periksa tidak.**
- **Sub-ledger (9)**: satu selisih yang sah memang ada secara rancangan — dokumen yang
  dibatalkan setelah periodenya membalik pada tanggal **hari ini**, jadi buku besar akhir
  periode masih membawa dokumen yang sudah tidak ditampilkan sub-ledger.
- **Bank (10)**: *"The statement arrives days to weeks after month end, from a third
  party… Blocking here would make the close hostage to the post office."*
- **Ekspor pajak (11)**: penghalang tersering adalah nomor seri faktur pajak yang datang
  **dari DJP**, dan ekspornya membaca dokumen terposting, bukan memposting — ia bisa
  dibuat setelah buku ditutup.

Beberapa catatan yang mencegah salah kejar:

> **Neraca saldo Januari yang masih membawa pendapatan tahun lalu itu BENAR, bukan
> kerusakan yang harus dikejar.** Tidak ada jurnal penutup di produk ini, jadi membuka
> tahun fiskal dengan 4-1100 pada nol akan membuat debit ≠ kredit persis sebesar laba
> tahun lalu — "laporan itu akan menciptakan sendiri kerusakan yang ia ada untuk
> mendeteksinya". Penggulungannya terjadi di **neraca**, bukan di sini (§7).

> **Butir bank berubah hijau atas "tuntas", bukan atas "jembatan cocok".** Jembatan yang
> residualnya nol masih bisa menyembunyikan kesalahan pembukuan nyata: dua item terbuka
> di sisi berlawanan dengan nilai nyaris sama saling meniadakan — *"the bank moved Rp 300
> juta, the ERP recorded Rp 350 juta… Arithmetically fine, Rp 50 juta wrong."* Di layar
> keadaan itu terbaca **"Jembatan cocok"** berwarna kuning, bukan **"Tuntas"** hijau.
> Selisih saldo awal saja juga cukup membuat sebuah rekening tidak "tuntas".
>
> Dan butir ini **hanya memeriksa rekening yang aktif**. Rekening bank yang
> dinonaktifkan tetapi masih menyimpan uang **tidak terlihat** oleh daftar periksa.

> **Menjual aset dengan penerimaan membuat butir 9 gagal untuk bulan itu.** Bagan akun
> tidak punya Piutang Lain-lain, jadi piutang penjualan aset dibukukan ke **1-1300
> Piutang Usaha** — saldo itu tampak di buku besar tetapi **tidak** di umur piutang yang
> digerakkan invoice, dan butir 9 membandingkan keduanya. Ini peringatan, jadi penutupan
> bisa lanjut dengan alasan tertulis, **tetapi penutup pertama setelah penjualan aset
> sebaiknya diberi tahu di depan** alih-alih bertemu kegagalan tie-out yang tak
> terjelaskan. Perbaikannya satu JV manual (Dr Bank / Cr 1-1300).

### 6.4 Butir 6: dokumen menggantung

Definisinya: **setiap dokumen yang tanggal pemostingannya TERPAKU di dalam periode
fiskal itu**. Sebuah JV bertanggal 2026-06-30 yang ditinggal draf bisa diposting sampai
detik Juni ditutup; begitu ditutup, penjagaan periode menolaknya **selamanya**, dan
dokumen itu menjadi yatim yang tidak pernah dibuka siapa pun lagi.

Lima belas sumber. Status yang dihitung "belum masuk buku besar":

| Dokumen | Status yang menggantung | Hanya di perpetual |
|---|---|---|
| Jurnal | draf | — |
| Invoice termin | draf, diajukan | — |
| Tagihan vendor | draf, diajukan | — |
| Pembayaran | draf, diajukan, **disetujui** | — |
| Voucher kas kecil | draf saja | — |
| Kasbon | draf saja | — |
| Payroll | draf, diajukan | — |
| Penyusutan | draf | — |
| Penerimaan barang | draf | ya |
| Pengeluaran barang | draf | ya |
| Retur material | draf | ya |
| Retur pembelian | draf | ya |
| Opname persediaan | draf, diajukan, **disetujui yang belum terposting** | ya |
| Transfer gudang | draf, **dalam perjalanan** | ya |
| Laporan lapangan | diajukan, **hanya bila membawa baris suku cadang** | ya |

Alasan yang layak dibawa:

- **Pembayaran yang sudah DISETUJUI adalah yang terburuk dari semuanya**, "because
  somebody has already agreed to pay it".
- **Kasbon: draf saja, sengaja.** Kasbon yang sudah diterbitkan tidak menghalangi —
  uang mukanya sudah terposting dan saldo yang belum dipertanggungjawabkan adalah
  piutang yang memang beredar, tersaji benar pada akhir periode; "demanding settlement
  before close would force fake pertanggungjawaban on real month boundaries".
- **Transfer dalam perjalanan** dicantumkan karena itulah satu-satunya keadaan yang
  **terbukti** membuat sub-buku stok tidak sama dengan GL 1-1400: barangnya sudah
  keluar dari saldo satu gudang dan belum sampai ke gudang lain sementara buku besar
  masih membawanya, dan baris kedatangannya mendarat di **bulan berikutnya**.
- **Opname yang disetujui tanpa jejak pemostingan** berarti transaksinya tidak selesai
  — "Rare, and exactly the kind of residue a close must not bury."
- **Laporan lapangan hanya dihitung bila membawa suku cadang**; laporan bertanda tangan
  saja tidak menerbitkan apa-apa dan tidak memaku apa pun, "and blocking a close on it
  would be the theatre this list exists to avoid".
- **Dokumen DITOLAK sengaja dikecualikan**: tagihan yang ditolak bisa disunting, jadi
  mengubah tanggalnya adalah koreksi normal.
- **Keenam sumber persediaan ditambah laporan lapangan — tujuh baris, persis yang
  bertanda "ya" pada kolom terakhir tabel di atas — hilang seluruhnya di bawah metode
  periodik.** Keenamnya: penerimaan barang, pengeluaran barang, retur material, retur
  pembelian, opname persediaan, transfer gudang (`perpetual_only => true` di
  `Modules/Finance/Support/DanglingDocuments.php`). Alasannya: pergerakan stok tidak
  menulis baris buku besar, jadi tanggalnya tidak terpaku ke periode fiskal. Itu
  keputusan yang dipatri uji, bukan kelalaian.

> **Teks butirnya menyuruh Anda "posting, hapus, atau ubah tanggalnya" — dan itu hanya
> benar untuk dokumen DRAF (dan yang DITOLAK).** Dokumen yang sudah **diajukan** atau
> **disetujui** tidak bisa disunting maupun dihapus. Prosedur sebenarnya untuk invoice,
> tagihan, run payroll, atau pembayaran yang sudah diajukan adalah: **tolak dulu** —
> yang menuntut `fin.approve`, izin **lebih tinggi** daripada yang dipegang penutup —
> **baru** sunting atau hapus.

> **Tombol "Buka" pada baris kasbon dan voucher kas kecil BEKERJA.** Ketiga kunci
> layarnya terdaftar di daftar sumber daya SPA — `finance/petty-cash-funds`,
> `finance/petty-cash-vouchers`, dan `finance/kasbon`, semuanya pada lingkup modul
> `public/app/js/views/kaskecil.js` (baris 76, 111, dan 169), dan `app.js:40` mengimpor
> berkas itu sehingga registrasinya berjalan sebelum rute dipasang. Tautan yang dipakai
> daftar periksa (`r/finance/petty-cash-vouchers` dan `r/finance/kasbon`) maupun entri
> navigasi **Keuangan → Kas Kecil & Kasbon** (`r/finance/petty-cash-funds`) ketiganya
> sampai ke layarnya.
>
> Yang perlu diketahui: tombol "Buka" — pada **setiap** sumber, bukan hanya kedua ini —
> membuka **daftarnya**, bukan dokumen yang disebut daftar periksa. Kode dokumennya ada
> di teks butir; carilah baris itu di daftar. Voucher dan kasbon **draf** bisa disunting
> dan dihapus langsung dari daftar itu, dan draf adalah satu-satunya status kedua sumber
> ini yang pernah menggantung.

Daftar periksa **tidak melaporkan rupiah** untuk dokumen menggantung — hanya jumlah dan
sampai lima kode per sumber. Angka uang untuk stok yang terdampar dalam perjalanan
hanya ada di CLI `erp:inventory-method-check`.

### 6.5 Menutup: apa yang benar-benar dilakukan tombolnya

Di dalam satu transaksi:

1. Membaca ulang periodenya. Penutup kedua mendapat *"Periode {label} sudah ditutup."*
2. **Menghitung ulang seluruh daftar periksa** — "karena antara menggambar layar dan
   menekan tombol sebuah draf jurnal bisa muncul". Ada uji khusus untuk itu.
3. Blok yang gagal ⇒ tolak: *"Periode {label} belum dapat ditutup: {…}."*
4. Setiap **peringatan** yang gagal wajib muncul di daftar pengakuan, atau tolak:
   *"Peringatan berikut belum diakui: {…}. Centang semuanya dan tulis alasannya."*
5. Yang dicatat sebagai pengabaian adalah **peringatan yang benar-benar gagal**, bukan
   apa yang dikirim layar — supaya periode bersih yang semua kotaknya tercentang tidak
   dipaksa mengarang alasan untuk sesuatu yang tidak ada.
6. Bila ada minimal satu pengabaian, alasan wajib **≥ 10 karakter**: *"Alasan wajib
   diisi bila ada peringatan yang diabaikan — minimal 10 karakter, dan tercatat
   permanen."*
7. Menulis status tertutup, waktu, dan siapa yang menutup.
8. Menulis satu baris riwayat: tindakan, pengguna, alasan, daftar pengabaian, dan
   **seluruh daftar periksa sebagai potret**.

Potret itu **bukti, tidak pernah gerbang** — dinyatakan di kode dan diulang di
migrasinya: *"a snapshot that were trusted would go stale the moment somebody posted the
draft journal it complained about."*

Di layar, dialog hanya menampilkan peringatan yang benar-benar gagal, satu kotak
centang masing-masing, plus kotak alasan dengan teks bantuan: *"Minimal 10 karakter.
Inilah satu-satunya penjelasan yang akan dibaca auditor."* Dialog itu **sengaja
memfokuskan kotak centang pertama, bukan tombol utamanya** — tanpa itu, sebuah periode
bersih membuka dialog dengan "Tutup Periode" berada di bawah tombol Enter, dan *"satu
refleks menutup buku sebulan penuh."*

Peringatan dialog itu sendiri, dan ia benar: *"Setelah {bulan} ditutup, tidak ada
dokumen yang dapat diposting pada tanggal di dalamnya, dan pembatalan dokumen lama akan
dibalik dengan jurnal bertanggal hari ini — bukan tanggal aslinya."*

> **Tombolnya aktif meski ada peringatan yang belum diakui** — "bisa ditutup" hanya
> mempertimbangkan blok keras. Pengakuan dan alasannya ditegakkan di dalam dialog **dan
> sekali lagi di dalam transaksi penutupan.**
>
> **Teks alasan permanen dan tidak bisa diubah.** Tidak ada rute untuk menyunting atau
> menghapus baris riwayat penutupan.
>
> **Sebuah periode baru bisa ditutup sehari setelah ia berakhir.** Jangan menjanjikan
> penutupan di hari yang sama.

### 6.6 Apa yang ditolak periode yang sudah ditutup

Penjagaan jurnal adalah *"THE control the whole posting layer runs through"*, dan
dicapai dari pemostingan jurnal serta lewat pemostingan otomatis dari persetujuan
tagihan AP, persetujuan invoice AR, pemostingan pembayaran, voucher kas kecil,
pelunasan kasbon, dan pelepasan aset.

Dua gerbang tambahan yang akan Anda temui:

- **Stok.** Pertanyaan yang sama diajukan **di depan**, atas apakah pemostingan buku
  besar menyala — **bukan** atas nilai dokumennya. Lubang yang ini tutup: setiap metode
  pemostingan keluar lebih awal ketika nilainya membulat ke nol, dan kedua jalur
  transfer tidak pernah memanggilnya sama sekali — sehingga **opname bernilai bersih
  nol memindahkan Rp 150.000 nilai antara dua item di dalam periode yang sudah
  ditandatangani, tanpa jurnal, tanpa kesalahan, dan tanpa apa pun di daftar periksa.**
  *"A fiscal period governs WHEN a movement may be recorded, not whether a journal
  happens to come out of THAT document."* Dokumennya **di-rollback**.
- **Biaya proyek.** Baris biaya bertanggal di dalam bulan tertutup ditolak, karena beban
  alat internal tidak memposting jurnal dan karenanya tidak mewarisi penjagaan apa pun.
  **Periode yang HILANG sengaja bukan penolakan di sini; hanya bulan yang sudah
  ditandatangani seseorang.** Insidennya nyata: mendemobilisasi satu alat pada
  2026-07-08 dengan tanggal kembali 2026-06-15 menulis Rp 265.000.000 biaya peralatan ke
  dalam Juni yang sudah ditutup dan yang run PSAK 115-nya sudah diposting dan
  dilaporkan.

> **Pembatalan memberi tanggal ulang pada dirinya sendiri.** Jurnal pembalik memakai
> tanggal asli dokumen **hanya selama periode itu masih terbuka DAN belum diukur**;
> selain itu ia mendarat **hari ini**. Bila hari ini pun tidak bisa diposting,
> operatornya diberi tahu alih-alih pembalikannya diam-diam jatuh kembali ke periode
> yang baru saja ditolak.
>
> Alasannya, dengan angka: Maret menagih Rp 9,7 M atas Rp 6,0 M yang diperoleh,
> sehingga run memarkir Rp 3,7 M di Liabilitas Kontrak. Membalikkan invoice itu kembali
> ke Maret membuat pendapatan Maret **negatif** sebesar Rp 3,7 M sementara April
> membukukan kejar-tayang Rp 9,7 M tanpa penyeimbang — **satu pembatalan, dua laporan
> laba rugi yang salah.** Bertanggal hari ini, Maret berdiri seperti dilaporkan dan
> April menjadi nol bersih. *"A cancellation discovered today is an event of today."*

### 6.7 Membuka kembali

Alasan **minimal 10 karakter wajib, selalu** — bahkan pada pembukaan yang bersih:
*"Alasan membuka periode wajib diisi — ini tercatat permanen."* Membuka menghapus
catatan siapa dan kapan menutupnya, lalu menulis baris riwayat baru; **baris penutupan
di atasnya tetap ada** — kedua tindakan tinggal di riwayat.

Layar menampilkan kalimat penolakan **di sebelah tombol yang DINONAKTIFKAN, bukan
menyembunyikan tombolnya** — "a control the user cannot see is a control they will ask
about by email". Tiga penolakan, berurutan:

1. **Tidak sedang tertutup.**
2. **Ada periode tertutup yang lebih baru di atasnya** — *"Buka periode terbaru lebih
   dulu."* Pembukaan berjalan **terbaru dulu**, kebalikan dari penutupan, karena
   membuka Maret di bawah April dan Mei yang tertutup akan mendorong perubahan Maret ke
   dalam saldo awal yang sudah tertutup dan tidak bisa diturunkan ulang.
3. **Yang permanen — sudah diukur run PSAK 115 yang terposting** (§6.1). Bahkan pada
   varian bulan yang sama, periode run-nya dieja, karena nomor dokumen dicetak dari
   bulan **hari ini**: run Juni yang diposting pada Agustus bernama `POC/2026/08/001`,
   dan "Periode Juni 2026 sudah diukur oleh POC/2026/08/001" akan terbaca seperti salah
   pasang.

**Tidak ada pembukaan paksa.** Tidak ada argumen pengabaian, tidak ada izin, tidak ada
flag, dan tidak ada setelan yang melewati penolakan PSAK 115 maupun urutan
terbaru-dulu.

### 6.8 Keadaan hidup per 22 Agustus 2026

- **2026-01 tertutup; 2026-02 sampai 2026-12 terbuka. Tidak ada baris 2027 sama
  sekali.** Januari yang tertutup itu **tanpa catatan siapa yang menutup dan tanpa baris
  riwayat** — ia ditutup seeder sebelum tabel riwayat ada. Layar mengatakannya dengan
  jujur: *"Periode ini ditutup sebelum riwayat penutupan dicatat."* Jangan berasumsi
  setiap periode tertutup membawa jejak audit.
- **Berikutnya dalam antrean adalah Februari 2026** — bulan berakhir tertua yang masih
  terbuka. Enam bulan sudah lewat tenggat menurut definisi §5.7 (berakhir dan masih
  terbuka lebih dari 10 hari): Februari sampai Juli.
- **Nol run pengakuan pendapatan.** Jadi butir 7 adalah **blok keras yang gagal pada
  setiap bulan dari Februari ke depan**, dan tidak ada bulan yang bisa ditutup sampai
  sebuah run dihitung dan diposting untuknya — **berurutan maju, Februari lebih dulu.**
- Payroll: dua run disetujui (Maret dan Juni) — peringatan payroll terpenuhi untuk kedua
  bulan itu dan gagal untuk selebihnya.
- Penyusutan: satu run terposting (Juni). **Karena run penyusutan maju-saja,
  Februari sampai Mei 2026 tidak bisa lagi disusutkan sama sekali — beban itu hilang.**
- Tiga mobilisasi aktif bertarif harian positif — tetapi Maret–Juli sudah terakru
  oleh kejar-tayang 22 Agustus (§12(d)), jadi butir akrual alat diam untuk kelima
  bulan itu dan baru berbunyi lagi untuk Agustus begitu bulannya berakhir tanpa
  akrual.
- Dua rekening bank aktif, dua rekening koran terimpor. Tidak ada jurnal draf.

### 6.9 Yang tidak ada pada tutup buku

- **Tidak ada perintah artisan yang menutup atau membuka periode.**
- **Tidak ada penutupan massal dan tidak ada pembukaan massal** — satu periode per
  permintaan.
- **Tidak ada jalur pengabaian untuk kelima blok keras.** Hanya peringatan yang bisa
  diakui; blok yang gagal tidak punya pintu darurat jenis apa pun.
- **Tidak ada pembukaan paksa.**
- **Tidak ada cara menyunting atau menghapus baris riwayat penutupan** — riwayatnya
  hanya-tambah tanpa rute perawatan.
- **Daftar periksa tidak melaporkan rupiah** untuk dokumen menggantung.
- **Tidak ada layar yang mendaftar setiap periode terbuka lintas tahun sekaligus**;
  layar kalender per tahun, dan hanya notifikasi harian `fin:close-watch` yang menyebut
  bulan terlambat tertua.
- **Kedua ambang tutup buku tidak bisa disunting dari layar mana pun** (§4.6).

---

## 7. Tutup buku tahunan

**Tidak ada jurnal penutup di produk ini.** Dinyatakan terus terang di kode: *"NO JURNAL
PENUTUP EXISTS IN THIS LEDGER. Nothing anywhere posts a year-end closing entry to 3-2100
Laba Ditahan — grep finds the account only in the chart of accounts and in comments
telling a human to do it."*

Konsekuensinya, dan keduanya benar sekaligus:

- **Pada 1 Januari, akun laba-rugi masih membawa tahun lalu.** 4-1100 membuka neraca
  saldo Januari dengan saldo kredit. **Itu perilaku yang benar** (§6.3), bukan kesalahan
  yang harus dikejar sebelum menutup.
- **Neraca melakukan penggulungannya secara aritmetika, bukan lewat pemostingan.** Ia
  menyajikan segala yang dihasilkan laba-rugi **sebelum 1 Januari** sebagai satu baris
  sintetis **"Laba Ditahan (belum dijurnal tutup)"**, dan hanya hasil **tahun fiskal
  berjalan** sebagai **"Laba Tahun Berjalan"**. Baris sintetis itu hanya muncul bila
  memang ada tahun sebelumnya di buku besar, karena *"A permanent Rp 0,00 row in the
  company's first year invites the opposite question — 'where did our retained earnings
  go?'"*

Rancangan itu bertahan bila akuntan Anda kemudian mengetikkan jurnal penutup sungguhan:
entri itu menolkan pergerakan laba-rugi tahun lalu dan mengkredit 3-2100, sehingga
**baris sintetisnya jatuh ke nol persis saat baris ekuitas yang sungguhan muncul.**
Cacat yang diperbaikinya konkret: direktur yang membuka Neraca pada 2027-01-02 membaca
*"Laba Tahun Berjalan Rp 9.471.760.000"* untuk tahun yang berumur dua hari, tanpa satu
baris Laba Ditahan pun.

**Tahun fiskal adalah tahun kalender.** Dinyatakan dua kali di kode: tabel periode
berkunci (tahun, bulan 1–12) dan *"nothing in the system offers another basis"* — tidak
ada periode ke-13, tidak ada periode penyesuaian, tidak ada tahun April–Maret.

### Yang Anda kerjakan sekali setahun, dari kode

1. **Pastikan kalender tahun berikutnya ada.** `fin:ensure-calendar` melakukannya
   otomatis sejak **1 Oktober** (tiga bulan ke depan), dan mengirim notifikasi
   *"Kalender fiskal {tahun} dibuat"* kepada pemegang `fin.post` dengan pesan:
   *"Periksa format penomoran dokumen dan saldo awal di Keuangan › Periode Fiskal
   sebelum tahun berjalan."* Itu pengingat untuk memeriksa §4.8 — format yang tanpa
   `{Y}` akan meledak justru pada 1 Januari.
2. **Tutup Desember seperti bulan lain.**

**Tidak ada tindakan "tutup tahun buku" yang terpisah dari menutup Desember, tidak ada
pemostingan entri penutup, dan tidak ada apa pun di basis kode yang memintanya.** Bila
akuntan perusahaan menghendaki jurnal penutup sungguhan, itu **JV manual biasa ke
3-2100** yang diketik lewat Keuangan → Jurnal — dan **tidak ada apa pun di ERP yang
membuatkannya, menjadwalkannya, memvalidasinya, atau mengingatkannya.**

---

## 8. Siklus hidup dokumen — dan apa yang tidak bisa dibatalkan

### 8.1 Siklus baku, dan satu kalimat yang menentukan segalanya

Kosakata bersamanya: **draf → diajukan → disetujui**, ditambah **ditolak**, **selesai**,
**dibatalkan**.

> **Hanya `draf` dan `ditolak` yang bisa disunting.** Segala yang lain — **termasuk
> `diajukan`** — beku. Itulah sebabnya "Ajukan" adalah keputusan sungguhan dan bukan
> formalitas: sejak dokumen diajukan, operatornya tidak bisa lagi mengubahnya, hanya
> bisa memintanya ditolak kembali ke mejanya.

Operator hampir selalu berharap masih bisa menyunting selagi menunggu persetujuan.
Mereka tidak bisa. Satu-satunya jalan kembali ke keadaan yang bisa disunting adalah
penyetuju menekan **Tolak** — itulah sebabnya tolak membawa kolom alasan wajib di setiap
layar.

`submit()` hanya menerima draf/ditolak; `approve()` dan `reject()` hanya menerima yang
diajukan. **Tidak ada batal-setuju.** Setiap transisi menulis satu baris ke
`core_approvals` dan memicu notifikasi.

Lima belas model memakai mesin bersama itu (penawaran, CCO, BOQ, RAP, tagihan AP,
invoice AR, cuti, payroll, opname stok, PO, PR, BAST, opname subkon, SPK, addendum SPK),
ditambah dua yang menempuh tahap yang sama tanpa mesinnya: **pembayaran** dan
**baseline proyek**.

### 8.2 Modul yang menyimpang dari siklus baku

| Modul / dokumen | Siklusnya | Yang perlu Anda tahu |
|---|---|---|
| **Jurnal (JV)** | draf → terposting | **Tidak ada langkah Ajukan** — mengetik JV **adalah** pengajuannya, dan barisnya ditulis ke riwayat persetujuan saat itu juga |
| **Pembayaran** | draf → diajukan → disetujui → terposting (+ ditolak, terbalik) | **Hanya pembayaran KELUAR yang menempuh tiga keadaan tengah; penerimaan masuk tetap draf → terposting.** Alasannya: uang masuk dikuatkan dokumen yang tidak dikendalikan perusahaan (rekening koran); uang keluar tidak punya penguat sampai ia sudah keluar |
| **Penerimaan barang & bon material** | draf → terposting, + dibatalkan | **Hanya kedua dokumen ini yang punya jalur pembatalan**: `POST inventory/goods-receipts/{id}/cancel` dan `POST inventory/issues/{id}/cancel` (`Modules/Inventory/Routes/api.php:48` dan `:64`, keduanya `inv.post`), disokong `StockService::cancelReceipt`/`cancelIssue`. "Dibatalkan" **bukan** jalan kembali ke draf |
| **Retur material & retur pembelian** | draf → terposting. **Tidak ada pembatalan** | Rutenya berhenti di `/post` (`Modules/Inventory/Routes/api.php:75` dan `:83`); layarnya hanya membawa tombol **"Posting Retur"**. Retur yang terlanjur diposting dikoreksi dengan **dokumen kedua** — lihat di bawah tabel |
| **Transfer gudang** | draf → dalam perjalanan → diterima | Tidak ada batal, tidak ada tolak |
| **Opname persediaan** | Memakai siklus baku, **tetapi menyetujui dan memposting adalah satu langkah atomik** | **Tidak ada tombol "Posting" terpisah** |
| **Payroll** | Memakai siklus baku, **tetapi menyetujui ADALAH memposting ke buku besar**, dalam satu transaksi | Penyetuju harus memperlakukan **Setujui sebagai tindakan yang tidak dapat dibatalkan** |
| **Aset** | Penyusutan draf → terposting; aset tersedia/termobilisasi/perawatan/terlepas; mobilisasi aktif/kembali | **Tidak ada tahap persetujuan sama sekali** di modul ini |
| **Laporan lapangan** | draf → diajukan → disahkan | Langkah tengah **bisa dikembalikan** ke draf; langkah terakhir **tidak** |
| **Tiket layanan** | Mesin keadaan sungguhan dengan tabel transisi | `closed` dan `cancelled` terminal |
| **Defect & insiden K3** | open/in_progress/… masing-masing | Sengaja **bukan** siklus dokumen: *"an incident is not approved into existence — it happened"* |
| **Kas kecil & kasbon** | Voucher draf → terposting → dibatalkan; kasbon draf → terbit → lunas | **Tidak ada tahap persetujuan**; penjagaannya adalah kepemilikan laci, plafon per voucher, saldo laci, dan mata kedua berupa pembayaran isi ulang |
| **Kontrak CRM** | "Aktifkan" membalikkannya ke disetujui | Setelah memeriksa jadwal termin ada dan persennya berjumlah 100. **Tidak ada ajukan/tolak** |
| **Penawaran** | Persetujuan normal, lalu sepasang hasil: **Tandai Menang** (membuat kontrak, status selesai) / **Tandai Kalah** (menuntut alasan) | **"Buat Revisi" bukan dokumen baru**: ia menaikkan `revision` pada penawaran yang **sama**, mengembalikannya ke draf, dan mengosongkan tanda kalah. Ditolak bila penawarannya sudah menang — *"revise via the contract instead"* |
| **RFQ** | draf → selesai | Bisa disunting hanya selagi draf |

> **Retur yang salah diposting: apa yang harus dikerjakan administrator.** Tidak ada
> tombol batal dan tidak ada endpoint yang membalikkannya. Yang ada hanyalah **dokumen
> kedua yang berlawanan**, dengan tanggal yang Anda isi sendiri — dan tanggal itu tetap
> tunduk pada penjagaan kronologi (tidak boleh mendahului pergerakan terakhir untuk
> pasangan gudang+item) dan penjagaan periode fiskal, jadi praktisnya ia mendarat di
> bulan berjalan, bukan di bulan returnya:
>
> - **Retur material (dari proyek ke gudang) yang salah** → terbitkan **bon pengeluaran
>   baru** untuk item dan kuantitas yang sama ke proyek yang sama. Perhatikan harganya:
>   retur masuk kembali pada **harga baris bonnya yang dibekukan**, sedangkan bon baru
>   keluar pada **rata-rata gudang hari ini**. Bila rata-rata sudah bergeser di antara
>   keduanya, biaya proyek tidak kembali persis ke angka semula.
> - **Retur pembelian (barang ke vendor) yang salah** → posting retur sudah
>   mengembalikan `qty_received` ke PO-nya lewat `PoService::unregisterReceipt()`, yang
>   **juga membuka kembali PO yang sempat tertutup otomatis "so the replacement delivery
>   can be received"**. Jadi jalan kembalinya adalah **penerimaan barang (GRN) baru**
>   atas PO yang sama. Sisi uangnya mengikuti akun kliring yang dicatat penerimaan itu.
>
> Jalan pertama bisa ditolak, dan penolakannya sah: bon pengganti adalah pergerakan
> **keluar**, jadi barangnya harus masih ada di gudang itu — pemeriksaan saldo
> `applyOut` — dan tanggalnya tidak boleh mendahului pergerakan terakhir untuk pasangan
> gudang+item. Bila barangnya sudah terlanjur keluar lagi, yang tersisa adalah **opname**
> untuk kuantitasnya plus **JV** untuk sisi uangnya: Kategori C di §8.4, dengan seluruh
> biayanya — dua bulan yang berbeda, dan laba-rugi bulan pertama tetap salah.

### 8.3 Apa yang memposting ke buku besar, dan pada transisi mana

Semua yang sampai ke buku besar melewati satu service, dan dua penjagaannya berlaku
untuk seluruh sistem: **jurnal harus seimbang** (toleransi 1 sen) dan **periodenya harus
terbuka**.

| Modul | Kapan memposting | Bentuk jurnalnya |
|---|---|---|
| **CRM** | **Tidak pernah** | Kontrak, penawaran, dan CCO tidak memindahkan uang |
| **Estimasi** | **Tidak pernah** | BOQ dan RAP dokumen perencanaan |
| **Pengadaan** | **Tidak pernah** | PO adalah komitmen, bukan entri |
| **Persediaan — GRN** | saat **posting** | Dr 1-1400 Persediaan / Cr liabilitas kliring. **Akun dan jumlahnya DITULIS KE BARIS PENERIMAAN**, dan catatan itu — bukan bentuk PO, bukan nilai saklar perpetual hari ini — satu-satunya yang boleh dilunasi tagihan AP kelak |
| **Persediaan — bon material** | saat **posting** | Dr 5-xxxx beban proyek (atau 6-4100 tanpa proyek) / Cr 1-1400, **plus** baris biaya proyek. Inilah satu-satunya langkah yang mengubah aset menjadi biaya |
| **Persediaan — retur dari proyek** | saat **posting** | Dr Persediaan / Cr HPP, plus baris biaya proyek **negatif** |
| **Persediaan — retur pembelian** | saat **posting** | Stok keluar, irisan kliring penerimaan dibalik, kuantitas diterima dikembalikan ke PO |
| **Persediaan — opname** | saat **posting** | Selisih bersih ke 6-4400 |
| **Persediaan — transfer** | **Tidak pernah, sengaja** | Kedua gudang milik perusahaan yang sama, jadi 1-1400 akan didebet dan dikredit rupiah yang sama. Harganya: antara kirim dan terima, barangnya tidak ada di saldo gudang mana pun sementara buku besar masih membawanya |
| **Keuangan — invoice AR** | saat **disetujui** | Dr 1-1300 / Dr 1-1350 Retensi / Cr 4-xxxx Pendapatan / Cr 2-1300 PPN Keluaran. Juga menerbitkan baris retensi dan menandai terminnya sudah ditagih |
| **Keuangan — tagihan AP** | saat **disetujui** | Salah satu dari tiga bentuk: uang muka, tiga-arah match, atau klasik. **Bentuk mana yang berlaku tidak pernah diturunkan ulang; ia dibaca dari penerimaannya.** Nomor bukti potongnya juga dicetak di sini, sekali dan selamanya |
| **Keuangan — pembayaran** | saat **posting** | Kaki bank plus kaki pelunasan. Penerimaan boleh membawa baris potongan (PPh final, PPN wapu, denda ke 7-2400); invoicenya tetap dilunasi **penuh** |
| **Keuangan — JV** | saat **posting** | Digerbangi `fin.approve`, bukan `fin.post` |
| **Keuangan — voucher kas kecil** | saat **posting** | Dr beban / Cr akun laci, plus baris biaya proyek |
| **Keuangan — kasbon** | saat **terbit** dan saat **lunas** | Pelunasan dimasukkan dan diposting dalam satu transaksi — karena itu kasbon tidak pernah bisa menggantung |
| **Keuangan — retensi pelanggan** | saat **dilepas** | Dr Bank / Cr 1-1350 |
| **Keuangan — PSAK 115** | saat **posting** | Lihat [`KEBIJAKAN-PENDAPATAN.md`](KEBIJAKAN-PENDAPATAN.md) |
| **SDM — payroll** | saat **disetujui** | Dr 5-1200 / 6-1100 / 6-1200 lawan Cr 2-1210 / 2-1120 / 2-1110. BPJS pemberi kerja dipecah satu baris debit per proyek — **buku biaya proyek karenanya sengaja melebihi 5-1200 persis sebesar porsi pemberi kerja** |
| **Aset — penyusutan** | saat **posting** | |
| **Aset — pelepasan** | saat **dilepas** | Akumulasi keluar, harga perolehan keluar, Dr 1-1300 untuk hasil penjualan, laba/rugi ke 7-1200 |
| **Aset — akrual mobilisasi** | bulanan | Menulis baris biaya proyek dan **tidak ada jurnal sama sekali** — itulah sebabnya penjagaan periode harus dipindahkan ke layanan biaya proyek sendiri |
| **Layanan — laporan lapangan** | saat **disahkan** | Memposting satu bon persediaan dalam transaksi yang sama, **bertanggal tanggal kunjungan, bukan tanggal klik** |

### 8.4 Yang tidak bisa dibatalkan — empat kategori

#### Kategori A — bisa dibatalkan lewat jurnal cermin

Jurnal aslinya **tidak pernah disentuh**; pembalikannya membaca **baris aslinya** dan
memposting cerminnya — karena "an AP bill alone has three posting shapes … and a
cancellation that re-computed them would undo something subtly different from what was
booked."

| Dokumen | Syarat pembatalannya |
|---|---|
| Invoice AR | disetujui, **belum dibayar sepeser pun**, ada alasan, dan retensinya belum dilepas. Ia menghapus baris retensi dan mengosongkan tanda "sudah ditagih" pada terminnya |
| Tagihan AP | idem, plus uang mukanya belum dikonsumsi dan retensinya belum dilepas |
| Pembayaran | lihat Kategori B |
| Voucher kas kecil | selama belum tertutup isi ulang yang terposting |
| Penerimaan barang | lihat Kategori B |
| Bon material | lihat Kategori B |

> **Membatalkan invoice AR TIDAK menarik faktur pajak yang sudah dilaporkan ke DJP.**
> Operator tetap harus mengajukan nota pembatalan; yang dijamin sistem hanyalah bahwa
> invoice yang dibatalkan **keluar dari ekspor e-Faktur**. Dan **tagihan AP yang
> dibatalkan tidak melepaskan nomor bukti potongnya** — nomornya tetap dipesan, dan
> tidak ada layar untuk menerbitkan ulang atau membatalkannya.

#### Kategori B — bisa dibatalkan secara prinsip, ditolak begitu ada yang mengonsumsinya

| Yang dibatalkan | Ditolak bila | Jalan keluarnya |
|---|---|---|
| Pembayaran | Ia transfer laci kas kecil | **Transfer sebaliknya** — tombolnya sengaja disembunyikan, "a button that is always refused is worse than no button" |
| Pembayaran | Ada baris rekening koran yang tercocokkan padanya | **Lepaskan pencocokan di Rekonsiliasi Bank lebih dulu.** Tidak ada apa pun di layar pembayaran yang mengatakan ini — hanya penolakan servernya |
| Pembayaran | Retensi invoice yang dilunasinya sudah dilepas, atau dokumennya sudah bergerak dari "disetujui" | — |
| Uang muka AP | Ada tagihan final hidup untuk PO yang sama — **termasuk yang masih DRAF** | **Tarik tagihan finalnya dulu, lalu uang mukanya, lalu terbitkan ulang finalnya** |
| Tagihan opname subkon | Retensi yang ditahannya sudah dilepas | Tagihan pelepasannya lebih dulu |
| Tagihan uang muka subkon | Opname yang disetujui sudah menetralkan uang mukanya | — |
| GRN terposting | Retur pembelian sudah membatalkan sebagiannya; tagihan vendor sudah menyapu kliringnya; sebagian stoknya sudah keluar gudang; atau satu-satunya tagihan final PO menempuh jalur klasik | Tiap penolakan menyebut obatnya sendiri: retur pembelian untuk sebagian, opname untuk susut, nota kredit vendor lewat Keuangan untuk sisi uangnya |
| Bon terposting | Retur material sudah membatalkan sebagiannya | Retur material |
| Bon terposting | **Ia lahir dari pengesahan laporan lapangan — dan ini TIDAK PERNAH bisa dibatalkan** | *"koreksi laporan lapangannya, karena pengesahan dan pengeluaran suku cadang adalah satu peristiwa yang sama"* |

#### Kategori C — tidak ada pembatalan; koreksinya dokumen kedua yang berlawanan

Ini daftar yang harus Anda hafal, karena setiap barisnya adalah percakapan yang akan
Anda hadapi.

| Yang terjadi | Koreksinya |
|---|---|
| **Jurnal yang sudah diposting** | **Tidak ada tombol Batalkan dan tidak ada tombol Balikkan di layar JV mana pun.** Koreksinya adalah mengetik JV yang berlawanan. Jangan menyuruh pembaca mencari tombol yang tidak ada |
| **Transfer gudang** | Terima transfernya, lalu kirim transfer kedua ke arah sebaliknya. Tidak ada jurnal yang diposting pada kedua kaki, jadi tidak ada yang perlu dibalik |
| **Opname stok** | Opname kedua. Biayanya disebut jujur: kedua selisih 6-4400 hanya saling meniadakan bila tidak ada barang berharga berbeda yang bergerak di antaranya, dan keduanya mendarat di bulan berbeda — **laba-rugi bulan pertama tetap salah**. "A reporting cost, not a hole a document can still spend" |
| **Run payroll** | Pemostingan kedua **ditolak**, dan pesannya menyebut "a reversing journal, not a second posting". **Tidak ada tindakan itu di modul — ia harus diketik tangan sebagai JV** |
| **Run penyusutan** | Terposting bersifat terminal; tidak ada batal, tidak ada balik |
| **Pelepasan aset** | Satu arah. Tidak ada yang mengembalikan aset dari status terlepas |
| **Demobilisasi alat** | Satu arah |
| **Pelepasan retensi pelanggan** | Satu arah. **Tidak ada pembatalan pelepasan** |
| **Pengesahan laporan lapangan** | Dari "diajukan" masih bisa dikembalikan ke draf; **dari "disahkan", tidak pernah** — "the signature posted a real bon … Correcting a wrong sign-off is an opname" |
| **Persetujuan itu sendiri** | **Tidak ada dokumen yang bisa dibatalkan persetujuannya.** BOQ, RAP, PR, PO, SPK, addendum, opname, baseline, BAST, atau pengajuan cuti yang sudah disetujui tetap disetujui. Bila butuh pengganti, mekanismenya **dokumen BARU**: BOQ "Versi Baru" (benar-benar menyalin ke baris baru, `BoqService::copyVersion`), opname kedua, addendum kedua. **Penawaran "Buat Revisi" adalah pengecualian** — ia mengembalikan penawaran yang sama ke draf dengan `revision` naik satu, bukan membuat dokumen kedua (§8.2) |

#### Kategori D — penghapusan

Setiap penghapusan adalah **soft delete**, dan **setiap penghapusan digerbangi
"hanya draf atau ditolak"**. Beberapa menambah syaratnya sendiri: penawaran atau kontrak
draf yang membawa jaminan **aktif** tidak bisa dihapus; PR yang sudah punya PO tidak
bisa; SPK yang sudah punya opname tidak bisa; baseline yang sudah diajukan tidak bisa
("minta penolakan lebih dulu"); dan tagihan AP draf mengembalikan penerimaan yang
diklaimnya sebelum ia pergi.

> **Tidak ada pemulihan untuk dokumen yang terhapus.** Tidak ada satu pun endpoint
> `restore` di seluruh basis kode. **Draf yang terhapus karena salah hanya bisa
> dikembalikan lewat akses basis data, bukan dari layar mana pun.**

### 8.5 Persetujuan: maker-checker, ambang direktur, dan empat pengabaian

Maker-checker dan ambang direktur dijelaskan di §3.8. Yang perlu ditambahkan di sini
adalah **apa yang ditinggalkan setiap pengabaian**, karena administrator akan diminta
mengauditnya:

| Pengabaian | Kapan muncul | Yang distempel |
|---|---|---|
| **Prakualifikasi vendor** | Vendor tidak aktif, atau dokumen wajibnya kedaluwarsa. Berjalan saat PO dibuat, PO diajukan, dan SPK dibuat | Kolom alasan pada PO — **dan hanya bila pengabaiannya benar-benar dipakai.** Alasan yang diketik untuk vendor SEHAT **dibuang**, karena itu akan menjadi "jejak audit yang menuduh vendor sehat bermasalah". **Baca kolom kosong sebagai "tidak ada penghalang", bukan "alasan tidak tercatat"** |
| **Deviasi harga** | Harga satuan baris PO melampaui harga BOQ yang dibekukan lebih dari ambangnya (bawaan 10%), hanya ke ATAS | **Tidak ada apa pun pada dokumen.** Jejaknya hanyalah baris `submitted` atas nama orang yang mengonfirmasi |
| **Gerbang anggaran** | PO/SPK melampaui RAP yang disetujui | **Tidak ada apa pun pada dokumen** — jejaknya sama seperti di atas |
| **Harga nol pada GRN** | Baris GRN berharga 0 yang **terkait ke baris PO** | **Tidak ada apa pun** — ia bendera permintaan, bukan kolom |

> **Bila panduan menjanjikan sebuah kolom untuk diperiksa sesudahnya pada dua yang
> tengah, panduan itu salah.** Deviasi harga dan gerbang anggaran hanya meninggalkan
> nama pengonfirmasi di riwayat persetujuan.

Perilaku gerbang anggaran adalah **kebijakan konfigurasi**: `warn` (bawaan — 422 sampai
dikonfirmasi), `block` (tidak ada jalur konfirmasi sama sekali), atau `off`. **Nilai
yang tidak dikenali diperlakukan sebagai `warn`** — salah ketik pada kebijakan tidak
bisa mematikan gerbangnya, tetapi juga tidak akan mengeluh.

Tiga gerbang berbentuk pengabaian lain yang akan Anda temui:

- **Milestone belum tercapai**: menagih termin yang seluruh milestone-nya belum tercapai
  ditolak sampai dikonfirmasi — dan **yang ini MENSTEMPEL dokumennya**: catatannya
  ditambahkan ke deskripsi invoice, "where everyone who reads the document reads it".
- **Pelepasan retensi sebelum masa pemeliharaan berakhir**: SPK yang **tidak punya
  tanggal masa pemeliharaan sama sekali** juga digerbangi, karena "unknown is never
  satisfied". Alasannya disimpan pada baris pelepasan retensi.
- **BAST II dan penutupan proyek**: pengabaiannya **hanya mengangkat PERINGATAN, tidak
  pernah BLOK** — "a gate whose blocks can be talked past with one free-text field is a
  warning system wearing a gate's clothes". Alasan minimal **20 karakter** (bandingkan:
  10 karakter untuk tutup buku), disimpan bersama potret daftar periksanya **dan**
  ditambahkan ke catatan persetujuan sehingga riwayatnya membawa alasan itu di samping
  klik yang dimaafkannya.

> **Memberi alasan terhadap sebuah BLOK tidak mengubah apa pun.** Mengatakan kepada
> pembaca "tulis alasan dan ia akan lolos" salah untuk blok.

### 8.6 Tiga-arah match: apa yang ditolaknya

Pencocokan ditanyakan saat **persetujuan tagihan AP**, dan seluruh rancangannya bertumpu
pada satu kalimat: *"WHICH TREATMENT APPLIES IS NOT RE-DERIVED HERE. It is read off the
receipts."* Karena itu, membalik saklar perpetual tidak bisa menelantarkan kredit yang
diterbitkan mesin maupun mendebet kredit yang tidak pernah diterbitkannya.

| Penolakan | Isinya |
|---|---|
| **Komitmen stok belum tuntas** | Tagihan PO tidak boleh disetujui selama masih ada baris yang kuantitas diterimanya kurang dari yang dipesan. Dua jalan keluar ditawarkan: terima sisanya, atau tutup PO-nya |
| **Stok yang dipesan tidak pernah diterima** | **Menutup PO TIDAK meloloskan yang ini.** Pesanan yang mengharapkan barang masuk gudang dan tidak punya satu pun penerimaan terposting **tidak boleh ditagih lewat jalur klasik (pembebanan)**. Konsekuensi terukurnya: PO senilai Rp 115.600.000 CCTV ditutup karena terlambat, ditagih klasik, realisasi material proyek melonjak dari 0 ke 115.600.000 untuk barang yang masih di truk vendor — lalu barangnya datang di luar PO, diterima dan dibonkan, dan **proyeknya dibebani untuk kedua kalinya** |
| **Uang muka masih menunggu** | Tagihan final tidak boleh disetujui selama uang muka untuk PO yang sama belum diputuskan |
| **Uang muka sudah tidak terbuka** | Uang muka tidak boleh disetujui begitu tagihan final untuk PO itu ada dalam keadaan hidup apa pun |
| **PPN pada vendor non-PKP** | Tagihan tidak boleh membebankan PPN bila vendornya bukan PKP |
| **PPh tidak teridentifikasi** | Tagihan yang memotong PPh harus menyebut **PPh yang mana**. Biaya dari versi lama yang mengembalikan `2-1220` begitu saja: **Rp 25.837.500 PPh final 4(2) dikreditkan ke Hutang PPh 23**, SSP tanggal 10 salah di kedua arah, dan tagihannya tidak bisa diperbaiki karena tagihan yang disetujui tidak bisa disunting |
| **Penagihan ganda** | Satu tagihan final per PO; satu tagihan per GRN; satu tagihan per opname. Penagihan utuh dan penagihan parsial **saling meniadakan per PO** |

Gerbang cerminnya ada di sisi Persediaan dan menolak **pengirimannya**, bukan
tagihannya: barang hanya boleh diterima terhadap PO yang **disetujui** — bukan yang
tertutup, draf, diajukan, atau terhapus — dan bukan terhadap PO yang satu tagihan
finalnya sudah membebankan barangnya secara klasik. Kedua penolakan menyebut jalan
keluar yang sama: **catat pengirimannya atas nama vendor tanpa nomor PO**, yang
menerbitkan akrual 2-1600 yang bisa dilunasi tagihan manual.

Ada juga **plafon kelebihan penerimaan**, diterapkan **kumulatif**, termasuk pada baris
yang tidak menyebut baris PO. Kasus tanpa plafonnya nyata: 1000 zak diterima terhadap
pesanan 100 zak tanpa penolakan, kuantitas diterima tetap 0, dan tagihan PO lalu menyapu
selisihnya ke 6-4500 sebagai "laba" pembelian Rp 13.500.000.

### 8.7 Menyunting setelah posting

**Aturannya**: dokumen bisa disunting hanya selagi draf atau ditolak. Setelah diajukan,
formulir operatornya tertutup; setelah disetujui/terposting, angkanya beku dan
satu-satunya jalur adalah pembatalan (bila ada) atau dokumen yang berlawanan.

**Di mana pemeriksaannya berjalan itu penting**, dan panduan ini tidak akan menjanjikan
sebaliknya: setiap layanan yang membawa uang **membaca ulang barisnya di DALAM
transaksi** dan memeriksa ulang statusnya di sana, bukan pada objek yang diambil rute.
Balapan konkretnya terdokumentasi: objek yang diambil rute dibaca tiga round-trip
sebelum penangannya bekerja, dan di jendela itu satu klik Posting yang bersamaan sempat
membuat kode lama **menghapus dan menyisipkan ulang baris jurnal yang SUDAH TERPOSTING,
menulis ulang Rp 5.000.000 menjadi Rp 500.000.000 dan memberinya tanggal di dalam bulan
yang sudah tertutup.**

> **Setiap penguncian baris di basis kode ini adalah no-op, karena basis datanya SQLite.
> Yang benar-benar melindungi di mana-mana adalah pembacaan ulang di dalam transaksi.**
> Ini penting secara operasional: dua orang yang menggarap dokumen yang sama di dua tab
> akan mendapat **penolakan pada klik kedua**, bukan penggabungan diam-diam — yang benar,
> tetapi terbaca seperti bug oleh yang tidak tahu.

**Pengecualian — kolom yang MASIH bisa diubah setelah persetujuan**, masing-masing lewat
pintunya sendiri yang sempit:

| Yang bisa diubah | Di mana | Batasnya |
|---|---|---|
| **Nomor faktur pajak** pada invoice AR yang sudah disetujui | Tindakan tersendiri di layar invoice | Satu nomor seri hanya boleh dipegang satu invoice; mendaftarkan ulang nomor yang sama pada invoice yang **sama** tetap boleh (memperbaiki salah ketik bukan duplikat); invoice yang **dibatalkan tetap memegang serinya**, karena nota pembatalan mengutipnya |
| **Tanggal akhir masa pemeliharaan** pada SPK yang sudah diajukan/disetujui | Tindakan tersendiri | Ditolak begitu retensinya dilepas. Ada karena tanggalnya biasanya baru diketahui setelah persetujuan, saat formulir Ubah sudah tertutup |
| **Catatan pada baris alokasi pembayaran** | Layar pembayaran | Ia berada **di luar** tanda tangan yang dibandingkan server, sehingga nomor NTPN atau bukti yang dikoreksi tetap bisa diposting tanpa membatalkan persetujuannya |

Dan dua hal yang **ditolak bahkan pada draf**: DPP tagihan AP **parsial** diturunkan dari
penerimaan yang ditagihnya dan tidak bisa diketik menimpanya ("batalkan tagihannya dan
terbitkan ulang"); dan suntingan yang tidak menyebut PPh sama sekali tidak boleh
menurunkan ulang jumlah PPh yang sudah diputuskan operator sebelumnya.

### 8.8 Yang tidak ada pada siklus dokumen

- **Tidak ada batal-setuju / batal-posting**, dengan SATU pengecualian: penawaran yang
  belum dimenangkan. `QuotationService::revise` menolak hanya bila `isWon()`; selain itu
  ia mengembalikan status ke draf, menaikkan `revision`, dan mengosongkan `lost_at` /
  `lost_reason`. Penawaran yang sudah menang direvisi lewat kontraknya, bukan di sini.
- **Tidak ada pemulihan dokumen terhapus.** Tidak ada satu pun **endpoint** `restore`,
  tombol, atau perintah artisan yang mengembalikan baris yang di-soft-delete —
  operasionalnya nol. (Dua **migrasi data** memang memanggil `restore()`, dan keduanya
  bukan jalan bagi operator: `Modules/Finance/Database/Migrations/2026_07_25_001195…:69`
  dan `…001196…:135` meng-un-delete baris **bagan akun** yang terlanjur terhapus, karena
  indeks unik pada `code` menahan kodenya sementara mesin memposting lewat scope
  non-trashed — "a trashed 2-1150 leaves the engine just as broken as a missing one".)
- **Tidak ada batal atau balik untuk jurnal yang sudah diposting.**
- **Tidak ada pembatalan transfer gudang.** Transfer draf bisa disunting atau dihapus;
  yang dalam perjalanan atau sudah diterima tidak bisa dibatalkan oleh endpoint mana pun.
- **Tidak ada batal atau balik untuk opname terposting.**
- **Tidak ada batal atau balik untuk run payroll terposting** — pesannya menyebut
  "jurnal pembalik" sebagai obatnya, **tetapi tindakan itu tidak ada di modul dan harus
  diketik tangan sebagai JV.**
- **Tidak ada batal atau balik untuk run penyusutan, pelepasan aset, atau demobilisasi.**
  Modul Aset tidak punya satu pun metode `cancel()`, `reverse()`, atau `unpost()`.
- **Tidak ada pembatalan pelepasan retensi pelanggan.**
- **Tidak ada pengiriman notifikasi WhatsApp.**
- **Tindakan siklus pembayaran (Ajukan/Setujui/Tolak/Posting/Balikkan) tidak ada di
  definisi layar generik** — ia layar detail khusus. Begitu pula kas kecil, kasbon,
  periode fiskal, retensi, dan rekonsiliasi bank. Ini disebut di sini supaya siapa pun
  yang mendokumentasikan layar pembayaran dari definisi generik tidak menyimpulkan
  tombolnya tidak ada.

---

## 9. Mencetak formulir rumah

### 9.1 Apa yang ada

**40 formulir rumah**: 7 formulir khusus proyek (Data Proyek, Laporan Harian, Detail
Schedule/Program Kerja, Daftar Temuan, Izin Kerja, Izin Lembur, Izin Material) ditambah
33 dokumen di registri. Semuanya dilayani **satu rute**, dan tidak ada izin di tingkat
rute — izinnya diturunkan per permintaan dari registri.

**Aturan rumahnya, dan ia berlaku sebagai instruksi operasional untuk Anda:**

> **Sebuah formulir membawa izin `.view` modul yang memiliki datanya, karena mencetak
> adalah membaca dalam bentuk lain. Tidak ada izin `print` di mana pun dalam himpunan
> izin.**

| Modul pemilik | Jumlah formulir |
|---|---|
| `inv.view` | 7 (penerimaan, bon material, surat jalan transfer, berita acara opname, saldo stok, retur pembelian, retur material) |
| `crm.view` | 4 (penawaran, kontrak ringkas, berita acara CCO, register jaminan) |
| `prc.view` | 4 (permintaan pembelian, order pembelian, banding penawaran, evaluasi vendor) |
| `fin.view` | 5 (tagihan vendor, bukti pembayaran, voucher jurnal, kewajiban pajak, ekualisasi pajak) |
| `est.view` | 3 (RAB, AHSP, RAP) |
| `scm.view` | 3 (SPK subkon, addendum SPK, opname subkon) |
| `hr.view` | 3 (rekap payroll, pengajuan cuti, daftar hadir) |
| `svc.view` | 2 (berita acara servis, kontrak layanan) |
| `ast.view` | 2 (kartu aset, berita acara mobilisasi) |
| `prj.view` | 7 formulir khusus |

**Penolakan yang akan dilihat pemakai**: 403 *"Anda tidak memiliki izin untuk mencetak
formulir ini."*; slug tak dikenal 404 *"Jenis formulir cetak tidak dikenal: {form}."* —
dan pemeriksaan slug berjalan **sebelum** datanya dimuat, sengaja, supaya endpoint-nya
tidak bisa menjawab "apakah proyek 812 ada?" bagi pemanggil yang tidak berhak.

**Respons tidak pernah di-cache**, karena "proyek di baliknya bisa berubah, dan laporan
harian yang basi lebih buruk daripada yang lambat". Cetak ulang selalu membaca ulang
basis data.

### 9.2 Empat jenis layar yang membawa tombol

Katalognya sudah **disaring izin di sisi server**, jadi tombol yang tidak berhak
benar-benar **tidak ada**, bukan 403-saat-diklik.

1. **Layar detail generik** — otomatis.
2. **Layar detail khusus** — masing-masing butuh satu baris pemanggilan (rekap gaji, SPK
   subkontraktor, bukti pembayaran, kartu aset, tabulasi banding penawaran).
3. **Daftar tanpa layar detail** — tombolnya di baris. Kasus yang melahirkannya ditulis
   apa adanya: progres mingguan tidak punya layar detail sementara tombol "Detail
   Schedule"-nya dideklarasikan — "the form works, the endpoint works, and not one screen
   carries its button".
4. **Layar berbasis rute** — tombolnya sendiri, berjangkar pada satu baris yang ada di
   layar (daftar hadir, kewajiban pajak, ekualisasi pajak).

**DUA formulir membawa parameter dari layar** — laporan harian dengan `?tanggal=`
(`public/app/js/schema.js:702`) dan progres mingguan dengan `?minggu=` (`:913`).
Keduanya dideklarasikan di definisi layar, bukan di registri, karena katalog tidak bisa
mengetahui sebuah parameter dari satu baris saja.

> **Daftar Temuan tidak termasuk, walaupun endpointnya menerima `?status=`.**
> Deklarasi tombolnya (`schema.js:1065`) hanya membawa `idField: 'project_id'` dan
> **tidak punya `params`**, jadi `printablePath()` tidak pernah mengeluarkan `?status=`:
> tombol di layar selalu mencetak **seluruh** register. Punch list tersaring hanya bisa
> dicapai dengan mengetik URL-nya sendiri —
> `/api/core/print/forms/daftar-temuan/{id-proyek}?status=open` — dengan nilai yang
> diterima `open`, `in_progress`, `ready_for_review`, `closed`, `waived`; nilai lain
> ditolak dengan *"Status temuan tidak dikenal: {nilai}."* Rekapitulasi di lembar itu
> **tetap menghitung seluruh temuan proyek**, sengaja, dan lembarnya mencetak satu
> kalimat yang mengatakan begitu.

Ketiga formulir di atas — laporan harian, progres mingguan, daftar temuan — adalah yang
dideklarasikan di `schema.js`. **Empat formulir proyek sisanya** digambar layar proyek
sendiri: "Cetak Data Proyek" ada di baris tindakan; ketiga formulir izin ada di
**kartunya sendiri** — dan alasannya
tertulis: Data Proyek mencetak isi basis data sementara ketiga izin mencetak **kertas
bergaris kosong**, dan kalimat yang menjelaskan itu harus ada di layar juga, "otherwise
somebody presses the button, sees a blank sheet, and concludes the button is broken".

**Bagaimana lembarnya sampai ke printer**: SPA tidak bisa memakai tautan biasa, karena
token sesinya menumpang di header khusus dan sebuah tautan tidak mengirim header. Jadi
ia membuka tab **secara sinkron pada klik**, menulis penampung "Menyiapkan formulir…",
mengambil lembarnya, lalu menavigasi tab itu. Bila browser memblokir popup, pemakai
mendapat: **"Popup diblokir browser. Izinkan popup untuk situs ini, lalu cetak lagi."**

### 9.3 Setelan cetak browser — dan lembarnya sudah mengatakannya

Setiap lembar membawa spanduk yang hanya tampil di layar (tidak ikut tercetak):

> Tekan **Ctrl+P** lalu pilih **Simpan sebagai PDF**. Kertas **A4**, orientasi
> **Potret/Lanskap**, skala 100%, dan aktifkan **Grafik latar belakang** agar arsiran
> kepala tabel ikut tercetak.

Alasan spanduk itu harus ada: "The tab is opened and print() is called for the user, but
the two settings that decide whether the sheet matches the pad — orientation and
background graphics — live in the browser's dialog and nowhere else."

**Tanpa "Grafik latar belakang", Chrome mencetak setiap kepala tabel yang diarsir menjadi
putih, dan pengelompokan yang menjadi alasan kepala itu ada ikut hilang.** Ini penyebab
paling sering dari "formulirnya kok beda dengan pad kami".

**Sebelas dari 40 formulir berorientasi lanskap**: Detail Schedule, Daftar Temuan,
register jaminan, banding penawaran, berita acara opname, saldo stok, opname subkon,
kewajiban pajak, ekualisasi pajak, rekap payroll, dan daftar hadir. Sisanya potret.

Lembarnya sengaja berdiri sendiri: CSS inline, tanpa stylesheet eksternal, tanpa font
web, logo disisipkan sebagai data — "a font fetched over the network would make the same
form print differently on a laptop with no signal".

### 9.4 Yang harus Anda rawat supaya formulirnya benar

Empat kotak di pita kepala, dan dari mana isinya:

| Kotak | Diisi dari | Layar |
|---|---|---|
| **PEMILIK** | Nama pelanggan | Penjualan → Pelanggan |
| Sebutan konsultan (huruf besar), bawaan **KONSULTAN MK** | `consultant_name` proyek | Proyek → form → bagian **Tim** |
| **PROYEK** | Nama + kode proyek | Proyek |
| **KONTRAKTOR** | **`legal_name`** perusahaan, plus logonya | Sistem → Profil Perusahaan |

**Dua kolom yang paling sering terlewat, dan keduanya baru:**

- **`consultant_name` / `consultant_role`** (Proyek → form → bagian "Tim", label
  "Konsultan MK / pengawas" dan "Sebutan konsultan"). Tiga dari empat kotak sudah bisa
  dijawab ERP; yang kedua tidak — *"there was no column anywhere in the system for the
  management-consultant firm that supervises the job and signs 'Menyetujui / menolak' on
  every laporan harian… a supervising firm's name is not something a template may
  invent."* Kolomnya **tidak di-backfill dengan sengaja**: pekerjaan tanpa MK itu biasa,
  dan jawaban formulir kertas untuk "tidak ada MK" adalah **kotak kosong**.
  `consultant_role` adalah **judul kotaknya**, bukan hiasan — "Konsultan MK" pada
  bangunan, "Konsultan Pengawas" pada proyek pemerintah, "Konsultan Perencana" bila
  perancangnya yang mengawasi.
- **`contract_number_customer`** (Kontrak → form → "No. kontrak pelanggan"). Ini **nomor
  yang dikenal PELANGGAN**, yang justru dicek MK terhadap formulirnya. Kode kontrak kita
  sendiri hanya cadangan, tidak pernah pilihan pertama.

**Blok identitas** sepuluh baris: no. SPK/kontrak, tanggal SPK, waktu pelaksanaan,
perpanjangan waktu I dan II, periode, tanggal, minggu ke, hari ke, sisa hari.

**Penghitung harinya inklusif kedua ujung** — hari pertama pekerjaan adalah HARI KE 1,
dan 1 Januari sampai 31 Desember adalah 365 hari. Minggu 1 adalah hari 1–7. Ketiganya
**kosong bila proyeknya belum punya tanggal**, yang biasa saja — sebuah proyek ada
sebelum SPK-nya ditandatangani, "and a zero there would be a claim rather than a blank".
Lembar bertanggal **sebelum** proyek dimulai mengosongkan HARI KE alih-alih mencetak
"-12" ("which reads as a system that cannot count"), dan pekerjaan yang **lewat waktu**
mencetak `0 hari (lewat N hari)` — karena "'0 hari' would hide the overrun from the
people signing the form".

**Blok tanda tangan**: tiga kolom. Kolom satu dan dua **tidak membawa nama, dengan
sengaja** — *"Nothing in this ERP records who signs for the owner or for the MK —
printing a name there would be forging a signature line."* Kolom ketiga milik kita dan
mengambil site manager, lalu project manager. Untuk dokumen tanpa proyek, ketiga
kolomnya tanpa nama, dengan alasan yang sama: riwayat persetujuan tahu siapa yang
menekan Setujui **di aplikasi ini**; itu bukan klaim yang sama dengan "orang ini
menandatangani dokumennya", dan mencetak yang satu di bawah yang lain berarti memalsukan
baris tanda tangan.

### 9.5 Aturan kejujuran, sebagai instruksi operasional

Aturannya, apa adanya dari kode:

> **Sebuah sel dicetak DARI BASIS DATA atau dicetak sebagai GARIS KOSONG. Tidak ada opsi
> ketiga, dan khususnya tidak ada nilai bawaan yang tampak masuk akal.** Ia tidak boleh
> mengembalikan 0 untuk "tidak diketahui", tidak boleh nilai bawaan yang plausibel,
> tidak boleh string "null". Nol yang tersimpan adalah fakta dan dicetak sebagai 0,00;
> nilai yang tidak ada bukan nol. **Formulir ini ditandatangani tiga pihak dan
> diarsipkan sebagai catatan proyek — angka yang dikarang di sini adalah angka yang
> ditandatangani seseorang.**

Aturan itu **mekanis, bukan kebiasaan**: setiap sel melewati satu fungsi yang
mengembalikan kosong untuk nilai kosong, dan templatnya hanya punya dua cabang — teksnya,
atau garis kosong.

**Instruksi operasional Anda:**

> **Garis kosong pada formulir tercetak berarti ERP tidak memegang fakta itu. Ia TIDAK
> berarti pencetakannya gagal.**
>
> Cara membedakannya dari kerusakan sungguhan: **apakah sel yang SAMA kosong pada setiap
> dokumen.** Kosong per-dokumen berarti kolomnya kosong di basis data — itu pekerjaan
> pengisian data. Kosong universal berarti ERP tidak punya kolom semacam itu — itu bukan
> kerusakan dan tidak akan pernah terisi.

Kasus yang benar-benar akan ditanyakan kepada Anda, masing-masing dengan alasannya:

| Yang dilihat pemakai | Kenyataannya |
|---|---|
| **Ketiga formulir izin tercetak nyaris seluruhnya kosong** | **Itu produk jadinya.** Tidak ada apa pun di basis data ini yang mencatat izin kerja, izin lembur, atau izin material — "not a partial table, none". Setiap lembar membawa kalimat tercetak yang mengatakannya: *"Formulir ini dicetak kosong: Nusantara ERP belum menyimpan data …, sehingga lembar kertas yang sudah diisi dan ditandatangani inilah satu-satunya catatan. Arsipkan di berkas proyek."* Layar proyek mengulang kalimat yang sama sebelum tombolnya |
| **Ketiga formulir izin tidak bertanggal** | Sengaja, kecuali tanggal diminta. "A site office prints a pad of these once and works through it for a month; stamping every sheet with the day somebody pressed print would put 'HARI KE 52' on a permit filled in on day 71." Hanya baris yang bergerak yang dikosongkan; tanggal SPK — fakta kontrak yang tidak berpindah — tetap tercetak |
| **PERPANJANGAN WAKTU I dan II selalu kosong** | **Tidak ada apa pun di ERP yang mencatat perpanjangan waktu** — tabel CCO membawa perubahan nilai dan **tidak membawa hari sama sekali**. Kedua baris itu diisi tangan, persis seperti di kertas. **Tidak ada jumlah pengisian data yang akan mengisinya** |
| **Jam kerja pada laporan harian kosong** | Kolomnya ada sejak Laporan Harian penuh — jam mulai/selesai kerja dan alasan jam kerja hilang — dan barisnya tercetak dari laporan bila dicatat. Kosong berarti laporan itu tidak mencatatnya: pekerjaan pengisian data, bukan kerusakan. Hal yang sama berlaku untuk empat tabel baris FM-10-12 (per jabatan, material masuk, alat, uraian): laporan pra-pembaruan tidak punya barisnya dan tercetak bergaris kosong persis seperti dulu, dan catatan kaki lembar menyebut hanya tabel yang masih manual pada laporan itu |
| **"No. Rev." selalu kosong** | Tidak ada apa pun di ERP yang menerbitkan nomor revisi untuk formulir cetak, dan "0" akan **menegaskan** bahwa ada |
| **Tabel berpad terisi garis kosong** | Bukan baris nol. Sengaja |
| **Daftar Temuan yang difilter `?status=` tetap merekap SELURUH register** | Dan lembarnya mencetak kalimat yang mengatakannya. Alasannya: "Printing a filtered recap next to filtered rows would let a page of two open items read as a job with two findings." Nilai status yang tidak dikenali **ditolak menyebut nama**, bukan disaring menjadi lembar kosong — tebakan Indonesia yang masuk akal seperti `?status=selesai` terhadap nilai yang dieja `closed` akan mencetak punch list bersih untuk pekerjaan yang punya temuan |

**Lawan-contoh yang membuktikan aturan ini bukan kemalasan**: Daftar Temuan adalah
formulir yang dijawab ERP **sepenuhnya** — tabel defect membawa setiap kolom yang ada di
register kertas pemiliknya, "so not one cell of the body is invented and not one is
hand-filled either".

### 9.6 Empat dokumen PDF yang terpisah

Empat rute terpisah — invoice AR (`fin.view`), PO (`prc.view`), BAST (`prj.view`), slip
gaji (`hr.view`) — dan **ini punya izin di tingkat rute**, tidak seperti formulir rumah.
Keempatnya PDF sungguhan, A4 potret, dan **mengunduh** alih-alih membuka tab.

Mengapa kedua tumpukan ada, dinyatakan agar tidak digabung: mesin PDF-nya benar untuk
sebuah invoice dan **mustahil** untuk formulir rumah — jadwal mingguan adalah kisi
lanskap dengan kepala kolom bertumpuk dua baris yang tidak bisa ditata mesin itu, dan
kotak halamannya potret saja. Browser menata kisinya, mengulang kepala bertumpuk di
halaman 2, dan menghormati kotak lanskap; mesin PDF tidak melakukan ketiganya.

Dua fakta yang berguna: **PDF invoice dan PO menstempel spanduk DIBATALKAN** bila
dokumennya dibatalkan — tanpa itu "the PDF of a cancelled invoice is byte-for-byte the
argument for paying it… The customer pays, and the receipt cannot be recorded at all."
Dan **slip gaji tidak punya entri layar sendiri**: ia satu ikon unduh **per baris
karyawan** di dalam layar run payroll, karena "the run is what gets posted, the slip is
what gets given".

### 9.7 Yang tidak ada pada pencetakan

- **Tidak ada cara menyetel logo kop dari aplikasi.** Lihat §4.2.
- **Tidak ada yang mencatat siapa mencetak dokumen apa, kapan.** Log audit ditulis oleh
  pengamat model untuk dibuat/diubah/dihapus; sebuah pencetakan adalah pembacaan. **Bila
  Anda butuh register cetak untuk keperluan audit, ia tidak ada.**
- **Tidak ada cetak massal.** Setiap rute satu dokumen, satu baris. Slip gaji pun
  sengaja per baris, bukan ekspor massal.
- **Tidak ada nomor revisi.** §9.5.
- **Penawaran tidak mencatat syarat penjualan** — tidak ada kolom, tidak ada tabel — jadi
  blok SYARAT & KETENTUAN pada surat penawaran adalah empat garis kosong secara
  rancangan. Termin pembayaran pada master pelanggan **sengaja tidak dipakai**: "that is
  the term we have with that customer, not a term THIS offer makes".
- **Tidak ada kolom tanggal penawaran**; suratnya hanya membawa baris tempat-dan-tanggal.

---

## 10. Cadangan & pemulihan

[`DEPLOYMENT.md` §5.1](DEPLOYMENT.md) memiliki bab ini. Yang diulang di sini hanyalah
bagian yang harus **Anda** kerjakan atau tafsirkan.

### 10.1 Apa yang dicadangkan, dan bagaimana ia dibuktikan baik

Server erp1 adalah **SQLite di atas mesin telanjang** — skrip cadangan Docker/MySQL di
repositori **tidak bisa berjalan di sana**, dan skrip yang benar mengatakannya di
paragraf pertamanya sendiri.

Yang dicadangkan, keduanya disebut "tak tergantikan": **basis datanya** (setiap dokumen,
jurnal, dan pengguna) dan **lampiran yang diunggah** (pindaian faktur pajak, foto
lapangan, invoice vendor).

Empat gerbang independen membuktikan sebuah cadangan baik — Anda tidak perlu
menjalankannya, tetapi harus tahu ia ada saat menjelaskan mengapa cadangan "gagal":

1. Snapshot diambil dengan mekanisme SQLite yang aman, **bukan `cp`** — "Copying a SQLite
   file while anything is writing to it yields a torn file that looks fine until you need
   it."
2. Pemeriksaan integritas pada snapshot, dan hasilnya harus `ok`.
3. **Hitungan pengguna**: bila kurang dari satu, snapshot itu **ditolak untuk disimpan**
   — "A structurally valid but empty file is not a backup either."
4. Setiap artefak dibangun dengan nama sementara, diuji, **baru** diganti namanya — "a
   crash mid-write must never leave a truncated file wearing a finished name."

Setiap artefak dienkripsi dan didorong ke tujuan luar; dorongannya berupa **sinkronisasi**
— apa pun yang belum ada di tujuan naik — "so a failed night heals itself the next run".
Retensi jauh harus **≥** retensi lokal, dan skripnya **menolak berjalan** bila tidak:
"Offsite is the copy of last resort; retiring it before the local copy would invert that."

Ada juga **lantai anti-jam-ngaco**: baik rotasi lokal maupun pemangkasan jauh selalu
menyisakan tiga salinan terbaru tiap jenis, apa pun kata tanggalnya — "Dates come from
clocks and clocks go wrong; a forward-skewed clock must never be able to age every copy
in existence past the cutoff."

### 10.2 Satu-satunya langkah yang harus pemilik kerjakan

**Menunjuk tujuannya.** Sampai itu dilakukan, setiap run menulis status "belum
dikonfigurasi", mencetak penjelasan berbaris banyak, keluar dengan kode 3, dan ERP
menaikkan alarm setiap pagi.

> **Alarm itu bukan bug yang harus dibungkam; keadaan "belum dikonfigurasi" ITULAH
> alarmnya.** Kata skripnya sendiri: "a silent local-only backup is exactly the failure
> mode the offsite copy exists to end." Dan mengarahkan tujuannya ke apa pun **di mesin
> yang sama** meniadakan seluruh gunanya — "everything local sits on the same disk as the
> thing it protects".

Konfigurasinya di `/etc/erp1/backup.conf` (templat ada di repositori). **Formatnya
ketat** — `KUNCI=nilai` polos, **tanpa spasi di sekitar `=`, tanpa tanda kutip, tanpa
akhir baris CRLF** — dan skripnya memvalidasi berkas itu **sebelum** membacanya, justru
supaya salah ketik tidak bisa menggagalkan run **sebelum cadangan lokalnya**, "turning a
config mistake in the OFFSITE half into zero database snapshots".

> Administrator yang menyunting berkas itu di Windows lalu menyalinnya akan melihat
> "refusing to use /etc/erp1/backup.conf" — dan **tidak punya salinan luar malam itu.**
> Cadangan lokalnya tetap jalan.

**Membuktikan jalurnya ujung ke ujung**, dua perintah — **sebagai root**, dan **lewat
`bash`**:

```bash
bash /var/www/erp1.pi2.co.id/deploy/backup-erp1.sh --offsite-only
```

```bash
bash /var/www/erp1.pi2.co.id/deploy/backup-erp1.sh --restore-drill
```

Yang pertama diharapkan menjawab "offsite ok", yang kedua "RESTORE DRILL PASSED".

> **Bentuk yang dicetak [`DEPLOYMENT.md`](DEPLOYMENT.md) §5.1 — memanggil skripnya
> langsung sebagai perintah — GAGAL di erp1.** Di situs hidup berkas itu
> `-rw-r----- root:www-data`, **tanpa bit eksekusi**, jadi bahkan root mendapat
> `Permission denied`. Bukan kelalaian: `deploy/sync-erp1.sh` memasang mode `640` pada
> setiap berkas yang disalinnya, dan `/etc/cron.d/erp1` karena itu memanggil ketiga
> jadwal cadangan lewat `bash` dengan alasannya tertulis di kepala berkas —
> *"Scripts are invoked through `bash` on purpose: a deploy that drops the exec bit must
> not be able to turn the nightly backup into exit 126."* Perintah di atas adalah bentuk
> yang benar-benar dipakai cron; ia perlu root karena menulis ke `/var/backups/erp1`,
> `/var/lib/erp1`, dan membaca `/etc/erp1/backup.key`.

**Uji pemulihan adalah satu-satunya pemeriksaan yang tidak bisa dipalsukan tujuan yang
berbohong atau membusuk**, karena ia membaca byte sungguhan: mengambil salinan terbaru,
mendekripsinya, membukanya, memeriksa integritasnya, lalu menghitung pengguna dan baris
jurnal. **Ia memulihkan ke direktori sementara dan tidak menyentuh apa pun yang hidup.**

### 10.3 Kunci enkripsi — jebakan yang paling mahal

Bila kunci belum ada, skripnya mencetak resep pembuatannya dan berhenti, diikuti kalimat
yang harus Anda perlakukan sebagai instruksi:

> **"then COPY IT SOMEWHERE OFF THIS MACHINE (password manager, printed page in a
> drawer). The offsite copies are encrypted with it; if this disk dies and the key lived
> only here, the backups are unreadable noise."**

**Kehilangan kunci itu membuat setiap salinan luar menjadi derau yang tidak terbaca, dan
hari Anda menemukannya adalah hari disknya mati.** Berkas status membawa **sidik jari
kunci** justru supaya salinan yang disimpan pemilik di luar server bisa dicocokkan dengan
yang benar-benar dipakai — **sebelum** hari perbedaan itu penting.

### 10.4 Membaca status cadangan tanpa masuk aplikasi

Berkas statusnya `/var/lib/erp1/offsite-status.json`.

> **"offsite ok" yang hijau BUKAN bukti bahwa cadangan masih dibuat.** Sinkronisasi yang
> tidak punya apa pun untuk didorong terus "berhasil" setelah cadangan lokalnya mati.
> Karena itu bacalah dua kolom yang diperiksa terpisah dari sukses terakhir: **arsip
> terbaru** dan **jumlah salinan di tujuan** — bukan hanya hasil terakhir.

### 10.5 Pemulihan — belum terdokumentasi

> **Tidak ada prosedur pemulihan tertulis untuk pemasangan SQLite di mesin telanjang
> ini.** [`DEPLOYMENT.md` §5.2](DEPLOYMENT.md) hanya berlaku untuk MySQL/Docker —
> perintah-perintahnya tidak satu pun berlaku di sini. Satu-satunya kode pemulihan yang
> ada di mana pun adalah mode **uji pemulihan**, yang sengaja **tidak menyentuh apa pun
> yang hidup**.
>
> Langkah-langkah untuk benar-benar mengembalikan salinan ke layanan — menghentikan
> php-fpm, mengganti berkas basis data, membongkar lampiran, dan mengembalikan
> kepemilikan `www-data:www-data` beserta modenya — **belum terdokumentasi**. Berkas yang
> harus dibuka pembaca untuk menyusunnya: `deploy/backup-erp1.sh` (blok `--restore-drill`,
> yang sudah melakukan ambil-dekripsi-buka) dan `deploy/sync-erp1.sh:58-67` (yang memuat
> fakta kepemilikan dan modenya). **Menyusun dan menguji prosedur ini adalah pekerjaan
> yang belum dilakukan siapa pun, dan lulus uji pemulihan bukan hal yang sama dengan
> pernah memulihkan.**

Satu hal lagi yang perlu dinyatakan: **jadwal cadangan tidak ada di repositori.**
Skripnya menunjuk ke `/etc/cron.d/erp1`, dan blok cron di `DEPLOYMENT.md` adalah blok
Docker/MySQL. **Bacalah `/etc/cron.d/erp1` di server** untuk mengetahui jam yang
sebenarnya berlaku, jangan mengutip angka dari repositori.

---

## 11. Pemecahan masalah

### 11.1 Tabel gejala → sebab → pemeriksaan

| Gejala | Sebab paling mungkin | Yang diperiksa |
|---|---|---|
| **"Belum ada periode fiskal untuk {tanggal}"** saat memposting | Kalender tahun itu belum dibuat | Keuangan → Periode Fiskal. Bila tahunnya kosong, tombol "Buat kalender {tahun}" — ingat §4.5: tahun lampau lahir **tertutup** |
| **"Periode fiskal YYYY-MM sudah ditutup"** | Persis itu | Dokumennya harus diberi tanggal ulang ke periode terbuka, atau periodenya dibuka kembali (`fin.approve`) — dan §6.7 mungkin melarangnya selamanya |
| **Dokumen ditolak dengan menyebut nama pengajunya** | Maker-checker: pengaju tidak boleh menyetujui | Pesannya menyebut izin yang harus dipegang penyetuju. Mintalah pemegang izin itu, atau — hanya bila perusahaan memang tidak punya petugas kedua — matikan setelannya di Pengaturan → Proyek & Persetujuan |
| **Periode tidak mau ditutup** | Satu atau lebih **blok keras** | Layar tutup buku sudah menyebut namanya. Tidak perlu menebak; §6.3 |
| **Bulan tidak bisa ditutup dan penyebabnya "dokumen menggantung" yang sudah diajukan** | Dokumen yang diajukan/disetujui tidak bisa disunting maupun dihapus | **Tolak dulu** (butuh `fin.approve`), baru sunting atau hapus — §6.4 |
| **Tombol "Buka" pada dokumen menggantung membuka daftar, bukan dokumennya** | Memang begitu, untuk semua sumber | Kode dokumennya tercetak di teks butir daftar periksa — cari baris itu di daftar yang terbuka. Draf bisa langsung disunting atau dihapus dari sana — §6.4 |
| **Ada dua menu kas kecil dan isinya berbeda** | Memang dua layar berbeda, keduanya bekerja | **Kasir Kas Kecil** (rute `kas-kecil`) melayani pemegang laci: posisi laci hari ini, entri bon, pencairan dan pertanggungjawaban kasbon. **Kas Kecil & Kasbon** (`r/finance/petty-cash-funds`) adalah register dananya — §6.4 |
| **Tombol cetak hilang di sebuah layar** | Empat sebab berurutan | (1) Pemakainya tidak memegang `.view` modul pemiliknya — katalog disaring di server, jadi tombolnya benar-benar tidak ada. (2) **Katalog cetak di-cache seumur sesi browser dan hanya disegarkan saat LOGIN** — izin yang diberikan selagi orang itu masuk **tidak** memunculkan tombol sampai ia keluar-masuk. (3) Pengambilan katalog gagal, dan **kegagalan itu senyap secara rancangan** — periksa tab jaringan browser. (4) Barisnya tidak membawa kolom id yang dibutuhkan tombol itu |
| **Tombol cetak diklik dan tidak terjadi apa-apa / tab berhenti di "Menyiapkan formulir…"** | Popup diblokir, atau pengambilan lembarnya gagal | Toast *"Popup diblokir browser. Izinkan popup untuk situs ini, lalu cetak lagi."* muncul bila itu sebabnya. Bila lembarnya tidak pernah selesai dirender, tabnya tetap di penampung tanpa dialog cetak |
| **Formulir tercetak berkepala tabel putih tanpa arsiran** | "Grafik latar belakang" mati di dialog cetak browser | Spanduk di lembarnya sendiri sudah menyebutkannya — §9.3 |
| **Formulir tercetak bergaris kosong di tempat pemakai mengharapkan data** | **Aturan kejujuran, bukan kegagalan** | Bandingkan beberapa dokumen: kosong per-dokumen = kolomnya kosong di basis data; kosong universal = ERP tidak punya kolom itu — §9.5 |
| **Login gagal** | Empat sebab yang bisa dibedakan dari kode statusnya | 401 *"Email atau password salah."*; **403 *"Akun Anda dinonaktifkan. Hubungi administrator."***; 429 = melewati 10 percobaan per menit; dan tab yang dibiarkan semalam habis masa tokennya (12 jam) sehingga SPA kembali ke layar masuk |
| **Pemakai bilang menunya berubah tetapi tombolnya masih yang lama** | Cache izin di sisi klien | Suruh muat ulang halaman atau keluar-masuk — §3.5 |
| **Pemakai memegang izinnya tetapi tombol simpan menjawab 403** | Layar yang dibukanya menuntut izin lain | Contoh yang paling sering: peran `hr` melihat menu Sistem karena `iam.view`, tetapi Pengaturan dan Profil Perusahaan menuntut `core.update` — §3.7 |
| **Kotak merah *`Halaman "<kunci>" tidak dikenal.`*** — mis. `Halaman "finance/payments" tidak dikenal.` — untuk layar yang baru saja di-deploy | Tab yang dibiarkan terbuka melewati deploy | SPA memakai hash router dan asetnya tanpa versi, jadi berpindah rute **tidak** memuat ulang modulnya. **Paksa pemuatan dokumen** (`/app/index.html?v=N#/rute`) sebelum menyimpulkan deploy-nya gagal |
| **Kotak kuning *`Halaman "<rute>" tidak ditemukan.`*** — pesan yang BERBEDA dari baris di atas | Sebab yang sama, cabang yang lain: rute itu tidak dikenal router sama sekali (`app.js:697`), bukan kunci RESOURCES yang hilang (`app.js:632`/`:653`) | Perlakuan sama — paksa pemuatan dokumen lebih dulu |
| **Situs menjawab `attempt to write a readonly database`** | Artisan pernah dijalankan sebagai root di pohon produksi, meninggalkan berkas sisi milik root | Hapus berkas sisi `-wal`/`-shm` milik root di direktori basis data, lalu jalankan artisan sebagai `www-data` — §1 |
| **Perintah artisan sukses tetapi tidak ada yang berubah di erp1.pi2.co.id** | Dijalankan dari pohon sumber, bukan situs hidup | §1 |
| **`php artisan tinker` gagal: "Writing to directory /var/www/.config/psysh is not allowed"** | HOME tidak bisa ditulis | Jalankan dengan `env HOME=/tmp` |
| **erp1.pi2.co.id menjawab 401 di browser** | **Itu keadaan SEHAT.** Gerbang Basic-auth nginx bukan login ERP | Skrip sinkronisasi memperlakukan 401 pada endpoint kesehatan sebagai jawaban yang **diharapkan** dan justru mencetak peringatan bila mendapat 200 |
| **Panggilan API lewat gerbang selalu 401 dan terbaca seperti login rusak** | Token ERP dikirim di header `Authorization` | Header itu **mengganti** kredensial gerbang. Pakai `X-Api-Token` untuk token, dan kredensial gerbang terpisah — §3.5 |
| **Alarm cadangan berbunyi lagi setiap pagi walau sudah ditandai dibaca** | Perilaku yang dirancang | Menandai dibaca **membuka** pintu kiriman berikutnya. Satu-satunya cara menghentikannya adalah memperbaiki penyebabnya — §5.10 |
| **Sebuah pengawas tenggat "tidak pernah berbunyi"** | Mungkin ia **BLIND** — semua tanggalnya NULL | Jalankan `erp:deadline-watch` tangan dan baca baris BLIND; keluaran terjadwal dibuang ke `/dev/null` — §5.2, §5.8 |
| **Stok tidak bisa dicatat mundur** | Penjagaan kronologi: dokumen bertanggal lebih awal daripada pergerakan terakhir untuk (gudang, item) ditolak | Penolakannya menyebut tanggal paling awal yang bisa diterima dan menawarkan opname sebagai alternatif |
| **Realisasi peralatan sebuah proyek nol padahal alatnya di lapangan berbulan-bulan** | Akrual bulanan belum dikejar | §12(d) |
| **Neraca saldo Januari masih membawa pendapatan tahun lalu** | **Benar, bukan kerusakan** | §7 |
| **Butir bank kuning "Jembatan cocok" padahal residualnya nol** | Masih ada item terbuka atau selisih saldo awal | §6.3 |

### 11.2 Dua salah paham yang layak disebut sendiri

**Ada dua tombol berbeda di layar detail yang sama-sama berarti "cetak".** Ikon printer
tanpa label berjudul **"Cetak halaman"** adalah cetak halaman web itu sendiri — ia
menghasilkan **daftar kunci/nilai** dari layar, bukan dokumen yang bisa ditandatangani.
Tombol berlabel **"Cetak {nama formulir}"** adalah formulir rumah. Pemakai yang
ditunjukkan tombol yang salah akan mendapat cetakan yang salah dan menyimpulkan
formulirnya rusak.

**Pencetakan tidak meninggalkan jejak.** Tidak ada yang mencatat siapa mencetak apa dan
kapan — §9.7.

---

## 12. Keputusan yang menunggu pemilik

**Tinggal satu yang benar-benar menunggu: (a) pemutaran kata sandi demo.** Ia bukan
cacat dan bukan pekerjaan yang tertinggal — keputusan yang sengaja dibiarkan terbuka
karena jawabannya milik pemilik, bukan milik kode. Tiga butir lain yang pernah
terbuka di halaman ini **diputuskan pemilik 22 Agustus 2026** dan dibiarkan di sini
sebagai catatan keputusan: (b) `teknisi` diberi `inv.post`, (c) register log BBM &
jam alat dibangun, (d) kejar-tayang akrual alat dijalankan. Keempatnya tercatat di
[`LAPORAN-DEVIASI.md`](LAPORAN-DEVIASI.md) dan
[`ASSESSMENT-LANJUTAN.md`](ASSESSMENT-LANJUTAN.md).

### (a) Memutar kata sandi demo, lalu menurunkan gerbang erp1

**Keadaan hari ini.** Sebelas akun, satu per peran, semuanya aktif — dan **kesebelasnya
masih memakai kata sandi `password`**, termasuk `admin@nusantara.test`, akun yang
memegang setiap izin yang didefinisikan aplikasi ini. Gerbang Basic-auth nginx **adalah
satu-satunya hal yang membuat itu layak dipublikasikan.** Ada 18 baris token API yang
beredar.

Pengerasannya **sudah ter-deploy**; yang tersisa adalah **ketukan tombol pemilik**.

**Keputusannya bukan "kapan memutar", melainkan "untuk apa demo ini setelah gerbangnya
turun"** — dan itulah langkah yang paling sering dilewati, yang bila dilewati
membatalkan sisanya. Demo yang tidak bisa dimasuki siapa pun bukan demo; jadi
menerbitkan situsnya berarti menerbitkan sebuah login — dan kata sandi yang sudah
diputar lalu muncul di halaman depan **mendarat persis di titik semula**: satu kredensial
bersama dengan akses tulis penuh.

Bentuk yang tahan lama adalah **pemisahan**: satu akun **hanya-baca** yang diterbitkan
bebas (memegang izin `.view` saja), dan setiap akun **berkemampuan tulis** diputar ke
nilai yang tidak pernah diterbitkan.

**Urutan enam langkahnya ada di [`DEPLOYMENT.md` §7.1](DEPLOYMENT.md), dan urutannya
penting.** Panduan ini tidak mengulangnya. Tiga hal yang harus dibaca bersamanya:

- **Perintah pengerasan mencabut setiap token API akun yang diputarnya — dan pencabutan
  itu tidak bisa dibatalkan — tetapi TIDAK memutus sesi**; siapa pun yang sudah masuk
  tetap di dalam (§5.9).
- **`--dry-run` yang bersih berarti "tidak ada yang masih memakai `password`", bukan
  "tidak ada kata sandi lemah"** (§5.9).
- **Ada satu pekerjaan tambahan yang tidak dilakukan perintah itu.** Layar masuk memasang
  blok statis **"Akun demo (kata sandi: password):"** lengkap dengan empat email yang
  mengisi formulir bila diklik. Blok itu **teks statis, tidak bersyarat lingkungan, dan
  TIDAK hilang setelah pemutaran.** Bila instalasi ini bukan demo, blok itu harus dihapus
  dari `public/app/js/app.js:132-140` — blok `el('.login-hint', […])` itu sendiri, BUKAN
  dari 118: baris 117-121 adalah pembantu `fill()` yang dipakai blok tersebut, dan
  menghapus 118-140 memotong badan fungsi itu di tengah sehingga layar masuk gagal
  dimuat sama sekali. Kalau blok itu dibiarkan, **layar masuk terus mengiklankan
  kata sandi yang sudah tidak berlaku dan nama-nama akun yang masih berlaku.**

**Yang dipertaruhkan bila gerbangnya diturunkan lebih dulu:** ERP yang bisa ditulis,
dengan akun yang bisa memposting jurnal, menutup periode, memindahkan stok, dan menghapus
dokumen, terbuka bagi siapa pun yang bisa membaca seeder-nya.

### (b) Apakah `teknisi` mendapat `inv.post` yang tidak dipegangnya

**DIPUTUSKAN 22 Agustus 2026: ya.** Dari empat pilihan yang pernah ditimbang di
halaman ini, pemilik mengambil jalan termurah — `teknisi` diberi satu izin,
`inv.post`.

**Duduk perkaranya** (T13): mengesahkan berita acara lapangan yang memakai suku
cadang menuntut `inv.post` di samping `svc.update`, karena pengesahan itu memposting
bon persediaan sungguhan dalam transaksi yang sama — dan `inv.post` hanya dipegang
admin, jadi setiap kunjungan bersuku-cadang menunggu akun admin. Pada volume tinggi
itu hambatan yang membuat orang berbagi kata sandi admin — persis kegagalan yang
seluruh pemisahan tugas di sistem ini ada untuk mencegahnya.

**Yang mengubahnya di kode.** `RoleSeeder` kini menyemai `teknisi` dengan lima izin,
sehingga instalasi baru langsung sama dengan erp1, dan migrasi
`2026_08_22_000242_give_the_teknisi_role_inv_post` melakukan operasi yang sama pada
peran yang sudah tersemai di tenant hidup — dicari berdasarkan nama harfiah
`'teknisi'`, no-op bila perannya tidak ada. Membatalkannya satu `revokePermissionTo`
pada peran itu; izinnya sendiri kanon dan admin tetap memegangnya.

**Harga yang diterima pemilik — kedua paruhnya dinyatakan di kode:**

- **Yang terbuka:** teknisi mengesahkan kunjungan yang ia lakukan sendiri; stok
  keluar atas namanya, bukan atas nama admin yang dipinjam.
- **Yang melebar:** `inv.post` bukan izin sempit — ia menggerbangi 8 rute persediaan
  (posting & pembatalan penerimaan barang, posting & pembatalan bon keluar, posting
  kedua retur, kirim/terima transfer). Teknisi kini bisa memposting atau membatalkan
  dokumen stok draf APA PUN yang terjangkau `inv.view`, bukan hanya bon di balik
  kunjungannya sendiri.

**Dua regangan yang DICATAT, bukan diputuskan:**

- Alur pengesahan kini bisa satu orang dari ujung ke ujung — menulis, mengajukan,
  lalu mengesahkan laporannya sendiri; satu-satunya tanda tangan balik adalah
  `customer_sign_name`, yang diketik teknisi itu juga. Jalur ini berada di luar
  kedua cincin SoD yang tercatat (berita acara lapangan tidak ikut maker-checker,
  dan bon yang lahir darinya di luar register maker-checker Inventory), jadi tidak
  ada kontrol yang dilemahkan — tetapi di bawah `inv.post`-hanya-admin, pengesah
  setidaknya selalu orang lain daripada penulisnya.
- `warehouse` tetap tanpa `inv.post` sementara teknisi memegangnya — peran yang ada
  untuk menggerakkan stok tidak bisa memposting bon yang bisa diposting teknisi
  lapangan (§3.3 perangkap 1).

Sepupu yang TIDAK ikut diputuskan dan masih menunggu akun admin: kedua rute subkon
yang menuntut `scm.post` + `fin.approve` sekaligus (§3.3 perangkap 2).

### (c) Bagaimana log BBM dan hour meter dicatat

**DIPUTUSKAN 22 Agustus 2026: register lapangan — dan registernya sudah dibangun.**
Deviasi #13, satu-satunya temuan asesmen yang tersisa terbuka, ditutup hari itu.

**Bentuk yang diputuskan.** Layar `Aset › Log BBM & Jam Alat` menulis ke tabel
`ast_equipment_logs`: satu baris per pembacaan pada sebuah MOBILISASI — tanggal,
hour meter (angka yang terbaca di meter, bukan selisih), liter BBM yang diisi,
catatan. Boleh lebih dari satu baris per hari (pengisian pagi dan sore adalah dua
fakta); minimal salah satu dari kedua angka wajib diisi.

**Siapa yang mencatat: peran lapangan.** Menulis butuh `prj.update` — site manager
dan manajer proyek — izin Proyek pada rute Aset, dengan sengaja: memberi mereka
`ast.create` akan sekaligus membolehkan mencetak ASET baru, kuasa yang jauh lebih
lebar daripada menambah baris BBM. Membaca butuh `ast.view` ATAU `prj.view` (pipa
`|` spatie — §3.7). `teknisi` tidak bisa menulis register ini, dan kolom pencatat
diambil dari akun yang sedang masuk, tidak pernah dari isian formulir.

**REGISTER, bukan pembukuan.** Ia tidak memposting jurnal, tidak menggerakkan stok,
tidak membawa rupiah. Biaya BBM tetap mengalir lewat kas kecil kategori "BBM & Tol" —
mencatat di sini TIDAK menggantikan voucher kas kecilnya, dan sebaliknya. Barisnya
append-only: tidak ada Ubah dan tidak ada Hapus; salah ketik dikoreksi baris
berikutnya, dan server menolak PUT/DELETE dengan kalimat yang mengatakan persis
itu. Hour meter dijaga monoton dari kedua arah, log hanya diterima pada rentang
hari mesinnya memang di lokasi, dan tanggal masa depan ditolak.

Kartu Aset (Form F/KA) kini mencetak registernya sebagai tabel ketiga — LOG BBM &
JAM ALAT — tanpa kolom rupiah, karena uangnya memang tidak di sini. Panduan
pengguna §9.5 menjelaskan layarnya langkah demi langkah, termasuk kalimat-kalimat
penolakannya.

### (d) Mengejar akrual alat yang tertinggal, per bulan terbuka

**DIJALANKAN 22 Agustus 2026.** Kelima bulan Maret–Juli 2026 diakru pada basis data
hidup selagi semuanya masih terbuka: 13 baris `fin_project_costs` — satu per
mobilisasi per bulan — total **Rp 573.000.000**, cocok baris per baris dengan tabel
di bawah. Ketiga alat yang sejak Maret dan Mei menyumbang Rp 0 ke ember biaya
peralatan (paparan yang diukur asesmen: Rp 585.000.000 per 3 Agustus, versus
RAP/2026/0001 yang menganggarkan Rp 178.031.790,79 untuk peralatan) kini membebani
bulan pemakaiannya — AC, CPI/EAC, dan basis biaya POC membaca angka yang sama, dan
belum ada run PSAK 115 terposting yang sempat memotret angka lamanya.

**Mengapa tidak dikejar otomatis.** Alasannya tertulis penuh di kode, dan ia sah:
akuntansi di sini **maju-saja**, dan mengakru bulan lampau sebuah mobilisasi yang masih
terbuka membuat baris biaya bertanggal di bulan-bulan itu — **yang sah persis selama
kalender fiskal mengatakan bulan itu masih terbuka.** Bulan yang sudah **ditutup** tanpa
akrual **tidak diperbaiki dengan memundurkan tanggal** (itu akan mengubah buku yang sudah
ditandatangani seseorang); biaya alatnya muncul pada demobilisasi, bertanggal hari
kembali.

Karena itu **daftar periksa tutup buku, bukan cron, yang menjadi wasitnya**: *"A cron can
miss a month forever…; the plant_accrued checklist item cannot, because the closer has to
read it before the month becomes unrepairable. This command is the hands, the checklist
is the eyes."*

**Yang dijalankan 22 Agustus**: `ast:accrue-plant 2026 3` sampai `2026 7`, dari yang
tertua, satu bulan per perintah, dalam bentuk baku §1 (`sudo -u www-data env
HOME=/tmp …`). Memeriksa keadaannya kapan pun, tanpa menulis apa pun: buka
**Keuangan → Periode Fiskal** bulan yang bersangkutan dan baca butir **"Akrual alat
internal bulan ini sudah dicatat"** — ia menyebut per mobilisasi: kode, jumlah hari,
dan rupiah yang belum diakru.

**Angka yang terbukukan**, dihitung dengan aturan hari yang sama seperti perintahnya
(inklusif kedua ujung, dimulai dari yang terakhir antara tanggal mobilisasi dan awal
bulan):

| Bulan | DEP/2026/III/0001 | DEP/2026/III/0002 | DEP/2026/V/0003 | Total bulan |
|---|---|---|---|---|
| 2026-03 | 30 hari · 75.000.000 | 30 hari · 30.000.000 | — | **105.000.000** |
| 2026-04 | 30 hari · 75.000.000 | 30 hari · 30.000.000 | — | **105.000.000** |
| 2026-05 | 31 hari · 77.500.000 | 31 hari · 31.000.000 | 21 hari · 10.500.000 | **119.000.000** |
| 2026-06 | 30 hari · 75.000.000 | 30 hari · 30.000.000 | 30 hari · 15.000.000 | **120.000.000** |
| 2026-07 | 31 hari · 77.500.000 | 31 hari · 31.000.000 | 31 hari · 15.500.000 | **124.000.000** |
| | | | **Jumlah Mar–Jul** | **573.000.000** |

Selisih dengan angka Rp 585 juta di asesmen **bukan kesalahan**: Rp 585 juta adalah
paparan **per 3 Agustus 2026** termasuk hari-hari Agustus yang sudah lewat, sedangkan
perintah ini hanya boleh mengakru bulan yang **sudah berakhir**. **Sisa Agustus diakru
otomatis oleh jadwal 05:40 pada 1 September** — SELAMA alatnya belum didemobilisasi
lebih dulu: begitu sebuah alat dikembalikan, seluruh sisanya dilunasi baris residual
bertanggal hari kembali dan bulan lampaunya tidak bisa lagi diakru (§5.4, jebakan 3).

**Yang tetap berlaku sesudah kejar-tayang ini.** Wasitnya tetap butir daftar periksa
`plant_accrued` — WARN yang menyebut mesin, hari, dan rupiahnya setiap kali sebuah
bulan mau ditutup dengan akrual yang belum dicatat (§5.4, §6.3).

---

*Panduan ini menjelaskan perilaku kode per 22 Agustus 2026. Setiap klaim di dalamnya
diverifikasi terhadap berkas sumber atau terhadap salinan basis data hidup. Bila sebuah
perilaku berubah, panduan inilah yang salah — bukan kodenya.*
