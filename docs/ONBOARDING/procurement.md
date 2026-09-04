# Onboarding minggu pertama — Petugas Pengadaan (`procurement`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `procurement` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 5**, ditambah §16.4 (baca daftar
SMS sebelum membeli material yang belum disetujui MK) dan §4.7 (mengapa harga BOQ dan
anggaran RAP mengikat PO yang Anda ajukan).

---

## 1. Siapa Anda di sistem

- **Peran akun:** `procurement`. **Akun demo:** `procurement@nusantara.test` (Andi
  Kurniawan) — pakai akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** mengetik dan **mengajukan** seluruh dokumen
  pengadaan — vendor beserta dokumennya, permintaan pembelian, lembar banding, berita
  acara negosiasi, keputusan pemenang, pesanan pembelian, dan PPK alat & jasa — sementara
  **menyetujui** bukan pekerjaan Anda (peran ini tidak memegang `prc.approve`) dan
  **menerima barang** bukan pekerjaan Anda pula (peran ini tidak memegang `inv.create`).
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1): Anda berdiri di
  **awal rantai permintaan → pembayaran** (§1.2): PR → (RFQ → BA negosiasi → keputusan
  pemenang) → PO → penerimaan barang di gudang → tagihan vendor → pembayaran. Tiga gerbang
  berbunyi saat PO Anda diajukan: prakualifikasi vendor, harga terhadap BOQ, dan anggaran
  RAP. Yang ANALISIS §2 catat dari produksi: PO yang kolom *Perkiraan kirim*-nya kosong
  tidak pernah diawasi siapa pun — satu PO Rp 128 juta disetujui 40 hari tanpa satu pun
  penerimaan. Isi tanggalnya.
- **Yang menyerahkan pekerjaan kepada Anda:** `project-manager` dan `site-manager`
  (kebutuhan material dan alat — mereka tidak memegang izin pengadaan, jadi kebutuhan
  itu sampai kepada Anda di luar sistem dan Anda yang mengetik PR-nya), `estimator` (BOQ
  dan RAP yang disetujui: plafon harga dan gerbang anggaran PO Anda), `direktur`
  (mengembalikan PR/PO Anda sebagai Disetujui atau Ditolak).
- **Yang menerima pekerjaan dari Anda:** `direktur` — `Setujui`/`Tolak` pada PR, PO,
  keputusan pemenang, dan PPK (PO **Rp 100 juta atau lebih** hanya boleh disetujui
  direktur); `warehouse` — menerima barang atas PO yang sudah Disetujui (`Tambah GRN`
  milik gudang, bab 6); `finance` — tagihan vendor dari PO/GRN dan dari tagihan periode
  PPK, lalu pembayarannya (§5.9, §5.10).

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2) —
lembar banding dengan lima vendor disimpan dulu kepalanya, harganya menyusul.

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Pengadaan** — ketiga belas layarnya milik Anda: Vendor & Subkon · Dokumen Vendor ·
  Permintaan (PR) · RFQ (Banding Penawaran) · Pesanan (PO) · Baris PO Terbuka · PPK Alat &
  Jasa · Tagihan Periode PPK · Rekap Tagihan Alat · BA Negosiasi · Keputusan Pemenang ·
  Rencana Pengadaan · Evaluasi Vendor.
- **Estimasi** — BOQ / RAB · RAP · Riwayat Harga Satuan — **baca saja**: harga BOQ beku
  adalah plafon harga PO Anda, RAP disetujui adalah anggarannya (§4.7). AHSP dan Pustaka
  Metode Kerja ikut tampil, tanpa tombol Tambah.
- **Engineering** — Persetujuan Material (SMS) — **baca saja**, dan bacalah sebelum
  membeli (§16.4). Register Gambar, SDS, Transmittal, dan IPP ikut tampil; Lokasi Tapak
  tidak (barisnya bergerbang izin proyek).
- **Persediaan** — Saldo Stok · Penerimaan (GRN) · Item — **baca saja**: tidak ada tombol
  Tambah untuk Anda di kedelapan layarnya (§5.1).
- **Sistem** — satu baris saja: **Impor Data Master** (vendor massal, §2.9). Baris ini
  punya izinnya sendiri; itu sebabnya kelompok Sistem tampil untuk Anda hanya berisi satu
  baris (§1.4).

Kelompok Penjualan, Proyek, Mutu, Subkontrak, Keuangan, SDM, Layanan, dan Aset tidak ada
di sidebar Anda.

**Dasbor Anda** (PANDUAN §1.7):

- **Tidak ada ubin angka** — barisnya berbunyi *"Peran Anda tidak memiliki akses ke
  ringkasan modul mana pun."* Ubin dasbor membaca izin proyek, keuangan, dan layanan;
  tidak satu pun Anda pegang. Itu bukan kerusakan.
- Kartu **Menunggu persetujuan Anda** — **selalu berbunyi** *"Tidak ada dokumen yang
  menunggu persetujuan."* untuk Anda, karena Anda tidak menyetujui apa pun. Kabar PR/PO
  Anda disetujui atau ditolak datang lewat **lonceng** (lencana Disetujui hijau / Ditolak
  merah) dan lewat lencana status di layar daftarnya.
- Kartu **Kalender Acara** (selalu digambar) dan **Stok di bawah minimum** (digambar hanya
  bila ada item di bawah minimum — daftar yang dibaca, tanpa tombol PR di atasnya; §6.10).

**Lonceng dan Tenggat** — yang ditujukan kepada peran Anda (PANDUAN §1.7), tiga saja:

| Tenggat | Diperingatkan | Syaratnya |
|---|---|---|
| Permintaan pembelian mendekati tanggal dibutuhkan | 7 hari | hanya PR tanpa PO |
| Pesanan pembelian lewat tanggal terima | 0 hari | hanya bila Perkiraan kirim terisi |
| Dokumen vendor mendekati akhir masa berlaku | 30 hari | vendor berstatus Aktif |

"Hanya PR tanpa PO" berarti PR berstatus Diajukan atau Disetujui yang belum melahirkan
PO; PO tanpa *Perkiraan kirim* tidak pernah dihitung telat, di Tenggat maupun di Baris PO
Terbuka.

Satu pemberitahuan lain milik Anda: **"Evaluasi vendor diperlukan"**, dikirim ke semua
pemegang `prc.create` saat PO **Rp 100 juta atau lebih** ditutup sementara vendornya belum
dievaluasi enam bulan terakhir (§5.8). Yang **tidak** diawasi siapa pun: tagihan vendor
yang jatuh tempo (§5.9) — keuangan akan menanyakannya kepada Anda, bukan sebaliknya.

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu dokumen keluar dari Draf/Ditolak; jalan kembali
   adalah `Tolak` (oleh direktur), bukan menunggu tombol Ubah kembali.
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — di lajur Anda, `Tutup RFQ` dan
   `Tutup PO` adalah titik tanpa jalan kembali.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; dokumen panjang disimpan bertahap, lalu dibuka lagi lewat `Ubah`.

## 3. Pekerjaan Anda

Sembilan walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Vendor baru dan dokumen prakualifikasinya.** Pemicu: vendor pertama kali dipakai.
   Layar: `Pengadaan › Vendor & Subkon` → **`Tambah Vendor`** → **Nama vendor**,
   **Klasifikasi**, **Jenis vendor** (Pemasok / Subkontraktor / Mandor / Rental — hanya
   Subkontraktor yang boleh masuk SPK, hanya Mandor yang boleh masuk SP3, hanya
   Rental/Pemasok yang boleh masuk PPK), **PKP** (centang ini **menghitung**: PPN PO
   terisi hanya bila vendor PKP),
   **No. SPPKP** (wajib bila PKP), **Termin bayar (hari)** → **`Simpan`**. Tidak ada
   persetujuan. Lalu `Pengadaan › Dokumen Vendor` → satu baris per dokumen: **Jenis**,
   **Nama dokumen**, **Berlaku s/d**, centang **Wajib untuk PO/SPK**. Centang itulah
   gerbangnya: hanya dokumen bercentang Wajib yang lewat masa berlakunya (dan vendor
   Nonaktif) yang memblokir PO/SPK/PPK; register kosong tidak memblokir apa pun. Untuk
   subkontraktor dan mandor, **Komitmen K3L** dan **Pakta Integritas** wajib hadir dan
   belum kedaluwarsa apa pun centangnya. Vendor massal: `Sistem › Impor Data Master` →
   tabel **Vendor & Subkontraktor** (§2.9). → PANDUAN §5.2, §5.3.

2. **Permintaan pembelian.** Pemicu: lapangan butuh barang. Layar: `Pengadaan ›
   Permintaan (PR)` → **`Tambah PR`** → Proyek, Gudang tujuan, **Dibutuhkan tanggal**
   (wajib — inilah yang diawasi Tenggat), Keperluan → tabel **Item yang diminta** (Item ·
   Uraian · **Qty** · Satuan · Estimasi harga; Uraian wajib bila Item kosong) →
   **`Simpan`** → **`Ajukan`**. Nomor: `PR/2026/IX/0001`. Penyetuju: **direktur**.
   Setelah Disetujui, tombol **`Buat PO`** muncul di halaman PR: dialognya meminta
   **Vendor**, Tanggal PO, **Perkiraan kirim**, dan kotak *Alasan override
   prakualifikasi*; ia menyalin setiap baris PR pada estimasi harganya, membawa tautan
   BOQ, dan membuka PO draf. → PANDUAN §5.4.

3. **Lembar banding penawaran (RFQ).** Pemicu: PR disetujui perlu dibanding ke beberapa
   vendor. Tidak ada portal vendor dan tidak ada email — harga yang masuk **Anda ketik**.
   Layar: `Pengadaan › RFQ (Banding Penawaran)` → **`Tambah RFQ`** → **Dari PR
   (disetujui)** (hanya saat membuat; barisnya disalin), **Tanggal RFQ**, Batas masuk
   penawaran, **Vendor diundang** → **`Simpan`**. Nomor: `RFQ/2026/IX/0001`. Di halaman
   RFQ: kartu **Tabulasi penawaran** — ketik harga per sel → **`Simpan harga`** → tombol
   **`Menang`** per baris atau **`Menangkan semua`** per vendor; kartu **Penilaian
   berbobot (sistem nilai)** — Mutu · Waktu · Keuangan · K3 (0–100; skor Harga dihitung
   server dari rasio ke RAB) → **`Simpan penilaian`**. RFQ tidak punya persetujuan — hanya
   Draf dan Selesai; kendalinya ada pada PO yang lahir darinya. Selesaikan kepala, daftar
   vendor, dan baris barang **sebelum** mengetik satu pun harga: `Ubah` sesudahnya
   menghapus seluruh matriks harga. → PANDUAN §5.5.

4. **Berita acara negosiasi.** Pemicu: harga vendor bergerak setelah banding. Layar:
   `Pengadaan › BA Negosiasi` → **`Tambah BA Negosiasi`** → **RFQ** dan **Vendor** (hanya
   saat membuat; vendornya harus yang diundang), **Tanggal pertemuan**, Tempat → baris
   **`Harga awal → harga nego`** → **`Simpan`**. Nomor: `BAN/2026/IX/0001`. Tidak ada
   `Ajukan`/`Setujui`; ia risalah. Daftar hadir **dilampirkan** lewat kartu Lampiran,
   bukan diketik — dan BAN adalah satu-satunya dokumen pengadaan baru yang menerima
   lampiran. → PANDUAN §5.11.

5. **Keputusan pemenang.** Pemicu: pemenang RFQ sudah dipilih. Layar: `Pengadaan ›
   Keputusan Pemenang` → **`Tambah Keputusan Pemenang`** → **RFQ** & **Vendor pemenang**
   (hanya saat membuat), **Nilai RAB (HPS)**, **Nilai diputuskan**, **Alasan deviasi**
   (wajib hanya bila di atas RAB) → **`Simpan`** → **`Ajukan`**. Nomor:
   `AWD/2026/IX/0001`. Ini **satu-satunya dokumen berjenjang**: di bawah Rp 100 juta satu
   penyetuju (`prc.approve`); Rp 100 juta sampai di bawah Rp 1 miliar dua penyetuju
   berbeda, tingkat kedua **direktur**; Rp 1 miliar ke atas tiga. Dua pagarnya: nilai
   yang berbeda dari penawaran terakhir vendor menuntut BAN (butir 4), dan PO dari RFQ
   baru boleh disetujui setelah keputusan ini Disetujui. → PANDUAN §5.12.

6. **Pesanan pembelian — dan menutupnya.** Pemicu: PR disetujui (`Buat PO`), RFQ
   berpemenang (**`Buat PO dari RFQ`** di halaman RFQ — satu PO per vendor pemenang),
   atau pembelian tanpa PR lewat `Pengadaan › Pesanan (PO)` → **`Tambah PO`** (**Vendor**,
   **Tanggal PO**, **Perkiraan kirim**, Termin bayar, baris **Item pesanan**: Uraian ·
   Qty · **Harga satuan**). Nomor: `PO/2026/IX/0001`. PPN dihitung, tidak diketik.
   **`Ajukan`** selalu membuka dialog *Alasan override prakualifikasi* dulu, lalu
   memeriksa berurutan: vendor terhapus → prakualifikasi → harga di atas BOQ (dialog
   **`Ya, harga sudah dinegosiasi`**) → sisa anggaran RAP (dialog **`Ya, tetap
   ajukan`**). Penyetuju: **direktur** — wajib direktur pada Rp 100 juta ke atas. Setelah
   Disetujui, gudang menerima barangnya; kolom **Diterima** di baris PO terisi hanya dari
   GRN yang memakai `Salin baris dari PO`. PO **menutup dirinya sendiri** saat baris
   terakhir diterima penuh; kiriman kurang yang Anda terima ditutup lewat **`Tutup PO`**
   (*"Sisa kuantitas yang belum diterima dibatalkan."*). Yang belum datang Anda kejar dari
   `Pengadaan › Baris PO Terbuka` — kotak **Lewat batas kirim** dan kolom **Telat**.
   → PANDUAN §5.6, §5.7, §4.7.

7. **PPK alat & jasa, lalu tagihan periodenya.** Pemicu: sewa alat atau jasa yang ditagih
   per periode (excavator per jam, scaffolding per bulan). Layar: `Pengadaan › PPK Alat &
   Jasa` → **`Tambah PPK`** → **Vendor rental/jasa** (bertipe Rental atau Pemasok),
   **Proyek**, **Judul pekerjaan** → baris **`Baris alat / jasa`**: **Basis tarif** (Per
   bulan / Per hari (8 jam) / Per jam), **Tarif**, **Plafon kuantitas**, dan **ID aset**
   untuk per jam (alat sewa didaftarkan dulu di Aset — bukan layar Anda) → **`Simpan`**
   → **`Ajukan`**. Nomor: `PPK/2026/IX/0001`; nilainya Σ tarif × plafon, dihitung server.
   Penyetuju: **direktur**. Tiap periode: `Pengadaan › Tagihan Periode PPK` → **`Tambah
   Tagihan periode`** → **PPK**, **Periode mulai**, **Periode selesai** — kuantitas dan
   rupiah **diturunkan server** dari register hour-meter dan kalender, tidak ada angka
   yang diketik. Nomor: `PPKB/2026/IX/0001`. Keuangan menagihkannya lewat *Dari tagihan
   periode PPK* (§5.9); `Pengadaan › Rekap Tagihan Alat` memperlihatkan periode mana yang
   belum jadi tagihan AP (kolom **Tagihan AP** bergaris). → PANDUAN §5.14, §5.15, §5.16.

8. **Evaluasi vendor.** Pemicu: PO besar ditutup (pemberitahuan "Evaluasi vendor
   diperlukan"), atau setengah tahunan. Layar: `Pengadaan › Evaluasi Vendor` →
   **`Tambah Evaluasi Vendor`** → **Vendor**, **Periode** (mis. `2026-S2`), **Kualitas ·
   Ketepatan kirim · Harga · Layanan** (1–5, keempatnya wajib) → **`Simpan`**. Tidak ada
   persetujuan; menyimpan
   langsung menulis ulang **Rating** vendor (rata-rata berjalan, satu desimal), dan
   rating itu tampil di daftar vendor. → PANDUAN §5.8.

9. **Sebelum membeli — empat layar yang hanya Anda baca.** `Engineering › Persetujuan
   Material (SMS)`: material yang belum berstempel **Disetujui** penuh belum boleh dibeli
   untuk IPP (§16.4). `Estimasi › BOQ / RAB`: harga beku baris BOQ adalah plafon yang
   membunyikan dialog harga di PO Anda; `Estimasi › RAP`: proyek tanpa RAP Disetujui
   **tidak punya gerbang anggaran sama sekali** (§4.7). `Persediaan › Saldo Stok`, tab
   **Di bawah minimum**: daftar yang dibaca — tidak ada PR otomatis darinya (§6.2). Pola
   belanja proyek disusun lebih dulu di `Pengadaan › Rencana Pengadaan` → **`Tambah
   Rencana Pengadaan`** (`PBL/2026/0001`) — register perencanaan, tidak menggerakkan uang
   dan tidak dicetak (§5.13).

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §14.2): `Setujui`/`Tolak` pada PR,
  PO, keputusan pemenang, dan PPK; `Tambah GRN` dan `Posting ke Stok`. Penyetujunya
  direktur; penerima barangnya gudang.
- **Gerbang prakualifikasi saat `Ajukan` PO** (§5.6): *"Vendor {kode} ({nama}) belum lolos
  prakualifikasi: vendor berstatus nonaktif; dokumen wajib {nama} kedaluwarsa sejak
  dd-mm-yyyy. Sertakan alasan override (qualification_override_reason) bila tetap harus
  diajukan."* Mengetik alasan meloloskannya — dan alasan yang diketik untuk vendor sehat
  **dibuang dengan sengaja**; "catatan saya hilang" memang perilakunya.
- **Harga di atas BOQ** (§4.7), dialog *"Harga di atas harga BOQ — tetap ajukan?"*: *"Baris
  3 "Semen PCC 50 kg": harga PO Rp 78.000 di atas harga BOQ beku Rp 68.000 (+14,71%,
  ambang 10%). Ajukan ulang dengan konfirmasi bila harga ini memang hasil negosiasi
  terbaik."* Hanya penyimpangan ke atas yang diperingatkan; baris BOQ tanpa harga tidak
  pernah dibandingkan. Dan **`Ubah` pada PO diam-diam mematikan gerbang ini** — baris
  ditulis ulang tanpa tautan BOQ; harga berubah, buat PO baru dari PR-nya.
- **Melampaui sisa anggaran RAP** (§4.7), dialog dengan tombol `Ya, tetap ajukan` yang
  menyebut anggaran, realisasi, komitmen, dokumen ini, dan besar pelampauannya. Keduanya
  peringatan, bukan tembok — jejaknya tercatat atas nama Anda.
- **Ambang direktur** — yang ditolak adalah penyetuju bukan-direktur, tetapi Anda yang akan
  ditanyai (§5.6): *"Pesanan Pembelian {kode} senilai Rp … mencapai ambang persetujuan
  direktur Rp 100.000.000; dokumen ini hanya dapat disetujui oleh pemegang izin
  prc.approve-director — pada instalasi standar peran direktur. …"* Aturan yang tercap
  saat pengajuan yang berlaku, walau ambangnya berubah sesudahnya.
- **RFQ** (§5.5): `"RFQ {kode} sudah menjadi dasar harga sebuah PO; barisnya tidak dapat
  diubah lagi — perubahan barang berarti lembar banding baru."` · `"Vendor belum menawar
  baris 2, 5 pada RFQ {kode}; lengkapi harganya dulu atau pilih pemenang per baris."` ·
  *"Pilih pemenang dulu sebelum membuat PO."* · *"Isi minimal satu skor aspek
  (mutu/waktu/keuangan/K3) untuk satu vendor."* · `"RFQ {kode} sudah melahirkan PO dan
  tidak dapat dihapus."` Dan **`Buat PO dari RFQ` tidak membawa kotak override
  prakualifikasi** — vendor pemenang yang terblokir gagal tanpa jalan lewat di layar itu;
  buat PO-nya dari `Tambah PO`, atau perbaiki dokumen vendornya.
- **Keputusan pemenang** (§5.12): *"Nilai keputusan melampaui RAB; alasan deviasi
  (deviation_reason) wajib diisi karena memutuskan di atas nilai wajar harus dapat
  dipertanggungjawabkan."* · *"Nilai keputusan (Rp {nilai}) berbeda dari penawaran terakhir
  vendor (Rp {nilai}), sehingga keputusan pemenang ini WAJIB didasari Berita Acara
  Negosiasi (BAN) untuk RFQ {kode}; belum ada BAN untuk vendor ini — buat BAN-nya dulu."* ·
  *"Vendor #{id} tidak diundang pada RFQ {kode}; keputusan pemenang hanya untuk vendor
  yang diajak banding."* — dan pada PO/SPK dari RFQ yang disetujui terlalu dini:
  *"{Dokumen} {kode} berasal dari RFQ {kode} namun keputusan pemenang (award) untuk vendor
  ini belum ada atau belum disetujui; terbitkan dan setujui keputusan pemenang dulu
  sebelum menyetujui {Dokumen}."*
- **PR dan PO yang sudah bergerak** (§5.4, §5.6): `"PR {code} already has purchase orders
  and cannot be deleted."` · `"PR {code} is {status} and can no longer be edited."` ·
  *"Vendor PO {kode} sudah dihapus; pilih vendor lain sebelum mengajukan."* · GRN atas PO
  yang sudah tertutup: `"…which is closed; only an approved purchase order can receive
  goods"` — PO menutup dirinya sendiri saat diterima penuh; kiriman pengganti dibukukan
  gudang atas nama vendor tanpa nomor PO.
- **PPK dan tagihan periodenya** (§5.14, §5.15): *"Vendor {kode} ({nama}) bertipe {jenis};
  PPK alat & jasa hanya untuk vendor rental atau pemasok jasa. Mandor memakai SP3,
  subkontraktor memakai SPK."* · *"Baris tarif per_jam harus menunjuk alat yang terdaftar
  di register aset — jam tagihannya dibaca dari hour-meter alat itu, bukan diketik."* ·
  *"Setiap baris PPK memerlukan tarif lebih dari nol."* · *"Periode {a} s.d. {b}
  tumpang-tindih dengan tagihan {kode} ({c} s.d. {d}) pada PPK {kode} — satu periode hanya
  ditagih sekali."* · *"Kuantitas {x} {satuan} pada baris "{uraian}" melebihi sisa plafon
  PPK {y} {satuan} (plafon {p}, sudah tertagih {q})."* · *"Baris per_bulan "{uraian}"
  menagih bulan kalender utuh; …"* · tagihan kosong tidak dibuat bila per jam belum punya
  dua pembacaan hour-meter di dalam periode.
- **Vendor** (§5.2): `"Vendor {code} has purchase orders and cannot be deleted; set it
  inactive instead."` — nonaktifkan, jangan hapus. Dan tagihan vendor non-PKP yang memungut
  PPN ditolak di Keuangan: *"Vendor {nama} bukan PKP sehingga tidak dapat menerbitkan
  faktur pajak; tagihan ini tidak boleh memungut PPN."* — centang PKP di master Anda yang
  menentukannya.
- **Yang tidak bisa dibatalkan** (§14.4): `Tutup RFQ` (harga dan pemenang membeku) ·
  `Tutup PO` (memaafkan sisa kiriman secara permanen — bukan untuk kiriman yang tidak
  pernah datang; menutup PO tanpa penerimaan **tidak** membuat tagihannya bisa disetujui,
  §5.9) · `Ajukan` (Ubah dan Hapus hilang; jalan kembali hanya `Tolak` oleh direktur).

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **23** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Pengadaan — Permintaan Pembelian (F/PP) · Pesanan Pembelian (Formulir Rumah) (F/PO —
  **berbeda dari tombol `PDF`** PO, yang mencetak pesanan komersial untuk pemasok)
- Pengadaan — Tabulasi Banding Penawaran (F/TBP, mendatar; memuat penilaian berbobot
  begitu ada vendor yang dinilai) · Berita Acara Negosiasi (F/BAN, mendatar)
- Pengadaan — Keputusan Pemenang (F/AWD) · Evaluasi Vendor (F/EV)
- Pengadaan — Persyaratan K3L Vendor (F/K3V) · CV Mandor (F/CVM) — keduanya di halaman
  Vendor · PPK Alat & Jasa (F/PPK — lembar plafon, tanpa tabel realisasi)
- Estimasi — RAB / BOQ (F/RAB) · AHSP (F/AHSP) · RAP (F/RAP)
- Engineering — Persetujuan Gambar (SDS) (F/SD) · Persetujuan Material (SMS) (F/SM) ·
  Transmittal (F/TR) · Ijin Pelaksanaan (IPP) (F/IPP)
- Persediaan — Bukti Penerimaan Barang (F/BPB) · Bon Pengeluaran Barang (F/BM) · Surat
  Jalan Antar Gudang (F/SJ) · Berita Acara Stock Opname (F/BAO) · Daftar Saldo Stok
  (F/SS, dari halaman Gudang) · Bukti Retur Pembelian (F/RPB) · Bukti Retur Material (F/RTM)

Permintaan Pembelian dan Pesanan Pembelian juga punya tombol **`XLSX`** (§13.2a). Rencana
Pengadaan dan Rekap Tagihan Alat sengaja tidak punya lembar cetak. **Aturan kejujuran
(§13.5): sel yang bergaris kosong berarti "tidak tercatat", tidak pernah berarti nol** —
vendor yang tidak dinilai pada tabulasi bergaris, bukan nol; peserta negosiasi ada di
lampiran, bukan di tabel; delapan baris CV Mandor diisi tangan.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `procurement` di
      dialog Akun.
- [ ] Saya sudah membuat satu vendor dan satu baris Dokumen Vendor bercentang **Wajib
      untuk PO/SPK**, dan tahu bahwa centang itulah gerbang prakualifikasi.
- [ ] Saya sudah mengajukan satu PR (`PR/…`) dengan *Dibutuhkan tanggal* terisi, dan tahu
      nama direktur yang menyetujuinya.
- [ ] Saya sudah melihat tombol `Buat PO` muncul pada PR yang Disetujui, dan PO draf yang
      lahir darinya membawa catatan *"Dibuat dari {kode PR}"*.
- [ ] Saya sudah membuat satu RFQ, mengetik harga dua vendor, menekan `Simpan harga`, dan
      memilih pemenang — **tanpa** menekan `Ubah` sesudahnya.
- [ ] Saya sudah membaca dialog `Ajukan` PO (*Alasan override prakualifikasi*) dan tahu
      alasan untuk vendor sehat dibuang.
- [ ] Saya sudah melihat dialog "Harga di atas harga BOQ — tetap ajukan?" sekali, atau
      tahu mengapa PO saya tidak memicunya (baris tanpa tautan BOQ, atau RAP masih Draf).
- [ ] Saya sudah membuka `Baris PO Terbuka` dan tahu PO mana yang kolom Perkiraan
      kirim-nya kosong — PO itu tidak pernah dihitung telat.
- [ ] Saya sudah membuka `Engineering › Persetujuan Material (SMS)` dan tahu mana material
      yang berstempel **Disetujui** penuh.
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan tahu tiga tenggat mana yang milik
      peran saya.
- [ ] Saya sudah mencetak satu Permintaan Pembelian (F/PP) dan satu Tabulasi Banding
      Penawaran (F/TBP), dan tahu sel mana yang bergaris.
- [ ] Saya tahu bahwa PO Rp 100 juta ke atas hanya bisa disetujui direktur, dan award
      Rp 100 juta ke atas butuh dua penyetuju berbeda.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **PR, PO, keputusan pemenang, PPK menunggu `Setujui`; PO Rp 100 juta ke atas; award
  tingkat kedua/ketiga** → direktur (§5.6, §5.12).
- **Barang sudah datang tetapi belum ada GRN; kolom Diterima PO tetap 0; PO tertutup
  padahal kiriman pengganti akan datang** → gudang (`warehouse`) untuk drafnya, admin
  atau teknisi untuk `Posting ke Stok` (§6.1, §6.4).
- **Tagihan vendor, uang muka, nota kredit, pembayaran** → petugas keuangan (`finance`),
  manajer keuangan untuk persetujuannya (§5.9, §5.10).
- **Harga BOQ yang membunyikan gerbang, RAP yang masih Draf** → estimator (§4.3, §4.4).
- **Material yang belum berstempel MK** → manajer proyek, yang mencatat stempelnya
  (§16.4).
- **Kebutuhan lapangan yang berubah, paket pekerjaan, IPP** → manajer proyek atau site
  manager (§7.2, §16.5).
- **Pengaturan ambang direktur, bobot penilaian RFQ, mematikan pemisahan tugas** →
  administrator — keputusan kebijakan, bukan kemudahan (§14.1; PANDUAN-ADMINISTRATOR
  §4.6).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang menampilkan PR/PO Anda
> yang ditolak dan yang menunggu dalam satu permintaan; draf formulir (termasuk lembar
> banding) yang bertahan di peramban saat sesi habis; catatan persetujuan inline tanpa
> dialog; dan ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini menggambarkan
> yang berlaku.
