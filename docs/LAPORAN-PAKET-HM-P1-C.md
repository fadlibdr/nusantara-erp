# Laporan Paket P1-C (ROADMAP-HASHMICRO Fase 1) — Preferensi pengguna + App launcher + Beranda modul

Branch: `feat/phase1-c` (dari main `f51f18b`; 34 commit: 10 bangun + 10 perbaikan verifikasi + 7 putaran 2 + dokumen) · 6 September 2026

> Status jujur: dibangun, diverifikasi adversarial dua lensa (16 temuan), diperbaiki dan
> diverifikasi ulang (10/10 FIXED), lalu putaran kedua atas tiga temuan susulan DAN empat dari enam
> keputusan DESIGN yang ternyata tidak butuh keputusan pemilik. **Dua keputusan tersisa untuk
> pemilik** — lihat § Keputusan pemilik. Satu migrasi baru (tabel preferensi) + satu migrasi indeks.

## Yang ditutup (ROADMAP-HASHMICRO Fase 1 / P1-C → status)

| Tugas | Status | Bukti (commit / angka terukur) |
|---|---|---|
| `core_user_preferences` + registri whitelist `UserPreferences` (5 kunci, plafon per kunci, plafon keras 16 KB) | ✅ | `57eee6e`; migrasi Core `000195`; kunci: `favorites` 4096 B/50 entri, `recent` 8192 B/20, `density` 64 B, `dashboard.layout` 16384 B (disiapkan P1-D), `launcher.hidden` 512 B/32 |
| `GET`/`PUT api/core/me/preferences[/{key}]` (auth saja — baris milik pemanggil sendiri; whitelist menggantikan gerbang izin) | ✅ | `57eee6e`, `cf7998d`; `meta.keys` mengumumkan `max_bytes` DAN `max_entries` per kunci, bukan hanya plafon keras global |
| Favorit / Terakhir dibuka / kepadatan pindah dari `localStorage` ke server, dengan migrasi sekali jalan | ✅ | `57eee6e`, `6616e58`; harness: tiga kunci P1-B ditanam sebelum masuk → server memegang ketiganya, kunci lokal hilang, boot kedua tidak menulis lagi; favorit terbaca di konteks peramban BARU |
| Registri `ModuleCounts` — satu angka utama per modul, 14 entri berurutan NAV, absen ≠ 0 | ✅ | `21af950`, `1c5e545`; setiap entri `label`/`unit`/`permission`/`tables`/`count`/`why`; tabel hilang → entri absen; kueri gagal → `null` + `Log::warning`, dasbor tidak pernah 500 |
| Blok `modules` di `dashboard/summary` HANYA bila diminta + `GET core/modules` | ✅ | `21af950`, `6360f61`, `bc48bce`; tanpa `?include=modules` bentuk & jumlah permintaan endpoint identik dengan sebelumnya |
| App launcher `#/home` + aturan landing (desktop → dasbor, ponsel → launcher) | ✅ | `fa5b281`, `8d9f338`, `1199c43`; ketuk ke Lapangan di ponsel **3 → 2**; **peran tanpa ubin 2 → 0** (diukur dengan masuk sebagai 12 akun demo) |
| Beranda modul `#/m/<prefix>`: ubin angka, "Terakhir dibuka", bintang favorit per kartu | ✅ | `c2a3475`, `5e0d24c`, `f411e7c` |
| Harness `S22_launcher_truth` (+ `_mobile`, `_roles_with_tiles`) | ✅ | `a33c3f8`, `b1a8d89`; kebenaran KPI dibandingkan dengan endpoint daftar modulnya sendiri (14 filter ditulis di harness): admin 14/14, warehouse@ 6/6, teknisi@ 3/3 cocok di dua viewport |
| Dokumentasi | ✅ | `0954153`, `ea7c22c`, `8856b3c`: CONVENTIONS §15 Preferensi pengguna & §16 Registri ModuleCounts (termasuk hasil EXPLAIN), FRONTEND, PANDUAN §1.4a |

## Verifikasi adversarial

- **Putaran 1** — lensa server (8 temuan, 17 klaim) + lensa UX (8 temuan, 17 klaim). Sepuluh
  diperbaiki, verifikasi ulang **10/10 FIXED** tanpa regresi. Yang penting:
  - aturan landing ponsel mematahkan lembar onboarding ponsel (S19 merah) → langkah pembuka
    menyebut halaman yang benar-benar dipilih aturan landing (`b3f1fe8`);
  - fixture S22 tidak membedakan apa pun untuk 9 dari 14 modul (26 dari 46 perbandingan `0 == 0`)
    → S22 membangun fixture pembeda sendiri (`b1a8d89`);
  - `ModuleCountsTest` tidak bisa membedakan status mana yang dihitung (4 mutasi hijau) → fixture
    memberi 2 baris terhitung + 1 baris per status yang tidak terhitung (`1c5e545`);
  - aturan kejujuran ubin hanya dijaga pemindaian substring (2 regresi perilaku lolos) →
    penjaga mencapai jalur yang menulis angkanya (`c186908`);
  - plafon 16 KB tidak terpaku pada 16384 (menaikkannya tetap hijau) → angka literal (`557fa71`);
  - ubin ber-`—` tanpa keterangan (justru kasus tersering: izin tidak dipegang) → keterangan tetap
    ada, dibaca dari cermin `MODULES[prefix].kpi` (`f411e7c`).
- **Putaran 2** — tiga temuan susulan verifier + empat DESIGN yang ternyata bukan keputusan:
  - keterangan ubin bisa menyebut angka yang SALAH tanpa satu uji pun merah (tiga mutasi hijau)
    → sumbernya dipaku: `fail()` wajib `module.kpi`, `fill()` wajib `entry.label`; **lima mutasi
    merah** (`6d6bc55`);
  - dua perbandingan numerik entri `inv` tidak terpaku → fixture duduk di batas: `qty == min_stock`
    dan saldo negatif pada item ber-`min_stock` 0 (`b891408`);
  - docblock `UserPreferences` menyebut tiga kunci per-entri, kodenya empat (`bdc9359`);
  - **`?include` dicocokkan sebagai substring** → `?include=notmodules` membayar 14 hitungan yang
    tidak diminta siapa pun, dan `?include[]=modules` yang jelas memintanya dijawab tanpa blok;
    kini satu aturan daftar, anggota bukan-skalar dibuang sehingga `?include[][]=modules` tetap
    200 (`bc48bce`);
  - **plafon diukur pada enkode yang berbeda dari yang disimpan**: nilai emoji terukur 16.384 byte
    mendarat 49.144 byte di kolom (rasio 3×, TEXT MySQL berhenti di 65.535) → diukur seperti cast
    `json` menulis (`0a769a7`), dengan oracle = kolomnya sendiri (`ad3b5d2`);
  - **tiga hitungan memindai tabel penuh** (`type=ALL key=NULL`: `qc_ncr.status`,
    `hr_leave_requests.status`, `eng_drawing_submittals(decision, superseded_at)`) — dan sejak P1-C
    ketiganya jalan setiap kali launcher dibuka → indeks (migrasi Core `000196`, hanya indeks,
    berpenjaga) + uji penjaga + catatan EXPLAIN di CONVENTIONS §16 (`8856b3c`);
  - **launcher ponsel satu kolom** setinggi 2.235 px (2,6 layar) untuk menggantikan laci yang
    menampilkan 14 kepala grup sekaligus → dua kolom di bawah 760 px, **1.325 px (−41 %)**, huruf
    tetap 11,5 px, desktop tidak berubah (`1199c43`).

## Keputusan pemilik yang TERSISA (perilaku hari ini dipertahankan)

1. **Jumlah permintaan dasbor naik satu.** `boot()` memanggil `prefs.load()` setiap kali, jadi
   `GET core/me/preferences` ikut pada setiap muat halaman termasuk dasbor (11 → 12; target Fase 1
   ≤ 10). Pilihan: lipat preferensi ke dalam `iam/auth/me` yang sudah diambil (mengubah bentuk
   jawaban endpoint itu), atau catat 12 sebagai baseline baru yang diukur S23.
2. **Angka KPI tidak bisa diklik.** Ubin menaut ke beranda modul yang mengulang angka yang sama,
   lalu menawarkan kartu tanpa filter — pembaca yang ingin memeriksa "PO terbuka 2" sampai di
   daftar PO berisi empat baris. Usulan verifier: tambahkan `link` (rute + query) di setiap entri
   registri dan render ubinnya sebagai tautan, sehingga registri menyatakan "apa yang dihitung"
   dan "di mana melihatnya" di satu tempat. Belum dikerjakan — ini penambahan fitur, bukan
   perbaikan.

## Skema yang berubah — dan apakah aman di MySQL dengan data lama

- `2026_09_05_000195_create_core_user_preferences_table` — tabel baru (`user_id` FK cascade,
  `key` string(64), `value` json/text, UNIQUE `(user_id, key)`); tidak menyentuh data lama.
- `2026_09_06_000196_add_status_indexes_for_module_counts` — **hanya indeks**, berpenjaga
  `Schema::hasTable`/`hasColumn` dan pemeriksaan indeks yang sudah ada, `down()` simetris. Aman di
  kedua driver; tabel modul yang belum terpasang dilewati (Core tidak menuntut modul fitur ada).

## Uji

- baru: `UserPreferencesTest` (15 uji / 94 asersi), `ModuleCountsTest` (18 / 163),
  `LauncherWiringTest` (7 / 73); `ErpTestCase` membuang memo skema `ModuleCounts`.
- `tests/Feature/Core` + `tests/Feature/Iam` di HEAD: **797 uji / 5.390 asersi hijau** (11 dilewati).
- harness: S22/S22m/S22r + S21 regresi + S18/S19 (yang putaran bangun belum sempat catat padahal
  paket ini memindahkan tempat orang mendarat); hasil di `docs/bukti-uji/results-phase-1.json`,
  12 PNG `s22-*-p1c.png`.
- **suite penuh di commit rilis `ad3b5d2`**: SQLite — (diisi); MySQL — (diisi).

## Deviasi baru yang ditemukan

- `PDO::quote()` bukan oracle byte tersimpan: ia meng-escape per driver (MySQL 1.406 vs SQLite
  1.204 untuk muatan emoji yang sama), jadi uji yang memakainya hijau di SQLite dan merah di suite
  MySQL penuh — oracle yang benar adalah membaca kembali kolomnya.
- Satu pemindaian tabel penuh TERSISA dengan sengaja: entri `inv` membandingkan `b.qty <
  i.min_stock` antar dua tabel; tidak ada indeks yang melayani perbandingan antar kolom. Ambang
  ~100 rb baris ditulis di CONVENTIONS §16.
