# Asesmen Lanjutan — Proses Bisnis, Fitur, dan UI/UX

**Nusantara ERP** · disusun 1 Agustus 2026 · lanjutan dari [ASSESSMENT.md](ASSESSMENT.md)

> Asesmen pertama menanyakan *"apakah sistem ini benar?"* — dan 15 itemnya kini
> selesai: payroll, penyusutan, dan pendapatan PSAK 115 masuk buku besar; jejak
> audit, CCO, dokumen cetak, impor massal, pencarian, register K3, cadangan
> offsite. Asesmen kedua ini menanyakan pertanyaan berikutnya: *"apakah sistem
> ini benar-benar menjalankan bisnisnya?"* — apakah prosesnya tersambung ujung
> ke ujung, fiturnya cukup untuk kontraktor 50–200 orang, dan layarnya nyaman
> dipakai delapan jam sehari.
>
> **Metode:** lima auditor paralel membedah kode, rute, skema, dan basis data
> demo dari lensa berbeda (quote-to-cash, procure-to-pay, ritme keuangan,
> kelengkapan fitur pasar, kualitas UX di kode); satu kritikus memverifikasi
> ulang klaim paling berisiko langsung ke kode dan DB — **tidak satu pun temuan
> gugur** — lalu menambah 6 temuan yang terlewat; ditambah uji layar langsung
> di situs live. Total **81 temuan**: 10 kritis, 45 besar, 26 kecil. Setiap
> temuan membawa bukti `berkas:baris` yang benar-benar dibaca.
>
> **Pembaruan 3 Agustus 2026.** Kedua belas paket prioritas selesai, ditambah dua
> permintaan susulan pemilik (kalender acara korporat, impor excel/csv) dan satu
> layar yang ternyata tidak pernah ada sama sekali: buku besar. Sesudahnya
> seluruh modul keuangan/akuntansi/pajak dan seluruh modul persediaan **diaudit
> ulang dari nol** — bukan meninjau yang baru dibangun, melainkan menanyakan
> lagi pertanyaan pertama pada kode yang sudah berlapis-lapis disentuh. Hasilnya
> ada di [catatan pelaksanaan 3 Agustus](#catatan-pelaksanaan-buku-besar-impor-dokumen-dan-audit-ulang-2026-08-03),
> termasuk lima hal yang sengaja **dipagari, bukan ditutup** — dan satu cara membuat
> tutup buku macet permanen yang ditemukan pada pas verifikasi terakhir, lalu
> ditutup.
> Suite: 2.297 uji hijau (dari 1.337 saat asesmen ini ditulis).
>
> **Rekonsiliasi per-temuan** — status hari-ini ke-81 temuan terhadap resepnya,
> berikut setiap penyimpangan pelaksanaan dari rekomendasi:
> [LAPORAN-DEVIASI.md](LAPORAN-DEVIASI.md). Per 8 Agustus, setelah tiga
> gelombang perbaikan: **77 dari 81 ditutup**, 4 sengaja dipagari — tidak ada
> lagi yang sebagian atau belum disentuh.

---

## Ringkasan eksekutif

Fondasi akuntansi dan dokumen sistem ini kini **kuat** — lebih kuat dari
kebanyakan ERP kontraktor menengah. Kelemahannya bergeser ke tempat lain:
**mesin-mesin yang sudah dibangun tidak saling menyapa**. Milestone tercapai
tidak memberi tahu finance; GRN tidak memberi tahu PO; retensi yang boleh cair
tidak memberi tahu siapa pun; dan uang yang benar-benar masuk dari pelanggan
— yang selalu dipotong pajak oleh pemberi kerja — bahkan tidak bisa dicatat.

**Tiga temuan yang paling menyentuh uang** (versi kritikus, terverifikasi di DB):

1. **Rp 14,55 miliar hak tagih diam 4 bulan.** Milestone "syarat penagihan
   Termin 2" tercapai 27 Maret; per hari ini terminnya masih `billed_at=NULL`
   dan tidak ada satu sinyal pun dari sistem. Handoff PM→Finance hidup di luar
   aplikasi. *(Perbaikan 1–2 hari.)*
2. **Setiap penerimaan termin riil tidak bisa dicatat.** Pemberi kerja badan
   usaha/instansi wajib memotong PPh final konstruksi (1,75–6%) saat membayar
   — BUMN/pemerintah bahkan memungut PPN-nya (wapu). Alokasi penerimaan wajib
   sama persis dengan nilai invoice dan tidak mengenal baris potongan pajak;
   akun 1-1700 "Pajak Dibayar Dimuka" di-seed tapi tak pernah dipakai satu
   baris kode pun. *(1–2 hari.)*
3. **Three-way match mati dalam praktik.** Mesin backend lengkap (guard
   over-receipt, auto-close, gate tagihan final) tetapi form GRN tidak pernah
   mengirim `po_item_id` — `qty_received` abadi nol di seluruh DB, dan tagihan
   final SETIAP PO barang tertolak sampai PO ditutup manual. Kontrol belanja
   material — uang keluar terbesar kontraktor — bersifat hiasan. *(1–2 hari.)*

**Kematangan per area** (1 = manual di luar sistem, 5 = tersambung dan terjaga):

Kolom **Awal** adalah penilaian 1 Agustus; kolom **Kini** adalah keadaan setelah
paket 1–12, kalender, buku besar, impor, dan audit ulang 3 Agustus.

| Area | Awal | Kini | Satu kalimat (per 3 Agustus 2026) |
|---|---|---|---|
| Akuntansi & buku besar | ★★★★ | ★★★★★ | Periode fiskal benar-benar bisa ditutup, dan satu-satunya keadaan yang bisa memacetkannya permanen (T40) kini ditutup di kedua pintunya; bagan akun tersegel di bawah riwayat terposting, dan buku besar yang menjelaskan setiap baris neraca saldo akhirnya ada |
| Penjualan (quote-to-cash) | ★★★ | ★★★★ | Milestone→tagih, retensi→cair, void/nota kredit, penerimaan neto pajak semuanya tersambung; denda keterlambatan kini tercatat sebagai potongan beralasan pada alokasi penerimaan (8 Agu) |
| Pengadaan (procure-to-pay) | ★★½ | ★★★★½ | Three-way match hidup dan tiga jalan pintasnya ditutup; RFQ, gerbang harga vs BOQ, gate anggaran, prakualifikasi vendor, dan tagihan parsial per GRN menyusul 6-8 Agu |
| Subkontrak | ★★★ | ★★★★ | Pelepasan retensi berjurnal dan berpembayaran; uang muka subkon, addendum SPK, dan gate masa pemeliharaan menyusul 6-8 Agu |
| Persediaan | ★★★ | ★★★★ | Aturan costing ditegakkan, setiap mutasi tunduk pada tutup buku, sub-buku diadu dengan 1-1400 oleh perintah CLI; retur pembelian & retur proyek kini ada (6-8 Agu) — pembatalan tetap hanya untuk bon (T37) |
| Proyek | ★★★ | ★★★★ | EVM dengan baseline beku, varian material, register defect sebagai prasyarat BAST II; AC/CPI/EAC/POC masih mengecil selama ember biaya alat kosong sampai demobilisasi (lihat T43) |
| Kas & operasi keuangan | ★★ | ★★★★ | Kas kecil imprest + kasbon (mesinnya lengkap; di demo masih menunggu akun laci 1-11xx, di produksi sudah dipasang), pembayaran non-AP berdokumen, arus kas PSAK 2 historis + proyeksi 90 hari |
| SDM | ★★½ | ★★★★ | Register sertifikat & PKWT masuk pengawas tenggat; cuti ber-saldo UU 13/2003 dan register absensi harian menyusul 8 Agu (absensi belum menggerakkan gaji — register dulu) |
| Layanan (ServiceDesk) | ★★★ | ★★★★ | Suku cadang berita acara menyentuh stok dan buku besar; laporan yang sudah diajukan bisa ditarik kembali, dan syarat pengeluarannya diuji kering sebelum diajukan; hanya admin yang bisa mengesahkannya pada tenant baru (T13) |
| Aset | ★★★ | ★★★½ | Pelepasan aset kini berjurnal dengan pintu update tertutup (6-8 Agu); biaya alat masih mendarat satu gelondong saat demobilisasi (lihat T43) |
| UI/UX | ★★½ | ★★★★ | Combobox di seluruh field lookup (form DAN filter daftar), sorting + ekspor CSV + filter tanggal + baris-per-halaman, format rupiah ber-hint terbilang, proteksi data belum tersimpan; cetak kini memaksa tema terang |

---

## Bagian A — Proses bisnis

### A1. Quote-to-cash: dokumennya ada, sambungannya tidak

**🔴 KRITIS — Milestone tercapai tidak memicu penagihan.**
`MilestoneController::update` hanya mengisi `achieved_date`; tidak ada event,
notifikasi, atau kartu dasbor "termin siap ditagih". Bukti hidup: milestone
"Progres fisik 50% — syarat penagihan Termin 2" tercapai 27-03-2026, termin
Rp 14,55 M masih belum ditagih per 31-07. *(MilestoneController.php:38-43;
dashboard.js:128-166. Usaha: 1–2 hari — event + notifikasi `fin.create` +
kartu dasbor.)*

**🔴 KRITIS — Pencairan retensi pelanggan tidak punya layar.**
Endpoint `GET finance/ar-retentions` (lengkap dengan flag `is_due` dari BAST)
dan `POST .../release` sudah dibangun — `grep 'ar-retentions' public/` = nol.
Finance tidak melihat retensi mana yang sudah boleh dicairkan; masalah yang
didokumentasikan di docblock service-nya ("nobody chased it") terulang persis
di lapisan UI. *(Finance/Routes/api.php:65-66. Usaha: 1 hari.)*

**🟠 BESAR — Invoice termin bisa terbit sebelum syaratnya terpenuhi.** Modul
Finance tidak pernah membaca `prj_milestones`; `termin_id` milestone hanya
divalidasi `integer min:1` tanpa `Rule::exists` dan tanpa cek kontrak yang
sama. Finance bisa menagih "Progress 80%" saat progres aktual 55%. *(Usaha:
1 hari — jadikan konfirmasi eksplisit, bukan blok keras.)*

**🟠 BESAR — Menagih termin = mengetik ID database.** Field "Termin kontrak
(ID)" adalah input angka mentah; tabel jadwal termin di detail kontrak tidak
punya tombol "Tagih termin ini". Salah ketik = menagih termin yang salah.
*(schema.js:1594, 289-300. Usaha: 0,5–1 hari.)*

**🟠 BESAR — Masa pemeliharaan tanpa register defect, BAST II tanpa prasyarat.**
Tidak ada punch-list; BAST II bisa disetujui tanpa BAST I, tanpa cek progres,
tanpa cek defect — dan langsung menutup proyek. Padahal BAST II adalah dasar
pencairan retensi Rp 2,425 M kontrak 1. *(ProjectService.php:207-226. Usaha:
2–3 hari.)*

**🟠 BESAR — Denda keterlambatan tidak dikenal sistem.** `grep denda|penalty`
= nol. Pemilik yang membayar dikurangi denda 1‰/hari tidak bisa dicatat —
alokasi wajib pas — sehingga invoice menggantung "kurang bayar" selamanya.
*(Usaha: 2–3 hari.)*

**🟠 BESAR — CCO tidak bisa menurunkan termin baru.** Komentar di
`ContractChangeOrderService` menjanjikan "billed through new termins", tapi
kontrak approved tidak editable dan rute tambah-termin tidak ada: setelah CCO,
jumlah termin ≠ nilai kontrak dan pekerjaan tambah hanya bisa ditagih lewat
invoice manual tanpa jadwal. *(Usaha: 1–2 hari.)*

**🟠 BESAR — Siklus hidup proyek tanpa state machine.** Status proyek dropdown
bebas; `ProjectStatus::isOperational()` dideklarasikan dan tidak dipakai satu
kali pun — laporan harian masih bisa masuk ke proyek closed, dan proyek bisa
ditutup dengan Rp 9,7 M termin belum tertagih tanpa satu peringatan. *(Usaha:
1–2 hari.)*

**🟠 BESAR — Termin kalender tidak bisa dijadwalkan.** Termin tidak punya
tanggal rencana tagih; kontrak maintenance demo terbukti melewatkan satu
kuartal penuh (Rp 120 juta, termin TW II masih NULL di pertengahan TW III).
*(Usaha: 1 hari — kolom `due_date` + pengingat harian.)*

**🟠 BESAR (dari kritikus) — Tidak ada void/nota kredit.** Status `Cancelled`
dideklarasikan dan difilter defensif di mana-mana, tetapi tidak ada satu jalur
kode pun yang men-set-nya: invoice/tagihan salah yang terlanjur approved tidak
bisa dibatalkan — piutang fiktif menggantung, termin terkunci "sudah ditagih",
dan invoice pengganti justru ditolak guard. *(Usaha: 1–2 hari — aksi
"Batalkan" dengan jurnal balik untuk dokumen belum dibayar.)*

Kecil: dua pola retensi (per-invoice vs termin khusus) tanpa pagar bisa dobel
potong; `contract_value` proyek tidak ikut CCO; status lead tidak mengikuti
nasib penawarannya; eskalasi harga kontrak multi-tahun tidak didukung.

### A2. Procure-to-pay & subkon: mesinnya dibayar, sakelarnya tidak dinyalakan

**🔴 KRITIS — Three-way match mati** *(lihat ringkasan eksekutif #3)*.
Ikutannya: kolom "Diterima" di detail PO selalu 0, guard over-receipt tidak
pernah aktif, dan `assertStockCommitmentSettled` menolak tagihan final setiap
PO barang — memaksa kebiasaan tutup-PO-manual yang justru mematikan disiplin.
*(schema.js:1126-1135; PoService.php:150-185; DB: 0 dari 5 baris PO pernah
menerima. Usaha: 1–2 hari — aksi "salin baris dari PO" di form GRN.)*

**🔴 KRITIS — Pelepasan retensi subkon tidak berjurnal.** `release()` hanya
membuat baris `scm_retention_releases`; 2-1500 tidak pernah didebit dan
PaymentService tidak bisa membayarnya. GL bilang retensi masih terutang
selamanya, modul Subkon bilang sudah dilepas. Ini separuh yang belum selesai
dari perbaikan retensi asesmen pertama. *(RetentionService.php:37-66. Usaha:
1–2 hari — release membuat AP bill retensi Dr 2-1500 / Cr 2-1100.)*

**🟠 BESAR — Persetujuan dua tingkat hanya label.** `needs_director_approval`
distempel saat submit lalu diabaikan: `Approvable::approve` tetap satu langkah
oleh siapa pun pemegang `prc.approve`. Beri manajer pengadaan hak approve agar
PO kecil tak antre — dan orang yang sama bisa menyetujui PO Rp 5 M sendirian.
*(Approvable.php:33-41. Usaha: 2–3 hari.)*

**🟠 BESAR — Uang muka vendor & tagihan-atas-GRN tidak ada di form.** Mesin
`is_advance` dan `goods_receipt_id` lengkap di backend; form Tagihan Vendor
tidak punya kedua field itu. DP 20–30% ke pemasok — alasan fitur ini dibangun
— tidak bisa dicatat dari layar. *(schema.js:1638-1658. Usaha: 0,5–1 hari.)*

**🟠 BESAR — Uang muka subkon tidak ada sama sekali** — ApBillService menolak
advance untuk klaim subkon, dan rumus opname tidak mengenal pemotongan DP.
Dipaksakan lewat tagihan manual = biaya subkon terhitung dua kali. *(Usaha:
2–3 hari, meniru pola advance PO.)*

**🟠 BESAR — Tanpa RFQ, tanpa kendali harga, tanpa gate anggaran.** Harga PO
bebas diketik tanpa dibanding `estimated_price` PR/AHSP/RAP; `boq_item_id`
putus di baris PO; `CommitmentService` menghitung sisa anggaran tapi tidak
pernah ditunjukkan pada saat approve. Pembelian menembus RAP lolos diam-diam.
*(Usaha: bertahap — bawa boq_item_id 0,5 hari; peringatan harga 1 hari;
soft-block anggaran di layar approve 1–2 hari; RFQ sederhana 2–3 hari.)*

**🟠 BESAR — Varian material teori vs aktual tidak pernah dihitung** padahal
seluruh datanya ada (koefisien AHSP × qty BOQ vs issue per proyek/WBS —
`wbs_task_id` di issue terisi 0 di seluruh DB karena tidak ada yang
mengonsumsinya). Kebocoran material — masalah #1 kontraktor — baru terlihat
sebagai selisih rupiah di P&L, terlambat untuk dikoreksi. *(Usaha: 3–5 hari.)*

**🟠 BESAR — Tagihan parsial per kiriman tidak bisa** (satu tagihan final per
PO); **retur pembelian ke vendor tidak ada** (barang ditolak = beban selisih
6-4400, hutang vendor tetap penuh); **retur sisa material dari proyek tidak
ada** (biaya proyek tercatat lebih, stok fisik ≠ sistem); **addendum SPK tidak
ada** (perubahan lingkup subkon = SPK baru terpisah, riwayat pecah). *(Usaha:
masing-masing 1–5 hari.)*

Kecil: pelepasan retensi subkon tanpa syarat masa pemeliharaan; `expected_date`
PO tidak diawasi (tanpa laporan keterlambatan); evaluasi vendor diisi manual
padahal data ketepatan kirim ada; harga GRN diketik bebas tanpa default dari
baris PO.

### A3. Ritme keuangan: tutup buku, kas, dan pemisahan tugas

**🔴 KRITIS — Periode fiskal tidak bisa ditutup.** Tidak ada rute, layar, atau
command — guard periode yang rapi di JournalService praktis tidak pernah aktif;
siapa pun bisa mem-backdate jurnal ke bulan yang laporannya sudah terbit.
*(Usaha: 1–2 hari.)*

**🔴 KRITIS — 1 Januari 2027 semua posting gagal serentak.** Kalender fiskal
hanya di-seed untuk tahun berjalan; tidak ada mekanisme membuat tahun
berikutnya. Approve invoice, payroll, penyusutan, POC — semuanya melempar "No
fiscal period exists" tepat di hari sibuk awal tahun. *(Usaha: 2–4 jam.)*

**🔴 KRITIS — Pemisahan tugas: jalur fraud satu orang.** Role `finance`
memegang create+approve+post sekaligus; `approve()` tidak menolak menyetujui
dokumen sendiri; pembayaran keluar bahkan tidak punya tahap approval (draft →
post). Staf finance bisa membuat tagihan fiktif, menyetujuinya sendiri, dan
memposting pembayarannya — tanpa satu mata kedua. Bagi auditor eksternal ini
temuan pengendalian internal material. *(RoleSeeder.php:56-59; Approvable.php:
33-41. Usaha: 1–2 hari.)*

**🟠 BESAR — Tutup buku tanpa orkestrasi.** Payroll→GL, penyusutan, POC,
rekonsiliasi, ekspor pajak adalah pulau-pulau tanpa checklist dan tanpa
penjagaan urutan — POC bisa diposting sebelum payroll/penyusutan bulan itu
masuk (% penyelesaian understated). Urutan close hidup di kepala satu orang.
*(Usaha: 2–3 hari — layar checklist per periode, tombol tutup di ujungnya.)*

**🟠 BESAR — Kas kecil / kasbon tidak ada.** Bensin, tol, konsumsi tukang,
material mendadak — operasional harian site tidak punya jalur masuk; biaya
proyek understated sampai JV manual masuk, dan run PSAK 115 ikut tertunda
akurasinya. *(Usaha: 3–5 hari.)*

**🟠 BESAR — Membayar gaji/pajak/BPJS = JV manual.** PaymentService hanya
mengenal alokasi AP; disbursement terbesar tiap bulan lewat jalur paling rawan
tanpa approval. *(Usaha: 1–2 hari — tipe alokasi "lainnya" dengan akun lawan.)*

**🟠 BESAR — Tidak ada arus kas.** Tanpa laporan PSAK 2, tanpa proyeksi, tanpa
saldo bank di dasbor — "cukupkah kas bulan depan untuk gaji + subkon + pajak?"
dijawab spreadsheet. Semua bahan bakunya (due date AR/AP, jadwal termin,
jadwal gaji) sudah di database. *(Usaha: 2–4 hari.)*

**🟠 BESAR — Kalender kewajiban pajak tidak ada.** Ekspor per masa ada, tapi
status setor/lapor (NTPN, tanggal) dan batas waktunya tidak dilacak; saldo
hutang pajak menumpuk tanpa penanda masa mana yang belum dibayar. *(Usaha:
1–2 hari.)*

Kecil: tidak ada role bawaan pemegang `ast.post` selain admin (penyusutan
praktis hanya bisa diposting admin); WIP schedule auditor belum ada padahal
semua kolomnya kini tersedia di baris run POC (usaha: 1 hari); arsitektur
satu entitas hukum (batasan yang perlu disadari, bukan cacat).

### A4. Sambungan lintas modul yang putus

**🟠 BESAR (dari kritikus) — Suku cadang berita acara tidak menyentuh stok.**
Part yang dipasang teknisi tercatat sebagai baris data di berita acara, tidak
pernah mengurangi gudang dan tidak pernah menjadi biaya kontrak layanan —
stok sistem > fisik, selisihnya dibukukan buta sebagai kerugian opname 6-4400.
Celah kebocoran gudang klasik, di lini bisnis yang hidup dari penggantian
part. *(FieldReportService.php:13-44. Usaha: 1–2 hari — acknowledge() men-
generate issue otomatis.)*

**🟡 KECIL (dari kritikus) — Pelepasan aset tanpa akuntansi.** Status
`disposed` bisa dipilih lewat update biasa; `disposal_date/value` ada di tabel
dan tidak dikonsumsi kode; alat yang dijual/hilang tetap di neraca selamanya.
*(Usaha: 1 hari, meniru pola DepreciationService::postJournal.)*

---

## Bagian B — Fitur yang belum ada (dibanding kebutuhan kontraktor menengah)

Diurutkan menurut seberapa sering ketiadaannya menyakiti:

| Fitur | Kenapa penting | Usaha |
|---|---|---|
| **Pengawas tenggat terpusat** — puluhan kolom tanggal yang datanya ada tapi tak pernah mengingatkan siapa pun: `valid_until` penawaran, `end_date` kontrak, `retention_release_due` BAST, `due_date` tindakan K3, `expected_date` PO, `next_due_date` servis aset, `period_end` kontrak layanan, `planned_until` mobilisasi… | Retensi ratusan juta lupa ditagih; kontrak layanan lewat tanpa perpanjangan; alat tak diservis. Sistem sudah tahu semua tanggalnya — tidak ada yang memberi tahu manusia | 3–5 hari (satu `erp:deadline-watch` dengan registry deklaratif, memakai `NotificationService::system()` yang sudah ada) |
| **Register jaminan bank & asuransi proyek** (bid/performance/advance/maintenance bond, CAR/TPL) — kata "jaminan" hanya ada sebagai teks bebas di data demo, yang justru membuktikan prosesnya membutuhkannya | Jaminan pelaksanaan kedaluwarsa saat proyek molor = wanprestasi; jaminan lupa ditarik = limit bank tertahan | 2–3 hari |
| **Cuti/izin & absensi harian** — HR hanya punya rekap bulanan agregat | Saldo cuti UU 12 hari tak terkontrol; rekap manual rawan salah potong gaji | 5–8 hari |
| **PKWT tanpa tanggal berakhir & register sertifikat (SKK/K3/principal)** | PKWT lewat tanggal = demi hukum jadi PKWTT (UU Cipta Kerja); SKK kedaluwarsa menggugurkan tender | 1–2 + 2–3 hari |
| **EVM (CPI/SPI) & baseline kurva-S** — seluruh bahan bakunya sudah ada (RAP, biaya aktual, progres berbobot, run POC) | Proyek bisa on-schedule tapi membakar biaya dan baru ketahuan rugi di termin akhir; tanpa baseline, deviasi terhadap kontrak awal tak bisa dibuktikan saat mengajukan EOT / melawan denda | 2–3 + 2–3 hari |
| **BBM & hour meter alat berat** | BBM 10–30% biaya operasi alat dan titik kebocoran klasik; servis excavator/genset berbasis jam operasi, bukan tanggal | 3–5 hari |
| **Galeri foto progres per proyek** — foto sudah ter-geotag tapi tersebar per dokumen sebagai baris nama file | Lampiran tagihan termin/BAST dirakit dengan membuka dokumen satu-satu | 2–3 hari |
| **Riwayat harga satuan** — harga AHSP satu angka yang tertimpa; harga tiap PO sebenarnya tersimpan | Estimator menyusun RAB buta tren; penawaran lama tak bisa diaudit | 2–4 hari |
| **Analitik win-rate tender** — `won_at/lost_at/lost_reason` dicatat, tidak pernah dilaporkan | Belajar dari kekalahan = gratis, datanya sudah ada | 1 hari |
| **Dasbor per peran** — satu dasbor untuk semua, disaring permission bukan peran; agregatnya pun terpotong 100 baris pertama | Direktur dan admin gudang melihat layar pembuka yang sama | 2–3 hari |
| Peminjaman alat kecil; portal pelanggan; multi-valuta | Lihat "sengaja tidak disarankan" | — |

---

## Bagian C — UI/UX

Diverifikasi dua arah: pembacaan kode SPA menyeluruh + uji klik langsung di
situs live.

**🔴 KRITIS — 93 field lookup adalah `<select>` polos.** Semua pemilih
referensi (plus 8 kolom `item_id` di baris PR/PO/GRN/opname/AHSP) tanpa
type-ahead, dan `lookup.js` memuat seluruh sumber (hingga plafon 10.000 baris)
ke DOM. Memilih satu item dari katalog ribuan baris pada setiap baris PO —
layar yang dipakai berkali-kali sehari — berarti scroll manual di dropdown
native. **Satu komponen combobox di `buildInput` memperbaiki seluruh aplikasi
sekaligus.** *(form.js:81-98; lookup.js:41-113. Usaha: 3–4 hari.)*

**🔴 KRITIS — Satu ketukan Esc membuang isian.** Modal tertutup lewat Esc,
klik backdrop, atau X tanpa dirty-check dan tanpa konfirmasi — teruji langsung
di layar: PO 15 baris hilang total oleh satu klik meleset. *(ui.js:154-192.
Usaha: 1 hari.)*

**🟠 BESAR — Tanpa sorting kolom, di mana pun.** Teruji klik langsung: header
tak bereaksi; tidak ada handler, tidak ada parameter sort. Finance tidak bisa
mengurutkan invoice per jatuh tempo atau nilai. *(list.js:237-254. Usaha: 1–2
hari.)*

**🟠 BESAR — Tanpa ekspor CSV** di daftar generik maupun laporan keuangan —
neraca saldo untuk KAP disalin manual. *(Usaha: 1–2 hari; pola unduh sudah ada
di taxexport.js.)*

**🟠 BESAR — Validasi hanya di server, error baris tak tersasar.** Field wajib
dikirim kosong; error `items.6.qty` muncul sebagai toast Inggris mentah yang
hilang 8 detik, tak pernah dipetakan ke baris ke-7. *(form.js:349-382. Usaha:
1–2 hari.)*

**🟠 BESAR — Nilai miliaran diketik tanpa pemisah ribuan** di
`<input type=number>` polos — `15000000000` vs `1500000000` tak terbedakan
mata, dan scroll-wheel bisa mengubah angka tanpa disadari. *(Usaha: 1 hari.)*

**🟠 BESAR — Keyboard & pembaca layar putus di gerbang dasar.** Baris tabel
hanya bisa diklik mouse (tanpa tabindex/href); modal tanpa `role="dialog"`,
tanpa focus-trap — Tab keluar ke halaman di belakang overlay, pada aplikasi
yang seluruh form-nya hidup di modal. *(Usaha: 2 hari untuk keduanya, di satu
tempat — ui.js.)*

**🟠 BESAR — Filter tanggal hanya ada di 2 dari 51 resource**, dan state
daftar (halaman, kata kunci, filter) tidak di URL — "semua invoice November"
tak terjawab, dan tautan yang dibagikan selalu membuka daftar kosong. *(Usaha:
1 hari.)*

**🟠 BESAR — Rekonsiliasi bank membuang seluruh layar per aksi.** Tiap
cocokkan/batalkan satu baris me-refetch semuanya dan mengembalikan scroll ke
puncak — 200 baris = 200 kali kembali ke atas. *(bankrecon.js:503-509. Usaha:
1–2 hari.)*

**Temuan uji layar langsung (tambahan):**
- **Jam klien dipercaya untuk tampilan dan hitungan.** Dasbor menulis "per
  1 Agustus 2026" ketika server 28 Juli; tanggal default form dan hitungan
  "terlambat X hari" SLA semuanya `new Date()` browser — PC pengguna yang
  jamnya miring menggeser semuanya. Server sebaiknya memasok "as of".
- **Input tanggal native tampil `mm/dd/yyyy`** pada browser berlokal EN —
  urutan bulan-dulu yang ambigu bagi pengguna Indonesia.
- **Baris item PO berada di bawah lipatan modal** tanpa petunjuk bahwa
  formulir berlanjut — formulir tampak selesai padahal bagian terpentingnya
  belum terlihat.

Kecil (masing-masing ≤ 1 hari, banyak yang menitan): campuran label EN/ID
('Update', 'rollup', header auto-titleize Inggris); 8 layar detail custom
blank tanpa skeleton saat memuat; **cetak dari tema gelap menghasilkan teks
abu-terang di kertas putih** (token warna tidak dipaksa terang di `@media
print`) — padahal print adalah satu-satunya jalan keluar laporan keuangan;
tabel tanpa total kolom uang (tfoot); header sticky mati (konteks overflow
salah); `hideOnNarrow` terdokumentasi di schema.js tapi tak pernah
diimplementasikan — di ponsel semua tabel mengandalkan geser horizontal;
lookup yang 403 tampil sebagai daftar kosong tanpa penjelasan "butuh hak
akses"; chevron grup navigasi tak pernah berputar (selector CSS menunjuk
class yang tidak pernah diberikan).

---

## Prioritas pengerjaan yang disarankan

Diurutkan dengan rumus yang sama seperti asesmen pertama: uang yang
diselamatkan ÷ usaha, dan berapa banyak temuan lain yang ikut selesai.

| # | Paket | Menutup temuan | Usaha | Status |
|---|---|---|---|---|
| 1 | **Termin siap tagih**: notifikasi milestone→finance + kartu dasbor + tombol "Tagih termin ini" + `due_date` termin kalender | 4 temuan QTC, Rp 14,55 M yang diam | 2–3 hari | ✅ selesai |
| 2 | **Penerimaan neto pajak**: baris potongan PPh final/PPN wapu pada alokasi RCV + arsip bukti potong | kritis #2 — 100% uang masuk | 1–2 hari | ✅ selesai |
| 3 | **Hidupkan three-way match**: "salin baris dari PO" di form GRN + field uang muka vendor di form tagihan | 2 kritis/besar P2P | 2 hari | ✅ selesai |
| 4 | **Retensi dua arah tuntas**: layar pencairan retensi AR + jurnal & pembayaran pelepasan retensi subkon | 2 kritis | 2 hari | ✅ selesai |
| 5 | **Void/nota kredit** dokumen approved belum dibayar | 1 besar, prasyarat kebersihan piutang | 1–2 hari | ✅ selesai |
| 6 | **Disiplin periode**: layar tutup/buka periode fiskal + auto-kalender tahun baru + checklist tutup buku | 3 kritis/besar | 3 hari | ✅ selesai |
| 7 | **Pemisahan tugas**: guard self-approval + approval pembayaran keluar + pisah `fin.approve` dari role finance | 1 kritis | 1–2 hari | ✅ selesai |
| 8 | **Combobox lookup + proteksi data belum tersimpan** | 2 kritis UX, terasa di semua form | 3–4 hari | ✅ selesai |
| 9 | **`erp:deadline-watch`**: satu pengawas untuk semua kolom tanggal (retensi, jaminan, PKWT, SKK, PO telat, servis aset, kontrak layanan) + register jaminan & sertifikat sebagai sumber datanya | ± 8 temuan sekaligus | 5–7 hari | ✅ selesai |
| 10 | **Kas kecil/kasbon + pembayaran non-AP + arus kas 90 hari** | 3 besar area kas | 5–7 hari | ✅ selesai |
| 11 | Sorting + ekspor CSV + filter tanggal + validasi client-side + format rupiah | 5 besar UX | 4–5 hari | ✅ selesai |
| 12 | Suku cadang→stok, EVM + baseline, varian material, defect register | sisa besar bernilai | 8–12 hari | ✅ selesai |
| + | **Kalender acara korporat** (permintaan pemilik 2026-08-01): agregasi acara bertanggal seluruh departemen ke kartu dasbor + layar `#/kalender` | melengkapi paket 9 — tenggat menjawab "apa yang terlambat", kalender menjawab "apa yang terjadi kapan" | 1–2 hari | ✅ selesai |
| + | **Buku besar** (permintaan pemilik 2026-08-03): `GeneralLedgerService` + `#/buku-besar` — saldo awal, mutasi, saldo berjalan, referensi dokumen | tidak ada di daftar 81 temuan karena tidak seorang pun mengira layarnya belum ada | 1 hari | ✅ selesai |
| + | **Impor excel/csv dokumen berbaris** (permintaan pemilik 2026-08-03): penawaran, BOQ, AHSP, RAP lewat satu mesin generik | melengkapi impor master data yang sudah ada sejak asesmen pertama | 2–3 hari | ✅ selesai |
| + | **Audit ulang keuangan/akuntansi/pajak dan persediaan** (permintaan pemilik 2026-08-03) | 29 temuan keuangan + 21 temuan persediaan; 94 invarian keuangan dikonfirmasi bertahan | — | ✅ selesai, 6 dipagari |

Paket 1–5 ≈ dua minggu kerja dan menyentuh langsung uang masuk. Paket 6–8
menyiapkan sistem untuk diaudit dan dipakai ramai-ramai.

### Catatan pelaksanaan paket 1–5 (2026-08-01)

Kelimanya selesai dan diverifikasi di erp1.pi2.co.id: antrean `#/siap-tagih`
memunculkan dua termin senilai Rp 14,67 M (terlama 127 hari), "Tagih termin ini"
membuka form invoice yang sudah terisi, GRN menyalin baris PO, penerimaan
RCV/2026/VIII/0002 memotong PPh final Rp 3.180.000 (Dr 1-1700) sehingga kas
Rp 130.020.000 cocok dengan mutasi bank, dan siklus batalkan → termin terbuka →
tagih ulang berjalan penuh.

Tinjauan lawan atas hasil paket 5 memunculkan lima cacat yang ikut ditutup:

1. **Tanggal jurnal pembalik.** Pembalikan dulu selalu memakai tanggal dokumen.
   Bila periode itu sudah diukur run PSAK 115 yang terposting — dan run
   terposting tidak pernah bisa dihitung ulang — satu pembatalan menghasilkan
   dua laporan laba rugi yang salah. Sekarang `JournalService::reversalDate()`
   memindahkan pembalikan ke periode berjalan bila periode asal sudah ditutup
   atau sudah diukur. Aturan lama (menolak pembatalan) dicabut: aplikasi ini
   tidak punya nota kredit, jadi menolak berarti tidak ada jalan sama sekali.
2. **PDF dokumen dibatalkan** tercetak identik dengan yang hidup — nomor faktur
   pajak dan "Jumlah yang harus dibayar" utuh. Sekarang berpita `DIBATALKAN`
   beserta alasannya.
3. **`outstanding` dokumen dibatalkan** masih menampilkan nilai penuh di kolom
   "Sisa"; pembatalan mensyaratkan nol dibayar, jadi angkanya selalu seluruh
   nilai dokumen. Sekarang nol, dan `isFullyPaid()` tidak ikut berbohong.
4. **Filter `?unpaid=1`** mengembalikan dokumen dibatalkan sebagai piutang/hutang
   terbuka.
5. **Pesan penolakan** menyuruh operator "batalkan penerimaannya lebih dulu" —
   operasi yang tidak ada di aplikasi ini.

### Catatan pelaksanaan paket 6–9 (2026-08-01)

**Paket 6 — periode fiskal kini benar-benar bisa ditutup.** Checklist sepuluh butir
dihitung ulang dari data sumber pada setiap permintaan (lima blok keras: periode
berakhir, periode sebelumnya tutup, tanpa dokumen menggantung, neraca saldo seimbang,
run PSAK 115 terposting; plus tie-out subledger AR/AP vs 1-1300/2-1100), empat
peringatan yang boleh dilewati dengan alasan tercatat di `fin_period_events`. Kalender
bergulir maju lewat `fin:ensure-calendar`; `generateYear` untuk tahun lampau membuat
periode TERTUTUP. Tutup berurutan tertua-dulu, buka-kembali terbaru-dulu, dan periode
yang sudah diukur run PSAK 115 terposting tidak pernah bisa dibuka lagi — alasannya
tampil di layar sebelum tombolnya diklik.

**Paket 7 — pemisahan tugas ditegakkan di tiga lapis.** `Approvable` menolak
menyetujui dokumen yang diajukan sendiri (12 tipe dokumen); pembayaran keluar kini
draf → diajukan → disetujui → diposting; `fin.approve` dan `hr.approve` dipisah dari
role pembuatnya; JV manual — satu-satunya jalur uang keluar tanpa dokumen — kini
menuntut `fin.approve` dengan jejak pengaju; `needs_director_approval` yang dulu hanya
label kini ditegakkan saat approve; pelepasan retensi subkon menuntut `fin.approve`
pada route-nya. Katup darurat `approvals.segregation_of_duties` tetap ada untuk
perusahaan yang benar-benar tanpa petugas kedua, default TEGAK.

**Paket 8 — 94 field lookup menjadi combobox ketik-cari** (satu komponen vanila tanpa
build step), modal berpagar dirty-check di tiga jalur tutupnya, focus-trap +
`role="dialog"`, dan tiga bug lama ikut mati: Ctrl+K menghapus form yang sedang diisi
lewat overlay bersama, `<select>` membisukan nilai yang option-nya hilang (referensi
ter-null saat simpan), dan peringatan pemangkasan 10.000 baris yang tampil sebagai
toast 8 detik sebelum form terbuka.

**Paket 9 — pengawas tenggat + dua register baru.** `erp:deadline-watch` membaca
registry deklaratif 16 pengawas lintas modul dengan degradasi skema (tabel yang belum
termigrasi dilewati, bukan crash), dedup jujur (judul stabil + tanda-tangan isi,
renag terjadwal), dan baris "BLIND" saat kolom tanggal sebuah pengawas kosong
seluruhnya. Register jaminan/asuransi (Crm) dan sertifikat+PKWT (HrPayroll) menjadi
sumber datanya — PKWT dasar *selesainya pekerjaan* (PP 35/2021 Pasal 9) sah tanpa
tanggal dan tidak ditagih pengawas. Hari pertama di data live: dua PO telat 153 dan
131 hari, dua PKWT tanpa tanggal akhir.

Setiap paket melewati tinjauan lawan multi-lensa; seluruh temuannya (11 pada paket
6–7, 14 pada paket 8, 16 pada paket 9) diverifikasi mati oleh pemeriksa independen
sebelum dianggap selesai.

### Catatan pelaksanaan paket 10, 12, dan kalender (2026-08-02)

**Paket 10 — operasi kas lengkap.** Gaji/PPh/BPJS/PPN kini keluar sebagai DOKUMEN
pembayaran beralokasi-akun (registry `SettleableLiabilities`, 8 akun, plafon per akun
dibaca dari GL terposting dan divalidasi ulang di dalam transaksi posting — kredit
dibatasi jendela bulan, debit TIDAK, sehingga pembayaran ganda mundur-tanggal ditolak);
kas kecil imprest + kasbon dengan identitas `float = kas laci + bon belum diganti +
belanja kasbon belum diganti + kasbon beredar` dihitung di SATU tempat; arus kas
historis PSAK 2 (terikat identitas: laporan = mutasi saldo bank+kas periode) dan
proyeksi 90 hari berasumsi-tercetak. Delapan temuan tinjauan lawan ditutup dan
diverifikasi ulang.

**Paket 12 — mesin proyek tersambung.** EVM dengan baseline beku bermaker-checker
(kurva revisi 0 byte-identik setelah dua re-baseline; CPI 101,63 demo jujur ditandai
"biaya belum lengkap" lewat uji cakupan per kategori — serangan Rp 4.000 token
terpatri sebagai test); register defect + prasyarat BAST II nyata (penurunan keparahan
temuan terbuka berbiaya prj.approve + alasan tertulis — gerbang di depan retensi
Rp 2,425 M); suku cadang berita acara kini MENYENTUH stok (issue+biaya+GL atomik saat
acknowledge, FORWARD-ONLY — baris historis tidak difabrikasi jurnalnya); varian
material teori-vs-aktual dengan bucket "tanpa atribusi WBS" yang jujur. 22 temuan
tinjauan: 26/27 verdict mati (satu yang tersisa ditutup di jalur Finance setelah
jalurnya bebas: `is_due` retensi kini mensyaratkan BAST II approved).

**Kalender acara korporat (permintaan pemilik).** `GET api/core/calendar` mengkomposisi
registry pengawas tenggat (scope yang sama, jendela bulan alih-alih tier urgensi —
satu sumber kebenaran, kalender dan pengawas tidak mungkin saling bertentangan) plus
sumber khusus-kalender: tutup buku, tanggal gajian, mulai/target proyek dan kontrak,
jadwal kunjungan PM. Disaring per izin .view pemanggil. Kartu dasbor (mini-grid +
5 agenda terdekat) dan layar penuh `#/kalender` (navigasi bulan Senin-dulu, legenda
departemen yang sekaligus filter, palet SATU pemilik dengan kartu dasbor). Enam temuan
tinjauannya ditutup, termasuk memo introspeksi skema yang memangkas ~276 query per
muat dasbor menjadi ~30.

### Catatan pelaksanaan paket 11 (2026-08-02)

`ApiController::listing()` menjadi SATU mekanisme untuk urut + jendela tanggal +
kontrak `meta.sortable`/`meta.date_column`, diadopsi ~40 controller non-Finance
lalu seluruh Finance dalam pas terpisah. Layar menemukan kemampuan itu dari
meta, bukan dari deklarasi kedua di schema.js — daftar yang controllernya belum
mengadopsi tetap hidup, hanya tanpa tombolnya.

Keputusan yang layak diingat: **ekspor CSV berjalan di klien** (page-walk dengan
q/filter/tanggal/urut yang identik, `per_page=200` sampai `meta.last_page`).
Alasannya bukan kemudahan: arti sebuah sel hanya ada di klien — kolom `rel`
membawa foreign key mentah yang baru bernama lewat cache lookup, label enum
hidup di enums.js — sehingga CSV sisi server akan mengekspor id mentah (tak
berguna bagi KAP) atau menuntut skema kolom kedua di PHP. Format ramah Excel
Indonesia: pemisah `;`, BOM UTF-8, angka desimal koma tanpa pemisah ribuan.

Sepuluh temuan tinjauannya ditutup, dua di antaranya kelas "dua rantai
membangun terhadap rancangan, bukan terhadap satu sama lain": server menjawab
422 untuk kunci urut tak dikenal sementara SPA menyemai `?sort=` dari URL dengan
asumsi diabaikan diam-diam — tautan lama akan mematikan daftar. Sekarang SPA
memulihkan diri (toast menyebut kolom yang dibuang, urutan DAN halaman kembali
ke bawaan, URL dibersihkan) tanpa melemahkan 422-nya. Serta `money.js` yang
merusak tempel desimal en-US 100× — aturan titik-sebagai-desimal kini hanya
berlaku pada event tempel, karena menerapkannya saat mengetik akan merusak alur
backspace yang jauh lebih sering ('15.000' → '15.00' harus tetap 1500).

Sisa yang sengaja tidak diambil: kunci urut untuk kolom turunan/nested
(`period` gabungan, `customer.name`) menunggu seam `column.sortKey` di schema.js
— dicatat, tidak dibangun.

### Catatan pelaksanaan: buku besar, impor dokumen, dan audit ulang (2026-08-03)

Empat pekerjaan dalam satu pas: menutup sisa temuan perjalanan, membangun buku
besar yang ternyata tidak pernah ada, menambah impor excel/csv untuk dokumen
berbaris, dan **mengaudit ulang dari nol** dua modul terbesar — bukan meninjau
yang baru dibangun, melainkan menanyakan lagi pertanyaan pertama pada kode yang
sudah berlapis-lapis disentuh.

**Buku besar — pertanyaannya "di mana tautannya", jawabannya "layarnya belum
pernah ada".** Tidak ada service, tidak ada route, tidak ada view; neraca saldo
menyebutkan saldo tiap akun tanpa satu pun jalan melihat isinya.
`GeneralLedgerService::ledger()` menghitung saldo awal sebagai SATU agregat atas
seluruh mutasi sebelum jendela, mutasi debit/kredit di dalamnya, lalu saldo akhir
**dengan urutan pembulatan yang persis sama dengan `trialBalance()`** — bukan
sekadar rumus yang mirip, melainkan urutan operasi yang sama, karena dua laporan
yang membulatkan pada langkah berbeda akan berselisih satu sen dan menghabiskan
satu sore akuntan. `GET api/finance/reports/general-ledger` bergerbang `fin.view`,
izin yang sama dengan neraca saldo yang ia jelaskan: mengunci lebih ketat dari
laporan yang menampilkan angka penutupnya tidak koheren. Layar `#/buku-besar`,
item NAV tepat di bawah "Laporan Keuangan" karena di situlah orang yang baru
membaca baris 1-1400 akan mencarinya. *(GeneralLedgerService.php:94,124;
Routes/api.php:203; schema.js:2772; bukubesar.js:429.)*

Uji penguncinya beriterasi SETIAP baris `trialBalance(2026, 3)` dan menuntut
kecocokan pada saldo akhir **dan** saldo awal **dan** kedua sisi mutasi — supaya
angka penutup tidak bisa benar karena alasan yang salah — lalu mengulanginya di
halaman kedua — untuk satu akun (1-1210) dan hanya pada saldo akhir debit,
supaya angka kepala tetap milik JENDELA dan bukan milik halaman. 20 uji, 134
asersi, hijau. Di data demo: 1-1400 pada 1–31 Juli membuka Rp 0, bermutasi Rp
351.250.000 / Rp 18.740.000, menutup Rp 332.510.000, dan `trialBalance(2026, 7)`
menyebut angka yang sama. *(GeneralLedgerTest.php:88,116.)*

Yang layar itu belum lakukan — dicatat, tidak dibangun: akun **induk**
mengembalikan buku besar kosong (tidak ada roll-up ke anak) padahal pemilihnya
menawarkan seluruh bagan akun, sehingga "Bank" adalah pilihan yang masuk akal dan
menjawab dengan kosong — dan karena induk selalu `is_postable = 0`, layarnya masih
memasang spanduk "Buku besar ini masih memegang barisnya" di atas tabel yang kosong
*(bukubesar.js:405-412)*, jadi jawabannya bukan sekadar kosong melainkan salah;
tidak ada opsi "semua akun" untuk satu berkas buku besar lengkap yang biasa diminta
KAP — dan ekspor per akun pun berhenti di 10.000 baris *(bukubesar.js:42,148-151)*,
tepat pada rekening bank setahun penuh yang docblock servicenya sendiri sebut
"puluhan ribu baris"; baris neraca saldo belum bisa diklik menuju
penjelasannya; kolom Dokumen menampilkan jenis dokumen dan nomor
internalnya ("Penerimaan barang #1"), bukan `GRN/2026/VII/0001` — jenisnya sudah
diterjemahkan peta `REFERENCE_LABELS` di service, yang belum ada adalah pemeta
`reference_id` → nomor dokumen milik modulnya, dan itulah yang menuntut peta baru;
tanpa kolom lawan akun (barisnya justru
menautkan ke voucher jurnalnya — pertukaran yang disengaja); tanpa kolom
diposting-oleh/kapan; dan akun laba-rugi membuka tahun buku baru membawa saldo
kumulatif tahun lalu — konsisten dengan `trialBalance()` yang memang sengaja
all-time karena **tidak ada jurnal penutup di produk ini**, tetapi kartu "Cara
membaca" belum mengatakannya. Satu lagi yang jujur disebut: jendelanya terikat
`journal_date` saja, jadi di bulan yang periodenya masih terbuka, posting
mundur-tanggal mengubah buku besar yang sudah dicetak, dan layarnya tidak
memberi tahu apakah periode itu terbuka atau tertutup. Untuk bulan yang sudah
ditutup, `assertPeriodOpen()` menolak postingnya — pagarnya nyata, cakupannya
terbatas.

**Impor excel/csv untuk dokumen berbaris.** Satu mesin generik
(`DocumentImportService` + registry `ImportableDocuments`) melayani empat jenis:
penawaran, BOQ/RAB, AHSP, dan RAP. *Item* tidak ikut ke sini — ia sudah bisa
diimpor sejak asesmen pertama lewat impor master data yang datar, dan
menduplikasinya akan membuat dua tempat mengklaim kebenaran yang sama.

Empat keputusan yang menahan beban:

1. **Baris DIKETIK, tidak diendus.** Kolom `tipe` wajib; template selalu
   menaruhnya paling kiri, tetapi importirnya menemukan kolom itu lewat NAMA, jadi
   posisinya tidak dipaksakan. Hanya `abaikan`/`lewati` yang melewati baris dengan
   sengaja. Kosakata pelewat sengaja sempit: kata yang menamai ISI baris
   (`subtotal`, `rekap`) tidak pernah boleh menjadi kata pelewat, sebab baris
   ber-`tipe` REKAP yang sel terakhirnya berbunyi 999.000.000 akan lenyap tanpa
   suara. Tipe yang tak dikenal **menolak**, bukan melewati.
   *(ImportableDocuments.php:155-156; DocumentImportService.php:286,373,416-418.)*
2. **Uang dan koefisien dibaca dengan aturan berbeda.** `1.050` di kolom harga
   adalah seribu lima puluh; `1.050` di kolom koefisien adalah satu koma nol lima.
   Titik-sebagai-ribuan hanya berlaku pada pola ketat 1-3/3/3 berawalan bukan-nol,
   dan koefisien/persen tidak pernah mengelompokkan titik. (Bila sel yang sama juga
   memuat koma, aturan rasio memang tidak dipakai — tetapi di situ tidak ada
   ambiguitas untuk dipecahkan: pada `1.050,00` komanya jelas desimal, jadi titiknya
   memang ribuan dan 1050 adalah bacaan yang benar di kolom mana pun.) Salah baca
   di sini mengalikan harga satuan setiap item BOQ yang memakai analisa itu dengan
   seribu — dan BOQ-nya tetap tampak menjumlah dengan benar.
   *(SpreadsheetReader.php:470-473,513-517.)*
3. **Kolom `jumlah` milik berkas dibaca sebagai checksum, tidak pernah disimpan.**
   Total dihitung ulang oleh service modulnya sendiri; selisih di atas toleransi
   `max(Rp 1; 0,5%)` menolak barisnya sambil menyebut kedua angka.
4. **Harga yang hilang adalah TIDAK DIKETAHUI, bukan nol.** `(float) null`
   membuat baris bernilai Rp 0 yang lalu dijumlahkan menjadi total yang percaya
   diri untuk tagihan ratusan juta. Sekarang `computed_total` hanya menjumlahkan
   baris yang **berkasnya** hargai, dengan `unpriced_lines` di sebelahnya. Baris
   yang dihargai analisa AHSP tetapi berkasnya JUGA menulis `jumlah` **ditolak** —
   dua harga dari dua tempat, dan impor tidak berhak memilih pemenangnya.

Dua plafon baris, karena keduanya membatasi hal berbeda: 5.000 **record** (baris
yang mengalokasikan sesuatu — dokumen, baris, atau error) dan 20.000 baris
**fisik**. Plafon record dihitung sebelum pengetikan tipe, karena 20.000 baris
ber-`tipe=xx` pernah lolos dan membuat preview menjawab dengan badan 4,4 MB.
146 uji impor hijau (78 mesin generik, 20 BOQ, 17 AHSP, 16 RAP, 15 penawaran).

**Dua celah pada impor yang ditemukan justru saat memverifikasi tulisan ini**, dan
keduanya adalah persis kegagalan yang rancangannya klaim dicegah. Keduanya
dibuktikan lewat probe, bukan lewat pembacaan — dan **keduanya sudah ditutup**:

- **Baris berharga bisa lenyap tanpa suara lewat tanda `#`.** `records()` melewati
  setiap baris yang sel FISIK pertamanya diawali `#` *(DocumentImportService.php:344-350)*.
  Karena posisi kolom `tipe` tidak dipaksakan, pada berkas yang kolom pertamanya
  `uraian`, baris ber-`tipe` item seharga Rp 999.000.000 yang uraiannya berbunyi
  "#3 Pekerjaan beton" hilang tanpa error dan tanpa peringatan — sementara barisnya
  yang lain terimpor normal. Itu kata demi kata bencana yang paragraf "kosakata
  pelewat sengaja sempit" di atas dibangun untuk mencegah.
  **Ditutup:** penanda komentar kini dibaca dari kolom `tipe`, bukan dari sel fisik
  pertama — dan `template()` juga menulisnya ke kolom itu berdasarkan NAMA, karena
  dua tempat yang sepakat hanya karena urutan kolom akan berpisah pada perubahan
  urutan pertama. Yang ditukar: baris catatan bebas yang diketik di kolom uraian
  dan diawali `#` kini ditolak dengan pesan yang menyebut barisnya, alih-alih
  dilewati diam-diam. Nyaring mengalahkan senyap.
  **Dan cacat yang sama ada di importir kedua**, yang tidak ikut terperiksa sampai
  seseorang menyebutkannya: impor master data yang datar membaca penanda dari sel
  fisik pertama juga. Daftar vendor berkolom `nama` di depan akan kehilangan setiap
  baris yang namanya diawali `#` — tanpa dihitung sebagai dibuat, dilewati, maupun
  error. Di sana penanda kini dibaca dari kolom IDENTITAS (kolom `unique`, yang
  wajib diisi sehingga baris data selalu punya), sementara baris petunjuk milik
  templatenya sendiri tetap terlewati.
- **Berkas .csv tidak punya plafon baris fisik.** Plafon 20.000 dipasang sebagai
  read filter di dalam jalur PhpSpreadsheet saja *(SpreadsheetReader.php:56,275-294)*;
  jalur `readCsv` hanya dibatasi 5 MB. Probe: .csv 1,8 MB berisi 80.000 baris
  `abaikan` lolos preview tanpa satu pun penolakan, dan .csv 2,1 MB berisi 150.001
  baris terbaca seluruhnya. Plafon 5.000 record tetap mengikat semua jenis berkas —
  yang tidak terbatas adalah baris yang tidak mengalokasikan apa pun.
  **Ditutup:** `readCsv` menghitung baris fisik sebelum satu baris pun dibangun,
  dan plafon 256 kolom ikut dipasang di sana — tanpa itu berkas .csv 4,7 MB berisi
  260 kolom masih membangun 178 MB larik PHP dari sel yang tidak satu pun definisi
  bisa menamainya.

Temuan ketiga pas itu — bahwa `1.050,00` di kolom koefisien terbaca 1050 — **tidak
lolos pemeriksaan** dan tidak dicatat sebagai cacat: sel itu memuat titik DAN koma,
sehingga komanya jelas tanda desimal dan titiknya memang pemisah ribuan. 1050 adalah
bacaan yang benar. Dicatat di sini karena pemeriksa yang menemukannya salah, dan
dokumen ini sempat memuatnya.

**Audit ulang keuangan, akuntansi, dan pajak — 29 temuan, 94 invarian bertahan.**
Enam lensa paralel (seam/kontrol, pengakuan pendapatan, laporan keuangan,
tata buku berpasangan, AR/AP/pembayaran/kas, pajak), masing-masing diserang balik
oleh skeptis yang tugasnya **membatalkan** temuan. Yang paling menyentuh angka:

- **Bagan akun bisa diubah di bawah riwayat terposting.** Pemegang `fin.update`
  bisa membalik `is_postable` sebuah akun yang sudah memikul jurnal terposting.
  Neraca saldo, laba rugi, dan neraca hanya beriterasi akun postable, sementara
  `sumsPerAccount` menjumlahkan setiap baris terposting: seluruh riwayat akun itu
  keluar dari barisnya **dan** dari totalnya. Probe: membalik 1-1220 menjatuhkan
  aset dari Rp 10.890.010.000 menjadi Rp 123.010.000 — lubang Rp 10.767.000.000 —
  sementara buku besarnya sendiri tidak tersentuh dan tetap seimbang di
  Rp 22.739.070.000, dan penghalang tutup buku `trial_balance_balanced` (yang tak
  bisa dilewati) gagal. Sekarang empat kolom tersegel begitu ada baris jurnal, dan
  hanya perubahan NYATA yang ditolak — ganti nama, pindah induk, nonaktifkan tetap
  boleh, dan akun yang terlanjur salah dibalik bisa dicentang kembali lewat layar.
  *(AccountUpdateRequest.php:23-28,93-131,171-186.)*
- **Tagihan jalur klasik atas PO barang yang belum datang membebani proyek dua
  kali** ketika barangnya akhirnya tiba. Pada data demo yang dikirimkan
  Rp 209.500.000 sudah dibebankan penuh ke 5-1100 dan ke realisasi proyek 1
  sementara PO/2026/II/0001 masih tercatat `qty_received` nol — jadi duplikasinya
  BELUM terjadi; ia akan terjadi begitu barangnya diterima, dan probe mengukur
  5-1100 melompat dari Rp 228.240.000 ke Rp 437.740.000. Karena
  `RevenueRecognitionService` membaca `fin_project_costs`, persentase POC ikut
  bergerak bersamanya. Skeptis mengoreksi diagnosisnya: beban penuh DPP di jalur klasik itu
  sendiri disengaja dan berujian — cacatnya adalah tidak ada yang mencegah atau
  mendeteksi barang yang sama datang sesudahnya.
- **RAP yang menutupi sebagian proyek pada kontrak multi-proyek menggelembungkan
  POC**: penyebut menutupi sebagian, pembilang menutupi semua, dan barisnya
  distempel `rap_approved` — label kepercayaan tertinggi. 4-1100 dikredit
  Rp 500.000.000 di tempat Rp 250.000.000, pada run yang tidak pernah bisa
  dihitung ulang. Sekarang syaratnya **cakupan, bukan keberadaan**: biaya yang
  tidak tertutup anggaran menjatuhkan kontrak ke basis margin-nol PSAK 115 par. 45.
- **Membatalkan invoice yang sudah diukur run terposting** membukukan koreksi POC
  di periode yang lebih awal daripada jurnal pembatalannya sendiri — dua laporan
  laba rugi salah sebesar DPP penuh, dan satu probe ke 2027-01-12 memperlihatkan
  celahnya melintasi tahun buku ke periode yang tidak akan pernah bisa dibuka.
  Sekarang `billingsAt()` bertanya KAPAN penagihan itu benar-benar meninggalkan
  buku besar — tanggal jurnal pembaliknya, bukan `cancelled_at`.
- **Aki berita acara lapangan menggerakkan stok dan memposting jurnal hanya
  berbekal `svc.update`** — izin yang dipegang role `teknisi`, yang selain
  svc.view/create/update hanya punya `inv.view`. Direproduksi lewat kernel HTTP
  sungguhan memakai akun `teknisi@nusantara.test` yang dikirim bersama demo:
  HTTP 200, 3 unit ITM-0004 lenyap dari WH-PUSAT, JV/2026/08/0009 terposting Dr
  6-4100 / Cr 1-1400 Rp 5.550.000 — sementara token yang sama ditolak 403 pada
  `POST /api/inventory/issues`.
- **Pajak:** nomor bukti potong dulu **posisional dan tidak pernah disimpan**,
  sehingga menjalankan ulang satu masa — persis tindakan yang disuruh daftar
  penghalangnya — memberikan nomor yang sudah terbit kepada vendor lain dengan
  nilai lain; sekarang `bupot_no` ditulis saat approval dan ekspornya hanya
  MEMBACA, jadi menjalankan ulang menghasilkan berkas yang identik.
  Empat lagi, semuanya kini tertutup: `createFromPo()` DULU diam-diam
  membuang PPh yang sudah diketik operator (sekarang lewat `resolvePph()`); serial
  faktur pajak ganda DULU diterima dan diekspor dua kali di bawah satu nomor
  terbitan DJP (sekarang ditolak); baris master pajak DULU bisa dihapus-lunak
  sementara tagihan yang sudah disetujui masih memotong di bawahnya (sekarang
  ditolak); tagihan DULU bisa memotong PPh tanpa menyebut pajaknya dan jatuh ke
  2-1220 apa pun sebenarnya (sekarang wajib menyebut); dan PPN masukan DULU
  dikreditkan untuk vendor non-PKP di jalur manual padahal PO dan subkon
  menegakkan aturannya (sekarang `assertPpnCreditable` menegakkannya di sana juga).
- **Dua temuan lain sengaja TIDAK dituruti sebagaimana dilaporkan.** Toleransi
  satu sen per jurnal versus 0,01 seluruh buku besar dilaporkan sebagai cacat
  `ReportService`; skeptis membalik arahnya — laporannya yang benar, penjaga
  postingnya yang longgar — jadi laporan kini **menurunkan** toleransinya dari
  penjaga itu (satu sen per jurnal yang memang timpang, dibatasi satu sen
  masing-masing), sehingga buku besar yang seluruh jurnalnya seimbang tidak
  mentoleransi apa pun. Dan e-Faktur yang melaporkan harga jual sebagai DPP
  ditandai berlebihan: mekanismenya nyata tetapi **rupiah PPN-nya tidak pernah
  salah**.

Sembilan puluh empat invarian keuangan/akuntansi/pajak dihitung ulang satu per
satu dan **bertahan** — antara lain: `JournalService::post()` satu-satunya penulis
status jurnal terposting; tidak ada kode di luar migrasi dan seeder yang menulis
`fin_journals`/`fin_journal_lines` langsung; gerbang periode fiskal hidup di dalam
`post()` sehingga kelima belas titik panggil `autoPost` di sebelas service
mewarisinya; jurnal terposting tidak bisa diedit, ditanggali ulang, atau
dihapus-lunak; dan neraca saldo debit = kredit untuk setiap bulan 2026 di data
demo, dengan neraca seimbang pada empat tanggal berbeda.

**Audit ulang persediaan — 21 temuan.** Yang ditutup antara lain: `sendTransfer`
tidak pernah menyegarkan rata-rata tertimbang tingkat item selama seluruh jendela
transit; setiap mutasi stok kini tunduk pada tutup buku **termasuk yang nilainya
membulat ke nol dan transfer yang tidak memposting jurnal sama sekali** (audit
memindahkan Rp 150.000 nilai di dalam periode yang sudah ditandatangani, tanpa
jurnal dan tanpa error); `erp:inventory-method-check` yang dulu mencetak sub-buku
dan 1-1400 berdampingan **tanpa satu pun uji kesamaan di seluruh berkas** dan
menghitung nol opname karena memfilter kolom status yang salah, kini memunculkan
penghalangnya sendiri; plafon over-receipt yang dilewati begitu `po_item_id` kosong
(1000 zak datang atas pesanan 100 zak tanpa penolakan); item yang dihapus bisa
mendamparkan barang di jalan selamanya; dan pembatalan bon material — satu-satunya
dokumen persediaan yang kini punya jalan pulang.

**Ketika gelombang perbaikan merusak pekerjaannya sendiri.** Bagian paling
instruktif dari pas ini bukan temuan auditnya, melainkan apa yang dilakukan
perbaikannya sendiri — dan semuanya ditangkap oleh verifikasi penutup, bukan oleh
penulisnya:

- Gelombang yang sama yang menetapkan **aturan costing 2** ("setiap kaki jurnal
  adalah perubahan saldo tersimpan") menulis pembatalan bon yang membuang nilai
  kembalian `applyIn()` dan justru membalik nilai jurnal ASLI. Ketika ada
  penerimaan berharga beda mendarat di antara bon dan pembatalannya, kedua angka
  itu berpisah — residu terukur Rp 7,53 *(IssueCancellationTest.php:250)*,
  yang menjatuhkan penghalang tie-out **yang baru saja dibangun gelombang itu
  juga**. Residu sekecil satu sen justru lolos toleransi penghalang itu
  (`max(0,01; 0,01 × jumlah baris saldo)`) dan hanya tertangkap oleh asersi
  kesamaan persis di uji — pengingat bahwa perintah kesehatan itu punya lantai.
- Pembatalan yang sama membebaskan cermin stoknya dari **penjaga kronologi yang
  ditambahkan gelombang itu juga**, dan menanggalinya di masa lalu — memunculkan
  kembali persis `balance_qty_after` tak berurutan yang penjaga itu dibangun untuk
  melenyapkan — kartu yang seharusnya terbaca 100/60/110/100/140 justru berakhir
  40 unit di bawah rak *(IssueCancellationTest.php:186-215)*.
- Di data demo — dokumen yang menjadi alasan pembatalan itu dibangun — biaya bon
  ISS/2026/VII/0001 mencapai 5-1100 lewat jurnal KEDUA (JV/2026/07/0008, ditulis
  migrasi lama karena bonnya diposting saat tabel proyek masih kosong). Membalik
  hanya jurnal milik bon menjatuhkan `fin_project_costs` ke Rp 209.500.000
  sementara laba rugi proyek 1 tetap Rp 228.240.000.
- Gerbang periode baru pada `receiveTransfer` **mendamparkan barang dalam
  perjalanan secara permanen**: menutup bulan di atas truk yang sedang jalan
  membuat "Terima" melempar selamanya, sementara transfer in-transit tidak bisa
  dihapus dan `inv_transfers` belum menjadi sumber DanglingDocuments saat itu.
  Lewat gerbang periode, keadaan itu **tidak mungkin terjadi sebelum gelombang
  tersebut** — meski barang di jalan memang sudah bisa terdampar permanen lewat
  jalan lain yang gelombang itu juga tutup, yaitu penghapusan item. Sekarang
  kedatangan memakai tanggal kirim selama periodenya masih terbuka dan belum
  diukur, dan jatuh ke hari ini begitu tidak; transfer draf dan in-transit pun
  masuk daftar dokumen menggantung *(DanglingDocuments.php:211-217)*.

- Dan sekali lagi, pada gelombang yang menutup T40. Menambahkan jalan pulang dari
  status Diajukan berarti Diajukan berhenti menjadi pintu satu arah — sehingga
  ketiga transisinya bisa saling mendahului. `returnToDraft()` memutuskan dari
  status pada model yang di-*bind* rute, jadi: ikat laporan saat Diajukan, biarkan
  `acknowledge()` milik permintaan lain commit, lalu panggil `returnToDraft()` —
  laporan yang SUDAH disahkan jatuh kembali ke Draf, bisa diedit, dengan bon
  terposting dan jurnal terposting masih menunjuk kepadanya. Persis keadaan yang
  docblock `canReturnToDraft()` sendiri bilang tidak boleh ada. Uji kontrol
  negatifnya tidak pernah melihatnya karena ia selalu memakai `->fresh()`, yang
  bukan yang dilakukan route-model binding. Sekarang ketiga transisi memutuskan
  dari baris yang **dibaca ulang di dalam transaksinya** — `lockForUpdate` adalah
  no-op di SQLite, jadi pembacaan ulangnya yang menjadi penjaga, bukan kuncinya.

Pola yang layak diingat: setiap kali sebuah gelombang menetapkan aturan baru dan
sekaligus menulis fitur yang tunduk pada aturan itu, fiturnya melanggar aturannya.
Kelima kalinya bahkan lebih tajam — gelombang yang MENUTUP sebuah kemacetan
membuka balapan yang bisa membatalkan pengesahan yang sudah terposting. Itulah
sebabnya setiap gelombang ditutup oleh pemeriksa yang **mereproduksi**, bukan
membaca; dan mengapa uji regresinya dijalankan lebih dulu dengan perbaikannya
dilepas, untuk membuktikan uji itu benar-benar gagal tanpa perbaikan.

**Cacat kecil yang tersingkap oleh pas verifikasi ini — semuanya kini
dikerjakan.** `periods.js` menjanjikan operator "Sembilan syarat — lima penghalang
keras, empat peringatan" padahal servicenya mengembalikan sepuluh (lima dan lima);
pesan penolakan impor berbunyi "berharga dari analisa" padahal penjaganya berlaku
untuk semua jenis baris, termasuk penawaran yang tidak mengenal analisa, dan
remedinya ("kosongkan kolom jumlah") hanya bisa dijalankan pada satu dari empat
sumber daya; 403 impor mengatakan "tidak boleh mengimpor" bahkan saat yang ditolak
adalah unduh template; docblock `assertMovementInOrder` — penjelasan yang dirujuk
seluruh aturan costing 1 — terlanjur menempel pada fungsi tetangganya; komentar
`InventoryMethodCheck` menjanjikan toleransi "satu rupiah per baris" padahal
kodenya memberi satu **sen**; `StockDocumentStatus` menunjuk "module notes" yang
tidak ada di repo mana pun; dan docblock `DeploymentService` menyebut dua
mobilisasi terbuka padahal datanya punya tiga.

Yang terakhir itu ikut membawa kunci akrual yang salah, dan koreksinya bukan yang
pertama terpikir: membuang `deployment_id` demi `tahun*100 + bulan` justru
menabrakkan setiap mobilisasi terbuka pada bulan yang sama menjadi satu baris —
kuncinya harus membawa keduanya.

Satu hal dari daftar itu sengaja TIDAK diubah: aturan `project_id` pada endpoint
buku besar tetap tanpa `whereNull('deleted_at')`. Asimetrinya dengan `account_id`
memang benar, dan sekarang tertulis begitu — `account_id` adalah SUBJEK laporan,
dan akun terhapus tidak punya buku besar untuk digambar; `project_id` hanya
MENYEMPITKAN subjek itu, dan baris milik proyek yang sudah ditutup lalu dihapus
tetap riwayat yang sah, masih terposting dan masih terhitung di 1-1300. Menolaknya
akan menutup pintu bagi pembaca yang paling membutuhkannya. Yang salah bukan
perilakunya, melainkan bahwa asimetrinya tampak tidak sengaja.

### Yang sengaja dipagari — tinggal satu yang TIDAK ditutup (T13 ditutup 22 Agustus)

Masing-masing diperiksa ulang ke kode hari ini, dan pertanyaan yang menentukan
selalu sama: **apakah ini menghasilkan angka yang salah hari ini, atau ini celah?**
T40 ditutup 5 Agustus; **T43, T28/T29, dan sebagian besar T37 menyusul
8 Agustus** — akrual alat bulanan ber-checklist (satu-satunya "Ya" di tabel ini
padam; bulan lampau yang menunggu `ast:accrue-plant` operator DIKEJAR 22 Agustus:
Maret–Juli terakru, Rp 573.000.000), nilai dalam-perjalanan di Saldo Stok dari
query yang sama dengan CLI, dan pembatalan penerimaan barang lengkap dengan
pemulihan qty PO. **T13 ditutup 22 Agustus** — keputusan operabilitas diambil
pemilik: `teknisi` diberi `inv.post`. Yang tersisa dipagari hanya **T26**
(re-costing retrospektif — keputusan rancangan, sistem menolak alih-alih salah).
Uraian di bawah dipertahankan sebagai riwayat KEADAAN SAAT DIPAGARI — status
terkininya di [LAPORAN-DEVIASI.md](LAPORAN-DEVIASI.md) §d.

| # | Pagar | Saat dipagari | Status kini |
|---|---|---|---|
| T43 | Biaya alat internal ditulis satu gelondong saat demobilisasi | **Ya** — angka manajemen salah | **DITUTUP** 8 Agu — akrual bulanan + residual + checklist; catch-up operator 22 Agu |
| T26 | Re-costing retrospektif tidak ada; mesinnya menolak dokumen mundur-tanggal | Tidak | Tetap dipagari (rancangan) |
| T28/T29 | Nilai barang dalam perjalanan tidak ada di layar Saldo Stok | Tidak — laten | **DITUTUP** 8 Agu — ubin + query bersama CLI |
| T37 | Hanya bon yang bisa dibatalkan; penerimaan, transfer, opname tidak | Tidak — remedi hilang | **Penerimaan kini bisa dibatalkan** (8 Agu); transfer & opname tetap (rasional tertulis) |
| T13 | Tidak ada role tersemai selain admin yang bisa menuntaskan kunjungan bersuku-cadang | Tidak | **DITUTUP 22 Agu** — `teknisi` diberi `inv.post` (keputusan pemilik; pelebarannya dinyatakan di kode) |

**T43 — dan pagarnya sendiri terlalu ramah pada dirinya.** `chargeProject()`
hanya dipanggil dari `returnDeployment()`, jadi mobilisasi yang masih berjalan
tidak menyumbang biaya proyek sepanjang hidupnya, lalu seluruh beban
berbulan-bulan mendarat dalam satu baris bertanggal hari demobilisasi. Pagarnya
menulis "angka kumulatifnya benar — tidak ada yang hilang atau terhitung dua
kali", dan itu benar **hanya setelah demobilisasi**. Hari ini, dengan tiga alat
masih di lapangan (pagarnya sendiri hanya menyebut dua), ember biaya peralatan
berisi **Rp 0**: dua di proyek 1 sejak 2026-03-02 (lima bulan, Rp 542.500.000)
dan DEP/2026/V/0003 di proyek 2 sejak 2026-05-11 (Rp 42.500.000) — total **Rp
585.000.000** belum terakru, dan proyek 2 belum punya RAP sama sekali sehingga
paparannya tidak masuk rasio mana pun — sementara RAP/2026/0001 menganggarkan Rp
178.031.790,79 untuk peralatan, dan `docs/KEBIJAKAN-PENDAPATAN.md:115-117`
menyebut "alat dari mobilisasi" sebagai salah satu dari empat sumber basis biaya
POC. Pembilang tanpa peralatan di atas penyebut dengan peralatan **mengecilkan
persentase progres dan karenanya pendapatan kumulatif**. Yang menyelamatkan hari
ini: `fin_revenue_recognition_runs` masih kosong, jadi belum ada laporan laba
rugi terposting yang salah — yang salah adalah setiap angka hidup/pratinjau yang
dihitung dari basis itu (AC, CPI, EAC, POC yang dihitung ulang). Skalanya: Rp
542.500.000 yang belum diakru untuk proyek 1 adalah **3,05×** seluruh anggaran
peralatan proyek itu. Mitigasi yang sudah ada, dengan satu catatan: aturan
cakupan EVM memang menandai `cpi_status = cost_incomplete` — tetapi bukan karena
peralatan. Ember peralatan hanya satu dari EMPAT kategori beranggaran yang
realisasinya nol (upah, subkon, overhead, peralatan), jadi menutup T43 tidak
akan menurunkan bendera itu. **Catatan untuk yang menutupnya nanti:** kunci
akrual yang diusulkan pagarnya (`deployment_id * 100 + bulan`) tidak membawa
tahun — Maret 2026 dan Maret 2027 menghasilkan `reference_id` identik, dan
`ProjectCostService::record()` adalah `updateOrCreate`, jadi tahun kedua akan
**menimpa** tahun pertama diam-diam. Pakai kunci yang membawa keduanya —
`deployment_id * 1000000 + tahun*100 + bulan`, atau referensi string per
(mobilisasi, bulan). Mengganti dengan `tahun*100 + bulan` saja justru lebih
buruk: itu membuang `deployment_id` dan menabrakkan setiap mobilisasi terbuka
pada bulan yang sama menjadi satu baris. Bagian tersulitnya bukan aritmetika
melainkan urutan terhadap tutup buku: akrualnya harus mendarat sebelum bulannya
ditutup, sehingga mengaitkannya ke checklist tutup buku lebih tepat daripada
cron malam yang bisa melewatkan satu bulan selamanya.

**T26 — pagarnya benar, kalimatnya berlebihan.** Penjaga kronologi menolak setiap
mutasi bertanggal sebelum baris kartu stok terakhir untuk (gudang, item) itu, dan
dipanggil dari lima jalur. Pagarnya menulis re-costing "tidak tersedia begitu
periode fiskal ditutup"; yang benar adalah **ditutup DAN sudah diukur run PSAK 115
terposting** — `reopen()` ada dan bekerja, dan sebuah bulan tertutup bukan pintu
satu arah dengan sendirinya. Satu catatan presisi: penjaganya berbutir HARI, bukan
urutan, jadi mutasi mundur di dalam hari yang sama tetap diterima dan dinilai pada
rata-rata hari ini — disengaja dan berujian.

Pagarnya juga bukan satu-satunya sumber angka yang perlu dibaca: layar Saldo Stok
hanya menjumlahkan HALAMAN yang dimuat (`per_page: 200`), jadi di atas 200 baris
saldo "Nilai persediaan" diam-diam kurang, terlepas dari barang di jalan.

**T28/T29 — dan koreksi pada pagarnya.** `StockController::balances()` memang
persis koleksi terpaginasi tanpa agregat apa pun, dan layar Saldo Stok
menghitung "Nilai persediaan" di klien dari `stock_value` per baris — lalu
mencetaknya **dua kali**, sebagai ubin statistik dan sebagai footer tabel. Yang
perlu ditegaskan: hanya CLI yang mencetak rupiah — checklist tutup buku sekadar
tahu ADA transfer terbuka, ia menampilkan jumlah dokumen dan sampai lima kode,
tanpa uang *(DanglingDocuments.php:322-334)*. Paparannya nihil hari ini:
satu-satunya transfer sudah `received`, sub-buku Rp 332.510.000 dan 1-1400 Rp
332.510.000 bertemu sampai ke rupiah.

**T37 — pagarnya meratakan tiga hal yang tidak sama.** Ketiadaannya nyata untuk
ketiga dokumen, tetapi **akibatnya menumpuk di penerimaan barang**: GRN terposting
tidak bisa diedit, dihapus, atau dibalik; nilainya duduk di 1-1400 dengan kredit
kliring yang masih bisa dilunasi sebuah tagihan; dan ia menggelembungkan three-way
match secara permanen karena `registerReceipt()` hanya pernah MENAMBAH
`qty_received` — tidak ada pengurangnya di mana pun. Mengoreksi GRN keliru berarti
opname (yang nilainya jatuh ke 6-4400 Selisih Persediaan — akun yang salah) plus
JV manual, dengan kuantitas diterima PO salah selamanya. Transfer ringan (selalu
bisa diselesaikan lalu dikembalikan dengan transfer kedua); opname ringan dengan
syarat (opname kedua mengoreksi; kedua selisih 6-4400 saling meniadakan hanya bila
tidak ada mutasi berharga beda di antaranya — dan keduanya tetap jatuh di bulan
yang berbeda, jadi laba rugi bulan pertama tetap salah).

**T40 — DITUTUP, dan di sinilah verifikasi menemukan sesuatu yang lebih buruk
dari pagarnya.** `warehouse_id` boleh kosong saat simpan maupun ubah, dan `submit()`
tidak menuntut laporan bersuku-cadang punya gudang. Bila laporan bersuku-cadang
diajukan dengan `warehouse_id` kosong: `acknowledge` melempar "gudang asalnya belum
diisi", dan perbaikan yang disuruhnya — isi kolom Gudang — persis yang ditolak
`update()` karena laporannya bukan Draft lagi. Dokumen itu menjadi tidak bisa
diakui, tidak bisa diubah, tidak bisa dihapus, tidak bisa ditanggali ulang; ia
muncul di DanglingDocuments hanya berbekal keberadaan suku cadang; penghalangnya
BLOCK keras tanpa jalur override; dan karena tutup buku berurutan, **bulan itu dan
setiap bulan sesudahnya tidak akan pernah bisa ditutup**. Ini tidak menunggu
periode tertutup untuk terjadi. Tidak ada instansnya di data live — satu-satunya
baris sudah `acknowledged` — jadi ini belum menggigit siapa pun.

Dan gudang kosong bukan satu-satunya pintunya: `acknowledge` juga menjalankan
penjaga kronologi stok pada `report_date`, jadi
laporan bersuku-cadang yang hari kunjungannya jatuh di belakang mutasi terakhir
(gudang, item) macet dengan cara yang persis sama dan sama permanennya — dan itu
terjangkau di data live hari ini — sehingga penjaga gudang saja tidak akan cukup.

**Yang dibangun, keduanya.** `submit()` kini menjalankan syarat pengeluaran barang
sebagai UJI KERING, dan menjalankannya dengan cara yang tidak bisa berbohong:
bukan dengan menyalin aturannya ke modul ini — dua salinan akan berpisah pada
perubahan pertama, dan salinan yang masih bilang "boleh" sementara posting
sebenarnya bilang "tidak" adalah persis wedge-nya — melainkan dengan **menjalankan
jalur aslinya** (`IssueService::create()` → `StockService::postIssue()`, dua
panggilan yang sama dengan yang dipakai `acknowledge`) di dalam transaksi yang
SELALU di-rollback. Apa pun yang akan menolak tanda tangan nanti menolak draf
sekarang, dengan kalimat yang sama. Rollback-nya total termasuk nomor bon, jadi uji
kering tidak membakar nomor dokumen.

Dan karena lulus uji kering hari ini bukan janji untuk besok — satu GRN atau satu
bon lain yang mendarat pada (gudang, item) yang sama setelah hari kunjungan
menggeser `MAX(trx_date)` melewati `report_date`, dan sejak itu bon laporan ini
ditolak selamanya, tanpa teknisinya berbuat apa pun — **status Diajukan kini punya
jalan pulang**. Laporan yang belum disahkan bisa kembali ke Draf, ditanggali ulang
atau dikosongkan suku cadangnya, lalu diajukan lagi. Yang sudah **disahkan tetap
satu arah**: `inv_issues.field_report_id` UNIK dan `cancelIssue` menolak bon yang
lahir dari berita acara, jadi jalan pulang di sana akan meninggalkan bon di buku
besar yang menunjuk laporan yang mengaku suku cadangnya tidak pernah keluar.

Layarnya ikut: tombol "Kembalikan ke Draf" dan — yang nyaris terlewat — **kolom
"Gudang suku cadang" pada formulirnya**, yang selama ini tidak pernah ada. Tanpa
kolom itu uji kering yang baru justru akan membuat setiap berita acara bersuku-
cadang yang dibuat lewat SPA mentok di Draf selamanya: ditolak karena gudang
kosong, pada kolom yang formulirnya tidak render. Perbaikan yang menciptakan
kemacetan versinya sendiri.

**T13 — seam yang tidak pernah dipasang** (ini yang kelima; **ditutup 22 Agustus
2026**). Setelah `acknowledge` menuntut `inv.post` untuk laporan bersuku-cadang,
tidak ada role tersemai yang bisa menuntaskan kunjungan yang memakai suku cadang
kecuali admin: `warehouse` tidak punya keduanya — bukan `svc.update` maupun
`inv.post` — sementara `teknisi` sudah punya `svc.update` dan hanya kurang
`inv.post`, jadi jalan termurahnya adalah memberi `teknisi` satu izin, bukan
memberi `warehouse` dua. Lubang keamanannya tertutup sejak itu; keputusan
operabilitasnya diambil pemilik 22 Agustus 2026 — jalan termurah itu yang
dipilih: `RoleSeeder` menyemai `teknisi` dengan `inv.post` dan migrasi Iam
000242 memasangnya pada tenant hidup, dengan pelebaran izinnya (8 rute
persediaan) dinyatakan di kode dan diterima. Regangan yang tinggal dicatat di
PANDUAN-ADMINISTRATOR.md §12(b).

### Yang ditemukan dalam perjalanan — lima ditutup, satu sebagian (3 Agustus 2026)

Enam hal yang tersingkap sambil mengerjakan paket 1–12, dicatat waktu itu
sebagai utang. Lima ditutup tuntas; yang keenam (cache/SQLite) diperbaiki untuk
cache saja — sesi dan antrean masih berbagi berkas yang sama, menunggu keputusan
pemilik. Masing-masing diverifikasi ulang ke kode hari ini, dan ketiganya yang
menyentuh basis data juga diperiksa **hidup di produksi** (kolom dan akunnya ada
di `database.sqlite` erp1, berkas sumbernya md5-identik dengan dev).

- ~~**Tidak ada satu pun jalur untuk menutup periode fiskal.**~~ **DITUTUP.**
  `assertPeriodOpen()` tidak lagi hampa. `PeriodCloseService` menghitung ulang
  **sepuluh** butir checklist dari tabel sumber pada setiap permintaan — lima
  penghalang keras, lima peringatan — dan `close()` menghitungnya ulang lagi di
  dalam transaksinya sendiri alih-alih memercayai apa yang dikirim layar. Tutup
  berurutan tertua-dulu, buka-kembali terbaru-dulu, dan izinnya sengaja asimetris:
  menutup butuh `fin.post`, membuka butuh `fin.approve`, supaya yang bisa
  memposting tidak bisa membuka bulan yang ingin ia isi. Satu presisi yang layak
  dicatat: butir `revenue_recognition_posted` **bersyarat** — ia penghalang keras
  di tiga cabang, tetapi peringatan yang boleh dilewati bila yang ada adalah run
  terposting untuk periode yang LEBIH BARU ("Penutupan boleh dilanjutkan"), yang
  baru terjangkau setelah ada satu run terposting untuk periode yang lebih baru. Di
  data demo `fin_revenue_recognition_runs` masih kosong, sehingga setiap periode
  lampau justru jatuh ke cabang penghalang keras: bulan lama belum dapat ditutup
  sebelum satu run PSAK 115 diposting.
  55 uji di empat berkas periode (PeriodCloseTest 16, PeriodReopenTest 8,
  PeriodCloseChecklistTest 23, PeriodCloseApiTest 8).
  *(PeriodCloseService.php:83,373,666,743;
  Routes/api.php:156,160.)*
- ~~**Pembayaran terposting tidak dapat dibalik.**~~ **DITUTUP.**
  `PaymentService::reverse()` adalah pembalikan, bukan penyuntingan: jurnal asli
  tidak disentuh, `reverseFor()` mencerminkan BARISNYA (bukan menurunkannya
  ulang), jadi bentuk apa pun yang dipilih `post()` hari itu dibatalkan persis.
  `amount_paid` kembali ke setiap dokumen yang dialokasi menyebutnya — itulah
  yang membuka `ArInvoiceService::cancel()`. Statusnya terminal `Reversed`,
  bukan draf, karena memposting ulang akan menggandakan kaki banknya. **Soal
  tanggal:** cerminnya memakai `reversalDate()`, yang mengembalikan tanggal
  pembayaran itu sendiri hanya bila periodenya ada, terbuka, DAN belum diukur
  run PSAK 115 terposting; selain itu jatuh ke hari ini — dan bila periode hari
  ini sendiri belum ada atau sudah ditutup, pembalikannya ditolak sama sekali,
  bukan ditanggali paksa. **Lima** keluarga penolakan menjaga pembalikan yang
  akan berbohong: kas kecil, pembayaran yang sudah dicocokkan baris rekening
  koran, invoice yang sudah meninggalkan status Approved, invoice yang
  retensinya sudah dicairkan, dan tagihan AP yang sudah meninggalkan status
  Approved. *(PaymentService.php:463,491,554-639; JournalService.php:305.)*
- ~~**`WithholdingType` hanya mengenal PPh final 4(2) dan PPN wapu.**~~ **DITUTUP.**
  `Pph23` memetakan ke akun **1-1710**, sengaja BUKAN 1-1700: PPh final 4(2) adalah
  pajak final sementara PPh 23 adalah kredit pajak yang mengurangi PPh Badan tahun
  itu; diparkir dalam satu saldo, SPT Tahunan akan mengklaim kredit yang tidak ada
  atau merelakan kredit yang ada. Akunnya dibuat sebagai **saudara** 1-1700, bukan
  anaknya — mendemosi 1-1700 menjadi grup akan menjatuhkan saldo terpostingnya
  keluar dari setiap laporan ber-`is_postable` (persis cacat yang ditemukan audit
  pada bagan akun). Sisi piutangnya lengkap sampai ke layar: form penerimaan
  menawarkan nilai PPh 23 beserta kolom nomor bukti potongnya sendiri.
  *(WithholdingType.php:52,68,80.)*
- **Cache dan data aplikasi berbagi satu berkas SQLite — SUDAH DIPERBAIKI
  2026-08-02, dan masih berlaku.** Setiap permintaan menulis penghitung rate limit
  ke tabel `cache` di berkas SQLite yang sama dengan data bisnis. Terbukti merusak
  layar, bukan teori: 23 dari 24 error produksi hari itu adalah `database is
  locked`, dan pada muat dasbor kartu Kalender Acara serta ubin "Termin siap
  ditagih" hilang diam-diam. Diverifikasi ulang hari ini pada berkas produksi
  langsung — dan bukan hanya `.env`-nya, sebab konfignya di-cache: `.env:34`
  `CACHE_STORE=file`, dan `bootstrap/cache/config.php` (dibangun ulang 3 Agustus)
  benar-benar me-resolve `cache.default=file`. Perbaikannya awet lintas deploy
  karena `sync-erp1.sh` mengecualikan `.env` dari rsync-nya. Sejak itu **nol**
  `database is locked` di log Laravel; 5xx yang tersisa satu — 504 upstream timeout
  pada `/api/core/notifications/unread-count`, 2 Agustus 12:05 UTC, 49 menit setelah
  perbaikan (timeout PHP-FPM tidak pernah sampai ke log Laravel, jadi paruh pertama
  tidak bisa dipakai membuktikan paruh kedua) — dan **nol** sepanjang 3 Agustus.
  **Sisa yang belum dikerjakan:** `SESSION_DRIVER=database` masih menulis tiap
  permintaan ke berkas yang sama (`session.connection` null → tabel `sessions` di
  `database.sqlite`), begitu pula antrean. Sengaja tidak diubah — memindahkannya
  mengeluarkan semua pengguna yang sedang masuk, keputusan pemilik, bukan
  keputusan teknis sepihak.
- ~~**Pemilih menawarkan apa yang dijaga akan ditolak.**~~ **DITUTUP**, dan mata
  rantai tengahnya ikut diperiksa alih-alih dipercaya: field kas kecil memakai
  lookup `pettyCashAccounts` tersendiri, lookup itu mengirim `{ is_postable: 1,
  code_prefix: '1-11' }`, dan `AccountController::index` benar-benar menghormati
  `code_prefix` dengan klausa `LIKE`. Tanpa baris controller itu filternya akan
  menjadi no-op yang senyap. Yang tetap berlaku **di data demo**: belum ada satu
  pun akun 1-11xx postable, sebab migrasinya mengubah 1-1100 menjadi grup dan
  **sengaja tidak** membuat akun laci — jadi di demo kas kecil masih menunggu
  langkah penyiapannya, persis seperti bunyi teks bantuan di layarnya. **Di
  produksi langkah itu sudah dikerjakan** pada 2 Agustus 2026: akun 1-1110
  "Petty Cash HO" (postable, aktif) dan laci KK-HO "Kas Kecil Head Office"
  berdana tetap Rp 10.000.000 sudah ada, dan pemilihnya kini menawarkannya.
  *(kaskecil.js:97; lookup.js:40; AccountController.php:35.)*
- ~~**Kegagalan senyap pada ubin dasbor.**~~ **DITUTUP**, dan kedua paruhnya
  diperiksa — mekanisme tanpa konsumen hanyalah kode mati. `safe()` kini mencatat
  sebabnya ke konsol dan mengembalikan `Object.assign([], { loadFailure: error })`:
  tetap larik kosong, sehingga setiap `.filter`/`.reduce`/`.length` di hilir tetap
  jalan dan satu sumber mati tidak bisa menggelapkan dasbor — tetapi kini bisa
  dibedakan. `failure(rows)` sengaja mengembalikan null untuk `[]` polos yang
  dihasilkan gerbang izin, jadi sumber yang tidak diizinkan tidak pernah dihitung
  sebagai gagal. Ubin uang yang gagal menampilkan "—", tidak pernah Rp 0 yang
  percaya diri — "Hutang belum dibayar Rp 0" pada dasbor yang gagal memuat
  ap-bills adalah kebohongan yang rapi. *(dashboard.js:63-69.)*

## Yang sengaja TIDAK disarankan

- **Multi-valuta penuh** — belum ada kontrak impor material di data; cukup
  kolom catatan kurs indikatif di PO bila mulai membeli dari principal.
  Putuskan FX sungguhan hanya bila volumenya nyata (berminggu-minggu kerja).
- **Portal pelanggan** — nilai tinggi, tapi membuka permukaan keamanan baru
  (auth eksternal) yang belum sepadan sebelum proses internal tersambung.
- **Multi-entitas/intercompany** — arsitektur satu badan hukum adalah batasan
  sadar; dokumentasikan, jangan bangun sebelum ada PT kedua.
- **Aplikasi mobile native** — layar Lapangan + perbaikan `hideOnNarrow` jauh
  lebih murah; approval dari ponsel cukup lewat browser.
- **Membangun ulang lookup jadi framework** — satu komponen combobox vanilla
  di `buildInput` cukup; jangan tergoda menambah dependensi build.
- **Peminjaman alat kecil (checkout/checkin)** — register pemegang untuk
  `item_type=tool` menunggu proses gudangnya sendiri jelas; sampai itu ada,
  kartu stok per gudang sudah menjawab "di mana barangnya" dan register
  peminjaman hanya akan menjadi daftar kedua yang tidak dirawat. (Butir ini
  semula hanya dirujuk tabel Bagian B tanpa pernah dienumerasi di sini —
  pagar yang menggantung, kini ditambatkan.)

---

*Metode dan bukti — asesmen (1 Agustus): 5 auditor + 1 kritikus (34 ribu baris
dibaca, setiap klaim ber-bukti berkas:baris), kritikus memverifikasi ulang klaim
berisiko ke kode dan DB demo — nol temuan gugur — dan uji klik langsung di
erp1.pi2.co.id. Daftar temuan mentah 81 temuan pertama:
[ASSESSMENT-LANJUTAN-LAMPIRAN.md](ASSESSMENT-LANJUTAN-LAMPIRAN.md).*

*Pelaksanaan (1–3 Agustus): setiap paket melewati bangun → tinjauan lawan
multi-lensa → perbaikan → verifikasi oleh pemeriksa independen yang
**mereproduksi**, bukan membaca — pola yang menangkap empat cacat yang dibuat oleh
gelombang perbaikan itu sendiri, tiga di antaranya melanggar aturan yang gelombang
itu baru saja tetapkan. Audit ulang 3 Agustus memakai enam lensa keuangan dan dua
lensa persediaan, masing-masing diserang balik oleh skeptis yang tugasnya
membatalkan temuan; sebagian berhasil — enam temuan keuangan diturunkan
keparahannya dan satu pagar dikoreksi kalimatnya — dan koreksi merekalah yang
dipakai, termasuk sekali ketika perbaikan yang diusulkan justru akan membuat buku
besar berbohong.*

*Catatan pelaksanaan di berkas ini melewati DUA pas verifikasi berkas-per-berkas —
satu sebelum ditulis, satu terhadap tulisannya sendiri. Yang pertama mengoreksi 34
klaim, yang kedua 38 lagi dari 182 klaim yang diperiksa: nomor baris yang bergeser,
hitungan uji yang salah, kalimat yang lebih berani daripada kodenya, satu skor
kematangan yang bertentangan dengan halaman lain di berkas yang sama, dan tiga
celah impor yang justru baru ditemukan saat memverifikasi paragraf yang mengklaim
celah itu dicegah — dua nyata dan sudah ditutup, satu tidak lolos pemeriksaan dan
dicatat sebagai kesalahan pemeriksanya. Angka yang tersisa di sini adalah angka
yang bertahan.*

*Pas ketiga menutup apa yang kedua temukan, dan gelombang perbaikannya sendiri
diperiksa dengan aturan yang sama: setiap uji regresi dijalankan LEBIH DULU dengan
perbaikannya dilepas, untuk membuktikan uji itu memang gagal tanpanya. Dua di
antaranya gagal persis seperti seharusnya, dan itulah yang menangkap balapan yang
bisa membatalkan pengesahan terposting. Suite: 2.297 hijau. Pint bersih pada
seluruh berkas yang dimiliki repo ini. Sudah dideploy ke erp1.*
