# Onboarding minggu pertama — Direktur (`direktur`)

**Peran akun:** `direktur` · **Akun demo:** `direktur@nusantara.test` (Budi Santoso) ·
**Manual lengkap:** `docs/PANDUAN-PENGGUNA.md` bab 1, 2, **14**, lalu bab dokumen yang
Anda setujui, dan **15** untuk tanda tangan MK/Owner — itulah baris Anda di PANDUAN §0.

> Panduan ini **bukan** manual. Ia jalan masuk ke manual: siapa Anda di sistem, apa yang
> Anda lihat hari pertama, pekerjaan yang benar-benar Anda kerjakan, aturan yang akan
> menolak Anda, dan daftar periksa. Setiap langkah menunjuk pasal PANDUAN yang
> menjelaskannya. Yang ditulis di sini hanyalah yang benar-benar ada di layar `direktur`
> hari ini.

---

## 1. Siapa Anda di sistem

Anda **menyetujui, dan tidak membuat, mengubah, maupun membukukan apa pun.** Peran
`direktur` memegang izin **lihat** dan **setujui** pada keempat belas modul, ditambah dua
izin direktur `prc.approve-director` dan `scm.approve-director` — 30 izin, tanpa satu pun
`create`, `update`, `delete`, atau `post` (PANDUAN-ADMINISTRATOR §3.2). Kotak masuk
persetujuan adalah hari kerja Anda.

Yang bisa Anda setujui: **seluruh 28 jenis dokumen berpersetujuan** — dari Penawaran
sampai Pengajuan cuti. Pada susunan peran bawaan, untuk penawaran, BOQ/RAB, RAP, PR/PO,
keputusan pemenang, SPK/addendum/opname subkon, cuti, payroll, dan opname stok, **Anda
dan admin adalah satu-satunya penyetuju** (ADMINISTRATOR §3.2). Untuk dokumen proyek,
engineering, dan mutu, manajer proyek ikut memegang izin setujui; untuk dokumen keuangan,
manajer keuangan.

Tempat Anda pada empat rantai proses (ANALISIS-PROSES-BISNIS §1):

| Rantai | Gerbang yang Anda pegang | Dari siapa | Ke siapa setelah `Setujui` |
|---|---|---|---|
| Penawaran → kas | `QTN/…` penawaran, `CCO/…` tambah-kurang, `Aktifkan Kontrak`, `BOQ/…`, `RAP/…` | sales, estimator | sales menandai menang; keuangan menagih termin |
| Permintaan → pembayaran | `PR/…`, `PO/…` (**≥ Rp 100 juta hanya Anda**), `AWD/…` keputusan pemenang (tingkat 2–3), `PPK/…` | pengadaan | gudang menerima barang; keuangan menagih dan membayar |
| Subkontrak & mandor | `SPK/…` (**≥ Rp 200 juta hanya Anda**), addendum, opname subkon, BAST subkon, SP3 & opname mandor | manajer proyek | keuangan menagihkan opname |
| Lapangan → progres → tagihan | baseline, `IPP/…`, inspeksi mutu, `OPN/…` opname owner, izin IKL/ILB/IMK, `BAST/…` | site manager, manajer proyek | keuangan — BAST II melepas retensi dan **menutup proyek** |
| Keuangan & SDM | invoice termin, tagihan vendor, pembayaran keluar, `PYR/…` payroll, cuti | keuangan, SDM | keuangan memposting pembayaran |

Yang perlu Anda tahu dari analisis produksi: gerbangnya bekerja, tetapi dokumen
**berhenti setelah gerbang** karena langkah berikutnya tidak berpemilik — pembayaran
menunggu persetujuan 33 hari tanpa tampil di kartu dasbor (ANALISIS §0, §2). Kotak masuk
Anda yang lengkap adalah satu-satunya pendorong hari ini.

## 2. Hari pertama

**Masuk.** Buka https://erp1.pi2.co.id, halaman **"Masuk ke akun Anda"**, isi **Email**
dan **Kata sandi** yang diberikan administrator, tekan **`Masuk`** (PANDUAN §1.1). Sesi
berumur 12 jam (§1.2).

**Sidebar Anda** memuat **seluruh empat belas kelompok** — izin lihat Anda menyeluruh
(§1.4). Yang penting di minggu pertama:

| Kelompok | Layar yang akan Anda buka |
|---|---|
| Ringkasan | **Dasbor** · **Tenggat** · Kalender |
| Penjualan | Penawaran · Kontrak · Pekerjaan Tambah-Kurang · Jaminan & Asuransi |
| Estimasi | BOQ / RAB · RAP |
| Pengadaan | Permintaan (PR) · Pesanan (PO) · Keputusan Pemenang · PPK Alat & Jasa |
| Subkontrak | SPK Subkon · Addendum SPK · Opname Subkon · BAST Subkon · SP3 Mandor · Opname Mandor |
| Keuangan | Invoice Termin (AR) · Tagihan Vendor (AP) · **Pembayaran** · Jurnal · Periode Fiskal · Laporan Keuangan · Ekspor Pajak |
| Proyek | BAST · Opname Owner (OPN) · EVM & Baseline · Izin Kerja (IKL) · Izin Lembur (ILB) · Izin Material (IMK) · Register Defect (Punch List) |
| Engineering · Mutu (QA/QC) | Ijin Pelaksanaan (IPP) · Inspeksi Mutu (QCI) · Ketidaksesuaian (NCR) |
| Persediaan | Opname |
| SDM & Payroll | Payroll · Cuti & Izin |
| Sistem | Pengguna · Peran & Hak Akses — terlihat, tetapi mengubahnya butuh izin admin |

**Dasbor Anda** menampilkan enam ubin — **Proyek berjalan**, **Piutang belum tertagih**,
**Hutang belum dibayar**, **Saldo bank**, **Termin siap ditagih**, **Tiket aktif** — dan
kartu **Menunggu persetujuan Anda**, **Kalender Acara**, progres proyek, **Piutang jatuh
tempo terdekat**, **Tiket layanan aktif**, **Stok di bawah minimum**.

> **Kartu "Menunggu persetujuan Anda" hanya memuat 11 dari 28 jenis dokumen** — Penawaran,
> BOQ / RAB, RAP, Permintaan (PR), Pesanan (PO), SPK subkon, Opname subkon, Opname stok,
> Invoice termin, Tagihan vendor, Payroll — paling banyak 10 baris (§1.7). **Tujuh belas
> jenis lainnya** — pembayaran keluar, pekerjaan tambah-kurang, keputusan pemenang, PPK,
> addendum SPK, BAST subkon, SP3 dan opname mandor, baseline, IPP, inspeksi mutu, opname
> owner, BAST, ketiga izin lapangan, dan cuti — hanya sampai lewat lonceng dan lewat layar
> daftarnya masing-masing yang Anda saring ke status **Diajukan**.

**Lonceng dan Tenggat.** Layar **Ringkasan › Tenggat** mengawasi **dua** hal untuk peran
Anda (§1.7): **Kontrak** mendekati tanggal *berakhir* (30 hari) dan **Jaminan & asuransi**
mendekati *berakhir* (30 hari). Lewat lonceng Anda juga menerima setiap pengajuan dokumen
yang boleh Anda setujui (penawaran yang diajukan memberi tahu **semua direktur**, §3.4),
*"Keputusan eksternal tercatat: …"* dari kartu MK/Owner (§15.4), dan — karena Anda
memegang `core.approve` — **alarm cadangan** setiap pagi; di erp1 hari ini judulnya
*"Salinan cadangan offsite belum dikonfigurasi"*, dan itu memang alarmnya, bukan
kerusakan (ADMINISTRATOR §5.6, §10.2). Alarm sistem berlencana **"—"**.

**Enam kalimat untuk semua orang** (PANDUAN §0), satu baris masing-masing:

1. Menu yang izinnya tidak Anda punya tidak abu-abu — ia tidak ada.
2. `Ubah` dan `Hapus` hilang begitu dokumen diajukan; **`Tolak`** Anda-lah jalan kembalinya.
3. Pengaju tidak boleh menyetujui dokumennya sendiri — itu melindungi tanda tangan Anda.
4. Yang terposting tidak bisa dibatalkan — hanya dibalik, dua jurnal berdampingan.
5. Anda tidak bisa mengganti kata sandi sendiri — minta administrator (bab 14).
6. Sesi 12 jam; jendela persetujuan yang belum dikirim hilang saat sesi habis.

## 3. Pekerjaan Anda

Setiap butir: pemicu → layar → yang Anda periksa → yang terjadi setelah tombol → pasal.

**1. Kotak masuk pagi, dua pintu** — PANDUAN §1.7, §2.5
Pemicu: mulai hari. Pintu pertama **Dasbor › Menunggu persetujuan Anda** (11 jenis). Pintu
kedua: layar daftar dari 17 jenis lain, disaring **Status = Diajukan** — minimal
**Keuangan › Pembayaran** dan **Pengadaan › Keputusan Pemenang** setiap hari. Bila kartu
berbunyi *"Tidak ada dokumen yang dapat ditampilkan."*, ada sumber yang gagal dimuat dan
daftarnya belum lengkap.

**2. Setujui, atau Tolak dengan alasan** — §2.5, §14.4
Tiga tombol yang sama di semua dokumen: **`Setujui`** (*Catatan persetujuan* opsional),
**`Tolak`** (*Alasan penolakan* **wajib**). Sebelum menekan, bacalah **Riwayat
Persetujuan** di kolom kanan — siapa yang mengajukan dan kapan. Untuk beberapa dokumen
**`Setujui` adalah postingnya**: invoice termin memposting jurnal dan mengunci jadwal
termin (§3.11); tagihan vendor membentuk hutang (§5.9); **payroll memposting seluruh run**
(§11.6); **opname stok menggerakkan stok seketika** (§6.7); **BAST II menutup proyek**
tanpa konfirmasi kedua (§7.11). Tidak satu pun punya tombol batal-setuju.

**3. PO dan SPK bernilai besar: hanya Anda** — §5.6, §8.2
Pemicu: `PO/…` senilai **Rp 100.000.000 atau lebih**, atau `SPK/…` senilai **Rp
200.000.000 atau lebih**, dicap saat pengajuan sebagai perlu persetujuan direktur. Penyetuju
lain ditolak dengan kalimat yang menyebut nilai, ambang, dan izin `prc.approve-director`.
Gerbang prakualifikasi, harga, dan anggaran sudah dilewati pengaju saat `Ajukan`; **alasan
override prakualifikasi** hanya tercap bila gerbangnya benar-benar memblokir — kolom
kosong berarti vendor sehat (ADMINISTRATOR §8.5). PO/SPK yang lahir dari RFQ menuntut
Keputusan Pemenang yang sudah Disetujui (butir 4).

**4. Keputusan Pemenang: tangga berjenjang** — §5.12
Pemicu: `AWD/…` Diajukan. Jumlah penyetuju **berbeda** mengikuti nilai yang diputuskan:
di bawah Rp 100 juta satu; **Rp 100 juta sampai di bawah Rp 1 miliar dua**, tingkat kedua
dari direktur; **Rp 1 miliar ke atas tiga**, tingkat kedua dan ketiga dari direktur. Batas
dibaca ke atas — award persis Rp 100 juta sudah butuh dua. Tombol `Setujui` tetap tampil
sampai tingkat terakhir; panel detail menyebut **Persetujuan masuk** dan **Tingkat
persetujuan diperlukan**. Anda tidak bisa mengisi dua tingkat. Nilai di atas RAB wajib
beralasan; nilai yang berubah dari penawaran terakhir menuntut BA Negosiasi.

**5. Penjualan dan estimasi** — §3.4, §3.6, §3.7, §4.3, §4.4
Penawaran: `Setujui`; **`Tandai Menang`** oleh sales diam-diam menerbitkan kontrak (§3.4).
Kontrak: **`Aktifkan Kontrak`** adalah tombol Anda (izin `crm.approve`), memeriksa jadwal
termin tepat 100%, status langsung Disetujui, **tidak tunduk pemisahan tugas** dan tidak
ada tombol kembali (§3.6). Pekerjaan tambah-kurang: `Setujui` CCO mengubah nilai atau
tanggal kontrak; addendum waktu yang disetujui bersifat final (§3.7). BOQ yang disetujui
**membekukan harganya selamanya**; RAP yang disetujui — sekalipun Rp 0 — menjadi gerbang
anggaran setiap PO proyek itu (§4.3, §4.4).

**6. Lapangan, engineering, mutu** — §7.11, §7.14, §16.5, §17.2, §15
BAST I mengunci seluruh entri lapangan; BAST II punya prasyarat wajib (BAST I disetujui,
tidak ada temuan kritis/mayor terbuka) dan peringatan yang hanya bisa dilewati dengan
alasan **minimal 20 karakter** — dan **tidak ada layar yang memperlihatkannya sebelum
tombol Setujui ditekan**; buka Register Defect, petak **"Menahan BAST II"**, lebih dulu.
Opname owner `OPN/…` diperiksa ulang plafonnya saat Setujui. IPP dan inspeksi mutu
biasanya disetujui manajer proyek; Anda cadangannya. Untuk MK/Owner, kartu **Persetujuan
Eksternal (MK/Owner)** pada laporan harian, CCO, dan IKL: **`Terbitkan Tautan`** (salin
sekarang — hanya ditampilkan sekali) atau **`Catat Tanda Tangan Fisik`** setelah scannya
dilampirkan (§15.1, §15.3).

**7. Keuangan dan SDM** — §3.11, §5.9, §5.10, §10.2, §11.4, §11.6; ADMINISTRATOR §6.7
Anda memegang izin persetujuan keuangan yang sama dengan manajer keuangan: invoice
termin, tagihan vendor (pencocokan tiga arah), pembayaran keluar (setelahnya petugas
keuangan yang memposting), **`Posting Jurnal`** manual, **`Terbitkan nomor bukti potong`**
(§10.12), dan **membuka kembali periode fiskal** — alasan minimal 10 karakter, terbaru
dulu, dan bulan yang sudah diukur PSAK 115 tidak bisa dibuka selamanya. Payroll: `Setujui`
memposting run bertanggal hari terakhir periode dan **tidak membayar siapa pun** —
transfernya dokumen Pembayaran tersendiri (§11.7). Cuti: saldo diperiksa saat Ajukan dan
saat Setujui (§11.4).

**8. Tenggat dan alarm** — §1.7, §3.8; ADMINISTRATOR §5.6, §5.10
Setiap pagi 08.30 pengawas tenggat berjalan; baris **Kontrak** dan **Jaminan & asuransi**
Anda hilang dari Tenggat hanya ketika statusnya diubah pemegang izin ubah penjualan.
Alarm cadangan dibaca hari ini berbunyi lagi besok pagi sampai sebabnya dibereskan
administrator — menandai dibaca bukan menyelesaikan.

## 4. Yang akan menolak Anda

| Saat Anda | Penolakan (kata demi kata) | Pasal |
|---|---|---|
| menyetujui dokumen yang masih Draf | `Cannot approve document PO/2026/VIII/0003 while status is draft.` | §2.10 |
| menyetujui PO/SPK dari RFQ tanpa award | *"{Dokumen} {kode} berasal dari RFQ {kode} namun keputusan pemenang (award) untuk vendor ini belum ada atau belum disetujui; terbitkan dan setujui keputusan pemenang dulu sebelum menyetujui {Dokumen}."* | §5.12 |
| menekan `Setujui` kedua kali pada award berjenjang | *"{Dokumen} {kode} sudah Anda setujui pada tingkat sebelumnya; persetujuan berjenjang menuntut penyetuju yang BERBEDA di tiap tingkat. Minta tingkat berikutnya kepada pengguna lain."* | §5.12 |
| menyetujui award di atas RAB tanpa alasan | *"Nilai keputusan melampaui RAB; alasan deviasi (deviation_reason) wajib diisi karena memutuskan di atas nilai wajar harus dapat dipertanggungjawabkan."* | §5.12 |
| menyetujui BAST II dengan prasyarat wajib gagal | *"BAST II {kode} belum dapat disetujui — {daftar item}."* | §7.11 |
| menyetujui BAST I selagi NCR terbuka | *"BAST I {kode} belum dapat disetujui — {n} NCR masih terbuka ({daftar NCR}); verifikasi …"* | §17.3 |
| menyetujui opname owner yang melampaui plafon | *"Volume kumulatif item "{uraian}" {x} {satuan} melampaui volume kontrak + CCO disetujui {y} {satuan}; perbaiki volume opname, atau catat dahulu volume CCO-nya pada register variasi kontrak."* | §7.14 |
| menyetujui invoice bertanggal di bulan tertutup | *"Periode fiskal {tahun}-{bulan} sudah ditutup; jurnal tidak dapat diposting ke dalamnya."* | §3.11 |
| menyetujui tagihan PO yang barangnya belum lengkap | *"Tagihan atas {PO} hanya dapat disetujui setelah barang diterima seluruhnya. Terima sisa barang atau tutup PO terlebih dahulu."* | §5.9 |
| menyetujui payroll yang belum dihitung | `Payroll run PYR/… has no payslips yet — calculate it first.` | §11.6 |
| memposting jurnal yang tidak seimbang | `Journal JV/2026/08/0009 is not balanced: debit 5000000 vs credit 4500000.` | §10.2 |
| membuka periode di bawah periode tertutup lain | *"Buka periode terbaru lebih dulu."* | ADMINISTRATOR §6.7 |
| menerbitkan tautan MK/Owner untuk CCO yang masih draf | *"Tautan persetujuan pekerjaan tambah-kurang hanya dapat diterbitkan saat dokumen berstatus submitted — saat ini draft."* | §15.1 |

**Tombol yang tidak akan pernah muncul untuk Anda** (§14.2): **`Tambah …`**, **`Ubah`**,
**`Ajukan`**, **`Posting ke Stok`**, **`Posting Pembayaran`**, **`Posting Penyusutan`**,
**`Tutup Periode`**, dan tombol simpan di **Pengaturan** / **Profil Perusahaan** (kolomnya
terlihat, tetapi mati tanpa izin sistem, §14.1). Bila Anda menemukan kesalahan pada isi
dokumen, obatnya **`Tolak`** — bukan mencari tombol Ubah.

## 5. Formulir yang Anda cetak

Izin lihat Anda menggambar **seluruh 61** tombol cetak (§13.3). Yang menyertai dokumen
yang Anda tandatangani:

| Formulir | Kode | Dokumen |
|---|---|---|
| Surat Penawaran Harga | F/PN | Penawaran (§3.4) |
| Ringkasan Kontrak · Berita Acara Tambah-Kurang | F/RK · F/BATK | Kontrak · CCO (§3.6, §3.7) |
| RAB / BOQ · RAP | F/RAB · F/RAP | BOQ, RAP (§4.3, §4.4) |
| Permintaan Pembelian · Pesanan Pembelian (Formulir Rumah) | F/PP · F/PO | PR, PO (§5.4, §5.6) |
| Berita Acara Keputusan Pemenang | F/AWD | Keputusan Pemenang (§5.12) |
| PPK Alat & Jasa | F/PPK | PPK (§5.14) |
| SPK Subkontraktor · Addendum SPK · Berita Acara Opname · BAST Subkontraktor | F/SP · F/AS · F/BO · F/BST-SK | Subkontrak (§8.2–§8.8) |
| Opname ke Pemilik (OPN) | F/OPN | Opname owner (§7.14) |
| Ijin Pelaksanaan (IPP) · Inspeksi Mutu (QCI) | F/IPP · F/QI | IPP, inspeksi (§16.5, §17.2) |
| Izin Kerja Lapangan · Izin Kerja Lembur · Izin Masuk / Keluar Material & Peralatan | F/IK · F/IL · F/IM | IKL, ILB, IMK (§7.13) |
| Berita Acara Stock Opname | F/BAO | Opname stok (§6.7) |
| Lembar Verifikasi Tagihan · Bukti Pembayaran / Penerimaan | F/VT · F/BP | Tagihan vendor, pembayaran (§5.9, §5.10) |
| Rekap Gaji · Pengajuan Cuti | F/RG · F/PC | Payroll, cuti (§11.6, §11.4) |

**BAST** dan **invoice termin** bukan formulir rumah: tombolnya **`PDF`** (§13.4).
**Aturan kejujuran** (§13.5): sel bergaris kosong berarti *tidak tercatat*, bukan nol —
SPK mencetak **TERMIN PEMBAYARAN** bergaris karena sistem tidak menyimpan jadwal bayar
SPK; Keputusan Pemenang mencetak alasan deviasi persis seperti diketik. Jangan
menandatangani angka yang diisi tangan di atas garis tanpa memeriksa dokumennya.

## 6. Daftar periksa minggu pertama

- [ ] Saya sudah masuk dengan akun saya sendiri, dan sidebar saya memuat empat belas
      kelompok.
- [ ] Saya sudah membuka kartu **Menunggu persetujuan Anda** dan tahu ia hanya memuat
      11 jenis.
- [ ] Saya sudah menyaring **Keuangan › Pembayaran** dan **Pengadaan › Keputusan Pemenang**
      ke status Diajukan, satu kali pagi ini.
- [ ] Saya sudah menyetujui satu dokumen dan membaca kartu Riwayat Persetujuannya.
- [ ] Saya sudah menolak satu dokumen dengan alasan tertulis, dan melihatnya kembali
      ke Draf.
- [ ] Saya tahu dua ambang yang hanya saya pegang: PO Rp 100 juta, SPK Rp 200 juta.
- [ ] Saya tahu tangga award (100 juta / 1 miliar) dan bahwa saya tidak bisa mengisi
      dua tingkat.
- [ ] Saya sudah membuka **Tenggat** dan tahu dua baris yang diawasi untuk peran saya.
- [ ] Saya sudah membaca §14.4 dan tahu lima `Setujui` yang sekaligus memposting atau
      menutup.
- [ ] Saya tahu nama manajer keuangan, manajer proyek, dan administrator yang saya andalkan.
- [ ] Saya sudah melihat alarm cadangan di lonceng dan tahu siapa yang membereskannya.

## 7. Bila tersangkut

| Situasi | Tanyakan ke |
|---|---|
| Kata sandi, akun, izin, ambang, pemisahan tugas, penomoran | **administrator** — PANDUAN §14.1; ambang dan pemisahan tugas ada di Pengaturan, dan itu keputusan Anda yang disetel administrator |
| Isi dokumen yang saya nilai salah | **`Tolak`** dengan alasan — pengaju yang disebut Riwayat Persetujuan memperbaiki |
| Pertanyaan lapangan: progres, opname, defect, BAST, NCR | **manajer proyek** (`project-manager`) — bab 7, 16, 17 |
| Pertanyaan pengadaan: prakualifikasi, RFQ, BAN, award | **pengadaan** (`procurement`) — bab 5 |
| Invoice, tagihan, pembayaran, tutup buku | **manajer keuangan** (`finance-manager`) dan **petugas keuangan** (`finance`) — bab 3, 5, 10 |
| Alarm cadangan, periode yang tidak bisa dibuka, deploy | **administrator** — PANDUAN-ADMINISTRATOR §5.6, §6.7, §10 |

Eskalasi, dua baris: kirimkan **empat hal** ke administrator — alamat halaman lengkap,
kode dokumen, teks merah persis, tombol yang ditekan (PANDUAN §14.5). Keputusan yang
menunggu pemilik — ambang nilai, pelimpahan persetujuan, kata sandi demo — tercatat di
PANDUAN-ADMINISTRATOR §12 dan ANALISIS §5; itu keputusan Anda, bukan keputusan kode.

---

> **Yang berubah pada rilis UX berikutnya — belum tayang di erp1 hari ini** (cabang
> `ux/p0-measured`, belum digabung): layar **Tugas Saya** dengan satu kotak masuk untuk
> **semua 28 jenis dokumen** (bukan 11); draf formulir bertahan di peramban saat sesi
> habis; catatan persetujuan diketik langsung di halaman tanpa dialog; ganti kata sandi
> sendiri. Sampai rilis itu tayang, panduan ini yang berlaku.
