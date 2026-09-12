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

## 4. Mutasi — 21 dijalankan, **21 merah, 0 LOLOS HIJAU**

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
| Sapuan frasa janji | — | ✅ | — | ✅ | — | ✅ README | — |

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
- **Ujung cabang, SQLite (`:memory:`):** `tests/Feature/Finance` **964 uji / 5.039 asersi** hijau (2 mnt 22 dtk;
  di `55f7996` 964 / 5.038 — +1 asersi dari paku panjang signature); `tests/Feature/Core` **1.124 uji / 10.106
  asersi, 11 dilewati** hijau (di `55f7996`; perubahan sesudahnya — `BankInboxService`, `BankInboxTest`,
  CONVENTIONS — tidak menyentuh Core). Log `p3c/build/gate.log` dan `gate2.log` di scratchpad.
- **Ujung cabang, MySQL 8.0 `erp_dryrun` (SATU proses; 11 berkas yang disentuh/baru; kredensial dari env,
  `DB_DATABASE=erp_dryrun` menimpa `phpunit.mysql.xml`):** **208 uji / 1.995 asersi, hijau, 1 dilewati**
  (`BankPresetsTest`, `BankImportPresetTest`, `BankInboxTest`, `BankStatementImportTest`,
  `BankReconciliationTest`, `DjpFormatsTest`, `FiscalCalendarTest`, `SchedulerHeartbeatTest`,
  `SettingValidationTest`, `SettingApiTest`, `PwaServiceWorkerTest`). **Putaran pertama MySQL (di `55f7996`)
  merah 4 uji `BankInboxTest`** — signature sha256 64 karakter pada kolom varchar(40), ditelan `guard()`
  (§3.5); ditutup `711873a`, keduanya hijau sesudahnya.
- `vendor/bin/pint --test` bersih pada **setiap** berkas PHP yang disentuh
  (`git diff --name-only b4fb40b...HEAD | grep '\.php$' | xargs vendor/bin/pint --test` → passed); dua
  kegagalan pint lama tidak disentuh.
- Suite penuh dua driver = gerbang rilis sesi utama (§15), bukan bagian laporan ini.

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
(commit ini)  laporan ini
```

Skema: **dua migrasi** — `2026_09_12_001502_add_import_preset_to_fin_bank_accounts_table.php` (JSON
nullable) dan `2026_09_12_001503_create_fin_bank_inbox_files_table.php` (tabel ledger baru); tanpa
backfill, tanpa `constrained()` lintas modul. `git diff --stat b4fb40b...HEAD` sebelum laporan ini: **40 files changed, 4420 insertions(+), 45 deletions(-)**
(18 ditambah + 22 diubah). **Tidak ada** dependensi baru.

---

## 15. Putaran verifikasi

(diisi sesi utama)
