# Onboarding minggu pertama — Site Manager (`site-manager`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `site-manager` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 7** (§7.3–§7.6), ditambah §9.5
(log BBM & jam alat), **16** (Anda yang mengajukan IPP), dan **17** (inspeksi mutu, NCR,
benda uji di lokasi Anda).

---

## 1. Siapa Anda di sistem

- **Peran akun:** `site-manager`. **Akun demo:** `site-manager@nusantara.test` (Agus
  Prasetyo) — pakai akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** mencatat lapangan setiap hari dan
  **mengajukan** — laporan harian, formulir K3, izin kerja, IPP, inspeksi mutu, NCR,
  benda uji, temuan — sementara **menyetujui** bukan pekerjaan Anda: peran ini tidak
  memegang satu pun izin setujui.
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1): Anda berdiri di
  **awal rantai lapangan → progres → tagihan** (§1.4): laporan harian dengan foto ber-GPS
  → progres mingguan → kurva-S; IPP, izin kerja, K3 → inspeksi mutu → NCR (yang memblokir
  BAST) → BAST → termin. Yang Anda ketik hari ini adalah yang ditagihkan bulan depan.
- **Yang menyerahkan pekerjaan kepada Anda:** `project-manager` (proyek yang sudah
  ber-WBS, tim, lokasi tapak), `estimator` (gambar dan submittal SDS/SMS yang harus
  distempel MK sebelum IPP Anda bisa diajukan), `warehouse` (GRN terposting yang Anda
  tarik ke laporan harian lewat `Impor dari GRN`).
- **Yang menerima pekerjaan dari Anda:** `project-manager` — semua yang Anda ajukan:
  IKL/ILB/IMK dan IPP (`Setujui`), inspeksi (`Setujui`), NCR (`Verifikasi`), temuan
  (`Verifikasi`/`Dispensasi`), insiden K3 (`Tutup Insiden`), BAST. Konsultan MK
  menandatangani lembar yang Anda cetak; stempelnya diketik manajer proyek.

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2).

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Engineering** — Ijin Pelaksanaan (IPP) · Persetujuan Gambar (SDS) · Persetujuan
  Material (SMS) · Lokasi Tapak.
- **Proyek** — Laporan Harian · Lapangan (mobile) · Formulir K3 Harian · Register K3
  (SMK3) · Izin Kerja (IKL) · Izin Lembur (ILB) · Izin Material (IMK) · Progres
  Mingguan · Register Defect (Punch List) · BAPP per Zona · Milestone.
- **Mutu (QA/QC)** — Inspeksi Mutu (QCI) · Ketidaksesuaian (NCR) · Benda Uji Beton.
- **Persediaan** — Saldo Stok · Penerimaan (GRN) · Pengeluaran — Anda hanya membuat
  draf (§6.1).
- **Aset** — satu baris saja: Log BBM & Jam Alat (baris ini punya izinnya sendiri, itu
  sebabnya kelompok Aset tampil untuk Anda hanya berisi satu baris — §1.4, §9.5).
- **Sistem** — Impor Data Master · Impor Dokumen (dua baris ini juga punya izinnya
  sendiri — §1.4).

Kelompok Proyek Anda memuat 20 layar dan Engineering 6; yang di atas cukup untuk minggu
pertama. Kelompok Estimasi, Subkontrak, Keuangan, dan Pengadaan tidak ada di sidebar Anda.

**Dasbor Anda** (PANDUAN §1.7):

- Ubin **Proyek berjalan**. Sakelar **`Proyek saya`** mencocokkan akun dengan kolom
  **manajer proyek**, bukan site manager — untuk Anda ia mengembalikan nol baris. Biarkan
  mati.
- Kartu **Kalender Acara** (selalu digambar, walau bulan kosong), **Progres proyek** dan
  **Stok di bawah minimum** (keduanya digambar hanya bila ada isinya).
- Kartu **Menunggu persetujuan Anda** — **selalu kosong untuk Anda**, karena Anda tidak
  menyetujui apa pun. Kabar bahwa dokumen Anda disetujui atau ditolak datang lewat
  **lonceng** (lencana Disetujui hijau / Ditolak merah) dan lewat lencana status di layar
  daftarnya.

**Lonceng dan Tenggat** — yang ditujukan kepada peran Anda (PANDUAN §1.7), dua saja:

| Tenggat | Diperingatkan |
|---|---|
| Tindak lanjut insiden K3 mendekati batas waktu | 3 hari |
| Milestone proyek mendekati jatuh tempo | 7 hari |

Target perbaikan temuan **tidak pernah** muncul di Tenggat — satu-satunya alarmnya adalah
kolom **Umur** dan lencana **Lewat target** di Register Defect (§7.6). Buka layar itu.

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu dokumen keluar dari Draf/Ditolak; jalan kembali
   adalah `Tolak` (oleh manajer proyek).
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — hanya pembalikan, dan itu permanen.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; laporan harian yang panjang disimpan dulu, lalu dibuka lagi lewat `Ubah`.

## 3. Pekerjaan Anda

Sembilan walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Laporan harian, setiap hari kerja.** Pemicu: hari berjalan di lokasi. Layar: `Proyek ›
   Laporan Harian` → **`Tambah Laporan Harian`** → Proyek (tidak bisa dipindah setelah
   tersimpan), Tanggal laporan, cuaca, **Jam mulai/selesai kerja**, **Kegiatan hari ini**,
   Catatan K3 → lima tabel baris: **Tenaga kerja per jabatan** (dua belas jabatan
   FM-10-12; totalnya menjadi turunan), **Uraian pekerjaan**, **Material masuk** (tombol
   **`Impor dari GRN`** menawarkan GRN terposting gudang site pada tanggal laporan),
   **Pemakaian material**, **Alat-alat** → **`Simpan`**. Nomor: `DRP/2026/09/0001`.
   Tidak ada persetujuan; laporan hidup sampai BAST I disetujui, lalu terkunci. Satu
   laporan per proyek per tanggal. Foto masuk lewat kartu **Lampiran** dan tampil di
   Galeri Foto. → PANDUAN §7.3.

2. **Dari ponsel di lokasi.** Layar: `Proyek › Lapangan (mobile)` → tab **Laporan
   Harian** → pilih Proyek dan Tanggal → stepper **Tenaga kerja per jabatan**, kotak
   **Kegiatan** → **`Buat laporan hari ini`**; kartu **Foto lapangan** → **`Ambil foto`**
   (batas 5 MB, GPS diminta sekali per jepretan). Formulir cepat itu hanya dua isian;
   cuaca, jam kerja, kendala, dan keempat tabel lainnya **dilengkapi lewat `Proyek ›
   Laporan Harian` → `Ubah`** sebelum lembarnya dicetak. Dropdown proyek di sana
   menawarkan semua proyek, termasuk yang ditutup — penolakan baru datang setelah tombol
   ditekan. → PANDUAN §7.4.

3. **Formulir K3 harian dan insiden.** Layar: `Proyek › Formulir K3 Harian` →
   **`Tambah Formulir K3 Harian`** → Topik toolbox, Peserta, baris **Pemakaian APD per
   kategori**, baris **Temuan & tindak lanjut** → **`Simpan`**. Nomor:
   `HSE/2026/09/0001`; tautan ke laporan harian hari itu terisi sendiri. Kecelakaan atau
   near miss dicatat di `Proyek › Register K3 (SMK3)` → **`Tambah Insiden K3`** →
   Waktu kejadian (tidak boleh di masa depan), Keparahan, Jenis, Uraian, **Penyebab
   dasar**, **Tindakan korektif**, **Penanggung jawab**, Target selesai. Nomor:
   `K3/2026/IX/001`. Yang menutupnya (**`Tutup Insiden`**) adalah manajer proyek; Tenggat
   mengingatkan Anda 3 hari sebelum batas waktu tindak lanjut. → PANDUAN §7.7.

4. **Izin kerja lapangan, lembur, dan material.** Pemicu: pekerjaan berisiko, lembur,
   kendaraan masuk/keluar. Layar: `Proyek › Izin Kerja (IKL)` → **`Tambah Izin Kerja
   Lapangan`** (Tanggal izin di dalam masa pelaksanaan, Shift, Berlaku mulai/sampai,
   Potensi bahaya dan APD satu per baris, Pemohon, Petugas K3) → **`Simpan`** →
   **`Ajukan`**. Nomor: `IKL/2026/IX/0001`. `Proyek › Izin Lembur (ILB)` → **`Tambah Izin
   Kerja Lembur`** (Jam mulai/selesai, tabel **Daftar pekerja lembur**: Karyawan ATAU
   Nama non-karyawan, Jam) → `ILB/…`; `Setujui` pada ILB menulis jam lembur karyawan ke
   rekap absensi. `Proyek › Izin Material (IMK)` → **`Tambah Izin Masuk/Keluar
   Material`** → `IMK/…`; setelah Disetujui, **`Periksa di gerbang`** (izin ubah proyek —
   Anda boleh menekannya mewakili pos jaga, sekali saja). Penyetuju ketiganya: **manajer
   proyek** (atau direktur/admin); pada IKL, keputusan Konsultan MK lewat tautan yang
   diterbitkan manajer proyek juga menggerakkan izin (§15). IKL yang salah setelah
   diajukan: **`Buat Revisi`**, bukan Ubah. → PANDUAN §7.13.

5. **IPP sebelum pekerjaan dimulai.** Pemicu: paket pekerjaan siap jalan, gambar dan
   material sudah distempel MK. Layar: `Engineering › Ijin Pelaksanaan (IPP)` →
   **`Tambah Ijin Pelaksanaan Pekerjaan`** → Proyek, Lingkup, Rencana mulai, Durasi,
   **Lokasi tapak**, **Paket pekerjaan (WBS)** (paket daun ber-BOQ; menetes ke bon
   gudang), baris **bahan**, **alat**, **gambar** (menunjuk SDS), **material** (menunjuk
   SMS) → **`Simpan`** → **`Ajukan`** — gerbangnya menolak selama ada gambar/material yang
   belum berstempel pembuka. Nomor: `IPP/2026/IX/0001`. Penyetuju: **manajer proyek**.
   Metode berubah setelah disetujui: **`Buat Revisi`** → IPP baru yang melewati gerbang
   lagi. → PANDUAN §16.5. Lokasi tapak (Tower › Lantai › Zona › As › Ruang) Anda susun
   di `Engineering › Lokasi Tapak` → **`Tambah Lokasi`**, atau impor CSV lewat `Sistem ›
   Impor Data Master` → §16.7.

6. **Inspeksi mutu, NCR, benda uji.** Layar: `Mutu › Inspeksi Mutu (QCI)` → **`Tambah
   Inspeksi Mutu`** → Proyek, **Template checklist**, Lokasi, IPP terkait, Tanggal,
   Inspektor, Disaksikan → **`Muat butir dari template`** → Hasil per butir (Sesuai /
   Tidak sesuai / Tidak berlaku) → **`Simpan`** → **`Ajukan`**. Nomor:
   `QCI/2026/IX/0001`. Lulus/tidak dihitung dari butirnya; penyetuju: **manajer proyek**.
   Ketidaksesuaian: `Mutu › Ketidaksesuaian (NCR)` → **`Tambah NCR`** → Inspeksi asal
   (mengisi Tahap dan Lokasi), Uraian, **tepat satu penanggung jawab** (karyawan ATAU
   subkontraktor), Batas waktu → **`Mulai Perbaikan`** saat perbaikan berjalan;
   **`Verifikasi`** milik manajer proyek; **`Tutup`** boleh Anda tekan setelah
   terverifikasi. Nomor: `NCR/2026/IX/0001`. Benda uji: `Mutu › Benda Uji Beton` →
   **`Tambah Benda Uji`** → Mutu (`K-350` atau `fc'-30`), No. truk, baris **Hasil uji
   tekan** (Umur 7/14/28, Kekuatan MPa) — kolom Memenuhi dihitung server.
   → PANDUAN §17.2, §17.3, §17.4.

7. **Progres mingguan dan progres WBS.** Layar: `Proyek › Progres Mingguan` → **`Tambah
   Progres Mingguan`** → Proyek, Minggu ke-, Periode, **Rencana kumulatif (%)**, **Aktual
   kumulatif (%)** → **`Simpan`**. Tanpa halaman detail, tanpa Ubah/Hapus: menyimpan
   ulang minggu yang sama memperbarui barisnya. Pada minggu yang dicakup opname ke pemilik
   yang disetujui, angka Aktual Anda **diganti server** dan kolom *Sumber aktual*
   mengatakannya. Progres per paket: halaman proyek → kartu **Struktur WBS** →
   **`Perbarui`** pada tugas daun (Progres %). Tanggal tercapai pada `Proyek › Milestone`
   memberi tahu keuangan bahwa termin siap ditagih. → PANDUAN §7.5, §7.2, §7.8.

8. **Temuan (punch list) dan BAPP per zona.** Layar: `Proyek › Register Defect (Punch
   List)` → **`Catat temuan`** → Temuan, Keparahan, Sumber temuan, Lokasi, Penanggung
   jawab, Target perbaikan → setelah dikerjakan: **`Selesai diperbaiki`** (Tanggal
   perbaikan selesai). Status menjadi *Menunggu verifikasi* — **masih dihitung terbuka dan
   tetap menahan BAST II** sampai manajer proyek menekan `Verifikasi`. Pemeriksaan zona:
   `Proyek › BAPP per Zona` → **`Tambah Berita Acara Pemeriksaan Pekerjaan`** →
   Zona/lokasi, **Hasil pemeriksaan** (Selesai / Diperiksa / Nunggu perbaikan), Pihak dan
   Nama pemeriksa **dari lembar yang benar-benar ditandatangani**. Nomor:
   `BAPP/2026/IX/0001`; zona yang diperiksa ulang mendapat lembar baru. → PANDUAN §7.6,
   §7.16.

9. **Log BBM & jam alat.** Layar: `Aset › Log BBM & Jam Alat` → **`Tambah Log Alat`** →
   Mobilisasi (kode `DEP/…` beserta alatnya), Tanggal, **Hour meter (jam)** (angka yang
   terbaca di meter, bukan selisih), **BBM diisi (liter)** → **`Simpan`**. Register ini
   hanya-tambah: tidak ada Ubah, tidak ada Hapus, untuk siapa pun; salah ketik dikoreksi
   dengan baris baru berangka benar. Ia tidak menggerakkan uang — bon BBM tetap lewat kas
   kecil. → PANDUAN §9.5.

Persediaan ada di sidebar Anda karena Anda boleh **membuat draf** GRN dan bon serta
membaca Saldo Stok; **`Posting ke Stok`** milik admin atau teknisi (§6.1). Bon yang Anda
draf sebaiknya membawa **Paket pekerjaan (WBS)** dan **Proyek tujuan** — tanpa keduanya,
pemakaian itu tidak pernah masuk laporan Varian Material dan biaya proyek (§6.5, §7.9).

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §7.1, §14.2): `Setujui`/`Tolak`,
  `Verifikasi`, `Dispensasi`, `Buka kembali`, `Tutup Insiden`, `Bekukan`, `Tutup proyek`,
  `Catat Keputusan MK`, `Terbitkan Tautan` (MK/Owner), ikon Hapus di modul Proyek, dan
  `Posting ke Stok`. Bukan kerusakan: menyatakan perbaikan selesai adalah pekerjaan
  lapangan; **menerimanya** adalah tindakan orang lain.
- **Laporan ganda** (§7.3): `The report date has already been taken.` — di bawah kolom
  Tanggal laporan. *"Jam selesai ({selesai}) harus setelah jam mulai ({mulai})."* ·
  *"Jumlah tenaga kerja manual ({manual}) berbeda dengan total rincian per jabatan
  ({turunan}); selisih {selisih}. Kosongkan angka manual atau samakan dengan rinciannya —
  rincian per jabatan adalah sumbernya."*
- **Setelah BAST I / proyek ditangguhkan** (§7.3): *"Proyek {kode} berstatus Masa
  Pemeliharaan; {laporan harian|progres mingguan|progres paket pekerjaan|generate WBS}
  hanya dapat dientri pada proyek berstatus Persiapan, Berjalan, atau Finishing."* — dan
  laporan yang sudah terkunci: *"Laporan {kode} terkunci oleh BAST I {kode BAST} (serah
  terima {tanggal}) dan tidak dapat {diubah|dihapus}: pekerjaan sebelum serah terima sudah
  ditandatangani tiga pihak."* Rapikan laporan **sebelum** BAST I disetujui.
- **Gerbang IPP** (§16.5), tanpa tombol konfirmasi: *"IPP {kode} tidak dapat diajukan:
  gambar {kode} ({no} {rev}) masih menunggu keputusan Konsultan MK; material {kode}
  ({nama}) berkeputusan Disetujui dengan catatan — baris material menuntut keputusan
  Disetujui penuh; bereskan catatannya dan ajukan ulang submittal-nya. Selesaikan
  persetujuan MK-nya dahulu."* Kejar lembar yang disebut ke MK, lewat estimator/MP.
- **Gerbang NCR pada inspeksi** (§17.2): *"Inspeksi {kode} tahap {tahap} tidak dapat
  diajukan: masih ada NCR terbuka di lokasi ini dari tahap sebelumnya — {NCR} ({tahap},
  {status}); …. Selesaikan (verifikasi) NCR-nya dahulu sebelum melanjutkan ke tahap
  berikutnya."* Inspeksi ulang pada tahap yang **sama** tetap lolos.
- **NCR** (§17.3): *"Isi tepat satu penanggung jawab: karyawan sendiri ATAU
  subkontraktor, tidak keduanya dan tidak kosong."* · *"Tahap NCR wajib diisi bila tidak
  mengacu pada inspeksi."*
- **BAPP "Selesai" di atas NCR terbuka** (§7.16): *"Zona {kode} ({jalur lokasi}) tidak
  dapat ditandai "Selesai": {n} NCR masih terbuka di zona ini atau di bawahnya (…).
  Verifikasi atau tutup NCR-nya dahulu, atau tandai zona ini "Nunggu perbaikan"."*
- **Izin lapangan** (§7.13): *"Tanggal izin {tanggal} di luar waktu pelaksanaan proyek
  {kode} ({mulai} s/d {selesai}). Izin kerja hanya untuk hari di dalam masa pelaksanaan —
  perpanjangan waktu dicatat lewat CCO waktu, bukan lewat izin."* · *"Izin lembur tanpa
  satu pun baris pekerja bukan izin — lembar ini ditandatangani per orang."* · *"Izin
  {kode} belum disetujui (status: {status}) — pemeriksaan gerbang hanya untuk izin yang
  sudah disetujui manajemen."* · izin yang sudah diajukan: *"Izin kerja lapangan {kode}
  telah digantikan revisi {kode-baru} dan tidak dapat {aksi}; buka revisi terbarunya."*
- **K3** (§7.7): *"Formulir K3 harian untuk proyek dan tanggal ini sudah ada."* · *"Waktu
  kejadian tidak boleh di masa depan."* · *"Risiko sisa dinilai lengkap: kemungkinan DAN
  keparahan, atau kosongkan keduanya."*
- **Temuan** (§7.6): *"Temuan {kode} berstatus {status} dan tidak dapat {diubah|ditandai
  selesai diperbaiki}. Buka kembali lebih dulu bila ada koreksi."* — dan menurunkan
  keparahan dari Kritis/Mayor ke Minor tidak punya kolom alasan di layar mana pun; minta
  manajer proyek memakai `Dispensasi`, atau administrator.
- **Log alat** (§9.5): *"Hour meter {angka} lebih rendah dari pembacaan terakhir {angka}
  ({tanggal}) pada mobilisasi {kode}. Meter hanya berjalan maju — angka yang lebih rendah
  berarti salah ketik atau salah alat."* · *"Tanggal log {tanggal} masih di masa depan —
  register mencatat pembacaan yang sudah terjadi, bukan rencana."*
- **Progres mingguan** (§7.5): *"Proyek {kode} berstatus {status}; progres mingguan hanya
  dapat dientri pada proyek berstatus Persiapan, Berjalan, atau Finishing."* — dan
  **`Catat minggu`** di halaman proyek membuka dialog dengan kolom Proyek **kosong**;
  salah pilih proyek tidak bisa dikoreksi dari layar mana pun.
- **Yang tidak bisa dibatalkan** (§14.4): `Simpan` log BBM/jam alat · baris progres
  mingguan yang salah proyek · foto dan lampiran yang dihapus.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **25** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Proyek — Data Proyek (F/DP, kepala halaman proyek)
- Proyek — Laporan Harian (F/LH, halaman laporan)
- Proyek — Detail Schedule / Program Kerja (F/DS, ikon printer pada **baris** Progres
  Mingguan; selalu bulan berjalan)
- Proyek — Daftar Temuan / Defect List (F/DT, halaman satu temuan; seluruh register)
- Proyek — Izin Kerja Lapangan (F/IK)
- Proyek — Izin Kerja Lembur (F/IL)
- Proyek — Izin Masuk / Keluar Material & Peralatan (F/IM)
- Proyek — Opname ke Pemilik (OPN) (F/OPN)
- Proyek — BAPP per Zona (F/BAPP)
- Proyek — Formulir K3 Harian (F/K3H) · IBPRP (F/IBPRP, seluruh register proyek)
- Engineering — Persetujuan Gambar (SDS) (F/SD) · Persetujuan Material (SMS) (F/SM)
- Engineering — Transmittal (F/TR) · Ijin Pelaksanaan (IPP) (F/IPP)
- Mutu — Inspeksi Mutu (QCI) (F/QI) · Ketidaksesuaian (NCR) (F/NCR)
- Mutu — Benda Uji Beton (F/BU)
- Persediaan — Bukti Penerimaan Barang (F/BPB) · Bon Pengeluaran Barang (F/BM)
- Persediaan — Surat Jalan Antar Gudang (F/SJ) · Berita Acara Stock Opname (F/BAO)
- Persediaan — Daftar Saldo Stok (F/SS, dari halaman Gudang)
- Persediaan — Bukti Retur Pembelian (F/RPB) · Bukti Retur Material (F/RTM)

Laporan Harian juga punya tombol **`XLSX`** (§13.2a). **Aturan kejujuran (§13.5): sel
yang bergaris kosong berarti "tidak tercatat", tidak pernah berarti nol** — jabatan tanpa
baris, kategori APD yang tidak dihitung, dan kolom tanda tangan diisi tangan di lokasi.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `site-manager` di
      dialog Akun.
- [ ] Saya sudah membuat satu laporan harian (`DRP/…`) dengan tabel Tenaga kerja per
      jabatan terisi, dan melihat total tenaga kerja dihitung sendiri.
- [ ] Saya sudah membuat satu laporan dari ponsel lewat `Lapangan (mobile)` dan
      mengirim satu foto ber-GPS.
- [ ] Saya sudah membuat satu Formulir K3 Harian (`HSE/…`) dan melihat tautan laporan
      hariannya terisi sendiri.
- [ ] Saya sudah mengajukan satu izin kerja lapangan (`IKL/…`) dan tahu nama manajer
      proyek yang menyetujuinya.
- [ ] Saya sudah mencoba mengajukan satu IPP dan membaca kalimat gerbangnya — atau
      melihatnya lolos karena SDS/SMS-nya sudah berstempel.
- [ ] Saya sudah mengajukan satu inspeksi mutu (`QCI/…`) dan melihat kolom Lulus terisi
      dari butirnya.
- [ ] Saya sudah mencatat satu temuan, menekan `Selesai diperbaiki`, dan melihat statusnya
      *Menunggu verifikasi* — masih terbuka.
- [ ] Saya sudah menyimpan satu baris progres mingguan dan membaca kolom *Sumber aktual*.
- [ ] Saya sudah mencatat satu log BBM/jam alat dan tahu bahwa barisnya tidak bisa diubah.
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan tahu dua tenggat mana yang milik
      peran saya.
- [ ] Saya sudah mencetak satu Laporan Harian (F/LH) dan tahu sel mana yang bergaris.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **IKL/ILB/IMK, IPP, inspeksi menunggu `Setujui`; NCR dan temuan menunggu
  `Verifikasi`; insiden menunggu `Tutup Insiden`** → manajer proyek, atau direktur
  (§7.13, §16.5, bab 17).
- **Gambar/material belum berstempel MK sehingga IPP tertahan** → estimator untuk
  submittal-nya, manajer proyek untuk stempelnya (§16.3, §16.4).
- **Draf GRN/bon perlu diposting** → admin atau teknisi (§6.1).
- **Laporan harian yang terkunci BAST I, baris progres mingguan salah proyek, tugas
  WBS** → administrator (§7.3, §14.3).
- **Tautan persetujuan MK/Owner untuk IKL atau laporan harian** → manajer proyek, pemegang
  izin setujui proyek (bab 15).
- **Pertanyaan proyek, jadwal, termin** → manajer proyek (§7.2, §7.8).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang menampilkan dokumen Anda
> yang ditolak dan yang menunggu dalam satu permintaan; draf formulir (termasuk laporan
> harian) yang bertahan di peramban saat sesi habis; catatan persetujuan inline tanpa
> dialog; dan ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini menggambarkan
> yang berlaku.
