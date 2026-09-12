# Laporan Paket HM P-3d — API & webhook (token akses pribadi, ability yang ditegakkan, webhook keluar bertanda tangan, OpenAPI tangan)

**Cabang:** `feat/phase3-p3d` (dari `main` 8438066 = merge P-3c) · **Tanggal:** 12 September 2026
**Roadmap:** `docs/ROADMAP-HASHMICRO.md` Fase 3 / P-3d (8 hari-orang), tugas T3d.0–T3d.4
**Status:** selesai di cabang — **belum di-merge, belum di-deploy** (keduanya langkah pemilik;
deploy dilarang untuk agen di alur kerja ini).

---

## 0. Satu kalimat

Paket ini seluruhnya tentang **janji kepada sistem lain**, dan keputusan besarnya adalah **tidak
menjual satu pun kendali yang tidak ada**: sebelum commit pertama, setiap token di aplikasi ini
membawa `["*"]` dan **tidak ada satu pun `tokenCan()` di seluruh kode** (grep = 0), jadi sebuah layar
"abilities = subset izin" di atas dasar itu akan membiarkan token bernama "hanya baca proyek"
**menyetujui pembayaran** — maka penegakannya dipasang lebih dulu, di **satu tempat yang dilewati
setiap pemeriksaan izin** (`App\Models\User::hasPermissionTo()`), dibuktikan dengan matriks 403 yang
menyebut ability yang kurang, dan **batasnya dikatakan apa adanya** di layar, panduan, dan dokumen
OpenAPI (218 dari 862 rute tidak dijaga izin apa pun dan tetap dijangkau token terbatas).
Kedaluwarsa Sanctum yang **punya dua kunci** — plafon global 720 menit yang diam-diam membunuh
`expires_at` per token — diperbaiki tanpa menyentuh `config/sanctum.php` dan **tanpa backfill**,
sehingga tidak satu pun baris produksi menjadi abadi. Webhook keluar menandatangani **byte yang
persis dikirim**, menunggu **commit** dua lapis sebelum berangkat, menolak alamat internal **dua
kali** (menyimpan dan mengirim) dengan redirect yang tidak diikuti, dan mencatat `sent` **hanya**
pada 2xx. OpenAPI dua puluh endpoint ditulis tangan dan dijaga uji anti-drift yang **dibuktikan bisa
memerah pada jalur, metode, dan izin**.

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
| T3d.2 | Penegakan ability di satu tempat + matriks + batas laju 300/menit | ✅ | `377398c` — `ApiTokenAbilityMatrixTest` **9 / 27**, `ApprovalDelegationTokenScopeTest` **4 / 4**, `IntegrationRateLimitTest` **5 / 17**, `UngatedApiRouteCensusTest` **2 / 5**; 5 mutasi merah (§4) |
| T3d.3 | Webhook: migrasi Core 001803, model, service, job, listener, HMAC, SSRF, layar log | ✅ | `7900bd1` — `WebhookDeliveryTest` **10 / 43**, `WebhookGuardTest` **27 / 79**, `WebhookEndpointTest` **10 / 60**; 5 mutasi merah (§4) |
| T3d.4 | OpenAPI tangan 20 endpoint + anti-drift tiga arah | ✅ | `80a9881` — `docs/api/openapi.json`, `OpenApiDriftTest` **5 / 59**; 5 mutasi merah (§4) |
| 5 | Layar Profil › Token API + Sistem › Webhook; `SHELL_VERSION` 11 → 12 | ✅ | `057a078` — `ApiTokenAndWebhookSpaTest` **7 / 57** |
| 6 | Dokumen: PANDUAN-PENGGUNA §20, ADMINISTRATOR §5.14, KEPUTUSAN-INTEGRASI §11, CONVENTIONS §41, ROADMAP, `.env.example` | ✅ | `aebe995` |
| 7 | Harness S40 + S40m → `results-phase-3.json` BERDASARKAN KUNCI | ✅ | `057a078` — **23 syarat** desktop + **8 syarat** ponsel, `console_errors: []` keduanya; 14 kunci lama utuh → **16** |
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
| Ability = subset izin | `ApiTokenStoreRequest` | `ExplainTokenScopeRefusal` (kalimat) | `TokenScope` di `User::hasPermissionTo()` + gerbang delegasi | — | 403 + `errors.token_abilities` | `token-scope-note` | — | `x-autentikasi.ability`, 403 tiap operasi | §20 | S40 (403 sungguhan) |
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

* **Harness S40** (1440×900): **23 syarat**, semuanya hijau, `console_errors: []`, 5 klik.
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

Tangkapan layar: `docs/bukti-uji/s40-token-api.png`, `s40-webhook.png`, `s40m-token-api.png`,
`s40m-webhook.png`.

---

## 8. Gerbang

Diisi dari `/tmp/…/p3d-gate-aebe995.log` (worktree terisolasi, vendor disalin bukan symlink).

---

## 9. Keputusan pemilik

Tidak ada yang menunggu pemilik untuk paket ini berfungsi. Yang **dipakai sebagai rekomendasi
sampai dijawab**: ledger §5 baris 10 (CORS kosong / 300 per menit) dipakai apa adanya, dan angka 20
untuk ambang nonaktif otomatis adalah pilihan paket ini (§2F) yang bisa diubah pemilik.

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
   layar, panduan, dokumen OpenAPI, dan dijaga sensus (§2A, §12.1).
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

## 14. Penyimpangan konvensi yang disengaja

Lihat §12.2 (`ApiToken` menuruni kelas Sanctum) dan §2A (`pushMiddlewareToGroup` alih-alih alias di
`bootstrap/app.php` — dipilih justru **agar** `bootstrap/*` tidak disentuh).

## 15. Putaran verifikasi

Diisi sesi utama.
