# Laporan Paket UI-0 — Prototipe UI, dan tiga cacat yang audit temukan di jalan

Branch: `feat/ui-0-prototipe` (dari main `686d9eb`; 2 commit) · 15 September 2026

> Status jujur: UI-0 **tidak menyentuh satu baris pun** di `public/app/js/`. Ia menghasilkan satu
> berkas prototipe mandiri dan memperbaiki tiga cacat yang berdiri sendiri di `public/app/app.css`
> dan `docs/`. Skala jarak dan tipografi yang diusulkan prototipe **belum masuk** app.css — itu
> UI-1. Kampanye lengkapnya (UI-1…UI-7) belum dikerjakan.

## Latar: mengapa prototipe lebih dulu

Pemilik meminta seluruh tujuh fase dikerjakan, tetapi dengan satu syarat: **prototipe seluruh UI
lebih dulu**, sebagai berkas yang bisa dibuka dan ditinjau, sebelum satu baris aplikasi disentuh.
Dua keputusan lain diambil di sesi yang sama: **metrik baris tabel dibekukan** (Padat 32 · Normal
38,5 · Lega 48 · kaki 41), dan urutan layar khusus Keuangan → Proyek → Pengadaan+Persediaan →
SDM/Layanan/Aset.

## Yang ditutup

| Tugas | Status | Bukti (commit / angka terukur) |
|---|---|---|
| Prototipe seluruh UI, satu berkas, tanpa server/API/CDN | ✅ | `8254dd2`; `docs/prototipe-ui/nusantara-erp-2026.html`, 3.800 baris. Halaman digambar di dalam `<iframe>` supaya `@media` mengukur lebar bingkai — tanpa itu "390 px" hanya mengecilkan kotak tanpa menyalakan satu pun aturan ponsel |
| Dua puluh layar, semua pola BERSAMA punya jawaban rancangan | ✅ | Orientasi 3 · Sumber daya generik 3 · Alur kerja 2 · Domain 5 · Pengaturan & laporan 3 · Masih terbuka 2 · Sistem desain 2 |
| Tiga sakelar peninjauan sungguhan | ✅ | tema (`data-theme`), kepadatan (`data-density`), lebar 1440/1024/768/390 |
| Formulir dokumen sebagai HALAMAN, bukan modal | ✅ | Baris item naik dari **y = 843 px** (modal 960 px, ASESMEN-UX §1.2) ke **y = 754 px** di 1440 **dan** 1024 — di atas lipatan |
| Metrik baris tabel dibekukan dan dibuktikan | ✅ | Diukur di peramban pada prototipe: 32 / 38,5 / 48, kaki Normal 41 — cocok persis dengan kesepakatan harness S21 |
| Hanyutan token blok `@media print` | ✅ | `8d97ff9`; kontras di kertas: `--muted` di `--surface-2` **4,46 → 5,47**, di `--bg` **4,26 → 5,23**, `--success` di `--success-soft` **4,42 → 5,29**. Ketiganya teks kecil (ambang 4,5) dan ketiganya gagal sebelum ini |
| Panah `<select>` yang warnanya dipanggang di data-URI | ✅ | `8d97ff9`; satu token `--chevron` di lima blok palet, dipakai dua kali. Diverifikasi di Chromium: terang `#5e6874`, gelap `#8b95a3`, cetak-dari-gelap dipaksa kembali ke terang |
| CONVENTIONS §17: kisi widget 3 → 4 kolom | ✅ | `8d97ff9`; kode berkata `SPAN = { kecil: 1, sedang: 2, lebar: 4 }` dan `repeat(4, minmax(0, 1fr))`. Sapuan §35 sesudah: **0** penyebutan "kisi tiga kolom" tersisa di `docs/` |

## Verifikasi

**Prototipe** — skrip Playwright tersendiri, dijalankan ulang sesudah setiap suntingan:
20 layar × 4 lebar × 2 tema. Tidak ada gulir menyamping, tidak ada teks terpotong, **nol galat
konsol**, kepadatan tepat 32/38,5/48 dengan kaki 41, kontras lulus AA (terendah 4,72 —
`--warning` di `--warning-soft`), dan **setiap `var(--x)` di berkas resolve** di dokumen hidup
(`--module-accent*` diperiksa di elemen ber-`[data-accent]`, karena ia sengaja tidak ada di
`:root`).

**Angkanya saling menutup.** Bobot WBS berjumlah 100%; bobot × progres = 78,0% terhadap rencana
81,0% (SPI 0,963); biaya terserap 5,13 M terhadap nilai perolehan 5,33 M = CPI 1,040; sisa per
paket menjumlah 1,71 M. Setiap subtotal, marjin dan kolom selisih laba rugi dihitung ulang dari
barisnya. Umur piutang menjumlah 2,70 M ke empat ember dan cocok dengan akun kontrol 1-1210.
Putaran pertama **tidak** menutup — bobot × progres menghasilkan 73% sementara judulnya mengaku
78% — dan itu diperbaiki, bukan dibiarkan: aplikasi ini menolak menggambar `Rp 0` untuk angka yang
gagal dimuat, dan prototipe yang angkanya tidak menutup merusak kebiasaan itu sejak hari pertama.

## Skema yang berubah

Tidak ada. Tidak ada migrasi, tidak ada endpoint, tidak ada perubahan `schema.js`.

## Uji

`tests/Feature/Core` — **1.406 uji, 11.575 asersi, keluar 0** (11 dilewati). Kesebelas lewatan itu
**sudah ada sebelum perubahan ini**, diperiksa dengan menjalankan ulang uji pemaku CSS di atas pohon
yang di-`stash`: 39 uji / 691 asersi hijau di kedua pohon.

Yang paling berisiko dan tetap hijau: `VendorManifestTest` (pemindaian tanpa-CDN atas data-URI yang
dipindah ke token), `PwaManifestTest` (membaca `--surface` per blok tema dengan regex yang berhenti
di `\n}`), `SidebarNavWiringTest` (token aksen dihitung **tepat lima kali**), `ToastToneTest`,
`ChartMigrationTest`, `BoardWiringTest`.

## Yang sengaja tidak dikerjakan

* **Tidak satu baris pun `public/app/js/` disentuh.** Prototipe adalah mockup: tanpa API, tanpa
  router, tanpa lifecycle.
* **Skala jarak (`--space-*`) dan tipografi (`--fs-*`) belum masuk `app.css`** — baru diusulkan dan
  dibuktikan di prototipe. Itu isi UI-1.
* **Enam berkas dengan utang gaya sebaris terbesar tidak dirombak** — `evm.js` (49 objek `style`),
  `custom.js` (48), `tender.js` (38), `project.js` (33), `periods.js` (32), `bankrecon.js` (23).
  Masalahnya bukan rancangan yang hilang melainkan gaya sebaris yang mengalahkan sistem; itu
  pekerjaan UI-2 dan UI-6.
* **Chip filter, seleksi massal, tampilan tersimpan, `detail.groups`, jalan keyboard papan
  kanban — dirancang, belum dibangun.** Semuanya bangunan BARU di `list.js`/`detail.js`/`board.js`,
  bukan penggantian gaya, dan masuk UI-4.
* **Harness Playwright repo (`docs/bukti-uji/harness-playwright.py`) belum ditambahi skenario UI-0.**
  Verifikasi prototipe memakai skrip tersendiri di luar repo, karena yang diperiksa adalah berkas
  statis, bukan SPA yang berjalan. Paket UI berikutnya yang menyentuh `public/app/` **harus**
  menambah skenarionya di sana seperti setiap paket sebelumnya.
* **Belum di-deploy.**

## Deviasi baru yang ditemukan

**Dua klaim audit yang KELIRU, dikoreksi sebelum sempat jadi pekerjaan.** `.boot-failed` dan
`.money-hint` disebut audit sebagai "dipakai tanpa CSS sama sekali". Keduanya bukan cacat: gaya
sebaris layar boot **tidak menyetel warna sama sekali**, jadi teksnya mewarisi
`body { color: var(--text) }` dan tombolnya memakai `color: inherit` — ia sudah mengikuti tema, dan
sebarisnya disengaja karena layar itu harus tergambar justru ketika `app.css` tidak sampai.
`.money-hint` dikirim sebagai `.help.money-hint` dan mewarisi `.field .help`. Keduanya ditulis di
sini supaya paket berikutnya tidak "memperbaikinya".

**Enam jebakan CSS**, semuanya ditemukan dengan mengukur di peramban dan semuanya ditulis sebagai
komentar di tempat kejadian:

| # | Jebakan | Harga yang sempat dibayar |
|---|---|---|
| 1 | Kisi tanpa trek eksplisit disesuaikan ke max-content anaknya, lalu `max-width:100%` mengukur trek yang sudah kebesaran | Kena **dua kali**: modal 960 px di `.overlay`, lembar cetak 640 px di `.main`. Obatnya sama: `grid-template-columns: minmax(0, 1fr)` |
| 2 | Aturan dasar yang ditulis **sesudah** media query-nya sendiri | Menang di semua lebar; tabel baris menembus layar 390 px sementara CSS-nya terbaca benar |
| 3 | Kelas komponen baru menabrak kelas yang sudah ada | `.pick` milik pembangun laporan merestyle `td.pick`, kolom centang di **setiap** tabel |
| 4 | `flex-wrap` yang hilang di kepala kartu | Kepala dengan dua tombol `white-space: nowrap` punya min-content lebih lebar dari ponsel, dan trek grid induknya melar mengikutinya |
| 5 | **`var()` yang tidak terdefinisi tidak menghasilkan galat** — ia jatuh ke nilai AWAL properti | `fill` yang jatuh ke hitam menggambar balok pekat melintang di gantt. Inilah alasan `charts.js` memegang daftar putih token |
| 6 | Margin antar-elemen bocor ke dalam kisi | `.card + .card { margin-top }` menggeser dua kartu pembanding 16 px. `.main` kini memiliki jarak antar-blok sebagai kisi ber-`gap`; **22 tambalan `margin-bottom` sebaris ikut hilang** |

Butir 5 menjadi **gerbang tetap** di verifier prototipe: ia menarik setiap `var(--x)` di berkas dan
memastikan semuanya resolve.

**Satu hal yang harus diketahui pemilik:** `deploy/sync-erp1.sh` mengirim **pohon kerja** apa adanya,
bukan `main`. Selama cabang ini atau hasil gabungannya ter-checkout, deploy akan membawa perbaikan
`app.css` ini — yang memang diinginkan, tetapi sebaiknya menjadi keputusan, bukan kejutan.
