# Laporan Deviasi — Asesmen Lanjutan vs Realisasi

**Nusantara ERP** · disusun 6 Agustus 2026 · pembanding:
[ASSESSMENT-LANJUTAN.md](ASSESSMENT-LANJUTAN.md) +
[lampirannya](ASSESSMENT-LANJUTAN-LAMPIRAN.md) (1 Agustus) vs pohon kode hari ini

> Asesmen lanjutan meresepkan perbaikan untuk 81 temuan, lengkap dengan taksiran
> usaha per butir dan rencana 12 paket prioritas. Eksekusinya berjalan 1–3 Agustus
> dan tercatat dalam catatan pelaksanaan — tetapi catatan itu tersusun per PAKET,
> bukan per TEMUAN, dan berulang kali merekam pelaksanaan yang MENYIMPANG dari
> resepnya. Laporan ini merekonsiliasi keduanya: untuk setiap temuan, apa yang
> diresepkan vs apa yang benar-benar ada di kode **hari ini** — diverifikasi ulang
> dengan membuka berkasnya, bukan mengutip catatan pelaksanaan, karena catatan itu
> pernah tertinggal dari kenyataan (akun kas kecil produksi sudah ada sebelum
> dokumennya mengakui).
>
> **Metode:** sembilan pemeriksa paralel per rumpun modul membuka kode untuk
> ke-81 temuan (setiap status berbukti `berkas:baris` yang dibaca 6 Agustus), plus
> satu pemeriksa angka; hasilnya ditulis, lalu **diserang balik** oleh pas
> verifikasi kedua atas tulisan ini sendiri — pola yang sama dengan dokumen
> induknya.

---

## Ringkasan eksekutif

**77 dari 81 temuan ditutup** — 43 persis seperti resepnya, 5 lewat mekanisme
yang berbeda, dan 29 **melampaui** resepnya. Tidak ada lagi yang sebagian atau
belum disentuh. **4 sengaja ditolak**, masing-masing dengan pagarnya: #60 portal
pelanggan dan #65 multi-valuta (alasan tertulis sejak awal), #71 peminjaman alat
(pagar Bagian B), dan #13 BBM & hour meter — dipagari sadar pada gelombang
ketiga, menunggu keputusan pemilik tentang alur pencatatan lapangan.
**Kesepuluh temuan kritis tuntas.** (Angka SESUDAH tiga gelombang perbaikan
6-8 Agustus — 42 temuan ditutup pasca-laporan; riwayatnya di catatan gelombang.)
Distribusinya tidak acak:

| Keparahan | Jumlah | ✅ | ➕ | 🔀 | 🟡 | 🚫 | ⬜ |
|---|---|---|---|---|---|---|---|
| 🔴 Kritis | 10 | 3 | 7 | — | — | — | — |
| 🟠 Besar | 44 | 19 | 19 | 5 | — | 1 | — |
| 🟡 Kecil | 27 | 21 | 3 | — | — | 3 | — |
| **Total** | **81** | **43** | **29** | **5** | **—** | **4** | **—** |

Tiga kalimat yang merangkum tabel itu:

1. **Kesepuluh temuan kritis tuntas**, termasuk #1 yang paruh daftarnya
   (filter lookup) baru rampung di gelombang kedua — memakai combobox yang
   sama dengan form, bukan fork.
2. **Pola pelaksanaannya melampaui, bukan sekadar mematuhi.** 29 temuan ditutup
   lebih ketat/lebih lengkap dari resepnya — dan justru di antara yang paling
   menyentuh uang (SoD, periode fiskal, retensi subkon, EVM, tagihan parsial).
3. **Backlog-nya habis.** Saat laporan pertama disusun, 33 temuan belum
   disentuh — hampir semuanya tak pernah masuk paket mana pun; ketiga gelombang
   6-8 Agustus menghabiskannya.

**Deviasi jadwal.** Taksiran usaha paket 1–12 berjumlah **37–51 hari**, plus
pekerjaan susulan bertaksiran 4–6 hari → **41–57 hari** taksiran total. Realisasi
kalender: **1–3 Agustus 2026, tiga hari**. Kedua angka itu bukan satuan yang sama —
taksiran ditulis sebagai hari-orang pengerjaan manual, realisasinya eksekusi
paralel multi-agen dengan verifikasi berlapis — tetapi deviasi jadwalnya nyata:
seluruh rencana prioritas selesai dalam kalender yang lebih pendek dari taksiran
paket TERBESARNYA sendirian (8–12 hari) — selisih satu orde besaran terhadap
totalnya.

**Deviasi cakupan (dua arah).** Ke bawah: paket 1–12 hanya pernah mengklaim ±35
dari 81 temuan; 46 nomor tidak diklaim paket mana pun, dan di sanalah 33 status
belum berada saat laporan disusun (gelombang 6-8 Agustus menghabiskannya). Ke
atas: eksekusi mengerjakan hal-hal yang TIDAK ada di daftar 81 —
buku besar (layar yang ternyata tidak pernah ada), impor dokumen berbaris,
kalender acara korporat, audit ulang dua modul terbesar (29+21 temuan baru, 94
invarian dikonfirmasi), dan penyiapan pelepasan gerbang demo. Suite tumbuh
**1.337 → 2.305** uji hijau (dihitung ulang 6 Agustus: 2.305 lulus, 10.655
asersi).

---

## Matriks 81 temuan

Legenda status: ✅ sesuai resep · ➕ melampaui resep · 🔀 selesai, mekanisme
berbeda · 🟡 sebagian · 🚫 sengaja ditolak · ⬜ belum dikerjakan. Kolom Deviasi
menjelaskan BAGAIMANA realisasi berbeda dari resep — "—" berarti tidak ada yang
perlu dicatat (untuk ⬜: temuannya utuh seperti saat ditulis).

| No | Temuan | Taksiran | Status | Deviasi |
|---|---|---|---|---|
| 1 | 🔴 Lookup picker | 3-4 hari | ✅ | Gelombang 6-8 Agu (2): filter lookup daftar kini combobox yang SAMA dengan form — komponen dipakai ulang, bukan di-fork; kedua paruh temuan tuntas |
| 2 | 🔴 Proteksi data belum tersimpan | 1 hari | ➕ | Dirty = diff snapshot + stack modal + gagal-ke-arah-menyimpan; beforeunload tidak dibuat |
| 3 | 🔴 Milestone → penagihan | 1-2 hari | ➕ | + endpoint antrean & layar #/siap-tagih penuh; notifikasi menaut ke detail kontrak |
| 4 | 🔴 Penerimaan termin neto pajak | 1-2 hari | ➕ | Tabel fin_payment_withholdings, bukan "tipe alokasi"; + PPh 23/1-1710, bukti potong wajib |
| 5 | 🔴 Three-way match | 1-2 hari | ✅ | Baris PO lewat tombol salin ber-prefill, bukan muat-otomatis saat PO dipilih |
| 6 | 🔴 Retensi AR — pencairan | 1 hari | ✅ | due_now = stat layar retensi + pengawas harian, bukan ubin dasbor |
| 7 | 🔴 Segregation of duties | 1-2 hari | ➕ | SETIAP pembayaran keluar wajib orang kedua; katup SoD terdokumentasi, default tegak |
| 8 | 🔴 Retensi subkon | 1-2 hari | ➕ | Pelepasan dipagari saldo 2-1500 yang terbukukan; rute butuh dua izin |
| 9 | 🔴 Kalender fiskal tahun baru | 2-4 jam | ➕ | Auto-create ditolak tertulis; diganti cron 3-bulan-ke-depan + tombol + notifikasi |
| 10 | 🔴 Periode fiskal | 1-2 hari | ➕ | Tanpa permission baru: close=fin.post, reopen=fin.approve — pemisahan lewat role |
| 11 | 🟠 Aksesibilitas modal | 1 hari | ➕ | + inert pada #root, stack multi-layer, fokus awal melompati field mati |
| 12 | 🟠 Navigasi keyboard | 1 hari | 🔀 | Roving tabindex + Enter/Space, bukan link hash; Ctrl+klik buka-tab tetap tidak bisa |
| 13 | 🟠 Alat berat — BBM & hour meter | 3-5 hari | 🚫 | Gelombang 6-8 Agu (3): TIDAK dibangun — log BBM/hour meter menunggu keputusan pemilik tentang alur lapangan; kategori kas kecil BbmTol menampung biayanya sementara. Satu-satunya temuan yang tersisa terbuka. |
| 14 | 🟠 CCO ↔ jadwal termin | 1-2 hari | ✅ | Gelombang 6-8 Agu (2): aksi "jadwalkan penagihan nilai tambah" pada CCO approved — termin baru senilai value_change, idempoten lewat re-read |
| 15 | 🟠 Denda keterlambatan | 2-3 hari | ✅ | Gelombang 6-8 Agu (2): baris potongan other_deduction BERALASAN pada alokasi penerimaan; identitas alokasi dijaga; reverse() memutarnya balik |
| 16 | 🟠 Galeri foto progres | 2-3 hari | ✅ | Gelombang 6-8 Agu (2): galeri foto per proyek dari core_attachments ber-mime image lintas dokumen (termasuk BOQ/RAP setelah verifier menagih kejujuran daftar sumbernya), badge geotag |
| 17 | 🟠 Riwayat harga satuan | 2-4 hari | ✅ | Gelombang 6-8 Agu: layar tren harga PO/GRN dibangun; separuh snapshot TERNYATA SUDAH ADA (harga beku di baris BOQ, dipatri uji tanpa kode baru) — matriks awal salah menilainya belum |
| 18 | 🟠 Alur rekonsiliasi bank | 1-2 hari | ✅ | Gelombang 6-8 Agu (2): match/batalkan mengganti BARIS itu saja — scroll tidak hilang lagi; + tombol muat ulang |
| 19 | 🟠 Filter & state daftar | 1 hari | ➕ | Server-announced meta.date_column (±36 resource); paruh "menyusul" (state ke URL) ikut selesai |
| 20 | 🟠 Input nilai rupiah | 1 hari | ✅ | Gelombang 6-8 Agu (2): hint terbilang ("1,5 M") di bawah input uang, senyap saat kosong/nol |
| 21 | 🟠 Validasi & pesan error | 1-2 hari | ✅ | Galat menempel di field/sel; klausa toast hanya sebagian (ambang 160 karakter) |
| 22 | 🟠 Cuti/izin & absensi harian | 5-8 hari | ➕ | Gelombang 6-8 Agu (3): register cuti CTI lengkap — saldo 12 hari UU 13/2003 dihitung dari join_date (11 bulan = 0, teruji di batas), approve mengisi rekap payroll FORWARD-ONLY (periode terposting dilewati dan disebutkan), + register absensi harian ber-bulk entry; keterkaitan absensi→gaji sengaja ditolak (register dulu, mesin gaji belum) |
| 23 | 🟠 PKWT tanpa tanggal akhir | 1-2 hari | 🔀 | Nullable sadar + alarm TANPA_TANGGAL; H-60 (bukan H-30/14); + pkwt_basis & plafon 5 tahun |
| 24 | 🟠 SKK/sertifikat tenaga ahli | 2-3 hari | ✅ | Layar mandiri "Sertifikat & PKWT", bukan tab di detail karyawan |
| 25 | 🟠 Kalender kewajiban pajak | 1-2 hari | ✅ | Gelombang 6-8 Agu (3): register masa pajak ber-NTPN memakai tenggat statutori yang SUDAH ada di CashFlowService — tidak dideklarasikan dua kali |
| 26 | 🟠 Kas kecil / kasbon | 3-5 hari | ➕ | Imprest lengkap + kasbon + DanglingDocuments; tanpa approval per-kasbon |
| 27 | 🟠 Register jaminan bank | 2-3 hari | ✅ | Hidup di crm/guarantees, bukan fin/ — identitas penerbit+nomor |
| 28 | 🟠 Void / nota kredit | 1-2 hari | ✅ | + jurnal balik pindah ke periode berjalan bila periode asal ditutup/diukur |
| 29 | 🟠 Masa pemeliharaan & BAST II | 2-3 hari | ➕ | Workflow dua-tanggal + severity + waive beralasan + override tertulis |
| 30 | 🟠 Orkestrasi tutup buku | 2-3 hari | ➕ | Lima butir jadi peringatan ber-override beralasan; + fin:close-watch harian |
| 31 | 🟠 Pembayaran non-AP | 1-2 hari | ➕ | Biaya bank justru DITOLAK langsung (akru 2-1600); + maker-checker penuh |
| 32 | 🟠 Penagihan termin — validasi | 1 hari | ✅ | Gelombang 6-8 Agu (2): menagih termin ber-milestone belum tercapai kini 422 sampai membawa confirm_unachieved_milestone; konfirmasinya tercatat di deskripsi invoice |
| 33 | 🟠 Pengadaan — kendali anggaran | 1-2 hari | ✅ | Gelombang 6-8 Agu (3): CommitmentService akhirnya digerbangkan di submit PO & SPK — warn/block/off via config, pesannya menyebut angka anggaran/komitmen/dokumen/lebihnya |
| 34 | 🟠 Pengadaan — kendali harga | 3-5 hari | ➕ | Gelombang 6-8 Agu (3): ketiga tahap — boq_item_id ke baris PO (diwariskan saat Ubah tanpa kuncinya — pelucutan yang ditangkap verifier), peringatan simpangan harga ber-konfirmasi, dan dokumen RFQ yang matriks penawarannya selamat dari Ubah |
| 35 | 🟠 Prakualifikasi vendor | 2-3 hari | ➕ | Gelombang 6-8 Agu (2): register dokumen ber-expiry + gate submit PO DAN SPK + override beralasan yang tersimpan hanya bila benar-benar meng-override dan hanya setelah submit sukses (dua lubang verifier ditambal) |
| 36 | 🟠 Pengendalian material site | 3-5 hari | 🔀 | Layar varian ada; wbs_task_id TIDAK diwajibkan — diganti ember "belum ditandai" yang jujur |
| 37 | 🟠 Retur dari proyek | 1-2 hari | ✅ | Gelombang 6-8 Agu (2): dokumen Retur Material parsial mereferensi bon asal, di harga saat keluar; plafon kumulatif dijaga di validasi DAN loop posting (bypass baris-ganda verifier ditambal) |
| 38 | 🟠 Retur pembelian | 2-3 hari | ➕ | Gelombang 6-8 Agu (2): Retur Pembelian ber-unregisterReceipt() — decrement qty_received pertama yang pernah ada + buka lagi PO yang tertutup otomatis; pembalikan clearing mengikuti apa yang DITERIMA catat, bukan parameter hari ini |
| 39 | 🟠 P2P — otorisasi dua tingkat | 2-3 hari | 🔀 | Satu approval wajib direktur + maker-checker, bukan dua approval berurutan |
| 40 | 🟠 Tagihan parsial per kiriman | 3-5 hari | ➕ | Gelombang 6-8 Agu (3): tagihan parsial per (PO, himpunan GRN) — pivot ber-cleared_amount per GRN, uang muka dipulihkan proporsional, anti-tagih-dobel di constraint UNIQUE; pembaca kliring sisi Inventory ikut diajari per-GRN (retur menilai kliring GRN-nya sendiri) |
| 41 | 🟠 Uang muka vendor (UI) | 0,5-1 hari | ✅ | Toggle tidak dinonaktifkan dinamis — server yang menolak |
| 42 | 🟠 EVM (CPI/SPI) | 2-3 hari | ➕ | Mesin EVM baseline-beku + TCPI/EAC/VAC + gerbang cpi_reliable, bukan hitungan di dashboard |
| 43 | 🟠 Baseline kurva-S | 2-3 hari | ➕ | Maker-checker beku; tautan CCO teks bebas, bukan FK |
| 44 | 🟠 Register jaminan & asuransi | 2-3 hari | ✅ | — |
| 45 | 🟠 Reminder engine | 3-5 hari | ➕ | Registry deklaratif — 18 pengawas per 8 Agu (resep: mulai 4) + BLIND/TANPA_TANGGAL + dua layar SPA |
| 46 | 🟠 Suku cadang berita acara | 1-2 hari | ➕ | + uji kering saat submit, UNIQUE anti-dobel, tanda tangan+bon atomik |
| 47 | 🟠 Siklus hidup proyek | 1-2 hari | ➕ | Gelombang 6-8 Agu: aksi "Tutup proyek" ber-checklist item terbuka + snapshot + override beralasan; dropdown tak lagi mencapai closed; guard isOperational diperluas (generate-wbs, hapus laporan harian) |
| 48 | 🟠 Addendum SPK | 2-3 hari | ✅ | Gelombang 6-8 Agu (2): addendum SPK (ADS) meniru pola CCO, dengan change_type sejak hari pertama; plafon klaim membaca nilai + addenda approved |
| 49 | 🟠 Uang muka subkon | 2-3 hari | ✅ | Gelombang 6-8 Agu (2): klaim uang muka + advance_recovery pada klaim berikutnya mengurangi net_payable |
| 50 | 🟠 Ekspor CSV | 1-2 hari | ➕ | Ekspor SELURUH daftar terfilter (page-walk API), bukan halaman yang terrender |
| 51 | 🟠 Sorting | 1-2 hari | ➕ | Cabang "ideal" resep + 422 whitelist + pemulihan klien + aria-sort |
| 52 | 🟠 Termin berbasis waktu | 1 hari | 🔀 | due_date manual per termin + reschedule; auto-isi kuartalan tidak ada |
| 53 | 🟠 UX penagihan termin | 0,5-1 hari | ✅ | Field ID mentah masih ada sebagai jalur cadangan |
| 54 | 🟠 Visibilitas arus kas | 2-4 hari | ➕ | + laporan PSAK 2 metode langsung penuh + endpoint saldo bank |
| 55 | 🟡 Aset — disposal tanpa jurnal | 1 hari | ➕ | Gelombang 6-8 Agu: aksi Hapus Buku/Jual + jurnal pelepasan; pintu update ditutup (re-read terkunci); urutan vs run penyusutan draf dijaga dua sisi |
| 56 | 🟡 Entitas tunggal / intercompany | 1 jam dok | ✅ | Gelombang 6-8 Agu (2): catatan batasan entitas-tunggal + penanganan KSO porsi proporsional ditulis di ARCHITECTURE.md |
| 57 | 🟡 Konsistensi ID-EN | 0,5-1 hari | ✅ | Gelombang 6-8 Agu (3): sapuan literal EN + kamus LABELS + titleize() memperingatkan kunci tak dikenal; jargon baku (WIP Schedule, win-rate, baseline) sengaja dipertahankan |
| 58 | 🟡 Lead ↔ nasib penawaran | 0,5 hari | ✅ | Gelombang 6-8 Agu: markWon/markLost menular ke lead; Jadikan Pelanggan idempoten lewat re-read terkunci; kolom follow-up |
| 59 | 🟡 Print stylesheet | 2 jam | ✅ | Gelombang 6-8 Agu: token terang dipaksa di @media print utk ketiga tema; toasts/overlay/tabs disembunyikan |
| 60 | 🟡 Customer portal | 5-10 hari | 🚫 | Ditolak tertulis: permukaan keamanan baru belum sepadan |
| 61 | 🟡 Eskalasi harga multi-tahun | 0,5-3 hari | ✅ | Gelombang 6-8 Agu: alternatif murah resep — change_type pada CCO (tambah_kurang / eskalasi_harga), tanpa mesin formula |
| 62 | 🟡 Skeleton layar custom | 0,5 hari | ✅ | Gelombang 6-8 Agu: skeleton dipasang di loadOrFail — kedelapan layar custom mendapatkannya otomatis |
| 63 | 🟡 Lookup 403 tanpa penjelasan | 0,5 hari | ➕ | Tiga status per-sumber + "Coba lagi" + invalidate + live-region |
| 64 | 🟡 Hak akses posting penyusutan | 1 jam | ✅ | Gelombang 6-8 Agu: seeder + migrasi idempoten utk tenant hidup; diverifikasi di erp1 (finance kini ast.view+ast.post) |
| 65 | 🟡 Mata uang tunggal IDR | 1 jam dok | 🚫 | Ditolak tertulis; bahkan kolom catatan kurs tidak ditambah |
| 66 | 🟡 Responsif 390px (hideOnNarrow) | 1 hari | ✅ | Gelombang 6-8 Agu: mekanisme di list.js/detail.js + flag kolom sekunder (14 per 8 Agu); kolom nested-table sengaja tidak diberi flag (colspan) |
| 67 | 🟡 Chevron navigasi | 5 menit | ✅ | Gelombang 6-8 Agu: class chev dipasang — CSS mati itu akhirnya menemukan targetnya |
| 68 | 🟡 Evaluasi vendor | 2-3 hari | ✅ | Gelombang 6-8 Agu (2): skor kirim otomatis dari GRN vs tanggal janji, rating di label picker, ajakan evaluasi saat menutup PO besar |
| 69 | 🟡 Legalitas vendor ber-expiry | 1-2 hari | ✅ | Gelombang 6-8 Agu (2): SATU register dengan #35 — SIUP/NIB/NPWP/SBU sebagai jenis dokumen ber-masa-berlaku, bukan register kedua |
| 70 | 🟡 Pemantauan pengiriman PO | 1 hari | ✅ | Gelombang 6-8 Agu: laporan Baris PO Terbuka lintas-PO (overdue-first) + layar #/po-outstanding |
| 71 | 🟡 Peminjaman alat kecil | 3-4 hari | 🚫 | Dipagari lewat baris Bagian B, tetapi daftar penolakan lupa mengenumerasinya |
| 72 | 🟡 Valuasi GRN | 0,5-1 hari | ✅ | Gelombang 6-8 Agu: harga 0 pada baris tertaut PO ditolak 422 sampai membawa confirm_zero_cost; alur konfirmasi-lanjut generik di form |
| 73 | 🟡 Retensi dua pola tanpa pagar | 0,5 hari | ➕ | Gelombang 6-8 Agu: flag is_retention + pagar di TIGA jalur (createFromTermin, createManual, update draf — dua terakhir ditambal setelah verifier menembus pagar pertama) + prefill SPA berhenti menyalakan retensi |
| 74 | 🟡 Sinkron nilai kontrak → proyek | 0,5 hari | ✅ | Gelombang 6-8 Agu: contract_value proyek diperbarui dalam transaksi approve CCO |
| 75 | 🟡 Gate retensi subkon | 1 hari | ✅ | Gelombang 6-8 Agu (2): gate waktu defect_liability_until pada release + pintu sempit mencatat tanggalnya pada SPK yang SUDAH approved (tanpa itu, portofolio hidup selamanya butuh override — tangkapan verifier) |
| 76 | 🟡 Header sticky & kepadatan | 0,5 hari | ✅ | Gelombang 6-8 Agu (3): pemilih baris-per-halaman di pager + kontrak per_page server (klamp non-positif); kedua paruh tuntas |
| 77 | 🟡 Total kolom uang (tfoot) | 0,5 hari | ✅ | Gelombang 6-8 Agu: tfoot "Total halaman ini" utk kolom uang, jujur menyebut cakupan halaman |
| 78 | 🟡 Analitik win-rate | 1-2 hari | ✅ | Gelombang 6-8 Agu (2): crm/reports/pipeline — win-rate per kuartal, nilai menang vs kalah, alasan kalah; quotation rejected tidak lagi menyaru "masih berjalan" |
| 79 | 🟡 Agregat dasbor terpotong 100 | 0,5-1 hari | ✅ | Gelombang 6-8 Agu (2): core/dashboard/summary menjumlah di SQL — undercount senyap >100 baris mati |
| 80 | 🟡 Dasbor per peran | 3-5 hari | ✅ | Gelombang 6-8 Agu (2): toggle "Proyek saya" di dasbor + daftar proyek via users.employee_id → project_manager_id |
| 81 | 🟡 WIP schedule | 2-4 jam | ✅ | Gelombang 6-8 Agu: tombol cetak + WIP Schedule (CSV) + judul cetak di layar run PSAK 115 |

### Catatan gelombang perbaikan 6-8 Agustus

Saat laporan ini pertama disusun (6 Agustus) tabelnya membaca 9/21/5/10/3/33.
Gelombang perbaikan pertama — empat jalur paralel berkas-disjoint, masing-masing
ditutup pemeriksa independen yang MEREPRODUKSI — menutup 16 temuan (14 kecil
plus #17 dan #47 yang besar) dan memajukan #76: #17, #47, #55, #58, #59, #61,
#62, #64, #66, #67, #70, #72, #73, #74, #77, #81. Enam belas baris matriks di
atas membawa tanggalnya di sel Deviasi (#76 kemudian dituntaskan gelombang
ketiga).

Pola sesi ini bertahan: pemeriksa menembus pekerjaan barunya sendiri LIMA kali —
pagar retensi #73 bisa dilewati lewat edit draf dan invoice manual (ditambal:
satu pagar, tiga jalur); konversi lead menggandakan pelanggan lewat instance
basi (re-read terkunci); run penyusutan draf yang diposting setelah pelepasan
membebani aset yang sudah keluar dari neraca (dijaga dua sisi); penjaga disposed
di controller aset memutuskan dari instance basi (pindah ke service ber-re-read);
dan generate-wbs menjawab 200 pada proyek TUTUP, me-reset progres 100 → 0 (satu
baris assertOperational). Setiap tambalan dibuktikan gagal dulu tanpa
perbaikannya. Satu koreksi pada laporan ini sendiri: matriks awal menilai
separuh snapshot #17 belum ada — ternyata sudah, dan kini terpatri uji.

Suite setelah gelombang: **2.391 hijau** (dari 2.305 saat laporan disusun).
Terdeploy ke erp1; migrasi termasuk pemberian ast.view+ast.post ke role finance
pada tenant hidup, diverifikasi langsung.

**Gelombang kedua (6-8 Agustus)** menutup 19 temuan lagi — enam jalur paralel:
register vendor ber-expiry + gate prakualifikasi PO/SPK (#35/#68/#69), dua
dokumen retur (#37/#38, termasuk `unregisterReceipt()` — decrement qty_received
pertama dalam sejarah modul), trio subkon (#48/#49/#75), wizard CCO→termin +
potongan denda beralasan + konfirmasi milestone + win-rate (#14/#15/#32/#78),
galeri foto + rekon bank in-place + penjumlahan dasbor di SQL + Proyek saya
(#16/#18/#79/#80), dan sisa parsial (#1/#20/#56). Verifikasinya menembus
pekerjaan baru TUJUH kali — dua di antaranya money-wrong murni (bypass
baris-ganda yang membuat HPP negatif; jejak override palsu dari submit yang
ditolak) — dan setiap tambalan dibuktikan gagal dulu. Suite: **2.554 hijau**.
Sebelas migrasi live berjalan mulus; seluruhnya terdeploy dan md5-identik.

**Gelombang ketiga (6-8 Agustus)** menutup delapan yang terakhir — dan yang
terbesar: cuti + saldo UU 13/2003 + absensi (#22), RFQ tiga tahap + gerbang
harga (#34), gate anggaran yang akhirnya menyala (#33), tagihan parsial per
GRN dengan pemulihan uang muka proporsional (#40), kalender pajak (#25), dan
dua paruh terakhir (#57, #76). #13 dipagari sadar sebagai penolakan keempat.
Verifier menembus pekerjaan baru lagi: Ubah RFQ menghanguskan matriks
penawaran yang sudah diketik (validated() membuang id baris), Ubah PO melucuti
gerbang harga (boq_item_id tak diwariskan), dan pembaca kliring Inventory
masih menilai retur dari pool PO alih-alih GRN-nya sendiri — semuanya ditambal
dengan uji yang gagal dulu. Suite akhir: **2.642 hijau** (dari 1.337 saat
asesmen ditulis — hampir dua kali lipat dalam delapan hari). Sepuluh migrasi
live lagi; terdeploy.

---

## Deviasi material — bagaimana realisasi berbeda dari resep

### a. Selesai lewat mekanisme yang berbeda, dan mengapa

- **Ekspor CSV berjalan di klien** (#50), bukan "dari data yang sudah dirender"
  seperti resep — page-walk seluruh daftar terfilter. Alasannya arsitektural: arti
  sebuah sel hanya ada di klien (kolom `rel`, label enum), jadi CSV sisi server
  akan mengekspor id mentah.
- **Filter tanggal & sorting diumumkan server** (#19, #51): kemampuan datang dari
  `meta.date_column`/`meta.sortable` yang dihitung controller, bukan deklarasi
  kedua di schema.js — daftar yang belum mengadopsi tetap hidup, hanya tanpa
  tombolnya. Dua sumber kebenaran ditolak by design.
- **PKWT tanpa tanggal** (#23): resep meminta kolom wajib; realisasinya kolom
  *nullable yang disengaja* — PKWT dasar selesainya-pekerjaan (PP 35/2021 Psl 9)
  sah tanpa tanggal — dengan tanggal-yang-hilang menjadi alarm berulangnya
  sendiri, bukan galat validasi.
- **Varian material** (#36): resep mewajibkan `wbs_task_id` pada issue; realisasi
  menolak mewajibkannya dan memajang ember "bon belum ditandai" sebagai peringatan
  utama laporan — kejujuran tentang data lama dipilih di atas pemaksaan pada data
  baru.
- **Otorisasi dua tingkat** (#39): bukan dua approval berurutan; satu approval
  yang WAJIB dipegang direktur, ditambah maker-checker pengaju≠penyetuju di
  seluruh dokumen.

### b. Resep yang DIBALIK oleh pelaksanaan

Empat kali pemeriksa skeptis membatalkan arah resep sebelum resep itu dipakai:

- **Arah toleransi neraca saldo**: dilaporkan sebagai cacat `ReportService`;
  skeptis membuktikan laporannya yang benar dan penjaga postingnya yang longgar —
  toleransi laporan kini DITURUNKAN dari penjaga posting, bukan dilonggarkan.
- **e-Faktur DPP**: diturunkan dari cacat uang menjadi cacat berkas — rupiah
  PPN-nya tidak pernah salah.
- **Diagnosis tagihan-tanpa-GRN**: beban penuh DPP di jalur klasik ternyata
  disengaja dan berujian; cacat sebenarnya adalah tidak ada yang mencegah barang
  yang sama datang SESUDAHNYA — dan itulah yang dipagari.
- **Kunci akrual T43** yang diusulkan pagar (`deployment_id*100+bulan`) terbukti
  menabrak dirinya lintas tahun; dikoreksi di dokumen sebelum sempat dipakai
  siapa pun.

### c. Lebih ketat dari resep

- Periode yang sudah diukur run PSAK 115 terposting **tidak pernah bisa dibuka
  lagi** — resep hanya meminta tutup/buka ber-permission (#10).
- `generateYear` untuk tahun lampau membuat periode **TERTUTUP**, dan auto-create
  periode saat posting ditolak dengan alasan tertulis (#9).
- SETIAP pembayaran keluar butuh orang kedua, bukan hanya di atas ambang (#7);
  biaya bank justru ditolak dialokasikan langsung dan wajib lewat akrual (#31).
- Suku cadang berita acara (#46): selain resepnya, submit menjalankan seluruh
  syarat pengeluaran barang sebagai uji kering di transaksi yang selalu
  di-rollback.

### d. Sebagian & dipagari

Sepuluh temuan lampiran sempat berstatus sebagian saat laporan disusun;
ketiga gelombang menutup semuanya. Terpisah dari itu,
audit ulang 3 Agustus meninggalkan **lima pagar sadar** — dicatat di sini karena
penomorannya bertabrakan: **T13/T26/T28/T37/T43 adalah nomor temuan audit-ulang,
BUKAN nomor lampiran** (lampiran #13 = BBM alat berat, #26 = kas kecil, #37 =
retur proyek, #43 = baseline — temuan yang sama sekali lain).

| Pagar audit | Isi | Status per 8 Agustus |
|---|---|---|
| T43 | Biaya alat internal satu gelondong saat demobilisasi | **DITUTUP** — akrual bulanan ber-kunci (mobilisasi × tahun × bulan) + residual eksak saat demobilisasi + butir checklist tutup buku `plant_accrued`; Rp 585 jt bulan-bulan lampau menunggu SATU aksi operator: `ast:accrue-plant` per bulan terbuka |
| T26 | Re-costing retrospektif tidak ada; dokumen mundur ditolak | Tetap dipagari — keputusan rancangan; sistem menjawab "tidak bisa", bukan menjawab salah |
| T28/T29 | Nilai barang dalam perjalanan absen dari Saldo Stok | **DITUTUP** — satu query bersama layar & CLI, ubin "Dalam perjalanan" + "Total dimiliki", dan total dihitung SQL (undercount >200 baris ikut mati) |
| T37 | Hanya bon yang bisa dibatalkan | **Sebagian besar DITUTUP** — pembatalan penerimaan kini ada (cermin + jurnal balik + kliring nol + qty PO kembali + PO auto-close terbuka lagi); transfer & opname tetap tanpa jalan pulang, dengan rasional tertulis (transfer kedua / opname kedua sudah mencapai keadaan akhir yang sama) |
| T13 | Hanya admin bisa menuntaskan kunjungan bersuku-cadang | Tetap terbuka — keputusan operabilitas menunggu pemilik |

Pagar keenam (T40, kemacetan tutup buku permanen) sudah ditutup 5 Agustus,
bersama balapan status yang diciptakan perbaikannya sendiri. **Penutupan T43,
T28/T29, dan T37 menyusul 8 Agustus** — dengan itu satu-satunya baris yang
pernah menjawab "Ya, angka salah hari ini" ikut padam: yang tersisa untuk
angka-angka lampau T43 hanyalah aksi catch-up operator ke bulan-bulan yang
masih terbuka, dijaga butir checklist WARN yang menyebut mesin, hari, dan
rupiahnya setiap kali sebuah bulan mau ditutup.

### e. Sengaja tidak dibangun

Tiga temuan punya penolakan tertulis di dokumen induk (#56 multi-entitas,
#60 portal pelanggan, #65 multi-valuta). #56 sempat dinilai 🟡 karena resep
penolakannya sendiri — MENULIS dokumentasi batasannya — belum dikerjakan;
gelombang kedua menulisnya (catatan entitas-tunggal + KSO porsi proporsional di
ARCHITECTURE.md), maka matriks kini menilainya ✅. Penolakan keempat menyusul di
gelombang ketiga: **#13 BBM & hour meter**, dipagari menunggu keputusan pemilik
tentang alur pencatatan lapangan — sementara itu kategori kas kecil BbmTol
menampung biayanya tanpa tautan aset. Dua penolakan lain menaungi temuan
yang bukan dirinya: "aplikasi mobile native" ditolak padahal temuan #66 meminta
`hideOnNarrow` (kini dibuat pada gelombang pertama), dan #71 peminjaman alat
dirutekan ke daftar penolakan oleh tabel Bagian B yang semula tidak pernah
mengenumerasinya — pagar menggantung yang kini ditambatkan: butir peminjaman
alat sudah ditambahkan ke daftar penolakan dokumen induk. Ditambah penolakan
tingkat-mekanisme yang tercatat di
kode: kunci urut kolom turunan (menunggu seam `column.sortKey`), jurnal
retroaktif (FORWARD-ONLY), dan auto-create periode fiskal.

---

## Pekerjaan di luar 81 temuan

Enam deliverable lahir 1–9 Agustus tanpa pernah ada di daftar:

- **Buku besar** — tidak ada di 81 temuan "karena tidak seorang pun mengira
  layarnya belum ada". Service + endpoint + layar `#/buku-besar`, saldo akhir
  terpatri identik dengan neraca saldo per akun (20 uji).
- **Impor excel/csv dokumen berbaris** — penawaran, BOQ, AHSP, RAP lewat satu
  mesin generik; 146 uji saat catatan 3 Agustus ditulis, **150 hari ini** —
  verifikasi dokumennya sendiri menemukan dua celah nyata (pelewat `#`
  posisional, .csv tanpa plafon fisik) yang langsung ditutup, dan empat uji
  lahir bersama penutupannya.
- **Kalender acara korporat** — komposisi registry pengawas tenggat + sumber
  khusus kalender, satu sumber kebenaran dengan dasbor.
- **Audit ulang keuangan/pajak + persediaan** — 29 + 21 temuan baru di luar
  ke-81, 94 invarian dikonfirmasi bertahan, lima pagar sadar.
- **`erp:harden-demo-logins`** — penyiapan pelepasan gerbang demo erp1 (rotasi
  password tersemai, pencabutan token, noindex) — operasional, bukan temuan
  asesmen; rotasinya sendiri menunggu keystroke pemilik.
- **Formulir rumah — 39 dokumen (8–9 Agustus).** Permintaan pemilik, bukan
  temuan: ERP mencetak formulir konstruksi milik perusahaan sendiri, bukan PDF
  generik. 7 formulir Proyek bawaan (`FormPrintService::FORMS`) ditambah **32
  entri deklaratif** di `Modules/Core/Support/PrintableDocuments`, semuanya
  lewat SATU Blade generik. Menambah formulir = **satu entri larik**;
  `GET api/core/print/forms` menjawab katalog yang sudah disaring hak akses dan
  tombolnya menggambar dirinya sendiri — tanpa sunting `schema.js`, tanpa Blade
  baru. Empat berkas dompdf lama (faktur, PO, BAST, slip gaji) TETAP; lembar
  rumah berdiri di sebelahnya, bukan menggantikannya.

  Suite **2.642 → 2.938** (13.339 asersi) selama pekerjaan formulir ini —
  angka 2.642 di §"Catatan gelombang perbaikan" adalah penutup kampanye 8
  Agustus dan benar untuk tanggalnya, bukan angka terakhir dokumen ini.

  Tiga hal yang layak dicatat sebagai deviasi proses, bukan sebagai fitur:

  - **Backend benar tidak berarti tombolnya ada.** Verifikasi menemukan
    `printcatalog.js` tidak diimpor satu berkas pun: 32 endpoint bekerja
    sempurna dan NOL tombol tampil. Efek sampingnya menyingkap cacat lama —
    tombol "Detail Schedule" pada `projects/weekly-progress` tidak pernah dapat
    dijangkau sejak dikirim, karena sumber dayanya `noDetail: true` sementara
    `printForms` hanya dibaca layar detail. Periksa layarnya, bukan
    endpoint-nya.
  - **Aturan kejujuran memutuskan 18 perbaikan.** Sel dicetak DARI BASIS DATA
    atau dicetak sebagai GARIS KOSONG — tidak pernah bawaan yang masuk akal,
    tidak pernah 0 yang berarti "tidak diketahui", dan tidak pernah label yang
    menyatakan hal yang tidak dimaksud angkanya. Yang terburuk: bukti
    penerimaan menyatakan DI ATAS GARIS TANDA TANGAN bahwa pelanggan menerima
    uang yang justru baru saja mereka setorkan (nama benar, label terbalik).
    Berikutnya: vendor/barang/proyek yang sudah dihapus lunak meninggalkan
    baris berharga tanpa nama; kartu aset mencetak penyusutan bulanan untuk
    aset yang tidak disusutkan siapa pun; lembar uang muka mengutip tarif
    retensi 5% di sebelah 0,00.
  - **Tiga putaran verifikasi menemukan 86 masalah, dan mayoritasnya dibuat
    oleh gelombang yang sedang memperbaiki temuan sebelumnya.** Dua pelajaran
    yang layak dibawa: (a) `loadMissing` adalah no-op pada relasi yang sudah
    dimuat pemanggil sebelumnya — penjaga `withTrashed` di
    `FormPrintService::header()` tertulis rapi dan TIDAK PERNAH JALAN sampai
    `::project()` ikut dibatasi; membaca kode dan mempercayainya tidak cukup,
    yang menangkapnya adalah asersi keluaran identik byte-per-byte. (b) Dua
    belas uji tidak bisa gagal — semuanya berbentuk sama, menuntut `fill-line`
    muncul DI SUATU TEMPAT pada lembar yang memang punya sel bergaris lain.
    Sebelum memercayai uji aturan kejujuran: balikkan perbaikannya di salinan
    scratch, jalankan, dan pastikan merah.

---

## Deviasi angka — dokumen induk vs kenyataan

Diperiksa ulang 6 Agustus; yang tercantum di sini adalah selisih yang MASIH ada
di dokumen induk:

- **"10 kritis, 45 besar, 26 kecil" vs lampiran 10/44/27.** Judul-judul lampiran
  menghitung 44 🟠 dan 27 🟡. Kandidat penyebabnya: #78 (analitik win-rate) dan
  #80 (dasbor per peran) — keduanya ber-judul 🟡 di lampiran tetapi diperlakukan
  berbobot besar di tabel Bagian B dokumen induk; menggeser tepat satu dari
  keduanya mereproduksi 45/26. Temuan #69 bahkan tidak muncul di badan dokumen
  induk sama sekali.
- **"Combobox di 115 field" vs 116 hari ini** — bertambah satu karena field
  "Gudang suku cadang" yang lahir bersama penutupan T40 (5 Agustus); hitungan
  dokumen benar pada tanggalnya, tertinggal sesudahnya.
- **"Suite: 2.297" vs 2.305 hari ini** — delapan uji `HardenDemoLoginsTest`
  menyusul setelah angka itu ditulis.
- **"146 uji impor (78 mesin generik)" vs 150 (82) hari ini** — empat uji lahir
  bersama penutupan dua celah impor pada pas verifikasi 5 Agustus; angka dokumen
  induk benar pada tanggal catatannya, sebagai catatan bersejarah ia dibiarkan.
- **Nomor yang menjebak**: "kritis #2" di tabel paket adalah penomoran ringkasan
  eksekutif (= temuan lampiran **#4**), bukan nomor lampiran.

---

## Deviasi proses — bagaimana pelaksanaan menjaga dirinya

Yang membuat angka-angka di atas bisa dipercaya juga layak dicatat sebagai
deviasi dari praktik biasa:

- **Lima kali gelombang perbaikan melanggar aturan yang baru ditetapkannya
  sendiri** — pembatalan bon yang membalik nilai yang salah, cermin yang
  dibebaskan dari penjaga kronologinya sendiri, gerbang periode yang mendamparkan
  transfer, jurnal kedua yang tak ikut dibalik, dan balapan status pada penutupan
  T40. Semuanya tertangkap oleh pemeriksa yang MEREPRODUKSI, bukan membaca.
- **Dua pas verifikasi atas dokumen induk mengoreksi 34 lalu 38 dari 182 klaim**
  sebelum dokumen itu dipercaya — nomor baris yang bergeser, hitungan yang salah,
  kalimat yang lebih berani daripada kodenya.
- **Satu temuan verifikasi terbukti salah** (pembacaan koefisien `1.050,00`) dan
  dicatat sebagai kesalahan pemeriksanya, bukan dihapus diam-diam.
- **Uji regresi dibuktikan gagal dulu** dengan perbaikannya dilepas, baru
  dipercaya — dua di antaranya menangkap balapan yang bisa membatalkan pengesahan
  terposting.

---

*Setiap status di matriks berbukti `berkas:baris` yang dibuka 6 Agustus 2026 oleh
pemeriksa yang tidak menulis kodenya; bukti lengkap tersimpan di jejak verifikasi
sesi. Dokumen ini sendiri melewati pas penyerangan balik sebelum di-deploy —
sebagaimana dokumen induknya.*
