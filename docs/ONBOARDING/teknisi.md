# Onboarding minggu pertama — Teknisi Servis (`teknisi`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `teknisi` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 12**, ditambah layar Lapangan
lewat alamat `#/lapangan` (§7.4) dan — karena sejak 22 Agustus 2026 peran Anda memegang
izin posting stok — §6.1 dan tombol posting di bab 6.

---

## 1. Siapa Anda di sistem

- **Peran akun:** `teknisi`. **Akun demo:** `teknisi@nusantara.test` (Joko Susilo) —
  pakai akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** mengerjakan tiket layanan, menulis berita acara
  kunjungan, mengesahkannya dengan tanda tangan pelanggan (yang sekaligus mengeluarkan
  suku cadang dari gudang), dan — sejak 22 Agustus 2026 — menekan tombol posting stok
  untuk draf yang disiapkan gudang.
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1): layanan dan
  pemeliharaan berjalan **di samping** empat rantai proyek, bukan di dalamnya.
  Persinggungannya satu: **stok**. Pada rantai permintaan → pembayaran (§1.2), `Posting
  ke Stok` pada GRN adalah langkah yang menggerakkan stok dan HPP — dan tombol itu, pada
  susunan peran bawaan, hanya dipegang admin dan Anda (PANDUAN §6.1). Di produksi hari
  ini empat dari enam tiket ditugaskan tanpa penyelesaian dan dasbor berkata "melewati
  SLA" (ANALISIS §2) — tiket yang Anda selesaikan adalah angka itu.
- **Yang menyerahkan pekerjaan kepada Anda:** siapa pun yang membuat tiket (`Tugaskan`
  menunjuk Anda sebagai Teknisi), jadwal PM otomatis (tiket kategori Pemeliharaan
  Preventif setiap malam), `warehouse` (draf GRN, bon, transfer, retur yang menunggu
  posting).
- **Yang menerima pekerjaan dari Anda:** **pelanggan** (menandatangani berita acara —
  Anda yang mencatatnya dengan `Sahkan Pelanggan`), `admin` (menghapus tiket — Anda tidak
  memegang izin hapus layanan), `direktur`/`admin` (`Setujui` opname stok — izin setujui
  persediaan bukan milik Anda).

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2).

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
sidebar Anda pendek, dan itu normal. Layar yang akan Anda pakai minggu ini:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Persediaan** — Saldo Stok · Penerimaan (GRN) · Pengeluaran · Transfer · Opname.
- **Layanan** — Tiket · Tiket Lewat SLA · Berita Acara · Jadwal Preventif · Kontrak
  Layanan.

**Baris "Lapangan (mobile)" tidak ada di sidebar Anda** — ia tinggal di kelompok Proyek,
yang tidak digambar untuk peran `teknisi`. Layarnya sendiri terbuka untuk Anda: ketik
`#/lapangan` di belakang alamat aplikasi, simpan sebagai markah atau ikon layar utama di
ponsel. Anda hanya akan melihat tab **Tiket Servis** (§7.4).

**Dasbor Anda** (PANDUAN §1.7):

- Ubin **Tiket aktif**, dengan sub-baris *"N melewati SLA"* atau *"SLA aman"*.
- Kartu **Tiket layanan aktif** (judul, prioritas, lencana SLA *Terlampaui*) dan kartu
  **Stok di bawah minimum** — keduanya digambar hanya bila ada isinya; kartu **Kalender
  Acara** selalu digambar (agenda modul yang boleh Anda lihat — kunjungan PM tampil di
  sini).
- Kartu **Menunggu persetujuan Anda** — **selalu kosong untuk Anda**, karena Anda tidak
  menyetujui apa pun. Tiket dan berita acara tidak lewat `Setujui`; opname stok
  disetujui direktur/admin.

**Lonceng dan Tenggat:** **tidak ada satu pun tenggat yang ditujukan kepada peran Anda.**
Sembilan belas tenggat di layar Tenggat disaring menurut izin, dan tidak satu pun memakai
izin layanan atau posting stok — layar itu kosong untuk Anda. Kontrak layanan yang
mendekati akhir periode diperingatkan kepada `sales`, bukan kepada Anda. **Alarm SLA Anda
adalah layar `Layanan › Tiket Lewat SLA`** dan kolom **SLA selesai** yang memerah di
daftar Tiket (§12.3, §12.4). Buka keduanya setiap pagi.

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu dokumen keluar dari Draf; pada berita acara,
   jalan kembali adalah `Kembalikan ke Draf` — dan ia mati begitu pelanggan menandatangani.
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — hanya pembalikan, dan itu permanen.
   Bagi Anda kalimat ini nyata: `Sahkan Pelanggan` dan `Posting ke Stok` menggerakkan stok.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; berita acara yang panjang disimpan dulu, lalu dibuka lagi lewat `Ubah`.

## 3. Pekerjaan Anda

Delapan walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Mengerjakan tiket — catat aktivitas begitu Anda menyentuhnya.** Pemicu: tiket
   ditugaskan kepada Anda (lonceng, atau kolom Teknisi di `Layanan › Tiket`). Layar: buka
   tiketnya (`TKT-202609-0001`) → **`Tambah Aktivitas`** → Jenis **Catatan Pekerjaan**,
   Isi, Waktu (menit). Catatan Pekerjaan pertama mencap **waktu respons pertama** dan
   memindahkan status Ditugaskan → Dikerjakan; tanpa itu, `Selesaikan` yang mencapnya,
   dan SLA respons terbaca "tercapai" padahal tidak. Selesai: **`Selesaikan`** (Catatan
   penyelesaian, wajib) → **`Tutup Tiket`**. Tidak ada persetujuan. Tiket yang
   Terselesaikan tidak bisa dibuka kembali — masalah yang kembali adalah tiket baru.
   → PANDUAN §12.3.

2. **Membuat tiket sendiri.** Pemicu: laporan pelanggan lewat telepon/WhatsApp. Layar:
   `Layanan › Tiket` → **`Tambah Tiket`** → Kontrak layanan, Pelanggan, **ID lokasi**
   (kotak angka mentah — bacanya di tabel **Lokasi layanan** pada halaman Kontrak
   Layanan), Judul, Kategori, Prioritas, Kanal, Teknisi, Deskripsi → **`Simpan`**. SLA
   respons dan penyelesaian dihitung dari kontrak (jam kerja Senin–Jumat 08.00–17.00
   WIB; prioritas Kritis 24/7). **Tiket tanpa kontrak layanan tidak punya SLA** dan tidak
   pernah tampil di Tiket Lewat SLA. → PANDUAN §12.3, §12.2.

3. **Memeriksa yang lewat SLA, setiap pagi.** Layar: `Layanan › Tiket Lewat SLA` — dua
   kotak (Tiket lewat SLA, Terlama) dan tabel Kode · Batas selesai · Terlambat ·
   Ditugaskan ke; klik baris membuka tiketnya. Yang masuk: tiket belum ditutup yang
   responsnya lewat tanpa respons pertama, atau penyelesaiannya lewat. → PANDUAN §12.4.

4. **Berita acara kunjungan — semuanya benar SEBELUM `Ajukan`.** Pemicu: kunjungan
   selesai di lokasi. Layar: `Layanan › Berita Acara` → **`Tambah Berita Acara`** →
   Tiket, Tanggal kunjungan, Teknisi, **Gudang suku cadang** (wajib dalam praktik begitu
   ada satu baris sparepart), Temuan, Tindakan, Rekomendasi, tabel **Sparepart terpakai**
   (Item, Qty) → **`Simpan`** → **`Ajukan`** — pengajuan mencoba seluruh pengeluaran
   stoknya lebih dulu lalu membatalkannya, dan menolak dengan kalimat yang sama yang
   nanti dipakai pengesahan. Nomor: `PM/2026/IX/0001`. Setelah pelanggan menandatangani:
   **`Sahkan Pelanggan`** → **Nama penandatangan pelanggan** (wajib). Yang terjadi:
   bon `ISS/…` terbit **bertanggal hari kunjungan**, suku cadang keluar dari gudang,
   jurnalnya masuk buku besar — dan bon itu tidak bisa dibatalkan. Laporan tanpa
   sparepart adalah tanda tangan murni: tanpa gudang, tanpa stok. Kotak *Nama
   penandatangan* di formulir bukan bukti tanda tangan; hanya `Sahkan Pelanggan` yang
   mencapnya. → PANDUAN §12.5.

5. **Dari ponsel di lokasi.** Layar: `#/lapangan` → tab **Tiket Servis** → pilih tiket
   dari daftar tiket terbuka → kartu **Foto lapangan** → **`Ambil foto`** (kamera
   belakang, batas 5 MB, GPS diminta sekali per jepretan; *"Foto terkirim dengan lokasi."*
   atau *"Foto terkirim (tanpa lokasi)."*). Foto menempel sebagai lampiran tiket itu.
   Tanpa tiket terbuka, layar berkata *"Tidak ada tiket terbuka."* → PANDUAN §7.4.

6. **Jadwal preventif dan tiket PM.** Layar: `Layanan › Jadwal Preventif` → **`Tambah
   Jadwal PM`** → Kontrak layanan, ID lokasi, Nama jadwal, Frekuensi (Bulanan /
   Triwulanan / Semesteran), Jatuh tempo berikutnya, Teknisi, Checklist (satu butir per
   baris) → **`Simpan`**. Tombol **`Buat Tiket PM`** di kepala daftar membuat satu tiket
   per jadwal yang jatuh tempo (kategori Pemeliharaan Preventif, prioritas Rendah, judul
   `{nama jadwal} — dd/mm/yyyy`) lalu menggulirkan jatuh temponya — dan pekerjaan yang
   sama sudah berjalan otomatis setiap malam, jadi menekannya biasanya tidak perlu. Satu
   tiket susulan per jadwal, bukan per periode yang terlewat. → PANDUAN §12.6.

7. **Memposting dokumen stok yang disiapkan gudang.** Pemicu: penjaga gudang (peran
   `warehouse`) menyerahkan draf — ia bisa mengetik, tidak bisa memposting; Anda
   sebaliknya: **tombol `Tambah GRN` / `Tambah Pengeluaran Barang` tidak digambar untuk
   Anda** (tanpa izin buat persediaan), tetapi tombol postingnya ada. Layar dan tombol:
   `Persediaan › Penerimaan (GRN)` → draf `GRN/2026/IX/0001` → **`Posting ke Stok`**
   (konfirmasi *"Posting GRN ini? Stok dan HPP rata-rata bergerak akan diperbarui dan
   dokumen tidak bisa diubah lagi."*); `Persediaan › Pengeluaran` → draf `ISS/…` →
   **`Posting ke Stok`** — periksa dulu **Proyek tujuan** dan **Paket pekerjaan (WBS)**
   di kepala bon, karena bon yang diposting tanpa keduanya menjadi overhead kantor dan
   tidak pernah masuk laporan varian; `Persediaan › Transfer` → **`Kirim`** lalu, di
   gudang tujuan, **`Terima`**; retur (`Buat Retur` dari GRN/bon terposting, dibuat
   gudang) → **`Posting Retur`**. Pembatalan: **`Batalkan Penerimaan`**, **`Batalkan
   Bon`** (Alasan pembatalan wajib) — mutasi cermin dan jurnal pembalik, permanen.
   Sebelum menekan apa pun: baca `Persediaan › Saldo Stok` untuk gudang dan item itu.
   → PANDUAN §6.1, §6.2, §6.4, §6.5, §6.6, §6.8.

8. **Kontrak layanan.** Layar: `Layanan › Kontrak Layanan` → **`Tambah Kontrak Layanan`**
   → Pelanggan, Nama kontrak, Periode, Nilai, Siklus penagihan, **SLA respons (jam)**,
   **SLA penyelesaian (jam)**, tabel **Lokasi layanan** (minimal 1) → **`Simpan`**.
   Tabel Lokasi layanan pada halaman kontrak adalah tempat Anda membaca **nomor id
   lokasi** untuk formulir tiket dan jadwal PM. → PANDUAN §12.2.

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §14.2, §6.1): ikon Hapus pada tiket
  (izin hapus layanan — admin), `Setujui` opname stok (admin, direktur), `Tambah GRN` dan
  tombol Tambah lain di Persediaan (izin buat persediaan — gudang), dan baris Lapangan di
  sidebar.
- **Tiket yang sudah selesai** (§12.3): `Ticket TKT-… is resolved and can no longer be
  edited.` — tidak bisa diubah, tidak bisa ditugaskan ulang, tidak ada tombol buka
  kembali; yang tersisa `Tutup Tiket`. Lokasi salah kontrak: `"The selected site does not
  belong to the selected service contract."`
- **Berita acara, saat `Ajukan`** (§12.5): *"Laporan PM/… belum dapat diajukan. Pengesahan
  pelanggan nanti mengeluarkan suku cadangnya dari gudang, dan pengeluaran itu diuji
  sekarang — hasilnya ditolak: {pesan asli} Perbaiki selagi laporan masih berstatus draf:
  setelah diajukan seluruh kolomnya terkunci, dan periode yang memuat tanggal kunjungan
  tidak dapat ditutup sampai laporan ini selesai. Pemeriksaan ini tidak membuat bon maupun
  mutasi stok — nomor bon yang mungkin disebut di atas hanya nomor uji coba."*
- **Berita acara tanpa gudang** (§12.5): *"Laporan PM/… mencantumkan suku cadang, tetapi
  gudang asalnya belum diisi. Isi kolom 'Gudang suku cadang' pada laporan, lalu ulangi
  pengesahan pelanggan — tanpa gudang, stok tidak dapat dikeluarkan."*
- **Sesudah pelanggan menandatangani** (§12.5): *"Laporan PM/… sudah disahkan pelanggan dan
  tidak dapat dikembalikan ke draf. Pengesahan itu sudah menerbitkan bon ISS/…: suku
  cadangnya sudah keluar dari gudang dan jurnalnya sudah ada di buku besar. Bon yang lahir
  dari berita acara tidak dapat dibatalkan, jadi koreksinya lewat opname."* — dan pada bon
  itu sendiri (§6.5): *"Bon {kode} dibuat otomatis dari pengesahan laporan lapangan dan
  tidak dapat dibatalkan sendiri — koreksi laporan lapangannya, karena pengesahan dan
  pengeluaran suku cadang adalah satu peristiwa yang sama."* Koreksinya: opname stok
  oleh gudang, disetujui direktur/admin, selisihnya menjadi beban (§6.7).
- **Stok tidak cukup** (§6.5): *"Stok tidak mencukupi: {item} di {gudang} (tersedia {n},
  diminta {m})."* — berlaku pada bon dan pada pengesahan berita acara.
- **Tanggal mundur** (§6.10): *"Dokumen {kode} bertanggal {tanggal}, lebih awal dari
  mutasi terakhir {tanggal} untuk {item} di {gudang}. Harga rata-rata dihitung maju
  menurut urutan pencatatan, … Ubah tanggalnya menjadi {tanggal} atau sesudahnya, atau
  catat selisihnya lewat opname."* Berlaku pada GRN, bon, `Kirim`, retur, kedua
  pembatalan — dan pada berita acara yang tanggal kunjungannya lebih awal dari mutasi
  terakhir gudang itu. `Terima` transfer satu-satunya yang jatuh maju, bukan menolak.
- **Periode tertutup** (§6.10): *"Periode fiskal 2026-03 sudah ditutup; jurnal tidak dapat
  diposting ke dalamnya."* — memblokir setiap mutasi stok, termasuk transfer bernilai nol.
- **Status dokumen** (§6.4, §6.5): `"GRN {kode} is {status}; only draft GRNs can be
  posted."` · `"Issue {kode} is {status}; only draft issues can be posted."` · GRN yang
  menunjuk PO tertutup: `"GRN {kode} references PO {kode}, which is closed; …"`.
- **Yang tidak bisa dibatalkan** (§14.4): `Sahkan Pelanggan` (bon suku cadang terbit,
  bertanggal hari kunjungan) · `Posting ke Stok` (jalan setelahnya hanya `Batalkan
  Penerimaan`/`Batalkan Bon`, selama syaratnya terpenuhi) · `Kirim` transfer (tidak bisa
  diubah, dihapus, atau dibatalkan — transfer kedua ke arah sebaliknya) · `Posting Retur`
  (tidak ada pembatalan sama sekali).

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **9** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Layanan — Berita Acara Servis (F/BS, halaman berita acara)
- Layanan — Ringkasan Kontrak Layanan (F/KL, halaman kontrak)
- Persediaan — Bukti Penerimaan Barang (F/BPB) · Bon Pengeluaran Barang (F/BM)
- Persediaan — Surat Jalan Antar Gudang (F/SJ) · Berita Acara Stock Opname (F/BAO)
- Persediaan — Daftar Saldo Stok (F/SS, dari halaman Gudang; selalu bertanggal hari cetak)
- Persediaan — Bukti Retur Pembelian (F/RPB) · Bukti Retur Material (F/RTM)

Empat formulir Persediaan (Bukti Penerimaan, Bon, Berita Acara Stock Opname, Daftar Saldo
Stok) juga punya tombol **`XLSX`** (§13.2a). **Aturan kejujuran (§13.5): sel yang bergaris
kosong berarti "tidak tercatat", tidak pernah berarti nol** — dan di XLSX sel itu kosong,
bukan 0.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `teknisi` di dialog
      Akun.
- [ ] Saya sudah menyimpan `#/lapangan` di ponsel dan melihat tab **Tiket Servis**.
- [ ] Saya sudah menambahkan satu **Catatan Pekerjaan** pada tiket saya sebelum apa pun
      yang lain, dan melihat statusnya berganti Dikerjakan.
- [ ] Saya sudah menyelesaikan satu tiket (`TKT-…`) dengan Catatan penyelesaian, lalu
      menutupnya.
- [ ] Saya sudah membuka `Tiket Lewat SLA` dan tahu tiket mana yang terlama.
- [ ] Saya sudah membaca nomor id lokasi dari tabel Lokasi layanan pada satu kontrak.
- [ ] Saya sudah membuat satu berita acara (`PM/…`) dengan satu baris sparepart dan gudang
      terisi, dan membaca kalimat penolakan `Ajukan` bila ada.
- [ ] Saya sudah menekan `Sahkan Pelanggan` sekali dan melihat nomor bon `ISS/…` yang
      terbit bertanggal hari kunjungan.
- [ ] Saya sudah memposting satu draf GRN atau bon milik gudang dengan `Posting ke Stok`,
      setelah membaca Saldo Stok gudang itu.
- [ ] Saya tahu bahwa `Kirim` transfer tidak bisa ditarik kembali, dan sudah memeriksa
      gudang tujuan sebelum menekannya.
- [ ] Saya sudah mencetak satu Berita Acara Servis (F/BS).
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan tahu bahwa ia kosong untuk peran saya.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **Tiket harus dihapus** → admin (§12.3, §14.2).
- **Draf GRN/bon/transfer/retur salah isi sebelum diposting** → petugas gudang, lewat
  `Ubah` pada drafnya (§6.4, §6.5).
- **Suku cadang salah pada berita acara yang sudah disahkan** → petugas gudang menyusun
  opname; direktur atau admin menekan `Setujui` (§6.7, §12.5).
- **Periode fiskal tertutup menolak posting** → manajer keuangan atau administrator
  (§6.10; PANDUAN-ADMINISTRATOR §6).
- **Kontrak layanan pelanggan, SLA, akhir periode** → sales (§12.2).
- **Item stok belum ada di master** → petugas gudang atau administrator (§6.3).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang mengumpulkan pekerjaan
> yang menunggu Anda dalam satu permintaan; draf formulir (berita acara) yang bertahan di
> peramban saat sesi habis; catatan persetujuan inline tanpa dialog; dan ganti kata sandi
> sendiri. Sampai rilis itu tayang, halaman ini menggambarkan yang berlaku.
