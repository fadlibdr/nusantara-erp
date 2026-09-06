# Laporan Paket P1-B (ROADMAP-HASHMICRO Fase 1) — Penyegaran visual dalam sistem token

Branch: `feat/phase1-b` (dari main `412af99`; 27 commit: 6 bangun + 20 perbaikan verifikasi + laporan) · 5–6 September 2026

> Status jujur: dibangun, diverifikasi adversarial dua lensa, lalu tiga putaran perbaikan/verifikasi
> ulang (20 + 3 + 3 temuan, semuanya FIXED). Tidak ada perubahan skema, tidak ada endpoint baru:
> P1-B hanya menyentuh SPA, token CSS, dua uji pin, dokumen, dan harness. Kepadatan masih di
> `localStorage` — P1-C memindahkannya ke server bersama favorit/terakhir dibuka.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-B → status)

| Tugas | Status | Bukti (commit / angka terukur) |
|---|---|---|
| `--accent-1..8` (+ `-soft`, `-fg`) untuk 14 grup NAV → 8 slot departemen, ΔE tervalidasi | ✅ | `1f4d198`; palet grafik apa adanya GAGAL ΔE ≥ 20 (min 10,2 terang), jadi slot diturunkan dengan pengoptimal yang mempertahankan hue (slot 1 dipaku ke `--primary`): **ΔE2000 min 20,1 terang / 20,9 gelap**; kontras aksen di `--surface` **5,20 terang / 5,41 gelap** (syarat 3:1, dipasang ≥ 5,2 karena remah memakainya sebagai teks), `-fg` di aksen ≥ 5,20, teks aksen di `-soft` ≥ 4,62; matriks 8×8 penuh ada di komentar `app.css` |
| Aksen dipakai HANYA di tempat yang bermakna | ✅ | penanda grup aktif sidebar (bilah inset 3 px), remah modul, kepala beranda modul; token semantik (success/warning/danger) tidak disentuh; mekanisme satu atribut `data-accent` → `--module-accent/-soft/-fg` |
| Blok token `@media print` (blok KELIMA) | ✅ | `f8d5af3`; tanpa itu tema gelap mencetak aksen gelap di kertas putih (slot 7 **1,52:1**); sesudah: min **5,20:1** di kertas, diukur `emulate_media('print')` di empat konteks |
| `--row-h` + kepadatan per pengguna (Padat/Normal/Lega) | ✅ | `c9bbeec`, `95de394`, `f702eee`; baseline diukur SEBELUM token (baris teks 38,5 px, tfoot 41, th 35, nav 31,5) dan 'Normal' dibuat identik — sapuan 20 rute HEAD vs main: **0 baris berbeda**; Padat 32 / Lega 48; `@media (pointer: coarse)` menjaga nav ≥ 36 px dan baris dialog ≥ 40 px |
| Remah roti "Modul › Layar › (Dokumen)" → beranda modul | ✅ | `b5d943c`, `8c68fde`, `eaef2e7`; satu pembuat `public/app/js/crumbs.js` (`#crumbs[data-root]` module\|screen), remah modul beraksen menaut `#/m/<prefix>`, remah layar detail menaut ke daftarnya, remah terakhir `aria-current="page"` di `<nav aria-label="Remah roti">` |
| Beranda modul minimal `#/m/<prefix>` (`views/module.js`) | ✅ | `b5d943c`, `809220f`; kartu = tautan sidebar yang boleh dibuka pemakainya — diukur admin 14 grup (ringkasan 4 … fin 20) dan warehouse@ 6 grup, `#/m/fin` warehouse → empty state; navigasi panah per geometri (0 salah sasaran di 14 beranda × 4 lebar) |
| Empty state berilustrasi (`inbox\|search\|filter\|error\|done`) | ✅ | `ad21925`, `376aa91`, `63040aa`; `js/illustrations.js` 402–521 B per gambar, **0 literal hex** (semua token), 112 px (72 px compact); daftar membedakan "tidak ada hasil pencarian" dari "tidak ada hasil untuk filter ini" + tombol Hapus filter |
| Harness `S21_module_accents_breadcrumb` (+ `_mobile`), S8 di DUA tema | ✅ | `8b732b2`, `1892dab`, `bb3645d`; hasil di `docs/bukti-uji/results-phase-1.json`, 20 PNG `s21-*-p1b.png` |
| Dokumentasi | ✅ | `416b263`, `5215b57`, `369253b`, `a0b677c`: CONVENTIONS §12 Aksen modul / §13 Kepadatan / §14 Empty state, FRONTEND.md, PANDUAN §1.4 & §2.1 |

## Verifikasi adversarial

- **Putaran 1** — lensa a11y/kebenaran (7 temuan, 15 klaim terkonfirmasi) + lensa UX/harness
  (11 temuan, 10 klaim). Yang penting: kepadatan bawaan TIDAK sama dengan sebelum P1-B pada tabel
  ber-`tfoot` (41 → 39 px) → token `--foot-py`; `@media print` tidak menimpa aksen → tema gelap
  tercetak di kertas; S21 jatuh bila keputusan onboarding belum diambil; remah ponsel bertabrakan
  dengan tombol Cari; navigasi panah beranda modul salah kartu bila ada kepala seksi; kepadatan
  di layar sentuh tidak menyentuh nav/dialog; `NavRouteRegistryTest` tidak merah saat sebuah grup
  kehilangan `prefix`. **20 diperbaiki**, verifikasi ulang: 11/11 FIXED (tidak ada regresi).
- **Putaran 2** (`2df967d`, `8c68fde`, `369253b`) — tiga temuan baru: S8 diam-diam mengukur
  DASBOR pada salinan DB hidup (`onboarding_status` NULL) sambil melapor "ok" → keputusan
  onboarding pindah ke `login()` dan `assert_screen()` menjatuhkan layar yang salah; remah ponsel
  KOSONG pada 7 layar di luar NAV → `#crumbs[data-root]` memisahkan rantai berakar modul dari
  yang tidak; teks CONVENTIONS "keempat blok" → lima. Verifikasi ulang: 3/3 FIXED.
- **Putaran 3** (`a0b677c`, `eaef2e7`, `c465463`, `bb3645d`) — tiga temuan baru:
  - komentar `MODULES` di `schema.js` (tempat pengembang menambah slot 9) masih menyebut empat
    blok → lima, dengan alasannya;
  - **daftar dan halaman dokumen berganti subjek**: `#/r/finance/kasbon` berakar `screen`
    ('ERP › Kasbon'; di ponsel 'Kasbon') sementara `#/d/finance/kasbon/1` mengeja 'Keuangan'
    sendiri di `kaskecil.js` → `groupLabelFor()` jatuh ke `moduleFor(RESOURCES[key].module)` dan
    `kaskecil.js` membaca label dari NAV. Diukur admin/Chromium, dua viewport: **sebelum** 14
    daftar berakar `screen` dengan remah pertama 'ERP', 2 ketidakcocokan daftar↔dokumen;
    **sesudah** 0 dan 0. Dipaku `SidebarNavWiringTest::test_every_resource_screen_is_rooted_in_a_real_module`
    (modul setiap resource di luar sidebar wajib prefix grup NAV; tidak ada view yang mengeja
    label grup ke `setCrumbs`) — MERAH pada dua mutasi;
  - **S16 mati** (`StopIteration`) pada salinan DB hidup: satu tagihan vendor, lunas → skenario
    membuat fixture-nya sendiri lewat API di salinan coretan (draf → submit → approve direktur),
    dan bila tidak bisa tercatat `SKIPPED` **beralasan**, bukan "ok"; pelari mencetak SKIPPED.

## Skema yang berubah

Tidak ada migrasi, tidak ada endpoint, tidak ada perubahan izin.

## Uji

- diubah: `SidebarNavWiringTest` (+3: slot aksen & ketiga token di kelima blok; aturan remah ponsel
  ber-`data-root`; setiap layar resource berakar modul nyata) dan `NavRouteRegistryTest` (+2: setiap
  prefix grup NAV punya beranda modul; separuh penolakannya).
- `tests/Feature/Core` + `tests/Feature/Iam` di HEAD: **757 uji / 5.058 asersi hijau** (11 dilewati —
  driver-specific, pra-P1-B). `pint --dirty` bersih.
- harness: S21 desktop 95,1 s / mobile 94,4 s dari salinan DB hidup — **131 rute** ditelusuri,
  `by_root` module 130 / screen 1 (`screen_routes: ['#/dashboard']`, satu remah), **0 kosong,
  0 bertabrakan dengan tombol Cari, 0 lebih tinggi dari header, 131 `aria-current`**; S8 dua tema
  identik dengan sebelum P1-B (th 11 px, muted 5,23:1 terang / 6,24:1 gelap, smallest font 11 px)
  dan kini mencatat layar yang diukurnya (`#/r/procurement/purchase-orders`).
- **suite penuh di commit rilis `bb3645d`** (worktree terpisah): SQLite **3.860 uji / 18.716 asersi, 11 dilewati, hijau** (11 mnt 11 dtk); MySQL 8.0.46 **3.860 uji / 18.736 asersi, 4 dilewati, hijau** (26 mnt 37 dtk).

## Yang sengaja tidak dikerjakan

- Kepadatan masih `localStorage` (kunci `nusantara_erp_density:<id>`) — P1-C memindahkannya ke
  `core_user_preferences` bersama favorit/terakhir dibuka; nama kuncinya ditulis agar P1-C bisa
  memigrasikannya.
- `icon()` lama TIDAK dialihkan ke sprite Lucide (masih glyph inline) — penggantian menyentuh
  setiap layar dan tidak dibutuhkan P1-B.
- Beranda modul belum punya KPI dan "Terakhir dibuka" (P1-C).

## Deviasi baru yang ditemukan

- Menelusuri 131 rute menembus batas 120 permintaan/menit/pengguna di `AppServiceProvider`;
  jalur telusur harness men-stub `/api/**` dan mencatat galat konsol yang berasal dari stub
  secara TERPISAH agar `console_errors` skenario tetap berarti.
- `views/kaskecil.js` menyimpan pembuat remah roti kedua dari sebelum P1-B (tanpa remah modul,
  tanpa `aria-current`) — dilebur ke `crumbs.js`; sejak itu hanya satu berkas memegang `#crumbs`,
  dan itu dipaku uji.
- Dua layar khusus (rekap alat, retensi) melempar bila `/api` menjawab daftar kosong berbentuk
  objek — hanya muncul di bawah stub harness, dicatat, bukan diperbaiki di sini.
