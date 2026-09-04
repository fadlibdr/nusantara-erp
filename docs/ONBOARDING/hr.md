# Onboarding minggu pertama — Petugas SDM / Payroll (`hr`)

Halaman ini bukan panduan. Panduannya adalah `docs/PANDUAN-PENGGUNA.md` (disebut
**PANDUAN** di bawah); halaman ini adalah jalan masuk ke sana untuk minggu pertama Anda:
siapa Anda di sistem, apa yang Anda lihat hari pertama, pekerjaan yang benar-benar Anda
lakukan, aturan yang akan menolak Anda, dan daftar periksa. Yang tertulis di sini hanyalah
yang benar-benar ada di layar akun `hr` hari ini.

Bab PANDUAN yang menjadi milik Anda (PANDUAN §0): **1, 2, 11**.

---

## 1. Siapa Anda di sistem

- **Peran akun:** `hr`. **Akun demo:** `hr@nusantara.test` (Siti Rahayu) — pakai akun
  itu untuk satu jam latihan sebelum akun Anda sendiri dibuat.
- **Pekerjaan Anda dalam satu kalimat:** merawat data karyawan dan register sertifikat /
  PKWT, mencatat cuti dan absensi, lalu menghitung dan **mengajukan** payroll —
  sementara **menyetujui** bukan pekerjaan Anda: peran ini memegang lihat/buat/ubah/hapus/
  posting pada modul SDM tetapi **tidak memegang `hr.approve`**. Persetujuan cuti dan
  persetujuan payroll ada pada **direktur** (dan admin), dan itu disengaja: menyetujui
  payroll **adalah** memposting seluruh run ke buku besar (§11.1).
- **Tempat Anda di rantai proses** (ANALISIS-PROSES-BISNIS-2026-09 §1): modul SDM tidak
  berada di salah satu dari empat rantai dokumen itu — ia memberi makan ketiganya dari
  samping. Sertifikat keahlian yang Anda rawat menjadi lampiran kualifikasi tender sales
  (§3.5d) dan tarif PPh final pelaksanaan (SKK yang lewat menaikkan 2,65% menjadi 4,00%,
  §11.3); rekap absensi Anda menggerakkan lembur payroll; payroll yang disetujui menjadi
  kewajiban di Hutang Gaji & Upah yang dibayar keuangan (§11.7). Yang ANALISIS §2 catat
  dari produksi: nol pengajuan cuti — modul cuti belum pernah dipakai. Anda yang akan
  memulainya.
- **Yang menyerahkan pekerjaan kepada Anda:** `project-manager` dan `site-manager` (izin
  lembur ILB yang disetujui menulis jam lembur ke rekap Anda; penugasan personel yang
  membekukan proyek pada slip gaji), `direktur` (mengembalikan cuti dan payroll Anda
  sebagai Disetujui atau Ditolak), administrator (akun pengguna untuk karyawan baru —
  Anda hanya membaca layar Pengguna).
- **Yang menerima pekerjaan dari Anda:** `direktur` — `Setujui`/`Tolak` pengajuan cuti dan
  payroll; `finance` — pembayaran gaji, BPJS, dan PPh 21 dari kewajiban yang dibentuk
  payroll (§11.7); `sales` — membaca sertifikat berlaku di Penyusun Kualifikasi (§3.5d).

## 2. Hari pertama

**Masuk** — PANDUAN §1.1: `https://erp1.pi2.co.id`, Email + Kata sandi dari administrator,
tombol **`Masuk`**. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis (§1.2).

**Sidebar Anda** (kelompok yang izinnya Anda pegang; kelompok lain tidak digambar) —
layar yang akan Anda pakai minggu ini, per kelompok:

- **Ringkasan** — Dasbor · Tenggat · Kalender.
- **SDM & Payroll** — keenam layarnya milik Anda: Karyawan · Sertifikat & PKWT · Cuti &
  Izin · Absensi Harian · Rekap Absensi · Payroll.
- **Sistem** — Pengguna · Peran & Hak Akses · Profil Perusahaan · Pengaturan — semuanya
  **baca saja** (Anda memegang izin lihat sistem, bukan ubah: tidak ada tombol Tambah di
  Pengguna, dan kolom Profil Perusahaan terlihat tetapi mati — §14.1); ditambah **Impor
  Data Master** (tabel **Karyawan**, §2.9), yang boleh Anda pakai. Baris **Impor Dokumen**
  tidak digambar untuk Anda — izinnya milik modul lain.

Kelompok Penjualan, Estimasi, Engineering, Proyek, Mutu, Pengadaan, Persediaan,
Subkontrak, Keuangan, Layanan, dan Aset tidak ada di sidebar Anda.

**Dasbor Anda** (PANDUAN §1.7):

- **Tidak ada ubin angka** — barisnya berbunyi *"Peran Anda tidak memiliki akses ke
  ringkasan modul mana pun."* Ubin dasbor membaca izin proyek, keuangan, dan layanan;
  tidak satu pun Anda pegang. Itu bukan kerusakan.
- Kartu **Menunggu persetujuan Anda** — **selalu berbunyi** *"Tidak ada dokumen yang
  menunggu persetujuan."* untuk Anda. Kabar cuti dan payroll Anda disetujui atau ditolak
  datang lewat **lonceng** (lencana Disetujui hijau / Ditolak merah) dan lewat lencana
  status di daftarnya.
- Kartu **Kalender Acara** (selalu digambar). Tidak ada kartu lain untuk peran Anda.

**Lonceng dan Tenggat** — yang ditujukan kepada peran Anda (PANDUAN §1.7), dua saja,
keduanya diingatkan **60 hari** di muka:

| Tenggat | Diperingatkan | Sumbernya |
|---|---|---|
| Sertifikat keahlian mendekati kedaluwarsa | 60 hari | register Sertifikat & PKWT |
| PKWT karyawan mendekati tanggal akhir | 60 hari | Akhir PKWT pada data karyawan |

Layar `SDM & Payroll › Sertifikat & PKWT` memuat tiga kotak yang menghitung hal yang sama
hari ini: **Sertifikat lewat / menipis** · **PKWT lewat / menipis** · **PKWT tanpa
tanggal**. Sertifikat tanpa tanggal kedaluwarsa **tidak pernah diawasi** — kosong dibaca
"tidak kedaluwarsa" (§11.3).

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang tidak Anda punya izinnya tidak ada — bukan abu-abu.
2. `Ubah` dan `Hapus` menghilang begitu cuti dan payroll keluar dari Draf/Ditolak; jalan
   kembali adalah `Tolak` (oleh direktur).
3. Anda tidak boleh menyetujui dokumen yang Anda ajukan sendiri — bagi Anda lebih
   sederhana: Anda tidak menyetujui apa pun.
4. Yang sudah diposting tidak punya tombol batal — payroll yang disetujui hanya bisa
   dikoreksi jurnal pembalik oleh akuntan.
5. Kata sandi tidak bisa Anda ganti sendiri — juga bukan Anda yang mengganti sandi
   karyawan lain; itu administrator (§14).
6. Sesi 12 jam; lembar absen yang panjang disimpan dulu sebelum sesi habis.

## 3. Pekerjaan Anda

Tujuh walkthrough. Tiap butir: pemicu → layar → nomor dokumen → siapa yang menyetujui /
apa yang terjadi berikutnya → rujukan PANDUAN.

1. **Karyawan baru — dan karyawan yang keluar.** Pemicu: orang bergabung atau resign.
   Layar: `SDM & Payroll › Karyawan` → **`Tambah Karyawan`** → *Data pribadi* (**Nama
   lengkap**, **NIK KTP** — tepat 16 digit dan unik, **Jenis kelamin**, **Tanggal lahir**,
   **Status PTKP**), *Kepegawaian* (**Jabatan**, **Departemen**, **Status kerja**:
   PKWTT / PKWT / Tenaga Harian Lepas — **Dasar PKWT** dan **Akhir PKWT** hanya untuk
   PKWT, **Tanggal masuk**), *Remunerasi & BPJS* (**Gaji pokok**, Tunjangan tetap, nomor
   BPJS, Bank · No. rekening · Atas nama) → **`Simpan`**. Tidak ada persetujuan dan tidak
   ada nomor dokumen — kolom Kode karyawanlah pengenalnya. Yang keluar: **Status =
   Resign** dan **Tanggal resign** — **jangan hapus**: menghapus karyawan tidak dijaga
   apa pun (§11.2). Karyawan massal: `Sistem › Impor Data Master` → tabel **Karyawan** →
   `Unduh template` atau `Ekspor data saat ini` → pratinjau → **`Simpan N baris`**;
   pencocokan lewat kolom `kode`, dan kolom yang ada tetapi kosong ditulis kosong (§2.9).
   Akun **masuk aplikasi** untuk karyawan itu bukan pekerjaan Anda — minta administrator
   (PANDUAN-ADMINISTRATOR §3.4). → PANDUAN §11.2.

2. **Register sertifikat dan PKWT.** Pemicu: SKK, sertifikat K3, sertifikasi principal
   diterima atau diperpanjang; karyawan kontrak baru. Layar: `SDM & Payroll › Sertifikat &
   PKWT` — tabel **Sertifikat keahlian**: **`Tambah Sertifikat`** (Karyawan, Sertifikat,
   nomor, penerbit, **Jenis**: SKK Konstruksi / Sertifikat K3/AK3 / Sertifikasi Principal
   / Lainnya, **Kedaluwarsa**), klik baris untuk halamannya dan lampirkan PDF pindaiannya;
   yang mendekati habis: tombol **`Perpanjang`** → **Tanggal kedaluwarsa baru** →
   **`Simpan perpanjangan`** (*"Tanggal kedaluwarsa diperbarui — pengingatnya
   berhenti."*). Tabel **PKWT karyawan kontrak**: tombol **`Isi tanggal`** / **`Ubah
   PKWT`** → **Dasar PKWT** (wajib) dan **Tanggal akhir PKWT** — sengaja tidak diwajibkan:
   PKWT selesainya pekerjaan sah tanpa tanggal akhir; jangan mengarang tanggal hanya untuk
   mendiamkan pengingat. Tidak ada persetujuan. → PANDUAN §11.3.

3. **Pengajuan cuti.** Pemicu: karyawan mengajukan cuti/sakit/izin. Layar: `SDM & Payroll
   › Cuti & Izin` → **`Tambah Pengajuan Cuti`** → **Karyawan**, **Jenis** (hanya cuti
   tahunan yang memotong saldo 12 hari), **Tanggal mulai**, **Tanggal selesai**, **Alasan /
   keperluan** → **`Simpan`** → **`Ajukan`**. Nomor: `CTI/2026/IX/0001`. Tidak ada kolom
   jumlah hari — server menghitungnya: **Sabtu ikut memotong**, hanya Minggu dilewati,
   dan **hari libur nasional tidak pernah dikecualikan**. Penyetuju: **direktur**.
   Menyetujui menulis ketidakhadirannya ke Rekap Absensi bulan itu (kolom Sakit dan Cuti
   dihitung ulang seluruhnya) — kecuali payroll bulan itu sudah disetujui, yang dilewati
   **diam-diam**. Saldo cuti tidak pernah disimpan; ia dihitung ulang setiap kali, terbit
   setelah 12 bulan masa kerja, tidak menumpuk bulanan, dan sisa tahun lalu tidak dibawa.
   → PANDUAN §11.4.

4. **Lembar absen harian.** Pemicu: setiap hari kerja, per proyek atau kantor. Layar:
   `SDM & Payroll › Absensi Harian` → **Tanggal** (tidak bisa melewati hari ini) dan
   **Proyek** (*"Kosongkan untuk staf kantor."*) → satu baris per karyawan aktif, tombol
   **`Hadir`** / **`½ Hari`** / **`Absen`** (mengklik status yang sudah terpilih
   membatalkannya — *"Belum dicatat" bukan "absen"*) → **`Simpan Lembar Absen`**
   (*"Absensi tersimpan: N baru, M diperbarui."*). Tidak ada nomor dokumen; ia register
   per pasangan (karyawan, tanggal) — mengirim ulang mengoreksi, bukan menggandakan, dan
   menyimpan ulang seseorang di bawah proyek lain **memindahkannya**. Register ini
   **belum memberi makan payroll**. → PANDUAN §11.5.

5. **Rekap absensi bulanan — satu kolom yang menggerakkan gaji.** Pemicu: akhir bulan,
   sebelum payroll. Layar: `SDM & Payroll › Rekap Absensi` (tanpa halaman detail; baris
   diubah di tempat) → **Karyawan**, **Tahun**, **Bulan**, **Hari kerja**, **Hari hadir**,
   Sakit, Cuti, Alpa, **Jam lembur** (langkah 0,5) → **`Simpan`**. Satu rekap per karyawan
   per bulan. **Payroll hanya membaca jam lembur** — hari kerja, hadir, sakit, cuti, alpa
   tidak berpengaruh pada gaji; pemotongan karena ketidakhadiran diurus di luar sistem.
   Jam lembur karyawan yang punya **Izin Lembur (ILB)** disetujui **ditulis ulang** oleh
   persetujuan ILB berikutnya dari seluruh izin bulan itu; ketik tangan hanya untuk lembur
   yang memang tidak lewat izin. → PANDUAN §11.5, §7.13.

6. **Payroll bulanan dan THR.** Pemicu: tanggal gajian. Layar: `SDM & Payroll › Payroll`
   → **`Tambah Payroll Run`** → **Tahun**, **Bulan**, **Jenis** (Gaji Bulanan / THR
   Keagamaan), Tanggal pembayaran → **`Simpan`** → **`Hitung Payroll`** (*"Hitung ulang
   payroll? Slip gaji yang sudah ada akan diganti."*) → periksa tabel slip (Gaji pokok ·
   Tunjangan · Lembur · THR · Bruto · BPJS (karyawan) · **TER** · PPh 21 · Netto; ikon
   unduh per baris = slip gaji PDF) → **`Ajukan`**. Nomor: `PYR/2026/09/001`. Penyetuju:
   **direktur** — dan **`Setujui` ADALAH postingnya**: satu transaksi ke buku besar,
   bertanggal **hari terakhir periode**, tanpa langkah posting terpisah dan tanpa
   pembatalan. `Hitung Payroll` mengambil **setiap** karyawan aktif yang tanggal masuknya
   ≤ akhir periode — tidak ada cara mengeluarkan satu orang dari run. PPh 21 memakai TER,
   kecuali Desember (Pasal 17 dikurangi TER Januari–November; nilai negatif berarti
   kelebihan potong yang dikembalikan). THR: masa kerja ≥ 12 bulan 1× gaji pokok, 1–11
   bulan pro-rata, < 1 bulan tanpa slip. → PANDUAN §11.6.

7. **Sesudah payroll disetujui: menyerahkan ke keuangan.** Menyetujui payroll **tidak
   membayar siapa pun** — ia membentuk kewajiban di Hutang Gaji & Upah, Hutang BPJS, Hutang
   PPh 21. Transfernya adalah dokumen **Pembayaran** tersendiri milik `finance`, lewat
   kartu *"Bayar kewajiban non-AP (gaji, pajak, BPJS)"*, dengan rantai persetujuannya
   sendiri (manajer keuangan). Yang Anda serahkan: **`Cetak Rekap Gaji`** (F/RG) dan slip
   PDF; yang Anda tanyakan sesudahnya ke keuangan: nomor `PAY/…`-nya. → PANDUAN §11.7,
   §5.10.

## 4. Yang akan menolak Anda

Kalimat merah di layar sengaja menyebut nama, angka, dan jalan keluar. Yang paling sering
untuk peran Anda, apa adanya:

- **Tombol yang tidak akan pernah muncul** (PANDUAN §14.2): `Setujui`/`Tolak` cuti dan
  payroll (direktur), `Tambah Pengguna` dan pensil Ubah pada Pengguna (administrator).
- **Karyawan** (§11.2): NIK KTP harus **tepat 16 digit dan unik**; Tanggal lahir harus
  sebelum hari ini; **Dasar PKWT dan Akhir PKWT ditolak** untuk karyawan bukan-PKWT
  (galatnya tepat pada kedua kolom itu), dan mengubah kontrak menjadi tetap
  **mengosongkan keduanya diam-diam**; Akhir PKWT harus setelah Tanggal masuk dan **paling
  lama lima tahun sesudahnya** (PP 35/2021 Pasal 8); **Tanggal resign wajib** bila Status
  = Resign. Dan satu yang **tidak** menolak padahal seharusnya: menghapus karyawan lolos
  tanpa memeriksa slip gaji, kasbon, atau tiket — jangan lakukan.
- **Sertifikat & PKWT** (§11.3): *"Tanggal kedaluwarsa harus setelah tanggal terbit."* ·
  konfirmasi hapus: *"Hapus '{nama}' dari register? Pengingat kedaluwarsanya ikut
  berhenti; barisnya disimpan sebagai soft delete untuk jejak audit."*
- **Cuti** (§11.4): *"Rentang tanggal tidak memuat satu pun hari kerja."* · *"Tanggal
  selesai mendahului tanggal mulai."* · *"Rentang cuti melebihi 90 hari — periksa
  tahunnya."* · *"Rentang tanggal bertabrakan dengan CTI/… (2026-08-10 s.d. 2026-08-14)."*
  — **termasuk draf** yang terlupa; hapus drafnya, jangan mencari jalan memutar · *"Cuti
  tahunan belum tersedia: masa kerja belum genap 12 bulan (UU 13/2003 Pasal 79). Hak cuti
  terbit 2027-01-15."* · *"Saldo cuti tahunan tidak cukup: sisa 3 hari, diminta 5 hari
  (CTI/…)."* — diperiksa dua kali, saat Ajukan dan saat Setujui.
- **Absensi dan rekap** (§11.5): *"Belum ada status yang dipilih."* · `Present + sick +
  leave + alpha days may not exceed the work days of the period.` (hadir + sakit + cuti +
  alpa ≤ hari kerja; pesannya berbahasa Inggris) · tombol `Cetak Daftar Hadir` **mati**
  sampai pasangan tanggal-dan-proyek punya baris tersimpan (*"Belum ada absensi tersimpan
  untuk proyek ini pada 12 Agustus 2026"*) · persetujuan ILB pada bulan yang payrollnya
  sudah diposting: *"Rekap {YYYY-MM} tidak diubah — payroll periode itu sudah
  diposting."*
- **Payroll** (§11.6): `"Payroll run PYR/… has no payslips yet — calculate it first."` ·
  `"Payroll run … cannot be modified while status is submitted."` · `"Payroll PYR/… is
  already posted as JV/…. Correcting it needs a reversing journal, not a second
  posting."` · dan pemisahan tugas pada penyetujunya (§2.5) — direktur yang kebetulan
  mengajukan ulang run Anda tidak boleh menyetujuinya sendiri.
- **Yang menimpa tanpa bertanya** (§11.4, §11.5): menyetujui cuti menulis ulang kolom
  Sakit dan Cuti rekap bulan itu; menyetujui ILB menulis ulang jam lembur karyawan izin
  itu. Apa pun yang Anda ketik tangan di kolom-kolom itu tertimpa.
- **Yang tidak bisa dibatalkan** (§14.4): `Setujui` payroll (sekaligus memposting seluruh
  run — koreksinya jurnal pembalik oleh akuntan) · `Hitung Payroll` (slip yang ada
  diganti) · hapus karyawan, sertifikat, atau apa pun dari daftar.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan PDF (PANDUAN §13.1). Tombol yang izinnya tidak Anda pegang tidak digambar.
Peran Anda memegang **3** formulir rumah (kode F/ dari §13.3):

- SDM & Payroll — Rekap Gaji (F/RG, mendatar, halaman Payroll)
- SDM & Payroll — Pengajuan Cuti (F/PC, halaman Cuti & Izin)
- SDM & Payroll — Daftar Hadir Harian (F/DH, mendatar, berkolom tanda tangan basah —
  tombol **`Cetak Daftar Hadir`** di layar Absensi Harian, hidup hanya setelah lembarnya
  tersimpan)

Di luar ketiganya, **slip gaji** adalah PDF sungguhan: ikon unduh per baris slip di halaman
Payroll run, satu berkas per karyawan (§13.4). **Aturan kejujuran (§13.5): sel yang
bergaris kosong berarti "tidak tercatat", tidak pernah berarti nol** — kolom tanda tangan
Daftar Hadir diisi tangan di lokasi.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri dan melihat peran `hr` di dialog Akun.
- [ ] Saya sudah membuat satu karyawan dengan NIK 16 digit dan melihat kolom Dasar/Akhir
      PKWT ditolak saat Status kerja bukan PKWT.
- [ ] Saya sudah mencatat satu sertifikat **dengan tanggal kedaluwarsa**, dan tahu bahwa
      sertifikat tanpa tanggal tidak pernah diingatkan.
- [ ] Saya sudah membuka **Ringkasan › Tenggat** dan melihat dua tenggat milik peran saya
      (Sertifikat, PKWT karyawan) — atau tahu mengapa kosong.
- [ ] Saya sudah mengajukan satu pengajuan cuti (`CTI/…`), membaca jumlah harinya dari
      kolom Hari, dan tahu nama direktur yang menyetujuinya.
- [ ] Saya sudah menyimpan satu lembar absen untuk satu tanggal dan satu proyek, dan
      melihat tombol `Cetak Daftar Hadir` hidup sesudahnya.
- [ ] Saya sudah mengisi satu rekap absensi dan tahu bahwa hanya kolom **Jam lembur** yang
      dibaca payroll.
- [ ] Saya sudah membuat satu payroll run (`PYR/…`), menekan `Hitung Payroll`, membuka satu
      slip PDF, dan menekan `Ajukan`.
- [ ] Saya tahu bahwa `Setujui` payroll oleh direktur sekaligus memposting ke buku besar,
      dan bahwa pembayaran gajinya adalah dokumen `PAY/…` milik keuangan.
- [ ] Saya sudah membuka `Sistem › Pengguna` dan tahu saya hanya membaca — akun baru
      diminta ke administrator.
- [ ] Saya sudah mencetak satu Rekap Gaji (F/RG) dan tahu sel mana yang bergaris.

## 7. Bila tersangkut

- **Kata sandi, lupa sandi, akun masuk untuk karyawan baru, menonaktifkan akun orang
  yang keluar, izin yang kurang** → administrator (PANDUAN §14.1;
  PANDUAN-ADMINISTRATOR §3.4) — Anda melihat layar Pengguna, tetapi tidak mengubahnya.
- **Cuti atau payroll menunggu `Setujui`; payroll yang ditolak dan alasannya** →
  direktur (§11.4, §11.6).
- **Gaji, BPJS, dan PPh 21 belum ditransfer setelah payroll disetujui; payroll yang sudah
  diposting harus dikoreksi (jurnal pembalik)** → petugas keuangan, manajer keuangan
  untuk persetujuannya (§11.7, §5.10).
- **Jam lembur yang berbeda dari izin lembur; karyawan yang proyeknya salah di slip
  gaji (penugasan personel)** → manajer proyek atau site manager (§7.13, §7.8).
- **Sertifikat yang dibutuhkan lembar kualifikasi tender sebelum batas pemasukan** →
  sales, yang membaca register Anda di Penyusun Kualifikasi (§3.5d).
- **Hari libur nasional, pemotongan gaji karena ketidakhadiran, pemecahan gaji ke dua
  proyek** → tidak ada layarnya untuk siapa pun (§14.3); catat di luar sistem atau lewat
  jurnal penyesuaian oleh keuangan.

Eskalasi dalam dua baris (PANDUAN §14.5): kirim **alamat halaman** (seluruh isi bilah
alamat), **kode dokumen**, **teks merah persis**, dan **tombol yang Anda tekan** —
sekaligus. Bila yang hilang adalah tombolnya, sebutkan itu: tombol yang tidak ada hampir
selalu berarti izin, bukan kerusakan.

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** yang menampilkan cuti dan
> payroll Anda yang ditolak dan yang menunggu dalam satu permintaan; draf formulir yang
> bertahan di peramban saat sesi habis; catatan persetujuan inline tanpa dialog; dan
> ganti kata sandi sendiri. Sampai rilis itu tayang, halaman ini menggambarkan yang
> berlaku.
