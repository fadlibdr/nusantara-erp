# Kebijakan Pengakuan Pendapatan — PSAK 115

**PT Nusantara Karya Integrasi** · disusun 28 Juli 2026 · berlaku sejak periode buku 2026

> Dokumen ini adalah telaah PSAK 115 dan penerapannya sebagai kebijakan
> akuntansi perusahaan, sekaligus spesifikasi dari mesin pengakuan pendapatan
> di ERP (layar **Keuangan › Pengakuan Pendapatan**). Ditelaah oleh mesin,
> untuk disahkan manajemen — tunjukkan dokumen ini ke akuntan publik Anda
> sebelum tutup buku tahunan.

---

## 1. Standar yang berlaku: PSAK 115 = PSAK 72

**PSAK 115 dan PSAK 72 adalah satu standar yang sama** — *Pendapatan dari
Kontrak dengan Pelanggan*, adopsi IFRS 15. DSAK IAI menomori ulang seluruh SAK
agar sejajar dengan nomor IFRS-nya (disahkan 12 Desember 2022, efektif
1 Januari 2024) tanpa mengubah substansi: PSAK 72 → **PSAK 115**, sebagaimana
PSAK 73 → PSAK 116 (sewa) dan PSAK 57 → **PSAK 237** (provisi). Dokumen lama
yang menyebut PSAK 72 tetap benar isinya; nomornya saja yang berganti.

Yang penting diingat: PSAK 115 **menggantikan** PSAK 34 *Kontrak Konstruksi*
sejak 2020. Tidak ada lagi "standar khusus konstruksi" — kontrak konstruksi
tunduk pada model lima langkah yang sama dengan semua kontrak pelanggan lain.

## 2. Mengapa ini mendesak untuk perusahaan ini

Sistem selama ini mengakui pendapatan **saat invoice termin disetujui** (basis
penagihan). Data live per Juli 2026 menunjukkan persis mengapa itu salah dua
arah sekaligus:

| | CTR/2026/I/0001 (Graha Sentosa) |
|---|---|
| Nilai kontrak (DPP) | Rp 48.500.000.000 |
| Tertagih (DP 20%) | Rp 9.700.000.000 |
| Biaya s.d. kini | Rp 228.240.000 |
| RAP (estimasi total biaya) | Rp 42.173.913.043 |
| **Kemajuan (cost-to-cost)** | **± 0,5%** |
| **Pendapatan seharusnya (PSAK 115)** | **± Rp 262 juta** |
| **Pendapatan menurut basis penagihan** | **Rp 9.700 juta — lebih saji ± Rp 9,4 M** |

Uang muka 20% itu **liabilitas kontrak** — utang kinerja kepada pelanggan —
bukan pendapatan. Arah sebaliknya juga terjadi: pekerjaan fisik 55% dengan
termin berikutnya baru bisa ditagih di progres 80% berarti ada pendapatan yang
*belum* diakui padahal sudah dihasilkan (aset kontrak).

## 3. Model lima langkah, diterapkan

### Langkah 1 — Identifikasi kontrak (para. 9)
Kontrak diakuntansikan bila: disetujui para pihak, hak dan syarat pembayaran
teridentifikasi, bersubstansi komersial, dan penagihannya **kemungkinan besar
tertagih**. Di ERP: hanya kontrak berstatus **approved** yang masuk mesin
pengakuan; penilaian kolektibilitas dilakukan saat persetujuan kontrak.

### Langkah 2 — Identifikasi kewajiban pelaksanaan (para. 22–30)
- **Kontrak konstruksi** (`scope_type = construction`): desain, material,
  pekerjaan sipil/ME saling terkait tinggi dan dijanjikan sebagai satu keluaran
  terintegrasi (gedung) → **satu kewajiban pelaksanaan** (para. 29(a)).
- **Integrasi sistem** (`system_integration`): pengadaan perangkat + instalasi
  + konfigurasi menjadi satu sistem berfungsi → **satu kewajiban pelaksanaan**.
  (Bila suatu kontrak menjanjikan barang terpisah yang berdiri sendiri —
  mis. jual perangkat lepas — itu kewajiban terpisah; saat ini tidak ada.)
- **Kontrak pemeliharaan** (SVC): jasa siaga (*stand-ready*) per periode —
  satu kewajiban pelaksanaan berupa **rangkaian** jasa harian yang substansinya
  sama (para. 22(b)).
- **Tiket/jasa lepas & penjualan barang**: kewajiban tunggal berdurasi pendek.

### Langkah 3 — Harga transaksi (para. 47–72)
- **DPP kontrak, tanpa PPN** — PPN dipungut untuk negara, bukan pendapatan.
- **Pekerjaan tambah-kurang (CCO)** yang disetujui mengubah harga transaksi.
  Karena sisa pekerjaan **tidak bersifat distinct** (satu kewajiban
  terintegrasi), modifikasi diakuntansikan sebagai **bagian kontrak eksisting**
  dengan **penyesuaian kumulatif (cumulative catch-up)** — para. 21(b). Mesin
  menghitung % kemajuan kumulatif dikali harga transaksi **per akhir periode**:
  `crm_contracts.value` (yang sudah dimutakhirkan layanan CCO) dikurangi CCO
  disetujui yang `change_date`-nya jatuh SETELAH periode berakhir. Catch-up-nya
  tetap terjadi, hanya mendarat di bulan pekerjaan tambah itu disepakati — bukan
  mundur ke bulan yang sedang ditutup. Tutup buku di sini berjalan pada minggu
  pertama bulan berikutnya, sehingga tanpa cut-off ini CCO tertanggal 2 Agustus
  menambah pendapatan Juli lewat jurnal bertanggal 31 Juli, dan run Juli yang
  sudah diposting tidak dapat dihitung ulang lagi.
- **Retensi 5%** adalah bagian harga transaksi, bukan pengurang. Tujuannya
  proteksi kualitas, bukan pendanaan → **bukan komponen pendanaan signifikan**
  (para. 62(c)).
- **Uang muka/termin DP** juga bukan komponen pendanaan bila jaraknya terhadap
  kinerja ≤ 1 tahun (praktis, para. 63) atau tujuannya proteksi/mobilisasi.
- Denda keterlambatan/bonus kinerja (bila ada di kontrak) adalah **imbalan
  variabel** — diestimasi dan dibatasi (para. 56); saat ini diperlakukan manual
  lewat penyesuaian EAC/harga di layar run.

### Langkah 4 — Alokasi (para. 73–86)
Satu kewajiban pelaksanaan per kontrak → seluruh harga transaksi teralokasi ke
kewajiban itu. Tidak ada isu alokasi selama pola kontrak tetap seperti sekarang.

### Langkah 5 — Pengakuan: *over time* vs *point in time* (para. 35–45)

| Aliran pendapatan | Dasar | Kesimpulan |
|---|---|---|
| Konstruksi di lahan pelanggan | Para. 35(b): pelanggan **mengendalikan aset selagi dibangun** (gedung berdiri di tanah pelanggan) | **Over time** |
| Integrasi sistem di premis pelanggan | Para. 35(b); dan/atau 35(c): instalasi terkonfigurasi spesifik **tanpa penggunaan alternatif** + **hak tagih atas kinerja s.d. kini** (struktur termin per progres) | **Over time** |
| Kontrak pemeliharaan | Para. 35(a): pelanggan **menerima dan mengonsumsi manfaat bersamaan** jasa siaga | **Over time**, garis lurus |
| Tiket lepas / penjualan barang | Tidak memenuhi 35 | **Point in time** saat selesai/serah |

### Ukuran kemajuan (para. 39–45, B14–B19)

**Metode input biaya-ke-biaya (cost-to-cost)** dipilih untuk konstruksi dan
integrasi sistem:

```
% kemajuan  = biaya kontrak kumulatif  ÷  estimasi total biaya (EAC)
Pendapatan kumulatif = % kemajuan × harga transaksi
Pendapatan periode   = kumulatif kini − kumulatif yang telah diakui
```

Alasan memilih input, bukan output: biaya tercatat harian di `fin_project_costs`
(material saat **dikeluarkan ke lapangan**, upah dari payroll, subkon dari
opname, alat dari mobilisasi) sehingga objektif dan teraudit; sedangkan progres
fisik kurva-S adalah klaim lapangan yang tidak selalu sinkron dengan serah
kendali. Kurva-S tetap ditampilkan sebagai pembanding manajerial.

Kehati-hatian metode input:
- **Material belum terpasang** (B19(b)): karena biaya masuk saat *pengeluaran
  barang* (ISS) dan bukan saat penerimaan gudang (GRN), basis biaya sudah
  mendekati basis terpasang. Tumpukan material signifikan yang keluar gudang
  tetapi belum terpasang wajib ditelaah saat run dan, bila material, dikeluarkan
  dari pengukuran lewat penyesuaian EAC/biaya.
- **Inefisiensi signifikan** (B19(a) — pengerjaan ulang, material terbuang)
  tidak mencerminkan kinerja → dikeluarkan lewat telaah yang sama.
- **EAC** (estimasi biaya penyelesaian) adalah estimasi manajemen yang
  **dimutakhirkan tiap periode**. Sumber sistem: RAP terkini proyek (statusnya
  ditampilkan di layar run; RAP belum disetujui diberi tanda). EAC dapat
  diubah per baris sebelum posting, dan minimal selalu ≥ biaya terjadi.
- **Tanpa estimasi andal** (para. 45): bila proyek belum punya RAP, hasil
  kontrak belum bisa diukur wajar → pendapatan diakui **sebesar biaya terjadi**
  (margin nol) sepanjang biaya dipulihkan, sampai estimasi tersedia. Berlaku
  juga bila RAP hanya menutupi **sebagian** proyek kontrak: penyebut yang
  mengabaikan satu site sementara pembilangnya menghitung biaya site itu bukan
  ukuran kemajuan apa pun. Jalan keluarnya menyetujui RAP yang kurang, atau
  mengisi **EAC manajemen** pada baris run — satu-satunya input yang memang
  berlaku untuk seluruh kontrak.
- % kemajuan dibatasi 100%; biaya melebihi EAC otomatis menaikkan EAC.

Untuk **pemeliharaan**: penagihan berkala (bulanan/kuartalan) ≈ garis lurus,
sehingga basis penagihan yang berjalan **sudah memenuhi** PSAK 115; akrual
tambahan hanya bila ada gap periode yang material. Mesin tidak menyentuh
kontrak `maintenance`.

## 4. Penyajian: aset kontrak, liabilitas kontrak, piutang (para. 105–109)

Per kontrak, tiap akhir periode:

```
Saldo kontrak = pendapatan kumulatif (PSAK 115) − penagihan kumulatif (DPP)
```

- Saldo **positif** → **1-1360 Aset Kontrak** ("pendapatan belum difakturkan").
  Berubah menjadi piutang saat hak menjadi tak bersyarat (ditagihkan).
- Saldo **negatif** → **2-1410 Liabilitas Kontrak** ("penagihan melebihi
  pendapatan", termasuk uang muka).
- **Piutang retensi (1-1350)**: tetap piutang — haknya hanya menunggu waktu
  (BAST II / akhir masa pemeliharaan), bukan menunggu kinerja tambahan.
- Aset kontrak diuji penurunan nilai sebagaimana piutang (PSAK 109/71 ECL) —
  di luar cakupan mesin ini, ditelaah saat tutup buku.

## 5. Kontrak merugi (onerous) — PSAK 237 (d/h PSAK 57)

PSAK 115 tidak mengatur rugi kontrak; berlaku PSAK 237: bila **EAC > harga
transaksi**, seluruh taksiran rugi diakui **seketika**:

```
Provisi = (EAC − harga transaksi) × (1 − % kemajuan)
```

(bagian rugi yang porsi kemajuannya sudah lewat dengan sendirinya sudah masuk
laba-rugi lewat margin negatif). Jurnal: **Dr 5-1600 Beban Provisi Kerugian
Kontrak / Cr 2-1700 Provisi Kerugian Kontrak**, dilepas seiring kemajuan.

## 6. Interaksi dengan PPh final konstruksi (PP 9/2022)

PPh final jasa konstruksi dikenakan atas **pembayaran/tagihan**, bukan atas
pendapatan PSAK 115 — beda waktu antara pendapatan buku dan objek pajak adalah
normal dan **tidak menimbulkan pajak tangguhan** (penghasilan rezim final di
luar cakupan PSAK 46/pajak penghasilan tangguhan). Ekspor pajak & bukti potong
tetap bekerja dari basis tagihan seperti sekarang; tidak ada perubahan.

## 7. Mekanika pembukuan di ERP

Praktik yang lazim untuk kontraktor, dan yang diimplementasikan:

1. **Invoice termin tetap seperti sekarang** (Dr Piutang / Cr Pendapatan +
   PPN) — dokumen tagih tidak berubah, faktur pajak tidak berubah.
2. **Run "Pengakuan Pendapatan" bulanan** (dokumen `POC/{tahun}/{bulan}/{seq}`)
   menghitung posisi kumulatif PSAK 115 per kontrak lalu memposting **satu
   jurnal penyesuaian** pada tanggal akhir periode yang menggeser saldo
   pendapatan-tertagih menuju pendapatan-dihasilkan:
   - kurang tagih → Dr 1-1360 Aset Kontrak / Cr Pendapatan (per akun skopnya)
   - lebih tagih → Dr Pendapatan / Cr 2-1410 Liabilitas Kontrak
   - plus jurnal provisi rugi bila ada.
   Net efek buku besar per akhir periode = PSAK 115 murni.
3. Run bersifat **draft → posted**: draft bisa dihitung ulang dan EAC-nya
   diubah per baris; posted terkunci, satu per periode, tidak boleh melompati
   run posted yang lebih baru, dan menghormati periode fiskal terbuka.
4. **Run perdana adalah catch-up kumulatif**: seluruh selisih historis basis
   penagihan vs PSAK 115 terserap pada periode run pertama (untuk data live
   sekarang: memindahkan ± Rp 9,44 M dari pendapatan ke liabilitas kontrak).

## 8. Pengungkapan minimum (para. 110–129) — daftar untuk tutup buku

- Disagregasi pendapatan (konstruksi / integrasi / pemeliharaan — sudah
  terpisah per akun 4-1100/4-1200/4-1300).
- Saldo awal-akhir aset kontrak, liabilitas kontrak, piutang retensi, dan
  pendapatan yang diakui dari liabilitas kontrak awal periode.
- Sisa harga transaksi yang dialokasikan ke kewajiban belum terpenuhi
  (*remaining performance obligation* = nilai kontrak − pendapatan kumulatif;
  tersedia per baris run).
- Pertimbangan signifikan: pilihan metode input, cara menetapkan EAC.
- Provisi kontrak merugi (bila ada).

## 9. Yang secara sadar TIDAK diubah

- **Basis penagihan untuk pajak** (PPN, PPh final, e-Faktur) — rezim pajak
  memang berbasis tagihan/pembayaran.
- **Kontrak pemeliharaan** — penagihan berkalanya sudah ≈ garis lurus.
- **Pengakuan biaya** — biaya kontrak tetap dibebankan saat terjadi; kapitalisasi
  biaya memperoleh kontrak (para. 91) tidak relevan (tidak ada komisi penjualan
  inkremental material).

## Sumber

- [IAI — PSAK Umum (daftar SAK efektif)](https://web.iaiglobal.or.id/PSAK-Umum/83)
- [IAI — workshop Penerapan PSAK 115 & PSAK 116](https://knowledge.iaiglobal.or.id/detail_workshop/75395748724d5331463755574f376c6832577638782f6e764452612b706e614c57653150413530306b366b536f6d7371336d6c77484b536976336746654454673435556677333555567233792b324a66662f5072373443423464613954752b6a3679544c5765522b506746675432592f)
- [ED PSAK 72 — Pendapatan dari Kontrak dengan Pelanggan (IAI)](https://web.iaiglobal.or.id/assets/files/file_berita/ED%20PSAK%2072_Pendapatan%20dari%20Kontrak%20dengan%20Pelanggan.pdf)
- PP 9/2022 (PPh final jasa konstruksi); PSAK 237 (provisi); PSAK 46 (pajak penghasilan)
