# Laporan Paket UI-3c — satu sistem ikon, dan kepala halaman yang akhirnya punya perilaku ponsel

> Paket terakhir UI-3 (Cangkang). Dua bagian: penyatuan ikon yang dipindahkan ke sini dari UI-2,
> dan standar kepala halaman.

---

## Bagian 1 — satu sistem ikon

Ada dua sistem: `icon()` menggambar 20 glyph tulisan tangan (viewBox 16, stroke 1,5) dan `svgIcon()`
memakai sprite Lucide yang di-vendor (viewBox 24, stroke 2). Yang pertama dipakai **79 tempat**, dan
**dua di antaranya corong** — `button({iconName})` dan `menuButton` — yang melewatkan **212 ikon
lagi**. Totalnya **291**.

### Yang tidak dilakukan, dan mengapa

Mengganti `icon(` menjadi `svgIcon(` di 79 tempat. **Tanda tangannya tidak cocok:**
`icon(name, size)` menerima ANGKA, `svgIcon(name, {size})` menerima OBJEK. Penggantian mekanis itu
JavaScript yang **sah** — angka `13` yang didestrukturisasi sebagai `{ size = 16 }` diam-diam menjadi
`16` — sehingga 67 ikon akan membesar 7–33 % tanpa satu pun galat, tanpa satu uji merah, dan tanpa
satu layar pun yang jelas salah.

Alih-alih itu, **`icon()` sendiri yang mendelegasikan**. Nol suntingan di tempat panggil;
`LUCIDE_ALIAS` sudah memetakan kesepuluh nama lama; `PATHS` dibuang.

### Empat glyph yang selama ini menggambar nol piksel

`bell`, `camera`, `copy` dan `list` sampai ke `icon()` tanpa pernah ada di `PATHS`, jadi
`PATHS[name] || ''` memberi mereka `<path d="">` — tak terlihat, tanpa galat, di enam tombol.
Diukur berdampingan dengan memanggil `icon()` untuk tiap nama di kedua pohon:

| glyph | `main` | UI-3c |
|---|---|---|
| `bell` | **kosong** 0×0 | menggambar 18×20 |
| `camera` | **kosong** 0×0 | menggambar 20×16 |
| `copy` | **kosong** 0×0 | menggambar 20×20 |
| `list` | **kosong** 0×0 | menggambar 18×14 |
| `search` · `warn` · `star` · `home` | menggambar | menggambar |

Itu **perbaikan**, bukan regresi — dicatat di sini supaya peninjau yang membandingkan tangkapan
layar tidak melaporkannya sebagai cacat.

### Satu hal yang tidak bisa ikut, diukur lebih dulu

`.nav-item .star.on svg path { fill: currentColor }` adalah **satu-satunya** aturan di seluruh
app.css yang menjangkau ke DALAM sebuah ikon, dan `<use>` tidak punya `<path>` di DOM. Diuji di
peramban: `fill` pada `<svg>` **tidak** menyeberangi batas `<use>` — tiap simbol sprite membawa
`fill="none"` sendiri, dan kedua bintang tergambar kosong. Sprite ini tidak punya varian bintang
terisi.

Keadaan "favorit" karena itu pindah ke **keping latar** (`--primary-soft`), yang pada 13 px terbaca
lebih jelas daripada isian dan tidak bergantung pada bentuk ikon sama sekali.

### Uji yang diganti, bukan dihapus

`LauncherWiringTest` memaku isi `PATHS`, dan pesan gagalnya sendiri berbunyi *"tombolnya menggambar
path kosong"* — ia lahir untuk mencegah cacat di atas. Ia hanya memeriksa **dua** nama, dan keempat
glyph itu lolos berbulan-bulan. Penggantinya memindai **setiap** nama yang dipakai di seluruh SPA dan
menuntut tiap satunya resolve di sprite. Dibuktikan merah dengan satu nama karangan:

```
daftar-yang-tidak-ada → #lucide-daftar-yang-tidak-ada (views/timesheet.js)
```

### Verifikasi

Dua pohon berdampingan (`main` 8098, pohon kerja 8099), 10 rute:

| | hasil |
|---|---|
| Jumlah ikon per rute | **identik** di kesepuluh rute |
| Ikon hilang | **0** |
| Ikon tampak berukuran nol | **0** |
| Kotak yang bergeser | **11 dari ~807** |
| Galat konsol | **0** |

Kesebelas pergeseran semuanya akibat kelas `.lucide` yang kini ikut: satu ikon berhenti dimampatkan
(12 → 15 px, `flex: none`) dan sepuluh induk kehilangan 1 px tinggi (`vertical-align: middle`).

**Probe glyph-nya sendiri sempat berbohong.** `<use>` ke sprite **eksternal** diselesaikan secara
asinkron, dan mengukur `getBBox()` seketika sesudah `append` melaporkan `0×0` untuk **semuanya** —
termasuk `search` dan `home`, yang jelas tergambar di layar. Ketahuan hanya karena pengukuran
sebelumnya mengatakan sebaliknya.

---

## Bagian 2 — kepala halaman

`.page-head` dibangun **69 kali di 54 berkas** dan **tidak ada pembangun bersama**; `ui.js` tidak
mengekspor satu pun helper untuk itu. Menyatukan ke-69 situs itu menyentuh setiap layar dan milik
UI-4/UI-6 — bukan paket cangkang. Yang dipaku di sini adalah **standarnya**, supaya ia menyebar dan
tidak mundur.

### Tipografi memakai skala UI-1

`--fs-page` ada sejak UI-1 dan sampai hari ini **tidak punya satu pun pemakai**; kepala halaman
memakai `20px` mentah. Nilainya sama persis, jadi nol piksel bergerak — yang berubah adalah bahwa
menyetel skalanya kini menggerakkan judul halaman juga.

### Bilah aksi akhirnya punya perilaku ponsel

Sampai UI-3c `.page-head` tidak punya **satu pun** aturan responsif di seluruh app.css. Terukur pada
390 px:

| rute | `main` | UI-3c |
|---|---:|---:|
| `#/d/projects/1` (8 tombol rata) | **256 px**, tombol 3 baris | **172 px**, tombol 1 baris |
| `#/periods` | 136 px | 132 px |
| `#/d/…/purchase-orders/1` (berzona) | 74 px | 74 px |
| `#/reports` (1 tombol) | 49 px | 49 px |
| **1440 px, kelima rute** | — | **identik** |

Dipilih **satu baris yang menggulung mendatar**, bukan menu yang melipat. Melipat menuntut keputusan
per layar tentang aksi mana yang sekunder — itu 54 berkas, dan menebaknya di sini akan menyembunyikan
tombol yang ternyata utama. Menggulung tidak menyembunyikan apa pun: kedelapan tombol tetap ada
(bilahnya menggulung 522 px di dalam wadahnya), dan **halaman sendiri tidak pernah ikut bergeser**.

**Putaran pertama aturan ini membuat keadaan lebih buruk.** Ia memberi `width: 100%` kepada SETIAP
bilah, dan `#/reports` naik **49 → 97 px**: tombol tunggalnya yang tadinya muat di samping judul
dipaksa turun ke barisnya sendiri. Diperbaiki dengan menyempitkan aturannya ke bilah yang benar-benar
ramai — tiga tombol ke atas, atau bilah berzona.

### Bilah tiga-zona, dan lantainya

`.zone.navigasi` / `.keluaran` / `.keputusan` punya **tepat satu produsen**: `views/detail.js`,
tiga zona. Empat puluh bilah lain menaruh tombol rata; 22 kepala halaman tidak punya `.actions` sama
sekali. Angka **3** kini sebuah **lantai** di `SpaPageHeadStandardTest` — bebas naik saat layar lain
mengadopsinya, tidak boleh turun.

Keempat asersi paket ini dibuktikan merah satu per satu; kedua berkas dipulihkan byte-per-byte
(md5 cocok).

---

## Uji

| Gerbang | Hasil |
|---|---|
| `tests/Feature/Core` | **1.414 / 11.599**, keluar 0 (11 dilewati) |
| `tests/Unit` | **630 / 2.201**, keluar 0 |
| Harness `S5 S7 S8 S14 S18 S19 S20 S20m S21 S21m S22m S43` | hijau, keluar 0 — `results-ui-3c.json` |
| `SHELL_VERSION` | 20 → **21** |

Uji bertambah 5: satu di `LauncherWiringTest` (setiap nama ikon resolve di sprite) dan empat di
`SpaPageHeadStandardTest` yang baru.

---

## Yang sengaja tidak dikerjakan

* **Pembangun kepala halaman bersama.** 69 situs di 54 berkas, tanpa helper. Menyatukannya menyentuh
  setiap layar; ia milik UI-4 (sumber daya generik) dan UI-6 (layar khusus), bukan paket cangkang.
* **Mengadopsi zona di luar `detail.js`.** Ke-12 sumber daya ber-`customDetail` melewati
  `renderDetail` sepenuhnya, jadi bilah berzona tidak terjangkau bagi mereka tanpa menyentuh tiap
  berkasnya. Lantainya dipasang supaya adopsi itu bisa dicicil.
* **Melipat aksi sekunder ke dalam menu di ponsel.** Butuh keputusan per layar tentang aksi mana yang
  sekunder; menebaknya akan menyembunyikan tombol utama seseorang.
* **Varian bintang terisi di sprite.** Menambah simbol ke berkas yang di-vendor menuntut pembaruan
  manifes dan `VendorManifestTest`; keping latar menjawab kebutuhan yang sama tanpa menyentuh vendor.
