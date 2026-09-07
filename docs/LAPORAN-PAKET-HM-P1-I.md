# Laporan Paket P1-I (ROADMAP-HASHMICRO Fase 1) — PWA

Branch: `feat/phase1-i` (dari `main` 9e1e77a) · 7 September 2026 · **paket terakhir Fase 1**

> Dua berkas statis baru di `public/app/` (`manifest.webmanifest`, `sw.js`), tiga ikon PNG yang
> **dibangkitkan** dari `favicon.svg` yang sudah ada, dan kabel SPA-nya. **Nol migrasi, nol endpoint
> baru, nol dependensi, nol pustaka vendor baru, nol perubahan konfigurasi server.** Push ditunda ke
> Fase 3 sesuai ROADMAP dan tidak disentuh.
>
> Klaim tengah paket ini bukan "aplikasinya bisa dipasang" — itu bagian yang mudah. Klaimnya adalah
> **`/api/*` dan lampiran TIDAK PERNAH masuk cache**, dan klaim itu tidak dinyatakan melainkan
> **diukur**: 102 entri cache, 0 di antaranya `/api/`; 0 dari 12 respons `/api/` yang pernah lewat
> service worker; dan saat jaringan diputus, cangkang tergambar penuh dari `CacheStorage` (76 dari
> 76 entri) **sementara** `fetch('/api/core/dashboard/summary')` melempar `TypeError: Failed to
> fetch`.
>
> **VERIFIKASI ADVERSARIAL BELUM DIJALANKAN** — lihat § Yang BELUM diverifikasi.

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
*"Lapangan · Nusantara ERP"*.

## Uji

`tests/Feature/Core/PwaServiceWorkerTest` — 9 uji, 141 asersi ·
`tests/Feature/Core/PwaManifestTest` — 6 uji, 51 asersi.

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

`tests/Feature/Core` hijau: **846 uji, 6.733 asersi, 11 dilewati, 155 detik.**

## Harness — S27, tiga skenario, 47 syarat, semuanya hijau

Dijalankan 7 Sep 2026 pada `http://127.0.0.1:8071` (`php -S`, salinan coretan basis data demo),
masuk sebagai `teknisi@nusantara.test`. Hasil digabung ke `docs/bukti-uji/results-phase-1.json`
(25 → 28 kunci); tujuh tangkapan layar `s27-*.png`.

**`S27_pwa` (1440×900, 19 syarat, 20,7 s)** dan **`S27_pwa_mobile` (390×844, 19 syarat, 20,6 s)** —
angkanya identik kecuali jumlah respons API (12 vs 9):

| Yang diukur | Angka |
|---|---|
| `scope` / `scriptURL` | `…/app/` · `…/app/sw.js`, halaman dikuasai |
| cache | satu, `nusantara-shell-v1`, **102** entri |
| entri `/api/` di cache | **0** |
| entri di luar `/app/` di cache | **0** |
| daring, `app.css` diminta ulang | 200 · `deliveryType ''` · 112.978 byte · `from_service_worker=true` |
| luring, cangkang | `.shell` ada · 18 tautan nav · 0 formulir masuk · 17.396 karakter · **76 dari 76** entri `/app/` ber-`deliveryType: 'cache-storage'` |
| luring, `/api/core/dashboard/summary` | **melempar** `TypeError: Failed to fetch` |
| entri `/api/` ber-`cache-storage` | **0 dari 6** |
| respons `/api/` dari worker (sisi Playwright) | **0 dari 12** (desktop) · **0 dari 9** (ponsel) |
| pita | tersembunyi daring → terlihat luring → tersembunyi lagi; terlihat lagi sesudah muat ulang luring |

`deliveryType` adalah pembeda yang membuat klaim ini bisa dibaca alih-alih diyakini: `'cache'` =
cache HTTP peramban, `''` = jaringan, `'cache-storage'` = CacheStorage, **satu-satunya** yang bisa
diisi service worker.

**`S27_pwa_pembaruan` (9 syarat, 14,1 s)** menaikkan `SHELL_VERSION` **di berkasnya** lalu
memulihkannya di `finally` — peramban membandingkan **byte** `sw.js`, jadi tidak ada cara lain
memunculkan worker yang menunggu. Terukur: 0 toast pada pemasangan pertama; sesudah
`registration.update()` toast berbunyi *"Versi baru siap — Muat ulang untuk memakainya."* dengan
tombol `Muat ulang` dan `registration.waiting` benar; **satu klik = 1 navigasi**; cache berganti
`['nusantara-shell-v1']` → `['nusantara-shell-v1-uji']` (yang lama benar-benar dibuang); 0 toast
tersisa; halaman tetap dikuasai; `sw.js` identik byte demi byte dengan sebelumnya.

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

## Yang BELUM diverifikasi — baca ini sebelum merge

1. **Verifikasi adversarial belum dijalankan.** Putaran pertama P1-F menemukan 20 cacat sungguhan;
   putaran P1-H menemukan 18. Paket ini belum melewati satu pun.
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
4. **Tombol "Pasang aplikasi" tidak pernah ditekan.** `beforeinstallprompt` tidak menyala di
   Chromium headless (terukur: "tidak menyala" sesudah 4 detik), jadi yang terukur hanyalah keadaan
   **ketiga** dialog Akun — kalimat yang menyebut jalan lewat menu peramban. Jalur
   `deferred.prompt()` → `userChoice` → toast *"Aplikasi sedang dipasang."* **belum pernah
   dijalankan siapa pun.** Begitu juga keadaan **kedua** ("sudah terpasang"), yang bergantung pada
   `display-mode: standalone`.
5. **iOS belum disentuh sama sekali.** Tidak ada perangkat iOS di host ini. "Tambahkan ke Layar
   Utama", `apple-touch-icon`, dan perilaku standalone Safari semuanya tidak terukur — dan PANDUAN
   §1.4e menyebut langkah iOS-nya tanpa bukti.
6. **Ikon belum pernah dilihat di layar utama sungguhan.** Ukuran, zona aman dan latar transparan
   dihitung; bagaimana peluncur Android/desktop benar-benar memotong dan membingkainya tidak.
7. **Pita luring dipasang HANYA di layar Lapangan** (itu kontraknya). Layar lain yang gagal memuat
   saat luring tetap menampilkan panel galatnya sendiri, tanpa pita. Apakah itu cukup untuk
   pengawas yang membuka Absensi di basement belum ditanyakan ke siapa pun.
8. **Pita tidak menyelidik jaringan sendiri.** Ia padam pada peristiwa `online` atau pada permintaan
   berikutnya yang berhasil — paling lambat polling notifikasi **90 detik**. Pada kasus
   "onLine bilang true tetapi paket tidak sampai" (portal Wi-Fi lokasi), pita bisa bertahan sampai
   90 detik sesudah jaringan sebenarnya pulih. Itu pilihan sadar — lalu lintas latar dari ponsel
   berkuota adalah biaya nyata untuk informasi yang akan datang sendiri — tetapi **belum diukur di
   perangkat sungguhan** dan belum ditanyakan ke pemakainya.
9. **Antrean foto tetap tidak mengirim dirinya sendiri.** `pump()` hanya berjalan saat foto
   dimasukkan atau `Kirim ulang` ditekan; paket ini tidak mengubahnya, dan kalimat pita serta
   PANDUAN §1.4e karena itu menyuruh menekan tombol. Pengiriman otomatis saat sinyal kembali adalah
   pekerjaan F-4 (`uploadqueue.js`), bukan paket ini.
10. **Install worker mengambil 102 berkas.** Di `php -S` loopback itu tidak terasa; di 4G satu bar
    pada rilis pertama sesudah deploy, biayanya **belum diukur**. Ia terjadi sekali per versi, di
    latar belakang, sesudah cat pertama — tetapi angkanya tidak ada.
11. **`SHELL` yang dipasang satu per satu (`allSettled`) menelan kegagalan** ke `console.warn`.
    Itu disengaja (satu 404 pada `addAll` menolak seluruh install dan membekukan pembaruan bagi
    semua orang), dan uji dua arah menjaga daftarnya benar di repositori — tetapi di produksi,
    berkas yang gagal terpasang tidak terlihat siapa pun.
12. **Kontras pita di tema gelap tidak diukur ulang.** Ia memakai pasangan `--warning` di atas
    `--warning-soft` yang sama dengan `.alert.warn`, yang sudah divalidasi P1-B/S8; tidak ada
    pengukuran baru di paket ini.

## Gerbang rilis

Belum dijalankan — suite penuh dan MySQL adalah milik orkestrator. Yang sudah hijau di sesi ini:
`tests/Feature/Core` **846 uji / 6.733 asersi** (11 dilewati, 155 s), `pint --dirty` bersih, tiga
skenario harness S27 hijau (47 syarat), dan pemeriksaan peramban wajib — `/app/` dimuat di Chromium,
**0 galat konsol, 0 permintaan gagal, formulir masuk tergambar**, dengan worker terdaftar di lingkup
`/app/`.

## Commit

| Commit | Tugas |
|---|---|
| `39e3253` | T1I.1 manifest + ikon + `<link>`/`theme-color` |
| `0163d44` | T1I.2 `sw.js` |
| `396fa34` | T1I.3 kabel SPA (pendaftaran, pasang, toast, pita, boot luring) |
| `fe5b71b` | T1I.4 uji |
| `84d42ac` | T1I.5 harness S27 |
