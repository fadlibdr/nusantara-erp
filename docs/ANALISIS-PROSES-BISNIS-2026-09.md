# Analisis Proses Bisnis — Nusantara ERP

**4 September 2026** · dasar: kode `main` (48cee5b), pengukuran di produksi
`erp1.pi2.co.id` (4 Sep, berurutan, ≤ 270 ms/permintaan), dan sandbox terukur
(lihat [HASIL-UJI-UX-2026-09.md](HASIL-UJI-UX-2026-09.md)). Perubahan yang
diusulkan **dan sudah dibangun** ada di `ux-p0-and-process.patch` (2 commit,
22 berkas, 2.975 tes hijau di modul yang disentuh).

> Cara membaca: Bagian 1 adalah *apa yang sebenarnya dilakukan sistem* —
> bukan apa yang dijanjikan README. Bagian 2 adalah *apa yang terjadi pada
> data produksi*. Bagian 3 adalah celah kendali dan celah aliran. Bagian 4
> adalah yang dibangun hari ini, dengan tes. Bagian 5 adalah yang sengaja
> tidak dibangun, dan mengapa.

---

## 0. Ringkasan untuk direktur

Mesin prosesnya **ada dan berat**: 28 jenis dokumen lewat ajukan → setujui,
maker-checker yang dijaga server, gerbang anggaran (#33), kendali harga (#34),
prakualifikasi vendor, persetujuan berjenjang untuk keputusan pemenang,
retensi dua arah, 19 tanggal yang diawasi tiap pagi (`erp:deadline-watch`),
jam tutup buku (`fin:close-watch`), PM otomatis. Ini bukan ERP yang kurang
aturan.

Yang kurang adalah **gerak**: aturan menahan dokumen di gerbang, tetapi tidak
ada yang mendorongnya lewat. Buktinya di produksi:

| Bukti | Angka |
|---|---|
| Pembayaran keluar `PAY/2026/VIII/0002` menunggu persetujuan | **33 hari**, Rp 10 jt, tanpa pengingat kedua |
| Tagihan vendor `BIL/2026/VII/0002` disetujui, jatuh tempo 27 Jun | **69 hari lewat jatuh tempo**, belum dibayar penuh |
| Penawaran `QTN/2026/VII/0004` menang 22 Agu → kontrak `CTR/2026/VIII/0005` | masih **draf 13 hari** kemudian |
| `PO/2026/III/0002` disetujui 26 Jul, Rp 128 jt | **0 penerimaan** dalam 40 hari |
| Tiket layanan | **4 dari 6** ditugaskan tanpa penyelesaian, dasbor: "4 melewati SLA" |
| Dokumen menunggu persetujuan yang **tidak** tampil di kartu dasbor | 1 dari 4 (25%), dan itulah yang paling lama |

Setiap baris di atas adalah dokumen yang **sudah lolos gerbang sebelumnya**
dan berhenti karena langkah berikutnya tidak punya pemilik, tenggat, atau
pengingat. Perbaikan yang dibangun hari ini menangani yang termahal dari
kelas itu — antrean persetujuan yang menua — dengan pengingat otomatis dan
eskalasi ke direktur. Sisanya di Bagian 5 adalah keputusan bisnis, bukan
keputusan kode.

---

## 1. Proses sebagaimana diimplementasikan

### 1.1 Penawaran → kas (quote-to-cash)

```
Prospek ──convert──▶ Pelanggan
Paket tender / RKK / TKDN ─▶ Penawaran ──(ajukan→setujui)──▶ menang (won_at)
        │                                                        │
        │                              (tidak ada from-quotation) ▼   ← CELAH A1
        └────────────────────────────▶ Kontrak (dibuat manual) ──(ajukan→setujui)──▶ Aktif
                                          │  termin ─▶ opname owner ─▶ "termin siap ditagih"
                                          ▼
                                    Invoice termin ──(ajukan→setujui)──▶ terbuka ─▶ RCV (kas masuk)
                                          │  retensi AR ─▶ release (endpoint ada)
                                          ▼
                              Piutang jatuh tempo: diawasi (deadline 'Invoice pelanggan')
                              Penagihan (dunning): ─────────────────────────── ← CELAH A2
```

Bukti kode: `Modules/Crm/Routes/api.php` punya `leads/{lead}/convert-to-customer`
tetapi **tidak** punya `quotations/{q}/create-contract` — kontrak diisi ulang
dari nol (pelanggan, nilai, termin). Di produksi ini terlihat sebagai
`QTN/2026/VIII/0008` Rp 2,04 M → `CTR/2026/VIII/0004` Rp 1,84 M: dua angka
untuk satu kesepakatan, tanpa jejak mengapa berbeda.

### 1.2 Permintaan → pembayaran (procure-to-pay)

```
PR ──(ajukan→setujui)──▶ create-po ──▶ PO ──(gerbang: prakualifikasi vendor,
                     RFQ ─▶ banding ─▶ award (berjenjang) ─▶ create-po      harga vs BOQ #34,
                                                                             anggaran RAP #33)
PO disetujui ─▶ GRN (posting: stok + HPP) ─▶ Tagihan vendor (dari GRN, parsial #40)
                                                   │ ──(ajukan→setujui)──▶ PAY ──(ajukan→setujui)──▶ posting
PO tanpa penerimaan: diawasi ('Pesanan pembelian lewat tanggal terima')
Tagihan vendor lewat jatuh tempo: ────────────────────────────────────────── ← CELAH B1
PAY menunggu persetujuan: ─────────────────────────────────── ← CELAH B2 (ditutup hari ini)
```

Pencocokan tiga arah **ada** (`ApBillService::createFromGoodsReceipt`,
`fin_ap_bill_goods_receipts`): tagihan hanya bisa menagih kuantitas yang
diterima. Yang tidak ada: **jadwal bayar**. Tagihan yang disetujui tidak punya
pemilik langkah "buat pembayaran" — ia menunggu seseorang ingat.

### 1.3 Subkontrak & mandor

```
SPK ──(ajukan→setujui)──▶ opname subkon ──(ajukan→setujui)──▶ tagihan ─▶ PAY
  │  addendum, BAST sub, retensi (retention-release: mesin membuat tagihan pelepasan,
  │  submit tanpa aktor — SoD lolos by design, penyetuju = pelepas retensi)
  └─ SP3 mandor ─▶ opname mandor ─▶ ...
```

Rantai paling lengkap di sistem; retensi dua arah (AR & SPK) adalah pembeda
nyata untuk kontraktor. Celahnya sama dengan 1.2: opname disetujui → tagihan →
bayar tidak punya pendorong.

### 1.4 Lapangan → progres → tagihan

```
Laporan harian (Lapangan, foto+GPS) ─▶ progres mingguan ─▶ kurva-S vs baseline
IPP / izin kerja / K3 (ajukan→setujui) ─▶ inspeksi mutu ─▶ NCR (memblokir BAST) ─▶ BAST ─▶ termin
```

Di produksi: **seluruh cabang Engineering dan Mutu tidak terjangkau** karena
peran admin kehilangan `eng.*`/`qc.*` (P-1 di HASIL-UJI). Artinya IPP,
inspeksi, dan NCR — gerbang mutu sebelum BAST — hari ini tidak dijalankan
siapa pun di produksi, dan BAST bisa disetujui tanpa NCR karena tidak ada NCR
yang pernah dibuat. Ini bukan celah desain; ini celah deployment yang
**meniadakan kendali** yang sudah dibangun.

### 1.5 Kendali yang benar-benar berjalan (dan harus dipertahankan)

| Kendali | Di mana | Catatan |
|---|---|---|
| Maker-checker | `SegregationOfDuties` | Dapat dimatikan lewat Pengaturan; lolos untuk dokumen tanpa jejak pengajuan (by design) |
| Persetujuan berjenjang | `ApprovalLevels`, `required_levels` | Dipakai keputusan pemenang; tersedia untuk jenis lain |
| Gerbang anggaran / harga | PO submit, `confirmResubmit` | Peringatan dua tahap dengan angka nyata |
| Prakualifikasi vendor | PO submit | Override beralasan, tercatat |
| Tiga arah PO–GRN–tagihan | `ApBillService` | Parsial per GRN |
| Retensi | AR & SPK | Pelepasan oleh mesin dengan pemisahan hak |
| 19 tenggat | `WatchedDeadlines` | Dua tingkat, dedupe, eskalasi tingkat |
| Tutup buku, backup | `fin:close-watch`, `erp:backup-watch` | — |

---

## 2. Yang terjadi pada data produksi (4 Sep 2026)

Data produksi = seed demo + 5 dokumen buatan manusia (2 penawaran, 2 kontrak,
1 proyek). Angka di bawah kecil; **polanya** yang penting.

| Rantai | Dokumen | Keadaan | Umur |
|---|---|---|---|
| Q2C | QTN/2026/VII/0004 menang 22 Agu | CTR/2026/VIII/0005 **draf** | 13 hari tanpa kontrak aktif |
| Q2C | QTN/2026/VIII/0008 (Rp 2,04 M, draf) | CTR/2026/VIII/0004 draf Rp 1,84 M | kontrak dibuat **sebelum** penawaran diajukan — urutan terbalik, tidak ada yang mencegah |
| Q2C | INV/2026/VIII/0004 Rp 15,42 M | disetujui, jatuh tempo 22 Sep | 18 hari lagi; tidak ada jadwal penagihan |
| P2P | PO/2026/III/0002 Rp 128 jt | disetujui, 0 GRN | 40 hari; deadline 'PO lewat tanggal terima' hanya bila `expected_date` diisi |
| P2P | BIL/2026/VII/0002 Rp 48,5 jt | disetujui, jatuh tempo 27 Jun | **69 hari lewat** |
| P2P | PAY/2026/VIII/0002 Rp 10 jt | **submitted** | **33 hari**; tak tampil di kartu dasbor |
| SCM | SPK/2026/III/0002 Rp 2,1 M | submitted | 40 hari (seed, tanpa jejak pengajuan) |
| SVC | 4 tiket `assigned` | tanpa `resolved_at` | 23–40 hari; "melewati SLA" di dasbor, tidak ada eskalasi |
| IAM | 0 pengajuan cuti | — | modul cuti belum dipakai |

Tiga pola:

1. **Berhenti setelah gerbang, bukan di gerbang.** Semua dokumen di atas sudah
   `approved` (atau `submitted` tanpa penolakan). Gerbangnya bekerja; langkah
   setelahnya tidak berpemilik.
2. **Tanggal yang tidak diisi tidak diawasi.** PO tanpa `expected_date`, tiket
   tanpa `sla_due_at` di resource, PR tanpa `needed_date` — deadline-watch
   hanya sekuat kolom yang diisi. Formulir tidak mewajibkannya.
3. **Urutan proses tidak ditegakkan lintas dokumen.** Kontrak boleh ada sebelum
   penawaran diajukan; PO boleh ada tanpa PR (by design untuk darurat, tetapi
   tanpa alasan tercatat seperti override prakualifikasi).

---

## 3. Celah — diurutkan menurut uang yang tertahan

| # | Celah | Bukti produksi | Jenis | Perbaikan | Status |
|---|---|---|---|---|---|
| B2 | Antrean persetujuan menua tanpa pengingat/eskalasi | PAY 33 hari | aliran | `erp:approval-watch` + setting `approvals.aging_days` | **dibangun** (§4) |
| B0 | Kotak masuk tidak lengkap & menawarkan dokumen milik sendiri | 1/4 tak tampil; PR disetujui pengajunya | aliran + kendali | `ApprovalQueue` + Tugas Saya | **dibangun** (§4, patch 1) |
| B1 | Tagihan vendor disetujui tidak punya jadwal bayar | BIL 69 hari lewat | aliran | tenggat baru `ap_due` di `WatchedDeadlines` (kolom `due_date`, scope approved & belum lunas, izin `fin.create`) + tombol **Buat pembayaran** di tagihan | ½ hari — satu entri registri |
| A2 | Piutang jatuh tempo: diawasi tetapi tanpa tindakan | INV Rp 15,4 M | aliran | surat penagihan 1/2/3 sebagai formulir rumah dari invoice (katalog cetak sudah ada), status `dunning_level` | 1–2 hari |
| A1 | Penawaran menang → kontrak diketik ulang | QTN vs CTR beda Rp 200 jt | aliran + kendali | `quotations/{q}/create-contract`: salin pelanggan, nilai, baris, termin; kontrak menyimpan `quotation_id`; selisih nilai wajib beralasan | 1 hari |
| C1 | Peran produksi kehilangan `eng.*`/`qc.*` | Engineering 1 layar, Mutu 0 | deployment | re-seed + pemeriksaan drift di `sync-erp1.sh` | 1 jam |
| C2 | Jejak persetujuan tak tampil di 22/28 jenis dokumen | PR tanpa kartu Persetujuan | audit | `->load('approvals.user')` di `show()` | ½ hari |
| C3 | Maker-checker lolos untuk dokumen tanpa jejak pengajuan | PR disetujui pengajunya | kendali | (a) impor/seed menulis baris `submitted`; (b) penjaga menolak bila jejak kosong **dan** `requested_by` = penyetuju | ½ hari |
| D1 | Tanggal pendorong tidak wajib | PO tanpa expected_date | data | `expected_date` wajib di PO, `needed_date` di PR, `sla_due_at` dihitung saat tiket dibuat | ½ hari |
| D2 | Tiket melewati SLA tanpa eskalasi | 4 tiket | aliran | entri `WatchedDeadlines` `ticket_sla` (kolom `sla_due_at`, scope open/assigned/in_progress) | ½ hari |
| E1 | Tidak ada pelimpahan persetujuan saat cuti | modul cuti ada, belum dipakai | kendali | `approvals.delegations` (dari, kepada, rentang tanggal) — penerima notifikasi & pemegang keputusan berpindah | 2–3 hari |
| E2 | Tidak ada ambang nilai persetujuan | semua PO satu tingkat | kendali | `required_levels` sudah ada; ambang per jenis (≤ Rp 25 jt satu tingkat, > Rp 250 jt direktur) di Pengaturan | 1–2 hari |
| E3 | PO tanpa PR tidak butuh alasan | — | kendali | `pr_bypass_reason` wajib bila `purchase_requisition_id` kosong — pola override prakualifikasi | ½ hari |

---

## 4. Yang dibangun hari ini (patch 2, teruji)

### 4.1 `ApprovalQueue` — satu antrean untuk tiga pembaca
`Modules/Core/Support/ApprovalQueue.php`. Kotak masuk, kartu dasbor, dan
pengawas umur memakai fungsi yang sama atas `ApprovableDocuments` (28 jenis).
Baru: **fallback pemilik** — bila tidak ada baris `submitted`, kolom
`requested_by`/`created_by`/`submitted_by`/`employee_id` menentukan "milik
sendiri", sehingga kasus PR/2026/III/0002 (admin menyetujui permintaannya
sendiri lewat kartu dasbor) tidak lagi ditawarkan.

### 4.2 `erp:approval-watch` — 08:45 setiap pagi
`Modules/Core/Console/Commands/ApprovalWatchCommand.php`, dijadwalkan setelah
`deadline-watch` (08:30). Untuk setiap dokumen menunggu ≥ `approvals.aging_days`
(bawaan 5): pengingat ke semua pemegang izin approve jenis itu **kecuali
pengaju**, dedupe per dokumen (signature = kode), diulang tiap 3 hari selama
belum diputus. Pada ≥ 2× ambang: judul berganti **"Eskalasi: …"** dan pemegang
`fin.approve` (direktur) ikut menerima. `--dry-run` untuk melihat tanpa
mengirim. Setting terdaftar di Pengaturan › Proyek & Persetujuan.

Pada data produksi hari ini perintah ini akan menghasilkan dua baris:
`[ESKALASI] PAY/2026/VIII/0002 Pembayaran keluar 33 hari` dan
`[ESKALASI] SPK/2026/III/0002 SPK subkontraktor 40 hari`.

### 4.3 Tes (`tests/Feature/Core/ApprovalWatchTest.php`, 5 tes)
Di bawah ambang diam; pada ambang pengingat ke penyetuju lain, bukan pengaju
(meski pengaju memegang izin approve), bukan direktur; idempoten pada pagi
yang sama; pada 33 hari (kasus produksi) eskalasi ke direktur dengan tautan
dokumen; ambang adalah setting; antrean tidak menawarkan dokumen kepada
pemintanya bahkan tanpa baris `submitted`. Suite `tests/Feature/Core` penuh:
**564 tes hijau**.

---

## 5. Yang sengaja tidak dibangun

| Usulan | Mengapa ditunda |
|---|---|
| Otomatis membuat pembayaran dari tagihan yang disetujui | Keputusan kas adalah keputusan manusia (arus kas, prioritas vendor). Yang benar adalah *tenggat* + tombol, bukan otomasi. |
| Otomatis menagih (dunning) lewat email | `MAIL_MAILER=log` di produksi; surat penagihan Indonesia biasanya ditandatangani dan dikirim resmi. Formulir cetak, bukan email otomatis. |
| Menegakkan urutan penawaran → kontrak secara keras | Kontrak tanpa penawaran memang terjadi (penunjukan langsung, addendum). Yang perlu: **alasan tercatat**, bukan larangan. |
| Mengubah maker-checker untuk semua dokumen tanpa jejak | `RetentionService` bergantung pada submit-tanpa-aktor. Perbaikan yang aman adalah di kotak masuk (dibangun) dan di impor/seed (B/C3a). |
| Ambang nilai persetujuan (E2) | Angka ambangnya keputusan direksi; mekanismenya (`required_levels`) sudah ada. Bangun setelah angkanya ditetapkan. |
| Pelimpahan persetujuan (E1) | Butuh keputusan siapa boleh melimpahkan ke siapa — kebijakan sebelum kode. |

---

## 6. Urutan yang saya sarankan

1. **Hari ini, produksi**: re-seed izin (C1); pasang patch; jalankan
   `php artisan erp:approval-watch --dry-run` dan lihat dua eskalasi yang
   sudah menunggu.
2. **Minggu ini**: B1 + D2 (dua entri `WatchedDeadlines`), C2 (jejak
   persetujuan tampil), D1 (tanggal wajib) — semuanya setengah hari masing-
   masing dan semuanya memperluas mesin yang sudah ada.
3. **Setelah angka direksi**: E2 ambang nilai, E1 pelimpahan.
4. **Riset dulu**: A1/A2 menyentuh cara tim penjualan bekerja; wawancara 2
   orang sebelum membangun (rencana riset di ASESMEN-UX §6).

Tolok ukur keberhasilan tiga bulan ke depan, semuanya sudah bisa diukur dari
`core/inbox` dan `core_approvals`: **median umur antrean persetujuan < 3
hari**, **tidak ada dokumen > 10 hari**, **tagihan vendor dibayar sebelum
jatuh tempo ≥ 90%**.
