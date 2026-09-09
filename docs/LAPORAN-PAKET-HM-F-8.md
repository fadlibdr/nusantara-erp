# Laporan Paket HM F-8 — Kedaluwarsa lampiran umum; sikap e-sign

**Cabang:** `feat/phase2-f8` (dari `main` a5ca33d) · **Tanggal:** 9 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 2 baris F-8 (2 hari-orang)
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik)

---

## 0. Satu kalimat

Sebagian dokumen benar hanya sampai sebuah tanggal — polis CAR pada SPK, STNK/KIR pada kartu
aset, sertifikat kalibrasi pada lembar inspeksi — dan sebelum paket ini tanggal itu hidup
**hanya di dalam berkasnya**, jadi tidak seorang pun mengetahuinya sampai ada yang bertanya.
F-8 menambah satu kolom (`core_attachments.valid_until`), tiga pintu tulis, dua belas
kelompok alarm yang masing-masing berbicara kepada modul pemilik dokumennya — dan **satu
dokumen keputusan** yang menolak membangun e-sign.

Perangkap paketnya bukan menambah kolomnya; ia menambahkannya **tanpa membuat 40.000 foto
lapangan berteriak setiap pagi**.

---

## 1. Tugas → status → bukti

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T1 | Migrasi Core `core_attachments.valid_until` (date, nullable, maju-saja) | ✅ | `fc7c744` — `2026_09_09_001800_…`; kolom nullable tanpa bawaan, tanpa backfill (`AttachmentValidUntilSchemaTest`, 3 uji) |
| T2 | Blok migrasi lanjutan Core didaftarkan **di tabel CONVENTIONS §2**, pada commit pemakaian pertamanya | ✅ | `fc7c744` — baris `Core \| 000100–000199 \| **001800–001899** \| DIPAKAI`; prosa lama "belum dibutuhkan" diganti dengan sejarah pemakaiannya |
| T3 | Indeks yang perangkap D butuhkan, EXPLAIN **kedua driver** | ✅ | `fc7c744` — pasangan `(attachable_type, valid_until)`; SQLite tanpa ANALYZE **14,689 ms → 0,024 ms**, MySQL 8 **44,530 ms → 0,382 ms**; §3 di bawah |
| T4 | Pintu tulis saat unggah — **kedua transport** | ✅ | `950edb5` — `POST core/attachments` (JSON) dan `POST core/attachments/upload` (multipart); dua uji terpisah, mutasi M2 merah |
| T5 | Pintu ubah sesudahnya, izin **sama** dengan mengubah lampirannya | ✅ | `950edb5` — `PATCH core/attachments/{id}`, lewat `reachable()` yang sama dengan `destroy()`; 6 uji izin/penjaga induk, mutasi M4 merah |
| T6 | Validasi tanggalnya | ✅ | `950edb5` — `['present','nullable','date']`; `31-02-2026` → 422 dan **nol baris tersimpan**; mutasi M3 (`present`→`sometimes`) merah |
| T7 | Entri watcher Core-only, izin per baris dari `AttachableDocuments` (perangkap B) | ✅ | `b6237ed` — 12 entri `attachment_valid_until_<prefix>`, `{prefix}.update`; uji "dua modul, dua temuan, tidak saling membaca"; mutasi M9 merah |
| T8 | Induk yang hilang ditangani sadar (perangkap C) | ✅ | `b6237ed` — `EXISTS` per kelas atas kolom `table` literal baru; 3 uji (hapus lunak, hapus permanen, kelas di luar registri) + 1 uji induk hidup tetap berbunyi; mutasi M8 merah |
| T9 | **TANPA** `alarm_when_date_missing` (perangkap A) | ✅ | `b6237ed` — bendera `dateless_is_normal`; 60 lampiran tanpa tanggal = **0 temuan, 0 baris BLIND, 0 notifikasi**; mutasi M7 merah |
| T10 | Kartu lampiran menampilkan masa berlaku; "tanpa masa berlaku" **bukan** peringatan | ✅ | `26937a2` — empat keadaan; harness membaca KELAS lencananya: `badge_class` **null** untuk keadaan normal, `badge amber dot` menipis, `badge red dot` kedaluwarsa |
| T11 | Sikap e-sign sebagai TULISAN — nol kolom, nol pustaka, nol endpoint | ✅ | `7c636ba` — `docs/SIKAP-E-SIGN.md`; §7 dokumen itu berisi 4 perintah git yang memeriksanya sendiri, semuanya dijalankan (§8 di bawah) |
| T12 | Uji PHP untuk setiap perubahan server + mutasi merah | ✅ | **35 uji baru** (256 assertion) di 3 berkas + 3 uji ditambahkan ke 2 berkas lama; **14 mutasi dijalankan, 13 merah, 1 lolos dan dilaporkan** (§4) |
| T13 | Harness S35 desktop + ponsel → `results-phase-2.json` + PNG | ✅ | `0c2669f` — `[S35_kedaluwarsa_lampiran] ok`, `[S35_kedaluwarsa_lampiran_ponsel] ok`; 25 → 27 kunci, irisan kosong di-assert sebelum menulis; 6 PNG |
| T14 | Cangkang PWA | ✅ | `26937a2` — daftar SHELL **tidak berubah** (tidak ada berkas SPA baru), tetapi `app.css`, `js/api.js`, `js/views/attachments.js` semuanya berkas cangkang → `SHELL_VERSION` 6 → **7** |
| T15 | Muat `/app/` di Chromium, 0 galat konsol di tiap layar yang tersentuh | ✅ | **13 rute dimuat, `all_console_errors: []`, `http_4xx_5xx: []`** — §6 |
| T16 | Sapuan dokumentasi (CONVENTIONS §35) | ✅ | `7c636ba` — CONVENTIONS §37 baru, PANDUAN-PENGGUNA §1.7/§2.7/§5.9, PANDUAN-ADMINISTRATOR §5.8/§5.11; angka grep di §7 |

---

## 2. Tiga keputusan yang membuat atau menggagalkan paket ini

### (A) Tanpa masa berlaku adalah KEADAAN NORMAL — dan ia punya DUA cara membunuh fiturnya

Yang jelas: pola `alarm_when_date_missing` (PKWT, servis aset) tidak dipakai. Di sana tanggal
kosong berarti kelalaian; di sini ia berarti foto lapangan.

Yang **tidak** jelas, dan hampir lolos: `scan()` punya cabang KEDUA yang melakukan hal yang
sama dengan kalimat berbeda. Ketika sebuah entri diam, ia menghitung barisnya dan mencetak
`BLIND <kunci>: N baris dalam cakupan tetapi setiap tanggalnya NULL — diamnya di sini adalah
data yang hilang, bukan tanda aman`. Untuk lampiran, kalimat itu **bohong** dan akan tercetak
setiap pagi tentang puluhan ribu berkas yang memang tidak punya masa berlaku.

Benderanya `dateless_is_normal`, dan ia mahal untuk dilewatkan dalam dua arti. Diukur di
MySQL 8 atas 40.000 lampiran tanpa tanggal:

```
cabang BLIND, 12 entri lampiran ........................ 246,4 ms
  attachment_valid_until_prj sendirian ................. 172,4 ms
scan() penuh 34 entri, DENGAN bendera .................. 106,1 ms
```

Dua `COUNT(*)` per entri **tanpa saringan tanggal** adalah satu-satunya kueri di seluruh
registri yang menyentuh seluruh tabel; entri `prj` paling mahal karena semua 40.000 baris
bertipe `DailyReport`, jadi rantai OR delapan cabangnya dievaluasi untuk setiap baris tanpa
indeks tanggal yang memotongnya lebih dulu. Tanpa bendera, layar Tenggat menjadi **3,3×**
lebih lambat untuk mencetak 12 baris yang salah.

### (B) Izin per baris di atas tabel polimorfik — dipecah per modul, bukan dipilih satu

Sebuah temuan `WatchedDeadlines` membawa **satu** izin dan **satu** judul. `core_attachments`
menunjuk 40 jenis dokumen dari 12 modul. Satu entri untuk seluruh tabel berarti memilih satu
izin untuk semuanya, dan setiap pilihan salah:

- `core.update` → dipegang hanya admin dan direktur; pemilik sertifikat yang kedaluwarsa
  tidak pernah diberi tahu.
- Izin modul mana pun → pemegang `svc.update` membaca nama berkas sertifikat karyawan.

**Yang dipilih:** entrinya **dibangkitkan per prefix izin** dari `AttachableDocuments::byPrefix()`
— 12 entri, masing-masing `{prefix}.update`, izin yang sama persis dengan yang dituntut
`AttachmentController::deny()/reachable()` untuk mengubah lampiran itu. Registri yang
menerjemahkan kelas → prefix sudah tinggal di Core dan sudah memikul beban itu untuk endpoint
lampiran; memakainya di sini **tidak menambah satu pun impor modul fitur untuk DATA**.

Dibuktikan, bukan diklaim: satu lampiran pada tagihan vendor dan satu pada sertifikat
karyawan menghasilkan dua temuan berbeda, dan `GET core/deadlines` untuk pemegang `hr.update`
tidak memuat string `polis-car.pdf` sama sekali (dan sebaliknya).

### (C) Induk yang sudah tidak ada — dua bentuk, dua jawaban

| Bentuk | Jawaban | Biaya |
|---|---|---|
| `attachable_type` tidak ada di registri (kelas warisan/dihapus) | tidak pernah masuk cakupan — `whereIn` dibangun DARI registri | nol; aturan yang sama dengan 404 `reachable()` |
| Induk dihapus lunak / dihapus permanen | gugur lewat `EXISTS` per kelas atas kolom `table` **literal baru** di `AttachableDocuments` | satu `eq_ref` PRIMARY per kelas, atas baris yang sudah dipotong indeks tanggal |

Penjaga `deleted_at` duduk **di dalam** closure (pola `missing_scope` F-7), bukan di
`columns`: `hr_attendances` memang tidak menghapus-lunak, dan mendaftarkannya di `columns`
akan menggugurkan **seluruh** entri `hr`. Literal tabelnya dipaku
`AttachmentRegistryTest::test_every_parent_table_literal_matches_the_model_it_belongs_to`
terhadap model aslinya — 40 assertion, jadi tabel yang diganti nama di lane tim lain
menjatuhkan uji alih-alih diam-diam membuat setiap lampiran modul itu tampak yatim.

---

## 3. Indeks — diukur di kedua driver, dan angkanya mengubah keputusannya

Pilihan yang "jelas" adalah indeks satu kolom `valid_until`: kolomnya sangat selektif, hampir
semua baris NULL. **Pengukuran membantahnya.** 40.000 baris tiruan (38.577 foto laporan
harian, 59 bertanggal), kueri pengawas `attachable_type IN (…) AND valid_until <rentang>`:

| Driver | Indeks | Rencana | Waktu |
|---|---|---|---|
| SQLite (tanpa ANALYZE — **keadaan produksi**) | `(valid_until)` | `SEARCH … USING INDEX …attachable_type_attachable_id…` | **14,689 ms** |
| SQLite | `(valid_until, attachable_type)` | idem — indeks barunya tidak tersentuh | **14,758 ms** |
| SQLite | **`(attachable_type, valid_until)`** | `SEARCH … USING COVERING INDEX …type_valid_until…` | **0,024 ms** |
| MySQL 8 | tanpa indeks baru | `type=ALL key=NULL rows=39.844 Using where` | **44,530 ms** |
| MySQL 8 | `(valid_until)` | `type=range rows=34 Using index condition` | **0,276 ms** |
| MySQL 8 | **`(attachable_type, valid_until)`** | `type=range rows=35 Using where; Using index` | **0,382 ms** |

Perencana SQLite **tanpa statistik selalu** memilih indeks kesetaraan yang sudah ada
(`attachable_type, attachable_id`) lalu menyaring tanggal baris demi baris atas 38.577 foto;
indeks `valid_until` sendirian tidak pernah dibuka. Repo ini tidak pernah menjalankan
`ANALYZE`, jadi "sesudah ANALYZE" bukan keadaan yang boleh dipakai memutuskan. Hanya pasangan
berawalan `attachable_type` dipakai **kedua** driver apa adanya, dan di keduanya ia COVERING.

`EXPLAIN` kueri registri **yang sebenarnya** (dengan rantai penjaga induk), MySQL 8,
`erp_dryrun`, 40.000 lampiran:

```
attachment_valid_until_prj  PRIMARY  core_attachments type=range key=…type_valid_until… rows=40  Using index condition; Using where
                            DEPENDENT SUBQUERY ×8     type=eq_ref key=PRIMARY           rows=1
                            1,357 ms/kueri
attachment_valid_until_fin  PRIMARY  core_attachments type=range key=…type_valid_until… rows=6   Using index condition; Using where
                            DEPENDENT SUBQUERY ×6     type=eq_ref key=PRIMARY           rows=1
                            0,846 ms/kueri
scan() penuh, 34 entri:     109,8 ms
```

Rencana kueri registrinya sendiri **dipaku uji**
(`test_the_registry_scope_itself_is_planned_through_the_pair_index`), bukan hanya bentuk
sederhananya — karena yang bisa membusuk diam-diam adalah kueri yang benar-benar dijalankan.

---

## 4. Mutasi — 14 dijalankan, 13 merah, **1 LOLOS** dan dilaporkan begitu

| # | Mutasi | Hasil |
|---|---|---|
| M1 | Indeks disederhanakan menjadi `(valid_until)` | **MERAH** — 2 uji; pesan gagalnya mencetak rencana kueri yang jatuh kembali ke indeks lama |
| M2 | Aturan `valid_until` dicabut dari rute **multipart** saja | **MERAH** — 1 uji (rute JSON tetap hijau; itulah gunanya dua uji terpisah) |
| M3 | `'present'` → `'sometimes'` pada pintu ubah | **MERAH** — PATCH tanpa kuncinya berhenti ditolak, tanggal orang lain terhapus |
| M4 | Pintu ubah memakai izin `view` alih-alih `update` | **MERAH** — pemegang `fin.view` bisa menulis |
| M5 | Batas menipis `<` alih-alih `<=` | **MERAH** — hari ke-30 berhenti berbunyi |
| M6 | `isExpired()` memakai `<=` (hari terakhir dianggap lewat) | **MERAH** |
| M7 | `dateless_is_normal` dicabut | **MERAH** — 2 uji; 60 foto biasa menjadi baris BLIND |
| M8 | Penjaga induk-hidup dicabut | **MERAH** — 2 uji; lampiran dokumen yang dibuang berbunyi lagi |
| M9 | `permission` diseragamkan menjadi `core.update` | **MERAH** — 4 gagal + 4 error |
| M10 | `valid_through_end` → `false` | **MERAH** — hari terakhirnya menjadi "lewat" |
| M11 | `calendar_source => false` dicabut | **MERAH** di DUA berkas — `GET core/calendar` melempar (prefix `core` tanpa departemen), dan `CalendarEventsTest` 4 error |
| M12 | `whereIn('attachable_type', …)` dicabut, rantai OR saja | **LOLOS HIJAU** — lihat di bawah |
| M13 | Keadaan normal digambar sebagai lencana kuning di SPA | **MERAH** — `AttachmentSpaPolicyTest` |
| M14 | Cabang `'menipis'` dihapus dari kartu (jatuh ke bawaan) | **MERAH** — `AttachmentSpaPolicyTest` |

**Satu paku lama harus diperbaiki, bukan dilonggarkan.**
`CalendarEventsTest::test_the_department_map_covers_every_source_prefix_with_a_view_permission`
menghitung `count(WatchedDeadlines::entries()) + 7` dan berbunyi merah (29 ≠ 41) begitu 12
entri lampiran memilih keluar dari kalender — persis pelajaran 4 (paku yang menyapu seluruh
aplikasi merah ketika modul lain memilih nama yang wajar). Ia **tidak** dilonggarkan menjadi
`assertGreaterThan`: invariannya ditulis ulang menjadi yang sebenarnya berlaku — *setiap entri
yang tidak memilih keluar, plus tujuh sumber khusus kalender* — **plus** paku baru bahwa yang
memilih keluar benar-benar tidak ada di daftar sumber, dengan jumlahnya (**12**) ditulis
sebagai LITERAL. Membaca angka itu kembali dari registrinya akan membuat barisnya hijau untuk
angka berapa pun, termasuk nol — dan nol persis seperti bendera yang lupa dipasang.

**M12 lolos, dan itu jujur.** `whereIn` di depan rantai OR **redundan untuk kebenaran**:
tiap cabang OR sudah memaku `attachable_type = <kelas>`. Yang hilang tanpa dia adalah bentuk
rencananya, dan diukur ternyata **bukan bencana** — SQLite beralih ke `MULTI-INDEX OR`, enam
pencarian terpisah pada indeks pasangan yang sama alih-alih satu rentang ber-`IN`:

```
dengan whereIn : SEARCH core_attachments USING INDEX …type_valid_until… (attachable_type=? AND valid_until>? AND valid_until<?)
tanpa whereIn  : MULTI-INDEX OR → 6 × SEARCH core_attachments USING INDEX …type_valid_until… (attachable_type=? AND valid_until<?)
```

Jadi paku rencana kueri pun **tidak** menangkapnya: indeksnya tetap dipakai. Ia dibiarkan
lolos alih-alih dipaksa merah dengan uji yang mencocokkan teks rencana kueri kata demi kata —
paku seperti itu akan merah pada versi SQLite berikutnya tanpa satu pun perilaku berubah.
Yang ditulis sebagai gantinya: alasannya ada di komentar `scope`, dan di sini.

**Uji yang memakai konstanta produksi — diperiksa satu per satu.** Dua uji menyebut jendela
30 hari (`test_the_thirtieth_day_before_the_date_is_the_first_warning_day`,
`test_the_warning_window_is_thirty_days_on_both_surfaces`). Keduanya **memaku angkanya**
(31 hari lagi = diam, 30 hari lagi = berbunyi, plus `assertSame(30, …VALID_UNTIL_LEAD_DAYS)`),
bukan menyusun harapan dari konstantanya — mutasi M5 membuktikannya merah. Uji yang memakai
`AttachableDocuments` hanya untuk **bentuk** (setiap prefix punya dokumen) dan tetap memaku
daftar 12 prefixnya sebagai literal.

---

## 5. Permukaan — daftar lengkap aturan baru, diperiksa satu per satu

Cacat berulang kampanye ini: aturan ditegakkan di satu permukaan dan bocor di permukaan lain
yang sama. Aturan barunya dua: **(i) siapa boleh menulis masa berlaku** dan **(ii) bagaimana
keadaannya dibaca**.

| Permukaan | (i) tulis | (ii) baca | Status |
|---|---|---|---|
| `AttachmentService::store()` (JSON) | parameter diteruskan | — | ✅ |
| `AttachmentService::storeBinary()` (multipart, satu jalur untuk keduanya) | parameter diteruskan | — | ✅ |
| `AttachmentController::store` | validasi + `fin.update`-nya dokumen | — | ✅ |
| `AttachmentController::upload` | validasi + izin yang sama | — | ✅ |
| `AttachmentController::update` (**baru**) | `reachable(…, 'update')` + `present` | mengembalikan `validity` baru | ✅ |
| `AttachmentController::index` | — | `validity` per baris | ✅ |
| `Attachment` model | cast `date:Y-m-d` | `$appends = ['validity']` — **satu aturan, di server** | ✅ |
| `WatchedDeadlines` (12 entri) | — | tingkat MENIPIS/LEWAT, `valid_through_end` | ✅ |
| `DeadlineController` (layar Tenggat) | — | menyaring per izin entri | ✅ (tanpa perubahan) |
| `DeadlineWatchCommand` (08.30) | — | badan pesan + klausa "menempel pada <Dokumen> #id" | ✅ (tanpa perubahan) |
| `CalendarEvents` | — | **sengaja tidak** — `calendar_source => false`, §9 | ✅ keputusan tertulis |
| Kartu lampiran SPA (`views/attachments.js`) | kotak unggah + dialog per baris | 4 keadaan, normal = teks polos | ✅ |
| Galeri Foto Proyek (`ProjectPhotoController`) | — | **tidak** — proyeksinya memilih kolom secara eksplisit, dan foto progres tidak punya masa berlaku | ✅ diperiksa, sengaja |
| Kartu selfie absensi (`views/absensi.js`) | mewarisi kartu yang sama | mewarisi | ✅ otomatis |
| Layar detail generik + Proyek | mewarisi `attachmentsCard()` | mewarisi | ✅ otomatis |
| Antrean unggah lapangan (`uploadqueue.js`) | tidak menawarkan tanggal | — | ✅ sengaja — yang naik dari lapangan adalah foto progres |
| Cetakan / ekspor | — | tidak ada formulir cetak yang memuat daftar lampiran | ✅ diperiksa (`PrintableDocuments`) |
| Persetujuan eksternal (lembar fisik) | — | lampirannya bukti tanda tangan, bukan dokumen bermasa berlaku | ✅ diperiksa |

---

## 6. Bukti peramban (pelajaran P1-I: rilis SPA belum terverifikasi sampai DIMUAT)

`php -S 127.0.0.1:8201` atas **salinan** `database/database.sqlite` di scratchpad (data demo
hidup tidak disentuh), migrasi 001800 dijalankan pada salinan itu. Tiga lampiran ditanam lewat
API, dibersihkan sesudahnya. Chromium headless, satu sesi, 13 rute:

| Rute | h1 | Galat konsol |
|---|---|---|
| `#/dashboard` | Selamat sore, Administrator | 0 |
| `#/home` | Beranda | 0 |
| `#/tenggat` | Tenggat | 0 |
| `#/kalender` | Kalender | 0 |
| `#/ambang` | Ambang & Batas | 0 |
| `#/tugas` | Tugas Saya | 0 |
| `#/m/fin` | Keuangan | 0 |
| `#/r/finance/ap-bills` | Tagihan Vendor (AP) | 0 |
| `#/d/finance/ap-bills/1` | BIL/2026/III/0001 | 0 |
| `#/r/procurement/vendor-documents` | Dokumen Vendor | 0 |
| `#/r/hr/certificates` | Sertifikat | 0 |
| `#/stock` | Saldo Stok | 0 |
| `#/laporan-bebas` | Laporan Bebas | 0 |

```
routes_loaded: 13 / 13
all_console_errors: []
http_4xx_5xx: []
```

`#/m/fin` ikut diuji **karena ia tautan `Buka` entri lampiran keuangan**: sebuah alarm yang
membuka layar yang tidak ada adalah alarm yang lebih buruk daripada tidak ada alarm.

**Harness S35** (`docs/bukti-uji/harness-playwright.py`, dua kunci baru di
`results-phase-2.json`):

```
[S35_kedaluwarsa_lampiran] ok 9517ms clicks=4
[S35_kedaluwarsa_lampiran_ponsel] ok 7302ms clicks=2
```

Yang diukurnya, dan yang **tidak bisa** dibuktikan suite PHP — **kelas lencananya**:

| Berkas | Teks | `badge_class` |
|---|---|---|
| `foto-lapangan.pdf` (tanpa tanggal) | `Tanpa masa berlaku` | **null** |
| `polis-car.pdf` (+12 hari) | `Berlaku s/d 22 Sep 2026 · 12 hari lagi` | `badge amber dot` |
| `izin-kerja.pdf` (hari terakhirnya) | `Berlaku s/d 10 Sep 2026 · hari ini` | `badge amber dot` |
| `sertifikat-kalibrasi.pdf` (−3 hari) | `Kedaluwarsa 07 Sep 2026 · 3 hari lalu` | `badge red dot` |

…dan bahwa layar Tenggat menyebut **kedua** berkas yang dilencanai kartu, **tidak** menyebut
yang tanpa masa berlaku, serta bahwa dialognya benar-benar menulis dan tombol `Kosongkan`
benar-benar mengembalikan barisnya ke keadaan normal.

PNG: `s35-kartu-lampiran.png`, `s35-dialog-sesudah.png`, `s35-tenggat-lampiran.png`,
`s35-kartu-lampiran-ponsel.png`, `s35-dialog-ponsel.png`, `s35-tenggat-lampiran-ponsel.png`,
`f8-browsercheck-kartu.png`.

---

## 7. Sapuan dokumentasi (CONVENTIONS §35)

```
grep -rn "kartu Lampiran" docs/ | wc -l      # 17 baris
grep -rl "kartu Lampiran" docs/ | wc -l      # 8 berkas
grep -rn "Tambah lampiran" docs/ | wc -l     # 3 baris di 3 berkas
```

Ketiga penyebutan `Tambah lampiran`: satu laporan paket lama (sejarah, tidak diubah), satu
`results-phase-2.json` (bukti terukur, tidak diubah), satu **PANDUAN-PENGGUNA §2.7 — diubah**
(izin `Masa berlaku` ditambahkan di kalimat yang sama). Tujuh belas penyebutan `kartu
Lampiran` dibaca semuanya; yang berubah artinya hanya §2.7, sisanya menyebut kartu itu
sebagai tempat menaruh berkas dan tetap benar.

Yang ditulis:

- **CONVENTIONS §37** (baru) — empat keadaan, jendela 30 hari yang dibaca dua permukaan, dua
  bendera registri baru, `EXPLAIN` kedua driver, dan biaya cabang BLIND.
- **PANDUAN-PENGGUNA §2.7** — sensus kartu Lampiran **39 → 40**, tombol `Masa berlaku`, tabel
  empat keadaan, aturan hari terakhir.
- **PANDUAN-PENGGUNA §1.7** — kelompok lampiran ditambahkan ke tabel Tenggat.
- **PANDUAN-ADMINISTRATOR §5.8/§5.11** — registri **18/19 → 34** entri; 12 entri lampiran
  dijelaskan di bawah tabel, termasuk kenapa ia satu-satunya yang bukan sumber kalender.

---

## 8. Sikap e-sign — dan bukti bahwa ia TIDAK dibangun

`docs/SIKAP-E-SIGN.md`. Isinya empat hal yang diminta roadmap: apa yang **sudah** ada, apa
yang **ditolak**, **syarat** peninjauan ulang, dan **di mana penolakan tertulis sebelumnya
berada**.

Yang dipertahankan: `ExternalApprovalService` (tautan sekali-pakai — token hanya tampil sekali,
sha256 di basis data, `expires_at`, pencabutan, tiga nilai keputusan, `decided_via`, pemisahan
tugas) + `record-physical` (lembar bertanda tangan basah dengan pindaian **wajib** yang harus
menempel pada dokumen yang sama) + formulir cetak berkolom tanda tangan.

Yang ditolak: integrasi PSrE, TTE tersertifikasi, e-Materai, kolom tanda tangan, pustaka
kripto — dan **tanda tangan gambar-tangan di layar**, yang paling murah dibangun dan justru
paling berbahaya: ia terlihat mengikat tanpa membawa satu pun sifat yang membuat tanda tangan
mengikat.

**Diperiksa, bukan diklaim** (§7 dokumen itu, dijalankan hari ini):

```
git diff main...feat/phase2-f8 -- '*/Database/Migrations/*'
  → 1 berkas: 2026_09_09_001800_add_valid_until_to_core_attachments_table.php
git diff main...feat/phase2-f8 -- composer.json composer.lock package.json
  → (kosong)
git diff main...feat/phase2-f8 -- 'Modules/*/Routes/api.php'
  → 1 rute: PATCH core/attachments/{attachment}
git diff main...feat/phase2-f8 -- public/app/vendor/
  → (kosong)
```

---

## 9. Keputusan pemilik

1. **Blok migrasi lanjutan Core `001800–001899`** — didaftarkan di CONVENTIONS §2 karena
   aturan blok lanjutan menuntutnya pada commit pemakaian pertama, dan F-8 adalah paket
   pertama yang butuh migrasi Core sejak blok pertamanya habis di F-1. Seperti baris Inventory
   `001700–001799` (F-6), ia **usulan yang menunggu pengesahan ke ledger pemilik**
   (ROADMAP-HASHMICRO §5 baris 5 menyebut "Core 001400–?", yang tidak bisa dipakai: itu blok
   pertama Quality dan sudah berisi enam migrasi).
2. **Masa berlaku lampiran TIDAK masuk Kalender.** Tiga alasan; yang ketiga milik pemilik:
   (a) kalender menjawab "apa yang **terjadi** kapan" — habisnya masa berlaku sebuah berkas
   bukan acara siapa pun; (b) 12 sumber baru = **+52 %** atas 23 sumber yang ada, pada endpoint
   yang ikut dipanggil dasbor dan dibaca di ponsel lapangan; (c) legenda departemen kalender
   adalah **daftar delapan label milik pemilik**, dan tiga prefix lampiran (`est`, `eng`, `qc`)
   tidak punya departemen di dalamnya. Menambah chip "Estimasi/Engineering/Mutu" mengubah
   legenda yang disetujui pemilik; memaksa ketiganya ke "Proyek" mengarang penempatan.
   **Pertanyaan untuk pemilik:** perlukah masa berlaku lampiran tampil di Kalender, dan bila
   ya, di bawah departemen mana ketiga prefix itu?
3. **Jendela peringatan 30 hari** dipilih menyamai dokumen vendor (`prc_vendor_documents`).
   Bila pemilik menghendaki jendela berbeda per modul, tempatnya `config/erp.php` — hari ini
   ia satu konstanta yang dibaca dua permukaan, dan itu yang menjaga keduanya sepakat.
4. **Sikap e-sign** (§8) menunggu keempat syarat `SIKAP-E-SIGN.md` §5: penyedia + anggaran
   bernama, pemicu bisnis dengan nomor dokumen, daftar dokumen yang ikut, kesiapan
   penandatangan luar.
5. **Merge dan deploy** adalah langkah pemilik. Deploy menyalin **pohon kerja**, bukan `main`
   (`deploy/sync-erp1.sh`) — dan ia menambah tabel/kolom, jadi urutan "migrate lalu verifikasi
   kolomnya benar-benar mendarat" berlaku.

---

## 10. Yang TIDAK dikerjakan

- **E-sign apa pun.** Nol kolom, nol endpoint, nol pustaka — §8.
- **Masa berlaku sebagai sumber Kalender** — keputusan pemilik #2.
- **Notifikasi kedaluwarsa untuk tautan persetujuan eksternal.** `PERSETUJUAN-EKSTERNAL.md`
  mencatatnya sebagai kekurangan yang tempatnya `WatchedDeadlines`; ia tanggal yang berbeda
  (`core_external_approvals.expires_at`), audiens berbeda, dan bukan cakupan F-8.
- **Masa berlaku wajib per jenis dokumen.** Tidak ada daftar "jenis ini wajib bertanggal";
  roadmap menulis "kedaluwarsa lampiran **umum**", dan memilih jenis mana yang wajib adalah
  keputusan pemilik, bukan tebakan paket.
- **Pembaruan massal masa berlaku** (satu tanggal untuk banyak berkas sekaligus). Kotak
  "Masa berlaku (opsional)" di kartu **tidak dikosongkan** setelah unggah, jadi melampirkan
  lima polis dengan masa berlaku sama tetap satu kali ketik — itu ganti murahnya.
- **Pengurutan/penyaringan lampiran menurut masa berlaku** di kartu. Kartu menampilkan seluruh
  lampiran satu dokumen; layar Tenggat-lah yang menyusun lintas dokumen.
- **`ANALYZE` otomatis pada SQLite.** Diukur (§3) dan sengaja tidak dipakai sebagai solusi:
  indeks yang benar bekerja tanpanya, dan menambahkan langkah pemeliharaan basis data untuk
  menutupi indeks yang salah adalah menukar satu masalah dengan dua.

---

## 11. Deviasi baru yang ditemukan

| # | Temuan | Bukti | Nasib |
|---|---|---|---|
| D1 | **Sensus kartu Lampiran PANDUAN §2.7 basi sejak F-4**: tertulis 39 jenis, sebenarnya 40 — `hr/attendances` masuk registri di F-4 dan kartunya memang dirender `views/absensi.js`, tetapi tidak pernah masuk daftar §2.7 | `AttachableDocuments::slugs()` = 40 | **DITUTUP** `7c636ba` |
| D2 | **Tabel Tenggat PANDUAN §1.7 kehilangan dua pengawas yang sudah dikirim**: `ap_due` (tagihan vendor) dan `ticket_sla` (tiket lewat SLA) | registri = 22 entri non-lampiran, tabel = 20 baris | **DITUTUP** `7c636ba` |
| D3 | **PANDUAN §5.9 aktif membantah kode**: *"tidak ada alarm Tenggat untuk tagihan vendor yang jatuh tempo (Tenggat hanya mengawasi invoice pelanggan)"* — `ap_due` sudah ada dan berbunyi ke `fin.create` | baris 3271–3274 | **DITUTUP** `7c636ba`, dengan menyebut kalimat lamanya |
| D4 | **PANDUAN-ADMINISTRATOR menghitung 18/19 tenggat**, tiga entri hilang dari §5.11 (`crm_activity_due`, `ap_due`, `ticket_sla`) | `count(WatchedDeadlines::entries())` = 22 pra-F-8 | **DITUTUP** `7c636ba` |
| D5 | **Harness memakai jam prosesnya sendiri untuk menanam fixture tanggal**, sementara aplikasinya di Asia/Jakarta dan mesin ini di UTC — selama tujuh jam setiap hari itu sehari lebih awal | terukur 17.4x UTC: fixture "berlaku s/d hari ini" dibaca server sebagai kedaluwarsa 1 hari | **DITUTUP** `0c2669f` — anchor diambil dari `meta.today` milik server |
| D6 | **Keterangan masa berlaku terpotong di 390 px** — `.badge` mewarisi `white-space: nowrap`, dan lencana ini membawa kalimat, bukan satu kata status | `validity_clipped: true`, harness S35 ponsel | **DITUTUP** `0c2669f` |
| D7 | **`ANALISIS-PROSES-BISNIS-2026-09.md` baris 127 menulis "19 tenggat"** | registri 22 pra-F-8, 34 pasca | **DIBIARKAN** — berkas itu asesmen bertanggal (potret 4 Sep 2026), bukan dokumen rujukan hidup; mengeditnya akan memalsukan potretnya |
| D8 | **`AttachmentController` tidak punya jalur mengubah `caption`** — satu-satunya cara memperbaiki keterangan yang salah ketik masih menghapus berkasnya dan mengunggah ulang | `PATCH` yang ditambah paket ini sengaja hanya menerima `valid_until` | **DIBIARKAN** — di luar cakupan F-8; dicatat di sini supaya paket berikutnya tidak menemukannya lagi dari nol |

---

## 12. Gerbang

Dijalankan per-direktori selama kerja (gerbang rilis penuh dijalankan terpisah):

| Yang dijalankan | SQLite | MySQL 8 (`erp_dryrun`) |
|---|---|---|
| `tests/Feature/Core` (seluruhnya, pada `7d1f56c`) | **OK 1.022 uji, 9.231 assertion** (11 skipped) | — |
| Tujuh berkas uji lampiran | OK | **OK 82 uji, 568 assertion** (2 skipped = paku rencana kueri khusus SQLite) |
| Tiga berkas uji baru F-8 | **OK 35 uji, 256 assertion** | ikut di atas |
| `tests/Unit` + `tests/Feature/Procurement` + `tests/Feature/Projects` + `tests/Feature/HrPayroll` | **OK 1.377 uji, 5.712 assertion** | — |
| `php vendor/bin/pint --test` berkas baru/tersentuh | passed | — |

Gerbang rilis penuh dijalankan terpisah; yang di atas adalah putaran per-direktori selama
kerja. Satu uji lama merah di putaran itu (`CalendarEventsTest`) diperbaiki, bukan
dilonggarkan — §4.

---

## 13. Commit

| SHA | Judul |
|---|---|
| `fc7c744` | F-8: kolom masa berlaku lampiran — dan blok migrasi lanjutan Core yang habis sejak F-1 |
| `950edb5` | F-8: pintu tulis masa berlaku — dua transport unggah, satu pintu ubah, izin dokumennya |
| `26937a2` | F-8: kartu lampiran menampilkan masa berlaku — dan "tanpa masa berlaku" bukan peringatan |
| `b6237ed` | F-8: pengawas kedaluwarsa lampiran — satu kolom, dua belas audiens, nol teriakan pada foto lapangan |
| `7c636ba` | F-8: sikap e-sign ditulis sebagai keputusan, bukan dibangun sebagai kode |
| `0c2669f` | F-8: harness S35 — dan satu keterangan yang terpotong di ponsel, ditemukan olehnya |
