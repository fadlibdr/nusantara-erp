# Laporan Paket P1-A (ROADMAP-HASHMICRO Fase 1) — Fondasi vendor + `charts.js`

Branch: `feat/phase1-a` (dari main `6b68eda`; 38 commit: 4 bangun + 33 perbaikan verifikasi + 1 laporan) · 5 September 2026

> Status jujur: dibangun, diverifikasi adversarial dua lensa lalu tiga putaran perbaikan/verifikasi
> ulang (23 + 10 + 3 temuan; semuanya FIXED kecuali dua residu LOW yang dicatat di bawah), suite
> penuh di commit rilis lihat § Uji. Tidak ada perubahan skema, tidak ada endpoint baru; tidak ada
> layar yang memakai `charts.js`/SortableJS hari ini — pemakainya adalah P1-D/E/G/H.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-A → status)

| Tugas | Status | Bukti |
|---|---|---|
| Vendor `public/app/vendor/sortablejs@1.15.7/` (Sortable.min.js 45.478 B + LICENSE MIT) dan `lucide@1.41.0/` (sprite.svg 79 simbol 23.271 B + LICENSE ISC), `VENDOR.md` (sha256 tarball & per-berkas, lisensi, ukuran gzip, resep pembaruan) | ✅ | `e6df82b`; tarball diverifikasi terhadap `dist.integrity`/shasum registry npm sebelum ekstraksi; total gzip **21.257 B** (batas 61.440) |
| `ui.js svgIcon(name,{size})` + `LUCIDE_SPRITE` (pemanggil `icon()` lama tidak diubah) | ✅ | `e6df82b`; nama kanonik Lucide 1.41 (`triangle-alert`, `chart-bar`, …) — `svgIcon` memetakan nama lama `icon()` |
| `VendorManifestTest` — aturan tanpa-CDN menjadi uji: (a) setiap berkas vendor ada di manifest dengan sha256 kini; (b) tidak ada pemuat eksternal di `public/app` (*.html/htm/xhtml/js/mjs/css/svg/webmanifest/json: `<script src>`, `<link href>`, `import`/`import()`/`fetch`/`new URL`, `@import`/`url()`, `srcset` per kandidat, `el('link'|'script'|'img'|…)`); (c) gzip vendor ≤ 60 KB; (d) sprite XML valid, setiap `<symbol>` ber-id `lucide-` + viewBox; (e) Sortable.min.js identik dengan sha manifest | ✅ | `e6df82b`, `44f182e`, `9aea85a`, `c9280ea`, `e7f3b9b`, `df954fa`, `641b135`, `95bbd7d`; setiap asersi dibuktikan MERAH dulu pada salinan coretan lewat env `SPA_ROOT` (30+ mutasi: CDN `<script>`, `//cdn` protokol-relatif, `import(\`//…\`)`, `srcset` kandidat ke-2, byte Sortable diubah, berkas tak terdaftar, simbol tanpa viewBox, blob 70 KB) |
| `public/app/js/charts.js` — `lineChart`, `barChart`, `donutChart`, `sparkline`, `ganttChart` (fungsi murni → `<svg>`; warna hanya lewat token `--chart-1..8`, `--chart-grid/-axis/-text/-today/-weekend/-baseline`; `<title>` per tanda; kosong → "Belum ada data"; NaN/null = celah; titik tunggal = dot; negatif melewati nol; donat satu irisan = cincin penuh; gantt: garis hari ini, akhir pekan, baseline di bawah bar, bar terbuka bergaris putus + `<title>`; klip ke plot; legenda satu model kotak) | ✅ | `26ecdb3` + 20 perbaikan (mis. `70ea2b3` lebar batang negatif ≥ 481 batang, `838fed2` irisan ≥ 99,999 % hilang, `60158ff` klip yMin/yMax, `e51be6f` model kotak label sumbu tanggal, `78e9370`/`7eb92a5` donat 360 + pembungkusan per kelas glyph) |
| Token `--chart-*` di `app.css` untuk dua tema + blok cetak (abu-abu berjarak geometris, pola garis/tepi per seri) | ✅ | `26ecdb3`, `289ffe8`; kontras seri vs `--surface` terukur: terang 4,92–7,58:1, gelap 5,41–11,41:1; cetak min 3,07:1 vs kertas, tetangga ≥ 1,74:1 |
| Harness `S20_chart_tokens` + `S20_chart_tokens_mobile` (1440×900 / 390×844, terang & gelap), `docs/bukti-uji/results-phase-1.json` + 4 PNG | ✅ | `6e9fff6` → `93c2204`/`7eb92a5`: 41 grafik fixture, 1.373 bentuk ber-token **0 selisih** di 2 tema × 2 viewport, 887 `.mark` = 887 `<title>`, 0 title liar, 0 atribut negatif, 0 console.error, 0 geometri di luar viewBox, `x_label_overlaps` 0 (sebelum perbaikan: 4 tumpukan / 53,7 px), S8 identik |
| Dokumentasi: CONVENTIONS §10 Pustaka vendor, §11 Grafik; FRONTEND.md; README | ✅ | `2307539` |

## Verifikasi adversarial

- **Putaran 1** (dua lensa: kebenaran/kejujuran 11 temuan + 14 klaim terkonfirmasi; UX/harness 12
  temuan + 12 klaim): 4 BREAKS (lebar batang negatif → grafik kosong; irisan donat ≥ 99,999 %
  runtuh; legenda dan sumber melukis di luar svg; yMin/yMax tidak memotong), 11 INCOMPLETE, 5
  COSMETIC, 3 DESIGN. 20 diperbaiki (`70ea2b3`…`b91cbe0`), verifikasi ulang: 19 FIXED, **1 REGRESI**
  (`ux-8`: dua label sumbu tanggal terakhir bertumpuk 17–54 px) + 9 temuan baru.
- **Putaran 2** (`e51be6f`…`93c2204`): regresi diperbaiki dengan satu model kotak label
  (`xLabelBox` dipakai `xTickLabel` dan `thin()`), dibuktikan pada 3.975 kasus kepadatan × lebar
  × format (0 tumpukan; berkas lama 1.407 kasus buruk); 9 temuan baru FIXED; 3 residu.
- **Putaran 3** (`641b135`, `95bbd7d`, `7eb92a5`): `srcset` kandidat ke-N, pemindai `elContext`
  bersepadan-kurung menggantikan regex `el('a'…)`, legenda donat per kelas glyph terukur
  (`getBBox` 40× per glyph; rasio terukur/estimasi maks 1,004) + `clipPath` sebagai jaring terakhir
  — semuanya FIXED.
- **Residu LOW yang diterima (dicatat, bukan diperbaiki):** (1) ekspresi template-literal di
  dalam nilai `srcset` (`\`a.png 1x ${await import('https://…')}\``) lolos pemindai — bentuk yang
  tidak ada di kode dan tidak masuk akal ditulis; (2) `el('a', {…})` multi-baris dengan `href`
  di barisnya sendiri DITOLAK (konservatif; sama seperti sebelum perbaikan) — bila muncul, tulis
  di satu baris atau tambah aturan sadar di `isDataLiteral()`.

## Keputusan/pertanyaan untuk pemilik (ledger §5 tidak diubah — tidak ada keputusan baru yang memaksa)

- **P1-E "diff piksel ≤ 2 %" tidak tercapai secara konstruksi** dengan `charts.js` (verifier
  `ux-5`): tiga grafik tangan akan digambar ulang dengan API yang kini menampung fiturnya
  (`series.dash/token/dots`, `points[].title/r`, `yMin/yMax` untuk aturan EVM > 100 %, `sourceNote`)
  — usul: kriteria P1-E diganti "tidak ada fitur hilang (daftar per grafik) + harness S20 hijau",
  bukan diff piksel.
- Tipografi berskala viewBox: grafik 720 lebar merender teks 5,5 px di viewport 390 (perilaku
  yang sama dengan grafik tangan lama). `charts.js` menerima `width` per pemanggil; P1-D/E
  memilih lebar per viewport (fixture `line_dates_w358` membuktikan 11 px pada 358).
- `--chart-baseline` sengaja < 3:1 (2,56 terang / 2,31 gelap) karena isian lebar di bawah bar,
  bukan garis tipis.
- Pola cetak per indeks seri menimpa `dash` semantik pemanggil di kertas (dipertahankan agar
  seri di kertas selalu berbeda bentuk).

## Skema yang berubah

Tidak ada migrasi, tidak ada endpoint, tidak ada perubahan permission.

## Uji

- baru: `tests/Feature/Core/VendorManifestTest.php` (6 uji / 282 asersi; env `SPA_ROOT` untuk
  salinan coretan). `tests/Feature/Core`: 694 uji / 4.475 asersi hijau (11 dilewati — MySQL/deploy).
- harness: S20 desktop + mobile, S8 dijalankan ulang identik (th 11 px, muted 5,23:1, badge 5,29:1);
  bukti di `docs/bukti-uji/results-phase-1.json` dan `s20-chart-tokens-{light,dark,mobile}-p1a.png`.
- **suite penuh di commit rilis `7eb92a5`** (worktree terpisah): SQLite **3.854 uji / 18.590 asersi, 11 dilewati, hijau** (9 mnt 14 dtk); MySQL 8.0.46 **3.854 uji / 18.610 asersi, 4 dilewati, hijau** (21 mnt 36 dtk).

## Dokumentasi yang diperbarui

`docs/CONVENTIONS.md` §10 (aturan vendor, manifest, uji, cara memperbarui) dan §11 (API grafik,
token, aturan `<title>`, pengecualian label gantt terpotong), `docs/FRONTEND.md`, `README.md`,
`public/app/vendor/VENDOR.md` (referensi), docblock `charts.js` (referensi API).

## Yang sengaja tidak dikerjakan

- `icon()` lama tidak dialihkan ke sprite (P1-B memutuskan setelah harness membuktikan tanpa regresi).
- SortableJS belum dimuat layar mana pun (P1-D/P1-G memuatnya lazy).
- Tidak ada uji unit JS (tidak ada node di erp1; harness Playwright adalah bukti UI — keputusan
  platform #1).

## Deviasi baru yang ditemukan

- Estimasi lebar teks berbasis `CHAR_W` tunggal tidak cukup untuk huruf besar/angka (7,3–9,2
  px/glyph pada font fallback host) — model per kelas glyph dipakai di legenda donat; sumbu x
  memakai penjangkaran tepi + celah 6 px (dicatat di docblock, bukan diklaim sebagai batas atas).
- `getComputedStyle` tidak membedakan `--chart-text` dari `--muted` (nilai sama) — pemeriksaan
  harness diperketat dengan `font-variant-numeric: tabular-nums` yang hanya diset `.chart-lib`.
