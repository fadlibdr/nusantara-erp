# Laporan Paket UI-2a — Primitif, angka yang berbaris, dan pagar yang bergerak satu arah

Branch: `feat/ui-2a-primitif` (dari main `1f84552`) · 15 September 2026

> Status jujur: UI-2a adalah **irisan pertama** UI-2, bukan seluruhnya. Ia menambahkan primitif yang
> dibutuhkan sapuan, menyapu **satu** berkas sampai nol, memperbaiki dua cacat yang audit temukan,
> dan memasang pagar supaya utangnya tidak bisa tumbuh lagi. Sembilan berkas berutang terbesar
> belum disentuh.

## Yang ditutup

| Tugas | Status | Bukti |
|---|---|---|
| Primitif tata letak | ✅ | `.stack` (+`.tight` 8 / `.loose` 16) · `.row` (+`.top`) · `.cluster` · `.grid-auto`, semuanya ber-`gap` dari skala UI-1 |
| Nada angka | ✅ | `.tone-pos` · `.tone-neg` · `.tone-warn` — pengganti `color: v < 0 ? …` sebaris |
| Utilitas | ✅ | `.strong` · `.col-min` · komponen `.fin-line` · `table.data tr.emphasis` |
| Tombol tersier | ✅ | `.btn.subtle` — terbaca tanpa bersaing dengan `.primary` |
| **Angka yang berbaris** (temuan audit #8) | ✅ | `table.data td.right` dan `tfoot td` kini selalu `tabular-nums`. Sebelumnya hanya `.num`, dan pemanggil harus ingat DUA kelas — sehingga kolom uang yang hanya ber-`align: right` tidak pernah berbaris |
| **`strong: true` akhirnya berarti sesuatu** | ✅ | `cells.js:52` memasang kelas itu pada `<span>` di dalam sel, sementara satu-satunya aturan `table.data td.strong` menuntutnya pada `<td>`. Dua kolom yang menyatakannya — "Skor" (evaluasi vendor) dan "Netto" (payroll) — tidak menghasilkan apa pun. `.strong` sebagai utilitas menutupnya |
| `views/cashflow.js` disapu | ✅ | **18 → 0** objek `style: {`. Berkas dengan rasio terburuk di repositori: 18 dalam 282 baris, dan ia hanya mengimpor `el` dan `icon` dari `ui.js` |
| Pagar yang bergerak satu arah | ✅ | `SpaInlineStyleBudgetTest` — plafon 591, **dibuktikan bisa merah** |
| `SHELL_VERSION` 16 → 17 | ✅ | `app.css` dan sebuah berkas view berubah |

## Verifikasi

**Diff gaya terkomputasi** (26 elemen × 23 properti × 2 tema × 2 lebar), dan kali ini piksel MEMANG
bergerak — jadi yang dibuktikan adalah bahwa **hanya yang dimaksud** yang bergerak. Tepat tiga
properti berubah, tidak lebih:

```
sp.font-weight            400  -> 600          (span.strong di dalam sel — cacat yang ditutup)
tdr.font-variant-numeric  normal -> tabular-nums
tf.font-variant-numeric   normal -> tabular-nums
```

**Harness** `S8 S21 S21m` — ketiganya `ok`, keluar 0. `S8` **identik** dengan bukti UI-1, nol kunci
berbeda: tabular-nums dan `.strong` tidak menggeser tinggi baris, ukuran huruf, maupun kontras.
Kontrak kepadatan yang dibekukan pemilik utuh: `compact_ok` · `comfortable_ok` ·
`normal_equals_baseline` · `tfoot_normal_41` semuanya true, `crumb_walk` ok atas 131 rute.
Mentah: `docs/bukti-uji/results-ui-2a.json`.

**Peramban, layar yang disapu.** Kedua layar arus kas digambar dan diperiksa: `.fin-line` terhitung
`flex / 6px 0 / 13px` dan `.fin-line.total` `700 / 14px / border-top 1px` — **persis** nilai gaya
sebaris yang digantikannya. Nol galat konsol.

**Uji PHP:** `tests/Feature/Core` **1.409 uji / 11.588 asersi** keluar 0 — naik tepat +3 uji /
+13 asersi dari acuan, yaitu uji anggaran yang baru dan bukan yang lain. `tests/Unit` 630 / 2.201.

## Pagar itu dibuktikan bisa merah

Sebuah gerbang yang tidak bisa gagal adalah pajangan. Satu `style: { marginTop: '4px' }` ditambahkan
kembali ke `cashflow.js`, dan **dua** asersi menyala: plafon (592 > 591) dan penjaga berkas tersapu.
Dipulihkan, hijau lagi.

Uji itu juga memeriksa bahwa kedelapan primitif yang disebut pesan kegagalannya **benar-benar ada di
`app.css`** — pesan yang menyuruh memakai kelas yang tidak menggambar apa pun adalah pesan yang
menyesatkan tanpa ada yang tahu.

**Warna sengaja TIDAK dilarang.** Dari 122 kemunculan `color:` di lapisan view, nol ditulis mentah —
semuanya `var(--…)`. Melarang warna berarti melarang sesuatu yang sudah benar. Utangnya ada di
geometri dan ukuran huruf: 127 `fontSize:` tanpa satu pun token.

## Yang sengaja tidak dikerjakan

* **Sembilan berkas berutang terbesar belum disentuh**: `custom.js` 48 · `evm.js` 41 · `tender.js` 38
  · `periods.js` 32 · `project.js` 32 · `profil.js` 25 · `bankrecon.js` 23 · `kalender.js` 18 ·
  `reports.js`. Plafon 591 ada supaya angkanya hanya bisa turun sambil mereka dikerjakan.
* **Tabel belum dirombak** — perlakuan kepala, tepi yang ditahan, keadaan terpilih, pemotongan
  cerdas: semuanya masih UI-2 dan belum di sini.
* **Migrasi `icon()` → `svgIcon()` belum dimulai** — 85 pemanggil lawas, 4 pemakai sprite.
* **Sebelas aturan label huruf besar** masih berbeda-beda (warisan koreksi UI-1).
* **Belum di-deploy.**

## Deviasi baru yang ditemukan

**`strong: true` adalah bendera schema yang tidak menghasilkan apa pun** sejak ia ada — kelas
dipasang pada `<span>`, aturan menuntut `<td>`. Ditemukan bukan dengan membaca CSS melainkan dengan
memeriksa setiap nama kelas kandidat terhadap pemakaian JS sebelum menambahkannya — kebiasaan yang
lahir dari tabrakan `.pick` di prototipe. Pemeriksaan yang sama menyelamatkan `.row`: 2.342
kecocokan "row" di lapisan view ternyata **seluruhnya nama variabel**, nol kelas.

**Jebakan `pgrep -f` / `pkill -f`, dua kali.** Pola yang memuat nama proses juga mencocokkan
**baris perintah shell yang sedang mencarinya**. Pertama ia membunuh shell-nya sendiri; kemudian ia
melaporkan harness "masih berjalan" padahal sudah selesai, karena yang cocok adalah perintah polling
itu sendiri. Matikan lewat port (`ss -lptnH 'sport = :PORT'`), dan periksa keluarannya, bukan
prosesnya.

**Prototipe dan implementasi sempat berselisih.** `.stack` lahir 12 px di prototipe dan 16 px di
`app.css`. Dibereskan dengan memisahkan maknanya — `.stack` 12 untuk isi kartu, `.stack.loose` 16
untuk blok halaman (angka yang sama dengan `.card + .card`) — dan menyamakan keduanya. `.stack.loose`
nol pemakai di prototipe, jadi penyamaannya nol dampak visual; diverifikasi ulang, 20 layar tetap
bersih. Rujukan rancangan yang berselisih dengan kode adalah hanyutan, bukan detail.
