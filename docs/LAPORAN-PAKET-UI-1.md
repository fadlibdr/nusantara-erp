# Laporan Paket UI-1 — Fondasi: hierarki token

Branch: `feat/ui-1-fondasi-token` (dari main `c2674c5`) · 15 September 2026

> Status jujur: UI-1 **hanya mendefinisikan** skala dan membersihkan aturan mati. Ia sengaja tidak
> menggerakkan satu piksel pun — dan itu **dibuktikan**, bukan diklaim: nol perbedaan gaya
> terkomputasi pada 25 elemen × 23 properti × 7 lebar × 2 tema, dan S8 identik dengan bukti
> Fase 1 yang tercatat. Memakai token menggantikan nilai yang ada adalah UI-2.

## Yang ditutup

| Tugas | Status | Bukti |
|---|---|---|
| **30 token** skala masuk `:root` | ✅ | 7 jarak (`--space-1..7`) · 14 tipografi (`--fs-*`, `--lh-*`, `--fw-*`, `--tracking-caps`) · 2 radius (`--radius-lg`, `--radius-pill`) · 4 peran permukaan · `--focus-ring` · `--drawer-w`. Semua independen tema → **satu tempat**, bukan lima |
| Peran permukaan sebagai ALIAS, bukan warna baru | ✅ | `--surface-hover: var(--surface-3)` dll. Karena nilainya `var()`, ia mengikuti tema tanpa ditulis ulang di kelima blok palet |
| Cincin fokus ditokenkan | ✅ | `--focus-ring` dipakai di **dua** pemakaian sejati: `input:focus` dan `.combo-input:focus`. `.spotlight` memakai nilai yang sama secara KEBETULAN — ia sorotan panduan, bukan fokus — dan tetap literal |
| `--drawer-w` menggantikan 264 px mentah | ✅ | satu-satunya angka lebar laci di `@media (max-width: 760px)` |
| Dua aturan label identik dilebur | ✅ | `.module-section, .module-recent-title` + `.home-section-title` → satu aturan, tiga selektor. Nol piksel bergerak |
| Tiga aturan mati dibuang | ✅ | `.wbs-indent` (nol pemakai), `.legend i.pt.lg` (nol pemakai), dan `.input` dari daftar selektor medan — tidak satu pun JS memasang `class="input"`, yang ada `el('input', …)` yang membuat ELEMEN |
| `SHELL_VERSION` 15 → 16 | ✅ | dan ia menutup kelalaian: `8d97ff9` mengubah `app.css` tanpa menaikkannya, sehingga tab yang terbuka berhari-hari tidak pernah diberi tahu |

## Verifikasi: membuktikan tidak ada yang bergerak

Sebuah paket yang berjanji "tidak menggerakkan apa pun" harus membuktikannya, bukan menyatakannya.

**Diff gaya terkomputasi.** Halaman uji berisi 25 elemen wakil (medan, combobox, kepala tabel,
kaki tabel, baris item, lencana, tombol, remah, dan kelima aturan label yang disentuh) digambar dua
kali — sekali dengan `app.css` dari `HEAD`, sekali dengan pohon kerja — lalu 23 properti
terkomputasi dibandingkan pada **7 lebar** (1440 · 1180 · 1024 · 900 · 768 · 560 · 390) × **2 tema**.
Hasil: **0 perbedaan**.

Putaran pertama melaporkan 3 perbedaan, semuanya pada cincin fokus dan semuanya pecahan
(`1.40641px` vs `1.40463px`). Sebabnya bukan CSS: `input:focus` membawa
`transition: border-color .12s, box-shadow .12s`, dan sampelnya diambil 30 ms sesudah fokus — di
tengah transisi. Dengan jeda 400 ms ketiganya lenyap. **Alat ukur yang terlalu cepat melaporkan
regresi yang tidak ada.**

**Harness Playwright** (`S8 S21 S21m`, keluar 0, tidak ada `GAGAL:`):

* `S21.density`: `compact_ok` ✓ · `comfortable_ok` ✓ · `normal_equals_baseline` ✓ ·
  `tfoot_normal_41` ✓ — kontrak kepadatan yang dibekukan pemilik tetap utuh.
* `S21.crumb_walk`: `ok` ✓ atas 131 rute, `never_empty` ✓, `all_fit` ✓.
* `S8` **identik dengan `results-phase-1.json` yang tercatat**, kunci per kunci: `th_font` 11px,
  `th_color` rgb(94,104,116), `smallest_font_px` 11, `muted_token` #5e6874, kontras 5,23 / 5,47 /
  5,29, dan `page_head_buttons` yang sama. Termasuk `btn_sm_height: 0`, yang **sudah 0** di bukti
  tercatat — jadi bukan regresi, melainkan sifat layar itu.

Bukti mentah: `docs/bukti-uji/results-ui-1.json`.

**Uji PHP:** `tests/Feature/Core` **1.406 uji / 11.575 asersi**, keluar 0 (11 dilewati, sudah
dilewati sebelum perubahan ini) — angka yang sama persis dengan acuan di `1abddd6`.
`tests/Unit` **630 uji / 2.201 asersi**.

## Skema yang berubah

Tidak ada.

## Yang sengaja tidak dikerjakan

* **Token tidak dipakai menggantikan nilai yang ada.** 873 literal px masih di tempatnya. Itu UI-2,
  dan di situlah piksel boleh bergerak.
* **Dua token sengaja bukan cermin keadaan hari ini**, melainkan target: `--fs-section` 14 px
  (kepala kartu kini 13,5) dan `--fs-kpi` 22 px. Ditandai di komentar supaya UI-2 tahu ia sedang
  mengubah nilai, bukan merapikan.
* **`--form-bar-h` ditunda ke UI-4**, tempat formulir halaman penuhnya benar-benar lahir —
  menambahkannya sekarang hanya menambah token tanpa pemakai.
* **Sebelas aturan label huruf besar TIDAK disatukan.** Lihat deviasi di bawah.
* **`--surface-hover` / `--surface-selected` / `--surface-header` / `--surface-sidebar` belum
  dipakai** — kosakatanya ditetapkan sekarang, pemakaiannya menyertai paket yang menyentuh
  komponennya (UI-2, UI-3).
* **Belum di-deploy.**

## Deviasi baru yang ditemukan

**Klaim audit "enam aturan label kecil huruf besar yang nyaris identik" KELIRU.** Terukur: **13
aturan**, dengan 3 ukuran (11 · 11,5 · 12 px), 3 letter-spacing (.05 · .06 · .07em) dan 3 keadaan
bobot (700 · 600 · tak disetel) — sembilan kombinasi berbeda. Hanya **dua** yang identik
byte-per-byte, dan hanya keduanya yang dilebur di sini. Menyatukan sebelas sisanya adalah perubahan
NILAI yang akan menggerakkan piksel dan menjatuhkan pembandingan S8 — pekerjaan UI-2, bukan UI-1.
Ini klaim audit keliru yang **kelima** yang runtuh saat diukur.

**`SHELL_VERSION` tertinggal satu rilis.** Nilai `'15'` dipasang F-5 (`103f481`), dan satu-satunya
perubahan `app.css` sesudahnya adalah `8d97ff9` — yang tidak menaikkannya. Perbaikan cetak dan panah
`<select>` karena itu tidak pernah diumumkan ke tab yang sudah terbuka. Dinaikkan ke `'16'` di sini,
menutup keduanya sekaligus.

**Snapshot demo tertinggal ~40 migrasi.** `database/database.sqlite` tidak punya kolom
`personal_access_tokens.kind` (ditambahkan P-3d). Melayaninya apa adanya membuat login menjawab
**HTTP 500**, dan harness gagal di `wait_for_selector('nav.nav')` — pesan yang tidak menyebut
sebabnya sama sekali. **Salinan coretan harus dimigrasi sebelum dilayani.** Biayanya satu putaran
harness penuh.

Dua jebakan alat yang ikut tercatat: `pkill -f '127.0.0.1:8099'` mencocokkan **baris perintah shell
sendiri** dan membunuh shell-nya (matikan lewat port); dan `python … | tail` membuat `$?` menjadi
status `tail`, bukan status harness.
