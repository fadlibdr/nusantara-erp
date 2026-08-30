# Laporan Paket P7 — Paket tender

Branch: feat/p7 · Commit: **belum dikomit** — ketiga lane (backend, cetak+SPA,
dokumentasi) bekerja di satu pohon kerja; HEAD `feat/p7` masih `a2538ff` (merge P6),
dan seluruh perubahan P7 berdiri sebagai perubahan yang belum di-stage · Tanggal:
2026-08-30

Berkas lelang berhenti menjadi lampiran pada penawaran. **Paket tender** (`TND`)
mencatat register dokumen lelang — judul, bab, tanggal terbit, addendum ke-n — dengan
**nomor addendum yang tidak boleh berlubang**, berita acara aanwijzing, dan checklist
kelengkapan yang tersimpan sebagai **snapshot**, bukan sebagai daftar kunci. **Lembar
TKDN** (`TKD`) menghitung TKDN Jasa menurut **Permenperin 35/2025 Pasal 14 dan Lampiran
IV huruf B** — peraturan yang dibaca, bukan diingat — dan baris penawaran yang belum
diuraikan biayanya **BELUM DINILAI**: bukan 0%, bukan 100%, dan persennya tidak pernah
tampil tanpa cakupannya. Cakupan itu mengukur **besaran biaya yang diuraikan terhadap
nilai barisnya**, bukan sekadar ada-tidaknya satu baris biaya: satu baris **Rp 1** tidak
bisa memutihkan lembarnya, ia berstatus **DINILAI SEBAGIAN**. **RKK penawaran** (`RKK`, cetak **F/RKK**) menyusun empat
bagian Permen PUPR 10/2021 dengan baris IBPRP **dibaca hidup** dari register risiko P6
dan biaya SMKK yang **rupiahnya diturunkan** dari baris RAB. **Pustaka metode kerja**
(`core_method_library`, `MTD`) memberi "Metode Pelaksanaan" identitas berversi yang
dikutip penawaran — dan versi yang sudah digantikan **ditolak** dengan menyebut
penggantinya. **Penyusun kualifikasi** merakit personil, alat, dan subkon dari master
modul lain, baca-saja, dengan **sertifikat kedaluwarsa yang tidak pernah dihitung
sebagai kualifikasi dan tidak pernah dibuang diam-diam** (cetak **F/SBD**, **F/DA**).
Katalog cetak 58 → **61**; mask penomoran 55 → **59**; kartu Lampiran 37 → **39**.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.1 Register Dok. Lelang / aanwijzing / addendum lelang — *"tidak ada register RKS/BAB/BA aanwijzing sebagai entitas"* | 🟡 | ✅ | migrasi `2026_08_30_000386`+`000387`; `TenderPackageService::replaceDocuments` (addendum kontigu); `TenderPackageTest` (8); `TenderPackageApiTest` (9) |
| 3.1 TKDN — *"0 berkas"* | ⬜ | ✅ **sisi Jasa** | migrasi `000388`+`000389`; `TkdnService` (Permenperin 35/2025 Psl 14 + Lampiran IV.B, sumber dikutip di docblock); `TkdnWorksheetTest` (13). **TKDN gabungan Barang dan Jasa tetap ⬜** — lihat *Perlu konfirmasi pemilik* #2 |
| 3.1 Metode Pelaksanaan — *"tidak ada pustaka"* | ⬜ | ✅ | migrasi Core `2026_08_30_000191` + `000393` (kolom pada `crm_quotations`); `MethodLibraryService` (revisi = baris baru); `MethodLibraryTest` (5) |
| 3.1 RKK / Pra-RK3K — *"0 berkas"* | ⬜ | ✅ | migrasi `000390`–`000392`; `RkkService` (IBPRP hidup + SMKK turunan); `RkkDocumentTest` (10 sejak ⟲); cetak F/RKK (`TenderFormPrintTest`) |
| 3.1 Kualifikasi teknis, personil, alat, subkon — *"tidak ada penyusun paket kualifikasi"* | 🟡 | ✅ | `TenderQualificationService` (tiga baca mentah); `TenderQualificationTest` (7 sejak ⟲); cetak F/SBD + F/DA (`TenderFormPrintTest`, `TenderPrintCatalogueTest`) |
| 3.1 Paket submit & hasil — *"tanpa checklist kelengkapan"* | 🟡 | 🟡 | Template 21 butir di `config('erp.tender.checklist_template')`, snapshot di `crm_tender_packages.checklist`, endpoint `GET/PUT .../checklist` + `GET .../checklist-template`, demo terisi 17/21 — **tetapi belum ada layar yang menampilkan atau mencentangnya** (Deviasi baru #1) |
| 3.1 Mesin AHSP — *"tanpa komponen biaya SMKK"* | 🟡 | 🟡 | P7 menautkan biaya SMKK ke **baris RAB** lewat RKK (`crm_rkk_smkk_costs`), bukan menambahkan komponen SMKK ke `est_ahsp`. Sisi AHSP-nya tidak disentuh |
| 3.1 Surat Penawaran, Pernyataan, Pakta Integritas | 🟡 | 🟡 | tidak disentuh — checklist paket tender **menyebut** Pakta Integritas sebagai butir, tetapi tidak ada dokumennya. Bukan lingkup P7 |
| 3.9 Biaya SMKK di BoQ — *"seksi BoQ manual, tanpa template"* (diwariskan P6) | 🟡 | 🟡 | sisi **RKK** selesai: baris biaya SMKK menunjuk baris RAB nyata, rupiahnya turunan, dan F/RKK mencetak jumlahnya. Template seksi SMKK pada RAB tetap belum ada (seeder/mesin Estimation) |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

**Asumsi Bagian 2 yang dipakai: satu.** Keputusan **#4** (lampiran DWG/MPP & batas
ukuran) — P7 akhirnya punya dokumen yang membutuhkan kebijakan `pptx`/`docx` yang
dipaku P0-D: dek metode pelaksanaan menumpang `core/method-library`. Tidak ada asumsi
lain dari Bagian 2 yang dipakai paket ini.

### PERLU KONFIRMASI PEMILIK

**1. Rumus TKDN — apa yang dibaca, apa yang diperiksa ulang, dan apa yang tidak.**
`TkdnService` menghitung **TKDN Jasa** menurut **Permenperin Nomor 35 Tahun 2025**
**Pasal 14** dan **Lampiran IV huruf B**, dari salinan resmi
`https://peraturan.go.id/files/permenperin-no-35-tahun-2025.pdf`. **Bukan** rumus
2011/2013 dari korpus.

**Lane dokumentasi memeriksa ulang kutipan itu terhadap teks peraturannya sendiri** —
PDF diunduh dan diekstrak — dan empat hal cocok kata demi kata:

- **Pasal 14** ayat (1)–(3): perbandingan biaya Jasa Industri keseluruhan dikurangi biaya
  luar negeri terhadap biaya keseluruhan, dihitung sampai di lokasi pengerjaan, atas tiga
  kelompok biaya — *tenaga kerja*, *alat kerja/fasilitas kerja*, *Jasa umum*.
- **Lampiran IV huruf B**: WNI 100% / WNA 0%; tabel alat kerja enam baris persis
  (DN×DN 100%, DN×(DN+LN) 100%, DN×LN 100%, LN×DN 50%, LN×(DN+LN) *"50% x proporsional
  saham dalam negeri"*, LN×LN 0%); jasa umum penyedia DN 100% / LN 0%.
- **Pasal 17**: *"Penghitungan nilai TKDN Jasa Industri ditetapkan oleh Sekretaris
  Jenderal."* — **docblock `TkdnService` semula memarafrasekannya sebagai *"Petunjuk
  teknis penghitungan TKDN Jasa Industri … ditetapkan oleh Sekretaris Jenderal"*, dan itu
  keliru**: kata *"Petunjuk teknis"* adalah bunyi **Pasal 13**, yang berbicara tentang
  **Barang** (*"Petunjuk teknis penghitungan nilai TKDN Barang ditetapkan oleh Sekretaris
  Jenderal."*). Selisihnya bukan gaya bahasa — parafrase itu **mengecilkan
  pendelegasiannya**: untuk Jasa, peraturan menyerahkan **penghitungannya sendiri**
  kepada Sekretaris Jenderal, bukan panduan tentang penghitungan itu. Diperbaiki di
  gelombang perbaikan; kutipannya kini kata demi kata dan dipaku uji.
- **Pasal 74**: mencabut Permenperin 16/M-IND/PER/2/2011, 02/2014, 03/2014, dan 46/2022,
  *"dicabut dan dinyatakan tidak berlaku"*.

**Satu "koreksi" yang lahir dari pemeriksaan itu — dan yang ternyata SALAH; dicoret di
gelombang perbaikan.** Laporan ini semula menyatakan bahwa tanggal *"ditetapkan di
Jakarta pada tanggal 11 September 2025"* pada docblock `TkdnService` **tidak dapat
diverifikasi**, karena halaman tanda tangan pada salinan yang diperoleh lane dokumentasi
tidak menghasilkan teks, dan menganjurkan membersihkan baris itu dari docblock-nya.

**Anjuran itu ditarik. Tanggalnya ADA di dalam PDF dan benar.** `pdftotext -layout` atas
salinan resmi yang sama (`https://peraturan.go.id/files/permenperin-no-35-tahun-2025.pdf`,
124 halaman) mengeluarkan, pada halaman tanda tangannya:

```
                                 Ditetapkan di Jakarta
                                 pada tanggal 11 September 2025
```

Yang gagal adalah **ekstraksi teksnya**, bukan peraturannya — dan sebuah ekstraksi yang
gagal bukan ketiadaan. Kesimpulan "tidak dapat diverifikasi" karenanya keliru, dan
menghapus baris itu akan **membuang fakta terverifikasi atas dasar kegagalan alat**.
Docblock-nya dipertahankan, ditambah satu paragraf yang menyebutkan bagaimana tanggal itu
diperoleh, supaya pemeriksa berikutnya tidak mengulangi pencabutan yang sama. Dipaku
`TkdnWorksheetTest::test_the_service_docblock_quotes_pasal_17_and_keeps_the_verified_date`.

Yang memang **tidak** terbaca adalah tanggal **diundangkan** — kolomnya kosong pada
salinan ini — dan itu sebabnya tidak ada satu pun berkas di repo yang mengutipnya.
(Tanggal 11 Desember 2025 dari sumber publik adalah tanggal **berlaku**, hal yang berbeda
dari tanggal penetapan; keduanya tidak pernah bertentangan.)

Yang **tidak** diperoleh: **keputusan Sekretaris Jenderal** yang menurut Pasal 17
menetapkan tata cara rinci penghitungan TKDN Jasa Industri. Yang dikodekan adalah
Pasal 14 dan Lampiran IV huruf B **apa adanya**; tempat mengubahnya tertulis di
`PANDUAN-ADMINISTRATOR.md` §12(e).

**1b. Ambang cakupan biaya per baris adalah ANGKA RUMAH — pemilik yang memilikinya
(baru di gelombang perbaikan).** Cakupan lembar TKDN semula diukur dengan uji
**keberadaan**: baris penawaran yang punya *setidaknya satu* baris biaya dihitung
"dinilai", dan seluruh nilainya masuk `assessed_value`. Uji keberadaan bisa dikalahkan
dengan **Rp 1** — satu baris biaya Rp 1 pada baris penawaran Rp 100 juta menjawab cakupan
**100,00%** dan `fully_assessed: true`, sebuah lembar yang terbaca bersih dan tidak
pernah ada yang menguraikan isinya. (Cacat berbentuk sama pernah ditemukan di EVM:
`config/erp.php` mencatat *"satu baris Rp 1.000 di tiap kategori kosong menghijaukan CPI
144x pada demo"*.)

Sekarang cakupan membandingkan **besaran** biaya yang diuraikan dengan **nilai baris
penawaran itu sendiri**, dan setiap baris berada di salah satu dari **tiga** keadaan:
`belum` (tak ada baris biaya), `sebagian` (ada, tetapi di bawah ambang), `penuh`. Hanya
baris `penuh` yang masuk cakupan; baris `sebagian` berdiri sendiri sebagai
`partially_assessed_value` di sebelahnya, dan **biayanya tetap dihitung** pada
`tkdn_pct` — ia biaya nyata yang benar-benar diuraikan seseorang. Ketiga ember menjumlah
persis `quotation_value`.

**Ambangnya MENGUNGKAPKAN, tidak menolak, dan ia bukan angka Permen.** Permenperin
35/2025 tidak menyebut satu pun pecahan antara biaya dan nilai penawaran: Pasal 14
berbicara tentang **biaya keseluruhan**, sementara nilai baris penawaran adalah **harga**,
yang memuat margin yang tidak diatur peraturan mana pun. Sebuah ambang karenanya tidak
bisa disandarkan pada Permen — dan paket ini sudah menolak mengarang angka di tempat lain
(butir 3 di bawah). Jadi ambangnya berdiri terbuka sebagai angka rumah: **diumumkan
sendiri di dalam muatan** (`min_cost_to_value_pct` dan kalimat `basis_cakupan`, keduanya
tercetak di kartu rekap), dipegang pemilik di
`config('erp.tender.tkdn_min_cost_to_value_pct')` (bawaan **50%**, meniru bentuk
`projects.cpi_coverage_min_pct` yang lahir dari cacat yang sama), dan **`replaceItems()`
tetap menerima baris Rp 1** — lembar yang sedang dikerjakan separuh jalan memang belum
lengkap, dan menolaknya akan mengusir pekerjaan yang sah. Yang tidak boleh terjadi
hanyalah satu hal: baris seperti itu **mengaku** dinilai penuh.

Ambang ini **sengaja tidak ada di layar Pengaturan** — ambang pengungkapan yang bisa
diturunkan ke 0 lewat formulir web berhenti berarti "cakupan", alasan yang sama yang
menahan `tender.checklist_template`. **Yang perlu diputuskan pemilik**: apakah 50% adalah
angka yang benar untuk margin pekerjaan mereka. Menaikkannya membuat lebih banyak baris
berstatus "dinilai sebagian"; menurunkannya melemahkan penjagaan itu. Angka ini tidak
pernah mengubah `tkdn_pct` — hanya cakupan yang dilaporkan bersamanya.

**2. TKDN gabungan Barang dan Jasa (Pasal 18–20) TIDAK dihitung.** Angka gabungan
menimbang TKDN tiap **Barang** dengan proporsi nilai perolehannya, dan nilai itu —
menurut Lampiran IV huruf A — datang dari **Sertifikat TKDN barang tersebut**, bukan
dari uraian biaya kita. Tidak ada tabel sertifikat TKDN barang di ERP ini, jadi tidak
ada cara jujur menghitungnya; sebuah kolom "TKDN barang" yang boleh diketik adalah
persis mesin pemalsu yang lembar ini dibuat untuk menghindari. Pekerjaan konstruksi
umumnya gabungan Barang dan Jasa, jadi **pemilik harus tahu bahwa yang dihitung sistem
ini adalah sisi Jasa-nya saja**, dan lembarnya menyebut dirinya begitu.

**3. Ambang TKDN minimum TIDAK ditegakkan.** Kepmen PUPR 602/KPTS/M/2023 (ambang TKDN
minimum jasa konstruksi) **tidak dibaca** dan tidak ada pemeriksaan ambang di mana pun.
Lembar ini menghitung dan melaporkan; ia tidak meluluskan dan tidak menahan.

**3b. Penelusuran tingkat 2 dan 3 (Pasal 15) TIDAK dilakukan — temuan lane
dokumentasi.** Pasal 15 ayat (2)–(3) menyuruh penghitungan TKDN Jasa **ditelusuri sampai
Barang dan/atau Jasa tingkat 2** yang dihasilkan penyedia dalam negeri, dan bila di
dalamnya terdapat komponen dari **Jasa Industri tingkat 3** oleh penyedia dalam negeri,
komponen itu **diperhitungkan sebesar 100%**. Lembar TKDN menerima uraian biaya **datar**
— satu tingkat — jadi rantai pasok di balik satu baris biaya tidak ditelusuri dan aturan
100% tingkat-3 tidak pernah berlaku. Akibatnya: angka yang dihasilkan adalah TKDN Jasa
**atas uraian biaya yang diketik**, dan bila sebagian biaya itu sendiri dibeli dari
penyedia dalam negeri yang punya kandungan impor, angkanya bisa **lebih tinggi** daripada
hasil penelusuran penuh. Bersama butir 1 (juknis Sekjen), inilah yang paling perlu
dikonfirmasi sebelum sebuah angka dari layar ini masuk ke dokumen penawaran.
**Pasal 16** (aktivitas harus masuk ruang lingkup KBLI Jasa Industri pada Perizinan
Berusaha) juga tidak diperiksa: sistem ini tidak menyimpan KBLI perusahaan.

**4. Butir checklist kelengkapan adalah praktik rumah, bukan kutipan peraturan.**
Kedua puluh satu butirnya adalah apa yang lazim diminta satu dokumen lelang jasa
konstruksi. Menambah, membuang, atau mengganti kalimat sebuah butir adalah keputusan
pemilik, dan tempatnya `config/erp.php` (`tender.checklist_template`) — bukan layar,
karena daftar periksa yang bisa dipendekkan diam-diam lewat formulir web berhenti
berarti "kelengkapan".

**5. Tidak ada lembar cetak TKDN sama sekali, dan itu keputusan yang harus dikatakan
dengan kata-kata.** Lembar TKDN sengaja **tidak** masuk katalog formulir rumah:
formulir penghitungan TKDN adalah formulir Kemenperin, dan mencetaknya di kop empat
pihak akan menyamarkan lembar sertifikasi sebagai lembar proyek. Konsekuensinya
angkanya harus **disalin tangan** ke formulir Kemenperin. Ini perlu dikatakan kepada
pemilik dalam kalimat, karena **sebuah layar yang menghitung persentase terbaca seperti
layar yang mencetaknya** — dan orang yang mengira lembarnya ada akan mencarinya pada
hari batas pemasukan.

**6. ~~F/SBD mengabaikan tanggal acuan penyusun kualifikasi dan selalu dicetak per hari
ini.~~ DITUTUP pada gelombang perbaikan pasca-verifikasi.** Tanggal lembar sebuah paket
tender adalah **batas pemasukan penawarannya**: registri mendeklarasikan
`'date' => 'submission_deadline'` pada kedua lembar, sehingga tanggal itu yang tercetak
di kepala lembar **dan** yang dijawab kedua penyusunnya. Paket yang belum mencatat batas
pemasukan jatuh ke `?tanggal=` / hari cetak seperti blanko mana pun, dan lembarnya tetap
mencetak tanggal yang dijawabnya (`PERSONIL PER TANGGAL`, `ALAT PER TANGGAL`). Layar
Penyusun Kualifikasi tetap memperingatkan bila tanggal acuannya berbeda dengan tanggal
lembar paket yang dipilih.

### Asumsi teknis yang diambil paket ini, dan alasannya

- **Paket tender, lembar TKDN, dan RKK BUKAN `Approvable`.** Maker-checker berkas
  lelang hidup pada **penawarannya**, yang sudah melewati siklus itu; siklus kedua akan
  meminta pemegang `crm.approve` menyetujui pengajuan yang sama dua kali — alasan yang
  sama yang menahan PPKB (P5) dan BAPP zona (P3) keluar dari `ApprovableDocuments`.
  Ketiganya tetap bernomor: berkas butuh identitas. `ApprovableDocuments` tidak
  disentuh, alasannya ditulis di migrasi `000386`.
- **Paket tender menggantung pada LEAD, bukan pada penawaran.** Sebuah lelang punya
  berkas, jadwal, dan BA aanwijzing sebelum ada penawaran, dan sering berakhir tanpa
  satu pun.
- **Checklist adalah SNAPSHOT.** Label dan grup ikut tersimpan di samping centangnya,
  jadi menyunting template tidak pernah menulis ulang checklist paket yang sudah diisi
  dan ditandatangani. Kunci di luar template ditolak 422.
- **Sertifikat kedaluwarsa: dua ember, bukan satu daftar.** `memenuhi` berisi yang masih
  berlaku pada tanggal lembar; `kedaluwarsa` berisi yang lewat, beserta tanggal
  lewatnya. **F/SBD mencetak ember pertama saja** — lembar yang menyatakan seorang ahli
  bersedia ditugaskan tidak boleh berdiri di atas SKK yang lewat — **tetapi jumlah ember
  kedua tercetak pada blok identitas** (`SERTIFIKAT KEDALUWARSA TIDAK DIDAFTAR`).
  Membuangnya diam-diam adalah kandidat yang kalah: tim tender akan menemukan lubangnya
  setelah kalah lelang, atau tidak sama sekali.
- **"Berlaku pada tanggal berapa" adalah tanggal LEMBAR, bukan hari ini** — aturan yang
  sama yang dipakai `PrintableDocuments` untuk SISA MASA BERLAKU kontrak layanan.
  Tanggal lembar sebuah paket tender adalah **batas pemasukan penawarannya**, karena
  itulah tanggal panitia menilai berkas yang dimasukkan; kedua lembar
  mendeklarasikannya sebagai `'date'`-nya, jadi kepala lembar dan jawabannya menyebut
  tanggal yang sama (Perlu konfirmasi #6, ditutup).
- **Alat sewa BOLEH mendukung penawaran, dan F/DA menyebutnya sewa.** P5 menjadikan alat
  sewa baris `ast_assets` yang sah; kepemilikan adalah fakta yang dapat diungkapkan,
  bukan kelemahan yang disembunyikan. Kolom `STATUS` dan `PEMILIK / LESSOR` berdiri di
  tengah tabel — persis kolom yang diperiksa panitia — dan alat milik sendiri menggarisi
  kolom lessornya.
- **Sewa yang sudah berakhir: dua ember juga, cermin aturan sertifikat lewat.** Tidak
  ada apa pun di modul Aset yang memindahkan status alat sewa ketika sewanya habis —
  barisnya tetap `available` selamanya — jadi menyaring pada status saja akan
  mendaftarkan excavator yang sudah kembali ke lessor sebagai dukungan alat dan
  menghitungnya pada `JUMLAH ALAT DIDAFTAR`. `equipment()` memisahkan `memenuhi` dan
  `kedaluwarsa` pada tanggal lembar, F/DA mencetak ember pertama saja, dan jumlah ember
  kedua tercetak pada blok identitas (`SEWA BERAKHIR TIDAK DIDAFTAR`).
- **Tidak ada tabel nominasi.** Penyusun kualifikasi menampilkan apa yang perusahaan
  **bisa** ajukan; siapa yang dinominasikan pada lelang tertentu tidak diminta pemilik
  dan tidak dibuatkan tabelnya — roster kedua yang tidak dirawat lebih buruk daripada
  tidak ada roster, karena daftar nominasi basi justru yang pertama diperiksa panitia.
- **Bobot persen paket TKDN adalah bobot BIAYA, bukan bobot HARGA.** Pasal 18 /
  Lampiran IV huruf C menimbang dengan proporsi nilai perolehan, tetapi itu aturan untuk
  **menggabungkan** dua sertifikat jadi, bukan untuk menghitung TKDN Jasa dari uraian
  biayanya. Menimbang dengan harga di sini akan menyelipkan margin ke dalam penyebut
  sebuah rasio biaya.
- **Izin pustaka metode `est.*`, bukan `core.*`** — preseden `core_locations` yang
  digerbangi `prj.*`. Yang menulis metode pelaksanaan adalah estimator/drafter yang
  menyusunnya bersama RAB; `core.*` hanya dipegang admin dan direktur, dan memakainya
  akan membuat pustaka ini praktis hanya-admin.
- **Tenggat batas pemasukan: `lead_days` 7 + `valid_through_end`.** Berkas masih boleh
  dimasukkan **pada** hari batasnya, jadi hari itu masih "menipis" dan "lewat" baru mulai
  keesokan harinya; tanpa `valid_through_end` hari terakhir — satu-satunya hari yang
  masih bisa diselamatkan — jatuh di antara dua tingkat dan tidak berbunyi sama sekali.
  `permission` `crm.create` (yang harus bertindak adalah penyusun berkasnya), dan
  `value` sengaja kosong: paket tender tidak menyimpan nilai HPS.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

| Migrasi | Isi | MySQL dengan data lama |
|---|---|---|
| `2026_08_30_000386_create_crm_tender_packages_table` | `crm_tender_packages` (header + `checklist` json + `lead_id` constrained in-module) | Tabel baru — aman |
| `2026_08_30_000387_create_crm_tender_documents_table` | `crm_tender_documents` (register dokumen lelang) | Tabel baru — aman |
| `2026_08_30_000388_create_crm_tkdn_worksheets_table` | `crm_tkdn_worksheets` (`quotation_id` **unique**, `tender_package_id` nullable) | Tabel baru — aman. **Catatan**: unique polos + SoftDeletes, lihat Deviasi baru #2 |
| `2026_08_30_000389_create_crm_tkdn_worksheet_items_table` | `crm_tkdn_worksheet_items` (kelompok biaya + kolom penentu Lampiran IV.B) | Tabel baru — aman |
| `2026_08_30_000390_create_crm_rkk_documents_table` | `crm_rkk_documents` (empat bagian Permen PUPR 10/2021; `project_id`, `boq_id` lintas modul tanpa constraint) | Tabel baru — aman |
| `2026_08_30_000391_create_crm_rkk_ibprp_links_table` | `crm_rkk_ibprp_links` (`risk_entry_id` **tanpa** constraint — `prj_risk_register` milik Projects) | Tabel baru — aman |
| `2026_08_30_000392_create_crm_rkk_smkk_costs_table` | `crm_rkk_smkk_costs` (`boq_item_id` tanpa constraint; **tanpa satu kolom rupiah pun**) | Tabel baru — aman |
| `2026_08_30_000393_add_method_library_to_crm_quotations_table` | `crm_quotations.method_library_id` nullable + index, tanpa constraint | **ADD COLUMN nullable** — aman; baris lama bernilai NULL, dan NULL berarti "tidak mengutip metode", bukan "metode hilang" |
| `2026_08_30_000191_create_core_method_library_table` (Core) | `core_method_library` (satu baris = satu versi; `superseded_by_id` in-module) | Tabel baru — aman. **Catatan**: unique `(category, work_package, version)` + SoftDeletes, lihat Deviasi baru #2 |

**Blok nomor migrasi.** Crm memakai `000386`–`000393` melanjutkan `000381`–`000385`
(preseden kenaikan 1). Core memakai `000191` karena blok `000100`–`000199` sudah habis
pada kelipatan 10 (`000190` = `core_locations`).

**Tidak ada `constrained()` lintas modul di mana pun** (kontrak §3): `project_id`,
`boq_id`, `risk_entry_id`, `boq_item_id`, `method_library_id`, dan setiap `created_by`
adalah `unsignedBigInteger` + index. Di dalam Crm dan di dalam Core, `constrained()`
dipakai seperti biasa.

**Registri dan konfigurasi yang bergerak.** `config('erp.documents')` +`TND` +`TKD`
+`RKK` +`MTD` (55 → 59) dan `SettingService::DOCUMENT_LABELS` mengikuti pada baris yang
sama — paku `DocumentFormatValidationTest` menolak mask tanpa label.
`AttachableDocuments` +`core/method-library` +`crm/tender-packages` (37 → 39).
`PrintableDocuments::crm()` +`rkk` +`daftar-personil` +`dukungan-alat` (58 → 61).
`WatchedDeadlines` +`tender_submission_deadline`. `config('erp.tender')` baru — dua kunci:
`checklist_template` dan (gelombang perbaikan) `tkdn_min_cost_to_value_pct`, keduanya
**sengaja di luar registri `SettingService`**, alasannya di `PANDUAN-ADMINISTRATOR` §4.6.

## Uji

- baru: **68** — 64 metode uji di sembilan berkas, ditambah **4 baris data-provider**
  (mask `TND`/`TKD`/`RKK`/`MTD` ikut sapuan
  `DocumentFormatValidationTest::test_every_shipped_document_format_satisfies_the_rule`).
  Empat di antaranya lahir di **gelombang perbaikan** dan ditandai ⟲ di bawah:
  - `TenderPackageTest` (8: penomoran + jangkar lead, register terurut, **lompatan
    addendum ditolak dan menyebut nomor yang bolong**, dua baris ber-addendum sama,
    checklist round-trip sebagai snapshot, menyunting template tidak menulis ulang
    checklist tersimpan, tenggat diawasi dan **hari terakhirnya masih terhitung**, kunci
    checklist di luar template ditolak);
  - `TenderPackageApiTest` (9: paket + register dalam satu simpan, lompatan addendum
    ditolak dalam Bahasa Indonesia, endpoint template, kunci asing ditolak, **endpoint
    ringkasan TKDN selalu membawa cakupannya**, daftar pustaka metode menyembunyikan
    versi digantikan, penawaran menyimpan metode dan menolak yang digantikan, endpoint
    kualifikasi baca-saja + digerbangi `crm.view`, paket dihapus tanpa baris yatim);
  - `TkdnWorksheetTest` (13: **faktor mengikuti tabel Lampiran IV**, alat LN kepemilikan
    campuran memakai proporsi saham, **persen paket berbobot biaya bukan rata-rata
    polos**, baris belum dinilai **tidak** dihitung 0%, baris belum dinilai **juga
    tidak** dihitung 100%, lembar tanpa baris tidak melaporkan persen sama sekali, baris
    tenaga kerja tanpa kewarganegaraan ditolak, alat campuran tanpa saham ditolak, baris
    penawaran milik penawaran lain ditolak; ⟲ **satu baris biaya Rp 1 tidak bisa mengaku
    lembar dinilai penuh** — dan persen barisnya sendiri tetap tercetak, ⟲ lembar yang
    barisnya benar-benar diuraikan **tetap boleh** menyatakan dirinya dinilai penuh
    (penjaga yang selalu berteriak tidak menjaga apa pun), ⟲ ambang rumah diumumkan di
    muatan dan **menaikkannya lewat config benar-benar mengubah jawabannya**, ⟲ docblock
    mengutip Pasal 17 kata demi kata dan **mempertahankan tanggal penetapan yang
    terverifikasi**);
  - `RkkDocumentTest` (8: penomoran + empat bagian Permen PUPR, tautan IBPRP dibaca dari
    register, tautan menggantung ditolak dan menyebut id-nya, baris proyek lain ditolak,
    **baris register yang dihapus tercetak bergaris, bukan lenyap**, baris SMKK menunjuk
    baris BoQ nyata dan jumlahnya turunan, baris SMKK ke BoQ yang hilang ditolak, baris
    SMKK dari BoQ lain ditolak);
  - `TenderQualificationTest` (6: **sertifikat kedaluwarsa tidak pernah menjadi
    kualifikasi dan tetap diungkapkan**, sertifikat tanpa masa berlaku memenuhi, jawaban
    per tanggal lembar bukan hari ini, daftar alat memuat milik + sewa dan menyebut yang
    mana, alat hapus-buku tidak bisa mendukung, hanya vendor subkontraktor yang masuk);
  - `TenderFormPrintTest` (4: F/RKK mencetak IBPRP hidup + rupiah SMKK turunan, RKK tanpa
    tautan mencetak kalimat kosongnya bukan nol, F/SBD membuang sertifikat lewat tetapi
    mengungkap jumlahnya, F/DA menyebut alat sewa sebagai sewa);
  - `TenderPrintCatalogueTest` (4: ketiga lembar ditawarkan pada layar yang bisa mengisi
    kepalanya, pemanggil tanpa `crm.view` tidak ditawari satu pun, F/SBD tanpa sertifikat
    berkata begitu **dan menyebut tanggalnya**, F/DA tanpa aset berkata begitu);
  - `TenderSpaWiringTest` (7: penyusun kualifikasi terjangkau dan meminta ketiga
    endpoint, pustaka metode punya entri menu, TKDN & RKK terpasang sebagai detail
    khusus, RKK menaut lewat endpoint tersendiri, **muatan SMKK yang dikirim layar persis
    yang diterima server**, layar mengucapkan "belum dinilai", ⟲ "DINILAI SEBAGIAN" dan
    "sumber tidak ditemukan" dengan lantang, pembaca tetap bisa ditolak);
  - `MethodLibraryTest` (5: versi 1 + berlaku, revisi menjadi versi 2 dan menggantikan
    pendahulunya, pustaka attachable sehingga metode membawa pptx-nya, penawaran boleh
    mengutip versi berlaku, penawaran **tidak** boleh mengutip versi digantikan).
- lama yang diubah: **4** —
  `PrintCatalogueBespokeTest` paku katalog 58 → **61** (54 registri + 7 bespoke; alasan
  di komentar uji, termasuk **mengapa lembar TKDN sengaja tidak menambah entri**);
  `DocumentFormatValidationTest::SHIPPED_DOCUMENT_TYPES` 55 → **59** (+`TND` `TKD` `RKK`
  `MTD`) — paku inilah yang menuntut keempat mask punya label di
  `SettingService::DOCUMENT_LABELS` agar dapat disunting di layar Pengaturan;
  ⟲ `TenderSpaWiringTest` (asersi baru pada uji kejujuran layar: kata `DINILAI SEBAGIAN`
  dan kolom ember ketiga pada daftar — tanpa metode uji baru);
  ⟲ `SettingServiceTest::RUNTIME_KEYS_DELIBERATELY_NOT_EDITABLE` +
  `tender.tkdn_min_cost_to_value_pct`. **Paku ini menangkap ambang cakupan baru begitu ia
  ditulis**: setiap kunci yang dibaca lewat `Erp::` wajib editable di layar Pengaturan
  **atau** terdaftar di sini beserta alasannya. Ambang ini memilih yang kedua, dan
  alasannya (*"ambang pengungkapan yang bisa diturunkan ke 0 lewat layar berhenti berarti
  cakupan"*) kini berdiri sebagai asersi, bukan sebagai komentar.
- suite penuh (lane backend): **OK (3.555 uji, 16.141 asersi, 09:37)** — dari 3.502
  pra-P7.
- suite penuh (lane cetak+SPA): **OK (3.566 uji, 16.199 asersi, 08:55)**.
- suite penuh (lane dokumentasi, dijalankan ulang atas pohon yang sama): **OK (3.566 uji,
  16.199 asersi, 09:04)** — tidak bergeser; perubahan `docs/` tidak menyentuh runtime.
- `vendor/bin/pint --test --dirty`: **passed**.

⟲ **Gelombang perbaikan, lane C (seed, label, cakupan janji, N+1) — 6 uji baru,
semuanya disaksikan merah lebih dulu** (atau, untuk paku janji yang sudah terpenuhi,
dibuktikan bisa merah dengan mematahkan janjinya sementara):

- `RkkSeederLinkageTest` **(baru, 2)**: seed segar dengan seeder asli dalam urutan asli
  menautkan RKK demo ke register IBPRP (`project_id` terisi, tautan hidup, nol
  menggantung), dan seed ulang **konvergen** pada keadaan yang sama tanpa penggandaan —
  paku kembaran `completeRkkIbprpLinks` (lihat *Untuk pemilik seed demo* #2).
- `RkkDocumentTest` +2 → 10: baris SMKK yang baris RAB-nya sudah lenyap melapor
  `amount` **NULL dan dikeluarkan dari jumlah, bukan dihitung nol** — cermin uji baris
  register terhapus di sisi IBPRP, memaku janji docblock `RkkService` yang tidak dijaga
  FK mana pun; dan **endpoint satu-dokumen menyapu `est_boq_items` SEKALI** per respons
  (show/update/sync memuat `smkkCosts.boqItem` seperti `index()`), dihitung dengan
  `DB::listen` gaya `FinanceFormPrintTest` — sebelum perbaikan: satu query per baris.
- `TenderQualificationTest` +1 → 7: `certificate_type_label` dipaku **case demi case**
  terhadap `HrPayroll\Enums\CertificateType::label()` — labelnya dieja by value di
  service (arah panah yang membuat query-nya raw juga melarang mengimpor enum-nya),
  dan paku inilah yang berbunyi bila salah satu sisi bergeser.
- `TenderFormPrintTest` +1 → 7: kolom JENIS SERTIFIKAT F/SBD mencetak **labelnya**
  (`SKK Konstruksi`), dan tidak ada sel yang tinggal berisi nilai enum mentah `skk` —
  cara yang sama F/DA menulis `Sewa`, bukan `rented`.
- suite penuh (lane C, sesudah keempat perbaikan): **OK (3.581 uji, 16.290 asersi,
  09:24)** — 3.566 + 9 (gelombang perbaikan lane A/B) + 6 (lane C).
  `vendor/bin/pint --test --dirty`: **passed**. Seed segar `migrate:fresh --seed`
  diverifikasi pada salinan scratch, bukan sqlite pengembangan.

Lane dokumentasi menambah **0** uji dan mengubah **0** uji. Setiap angka yang ditulisnya
sudah dipaku uji lebih dulu — katalog 61 oleh `PrintCatalogueBespokeTest`, mask 59 oleh
`DocumentFormatValidationTest`, keanggotaan lampiran oleh `AttachmentRegistryTest`,
entri menu oleh `NavRouteRegistryTest`. Menambahkan paku kedua untuk kalimat prosa adalah
duplikasi, bukan perlindungan.

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Terhadap basis data scratch hasil `migrate:fresh --seed` (sqlite hidup **tidak
disentuh** — md5 `57d73e0379e64f4ad9b479ebd768e375` sebelum dan sesudah), login
`admin@nusantara.test`:

- `GET /api/crm/tender-packages` → `TND/2026/VIII/0001 | Pengadaan Smart Campus
  Universitas Cendekia Nusantara (4 Gedung) | 014/PAN-PBJ/UCN/VIII/2026 | 2026-09-04 |
  addendum ke 1`.
- `GET /api/crm/tkdn-worksheets` → `TKD/2026/VIII/0001 | tkdn 72,5% | cakupan 42,86% |
  belum dinilai 5.600.000.000 | fully_assessed false`, dengan
  `basis: TKDN Jasa — Permenperin 35/2025 Pasal 14 & Lampiran IV huruf B`.
  **Angka cakupan ini berubah di gelombang perbaikan** — lihat dua baris di bawah.
- `GET /api/crm/tkdn-worksheets/1/summary` → biaya 800.000.000 (DN 580.000.000, LN
  220.000.000); satu baris penawaran **DINILAI 72,5%**, tiga baris `tkdn_pct: null` —
  **bukan 0**.
- **Sesudah perbaikan cakupan (butir *Perlu konfirmasi #1b*), dijalankan ulang atas basis
  data scratch yang sama** (sqlite hidup tetap `57d73e0379e64f4ad9b479ebd768e375` sebelum
  dan sesudah): `tkdn_pct 72,5` **tidak bergeser** — aritmetika Pasal 14 tidak disentuh —
  tetapi cakupannya kini berbunyi `coverage_pct 0,0 · assessed_value 0 ·
  partially_assessed_value 4.200.000.000 · unassessed_value 5.600.000.000 ·
  cost_to_value_pct 19,05 · min_cost_to_value_pct 50,0 · fully_assessed false`, dengan
  baris `#5 Instalasi CCTV & access control | nilai 4.200.000.000 | biaya 800.000.000 →
  sebagian (19,05%)` dan tiga baris lainnya `belum`.
- **Itu bukan kemunduran, itu temuannya.** Lembar demo semula melaporkan cakupan
  **42,86%** karena satu-satunya baris yang diuraikan dihitung "dinilai" hanya karena
  punya baris biaya — padahal biaya yang diuraikan atas baris Rp 4,2 miliar itu baru
  Rp 800 juta, **19,05%** dari nilainya. Nilai 42,86% itu mengklaim pemeriksaan yang
  tidak pernah terjadi. Konsekuensinya untuk demo dicatat di *Untuk pemilik seed demo* #4.
- `GET /api/crm/rkk-documents/1` (saat itu perlu seeder Crm dijalankan ulang; sejak
  gelombang perbaikan, seed segar langsung menautkannya — lihat *Untuk pemilik seed
  demo* #2) → `RKK/2026/VIII/0001 | project_id 1 | smkk_total 0`, tiga baris
  IBPRP hidup: `Pengecoran plat & balok lantai atas | F×A 15 | sisa 5`,
  `Pengangkatan material dengan tower crane | F×A 10 | sisa 5`,
  `Pekerjaan pembesian | F×A 6 | sisa None`.
- `GET /api/crm/tender-qualification/equipment` → `as_of 2026-08-30 | memenuhi 7 |
  kedaluwarsa 0`; **`AST-0007 Sewa | lessor PT Alat Berat Nusantara | sewa s/d
  2026-12-31`**, enam lainnya `Milik sendiri` dengan lessor kosong.
- `GET /api/crm/tender-qualification/personnel` → `{"as_of":"2026-08-30","memenuhi":[],
  "kedaluwarsa":[]}` — `hr_certificates` demo memang kosong (*Untuk pemilik seed demo*).
- `GET /api/crm/tender-qualification/subcontractors` → `VND-0004 CV Karya Sipil
  Sejahtera (sipil)`, `VND-0005 PT Mekanika Prima (me)`.
- `GET /api/core/method-library` → **2 baris** (`MTD/2026/0003 v1 elv`,
  `MTD/2026/0002 v2 struktur`); `?with_superseded=1` → **3 baris**.
- `GET /api/crm/tender-packages/checklist-template` → **21 butir**
  (`administrasi` 5 · `kualifikasi` 6 · `teknis` 6 · `harga` 4);
  `GET .../1/checklist` → **17 dari 21 tercentang**.
- `GET /api/core/deadlines` → `{"key":"tender_submission_deadline","label":"Paket
  tender","tier":"menipis","permission":"crm.create","lead_days":7,"count":1}` dengan
  `{"code":"TND/2026/VIII/0001","date":"2026-09-04","days":5,"value":null}` — `value`
  null karena paket tender tidak menyimpan HPS.
- `GET /api/core/print/forms` → **61** baris; `rkk`, `daftar-personil`, `dukungan-alat`
  termuat, dengan `resource` `crm/rkk-documents` (satu) dan `crm/tender-packages` (dua).
  Muatan katalog **tidak membawa bidang `permission` untuk entri mana pun** — itu
  disengaja, penyaringnya di server (`PrintableDocuments::catalogue()`), dan bahwa
  pemanggil tanpa `crm.view` tidak ditawari satu pun dipaku
  `TenderPrintCatalogueTest::test_a_caller_without_crm_view_is_offered_none_of_them`.
- `GET /api/core/print/forms/rkk/1` → judul `RENCANA KESELAMATAN KONSTRUKSI (RKK)`,
  `Form F/RKK`, orientasi **Lanskap**, `SUMBER REGISTER IBPRP : PRJ-2026-001 —
  Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)`, `JUMLAH BIAYA PENERAPAN SMKK :
  Rp 0,00`, dan kalimat *"RKK ini belum menaut satu pun baris biaya SMKK pada RAB."*
- `GET /api/core/print/forms/daftar-personil/1` → `Form F/SBD`, `PERSONIL PER TANGGAL :
  04 September 2026` (= batas pemasukan `TND/2026/VIII/0001`, bukan hari cetak),
  `SERTIFIKAT KEDALUWARSA TIDAK DIDAFTAR : 0`, dan *"Belum ada personil bersertifikat
  yang masih berlaku pada tanggal ini."* — dengan kepala penuh.
- `GET /api/core/print/forms/dukungan-alat/1` → `Form F/DA`, `ALAT PER TANGGAL :
  04 September 2026`, `JUMLAH ALAT DIDAFTAR : 7`, `SEWA BERAKHIR TIDAK DIDAFTAR : 0`,
  baris `AST-0007 Excavator Doosan DX225LCA (sewa) … Sewa PT Alat Berat Nusantara
  31 Desember 2026`.

**Pesan 422, kata demi kata** (dikutip dari respons, bukan dari kode):

| Endpoint & tindakan | Pesan |
|---|---|
| `PUT crm/tender-packages/{id}/documents` — register melompat | *"Register dokumen lelang melompat: addendum ke-1 belum tercatat, sementara addendum ke-3 sudah. Catat dokumen yang terlewat dahulu."* |
| `PUT crm/tender-packages/{id}/checklist` — kunci asing | *"Butir checklist \"surat_sakti\" tidak dikenali template kelengkapan paket tender."* |
| `PUT crm/tkdn-worksheets/{id}/items` — tenaga kerja tanpa kewarganegaraan | *"Baris 1: biaya tenaga kerja wajib menyebut kewarganegaraan (wni atau wna)."* |
| `PUT crm/tkdn-worksheets/{id}/items` — alat LN kepemilikan campuran tanpa saham | *"Baris 1: alat buatan luar negeri dengan kepemilikan campuran wajib menyebut proporsi saham dalam negeri (0–100)."* |
| `PUT crm/tkdn-worksheets/{id}/items` — baris milik penawaran lain | *"Baris 1: baris penawaran tidak dikenali pada penawaran lembar ini."* |
| `PUT crm/rkk-documents/{id}/ibprp-links` — tautan menggantung | *"Baris IBPRP tidak ditemukan pada register risiko proyek ini: 9001, 9002."* |
| `PUT crm/rkk-documents/{id}/smkk-costs` — baris RAB tidak ada | *"Baris 1: baris BoQ #99999 tidak ditemukan; biaya SMKK harus menunjuk baris RAB yang ada."* |
| `POST crm/quotations` — mengutip metode yang digantikan | *"Metode MTD/2026/0001 versi 1 sudah digantikan; versi yang berlaku adalah MTD/2026/0002 versi 2."* |

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- `PANDUAN-PENGGUNA.md` §1.4 — baris sidebar **Penjualan** (+Paket Tender, Lembar TKDN,
  RKK Penawaran, Penyusun Kualifikasi) dan **Estimasi** (+Pustaka Metode Kerja).
- `PANDUAN-PENGGUNA.md` §2.7 — sensus kartu Lampiran 37 → **39** (+paket tender,
  +pustaka metode kerja), dan daftar "Yang tidak bisa" kini menyebut **lembar TKDN serta
  RKK sebagai sengaja tidak berlampiran**, dengan alasannya.
- `PANDUAN-PENGGUNA.md` §3.1 — paragraf baru: bila pekerjaannya dilelangkan, langkah 3
  punya berkasnya sendiri (menunjuk §3.5a–3.5d).
- `PANDUAN-PENGGUNA.md` §3.4 — isian **Metode pelaksanaan** pada penawaran, dan kedua
  penolakannya kata demi kata.
- `PANDUAN-PENGGUNA.md` **§3.5a–3.5d (baru)** — Paket Tender (register + addendum
  kontigu + aanwijzing + lampiran + tenggat + checklist tanpa layar), Lembar TKDN
  (tabel faktor Lampiran IV.B, dialog dua langkah, aturan BELUM DINILAI, **pernyataan
  provenance rumusnya**, dan mengapa tidak ada tombol cetak), RKK Penawaran (empat
  bagian, dua pemilih, mengapa IBPRP-nya berasal dari register proyek lain, mengapa
  tidak ada kotak rupiah), Penyusun Kualifikasi (tiga bagian, kartu sertifikat
  kedaluwarsa, kartu sewa berakhir, tanggal acuan, peringatan bila tanggal acuan layar
  berbeda dengan tanggal lembar paketnya).
- `PANDUAN-PENGGUNA.md` **§4.6a (baru)** — Pustaka Metode Kerja: satu baris satu versi,
  `Terbitkan Revisi`, daftar bawaan hanya versi berlaku, lampiran menempel pada versi,
  ketiga penolakannya kata demi kata.
- `PANDUAN-PENGGUNA.md` §13.1 & §13.3 — 58 → **61**, formulir mendatar lima belas →
  **delapan belas**, blok "Penjualan — tiga lembar tender P7" dengan penjelasan F/RKK,
  F/SBD, F/DA, dan **catatan penutup bahwa tidak ada formulir rumah untuk TKDN**.
- `PANDUAN-PENGGUNA.md` §13.5 — tiga baris aturan kejujuran baru (F/RKK, F/SBD, F/DA).
- `PANDUAN-PENGGUNA.md` §14.3 — tiga baris "tidak punya layar": mencentang checklist
  paket tender, mencetak lembar TKDN, mencetak F/SBD atau F/DA untuk tanggal selain
  batas pemasukan paketnya.
- `PANDUAN-ADMINISTRATOR.md` §2 — paragraf **Core** (+`core_method_library`, mengapa di
  Core, mengapa `est.*`, satu baris satu versi) dan paragraf **Crm** (+empat entitas P7
  dan empat catatan arsitektural: bukan `Approvable`, tidak ada panah dependensi baru,
  tidak ada rupiah kedua, tidak ada tabel nominasi); hitungan cetak 58 → **61**.
- `PANDUAN-ADMINISTRATOR.md` §4.6 — kendali **Penomoran Dokumen** 55 → **59**, dan blok
  baru `tender.checklist_template` di daftar "sengaja TIDAK ada di layar", termasuk dua
  konsekuensi menyuntingnya (snapshot tidak ditulis ulang; membuang butir membuat
  pengiriman berikutnya ditolak).
- `PANDUAN-ADMINISTRATOR.md` §4.8 — 55 → **59**, dengan keempat mask P7 disebut.
- `PANDUAN-ADMINISTRATOR.md` §9.1 & §9.3 — 58 → **61**, `crm.view` 4 → **7**, mendatar
  lima belas → **delapan belas** (+F/RKK, F/SBD, F/DA).
- `PANDUAN-ADMINISTRATOR.md` **§12(e) (baru)** — provenance rumus TKDN (peraturan,
  pasal, URL yang dibaca; mengapa bukan rumus 2011/2013), empat hal yang belum dijawab,
  **dan tabel "bagaimana mengubah rumusnya"**: `TkdnService::domesticFactor()` /
  `toolFactor()`, keempat enum `Tkdn*` beserta cerminnya di `enums.js`, kunci `basis`
  pada `summary()` — beserta kedua uji yang akan merah begitu satu faktor bergeser.
  Paragraf pembuka §12 diperbarui: dua keputusan menunggu, bukan satu.
- README Modules: tidak ada modul baru — tidak ada baris baru.
- CONVENTIONS/ARCHITECTURE: **tidak diubah**. Tidak ada panah dependensi baru — Crm →
  Core dan Crm → Estimation sudah ada; keempat pembacaan lain (Projects, HrPayroll,
  Assets, Procurement) mentah di balik `Schema::hasTable`, yang secara sengaja **bukan**
  dependensi kode (preseden `BastPrerequisiteService`). Keputusannya ditulis di docblock
  `RkkService`, `TenderQualificationService`, dan laporan ini.

## Yang sengaja tidak dikerjakan, dan mengapa

- **TKDN gabungan Barang dan Jasa** — butuh nilai TKDN tiap Barang dari sertifikatnya;
  tidak ada tabelnya, dan kolom yang boleh diketik adalah mesin pemalsu (*Perlu
  konfirmasi #2*).
- **Penelusuran TKDN tingkat 2 dan 3 (Pasal 15)** — lembar menerima uraian biaya datar;
  rantai pasok di balik satu baris tidak ditelusuri (*Perlu konfirmasi #3b*).
- **Pemeriksaan kesesuaian KBLI (Pasal 16)** — sistem tidak menyimpan KBLI perusahaan.
- **Ambang TKDN minimum** — sumbernya tidak dibaca; ambang tanpa sumber adalah angka
  karangan (*Perlu konfirmasi #3*).
- **Lembar cetak TKDN** — formulir Kemenperin, bukan formulir rumah (*Perlu konfirmasi
  #5*).
- **Pemilih tanggal bebas pada F/SBD / F/DA saat mencetak** — tanggal lembarnya adalah
  batas pemasukan paketnya (*Perlu konfirmasi #6*, ditutup); parameter `?tanggal=` hanya
  berlaku untuk paket yang belum mencatat batas pemasukan.
- **Layar checklist kelengkapan** — API, template, snapshot, dan seed demo ada; kartunya
  belum digambar (Deviasi baru #1).
- **`Approvable` untuk ketiga dokumen P7** — maker-checker hidup di penawarannya.
- **Tabel nominasi personil/alat per lelang** — tidak diminta pemilik; roster kedua yang
  basi lebih buruk daripada tidak ada.
- **Komponen biaya SMKK pada `est_ahsp`, dan seksi "Pekerjaan Penerapan SMKK" pada RAB
  demo** — milik Estimation. Seeder P7 sengaja **tidak** menautkan baris "Pekerjaan
  Persiapan" agar demo terlihat penuh: itu akan menyatakan bahwa baris itu adalah biaya
  keselamatan, yang tidak kita ketahui.
- **Surat Pernyataan & Pakta Integritas sebagai dokumen** — checklist menyebutnya,
  dokumennya bukan lingkup P7.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **Checklist kelengkapan paket tender tidak punya satu pun layar.** Templatnya (21
   butir), snapshot-nya, keempat endpoint-nya, dan seed demo (17/21 tercentang) semuanya
   ada dan teruji; `TenderPackageResource` bahkan mengembalikan `checklist`. Tetapi
   `RESOURCES['crm/tender-packages']` tidak punya `customDetail` dan detail generiknya
   hanya menggambar tabel `documents` — **tidak ada kartu yang menampilkan atau
   mencentang checklist**. Konsekuensinya butir yang paling sering ditanyakan panitia
   hanya bisa diisi lewat panggilan API. Dicatat di PANDUAN §3.5a dan §14.3; tidak
   diperbaiki lane ini (lane dokumentasi hanya menyentuh `docs/`).
2. **Menghapus lembar TKDN atau entri pustaka metode membuat penggantinya tidak bisa
   dibuat — dan gagalnya 500, bukan 422.** `crm_tkdn_worksheets.quotation_id` UNIQUE
   polos dan `core_method_library` UNIQUE `(category, work_package, version)` polos,
   sementara **kedua modelnya memakai SoftDeletes**: baris nisan tetap memegang indeks,
   sedangkan penjaga di service (`TkdnService::createWorksheet`,
   `MethodLibraryService::current()`) bertanya pada himpunan yang tidak-terhapus dan
   tidak melihatnya. Direproduksi pada scratch:
   `DELETE crm/tkdn-worksheets/1` → 200 *"Lembar TKDN dihapus."*, lalu
   `POST crm/tkdn-worksheets {"quotation_id":2}` →
   `SQLSTATE[23000] … UNIQUE constraint failed: crm_tkdn_worksheets.quotation_id`;
   dan `DELETE core/method-library/3` → 200, lalu membuat entri dengan kategori + paket
   yang sama → `UNIQUE constraint failed: core_method_library.category,
   core_method_library.work_package, core_method_library.version`. **Presedennya ada di
   repo**: migrasi P6 `000742` memakai indeks unik **parsial** atas baris hidup
   (`WHERE deleted_at IS NULL`). Tidak diperbaiki lane ini — perbaikannya migrasi, bukan
   dokumen.
3. **Pesan 422 dari `Rule::exists` / `Rule::enum` masih berbahasa Inggris — dan itu
   berlaku sekeliling repo, bukan hanya P7.** `PUT crm/tkdn-worksheets/1/items` dengan
   `quotation_item_id` yang **tidak ada** menjawab *"The selected
   items.0.quotation_item_id is invalid."*, sementara id yang **ada tetapi milik
   penawaran lain** menjawab Bahasa Indonesia dari service. Bukan regresi P7:
   `POST crm/quotations` dengan `customer_id` 9999 (jalur pra-P7) menjawab *"The selected
   customer id is invalid."* dengan cara yang sama. Tidak ada berkas bahasa `lang/id` di
   repo. Aturan §3 ("pesan 422 dalam Bahasa Indonesia") karenanya dipenuhi oleh setiap
   aturan yang ditulis service, dan **tidak** oleh aturan bentuk bawaan Laravel. Satu
   berkas `lang/id/validation.php` akan menutupnya untuk seluruh repo sekaligus; di luar
   lingkup P7.
4. ~~**Docblock `TkdnService` mengutip tanggal penetapan yang tidak dapat
   diverifikasi.**~~ **DICORET — deviasi ini tidak pernah ada.** Tanggal *"Ditetapkan di
   Jakarta pada tanggal 11 September 2025"* terbaca utuh pada halaman tanda tangan PDF
   resminya; yang gagal adalah ekstraksi teks lane dokumentasi, bukan peraturannya. Lihat
   *Perlu konfirmasi pemilik #1* di atas untuk keluaran `pdftotext -layout`-nya. Docblock
   dipertahankan dan diperkuat dengan catatan cara memperolehnya; **tidak ada yang
   dihapus**. Dicatat di sini apa adanya — sebuah temuan yang salah yang sempat berdiri
   di laporan adalah hal yang perlu dilihat pembaca berikutnya, bukan dihapus tanpa
   jejak.
5. **Satu dugaan yang ditarik kembali, dicatat supaya tidak dicari orang lain.** Dump
   mentah `GET /api/core/print/forms` memperlihatkan `permission` kosong pada ketiga
   entri baru, yang sempat terbaca seperti gerbang yang tidak terpasang. Ia bukan:
   `PrintableDocuments::catalogue()` **tidak pernah** menyertakan bidang `permission`
   pada entri mana pun — bespoke maupun registri — karena penyaringannya terjadi di
   server sebelum baris itu dibuat. Bukan deviasi, bukan temuan P7; dicatat karena
   dump katalog adalah hal yang wajar dibaca orang saat menelusuri tombol cetak yang
   hilang.

## Untuk pemilik seed demo (tiga celah yang bukan milik lane mana pun di P7)

1. **`hr_certificates` kosong** (8 karyawan, 0 sertifikat), jadi F/SBD pada demo
   mencetak kalimat kosongnya. Dua baris SKK di seeder HrPayroll — **satu masih berlaku,
   satu sudah lewat** — akan membuat aturan sertifikat kedaluwarsa terlihat di layar dan
   di kertas. Uji `TenderPrintCatalogueTest`
   `::test_the_personnel_sheet_with_no_certificates_says_so_and_names_its_date` sudah
   memaku bahwa lembar kosongnya jujur; ia tidak mengisi demonya.
2. **~~Tautan IBPRP RKK demo kosong setelah `migrate:fresh --seed`~~ DITUTUP pada
   gelombang perbaikan** — dengan tepat perbaikan bersih yang dicatat di sini:
   `ProjectsDatabaseSeeder::completeRkkIbprpLinks` (kembaran pola AST-0007, terdokumen
   di kedua seeder) menjalankan pemilihan yang huruf demi huruf sama dengan blok IBPRP
   `CrmDatabaseSeeder::seedRkk` dan menulis lewat `RkkService::syncIbprpLinks` yang
   sama, segera setelah register risikonya diseed — sehingga seed segar dalam urutan
   asli (Crm posisi 3, Projects posisi 7) kini mendaratkan `project_id 1` + 3 tautan
   IBPRP, dan `db:seed` kedua konvergen tanpa penggandaan. **Diverifikasi dengan
   `migrate:fresh --seed` sungguhan pada salinan scratch** (tautan mendarat; seed ulang
   konvergen) dan dipaku `RkkSeederLinkageTest` (2 uji, di-seed dengan seeder asli
   dalam urutan asli, gaya `ProjectsSeederP3Test`).
   `database/seeders/DatabaseSeeder.php` tidak disentuh (§0 batas 3).
3. **RAB demo tidak punya seksi "Pekerjaan Penerapan SMKK"**, jadi tidak ada baris biaya
   yang diseed dan F/RKK mencetak kalimat kosongnya. Menambahkan seksinya adalah
   pekerjaan seeder Estimation.
4. **Lembar TKDN demo tidak punya satu pun baris berstatus `penuh`** (baru: gelombang
   perbaikan). Satu-satunya baris yang diuraikan, `#5` senilai Rp 4,2 miliar, dibekali
   uraian biaya Rp 800 juta — **19,05%** dari nilainya — jadi ia `sebagian`, dan
   `coverage_pct` demo berbunyi **0,00%**. Angkanya benar dan pengungkapannya lengkap
   (`partially_assessed_value` Rp 4,2 miliar berdiri di kartu rekap dengan kalimatnya),
   **tetapi demonya jadi tidak pernah memperlihatkan seperti apa baris yang dinilai
   penuh** — ketiga keadaan seharusnya terlihat sekaligus. **Seeder sengaja TIDAK
   disentuh gelombang ini**: menaikkan rupiah demo agar lembarnya terlihat bagus adalah
   persis kebiasaan yang cacat ini lahir darinya, dan angka-angka itu dikutip lane lain
   di laporan ini. Perbaikan bersihnya milik pemilik seed: naikkan uraian biaya baris
   `#5` sampai melewati ambang (mis. menambah baris material/subkontrak dalam negeri
   hingga ± Rp 2,5 miliar) **dan** biarkan satu baris lain tetap bernilai kecil, supaya
   `penuh`, `sebagian`, dan `belum` ketiganya tampil pada satu layar.
