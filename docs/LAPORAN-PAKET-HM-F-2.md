# Laporan Paket F-2 (ROADMAP-HASHMICRO Fase 2) — Anggaran vs realisasi, overhead, revisi RAP, registri ambang

Branch: `feat/phase2-f2` (dari `main` cfbdf84) · 7 September 2026 · **paket kedua Fase 2**

> **Klaim tengah paket ini satu kalimat: ANGKA DI LAYAR ADALAH ANGKA YANG MENOLAK PO.**
>
> Sampai paket ini, hanya SATU tempat di seluruh aplikasi yang tahu berapa sisa anggaran sebuah
> proyek: tiga metode privat di dalam `BudgetGateService`, dipanggil hanya pada detik sebuah PO/SPK
> diajukan, dan tidak pernah ditampilkan di layar mana pun. Sebuah layar portofolio yang menghitung
> sendiri akan menjadi **jawaban kedua atas satu pertanyaan** — dan hari keduanya berselisih adalah
> hari seorang manajer proyek membaca "sisa Rp 80 juta" lalu ditolak saat memesan Rp 50 juta.
>
> Maka aritmetikanya pindah ke satu kelas dan **gerbangnya membacanya**; kesetaraannya tidak
> dilihat, melainkan **dibuktikan di batasnya** — PO sungguhan ber-DPP tepat sebesar angka yang
> dicetak layar LOLOS, satu sen di atasnya 422. Diukur di Chromium atas salinan data demo:
> **Rp 31.123.865.391 diterima, Rp 31.123.865.391,01 ditolak.**
>
> Dua migrasi: `fin_overhead_budgets` (**slot pertama blok lanjutan Finance 001500–**) dan kolom
> revisi pada `est_cost_budgets` (000670). Nol dependensi, nol pustaka vendor, nol perubahan
> konfigurasi server.
>
> **Verifikasi peramban sungguhan dijalankan**: S29 (kini 25 syarat) + S29m ponsel (3 syarat),
> keduanya hijau — dan menemukan **dua cacat yang lolos dari 2.100+ uji PHP yang hijau**, termasuk
> satu tanda kurung yang mematikan SELURUH SPA. Lihat § Yang hanya ditemukan peramban.
>
> **PUTARAN VERIFIKASI (7 Sep 2026) menemukan tiga belas temuan lagi, tiga di antaranya
> BREAKS_SOMETHING** — semuanya diperbaiki di akarnya pada branch ini. Yang terbesar: klaim
> tengah paket ini sendiri ternyata dilanggar layar-layarnya, karena gerbang menghakimi PER SISI
> (PO terhadap non-subkon, SPK terhadap subkon) sementara empat permukaan mencetak sisa TOTAL dan
> menjanjikan bahwa gerbang menegakkannya. Lihat § Putaran verifikasi.

## Yang ditutup (ROADMAP-HASHMICRO Fase 2 / F-2, baris 208 → status)

| Klausa kontrak | Status | Bukti |
|---|---|---|
| `WatchedThresholds` (Core, saudara `WatchedDeadlines`; limit null = digaris) | ✅ | `Core\Support\WatchedThresholds`, **5 keadaan**, 3 entri · `ThresholdWatchTest` (10 uji) · CONVENTIONS §24 |
| layar per proyek × bulan (anggaran bulanan = RAP × fase baseline, BERLABEL) | ✅ | `BudgetRealisationService::monthly()` + `#/anggaran` tab "Per bulan" · `BudgetMonthlyTest` (8 uji) · S29 `the_monthly_budget_is_labelled_derived` (17 bulan diukur) |
| tanpa baseline → digaris | ✅ | `budget_state = tanpa_baseline` · S29 `and_invents_no_monthly_number_at_all` (**0 bulan, 0 rupiah** di layar) |
| bulan kosong → null | ✅ | `BudgetMonthlyTest::test_a_month_without_realisation_is_null_never_zero` · S29 `months_without_realisation_are_ruled_not_zero` |
| layar portofolio, angka SAMA dengan `BudgetGateService`, uji kesetaraan | ✅ | gerbang **membaca** kelas yang sama · `BudgetPortfolioEqualityTest` (12 uji, 8 keadaan) · S29 dua PO sungguhan di batasnya |
| `fin_overhead_budgets` (OVB, Approvable, satu approved/tahun) | ✅ | migrasi 001500 + `OverheadBudgetService` · `OverheadBudgetTest` (17 uji) — ditolak di **layanan DAN di basis data** |
| realisasi OVB dari jurnal | ✅ | debit − kredit baris jurnal **terposting** pada akun yang dianggarkan · uji memakai jurnal 31 Des dan jurnal draf |
| revisi RAP (`revision`, `superseded_by_id`, riwayat selisih) | ✅ | migrasi 000670 + `RapService::revise/approve/revisionChain/revisionDiff` · `RapRevisionTest` (11 uji) |
| peringatan `project_budget_pct` ≥ 90 % | ✅ | registri T2.1 + **formulir PO/SPK + layar proyek** · `ProjectBudgetWarningTest` (5 uji) · S29 empat syarat |
| blok lanjutan CONVENTIONS §2 (Finance 001500–, Projects 001600–) | ✅ | tabel baru di §2 — **Projects 001600 DIDAFTARKAN tetapi tidak dipakai**: F-2 tidak butuh migrasi Projects |

## Commit pembangunan

| Commit | Isi |
|---|---|
| `ac65a32` | T2.1 `WatchedThresholds` + layar `#/ambang` + config `erp.thresholds.*` |
| `066a861` | T2.2+T2.3 `BudgetRealisationService`, gerbang membacanya, layar `#/anggaran`, uji kesetaraan |
| `d10fcf6` | T2.4 OVB (migrasi 001500, model/layanan/layar), jenis dokumen ke-29, CONVENTIONS §2 |
| `036debb` | T2.5 revisi RAP (migrasi 000670), rantai append-only, riwayat selisih |
| `f22f4d3` | T2.6 peringatan ≥ 90 % di formulir PO/SPK dan layar proyek (`liveNote`) |
| `098ebdb` | T2.7 harness S29 + S29m, hasil digabung, dan dua cacat yang ditemukannya |
| `b071706`, `c1b8c42` | T2.8 dokumentasi + tangkapan layar registri Ambang |

Commit putaran verifikasi ada di § Putaran verifikasi — satu commit per temuan.

## Bukti kesetaraan — yang paling penting di paket ini

Kesetaraan **struktural**: sejak `066a861`, `BudgetGateService::assertWithinBudget` memanggil
`BudgetRealisationService::side()`. Tidak ada dua implementasi yang bisa berselisih karena hanya
ada satu. Kalimat penolakan, pembelahan subkon/non-subkon dan kebijakan `warn`/`block`/`off` tidak
berubah satu huruf pun.

Kesetaraan **yang diuji dari luar** — karena membandingkan dua pemanggilan kelas yang sama
membuktikan nol:

| Keadaan | Yang dicetak layar | Yang dilakukan gerbang |
|---|---|---|
| anggaran normal, sisa non-subkon Rp 25.000.000 | Sisa PO Rp 25.000.000 | PO Rp 25.000.000 **diterima**; Rp 25.000.000,01 **422** `budget` |
| sisa subkon Rp 130.000.000 | Sisa SPK Rp 130.000.000 | SPK Rp 130.000.000 **diterima**; +Rp 0,01 **422** |
| tanpa RAP disetujui | RAP/Sisa/Terpakai ketiganya "—" | **diam** — PO Rp 10 miliar lolos, karena tidak ada aturannya |
| tepat di anggaran | 100 %, keadaan "Melampaui" | rupiah berikutnya ditolak |
| lampau anggaran | **140 %**, tidak dijepit ke 100 | kalimatnya tetap "sisa Rp 0" — tidak pernah menjanjikan sisa negatif |
| nilai kontrak 0 | digaris (= belum dicatat) | tidak mengubah satu angka anggaran pun |
| **RAP direvisi** | sisa pindah ke revisi pada detik persetujuannya | batas atas gerbang ikut pindah, diuji sebelum **dan** sesudah |
| **sisi habis, total masih lega** (non-subkon Rp 100 jt habis, subkon Rp 500 jt utuh) | kalimat sisi PO "menyisakan Rp 0", keadaan **Melampaui** — walau totalnya 16,7 % terpakai | PO Rp 1 **ditolak**, SPK Rp 500.000.000 **diterima** (putaran verifikasi) |
| sisi yang RAP-nya **tidak menganggarkan** apa pun | "SPK tidak dianggarkan", bukan "sisa Rp 0" | menolak setiap SPK sampai pelampauannya dikonfirmasi |

Dan di peramban, atas salinan data demo (S29): PO **Rp 31.123.865.391** diterima; PO
**Rp 31.123.865.391,01** ditolak 422 pada kunci `budget` dengan kalimat yang menyebut
"menyisakan Rp 31.123.865.391" — angka yang sama yang tercetak di barisnya.

## Enam keadaan registri ambang, dan kenapa tiga di antaranya bukan angka

| Keadaan | Artinya | Yang dicetak |
|---|---|---|
| `aman` | di bawah ambang peringatan | persentasenya |
| `mendekati` | ≥ ambang, masih di bawah batas | persentasenya, berwarna |
| `lampau` | **tepat 100 % ada di sisi ini** — dan **batas Rp 0 yang sudah dibelanjakan** | persentasenya, merah |
| `tanpa_anggaran` | batasnya DIKETAHUI nol, belum ada yang dibelanjakan | **aturannya** ("Tidak dianggarkan") |
| `tanpa_batas` | yang diukur ADA, batasnya tidak pernah disetel | **aturannya** |
| `tidak_terukur` | yang diukurnya sendiri belum ada | **aturannya** |

`tidak_terukur` mendahului `tanpa_batas`, dan catatan barisnya menyebut **kedua** sisi yang hilang.
Sebuah batas yang TIDAK ADA dikatakan barisnya dengan **null**, tidak disimpulkan dari nilainya
(verifikasi putaran 2): `prj_projects.contract_value` berbawaan 0 dan `rapVersusContract` sendiri
yang mengirim null di sana, sementara **Rp 0 yang sungguh dianggarkan sebuah RAP tetap sebuah
batas** — yang sudah dibelanjakan di atasnya `lampau`, dan urutan daftarnya `stateRank()` lebih
dulu, persentase sesudahnya, supaya baris tanpa persentase tidak jatuh ke dasar.

Tiga entri yang dikirim: `project_budget_pct` (**dipasok Finance** lewat `WatchedThresholds::supply`,
karena aritmetika komitmen milik `CommitmentService` dan menyalinnya ke Core berarti dua jawaban),
`rap_vs_kontrak_pct` dan `overhead_budget_pct` (dihitung Core, `DB::table` + literal string).
Sebuah entri yang modulnya belum memasok adalah baris **SKIPPED**, bukan entri kosong yang terbaca
"semua aman" — dipaku uji.

## Yang hanya ditemukan peramban

Dua cacat lolos dari **3.219 uji PHP yang hijau** dan ditemukan pada putaran harness pertama:

1. **`views/ambang.js` kurang satu tanda kurung tutup.** Setiap berkas menjawab 200, setiap uji PHP
   hijau, dan **seluruh SPA mati** — `app.js` mengimpornya, jadi halaman masuk pun tidak pernah
   tergambar. Persis kegagalan yang aturan "belum terverifikasi sampai aplikasinya termuat di
   peramban sungguhan" ada untuk menangkap.
2. **Catatan anggaran di bawah kotak Proyek tidak pernah muncul.** Pendengarnya dipasang pada
   pembungkus lookup, sedangkan combobox memancarkan `change` dari `<input>` di dalamnya. Kini
   didelegasikan pada body — pola yang sama yang sudah dipakai `visibleWhen`. Pada putaran itu
   ketahuan pula bahwa `liveNote` mendarat di formulir **permintaan pembelian**, bukan PO.

Dan satu cacat ditemukan uji kesetaraan T2.3 sendiri, sebelum harness:

3. **Memo per instance pada "RAP mana yang mengatur".** Sesudah sebuah revisi RAP disetujui, layar
   menjawab Rp 250 juta sementara **gerbang masih menolak dengan Rp 100 juta** milik revisi yang
   sudah digantikan — karena `Illuminate\Routing\Route::getController()` menyimpan instance
   controller pada objek Route yang hidup selama aplikasinya, jadi permintaan kedua memakai service
   yang sama. Memonya dibuang: satu kueri kecil per pembacaan jauh lebih murah daripada cache tanpa
   pembatalan pada angka yang menolak pembelian.

## Uji

| Berkas | Uji | Yang dijaganya |
|---|---:|---|
| `tests/Feature/Core/ThresholdWatchTest` | 10 | lima keadaan, invarian "tidak pernah persen tanpa kedua sisinya", **keadaan cocok dengan persentase yang dicetak di sebelahnya**, degradasi tabel hilang, Core tidak mengimpor modul fitur, gerbang izin endpoint |
| `tests/Feature/Finance/BudgetPortfolioEqualityTest` | 12 | kesetaraan layar↔gerbang di batasnya, **8** keadaan tepi (termasuk sisi habis di balik total yang lega, dan realisasi nol baris yang digaris), registri Core menerbitkan angka yang sama |
| `tests/Feature/Finance/BudgetMonthlyTest` | 8 | anggaran bulanan turunan + berlabel, **jumlah bulan = RAP juga pada total tidak bulat**, muatannya menyebut proyeknya, tanpa baseline digaris, bulan kosong null, biaya di luar rentang tetap tampil |
| `tests/Feature/Finance/OverheadBudgetTest` | 17 | satu approved/tahun (layanan **dan** basis data), **pembatalan** + tahun buku pada kodenya, realisasi dari jurnal terposting termasuk 31 Des, **nol jurnal = digaris**, jurnal draf bukan realisasi, forward-only |
| `tests/Feature/Finance/ProjectBudgetWarningTest` | 5 | angka terbaca oleh yang MEMBUAT PO (tanpa `fin.view`), 403 bagi yang tidak berhak, tanpa RAP tidak dicetak 0 %, **tiap formulir membaca plafon dokumennya sendiri** |
| `tests/Feature/Estimation/RapRevisionTest` | 11 | RAP lama menjawab identik dengan aturan lama, penggantian saat disetujui, **satu proyek satu RAP yang mengatur** (revisi kedua & RAP kedua ditolak), dua kolom saja pada pendahulu, alasan wajib, riwayat selisih |
| **Total baru** | **63** | 340 assertion (47/235 di akhir pembangunan; sisanya dipaku putaran verifikasi) |

**Direktori tersentuh, SQLite** (diukur di ujung putaran verifikasi, satu proses): Core, Finance,
Estimation, Projects, Procurement, Subcontract dan Unit — **3.236 uji, 18.784 assertion, 0 gagal**
(11 skipped, semuanya sudah ada sebelum paket ini). `pint --dirty` bersih.

**MySQL** (`phpunit.mysql.xml`): berkas anggaran Finance + `ThresholdWatchTest` +
`RapRevisionTest` + `DocumentFormatValidationTest` + `ApprovalDelegationRouteReachTest` —
**141 uji, 609 assertion hijau**, termasuk penegakan "satu OVB disetujui per tahun" lewat kolom
generated STORED + UNIQUE (padanan indeks parsial SQLite; keduanya dalam satu migrasi, satu cabang
driver). Kaki MySQL itu pula yang menangkap `COUNT(*) as lines` — hijau di SQLite, `1064` di
MySQL 8 (lihat § Putaran verifikasi).

## Harness (bukti UI)

Dijalankan 7 Sep 2026, `php -S` port 8121 atas **salinan coretan** basis data demo (disalin lalu
dimigrasikan; `database/database.sqlite` tidak disentuh), Chromium.

| Skenario | Viewport | Syarat | Hasil |
|---|---|---:|---|
| `S29_anggaran_vs_realisasi` | 1440×900 | 25 | ✅ semua (dijalankan ulang pada putaran verifikasi) |
| `S29_anggaran_vs_realisasi_mobile` | 390×844 | 3 | ✅ semua (idem) |
| `S28_matriks_persetujuan` (dijalankan ulang, matriks 29 baris) | 1440×900 | 34 | ✅ semua |
| `S28_matriks_persetujuan_mobile` (idem) | 390×844 | 3 | ✅ semua |

Angka yang diukur S29 di peramban (putaran verifikasi, 7 Sep 2026): portofolio 2 proyek;
**17 bulan** anggaran turunan yang menjumlah tepat **Rp 42.173.913.043** (= RAP-nya); proyek tanpa
baseline **0 bulan, 0 rupiah**; kolom Sisa "Rp 37.525.673.043 · PO Rp 31.123.865.391 · SPK
Rp 6.401.807.652" (rupiah penuh, bukan ringkas); kolom Terpakai **40,8 %** dengan "sisi SPK ·
total 11%"; proyek tanpa satu baris biaya pun berkolom Realisasi "—" (**nol sel "Rp 0"** di
seluruh tabel); sesudah komitmen sungguhan **total 93,0 % tetapi sisi PO 100,6 %** → ubin MERAH
"PO melampaui Rp 105.039.400" dan pita merah yang membuka dengan kalimat sisi PO; formulir PO
mencetak plafon non-subkon yang sama; lembar "Per bulan" **di media cetak** menyebut
"PRJ-2026-001 · Pembangunan Gedung Kantor Graha Sentosa (8 Lantai)" dengan `.filters`
tersembunyi; riwayat revisi 2 baris dengan selisih **Rp -17.923.913.043** dan revisi 0 yang
selisihnya "—". **Delapan** tangkapan layar di `docs/bukti-uji/s29-*.png` (dihitung hari ini);
hasil digabung per kunci ke `results-phase-2.json` — 12 kunci, S1..S11 dan S28 tetap utuh.

## Putaran verifikasi (7 September 2026) — tiga belas temuan, tiga belas perbaikan

Dua lensa verifikasi (uang dan pengalaman-pemakai) menjalankan sendiri harness ini, mengajukan
PO/SPK sungguhan lewat HTTP di atas salinan data demo, dan membaca layarnya di Chromium. Tiga
temuan BREAKS_SOMETHING, empat INCOMPLETE, enam COSMETIC. Semuanya diperbaiki **di akarnya**,
satu commit per temuan, masing-masing dipaku uji yang merah tanpa perbaikannya.

| # | Temuan | Perbaikan | Commit |
|---|---|---|---|
| 1 | **Dua revisi RAP paralel dua-duanya bisa DISETUJUI** — proyek punya dua "RAP yang mengatur", layar riwayat mencetak anggaran yang bukan yang dipakai gerbang (selisih Rp 10.104.166.666,68 pada data demo) | `approve()` menolak RAP disetujui kedua yang bukan pendahulunya; `revise()` menolak revisi kedua dari satu RAP; `chainOf()` menelusuri ke belakang dari RAP yang diminta | `dce6b27` |
| 2 | **Layar menjanjikan sisa TOTAL, gerbang menghakimi PER SISI** — pada data demo layar menjanjikan Rp 1.697.500.000 sementara PO Rp 1 ditolak | `project()` menerbitkan `sides` (keadaan/persen/kalimat per sisi) + `worst_state`; formulir PO membaca non-subkon, SPK subkon; ubin, pita, portofolio dan registri memakai sisi terketat | `f5d691b` |
| 3 | Anggaran bulanan tidak menjumlah tepat sebesar RAP (5 dari 7 kasus meleset ±Rp 0,01–0,02; satu MENCIPTAKAN rupiah) | sisa pembulatan diserap pada RUPIAHNYA di bulan terakhir (`absorbRounding`), bukan disimpulkan dari bobot 100 % | `25f7bc8` |
| 4 | Total realisasi OVB mencetak "Rp 0 · 0,0 % · Aman" ketika **nol** akun bermutasi, sementara tiap barisnya mencetak "—" | total mengikuti barisnya: `TIDAK_TERUKUR`; registri membedakan "nol baris jurnal" dari "mutasi bersih nol rupiah" lewat `COUNT(*)` | `b73fad8` |
| 5 | Penolakan OVB menyuruh "batalkan OVB/… lebih dulu", padahal OVB disetujui tidak bisa dibatalkan/dihapus/ditolak/diedit | rute + tombol **Batalkan OVB** (migrasi 001501, `fin.approve`, alasan wajib, jejak `cancelled`, nol jurnal) — dan kalimatnya menyebut tombol itu | `bbe5422` |
| 6 | 89,995–90 % dicetak "90,0 %" tetapi berkeadaan "Aman", di bawah judul "peringatan ≥ 90 %" | `mendekati` diadu dengan persentase YANG DICETAK (`displayPct`); `lampau` tetap diadu dengan rupiahnya | `47235a7` |
| 7 | Kode OVB membawa tahun BERJALAN, bukan tahun buku (OVB/2026/IX/0001 untuk tahun buku 2031) | `DocumentNumberService::next($type, $scope, $year)` + `documentNumberYear()`; format `OVB/{Y}/{N4}` terdaftar (jenis dokumen 59 → 60) | `405fa97` |
| 8 | Portofolio mencetak "Realisasi Rp 0" untuk proyek tanpa satu baris biaya pun — melanggar aturan yang dicetak layarnya sendiri | `actual` null bila **nol baris** (COUNT, bukan SUM); S29 akhirnya membaca `zero_cells` yang sudah lama ia kumpulkan | `745a10c` |
| 9 | Satu-satunya plafon per sisi di layar dibulatkan `rupiahShort` — dan membulatkan KE ATAS (Rp 31,126 M → "Rp 31,13 M") | rupiah penuh, dan sisi yang tidak dianggarkan menyebutkan itu | `8736619` |
| 10 | Lembar "Per bulan" yang dicetak tidak pernah menyebut proyeknya (`.filters` disembunyikan `@media print`) | `project_code`/`project_name` di muatan + kepala kartu; syarat S29 baru diukur di media cetak sungguhan | `38e075a` |
| 11 | Komentar formulir PR menjanjikan catatan anggaran yang tidak ada di sana (dan menyebut PR sebagai PO) | komentarnya diganti alasan mengapa formulir itu justru tidak membawanya | `70b7145` |
| 12 | Judul kolom registri Ambang mencetak "Subjek" untuk setiap entri yang bukan "proyek" | `subject_word` entri dipakai apa adanya (dikapitalkan) | `ca67978` |
| 13 | Dua angka di laporan ini bukan yang dikirim branch ("Tujuh tangkapan layar", "Enam commit") | laporan ini — angkanya diukur hari ini, dan judul yang menghitung commit diganti | *commit ini* |

Ditemukan **saat memperbaiki**, bukan oleh verifier: alias `COUNT(*) as lines` hijau di SQLite dan
`1064` di MySQL (LINES kata tercadang MySQL 8) — dua uji baru merah di kaki MySQL, `23833ec`.

**Yang TIDAK berubah pada putaran ini:** definisi anggaran/realisasi/komitmen, kalimat penolakan
gerbang, kebijakan `warn`/`block`/`off`, dan aturan "satu OVB disetujui per tahun". Kesetaraan
layar-vs-gerbang tetap struktural — yang diperbaiki adalah SISI mana yang dicetak layar.

## Yang TIDAK diverifikasi

1. **Data demo tidak punya satu pun RAP disetujui.** `RAP/2026/0001` berstatus `submitted`, jadi
   di erp1 hari ini **setiap** baris portofolio berbunyi "Belum ada RAP disetujui" dan gerbang
   anggaran diam untuk setiap proyek. Seluruh angka anggaran S29 diukur setelah harness
   **menyetujui RAP itu di salinan coretannya**. Pemilik perlu menyetujui RAP-nya di produksi
   sebelum satu pun angka pada layar ini berarti sesuatu.
2. **Belum pernah dijalankan di belakang nginx/produksi.** Seluruh pengukuran memakai `php -S`
   loopback dan SQLite. Kinerja `budget/portfolio` pada portofolio besar (kueri per proyek)
   belum diukur di mesin produksi maupun di MySQL.
3. **Belum ada OVB nyata.** Uji dan layar OVB dijalankan atas anggaran yang dibuat uji itu sendiri;
   tidak ada OVB pada data demo, jadi tab Overhead di erp1 akan berbunyi "belum ada OVB disetujui"
   sampai pemilik menyusunnya.
4. **Rincian RAP tidak bisa diedit dari layar.** Sebuah revisi RAP menyalin rinciannya, lalu
   satu-satunya jalan mengubah angkanya lewat UI adalah **"Buat dari BOQ"** dengan target margin
   lain (formulir RAP tidak punya editor baris; itu benar sebelum paket ini dan tidak diubah
   paket ini). Mengubah satu baris tertentu masih jalur importir. Lihat § Untuk pemilik.
5. **Tidak ada pemberitahuan ambang.** `WatchedDeadlines` punya `erp:deadline-watch` 08:30;
   `WatchedThresholds` **tidak** punya padanannya — peringatan 90 % hanya hidup di layar (dan di
   formulir PO/SPK). Membangunnya adalah paket sendiri, bukan sebuah baris.
6. **Realisasi overhead tidak dipilah proyek.** Sebuah jurnal beban kantor yang kebetulan membawa
   `project_id` tetap dihitung sebagai realisasi OVB. Itu keputusan sadar (yang dianggarkan adalah
   AKUN), bukan kelalaian — tetapi belum diuji terhadap bagan akun perusahaan sungguhan.
7. **Angka bobot fase = kurva `PlannedCurve` yang dibulatkan 4 desimal**, sama dengan EVM. Pada
   RAP Rp 400 juta, Januari menjadi Rp 105.084.800 dan bukan Rp 105.084.745,76 — selisih Rp 54
   yang ditulis apa adanya di ujinya. Tidak ada yang memeriksa apakah pembulatan itu cocok dengan
   cara perusahaan menyusun kas bulanannya. (Yang KINI dijamin: bulan-bulannya menjumlah **tepat**
   sebesar RAP-nya, juga pada total tidak bulat — putaran verifikasi.)
8. **Layar Ambang/Anggaran belum diuji dengan pembaca layar maupun keyboard-only.** Diukur hanya
   pada 1440×900 dan 390×844.
9. **Pembatalan OVB belum pernah dijalankan pemilik.** Rutenya, tombolnya dan jejak auditnya
   diuji (`OverheadBudgetTest`, 3 uji), tetapi tidak ada OVB nyata untuk dibatalkan — dan
   SIAPA yang boleh menariknya kembali (`fin.approve`, dipilih agar sama dengan yang menyetujuinya)
   adalah keputusan proses yang pantas ditinjau pemilik.
10. **Kode OVB tahun buku hanya diuji pada data baru.** Belum ada satu OVB pun di produksi maupun
    data demo, jadi tidak ada kode lama yang perlu dinomori ulang — dan tidak ada yang membuktikan
    perilaku penomoran ini pada data yang sudah terlanjur memakai format lama.

## Untuk pemilik — setiap bawaan dan setiap ambang yang dibawa paket ini

| # | Yang dikirim | Nilainya | Di mana diubah | Akibat kalau dibiarkan |
|---:|---|---|---|---|
| 1 | Ambang peringatan anggaran proyek | **90 %** | `config('erp.thresholds.project_budget_pct')` | baris berubah "Mendekati" pada 90 % terpakai (ROADMAP §5 baris 13) |
| 2 | Ambang peringatan RAP vs nilai kontrak | **90 %** | `erp.thresholds.rap_vs_kontrak_pct` | RAP yang memakan ≥ 90 % nilai kontrak ditandai |
| 3 | Ambang peringatan overhead | **90 %** | `erp.thresholds.overhead_budget_pct` | idem untuk OVB tahun berjalan |
| 4 | Gerbang anggaran PO/SPK | `warn` (**tidak diubah paket ini**) | `erp.procurement.budget_gate` | pelampauan wajib diakui pengaju, tidak diblokir |
| 5 | Ambang direktur OVB | **kosong** | Pengaturan › Matriks Persetujuan, baris ke-29 | OVB berapa pun nilainya cukup satu penyetuju |
| 6 | Izin endpoint anggaran satu proyek | `fin.view` **atau** `prc.create` **atau** `scm.create` | rute Finance | pembeli melihat sisa anggaran sebelum mengetik PO |
| 7 | Proyek berstatus `closed` | **tidak** muncul di portofolio | `budget/portfolio?include_closed=1` | anggaran proyek yang sudah ditutup adalah sejarah |
| 8 | Blok migrasi lanjutan | Finance **001500–001599** (dipakai: 001500 OVB, 001501 pembatalan OVB), Projects **001600–001699** (didaftarkan, belum dipakai) | CONVENTIONS §2 | tabel §2 adalah sumber kebenaran kedua rentang itu |
| 9 | Izin membatalkan OVB yang sudah disetujui | `fin.approve` | rute Finance | yang boleh menyetujui anggaran tahunan boleh menariknya kembali, dengan alasan wajib yang tercatat |
| 10 | Format kode OVB | `OVB/{Y}/{N4}` dengan **{Y} = tahun buku** | Pengaturan › Penomoran Dokumen | OVB tahun buku 2031 berkode `OVB/2031/0001`, dibuat tahun berapa pun |

**Tiga keputusan yang menunggu pemilik:**

- **OQ-F2-1 — RAP demo/produksi belum disetujui.** Sampai `RAP/2026/0001` disetujui, layar anggaran
  jujur tetapi kosong. Menyetujuinya adalah keputusan uang, bukan langkah teknis.
- **OQ-F2-2 — editor baris RAP.** Revisi RAP hari ini hanya bisa mengubah angka lewat "Buat dari
  BOQ" (target margin). Editor baris di formulir RAP adalah paket sendiri; tanpa itu, revisi yang
  hanya menggeser satu kategori biaya harus lewat importir.
- **OQ-F2-3 — pemberitahuan ambang.** Apakah peringatan 90 % perlu berbunyi di kotak masuk pagi
  (seperti tenggat 08.30), atau cukup di layar tempat uangnya dibelanjakan? Paket ini memilih yang
  kedua, dengan sadar.

## Dokumentasi

- `docs/CONVENTIONS.md` §2 — **tabel blok lanjutan** (Finance 001500–, Projects 001600–), dan
  aturannya: didaftarkan di tabel itu pada commit yang pertama kali memakainya.
- `docs/CONVENTIONS.md` §24 — registri `WatchedThresholds`: lima keadaan, aturan "batas 0 = tidak
  ada", dan mekanisme `supply()` beserta alasannya.
- `docs/FRONTEND.md` — `views/anggaran.js`, `views/ambang.js`, dan `liveNote` (**empat** aturannya,
  keempatnya dipelajari dengan cara yang mahal; yang keempat ditambahkan putaran verifikasi:
  catatan hidup harus mencetak angka yang menghakimi DOKUMEN ITU).
- `docs/PANDUAN-PENGGUNA.md` §19 — apa yang dibaca manajer proyek, **apa arti setiap sel yang
  bergaris**, peringatan 90 %, revisi RAP, dan OVB.
