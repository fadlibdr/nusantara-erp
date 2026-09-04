# Onboarding minggu pertama — Estimator / Drafter (`estimator`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `estimator` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 4, 16** — Anda estimator yang
menyusun AHSP/BOQ/RAP, dan drafter yang mendaftarkan gambar serta menyiapkan submittal.

---

## 1. Siapa Anda di sistem

- **Peran akun:** `estimator`. **Akun demo:** `estimator@nusantara.test` (Made Wirawan) —
  pakai akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** menghitung — buku analisa (AHSP), BOQ/RAB, dan
  RAP — lalu mendaftarkan gambar, menyiapkan submittal SDS/SMS dan transmittal, dan
  membaca berkas lelang serta riwayat harga sebelum menulis angka.
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1):
  - **Penawaran → kas** (§1.1): paket tender, RKK, dan TKDN milik sales bermuara pada
    penawaran; BOQ Anda adalah angka di baliknya. Di layar Penjualan Anda **hanya
    membaca** (izin `crm.view`).
  - **Permintaan → pembayaran** (§1.2): dua gerbang PO membaca dokumen Anda — **harga BOQ
    yang disetujui adalah plafon harga PO** dan **RAP yang disetujui adalah gerbang
    anggaran** (PANDUAN §4.7). BOQ Draf tidak memagari apa pun.
  - **Lapangan → progres → tagihan** (§1.4): SDS/SMS yang Anda ajukan dan distempel MK
    membuka gerbang IPP; tanpa stempel itu lapangan tidak boleh mulai.
- **Yang menyerahkan pekerjaan kepada Anda:** `sales` (paket tender, penawaran yang
  butuh RAB), `project-manager` (proyek/kontrak yang butuh BOQ dan RAP, gambar kerja).
- **Yang menerima pekerjaan dari Anda:** `direktur` (`Setujui` BOQ dan RAP — Anda tidak
  memegang setujui estimasi), `project-manager` (`Catat Keputusan MK` pada SDS/SMS
  Anda, RAP untuk baseline), `procurement` (membaca harga BOQ dan daftar SMS sebelum
  membeli), `site-manager` (IPP yang menunjuk submittal Anda).

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2) —
AHSP 40 baris disimpan bertahap.

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Penjualan** — Paket Tender · Penawaran · Lembar TKDN · Penyusun Kualifikasi —
  **baca saja**: tidak ada tombol Tambah untuk Anda di kelompok ini.
- **Estimasi** — AHSP · BOQ / RAB · RAP · Riwayat Harga Satuan · Pustaka Metode Kerja.
- **Engineering** — Register Gambar · Persetujuan Gambar (SDS) · Persetujuan Material
  (SMS) · Transmittal · Ijin Pelaksanaan (IPP) · Lokasi Tapak (baca saja).
- **Proyek** — Daftar Proyek — baca saja; 20 layar proyek tampil untuk Anda tanpa tombol
  Tambah.
- **Aset** — satu baris saja: Log BBM & Jam Alat (baca saja; barisnya bergerbang izin
  lihat proyek, itu sebabnya kelompok Aset tampil hanya berisi satu baris — §1.4, §9.5).
- **Sistem** — satu baris saja: **Impor Dokumen** (baris ini punya izinnya sendiri, itu
  sebabnya kelompok Sistem tampil untuk Anda hanya berisi satu baris — §1.4).

Kelompok Pengadaan, Persediaan, Keuangan, Subkontrak, dan Mutu tidak ada di sidebar Anda.

**Dasbor Anda** (PANDUAN §1.7):

- Ubin **Proyek berjalan**; kartu **Progres proyek** (bila ada proyek berjalan) dan
  **Kalender Acara**.
- Kartu **Menunggu persetujuan Anda** — **selalu kosong untuk Anda**, karena Anda tidak
  menyetujui apa pun. Kabar bahwa BOQ/RAP Anda disetujui atau ditolak datang lewat
  **lonceng** (lencana Disetujui hijau / Ditolak merah) dan lewat kolom Status di daftar.

**Lonceng dan Tenggat:** **tidak ada satu pun tenggat yang ditujukan kepada peran Anda.**
Sembilan belas tenggat di layar Tenggat disaring menurut izin, dan tidak satu pun memakai
izin estimasi atau engineering — layar itu kosong untuk Anda. Batas pemasukan paket tender
diperingatkan kepada `sales` (izin buat penjualan), bukan kepada Anda; bacalah kolom
**Batas pemasukan** di `Penjualan › Paket Tender`, yang diurutkan dari yang paling cepat
jatuh tempo (§3.5a).

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu BOQ/RAP keluar dari Draf/Ditolak; jalan kembali
   adalah `Tolak` oleh direktur.
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — pada modul Anda, yang sepadan: BOQ
   yang disetujui membekukan harganya selamanya.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; simpan dokumen panjang bertahap.

## 3. Pekerjaan Anda

Delapan walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Menyusun analisa harga satuan (AHSP).** Pemicu: pekerjaan baru yang belum punya
   analisa. Layar: `Estimasi › AHSP` → **`Tambah Analisa Harga Satuan`** → Kode AHSP
   (unik, mis. `A.2.3.1.1`), Satuan, Uraian, Kategori, Overhead & profit (%) → tabel
   **Komponen (upah / bahan / alat)**: Jenis · Uraian · Item stok (tautan saja, tidak
   mengambil harga) · Satuan · Koefisien · Harga satuan → **`Simpan`**. Tidak ada nomor
   dokumen dan tidak ada persetujuan: menyimpan berarti menerbitkan, dan harga analisa
   dihitung — bukan diketik. Harga baru mengalir ke baris BOQ **berikutnya** saja.
   → PANDUAN §4.2.

2. **Membuat BOQ / RAB — kepalanya di formulir, barisnya lewat impor.** Pemicu: penawaran
   atau proyek butuh RAB. Layar: `Estimasi › BOQ / RAB` → **`Tambah BOQ`** → Judul
   (Proyek, Kontrak, Penawaran opsional) → **`Simpan`**. Nomor: `BOQ/2026/0001` — dan
   BOQ itu **tinggal kosong Rp 0** sampai Anda mengimpor barisnya: `Sistem › Impor
   Dokumen` → jenis **BOQ / RAB** → **`Unduh template`** → isi `tipe` dokumen/bagian/item
   dengan **nomor BOQ yang baru terbit** pada kolom pengelompok → pratinjau → **`Simpan
   {n} dokumen`**. Kembali ke BOQ → **`Ajukan`**. Penyetuju: **direktur**. Catatan biru
   di formulir BOQ yang mengatakan bagian dan item dikelola dari halaman detail **tidak
   benar** — tidak ada editornya. Harga berubah setelah disetujui: **`Versi Baru`** (BOQ
   baru bernomor baru, versi +1), lalu impor harga baru ke versi itu. → PANDUAN §4.1,
   §4.3, §4.5, §2.9.

3. **Menurunkan RAP dari BOQ.** Pemicu: BOQ Disetujui. Layar: `Estimasi › RAP` →
   **`Tambah RAP`** → BOQ sumber, Proyek, Target margin (%) → **`Simpan`** → di halaman
   RAP: **`Buat dari BOQ`** (menghapus setiap baris yang ada lalu membangun ulang) →
   periksa **Rincian anggaran** → **`Ajukan`**. Nomor: `RAP/2026/0001`. Penyetuju:
   **direktur**. Baris BOQ tanpa AHSP menjadi satu baris **SUBKON**; RAP Rp 0 yang
   disetujui menolak setiap pembelian proyek itu. Manajer proyek juga memegang izin
   membuat RAP — sepakati siapa yang mengerjakannya. → PANDUAN §4.4.

4. **Membaca harga sungguhan sebelum menulis angka.** Layar: `Estimasi › Riwayat Harga
   Satuan` → pilih item, rentang tanggal → kartu Harga terakhir · Terendah—tertinggi ·
   Rata-rata tertimbang, grafik titik biru (harga PO) dan kuning (valuasi GRN). Layar ini
   baca saja dan tidak mengubah harga BOQ mana pun; kosong berarti harga AHSP/BOQ Anda
   masih taksiran, bukan riwayat. → PANDUAN §4.6.

5. **Menulis metode pelaksanaan.** Pemicu: penawaran mengutip "Metode Pelaksanaan".
   Layar: `Estimasi › Pustaka Metode Kerja` → **`Tambah Metode Kerja`** → Kategori dan
   Paket pekerjaan (hanya saat membuat), Judul, Ringkasan, Berlaku sejak → **`Simpan`**;
   dek `pptx`/`docx` masuk ke kartu Lampiran **versi itu**. Nomor: `MTD/2026/0001`.
   Perubahan bukan suntingan: **`Terbitkan Revisi`** melahirkan versi n+1. Tidak ada
   persetujuan. → PANDUAN §4.6a.

6. **Mendaftarkan gambar dan mengajukan submittal.** Pemicu: shop drawing siap diperiksa
   MK. Layar: `Engineering › Register Gambar` → **`Tambah Gambar`** → Proyek, Nomor
   gambar (unik per proyek), Judul, Disiplin, Rencana tanggal ajuan. Lalu `Engineering ›
   Persetujuan Gambar (SDS)` → **`Tambah Persetujuan Gambar`** → Gambar (register),
   Revisi (`R0`), Tanggal diajukan, Pemeriksa → lampirkan berkas dwg/pdf di kartu
   **Lampiran submittal** (dwg/dxf sampai 25 MB; DXF harus ASCII). Nomor:
   `SDS/2026/IX/0001`. Material: `Engineering › Persetujuan Material (SMS)` → **`Tambah
   Persetujuan Material`** → Nama material, Merek, Rujukan spesifikasi, Sampel disertakan
   → `SMS/…`. Yang terjadi berikutnya: lembar kembali dari MK, dan **manajer proyek**
   mencatat stempelnya dengan `Catat Keputusan MK` — bukan Anda. Revisi gambar berikutnya
   (`R1`) diajukan dari layar SDS dan mencap revisi lama *Digantikan*; material yang
   dikembalikan diajukan sebagai **SMS baru**. → PANDUAN §16.2, §16.3, §16.4, §2.7.

7. **Transmittal dan IPP.** Layar: `Engineering › Transmittal` → **`Tambah Transmittal`**
   → Arah, Kepada, Tanggal, baris **Dokumen yang disertakan** (SDS/SMS berisi ID
   dokumennya; "lainnya" cukup uraian) → **`Simpan`**; saat kembali bertanda tangan:
   **`Catat Tanda Terima`** (Diterima oleh, tanggal) — sesudahnya lembar terkunci. Nomor:
   `TRM/2026/IX/0001`. Bila Anda yang menyiapkan IPP: `Engineering › Ijin Pelaksanaan
   (IPP)` → **`Tambah Ijin Pelaksanaan Pekerjaan`** → baris bahan, alat, gambar (SDS),
   material (SMS) → **`Ajukan`**; penyetuju: **manajer proyek**. Nomor:
   `IPP/2026/IX/0001`. → PANDUAN §16.6, §16.5.

8. **Membaca berkas lelang dan mencetak lampiran kualifikasi.** Layar: `Penjualan › Paket
   Tender` → buka paketnya → **Register dokumen lelang** (addendum berurut tanpa lubang)
   dan kartu Lampiran (dokumen pemilihan, addendum, BA aanwijzing) — bacalah sebelum
   menghargai RAB; `Penjualan › Lembar TKDN` → angka TKDN jasa beserta **kalimat
   cakupannya** (tidak ada tombol cetak — disalin tangan ke formulir Kemenperin);
   `Penjualan › Penyusun Kualifikasi` → pilih paket → cetak **Daftar Personil (F/SBD)**
   dan **Dukungan Alat (F/DA)**. Semua ini Anda baca dan cetak; yang menulisnya adalah
   `sales`. → PANDUAN §3.5a, §3.5b, §3.5d, §14.3.

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §14.2): `Setujui`/`Tolak` BOQ dan RAP
  (direktur), `Catat Keputusan MK` (manajer proyek), `Setujui` IPP, semua tombol Tambah
  di kelompok Penjualan dan Proyek, dan `Tambah Lokasi` di Lokasi Tapak (izin buat
  proyek). Di layar `Lapangan (mobile)` Anda membaca *"Anda tidak memiliki izin membuat
  laporan harian."* — memang bukan pekerjaan Anda.
- **BOQ/RAP yang sudah bergerak** (§4.3, §4.4): `"BOQ {kode} cannot be edited while status
  is {status}."` · `"BOQ {kode} cannot be deleted while status is {status}."` · `"RAP
  {kode} cannot be regenerated while status is {status}."` · `"Cannot {aksi} document
  {kode} while status is {status}."` Jalan kembali ke Draf adalah `Tolak` oleh direktur.
- **AHSP yang dipakai** (§4.2): `"AHSP {kode} is referenced by BOQ items and cannot be
  deleted."` Kode ganda ditandai merah pada kolom Kode.
- **Impor Dokumen** (§2.9, §4.5): *"Menimpa dokumen berisi N baris senilai Rp X; berkas
  ini membawa M baris — K baris akan DIHAPUS."* — memperbarui **mengganti seluruh
  baris**; selalu ekspor dokumen utuh. Mengunggah berkas yang sama dua kali **membuat
  dokumen kedua**. Yang boleh ditimpa hanya Draf/Ditolak: *"yang sudah Diajukan, Disetujui
  atau Selesai harus dibuatkan Versi Baru lebih dulu."*
- **Impor BOQ yang merusak sesuatu di luar Estimasi** (§4.5): *"RAP {kode} ({status})
  dibuat dari BOQ ini dan seluruh barisnya akan terhapus; buat Versi Baru BOQ lalu impor
  ke versi itu."* · *"{n} tugas WBS proyek / baris permintaan pembelian (PR) / baris SPK
  subkontraktor menunjuk baris BOQ ini; menggantinya memutus tautan itu tanpa jejak …
  Buat Versi Baru BOQ lalu impor ke versi itu."*
- **Impor RAP di atas baseline beku** (§4.5): *"baseline {kode} sudah dibekukan terhadap
  RAP ini; mengganti rinciannya akan mengubah acuan biaya laporan EVM. Buat baseline
  revisi baru lalu impor ke RAP-nya."* — dan *"boq_kode: RAP {kode} milik {BOQ} dan tidak
  dapat dipindahkan ke BOQ lain; buat RAP baru untuk BOQ tersebut."*
- **Koefisien AHSP di berkas** (§4.5): *"koefisien memakai koma sebagai desimal (1,05).
  Titik di kolom koefisien dibaca sebagai desimal — bukan pemisah ribuan."* `1.050`
  berarti satu koma nol lima nol; dibaca terbalik ia mengalikan harga seribu kali dan
  BOQ-nya tetap berjumlah "benar". *"harga_analisa: berkas menulis {X}, tetapi jumlah
  (koefisien x harga satuan) ditambah overhead {n}% ({sumber}) = {Y}. Periksa apakah ada
  baris komponen yang tertinggal."*
- **Metode kerja** (§4.6a): *"Metode untuk paket "Pekerjaan pondasi bore pile" sudah ada
  dan berlaku (MTD/2026/0002 versi 2); terbitkan revisi, jangan entri baru."* · *"MTD/
  2026/0001 sudah digantikan versi berikutnya dan tidak dapat disunting."*
- **Submittal** (§16.3, §16.4): *"Keputusan {stempel} sudah tercatat untuk {kode} pada
  {tanggal} dan tidak dapat ditimpa; bila lembar stempel berbeda, ajukan revisi baru."* ·
  *"Submittal {kode} telah digantikan revisi {kode}; keputusan MK dicatat pada revisi
  terbarunya."* · *"Submittal {kode} sudah berkeputusan {stempel} dan tidak dapat diubah;
  material yang dikembalikan diajukan sebagai submittal baru."*
- **Gerbang IPP** (§16.5), tanpa tombol konfirmasi: *"IPP {kode} tidak dapat diajukan:
  gambar {kode} ({no} {rev}) masih menunggu keputusan Konsultan MK; …. Selesaikan
  persetujuan MK-nya dahulu."* "Disetujui dengan catatan" meloloskan **gambar**, tidak
  meloloskan **material**.
- **Transmittal** (§16.6): *"Transmittal {kode} sudah diterima {nama} pada {waktu} dan
  tidak dapat diubah lagi."* · *"Dokumen {kode} berada pada proyek lain dan tidak dapat
  dimuat pada transmittal proyek ini."*
- **Lampiran** (§2.7): DXF biner ditolak (pesannya menyebut `application/octet-stream`)
  — ekspor ulang dari CAD sebagai ASCII DXF; batas 25 MB untuk `.dwg`/`.dxf`/`.mpp`,
  5 MB untuk yang lain.
- **Yang tidak bisa dibatalkan** (§14.4): `Versi Baru` tidak menggantikan BOQ lama —
  proyek, RAP, dan WBS tetap menunjuk BOQ lama sampai ditautkan ulang · Impor Dokumen
  yang memperbarui dokumen menghapus baris yang tidak dibawa berkas.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **25** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Estimasi — RAB / BOQ (F/RAB) · AHSP (F/AHSP) · RAP (F/RAP)
- Engineering — Persetujuan Gambar (SDS) (F/SD) · Persetujuan Material (SMS) (F/SM)
- Engineering — Transmittal (F/TR) · Ijin Pelaksanaan (IPP) (F/IPP)
- Penjualan (baca saja, tetapi boleh dicetak) — Penawaran (F/PN) · Ringkasan Kontrak (F/RK)
- Penjualan — Berita Acara Tambah-Kurang (F/BATK) · Register Jaminan (F/RJ)
- Penjualan — RKK Penawaran (F/RKK)
- Penjualan — Daftar Personil (F/SBD) · Dukungan Alat (F/DA) — dari halaman Paket Tender
  atau Penyusun Kualifikasi, bertanggal batas pemasukan paket
- Proyek (baca saja, tetapi boleh dicetak) — Data Proyek (F/DP) · Laporan Harian (F/LH)
- Proyek — Detail Schedule / Program Kerja (F/DS)
- Proyek — Daftar Temuan / Defect List (F/DT)
- Proyek — Izin Kerja Lapangan (F/IK) · Izin Kerja Lembur (F/IL)
- Proyek — Izin Masuk / Keluar Material & Peralatan (F/IM)
- Proyek — Opname ke Pemilik (OPN) (F/OPN) · BAPP per Zona (F/BAPP)
- Proyek — Formulir K3 Harian (F/K3H) · IBPRP (F/IBPRP)

**Lembar TKDN sengaja tidak punya tombol cetak** — ia formulir Kemenperin, bukan formulir
rumah (§3.5b). **Aturan kejujuran (§13.5): sel yang bergaris kosong berarti "tidak
tercatat", tidak pernah berarti nol** — submittal yang belum dijawab MK tercetak *"Menunggu
keputusan Konsultan MK"*, bukan stempel karangan.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `estimator` di dialog
      Akun.
- [ ] Saya sudah menyimpan satu analisa AHSP dan melihat harga satuannya dihitung, bukan
      diketik.
- [ ] Saya sudah membuat satu BOQ (`BOQ/…`) yang kosong, lalu mengisi barisnya lewat
      `Sistem › Impor Dokumen` dengan nomor BOQ itu di kolom pengelompok.
- [ ] Saya sudah membaca pratinjau impor sampai baris **Kode dokumen yang tersimpan** dan
      tahu mengapa mengunggah dua kali membuat dokumen kedua.
- [ ] Saya sudah mengajukan satu BOQ dan tahu bahwa direktur yang menyetujuinya.
- [ ] Saya sudah membuat satu RAP (`RAP/…`) dengan `Buat dari BOQ` dan melihat baris
      SUBKON untuk item tanpa AHSP.
- [ ] Saya sudah membuka Riwayat Harga Satuan untuk satu item yang saya hargai.
- [ ] Saya sudah mendaftarkan satu gambar dan mengajukan satu SDS (`SDS/…`) dengan berkas
      gambarnya terlampir pada submittal, bukan pada register.
- [ ] Saya tahu nama manajer proyek yang mencatat stempel MK pada submittal saya.
- [ ] Saya sudah membuka satu Paket Tender dan membaca register dokumen lelangnya.
- [ ] Saya sudah mencetak satu RAB / BOQ (F/RAB) dan satu Persetujuan Gambar (F/SD).
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan tahu bahwa ia kosong untuk peran saya.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **BOQ atau RAP menunggu `Setujui`, atau perlu dikembalikan ke Draf lewat `Tolak`** →
  direktur (§4.3, §4.4).
- **Stempel MK pada SDS/SMS belum dicatat; IPP tertahan gerbang** → manajer proyek
  (§16.3, §16.5).
- **Item stok baru untuk kolom Item stok / `item_kode`** → petugas gudang atau
  administrator, lewat Impor Data Master (§4.5, §6.3).
- **Paket tender, penawaran, TKDN, RKK perlu diubah** → sales (§3.5a–§3.5c).
- **Lokasi tapak untuk IPP** → site manager atau manajer proyek (§16.7).
- **Satu bagian/item BOQ perlu disunting tanpa impor** → tidak ada layarnya untuk siapa
  pun; jalannya impor atau `Versi Baru` (§14.3).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang menampilkan dokumen Anda
> yang ditolak dan yang menunggu dalam satu permintaan; draf formulir (AHSP berbaris
> banyak) yang bertahan di peramban saat sesi habis; catatan persetujuan inline tanpa
> dialog; dan ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini menggambarkan
> yang berlaku.
