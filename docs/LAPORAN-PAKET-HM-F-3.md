# Laporan Paket F-3 (ROADMAP-HASHMICRO Fase 2) — Aktivitas CRM, pemilik prospek, transisi pipeline

Branch: `feat/phase2-f3` (dari `main` 6cd42de) · 8 September 2026 · **paket ketiga Fase 2**

> **Klaim tengah paket ini satu kalimat: TAHAP SEBUAH PROSPEK BUKAN LAGI KOLOM YANG BISA DIKETIK.**
>
> Sampai paket ini, `crm_leads.status` adalah isian biasa di formulir. Satu `PUT` dengan
> `{"status":"won"}` memenangkan sebuah prospek **tanpa penawaran, tanpa nilai, tanpa tanggal
> keputusan** — sementara layar Analitik Win-Rate menghitung persis dari kolom itu. Sejak F-3
> perpindahan tahap punya SATU pintu (`POST crm/leads/{id}/pipeline`), maju bebas, **mundur wajib
> beralasan** (tersimpan dan terbaca di riwayat), dan **Menang/Kalah hanya lahir dari keputusan
> penawaran** — ditolak di mana pun ia dicoba, dengan kalimat yang menyebut penawaran MANA yang
> harus ditandai.
>
> Klaim kedua: **tanggal tindak lanjut tidak lagi diketik, ia DITURUNKAN** dari aktivitas terbuka
> paling awal. Dan karena kolom itu sudah dipakai orang sejak Agustus, migrasi 000396 memindahkan
> setiap tanggal yang pernah diketik menjadi satu aktivitas terbuka bertanggal sama — sehingga
> **tidak ada satu prospek pun yang berubah makna** dalam deploy ini. Dipaku uji, baris per baris.
>
> Empat migrasi (blok Crm 000395–000398), nol dependensi, nol pustaka vendor, nol perubahan
> konfigurasi server. Papan kanban P1-G dipakai **apa adanya** + tiga kait kecil yang generik.
>
> Verifikasi peramban dijalankan: **S30 (23 syarat) + S30 ponsel (4 syarat), semuanya hijau**, di
> atas salinan coretan basis data demo.

## Yang ditutup (ROADMAP-HASHMICRO Fase 2 / F-3 → status)

| Klausa kontrak | Status | Bukti |
|---|---|---|
| `crm_activities` (call/meeting/email/visit/note, due/done, owner) | ✅ | migrasi `000395` + `ActivityService` (satu pintu) · `ActivityTest` (10 uji) · CONVENTIONS §25 |
| kartu di prospek / penawaran / pelanggan | ✅ | `views/activities.js` + satu baris di `renderDetail` · `ActivityRegistryTest` (6 uji) · S30 `the_same_card_serves_the_quotation_screen` |
| `next_follow_up_at` DITURUNKAN dari aktivitas | ✅ | `LeadFollowUpService` (satu penulis) + `missing` di kedua FormRequest + `Arr::except` di controller (lihat putaran verifikasi) · `LeadFollowUpDerivationTest` (10 uji) · S30 tiga pembaca menyebut tanggal yang sama |
| …dan nilai yang sudah diketik tidak berubah makna | ✅ | migrasi data `000396` (idempoten, tanpa `down()` yang menghapus) · uji membandingkan tiap baris sebelum/sesudah |
| `owner_user_id` pada prospek, **tanpa backfill** | ✅ (dengan penyimpangan bentuk — lihat § berikutnya) | migrasi `000397` **mengganti nama** `user_id` · `LeadOwnerTest` (6 uji) |
| "Belum ditugaskan" di mana pun kosong | ✅ | SATU kalimat (`ActivityResource::ownerName`) → daftar, CSV daftar, kartu papan, layar dokumen · S30 tiga permukaan diukur |
| `LeadStatus::canMoveTo` (maju bebas, mundur wajib alasan, won/lost hanya lewat penawaran) | ✅ | `LeadStatus::canMoveTo` → `LeadMove` (5 putusan) · matriks 6×6 penuh dipaku uji · `LeadPipelineTransitionTest` (14 uji) · CONVENTIONS §26 |
| ditegakkan di LAYANAN (satu pintu) dan di setiap permukaan | ✅ | `LeadPipelineService`; `PUT` prospek menolak `status`; formulir `createOnly` tanpa Menang/Kalah; papan lewat `runAction` |
| `GET crm/pipeline/board` untuk kanban Fase 1 | ✅ | `PipelineBoardController` (N teratas per kolom + jumlah sebenarnya) · `PipelineBoardTest` (8 uji) |
| drop yang ditolak mengembalikan kartu + menyebut aturannya | ✅ | S30 `a_drag_to_won_is_refused` + `and_the_refusal_names_the_quotation_route` + `the_refusal_is_the_servers_sentence_not_the_generic_one` |
| watcher aktivitas jatuh tempo (bentuk `WatchedDeadlines`) | ✅ | satu entri `crm_activity_due` (lead 3 hari, izin `crm.update`) · `ActivityDeadlineWatchTest` (9 uji) |

## Commit pembangunan

| Commit | Isi |
|---|---|
| `d0339a8` | T3.1 `crm_activities` (000395), enum jenis, registri `ActivityDocuments`, `ActivityService`, rute + `LeadFollowUpService` |
| `eb4af15` | T3.3 turunan `next_follow_up_at` + migrasi data 000396 + penolakan ketikan di kedua FormRequest |
| `df9d1bb` | T3.4+T3.5+T3.6 `owner_user_id` (000397), `crm_lead_status_changes` (000398), `LeadPipelineService`, `GET crm/pipeline/board`, papan + tiga kait `board.api` / `board.card.fields` / `action.body`+`boardOnly` |
| `b658355` | T3.2 kartu Aktivitas (`views/activities.js`) + cermin registri berpenjaga uji |
| `fd5d716` | T3.7 entri pengawas `crm_activity_due` + layar `Aktivitas CRM` + parameter `transform` pada `ApiController::listing` |
| `075a086` | T3.8 harness `S30_pipeline_crm` (+ ponsel) dan hasilnya |

## Penyimpangan yang harus dibaca pemilik: `owner_user_id` diganti nama, bukan ditambah

ROADMAP meminta *"`owner_user_id` pada prospek, tanpa backfill"*. Kolom itu **sudah ada** sejak
migrasi 000310 dengan nama yang lebih miskin: `user_id`, berkomentar *"Owner (sales/estimator)"*,
dengan relasi `Lead::owner()` di atasnya dan saringan `?user_id=` di daftarnya.

**Terukur pada salinan basis data produksi, 8 September 2026: 2 prospek, KEDUANYA ber-`user_id`
(Administrator Sistem).** Menambahkan kolom KEDUA dan mematuhi "tanpa backfill" secara harfiah
berarti: kedua prospek itu membaca **"Belum ditugaskan"** di layar sejak menit pertama sesudah
deploy, sementara pemiliknya tersimpan utuh di kolom sebelah. Itu persis "berubah makna
diam-diam" yang paket ini ada untuk mencegahnya, dan dua kolom pemilik pada satu baris adalah dua
kebenaran.

Maka migrasi `000397` **mengganti namanya** (`renameColumn` + indeksnya ikut diganti, `down()`
mengembalikannya). "Tanpa backfill" tetap dipatuhi dalam artinya yang sebenarnya: **tidak ada satu
prospek pun yang MENDAPAT pemilik yang tidak pernah dituliskan seseorang**; yang `NULL` tetap
`NULL` dan berbunyi "Belum ditugaskan". Dibuktikan dua kali: `LeadOwnerTest` menjalankan
`down()` lalu `up()` di atas baris yang sudah berisi (bentuk sesungguhnya dari deploy di atas basis
data yang sudah berjalan), dan migrasi sungguhan di atas salinan produksi memulangkan
`owner_user_id = 1` untuk kedua prospek dan indeks `crm_leads_owner_user_id_index`.

## Nasib tanggal tindak lanjut yang sudah diketik (T3.3, dan kenapa ini penting)

Tanpa migrasi `000396`, hari deploy adalah hari setiap tanggal ketikan berhenti berarti: kolomnya
masih memajang "20 Agu 2026" sementara **perubahan aktivitas PERTAMA** pada prospek itu menghitung
ulang turunannya dan mengosongkannya — rencana yang hilang tanpa satu baris pun yang bisa ditunjuk
orang, dan tanpa satu galat pun di layar.

Maka setiap prospek bertanggal ketikan mendapat SATU aktivitas terbuka bertanggal sama; sesudahnya
turunannya identik dengan angka yang sudah tertulis. Tiga hal **sengaja tidak ditebak**: jenisnya
`note` (kolom lama tidak pernah menyimpan apakah yang direncanakan telepon atau kunjungan),
pemiliknya kosong (siapa yang menyanggupinya belum pernah dinyatakan), dan tidak ada jam palsu.
Idempoten; `down()` sengaja tidak menghapus baris yang sesudah deploy sudah menjadi milik
penggunanya.

**Di produksi, migrasi ini akan mengonversi NOL baris** — terukur pada salinannya: kedua prospek
demo ber-`next_follow_up_at` NULL. Jalur itu tetap dipaku uji, karena pemasangan lain (dan basis
data mana pun yang sudah dipakai sejak Agustus) belum tentu begitu.

## Matriks transisi sebagaimana dikirim

| dari \ ke | Baru | Sudah Dihubungi | Terkualifikasi | Penawaran Dikirim | Menang | Kalah |
|---|---|---|---|---|---|---|
| **Baru** | sama | maju | maju | maju | lewat penawaran | lewat penawaran |
| **Sudah Dihubungi** | mundur | sama | maju | maju | lewat penawaran | lewat penawaran |
| **Terkualifikasi** | mundur | mundur | sama | maju | lewat penawaran | lewat penawaran |
| **Penawaran Dikirim** | mundur | mundur | mundur | sama | lewat penawaran | lewat penawaran |
| **Menang** | terkunci | terkunci | terkunci | terkunci | sama | lewat penawaran |
| **Kalah** | terkunci | terkunci | terkunci | terkunci | lewat penawaran | sama |

`maju` = dijalankan tanpa pertanyaan · `mundur` = 422 berkunci `reason`, dijawab satu isian wajib
(≥ 5 karakter) lalu dikirim ulang, tersimpan di `crm_lead_status_changes` · `lewat penawaran` = 422
yang menyebut `Tandai Menang`/`Tandai Kalah` pada penawaran tertentu, atau mengakui bahwa prospek
itu belum punya penawaran · `terkunci` = 422 yang mengatakan tahapnya mengikuti penawarannya.

Keempat kalimat itu ditulis SEKALI (`LeadPipelineService`) dan dibaca empat permukaan: layar
dokumen, daftar, papan, dan API.

## Yang hanya ditemukan peramban

1. **Isian yang tertinggal di formulir menggagalkan SETIAP Simpan.** `next_follow_up_at` dan
   `status` kini ditolak server pada `PUT`. Sebuah isian yang masih terpasang akan mengirim
   nilainya pada setiap penyuntingan prospek — bukan "isian mati", melainkan formulir yang tidak
   bisa disimpan sama sekali. Keduanya dicabut/di-`createOnly`, dan `LeadPipelineSpaWiringTest`
   memakukan keduanya sebagai teks.
2. **Berkas layar baru yang tidak terdaftar di service worker.** `js/views/activities.js` adalah
   berkas cangkang ke-103, dan `PwaServiceWorkerTest` menolak rilis yang melupakannya: aplikasinya
   tetap jalan daring lalu **setengah mati saat luring**, tanpa satu galat pun. Ditambahkan ke
   `SHELL` dan `SHELL_VERSION` dinaikkan `2` → `3` (CONVENTIONS §21) — yang berarti tab yang sudah
   terbuka berhari-hari akan memunculkan toast "Versi baru siap — Muat ulang" setelah deploy.
   (Ditangkap uji rumah, bukan peramban; dicatat di sini karena ia bagian dari kelalaian yang sama:
   satu berkas baru menyentuh lebih banyak permukaan daripada yang terlihat.)
3. **Label `owner_name` berbunyi "Pemilik prospek" di layar Paket Tender.** `LABELS` di `detail.js`
   global; sejak P7 `crm_tender_packages.owner_name` berarti **pemberi tugas**, jadi layar itu
   menuliskan *"Pemilik prospek: Universitas Cendekia Nusantara"*. F-3 memindahkan pemilik prospek
   ke kunci sendiri (`owner_user_name`) dan **memperbaiki label lama menjadi "Pemberi tugas"**.

## Uji

| Berkas | Uji | Yang dipaku |
|---|---:|---|
| `tests/Feature/Crm/ActivityTest.php` | 10 | register aktivitas: induk wajib ada, `done_at` dicap server, selesai dua kali ditolak menyebut kapan & siapa, "lewat tanggal" mulai HARI BERIKUTNYA, pemilik hilang ≠ belum ditugaskan |
| `tests/Feature/Crm/LeadFollowUpDerivationTest.php` | 8 | turunan bergerak pada create/done/reopen/delete/pindah dokumen; kosong bukan tanggal basi; aktivitas penawaran tidak menggeser prospek; ketikan ditolak; **migrasi mempertahankan makna** + idempoten |
| `tests/Feature/Crm/LeadOwnerTest.php` | 6 | nama kolom, nilai selamat melewati `down()`+`up()`, "Belum ditugaskan" di API, pembuat ≠ pemilik, dua saringan |
| `tests/Feature/Crm/LeadPipelineTransitionTest.php` | 14 | **matriks 6×6 penuh**, mundur tanpa alasan menolak & TIDAK menggeser status, alasan tersimpan, penolakan menang/kalah menyebut jalannya, pintu kedua tertutup (`PUT`, `POST` baru), riwayat di layar, izin |
| `tests/Feature/Crm/PipelineBoardTest.php` | 8 | enam kolom + jumlah SEBENARNYA, kolom terpenggal mengaku, urutan mendesak-dulu, kartu tanpa aktivitas diam, izin |
| `tests/Feature/Crm/ActivityRegistryTest.php` | 6 | cermin registri PHP ⇄ SPA, jenis = enum, kartu terpasang di layar dokumen, kartu kosong berbunyi kosong |
| `tests/Feature/Crm/LeadPipelineSpaWiringTest.php` | 7 | enam kolom → enam aksi ber-`body`, satu endpoint, `boardOnly` disaring, satu deklarasi dialog alasan, papan membaca rutenya, riwayat punya kartunya |
| `tests/Feature/Crm/ActivityDeadlineWatchTest.php` | 9 | entri registri, MENIPIS/LEWAT, selesai & terhapus mendiamkan, tanpa tanggal tidak pernah berbunyi, hari ini tidak hilang di antara dua tingkat, hanya yang bisa bertindak diberi tahu |

**Mutasi yang dipaku merah** (dijalankan, diverifikasi merah, lalu dikembalikan):

| Mutasi | Uji yang menangkap |
|---|---|
| `isOverdue` memakai `lte` (hari ini ikut merah) | `test_overdue_starts_the_day_after_the_due_date` |
| `done_at` diterima sebagai isian biasa (bukan `prohibited`) | `test_done_at_cannot_be_typed` |
| scope pengawas tanpa `whereNull('done_at')` | `test_a_done_activity_is_silent` |

## Harness (bukti UI)

Dijalankan **8 September 2026**, `php -S` port **8141** atas **salinan coretan** basis data demo
(disalin lalu dimigrasikan; `database/database.sqlite` tidak disentuh), Chromium headless,
dimatikan menurut PID-nya. Basis datanya **dibangun ulang dari salinan segar** sebelum putaran
yang dilaporkan di sini, jadi angkanya berdiri di atas bentuk data produksi (2 prospek, 0
aktivitas).

| Skenario | Viewport | Syarat | Hasil |
|---|---|---:|---|
| `S30_pipeline_crm` | 1440×900 | 23 | ✅ semua |
| `S30_pipeline_crm_mobile` | 390×844 | 4 | ✅ semua |

Yang diukur, bukan diasumsikan:

- **"Belum ditugaskan" di tiga permukaan untuk prospek yang sama** — kartu papan
  (`LEAD-0003 Rp 0 PT Cahaya Nusantara (fixture S30) 11 Sep 2026 Belum ditugaskan 2 aktivitas
  terbuka`), sel daftar, dan baris "Pemilik prospek" di layar dokumen.
- **Seretan mundur** membuka dialog berisi kalimat SERVER (*"Prospek LEAD-0003 mundur dari Sudah
  Dihubungi ke Baru: sebutkan alasannya (minimal 5 karakter)…"*); **membatalkannya mengembalikan
  kartu** ke kolom `contacted`; menjawabnya memindahkannya ke `new` dan alasannya muncul di kartu
  Riwayat Tahap.
- **Seretan ke Menang ditolak** — kartunya kembali ke `new`, toast-nya berbunyi *"… prospek ini
  belum punya penawaran — buat penawarannya lebih dulu, lalu tekan "Tandai Menang" di penawaran
  itu."*, dan kalimat generik papan TIDAK muncul.
- **Tanggal turunan = aktivitas terbuka paling awal**: dua aktivitas (+3 dan +10 hari), dan tiga
  pembaca menyebut **11 Sep 2026** — kartu Aktivitas ("diturunkan dari aktivitas terbuka paling
  awal"), panel Informasi, dan sel Follow-up di daftar. Isian ketikannya sudah tidak ada.
- **Kartu aktivitas kosong** berbunyi *"Belum ada aktivitas dicatat untuk dokumen ini"* dan tidak
  memuat "0 aktivitas" di mana pun.
- **Menambah aktivitas DARI KARTUNYA** (satu-satunya jalan membuat aktivitas — layar daftarnya
  baca saja) bekerja di peramban: dialognya terisi, tersimpan, dan kalimat tanggal turunannya
  muncul seketika di kartu yang sama.
- **Kartu yang sama di layar PENAWARAN** merender aktivitas penawarannya (registri kartunya memuat
  tiga slug; dua di antaranya tidak akan pernah tersentuh kalau hanya prospek yang dibuka).
- Ponsel 390 px: papan **enam kolom** (terlebar di aplikasi ini — papan PR dan NCR punya empat)
  menggulir di dalam `.board-grid`; **halamannya tidak menggulir mendatar**.

Tangkapan layar: `docs/bukti-uji/s30-papan-pipeline-f3.png`, `s30-alasan-mundur-f3.png`,
`s30-tolak-menang-f3.png`, `s30-prospek-aktivitas-f3.png`, `s30-papan-ponsel-f3.png`.

## Yang TIDAK diverifikasi

1. **Belum pernah dijalankan di belakang nginx/produksi.** Seluruh pengukuran UI memakai `php -S`
   di loopback atas salinan basis data demo. Deploy sesungguhnya (dan migrasi 000395–000398 di
   sana) belum terjadi.
2. **Migrasi 000397 belum berjalan di produksi** — hanya di atas **salinannya** (hasil: kedua
   prospek mempertahankan pemiliknya, indeksnya ikut berganti nama). Deploy yang setengah jalan
   tetap risiko yang dijaga runbook, bukan paket ini.
3. **Seret-lepas hanya diukur di Chromium headless** dengan `page.drag_and_drop`. Sentuhan jari
   sungguhan di ponsel (jalur fallback SortableJS) **tidak** diuji; yang diukur di 390 px hanyalah
   tata letak dan gulirnya.
4. **Kartu aktivitas di layar PELANGGAN tidak dibuka peramban.** Prospek dan penawaran dibuka;
   pelanggan hanya ditutup uji PHP + cermin registri.
5. **Ekspor CSV daftar prospek tidak diunduh dan dibuka.** Yang diukur adalah sel "Belum
   ditugaskan" di baris tabelnya; ekspor CSV membaca kolom yang sama (`views/list.js` `csvValue`),
   tetapi berkasnya sendiri tidak diperiksa dalam paket ini.
6. **Pengiriman notifikasi tidak diuji ujung ke ujung.** Yang dipaku adalah bahwa pengawas menulis
   notifikasi yang benar untuk orang yang benar; apakah e-mail/WhatsApp-nya sampai adalah urusan
   `core_notification_deliveries` (P-0b), yang tidak disentuh paket ini.
7. **Baris "N dari M digambar" tidak pernah tergambar di peramban.** Kolom yang terpenggal
   dipaku uji di sisi server (`shown` < `count`), tetapi harness-nya berjalan di atas data demo
   yang tidak punya satu kolom pun berisi lebih dari 25 prospek — jadi kalimat itu sendiri belum
   pernah dilihat.
8. **Papan belum pernah diukur dengan data besar.** Rutenya mengambil 25 kartu per kolom dan
   menghitung jumlah sebenarnya per kolom (13 kueri untuk enam kolom), tetapi angka waktunya hanya
   diukur atas 6 kartu. Tidak ada indeks baru yang ditambahkan untuk itu.
9. **Aktivitas tidak masuk laporan/cetakan mana pun.** Tidak ada formulir rumah, tidak ada entri
   `ReportableResources`, tidak ada widget dasbor. Ia hidup di kartunya, layarnya, kalender, dan
   pengawas tenggat.
10. **Tidak ada uji beban atas `transform` di `ApiController::listing`.** Pemakainya hanya satu
   (daftar aktivitas), dan biayanya diukur sebagai jumlah kueri (satu per JENIS), bukan sebagai
   waktu.

## Untuk pemilik — setiap bawaan dan aturan yang dibawa paket ini

| Hal | Nilai yang dikirim | Bisa diubah? |
|---|---|---|
| Jenis aktivitas | Telepon · Rapat · Email · Kunjungan · Catatan | Tidak dari layar (enum). Menambah jenis = satu baris enum + satu baris cermin SPA |
| `due_at` aktivitas | **tanggal**, tanpa jam | Tidak. Keputusan desain: "hubungi lagi Senin depan" adalah sebuah hari; jam palsu 00:00 adalah ketelitian yang tidak pernah diketik |
| Pemilik aktivitas / prospek kosong | **"Belum ditugaskan"** | Kalimatnya satu tempat (`ActivityResource::ownerName`) |
| Panjang minimum alasan mundur | **5 karakter** | `LeadPipelineService::REASON_MIN` (bukan setting; ubah = satu baris + uji) |
| Pengawas aktivitas: peringatan dini | **3 hari** sebelum jatuh tempo, lalu setiap hari sesudah lewat | `WatchedDeadlines` entri `crm_activity_due`, `lead_days` |
| Pengawas aktivitas: siapa diberi tahu | pemegang **`crm.update`** | idem, kunci `permission` |
| Kartu papan per kolom | **25** (parameter `per_lane`, batas 5–100) | `PipelineBoardController::PER_LANE` |
| Kolom papan | keenam tahap, termasuk Menang & Kalah | `schema.js` `board.lanes` |
| Tahap awal saat membuat prospek | empat tahap terbuka; bawaan **Baru** | `schema.js` + `LeadStoreRequest` |
| Versi cangkang PWA | **3** (naik dari 2, karena ada berkas layar baru) | `sw.js` `SHELL_VERSION`. Akibatnya di lapangan: tab yang sudah lama terbuka akan menampilkan toast "Versi baru siap — Muat ulang" sekali setelah deploy |

**Empat aturan baru yang mengubah apa yang bisa dilakukan orang** — semuanya disengaja, dan
semuanya bisa dicabut hanya lewat kode:

1. **Tahap prospek tidak bisa lagi diubah dari formulir.** Tombol `Ubah Tahap` (atau papan)
   satu-satunya jalan; `PUT` menolak dengan kalimat yang menyebut tombol itu.
2. **Mundur menuntut alasan tertulis** yang tersimpan permanen dan terbaca di halaman prospek.
3. **Menang/Kalah hanya lewat penawaran.** Konsekuensi yang harus pemilik ketahui: **prospek yang
   mati tanpa pernah ditawar tidak punya jalan menuju "Kalah"** — ia tetap terbuka di corong dan
   tetap terhitung di "Prospek terbuka" pada dasbor. Lihat pertanyaan terbuka OQ-F3-1.
4. **Tanggal tindak lanjut tidak bisa diketik.** Menggesernya = menambah/menyelesaikan aktivitas.

## Pertanyaan terbuka (milik pemilik)

- **OQ-F3-1 — prospek yang mati tanpa penawaran.** Aturan ROADMAP ("won/lost HANYA lewat
  penawaran") dijalankan apa adanya. Akibatnya sebuah prospek yang berhenti membalas telepon tidak
  punya cara jujur untuk ditutup: menandainya Kalah menuntut penawaran yang tidak pernah ada.
  Pilihan yang tersedia — (a) biarkan (corong berisi prospek dingin yang terus diingatkan
  pengawas), (b) izinkan "Kalah" langsung dengan alasan wajib **tanpa** penawaran (satu
  pengecualian di `canMoveTo`), (c) tambahkan tahap kelima "Tidak dilanjutkan" yang bukan Kalah dan
  tidak masuk win-rate. Paket ini tidak memilih.
- **OQ-F3-2 — pemilik prospek dan hak akses.** Pemilik saat ini hanyalah label + saringan: setiap
  pemegang `crm.view` tetap melihat semua prospek. Apakah sales hanya boleh melihat prospeknya
  sendiri? Itu perubahan kebijakan akses, bukan kolom, dan sengaja tidak diambil di sini.
- **OQ-F3-3 — aktivitas di penawaran/pelanggan tidak menggeser tanggal apa pun.** Turunannya hanya
  membaca aktivitas PROSPEK (supaya tanggal di baris prospek selalu bisa dijelaskan oleh sesuatu
  yang terlihat di layar prospek itu). Bila pemilik ingin aktivitas penawaran ikut menagih, itu
  aturan baru yang harus ditulis, bukan kelalaian.

## Dokumentasi

| Berkas | Isi |
|---|---|
| `docs/CONVENTIONS.md` §25 | Aktivitas CRM: dua tanggal dengan dua tipe, jenis pendek + registri, satu pintu tulis, tiga aturan kartunya, pengawasnya |
| `docs/CONVENTIONS.md` §26 | Transisi pipeline: matriks 6×6, satu pintu, alasan mundur, tiga kait papan, "Belum ditugaskan" |
| `docs/FRONTEND.md` | "A card on the document screen" (kawat satu baris + cermin registri) dan "Kanban hooks" (`board.api`, `board.card.fields`, `action.body`, `boardOnly`) |
| `docs/PANDUAN-PENGGUNA.md` §1.4d | papan ketiga, kolom yang menolak dengan sengaja, kartu yang tidak digambar |
| `docs/PANDUAN-PENGGUNA.md` §3.2 | pemilik prospek, tahap awal, Ubah Tahap, apa arti penolakan mundur/menang |
| `docs/PANDUAN-PENGGUNA.md` §3.2a/§3.2b | Papan Pipeline; kartu Aktivitas + layar Aktivitas CRM + pemberitahuannya |
| `docs/bukti-uji/results-phase-2.json` | `S30_pipeline_crm` + `S30_pipeline_crm_mobile` + `S30_pipeline_crm_repair` digabung menurut kunci |

## Putaran verifikasi (8 September 2026) — 15 temuan, 15 diperbaiki

Dua lensa verifikasi (aturan & turunannya; papan dan kartunya sebagaimana ditemui sales)
menjalankan kodenya alih-alih membacanya, dan menemukan 15 hal. Satu aturan, banyak permukaan:
sebelas dari lima belas adalah permukaan yang TERLEWAT, bukan aturan yang salah.

| # | Yang ditemukan | Yang diperbaiki |
|---|---|---|
| 1 | `PUT crm/leads {"next_follow_up_at": null}` dijawab **200** dan menghapus kolom turunannya; satu halaman lalu memajang dua jawaban (kartu "25 Sep 2026" / panel Informasi "—"). `prohibited` adalah kebalikan `required`, jadi ia lulus untuk nilai KOSONG; `{"status": null}` bahkan HTTP 500 | `missing` di kedua FormRequest + `Arr::except` di `LeadController` (ikat pinggang dan tali, seperti `ActivityService`) + `status` null pada POST berarti "tahap awal", dan jawabannya dibaca ulang dari barisnya |
| 2 | Pengawas menyebut aktivitas yang jatuh tempo HARI INI "lewat jatuh tempo", sementara `isOverdue`, saringan `state=overdue` dan hitungan kartu papan menyebutnya belum — orang dikabari terlambat lalu tidak menemukan tanda terlambat di mana pun | `valid_through_end` pada entri `crm_activity_due`: hari itu MENIPIS ("hari ini"), LEWAT mulai besok. Ujinya kini memaku JUDUL notifikasinya, bukan sekadar keberadaannya |
| 3 | Penolakan "Menang" menyebut penawaran terbuka TERBARU — bisa jadi draf, yang tidak punya tombol "Tandai Menang" | Yang disebut adalah yang bisa ditandai (Menang: disetujui; Kalah: setiap yang terbuka); yang belum disetujui disebut bersama langkah yang kurang; yang semua penawarannya sudah diputuskan tidak lagi disebut "belum punya penawaran" |
| 4 | `state=open\|done\|overdue` ada di API dan tidak di satu bilah saringan pun; layar yang dibuka pemberitahuan 08.30 terbuka pada urutan `due_at` lintas keadaan — baris pertamanya pekerjaan yang selesai 20 bulan lalu | Saringan **Keadaan** (enum `activityState`) sebagai saringan pertama, dan `link` entri pengawas menjadi `r/crm/activities?state=open`. Ujinya membaca KEDUA sisi: setiap kunci query di `link` harus dideklarasikan `def.filters` |
| 5 | `?unassigned=1` dijanjikan komentar controller sebagai "satu klik" dan tidak bisa diklik dari layar mana pun (dan tautannya dibuang `seedFromUrl`) | Saringan `boolFilter` "Belum ditugaskan"; `filled()` menggantikan `boolean()` supaya "Tidak" benar-benar berarti "sudah ditugaskan" alih-alih memulangkan semua |
| 6 | Aturan "induk terhapus tidak ada" hidup hanya di docblock: mutasi `withTrashed()` meninggalkan 294 uji hijau | `test_a_soft_deleted_parent_does_not_exist` (POST baru DAN PUT yang memindahkan) |
| 7 | Kartu Aktivitas memajang "100 terbuka." pada dokumen berisi 110 tanpa mengaku memotong — sementara kartu papan untuk prospek yang sama menyebut 110 | `api.list` + `meta.total`, kalimat "100 dari 110 digambar", dan pada kartu terpotong jumlahnya ditanyakan lagi ke server (termasuk aktivitas terbuka paling awal, supaya kalimat turunannya tidak bisa menyebut baris yang salah) |
| 8 | Layar detail aktivitas tidak menyebut dokumen induknya sama sekali: `document_id` dibayangi `document_label`, dan `document_label` dibuang penyaring `_label` | Baris "Dokumen" dengan TAUTAN ke induknya (peta jenis→layar dibalik dari registri yang sudah ada) |
| 9 | "Diselesaikan oleh" dua kali, sekali sebagai id pengguna mentah | `NAME_SHADOWED.done_by_id`, + keduanya masuk `WHEN_SET_KEYS` supaya aktivitas terbuka tidak menyisakan baris menggantung |
| 10 | Toast penolakan diawali nama kolom: "status: Prospek LEAD-0003 tidak bisa…" | `toastError` membuang awalan kunci ketika galatnya hanya SATU |
| 11 | Toast keberhasilan perpindahan papan: "Pindahkan ke Baru berhasil." — tidak mengatakan prospek mana, padahal server sudah mengirim kalimat yang lebih baik | `TOAST_TAHAP` pada ketujuh aksinya; tahap tujuannya dibaca dari jawaban server |
| 12 | Mencetak papan memotong 3 dari 6 kolom pada A4 tanpa mengaku | Satu blok `@media print` di sebelah `.board-grid` (bukan di blok cetak jauh di atas: spesifisitasnya sama, yang belakangan menang) |
| 13 | Kartu papan bisa difokus tetapi tanpa `role`, dan Spasi menggulirkan halaman | `role="button"` + `aria-label`, Spasi = Enter dengan `preventDefault`, dan kalimat pengantar papan menyebut jalan yang ADA bagi yang tidak bisa menyeret |

Dua temuan lensa UX adalah temuan yang sama dengan dua temuan lensa aturan (saringan `state` dan
`unassigned`), jadi lima belas temuan ditutup oleh tiga belas perbaikan.

**Bukti barunya bisa dijalankan ulang**: skenario harness `S30_pipeline_crm_repair` (16 syarat)
mengukur ketiga belas perbaikan itu di peramban — termasuk kartu berisi 110 aktivitas, cetak A4
794×1123, dan tombol Spasi pada kartu papan.

## Gerbang paket (8 September 2026)

Dua driver, dijalankan setelah commit terakhir paket ini (`sw.js` termasuk):

| Kaki | Berkas uji | Hasil |
|---|---|---|
| SQLite (`phpunit.xml`) | `tests/Feature/Crm` + `tests/Unit/Crm` + `tests/Feature/Core` | **1.312 uji, 10.033 assertion hijau** (11 dilewati — semuanya khusus MySQL) |
| MySQL 8 (`phpunit.mysql.xml`) | `tests/Feature/Core` | **958 uji, 8.766 assertion hijau** (6 dilewati) |
| MySQL 8 (`phpunit.mysql.xml`) | `tests/Feature/Crm` + `tests/Unit/Crm` | **354 uji, 1.281 assertion hijau** |

Kaki MySQL dijalankan satu proses per basis data (aturan
`memory/mysql-test-suite.md`), dan ia yang membuktikan bahwa `renameColumn`
migrasi 000397 dan kolom `crm_activities` berperilaku sama di kedua driver.

**Gerbang rilis penuh (seluruh suite) adalah milik orkestrator** — paket ini
menjalankan Crm + Core, sesuai penugasannya.

### Sesudah putaran verifikasi (8 September 2026)

Dijalankan ulang setelah tiga belas perbaikan di atas; angkanya naik karena putaran itu
menambah 11 uji (dan `tests/Unit/Core` ikut dijalankan kali ini):

| Kaki | Berkas uji | Hasil |
|---|---|---|
| SQLite (`phpunit.xml`) | `tests/Feature/Crm` + `tests/Unit/Crm` + `tests/Feature/Core` + `tests/Unit/Core` | **1.561 uji, 11.127 assertion hijau** (11 dilewati — semuanya khusus MySQL) |
| MySQL 8 (`phpunit.mysql.xml`) | `tests/Feature/Core` | **961 uji, 8.776 assertion hijau** (6 dilewati) |
| MySQL 8 (`phpunit.mysql.xml`) | `tests/Feature/Crm` + `tests/Unit/Crm` | **368 uji, 1.352 assertion hijau** |

Harness: `S30_pipeline_crm` (23 syarat) · `S30_pipeline_crm_mobile` (4) ·
`S30_pipeline_crm_repair` (16) — 43 syarat, semuanya hijau, di atas salinan coretan basis data
demo (`database/database.sqlite` tidak disentuh; mtime tetap 7 Sep 14.17). Server `php -S`
pada porta 8144, dimatikan menurut PID-nya.
