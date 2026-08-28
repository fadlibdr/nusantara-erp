# Laporan Paket P1-QC — Modul baru `Quality`

Branch: feat/p1-qc · Commit: (belum di-commit — commit milik orkestrator; HEAD dasar
f661981) · Tanggal: 2026-08-28

Modul baru `Quality`: template checklist inspeksi (Q1–Q31, diimpor XLSX), inspeksi mutu
(QCI) `Approvable`, laporan ketidaksesuaian (NCR) berdaur sendiri yang **memblokir** inspeksi
tahap berikutnya di satu lokasi dan **serah terima pertama (BAST I)**, serta benda uji beton
dengan lulus/tidak kuat tekan **dihitung** terhadap target mutu (K → fc' silinder, PBI 1971) —
tidak pernah diketik. Dependensi Quality → Projects, Engineering, Core (tidak sebaliknya);
Projects membaca `qc_ncr` di balik `Schema::hasTable` (bukan dependensi kode).

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.8 Inspeksi IK (checklist mutu) | ⬜ | ✅ | `qc_inspections` + `qc_inspection_templates`; `InspectionCycleTest` (7 uji) |
| 3.8 Benda uji & slump (FM-10-24) | ⬜ | ✅ | `qc_concrete_samples` (slump_cm, truck_no, volume_m3, sample_count); `ConcreteStrengthTest::test_the_api_stores_the_computed_pass_never_a_typed_one` |
| 3.8 Kuat tekan (FM-10-23) | ⬜ | ✅ | `qc_concrete_tests.pass` **dihitung** `ConcreteStrengthService`; `ConcreteStrengthTest` (8 uji) |
| 3.8 Q-Pass (verdict lulus/tidak) | ⬜ | ✅ | `qc_inspections.passed` diturunkan dari butir (any `nok`); `InspectionCycleTest::test_overall_pass_is_derived_from_the_result_rows` |
| 3.8 Q-Plan / template inspeksi Q1–Q31 (§3.4) | ⬜ | ✅ | `qc_inspection_templates` (+`_items`), impor XLSX `document-import`; `TemplateImportTest` (3 uji) |
| 3.7 NCR (FM-10-14) — "defect ≠ NCR" | ⬜ | ✅ | `qc_ncr` (root/corrective/preventive = CAPA), daur `NcrStatus`; `NcrLifecycleTest` (7 uji) |
| Kriteria #3 — NCR terbuka memblokir inspeksi berikut | ⬜ | ✅ | `InspectionService::submit` (urutan tahap); `NcrBlocksInspectionTest` (5 uji) |
| BAST I prasyarat diperluas — tidak ada NCR terbuka | ⬜ | ✅ | `BastPrerequisiteService::ncrChecks`; `NcrBlocksHandoverTest` (3 uji) |
| 3.8 "Kendali mutu terstruktur … hanya prasyarat BAST & defect" | 🟡 | ✅ | modul QC terstruktur berdiri; F/QI, F/NCR, F/BU (`QualityFormPrintTest`, 5 uji) |
| Stock Card Beton (FM-10-25) — register pengecoran | ⬜ | 🟡 | `qc_concrete_samples` mencatat pengecoran (tgl, mutu, truk, volume); kartu stok beton penuh di luar cakupan |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **Distribusi izin `qc.*`** mengikuti alasan `eng.*` (P1-ENG), diambil dari realitas
  `RoleSeeder`: inspeksi & NCR disiapkan `site-manager` dan QC proyek, otorisasi internal di
  tangan `project-manager` — jadi `qc.approve` mendarat pada peran yang sama yang memegang
  `prj.approve`/`eng.approve`. `qc.approve` juga mencakup **memverifikasi** NCR (menerima bahwa
  koreksi berhasil = kuasa serumpun approve). `direktur` mewarisi `qc.view` + `qc.approve` lewat
  ekspansi `PREFIXES`; `admin` semuanya. Perlu konfirmasi bila pemilik ingin peran QC terpisah.
- **NCR bukan `Approvable`, `DocumentStatus`, maupun keputusan MK.** NCR punya `NcrStatus`
  sendiri (open/under_correction/verified/closed) — ia tidak diajukan-lalu-disetujui menjadi
  ada; ia dinaikkan, diperbaiki, diverifikasi. Sejalan preseden `DefectStatus`/`IncidentStatus`
  di Projects. Hanya **inspeksi** yang `Approvable` (submit → approve, maker-checker rumah).
- **`witness_party` (mk/owner) = fakta yang dicatat, bukan penyetuju.** Inspeksi disetujui lewat
  maker-checker internal `qc.approve` (persis IPP); MK yang menyaksikan tercetak di F/QI di
  samping kolom tanda tangan. Seam eksternal (`ExternalApprovableDocuments`) sengaja tidak diisi
  — sama seperti seam SDS/SMS di P1-ENG.
- **Rumus kuat tekan bersumber, bukan dikarang** (inti kejujuran paket): `K-xxx` = kekuatan
  karakteristik kubus kg/cm² (PBI 1971 N.I.-2), `fc'-xx` = kuat tekan silinder MPa
  (SNI 2847:2019). Konversi kg/cm²→MPa `× 0,0980665`, kubus→silinder `× 0,83`; rasio kematangan
  umur 7/14/28 = 0,65/0,88/1,00 dari **PBI 1971 (N.I.-2) Tabel 4.1.4** semen tipe I. Umur di luar
  tabel **ditolak**, bukan ditebak. Sumber dikutip di docblock `ConcreteStrengthService`.
- **NCR terbuka = `open` ATAU `under_correction`** (bukan `verified`/`closed`). Predikat tunggal
  `NcrStatus::isOpen()` ini adalah seluruh makna "NCR terbuka" di mana pun frasa itu muncul; uji
  `NcrLifecycleTest::test_open_values_match_the_is_open_predicate` memaku dua pembacanya
  (`InspectionService`, `BastPrerequisiteService` yang membacanya *by value* karena Projects
  tidak boleh meng-`import` enum Quality).

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

Blok migrasi `001400–001499` (Quality), seluruhnya tabel `qc_` **baru**. Tidak ada ALTER
lintas-modul.

| Migrasi | Tabel | Catatan |
|---|---|---|
| `2026_08_28_001400` | `qc_inspection_templates`, `qc_inspection_template_items` | code (Q1..Q31, unik), work_package, stage; items constrained ke template (dalam-modul); softDeletes pada template |
| `2026_08_28_001410` | `qc_inspections`, `qc_inspection_results` | project_id/ipp_id/location_id/inspector_employee_id (unsignedBigInteger+index, TANPA FK); template_id & result.template_item_id constrained (dalam-modul); `passed` diturunkan; status Approvable |
| `2026_08_28_001420` | `qc_ncr` | project_id/location_id/responsible_employee_id/subcontract_id/verified_by (index, TANPA FK); inspection_id nullable constrained (dalam-modul); stage; CAPA; `status` = NcrStatus |
| `2026_08_28_001430` | `qc_concrete_samples`, `qc_concrete_tests` | project_id/location_id (index, TANPA FK); grade/slump/truck/volume/sample_count; tests age_days/strength_mpa/lab/`pass` (dihitung); tests constrained ke sample |

**Perubahan kode (bukan migrasi) di modul lain:** `BastPrerequisiteService::ncrChecks` +
`ProjectService::approve` kini menjalankan checklist BAST I saat `submitted` (dulu hanya
BAST II). Keduanya membaca `qc_ncr` **by value** di balik `Schema::hasTable` — Projects tidak
bergantung ke Quality. Registri Core (`ApprovableDocuments`, `AttachableDocuments`,
`ImportableDocuments`, `PrintableDocuments`), `SettingService::DOCUMENT_LABELS` (+QCI/NCR),
`config('erp.documents')` (+QCI/NCR), dan `PermissionSeeder`/`RoleSeeder` (+prefiks `qc`)
ditambah lane backend.

**Aman di MySQL dengan data lama.** Keenam tabel `qc_` seluruhnya baru — tidak ada kolom lama
diubah atau dihapus, tidak ada backfill. Rujukan lintas-modul `unsignedBigInteger` + `index`
tanpa `constrained()` (CONVENTIONS §3); `constrained()` hanya di dalam modul (template↔items,
inspection↔results, sample↔tests, ncr→inspection). Gerbang BAST I hanya berlaku sejak tabel
`qc_ncr` ada (`Schema::hasTable`), jadi instalasi tanpa modul Quality tak berubah perilakunya.

## Uji

- baru: 40 (8 berkas `tests/Feature/Quality/`)
  - `ConcreteStrengthTest.php` (8) — K→fc' silinder, fc' apa adanya, rasio kematangan PBI,
    lulus/gagal tiap umur, mutu tak terbaca ditolak, umur di luar tabel ditolak, API menyimpan
    `pass` yang dihitung (bukan diketik), mutu tak terparse ditolak sebelum baris mendarat
  - `InspectionCycleTest.php` (7) — nomor QCI + draf, `passed` diturunkan dari butir, `passed`
    tak bisa dipaksa dari request, butir template lain ditolak, lokasi proyek lain ditolak,
    submit→approve maker-checker rumah, pemegang kedua menyetujui
  - `NcrBlocksInspectionTest.php` (5) — inspeksi tahap lebih lanjut di lokasi sama diblokir &
    disebut, tahap sama lolos, lokasi lain lolos, tahap lebih awal tak diblokir, NCR
    terverifikasi tak lagi memblokir
  - `NcrBlocksHandoverTest.php` (3) — BAST I ditolak selama NCR terbuka & pesan menyebutnya, NCR
    under_correction tetap memblokir, BAST I lolos begitu NCR diverifikasi
  - `NcrLifecycleTest.php` (7) — nomor + mulai open, tahap/lokasi diwarisi dari inspeksi rujukan,
    NCR mandiri tanpa tahap ditolak, penanggung jawab XOR, transisi open→closed, transisi ilegal
    ditolak, `openValues()` cocok dengan `isOpen()`
  - `QualityFormPrintTest.php` (5) — F/QI cetak checklist + verdict dari DB, F/QI tanpa hasil
    mengosongkan sel verdict (tak pernah klaim LULUS), F/NCR cetak & sebut penanggung jawab,
    F/NCR terbuka mengosongkan kolom verifikasi, F/BU cetak target & `pass` tersimpan
  - `TemplateImportTest.php` (3) — template + butir impor sebagai satu dokumen, impor ulang kode
    sama memperbarui & tak menggandakan, satu workbook memuat banyak template
  - `QualitySeederTest.php` (2) — skip anggun tanpa proyek kanon, seed cerita mutu idempoten
- lama yang diubah: 3
  - `tests/Unit/Core/DocumentFormatValidationTest.php` — `SHIPPED_DOCUMENT_TYPES` 42→44
    (tambah mask QCI/NCR di `config('erp.documents')`)
  - `tests/Feature/Core/PrintCatalogueBespokeTest.php` — katalog 45→48 (3 formulir registri
    Quality: F/QI, F/NCR, F/BU)
  - `tests/Feature/Projects/BastTwoPrerequisiteTest.php` — **perilaku berubah oleh keputusan
    pemilik** (BAST I kini bergerbang "tidak ada NCR terbuka"): uji `..._not_gated_by_the_new_
    checklist` diganti nama menjadi `..._not_gated_by_the_bast_two_checklist` dan kini menegaskan
    BAST I membawa **satu** check `ncr_terbuka` (lolos tanpa NCR), sementara defect kritis
    terbuka tetap hanya menggerbangi BAST II. Bukan regresi: `evaluate(BAST I)` dulu `[]`, kini
    `['ncr_terbuka']` — persis perluasan prasyarat yang diminta roadmap P1-QC.
- suite penuh: OK (3228 uji, 14752 asersi, 7m21s) — `vendor/bin/phpunit`, 2026-08-28

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Endpoint baru (semua di bawah `auth:sanctum`, prefiks `api/quality`):
`inspection-templates`, `inspections` (+`/{id}/submit`, `/approve`, `/reject`), `ncr`
(+`/{id}/start-correction`, `/verify`, `/close`), `concrete-samples` (+`/{id}/tests`). GET
tak digerbangi izin (sidebar disaring `qc.view`); tulis butuh `qc.create/update`, approve &
verify butuh `qc.approve`, hapus `qc.delete` (`qc.post` tak dipakai — dicatat di Deviasi).
Pesan 422 di bawah dikutip kata-demi-kata dari sumber dan dibuktikan oleh uji yang disebut.

Gerbang NCR — `POST /api/quality/inspections/{id}/submit` (kunci galat `status`), bukti
`NcrBlocksInspectionTest` (`InspectionService::submit`):

```
$ curl -sS -X POST .../api/quality/inspections/5/submit -H "Authorization: Bearer $T"
{"message":"...","errors":{"status":[
 "Inspeksi QCI/2026/III/0002 tahap Saat pelaksanaan tidak dapat diajukan: masih ada NCR
  terbuka di lokasi ini dari tahap sebelumnya — NCR/2026/III/0001 (Sebelum pelaksanaan,
  Terbuka). Selesaikan (verifikasi) NCR-nya dahulu sebelum melanjutkan ke tahap berikutnya."
]}}
```

Gerbang BAST I — `POST /api/projects/bast/{id}/approve` saat NCR terbuka (kunci galat
`prerequisites`), bukti `NcrBlocksHandoverTest` (`BastPrerequisiteService`):

```
"BAST I BAST/2026/IV/0001 belum dapat disetujui — 1 NCR masih terbuka (NCR/2026/III/0001);
 verifikasi atau tutup dahulu sebelum serah terima pertama."
```

Inspeksi (kata-demi-kata):
- `Lokasi yang dipilih bukan bagian dari proyek inspeksi ini.`
- `IPP yang dipilih berada pada proyek lain dan tidak dapat mendasari inspeksi ini.`
- `Butir hasil tidak termasuk dalam template inspeksi ini.`
- `Inspeksi %s berstatus %s dan tidak dapat diubah lagi.`

NCR (kata-demi-kata, `NcrService`):
- `Isi tepat satu penanggung jawab: karyawan sendiri ATAU subkontraktor, tidak keduanya dan
  tidak kosong.`
- `Tahap NCR wajib diisi bila tidak mengacu pada inspeksi.`
- `Inspeksi yang dirujuk berada pada proyek lain dan tidak dapat menjadi asal NCR ini.`
- `Lokasi yang dipilih bukan bagian dari proyek NCR ini.`
- `NCR %s berstatus %s dan tidak dapat %s dari status itu.` (`%s` verb: memulai perbaikan /
  memverifikasi / menutup)
- `NCR %s sudah ditutup dan tidak dapat diubah lagi.`

Benda uji (kata-demi-kata, `ConcreteStrengthService`):
- `Mutu beton "%s" tidak dikenali; gunakan K-xxx (kubus, kg/cm²) atau fc'-xx (silinder, MPa).`
- `Umur uji %d hari tidak ada pada tabel kematangan PBI 1971 (7, 14, atau 28 hari); pass/fail
  > Catatan kejujuran: pesan ini adalah pertahanan lapis-service; endpoint-nya
  > menolak `age_days` di luar {7,14,28} lebih dulu di validasi (Rule::in) dengan
  > pesan validasi standar. Pesan service hanya muncul bila service dipanggil langsung.
  hanya dihitung pada umur baku, bukan ditebak.`

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- **PANDUAN-PENGGUNA**: bab baru **§17 Mutu — inspeksi, NCR, benda uji beton** (siapa boleh apa,
  QCI + gerbang NCR, NCR + daur + dua pintu yang ditahan, benda uji + rumus bersumber, template
  + impor, cetak) + daftar isi; **§0** tabel rujukan peran (PM & site-manager → §17); **§13.1/
  §13.3** 45→48 + tabel formulir Mutu (F/QI, F/NCR, F/BU); **§13.5** tiga baris kejujuran (F/QI
  HASIL KESELURUHAN kosong tanpa butir, F/NCR kolom verifikasi kosong, F/BU MEMENUHI dihitung /
  TARGET fc' kosong pada mutu tak terbaca). Bab baru diletakkan sebagai **§17** (append setelah
  §16 Engineering), **bukan** disisipkan di urutan-alur — alasan sama seperti P1-ENG: menyisipkan
  akan merenomori bab yang dirujuk 100+ tautan silang dan daftar-baca roadmap §1.
- **PANDUAN-ADMINISTRATOR**: §2 "tiga belas modul"→"empat belas" (+ paragraf modul Quality, TOC,
  anchor; Core "cetak 45 formulir"→"48", "dipakai dua belas modul"→"tiga belas"); §3.1 "80 izin"→
  "86" (prefiks `qc`, 78→84), "sepuluh izin" hipotetis→"dua belas", "empat belas izin tak
  menjaga"→"lima belas" (+`qc.post`); §3.2 tabel jumlah izin (admin 80→86, direktur 28→30
  "keempat belas modul", PM 20→24 +`qc`+`qc.approve`, site-manager 8→11 +`qc`) + baris approve
  `qc.approve`; §3.3 "80 dari 80"→"86", "seluruh 80"→"86"; §3.4 admin-only 17→19
  (+`qc.delete`,`qc.post`),
  "kelima izin delete"→"keenam" (+`qc`), "empat belas izin tak menjaga"→"lima belas"; §9 "45
  formulir"→"48" (38→41 registri), lanskap "11 dari 45"→"11 dari 48" (ketiga formulir Mutu
  potret).
- **README**: baris modul Quality (`api/quality`); "Seluruh 13 modul"→"14".
- **ARCHITECTURE.md**: blok panah dependensi P1-QC (Quality → Projects/Engineering/Core; Projects
  ┈▶ `qc_ncr` sebagai pembaca tabel, bukan dependensi kode) + alur dokumen "Quality (inspection →
  NCR → concrete test)".
- **CONVENTIONS.md**: baris registri Quality (`api/quality`/`qc_`/001400–001499) + katalog
  41 registri + 7 proyek = 48 sudah ditambah lane backend — dikonfirmasi, tidak diubah lagi.

## Yang sengaja tidak dikerjakan, dan mengapa

- **`ExternalApprovableDocuments` untuk inspeksi** tidak diisi — instruksi lane (seam-only,
  seperti SDS/SMS di P1-ENG). MK menyetujui = eksternal transisi; hari ini inspeksi memakai
  maker-checker internal `qc.approve` dan `witness_party` sebagai fakta tercatat.
- **`qc.post` tidak dipakai** — modul Quality tidak memposting ke buku besar maupun menggerakkan
  stok; `pass` benda uji **dihitung**, tidak "diposting". Izin tetap dicetak oleh
  `PREFIXES × ACTIONS` (skema seragam) tetapi tak menggerbangi rute apa pun — sudah masuk
  daftar "izin tak menjaga apa pun" (§3.1 ADMINISTRATOR).
- **ADMINISTRATOR §3.1 "Ke-18 rute submit"** tidak diubah — angka itu sudah usang **sebelum**
  P1-QC (kini 23 termasuk submit inspeksi; sudah hanyut ke 22 sebelum paket ini), jadi bukan
  delta bersih paket ini; dicatat di Deviasi baru (sama seperti P1-ENG mencatatnya).
- **Q-Plan sebagai dokumen tersendiri & Stock Card Beton (FM-10-25) penuh** tidak dibangun —
  template inspeksi mengisi peran Q-Plan, `qc_concrete_samples` mencatat pengecoran; kartu stok
  beton penuh di luar cakupan roadmap P1-QC.
- **Perbaikan hard-delete butir template yang sudah dirujuk** tidak dilakukan (lihat Deviasi #1)
  — bukan pin DoD; mitigasi UI/dokumentasi sudah ada, perbaikan skema milik lane backend.

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **`InspectionTemplateService::replaceItems` & seeder hard-delete butir template, padahal
   `qc_inspection_results.template_item_id` adalah FK constrained.** Mengimpor ulang atau
   menyunting template yang butirnya sudah dirujuk inspeksi terisi akan FK-gagal dengan 500
   mentah. Tak terjangkau uji DoD (tak ada uji menyunting template terpakai) dan seeder demo
   menghindarinya (butir disemai sekali). Mitigasi kini: catatan formulir di `quality/inspection-
   templates` + peringatan §17.5 PANDUAN ("buat template baru"). **Perbaikan sungguhan** (milik
   lane backend): snapshot `check_text`/`acceptance` ke `qc_inspection_results` saat inspeksi,
   ATAU tolak penggantian butir dengan 422 bersih selama template masih dirujuk.
2. **Komentar `PermissionSeeder::DIRECTOR_APPROVALS` masih berbunyi "thirteen prefixes … eleven
   permissions"** setelah `qc` masuk `PREFIXES` (seharusnya *fourteen/twelve* dalam hipotesis
   itu — ekspansi `approve-director` ke 14 prefiks mencetak 12 izin tak-diperiksa). Komentar
   hipotetis, tak berdampak fungsi; ADMINISTRATOR §3.1 sudah diperbarui ke "empat belas prefiks
   … dua belas izin", jadi doc kini **lebih benar** dari komentar kode yang dirujuknya. Perbaikan
   satu-baris milik lane backend. (Deviasi kembar dicatat P1-ENG untuk "twelve→thirteen".)
3. **ADMINISTRATOR §3.1 "Ke-18 rute submit" usang** — kini **23** rute `.../submit` (termasuk
   `quality/inspections/{id}/submit`), sudah hanyut ke 22 sebelum P1-QC (izin lapangan P0-C +3,
   IPP P1-ENG +1 tak pernah dibumikan pada angka ini). Bukan delta paket ini; perlu sapuan
   dokumentasi tersendiri yang menghitung ulang seluruh rute `submit`.
4. **`config('erp.documents')` menyimpan mask QCI & NCR, tetapi benda uji tak punya nomor
   dokumen** — pilihan sengaja (F/BU mencetak identitas pengecoran, bukan kode yang dicetak
   sistem; menjaga paket ke dua mask yang dipin seam). Dicatat agar tak disangka kelalaian: benda
   uji diidentifikasi oleh pengecorannya (tgl/lokasi/truk), bukan nomor dokumen.
