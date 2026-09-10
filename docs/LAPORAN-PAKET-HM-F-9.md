# Laporan Paket HM F-9 — CSAT tiket via tautan sekali pakai

**Cabang:** `feat/phase2-f9` (dari `main` be5c3d7) · **Tanggal:** 10 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 2 baris F-9 (2 hari-orang) — **paket terakhir Fase 2**
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik)

---

## 0. Satu kalimat

Setelah sebuah tiket layanan selesai, tidak seorang pun di perusahaan ini pernah menanyakan
kepada pelanggannya apakah pekerjaannya baik. F-9 menambahkan satu pertanyaan itu — lewat
**tautan sekali pakai** yang polanya disalin dari `ExternalApprovalService` (bukan portal
pelanggan, yang roadmap tolak tertulis) — dan satu angka yang menjawabnya.

Perangkap paketnya bukan mengumpulkan penilaiannya; ia **menerbitkan angkanya tanpa berbohong**:
tiket yang belum dijawab bukan nol bintang, tautannya tidak pernah dikirim surel oleh sistem
ini, dan komentar pelanggan tentang seorang teknisi tidak boleh berakhir di endpoint yang lebih
longgar daripada tiketnya.

---

## 1. Tugas → status → bukti

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T1 | Migrasi ServiceDesk blok 001280, meniru sisi token `core_external_approvals` | ✅ | `2817372` — `2026_09_10_001280_create_svc_csat_ratings_table.php`; `token_hash char(64) nullable unique`, `expires_at`, `issued_by`, `revoked_at/by`; `CsatSchemaTest` 6 uji |
| T2 | `score` NULLABLE — tiket yang belum menjawab TIDAK punya skor | ✅ | `2817372` — mutasi M1 (`->nullable()` → `->default(0)`) **merah** |
| T3 | Enum skala 1..5 berlabel Indonesia, satu sumber untuk empat permukaan | ✅ | `2817372` — `CsatScore`; angka & label dipaku **literal** di uji, bukan dibaca dari enum yang diuji |
| T4 | Service penerbitan: hash tersimpan, polos **tepat sekali** | ✅ | `e4b9af5` — `CsatService::issue()`; uji memaku `token_hash` = sha256 dan bahwa token polos tidak ada di satu atribut baris pun |
| T5 | Sekali-pakai di bawah balapan (baca ulang TERKUNCI di transaksi) | ✅ | `e4b9af5` — `rate()`; uji "kalah pada baca ulang terkunci, bukan pada salinan usang"; mutasi M10 **merah** |
| T6 | **Satu tiket satu penilaian**, juga lewat tautan lain | ✅ | `e4b9af5` — dua undangan hidup, satu penilaian; mutasi M7 **merah** |
| T7 | **Yang dinilai tidak menerbitkan undangannya sendiri** (maker-checker CSAT) | ✅ | `e4b9af5` — `assertIssuerIsNotTheRatedTechnician`; peran `teknisi` memegang `svc.update`, dan penerbit melihat token polosnya; mutasi M6 **merah** |
| T8 | Tiket yang **dibuka kembali** menidurkan tautannya, tidak membunuhnya | ✅ | `e4b9af5` + `389576a` — status diperiksa lagi saat pelanggan menekan tombol; uji "selesai lagi → tautan yang sama hidup lagi"; mutasi M5/P8 **merah** |
| T9 | Halaman publik `/penilaian/{token}` di `Modules/ServiceDesk/Routes/web.php`, didaftarkan lewat ServiceProvider modulnya | ✅ | `389576a` — `routes/*` akar tidak disentuh; `php artisan route:list` mencetak dua rute |
| T10 | `throttle:10,1`, regex `[A-Za-z0-9-]{20,64}`, **tanpa** grup `web` — persis preseden | ✅ | `389576a` — uji membaca rutenya dari `Route::getRoutes()`; mutasi P4/P5/P6 **merah** |
| T11 | Token salah tidak membocorkan apa pun (perangkap F) | ✅ | `389576a` — dua tebakan berbeda → **byte identik**, GET maupun POST; mutasi P1 **merah**; §2(C) |
| T12 | Rata-rata **hanya dari yang dinilai**, jumlahnya selalu di sampingnya | ✅ | `9dad81f` — fixture 10 selesai / 3 diundang / 2 menjawab; tiga rumus salah memberi tiga angka berbeda (0,9 · 0,9 · 3,0), yang benar **4,5**; mutasi A1/A2/A3 **merah** |
| T13 | "Belum ada penilaian" adalah KALIMAT — `average` NULL, tidak pernah 0,0 | ✅ | `9dad81f` + `da6d0cb` — null di service, kalimat di layar; mutasi A3 **merah** |
| T14 | Endpoint + resource, komentar di **tepat dua** endpoint bergerbang `svc.view` | ✅ | `da6d0cb` — 4 rute; `CsatApiTest` sensus 7 permukaan lain, semuanya bersih |
| T15 | Tombol terbitkan di layar tiket, **kalimat yang benar tentang surel** | ✅ | `da6d0cb` — "Kirim tautan ini … lewat saluran Anda sendiri. Sistem tidak mengirimkannya."; harness memakunya |
| T16 | Penilaian terlihat pada tiketnya + ringkasan CSAT `#/csat` | ✅ | `da6d0cb` — kartu di detail tiket, layar di grup NAV Layanan |
| T17 | Uji PHP + mutasi | ✅ | **61 uji baru** (335 assertion) di 5 berkas; **28 mutasi dijalankan — 26 merah, 2 LOLOS HIJAU dan dilaporkan** (§4) |
| T18 | Harness S36 desktop + ponsel (halaman publik = konteksnya sendiri) | ✅ | `65ec12d` — `[S36_csat_tautan_penilaian] ok`, `[S36_csat_tautan_penilaian_ponsel] ok`; **27 → 29 kunci, nol kunci lama berubah**; 31 syarat hijau; 10 PNG |
| T19 | Cangkang PWA | ✅ | `da6d0cb` — `js/views/csat.js` ditambahkan ke `SHELL`, `SHELL_VERSION` **7 → 8**; `PwaServiceWorkerTest` 12 uji hijau |
| T20 | `/app/` dimuat di Chromium, 0 galat konsol di tiap layar tersentuh | ✅ | **54 pemuatan** (3 peran × 2 viewport × 8 rute + 6 halaman publik), `all_console_errors: []` di aplikasi, `http_4xx_5xx: []` — §6 |
| T21 | Halaman publik dimuat sebagai peramban **tanpa sesi** | ✅ | §6 — `document.cookie` kosong, `localStorage` kosong, **0 subresource**, 0 `<script>` |

---

## 2. Tiga keputusan yang membuat atau menggagalkan paket ini

### (A) Rata-rata hanya dari yang dinilai — dan penyebutnya ada TIGA yang berbeda

Ada tiga bilangan yang semuanya masuk akal disebut "penyebut CSAT", dan memilih yang salah
membuat seluruh angkanya tidak berguna:

| penyebut | apa artinya | pada fixture 10/3/2 |
|---|---|---|
| tiket **selesai** (`ratable`) | "berapa banyak yang bisa dinilai" | 9 / 10 = **0,9** |
| **undangan** terbit (`invited`) | "berapa banyak yang kita tanyai" | 9 / 3 = **3,0** |
| **jawaban** masuk (`rated`) | "berapa banyak yang menjawab" | 9 / 2 = **4,5** ← benar |

`CsatService::summary()` mengembalikan **ketiganya bersama-sama**, dan itu bukan kelengkapan
melainkan penjagaan: klien yang menerima `average` sendirian akan memajangnya sendirian.
Layarnya menuliskan keduanya dalam satu ekspresi (`averageText` + `averageBasis`), sehingga
tidak ada jalan menghapus yang kedua tanpa menyentuh yang pertama:

```
RATA-RATA KEPUASAN        TIKET SELESAI      TINGKAT JAWABAN      PUAS (4–5)
4,5 dari 5                10                 89%                  8 dari 8
8 dari 10 tiket           9 sudah diundang   8 dari 9 undangan    dari jawaban
selesai dinilai           menilai            dijawab              yang masuk
```
(`docs/bukti-uji/s36-ringkasan-csat.png`, diukur 10 Sep 2026 pada salinan DB demo.)

Fixture ujinya sengaja **tidak simetris**. Sebuah fixture di mana "selesai" = "diundang" =
"dinilai" akan hijau untuk keempat rumus sekaligus — itu persis bentuk kegagalan yang membuat
empat mutasi status `ModuleCounts` lolos hijau di P1-C.

**Nol penilaian adalah kalimat.** `average` NULL, bukan `0.0`; layar menulis "Belum ada
penilaian". Sebuah 0,0 di sini menjadi 0,0 di layar, di ekspor, dan di setiap tangkapan layar
yang dikirim ke pemilik.

### (B) Yang dinilai tidak boleh memegang undangannya

Peran `teknisi` memegang `svc.update` (`RoleSeeder`), dan penerbit tautan **melihat token
polosnya tepat sekali**. Digabung, keduanya berarti seorang teknisi bisa menerbitkan tautan
untuk tiketnya sendiri, membukanya, dan mencatat bintang lima — tanpa satu pun pelanggan
menyentuh apa pun. Rata-rata yang dihasilkan mengukur dirinya sendiri.

Ini maker-checker yang sama dengan `ExternalApprovalService::assertIssuerIsNotMaker`, dengan
sisi "maker" yang berbeda: di sana pengaju dokumen, di sini **orang yang dinilai**. Ditolak
saat terbit, dengan kalimat yang menyebut jalan keluarnya ("Minta rekan atau atasan Anda yang
menerbitkannya"). Petanya `users.employee_id` → `svc_tickets.assigned_to`.

Batasnya jujur: aturan ini menutup **penerbit = yang dinilai**, bukan **penerbit yang
berkolusi**. Dua teknisi yang saling menerbitkan tautan tetap bisa. Yang mencegah itu bukan
kode melainkan bahwa tautannya harus benar-benar sampai ke pelanggan agar penilaiannya berarti
— dan itu adalah alasan yang sama mengapa penilaian yang DIKETIK staf lewat telepon sengaja
tidak dibangun (§10).

### (C) Apa yang dibedakan token yang salah — dan apa yang tidak

Diputuskan, ditulis, dan diuji:

> **Tanpa memegang token yang sah, hanya ADA SATU jawaban.** Setiap token berbentuk benar yang
> tidak ada di tabel mendapat halaman 404 yang **byte-identik** — GET maupun POST, tanpa kode
> tiket, tanpa nama, tanpa panjang isi yang berbeda.
>
> **Dengan token yang sah, sebabnya memang dibedakan**, dan itu disengaja. Pemegangnya adalah
> pelanggan yang kita undang sendiri; menyembunyikan darinya bahwa tautannya kedaluwarsa — dan
> memberinya 404 yang sama dengan tebakan acak — membuatnya menyangka alamatnya salah ketik
> lalu diam. Yang dibedakan hanya SEBABNYA; isinya tetap **kode tiket + kalimatnya**.

| keadaan | HTTP | yang dibawa halaman |
|---|---|---|
| token tak dikenal | **404** | tidak ada apa pun (byte-identik untuk setiap tebakan) |
| tautan hidup | 200 | kode, judul pekerjaan, tanggal selesai, 5 tombol, kotak komentar |
| sudah dinilai (tautan INI) | 200 | struk: skor + komentar **milik pemegangnya sendiri** |
| dicabut | **410** | kode tiket + "dicabut oleh penerbitnya" |
| kedaluwarsa | **410** | kode tiket + tanggal kedaluwarsanya |
| tiket sudah dinilai lewat tautan **lain** | **410** | kode tiket saja — **bukan** skor dan **bukan** komentar orang lain |
| tiket **dibuka kembali** | **409** | kode tiket + "tautan Anda tetap berlaku" |

409 dan bukan 410 untuk tiket yang dibuka kembali: 410 berarti hilang selamanya, sedangkan
tautannya justru **hidup lagi** begitu tiketnya selesai lagi. 409 dan bukan 422: isian
pelanggannya benar; yang berubah adalah keadaan dunia.

Halaman terminal **tidak** membawa judul tiket maupun nama penerima undangan — pelajaran
halaman persetujuan eksternal: tautan mati bisa dibuka siapa pun yang menerimanya diteruskan.

---

## 3. Perangkap D — tidak ada satu surel pun yang terkirim

`MAIL_MAILER=log` di `.env` pengembangan **dan** di `.env` produksi; SMTP adalah paket P-3a yang
belum dikerjakan. Maka:

- **tidak satu baris pun** di `CsatService` mengirim apa pun;
- `recipient_email` disimpan sebagai **arsip untuk siapa undangan diterbitkan**, bukan alamat
  kirim — peran yang sama persis dengan kolom `email` pada persetujuan eksternal;
- dialog penerbitan berbunyi: *"Kirim tautan ini kepada <nama> lewat saluran Anda sendiri
  (WhatsApp/e-mail). **Sistem tidak mengirimkannya.**"* dan kolom e-mailnya berlabel
  *"Opsional, arsip untuk siapa tautan diterbitkan — sistem tidak mengirim e-mail."*;
- kartu tiketnya mengulang: *"URL-nya hanya tampil sekali saat diterbitkan, dan **Anda** yang
  mengirimkannya kepada pelanggan — sistem ini tidak mengirim e-mail."*

Halaman publiknya sendiri **tidak menyebut kata "e-mail", "email", atau "surel" sama sekali**
— dipaku uji (`test_the_public_page_never_promises_an_email`) dan diukur lagi oleh harness
(`the_public_page_never_promises_an_email`). Sebuah halaman yang menutup dengan "kami akan
mengabari Anda lewat e-mail" adalah janji yang tidak akan pernah ditepati.

---

## 4. Mutasi — 28 dijalankan, 26 merah, **2 LOLOS HIJAU** dan dilaporkan begitu

### Merah (26)

| # | Mutasi | Uji yang memerahkannya |
|---|---|---|
| M1 | `score` `->nullable()` → `->default(0)` | `an_unrated_row_stores_a_null_score_not_a_zero` |
| M2 | `token_hash` tanpa `unique()` | `two_rows_cannot_share_a_token_hash` |
| M4 | `DEFAULT_VALIDITY_DAYS` 14 → 7 | `a_link_lives_fourteen_days_by_default` |
| M5 | `RATABLE` + `in_progress` | `only_a_finished_ticket_can_be_invited_to_rate` (+1) |
| M6 | `assertIssuerIsNotTheRatedTechnician` dihapus | `the_rated_technician_cannot_issue_the_link…` |
| M7 | penjaga "satu penilaian per tiket" di `rate()` dimatikan | `one_ticket_carries_exactly_one_rating…` |
| M8 | komentar disisipkan ke badan lonceng | `the_bell_carries_the_score_but_never_the_comment` |
| M9 | komentar kosong disimpan `''` alih-alih `null` | `an_empty_comment_is_stored_as_nothing…` |
| M10 | `isRated()` tidak diperiksa di `rate()` | `a_token_is_single_use…` (+1) |
| P1 | `unknown()` menyebut tokennya | `every_unknown_token_gets_one_identical_page…` |
| P2 | halaman terminal memajang judul + nama penerima | `a_revoked_link_is_gone_and_leaks_neither…` |
| P3 | tautan kedua memajang skor & komentar tautan pertama | `a_second_invitation_never_shows_the_first_persons_score` |
| P4 | `throttle:10,1` dilepas | `both_public_routes_are_throttled…` |
| P5 | regex token `[A-Za-z0-9-]{20,64}` → `.*` | `a_token_that_is_not_token_shaped…` |
| P6 | grup `web` dipasang pada kedua rute | `both_public_routes_are_throttled…` |
| P7 | nama teknisi ditambahkan ke ringkasan publik | `the_public_form_never_names_the_technician…` |
| P8 | tiket yang dibuka kembali tetap boleh dinilai | `a_reopened_ticket_answers_409…` |
| P9 | skor dibaca `(int) $request->input('score')` | `a_score_outside_the_scale_is_refused` |
| A1 | penyebut rata-rata = `ratable` | 3 uji |
| A2 | penyebut rata-rata = `invited` | 2 uji |
| A3 | kosong dijawab `0.0` alih-alih `null` | 2 uji |
| A4 | universe memuat `cancelled` | `open_and_cancelled_tickets_are_not_part…` |
| A5 | jendela waktu memakai `reported_at` | `the_window_filters_on_when_the_work_finished` |
| A6 | baris ber-`rated_at` tanpa skor ikut penyebut | `a_rated_row_without_a_score…` |
| P10 | cabang `isRated()` dilucuti dari jalan masuk POST-tanpa-skor | `a_post_without_a_score_on_a_used_link_is_still_a_receipt` |
| P11 | `TicketStatus::Closed` diberi transisi keluar `[InProgress]` | `a_closed_ticket_has_no_way_back…` |

### LOLOS HIJAU — dan apa yang dilakukan terhadapnya (2)

**M3 — `CsatRating::isExpired()` `<=` diganti `<`.** Tepi "expires_at = sekarang sudah
kedaluwarsa" adalah satu-satunya hal yang diuji uji itu, dan ia **hijau untuk kedua operator**.
Sebabnya diukur: cast `'datetime'` membuang mikrodetik, jadi nilai yang dibaca ulang selalu
sedikit lebih kecil dari `now()`. Ia tetap hijau setelah `freezeTime()` ditambahkan (waktu beku
pun ber-mikrodetik). Yang memerahkannya: `travelTo(Carbon::parse('2026-09-10 09:00:00'))` —
**detik bulat**, sehingga tepinya benar-benar menjadi satu titik waktu. Uji ditambah dua
assertion (satu detik sesudah, satu detik sebelum).

**A7 — `CsatScore::isSatisfied()` `>= 4` diganti `>= 3`.** Fixture ringkasannya berisi jawaban
5 dan 4 saja, dan pada fixture itu kedua ambang memberi angka yang **sama**. Yang memerahkannya:
uji baru berfixture 5·4·3·2·1 — **3 adalah satu-satunya nilai yang membedakan ambangnya**.

---

## 5. Empat cacat yang ditemukan uji dan peramban sendiri (bukan oleh mutasi)

**D1 — rata-rata 2,5 dari satu jawaban bintang 5.** `summary()` melewati baris ber-`rated_at`
tanpa skor dari penjumlahan tetapi **tetap menghitungnya sebagai penyebut**: nol bintang lewat
pintu belakang. Satu baris cacat di samping satu skor 5 mencetak 2,5. Pembilang dan penyebut
sekarang dihitung di loop yang sama. (`9dad81f`)

**D2 — "4.5" tercatat sebagai penilaian 4 bintang.** `(int) $request->input('score')` menerima
`"4.5"` sebagai 4, `"4abc"` sebagai 4, dan `" 5 "` sebagai 5 — sebuah nilai yang **tidak pernah
ditawarkan halamannya** tercatat sebagai penilaian pelanggan, dibulatkan tanpa satu kata pun.
Diganti perbandingan ketat terhadap lima nilai yang benar-benar dicetak. (`389576a`)

**D3 — rata-rata 4,6 tampil sebagai "5".** `fmt.num(value, decimals)` hanya mengenal 0 dan 2
desimal (`decimals === 2` memilih `money2`, apa pun yang lain jatuh ke `money0`), jadi
`fmt.num(4.6, 1)` mencetak `"5"`. Ditemukan **di peramban**, bukan di suite. Layar CSAT memakai
formatter satu-desimalnya sendiri; sebagai bonus, "5,0 dari 5" terbaca sebagai rata-rata
sedangkan "5 dari 5" terbaca sebagai satu penilaian. Disensus 10 Sep 2026: **tidak ada pemanggil
`fmt.num` lain di seluruh SPA yang meminta desimal selain 0 atau 2**, dan keenam kolom
ber-`decimals:` di `schema.js` bernilai `2` — jadi D3 tidak menyentuh layar lain pada tanggal itu.
Lihat §11. (`da6d0cb`)

**D4 — janji "tidak pernah formulir lagi" dibatalkan lewat pintu belakang.** Halaman publik
punya DUA jalan masuk: `show()` dan cabang "pilih dulu bintangnya" di dalam `rate()`. Yang
kedua punya daftar keadaan matinya sendiri, dan daftar itu memeriksa dicabut / kedaluwarsa /
sudah-dinilai-lewat-tautan-lain tetapi **tidak** memeriksa "tautan INI sendiri sudah dipakai".
Akibatnya satu POST tanpa skor pada tautan terpakai mengembalikan **formulir berikut lima
tombolnya** — persis hal yang §2(C) berjanji tidak akan pernah terjadi. Ditemukan saat menutup
perangkap C, bukan oleh mutasi: mutasinya baru bisa ditulis setelah cacatnya ada namanya.
Diperbaiki dengan memanggil `stateFor()` yang sama, sehingga tidak ada dua daftar keadaan yang
bisa berselisih. (`bd0fe52`)

---

## 6. Permukaan — daftar lengkap aturan baru, diperiksa satu per satu

Cacat yang berulang di kampanye ini: *"aturan yang benar ditegakkan di SATU permukaan tetapi
bocor di permukaan lain yang sama."* Aturannya:

> **Komentar pelanggan** — teks bebas tentang seorang teknisi yang namanya ada di tiket itu —
> bergerbang `permission:svc.view`, dan tidak pernah lebih longgar.

Perlu ditulis terang: gerbang itu **lebih ketat daripada tiketnya sendiri**. Rute baca modul
ini (dan seluruh aplikasi ini) hanya bersesi — `GET servicedesk/tickets/{ticket}` tidak
menuntut satu izin pun — jadi "gerbang yang sama dengan tiketnya" akan berarti "siapa pun yang
punya akun".

| # | Permukaan | Membawa komentar? | Bukti |
|---|---|---|---|
| 1 | `CsatService::ratedQuery()` | ya (pemanggilnya wajib memeriksa) | docblock; hanya dipanggil controller ber-gerbang |
| 2 | `GET servicedesk/tickets/{id}/csat` | **ya** — `svc.view` | `a_session_without_svc_view_cannot_read_the_comment` |
| 3 | `GET servicedesk/csat-summary` | **ya** — `svc.view` | idem |
| 4 | `CsatRatingResource` | ya (dipakai hanya oleh 2 & 3) | tanpa `token_hash`, dipaku uji |
| 5 | `TicketResource` | **tidak — nol kunci CSAT** | `the_ticket_resource_carries_no_csat_key_at_all` |
| 6 | `GET servicedesk/tickets` (daftar **dan** pemilih `lookup.js`) | tidak | `no_other_ticket_surface_carries_the_comment` |
| 7 | `GET servicedesk/tickets-sla-breaches` | tidak | idem |
| 8 | `GET core/search` (pencarian global) | tidak | idem |
| 9 | Cetakan house-form (`ServiceDeskFormService`) | tidak | `the_printed_ticket_form_carries_no_comment` |
| 10 | Ekspor XLSX daftar | tidak — ia menulis kolom `schema.js`, dan tidak ada kolom CSAT | konsekuensi #5 |
| 11 | Registri Laporan Bebas (`ReportableResources`) | tidak | `the_csat_table_is_not_a_free_report_source` |
| 12 | Lonceng notifikasi (`svc.update`) | **tidak — skor saja** | `the_bell_carries_the_score_but_never_the_comment` |
| 13 | Halaman publik | hanya komentar **milik pemegang tautan itu sendiri** | `a_second_invitation_never_shows_the_first_persons_score` |
| 14 | Kartu SPA di detail tiket | ya — dan kartunya `return null` tanpa `svc.view` | `csatCard()` |
| 15 | Layar `#/csat` | ya — rutenya `accessDenied` tanpa `svc.view` | `app.js` |
| 16 | `ModuleCounts`, widget dasbor, `WatchedDeadlines`/`Thresholds` | tidak menyentuh `svc_csat_ratings` | tidak ada entri baru |

**Apakah teknisi yang dinilai boleh membaca penilaian tentang dirinya? YA** — dan itu
keputusan, bukan kelalaian. Peran `teknisi` memegang `svc.view`, namanya sudah ada di tiket
itu, dan umpan balik yang tidak boleh dibacanya adalah umpan balik yang tidak mengubah apa pun.
Yang **tidak** dibangun adalah papan peringkat teknisi (§10) — agregat per orang adalah
artefak manajemen kinerja yang butuh keputusan pemilik, bukan efek samping fitur dukungan.

---

## 7. Bukti peramban (pelajaran P1-I: rilis SPA belum terverifikasi sampai DIMUAT)

Chromium sungguhan, server `php -S` pada port 8211 atas **salinan** basis data demo
(`database/database.sqlite` tidak disentuh — dibuktikan `mtime` 7 Sep dan
`erp:mysql-preflight` yang membaca 190 tabel tanpa `svc_csat_ratings`).

```
3 peran (admin, teknisi, direktur) × 2 viewport (1440×900, 390×844) × 8 rute
  dashboard · csat · sla-breaches · r/servicedesk/tickets
  d/servicedesk/tickets/1 (sudah dinilai) · d/servicedesk/tickets/3 (belum selesai)
  tenggat · home
                                            = 48 pemuatan
+ halaman publik × 3 keadaan × 2 viewport   =  6 pemuatan
                                              --------------
                                              54 pemuatan

all_console_errors : []      (di dalam aplikasi)
http_4xx_5xx       : []      (setiap permintaan /api/)
```

Halaman publik diukur terpisah, **peramban tanpa sesi**, satu keadaan per konteks:

| keadaan | HTTP | galat konsol | subresource yang diminta |
|---|---|---|---|
| tautan hidup | 200 | — | **0** |
| struk (sudah dinilai) | 200 | — | **0** |
| token tak dikenal | **404** | 1 × *"Failed to load resource: 404"* | **0** |

Satu-satunya baris konsol pada halaman publik adalah Chromium melaporkan **404 dokumennya
sendiri** — yaitu justru perilaku yang paket ini tuntut. `document.cookie` kosong,
`localStorage` kosong, `0` subresource, `0` `<script>`: halaman ini utuh di ponsel tanpa
sinyal bagus dan tidak menyeret satu byte pun dari mana pun.

**Harness S36** (`docs/bukti-uji/results-phase-2.json`, kunci `S36_csat_tautan_penilaian` dan
`S36_csat_tautan_penilaian_ponsel`): **27 → 29 kunci, nol kunci lama berubah satu byte pun**
(diperiksa dengan membandingkan JSON kanonik per kunci sebelum menulis). 18 + 13 = **31 syarat
hijau**. Sepuluh PNG di `docs/bukti-uji/s36-*.png`.

Yang S36 buktikan dan uji PHP tidak bisa:

1. **URL polosnya sampai ke tangan manusia.** Skenario tidak memanggil API untuk menerbitkan;
   ia menekan tombolnya, mengisi dialognya, **menyalin URL dari kotak di layar**, lalu
   benar-benar membukanya di konteks lain.
2. **Pelanggannya tidak punya sesi.** `document.cookie === ''`, `localStorage` kosong, tanpa
   `<script>`.
3. **Rata-ratanya berdesimal dan tidak pernah sendirian.** Regex `^\d+,\d dari 5$` pada nilai
   ubinnya, syarat terpisah bahwa baris penopangnya menyebut "dinilai", dan di ponsel: tidak
   satu label/angka/penopang pun terpotong (`scrollWidth > clientWidth`).
4. **Lima tombol skor muat dan setinggi ≥ 40 px** di 390 px — lantai sasaran jempol yang sama
   dengan baris radio dialog (CONVENTIONS §13). Lima tombol setinggi 22 px adalah penilaian
   yang tidak pernah masuk.

---

## 8. Gerbang

| Gerbang | Hasil |
|---|---|
| `tests/Feature/ServiceDesk` (SQLite) | **107 uji, 537 assertion — hijau** |
| `tests/Feature/ServiceDesk` (MySQL 8, `erp_dryrun`, `phpunit.mysql.xml`) | **107 uji, 537 assertion — hijau** |
| `tests/Feature/Core` (SQLite) | **1018 lulus, 11 dilewati — hijau** |
| `php artisan erp:mysql-preflight` | **Verdict: ok** (6 situs SQLite-only, semuanya lama dan berpenjaga) |
| `./vendor/bin/pint --test` atas seluruh berkas paket ini | **passed** (dua kegagalan lama di `main` tidak disentuh) |
| Harness S36 desktop + ponsel | **ok / ok**, 31 syarat hijau |
| Migrasi atas salinan basis data demo hidup | `2026_09_10_001280… DONE` (5,74 ms) |

Suite penuh **belum** dijalankan — gerbang rilis dijalankan terpisah, sesuai instruksi paket.

---

## 9. Keputusan pemilik

Lima hal yang sudah diputuskan **di dalam kode** dan bisa dibalik pemilik dengan biaya kecil:

1. **Masa berlaku undangan 14 hari** (persetujuan eksternal 7). MK yang ditunggu tanda
   tangannya membuka tautannya hari itu juga karena pekerjaan berhenti menunggunya; pelanggan
   yang diminta menilai tidak menunggu apa pun, dan undangan yang mati di hari ketujuh hilang
   bersama cuti seminggu satu orang. Kalau pemilik ingin angka lain, tempatnya `SettingService`,
   bukan konstanta — satu perubahan, satu uji yang angkanya dipaku literal.
2. **Satu tiket satu penilaian, selamanya.** Tiket yang sudah dinilai tidak bisa menerima
   undangan baru — juga bila ia dibuka kembali dan dikerjakan lagi berminggu-minggu kemudian.
   Alternatifnya (penilaian kedua yang menggantikan, dengan jejak) bisa dibenarkan, tetapi ia
   mengubah arti "rata-rata per tiket" dan harus diputuskan sebelum ada data.
3. **Teknisi yang dinilai boleh membaca penilaian tentang dirinya** (§6). Bila pemilik ingin
   sebaliknya, itu bukan satu `if`: ia menuntut gerbang per-baris pada dua endpoint, kartu
   tiket, layar ringkasan, dan setiap ekspor yang kelak dibuat — dan tetap tidak bisa mencegah
   atasannya menunjukkannya.
4. **Lonceng penilaian pergi ke pemegang `svc.update`, tanpa komentarnya.** Bila pemilik ingin
   skor rendah (1–2) berbunyi ke meja yang lebih tinggi, izin tujuannya berubah — dan
   komentarnya tetap tidak boleh ikut, karena itulah yang membuat gerbangnya berarti.
5. **Tautan diterbitkan MANUAL, satu per satu.** Tidak ada penerbitan otomatis saat tiket
   ditutup (§10) — pilihan yang langsung berhubungan dengan keputusan pemilik yang lebih besar:
   **SMTP (P-3a)**. Selama tidak ada surel, penerbitan otomatis hanya mencetak token yang tidak
   dikirim siapa pun.

---

## 10. Yang TIDAK dikerjakan

- **BASIS PENGETAHUAN — DITUNDA.** Roadmap baris F-9 menuliskannya begitu ("basis pengetahuan
  DITUNDA"), dan paket ini **tidak membangun satu barisnya**: tidak ada tabel artikel, tidak
  ada layar, tidak ada pencarian artikel, tidak ada tautan "artikel terkait" pada tiket, dan
  tidak ada satu entri registri pun yang menyiapkannya. Ia disebut di sini supaya
  penundaannya tertulis, bukan dilewati diam-diam.
- **Pintu kedua penilaian (staf mengetikkan nilai pelanggan lewat telepon).** Sengaja tidak
  dibangun: orang yang dinilai memegang keyboard yang sama, dan sebuah CSAT yang boleh diketik
  staf adalah CSAT yang tidak bisa dipercaya angkanya. Kolom `rated_via` tetap ada agar
  ketiadaan pintu itu terbaca di lapisan penyimpanan — dan agar, bila pintunya kelak dibuka,
  setiap rata-rata bisa memisahkan keduanya. Dipaku uji: setiap baris yang bisa ditulis sistem
  ini hari ini ber-`rated_via = 'link'`, dan hanya SATU tempat di service yang menstempel
  `rated_at`.
- **Papan peringkat / agregat per teknisi.** Angka "rata-rata CSAT Joko Susilo" adalah artefak
  manajemen kinerja, bukan efek samping fitur dukungan; ia butuh keputusan HR (dan mungkin
  perjanjian kerja), bukan satu `GROUP BY`. Ringkasan yang ada menyaring per **kontrak layanan**
  dan per **pelanggan**, tidak pernah per orang.
- **Penerbitan otomatis saat tiket ditutup**, dan **pengingat undangan yang belum dijawab.**
  Keduanya menunggu SMTP (P-3a); tanpa surel, keduanya hanya mencetak token yang tidak dikirim
  siapa pun. Bila kelak dibutuhkan, tempat pengingatnya adalah registri tenggat
  (`WatchedDeadlines`) — bukan cron baru; catatan yang sama sudah ditulis
  `docs/PERSETUJUAN-EKSTERNAL.md` untuk tautan persetujuan.
- **Widget dasbor CSAT dan entri `ModuleCounts`.** Angka utama modul Layanan tetap "Tiket belum
  selesai": itu angka yang mengubah rencana hari ini, sedangkan CSAT mengubah rencana kuartal
  ini. Menambahkannya berarti satu entri registri + satu berkas widget, kapan pun pemilik mau.
- **Penyaring rentang tanggal di layar `#/csat`.** Endpoint-nya sudah menerima `from`/`to`
  (dan mengukurnya pada `resolved_at`, bukan `reported_at`), tetapi layarnya belum punya
  kendalinya — jadi layarnya **mengatakan** apa yang ditampilkannya: *"Angka di bawah mencakup
  SELURUH riwayat tiket yang sudah selesai, bukan satu bulan."* Sebuah angka tanpa rentang
  terbaca sebagai "bulan ini" oleh siapa pun yang membacanya di rapat bulanan.
- **CSAT di Laporan Bebas dan di ekspor XLSX.** Sengaja: menambahkannya berarti komentar
  pelanggan bisa disusun ulang, disimpan, dan **dibagikan per peran** oleh mesin laporan —
  permukaan yang jauh lebih luas daripada dua endpoint hari ini, dan yang gerbangnya bukan lagi
  gerbang tiketnya.
- **Nada suara skor rendah.** Tidak ada eskalasi, tugas otomatis, atau tiket tindak lanjut yang
  lahir dari bintang satu. Yang ada hanya lonceng.

---

## 11. Deviasi baru yang ditemukan

| # | Deviasi | Status |
|---|---|---|
| **DV-F9-1** | `fmt.num(value, decimals)` di `public/app/js/format.js` **diam-diam mengabaikan** setiap `decimals` selain 2 dan jatuh ke nol desimal, sehingga `fmt.num(4.6, 1)` mencetak `"5"`. Tanda tangannya menjanjikan jumlah desimal bebas; implementasinya memberi dua pilihan. | **Belum diperbaiki — sengaja.** Layar CSAT memakai formatter sendiri, dan sensus 10 Sep 2026 menunjukkan **tidak ada pemanggil lain** yang meminta desimal selain 0/2 (keenam kolom ber-`decimals:` di `schema.js` bernilai `2`). Memperbaiki `fmt.num` menyentuh **18 pemanggilan di 7 berkas** di luar paket ini (diukur 10 Sep 2026: 21 pemanggilan di 8 berkas, 3 di antaranya milik `views/csat.js`) — perubahan yang pantas dapat pakunya sendiri, bukan menumpang F-9. |
| **DV-F9-2** | Uji tepi waktu yang membandingkan kolom ber-cast `'datetime'` dengan `now()` **tidak bisa membedakan `<` dari `<=`** kecuali waktunya dibekukan pada **detik bulat** — `freezeTime()` saja tidak cukup, karena waktu beku pun ber-mikrodetik sementara cast membuangnya. Dua mutasi lolos hijau sebelum ini dipahami. | **Diperbaiki di dalam paket** (uji CSAT). Preseden yang sama di `ExternalApprovalPublicTest::test_expiry_is_enforced_at_the_exact_boundary` **kebetulan aman** karena ia ber-`travelTo($row->expires_at)` dari nilai yang sudah dibaca DB (sudah terpotong ke detik). Dicatat di sini supaya penulis uji tepi berikutnya tahu sebabnya. |
| **DV-F9-3** | Harness yang membuka halaman ber-`throttle` mati di `wait_for_selector` dengan pesan yang menuduh halamannya rusak, bukan menyebut 429-nya. | **Diperbaiki di dalam paket** (`_f9_open_public`). Sejenis dengan cacat "nama skenario tidak dikenal dilewati diam-diam" yang diperbaiki di F-7: sebuah kegagalan harness yang menyebut sebab yang salah membuang waktu mencari bug yang tidak ada. |
| **DV-F9-4** | Rute BACA di seluruh aplikasi ini hanya bersesi (`GET servicedesk/tickets/{ticket}` dan puluhan saudaranya tidak menuntut `*.view`), sementara NAV SPA menyaring dengan `perm: 'svc.view'`. Artinya izin `*.view` hari ini adalah **kendali tampilan, bukan kendali akses**. | **Di luar lingkup F-9, tidak diubah.** Paket ini menutup bagiannya dengan menggerbangi endpoint CSAT-nya sendiri di `svc.view` — lebih ketat daripada tetangganya. Menutup deviasinya sendiri berarti menyentuh ratusan rute di 14 modul dan berisiko memutus peran yang hari ini bekerja; ia butuh paketnya sendiri dan keputusan pemilik. |

---

## 12. Commit

| commit | isi |
|---|---|
| `2817372` | tabel `svc_csat_ratings` (blok 001280), enum `CsatScore`, model `CsatRating` |
| `e4b9af5` | `CsatService` — issue / revoke / rate, enam aturan |
| `389576a` | rute web modul + `CsatPageController` + Blade publik |
| `9dad81f` | `summary()` / `ratedQuery()` — rata-rata yang jujur |
| `da6d0cb` | endpoint, resource, request, kartu tiket, layar `#/csat`, NAV, `sw.js` |
| `65ec12d` | harness S36 desktop + ponsel, `results-phase-2.json`, 10 PNG |
| `346625c` | laporan paket |
| `bd0fe52` | verifikasi penutup: D4 (formulir yang kembali pada tautan terpakai) + dua paku baru |

**Blok migrasi:** ServiceDesk `001200–001299` masih longgar — `001280` dipakai, `001290` bebas.
Tidak ada blok lanjutan yang perlu didaftarkan di CONVENTIONS §2.
