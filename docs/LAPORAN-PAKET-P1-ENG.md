# Laporan Paket P1-ENG — Modul baru `Engineering`

Branch: feat/p1-eng · Commit: (belum di-commit — commit milik orkestrator; HEAD dasar
4b63619) · Tanggal: 2026-08-28

Modul baru `Engineering`: register shop drawing, submittal gambar (SDS) & material (SMS)
dengan empat stempel keputusan MK, transmittal + tanda terima, Ijin Pelaksanaan Pekerjaan
(IPP) bergerbang submittal-disetujui, dan `core_locations` hierarkis. Dependensi
Engineering → Projects, Estimation, Core (tidak sebaliknya); Inventory → Engineering (bon
menunjuk IPP).

## Yang ditutup (baris Laporan Deviasi v2 → status baru)

| Baris | Sebelum | Sesudah | Bukti (berkas:baris / nama uji) |
|---|---|---|---|
| 3.3 Daftar Rencana Persetujuan Shop Drawing (FM-10-01) | ⬜ | ✅ | `eng_drawings`; `DrawingSubmittalTest::test_a_drawing_registers_with_its_own_number_and_starts_unsubmitted` |
| 3.3 Form Persetujuan Drawing (FM-10-03) + status stempel | ⬜ | ✅ | `eng_drawing_submittals` (decision/decided_at/notes); `DrawingSubmittalTest::test_recording_a_decision_stamps_the_submittal_and_mirrors_the_drawing_status` |
| 3.3 Monitoring Shop Drawing (FM-10-21) | ⬜ | ✅ | `eng_drawings.status` cermin dari SDS; `DrawingSubmittalTest::test_a_new_revision_supersedes_the_previous_one_in_the_same_transaction` |
| 3.3 Form Persetujuan Material (FM-10-05) / Daftar (FM-10-22) | ⬜ | ✅ | `eng_material_submittals`; `MaterialSubmittalTest::test_a_material_submittal_gets_an_sms_number` |
| 3.3 IPP — Ijin Pelaksanaan Pekerjaan (FM-10-11) | ⬜ | ✅ | `eng_work_permits_ipp` (+lines); `IppGateTest` (11 uji, gerbang kriteria #2) |
| 3.3 Transmittal | ⬜ | ✅ | `eng_transmittals` + `eng_transmittal_lines`; `TransmittalTest` (4 uji) |
| 3.3 Stempel status dokumen | ⬜ | ✅ | `SubmittalDecision` (4 nilai) + `DrawingStatus` cermin; `DrawingSubmittalTest::test_a_decision_outside_the_four_stamps_is_refused` |
| 3.3 Standard gambar DWG (FM-10-04) | ⬜ 🔬 | 🔬 tetap | di luar cakupan (kebijakan `.dwg` P0-D); lampiran gambar riding SDS via `AttachableDocuments` |
| D10 `location_tree` (string bebas) | ⬜ | ✅ | `core_locations` hierarkis; `LocationTest` (6 uji, invarian induk/siklus/hapus-beranak) |
| Kriteria #2 — IPP ditolak bila drawing/material belum approved | ⬜ | ✅ | `IppService::submit`; `IppGateTest::test_the_gate_names_every_blocker_at_once` |
| Kriteria #3 — Bon → cost code & IPP; Ra-Ri otomatis | 🟡 | 🟡 (lebih maju) | bon menunjuk IPP & mewarisi WBS (`IssueIppLinkTest`); Ra-Ri otomatis belum |
| FM-10-10 Bon Pengambilan — "tanpa tautan IPP" | ✅ (caveat) | ✅ | `inv_issues.ipp_id`; `IssueIppLinkTest::test_a_bon_pointing_at_an_ipp_inherits_its_wbs_task` |

## Asumsi yang dipakai (dari Bagian 2) dan yang perlu dikonfirmasi

- **Distribusi izin `eng.*`** diputuskan dari realitas `RoleSeeder`: gambar/submittal
  disiapkan drafter (`estimator` = "Drafter/Estimator" Made Wirawan) dan site, otorisasi
  internal + pencatatan stempel MK di tangan `project-manager` (`eng.approve`, sama seperti
  `prj.approve` yang menandatangani izin lapangan P0-C), `procurement` hanya `eng.view`
  (mencegah beli material belum disetujui). Perlu konfirmasi pemilik bila ingin peran teknik
  terpisah.
- **Keputusan MK = fakta yang diketik, bukan trait `Approvable`.** SDS/SMS memakai endpoint
  `decision` (kolom tercatat), bukan submit→approve — sejalan keputusan pemilik #6 (pihak
  eksternal bukan baris `users`). Hanya IPP yang `Approvable`.
- **Asimetri gerbang** (spec verbatim): baris gambar lolos pada `approved`/`approved_as_noted`;
  baris material menuntut `approved` penuh. Diambil apa adanya dari teks spec.
- **Lokasi digerbangi `prj.*`, bukan `core.*`** (preseden `ProjectPhotoController`): data tapak
  milik sisi proyek, `core.*` milik admin.

## Skema yang berubah (tabel, kolom, migrasi) — dan apakah migrasi aman di MySQL dengan data lama

Blok migrasi `001300–001399` (Engineering), plus satu tabel Core dan dua ALTER lintas modul.

| Migrasi | Tabel | Catatan |
|---|---|---|
| `2026_08_28_000190` | `core_locations` | project_id (unsignedBigInteger+index, TANPA FK), parent_id, kind, code (unik), name, sort_order; softDeletes |
| `2026_08_28_001300` | `eng_drawings` | project_id, number, title, discipline, planned_submit_date, status |
| `2026_08_28_001310` | `eng_drawing_submittals` | drawing_id, revision, submitted_at, reviewer_party, decision, decided_at, notes, superseded_at/by_id, created_by |
| `2026_08_28_001320` | `eng_material_submittals` | project_id, item_id, material_name, brand, spec_reference, sample_attached, decision… |
| `2026_08_28_001330` | `eng_transmittals`, `eng_transmittal_lines` | header direction/to_party/received_by/at; lines morph (document_type/id) atau teks bebas |
| `2026_08_28_001340` | `eng_work_permits_ipp` (+`_materials`,`_equipment`,`_drawings`,`_material_approvals`) | header scope/location_id/planned_start/duration/status; empat set baris |
| `2026_08_28_001350` | `eng_work_permits_ipp` | ALTER: `wbs_task_id` nullable (unsignedBigInteger+index, TANPA FK) |
| `2026_08_28_000445` | `inv_issues` | ALTER: `ipp_id` nullable (unsignedBigInteger+index, TANPA FK) |

**Aman di MySQL dengan data lama.** Tabel `eng_`/`core_locations` baru. Kedua ALTER bersifat
aditif: kolom `nullable`, ber-index, **tanpa constraint**, dijaga `hasTable`/`hasColumn`
(idempoten) dengan `down()` yang men-drop index+kolom. Tidak ada kolom lama diubah/dihapus;
baris `inv_issues` lama tetap valid dengan `ipp_id = NULL`. Lintas-modul memakai
`unsignedBigInteger` + `index` (CONVENTIONS §3), tanpa `constrained()`.

## Uji

- baru: 55
  - `tests/Feature/Engineering/DrawingSubmittalTest.php` (10) — register, nomor unik, SDS,
    catat keputusan, pencatat≠pengaju, sekali-catat, empat stempel, supersede sat-transaksi,
    revisi tergantikan menolak keputusan, label revisi unik
  - `tests/Feature/Engineering/MaterialSubmittalTest.php` (4) — nomor SMS, catat keputusan
    label Indonesia, pencatat≠pengaju, submittal berkeputusan menolak edit
  - `tests/Feature/Engineering/TransmittalTest.php` (4) — nomor TRM + baris, jenis tak dikenal,
    dokumen proyek lain ditolak, tanda terima mengunci
  - `tests/Feature/Engineering/IppGateTest.php` (11) — nomor+draf, gerbang (gambar undecided /
    revise_resubmit / superseded / material unapproved), approved & approved_as_noted membuka,
    siklus penuh maker-checker + notifikasi eng.approve, approved_as_noted material tetap blok,
    gerbang sebut semua penghambat, WBS paket-pekerjaan, submittal se-proyek
  - `tests/Feature/Engineering/EngineeringSeederTest.php` (2) — skip anggun tanpa proyek kanon,
    seed 3 gambar/2 submittal/1 IPP idempoten
  - `tests/Feature/Engineering/EngineeringFormPrintTest.php` (10) — F/SD keputusan dari DB /
    null=menunggu / tergantikan; F/SM material+keputusan / null=menunggu; F/TR baris+terima /
    belum-terima kosong; F/IPP empat tabel / baris undecided menunggu; cetak butuh eng.view
  - `tests/Feature/Core/LocationTest.php` (6) — hirarki via API, induk proyek lain, siklus,
    hapus-beranak, tulis butuh prj.create bukan core, impor master-data konvergen dua-lari
  - `tests/Feature/Inventory/IssueIppLinkTest.php` (8) — bon warisi WBS, baris menang atas
    kepala, kepala bentrok ditolak, IPP proyek lain, IPP belum approved, konfirmasi tanpa IPP,
    tanpa konfirmasi bila tak ada IPP aktif, bon kantor tak butuh konfirmasi
- lama yang diubah: 2
  - `tests/Unit/Core/DocumentFormatValidationTest.php` — `SHIPPED_DOCUMENT_TYPES` 38→42
    (tambah SDS/SMS/TRM/IPP di `config('erp.documents')`)
  - `tests/Feature/Core/PrintCatalogueBespokeTest.php` — katalog 41→45 (4 formulir registri
    Engineering baru)
- suite penuh: OK (3186 uji, 14555 asersi, 6m55s) — `vendor/bin/phpunit`, 2026-08-28

## Smoke test curl (endpoint baru, pesan 422 yang dijanjikan — kutip kata demi kata)

Endpoint baru (semua di bawah `auth:sanctum`): `api/engineering/drawings`,
`api/engineering/drawing-submittals` (+`/{id}/decision`), `api/engineering/material-submittals`
(+`/{id}/decision`), `api/engineering/transmittals` (+`/{id}/terima`), `api/engineering/ipp`
(+`/{id}/submit`, `/approve`, `/reject`), `api/core/locations` (gerbang `prj.*`). Pesan 422
di bawah dikutip kata-demi-kata dari sumber dan dibuktikan oleh uji fitur yang disebut.

Gerbang IPP — `POST /api/engineering/ipp/{id}/submit` (kunci galat `status`), bukti
`IppGateTest`:

```
$ curl -sS -X POST .../api/engineering/ipp/7/submit -H "Authorization: Bearer $T"
{"message":"...","errors":{"status":[
 "IPP IPP/2026/III/0001 tidak dapat diajukan: gambar SDS/2026/III/0002 (GSP-ST-101 R1)
  masih menunggu keputusan Konsultan MK; material SMS/2026/III/0001 (Ready Mix K-300)
  berkeputusan Disetujui dengan catatan — baris material menuntut keputusan Disetujui penuh;
  bereskan catatannya dan ajukan ulang submittal-nya. Selesaikan persetujuan MK-nya dahulu."
]}}
```

Tiap penghambat (kata-demi-kata, `IppService::submit`):
- `gambar %s (%s %s) masih menunggu keputusan %s`
- `gambar %s (%s %s) berkeputusan %s`
- `gambar %s (%s %s) telah digantikan revisi %s — rujuk revisi terbarunya`
- `material %s (%s) masih menunggu keputusan %s`
- `material %s (%s) berkeputusan Disetujui dengan catatan — baris material menuntut keputusan
  Disetujui penuh; bereskan catatannya dan ajukan ulang submittal-nya`

Catat keputusan MK — `POST /api/engineering/drawing-submittals/{id}/decision` (kunci
`decision`), bukti `DrawingSubmittalTest`:
- `Pencatat keputusan tidak boleh orang yang mengajukan submittal %s sendiri — minta pemegang
  eng.approve lain mencatat lembar stempel MK.`
- `Keputusan %s sudah tercatat untuk %s pada %s dan tidak dapat ditimpa; bila lembar stempel
  berbeda, ajukan revisi baru.`
- `Submittal %s telah digantikan revisi %s; keputusan MK dicatat pada revisi terbarunya.`

Bon menunjuk IPP — `POST /api/inventory/issues` (kunci `ipp_id`/`wbs_task_id`), bukti
`IssueIppLinkTest`:
- `Proyek ini memiliki IPP aktif: %s. Pilih IPP yang mendasari pengeluaran ini agar bon
  mewarisi paket pekerjaannya, atau ajukan ulang dengan konfirmasi bila bon ini memang di luar
  cakupan IPP.` (kirim ulang dengan `confirm_without_ipp=true` untuk melewati)
- `IPP %s milik proyek lain dan tidak dapat menjadi dasar bon proyek ini.`
- `IPP %s masih berstatus %s; hanya IPP yang disetujui yang dapat menjadi dasar pengeluaran
  material.`
- `Bon menunjuk %s yang paket pekerjaannya WBS %s, tetapi tugas WBS bon diisi %s. Kosongkan
  tugas WBS agar diwarisi dari IPP, atau lepaskan IPP-nya bila bon ini untuk pekerjaan lain.`

Transmittal — `POST /api/engineering/transmittals/{id}/terima` & lines, bukti `TransmittalTest`:
- `Jenis baris "%s" tidak dikenal. Yang tersedia: drawing_submittal, material_submittal, lainnya.`
- `Dokumen %s berada pada proyek lain dan tidak dapat dimuat pada transmittal proyek ini.`
- `Tanda terima %s sudah dicatat atas nama %s pada %s.`

Lokasi — `POST/DELETE /api/core/locations`, bukti `LocationTest`:
- `Induk lokasi %s berada pada proyek lain; induk dan anak harus pada proyek yang sama.`
- `Lokasi %s tidak boleh menjadi induk dari dirinya sendiri (siklus hirarki).`
- `Lokasi %s masih memiliki %d sub-lokasi; hapus atau pindahkan dulu sub-lokasinya.`

## Dokumentasi yang diperbarui (PANDUAN §…, README, CONVENTIONS, ARCHITECTURE)

- **PANDUAN-PENGGUNA**: bab baru **§16 Engineering** (gambar, SDS, SMS, transmittal, IPP,
  lokasi, cetak) + daftar isi; **§0** tabel rujukan peran (estimator/drafter, PM, site,
  procurement, warehouse → §16); **§6.5 bon** kolom IPP + peringatan konfirmasi + pesan
  bentrok/proyek-lain/belum-approved; **§13.1/§13.3** 41→45 + tabel formulir Engineering;
  **§13.5** dua baris kejujuran (submittal "Menunggu keputusan…", "DIGANTIKAN oleh…", F/IPP
  apa adanya). Bab baru diletakkan sebagai **§16** (append), **bukan** disisipkan di tengah:
  menyisipkan di urutan-alur akan merenomori §3–§15 yang dirujuk 100+ tautan silang internal
  DAN daftar-baca roadmap §1 (§7.3/§7.13/§13.3–13.5); urutan-alur disampaikan lewat tabel §0
  dan penunjuk-maju. §16 diletakkan setelah §15 karena §13–§15 adalah bab rujukan lintas-fitur,
  dan §15 (Persetujuan MK/Owner) bertetangga tema.
- **PANDUAN-ADMINISTRATOR**: §2 "dua belas modul"→"tiga belas" (+ paragraf modul Engineering,
  TOC, anchor); §3.1 "74 izin"→"80" (prefiks `eng`, 72→78), "13 izin tak menjaga"→"14"
  (+`eng.post`); §3.2 tabel jumlah izin per peran (admin 74→80, direktur 26→28 "ketiga belas
  modul", PM 16→20, estimator 7→10, procurement 7→8, site-manager 5→8) + baris approve
  `eng.approve`; §3.3 admin-only 15→17, "keempat izin delete"→"kelima" (+`eng`), 74→80; §9
  "41 formulir"→"45" (34→38 registri), baris `eng.view`, **prc 4→5** (menutup F/K3V yang
  tercecer sejak P0-E), lanskap "11 dari 41"→"11 dari 45".
- **README**: baris modul Engineering; "Seluruh 12 modul"→"13"; lampiran "31 jenis dokumen"→"33";
  "kedua belas jenis dokumen"→"setiap jenis dokumen" (angka Approvable sudah usang → 21).
- **ARCHITECTURE.md**: blok panah dependensi P1-ENG (Engineering → Estimation/Projects/Core;
  Inventory → Engineering) + catatan `core_locations` di Core; alur dokumen "Engineering
  (shop drawing → IPP → bon)".
- **CONVENTIONS.md**: baris registri Engineering (`api/engineering`/`eng_`/001300–001399)
  sudah ditambah lane backend — dikonfirmasi, tidak diubah lagi.

## Yang sengaja tidak dikerjakan, dan mengapa

- **`ExternalApprovableDocuments` untuk SDS/SMS** tidak diisi — instruksi lane; komentar seam
  bernama ada di kedua model submittal. Bila ditutup kelak: perluas `ExternalDecision` empat
  nilai ATAU petakan `approved_with_notes → approved_as_noted` + adapter `recordDecision`.
- **GlobalSearchService / CalendarEvents / WatchedDeadlines** tidak mendaftarkan Engineering —
  opsional, bukan pin; keputusan backend dipertahankan.
- **ADMINISTRATOR §3.1 "Ke-18 rute submit"** tidak diubah — angka itu sudah usang **sebelum**
  P1-ENG (kini 22 termasuk submit IPP; hanyut sejak izin lapangan P0-C menambah 3), jadi bukan
  delta bersih paket ini; dicatat di Deviasi baru untuk sapuan dokumentasi tersendiri.
- **Renomori PANDUAN-PENGGUNA §3–§15** tidak dilakukan (lihat alasan di bagian Dokumentasi).

## Deviasi baru yang Anda temukan (apa pun, sekecil apa pun)

1. **Seed demo: IPP disetujui menumpang submittal material `approved_as_noted`.**
   `EngineeringDatabaseSeeder::seedMaterialSubmittal` menstempel SMS "Ready Mix K-300"
   `approved_as_noted`, lalu `seedIpp` menautkannya sebagai baris `material_approvals` pada IPP
   yang di-`forceFill` **Approved**. Padahal gerbang (`IppService::submit`) menolak baris
   material yang bukan `approved` penuh — jadi demo menggambarkan IPP disetujui yang **tak akan
   pernah lolos gerbangnya sendiri lewat UI**, dan docblock seeder sendiri mengklaim "its lines
   MUST ride approved submittals". Suite tetap hijau karena seeder menulis status langsung
   (maker-checker butuh dua orang). **Keputusan pemilik**: ubah SMS seed menjadi `approved`,
   ATAU lepas baris `material_approvals` itu, ATAU terima sebagai snapshot sengaja. (Sisi
   docs; perbaikan seed milik lane backend.)
2. **README "kedua belas jenis dokumen yang memakai Approvable" sudah usang** —
   `ApprovableDocuments` kini 21 entri (bukan 12), hanyut sejak paket-paket P0. Digeneralkan
   menjadi "setiap jenis dokumen" agar tak menambah angka yang salah; hitung persisnya perlu
   audit tersendiri.
3. **ADMINISTRATOR §9 tabel formulir per modul kehilangan F/K3V** (Persyaratan K3L Vendor,
   P0-E): baris `prc.view` tertulis 4 padahal §13.3 PENGGUNA memuat 5. Diperbaiki 4→5 di sini
   agar tabel berjumlah 38 registri (rekonsiliasi 45 = 38 registri + 7 khusus proyek).
4. **`PermissionSeeder` komentar `DIRECTOR_APPROVALS` masih berbunyi "twelve prefixes … ten
   permissions"** setelah `eng` masuk `PREFIXES` (seharusnya tiga belas/sebelas dalam hipotesis
   itu). Komentar hipotetis, tak berdampak fungsi; dicatat untuk lane backend.
5. **ADMINISTRATOR §3.1 "Ke-18 rute submit" usang** (kini 22, termasuk `ipp/{id}/submit`) —
   lihat "Yang sengaja tidak dikerjakan".
