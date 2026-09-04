# Onboarding minggu pertama — Petugas Keuangan (`finance`)

**Peran akun:** `finance` · **Akun demo:** `finance@nusantara.test` (Dewi Lestari) ·
**Manual lengkap:** `docs/PANDUAN-PENGGUNA.md` bab 1, 2, **10**, ditambah §3.10–§3.14
(penagihan) dan §5.9–§5.10 (bayar vendor) — itulah baris Anda di PANDUAN §0.

> Panduan ini **bukan** manual. Ia jalan masuk ke manual: siapa Anda di sistem, apa yang
> Anda lihat hari pertama, sepuluh pekerjaan yang benar-benar Anda kerjakan, aturan yang
> akan menolak Anda, dan daftar periksa. Setiap langkah menunjuk pasal PANDUAN yang
> menjelaskannya; bacalah pasal itu sebelum menekan tombol untuk pertama kali. Yang
> ditulis di sini hanyalah yang benar-benar ada di layar `finance` hari ini.

---

## 1. Siapa Anda di sistem

Anda **menyiapkan dan membayar, tetapi tidak menyetujui.** Peran `finance` memegang
izin lihat/buat/ubah/hapus/posting di modul Keuangan, izin lihat pada Penjualan,
Pengadaan, Subkontrak, dan SDM (konteks untuk menilai sebuah dokumen), serta izin lihat
dan **posting** pada Aset (PANDUAN §10.1, §9.1). Izin **setujui** keuangan sengaja tidak
Anda pegang — itu milik `finance-manager` dan `direktur`.

Tempat Anda pada tiga rantai proses (ANALISIS-PROSES-BISNIS §1):

| Rantai | Yang sampai ke meja Anda | Yang Anda hasilkan | Diteruskan ke |
|---|---|---|---|
| Penawaran → kas | termin siap ditagih (milestone dari **manajer proyek**, atau jadwal); opname owner disetujui | `INV/…` invoice termin, `RCV/…` penerimaan, pencairan retensi | `finance-manager` / `direktur` menyetujui invoice; uang masuk Anda posting sendiri |
| Permintaan → pembayaran | PO disetujui + GRN diposting (**pengadaan** dan **gudang**); tagihan periode PPK | `BIL/…` tagihan vendor, `PAY/…` pembayaran keluar | `finance-manager` / `direktur` menyetujui; Anda yang memposting |
| Subkontrak & mandor | opname subkon / opname mandor yang disetujui **direktur** | `BIL/…` dari opname, `PAY/…` | sama seperti di atas |
| Payroll | run payroll yang disetujui direktur (kewajiban di Hutang Gaji) | `PAY/…` ke akun kewajiban | `finance-manager` / `direktur` |

Rantai keuangan di sistem ini berhenti **setelah** gerbang, bukan di gerbang: tagihan
yang disetujui tidak punya jadwal bayar dan tidak ada alarm untuk tagihan vendor yang
jatuh tempo (ANALISIS §1.2, PANDUAN §5.9). Mengingatnya adalah pekerjaan Anda.

## 2. Hari pertama

**Masuk.** Buka https://erp1.pi2.co.id, halaman **"Masuk ke akun Anda"**, isi **Email**
dan **Kata sandi** yang diberikan administrator, tekan **`Masuk`** (PANDUAN §1.1). Sesi
berumur 12 jam (§1.2).

**Sidebar Anda** memuat **tujuh kelompok** — kelompok yang izinnya tidak Anda pegang
tidak digambar (§1.4). Yang penting di minggu pertama:

| Kelompok | Layar yang akan Anda buka |
|---|---|
| Ringkasan | Dasbor · **Tenggat** · Kalender |
| **Keuangan** (milik Anda, 20 layar) | Termin Siap Ditagih · Invoice Termin (AR) · Tagihan Vendor (AP) · Pembayaran · Piutang Retensi · Jurnal · Buku Besar · Rekonsiliasi Bank · Kasir Kas Kecil · Kas Kecil & Kasbon · Kalender Pajak · Ekspor Pajak · Periode Fiskal · Pengakuan Pendapatan · Laporan Keuangan · Bagan Akun · Pajak · Rekening Bank |
| Penjualan (lihat saja) | Kontrak — tombol `Tagih termin ini` ada di halaman kontrak (§3.11) |
| Pengadaan (lihat saja) | Pesanan (PO) · Baris PO Terbuka · Tagihan Periode PPK — konteks tagihan vendor |
| Subkontrak (lihat saja) | SPK Subkon · Opname Subkon · Opname Mandor — sumber tagihan subkon |
| SDM & Payroll (lihat saja) | Payroll — run yang disetujui menjadi kewajiban yang Anda bayar (§11.7) |
| Aset | Penyusutan — tombol `Posting Penyusutan` milik Anda (§9.7) |

**Dasbor Anda** menampilkan empat ubin: **Piutang belum tertagih**, **Hutang belum
dibayar**, **Saldo bank** (negatif ditampilkan apa adanya), dan **Termin siap ditagih**
(dengan umur tunggu terlama). Di bawahnya: kartu **Piutang jatuh tempo terdekat** dan
**Kalender Acara**. Kartu **Menunggu persetujuan Anda** ikut digambar tetapi untuk peran
Anda selalu berbunyi *"Tidak ada dokumen yang menunggu persetujuan."* — Anda memang tidak
menyetujui apa pun.

**Lonceng dan Tenggat.** Layar **Ringkasan › Tenggat** menghitung ulang setiap dibuka;
untuk peran Anda ia mengawasi **empat** hal (§1.7):

| Yang diawasi | Kata tanggalnya | Diperingatkan |
|---|---|---|
| **Termin kontrak** | rencana tagih | 7 hari sebelumnya |
| **Retensi BAST** | jatuh tempo | 14 hari sebelumnya |
| **Invoice pelanggan** | jatuh tempo | pada hari jatuh tempo (0 hari) |
| **Setoran pajak masa** | jatuh tempo setor | 7 hari sebelumnya |

Lewat lonceng Anda juga menerima *"Termin {no} kontrak {kode} siap ditagih — Rp {nilai}"*
saat manajer proyek mengisi tanggal milestone (§3.10), *"Retensi proyek {kode} dapat
ditagih — Rp …"* saat BAST II disetujui (§7.11), *"Periode {label} belum ditutup"* setiap
pagi selama bulan yang sudah berakhir masih terbuka lebih dari 10 hari, dan *"Kalender
fiskal {tahun} dibuat"* menjelang tahun baru (PANDUAN-ADMINISTRATOR §5.7, §5.3). Menandai
dibaca tidak menyelesaikan apa pun; baris Tenggat hilang hanya ketika sebabnya dibereskan.

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang izinnya tidak Anda punya tidak abu-abu — ia tidak ada.
2. `Ubah` dan `Hapus` hilang begitu dokumen diajukan; jalan kembalinya `Tolak`.
3. Pengaju tidak boleh menyetujui dokumennya sendiri — peran Anda memang tidak menyetujui.
4. Yang terposting tidak bisa dibatalkan — hanya dibalik, dua jurnal berdampingan.
5. Anda tidak bisa mengganti kata sandi sendiri — minta administrator (bab 14).
6. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis — simpan bertahap.

## 3. Pekerjaan Anda

Setiap butir: pemicu → layar → nomor dokumen → apa yang terjadi berikutnya → pasal.

**1. Menagih satu termin kontrak** — PANDUAN §3.10, §3.11
Pemicu: baris muncul di **Keuangan › Termin Siap Ditagih** (milestone tercapai, atau
Rencana tagih jatuh tempo). Tekan **`Buka kontrak`**, di tabel **Jadwal termin** cari
baris yang kolom **Ditagih**-nya kosong, tekan **`Tagih termin ini`**. Formulir terbuka
sudah terisi; periksa **Tanggal invoice** dan **Jatuh tempo**, **`Simpan`**, lalu
**`Ajukan`** — sistem menerbitkan `INV/…` dan manajer keuangan diberi tahu. Setelah
**`Setujui`** ditekan orang lain, kembali dan tekan **`Catat Faktur Pajak`** (nomor seri
dari DJP). **Jangan mengetik `Termin kontrak (ID)` sendiri** — kolom itu meminta ID
basis data, dan tidak ada yang menahan Anda menagih termin kontrak lain. Bila menagih
volume opname owner, isi pintu **Opname ke pemilik (OPN)** dan biarkan DPP dihitung
server (§3.11a). Satu kontrak, satu pola retensi (§3.14).

**2. Mencatat uang masuk** — §3.12
Pemicu: mutasi kredit di rekening koran. **Keuangan › Pembayaran** → **`Tambah
Pembayaran`**, **Arah = Penerimaan (RCV)**, **Jumlah = uang yang benar-benar masuk**,
**`Simpan`** → `RCV/…`. Di kartu **`Alokasikan ke invoice terbuka`** isi **Alokasi** atau
tekan **`Lunasi`**; bila pelanggan memotong pajak, tekan **`Potongan pajak`** pada baris
itu (PPh final dan PPh 23 adalah dua kotak berbeda; nomor bukti potong wajib). Kaki kartu
harus berbunyi **`Sesuai mutasi bank ✓`**. Tekan **`Posting Pembayaran`** — penerimaan
tidak lewat persetujuan siapa pun dan jurnalnya langsung terbentuk.

**3. Mencairkan retensi pelanggan** — §3.13
Pemicu: lencana **`Sudah boleh ditagih`** di **Keuangan › Piutang Retensi** (butuh tanggal
BAST lewat **dan** BAST II Disetujui). **`Catat pencairan`** → Tanggal uang diterima,
Masuk ke rekening → **`Catat penerimaan Rp {nilai}`**. Ini jurnal langsung, **bukan**
dokumen Pembayaran, dan seluruh baris cair sekaligus. Baris *"Belum ada BAST"* adalah
pekerjaan yang tertinggal di sisi proyek — tanyakan ke manajer proyek.

**4. Mencatat tagihan vendor** — §5.9, §8.5, §5.15
Pemicu: invoice vendor tiba atas PO yang barangnya sudah diterima, opname subkon/mandor
yang disetujui, atau tagihan periode PPK. **Keuangan › Tagihan Vendor (AP)** →
**`Tambah Tagihan`**: isi **satu** dari **Dari PO** / **Dari opname subkon** / **Dari
opname mandor** / **Dari tagihan periode PPK** — nilainya disalin server dan ketikan
Anda di DPP/PPN diabaikan pada mode PO. **No. invoice vendor** wajib; pilih **Jenis PPh
dipotong** bila memotong PPh. **`Simpan`** → `BIL/…`, cetak **`Cetak Lembar Verifikasi
Tagihan`** (Form F/VT) untuk ditandatangani pemeriksa, lalu **`Ajukan`**. Persetujuan
adalah pencocokan tiga arah — tagihan atas PO yang belum diterima penuh akan ditolak
penyetuju, dan itu bukan kesalahan Anda mengetik.

**5. Membayar vendor, gaji, pajak, dan BPJS** — §5.10, §11.7
Pemicu: tagihan Disetujui yang jatuh tempo (urutkan kolom **Jatuh tempo**, baca kolom
**Sisa** — tidak ada alarm Tenggat untuk ini), atau kewajiban akun. **Keuangan ›
Pembayaran** → **`Tambah Pembayaran`**, **Arah = Pengeluaran (PAY)** → `PAY/…`. Isi
kartu **`Alokasikan ke tagihan terbuka`** **atau** kartu **`Bayar kewajiban non-AP
(gaji, pajak, BPJS)`** — mengetik di satu kartu mematikan yang lain. Kaki kartu harus
**`Sesuai mutasi bank ✓`**. Tekan **`Ajukan Pembayaran`**. Setelah manajer keuangan atau
direktur menekan **`Setujui`**, Anda kembali dan menekan **`Posting Pembayaran`** —
konfirmasinya menyebut rupiah dan rekeningnya, dan itu perhentian terakhir. Cetak
**`Cetak Bukti Pembayaran / Penerimaan`** (Form F/BP).

**6. Mengetik jurnal manual** — §10.2
Pemicu: nota kredit vendor, biaya admin bank, koreksi. **Keuangan › Jurnal** →
**`Tambah Jurnal`**, minimal dua baris, kaki tabel **`Seimbang ✓`**, **`Simpan`** →
`JV/…`. Jurnal yang tidak seimbang **tetap tersimpan** dan baru ditolak saat posting.
**Anda tidak memposting jurnal** — tombol **`Posting Jurnal`** butuh izin persetujuan
keuangan; minta `finance-manager` atau `direktur`. Cetak **`Cetak Voucher Jurnal`**
(Form F/VJ).

**7. Mengimpor rekening koran dan merekonsiliasi** — §10.4
Pemicu: rekening koran bulan lalu tiba. **Keuangan › Rekonsiliasi Bank**, tab **Impor**:
rekening, format, berkas, **pemetaan kolom** (tidak pernah ditebak — selalu isi **Kolom
saldo**), **`Pratinjau`**, lalu **`Impor rekening koran`** hanya bila tanpa penghalang
merah. Tab **Rekening Koran**: **`Cocokkan`** per baris, atau **`Tanpa padanan`** dengan
alasan. Layar ini tidak pernah membuat jurnal; biaya admin bank dibukukan lewat Jurnal
(butir 6) lalu dicocokkan. Tab **Rekonsiliasi**: baca kartu **"Periksa — kemungkinan
salah catat"** walau jembatannya tampak menutup.

**8. Kas kecil dan kasbon** — §10.5–§10.8
Pemicu: laci perlu dana, bon masuk, karyawan minta kasbon. Register laci di **Keuangan ›
Kas Kecil & Kasbon** (kode, akun 1-11xx, pemegang, float, plafon per bon). Harian di
**Keuangan › Kasir Kas Kecil**: **`Catat & Posting`** bon, **`Cairkan Kasbon`**,
**`Bukukan Pertanggungjawaban`** — **hanya pemegang laci** yang diterima server, tanpa
jalan pintas. Isi ulang: **`Minta Isi Ulang`** → **`Buat Draf Pembayaran`** →
**`Ajukan Isi Ulang`**; jumlahnya dihitung server, tidak diketik. Bon dan kasbon draf
menahan tutup buku pada tanggalnya.

**9. Kalender pajak dan ekspor pajak** — §10.10, §10.12
Pemicu: awal bulan. **Keuangan › Kalender Pajak** → **`Lengkapi kalender`** (48 baris
setahun; aman ditekan dua kali) → **`Catat {nama}`** per masa: jumlah, **NTPN** (wajib
saat mencatat tanggal setor), tanggal setor, tanggal lapor. Kolom *JV penyetoran* tidak
bisa dipakai — tulis nomor JV di Catatan. **`Cetak Register`** (Form F/KP). **Keuangan ›
Ekspor Pajak**: **`Unduh CSV`** e-Faktur / e-Bupot; baca kartu **"Tertahan"** — nomor
faktur kosong dan NPWP kurang dari 15 digit menahan dokumen. **`Terbitkan nomor bukti
potong`** bukan tombol Anda (butuh izin persetujuan).

**10. Akhir bulan: penyusutan → PSAK 115 → tutup periode** — §9.7, §10.9;
PANDUAN-ADMINISTRATOR §6
Pemicu: bulan berakhir, notifikasi *"Periode {label} belum
ditutup"*. Urutannya mengikat: payroll (disetujui direktur) → **Aset › Penyusutan**:
run-nya dibuat pemegang izin buat aset (manajer proyek atau administrator), Anda menekan
**`Posting Penyusutan`** → **Keuangan › Pengakuan Pendapatan**: **`Tambah Run PSAK 115`**,
**`Hitung Ulang`**, **`Posting Jurnal`** (angka dihitung ulang saat posting) → **Keuangan
› Periode Fiskal**: baca sebelas butir daftar periksa dari atas ke bawah — lima memblokir,
enam boleh diakui dengan alasan minimal 10 karakter — lalu **`Tutup Periode`**. **Pada
perusahaan ini penutupan tidak dapat dibatalkan** begitu run PSAK 115 terposting
(ADMINISTRATOR §6.1). Dokumen menggantung yang sudah **diajukan** harus **ditolak dulu**
oleh pemegang izin persetujuan sebelum Anda bisa mengubah tanggalnya (ADMINISTRATOR §6.4).

## 4. Yang akan menolak Anda

Kalimat di bawah adalah teks merah yang benar-benar dikirim server. Cari kalimat Anda,
baca pasalnya.

| Saat Anda | Penolakan (kata demi kata) | Pasal |
|---|---|---|
| memposting penerimaan yang Jumlah-nya nilai invoice, bukan uang masuk | *"Alokasi ({x}) harus sama dengan uang diterima ({y}) ditambah potongan pajak ({z})."* | §3.12 |
| menagih termin yang sudah punya invoice | *"An invoice already exists for termin …"* | §3.10 |
| mencentang retensi pada kontrak berpola termin retensi | *"Kontrak {kode} menagih retensinya lewat termin retensi pada jadwalnya sendiri; hapus potongan retensi pada invoice ini agar tidak tercatat dobel."* | §3.14 |
| menagih zona yang BAPP-nya *Nunggu perbaikan* | *"Zona {kode} — {nama} pada opname {kode} masih berstatus "Nunggu perbaikan"; pekerjaan di zona itu tidak dapat ditagihkan sampai BAPP-nya menyatakan selesai."* | §3.11a |
| membuat tagihan kedua atas PO yang sudah ditagih utuh | *"PO {kode} sudah memiliki tagihan atas seluruh pesanan; penerimaan yang datang setelahnya ditagihkan lewat penerimaan barangnya."* | §5.9 |
| memposting pembayaran yang belum disetujui | *"Pembayaran {kode} belum disetujui, jadi belum boleh diposting."* | §5.10 |
| mengubah alokasi setelah disetujui | *"Alokasi pembayaran {kode} berbeda dari yang disetujui. Ajukan ulang bila alokasinya berubah."* | §5.10 |
| mencampur tagihan vendor dan kewajiban akun | *"Satu pembayaran melunasi tagihan vendor ATAU kewajiban non-AP, tidak keduanya — pisahkan sesuai mutasi banknya."* | §5.10 |
| memposting apa pun ke bulan yang sudah ditutup | *"Periode fiskal {tahun}-{bulan} sudah ditutup; jurnal tidak dapat diposting ke dalamnya."* | §3.11, §10.2 |
| memposting ke tanggal yang kalendernya belum ada | *"Belum ada periode fiskal untuk {tanggal}. Buat kalender fiskal {tahun} lebih dulu di Keuangan › Periode Fiskal."* | §10.2 |
| jurnal Anda diposting orang lain padahal tidak seimbang | `Journal JV/2026/08/0009 is not balanced: debit 5000000 vs credit 4500000.` | §10.2 |
| memposting bon di laci orang lain | *"Hanya pemegang kas kecil KK-HO yang dapat memposting voucher — uang tunainya ada di laci pemegang, bukan di layar orang lain. …"* | §10.6 |
| mengubah jumlah isi ulang | *"Isi ulang kas kecil KK-HO harus tepat sebesar float dikurangi saldo laci dan kasbon beredar: 5000000 − 3800000 − 0 = 1200000, bukan 1500000."* | §10.8 |
| mencatat setoran pajak tanpa NTPN | *"Setoran PPh 21 masa Jun 2026 harus mencantumkan NTPN dari SSP/BPN-nya; tanpa NTPN pembayarannya tidak dapat diverifikasi."* | §10.10 |
| membalik penerimaan yang sudah dicocokkan | *"Pembayaran {kode} sudah dicocokkan dengan mutasi bank; buka dulu pencocokannya di rekonsiliasi bank sebelum membalik pembayaran ini."* | §3.12 |
| membatalkan invoice yang sudah dibayar | *"Invoice {kode} sudah menerima pembayaran {jumlah}; hanya invoice yang belum dibayar yang dapat dibatalkan …"* | §3.11 |
| memposting run PSAK 115 sebelum payroll | *"Payroll untuk periode 2026-06 belum diposting. Biaya bulan ini belum lengkap, … — posting payroll dan penyusutan lebih dulu."* | §10.9 |
| memposting penyusutan bulan yang terlewat | `Cannot run period 2026-05 at or before the last posted period 2026-06.` | §9.7 |
| menutup periode dengan blok yang gagal | *"Periode {label} belum dapat ditutup: {…}."* | ADMINISTRATOR §6.5 |

**Tombol yang tidak akan pernah muncul untuk Anda** (§14.2): **`Setujui`** dan **`Tolak`**
pada invoice, tagihan, dan pembayaran; **`Posting Jurnal`**; **`Terbitkan nomor bukti
potong`**; **`Buka Kembali`** periode. Semuanya milik `finance-manager` atau `direktur`.
Tombol yang **ada** untuk Anda dan tidak punya jalan kembali: **`Posting Pembayaran`**,
**`Catat pencairan`**, **`Posting Penyusutan`**, **`Posting Jurnal`** run PSAK 115,
**`Tutup Periode`** (§14.4). Obat untuk pembayaran yang salah adalah **`Balikkan
Pembayaran`** (status akhir **Dibalik**, dua jurnal berdampingan); obat untuk invoice
atau tagihan yang salah dan belum dibayar adalah **`Batalkan Dokumen`** (jurnal pembalik;
nomor faktur pajak **tidak** dilepas) — §3.11, §5.9.

## 5. Formulir yang Anda cetak

Tombol **`Cetak <nama formulir>`** membuka tab baru dan dialog cetak peramban — bukan
unduhan (§13.1). Nyalakan **"Grafik latar belakang"** di dialog cetak. Izin Anda
menggambar **31** tombol cetak; yang benar-benar Anda pakai:

| Formulir | Kode | Di mana tombolnya | Pasal |
|---|---|---|---|
| Lembar Verifikasi Tagihan | F/VT | halaman Tagihan Vendor (AP) | §5.9 |
| Bukti Pembayaran / Penerimaan | F/BP | halaman Pembayaran | §5.10 |
| Voucher Jurnal | F/VJ | halaman Jurnal | §10.2 |
| Register Kewajiban Pajak Masa | F/KP | layar Kalender Pajak, `Cetak Register` | §10.10 |
| Ekualisasi Pajak | F/EQ | layar Ekualisasi Pajak, `Cetak Ekualisasi` | §10.11 |
| Rekap Gaji | F/RG | halaman Payroll | §11.6 |
| Kartu Aset | F/KA | halaman Aset | §9.10 |

Sisanya — Pengajuan Cuti (F/PC), Daftar Hadir Harian (F/DH), Berita Acara Mobilisasi
Alat (F/BAM), dan 21 formulir Penjualan/Pengadaan/Subkontrak (Ringkasan Kontrak, Pesanan
Pembelian (Formulir Rumah), SPK Subkontraktor, Berita Acara Opname, Rekap Upah, …) —
tergambar karena izin lihat Anda, tetapi milik pekerjaan orang lain. **Invoice termin
tidak punya formulir rumah**: tombolnya **`PDF`**, mengunduh `invoice-{kode}.pdf` (§13.4).

**Aturan kejujuran** (§13.5): sel yang bergaris kosong berarti *tidak tercatat*, bukan
nol — Kartu Aset alat sewa mencetak **NILAI BUKU** bergaris, bukan Rp 0. Jangan mengisi
nol di kertas yang akan ditandatangani.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri, dan sidebar saya memuat tujuh kelompok.
- [ ] Saya sudah membuka **Tenggat** dan tahu keempat baris yang diawasi untuk peran saya.
- [ ] Saya tahu nama pemegang `finance-manager` dan `direktur` yang menyetujui dokumen saya.
- [ ] Saya sudah menagih satu termin lewat **`Tagih termin ini`** dan melihat nomor
      `INV/…`-nya.
- [ ] Saya sudah mencatat satu penerimaan `RCV/…` dengan kaki kartu
      **`Sesuai mutasi bank ✓`**.
- [ ] Saya sudah membuat satu tagihan `BIL/…` dari PO dan mencetak Lembar Verifikasi
      Tagihan.
- [ ] Saya sudah mengajukan satu pembayaran `PAY/…` dan tahu bahwa saya yang mempostingnya
      setelah disetujui.
- [ ] Saya sudah mengetik satu jurnal `JV/…` yang seimbang dan tahu siapa yang
      mempostingnya.
- [ ] Saya sudah membuka **Rekonsiliasi Bank** dan tahu kolom saldo harus selalu dipetakan.
- [ ] Saya sudah membuka **Periode Fiskal** dan membaca sebelas butir daftar periksa bulan
      yang masih terbuka.
- [ ] Saya sudah membaca §14.4 dan tahu tiga tombol saya yang tidak punya jalan kembali.
- [ ] Saya tahu bahwa tidak ada alarm untuk tagihan vendor jatuh tempo, dan bagaimana
      mengurutkannya.

## 7. Bila tersangkut

| Situasi | Tanyakan ke |
|---|---|
| Kata sandi, akun nonaktif, menu yang tidak ada, izin | **administrator** — PANDUAN §14.1; tidak ada layar ganti sandi |
| Dokumen saya menunggu `Setujui` / ditolak tanpa alasan yang saya pahami | **manajer keuangan** (`finance-manager`) atau **direktur** — baca kartu Riwayat Persetujuan dulu (§2.5) |
| Termin tidak muncul di Termin Siap Ditagih, milestone, BAST, retensi *"Belum ada BAST"* | **manajer proyek** (`project-manager`) — §3.10, §3.13 |
| Tagihan ditolak karena barang belum diterima penuh, PO tertutup, GRN bukan milik PO | **pengadaan** (`procurement`) dan **gudang** (`warehouse`) — §5.9, §5.6 |
| Bon atau kasbon di laci yang bukan milik saya | **pemegang laci itu** — tidak ada jalan pintas (§10.6) |
| Bulan tidak mau ditutup | layar Periode Fiskal sudah menyebut butir yang gagal; yang sudah diajukan minta **ditolak** oleh manajer keuangan — ADMINISTRATOR §6.3–§6.4 |
| Rencana tagih termin harus dipindah, JV ke kalender pajak, nota kredit vendor | tidak ada layarnya — **administrator** (PANDUAN §14.3) |

Eskalasi, dua baris: kirimkan **empat hal** ke administrator — alamat halaman lengkap,
kode dokumen, teks merah persis, tombol yang ditekan (PANDUAN §14.5). Bila jawabannya
"itu keputusan kebijakan" (ambang, pemisahan tugas, tarif), bawa ke direktur.

---

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** dengan satu kotak masuk untuk
> semua jenis dokumen; draf formulir bertahan di peramban saat sesi habis; catatan
> persetujuan diketik langsung di halaman tanpa dialog; ganti kata sandi sendiri. Sampai
> rilis itu tayang, panduan ini yang berlaku.
