# Onboarding minggu pertama — Penjaga Gudang (`warehouse`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `warehouse` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 6**, ditambah §16.5 (bon
pengeluaran bisa menunjuk IPP dan mewarisi paket pekerjaannya).

---

## 1. Siapa Anda di sistem

- **Peran akun:** `warehouse`. **Akun demo:** `warehouse@nusantara.test` (Hendra Gunawan)
  — pakai akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** menyiapkan **draf** setiap dokumen stok sampai
  benar — penerimaan barang, bon pengeluaran, transfer antar gudang, opname, kedua retur —
  dan merawat master item serta gudang; sementara **memposting** bukan pekerjaan Anda
  (peran ini tidak memegang `inv.post`) dan **menyetujui opname** bukan pekerjaan Anda
  (peran ini tidak memegang `inv.approve`). Layar Anda tidak rusak: tombol posting memang
  tidak digambar untuk Anda (§6.1).
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1): Anda berdiri di
  **tengah rantai permintaan → pembayaran** (§1.2): PO disetujui → **GRN Anda** (posting:
  stok + HPP) → tagihan vendor dari GRN → pembayaran. Pencocokan tiga arah keuangan hanya
  bisa menagih kuantitas yang **Anda** catat diterima. Dan Anda berdiri di **awal rantai
  lapangan → progres → tagihan** (§1.4): bon Anda adalah satu-satunya dokumen stok yang
  mendarat sebagai biaya proyek, dan GRN terposting Anda ditarik site manager ke laporan
  hariannya lewat `Impor dari GRN` (§7.3).
- **Yang menyerahkan pekerjaan kepada Anda:** `procurement` (PO Disetujui — hanya PO
  Disetujui yang bisa menerima barang), `site-manager` dan `project-manager` (permintaan
  material ke lapangan, IPP yang menjadi dasar bon, paket pekerjaan WBS), `direktur`/admin
  (opname Anda kembali sebagai Disetujui atau Ditolak).
- **Yang menerima pekerjaan dari Anda:** **admin atau teknisi** — `Posting ke Stok` pada
  GRN dan bon, `Kirim`/`Terima` transfer, `Posting Retur` (§6.1); **direktur atau admin**
  — `Setujui` opname (yang sekaligus memposting); `finance` — tagihan vendor atas GRN
  yang sudah diposting (§5.9); `site-manager` — laporan harian yang mengimpor GRN Anda.

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2).

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Persediaan** — kedelapan layarnya milik Anda: Saldo Stok · Item · Kategori Item ·
  Gudang · Penerimaan (GRN) · Pengeluaran · Transfer · Opname. Kedua retur **tidak punya
  baris di sidebar** — pintunya tombol `Buat Retur` pada GRN atau bon yang sudah diposting
  (§6.8).
- **Proyek** — Daftar Proyek · Laporan Harian · Izin Material (IMK) — **baca saja**: 20
  layar proyek tampil untuk Anda tanpa tombol Tambah (§7.1). Yang berguna: kode proyek dan
  status proyek sebelum mengetik bon, dan izin masuk/keluar material yang sudah Disetujui.
- **Engineering** — satu baris saja: Lokasi Tapak (baca saja; barisnya bergerbang izin
  proyek, itu sebabnya kelompok Engineering tampil hanya berisi satu baris — §1.4).
- **Aset** — satu baris saja: Log BBM & Jam Alat (baca saja; sebab yang sama — §1.4, §9.5).
- **Sistem** — dua baris saja: **Impor Data Master** (tabel Item / Material, §6.3) dan
  **Impor Dokumen** (Kartu Stok (warisan) → satu opname draf per gudang, §2.9). Kedua
  baris punya izinnya sendiri (§1.4).

Kelompok Penjualan, Estimasi, Mutu, Pengadaan, Subkontrak, Keuangan, SDM, dan Layanan
tidak ada di sidebar Anda. PO Anda baca dari kolom **PO** di daftar GRN dan dari pemilih
**PO terkait** di formulir — layar Pesanan (PO) sendiri tidak ada untuk Anda.

**Dasbor Anda** (PANDUAN §1.7):

- Ubin **Proyek berjalan** (jumlah dan nilai kontrak). Tombol **`Proyek saya`** hanya
  digambar bila akun Anda ditautkan ke data karyawan; pada akun demo `warehouse` ia tidak
  ada.
- Kartu **Menunggu persetujuan Anda** — **selalu berbunyi** *"Tidak ada dokumen yang
  menunggu persetujuan."* untuk Anda. Kabar opname Anda disetujui atau ditolak datang
  lewat **lonceng** (lencana Disetujui hijau / Ditolak merah) dan lewat lencana status di
  daftar Opname; GRN dan bon Anda yang sudah diposting orang lain terbaca dari lencana
  **Diposting** di daftarnya.
- Kartu **Kalender Acara** (selalu digambar), **Progres proyek** (bila ada proyek
  berjalan), dan **Stok di bawah minimum** — kartu yang paling berguna bagi Anda: item ·
  gudang · stok / minimum, digambar hanya bila ada isinya.

**Lonceng dan Tenggat** — **tidak satu pun tenggat harian ditujukan kepada peran Anda**
(PANDUAN §1.7): sembilan belas tenggat itu bergerbang izin penjualan, keuangan,
pengadaan, proyek, aset, dan SDM. Layar `Ringkasan › Tenggat` akan kosong untuk Anda, dan
itu benar. Alarm stok Anda adalah tab **Di bawah minimum** di Saldo Stok dan kartu dasbor
di atas — keduanya daftar yang dibaca, tanpa PR otomatis (§6.10).

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu; begitu pula tombol
   `Posting ke Stok`.
2. `Ubah` dan `Hapus` menghilang begitu GRN, bon, transfer, dan retur keluar dari Draf —
   pada dokumen stok, **posting** itulah titik tanpa jalan kembali untuk penyuntingan.
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — hanya `Batalkan Penerimaan` /
   `Batalkan Bon` oleh pemegang izin posting, dan transfer serta opname tidak punya itu.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; GRN puluhan baris disimpan dulu, lalu dibuka lagi lewat `Ubah`.

## 3. Pekerjaan Anda

Delapan walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang memposting /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Master: item, kategori, gudang.** Pemicu: barang atau gudang baru. Layar:
   `Persediaan › Item` → **`Tambah Item`** → **Nama item**, Kode (kosong = `ITM-xxxx`),
   **Kategori**, **Jenis item**, **Satuan**, Stok minimum → **`Simpan`**. **Jenis item
   menentukan pos biaya proyek yang dibebani setiap bon selamanya**: Alat Bantu → Alat;
   Material, Sparepart, Barang Dagangan → Material. HPP rata-rata tidak pernah diketik.
   `Persediaan › Kategori Item` (tanpa halaman detail; ubah/hapus dari ikon baris) dan
   `Persediaan › Gudang` → **Kode** (wajib, tanpa penomoran otomatis), **Nama gudang**,
   **Proyek (gudang site)** bila gudang berada di lokasi proyek. Item massal: `Sistem ›
   Impor Data Master` → **Item / Material** (`kode`, `nama`, `satuan`, `kategori_kode`
   wajib; kategori harus sudah ada). Gudang dan kategori tidak punya pengimpor. → PANDUAN
   §6.3, §2.9.

2. **Penerimaan barang atas PO.** Pemicu: truk datang membawa surat jalan. Layar:
   `Persediaan › Penerimaan (GRN)` → **`Tambah GRN`** → **Gudang penerima**, **Tanggal
   terima**, **PO terkait**, Vendor, **No. surat jalan** → **tekan `Salin baris dari PO`**
   (mengisi satu baris per baris PO dengan **sisa** kuantitas pada harga PO) → sesuaikan
   Qty sesuai yang benar-benar datang → **`Simpan`**. Nomor: `GRN/2026/IX/0001`. Lalu
   minta pemegang `inv.post` menekan **`Posting ke Stok`**. Hanya baris hasil salin yang
   **tertaut** ke baris PO-nya — halaman GRN memperlihatkannya per baris pada kolom
   **Baris PO**: lencana **Tertaut** atau kuning **Lepas**. Baris yang diketik tangan
   membiarkan kolom Diterima PO tetap 0, PO tidak pernah menutup, dan tagihan final PO
   ditolak. Stok awal atau barang tanpa PO: GRN tanpa PO dan tanpa vendor — dan GRN itu
   tidak akan pernah bisa diretur. → PANDUAN §6.4.

3. **Bon pengeluaran ke lapangan.** Pemicu: material diminta ke proyek. Layar:
   `Persediaan › Pengeluaran` → **`Tambah Pengeluaran Barang`** → **Gudang asal**,
   **Tanggal keluar**, **Proyek tujuan**, **IPP (Ijin Pelaksanaan)**, **Paket pekerjaan
   (WBS)**, **Keperluan** → baris **Item dikeluarkan**: Item · **Paket WBS** (per baris,
   mengalahkan kepala) · **Qty** — tidak ada kolom harga; nilainya HPP rata-rata gudang
   saat posting → **`Simpan`**. Nomor: `ISS/2026/IX/0001`. Posting oleh pemegang
   `inv.post`. Dua kolom menentukan ke mana biayanya: **Proyek tujuan kosong = overhead
   kantor**, tidak pernah masuk realisasi proyek; **Paket WBS kosong = tidak masuk laporan
   Varian Material**. Bon yang menunjuk IPP Disetujui **mewarisi** paket pekerjaan IPP
   itu; proyek yang punya IPP aktif menahan bon tanpa IPP sekali dengan peringatan
   konfirmasi — pilih IPP-nya, atau kirim ulang untuk menegaskan bon ini di luar IPP.
   → PANDUAN §6.5, §16.5.

4. **Transfer antar gudang.** Pemicu: barang pindah gudang. Layar: `Persediaan ›
   Transfer` → **`Tambah Transfer`** → **Gudang asal**, **Gudang tujuan** (harus berbeda),
   **Tanggal transfer** → baris Item · Qty (tanpa harga) → **`Simpan`**. Nomor:
   `TRF/2026/IX/0001`. **`Kirim`** dan **`Terima`** milik pemegang `inv.post`. Periksa
   gudang tujuan **sebelum** meminta `Kirim`: transfer dalam perjalanan tidak bisa diubah,
   dihapus, maupun dibatalkan — satu-satunya jalan kembali adalah transfer kedua ke arah
   sebaliknya. Selama di jalan, barangnya tidak ada di gudang mana pun; nilainya duduk di
   kotak **Dalam perjalanan** Saldo Stok, yang hanya tampil tanpa saringan gudang.
   → PANDUAN §6.6.

5. **Opname — susut, rusak, hilang.** Pemicu: hitung fisik. Layar: `Persediaan › Opname`
   → **`Tambah Penyesuaian Stok`** → **Gudang**, **Tanggal opname**, **Alasan** (Stock
   Opname / Barang Rusak / Barang Hilang) → **Hasil hitung fisik**: Item · **Qty
   terhitung** — Anda memasukkan hasil hitungan, **tidak pernah selisihnya** →
   **`Simpan`** → **`Ajukan`**. Nomor: `ADJ/2026/IX/0001`. Penyetuju: **direktur atau
   admin**, dan **`Setujui` memposting seketika**: selisihnya menjadi beban operasional.
   Qty sistem dan Selisih dibekukan saat lembar **disimpan** — bila ada bon keluar antara
   simpan dan setujui, selisih lama diterapkan pada saldo baru. Selesaikan persetujuannya
   hari itu juga, atau buka dan simpan ulang lembarnya dulu. Opname adalah dokumen yang
   **salah** untuk material kembali dari lapangan dan untuk stok awal. → PANDUAN §6.7.

6. **Retur — ke vendor, dan dari lapangan.** Pemicu: barang ditolak/berlebih kembali ke
   vendor, atau sisa material kembali dari proyek. Pintunya tombol **`Buat Retur`** pada
   GRN Diposting (→ draf **Retur Pembelian**, `RPB/…`, baris lewat **`Salin baris dari
   GRN`**) atau pada bon Diposting (→ draf **Retur Material Proyek**, `RTM/…`, baris lewat
   **`Salin baris dari bon`**), keduanya dengan **Alasan retur** minimal 5 karakter.
   **`Buat Retur` hanya membuat DRAF** — tidak ada yang bergerak sampai pemegang
   `inv.post` menekan **`Posting Retur`**. Draf retur yang terlantar tampak seperti retur
   selesai di kertas rak sementara rekening vendor dan biaya proyek tidak tahu apa-apa.
   Aturan waktunya: kembalikan barang **sebelum** tagihan vendornya disetujui — sesudah
   itu urusannya nota kredit di Keuangan. → PANDUAN §6.8, §6.9.

7. **Membaca stok: saldo, kartu, di bawah minimum.** Layar: `Persediaan › Saldo Stok` —
   tab **`Saldo per gudang`** (Item · Gudang · Qty · HPP rata-rata · Nilai; kotak **Nilai
   persediaan**, dan **Dalam perjalanan** + **Total dimiliki** hanya tanpa saringan), tab
   **`Kartu stok (ledger)`** (200 baris pertama, **paling lama di atas**, tanpa saringan
   tanggal — persempit dengan dropdown gudang; kolom Referensi menyebut nama teknis
   `GoodsReceipt`/`Issue`/`Transfer`, bukan nomor dokumen), tab **`Di bawah minimum`**
   (hanya item yang sudah punya baris saldo di gudang itu; stok minimum diterapkan **per
   gudang**). HPP rata-rata **per gudang**; item yang sama boleh berbeda HPP di dua
   gudang. Tidak ada riwayat saldo — Daftar Saldo Stok tercetak selalu bertanggal hari
   mencetak. → PANDUAN §6.2, §6.10.

8. **Membaca proyek sebelum mengetik bon.** Layar: `Proyek › Daftar Proyek` (kode dan
   status proyek — bon ke proyek yang salah harus dihapus dan diketik ulang, karena
   mengubah *Proyek tujuan* pada draf bon diam-diam tidak berpengaruh), `Proyek › Izin
   Material (IMK)` (izin masuk/keluar material yang sudah Disetujui; tombol **`Periksa di
   gerbang`** bukan milik Anda — izin ubah proyek), dan `Proyek › Laporan Harian` (GRN
   terposting Anda pada tanggal laporan ditawarkan site manager lewat `Impor dari GRN`
   — GRN yang masih draf tidak pernah ditawarkan). → PANDUAN §7.1, §7.13, §7.3.

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §6.1, §14.2): `Posting ke Stok`,
  `Kirim`, `Terima`, `Posting Retur`, `Batalkan Penerimaan`, `Batalkan Bon` (admin atau
  teknisi), dan `Setujui` opname (direktur atau admin). Permintaan yang benar berbunyi
  *"tolong posting dokumen ini"*, bukan *"tolong beri saya izin"*.
- **Harga Rp 0 pada baris tertaut PO** (§6.4) — berbunyi saat **menyimpan**, dialog
  *"Harga satuan Rp 0 — lanjutkan?"*, tombol `Ya, barang gratis`: *""Semen PCC 50 kg"
  diterima dengan harga satuan Rp 0 padahal tertaut baris PO — stok masuk bernilai nol
  dan HPP rata-rata gudang ikut turun. Lanjutkan hanya bila memang barang gratis
  (free-issue/bonus)."* Baca nama itemnya sebelum mengonfirmasi: HPP gudang yang turun
  karena harga terhapus tak sengaja turun **permanen**, tanpa layar yang menandainya.
- **Salin baris dari PO** (§6.4): tombol itu **mengganti** setiap baris yang sudah ada,
  dan menyalin **sisa** kuantitas, bukan kuantitas pesanan. Tanpa PO terpilih: *"Pilih PO
  terkait dulu di bagian atas formulir."* Bila sudah habis: *"Seluruh baris PO ini sudah
  diterima penuh."*
- **Posting GRN yang ditolak** — yang akan dibacakan pemosting kepada Anda (§6.4): `"GRN
  {kode} references PO {kode}, which is closed; only an approved purchase order can
  receive goods. Record the delivery against the vendor without the purchase order so it
  can be billed on the receipt."` · *"GRN {kode} membuat total penerimaan Semen PCC 50 kg
  atas PO {kode} menjadi 160,000, melebihi 100,000 yang dipesan. Baris ini tidak tertaut
  ke baris PO, sehingga batas kuantitas PO tidak diperiksa lewat jalur biasa. Gunakan
  'Salin baris dari PO' untuk barang yang memang dipesan, atau perbaiki kuantitasnya."* ·
  `"Receipt of 30 exceeds remaining quantity 20 on PO {kode} line 2."`
- **Stok tidak cukup** (§6.5): *"Stok tidak mencukupi: {item} di {gudang} (tersedia {n},
  diminta {m})."* — juga pada transfer dan pada opname bernilai negatif.
- **Tanggal mundur** (§6.10): *"Dokumen {kode} bertanggal {tanggal}, lebih awal dari mutasi
  terakhir {tanggal} untuk {item} di {gudang}. Harga rata-rata dihitung maju menurut
  urutan pencatatan, … Ubah tanggalnya menjadi {tanggal} atau sesudahnya, atau catat
  selisihnya lewat opname."* Berlaku pada GRN, bon, pengiriman transfer, kedua retur, dan
  opname; hanya `Terima` transfer yang jatuh maju alih-alih menolak.
- **Periode fiskal tertutup** (§6.10): *"Periode fiskal 2026-03 sudah ditutup; jurnal tidak
  dapat diposting ke dalamnya."* — memblokir **setiap** mutasi stok, termasuk transfer dan
  opname bernilai nol. Penerimaan yang dimundurkan ke bulan lalu menemuinya, dan ia bukan
  tentang stok; tutup buku milik administrator.
- **Pemilih WBS dan IPP pada bon** (§6.5): *"Tugas WBS yang dipilih bukan bagian dari WBS
  proyek ini."* · *"Tugas WBS yang dipilih masih punya sub-tugas; pilih paket pekerjaan
  paling bawah."* · *"IPP {kode} masih berstatus {status}; hanya IPP yang disetujui yang
  dapat menjadi dasar pengeluaran material."* · *"IPP {kode} milik proyek lain dan tidak
  dapat menjadi dasar bon proyek ini."* · bila WBS bon bentrok dengan WBS IPP-nya:
  kosongkan tugas WBS agar diwarisi dari IPP. Pemilih WBS memuat tugas daun **seluruh
  proyek**, dibedakan sub-label seperti `PRJ-2026-001 · B.3` — perhatikan.
- **Retur** (§6.8): `"Penerimaan {kode} tidak menyebut PO maupun vendor (stok awal); tidak
  ada pihak yang bisa menerima retur. Keluarkan lewat opname bila barangnya memang harus
  keluar."` · *"Retur {kode} senilai {Rp} melebihi sisa penerimaan {kode} yang belum
  ditagih ({Rp}). Bagian yang sudah disapu tagihan vendor adalah hutang yang telah
  disetujui — mintakan nota kredit vendor dan bukukan lewat Keuangan, bukan lewat dokumen
  stok."* · *"Alasan retur terlalu singkat; jelaskan mengapa material ini kembali."*
- **Master** (§6.3): *"Item masih memiliki stok dan tidak dapat dihapus."* · *"Item ini
  sedang dalam perjalanan antar gudang dan tidak dapat dihapus. Terima dulu transfernya di
  gudang tujuan, baru hapus itemnya."* · *"Gudang masih memiliki stok dan tidak dapat
  dihapus."* · *"Kategori masih dipakai oleh item atau sub-kategori."*
- **Dokumen yang sudah bergerak**: `"GRN {kode} is {status}; only draft GRNs can be
  posted."` · `"Transfer {kode} is {status} and can no longer be modified."` ·
  `"Adjustment {kode} is {status} and can no longer be modified."` — opname yang Ditolak
  tetap bisa diubah (§2.6).
- **Yang tidak bisa dibatalkan** (§14.4): `Kirim` transfer · `Setujui` opname (sekaligus
  memposting; koreksinya opname berikutnya) · `Posting Retur` (tidak ada pembatalan sama
  sekali) · draf bon salah proyek (hapus, ketik ulang) · hapus apa pun dari daftar.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **18** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Persediaan — Bukti Penerimaan Barang (F/BPB, halaman GRN) · Bon Pengeluaran Barang
  (F/BM, halaman Pengeluaran)
- Persediaan — Surat Jalan Antar Gudang (F/SJ) · Berita Acara Stock Opname (F/BAO,
  mendatar)
- Persediaan — Daftar Saldo Stok (F/SS, mendatar, dari **halaman Gudang** — selalu
  bertanggal hari mencetak)
- Persediaan — Bukti Retur Pembelian (F/RPB) · Bukti Retur Material (F/RTM)
- Proyek — Data Proyek (F/DP) · Laporan Harian (F/LH) · Detail Schedule / Program Kerja
  (F/DS, ikon printer pada baris Progres Mingguan) · Daftar Temuan / Defect List (F/DT)
- Proyek — Izin Kerja Lapangan (F/IK) · Izin Kerja Lembur (F/IL) · Izin Masuk / Keluar
  Material & Peralatan (F/IM)
- Proyek — Opname ke Pemilik (OPN) (F/OPN) · BAPP per Zona (F/BAPP) · Formulir K3 Harian
  (F/K3H) · IBPRP (F/IBPRP)

Yang sehari-hari milik Anda adalah tujuh lembar Persediaan; sebelas lembar Proyek tampil
karena Anda memegang izin lihat proyek. Empat di antaranya juga punya tombol **`XLSX`**:
Daftar Saldo Stok, Bon Pengeluaran Barang, Bukti Penerimaan Barang, Berita Acara Stock
Opname (§13.2a). **Aturan kejujuran (§13.5): sel yang bergaris kosong berarti "tidak
tercatat", tidak pernah berarti nol** — dan di XLSX sel itu **kosong**, bukan 0; jangan
mengisinya dengan nol saat mengolah lanjut.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `warehouse` di dialog
      Akun.
- [ ] Saya sudah membuka satu GRN dan tahu bahwa tombol `Posting ke Stok` **tidak ada** di
      layar saya — dan tahu nama admin atau teknisi yang memegangnya.
- [ ] Saya sudah membuat satu GRN draf (`GRN/…`) lewat `Salin baris dari PO` dan melihat
      lencana **Tertaut** pada kolom Baris PO.
- [ ] Saya sudah membaca dialog "Harga satuan Rp 0 — lanjutkan?" sekali dan tahu kapan
      boleh menekan `Ya, barang gratis`.
- [ ] Saya sudah membuat satu bon draf (`ISS/…`) dengan **Proyek tujuan** dan **Paket
      pekerjaan (WBS)** terisi, dan tahu akibat mengosongkan masing-masing.
- [ ] Saya sudah membuat satu transfer draf (`TRF/…`) dan memeriksa gudang tujuannya
      sebelum meminta `Kirim`.
- [ ] Saya sudah mengajukan satu opname (`ADJ/…`) dan tahu nama direktur/admin yang
      menyetujuinya — dan bahwa `Setujui` itu sekaligus posting.
- [ ] Saya sudah menemukan tombol `Buat Retur` pada GRN Diposting dan tahu drafnya belum
      menggerakkan apa pun.
- [ ] Saya sudah membuka tab **Di bawah minimum** dan tab **Kartu stok (ledger)**, dan tahu
      mutasi terbaru bisa tidak terlihat pada item yang sibuk.
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan tahu bahwa kosongnya layar itu benar
      untuk peran saya.
- [ ] Saya sudah mencetak satu Bukti Penerimaan Barang (F/BPB) dan satu Daftar Saldo Stok
      (F/SS) dari halaman Gudang.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **Draf GRN/bon/transfer/retur menunggu `Posting ke Stok`, `Kirim`, `Terima`, `Posting
  Retur`; GRN atau bon yang salah dan harus dibatalkan** → admin atau teknisi, pemegang
  izin posting stok (§6.1, §14.2).
- **Opname menunggu `Setujui`** → direktur atau admin (§6.7).
- **PO yang tertutup padahal kiriman pengganti akan datang, PO tanpa Perkiraan kirim,
  vendor dan harga PO** → petugas pengadaan (§5.6, §5.7).
- **Proyek tujuan, paket pekerjaan WBS, IPP yang mendasari bon, izin masuk material** →
  site manager atau manajer proyek (§7.2, §7.13, §16.5).
- **Barang sudah ditagihkan lalu harus kembali (nota kredit), tagihan atas GRN tanpa PO**
  → petugas keuangan (§5.9, §6.4).
- **Periode fiskal tertutup yang menolak tanggal Anda** → administrator, yang memegang
  tutup buku (PANDUAN-ADMINISTRATOR §6).
- **Bon yang lahir dari berita acara servis** (tidak bisa dibatalkan) → teknisi; koreksinya
  opname (§12.5).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan — dan untuk peran Anda, `Posting ke Stok` yang tidak
ada adalah keadaan normal.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang menampilkan opname Anda
> yang ditolak dan yang menunggu dalam satu permintaan; draf formulir (termasuk GRN dan
> bon) yang bertahan di peramban saat sesi habis; catatan persetujuan inline tanpa
> dialog; dan ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini menggambarkan
> yang berlaku.
