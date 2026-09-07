# Laporan Paket P1-I (ROADMAP-HASHMICRO Fase 1) — PWA

Branch: `feat/phase1-i` (dari `main` 9e1e77a) · 7 September 2026 · **paket terakhir Fase 1**

> Dua berkas statis baru di `public/app/` (`manifest.webmanifest`, `sw.js`), tiga ikon PNG yang
> **dibangkitkan** dari `favicon.svg` yang sudah ada, dan kabel SPA-nya. **Nol migrasi, nol endpoint
> baru, nol dependensi, nol pustaka vendor baru, nol perubahan konfigurasi server.** Push ditunda ke
> Fase 3 sesuai ROADMAP dan tidak disentuh.
>
> Klaim tengah paket ini bukan "aplikasinya bisa dipasang" — itu bagian yang mudah. Klaimnya adalah
> **`/api/*` dan lampiran TIDAK PERNAH masuk cache**, dan klaim itu tidak dinyatakan melainkan
> **diukur**: 102 entri cache, 0 di antaranya `/api/`; 0 dari 18 respons `/api/` yang pernah lewat
> service worker; dan saat jaringan diputus, cangkang tergambar penuh dari `CacheStorage` (76 dari
> 76 entri) **sementara** `fetch('/api/core/dashboard/summary')` melempar `TypeError: Failed to
> fetch`.
>
> **Putaran verifikasi adversarial pertama sudah dijalankan** (dua lensa, 10 temuan, semuanya
> diperbaiki dan dipaku) — lihat § Verifikasi adversarial di bawah.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-I, baris 195 → status)

| Klausa kontrak | Status | Bukti |
|---|---|---|
| `manifest.webmanifest` | ✅ | Chromium CDP `Page.getAppManifest`: `errors: []`, empat ikon terbaca, `Page.getInstallabilityErrors: []` · `PwaManifestTest` (6 uji) |
| `sw.js` network-first untuk cangkang `/app/*` | ✅ | daring: `app.css` diminta ulang → 200, `deliveryType ''` (jaringan), 112.978 byte, `from_service_worker=true` — worker menjawab DAN pergi ke jaringan |
| **`/api/*` & lampiran TIDAK PERNAH di-cache — dipaku uji** | ✅ | `PwaServiceWorkerTest` (9 uji, 141 asersi) + S27: 0 entri `/api/` di cache, 0 respons `/api/` dari worker, 0 entri `/api/` ber-`deliveryType: 'cache-storage'` |
| "Pasang aplikasi" | ⚠️ **sebagian** | baris ada di dialog Akun dengan **tiga** keadaan; tombolnya sendiri **tidak terukur** — `beforeinstallprompt` tidak menyala di Chromium headless (terukur: "tidak menyala" sesudah 4 detik) |
| toast "Versi baru siap — Muat ulang" | ✅ | `S27_pwa_pembaruan`: teks persis, tombol `Muat ulang`, `registration.waiting` benar, **1 navigasi** sesudah klik (bukan 2), cache lama benar-benar dibuang |
| pita luring di atas antrean Lapangan | ✅ | S27 desktop + ponsel: tersembunyi daring → terlihat luring → tersembunyi lagi; selamat melewati muat ulang luring |
| push ditunda ke Fase 3 | ✅ | tidak ada `PushManager`, `pushsubscriptionchange`, VAPID, atau tabel langganan di paket ini |
| **h-o 2,5** | — | lima commit; angka jam tidak diklaim |

## Aturan yang dikirim — rujukan, bukan ringkasan

Worker **menjawab** sebuah permintaan hanya bila SEMUA benar:

1. metodenya `GET`;
2. asalnya sama dengan asal worker;
3. path-nya dimulai dengan lingkup worker, yaitu `/app/`;
4. tidak membawa header `Authorization`;
5. bukan berkas worker itu sendiri (`/app/sw.js`).

Permintaan yang tidak memenuhinya **tidak disentuh sama sekali** — tanpa `respondWith()`, jadi
peramban mengambilnya seolah worker tidak terpasang. Yang boleh **masuk** cache lebih sempit lagi:
hanya jawaban `200` bertipe `basic` (bukan opaque, bukan 206 Range).

**Kenapa daftar izin dan bukan daftar larangan.** `if (path.startsWith('/api/')) return;` terbaca
lebih jelas dan membusuk lebih cepat: endpoint atau awalan berikutnya yang lupa didaftarkan langsung
ikut ter-cache, dan **kegagalannya diam** — tablet lapangan yang dipakai bergantian akan menyajikan
daftar dokumen milik orang sebelumnya tanpa satu pun pesan galat. Karena `/api/`, `/storage/` dan
setiap unduhan lampiran berada **di luar** `/app/`, dengan bentuk daftar izin tidak ada satu jalur
kode pun yang bisa menyimpannya. Uji memaku bentuk itu, bukan sekadar akibatnya: kode `sw.js`
(tanpa komentar, tanpa daftar `SHELL`) **tidak boleh menyebut** `/api`, `storage`, `attachment`,
`lampiran`, `download` atau `unduh` — munculnya daftar larangan di sana adalah kegagalan uji.

**Lingkup dibuktikan, bukan diasumsikan.** Sebuah worker hanya menguasai path di bawah folder
skripnya. `navigator.serviceWorker.getRegistration()` di Chromium melaporkan
`scope: http://127.0.0.1:8071/app/` dan `scriptURL: .../app/sw.js`. nginx sudah melayani `/app/`
dengan `try_files $uri $uri/index.html =404` + `Cache-Control: no-cache`, jadi **nol** baris
konfigurasi server berubah dan `deploy/sync-erp1.sh` sudah menyalin seluruh `public/`.

**`SHELL` adalah daftar dua arah.** 102 lentera = `'./'` + 101 berkas `html/css/js/svg/webmanifest`
di bawah `public/app` (`icons/` tidak masuk — dibaca sistem operasi, bukan halaman; `sw.js` tidak
pernah men-cache dirinya sendiri). Uji menolak baris yang berkasnya hilang **dan** berkas yang tidak
terdaftar. Konsekuensinya sengaja: **setiap layar baru menambah satu baris di `SHELL`**. Tanpa
tripwire itu, sebuah layar yang lupa didaftarkan membuat aplikasi setengah mati saat luring — dan
itu tidak terlihat siapa pun sampai seseorang membuka ponselnya di lokasi tanpa sinyal.

## Warna, dan batas yang jujur

| Nilai | Token | Kenapa |
|---|---|---|
| `<meta theme-color>` terang `#ffffff` | `--surface` terang | bilah peramban duduk **persis di atas** `.header`, dan `.header` berlatar `--surface` — bukan `--bg` |
| `<meta theme-color>` gelap `#151a21` | `--surface` gelap | idem |
| manifest `theme_color` `#1a56db` | `--primary` terang | warna yang sama dengan `rect` di `favicon.svg`; dipakai layar splash **sebelum** dokumen ada |
| manifest `background_color` `#f4f6f8` | `--bg` terang | kanvas splash = latar `body` sebelum aplikasi menggambar |

Batasnya: **manifest tidak punya media query.** Ia hanya boleh punya satu `theme_color` dan satu
`background_color`, jadi keduanya nilai tema terang — pemakai bertema gelap melihat splash terang
lalu aplikasi gelap. Itu tidak bisa diperbaiki di dalam manifest; yang bisa bermedia adalah kedua
`<meta>`, dan itulah yang dipasang. `PwaManifestTest` membaca `app.css` dan menuntut keempat nilai
di atas sama persis dengan tokennya, jadi penyetelan token berikutnya tidak bisa menghanyutkannya
diam-diam.

## Ikon — dibangkitkan, bukan digambar ulang, bukan placeholder

Tidak ada rasterizer di host ini: `rsvg-convert`, ImageMagick (`convert`/`magick`), Inkscape,
`resvg`, Pillow dan `cairosvg` semuanya **tidak terpasang** (diperiksa 7 Sep 2026), dan paket ini
tidak boleh menambah dependensi. Yang **ada** adalah Chromium milik harness Playwright — mesin yang
sama yang akan menggambar ikon itu. `docs/bukti-uji/buat-ikon-pwa.py` memotretnya dari satu sumber,
`public/app/favicon.svg`, tanpa satu path pun disalin-tempel:

| Berkas | Ukuran | Byte | sha256 (12 pertama) |
|---|---|---|---|
| `icons/icon-192.png` | 192×192, latar transparan, `purpose: any` | 2.696 | `437bb6af10cf` |
| `icons/icon-512.png` | 512×512, latar transparan, `purpose: any` | 7.956 | `78f74ff92c48` |
| `icons/icon-maskable-512.png` | 512×512, latar `#1a56db` penuh bidang, `purpose: maskable` | 4.944 | `933bb63fc411` |

Ikon maskable memperkecil lambang ke 78 % dan memusatkannya pada **kotak batasnya sendiri**, bukan
pada viewBox: lambang `favicon.svg` tidak simetris (kotak batas x 7..25, y 10,5..23 → pusat
(16 · 16,75)). Setengah diagonalnya pada skala itu 137 px terhadap jari-jari zona aman 205 px.
`PwaManifestTest` membaca chunk IHDR tiap PNG dan menuntut ukuran yang **diumumkan** manifest sama
dengan ukuran berkasnya — manifest yang menulis `512x512` di atas berkas 192 px tidak pernah
terlihat sampai ikonnya buram di layar utama orang lain.

## Satu jalan buntu yang hanya terlihat karena luring akhirnya bisa diukur

Sebelum paket ini, **muat ulang tanpa jaringan melempar orang yang sudah masuk ke halaman masuk**
berbunyi *"Tidak dapat menghubungi server. Coba masuk kembali."* — halaman yang, tanpa jaringan,
tidak bisa dilewati: `POST iam/auth/login` butuh server. Diukur 7 Sep 2026 pada build sebelum
perbaikan: cangkang tergambar dari cache (2.544 karakter HTML) tetapi `has_shell: false`,
`has_login: true`. Layar `Lapangan` beserta antrean fotonya — dan pita luring yang paket ini
bangun — berada di seberang jalan buntu itu.

`init()` sekarang membedakan `status 0` (transport gagal) dari `401`: dengan sesi yang masih
tersimpan ia `boot()` dari cermin `localStorage` dan berkata **"Mode luring"**. `401` tetap membuang
sesi, dan permintaan pertama yang dijawab `401` setelah sinyal kembali tetap melempar keluar lewat
`setUnauthorizedHandler`. Tidak ada data yang belum dimiliki peramban itu yang terbuka karenanya.
Sesudah perbaikan, diukur sama: `has_shell: true`, 17.396 karakter, judul
*"Lapangan · Nusantara ERP"*. (Diukur ulang sesudah putaran verifikasi: **21.455 karakter** —
pengawas boot sebaris di `index.html` ikut terhitung.)

## Uji

`tests/Feature/Core/PwaServiceWorkerTest` — **12 uji, 152 asersi** ·
`tests/Feature/Core/PwaManifestTest` — 6 uji, 51 asersi. (Tiga uji dan pemakuan bentuknya berasal
dari verifikasi adversarial; lihat § di bawah.)

**Merah dulu, dibuktikan dengan sepuluh mutasi** (dijalankan atas pohon kerja lalu dipulihkan,
7 Sep 2026). **Kesepuluhnya MERAH; nol yang lolos:**

| # | Mutasi | Yang ditangkap |
|---|---|---|
| m1 | penjaga `startsWith(SCOPE)` dihapus | syarat 3 daftar izin |
| m2 | `SHELL` menunjuk `js/views/belumada.js` | baris tanpa berkas |
| m3 | `SHELL` kehilangan `js/views/lapangan.js` | berkas tanpa baris |
| m4 | `storable()` lupa `type === 'basic'` | jawaban opaque bisa masuk cache |
| m5 | `register('workers/sw.js')` | path/lingkup meleset — diam di produksi |
| m6 | manifest mengumumkan `512x512` atas berkas 192 px | ukuran ikon berbohong |
| m7 | `theme-color` gelap digeser ke `#000000` | hanyut dari token `--surface` |
| m8 | daftar larangan `startsWith('/api/')` diselipkan | bentuk aturan berubah |
| m9 | `SHELL` memuat `'/api/core/health'` | lubang lewat DAFTAR, bukan lewat fetch |
| m10 | pendengar `fetch` kedua ber-`respondWith` tanpa gerbang | gerbang kedua |

Sepuluh mutasi itu tidak cukup: verifikasi menjalankan **enam** mutasi lain dan **empat**
di antaranya lolos HIJAU — semuanya kebocoran cache sungguhan. Uji sekarang membandingkan **bentuk**
(badan `shellRequest()` dan `storable()` utuh, tulisan cache sebagai pola `\w+.put(`, daftar
pendengar tepat empat), dan keenam mutasi itu MERAH.

`tests/Feature/Core` hijau sesudah perbaikan: **849 uji, 6.744 asersi, 11 dilewati, 165,9 detik.**

## Harness — S27, lima skenario, 81 syarat, semuanya hijau

Dijalankan 7 Sep 2026 (`php -S`, salinan coretan basis data demo — port 8071 saat dibangun,
8074 saat diverifikasi ulang), masuk sebagai `teknisi@nusantara.test`. Hasil digabung ke
`docs/bukti-uji/results-phase-1.json` (25 → **30** kunci); **17** tangkapan layar `s27-*.png`.

| Skenario | Syarat | Waktu |
|---|---|---|
| `S27_pwa` (1440×900) | 26 | 35,5 s |
| `S27_pwa_mobile` (390×844) | 26 | 35,0 s |
| `S27_pwa_pembaruan` | 10 | 18,2 s |
| `S27_pwa_cangkang_sebagian` | 9 | 29,5 s |
| `S27_pwa_pasang` | 10 | 6,6 s |

**`S27_pwa`** dan **`S27_pwa_mobile`** — angkanya identik kecuali jumlah respons API (18 vs 15):

| Yang diukur | Angka |
|---|---|
| `scope` / `scriptURL` | `…/app/` · `…/app/sw.js`, halaman dikuasai |
| cache | satu, `nusantara-shell-v1`, **102** entri |
| entri `/api/` di cache | **0** |
| entri di luar `/app/` di cache | **0** |
| daring, `app.css` diminta ulang | 200 · `deliveryType ''` · 112.978 byte · `from_service_worker=true` |
| luring, cangkang | `.shell` ada · 18 tautan nav · 0 formulir masuk · 21.455 karakter · **76 dari 76** entri `/app/` ber-`deliveryType: 'cache-storage'` |
| luring, `/api/core/dashboard/summary` | **melempar** `TypeError: Failed to fetch` |
| entri `/api/` ber-`cache-storage` | **0 dari 6** |
| respons `/api/` dari worker (sisi Playwright) | **0 dari 18** (desktop) · **0 dari 15** (ponsel) |
| pita | tersembunyi daring → terlihat luring → tersembunyi lagi; terlihat lagi sesudah muat ulang luring |
| pita di balik portal (onLine `true`, `/api/**` digugurkan, sesudah satu peristiwa `online`) | **terlihat** — dan padam lagi begitu permintaan sampai |
| kalimat pita | antrean kosong → tidak menyebut tombol, **0** tombol "Kirim ulang" di halaman; satu foto di antrean → menunjuk barisnya, **1** tombol |
| toast "Mode luring" sesudah tersambung lagi | hilang, diganti *"Kembali daring. Izin dan menu disegarkan…"* |

`deliveryType` adalah pembeda yang membuat klaim ini bisa dibaca alih-alih diyakini: `'cache'` =
cache HTTP peramban, `''` = jaringan, `'cache-storage'` = CacheStorage, **satu-satunya** yang bisa
diisi service worker.

**`S27_pwa_pembaruan` (10 syarat)** menaikkan `SHELL_VERSION` **di berkasnya** — dua kali, lalu
memulihkannya di `finally` — peramban membandingkan **byte** `sw.js`, jadi tidak ada cara lain
memunculkan worker yang menunggu. Terukur: 0 toast pada pemasangan pertama; sesudah
`registration.update()` toast berbunyi *"Versi baru siap — Muat ulang untuk memakainya."* dengan
tombol `Muat ulang` dan `registration.waiting` benar; **satu klik = 1 navigasi**; cache berganti
`['nusantara-shell-v1']` → `['nusantara-shell-v1-uji2']` (yang lama benar-benar dibuang); 0 toast
tersisa; halaman tetap dikuasai; `sw.js` identik byte demi byte dengan sebelumnya. **Rilis KEDUA di
tab yang sama tetap menyisakan satu toast**, dan tombolnya memasang versi terbaru — sebelum
verifikasi ia menyisakan dua toast identik yang permanen.

**`S27_pwa_cangkang_sebagian` (9 syarat)** membatasi kuota origin ke 1,2 MB lewat CDP dan membawa
jalan **kendali** tanpa batas itu di konteks yang sama: kendali memasang **102** entri, yang
berkuota **0** (cangkang tidak lengkap dibuang), aplikasinya tetap jalan daring (`h1` "Lapangan"),
dan muat ulang luring jatuh ke halaman galat peramban alih-alih pemutar boot abadi. Jalan kendali
itu perlu karena `navigator.storage.estimate()` **tidak** melaporkan kuota yang ditimpa CDP (tetap
4,3 GB sementara install-nya nyata-nyata terpotong). Bagian keduanya menggugurkan satu modul
cangkang: pengawas boot `index.html` menggambar kalimat + tombol `Muat ulang`, dan kalimatnya
berganti ketika perangkatnya luring.

**`S27_pwa_pasang` (10 syarat)** mengirim `beforeinstallprompt` sendiri (ia tidak menyala di
Chromium headless) dan untuk pertama kalinya benar-benar **menekan** tombolnya: `preventDefault`,
tombol tergambar, `prompt()` terpanggil tepat sekali, tombol mengunci diri, lalu kalimat yang
dibaca orangnya sesudahnya, dan keadaan "sudah terpasang" lewat peristiwa `appinstalled`.

**Urutan pita sengaja tanpa muat ulang di antara putus dan sambung.** Emulasi luring Playwright
hilang saat dokumen baru dibuat: sesudah `reload()`, `navigator.onLine` kembali `true` meski
jaringannya masih terputus (terukur 7 Sep 2026), sehingga peristiwa `online` yang memadamkan pita
tidak akan pernah menyala. Yang diukur adalah kontrak aplikasinya, bukan artefak alatnya.

## Deviasi

1. **Toast berbunyi "Versi baru siap — Muat ulang untuk memakainya."**, bukan persis
   *"Versi baru siap — Muat ulang"*. Kalimat kontrak ada utuh sebagai awalannya; empat kata
   terakhir ditambahkan karena tombol aksinya sendiri sudah berbunyi `Muat ulang`, dan pesan yang
   berhenti di kata itu terbaca seperti perintah tanpa akibat.
2. **`toast()` bertambah satu opsi**, `action: { label, onClick }`. Sebelumnya pemanggil menempel
   tombolnya sendiri ke `.msg` (`offerDrafts`) — dua tempat yang harus sepakat soal tata letak.
   Ini pengurangan, bukan penambahan: satu toast, satu bentuk.
3. **`api.js` mengumumkan `erp:network`** dari ketiga transport. Peristiwa window, bukan impor,
   supaya `ui.js` tidak perlu mengimpor `api.js` — pola yang sama dengan `erp:prefs-loaded`.
4. **`init()` berubah perilaku saat luring** (§ jalan buntu di atas). Perubahan sungguhan, di luar
   daftar T1I.1–T1I.6, dan tanpanya keluaran utama paket ini tidak bisa dicapai sesudah muat ulang.
5. **Tiga ikon PNG masuk repositori** sebagai berkas biner. Provenansinya adalah skrip yang ikut
   dikirim (`docs/bukti-uji/buat-ikon-pwa.py`), sha256-nya ada di laporan ini, dan ukurannya dipaku
   uji. Alternatifnya — mengirim SVG saja dengan `purpose: "any maskable"` — akan membuat peluncur
   Android memotong lambang penuh bidang menjadi lingkaran.
6. **`<link rel="apple-touch-icon">` dipasang tanpa bukti.** Satu baris, karena iOS tidak membaca
   `icons[]` manifest untuk "Tambahkan ke Layar Utama"; tidak ada perangkat iOS di sini.

## Verifikasi adversarial — putaran pertama (7 Sep 2026)

Dua lensa: **i-cache** (service worker sebagai permukaan keamanan) dan **i-ux** (orang yang
memakainya, luring dan di ponsel). **Sepuluh temuan, sepuluh diperbaiki**, masing-masing dipaku uji
atau syarat harness yang terbukti MERAH tanpa perbaikannya.

| # | Temuan | Berat | Perbaikan | Pakunya |
|---|---|---|---|---|
| i-cache-1 / i-ux-1 | pita luring padam selamanya di balik portal Wi-Fi: `api.js` dan `ui.js` memegang dua salinan satu keadaan, dan `online` hanya melupakan salah satunya | RUSAK | `api.js` ikut menyetel ulang ingatannya pada `online` | S27 `ribbon_shown_behind_captive_portal` |
| i-cache-2 | uji `sw.js` menghitung EJAAN: empat mutasi yang benar-benar membocorkan cache lolos hijau | KURANG | bentuk dibandingkan utuh; tulisan cache dihitung sebagai pola; daftar pendengar dipaku empat | keenam mutasi kini MERAH |
| i-cache-3 | cangkang setengah (kuota penuh) → pemutar boot abadi saat luring | KURANG | cache tidak lengkap dibuang; pengawas boot sebaris di `index.html` | S27_pwa_cangkang_sebagian (9 syarat) + uji install |
| i-cache-4 | tidak ada cara tertulis mencabut worker — dan menghapus `sw.js` terbukti tidak mencabut apa pun | KURANG | DEPLOYMENT § 2.3 dengan kedua jalan dan angkanya | terukur pada cermin statis (C dan D) |
| i-ux-2 | toast "Mode luring" tidak pernah membetulkan diri, dan `refreshMe()` tidak pernah diulang | KURANG | toast dipegang, sesi disegarkan pada peristiwa jaringan pertama yang berhasil | S27 `offline_boot_toast_clears_itself` |
| i-ux-3 | toast "Versi baru siap" menumpuk satu per rilis | KURANG | satu simpul di tingkat modul; yang lama dibuang | S27_pwa_pembaruan `a_second_release_does_not_stack_a_second_toast` |
| i-ux-4 | sesudah tawaran pasang dipakai, dialog Akun berkata "belum menawarkannya" | KOSMETIK | empat keadaan, `appinstalled` diingat, kalimat menyebut jalan iPhone | S27_pwa_pasang (10 syarat) |
| i-ux-5 | kunjungan kedua membayar 76 `caches.open` di jalur cat pertama | KURANG | `fetch()` dimulai sebelum cache dibuka | uji urutan + A/B 14 putaran |
| i-ux-6 | pita menyuruh menekan tombol yang tidak ada di layar saat antrean kosong | KOSMETIK | dua kalimat, dipilih dari isi antrean | S27 `empty_queue_ribbon_names_no_button` |

**Angka yang berubah karena putaran ini** — kunjungan kedua (worker menguasai halaman), 14 putaran
per varian yang **diselang-seling** supaya drift mesin mengenai keduanya: cat pertama median
**268 ms → 192 ms**, `loadEventEnd` **338,5 ms → 253,5 ms**, permintaan cangkang terakhir selesai
**334,5 ms → 251,5 ms**. Ini menutup sebagian butir 10 daftar di bawah: biaya per-MUAT sudah punya
angka; biaya install 102 berkas di 4G satu bar tetap tidak punya.

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Putaran verifikasi adversarial KEDUA belum dijalankan.** Putaran pertama (di atas) menemukan
   10 cacat, dua di antaranya RUSAK; P1-F menemukan 20 pada putaran pertama dan tiga residu pada
   putaran kedua. Tidak ada alasan menganggap putaran kedua di sini akan kosong.
2. **Suite penuh dan suite MySQL belum dijalankan** — hanya `tests/Feature/Core` (846 hijau).
   Paket ini tidak menyentuh satu baris PHP produksi pun (hanya uji baru), jadi risikonya rendah,
   tetapi "rendah" bukan "diukur".
3. **Worker belum pernah dijalankan di belakang nginx.** Seluruh pengukuran memakai `php -S`, yang
   **tidak** mengirim `Cache-Control` dan **mengenal** `.webmanifest` (`application/manifest+json`).
   Produksi berbeda pada keduanya: nginx mengirim `no-cache` (revalidasi — seharusnya justru lebih
   baik) dan **tidak** mengenal `.webmanifest`, jadi manifest dilayani sebagai
   `application/octet-stream`. Yang **sudah** diukur: Chromium tetap mem-parsingnya dengan 0 galat
   ketika content-type ditulis ulang menjadi `application/octet-stream`. Yang **belum**: satu muat
   sungguhan di `https://erp1.pi2.co.id/app/` sesudah deploy.
4. **Tombol "Pasang aplikasi" sekarang ditekan — tetapi oleh peristiwa yang DIKIRIM SENDIRI.**
   `beforeinstallprompt` tetap tidak menyala di Chromium headless (diukur ulang: "tidak menyala"
   sesudah 4 detik), jadi `S27_pwa_pasang` mengirim peristiwanya sendiri dengan `prompt()` dan
   `userChoice` palsu. Yang dijalankan adalah kode yang dikirim — penangkapan, render tombol,
   `prompt()` sekali, tombol mengunci diri, keempat kalimat — tetapi **dialog pemasangan peramban
   yang sesungguhnya belum pernah muncul di sesi ini**, jadi toast *"Aplikasi sedang dipasang."*
   (cabang `outcome === 'accepted'`) masih belum pernah dilihat siapa pun.
5. **iOS belum disentuh sama sekali.** Tidak ada perangkat iOS di host ini. "Tambahkan ke Layar
   Utama", `apple-touch-icon`, dan perilaku standalone Safari semuanya tidak terukur — dan PANDUAN
   §1.4e menyebut langkah iOS-nya tanpa bukti.
6. **Ikon belum pernah dilihat di layar utama sungguhan.** Ukuran, zona aman dan latar transparan
   dihitung; bagaimana peluncur Android/desktop benar-benar memotong dan membingkainya tidak.
7. **Pita luring dipasang HANYA di layar Lapangan** (itu kontraknya). Layar lain yang gagal memuat
   saat luring tetap menampilkan panel galatnya sendiri, tanpa pita. Apakah itu cukup untuk
   pengawas yang membuka Absensi di basement belum ditanyakan ke siapa pun.
8. **Pita tidak menyelidik jaringan sendiri.** Ia padam pada peristiwa `online` atau pada permintaan
   berikutnya yang berhasil — paling lambat polling notifikasi **90 detik**. Butir ini dulu berbunyi
   bahwa pita bisa BERTAHAN terlalu lama di kasus portal; verifikasi mengukur **kebalikannya** —
   pita justru tidak pernah menyala lagi (i-cache-1/i-ux-1, sudah diperbaiki dan dipaku). Sisa yang
   memang belum diukur: berapa lama pita bertahan sesudah jaringan sungguhan pulih **di perangkat
   sungguhan**, dan apakah 90 detik terburuk itu diterima pemakainya. Keduanya belum ditanyakan.
9. **Antrean foto tetap tidak mengirim dirinya sendiri.** `pump()` hanya berjalan saat foto
   dimasukkan atau `Kirim ulang` ditekan; paket ini tidak mengubahnya, dan kalimat pita serta
   PANDUAN §1.4e karena itu menyuruh menekan tombol. Pengiriman otomatis saat sinyal kembali adalah
   pekerjaan F-4 (`uploadqueue.js`), bukan paket ini.
10. **Install worker mengambil 102 berkas.** Di `php -S` loopback itu tidak terasa; di 4G satu bar
    pada rilis pertama sesudah deploy, biayanya **belum diukur**. Ia terjadi sekali per versi, di
    latar belakang, sesudah cat pertama — tetapi angkanya tidak ada.
11. **Berkas yang gagal terpasang tetap tidak terlihat siapa pun di produksi.** Sejak verifikasi,
    akibatnya tidak lagi diam: satu kegagalan membuang seluruh cache (perangkat turun ke "tanpa
    lapisan luring") dan pengawas boot `index.html` menggantikan pemutar dengan kalimat. Tetapi
    tidak ada telemetri: **berapa banyak perangkat yang benar-benar mengalaminya tidak diketahui**,
    dan tidak ada rencana mengukurnya.
12. **Kontras pita di tema gelap tidak diukur ulang.** Ia memakai pasangan `--warning` di atas
    `--warning-soft` yang sama dengan `.alert.warn`, yang sudah divalidasi P1-B/S8; tidak ada
    pengukuran baru di paket ini.

## Gerbang rilis

Belum dijalankan — suite penuh dan MySQL adalah milik orkestrator. Yang sudah hijau sesudah
putaran verifikasi: `tests/Feature/Core` **849 uji / 6.744 asersi** (11 dilewati, 165,9 s),
`pint --dirty` bersih, **lima** skenario harness S27 hijau (**81 syarat**), dan pemeriksaan peramban
wajib — `/app/` dimuat di Chromium pada 1440×900 dan 390×844, **0 galat konsol, 0 permintaan gagal,
formulir masuk tergambar**, worker terdaftar di lingkup `/app/` dengan 102 entri cache, ditambah
sapuan masuk sebagai `admin@nusantara.test` melewati `#/lapangan`, `#/home`, `#/dashboard` dan
`#/r/procurement/purchase-orders` — **0 galat konsol, 0 permintaan gagal**.

**Catatan fixture (bukan cacat kode, bukan milik paket ini).** `database/database.sqlite` yang
ikut repositori tertinggal dua migrasi dari produksi: ia belum punya `core_user_preferences`,
sehingga salinan coretannya menjawab **500** pada `GET /api/core/me/preferences` sampai
`php artisan migrate` dijalankan di atas salinan itu. Diperiksa baca-saja: basis data produksi
`/var/www/erp1.pi2.co.id` **punya** tabel itu (migrasi terakhirnya
`2026_09_06_000197_create_core_saved_reports_table`), jadi ini murni fixture repositori yang basi —
tetapi ia membuat sapuan peramban pertama di sesi ini melaporkan satu 500 yang tidak ada
hubungannya dengan PWA.

## Commit

| Commit | Tugas |
|---|---|
| `39e3253` | T1I.1 manifest + ikon + `<link>`/`theme-color` |
| `0163d44` | T1I.2 `sw.js` |
| `396fa34` | T1I.3 kabel SPA (pendaftaran, pasang, toast, pita, boot luring) |
| `fe5b71b` | T1I.4 uji |
| `84d42ac` | T1I.5 harness S27 |
| `5892965` | T1I.6 dokumentasi |
| `9ab8bce` | verifikasi — pita luring di balik portal Wi-Fi (i-cache-1 / i-ux-1) |
| `2a1ffcf` | verifikasi — jaringan dimulai sebelum cache dibuka (i-ux-5) |
| `7032951` | verifikasi — cangkang setengah dibuang + pengawas boot (i-cache-3) |
| `16b194b` | verifikasi — uji memaku bentuk, bukan ejaan (i-cache-2) |
| `d1d7ae2` | verifikasi — toast "Mode luring" membetulkan diri (i-ux-2) |
| `231d8ec` | verifikasi — satu toast "Versi baru siap" (i-ux-3) |
| `8a80a57` | verifikasi — kalimat pita mengikuti isi antrean (i-ux-6) |
| `6ef5fca` | verifikasi — empat keadaan baris "Pasang aplikasi" (i-ux-4) |
| `7493132` | verifikasi — DEPLOYMENT § 2.3 mencabut worker (i-cache-4) |
