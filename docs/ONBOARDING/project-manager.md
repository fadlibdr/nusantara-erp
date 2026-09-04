# Onboarding minggu pertama — Manajer Proyek (`project-manager`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, sepuluh pekerjaan yang benar-benar
Anda lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini
hanyalah yang benar-benar ada di layar akun `project-manager` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 7, 8, 16, 17**, ditambah §4.4
(RAP), bab 9 bila Anda memegang alat, dan bab 15 bila Anda meminta tanda tangan MK/Owner.

---

## 1. Siapa Anda di sistem

- **Peran akun:** `project-manager`. **Akun demo:** `project-manager@nusantara.test`
  (Rina Wijaya) — pakai akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** memimpin pelaksanaan proyek — RAP dan WBS,
  progres dan opname ke pemilik, persetujuan izin lapangan, IPP dan inspeksi mutu, SPK
  subkontraktor, sampai BAST dan penutupan proyek.
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1):
  - **Lapangan → progres → tagihan** (§1.4) adalah rantai Anda dari ujung ke ujung:
    laporan harian dan IPP masuk dari site manager, inspeksi dan NCR menggerbangi BAST,
    BAST membuka termin.
  - **Subkontrak** (§1.3): Anda menyusun SPK dan opname subkon; direktur menyetujui;
    keuangan menagihkan dan membayar.
  - **Permintaan → pembayaran** (§1.2): Anda tidak menyentuh PR/PO, tetapi **RAP yang
    Anda setujukan menjadi gerbang anggaran setiap PO dan SPK** (PANDUAN §4.7).
  - **Penawaran → kas** (§1.1): tanggal tercapai pada milestone Anda yang memberi tahu
    keuangan bahwa termin siap ditagih (PANDUAN §7.8); BAST II Anda yang melepas retensi.
- **Yang menyerahkan pekerjaan kepada Anda:** `site-manager` (laporan harian, IKL/ILB/
  IMK, IPP, inspeksi, NCR, temuan yang "selesai diperbaiki"), `estimator` (BOQ, RAP,
  SDS/SMS yang menunggu stempel MK), `warehouse` (draf bon), `sales` (kontrak → proyek).
- **Yang menerima pekerjaan dari Anda:** `direktur` (RAP/BOQ, SPK, addendum, opname
  subkon, BAST subkon — dan BAST/baseline yang Anda ajukan sendiri), `finance` (termin
  siap ditagih, retensi), `admin` (`Bayar Retensi`, `Cairkan Uang Muka`, koreksi WBS).

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2).

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Estimasi** — RAP · BOQ / RAB · AHSP · Riwayat Harga Satuan.
- **Engineering** — Ijin Pelaksanaan (IPP) · Persetujuan Gambar (SDS) · Persetujuan
  Material (SMS).
- **Proyek** — Daftar Proyek · Laporan Harian · Progres Mingguan · Opname Owner (OPN) ·
  EVM & Baseline · Milestone · BAST · Izin Kerja (IKL) · Izin Lembur (ILB) · Izin
  Material (IMK) · Register K3 (SMK3) · Register Defect (Punch List).
- **Mutu (QA/QC)** — Inspeksi Mutu (QCI) · Ketidaksesuaian (NCR) · Benda Uji Beton.
- **Persediaan** — Saldo Stok · Pengeluaran (draf saja — §6.1).
- **Subkontrak** — SPK Subkon · Addendum SPK · Opname Subkon · BAST Subkon.
- **Aset** — Daftar Aset · Mobilisasi · Log BBM & Jam Alat · Perawatan.
- **Sistem** — Impor Data Master · Impor Dokumen (dua baris ini punya izinnya sendiri,
  itu sebabnya kelompok Sistem tampil hanya berisi keduanya — §1.4).

Sidebar penuh Anda memuat 60 layar lebih; yang di atas cukup untuk minggu pertama.

**Dasbor Anda** (PANDUAN §1.7):

- Ubin **Proyek berjalan** — menjadi **Proyek saya (berjalan)** bila sakelar
  **`Proyek saya`** dinyalakan; sakelar itu mencocokkan akun Anda dengan kolom manajer
  proyek lewat data karyawan, jadi ia hanya berguna bila akun Anda tertaut karyawan.
- Kartu **Kalender Acara** (selalu digambar, walau bulan kosong), **Progres proyek** dan
  **Stok di bawah minimum** (keduanya digambar hanya bila ada isinya).
- Kartu **Menunggu persetujuan Anda** — **untuk peran Anda kartu ini selalu kosong, dan
  itu bukan berarti tidak ada yang menunggu.** Kartu itu hanya mencakup 11 jenis
  dokumen, dan tidak satu pun di antaranya Anda setujui: IPP, inspeksi mutu, BAST,
  baseline, ketiga izin lapangan, dan opname ke pemilik **tidak pernah tampil di sana**.
  Pekerjaan persetujuan Anda datang lewat **lonceng** dan lewat **layar daftar masing-
  masing yang disaring ke status Diajukan**. Pakai keduanya setiap pagi.

**Lonceng dan Tenggat** — yang ditujukan kepada peran Anda (PANDUAN §1.7):

| Tenggat | Diperingatkan |
|---|---|
| Tindak lanjut insiden K3 | 3 hari sebelum batas waktu |
| Milestone proyek | 7 hari sebelum jatuh tempo |
| SPK subkontraktor mendekati tanggal selesai | 14 hari |
| Servis aset berikutnya | 14 hari |
| Penempatan aset melewati rencana kembali | 7 hari |

Baris di Tenggat hilang hanya ketika sebabnya dibereskan, bukan ketika dibaca.

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu dokumen keluar dari Draf/Ditolak; jalan kembali
   adalah `Tolak`.
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri.
4. Yang sudah diposting tidak punya tombol batal — hanya pembalikan, dan itu permanen.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; simpan dokumen panjang bertahap.

## 3. Pekerjaan Anda

Sepuluh walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Menyiapkan halaman proyek sebelum lembar pertama dicetak.** Pemicu: proyek baru
   terbit dari kontrak. Layar: `Proyek › Daftar Proyek` → klik proyek → **`Ubah`** →
   bagian *Tim*: Project manager, Site manager, **Konsultan MK / pengawas**, Sebutan
   konsultan; bagian *Lokasi & jadwal*: Lintang/Bujur. Kolom Konsultan yang kosong
   berarti kotak kosong di kop **ketujuh formulir rumah proyek**, dan kertas yang sudah
   ditandatangani tidak dicetak ulang. Tidak ada nomor baru; kode proyek `PRJ/…` sudah
   ada. → PANDUAN §7.2.

2. **Menyusun RAP dari BOQ.** Pemicu: BOQ proyek sudah Disetujui. Layar: `Estimasi › RAP`
   → **`Tambah RAP`** (BOQ sumber, Target margin) → **`Simpan`** → di halaman RAP tekan
   **`Buat dari BOQ`** → periksa tabel *Rincian anggaran* → **`Ajukan`**. Nomor:
   `RAP/2026/0001`. Penyetuju: **direktur** (Anda tidak memegang setujui estimasi).
   Setelah Disetujui, RAP itu menjadi gerbang anggaran setiap PO dan SPK proyek; proyek
   tanpa RAP disetujui tidak punya gerbang sama sekali, dan **RAP Rp 0 yang disetujui
   menolak setiap pembelian**. → PANDUAN §4.4, §4.7.

3. **Membangun WBS lalu membekukan baseline.** Pemicu: RAP disetujui, jadwal disepakati.
   Layar: halaman proyek → **`Buat WBS dari BOQ`** (tanpa konfirmasi — menghapus WBS dan
   seluruh progres yang ada) → lencana *Bobot daun 100,00%* harus hijau → `Proyek › EVM &
   Baseline` → **`Bekukan baseline`** → **`Buat draf`** → **`Ajukan`** pada barisnya.
   Yang menyetujui adalah tombol **`Bekukan`** pada baris — oleh **orang lain** pemegang
   setujui proyek (direktur/admin), bukan Anda yang mengajukannya. Setelah beku: tidak
   bisa diubah, diambil ulang, atau dihapus. Jadwal MS Project masuk lewat **`Impor
   Jadwal (MPP-XML)`** hanya pada proyek yang belum ber-WBS. → PANDUAN §7.2, §7.10.

4. **Menyetujui izin lapangan dan IPP setiap pagi.** Pemicu: lonceng *Menunggu*, atau
   saring status **Diajukan** di `Proyek › Izin Kerja (IKL)`, `Izin Lembur (ILB)`,
   `Izin Material (IMK)`, dan `Engineering › Ijin Pelaksanaan (IPP)`. Tombol:
   **`Setujui`** (catatan opsional) / **`Tolak`** (alasan wajib). Nomor yang Anda putus:
   `IKL/2026/IX/0001`, `ILB/…`, `IMK/…`, `IPP/…`. Akibat: `Setujui` pada ILB menulis jam
   lembur karyawan ke rekap absensi bulan itu; IMK yang disetujui baru bisa dicap
   **`Periksa di gerbang`**. → PANDUAN §7.13, §16.5.

5. **Mencatat stempel MK pada gambar dan material.** Pemicu: lembar SDS/SMS kembali dari
   Konsultan MK. Layar: `Engineering › Persetujuan Gambar (SDS)` / `Persetujuan Material
   (SMS)` → **`Catat Keputusan MK`** → Stempel · Tanggal stempel · Catatan stempel apa
   adanya. Nomor: `SDS/2026/IX/0001`, `SMS/…`. Ini bukan `Setujui`: keputusan MK adalah
   fakta yang diketik. Hanya stempel **Disetujui** (gambar boleh **Disetujui dengan
   catatan**) yang membuka gerbang IPP; keputusan yang sudah tercatat tidak bisa
   ditimpa — revisi baru. → PANDUAN §16.3, §16.4.

6. **Menyetujui inspeksi mutu dan memverifikasi NCR.** Pemicu: site manager mengajukan
   `QCI/…` atau mencatat `NCR/…`. Layar: `Mutu › Inspeksi Mutu (QCI)` → **`Setujui`** /
   **`Tolak`**; `Mutu › Ketidaksesuaian (NCR)` → **`Verifikasi`** (Tanggal verifikasi).
   Verifikasi itulah yang mencabut blokir: NCR yang masih Terbuka/Perbaikan berjalan
   menahan inspeksi tahap berikutnya di lokasi yang sama, tanda *Selesai* BAPP zona itu,
   dan **BAST I**. Hasil lulus/tidak sebuah inspeksi dihitung dari butirnya, bukan
   diketik. → PANDUAN §17.2, §17.3, §7.16.

7. **Opname ke pemilik.** Pemicu: akhir periode pengukuran dengan MK. Layar: `Proyek ›
   Opname Owner (OPN)` → **`Tambah Opname ke Pemilik`** → Proyek, Periode → baris **ID
   item BOQ** (angka mentah dari halaman BOQ / RAB kontrak ini) + **Volume periode ini**
   → **`Simpan`** → **`Ajukan`**. Nomor: `OPN/2026/IX/0001`. Penyetuju: pemegang
   setujui proyek **selain pengaju**, atau Konsultan MK lewat kartu **Persetujuan
   Eksternal** (tautan sekali-pakai / lembar fisik — §15). Akibat: kolom Aktual pada
   Progres Mingguan untuk minggu yang dicakup **diganti** dengan volume terukur, dan
   opname yang disetujui menjadi backsheet tagihan termin. → PANDUAN §7.14, §7.15, §15.

8. **Menyerahkan penagihan lewat milestone.** Pemicu: milestone yang tertaut termin
   tercapai. Layar: `Proyek › Milestone` → **`Ubah`** → isi **Tanggal tercapai** (ID
   termin terkait adalah angka mentah dari halaman kontrak). Akibat: keuangan menerima
   *"Termin {n} kontrak {kode} siap ditagih — Rp …"* — hanya pada perpindahan kosong →
   terisi. Itulah cara resmi menyerahkan termin ke keuangan. → PANDUAN §7.8, §3.10.

9. **SPK subkontraktor dan opnamenya.** Pemicu: paket pekerjaan disubkontrakkan. Layar:
   `Subkontrak › SPK Subkon` → **`Tambah SPK`** (Subkontraktor = vendor bercentang
   Subkontraktor, Skema PPh final, Retensi %, Masa pemeliharaan s/d, tabel *Rincian
   pekerjaan*) → **`Simpan`** → **`Ajukan`** — dialog *Alasan override prakualifikasi*
   (kosongkan bila subkon sehat), lalu gerbang anggaran RAP sisi subkon. Nomor:
   `SPK/2026/IX/0001`. Penyetuju: **direktur**; SPK **≥ Rp 200 juta** dicap saat
   pengajuan dan hanya bisa disetujui direktur. Setelah Disetujui: **`Buat opname`** di
   halaman SPK → **ID baris SPK** (kolom ID pada *Rincian pekerjaan*) + **Progres
   kumulatif (%)** → **`Ajukan`** → direktur **`Setujui`** → keuangan menagihkannya
   sebagai tagihan vendor. Nomor opname: `CLM/2026/IX/0001`. **`Klaim Uang Muka`** boleh
   Anda tekan; **`Cairkan Uang Muka`** dan **`Bayar Retensi`** hanya admin.
   → PANDUAN §8.1, §8.2, §8.4, §8.6, §8.7.

10. **Memverifikasi temuan, BAST, dan menutup proyek.** Pemicu: site manager menekan
    `Selesai diperbaiki`; pekerjaan siap diserahterimakan. Layar: `Proyek › Register
    Defect (Punch List)` → **`Verifikasi`** (Tanggal diterima) atau **`Dispensasi`**
    (alasan ≥ 10 karakter); *Menunggu verifikasi* masih dihitung terbuka. Lalu `Proyek ›
    BAST` → **`Tambah BAST`** (Jenis: BAST I / BAST II) → **`Ajukan`** → **`Setujui`**
    oleh pemegang setujui proyek **yang bukan pengaju** (pada tim ber-satu MP, itu
    direktur atau admin). Nomor: `BAST/2026/IX/001`. Sebelum BAST II: baca petak
    **Menahan BAST II** di Register Defect — angka itulah yang akan menolak Anda. BAST I
    disetujui mengunci laporan harian dan mematikan seluruh entri lapangan; **BAST II
    disetujui langsung menutup proyek**, tanpa konfirmasi kedua. Penutupan tanpa BAST II:
    tombol **`Tutup proyek`** di halaman proyek membaca daftar periksanya lebih dulu.
    → PANDUAN §7.6, §7.11, §7.12.

Yang juga milik Anda, tetapi bukan minggu pertama: mobilisasi alat (`Aset › Mobilisasi`,
`DEP/2026/IX/001`) dan perawatan — tanpa `Demobilisasi`, `Posting Penyusutan`, `Hapus
Buku` (izin posting aset milik keuangan/admin) → PANDUAN §9.1, §9.4; `Tutup Insiden` pada
`Register K3 (SMK3)` → §7.7; `Persetujuan Eksternal` MK/Owner → bab 15.

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Pemisahan tugas** (setiap dokumen berpersetujuan, PANDUAN §2.5): *"{Dokumen} {KODE}
  diajukan oleh {Nama}; dokumen tidak boleh disetujui oleh pengajunya sendiri. Minta
  persetujuan pengguna lain pemegang izin {modul}.approve, atau matikan "Wajib pemisahan
  tugas" di Pengaturan → Proyek & Persetujuan bila perusahaan Anda memang tidak memiliki
  petugas kedua."* Yang dihitung pengaju adalah penekan `Ajukan` terakhir. Berlaku juga
  pada **`Bekukan`** baseline, BAST, opname ke pemilik, dan IPP yang Anda ajukan.
- **Stempel MK bukan oleh pengaju** (§16.1): *"Pencatat keputusan tidak boleh orang yang
  mengajukan submittal {kode} sendiri — minta pemegang eng.approve lain mencatat lembar
  stempel MK."*
- **Gerbang IPP** (§16.5) — `Ajukan` ditolak tanpa tombol konfirmasi: *"IPP {kode} tidak
  dapat diajukan: gambar {kode} ({no} {rev}) masih menunggu keputusan Konsultan MK; …
  Selesaikan persetujuan MK-nya dahulu."* Material menuntut **Disetujui** penuh.
- **Gerbang NCR** (§17.2, §17.3): *"Inspeksi {kode} tahap {tahap} tidak dapat diajukan:
  masih ada NCR terbuka di lokasi ini dari tahap sebelumnya — …"* dan pada BAST: *"BAST I
  {kode} belum dapat disetujui — {n} NCR masih terbuka ({daftar NCR}); verifikasi atau
  tutup dahulu sebelum serah terima pertama."*
- **Prasyarat BAST II** (§7.11): *"BAST II {kode} belum dapat disetujui — {daftar item}."*
  Temuan kritis/mayor terbuka adalah blokir keras; masa pemeliharaan belum berakhir,
  temuan minor, progres < 100% hanya peringatan yang bisa dilewati alasan ≥ 20 karakter.
- **Plafon opname** (§7.14): *"Volume kumulatif item "{uraian}" {x} {satuan} melampaui
  volume kontrak + CCO disetujui {y} {satuan}; perbaiki volume opname, atau catat dahulu
  volume CCO-nya pada register variasi kontrak."*
- **Baseline** (§7.10): *"Proyek {kode} belum punya RAP; baseline tidak dapat dibekukan
  karena anggaran biaya (BAC) tidak ada. Susun RAP lebih dulu."* · *"Bobot tugas daun
  berjumlah {x}%, bukan 100%; perbaiki WBS sebelum membekukan baseline."*
- **Entri lapangan setelah BAST I / proyek ditangguhkan** (§7.3): *"Proyek {kode}
  berstatus Masa Pemeliharaan; {laporan harian|progres mingguan|progres paket pekerjaan|
  generate WBS} hanya dapat dientri pada proyek berstatus Persiapan, Berjalan, atau
  Finishing."*
- **Ambang direktur SPK** (§8.2): SPK bernilai **≥ Rp 200.000.000** dicap saat pengajuan;
  penyetuju bukan-direktur ditolak dengan pesan yang menyebut nilai, ambang, dan izin
  yang diperlukan. Addendum yang membawa SPK melewati ambang itu (§8.3): *"Addendum {kode}
  membawa nilai SPK melewati ambang persetujuan direktur; dokumen ini hanya dapat
  disetujui oleh pemegang izin scm.approve-director — pada instalasi standar peran
  direktur."*
- **Gerbang anggaran RAP pada SPK** (§4.7, §8.2): dialog **"Melampaui sisa anggaran RAP
  subkon — tetap ajukan?"** dengan tombol **`Ya, tetap ajukan`** — peringatan tercatat,
  bukan tembok.
- **Opname subkon** (§8.4): *"Progress on "{uraian}" cannot go backwards ({x}% < {y}%)."*
  · *"Opname of {bruto} exceeds the remaining SPK value {sisa} on {kode}."*
- **Tutup Insiden K3** (§7.7): *"Insiden belum dapat ditutup — lengkapi dulu: penyebab
  dasar (root cause), tindakan korektif, penanggung jawab."* — ketiganya, walau bantuan
  formulir hanya menyebut dua.
- **Tombol yang tidak akan Anda lihat** (§14.2): `Setujui` SPK/addendum/opname subkon
  (direktur), `Bayar Retensi` dan `Cairkan Uang Muka` (admin), `Posting ke Stok` (admin
  atau teknisi — §6.1), `Demobilisasi` / `Posting Penyusutan` / `Hapus Buku` (keuangan
  atau admin), dan ikon Hapus di modul Proyek (tidak ada izin hapus — §7.1).
- **Yang tidak bisa dibatalkan** (§14.4): `Buat WBS dari BOQ` · `Bekukan` baseline ·
  `Setujui` BAST I dan BAST II · `Simpan` log BBM/jam alat.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **35** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Estimasi — RAB / BOQ (F/RAB) · AHSP (F/AHSP) · RAP (F/RAP)
- Proyek — Data Proyek (F/DP, kepala halaman proyek)
- Proyek — Laporan Harian (F/LH)
- Proyek — Detail Schedule / Program Kerja (F/DS, dari baris Progres Mingguan; selalu
  bulan berjalan)
- Proyek — Daftar Temuan / Defect List (F/DT, dari halaman satu temuan; seluruh register)
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
- Subkontrak — SPK Subkontraktor (F/SP) · Addendum SPK (F/AS)
- Subkontrak — Berita Acara Opname (F/BO) · BAST Subkontraktor (F/BST-SK)
- Subkontrak — Rekap Upah (F/RU)
- Persediaan — Bukti Penerimaan Barang (F/BPB) · Bon Pengeluaran Barang (F/BM)
- Persediaan — Surat Jalan Antar Gudang (F/SJ) · Berita Acara Stock Opname (F/BAO)
- Persediaan — Daftar Saldo Stok (F/SS, dari halaman Gudang)
- Persediaan — Bukti Retur Pembelian (F/RPB) · Bukti Retur Material (F/RTM)
- Aset — Kartu Aset (F/KA) · Berita Acara Mobilisasi Alat (F/BAM)

Letak tombolnya berbeda per jenis layar (§13.2); Laporan Harian, SPK Subkontraktor,
Opname ke Pemilik, dan Rekap Upah juga punya tombol **`XLSX`** (§13.2a). **Aturan
kejujuran (§13.5): sel yang bergaris kosong berarti "tidak tercatat", tidak pernah
berarti nol** — isi tangan, jangan menebak.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `project-manager` di
      dialog Akun.
- [ ] Saya sudah membuka halaman proyek saya dan kolom **Konsultan MK / pengawas** terisi.
- [ ] Saya tahu **`Menunggu persetujuan Anda`** tidak memuat IPP/inspeksi/BAST/izin, dan
      saya sudah menyaring satu layar daftar ke status Diajukan.
- [ ] Saya sudah menyetujui satu izin lapangan (`IKL/…`) atau satu IPP dan melihat
      lencananya berganti hijau.
- [ ] Saya sudah mencatat satu stempel MK dengan `Catat Keputusan MK` (SDS atau SMS).
- [ ] Saya sudah mengajukan satu RAP (`RAP/…`) dan tahu bahwa direktur yang menyetujuinya.
- [ ] Saya sudah menekan `Perbarui` pada satu tugas daun WBS dan melihat progres induk
      dihitung ulang.
- [ ] Saya sudah membaca petak **Menahan BAST II** di Register Defect proyek saya.
- [ ] Saya sudah mengajukan satu SPK (`SPK/…`) dan membaca dialog override prakualifikasi.
- [ ] Saya tahu nama orang kedua yang menyetujui BAST/baseline yang saya ajukan sendiri.
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan tahu lima tenggat mana yang milik
      peran saya.
- [ ] Saya sudah mencetak satu Laporan Harian (F/LH) dan melihat sel bergaris kosong.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **RAP/BOQ, SPK, addendum, opname subkon, BAST subkon menunggu persetujuan** →
  direktur (§4.4, §8.1).
- **BAST atau baseline yang saya ajukan sendiri** → direktur atau admin, pemegang
  setujui proyek yang lain (§2.5, §7.11).
- **`Bayar Retensi`, `Cairkan Uang Muka`** → admin (§8.6, §8.7).
- **Menambah/mengubah satu tugas WBS; menghapus baris di modul Proyek** → administrator
  (§14.3, §7.1).
- **Draf GRN/bon perlu diposting** → admin atau teknisi (§6.1).
- **Termin siap ditagih, retensi** → petugas keuangan (§3.10, §3.13).
- **Data lapangan, laporan harian, inspeksi** → site manager (§7.3, bab 17).
- **Gambar, submittal, BOQ** → estimator (bab 16, bab 4).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang mengumpulkan semua dokumen
> menunggu persetujuan Anda dalam satu permintaan (termasuk yang hari ini hanya lewat
> lonceng); draf formulir yang bertahan di peramban saat sesi habis; catatan persetujuan
> inline tanpa dialog; dan ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini
> menggambarkan yang berlaku.
