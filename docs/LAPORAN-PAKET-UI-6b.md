# Laporan Paket UI-6b — Proyek: sebuah regresi yang paket saya sendiri kirim

> Domain kedua dari empat (keputusan pemilik). Layar: `#/d/projects/1` (workspace + tab Jadwal),
> `#/evm`, `#/harga-satuan`.

---

## 1. UI-5 menghapus tepat kalimat yang berkata "jangan percaya angka ini"

Audit UI-6b dimulai dengan membuka ketiga layar proyek dan menghitung. Yang ditemukan bukan utang
gaya lama, melainkan cacat yang **berumur satu paket**.

UI-5 meratakan ubin KPI dengan `subgrid` dan mengikat tiap ubin ke **tepat tiga trek**:

```css
.stat-row > .stat { grid-row: span 3; display: grid; grid-template-rows: subgrid; }
```

Ubin ber-**empat** anak karena itu tidak punya baris untuk anak keempatnya, dan menggambarnya **di
atas** anak ketiga. Terukur pada pohon UI-6a, dua layar:

| layar | ubin | tindih | rasio |
|---|---|---|---|
| `#/d/projects/1` | CPI (BIAYA) | 6.872 px² | **1,0** |
| `#/evm` | CPI PORTOFOLIO | 4.917 px² | **1,0** |

Rasio 1,0 berarti satu kotak **seluruhnya** di dalam kotak lain. Yang tertimpa, pada keduanya,
adalah kalimat peringatan — *"Biaya aktual belum lengkap — CPI belum dapat dipercaya"* — yang
tergambar persis menimpa kalimat basisnya *"EV ÷ AC per 19 Sep 2026"*. Aturan perataan itu menghapus
tepat kalimat yang memberi tahu bahwa angka 101,6283 di atasnya tidak boleh dipercaya.

**Sebabnya dibuktikan, bukan disimpulkan:** dengan blok `@supports` UI-5 dimatikan sementara, kedua
tabrakan hilang (`0 tabrakan` di ketiga layar); dikembalikan, keduanya muncul lagi.

**Mengapa UI-5 hijau.** Ia mengukur keluarga dasbor, dan di sana **setiap** ubin berisi tepat tiga
anak. Sebaran sebenarnya, dihitung di 15 rute: **13 ubin dua-anak · 60 tiga-anak · 2 empat-anak**.
Dua ubin itu tidak ada di layar mana pun yang UI-5 buka.

Perbaikannya menghitung **anak**, bukan pola kelas, supaya ubin empat-anak bentuk apa pun ikut
tertolong:

```css
.stat-row > .stat:has(> :nth-child(4)) { grid-row: span 4; }
```

Dan kemenangan UI-5 tidak dikorbankan untuk itu: **0 dari 19 baris visual** tidak sejajar sesudahnya,
diukur di tujuh layar (`#/dashboard`, `#/`, `#/evm`, `#/d/projects/1`, `#/harga-satuan`, `#/periods`,
`#/tax-exports`).

## 2. Angka yang paling mengenali proyek adalah yang paling kecil

Baris ubin pahlawan `#/d/projects/1`, diukur sebelum paket ini:

| ubin | nilai | ukuran |
|---|---|---|
| Nilai kontrak | Rp 48.500.000.000 | **16 px** |
| Progres aktual | 55% | 22 px |
| Tenaga kerja hari ini | 2 | 22 px |
| Milestone terlambat | 0 | 22 px |
| PO terbuka | 1 | 22 px |
| Retensi ditahan | Rp 2.425.000.000 | **16 px** |
| Anggaran terpakai | — | 22 px |

Pembelahnya bukan kepentingan, melainkan **panjang string**: rupiah penuh tidak muat di petak 158 px,
jadi situs uangnya memilih `.value.sm`. Hasilnya nilai kontrak — angka yang paling mengenali proyek —
tergambar lebih kecil daripada "PO terbuka: 1".

Bentuk **ringkas** menyelesaikan sebabnya, bukan gejalanya, dan angka penuhnya tidak hilang: ia turun
ke baris konteks (dan tetap ada di `title`). Idiomnya sudah ada di `evm.js` sejak layar itu lahir —
`money()` ringkas dengan `exact()` di tooltip. Sesudahnya ketujuh ubin **22 px**, dengan
"Rp 48.500.000.000" terbaca utuh satu baris di bawahnya.

Kartu EVM di bawahnya **tetap** campuran 22/16 px, dan itu disengaja: di sana indeks (SPI · CPI ·
TCPI) adalah subjek kartunya dan uang (PV · EV · AC) adalah masukannya. Itu hierarki; yang di atas
tadi kecelakaan.

## 3. 446.520 piksel untuk mengatakan "harganya tidak berubah"

`.chart` memaksa `width: 100%` supaya grafik mengisi kartunya — benar untuk grafik padat. Tetapi
viewBox-nya 720×260 (`chartWidth()`), jadi di kartu selebar 1112 px grafik yang sama tergambar
**1112×402**.

Diukur di `#/harga-satuan`: **446.520 px² untuk DUA titik data yang nilainya sama** — 223.260 px² per
titik, **sembilan kali** lebih longgar daripada kurva workspace proyek (198.343 px² untuk 8 mark =
24.793 px² per mark) — sementara keempat angka yang benar-benar menjawab layar itu tergambar 16 px.
Itu kebalikan persis dari "grafik lebih sunyi dari angkanya".

`.chart-cap` membatasi grafik pada ukuran **alaminya sendiri**, bukan pada angka yang dikarang:

```css
.chart-cap { max-width: 720px; margin-inline: auto; }
```

Sesudahnya **688×248 = 170.925 px²** — turun **62%**.

## 4. Lima pemakai pertama pemindahan `.tone-*` UI-6a

`jadwal.js` menulis `color: 'var(--warning)'` sebaris pada empat `p.cell-sub`, dan `evm.js` pada satu
`span.cell-sub`. Kelimanya kini `.cell-sub.tone-warn` — dan itu **baru mungkin sejak UI-6a**: sampai
paket itu memindahkan `.tone-*` ke akhir `app.css`, `.cell-sub` (yang menyetel warnanya sendiri di
baris 1099) menang dan peringatannya tergambar abu-abu.

Terverifikasi di peramban pada pohon ini: `.cell-sub.tone-warn` = `rgb(150, 96, 26)` = `var(--warning)`,
sementara `.cell-sub` polos = `rgb(94, 104, 116)`.

Kelimanya hanya tergambar pada data yang rusak (kode WBS ganda, induk melingkar) yang tidak ada di
basis data demo, jadi yang diuji adalah kaskadenya — sama seperti kas kecil di UI-6a, dan disebut di
sini supaya tidak terbaca sebagai render sungguhan.

## Satu kesalahan yang hampir terkirim, dan bagaimana ia tertangkap

Penggantian kelima situs itu saya lakukan dengan dua suntingan bersasaran lalu **satu `replace`
menyeluruh** untuk sisanya. `replace` menyeluruh itu mencabut `color: var(--warning)` dari situs
keempat — peringatan "Induk melingkar" — yang **tidak pernah mendapat `.tone-warn`**. Hasilnya
peringatan berwarna yang diam-diam menjadi abu-abu: persis cacat yang paket ini ada untuk
memperbaikinya, dikirim oleh perbaikannya sendiri.

Tertangkap dengan menghitung ulang `p.cell-sub` di berkas itu sesudah menyunting, bukan dengan
membaca diff. Pelajarannya sama tuanya dengan kampanye ini: **sebuah penggantian menyeluruh harus
dihitung sesudahnya, bukan dipercaya.**

## Gerbangnya sendiri harus dibangun ulang DUA KALI sebelum ia jujur

Gerbang S51 mengukur satu hal yang terdengar sederhana — *tidak ada dua daun teks yang kotaknya
bertindih* — dan butuh tiga versi untuk benar-benar mengukurnya.

**Versi 1 — `getBoundingClientRect()`.** Hijau di 1440 px, dan melaporkan **19 tabrakan** di
`#/d/projects/1` pada 768 px. Dipotret satu per satu, dengan kedua belah pihak diberi garis warna
dalam putaran pengukuran yang SAMA: tidak satu pun glyph bersentuhan.

**Versi 2 — kotak baris satu per satu (`getClientRects()`).** Hipotesis saya: untuk elemen inline
yang teksnya membungkus, bounding box adalah GABUNGAN kotak barisnya, jadi ia memuat ruang kosong di
kanan baris terakhir dan dua gabungan bisa berpotongan tanpa ada huruf yang bertemu. Masuk akal, dan
**salah** — dengan kotak baris satu per satu, jumlahnya tetap **19**. Hipotesis yang terdengar benar
tidak menjadi benar karena terdengar benar; pengukurannya yang memutuskan.

**Versi 2b — kotak baris + saringan `elementFromPoint`.** Ia menghapus seluruh 19 tabrakan palsu dan
terlihat sempurna: hijau di kedua lebar, di keempat layar. Ia juga **gerbang MATI**. Untuk tabrakan
SUNGGUHAN satu elemen MENUTUPI yang lain, jadi yang tertutup selalu gagal uji titiknya. Dibuktikan:
dengan perbaikan trek keempat dicabut — keadaan yang gerbang ini ada untuk menangkapnya — ia tetap
menjawab **0 tabrakan**.

Sebab sebenarnya baru terlihat sesudah itu, dan cuma satu: sebuah baris tabel yang **tergulung
keluar** oleh `.table-wrap` tetap punya geometri, dan geometrinya jatuh persis di atas kartu
berikutnya.

Ini persis bentuk yang rencana kampanye sebut sesudah UI-5: *sebuah gerbang tidak cukup ditulis dan
dibaca ulang, ia harus DISABOTASE.* Tanpa sabotase, versi 2 akan terkirim sebagai gerbang yang
tampak menjaga sesuatu.

**Versi 3 — kotak baris, dipotong terhadap setiap leluhur yang menggulung.** Yang tergulung keluar
kehilangan seluruh kotaknya; yang tertutup tetangganya tidak. Hasilnya benar di kedua arah:

| pohon | 1440 px | 768 px |
|---|---|---|
| UI-6b (diperbaiki) | 0 · 0 · 0 · 0 | 0 · 0 |
| sabotase (trek keempat dicabut) | **1** · **1** · 0 · 0 | **1** · **1** |

Dan yang ditemukan dalam sabotase itu tepat pasangan aslinya, rasio 1,0: `.delta tone-warn` *"Biaya
aktual belum lengkap — CPI belum dapat dipercaya"* di atas `.delta muted` *"EV ÷ AC per 19 Sep 2026"*.

## Koreksi terhadap paket ini (19 Sep 2026, tinjauan pra-gabung)

Perbaikan di §1 — `.stat-row > .stat:has(> :nth-child(4)) { grid-row: span 4 }` — **menukar satu
cacat dengan cacat lain**, dan gerbang yang paket ini bangun tidak bisa melihatnya.

`.stat-row` tidak punya `grid-auto-flow: dense`, jadi penempatan otomatisnya SPARSE: kursornya tidak
pernah mundur. Begitu SATU ubin meminta empat trek sementara tetangganya meminta tiga, baris
berikutnya jatuh ke sel mana pun yang kebetulan kosong. Terukur pada pohon UI-6c, baris ubin EVM di
`#/d/projects/1` (8 ubin, salah satunya berempat anak):

| lebar | sebelum koreksi | sesudah |
|---|---|---|
| 1440 px | 3 garis `[4, 3, 1]` — satu lubang, satu ubin terdampar | 2 garis `[4, 4]` |
| 1100 px | **7 garis** `[2,1,1,1,1,1,1]` | `[2,2,2,2]` |
| 900 px | 4 garis `[3,2,1,2]` | `[3,3,2]` |
| 768 px | **7 garis** `[2,1,1,1,1,1,1]` | `[2,2,2,2]` |

Pada 1440 px — lebar desktop biasa, tanpa mengubah ukuran apa pun — ubin TERAKHIR dalam urutan DOM
tergambar di KIRI dua pendahulunya dan 46 px lebih rendah. Itu justru janji yang UI-5 bangun subgrid
untuk menepatinya, dipatahkan oleh perbaikan yang menyelamatkan janji lain.

**S51 tidak bisa melihatnya** karena ia mengukur TABRAKAN (kotak yang bertindih) dan hanya di 1440
dan 768 px. Lubang dan ubin yang bergeser satu trek bukan tabrakan. Buktinya sendiri merekam
`"#/evm @768": {"tabrakan": []}` sementara dua ubin di sana tertukar kiri-kanan.

Diperbaiki dengan memindahkan `:has()` ke WADAHnya —
`.stat-row > .stat:has(> :nth-child(4))` menjadi
`.stat-row:has(> .stat > :nth-child(4)) > .stat` — sehingga SELURUH ubin dalam baris yang memuat
ubin empat-anak merentang empat trek dan kisinya kembali seragam. Harganya satu trek kosong pada
ubin bertiga anak, yang di UI-6d saya tolak dengan alasan estetis ("menambah ruang kosong di lima
ubin untuk merapikan satu") — alasan itu diambil **tanpa mengukur akibat dari tidak melakukannya**.

## Uji

| gerbang | hasil |
|---|---|
| `tests/Feature/Core` (SQLite) | **1.420 uji / 11.626 asersi**, 11 dilewati |
| `tests/Unit` | **630 uji / 2.201 asersi** |
| `tests/Feature/Core` (MySQL 8.0.46) | **1.420 uji / 11.633 asersi**, 9 dilewati |
| `tests/Unit` (MySQL 8.0.46) | **630 uji / 2.201 asersi** |
| harness Playwright, 27 skenario gerbang | semua **ok**, keluar 0 — `docs/bukti-uji/results-ui-6b.json` |

Angka gerbang S51 pada pohon yang di-commit, dibaca dari berkas buktinya:
`tabrakan = 0` di keenam pengukuran (empat layar di 1440 px, dua di 768 px), baris ubin pahlawan
`{'22px': 7}` — satu ukuran untuk tujuh ubin — dan grafik `#/harga-satuan` **688×248 = 170.925 px²**.

**A/B terhadap UI-6a (`f94e4e1`), 1440 px, 19 properti terhitung + kotak pembatas per elemen:**

```
#/d/projects/1                a=492 b=493 beda=24  {font-size 2, _ada 1, _box 21}
#/evm                         a=108 b=108 beda=5   {_box 5}
#/harga-satuan                a=118 b=118 beda=23  {_box 21, margin 2}
#/dashboard                   a=471 b=471 beda=0
#/                            a=471 b=471 beda=0
#/periods                     a=239 b=239 beda=0
#/tax-exports                 a=103 b=103 beda=0
#/reports?tab=cash-projection a=223 b=223 beda=0
#/stock                       a=137 b=137 beda=0
#/bank-recon                  a=28  b=28  beda=0
```

**Tujuh layar yang tidak disentuh: nol beda.** Itu yang membuktikan kedua aturan CSS baru tidak
bocor — `:has(> :nth-child(4))` hanya mengenai ubin empat-anak (yang ada dua, keduanya di layar
proyek) dan `.chart-cap` harus diminta.

Ke-52 beda di tiga layar proyek seluruhnya terhitung: dua `font-size` adalah uang 16 → 22 px, satu
`_ada` adalah baris konteks baru, dan sisanya geometri yang mengikuti. Yang paling terbaca di antara
mereka adalah bukti tabrakannya hilang:

```
'Biaya aktual belum lengkap'   kotak a=[139, 50] -> b=[139, 64]
'EV ÷ AC per 19 Sep 2026'      kotak a=[139, 50] -> b=[139, 17]
```

Keduanya setinggi 50 px sebelumnya karena keduanya menempati **baris yang sama**.

**Responsif dan tema.** Ketiga layar diukur di 1440 / 1024 / 768 / 390 px × terang/gelap: nol gulir
menyamping, nol nilai ubin terpotong, dan grafik `#/harga-satuan` menyusut mengikuti kartunya
(688×248 → 440×159 → 328×224) alih-alih melar.

**S51 dibuktikan MERAH tiga kali** sebelum dipercaya:

| sabotase | yang dikatakan gerbang |
|---|---|
| cabut aturan trek keempat (= keadaan UI-5) | `#/d/projects/1: teks menimpa teks (rasio 1) — .delta tone-warn … di atas .delta muted …` (dan `#/evm`) |
| uang kembali ke `.value.sm` | `baris ubin tingkat atas memakai 2 ukuran sekaligus: {'16px': 1, '22px': 6}` |
| cabut `.chart-cap` | `grafik 1112x402 = 446,520 px2 untuk 2 titik (223,260 px2 per titik)` |

Gerbang pertamanya sengaja **umum**, bukan tentang ubin CPI: *tidak boleh ada dua daun teks yang
kotaknya bertindih >35%*. Regresi berikutnya tidak akan berbentuk ubin CPI.

Gaya sebaris `views/` **540 → 539**; `CEILING` diturunkan; `SHELL_VERSION` **29 → 30**.

## Yang sengaja tidak dikerjakan — dengan angkanya

* **Sapuan gaya sebaris `evm.js` (37) dan `project.js` (27).** Diukur situs per situs: ia didominasi
  tambalan margin dengan **lima nilai berbeda** (`0`, `6px`, `8px`, `10px`, `12px`). Menyapunya
  dengan benar berarti mengadopsi `.stack` pada badan kartunya dan membuang margin anaknya — sebuah
  keputusan JARAK yang menggeser piksel di banyak layar. Menyapunya dengan kelas sekali-pakai per
  situs justru melanggar aturan paket ini sendiri ("tiap layar pindah ke primitif bersama, bukan
  menumbuhkan CSS sendiri"). Itu pekerjaan UI-7.
* **Ukuran `.value` tingkat atas di seluruh aplikasi.** Diukur di 14 rute: **22 nilai pada 16 px
  lawan 13 pada 22 px** di baris tingkat atas, dan **tiga layar mencampur keduanya di dalam satu
  baris** (`#/evm` 3+3, `#/tax-exports` 2+2, `#/stock` 1+1). Di dalam kartu normanya jelas (37 pada
  16 px lawan 3 pada 22 px) dan UI-5 sengaja mempertahankannya. Memilih satu sisi untuk tingkat atas
  menggeser piksel di empat domain sekaligus — UI-7, bukan paket Proyek.
* **Margin `<p>` di badan kartu.** Diukur di 16 rute: **42** `<p>` di `.card-body`, hanya **5**
  anak-tunggal, dan **nol** di antaranya masih bermargin — artinya semuanya sudah membawa
  `style: { margin: 0 }` sebaris. Aturan `.card-body > p:only-child` karena itu hanya memenangkan 5
  situs dari 17 yang serupa: setengah jawaban yang justru membuat penanya berikutnya bingung. Jawaban
  penuhnya adalah adopsi `.stack`, lagi-lagi UI-7.
* **Sumbu grafik pada deret KONSTAN.** `#/harga-satuan` menggambar dua titik yang keduanya
  Rp 62.000 di atas sumbu ber-tick `Rp 61,23 rb · Rp 61,61 rb · Rp 62 rb · Rp 62,39 rb ·
  Rp 62,78 rb` — pita ±1,3% yang tidak ada di datanya, dan sekilas terbaca sebagai harga yang
  bergerak.

  **Koreksi terhadap catatan saya sendiri:** ini bukan jebakan yang tidak terdokumentasi. Sumbu
  yang tidak dimulai dari nol adalah keputusan yang disengaja dan sudah **tertulis untuk pengguna**
  — `PANDUAN-PENGGUNA.md` §4.6 berkata *"Sumbu harga tidak dimulai dari nol"* — dan `FRONTEND.md`
  menyebutnya sebagai salah satu properti yang memang milik layarnya untuk diputuskan. Yang tersisa
  sebagai pertanyaan terbuka jauh lebih sempit: apa yang harus digambar sumbu itu ketika deretnya
  benar-benar DATAR. Itu keputusan penyajian (pita minimum? kalimat "harga tidak berubah"?), bukan
  gaya, dan tidak pantas diputuskan diam-diam di dalam paket UI.
