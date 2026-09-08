# LAPORAN PAKET HM F-6 — Reorder → usulan PR, label & pindai barcode

Fase 2, baris F-6 ROADMAP-HASHMICRO (5 hari-orang). Branch `feat/phase2-f6`, dari `main` 2e49979.
Tidak di-merge, tidak di-deploy. 8 September 2026.

---

## 1. Tugas → status → bukti

| # | Tugas (dari perintah paket) | Status | Bukti terukur |
|---|---|---|---|
| 1 | `inv_reorder_rules` per item × gudang, **blok lanjutan Inventory didaftarkan di CONVENTIONS §2 pada commit yang sama** | SELESAI | `b56fb70`. Migrasi `2026_09_08_001700_create_inv_reorder_rules_table.php`; baris `Inventory │ 000400–000499 │ **001700–001799** │ DIPAKAI` di tabel "Blok lanjutan" §2. `ReorderRuleSchemaTest` **5 uji / 17 pernyataan**, termasuk uji yang membaca CONVENTIONS.md dan menuntut barisnya ada |
| 2 | Definisi "perlu dipesan ulang" yang menghormati aturan **di atas** `min_stock`, di `StockService` DAN salinan `ModuleCounts`, dengan uji kesetaraan **DIPERKUAT** | SELESAI | `98a3e07`. `ReorderThresholdTest` **9 / 29**; `ModuleCountsTest` **20 / 173** (dari 18 uji sebelum paket ini) dengan 5 baris fixture baru dan 2 uji baru. Angka entri `inv` di fixture: **1 → 4** |
| 3 | API aturan reorder (CRUD, izin `inv.*`) + layar/daftar di SPA | SELESAI | `baaad56`. `ReorderRuleApiTest` **9 / 23**. 5 rute `inventory/reorder-rules`, `RESOURCES['inventory/reorder-rules']`, entri NAV "Aturan Reorder" |
| 4 | Usulan PR yang MEMBACA kekurangan dan membuat PR **DRAF** lewat `PurchaseRequisitionService`; **idempoten**; tidak pernah mengajukan/menyetujui | SELESAI | `33504ea`. `ReorderProposalTest` **12 / 58**, termasuk uji yang memaku awalan rute `reorder/` hanya punya 2 rute dan tidak satu pun bernama `submit`/`approve` |
| 5 | Code 128 sebagai SVG di PHP dengan **DEKODER di uji**; formulir cetak F/LBL di registri rumah | SELESAI | `db55d4d`. `Code128Test` **35 / 5.782** (23 perjalanan bolak-balik + tabel simbol + zona tenang); `LabelBarcodePrintTest` **8 / 27**. Katalog cetak **64 → 65** baris |
| 6 | Layar pindai `BarcodeDetector` + jalur manual + tiga (jadinya **empat**) kalimat keadaan; barcode duplikat | SELESAI | `ff2881e`. `ItemScanTest` **10 / 37**. Empat keadaan kamera + keadaan kelima ("belum ketemu") diukur harness S33k |
| 7 | Uji PHP untuk setiap perubahan server + **mutasi yang dipaku merah** | SELESAI | **88 uji / 5.973 pernyataan** pada tujuh berkas uji baru; **21 mutasi** dijalankan, 20 merah + 1 yang **lolos hijau dan memaksa ujinya diperbaiki** (§4) |
| 8 | Harness S32 + S33 (desktop + ponsel) → `results-phase-2.json` + PNG | SELESAI | `2090af9`. **5 skenario, 55 syarat, semuanya hijau**. `results-phase-2.json` **18 → 23 kunci** (digabung per kunci; tidak satu pun skenario lain tersentuh). **17 PNG** |
| 9 | Berkas cangkang PWA baru → tambahkan ke `SHELL` dan naikkan `SHELL_VERSION` | SELESAI | `js/views/reorder.js` + `js/views/pindai.js` masuk `SHELL`; `SHELL_VERSION` **4 → 5**. `PwaServiceWorkerTest` hijau (cocok dua arah dengan berkas yang benar-benar ada) |
| 10 | MUAT `/app/` DI CHROMIUM, 0 galat konsol pada setiap layar yang disentuh | SELESAI | **0 galat konsol** dan **0 permintaan gagal** pada boot + 7 rute + tab "Perlu dipesan ulang". Chromium headless (Playwright) di atas `php -S 127.0.0.1:8161` melayani SALINAN sqlite |
| 11 | `docs/LAPORAN-PAKET-HM-F-6.md` | SELESAI | Berkas ini |

### Commit

| SHA | Judul |
|---|---|
| `b56fb70` | inventory: tabel `inv_reorder_rules` — ambang per gudang, karena satu angka perusahaan tidak bisa melayani dua gudang |
| `98a3e07` | inventory: "perlu dipesan ulang" menghormati aturan gudang di atas `min_stock` — di KEDUA salinan kuerinya, dan di keempat permukaannya |
| `baaad56` | inventory: layar & API aturan reorder — pasangan kedua ditolak sebagai kalimat, bukan sebagai 500 |
| `33504ea` | inventory: usulan PR dari kekurangan stok — draf, idempoten, dan aturan pelewatannya tertulis |
| `db55d4d` | core: Code 128 sebagai SVG tanpa dependensi + lembar label F/LBL — dibuktikan dengan DEKODER, bukan dengan menghitung batang |
| `ff2881e` | inventory: pindai barcode — jalur ketik selalu ada, empat keadaan kamera punya empat kalimat, barcode ganda tidak pernah dipilihkan |
| `2090af9` | bukti: harness S32/S33 (desktop + ponsel) — dan DUA cacat yang hanya terlihat di peramban |
| `edc747a` | docs: CONVENTIONS §31–§34 dan LAPORAN-PAKET-HM-F-6 — termasuk mutasi yang LOLOS hijau |
| `59bd642` | docs: panduan pengguna & enam berkas onboarding — kalimat yang menjadi SALAH begitu ambangnya berubah |

CONVENTIONS §2 (blok lanjutan) didaftarkan pada `b56fb70`, yaitu commit pemakaian pertamanya —
aturan §2 sendiri. §31–§34 pada `edc747a`.

---

## 2. Perangkap paket ini — apa yang dilakukan terhadap masing-masing

### A. Definisi "di bawah minimum" hidup di DUA tempat

Diterapkan pada **keduanya**, dan uji kesetaraannya **diperkuat, bukan dilemahkan**.

Yang dilakukan lebih dari sekadar menyalin: sampai F-6, `ModuleCountsTest::test_the_low_stock_count_equals_the_stock_screens_own_query` membandingkan dua kueri yang **sama-sama bisa melupakan tabel aturan dan tetap hijau**. Fixture `inv` karena itu mendapat **lima baris yang jawabannya berbeda** antara "dengan aturan" dan "hanya `min_stock`" — aturan yang menaikkan, yang menurunkan, yang nonaktif, yang bertitik 0, dan yang milik gudang lain — dan sebuah **uji kedua** menjalankan kueri PRA-F-6 kata demi kata lalu menuntut jawabannya BERBEDA. Kalau fixture itu suatu hari kehilangan kemampuan membedakan, ia jatuh dengan menyebut sebabnya alih-alih diam-diam berhenti menjaga apa pun.

Angka terukur pada fixture registri: **min_stock saja = 5, dengan aturan = 4**.

**Empat permukaan, bukan satu.** Layanan, salinan registri (label ubin berubah menjadi "Item di bawah titik pesan ulang", beserta cerminnya di `schema.js MODULES.kpi` yang dipaku uji), tab "Perlu dipesan ulang" di layar Saldo Stok, dan widget dasbor `stok-minimum` (judul + keterangan + urutan, yang dulu mengurutkan menurut `qty − min_stock`, angka yang tidak dipakai satu pun baris beraturan).

### B. Code 128 mudah SALAH tanpa terlihat salah

Ujinya memuat **dekoder**: ia membaca `<rect>` dari SVG yang benar-benar dihasilkan produksi,
menyusun ulang deret lebar **batang DAN spasi**, memetakannya kembali ke nilai simbol, **menghitung
ulang digit periksa mod-103 dari nol**, dan mengembalikan teks. **23 perjalanan bolak-balik**: kode
item nyata, angka genap dan ganjil (peralihan set C), spasi, kedua batas set B, tanda baca, EAN-13,
modul yang bukan bawaan, dan tiga baris yang khusus menyerang pasangan `99`/`00` di set C.

Dekoder itu **menemukan sesuatu pada percobaan pertama**: di set C nilai 99 adalah pasangan angka
"99", sementara "pindah ke set C" hanya berarti itu di set A/B. Dekoder pertama membaca `ITM-9999`
sebagai `ITM-`. Encoder-nya benar; ujinyalah yang salah — dan tanpa dekoder tidak ada yang akan
pernah mengetahui perbedaannya.

Tabel `PATTERNS` sendiri diperiksa terhadap sifat yang harus dipenuhi tabel Code 128 mana pun
(107 simbol, 11 modul, 13 untuk stop, elemen 1–4 modul, semuanya unik), karena encoder dan dekoder
membaca tabel yang **sama**.

**Zona tenang dan teks terbaca-manusia**: keduanya ada dan keduanya diuji; lihat §4 untuk mutasi
zona tenang yang lolos hijau dan apa yang dilakukan terhadapnya.

### C. `BarcodeDetector` tidak ada di iOS Safari

Jalur ketik adalah **isian pertama** di layar — aktif, terfokus, 46 px, huruf 16 px. Keberadaan
detektor diperiksa lewat `typeof globalThis.BarcodeDetector`, bukan dengan menyentuh namanya.

**Empat keadaan, empat kalimat**, diukur satu per satu oleh S33k dengan memalsukan tepat satu hal
per konteks: peramban tanpa detektor · halaman bukan konteks aman · izin ditolak · tidak ada kamera.
Ditambah keadaan kelima yang **bukan galat**: menyala dan belum menemukan apa pun, yang sesudah
6 detik berubah menjadi saran jarak dan cahaya sementara pemindaiannya jalan terus.

### D. Idempotensi usulan PR

Aturannya **dinyatakan**: item dilewati bila sudah menjadi baris pada PR terbuka
(`draft`/`submitted`/`approved`, belum dibuang) untuk gudang yang sama **atau** pada PR yang tidak
menyebut gudang sama sekali. `rejected`/`closed`/`cancelled` **bukan** terbuka.

Dipaku uji PHP (`ReorderProposalTest`) **dan** di peramban (S32): tekan tombolnya, muat ulang, dan
baris yang tadinya "Akan diusulkan" sekarang berbunyi "Dilewati — Sudah diminta pada
PR/2026/IX/0004 (Draf)". Endpoint-nya lalu dipanggil **langsung** sesudah tombolnya hilang dari
layar: layar yang menyembunyikan tombol bukan gerbang, dan yang harus menolak permintaan kedua
adalah servernya (`created: []`).

### E. Barcode duplikat

Server **tidak pernah** memilih yang pertama: ia memulangkan semua yang cocok dengan
`status: 'ambiguous'` dan kalimat yang menyebut jumlahnya serta akibatnya. Layar menampilkan kedua
kartunya dengan peringatan di atasnya. Diukur di peramban (S33): dua kartu item, pesan
`2 ITEM memakai kode yang sama ("F6PROBE9001")…`.

Kolomnya **tidak** dijadikan UNIQUE oleh paket ini — itu keputusan pemilik, §5 butir 1.

---

## 3. Angka yang benar-benar dijalankan

### Uji PHP (SQLite, `vendor/bin/phpunit`)

| Berkas uji | Uji | Pernyataan |
|---|---|---|
| `tests/Feature/Inventory/ReorderRuleSchemaTest.php` | 5 | 17 |
| `tests/Feature/Inventory/ReorderThresholdTest.php` | 9 | 29 |
| `tests/Feature/Inventory/ReorderRuleApiTest.php` | 9 | 23 |
| `tests/Feature/Inventory/ReorderProposalTest.php` | 12 | 58 |
| `tests/Feature/Inventory/Code128Test.php` | 35 | 5.782 |
| `tests/Feature/Inventory/LabelBarcodePrintTest.php` | 8 | 27 |
| `tests/Feature/Inventory/ItemScanTest.php` | 10 | 37 |
| **Tujuh berkas baru, satu proses** | **88** | **5.973** |

Uji yang sudah ada dan diubah: `tests/Feature/Core/ModuleCountsTest.php` (18 → **20 uji**,
163 → **173 pernyataan**), `tests/Feature/Core/PrintCatalogueBespokeTest.php` (3 uji; hitungan
katalog 64 → 65, dan satu uji izin diperkuat — lihat §4).

Gerbang per-direktori, keadaan akhir:

- `vendor/bin/phpunit tests/Feature/Inventory tests/Feature/Procurement` → **OK (563 uji, 8.385 pernyataan)**, 01:37
- `vendor/bin/phpunit tests/Feature/Core` → **OK (968 uji, 8.818 pernyataan), 11 dilewati**, 03:27

Gerbang rilis penuh (dan MySQL) **tidak** dijalankan di sini — ia dijalankan terpisah.

### `vendor/bin/pint --test`

Lolos pada setiap berkas yang disentuh paket ini. **Kegagalan lama yang tersisa ada ENAM, bukan
dua** — angka itu salah sejak versi pertama laporan ini, dan pembacanya yang menjalankan `pint
--test` sebagai gerbang tidak punya cara membedakan "yang memang sudah merah di `main`" dari "yang
dibawa paket ini", yaitu persis pekerjaan yang kalimat itu seharusnya selesaikan. Diukur
(putaran perbaikan, 8 Sep 2026):

```
{"tool":"pint","result":"fail","files":[
  tests/Feature/Core/ChartMigrationTest.php,
  Modules/Core/Services/FormXlsxExportService.php,
  database/factories/UserFactory.php,
  database/seeders/ProductionSeeder.php,
  bootstrap/providers.php,
  bootstrap/app.php ]}
```

`git diff --stat main...HEAD` atas keenam berkas itu **kosong**: 6 kegagalan lama, 0 di antaranya
disentuh paket ini.

### Bukti peramban (Chromium headless, Playwright)

`php -S 127.0.0.1:8161 -t public` melayani **salinan** `database/database.sqlite` di scratchpad
(`DB_DATABASE=<salinan> php artisan migrate --force`; migrasi 001700 tercatat DONE). Server dimatikan
berdasarkan PID.

| Layar | hash | h1 | Galat konsol | Permintaan gagal |
|---|---|---|---|---|
| boot (masuk + nav) | — | — | 0 | 0 |
| Beranda | `#/home` | Beranda | 0 | 0 |
| Dasbor (widget stok) | `#/dashboard` | Selamat sore, … | 0 | 0 |
| Saldo Stok | `#/stock` | Saldo Stok | 0 | 0 |
| Aturan Reorder | `#/r/inventory/reorder-rules` | Aturan Reorder | 0 | 0 |
| Usulan Pesan Ulang | `#/usulan-pesan-ulang` | Usulan Pesan Ulang | 0 | 0 |
| Pindai Barcode | `#/pindai` | Pindai Barcode Item | 0 | 0 |
| Item | `#/r/inventory/items` | Item | 0 | 0 |
| tab "Perlu dipesan ulang" | `#/stock` | — | 0 | 0 |

**TOTAL_CONSOLE_ERRORS = 0.**

Baris yang benar-benar terbaca di tab itu (bukti bahwa prioritasnya tertulis):

```
Semen Portland 50kg ITM-0001 · Gudang Site Proyek Graha Sentosa · 350 zak ·
  400 (Aturan reorder gudang ini · stok min. item 200) · kurang 50 · 500 zak (jumlah pesan aturan)
```

### Harness (S32/S33)

`ERP_BASE=http://127.0.0.1:8161`, `ERP_DB=<salinan>`, `UXTEST_OUT=<scratchpad>`; hasil digabung ke
`docs/bukti-uji/results-phase-2.json` **berdasarkan kunci** (18 → 23; tidak satu pun dari 18 kunci
lama tersentuh).

| Skenario | Viewport | Syarat | Hasil | ms |
|---|---|---|---|---|
| `S32_reorder_usulan_pr` | 1440×900 | 15 | ok | 11.203 |
| `S32_reorder_usulan_pr_mobile` | 390×844 | 7 | ok | 5.215 |
| `S33_label_dan_pindai` | 1440×900 | 15 | ok | 14.189 |
| `S33_pindai_keadaan_kamera` | 1440×900 (5 konteks) | 10 | ok | 37.039 |
| `S33_label_dan_pindai_mobile` | 390×844 | 8 | ok | 6.252 |
| **Total** | | **55** | **semuanya hijau** | |

Angka pilihan dari `results-phase-2.json`:

- lembar F/LBL yang disuntikkan ke dokumen: **34 batang, 34 di antaranya berlebar bukan-nol**,
  SVG 286×55 px, teks terbaca-manusia `ITM-0001 · 8991002123458`, `aria-label="Barcode 8991002123458"`,
  **6 stiker** untuk `?jumlah=6`;
- barcode ganda: **2 kartu item**, pesan `2 ITEM memakai kode yang sama ("F6PROBE9001")…`;
- ponsel: isian ketik **236 × 46 px**, tombol **46 px**, halaman tidak menggulir mendatar,
  tabel menggulir di dalam kotaknya sendiri.

17 PNG di `docs/bukti-uji/` (`s32-*.png`, `s33-*.png`), termasuk empat tangkapan keadaan kamera.

---

## 4. Mutasi — 21 dijalankan, dan yang satu yang LOLOS

Setiap mutasi: ubah satu konstanta/operator/baris di kode **produksi**, jalankan ujinya, kembalikan.

| # | Mutasi | Berkas | Uji merah |
|---|---|---|---|
| 1 | `UNIQUE(warehouse_id, item_id)` dibuang | migrasi 001700 | 1 |
| 2 | `decimal(15,3)` → `(15,2)` | migrasi 001700 | 1 |
| 3 | `is_active` dipindah dari klausa ON ke WHERE | `StockService` | 8 |
| 4 | syarat `> 0` diperiksa pada `min_stock`, bukan pada ambang yang menang | `StockService` | 5 |
| 5 | salinan `ModuleCounts` dikembalikan ke kueri `min_stock` saja | `ModuleCounts` | 4 |
| 6 | `shortage_qty` dihitung dari `min_stock` | `StockService` | 4 |
| 7 | `suggested_qty` mengabaikan `reorder_qty` aturan | `StockService` | 2 |
| 8 | `Rule::unique` dibuang dari store | `ReorderRuleStoreRequest` | 1 (422 → 500) |
| 9 | `->ignore()` dibuang dari update | `ReorderRuleUpdateRequest` | 1 |
| 10 | idempotensi dimatikan | `ReorderService` | 3 |
| 11 | PR **ditolak** ikut dianggap terbuka | `ReorderService` | 1 |
| 12 | gudang diabaikan saat mencocokkan PR terbuka | `ReorderService` | 1 |
| 13 | PR dibuat langsung berstatus **Diajukan** | `PurchaseRequisitionService` | 2 |
| 14 | satu pola simbol digeser satu modul | `Code128` | 1 |
| 15 | modulus digit periksa 103 → 101 | `Code128` | 26 |
| 16 | bobot posisi digit periksa digeser satu | `Code128` | 26 |
| 17 | **zona tenang 10 → 0** | `Code128` | **0 — LOLOS HIJAU** (lihat di bawah), lalu **1** |
| 18 | `supports()` menerima segalanya | `Code128` | 3 |
| 19 | pemindaian memilihkan yang pertama | `ItemScanController` | 2 |
| 20 | pencocokan sebagian (`like`) alih-alih persis | `ItemScanController` | 1 |
| 21 | rute `items/scan` dipindah ke bawah `items/{item}` | `Routes/api.php` | 10 |

### Mutasi 17: uji yang mengukur dirinya sendiri

Versi pertama uji zona tenang membandingkan lebar yang terukur dengan
`Code128::QUIET_MODULES * $module`. Menyetel konstanta itu ke **0** — yang **membuang seluruh zona
tenang dan membuat setiap pemindai gagal DIAM-DIAM** — membuat harapannya ikut menjadi 0, dan uji
itu **lolos hijau**. Ujinya sekarang memaku **angka 10** (minimum spesifikasi Code 128) dan
sekaligus memaku konstantanya sama dengan 10. Sesudah perbaikan, mutasi yang sama merah.

Ini persisnya jenis cacat yang perintah paket sebut: sebuah uji yang lolos untuk setiap masukan
yang mungkin tidak menjamin apa pun.

---

## 5. Keputusan pemilik

1. **`inv_items.barcode` unik atau tidak?** Hari ini nullable dan **tidak** unik, dan paket ini
   **sengaja tidak mengubahnya**: data produksi mungkin sudah memuat duplikat, sehingga migrasi
   yang menambahkan UNIQUE akan **gagal saat deploy** alih-alih memberi tahu siapa pun. Yang
   dikerjakan paket ini adalah membuat duplikatnya **terlihat** (pemindaian memulangkan semuanya,
   layar meminta orangnya memilih). Yang dibutuhkan sebelum memutuskan: satu audit
   `SELECT barcode, COUNT(*) … GROUP BY barcode HAVING COUNT(*) > 1` di produksi.

2. **Rentang blok migrasi Inventory 001700–001799 belum ada di ledger.** ROADMAP-HASHMICRO §5
   baris 5 menyebut Core, Finance dan Projects saja. Rentangnya ditetapkan di tabel CONVENTIONS §2
   karena aturan §2 sendiri menuntut penetapannya pada commit pemakaian pertama dan F-6
   membutuhkannya; barisnya menyatakan bahwa ia **usulan yang menunggu pengesahan**, bukan
   pengganti ledger.

3. **PR terbuka yang TIDAK menyebut gudang menahan usulan.** Ini pilihan ke arah yang lebih sepi:
   kadang tidak mengusulkan sesuatu yang benar-benar kurang (terlihat di layar, dengan kode PR-nya)
   ketimbang kadang memesan barang yang sudah dipesan (baru terlihat saat barangnya datang dua
   kali). Pemilik boleh membalikkannya; aturannya ada di satu tempat
   (`ReorderService::blockingRequisition`) dan kalimatnya di satu tempat lain (`why_skipped`).

4. **Jumlah stiker bawaan 12, plafon 60.** Dipilih untuk kisi 3 × 4 pada A4. Kalau kertas label
   pemilik punya kisi lain, angkanya perlu diganti — satu konstanta di `labelBarcode()` dan satu
   batas validasi di `FormPrintController`.

5. **Aturan reorder belum bisa diimpor massal.** Menetapkan ambang untuk 8 gudang × 400 item lewat
   formulir satu per satu tidak akan pernah terjadi. `ImportableResources` sudah punya jalurnya;
   menambah satu resource ke sana adalah paket kecil tersendiri, dan ia menyentuh registri yang
   dipakai lane lain.

6. **`WatchedThresholds` (F-2) tidak diberi entri reorder.** Ambang stok sekarang punya rumahnya
   sendiri (`inv_reorder_rules`), dan mendaftarkannya juga di registri ambang akan menciptakan
   **salinan kedua sebuah aturan** — penyimpangan yang paling mahal menurut CONVENTIONS §16
   sendiri. Kalau pemilik ingin notifikasi "stok di bawah titik pesan ulang", jalurnya adalah
   pemasok `WatchedThresholds` yang **membaca** `lowStockAlerts()`, bukan ambang kedua.

7. **Biaya kueri.** CONVENTIONS §16 sudah mencatat bahwa entri `inv` adalah satu-satunya pemindaian
   tabel penuh yang tersisa (`b.qty < i.min_stock` — perbandingan antar kolom dua tabel yang tidak
   bisa dilayani indeks). F-6 menambahkan satu LEFT JOIN ke `inv_reorder_rules` (berindeks unik
   pada pasangannya, jadi join-nya sendiri murah), tetapi **tidak mengubah** sifat pemindaiannya.
   Ambang "~100 rb baris `inv_stock_balances` butuh tabel ringkasan" tetap berlaku dan tetap belum
   tercapai.

---

## 6. Deviasi baru yang ditemukan

1. **Empat migrasi Inventory bernomor di dalam blok Finance** (penyimpangan LAMA, dari kampanye
   sebelumnya; **tidak** diganti namanya karena sudah berjalan di produksi):

   | Berkas | Blok yang benar |
   |---|---|
   | `2026_08_03_001117_add_received_date_to_inv_transfers_table.php` | Inventory (000400–000499) |
   | `2026_08_08_001118_create_inv_issue_returns_table.php` | Inventory |
   | `2026_08_08_001119_create_inv_purchase_returns_table.php` | Inventory |
   | `2026_08_08_001124_add_cancellation_to_inv_goods_receipts_table.php` | Inventory |

   Keempatnya duduk di 001100–001199, yang §2 berikan kepada **Finance**. Akibat praktisnya bukan
   nol: siapa pun yang menghitung "sampai mana blok Inventory terpakai" dengan membaca nomor akan
   melewatkan empat berkas ini, dan siapa pun yang menghitung blok Finance akan mengira empat slot
   terpakai padahal tidak.

2. **Dua migrasi Inventory melanggar aturan "increment by 10" DI DALAM bloknya sendiri**:
   `2026_08_28_000445_add_ipp_to_inv_issues_table.php` dan
   `2026_08_30_000446_add_import_source_to_inv_stock_adjustments_table.php` duduk di antara 000440
   dan 000450. Tidak berbahaya, tetapi ia sebabnya blok Inventory "habis" pada granularitas puluhan
   sementara masih ada nomor satuan yang bebas — dan itulah yang membuat penetapan blok lanjutan
   di §2 perlu menyebutkan granularitasnya dengan kata-kata.

3. **`Modules/Core/Support/PrintableDocuments.php` (Core) mengimpor 60+ kelas modul fitur.** Aturan
   rumah "Core tidak pernah mengimpor modul fitur" nyata-nyata tidak berlaku untuk berkas itu.
   Paket ini **tidak** mengubahnya (dan mengikuti pola yang ada: `FormPrintService` bespoke
   mengimpor `Modules\Inventory\Models\Item`), tetapi mencatat bahwa aturannya, sebagaimana
   tertulis, tidak menggambarkan basis kode ini. Yang benar-benar ditegakkan adalah aturan yang
   lebih sempit: **registri yang harus DEGRADASI dengan anggun** (`ModuleCounts`,
   `WatchedDeadlines`, `WatchedThresholds`) memakai `DB::table` + literal string + `Schema::hasTable`.

4. **`CLAUDE.md` yang disebut perintah paket tidak ada di repositori ini.** Yang ada
   `docs/CLAUDE-CODE-PROMPT.md`. Tidak ada berkas bernama `CLAUDE.md` di seluruh pohon (di luar
   `vendor/`).

5. **Tiga kalimat dokumentasi yang menjadi SALAH begitu ambangnya berubah** — permukaan yang sama
   mudah dilupakan seperti salinan kueri. PANDUAN §6.2 berkata "Stok minimum adalah satu angka pada
   master item yang diterapkan ke SETIAP gudang" (sejak F-6 hanya benar untuk pasangan tanpa
   aturan); ia mengutip keadaan kosong *"Semua item berada di atas stok minimum."* yang tidak ada
   lagi di kodenya; dan **enam** berkas `docs/ONBOARDING/*.md` menamai kartu dasbor "Stok di bawah
   minimum", kartu yang namanya sudah berganti. Ketiganya diperbaiki pada `59bd642`; berkas bukti
   (`results-phase-*.json`) sengaja **tidak** disentuh — angka di sana adalah pengukuran pada
   waktunya.

6. **Cacat SPA yang lolos dari suite PHP yang hijau sempurna** (keduanya ditemukan oleh harness,
   keduanya diperbaiki pada `2090af9`):

   - **isian ketik layar pindai setinggi 34 px.** Ini satu-satunya jalan yang tersisa di iPhone,
     jadi kotak itu adalah pemindai bagi separuh lapangan — dan standar target sentuh rumah ini
     42–46 px (`.btn.lg` = 46). Sekarang 46 px, huruf 16 px (Safari iOS memperbesar seluruh halaman
     saat fokus masuk ke isian berhuruf lebih kecil dan tidak mengecil lagi).
   - **`await video.play()` menggantung selamanya** pada trek yang menyala tanpa mengirim bingkai:
     pemindainya tidak pernah mulai dan kalimat di layar berhenti di "Meminta izin kamera…", yang
     persis salah — izinnya sudah diberikan.

7. **Jebakan Blade `\B@`.** `@else` yang didahului huruf (`…berbeda@else`) **bukan** direktif: ia
   lolos sebagai teks, cabang `@if` di atasnya menelan sisa berkas, dan lembarnya gagal dengan
   "unexpected end of file, expecting elseif". Terjadi pada versi pertama `label-barcode.blade.php`
   dan sekarang tercatat di CONVENTIONS §33.

8. **Urutan rute Laravel.** `items/scan` harus berdiri **di atas** `items/{item}`; di bawahnya
   `scan` tertangkap sebagai `{item}`, pengikatan modelnya gagal, dan **setiap** pemindaian
   menjawab 404 — yang di lapangan terbaca sebagai "pemindainya rusak". Dipaku uji (mutasi 21:
   10 uji merah).

9. **Dua kekeliruan harness sendiri** (diperbaiki dan dicatat di tempatnya):
   - skenario yang memaku pasangan canon `WH-PRJ-2026-001 × ITM-0001` jatuh karena item itu
     **sudah** menjadi baris PR disetujui `PR/2026/II/0001` di data demo — **fiturnya bekerja**,
     skenarionya yang tidak bisa dipercaya. Targetnya kini **dipilih dari data saat berjalan**;
   - `_f6_set_barcode` yang mem-PUT hanya kolom `barcode` ditolak **422** oleh
     `ItemUpdateRequest` (yang menuntut `name`/`category_id`/`unit`/`item_type`) tanpa satu tanda
     pun, sehingga "tidak ada barcode ganda" terukur sebagai **keberhasilan**. Status PUT-nya
     sekarang ikut menjadi syarat skenario.

---

## 7. Yang TIDAK dikerjakan, dan alasannya

| Tidak dikerjakan | Alasan |
|---|---|
| `inv_items.barcode` dijadikan UNIQUE | Keputusan pemilik (§5.1). Migrasinya bisa **gagal saat deploy** di atas data yang sudah memuat duplikat; yang dikerjakan adalah membuat duplikatnya terlihat |
| Impor massal aturan reorder | Keputusan pemilik (§5.5). Menyentuh registri `ImportableResources` yang dipakai lane lain — paket kecil tersendiri |
| Entri `WatchedThresholds` untuk reorder | Akan menjadi **salinan kedua sebuah aturan** (§5.6). Jalur yang benar adalah pemasok yang membaca `lowStockAlerts()` |
| Entri `ReportableResources` untuk aturan reorder | Delapan resource Laporan Bebas adalah keputusan pemilik ledger #4; menambah yang kesembilan bukan wewenang paket ini |
| Ekspor XLSX lembar label | Lembar stiker bukan tabel; sebuah XLSX berisi barcode adalah berkas yang tidak bisa dipakai siapa pun |
| Pindai → langsung membuat bon/penerimaan | Layar pindai **hanya membaca**. Menjadikannya pintu tulis berarti satu pindaian yang salah memindahkan stok, dan pemindaian yang ambigu (barcode ganda) belum punya jawaban yang bisa dipercaya sampai §5.1 diputuskan |
| Perintah CLI `erp:` untuk reorder | Tidak diminta paket; usulan PR adalah tindakan yang harus dilihat orangnya sebelum disimpan, dan cron yang membuat PR draf setiap malam adalah fitur yang berbeda |
| Gerbang rilis penuh + MySQL | Dijalankan terpisah, sesuai perintah paket. Yang dijalankan di sini: `tests/Feature/Inventory`, `tests/Feature/Procurement`, `tests/Feature/Core` |
| Merge / deploy | Dilarang perintah paket. Branch `feat/phase2-f6` berdiri sendiri |

---

## 8. Berkas

**Baru**

```
Modules/Inventory/Database/Migrations/2026_09_08_001700_create_inv_reorder_rules_table.php
Modules/Inventory/Models/ReorderRule.php
Modules/Inventory/Http/Controllers/ReorderRuleController.php
Modules/Inventory/Http/Controllers/ReorderController.php
Modules/Inventory/Http/Controllers/ItemScanController.php
Modules/Inventory/Http/Requests/ReorderRuleStoreRequest.php
Modules/Inventory/Http/Requests/ReorderRuleUpdateRequest.php
Modules/Inventory/Http/Resources/ReorderRuleResource.php
Modules/Inventory/Services/ReorderService.php
Modules/Core/Support/Code128.php
Modules/Core/Resources/views/forms/label-barcode.blade.php
public/app/js/views/reorder.js
public/app/js/views/pindai.js
tests/Feature/Inventory/ReorderRuleSchemaTest.php
tests/Feature/Inventory/ReorderThresholdTest.php
tests/Feature/Inventory/ReorderRuleApiTest.php
tests/Feature/Inventory/ReorderProposalTest.php
tests/Feature/Inventory/Code128Test.php
tests/Feature/Inventory/LabelBarcodePrintTest.php
tests/Feature/Inventory/ItemScanTest.php
docs/LAPORAN-PAKET-HM-F-6.md
docs/bukti-uji/s32-*.png, docs/bukti-uji/s33-*.png (17 berkas)
```

**Diubah**

```
Modules/Inventory/Services/StockService.php          lowStockAlerts() — ambang yang menang
Modules/Inventory/Routes/api.php                     8 rute baru; items/scan DI ATAS items/{item}
Modules/Core/Support/ModuleCounts.php                salinan kueri 'inv' + label
Modules/Core/Services/FormPrintService.php           FORMS['label-barcode'] + labelBarcode()
Modules/Core/Http/Controllers/FormPrintController.php  parameter ?jumlah=
public/app/js/schema.js                              RESOURCES + NAV + printForms + MODULES.kpi
public/app/js/app.js                                 2 rute baru
public/app/js/views/custom.js                        tab "Perlu dipesan ulang"
public/app/js/views/widgets/stok-minimum.js          ambang yang menang + urutan
public/app/js/views/widgets/registry.js              judul + keterangan widget
public/app/sw.js                                     SHELL + SHELL_VERSION 4 → 5
docs/CONVENTIONS.md                                  §2 blok lanjutan; §31–§34 baru
docs/PANDUAN-PENGGUNA.md                             §6.2 ditulis ulang; §6.3b baru (tiga layar F-6)
docs/ONBOARDING/{direktur,procurement,project-manager,site-manager,teknisi,warehouse}.md
                                                     nama kartu dasbor yang sudah berganti
docs/bukti-uji/harness-playwright.py                 S32, S32m, S33, S33k, S33m
docs/bukti-uji/results-phase-2.json                  18 → 23 kunci
tests/Feature/Core/ModuleCountsTest.php              fixture + 2 uji baru
tests/Feature/Core/PrintCatalogueBespokeTest.php     katalog 64 → 65; uji izin diperkuat
```

Tidak satu pun berkas bersama terlarang disentuh (`bootstrap/*`, `composer.json`,
`database/seeders/DatabaseSeeder.php`, `routes/*` di root). Tidak ada dependensi Composer/npm baru.
`database/database.sqlite` tidak disentuh; seluruh percobaan berjalan di atas salinan di scratchpad.

---

## 9. Putaran perbaikan verifikasi (8–9 September 2026)

Empat lensa verifikasi mengembalikan **41 temuan** atas `fb7ada5`. Seluruhnya diperbaiki di
branch yang sama; tidak satu pun ditolak. Bagian ini menggantikan angka §3 dan §4 di atas untuk
keadaan HARI INI — angka lama dibiarkan berdiri sebagai catatan keadaan pada `fb7ada5`.

### 9.1 Tiga cacat yang membuat fiturnya tidak bekerja di tangan pemakainya

**a. Dialog cetak lembar label TIDAK PERNAH muncul.** `print.js` menunggu
`tab.document.querySelector('.lembar')` sebelum memanggil `tab.print()` — `readyState` saja tidak
cukup, karena `about:blank` sudah `complete`. `label-barcode.blade.php` adalah satu-satunya lembar
yang tidak mewarisi `forms.layout`, jadi ia tidak punya pembungkus itu: lembar 12 stiker tergambar
sempurna di tab barunya, lalu tidak terjadi apa-apa; sesudah ~7 detik `PRINT_POLL_LIMIT` menyerah
tanpa satu pun pesan. Di gudang itu terbaca sebagai "tombol cetaknya rusak".
Diukur di Chromium lewat menu Cetak sungguhan: `print_calls` **0 → 1**, sama dengan kontrol GRN.

**b. Barcode dikecilkan diam-diam sampai tidak terpindai.** `.stiker { width: 62mm }` +
`max-width: 100%`: kotaknya tetap dan GAMBARNYA yang dikecilkan — 80,9% untuk ITM-0001, 26,2%
untuk kode 33 karakter, **2,8%** untuk barcode pemasok 100 karakter (modul 0,055 mm). Dibuktikan
sampai kertasnya oleh lensa barcode: raster 600 dpi dari PDF cetaknya sendiri, **0 dari 5 garis
pindai** bisa membacanya. Sekarang KOTAKNYA yang menyesuaikan (kisi 3 → 2 → 1 stiker per baris)
dan kode yang tetap tidak muat ditolak dengan kalimat yang menyebut panjangnya. Diukur di
Chromium sesudahnya:

| kode | kolom | stiker | modul cetak | tinggi batang | rasio |
|---|---|---|---|---|---|
| `ITM-0001` (8) | 3 | 62,0 mm | **0,432 mm** | 8,64 mm | 15,2% |
| `8991002123458` (13) | 3 | 62,0 mm | **0,399 mm** | 8,57 mm | 15,0% |
| 22 karakter | 2 | 95,0 mm | **0,303 mm** | 13,49 mm | 15,0% |
| 33 karakter | 1 | 190,0 mm | **0,455 mm** | 27,74 mm | 15,0% |
| 100 karakter | — | — | **ditolak** ("membutuhkan 1155 modul … di bawah 0,25 mm") | — | — |

**c. "Matikan kamera" tidak mematikan kamera.** `ui.js` memasang `onClick:` lewat
`addEventListener`; `pindai.js` lalu menambahkan `trigger.onclick = …`, yang adalah pendengar
KEDUA. Satu klik menjalankan `startCamera()` DAN `stopCamera()`: akuisisi kedua menimpa `stream`
sebelum yang pertama sempat dihentikan. Layar berkata "Kamera belum dinyalakan." sementara lampu
kamera tetap menyala dan di Android menahan aplikasi lain sampai tabnya ditutup. Diukur
(getUserMedia dan `MediaStreamTrack.stop` diinstrumentasi):

```
sebelum: gum=2 stops=1  stream#1 LIVE pada detik ke-1, 6 dan 10
sesudah: gum=1 stops=1  stream#1 active=false track=ended
pindah rute: gum=2 stops=2, kedua stream berakhir
```

### 9.2 Temuan → commit

| Temuan | Ringkas | Commit |
|---|---|---|
| F6L-01, F6L-02 | "Jumlah pesan" dikosongkan → 500 SQL mentah; penjaga unik hanya satu arah | `38745b3` |
| F6L-03 | "semuanya sudah ada di PR terbuka" untuk SETIAP hasil kosong; `warehouse_id` tanpa `exists` | `682f0c0` |
| F6L-06, F6L-07, F6L-08, F6-K6 | empat kekosongan uji idempotensi/jumlah/soft-delete/kalimat | `682f0c0` |
| F6L-09 | daftar `tables` registri tidak dijaga per-tabel | `c6706bc` |
| F6L-12 | aturan yang item/gudangnya dibuang tampak hidup | `4dbc86a` |
| F6L-10, F6L-11 | nama uji & dua komentar rute menjanjikan gerbang yang tidak ada; komentar app.js salah tempat | `3f97603` |
| F6-C128-01…-04, -06…-10, F6-K1 | geometri cetak, teks terpotong, `.lembar`, `trim()`, barcode ganda di lembar, ambang formulir 7→8 | `a003a84` |
| F6-V4-01, -02, -03, -05 | kamera bocor, urutan isSecureContext, huruf besar-kecil + autocapitalize, target sentuh | `05625ef` |
| F6-V4-06 | ubin menghitung PASANGAN dan menyebut "item" | `671a091` |
| F6-K2, F6-K11 | PO terbuka tanpa PR tidak menahan usulan; dua layar tanpa tautan | `35c98d7` |
| F6-K4, F6-K10, F6-V4-08 | kartu item tidak menyebut penggantinya; audit barcode ganda tanpa permukaan | `5e4ce8c` |
| F6-K5 | plafon 1–60 stiker tidak bisa dicapai pemakai | `bfccad1` |
| F6-C128-05, F6-V4-04 | harness mengukur lembar tanpa CSS-nya; siklus hidup kamera tidak terlihat | `4887a57` |
| F6L-04, F6L-05, F6-K3, F6-V4-07 | enam kalimat panduan yang paket ini buat salah | `bf34194` |
| F6-K7, F6-K9 | §16 menggambarkan kueri lama tanpa EXPLAIN; tabrakan blok Core di ledger | `0af339b` |
| F6-K8 | laporan menghitung 2 kegagalan pint, terukur 6 | berkas ini, §3 |
| — | §31–§34 disesuaikan dengan aturan yang benar-benar berlaku | `cd78d35` |

### 9.3 Mutasi putaran perbaikan — 25 dijalankan, 25 dipaku MERAH

Sepuluh di antaranya **lolos hijau sebelum** commit yang menutupnya.

| Mutasi | Berkas | Merah | Dulu |
|---|---|---|---|
| pin `reorder_qty` null → 0 dibuang | kedua FormRequest | 2 | — |
| cermin `unique` pada `item_id` dibuang | `ReorderRuleUpdateRequest` | 1 | — |
| pin `is_active` null dibuang | `ReorderRuleUpdateRequest` | 1 | — |
| `skipped > 0` dipaksa `true` | `ReorderController` | 1 | — |
| `Rule::exists` warehouse dibuang | `ReorderController` | 1 | — |
| `OPEN_STATUSES` → `[Draft]` | `ReorderService` | 2 | **HIJAU** |
| baris PR memakai `shortage_qty` | `ReorderService` | 1 | **HIJAU** |
| `whereNull('p.deleted_at')` dibuang | `ReorderService` | 1 | **HIJAU** |
| `why_skipped` → "XXX MUTASI XXX" | `ReorderService` | 1 | **HIJAU** |
| kueri PO dicabut | `ReorderService` | 1 | — |
| PO `closed` ikut dianggap terbuka | `ReorderService` | 1 | — |
| "Sudah dipesan" disamakan dengan "diminta" | `ReorderService` | 1 | — |
| `'inv_reorder_rules'` dibuang dari `tables` | `ModuleCounts` | 2 | **HIJAU** |
| noun label dikembalikan ke "Item …" | `ModuleCounts` + `schema.js` | 1 | **HIJAU** |
| kueri diubah menjadi `distinct` item | `ModuleCounts` | 5 | — |
| `applies` dipaksa `true` + label dikosongkan | `ReorderRuleResource` | 2 | **HIJAU** |
| kolom `deleted_labels` dicabut | `schema.js` | 1 | — |
| lebar stiker 62 → 120 mm | `FormPrintService` | 5 | **HIJAU** |
| tinggi batang 15% → 2% | `FormPrintService` | 4 | **HIJAU** |
| `widthMm` dicabut (ukuran kembali ke CSS) | `FormPrintService` | 4 | — |
| penolakan kode terlalu panjang dimatikan | `FormPrintService` | 3 | — |
| pembungkus `.lembar` dicabut | blade F/LBL | 2 | **HIJAU** |
| `trim()` barcode pemasok dicabut | `FormPrintService` | 2 | **HIJAU** |
| peringatan barcode ganda dimatikan | `FormPrintService` | 1 | — |
| pematahan teks manusia dikembalikan | `Code128` | 1 | — |
| dekoder berhenti memeriksa digit periksa | `Code128Test` | 1 | — |
| pencocokan pindai kembali peka huruf | `ItemScanController` | 3 | **HIJAU** |
| `matched_on` kembali peka huruf | `ItemScanController` | 2 | — |
| kalimat kartu item dimatikan | `ItemResource` | 1 | — |
| hitungan aturan mengabaikan `is_active` | `ItemController` | 1 | — |
| saringan barcode ganda dimatikan | `ItemController` | 1 | — |

…ditambah tiga mutasi terhadap **harness**, yang sebelumnya tidak bisa dilihat sama sekali:

| Mutasi | Skenario | Syarat merah | Dulu |
|---|---|---|---|
| `stopCamera()` dilumpuhkan total | S33k | 3 | **HIJAU** (S33, S33k, S33m semuanya) |
| pembungkus `.lembar` dicabut | S33 | 1 | **HIJAU** |
| `widthMm` dicabut + kisi dikunci 62 mm | S33 | 1 | **HIJAU** |

### 9.4 Angka gerbang sesudah perbaikan

| Perintah | Sebelum (`fb7ada5`) | Sesudah |
|---|---|---|
| `vendor/bin/phpunit tests/Feature/Inventory tests/Feature/Procurement` | OK 563 / 8.385 | **OK 603 uji / 8.789 pernyataan** (01:48) |
| `vendor/bin/phpunit tests/Feature/Core` | OK 968 / 8.818, 11 dilewati | **OK 972 uji / 8.870 pernyataan, 11 dilewati** (03:08) |
| MySQL 8 (`phpunit.mysql.xml`, `DB_DATABASE=erp_dryrun`), sembilan berkas F-6 | 55 / 293 | **OK 155 uji / 6.666 pernyataan, 3 dilewati** (01:05) |
| `vendor/bin/pint --test` pada berkas yang disentuh | lolos | **lolos** |
| Harness S32/S32m/S33/S33k/S33m | 5 skenario, 55 syarat | **5 skenario, 65 syarat**, semuanya hijau |
| `results-phase-2.json` | 23 kunci | **23 kunci**; 18 kunci pra-F-6 tetap **byte-identik** dengan versi di `main` |

**Peramban.** Chromium headless di atas `php -S 127.0.0.1:8166` melayani SALINAN sqlite di
scratchpad (server dimatikan berdasarkan PID). **Lima peran** (admin, warehouse, procurement,
teknisi, direktur) × **dua lebar** (1440×900 dan 390×844) × **delapan rute** (`#/home`,
`#/dashboard`, `#/stock`, `#/r/inventory/reorder-rules`, `#/usulan-pesan-ulang`, `#/pindai`,
`#/r/inventory/items`, `#/d/inventory/items/1`) = 80 pemuatan rute:

```
console_errors: 0   pageerrors: 0   failed_requests: 0   responses_4xx_5xx: 0
```

### 9.5 Yang berpindah ke keputusan pemilik

1. **GET Inventory tidak bergerbang izin baca** — termasuk `reorder-rules`, `reorder/proposal` dan
   `stock/low-stock`. Siapa pun yang punya sesi (termasuk peran HR) bisa membacanya lewat alamat
   endpoint-nya, sementara ubin launcher Persediaan MEMANG bergerbang `inv.view`. Ini **pola modul
   yang sudah ada sebelum F-6**, bukan lubang yang paket ini buka, jadi perilaku hari ini
   dipertahankan — tetapi ia sekarang **dinyatakan**, bukan disimpulkan: nama uji dan komentar rute
   berhenti menjanjikan gerbang, dan `ReorderRuleApiTest::test_writing_a_rule_needs_inv_create_
   while_reading_follows_the_module_default` MEMAKU 200 bagi sesi tanpa satu pun izin `inv.*`.
   Kalau pemilik memutuskan sebaliknya, baris itulah yang jatuh lebih dulu dan menunjuk tempat
   gerbangnya.

2. **Blok migrasi Core.** Ledger pemilik (ROADMAP §5 baris 5) merekomendasikan "Core 001400–?",
   dan `001400–001499` adalah blok PERTAMA Quality menurut tabel CONVENTIONS §2 — sudah berisi
   enam migrasi. Usul pengganti **001800–001899** ditandai di kedua dokumen dan menunggu
   pengesahan. Tidak ada migrasi Core yang dibuat paket ini, jadi tidak ada yang mendesak.

3. **`inv_items.barcode` unik atau tidak** (§5.1) tetap keputusan pemilik — tetapi audit yang
   dibutuhkannya sekarang **punya permukaan**: daftar Item membawa kolom Barcode, saringan
   "Barcode ganda", dan `sort=barcode`. Sebelumnya §5.1 meminta angka yang hanya bisa diambil
   lewat SSH + tinker ke produksi, yaitu keputusan yang tidak akan pernah diambil.

### 9.6 Deviasi tambahan yang ditemukan putaran ini

1. **Tabrakan blok migrasi di ledger pemilik**: "Core 001400–?" = blok pertama Quality. Ditandai di
   ROADMAP §5 dan CONVENTIONS §2 (lihat §9.5 butir 2).

2. **Enumerasi layar Persediaan di empat berkas onboarding sudah tidak lengkap SEBELUM F-6.**
   Diukur di Chromium: keenam peran pemegang `inv.view` (warehouse, procurement, teknisi,
   site-manager, project-manager, direktur) melihat **11 baris yang sama**, sementara
   `teknisi.md`, `site-manager.md` dan `project-manager.md` menyebut lima, tiga dan dua baris.
   Putaran ini memperbaiki **klaim jumlah** yang F-6 buat salah (warehouse.md "kedelapan" → 11,
   procurement.md "kedelapan" → 11, "tujuh lembar" → delapan) dan menambahkan satu kalimat tentang
   tiga baris baru ke tiga berkas lain; **audit ulang penuh enumerasi lama tidak dikerjakan** —
   ia tidak dibuat salah oleh F-6 dan menyentuh enam berkas peran sekaligus.

---

## 10. Putaran kedua: enam cacat yang LAHIR DARI PUTARAN PERBAIKAN (9 September 2026)

Verifikasi ulang atas `4fe3274` menyatakan 39 dari 41 temuan FIXED dan 28 mutasi merah — dan
menemukan **enam cacat baru, semuanya lahir dari putaran perbaikan itu sendiri**. Empat di
antaranya sampai ke kertas atau ke keputusan pemilik. Bagian ini menggantikan §9 untuk keadaan
HARI INI.

### 10.1 Satu penyakit, tiga bentuk — dan bagaimana ia disatukan

V7-2, V7-3 dan V7-4 adalah cacat yang sama: **satu aturan ditegakkan di satu permukaan dan bocor
di permukaan kedua**. Ia sudah berulang di sepanjang kampanye ini, jadi perbaikannya bukan tambalan
ketiga melainkan **penyatuan sumbernya**.

**Aturan pertama — "kode mana yang dianggap sama".** Ditulis tiga kali, tiga jawaban:

| permukaan | yang dijalankannya SEBELUM | akibat yang terukur |
|---|---|---|
| `items/scan` | `UPPER(barcode)=? OR UPPER(code)=?`, tanpa terbuang | (benar) |
| lembar F/LBL | `where('barcode',$e)->orWhere('code',$e)` + `withTrashed()` | DIAM untuk `F6DUP001` vs `f6dup001`; MEMPERINGATKAN tentang kartu yang sudah dibuang |
| saringan "Barcode ganda" | `GROUP BY barcode HAVING COUNT(*)>1` | **nol baris** untuk tabrakan yang pemindainya sebut ganda |

Sesudah: `Item::SCAN_KEY_COLUMNS` (satu daftar kolom) + `Item::whereScanKeyEquals()` (satu bentuk
perbandingan) → `matchingScanCode()` untuk dua permukaan pertama, `sharingScanCode()` untuk
saringan audit. Pertanyaannya berbeda (kode yang DIKETIK / kode yang SEDANG DICETAK / SETIAP kode
yang item itu jawab); aturannya satu.

**Aturan kedua — "aturan reorder mana yang benar-benar berlaku".** Tiga syarat, tiga isi berbeda:

| permukaan | SEBELUM | akibat |
|---|---|---|
| `lowStockAlerts()` | aktif + item hidup + gudang hidup | (benar) |
| `loadCount` kartu item | `is_active` saja | kartu berkata "stok minimum di atas TIDAK berlaku" untuk aturan yang gudangnya dibuang |
| `applies` daftar aturan | kedua `deleted_at` saja | aturan NONAKTIF dikirim `applies: true` |

Sesudah: `ReorderRule::governing()` (kueri) dan `->governs()` (baris). Kueri kekurangan **tidak
bisa** memanggilnya — ia berangkat dari `inv_stock_balances` lewat LEFT JOIN supaya pasangan TANPA
aturan tetap muncul — jadi kesetaraannya **dipaku uji**, pola yang sama dengan salinan registri
Core.

### 10.2 Enam temuan → commit

| Temuan | Gejala yang dibaca pemakainya | Commit |
|---|---|---|
| V7-2, V7-3 | lembar label DIAM untuk kode ganda; saringan audit menjawab "Tidak ada data" kepada pemilik yang sedang memutuskan UNIQUE | `bdd7d8c` |
| V7-4 | kartu item menghitung aturan yang gudangnya sudah dibuang; `applies: true` untuk aturan nonaktif | `fac496f` |
| V7-1, V7-6 | kode yang ditolak tercetak 191 mm di dalam kotak 56,5 mm, menimpa dua stiker tetangga dan keluar halaman | `5031ae0` |
| (mutasi yang lolos) | font kode tulis-tangan boleh berbeda dari font yang dipakai menghitung penggalannya | `5150362` |
| V7-5 | panduan pengadaan berkata daftar itu "tanpa tombol PR" — tombol yang hanya DIA yang dapat | `592295a` |
| — | §31/§33/§34 + PANDUAN §6.3 menyatakan aturan yang benar-benar berlaku | `047a734` |

### 10.3 Angka yang diukur di Chromium (media=print, item ITM-0006)

| kode | sebelum | sesudah |
|---|---|---|
| 62 karakter | barcode dicetak, `.lembar` 194,01 mm | tidak berubah |
| 63 karakter | `.kode-tangan` **120,43 mm** dalam kotak 56,5 mm; `.lembar` **252,24 mm** | 3 baris, terlebar **56,38 mm**; `.lembar` **194,01 mm** |
| 100 karakter | `.kode-tangan` **191,10 mm**; `.lembar` **322,00 mm**; halaman menggulir menyamping | 4 baris, terlebar **56,38 mm**; `.lembar` **194,01 mm**; tidak menggulir |
| nama 120 + barcode 100 | `.lembar` **376,11 mm** (`.kepala .sub` 244,30 · `.catatan` 205,66 · `.stiker .nama` 244,30) | **194,01 mm**, 0 kotak meluap |

Permukaan keempat pada baris terakhir ditemukan oleh syarat harness yang baru, bukan oleh temuan:
setiap teks di lembar itu datang dari data yang diketik orang, jadi jaringnya dipasang satu lembar
penuh (`overflow-wrap: anywhere` pada `body`), sementara **di mana** kode tulis-tangan patah tetap
diputuskan PHP supaya angkanya bisa dipaku uji.

### 10.4 Permukaan yang DICARI SENDIRI (grep lengkap)

**Membandingkan barcode / kode item** — 10 tempat, 3 memakai aturan bersama, 7 menjawab pertanyaan
lain dan sengaja berbeda:

| tempat | pertanyaannya | status |
|---|---|---|
| `ItemScanController` | kode yang dipindai/diketik | `matchingScanCode()` |
| `FormPrintService::labelBarcode()` | kode yang sedang dicetak | `matchingScanCode()` |
| `ItemController::index` (`barcode_duplicate`) | setiap kode yang item itu jawab | `sharingScanCode()` |
| `ItemController::index` (`q=`) | pencarian SEBAGIAN (`LIKE %…%`) | beda pertanyaan — dinyatakan §34 |
| `GlobalSearchService` (grup Item) | `code`/`name` `LIKE`, **tidak menyentuh barcode** | beda pertanyaan; Ctrl+K bukan pemindai |
| `ImportableResources['items']` (`unique => 'code'`) | identitas baris saat impor | beda pertanyaan (upsert), bukan pencocokan pindai |
| `Item::booted()` (`MAX(code)`) | penomoran ITM-nnnn berikutnya | bukan perbandingan |
| `InventoryDatabaseSeeder` (`firstOrCreate(['code'…])`) | seed idempoten | bukan perbandingan |
| `pindai.js` | menampilkan `matched_on`/`status` dari server | tidak pernah membandingkan sendiri |
| `schema.js` (`lookup: 'items'`) | pilih item lewat id | bukan perbandingan |

**Menghitung / menampilkan aturan reorder** — 10 tempat:

| tempat | status |
|---|---|
| `StockService::lowStockAlerts()` | sumber ambang; tiga syaratnya di join |
| `ModuleCounts` entri `inv` | salinan sengaja di Core, dipaku `ModuleCountsTest` |
| `ItemController::show` (`loadCount`) | `governing()` |
| `ItemResource::reorder_rule_note` | dari hitungan yang sama |
| `ReorderRuleResource::applies` | `->governs()` |
| `ReorderRuleResource::deleted_labels` | hanya yang benar-benar DIBUANG — namanya jujur |
| `ReorderRuleController::index` | menampilkan SEMUA aturan, termasuk yang tidak berlaku (harus bisa dilihat & dibuang) |
| `ReorderService` | membaca `lowStockAlerts()` |
| `widgets/stok-minimum.js`, tab `custom.js`, `reorder.js` | membaca endpoint yang sama |
| FormRequest `unique` (gudang × item) | penjaga pasangan, bukan "berlaku" |

### 10.5 Mutasi putaran kedua — 16 dijalankan, 16 dipaku MERAH

Satu di antaranya **lolos hijau** dan memaksa syarat harness baru (baris terakhir).

| Mutasi | Berkas | Merah di |
|---|---|---|
| `UPPER()` dicabut dari `whereScanKeyEquals` | `Item` | 5 uji PHP |
| `SCAN_KEY_COLUMNS` tinggal `['barcode']` | `Item` | 9 uji PHP |
| `whereNull('other.deleted_at')` dicabut | `Item` | 1 uji PHP |
| lembar F/LBL menyalin aturannya sendiri (peka huruf + `withTrashed`) | `FormPrintService` | 3 uji PHP |
| peringatan ganda dimatikan di lembar | blade F/LBL | 4 uji PHP |
| lengan saringan audit ditukar | `ItemController` | 5 uji PHP |
| syarat "gudangnya hidup" dicabut dari `governing()` | `ReorderRule` | 2 uji PHP |
| syarat "itemnya hidup" dicabut | `ReorderRule` | 1 uji PHP |
| syarat `is_active` dicabut | `ReorderRule` | 2 uji PHP |
| `governs()` berhenti melihat `is_active` | `ReorderRule` | 1 uji PHP |
| kartu item kembali menghitung `is_active` saja | `ItemController` | 1 uji PHP |
| `applies` kembali hanya melihat kedua `deleted_at` | `ReorderRuleResource` | 1 uji PHP |
| aturan pemenggalan kode tulis-tangan dicabut | `FormPrintService` | 3 uji PHP **+ S33** |
| jaring `overflow-wrap` satu lembar dicabut | blade F/LBL | **S33** (`.lembar` 205,92 mm > 194,01 mm) |
| lebar pemenggalan 3× lebar stiker | `FormPrintService` | 3 uji PHP |
| font tulis-tangan 14 pt, penggalan tetap dihitung 9 pt | blade F/LBL | **LOLOS HIJAU** → syarat `Range.getClientRects()` ditambahkan, lalu **S33 merah** |

### 10.6 Angka gerbang sesudah putaran kedua

| Perintah | Hasil |
|---|---|
| `vendor/bin/phpunit tests/Feature/Inventory tests/Feature/Procurement` | **OK 614 uji / 8.913 pernyataan** (01:59) |
| `vendor/bin/phpunit tests/Feature/Core` | **OK 972 uji / 8.870 pernyataan, 11 dilewati** (03:18) |
| `vendor/bin/phpunit tests/Feature/Iam` | **OK 62 uji / 472 pernyataan** (00:21) |
| MySQL 8 (`phpunit.mysql.xml`, `DB_DATABASE=erp_dryrun`), 11 berkas F-6 | **OK 188 uji / 6.789 pernyataan, 3 dilewati** |
| `vendor/bin/pint --test` pada berkas yang disentuh | **lolos** |
| Harness S32/S32m/S33/S33k/S33m | **5 skenario, 71 syarat** (dari 65), semuanya hijau |
| `results-phase-2.json` | **23 kunci**; 18 kunci non-F-6 **byte-identik** dengan sebelumnya (diperiksa sebelum ditulis) |

**Peramban.** Chromium headless di atas `php -S 127.0.0.1:8171` (dimatikan berdasarkan PID)
melayani SALINAN sqlite di scratchpad. Empat sesi (admin, warehouse, procurement, warehouse@390px)
× **10 pemuatan rute** — `#/home`, `#/dashboard`, `#/stock` + tab "Perlu dipesan ulang",
`#/r/inventory/items`, saringan `?barcode_duplicate=1`, `#/d/inventory/items/1`,
`#/r/inventory/reorder-rules`, `#/usulan-pesan-ulang`, `#/pindai`:

```
console_errors: 0   pageerrors: 0   failed_requests: 0   responses_4xx_5xx: 0
saringan "Barcode ganda" menggambar 3 baris pada katalog bertabrakan (keempat sesi)
```

### 10.7 Yang TIDAK diperbaiki, dan alasannya

1. **`GlobalSearchService` tetap tidak mencari barcode.** Ctrl+K mencocokkan `code` dan `name`
   dengan `LIKE`, jadi menempelkan barcode pemasok ke sana tidak menemukan itemnya. Itu permukaan
   PENCARIAN, bukan pemindaian, dan layar `Persediaan › Pindai Barcode` adalah jawaban untuk
   pertanyaan itu (§34). Menambahkannya berarti keputusan produk baru — bukan penyatuan aturan yang
   sudah ada — jadi ia dinyatakan di sini, bukan dikerjakan diam-diam.
2. **`deleted_labels` tidak mendapat keping "Nonaktif".** `applies` sekarang memperhitungkan
   `is_active`, tetapi kepingnya tetap hanya menyebut yang benar-benar DIBUANG, karena itulah nama
   kolomnya — dan keadaan "nonaktif" sudah punya kolomnya sendiri di layar yang sama ("Aktif ✗").
3. **Audit enumerasi layar lama di enam berkas onboarding** (§9.6 butir 2) tetap tidak dikerjakan:
   ia tidak dibuat salah oleh F-6, dan §35 yang ditambahkan putaran ini adalah aturan untuk paket
   BERIKUTNYA, bukan izin membuka enam berkas peran sekaligus hari ini.
