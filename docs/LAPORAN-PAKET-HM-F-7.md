# Laporan Paket HM F-7 — Servis alat per hour-meter

**Cabang:** `feat/phase2-f7` (dari `main` 6e55585) · **Tanggal:** 9 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 2 baris F-7 (2–3 hari-orang)
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik)

---

## 0. Satu kalimat

Alat berat tidak dirawat menurut kalender: excavator yang menganggur sebulan tidak butuh
servis 250 jam, dan yang bekerja dua shift menembusnya dalam dua minggu. Paket ini menambah
**pemicu servis kedua** — `ast_maintenances.next_due_hour_meter` — yang berdiri **sendiri**
di samping `next_due_date` yang sudah ada: yang mana pun tercapai lebih dulu, servisnya
jatuh tempo.

---

## 1. Tugas → status → bukti

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T1 | Migrasi `next_due_hour_meter`, blok Assets 000500–000599 | ✅ | `e355694` — `2026_09_09_000545_...`, `decimal(15,3)` nullable, maju-saja tanpa backfill. Dijalankan pada dua salinan sqlite scratchpad (`demo.sqlite`, `harness.sqlite`): DONE 4,43 ms / 3,05 ms. Presisinya sama persis dengan `ast_equipment_logs.hour_meter` |
| T2 | Definisi "pembacaan terakhir" dan "target yang berlaku", satu tempat, alasannya tertulis | ✅ | `8095c04` — `Modules/Assets/Services/MaintenanceDueService.php` (docblock kelas, 2 definisi + 3 sebab tidak-terukur + aturan aset dilepas). Dipaku 24 uji, mutasi M1–M7 merah |
| T3 | Keadaan jatuh tempo memakai kosakata enam keadaan `WatchedThresholds` | ✅ | `8095c04` — `state()` dipanggil dengan margin; TIDAK_TERUKUR (tanpa pembacaan), TANPA_BATAS (pembacaan tanpa target). Terukur di layar: 6 baris registri pada data demo bercabang → 1 lampau, 1 mendekati, 1 tanpa_batas, 3 tidak_terukur (tiga sebab berbeda) |
| T4 | Entri registri + kesetaraan dengan service dipaku uji | ✅ | `f9da79c` + `b2dd280` — entri `maintenance_hour_meter` **dipasok** Assets (`supply()`, aturan §24), jadi tidak ada kueri kedua yang bisa berselisih; `ThresholdHourMeterTest::test_the_registry_row_is_the_assets_service_answer` membandingkan `actual/limit/state/note/link` baris demi baris. Core tetap tidak mengimpor Assets (dipaku `ThresholdWatchTest::test_core_imports_no_feature_module_to_compute_a_threshold`, 11 uji hijau) |
| T5 | Aset dilepas keluar dari pengawasan — dari aturan yang sama dengan pemicu tanggal | ✅ | `b2dd280` — `test_a_disposed_asset_drops_out_of_both_triggers` menanyai **kedua** pengawas atas satu aset: sebelum `disposed` → 1 baris registri + `WatchedDeadlines::scoped()` = 1; sesudah → null + 0. Mutasi M4 merah |
| T6 | Permukaan pemakai: kedua pemicu berdampingan, tiga kalimat "digaris" | ✅ | `6fe244f` + `7b297b9` — **sepuluh** permukaan (daftar, formulir, resource, listing, endpoint history, kartu aset, tabel riwayat, cetakan kartu aset, layar Ambang, **layar detail satu perawatan** — yang terakhir ditambahkan di putaran verifikasi: layarnya dibuka sejak awal, angkanya baru dibaca kemudian, lihat D12). Diukur di Chromium: 8 layar, **0 galat konsol**, 0 respons ≥ 400, 0 gulir samping |
| T7 | Uji PHP + mutasi | ✅ | `b2dd280` + `e4b0beb` — **40 uji di tiga berkas baru** (24 + 6 + 10), 143 asersi, ditambah **2 uji baru di berkas lama** (`DeadlineWatchTest`, `AssetPrintTest`) dan satu asersi uji lama yang diperbarui karena kalimatnya memang berubah; **24 mutasi dijalankan, 24 merah** (satu lolos hijau lebih dulu lalu ditutup — lihat §5) |
| T8 | Harness S34 desktop + ponsel | ✅ | `7b297b9` — `S34_servis_alat_per_jam` (20 syarat) + `S34_servis_alat_per_jam_mobile` (9 syarat), keduanya `ok`. `results-phase-2.json`: 23 kunci lama **tidak berubah satu byte pun** (dibandingkan JSON-nya), 25 kunci sesudahnya. 9 PNG |
| T9 | Cangkang PWA | ✅ | `6fe244f` — daftar `SHELL` **tidak berubah** (tidak ada berkas baru); `SHELL_VERSION` 5 → 6 karena berkas cangkang berubah (CONVENTIONS §21) |
| T10 | Muat `/app/` di Chromium sungguhan | ✅ | php -S 8191 atas salinan sqlite scratchpad; 8 layar; `all_console_errors: []`, `http_4xx_5xx: []` |
| T11 | Dokumentasi | ✅ | commit ini — CONVENTIONS §24 (dua generalisasi registri) + §36 baru, PANDUAN-PENGGUNA §9.3/§9.6/§1.7/anggaran, PANDUAN-ADMINISTRATOR tabel tenggat (catatan §), ONBOARDING project-manager |

---

## 2. Dua definisi yang bisa dipilih salah — dan yang dipilih

### (B) "Pembacaan hour-meter terakhir" = pembacaan **TERTINGGI**, bukan yang terbaru

Tiga kandidat ada: menurut `log_date`, menurut `id`, atau menurut **nilai tertinggi**.
Yang dipilih adalah nilai tertinggi, dengan satu alasan: **meter tidak berjalan mundur.**
Sebuah pembacaan yang lebih rendah hanya punya dua sebab nyata — meterannya diganti bengkel,
atau operator salah ketik satu digit — dan tidak satu pun berarti mesinnya berjalan lebih
sedikit.

Terukur, dan inilah harganya kalau salah pilih: alat pada **5.120 jam** yang sudah melewati
target 5.000 jam, lalu satu digit hilang menjadi **512**. Dengan "yang terbaru", alatnya
berbunyi "masih 4.488 jam lagi" dan alarmnya **mati persis pada alat yang paling perlu
dilihat** (`test_a_reading_that_drops_never_silences_an_overdue_service`; mutasi M1
mengubahnya menjadi "yang terbaru" → MERAH).

Penjaga monoton `EquipmentLogService` **tidak** menutup lubang ini: ia berlingkup satu
mobilisasi, sementara penggantian meter justru terjadi **di antara** dua mobilisasi. Di data
demo bercabang: Dump Truck AST-0002 membaca 8.150 jam pada DEP/2026/III/0002 (April) lalu
815 jam pada DEP/2026/VII/0005 (Juli, meter baru) — dua mobilisasi, penjaga tulis tidak
pernah melihat keduanya bersamaan.

Pembacaan terbaru **tetap dibawa** dan **dikatakan**: kartu alat memasang pita peringatan
("Pembacaan terakhir (15 Jul 2026) 815 jam lebih rendah dari pembacaan tertinggi 8.150
jam — meter diganti atau salah ketik…"). Yang ditolak adalah membiarkan angka yang turun
**mendiamkan** alarm, bukan menyembunyikan bahwa angkanya turun.

Tiga kasus batas ikut dipaku, dan semuanya kini diputuskan SQL (§5b): dua pembacaan **sama
tinggi** (alat menganggur) → tanggal yang dipulangkan adalah yang **terakhir**, bukan yang
pertama (mutasi M2 `MAX` → `MIN` merah); dua pembacaan pada **hari yang sama** → yang
"terakhir" adalah yang ditulis belakangan menurut id, karena salah ketik sore hari harus
tetap memunculkan pita meter mundur (M2b merah); dan **log BBM tanpa angka jam** tidak
dihitung sebagai pembacaan maupun menggeser tanggal pembacaan terakhir (M2c, M2d merah).

**Dan satu definisi lain di modul yang sama memang berbeda, dengan benar.**
`RentVsOwnService::hoursLogged` menjumlahkan **delta per mobilisasi** (pembacaan terakhir −
pertama pada tiap mobilisasi), karena ia menjawab pertanyaan yang lain: "berapa jam alat ini
BERJALAN", untuk membagi biaya menjadi rupiah per jam. Meter yang diganti di antara dua
mobilisasi tidak merusaknya, karena tiap delta dihitung **di dalam** satu mobilisasi. Paket
ini menjawab "apa yang tertulis di meternya SEKARANG". Dua pertanyaan, dua definisi, dan
docblock keduanya kini saling menyebut — menyatukannya akan merusak salah satunya.

### (D) "Target yang berlaku" = catatan perawatan **TERBARU**, baris yang sama dengan pemicu tanggal

`WatchedDeadlines` sudah memilih baris perawatan terbaru per aset (`latest_per_group`,
menurut `maintenance_date` lalu `id`) dengan alasan "mencatat servis 14 Jun harus mendiamkan
pengingat yang ditinggalkan servis sebelumnya" — dan alasan itu tidak berubah kalau
satuannya jam. Alternatifnya ("target terkecil yang belum terlampaui") ditolak karena ia
akan menghidupkan lagi target yang sudah digantikan mekanik, dan — lebih buruk — membuat
**dua pemicu pada satu tabel membaca dua baris yang berbeda**.

Akibatnya sengaja tajam dan dipaku: kartu servis terbaru yang **lupa** mengisi target jam
membuat alatnya `TANPA_BATAS` ("Batas belum disetel"), bukan diam-diam mewarisi 4.500 dari
kartu Januari (`test_a_newest_maintenance_without_an_hour_target_is_tanpa_batas_not_an_inherited_one`;
mutasi M3 merah).

---

## 3. Tiga cara sebuah alat tidak terukur — tiga kalimat, bukan satu "—"

`0 jam` berarti "mesin baru, meterannya masih nol". "Tidak ada yang tahu" adalah keadaan
lain, dan sebabnya tiga, dengan jalan keluar yang berbeda-beda:

| Sebab | Kalimat di layar | Jalan keluarnya |
|---|---|---|
| Belum pernah dimobilisasi | *"Alat ini belum pernah dimobilisasi, jadi belum ada mobilisasi yang bisa menampung log jam"* | mobilisasi alatnya |
| Mobilisasi ada, log belum | *"1 mobilisasi tercatat, tetapi belum ada satu log BBM & jam alat pun"* | minta lapangan menulis log |
| Log ada, `hour_meter` NULL semua | *"2 log tercatat, tetapi tidak satu pun mengisi hour meter (log BBM tanpa jam kerja)"* | minta operator membaca meternya, bukan hanya mencatat solar |

Ketiganya **DIGARIS** ("—") di layar Ambang dan di kartu alat, tidak pernah digambar 0 jam.
Ketiganya diukur di Chromium pada tiga alat berbeda (S34: `an_asset_with_a_deployment_but_no_log_says_exactly_that`,
`an_asset_whose_logs_never_filled_the_meter_says_something_else`,
`an_asset_never_deployed_says_that_instead`), dan mutasi M7 (menukar dua sebab) merah.

**Nol jam tetap sebuah pembacaan:** mesin baru pada 0 jam dengan target 250 jam berbunyi
"Aman, sisa 250 jam" — bukan "belum terukur" (`test_a_zero_hour_reading_is_a_reading_not_an_absence`).

---

## 4. Yang berubah di registri ambang — dan alasan terukurnya

Paket ini memakai `WatchedThresholds` (keputusan desain paket, CONVENTIONS §24) dan
**bukan** mekanisme kedua. Enam keadaannya cocok tanpa dipaksa: `TIDAK_TERUKUR` untuk alat
tanpa pembacaan, `TANPA_BATAS` untuk pembacaan tanpa target, dan `LAMPAU` tepat saat
pembacaan mencapai targetnya. (`TANPA_ANGGARAN` tidak bisa lahir di sisi jam, dan itu
disengaja — lihat di bawah.)

Dua hal **harus** digeneralisasi lebih dulu, masing-masing dengan alasan yang bisa diukur:

**(1) `unit` akhirnya DIBACA.** Setiap entri sudah mendeklarasikan `'unit' => 'rupiah'`
sejak F-2 dan **tidak ada satu baris pun yang membacanya**: `ambang.js` memanggil
`fmt.rupiah` pada kedua sel angkanya. Entri berjam pertama akan mencetak **"Rp 3.375,50"
untuk 3.375,5 JAM**. Ini bentuk yang sama dengan cacat `subject_word` yang verifikasi F-2
tutup ("SUBJEK" di atas baris berisi "2026"): satuan yang dideklarasikan sebuah entri tidak
boleh hilang di tabel yang menampilkannya. Mutasi M13 (`'unit' => 'rupiah'` pada entri jam)
merah.

**(2) AMBANGNYA JAM, BUKAN PERSEN.** Persentase tidak bisa dipindahkan ke meter kumulatif,
karena meterannya **tidak pernah mulai dari nol pada servis terakhir**. Angkanya:

| Alat | Pembacaan | Target | Ambang 90 % menyala pada | Tenggangnya |
|---|---|---|---|---|
| Doosan DX225LCA | 3.375,5 jam | 3.500 jam | 3.150 jam | **350 jam** — lebih panjang dari satu interval servis penuh (250 jam) |
| alat tua yang sama jenisnya | 12.000 jam | 12.250 jam | 11.025 jam | **1.225 jam** — lima interval |
| genset baru | 40 jam | 250 jam | 225 jam | **25 jam** |

Satu angka config memberi tenggang **25 jam sampai 1.225 jam** tergantung UMUR alat, bukan
sisa jatah servisnya. Maka entri yang tidak proporsional (`proportional => false`)
mendeklarasikan ambangnya dalam satuannya (`warn_margin_key`, **50 jam**), tidak mengirim
persentase sama sekali, dan layarnya mencetak **sisa** ("24,5 jam lagi", "150 jam lewat").
`state()` menerima parameter keempat opsional; **empat keadaan yang lain tidak berubah satu
baris pun** — LAMPAU tetap dihakimi pada angkanya (mutasi M8/M9/M10/M11 merah, dan
`test_the_rupiah_entries_keep_their_percentage_and_percent_warning` memaku bahwa entri
rupiah tidak kehilangan apa pun).

Urutan barisnya ikut: **keadaan dulu, lalu SISA** — bukan persentase, yang akan menaruh alat
12.000 jam yang masih 400 jam lagi (99,8 %) di atas alat 900 jam yang tinggal 12 jam
(98,7 %). Mutasi M12 (membalik tanda sisa) merah.

**`next_due_hour_meter` divalidasi `gt:0`, bukan `min:0`.** Nol adalah ANGKA (§24: batas
tidak pernah disimpulkan dari nilainya), dan "servis pada jam ke-0" tidak berarti apa pun
untuk mesin mana pun; menerimanya akan melahirkan `TANPA_ANGGARAN` ("Tidak dianggarkan") di
sisi jam, tempat kalimat itu tidak punya arti. Yang berarti "belum disetel" adalah NULL.
Mutasi M15 merah.

---

## 5. Mutasi — 24 dijalankan, 24 merah (satu lewat lubang lebih dulu)

Dijalankan **dua kali**: sekali atas kode versi pertama (21 mutasi), lalu **seluruhnya
ulang** atas kode sesudah penulisan ulang agregat (§6) dengan empat mutasi baru yang
menyasar persis SQL-nya. Tabel ini yang terakhir.

| # | Mutasi | Hasil |
|---|---|---|
| M1 | pembacaan tertinggi → terbaru | MERAH |
| M2 | tanggal puncak `MAX(log_date)` → `MIN` | MERAH |
| M2b | id pembacaan terakhir `MAX(id)` → `MIN` | MERAH |
| M2c | cacah pembacaan `COUNT(hour_meter)` → `COUNT(*)` | MERAH |
| M2d | `CASE WHEN` yang membuang log tanpa jam dilumpuhkan | MERAH |
| M3 | perawatan terbaru → terlama | MERAH |
| M4 | aset `disposed` ikut diawasi | MERAH |
| M5 | log mobilisasi yang dihapus ikut dihitung | MERAH |
| M6 | lingkup daftar `&&` → `\|\|` | MERAH |
| M7 | dua sebab tidak-terukur tertukar | MERAH |
| M8 | ambang `>=` → `>` (batas tepat 4.950) | MERAH |
| M9 | margin diabaikan (peringatan hanya saat lampau) | MERAH |
| M10 | persentase tetap dikirim untuk entri jam | MERAH |
| M11 | `warn_pct` tetap dikirim untuk entri jam | MERAH |
| M12 | urutan sisa dibalik | MERAH |
| M13 | satuan entri `jam` → `rupiah` | MERAH |
| M14 | resource kehilangan kolomnya | MERAH |
| M15 | `gt:0` → `min:0` (target 0 jam diterima) | MERAH |
| M16 | pengawas tanggal kehilangan klausa jam | MERAH |
| M17 | cetakan kembali mencetak tanggal saja | MERAH |
| M18 | endpoint history tanpa blok jam | MERAH |
| M19 | bawaan margin di entri Core 50 → 999 | **LOLOS HIJAU** (putaran 1) → ditutup → MERAH |
| M20 | bawaan margin di service Assets 50 → 999 | MERAH |
| M21 | angka yang dikirim `config/erp.php` 50 → 999 | MERAH |

**M19 adalah temuan mutasi itu sendiri**, dan bentuknya persis pelajaran F-6: `config/erp.php`
selalu menyebutkan kunci ambangnya, jadi **bawaan sisi Core tidak pernah tersentuh satu uji
pun**. Pada hari seseorang mengosongkan config itu, layar Ambang akan memperingatkan pada
jarak yang berbeda dari kartu alat. Ditutup dengan
`test_the_core_entry_falls_back_to_the_same_default_as_assets`, yang mengosongkan config
lebih dulu lalu menuntut kedua bawaan itu satu angka.

**Uji yang memakai konstanta produksi diperiksa sendiri** (pelajaran F-6): ambang 50 jam
**ditulis** di `setUp` uji, dan 4.949 / 4.950 / 5.000 dihitung darinya dengan tangan. Angka
yang benar-benar dikirim pemilik dipaku terpisah dengan **membaca `config/erp.php` dari
disk** (`require config_path('erp.php')`), karena `config()` sudah ditimpa setUp — tanpa itu
mutasi M21 akan lolos hijau.

---

## 5b. Satu jalan buntu yang diukur lalu dibuang

Kelas ini mula-mula mengambil **setiap baris** log milik aset yang diawasi dan menghitungnya
di PHP. Diukur pada **24.007 pembacaan** — bentuk armada kontraktor sekitar dua tahun
(~50 alat × 250 hari kerja), disuntikkan ke salinan sqlite scratchpad:

| | seluruh armada (`#/ambang`) | satu alat (kartu aset) |
|---|---|---|
| ambil semua log, hitung di PHP | **803 ms** | **147 ms** |
| pola `whereNotExists` (latest_per_group milik `WatchedDeadlines`) | 1.130 ms — **lebih lambat**, 1.065 ms di antaranya satu subkueri berkorelasi | 218 ms |
| tiga/empat kueri agregat (yang dikirim) | **17,0 ms** | **4,0 ms** |

(median dari lima jalan sesudah pemanasan; rentang 15,5–21,6 ms dan 3,8–5,0 ms.) Register ini
**hanya bisa membesar** — ia append-only dan tidak punya pintu hapus
(`EquipmentLogController` menolak PUT/DELETE) — jadi angka pertama itu adalah angka yang
memburuk setiap hari. Keluaran ketiganya IDENTIK pada data demo bercabang: enam baris, keadaan,
sisa dan kalimat yang sama persis.

**DUA KOREKSI PADA TABEL DI ATAS (putaran verifikasi, 9 Sep 2026).** Kalimat "jumlah kueri
tidak bertambah" **salah**, dan tabelnya hanya mengukur SATU sumbu:

| bentuk data | versi | kueri per `rows()` | median 5 jalan |
|---|---|---|---|
| 50 alat × 480 pembacaan (**24.002**) | sebelum (`e4b0beb^`) | 4 | **775,0 ms** (752,6–1.072,6) |
| 50 alat × 480 pembacaan | HEAD | **7** | **39,3 ms** (37,1–45,3) |
| **300 alat** × 2 pembacaan (602) | sebelum (`e4b0beb^`) | 4 | **58,3 ms** (52,6–65,0) |
| **300 alat** × 2 pembacaan | HEAD | **7** | **92,7 ms** (72,1–94,1) |

Jadi arah perbaikannya benar pada sumbu PEMBACAAN (≈20×) dan **terbalik pada sumbu JUMLAH
ALAT** (1,6× lebih lambat): kueri (2) dan (3) merakit rantai `orWhere` yang bercabang satu
kali per aset, jadi armada besar membayar panjang SQL yang tumbuh linear. Angkanya masih di
bawah 0,1 detik untuk 300 alat berjam — armada kontraktor yang sangat besar — jadi yang
dikirim tetap versi agregat; menggantinya dengan satu subkueri window/`ROW_NUMBER` atau join
ke agregat adalah pekerjaan yang **belum** dilakukan dan tidak boleh diklaim sudah.
(Diukur dengan `DB::listen` + median lima jalan sesudah pemanasan, skrip penanam bentuk data
dan pengukurnya di scratchpad; versi lama diambil dengan `git show e4b0beb^:…`.)

Yang menarik dari baris kedua: **pola rumah yang sudah terbukti pun harus diukur di tempat
barunya.** `latest_per_group` benar dan murah pada `ast_maintenances` (satu baris per aset,
tabel kecil); pada `ast_equipment_logs` ia justru lebih lambat daripada mengambil seluruh
tabelnya.

---

## 6. Bukti peramban (pelajaran P1-I: rilis SPA belum terverifikasi sampai DIMUAT)

`php -S 127.0.0.1:8191` atas **salinan** `database.sqlite` di scratchpad (data demo hidup
tidak disentuh), Chromium headless, login `admin@nusantara.test`:

| Layar | Galat konsol | Gulir samping |
|---|---|---|
| `#/ambang` | 0 | tidak |
| kartu alat AST-0007 (mendekati, dua pemicu) | 0 | tidak |
| kartu alat AST-0002 (lampau + pita meter mundur) | 0 | tidak |
| kartu alat AST-0001 (log tanpa jam) | 0 | tidak |
| kartu alat AST-0008 (belum pernah dimobilisasi) | 0 | tidak |
| kartu alat AST-0004 (bukan alat berjam — tanpa kartu servis) | 0 | tidak |
| `#/r/assets/maintenances` + formulirnya | 0 | tidak |
| detail satu perawatan | 0 | tidak |

Total: **0 galat konsol, 0 respons ≥ 400** di seluruh sesi.

**LAYARNYA DIBUKA, ANGKANYA TIDAK DIBACA** (putaran verifikasi): baris terakhir tabel di
atas benar — layar detail satu perawatan memang dimuat tanpa galat — dan justru di sana
angkanya tercetak mentah, "5212.5", karena tidak ada yang membacanya. "0 galat konsol"
bukan "layarnya benar". Sesudah perbaikan, diukur di Chromium pada
`#/d/assets/maintenances/2` (php -S 127.0.0.1:8195, salinan DB demo):
"Jatuh tempo berikutnya 14 Des 2026 · **Jadwal berikutnya (hour meter) 5.212,5 jam**",
0 galat konsol.

**Dua kalimat yang HANYA terlihat sesudah halamannya dimuat** — keduanya diperbaiki:

1. Sisa negatif tercetak **"-40 jam lagi"** — sebuah minus yang harus dibaca dua kali di
   kolom yang seluruh tugasnya memberi tahu berapa lama lagi. Sekarang **"150 jam lewat"**
   (registri) / **"lewat 150 jam"** (kartu alat).
2. Kartu "Cara membacanya" menjelaskan ambang **100 %** tepat di bawah tabel yang tidak
   punya satu persentase pun — bentuk yang sama dengan cacat "90,0 % · Aman" yang F-2 tutup,
   hanya pindah tempat. Paragrafnya sekarang menyebut kedua bentuk ukuran.

**Harness S34** (`docs/bukti-uji/harness-playwright.py`, kunci baru di `results-phase-2.json`):

| Skenario | Syarat | Hasil |
|---|---|---|
| `S34_servis_alat_per_jam` (desktop 1440×900) | 22 | `ok` |
| `S34_servis_alat_per_jam_mobile` (390×844) | 9 | `ok` |

Fixture-nya ditanam **lewat API**, bukan SQL, dan **idempoten**. Klaim "dijalankan dua kali
berturut-turut, keduanya hijau" **tidak reproduksi pada versi pertama** dan sudah
diperbaiki: pendengar konsol dipasang sebelum `login()`, jadi throttle 429 gerbang masuk
pada jalan kedua dihakimi sebagai galat konsol produk. Terukur pada satu salinan DB
(`php -S 127.0.0.1:8195`):

| | jalan 1 | jalan 2 |
|---|---|---|
| sebelum perbaikan | S34 `ok`, S34m `ok` | S34 `ok`, **S34m GAGAL** `the_screens_raise_no_console_error` (`["error: … 429 (Too Many Requests)"]`) |
| sesudah perbaikan | S34 `ok`, S34m `ok` | S34 `ok`, S34m `ok` |

Log jam tidak dihapus di akhir (register pembacaan append-only —
`EquipmentLogController` menolak PUT/DELETE dengan kalimatnya sendiri); catatan perawatan
yang ditanam dihapus.

---

## 7. Sapuan dokumentasi (CONVENTIONS §35)

Angka di bawah ini **dijalankan ulang pada HEAD 9 Sep 2026** (putaran verifikasi: tiga
dari empat baris versi sebelumnya tidak keluar dari perintah yang tercetak di sebelahnya
— mis. `grep -rn "Berikutnya" docs/ | wc -l` memulangkan 37/16 di `main` dan bukan
28/11). Perintahnya **mengecualikan berkas laporan ini sendiri**, karena sapuan yang
dimaksud adalah sapuan atas dokumen LAIN — dan karena angka yang menghitung dirinya
sendiri berubah setiap kali paragraf ini disunting. Itu perbedaan yang dulu membuat dua
baris terlihat "hampir cocok".

```
# SEBELUM
git grep -n  "<kata>" main -- docs/ | grep -v LAPORAN-PAKET-HM-F-7 | wc -l
# SESUDAH
grep -rn     "<kata>" docs/         | grep -v LAPORAN-PAKET-HM-F-7 | wc -l
```

| kata | sebelum (`main`) | sesudah (HEAD) |
|---|---|---|
| `Berikutnya` | 37 baris / 16 berkas | **36 / 15** |
| `Servis aset` | 2 baris / 2 berkas | **4 / 3** |
| `Jadwal berikutnya` | 2 baris / 1 berkas | **4 / 1** |
| `hour meter` | 11 baris / 5 berkas | **26 / 8** |

Daftar berkas "Berikutnya" berubah tepat satu masuk satu keluar (`comm` atas kedua
daftar): yang KELUAR adalah `docs/PANDUAN-PENGGUNA.md`, dan itu disengaja —
satu-satunya penyebutannya adalah **judul kolom** daftar perawatan, yang kini bernama
**"Jadwal berikut"** (tanggal) berdampingan dengan **"Jam berikut"**, karena dua kolom
yang sama-sama berjudul "Berikutnya" adalah dua kolom yang tertukar. Yang MASUK adalah
berkas laporan ini sendiri. **Lima belas berkas sisanya** diperiksa satu per satu:
seluruhnya laporan UX/paket lain, berkas patch, dan berkas bukti harness
(`results-phase-*.json`) — tidak satu pun berbicara tentang layar ini.

Kalimat yang **menjadi salah** dan sudah diperbaiki:

- `PANDUAN-PENGGUNA §9.6` — kolom, formulir, dan paragraf *"`Jadwal berikutnya` adalah
  pengingat yang benar-benar berbunyi"*, yang dulu menyiratkan **satu** pemicu.
- `PANDUAN-PENGGUNA §9.3` — kartu aset kini punya kartu **Servis berikutnya** sebelum empat
  kartu riwayat; tiga sebab "belum terukur" dan pita meter mundur dijelaskan.
- `PANDUAN-PENGGUNA §1.7` (tabel tenggat) — baris servis aset diberi keterangan bahwa sisi
  JAM-nya ada di layar Ambang.
- `PANDUAN-PENGGUNA` (bagian anggaran) — daftar isi layar Ambang & Batas + penjelasan bahwa
  tidak semua ukuran di sana berbentuk persentase.
- `PANDUAN-ADMINISTRATOR` (tabel registri tenggat) — catatan **§** baru: pemicu kedua,
  di mana ambangnya, dan mengapa alarm "tanpa jadwal berikut" menyempit.
- `ONBOARDING/project-manager.md` — pemicu kedua dan pasangan layar Tenggat ↔ Ambang.
- `CONVENTIONS §24` (dua generalisasi registri + bawaan margin di dua tempat) dan
  **§36 baru** (dua pemicu, dua definisi, tiga sebab, siapa yang diawasi).

---

## 8. Keputusan pemilik

1. **Ambang peringatan 50 jam sebelum target** (`erp.thresholds.maintenance_hour_meter`).
   Dasarnya: 50 jam ≈ 5–6 hari kerja alat berat (8–10 jam/hari) — cukup untuk memesan
   sparepart dan menjadwalkan mekanik, dan **sama panjangnya untuk alat baru maupun alat
   tua**, yang tidak bisa dilakukan ambang persen. Angkanya milik pemilik; ia ada di config,
   bukan di konstanta. **Kalau pemilik memilih angka lain, satu baris config saja.**
2. **Satu ambang untuk seluruh armada.** Excavator, dump truck, genset dan fusion splicer
   memakai 50 jam yang sama. Ambang per kategori aset (mis. 25 jam untuk genset, 100 jam
   untuk alat berat) **bisa** ditambahkan sebagai kolom pada `ast_categories`, tetapi itu
   paket tersendiri dan belum ada permintaannya.
3. **Alat yang tidak punya target jam dan tidak punya pembacaan tidak dibariskan.**
   Scaffolding dan rak server tanpa target tidak muncul sebagai "belum terukur" selamanya.
   Kalau pemilik ingin daftar "alat yang SEHARUSNYA diukur jam tetapi belum disetel", itu
   pertanyaan yang berbeda dan butuh penanda per kategori (lihat keputusan 2).
4. **Pemberitahuan pagi.** Sisi TANGGAL berbunyi lewat `erp:deadline-watch` (08.30 WIB).
   Sisi JAM **tidak mengirim pemberitahuan** — ia hidup di layar Ambang, seperti tiga entri
   ambang yang sudah ada. Mengirimkannya lewat lonceng berarti mendefinisikan "kapan sebuah
   ambang berbunyi ulang" untuk seluruh registri, yang belum pernah diputuskan siapa pun.
5. **Log perjalanan kendaraan** (bagian "+ log perjalanan bila ada kendaraan" pada baris
   roadmap) **tidak dikerjakan** — lihat §9.
6. **PENGGANTIAN METER DAN SALAH KETIK YANG NAIK BELUM PUNYA JALAN KOREKSI** (putaran
   verifikasi). Definisi (B) memilih pembacaan TERTINGGI supaya satu digit yang HILANG
   tidak mendiamkan servis yang sudah lewat. Harganya, yang tidak pernah ditulis sampai
   sekarang: satu digit yang KELEBIHAN — dan penggantian hour meter, sebab yang pita
   peringatan kartu alat sebutkan sendiri — mengunci alat itu di "Melampaui batas"
   selamanya. Terukur lewat HTTP pada salinan DB demo (AST-0007, target 3.400 jam, log
   33.755 di atas 3.375,5): pembacaan berikutnya yang lebih rendah **422** di mobilisasi
   yang sama; `PUT` dan `DELETE` ditolak dengan kalimat register; baris koreksi pada
   mobilisasi **BARU** diterima (201) dan **tidak mengubah apa pun** — `reading=33755
   latest=3400 remaining=-30355 state=Melampaui batas`. Satu-satunya jalan keluar yang
   tersisa adalah menaikkan target servisnya, yaitu merusak rencana perawatan untuk
   mendiamkan alarm palsu — dan PANDUAN-PENGGUNA §9.5 sekarang melarangnya secara
   eksplisit.
   **Perilaku hari ini DIPERTAHANKAN dan DINYATAKAN**; yang diperbaiki di putaran ini
   hanya kalimat-kalimat yang menjanjikan koreksi yang tidak ada (`39bb27e`). Dua
   mekanisme yang mungkin, keduanya perubahan data/perilaku yang pantas diputuskan
   pemilik, bukan diselundupkan ke putaran perbaikan:
   (a) **penanda koreksi** pada `ast_equipment_logs` (mis. `corrects_log_id`, ditulis oleh
       baris koreksi dan dikecualikan dari `MAX`) — riwayat tetap utuh, register tetap
       hanya-tambah, dan kalimat penolakannya kembali benar; butuh satu migrasi, satu
       kotak formulir, dan satu aturan tulis (baris yang dikoreksi harus milik aset yang
       sama);
   (b) **puncak dihitung sejak tanggal kartu servis yang berlaku** — kartu "ganti hour
       meter" menjadi garis awal yang benar; tanpa kolom baru, tetapi ia mengubah arti
       definisi (B) untuk SETIAP alat dan butuh cadangan "kalau jendelanya kosong, pakai
       seluruh riwayat" supaya kartu servis yang dicatat hari ini tidak menghapus
       pembacaan minggu lalu.
7. **LAYAR TENGGAT SENGAJA HANYA BICARA TANGGAL** (putaran verifikasi). Pengawas yang
   mengikuti pemberitahuan 08.30 mendarat di `#/tenggat` dan tidak menemukan satu kata pun
   tentang target jam kartu servis yang sama; CONVENTIONS §36 dulu menulis aturannya
   sebagai universal ("tidak ada permukaan yang boleh menampilkan satu tanpa yang lain")
   sambil mendaftar permukaan yang tidak memuat layar itu. Kalimat konvensinya sudah
   diperbaiki menjadi daftar permukaan + pernyataan eksplisit (`540718d`), dan
   **perilakunya tidak diubah**: entri `maintenance_next_due` tidak membawa kolom nilai,
   dan sisi jam memang tidak mengirim pemberitahuan (keputusan 4). Membawa target jam ke
   baris Tenggat berarti memberi entri tenggat sebuah kolom nilai **beserta satuannya**
   (`tenggat.js` memformat kolom nilai dengan `fmt.rupiah` tanpa syarat — cacat D1 yang
   sama, di layar saudaranya). Itu keputusan pemilik, dan pekerjaannya bukan satu baris.

---

## 9. Yang TIDAK dikerjakan

1. **Log perjalanan kendaraan (odometer / rute).** Baris roadmap menyebutnya sebagai
   tambahan bersyarat ("bila ada kendaraan"). Register yang ada (`ast_equipment_logs`)
   mencatat **jam** dan **liter**, bukan kilometer, dan servis kendaraan berbasis
   **odometer** adalah kolom ketiga dengan aturan monotonnya sendiri, ambangnya sendiri
   (km, bukan jam), dan pertanyaan "satu kendaraan bisa punya dua meter" yang belum
   dijawab siapa pun. Menambahkannya sekarang berarti menebak tiga aturan; paket ini
   memilih mengirim satu pemicu yang benar. Generalisasi registri yang dikerjakan di sini
   (`unit` + `warn_margin_key`) sudah menyediakan tempatnya: entri "servis kendaraan per
   kilometer" adalah **satu entri array** dengan `unit => 'km'`.
2. **Penggulingan target otomatis.** Tidak ada apa pun yang menambah 250 jam ke target
   setelah servis dicatat — persis seperti `next_due_date`, yang juga murni entri manual.
   Yang menggulirkannya adalah mekanik yang mencatat kartu servis berikutnya.
3. **`hour_meter_at_service`** (pembacaan meter PADA saat servis). Dipertimbangkan supaya
   registri bisa mengukur *persentase interval* alih-alih sisa jam; ditolak karena ia kolom
   kedua yang harus diisi orang, dan sisa jam sudah menjawab pertanyaan yang sama tanpa
   menambah pekerjaan lapangan.
4. **Notifikasi/kalender untuk sisi jam** — lihat keputusan pemilik 4.
5. **Merge dan deploy.** Cabang `feat/phase2-f7` belum di-merge ke `main` dan **tidak**
   di-deploy; `deploy/sync-erp1.sh` menyalin pohon kerja apa adanya, dan menjalankannya di
   tengah pembangunan mengirim paket setengah jadi ke situs hidup (kejadian 8 Sep 2026).

---

## 10. Deviasi baru yang ditemukan

| # | Temuan | Bukti | Sikap |
|---|---|---|---|
| D1 | **`unit` registri ambang tidak pernah dibaca layar.** Setiap entri mendeklarasikannya sejak F-2; `ambang.js` memanggil `fmt.rupiah` pada kedua sel angkanya | entri berjam pertama mencetak "Rp 3.375,50" untuk 3.375,5 jam | **DITUTUP** di paket ini (mutasi M13 merah) |
| D2 | **Ambang persen tidak berlaku untuk angka yang tidak mulai dari nol.** Meter kumulatif membuat satu angka config memberi tenggang 25–1.225 jam tergantung umur alat | tabel di §4 | **DITUTUP** — `warn_margin_key` + `proportional` |
| D3 | **Alarm "Servis aset tanpa jadwal berikut" menghukum pemakaian yang benar.** Sejak kolom jam ada, kartu servis yang dijadwalkan dengan jam saja diteriaki setiap pagi | `DeadlineWatchTest::test_a_service_scheduled_by_hour_meter_is_not_a_service_without_a_schedule`; mutasi M16 merah | **DITUTUP** — `missing_scope` |
| D4 | **Kartu aset cetak menyembunyikan setengah kartu servis.** Sel "JATUH TEMPO BERIKUT" hanya membaca tanggal, jadi servis yang dijadwalkan dengan jam tercetak **bergaris** — persis seperti servis yang tidak menjadwalkan apa pun, di lembar yang ditandatangani | `AssetPrintTest::test_the_asset_card_prints_both_service_triggers`; mutasi M17 merah | **DITUTUP** — satu sel, dua pemicu ("14 Desember 2026 / 5.500 jam") |
| D5 | **`WatchedThresholds::flushSuppliers()` disebut CONVENTIONS §24 "sudah dipasang di `ErpTestCase::setUp`" — ia TIDAK dipasang di sana.** `grep -rn flushSuppliers` memulangkan dua baris: definisinya, dan satu pemanggilan manual di `ThresholdWatchTest:296` | grep di atas | **KALIMATNYA DITUTUP** (`540718d`), perilakunya tetap. `ErpTestCase` tidak disentuh — itu menyentuh setiap uji di repo dan bukan pekerjaan paket fitur — tetapi §24 sekarang mengatakan apa adanya: `flushSchemaMemo()` sudah dipasang, `flushSuppliers()` **belum**, dan uji yang memerlukannya memanggilnya sendiri. Membiarkan kalimat yang salah karena perbaikan KODEnya mahal adalah dua hal yang berbeda |
| D6 | **Sisa negatif tercetak dengan tanda minus** ("-40 jam lagi") di kolom yang seluruh tugasnya memberi tahu berapa lama lagi | terlihat di Chromium, bukan di uji mana pun | **DITUTUP** — "150 jam lewat" / "lewat 150 jam" |
| D8 | **Register pembacaan dibaca seluruhnya untuk menjawab lima angka.** Kartu satu alat 147 ms dan layar Ambang 803 ms pada dua tahun register — pada tabel yang hanya bisa membesar | tabel §5b, diukur pada 24.007 pembacaan | **DITUTUP** — empat kueri agregat, 4,0 ms dan 17,0 ms; keluaran identik |
| D7 | **Kartu "Cara membacanya" menjelaskan ambang 100 %** di layar yang kini memuat tabel tanpa satu persentase pun | terlihat di Chromium | **DITUTUP** — paragrafnya menyebut kedua bentuk ukuran |

**Putaran verifikasi (9 Sep 2026)** — temuan yang ditutup di putaran ini:

| # | Temuan | Bukti | Sikap |
|---|---|---|---|
| D9 | **Ambang jam dihakimi pada selisih float MENTAH** sementara sisa yang dicetak layar dibulatkan 3 desimal: dua alat yang sama-sama "50 jam lagi" mendapat dua lencana berbeda pada target PECAHAN (512,2/462,2 → Aman; 5.120,2/5.070,2 → Mendekati) | `state()` diukur langsung; uji baru di kedua berkas memakai target pecahan | **DITUTUP** `f168f0c` — dihakimi pada sisa yang dicetak, aturan yang sama yang F-2 pakai untuk persentase; 2 mutasi merah |
| D10 | **`gt:0` dijalankan sebelum pembulatan kolom**: `next_due_hour_meter` 0,0004 → 201, tersimpan `0.000`, dan lahirlah keadaan "Tidak dianggarkan" yang tiga docblock bilang mustahil di sisi jam | `POST /api/assets/maintenances` di php -S | **DITUTUP** `0a14c06` — `decimal:0,3` di kedua pintu tulis, kotak formulir berlantai 0,001; mutasi merah |
| D11 | **Pesan 422 kolom baru berbahasa Inggris** — "next due hour meter harus lebih besar dari 0." di bawah kotak berlabel "Jadwal berikutnya (hour meter)" | 422 diukur lewat HTTP; `grep next_due lang/id/validation.php` = satu baris | **DITUTUP** `10b001a` — satu baris di peta `attributes`, dipaku uji pintu tulis |
| D12 | **Layar detail satu perawatan mencetak jam MENTAH** ("5212.5"): `next_due_hour_meter` berakhiran `_meter`, tidak cocok satu cabang format pun, jatuh ke `String(value)` | Chromium `#/d/assets/maintenances/2` | **DITUTUP** `904930d` — satu cabang `/hour_meter$/` menutup kolom ini DAN `hour_meter` log alat; mutasi merah |
| D13 | **Lencana kartu "Servis berikutnya" adalah vonis atas SATU dari dua pemicu yang kartu itu tampilkan**, dan tanggal 86 hari lalu disebut "jadwal kalender berikutnya" | Chromium; layar Tenggat meneriakkan baris yang sama pada hari yang sama | **DITUTUP** `41fd563` — judul menyebut sisinya ("…menurut jam"), tanggal lewat dicetak merah dengan umur relatifnya; 2 syarat S34 baru |
| D14 | **Entri ambang yang NOL BARIS tidak punya kalimat** — dan nol baris adalah keadaan bawaan setiap pemasangan baru | Chromium pada salinan DB yang jam-nya dikosongkan | **DITUTUP** `fda9025` — berlaku untuk seluruh registri, kalimatnya dari `subject_word` entrinya |
| D15 | **Tiga uji yang tidak membedakan**: fixture registri menanam SATU pembacaan (menukar `reading`↔`latest_reading` lolos hijau); jalur PUT tidak diuji sama sekali (menghapus aturannya lolos hijau, 205 uji); jarum lencana ambang dipenuhi KOMENTAR di atas kodenya | tiga mutasi yang lolos hijau, semuanya dijalankan | **DITUTUP** `978a519`, `e54a779`, `a2c2f23` — ketiga mutasi sekarang merah |
| D16 | **Harness**: pendengar konsol dipasang sebelum `login()`, jadi throttle 429 jalan kedua dihakimi sebagai cacat produk; dan syarat "…beside a RULED DATE" tidak pernah memeriksa sel tanggalnya (serta digantung pada literal "8.000 jam" yang tidak pernah ditanam) | dua jalan berturut-turut sebelum/sesudah; `baris_jam_saja` dicetak ke results | **DITUTUP** `16fd881`, `b58f9f4` — mutasi kolom tanggal merah |
| D17 | **Alasan yang ditulis untuk salinan kelima array `BULAN` sudah tidak benar** ("APP_LOCALE 'en' tanpa direktori lang/") | `APP_LOCALE=id` di kedua `.env*.example`; `lang/id` ada; `translatedFormat('j M Y')` memulangkan "31 Jul 2026" | **DITUTUP** `39bb27e` — alasannya diganti dengan alasan yang masih berlaku, dan diberi tanda agar tidak disalin keenam kalinya |

---

## 11. Gerbang

Dijalankan per direktori selama bekerja (gerbang rilis penuh dijalankan terpisah oleh
pemilik):

| Gerbang | Driver | Hasil |
|---|---|---|
| `tests/Feature/Assets` | SQLite | OK — **123 uji, 441 asersi** |
| `tests/Feature/Core` | SQLite | OK — 983 uji, 8.907 asersi, 11 dilewati (sebelum penulisan ulang agregat) |
| Core: ambang + tenggat + kalender (5 berkas) | SQLite | OK — 105 uji, 374 asersi (sesudah penulisan ulang) |
| `tests/Feature/Assets` + ambang/tenggat Core | **MySQL** `erp_dryrun` | OK — **205 uji, 717 asersi** (9 mnt 58 dtk) |
| `pint` atas berkas baru/diubah | — | lolos (dua kegagalan lama di `main` — `FormXlsxExportService`, `ChartMigrationTest` — tidak disentuh) |

**Sesudah putaran verifikasi (9 Sep 2026)** — dijalankan ulang di pohon ini:

| Gerbang | Driver | Hasil |
|---|---|---|
| `tests/Feature/Assets` | SQLite | OK — **127 uji, 481 asersi** |
| `tests/Feature/Core` | SQLite | OK — **984 uji, 8.915 asersi**, 11 dilewati |
| `tests/Feature/Assets` + `ThresholdHourMeterTest` + `ThresholdWatchTest` + `DeadlineWatchTest` | **MySQL** `erp_dryrun` | OK — **210 uji, 765 asersi** (8 mnt 47 dtk) |
| `pint` atas 10 berkas PHP yang disentuh putaran ini | — | `{"tool":"pint","result":"passed"}` |
| Harness `S34` + `S34m` | Chromium | `ok` — **22 + 9 syarat**, dua jalan berturut-turut keduanya hijau |
| Peramban: 11 rute × 2 ukuran (1440×900 dan 390×844) | Chromium | **0 galat konsol, 0 respons ≥ 400, 0 gulir samping** |

Sebelas rute itu: `#/home`, `#/dashboard`, `#/ambang`, `#/tenggat`,
`#/r/assets/maintenances`, `#/d/assets/maintenances/{id}`, empat kartu aset, dan
`#/r/assets/equipment-logs`.

---

## 12. Commit

| SHA | Judul |
|---|---|
| `e355694` | migrasi: `ast_maintenances.next_due_hour_meter` — pemicu servis KEDUA |
| `8095c04` | assets/core: definisi jatuh tempo servis per jam — dan dua pilihan yang bisa salah |
| `f9da79c` | assets: Assets memasok baris pemicu jam ke registri ambang — ambangnya 50 JAM |
| `d3a0c9f` | core: servis yang dijadwalkan dengan JAM bukan servis tanpa jadwal |
| `6fe244f` | permukaan: kedua pemicu berdampingan di SETIAP layar, cetakan, dan sel |
| `b2dd280` | uji: 39 uji F-7 dan 21 mutasi yang dipaku merah |
| `7b297b9` | harness: S34 desktop + ponsel (29 syarat) |
| `c16609f` | docs: sapuan dokumentasi, CONVENTIONS §36, laporan paket |
| `e4b0beb` | assets: ringkasan pembacaan lewat kueri AGREGAT — 147 ms → 4 ms |

**Putaran verifikasi (9 Sep 2026):**

| SHA | Judul | Temuan yang ditutup |
|---|---|---|
| `f168f0c` | ambang: dua alat yang sama-sama "50 jam lagi" tidak lagi mendapat dua lencana berbeda | D9 |
| `0a14c06` | assets: target jam 0,0004 tidak lagi lolos jadi kartu berlencana "Tidak dianggarkan" | D10 |
| `10b001a` | lang: kotak "Jadwal berikutnya (hour meter)" tidak lagi ditolak dalam bahasa Inggris | D11 |
| `e54a779` | uji: pintu UBAH target jam dipaku — menghapus aturannya dulu lolos hijau | D15 |
| `978a519` | uji: fixture registri jam menanam DUA pembacaan | D15 |
| `904930d` | spa: layar detail perawatan mencetak "5.212,5 jam", bukan "5212.5" | D12 |
| `a2c2f23` | uji: lencana ambang jam dipaku pada KODENYA | D15 |
| `41fd563` | spa: kartu servis alat berhenti berlencana "Aman" untuk alat yang servis kalendernya lewat 86 hari | D13 |
| `16fd881` | harness: throttle gerbang masuk berhenti dihitung sebagai galat konsol produk | D16 |
| `b58f9f4` | harness: syarat "jam di sebelah tanggal BERGARIS" benar-benar memeriksa sel tanggalnya | D16 |
| `fda9025` | spa: entri ambang yang nol baris mengatakan apa artinya nol | D14 |
| `39bb27e` | assets: pintu register berhenti menjanjikan koreksi yang tidak sampai ke alarm servis | keputusan pemilik 6, D17 |
| `540718d` | docs: tiga kalimat CONVENTIONS yang bisa diperiksa dan ternyata salah | D5, keputusan pemilik 7 |
| `41ef072` | docs: angka §5b, §6 dan §7 dijalankan ulang | §5b, §6, §7 |

## Verifikasi penutup — tiga temuan terakhir (9 September 2026)

| # | Jenis | Gejala yang dibaca pemakainya | Perbaikan |
|---|---|---|---|
| G1 | HONESTY | Kartu "Servis alat menurut jam operasi · 0 baris" berbunyi "Sebuah baris muncul begitu ada yang diukur **DAN** batasnya disetel" — tepat di atas tabel yang, pada keadaan data lain, menggambar baris yang hanya punya SALAH SATU ("Batas belum disetel" / "Belum ada yang diukur"). Layar membantah dirinya sendiri dalam satu pemindaian. | Satu kata: **ATAU**. Klausanya dipaku uji (`test_the_threshold_screen_says_what_zero_rows_means`) dari dua arah — menuntut "ATAU" dan menolak "DAN". |
| G2 | TEST-GAP | Menjalankan harness dengan nama yang tertulis di laporan dan di `results-phase-2.json` (`S34_servis_alat_per_jam`) mencocokkan NOL entri: seluruh loop dilewati, "saved results.json" tercetak, status keluar **0**. Empat kali di dalam putaran verifikasi ini sendiri — termasuk sekali yang menyimpulkan sebuah mutasi "lolos hijau" padahal tidak satu skenario pun berjalan. | Runner menolak nama tak dikenal dengan `exit 2` dan mencetak daftar yang dikenal; nama PANJANG kini menjadi alias resmi (dibawa `wrapper.scenario_name`); dan "diminta sesuatu, nol yang jalan" juga `exit 2`. |
| G3 | BUG | Mekanik yang menahan satu tombol angka terlalu lama pada "Jadwal berikutnya (hour meter)" mendapat **HTTP 500**, bukan tulisan merah di bawah kotaknya. `decimal:0,3` menghakimi angka di belakang koma; 13 angka di depannya tidak dijaga siapa pun. Di MySQL STRICT_TRANS_TABLES itu SQLSTATE 22003; di SQLite tersimpan diam-diam sebagai `1.0e+18` dan setiap layar sisa jam membaca `9,99e+17`. | `max:` sebesar jangkauan kolomnya pada `next_due_hour_meter` **dan** `cost` di formulir yang sama (menutup satu kotak dan meninggalkan tetangganya adalah cacat "benar di satu permukaan, bocor di permukaan lain"), plus `max` pada kotaknya di `schema.js`. |

**Catatan G2 melampaui paket ini.** Cacat itu ada di harness sejak awal dan menyentuh SETIAP paket
yang buktinya dijalankan ulang dengan nama panjang. Semua bukti F-7 dijalankan ULANG sesudah
perbaikannya, dengan kedua bentuk nama, dan hijau: `S34_servis_alat_per_jam` 22 syarat,
`S34_servis_alat_per_jam_mobile` 9 syarat; 25 kunci di `results-phase-2.json`, tidak satu pun dari
23 kunci lama tersentuh.

**Batas representasi yang ditemukan G3, dinyatakan.** Nilai batas PERSIS kolomnya
(999.999.999.999,999) tidak bisa dicapai lewat JSON: PHP dengan `precision=14` bawaan merender
float itu sebagai `1.0E+12` saat aturan `max` membandingkannya, jadi ia ditolak. Itu batas
representasi float PHP, bukan batas yang dipilih paket ini, dan ujinya memakai 999.999.999.999
(tanpa desimal) supaya yang dipaku adalah aturan kita, bukan perilaku float.

**Deviasi yang TIDAK ditutup di sini:** `max:` jangkauan kolom belum menjadi aturan rumah untuk
SETIAP pintu tulis `decimal(p,s)` di aplikasi. Terukur hari ini: `decimal:0,` dipakai di 4 tempat
di 2 berkas (keduanya F-7, keduanya kini ber-`max`), tetapi kolom rupiah `numeric` tanpa `max` jauh
lebih banyak dan menyapunya adalah pekerjaan tersendiri. Ia dicatat di sini, bukan dikerjakan
diam-diam.
