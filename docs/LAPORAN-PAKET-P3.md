# Laporan Paket P3 — Opname owner, BAPP per zona, BAST subkon, UM & denda

Branch: feat/p3 · Commit: (belum di-commit — commit milik orkestrator; HEAD dasar
32e8824) · Tanggal: 2026-08-29

Opname ke pemilik (`OPN`) mengukur **volume per item BOQ per periode** dengan plafon keras
volume kontrak + CCO disetujui, menjadi sumber `actual_pct` proyek yang **berbobot nilai**
dan **menggantikan** persen yang diketik tangan, serta menjadi DPP klaim owner. BAPP per
zona (`BAPP`) mencatat apa yang dilihat pemeriksa, digerbangi NCR terbuka, dan zona
"Nunggu perbaikan"-nya **menolak ditagihkan** (kriteria #6). Invoice AR mendapat potongan
uang muka proporsional dan denda beralasan wajib (kriteria #5). `scm_handovers` memberi sisi
subkontraktor BAST I/II yang selama ini hanya berupa tanggal. Tiga formulir rumah baru:
F/OPN, F/BAPP, F/BST-SK — katalog cetak 50 → 53.

Paket ini dikerjakan tiga jalur dalam satu branch: **jalur backend** (skema, service,
controller, registri, seeder, uji aturan), **jalur cetak + SPA** (registri cetak, layar,
menu, dan sebagian besar dokumentasi), dan **jalur dokumentasi** (audit kata-demi-kata atas
pesan yang dikutip, bab yang menjadi basi karena P3, dan sisi administrator dari pergantian
sumber `actual_pct`). Bagian "Yang sengaja tidak dikerjakan" menyebut apa yang masih terbuka
dan milik siapa.

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.10 Opname owner (berita acara pengukuran volume) | ⬜ | ✅ | `prj_progress_measurements` + `_items`; `ProgressMeasurementCeilingTest` (9 uji), `ProgressMeasurementApiTest` (5 uji) |
| 3.10 Plafon volume terhadap kontrak + CCO | ⬜ | ✅ | `MeasurementService::assertWithinCeiling` + `prj_contract_variations`; `ProgressMeasurementCeilingTest` |
| 3.10 Risalah / klaim per zona (BAPP) | ⬜ | ✅ | `prj_zone_certificates`, `ZoneCertificateService`; `ZoneCertificateTest` (9 uji) |
| 3.10 BAST subkontraktor | ⬜ | ✅ | `scm_handovers`, `HandoverService`; `HandoverPrerequisiteTest` (11 uji) |
| Kriteria #5 — UM & denda pada klaim owner | ⬜ | ✅ | `fin_ar_invoices.advance_recovery_amount` / `penalty_amount` / `penalty_reason`, `OwnerAdvanceService`; `OwnerClaimTest` (15 uji) |
| Kriteria #6 — zona `waiting_repair` menolak ditagih | ⬜ | ✅ | `ArInvoiceService::assertNoBlockedZone`; `OwnerClaimTest::test_the_billing_gate_and_the_bapp_service_agree_on_which_sheet_counts` |
| 3.10 `actual_pct` dari opname (berbobot nilai) | ⬜ | ✅ | `MeasurementService::actualPctAt` + `ProgressService::refreshWeeklyActualsFromMeasurements`; `ProgressMeasurementActualPctTest` (7 uji) |
| Cetak F/OPN, F/BAPP, F/BST-SK (katalog 50 → 53) | ⬜ | ✅ | `PrintableDocuments::projects()` / `::subcontract()`; `OpnameFormPrintTest` (14 uji), `HandoverFormPrintTest` (9 uji), `PrintCatalogueBespokeTest` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **Keputusan #1 (tautan sekali-pakai) dipakai penuh untuk opname.**
  `projects/progress-measurements` masuk `ExternalApprovableDocuments` mode **TRANSISI** —
  tanda tangan yang dikumpulkan lembar ini memang tanda tangan MK, dan opname yang hanya
  disetujui kontraktor adalah klaim, bukan pengukuran. Adapter-nya
  `MeasurementService::applyExternalDecision` (service, bukan trait — §7), sehingga
  persetujuan eksternal memeriksa ulang plafon atas data terkini dan menurunkan ulang kurva
  mingguan persis seperti persetujuan internal.
- **Keputusan #6 (proksi internal dipertahankan).** Yang tersimpan sebagai penyetuju di
  `core_approvals` tetap penerbit tautan; siapa yang benar-benar memutuskan tersimpan di
  `core_external_approvals`. **Tidak satu kolom pun** pada dokumen P3 diisi nama Pemilik/MK
  dari master proyek — `prj_zone_certificates.certified_by_party` dan `certified_by_name`
  nullable **karena memang begitu** (§7), dan tercetak bergaris kosong bila kosong.
- **BAPP bukan `Approvable`.** Statusnya `ZoneCertificateStatus` (done/check/waiting_repair)
  — catatan pemeriksa, bukan tahapan persetujuan; sejalan preseden `NcrStatus` dan
  `DefectStatus`. Karena itu ia tidak masuk `ApprovableDocuments`: entri di sana akan
  meminta pemegang `prj.approve` "menyetujui" lembar yang tidak disetujui siapa pun.
- **Satu zona boleh punya banyak BAPP, dan lembar TERAKHIR yang menentukan.** Tidak ada
  `unique (project_id, location_id)`: BAPP I "nunggu perbaikan" → perbaikan → BAPP II
  "selesai". Menimpa lembar pertama akan menghapus bukti yang mendasari lembar kedua.
- **`prj_contract_variations` ada karena CCO tidak punya baris.** `crm_contract_change_orders`
  mencatat **nilai** yang ditandatangani dan tidak membawa satu pun baris volume, sedangkan
  plafon opname adalah pernyataan tentang **volume per item**. Registernya diletakkan di
  Projects, bukan Crm, karena satu-satunya pembacanya adalah aturan opname.
- **Denda tidak dihitung dari klausul mana pun.** Tidak ada mesin *liquidated damages* di
  sistem ini; `penalty_amount` manual dan `penalty_reason` **wajib** begitu nilainya bukan
  nol — kalimat alasan itulah satu-satunya bukti yang dimiliki angkanya.
- **Potongan uang muka owner memakai aritmetika yang sama persis dengan sisi subkon**
  (`AdvanceService`): `recovery = dpp × (DP ditagih ÷ nilai kontrak)`, dengan koreksi
  *catch-up* dan *floor* yang sama. "DP yang ditagih" berarti invoice `is_advance` yang
  **disetujui** dan tidak dibatalkan.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

| Migrasi | Tabel | Catatan |
|---|---|---|
| `2026_08_29_000731` | `prj_progress_measurements`, `prj_progress_measurement_items` (baru) | `contract_id` (crm) & `location_id`/`boq_item_id` lintas-modul **tanpa** `constrained()`; `project_id` di dalam modul memakai constraint. Kolom uraian/satuan/harga satuan adalah **snapshot** agar revisi BOQ tidak mengubah lembar yang sudah ditandatangani. |
| `2026_08_29_000732` | `prj_contract_variations` (baru) | Volume tambah-kurang per pasangan CCO × item BOQ. |
| `2026_08_29_000733` | `prj_zone_certificates` (baru) | Tanpa `unique` per zona, sengaja — lihat asumsi di atas. |
| `2026_08_29_000741` | `prj_weekly_progress.actual_pct_source` | String default `weekly_report`, **tidak** di-backfill: setiap baris lama memang diketik tangan, jadi defaultnya menyatakan fakta, bukan tebakan. Aman di MySQL (satu kolom ber-default, tanpa index, tanpa menulis ulang baris). |
| `2026_08_29_000970` | `scm_handovers` (baru) | Satu BAST I & satu BAST II hidup per SPK ditegakkan **service**, bukan unique index — baris soft-deleted tetap memakan slotnya pada index penuh dan aplikasi ini tidak punya undelete (PANDUAN §14). |
| `2026_08_29_001124` | `fin_ar_invoices` +5 kolom | `measurement_id` (nullable, indexed, tanpa constraint), `is_advance` (default false), `advance_recovery_amount` & `penalty_amount` (default 0), `penalty_reason` (nullable). Forward-only: tidak ada makna baris lama yang bergeser dan tidak ada jurnal yang di-restate. |

Penomoran baru di `config('erp.documents')`: `OPN/{Y}/{RM}/{N4}`, `BAPP/{Y}/{RM}/{N4}`,
`BSK/{Y}/{RM}/{N4}` — dan `SettingService::DOCUMENT_LABELS` ikut. `AWD` (P2) memang dipilih
agar kode `BAPP` tersisa untuk paket ini.

Registri yang bertambah: `ApprovableDocuments` (opname, BAST subkon), `AttachableDocuments`
(foto opname), `ExternalApprovableDocuments` (opname, mode transisi),
`PrintableDocuments::projects()` (F/OPN, F/BAPP) dan `::subcontract()` (F/BST-SK).
`PrintableDocuments::projects()` sebelumnya **kosong** — inilah dua dokumen deklaratif
pertama modul Projects; ketujuh formulir bespoke tetap di `FormPrintService::FORMS`.

## Uji

- **baru: 79.**
  `ProgressMeasurementCeilingTest` (9) · `ProgressMeasurementActualPctTest` (7) ·
  `ProgressMeasurementApiTest` (5) · `ZoneCertificateTest` (9) ·
  `HandoverPrerequisiteTest` (11) · `OwnerClaimTest` (15) ·
  `OpnameFormPrintTest` (14) · `HandoverFormPrintTest` (9).
  Fixture bersama: `tests/Feature/Projects/OpnameFixtures.php` (kontrak Rp 1 M dengan BOQ
  dua item 20 % / 80 %, sehingga persentase berbobot nilai bisa diperiksa di kepala) dan
  `tests/Unit/Subcontract/SubcontractFixtures.php` yang sudah ada, dipakai ulang.
- **lama yang diubah: 2.**
  - `tests/Unit/Core/DocumentFormatValidationTest::SHIPPED_DOCUMENT_TYPES` 47 → 50 — tiga
    jenis dokumen baru (OPN, BAPP, BSK); angkanya memang dipaku untuk berubah.
  - `tests/Feature/Core/PrintCatalogueBespokeTest` `assertCount(50)` → `53` — tiga formulir
    rumah baru; angka katalog memang dipaku untuk naik seiring formulir baru.
- **suite penuh: OK (3.352 uji, 15.188 asersi, 8 menit 11 detik)** — `vendor/bin/phpunit`
  tanpa saringan, pada tree ini, dengan seluruh perubahan kedua jalur terpasang.
  `vendor/bin/pint --dirty`: passed.

  Angka itu **dijalankan ulang dan dikonfirmasi jalur dokumentasi** pada tree yang sama,
  sesudah suntingan dokumentasinya: `OK (3352 tests, 15188 assertions)`, 7 menit 54 detik.

  Angka pembuka yang dicatat orkestrator untuk paket ini adalah **3.270 / 14.965**. Delapan
  puluh dua uji lebih banyak sekarang, sementara berkas uji P3 berisi **79** (dihitung
  dengan menjalankan kedelapan berkasnya sendiri: `OK (79 tests, 174 assertions)`). **Tiga
  uji sisanya kini terpertanggungjawabkan**, dan bukan misteri:
  `DocumentFormatValidationTest` menyetir sebagian ujinya lewat
  `#[DataProvider('shippedDocumentFormats')]` atas seluruh
  jenis dokumen yang dikapalkan, sehingga menaikkan `SHIPPED_DOCUMENT_TYPES` 47 → 50
  **menambah tiga kasus data-provider** — satu per jenis dokumen baru (OPN, BAPP, BSK).
  Aritmetikanya tutup persis: **3.270 + 79 + 3 = 3.352**. Tidak ada uji lama yang merah,
  dan dua yang diubah diubah karena angkanya memang dipaku untuk berubah (lihat di atas).

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Belum dijalankan pada tree ini (langkah 7 protokol: `migrate:fresh --seed` + curl). Pesan
yang dijanjikan, dikutip dari sumbernya dan **diuji** oleh uji yang disebut di sampingnya:

- Plafon opname (`MeasurementService::assertWithinCeiling`,
  `ProgressMeasurementCeilingTest`):
  > `Volume kumulatif item "{uraian}" {x} {satuan} melampaui volume kontrak + CCO disetujui {y} {satuan}; perbaiki volume opname, atau catat dahulu volume CCO-nya pada register variasi kontrak.`
- Gerbang BAPP (`ZoneCertificateService::assertMayCarry`, `ZoneCertificateTest`):
  > `Zona {kode} ({jalur}) tidak dapat ditandai "Selesai": {n} NCR masih terbuka di lokasi ini ({daftar}). Verifikasi atau tutup NCR-nya dahulu, atau tandai zona ini "Nunggu perbaikan".`
- Penolakan klaim owner — kriteria #6 (`ArInvoiceService::assertNoBlockedZone`,
  `OwnerClaimTest`):
  > `Zona {kode} — {nama} pada opname {kode} masih berstatus "Nunggu perbaikan"; pekerjaan di zona itu tidak dapat ditagihkan sampai BAPP-nya menyatakan selesai.`
- Denda tanpa alasan (`ArInvoiceService::assertPenaltyIsAccountedFor`, `OwnerClaimTest`):
  > `Denda wajib disertai alasan — sebutkan dasar pemotongannya (keterlambatan, pekerjaan tidak sesuai, atau kesepakatan lain).`
- Prasyarat BAST subkon (`HandoverService::assertPrerequisites`,
  `HandoverPrerequisiteTest`):
  > `{Jenis} {kode} belum dapat disetujui — {daftar butir}.`

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- **PANDUAN §3.11** — strip ringkasan invoice bertambah *Potongan uang muka* dan *Denda*;
  rumus totalnya diperbaiki (`Total = DPP + PPN − Retensi − Uang muka − Denda`, dan PPN
  dihitung atas `DPP − uang muka`); paragraf "Tiga pintu, pilih satu".
- **PANDUAN §3.11a (baru)** — klaim owner dari opname, invoice uang muka, denda beralasan
  wajib, dan penolakan zona `waiting_repair`, dengan kelima pesan dikutip kata demi kata.
- **PANDUAN §7.14 / §7.15 / §7.16 (baru)** — Opname ke Pemilik (OPN), Variasi Kontrak,
  BAPP per Zona. Termasuk mengapa `ID item BOQ` meminta id mentah, mengapa satu zona punya
  banyak lembar, dan apa artinya bagi uang.
- **PANDUAN §8** — "Tiga layar" → "Empat layar"; **§8.8 (baru)** BAST Subkon dengan tabel
  prasyarat dan kutipan penolakannya.
- **PANDUAN §13.3** — judul 50 → 53, satu blok Proyek baru untuk F/OPN & F/BAPP (dengan
  aturan kejujurannya: item tak terukur **tidak punya baris**, status zona tercetak sebagai
  kata), dan baris F/BST-SK di tabel Subkontrak. **§13.5** — tiga baris tabel kejujuran
  untuk ketiga lembar baru. **§1** (tabel menu per kelompok) dan **§2.8** ("50 formulir"
  → 53) ikut.
- **PANDUAN-ADMINISTRATOR §9.1 / §9.3** — hitungan 50 → 53 (43 → 46 di registri), baris
  `scm.view` 3 → 4, baris `prj.view` mendapat dua dokumen registri, dan "Dua belas dari 50
  formulir berorientasi lanskap" → "Tiga belas dari 53" karena F/OPN mendatar. **Baris
  `qc.view` yang hilang sejak P1-QC ditambahkan** — tanpa itu tabelnya tidak berjumlah 53
  (lihat "Deviasi baru" #6).
- **PANDUAN §7.5 (Progres Mingguan) — diperbaiki, bukan ditambah.** Bab itu berjanji
  *"Kedua persen diketik tangan. Tidak ada satu pun angka di layar ini yang dihitung…"* —
  kalimat yang **menjadi bohong** pada hari P3 mendarat, karena `recordWeekly` sejak itu
  membuang persen ketikan untuk minggu yang dicakup opname disetujui. Bab itu kini memuat
  tabel dua sumber, kutipan bantuan isian Aktual kata demi kata, kolom **Sumber aktual**
  pada daftar kolomnya, dan pernyataan tegas bahwa menyetujui opname pertama **mengubah
  angka minggu-minggu yang sudah tersimpan**. Batasnya ikut disebut: progres WBS di
  halaman proyek tidak bergeser.
- **PANDUAN §7.10 (EVM)** — kedua bunyi kalimat sumber di bawah kurva-S dikutip kata demi
  kata, berikut sebab ketiadaannya pada proyek tanpa laporan mingguan.
- **PANDUAN §3.11a** — satu kutipan **salah** diperbaiki: keterangan invoice terisi
  otomatis berbunyi `— {judul kontrak} ({kode kontrak})`, bukan `— {kontrak} ({judul})`
  seperti tertulis semula (`ArInvoiceService::createFromMeasurement` meneruskan
  `$contract->title` lalu `$contract->code`).
- **PANDUAN §7.15 / §7.16 / §8.8** — nama tombol tambahnya ditulis persis seperti SPA
  merakitnya (`Tambah ${labelOne}`, `views/list.js:121`): **`Tambah Volume
  Tambah-Kurang`**, **`Tambah Berita Acara Pemeriksaan Pekerjaan`**, **`Tambah BAST
  Subkontraktor`**. Ketiganya sebelumnya tidak disebut sama sekali.
- **PANDUAN-ADMINISTRATOR §11.1 / §11.2** — §11.2 menjadi **"Tiga salah paham"**: satu
  bagian baru tentang **pergantian sumber `actual_pct`**, sisi administrator. Ia menjawab
  telepon yang akan diterima administrator ("persen yang saya ketik hilang"): tabel *mana
  yang menang* (opname disetujui menang, tanpa setelan dan tanpa pengecualian; draf,
  diajukan dan ditolak tidak menggantikan apa pun), **mengapa persentase sebuah proyek
  bisa berubah pada hari opname pertamanya disetujui** (persetujuan menurunkan ulang
  seluruh baris mingguan yang dicakup, bukan hanya minggu berjalan), dua batasnya (progres
  WBS tidak ikut bergerak; kurva-S menyebut sumber titik terakhir), dan fakta bahwa tidak
  ada perintah artisan untuk memaksanya — ia efek samping `MeasurementService::approve`,
  jadi yang diperiksa adalah opnamenya. §11.1 mendapat satu baris gejala → sebab →
  pemeriksaan yang menunjuk ke sana.
- **PANDUAN-ADMINISTRATOR §9.3** — satu baris prosa 127 kolom dirapikan kembali ke ≤ 92,
  dan daftar "sisanya potret" dilengkapi dengan kedua lembar P3 yang memang potret
  (F/BAPP, F/BST-SK). Hitungan **tiga belas dari 53 lanskap** diverifikasi terhadap kode:
  11 `'orientation' => 'landscape'` di `PrintableDocuments` + 2 di `FormPrintService`.
- **ARCHITECTURE.md** — blok panah P3: `Projects ┈┈▶ crm_contract_change_orders.status`
  (baca *by value*) dan `Finance ┈┈▶` empat tabel `prj_`/`core_` untuk kriteria #6.
- **CONVENTIONS.md** — tidak ada perubahan yang diperlukan (tidak ada modul baru, tidak ada
  blok migrasi baru). **README.md** — tidak ada modul baru, jadi tidak ada baris baru;
  angka uji di README **belum** disegarkan (lihat di bawah).

## Yang sengaja tidak dikerjakan, dan mengapa

- **Tidak ada baris `printForms` di `schema.js` untuk ketiga formulir baru.** Tombolnya
  digambar dari **katalog server** (`GET core/print/forms`) seperti seluruh dokumen
  registri sejak paket cetak deklaratif; entri `printForms` hanya dipakai oleh tujuh
  formulir bespoke yang membawa parameter query yang tidak bisa diketahui katalog dari satu
  baris (`?tanggal=`, `?minggu=`). Menambahkannya akan menjadi pendaftaran ganda —
  `printButtonsFor` mendedupnya, jadi hasilnya bukan tombol ganda melainkan satu baris
  konfigurasi yang tidak pernah berpengaruh. `PrintFormReachabilityTest` membuktikan
  ketiganya tetap terjangkau.
- **Tidak ada sumber pilih `boqItems`.** Baris opname mengirim `boq_item_id` mentah, pola
  yang sama dengan `subcontract_item_id` pada opname subkon. Membuat pemilihnya menuntut
  endpoint daftar item BOQ yang **datar** (yang ada hanya bersarang, `boqs/{boq}/items`)
  dan endpoint itu dijaga `est.view` sementara layar opname dijaga `prj.*` — dua keputusan
  backend yang bukan milik jalur cetak+SPA. Layarnya sekarang membawa bantuan yang menyebut
  dari mana id itu dibaca, dan server menolak id dari BOQ kontrak lain dengan 422 bernama.
- **Angka uji di `README.md` belum disegarkan.** Angkanya berubah lagi begitu orkestrator
  meng-commit; menuliskannya sekarang berarti menuliskan angka yang basi sebelum dibaca.
- **Smoke test curl (langkah 7) belum dijalankan** — butuh `migrate:fresh --seed` pada tree
  bersama; milik orkestrator.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **Layar EVM mengklaim sumber yang salah setelah P3.** Keterangan di bawah kurva-S
   berbunyi *"garis fisik memakai laporan progres mingguan"* sebagai fakta tetap —
   padahal sejak P3 minggu yang dicakup opname disetujui membawa angka berbobot nilai.
   **Diperbaiki di paket ini**: `curveSourceNote()` di `public/app/js/views/evm.js` membaca
   `curve.actual_pct_source` dan menyebut sumber yang benar pada kedua tempat kurva
   digambar.
2. **Progres Mingguan menampilkan angka yang tidak diketik pengguna, tanpa penjelasan.**
   `ProgressService::recordWeekly` **membuang** persen ketikan untuk minggu yang dicakup
   opname disetujui, tetapi `WeeklyProgressResource` tidak pernah mengirim
   `actual_pct_source`, sehingga layar memperlihatkan angka lain tanpa satu kata pun
   alasannya. **Diperbaiki di paket ini**: kolom `actual_pct_source` pada resource, kolom
   "Sumber aktual" pada daftar, enum `actualPctSource`, dan bantuan pada isian Aktual.
3. **`ApprovableDocuments` menyebut jumlah dalam prosa** ("The twenty documents" sambil
   memuat 23) — sudah basi sejak P1. Jalur backend mengganti angkanya dengan prosa
   ketimbang memecahkannya lagi.
4. **`vendor/bin/pint --test` seluruh repo melaporkan 4 berkas yang butuh perbaikan dan
   tidak disentuh paket ini**: `bootstrap/app.php`, `bootstrap/providers.php`,
   `database/seeders/ProductionSeeder.php`, `database/factories/UserFactory.php`. Keempatnya
   berkas bersama yang §0.3 melarang paket menyentuhnya. Pra-ada; perlu satu kali `pint`
   oleh yang berwenang mengubah berkas bersama.
5. **Tabel menu per kelompok di PANDUAN §1 sudah basi dua paket.** Barisnya tidak memuat
   kelompok **Engineering** dan **Mutu (QA/QC)** sama sekali, dan baris Pengadaan tidak
   memuat BA Negosiasi / Keputusan Pemenang / Rencana Pengadaan (P2). Paket ini hanya
   memperbarui dua baris yang menjadi miliknya (Proyek, Subkontrak); tiga kekurangan di
   atas ditinggalkan sengaja, karena memperbaikinya berarti menulis ulang catatan paket
   lain di berkas yang sama.
6. **Tabel "jumlah formulir per modul pemilik" di PANDUAN-ADMINISTRATOR §9.1 tidak memuat
   baris `qc.view`** sejak P1-QC — jumlahnya berhenti di 47 sementara prosanya menyebut
   50. Ditambahkan di paket ini karena tanpa baris itu tabelnya tidak bisa berjumlah 53,
   dan tabel yang tidak berjumlah adalah tabel yang tidak dipercaya siapa pun.
7. **Tidak ada pemeriksa sintaks JavaScript di repo ini.** Tidak ada runtime JS di host dan
   tidak ada uji yang membaca keseimbangan kurung `schema.js` — satu koma hilang di berkas
   3.500 baris itu mematikan seluruh SPA tanpa satu pun uji merah. Paket ini memeriksanya
   dengan skrip sekali pakai; sebuah uji PHP yang menghitung kurung (senapas
   `NavRouteRegistryTest` yang juga sekadar grep) akan menutup lubang itu permanen dan
   sengaja **tidak** dibuat di sini karena bukan bagian paket P3.
8. **PANDUAN §7.5 berjanji hal yang P3 sendiri membuatnya tidak benar** — dan tidak ada
   uji yang bisa menangkapnya. Kalimatnya: *"Kedua persen diketik tangan. Tidak ada satu
   pun angka di layar ini yang dihitung dari WBS, laporan harian, atau baseline."* Sejak
   `ProgressService::recordWeekly` membuang persen ketikan untuk minggu yang dicakup opname
   disetujui, kalimat itu **salah**, dan ia salah dengan cara yang paling merugikan: ia
   meyakinkan pengawas bahwa angka yang berubah sendiri pasti kerusakan. **Diperbaiki di
   paket ini** (jalur dokumentasi). Pelajarannya lebih luas dari satu bab — sebuah paket
   yang mengganti sumber sebuah angka harus mencari **janji-janji lama tentang angka itu**,
   bukan hanya menulis bab baru tentang dokumennya sendiri.
9. **Satu kutipan "kata demi kata" ternyata tidak kata demi kata.** PANDUAN §3.11a menulis
   keterangan invoice otomatis sebagai `— {kontrak} ({judul})` sementara
   `ArInvoiceService::createFromMeasurement` meneruskan `$contract->title` lalu
   `$contract->code`, yaitu `— {judul} ({kode})`. Terbalik. **Diperbaiki di paket ini.**
   Tidak ada mekanisme di repo ini yang mengikat kutipan di dokumentasi kepada senarnya di
   kode: keduanya bisa menyimpang diam-diam, dan hanya pembacaan berdampingan yang
   menemukannya. Ini kerabat dekat lubang #7 — dua-duanya kelas kesalahan yang seluruh
   suite hijau tidak menyentuhnya sama sekali.
