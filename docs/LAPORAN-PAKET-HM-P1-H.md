# Laporan Paket P1-H (ROADMAP-HASHMICRO Fase 1) — Gantt baca-saja v1

Branch: `feat/phase1-h2` (dari `main` 932cb8b) · 7 September 2026

> Status jujur: **layarnya sudah ada sebelum paket ini** — `views/jadwal.js` (239 baris) dan tab
> "Jadwal" di `views/project.js` dikirim di sesi sebelumnya, sudah di-merge dan sudah hidup di
> erp1. Yang **tidak** pernah dikirim bersamanya adalah lapisan buktinya: **tidak satu uji PHP,
> tidak satu skenario harness, tidak satu laporan paket.** Paket ini menutup lubang itu, dan
> menutup empat cacat yang ditemukan lubang itu ketika ia akhirnya ditutup.
>
> Tidak ada migrasi, tidak ada endpoint baru, tidak ada dependensi baru, tidak ada pustaka vendor
> baru. **Verifikasi adversarial belum dijalankan.**

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-H → status)

| Klausa kontrak (baris 194) | Status | Bukti |
|---|---|---|
| `prj_wbs_tasks` sebagai sumber baris | ✅ | `GET projects/{id}/wbs-tasks`; 12 baris tergambar = 12 tugas yang dipulangkan (S26) |
| baseline per `wbs_code` (bukan id — kolomnya nullable) | ✅ | `JadwalGanttTest` mengukur **0 dari 11** id beku bertahan sesudah satu `generateWbsFromBoq`, **11 dari 11** kode cocok |
| progres | ✅ | 0..100 sebagai string di kawat, dibagi 100 di klien; nilai di luar rentang lolos utuh (105,5000 diuji) |
| garis hari ini | ✅ | terukur S26: x = 484,67 px, diharapkan 484,67 px |
| bayangan akhir pekan | ✅ | 73 rect = 73 Sabtu di jendela 514 hari (dihitung ulang di harness) |
| zoom minggu/bulan | ✅ | 74 tick mingguan (= 74 Senin) vs 16 tick bulanan (= 16 tanggal 1) |
| cetak lanskap | ✅ | PDF Chromium: halaman gantt **792×612 pt (lanskap)**, halaman lain 612×792 pt |
| tab "Jadwal" di proyek | ✅ | `.tabs` = `["Ringkasan", "Jadwal"]`, dipaku uji impor `views/jadwal.js` |
| **h-o 3,5** | — | layarnya sudah dibayar sesi lalu; paket ini adalah lapisan buktinya + 4 perbaikan |

## Apa yang lapisan bukti ini temukan

Empat cacat, semuanya **hanya bisa dilihat karena buktinya ditulis**, dan tidak satu pun di dalam
`jadwal.js` sendiri — semuanya di server yang layar itu berdiri di atasnya.

### 1. Pohon WBS terpotong di tingkat tiga (`df0bd21`)

`WbsTaskController::index()` memuat `children.children`. Itu benar untuk WBS yang dihasilkan
`generateWbsFromBoq` (bagian → item, dua tingkat) dan **salah untuk pintu kedua ke tabel yang
sama**: `MppXmlImportService` menerima `OutlineLevel` sedalam apa pun dan hanya menolak lompatan
lebih dari satu tingkat.

Diukur: 13 baris tersimpan, **12 terkirim**. Tugas tingkat empat hilang sama sekali dari gantt DAN
dari tabel WBS tab Ringkasan; induknya di tingkat tiga dikirim **tanpa kunci `children`**, sehingga
`views/project.js` (`task.children || []`) menyebutnya daun dan menawarkan tombol **`Perbarui`**
untuk tugas yang `ProgressService` pasti tolak. Sebuah jadwal yang diam-diam kehilangan paket
pekerjaan terlihat persis seperti jadwal yang benar.

Pohonnya kini dirakit dari **satu** kueri (tiga sebelumnya), tanpa batas kedalaman, setiap baris
membawa `children` (kosong bila daun), dan baris yatim diperlakukan sebagai akar alih-alih dibuang.

### 2. `live_exists` tidak pernah bisa bernilai `false` (`25d13f1`)

`BaselineTaskResource` memakai `whenLoaded('liveTask', fn () => …)`. Laravel memulangkan `null`
**tanpa memanggil closure-nya** ketika relasinya dimuat tetapi kosong — jadi medan itu hanya pernah
`true` atau `null`, dan `null` juga berarti "tidak dimuat". `views/evm.js:1145` menguji
`task.live_exists === false`, jadi coretan *"sudah tidak ada di WBS — bobotnya dihitung nol"*
**belum pernah sekali pun tergambar**, dan docblock resource-nya menjanjikan keselamatan yang tidak
diberikannya. Pada berkas demo yang dikirim repositori ini **11 dari 11** baris beku menggantung,
jadi kartunya tidak pernah benar sekali pun.

### 3. EVM diam saat id beku dan kode beku tidak sepakat (`77362ce`)

`EvmService::physicalProgress` mencoba `wbs_task_id` **dulu**. Kolom itu tanpa FK (disengaja — baris
beku harus bertahan meski tugas hidupnya dihapus), tanpa validasi, dan relasinya tidak dibatasi per
proyek. Memindahkan id beku B.3 ke baris hidup C.1 — satu `UPDATE`, tidak ada batasan yang
menolaknya — membuat EV memakai progres C.1 (4,0604 %) atas bobot beku B.3 (36,9962 %), C.1 dipakai
dua kali, dan B.3 yang hidup dilaporkan sebagai *"lingkup baru"*. Uang yang salah, dan diam.

Perilaku **angkanya sengaja tidak diubah** (kode yang diganti nama adalah tugas yang sama, dan di
situ id-lah yang benar); yang ditambahkan adalah peringatan yang menyebut kedua kodenya, lewat
kanal `warnings` yang sudah dicetak layar EVM.

### 4. Gerbang izin tiga GET layar proyek hanya ada di peramban (`4df5959`)

`app.js` menolak rute proyek tanpa `prj.view` di lima tempat, dan `projects/baselines` menuntut
`prj.view` sejak paket baseline. Tetapi `{project}/wbs-tasks`, `/s-curve` dan `/dashboard` berjalan
di bawah `auth:sanctum` saja. Diukur: pengguna berperan keuangan (`fin.view`, `crm.view` — persis
peran demo `finance`) mendapat **403** dari `projects/baselines` dan **200** dari
`projects/1/wbs-tasks`, lengkap dengan kode, uraian, bobot, tanggal rencana dan progres setiap
paket pekerjaan. **Separuh tab Jadwal dijaga server dan separuhnya tidak**, dan tab Jadwal-lah yang
membuat ketimpangan itu terlihat. Ketiganya kini `permission:prj.view`; gudang (yang memegangnya)
ikut diuji tetap 200 supaya perbaikan ini tidak diam-diam mengunci orang yang membutuhkannya.

**Putaran verifikasi menemukan gerbang itu bisa diputari** (7 Sep 2026): `GET projects/{project}`,
tepat di atas ketiga rute yang baru digerbangi, tetap 200 untuk keempat peran tanpa `prj.*` —
dan `ProjectResource` mengirim `wbs_tasks` di dalamnya, **persis daftar medan yang kalimat di atas
sebut sebagai alasan gerbang itu dipasang**. Salinan kedua itu juga membawa dua cacat yang
`df0bd21` tutup di pintu sebelah: pada proyek berpohon empat tingkat ia mengirim 11 dari 13 baris
(B.3.1 dan B.3.1.1 hilang) dan setiap simpul tingkat satu dikirim tanpa kunci `children`. Medannya
karena itu **dihapus**: pohon WBS kini punya satu pintu, yang utuh dan yang bergerbang. Tidak ada
pembaca di SPA (grep `wbs_tasks` atas `public/app/js`: 0 hasil) dan tidak ada uji yang memakainya;
`test_the_project_payload_carries_no_second_copy_of_the_wbs_tree` menjaganya. Rutenya SENDIRI
tetap tanpa gerbang — itu bagian dari butir #10 di bawah, keputusan modul Projects.

## Invarian yang menopang seluruh layar

**Baseline dicocokkan lewat `wbs_code`, tidak pernah lewat `wbs_task_id`** (CONVENTIONS §20).
Angka yang selama ini hanya ditulis docblock `jadwal.js` sekarang **diukur ulang** terhadap layanan
yang sungguhan, bukan dihafal:

| | id beku menemukan tugas hidup | `wbs_code` cocok |
|---|---|---|
| sebelum `generateWbsFromBoq` | 11 dari 11 | 11 dari 11 |
| sesudah **satu** kali tekan | **0 dari 11** | **11 dari 11** |

Id lamanya tidak dipakai ulang — ia ditinggalkan (SQLite `AUTOINCREMENT`; sequence tidak mundur).
Bentuknya sama dengan berkas demo yang dikirim repositori ini: id beku 12–22, id hidup 34–44,
yang berarti tombol itu ditekan dua kali lagi sesudahnya.

Konsekuensinya bukan "kurang aman": gantt yang dicocokkan lewat id menggambar **nol** bar
pembanding dan kaki kartunya mengumumkan *"0 dari 11 tugas cocok"* untuk baseline yang disetujui
dan lengkap. Kedua pencocok dijalankan atas **muatan endpoint-nya**, bukan atas model, supaya yang
dibandingkan adalah apa yang benar-benar sampai ke peramban.

## Uji

- baru: **`tests/Feature/Projects/JadwalGanttTest.php` — 11 uji / 89 asersi.** Tujuh ditulis lebih
  dulu dan hijau (invarian `wbs_code`, dua pencocok atas muatan sungguhan, tugas tanpa tanggal →
  `null` bukan tanggal proyek, satuan progres 0..100 tanpa jepitan di jalur baca, proyek tanpa
  baseline disetujui → daftar kosong 200 dan baseline draft tidak terhitung *current*, pin
  SPA↔server untuk tiga jalur + medan yang dibaca, pin impor `views/jadwal.js` oleh
  `views/project.js`); **empat sisanya ditulis MERAH lebih dulu** dan menjadi hijau bersama
  perbaikannya masing-masing, satu commit per cacat.
- `tests/Feature/Projects` + `tests/Feature/Core`: **1.193 uji / 8.480 asersi hijau** (11
  dilewati, 240 s). Suite penuh: gerbang rilis milik orkestrator. MySQL: belum dijalankan.

**Harness `S26_gantt` + `S26_gantt_mobile`** (Chromium 1440×900 dan 390×844): **29 syarat hijau di
kedua ukuran, jalan pertama**, dan yang dicatat adalah angka:

- **23 rect** (12 bar aktual + 11 bar baseline) berdiri di `x` dan lebar yang **dihitung ulang di
  Python dari tanggal muatan API**, lalu dikembalikan ke nomor barisnya dari koordinat `y`:
  **0 meleset lebih dari 0,05 px**.
- Bar baseline hanya pada 11 baris yang punya pasangan beku, dan **selalu 10 px di bawah** bar
  aktualnya — satu-satunya nilai selisih `y` yang terukur.
- Bar baseline benar-benar digambar dari tanggal **beku**: satu tanggal beku (B.2) digeser 30 hari,
  selisih yang muncul di layar **42,02 px**, yang diharapkan **42,02 px**. Tanpa fixture ini
  skenarionya tidak menguji apa pun — lihat § Yang BELUM diverifikasi #1.
- Garis "Hari ini" ada (7 Sep 2026 memang di dalam 02-02-2026 s.d. 30-06-2027), berlabel, di
  **484,67 px** terukur vs **484,67 px** diharapkan.
- **73** bayangan akhir pekan = 73 Sabtu di jendela 514 hari; **74** tick mingguan = 74 Senin;
  **16** tick bulanan = 16 tanggal 1; zoom benar-benar mengubah kerapatan dan tombolnya menandai
  dirinya aktif.
- Tugas tanpa tanggal selesai (ditanam — tidak ada di data demo): bar `data-open="end"`, satu tepi
  putus-putus, `<title>` menyebut namanya + *"tanggal selesai belum ditetapkan (bar terbuka)"*.
- **23 mark, 23 `<title>`.** Legenda hanya menyebut yang tergambar. Kaki kartu mengumumkan
  *"11 dari 12 tugas cocok, 1 tanpa pasangan, dicocokkan menurut kode WBS"* dan menyebut
  ketergantungan yang sengaja tidak digambar.
- **Cetak**: PDF sungguhan dari Chromium, ukuran halaman dibaca dari `/MediaBox` —
  **792×612 pt (lanskap)** untuk lembar gantt, 612×792 pt untuk sisanya. `@page gantt` terbaca di
  CSSOM, `getComputedStyle('.gantt-sheet').page === "gantt"`, gulir mendatar dilepas, `min-width`
  svg jatuh ke 0 sehingga gambarnya menyusut ke kertas alih-alih terpotong, bilah zoom
  disembunyikan tetapi judul dan catatan sumber tetap tercetak
  (`s26-jadwal-cetak-lanskap-p1h.png` adalah halaman lanskapnya, di-render dari PDF-nya).
- **Ponsel 390×844**: 29 syarat yang sama hijau, dengan angka yang **tidak dipoles** — teks
  tergambar **9,2 px** (label) dan **8,8 px** (tick) karena svg berhenti di lantai `min-width`
  80 % (720 px dari 900 px alami) yang **diputuskan P1-A dan ditulis di docblock charts.js**, dan
  **10 dari 12** label baris terpotong. Di desktop label terukur **14,21 px**, tick **13,59 px**,
  pada svg **1.112 px**.
- 0 `pageerror` di kedua ukuran. Dua console error yang tercatat adalah HTTP 500
  `PUT core/me/preferences/recent` — tabel `core_user_preferences` memang belum ada di salinan
  berkas demo (0 baris di `sqlite_master`), tidak ada hubungannya dengan paket ini, dan dicatat apa
  adanya alih-alih disaring.

**Pemeriksaan peramban wajib** (aturan 7 Sep 2026): `/app/` dimuat di Chromium — **74 modul JS, 0
console error, 0 permintaan gagal, formulir masuk tergambar.**

## Deviasi

1. **Layarnya tidak dibangun di paket ini.** Ia sudah ada, sudah di-merge, sudah hidup. Yang
   dibangun adalah lapisan buktinya. Laporan ini menuliskannya begitu alih-alih mengklaim h-o 3,5.
2. **Empat perbaikan menyentuh berkas di luar tab Jadwal** — `WbsTaskController`,
   `BaselineTaskResource`, `EvmService`, dan file rute Projects. Tidak satu pun bisa diperbaiki di
   dalam `jadwal.js`, dan semuanya ditemukan karena tab Jadwal membuatnya terlihat.
3. **`EvmService` tetap mencoba id dulu.** Mengubahnya menjadi "kode saja" akan menyamakannya
   dengan gantt, tetapi juga akan salah untuk kode WBS yang diganti nama. Yang dijamin sekarang
   adalah ia tidak diam; yang dijamin BUKAN adalah ia selalu benar.
4. **Tiga rute mendapat gerbang izin baru.** Perubahan perilaku sungguhan (200 → 403 untuk peran
   tanpa `prj.view`), disengaja, dan diuji dua arah.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Data demo tidak punya deviasi jadwal sama sekali.** `BSL/2026/VIII/0001` dibekukan dari WBS
   yang sama persis, jadi **11 dari 11** bar pembandingnya berimpit sempurna dengan bar rencana
   hidupnya: sebuah gantt yang MELUPAKAN bar baseline akan terlihat sama benarnya. Karena itu S26
   menanam deviasinya sendiri (satu tanggal beku dipasang MUTLAK ke 2026-10-01, 30 hari dari
   2026-10-31, dan dikembalikan di blok `finally` — verifikasi 7 Sep 2026: pergeseran relatif
   yang dikembalikan di baris pernyataan biasa merusak baseline yang disetujui secara permanen
   begitu satu jalan mati di tengah, sementara setiap S26 berikutnya tetap hijau). Yang **belum**
   pernah dilihat siapa pun adalah gantt dengan deviasi yang SUNGGUHAN dan banyak.
2. **Verifikasi adversarial belum dijalankan.** Putaran pertama P1-F menemukan 20 cacat sungguhan.
3. **Suite MySQL belum dijalankan.** Perbaikan pohon WBS mengubah bentuk kueri (`groupBy` di PHP
   atas satu `SELECT` alih-alih tiga `whereIn`), dan itu jenis perubahan yang bisa berbeda di
   MySQL — meski tidak ada SQL baru yang ditulis.
4. **Teks 8,8 px di ponsel dibiarkan apa adanya.** Ia adalah lantai yang DIPUTUSKAN dan
   DIDOKUMENTASIKAN P1-A (`min-width` 80 % lebar alami), bukan cacat yang baru ditemukan, dan
   menaikkannya ke 11 px berarti membatalkan keputusan paket lain yang punya verifikasinya
   sendiri. Yang belum dilakukan: menanyakan ke pemakainya apakah gantt di ponsel memang dipakai
   untuk MEMBACA nama paket, atau hanya untuk melihat bentuk jadwalnya.
5. **`<title>` tidak bisa disentuh.** Seluruh keterangan bar (tanggal, persentase, catatan
   baseline tidak valid) hidup di `<title>` SVG, yang di ponsel tidak muncul pada ketukan. Di layar
   sentuh, 10 dari 12 nama paket terpotong DAN keterangannya tidak terjangkau.
6. **Pohon lebih dari tiga tingkat belum pernah ada di data sungguhan.** Perbaikan #1 diuji dengan
   dua baris yang ditanam uji; belum ada berkas MPP-XML empat tingkat yang benar-benar diimpor
   lewat layarnya.
7. ~~**Siklus `parent_id` tidak dijaga.**~~ **DIPERBAIKI 7 Sep 2026 — dan akibat yang ditulis di
   sini keliru.** Dugaannya "`flatten()` di klien berulang tanpa henti"; yang sebenarnya terjadi
   lebih sunyi: dengan penyaring akar yang baru, klien tidak pernah MELIHAT siklusnya — anggota
   siklus tidak pernah menjadi akar dan tidak pernah terjangkau dari akar mana pun, jadi barisnya
   hilang tanpa sepatah kata (diukur pada salinan berkas demo dengan B.3 ↔ B.3.1: 13 baris
   tersimpan, **10 terkirim**; B.3, B.3.1 dan B.3.1.1 lenyap sementara kaki gantt tetap
   mengumumkan angkanya dengan yakin). Itu persis kegagalan yang paket ini dibangun untuk
   memberantas. Perakitan pohonnya kini mengangkat baris yang tidak terjangkau menjadi akar dan
   menyebutnya lewat `meta.parent_cycles`; dua uji baru memakukannya, dan perilaku YATIM yang
   selama ini tidak diuji apa pun ikut dipaku.
8. **Kode WBS ganda belum pernah diukur di peramban.** `jadwal.js` menghitung tabrakan dan
   menyebutkannya di bawah gantt (indeks `(baseline_id, wbs_code)` bukan `unique`), tetapi data
   demo tidak punya satu pun, dan S26 karena itu tidak pernah melihat kalimat peringatannya.
9. **`{project}/wbs-tasks` sekarang bergerbang — dan tidak ada layar lain yang memanggilnya.**
   Grep atas `public/app/js/` hanya menemukan `project.js` dan `jadwal.js`. Kalau ada pemakai di
   luar SPA (skrip, integrasi), ia akan mulai menerima 403 tanpa peringatan.
10. **29 GET Projects lain masih tanpa gerbang.** Dihitung 7 Sep 2026 di
    `Modules/Projects/Routes/api.php`: **44 GET, 15 bergerbang, 29 tidak** — termasuk
    `GET projects/` (daftar seluruh proyek), `GET projects/{project}` (detail proyek — sejak
    verifikasi 7 Sep 2026 tanpa muatan WBS, tetapi tetap tanpa gerbang), laporan
    harian, progres mingguan, milestone, BAST, punch list, insiden K3, register risiko, ketiga
    register izin, gate pass, dan penugasan personel. Paket ini hanya menutup **tiga** yang dipakai
    layar proyek, karena hanya ketiga itu yang tab Jadwal berdiri di atasnya. Sisanya adalah
    keputusan modul Projects, bukan keputusan P1-H — dan angkanya ditulis di sini supaya ia tidak
    hilang bersama paket ini.
