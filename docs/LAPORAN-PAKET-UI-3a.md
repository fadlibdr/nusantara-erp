# Laporan Paket UI-3a — sidebar: ikon modul, kotak saring, dan sebuah kunci yang tidak pernah stabil

> Paket pertama UI-3 (Cangkang). Ia menyentuh **sidebar saja**: header, palet perintah dan
> standar kepala halaman menyusul di UI-3b/UI-3c, supaya masing-masing membawa buktinya sendiri.

---

## Yang ditutup

| Butir | Keadaan |
|---|---|
| Ikon modul di kepala grup | ✅ 14 modul + 2 grup pintasan (`star`, `history`) |
| Kotak saring 150 tautan | ✅ baru — menyaring DOM, tidak membangun ulang |
| Kunci keadaan buka/tutup yang stabil | ✅ dibaca dari atribut, bukan `textContent` |
| `[hidden]` yang dikalahkan `display: flex` | ✅ pengaman `.nav-item[hidden]` |
| Gerbang sorotan onboarding di S18 | ✅ dibuktikan bisa MERAH |

### Angka rencana yang basi, diukur ulang 16 Sep 2026

Rencana dan komentar kode menulis "121 tautan / 14 grup / 4,9 layar". Sesi admin hari ini:

| | rencana | terukur |
|---|---|---|
| tautan sidebar | 121 | **150** |
| grup | 14 | **16** (termasuk 2 grup pintasan) |
| tinggi pada viewport 900 | 4,9 layar | **2,45 layar** (hanya 2 dari 15 grup terbuka) |
| grup Keuangan | 20 tautan | **22 tautan / 5 pemisah** |

Dua butir yang rencana UI-3 daftarkan sebagai pekerjaan ternyata **sudah ada**: `--drawer-w`
ditokenkan di UI-1, dan sub-seksi bernama sudah hidup sebagai **9 pemisah** yang dipaku
`SidebarNavWiringTest::test_the_long_groups_carry_the_agreed_dividers_in_order`.

---

## Kunci yang tidak pernah stabil

Keadaan buka/tutup tiap grup disimpan di `localStorage['nusantara_erp_nav']` sebagai larik label.
Jalur BACA membandingkan `group.label` dari model data (`app.js:493`); jalur TULIS memanen
`node.querySelector('button').textContent.trim()` dari DOM. Keduanya sepakat **hanya selama tombol
itu tidak memuat teks lain**.

Diukur, bukan ditebak — sebuah simulasi menyisipkan dua jenis anak ke dalam tombol grup:

| disisipkan | `textContent` sesudahnya |
|---|---|
| `<svg>` (ikon modul) | `'Terakhir dibuka'` — **tidak berubah** |
| `<span>20</span>` (lencana hitungan) | `'Terakhir dibuka20'` — **berubah** |

Jadi ikon aman; lencana hitungan — butir berikutnya di prototipe — akan **meyatimkan preferensi
sidebar setiap pengguna** tanpa satu pun galat. Penulisnya kini membaca `groupNode.dataset.label`.
Nilai yang ditulis **sama persis** dengan sebelumnya, jadi tidak ada migrasi dan tidak ada
preferensi yang hangus.

---

## Kotak saring

150 tautan, 2,45 layar. Mengetik tiga huruf memotongnya:

| kueri | grup tampak | tautan tampak | tinggi nav |
|---|---:|---:|---:|
| (kosong) | 16 | 38 | 1.965 px |
| `opname` | 3 | **5** | 960 px |
| `jurnal` | 2 | 2 | 960 px |
| `zzz` | 0 | 0 | + kalimat "Tidak ada menu yang cocok dengan “zzz”" |

`opname` adalah contoh yang prototipe pakai persis: tiga modul, lima layar, satu kolom.

**Keadaan tersimpan tidak ikut berubah.** Saat menyaring, grup yang cocok dipaksa terbuka lewat
atribut saja; `localStorage` tidak disentuh (diukur: `null` sebelum dan sesudah) dan keadaan
sebelum menyaring dipulihkan tepat saat kotaknya dikosongkan — 38 tautan, 5 pemisah, tinggi
1.965 px, sama seperti sebelumnya. Kalau tidak, "mencari sesuatu" diam-diam menjadi "menata ulang
sidebar saya selamanya".

Saringan juga selamat dari pembangunan ulang: memberi bintang selagi menyaring membangun ulang
seluruh nav, dan kueri serta hasilnya tetap di tempat.

---

## Verifikasi

**Uji pin** membuktikan apa yang tertulis; dua pohon dilayani berdampingan (`main` di 8098, pohon
kerja di 8099) membuktikan apa yang tergambar.

| Lingkup | Hasil |
|---|---|
| `.main` (isi halaman), 14 rute × 2 tema | **0 perbedaan** |
| `.header`, 14 rute × 2 tema | **0 perbedaan** (28 elemen, identik) |
| Metrik baris nav | **identik**: baris 31,5 · kepala grup 30,5 · bintang 26 · lebar 250 · padding · 150 tautan · 16 grup |
| S5 di 11 peran | grup, tautan, `open_groups`, `biggest`, `dividers` **identik**; `navContentPx` **+45 px di semua peran** — tinggi kotak saring, dan tidak ada yang lain |
| Ponsel 390 px | laci 264 px · baris nav **36 px** · bintang **34 px** — lantai sasaran sentuh utuh |
| Cetak | nav dan kotak saring keduanya tersembunyi |

Kepadatan S21 tetap: `compact_ok` · `comfortable_ok` · `normal_equals_baseline` ·
`tfoot_normal_41` semuanya true, `row_h` 38,5 px, `nav_link` 31,5 px.

### Uji

Core **1.409 / 11.588** keluar 0 (11 dilewati, sudah dilewati sebelumnya) · Unit **630 / 2.201** ·
harness `S5 S7 S8 S18 S19 S20 S20m S21 S21m S43` hijau, keluar 0 —
bukti `docs/bukti-uji/results-ui-3a.json` · `SHELL_VERSION` 18 → **19**.

---

## Dua jebakan yang dijatuhkan paket ini, keduanya ke saya sendiri

**1. `display: flex` mengalahkan `[hidden]`.** Kotak saring menyetel `.hidden = true` dengan patuh
dan baris-barisnya **tetap tergambar**: `.nav-item { display: flex }` milik penulis menang atas
`[hidden] { display: none }` milik stylesheet UA. Codebase ini sudah mengenal jebakan itu — tiga
pengaman bertanda komentar ada di `app.css` (`.overlay[hidden]`, `.combo-clear[hidden]`,
`.combo-warn .btn[hidden]`) — dan saya tetap melangkah masuk.

**2. Probe pertama melaporkan "5 baris cocok" ketika layar memuat tiga puluhan.** Ia menghitung
properti `hidden` di JavaScript, bukan apa yang peramban benar-benar gambar. Angkanya cocok dengan
niat saya, jadi ia terbaca benar. Yang menangkapnya adalah **tangkapan layar**. Probe sekarang
memakai `getClientRects().length > 0`.

Keduanya adalah bentuk kegagalan yang sama: sebuah pengukuran yang menyetujui penulisnya.

---

## Gerbang yang dibuktikan bisa merah

`ui.js spotlight()` diam ketika sasarannya hilang — `if (!(element instanceof Element) …) return`
— dan kelima selektornya milik UI-3 (`.header .menu-toggle`, `nav.nav`, `#view .page-head h1`,
tautan nav, `.page-head .actions .btn.primary`). Sampai paket ini, S18 hanya **mencatat**
`ring_covers_nav`: panduan onboarding bisa putus total dan harness tetap melapor "ok".

Sekarang ia gerbang. Dibuktikan dengan menyabotase satu selektor menjadi `nav.nav-YANG-TIDAK-ADA`:

```
GAGAL: S18_onboarding_dock_desktop
STATUS KELUAR: 1
galat: ['S18 langkah 2: cincin tidak menutupi sidebar — []']
```

Sabotase dipulihkan; `git diff` bersih.

---

## Gerbang kepadatan yang tidak pernah bisa merah

Audit cangkang menemukan yang tidak satu pun laporan paket sebelumnya sadari, termasuk laporan
saya sendiri: **S21 tidak pernah bisa gagal.** Dekorator harness menandai skenario merah hanya bila
hasilnya memuat `ok: False` di puncak, atau bila ia melempar. S21 tidak pernah mengembalikan kunci
`ok` — jadi `compact_ok`, `comfortable_ok`, `normal_equals_baseline` dan `tfoot_normal_41` dihitung
dengan rapi, ditulis ke `results.json`, dan **tidak ada yang membacanya**.

Diperiksa pada kesembilan skenario yang dipakai gerbang rilis kampanye ini: **tidak satu pun**
punya `ok` di puncak, kecuali dua yang gerbangnya ditulis tangan (S43 di UI-2b, S18 di paket ini).

Ini menyentuh keputusan pemilik secara langsung. Laporan UI-2b saya menulis bahwa kepadatan
"dipaku harness S21". Ia tidak dipaku; ia dicatat. Sekarang ia dipaku — lewat mekanisme
`ok`/`failed_checks` yang dekoratornya memang sudah punya, ditambah kecocokan beranda modul dan
panah remah. Dibuktikan bisa merah dengan menggeser `--row-h` Padat 32 → 34 px:

```
[S21_module_accents_breadcrumb] GAGAL: kepadatan Padat bukan 32 px ([34]), pilihan kepadatan tidak bertahan
GAGAL: S21_module_accents_breadcrumb
STATUS KELUAR: 1
```

---

## Laci ponsel: tiga jalan keluar yang tidak ada

Esc tidak melakukan apa-apa. Menyentuh kain gelap di sebelah laci juga tidak — kainnya
`body.nav-open::after`, sebuah pseudo-element, yang tidak bisa memegang pendengar. Keduanya gerak
refleks orang yang membuka laci dan berubah pikiran. (Satu jalur Esc memang ada di
`views/onboarding.js`, tetapi hanya ketika **tur** yang membuka lacinya.)

Kini: `aria-expanded` + `aria-controls`, Esc yang mengalah pada dialog di atasnya dan
mengembalikan fokus ke hamburger, dan sentuhan di luar laci — dengan hamburger dikecualikan,
karena tanpa itu keduanya saling membatalkan dan laci tidak pernah terbuka.

**Laci tertutup juga keluar dari urutan Tab.** Diukur, bukan ditaksir:

| | kontrol yang benar-benar bisa difokus |
|---|---:|
| `main`, laci tertutup | **87** (16 tombol grup · 38 tautan · 33 bintang) |
| UI-3a, laci tertutup | **0** |

Bukan 150: `.nav-group[data-open="false"] .nav-items { display: none }` sudah mencabut tautan grup
yang terlipat, jadi yang tertinggal hanyalah grup yang kebetulan terbuka. Audit menaksir "~149" dan
itu salah; angka di atas dari mencoba memfokuskan tiap kandidat satu per satu.

Dipakai **`inert`** — idiom yang `ui.js` memang sudah pakai untuk `#root` saat modal terbuka.
Percobaan pertama memakai `visibility: hidden` dan ia **menjatuhkan seluruh harness dalam satu
langkah**: setiap skenario masuk lewat `wait_for_selector('nav.nav')`, dan nav yang
visibility-hidden tidak pernah "visible" bagi Playwright — ke-77 skenario akan gagal di baris
login, bukan pada hal yang diujinya. Tertangkap karena percobaan berikutnya dijalankan, bukan
karena CSS-nya dibaca ulang.

`inert`-nya **mengamati kelas `body`**, bukan disetel dari satu tempat: panduan onboarding membuka
laci dengan menulis `classList.add('nav-open')` langsung, tidak lewat `setDrawer()`. Tanpa pengamat
itu laci tur akan tergambar terbuka tetapi tetap inert — terlihat, dan tidak bisa disentuh siapa
pun.

Dan probe pertama untuk `inert` pun salah ukur: ia menghitung `getClientRects` dan `visibility`,
yang keduanya tidak disentuh `inert`, lalu melaporkan angka yang **sama** untuk laci terbuka dan
tertutup. Yang benar adalah mencoba memfokuskan.

---

## Yang sengaja tidak dikerjakan

* **Lencana hitungan di kepala grup** (prototipe `.n`). Bentuknya siap dan kuncinya kini aman,
  tetapi ia butuh keputusan: menghitung apa — tautan yang terlihat, atau dokumen yang menunggu?
  Angka yang salah artinya lebih buruk daripada tidak ada angka.
* **Slot konteks perusahaan di sel merek.** Tidak ada data perusahaan di sesi; menambahkannya
  berarti mengubah kontrak API, yang brief melarang tanpa alasan kuat. Ia bukan restyle cangkang.
* **"Irama yang lebih rapat".** `--nav-py` ada di daftar terlarang keputusan pemilik (kepadatan
  dibekukan) dan baris nav sudah duduk di lantai 36 px pada `pointer: coarse`. Yang tersisa untuk
  dirapatkan hanyalah padding kepala grup — perubahan yang tidak sepadan dengan risikonya.
* **Header, palet perintah, standar kepala halaman, dan penyatuan `icon()`** — UI-3b dan UI-3c.
