# Onboarding minggu pertama — Manajer Keuangan (`finance-manager`)

**Peran akun:** `finance-manager` · **Akun demo:** `finance-manager@nusantara.test`
(Ratna Kusumawardani) · **Manual lengkap:** `docs/PANDUAN-PENGGUNA.md` bab 1, 2, **14**,
lalu bab dokumen yang Anda setujui — §3.11 (invoice), §5.9 (tagihan vendor), §5.10
(pembayaran), §10.2 (jurnal) — itulah baris Anda di PANDUAN §0.

> Panduan ini **bukan** manual. Ia jalan masuk ke manual: siapa Anda di sistem, apa yang
> Anda lihat hari pertama, pekerjaan yang benar-benar Anda kerjakan, aturan yang akan
> menolak Anda, dan daftar periksa. Setiap langkah menunjuk pasal PANDUAN yang
> menjelaskannya. Yang ditulis di sini hanyalah yang benar-benar ada di layar
> `finance-manager` hari ini.

---

## 1. Siapa Anda di sistem

Anda **menyetujui, dan tidak membuat apa pun.** Peran `finance-manager` memegang lima
izin: `fin.view`, **`fin.approve`**, `crm.view`, `prc.view`, `scm.view`
(PANDUAN-ADMINISTRATOR §3.2). Tidak ada izin buat, ubah, hapus, maupun posting — jadi
tidak ada tombol **`Tambah …`**, **`Ajukan`**, atau **`Posting Pembayaran`** di layar Anda
(PANDUAN §10.1, §14.2). Itu pemisahan tugas yang disengaja: petugas keuangan menyiapkan
dan membayar, Anda menilai.

Tiga dokumen yang Anda setujui (registri dokumen berpersetujuan, awalan `fin`): **Invoice
termin**, **Tagihan vendor**, **Pembayaran keluar**. Tiga tombol lain yang juga bergerbang
izin persetujuan keuangan: **`Posting Jurnal`** (§10.2), **`Terbitkan nomor bukti
potong`** (§10.12), dan **membuka kembali periode fiskal** (ADMINISTRATOR §6.2). Direktur
memegang izin yang sama dan bisa menggantikan Anda; admin juga.

Tempat Anda pada rantai proses (ANALISIS-PROSES-BISNIS §1):

| Rantai | Yang sampai ke Anda | Dari siapa | Setelah `Setujui` |
|---|---|---|---|
| Penawaran → kas | `INV/…` invoice termin Diajukan | petugas keuangan (`finance`) | jurnal piutang & pendapatan terposting; termin dicap Ditagih |
| Permintaan → pembayaran | `BIL/…` tagihan vendor Diajukan | petugas keuangan | hutang vendor terposting (pencocokan tiga arah lolos) |
| Permintaan → pembayaran | `PAY/…` pembayaran keluar Diajukan | petugas keuangan | petugas keuangan menekan `Posting Pembayaran` |
| Subkontrak & mandor | `BIL/…` dari opname yang disetujui direktur | petugas keuangan | sama seperti tagihan vendor |

Catatan dari PANDUAN-ADMINISTRATOR §3.2: di erp1 peran ini sempat **tanpa pemegang**,
sehingga seluruh persetujuan tagihan menumpuk di login direktur. Anda ada supaya itu
tidak terjadi lagi. Jangan biarkan peran ini kosong.

## 2. Hari pertama

**Masuk.** Buka https://erp1.pi2.co.id, halaman **"Masuk ke akun Anda"**, isi **Email**
dan **Kata sandi** yang diberikan administrator, tekan **`Masuk`** (PANDUAN §1.1). Sesi
berumur 12 jam (§1.2).

**Sidebar Anda** memuat **lima kelompok**; kelompok lain tidak digambar (§1.4):

| Kelompok | Layar yang akan Anda buka |
|---|---|
| Ringkasan | **Dasbor** · Tenggat · Kalender |
| **Keuangan** (20 layar, semua baca-dan-setujui) | Invoice Termin (AR) · Tagihan Vendor (AP) · **Pembayaran** · Jurnal · Termin Siap Ditagih · Piutang Retensi · Periode Fiskal · Laporan Keuangan · Buku Besar · Rekonsiliasi Bank · Ekspor Pajak · Ekualisasi Pajak |
| Penjualan (lihat saja) | Kontrak — jadwal termin, pola retensi, status kontrak yang ditagih |
| Pengadaan (lihat saja) | Pesanan (PO) · Baris PO Terbuka — untuk memeriksa tagihan vendor |
| Subkontrak (lihat saja) | SPK Subkon · Opname Subkon · Opname Mandor — sumber tagihan subkon |

**Dasbor Anda**: ubin **Piutang belum tertagih**, **Hutang belum dibayar**, **Saldo bank**,
**Termin siap ditagih**; kartu **Menunggu persetujuan Anda** — untuk Anda ia memuat
**Invoice termin** dan **Tagihan vendor** yang Diajukan, terbaru di atas, paling banyak
10 baris; kartu **Piutang jatuh tempo terdekat** dan **Kalender Acara**.

> **Pembayaran keluar tidak tampil di kartu itu** (§1.7). Ia hanya sampai lewat lonceng
> dan lewat **Keuangan › Pembayaran** yang Anda saring ke status **Diajukan**. Di produksi,
> dokumen yang paling lama menunggu justru yang tidak tampil di kartu (ANALISIS §0).
> Buka daftar Pembayaran setiap pagi, jangan hanya kartu dasbor.

**Lonceng dan Tenggat.** Peran Anda **tidak menerima satu pun baris Tenggat** — kesembilan
belas pengawas tanggal menyasar izin buat/ubah, bukan izin setujui (§1.7). Yang Anda
terima lewat lonceng adalah pemberitahuan **pengajuan**: invoice termin yang diajukan
memberi tahu manajer keuangan (§3.11), pembayaran keluar menunggu pemegang `fin.approve`
(§5.10), dan tagihan vendor. Lonceng diperiksa tiap 90 detik dan **menjadi basi** —
menyetujui sebuah dokumen tidak menghapus pemberitahuan "menunggu" di kotak masuk
penyetuju lain (§1.6).

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang izinnya tidak Anda punya tidak abu-abu — ia tidak ada.
2. `Ubah` dan `Hapus` hilang begitu dokumen diajukan; **`Tolak`** Anda-lah jalan kembalinya.
3. Yang menekan `Ajukan` tidak boleh menekan `Setujui` — Anda tidak pernah mengajukan.
4. Yang terposting tidak bisa dibatalkan — hanya dibalik, dua jurnal berdampingan.
5. Anda tidak bisa mengganti kata sandi sendiri — minta administrator (bab 14).
6. Sesi 12 jam; isian yang belum disimpan hilang saat sesi habis.

## 3. Pekerjaan Anda

Setiap butir: pemicu → layar → yang Anda periksa → yang terjadi setelah tombol → pasal.

**1. Membaca kotak masuk pagi** — PANDUAN §1.7, §2.5
Pemicu: mulai hari. **Dasbor › Menunggu persetujuan Anda** untuk invoice dan tagihan;
**Keuangan › Pembayaran**, saring **Status = Diajukan**, untuk pembayaran keluar. Bila
kartu berbunyi *"Tidak ada dokumen yang dapat ditampilkan."* dan menyebut sumber yang
gagal dimuat, **daftar yang pendek bukan bukti tidak ada yang menunggu** — muat ulang.
Klik baris untuk membuka dokumennya.

**2. Menyetujui invoice termin** — §3.11, §3.11a, §3.14
Pemicu: `INV/…` berstatus Diajukan. Periksa: kontrak **Disetujui**; termin yang ditagih
belum bercap Ditagih; kolom **Keterangan** — bila ada teks
`[Konfirmasi: milestone "…" belum tercapai — tetap ditagih.]`, petugas menagih sebelum
milestone dan itu tercetak di invoice; strip **DPP · PPN · Retensi ditahan · Potongan
uang muka · Denda · Total**; satu pola retensi per kontrak. Tekan **`Setujui`** (catatan
opsional) atau **`Tolak`** (alasan wajib). **`Setujui` melakukan tiga hal sekaligus**:
memposting jurnal piutang dan pendapatan, membuat baris piutang retensi, dan mencap
termin **Ditagih** — invoice pertama yang disetujui mengunci jadwal termin kontrak
selamanya (§14.4). Setelah itu satu-satunya obat adalah `Batalkan Dokumen` oleh petugas
keuangan, dan hanya selama belum dibayar.

**3. Menyetujui tagihan vendor** — §5.9, §8.5; ADMINISTRATOR §8.6
Pemicu: `BIL/…` Diajukan. Minta **Lembar Verifikasi Tagihan** (F/VT) yang sudah
ditandatangani pemeriksa. **`Setujui` adalah pencocokan tiga arah** — server menolak bila
barang PO belum diterima seluruhnya, belum ada penerimaan sama sekali (menutup PO tidak
meloloskan yang ini), uang muka PO masih menunggu, vendor non-PKP memungut PPN, atau
PPh dipotong tanpa **Jenis PPh**. Kategori biaya RAP diturunkan server (opname subkon →
Subkon, PO/GRN → Material, PPK → Alat) dan tidak bisa dipilih siapa pun. Setelah
Setujui, hutang vendor terbentuk di buku besar.

**4. Menyetujui pembayaran keluar** — §5.10, §10.8, §11.7
Pemicu: `PAY/…` Diajukan (dari daftar, bukan kartu). Periksa kartu **`Alokasikan ke
tagihan terbuka`** atau **`Bayar kewajiban non-AP (gaji, pajak, BPJS)`** — satu
pembayaran hanya salah satu — dan kaki kartu **`Sesuai mutasi bank ✓`**. Pada isi ulang
kas kecil, tabel **"Bon & kasbon yang akan diganti"** berkata *"periksa bukti fisiknya
sebelum menyetujui — inilah yang dibayar uang bank ini"*; jumlahnya dihitung server dari
float − saldo laci − kasbon beredar. Tekan **`Setujui`**, atau **`Tolak`** dengan alasan —
bantuannya: *"Petugas yang menyiapkan harus tahu apa yang perlu diperbaiki."* Setelah
Setujui, **petugas keuangan** menekan `Posting Pembayaran`; Anda tidak bisa. Bila
alokasi diubah setelah Anda menyetujui, server menuntut pengajuan ulang.

**5. Memposting jurnal manual** — §10.2
Pemicu: `JV/…` Draf yang diketik petugas keuangan. Buka jurnalnya, periksa kaki tabel
**`Seimbang ✓`** dan akun yang bisa diposting, tekan **`Posting Jurnal`** — konfirmasi
*"Posting jurnal ini ke buku besar? Jurnal terposting tidak dapat diubah."* Jurnal tidak
punya kartu Riwayat Persetujuan; "Dibuat oleh" / "Diposting oleh" ada di kartu Informasi.
Koreksi jurnal terposting hanyalah jurnal kedua yang berlawanan.

**6. Menerbitkan nomor bukti potong** — §10.12
Pemicu: **Keuangan › Ekspor Pajak**, tab **e-Bupot**, kartu **"Tertahan"** berbunyi
*"Nomor bukti potong untuk BIL/… belum diterbitkan…"*. Tombol **`Terbitkan nomor bukti
potong`** hanya digambar untuk pemegang izin persetujuan keuangan. Konfirmasi: *"Nomor
diterbitkan sekali untuk masa ini dan tidak berubah lagi. Tagihan yang sudah bernomor
dilewati."* Penghalang lain (NPWP vendor, kode objek pajak, jenis PPh) dibereskan petugas
keuangan pada dokumennya.

**7. Menolak untuk membuka kembali** — §2.5; ADMINISTRATOR §6.4
Pemicu: dokumen yang sudah **Diajukan** salah tanggal, salah alokasi, atau menahan tutup
buku sebagai dokumen menggantung. Dokumen Diajukan tidak bisa disunting maupun dihapus
siapa pun; **`Tolak`** (alasan wajib) mengembalikannya ke keadaan bisa diubah. Menolak
adalah alat kerja, bukan hukuman — pembayaran yang ditolak kembali dengan alokasinya utuh
dan banner yang menyebut alasan Anda.

**8. Membuka kembali periode fiskal** — ADMINISTRATOR §6.7, §6.1
Pemicu: petugas keuangan meminta bulan yang sudah ditutup dibuka. **Keuangan › Periode
Fiskal** — membuka butuh `fin.approve` (Anda), menutup butuh `fin.post` (petugas
keuangan); pemisahan itu disengaja. Alasan **minimal 10 karakter**, tercatat permanen;
pembukaan berjalan **terbaru dulu** (*"Buka periode terbaru lebih dulu."*). **Bulan yang
sudah diukur run PSAK 115 terposting tidak bisa dibuka kembali, selamanya** — dan itu
berlaku di erp1 hari ini. Tombolnya dinonaktifkan dengan kalimat penolakannya di sebelahnya.

**9. Membaca, bukan mengetik** — §10.3, §10.4, §10.11; ADMINISTRATOR §6.3
**Buku Besar** (klik baris membuka jurnalnya), **Laporan Keuangan**, tab **Rekonsiliasi**
di Rekonsiliasi Bank — bacalah kartu **"Periksa — kemungkinan salah catat"** walau
lencananya `Cocok sepenuhnya`; **Ekualisasi Pajak** — baris *"Selisih belum terjelaskan"*
tidak pernah dipaksa nol. Mengimpor rekening koran dan mencocokkan baris butuh izin buat;
itu pekerjaan petugas keuangan. Sebelum tutup buku, layar **Periode Fiskal** menampilkan
sebelas butir daftar periksa; lima memblokir (periode berakhir, periode sebelumnya
ditutup, tidak ada dokumen menggantung, run PSAK 115 terposting, neraca saldo seimbang).

## 4. Yang akan menolak Anda

| Saat Anda | Penolakan (kata demi kata) | Pasal |
|---|---|---|
| menyetujui dokumen yang masih Draf | `Cannot approve document PO/2026/VIII/0003 while status is draft.` | §2.10 |
| menyetujui invoice bertanggal di bulan tertutup | *"Periode fiskal {tahun}-{bulan} sudah ditutup; jurnal tidak dapat diposting ke dalamnya."* | §3.11 |
| menyetujui invoice atas kontrak yang belum aktif | `Contract {kode} is {status}; only approved contracts can be billed.` | §3.11 |
| menyetujui invoice beretensi pada kontrak berpola termin retensi | *"Kontrak {kode} menagih retensinya lewat termin retensi pada jadwalnya sendiri; hapus potongan retensi pada invoice ini agar tidak tercatat dobel."* | §3.14 |
| menyetujui tagihan PO yang barangnya belum lengkap | *"Tagihan atas {PO} hanya dapat disetujui setelah barang diterima seluruhnya. Terima sisa barang atau tutup PO terlebih dahulu."* | §5.9 |
| menyetujui tagihan PO tanpa satu pun penerimaan | *"Tagihan atas {PO} hanya dapat disetujui setelah barang diterima: belum ada penerimaan barang yang diposting atas pesanan ini, …"* | §5.9 |
| menyetujui tagihan yang memotong PPh tanpa jenisnya | *"Tagihan yang memotong PPh harus menyebut jenis PPh-nya; pilih 'Jenis PPh dipotong' agar potongannya masuk ke akun hutang pajak yang benar."* | §5.9 |
| memposting jurnal yang tidak seimbang | `Journal JV/2026/08/0009 is not balanced: debit 5000000 vs credit 4500000.` | §10.2 |
| memposting jurnal ke akun kelompok | `COA account 1-1100 (Kas) is a group and cannot be posted to.` | §10.2 |
| membuka periode di bawah periode tertutup lain | *"Buka periode terbaru lebih dulu."* | ADMINISTRATOR §6.7 |
| membuka bulan yang sudah diukur PSAK 115 | *"Run yang sudah diposting tidak dapat dihitung ulang, jadi periode ini tidak dapat dibuka lagi — koreksi yang ditemukan hari ini dibukukan hari ini."* | ADMINISTRATOR §6.1 |

**Maker-checker tidak akan pernah menyebut nama Anda**: penolakan *"{Dokumen} {KODE}
diajukan oleh {Nama}; dokumen tidak boleh disetujui oleh pengajunya sendiri"* menyasar
penekan `Ajukan` terakhir, dan peran Anda tidak memegang tombol itu (§2.5).

**Tombol yang tidak akan pernah muncul untuk Anda** (§14.2): **`Tambah …`** apa pun,
**`Ajukan`**, **`Posting Pembayaran`**, **`Balikkan Pembayaran`**, **`Batalkan Dokumen`**,
**`Catat Faktur Pajak`**, **`Tutup Periode`**, **`Impor rekening koran`**, dan **`Bayar
Retensi`** di halaman SPK (butuh izin posting subkontrak **dan** persetujuan keuangan
sekaligus — hanya admin, §8.7). Tombol yang tidak ada berarti izin, bukan kerusakan.

## 5. Formulir yang Anda cetak

Izin lihat Anda menggambar **26** tombol cetak (§13.1); yang menyertai dokumen yang Anda
setujui:

| Formulir | Kode | Di mana tombolnya | Pasal |
|---|---|---|---|
| Lembar Verifikasi Tagihan | F/VT | halaman Tagihan Vendor (AP) | §5.9 |
| Bukti Pembayaran / Penerimaan | F/BP | halaman Pembayaran | §5.10 |
| Voucher Jurnal | F/VJ | halaman Jurnal | §10.2 |
| Register Kewajiban Pajak Masa | F/KP | layar Kalender Pajak, `Cetak Register` | §10.10 |
| Ekualisasi Pajak | F/EQ | layar Ekualisasi Pajak, `Cetak Ekualisasi` | §10.11 |

Dua puluh satu sisanya — Ringkasan Kontrak (F/RK), Pesanan Pembelian (Formulir Rumah)
(F/PO), SPK Subkontraktor (F/SP), Berita Acara Opname (F/BO), Rekap Upah, dan formulir
Penjualan/Pengadaan/Subkontrak lain — tergambar karena izin lihat, berguna sebagai bukti
saat memeriksa tagihan. Invoice termin dan PO punya tombol **`PDF`** tersendiri (§13.4).

**Aturan kejujuran** (§13.5): sel bergaris kosong berarti *tidak tercatat*, bukan nol.
Lembar yang ditandatangani dengan angka karangan lebih buruk daripada garis kosong.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri, dan sidebar saya memuat lima kelompok.
- [ ] Saya tahu nama petugas keuangan (`finance`) yang mengajukan dokumen kepada saya.
- [ ] Saya sudah membuka kartu **Menunggu persetujuan Anda** dan tahu ia hanya memuat
      invoice dan tagihan — bukan pembayaran.
- [ ] Saya sudah menyaring **Keuangan › Pembayaran** ke status Diajukan, satu kali pagi ini.
- [ ] Saya sudah menyetujui satu invoice termin dan membaca kartu Riwayat Persetujuannya.
- [ ] Saya sudah menolak satu dokumen dengan alasan tertulis, dan melihatnya kembali
      ke Draf.
- [ ] Saya sudah menyetujui satu tagihan vendor yang lembar F/VT-nya sudah ditandatangani.
- [ ] Saya sudah memposting satu jurnal `JV/…` dan tahu ia tidak bisa diubah lagi.
- [ ] Saya sudah membuka **Periode Fiskal** dan tahu tombol mana milik saya (buka) dan
      mana milik petugas keuangan (tutup).
- [ ] Saya tahu bahwa peran saya tidak menerima baris Tenggat, dan mengapa.
- [ ] Saya sudah membaca §14.4 dan tahu bahwa `Setujui` invoice adalah posting.

## 7. Bila tersangkut

| Situasi | Tanyakan ke |
|---|---|
| Kata sandi, akun nonaktif, izin, menu yang tidak ada | **administrator** — PANDUAN §14.1 |
| Isi dokumen yang saya nilai salah | **`Tolak`** dengan alasan — petugas keuangan (`finance`) memperbaiki dan mengajukan ulang |
| Tagihan vendor ditolak server karena barang / PO | **pengadaan** (`procurement`) dan **gudang** (`warehouse`) — §5.9, §5.6 |
| Invoice tergantung milestone, BAST, zona BAPP | **manajer proyek** (`project-manager`) — §3.10, §3.11a |
| Bulan tidak mau ditutup; dokumen menggantung | tolak yang sudah diajukan, lalu petugas keuangan; blok keras lain: **administrator** — ADMINISTRATOR §6.3–§6.4 |
| Ambang persetujuan, pemisahan tugas, tarif pajak | **direktur** memutuskan, **administrator** menyetel (Pengaturan) — §14.1 |

Eskalasi, dua baris: kirimkan **empat hal** ke administrator — alamat halaman lengkap,
kode dokumen, teks merah persis, tombol yang ditekan (PANDUAN §14.5). Keputusan yang
bukan soal layar (bayar siapa dulu, buka bulan atau tidak) adalah milik direktur.

---

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** dengan satu kotak masuk untuk
> semua jenis dokumen (termasuk pembayaran keluar); draf formulir bertahan di peramban
> saat sesi habis; catatan persetujuan diketik langsung di halaman tanpa dialog; ganti
> kata sandi sendiri. Sampai rilis itu tayang, panduan ini yang berlaku.
