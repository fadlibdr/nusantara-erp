# Laporan Paket HM P-3d — API & webhook (token akses pribadi, ability yang ditegakkan, webhook keluar bertanda tangan, OpenAPI tangan)

**Cabang:** `feat/phase3-p3d` (dari `main` 8438066 = merge P-3c) · **Tanggal:** 12 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 3 / P-3d (8 hari-orang), tugas T3d.0–T3d.4
**Status:** selesai di cabang, **satu putaran verifikasi ditutup** (§15) — **belum di-merge, belum
di-deploy** (keduanya langkah pemilik; deploy dilarang untuk agen di alur kerja ini).

---

## 0. Satu kalimat

Paket ini seluruhnya tentang **janji kepada sistem lain**, dan keputusan besarnya adalah **tidak
menjual satu pun kendali yang tidak ada**: sebelum commit pertama, setiap token di aplikasi ini
membawa `["*"]` dan **tidak ada satu pun `tokenCan()` di seluruh kode** (grep = 0), jadi sebuah layar
"abilities = subset izin" di atas dasar itu akan membiarkan token bernama "hanya baca proyek"
**menyetujui pembayaran** — maka penegakannya dipasang lebih dulu, di **satu tempat yang dilewati
setiap pemeriksaan izin** (`App\Models\User::hasPermissionTo()`), dibuktikan dengan matriks 403 yang
menyebut ability yang kurang, dan **batasnya dikatakan apa adanya** di layar, panduan, dan dokumen
OpenAPI (diukur 12 Sep 2026: 218 dari 862 rute tidak dijaga izin apa pun dan tetap dijangkau token
terbatas — **angka itu diukur, bukan dipaku**, §12.4).
Kedaluwarsa Sanctum yang **punya dua kunci** — plafon global 720 menit yang diam-diam membunuh
`expires_at` per token — diperbaiki tanpa menyentuh `config/sanctum.php` dan **tanpa backfill**,
sehingga tidak satu pun baris produksi menjadi abadi. Webhook keluar menandatangani **byte yang
persis dikirim**, menunggu **commit** dua lapis sebelum berangkat, menolak alamat internal **dua
kali** (menyimpan dan mengirim) dengan redirect yang tidak diikuti, dan mencatat `sent` **hanya**
pada 2xx. OpenAPI dua puluh endpoint ditulis tangan dan dijaga uji anti-drift yang **dibuktikan bisa
memerah pada jalur, metode, izin, dan autentikasi**.

Putaran verifikasi menutup **16 temuan** (§15): dua di antaranya SECURITY — alamat internal dalam
bentuk IPv6 bertopeng IPv4 (`https://[::ffff:169.254.169.254]/`) yang lolos **kedua** pintu SSRF dan
POST bertanda tangannya benar-benar berangkat, dan rahasia langganan yang digemakan penerima lalu
mendarat di kolom log yang dibaca setiap pemegang `core.update`. Satu BUG membuat 3 dari 5 percobaan
webhook **mustahil diterima** penerima yang memasang resep yang kita terbitkan sendiri. Tidak ada
temuan yang ditolak.

Tidak ada dependensi baru (`git diff 8438066...HEAD -- composer.json composer.lock package.json` =
**0 baris**); **tidak ada sentuhan** pada `bootstrap/*`, `routes/*` akar, atau `DatabaseSeeder`
(`git diff --stat 8438066...HEAD -- bootstrap/ routes/ database/seeders/DatabaseSeeder.php` = **0
baris**); **dua migrasi** aditif nullable tanpa backfill (Iam 000253, Core 001803 — CONVENTIONS §2
belum perlu diperbarui untuk 001803 karena blok Core 001800–001899 sudah terdaftar; 000253 memakai
blok pertama Iam yang masih longgar).

---

## 1. Tugas → status → bukti

> Setiap angka di laporan ini keluar dari perintah yang dijalankan. Angka gerbang ada di §8.

| # | Tugas | Status | Bukti (commit + angka terukur) |
|---|---|---|---|
| T3d.0 | Ukur dulu: rute api, 20 endpoint yang akan didokumentasikan, keadaan kedaluwarsa Sanctum hari ini | ✅ | §0 tabel di bawah + `5fd2b21` — `ApiTokenExpiryTest` membuktikan dengan `travel()` bahwa token pribadi "berlaku setahun" **mati 13 jam** sebelum paket ini |
| T3d.1a | Kedaluwarsa: migrasi Iam 000253 (`kind`), `ApiToken`, `Sanctum::authenticateAccessTokensUsing()` | ✅ | `5fd2b21` — `ApiTokenExpiryTest` **8 uji / 9 asersi**, merah dulu (5 error + 1 gagal), 3 mutasi merah (§4) |
| T3d.1b | Token CRUD: `iam/me/api-tokens`, ability ⊆ izin, ≤ 365 hari, tampil sekali, `SessionOnly` | ✅ | `dc062e1` — `ApiTokenEndpointTest` **11 uji / 53 asersi** |
| T3d.2 | Penegakan ability di satu tempat + matriks + batas laju 300/menit | ✅ | `377398c` + `675c10d` — `ApiTokenAbilityMatrixTest` **13 / 41**, `ApprovalDelegationTokenScopeTest` **5 / 7**, `IntegrationRateLimitTest` **5 / 17**, `UngatedApiRouteCensusTest` **1 / 1**; 5 + 3 mutasi merah (§4) |
| T3d.3 | Webhook: migrasi Core 001803, model, service, job, listener, HMAC, SSRF, layar log | ✅ | `7900bd1` + `8a534d9` — `WebhookDeliveryTest` **10 / 50**, `WebhookGuardTest` **40 / 133**, `WebhookEndpointTest` **10 / 61**, `WebhookSignatureTest` **4 / 48**; 5 + 5 mutasi merah (§4) |
| T3d.4 | OpenAPI tangan 20 endpoint + anti-drift **empat** arah | ✅ | `80a9881` + `ba599ec` — `docs/api/openapi.json`, `OpenApiDriftTest` **8 / 197**; 5 + 4 mutasi merah (§4) |
| 5 | Layar Profil › Token API + Sistem › Webhook; `SHELL_VERSION` 11 → 12 | ✅ | `057a078` + `0fd26be` — `ApiTokenAndWebhookSpaTest` **7 / 59** |
| 6 | Dokumen: PANDUAN-PENGGUNA §20, ADMINISTRATOR §5.14, KEPUTUSAN-INTEGRASI §11, CONVENTIONS §41, ROADMAP, `.env.example` | ✅ | `aebe995` |
| 7 | Harness S40 + S40m → `results-phase-3.json` BERDASARKAN KUNCI | ✅ | `057a078` + `0fd26be` — **28 syarat** desktop + **8 syarat** ponsel, `console_errors: []` keduanya; 16 kunci tetap **16** |
| 8 | `/app/` dimuat di Chromium desktop + ponsel | ✅ | §7 — 12 rute × 2 viewport, 0 galat konsol, 0 jawaban ≥ 400, 0 gulir samping |
| 9 | Gerbang dua driver + `tests/Unit` + pint | ✅ | §8 |
| 10 | Laporan ini | ✅ | berkas ini |

### Angka T3d.0 (diukur, bukan dikutip)

| Yang diukur | Pada `main` 8438066 | Sesudah P-3d |
|---|---|---|
| Rute di bawah `api/` (metode × jalur, tanpa HEAD) | **852** | **862** |
| …dijaga sebuah `permission:` di rutenya | **637** | **644** |
| …tidak dijaga izin apa pun | **215** | **218** |
| Kemunculan middleware `permission:` | **639** | **646** |
| Rute TULIS tanpa gerbang izin | **29** | **31** |
| `tokenCan()` di seluruh kode | **0** | 0 (penegakannya bukan lewat `tokenCan()` — §2A) |

Roadmap menulis "793 rute tak terkurasi"; angka itu sudah basi dan diganti angka terukur di atas.

Angka-angka ini **diukur, tidak dipaku** (§12.4 — pelajaran 5). Yang dijaga uji hanyalah **daftar
literal rute TULIS tanpa gerbang izin** (`UngatedApiRouteCensusTest::UNGATED_WRITES`), karena rute
bergerbang baru di modul mana pun bukan urusan paket ini. Diukur ulang sesudah putaran verifikasi
dengan `Route::getRoutes()` (`scratchpad/p3d/repair/census.php`): **862 / 644 / 218 / 646 / 31** —
tidak berubah, putaran verifikasi tidak menambah satu rute pun.

---

## 2. Keputusan yang membuat paket ini

### (A) Penegakan ability di `User::hasPermissionTo()` — bukan `Gate::before`, bukan parameter rute

Setiap pemeriksaan izin di aplikasi ini bermuara pada satu metode: middleware rute spatie
(`canAny`), `Gate::before` yang **didaftarkan spatie** (`checkPermissionTo`),
`$request->user()->can()` di dalam controller, `hasAnyPermission()` di dalam service.
`Modules\Core\Support\TokenScope` menyempitkannya **sesudah** versi spatie memulangkan `true`, jadi
ia hanya pernah MENGURANGI.

Dua alternatif ditolak, dan keduanya karena alasan yang terukur:

* **Membaca parameter `permission:` rutenya.** 215 rute (29 di antaranya TULIS) tidak membawanya —
  `AttachmentController` menurunkan izin dari DOKUMEN-nya, `MasterDataController` dari resource yang
  disebut badan permintaan. Sebuah gerbang ability di sana akan membiarkan semuanya tanpa penjaga.
* **`Gate::before` sendiri.** Spatie mendaftarkan `Gate::before`-nya lewat
  `callAfterResolving(Gate::class)` **di dalam `register()`**, jadi ia selalu callback PERTAMA;
  `Gate` memulangkan hasil non-null pertama, dan callback spatie memulangkan `true` untuk izin yang
  dimiliki pengguna. Sebuah `before` kedua yang menolak **tidak akan pernah dipanggil** untuk kasus
  yang justru harus ditolaknya.

**Satu-satunya jalur pemberian yang tidak lewat `hasPermissionTo()` milik pemakainya** adalah
`Gate::before` delegasi persetujuan (F-1) — delegatnya memang TIDAK memegang izin itu, itulah guna
delegasi — dan ia memanggil `TokenScope::allows()` sendiri. Tanpa dua baris itu, sebuah token "hanya
baca keuangan" yang kebetulan dipegang seorang delegat bisa MENYETUJUI dokumen, dan tidak satu pun
uji ability akan melihatnya (`ApprovalDelegationTokenScopeTest`, mutasi M5).

### (B) Satu sumber untuk "token apa yang dipakai permintaan ini" — dan mengapa bukan dua

Token diingat dari peristiwa `Laravel\Sanctum\Events\TokenAuthenticated`, satu-satunya titik yang
dilewati setiap permintaan bearer. `currentAccessToken()` **sengaja tidak** dibaca sebagai sumber
kedua, dan harganya diukur: `Sanctum::actingAs($user)` — bentuk yang dipakai ratusan uji di
repositori ini — memasang **Mockery** dari model token dengan daftar ability **kosong**
(`actingAs($user, $abilities = [])`), sehingga `can()` memulangkan falsy untuk apa pun. Versi pertama
`TokenScope` membacanya dan **23 uji di `tests/Feature/Core` berubah menjadi 403** atas izin yang
penggunanya pegang penuh — kegagalan yang tidak punya padanan di produksi. Aturannya karena itu
sesempit yang bisa dikatakan: penyempitan hanya pernah datang dari **baris token yang benar-benar
diautentikasi Guard**.

### (C) Kedaluwarsa: plafon global hanya untuk token sesi (pilihan a), bukan `expiration = null`

`Sanctum\Guard::isValidAccessToken()` menuntut DUA syarat sekaligus, dan yang global membunuh yang
per-token. Pilihan (b) — menjadikan `expiration` null dan memberi token sesi `expires_at` sendiri —
ditolak: **setiap baris `personal_access_tokens` yang sudah ada di produksi tidak punya
`expires_at`** dan akan menjadi **abadi** sampai sebuah backfill mengejarnya. Itu regresi keamanan
yang dibayar di muka untuk kenyamanan satu baris konfigurasi.

Yang dipakai: `Sanctum::authenticateAccessTokensUsing()` yang menerapkan plafon 720 menit HANYA pada
token `kind = session` **dan `kind` NULL** (setiap baris sebelum paket ini), dan menilai token
`kind = personal` dari `expires_at`-nya sendiri. `config/sanctum.php` **tidak disentuh sama sekali**.
Token personal tanpa `expires_at` **ditolak** — bukan diterima selamanya karena plafonnya sudah tidak
berlaku baginya.

Masa berlaku dikirim sebagai **jumlah hari** (1–365), bukan tanggal: batas "satu tahun" yang bergeser
satu hari karena WIB lawan UTC adalah persis ketidakjelasan yang tidak boleh ada di sebuah kredensial.

### (D) Sebuah token tidak boleh mencetak token — `SessionOnly` pada tiga pintu

Rute `me/*` tidak dijaga izin apa pun, jadi ability tidak bisa mempersempitnya: **tidak ada izin di
sana untuk dipersempit**. Untuk sebagian besar rute itu (preferensi, onboarding) itu tepat. Tiga
lain adalah jalan memutar yang membatalkan seluruh gagasan token terbatas, dan ketiganya menuntut
sesi: `POST/DELETE me/api-tokens` (token "hanya baca" mencetak token kedua dengan SELURUH izin
pemiliknya — setiap pembatasan berumur satu permintaan), `PUT me/password` (kunci akun berpindah
tangan), `PUT me/phone` (alarm operasional diarahkan ke nomor lain). Dipasang dengan **nama kelas**
pada rutenya, jadi `bootstrap/app.php` tidak perlu disentuh.

### (E) Muatan webhook sengaja KURUS — penunjuk, bukan salinan dokumen

Yang dikirim: jenis dokumen, id, kode, status, aktor, catatan. **Tidak ada nilai rupiah.** Tiga
alasan, masing-masing cukup sendirian: (1) URL penerima milik orang lain, dan muatan gemuk adalah
salinan data keuangan perusahaan yang keluar setiap transisi; (2) penerima yang butuh detail
memanggil balik API dengan **tokennya sendiri**, tempat ability-nya berlaku — muatan gemuk
menyerahkan data lewat pintu belakang; (3) bentuk yang kurus adalah bentuk yang tidak berubah.
`version` naik hanya bila arti sebuah kolom berubah.

### (F) Langganan yang terus gagal DINONAKTIFKAN otomatis — dan mengatakannya

Ambangnya **20 kegagalan berturut-turut**. Alasannya: URL yang mati membakar lima percobaan
bertingkat untuk SETIAP transisi dokumen, berhari-hari, di pekerja antrean yang sama yang mengantre
e-mail dan WhatsApp; dengan backoff rumah, gangguan satu jam tidak akan pernah mencapai 20, dan satu
pengiriman berhasil mengembalikan hitungannya ke nol. Yang dinonaktifkan **mengatakan dirinya**:
`disabled_reason` yang menyebut berapa kali dan sebab terakhirnya (digambar layar apa adanya), satu
notifikasi ke pemegang `core.update`, dan tombol **Aktifkan lagi**. Sebuah langganan yang berhenti
bekerja tanpa mengatakannya adalah kegagalan diam — yang justru dihindari seluruh paket ini.

### (G) Dua puluh endpoint OpenAPI: yang dipanggil integrasi, bukan yang tampak penting

Masuk + periksa token (2), daftar & detail dokumen utama yang dibaca sistem akuntansi atau portal
(16: pelanggan ×3, vendor ×2, PO ×2, AR ×2, AP ×2, pembayaran ×2, jurnal, SPK, penerimaan barang),
dan dua layar webhook (2). Yang **sengaja tidak** masuk: 842 rute lain, dan Profil › Token API —
pintu itu ditolak untuk token pribadi (§2D), jadi mendokumentasikannya sebagai endpoint integrasi
akan menjanjikan sesuatu yang tidak terjadi. **JSON, bukan YAML**: tidak ada parser YAML di pohon ini
(`vendor/symfony/yaml` tidak ada) dan menambahkannya melanggar aturan dependensi; `json_decode` ada
di PHP.

### (H) Dua ember laju, dan harganya dikatakan

Ledger §5 baris 10 dipakai apa adanya: token pribadi **300/menit per TOKEN**, semua yang lain tetap
**120/menit** dengan kunci yang sama persis seperti sebelumnya. Per token dan bukan per pengguna:
operator yang membuka peramban sambil integrasinya berjalan tidak boleh memakan jatah integrasi itu.
Harganya: pembatas laju berjalan **sebelum** `auth:sanctum` (ia middleware grup, auth adalah
middleware rute), jadi ia menyelesaikan tokennya sendiri — **satu pencarian indeks primer per
permintaan bertoken**, di samping pencarian yang sama yang dilakukan Guard beberapa mikrodetik
kemudian. Menghindarinya berarti menebak jenis token dari bentuk teksnya, dan tebakan yang salah
membagi jatah yang salah.

---

## 3. Temuan sendiri (bukan oleh mutasi)

1. **Angka 639 yang saya ukur sendiri salah.** Sensus pertama menghitung **kemunculan**
   `PermissionMiddleware` di keluaran `route:list --json`, bukan **rute**: `POST subcontract/
   subcontracts/{subcontract}/advance-payout` dan `.../retention-release` masing-masing membawa DUA
   (`scm.post` DAN `fin.approve`). Yang berarti bagi sebuah token adalah jumlah RUTE — 637, bukan
   639. Ditemukan oleh `UngatedApiRouteCensusTest` yang baru ditulis, dan angkanya diperbaiki di
   empat docblock.
2. **`Modules\Iam\Models\ApiToken` harus menuliskan `$table` sendiri.** Eloquent menurunkan nama
   tabel dari nama KELAS, dan `Laravel\Sanctum\PersonalAccessToken` tidak perlu menuliskannya.
   Tanpa baris itu setiap penulisan token menuju tabel `api_tokens` yang tidak ada, dan **seluruh
   login mati** dengan "no such table" — terukur pada jalan pertama uji kedaluwarsa.
3. **`RequestGuard` menyimpan pengguna yang sudah diselesaikannya**, dan seluruh uji berbagi satu
   aplikasi: permintaan KEDUA di uji yang sama dijawab dari memo tanpa `isValidAccessToken()`
   dipanggil lagi. Versi pertama uji kedaluwarsa memanggil `auth/me` sekali sebelum `travel()` untuk
   "membuktikan tokennya hidup" dan mendapat **200 sesudah 13 jam** — bukan karena plafonnya tidak
   berlaku, tetapi karena tidak ada yang memeriksanya. Kesegaran token kini dipaku di uji sendiri.
4. **`Http::fake()` MENGGABUNGKAN stub.** Pola `'*'` yang didaftarkan di `setUp` menang atas pola
   yang lebih tepat yang didaftarkan sebuah uji: dua uji webhook mendapat **200 alih-alih 302/500**
   dan lulus tanpa menguji apa pun. Stub tangkap-semua dibuang dari `setUp`.
5. **Ukuran "teks terpotong" VACUOUS di layar ini.** Mutasi 120 karakter `white-space: nowrap` di
   kartu Token API **lolos hijau** pada versi pertama S40m: di cangkang ini `.main` ber-
   `overflow-x: auto`, jadi kata panjang tanpa pemutus tidak memperlebar dokumen **dan** tidak
   terpotong leluhur mana pun — ia hanya membuat kolom utama bisa digulir ke samping, yang tidak
   terlihat oleh kedua ukuran yang sudah ada. Syaratnya diperbaiki (`main_scrolls_sideways`), dan
   mutasi yang sama sekarang **merah**.
6. **94 baris centang.** Tangkapan layar S40 pertama menunjukkan kartu Token API setinggi ±1.300 px
   untuk seorang admin (94 izin), dengan tombol "Buat token" jauh di bawah lipatan. Diperbaiki
   menjadi kisi responsif.
7. **Uji yang menjatuhkan tabel adalah uji yang benar di SQLite dan merusak di MySQL.** Versi
   pertama uji ketahanan webhook memakai `Schema::drop()` untuk menirukan jendela deploy (kode
   disalin lebih dulu, `migrate` sesudahnya). Di SQLite itu transaksional dan rollback
   mengembalikannya; di MySQL **DDL adalah commit implisit** — transaksi `RefreshDatabase` pecah,
   tabelnya tidak pernah kembali, dan setiap uji sesudahnya di proses yang sama berjalan di atas
   skema yang bolong. Diganti dengan kegagalan yang sama bentuknya TANPA DDL: satu baris langganan
   yang `secret`-nya bukan ciphertext sah (kasus nyata: `APP_KEY` yang dirotasi), sehingga cast
   `encrypted` melempar tepat di tengah `queueDeliveries()`. Persetujuannya tetap berhasil, 0 baris
   kiriman, 0 permintaan HTTP.
8. **Uji per-berkas hijau BUKAN uji per-direktori hijau.** Gerbang dua driver menemukan satu
   kegagalan yang enam putaran uji per-berkas tidak bisa melihat: aturan tanpa-CDN P1-A
   (`VendorManifestTest`) menolak literal `https://contoh.co.id/…` di `webhook.js` — dan ia BENAR
   menolaknya, karena ia tidak punya aturan untuk bentuk itu. `webhook.js` lahir SESUDAH
   `tests/Feature/Core` terakhir dijalankan utuh, dan setiap putaran sesudahnya per-berkas.
   Ditutup `fff53af` dengan **aturan** (atribut `placeholder` tidak pernah menjadi pemuat pada
   elemen mana pun), bukan allowlist — uji itu sendiri yang menuntut demikian — dan dibuktikan
   tetap sempit dengan tiga varian (§4, V1–V3).

---

## 4. Mutasi — 24 dijalankan, **22 merah, 1 LOLOS HIJAU → syarat diperbaiki lalu merah, 1 hijau by design**

Yang LOLOS HIJAU (M20) dicatat apa adanya, karena itulah gunanya mutasi: ia menemukan sebuah UKURAN
yang tidak bisa gagal (§3.5), dan syaratnya diperbaiki sampai mutasi yang sama memerahkannya.

| # | Mutasi | Yang memerah |
|---|---|---|
| M1 | `Sanctum::authenticateAccessTokensUsing()` dihapus | 3 uji kedaluwarsa |
| M2 | Token personal tanpa `expires_at` diterima | `…without_an_expiry_is_refused_outright` |
| M3 | Cabang personal diterapkan ke SEMUA `kind` | `…fresh_spa_session_token_authenticates` (seluruh aplikasi mati) |
| M4 | Penyempitan di `User::hasPermissionTo()` dimatikan | 3 uji matriks ability |
| M5 | Gerbang delegasi tidak lagi memeriksa token | `…token_without_the_approve_ability_cannot_use_the_delegation` |
| M6 | Token `['*']` ikut dipersempit | 5 uji (termasuk SPA mati) |
| M7 | `ExplainTokenScopeRefusal` dilepas dari grup `api` | 2 uji (kalimat 403) |
| M8 | Laju integrasi dipukul rata 120 | 3 uji laju |
| M9 | Pendengar webhook tidak lagi `ShouldHandleEventsAfterCommit` | `…rolled_back_transaction_sends_nothing_and_logs_nothing` |
| M10 | Badan di-`json_encode` ULANG saat mengirim | `…bytes_that_were_signed_are_the_bytes_that_went_out` |
| M11 | `allow_redirects => true` dan 3xx dihitung terkirim | `…redirect_is_not_followed_and_is_not_counted_as_delivered` |
| M12 | `WebhookUrl::assertSafeToSend()` dilepas dari job | `…broken_subscription_does_not_fail_the_approval` |
| M13 | `http://` diterima | provider "http biasa" |
| M14 | Jalur di OpenAPI diganti nama | `…operation_exists_with_that_path_and_method` |
| M15 | Metode `get` payments → `post` di OpenAPI | `…names_the_permission_that_actually_gates_it` (lewat pembanding izin: `POST payments` ADA, tetapi digerbangi `fin.create`) |
| M16 | `x-izin` ap-bills `fin.view` → `fin.create` | `…names_the_permission_that_actually_gates_it` |
| M17 | Rute Finance diganti nama **di aplikasi** (arah sebaliknya) | `…operation_exists_with_that_path_and_method` |
| M18 | "300 permintaan/menit" di dokumen → "3000" | `…states_the_contract_the_application_actually_implements` |
| M19 | Penegakan ability dimatikan, diukur **di Chromium** | S40: 2 syarat merah |
| M20 | 120 karakter `nowrap` di kartu token, **di Chromium** | ❗ **LOLOS HIJAU** pada versi pertama → syarat diperbaiki (§3.5) → **merah** sesudahnya |
| M21 | `WebhookService::dispatchFor` tidak lagi menelan `Throwable` | `…subscription_that_cannot_even_be_read_does_not_fail_the_approval` |

### Mutasi putaran verifikasi — 16 dijalankan, **16 memerah yang seharusnya merah**

Dua di antaranya (M-R10, M-R14b) adalah mutasi yang harus tetap **HIJAU**, dan itu pun diperiksa:
sebuah paku yang merah untuk hal yang bukan urusannya sama buruknya dengan paku yang tidak pernah
merah. M-R10 menemukan bahwa versi PERTAMA perbaikan V-OPENAPI-1 masih hijau — `withoutMiddleware()`
tidak mengeluarkan middlewarenya dari `gatherMiddleware()`.

| # | Mutasi | Hasil |
|---|---|---|
| M-R1 | `WebhookSignature::TOLERANCE` 300 → 600 | **merah** — `WebhookSignatureTest` (konstanta ≠ openapi.json ≠ §5.14) |
| M-R2 | `normalize()` tidak dipanggil `isPublicIp()` | **merah** — 6 baris provider bertopeng + uji job |
| M-R3 | `numericIpv4()` selalu null | **merah** — `2130706433`, `0177.0.0.1`, `127.1` lolos pintu SIMPAN |
| M-R4 | `scrub()` tanpa rahasia yang dikenal | **merah** — rahasia langganan utuh di kolom `error` |
| M-R5 | Badan bukan-teks tidak diganti kalimatnya | **merah** — `…not_valid_utf8_still_produces_an_indonesian_reason` |
| M-R6 | Tanda tangan dibekukan lagi saat mengantre | **merah** — percobaan ke-3 (+360 dtk) di luar jendela 300 dtk |
| M-R7 | Pemeriksaan pemilik di `TokenScope::tokenFor()` dibuang | **merah** — izin PEMBERI dipersempit token DELEGAT |
| M-R8 | Cabang `catch` `ExplainTokenScopeRefusal` dibuang | **merah** — uji `withoutExceptionHandling()` (sebelum putaran ini: hijau) |
| M-R9 | `token_abilities` dipulangkan untuk SETIAP baris | **merah** — baris orang lain mengaku tahu token pemanggil |
| M-R10 | `->withoutMiddleware('auth:sanctum')` pada rute yang didokumentasikan | ❗ **LOLOS HIJAU** pada versi pertama syaratnya (hanya `gatherMiddleware()` yang dibaca) → `excludedMiddleware()` ikut dibaca → **merah** |
| M-R11 | `requestBody` login dihapus dari dokumen | **merah** |
| M-R12 | Parameter `page` dibuang dari satu daftar | **merah** |
| M-R13 | `throttle:10,1` login → `throttle:20,1` | **merah** — dokumen menyebut angka yang bukan angka rutenya |
| M-R14 | Rute BERGERBANG baru di modul lain (reproduksi V-OPENAPI-6) | **merah** pada sensus lama → sesudah perbaikan **hijau**, dan itu benar |
| M-R15 | Rute TULIS baru TANPA gerbang izin | **merah** — daftar literal tetap menjaga apa yang harus dijaga |
| M-R16 | Gerbang layar `#/webhook` dibuang, diukur **di Chromium** | **merah** — S40 2 syarat gagal, alert «User does not have the right permissions.» + 1 galat konsol 403 |

Dan tiga varian atas salinan `public/app` (`SPA_ROOT`) yang membuktikan **aturan `placeholder` yang
baru tetap sempit** (§3.7):

| # | Varian | Hasil |
|---|---|---|
| V1 | alamat yang SAMA dipindah ke `src:` | **merah** — ia pemuat |
| V2 | `<script src="https://cdn.jsdelivr.net/…">` baru di `index.html` | **merah** |
| V3 | `placeholder:` ke CDN sungguhan | hijau — **memang**: ia teks yang dibaca orang, bukan alamat yang diambil peramban |

---

## 5. Permukaan — setiap aturan baru, diperiksa satu per satu

| Aturan | Request | Middleware | Service/Job | Event/Listener | API | Layar SPA | Log | OpenAPI | PANDUAN | Harness |
|---|---|---|---|---|---|---|---|---|---|---|
| Ability = subset izin | `ApiTokenStoreRequest` | `ExplainTokenScopeRefusal` (kalimat) | `TokenScope` di `User::hasPermissionTo()` + gerbang delegasi | — | 403 + `errors.token_abilities`; **`data.token_abilities` di `auth/me`** (V-TOKEN-1) | `token-scope-note` | — | `x-autentikasi.ability`, **403 tiap operasi dengan KEDUA bentuk badannya** | §20 | S40 (403 sungguhan) |
| Kedaluwarsa ≤ 1 tahun | `expires_in_days` 1–365 | — | `authenticateAccessTokensUsing` | — | `expires_at`, `expired` | baris token | — | `x-autentikasi.token_pribadi` | §20 | S40 ("Berlaku sampai") |
| Rahasia tampil sekali | — | — | — | — | hanya di `store`/`rotate` | `token-secret` / `webhook-secret` + `shown_once` | — | — | §20, §5.14 | S40 (muat ulang → hilang) |
| Token tidak mencetak token | — | `SessionOnly` | — | — | 403 | (tak terlihat: SPA memakai sesi) | — | — | §20 tabel | `ApiTokenEndpointTest` |
| HMAC atas byte yang dikirim | — | — | `WebhookSignature` + `WebhookPayload::encode` | — | `data.signature` (resep) | `webhook-signature` | kolom `signature` + `payload` | `x-webhook.tanda_tangan` | §5.14 | S40 (resep digambar) |
| https + alamat internal ditolak | `WebhookSubscriptionRequest` | — | `WebhookUrl` (job) | — | 422 | toast | `error` baris | `x-webhook` | §5.14, KEPUTUSAN §11 | S40 (toast SSRF) |
| `sent` hanya 2xx | — | — | `DeliverWebhook` | — | `status` | lencana + `webhook-error` | kolom `status` | — | §5.14 | `WebhookGuardTest` |
| Tidak mengklaim yang dibatalkan | — | — | `ShouldQueueAfterCommit` | `ShouldHandleEventsAfterCommit` | — | — | 0 baris | — | §5.14 | `WebhookDeliveryTest` |
| Nonaktif otomatis 20× | — | — | `WebhookService::recordFailure` | — | `disable_after_failures` | `webhook-disabled-reason` + Aktifkan lagi | `consecutive_failures` | — | §5.14 | S40 (kalimat ambang) |
| Laju 300/menit | — | `throttleApi` + `IntegrationRate` | — | — | 429 + `Retry-After` | kalimat kartu | — | 429 tiap operasi | §20, §5.14 | S40 (kalimat kartu) |
| CORS kosong | — | — | — | — | — | — | — | `x-autentikasi.cors` | §5.14 | `OpenApiDriftTest` (config nyata) |
| **Tanda tangan per PERCOBAAN** (V-webhook-1) | — | — | `DeliverWebhook` (tepat sebelum POST) | — | — | — | kolom `signature` = catatan percobaan terakhir | `x-webhook.tanda_tangan.percobaan` | §5.14 | `WebhookGuardTest` (travel 4.860 dtk) |
| **Bentuk samaran alamat internal** (V-webhook-2) | `WebhookSubscriptionRequest` (422) | — | `WebhookUrl::normalize()` + `numericIpv4()` (job) | — | 422 | toast | `error` baris | — | §5.14, KEPUTUSAN §11.2 | `WebhookGuardTest` (9 bentuk) |
| **Rahasia tidak pernah ke log** (V-webhook-4) | — | — | `ProviderErrorScrubber` dengan rahasia langganan | — | kolom `error` yang dipulangkan `deliveries` | `webhook-error` | `[rahasia]` | — | §5.14 | `WebhookGuardTest` |
| **Badan penerima selalu UTF-8 sah** (V-webhook-3) | — | — | `ProviderErrorScrubber` (setiap pemanggil, termasuk P-3a) | — | — | `webhook-error` | kolom `error` | — | — | `WebhookGuardTest` (WAJIB di MySQL) |
| **Bentuk rahasia dikatakan** (V-webhook-5) | — | — | `WebhookSignature::SECRET_FORM` | — | `data.signature.secret_form` | `webhook-signature` | — | `x-webhook.tanda_tangan.rahasia` | §5.14 ×2 | S40 + `WebhookSignatureTest` |
| **Layar digerbangi izinnya sendiri** (V-OPENAPI-3) | — | — | — | — | 403 | `accessDenied(host, 'core', 'core.update')` | — | — | — | S40 (finance@) |

---

## 6. Perangkap yang disebut di perintah — apa yang terjadi pada masing-masing

| | Perangkap | Hasil |
|---|---|---|
| A | Ability yang tidak ditegakkan | Ditegakkan di `User::hasPermissionTo()`; matriks 403 menyebut ability; token `['*']` utuh; kedua arah subset dipaku; **jalur delegasi ditutup terpisah** |
| B | Dua kunci kedaluwarsa Sanctum | Pilihan (a), tanpa backfill, `config/sanctum.php` tidak disentuh; uji `travel()` 13 jam / 11 bulan / lewat tanggal / tanpa `expires_at` |
| C | Webhook mengklaim peristiwa yang dibatalkan | Dua lapis AfterCommit; uji rollback menuntut 0 job, 0 baris, 0 HTTP; langganan rusak **dan tabel yang belum ada** (jendela deploy sebelum `migrate`) tidak menjatuhkan persetujuan |
| D | HMAC sebagai kontrak | Byte yang dikirim = byte yang ditandatangani = byte di kolom `payload`; stempel ikut ditandatangani; resep lengkap di tiga permukaan dari satu sumber |
| E | SSRF | https wajib; loopback/privat/link-local/CGNAT/`.local` ditolak **saat menyimpan DAN saat mengirim**; redirect tidak diikuti; `Http::fake()` + `preventStrayRequests()` + seam DNS |
| F | Log yang jujur | `sent` hanya 2xx; 5 percobaan backoff rumah; `failed` dengan sebab Indonesia; `ProviderErrorScrubber`; nonaktif otomatis yang mengatakan dirinya |
| G | OpenAPI yang bisa memerah | 20 endpoint, anti-drift **dibatasi** pada kedua puluh itu, tiga arah dibuktikan mutasi |
| H | CORS / laju | CORS kosong (diperiksa terhadap config nyata); 300/menit per token, 120/menit peramban tidak disentuh; 429 + `Retry-After` + kalimat |
| I | Permukaan | §5 |

---

## 7. Bukti peramban (pelajaran 1: rilis SPA belum terverifikasi sampai DIMUAT)

Chromium headless, server `php -S 127.0.0.1:8271` atas **salinan** data demo (migrasi dijalankan
lebih dulu), dimatikan berdasarkan PID dari `ss -ltnp`.

* **Harness S40** (1440×900): **28 syarat**, semuanya hijau, `console_errors: []`, 5 klik.
  Token dan langganan dibuat **lewat layar**, bukan disuntikkan ke sqlite. Yang dibuktikan di
  peramban dan tidak bisa dibuktikan di suite: teks token hilang sesudah muat ulang **dan tidak ada
  di mana pun di HTML halaman**; token itu dipakai memanggil API sungguhan (`finance/journals` 200,
  `iam/users` 403 dengan `«iam.view»`); Cabut → permintaan berikutnya 401 sementara token sesi
  peramban yang sedang dipakai tetap hidup; URL `169.254.169.254` ditolak dengan kalimat SSRF-nya.
* **Harness S40m** (390×844, `is_mobile`, `has_touch`): **8 syarat** hijau, 0 gulir samping
  (dokumen **dan** kolom utama), 0 teks terpotong, `console_errors: []`.
* **Sapuan 12 rute × 2 viewport** (dasbor, profil, webhook, pengaturan, tugas, tenggat, pengiriman
  notifikasi, antrean gagal, pelanggan, jurnal, PO, beranda): **0 galat konsol, 0 jawaban HTTP
  ≥ 400, 0 gulir samping** di kedua viewport. Sapuan ini ada karena §2A menyentuh **setiap**
  pemeriksaan izin di aplikasi — satu regresi di sana akan mematikan layar yang tidak ada
  hubungannya dengan paket ini.

**Putaran verifikasi** (server `php -S 127.0.0.1:8275` atas salinan yang sama; dibuktikan memakai
SALINAN dengan menulis penanda ke baris `finance@` di sqlite dan membacanya kembali lewat
`auth/me`, lalu mengembalikannya — basis data demo hidup tidak disentuh):

* `#/webhook` sebagai **finance@nusantara.test** (tanpa `core.update`), 1440×900:
  `alert` = «Anda tidak memiliki hak akses "core.update" untuk halaman ini.», `webhook_form_rendered`
  false, `nav_has_webhook` false, `console_errors` **[]**, jawaban ≥ 400 ke `core/webhooks` **[]** —
  dan orang yang sama tetap melihat layar yang boleh dilihatnya (`Profil & Notifikasi`).
* Empat syarat itu **dibuktikan bisa gagal**: dengan baris gerbangnya dibuang, S40 melaporkan
  `a_user_without_core_update_gets_the_house_sentence_not_the_english_one` dan
  `the_refused_screen_never_calls_the_api_it_may_not_call` merah, dengan `alert` = «User does not
  have the right permissions.» dan satu galat konsol 403 (M-R16).
* **HTTP sungguhan, V-TOKEN-1**: token pribadi ber-ability `prj.view` saja →
  `GET /api/iam/auth/me` memulangkan `token_abilities: ["prj.view"]` sementara `permissions` tetap
  **94** nama (termasuk `fin.approve` dan `fin.post`); `POST /api/finance/payments/1/approve` →
  403 «fin.approve» dengan `errors.token_abilities: ["fin.approve"]`. Token sesi SPA → `["*"]`.
* **HTTP sungguhan, V-OPENAPI-2**: `GET /api/core/webhooks` sebagai finance@ → 403 dengan `message`
  saja dan **tanpa** kunci `errors` — bentuk yang kini dituliskan dokumen apa adanya.
* **HTTP sungguhan, V-OPENAPI-4/5**: `?page=2&per_page=1&sort=name&dir=desc` → `meta` =
  `{current_page: 2, per_page: 1, from: 2, to: 2, total: 3, last_page: 3, sort: 'name', dir: 'desc'}`;
  `POST auth/login` badan kosong → **422**; sesudah 10 kali → **429** dengan `Retry-After: 34`.

Tangkapan layar: `docs/bukti-uji/s40-token-api.png`, `s40-webhook.png`, `s40m-token-api.png`,
`s40m-webhook.png` (keempatnya diambil ulang pada putaran verifikasi).

---

## 8. Gerbang

Dijalankan di worktree terisolasi `/root/p3d` (vendor **disalin**, bukan symlink — pelajaran
"worktree vendor symlink"), **atas `78e7513`**, ujung cabang pada saat gerbang dijalankan. Satu
proses phpunit per basis data; MySQL memakai `erp_dryrun`, bukan `erp_test`.

| Driver | Perintah | Hasil |
|---|---|---|
| SQLite | `php vendor/bin/phpunit` | **OK — 5.044 uji / 34.620 asersi, 11 dilewati**, 16 mnt 12 dtk |
| MySQL `erp_dryrun` | `php vendor/bin/phpunit -c phpunit.mysql.xml` | **OK — 5.044 uji / 34.626 asersi, 9 dilewati**, 38 mnt 42 dtk |

Selisih dilewati (11 SQLite : 9 MySQL) dan asersi (34.620 : 34.626) berbentuk sama persis dengan
LAPORAN P-3b §8 (11 : 9 di sana juga): dua uji yang dilewati di SQLite berjalan di MySQL, dan
asersinya ikut terhitung.

`tests/Unit` **ikut di dalam gerbang penuh** (`phpunit.xml` memuat suite `tests/Unit`; **630 uji /
2.168 asersi** bila dijalankan sendiri) — disebut terpisah di sini karena gerbang per-direktori
yang dipakai selama bekerja TIDAK memuatnya, dan paket ini menyentuh `config/` dan rute. Gerbang
per-direktori yang dijalankan pada putaran ini: `tests/Feature/Core tests/Feature/Iam tests/Unit` →
**OK, 1.946 uji / 13.542 asersi, 11 dilewati**.

`vendor/bin/pint --test` atas **setiap** berkas PHP yang disentuh putaran verifikasi (11 berkas
`Modules/`, 9 berkas `tests/` — `git diff --name-only 8fe7d4e..HEAD`) → `passed`. Dua kegagalan pint lama yang sengaja tidak direformat
(`FormXlsxExportService`, `ChartMigrationTest`) tidak disentuh.

**Delta terhadap `main` 8438066: +122 kasus uji.** Diturunkan, bukan ditebak: `git diff --name-only
main..HEAD -- tests/` menyebut 13 berkas, **12 di antaranya BARU** (tidak ada di `main`) dan yang
ke-13 (`VendorManifestTest`) punya jumlah metode uji yang sama di kedua sisi (6 : 6, hanya asersinya
bertambah). Jumlah kasus di kedua belas berkas baru itu: 40 + 13 + 11 + 10 + 10 + 8 + 8 + 7 + 5 + 5
+ 4 + 1 = **122**, jadi `main` = 5.044 − 122 = **4.922**. Gerbang penuh atas `main` sendiri TIDAK
dijalankan di putaran ini (ia menuntut worktree ketiga beserta vendornya); angka 4.922 karena itu
ditandai sebagai **turunan**, bukan hasil pengukuran.

Satu-satunya berkas yang berubah sesudah gerbang dijalankan adalah laporan ini
(`git diff 78e7513..HEAD --stat` → hanya `docs/LAPORAN-PAKET-HM-P-3d.md`); tidak ada uji di
repositori ini yang membacanya. Itu dikatakan di sini karena putaran verifikasi ini menutup sebuah
temuan yang persis tentang baris gerbang ✅ yang menunjuk ke log dari commit yang bukan HEAD
(V-webhook-6 / V-OPENAPI-8).

---

## 9. Keputusan pemilik

Tidak ada yang menunggu pemilik untuk paket ini berfungsi. Yang **dipakai sebagai rekomendasi
sampai dijawab**: ledger §5 baris 10 (CORS kosong / 300 per menit) dipakai apa adanya, dan angka 20
untuk ambang nonaktif otomatis adalah pilihan paket ini (§2F) yang bisa diubah pemilik.

### (V-OPENAPI-2) 403 "izin penggunanya kurang": dokumen yang jujur, atau kalimat yang diterjemahkan

Sebuah 403 di aplikasi ini punya **dua** bentuk badan. Ability token yang kurang → kalimat Indonesia
+ `errors.token_abilities` (buatan paket ini). Izin PENGGUNA yang kurang → «User does not have the
right permissions.» berbahasa Inggris, tanpa `errors` — kalimat bawaan pustaka izin, dan itu bentuk
yang sudah ada di `main` untuk **seluruh 644 rute bergerbang**, jauh sebelum P-3d.

* **(a) Dokumen mengatakan keduanya apa adanya** — dipilih dan diterapkan. Dokumen berhenti
  berbohong hari ini, bentuk kedua kasus dipaku uji HTTP, dan tidak ada satu pun perilaku di luar
  P-3d yang berubah.
* **(b) `ExplainTokenScopeRefusal` ikut menerjemahkan `UnauthorizedException` spatie** menjadi
  kalimat Indonesia berbentuk `Galat`. Lebih ramah, tetapi ia mengubah **badan 403 setiap rute api
  di aplikasi ini** — 644 rute bergerbang di sembilan modul, termasuk yang tidak pernah disentuh
  paket ini — jadi ia menuntut verifikasi selebar `main`, bukan selebar P-3d. Sebuah putaran
  perbaikan bukan tempatnya.

Rekomendasi: (b) dikerjakan sebagai paketnya sendiri, bersama keputusan apakah kalimat izin yang
kurang boleh **menyebutkan nama izinnya** (hari ini tidak — dan itu sendiri keputusan keamanan:
ia memberi tahu pemanggil peta izin aplikasi).

## 10. Prasyarat pemilik

1. **URL penerima webhook** — tidak ada satu pun langganan di repo; perusahaan yang akan menerima
   kiriman harus menyediakan endpoint https yang bisa dijangkau dari luar dan memasang rahasianya.
2. **Keputusan siapa yang boleh memegang token integrasi** — token mewarisi izin pemiliknya, jadi
   token milik admin adalah token admin. Rekomendasi: satu akun teknis dengan peran sempit.
3. **Merge dan deploy** — keduanya langkah pemilik.

## 11. Yang TIDAK dikerjakan (per butir)

1. **Webhook MASUK.** Paket ini hanya arah keluar; webhook masuk WhatsApp (P-3a) tidak disentuh.
2. **Langganan per PENGGUNA.** Langganan adalah pengaturan sistem (`core.update`), bukan milik
   pribadi; muatannya tidak disaring menurut izin siapa pun karena ia hanya penunjuk (§2E).
3. **Kirim ulang manual sebuah kiriman yang gagal.** Log menampilkan sebabnya; tombol kirim ulang
   akan menuntut jalur kedua yang bisa menimpa jadwal antrean — pelajaran ledger #16.
4. **Peristiwa selain `DocumentTransitioned`.** Tidak ada `document.deleted`, `payment.posted`, dll.
5. **Penyempitan rute yang tidak dijaga izin apa pun.** 218 rute tetap terbuka bagi token terbatas;
   menutupnya adalah perubahan perilaku yang menyentuh setiap modul, dan batasnya **dikatakan** di
   layar, panduan, dan dokumen OpenAPI. Yang dijaga uji adalah **daftar literal 31 rute TULIS** di
   antaranya, bukan jumlahnya (§2A, §12.1, §12.4).
6. **Sunting ability sebuah token yang sudah ada.** Ability dipilih saat dibuat; mengubahnya berarti
   token yang sama berganti arti di tengah umurnya.

## 12. Deviasi baru yang ditemukan

1. **218 rute api tidak dijaga izin apa pun** (29 di antaranya TULIS sebelum paket ini). Semuanya
   sudah diperiksa satu per satu dan masuk tiga golongan (self-service / in-controller /
   session-only), tetapi **tidak satu pun** bisa dipersempit ability. Dipaku
   `UngatedApiRouteCensusTest` dengan daftar literal, supaya yang berikutnya adalah keputusan sadar.
2. **`Modules\Iam\Models\ApiToken` menuruni `PersonalAccessToken` Sanctum, bukan
   `Modules\Core\Models\BaseModel`** (CONVENTIONS §1). Disengaja: Guard menyelesaikan token lewat
   `Sanctum::$personalAccessTokenModel::findToken()`, jadi model yang tidak menuruni kelas itu tidak
   akan pernah dipakai.
3. **`Sanctum::actingAs($user)` tanpa daftar ability berarti NOL ability**, dan ratusan uji di
   repositori ini memakainya sebagai "sesi biasa". Tidak diubah (perubahan menyentuh ratusan
   berkas), tetapi dicatat di CONVENTIONS §41: uji yang menguji ability harus memakai token
   sungguhan lewat HTTP.

### Ditemukan pada putaran verifikasi

4. **Sensus rute adalah UKURAN, bukan paku** (V-OPENAPI-6, pelajaran 4). Versi pertama
   `UngatedApiRouteCensusTest` memaku empat total seluruh aplikasi; satu rute baru yang wajar DAN
   bergerbang izin di modul mana pun memerahkan gerbang P-3d. Keempat total dibuang; yang tinggal
   adalah daftar literal rute TULIS tanpa gerbang izin. Konsekuensinya: **angka 862/644/218/646/31
   di §0 tidak dijaga uji apa pun** — ia diukur ulang dengan perintah ketika ada yang ingin tahu,
   dan itu disengaja.

5. **`Illuminate\Routing\Pipeline` merender pengecualian sebelum middleware grup melihatnya**
   (V-TOKEN-2). Cabang `catch` di `ExplainTokenScopeRefusal` karena itu tidak pernah berjalan di
   jalur produksi — yang berjalan adalah cabang JAWABAN, untuk 403 dari middleware rute MAUPUN dari
   dalam controller. Cabangnya dipertahankan sebagai lapis kedua (ia hidup ketika penangan
   pengecualian melempar ulang, mis. `withoutExceptionHandling()`), dan docblock-nya berhenti
   menjanjikan bahwa separuh aplikasi bergantung padanya.

6. **Pola `r/<modul>/<resource>` menuliskan `${module}.view` walau `viewPerm` berbeda.**
   Dua entri di `schema.js` memakai `viewPerm: 'core.update'`, tetapi `accessDenied()` dipanggil
   dengan `def.module` saja, jadi layar itu berkata «hak akses "core.view"» kepada orang yang
   sebenarnya kurang `core.update`. **TIDAK diubah di sini**: ia ada sejak sebelum P-3d, menyentuh
   tiga pemanggil generik dan setiap layar `r/`, dan bukan temuan putaran ini. `accessDenied()`
   kini **menerima** nama izin penuh, jadi perbaikannya tinggal meneruskan `def.viewPerm`.

7. **Langganan dengan `secret` yang tidak bisa didekripsi kini MELAHIRKAN baris log, lalu berhenti**
   (akibat V-webhook-1). Sebelumnya rahasianya dibaca saat mengantre, jadi `DecryptException`
   ditelan `dispatchFor()` dan tidak ada baris sama sekali; sekarang ia dibaca di job. Persetujuan
   dokumennya tetap berdiri (itu yang dijaga), tidak satu pun POST berangkat, dan barisnya `failed`
   dengan kalimat Indonesia yang menyuruh memutar rahasianya. Perubahan perilaku yang disengaja:
   kegagalan yang terlihat lebih baik daripada kegagalan yang diam.

## 13. Commit (urut lama → baru)

| SHA | Isi |
|---|---|
| `5fd2b21` | T3d.1a — kedaluwarsa Sanctum, migrasi Iam 000253, `ApiToken` |
| `377398c` | T3d.2 — penegakan ability, kalimat 403, dua ember laju, sensus rute |
| `dc062e1` | T3d.1b — Profil › Token API (CRUD) + `SessionOnly` |
| `7900bd1` | T3d.3 — webhook keluar (migrasi Core 001803, HMAC, SSRF, log, nonaktif otomatis) |
| `80a9881` | T3d.4 — OpenAPI tangan + anti-drift tiga arah |
| `057a078` | Layar Token API & Webhook, `SHELL_VERSION` 12, harness S40/S40m |
| `13958d6` | pint `single_quote` pada uji kabel SPA |
| `aebe995` | Dokumen (PANDUAN ×2, KEPUTUSAN-INTEGRASI §11, CONVENTIONS §41, ROADMAP, `.env.example`) |
| `8e278e9` | Laporan + uji: tabel webhook yang belum ada tidak boleh menjatuhkan persetujuan |
| `a0479a3` | Rapi: parameter mati di `recordAttemptFailure`, muatan yang di-decode ulang |
| `fff53af` | Aturan ketiga `VendorManifestTest` — `placeholder` adalah contoh, bukan pemuat |
| `8fe7d4e` | Uji ketahanan webhook tanpa DDL (benar di SQLite, merusak di MySQL) |

**Putaran verifikasi:**

| SHA | Isi | Temuan yang ditutup |
|---|---|---|
| `8a534d9` | Webhook: normalisasi alamat, tanda tangan per percobaan, rahasia ke penyaring, badan bukan-teks, resep tiga permukaan | V-webhook-1..5, V-OPENAPI-7 |
| `675c10d` | Token: `token_abilities`, cabang `catch` yang dijaga, pemeriksaan pemilik, angka yang dikutip | V-TOKEN-1..4 |
| `ba599ec` | OpenAPI: arah keempat, 403 dua bentuk, `requestBody`/paging, batas laju login, sensus yang menyempit | V-OPENAPI-1, 2, 4, 5, 6, 8 (rujukan silang) |
| `0fd26be` | Layar: `#/webhook` digerbangi `core.update`, S40 +4 syarat | V-OPENAPI-3 |
| (laporan) | §8 diisi angka gerbang yang benar-benar dijalankan, §15 | V-webhook-6, V-OPENAPI-8 |

## 14. Penyimpangan konvensi yang disengaja

Lihat §12.2 (`ApiToken` menuruni kelas Sanctum) dan §2A (`pushMiddlewareToGroup` alih-alih alias di
`bootstrap/app.php` — dipilih justru **agar** `bootstrap/*` tidak disentuh).

Putaran verifikasi menambah satu: **migrasi Core 001803 disunting, bukan ditambahi migrasi kedua**
(kolom `signature` menjadi nullable, V-webhook-1). Aturan "migrasi aditif nullable tanpa backfill"
menjaga baris yang SUDAH ADA di produksi; migrasi ini belum pernah dijalankan di mana pun di luar
worktree ini — `docs/api/openapi.json` dan tabelnya lahir bersama cabang ini dan belum di-deploy —
jadi migrasi kedua yang mengubah kolom yang belum pernah ada hanya akan menambah satu langkah yang
tidak berarti bagi siapa pun. Bila cabang ini sudah terlanjur di-deploy ketika ini dibaca, yang
benar adalah migrasi kedua.

## 15. Putaran verifikasi

Enam belas temuan, **enam belas ditutup, nol ditolak**. Setiap satu direproduksi lebih dulu dengan
perintah verifier, lalu diperbaiki dengan paku yang **merah sebelum perbaikan** (§4, M-R1…M-R16).

| Id | Jenis | Gejala | Penutupan | Commit |
|---|---|---|---|---|
| V-webhook-2 | SECURITY | `https://[::ffff:169.254.169.254]/` lolos KEDUA pintu SSRF dan POST bertanda tangannya berangkat; bentuk desimal/oktal/pendek lolos pintu SIMPAN | Alamat DINORMALKAN sebelum dinilai (`::ffff:`, `::a.b.c.d`, NAT64) dan host numerik diterjemahkan ala `inet_aton`; 9 bentuk baru di data provider + uji job `assertSentCount(0)` | `8a534d9` |
| V-webhook-4 | SECURITY | Rahasia langganan yang digemakan penerima tersimpan utuh di kolom `error` yang dipulangkan API dan dibaca setiap pemegang `core.update` | Rahasia diserahkan ke `ProviderErrorScrubber` sebagai rahasia yang dikenal, di ketiga pemanggilnya | `8a534d9` |
| V-webhook-1 | BUG | Tanda tangan dibekukan saat mengantre; percobaan ke-3/4/5 (+360/+1260/+4860 dtk) selalu di luar jendela 300 dtk yang dokumen suruh penerima tegakkan — 3 dari 5 percobaan mustahil berhasil | Ditandatangani per PERCOBAAN tepat sebelum POST; kolom `signature` menjadi catatan percobaan terakhir (nullable); uji `travel()` sampai +4.860 dtk | `8a534d9` |
| V-webhook-3 | BUG | Badan jawaban yang bukan UTF-8 sah menjatuhkan penulisan baris log di MySQL (1366); layar lalu menampilkan galat SQL Inggris yang menyebut soket dan nama basis data | Penyaring memaksa keluarannya UTF-8 sah (melindungi P-3a juga) + badan bukan-teks diganti hitungan byte; diuji **di kedua driver** | `8a534d9` |
| V-webhook-5 | DOCS | Resep tidak pernah mengatakan rahasianya dipakai apa adanya; penerima yang meng-hex-decode 64 karakter itu gagal pada SETIAP kiriman | `WebhookSignature::SECRET_FORM` → layar, PANDUAN ×2, openapi.json; dipaku `WebhookEndpointTest`, `WebhookSignatureTest`, S40 | `8a534d9` |
| V-OPENAPI-7 | DOCS | Docblock mengklaim `WebhookSignatureTest` memaku ketiga permukaan; berkas itu tidak ada, dan tidak ada uji apa pun yang membaca PANDUAN | Berkas itu ditulis: ia MEMBACA §5.14 dan `openapi.json` dan membandingkannya dengan konstanta (4 uji / 48 asersi); mutasi TOLERANCE 300→600 merah | `8a534d9` |
| V-TOKEN-1 | HONESTY | `auth/me` menyerahkan 94 izin termasuk `fin.approve` kepada token ber-ability `prj.view`, dan tidak ada pintu mana pun untuk membaca ability tokennya sendiri | `token_abilities` dari `TokenScope` (sumber penegakan yang sama), hanya pada baris pemanggil; didokumentasikan + dipaku HTTP | `675c10d` |
| V-TOKEN-2 | TEST-GAP | Cabang `catch` bisa dihapus seluruhnya dengan 107 uji tetap hijau, sementara docblock-nya menjanjikan separuh aplikasi bergantung padanya | Docblock dikoreksi (Pipeline merender lebih dulu), cabangnya dipertahankan sebagai lapis kedua, dan uji `withoutExceptionHandling()` menjalankannya | `675c10d` |
| V-TOKEN-3 | TEST-GAP | Pemeriksaan PEMILIK di `TokenScope::tokenFor()` dibuang tanpa satu pun uji memerah | Uji delegasi: izin PEMBERI ditanyakan sementara token DELEGAT diingat, dengan ability yang sengaja berbeda | `675c10d` |
| V-TOKEN-4 | DOCS | Docblock mengutip sensus `main` (852/637/215/29) tanpa syarat di cabang yang angkanya 862/644/218/31 | Angka dibuang dari komentar; yang dipaku adalah daftar literal, jumlahnya diukur dengan perintah (§12.4) | `675c10d` |
| V-OPENAPI-1 | TEST-GAP | `->withoutMiddleware('auth:sanctum')` pada endpoint yang didokumentasikan LOLOS HIJAU — dokumen menjanjikan Bearer untuk rute yang terbuka bagi siapa pun | Arah keempat ditambahkan, membaca `excludedMiddleware()` juga; login dipaku sebagai satu-satunya `security: []` | `ba599ec` |
| V-OPENAPI-2 | HONESTY | 19 operasi menjanjikan `errors.token_abilities` untuk kasus yang badannya justru tanpa `errors` sama sekali | Kedua kasus dituliskan terpisah; bentuk badan keduanya dipaku uji HTTP; pilihan a/b ditulis di §9 | `ba599ec` |
| V-OPENAPI-4 | DOCS | Nol `requestBody`, nol parameter kueri — integrasi tidak bisa masuk dan tidak bisa meminta halaman kedua | `requestBody` untuk kedua operasi TULIS, skema `AmplopDaftar`, parameter bersama pada 10 daftar; dua asersi baru + satu uji HTTP | `ba599ec` |
| V-OPENAPI-5 | HONESTY | Login didokumentasikan 200/401 dan uji MEMAKU kelalaian itu, padahal rutenya `throttle:10,1` dan menjawab 429 serta 422 | 422 + 429 didokumentasikan dengan angka 10/menit; asersi 19 → 20; angkanya dipaku terhadap rutenya | `ba599ec` |
| V-OPENAPI-6 | DESIGN | Empat total seluruh aplikasi dipaku; satu rute bergerbang baru di modul mana pun memerahkan gerbang P-3d | Keempat total dibuang, daftar literal dipertahankan; dibuktikan hijau untuk rute bergerbang baru dan merah untuk rute TULIS tak bergerbang | `ba599ec` |
| V-OPENAPI-3 | UX | `#/webhook` menggambar «User does not have the right permissions.» kepada setiap pengguna tanpa `core.update`, plus satu galat konsol | Rutenya digerbangi di layar; `accessDenied()` menerima nama izin penuh; S40 +4 syarat, dibuktikan bisa gagal (M-R16) | `0fd26be` |
| V-webhook-6, V-OPENAPI-8 | HONESTY / DOCS | Baris gerbang ✅ menunjuk §8 yang kosong dan sebuah log di `/tmp` yang bukan HEAD; dua rujukan silang salah arah | §8 diisi angka yang benar-benar dijalankan di kedua driver atas HEAD; §7 → §4 dan §5 → §12.1 diperbaiki | `ba599ec` + laporan ini |

Yang **tidak** dikerjakan di putaran ini, dan alasannya, ada di §9 (pilihan b V-OPENAPI-2) dan §12.6
(kalimat `${module}.view` pada pola `r/` generik — ada sejak sebelum P-3d, bukan temuan putaran ini,
dan jalannya sudah dibuka).

### 15.1 Putaran penutup (verifier penutup atas `ffb9550`: 3 temuan, semuanya ditutup sesi utama)

Verifier penutup menjalankan ulang setiap perintah verifier putaran pertama di ujung cabang (18/18
tertutup, direproduksi, termasuk V-webhook-3 DI MySQL), gerbang per-direktori sendiri (Core + Iam +
`tests/Unit` = 1.946 uji / 13.542 asersi), harness S40/S40m dari worktree-nya sendiri (28 + 8 syarat,
`console_errors []`), dan memeriksa bahwa `results-phase-3.json` menyimpan 14 kunci lama TANPA satu
byte pun berubah. Putusannya **BELUM SIAP** karena satu temuan kejujuran pada satu-satunya permukaan
yang dibaca pihak penerima.

| ID | Jenis | Temuan (gejala) | Penutupan |
|---|---|---|---|
| V-close-1 | HONESTY | **Sembilan** dari dua puluh operasi yang didokumentasikan menjanjikan 403 «ability yang kurang» pada rute yang TIDAK digerbangi izin apa pun — 403 yang tidak pernah bisa dikirim. Token «hanya baca proyek» memulangkan **200** berisi seluruh master pelanggan, vendor, PO, SPK dan penerimaan barang, sementara `OpenApiDriftTest` justru MEMAKU kalimat yang tidak benar itu supaya tetap ada. Batasnya sudah jujur di layar Profil dan PANDUAN §20 — yang berbohong hanya `openapi.json` | Blok `403` dibuang dari kesembilan operasi ber-`x-izin: []`; masing-masing kini mengatakan sendiri «Rute ini TIDAK digerbangi izin apa pun … ability tidak mempersempitnya … TIDAK punya jawaban 403»; `x-autentikasi.ability` membawa peringatan yang sama. `OpenApiDriftTest` kini MENGIKUTI `x-izin` dan berlaku DUA ARAH: bergerbang → wajib 403 dengan kedua kalimatnya; tanpa gerbang → wajib TIDAK punya 403 DAN wajib memuat kalimat jujurnya. Tiga mutasi merah: 403 mustahil dikembalikan, kalimat jujur dihapus, 403 dibuang dari rute bergerbang |
| V-close-2 | DOCS | `info.description` masih berkata uji anti-driftnya membandingkan «TIGA hal» — sejak `ba599ec` ia membandingkan EMPAT (autentikasi termasuk), dan kalimat itu berumur lebih pendek daripada commit yang menulisnya | «EMPAT hal — jalur, metode HTTP, izin yang menggerbanginya, dan apakah rutenya benar-benar menuntut `auth:sanctum`» |
| V-close-3 | DOCS | `CoreServiceProvider::registerApiTokenScope()` mengutip sensus `main` (852 rute api) tanpa menyebut pohonnya — cacat yang sama dengan V-TOKEN-4, diperbaiki di `TokenScope` tetapi tertinggal di berkas yang MENDAFTARKAN penjaganya; angka berjalannya 862 | Angka dikualifikasikan dan sumbernya disebut (`php artisan route:list --json`), seperti `TokenScope`; dua kemunculan «852» yang tersisa adalah kutipan sejarah yang memang menjelaskan angka basi itu |

**Bukti sesudah penutupan** (pohon kerja `/root/p3d`, ujung cabang): `tests/Feature/Core` +
`tests/Feature/Iam` + `tests/Unit` = **1.946 uji / 13.558 asersi** (11 dilewati) hijau;
`OpenApiDriftTest` **8 uji / 213 asersi**; `pint --test` bersih pada berkas yang disentuh. Dokumen
`openapi.json` kini: 20 operasi, **10 bergerbang izin dengan 403**, **10 tanpa gerbang tanpa 403**.
