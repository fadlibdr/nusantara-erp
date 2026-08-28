# Nusantara ERP — Construction & System Integration

ERP for an Indonesian **construction company & system integrator** (kontraktor gedung +
integrasi sistem ELV/ICT), built as a **Laravel 12 modular monolith**. API-first
(Sanctum), RBAC (spatie/laravel-permission), Indonesian statutory logic built in:
PPN efektif 11% (PMK 131/2024), PPh final jasa konstruksi (PP 9/2022), PPh 21 metode
TER (PMK 168/2023), BPJS Kesehatan & Ketenagakerjaan dengan plafon, THR, terbilang,
penomoran dokumen `PO/2026/VII/0001`.

> Terverifikasi berjalan: 161 migrasi + seed demo penuh, PHPUnit hijau (2.995 uji,
> 13.610 asersi), dan smoke test API (login, seluruh modul, alur
> create→submit→approve) lulus pada PHP 8.3 / SQLite.

## Modules

| Module | Prefix | Isi |
|---|---|---|
| Core | `api/core` | Profil perusahaan (NPWP/NIB/PKP), settings, penomoran dokumen, approval trail, terbilang |
| Iam | `api/iam` | Login Sanctum, users, roles & permissions per modul |
| Crm | `api/crm` | Customers, leads, penawaran (quotation), kontrak + jadwal **termin** |
| Estimation | `api/estimation` | **AHSP**, **BOQ/RAB** berversi, **RAP** (anggaran biaya pelaksanaan) |
| Projects | `api/projects` | Proyek, **WBS** dari BOQ, laporan harian, progress mingguan (**kurva-S**), milestone, **BAST**, penugasan manpower |
| Procurement | `api/procurement` | Vendor (termasuk subkon), **PR → PO** dengan approval berjenjang, evaluasi vendor |
| Inventory | `api/inventory` | Item, gudang pusat & site, **GRN**, pengeluaran barang, transfer, **opname**, kartu stok moving-average |
| Subcontract | `api/subcontract` | **SPK** subkon, **opname** progress claim, retensi, PPh final konstruksi |
| Finance | `api/finance` | COA, jurnal, pajak, **invoice termin AR** + faktur pajak + terbilang, AP bill + potongan PPh, pembayaran, biaya proyek, laporan (TB, L/R, neraca, profitabilitas proyek, aging) |
| HrPayroll | `api/hr` | Karyawan, rekap absensi, **payroll**: lembur /173, BPJS (plafon), **PPh 21 TER** + true-up Desember, **THR** |
| ServiceDesk | `api/servicedesk` | Kontrak maintenance + **SLA**, tiket, jadwal **preventive maintenance** otomatis, berita acara lapangan + sparepart |
| Assets | `api/assets` | Alat berat/kendaraan/alat ukur/IT, **mobilisasi** ke proyek, maintenance, **penyusutan** garis lurus |

Arsitektur & aturan modul: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) ·
[docs/CONVENTIONS.md](docs/CONVENTIONS.md)

## Front-end

SPA di `public/app/` — HTML/CSS/JavaScript murni (ES modules), **tanpa build step**:
tidak ada Node, npm, bundler, atau dependensi CDN. Di-serve sebagai file statis oleh
web server yang sama; root `/` mengarahkan ke `/app/`.

- Login Sanctum (token bearer), navigasi & tombol aksi otomatis mengikuti permission user.
- Seluruh 12 modul: daftar (cari, filter, paginasi), detail dokumen, form create/edit
  termasuk baris item, dan aksi lifecycle (`submit`/`approve`/`reject`/`post`).
- Layar khusus: dasbor lintas modul + kotak persetujuan, workspace proyek dengan
  **kurva-S** dan pohon WBS, laporan keuangan (neraca saldo, L/R, neraca, aging AR/AP,
  profitabilitas proyek), saldo & kartu stok, register slip gaji, workspace SPK subkon
  dengan retensi, alokasi pembayaran, dan matriks hak akses peran.
- Bahasa Indonesia, format Rp/tanggal id-ID, tema terang & gelap, responsif, siap cetak.

Menambah layar = menambah satu entri di `public/app/js/schema.js`; view list/detail/form
generik yang membacanya. Detail: [docs/FRONTEND.md](docs/FRONTEND.md).

## Setup

Requires PHP ≥ 8.2, Composer, MySQL 8 / MariaDB (or SQLite for a demo).

```bash
composer install
cp .env.example .env         # done automatically on first install
php artisan key:generate
# edit .env → DB_* (or switch to sqlite)
php artisan migrate --seed
php artisan serve
```

Seeded demo: one construction project (gedung kantor 8 lantai) and one system-integration
project (ELV & data center bank) end-to-end — kontrak + termin, BOQ + RAP, WBS + kurva-S,
PO + stok, SPK subkon + opname, invoice termin + jurnal, payroll Juni 2026, tiket servis
+ jadwal PM, penyusutan aset.

## Production

The demo seed above is for development only — **never** run `migrate --seed` in
production. Production runs as a Docker Compose stack (php-fpm + nginx + queue +
scheduler + MySQL 8 + Redis):

```bash
cp .env.production.example .env      # isi semua CHANGE_ME
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=ProductionSeeder --force
```

`ProductionSeeder` seeds master data + roles/permissions + satu akun admin dari
env `ERP_ADMIN_*` — tanpa dokumen demo. Full runbook (TLS, first deploy,
backup/restore, monitoring, security checklist):
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Login (seeded)

`POST /api/iam/auth/login` dengan `{"email": "...", "password": "password"}` →
pakai token sebagai `Authorization: Bearer <token>`.

Users: `admin@nusantara.test` (semua akses), plus satu user per peran:
`direktur@`, `project-manager@`, `site-manager@`, `estimator@`, `procurement@`,
`warehouse@`, `finance@`, `hr@`, `sales@`, `teknisi@` — semua `@nusantara.test`,
password `password`.

## API shape

- `GET` list: paginated (`per_page`, default 20), pencarian `q`, filter per modul.
- Response envelope: `{ "data": ..., "message": ..., "meta": ... }`. Untuk endpoint
  list, `meta` berisi `current_page`, `last_page`, `per_page`, `total`, `from`, `to`.
- Dokumen ber-lifecycle: `POST .../{id}/submit | approve | reject` (jejak di
  `core_approvals`), nomor otomatis saat create.

Contoh:

```bash
curl -s -X POST http://localhost:8000/api/iam/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@nusantara.test","password":"password"}'
```

```bash
curl -s http://localhost:8000/api/projects \
  -H "Authorization: Bearer $TOKEN"
```

## Catatan pajak & payroll (WAJIB dibaca sebelum produksi)

Semua parameter ada di [config/erp.php](config/erp.php) — angka statutori berubah;
tinjau setiap tahun:

- **PPN** disimpan sebagai tarif efektif **11%** (PMK 131/2024: 12% × DPP nilai lain
  11/12 untuk non-barang-mewah). Ubah satu angka bila kebijakan berubah.
- **PPh final jasa konstruksi** per PP 9/2022, tarif per klasifikasi/sertifikasi
  (1,75% – 6%). Skema dipilih per SPK subkon dan di-snapshot.
- **PPh 21 TER** (PMK 168/2023): tabel bracket TER A/B/C ada sebagai data di
  `Modules/HrPayroll` — **verifikasi terhadap lampiran PMK** sebelum produksi;
  Desember memakai true-up tahunan Pasal 17 + PTKP.
- **BPJS**: plafon JP & Kesehatan di-review pemerintah tiap tahun; kelas risiko JKK
  konstruksi umumnya ≥ kelas 3.
- Nomor NPWP/NIB/faktur pada seed adalah **dummy**.
- **Kode objek pajak e-Bupot tidak di-seed.** Kodenya diterbitkan DJP, berbeda per
  skema, dan sesekali direvisi — jadi diisi manual di *Master Data › Pajak*.
  Selama kosong, bukti potong yang memakai pajak itu **ditahan** dari file ekspor
  dan dilaporkan di layar, bukan diam-diam ikut terkirim dengan kode yang salah.

## Roadmap yang disarankan

1. ~~Tes otomatis untuk matematika bisnis (PPh 21 TER, moving average, opname subkon,
   laporan keuangan)~~ — **selesai**, `phpunit` menjalankan 2.995 tes.
2. ~~Ekspor pajak & integrasi bank~~ — **selesai**:
   - e-Faktur (PPN keluaran) & e-Bupot (PPh dipotong), di *Keuangan › Ekspor Pajak*;
   - impor rekening koran (MT940 & CSV) + rekonsiliasi, di
     *Keuangan › Rekonsiliasi Bank*. Catatan keduanya di bawah.
   - API bank langsung (host-to-host) tidak dibuat — lihat catatan.
3. ~~Perpetual ↔ GL otomatis penuh (persediaan → jurnal per GRN/issue)~~ —
   **selesai**; cek konsistensinya dengan `php artisan erp:inventory-method-check`.
4. ~~Notifikasi approval, file attachment per dokumen~~ — **selesai**:
   - pemberitahuan persetujuan di dalam aplikasi (lonceng di kanan atas) dan
     opsional lewat email;
   - lampiran berkas pada 22 jenis dokumen. Catatan keduanya di bawah.
   - WhatsApp tidak dibuat — lihat catatan.
5. ~~Mobile untuk laporan harian site + foto geotag, dan teknisi servis~~ —
   **selesai**, di *Proyek › Lapangan (mobile)*. Catatan di bawah.

### Ekspor pajak — batasannya

Layar *Ekspor Pajak* membentuk CSV dari dokumen yang **sudah disetujui** pada satu
masa pajak, dan menampilkan isinya sebelum diunduh: yang siap diekspor, totalnya
untuk dicocokkan dengan buku besar, dan yang **tertahan** beserta sebabnya —
supaya file yang kurang satu invoice tidak terlihat sama dengan file yang benar.

Yang perlu diketahui sebelum dipakai melapor:

- **Tata letak kolom mengikuti skema impor e-Faktur/e-Bupot dan dapat berubah.**
  Impor satu masa ke lingkungan uji DJP dan cocokkan totalnya dulu.
- Nomor bukti potong (`BP-YYYYMM-NNNN`) dibentuk aplikasi, urut per masa pajak.
- Tarif yang tercetak diambil dari jumlah yang **benar-benar dipotong** pada
  tagihan, bukan dari tarif master — supaya revisi tarif tidak mengubah slip lama.

### Rekonsiliasi bank — cara kerjanya, dan batasannya

*Keuangan › Rekonsiliasi Bank* mengimpor rekening koran, mencocokkan mutasinya
dengan pembayaran dan jurnal yang **sudah diposting**, lalu menjembatani saldo
akhir bank ke saldo buku besar rekening itu:

```
saldo buku besar = saldo akhir rekening koran
                 + selisih saldo awal
                 + sudah dibukukan belum tampak di bank   (debit − kredit)
                 − ada di bank belum dibukukan            (kredit − debit)
```

**Layar ini tidak pernah membuat jurnal.** Mengimpor tidak, mencocokkan tidak.
Rekonsiliasi adalah pernyataan *tentang* pembukuan, bukan pembukuan. Biaya admin
bank yang muncul di rekening koran tetap menjadi selisih sampai seseorang
membukukannya sebagai voucher jurnal di layar Jurnal — dua layar, satu jalur
posting. Berkas contoh untuk mencoba impornya ada di [docs/samples](docs/samples).

Yang ditolak, dan alasannya — semuanya adalah cara berkas yang salah tetap
terlihat benar:

- **Berkas yang tidak seimbang.** Saldo awal + mutasi harus sama persis dengan
  saldo akhir. Parser yang menjatuhkan satu transaksi menghasilkan berkas yang
  konsisten dengan dirinya sendiri dan kurang satu mutasi; aritmetika bank
  sendiri adalah satu-satunya yang tahu.
- **Periode yang bertumpang tindih atau berlubang.** Rekening koran satu rekening
  harus bersambung: tidak ada periode yang beririsan, dan saldo akhir satu
  periode harus sama dengan saldo awal periode berikutnya.
- **Berkas yang sama dua kali**, termasuk ke rekening yang berbeda — yang justru
  akan merekonsiliasi satu bank dengan mutasi bank lain.
- **Mata uang selain Rupiah**, dan **MT942** (laporan intraday: tidak memuat
  saldo sehingga kebenarannya tidak dapat diuji).

Batasan yang perlu diketahui:

- **Kolom CSV tidak ditebak.** Tidak ada tata letak CSV rekening koran yang baku
  di Indonesia. Operator menetapkan kolomnya dan memeriksa pratinjaunya. Bila
  berkas punya kolom **Saldo**, petakan — itu satu-satunya pemeriksaan yang tidak
  bergantung pada angka yang diketik sendiri, dan ia menunjuk baris yang salah.
- **Pencocokan harus sama persis**, satu mutasi ke satu dokumen. Satu transfer
  bank yang melunasi beberapa dokumen belum didukung; keduanya tetap tampil
  sebagai pos terbuka dan jembatannya tetap benar.
- **"Jembatan tertutup" bukan "sudah cocok".** Dua pos terbuka yang berlawanan
  arah bisa saling meniadakan — mis. bank memindahkan Rp 300 juta sedangkan ERP
  mencatat Rp 350 juta. Karena itu keduanya dilaporkan terpisah, dan pasangan
  yang nilainya mirip ditandai di bagian *"kemungkinan salah catat"*.
- **API bank langsung tidak dibuat.** Host-to-host di BCA/Mandiri butuh kontrak
  cash management, sertifikat, dan IP allowlist per nasabah — bukan sesuatu yang
  bisa dikirim di dalam aplikasi. Impor berkas melayani semua bank hari ini.

### Notifikasi persetujuan

Saat dokumen **diajukan**, semua pemegang izin `{modul}.approve` diberi tahu;
saat **disetujui atau ditolak**, yang diberi tahu adalah pengaju. Berlaku untuk
kedua belas jenis dokumen yang memakai `Modules\Core\Traits\Approvable`, lewat
satu event — jadi jenis dokumen baru ikut otomatis, asal terdaftar di
`Modules\Core\Support\ApprovableDocuments`.

- **Di dalam aplikasi selalu aktif.** Tidak butuh layanan luar, jadi inilah
  saluran yang benar-benar jalan di setiap pemasangan.
- **Email menyusul bila dinyalakan** di *Pengaturan › Notifikasi*. Bawaannya
  mati: `MAIL_MAILER` pada pemasangan baru bernilai `log`, dan menyalakannya
  sebelum server email disetel hanya menuliskan isi pemberitahuan ke berkas log.
- **Gagal mengirim tidak boleh membatalkan persetujuannya.** Pemberitahuan
  dikirim dari dalam transaksi yang menulis ke buku besar, jadi setiap jalur
  pengiriman dibungkus: server email mati, penerima terhapus, tabel hilang —
  semuanya dicatat ke log lalu diabaikan.
- **WhatsApp tidak dibuat.** Perlu akun gateway (Twilio, Fonnte, Wablas, atau
  Cloud API Meta) dengan kredensial per pelanggan dan template yang disetujui
  Meta. Titik sambungnya ada di `NotificationService::deliver()`.

### Lampiran berkas

Ada pada 31 jenis dokumen (lihat `Modules\Core\Support\AttachableDocuments`),
muncul sebagai kartu **Lampiran** di layar detail. Izinnya ikut dokumennya:
melihat lampiran tagihan vendor butuh `fin.view`, menambah butuh `fin.update`.

Yang ditolak, dan alasannya:

- **Nama berkas tidak pernah menyentuh disk.** Nama simpan dibangkitkan
  (ULID), nama asli hanya label. `../../.env` dan `shell.php` adalah tulisan.
- **Ekstensi dibatasi** — PDF, gambar, Word, Excel, PowerPoint, CSV/teks, XML,
  gambar teknik (DWG/DXF), jadwal MS Project (MPP). `.svg` sengaja tidak ada:
  itu satu-satunya format gambar yang menjalankan skrip. Arsip juga tidak —
  isinya tidak bisa diperiksa di sini.
- **Isi berkas diperiksa** dengan `finfo` dan harus cocok dengan ekstensinya —
  MIME per tipe dipatok dari sampel biner asli (`tests/fixtures/attachments/`),
  bukan dari registri. Berkas HTML bernama `.pdf` ditolak — itulah cara
  unggahan menjadi XSS; `.xml` yang isinya HTML ditolak dengan sniff terpisah,
  dan DXF hanya diterima dalam bentuk ASCII.
- **Berkas tidak berada di web root.** Semuanya di `storage/app/private`, yang
  tidak dilayani nginx; satu-satunya jalan membacanya adalah endpoint yang
  memeriksa izin. Unduhan dikirim dengan `X-Content-Type-Options: nosniff`, dan
  hanya gambar serta PDF yang boleh tampil inline.
- **Batas 5 MB — gambar teknik (dwg/dxf) dan jadwal MPP 25 MB.** Berkas kecil
  dikirim sebagai base64 di dalam badan JSON biasa (api.js mengirim semua badan
  sebagai JSON), sehingga 5 MB menjadi ~6,7 MB di kabel — masih di bawah
  `post_max_size` php-fpm. Kelas 25 MB tidak mungkin lewat situ (~33 MB
  base64), jadi ia naik mentah lewat rute multipart
  `POST api/core/attachments/upload`; `api.uploadFile()` memilih rutenya
  otomatis. Angka `php.ini` produksinya di `docs/DEPLOYMENT.md` §2.1.

### Lapangan & foto ber-GPS

*Proyek › Lapangan* adalah layar untuk dipakai **di lokasi**: satu kolom, tombol
besar, hampir tanpa mengetik, kamera satu ketukan. Dua mode — **laporan harian**
(buat laporan hari ini lalu kirim fotonya) dan **tiket servis** (teknisi
melampirkan foto ke tiket yang sedang dikerjakan).

Bukan aplikasi terpisah: SPA dan API yang sama, jadi tidak ada versi kedua yang
harus disamakan dan tidak ada app store di antara pengawas lapangan dan
perbaikan. Cukup buka situsnya di ponsel.

**Yang membuat fitur ini ada gunanya adalah jaraknya.** Foto progres yang diambil
di parkiran kantor tidak bisa dibedakan dari foto lantai 8 sampai ada yang
mengukur. Setiap foto menampilkan jaraknya dari titik proyek: hijau ≤ 250 m,
kuning ≤ 1 km, merah di atas itu.

Dua sumber lokasi, dan keduanya dicatat apa adanya karena **tidak sama kualitasnya**:

| Sumber | Artinya | Catatan |
|---|---|---|
| `exif` | posisi yang ditulis kamera saat rana ditekan | sezaman dengan fotonya, tetapi tetap hanya byte di dalam berkas dan bisa disunting |
| `device` | posisi ponsel saat foto dikirim | bukan tempat foto diambil, dan mudah dipalsukan di perangkat yang di-root |

EXIF menang bila ada. Kalau tidak ada — dan **biasanya memang tidak ada**, karena
WhatsApp, Telegram dan hampir semua platform menghapus EXIF saat unggah — barulah
posisi perangkat dipakai. Foto tanpa lokasi tetap tersimpan: foto tanpa lokasi
lebih berguna daripada tidak ada foto.

> Jarak ini **bukan alat bukti**. Ia berguna untuk hal yang memang jadi tujuannya:
> menyadari bahwa foto progres hari ini diambil 8 km dari proyek. Izin lokasi
> boleh ditolak, sinyal GPS boleh gagal, dan layarnya tetap jalan.
>
> Lokasi proyek diambil dari `latitude`/`longitude` pada master proyek. Selama
> kosong, jaraknya tidak dihitung dan tidak ditebak.

> **Untuk operator server:** `deploy/sync-erp1.sh` memakai `rsync --delete`.
> `storage/app/private` dan `storage/app/public` **wajib** tetap dikecualikan —
> tanpa itu setiap deploy menghapus seluruh lampiran dan menyisakan baris
> `core_attachments` yang menunjuk ke berkas yang sudah tidak ada.
