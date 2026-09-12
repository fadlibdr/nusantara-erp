# Laporan Paket HM P-3c — Bank (preset per rekening, registri preset bank yang jujur, folder terpantau per jam)

**Cabang:** `feat/phase3-p3c` (dari `main` b4fb40b = merge P-3b) · **Tanggal:** 12 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 3 / P-3c (4 hari-orang), tugas T3c.0–T3c.3
**Status:** bagian yang **tidak menunggu pemilik** selesai di cabang — **belum di-merge, belum
di-deploy** (keduanya langkah pemilik; deploy dilarang untuk agen di alur kerja ini). Bagian yang
menunggu pemilik **tidak dibangun** dan dicatat di §10–§11.

---

## 0. Satu kalimat

Paket ini seluruhnya tentang kata **"otomatis"** — dan keputusannya adalah **jujur soal apa yang
tidak otomatis**. Sesudah P-3c, rekening koran bisa masuk tanpa ada yang membuka layar Impor:
`fin:bank-inbox` membaca **folder di server** tiap jam (sub-folder per kode rekening) dan mengimpor
berkas baru lewat **jalur yang sama persis** dengan layar (tie-out sen-demi-sen, rantai periode/saldo,
identitas sha256, transaksional). Tetapi: berkasnya **tidak datang sendiri dari bank** (tidak ada
host-to-host — penolakan tertulis dipertahankan; tidak ada klien SFTP), aplikasi **hanya membaca**
folder itu (tidak memindah/menghapus — yang ditulis adalah ledger `fin_bank_inbox_files`), CSV hanya
dibaca bila **preset rekening** memetakan kolom saldo (periode/saldo diturunkan dari kolom saldo
berkas), preset itu **pilihan eksplisit** yang disimpan operator dari pratinjau yang berhasil (bukan
sniffing; header yang bergeser → 422 yang menyebut kolomnya), dan **preset bawaan BCA/Mandiri/BNI/BRI
tidak ada** sampai pemilik meletakkan berkas ekspor nyata di `docs/samples/bank/` — registri
`BankPresets` berkata "BELUM ADA BERKAS EKSPOR NYATA" di API, layar, dan README, dari satu sumber.
Folder yang belum ada (keadaan produksi sesudah deploy) membuat perintahnya berkata begitu, keluar 0,
tanpa notifikasi — dan layar tidak pernah mengklaim penjadwalnya hidup.

Tidak ada dependensi baru (`git diff b4fb40b...HEAD -- composer.json composer.lock package.json` =
**0 baris**); tidak ada sentuhan pada `bootstrap/*`, `routes/*` akar, `DatabaseSeeder`; **dua migrasi**
aditif nullable tanpa backfill (Finance 001502, 001503 — CONVENTIONS §2 diperbarui).

---

## 1. Tugas → status → bukti

> Angka di laporan ini adalah angka di **ujung cabang** (§8); angka yang berlaku pada satu commit
> disebut bersama SHA-nya. Setiap angka keluar dari perintah yang dijalankan.
> **Putaran verifikasi (§15, 12–13 Sep 2026)** menutup 18 temuan verifier dalam lima commit
> (`2d9c311`…): angka ujung cabang sesudahnya — `BankInboxTest` **38 uji / 237 asersi**,
> `BankPresetsTest` **10 / 194**, `BankImportPresetTest` **18 / 97**, harness S39 **32 syarat** +
> S39m **10 syarat**, `tests/Feature/Finance` **977 / 5.177**. Angka per-tugas di tabel ini adalah
> angka pada SHA yang disebutnya.

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T3c.0 | Dokumen SEBELUM kode: README daftar belanja pemilik, paragraf KEPUTUSAN-INTEGRASI, registri `BankPresets` + uji kejujurannya | ✅ | `df99244` — `docs/samples/bank/README.md` (satu baris per bank + kanal ekspor, pola `<bank>-<kanal>-<YYYY-MM-DD>.<ekstensi asli>`, siapa memverifikasi, register §4, **tanpa satu nama kolom pun** — dipaku: README menyebut keempat kunci + `sample_stem` dan TIDAK memuat `Tanggal;`/`;Saldo`/`Kolom 1`/`date_column`/…); `Modules\Finance\Support\BankPresets` PERSIS `bca|mandiri|bni|bri`, `verified_against` null keempatnya, `mapping` null keempatnya, `describe()` murni (klaim tanpa berkas di pohon diturunkan DAN pemetaannya ditahan), `demo_note` menyebut dua contoh demo sebagai contoh demo; `docs/KEPUTUSAN-INTEGRASI.md` §10 (folder terpantau dipilih; SFTP tidak; host-to-host tetap ditolak — mengapa dan batasnya). `BankPresetsTest` **10 uji / 163 asersi**, merah dulu (9 error + 1 gagal), 3 mutasi merah (§4) |
| T3c.1 | Preset per rekening: migrasi 001502, PUT/DELETE import-preset, `use_preset`, 422 header-tak-cocok yang menyebut kolom, resource, layar | ✅ | `9357e94` (+ `69ec078`, temuan Chromium §3) — `fin_bank_accounts.import_preset` JSON nullable; `ImportPreset` (fungsi murni: `mappingOnly`, `mappedColumns`, `expectedHeader`, `headerMismatches`, `merge`, `hasBalanceColumn`); `BankStatementImportService::savePreset` (parse + **tie-out nol wajib**; MT940 ditolak), `deletePreset`, **`resolveMapping`** = satu jalur untuk controller dan job; `BankStatementParseRequest` menerima `use_preset` (kolom tidak wajib, periode/saldo tetap wajib); `PUT/DELETE finance/bank-accounts/{id}/import-preset` **fin.update**; `GET finance/bank-statements/presets` (registri) **fin.view**; `BankAccountResource.import_preset`; pratinjau membawa `preset: {used, name}`. Kalimat 422 literal: `Kolom 4 pada preset «BCA KlikBCA» diharapkan 'Debit', berkas berisi 'Mutasi'.` — SEMUA kolom yang bergeser disebut. Layar: pemilih "Pemetaan kolom", kartu preset (periode/saldo + judul yang diingat + Hapus preset), tombol "Simpan sebagai preset rekening ini" hanya pada pratinjau hijau, kartu "Preset bawaan per bank" dari API. `BankImportPresetTest` **18 uji / 95 asersi**, merah dulu (17 gagal), 7 mutasi merah (§4) |
| T3c.2 | Folder terpantau: config, migrasi 001503, `BankInboxService`, `fin:bank-inbox` hourly, API, tab layar, matriks uji, bukti tidak menulis | ✅ | `953288b` — `config('erp.bank_inbox')` (`BANK_INBOX_PATH`, bawaan `storage/app/private/bank-inbox`); `fin_bank_inbox_files` (unik `(relative_path, sha256)`, status `imported|failed|duplicate|ignored`, `error` kalimat, tanpa FK); `BankInboxService::scan()` (sha256 → ledger → `resolveMapping` + `deriveEndpoints` → `preview` → `import` transaksional, `imported_by` null) dan `status()` (tanpa jalur absolut); notifikasi gagal sekali per berkas (signature = 40 karakter pertama sha256 — §3.5, renag 7 hari) + sukses ringkas bertautan; `fin:bank-inbox` `hourly()` (dipaku lewat `schedule:list` + regex ekspresi cron); `GET finance/bank-inbox` **fin.view**, `POST finance/bank-inbox/run` **fin.create**; tab kelima "Folder terpantau"; stempel `core_settings` `bank_inbox.checked_at` (kunci internal baru di `SettingService::INTERNAL_KEYS`). `BankInboxTest` **25 uji / 132 asersi**, merah dulu (21 error + 4 gagal) — termasuk **potret folder** (nama/ukuran/inode/mtime) sebelum = sesudah dua pemeriksaan; 11 mutasi merah (§4) |
| T3c.3 | Dokumen: PANDUAN (Impor: preset, registri, folder), ADMINISTRATOR (runbook §5.13), CONVENTIONS §2 + §40, ONBOARDING, sapuan frasa janji | ✅ | `700b84b` — PANDUAN-PENGGUNA §10.4 "Lima tab" + kartu preset + kartu registri + butir e "Tab Folder terpantau" + paragraf "Yang tidak otomatis"; ADMINISTRATOR §5 "sembilan perintah", baris `fin:bank-inbox`, §5.12 tiga butir yang tidak ada, **§5.13 runbook** (jalur, `install -d … www-data` hak BACA, sub-folder per kode, tidak dipindah — pemilik yang membersihkan, notifikasi, cara membaca tanpa terminal, yang sengaja tidak dilakukan); CONVENTIONS §2 (001502/001503 DIPAKAI) + §40; ONBOARDING finance/admin; `.env.example` `BANK_INBOX_PATH`; ROADMAP §5 catatan P-3c. Sapuan frasa janji dipaku TERBATAS pada berkas paket (`BankInboxTest`): `otomatis dari bank`, `langsung dari bank`, `terhubung ke bank`, `penjadwal aktif/berjalan`, `diambil dari bank` = **0** |
| 4 | `SHELL_VERSION` 10 → 11 | ✅ | `d987037` — `bankrecon.js` berubah; daftar `SHELL` tidak bertambah (tab baru hidup di berkas yang sudah terdaftar); kabel SPA **32 uji** hijau |
| 5 | Harness S39 (desktop + ponsel) → `results-phase-3.json` BERDASARKAN KUNCI | ✅ | `6e4e49e` (+ `55f7996` fixture pint) — `[S39_folder_terpantau] ok 7605ms clicks=1` (**23 syarat**), `[S39_folder_terpantau_ponsel] ok 5174ms clicks=0` (**9 syarat**), `console_errors: []` keduanya; **12 kunci lama tetap, 2 ditambahkan** (12 → 14); 4 PNG. Run pertama S39 JATUH pada dua syarat — satu **cacat aplikasi** (§3.1, ditutup `69ec078`) dan satu pengukur harness yang menghitung sel tabel yang bisa digulir sebagai "terpotong" (§3.2) |
| 6 | `/app/` dimuat di Chromium, desktop + ponsel, setiap layar yang disentuh | ✅ | §7 — 6 rute × 2 viewport, 0 galat konsol, 0 galat HTTP, tanpa gulir samping, tab kelima terjangkau di ponsel; alur UI sungguhan: Hapus preset → pemetaan manual → Pratinjau hijau → Simpan sebagai preset → kartu "Kolom 4 'Debit'" → berkas judul bergeser → 422 tergambar → lonceng → "Buka dokumen" mendarat di tab Folder terpantau |
| 7 | Gerbang per-direktori dua driver + pint | ✅ | §8 |
| 8 | Laporan ini | ✅ | berkas ini; sapuan dokumentasi §13 |

---

## 2. Keputusan yang membuat paket ini

### (A) Satu preset per REKENING (kolom JSON), bukan tabel banyak-per-rekening, bukan "per bank"

Roadmap menulis "preset `parse_options` tersimpan per bank". Yang dibangun: **satu preset per
rekening** di `fin_bank_accounts.import_preset`. Alasannya: tata letak ekspor ditentukan oleh
**kanal** tempat rekening itu diunduh (KlikBCA Bisnis ≠ myBCA ≠ BCA API), dan satu rekening
perusahaan diekspor dari satu kanal oleh satu orang — jadi "per bank" sebenarnya "per rekening";
dua tata letak untuk satu rekening adalah dua rekening di layar, bukan dua preset. Sebuah tabel
banyak-per-rekening menambah entitas, layar CRUD, pemilih preset yang bisa salah pilih, dan
migrasi struktural — untuk kasus yang belum pernah ada. Bila suatu hari dibutuhkan, kolom JSON
ini dipindah ke tabel dengan satu migrasi data; bentuk isinya (`ImportPreset`) tidak berubah.
"Per bank" yang sesungguhnya adalah **registri preset bawaan** (`BankPresets`) — dan itu kosong
sampai berkas ekspor nyata ada (§10).

### (B) Periode dan saldo DITURUNKAN dari kolom saldo berkas — hanya untuk folder, hanya bila kolom saldo dipetakan

Di layar, operator mengetik periode/saldo dan tie-out membandingkan angka yang ia ketik dengan
mutasi; docblock `CsvStatementParser` sudah jujur bahwa itu lemah, dan kolom saldo-lah pemeriksa
yang independen. Di folder tidak ada operator. Pilihan: (1) menebak periode dari bulan berkas dan
saldo dari … tidak ada; (2) menolak semua CSV; (3) **menurunkan dari kolom saldo** — saldo awal =
saldo baris mutasi pertama − mutasi pertama, saldo akhir = saldo baris mutasi terakhir, periode =
tanggal min/max — lalu `parse()` tetap memeriksa saldo berjalan setiap baris dan tie-out. (3) dipakai
karena hasilnya adalah **aritmetika berkas sendiri**, bukan tebakan: preset tanpa kolom saldo →
`failed` "preset tanpa kolom saldo tidak bisa diimpor otomatis; impor lewat layar"; format tanggal
`dd/mm` tanpa tahun → `failed` dengan kalimatnya (tahun tidak ditebak dari bulan ini). Periode yang
diturunkan adalah rentang tanggal mutasi (mis. 2026-03-10 s/d 2026-03-15), bukan bulan kalender —
rantai periode per rekening tetap ditegakkan atasnya.

### (C) Ledger, bukan penanda di folder

Cara umum "pindahkan ke `processed/`" atau "tulis `.done`" ditolak: docblock
`BankStatementParseRequest` menetapkan "nothing in this application writes to disk", dan setiap
tulisan ke folder itu adalah permukaan baru yang bisa gagal (hak tulis, berkas setengah dipindah,
`rsync --delete` yang menyapu penanda). Ledger `fin_bank_inbox_files` berkunci `(relative_path,
sha256)` memberi idempotensi yang lebih kuat daripada penanda: berkas yang sama tidak diimpor dua
kali **walau dipindahkan atau diganti nama**, isi yang berubah di bawah nama lama adalah berkas
baru, dan yang membersihkan folder adalah pemilik, kapan pun. `failed`/`ignored` diperiksa ulang
tiap jam (rantai yang putus tersambung sesudah periode sebelumnya masuk); `imported`/`duplicate`
final. Berkas yang sudah diimpor lewat **layar** dikenali lewat `content_hash` service yang sama →
`duplicate`, bukan `failed`.

### (D) Izin

`PUT/DELETE import-preset` = **fin.update** (mengubah cara sebuah rekening dibaca bulan demi bulan
adalah perubahan master, bukan impor); `GET bank-inbox` = **fin.view**; `POST bank-inbox/run` =
**fin.create** (tombol itu menjalankan impor yang sama dengan `POST bank-statements`). Notifikasi
ke pemegang **fin.update** — orang yang merekonsiliasi.

### (E) Kalimat notifikasi

Gagal: judul tetap `Berkas rekening koran di folder terpantau gagal diimpor`, badan
`Berkas <jalur relatif> untuk rekening <kode> <nama>: <sebab>`, tautan `/bank-recon?tab=inbox`,
`renagAfterDays` 7, signature = **40 karakter pertama sha256** berkas (`core_notifications.document_code`
varchar(40) — lihat §3.5; dedupe judul + signature → **sekali per berkas**, dipaku tiga jam berturut). Berhasil: judul `Rekening koran BST/… diimpor dari folder terpantau`,
badan menyebut berkas relatif, rekening, kode, jumlah mutasi, tautan
`/bank-recon?tab=statements&account=…&statement=…`. Template `null` = generik, ditulis di kode dan
di sini: tidak ada template kanal luar untuk peristiwa ini. Jalur absolut server **tidak pernah**
masuk ledger/API/notifikasi (dipaku).

### (F) Yang sengaja TIDAK otomatis

Berkas tidak diambil dari bank atau dari server lain (tidak ada host-to-host, tidak ada SFTP/FTP —
KEPUTUSAN-INTEGRASI §10); folder tidak dibuat aplikasi; tata letak CSV tidak ditebak (preset
eksplisit); preset bawaan per bank tidak ditulis dari ingatan; berkas tidak dipindah/dihapus;
"terakhir diperiksa" bukan jadwal melainkan stempel yang benar-benar ditulis; hidup-matinya
penjadwal tidak diklaim layar ini (`core/health` P-0b yang berkata).

---

## 3. Temuan sendiri (Chromium dan harness — bukan oleh mutasi)

1. **`JsonResource` membuang kunci numerik `expected_header`** — harness S39 mengukur kartu preset
   di layar Impor: "Kolom 1 'Tanggal' · Kolom 2 'Keterangan' · **Kolom 3 'Debit'**" untuk preset yang
   memetakan debit pada kolom 4. Basis data menyimpan `{"0":…,"1":…,"3":"Debit"}` dengan benar, PUT
   memulangkannya benar; `BankAccountResource` (`JsonResource::filter()` → `array_values()` atas array
   berkunci numerik) memulangkan daftar tanpa indeks, dan SPA menomori ulang dari 1. Suite PHP hijau
   sepanjang itu — uji resource memeriksa `name` dan `mapping.balance_column`, tidak `expected_header`.
   **Ditutup** `69ec078`: bentuk diganti daftar `{index, cell}`; uji baru
   `test_the_header_indexes_survive_the_resource…` (GET show DAN daftar rekening) **merah (error)
   terhadap bentuk lama**, hijau sesudahnya; kalimat 422 tidak berubah.
2. **Pengukur "teks terpotong" versi viewport-saja menghitung sel tabel yang bisa digulir** — 13
   simpul di ponsel, semuanya di dalam `.table-wrap` (`overflow-x: auto`, pola baku aplikasi:
   teks tercapai dengan menggulir tabel). Pengukur S39 membedakan leluhur yang **bisa digulir**
   (batas = lebar gulirnya) dari yang **memotong** (`hidden`/`clip`, atau viewport). Aplikasi tidak
   berubah; hasil sesudahnya **0** di kedua tab.
3. **Pratinjau berkas yang sudah diimpor dari folder tidak menawarkan "Simpan sebagai preset"** —
   ditemukan saat browsercheck memakai contoh demo April yang barusan diimpor folder: penghalang
   "sudah diimpor sebagai BST/…" → `can_import` false → tombol tidak ada. Itu perilaku yang
   dirancang (tombol hanya pada pratinjau hijau); pemeriksaan memakai berkas Mei yang menyambung.
   Dicatat karena operator yang mencoba menyimpan preset dari berkas bulan lalu akan menemui hal
   yang sama — PANDUAN §10.4 menyebut "sesudah pratinjau CSV hijau".
5. **Signature notifikasi sha256 utuh (64) ditolak MySQL, ditelan `guard()`** — gerbang `erp_dryrun`:
   4 uji `BankInboxTest` merah ("actual size 0 matches expected size 1") sementara SQLite hijau.
   `core_notifications.document_code` adalah varchar(40); SQLite tidak menegakkan panjang, MySQL
   menolak baris, dan `NotificationService::guard()` mencatatnya ke log tanpa melempar — **nol
   notifikasi tanpa galat**, persis jenis kegagalan diam yang paket ini ada untuk mencegahnya.
   **Ditutup**: signature = 40 karakter pertama sha256 (`BankInboxService::signature`), dipaku
   panjangnya; kedua driver hijau (§8).
4. **`pint` memindahkan `use` ke bawah baris bootstrap fixture** dan fixture jatuh ("Class
   \"Kernel\" does not exist") — `Kernel::class` dibaca saat kompilasi sebelum import-nya. Blok `use`
   dipindah ke atas (`55f7996`); fixture s38 lama memakai FQCN inline sehingga tidak kena.

---

## 4. Mutasi — 21 dijalankan, **21 merah, 0 LOLOS HIJAU**; putaran verifikasi (§4b) 26 dijalankan, **25 merah, 1 LOLOS HIJAU → ditutup**

Setiap mutasi diterapkan pada kode yang **sudah di-commit**, ujinya dijalankan, lalu dikembalikan
dengan `git checkout` berkas itu (pohon bersih diperiksa sesudah setiap putaran).

| # | Mutasi | Uji yang merah |
|---|---|---|
| M1 | `BankPresets::describe()` percaya klaim tanpa berkas (`is_file` → `true`) | `BankPresetsTest` 1 gagal |
| M2 | pemetaan ikut walau belum diverifikasi | 1 gagal |
| M3 | README menggambar tata letak (`| Kolom 1 | Tanggal;Keterangan;Saldo |`) | 1 gagal |
| MP1 | `resolveMapping` mengabaikan header yang bergeser | `BankImportPresetTest` 3 gagal |
| MP2 | preset diterapkan tanpa `use_preset` | 1 gagal |
| MP3 | `savePreset` tanpa syarat tie-out | 1 gagal |
| MP4 | rute PUT preset `fin.create` | 1 gagal |
| MP5 | nomor kolom 0-based di kalimat 422 | 3 gagal |
| MP6 | `use_preset` membuang syarat periode/saldo di Request | 1 gagal |
| MP7 | `expected_header` mengingat SEMUA kolom, bukan yang dipetakan | 1 gagal |
| MI1 | salinan berganti nama diimpor lagi (`twin` diabaikan) | `BankInboxTest` 1 gagal |
| MI2 | baris `imported` diproses ulang tiap jam | 3 gagal |
| MI3 | notifikasi gagal tanpa signature/renag (berulang tiap jam) | 1 gagal |
| MI4 | preset tanpa kolom saldo tetap diimpor | 1 gagal |
| MI5 | saldo awal = saldo baris pertama (tanpa dikurangi mutasi) | 2 gagal |
| MI6 | jadwal `dailyAt('07:00')` bukan `hourly()` | 1 gagal |
| MI7 | folder belum ada → dibuat aplikasi (`@mkdir`) | 1 gagal |
| MI8 | rute `run` `fin.view` | 1 gagal |
| MI9 | jalur absolut di badan notifikasi | 1 gagal |
| MI10 | sub-folder asing dibaca (rekening pertama dipakai) | 2 gagal |
| MI11 | aplikasi menulis penanda `.imported` ke folder | 3 gagal |

Ditambah satu **cacat nyata** yang ditemukan Chromium (§3.1) dengan uji yang dibuktikan merah
terhadap kode lama — bukan mutasi, tetapi jenis bukti yang sama.

### 4b. Mutasi putaran verifikasi (atas `e14f87d`, skrip `p3c/repair/mutate.py`, berkas dikembalikan `git checkout` — pohon bersih sesudahnya)

Verifier membuktikan enam mutasi LOLOS HIJAU pada kode `61b3fcc` (M4 sapuan panduan, M6/M-b
`use_preset`, M-d symlink, M-d ubin jam sekarang, M-a `file.error`). Sesudah perbaikan, setiap paku
baru diuji lagi dengan mutasinya:

| # | Mutasi | Hasil |
|---|---|---|
| M1 | `record()` tidak menjaga baris `imported` (V-folder-1) | merah, 1 gagal |
| M2 | kunci cache dimatikan (`if (false)`) | merah |
| M3 | jadwal tanpa `withoutOverlapping()` | merah |
| M4 | "Berkas ini sudah diimpor" dari `import()` tetap `failed` | merah |
| M5 | baris > 2 MB dihash dari isi (dibaca) — V-folder-2 | merah: **phpunit mati** "Allowed memory size … exhausted (tried to allocate 536879136)" — persis kegagalan verifier, kini tertangkap uji |
| M6 | baris lama tidak di-`superseded` (V-folder-3) | merah, 2 gagal |
| M7 | berkas tak terbaca dikunci `sha256('')` | merah |
| M8 | `isSettled()` tanpa memeriksa rekening koran masih ada (V-folder-4) | merah |
| M9 | `duplicateSentence()` selalu "(lewat layar Impor)" (V-folder-5) | **LOLOS HIJAU** pada `e14f87d` — cabang "tanpa operator DAN tanpa baris ledger" belum dipaku; **ditutup `3f6c249`**, M9 diulang → merah |
| M9b | baris ledger diabaikan (twin lewat jalur) | merah, 2 gagal |
| M10 | penjaga symlink/realpath dimatikan (mutasi verifier M-d) | merah — dulu 25/25 hijau |
| M11 | ubin menghitung seluruh ledger (V-folder-7) | merah |
| M12 | kalimat "yang Anda pilih" di jalur folder (V-folder-8) | merah |
| M13a | Request tanpa pola kode (V-permukaan-3) | merah |
| M13b | sub-folder tak sah dilewati bisu (`continue`) | merah |
| M14 | `evidencePath()` selalu benar (V-preset-1) | merah |
| M15a–d | janji ditambahkan ke ONBOARDING finance / PANDUAN §10.4 / KEPUTUSAN §10 / ADMINISTRATOR §5.13 (mutasi verifier M4) | merah keempatnya — dulu hijau |
| M16a | `use_preset: true` tanpa syarat (mutasi verifier M6) | merah — dulu hijau |
| M16b | `use_preset: false` + preset digabung SPA (mutasi verifier M-b) | merah di paku PHP; **harness S39 merah pada 2 syarat** (badan permintaan, kalimat 422) |
| M17 | kalimat sebab disusun SPA (mutasi verifier M-a) | merah — dulu hanya harness yang merah |
| M18 | ubin "Terakhir diperiksa" = jam sekarang (mutasi verifier M-d) | merah di paku PHP; **harness S39 merah** `last_checked_tile_carries_the_sqlite_stamp_not_the_clock` |
| M19 | tab tidak menulis hash (V-permukaan-2) | merah di paku PHP; **harness S39 merah** pada `switching_tab_rewrites_the_hash` + `a_deeplink_to_inbox_after_switching_tabs_lands_on_the_inbox_tab` |

M16b + M18 + M19 diterapkan bersama pada `bankrecon.js` lalu S39 dijalankan atas salinan segar:
`GAGAL: last_checked_tile…, preview_with_preset_sends…, preview_with_preset_draws…,
switching_tab_rewrites_the_hash, a_deeplink_to_inbox…` — lima syarat, tepat yang dijaga ketiganya.

---

## 5. Permukaan — setiap aturan baru, diperiksa satu per satu

| Aturan | Request/Controller | Service/Command | Resource/API | Layar SPA | Notifikasi | README/PANDUAN | Harness |
|---|---|---|---|---|---|---|---|
| Preset bawaan hanya dari berkas ekspor nyata; hari ini tidak ada | — | ✅ `BankPresets::describe()` murni, `selectable` = terverifikasi ∧ pemetaan | ✅ `GET bank-statements/presets` (`presets`, `summary`) | ✅ kartu `.bank-preset` — `badge_label`/`verification`/`awaiting_file`/`demo_note` apa adanya; SPA tanpa literal «diverifikasi»/«ekspor nyata» (dipaku) | — | ✅ `docs/samples/bank/README.md` (tanpa nama kolom, dipaku), PANDUAN §10.4, CONVENTIONS §40 | ✅ S39: 4 blok, `selectable=false`, lencana + kalimat awal |
| Preset = pilihan eksplisit (`use_preset`) | ✅ `BankStatementParseRequest` (kolom tidak wajib, periode/saldo wajib) | ✅ `resolveMapping` hanya bila `usePreset` (MP2) | ✅ pratinjau `preset: {used, name}` | ✅ pemilih "Pemetaan kolom" hanya bila preset ada; bawaan `preset` | — | ✅ PANDUAN §10.4 | ✅ S39 `picker.value == 'preset'` |
| Header bergeser → 422 menyebut kolom (1-based, semua kolom) | ✅ controller menangkap `LogicException` → 422 | ✅ `ImportPreset::headerMismatches` sebelum parse | ✅ `message` literal | ✅ `errorState` menggambar kalimatnya (browsercheck) | ✅ badan notifikasi `failed` (folder) | ✅ PANDUAN §10.4 kutipan literal | ✅ S39 baris Gagal = kalimat literal |
| Preset tidak melonggarkan tie-out/rantai/identitas | — | ✅ `preview/import` tak berubah; dipaku 2 uji | ✅ | ✅ tombol Impor tetap mati pada penghalang | — | ✅ | ✅ (Mei menyambung April di browsercheck) |
| Simpan preset hanya dari pratinjau yang seimbang; MT940 tidak | ✅ `BankImportPresetRequest` csv saja | ✅ `savePreset` (MP3) | ✅ PUT memulangkan preset | ✅ tombol hanya di kartu pratinjau `can_import` | — | ✅ | ✅ browsercheck |
| Izin preset `fin.update`, ledger `fin.view`, run `fin.create` | ✅ rute (MP4, MI8) | — | ✅ 403 dipaku | ✅ tombol mengikuti `session.can` | ✅ penerima `fin.update` | ✅ PANDUAN §10.4, ADMINISTRATOR §5.13 | — |
| Folder hanya dibaca | — | ✅ tidak ada tulis/rename/unlink/mkdir (MI7, MI11; potret folder) | ✅ | ✅ kalimat "tidak dipindah…" | — | ✅ ADMINISTRATOR §5.13, KEPUTUSAN §10 | ✅ mtime/daftar folder sebelum = sesudah perintah |
| Ledger idempoten (sama/salinan/berubah) | — | ✅ `(relative_path, sha256)`, twin, `content_hash` (MI1, MI2) | ✅ `files[]` | ✅ tabel + ubin | ✅ sekali per berkas (MI3) | ✅ | ✅ Periksa sekarang → sqlite tidak bertambah |
| CSV dari folder: preset + kolom saldo; periode/saldo diturunkan | — | ✅ `deriveEndpoints` (MI4, MI5), `dd/mm` ditolak | ✅ `accounts[].preset.note` | ✅ "CSV & MT940" / "MT940 saja" + kalimat server | ✅ sebab di badan | ✅ | ✅ S39 kesiapan per rekening |
| Sub-folder asing / akar / tersembunyi / bersarang | — | ✅ `ignored` + kalimat; dilewati (MI10) | ✅ | ✅ | tidak dibunyikan | ✅ | ✅ S39 baris Diabaikan |
| Tidak ada jalur absolut keluar | — | ✅ relatif saja (MI9) | ✅ dipaku (`getContent()` tanpa root) | ✅ | ✅ dipaku | ✅ | ✅ `absolute_path_leaks: false` |
| Folder belum ada → diam, keluar 0 | — | ✅ command + service | ✅ `folder.note` | ✅ `.inbox-folder-note` dari server | tidak ada | ✅ ADMINISTRATOR §5.13 | (folder ada di S39; uji PHP memaku) |
| Terakhir diperiksa = stempel yang ditulis | — | ✅ `bank_inbox.checked_at` (INTERNAL_KEYS) | ✅ `last_checked_at` | ✅ ubin "stempel yang ditulis pemeriksaan, bukan jadwal" | — | ✅ | ✅ |
| Tidak mengklaim penjadwal hidup | — | — | — | ✅ kalimat "tidak dilaporkan layar ini" (dipaku sapuan) | — | ✅ | ✅ S39 |
| Jadwal `hourly()` | — | ✅ `FinanceServiceProvider` (MI6) | — | — | — | ✅ ADMINISTRATOR §5.1 | — |
| Sapuan frasa janji | — | ✅ | — | ✅ | — | ✅ README + ONBOARDING finance/admin utuh + irisan PANDUAN §10.4 / ADMINISTRATOR §5.13 / KEPUTUSAN §10 (M15a–d; V-preset-2) | — |
| Satu pemeriksaan pada satu waktu (V-folder-1) | ✅ `run` memulangkan `LOCKED_NOTE` | ✅ `Cache::lock` + `withoutOverlapping` + `record()` tidak menurunkan; "sudah diimpor" → `duplicate` | ✅ `summary.locked` | ✅ toast kalimat server | tidak ada notifikasi gagal palsu (dipaku) | ✅ ADMINISTRATOR §5.13, PANDUAN §10.4 | — |
| Berkas yang ditolak tidak pernah dibaca (V-folder-2/-3, V-permukaan-6) | — | ✅ ukuran sebelum `read()`, `pathKey`, symlink tanpa stat; try/Throwable per berkas | ✅ `size` 0 / `file_mtime` null untuk symlink | ✅ | ✅ satu signature per berkas | ✅ ADMINISTRATOR §5.13 | — |
| Rekening koran dihapus = berkas baru lagi (V-folder-4) | — | ✅ `isSettled()` | ✅ `statement_deleted` + kalimat | ✅ lencana "Rekening koran dihapus" (Chromium 8255) | — | ✅ PANDUAN §10.4 paragraf obat, ADMINISTRATOR §5.13 | — |
| Kalimat kanal dari fakta (V-folder-5) | — | ✅ `duplicateSentence()` (M9/M9b) | ✅ | ✅ | — | ✅ PANDUAN §10.4 | — |
| Ubin = pemeriksaan terakhir (V-folder-7) | — | ✅ `checked_at` = stempel | ✅ `counts`, `counts_note` | ✅ kalimat server di bawah ubin | — | ✅ | ✅ S39 `the_tiles_say_they_count_the_last_check` |
| Salah sub-folder (V-folder-8) | — | ✅ `unattended: true` | ✅ | ✅ | ✅ badan menyebut sub-folder | ✅ PANDUAN §10.4 | — |
| Kode rekening = nama sub-folder (V-permukaan-3) | ✅ Store/Update `regex` + pesan Indonesia | ✅ `BankAccount::CODE_PATTERN`; sub-folder tak sah → `ignored` | ✅ `accounts[].subfolder` | ✅ catatan merah kartu Kesiapan (Chromium 8255, desktop + ponsel) | — | ✅ ADMINISTRATOR §5.13, PANDUAN §10.4 | ✅ S39 `no_account_is_flagged…` |
| Tab menulis hash; tautan ke ?tab=inbox mendarat (V-permukaan-2) | — | — | — | ✅ `router.replacePath()`, `notifications.js` → `resolve()` bila hash sama | ✅ "Buka dokumen" mendarat (lonceng sungguhan, Chromium) | ✅ CONVENTIONS §40 | ✅ S39 dua syarat |

---

## 6. Perangkap yang disebut di perintah — apa yang terjadi pada masing-masing

| Perangkap | Keadaan |
|---|---|
| A. Mengarang tata letak bank | `docs/samples/bank/` hanya README (dipaku `scandir`); keempat `verified_against`/`mapping` null; `describe()` menurunkan klaim tanpa berkas dan menahan pemetaan; contoh demo = `demo_note`; README tanpa nama kolom (M3) |
| B. Sniffing | `use_preset` eksplisit (MP2); `expected_header` hanya kolom yang dipetakan (MP7); 422 menyebut kolom (MP1, MP5); tie-out/rantai/identitas tak tersentuh; satu jalur `resolveMapping` |
| C. Aplikasi menulis ke folder | tidak ada API tulis; potret folder; MI7, MI11; ledger yang menanggung idempotensi (MI1, MI2) |
| D. Spam notifikasi | signature sha256 + renag 7 hari (MI3); tiga jam berturut = 1 notifikasi; sukses sekali; template null tertulis |
| E. SFTP / host-to-host | tidak ada klien, tidak ada kredensial, tidak ada polling host jauh; KEPUTUSAN §10, ADMINISTRATOR §5.12/§5.13; sapuan frasa = 0 |
| F. Penjadwal | `hourly()` dipaku (MI6); folder belum ada → 0 diam (MI7); stempel yang ditulis; tidak ada klaim "aktif" |
| G. Izin/traversal | rute dipaku (MP4, MI8); `CODE_PATTERN` + `realpath` di bawah root; jalur relatif saja (MI9) |
| H. Permukaan | tabel §5, satu per satu |

---

## 7. Bukti peramban (pelajaran P1-I: rilis SPA belum terverifikasi sampai DIMUAT)

Chromium sungguhan (Playwright), `php -S 127.0.0.1:8251` dengan router
`vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`, `PHP_CLI_SERVER_WORKERS=1`,
env `DB_DATABASE=<salinan>` dan `BANK_INBOX_PATH=<folder sementara di scratchpad>`, atas **salinan**
`database/database.sqlite` (`migrate --force` pada salinan: 001093 P-3b, 001502, 001503); tabel `cache`
salinan dikosongkan; `database/database.sqlite` tidak disentuh; server dimatikan berdasarkan PID dari
`ss -ltnp 'sport = :8251'` (pid 10256 lalu 10609) — port terbukti tertutup.

**Harness S39** (`docs/bukti-uji/harness-playwright.py`; fixture
`docs/bukti-uji/fixtures/s39-preset.php` menyimpan preset BANK-BCA-OPS lewat
`BankStatementImportService::savePreset` atas contoh demo; harness meletakkan **empat berkas dari
luar aplikasi** — MT940 contoh demo untuk `BANK-MDR-PRJ/`, CSV contoh demo untuk `BANK-BCA-OPS/`,
CSV judul bergeser `BANK-BCA-OPS/mei-judul-bergeser.csv`, `BANK-XYZ/asing.sta` — lalu
`php artisan fin:bank-inbox` lewat subprocess):

```
fin:bank-inbox → "4 berkas: 2 diimpor, 1 gagal, 0 salinan, 1 diabaikan, 0 tidak berubah", exit 0,
                 mtime/daftar folder sebelum = sesudah
[S39_folder_terpantau] ok 7605ms clicks=1        23 syarat, console []
[S39_folder_terpantau_ponsel] ok 5174ms clicks=0  9 syarat, console []
```

Yang diukur **di layar** (bukan dari API): lima tab dan tab "Folder terpantau" aktif; ubin
Diimpor **2** · Gagal **1** · Salinan **0** · Diabaikan **1** · Terakhir diperiksa **12 Sep 2026 17.16**
(= stempel `bank_inbox.checked_at` di sqlite); kartu folder "dari luar aplikasi", "tidak dipindah",
"tidak dilaporkan layar ini"; kesiapan per rekening `BANK-BCA-OPS` **CSV & MT940** ("memetakan kolom
saldo…"), `BANK-MDR-PRJ` **MT940 saja** ("Tanpa preset…"); baris Diimpor bertombol `BST/2026/IX/0001`
dan `BST/2026/IX/0002`; baris Gagal dengan kalimat literal `Kolom 4 pada preset «BCA contoh demo
(S39)» diharapkan 'Debit', berkas berisi 'Mutasi'.`; baris Diabaikan `Sub-folder BANK-XYZ bukan kode
rekening bank yang aktif; berkas tidak dibaca.`; **Periksa sekarang** dari peramban → toast
`4 berkas diperiksa: 0 diimpor, 1 gagal, 0 salinan, 1 diabaikan.` dan sqlite **tidak bertambah**
(2 rekening koran, 4 baris ledger, **1** notifikasi gagal sebelum dan sesudah); API `files` = ledger
sqlite, `counts {2,1,0,1}`, `absolute_path_leaks: false`; tab Impor: empat `.bank-preset`
`data-selectable="false"` berlencana "Belum ada berkas ekspor nyata", kepala "0 dari 4 bank punya
berkas ekspor nyata", `awaiting_file` menyebut `docs/samples/bank/`, `demo_note` "contoh demo",
pemilih "Preset rekening ini: «BCA contoh demo (S39)»", kartu preset "Kolom 4 'Debit'". Ponsel
390×844: keempat baris tergambar, kalimat Gagal terbaca, tanpa gulir samping di kedua tab, **0**
simpul teks terpotong leluhur (pengukur §3.2). PNG: `s39-folder-terpantau.png`,
`s39-impor-preset.png`, `s39m-folder-terpantau.png`, `s39m-impor-preset.png`.

**Pemeriksaan pemuatan + alur UI** (skrip scratchpad `browsercheck.py`, tidak di-commit; JSON +
PNG di scratchpad): `admin@` × 2 viewport × 6 rute — `#/bank-recon` (Rekonsiliasi),
`?tab=overview`, `?tab=statements`, `?tab=import`, `?tab=inbox`, `#/dashboard` — `h1` dan tab aktif
yang benar pada semuanya, `scrolls_sideways: false` pada keduabelas pemuatan, `.tabs`
`overflow-x: auto` dan tab aktif **terjangkau** di ponsel (scrollWidth 601 > clientWidth 362,
`active_tab_reachable: true`), `console_errors: []`, `http_errors: []` kedua viewport. Alur UI
sungguhan di desktop: **Hapus preset** → konfirmasi → toast "Preset rekening dihapus.", pemilih
hilang → berkas Mei 2026 (menyambung April yang diimpor folder) dibaca `FileReader` → kolom
debit 4 / kredit 5 / saldo 6, periode 2026-05-01..31, saldo −232.795.000 → −233.045.000 →
**Pratinjau** "Seimbang", 0 penghalang, tombol "Simpan sebagai preset rekening ini" ada → nama
"BCA KlikBCA (peramban)" → toast `Preset «BCA KlikBCA (peramban)» disimpan untuk rekening
BANK-BCA-OPS.` → kartu `2 · Preset «BCA KlikBCA (peramban)»`, "Judul kolom yang diingat: Kolom 1
'Tanggal' · Kolom 2 'Keterangan' · **Kolom 4 'Debit'** · Kolom 5 'Kredit' · Kolom 6 'Saldo'" (§3.1
tertutup) → berkas judul bergeser dengan preset → `errorState` menggambar `Kolom 4 pada preset
«BCA KlikBCA (peramban)» diharapkan 'Debit', berkas berisi 'Mutasi'.` (satu baris
`Failed to load resource: 422` yang Chromium tulis sendiri untuk probe yang disengaja — satu-satunya
"galat konsol") → lonceng (3 pemberitahuan: dua "diimpor dari folder terpantau", satu "gagal
diimpor") → **Buka dokumen** pada yang gagal → `#/bank-recon?tab=inbox`, tab "Folder terpantau" aktif.

---

## 8. Gerbang (per-direktori, sesuai perintah — bukan suite penuh)

- **Saat kerja:** sesudah T3c.0 — `BankPresetsTest` 10 hijau; sesudah T3c.1 — `BankImportPresetTest` 17
  + `BankStatementImportTest`/`BankReconciliationTest`/`BankStatementMatchTest`/`BankBalancesApiTest` **84**
  hijau; sesudah T3c.2 — `BankInboxTest` 25 + `SettingApi`/`SettingOverrideEndToEnd`/`SettingValidation`/
  `SettingsCache`/`SchedulerHeartbeat`/`BankStatementImport`/`BankImportPreset`/`FiscalCalendar` **141** hijau;
  sesudah T3c.3 — pembaca docs (`SidebarNavWiring`, `Onboarding`, `RentVsOwn`, `ReorderRuleSchema`) **33** hijau;
  kabel SPA (`PwaServiceWorkerTest`, `NavRouteRegistryTest`, `SidebarNavWiringTest`, `LauncherWiringTest`)
  **32 uji / 612 asersi** hijau.
- **Ujung cabang sebelum putaran verifikasi (`61b3fcc`), SQLite (`:memory:`):** `tests/Feature/Finance` **964 uji / 5.039 asersi** hijau (2 mnt 22 dtk;
  di `55f7996` 964 / 5.038 — +1 asersi dari paku panjang signature); `tests/Feature/Core` **1.124 uji / 10.106
  asersi, 11 dilewati** hijau (di `55f7996`; perubahan sesudahnya — `BankInboxService`, `BankInboxTest`,
  CONVENTIONS — tidak menyentuh Core). Log `p3c/build/gate.log` dan `gate2.log` di scratchpad.
- **Ujung cabang sebelum putaran verifikasi, MySQL 8.0 `erp_dryrun` (SATU proses; 11 berkas yang disentuh/baru; kredensial dari env,
  `DB_DATABASE=erp_dryrun` menimpa `phpunit.mysql.xml`):** **208 uji / 1.995 asersi, hijau, 1 dilewati**
  (`BankPresetsTest`, `BankImportPresetTest`, `BankInboxTest`, `BankStatementImportTest`,
  `BankReconciliationTest`, `DjpFormatsTest`, `FiscalCalendarTest`, `SchedulerHeartbeatTest`,
  `SettingValidationTest`, `SettingApiTest`, `PwaServiceWorkerTest`). **Putaran pertama MySQL (di `55f7996`)
  merah 4 uji `BankInboxTest`** — signature sha256 64 karakter pada kolom varchar(40), ditelan `guard()`
  (§3.5); ditutup `711873a`, keduanya hijau sesudahnya.
- **Ujung cabang sesudah putaran verifikasi (`3f6c249`), SQLite:** `tests/Feature/Finance` **977 uji / 5.177
  asersi** hijau; `tests/Feature/Core` **1.124 uji / 10.106 asersi, 11 dilewati** hijau (log
  `p3c/repair/gate-finance-sqlite.txt`, `gate-core-sqlite.txt`). **MySQL `erp_dryrun`, SATU proses** atas
  empat berkas uji yang disentuh (`BankInboxTest`, `BankPresetsTest`, `BankImportPresetTest`,
  `BankStatementImportTest` — service-nya berubah): **105 uji / 616 asersi, hijau** (`gate-mysql.txt`).
  Uji berkas 512 MB jarang + `memory_limit` diturunkan berjalan di kedua driver.
- `vendor/bin/pint --test` bersih pada **setiap** berkas PHP yang disentuh
  (`git diff --name-only b4fb40b...HEAD | grep '\.php$' | xargs vendor/bin/pint --test` → passed); dua
  kegagalan pint lama tidak disentuh.
- **Gerbang rilis (sesi utama, suite PENUH dua driver dari worktree terisolasi, vendor disalin):**
  `a1130fe` — **GAGAL identik di kedua driver** (4.861 uji, 1 error:
  `DocumentFormatValidationTest` — `storage_path()` di `config/erp.php`, §12) → ditutup `036f02b`.
  **`036f02b` (ujung cabang): SQLite 4.922 uji / 33.932 asersi (11 dilewati, 14 mnt 08 dtk) hijau;
  MySQL `erp_dryrun` 4.922 / 33.938 (9 dilewati, 30 mnt 19 dtk) hijau.** main `b4fb40b` membawa
  4.849 → **+73 uji**. Log `p3c-gate-<sha>.log` di scratchpad sesi.

---

## 9. Keputusan pemilik

| # | Keputusan | Rekomendasi / yang dipakai kode sampai dijawab |
|---|---|---|
| A | **Preset per rekening** (kolom JSON, satu per rekening) vs tabel preset banyak-per-rekening vs "per bank" | **per rekening** — dipakai; alasan §2A. Ke tabel = satu migrasi data bila kasusnya muncul |
| B | **Preset bawaan BCA/Mandiri/BNI/BRI** | tidak ada sampai berkas ekspor nyata di `docs/samples/bank/` (§10) — pemilik yang mengunduh; pengembang mengisi `mapping` + `verified_against` per entri, uji "hari ini tidak satu pun" dirancang merah pada hari itu |
| C | **Periode/saldo CSV dari kolom saldo** untuk folder (dipakai) vs menolak semua CSV dari folder | dipakai; §2B — periode = rentang tanggal mutasi, bukan bulan kalender |
| D | **Ledger di basis data, folder tidak disentuh** (dipakai) vs memindah berkas ke `processed/` | dipakai; §2C — pembersihan folder milik pemilik |
| E | **Jalur bawaan** `storage/app/private/bank-inbox` (dikecualikan `rsync --delete`, ikut cadangan bersama lampiran) vs folder di luar direktori situs (`BANK_INBOX_PATH`) | bawaan dipakai bila `.env` kosong; pemilik boleh menunjuk tempat lain — hak baca `www-data` cukup |
| F | **Retensi berkas di folder** — berapa lama berkas yang sudah `imported` dibiarkan; siapa membersihkan | tidak diputuskan kode; ledger mengingat sha256 sehingga pembersihan kapan pun aman; runbook §5.13 |
| G | **`failed`/`ignored` diperiksa ulang tiap jam** (dipakai) vs hanya sekali | dipakai — rantai yang putus tersambung sesudah periode sebelumnya masuk; notifikasi tetap sekali (dedupe) |
| H | **Renag notifikasi gagal 7 hari** sesudah dibaca | dipakai; angka di `BankInboxService::RENAG_DAYS` |
| I | **Sakelar mematikan pemeriksaan per jam** dari Pengaturan | tidak dibangun — folder yang tidak ada/kosong sudah membuatnya diam; bila dibutuhkan: satu kunci `core_settings` + satu baris di command |
| J | **Pola kode rekening bank ditegakkan di Request** (V-permukaan-3): (a) `regex` huruf/angka/titik/strip/garis bawah pada `POST/PUT finance/bank-accounts` — kontrak API berubah untuk kode baru; (b) hanya menandai di kartu Kesiapan tanpa menolak | **(a) dipakai** + (b) untuk kode lama; kedua rekening produksi (`BANK-BCA-OPS`, `BANK-MDR-PRJ`) lolos pola; kode lama yang berspasi tidak diubah data-nya (maju-saja) — pemilik yang mengubah lewat layar bila ingin dibaca dari folder |
| K | **Pemeriksaan kedua saat kunci dipegang**: pulang dengan kalimat tanpa menunggu (dipakai) vs menunggu (`block()`) | dipakai — tombol layar tidak boleh menggantung sampai 15 menit; jam berikutnya mengulang sendiri |
| L | **Rekening koran hasil folder dihapus** → berkas diimpor ulang jam berikutnya (dipakai) vs ditandai "jangan impor lagi" | dipakai — obat pemetaan yang salah menuntut impor ulang; runbook menyuruh perbaiki preset dulu. Bila pemilik ingin "hapus = jangan lagi", satu status ledger + satu syarat di `isSettled()` |
| M | **Status `superseded`** untuk baris lama jalur yang isinya berganti (dipakai) vs menghapus barisnya | dipakai — sejarah tetap terbaca di tabel, ubin tidak menghitungnya |

## 10. Prasyarat pemilik — tidak satu pun ada di repo

1. **Berkas ekspor nyata per bank** ke `docs/samples/bank/` sesuai README di sana
   (`<bank>-<kanal>-<YYYY-MM-DD>.<ekstensi asli>`; satu bulan penuh; tidak disunting) — untuk
   BCA/Mandiri/BNI/BRI. Folder itu **hanya berisi README pada 12 Sep 2026** (dipaku).
2. Sesudah 1: pengembang mengisi `mapping` + `verified_against` di `BankPresets::entries()`,
   mencocokkan kolom demi kolom, memperbarui `BankPresetsTest::test_today_no_bank_has_a_real_export_file…`
   (dirancang merah pada hari itu), menulis uji pratinjau atas berkas itu — barulah preset bawaan
   bisa dipilih di layar dan dipakai job.
3. **Folder terpantau di produksi**: `install -d -o www-data -g www-data -m 0750
   /var/www/erp1.pi2.co.id/storage/app/private/bank-inbox` + sub-folder per kode rekening
   (ADMINISTRATOR §5.13). Sampai dibuat, `fin:bank-inbox` per jam berkata "Folder terpantau belum
   ada" dan keluar 0 — **tanpa galat, tanpa notifikasi** (itulah keadaan produksi sesudah deploy).
4. **Alat pengisi folder** (scp/rclone/salinan manual) dan siapa yang meletakkan berkas — di luar
   aplikasi; tidak ada kredensial bank di server.
5. **Preset per rekening** untuk setiap rekening yang CSV-nya akan dibaca dari folder — disimpan
   operator dari layar Impor sesudah pratinjau hijau, dengan **kolom saldo** dipetakan.
6. Keputusan §9 F (retensi) dan I (sakelar), bila dikehendaki.

## 11. Yang TIDAK dikerjakan (per butir)

- **Klien SFTP/FTP** — tidak; tidak ada `ext-ssh2` di produksi (`php -m` = 0), tidak ada dependensi.
- **Host-to-host bank** — tetap ditolak (ROADMAP-DEVIASI §0 batas 5; KEPUTUSAN §10).
- **Preset bawaan BCA/Mandiri/BNI/BRI dengan pemetaan** — tidak; menunggu berkas ekspor nyata (§10).
- **Sniffing tata letak CSV** — tidak, dengan sengaja.
- **Memindah/menghapus/menandai berkas di folder** — tidak (§2C).
- **Tabel preset banyak-per-rekening** — tidak (§2A).
- **Sakelar mematikan pemeriksaan** dari layar — tidak (§9 I).
- **Layar Rekening Bank (master)** tidak diubah: preset disimpan/dihapus dari tab Impor tempat
  pratinjaunya ada; resource-nya memulangkan `import_preset`.
- **Jadwal yang bisa disetel** — `hourly()` tetap konstanta kode (roadmap "per jam").
- **Notifikasi kanal luar bertemplate** untuk peristiwa ini — tidak; template `null` = generik.
- **Deploy dan merge** — dilarang untuk agen di alur kerja ini; langkah sesi utama sesudah gerbang.

## 12. Deviasi baru yang ditemukan

Gerbang rilis (§15.2):

- **`config/erp.php` harus bisa di-`require` TANPA aplikasi yang di-boot.** Paket ini mula-mula
  menulis `'path' => env('BANK_INBOX_PATH', storage_path('app/private/bank-inbox'))`; `storage_path()`
  memanggil `Container::getInstance()->storagePath()`, dan penyedia data STATIS
  `tests/Unit/Core/DocumentFormatValidationTest::shippedDocumentFormats()` me-require berkas itu apa
  adanya sebelum aplikasi ada. Akibatnya SELURUH suite gagal di KEDUA driver (4.861 uji, 1 error,
  pesan "Call to undefined method Illuminate\Container\Container::storagePath()") — dan tidak satu
  pun gerbang per-direktori paket ini melihatnya, karena `tests/Unit` tidak ikut. Bawaannya kini
  diselesaikan di `BankInboxService::path()` (`DEFAULT_PATH`), config memulangkan `''`, dan
  kontraknya dipaku lewat subproses di `BankInboxTest` (CONVENTIONS §40).

Putaran verifikasi (§15):

- **Stempel `checked_at` bergranularitas detik**: ubin "pemeriksaan terakhir" menyamakan baris lewat
  `checked_at` = stempel, jadi dua pemeriksaan yang jatuh pada DETIK yang sama berbagi stempel dan
  ubinnya menghitung keduanya. Bukan "hanya mungkin di uji" seperti tertulis sebelumnya (dikoreksi
  putaran penutup): dua klik **Periksa sekarang** dalam satu detik cukup — yang dibutuhkan hanyalah
  perubahan isi folder di antara keduanya agar angkanya terlihat lain, dan itu sebabnya dampaknya
  tetap nihil dalam praktik (pemeriksaan terjadwal per jam, dan kuncinya menolak yang kedua selama
  yang pertama berjalan). Uji memakai `travel(1)->hours()`; dicatat di sini, tidak diubah.
- **Chromium mencatat 422 yang dirancang sebagai galat konsol** ("Failed to load resource"): S39 memisahkan
  galat dari dua pratinjau yang sengaja ditolak (pola S23 `stub_errors`) dan menuntut hanya pesan 422.
- **`'\n'` di dalam string JS yang dibungkus string Python non-raw** menjadi baris baru sungguhan →
  `Page.evaluate: SyntaxError`; harness memakai `String.fromCharCode(10)`.
- **Mockery partial mock atas kelas dengan konstruktor promoted readonly**: `partialMock()` Laravel tidak
  memanggil konstruktor → properti tidak terinisialisasi; dipakai `Mockery::mock(Class, [deps])->makePartial()`.
- **Pembaca berkas diprotected-kan** (`BankInboxService::read()`) supaya kasus hak akses bisa diuji di
  proses root — satu seam, dipakai produksi, bukan kait uji.
- **`SHELL_VERSION` tidak dinaikkan lagi** (tetap 11): 11 belum pernah dirilis (produksi masih 10), jadi
  cangkang lama sudah diumumkan usang oleh kenaikan `d987037`.

- `JsonResource` menjalankan `array_values()` atas array berkunci numerik — bentuk "peta indeks →
  nilai" **tidak selamat** melewati resource mana pun di aplikasi ini. **Ditutup** untuk
  `expected_header` (`69ec078`); pola umumnya dicatat di CONVENTIONS §40 dan docblock `ImportPreset`.
- Pengukur "teks terpotong" harness viewport-saja salah pada tabel `.table-wrap` yang bisa digulir
  (S38 mengukur kartu, bukan tabel). **Ditutup** di pengukur S39 (§3.2).
- Tombol "Simpan sebagai preset" tidak muncul untuk berkas yang sudah diimpor (pratinjau merah
  oleh penghalang ganda) — perilaku yang dirancang; PANDUAN menyebut syarat "pratinjau hijau" (§3.3).
- `pint` memindahkan `use` fixture ke bawah pemakaian `Kernel::class` — fixture jatuh. **Ditutup**
  (`55f7996`); fixture berikutnya: `use` di atas bootstrap.
- Layar SPA menampilkan `.stat .label` di-uppercase CSS — harness memakai `textContent` (jebakan
  S37/S38, dihindari sejak awal di S39).
- Kolom `core_notifications.document_code` varchar(40) lebih pendek dari sha256 — kegagalan tulisnya
  DIAM (`guard()`), dan hanya MySQL yang menunjukkannya. **Ditutup** untuk signature ini (§3.5); pola
  "guard() menelan galat skema" dicatat: uji notifikasi harus dijalankan di MySQL juga.
- Ubin "Terakhir diperiksa" membaca stempel `core_settings` — kunci internal `bank_inbox.checked_at`
  harus terdaftar di `SettingService::INTERNAL_KEYS` (Core), kalau tidak `invalidOverrides()` menandai
  barisnya "not editable". Dicatat di CONVENTIONS §40: kunci internal modul fitur didaftarkan KUNCI-nya
  saja di Core.

## 13. Sapuan dokumentasi (CONVENTIONS §35, `grep -rn … | wc -l` di ujung cabang)

- `grep -rn "Folder terpantau\|folder terpantau" docs/ --include=*.md | grep -v LAPORAN-PAKET-HM-P-3c | wc -l`
  — **21** baris di luar laporan ini (PANDUAN-PENGGUNA §10.4, PANDUAN-ADMINISTRATOR §5.1/§5.12/§5.13,
  CONVENTIONS §40, KEPUTUSAN-INTEGRASI §10, ONBOARDING finance/admin, `docs/samples/bank/README.md`,
  ROADMAP §5) — semuanya dibaca.
- `grep -rn "BELUM ADA BERKAS EKSPOR NYATA" docs/ --include=*.md | grep -v LAPORAN-PAKET-HM-P-3c | wc -l` — **4**
  (README samples/bank, CONVENTIONS §40, ROADMAP §5, PANDUAN §10.4 memakai lencana "Belum ada berkas
  ekspor nyata").
- `grep -c "^Empat tab" docs/PANDUAN-PENGGUNA.md` — **0** (§10.4 kini "Lima tab").
- `grep -rn "Simpan sebagai preset rekening ini" docs/ --include=*.md | grep -v LAPORAN-PAKET-HM-P-3c | wc -l` — **3**
  (PANDUAN §10.4, ONBOARDING finance, README samples/bank).
- `grep -rn "5\.13" docs/ Modules/Finance config/erp.php | grep -v LAPORAN-PAKET-HM-P-3c | wc -l` — **14**
  rujukan ke runbook (kode, config, KEPUTUSAN, PANDUAN, ONBOARDING, CONVENTIONS) — bagian §5.13 ADA di
  PANDUAN-ADMINISTRATOR.
- `grep -rln "host-to-host" docs/ --include=*.md | wc -l` — **8** berkas; penolakan lama tidak disunting
  (KEPUTUSAN §10 merujuk tempatnya).
- Berkas yang disapu: `docs/samples/bank/README.md` (baru), `docs/KEPUTUSAN-INTEGRASI.md` §10 (baru),
  `docs/CONVENTIONS.md` (§2 tabel, §40 baru), `docs/PANDUAN-PENGGUNA.md` §10.4,
  `docs/PANDUAN-ADMINISTRATOR.md` (§5 judul, §5.1, §5.12, §5.13 baru), `docs/ONBOARDING/finance.md`,
  `docs/ONBOARDING/admin.md`, `.env.example`, `docs/ROADMAP-HASHMICRO.md` §5. Tidak ada perubahan di
  `bootstrap/*`, `composer.json`, `routes/*` akar, `DatabaseSeeder`.

## 14. Commit (urut lama → baru)

```
df99244  T3c.0  docs/samples/bank/README.md, KEPUTUSAN-INTEGRASI §10, BankPresets, BankPresetsTest 10
9357e94  T3c.1  migrasi 001502, ImportPreset, resolveMapping/savePreset/deletePreset, use_preset, rute preset + registri,
                resource, layar Impor (pemilih/kartu/simpan/registri), BankImportPresetTest 17
953288b  T3c.2  config bank_inbox, migrasi 001503, BankInboxFile, deriveEndpoints, BankInboxService, fin:bank-inbox hourly,
                GET/POST bank-inbox, tab Folder terpantau, INTERNAL_KEYS bank_inbox.checked_at, BankInboxTest 25
700b84b  T3c.3  PANDUAN §10.4, ADMINISTRATOR §5.1/§5.12/§5.13, CONVENTIONS §2 + §40, ONBOARDING, .env.example, ROADMAP §5
d987037  SHELL_VERSION 10 → 11
69ec078  temuan Chromium: expected_header daftar {index, cell} (JsonResource membuang kunci numerik), +1 uji merah-dulu
6e4e49e  bukti: harness S39 + S39m, fixture s39-preset.php, results-phase-3.json berdasarkan kunci (12 → 14), 4 PNG
55f7996  bukti: fixture — use di atas bootstrap (pint-bersih dan tetap jalan)
711873a  gerbang MySQL: signature notifikasi 40 karakter pertama sha256 (document_code varchar(40); guard() menelan)
61b3fcc  laporan ini (§0–§14)
2d9c311  putaran verifikasi: V-preset-1 (evidencePath), V-preset-3 (ONBOARDING fin.update), V-preset-4/V-permukaan-1 paku literal
6ea6bbf  putaran verifikasi: V-folder-1…8, V-permukaan-2/-3/-4/-5/-6, V-preset-2 — service, model, Request, provider, SPA, dokumen
e14f87d  putaran verifikasi (bukti): harness S39 32 syarat + S39m 10, results-phase-3.json berdasarkan kunci, 5 PNG
3f6c249  putaran verifikasi: paku cabang V-folder-5 yang mutasi M9 lolos hijau
(commit ini)  §1/§4b/§5/§8/§9/§12/§14/§15 laporan ini
```

Skema: **dua migrasi** — `2026_09_12_001502_add_import_preset_to_fin_bank_accounts_table.php` (JSON
nullable) dan `2026_09_12_001503_create_fin_bank_inbox_files_table.php` (tabel ledger baru); tanpa
backfill, tanpa `constrained()` lintas modul. `git diff --stat b4fb40b...HEAD` sebelum laporan ini: **40 files changed, 4420 insertions(+), 45 deletions(-)**
(18 ditambah + 22 diubah). **Tidak ada** dependensi baru.

---

## 15. Putaran verifikasi

Temuan verifier (18) atas `61b3fcc`, ditutup 12–13 Sep 2026. Setiap temuan direproduksi dulu
(perintah verifier atau uji merah-dulu), setiap paku dibuktikan membedakan dengan mutasi (§4b).
Tidak ada temuan yang ditolak. Reproduksi yang dicatat: `describe()` atas contoh demo →
`{"verified":true,"badge":"Diverifikasi 2026-09-12"}` dan README.md → `"Diverifikasi x"`;
validator `code` menerima `'BCA OPS'`; `fin:bank-inbox` atas salinan (`p3c/repair/repro.sqlite`):
`feb.sta` impor folder dijawab "(lewat layar Impor)", `tautan.csv → /etc/hostname` mendapat sha256
isi hostname + size 11, `BCA OPS/y.sta` tanpa baris ledger, notifikasi "sedangkan yang Anda pilih",
ubin sepanjang masa `failed 2` vs pemeriksaan `1 gagal`; uji berkas 512 MB sebelum perbaikan →
`Allowed memory size … BankInboxService.php on line 421`.

| Id | Jenis | Gejala | Penutupan | Commit |
|---|---|---|---|---|
| V-preset-1 | DESIGN | contoh demo / README.md yang ditunjuk `verified_against` naik menjadi "Diverifikasi", selectable | `BankPresets::evidencePath()` + tanggal `YYYY-MM-DD`; yang lain diturunkan sambil menyebut jalurnya; uji README.md dibalik, uji positif memakai berkas berpola sementara | `2d9c311` |
| V-preset-2 | TEST-GAP | sapuan janji tidak menyentuh panduan yang KEPUTUSAN §10 klaim terpaku | ONBOARDING finance/admin utuh + irisan §10.4 / §5.13 / §10 (irisan kosong = merah); KEPUTUSAN memakai «guillemet» | `6ea6bbf` |
| V-preset-3 | DOCS | ONBOARDING: "finance-manager" padahal peran itu 403 | "fin.update; dalam data demo peran `finance`, bukan `finance-manager`" | `2d9c311` |
| V-preset-4 | TEST-GAP | `use_preset: true` tanpa syarat lolos semua paku | paku literal `use_preset: usingPreset(),` + baris mapping + `PER_FILE_KEYS`; S39 pemilih manual → `use_preset:false`, tanpa lencana preset | `2d9c311`, `e14f87d` |
| V-folder-1 | BUG | dua pemeriksaan bersamaan: baris `imported` ditimpa `failed`, notifikasi gagal palsu | `Cache::lock` + `withoutOverlapping` + `record()` tidak menurunkan + "sudah diimpor" → `duplicate`; 4 uji | `6ea6bbf` |
| V-folder-2 | BUG | berkas > 2 MB / symlink dibaca seluruhnya → 500 / OOM, pemeriksaan berhenti | ukuran sebelum `read()`, `pathKey`, try/Throwable per berkas; uji 512 MB + `memory_limit` | `6ea6bbf` |
| V-folder-3 | BUG | N berkas tak terbaca = 1 notifikasi; baris tidak sembuh | kunci `unreadable\|<jalur>`, status `superseded`; pembaca disuntik | `6ea6bbf` |
| V-folder-4 | DESIGN | rekening koran dihapus → berkas tidak pernah diimpor ulang; "sebagai ?" | `isSettled()` menuntut rekening koran ada; `statement_deleted` di API/layar; twin lewat `content_hash` + ledger; PANDUAN/ADMINISTRATOR | `6ea6bbf` |
| V-folder-5 | HONESTY | "(lewat layar Impor)" untuk impor folder | `duplicateSentence()` dari fakta; 3 uji (cabang ketiga `3f6c249`) | `6ea6bbf`, `3f6c249` |
| V-folder-6 | TEST-GAP | penjaga symlink tidak dipaku | uji symlink berkas + sub-folder; target tidak disentuh | `6ea6bbf` |
| V-folder-7 | UX | ubin sepanjang masa vs ringkasan pemeriksaan | `counts` = baris `checked_at` = stempel; `counts_note` | `6ea6bbf` |
| V-folder-8 | UX | notifikasi "sedangkan yang Anda pilih" | `preview/import(unattended: true)` → kalimat sub-folder | `6ea6bbf` |
| V-permukaan-1 | TEST-GAP | preset digabung SPA tanpa `use_preset` lolos; harness tidak mengirim pratinjau | paku literal + S39 pratinjau sungguhan (badan permintaan + kalimat 422) | `2d9c311`, `e14f87d` |
| V-permukaan-2 | UX | "Buka dokumen" mati bila hash sudah `?tab=inbox` | `router.replacePath()` dari `load()`; `notifications.js` → `resolve()`; Chromium lonceng sungguhan + S39 | `6ea6bbf`, `e14f87d` |
| V-permukaan-3 | BUG | kode berspasi: sub-folder dilewati bisu | `BankAccount::CODE_PATTERN` di Request + pemindai (`ignored` + kalimat) + kartu Kesiapan; keputusan J | `6ea6bbf` |
| V-permukaan-4 | TEST-GAP | ubin jam sekarang lolos | paku `fmt.dateTime(data.last_checked_at)` + `data-iso`; S39/S39m membandingkan dengan stempel sqlite | `6ea6bbf`, `e14f87d` |
| V-permukaan-5 | TEST-GAP | `file.error` tidak dipaku PHP | `text: file.error \|\| ''` dipaku | `6ea6bbf` |
| V-permukaan-6 | HONESTY | "tidak dibaca" tetapi dihash + diukur | symlink: kunci jalur, size 0, mtime null, target tidak di-stat | `6ea6bbf` |

**Bukti peramban putaran ini** (`php -S 127.0.0.1:8255`, salinan sqlite `p3c/repair/harness.sqlite`,
`BANK_INBOX_PATH` scratchpad, reset per putaran, server dimatikan berdasarkan PID dari `ss`): S39
`ok 14825ms clicks=4` (32 syarat), S39m `ok 3848ms` (10 syarat), 0 galat konsol di luar dua 422 yang
dirancang; `p3c/repair/edgecheck.py` (Chromium 1440×900 + 390×844): rekening `BCA OPS` (disisipkan
langsung) → catatan merah kartu Kesiapan, baris `BCA OPS/y.sta` Diabaikan dengan kalimatnya; rekening
koran Mandiri dihapus + berkasnya diambil → baris "Rekening koran dihapus"; isi `mei-judul-bergeser.csv`
diganti → baris lama "Digantikan"; lonceng sungguhan → "Buka dokumen" pada notifikasi gagal sesudah
pindah ke tab Rekonsiliasi **mendarat di Folder terpantau** — juga ketika hash dipaksa sudah sama
(`history.replaceState`) — 0 galat konsol, 0 simpul teks terpotong, tanpa gulir samping di kedua viewport.

### 15.1 Putaran penutup (verifier penutup atas `13d217a`: 5 temuan, semuanya ditutup sesi utama)

Verifier penutup menjalankan ulang setiap perintah verifier putaran pertama di ujung cabang (18/18
tertutup, direproduksi), 31 mutasi (27 merah), harness dan Chromium sendiri — lalu menambah lima
temuan sendiri dan memberi putusan **"BELUM SIAP"** atas yang pertama.

| ID | Jenis | Temuan (gejala) | Penutupan | Bukti mutasi |
|---|---|---|---|---|
| V-close-1 | BUG | Folder ATAU sub-folder yang ADA tetapi tidak bisa dibaca proses aplikasi → pemeriksaan diam: "0 berkas", stempel bergerak tiap jam, tanpa baris ledger, tanpa notifikasi, kartu Kesiapan berkata sub-folder sah. Ini kegagalan diam yang §0 justru menjanjikan tidak ada | `entries()` memulangkan `false` (bukan `[]`) saat `scandir` gagal — akar → `folder_readable: false` + `FOLDER_UNREADABLE_NOTE` di layar dan keluaran perintah; satu sub-folder → satu baris `failed` atas sub-foldernya + satu notifikasi (dedupe jalur) + `subfolder.readable` di kartu Kesiapan, sub-folder lain tetap diperiksa; runbook §5.13 menulis hak akses yang dibutuhkan dan obatnya. Suite berjalan sebagai root (menembus `chmod 000`) → diuji lewat seam `protected entries()`, DAN metode produksinya dipaku langsung atas direktori yang benar-benar gagal di-`scandir` | `entries()` → `[]` (perilaku sebelum perbaikan): uji dasar MERAH |
| V-close-2 | HONESTY | Tautan simbolik sebagai SUB-FOLDER tetap diikuti untuk daftar isinya: nama berkas di folder tujuan (di mana pun di server) masuk ledger dan dipulangkan API kepada setiap pemegang `fin.view` — sementara kalimatnya mengaku tidak mengikuti tautan | `is_link() && is_dir()` mendahului `is_dir()`: satu baris `ignored` untuk tautannya, `scandir` target tidak pernah dipanggil; uji memaku bahwa nama berkas di folder tujuan TIDAK ada di ledger; runbook §5.13 menulisnya | cabang dihapus → uji symlink MERAH |
| V-close-3 | TEST-GAP | `mapping.period_start` wajib bersama `use_preset` tidak dipaku (uji mengirimkannya lalu memaku tiga kunci lain); tanpa aturan itu jalurnya 500 `Undefined array key`, bukan 422 | permintaan TANPA `period_start` → `assertJsonValidationErrors(['mapping.period_start'])` | aturan → `nullable`: MERAH |
| V-close-4 | TEST-GAP | Cabang `statement_deleted` untuk baris **duplicate** tidak dipaku (hanya `imported`) | uji: salinan berganti nama + rekening koran dihapus → kedua baris berlabel "Rekening koran dihapus" | daftar dibatasi ke `IMPORTED`: MERAH |
| V-close-5 | TEST-GAP | Perbandingan judul kolom tidak dipaku untuk dua tepi: beda HURUF saja, dan berkas ber-baris-judul lebih dari satu (`skip_rows ≥ 2`) | dua uji: `'DEBIT'` vs `'Debit'` ditolak menyebut kolom; `skip_rows 2` membandingkan baris judul KOLOM (baris 1 boleh berubah tiap unduhan) | `strcasecmp`: MERAH; baris judul dipaku ke baris 1: MERAH |

**Bukti sesudah penutupan** (pohon utama, ujung cabang): `tests/Feature/Finance` **983 uji / 5.233
asersi** hijau (977 + 6 uji baru), `tests/Feature/Core` **1.113 / 10.106** (11 dilewati) hijau, empat
kelas kabel SPA **32 / 612** hijau (dijalankan LEWAT JALUR berkasnya — `--filter` menyeret
`tests/Unit/Core/DocumentFormatValidationTest` yang penyedia datanya memang gagal di luar suite penuh,
cacat lama yang tidak disentuh paket ini dan hijau di gerbang penuh), `pint --test` bersih pada berkas
yang disentuh. Harness dijalankan ulang atas salinan DB karena layarnya berubah: S39 **ok 15003 ms
(32 syarat)**, S39m **ok 3974 ms (10 syarat)**, `console_errors []`, `folder_untouched_by_command
true`, perintah `4 berkas: 2 diimpor, 1 gagal, 0 salinan, 1 diabaikan`; `results-phase-3.json`
digabung BERDASARKAN KUNCI (14 kunci tetap), 5 PNG diperbarui.
