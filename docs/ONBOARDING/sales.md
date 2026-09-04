# Onboarding minggu pertama — Sales / Pemasaran (`sales`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `sales` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 3** — dan bab 3 berhenti di
§3.9: penagihan bukan pekerjaan Anda.

---

## 1. Siapa Anda di sistem

- **Peran akun:** `sales`. **Akun demo:** `sales@nusantara.test` (Maya Puspita) — pakai
  akun itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** mencatat prospek dan pelanggan, menyusun berkas
  lelang dan penawaran, **mengajukan** penawaran, menandai menang/kalah, melengkapi
  jadwal termin kontrak, dan mencatat tambah-kurang serta jaminan — sementara
  **menyetujui** bukan pekerjaan Anda: peran ini tidak memegang `crm.approve`, jadi Anda
  tidak menyetujui penawaran, tidak mengaktifkan kontrak, dan tidak menyetujui pekerjaan
  tambah-kurang. Keuangan pun tidak ada di sidebar Anda (§3.1).
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1): Anda berdiri di
  **awal rantai penawaran → kas** (§1.1): prospek → pelanggan → paket tender / RKK / TKDN
  → penawaran (ajukan → setujui) → menang → kontrak (jadwal termin) → aktif → invoice
  termin (keuangan) → kas masuk. Yang ANALISIS §2 catat dari produksi: sebuah penawaran
  yang menang 22 Agustus masih berpasangan dengan kontrak **draf** 13 hari kemudian, dan
  satu kontrak diketik dengan nilai Rp 200 juta lebih kecil daripada penawarannya. Kontrak
  yang tidak Anda lengkapi tidak pernah sampai ke keuangan.
- **Yang menyerahkan pekerjaan kepada Anda:** `estimator` (BOQ/RAB di balik angka
  penawaran dan baris biaya SMKK pada RKK), `direktur` (mengembalikan penawaran Anda
  sebagai Disetujui atau Ditolak; mengaktifkan kontrak), `hr` dan `project-manager`
  (register sertifikat, aset, dan IBPRP proyek yang dibaca lembar kualifikasi dan RKK
  Anda).
- **Yang menerima pekerjaan dari Anda:** `direktur` — `Setujui`/`Tolak` penawaran dan
  pekerjaan tambah-kurang, `Aktifkan Kontrak`; `finance` — termin yang siap ditagih
  begitu kontrak aktif dan Rencana tagih terisi (§3.10); `project-manager` — proyek yang
  lahir dari kontrak aktif, milestone yang menandai termin siap tagih.

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2) —
penawaran 40 baris disimpan bertahap, lalu dibuka lagi lewat `Ubah`.

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **Penjualan** — kesebelas layarnya milik Anda: Pelanggan · Prospek · Paket Tender ·
  Penawaran · Lembar TKDN · RKK Penawaran · Penyusun Kualifikasi · Kontrak · Pekerjaan
  Tambah-Kurang · Analitik Win-Rate · Jaminan & Asuransi.
- **Proyek** — Daftar Proyek · Milestone · BAST — **baca saja**: 20 layar proyek tampil
  untuk Anda tanpa tombol Tambah (§7.1); yang berguna adalah status proyek pelanggan Anda
  dan milestone yang menggerakkan terminnya.
- **Layanan** — Kontrak Layanan · Tiket — **baca saja** (§12.1): kontrak pemeliharaan
  pelanggan Anda dan tiket yang mereka buka.
- **Engineering** — satu baris saja: Lokasi Tapak (baca saja; barisnya bergerbang izin
  proyek — §1.4). **Aset** — satu baris saja: Log BBM & Jam Alat (baca saja; sebab yang
  sama).
- **Sistem** — dua baris saja: **Impor Data Master** (tabel **Pelanggan**) dan **Impor
  Dokumen** (**Penawaran** dari Excel, §3.5). Kedua baris punya izinnya sendiri (§1.4).

Kelompok Estimasi, Mutu, Pengadaan, Persediaan, Subkontrak, Keuangan, dan SDM tidak ada di
sidebar Anda. BOQ di balik penawaran Anda dibaca estimator, bukan Anda.

**Dasbor Anda** (PANDUAN §1.7):

- Ubin **Proyek berjalan** (jumlah dan nilai kontrak) dan **Tiket aktif** (dengan *"n
  melewati SLA"* atau *"SLA aman"*). Tombol **`Proyek saya`** hanya digambar bila akun
  Anda ditautkan ke data karyawan; pada akun demo `sales` ia tidak ada.
- Kartu **Menunggu persetujuan Anda** — **selalu berbunyi** *"Tidak ada dokumen yang
  menunggu persetujuan."* untuk Anda. Kabar penawaran dan CCO Anda disetujui atau ditolak
  datang lewat **lonceng** (lencana Disetujui hijau / Ditolak merah) dan lewat lencana
  status di daftarnya.
- Kartu **Kalender Acara** (selalu digambar), **Progres proyek** (bila ada proyek
  berjalan), dan **Tiket layanan aktif** (bila ada tiket terbuka).

**Lonceng dan Tenggat** — yang ditujukan kepada peran Anda (PANDUAN §1.7), tiga saja:

| Tenggat | Diperingatkan | Catatan |
|---|---|---|
| Paket tender mendekati batas pemasukan | 7 hari | hari batasnya masih "hari ini" |
| Penawaran mendekati akhir masa berlaku | 14 hari | hilang saat ditandai Menang/Kalah |
| Kontrak layanan mendekati akhir periode | 60 hari | layarnya baca saja bagi Anda |

Yang **tidak** sampai kepada Anda: **Kontrak mendekati tanggal berakhir** dan **Jaminan &
asuransi mendekati berakhir** — keduanya bergerbang `crm.approve`, jadi hanya direktur dan
admin yang menerimanya (§3.8). **Buka register Jaminan sendiri**: baris teratas selalu
yang paling cepat habis. Batas pemasukan tender adalah satu-satunya tenggat di sistem yang
**tidak bisa diperbaiki setelah lewat** (§3.5a).

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu penawaran keluar dari Draf/Ditolak; jalan kembali
   adalah `Tolak` (oleh direktur) atau `Buat Revisi`.
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — di lajur Anda, `Tandai Kalah` dan
   `Aktifkan Kontrak` (oleh direktur) adalah titik tanpa jalan kembali.
5. Kata sandi tidak bisa Anda ganti sendiri; minta administrator (§14).
6. Sesi 12 jam; penawaran panjang disimpan bertahap.

## 3. Pekerjaan Anda

Sepuluh walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Prospek.** Pemicu: ada yang menelepon. Layar: `Penjualan › Prospek` → **`Tambah
   Prospek`** → **Nama kontak** (wajib), Perusahaan, Sumber, Estimasi nilai, **Sales
   penanggung jawab**, **Follow-up berikutnya** → **`Simpan`**; Kode dikosongkan, sistem
   menomorinya. Tidak ada persetujuan. Status prospek bergerak sendiri saat penawarannya
   ditandai Menang/Kalah. Tombol **`Jadikan Pelanggan`** hanya muncul pada prospek
   **Menang** — prospek yang baru mau ditawar tetap butuh pelanggan, dibuat langsung di
   layar Pelanggan (butir 2). → PANDUAN §3.2.

2. **Pelanggan.** Layar: `Penjualan › Pelanggan` → **`Tambah Pelanggan`** → **Nama
   pelanggan**, Kode (kosong = `CUST-xxxx`), NPWP, **Pengusaha Kena Pajak (PKP)**, Alamat
   penagihan, PIC, **Termin pembayaran (hari)** → **`Simpan`**. Dua kolom yang menipu:
   **centang PKP pada pelanggan tidak menghitung apa pun** — tarif PPN 11% tetap terisi di
   setiap penawaran, kontrak, dan invoice; untuk pelanggan non-PKP Anda mengetiknya
   menjadi 0 di tiap dokumen — sedangkan **Termin pembayaran memang menggerakkan uang**:
   ia mengisi Jatuh tempo setiap invoice pelanggan itu. Jangan menghapus pelanggan
   (tidak dijaga apa pun); ubah **Status** menjadi **Nonaktif**. Pelanggan massal:
   `Sistem › Impor Data Master` → **Pelanggan** (kolom `kode` wajib di berkas).
   → PANDUAN §3.3, §2.9.

3. **Paket tender — berkas satu lelang.** Pemicu: dokumen lelang diterima. Layar:
   `Penjualan › Paket Tender` → **`Tambah Paket Tender`** → **Prospek**, **Paket
   pekerjaan**, Pemberi tugas, Nomor pengumuman lelang, **Batas pemasukan penawaran**,
   Tanggal aanwijzing → tabel **Register dokumen lelang** (**Judul dokumen**, **Tanggal
   terbit**, *Addendum ke* — kosong untuk terbitan asli; nomor addendum berjalan 1, 2, 3
   tanpa lompatan) → **`Simpan`**. Nomor: `TND/2026/IX/0001`. Tidak ada persetujuan —
   maker-checker berkas lelang hidup pada penawarannya. Kertas pemilik lelang (dokumen
   pemilihan, addendum, BA aanwijzing) ditempel di kartu **Lampiran** paket. Daftar paket
   diurutkan menaik menurut batas pemasukan. Checklist kelengkapan 21 butir **belum punya
   layar** (§14.3) — pakai kolom Catatan. → PANDUAN §3.5a.

4. **Penawaran — ajukan, lalu tandai.** Pemicu: harga siap. Layar: `Penjualan ›
   Penawaran` → **`Tambah Penawaran`** → **Pelanggan**, **Judul penawaran**, **Lingkup
   pekerjaan** (Konstruksi Gedung / Integrasi Sistem (ELV/ICT) / Pemeliharaan), Dari
   prospek, **Metode pelaksanaan** (hanya versi berlaku dari Pustaka Metode Kerja),
   Berlaku sampai, Tarif PPN → tabel **Rincian penawaran** (**Uraian** · **Qty** · Satuan
   · **Harga satuan**) → **`Simpan`** → **`Ajukan`**. Nomor: `QTN/2026/IX/0001`.
   Penyetuju: **direktur** (semua direktur mendapat pemberitahuan). Setelah Disetujui:
   **`Tandai Menang`** — konfirmasinya hanya *"Tandai penawaran ini sebagai
   dimenangkan?"*, tetapi satu klik itu **menerbitkan kontrak berstatus Draf** dengan
   nilai = DPP penawaran, menunggu di `Penjualan › Kontrak`; **jangan mengetik kontrak
   kedua**. **`Tandai Kalah`** (Alasan kalah wajib) muncul pada status apa pun dan **tidak
   bisa dibatalkan**. **`Buat Revisi`** mengembalikan Disetujui ke Draf, menaikkan nomor
   revisi, dan `Simpan` berikutnya **menimpa** baris lama tanpa arsip — cetak F/PN dulu.
   Menyimpan penawaran mengganti **seluruh** barisnya. Berkas Excel: `Sistem › Impor
   Dokumen` → **Penawaran** (§3.5). → PANDUAN §3.4.

5. **Tiga lembar lelang yang menguraikan penawaran.** Layar: `Penjualan › Lembar TKDN` →
   **`Tambah Lembar TKDN`** (satu penawaran, satu lembar) → halaman lembar → **`Tambah
   Komponen`** dua langkah (baris penawaran, kelompok biaya, biaya; lalu kolom penentu
   kelompok itu saja: kewarganegaraan / negara pembuat × kepemilikan / asal penyedia).
   Nomor: `TKD/2026/IX/0001`. Baris yang belum diuraikan **BELUM DINILAI** — bukan 0%,
   bukan 100%; persen paket selalu tampil bersama kalimat cakupannya, dan **lembar ini
   sengaja tidak punya tombol cetak** — angkanya disalin tangan ke formulir Kemenperin.
   `Penjualan › RKK Penawaran` → **`Tambah RKK`** → **Paket tender**, **Judul RKK**,
   Proyek sumber IBPRP, BoQ / RAB → di halamannya **`Pilih Baris IBPRP`** (dibaca hidup
   dari register risiko proyek sumber) dan **`Pilih Baris Biaya SMKK`** (nilai = baris
   RAB yang ditunjuk; tidak ada kotak rupiah). Nomor: `RKK/2026/IX/0001`. `Penjualan ›
   Penyusun Kualifikasi` — **baca saja**: personil bersertifikat berlaku (dari SDM),
   dukungan alat milik dan sewa (dari Aset), subkontraktor aktif (dari Pengadaan); pilih
   **paket tender** di kepala layar, dan tombol cetak **F/SBD** dan **F/DA** muncul,
   dijawab per **batas pemasukan** paket itu. → PANDUAN §3.5b, §3.5c, §3.5d.

6. **Kontrak — melengkapinya, bukan mengetiknya.** Pemicu: `Tandai Menang` sudah
   ditekan. Layar: `Penjualan › Kontrak` → buka kontrak draf yang lahir otomatis →
   **`Ubah`** → periksa **Nilai kontrak (DPP)** (selalu tanpa PPN), isi **No. kontrak
   pelanggan**, **Tanggal tanda tangan**, **Mulai**, **Selesai**, **Retensi (%)**, **Masa
   pemeliharaan (bulan)** → tabel **Jadwal termin** (**Nama termin** · **Persen (%)** —
   total tepat 100 · Syarat penagihan · centang **Retensi** · **Rencana tagih**) →
   **`Simpan Perubahan`**. Nomor: `CTR/2026/IX/0001`. Yang mengaktifkan: **direktur**,
   tombol **`Aktifkan Kontrak`** — status langsung Disetujui, tanpa `Ajukan`, tanpa
   Riwayat Persetujuan, tanpa pemisahan tugas. Sebelum termin pertama ditagih, pastikan
   **seluruh** termin (termasuk pemeliharaan triwulanan dan termin retensi) sudah ada dan
   **Rencana tagih** setiap termin terisi: termin tanpa Rencana tagih dan tanpa milestone
   **tidak pernah muncul** di Termin Siap Ditagih maupun di Tenggat — dan jadwal terkunci
   begitu invoice pertama disetujui. → PANDUAN §3.6.

7. **Pekerjaan tambah-kurang dan addendum waktu.** Pemicu: nilai atau waktu kontrak aktif
   berubah. Layar: `Penjualan › Pekerjaan Tambah-Kurang` → **`Tambah Pekerjaan
   Tambah-Kurang`** → **Kontrak**, **Tanggal**, **Judul**, **Jenis perubahan**
   (Tambah-Kurang / Eskalasi Harga / Addendum Waktu), **Perubahan nilai** (tidak boleh 0
   pada CCO nilai; **wajib 0** pada Addendum Waktu), **Perubahan waktu (hari)** (hanya
   Addendum Waktu, bertanda) → **`Simpan`** → **`Ajukan`**. Nomor: `CCO/2026/IX/0001`.
   Penyetuju: **direktur**; menyetujui CCO nilai langsung menggerakkan Nilai (DPP)
   kontrak, menyetujui addendum waktu menggeser Selesai kontrak dan proyeknya. Sesudah
   CCO nilai positif Disetujui: **`Jadwalkan Termin Nilai Tambah`** → **Rencana tagih**
   (wajib) — tanpa itu nilai tambahnya tidak pernah masuk antrean tagih. `Ubah` pada CCO
   tersimpan **gagal pada status apa pun**; CCO yang salah dihapus selagi Draf dan diketik
   ulang. → PANDUAN §3.7.

8. **Register jaminan & asuransi.** Pemicu: jaminan penawaran/pelaksanaan/uang
   muka/pemeliharaan atau polis CAR/TPL terbit. Layar: `Penjualan › Jaminan & Asuransi` →
   **`Tambah Jaminan`** → **Jenis**, **Nomor (dari penerbit)**, **Penerbit**, **Nilai**,
   **Mulai berlaku**, **Berakhir**, **Kontrak** *atau* **Penawaran** (salah satu wajib),
   **Lokasi dokumen fisik** → **`Simpan`**. Register, bukan dokumen: tanpa penomoran
   otomatis, tanpa persetujuan, tanpa tombol aksi. Jaminan yang selesai diubah statusnya
   lewat `Ubah` ke **Dikembalikan** / **Dicairkan** — jangan dihapus; perpanjangan adalah
   baris baru. Peringatannya **tidak sampai ke Anda** (butir Tenggat di atas). → PANDUAN
   §3.8.

9. **Membaca win-rate.** Layar: `Penjualan › Analitik Win-Rate` — baca saja: **Win-rate
   keseluruhan**, **Nilai dimenangkan** (DPP), **Nilai kalah**, **Masih berjalan**, tabel
   per kuartal (dari tanggal keputusan), dan **Alasan kalah terbanyak** — penawaran kalah
   tanpa alasan tercatat berlabel "Tidak dicatat". Itulah imbalan mengisi Alasan kalah
   dengan sungguh-sungguh. → PANDUAN §3.9.

10. **Membaca proyek dan layanan pelanggan Anda.** `Proyek › Daftar Proyek` dan
    `Proyek › Milestone`: milestone yang tanggal tercapainya diisi manajer proyek
    memasukkan termin ke antrean tagih (§3.10) — pertanyaan pelanggan "kapan ditagih"
    dijawab dari sini, bukan dari layar Keuangan yang tidak Anda punya. `Proyek › BAST`:
    serah terima yang menggerakkan retensi. `Layanan › Kontrak Layanan` dan `Layanan ›
    Tiket`: kontrak pemeliharaan dan tiket pelanggan — Anda menerima tenggat **Kontrak
    layanan** 60 hari di muka, tetapi mengubah kontraknya bukan izin Anda; teruskan ke
    teknisi atau admin (§12.2). → PANDUAN §7.8, §7.11, §12.2, §12.3.

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §14.2): `Setujui`/`Tolak` penawaran
  dan CCO, `Aktifkan Kontrak`, `Tagih termin ini`, seluruh kelompok Keuangan, dan
  peringatan Tenggat untuk jaminan dan kontrak. Direktur untuk yang pertama tiga; keuangan
  untuk penagihan.
- **Penawaran** (§3.4): `"Only an approved quotation can be marked won ({kode} is
  {status})."` · `"Quotation … outcome has already been decided."` · `"Quotation {kode}
  has been won; revise via the contract instead."` · *"Metode MTD/2026/0001 versi 1 sudah
  digantikan; versi yang berlaku adalah MTD/2026/0002 versi 2."* · menghapus penawaran
  berjaminan: *"Penawaran {kode} masih memiliki jaminan aktif ({nomor} — {penerbit});
  tandai jaminan itu dikembalikan/dicairkan atau pindahkan dulu tautannya."* · dan **yang
  tidak menolak padahal terlambat**: `Tandai Kalah` tersedia sejak Draf, dan sekali
  diklik penawaran terkunci Selesai selamanya — tidak ada tombol "batalkan kalah".
- **Prospek** (§3.2): `"Lead {kode} sudah menjadi pelanggan {kode}."` — klik kedua tidak
  membuat pelanggan kedua.
- **Paket tender** (§3.5a): *"Register dokumen lelang melompat: addendum ke-2 belum
  tercatat, sementara addendum ke-3 sudah. Catat dokumen yang terlewat dahulu."* · *"Baris
  3: addendum ke-1 sudah tercatat pada register ini."* · *"Baris 2: nomor addendum dimulai
  dari 1; kosongkan untuk terbitan asli."* · *"Baris 2: judul dokumen wajib diisi."*
- **Lembar TKDN** (§3.5b): *"Baris 1: biaya tenaga kerja wajib menyebut kewarganegaraan
  (wni atau wna)."* · *"Baris 1: biaya jasa umum wajib menyebut asal penyedia (dn atau
  ln)."* · *"Baris 1: alat buatan luar negeri dengan kepemilikan campuran wajib menyebut
  proporsi saham dalam negeri (0–100)."* · *"Penawaran QTN/2026/II/0002 sudah memiliki
  lembar TKDN (TKD/2026/VIII/0001)."* — tidak ada bawaan diam-diam pada kolom penentu.
- **RKK** (§3.5c): *"Baris IBPRP tidak ditemukan pada register risiko proyek ini: 91,
  92."* · *"Baris 1: baris BoQ #77 bukan milik BoQ yang dirujuk RKK ini."* · *"Baris 2:
  baris BoQ #77 sudah tercatat sebagai biaya SMKK pada RKK ini."* — dan kedua tombol
  pemilih baru muncul setelah *Proyek sumber IBPRP* dan *BoQ / RAB* diisi.
- **Kontrak** (§3.6): `"Termin percents must sum to 100, got {angka}."` · `"A contract
  needs at least one termin."` · `"Contract {kode} has billed termins; the schedule can no
  longer be replaced."` · `"Contract {kode} is {status} and can no longer be edited."` —
  dan yang menolak direktur saat mengaktifkan: `"Contract {kode} has no termin
  schedule."` · `"Contract {kode} termin percents sum to {x}, expected 100."`
- **Pekerjaan tambah-kurang** (§3.7): *"Kontrak {kode} berstatus {status}. Pekerjaan
  tambah-kurang hanya berlaku atas kontrak yang sudah disetujui — ubah nilainya langsung
  selama masih draf."* · `"Addendum waktu tidak memindahkan nilai — value_change wajib
  0."` · `"days_change hanya bermakna pada addendum waktu (change_type waktu)."` ·
  `"Kontrak {kode} tidak memiliki tanggal selesai — addendum waktu tidak punya dasar untuk
  digeser."` · `"Proyek {kode} berstatus {status}; addendum waktu hanya berlaku atas
  pekerjaan yang masih berjalan — perpanjangan setelah serah terima adalah instrumen
  lain."` · pada `Jadwalkan Termin Nilai Tambah`: `"Nilai tambah {kode} sudah dijadwalkan
  sebagai termin {no} ("{nama}") — satu perubahan, satu termin."` · dan galat isian
  Kontrak pada `Ubah` CCO tersimpan, pada status apa pun.
- **Jaminan** (§3.8): nomor jaminan **unik per penerbit, termasuk baris yang sudah
  dihapus** — konfirmasi hapusnya: *"Hapus jaminan ini dari register? Nomornya tetap
  terkunci per penerbit sampai baris dipulihkan — untuk jaminan yang sudah kembali, ubah
  status ke 'Dikembalikan', jangan dihapus."*
- **Impor** (§2.9, §3.5): *"pelanggan penawaran ini berubah dari {kode lama}; pastikan itu
  memang yang dimaksud."* (peringatan, bukan penolakan) · memperbarui dokumen **mengganti
  seluruh barisnya**: `Menimpa dokumen berisi N baris senilai Rp X; berkas ini membawa M
  baris — K baris akan DIHAPUS.` · hanya Draf/Ditolak yang boleh ditimpa.
- **Yang tidak bisa dibatalkan** (§14.4): `Tandai Kalah` · `Buat Revisi` lalu `Simpan`
  (baris lama hilang tanpa arsip) · `Aktifkan Kontrak` (oleh direktur) · invoice pertama
  disetujui (jadwal termin terkunci) · hapus pelanggan, prospek, atau apa pun dari daftar.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **20** formulir rumah, satu baris satu tombol (kode F/ dari §13.3):

- Penjualan — Surat Penawaran Harga (F/PN, halaman Penawaran; blok SYARAT & KETENTUAN
  sengaja kosong empat baris, ditulis tangan)
- Penjualan — Ringkasan Kontrak (F/RK; dua total bersebelahan — termin terjadwal dan nilai
  kontrak — selisihnya memang dimaksudkan supaya terlihat)
- Penjualan — Berita Acara Tambah-Kurang (F/BATK; CCO berjenis waktu mencetak BERITA
  ACARA ADDENDUM WAKTU dari tombol yang sama)
- Penjualan — Register Jaminan (F/RJ, mendatar; mencetak **seluruh jaminan kontrak itu**)
- Penjualan — RKK Penawaran (F/RKK, mendatar) · Daftar Personil (F/SBD) · Dukungan Alat
  (F/DA) — dua yang terakhir dari halaman Paket Tender atau Penyusun Kualifikasi setelah
  paket dipilih
- Layanan — Berita Acara Servis (F/BS) · Ringkasan Kontrak Layanan (F/KL)
- Proyek — Data Proyek (F/DP) · Laporan Harian (F/LH) · Detail Schedule / Program Kerja
  (F/DS) · Daftar Temuan / Defect List (F/DT) · Izin Kerja Lapangan (F/IK) · Izin Kerja
  Lembur (F/IL) · Izin Masuk / Keluar Material & Peralatan (F/IM) · Opname ke Pemilik
  (OPN) (F/OPN) · BAPP per Zona (F/BAPP) · Formulir K3 Harian (F/K3H) · IBPRP (F/IBPRP)

Yang sehari-hari milik Anda adalah tujuh lembar Penjualan; tiga belas lainnya tampil
karena Anda memegang izin lihat proyek dan layanan. **Lembar TKDN tidak punya tombol
cetak**, dan itu keputusan: formulir TKDN adalah formulir Kemenperin (§13.3). Surat
penawaran tidak dikirim lewat email dari aplikasi — cetak, lalu kirim sendiri. **Aturan
kejujuran (§13.5): sel yang bergaris kosong berarti "tidak tercatat", tidak pernah
berarti nol** — sertifikat kedaluwarsa dan sewa yang habis tidak dicetak pada F/SBD dan
F/DA, tetapi **jumlahnya tercetak** di blok identitas supaya ada yang sempat
memperpanjangnya.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `sales` di dialog Akun.
- [ ] Saya sudah mencatat satu prospek dan satu pelanggan, dan tahu bahwa centang PKP
      pelanggan tidak mengubah PPN dokumen mana pun.
- [ ] Saya sudah membuat satu paket tender (`TND/…`) dengan Batas pemasukan terisi, dan
      melihatnya muncul di **Ringkasan › Tenggat** bila kurang dari 7 hari.
- [ ] Saya sudah mengajukan satu penawaran (`QTN/…`) dan tahu nama direktur yang
      menyetujuinya.
- [ ] Saya sudah menekan `Tandai Menang` pada penawaran latihan yang Disetujui dan
      menemukan kontrak drafnya di `Penjualan › Kontrak` — tanpa mengetik kontrak kedua.
- [ ] Saya sudah melengkapi jadwal termin satu kontrak sampai persennya tepat 100 dan
      setiap termin punya **Rencana tagih**.
- [ ] Saya sudah mencoba `Buat Revisi` pada penawaran latihan dan tahu `Simpan` berikutnya
      menimpa baris lama tanpa arsip.
- [ ] Saya tahu `Tandai Kalah` tidak bisa dibatalkan, dan belum pernah menekannya pada
      penawaran yang bukan latihan.
- [ ] Saya sudah membuat satu lembar TKDN dan membaca lencana **BELUM DINILAI** pada baris
      yang belum diuraikan.
- [ ] Saya sudah membuka Penyusun Kualifikasi, memilih satu paket tender, dan melihat
      tombol cetak F/SBD dan F/DA muncul.
- [ ] Saya sudah membuka register Jaminan & Asuransi dan tahu bahwa peringatan
      kedaluwarsanya tidak sampai ke saya.
- [ ] Saya sudah mencetak satu Surat Penawaran Harga (F/PN) dan tahu blok mana yang
      bergaris.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, nama/email, izin yang kurang, menu yang tidak ada** →
  administrator (PANDUAN §14.1; PANDUAN-ADMINISTRATOR §3.4).
- **Penawaran atau CCO menunggu `Setujui`; kontrak menunggu `Aktifkan Kontrak`; jaminan
  atau kontrak yang mendekati berakhir** → direktur (§3.4, §3.6, §3.7, §3.8).
- **Angka di balik penawaran (BOQ/RAB), metode pelaksanaan di pustaka, baris RAB untuk
  biaya SMKK** → estimator (§4.3, §4.6a, §3.5c).
- **Termin siap ditagih, invoice, uang masuk, pencairan retensi; Rencana tagih pada
  kontrak yang jadwalnya terkunci** → petugas keuangan; yang terakhir administrator
  (§3.10–§3.14, §14.3).
- **Milestone yang menandai termin, tanggal selesai proyek, IBPRP sumber RKK** →
  manajer proyek (§7.8, §7.7).
- **Sertifikat personil yang kedaluwarsa sebelum batas pemasukan** → petugas SDM (§11.3);
  **sewa alat yang habis** → pemegang modul Aset (§9.3).
- **Kontrak layanan dan tiket pelanggan** → teknisi atau admin (§12.2, §12.3).
- **Checklist kelengkapan paket tender** → tidak ada layarnya untuk siapa pun; kolom
  Catatan, atau administrator lewat API (§14.3).

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang menampilkan penawaran dan
> CCO Anda yang ditolak dan yang menunggu dalam satu permintaan; draf formulir (termasuk
> penawaran) yang bertahan di peramban saat sesi habis; catatan persetujuan inline tanpa
> dialog; dan ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini menggambarkan
> yang berlaku.
